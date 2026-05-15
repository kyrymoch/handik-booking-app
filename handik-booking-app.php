<?php
/**
 * Plugin Name: Handik Booking App
 * Plugin URI: https://handik.pro/
 * Description: Single-page booking application for Handik with CRM, hosted ChatKit, silent returning-client recognition, and Cal.com orchestration.
 * Version: 2.1.26.3
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

define( 'HANDIK_BOOKING_APP_VERSION', '2.1.26.3' );
define( 'HANDIK_BOOKING_APP_DB_VERSION', '1.6.1' );
define( 'HANDIK_BOOKING_APP_FILE', __FILE__ );
define( 'HANDIK_BOOKING_APP_PATH', plugin_dir_path( __FILE__ ) );
define( 'HANDIK_BOOKING_APP_URL', plugin_dir_url( __FILE__ ) );
define( 'HANDIK_BOOKING_APP_OPTION', 'handik_booking_app_settings' );

require_once HANDIK_BOOKING_APP_PATH . 'includes/class-loader.php';
Handik_Booking_App_Loader::load();

register_activation_hook( __FILE__, array( 'Handik_Booking_App_DB', 'activate' ) );
// Sprint 8 — register the new handik_manage_bookings / handik_manage_integrations
// caps on the administrator role permanently so they survive the plugin
// reactivation cycle, then attach the runtime user_has_cap filter so
// existing admins (manage_options-holders) keep transparent access.
register_activation_hook( __FILE__, array( 'Handik_Booking_App_Capabilities', 'activate' ) );
add_action( 'init', array( 'Handik_Booking_App_Capabilities', 'init' ), 1 );
// Sprint 8 — wp_loaded heartbeat that fires overdue handik_* events when
// WP cron is suppressed (DISABLE_WP_CRON). No-op otherwise.
add_action( 'init', array( 'Handik_Booking_App_Cron_Fallback', 'init' ), 1 );

function handik_booking_app() {
	return Handik_Booking_App_Plugin::instance();
}

add_action( 'plugins_loaded', 'handik_booking_app' );
