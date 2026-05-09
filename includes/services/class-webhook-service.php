<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Webhook_Service {
	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @var Handik_Booking_App_Bookings_Service
	 */
	protected $bookings;

	/**
	 * @var Handik_Booking_App_Direct_Booking_Service|null
	 */
	protected $direct;

	/**
	 * @var Handik_Booking_App_Project_Schedule_Service|null
	 */
	protected $project;

	/**
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Bookings_Service     $bookings Bookings.
	 * @param Handik_Booking_App_Direct_Booking_Service|null   $direct   Direct booking service.
	 * @param Handik_Booking_App_Project_Schedule_Service|null $project  Project schedule service.
	 */
	public function __construct( $settings, $logger, $job_requests, $bookings, $direct = null, $project = null ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->direct       = $direct;
		$this->project      = $project;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function handle_cal_webhook( WP_REST_Request $request ) {
		$raw_body = $request->get_body();
		$payload  = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->logger->error( 'Cal webhook payload could not be decoded.', array( 'raw_body' => substr( (string) $raw_body, 0, 1000 ) ) );
			return array( 'error' => __( 'Invalid webhook payload.', 'handik-booking-app' ), 'status' => 400 );
		}
		if ( ! $this->verify_signature( $request, $raw_body ) ) {
			$this->logger->error(
				'Cal webhook signature failed.',
				array(
					'has_secret_header'    => (bool) $request->get_header( 'x-cal-secret-key' ),
					'has_signature_header' => (bool) ( $request->get_header( 'x-cal-signature-256' ) ?: $request->get_header( 'x-cal-signature' ) ),
				)
			);
			return array( 'error' => __( 'Webhook signature failed.', 'handik-booking-app' ), 'status' => 403 );
		}

		$event     = $this->normalize_event_name( (string) ( $payload['triggerEvent'] ?? $payload['event'] ?? $payload['type'] ?? 'BOOKING_CREATED' ) );
		$data      = ! empty( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : $payload;
		$this->logger->info(
			'Cal webhook received.',
			array(
				'event'         => $event,
				'booking_id'    => $data['bookingId'] ?? $data['bookingUid'] ?? $data['uid'] ?? $data['id'] ?? '',
				'has_metadata'  => ! empty( $data['metadata'] ),
				'payload_shape' => ! empty( $payload['payload'] ) && is_array( $payload['payload'] ) ? 'nested' : 'flat',
			)
		);
		$metadata  = array();
		if ( ! empty( $data['metadata'] ) ) {
			if ( is_array( $data['metadata'] ) ) {
				$metadata = $data['metadata'];
			} elseif ( is_string( $data['metadata'] ) ) {
				$decoded = json_decode( $data['metadata'], true );
				$metadata = is_array( $decoded ) ? $decoded : array();
			}
		}
		// Route to the Additional Booking Forms module before falling back to
		// the main AI flow. We only dispatch here AFTER signature verification
		// so a forged metadata payload cannot mutate state.
		$booking_source = isset( $metadata['handik_booking_source'] ) ? sanitize_key( (string) $metadata['handik_booking_source'] ) : '';
		if ( 'direct_booking_form' === $booking_source && $this->direct ) {
			return $this->dispatch_direct( $event, $data, $metadata );
		}
		if ( 'project_work_days_form' === $booking_source && $this->project ) {
			return $this->dispatch_project( $event, $data, $metadata );
		}

		$request_id = ! empty( $metadata['handik_job_request_id'] ) ? absint( $metadata['handik_job_request_id'] ) : 0;

		if ( ! $request_id ) {
			$booking_id = $this->bookings->extract_booking_id( $data );
			$row        = $this->job_requests->find_by_cal_booking_id( $booking_id );
			$request_id = $row ? (int) $row['id'] : 0;
		}

		if ( ! $request_id ) {
			$contact_match = $this->extract_contact_match( $data );
			if ( ! empty( $contact_match['email'] ) || ! empty( $contact_match['phone'] ) ) {
				$row        = $this->job_requests->find_latest_pending_by_contact( $contact_match['email'] ?? '', $contact_match['phone'] ?? '' );
				$request_id = $row ? (int) $row['id'] : 0;
				if ( $request_id ) {
					$this->logger->info(
						'Matched Cal webhook to request by contact fallback.',
						array(
							'request_id' => $request_id,
							'event'      => $event,
							'email'      => $contact_match['email'] ?? '',
							'phone'      => $contact_match['phone'] ?? '',
						)
					);
				}
			}
		}

		if ( ! $request_id ) {
			$this->logger->error( 'Could not match Cal webhook to job request.', array( 'event' => $event, 'payload' => $payload ) );
			return array( 'error' => __( 'Matching request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$data['booking_type'] = ! empty( $metadata['handik_booking_type'] ) ? sanitize_key( $metadata['handik_booking_type'] ) : '';
		$mapped_status = $this->map_status( $event );
		if ( '' === $mapped_status ) {
			// Unknown / non-state-changing event (e.g. meeting_started). Log
			// and acknowledge so Cal.com doesn't keep retrying, but don't
			// mutate the booking row.
			$this->logger->info(
				'Cal webhook acknowledged (no state change).',
				array(
					'request_id' => $request_id,
					'event'      => $event,
				)
			);
			return array( 'success' => true, 'status' => 'ignored' );
		}
		$this->bookings->upsert_from_cal( $request_id, $data, $mapped_status );
		$this->logger->info(
			'Cal webhook synced booking.',
			array(
				'request_id' => $request_id,
				'event'      => $event,
				'booking_id' => $this->bookings->extract_booking_id( $data ),
				'status'     => $mapped_status,
			)
		);

		return array( 'success' => true, 'status' => $mapped_status );
	}

	protected function verify_signature( WP_REST_Request $request, $raw_body ) {
		$secret = trim( (string) $this->settings->get( 'cal_webhook_secret', '' ) );
		if ( ! $secret ) {
			// Fail closed: webhook requires a configured shared secret. Set
			// `cal_webhook_secret` (or HANDIK_BOOKING_APP_CAL_WEBHOOK_SECRET) before
			// pointing Cal.com at this endpoint — otherwise anyone could forge bookings.
			return false;
		}
		$secret_header = $request->get_header( 'x-cal-secret-key' );
		if ( $secret_header && hash_equals( $secret, $secret_header ) ) {
			return true;
		}
		$signature = $request->get_header( 'x-cal-signature-256' );
		if ( ! $signature ) {
			$signature = $request->get_header( 'x-cal-signature' );
		}
		if ( ! $signature ) {
			return false;
		}
		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}
		return hash_equals( hash_hmac( 'sha256', $raw_body, $secret ), $signature );
	}

	/**
	 * @param string               $event    Normalized event.
	 * @param array<string, mixed> $data     Cal payload data.
	 * @param array<string, mixed> $metadata Cal metadata.
	 * @return array{success?: true, status?: string, error?: string, http_status?: int}
	 */
	protected function dispatch_direct( $event, array $data, array $metadata ) {
		$booking_id  = (string) ( $data['uid'] ?? $data['bookingUid'] ?? $data['id'] ?? $data['bookingId'] ?? '' );
		$status      = $this->map_status( $event );
		$direct_id   = isset( $metadata['handik_direct_request_id'] )
			? absint( $metadata['handik_direct_request_id'] )
			: 0;

		if ( '' === $status ) {
			$this->logger->info(
				'Cal webhook acknowledged for direct form (no state change).',
				array( 'event' => $event, 'direct_request_id' => $direct_id )
			);
			return array( 'success' => true, 'status' => 'ignored' );
		}

		if ( $direct_id ) {
			global $wpdb;
			$wpdb->update(
				Handik_Booking_App_DB::table( 'direct_booking_requests' ),
				array(
					'status'         => sanitize_key( $status ),
					'cal_booking_id' => $booking_id,
					'cal_booking_uid' => (string) ( $data['uid'] ?? $data['bookingUid'] ?? '' ),
				),
				array( 'id' => $direct_id )
			);
		} elseif ( '' !== $booking_id ) {
			$row = $this->direct->find_by_cal_booking_id( $booking_id );
			if ( $row ) {
				$this->direct->update_status_by_uid( (string) ( $data['uid'] ?? $data['bookingUid'] ?? $row['cal_booking_uid'] ), $status );
				$direct_id = (int) $row['id']; // For the bookings mirror below.
			}
		}

		// Sprint 13.5 — mirror into handik_bookings so the unified
		// admin Bookings list can surface this row alongside main-SPA
		// Cal bookings. Idempotent on cal_booking_id UNIQUE; if the
		// capture-side already inserted, this is a status / payload
		// refresh.
		if ( $direct_id && $this->bookings && method_exists( $this->bookings, 'upsert_from_direct_capture' ) ) {
			$this->bookings->upsert_from_direct_capture( $direct_id, $data, $status );
		}

		$this->logger->info(
			'Cal webhook routed to direct booking form.',
			array(
				'event'             => $event,
				'direct_request_id' => $direct_id,
				'cal_booking_id'    => $booking_id,
				'status'            => $status,
			)
		);
		return array( 'success' => true, 'status' => $status );
	}

	/**
	 * @param string               $event    Normalized event.
	 * @param array<string, mixed> $data     Cal payload data.
	 * @param array<string, mixed> $metadata Cal metadata.
	 * @return array{success?: true, status?: string, error?: string, http_status?: int}
	 */
	protected function dispatch_project( $event, array $data, array $metadata ) {
		$uid         = (string) ( $data['uid'] ?? $data['bookingUid'] ?? '' );
		$schedule_id = isset( $metadata['handik_project_schedule_id'] ) ? absint( $metadata['handik_project_schedule_id'] ) : 0;
		$day_index   = isset( $metadata['handik_project_day_index'] ) ? absint( $metadata['handik_project_day_index'] ) : 0;
		$status      = $this->map_status( $event );

		if ( '' === $status ) {
			$this->logger->info(
				'Cal webhook acknowledged for project (no state change).',
				array( 'event' => $event, 'schedule_id' => $schedule_id )
			);
			return array( 'success' => true, 'status' => 'ignored' );
		}

		// Guard: a webhook claiming to be a project booking must carry both
		// the schedule id AND a UID we can match. Without metadata we have
		// no safe way to attribute the event — refuse rather than guessing
		// and risking a contact-fallback match against the AI flow.
		if ( ! $schedule_id || '' === $uid ) {
			$this->logger->warning(
				'Cal webhook for project work days missing required metadata; skipping.',
				array(
					'event'       => $event,
					'has_uid'     => '' !== $uid,
					'schedule_id' => $schedule_id,
				)
			);
			return array( 'success' => true, 'status' => 'ignored' );
		}

		// Confirm the schedule actually exists. Defends against a forged
		// metadata payload pointing at someone else's row.
		$schedule = $this->project->get( $schedule_id );
		if ( ! $schedule ) {
			$this->logger->warning(
				'Cal webhook for project work days references unknown schedule; skipping.',
				array( 'schedule_id' => $schedule_id, 'event' => $event )
			);
			return array( 'success' => true, 'status' => 'ignored' );
		}

		// For projects we map "booked" → "confirmed" only at the day level;
		// other events ("cancelled", "rescheduled") flow through unchanged.
		$day_status = ( 'booked' === $status ) ? Handik_Booking_App_Project_Schedule_Service::DAY_STATUS_CONFIRMED : $status;
		$this->project->update_day_status_by_uid( $uid, $day_status );

		$this->logger->info(
			'Cal webhook routed to project work days.',
			array(
				'event'       => $event,
				'cal_uid'     => $uid,
				'day_status'  => $day_status,
				'schedule_id' => $schedule_id,
				'day_index'   => $day_index,
			)
		);
		return array( 'success' => true, 'status' => $status );
	}

	/**
	 * Map a Cal.com event name to one of our internal booking statuses.
	 *
	 * Whitelist-based: unknown events return an empty string and the caller
	 * SKIPS the state mutation. Earlier we defaulted to `booked` for any
	 * unknown event, which let benign signals like `meeting_started`,
	 * `payment_initiated`, `instant_meeting` etc. flip a cancelled booking
	 * back to booked — exactly what the customer didn't want.
	 *
	 * @param string $event Normalized event (lowercased, underscored).
	 * @return string Internal status, or '' for "ignore this event".
	 */
	protected function map_status( $event ) {
		switch ( $event ) {
			case 'booking_created':
			case 'booking_paid':
			case 'booking_payment_initiated':
				return 'booked';
			case 'booking_rescheduled':
				return 'rescheduled';
			case 'booking_cancelled':
			case 'booking_rejected':
				return 'cancelled';
			default:
				return '';
		}
	}

	/**
	 * @param string $event Raw event name.
	 * @return string
	 */
	protected function normalize_event_name( $event ) {
		$event = strtolower( trim( (string) $event ) );
		$event = str_replace( array( '.', '-', ' ' ), '_', $event );
		return preg_replace( '/[^a-z0-9_]/', '', $event );
	}

	/**
	 * @param array<string, mixed> $data Payload data.
	 * @return array<string, string>
	 */
	protected function extract_contact_match( array $data ) {
		$email = '';
		$phone = '';

		if ( ! empty( $data['attendees'] ) && is_array( $data['attendees'] ) ) {
			$attendee = reset( $data['attendees'] );
			if ( is_array( $attendee ) ) {
				$email = sanitize_email( (string) ( $attendee['email'] ?? $attendee['emailAddress'] ?? '' ) );
				$phone = sanitize_text_field( (string) ( $attendee['phoneNumber'] ?? $attendee['phone'] ?? '' ) );
			}
		}

		if ( ! $email && ! empty( $data['responses'] ) && is_array( $data['responses'] ) ) {
			$email = sanitize_email( (string) ( $data['responses']['email'] ?? '' ) );
			$phone = $phone ? $phone : sanitize_text_field( (string) ( $data['responses']['phone'] ?? '' ) );
		}

		if ( ! $email && ! empty( $data['userPrimaryEmail'] ) ) {
			$email = sanitize_email( (string) $data['userPrimaryEmail'] );
		}

		return array(
			'email' => $email,
			'phone' => $phone,
		);
	}
}
