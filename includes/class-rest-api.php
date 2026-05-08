<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_REST_API {
	/** @var Handik_Booking_App_Controller */
	protected $app;
	/** @var Handik_Booking_App_Auth_Service */
	protected $auth;
	/** @var Handik_Booking_App_ChatKit_Service */
	protected $chatkit;
	/** @var Handik_Booking_App_Webhook_Service */
	protected $webhook;
	/** @var Handik_Booking_App_Messages_Service|null */
	protected $messages;
	/** @var Handik_Booking_App_Bookings_Service|null */
	protected $bookings;
	/** @var Handik_Booking_App_Contacts_Service|null */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service|null */
	protected $addresses;
	/** @var Handik_Booking_App_Settings|null */
	protected $settings;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;
	/** @var Handik_Booking_App_Job_Requests_Service|null */
	protected $job_requests;
	/** @var Handik_Booking_App_Service_Catalog_Service|null */
	protected $service_catalog;
	/** @var Handik_Booking_App_Cascade_Delete_Service|null */
	protected $cascade_delete;

	public function __construct( $app, $auth, $chatkit, $webhook, $messages = null, $bookings = null, $contacts = null, $addresses = null, $settings = null, $logger = null, $job_requests = null, $service_catalog = null, $cascade_delete = null ) {
		$this->app             = $app;
		$this->auth            = $auth;
		$this->chatkit         = $chatkit;
		$this->webhook         = $webhook;
		$this->messages        = $messages;
		$this->bookings        = $bookings;
		$this->contacts        = $contacts;
		$this->addresses       = $addresses;
		$this->settings        = $settings;
		$this->logger          = $logger;
		$this->job_requests    = $job_requests;
		$this->service_catalog = $service_catalog;
		$this->cascade_delete  = $cascade_delete;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$namespace = 'handik-booking-app/v1';

		$this->route( $namespace, '/app/bootstrap', array( $this, 'bootstrap' ), WP_REST_Server::READABLE );
		$this->route( $namespace, '/app/draft', array( $this, 'save_draft' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/app/upload', array( $this, 'upload' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/auth/request-code', array( $this, 'request_code' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/auth/verify', array( $this, 'verify_code' ), WP_REST_Server::CREATABLE );
		// Sprint 5 — phone-first OTP routes used by both the main SPA and the
		// Additional Forms. The legacy /auth/* routes stay for back-compat
		// with the email magic-link flow.
		$this->route( $namespace, '/phone-verify/start', array( $this, 'phone_verify_start' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/phone-verify/check', array( $this, 'phone_verify_check' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/phone-verify/restore', array( $this, 'phone_verify_restore' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/phone-verify/bind-contact', array( $this, 'phone_verify_bind_contact' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/contacts/lookup', array( $this, 'contact_lookup' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/chatkit-session', array( $this, 'chatkit_session' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/photo-analysis', array( $this, 'photo_analysis' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/request-photo-context', array( $this, 'request_photo_context' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/request-pricing-context', array( $this, 'request_pricing_context' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/assistant-result', array( $this, 'assistant_result' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/chatkit-thread', array( $this, 'chatkit_thread' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/client-log', array( $this, 'client_log' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/booking-url', array( $this, 'booking_url' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/booking-status', array( $this, 'booking_status' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/booking-capture', array( $this, 'booking_capture' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/booking-complete', array( $this, 'booking_complete' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/cal-webhook', array( $this, 'cal_webhook' ), WP_REST_Server::CREATABLE );

		// Public chat-mirror endpoint (B3): the bridge calls this from the
		// customer's browser to persist the conversation. Auth is the same
		// draft_token + request_id pair the rest of the public API uses.
		$this->route( $namespace, '/messages/record', array( $this, 'messages_record' ), WP_REST_Server::CREATABLE );

		// Admin endpoints — gated behind manage_options.
		register_rest_route( $namespace, '/admin/booking/(?P<id>\d+)/notes', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'admin_booking_notes' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/booking/(?P<id>\d+)/status', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'admin_booking_status' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/contact', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_contact_create' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/contact/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'admin_contact_update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		// Sprint 12 — destructive cascades, all gated on the new
		// MANAGE_DELETE cap (see admin_delete_permission()).
		register_rest_route( $namespace, '/admin/contact/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'admin_contact_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		register_rest_route( $namespace, '/admin/job-request/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'admin_job_request_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		register_rest_route( $namespace, '/admin/booking/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'admin_booking_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		register_rest_route( $namespace, '/admin/address/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'admin_address_update' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/address/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'admin_address_delete' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/address/(?P<id>\d+)/primary', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'admin_address_primary' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/catalog', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'admin_catalog_save' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/transients/clear', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_transients_clear' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/migrations/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_migrations_run' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/export/(?P<table>[a-z_]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'admin_export_table_csv' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
	}

	/**
	 * Admin permission gate: manage_options + wp_rest nonce.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_permission() {
		// Sprint 8: all admin REST routes are booking-side ops (notes,
		// status, contacts, addresses, catalog, transients, migrations,
		// CSV exports). They require MANAGE_BOOKINGS only — `manage_options`
		// users still pass thanks to the user_has_cap filter.
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_BOOKINGS ) ) {
			return new WP_Error( 'handik_admin_forbidden', __( 'You do not have permission to do that.', 'handik-booking-app' ), array( 'status' => 403 ) );
		}
		return true;
	}

	protected function route( $namespace, $route, $callback, $methods ) {
		register_rest_route(
			$namespace,
			$route,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => '__return_true',
			)
		);
	}

	public function bootstrap() {
		return rest_ensure_response( $this->app->bootstrap() );
	}

	public function save_draft( WP_REST_Request $request ) {
		return $this->respond( $this->app->save_draft( (array) $request->get_json_params() ) );
	}

	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'missing_file', __( 'No file was uploaded.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		return $this->respond(
			$this->app->upload_photo(
				$files['file'],
				array(
					'request_id'      => absint( $request->get_param( 'request_id' ) ),
					'draft_token'     => sanitize_text_field( (string) $request->get_param( 'draft_token' ) ),
					'contact_id'      => absint( $request->get_param( 'contact_id' ) ),
					'app_session_key' => sanitize_text_field( (string) $request->get_param( 'app_session_key' ) ),
				)
			)
		);
	}

	public function request_code( WP_REST_Request $request ) {
		return $this->respond( $this->auth->request_code( (string) $request->get_param( 'email' ), (string) $request->get_param( 'phone' ), (string) $request->get_param( 'redirect' ) ) );
	}

	public function verify_code( WP_REST_Request $request ) {
		return $this->respond( $this->auth->verify( (string) $request->get_param( 'email' ), (string) $request->get_param( 'phone' ), (string) $request->get_param( 'code' ), (string) $request->get_param( 'token' ) ) );
	}

	public function phone_verify_start( WP_REST_Request $request ) {
		return $this->respond( $this->auth->start_phone_otp( (string) $request->get_param( 'phone' ) ) );
	}

	public function phone_verify_check( WP_REST_Request $request ) {
		return $this->respond(
			$this->auth->check_phone_otp(
				(string) $request->get_param( 'phone' ),
				(string) $request->get_param( 'code' )
			)
		);
	}

	public function phone_verify_restore( WP_REST_Request $request ) {
		return $this->respond( $this->auth->restore_verified_client( (string) $request->get_param( 'verified_token' ) ) );
	}

	public function phone_verify_bind_contact( WP_REST_Request $request ) {
		return $this->respond(
			$this->auth->bind_verified_token_to_contact(
				(string) $request->get_param( 'verified_token' ),
				(int) $request->get_param( 'contact_id' )
			)
		);
	}

	public function contact_lookup( WP_REST_Request $request ) {
		return $this->respond( $this->app->contact_lookup( sanitize_text_field( (string) $request->get_param( 'phone' ) ) ) );
	}

	public function chatkit_session( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->create_session( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function photo_analysis( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->warm_photo_analysis( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function request_photo_context( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->request_photo_context( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function request_pricing_context( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->request_pricing_context( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function assistant_result( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->save_assistant_result( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ), (array) $request->get_param( 'assistant_result' ) ) );
	}

	public function chatkit_thread( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->associate_thread( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ), (string) $request->get_param( 'thread_id' ) ) );
	}

	public function client_log( WP_REST_Request $request ) {
		if ( ! $this->client_log_rate_limit_ok( $request ) ) {
			return new WP_Error(
				'handik_booking_app_rate_limited',
				__( 'Too many log entries from this client. Try again shortly.', 'handik-booking-app' ),
				array( 'status' => 429 )
			);
		}

		$plugin  = handik_booking_app();
		$level   = sanitize_key( (string) $request->get_param( 'level' ) );
		$message = sanitize_text_field( (string) $request->get_param( 'message' ) );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
		$context = (array) $request->get_param( 'context' );

		if ( ! $message ) {
			return $this->respond( array( 'error' => __( 'Log message is required.', 'handik-booking-app' ), 'status' => 400 ) );
		}

		// Cap context payload to keep wp_options small under load.
		if ( count( $context ) > 32 ) {
			$context = array_slice( $context, 0, 32, true );
		}

		switch ( $level ) {
			case 'error':
				$plugin->logger->error( $message, $context );
				break;
			case 'debug':
				$plugin->logger->debug( $message, $context );
				break;
			default:
				$plugin->logger->info( $message, $context );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Sliding-window rate limit for /client-log: at most 60 entries per IP per minute.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function client_log_rate_limit_ok( WP_REST_Request $request ) {
		$ip = '';
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$candidate = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				$candidate = trim( explode( ',', $candidate )[0] );
				if ( $candidate ) {
					$ip = $candidate;
					break;
				}
			}
		}

		$bucket  = 'handik_clog_' . md5( $ip ?: 'unknown' );
		$count   = (int) get_transient( $bucket );
		$limit   = (int) apply_filters( 'handik_booking_app_client_log_rate_limit', 60 );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $bucket, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public function booking_url( WP_REST_Request $request ) {
		return $this->respond( $this->app->booking_url( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function booking_status( WP_REST_Request $request ) {
		return $this->respond( $this->app->booking_status( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function booking_capture( WP_REST_Request $request ) {
		return $this->respond( $this->app->capture_booking( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ), (array) $request->get_param( 'booking_payload' ) ) );
	}

	public function booking_complete( WP_REST_Request $request ) {
		return $this->respond( $this->app->complete_booking_step( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function cal_webhook( WP_REST_Request $request ) {
		return $this->respond( $this->webhook->handle_cal_webhook( $request ) );
	}

	/**
	 * Public endpoint: the chat bridge mirrors user/assistant messages here so
	 * the admin "What the customer wrote" panel can show a real transcript.
	 * Auth is the customer's draft_token (same gate as the rest of the
	 * public API).
	 */
	public function messages_record( WP_REST_Request $request ) {
		$request_id  = absint( $request->get_param( 'request_id' ) );
		$draft_token = sanitize_text_field( (string) $request->get_param( 'draft_token' ) );
		$role        = sanitize_key( (string) $request->get_param( 'role' ) );
		$content     = (string) $request->get_param( 'content' );
		$thread_id   = sanitize_text_field( (string) $request->get_param( 'thread_id' ) );
		$metadata    = (array) $request->get_param( 'metadata' );

		if ( ! $request_id || ! $draft_token || ! $this->job_requests || ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return new WP_Error( 'handik_messages_forbidden', __( 'Invalid request token.', 'handik-booking-app' ), array( 'status' => 403 ) );
		}
		if ( ! $this->messages ) {
			return new WP_Error( 'handik_messages_unavailable', __( 'Message store unavailable.', 'handik-booking-app' ), array( 'status' => 503 ) );
		}
		$id = $this->messages->record( $request_id, $thread_id, $role, $content, $metadata );
		return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
	}

	// --- Admin handlers ---------------------------------------------------

	public function admin_booking_notes( WP_REST_Request $request ) {
		if ( ! $this->bookings ) {
			return $this->admin_unavailable();
		}
		$id    = absint( $request['id'] );
		$notes = (string) $request->get_param( 'admin_notes' );
		$ok    = $this->bookings->update_admin_fields( $id, array( 'admin_notes' => $notes ) );
		return rest_ensure_response( array( 'success' => $ok ) );
	}

	public function admin_booking_status( WP_REST_Request $request ) {
		if ( ! $this->bookings ) {
			return $this->admin_unavailable();
		}
		$id      = absint( $request['id'] );
		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$allowed = array( 'cancelled', 'completed', 'rescheduled', 'no_show', '' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'handik_invalid_status', __( 'Status not allowed.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		$ok = $this->bookings->update_admin_fields( $id, array( 'admin_status_override' => '' === $status ? null : $status ) );
		return rest_ensure_response( array( 'success' => $ok, 'status' => $status ) );
	}

	public function admin_contact_create( WP_REST_Request $request ) {
		if ( ! $this->contacts ) {
			return $this->admin_unavailable();
		}
		$payload = array(
			'full_name' => (string) $request->get_param( 'full_name' ),
			'phone'     => (string) $request->get_param( 'phone' ),
			'email'     => (string) $request->get_param( 'email' ),
			'notes'     => (string) $request->get_param( 'notes' ),
		);
		$contact_id = $this->contacts->admin_create( $payload );
		if ( ! $contact_id ) {
			return new WP_Error( 'handik_contact_invalid', __( 'Need at least a name plus phone or email.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}

		$address_full = trim( (string) $request->get_param( 'address_full' ) );
		if ( '' !== $address_full && $this->addresses ) {
			$this->addresses->sync(
				$contact_id,
				array(
					'address_full'   => $address_full,
					'address_line_1' => (string) $request->get_param( 'address_line_1' ),
					'address_unit'   => (string) $request->get_param( 'address_unit' ),
					'city'           => (string) $request->get_param( 'city' ),
					'state'          => (string) $request->get_param( 'state' ),
					'zip_code'       => (string) $request->get_param( 'zip_code' ),
					'is_default'     => true,
				)
			);
		}

		return rest_ensure_response( array( 'success' => true, 'contact_id' => $contact_id ) );
	}

	public function admin_contact_update( WP_REST_Request $request ) {
		if ( ! $this->contacts ) {
			return $this->admin_unavailable();
		}
		$id    = absint( $request['id'] );
		$patch = array_intersect_key(
			$request->get_params(),
			array_flip( array( 'full_name', 'email', 'phone', 'notes', 'is_returning', 'is_spam' ) )
		);
		$ok = $this->contacts->admin_update( $id, $patch );
		return rest_ensure_response( array( 'success' => $ok ) );
	}

	public function admin_address_update( WP_REST_Request $request ) {
		if ( ! $this->addresses ) {
			return $this->admin_unavailable();
		}
		$id    = absint( $request['id'] );
		$patch = array_intersect_key(
			$request->get_params(),
			array_flip( array( 'address_full', 'address_unit', 'city', 'state', 'zip_code', 'label' ) )
		);
		$ok = $this->addresses->admin_update( $id, $patch );
		return rest_ensure_response( array( 'success' => $ok ) );
	}

	public function admin_address_delete( WP_REST_Request $request ) {
		if ( ! $this->addresses ) {
			return $this->admin_unavailable();
		}
		$id = absint( $request['id'] );
		$ok = $this->addresses->soft_delete( $id );
		return rest_ensure_response( array( 'success' => $ok ) );
	}

	/**
	 * Sprint 12 — permission gate for destructive cascades. Requires
	 * the new MANAGE_DELETE cap, which is held by `manage_options`
	 * users automatically (via the user_has_cap filter in
	 * Handik_Booking_App_Capabilities) and otherwise must be granted
	 * explicitly to a role.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_delete_permission() {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_DELETE ) ) {
			return new WP_Error(
				'handik_admin_forbidden',
				__( 'Hard-delete requires the handik_delete_data capability.', 'handik-booking-app' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public function admin_contact_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$id  = absint( $request['id'] );
		$res = $this->cascade_delete->delete_contact( $id );
		if ( empty( $res['deleted'] ) ) {
			return new WP_Error( 'handik_delete_failed', __( 'Could not delete that contact.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'success' => true,
			'summary' => $res['summary'] ?? array(),
		) );
	}

	public function admin_job_request_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$id  = absint( $request['id'] );
		$res = $this->cascade_delete->delete_request( $id );
		if ( empty( $res['deleted'] ) ) {
			return new WP_Error( 'handik_delete_failed', __( 'Could not delete that request.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'success' => true,
			'summary' => $res['summary'] ?? array(),
		) );
	}

	public function admin_booking_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$id  = absint( $request['id'] );
		$res = $this->cascade_delete->delete_booking( $id );
		if ( empty( $res['deleted'] ) ) {
			return new WP_Error( 'handik_delete_failed', __( 'Could not delete that booking.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'success' => true,
			'summary' => $res['summary'] ?? array(),
		) );
	}

	public function admin_address_primary( WP_REST_Request $request ) {
		if ( ! $this->addresses ) {
			return $this->admin_unavailable();
		}
		$id = absint( $request['id'] );
		$ok = $this->addresses->set_primary( $id );
		return rest_ensure_response( array( 'success' => $ok ) );
	}

	public function admin_catalog_save( WP_REST_Request $request ) {
		if ( ! $this->settings ) {
			return $this->admin_unavailable();
		}
		$groups = (array) $request->get_param( 'groups' );
		$this->settings->update( array( 'service_catalog_groups' => $groups ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function admin_transients_clear( WP_REST_Request $request ) {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_handik\\_%' OR option_name LIKE '\\_transient\\_timeout\\_handik\\_%'" );
		if ( $this->logger ) {
			$this->logger->info( 'Admin cleared plugin transients.', array( 'admin_id' => get_current_user_id() ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function admin_migrations_run( WP_REST_Request $request ) {
		$migrations = new Handik_Booking_App_Migrations();
		$result     = $migrations->migrate();
		// Sprint 7: surface the actual outcome instead of always claiming
		// success. Previously the admin button returned `success=true`
		// even when nothing happened (or when a step had silently failed
		// in the legacy code path).
		// `migrate()` is guaranteed to return the array shape; the
		// previous defensive is_array() wrappers tripped PHPStan.
		$ran     = isset( $result['ran'] ) && is_array( $result['ran'] ) ? array_values( $result['ran'] ) : array();
		$skipped = ! empty( $result['skipped'] );
		$error   = $result['error'] ?? null;
		if ( $this->logger ) {
			$this->logger->info(
				'Admin re-ran migrations.',
				array(
					'admin_id' => get_current_user_id(),
					'ran'      => $ran,
					'skipped'  => $skipped,
					'error'    => $error,
				)
			);
		}
		return rest_ensure_response( array(
			'success'    => null === $error,
			'db_version' => (string) get_option( Handik_Booking_App_Migrations::OPTION_NAME, '0.0.0' ),
			'ran'        => $ran,
			'skipped'    => $skipped,
			'error'      => $error,
			'no_changes' => ! $error && ! $skipped && empty( $ran ),
		) );
	}

	public function admin_export_table_csv( WP_REST_Request $request ) {
		global $wpdb;
		// Defence-in-depth — admin_permission already gated by capability,
		// but for a public-link-style URL we also accept a wp_rest nonce in
		// the query, mirroring how the logs CSV export verifies its nonce.
		$nonce = $request->get_param( '_wpnonce' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'handik_bad_nonce', __( 'Stale link — reopen the System info page and try again.', 'handik-booking-app' ), array( 'status' => 403 ) );
		}
		$table_short = sanitize_key( $request['table'] );
		$allowed = array(
			'job_requests',
			'bookings',
			'contacts',
			'addresses',
			'messages',
			// Additional Forms tables (added in 2.1.9.1 / 2.1.10.0).
			'form_presets',
			'direct_booking_requests',
			'project_scheduling_requests',
			'project_work_days',
		);
		if ( ! in_array( $table_short, $allowed, true ) ) {
			return new WP_Error( 'handik_invalid_table', __( 'Table not allowed.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		$table = Handik_Booking_App_DB::table( $table_short );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return new WP_Error( 'handik_no_table', __( 'Table missing — run migrations first.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="handik-' . $table_short . '-' . gmdate( 'Y-m-d-His' ) . '.csv"' );

		// Sprint 7 (admin perf): stream the export in keyset-paginated batches
		// instead of loading the whole table into PHP memory. The old path
		// was `SELECT *` with no LIMIT — fine for a 1k-row contacts table,
		// fatal on a 100k-row messages table (OOM at PHP's memory_limit).
		// We page on the primary key (`id`) so OFFSET cost stays O(1) and
		// we drop output to the client as we go via flush().
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		while ( ob_get_level() > 0 ) { @ob_end_flush(); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@ob_implicit_flush( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$out         = fopen( 'php://output', 'w' );
		$batch_size  = 1000;
		$last_id     = 0;
		$header_done = false;
		$any_row     = false;
		while ( true ) {
			$batch = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, $batch_size ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( empty( $batch ) ) {
				break;
			}
			$any_row = true;
			foreach ( $batch as $row ) {
				if ( ! $header_done ) {
					fputcsv( $out, array_keys( $row ) );
					$header_done = true;
				}
				fputcsv( $out, array_map( static function( $v ) {
					return is_null( $v ) ? '' : (string) $v;
				}, $row ) );
				$last_id = (int) $row['id'];
			}
			// Free the batch's memory before the next round so peak usage
			// stays bounded by `$batch_size` rows, not the whole table.
			unset( $batch );
			if ( function_exists( 'flush' ) ) { flush(); }
		}
		if ( ! $any_row ) {
			fputcsv( $out, array( 'empty' ) );
		}
		fclose( $out );
		exit;
	}

	protected function admin_unavailable() {
		return new WP_Error( 'handik_admin_unavailable', __( 'Admin services are not wired.', 'handik-booking-app' ), array( 'status' => 503 ) );
	}

	/**
	 * @param array<string, mixed> $result Result.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( array $result ) {
		return Handik_Booking_App_Api_Response::from_array( $result );
	}
}
