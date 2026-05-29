<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.4 — customer-level structured attributes.
 *
 * Sprint 3 of the customer-unification roadmap (§7). Until now everything
 * the operator knew about a customer lived in a single free-text `notes`
 * textarea — unsearchable, unusable in templates, invisible on the job
 * sheet. This adds structured customer-level fields following the
 * service-CRM pattern (Jobber / Housecall Pro): enums + booleans for the
 * recurring attributes (fast to filter via WHERE), a JSON `tags_json`
 * column for the flexible multi-select, and `brand_preferences` short
 * text. The existing `notes` textarea stays as the free-form catch-all.
 *
 * Variant A (denormalized columns) for booleans/enums so the Customers
 * list can filter with plain WHERE clauses; Variant B (JSON) only for the
 * open-ended tags. Nothing is migrated out of `notes` automatically — the
 * operator repacks attributes through usage (see roadmap §9). All columns
 * ship with safe DEFAULTs so no existing data is touched. Idempotent:
 * every ADD COLUMN is guarded by column_exists, so a partial-run + re-run
 * never throws.
 */
class Handik_Booking_App_Migration_164 {
	public function up() {
		global $wpdb;

		$contacts = Handik_Booking_App_DB::table( 'contacts' );

		$columns = array(
			// Communication & language.
			'language'                 => "VARCHAR(8) NOT NULL DEFAULT ''",
			'preferred_channel'        => "VARCHAR(16) NOT NULL DEFAULT ''",
			'preferred_time'           => "VARCHAR(16) NOT NULL DEFAULT ''",
			'do_not_text'              => 'TINYINT(1) NOT NULL DEFAULT 0',
			// Payment behavior.
			'payment_method_preferred' => "VARCHAR(16) NOT NULL DEFAULT ''",
			'tips_well'                => "VARCHAR(16) NOT NULL DEFAULT ''",
			'payment_on_time'          => "VARCHAR(24) NOT NULL DEFAULT ''",
			'requires_invoice'         => 'TINYINT(1) NOT NULL DEFAULT 0',
			// Risk / behavior flags (internal-only).
			'vip'                      => 'TINYINT(1) NOT NULL DEFAULT 0',
			'do_not_service'           => 'TINYINT(1) NOT NULL DEFAULT 0',
			'scope_creeper'            => 'TINYINT(1) NOT NULL DEFAULT 0',
			'negotiates_hard'          => 'TINYINT(1) NOT NULL DEFAULT 0',
			'complains_after'          => 'TINYINT(1) NOT NULL DEFAULT 0',
			// Service preferences.
			'eco_friendly_only'        => 'TINYINT(1) NOT NULL DEFAULT 0',
			'brand_preferences'        => "VARCHAR(255) NOT NULL DEFAULT ''",
			// Flexible multi-select.
			'tags_json'                => 'TEXT NULL',
		);

		foreach ( $columns as $name => $definition ) {
			if ( ! Handik_Booking_App_DB::column_exists( $contacts, $name ) ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"ALTER TABLE {$contacts} ADD COLUMN {$name} {$definition}"
				);
			}
		}

		// Index the two highest-value filter flags so the Customers list
		// "VIP" / "Do-not-service" chips stay fast as the table grows.
		if ( ! Handik_Booking_App_DB::index_exists( $contacts, 'idx_vip' ) ) {
			$wpdb->query( "ALTER TABLE {$contacts} ADD KEY idx_vip (vip)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! Handik_Booking_App_DB::index_exists( $contacts, 'idx_do_not_service' ) ) {
			$wpdb->query( "ALTER TABLE {$contacts} ADD KEY idx_do_not_service (do_not_service)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
