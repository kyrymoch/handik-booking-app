<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Migration_110 {
	public function up() {
		global $wpdb;

		$job_requests = Handik_Booking_App_DB::table( 'job_requests' );

		$columns = array(
			'app_step'        => "ALTER TABLE {$job_requests} ADD COLUMN app_step VARCHAR(50) NOT NULL DEFAULT 'welcome' AFTER lookup_verified_at",
			'app_session_key' => "ALTER TABLE {$job_requests} ADD COLUMN app_session_key VARCHAR(191) NULL AFTER app_step",
			'app_state_json'  => "ALTER TABLE {$job_requests} ADD COLUMN app_state_json LONGTEXT NULL AFTER app_session_key",
			'completed_at'    => "ALTER TABLE {$job_requests} ADD COLUMN completed_at DATETIME NULL AFTER app_state_json",
		);

		foreach ( $columns as $column => $sql ) {
			if ( ! Handik_Booking_App_DB::column_exists( $job_requests, $column ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}
}
