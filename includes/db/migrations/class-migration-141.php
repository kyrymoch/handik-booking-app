<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.4.1 — Sprint 1 (security) supporting columns.
 *
 * Adds:
 *   - direct_booking_requests.capture_token  VARCHAR(64) NOT NULL DEFAULT ''
 *
 * The capture_token closes an IDOR on POST /forms/direct/{id}/capture: in
 * 1.4.0 anyone could iterate `direct_booking_requests.id` and call the
 * endpoint to overwrite a stranger's booking record. Now /submit issues
 * an unguessable per-row token and /capture rejects requests whose token
 * doesn't match (`hash_equals`).
 *
 * No data backfill — the column is only consulted on capture, and active
 * sessions started under 1.4.0 will simply re-submit if they hit the new
 * version mid-flow (rare and self-correcting).
 */
class Handik_Booking_App_Migration_141 {
	public function up() {
		global $wpdb;

		$direct = Handik_Booking_App_DB::table( 'direct_booking_requests' );

		if ( ! Handik_Booking_App_DB::column_exists( $direct, 'capture_token' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$direct} ADD COLUMN capture_token VARCHAR(64) NOT NULL DEFAULT '' AFTER cal_booking_uid"
			);
		}
	}
}
