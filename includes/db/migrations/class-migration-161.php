<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.1 — Sprint 15 / Part 3 (external Cal.com bookings).
 *
 * Owner-reported gap: customers who couldn't complete the booking
 * flow through the plugin (form errors, embed timeouts, "Open the
 * booking page directly" fallback link, etc.) end up creating a
 * Cal.com booking on Cal's own page. Cal webhooks DO fire for those
 * bookings, but the existing Webhook_Service::handle_cal_webhook
 * routing requires one of:
 *
 *   - metadata.handik_booking_source ("direct_booking_form" /
 *     "project_work_days_form")
 *   - metadata.handik_job_request_id (main SPA AI flow)
 *   - cal_booking_id matching an existing job_requests row
 *   - email/phone matching the most recent pending job_request
 *
 * If NONE of those match — which is exactly the "booked directly on
 * Cal" case — the handler logs an error and returns 404. The
 * booking is invisible to the admin.
 *
 * This release captures those bookings as "external" rows in
 * handik_bookings (all FK columns NULL, attendee info preserved in
 * raw_webhook_json). When we can resolve the attendee email/phone
 * to an existing handik_contacts row, we link it via this new
 * `external_contact_id` column so People & Requests still shows the
 * booking under the right person. When we can't, the booking still
 * surfaces but as an "External booking" card with the attendee
 * pulled from the webhook payload.
 *
 * Render code now branches on four sources:
 *   job_request_id IS NOT NULL          → main SPA, JOIN job_requests
 *   direct_request_id IS NOT NULL       → direct form, JOIN direct_booking_requests
 *   project_work_day_id IS NOT NULL     → project form, JOIN project_work_days
 *   external_contact_id IS NOT NULL     → external booking with linked contact, JOIN contacts
 *   ALL NULL                            → external booking, attendee in raw_webhook_json
 */
class Handik_Booking_App_Migration_161 {
	public function up() {
		global $wpdb;

		$bookings = Handik_Booking_App_DB::table( 'bookings' );

		if ( ! Handik_Booking_App_DB::column_exists( $bookings, 'external_contact_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} ADD COLUMN external_contact_id BIGINT(20) UNSIGNED NULL AFTER project_work_day_id"
			);
		}
		if ( ! Handik_Booking_App_DB::index_exists( $bookings, 'external_contact_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$bookings} ADD KEY external_contact_id (external_contact_id)"
			);
		}
	}
}
