<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-to-server Cal.com API v2 client used by the Project Work Days flow.
 *
 * Handles slot lookup, booking creation, and cancellation for the multi-day
 * orchestration. The Direct Visit forms still use the public Cal.com iframe
 * (no API key required for that path) — this client is only invoked from
 * Project_Schedule_Service.
 *
 * Auth: Bearer token from settings (cal_api_key) or HANDIK_BOOKING_APP_CAL_API_KEY.
 *
 * Cal.com versions Platform API v2 endpoints independently, so each public
 * method here passes its own `cal-api-version` header:
 *   - GET  /slots                 → 2024-09-04 (the path doesn't exist on 2024-08-13)
 *   - POST /bookings              → 2024-08-13 (current stable for booking creation)
 *   - POST /bookings/{uid}/cancel → 2024-08-13 (matches booking creation)
 * The `cal_api_version` admin setting is a fallback for any future endpoint
 * that doesn't carry an explicit pin.
 *
 * All POSTs that create bookings carry an idempotency key so a network retry
 * never produces a duplicate booking on Cal's side. Reads do not need it.
 */
class Handik_Booking_App_Cal_Api_Service {
	const DEFAULT_BASE         = 'https://api.cal.com/v2';
	/**
	 * Default `cal-api-version` header sent on every request unless an
	 * endpoint-specific override is passed. We default to 2024-09-04 because
	 * that's the version that exposes the modern `GET /v2/slots` route used
	 * by the Project Work Days picker. Bookings (create/cancel) override to
	 * `2024-08-13` per Cal.com's per-endpoint versioning.
	 *
	 * Cal.com's Platform API v2 is versioned per-endpoint, not globally.
	 * See https://cal.com/docs/api-reference/v2/introduction#versioning
	 */
	const DEFAULT_API_VERSION  = '2024-09-04';
	const SLOTS_API_VERSION    = '2024-09-04';
	const BOOKINGS_API_VERSION = '2024-08-13';
	const DEFAULT_TIMEZONE     = 'America/New_York';
	const HTTP_TIMEOUT_SECONDS = 12;

	/** @var Handik_Booking_App_Settings */
	protected $settings;

	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	public function __construct( $settings, $logger = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	// ---------- public API ------------------------------------------------

	public function is_configured() {
		return '' !== $this->api_key();
	}

	/**
	 * Fetch availability windows from Cal.com for a given event type.
	 *
	 * @param array<string, mixed> $args Required keys: event_type_id|event_slug, start, end.
	 *                                   Optional: timezone, duration_minutes.
	 * @return array{slots?: array<int, array<string, mixed>>, error?: string, status?: int}
	 */
	public function get_slots( array $args ) {
		if ( ! $this->is_configured() ) {
			return array(
				'error'  => __( 'Cal.com API key is not configured.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$query = array(
			'start'    => isset( $args['start'] ) ? (string) $args['start'] : '',
			'end'      => isset( $args['end'] ) ? (string) $args['end'] : '',
			'timeZone' => isset( $args['timezone'] ) && '' !== $args['timezone']
				? (string) $args['timezone']
				: $this->timezone(),
		);
		if ( ! empty( $args['event_type_id'] ) ) {
			$query['eventTypeId'] = (string) $args['event_type_id'];
		} elseif ( ! empty( $args['event_slug'] ) ) {
			$query['eventTypeSlug'] = (string) $args['event_slug'];
		} else {
			return array(
				'error'  => __( 'Missing Cal.com event type id or slug.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		if ( ! empty( $args['duration_minutes'] ) ) {
			$query['duration'] = (int) $args['duration_minutes'];
		}

		$response = $this->request(
			'GET',
			'/slots',
			array(
				'query'   => $query,
				// Slots endpoint is versioned independently from bookings.
				'version' => self::SLOTS_API_VERSION,
			)
		);
		if ( ! empty( $response['error'] ) ) {
			return $response;
		}

		$normalized = array();
		$body       = isset( $response['data'] ) ? $response['data'] : array();
		// v2 returns either {data: {YYYY-MM-DD: [{start, end}, ...]}} or {data: [{start, end}, ...]}.
		if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
			foreach ( $body['data'] as $key => $value ) {
				if ( is_array( $value ) && isset( $value[0] ) ) {
					foreach ( $value as $slot ) {
						$normalized[] = $this->normalize_slot( $slot, $args );
					}
				} elseif ( is_array( $value ) ) {
					$normalized[] = $this->normalize_slot( $value, $args );
				}
			}
		}

		// Filter out null entries from normalize and drop duplicate starts.
		$seen = array();
		$out  = array();
		foreach ( $normalized as $slot ) {
			if ( ! $slot || empty( $slot['start_iso'] ) ) {
				continue;
			}
			$key = $slot['start_iso'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $slot;
		}

		return array( 'slots' => $out );
	}

	/**
	 * Create a single booking on Cal.com.
	 *
	 * @param array<string, mixed> $args Required: event_type_id|event_slug, start, attendee.
	 *                                   Optional: end, timezone, location, metadata, notes,
	 *                                   idempotency_key, duration_minutes.
	 * @return array{booking?: array<string, mixed>, error?: string, status?: int}
	 */
	public function create_booking( array $args ) {
		if ( ! $this->is_configured() ) {
			return array(
				'error'  => __( 'Cal.com API key is not configured.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$body = array(
			'start'    => isset( $args['start'] ) ? (string) $args['start'] : '',
			'attendee' => isset( $args['attendee'] ) && is_array( $args['attendee'] )
				? $args['attendee']
				: array(),
		);
		if ( ! empty( $args['event_type_id'] ) ) {
			$body['eventTypeId'] = (int) $args['event_type_id'];
		} elseif ( ! empty( $args['event_slug'] ) ) {
			$body['eventTypeSlug'] = (string) $args['event_slug'];
		} else {
			return array(
				'error'  => __( 'Missing Cal.com event type id or slug.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		if ( ! empty( $args['end'] ) ) {
			$body['end'] = (string) $args['end'];
		}
		if ( ! empty( $args['duration_minutes'] ) ) {
			$body['lengthInMinutes'] = (int) $args['duration_minutes'];
		}
		if ( ! empty( $args['timezone'] ) ) {
			$body['attendee']['timeZone'] = (string) $args['timezone'];
		} elseif ( empty( $body['attendee']['timeZone'] ) ) {
			$body['attendee']['timeZone'] = $this->timezone();
		}
		if ( ! empty( $args['location'] ) ) {
			$body['location'] = $args['location'];
		}
		if ( ! empty( $args['metadata'] ) && is_array( $args['metadata'] ) ) {
			$body['metadata'] = $args['metadata'];
		}
		if ( ! empty( $args['notes'] ) ) {
			$body['bookingFieldsResponses'] = array(
				'notes' => (string) $args['notes'],
			);
		}

		$headers = array();
		if ( ! empty( $args['idempotency_key'] ) ) {
			// Cal-Idempotency-Key turns a retried POST into a single booking.
			$headers['Cal-Idempotency-Key'] = (string) $args['idempotency_key'];
		}

		$response = $this->request(
			'POST',
			'/bookings',
			array(
				'headers' => $headers,
				'body'    => $body,
				'version' => self::BOOKINGS_API_VERSION,
			)
		);
		if ( ! empty( $response['error'] ) ) {
			return $response;
		}

		$payload = isset( $response['data']['data'] ) && is_array( $response['data']['data'] )
			? $response['data']['data']
			: ( isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array() );

		$booking = $this->normalize_booking( $payload );
		if ( empty( $booking['uid'] ) && empty( $booking['id'] ) ) {
			return array(
				'error'  => __( 'Cal.com did not return a booking identifier.', 'handik-booking-app' ),
				'status' => 502,
			);
		}

		return array( 'booking' => $booking );
	}

	/**
	 * Cancel a booking by its Cal-side UID.
	 *
	 * @param string $uid    Cal booking UID (NOT the numeric id).
	 * @param string $reason Optional reason logged in Cal.
	 * @return array{success?: true, error?: string, status?: int}
	 */
	public function cancel_booking( $uid, $reason = '' ) {
		$uid = (string) $uid;
		if ( '' === $uid ) {
			return array(
				'error'  => __( 'Cal.com booking UID required for cancellation.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		if ( ! $this->is_configured() ) {
			return array(
				'error'  => __( 'Cal.com API key is not configured.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$response = $this->request(
			'POST',
			'/bookings/' . rawurlencode( $uid ) . '/cancel',
			array(
				'body' => array(
					'cancellationReason' => '' !== $reason ? $reason : 'Handik plugin rollback',
				),
				'version' => self::BOOKINGS_API_VERSION,
			)
		);
		if ( ! empty( $response['error'] ) ) {
			return $response;
		}
		return array( 'success' => true );
	}

	// ---------- normalization --------------------------------------------

	/**
	 * @param mixed                $raw  Raw slot.
	 * @param array<string, mixed> $args Original request args (for fallback duration).
	 * @return array<string, mixed>|null
	 */
	public function normalize_slot( $raw, array $args = array() ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$start = isset( $raw['start'] )
			? (string) $raw['start']
			: ( isset( $raw['startTime'] ) ? (string) $raw['startTime'] : '' );
		$end = isset( $raw['end'] )
			? (string) $raw['end']
			: ( isset( $raw['endTime'] ) ? (string) $raw['endTime'] : '' );
		if ( '' === $start ) {
			return null;
		}
		// If end missing, derive it from duration.
		if ( '' === $end && ! empty( $args['duration_minutes'] ) ) {
			try {
				$dt = new DateTimeImmutable( $start );
				$dt = $dt->modify( '+' . (int) $args['duration_minutes'] . ' minutes' );
				$end = $dt->format( 'Y-m-d\TH:i:sP' );
			} catch ( Exception $e ) {
				$end = '';
			}
		}
		return array(
			'start_iso' => $start,
			'end_iso'   => $end,
		);
	}

	/**
	 * @param array<string, mixed> $raw Raw booking from Cal.com.
	 * @return array<string, mixed>
	 */
	public function normalize_booking( array $raw ) {
		$uid = '';
		foreach ( array( 'uid', 'bookingUid' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$uid = (string) $raw[ $key ];
				break;
			}
		}
		$id = '';
		foreach ( array( 'id', 'bookingId' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$id = (string) $raw[ $key ];
				break;
			}
		}
		$start = isset( $raw['start'] ) ? (string) $raw['start'] : ( isset( $raw['startTime'] ) ? (string) $raw['startTime'] : '' );
		$end   = isset( $raw['end'] ) ? (string) $raw['end'] : ( isset( $raw['endTime'] ) ? (string) $raw['endTime'] : '' );
		$url   = '';
		foreach ( array( 'meetingUrl', 'metaUrl', 'bookingUrl', 'rescheduleLink' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$url = (string) $raw[ $key ];
				break;
			}
		}
		return array(
			'uid'     => $uid,
			'id'      => $id,
			'start'   => $start,
			'end'     => $end,
			'url'     => $url,
			'raw'     => $raw,
		);
	}

	// ---------- internals -------------------------------------------------

	/**
	 * @param string               $method HTTP method.
	 * @param string               $path   Path under base URL.
	 * @param array<string, mixed> $opts   Optional: headers, body, query.
	 * @return array{data?: mixed, error?: string, status?: int}
	 */
	protected function request( $method, $path, array $opts = array() ) {
		$url = trailingslashit( $this->base_url() ) . ltrim( $path, '/' );
		if ( ! empty( $opts['query'] ) ) {
			$url = add_query_arg( $opts['query'], $url );
		}

		// Cal.com Platform API v2 versions each endpoint independently. Slots
		// uses 2024-09-04, bookings uses 2024-08-13. Each public method passes
		// its own `version` so we don't have to maintain that mapping in two
		// places.
		$version = isset( $opts['version'] ) && '' !== $opts['version']
			? (string) $opts['version']
			: $this->api_version();

		$headers = array(
			'Authorization'   => 'Bearer ' . $this->api_key(),
			'cal-api-version' => $version,
			'Accept'          => 'application/json',
		);
		if ( ! empty( $opts['headers'] ) && is_array( $opts['headers'] ) ) {
			$headers = array_merge( $headers, $opts['headers'] );
		}
		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => self::HTTP_TIMEOUT_SECONDS,
			'redirection' => 2,
			'httpversion' => '1.1',
		);
		if ( isset( $opts['body'] ) ) {
			$args['body'] = wp_json_encode( $opts['body'] );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( $this->logger ) {
				$this->logger->error(
					'Cal.com API request failed (transport).',
					array(
						'method' => $method,
						'path'   => $path,
						'error'  => $message,
					)
				);
			}
			return array(
				'error'  => sprintf(
					/* translators: %s: error message */
					__( 'Cal.com API request failed: %s', 'handik-booking-app' ),
					$message
				),
				'status' => 502,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status >= 400 ) {
			$err_message = '';
			if ( is_array( $data ) ) {
				if ( isset( $data['error']['message'] ) ) {
					$err_message = (string) $data['error']['message'];
				} elseif ( isset( $data['message'] ) ) {
					$err_message = (string) $data['message'];
				}
			}
			if ( '' === $err_message ) {
				$err_message = sprintf(
					/* translators: %d: HTTP status */
					__( 'Cal.com API returned HTTP %d.', 'handik-booking-app' ),
					$status
				);
			}
			if ( $this->logger ) {
				$this->logger->warning(
					'Cal.com API non-2xx.',
					array(
						'method' => $method,
						'path'   => $path,
						'status' => $status,
						'body'   => substr( $body, 0, 500 ),
					)
				);
			}
			return array(
				'error'  => $err_message,
				'status' => $status,
			);
		}

		return array(
			'data'   => $data,
			'status' => $status,
		);
	}

	protected function api_key() {
		if ( defined( 'HANDIK_BOOKING_APP_CAL_API_KEY' ) ) {
			$constant = (string) HANDIK_BOOKING_APP_CAL_API_KEY;
			if ( '' !== trim( $constant ) ) {
				return trim( $constant );
			}
		}
		return trim( (string) $this->settings->get( 'cal_api_key', '' ) );
	}

	protected function base_url() {
		$base = trim( (string) $this->settings->get( 'cal_api_base', '' ) );
		return '' !== $base ? rtrim( $base, '/' ) : self::DEFAULT_BASE;
	}

	protected function api_version() {
		$version = trim( (string) $this->settings->get( 'cal_api_version', '' ) );
		return '' !== $version ? $version : self::DEFAULT_API_VERSION;
	}

	public function timezone() {
		$tz = trim( (string) $this->settings->get( 'cal_api_timezone', '' ) );
		return '' !== $tz ? $tz : self::DEFAULT_TIMEZONE;
	}
}
