<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.2 — Additional Forms phone pre-approvals.
 *
 * Operator-driven soft gate for the Additional Forms presets: when a
 * customer is given a direct link to a preset (e.g.
 * `/booking/large-visit-360/`), the operator can pre-approve one or more
 * phone numbers for that preset. After the customer passes the phone-OTP
 * step, the form silently looks up `(preset_slug, verified_phone)`:
 *   - active approval count > 0 → proceed straight to the details step.
 *   - active approval count = 0 → soft warning screen (not a hard block):
 *     "This appointment wasn't pre-approved by Alex for this number;
 *      use the main booking form or continue if you're sure."
 * Each successful booking consumes one active approval (oldest first).
 * Multiple pre-approvals on the same phone allow the same number of
 * unwarned bookings.
 *
 * Schema:
 *   id                  surrogate PK
 *   preset_slug         FK by string to handik_form_presets.preset_slug
 *   phone               E.164 normalized via Contacts_Service::normalize_phone
 *   notes               operator memo (visible only in admin)
 *   created_by          wp_users.ID of the operator
 *   created_at          insert timestamp (mysql, UTC server time)
 *   expires_at          NULL = no expiry; otherwise after this time the row
 *                       counts as "expired" and no longer approves
 *   consumed_at         NULL until a booking consumes the row
 *   consumed_booking_id handik_bookings.id of the booking that consumed it
 *   status              active | consumed | revoked | expired
 *                       (derived; persisted as a denormalized column so
 *                       admin queries don't have to recompute every load)
 */
class Handik_Booking_App_Migration_162 {
	public function up() {
		global $wpdb;

		$table           = Handik_Booking_App_DB::table( 'form_approvals' );
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			preset_slug VARCHAR(190) NOT NULL,
			phone VARCHAR(40) NOT NULL,
			notes TEXT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			consumed_at DATETIME NULL DEFAULT NULL,
			consumed_booking_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY (id),
			KEY idx_preset_phone_status (preset_slug, phone, status),
			KEY idx_status (status),
			KEY idx_phone (phone)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
