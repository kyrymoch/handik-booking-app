<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.5.2 — Sprint 14c (cancellation + reschedule emails).
 *
 * Adds a single `last_status_emailed VARCHAR(16) NULL` column to the two
 * tables that own a "did we email about this booking yet?" idempotency
 * surface for the new email events:
 *
 *   - handik_bookings                    (main SPA Cal flow)
 *   - handik_direct_booking_requests     (Additional Forms direct preset)
 *
 * `confirmation_email_sent_at` (added in Migration 1.5.1) stays as the
 * single-shot stamp for the original booking-confirmation email. The
 * new column tracks the LAST status we emailed about so we can dedupe
 * per-state-transition events:
 *
 *   - Webhook retry with the same status → no-op (UPDATE WHERE last_status_emailed != %s
 *     returns 0 affected rows).
 *   - Real state change (booked → rescheduled → cancelled) → email goes
 *     out, column updates.
 *
 * Project schedules are NOT included — per-day Cal bookings make
 * per-schedule cancel/reschedule semantics ambiguous; project flow
 * cancel/reschedule is deferred to v2.
 *
 * Idempotent: column-existence guard via column_exists() so re-runs
 * never throw "duplicate column".
 */
class Handik_Booking_App_Migration_152 {
	public function up() {
		global $wpdb;

		$tables = array(
			Handik_Booking_App_DB::table( 'bookings' ),
			Handik_Booking_App_DB::table( 'direct_booking_requests' ),
		);

		foreach ( $tables as $table ) {
			if ( ! Handik_Booking_App_DB::column_exists( $table, 'last_status_emailed' ) ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"ALTER TABLE {$table} ADD COLUMN last_status_emailed VARCHAR(16) NULL DEFAULT NULL"
				);
			}
		}
	}
}
