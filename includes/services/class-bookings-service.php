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
