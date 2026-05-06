<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST endpoints for the Additional Booking Forms module.
 *
 * Namespace: handik-booking-app/v1
 *
 *  - GET  /forms/preset/{slug}        public preset metadata
 *  - POST /forms/direct/submit        create direct request, return Cal URL
 *  - POST /forms/direct/{id}/capture  record Cal booking from iframe onComplete
 *  - POST /forms/project/open         step 1+2 save contact/address
 *  - GET  /forms/project/{id}/slots   fetch Cal.com slots (token-protected)
 *  - POST /forms/project/{id}/select  step 3 save N selected slots (token-protected)
 *  - POST /forms/project/{id}/confirm step 5 re-check + create N bookings (token-protected)
 *
 * Submit endpoints carry an X-WP-Nonce (wp_rest) check by virtue of the SPA
 * sending it; tokenized endpoints rely on the unguessable schedule token.
 */
class Handik_Booking_App_Forms_Rest_Api {
	const NAMESPACE_V1 = 'handik-booking-app/v1';

	/** @var Handik_Booking_App_Booking_Presets_Service */
	protected $presets;
	/** @var Handik_Booking_App_Direct_Booking_Service */
	protected $direct;
	/** @var Handik_Booking_App_Project_Schedule_Service */
	protected $project;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	public function __construct( $presets, $direct, $project, $logger = null ) {
		$this->presets = $presets;
		$this->direct  = $direct;
		$this->project = $project;
		$this->logger  = $logger;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$ns = self::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/forms/preset/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_preset' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/forms/direct/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_direct' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/forms/direct/(?P<id>\d+)/capture',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'capture_direct' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/forms/project/open',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'open_project' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/forms/project/(?P<id>\d+)/slots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'project_slots' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/forms/project/(?P<id>\d+)/select',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'project_select' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/forms/project/(?P<id>\d+)/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'project_confirm' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ---------- handlers --------------------------------------------------

	public function get_preset( WP_REST_Request $request ) {
		$slug   = sanitize_title( (string) $request['slug'] );
		$preset = $this->presets->find_by_slug( $slug );
		if ( ! $preset || empty( $preset['enabled'] ) ) {
			return new WP_Error(
				'handik_form_not_available',
				__( 'This booking form is not available right now.', 'handik-booking-app' ),
				array( 'status' => 404 )
			);
		}
		return rest_ensure_response( array( 'preset' => $this->public_preset( $preset ) ) );
	}

	public function submit_direct( WP_REST_Request $request ) {
		$slug    = sanitize_title( (string) $request->get_param( 'preset_slug' ) );
		$payload = (array) $request->get_json_params();
		if ( ! isset( $payload['source_url'] ) ) {
			$payload['source_url'] = (string) $request->get_param( 'source_url' );
		}
		$result = $this->direct->submit( $slug, $payload );
		return $this->respond( $result );
	}

	public function capture_direct( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$payload = (array) $request->get_json_params();
		$booking = isset( $payload['booking_payload'] ) && is_array( $payload['booking_payload'] )
			? $payload['booking_payload']
			: $payload;
		$result = $this->direct->capture_booking( $id, $booking );
		return $this->respond( $result );
	}

	public function open_project( WP_REST_Request $request ) {
		$slug    = sanitize_title( (string) $request->get_param( 'preset_slug' ) );
		$payload = (array) $request->get_json_params();
		if ( ! isset( $payload['source_url'] ) ) {
			$payload['source_url'] = (string) $request->get_param( 'source_url' );
		}
		$result = $this->project->open_schedule( $slug, $payload );
		return $this->respond( $result );
	}

	public function project_slots( WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$schedule = $this->project->get( $id );
		$auth_err = $this->guard_token( $request, $schedule );
		if ( $auth_err ) {
			return $auth_err;
		}
		$start  = sanitize_text_field( (string) $request->get_param( 'start' ) );
		$end    = sanitize_text_field( (string) $request->get_param( 'end' ) );
		$result = $this->project->fetch_slots( (string) $schedule['preset_slug'], $start, $end );
		return $this->respond( $result );
	}

	public function project_select( WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$schedule = $this->project->get( $id );
		$auth_err = $this->guard_token( $request, $schedule );
		if ( $auth_err ) {
			return $auth_err;
		}
		$body     = (array) $request->get_json_params();
		$selected = isset( $body['selected_slots'] ) && is_array( $body['selected_slots'] )
			? $body['selected_slots']
			: array();
		$result   = $this->project->save_selection( $id, $selected );
		return $this->respond( $result );
	}

	public function project_confirm( WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$schedule = $this->project->get( $id );
		$auth_err = $this->guard_token( $request, $schedule );
		if ( $auth_err ) {
			return $auth_err;
		}
		$result = $this->project->confirm_schedule( $id );
		return $this->respond( $result );
	}

	// ---------- helpers --------------------------------------------------

	/**
	 * @param WP_REST_Request           $request   Request.
	 * @param array<string, mixed>|null $schedule  Loaded schedule row.
	 * @return WP_Error|null  WP_Error if the request is unauthorized.
	 */
	protected function guard_token( WP_REST_Request $request, $schedule ) {
		if ( ! $schedule ) {
			return new WP_Error(
				'handik_schedule_not_found',
				__( 'Schedule not found.', 'handik-booking-app' ),
				array( 'status' => 404 )
			);
		}
		$public_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( '' === $public_token || ! hash_equals( (string) $schedule['public_token'], $public_token ) ) {
			return new WP_Error(
				'handik_schedule_forbidden',
				__( 'Invalid schedule token.', 'handik-booking-app' ),
				array( 'status' => 403 )
			);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $result Service result.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( array $result ) {
		if ( ! empty( $result['error'] ) ) {
			$status  = absint( $result['status'] ?? 400 );
			$payload = $result;
			unset( $payload['error'], $payload['status'] );
			$data    = array_merge( array( 'status' => $status > 0 ? $status : 400 ), $payload );
			return new WP_Error( 'handik_form_error', (string) $result['error'], $data );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * @param array<string, mixed> $preset Preset.
	 * @return array<string, mixed>
	 */
	protected function public_preset( array $preset ) {
		// Don't leak admin internals (cal_event_type_id, internal_notes).
		return array(
			'preset_slug'                => (string) $preset['preset_slug'],
			'form_title'                 => (string) $preset['form_title'],
			'form_type'                  => (string) $preset['form_type'],
			'booking_type'               => (string) $preset['booking_type'],
			'duration_minutes'           => (int) $preset['duration_minutes'],
			'required_days'              => (int) $preset['required_days'],
			'work_day_duration_minutes'  => (int) $preset['work_day_duration_minutes'],
		);
	}
}
