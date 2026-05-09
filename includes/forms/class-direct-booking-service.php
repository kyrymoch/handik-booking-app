<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Direct Visit booking flow (Standard / Extended / Large Visits).
 *
 * Saves the contact + address into the shared CRM, persists a row in
 * handik_direct_booking_requests, and returns a Cal.com iframe URL the
 * client mounts to pick a slot. Cal.com fires its onBookingSuccessful event
 * back to the SPA, which calls capture_booking() to record the booking IDs.
 */
class Handik_Booking_App_Direct_Booking_Service {
	const STATUS_READY     = 'ready_for_booking';
	const STATUS_OPENED    = 'booking_opened';
	const STATUS_BOOKED    = 'booked';
	const STATUS_CANCELLED = 'cancelled';

	/** @var Handik_Booking_App_Booking_Presets_Service */
	protected $presets;
	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Settings */
	protected $settings;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	public function __construct( $presets, $contacts, $addresses, $settings, $logger = null ) {
		$this->presets   = $presets;
		$this->contacts  = $contacts;
		$this->addresses = $addresses;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	/**
	 * Step 1+2: persist contact + address, build Cal URL.
	 *
	 * @param string               $slug    Preset slug.
	 * @param array<string, mixed> $payload {full_name, phone, email?, address_full, address_unit?, source_url?, client_type?}
	 * @return array{request_id?: int, cal_booking_url?: string, error?: string, status?: int}
	 */
	public function submit( $slug, array $payload ) {
		$preset = $this->presets->find_by_slug( $slug );
		if ( ! $preset || empty( $preset['enabled'] ) || self::form_type( $preset ) !== Handik_Booking_App_Booking_Presets_Service::FORM_TYPE_DIRECT ) {
			return array(
				'error'  => __( 'This booking form is not available right now.', 'handik-booking-app' ),
				'status' => 404,
			);
		}

		$contact_payload = array(
			'full_name' => isset( $payload['full_name'] ) ? sanitize_text_field( (string) $payload['full_name'] ) : '',
			'phone'     => isset( $payload['phone'] ) ? sanitize_text_field( (string) $payload['phone'] ) : '',
			'email'     => isset( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '',
			'source'    => 'direct_booking_form',
		);
		if ( '' === $contact_payload['full_name'] ) {
			return array(
				'error'  => __( 'Please enter your full name.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		if ( '' === $contact_payload['phone'] ) {
			return array(
				'error'  => __( 'Please enter a phone number.', 'handik-booking-app' ),
				'status' => 400,
			);
		}

		$contact_id = $this->contacts->upsert( $contact_payload );
		if ( ! $contact_id ) {
			return array(
				'error'  => __( 'Could not save contact details. Please try again.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$address_full = isset( $payload['address_full'] ) ? sanitize_textarea_field( (string) $payload['address_full'] ) : '';
		$address_full = $this->normalize_address_country( $address_full );
		if ( '' === $address_full ) {
			return array(
				'error'  => __( 'Please enter the address.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		$address_id = (int) $this->addresses->sync(
			$contact_id,
			array(
				'address_full' => $address_full,
				'address_unit' => isset( $payload['address_unit'] ) ? sanitize_text_field( (string) $payload['address_unit'] ) : '',
				'is_default'   => 1,
			)
		);
		// Defensive: addresses->sync returns 0 on validation failure. We
		// already validated address_full above, so 0 here means a wpdb-level
		// failure or an unknown contact_id (also already checked). Bail with
		// an explicit error rather than persisting a row pointing at #0.
		if ( $address_id <= 0 ) {
			return array(
				'error'  => __( 'Could not save the address. Please try again.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		// Insert/update direct request row.
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'direct_booking_requests' );

		// Per-row capture_token closes the IDOR on /forms/direct/{id}/capture.
		// 32 unguessable chars; compared with hash_equals on capture.
		$capture_token = wp_generate_password( 32, false, false );

		$row = array(
			'contact_id'        => $contact_id,
			'address_id'        => $address_id,
			'preset_slug'       => (string) $preset['preset_slug'],
			'form_title'        => (string) $preset['form_title'],
			'booking_type'      => (string) $preset['booking_type'],
			'duration_minutes'  => (int) $preset['duration_minutes'],
			'status'            => self::STATUS_READY,
			'source_url'        => isset( $payload['source_url'] ) ? esc_url_raw( (string) $payload['source_url'] ) : '',
			'client_type'       => isset( $payload['client_type'] ) ? sanitize_key( (string) $payload['client_type'] ) : 'new_client',
			'client_ip'         => Handik_Booking_App_Forms_Helpers::client_ip_packed(),
			'capture_token'     => $capture_token,
		);
		$wpdb->insert( $table, $row );
		$request_id = (int) $wpdb->insert_id;
		if ( ! $request_id ) {
			return array(
				'error'  => __( 'Could not save your request. Please try again.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$contact = $this->contacts->get( $contact_id );
		$cal_url = $this->build_cal_url( $request_id, $preset, $contact, $address_full, isset( $payload['address_unit'] ) ? (string) $payload['address_unit'] : '' );

		if ( '' !== $cal_url ) {
			$wpdb->update(
				$table,
				array(
					'cal_booking_url' => $cal_url,
					'status'          => self::STATUS_OPENED,
				),
				array( 'id' => $request_id )
			);
		}

		if ( $this->logger ) {
			$this->logger->info(
				'Direct booking form submitted.',
				array(
					'request_id'        => $request_id,
					'preset_slug'       => $preset['preset_slug'],
					'booking_type'      => $preset['booking_type'],
					'duration_minutes'  => $preset['duration_minutes'],
					'has_cal_url'       => '' !== $cal_url,
				)
			);
		}

		return array(
			'request_id'      => $request_id,
			'cal_booking_url' => $cal_url,
			'preset_slug'     => $preset['preset_slug'],
			// Issued once per submit; the SPA stores it in state and sends it
			// back on /forms/direct/{id}/capture so anonymous parties can't
			// overwrite the booking record by guessing incrementing IDs.
			'capture_token'   => $capture_token,
		);
	}

	/**
	 * Sprint 13 — admin-initiated direct booking submission. Owner uses
	 * the same flow as the public Additional Forms direct-booking
	 * preset, but from `wp-admin` so they can book on behalf of an
	 * existing customer (e.g. a phone-call repeat customer who doesn't
	 * want to type into the public form themselves).
	 *
	 * Differences from `submit()`:
	 *   - Accepts `contact_id` (use existing) instead of upserting one.
	 *     Skips name/phone/email validation in that branch — the contact
	 *     already exists.
	 *   - Accepts `address_id` (use existing) instead of syncing.
	 *   - Tags `source_url='admin:bookings'` and `client_type='admin_initiated'`
	 *     so the row can be filtered out of public stats / abandoned-
	 *     cart cron.
	 *
	 * The webhook dispatcher (`Webhook_Service::dispatch_direct`) will
	 * pick up the resulting Cal booking by `metadata.handik_direct_request_id`
	 * exactly the same way as a public submission — no webhook changes
	 * needed.
	 *
	 * @param string               $slug    Preset slug.
	 * @param array<string, mixed> $payload Admin form payload. Must
	 *                                       include either `contact_id`
	 *                                       OR (full_name + phone), and
	 *                                       either `address_id` OR
	 *                                       `address_full`.
	 * @return array{request_id?: int, cal_booking_url?: string, error?: string, status?: int}
	 */
	public function admin_submit( $slug, array $payload ) {
		$preset = $this->presets->find_by_slug( $slug );
		if ( ! $preset || empty( $preset['enabled'] ) || self::form_type( $preset ) !== Handik_Booking_App_Booking_Presets_Service::FORM_TYPE_DIRECT ) {
			return array(
				'error'  => __( 'This booking form is not available right now.', 'handik-booking-app' ),
				'status' => 404,
			);
		}

		// 1. Resolve contact — existing or new.
		$contact_id = isset( $payload['contact_id'] ) ? absint( $payload['contact_id'] ) : 0;
		if ( $contact_id > 0 ) {
			$existing = $this->contacts->get( $contact_id );
			if ( ! $existing ) {
				return array(
					'error'  => __( 'Selected contact no longer exists.', 'handik-booking-app' ),
					'status' => 404,
				);
			}
		} else {
			$contact_payload = array(
				'full_name' => isset( $payload['full_name'] ) ? sanitize_text_field( (string) $payload['full_name'] ) : '',
				'phone'     => isset( $payload['phone'] ) ? sanitize_text_field( (string) $payload['phone'] ) : '',
				'email'     => isset( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '',
				'source'    => 'admin_direct_booking',
			);
			if ( '' === $contact_payload['full_name'] ) {
				return array( 'error' => __( 'Please enter the customer\'s full name.', 'handik-booking-app' ), 'status' => 400 );
			}
			if ( '' === $contact_payload['phone'] ) {
				return array( 'error' => __( 'Please enter the customer\'s phone number.', 'handik-booking-app' ), 'status' => 400 );
			}
			$contact_id = (int) $this->contacts->upsert( $contact_payload );
			if ( ! $contact_id ) {
				return array( 'error' => __( 'Could not save contact details.', 'handik-booking-app' ), 'status' => 500 );
			}
		}

		// 2. Resolve address — existing or new.
		$address_id   = isset( $payload['address_id'] ) ? absint( $payload['address_id'] ) : 0;
		$address_full = '';
		$address_unit = '';
		if ( $address_id > 0 ) {
			$existing_addr = $this->addresses->get( $address_id );
			if ( ! $existing_addr || (int) $existing_addr['contact_id'] !== $contact_id ) {
				return array(
					'error'  => __( 'Selected address does not belong to that contact.', 'handik-booking-app' ),
					'status' => 400,
				);
			}
			$address_full = (string) ( $existing_addr['address_full'] ?? '' );
			$address_unit = (string) ( $existing_addr['address_unit'] ?? '' );
		} else {
			$address_full = isset( $payload['address_full'] ) ? sanitize_textarea_field( (string) $payload['address_full'] ) : '';
			$address_full = $this->normalize_address_country( $address_full );
			if ( '' === $address_full ) {
				return array( 'error' => __( 'Please enter the address.', 'handik-booking-app' ), 'status' => 400 );
			}
			$address_unit = isset( $payload['address_unit'] ) ? sanitize_text_field( (string) $payload['address_unit'] ) : '';
			$address_id = (int) $this->addresses->sync(
				$contact_id,
				array(
					'address_full' => $address_full,
					'address_unit' => $address_unit,
					'is_default'   => 1,
				)
			);
			if ( $address_id <= 0 ) {
				return array( 'error' => __( 'Could not save the address.', 'handik-booking-app' ), 'status' => 500 );
			}
		}

		// 3. Insert direct_booking_request, build Cal URL — same shape
		//    as public submit() so the webhook flow is identical.
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$capture_token = wp_generate_password( 32, false, false );
		$row = array(
			'contact_id'       => $contact_id,
			'address_id'       => $address_id,
			'preset_slug'      => (string) $preset['preset_slug'],
			'form_title'       => (string) $preset['form_title'],
			'booking_type'     => (string) $preset['booking_type'],
			'duration_minutes' => (int) $preset['duration_minutes'],
			'status'           => self::STATUS_READY,
			'source_url'       => 'admin:bookings',
			'client_type'      => 'admin_initiated',
			'client_ip'        => Handik_Booking_App_Forms_Helpers::client_ip_packed(),
			'capture_token'    => $capture_token,
		);
		$wpdb->insert( $table, $row );
		$request_id = (int) $wpdb->insert_id;
		if ( ! $request_id ) {
			return array( 'error' => __( 'Could not save the request.', 'handik-booking-app' ), 'status' => 500 );
		}

		$contact = $this->contacts->get( $contact_id );
		$cal_url = $this->build_cal_url( $request_id, $preset, $contact, $address_full, $address_unit );
		if ( '' !== $cal_url ) {
			$wpdb->update(
				$table,
				array( 'cal_booking_url' => $cal_url, 'status' => self::STATUS_OPENED ),
				array( 'id' => $request_id )
			);
		}

		if ( $this->logger ) {
			$this->logger->info( 'Admin direct booking form submitted.', array(
				'request_id'  => $request_id,
				'contact_id'  => $contact_id,
				'address_id'  => $address_id,
				'preset_slug' => $preset['preset_slug'],
				'admin_id'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			) );
		}

		return array(
			'request_id'      => $request_id,
			'cal_booking_url' => $cal_url,
			'preset_slug'     => $preset['preset_slug'],
			'capture_token'   => $capture_token,
		);
	}

	/**
	 * Called by the SPA when Cal.com's onBookingSuccessful fires.
	 *
	 * Security:
	 *   1. The presented `capture_token` must match the one issued on submit
	 *      (timing-safe `hash_equals`).
	 *   2. Only rows currently in the OPENED state (i.e. an iframe was
	 *      mounted) can transition to BOOKED. Already-booked rows are an
	 *      idempotent no-op so a Cal embed that double-fires
	 *      `bookingSuccessful` doesn't re-overwrite IDs. Cancelled rows
	 *      cannot be re-opened.
	 *
	 * @param int                  $request_id    Direct request ID.
	 * @param array<string, mixed> $payload       Cal booking shape (best-effort).
	 * @param string               $capture_token Token from the submit response.
	 * @return array{success?: true, error?: string, status?: int}
	 */
	public function capture_booking( $request_id, $payload, $capture_token = '' ) {
		global $wpdb;
		$table   = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$payload = is_array( $payload ) ? $payload : array();

		$row = $this->get( $request_id );
		if ( ! $row ) {
			return array(
				'error'  => __( 'Booking request not found.', 'handik-booking-app' ),
				'status' => 404,
			);
		}
		// Token check (closes the IDOR). Never accept blank tokens — older
		// clients on 1.4.0 will simply re-submit and pick up a fresh one.
		$expected = (string) ( $row['capture_token'] ?? '' );
		if ( '' === $expected || '' === (string) $capture_token || ! hash_equals( $expected, (string) $capture_token ) ) {
			if ( $this->logger ) {
				$this->logger->warning(
					'Direct capture rejected: capture_token mismatch.',
					array( 'request_id' => $request_id )
				);
			}
			return array(
				'error'  => __( 'This booking session has expired. Please reload the page and try again.', 'handik-booking-app' ),
				'status' => 403,
			);
		}
		// State precondition.
		$status = (string) ( $row['status'] ?? '' );
		if ( self::STATUS_BOOKED === $status ) {
			// Idempotent — Cal embed sometimes fires bookingSuccessful twice.
			return array( 'success' => true );
		}
		if ( self::STATUS_CANCELLED === $status ) {
			return array(
				'error'  => __( 'This request has been cancelled.', 'handik-booking-app' ),
				'status' => 409,
			);
		}
		if ( self::STATUS_READY !== $status && self::STATUS_OPENED !== $status ) {
			return array(
				'error'  => __( 'Cannot capture booking from this state.', 'handik-booking-app' ),
				'status' => 409,
			);
		}

		$booking_id  = '';
		$booking_uid = '';
		foreach ( array( 'id', 'bookingId' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$booking_id = (string) $payload[ $key ];
				break;
			}
		}
		foreach ( array( 'uid', 'bookingUid' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$booking_uid = (string) $payload[ $key ];
				break;
			}
		}

		$wpdb->update(
			$table,
			array(
				'status'          => self::STATUS_BOOKED,
				'cal_booking_id'  => $booking_id,
				'cal_booking_uid' => $booking_uid,
			),
			array( 'id' => (int) $request_id )
		);

		if ( $this->logger ) {
			$this->logger->info(
				'Direct booking captured from iframe.',
				array(
					'request_id'    => $request_id,
					'cal_booking_id'  => $booking_id,
					'cal_booking_uid' => $booking_uid,
				)
			);
		}
		return array( 'success' => true );
	}

	/**
	 * @param int $id Direct request ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", max( 1, (int) $limit ) ),
			ARRAY_A
		);
	}

	/**
	 * @param string $cal_booking_id Cal numeric ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_cal_booking_id( $cal_booking_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE cal_booking_id = %s OR cal_booking_uid = %s LIMIT 1",
				(string) $cal_booking_id,
				(string) $cal_booking_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function update_status_by_uid( $uid, $status ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$wpdb->update( $table, array( 'status' => sanitize_key( $status ) ), array( 'cal_booking_uid' => (string) $uid ) );
	}

	// ---------- helpers --------------------------------------------------

	/**
	 * Build the Cal.com iframe URL with proper RFC-3986 encoding.
	 *
	 * @param int                       $request_id Direct request ID.
	 * @param array<string, mixed>      $preset     Hydrated preset row.
	 * @param array<string, mixed>|null $contact    Hydrated contact row.
	 * @param string                    $address    Address line.
	 * @param string                    $unit       Apt/Unit.
	 * @return string
	 */
	protected function build_cal_url( $request_id, array $preset, $contact, $address, $unit ) {
		$base = trim( (string) ( $preset['cal_event_url'] ?? '' ) );
		if ( '' === $base ) {
			$base = $this->event_url_for_type( (string) $preset['booking_type'] );
		}
		if ( '' === $base ) {
			if ( $this->logger ) {
				$this->logger->warning(
					'Direct booking: no Cal event URL configured for booking_type.',
					array(
						'request_id'   => $request_id,
						'booking_type' => $preset['booking_type'],
					)
				);
			}
			return '';
		}

		$attendee_phone = $contact && ! empty( $contact['phone'] ) ? (string) $contact['phone'] : '';
		$location_address = trim( implode( ', ', array_filter( array( $address, $unit ) ) ) );
		$location_address = $this->normalize_address_country( $location_address );

		$params = array_filter(
			array(
				'name'                                  => $contact['full_name'] ?? '',
				'email'                                 => $contact && ! empty( $contact['email'] ) ? (string) $contact['email'] : '',
				'attendeePhoneNumber'                   => $attendee_phone,
				'duration'                              => (string) ( (int) $preset['duration_minutes'] ),
				'notes'                                 => $this->build_notes( $request_id ),
				'metadata[handik_booking_source]'       => 'direct_booking_form',
				'metadata[handik_preset_slug]'          => (string) $preset['preset_slug'],
				'metadata[handik_duration_minutes]'     => (string) ( (int) $preset['duration_minutes'] ),
				'metadata[handik_booking_type]'         => (string) $preset['booking_type'],
				'metadata[handik_direct_request_id]'    => (string) $request_id,
				'metadata[handik_contact_id]'           => $contact ? (string) $contact['id'] : '',
				'metadata[handik_client_type]'          => $contact && ! empty( $contact['is_returning'] ) ? 'returning_client' : 'new_client',
				'metadata[handik_cal_url_builder]'      => 'forms_v1',
			),
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);

		if ( '' !== $location_address ) {
			$params['location'] = wp_json_encode(
				array(
					'value'       => 'attendeeInPerson',
					'optionValue' => $location_address,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		}

		return Handik_Booking_App_Forms_Helpers::build_encoded_url( $base, $params );
	}

	protected function event_url_for_type( $booking_type ) {
		$map = array(
			'standard_visit' => 'cal_standard_event_url',
			'extended_visit' => 'cal_extended_event_url',
			'large_visit'    => 'cal_large_event_url',
		);
		$key = $map[ $booking_type ] ?? '';
		if ( '' === $key ) {
			return '';
		}
		return trim( (string) $this->settings->get( $key, '' ) );
	}

	protected function build_notes( $request_id ) {
		$operator = (string) $this->settings->get( 'operator_first_name', 'Alex' );
		if ( '' === trim( $operator ) ) {
			$operator = 'Alex';
		}
		return sprintf(
			/* translators: 1: operator first name, 2: internal request ID */
			__( '%1$s will take care of it. Full details are saved in the Handik admin dashboard (request #%2$d).', 'handik-booking-app' ),
			$operator,
			(int) $request_id
		);
	}

	/**
	 * Replace cyrillic country names with the plain "USA" string and trim
	 * trailing punctuation. Cal.com mangles unicode in `location` query, so
	 * this avoids the `США` rendering that confuses customers.
	 *
	 * @param string $address Address.
	 * @return string
	 */
	protected function normalize_address_country( $address ) {
		$address = (string) $address;
		if ( '' === $address ) {
			return '';
		}
		$replacements = array( 'США', 'Сша', 'сша', 'U.S.A.', 'U.S.A', 'United States of America', 'United States' );
		foreach ( $replacements as $needle ) {
			$address = str_replace( $needle, 'USA', $address );
		}
		return trim( preg_replace( '/\s+/', ' ', $address ) );
	}

	// `build_encoded_url` + `client_ip_packed` moved to Forms_Helpers (shared
	// across the three forms services so they can't drift again).

	protected static function form_type( array $preset ) {
		return isset( $preset['form_type'] ) ? (string) $preset['form_type'] : '';
	}
}
