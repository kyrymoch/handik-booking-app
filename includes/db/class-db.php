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
}
