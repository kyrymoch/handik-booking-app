<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin coordinator for the Handik admin area.
 *
 * Each admin page lives in its own class under includes/admin/. This file:
 *   - registers the WP admin menu,
 *   - dispatches each page slug to the right renderer,
 *   - handles the legacy form-POST settings save for non-AJAX tabs,
 *   - enqueues admin CSS/JS and localizes data.
 *
 * Bottom-nav, sticky bars, modals, toasts and the SortableJS catalog live
 * in assets/booking-app-admin.js.
 */
class Handik_Booking_App_Admin {
	/** @var Handik_Booking_App_Settings */
	protected $settings;
	/** @var Handik_Booking_App_Assets */
	protected $assets;
	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Logger */
	protected $logger;
	/** @var Handik_Booking_App_Changelog_Service */
	protected $changelog;
	/** @var Handik_Booking_App_Service_Catalog_Service */
	protected $service_catalog;
	/** @var Handik_Booking_App_Messages_Service|null */
	protected $messages;
	/** @var Handik_Booking_App_Admin_Additional_Forms|null */
	protected $page_additional_forms;
	/** @var Handik_Booking_App_Booking_Presets_Service|null */
	protected $booking_presets;

	// Page renderers (lazy-instantiated in render_*).
	/** @var Handik_Booking_App_Admin_Dashboard|null */
	protected $page_dashboard;
	/** @var Handik_Booking_App_Admin_Bookings|null */
	protected $page_bookings;
	/** @var Handik_Booking_App_Admin_People|null */
	protected $page_people;
	/** @var Handik_Booking_App_Admin_Settings|null */
	protected $page_settings;
	/** @var Handik_Booking_App_Admin_System|null */
	protected $page_system;
	/** @var Handik_Booking_App_Admin_Integrations|null */
	protected $page_integrations;
	/** @var Handik_Booking_App_Admin_Logs|null */
	protected $page_logs;

	public function __construct( $settings, $assets, $contacts, $addresses, $job_requests, $bookings, $logger, $changelog, $service_catalog, $messages = null, $additional_forms = null, $booking_presets = null ) {
		$this->settings        = $settings;
		$this->assets          = $assets;
		$this->contacts        = $contacts;
		$this->addresses       = $addresses;
		$this->job_requests    = $job_requests;
		$this->bookings        = $bookings;
		$this->logger          = $logger;
		$this->changelog       = $changelog;
		$this->service_catalog = $service_catalog;
		$this->messages        = $messages;
		$this->page_additional_forms = $additional_forms;
		// Sprint 13 — passed through to Admin_Bookings so the Add
		// Booking page can populate the preset picker with currently-
		// enabled direct-cal-booking presets.
		$this->booking_presets = $booking_presets;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_footer', array( $this, 'render_bottom_nav' ) );

		// Logs page hooks admin_init for CSV export — instantiate it eagerly so
		// the hook is registered.
		$this->logs_page();
	}

	// =====================================================================
	// Menu
	// =====================================================================

	public function register_menu() {
		// Sprint 8: capability split. The booking-side menus all use the
		// narrower `handik_manage_bookings` cap so an editor can be granted
		// day-to-day operations without inheriting access to API keys.
		// `manage_options` users still see everything because the
		// `user_has_cap` filter in Handik_Booking_App_Capabilities grants
		// both new caps to anyone holding `manage_options`.
		$cap = Handik_Booking_App_Capabilities::MANAGE_BOOKINGS;

		add_menu_page(
			__( 'Handik Booking', 'handik-booking-app' ),
			__( 'Handik Booking', 'handik-booking-app' ),
			$cap,
			'handik-booking-app',
			array( $this, 'render_dashboard' ),
			'dashicons-calendar-alt',
			26
		);
		add_submenu_page( 'handik-booking-app', __( 'Dashboard', 'handik-booking-app' ), __( 'Dashboard', 'handik-booking-app' ), $cap, 'handik-booking-app', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'handik-booking-app', __( 'Bookings', 'handik-booking-app' ), __( 'Bookings', 'handik-booking-app' ), $cap, 'handik-booking-app-bookings', array( $this, 'render_bookings' ) );
		add_submenu_page( 'handik-booking-app', __( 'People & Requests', 'handik-booking-app' ), __( 'People', 'handik-booking-app' ), $cap, 'handik-booking-app-crm', array( $this, 'render_people' ) );
		add_submenu_page( 'handik-booking-app', __( 'App Setup', 'handik-booking-app' ), __( 'Setup', 'handik-booking-app' ), $cap, 'handik-booking-app-settings', array( $this, 'render_settings' ) );
		if ( $this->page_additional_forms ) {
			add_submenu_page( 'handik-booking-app', __( 'Additional Forms', 'handik-booking-app' ), __( 'Additional Forms', 'handik-booking-app' ), $cap, Handik_Booking_App_Admin_Additional_Forms::PAGE_SLUG, array( $this, 'render_additional_forms' ) );
		}
		add_submenu_page( 'handik-booking-app', __( 'Integrations & Logs', 'handik-booking-app' ), __( 'Logs', 'handik-booking-app' ), $cap, 'handik-booking-app-operations', array( $this, 'render_operations' ) );
		add_submenu_page( 'handik-booking-app', __( 'System info', 'handik-booking-app' ), __( 'System', 'handik-booking-app' ), $cap, 'handik-booking-app-system', array( $this, 'render_system' ) );
	}

	// =====================================================================
	// Form-post settings handler (covers all submit-button settings tabs)
	// =====================================================================

	public function maybe_save_settings() {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		if ( empty( $_POST['handik_booking_app_settings_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' );

		// Sprint 14a — "Send test email" button on the Notifications tab
		// shares the settings form (so unsaved template edits get included
		// in the preview) but is its own action: we ship a sample-data
		// email to the current admin and DON'T persist the form. Keeps
		// "Save" and "Send test" mutually exclusive — one click, one
		// outcome.
		$action = isset( $_POST['handik_action'] ) ? sanitize_key( wp_unslash( $_POST['handik_action'] ) ) : '';
		// Sprint 14a: send_test_email (customer booking confirmation).
		// Sprint 14b: send_test_owner_email (owner booking notification).
		// Sprint 14c: send_test_customer_cancellation, send_test_customer_reschedule,
		//             send_test_owner_cancellation, send_test_owner_reschedule.
		$test_action_map = array(
			'send_test_email'                  => 'customer',
			'send_test_owner_email'            => 'owner',
			'send_test_customer_cancellation'  => 'customer_cancellation',
			'send_test_customer_reschedule'    => 'customer_reschedule',
			'send_test_owner_cancellation'     => 'owner_cancellation',
			'send_test_owner_reschedule'       => 'owner_reschedule',
		);
		if ( isset( $test_action_map[ $action ] ) ) {
			$this->handle_send_test_email( wp_unslash( $_POST ), $test_action_map[ $action ] );
			return;
		}

		// 2.1.22.1 — Reset-to-default for a single email-template field.
		// Each Reset button posts `handik_action=reset_template_<key>`;
		// the key portion is parsed out and allow-listed against the
		// resettable-keys map inside handle_reset_template so a forged
		// action value can't reset arbitrary settings.
		if ( 0 === strpos( $action, 'reset_template_' ) ) {
			$this->handle_reset_template( substr( $action, strlen( 'reset_template_' ) ) );
			return;
		}

		// Sprint 8 (hotfix 2.1.15.1): if the submission contains any
		// integration-secret fields (API keys, auth tokens, Cal webhook
		// shared secret, Cal.com API credentials), the user must also
		// hold MANAGE_INTEGRATIONS or those values are stripped before
		// the settings update — otherwise the rest of the form (booking
		// flow, appearance, service catalog) saves normally.
		//
		// 2.1.15.1 P0 audit fix: list expanded to include cal_api_key /
		// cal_api_base / cal_api_version / cal_api_timezone. Those live
		// on App Setup → Cal.com (not the Integrations tab) but they ARE
		// rotating credentials — a MANAGE_BOOKINGS-only user could otherwise
		// craft a POST that swapped the Cal.com API key.
		$payload = wp_unslash( $_POST );
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_INTEGRATIONS ) ) {
			$integration_keys = array(
				'openai_api_key', 'openai_workflow_id', 'openai_api_base',
				'openai_project_id', 'openai_organization_id', 'chatkit_script_url',
				'google_maps_api_key', 'google_maps_country',
				'twilio_account_sid', 'twilio_auth_token', 'twilio_verify_service_sid',
				'github_repo_url', 'github_repo_branch', 'github_access_token',
				'github_release_asset_pattern',
				'cal_webhook_secret', 'cal_api_key', 'cal_api_base',
				'cal_api_version', 'cal_api_timezone',
			);
			$dropped = false;
			foreach ( $integration_keys as $key ) {
				if ( isset( $payload[ $key ] ) ) {
					unset( $payload[ $key ] );
					$dropped = true;
				}
			}
			if ( $dropped ) {
				add_settings_error(
					'handik-booking-app',
					'integrations_blocked',
					__( 'Integration credentials were ignored — that section needs the “Manage integrations” permission.', 'handik-booking-app' ),
					'warning'
				);
			}
		}

		$this->settings->update( $payload );
		add_settings_error( 'handik-booking-app', 'settings_saved', __( 'Settings updated.', 'handik-booking-app' ), 'updated' );
	}

	/**
	 * Sprint 14a/14b — handle the Notifications tab "Send test email" submit.
	 * Uses unsaved POST template values so the operator can preview edits
	 * before clicking Save. Does not persist anything; bypasses the
	 * master toggle (the whole point is preview before flipping it).
	 *
	 * @param array<string, mixed> $payload Unslashed POST data.
	 * @param string               $which   'customer' or 'owner' — which side to preview.
	 * @return void
	 */
	protected function handle_send_test_email( array $payload, $which = 'customer' ) {
		$plugin = handik_booking_app();
		if ( ! $plugin || empty( $plugin->notifications ) ) {
			add_settings_error(
				'handik-booking-app',
				'test_email_failed',
				__( 'Notifications service is not available.', 'handik-booking-app' )
			);
			return;
		}

		// 2.1.21.3 — recipient resolution order:
		//   1. unsaved POST value of `notification_test_recipient` from the
		//      same form submission (so an operator can type a one-off
		//      address and click Send Test without saving first);
		//   2. saved `notification_test_recipient` setting;
		//   3. WordPress account email of the logged-in user.
		// Each step needs sanitize_email — empty/invalid falls through.
		$to = '';
		if ( array_key_exists( 'notification_test_recipient', $payload ) ) {
			$to = sanitize_email( (string) $payload['notification_test_recipient'] );
		}
		if ( '' === $to ) {
			$to = sanitize_email( (string) $this->settings->get( 'notification_test_recipient', '' ) );
		}
		if ( '' === $to ) {
			$user = wp_get_current_user();
			$to   = sanitize_email( (string) $user->user_email );
		}
		if ( '' === $to ) {
			add_settings_error(
				'handik-booking-app',
				'test_email_failed',
				__( 'No valid test recipient — fill the "Send test emails to" field on the Notifications tab, or set an email on your WordPress profile.', 'handik-booking-app' )
			);
			return;
		}

		// Sprint 14c — extended to 6 sides (booked + cancellation +
		// reschedule, customer + owner). Each side has its own slice of
		// settings keys; we collect only the relevant ones from the
		// POST so a stray submitted field can't end up in the wrong
		// template's override.
		$override_keys_by_side = array(
			'customer'               => array(
				'customer_confirmation_subject',
				'customer_confirmation_body_html',
				'customer_confirmation_body_text',
				'customer_confirmation_reply_to',
			),
			'owner'                  => array( 'owner_notification_subject', 'owner_notification_body' ),
			'customer_cancellation'  => array(
				'customer_cancellation_subject',
				'customer_cancellation_body_html',
				'customer_cancellation_body_text',
			),
			'customer_reschedule'    => array(
				'customer_reschedule_subject',
				'customer_reschedule_body_html',
				'customer_reschedule_body_text',
			),
			'owner_cancellation'     => array( 'owner_cancellation_subject', 'owner_cancellation_body' ),
			'owner_reschedule'       => array( 'owner_reschedule_subject', 'owner_reschedule_body' ),
		);
		$keys_for_side = $override_keys_by_side[ $which ] ?? $override_keys_by_side['customer'];
		$html_keys     = array( 'customer_confirmation_body_html', 'customer_cancellation_body_html', 'customer_reschedule_body_html' );
		$email_keys    = array( 'customer_confirmation_reply_to' );

		$overrides = array();
		foreach ( $keys_for_side as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}
			if ( in_array( $key, $html_keys, true ) ) {
				$overrides[ $key ] = wp_kses_post( (string) $payload[ $key ] );
			} elseif ( in_array( $key, $email_keys, true ) ) {
				$overrides[ $key ] = sanitize_email( (string) $payload[ $key ] );
			} else {
				$overrides[ $key ] = trim( str_replace( "\0", '', (string) $payload[ $key ] ) );
			}
		}

		$result = $plugin->notifications->send_test( $to, $overrides, $which );
		if ( ! empty( $result['sent'] ) ) {
			add_settings_error(
				'handik-booking-app',
				'test_email_sent',
				/* translators: 1: which side ("customer"/"owner"), 2: recipient email address. */
				sprintf(
					__( 'Test %1$s email sent — check %2$s.', 'handik-booking-app' ),
					'owner' === $which ? __( 'owner', 'handik-booking-app' ) : __( 'customer', 'handik-booking-app' ),
					$result['recipient']
				),
				'updated'
			);
		} else {
			add_settings_error(
				'handik-booking-app',
				'test_email_failed',
				__( 'Test email failed to send. See Logs for details.', 'handik-booking-app' )
			);
		}
	}

	/**
	 * 2.1.22.1 — handle the "Reset to default" button on the
	 * Notifications tab. The user reports their saved
	 * customer_confirmation_body_html is the OLD pre-2.1.21.4 default
	 * (just `<p>Hi …` paragraphs); since defaults only land on fresh
	 * activation, the cleanest path is a button that explicitly
	 * overwrites the saved value with the new default.
	 *
	 * Allow-list of resettable keys defends against a forged form
	 * resetting e.g. `cal_webhook_secret` to its empty default.
	 *
	 * @param string $key Setting-key suffix from `handik_action=reset_template_<key>`.
	 * @return void
	 */
	protected function handle_reset_template( $key ) {
		$resettable = array(
			'customer_confirmation_subject',
			'customer_confirmation_body_html',
			'customer_confirmation_body_text',
			'customer_cancellation_subject',
			'customer_cancellation_body_html',
			'customer_cancellation_body_text',
			'customer_reschedule_subject',
			'customer_reschedule_body_html',
			'customer_reschedule_body_text',
			'owner_notification_subject',
			'owner_notification_body',
			'owner_cancellation_subject',
			'owner_cancellation_body',
			'owner_reschedule_subject',
			'owner_reschedule_body',
			'magic_link_email_subject',
			'magic_link_email_body',
		);

		$key = sanitize_key( (string) $key );
		if ( ! in_array( $key, $resettable, true ) ) {
			add_settings_error(
				'handik-booking-app',
				'reset_template_invalid',
				__( 'Cannot reset that field — unknown key.', 'handik-booking-app' )
			);
			return;
		}

		$ok = $this->settings->reset_to_default( $key );
		if ( $ok ) {
			add_settings_error(
				'handik-booking-app',
				'reset_template_ok',
				/* translators: %s: setting key (e.g. customer_confirmation_body_html). */
				sprintf( __( 'Reset %s to the bundled default.', 'handik-booking-app' ), $key ),
				'updated'
			);
		} else {
			add_settings_error(
				'handik-booking-app',
				'reset_template_failed',
				__( 'Reset failed — see Logs for details.', 'handik-booking-app' )
			);
		}
	}

	// =====================================================================
	// Assets
	// =====================================================================

	public function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'handik-booking-app' ) ) {
			return;
		}
		$this->assets->enqueue_admin();

		// SortableJS for the catalog editor — pinned to 1.15.6.
		wp_enqueue_script(
			'handik-sortablejs',
			'https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js',
			array(),
			'1.15.6',
			true
		);

		wp_localize_script(
			'handik-booking-app-admin',
			'HandikAdmin',
			array(
				'restBase'  => esc_url_raw( trailingslashit( rest_url( 'handik-booking-app/v1' ) ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'page'      => $page,
				'i18n'      => array(
					'saved'        => __( 'Saved', 'handik-booking-app' ),
					'saveFailed'   => __( 'Could not save. Try again.', 'handik-booking-app' ),
					'confirmDelete'=> __( 'Delete this? This cannot be undone.', 'handik-booking-app' ),
					'confirmCancel'=> __( 'Mark this booking as cancelled?', 'handik-booking-app' ),
					'confirmComplete' => __( 'Mark this booking as completed?', 'handik-booking-app' ),
					'noteTitle'    => __( 'Add a private note', 'handik-booking-app' ),
					'cancelTitle'  => __( 'Cancel booking', 'handik-booking-app' ),
					'completeTitle'=> __( 'Mark completed', 'handik-booking-app' ),
					'placeholder'  => __( 'Type here…', 'handik-booking-app' ),
					'save'         => __( 'Save', 'handik-booking-app' ),
					'cancel'       => __( 'Cancel', 'handik-booking-app' ),
					'confirm'      => __( 'Confirm', 'handik-booking-app' ),
					'copied'       => __( 'Copied', 'handik-booking-app' ),
					'addressEdit'  => __( 'Edit address', 'handik-booking-app' ),
				),
			)
		);
	}

	// =====================================================================
	// Page dispatchers
	// =====================================================================

	public function render_dashboard() {
		$page = $this->page_dashboard ?: ( $this->page_dashboard = new Handik_Booking_App_Admin_Dashboard(
			$this->bookings,
			$this->job_requests,
			$this->contacts,
			$this->addresses,
			$this->service_catalog,
			$this->logger,
			$this->changelog
		) );
		$page->render();
	}

	public function render_bookings() {
		$page = $this->page_bookings ?: ( $this->page_bookings = new Handik_Booking_App_Admin_Bookings(
			$this->bookings,
			$this->job_requests,
			$this->contacts,
			$this->addresses,
			$this->service_catalog,
			$this->logger,
			$this->messages,
			$this->booking_presets
		) );
		$page->render();
	}

	public function render_people() {
		$page = $this->page_people ?: ( $this->page_people = new Handik_Booking_App_Admin_People(
			$this->contacts,
			$this->addresses,
			$this->job_requests,
			$this->bookings,
			$this->service_catalog,
			$this->messages,
			$this->logger
		) );
		$page->render();
	}

	public function render_settings() {
		$page = $this->page_settings ?: ( $this->page_settings = new Handik_Booking_App_Admin_Settings( $this->settings, $this->service_catalog, $this->job_requests ) );
		$page->render();
	}

	public function render_operations() {
		$page = $this->page_integrations ?: ( $this->page_integrations = new Handik_Booking_App_Admin_Integrations(
			$this->settings,
			$this->changelog,
			$this->logs_page()
		) );
		$page->render();
	}

	public function render_additional_forms() {
		if ( ! $this->page_additional_forms ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Additional Forms module is not loaded.', 'handik-booking-app' ) . '</p></div>';
			return;
		}
		$this->page_additional_forms->render();
	}

	public function render_system() {
		$page = $this->page_system ?: ( $this->page_system = new Handik_Booking_App_Admin_System(
			$this->settings,
			$this->job_requests,
			$this->bookings,
			$this->contacts,
			$this->addresses,
			$this->messages
		) );
		$page->render();
	}

	protected function logs_page() {
		if ( ! $this->page_logs ) {
			$this->page_logs = new Handik_Booking_App_Admin_Logs( $this->logger, $this->job_requests, $this->bookings );
		}
		return $this->page_logs;
	}

	// =====================================================================
	// Mobile bottom nav (F1) — rendered inside the admin footer.
	// =====================================================================

	public function render_bottom_nav() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'handik-booking-app' ) ) {
			return;
		}

		// Sprint 10 fix: was 6 items on a 360px screen — labels truncated,
		// each item got <60px wide, the cluster looked cramped. Trimmed to
		// 5 by removing the standalone Forms entry (presets stay reachable
		// from Setup → tabs and from the top WP submenu); kept Dashboard,
		// Bookings, People, Setup, Logs. Forms admin is still discoverable
		// from inside Setup; this just declutters the thumb-nav.
		$items = array(
			'handik-booking-app'           => array( 'icon' => '🏠', 'label' => __( 'Dashboard', 'handik-booking-app' ) ),
			'handik-booking-app-bookings'  => array( 'icon' => '📅', 'label' => __( 'Bookings', 'handik-booking-app' ) ),
			'handik-booking-app-crm'       => array( 'icon' => '👥', 'label' => __( 'People', 'handik-booking-app' ) ),
			'handik-booking-app-settings'  => array( 'icon' => '⚙️', 'label' => __( 'Setup', 'handik-booking-app' ) ),
			'handik-booking-app-operations'=> array( 'icon' => '📜', 'label' => __( 'Logs', 'handik-booking-app' ) ),
		);

		// Sprint 10 fix: when the current page is the Additional Forms
		// admin (which we removed from the bottom nav above), highlight
		// Setup so the active state isn't blank.
		$active_slug = $page;
		if ( 'handik-booking-app-additional-forms' === $page ) {
			$active_slug = 'handik-booking-app-settings';
		}

		echo '<nav class="handik-admin-bottom-nav" data-handik-bottom-nav aria-label="' . esc_attr__( 'Handik admin sections', 'handik-booking-app' ) . '">';
		foreach ( $items as $slug => $item ) {
			$url = Handik_Booking_App_Admin_Helpers::admin_url_for( $slug );
			$cls = 'handik-admin-bottom-nav__item' . ( $active_slug === $slug ? ' is-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">';
			echo '<span class="handik-admin-bottom-nav__icon" aria-hidden="true">' . $item['icon'] . '</span>';
			echo '<span class="handik-admin-bottom-nav__label">' . esc_html( $item['label'] ) . '</span>';
			echo '</a>';
		}
		echo '</nav>';
	}
}
