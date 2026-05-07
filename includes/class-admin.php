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

	public function __construct( $settings, $assets, $contacts, $addresses, $job_requests, $bookings, $logger, $changelog, $service_catalog, $messages = null, $additional_forms = null ) {
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
		$cap = 'manage_options';

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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['handik_booking_app_settings_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' );

		$this->settings->update( wp_unslash( $_POST ) );
		add_settings_error( 'handik-booking-app', 'settings_saved', __( 'Settings updated.', 'handik-booking-app' ), 'updated' );
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
			$this->messages
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

		$items = array(
			'handik-booking-app'           => array( 'icon' => '🏠', 'label' => __( 'Dashboard', 'handik-booking-app' ) ),
			'handik-booking-app-bookings'  => array( 'icon' => '📅', 'label' => __( 'Bookings', 'handik-booking-app' ) ),
			'handik-booking-app-crm'       => array( 'icon' => '👥', 'label' => __( 'People', 'handik-booking-app' ) ),
			'handik-booking-app-additional-forms' => array( 'icon' => '📝', 'label' => __( 'Forms', 'handik-booking-app' ) ),
			'handik-booking-app-settings'  => array( 'icon' => '⚙️', 'label' => __( 'Setup', 'handik-booking-app' ) ),
			'handik-booking-app-operations'=> array( 'icon' => '📜', 'label' => __( 'Logs', 'handik-booking-app' ) ),
		);

		echo '<nav class="handik-admin-bottom-nav" data-handik-bottom-nav aria-label="' . esc_attr__( 'Handik admin sections', 'handik-booking-app' ) . '">';
		foreach ( $items as $slug => $item ) {
			$url = Handik_Booking_App_Admin_Helpers::admin_url_for( $slug );
			$cls = 'handik-admin-bottom-nav__item' . ( $page === $slug ? ' is-active' : '' );
			echo '<a class="' . $cls . '" href="' . esc_url( $url ) . '">';
			echo '<span class="handik-admin-bottom-nav__icon" aria-hidden="true">' . $item['icon'] . '</span>';
			echo '<span class="handik-admin-bottom-nav__label">' . esc_html( $item['label'] ) . '</span>';
			echo '</a>';
		}
		echo '</nav>';
	}
}
