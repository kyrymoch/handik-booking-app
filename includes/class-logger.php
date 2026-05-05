<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Logger {
	const OPTION_NAME       = 'handik_booking_app_logs';
	const DEFAULT_INFO_CAP  = 2000;
	const DEFAULT_DEBUG_CAP = 500;

	/**
	 * Logger levels in priority order (higher number = more severe).
	 *
	 * @var array<string, int>
	 */
	const LEVELS = array(
		'debug'    => 10,
		'info'     => 20,
		'notice'   => 30,
		'warning'  => 40,
		'error'    => 50,
		'critical' => 60,
	);

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @param Handik_Booking_App_Settings $settings Settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function debug( $message, array $context = array() ) {
		if ( ! $this->settings->is_debug() ) {
			return;
		}
		$this->write( 'debug', $message, $context );
	}

	public function info( $message, array $context = array() ) {
		$this->write( 'info', $message, $context );
	}

	public function notice( $message, array $context = array() ) {
		$this->write( 'notice', $message, $context );
	}

	public function warning( $message, array $context = array() ) {
		$this->write( 'warning', $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	public function critical( $message, array $context = array() ) {
		$this->write( 'critical', $message, $context );
	}

	/**
	 * Newest entry first (matches admin display order).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs() {
		$logs = get_option( self::OPTION_NAME, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Filter logs by level / time / search / request_id / thread_id. All filters
	 * are optional — pass empty/zero/false to skip.
	 *
	 * @param array<string, mixed> $filters Keys: level (string|array), since_ts (int),
	 *                                      query (string), request_id (int|string),
	 *                                      thread_id (string), include_debug (bool).
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $filters = array() ) {
		$logs = $this->get_logs();
		if ( empty( $logs ) ) {
			return array();
		}

		$levels        = isset( $filters['level'] ) ? (array) $filters['level'] : array();
		$levels        = array_filter( array_map( 'sanitize_key', $levels ) );
		$since_ts      = isset( $filters['since_ts'] ) ? (int) $filters['since_ts'] : 0;
		$query         = isset( $filters['query'] ) ? trim( strtolower( (string) $filters['query'] ) ) : '';
		$request_id    = isset( $filters['request_id'] ) ? (int) $filters['request_id'] : 0;
		$thread_id     = isset( $filters['thread_id'] ) ? trim( (string) $filters['thread_id'] ) : '';
		$include_debug = ! empty( $filters['include_debug'] );

		$out = array();
		foreach ( $logs as $entry ) {
			$entry_level = isset( $entry['level'] ) ? (string) $entry['level'] : 'info';
			if ( 'debug' === $entry_level && ! $include_debug ) {
				continue;
			}
			if ( ! empty( $levels ) && ! in_array( $entry_level, $levels, true ) ) {
				continue;
			}
			if ( $since_ts ) {
				$entry_ts = isset( $entry['time'] ) ? strtotime( (string) $entry['time'] ) : 0;
				if ( $entry_ts && $entry_ts < $since_ts ) {
					continue;
				}
			}
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			if ( $request_id ) {
				$entry_request_id = isset( $context['request_id'] ) ? (int) $context['request_id'] : 0;
				if ( $entry_request_id !== $request_id ) {
					continue;
				}
			}
			if ( '' !== $thread_id ) {
				$entry_thread = isset( $context['thread_id'] ) ? (string) $context['thread_id'] : '';
				if ( $entry_thread !== $thread_id ) {
					$context_json = wp_json_encode( $context );
					if ( false === stripos( (string) $context_json, $thread_id ) ) {
						continue;
					}
				}
			}
			if ( '' !== $query ) {
				$haystack = strtolower(
					(string) ( $entry['message'] ?? '' )
					. ' ' . wp_json_encode( $context )
				);
				if ( false === strpos( $haystack, $query ) ) {
					continue;
				}
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * @return int
	 */
	public function count_recent_errors( $hours = 24 ) {
		$cutoff = time() - max( 1, (int) $hours ) * HOUR_IN_SECONDS;
		$count  = 0;
		foreach ( $this->get_logs() as $entry ) {
			$level = isset( $entry['level'] ) ? (string) $entry['level'] : '';
			if ( 'error' !== $level && 'critical' !== $level ) {
				continue;
			}
			$ts = isset( $entry['time'] ) ? strtotime( (string) $entry['time'] ) : 0;
			if ( $ts && $ts >= $cutoff ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @param string $level Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	protected function write( $level, $message, array $context ) {
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => sanitize_key( $level ),
			'message' => sanitize_text_field( $message ),
			'context' => $this->sanitize_context( $context ),
		);

		error_log( '[Handik Booking App] ' . wp_json_encode( $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		$logs   = $this->get_logs();
		$logs[] = $entry;

		// Per-level retention: keep up to debug-cap debug entries and info-cap of
		// everything else (notice/warning/error/critical). This way a flood of
		// debug spam never wipes useful errors.
		$info_cap  = (int) $this->settings->get( 'log_max_entries_info', self::DEFAULT_INFO_CAP );
		$debug_cap = (int) $this->settings->get( 'log_max_entries_debug', self::DEFAULT_DEBUG_CAP );
		$info_cap  = $info_cap > 0 ? $info_cap : self::DEFAULT_INFO_CAP;
		$debug_cap = $debug_cap > 0 ? $debug_cap : self::DEFAULT_DEBUG_CAP;

		$debug_entries = array();
		$other_entries = array();
		foreach ( $logs as $log ) {
			if ( isset( $log['level'] ) && 'debug' === $log['level'] ) {
				$debug_entries[] = $log;
			} else {
				$other_entries[] = $log;
			}
		}
		if ( count( $debug_entries ) > $debug_cap ) {
			$debug_entries = array_slice( $debug_entries, -1 * $debug_cap );
		}
		if ( count( $other_entries ) > $info_cap ) {
			$other_entries = array_slice( $other_entries, -1 * $info_cap );
		}
		// Re-merge keeping chronological order — sort by time ASC.
		$logs = array_merge( $debug_entries, $other_entries );
		usort(
			$logs,
			static function( $a, $b ) {
				$ta = isset( $a['time'] ) ? (string) $a['time'] : '';
				$tb = isset( $b['time'] ) ? (string) $b['time'] : '';
				return strcmp( $ta, $tb );
			}
		);

		update_option( self::OPTION_NAME, $logs, false );
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected function sanitize_context( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && preg_match( '/(secret|token|key|authorization|password|client_secret)/i', $key ) ) {
					$sanitized[ $key ] = '[redacted]';
				} else {
					$sanitized[ $key ] = $this->sanitize_context( $item );
				}
			}
			return $sanitized;
		}

		if ( is_object( $value ) ) {
			return $this->sanitize_context( (array) $value );
		}

		if ( is_string( $value ) && strlen( $value ) > 500 ) {
			return substr( $value, 0, 500 ) . '...';
		}

		return $value;
	}
}
