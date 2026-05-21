<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST endpoints for the Additional Booking Forms module.
 *
 * Namespace: handik-booking-app/v1
 *
 *  - GET  /forms/preset/{slug}        public preset metadata          [rate-limited]
 *  - POST /forms/direct/submit        create direct request           [nonce + rate-limit]
 *  - POST /forms/direct/{id}/capture  record Cal booking from iframe  [nonce + capture_token]
 *  - POST /forms/project/open         step 1+2 save contact/address   [nonce + rate-limit]
 *  - GET  /forms/project/{id}/slots   fetch Cal.com slots             [public_token + rate-limit]
 *  - POST /forms/project/{id}/select  save N selected slots           [nonce + public_token]
 *  - POST /forms/project/{id}/confirm re-check + create N bookings    [nonce + public_token]
 *
 * Security model
 * --------------
 * - All POST endpoints require a valid `X-WP-Nonce` (the standard `wp_rest`
 *   nonce that the SPA already sends). This means an off-site script cannot
 *   write into the CRM without first scraping the form page.
 * - All endpoints (incl. GET) carry a sliding-window rate limit per IP
 *   (`Cf-Connecting-Ip` → `X-Forwarded-For` → `REMOTE_ADDR`). Defaults are
 *   tuned to comfortably support a real customer journey while killing
 *   bulk abuse: 30 submits/min, 60 reads/min.
 * - Tokenized endpoints (project schedule, direct capture) compare the
 *   client-presented token against the row's stored value with
 *   `hash_equals`. Tokens are issued server-side from
 *   `wp_generate_password( 32 )` so they're not guessable.
 * - The capture endpoint additionally requires a per-request capture_token
 *   handed back by the submit handler — that closes the previous IDOR where
 *   anyone could iterate `direct_booking_requests.id` and overwrite the
 *   booking status.
 */
class Handik_Booking_App_Forms_Rest_Api {
	const NAMESPACE_V1 = 'handik-booking-app/v1';

	const RATE_LIMIT_SUBMIT_PER_MIN = 30;
	const RATE_LIMIT_READ_PER_MIN   = 60;

	/** @var Handik_Booking_App_Booking_Presets_Service */
	protected $presets;
	/** @var Handik_Booking_App_Direct_Booking_Service */
	protected $direct;
	/** @var Handik_Booking_App_Project_Schedule_Service */
	protected $project;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;
	/** @var Handik_Booking_App_Form_Approvals_Service|null */
	protected $approvals;
	/** @var Handik_Booking_App_Auth_Service|null */
	protected $auth;

	public function __construct( $presets, $direct, $project, $logger = null, $approvals = null, $auth = null ) {
		$this->presets   = $presets;
		$this->direct    = $direct;
		$this->project   = $project;
		$this->logger    = $logger;
		$this->approvals = $approvals;
		$this->auth      = $auth;

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
			'/forms/preset/(?P<slug>[a-z0-9-]+)/check-approval',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_preset_approval' ),
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
		$err = $this->guard_rate_limit( 'preset', self::RATE_LIMIT_READ_PER_MIN );
		if ( $err ) {
			return $err;
		}
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

	/**
	 * 2.1.30.0 — soft phone-pre-approval gate for direct-link bookings.
	 *
	 * The Forms SPA calls this after a successful OTP verification (or
	 * after a `phone-verify/restore` rehydrated a 30-day session). We
	 * re-validate the supplied verified_token via the auth service so a
	 * malicious caller can't query approvals for a phone they don't
	 * own, then return the count of active approvals for the
	 * (preset_slug, verified_phone) pair. If it's 0, the SPA shows the
	 * "this wasn't pre-approved" warning screen before continuing to
	 * details. If it's > 0, the booking proceeds silently and one
	 * approval is consumed on successful Cal capture.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_preset_approval( WP_REST_Request $request ) {
		$err = $this->guard_submit( $request, 'check_approval' );
		if ( $err ) {
			return $err;
		}
		$slug = sanitize_title( (string) $request['slug'] );
		if ( ! $this->approvals || ! $this->auth ) {
			// Gate disabled (older boot path) — never warn.
			return rest_ensure_response( array( 'approved' => true, 'active_count' => 0, 'gate_enabled' => false ) );
		}
		$token = (string) $request->get_param( 'verified_token' );
		if ( '' === $token ) {
			return new WP_Error(
				'handik_form_missing_token',
				__( 'Phone verification is required.', 'handik-booking-app' ),
				array( 'status' => 400 )
			);
		}
		$restored = $this->auth->restore_verified_client( $token );
		if ( empty( $restored['verified_phone'] ) ) {
			return new WP_Error(
				'handik_form_invalid_token',
				isset( $restored['error'] ) ? (string) $restored['error'] : __( 'Phone verification is required.', 'handik-booking-app' ),
				array( 'status' => isset( $restored['status'] ) ? (int) $restored['status'] : 401 )
			);
		}
		$phone = (string) $restored['verified_phone'];
		$count = $this->approvals->count_active_for_phone( $slug, $phone );
		return rest_ensure_response(
			array(
				'approved'     => $count > 0,
				'active_count' => $count,
				'gate_enabled' => true,
			)
		);
	}

	public function submit_direct( WP_REST_Request $request ) {
		$err = $this->guard_submit( $request, 'direct_submit' );
		if ( $err ) {
			return $err;
		}
		$slug    = sanitize_title( (string) $request->get_param( 'preset_slug' ) );
		$payload = (array) $request->get_json_params();
		if ( ! isset( $payload['source_url'] ) ) {
			$payload['source_url'] = (string) $request->get_param( 'source_url' );
		}
		$result = $this->direct->submit( $slug, $payload );
		return $this->respond( $result );
	}

	public function capture_direct( WP_REST_Request $request ) {
		$err = $this->guard_submit( $request, 'direct_capture' );
		if ( $err ) {
			return $err;
		}
		$id      = absint( $request['id'] );
		$payload = (array) $request->get_json_params();
		$booking = isset( $payload['booking_payload'] ) && is_array( $payload['booking_payload'] )
			? $payload['booking_payload']
			: $payload;
		$capture_token = isset( $payload['capture_token'] )
			? sanitize_text_field( (string) $payload['capture_token'] )
			: sanitize_text_field( (string) $request->get_param( 'capture_token' ) );
		$result = $this->direct->capture_booking( $id, $booking, $capture_token );
		return $this->respond( $result );
	}

	public function open_project( WP_REST_Request $request ) {
		$err = $this->guard_submit( $request, 'project_open' );
		if ( $err ) {
			return $err;
		}
		$slug    = sanitize_title( (string) $request->get_param( 'preset_slug' ) );
		$payload = (array) $request->get_json_params();
		if ( ! isset( $payload['source_url'] ) ) {
			$payload['source_url'] = (string) $request->get_param( 'source_url' );
		}
		$result = $this->project->open_schedule( $slug, $payload );
		return $this->respond( $result );
	}

	public function project_slots( WP_REST_Request $request ) {
		$err = $this->guard_rate_limit( 'project_slots', self::RATE_LIMIT_READ_PER_MIN );
		if ( $err ) {
			return $err;
		}
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
		$err = $this->guard_submit( $request, 'project_select' );
		if ( $err ) {
			return $err;
		}
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
		$err = $this->guard_submit( $request, 'project_confirm' );
		if ( $err ) {
			return $err;
		}
		$id       = absint( $request['id'] );
		$schedule = $this->project->get( $id );
		$auth_err = $this->guard_token( $request, $schedule );
		if ( $auth_err ) {
			return $auth_err;
		}
		$result = $this->project->confirm_schedule( $id );
		return $this->respond( $result );
	}

	// ---------- guards ---------------------------------------------------

	/**
	 * Combined nonce + rate-limit guard for write endpoints.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $bucket  Per-endpoint rate-limit bucket name.
	 * @return WP_Error|null  WP_Error to short-circuit, null to proceed.
	 */
	protected function guard_submit( WP_REST_Request $request, $bucket ) {
		if ( ! $this->verify_nonce( $request ) ) {
			return new WP_Error(
				'handik_form_invalid_nonce',
				__( 'This form session has expired. Please reload the page and try again.', 'handik-booking-app' ),
				array( 'status' => 403 )
			);
		}
		return $this->guard_rate_limit( $bucket, self::RATE_LIMIT_SUBMIT_PER_MIN );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function verify_nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'x_wp_nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( '' === $nonce ) {
			return false;
		}
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Sliding-window rate limit. Keyed by IP + bucket name so each endpoint
	 * gets its own counter and abuse on one path doesn't lock the customer
	 * out of an unrelated one.
	 *
	 * @param string $bucket    Bucket name.
	 * @param int    $per_minute Allowed requests per minute.
	 * @return WP_Error|null
	 */
	protected function guard_rate_limit( $bucket, $per_minute ) {
		$ip      = Handik_Booking_App_Forms_Helpers::client_ip();
		$key     = 'handik_form_rl_' . md5( $bucket . '|' . $ip );
		$count   = (int) get_transient( $key );
		$limit   = (int) apply_filters( 'handik_booking_app_form_rate_limit', $per_minute, $bucket );
		if ( $count >= $limit ) {
			if ( $this->logger ) {
				$this->logger->warning(
					'Additional Forms rate limit hit.',
					array(
						'bucket' => $bucket,
						'limit'  => $limit,
					)
				);
			}
			return new WP_Error(
				'handik_form_rate_limited',
				__( 'Too many requests. Please wait a minute before trying again.', 'handik-booking-app' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return null;
	}

	// `client_ip` moved to Forms_Helpers — shared with the services so the
	// IP detection chain is identical everywhere.

	/**
	 * @param WP_REST_Request           $request   Request.
	 * @param array<string, mixed>|null $schedule  Loaded schedule row.
	 * @return WP_Error|null
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
