<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.5 — property-level (address) structured attributes.
 *
 * Sprint 4 of the customer-unification roadmap (§7). Customer-level
 * attributes (1.6.4) answer "who is this person"; property attributes
 * answer "what's true about THIS address" — gate code, parking, pets,
 * building hazards. Critically these live on the address, not the
 * contact: if the customer moves, the gate code shouldn't follow them,
 * and pet info belongs to the home you're visiting.
 *
 * Mirrors the 1.6.4 shape: enums + booleans as denormalized columns,
 * sensitive access codes as short text (masked in the UI, raw in DB),
 * and a free-form `property_notes` textarea. Safe DEFAULTs, nothing
 * touched on existing rows. Idempotent (column_exists guards).
 */
class Handik_Booking_App_Migration_165 {
	public function up() {
		global $wpdb;

		$addresses = Handik_Booking_App_DB::table( 'addresses' );

		$columns = array(
			// Access.
			'building_type'             => "VARCHAR(24) NOT NULL DEFAULT ''",
			'gate_code'                 => "VARCHAR(32) NOT NULL DEFAULT ''",
			'lockbox_code'              => "VARCHAR(32) NOT NULL DEFAULT ''",
			'alarm_code'                => "VARCHAR(32) NOT NULL DEFAULT ''",
			'doorman'                   => 'TINYINT(1) NOT NULL DEFAULT 0',
			'freight_elevator_required' => 'TINYINT(1) NOT NULL DEFAULT 0',
			'freight_elevator_hours'    => "VARCHAR(64) NOT NULL DEFAULT ''",
			'parking'                   => "VARCHAR(24) NOT NULL DEFAULT ''",
			'parking_notes'             => "VARCHAR(255) NOT NULL DEFAULT ''",
			// Pets at this address.
			'pets_present'              => 'TINYINT(1) NOT NULL DEFAULT 0',
			'pets_notes'                => "VARCHAR(255) NOT NULL DEFAULT ''",
			// Hazards / property condition.
			'building_age_class'        => "VARCHAR(32) NOT NULL DEFAULT ''",
			'asbestos_warning'          => 'TINYINT(1) NOT NULL DEFAULT 0',
			'mold_present'              => 'TINYINT(1) NOT NULL DEFAULT 0',
			'hoarding_situation'        => 'TINYINT(1) NOT NULL DEFAULT 0',
			// Free-form.
			'property_notes'            => 'TEXT NULL',
		);

		foreach ( $columns as $name => $definition ) {
			if ( ! Handik_Booking_App_DB::column_exists( $addresses, $name ) ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"ALTER TABLE {$addresses} ADD COLUMN {$name} {$definition}"
				);
			}
		}
	}
}
