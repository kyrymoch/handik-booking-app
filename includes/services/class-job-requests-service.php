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
		$app_state = ! empty( $payload['app_state'] ) && is_array( $payload['app_state'] ) ? $payload['app_state'] : array();

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
			'app_step'            => ! empty( $payload['app_step'] ) ? sanitize_key( $payload['app_step'] ) : 'task_selection',
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
		$wpdb->update(
			$table,
			array(
				'service_family'        => sanitize_key( $routing['service_family'] ?? '' ),
				'rate_family'           => sanitize_key( $routing['rate_family'] ?? '' ),
				'duration_bucket'       => sanitize_key( $routing['duration_bucket'] ?? '' ),
				'booking_type'          => sanitize_key( $routing['booking_type'] ?? '' ),
				'assistant_summary'     => sanitize_textarea_field( $routing['assistant_summary'] ?? '' ),
				'estimate_notes'        => sanitize_textarea_field( $routing['estimate_notes'] ?? '' ),
				'status'                => sanitize_key( $routing['status'] ?? 'draft' ),
				'routing_status'        => sanitize_key( $routing['routing_status'] ?? 'pending' ),
				'unsafe_flag'           => ! empty( $routing['unsafe_flag'] ) ? 1 : 0,
				'unsafe_reason'         => sanitize_textarea_field( $routing['unsafe_reason'] ?? '' ),
				'assistant_result_json' => wp_json_encode( $assistant_result ),
			),
			array( 'id' => $request_id )
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
				'app_step'     => 'success',
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
		$row['selected_tasks']  = $this->decode_json( $row['selected_tasks_json'] );
		$row['photos']          = $this->decode_json( $row['photos_json'] );
		$row['intake_payload']  = $this->decode_json( $row['intake_payload_json'] );
		$row['assistant_result']= $this->decode_json( $row['assistant_result_json'] );
		$row['app_state']       = $this->decode_json( $row['app_state_json'] );
		return $row;
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
			'assistant_summary'   => $row['assistant_summary'],
			'chat_thread_id'      => $row['chat_thread_id'],
		);
	}

	/**
	 * @param string $json Json.
	 * @return array<int|string, mixed>
	 */
	protected function decode_json( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}
}
