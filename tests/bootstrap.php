<?php
/**
 * PHPUnit bootstrap.
 *
 * Sets up just enough WordPress-compatible globals/functions to load the small,
 * pure utility classes (Logger, Api_Response). We intentionally avoid pulling
 * the full WordPress test framework — these are unit tests, not integration
 * tests, and a tiny stub layer keeps the suite fast (<1s).
 */

// Plugin classes guard with `if ( ! defined( 'ABSPATH' ) ) { exit; }`. Define it
// so `require_once` actually loads the file body during tests.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// In-memory option store so Logger can round-trip through get_option/update_option.
$GLOBALS['handik_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['handik_test_options'] )
			? $GLOBALS['handik_test_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['handik_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['handik_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/[\r\n\t\0\x0B]+/', ' ', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		public function get_error_code() {
			$keys = array_keys( $this->errors );
			return $keys ? $keys[0] : '';
		}
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

// Now load the units under test.
require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-api-response.php';
