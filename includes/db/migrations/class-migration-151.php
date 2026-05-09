<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.5.1 — Sprint 14a (branded confirmation emails — customer side).
 *
 * Adds a single nullable timestamp column `confirmation_email_sent_at` to
 * the three tables that own a "did this booking just get confirmed?"
 * write site:
 *
 *   - handik_bookings                       (main SPA Cal flow)
 *   - handik_direct_booking_requests        (Additional Forms direct preset)
 *   - handik_project_scheduling_requests    (Additional Forms project work-days preset)
 *
 * `Notifications_Service` uses an atomic UPDATE … WHERE id = %d AND
 * confirmation_email_sent_at IS NULL on the relevant table to decide
 * whether to actually send the customer email. Whichever code path
 * arrives first (capture-side leading edge vs. webhook trailing edge,
 * or webhook retries hitting the same booking twice) wins; the loser
 * sees zero affected_rows and bails. On wp_mail failure we roll the
 * stamp back to NULL so a manual retry can re-fire.
 *
 * Idempotent: column-existence guard via column_exists() so re-runs
 * never throw "duplicate column".
 */
class Handik_Booking_App_Migration_151 {
	public function up() {
		global $wpdb;

		$tables = array(
			Handik_Booking_App_DB::table( 'bookings' ),
			Handik_Booking_App_DB::table( 'direct_booking_requests' ),
			Handik_Booking_App_DB::table( 'project_scheduling_requests' ),
		);

		foreach ( $tables as $table ) {
			if ( ! Handik_Booking_App_DB::column_exists( $table, 'confirmation_email_sent_at' ) ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"ALTER TABLE {$table} ADD COLUMN confirmation_email_sent_at DATETIME NULL DEFAULT NULL"
				);
			}
		}
	}
}
