<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiny helper that standardizes how plugin services return REST results.
 *
 * Every service method that backs a REST endpoint returns an associative array.
 * Either it contains an `error` key (in which case it represents a failure and
 * may include a `status` HTTP code), or it represents a successful payload.
 *
 * `from_array()` takes such an array and turns it into either a
 * {@see WP_REST_Response} or a {@see WP_Error}, so callers no longer need to
 * remember the exact shape.
 */
class Handik_Booking_App_Api_Response {
	const ERROR_CODE = 'handik_booking_app_error';

	/**
	 * @param array<string, mixed> $result Raw result from a service method.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function from_array( array $result ) {
		if ( ! empty( $result['error'] ) ) {
			$status = isset( $result['status'] ) ? absint( $result['status'] ) : 400;
			$data   = array( 'status' => $status ? $status : 400 );
			return new WP_Error( self::ERROR_CODE, (string) $result['error'], $data );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Convenience for endpoints that want to return a flat success payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return WP_REST_Response
	 */
	public static function success( array $payload = array() ) {
		$payload = array_merge( array( 'success' => true ), $payload );
		return rest_ensure_response( $payload );
	}

	/**
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status (defaults to 400).
	 * @param string $code    Optional machine-readable code (defaults to ERROR_CODE).
	 * @return WP_Error
	 */
	public static function error( $message, $status = 400, $code = self::ERROR_CODE ) {
		return new WP_Error(
			(string) ( $code ?: self::ERROR_CODE ),
			(string) $message,
			array( 'status' => absint( $status ) ?: 400 )
		);
	}
}
