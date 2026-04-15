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
		$params  = array_filter(
			array(
				'name'                            => $contact['full_name'] ?? '',
				'email'                           => $contact['email'] ?? '',
				'notes'                           => $request['assistant_summary'] ?: $request['short_description'],
				'metadata[handik_job_request_id]' => (string) $request_id,
				'metadata[handik_booking_type]'   => (string) $request['booking_type'],
				'metadata[handik_contact_id]'     => ! empty( $request['contact_id'] ) ? (string) $request['contact_id'] : '',
				'metadata[handik_client_type]'    => $request['client_type'],
			),
			function ( $value ) {
				return '' !== (string) $value;
			}
		);
		if ( ! empty( $contact['phone'] ) ) {
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
}
