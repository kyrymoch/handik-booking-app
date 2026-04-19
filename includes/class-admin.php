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
	 * @var Handik_Booking_App_Service_Catalog_Service
	 */
	protected $service_catalog;

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
	public function __construct( $settings, $assets, $contacts, $addresses, $job_requests, $bookings, $logger, $changelog, $service_catalog ) {
		$this->settings     = $settings;
		$this->assets       = $assets;
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->logger       = $logger;
		$this->changelog    = $changelog;
		$this->service_catalog = $service_catalog;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page( __( 'Handik Booking', 'handik-booking-app' ), __( 'Handik Booking', 'handik-booking-app' ), 'manage_options', 'handik-booking-app', array( $this, 'render_dashboard' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'handik-booking-app', __( 'Dashboard', 'handik-booking-app' ), __( 'Dashboard', 'handik-booking-app' ), 'manage_options', 'handik-booking-app', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'handik-booking-app', __( 'Bookings', 'handik-booking-app' ), __( 'Bookings', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-bookings', array( $this, 'render_bookings' ) );
		add_submenu_page( 'handik-booking-app', __( 'People & Requests', 'handik-booking-app' ), __( 'People & Requests', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-crm', array( $this, 'render_crm' ) );
		add_submenu_page( 'handik-booking-app', __( 'App Setup', 'handik-booking-app' ), __( 'App Setup', 'handik-booking-app' ), 'manage_options', 'handik-booking-app-settings', array( $this, 'render_settings' ) );
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

	public function render_operations() {
		$this->render_crm();
	}

	public function render_people() {
		$this->render_crm();
	}

	public function render_bookings() {
		$booking_id = isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->page_start( __( 'Bookings', 'handik-booking-app' ) );

		if ( $booking_id ) {
			$this->render_booking_detail( $booking_id );
			$this->page_end();
			return;
		}

		$rows = $this->bookings->list_recent( 100 );
		echo '<div class="handik-admin-panel"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'ID', 'Client', 'Phone', 'Email', 'Tasks', 'Rate', 'Address', 'Assistant Summary', 'When', 'Duration', 'Status', 'Details' ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$request = ! empty( $row['job_request_id'] ) ? $this->job_requests->get( (int) $row['job_request_id'] ) : null;
			$contact = ( $request && ! empty( $request['contact_id'] ) ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
			$task_ids = is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array();
			$detail_url = add_query_arg(
				array(
					'page'       => 'handik-booking-app-bookings',
					'booking_id' => (int) $row['id'],
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td>#' . esc_html( (string) $row['id'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['full_name'] ?? 'Unknown client' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['phone'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['email'] ?? '' ) ) . '</td>';
			echo '<td>' . $this->task_summary_markup( $task_ids ) . '</td>';
			echo '<td>' . esc_html( $this->request_rate_label( $request ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $request['address_full'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) ( $request['assistant_summary'] ?? '' ), 18, '…' ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_booking_window( $row ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $request['duration_bucket'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td><a class="button button-secondary" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Open', 'handik-booking-app' ) . '</a></td>';
			echo '</tr>';
		}

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="12">' . esc_html__( 'No bookings found.', 'handik-booking-app' ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
		$this->page_end();
	}

	public function render_crm() {
		$tab = $this->current_tab( array( 'requests', 'contacts', 'addresses' ), 'requests' );
		$this->page_start( __( 'People & Requests', 'handik-booking-app' ) );
		echo $this->tabs_markup(
			array(
				'requests'  => __( 'Requests', 'handik-booking-app' ),
				'contacts'  => __( 'Contacts', 'handik-booking-app' ),
				'addresses' => __( 'Addresses', 'handik-booking-app' ),
			),
			$tab
		);

		if ( 'requests' === $tab ) {
			$this->render_table( $this->job_requests->list_recent( 100 ), array( 'id', 'contact_id', 'client_type', 'job_shape', 'booking_type', 'status', 'app_step', 'updated_at' ) );
		} elseif ( 'addresses' === $tab ) {
			$this->render_table( $this->addresses->list_recent( 100 ), array( 'id', 'contact_id', 'address_full', 'city', 'state', 'zip_code', 'updated_at' ) );
		} else {
			$this->render_table( $this->contacts->list_recent( 100 ), array( 'id', 'full_name', 'email', 'phone', 'is_returning', 'updated_at' ) );
		}

		$this->page_end();
	}

	protected function render_booking_detail( $booking_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! $booking ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Booking not found.', 'handik-booking-app' ) . '</p></div>';
			return;
		}

		$request = ! empty( $booking['job_request_id'] ) ? $this->job_requests->get( (int) $booking['job_request_id'] ) : null;
		$contact = ( $request && ! empty( $request['contact_id'] ) ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
		$address = ( $request && ! empty( $request['address_id'] ) ) ? $this->addresses->get( (int) $request['address_id'] ) : null;
		$photos  = is_array( $request['photos'] ?? null ) ? $request['photos'] : array();
		$logs    = $this->booking_chat_logs( $request, $booking );
		$map_url = $this->map_embed_url( $address['address_full'] ?? ( $request['address_full'] ?? '' ) );

		echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=handik-booking-app-bookings' ) ) . '">' . esc_html__( 'Back to bookings', 'handik-booking-app' ) . '</a></p>';

		echo '<div class="handik-admin-cards">';
		echo $this->admin_stat_card( __( 'Booking Status', 'handik-booking-app' ), (string) ( $booking['status'] ?? '' ) );
		echo $this->admin_stat_card( __( 'Booking Type', 'handik-booking-app' ), (string) ( $booking['booking_type'] ?? '' ) );
		echo $this->admin_stat_card( __( 'Client', 'handik-booking-app' ), (string) ( $contact['full_name'] ?? __( 'Unknown client', 'handik-booking-app' ) ) );
		echo $this->admin_stat_card( __( 'Scheduled For', 'handik-booking-app' ), $this->format_booking_window( $booking ) );
		echo '</div>';

		echo '<div class="handik-admin-detail-grid">';
		echo '<div class="handik-admin-panel">';
		echo '<h2>' . esc_html__( 'Booking overview', 'handik-booking-app' ) . '</h2>';
		echo $this->detail_list_markup(
			array(
				__( 'Booking ID', 'handik-booking-app' )      => '#' . (string) $booking['id'],
				__( 'Cal.com Booking ID', 'handik-booking-app' ) => (string) ( $booking['cal_booking_id'] ?? '' ),
				__( 'Cal Event Slug', 'handik-booking-app' )  => (string) ( $booking['event_type_slug'] ?? '' ),
				__( 'Duration', 'handik-booking-app' )        => ! empty( $booking['duration_minutes'] ) ? (string) $booking['duration_minutes'] . ' min' : '',
				__( 'Start', 'handik-booking-app' )           => (string) ( $booking['start_time'] ?? '' ),
				__( 'End', 'handik-booking-app' )             => (string) ( $booking['end_time'] ?? '' ),
				__( 'Updated', 'handik-booking-app' )         => (string) ( $booking['updated_at'] ?? '' ),
				__( 'Duration bucket', 'handik-booking-app' ) => (string) ( $request['duration_bucket'] ?? '' ),
				__( 'Hourly rate hint', 'handik-booking-app' ) => $this->request_rate_label( $request ),
			)
		);
		echo '</div>';

		echo '<div class="handik-admin-panel">';
		echo '<h2>' . esc_html__( 'Client', 'handik-booking-app' ) . '</h2>';
		echo $this->detail_list_markup(
			array(
				__( 'Full name', 'handik-booking-app' ) => (string) ( $contact['full_name'] ?? '' ),
				__( 'First name', 'handik-booking-app' ) => (string) ( $contact['first_name'] ?? '' ),
				__( 'Last name', 'handik-booking-app' ) => (string) ( $contact['last_name'] ?? '' ),
				__( 'Email', 'handik-booking-app' ) => (string) ( $contact['email'] ?? '' ),
				__( 'Phone', 'handik-booking-app' ) => (string) ( $contact['phone'] ?? '' ),
				__( 'Client type', 'handik-booking-app' ) => (string) ( $request['client_type'] ?? '' ),
			)
		);
		echo '</div>';
		echo '</div>';

		echo '<div class="handik-admin-detail-grid">';
		echo '<div class="handik-admin-panel">';
		echo '<h2>' . esc_html__( 'Request details', 'handik-booking-app' ) . '</h2>';
		echo $this->detail_list_markup(
			array(
				__( 'Request ID', 'handik-booking-app' )      => ! empty( $request['id'] ) ? '#' . (string) $request['id'] : '',
				__( 'Job shape', 'handik-booking-app' )       => (string) ( $request['job_shape'] ?? '' ),
				__( 'Project', 'handik-booking-app' )         => ! empty( $request['is_project'] ) ? __( 'Yes', 'handik-booking-app' ) : __( 'No', 'handik-booking-app' ),
				__( 'Service family', 'handik-booking-app' )  => (string) ( $request['service_family'] ?? '' ),
				__( 'Rate family', 'handik-booking-app' )     => (string) ( $request['rate_family'] ?? '' ),
				__( 'Duration bucket', 'handik-booking-app' ) => (string) ( $request['duration_bucket'] ?? '' ),
				__( 'Routing status', 'handik-booking-app' )  => (string) ( $request['routing_status'] ?? '' ),
				__( 'Thread ID', 'handik-booking-app' )       => (string) ( $request['chat_thread_id'] ?? '' ),
			)
		);
		echo $this->thread_link_markup( $request );
		echo '<h3>' . esc_html__( 'Selected tasks', 'handik-booking-app' ) . '</h3>';
		echo $this->task_summary_markup( is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array() );
		echo '<h3>' . esc_html__( 'Assistant summary', 'handik-booking-app' ) . '</h3>';
		echo '<div class="handik-admin-note">' . nl2br( esc_html( (string) ( $request['assistant_summary'] ?? '' ) ) ) . '</div>';
		echo '<h3>' . esc_html__( 'Estimate notes', 'handik-booking-app' ) . '</h3>';
		echo '<div class="handik-admin-note">' . nl2br( esc_html( (string) ( $request['estimate_notes'] ?? '' ) ) ) . '</div>';
		echo '<h3>' . esc_html__( 'Latest assistant output', 'handik-booking-app' ) . '</h3>';
		echo $this->assistant_output_markup( $request );
		echo '</div>';

		echo '<div class="handik-admin-panel">';
		echo '<h2>' . esc_html__( 'Address', 'handik-booking-app' ) . '</h2>';
		echo $this->detail_list_markup(
			array(
				__( 'Full address', 'handik-booking-app' ) => (string) ( $address['address_full'] ?? $request['address_full'] ?? '' ),
				__( 'Line 1', 'handik-booking-app' ) => (string) ( $address['address_line_1'] ?? '' ),
				__( 'Unit', 'handik-booking-app' ) => (string) ( $address['address_unit'] ?? $request['address_unit'] ?? '' ),
				__( 'City', 'handik-booking-app' ) => (string) ( $address['city'] ?? '' ),
				__( 'State', 'handik-booking-app' ) => (string) ( $address['state'] ?? '' ),
				__( 'ZIP', 'handik-booking-app' ) => (string) ( $address['zip_code'] ?? '' ),
			)
		);
		if ( $map_url ) {
			echo '<div class="handik-admin-map-wrap"><iframe title="' . esc_attr__( 'Job address map', 'handik-booking-app' ) . '" src="' . esc_url( $map_url ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
			echo '<p><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( (string) ( $address['address_full'] ?? $request['address_full'] ?? '' ) ) ) . '">' . esc_html__( 'Open in Google Maps', 'handik-booking-app' ) . '</a></p>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="handik-admin-panel">';
		echo '<h2>' . esc_html__( 'Photos', 'handik-booking-app' ) . '</h2>';
		echo $this->photos_gallery_markup( $photos );
		echo '</div>';

		echo '<div class="handik-admin-panel">';
		echo '<h2>' . esc_html__( 'Chat activity', 'handik-booking-app' ) . '</h2>';
		if ( ! empty( $request['chat_thread_id'] ) ) {
			echo '<p><strong>' . esc_html__( 'Chat thread ID:', 'handik-booking-app' ) . '</strong> <code>' . esc_html( (string) $request['chat_thread_id'] ) . '</code></p>';
		}
		echo '<p>' . esc_html__( 'Full chat transcripts are not stored in a separate table yet. This section shows the chat-related activity we do keep in plugin logs for this request and thread.', 'handik-booking-app' ) . '</p>';
		echo $this->chat_logs_markup( $logs );
		echo '</div>';
	}

	public function render_settings() {
		$tab = $this->current_tab( array( 'general', 'appearance', 'copy', 'services', 'integrations' ), 'general' );
		$this->page_start( __( 'App Setup', 'handik-booking-app' ) );
		settings_errors( 'handik-booking-app' );
		$s = $this->settings->all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' ); ?>
			<?php echo $this->tabs_markup(
				array(
					'general'      => __( 'General', 'handik-booking-app' ),
					'appearance'   => __( 'Appearance', 'handik-booking-app' ),
					'copy'         => __( 'Texts & Notifications', 'handik-booking-app' ),
					'services'     => __( 'Services & Categories', 'handik-booking-app' ),
					'integrations' => __( 'Integrations', 'handik-booking-app' ),
				),
				$tab
			); ?>
			<?php if ( 'general' === $tab ) : ?>
			<div class="handik-admin-grid">
				<?php $this->field( 'ui_loading_title', __( 'Loading Title', 'handik-booking-app' ), $s['ui_loading_title'] ); ?>
				<?php $this->textarea_field( 'ui_loading_subtitle', __( 'Loading Subtitle', 'handik-booking-app' ), $s['ui_loading_subtitle'] ); ?>
				<?php $this->field( 'ui_continue_button', __( 'Continue Button Label', 'handik-booking-app' ), $s['ui_continue_button'] ); ?>
				<?php $this->field( 'ui_back_button', __( 'Back Button Label', 'handik-booking-app' ), $s['ui_back_button'] ); ?>
				<?php $this->field( 'ui_send_code_button', __( 'Send Code Button Label', 'handik-booking-app' ), $s['ui_send_code_button'] ); ?>
				<?php $this->field( 'ui_verify_button', __( 'Verify Button Label', 'handik-booking-app' ), $s['ui_verify_button'] ); ?>
				<?php $this->field( 'ui_open_booking_button', __( 'Open Booking Button Label', 'handik-booking-app' ), $s['ui_open_booking_button'] ); ?>
				<?php $this->field( 'ui_complete_booking_button', __( 'Booking Status Button Label', 'handik-booking-app' ), $s['ui_complete_booking_button'] ); ?>
				<?php $this->field( 'ui_restart_button', __( 'Restart Button Label', 'handik-booking-app' ), $s['ui_restart_button'] ); ?>
			</div>
			<p><label><input type="checkbox" name="debug_mode" value="1" <?php checked( ! empty( $s['debug_mode'] ) ); ?> /> <?php esc_html_e( 'Enable debug logging', 'handik-booking-app' ); ?></label></p>
			<p><label><?php esc_html_e( 'Button style', 'handik-booking-app' ); ?>
				<select name="app_button_style">
					<option value="pill" <?php selected( $s['app_button_style'], 'pill' ); ?>><?php esc_html_e( 'Pill', 'handik-booking-app' ); ?></option>
					<option value="rounded" <?php selected( $s['app_button_style'], 'rounded' ); ?>><?php esc_html_e( 'Rounded', 'handik-booking-app' ); ?></option>
				</select>
			</label></p>
			<?php elseif ( 'appearance' === $tab ) : ?>
			<div class="handik-admin-grid">
				<?php $this->field( 'app_accent_color', __( 'Accent Color', 'handik-booking-app' ), $s['app_accent_color'], 'color' ); ?>
				<?php $this->field( 'app_background', __( 'Background', 'handik-booking-app' ), $s['app_background'], 'color' ); ?>
				<?php $this->field( 'app_surface', __( 'Surface', 'handik-booking-app' ), $s['app_surface'], 'color' ); ?>
				<?php $this->field( 'app_text_color', __( 'Text Color', 'handik-booking-app' ), $s['app_text_color'], 'color' ); ?>
				<?php $this->field( 'app_border_color', __( 'Border Color', 'handik-booking-app' ), $s['app_border_color'], 'color' ); ?>
				<?php $this->field( 'app_muted_text_color', __( 'Muted Text Color', 'handik-booking-app' ), $s['app_muted_text_color'], 'color' ); ?>
				<?php $this->field( 'app_button_text_color', __( 'Primary Button Text', 'handik-booking-app' ), $s['app_button_text_color'], 'color' ); ?>
				<?php $this->field( 'app_secondary_button_bg', __( 'Secondary Button Background', 'handik-booking-app' ), $s['app_secondary_button_bg'], 'color' ); ?>
				<?php $this->field( 'app_secondary_button_text', __( 'Secondary Button Text', 'handik-booking-app' ), $s['app_secondary_button_text'], 'color' ); ?>
				<?php $this->field( 'app_pending_button_bg', __( 'Pending Button Background', 'handik-booking-app' ), $s['app_pending_button_bg'], 'color' ); ?>
				<?php $this->field( 'app_pending_button_text', __( 'Pending Button Text', 'handik-booking-app' ), $s['app_pending_button_text'], 'color' ); ?>
				<?php $this->field( 'app_progress_track', __( 'Progress Track Color', 'handik-booking-app' ), $s['app_progress_track'], 'color' ); ?>
				<?php $this->field( 'app_font_family', __( 'Font Family', 'handik-booking-app' ), $s['app_font_family'] ); ?>
				<?php $this->field( 'app_radius', __( 'Radius (px)', 'handik-booking-app' ), $s['app_radius'], 'number' ); ?>
				<?php $this->field( 'app_spacing', __( 'Spacing (px)', 'handik-booking-app' ), $s['app_spacing'], 'number' ); ?>
				<?php $this->field( 'app_max_width', __( 'Max Width (px)', 'handik-booking-app' ), $s['app_max_width'], 'number' ); ?>
				<?php $this->field( 'app_font_scale', __( 'Font Scale', 'handik-booking-app' ), $s['app_font_scale'], 'number', '0.1' ); ?>
			</div>
			<?php $this->textarea_field( 'app_custom_css', __( 'Custom CSS', 'handik-booking-app' ), $s['app_custom_css'], __( 'Use {{WRAPPER}} to scope rules to this app instance.', 'handik-booking-app' ) ); ?>
			<?php elseif ( 'copy' === $tab ) : ?>
			<div class="handik-admin-grid">
				<?php $this->field( 'ui_loading_title', __( 'Loading Title', 'handik-booking-app' ), $s['ui_loading_title'] ); ?>
				<?php $this->textarea_field( 'ui_loading_subtitle', __( 'Loading Subtitle', 'handik-booking-app' ), $s['ui_loading_subtitle'] ); ?>
				<?php $this->field( 'ui_client_type_title', __( 'Client Type Title', 'handik-booking-app' ), $s['ui_client_type_title'] ); ?>
				<?php $this->textarea_field( 'ui_client_type_intro', __( 'Client Type Intro', 'handik-booking-app' ), $s['ui_client_type_intro'] ); ?>
				<?php $this->field( 'ui_new_client_label', __( 'New Client Label', 'handik-booking-app' ), $s['ui_new_client_label'] ); ?>
				<?php $this->field( 'ui_returning_client_label', __( 'Returning Client Label', 'handik-booking-app' ), $s['ui_returning_client_label'] ); ?>
				<?php $this->field( 'ui_new_client_tooltip_title', __( 'New Client Tooltip Title', 'handik-booking-app' ), $s['ui_new_client_tooltip_title'] ); ?>
				<?php $this->textarea_field( 'ui_new_client_tooltip_text', __( 'New Client Tooltip Text', 'handik-booking-app' ), $s['ui_new_client_tooltip_text'] ); ?>
				<?php $this->field( 'ui_returning_client_tooltip_title', __( 'Returning Client Tooltip Title', 'handik-booking-app' ), $s['ui_returning_client_tooltip_title'] ); ?>
				<?php $this->textarea_field( 'ui_returning_client_tooltip_text', __( 'Returning Client Tooltip Text', 'handik-booking-app' ), $s['ui_returning_client_tooltip_text'] ); ?>
				<?php $this->field( 'ui_returning_verify_title', __( 'Verification Title', 'handik-booking-app' ), $s['ui_returning_verify_title'] ); ?>
				<?php $this->textarea_field( 'ui_returning_verify_intro', __( 'Verification Intro', 'handik-booking-app' ), $s['ui_returning_verify_intro'] ); ?>
				<?php $this->field( 'ui_task_selection_title', __( 'Task Selection Title', 'handik-booking-app' ), $s['ui_task_selection_title'] ); ?>
				<?php $this->textarea_field( 'ui_task_selection_intro', __( 'Task Selection Intro', 'handik-booking-app' ), $s['ui_task_selection_intro'] ); ?>
				<?php $this->field( 'ui_project_label', __( 'Project Label', 'handik-booking-app' ), $s['ui_project_label'] ); ?>
				<?php $this->field( 'ui_address_title', __( 'Address Screen Title', 'handik-booking-app' ), $s['ui_address_title'] ); ?>
				<?php $this->field( 'ui_address_label', __( 'Address Label', 'handik-booking-app' ), $s['ui_address_label'] ); ?>
				<?php $this->field( 'ui_address_unit_label', __( 'Unit Label', 'handik-booking-app' ), $s['ui_address_unit_label'] ); ?>
				<?php $this->field( 'ui_photos_title', __( 'Photos Screen Title', 'handik-booking-app' ), $s['ui_photos_title'] ); ?>
				<?php $this->textarea_field( 'ui_photos_intro', __( 'Photos Screen Intro', 'handik-booking-app' ), $s['ui_photos_intro'] ); ?>
				<?php $this->field( 'ui_skip_photos_button', __( 'Skip Photos Button', 'handik-booking-app' ), $s['ui_skip_photos_button'] ); ?>
				<?php $this->field( 'ui_saved_address_label', __( 'Saved Address Label', 'handik-booking-app' ), $s['ui_saved_address_label'] ); ?>
				<?php $this->field( 'ui_saved_address_placeholder', __( 'Saved Address Placeholder', 'handik-booking-app' ), $s['ui_saved_address_placeholder'] ); ?>
				<?php $this->field( 'ui_photos_label', __( 'Photos Label', 'handik-booking-app' ), $s['ui_photos_label'] ); ?>
				<?php $this->textarea_field( 'ui_photos_help', __( 'Photos Help', 'handik-booking-app' ), $s['ui_photos_help'] ); ?>
				<?php $this->field( 'ui_photos_cta', __( 'Photos CTA', 'handik-booking-app' ), $s['ui_photos_cta'] ); ?>
				<?php $this->field( 'ui_photos_empty', __( 'Photos Empty State', 'handik-booking-app' ), $s['ui_photos_empty'] ); ?>
				<?php $this->field( 'ui_photos_loading', __( 'Photos Loading Text', 'handik-booking-app' ), $s['ui_photos_loading'] ); ?>
				<?php $this->field( 'ui_assistant_title', __( 'Assistant Title', 'handik-booking-app' ), $s['ui_assistant_title'] ); ?>
				<?php $this->textarea_field( 'ui_assistant_helper', __( 'Assistant Helper Copy', 'handik-booking-app' ), $s['ui_assistant_helper'] ); ?>
				<?php $this->textarea_field( 'ui_assistant_greeting', __( 'Assistant Greeting', 'handik-booking-app' ), $s['ui_assistant_greeting'] ); ?>
				<?php $this->textarea_field( 'ui_assistant_ready_notice', __( 'Assistant Ready Notice', 'handik-booking-app' ), $s['ui_assistant_ready_notice'] ); ?>
				<?php $this->field( 'ui_assistant_continue_button', __( 'Assistant Continue Button', 'handik-booking-app' ), $s['ui_assistant_continue_button'] ); ?>
				<?php $this->field( 'ui_contact_title', __( 'Contact Title', 'handik-booking-app' ), $s['ui_contact_title'] ); ?>
				<?php $this->textarea_field( 'ui_contact_intro', __( 'Contact Intro', 'handik-booking-app' ), $s['ui_contact_intro'] ); ?>
				<?php $this->field( 'ui_contact_continue_button', __( 'Contact Continue Button', 'handik-booking-app' ), $s['ui_contact_continue_button'] ); ?>
				<?php $this->field( 'ui_booking_title', __( 'Booking Title', 'handik-booking-app' ), $s['ui_booking_title'] ); ?>
				<?php $this->field( 'ui_success_title', __( 'Success Title', 'handik-booking-app' ), $s['ui_success_title'] ); ?>
				<?php $this->textarea_field( 'ui_success_body', __( 'Success Body', 'handik-booking-app' ), $s['ui_success_body'] ); ?>
				<?php $this->field( 'ui_unsafe_title', __( 'Unsafe Title', 'handik-booking-app' ), $s['ui_unsafe_title'] ); ?>
				<?php $this->textarea_field( 'ui_unsafe_body', __( 'Unsafe Body', 'handik-booking-app' ), $s['ui_unsafe_body'] ); ?>
				<?php $this->field( 'ui_continue_button', __( 'Continue Button Label', 'handik-booking-app' ), $s['ui_continue_button'] ); ?>
				<?php $this->field( 'ui_back_button', __( 'Back Button Label', 'handik-booking-app' ), $s['ui_back_button'] ); ?>
				<?php $this->field( 'ui_send_code_button', __( 'Send Code Button Label', 'handik-booking-app' ), $s['ui_send_code_button'] ); ?>
				<?php $this->field( 'ui_verify_button', __( 'Verify Button Label', 'handik-booking-app' ), $s['ui_verify_button'] ); ?>
				<?php $this->field( 'ui_open_booking_button', __( 'Open Booking Button Label', 'handik-booking-app' ), $s['ui_open_booking_button'] ); ?>
				<?php $this->field( 'ui_complete_booking_button', __( 'Booking Status Button Label', 'handik-booking-app' ), $s['ui_complete_booking_button'] ); ?>
				<?php $this->field( 'ui_restart_button', __( 'Restart Button Label', 'handik-booking-app' ), $s['ui_restart_button'] ); ?>
				<?php $this->field( 'ui_loading_assistant_title', __( 'Assistant Loading Title', 'handik-booking-app' ), $s['ui_loading_assistant_title'] ); ?>
				<?php $this->textarea_field( 'ui_loading_assistant_subtitle', __( 'Assistant Loading Subtitle', 'handik-booking-app' ), $s['ui_loading_assistant_subtitle'] ); ?>
				<?php $this->textarea_field( 'ui_error_pick_client_type', __( 'Client Type Validation Message', 'handik-booking-app' ), $s['ui_error_pick_client_type'] ); ?>
				<?php $this->textarea_field( 'ui_error_select_task', __( 'Task Validation Message', 'handik-booking-app' ), $s['ui_error_select_task'] ); ?>
				<?php $this->textarea_field( 'ui_error_address_required', __( 'Address Validation Message', 'handik-booking-app' ), $s['ui_error_address_required'] ); ?>
				<?php $this->textarea_field( 'ui_error_invalid_code', __( 'Invalid Code Message', 'handik-booking-app' ), $s['ui_error_invalid_code'] ); ?>
				<?php $this->textarea_field( 'ui_error_assistant_required', __( 'Assistant Validation Message', 'handik-booking-app' ), $s['ui_error_assistant_required'] ); ?>
				<?php $this->textarea_field( 'ui_error_name_email_required', __( 'Contact Validation Message', 'handik-booking-app' ), $s['ui_error_name_email_required'] ); ?>
				<?php $this->textarea_field( 'ui_error_phone_or_email_required', __( 'Verification Validation Message', 'handik-booking-app' ), $s['ui_error_phone_or_email_required'] ); ?>
				<?php $this->textarea_field( 'ui_booking_waiting', __( 'Booking Waiting Message', 'handik-booking-app' ), $s['ui_booking_waiting'] ); ?>
				<?php $this->textarea_field( 'ui_booking_confirmed', __( 'Booking Confirmed Message', 'handik-booking-app' ), $s['ui_booking_confirmed'] ); ?>
				<?php $this->textarea_field( 'ui_booking_cancelled', __( 'Booking Cancelled Message', 'handik-booking-app' ), $s['ui_booking_cancelled'] ); ?>
				<?php $this->field( 'ui_address_placeholder', __( 'Address Placeholder', 'handik-booking-app' ), $s['ui_address_placeholder'] ); ?>
				<?php $this->textarea_field( 'ui_project_notice', __( 'Project Notice', 'handik-booking-app' ), $s['ui_project_notice'] ); ?>
				<?php $this->textarea_field( 'ui_info_mode_tooltip', __( 'Info Mode Tooltip', 'handik-booking-app' ), $s['ui_info_mode_tooltip'] ); ?>
				<?php $this->field( 'ui_info_mode_enabled_message', __( 'Hints Enabled Message', 'handik-booking-app' ), $s['ui_info_mode_enabled_message'] ); ?>
				<?php $this->field( 'ui_info_mode_disabled_message', __( 'Hints Disabled Message', 'handik-booking-app' ), $s['ui_info_mode_disabled_message'] ); ?>
			</div>
			<?php elseif ( 'services' === $tab ) : ?>
			<?php $this->render_services_editor(); ?>
			<?php else : ?>
			<div class="handik-admin-grid">
				<?php $this->field( 'openai_api_key', __( 'OpenAI API Key', 'handik-booking-app' ), $s['openai_api_key'], 'password' ); ?>
				<?php $this->field( 'openai_workflow_id', __( 'OpenAI Workflow ID', 'handik-booking-app' ), $s['openai_workflow_id'] ); ?>
				<?php $this->field( 'openai_api_base', __( 'OpenAI API Base', 'handik-booking-app' ), $s['openai_api_base'] ); ?>
				<?php $this->field( 'openai_project_id', __( 'OpenAI Project ID', 'handik-booking-app' ), $s['openai_project_id'] ); ?>
				<?php $this->field( 'openai_organization_id', __( 'OpenAI Organization ID', 'handik-booking-app' ), $s['openai_organization_id'] ); ?>
				<?php $this->field( 'chatkit_script_url', __( 'Custom ChatKit Bridge URL', 'handik-booking-app' ), $s['chatkit_script_url'] ); ?>
				<?php $this->field( 'google_maps_api_key', __( 'Google Maps API Key', 'handik-booking-app' ), $s['google_maps_api_key'], 'password' ); ?>
				<?php $this->field( 'google_maps_country', __( 'Google Maps Country', 'handik-booking-app' ), $s['google_maps_country'] ); ?>
				<?php $this->field( 'github_repo_url', __( 'GitHub Repo URL', 'handik-booking-app' ), $s['github_repo_url'] ); ?>
				<?php $this->field( 'github_repo_branch', __( 'GitHub Release Branch', 'handik-booking-app' ), $s['github_repo_branch'] ); ?>
				<?php $this->field( 'github_access_token', __( 'GitHub Access Token', 'handik-booking-app' ), $s['github_access_token'], 'password' ); ?>
				<?php $this->field( 'github_release_asset_pattern', __( 'GitHub Release Asset Pattern', 'handik-booking-app' ), $s['github_release_asset_pattern'] ); ?>
				<?php $this->field( 'cal_standard_event_url', __( 'Standard Visit URL', 'handik-booking-app' ), $s['cal_standard_event_url'] ); ?>
				<?php $this->field( 'cal_extended_event_url', __( 'Extended Visit URL', 'handik-booking-app' ), $s['cal_extended_event_url'] ); ?>
				<?php $this->field( 'cal_large_event_url', __( 'Large Visit URL', 'handik-booking-app' ), $s['cal_large_event_url'] ); ?>
				<?php $this->field( 'cal_project_event_url', __( 'Project Consultation URL', 'handik-booking-app' ), $s['cal_project_event_url'] ); ?>
				<?php $this->field( 'cal_webhook_secret', __( 'Cal Webhook Secret', 'handik-booking-app' ), $s['cal_webhook_secret'], 'password' ); ?>
				<?php $this->field( 'email_from_name', __( 'Email From Name', 'handik-booking-app' ), $s['email_from_name'] ); ?>
				<?php $this->field( 'email_from_address', __( 'Email From Address', 'handik-booking-app' ), $s['email_from_address'], 'email' ); ?>
			</div>
			<div class="handik-admin-panel">
				<p><?php esc_html_e( 'Frontend app embedding options:', 'handik-booking-app' ); ?></p>
				<code>[handik_booking_app]</code>
				<p><?php esc_html_e( 'Elementor widget: Handik Booking App', 'handik-booking-app' ); ?></p>
				<p><?php esc_html_e( 'Cal webhook URL:', 'handik-booking-app' ); ?> <code><?php echo esc_html( rest_url( 'handik-booking-app/v1/cal-webhook' ) ); ?></code></p>
			</div>
			<?php endif; ?>
			<?php submit_button( __( 'Save Settings', 'handik-booking-app' ) ); ?>
		</form>
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

	protected function textarea_field( $name, $label, $value, $description = '' ) {
		echo '<label style="display:grid;gap:8px;"><span>' . esc_html( $label ) . '</span>';
		echo '<textarea name="' . esc_attr( $name ) . '" rows="3" style="width:100%;">' . esc_textarea( (string) $value ) . '</textarea>';
		if ( $description ) {
			echo '<small>' . esc_html( $description ) . '</small>';
		}
		echo '</label>';
	}

	protected function render_services_editor() {
		$catalog = $this->service_catalog->get_catalog();
		echo '<div class="handik-admin-panel"><p>' . esc_html__( 'Add categories and services that appear on the task-selection screen. Each service can include a short description and a pricing hint for the client-facing notification.', 'handik-booking-app' ) . '</p>';
		echo '<div class="handik-catalog-editor" data-handik-catalog-editor>';
		echo '<div class="handik-catalog-editor__groups">';
		foreach ( $catalog as $group_index => $group ) {
			echo $this->catalog_group_markup( $group_index, $group );
		}
		echo '</div>';
		echo '<p><button type="button" class="button button-secondary" data-handik-add-group>' . esc_html__( 'Add category', 'handik-booking-app' ) . '</button></p>';
		echo '<textarea name="service_catalog_json" data-handik-catalog-json rows="12" style="width:100%;display:none;">' . esc_textarea( $this->service_catalog->get_catalog_json() ) . '</textarea>';
		echo '</div></div>';
	}

	protected function catalog_group_markup( $group_index, array $group ) {
		ob_start();
		?>
		<div class="handik-catalog-group" data-handik-group>
			<div class="handik-catalog-group__header">
				<label>
					<span><?php esc_html_e( 'Category title', 'handik-booking-app' ); ?></span>
					<input type="text" data-handik-group-name value="<?php echo esc_attr( $group['group'] ); ?>" />
				</label>
				<button type="button" class="button-link-delete" data-handik-remove-group><?php esc_html_e( 'Remove category', 'handik-booking-app' ); ?></button>
			</div>
			<div class="handik-catalog-group__tasks">
				<?php foreach ( $group['tasks'] as $task_index => $task ) : ?>
					<?php echo $this->catalog_task_markup( $group_index, $task_index, $task ); ?>
				<?php endforeach; ?>
			</div>
			<p><button type="button" class="button button-secondary" data-handik-add-task><?php esc_html_e( 'Add service', 'handik-booking-app' ); ?></button></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	protected function catalog_task_markup( $group_index, $task_index, array $task ) {
		ob_start();
		?>
		<div class="handik-catalog-task" data-handik-task>
			<div class="handik-admin-grid">
				<label><span><?php esc_html_e( 'Service ID', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-id value="<?php echo esc_attr( $task['id'] ?? '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Label', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-label value="<?php echo esc_attr( $task['label'] ?? '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Hourly price hint', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-rate value="<?php echo esc_attr( $task['rate_label'] ?? '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Service family', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-service-family value="<?php echo esc_attr( $task['service_family'] ?? '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Rate family', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-rate-family value="<?php echo esc_attr( $task['rate_family'] ?? '' ); ?>" /></label>
			</div>
			<label style="display:grid;gap:8px;">
				<span><?php esc_html_e( 'Client-facing description', 'handik-booking-app' ); ?></span>
				<textarea rows="2" data-handik-task-description><?php echo esc_textarea( $task['description'] ?? '' ); ?></textarea>
			</label>
			<p><button type="button" class="button-link-delete" data-handik-remove-task><?php esc_html_e( 'Remove service', 'handik-booking-app' ); ?></button></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	protected function current_tab( array $allowed, $default ) {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, $allowed, true ) ? $tab : $default;
	}

	protected function tabs_markup( array $tabs, $active ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'handik-booking-app-settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = '<nav class="nav-tab-wrapper handik-admin-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url  = add_query_arg(
				array(
					'page' => $page,
					'tab'  => $key,
				),
				admin_url( 'admin.php' )
			);
			$html .= '<a href="' . esc_url( $url ) . '" class="nav-tab ' . ( $active === $key ? 'nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</nav>';
		return $html;
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

	protected function format_booking_window( array $booking ) {
		$start = ! empty( $booking['start_time'] ) ? (string) $booking['start_time'] : '';
		$end   = ! empty( $booking['end_time'] ) ? (string) $booking['end_time'] : '';

		if ( $start && $end ) {
			return $start . ' - ' . $end;
		}

		return $start ? $start : ( $end ? $end : __( 'Not scheduled', 'handik-booking-app' ) );
	}

	protected function admin_stat_card( $label, $value ) {
		return '<div class="handik-admin-card"><strong>' . esc_html( $value ? $value : '—' ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}

	protected function detail_list_markup( array $items ) {
		$html = '<dl class="handik-admin-detail-list">';
		foreach ( $items as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
		}
		$html .= '</dl>';
		return $html;
	}

	protected function task_summary_markup( array $task_ids ) {
		if ( empty( $task_ids ) ) {
			return '<p>' . esc_html__( 'No tasks saved.', 'handik-booking-app' ) . '</p>';
		}

		$html = '<div class="handik-admin-chip-list">';
		foreach ( $task_ids as $task_id ) {
			$task  = $this->service_catalog->find_task( (string) $task_id );
			$label = $task['label'] ?? (string) $task_id;
			$rate  = $task['rate_label'] ?? '';
			$html .= '<span class="handik-admin-chip"><strong>' . esc_html( $label ) . '</strong>' . ( $rate ? '<em>' . esc_html( $rate ) . '</em>' : '' ) . '</span>';
		}
		$html .= '</div>';
		return $html;
	}

	protected function request_rate_label( $request ) {
		if ( ! is_array( $request ) ) {
			return '';
		}

		$task_ids = is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array();
		$labels   = array();

		foreach ( $task_ids as $task_id ) {
			$task = $this->service_catalog->find_task( (string) $task_id );
			if ( ! empty( $task['rate_label'] ) ) {
				$labels[] = (string) $task['rate_label'];
			}
		}

		$labels = array_values( array_unique( array_filter( $labels ) ) );
		if ( ! empty( $labels ) ) {
			return implode( ', ', $labels );
		}

		return (string) ( $request['rate_family'] ?? '' );
	}

	protected function thread_link_markup( $request ) {
		$thread_id = ! empty( $request['chat_thread_id'] ) ? (string) $request['chat_thread_id'] : '';
		if ( '' === $thread_id ) {
			return '';
		}

		$url = 'https://platform.openai.com/logs/' . rawurlencode( $thread_id );
		return '<p><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html__( 'Open thread in OpenAI logs', 'handik-booking-app' ) . '</a></p>';
	}

	protected function assistant_output_markup( $request ) {
		$output = is_array( $request['assistant_result'] ?? null ) ? $request['assistant_result'] : array();
		if ( empty( $output ) ) {
			return '<p>' . esc_html__( 'No assistant output saved yet.', 'handik-booking-app' ) . '</p>';
		}

		return '<pre>' . esc_html( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	protected function photos_gallery_markup( array $photos ) {
		if ( empty( $photos ) ) {
			return '<p>' . esc_html__( 'No photos attached to this request.', 'handik-booking-app' ) . '</p>';
		}

		$html = '<div class="handik-admin-photo-grid">';
		foreach ( $photos as $photo ) {
			if ( empty( $photo['url'] ) ) {
				continue;
			}
			$url  = esc_url( (string) $photo['url'] );
			$name = ! empty( $photo['name'] ) ? (string) $photo['name'] : basename( (string) $photo['url'] );
			$html .= '<a class="handik-admin-photo" href="' . $url . '" target="_blank" rel="noopener noreferrer"><img src="' . $url . '" alt="' . esc_attr( $name ) . '" /><span>' . esc_html( $name ) . '</span></a>';
		}
		$html .= '</div>';
		return $html;
	}

	protected function booking_chat_logs( $request, $booking ) {
		$logs      = array_reverse( $this->logger->get_logs() );
		$request_id = ! empty( $request['id'] ) ? (int) $request['id'] : 0;
		$thread_id  = ! empty( $request['chat_thread_id'] ) ? (string) $request['chat_thread_id'] : '';
		$filtered   = array();

		foreach ( $logs as $entry ) {
			$message = (string) ( $entry['message'] ?? '' );
			$context = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();
			$match_request = $request_id && ! empty( $context['request_id'] ) && (int) $context['request_id'] === $request_id;
			$match_thread  = $thread_id && ( ( ! empty( $context['thread_id'] ) && (string) $context['thread_id'] === $thread_id ) || false !== strpos( wp_json_encode( $context ), $thread_id ) );
			$chat_message  = false !== stripos( $message, 'chatkit' ) || false !== stripos( $message, 'assistant' ) || false !== stripos( $message, 'thread' );

			if ( ( $match_request || $match_thread ) && $chat_message ) {
				$filtered[] = $entry;
			}
		}

		return array_slice( $filtered, 0, 30 );
	}

	protected function chat_logs_markup( array $logs ) {
		if ( empty( $logs ) ) {
			return '<p>' . esc_html__( 'No saved chat activity found for this booking yet.', 'handik-booking-app' ) . '</p>';
		}

		$html = '<div class="handik-admin-chat-log">';
		foreach ( $logs as $entry ) {
			$html .= '<div class="handik-admin-chat-log__item">';
			$html .= '<div class="handik-admin-chat-log__meta"><strong>' . esc_html( (string) ( $entry['level'] ?? 'info' ) ) . '</strong><span>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</span></div>';
			$html .= '<div class="handik-admin-chat-log__message">' . esc_html( (string) ( $entry['message'] ?? '' ) ) . '</div>';
			if ( ! empty( $entry['context'] ) ) {
				$html .= '<pre>' . esc_html( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	protected function map_embed_url( $address ) {
		$address = trim( (string) $address );
		if ( '' === $address ) {
			return '';
		}

		return 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&z=15&output=embed';
	}

	protected function page_start( $title ) {
		echo '<div class="wrap handik-admin-wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	protected function page_end() {
		echo '</div>';
	}
}
