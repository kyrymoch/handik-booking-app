<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Migration_100 {
	public function up() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$contacts     = Handik_Booking_App_DB::table( 'contacts' );
		$addresses    = Handik_Booking_App_DB::table( 'addresses' );
		$job_requests = Handik_Booking_App_DB::table( 'job_requests' );
		$bookings     = Handik_Booking_App_DB::table( 'bookings' );
		$login_tokens = Handik_Booking_App_DB::table( 'login_tokens' );

		$sql = "
		CREATE TABLE {$contacts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			full_name VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NULL,
			phone VARCHAR(50) NULL,
			notes TEXT NULL,
			source VARCHAR(100) NOT NULL DEFAULT 'booking_app',
			is_returning TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY email (email),
			KEY phone (phone)
		) {$charset_collate};

		CREATE TABLE {$addresses} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			address_full TEXT NOT NULL,
			address_line_1 VARCHAR(191) NOT NULL DEFAULT '',
			city VARCHAR(100) NOT NULL DEFAULT '',
			state VARCHAR(50) NOT NULL DEFAULT '',
			zip_code VARCHAR(20) NOT NULL DEFAULT '',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY contact_id (contact_id)
		) {$charset_collate};

		CREATE TABLE {$job_requests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			client_type VARCHAR(50) NOT NULL DEFAULT '',
			job_shape VARCHAR(100) NOT NULL DEFAULT '',
			request_source VARCHAR(100) NOT NULL DEFAULT 'booking_app',
			selected_tasks_json LONGTEXT NULL,
			is_project TINYINT(1) NOT NULL DEFAULT 0,
			address_id BIGINT UNSIGNED NULL,
			address_full TEXT NULL,
			short_description LONGTEXT NULL,
			photos_json LONGTEXT NULL,
			preferred_timeframe VARCHAR(191) NOT NULL DEFAULT '',
			service_family VARCHAR(100) NOT NULL DEFAULT '',
			rate_family VARCHAR(100) NOT NULL DEFAULT '',
			duration_bucket VARCHAR(100) NOT NULL DEFAULT '',
			booking_type VARCHAR(100) NOT NULL DEFAULT '',
			estimate_notes LONGTEXT NULL,
			assistant_summary LONGTEXT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			routing_status VARCHAR(50) NOT NULL DEFAULT 'pending',
			unsafe_flag TINYINT(1) NOT NULL DEFAULT 0,
			unsafe_reason TEXT NULL,
			cal_booking_url TEXT NULL,
			cal_booking_id VARCHAR(191) NULL,
			chat_session_id VARCHAR(191) NULL,
			chat_user_id VARCHAR(191) NULL,
			chat_thread_id VARCHAR(191) NULL,
			draft_token_hash VARCHAR(255) NULL,
			intake_payload_json LONGTEXT NULL,
			assistant_result_json LONGTEXT NULL,
			lookup_verified_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY contact_id (contact_id),
			KEY status (status),
			KEY cal_booking_id (cal_booking_id)
		) {$charset_collate};

		CREATE TABLE {$bookings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_request_id BIGINT UNSIGNED NOT NULL,
			cal_booking_id VARCHAR(191) NOT NULL,
			booking_type VARCHAR(100) NOT NULL DEFAULT '',
			event_type_slug VARCHAR(191) NOT NULL DEFAULT '',
			duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
			start_time DATETIME NULL,
			end_time DATETIME NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'pending',
			raw_webhook_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cal_booking_id (cal_booking_id),
			KEY job_request_id (job_request_id)
		) {$charset_collate};

		CREATE TABLE {$login_tokens} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(191) NULL,
			phone VARCHAR(50) NULL,
			code_hash VARCHAR(255) NOT NULL,
			token_hash VARCHAR(255) NOT NULL,
			expires_at DATETIME NOT NULL,
			consumed_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contact_id (contact_id),
			KEY email (email),
			KEY phone (phone),
			KEY expires_at (expires_at)
		) {$charset_collate};
		";

		dbDelta( $sql );
	}
}
