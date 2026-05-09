<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_DB {
	/**
	 * @param string $short Short table name.
	 * @return string
	 */
	public static function table( $short ) {
		global $wpdb;

		return $wpdb->prefix . 'handik_' . $short;
	}

	public static function activate() {
		$migrations = new Handik_Booking_App_Migrations();
		$migrations->migrate();
	}

	/**
	 * @param string $table Table.
	 * @param string $column Column.
	 * @return bool
	 */
	public static function column_exists( $table, $column ) {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return ! empty( $result );
	}

	/**
	 * Sprint 13.5 — checks whether an index exists on a table by name.
	 * Used by Migration 1.5.0 so re-runs are idempotent when adding the
	 * new direct_request_id key on handik_bookings.
	 *
	 * @param string $table Full table name.
	 * @param string $index Index name.
	 * @return bool
	 */
	public static function index_exists( $table, $index ) {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
		return ! empty( $result );
	}
}
