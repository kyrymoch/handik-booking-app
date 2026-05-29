<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.6 — notification idempotency stamps (Sprint 8).
 *
 * The reminder / review / nudge engine is a single recurring scanner that
 * walks bookings + requests and sends at-most-once. Each send is gated by
 * a per-row timestamp column so a re-scan (or a coarse cron interval, or
 * the DISABLE_WP_CRON heartbeat firing twice) can never double-text or
 * double-email a customer. NULL = not sent yet; a timestamp = sent.
 *
 *   handik_bookings.reminder_24h_sent_at    24h-before SMS reminder
 *   handik_bookings.reminder_2h_sent_at     2h-before SMS reminder
 *   handik_bookings.review_request_sent_at  post-visit review-request email
 *   handik_job_requests.nudge_1_sent_at     first ready-not-booked nudge
 *   handik_job_requests.nudge_2_sent_at     second ready-not-booked nudge
 *
 * Safe DEFAULT NULL, idempotent (column_exists guards). No data touched.
 */
class Handik_Booking_App_Migration_166 {
	public function up() {
		global $wpdb;

		$bookings = Handik_Booking_App_DB::table( 'bookings' );
		foreach ( array( 'reminder_24h_sent_at', 'reminder_2h_sent_at', 'review_request_sent_at' ) as $col ) {
			if ( ! Handik_Booking_App_DB::column_exists( $bookings, $col ) ) {
				$wpdb->query( "ALTER TABLE {$bookings} ADD COLUMN {$col} DATETIME NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		$job_requests = Handik_Booking_App_DB::table( 'job_requests' );
		foreach ( array( 'nudge_1_sent_at', 'nudge_2_sent_at' ) as $col ) {
			if ( ! Handik_Booking_App_DB::column_exists( $job_requests, $col ) ) {
				$wpdb->query( "ALTER TABLE {$job_requests} ADD COLUMN {$col} DATETIME NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}
}
