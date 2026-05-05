<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Addresses_Service {
	/**
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $payload Payload.
	 * @return int
	 */
	public function sync( $contact_id, array $payload ) {
		global $wpdb;

		if ( $contact_id <= 0 ) {
			return 0;
		}

		$table       = Handik_Booking_App_DB::table( 'addresses' );
		$address_id  = ! empty( $payload['address_id'] ) ? absint( $payload['address_id'] ) : 0;
		$address     = array(
			'contact_id'     => $contact_id,
			'address_full'   => ! empty( $payload['address_full'] ) ? sanitize_textarea_field( $payload['address_full'] ) : '',
			'address_line_1' => ! empty( $payload['address_line_1'] ) ? sanitize_text_field( $payload['address_line_1'] ) : '',
			'address_unit'   => ! empty( $payload['address_unit'] ) ? sanitize_text_field( $payload['address_unit'] ) : '',
			'city'           => ! empty( $payload['city'] ) ? sanitize_text_field( $payload['city'] ) : '',
			'state'          => ! empty( $payload['state'] ) ? sanitize_text_field( $payload['state'] ) : '',
			'zip_code'       => ! empty( $payload['zip_code'] ) ? sanitize_text_field( $payload['zip_code'] ) : '',
			'is_default'     => ! empty( $payload['is_default'] ) ? 1 : 0,
		);

		if ( empty( $address['address_full'] ) && empty( $address['address_line_1'] ) ) {
			return 0;
		}

		if ( $address_id ) {
			$wpdb->update( $table, $address, array( 'id' => $address_id, 'contact_id' => $contact_id ) );
			return $address_id;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d AND address_full = %s LIMIT 1", $contact_id, $address['address_full'] ),
			ARRAY_A
		);

		if ( $existing ) {
			$wpdb->update( $table, $address, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}

		if ( ! empty( $address['is_default'] ) ) {
			$wpdb->update( $table, array( 'is_default' => 0 ), array( 'contact_id' => $contact_id ) );
		}

		$wpdb->insert( $table, $address );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $contact_id Contact ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_contact( $contact_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'addresses' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d ORDER BY is_default DESC, updated_at DESC", $contact_id ),
			ARRAY_A
		);
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'addresses' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * @param int $address_id Address ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $address_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'addresses' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $address_id ), ARRAY_A );
		return $row ?: null;
	}
}
