<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Job_Requests_Service {
	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @param Handik_Booking_App_Logger $logger Logger.
	 */
	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $contact_id Contact ID.
	 * @param int                  $address_id Address ID.
	 * @param int                  $request_id Request ID.
	 * @return array<string, mixed>
	 */
	public function save_draft( array $payload, $contact_id = 0, $address_id = 0, $request_id = 0 ) {
		global $wpdb;

		$table     = Handik_Booking_App_DB::table( 'job_requests' );
		$existing  = $request_id ? $this->get( $request_id ) : null;
		$existing_app_state = ( $existing && ! empty( $existing['app_state'] ) && is_array( $existing['app_state'] ) ) ? $existing['app_state'] : array();
		$incoming_app_state = ! empty( $payload['app_state'] ) && is_array( $payload['app_state'] ) ? $payload['app_state'] : array();
		$app_state          = array_replace_recursive( $existing_app_state, $incoming_app_state );

		$data = array(
			'contact_id'          => $contact_id ? $contact_id : ( $existing ? (int) $existing['contact_id'] : null ),
			'client_type'         => ! empty( $payload['client_type'] ) ? sanitize_key( $payload['client_type'] ) : '',
			'job_shape'           => ! empty( $payload['job_shape'] ) ? sanitize_key( $payload['job_shape'] ) : '',
			'request_source'      => ! empty( $payload['request_source'] ) ? sanitize_key( $payload['request_source'] ) : 'booking_app',
			'selected_tasks_json' => wp_json_encode( array_values( $payload['selected_tasks'] ?? array() ) ),
			'is_project'          => ! empty( $payload['is_project'] ) ? 1 : 0,
			'address_id'          => $address_id ? $address_id : ( $existing ? (int) $existing['address_id'] : null ),
			'address_full'        => ! empty( $payload['address_full'] ) ? sanitize_textarea_field( $payload['address_full'] ) : '',
			'address_unit'        => ! empty( $payload['address_unit'] ) ? sanitize_text_field( $payload['address_unit'] ) : '',
			'short_description'   => ! empty( $payload['short_description'] ) ? sanitize_textarea_field( $payload['short_description'] ) : '',
			'photos_json'         => wp_json_encode( $payload['photos'] ?? array() ),
			'preferred_timeframe' => ! empty( $payload['preferred_timeframe'] ) ? sanitize_text_field( $payload['preferred_timeframe'] ) : '',
			'assistant_summary'   => ! empty( $payload['assistant_summary'] ) ? sanitize_textarea_field( $payload['assistant_summary'] ) : '',
			'estimate_notes'      => ! empty( $payload['estimate_notes'] ) ? sanitize_textarea_field( $payload['estimate_notes'] ) : '',
				'status'              => ! empty( $payload['status'] ) ? sanitize_key( $payload['status'] ) : 'draft',
				'app_step'            => ! empty( $payload['app_step'] ) ? sanitize_key( $payload['app_step'] ) : 'address_details',
			'app_session_key'     => ! empty( $payload['app_session_key'] ) ? sanitize_text_field( $payload['app_session_key'] ) : null,
			'app_state_json'      => wp_json_encode( $app_state ),
			'intake_payload_json' => wp_json_encode( $payload ),
			'lookup_verified_at'  => ! empty( $payload['lookup_verified'] ) ? current_time( 'mysql' ) : null,
		);

		if ( $existing ) {
			unset( $data['request_source'] );
			$wpdb->update( $table, $data, array( 'id' => $request_id ) );
			$draft_token = '';
			$request_id  = (int) $existing['id'];
		} else {
			$draft_token             = wp_generate_password( 32, false, false );
			$data['draft_token_hash'] = wp_hash_password( $draft_token );
			$wpdb->insert( $table, $data );
			$request_id = (int) $wpdb->insert_id;
		}

		return array(
			'request'     => $this->get( $request_id ),
			'draft_token' => $draft_token,
		);
	}

	/**
	 * @param int                  $request_id Request ID.
	 * @param array<string, mixed> $routing Routing.
	 * @param array<string, mixed> $assistant_result Assistant result.
	 */
	public function apply_routing( $request_id, array $routing, array $assistant_result = array() ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$request = $this->get( $request_id );
		$app_state = ( $request && ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ) ? $request['app_state'] : array();
		$existing_assistant = ( $request && ! empty( $request['assistant_result'] ) && is_array( $request['assistant_result'] ) ) ? $request['assistant_result'] : array();
		$assistant_result   = $this->merge_non_empty( $existing_assistant, $assistant_result );
		$app_state['suggested_duration_hours'] = sanitize_text_field( (string) ( $routing['suggested_duration_hours'] ?? $assistant_result['suggested_duration_hours'] ?? $app_state['suggested_duration_hours'] ?? '' ) );
		$app_state['pricing_posture']          = sanitize_key( (string) ( $routing['pricing_posture'] ?? $assistant_result['pricing_posture'] ?? $app_state['pricing_posture'] ?? '' ) );
		foreach ( array( 'applied_hourly_rate', 'labor_estimate_low', 'labor_estimate_high', 'materials_estimate_low', 'materials_estimate_high', 'total_estimate_low', 'total_estimate_high' ) as $pricing_key ) {
			$app_state[ $pricing_key ] = max( 0, (float) ( $assistant_result[ $pricing_key ] ?? $app_state[ $pricing_key ] ?? 0 ) );
		}
		$app_state['materials_notes']     = sanitize_textarea_field( (string) ( $assistant_result['materials_notes'] ?? $app_state['materials_notes'] ?? '' ) );
		$app_state['estimate_disclaimer'] = sanitize_textarea_field( (string) ( $assistant_result['estimate_disclaimer'] ?? $app_state['estimate_disclaimer'] ?? '' ) );
		$updated = $wpdb->update(
			$table,
			array(
				'service_family'        => sanitize_key( $routing['service_family'] ?? $request['service_family'] ?? '' ),
				'rate_family'           => sanitize_key( $routing['rate_family'] ?? $request['rate_family'] ?? '' ),
				'duration_bucket'       => sanitize_key( $routing['duration_bucket'] ?? $request['duration_bucket'] ?? '' ),
				'booking_type'          => sanitize_key( $routing['booking_type'] ?? $request['booking_type'] ?? '' ),
				'assistant_summary'     => sanitize_textarea_field( $routing['assistant_summary'] ?? $request['assistant_summary'] ?? '' ),
				'estimate_notes'        => sanitize_textarea_field( $routing['estimate_notes'] ?? $request['estimate_notes'] ?? '' ),
				'status'                => sanitize_key( $routing['status'] ?? $request['status'] ?? 'draft' ),
				'routing_status'        => sanitize_key( $routing['routing_status'] ?? $request['routing_status'] ?? 'pending' ),
				'unsafe_flag'           => ! empty( $routing['unsafe_flag'] ) ? 1 : 0,
				'unsafe_reason'         => sanitize_textarea_field( $routing['unsafe_reason'] ?? '' ),
				'app_state_json'        => wp_json_encode( $app_state ),
				'assistant_result_json' => wp_json_encode( $assistant_result ),
			),
			array( 'id' => $request_id )
		);

		$this->logger->info(
			'Assistant routing persisted.',
			array(
				'request_id'               => $request_id,
				'updated'                  => false !== $updated,
				'booking_type'             => sanitize_key( $routing['booking_type'] ?? '' ),
				'duration_bucket'          => sanitize_key( $routing['duration_bucket'] ?? '' ),
				'suggested_duration_hours' => sanitize_text_field( (string) ( $app_state['suggested_duration_hours'] ?? '' ) ),
				'pricing_posture'          => sanitize_key( (string) ( $app_state['pricing_posture'] ?? '' ) ),
				'applied_hourly_rate'      => $app_state['applied_hourly_rate'] ?? 0,
				'total_estimate_low'       => $app_state['total_estimate_low'] ?? 0,
				'total_estimate_high'      => $app_state['total_estimate_high'] ?? 0,
			)
		);
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $thread_id Thread.
	 */
	public function set_thread( $request_id, $thread_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$wpdb->update( $table, array( 'chat_thread_id' => sanitize_text_field( $thread_id ) ), array( 'id' => $request_id ) );
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $session_id Session.
	 * @param string $user_id User.
	 */
	public function set_chat_session( $request_id, $session_id, $user_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$wpdb->update(
			$table,
			array(
				'chat_session_id' => sanitize_text_field( $session_id ),
				'chat_user_id'    => sanitize_text_field( $user_id ),
			),
			array( 'id' => $request_id )
		);
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $url URL.
	 * @param string $booking_type Booking type.
	 */
	public function set_booking_url( $request_id, $url, $booking_type ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$wpdb->update(
			$table,
			array(
				'cal_booking_url' => esc_url_raw( $url ),
				'booking_type'    => sanitize_key( $booking_type ),
			),
			array( 'id' => $request_id )
		);
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $status Status.
	 */
	public function mark_complete( $request_id, $status = 'booking_started' ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$wpdb->update(
			$table,
			array(
				'status'       => sanitize_key( $status ),
				'app_step'     => 'booking',
				'completed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $request_id )
		);
	}

	/**
	 * @param int $request_id Request.
	 */
	public function mark_booking_pending( $request_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$wpdb->update(
			$table,
			array(
				'status'   => 'booking_pending',
				'app_step' => 'booking',
			),
			array( 'id' => $request_id )
		);
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $booking_id Booking ID.
	 * @param string $status Status.
	 */
	public function set_cal_booking( $request_id, $booking_id, $status ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$wpdb->update(
			$table,
			array(
				'cal_booking_id' => sanitize_text_field( $booking_id ),
				'status'         => sanitize_key( $status ),
			),
			array( 'id' => $request_id )
		);
	}

	/**
	 * @param int $request_id ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $request_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $request_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return $this->hydrate_row( $row );
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Cheap COUNT for dashboard widgets — avoids fetching full rows just to count them.
	 *
	 * @return int
	 */
	public function count_all() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * @param int $contact_id Contact ID.
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent_for_contact( $contact_id, $limit = 20 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	/**
	 * @param string $booking_id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_cal_booking_id( $booking_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", sanitize_text_field( $booking_id ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @return array<string, mixed>|null
	 */
	public function find_latest_pending_by_contact( $email = '', $phone = '' ) {
		global $wpdb;

		$requests_table = Handik_Booking_App_DB::table( 'job_requests' );
		$contacts_table = Handik_Booking_App_DB::table( 'contacts' );
		$email          = sanitize_email( $email );
		$phone          = sanitize_text_field( $phone );

		if ( $email ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT jr.* FROM {$requests_table} jr INNER JOIN {$contacts_table} c ON c.id = jr.contact_id WHERE c.email = %s AND jr.status IN ('booking_pending', 'ready_for_booking', 'draft') ORDER BY jr.updated_at DESC, jr.id DESC LIMIT 1",
					$email
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
		}

		if ( $phone ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT jr.* FROM {$requests_table} jr INNER JOIN {$contacts_table} c ON c.id = jr.contact_id WHERE c.phone = %s AND jr.status IN ('booking_pending', 'ready_for_booking', 'draft') ORDER BY jr.updated_at DESC, jr.id DESC LIMIT 1",
					$phone
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return bool
	 */
	public function verify_draft_token( $request_id, $draft_token ) {
		$row = $this->get( $request_id );
		return $row && ! empty( $row['draft_token_hash'] ) && ! empty( $draft_token ) && wp_check_password( $draft_token, $row['draft_token_hash'] );
	}

	/**
	 * @param int $request_id ID.
	 * @return array<string, mixed>
	 */
	public function build_context( $request_id ) {
		$row = $this->get( $request_id );
		if ( ! $row ) {
			return array();
		}
		return array(
			'request_id'          => (int) $row['id'],
			'client_type'         => $row['client_type'],
			'job_shape'           => $row['job_shape'],
			'selected_tasks'      => $row['selected_tasks'],
			'is_project'          => ! empty( $row['is_project'] ),
			'address_full'        => $row['address_full'],
			'address_unit'        => $row['address_unit'],
			'preferred_timeframe' => $row['preferred_timeframe'],
			'short_description'   => $row['short_description'],
			'photos'              => $row['photos'],
			'photo_analysis'      => ! empty( $row['app_state']['photo_analysis'] ) && is_array( $row['app_state']['photo_analysis'] ) ? $row['app_state']['photo_analysis'] : array(),
			'photo_analysis_status' => ! empty( $row['app_state']['photo_analysis_status'] ) ? (string) $row['app_state']['photo_analysis_status'] : '',
			'photo_context_summary' => ! empty( $row['app_state']['photo_context_summary'] ) ? (string) $row['app_state']['photo_context_summary'] : '',
			'visible_tasks_summary' => ! empty( $row['app_state']['visible_tasks_summary'] ) ? (string) $row['app_state']['visible_tasks_summary'] : '',
			'safety_summary'      => ! empty( $row['app_state']['safety_summary'] ) ? (string) $row['app_state']['safety_summary'] : '',
			'visual_estimate_notes' => ! empty( $row['app_state']['visual_estimate_notes'] ) ? (string) $row['app_state']['visual_estimate_notes'] : '',
			'suggested_duration_hours' => ! empty( $row['app_state']['suggested_duration_hours'] ) ? (string) $row['app_state']['suggested_duration_hours'] : '',
			'pricing_posture'     => ! empty( $row['app_state']['pricing_posture'] ) ? (string) $row['app_state']['pricing_posture'] : '',
			'applied_hourly_rate' => ! empty( $row['app_state']['applied_hourly_rate'] ) ? (float) $row['app_state']['applied_hourly_rate'] : 0,
			'labor_estimate_low'  => ! empty( $row['app_state']['labor_estimate_low'] ) ? (float) $row['app_state']['labor_estimate_low'] : 0,
			'labor_estimate_high' => ! empty( $row['app_state']['labor_estimate_high'] ) ? (float) $row['app_state']['labor_estimate_high'] : 0,
			'materials_estimate_low' => ! empty( $row['app_state']['materials_estimate_low'] ) ? (float) $row['app_state']['materials_estimate_low'] : 0,
			'materials_estimate_high' => ! empty( $row['app_state']['materials_estimate_high'] ) ? (float) $row['app_state']['materials_estimate_high'] : 0,
			'total_estimate_low'  => ! empty( $row['app_state']['total_estimate_low'] ) ? (float) $row['app_state']['total_estimate_low'] : 0,
			'total_estimate_high' => ! empty( $row['app_state']['total_estimate_high'] ) ? (float) $row['app_state']['total_estimate_high'] : 0,
			'materials_notes'     => ! empty( $row['app_state']['materials_notes'] ) ? (string) $row['app_state']['materials_notes'] : '',
			'estimate_disclaimer' => ! empty( $row['app_state']['estimate_disclaimer'] ) ? (string) $row['app_state']['estimate_disclaimer'] : '',
			'assistant_summary'   => $row['assistant_summary'],
			'chat_thread_id'      => $row['chat_thread_id'],
		);
	}

	/**
	 * @param int                  $request_id Request ID.
	 * @param array<string, mixed> $patch Patch.
	 * @return array<string, mixed>|null
	 */
	public function update_app_state( $request_id, array $patch ) {
		global $wpdb;

		$request = $this->get( $request_id );
		if ( ! $request ) {
			return null;
		}

		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$app_state = array_replace_recursive( $app_state, $patch );

		$wpdb->update(
			Handik_Booking_App_DB::table( 'job_requests' ),
			array(
				'app_state_json' => wp_json_encode( $app_state ),
			),
			array( 'id' => $request_id )
		);

		return $this->get( $request_id );
	}

	/**
	 * @param array<string, mixed> $base Base.
	 * @param array<string, mixed> $incoming Incoming.
	 * @return array<string, mixed>
	 */
	protected function merge_non_empty( array $base, array $incoming ) {
		$merged = $base;
		foreach ( $incoming as $key => $value ) {
			if ( is_bool( $value ) ) {
				$merged[ $key ] = ! empty( $merged[ $key ] ) || $value;
				continue;
			}
			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					$merged[ $key ] = $value;
				}
				continue;
			}
			if ( '' !== trim( (string) $value ) ) {
				$merged[ $key ] = $value;
			}
		}
		return $merged;
	}

	/**
	 * @param string $json Json.
	 * @return array<int|string, mixed>
	 */
	protected function decode_json( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	protected function hydrate_row( array $row ) {
		$row['selected_tasks']   = $this->decode_json( $row['selected_tasks_json'] ?? '' );
		$row['photos']           = $this->decode_json( $row['photos_json'] ?? '' );
		$row['intake_payload']   = $this->decode_json( $row['intake_payload_json'] ?? '' );
		$row['assistant_result'] = $this->decode_json( $row['assistant_result_json'] ?? '' );
		$row['app_state']        = $this->decode_json( $row['app_state_json'] ?? '' );
		return $row;
	}
}
