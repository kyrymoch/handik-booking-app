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
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Bookings_Service     $bookings Bookings.
	 */
	public function __construct( $settings, $logger, $job_requests, $bookings ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function handle_cal_webhook( WP_REST_Request $request ) {
		$raw_body = $request->get_body();
		$payload  = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return array( 'error' => __( 'Invalid webhook payload.', 'handik-booking-app' ), 'status' => 400 );
		}
		if ( ! $this->verify_signature( $request, $raw_body ) ) {
			return array( 'error' => __( 'Webhook signature failed.', 'handik-booking-app' ), 'status' => 403 );
		}

		$event     = sanitize_key( (string) ( $payload['triggerEvent'] ?? $payload['event'] ?? $payload['type'] ?? 'booking_created' ) );
		$data      = ! empty( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : $payload;
		$metadata  = ! empty( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();
		$request_id = ! empty( $metadata['handik_job_request_id'] ) ? absint( $metadata['handik_job_request_id'] ) : 0;

		if ( ! $request_id ) {
			$booking_id = $this->bookings->extract_booking_id( $data );
			$row        = $this->job_requests->find_by_cal_booking_id( $booking_id );
			$request_id = $row ? (int) $row['id'] : 0;
		}

		if ( ! $request_id ) {
			$this->logger->error( 'Could not match Cal webhook to job request.', array( 'payload' => $payload ) );
			return array( 'error' => __( 'Matching request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$data['booking_type'] = ! empty( $metadata['handik_booking_type'] ) ? sanitize_key( $metadata['handik_booking_type'] ) : '';
		$this->bookings->upsert_from_cal( $request_id, $data, $this->map_status( $event ) );

		return array( 'success' => true, 'status' => $this->map_status( $event ) );
	}

	protected function verify_signature( WP_REST_Request $request, $raw_body ) {
		$secret = trim( (string) $this->settings->get( 'cal_webhook_secret', '' ) );
		if ( ! $secret ) {
			return true;
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

	protected function map_status( $event ) {
		switch ( $event ) {
			case 'booking_cancelled':
			case 'booking.cancelled':
				return 'cancelled';
			case 'booking_rescheduled':
			case 'booking.rescheduled':
				return 'rescheduled';
			default:
				return 'booked';
		}
	}
}
