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
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	public function extract_booking_id( array $payload ) {
		return sanitize_text_field( (string) ( $payload['bookingId'] ?? $payload['bookingUid'] ?? $payload['uid'] ?? $payload['id'] ?? '' ) );
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
