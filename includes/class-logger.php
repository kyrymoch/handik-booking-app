<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Logger {
	const OPTION_NAME = 'handik_booking_app_logs';
	const MAX_ENTRIES = 200;

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

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function debug( $message, array $context = array() ) {
		if ( ! $this->settings->is_debug() ) {
			return;
		}

		$this->write( 'debug', $message, $context );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function info( $message, array $context = array() ) {
		$this->write( 'info', $message, $context );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function error( $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs() {
		$logs = get_option( self::OPTION_NAME, array() );

		return is_array( $logs ) ? $logs : array();
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
		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -1 * self::MAX_ENTRIES );
		}

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
