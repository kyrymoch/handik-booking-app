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
		$this->route( $namespace, '/chatkit-session', array( $this, 'chatkit_session' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/assistant-result', array( $this, 'assistant_result' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/chatkit-thread', array( $this, 'chatkit_thread' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/client-log', array( $this, 'client_log' ), WP_REST_Server::CREATABLE );
		$this->route( $namespace, '/booking-url', array( $this, 'booking_url' ), WP_REST_Server::CREATABLE );
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
		return $this->respond( $this->app->upload_photo( $files['file'] ) );
	}

	public function request_code( WP_REST_Request $request ) {
		return $this->respond( $this->auth->request_code( (string) $request->get_param( 'email' ), (string) $request->get_param( 'phone' ), (string) $request->get_param( 'redirect' ) ) );
	}

	public function verify_code( WP_REST_Request $request ) {
		return $this->respond( $this->auth->verify( (string) $request->get_param( 'email' ), (string) $request->get_param( 'phone' ), (string) $request->get_param( 'code' ), (string) $request->get_param( 'token' ) ) );
	}

	public function chatkit_session( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->create_session( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
	}

	public function assistant_result( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->save_assistant_result( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ), (array) $request->get_param( 'assistant_result' ) ) );
	}

	public function chatkit_thread( WP_REST_Request $request ) {
		return $this->respond( $this->chatkit->associate_thread( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ), (string) $request->get_param( 'thread_id' ) ) );
	}

	public function client_log( WP_REST_Request $request ) {
		$plugin = handik_booking_app();
		$level   = sanitize_key( (string) $request->get_param( 'level' ) );
		$message = sanitize_text_field( (string) $request->get_param( 'message' ) );
		$context = (array) $request->get_param( 'context' );

		if ( ! $message ) {
			return $this->respond( array( 'error' => __( 'Log message is required.', 'handik-booking-app' ), 'status' => 400 ) );
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

	public function booking_url( WP_REST_Request $request ) {
		return $this->respond( $this->app->booking_url( absint( $request->get_param( 'request_id' ) ), (string) $request->get_param( 'draft_token' ) ) );
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
		if ( ! empty( $result['error'] ) ) {
			return new WP_Error( 'handik_booking_app_error', $result['error'], array( 'status' => absint( $result['status'] ?? 400 ) ) );
		}
		return rest_ensure_response( $result );
	}
}
