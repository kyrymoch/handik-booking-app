<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.4.0 — Additional Booking Forms module.
 *
 * Adds four tables that power the Direct Visit and Project Work Days flows:
 *
 *   - handik_form_presets
 *       Registry of public booking-form presets. Defaults are seeded by
 *       Booking_Presets_Service the first time the table is empty (13 entries:
 *       8 direct visits + 5 project work-day presets).
 *
 *   - handik_direct_booking_requests
 *       One row per direct-visit submission (Standard/Extended/Large Visit
 *       forms). Holds the contact/address pointers, the resolved Cal.com
 *       iframe URL, and Cal booking IDs once the iframe reports onComplete.
 *
 *   - handik_project_scheduling_requests
 *       One row per Project Work Days submission. Tracks the multi-day flow
 *       state (draft → selecting_days → days_selected → creating_bookings →
 *       confirmed | partial_failed | rolled_back).
 *
 *   - handik_project_work_days
 *       N rows per scheduling_request — one per selected day. Stores the Cal
 *       booking UID needed for later cancellation/rollback.
 *
 * Schema notes:
 *   - dbDelta is whitespace-sensitive: two spaces between PRIMARY KEY and
 *     `(id)`, one column per line, no trailing spaces.
 *   - public_token on scheduling_requests is unguessable (32 chars from
 *     wp_generate_password) so customers can return to their schedule from
 *     any device without exposing the numeric ID.
 */
class Handik_Booking_App_Migration_140 {

	public function up() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$presets    = Handik_Booking_App_DB::table( 'form_presets' );
		$direct     = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$schedules  = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$work_days  = Handik_Booking_App_DB::table( 'project_work_days' );

		$presets_sql = "CREATE TABLE {$presets} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			preset_slug VARCHAR(120) NOT NULL DEFAULT '',
			form_title VARCHAR(191) NOT NULL DEFAULT '',
			form_type VARCHAR(40) NOT NULL DEFAULT 'direct_cal_booking',
			booking_type VARCHAR(40) NOT NULL DEFAULT '',
			duration_minutes INT(10) UNSIGNED NOT NULL DEFAULT 0,
			required_days TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			work_day_duration_minutes INT(10) UNSIGNED NOT NULL DEFAULT 0,
			cal_event_url TEXT NULL,
			cal_event_type_id VARCHAR(64) NOT NULL DEFAULT '',
			cal_event_slug VARCHAR(120) NOT NULL DEFAULT '',
			allowed_start_time VARCHAR(16) NOT NULL DEFAULT '',
			allowed_weekdays VARCHAR(40) NOT NULL DEFAULT '',
			confirmation_mode VARCHAR(40) NOT NULL DEFAULT 'pending_alex_confirmation',
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			admin_notes LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY preset_slug_unique (preset_slug),
			KEY form_type_idx (form_type),
			KEY enabled_idx (enabled)
		) {$charset_collate};";

		$direct_sql = "CREATE TABLE {$direct} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			address_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			preset_slug VARCHAR(120) NOT NULL DEFAULT '',
			form_title VARCHAR(191) NOT NULL DEFAULT '',
			booking_type VARCHAR(40) NOT NULL DEFAULT '',
			duration_minutes INT(10) UNSIGNED NOT NULL DEFAULT 0,
			cal_booking_url LONGTEXT NULL,
			cal_booking_id VARCHAR(64) NOT NULL DEFAULT '',
			cal_booking_uid VARCHAR(120) NOT NULL DEFAULT '',
			status VARCHAR(40) NOT NULL DEFAULT 'ready_for_booking',
			source_url TEXT NULL,
			client_type VARCHAR(40) NOT NULL DEFAULT 'new_client',
			client_ip VARBINARY(16) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY contact_id_idx (contact_id),
			KEY preset_slug_idx (preset_slug),
			KEY status_idx (status),
			KEY cal_booking_id_idx (cal_booking_id),
			KEY created_at_idx (created_at)
		) {$charset_collate};";

		$schedules_sql = "CREATE TABLE {$schedules} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			address_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			preset_slug VARCHAR(120) NOT NULL DEFAULT '',
			form_title VARCHAR(191) NOT NULL DEFAULT '',
			required_days TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			work_day_duration_minutes INT(10) UNSIGNED NOT NULL DEFAULT 0,
			cal_event_type_id VARCHAR(64) NOT NULL DEFAULT '',
			cal_event_slug VARCHAR(120) NOT NULL DEFAULT '',
			status VARCHAR(40) NOT NULL DEFAULT 'draft',
			public_token VARCHAR(64) NOT NULL DEFAULT '',
			source_url TEXT NULL,
			client_type VARCHAR(40) NOT NULL DEFAULT 'new_client',
			client_ip VARBINARY(16) NULL,
			client_notes LONGTEXT NULL,
			internal_notes LONGTEXT NULL,
			error_message TEXT NULL,
			confirmed_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY contact_id_idx (contact_id),
			KEY preset_slug_idx (preset_slug),
			KEY status_idx (status),
			KEY public_token_idx (public_token),
			KEY created_at_idx (created_at)
		) {$charset_collate};";

		$work_days_sql = "CREATE TABLE {$work_days} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			scheduling_request_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			day_index TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			start_time DATETIME NULL DEFAULT NULL,
			end_time DATETIME NULL DEFAULT NULL,
			start_iso VARCHAR(40) NOT NULL DEFAULT '',
			end_iso VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(40) NOT NULL DEFAULT 'selected',
			cal_booking_id VARCHAR(64) NOT NULL DEFAULT '',
			cal_booking_uid VARCHAR(120) NOT NULL DEFAULT '',
			cal_booking_url LONGTEXT NULL,
			error_message TEXT NULL,
			client_selected_at DATETIME NULL DEFAULT NULL,
			confirmed_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY scheduling_request_id_idx (scheduling_request_id),
			KEY status_idx (status),
			KEY cal_booking_uid_idx (cal_booking_uid)
		) {$charset_collate};";

		dbDelta( $presets_sql );
		dbDelta( $direct_sql );
		dbDelta( $schedules_sql );
		dbDelta( $work_days_sql );
	}
}
