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
		$metadata  = array();
		if ( ! empty( $data['metadata'] ) ) {
			if ( is_array( $data['metadata'] ) ) {
				$metadata = $data['metadata'];
			} elseif ( is_string( $data['metadata'] ) ) {
				$decoded = json_decode( $data['metadata'], true );
				$metadata = is_array( $decoded ) ? $decoded : array();
			}
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
		$this->bookings->upsert_from_cal( $request_id, $data, $this->map_status( $event ) );
		$this->logger->info(
			'Cal webhook synced booking.',
			array(
				'request_id' => $request_id,
				'event'      => $event,
				'booking_id' => $this->bookings->extract_booking_id( $data ),
				'status'     => $this->map_status( $event ),
			)
		);

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
				return 'cancelled';
			case 'booking_rescheduled':
				return 'rescheduled';
			default:
				return 'booked';
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
