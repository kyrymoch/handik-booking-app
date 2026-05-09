<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Bookings_Service {
	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 */
	public function __construct( $logger, $job_requests ) {
		$this->logger       = $logger;
		$this->job_requests = $job_requests;
	}

	/**
	 * @param int                  $job_request_id Request.
	 * @param array<string, mixed> $payload Payload.
	 * @param string               $status Status.
	 * @return int
	 */
	public function upsert_from_cal( $job_request_id, array $payload, $status ) {
		global $wpdb;
		$table      = Handik_Booking_App_DB::table( 'bookings' );
		$booking_id = $this->extract_booking_id( $payload );

		if ( ! $booking_id ) {
			$this->logger->error( 'Missing Cal booking ID.', array( 'payload' => $payload ) );
			return 0;
		}

		$record = array(
			'job_request_id'   => $job_request_id,
			'cal_booking_id'   => $booking_id,
			'booking_type'     => ! empty( $payload['booking_type'] ) ? sanitize_key( $payload['booking_type'] ) : '',
			'event_type_slug'  => ! empty( $payload['eventTypeSlug'] ) ? sanitize_key( $payload['eventTypeSlug'] ) : sanitize_key( (string) ( $payload['type'] ?? '' ) ),
			'duration_minutes' => absint( $payload['duration'] ?? $payload['lengthInMinutes'] ?? 0 ),
			'start_time'       => $this->normalize_datetime( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_time'         => $this->normalize_datetime( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'status'           => sanitize_key( $status ),
			'raw_webhook_json' => wp_json_encode( $payload ),
		);

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", $booking_id ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing['id'] ) );
			$row_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, $record );
			$row_id = (int) $wpdb->insert_id;
		}

		$this->job_requests->set_cal_booking( $job_request_id, $booking_id, $status );

		// Sprint 14a — fire the booking-confirmed action ONLY on first
		// transition into a confirmed state. Atomic UPDATE on the new
		// `confirmation_email_sent_at` column inside Notifications_Service
		// handles webhook-retry deduping; we only need to gate on status
		// here so cancellations / reschedules don't trigger a new email.
		if ( 'booked' === sanitize_key( $status ) && class_exists( 'Handik_Booking_App_Notifications_Service' ) ) {
			Handik_Booking_App_Notifications_Service::dispatch_for_cal( (int) $job_request_id, $row_id, $payload );
		}
		return $row_id;
	}

	/**
	 * Sprint 13.5 — mirror a direct-booking-form Cal booking into the
	 * canonical `handik_bookings` table.
	 *
	 * Owner-reported visibility bug: admin's "+ Add booking" flow + every
	 * public direct-booking submission was creating rows ONLY in
	 * `handik_direct_booking_requests`. The unified Bookings list reads
	 * `handik_bookings` only, so direct rows were invisible there. This
	 * upsert closes the gap — called from BOTH `Direct_Booking_Service::
	 * capture_booking()` (leading-edge, fires on Cal embed
	 * `bookingSuccessful`) and `Webhook_Service::dispatch_direct()`
	 * (trailing-edge, fires when Cal webhook lands). Whichever path
	 * arrives first wins; the other is a no-op idempotent UPDATE on
	 * the UNIQUE `cal_booking_id` row.
	 *
	 * Schema 1.5.0 made `job_request_id` NULLable + added
	 * `direct_request_id`, so a direct booking lives as
	 * `(job_request_id=NULL, direct_request_id=N, cal_booking_id=X)`.
	 *
	 * @param int                  $direct_request_id ID from `handik_direct_booking_requests`.
	 * @param array<string, mixed> $payload          Cal payload (same shape as
	 *                                                 `upsert_from_cal`'s `$data`).
	 * @param string               $status           Mapped status (e.g. 'booked', 'cancelled').
	 * @return int Row id in handik_bookings (0 on failure).
	 */
	public function upsert_from_direct_capture( $direct_request_id, array $payload, $status ) {
		global $wpdb;
		$table      = Handik_Booking_App_DB::table( 'bookings' );
		$booking_id = $this->extract_booking_id( $payload );

		// Unlike upsert_from_cal, a direct capture path can sometimes
		// fire BEFORE the webhook hands us a real Cal id (the JS-side
		// capture flow used to send empty UIDs — that bug was fixed in
		// 2.1.20.1 hotfix F1+F2 — but be defensive). If we don't have
		// a Cal id yet, bail; the trailing webhook will retry with a
		// proper id.
		if ( ! $booking_id ) {
			if ( $this->logger ) {
				$this->logger->info( 'Skipped direct-capture mirror — no Cal booking id yet (will retry on webhook).', array(
					'direct_request_id' => $direct_request_id,
				) );
			}
			return 0;
		}

		$record = array(
			'job_request_id'   => null,
			'direct_request_id' => (int) $direct_request_id,
			'cal_booking_id'   => $booking_id,
			'booking_type'     => ! empty( $payload['booking_type'] ) ? sanitize_key( $payload['booking_type'] ) : '',
			'event_type_slug'  => ! empty( $payload['eventTypeSlug'] ) ? sanitize_key( $payload['eventTypeSlug'] ) : sanitize_key( (string) ( $payload['type'] ?? '' ) ),
			'duration_minutes' => absint( $payload['duration'] ?? $payload['lengthInMinutes'] ?? 0 ),
			'start_time'       => $this->normalize_datetime( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_time'         => $this->normalize_datetime( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'status'           => sanitize_key( $status ),
			'raw_webhook_json' => wp_json_encode( $payload ),
		);

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", $booking_id ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing['id'] ) );
			$row_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, $record );
			$row_id = (int) $wpdb->insert_id;
		}

		// Sprint 14a — also fire from the trailing-edge webhook path so a
		// customer who abandoned the Cal embed (no leading-edge capture)
		// still gets the email when Cal eventually webhooks the booking.
		// Direct flow's leading-edge path in `Direct_Booking_Service::
		// capture_booking()` ALSO dispatches; idempotency on the
		// `direct_booking_requests.confirmation_email_sent_at` column
		// guarantees only one email goes out per real booking.
		if ( 'booked' === sanitize_key( $status ) && class_exists( 'Handik_Booking_App_Notifications_Service' ) ) {
			Handik_Booking_App_Notifications_Service::dispatch_for_direct( (int) $direct_request_id, $payload );
		}
		return $row_id;
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Cheap COUNT for dashboard widgets — avoids fetching full rows just to count them.
	 *
	 * @return int
	 */
	public function count_all() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Effective status taking the manual admin override into account.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return string
	 */
	public function effective_status( array $booking ) {
		if ( ! empty( $booking['admin_status_override'] ) ) {
			return (string) $booking['admin_status_override'];
		}
		return (string) ( $booking['status'] ?? '' );
	}

	/**
	 * @param string $from_utc DATETIME string in UTC (inclusive).
	 * @param string $to_utc   DATETIME string in UTC (exclusive).
	 * @param int|null $limit  Max rows or null for unlimited.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_in_window( $from_utc, $to_utc, $limit = null ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$sql   = "SELECT * FROM {$table} WHERE start_time >= %s AND start_time < %s ORDER BY start_time ASC";
		if ( null !== $limit ) {
			$sql .= ' LIMIT ' . max( 1, (int) $limit );
		}
		return $wpdb->get_results( $wpdb->prepare( $sql, $from_utc, $to_utc ), ARRAY_A );
	}

	public function count_in_window( $from_utc, $to_utc ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE start_time >= %s AND start_time < %s", $from_utc, $to_utc )
		);
	}

	public function avg_duration_in_window( $from_utc, $to_utc ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$avg   = $wpdb->get_var(
			$wpdb->prepare( "SELECT AVG(duration_minutes) FROM {$table} WHERE start_time >= %s AND start_time < %s AND duration_minutes > 0", $from_utc, $to_utc )
		);
		return null === $avg ? 0.0 : (float) $avg;
	}

	public function list_upcoming( $limit = 5 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE start_time >= %s ORDER BY start_time ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Update admin-only fields on a booking. Logs the change.
	 *
	 * @param int                  $booking_id Booking ID.
	 * @param array<string, mixed> $patch      Allowed keys: admin_notes, admin_status_override.
	 * @return bool
	 */
	public function update_admin_fields( $booking_id, array $patch ) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 ) {
			return false;
		}
		$update = array();
		if ( array_key_exists( 'admin_notes', $patch ) ) {
			$update['admin_notes'] = is_null( $patch['admin_notes'] ) ? null : sanitize_textarea_field( (string) $patch['admin_notes'] );
		}
		if ( array_key_exists( 'admin_status_override', $patch ) ) {
			$value = $patch['admin_status_override'];
			if ( is_null( $value ) || '' === $value ) {
				$update['admin_status_override'] = null;
			} else {
				$allowed = array( 'cancelled', 'completed', 'rescheduled', 'no_show' );
				$value   = sanitize_key( (string) $value );
				$update['admin_status_override'] = in_array( $value, $allowed, true ) ? $value : null;
			}
		}
		if ( empty( $update ) ) {
			return false;
		}
		$wpdb->update(
			Handik_Booking_App_DB::table( 'bookings' ),
			$update,
			array( 'id' => $booking_id )
		);
		$this->logger->info(
			'Admin updated booking fields.',
			array(
				'booking_id' => $booking_id,
				'fields'     => array_keys( $update ),
				'admin_id'   => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $booking_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $booking_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Sprint 7 (admin perf): bulk fetch bookings by ID for the dashboard's
	 * `ensure_next_visit_decorations` loops which used to do one `get()` per
	 * booking ID across today/tomorrow/week + the next-visits cache.
	 *
	 * @param array<int, int> $ids Booking ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_many( array $ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = Handik_Booking_App_DB::table( 'bookings' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * @param int $job_request_id Request.
	 * @return array<string, mixed>|null
	 */
	public function find_latest_for_request( $job_request_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_request_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1", $job_request_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Sprint 7 (admin perf): bulk equivalent of `find_latest_for_request`,
	 * keyed by job_request_id. Used by `class-admin-people.php::bookings_for_contact`
	 * which used to fan out N single-row lookups per contact card. Picks
	 * the highest (updated_at, id) per request_id with one window-style
	 * pass: ORDER BY descending, then in PHP take the first row we see for
	 * each request_id (cheaper than a SQL window function on MySQL 5.7).
	 *
	 * @param array<int, int> $job_request_ids Job request ids.
	 * @return array<int, array<string, mixed>> Keyed by job_request_id.
	 */
	public function find_latest_for_requests( array $job_request_ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $job_request_ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = Handik_Booking_App_DB::table( 'bookings' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_request_id IN ({$placeholders}) ORDER BY job_request_id ASC, updated_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$rid = (int) $row['job_request_id'];
			if ( ! isset( $out[ $rid ] ) ) {
				$out[ $rid ] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	public function extract_booking_id( array $payload ) {
		return sanitize_text_field( (string) ( $payload['bookingId'] ?? $payload['bookingUid'] ?? $payload['uid'] ?? $payload['id'] ?? '' ) );
	}

	/**
	 * Sprint 12 — hard-delete a single booking row.
	 *
	 * Per the audit, `cal_booking_id` is denormalized: the same Cal UID
	 * is mirrored on `handik_job_requests.cal_booking_id` /
	 * `cal_booking_url`. After we drop the booking row that parent
	 * back-pointer would still claim "there's a Cal booking attached"
	 * even though the row is gone, which corrupts the bookings-list
	 * status logic. We clear those columns first; the request itself
	 * stays (use Job_Requests_Service::delete_hard for that).
	 *
	 * The Cal.com side is intentionally NOT cancelled — owner-decided
	 * scope for Sprint 12 (visit happened; we're cleaning local DB).
	 *
	 * @param int $booking_id Booking id.
	 * @return bool True if a row was deleted.
	 */
	public function delete_hard( $booking_id ) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 ) {
			return false;
		}
		$table         = Handik_Booking_App_DB::table( 'bookings' );
		$requests      = Handik_Booking_App_DB::table( 'job_requests' );
		$row           = $this->get( $booking_id );
		if ( ! $row ) {
			return false;
		}
		$cal_uid       = (string) ( $row['cal_booking_id'] ?? '' );
		$request_id    = (int) ( $row['job_request_id'] ?? 0 );

		// Clear the parent's back-pointer if THIS booking was the
		// authoritative one for the request (matched by uid). Multiple
		// bookings on one request would each clear in turn — that's OK,
		// the column is a single-value pointer that the next booking-
		// upsert re-populates.
		if ( '' !== $cal_uid && $request_id > 0 ) {
			$wpdb->update(
				$requests,
				array( 'cal_booking_id' => '', 'cal_booking_url' => null ),
				array( 'id' => $request_id, 'cal_booking_id' => $cal_uid )
			);
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $booking_id ), array( '%d' ) );
		return false !== $deleted && $deleted > 0;
	}

	/**
	 * @param string $value Datetime.
	 * @return string|null
	 */
	protected function normalize_datetime( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		$timestamp = strtotime( (string) $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}
