<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'handik_booking_app_settings' );
delete_option( 'handik_booking_app_db_version' );
