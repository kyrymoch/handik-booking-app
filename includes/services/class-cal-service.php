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
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Contacts_Service     $contacts Contacts.
	 */
	public function __construct( $settings, $job_requests, $contacts ) {
		$this->settings     = $settings;
		$this->job_requests = $job_requests;
		$this->contacts     = $contacts;
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
		$map     = $this->event_map();
		$base    = $map[ $request['booking_type'] ] ?? '';
		if ( ! $base ) {
			return '';
		}
		$contact = ! empty( $request['contact_id'] ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
		$assistant_result = ! empty( $request['assistant_result'] ) && is_array( $request['assistant_result'] ) ? $request['assistant_result'] : array();
		$app_state        = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$suggested_duration = ! empty( $assistant_result['suggested_duration_hours'] ) ? (string) $assistant_result['suggested_duration_hours'] : (string) ( $app_state['suggested_duration_hours'] ?? '' );
		$pricing_posture    = ! empty( $assistant_result['pricing_posture'] ) ? (string) $assistant_result['pricing_posture'] : (string) ( $app_state['pricing_posture'] ?? '' );
		$params  = array_filter(
			array(
				'name'                            => $contact['full_name'] ?? '',
				'email'                           => $contact['email'] ?? '',
				'phone'                           => $contact['phone'] ?? '',
				'notes'                           => $request['assistant_summary'] ?: $request['short_description'],
				'metadata[handik_job_request_id]' => (string) $request_id,
				'metadata[handik_booking_type]'   => (string) $request['booking_type'],
				'metadata[handik_contact_id]'     => ! empty( $request['contact_id'] ) ? (string) $request['contact_id'] : '',
				'metadata[handik_client_type]'    => $request['client_type'],
				'metadata[handik_suggested_duration_hours]' => $suggested_duration,
				'metadata[handik_pricing_posture]'          => $pricing_posture,
			),
			function ( $value ) {
				return '' !== (string) $value;
			}
		);

		$duration_minutes = $this->duration_minutes( $suggested_duration );
		if ( $duration_minutes > 0 ) {
			$params['duration'] = (string) $duration_minutes;
		}
		$location_address = trim( implode( ', ', array_filter( array( $request['address_full'] ?? '', $request['address_unit'] ?? '' ) ) ) );
		if ( $location_address ) {
			$params['location'] = wp_json_encode(
				array(
					'value'       => 'attendeeInPerson',
					'optionValue' => $location_address,
				)
			);
		} elseif ( ! empty( $contact['phone'] ) ) {
			$params['location'] = wp_json_encode(
				array(
					'value'       => 'phone',
					'optionValue' => $contact['phone'],
				)
			);
		}
		$url = add_query_arg( $params, $base );
		$this->job_requests->set_booking_url( $request_id, $url, $request['booking_type'] );
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
}
