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
	/** @var Handik_Booking_App_Direct_Booking_Service|null */
	protected $direct_booking;
	/** @var Handik_Booking_App_Booking_Presets_Service|null */
	protected $booking_presets;
	/** @var Handik_Booking_App_Cal_Api_Service|null */
	protected $cal_api;

	public function __construct( $app, $auth, $chatkit, $webhook, $messages = null, $bookings = null, $contacts = null, $addresses = null, $settings = null, $logger = null, $job_requests = null, $service_catalog = null, $cascade_delete = null, $direct_booking = null, $booking_presets = null, $cal_api = null ) {
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
		$this->direct_booking  = $direct_booking;
		$this->booking_presets = $booking_presets;
		$this->cal_api         = $cal_api;

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
		// 2.1.23.1 — A5: backfill the local handik_messages table from
		// OpenAI ChatKit's authoritative thread storage. Triggered by
		// the "Load chat from OpenAI" button in the admin booking
		// detail "What the customer wrote" panel when the JS bridge
		// missed events (ChatKit web component event shape drift).
		register_rest_route( $namespace, '/admin/booking/(?P<id>\d+)/fetch-chat', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_booking_fetch_chat' ),
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
		// 2.1.26.0 — A4: bulk-delete drafts in People & Requests
		// (the "Abandoned drafts (24h+)" focus list). Each id goes
		// through Cascade_Delete_Service::delete_request so the
		// drop is identical to the single-row delete path (messages
		// → bookings → photos → request row → contact_id stays
		// orphan-safe via existing cleanup).
		// 2.1.26.2 — A6 follow-up: pull from Cal.com on demand. Lists
		// Cal bookings via the v2 API and upserts any that aren't
		// already in handik_bookings. Use cases: backfilling bookings
		// that pre-date the plugin install OR bookings made directly
		// on Cal.com that the webhook failed to deliver (network
		// drop, secret rotated, etc).
		register_rest_route( $namespace, '/admin/bookings/pull-from-cal', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_pull_from_cal' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		register_rest_route( $namespace, '/admin/job-requests/bulk-delete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_job_requests_bulk_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		// 2.1.26.3 — bulk-delete bookings (cleanup for owner after
		// "Pull from Cal.com" backfills test bookings + cancellations
		// they want to clear out of the local list without unbooking
		// on Cal.com).
		register_rest_route( $namespace, '/admin/bookings/bulk-delete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_bookings_bulk_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		// 2.1.26.3 — bulk-delete contacts (cascade: contact + all
		// requests + bookings + addresses). Gated on MANAGE_DELETE
		// like the single-row delete. UI will require typed-confirm
		// before posting.
		register_rest_route( $namespace, '/admin/contacts/bulk-delete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_contacts_bulk_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		register_rest_route( $namespace, '/admin/booking/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'admin_booking_delete' ),
			'permission_callback' => array( $this, 'admin_delete_permission' ),
		) );
		// Sprint 13 — admin-initiated direct booking. Accepts the same
		// shape the public form sends but supports `contact_id` /
		// `address_id` shortcuts so the admin can book on behalf of an
		// existing customer without re-typing.
		register_rest_route( $namespace, '/admin/booking/new', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_booking_new' ),
			'permission_callback' => array( $this, 'admin_permission' ),
		) );
		// Contact autocomplete for the Add-Booking form: search by name
		// fragment / phone digits / email prefix. Returns up to 10 hits.
		register_rest_route( $namespace, '/admin/contact/search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'admin_contact_search' ),
			'permission_callback' => array( $this, 'admin_permission' ),
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
		$reason  = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$allowed = array( 'cancelled', 'completed', 'rescheduled', 'no_show', '' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'handik_invalid_status', __( 'Status not allowed.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		// 2.1.27.0 — when transitioning to cancelled, propagate the
		// cancellation to Cal.com so the customer's email + calendar
		// invite (Apple / Google) get the cancel notification. Local
		// mark proceeds regardless of Cal outcome — the operator
		// shouldn't be blocked by a Cal API outage.
		$cal_result = array( 'skipped' => true, 'reason' => 'not_cancel' );
		if ( 'cancelled' === $status ) {
			$booking = $this->bookings->get( $id );
			if ( $booking ) {
				$cal_result = $this->cancel_on_cal_for_booking( $booking, $reason );
			}
		}
		$ok = $this->bookings->update_admin_fields( $id, array( 'admin_status_override' => '' === $status ? null : $status ) );
		return rest_ensure_response( array(
			'success'       => $ok,
			'status'        => $status,
			'cal_cancelled' => ! empty( $cal_result['success'] ),
			'cal_skipped'   => ! empty( $cal_result['skipped'] ),
		) );
	}

	/**
	 * 2.1.27.0 — extract the Cal.com booking UID for a local booking
	 * row. Needed for the Cal `/bookings/{uid}/cancel` API. Sources,
	 * in priority order:
	 *
	 *   1. project_work_days.cal_booking_uid (most authoritative for
	 *      project rows — the project flow always stores the uid here)
	 *   2. direct_booking_requests.cal_booking_uid (same for direct
	 *      bookings)
	 *   3. raw_webhook_json parsed for `uid` / `bookingUid` (covers
	 *      main-SPA Cal flow + external bookings pulled from Cal)
	 *   4. handik_bookings.cal_booking_id — but ONLY if it looks like
	 *      a UID (non-numeric); for older rows where extract_booking_id
	 *      picked the numeric id we'd just return empty rather than
	 *      send a bad value to Cal.
	 *
	 * @param array<string, mixed> $booking handik_bookings row.
	 * @return string Cal UID, '' if not resolvable.
	 */
	protected function extract_cal_uid_for_booking( array $booking ) {
		global $wpdb;
		if ( ! empty( $booking['project_work_day_id'] ) ) {
			$uid = (string) $wpdb->get_var( $wpdb->prepare(
				'SELECT cal_booking_uid FROM ' . Handik_Booking_App_DB::table( 'project_work_days' ) . ' WHERE id = %d LIMIT 1',
				(int) $booking['project_work_day_id']
			) );
			if ( '' !== $uid ) {
				return $uid;
			}
		}
		if ( ! empty( $booking['direct_request_id'] ) ) {
			$uid = (string) $wpdb->get_var( $wpdb->prepare(
				'SELECT cal_booking_uid FROM ' . Handik_Booking_App_DB::table( 'direct_booking_requests' ) . ' WHERE id = %d LIMIT 1',
				(int) $booking['direct_request_id']
			) );
			if ( '' !== $uid ) {
				return $uid;
			}
		}
		if ( ! empty( $booking['raw_webhook_json'] ) ) {
			$raw = json_decode( (string) $booking['raw_webhook_json'], true );
			if ( is_array( $raw ) ) {
				foreach ( array( 'uid', 'bookingUid' ) as $key ) {
					if ( ! empty( $raw[ $key ] ) ) {
						return (string) $raw[ $key ];
					}
				}
				if ( isset( $raw['booking']['uid'] ) ) {
					return (string) $raw['booking']['uid'];
				}
				if ( isset( $raw['booking']['bookingUid'] ) ) {
					return (string) $raw['booking']['bookingUid'];
				}
			}
		}
		$cal_booking_id = (string) ( $booking['cal_booking_id'] ?? '' );
		if ( '' !== $cal_booking_id && ! ctype_digit( $cal_booking_id ) ) {
			return $cal_booking_id;
		}
		return '';
	}

	/**
	 * 2.1.27.0 — cancel the Cal.com side of a local booking so the
	 * customer's email + calendar invite (Apple, Google, etc.) get
	 * the cancel notification. Wired into admin_booking_status (on
	 * status=cancelled), admin_booking_delete, and admin_bookings_
	 * bulk_delete so every local-side cancel/delete propagates to
	 * Cal automatically — owner no longer has to clean up Cal
	 * separately after each test booking.
	 *
	 * Returns one of:
	 *   { 'success' => true, 'uid' => 'abc...' }
	 *   { 'skipped' => true, 'reason' => 'no_cal_api' | 'no_uid' }
	 *   { 'skipped' => false, 'error' => '<cal message>', 'uid' => '...' }
	 *
	 * The caller proceeds with the local action regardless — a Cal
	 * outage or already-cancelled booking shouldn't block the
	 * operator from cleaning up locally.
	 */
	protected function cancel_on_cal_for_booking( array $booking, $reason = '' ) {
		if ( ! $this->cal_api ) {
			return array( 'skipped' => true, 'reason' => 'no_cal_api' );
		}
		$uid = $this->extract_cal_uid_for_booking( $booking );
		if ( '' === $uid ) {
			if ( $this->logger ) {
				$this->logger->info(
					'Skipped Cal cancel — no booking UID resolvable.',
					array( 'booking_id' => (int) ( $booking['id'] ?? 0 ) )
				);
			}
			return array( 'skipped' => true, 'reason' => 'no_uid' );
		}
		$resolved_reason = '' !== trim( (string) $reason ) ? (string) $reason : __( 'Cancelled by admin', 'handik-booking-app' );
		$result = $this->cal_api->cancel_booking( $uid, $resolved_reason );
		if ( ! empty( $result['error'] ) ) {
			if ( $this->logger ) {
				$this->logger->warning(
					'Cal cancel failed; proceeding with local action.',
					array(
						'booking_id' => (int) ( $booking['id'] ?? 0 ),
						'uid'        => $uid,
						'error'      => (string) $result['error'],
					)
				);
			}
			return array( 'skipped' => false, 'error' => (string) $result['error'], 'uid' => $uid );
		}
		if ( $this->logger ) {
			$this->logger->info(
				'Cal booking cancelled.',
				array(
					'booking_id' => (int) ( $booking['id'] ?? 0 ),
					'uid'        => $uid,
					'reason'     => $resolved_reason,
				)
			);
		}
		return array( 'success' => true, 'uid' => $uid );
	}

	/**
	 * 2.1.23.1 — A5: fetch the ChatKit thread transcript from OpenAI
	 * and backfill the local handik_messages table for this booking.
	 *
	 * The local JS bridge (handik-chatkit-bridge.js) writes user/
	 * assistant messages on `composer.submit` and `'message'` events.
	 * Newer `<openai-chatkit>` web component releases ship event-
	 * payload shape changes that occasionally make the bridge's
	 * `extractMessageText` come back empty, leaving the admin's
	 * "What the customer wrote" panel blank for what otherwise looks
	 * like a healthy booking. OpenAI is always the authoritative
	 * source of truth for the conversation, so we offer the admin a
	 * one-click backfill — `ChatKit_Service::fetch_thread_messages`
	 * pages through the `/v1/chatkit/threads/{id}/items` endpoint
	 * and `Messages_Service::record` collapses duplicates against
	 * whatever the bridge DID manage to capture (10-second dedup
	 * window on request_id + role + content).
	 */
	public function admin_booking_fetch_chat( WP_REST_Request $request ) {
		if ( ! $this->bookings || ! $this->job_requests || ! $this->chatkit || ! $this->messages ) {
			return $this->admin_unavailable();
		}
		$booking_id = absint( $request['id'] );
		$booking    = $this->bookings->get( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'handik_booking_not_found', __( 'Booking not found.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}
		$req_id = (int) ( $booking['job_request_id'] ?? 0 );
		if ( $req_id <= 0 ) {
			// Direct + project form bookings have no chat — surface a
			// clear message rather than a generic "no thread id" error.
			return new WP_Error(
				'handik_no_chat_for_form',
				__( 'This booking was made through a form — there is no AI chat history to fetch.', 'handik-booking-app' ),
				array( 'status' => 400 )
			);
		}
		$job_request = $this->job_requests->get( $req_id );
		if ( ! $job_request ) {
			return new WP_Error( 'handik_request_not_found', __( 'Job request row not found.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}
		$thread_id = trim( (string) ( $job_request['chat_thread_id'] ?? '' ) );
		if ( '' === $thread_id ) {
			return new WP_Error(
				'handik_no_thread_id',
				__( 'No ChatKit thread id is recorded for this booking — nothing to fetch.', 'handik-booking-app' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->chatkit->fetch_thread_messages( $thread_id );
		if ( ! empty( $result['error'] ) ) {
			return new WP_Error(
				'handik_chat_fetch_failed',
				(string) $result['error'],
				array( 'status' => isset( $result['status'] ) ? (int) $result['status'] : 502 )
			);
		}
		$messages = isset( $result['messages'] ) && is_array( $result['messages'] ) ? $result['messages'] : array();

		// Pre-load whatever the JS bridge already captured for this
		// request and build a `role|content` hash set so we don't
		// double-insert anything we have a local copy of. The
		// Messages_Service::record() built-in 10-second dedup window
		// only catches near-simultaneous double-fires from the bridge
		// itself — it can't recognize a bridge-captured row from
		// minutes/hours ago that an OpenAI backfill is about to
		// re-insert with an older source timestamp.
		$existing       = $this->messages->list_for_request( $req_id, 1000 );
		$existing_keys  = array();
		foreach ( $existing as $row ) {
			$key = (string) ( $row['role'] ?? '' ) . '|' . md5( (string) ( $row['content'] ?? '' ) );
			$existing_keys[ $key ] = true;
		}

		$inserted = 0;
		foreach ( $messages as $msg ) {
			$role    = (string) ( $msg['role'] ?? 'user' );
			$content = (string) ( $msg['content'] ?? '' );
			$key     = $role . '|' . md5( $content );
			if ( isset( $existing_keys[ $key ] ) ) {
				continue;
			}
			$id = $this->messages->record(
				$req_id,
				(string) ( $msg['thread_id'] ?? $thread_id ),
				$role,
				$content,
				is_array( $msg['metadata'] ?? null ) ? $msg['metadata'] : array()
			);
			if ( $id > 0 ) {
				$existing_keys[ $key ] = true;
				$inserted++;
			}
		}
		return rest_ensure_response( array(
			'success'           => true,
			'fetched'           => count( $messages ),
			'inserted'          => $inserted,
			'duplicates'        => count( $messages ) - $inserted,
			'openai_request_id' => isset( $result['openai_request_id'] ) ? (string) $result['openai_request_id'] : '',
		) );
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

	/**
	 * 2.1.26.0 — A4: bulk-delete a list of job_request rows. Loops
	 * through cascade_delete->delete_request for each id so every
	 * drop matches the per-row admin delete path exactly (messages
	 * + bookings + photos + request row). Accepts up to 200 ids
	 * per call (defensive cap so a single REST round-trip doesn't
	 * timeout / OOM on a corrupt selection).
	 */
	/**
	 * 2.1.26.2 — A6 follow-up: pull bookings from Cal.com and upsert
	 * any that aren't already mirrored locally. Covers two gaps the
	 * automatic webhook doesn't:
	 *   1. Bookings made BEFORE we shipped webhook-side external
	 *      mirroring (2.1.24.0) — they exist on Cal but never reached
	 *      our handik_bookings table.
	 *   2. Bookings where the webhook delivery was dropped (network
	 *      blip, secret rotated mid-flight, signature mismatch we
	 *      logged and bailed on).
	 *
	 * Strategy: read the existing handik_bookings.cal_booking_id set
	 * into a hash, page through Cal's /v2/bookings, and only call
	 * upsert_external_booking for items NOT already in the local
	 * hash. Reuses the same external-booking code path the webhook
	 * uses, so attendee → contact matching, raw_webhook_json stash,
	 * and the "no FK at all" external row fallback are identical to
	 * a fresh webhook arrival.
	 *
	 * Default range: last 90 days through 90 days in the future.
	 * Caller can override via `dateFrom` / `dateTo` body params (ISO
	 * 8601), or pass `pull_all=1` to scan up to the 1000-booking
	 * defensive cap with no date filter.
	 */
	public function admin_pull_from_cal( WP_REST_Request $request ) {
		if ( ! $this->cal_api || ! $this->bookings ) {
			return $this->admin_unavailable();
		}

		$pull_all  = (bool) $request->get_param( 'pull_all' );
		$date_from = (string) $request->get_param( 'dateFrom' );
		$date_to   = (string) $request->get_param( 'dateTo' );
		// 2.1.26.3: default to upcoming-only. Previously the button
		// pulled everything including past cancelled bookings, which
		// flooded the admin with test rows the owner had cancelled on
		// Cal directly. `status` body param overrides; e.g. send
		// `status=accepted` to include both upcoming and past
		// non-cancelled bookings, or omit (default upcoming) to skip
		// cancellations entirely.
		$status_filter = $request->get_param( 'status' );
		$args = array();
		if ( null !== $status_filter && '' !== $status_filter ) {
			$args['status'] = (string) $status_filter;
		} else {
			$args['status'] = 'upcoming';
		}
		if ( $pull_all ) {
			// Cal accepts no-date-filter "give me everything" — capped
			// at the API's hard limit per call, paginated up to our
			// internal 1000-booking ceiling in list_bookings.
		} elseif ( $date_from || $date_to ) {
			if ( $date_from ) { $args['dateFrom'] = $date_from; }
			if ( $date_to )   { $args['dateTo']   = $date_to; }
		} else {
			$args['dateFrom'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 90 * DAY_IN_SECONDS );
			$args['dateTo']   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 90 * DAY_IN_SECONDS );
		}

		$result = $this->cal_api->list_bookings( $args );
		if ( ! empty( $result['error'] ) ) {
			return new WP_Error(
				'handik_cal_pull_failed',
				(string) $result['error'],
				array( 'status' => isset( $result['status'] ) ? (int) $result['status'] : 502 )
			);
		}

		$bookings = isset( $result['bookings'] ) && is_array( $result['bookings'] ) ? $result['bookings'] : array();

		// Pre-load existing cal_booking_id set so we don't run a
		// SELECT per booking. Cap at 5000 — anyone with more than that
		// has bigger problems than this dedup query.
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$existing_ids = $wpdb->get_col( "SELECT cal_booking_id FROM {$table} WHERE cal_booking_id <> '' LIMIT 5000" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$existing_set = array_flip( array_map( 'strval', (array) $existing_ids ) );

		$inserted     = 0;
		$already      = 0;
		$skipped      = 0;
		foreach ( $bookings as $cal ) {
			$payload  = isset( $cal['raw'] ) && is_array( $cal['raw'] ) ? $cal['raw'] : $cal;
			$cal_id   = $this->bookings->extract_booking_id( $payload );
			if ( '' === $cal_id ) {
				$skipped++;
				continue;
			}
			// 2.1.26.3: defensive skip-cancelled — even with the
			// `status=upcoming` API filter (set as default a few
			// lines above), Cal occasionally returns cancelled rows
			// when the booking was cancelled AFTER the upcoming-
			// window started. Owner reported the previous build
			// flooded with cancelled test bookings, so we belt-and-
			// suspenders skip them here too.
			$cal_status = isset( $payload['status'] ) ? strtolower( (string) $payload['status'] ) : '';
			if ( 'cancelled' === $cal_status || 'rejected' === $cal_status ) {
				$skipped++;
				continue;
			}
			if ( isset( $existing_set[ $cal_id ] ) ) {
				$already++;
				continue;
			}
			$row_id = $this->bookings->upsert_external_booking( $payload, 'booked' );
			if ( $row_id > 0 ) {
				$inserted++;
				$existing_set[ $cal_id ] = true;
			} else {
				$skipped++;
			}
		}

		return rest_ensure_response( array(
			'success'         => true,
			'fetched'         => count( $bookings ),
			'already_present' => $already,
			'inserted'        => $inserted,
			'skipped'         => $skipped,
		) );
	}

	/**
	 * 2.1.26.3 — bulk-delete a list of bookings. Loops each id
	 * through Cascade_Delete_Service::delete_booking so each drop
	 * matches the per-row admin delete path. NOT cancelled on
	 * Cal.com (matching single-row behaviour — owner cleans local
	 * DB without touching Cal). Capped at 200 per call.
	 *
	 * 2.1.26.4 — audit log every call so any future "I deleted X
	 * but Y went missing too" report has a server-side record of
	 * the exact id list submitted, who submitted, and how many
	 * rows the cascade actually dropped. Owner-reported critical
	 * data-loss in 2.1.26.3 traced to a JS duplicate-checkbox
	 * desync; the fix lives in `initBulkMode()` but the audit log
	 * here gives us evidence if it ever happens again.
	 */
	public function admin_bookings_bulk_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$raw_ids = $request->get_param( 'ids' );
		if ( ! is_array( $raw_ids ) ) {
			return new WP_Error( 'handik_bulk_delete_invalid', __( 'No booking ids provided.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'handik_bulk_delete_invalid', __( 'No valid booking ids provided.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 200 ) {
			return new WP_Error( 'handik_bulk_delete_too_many', __( 'Bulk delete is capped at 200 bookings per call.', 'handik-booking-app' ), array( 'status' => 413 ) );
		}
		// 2.1.27.0 — single reason for the whole bulk; passed to each
		// Cal cancel so the customer's notification email carries
		// consistent context.
		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		if ( $this->logger ) {
			$this->logger->warning(
				'Admin bulk-delete bookings — incoming request.',
				array(
					'user_id'  => get_current_user_id(),
					'id_count' => count( $ids ),
					'ids'      => $ids,
				)
			);
		}
		$deleted          = 0;
		$failed           = array();
		$cal_cancelled    = 0;
		$cal_skipped      = 0;
		$cal_errors       = array();
		foreach ( $ids as $id ) {
			// 2.1.27.0 — propagate to Cal BEFORE the local cascade so
			// the customer gets the cancel notification + their
			// calendar invite is updated. Cal-side failures don't
			// abort the local delete — we want the operator's cleanup
			// to succeed even if Cal is unreachable.
			if ( $this->bookings ) {
				$booking = $this->bookings->get( (int) $id );
				if ( $booking ) {
					$cal_res = $this->cancel_on_cal_for_booking( $booking, $reason );
					if ( ! empty( $cal_res['success'] ) ) {
						$cal_cancelled++;
					} elseif ( ! empty( $cal_res['skipped'] ) ) {
						$cal_skipped++;
					} elseif ( ! empty( $cal_res['error'] ) ) {
						$cal_errors[] = array( 'id' => (int) $id, 'error' => (string) $cal_res['error'] );
					}
				}
			}
			$res = $this->cascade_delete->delete_booking( (int) $id );
			if ( ! empty( $res['deleted'] ) ) {
				$deleted++;
			} else {
				$failed[] = (int) $id;
			}
		}
		if ( $this->logger ) {
			$this->logger->info(
				'Admin bulk-delete bookings — completed.',
				array(
					'user_id'        => get_current_user_id(),
					'requested'      => count( $ids ),
					'deleted'        => $deleted,
					'failed'         => $failed,
					'cal_cancelled'  => $cal_cancelled,
					'cal_skipped'    => $cal_skipped,
					'cal_error_count'=> count( $cal_errors ),
				)
			);
		}
		return rest_ensure_response( array(
			'success'       => true,
			'requested'     => count( $ids ),
			'deleted'       => $deleted,
			'failed'        => $failed,
			'cal_cancelled' => $cal_cancelled,
			'cal_skipped'   => $cal_skipped,
			'cal_errors'    => $cal_errors,
		) );
	}

	/**
	 * 2.1.26.3 — bulk-delete a list of contacts (cascade: contact +
	 * all requests + bookings + addresses). Gated on MANAGE_DELETE
	 * (delete_permission). UI requires typed-confirm before
	 * posting. Capped at 100 per call — contact cascade is much
	 * heavier than booking delete.
	 */
	public function admin_contacts_bulk_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$raw_ids = $request->get_param( 'ids' );
		if ( ! is_array( $raw_ids ) ) {
			return new WP_Error( 'handik_bulk_delete_invalid', __( 'No contact ids provided.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'handik_bulk_delete_invalid', __( 'No valid contact ids provided.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'handik_bulk_delete_too_many', __( 'Bulk delete is capped at 100 contacts per call.', 'handik-booking-app' ), array( 'status' => 413 ) );
		}
		if ( $this->logger ) {
			$this->logger->warning(
				'Admin bulk-delete contacts — incoming request.',
				array(
					'user_id'  => get_current_user_id(),
					'id_count' => count( $ids ),
					'ids'      => $ids,
				)
			);
		}
		$deleted = 0;
		$failed  = array();
		foreach ( $ids as $id ) {
			$res = $this->cascade_delete->delete_contact( (int) $id );
			if ( ! empty( $res['deleted'] ) ) {
				$deleted++;
			} else {
				$failed[] = (int) $id;
			}
		}
		if ( $this->logger ) {
			$this->logger->info(
				'Admin bulk-delete contacts — completed.',
				array(
					'user_id'   => get_current_user_id(),
					'requested' => count( $ids ),
					'deleted'   => $deleted,
					'failed'    => $failed,
				)
			);
		}
		return rest_ensure_response( array(
			'success'  => true,
			'requested'=> count( $ids ),
			'deleted'  => $deleted,
			'failed'   => $failed,
		) );
	}

	public function admin_job_requests_bulk_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$raw_ids = $request->get_param( 'ids' );
		if ( ! is_array( $raw_ids ) ) {
			return new WP_Error( 'handik_bulk_delete_invalid', __( 'No request ids provided.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'handik_bulk_delete_invalid', __( 'No valid request ids provided.', 'handik-booking-app' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 200 ) {
			return new WP_Error( 'handik_bulk_delete_too_many', __( 'Bulk delete is capped at 200 requests per call.', 'handik-booking-app' ), array( 'status' => 413 ) );
		}
		$deleted = 0;
		$failed  = array();
		foreach ( $ids as $id ) {
			$res = $this->cascade_delete->delete_request( (int) $id );
			if ( ! empty( $res['deleted'] ) ) {
				$deleted++;
			} else {
				$failed[] = (int) $id;
			}
		}
		return rest_ensure_response( array(
			'success'  => true,
			'requested'=> count( $ids ),
			'deleted'  => $deleted,
			'failed'   => $failed,
		) );
	}

	public function admin_booking_delete( WP_REST_Request $request ) {
		if ( ! $this->cascade_delete ) {
			return $this->admin_unavailable();
		}
		$id     = absint( $request['id'] );
		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		// 2.1.27.0 — cancel on Cal BEFORE the local cascade delete so
		// the customer's email + calendar invite get the cancel
		// notification. Cal cancel + local delete are both best-effort;
		// neither blocks the other. If Cal failed but local delete
		// succeeds, the response surfaces `cal_cancelled: false` so
		// the operator can re-cancel manually if needed.
		$cal_result = array( 'skipped' => true );
		if ( $this->bookings ) {
			$booking = $this->bookings->get( $id );
			if ( $booking ) {
				$cal_result = $this->cancel_on_cal_for_booking( $booking, $reason );
			}
		}
		$res = $this->cascade_delete->delete_booking( $id );
		if ( empty( $res['deleted'] ) ) {
			return new WP_Error( 'handik_delete_failed', __( 'Could not delete that booking.', 'handik-booking-app' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'success'       => true,
			'summary'       => $res['summary'] ?? array(),
			'cal_cancelled' => ! empty( $cal_result['success'] ),
			'cal_skipped'   => ! empty( $cal_result['skipped'] ),
			'cal_error'     => isset( $cal_result['error'] ) ? (string) $cal_result['error'] : '',
		) );
	}

	/**
	 * Sprint 13 — admin booking creation. Forwards the form payload
	 * to Direct_Booking_Service::admin_submit(), which handles both
	 * "use existing contact" (contact_id + address_id shortcuts) and
	 * "book a brand-new walk-in" (full_name + phone + address_full)
	 * paths. Returns the same shape as the public submit so the admin
	 * JS can mount the Cal embed identically.
	 */
	public function admin_booking_new( WP_REST_Request $request ) {
		if ( ! $this->direct_booking ) {
			return $this->admin_unavailable();
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$slug = isset( $body['preset_slug'] ) ? sanitize_title( (string) $body['preset_slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error(
				'handik_admin_no_preset',
				__( 'Pick a booking preset first.', 'handik-booking-app' ),
				array( 'status' => 400 )
			);
		}
		$res = $this->direct_booking->admin_submit( $slug, $body );
		if ( ! empty( $res['error'] ) ) {
			$status = (int) ( $res['status'] ?? 400 );
			return new WP_Error( 'handik_admin_book_failed', $res['error'], array( 'status' => $status ) );
		}
		return rest_ensure_response( array(
			'success'         => true,
			'request_id'      => (int) ( $res['request_id'] ?? 0 ),
			'cal_booking_url' => (string) ( $res['cal_booking_url'] ?? '' ),
			'capture_token'   => (string) ( $res['capture_token'] ?? '' ),
		) );
	}

	/**
	 * Sprint 13 — contact autocomplete for the Add-Booking form.
	 * Matches by leading-fragment on full_name, phone digits, or
	 * email. Returns up to 10 lightweight hits with primary address
	 * pre-attached so the form can populate the address picker
	 * without a second round trip.
	 */
	public function admin_contact_search( WP_REST_Request $request ) {
		global $wpdb;
		if ( ! $this->contacts ) {
			return $this->admin_unavailable();
		}
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( strlen( $q ) < 2 ) {
			return rest_ensure_response( array( 'results' => array() ) );
		}
		$contacts_table  = Handik_Booking_App_DB::table( 'contacts' );
		$addresses_table = Handik_Booking_App_DB::table( 'addresses' );
		$digits = preg_replace( '/\D/', '', $q );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, email FROM {$contacts_table}
			 WHERE full_name LIKE %s OR email LIKE %s OR REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'(',''),')','') LIKE %s
			 ORDER BY updated_at DESC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$like, $like, '%' . $wpdb->esc_like( $digits ) . '%'
		), ARRAY_A );

		$results = array();
		foreach ( (array) $rows as $row ) {
			$contact_id = (int) $row['id'];
			$addresses = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, address_full, address_unit FROM {$addresses_table}
				 WHERE contact_id = %d AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
				 ORDER BY is_default DESC, id DESC LIMIT 5",
				$contact_id
			), ARRAY_A );
			$results[] = array(
				'id'        => $contact_id,
				'full_name' => (string) $row['full_name'],
				'phone'     => (string) $row['phone'],
				'email'     => (string) $row['email'],
				'addresses' => array_map( static function ( $a ) {
					return array(
						'id'           => (int) $a['id'],
						'address_full' => (string) $a['address_full'],
						'address_unit' => (string) $a['address_unit'],
					);
				}, (array) $addresses ),
			);
		}
		return rest_ensure_response( array( 'results' => $results ) );
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
