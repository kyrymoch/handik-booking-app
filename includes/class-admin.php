<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Admin {
	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Assets
	 */
	protected $assets;

	/**
	 * @var Handik_Booking_App_Contacts_Service
	 */
	protected $contacts;

	/**
	 * @var Handik_Booking_App_Addresses_Service
	 */
	protected $addresses;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @var Handik_Booking_App_Bookings_Service
	 */
	protected $bookings;

	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var Handik_Booking_App_Changelog_Service
	 */
	protected $changelog;

	/**
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Assets               $assets Assets.
	 * @param Handik_Booking_App_Contacts_Service     $contacts Contacts.
	 * @param Handik_Booking_App_Addresses_Service    $addresses Addresses.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Bookings_Service     $bookings Bookings.
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Changelog_Service    $changelog Changelog.
	 */
	public function __construct( $settings, $assets, $contacts, $addresses, $job_requests, $bookings, $logger, $changelog ) {
		$this->settings     = $settings;
		$this->assets       = $assets;
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->logger       = $logger;
		$this->changelog    = $changelog;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page( __( 'Handik Booking', 'handik-booking-app' ), __( 'Handik Booking', 'handik-booking-app' ), 'manage_options', 'handik-booking-app', array( $this, 'render_dashboard' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'handik-booking-app', __( 'Dashboard', 'handik-booking-app' ), __( 'Dashboard', 'handik-booking-app' ), 'manage_options', 'handik-booking-app', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'handik-booking-app', __( 'Requests', 'handik-booking-app' ), __( 'Requests', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-requests', array( $this, 'render_requests' ) );
		add_submenu_page( 'handik-booking-app', __( 'Contacts', 'handik-booking-app' ), __( 'Contacts', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-contacts', array( $this, 'render_contacts' ) );
		add_submenu_page( 'handik-booking-app', __( 'Addresses', 'handik-booking-app' ), __( 'Addresses', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-addresses', array( $this, 'render_addresses' ) );
		add_submenu_page( 'handik-booking-app', __( 'Bookings', 'handik-booking-app' ), __( 'Bookings', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-bookings', array( $this, 'render_bookings' ) );
		add_submenu_page( 'handik-booking-app', __( 'App Settings', 'handik-booking-app' ), __( 'App Settings', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'handik-booking-app', __( 'Integrations', 'handik-booking-app' ), __( 'Integrations', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-integrations', array( $this, 'render_integrations' ) );
		add_submenu_page( 'handik-booking-app', __( 'Logs', 'handik-booking-app' ), __( 'Logs', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-logs', array( $this, 'render_logs' ) );
		add_submenu_page( 'handik-booking-app', __( 'Changelog', 'handik-booking-app' ), __( 'Changelog', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-changelog', array( $this, 'render_changelog' ) );
	}

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

	public function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'handik-booking-app' ) ) {
			return;
		}
		$this->assets->enqueue_admin();
	}

	public function render_dashboard() {
		$this->page_start( __( 'Dashboard', 'handik-booking-app' ) );
		?>
		<div class="handik-admin-cards">
			<div class="handik-admin-card"><strong><?php echo esc_html( HANDIK_BOOKING_APP_VERSION ); ?></strong><span><?php esc_html_e( 'Current plugin version', 'handik-booking-app' ); ?></span></div>
			<div class="handik-admin-card"><strong><?php echo esc_html( (string) get_option( Handik_Booking_App_Migrations::OPTION_NAME, '0.0.0' ) ); ?></strong><span><?php esc_html_e( 'DB schema version', 'handik-booking-app' ); ?></span></div>
			<div class="handik-admin-card"><strong><?php echo esc_html( count( $this->job_requests->list_recent( 100 ) ) ); ?></strong><span><?php esc_html_e( 'Recent requests sample', 'handik-booking-app' ); ?></span></div>
			<div class="handik-admin-card"><strong><?php echo esc_html( count( $this->bookings->list_recent( 100 ) ) ); ?></strong><span><?php esc_html_e( 'Recent bookings sample', 'handik-booking-app' ); ?></span></div>
		</div>
		<h2><?php esc_html_e( 'Latest release notes', 'handik-booking-app' ); ?></h2>
		<?php $entry = $this->changelog->get_entries()[0]; ?>
		<div class="handik-admin-panel">
			<h3><?php echo esc_html( $entry['title'] . ' ' . $entry['version'] ); ?></h3>
			<p><?php echo esc_html( $entry['date'] ); ?></p>
			<ul><?php foreach ( $entry['notes'] as $note ) : ?><li><?php echo esc_html( $note ); ?></li><?php endforeach; ?></ul>
		</div>
		<?php
		$this->page_end();
	}

	public function render_requests() {
		$this->page_start( __( 'Requests', 'handik-booking-app' ) );
		$this->render_table( $this->job_requests->list_recent( 100 ), array( 'id', 'client_type', 'job_shape', 'booking_type', 'status', 'app_step', 'updated_at' ) );
		$this->page_end();
	}

	public function render_contacts() {
		$this->page_start( __( 'Contacts', 'handik-booking-app' ) );
		$this->render_table( $this->contacts->list_recent( 100 ), array( 'id', 'full_name', 'email', 'phone', 'is_returning', 'updated_at' ) );
		$this->page_end();
	}

	public function render_addresses() {
		$this->page_start( __( 'Addresses', 'handik-booking-app' ) );
		$this->render_table( $this->addresses->list_recent( 100 ), array( 'id', 'contact_id', 'address_full', 'city', 'state', 'zip_code', 'updated_at' ) );
		$this->page_end();
	}

	public function render_bookings() {
		$this->page_start( __( 'Bookings', 'handik-booking-app' ) );
		$this->render_table( $this->bookings->list_recent( 100 ), array( 'id', 'job_request_id', 'cal_booking_id', 'booking_type', 'status', 'start_time', 'updated_at' ) );
		$this->page_end();
	}

	public function render_settings() {
		$this->page_start( __( 'App Settings', 'handik-booking-app' ) );
		settings_errors( 'handik-booking-app' );
		$s = $this->settings->all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' ); ?>
			<div class="handik-admin-grid">
				<?php $this->field( 'openai_api_key', __( 'OpenAI API Key', 'handik-booking-app' ), $s['openai_api_key'], 'password' ); ?>
				<?php $this->field( 'openai_workflow_id', __( 'OpenAI Workflow ID', 'handik-booking-app' ), $s['openai_workflow_id'] ); ?>
				<?php $this->field( 'openai_api_base', __( 'OpenAI API Base', 'handik-booking-app' ), $s['openai_api_base'] ); ?>
				<?php $this->field( 'openai_project_id', __( 'OpenAI Project ID', 'handik-booking-app' ), $s['openai_project_id'] ); ?>
				<?php $this->field( 'openai_organization_id', __( 'OpenAI Organization ID', 'handik-booking-app' ), $s['openai_organization_id'] ); ?>
				<?php $this->field( 'chatkit_script_url', __( 'Custom ChatKit Bridge URL', 'handik-booking-app' ), $s['chatkit_script_url'] ); ?>
				<?php $this->field( 'cal_standard_event_url', __( 'Standard Visit URL', 'handik-booking-app' ), $s['cal_standard_event_url'] ); ?>
				<?php $this->field( 'cal_extended_event_url', __( 'Extended Visit URL', 'handik-booking-app' ), $s['cal_extended_event_url'] ); ?>
				<?php $this->field( 'cal_large_event_url', __( 'Large Visit URL', 'handik-booking-app' ), $s['cal_large_event_url'] ); ?>
				<?php $this->field( 'cal_project_event_url', __( 'Project Consultation URL', 'handik-booking-app' ), $s['cal_project_event_url'] ); ?>
				<?php $this->field( 'cal_webhook_secret', __( 'Cal Webhook Secret', 'handik-booking-app' ), $s['cal_webhook_secret'], 'password' ); ?>
				<?php $this->field( 'email_from_name', __( 'Email From Name', 'handik-booking-app' ), $s['email_from_name'] ); ?>
				<?php $this->field( 'email_from_address', __( 'Email From Address', 'handik-booking-app' ), $s['email_from_address'], 'email' ); ?>
				<?php $this->field( 'app_accent_color', __( 'Accent Color', 'handik-booking-app' ), $s['app_accent_color'], 'color' ); ?>
				<?php $this->field( 'app_background', __( 'Background', 'handik-booking-app' ), $s['app_background'], 'color' ); ?>
				<?php $this->field( 'app_surface', __( 'Surface', 'handik-booking-app' ), $s['app_surface'], 'color' ); ?>
				<?php $this->field( 'app_text_color', __( 'Text Color', 'handik-booking-app' ), $s['app_text_color'], 'color' ); ?>
				<?php $this->field( 'app_border_color', __( 'Border Color', 'handik-booking-app' ), $s['app_border_color'], 'color' ); ?>
				<?php $this->field( 'app_radius', __( 'Radius (px)', 'handik-booking-app' ), $s['app_radius'], 'number' ); ?>
				<?php $this->field( 'app_spacing', __( 'Spacing (px)', 'handik-booking-app' ), $s['app_spacing'], 'number' ); ?>
				<?php $this->field( 'app_max_width', __( 'Max Width (px)', 'handik-booking-app' ), $s['app_max_width'], 'number' ); ?>
				<?php $this->field( 'app_font_scale', __( 'Font Scale', 'handik-booking-app' ), $s['app_font_scale'], 'number', '0.1' ); ?>
			</div>
			<p><label><input type="checkbox" name="debug_mode" value="1" <?php checked( ! empty( $s['debug_mode'] ) ); ?> /> <?php esc_html_e( 'Enable debug logging', 'handik-booking-app' ); ?></label></p>
			<p><label><?php esc_html_e( 'Button style', 'handik-booking-app' ); ?>
				<select name="app_button_style">
					<option value="pill" <?php selected( $s['app_button_style'], 'pill' ); ?>><?php esc_html_e( 'Pill', 'handik-booking-app' ); ?></option>
					<option value="rounded" <?php selected( $s['app_button_style'], 'rounded' ); ?>><?php esc_html_e( 'Rounded', 'handik-booking-app' ); ?></option>
				</select>
			</label></p>
			<?php submit_button( __( 'Save Settings', 'handik-booking-app' ) ); ?>
		</form>
		<?php
		$this->page_end();
	}

	public function render_integrations() {
		$this->page_start( __( 'Integrations', 'handik-booking-app' ) );
		?>
		<div class="handik-admin-panel">
			<p><?php esc_html_e( 'Frontend app embedding options:', 'handik-booking-app' ); ?></p>
			<code>[handik_booking_app]</code>
			<p><?php esc_html_e( 'Elementor widget: Handik Booking App', 'handik-booking-app' ); ?></p>
			<p><?php esc_html_e( 'Cal webhook URL:', 'handik-booking-app' ); ?> <code><?php echo esc_html( rest_url( 'handik-booking-app/v1/cal-webhook' ) ); ?></code></p>
		</div>
		<?php
		$this->page_end();
	}

	public function render_logs() {
		$this->page_start( __( 'Logs', 'handik-booking-app' ) );
		$logs = array_reverse( $this->logger->get_logs() );
		$this->render_table( $logs, array( 'time', 'level', 'message', 'context' ) );
		$this->page_end();
	}

	public function render_changelog() {
		$this->page_start( __( 'Changelog', 'handik-booking-app' ) );
		foreach ( $this->changelog->get_entries() as $entry ) {
			echo '<div class="handik-admin-panel"><h3>' . esc_html( $entry['version'] . ' - ' . $entry['title'] ) . '</h3><p>' . esc_html( $entry['date'] ) . '</p><ul>';
			foreach ( $entry['notes'] as $note ) {
				echo '<li>' . esc_html( $note ) . '</li>';
			}
			echo '</ul></div>';
		}
		$this->page_end();
	}

	protected function field( $name, $label, $value, $type = 'text', $step = '' ) {
		printf(
			'<label><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s" %5$s /></label>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			$step ? 'step="' . esc_attr( $step ) . '"' : ''
		);
	}

	protected function render_table( array $rows, array $columns ) {
		echo '<div class="handik-admin-panel"><table class="widefat striped"><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th>' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $columns as $column ) {
				$value = isset( $row[ $column ] ) ? $row[ $column ] : '';
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				echo '<td>' . esc_html( (string) $value ) . '</td>';
			}
			echo '</tr>';
		}
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="' . esc_attr( (string) count( $columns ) ) . '">' . esc_html__( 'No records found.', 'handik-booking-app' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	protected function page_start( $title ) {
		echo '<div class="wrap handik-admin-wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	protected function page_end() {
		echo '</div>';
	}
}
