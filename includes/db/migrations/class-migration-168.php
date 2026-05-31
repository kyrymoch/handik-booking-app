<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.6.8 — birthday + small operator-facing columns (Sprint 11).
 *
 * Adds:
 *   handik_contacts.birthday  DATE NULL  — birthday/anniversary field
 *     (operator-facing retention reminder; the year is meaningful for
 *     anniversaries but the value is just a date — empty when unknown).
 *
 * Safe DEFAULT NULL, idempotent.
 */
class Handik_Booking_App_Migration_168 {
	public function up() {
		global $wpdb;
		$contacts = Handik_Booking_App_DB::table( 'contacts' );
		if ( ! Handik_Booking_App_DB::column_exists( $contacts, 'birthday' ) ) {
			$wpdb->query( "ALTER TABLE {$contacts} ADD COLUMN birthday DATE NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
