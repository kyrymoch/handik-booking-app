<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration 1.3.0:
 *  - Adds soft-delete + primary + label columns to addresses.
 *  - Adds is_spam to contacts.
 *  - Adds admin_notes + admin_status_override to bookings (so manual status
 *    survives a Cal.com webhook overwrite).
 *  - Creates the handik_messages table for the real chat transcript (B3).
 */
class Handik_Booking_App_Migration_130 {
	public function up() {
		global $wpdb;

		$contacts  = Handik_Booking_App_DB::table( 'contacts' );
		$addresses = Handik_Booking_App_DB::table( 'addresses' );
		$bookings  = Handik_Booking_App_DB::table( 'bookings' );
		$messages  = Handik_Booking_App_DB::table( 'messages' );

		$alterations = array(
			$contacts => array(
				'is_spam' => "ALTER TABLE {$contacts} ADD COLUMN is_spam TINYINT(1) NOT NULL DEFAULT 0 AFTER is_returning",
			),
			$addresses => array(
				'label'      => "ALTER TABLE {$addresses} ADD COLUMN label VARCHAR(100) NOT NULL DEFAULT '' AFTER address_unit",
				'is_primary' => "ALTER TABLE {$addresses} ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default",
				'deleted_at' => "ALTER TABLE {$addresses} ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER is_primary",
			),
			$bookings => array(
				'admin_notes'           => "ALTER TABLE {$bookings} ADD COLUMN admin_notes LONGTEXT NULL AFTER status",
				'admin_status_override' => "ALTER TABLE {$bookings} ADD COLUMN admin_status_override VARCHAR(32) NULL DEFAULT NULL AFTER admin_notes",
			),
		);

		foreach ( $alterations as $table => $columns ) {
			foreach ( $columns as $column => $sql ) {
				if ( ! Handik_Booking_App_DB::column_exists( $table, $column ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$messages_sql = "CREATE TABLE {$messages} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			thread_id VARCHAR(191) NULL DEFAULT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'user',
			content LONGTEXT NOT NULL,
			metadata LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY request_id_idx (request_id),
			KEY thread_id_idx (thread_id),
			KEY created_at_idx (created_at)
		) {$charset_collate};";

		dbDelta( $messages_sql );
	}
}
