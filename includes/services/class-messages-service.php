<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persisted chat transcript between the customer and the assistant.
 * Backed by the {prefix}handik_messages table created in migration 1.3.0.
 */
class Handik_Booking_App_Messages_Service {
	const ROLE_USER      = 'user';
	const ROLE_ASSISTANT = 'assistant';
	const ROLE_SYSTEM    = 'system';
	const ROLE_TOOL      = 'tool';

	const ALLOWED_ROLES = array( 'user', 'assistant', 'system', 'tool' );

	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $thread_id Thread (optional).
	 * @param string $role one of ALLOWED_ROLES.
	 * @param string $content Plain text body.
	 * @param array  $metadata Free-form metadata (tokens, model, latency_ms, tool_name).
	 * @return int Inserted row id, or 0 on validation failure.
	 */
	public function record( $request_id, $thread_id, $role, $content, array $metadata = array() ) {
		$request_id = (int) $request_id;
		$role       = sanitize_key( $role );
		$content    = trim( (string) $content );

		if ( $request_id <= 0 || '' === $content ) {
			return 0;
		}
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			$role = self::ROLE_USER;
		}

		// De-dupe: if the same content / role was recorded for this request in
		// the last 10 seconds (typical bridge double-fire), skip the insert.
		if ( $this->find_recent_duplicate( $request_id, $role, $content ) ) {
			return 0;
		}

		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'messages' );
		$wpdb->insert(
			$table,
			array(
				'request_id' => $request_id,
				'thread_id'  => $thread_id ? sanitize_text_field( (string) $thread_id ) : null,
				'role'       => $role,
				'content'    => $this->trim_content( $content ),
				'metadata'   => $metadata ? wp_json_encode( $this->sanitize_metadata( $metadata ) ) : null,
				'created_at' => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_request( $request_id, $limit = 200 ) {
		global $wpdb;
		$request_id = (int) $request_id;
		if ( $request_id <= 0 ) {
			return array();
		}
		$table = Handik_Booking_App_DB::table( 'messages' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY created_at ASC, id ASC LIMIT %d", $request_id, max( 1, (int) $limit ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_thread( $thread_id, $limit = 200 ) {
		global $wpdb;
		$thread_id = trim( (string) $thread_id );
		if ( '' === $thread_id ) {
			return array();
		}
		$table = Handik_Booking_App_DB::table( 'messages' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE thread_id = %s ORDER BY created_at ASC, id ASC LIMIT %d", $thread_id, max( 1, (int) $limit ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/**
	 * @return int
	 */
	public function count_for_request( $request_id ) {
		global $wpdb;
		$request_id = (int) $request_id;
		if ( $request_id <= 0 ) {
			return 0;
		}
		$table = Handik_Booking_App_DB::table( 'messages' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE request_id = %d", $request_id ) );
	}

	/**
	 * @return int|null
	 */
	protected function find_recent_duplicate( $request_id, $role, $content ) {
		global $wpdb;
		$table   = Handik_Booking_App_DB::table( 'messages' );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 10 );
		$content = $this->trim_content( $content );
		$row     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE request_id = %d AND role = %s AND content = %s AND created_at >= %s LIMIT 1",
				$request_id,
				$role,
				$content,
				$cutoff
			)
		);
		return $row ? (int) $row : null;
	}

	protected function trim_content( $content ) {
		// Hard cap a single message at 16 KB so a runaway bridge can't blow up
		// the table.
		if ( strlen( $content ) > 16384 ) {
			return substr( $content, 0, 16384 ) . '…';
		}
		return $content;
	}

	/**
	 * @param array<string, mixed> $metadata Metadata.
	 * @return array<string, mixed>
	 */
	protected function sanitize_metadata( array $metadata ) {
		$out = array();
		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || is_null( $value ) ) {
				$out[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $this->sanitize_metadata( $value );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	protected function hydrate( array $row ) {
		if ( ! empty( $row['metadata'] ) && is_string( $row['metadata'] ) ) {
			$decoded = json_decode( $row['metadata'], true );
			$row['metadata'] = is_array( $decoded ) ? $decoded : array();
		} else {
			$row['metadata'] = array();
		}
		return $row;
	}
}
