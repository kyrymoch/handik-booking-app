<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_ChatKit_Service {
	const COOKIE_NAME = 'handik_booking_app_chat_user';

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @var Handik_Booking_App_Routing_Service
	 */
	protected $routing;

	/**
	 * @var Handik_Booking_App_Cal_Service
	 */
	protected $cal;

	/**
	 * @var Handik_Booking_App_Photo_Analysis_Service
	 */
	protected $photo_analysis;

	/**
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Routing_Service      $routing Routing.
	 * @param Handik_Booking_App_Cal_Service          $cal Cal.
	 * @param Handik_Booking_App_Photo_Analysis_Service $photo_analysis Photo analysis.
	 */
	public function __construct( $settings, $logger, $job_requests, $routing, $cal, $photo_analysis ) {
		$this->settings       = $settings;
		$this->logger         = $logger;
		$this->job_requests   = $job_requests;
		$this->routing        = $routing;
		$this->cal            = $cal;
		$this->photo_analysis = $photo_analysis;
	}

	/**
	 * @param int    $request_id Request ID.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function create_session( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$request        = $this->job_requests->get( $request_id );
		$api_key        = trim( (string) $this->settings->get( 'openai_api_key', '' ) );
		$workflow       = trim( (string) $this->settings->get( 'openai_workflow_id', '' ) );
		$api_base       = untrailingslashit( trim( (string) $this->settings->get( 'openai_api_base', 'https://api.openai.com' ) ) );
		$project_id     = trim( (string) $this->settings->get( 'openai_project_id', '' ) );
		$organization_id = trim( (string) $this->settings->get( 'openai_organization_id', '' ) );

		if ( ! $request || ! $api_key || ! $workflow ) {
			return array( 'error' => __( 'OpenAI settings or draft request are missing.', 'handik-booking-app' ), 'status' => 500 );
		}

		$this->logger->info(
			'ChatKit session requested.',
			array(
				'request_id'       => $request_id,
				'workflow'         => $workflow,
				'project_id'       => $project_id,
				'organization_id'  => $organization_id,
				'has_existing_thread' => ! empty( $request['chat_thread_id'] ),
			)
		);

		$user_id  = $this->resolve_user_id( $request );
		$headers  = array(
			'Authorization'      => 'Bearer ' . $api_key,
			'OpenAI-Beta'        => 'chatkit_beta=v1',
			'Content-Type'       => 'application/json',
			'X-Client-Request-Id'=> 'handik-chatkit-' . wp_generate_uuid4(),
		);

		if ( $project_id ) {
			$headers['OpenAI-Project'] = $project_id;
		}
		if ( $organization_id ) {
			$headers['OpenAI-Organization'] = $organization_id;
		}

		$response = wp_remote_post(
			$api_base . '/v1/chatkit/sessions',
			array(
				'headers' => $headers,
				'timeout' => 15,
				'body'    => wp_json_encode(
					array(
						'user'     => $user_id,
						'workflow' => array( 'id' => $workflow ),
						'chatkit_configuration' => array(
							'file_upload' => array(
								'enabled'       => true,
								'max_file_size' => 10,
								'max_files'     => 5,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'ChatKit session request failed.',
				array(
					'message'    => $response->get_error_message(),
					'request_id' => $request_id,
					'workflow'   => $workflow,
				)
			);
			return array( 'error' => __( 'Unable to create assistant session.', 'handik-booking-app' ), 'status' => 502 );
		}

		$code             = (int) wp_remote_retrieve_response_code( $response );
		$raw_body         = (string) wp_remote_retrieve_body( $response );
		$payload          = json_decode( $raw_body, true );
		$openai_request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' );
		$error_message     = $this->extract_error_message( $payload, $raw_body );
		$client_secret     = $this->normalize_client_secret( $payload );
		if ( $code < 200 || $code >= 300 || empty( $client_secret ) ) {
			$this->logger->error(
				'Unexpected ChatKit response.',
				array(
					'status'            => $code,
					'openai_request_id' => $openai_request_id,
					'workflow'          => $workflow,
					'project_id'        => $project_id,
					'organization_id'   => $organization_id,
					'body'              => is_array( $payload ) ? $payload : $raw_body,
				)
			);

			return array(
				'error'             => $error_message ? $error_message : __( 'Assistant session could not be created.', 'handik-booking-app' ),
				'status'            => $code >= 400 ? $code : 502,
				'openai_request_id' => $openai_request_id,
			);
		}

		$this->job_requests->set_chat_session( $request_id, (string) ( $payload['id'] ?? '' ), $user_id );
		$this->logger->info(
			'ChatKit session created.',
			array(
				'request_id'        => $request_id,
				'session_id'        => (string) ( $payload['id'] ?? '' ),
				'openai_request_id' => $openai_request_id,
				'has_file_upload'   => ! empty( $payload['chatkit_configuration']['file_upload'] ),
			)
		);

		$draft_context                  = $this->job_requests->build_context( $request_id );
		$draft_context['photo_analysis'] = $this->photo_analysis->cached_analysis( $request );

		return array(
			'client_secret'   => $client_secret,
			'clientSecret'    => $client_secret,
			'expires_after'   => $payload['expires_after'] ?? null,
			'user_id'         => $user_id,
			'workflow_id'     => $workflow,
			'state_variables' => $this->state_variables( $request ),
			'draft_context'   => $draft_context,
			'file_upload'     => is_array( $payload['chatkit_configuration']['file_upload'] ?? null ) ? $payload['chatkit_configuration']['file_upload'] : null,
		);
	}

	/**
	 * @param int    $request_id Request ID.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function warm_photo_analysis( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$analysis = $this->photo_analysis->analyze_request( $request, false );
		return array(
			'success'          => true,
			'photo_analysis'   => $analysis,
			'analysis_status'  => ! empty( $analysis ) ? 'ready' : 'failed',
			'cached'           => ! empty( $analysis['source'] ) && 'cache' === $analysis['source'],
		);
	}

	/**
	 * @param int    $request_id Request ID.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function request_photo_context( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$has_photos = ! empty( $request['photos'] ) && is_array( $request['photos'] );
		$analysis   = array();

		if ( $has_photos ) {
			$analysis = $this->photo_analysis->analyze_request( $request, false );
			$request  = $this->job_requests->get( $request_id );
			if ( ! $request ) {
				return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
			}

			if ( empty( $analysis ) ) {
				$analysis = $this->photo_analysis->cached_analysis( $request );
			}
		}

		$payload = $this->build_photo_context_payload( $request, $analysis );
		$this->logger->info(
			'Photo context requested for ChatKit client tool.',
			array(
				'request_id'                    => $request_id,
				'has_photos'                    => ! empty( $payload['has_photos'] ),
				'photo_analysis_status'         => $payload['photo_analysis_status'],
				'has_actionable_visual_context' => ! empty( $payload['has_actionable_visual_context'] ),
			)
		);

		return array_merge(
			array(
				'success' => true,
			),
			$payload
		);
	}

	/**
	 * @param int    $request_id Request ID.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function request_pricing_context( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$payload = $this->build_pricing_context_payload( $request );
		$this->logger->info(
			'Pricing context requested for ChatKit client tool.',
			array(
				'request_id'               => $request_id,
				'selected_task_count'      => $payload['selected_task_count'],
				'applied_hourly_rate'      => $payload['applied_hourly_rate'],
				'suggested_duration_hours' => $payload['suggested_duration_hours'],
				'labor_estimate_low'       => $payload['labor_estimate_low'],
				'labor_estimate_high'      => $payload['labor_estimate_high'],
				'total_estimate_low'       => $payload['total_estimate_low'],
				'total_estimate_high'      => $payload['total_estimate_high'],
			)
		);

		return array_merge(
			array(
				'success' => true,
			),
			$payload
		);
	}

	/**
	 * @param mixed $payload Parsed payload.
	 * @return string
	 */
	protected function normalize_client_secret( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}
		if ( ! empty( $payload['client_secret'] ) && is_string( $payload['client_secret'] ) ) {
			return $payload['client_secret'];
		}
		if ( ! empty( $payload['client_secret']['value'] ) && is_string( $payload['client_secret']['value'] ) ) {
			return $payload['client_secret']['value'];
		}
		if ( ! empty( $payload['clientSecret'] ) && is_string( $payload['clientSecret'] ) ) {
			return $payload['clientSecret'];
		}
		return '';
	}

	/**
	 * @param mixed  $payload Parsed payload.
	 * @param string $raw_body Raw body.
	 * @return string
	 */
	protected function extract_error_message( $payload, $raw_body ) {
		if ( is_array( $payload ) ) {
			if ( ! empty( $payload['error']['message'] ) ) {
				return sanitize_text_field( (string) $payload['error']['message'] );
			}
			if ( ! empty( $payload['message'] ) ) {
				return sanitize_text_field( (string) $payload['message'] );
			}
			if ( ! empty( $payload['error'] ) && is_string( $payload['error'] ) ) {
				return sanitize_text_field( $payload['error'] );
			}
		}

		$raw_body = trim( (string) $raw_body );
		if ( '' === $raw_body ) {
			return '';
		}

		return sanitize_text_field( substr( $raw_body, 0, 300 ) );
	}

	/**
	 * @param int                  $request_id Request ID.
	 * @param string               $draft_token Token.
	 * @param array<string, mixed> $assistant_result Result.
	 * @return array<string, mixed>
	 */
	public function save_assistant_result( $request_id, $draft_token, array $assistant_result ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$this->logger->info(
			'Assistant result received.',
			array(
				'request_id'               => $request_id,
				'booking_type'             => sanitize_key( (string) ( $assistant_result['booking_type'] ?? '' ) ),
				'duration_bucket'          => sanitize_key( (string) ( $assistant_result['duration_bucket'] ?? '' ) ),
				'suggested_duration_hours' => sanitize_text_field( (string) ( $assistant_result['suggested_duration_hours'] ?? '' ) ),
				'pricing_posture'          => sanitize_key( (string) ( $assistant_result['pricing_posture'] ?? '' ) ),
				'applied_hourly_rate'      => max( 0, (float) ( $assistant_result['applied_hourly_rate'] ?? 0 ) ),
				'total_estimate_low'       => max( 0, (float) ( $assistant_result['total_estimate_low'] ?? 0 ) ),
				'total_estimate_high'      => max( 0, (float) ( $assistant_result['total_estimate_high'] ?? 0 ) ),
				'enough_information'       => ! empty( $assistant_result['enough_information'] ),
				'has_stored_result'        => ! empty( $request['assistant_result'] ),
			)
		);

		$stored_assistant   = $this->sanitize_assistant_result( is_array( $request['assistant_result'] ?? null ) ? $request['assistant_result'] : array() );
		$incoming_assistant = $this->sanitize_assistant_result( $assistant_result );
		$request_app_state  = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$incoming_differs_from_locked_url = ! empty( $request['cal_booking_url'] ) && $this->request_has_complete_routing( $request ) && $this->has_complete_routing_payload( $incoming_assistant ) && (
			(string) $incoming_assistant['booking_type'] !== (string) ( $request['booking_type'] ?? '' ) ||
			(string) $incoming_assistant['duration_bucket'] !== (string) ( $request['duration_bucket'] ?? '' ) ||
			(string) $incoming_assistant['suggested_duration_hours'] !== (string) ( $request_app_state['suggested_duration_hours'] ?? '' )
		);
		if ( ! $this->has_complete_routing_payload( $incoming_assistant ) && ! $this->has_complete_routing_payload( $stored_assistant ) && ! $this->request_has_complete_routing( $request ) ) {
			$this->logger->info(
				'Assistant result ignored because routing is not complete yet.',
				array(
					'request_id'                  => $request_id,
					'incoming_booking_type'       => sanitize_key( (string) ( $incoming_assistant['booking_type'] ?? '' ) ),
					'incoming_duration_bucket'    => sanitize_key( (string) ( $incoming_assistant['duration_bucket'] ?? '' ) ),
					'incoming_suggested_duration' => sanitize_text_field( (string) ( $incoming_assistant['suggested_duration_hours'] ?? '' ) ),
					'has_stored_result'           => false,
				)
			);

			return array(
				'success'                => true,
				'assistant_result_saved' => false,
				'booking_url_ready'      => false,
				'assistant_ready_for_booking' => false,
				'assistant_result'       => $stored_assistant,
				'routing'                => array(
					'status'         => 'needs_more_info',
					'routing_status' => 'awaiting_assistant',
				),
				'booking_url'            => '',
				'photo_analysis'         => ! empty( $request_app_state['photo_analysis'] ) && is_array( $request_app_state['photo_analysis'] ) ? $request_app_state['photo_analysis'] : array(),
				'unsafe_flag'            => ! empty( $request['unsafe_flag'] ),
				'unsafe_reason'          => $request['unsafe_reason'] ?? '',
			);
		}
		if ( ( ! $this->has_complete_routing_payload( $incoming_assistant ) || $incoming_differs_from_locked_url ) && ( $this->has_complete_routing_payload( $stored_assistant ) || $this->request_has_complete_routing( $request ) ) ) {
			$preserved_assistant = $this->has_complete_routing_payload( $stored_assistant ) ? $stored_assistant : $this->assistant_from_request( $request, $stored_assistant );
			$booking_url         = $this->assistant_ready_for_booking( $preserved_assistant, $request ) ? ( ! empty( $request['cal_booking_url'] ) ? (string) $request['cal_booking_url'] : $this->cal->build_booking_url( $request_id ) ) : '';
			$this->logger->info(
				'Ignored assistant result to preserve existing routing.',
				array(
					'request_id'                    => $request_id,
					'reason'                        => $incoming_differs_from_locked_url ? 'locked_booking_url_mismatch' : 'incomplete_payload',
					'incoming_booking_type'         => sanitize_key( (string) ( $incoming_assistant['booking_type'] ?? '' ) ),
					'incoming_duration_bucket'      => sanitize_key( (string) ( $incoming_assistant['duration_bucket'] ?? '' ) ),
					'incoming_suggested_duration'   => sanitize_text_field( (string) ( $incoming_assistant['suggested_duration_hours'] ?? '' ) ),
					'preserved_booking_type'        => sanitize_key( (string) ( $request['booking_type'] ?? $stored_assistant['booking_type'] ?? '' ) ),
					'preserved_duration_bucket'     => sanitize_key( (string) ( $request['duration_bucket'] ?? $stored_assistant['duration_bucket'] ?? '' ) ),
					'preserved_suggested_duration'  => sanitize_text_field( (string) ( $request_app_state['suggested_duration_hours'] ?? $stored_assistant['suggested_duration_hours'] ?? '' ) ),
					'has_preserved_booking_url'     => ! empty( $booking_url ),
				)
			);

			return array(
				'success'                => true,
				'assistant_result_saved' => true,
				'booking_url_ready'      => ! empty( $booking_url ),
				'assistant_ready_for_booking' => $this->assistant_ready_for_booking( $preserved_assistant, $request, $booking_url ),
				'assistant_result'       => $preserved_assistant,
				'routing'                => $this->routing_from_request( $request, $preserved_assistant ),
				'booking_url'            => $booking_url,
				'photo_analysis'         => ! empty( $request_app_state['photo_analysis'] ) && is_array( $request_app_state['photo_analysis'] ) ? $request_app_state['photo_analysis'] : array(),
				'unsafe_flag'            => ! empty( $request['unsafe_flag'] ),
				'unsafe_reason'          => $request['unsafe_reason'] ?? '',
			);
		}

		$photo_analysis = $this->photo_analysis->analyze_request( $request, false );
		$assistant      = $this->merge_assistant_result(
			$stored_assistant,
			$this->sanitize_assistant_result( $this->inject_photo_analysis_into_result( $assistant_result, $photo_analysis ) )
		);
		$routing   = $this->routing->route( $request, $assistant );
		$this->job_requests->apply_routing( $request_id, $routing, $assistant );
		$this->logger->info(
			'Assistant result processed.',
			array(
				'request_id'          => $request_id,
				'enough_information'  => ! empty( $assistant['enough_information'] ),
				'booking_type'        => $routing['booking_type'] ?? '',
				'duration_bucket'     => $routing['duration_bucket'] ?? '',
				'suggested_duration_hours' => $routing['suggested_duration_hours'] ?? '',
				'pricing_posture'     => $routing['pricing_posture'] ?? '',
				'applied_hourly_rate' => $assistant['applied_hourly_rate'] ?? 0,
				'total_estimate_low'  => $assistant['total_estimate_low'] ?? 0,
				'total_estimate_high' => $assistant['total_estimate_high'] ?? 0,
				'status'              => $routing['status'] ?? '',
				'routing_status'      => $routing['routing_status'] ?? '',
				'used_stored_result'  => ! empty( $request['assistant_result'] ),
			)
		);

		$booking_url = '';
		if ( 'ready_for_booking' === $routing['status'] && ! empty( $routing['booking_type'] ) ) {
			$booking_url = $this->cal->build_booking_url( $request_id );
		}
		$assistant_ready_for_booking = $this->assistant_ready_for_booking( $assistant, $routing, $booking_url );

		return array(
			'success'                => true,
			'assistant_result_saved' => $this->has_complete_routing_payload( $assistant ),
			'booking_url_ready'      => ! empty( $booking_url ),
			'assistant_ready_for_booking' => $assistant_ready_for_booking,
			'assistant_result'       => $assistant,
			'routing'                => $routing,
			'booking_url'            => $booking_url,
			'photo_analysis'         => $photo_analysis,
			'unsafe_flag'            => ! empty( $routing['unsafe_flag'] ),
			'unsafe_reason'          => $routing['unsafe_reason'],
		);
	}

	/**
	 * @param int    $request_id Request ID.
	 * @param string $draft_token Token.
	 * @param string $thread_id Thread.
	 * @return array<string, mixed>
	 */
	public function associate_thread( $request_id, $draft_token, $thread_id ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}
		if ( ! $thread_id ) {
			return array( 'error' => __( 'Thread ID is required.', 'handik-booking-app' ), 'status' => 400 );
		}
		$this->job_requests->set_thread( $request_id, $thread_id );
		$this->logger->info(
			'ChatKit thread associated.',
			array(
				'request_id' => $request_id,
				'thread_id'  => $thread_id,
			)
		);
		return array( 'success' => true, 'thread_id' => $thread_id );
	}

	protected function resolve_user_id( array $request ) {
		if ( ! empty( $request['chat_user_id'] ) ) {
			return sanitize_text_field( (string) $request['chat_user_id'] );
		}
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}
		$user_id = ! empty( $request['contact_id'] ) ? 'contact_' . (int) $request['contact_id'] : wp_generate_uuid4();
		setcookie(
			self::COOKIE_NAME,
			$user_id,
			array(
				'expires'  => time() + MONTH_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $user_id;
		return $user_id;
	}

	protected function state_variables( array $request ) {
		$analysis = $this->photo_analysis->cached_analysis( $request );
		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		return array(
			'draft_request_id'             => (int) $request['id'],
			'client_type'                  => (string) $request['client_type'],
			'job_shape'                    => (string) $request['job_shape'],
			'selected_task_count'          => ! empty( $request['selected_tasks'] ) && is_array( $request['selected_tasks'] ) ? count( $request['selected_tasks'] ) : 0,
			'selected_tasks_summary'       => ! empty( $request['selected_tasks'] ) && is_array( $request['selected_tasks'] ) ? implode( ', ', array_map( 'sanitize_key', $request['selected_tasks'] ) ) : '',
			'address_summary'              => (string) ( $request['address_full'] ?? '' ),
			'is_project'                   => ! empty( $request['is_project'] ),
			'has_photos'                   => ! empty( $request['photos'] ),
			'has_actionable_visual_context'=> ! empty( $analysis['has_actionable_visual_context'] ),
			'photo_context_summary'        => (string) ( $analysis['photo_context_summary'] ?? '' ),
			'visible_tasks_summary'        => (string) ( $analysis['visible_tasks_summary'] ?? '' ),
			'safety_summary'               => (string) ( $analysis['safety_summary'] ?? '' ),
			'visual_estimate_notes'        => (string) ( $analysis['visual_estimate_notes'] ?? '' ),
			'suggested_duration_hours'     => (string) ( $app_state['suggested_duration_hours'] ?? '' ),
			'pricing_posture'              => (string) ( $app_state['pricing_posture'] ?? '' ),
			'applied_hourly_rate'          => (float) ( $app_state['applied_hourly_rate'] ?? 0 ),
			'labor_estimate_low'           => (float) ( $app_state['labor_estimate_low'] ?? 0 ),
			'labor_estimate_high'          => (float) ( $app_state['labor_estimate_high'] ?? 0 ),
			'materials_estimate_low'       => (float) ( $app_state['materials_estimate_low'] ?? 0 ),
			'materials_estimate_high'      => (float) ( $app_state['materials_estimate_high'] ?? 0 ),
			'total_estimate_low'           => (float) ( $app_state['total_estimate_low'] ?? 0 ),
			'total_estimate_high'          => (float) ( $app_state['total_estimate_high'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $analysis Analysis.
	 * @return array<string, mixed>
	 */
	protected function build_photo_context_payload( array $request, array $analysis ) {
		$app_state   = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$photos      = ! empty( $request['photos'] ) && is_array( $request['photos'] ) ? array_values( $request['photos'] ) : array();
		$has_photos  = ! empty( $photos );
		$status      = ! empty( $app_state['photo_analysis_status'] ) ? sanitize_key( (string) $app_state['photo_analysis_status'] ) : '';
		$summary     = ! empty( $analysis['photo_context_summary'] ) ? (string) $analysis['photo_context_summary'] : (string) ( $app_state['photo_context_summary'] ?? '' );
		$tasks       = ! empty( $analysis['visible_tasks_summary'] ) ? (string) $analysis['visible_tasks_summary'] : (string) ( $app_state['visible_tasks_summary'] ?? '' );
		$safety      = ! empty( $analysis['safety_summary'] ) ? (string) $analysis['safety_summary'] : (string) ( $app_state['safety_summary'] ?? '' );
		$notes       = ! empty( $analysis['visual_estimate_notes'] ) ? (string) $analysis['visual_estimate_notes'] : (string) ( $app_state['visual_estimate_notes'] ?? '' );
		$actionable  = ! empty( $analysis['has_actionable_visual_context'] ) || ! empty( $app_state['has_actionable_visual_context'] );
		$missing     = ! empty( $analysis['missing_visual_details'] ) && is_array( $analysis['missing_visual_details'] ) ? array_values( array_map( 'sanitize_text_field', $analysis['missing_visual_details'] ) ) : array();
		$signature   = ! empty( $analysis['photos_signature'] ) ? sanitize_text_field( (string) $analysis['photos_signature'] ) : '';

		if ( ! $status ) {
			if ( $has_photos && ! empty( $analysis ) ) {
				$status = 'ready';
			} elseif ( $has_photos ) {
				$status = 'unavailable';
			} else {
				$status = 'not_requested';
			}
		}

		if ( $has_photos && empty( $missing ) && ! $actionable ) {
			$missing[] = __( 'Uploaded photos are available, but the current visual context is still limited.', 'handik-booking-app' );
		}

		return array(
			'request_id'                    => (int) $request['id'],
			'has_photos'                    => $has_photos,
			'photo_count'                   => count( $photos ),
			'photo_analysis_status'         => $status,
			'has_actionable_visual_context' => $actionable,
			'photo_context_summary'         => sanitize_textarea_field( $summary ),
			'visible_tasks_summary'         => sanitize_text_field( $tasks ),
			'safety_summary'                => sanitize_textarea_field( $safety ),
			'visual_estimate_notes'         => sanitize_textarea_field( $notes ),
			'missing_visual_details'        => $missing,
			'photos_signature'              => $signature,
		);
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	protected function build_pricing_context_payload( array $request ) {
		$assistant = ! empty( $request['assistant_result'] ) && is_array( $request['assistant_result'] ) ? $request['assistant_result'] : array();
		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$tasks     = $this->selected_task_details( ! empty( $request['selected_tasks'] ) && is_array( $request['selected_tasks'] ) ? $request['selected_tasks'] : array() );

		$service_family = sanitize_key( (string) ( $assistant['service_family'] ?? $request['service_family'] ?? '' ) );
		$rate_family    = sanitize_key( (string) ( $assistant['rate_family'] ?? $request['rate_family'] ?? '' ) );
		if ( ! $service_family && ! empty( $tasks[0]['service_family'] ) ) {
			$service_family = sanitize_key( (string) $tasks[0]['service_family'] );
		}
		if ( ! $rate_family && ! empty( $tasks[0]['rate_family'] ) ) {
			$rate_family = sanitize_key( (string) $tasks[0]['rate_family'] );
		}

		$applied_rate = $this->applied_hourly_rate( $tasks, $rate_family );
		$duration     = $this->resolve_duration_range( $request, $assistant, $app_state );
		$materials    = $this->material_estimate_range( $service_family, $rate_family );

		$labor_low  = $applied_rate['rate'] > 0 ? $this->round_estimate_low( $applied_rate['rate'] * $duration['low'] ) : 0;
		$labor_high = $applied_rate['rate'] > 0 ? $this->round_estimate_high( $applied_rate['rate'] * $duration['high'] ) : 0;

		if ( 'project_custom' === $rate_family || 'consultation_first' === (string) ( $assistant['pricing_posture'] ?? $app_state['pricing_posture'] ?? '' ) ) {
			$materials['notes'] = 'This looks consultation-first. Materials and final work cost should be estimated after the initial assessment.';
		}

		return array(
			'request_id'                 => (int) $request['id'],
			'has_pricing_context'        => ! empty( $tasks ) || $applied_rate['rate'] > 0 || ! empty( $duration['source'] ),
			'selected_tasks'             => $tasks,
			'selected_task_count'        => count( $tasks ),
			'service_family'             => $service_family,
			'rate_family'                => $rate_family,
			'booking_type'               => sanitize_key( (string) ( $assistant['booking_type'] ?? $request['booking_type'] ?? '' ) ),
			'duration_bucket'            => sanitize_key( (string) ( $assistant['duration_bucket'] ?? $request['duration_bucket'] ?? '' ) ),
			'pricing_posture'            => sanitize_key( (string) ( $assistant['pricing_posture'] ?? $app_state['pricing_posture'] ?? '' ) ),
			'applied_hourly_rate'        => $applied_rate['rate'],
			'applied_rate_source'        => $applied_rate['source'],
			'suggested_duration_hours'   => $duration['suggested_duration_hours'],
			'duration_low_hours'         => $duration['low'],
			'duration_high_hours'        => $duration['high'],
			'labor_estimate_low'         => $labor_low,
			'labor_estimate_high'        => $labor_high,
			'materials_estimate_low'     => $materials['low'],
			'materials_estimate_high'    => $materials['high'],
			'total_estimate_low'         => $labor_low + $materials['low'],
			'total_estimate_high'        => $labor_high + $materials['high'],
			'materials_notes'            => sanitize_textarea_field( $materials['notes'] ),
			'estimate_disclaimer'        => __( 'This is a rough planning estimate. Final cost may change after on-site review, actual conditions, and materials needed.', 'handik-booking-app' ),
		);
	}

	/**
	 * @param array<int, mixed> $selected_tasks Selected task IDs.
	 * @return array<int, array<string, mixed>>
	 */
	protected function selected_task_details( array $selected_tasks ) {
		$details = array();
		$catalog = ( function_exists( 'handik_booking_app' ) && ! empty( handik_booking_app()->service_catalog ) ) ? handik_booking_app()->service_catalog : null;

		foreach ( $selected_tasks as $task_id ) {
			$task_id = sanitize_key( is_array( $task_id ) ? (string) ( $task_id['id'] ?? '' ) : (string) $task_id );
			if ( ! $task_id ) {
				continue;
			}

			$task = $catalog && method_exists( $catalog, 'find_task' ) ? $catalog->find_task( $task_id ) : null;
			if ( ! is_array( $task ) ) {
				$task = array(
					'id'             => $task_id,
					'label'          => ucwords( str_replace( '_', ' ', $task_id ) ),
					'description'    => '',
					'rate_label'     => '',
					'service_family' => '',
					'rate_family'    => '',
				);
			}

			$details[] = array(
				'id'             => sanitize_key( (string) ( $task['id'] ?? $task_id ) ),
				'label'          => sanitize_text_field( (string) ( $task['label'] ?? '' ) ),
				'description'    => sanitize_textarea_field( (string) ( $task['description'] ?? '' ) ),
				'rate_label'     => sanitize_text_field( (string) ( $task['rate_label'] ?? '' ) ),
				'hourly_rate'    => $this->parse_hourly_rate( (string) ( $task['rate_label'] ?? '' ) ),
				'service_family' => sanitize_key( (string) ( $task['service_family'] ?? '' ) ),
				'rate_family'    => sanitize_key( (string) ( $task['rate_family'] ?? '' ) ),
			);
		}

		return $details;
	}

	/**
	 * @param string $rate_label Rate label.
	 * @return float
	 */
	protected function parse_hourly_rate( $rate_label ) {
		if ( preg_match( '/([0-9]+(?:\.[0-9]+)?)/', (string) $rate_label, $matches ) ) {
			return max( 0, (float) $matches[1] );
		}
		return 0;
	}

	/**
	 * @param array<int, array<string, mixed>> $tasks Tasks.
	 * @param string                          $rate_family Rate family.
	 * @return array<string, mixed>
	 */
	protected function applied_hourly_rate( array $tasks, $rate_family ) {
		$rates = array_values(
			array_filter(
				array_map(
					function( $task ) {
						return ! empty( $task['hourly_rate'] ) ? (float) $task['hourly_rate'] : 0;
					},
					$tasks
				)
			)
		);

		if ( 1 === count( $rates ) ) {
			return array( 'rate' => $rates[0], 'source' => 'selected_task' );
		}
		if ( count( $rates ) > 1 ) {
			return array( 'rate' => max( $rates ), 'source' => 'highest_selected_task' );
		}

		$fallbacks = array(
			'assembly_basic'         => 60,
			'repair_standard'        => 70,
			'trade_general'          => 75,
			'installation_specialty' => 80,
			'structural_repair'      => 85,
			'premium_specialty'      => 95,
			'exterior_premium'       => 100,
			'precision_specialty'    => 110,
			'general_diagnostic'     => 80,
		);

		$rate_family = sanitize_key( (string) $rate_family );
		if ( isset( $fallbacks[ $rate_family ] ) ) {
			return array( 'rate' => (float) $fallbacks[ $rate_family ], 'source' => 'rate_family_fallback' );
		}

		return array( 'rate' => 0, 'source' => 'none' );
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $assistant Assistant result.
	 * @param array<string, mixed> $app_state App state.
	 * @return array<string, mixed>
	 */
	protected function resolve_duration_range( array $request, array $assistant, array $app_state ) {
		$suggested = sanitize_text_field( (string) ( $assistant['suggested_duration_hours'] ?? $app_state['suggested_duration_hours'] ?? '' ) );
		$bucket    = sanitize_key( (string) ( $assistant['duration_bucket'] ?? $request['duration_bucket'] ?? '' ) );
		$booking   = sanitize_key( (string) ( $assistant['booking_type'] ?? $request['booking_type'] ?? '' ) );

		if ( in_array( $suggested, array( '1', '2', '3', '4', '5', '6', '7', '8' ), true ) ) {
			$hours = (float) $suggested;
			return array( 'suggested_duration_hours' => $suggested, 'low' => $hours, 'high' => $hours, 'source' => 'suggested_duration_hours' );
		}
		if ( 'consult_1' === $suggested ) {
			return array( 'suggested_duration_hours' => $suggested, 'low' => 1.0, 'high' => 1.0, 'source' => 'suggested_duration_hours' );
		}

		$buckets = array(
			'1_2_hours'       => array( 1.0, 2.0, '1_2' ),
			'3_5_hours'       => array( 3.0, 5.0, '3_5' ),
			'6_8_hours'       => array( 6.0, 8.0, '6_8' ),
			'6_7_hours'       => array( 6.0, 7.0, '6_7' ),
			'project_consult' => array( 1.0, 1.0, 'consult_1' ),
		);

		if ( isset( $buckets[ $bucket ] ) ) {
			return array(
				'suggested_duration_hours' => $buckets[ $bucket ][2],
				'low'                      => $buckets[ $bucket ][0],
				'high'                     => $buckets[ $bucket ][1],
				'source'                   => 'duration_bucket',
			);
		}

		$booking_defaults = array(
			'standard_visit'       => array( 1.0, 2.0, '1_2' ),
			'extended_visit'       => array( 3.0, 5.0, '3_5' ),
			'large_visit'          => array( 6.0, 8.0, '6_8' ),
			'project_consultation' => array( 1.0, 1.0, 'consult_1' ),
		);

		if ( isset( $booking_defaults[ $booking ] ) ) {
			return array(
				'suggested_duration_hours' => $booking_defaults[ $booking ][2],
				'low'                      => $booking_defaults[ $booking ][0],
				'high'                     => $booking_defaults[ $booking ][1],
				'source'                   => 'booking_type',
			);
		}

		return array( 'suggested_duration_hours' => '', 'low' => 1.0, 'high' => 2.0, 'source' => '' );
	}

	/**
	 * @param string $service_family Service family.
	 * @param string $rate_family Rate family.
	 * @return array<string, mixed>
	 */
	protected function material_estimate_range( $service_family, $rate_family ) {
		$rate_family = sanitize_key( (string) $rate_family );
		$service_family = sanitize_key( (string) $service_family );
		$ranges = array(
			'assembly_basic'         => array( 0, 40, 'Usually low material cost unless anchors, brackets, or replacement hardware are needed.' ),
			'repair_standard'        => array( 10, 75, 'Common small repair materials may include fasteners, adhesive, patching supplies, or replacement parts.' ),
			'trade_general'          => array( 20, 120, 'Materials vary by fixture, connection parts, trim, sealant, or small replacement components.' ),
			'installation_specialty' => array( 30, 180, 'Materials may include hardware, trim, anchors, fasteners, connectors, or finishing supplies.' ),
			'premium_specialty'      => array( 50, 300, 'Specialty work can need more parts, fittings, access materials, or job-specific supplies.' ),
			'exterior_premium'       => array( 75, 450, 'Exterior repairs can vary widely depending on replacement sections, boards, hardware, and weatherproofing materials.' ),
			'general_diagnostic'     => array( 0, 100, 'Materials depend on what the mixed handyman task requires after review.' ),
			'project_custom'         => array( 0, 0, 'Consultation-first work needs a separate material estimate after scope review.' ),
		);
		$overrides = array(
			'plumbing_fixture_repair' => array( 25, 180, 'Possible materials include supply lines, shutoff valves, drains, sealant, cartridges, trim, or fixture-specific parts.' ),
			'plumbing_pipework'       => array( 75, 350, 'Pipework may need fittings, pipe, valves, supports, access materials, or specialty connection parts.' ),
			'electrical_smart_home'   => array( 25, 180, 'Materials may include boxes, plates, connectors, anchors, low-voltage parts, or device-specific hardware.' ),
			'lighting_fans'           => array( 25, 160, 'Materials may include mounting hardware, electrical connectors, brackets, boxes, or fixture-specific parts.' ),
			'concealed_cable_work'    => array( 50, 250, 'Hidden cable work may need cable, plates, low-voltage brackets, fishing tools, conduit, or wall repair supplies.' ),
			'wall_surface_repair'     => array( 20, 150, 'Materials may include drywall compound, mesh, tape, texture, primer, or patch panels.' ),
			'painting_refinishing'    => array( 30, 200, 'Materials may include paint, primer, tape, rollers, brushes, masking, and prep supplies.' ),
			'sealing_weatherproofing' => array( 15, 120, 'Materials may include caulk, sealant, backer rod, weatherstripping, or cleaning/prep supplies.' ),
			'flooring_tile_repair'    => array( 30, 220, 'Materials may include grout, thinset, trim, transitions, adhesive, or replacement pieces.' ),
			'door_work'               => array( 40, 250, 'Materials may include hinges, locksets, strike plates, trim, shims, fasteners, or weatherstripping.' ),
			'window_work'             => array( 50, 350, 'Materials may include balances, hardware, sealant, trim, blinds/shades hardware, or window-specific parts.' ),
			'finish_carpentry'        => array( 40, 250, 'Materials may include trim, molding, fasteners, adhesive, caulk, filler, and finishing supplies.' ),
			'built_ins_woodwork'      => array( 75, 400, 'Woodwork materials vary based on boards, panels, hardware, fasteners, finish, and reinforcement needs.' ),
			'deck_porch_repair'       => array( 60, 350, 'Materials may include boards, rails, fasteners, brackets, trim, stain, or exterior-rated hardware.' ),
			'siding_fence_repair'     => array( 75, 450, 'Materials may include replacement sections, boards, panels, fasteners, brackets, or exterior sealants.' ),
			'yard_landscaping'        => array( 0, 120, 'Materials may include bags, edging, mulch, soil, plants, stakes, or cleanup supplies if requested.' ),
			'concrete_cement'         => array( 75, 400, 'Materials may include cement mix, form boards, reinforcement, patching products, sealers, or finishing supplies.' ),
		);

		$selected = isset( $overrides[ $service_family ] ) ? $overrides[ $service_family ] : ( $ranges[ $rate_family ] ?? array( 0, 100, 'Materials depend on the exact scope and what is found on-site.' ) );
		return array(
			'low'   => $this->round_estimate_low( $selected[0] ),
			'high'  => $this->round_estimate_high( $selected[1] ),
			'notes' => $selected[2],
		);
	}

	/**
	 * @param float|int $value Value.
	 * @return int
	 */
	protected function round_estimate_low( $value ) {
		return (int) ( floor( max( 0, (float) $value ) / 5 ) * 5 );
	}

	/**
	 * @param float|int $value Value.
	 * @return int
	 */
	protected function round_estimate_high( $value ) {
		return (int) ( ceil( max( 0, (float) $value ) / 5 ) * 5 );
	}

	protected function sanitize_assistant_result( array $result ) {
		$allowed = array( 'standard_visit', 'extended_visit', 'large_visit', 'project_consultation' );
		$suggested_duration_allowed = array( '1', '2', '3', '4', '5', '6', '7', '8', 'consult_1' );
		$pricing_allowed            = array( 'hourly_only', 'hourly_plus_materials', 'consultation_first' );
		$sanitized = array(
			'service_family'           => sanitize_key( $result['service_family'] ?? '' ),
			'rate_family'              => sanitize_key( $result['rate_family'] ?? '' ),
			'duration_bucket'          => sanitize_key( $result['duration_bucket'] ?? '' ),
			'booking_type'             => ! empty( $result['booking_type'] ) && in_array( $result['booking_type'], $allowed, true ) ? $result['booking_type'] : '',
			'suggested_duration_hours' => ! empty( $result['suggested_duration_hours'] ) && in_array( (string) $result['suggested_duration_hours'], $suggested_duration_allowed, true ) ? (string) $result['suggested_duration_hours'] : '',
			'pricing_posture'          => ! empty( $result['pricing_posture'] ) && in_array( (string) $result['pricing_posture'], $pricing_allowed, true ) ? (string) $result['pricing_posture'] : '',
			'assistant_summary'        => sanitize_textarea_field( $result['assistant_summary'] ?? '' ),
			'estimate_notes'           => sanitize_textarea_field( $result['estimate_notes'] ?? '' ),
			'next_message'             => sanitize_textarea_field( $result['next_message'] ?? '' ),
			'enough_information'       => ! empty( $result['enough_information'] ),
			'unsafe'                   => ! empty( $result['unsafe'] ),
			'unsafe_reason'            => sanitize_textarea_field( $result['unsafe_reason'] ?? '' ),
			'is_project'               => ! empty( $result['is_project'] ),
		);

		foreach ( array( 'applied_hourly_rate', 'labor_estimate_low', 'labor_estimate_high', 'materials_estimate_low', 'materials_estimate_high', 'total_estimate_low', 'total_estimate_high' ) as $pricing_key ) {
			if ( array_key_exists( $pricing_key, $result ) ) {
				$sanitized[ $pricing_key ] = max( 0, (float) $result[ $pricing_key ] );
			}
		}

		foreach ( array( 'materials_notes', 'estimate_disclaimer' ) as $pricing_text_key ) {
			if ( array_key_exists( $pricing_text_key, $result ) ) {
				$sanitized[ $pricing_text_key ] = sanitize_textarea_field( $result[ $pricing_text_key ] );
			}
		}

		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $incoming Incoming assistant result.
	 * @param array<string, mixed> $photo_analysis Photo analysis.
	 * @return array<string, mixed>
	 */
	protected function inject_photo_analysis_into_result( array $incoming, array $photo_analysis ) {
		if ( empty( $photo_analysis['has_actionable_visual_context'] ) ) {
			return $incoming;
		}

		$summary = ! empty( $photo_analysis['visual_summary'] ) ? sanitize_textarea_field( (string) $photo_analysis['visual_summary'] ) : '';
		$notes   = ! empty( $photo_analysis['visual_estimate_notes'] ) ? sanitize_textarea_field( (string) $photo_analysis['visual_estimate_notes'] ) : '';

		if ( $summary && empty( $incoming['assistant_summary'] ) ) {
			$incoming['assistant_summary'] = $summary;
		}

		$photo_notes = array();
		if ( $summary ) {
			$photo_notes[] = 'Photo observations: ' . $summary;
		}
		if ( $notes ) {
			$photo_notes[] = $notes;
		}
		if ( ! empty( $photo_analysis['safety_observations'] ) && is_array( $photo_analysis['safety_observations'] ) ) {
			$photo_notes[] = 'Visible cautions: ' . implode( '; ', array_map( 'sanitize_text_field', $photo_analysis['safety_observations'] ) );
		}

		$photo_block = implode( "\n", array_filter( $photo_notes ) );
		if ( $photo_block ) {
			$current_notes = ! empty( $incoming['estimate_notes'] ) ? (string) $incoming['estimate_notes'] : '';
			if ( false === strpos( $current_notes, $photo_block ) ) {
				$incoming['estimate_notes'] = trim( $current_notes ? $current_notes . "\n\n" . $photo_block : $photo_block );
			}
		}

		return $incoming;
	}

	/**
	 * @param array<string, mixed> $stored Stored assistant result.
	 * @param array<string, mixed> $incoming Incoming assistant result.
	 * @return array<string, mixed>
	 */
	protected function merge_assistant_result( array $stored, array $incoming ) {
		$merged = $stored;

		foreach ( $incoming as $key => $value ) {
			if ( is_bool( $value ) ) {
				$merged[ $key ] = ! empty( $stored[ $key ] ) || $value;
				continue;
			}

			if ( '' !== (string) $value ) {
				$merged[ $key ] = $value;
				continue;
			}

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * @param array<string, mixed> $result Assistant payload.
	 * @return bool
	 */
	protected function has_complete_routing_payload( array $result ) {
		return ! empty( $result['booking_type'] ) && ! empty( $result['duration_bucket'] ) && ! empty( $result['suggested_duration_hours'] );
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @return bool
	 */
	protected function request_has_complete_routing( array $request ) {
		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		return ! empty( $request['booking_type'] ) && ! empty( $request['duration_bucket'] ) && ! empty( $app_state['suggested_duration_hours'] );
	}

	/**
	 * @param array<string, mixed> $assistant Assistant payload.
	 * @param array<string, mixed> $context Routing or request context.
	 * @param string               $booking_url Booking URL.
	 * @return bool
	 */
	protected function assistant_ready_for_booking( array $assistant, array $context = array(), $booking_url = '' ) {
		$has_booking_url = ! empty( $booking_url ) || ! empty( $context['cal_booking_url'] );
		$status_ok       = empty( $context['status'] ) || 'ready_for_booking' === (string) $context['status'];
		$routing_ok      = empty( $context['routing_status'] ) || 'complete' === (string) $context['routing_status'];

		return $this->has_complete_routing_payload( $assistant )
			&& ! empty( $assistant['enough_information'] )
			&& empty( $assistant['unsafe'] )
			&& empty( $context['unsafe_flag'] )
			&& $status_ok
			&& $routing_ok
			&& $has_booking_url;
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $assistant Assistant payload.
	 * @return array<string, mixed>
	 */
	protected function assistant_from_request( array $request, array $assistant ) {
		return $this->merge_assistant_result( $assistant, $this->routing_from_request( $request, $assistant ) );
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $assistant Assistant payload.
	 * @return array<string, mixed>
	 */
	protected function routing_from_request( array $request, array $assistant ) {
		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		return array(
			'service_family'           => sanitize_key( (string) ( $request['service_family'] ?? $assistant['service_family'] ?? '' ) ),
			'rate_family'              => sanitize_key( (string) ( $request['rate_family'] ?? $assistant['rate_family'] ?? '' ) ),
			'duration_bucket'          => sanitize_key( (string) ( $request['duration_bucket'] ?? $assistant['duration_bucket'] ?? '' ) ),
			'booking_type'             => sanitize_key( (string) ( $request['booking_type'] ?? $assistant['booking_type'] ?? '' ) ),
			'suggested_duration_hours' => sanitize_text_field( (string) ( $app_state['suggested_duration_hours'] ?? $assistant['suggested_duration_hours'] ?? '' ) ),
			'pricing_posture'          => sanitize_key( (string) ( $app_state['pricing_posture'] ?? $assistant['pricing_posture'] ?? '' ) ),
			'assistant_summary'        => sanitize_textarea_field( (string) ( $request['assistant_summary'] ?? $assistant['assistant_summary'] ?? '' ) ),
			'estimate_notes'           => sanitize_textarea_field( (string) ( $request['estimate_notes'] ?? $assistant['estimate_notes'] ?? '' ) ),
			'status'                   => sanitize_key( (string) ( $request['status'] ?? 'ready_for_booking' ) ),
			'routing_status'           => sanitize_key( (string) ( $request['routing_status'] ?? 'complete' ) ),
			'unsafe_flag'              => ! empty( $request['unsafe_flag'] ) ? 1 : 0,
			'unsafe_reason'            => sanitize_textarea_field( (string) ( $request['unsafe_reason'] ?? '' ) ),
		);
	}
}
