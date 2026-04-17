<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Migration_120 {
	public function up() {
		global $wpdb;

		$addresses    = Handik_Booking_App_DB::table( 'addresses' );
		$job_requests = Handik_Booking_App_DB::table( 'job_requests' );

		$columns = array(
			$addresses => array(
				'address_unit' => "ALTER TABLE {$addresses} ADD COLUMN address_unit VARCHAR(100) NOT NULL DEFAULT '' AFTER address_line_1",
			),
			$job_requests => array(
				'address_unit' => "ALTER TABLE {$job_requests} ADD COLUMN address_unit VARCHAR(100) NOT NULL DEFAULT '' AFTER address_full",
			),
		);

		foreach ( $columns as $table => $table_columns ) {
			foreach ( $table_columns as $column => $sql ) {
				if ( ! Handik_Booking_App_DB::column_exists( $table, $column ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}
	}
}
