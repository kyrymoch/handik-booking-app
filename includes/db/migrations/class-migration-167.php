<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.7 — per-booking money fields (Sprint 10).
 *
 * Closes the loop with the operator's tax/accounting workflow: each
 * booking can record what was actually charged, materials cost, payment
 * method + status, an invoice number, and miles driven. The Reports page
 * aggregates these over a period (quarter / year / custom) into gross
 * revenue, materials, mileage deduction, and net pre-tax.
 *
 * Amounts are stored in integer cents (no float drift); mileage as a
 * small decimal. Mileage is a manual field for now — automatic
 * Distance-Matrix estimation needs geocoded address coordinates the
 * plugin doesn't store yet (same gap noted in Sprint 9). Safe DEFAULTs,
 * idempotent (column_exists guards).
 */
class Handik_Booking_App_Migration_167 {
	public function up() {
		global $wpdb;

		$bookings = Handik_Booking_App_DB::table( 'bookings' );

		$columns = array(
			'actual_amount_cents'   => 'INT NULL DEFAULT NULL',
			'materials_amount_cents' => 'INT NULL DEFAULT NULL',
			'payment_status'        => "VARCHAR(16) NOT NULL DEFAULT ''",
			'payment_method_used'   => "VARCHAR(16) NOT NULL DEFAULT ''",
			'invoice_number'        => "VARCHAR(64) NOT NULL DEFAULT ''",
			'mileage_miles'         => 'DECIMAL(6,1) NULL DEFAULT NULL',
		);
		foreach ( $columns as $name => $definition ) {
			if ( ! Handik_Booking_App_DB::column_exists( $bookings, $name ) ) {
				$wpdb->query( "ALTER TABLE {$bookings} ADD COLUMN {$name} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( ! Handik_Booking_App_DB::index_exists( $bookings, 'idx_payment_status' ) ) {
			$wpdb->query( "ALTER TABLE {$bookings} ADD KEY idx_payment_status (payment_status)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
