<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Changelog_Service {
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_entries() {
		return array(
			array(
				'version'      => '2.0.6',
				'date'         => '2026-04-15',
				'title'        => 'Mobile Booking Flow Refresh',
				'notes'        => array(
					'Redesigned the booking flow for mobile-first use, full-width layout, centered headers, tighter task chips, and cleaner progress indicators.',
					'Added Google Maps Places autocomplete support for the single-line job address field.',
					'Embedded the Cal.com booking step in the app and now wait for webhook-confirmed booking status before showing Success.',
				),
			),
			array(
				'version'      => '2.0.5',
				'date'         => '2026-04-15',
				'title'        => 'GitHub Auto-Updates',
				'notes'        => array(
					'Added a built-in GitHub release updater so WordPress can detect plugin updates from the kyrymoch/handik-booking-app repository.',
					'Added private-repository token support, release asset matching, and updater settings in the admin app.',
					'Added release packaging files so each GitHub Release can publish a ready-to-install handik-booking-app.zip asset for WordPress auto-updates.',
				),
			),
			array(
				'version'      => '2.0.4',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Step Flow Fixes',
				'notes'        => array(
					'Removed the welcome screen so the booking app now starts directly at client type selection.',
					'Added assistant-step navigation controls and a manual continue path so the flow no longer blocks when hosted ChatKit does not auto-advance.',
					'Removed short description from the address step and relaxed the assistant ready timeout when the chat is already interactive.',
				),
			),
			array(
				'version'      => '2.0.3',
				'date'         => '2026-04-15',
				'title'        => 'ChatKit Mount Diagnostics',
				'notes'        => array(
					'Adopted the more defensive hosted ChatKit mount flow from the previously working integration, including custom element readiness checks and client secret normalization.',
					'Added frontend-to-backend diagnostic logging for session fetch, mount, ready, thread, effect, message, and error stages.',
					'Expanded the admin logs screen to show serialized context for faster debugging when the assistant stalls.',
				),
			),
			array(
				'version'      => '2.0.2',
				'date'         => '2026-04-15',
				'title'        => 'ChatKit Session Payload Compatibility',
				'notes'        => array(
					'Removed unsupported state_variables from the hosted ChatKit session request after OpenAI returned a 400 unknown parameter error.',
					'Kept draft context associated locally in the plugin response and thread mapping flow while preserving hosted ChatKit.',
					'Bumped plugin assets and runtime version for cache-safe rollout.',
				),
			),
			array(
				'version'      => '2.0.1',
				'date'         => '2026-04-15',
				'title'        => 'ChatKit Session Diagnostics',
				'notes'        => array(
					'Improved hosted ChatKit session error reporting so upstream OpenAI messages are returned instead of a generic 502.',
					'Added OpenAI Project ID and Organization ID settings for project-scoped workflow access.',
					'Adjusted the returning-client wizard back navigation to stay inside the correct single-page flow.',
				),
			),
			array(
				'version'      => '2.0.0',
				'date'         => '2026-04-14',
				'title'        => 'Booking App Rebuild',
				'notes'        => array(
					'Converted the plugin into a standalone booking application with shortcode and Elementor widget embedding.',
					'Added app-owned step engine, hosted ChatKit assistant step, and top-level admin app.',
					'Retained local CRM, routing, Cal.com booking, and returning-client verification as internal services.',
				),
			),
			array(
				'version'      => '1.1.0',
				'date'         => '2026-04-14',
				'title'        => 'Migration Framework',
				'notes'        => array(
					'Added versioned migration support for future schema changes.',
					'Expanded draft requests with app step and app state tracking.',
				),
			),
		);
	}
}
