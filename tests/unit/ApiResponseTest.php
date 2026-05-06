<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Handik_Booking_App_Api_Response.
 *
 * The helper centralizes how every REST endpoint translates a service result
 * array into either a WP_REST_Response or a WP_Error. Drift here would change
 * the wire format silently, so we lock the four happy/edge paths down.
 */
final class ApiResponseTest extends TestCase {

	public function test_from_array_with_error_returns_wp_error_with_default_status(): void {
		$result = Handik_Booking_App_Api_Response::from_array( array( 'error' => 'Nope' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Handik_Booking_App_Api_Response::ERROR_CODE, $result->get_error_code() );
		$this->assertSame( 'Nope', $result->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}

	public function test_from_array_with_error_preserves_status_code(): void {
		$result = Handik_Booking_App_Api_Response::from_array(
			array(
				'error'  => 'Forbidden',
				'status' => 403,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
	}

	public function test_from_array_with_zero_status_falls_back_to_400(): void {
		$result = Handik_Booking_App_Api_Response::from_array(
			array(
				'error'  => 'Boom',
				'status' => 0,
			)
		);

		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}

	public function test_from_array_with_no_error_returns_rest_response(): void {
		$result = Handik_Booking_App_Api_Response::from_array(
			array(
				'request_id' => 42,
				'state'      => 'ready',
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame(
			array(
				'request_id' => 42,
				'state'      => 'ready',
			),
			$result->get_data()
		);
	}

	public function test_success_helper_adds_success_flag(): void {
		$result = Handik_Booking_App_Api_Response::success( array( 'id' => 7 ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame(
			array(
				'success' => true,
				'id'      => 7,
			),
			$result->get_data()
		);
	}

	public function test_success_helper_works_with_empty_payload(): void {
		$result = Handik_Booking_App_Api_Response::success();

		$this->assertSame( array( 'success' => true ), $result->get_data() );
	}

	public function test_error_helper_uses_default_code_and_status(): void {
		$result = Handik_Booking_App_Api_Response::error( 'Bad request' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Handik_Booking_App_Api_Response::ERROR_CODE, $result->get_error_code() );
		$this->assertSame( 'Bad request', $result->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}

	public function test_error_helper_accepts_custom_status_and_code(): void {
		$result = Handik_Booking_App_Api_Response::error( 'Not found', 404, 'handik_missing' );

		$this->assertSame( 'handik_missing', $result->get_error_code() );
		$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
	}

	public function test_error_helper_zero_status_falls_back_to_400(): void {
		$result = Handik_Booking_App_Api_Response::error( 'X', 0 );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}
}
