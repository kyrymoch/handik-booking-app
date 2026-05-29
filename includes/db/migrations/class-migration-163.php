<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.3 — link pre-approvals to a contact.
 *
 * Sprint 2 of the customer-unification roadmap. `handik_form_approvals`
 * keyed approvals purely by phone string. The admin pre-approval form
 * now offers a "search the customer base" picker, so we want to remember
 * WHICH contact an approval was created for (denormalized hint — phone
 * stays the authoritative lookup key because the OTP gate matches on
 * phone). This lets the admin list render the phone as a link back to the
 * Customer profile.
 *
 * Adds a nullable `contact_id` + index, then a one-time backfill: any
 * existing approval whose normalized phone matches exactly one contact
 * gets linked. Idempotent — the column guard means a re-run is a no-op,
 * and the backfill only touches rows where contact_id IS NULL.
 */
class Handik_Booking_App_Migration_163 {
	public function up() {
		global $wpdb;

		$approvals = Handik_Booking_App_DB::table( 'form_approvals' );
		$contacts  = Handik_Booking_App_DB::table( 'contacts' );

		// Table may not exist yet on installs that never ran 1.6.2 in the
		// same boot (the runner applies migrations in order, so it will —
		// but guard defensively so a partial state can't fatal).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $approvals ) );
		if ( ! $exists ) {
			return;
		}

		if ( ! Handik_Booking_App_DB::column_exists( $approvals, 'contact_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$approvals} ADD COLUMN contact_id BIGINT(20) UNSIGNED NULL AFTER phone"
			);
		}
		if ( ! Handik_Booking_App_DB::index_exists( $approvals, 'idx_contact_id' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"ALTER TABLE {$approvals} ADD KEY idx_contact_id (contact_id)"
			);
		}

		// One-time backfill: link approvals to a contact when the phone
		// matches exactly one contact row. Phones are already E.164 on both
		// sides (Contacts_Service::normalize_phone on write), so a direct
		// equality join is correct. Skip ambiguous matches (>1 contact per
		// phone) to avoid linking the wrong person.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"UPDATE {$approvals} a
			 JOIN (
			     SELECT phone, MIN(id) AS cid
			     FROM {$contacts}
			     GROUP BY phone
			     HAVING COUNT(*) = 1
			 ) c ON c.phone = a.phone
			 SET a.contact_id = c.cid
			 WHERE a.contact_id IS NULL"
		);
	}
}
