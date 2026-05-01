<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Photo_Analysis_Service {
	const DEFAULT_MODEL = 'gpt-4.1-mini';

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
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 */
	public function __construct( $settings, $logger, $job_requests ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->job_requests = $job_requests;
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @param bool                 $force Force refresh.
	 * @return array<string, mixed>
	 */
	public function analyze_request( array $request, $force = false ) {
		$media = ! empty( $request['photos'] ) && is_array( $request['photos'] ) ? array_values( $request['photos'] ) : array();
		if ( empty( $media ) ) {
			return array();
		}
		$videos = array_values(
			array_filter(
				$media,
				function( $item ) {
					$type = ! empty( $item['media_type'] ) ? (string) $item['media_type'] : ( ! empty( $item['type'] ) ? (string) $item['type'] : '' );
					$mime = ! empty( $item['mime_type'] ) ? (string) $item['mime_type'] : '';
					return 'video' === $type || 0 === strpos( $mime, 'video/' );
				}
			)
		);
		$photos = array_values(
			array_filter(
				$media,
				function( $item ) {
					$type = ! empty( $item['media_type'] ) ? (string) $item['media_type'] : ( ! empty( $item['type'] ) ? (string) $item['type'] : '' );
					$mime = ! empty( $item['mime_type'] ) ? (string) $item['mime_type'] : '';
					return 'video' !== $type && 0 !== strpos( $mime, 'video/' );
				}
			)
		);
		if ( empty( $photos ) ) {
			$this->job_requests->update_app_state(
				(int) $request['id'],
				array(
					'photo_analysis_status'         => 'video_saved',
					'has_uploaded_videos'           => ! empty( $videos ),
					'uploaded_video_count'          => count( $videos ),
					'photo_context_summary'         => ! empty( $videos ) ? 'Customer uploaded video files. They are saved in the CRM for Alex to review.' : '',
					'visible_tasks_summary'         => '',
					'safety_summary'                => '',
					'visual_estimate_notes'         => ! empty( $videos ) ? 'Video files are available in the request, but automated video analysis is not enabled for this flow.' : '',
					'has_actionable_visual_context' => false,
				)
			);
			return array();
		}

		$signature = $this->photos_signature( $media );
		$cached    = $this->cached_analysis( $request );

		if ( ! $force && ! empty( $cached['photos_signature'] ) && $cached['photos_signature'] === $signature ) {
			$cached['source'] = 'cache';
			return $cached;
		}

		$api_key         = trim( (string) $this->settings->get( 'openai_api_key', '' ) );
		$api_base        = untrailingslashit( trim( (string) $this->settings->get( 'openai_api_base', 'https://api.openai.com' ) ) );
		$project_id      = trim( (string) $this->settings->get( 'openai_project_id', '' ) );
		$organization_id = trim( (string) $this->settings->get( 'openai_organization_id', '' ) );

		if ( ! $api_key ) {
			return array();
		}

		$this->job_requests->update_app_state(
			(int) $request['id'],
			array(
				'photo_analysis_status' => 'processing',
			)
		);

		$content = array(
			array(
				'type' => 'text',
				'text' => $this->analysis_prompt( $request, count( $photos ), count( $videos ) ),
			),
		);

		foreach ( array_slice( $photos, 0, 4 ) as $photo ) {
			$url = ! empty( $photo['url'] ) ? esc_url_raw( (string) $photo['url'] ) : '';
			if ( ! $url ) {
				continue;
			}
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url'    => $url,
					'detail' => 'low',
				),
			);
		}

		if ( 1 === count( $content ) ) {
			return array();
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);

		if ( $project_id ) {
			$headers['OpenAI-Project'] = $project_id;
		}
		if ( $organization_id ) {
			$headers['OpenAI-Organization'] = $organization_id;
		}

		$response = wp_remote_post(
			$api_base . '/v1/chat/completions',
			array(
				'headers' => $headers,
				'timeout' => 45,
				'body'    => wp_json_encode(
					array(
						'model'       => self::DEFAULT_MODEL,
						'temperature' => 0.2,
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => 'You analyze uploaded handyman booking photos for internal intake. Return only valid JSON with keys visual_summary, visual_estimate_notes, visible_tasks, safety_observations, missing_visual_details, has_actionable_visual_context.',
							),
							array(
								'role'    => 'user',
								'content' => $content,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->persist_failure_state( (int) $request['id'], $signature );
			$this->logger->error(
				'Photo analysis request failed.',
				array(
					'request_id' => (int) $request['id'],
					'message'    => $response->get_error_message(),
				)
			);
			return array();
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$payload = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$this->persist_failure_state( (int) $request['id'], $signature );
			$this->logger->error(
				'Photo analysis returned an unexpected response.',
				array(
					'request_id' => (int) $request['id'],
					'status'     => $status,
					'body'       => is_array( $payload ) ? $payload : $body,
				)
			);
			return array();
		}

		$text   = $this->extract_text_response( $payload );
		$parsed = $this->decode_analysis_json( $text );
		if ( empty( $parsed ) ) {
			$this->persist_failure_state( (int) $request['id'], $signature );
			$this->logger->error(
				'Photo analysis JSON could not be parsed.',
				array(
					'request_id' => (int) $request['id'],
					'body'       => $text,
				)
			);
			return array();
		}

		$analysis = $this->normalize_analysis(
			array(
				'photos_signature'              => $signature,
				'generated_at'                  => current_time( 'mysql' ),
				'visual_summary'                => sanitize_textarea_field( $parsed['visual_summary'] ?? '' ),
				'visual_estimate_notes'         => sanitize_textarea_field( $parsed['visual_estimate_notes'] ?? '' ),
				'visible_tasks'                 => array_values( array_map( 'sanitize_text_field', is_array( $parsed['visible_tasks'] ?? null ) ? $parsed['visible_tasks'] : array() ) ),
				'safety_observations'           => array_values( array_map( 'sanitize_text_field', is_array( $parsed['safety_observations'] ?? null ) ? $parsed['safety_observations'] : array() ) ),
				'missing_visual_details'        => array_values( array_map( 'sanitize_text_field', is_array( $parsed['missing_visual_details'] ?? null ) ? $parsed['missing_visual_details'] : array() ) ),
				'has_actionable_visual_context' => ! empty( $parsed['has_actionable_visual_context'] ),
				'source'                        => 'generated',
			)
		);

		$this->job_requests->update_app_state(
			(int) $request['id'],
			array(
				'photo_analysis'                => $analysis,
				'photo_analysis_status'         => 'ready',
				'photo_analysis_summary'        => $analysis['visual_summary'],
				'has_actionable_visual_context' => ! empty( $analysis['has_actionable_visual_context'] ),
				'photo_analysis_updated_at'     => current_time( 'mysql' ),
				'photo_context_summary'         => $analysis['photo_context_summary'],
				'visible_tasks_summary'         => $analysis['visible_tasks_summary'],
				'safety_summary'                => $analysis['safety_summary'],
				'visual_estimate_notes'         => $analysis['visual_estimate_notes'],
			)
		);

		$this->logger->info(
			'Photo analysis generated.',
			array(
				'request_id'     => (int) $request['id'],
				'photo_count'    => count( $photos ),
				'video_count'    => count( $videos ),
				'visible_tasks'  => $analysis['visible_tasks'],
				'actionable'     => $analysis['has_actionable_visual_context'],
			)
		);

		return $analysis;
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	public function cached_analysis( array $request ) {
		if ( empty( $request['app_state']['photo_analysis'] ) || ! is_array( $request['app_state']['photo_analysis'] ) ) {
			return array();
		}

		return $this->normalize_analysis( $request['app_state']['photo_analysis'] );
	}

	/**
	 * @param string $text Raw response text.
	 * @return array<string, mixed>
	 */
	protected function decode_analysis_json( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$text = preg_replace( '/^```(?:json)?|```$/m', '', $text );
		$text = trim( (string) $text );

		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			return is_array( $data ) ? $data : array();
		}

		return array();
	}

	/**
	 * @param array<string, mixed> $payload API payload.
	 * @return string
	 */
	protected function extract_text_response( array $payload ) {
		if ( ! empty( $payload['choices'][0]['message']['content'] ) && is_string( $payload['choices'][0]['message']['content'] ) ) {
			return $payload['choices'][0]['message']['content'];
		}

		if ( ! empty( $payload['choices'][0]['message']['content'] ) && is_array( $payload['choices'][0]['message']['content'] ) ) {
			$parts = array();
			foreach ( $payload['choices'][0]['message']['content'] as $item ) {
				if ( ! empty( $item['text'] ) ) {
					$parts[] = (string) $item['text'];
				}
			}
			return implode( "\n", $parts );
		}

		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $photos Photos.
	 * @return string
	 */
	protected function photos_signature( array $photos ) {
		$parts = array();
		foreach ( $photos as $photo ) {
			$parts[] = implode(
				':',
				array(
					! empty( $photo['attachment_id'] ) ? (int) $photo['attachment_id'] : 0,
					! empty( $photo['name'] ) ? (string) $photo['name'] : '',
					! empty( $photo['url'] ) ? (string) $photo['url'] : '',
				)
			);
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @param int                  $photo_count Photo count.
	 * @param int                  $video_count Video count.
	 * @return string
	 */
	protected function analysis_prompt( array $request, $photo_count, $video_count = 0 ) {
		$tasks = ! empty( $request['selected_tasks'] ) && is_array( $request['selected_tasks'] ) ? implode( ', ', $request['selected_tasks'] ) : 'Not provided';

		return implode(
			"\n",
			array(
				'Review these uploaded handyman-job photos for internal booking intake.',
				'Known request context:',
				'- Client type: ' . ( ! empty( $request['client_type'] ) ? $request['client_type'] : 'unknown' ),
				'- Selected tasks: ' . $tasks,
				'- Project flag: ' . ( ! empty( $request['is_project'] ) ? 'yes' : 'no' ),
				'- Address: ' . ( ! empty( $request['address_full'] ) ? $request['address_full'] : 'not provided' ),
				'- Number of uploaded photos: ' . (int) $photo_count,
				'- Number of uploaded videos saved for CRM review but not directly analyzed here: ' . (int) $video_count,
				'Return only JSON with these keys:',
				'- visual_summary: concise summary of what is visibly relevant for the handyman visit',
				'- visual_estimate_notes: useful estimate/setup notes based only on visible evidence',
				'- visible_tasks: array of short task labels suggested by the images',
				'- safety_observations: array of visible concerns or cautions, or an empty array',
				'- missing_visual_details: array of details that still cannot be confirmed visually',
				'- has_actionable_visual_context: boolean',
				'Important:',
				'- mention only what is actually visible or reasonably inferable',
				'- if uncertain, say so briefly in the summary or notes',
				'- do not mention JSON formatting or markdown fences',
			)
		);
	}

	/**
	 * @param array<string, mixed> $analysis Raw analysis.
	 * @return array<string, mixed>
	 */
	protected function normalize_analysis( array $analysis ) {
		$visible_tasks = array_values( array_map( 'sanitize_text_field', is_array( $analysis['visible_tasks'] ?? null ) ? $analysis['visible_tasks'] : array() ) );
		$safety = array_values( array_map( 'sanitize_text_field', is_array( $analysis['safety_observations'] ?? null ) ? $analysis['safety_observations'] : array() ) );
		$missing = array_values( array_map( 'sanitize_text_field', is_array( $analysis['missing_visual_details'] ?? null ) ? $analysis['missing_visual_details'] : array() ) );
		$summary = sanitize_textarea_field( (string) ( $analysis['visual_summary'] ?? '' ) );
		$estimate_notes = sanitize_textarea_field( (string) ( $analysis['visual_estimate_notes'] ?? '' ) );

		return array(
			'photos_signature'              => sanitize_text_field( (string) ( $analysis['photos_signature'] ?? '' ) ),
			'generated_at'                  => sanitize_text_field( (string) ( $analysis['generated_at'] ?? '' ) ),
			'visual_summary'                => $summary,
			'visual_estimate_notes'         => $estimate_notes,
			'visible_tasks'                 => $visible_tasks,
			'safety_observations'           => $safety,
			'missing_visual_details'        => $missing,
			'has_actionable_visual_context' => ! empty( $analysis['has_actionable_visual_context'] ),
			'photo_context_summary'         => $summary,
			'visible_tasks_summary'         => implode( ', ', $visible_tasks ),
			'safety_summary'                => implode( '; ', $safety ),
			'source'                        => sanitize_text_field( (string) ( $analysis['source'] ?? '' ) ),
		);
	}

	/**
	 * @param int $request_id Request ID.
	 * @return void
	 */
	protected function persist_failure_state( $request_id, $signature = '' ) {
		$analysis = $this->normalize_analysis(
			array(
				'photos_signature'              => sanitize_text_field( (string) $signature ),
				'generated_at'                  => current_time( 'mysql' ),
				'visual_summary'                => '',
				'visual_estimate_notes'         => '',
				'visible_tasks'                 => array(),
				'safety_observations'           => array(),
				'missing_visual_details'        => array( 'Automatic image analysis was unavailable.' ),
				'has_actionable_visual_context' => false,
				'source'                        => 'failed',
			)
		);

		$this->job_requests->update_app_state(
			$request_id,
			array(
				'photo_analysis'                => $analysis,
				'photo_analysis_status'         => 'failed',
				'photo_analysis_summary'        => $analysis['visual_summary'],
				'photo_analysis_updated_at'     => current_time( 'mysql' ),
				'photo_context_summary'         => 'Photo analysis unavailable at the moment.',
				'visible_tasks_summary'         => '',
				'safety_summary'                => '',
				'visual_estimate_notes'         => '',
				'has_actionable_visual_context' => false,
			)
		);
	}
}
