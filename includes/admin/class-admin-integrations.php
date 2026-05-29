<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations & Logs & Changelog page (renamed from "Operations").
 *
 * Three tabs:
 *  - integrations: vendor credentials only (Cal.com lives in App Setup now per D3).
 *  - logs: rendered by Handik_Booking_App_Admin_Logs.
 *  - changelog: read-only release notes.
 */
class Handik_Booking_App_Admin_Integrations {

	/** @var Handik_Booking_App_Settings */
	protected $settings;
	/** @var Handik_Booking_App_Changelog_Service */
	protected $changelog;
	/** @var Handik_Booking_App_Admin_Logs */
	protected $logs_page;

	public function __construct( $settings, $changelog, $logs_page ) {
		$this->settings  = $settings;
		$this->changelog = $changelog;
		$this->logs_page = $logs_page;
	}

	public function render() {
		$tab = Handik_Booking_App_Admin_Helpers::current_tab( array( 'integrations', 'logs', 'changelog' ), 'integrations' );

		Handik_Booking_App_Admin_Helpers::page_start( __( 'Integrations, Logs & Changelog', 'handik-booking-app' ) );
		settings_errors( 'handik-booking-app' );

		echo Handik_Booking_App_Admin_Helpers::tabs_markup(
			array(
				'integrations' => __( 'Integrations', 'handik-booking-app' ),
				'logs'         => __( 'Logs', 'handik-booking-app' ),
				'changelog'    => __( 'Changelog', 'handik-booking-app' ),
			),
			$tab,
			'handik-booking-app-operations'
		);

		switch ( $tab ) {
			case 'logs':
				if ( $this->logs_page ) {
					$this->logs_page->render();
				}
				return; // logs already calls page_end
			case 'changelog':
				$this->render_changelog();
				break;
			default:
				$this->render_integrations_form();
		}

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function render_changelog() {
		echo '<div class="handik-admin-block">';
		foreach ( $this->changelog->get_entries() as $entry ) {
			echo '<div class="handik-admin-changelog-entry">';
			echo '<h3>' . esc_html( ( $entry['title'] ?? '' ) . ' ' . ( $entry['version'] ?? '' ) ) . '</h3>';
			if ( ! empty( $entry['date'] ) ) {
				echo '<p class="handik-admin-muted">' . esc_html( (string) $entry['date'] ) . '</p>';
			}
			echo '<ul>';
			foreach ( ( $entry['notes'] ?? array() ) as $note ) {
				echo '<li>' . esc_html( (string) $note ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '</div>';
	}

	protected function render_integrations_form() {
		// Sprint 8: integration credentials gated behind a separate cap so
		// a bookings-only admin doesn't see (or accidentally rotate) API
		// keys for OpenAI / Twilio / GitHub / Google Maps. The Operations
		// page itself stays accessible (Logs + Changelog tabs are useful
		// to a bookings admin), but this tab shows a notice instead of
		// the form when the user lacks the wider cap.
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_INTEGRATIONS ) ) {
			// Sprint 11 fix: actually name the cap key so the
			// administrator knows what to grant. Was P2 — the prior
			// copy said "Manage Handik integrations" (the human label)
			// without the underlying cap string, leaving the admin to
			// guess what to type into a role-management UI.
			$cap_key = Handik_Booking_App_Capabilities::MANAGE_INTEGRATIONS;
			echo '<section class="handik-admin-block">';
			echo '<h2 class="handik-admin-section-title">' . esc_html__( 'Integrations', 'handik-booking-app' ) . '</h2>';
			echo '<p>' . esc_html__( 'You don’t have permission to manage integration credentials.', 'handik-booking-app' ) . '</p>';
			echo '<p>' . sprintf(
				/* translators: %s: capability slug, displayed as <code>. */
				esc_html__( 'Ask a site administrator to grant you the %s capability (via a role-management plugin or by adding it to your role with WP-CLI).', 'handik-booking-app' ),
				'<code>' . esc_html( $cap_key ) . '</code>'
			) . '</p>';
			echo '</section>';
			return;
		}
		// Sprint 5 — integrations config now also lives under Settings →
		// Integrations. Point operators there; this tab stays for backward
		// compatibility.
		$settings_integrations_url = add_query_arg(
			array( 'page' => 'handik-booking-app-settings', 'tab' => 'integrations' ),
			admin_url( 'admin.php' )
		);
		echo '<div class="notice notice-info inline" style="margin:12px 0"><p>'
			. esc_html__( 'Integration credentials have a new home under', 'handik-booking-app' )
			. ' <a href="' . esc_url( $settings_integrations_url ) . '">' . esc_html__( 'Settings → Integrations', 'handik-booking-app' ) . '</a>. '
			. esc_html__( 'This tab still works but will be retired in a future update.', 'handik-booking-app' )
			. '</p></div>';
		$s = $this->settings->all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' ); ?>

			<section class="handik-admin-block">
				<h2 class="handik-admin-section-title">OpenAI</h2>
				<div class="handik-admin-grid">
					<?php Handik_Booking_App_Admin_Helpers::field( 'openai_api_key', __( 'API Key', 'handik-booking-app' ), $s['openai_api_key'], 'password' ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'openai_workflow_id', __( 'Workflow ID', 'handik-booking-app' ), $s['openai_workflow_id'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'openai_api_base', __( 'API base', 'handik-booking-app' ), $s['openai_api_base'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'openai_project_id', __( 'Project ID', 'handik-booking-app' ), $s['openai_project_id'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'openai_organization_id', __( 'Organization ID', 'handik-booking-app' ), $s['openai_organization_id'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'chatkit_script_url', __( 'Custom ChatKit bridge URL', 'handik-booking-app' ), $s['chatkit_script_url'] ); ?>
				</div>
			</section>

			<section class="handik-admin-block">
				<h2 class="handik-admin-section-title">Google Maps</h2>
				<div class="handik-admin-grid">
					<?php Handik_Booking_App_Admin_Helpers::field( 'google_maps_api_key', __( 'API key', 'handik-booking-app' ), $s['google_maps_api_key'], 'password' ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'google_maps_country', __( 'Country', 'handik-booking-app' ), $s['google_maps_country'] ); ?>
				</div>
			</section>

			<section class="handik-admin-block">
				<h2 class="handik-admin-section-title">Twilio</h2>
				<div class="handik-admin-grid">
					<?php Handik_Booking_App_Admin_Helpers::field( 'twilio_account_sid', __( 'Account SID', 'handik-booking-app' ), $s['twilio_account_sid'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'twilio_auth_token', __( 'Auth token', 'handik-booking-app' ), $s['twilio_auth_token'], 'password' ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'twilio_verify_service_sid', __( 'Verify service SID', 'handik-booking-app' ), $s['twilio_verify_service_sid'] ); ?>
				</div>
			</section>

			<section class="handik-admin-block">
				<h2 class="handik-admin-section-title">GitHub (plugin updates)</h2>
				<div class="handik-admin-grid">
					<?php Handik_Booking_App_Admin_Helpers::field( 'github_repo_url', __( 'Repo URL', 'handik-booking-app' ), $s['github_repo_url'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'github_repo_branch', __( 'Release branch', 'handik-booking-app' ), $s['github_repo_branch'] ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'github_access_token', __( 'Access token', 'handik-booking-app' ), $s['github_access_token'], 'password' ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'github_release_asset_pattern', __( 'Release asset pattern', 'handik-booking-app' ), $s['github_release_asset_pattern'] ); ?>
				</div>
			</section>

			<section class="handik-admin-block">
				<h2 class="handik-admin-section-title"><?php esc_html_e( 'Embedding', 'handik-booking-app' ); ?></h2>
				<p><?php esc_html_e( 'Shortcode:', 'handik-booking-app' ); ?> <code>[handik_booking_app]</code></p>
				<p><?php esc_html_e( 'Elementor widget:', 'handik-booking-app' ); ?> Handik Booking App</p>
				<p><?php esc_html_e( 'Cal.com config moved to App Setup → Cal.com.', 'handik-booking-app' ); ?></p>
			</section>

			<?php submit_button( __( 'Save integrations', 'handik-booking-app' ) ); ?>
		</form>
		<?php
	}
}
