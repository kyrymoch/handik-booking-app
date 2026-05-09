<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.5.0 — Sprint 13.5 (booking visibility unification).
 *
 * Owner-reported bug: an admin-created booking via the Sprint 13
 * "+ Add booking" CTA never showed up in the Bookings list. Tracing
 * showed the same bug existed for every public direct-booking-form
 * submission AND every project work-day schedule — they only ever
 * surfaced under Additional Forms admin, never in the unified
 * Bookings list at `?page=handik-booking-app-bookings`.
 *
 * Root cause: `Bookings_Service::list_in_window()` reads only
 * `handik_bookings`, but `handik_direct_booking_requests` and
 * `handik_project_work_days` were entirely separate tables with no
 * mirror into the canonical bookings table. Sprint 4 (Additional
 * Forms launch) added them as parallel tables; never bridged.
 *
 * This migration relaxes the schema so the bridge is possible:
 *
 *   1. `handik_bookings.job_request_id` becomes NULL-able. Direct
 *      bookings have no `handik_job_requests` row to point at — the
 *      assistant flow is what produces those. Direct rows reference
 *      `handik_direct_booking_requests` via a new column instead.
 *   2. New `handik_bookings.direct_request_id BIGINT NULL` + KEY.
 *      Mirrors `direct_booking_requests.id` for direct bookings;
 *      stays NULL for main-SPA Cal bookings.
 *
 * Render code branches on whichever id is set:
 *   job_request_id IS NOT NULL   → main SPA booking, JOIN job_requests
 *   direct_request_id IS NOT NULL → direct booking, JOIN direct_booking_requests
 *
 * Data backfill: nothing. Existing `handik_bookings` rows have
 * `job_request_id` already populated; `direct_request_id` defaults
 * NULL. Direct + project rows that pre-date this migration will
 * remain invisible until they're re-confirmed via webhook (which
 * fires `dispatch_direct` and now writes the `handik_bookings`
 * mirror) — acceptable: those are completed past visits already
 * recorded under Additional Forms.
 */
class Handik_Booking_App_Migration_150 {
	public function up() {
		global $wpdb;

		$bookings = Handik_Booking_App_DB::table( 'bookings' );

		// 1. Make job_request_id nullable. MySQL's MODIFY COLUMN is
		// idempotent — running again produces the same NULL state.
		$current_definition = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$bookings} WHERE Field = %s", 'job_request_id' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( $current_definition && 'NO' === ( $current_definition['Null'] ?? 'NO' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} MODIFY COLUMN job_request_id BIGINT(20) UNSIGNED NULL"
			);
		}

		// 2. Add direct_request_id + KEY (idempotent via column_exists +
		// index_exists checks so re-runs don't fail with "duplicate
		// column" / "duplicate key" errors).
		if ( ! Handik_Booking_App_DB::column_exists( $bookings, 'direct_request_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} ADD COLUMN direct_request_id BIGINT(20) UNSIGNED NULL AFTER job_request_id"
			);
		}
		if ( ! Handik_Booking_App_DB::index_exists( $bookings, 'direct_request_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} ADD KEY direct_request_id (direct_request_id)"
			);
		}
	}
}
