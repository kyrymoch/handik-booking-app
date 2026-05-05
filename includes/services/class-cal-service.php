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
		$notes              = $this->build_cal_notes( $request_id, $request, $contact );
		$params  = array_filter(
			array(
				'name'                            => $contact['full_name'] ?? '',
				'email'                           => $contact['email'] ?? '',
				'phone'                           => $contact['phone'] ?? '',
				'notes'                           => $notes,
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
		if ( $this->logger ) {
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
	 * Build the `notes` payload sent to Cal.com using the admin-editable
	 * cal_confirmation_note template (D4). Falls back to the previous behavior
	 * (assistant summary or short description) when the template is empty or
	 * doesn't include any placeholder.
	 *
	 * @param int                  $request_id Request ID.
	 * @param array<string, mixed> $request    Hydrated request row.
	 * @param array<string, mixed>|null $contact Hydrated contact row.
	 * @return string
	 */
	protected function build_cal_notes( $request_id, array $request, $contact ) {
		$template = trim( (string) $this->settings->get( 'cal_confirmation_note', '' ) );
		$assistant_summary = (string) ( $request['assistant_summary'] ?? '' );
		$short_description = (string) ( $request['short_description'] ?? '' );
		$fallback = $assistant_summary !== '' ? $assistant_summary : $short_description;

		if ( '' === $template ) {
			return $fallback;
		}

		$task_ids = is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array();
		$task_summary = '';
		if ( ! empty( $task_ids ) && class_exists( 'Handik_Booking_App_Admin_Helpers' ) ) {
			// Reuse the same formatter the admin uses, but only if the helpers
			// class is loaded (which it is in admin context; otherwise fall back).
			$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
			if ( $plugin && ! empty( $plugin->service_catalog ) ) {
				$task_summary = Handik_Booking_App_Admin_Helpers::task_summary_text( $task_ids, $plugin->service_catalog, 99 );
			}
		}
		if ( '' === $task_summary ) {
			$task_summary = implode( ', ', array_map( 'strval', $task_ids ) );
		}

		$placeholders = array(
			'request_id'    => (string) $request_id,
			'customer_name' => (string) ( $contact['full_name'] ?? '' ),
			'address'       => (string) ( $request['address_full'] ?? '' ),
			'task_summary'  => $task_summary,
			'assistant_summary' => $assistant_summary,
		);

		$out = $template;
		foreach ( $placeholders as $key => $value ) {
			$out = str_replace( '{{' . $key . '}}', $value, $out );
		}

		// Append assistant summary if it's non-empty and not already in the
		// rendered text — owner usually wants the AI gist, but the template
		// may not have that placeholder.
		if ( $assistant_summary && false === strpos( $out, $assistant_summary ) ) {
			$out = trim( $out . "\n\n" . $assistant_summary );
		}

		return trim( $out );
	}
}
