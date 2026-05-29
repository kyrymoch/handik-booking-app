<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Addresses_Service {

	// =====================================================================
	// Property-level structured attributes (Sprint 4 / migration 1.6.5).
	// Mirrors Contacts_Service's attribute schema. Single source of truth
	// for the sanitizer (admin_update), the edit modal, the REST allowlist,
	// and the pre-visit briefing assembly.
	// =====================================================================

	/**
	 * @return array<string, array<int, string>> field => allowed enum values.
	 */
	public static function attribute_enums() {
		return array(
			'building_type'      => array( '', 'single_family', 'apartment', 'condo', 'townhouse', 'commercial' ),
			'parking'            => array( '', 'driveway', 'street_free', 'street_metered', 'building_lot', 'none', 'specific_spot' ),
			'building_age_class' => array( '', 'pre_1978_lead_paint', 'modern', 'unknown' ),
		);
	}

	/**
	 * @return array<int, string> boolean attribute field names.
	 */
	public static function attribute_booleans() {
		return array(
			'doorman',
			'freight_elevator_required',
			'pets_present',
			'asbestos_warning',
			'mold_present',
			'hoarding_situation',
		);
	}

	/**
	 * Sensitive access-code fields — masked in the UI, stored raw.
	 *
	 * @return array<int, string>
	 */
	public static function attribute_sensitive() {
		return array( 'gate_code', 'lockbox_code', 'alarm_code' );
	}

	/**
	 * Plain short-text attribute fields.
	 *
	 * @return array<int, string>
	 */
	public static function attribute_texts() {
		return array( 'freight_elevator_hours', 'parking_notes', 'pets_notes' );
	}

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
	 * @param int  $contact_id Contact ID.
	 * @param bool $include_deleted Include soft-deleted entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_contact( $contact_id, $include_deleted = false ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'addresses' );
		$sql   = "SELECT * FROM {$table} WHERE contact_id = %d";
		if ( ! $include_deleted ) {
			$sql .= " AND ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )";
		}
		$sql .= ' ORDER BY is_primary DESC, is_default DESC, updated_at DESC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $contact_id ), ARRAY_A );
	}

	public function count_all() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'addresses' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' )" );
	}

	public function admin_update( $address_id, array $patch ) {
		global $wpdb;
		$address_id = (int) $address_id;
		if ( $address_id <= 0 ) {
			return false;
		}
		$update = array();
		foreach ( array( 'address_full', 'address_unit', 'city', 'state', 'zip_code', 'label' ) as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$value = (string) $patch[ $key ];
				$update[ $key ] = ( 'address_full' === $key ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
		}

		// Sprint 4 — property-level structured attributes. Enums validated
		// against the allowed set; booleans coerced; sensitive codes + texts
		// kept as short text; property_notes as a textarea.
		foreach ( self::attribute_enums() as $field => $allowed ) {
			if ( array_key_exists( $field, $patch ) ) {
				$value = sanitize_key( (string) $patch[ $field ] );
				$update[ $field ] = in_array( $value, $allowed, true ) ? $value : '';
			}
		}
		foreach ( self::attribute_booleans() as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$update[ $field ] = ! empty( $patch[ $field ] ) ? 1 : 0;
			}
		}
		foreach ( array_merge( self::attribute_sensitive(), self::attribute_texts() ) as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$update[ $field ] = sanitize_text_field( (string) $patch[ $field ] );
			}
		}
		if ( array_key_exists( 'property_notes', $patch ) ) {
			$update['property_notes'] = sanitize_textarea_field( (string) $patch['property_notes'] );
		}

		if ( empty( $update ) ) {
			return false;
		}
		$wpdb->update( Handik_Booking_App_DB::table( 'addresses' ), $update, array( 'id' => $address_id ) );
		return true;
	}

	public function set_primary( $address_id ) {
		global $wpdb;
		$address_id = (int) $address_id;
		if ( $address_id <= 0 ) {
			return false;
		}
		$row = $this->get( $address_id );
		if ( ! $row ) {
			return false;
		}
		$table = Handik_Booking_App_DB::table( 'addresses' );
		$wpdb->update( $table, array( 'is_primary' => 0 ), array( 'contact_id' => (int) $row['contact_id'] ) );
		$wpdb->update( $table, array( 'is_primary' => 1 ), array( 'id' => $address_id ) );
		return true;
	}

	public function soft_delete( $address_id ) {
		global $wpdb;
		$address_id = (int) $address_id;
		if ( $address_id <= 0 ) {
			return false;
		}
		$wpdb->update(
			Handik_Booking_App_DB::table( 'addresses' ),
			array( 'deleted_at' => current_time( 'mysql' ) ),
			array( 'id' => $address_id )
		);
		return true;
	}

	public function restore( $address_id ) {
		global $wpdb;
		$address_id = (int) $address_id;
		if ( $address_id <= 0 ) {
			return false;
		}
		$wpdb->update(
			Handik_Booking_App_DB::table( 'addresses' ),
			array( 'deleted_at' => null ),
			array( 'id' => $address_id )
		);
		return true;
	}

	/**
	 * Sprint 12 — bulk hard-delete every row for a contact (cascade
	 * step). Drops both soft-deleted and active rows. Returns the row
	 * count for the audit-log entry.
	 *
	 * @param int $contact_id Contact id.
	 * @return int
	 */
	public function delete_hard_for_contact( $contact_id ) {
		global $wpdb;
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return 0;
		}
		$out = $wpdb->delete(
			Handik_Booking_App_DB::table( 'addresses' ),
			array( 'contact_id' => $contact_id ),
			array( '%d' )
		);
		return false === $out ? 0 : (int) $out;
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

	/**
	 * Batch fetch by primary key (admin list use).
	 *
	 * @param array<int, int> $ids Address ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_many( array $ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = Handik_Booking_App_DB::table( 'addresses' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}
}
