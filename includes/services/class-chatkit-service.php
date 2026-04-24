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

		$photo_analysis = $this->photo_analysis->analyze_request( $request, false );
		$assistant      = $this->merge_assistant_result(
			$this->sanitize_assistant_result( is_array( $request['assistant_result'] ?? null ) ? $request['assistant_result'] : array() ),
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
				'status'              => $routing['status'] ?? '',
				'routing_status'      => $routing['routing_status'] ?? '',
				'used_stored_result'  => ! empty( $request['assistant_result'] ),
			)
		);

		$booking_url = '';
		if ( 'ready_for_booking' === $routing['status'] && ! empty( $routing['booking_type'] ) ) {
			$booking_url = $this->cal->build_booking_url( $request_id );
		}

		return array(
			'success'       => true,
			'assistant_result' => $assistant,
			'routing'       => $routing,
			'booking_url'   => $booking_url,
			'photo_analysis'=> $photo_analysis,
			'unsafe_flag'   => ! empty( $routing['unsafe_flag'] ),
			'unsafe_reason' => $routing['unsafe_reason'],
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

	protected function sanitize_assistant_result( array $result ) {
		$allowed = array( 'standard_visit', 'extended_visit', 'large_visit', 'project_consultation' );
		$suggested_duration_allowed = array( '1', '2', '3', '4', '5', '6', '7', '8', 'consult_1' );
		$pricing_allowed            = array( 'hourly_only', 'hourly_plus_materials', 'consultation_first' );
		return array(
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
}
