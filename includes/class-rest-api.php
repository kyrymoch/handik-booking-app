<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_REST_API {
	/**
	 * @var Handik_Booking_App_Controller
	 */
	protected $app;

	/**
	 * @var Handik_Booking_App_Auth_Service
	 */
	protected $auth;

	/**
	 * @var Handik_Booking_App_ChatKit_Service
	 */
	protected $chatkit;

	/**
	 * @var Handik_Booking_App_Webhook_Service
	 */
	protected $webhook;

	/**
	 * @param Handik_Booking_App_Controller      $app App.
	 * @param Handik_Booking_App_Auth_Service    $auth Auth.
	 * @param Handik_Booking_App_ChatKit_Service $chatkit ChatKit.
	 * @param Handik_Booking_App_Webhook_Service $webhook Webhook.
	 */
	public function __construct( $app, $auth, $chatkit, $webhook ) {
		$this->app     = $app;
		$this->auth    = $auth;
		$this->chatkit = $chatkit;
		$this->webhook = $webhook;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$namespace = 'handik-booking-app/v1';

		$this->route( $namespace, '/app/bootstrap', array( $this, 'bootstrap' ), WP_REST_Server::READABLE );
		$this->route( $namespace, '/app/draft', array( $this, 'save_draft' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/app/upload', array( $this, 'upload' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/auth/request-code', array( $this, 'request_code' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/auth/verify', array( $this, 'verify_code' ), WP_REST_Server::CREATABLE );
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
	 * @param array<string, mixed> $result Result.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( array $result ) {
		return Handik_Booking_App_Api_Response::from_array( $result );
	}
}
