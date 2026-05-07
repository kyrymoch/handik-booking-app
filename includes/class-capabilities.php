<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprint 8 — capability split (P2 from the v2.1.11.1 QA report).
 *
 * Before this class, every admin path checked `manage_options`. That meant a
 * helper editor who needed to manage bookings inevitably also got the keys
 * to OpenAI / Twilio / GitHub / Google Maps because those rotate behind the
 * same gate. The split:
 *
 *   handik_manage_bookings      Dashboard, Bookings, People, Setup,
 *                                System, Logs, Additional Forms — all the
 *                                day-to-day operational surface.
 *
 *   handik_manage_integrations  The Integrations tab on the Operations
 *                                page only (OpenAI / Twilio / GitHub /
 *                                Google Maps API keys + Cal.com webhook
 *                                shared secret).
 *
 * Backwards compatibility: the `user_has_cap` filter below grants both new
 * caps to anyone who already holds `manage_options`, so existing site
 * admins keep working without manual setup. New roles created later can
 * be granted just one of the two for a narrower handover.
 */
class Handik_Booking_App_Capabilities {

	const MANAGE_BOOKINGS     = 'handik_manage_bookings';
	const MANAGE_INTEGRATIONS = 'handik_manage_integrations';

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_implicit_caps' ), 10, 4 );
	}

	/**
	 * Grant both Handik caps to any user who already has `manage_options`.
	 * This keeps the admin who installed the plugin working without
	 * touching the database, and means the cap split is a pure additive
	 * change — no data migration, no role surgery.
	 *
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param WP_User             $user
	 * @return array<string, bool>
	 */
	public static function grant_implicit_caps( $allcaps, $caps, $args, $user ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::MANAGE_BOOKINGS ]     = true;
			$allcaps[ self::MANAGE_INTEGRATIONS ] = true;
		}
		return $allcaps;
	}

	/**
	 * Activation hook — adds both caps to the administrator role
	 * permanently, so the grant survives the plugin being deactivated +
	 * removed from `manage_options` (rare but possible). Safe to call
	 * repeatedly: `add_cap` is idempotent.
	 */
	public static function activate() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::MANAGE_BOOKINGS );
			$role->add_cap( self::MANAGE_INTEGRATIONS );
		}
	}
}
