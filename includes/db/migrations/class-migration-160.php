<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.0 — Sprint 15 (project work-days → unified Bookings list).
 *
 * Owner-reported: Additional Forms project bookings never showed up
 * in the unified admin Bookings list at
 * `?page=handik-booking-app-bookings`. Direct-form bookings were
 * bridged into `handik_bookings` back in 1.5.0 via the
 * `direct_request_id` column, but the same bridge for project work
 * days was never built — `Project_Schedule_Service::confirm_schedule`
 * created N Cal.com bookings, wrote them into
 * `handik_project_work_days`, and stopped there.
 *
 * This migration is the schema half of the fix. It adds:
 *
 *   `handik_bookings.project_work_day_id BIGINT(20) UNSIGNED NULL`
 *
 * One handik_bookings row per work day (1:1 with
 * handik_project_work_days), so each day of a multi-day project
 * surfaces as a row in the admin Bookings list with its own
 * start/end window and its own `cal_booking_id` (UNIQUE — the
 * existing key still guarantees idempotency across the leading-edge
 * upsert from `confirm_schedule` and the trailing-edge webhook).
 *
 * Render code branches on whichever id is set:
 *   job_request_id IS NOT NULL        → main SPA, JOIN job_requests
 *   direct_request_id IS NOT NULL     → direct form, JOIN direct_booking_requests
 *   project_work_day_id IS NOT NULL   → project form, JOIN project_work_days → project_scheduling_requests
 *
 * Backfill: nothing. Pre-existing project bookings stay invisible
 * until/unless we later add a reconciliation pass — acceptable per
 * the same call we made for direct-form rows in 1.5.0.
 */
class Handik_Booking_App_Migration_160 {
	public function up() {
		global $wpdb;

		$bookings = Handik_Booking_App_DB::table( 'bookings' );

		// Add project_work_day_id + KEY (idempotent via column_exists +
		// index_exists checks so re-runs don't fail with "duplicate
		// column" / "duplicate key" errors).
		if ( ! Handik_Booking_App_DB::column_exists( $bookings, 'project_work_day_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} ADD COLUMN project_work_day_id BIGINT(20) UNSIGNED NULL AFTER direct_request_id"
			);
		}
		if ( ! Handik_Booking_App_DB::index_exists( $bookings, 'project_work_day_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} ADD KEY project_work_day_id (project_work_day_id)"
			);
		}
	}
}
