<?php
/**
 * Plugin Name: Handik Booking App
 * Plugin URI: https://handik.pro/
 * Description: Single-page booking application for Handik with CRM, hosted ChatKit, returning-client auth, and Cal.com orchestration.
 * Version: 2.0.31
 * Author: Handik
 * Author URI: https://handik.pro/
 * Text Domain: handik-booking-app
 * Update URI: https://github.com/kyrymoch/handik-booking-app
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HANDIK_BOOKING_APP_VERSION', '2.0.31' );
define( 'HANDIK_BOOKING_APP_DB_VERSION', '1.2.0' );
define( 'HANDIK_BOOKING_APP_FILE', __FILE__ );
define( 'HANDIK_BOOKING_APP_PATH', plugin_dir_path( __FILE__ ) );
define( 'HANDIK_BOOKING_APP_URL', plugin_dir_url( __FILE__ ) );
define( 'HANDIK_BOOKING_APP_OPTION', 'handik_booking_app_settings' );

require_once HANDIK_BOOKING_APP_PATH . 'includes/class-loader.php';
Handik_Booking_App_Loader::load();

register_activation_hook( __FILE__, array( 'Handik_Booking_App_DB', 'activate' ) );

function handik_booking_app() {
	return Handik_Booking_App_Plugin::instance();
}

add_action( 'plugins_loaded', 'handik_booking_app' );
