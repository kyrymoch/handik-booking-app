<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Cal_Service {
	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @var Handik_Booking_App_Contacts_Service
	 */
	protected $contacts;

	/**
	 * @var Handik_Booking_App_Logger|null
	 */
	protected $logger;

	/**
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Contacts_Service     $contacts Contacts.
	 * @param Handik_Booking_App_Logger|null          $logger Logger.
	 */
	public function __construct( $settings, $job_requests, $contacts, $logger = null ) {
		$this->settings     = $settings;
		$this->job_requests = $job_requests;
		$this->contacts     = $contacts;
		$this->logger       = $logger;
	}

	/**
	 * @return array<string, string>
	 */
	public function event_map() {
		return array(
			'standard_visit'       => trim( (string) $this->settings->get( 'cal_standard_event_url', '' ) ),
			'extended_visit'       => trim( (string) $this->settings->get( 'cal_extended_event_url', '' ) ),
			'large_visit'          => trim( (string) $this->settings->get( 'cal_large_event_url', '' ) ),
			'project_consultation' => trim( (string) $this->settings->get( 'cal_project_event_url', '' ) ),
		);
	}

	/**
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function build_booking_url( $request_id ) {
		$request = $this->job_requests->get( $request_id );
		if ( ! $request || empty( $request['booking_type'] ) ) {
			return '';
		}
		$assistant_result = ! empty( $request['assistant_result'] ) && is_array( $request['assistant_result'] ) ? $request['assistant_result'] : array();
		$app_state        = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$suggested_duration = ! empty( $assistant_result['suggested_duration_hours'] ) ? (string) $assistant_result['suggested_duration_hours'] : (string) ( $app_state['suggested_duration_hours'] ?? '' );
		$current_duration_minutes = $this->duration_minutes( $suggested_duration );
		if ( ! empty( $request['cal_booking_url'] ) ) {
			$stored_query = wp_parse_url( $request['cal_booking_url'], PHP_URL_QUERY );
			$stored_args  = array();
			if ( $stored_query ) {
				wp_parse_str( $stored_query, $stored_args );
			}
			$stored_duration_minutes = isset( $stored_args['duration'] ) ? (int) $stored_args['duration'] : 0;
			$stored_booking_type = '';
			if ( ! empty( $stored_args['metadata'] ) && is_array( $stored_args['metadata'] ) && ! empty( $stored_args['metadata']['handik_booking_type'] ) ) {
				$stored_booking_type = sanitize_key( (string) $stored_args['metadata']['handik_booking_type'] );
			}
			$stored_builder_version = ! empty( $stored_args['metadata'] ) && is_array( $stored_args['metadata'] ) && ! empty( $stored_args['metadata']['handik_cal_url_builder'] )
				? sanitize_key( (string) $stored_args['metadata']['handik_cal_url_builder'] )
				: '';
			if ( $stored_duration_minutes > 0 && $stored_duration_minutes === $current_duration_minutes && $stored_booking_type === (string) $request['booking_type'] && '2' === $stored_builder_version ) {
				if ( $this->logger ) {
					$this->logger->info(
						'Reusing existing Cal booking URL.',
						array(
							'request_id'       => $request_id,
							'booking_type'     => (string) $request['booking_type'],
							'duration_minutes' => $stored_duration_minutes,
						)
					);
				}
				return (string) $request['cal_booking_url'];
			}
			if ( $this->logger ) {
				$this->logger->info(
					'Rebuilding Cal booking URL because duration changed.',
					array(
						'request_id'      => $request_id,
						'stored_minutes'  => $stored_duration_minutes,
						'current_minutes' => $current_duration_minutes,
					)
				);
			}
		}
		$map     = $this->event_map();
		$base    = $map[ $request['booking_type'] ] ?? '';
		if ( ! $base ) {
			return '';
		}
		$contact = ! empty( $request['contact_id'] ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
		$pricing_posture    = ! empty( $assistant_result['pricing_posture'] ) ? (string) $assistant_result['pricing_posture'] : (string) ( $app_state['pricing_posture'] ?? '' );
		$attendee_phone     = ! empty( $contact['phone'] ) ? (string) $contact['phone'] : '';
		$params  = array_filter(
			array(
				'name'                            => $contact['full_name'] ?? '',
				'email'                           => $contact['email'] ?? '',
				'attendeePhoneNumber'             => $attendee_phone,
				'notes'                           => sprintf( "Alex will take care of it. Full details, photos, and the assistant's notes are saved in the admin dashboard (request #%d).", (int) $request_id ),
				'metadata[handik_job_request_id]' => (string) $request_id,
				'metadata[handik_booking_type]'   => (string) $request['booking_type'],
				'metadata[handik_contact_id]'     => ! empty( $request['contact_id'] ) ? (string) $request['contact_id'] : '',
				'metadata[handik_client_type]'    => $request['client_type'],
				'metadata[handik_suggested_duration_hours]' => $suggested_duration,
				'metadata[handik_pricing_posture]'          => $pricing_posture,
				'metadata[handik_cal_url_builder]'          => '2',
			),
			function ( $value ) {
				return '' !== (string) $value;
			}
		);

		$duration_minutes = $this->duration_minutes( $suggested_duration );
		if ( $duration_minutes > 0 ) {
			$params['duration'] = (string) $duration_minutes;
		}
		$location_json = '';
		$location_address = trim( implode( ', ', array_filter( array( $request['address_full'] ?? '', $request['address_unit'] ?? '' ) ) ) );
		if ( $location_address ) {
			$location_json = wp_json_encode(
				array(
					'value'       => 'attendeeInPerson',
					'optionValue' => $location_address,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			$params['location'] = $location_json;
		} elseif ( $attendee_phone ) {
			$location_json = wp_json_encode(
				array(
					'value'       => 'phone',
					'optionValue' => $attendee_phone,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			$params['location'] = $location_json;
		}
		$url = $this->build_encoded_url( $base, $params );
		$this->job_requests->set_booking_url( $request_id, $url, $request['booking_type'] );
		if ( $this->logger ) {
			$this->logger->debug(
				'Cal URL encoded',
				array(
					'event_url'                  => $base,
					'duration_minutes'           => $duration_minutes,
					'suggested_duration_hours'   => $suggested_duration,
					'has_attendee_phone_number'  => '' !== $attendee_phone,
					'has_location'               => '' !== $location_json,
					'final_url_length'           => strlen( $url ),
					'location_json'              => $location_json,
					'final_url'                  => $url,
				)
			);
			$this->logger->info(
				'Cal booking URL built.',
				array(
					'request_id'               => $request_id,
					'booking_type'             => (string) $request['booking_type'],
					'duration_bucket'          => (string) ( $request['duration_bucket'] ?? '' ),
					'suggested_duration_hours' => $suggested_duration,
					'duration_minutes'         => $duration_minutes,
					'pricing_posture'          => $pricing_posture,
					'event_url_configured'     => ! empty( $base ),
					'cal_booking_url'          => $url,
				)
			);
		}
		return $url;
	}

	/**
	 * @param string $suggested_duration Suggested duration.
	 * @return int
	 */
	protected function duration_minutes( $suggested_duration ) {
		if ( 'consult_1' === $suggested_duration ) {
			return 60;
		}

		if ( preg_match( '/^[1-8]$/', (string) $suggested_duration ) ) {
			return (int) $suggested_duration * 60;
		}

		return 0;
	}

	/**
	 * Build a Cal URL with PHP's URLSearchParams equivalent.
	 *
	 * @param string               $base Base event URL.
	 * @param array<string,string> $params Query params.
	 * @return string
	 */
	protected function build_encoded_url( $base, array $params ) {
		$base     = (string) $base;
		$fragment = '';
		$hash_pos = strpos( $base, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $base, $hash_pos );
			$base     = substr( $base, 0, $hash_pos );
		}

		$query_args = array();
		$query_pos  = strpos( $base, '?' );
		if ( false !== $query_pos ) {
			$query = substr( $base, $query_pos + 1 );
			$base  = substr( $base, 0, $query_pos );
			if ( '' !== $query ) {
				wp_parse_str( $query, $query_args );
			}
		}

		foreach ( $params as $key => $value ) {
			$query_args[ $key ] = (string) $value;
		}

		$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC1738 );
		return $base . ( '' !== $query ? '?' . $query : '' ) . $fragment;
	}
}
