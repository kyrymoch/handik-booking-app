<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Controller {
	/**
	 * @var Handik_Booking_App_State
	 */
	protected $state;

	/**
	 * @var Handik_Booking_App_Schema
	 */
	protected $schema;

	/**
	 * @var Handik_Booking_App_Upload_Service
	 */
	protected $upload_service;

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Appearance_Service
	 */
	protected $appearance;

	/**
	 * @var Handik_Booking_App_Auth_Service
	 */
	protected $auth;

	/**
	 * @var Handik_Booking_App_Contacts_Service
	 */
	protected $contacts;

	/**
	 * @var Handik_Booking_App_Addresses_Service
	 */
	protected $addresses;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @var Handik_Booking_App_Bookings_Service
	 */
	protected $bookings;

	/**
	 * @var Handik_Booking_App_Routing_Service
	 */
	protected $routing;

	/**
	 * @var Handik_Booking_App_Cal_Service
	 */
	protected $cal;

	/**
	 * @var Handik_Booking_App_Changelog_Service
	 */
	protected $changelog;

	/**
	 * @param Handik_Booking_App_State                 $state State.
	 * @param Handik_Booking_App_Schema                $schema Schema.
	 * @param Handik_Booking_App_Upload_Service        $upload_service Uploads.
	 * @param Handik_Booking_App_Settings              $settings Settings.
	 * @param Handik_Booking_App_Appearance_Service    $appearance Appearance.
	 * @param Handik_Booking_App_Auth_Service          $auth Auth.
	 * @param Handik_Booking_App_Contacts_Service      $contacts Contacts.
	 * @param Handik_Booking_App_Addresses_Service     $addresses Addresses.
	 * @param Handik_Booking_App_Job_Requests_Service  $job_requests Requests.
	 * @param Handik_Booking_App_Bookings_Service      $bookings Bookings.
	 * @param Handik_Booking_App_Routing_Service       $routing Routing.
	 * @param Handik_Booking_App_Cal_Service           $cal Cal.
	 * @param Handik_Booking_App_Changelog_Service     $changelog Changelog.
	 */
	public function __construct( $state, $schema, $upload_service, $settings, $appearance, $auth, $contacts, $addresses, $job_requests, $bookings, $routing, $cal, $changelog ) {
		$this->state          = $state;
		$this->schema         = $schema;
		$this->upload_service = $upload_service;
		$this->settings       = $settings;
		$this->appearance     = $appearance;
		$this->auth           = $auth;
		$this->contacts       = $contacts;
		$this->addresses      = $addresses;
		$this->job_requests   = $job_requests;
		$this->bookings       = $bookings;
		$this->routing        = $routing;
		$this->cal            = $cal;
		$this->changelog      = $changelog;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function bootstrap() {
		$contact_id = $this->auth->current_contact_id();
		return array(
			'success'         => true,
			'version'         => HANDIK_BOOKING_APP_VERSION,
			'db_version'      => (string) get_option( Handik_Booking_App_Migrations::OPTION_NAME, '0.0.0' ),
			'task_catalog'    => $this->state->task_catalog(),
			'steps'           => $this->state->steps(),
			'default_state'   => $this->schema->default_state(),
			'appearance'      => $this->appearance->css_variables(),
			'verified_profile'=> $contact_id ? $this->auth->profile( $contact_id ) : null,
			'changelog'       => $this->changelog->get_entries(),
			'cal_configured'  => array_filter( $this->cal->event_map() ),
		);
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>
	 */
	public function save_draft( array $payload ) {
		$request_id  = ! empty( $payload['request_id'] ) ? absint( $payload['request_id'] ) : 0;
		$draft_token = ! empty( $payload['draft_token'] ) ? sanitize_text_field( $payload['draft_token'] ) : '';

		if ( $request_id && ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$selected_tasks = array_values(
			array_filter(
				array_map( 'sanitize_text_field', array_map( 'strval', $payload['selected_tasks'] ?? array() ) )
			)
		);
		$is_project = ! empty( $payload['is_project'] );
		$job_shape  = $is_project ? 'project' : ( count( $selected_tasks ) > 1 ? 'multiple_tasks' : 'single_task' );
		$contact_id = $this->auth->current_contact_id();
		$is_returning = $contact_id > 0 || ( ! empty( $payload['client_type'] ) && 'returning_client' === $payload['client_type'] );

		$contact_payload = array(
			'first_name'   => sanitize_text_field( $payload['first_name'] ?? '' ),
			'last_name'    => sanitize_text_field( $payload['last_name'] ?? '' ),
			'full_name'    => sanitize_text_field( $payload['full_name'] ?? '' ),
			'email'        => sanitize_email( $payload['email'] ?? '' ),
			'phone'        => $this->contacts->normalize_phone( $payload['phone'] ?? '' ),
			'source'       => 'booking_app',
			'is_returning' => $is_returning,
		);
		$contact_id = $this->contacts->upsert( $contact_payload, $contact_id );

		$address_id = $this->addresses->sync(
			$contact_id,
			array(
				'address_id'     => absint( $payload['address_id'] ?? 0 ),
				'address_full'   => $payload['address_full'] ?? '',
				'address_line_1' => $payload['address_line_1'] ?? '',
				'address_unit'   => $payload['address_unit'] ?? '',
				'city'           => $payload['city'] ?? '',
				'state'          => $payload['state'] ?? '',
				'zip_code'       => $payload['zip_code'] ?? '',
				'is_default'     => true,
			)
		);

		$saved = $this->job_requests->save_draft(
			array(
				'client_type'         => ! empty( $payload['client_type'] ) ? sanitize_key( $payload['client_type'] ) : ( $is_returning ? 'returning_client' : 'new_client' ),
				'job_shape'           => $job_shape,
				'selected_tasks'      => $selected_tasks,
				'is_project'          => $is_project,
				'address_full'        => sanitize_textarea_field( $payload['address_full'] ?? '' ),
				'address_unit'        => sanitize_text_field( $payload['address_unit'] ?? '' ),
				'short_description'   => sanitize_textarea_field( $payload['short_description'] ?? '' ),
				'photos'              => is_array( $payload['photos'] ?? null ) ? $payload['photos'] : array(),
				'preferred_timeframe' => sanitize_text_field( $payload['preferred_timeframe'] ?? '' ),
				'app_step'            => sanitize_key( $payload['app_step'] ?? 'address_photos' ),
				'app_session_key'     => sanitize_text_field( $payload['app_session_key'] ?? '' ),
				'app_state'           => is_array( $payload['app_state'] ?? null ) ? $payload['app_state'] : array(),
				'status'              => sanitize_key( $payload['status'] ?? 'draft' ),
				'lookup_verified'     => $is_returning,
			),
			$contact_id,
			$address_id,
			$request_id
		);

		$request         = $saved['request'];
		$effective_token = ! empty( $saved['draft_token'] ) ? $saved['draft_token'] : $draft_token;
		$routing_preview = $this->routing->route( $request );

		return array(
			'success'       => true,
			'request_id'    => (int) $request['id'],
			'draft_token'   => $effective_token,
			'request'       => $request,
			'routing'       => $routing_preview,
			'contact'       => $contact_id ? $this->contacts->get( $contact_id ) : null,
			'addresses'     => $contact_id ? $this->addresses->list_for_contact( $contact_id ) : array(),
		);
	}

	/**
	 * @param array<string, mixed> $file File.
	 * @return array<string, mixed>
	 */
	public function upload_photo( array $file ) {
		return $this->upload_service->upload_image( $file );
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function booking_url( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}
		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}
		if ( empty( $request['booking_type'] ) ) {
			$routing = $this->routing->route( $request );
			$this->job_requests->apply_routing( $request_id, $routing, array() );
		}
		$url = $this->cal->build_booking_url( $request_id );
		if ( ! $url ) {
			return array( 'error' => __( 'Cal.com is not configured for this booking type.', 'handik-booking-app' ), 'status' => 400 );
		}
		$this->job_requests->mark_booking_pending( $request_id );
		return array( 'success' => true, 'booking_url' => $url );
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function complete_booking_step( $request_id, $draft_token ) {
		return $this->booking_status( $request_id, $draft_token );
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function booking_status( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}
		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$booking       = $this->bookings->find_latest_for_request( $request_id );
		$status        = sanitize_key( (string) ( $booking['status'] ?? $request['status'] ?? 'booking_pending' ) );
		$is_confirmed  = in_array( $status, array( 'booked', 'rescheduled' ), true );

		if ( $is_confirmed && empty( $request['completed_at'] ) ) {
			$this->job_requests->mark_complete( $request_id, $status );
			$request = $this->job_requests->get( $request_id );
		}

		return array(
			'success'         => true,
			'request_id'      => $request_id,
			'status'          => $status,
			'is_confirmed'    => $is_confirmed,
			'cal_booking_id'  => $request['cal_booking_id'] ?? '',
			'booking'         => $booking,
			'request'         => $request,
		);
	}
}
