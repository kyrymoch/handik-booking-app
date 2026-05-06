<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Handik_Booking_App_Logger::sanitize_context.
 *
 * Logs are stored long-term in wp_options; if a secret slips into a log entry
 * it can be exposed by /admin/logs or exports. The redaction pass exists for
 * exactly that reason — these tests pin the behavior down so it can't silently
 * regress.
 */
final class LoggerRedactionTest extends TestCase {

	/** @var Handik_Booking_App_Logger */
	private $logger;

	/** @var \ReflectionMethod */
	private $sanitize;

	protected function setUp(): void {
		parent::setUp();
		$settings = new class() {
			public function is_debug() {
				return false;
			}
			public function get( $key, $default = null ) {
				return $default;
			}
		};
		$this->logger = new Handik_Booking_App_Logger( $settings );

		$ref            = new \ReflectionClass( $this->logger );
		$this->sanitize = $ref->getMethod( 'sanitize_context' );
		$this->sanitize->setAccessible( true );
	}

	private function sanitize( $value ) {
		return $this->sanitize->invoke( $this->logger, $value );
	}

	public function test_top_level_secret_keys_are_redacted(): void {
		$out = $this->sanitize(
			array(
				'secret'        => 'hush',
				'token'         => 'tok_123',
				'api_key'       => 'sk-abcdef',
				'authorization' => 'Bearer XYZ',
				'password'      => 'p@ss',
				'client_secret' => 'shh',
				'safe'          => 'visible',
			)
		);

		$this->assertSame( '[redacted]', $out['secret'] );
		$this->assertSame( '[redacted]', $out['token'] );
		$this->assertSame( '[redacted]', $out['api_key'] );
		$this->assertSame( '[redacted]', $out['authorization'] );
		$this->assertSame( '[redacted]', $out['password'] );
		$this->assertSame( '[redacted]', $out['client_secret'] );
		$this->assertSame( 'visible', $out['safe'] );
	}

	public function test_redaction_is_case_insensitive(): void {
		$out = $this->sanitize(
			array(
				'API_TOKEN'     => 'top',
				'AuthToken'     => 'auth',
				'CLIENT_SECRET' => 'shh',
			)
		);

		$this->assertSame( '[redacted]', $out['API_TOKEN'] );
		$this->assertSame( '[redacted]', $out['AuthToken'] );
		$this->assertSame( '[redacted]', $out['CLIENT_SECRET'] );
	}

	public function test_nested_secrets_are_redacted(): void {
		$out = $this->sanitize(
			array(
				'request' => array(
					'headers' => array(
						'authorization' => 'Bearer sensitive',
						'content-type'  => 'application/json',
					),
					'token'   => 'inner_token',
				),
			)
		);

		$this->assertSame( '[redacted]', $out['request']['headers']['authorization'] );
		$this->assertSame( 'application/json', $out['request']['headers']['content-type'] );
		$this->assertSame( '[redacted]', $out['request']['token'] );
	}

	public function test_long_strings_are_truncated_to_500_chars(): void {
		$long = str_repeat( 'a', 700 );
		$out  = $this->sanitize( array( 'note' => $long ) );

		$this->assertSame( 503, strlen( $out['note'] ), 'should truncate to 500 chars + "..."' );
		$this->assertStringEndsWith( '...', $out['note'] );
	}

	public function test_short_strings_are_passed_through(): void {
		$out = $this->sanitize( array( 'note' => 'hello' ) );
		$this->assertSame( 'hello', $out['note'] );
	}

	public function test_objects_are_walked_like_arrays(): void {
		$obj           = new stdClass();
		$obj->password = 'oops';
		$obj->name     = 'Alex';

		$out = $this->sanitize( array( 'user' => $obj ) );

		$this->assertSame( '[redacted]', $out['user']['password'] );
		$this->assertSame( 'Alex', $out['user']['name'] );
	}

	public function test_non_array_scalar_values_are_returned_unchanged(): void {
		$this->assertSame( 42, $this->sanitize( 42 ) );
		$this->assertSame( 'plain', $this->sanitize( 'plain' ) );
		$this->assertSame( true, $this->sanitize( true ) );
		$this->assertNull( $this->sanitize( null ) );
	}

	public function test_numeric_keys_matching_secret_pattern_are_kept(): void {
		// Numeric keys never match the regex (which only runs on string keys),
		// so list-style values are walked, not stripped.
		$out = $this->sanitize( array( 'a', 'b', 'c' ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $out );
	}
}
