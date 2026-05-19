<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App Setup page (D1-D4): Booking flow / Appearance / Service catalog /
 * Service area / Cal.com / Customer notifications.
 *
 * The catalog editor uses SortableJS (loaded from CDN by the JS file) for
 * drag-to-reorder + auto-save via the /admin/catalog REST endpoint.
 */
class Handik_Booking_App_Admin_Settings {

	/** @var Handik_Booking_App_Settings */
	protected $settings;
	/** @var Handik_Booking_App_Service_Catalog_Service */
	protected $catalog;
	/** @var Handik_Booking_App_Job_Requests_Service|null */
	protected $job_requests;

	public function __construct( $settings, $catalog, $job_requests = null ) {
		$this->settings     = $settings;
		$this->catalog      = $catalog;
		$this->job_requests = $job_requests;
	}

	public function render() {
		$tab = Handik_Booking_App_Admin_Helpers::current_tab(
			array( 'booking-flow', 'appearance', 'catalog', 'service-area', 'cal', 'notifications' ),
			'booking-flow'
		);

		Handik_Booking_App_Admin_Helpers::page_start( __( 'App Setup', 'handik-booking-app' ) );
		settings_errors( 'handik-booking-app' );

		$tabs = array(
			'booking-flow'  => __( 'Booking flow', 'handik-booking-app' ),
			'appearance'    => __( 'Appearance', 'handik-booking-app' ),
			'catalog'       => __( 'Service catalog', 'handik-booking-app' ),
			'service-area'  => __( 'Service area', 'handik-booking-app' ),
			'cal'           => __( 'Cal.com', 'handik-booking-app' ),
			'notifications' => __( 'Customer notifications', 'handik-booking-app' ),
		);
		echo Handik_Booking_App_Admin_Helpers::tabs_markup( $tabs, $tab, 'handik-booking-app-settings' );

		$s = $this->settings->all();

		echo '<form method="post">';
		wp_nonce_field( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' );

		switch ( $tab ) {
			case 'appearance':
				$this->render_appearance_tab( $s );
				break;
			case 'catalog':
				$this->render_catalog_tab();
				break;
			case 'service-area':
				$this->render_service_area_tab( $s );
				break;
			case 'cal':
				$this->render_cal_tab( $s );
				break;
			case 'notifications':
				$this->render_notifications_tab( $s );
				break;
			default:
				$this->render_booking_flow_tab( $s );
		}

		// Catalog tab is fully AJAX so doesn't need the submit button. Hide for it.
		if ( 'catalog' !== $tab ) {
			submit_button( __( 'Save changes', 'handik-booking-app' ) );
		}
		echo '</form>';

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	// =====================================================================
	// Booking flow (D1) — every step, plus global buttons & errors
	// =====================================================================

	protected function render_booking_flow_tab( array $s ) {
		?>
		<p class="handik-admin-page-subtitle"><?php esc_html_e( 'Customer-facing copy by step. Each setting appears here exactly once.', 'handik-booking-app' ); ?></p>

		<?php $this->section_open( __( 'Loading', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_loading_title', __( 'Loading title', 'handik-booking-app' ), $s['ui_loading_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_loading_assistant_title', __( 'Assistant loading title', 'handik-booking-app' ), $s['ui_loading_assistant_title'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_loading_subtitle', __( 'Loading subtitle', 'handik-booking-app' ), $s['ui_loading_subtitle'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_loading_assistant_subtitle', __( 'Assistant loading subtitle', 'handik-booking-app' ), $s['ui_loading_assistant_subtitle'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 1: Client type', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_client_type_title', __( 'Title', 'handik-booking-app' ), $s['ui_client_type_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_new_client_label', __( 'New client label', 'handik-booking-app' ), $s['ui_new_client_label'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_returning_client_label', __( 'Returning client label', 'handik-booking-app' ), $s['ui_returning_client_label'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_client_type_intro', __( 'Intro', 'handik-booking-app' ), $s['ui_client_type_intro'] ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_new_client_tooltip_title', __( 'New tooltip title', 'handik-booking-app' ), $s['ui_new_client_tooltip_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_returning_client_tooltip_title', __( 'Returning tooltip title', 'handik-booking-app' ), $s['ui_returning_client_tooltip_title'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_new_client_tooltip_text', __( 'New tooltip text', 'handik-booking-app' ), $s['ui_new_client_tooltip_text'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_returning_client_tooltip_text', __( 'Returning tooltip text', 'handik-booking-app' ), $s['ui_returning_client_tooltip_text'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'ui_returning_verify_title', __( 'Verification title', 'handik-booking-app' ), $s['ui_returning_verify_title'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_returning_verify_intro', __( 'Verification intro', 'handik-booking-app' ), $s['ui_returning_verify_intro'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_pick_client_type', __( 'Validation message', 'handik-booking-app' ), $s['ui_error_pick_client_type'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 2: Tasks', 'handik-booking-app' ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'ui_task_selection_title', __( 'Title', 'handik-booking-app' ), $s['ui_task_selection_title'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_task_selection_intro', __( 'Intro', 'handik-booking-app' ), $s['ui_task_selection_intro'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'ui_project_label', __( 'Project label', 'handik-booking-app' ), $s['ui_project_label'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_project_notice', __( 'Project notice', 'handik-booking-app' ), $s['ui_project_notice'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_select_task', __( 'Validation message', 'handik-booking-app' ), $s['ui_error_select_task'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 3: Address', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_address_title', __( 'Title', 'handik-booking-app' ), $s['ui_address_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_address_label', __( 'Label', 'handik-booking-app' ), $s['ui_address_label'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_address_unit_label', __( 'Unit label', 'handik-booking-app' ), $s['ui_address_unit_label'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_address_placeholder', __( 'Placeholder', 'handik-booking-app' ), $s['ui_address_placeholder'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_saved_address_label', __( 'Saved address label', 'handik-booking-app' ), $s['ui_saved_address_label'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_saved_address_placeholder', __( 'Saved address placeholder', 'handik-booking-app' ), $s['ui_saved_address_placeholder'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_address_help', __( 'Help text (pending)', 'handik-booking-app' ), $s['ui_address_help'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_address_valid_help', __( 'Help text (valid)', 'handik-booking-app' ), $s['ui_address_valid_help'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_address_required', __( 'Validation message', 'handik-booking-app' ), $s['ui_error_address_required'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 4: Photos', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_photos_title', __( 'Title', 'handik-booking-app' ), $s['ui_photos_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_photos_label', __( 'Label', 'handik-booking-app' ), $s['ui_photos_label'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_photos_cta', __( 'CTA', 'handik-booking-app' ), $s['ui_photos_cta'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_photos_empty', __( 'Empty state', 'handik-booking-app' ), $s['ui_photos_empty'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_photos_loading', __( 'Loading text', 'handik-booking-app' ), $s['ui_photos_loading'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_skip_photos_button', __( 'Skip photos button', 'handik-booking-app' ), $s['ui_skip_photos_button'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_photos_intro', __( 'Intro', 'handik-booking-app' ), $s['ui_photos_intro'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_photos_help', __( 'Help text', 'handik-booking-app' ), $s['ui_photos_help'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 5: Assistant', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_assistant_title', __( 'Title', 'handik-booking-app' ), $s['ui_assistant_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_assistant_continue_button', __( 'Continue button', 'handik-booking-app' ), $s['ui_assistant_continue_button'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_assistant_intro', __( 'Intro copy', 'handik-booking-app' ), $s['ui_assistant_intro'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_assistant_greeting', __( 'Greeting', 'handik-booking-app' ), $s['ui_assistant_greeting'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_assistant_ready_notice', __( 'Ready notice', 'handik-booking-app' ), $s['ui_assistant_ready_notice'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_assistant_required', __( 'Validation message', 'handik-booking-app' ), $s['ui_error_assistant_required'] ); ?>
			<h4 style="margin-top:14px"><?php esc_html_e( 'Plan B — assistant could not load', 'handik-booking-app' ); ?></h4>
			<p class="handik-admin-muted" style="margin:-6px 0 8px"><?php esc_html_e( 'Shown when the ChatKit assistant fails to mount or stays stuck on the loading state for ~14 seconds. The per-turn slow-reply timeline (1s / 5s / 10s / 20s / 30s / 40s / 50s) is built in and not customizable here.', 'handik-booking-app' ); ?></p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'ui_assistant_stuck_title', __( 'Stuck banner title', 'handik-booking-app' ), $s['ui_assistant_stuck_title'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_assistant_stuck_body', __( 'Stuck banner body', 'handik-booking-app' ), $s['ui_assistant_stuck_body'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'ui_assistant_stuck_cta', __( 'Stuck banner CTA', 'handik-booking-app' ), $s['ui_assistant_stuck_cta'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 6: Contact', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_contact_title', __( 'Title', 'handik-booking-app' ), $s['ui_contact_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_contact_continue_button', __( 'Continue button', 'handik-booking-app' ), $s['ui_contact_continue_button'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_contact_intro', __( 'Intro', 'handik-booking-app' ), $s['ui_contact_intro'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_name_email_required', __( 'Validation: name & email', 'handik-booking-app' ), $s['ui_error_name_email_required'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_phone_or_email_required', __( 'Validation: phone or email', 'handik-booking-app' ), $s['ui_error_phone_or_email_required'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_error_invalid_code', __( 'Validation: invalid code', 'handik-booking-app' ), $s['ui_error_invalid_code'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Step 7: Booking', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_booking_title', __( 'Title', 'handik-booking-app' ), $s['ui_booking_title'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_success_title', __( 'Success title', 'handik-booking-app' ), $s['ui_success_title'] ); ?>
			</div>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_success_body', __( 'Success body', 'handik-booking-app' ), $s['ui_success_body'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_booking_waiting', __( 'Waiting message', 'handik-booking-app' ), $s['ui_booking_waiting'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_booking_confirmed', __( 'Confirmed message', 'handik-booking-app' ), $s['ui_booking_confirmed'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_booking_cancelled', __( 'Cancelled message', 'handik-booking-app' ), $s['ui_booking_cancelled'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Unsafe', 'handik-booking-app' ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'ui_unsafe_title', __( 'Title', 'handik-booking-app' ), $s['ui_unsafe_title'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'ui_unsafe_body', __( 'Body', 'handik-booking-app' ), $s['ui_unsafe_body'] ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Global buttons', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_continue_button', __( 'Continue', 'handik-booking-app' ), $s['ui_continue_button'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_back_button', __( 'Back', 'handik-booking-app' ), $s['ui_back_button'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_send_code_button', __( 'Send code', 'handik-booking-app' ), $s['ui_send_code_button'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_verify_button', __( 'Verify', 'handik-booking-app' ), $s['ui_verify_button'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_open_booking_button', __( 'Open booking', 'handik-booking-app' ), $s['ui_open_booking_button'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_complete_booking_button', __( 'Booking status', 'handik-booking-app' ), $s['ui_complete_booking_button'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'ui_restart_button', __( 'Restart', 'handik-booking-app' ), $s['ui_restart_button'] ); ?>
			</div>
		<?php $this->section_close(); ?>
		<?php
	}

	// =====================================================================
	// Appearance — colors / type / layout / behavior / custom CSS
	// =====================================================================

	protected function render_appearance_tab( array $s ) {
		?>
		<?php $this->section_open( __( 'Colors', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_accent_color', __( 'Accent', 'handik-booking-app' ), $s['app_accent_color'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_background', __( 'Background', 'handik-booking-app' ), $s['app_background'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_surface', __( 'Surface', 'handik-booking-app' ), $s['app_surface'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_text_color', __( 'Text', 'handik-booking-app' ), $s['app_text_color'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_border_color', __( 'Border', 'handik-booking-app' ), $s['app_border_color'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_muted_text_color', __( 'Muted text', 'handik-booking-app' ), $s['app_muted_text_color'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_button_text_color', __( 'Primary button text', 'handik-booking-app' ), $s['app_button_text_color'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_button_border_color', __( 'Footer button border', 'handik-booking-app' ), $s['app_button_border_color'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_footer_button_inactive_bg', __( 'Footer button inactive bg', 'handik-booking-app' ), $s['app_footer_button_inactive_bg'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_footer_button_inactive_text', __( 'Footer button inactive text', 'handik-booking-app' ), $s['app_footer_button_inactive_text'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_footer_button_active_bg', __( 'Footer button active bg', 'handik-booking-app' ), $s['app_footer_button_active_bg'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_footer_button_active_text', __( 'Footer button active text', 'handik-booking-app' ), $s['app_footer_button_active_text'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_secondary_button_bg', __( 'Secondary button bg', 'handik-booking-app' ), $s['app_secondary_button_bg'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_secondary_button_text', __( 'Secondary button text', 'handik-booking-app' ), $s['app_secondary_button_text'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_pending_button_bg', __( 'Pending button bg', 'handik-booking-app' ), $s['app_pending_button_bg'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_pending_button_text', __( 'Pending button text', 'handik-booking-app' ), $s['app_pending_button_text'], 'color' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_progress_track', __( 'Progress track', 'handik-booking-app' ), $s['app_progress_track'], 'color' ); ?>
			</div>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Typography', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_font_family', __( 'Font family', 'handik-booking-app' ), $s['app_font_family'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_font_scale', __( 'Font scale', 'handik-booking-app' ), $s['app_font_scale'], 'number', '0.1' ); ?>
			</div>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Layout', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_radius', __( 'Radius (px)', 'handik-booking-app' ), $s['app_radius'], 'number' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_spacing', __( 'Spacing (px)', 'handik-booking-app' ), $s['app_spacing'], 'number' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_max_width', __( 'Max width (px)', 'handik-booking-app' ), $s['app_max_width'], 'number' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_field_gap', __( 'Field gap (px)', 'handik-booking-app' ), $s['app_field_gap'], 'number' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_field_padding_bottom', __( 'Field padding bottom (px)', 'handik-booking-app' ), $s['app_field_padding_bottom'], 'number' ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'app_task_group_gap', __( 'Task group gap (px)', 'handik-booking-app' ), $s['app_task_group_gap'], 'number' ); ?>
			</div>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Behavior', 'handik-booking-app' ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::checkbox_field( 'debug_mode', __( 'Enable debug logging', 'handik-booking-app' ), $s['debug_mode'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::select_field( 'app_button_style', __( 'Button style', 'handik-booking-app' ), $s['app_button_style'], array( 'pill' => 'Pill', 'rounded' => 'Rounded' ) ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Custom CSS', 'handik-booking-app' ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'app_custom_css', __( 'CSS (use {{WRAPPER}} to scope)', 'handik-booking-app' ), $s['app_custom_css'], '', 8 ); ?>
		<?php $this->section_close(); ?>
		<?php
	}

	// =====================================================================
	// Service catalog (D2) — auto-save via REST + SortableJS
	// =====================================================================

	protected function render_catalog_tab() {
		$catalog = $this->catalog ? $this->catalog->get_catalog() : array();
		$rest = trailingslashit( rest_url( 'handik-booking-app/v1' ) );

		// Pre-compute "in use by X" counts for every task in one query batch.
		$all_task_ids = array();
		foreach ( $catalog as $g ) {
			foreach ( ( $g['tasks'] ?? array() ) as $t ) {
				if ( ! empty( $t['id'] ) ) {
					$all_task_ids[] = (string) $t['id'];
				}
			}
		}
		$usage = ( $this->job_requests && $all_task_ids ) ? $this->job_requests->count_references_for_tasks( $all_task_ids ) : array();
		?>
		<p class="handik-admin-page-subtitle"><?php esc_html_e( 'Drag to reorder. Type to edit. Changes auto-save on blur.', 'handik-booking-app' ); ?></p>
		<div class="handik-catalog-editor"
			data-handik-catalog-editor
			data-rest-base="<?php echo esc_attr( esc_url_raw( $rest ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<div class="handik-catalog-editor__groups" data-handik-groups>
				<?php foreach ( $catalog as $g_idx => $group ) : ?>
					<?php echo $this->catalog_group_markup( $g_idx, $group, $usage ); ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button button-secondary" data-handik-add-group>+ <?php esc_html_e( 'Add category', 'handik-booking-app' ); ?></button>
				<span class="handik-catalog-editor__status" data-handik-catalog-status aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	protected function catalog_group_markup( $g_idx, array $group, array $usage = array() ) {
		ob_start();
		?>
		<div class="handik-catalog-group" data-handik-group>
			<div class="handik-catalog-group__header">
				<?php /* Sprint 7 (a11y): drag handle is a real button so keyboard
				   users can focus it and use arrow keys to reorder (handler
				   wired in booking-app-admin.js). SortableJS still picks it up
				   via the same `.handik-catalog-handle` selector for mouse drag. */ ?>
				<button type="button" class="handik-catalog-handle" data-handik-reorder="group" aria-label="<?php esc_attr_e( 'Reorder category (arrow keys)', 'handik-booking-app' ); ?>">⋮⋮</button>
				<label class="handik-admin-field">
					<span class="handik-admin-field__label"><?php esc_html_e( 'Category title', 'handik-booking-app' ); ?></span>
					<input type="text" data-handik-group-name value="<?php echo esc_attr( (string) ( $group['group'] ?? '' ) ); ?>" />
				</label>
				<button type="button" class="button-link-delete" data-handik-remove-group><?php esc_html_e( 'Remove', 'handik-booking-app' ); ?></button>
			</div>
			<div class="handik-catalog-group__tasks" data-handik-tasks>
				<?php foreach ( ( $group['tasks'] ?? array() ) as $t_idx => $task ) : ?>
					<?php echo $this->catalog_task_markup( $g_idx, $t_idx, $task, $usage ); ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button button-secondary" data-handik-add-task>+ <?php esc_html_e( 'Add service', 'handik-booking-app' ); ?></button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	protected function catalog_task_markup( $g_idx, $t_idx, array $task, array $usage = array() ) {
		$task_id = (string) ( $task['id'] ?? '' );
		$ref_count = ( $task_id !== '' && isset( $usage[ $task_id ] ) ) ? (int) $usage[ $task_id ] : 0;
		ob_start();
		?>
		<div class="handik-catalog-task" data-handik-task>
			<button type="button" class="handik-catalog-handle" data-handik-reorder="task" aria-label="<?php esc_attr_e( 'Reorder service (arrow keys)', 'handik-booking-app' ); ?>">⋮⋮</button>
			<div class="handik-catalog-task__fields">
				<div class="handik-admin-grid">
					<label class="handik-admin-field"><span><?php esc_html_e( 'Service ID', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-id value="<?php echo esc_attr( (string) ( $task['id'] ?? '' ) ); ?>" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Label', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-label value="<?php echo esc_attr( (string) ( $task['label'] ?? '' ) ); ?>" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Hourly hint', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-rate value="<?php echo esc_attr( (string) ( $task['rate_label'] ?? '' ) ); ?>" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Service family', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-service-family value="<?php echo esc_attr( (string) ( $task['service_family'] ?? '' ) ); ?>" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Rate family', 'handik-booking-app' ); ?></span><input type="text" data-handik-task-rate-family value="<?php echo esc_attr( (string) ( $task['rate_family'] ?? '' ) ); ?>" /></label>
				</div>
				<label class="handik-admin-field handik-admin-field--textarea">
					<span><?php esc_html_e( 'Description', 'handik-booking-app' ); ?></span>
					<textarea rows="2" data-handik-task-description><?php echo esc_textarea( (string) ( $task['description'] ?? '' ) ); ?></textarea>
				</label>
			</div>
			<div class="handik-catalog-task__actions">
				<?php if ( $ref_count > 0 ) : ?>
					<span class="handik-admin-pill handik-admin-pill--info" title="<?php esc_attr_e( 'This task is referenced by existing requests — deleting will not affect those rows but will remove it from the public app.', 'handik-booking-app' ); ?>">
						<?php echo esc_html( sprintf( _n( 'in use by %d request', 'in use by %d requests', $ref_count, 'handik-booking-app' ), $ref_count ) ); ?>
					</span>
				<?php endif; ?>
				<button type="button" class="button-link" data-handik-duplicate-task><?php esc_html_e( 'Duplicate', 'handik-booking-app' ); ?></button>
				<?php /* Sprint 10 fix: expose ref count to JS so the delete
				   confirm can name the actual count instead of a generic
				   "Delete?". */ ?>
				<button type="button" class="button-link-delete" data-handik-remove-task data-handik-ref-count="<?php echo esc_attr( (string) $ref_count ); ?>"><?php esc_html_e( 'Remove', 'handik-booking-app' ); ?></button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// =====================================================================
	// Service area (D3) — ZIP whitelist
	// =====================================================================

	protected function render_service_area_tab( array $s ) {
		$zips_value = (string) ( $s['service_area_zips'] ?? '' );
		$normalized = $this->parse_zips( $zips_value );
		?>
		<?php $this->section_open( __( 'Allowed ZIP codes', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted"><?php esc_html_e( 'One ZIP per line (5-digit US codes). Booking app will only accept addresses whose ZIP appears here. Leave empty to accept any ZIP.', 'handik-booking-app' ); ?></p>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'service_area_zips', __( 'ZIPs', 'handik-booking-app' ), $zips_value, '', 12 ); ?>
			<p class="handik-admin-muted">
				<strong><?php esc_html_e( 'Recognized:', 'handik-booking-app' ); ?></strong>
				<?php echo esc_html( $normalized ? sprintf( _n( '%d ZIP', '%d ZIPs', count( $normalized ), 'handik-booking-app' ), count( $normalized ) ) . ' — ' . implode( ', ', $normalized ) : __( '(none — service area is open)', 'handik-booking-app' ) ); ?>
			</p>
		<?php $this->section_close(); ?>
		<?php
	}

	protected function parse_zips( $raw ) {
		$lines = preg_split( '/[\s,;]+/', (string) $raw );
		$zips = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^\d{5}$/', $line ) ) {
				$zips[ $line ] = true;
			}
		}
		return array_keys( $zips );
	}

	// =====================================================================
	// Cal.com (D3) — moved from Integrations
	// =====================================================================

	protected function render_cal_tab( array $s ) {
		?>
		<?php $this->section_open( __( 'Cal.com event URLs', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_standard_event_url', __( 'Standard visit URL', 'handik-booking-app' ), $s['cal_standard_event_url'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_extended_event_url', __( 'Extended visit URL', 'handik-booking-app' ), $s['cal_extended_event_url'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_large_event_url', __( 'Large visit URL', 'handik-booking-app' ), $s['cal_large_event_url'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_project_event_url', __( 'Project consultation URL', 'handik-booking-app' ), $s['cal_project_event_url'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_fallback_url', __( 'Fallback URL (when no event matches)', 'handik-booking-app' ), $s['cal_fallback_url'] ); ?>
			</div>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Webhook', 'handik-booking-app' ) ); ?>
			<p><?php esc_html_e( 'Webhook URL:', 'handik-booking-app' ); ?> <code><?php echo esc_html( rest_url( 'handik-booking-app/v1/cal-webhook' ) ); ?></code></p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'cal_webhook_secret', __( 'Webhook secret', 'handik-booking-app' ), $s['cal_webhook_secret'], 'password' ); ?>
			<p class="handik-admin-muted"><?php esc_html_e( 'Required: webhook will reject unsigned requests when no secret is set.', 'handik-booking-app' ); ?></p>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Cal.com API (Project Work Days)', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted"><?php esc_html_e( 'Used by the Project Work Days forms to fetch slots and create bookings server-side. Direct visit forms still use the public Cal.com iframe and do not require the API key.', 'handik-booking-app' ); ?></p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'cal_api_key', __( 'API key (Bearer)', 'handik-booking-app' ), $s['cal_api_key'], 'password' ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_api_base', __( 'API base URL', 'handik-booking-app' ), $s['cal_api_base'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_api_version', __( 'API version header', 'handik-booking-app' ), $s['cal_api_version'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'cal_api_timezone', __( 'Default timezone', 'handik-booking-app' ), $s['cal_api_timezone'] ); ?>
			</div>
			<p class="handik-admin-muted"><?php esc_html_e( 'Defaults: api.cal.com/v2 · 2024-09-04 · America/New_York. Cal.com versions each endpoint independently — the plugin pins slots to 2024-09-04 and bookings to 2024-08-13 in code. The field above is a global fallback used when neither pin applies.', 'handik-booking-app' ); ?></p>
		<?php $this->section_close(); ?>
		<?php
	}

	// =====================================================================
	// Customer notifications (D4)
	// =====================================================================

	protected function render_notifications_tab( array $s ) {
		?>
		<?php $this->section_open( __( 'Cal.com confirmation note', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'This text is forwarded to Cal.com as the booking notes. Available placeholders:', 'handik-booking-app' ); ?>
				<code>{{request_id}}</code> · <code>{{customer_name}}</code> · <code>{{address}}</code> · <code>{{task_summary}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'cal_confirmation_note', __( 'Note template', 'handik-booking-app' ), $s['cal_confirmation_note'], '', 5 ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Magic link email', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'Sent when a returning client requests a magic link. Placeholders:', 'handik-booking-app' ); ?>
				<code>{{customer_name}}</code> · <code>{{magic_link}}</code> · <code>{{site_name}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'magic_link_email_subject', __( 'Subject', 'handik-booking-app' ), $s['magic_link_email_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'magic_link_email_body', __( 'Body', 'handik-booking-app' ), $s['magic_link_email_body'], '', 8 ); ?>
		<?php $this->section_close(); ?>

		<?php
		$current_user_email = (string) wp_get_current_user()->user_email;
		$test_recipient_effective = '' !== (string) $s['notification_test_recipient']
			? (string) $s['notification_test_recipient']
			: $current_user_email;
		?>

		<?php $this->section_open( __( 'Test recipient', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php
				/* translators: %s: email address that will receive test sends. */
				echo esc_html( sprintf( __( 'Address that receives the "Send test email" previews from the sections below. Empty → falls back to the WordPress account email of whoever clicks the button (currently %s).', 'handik-booking-app' ), $current_user_email ) );
				?>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'notification_test_recipient', __( 'Send test emails to', 'handik-booking-app' ), $s['notification_test_recipient'], 'email' ); ?>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Customer booking-confirmation email', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'When enabled, the plugin sends a branded confirmation email (HTML + plain-text alternative + .ics calendar attachment) to the customer after every new booking — main flow, direct preset, and project work-days. Disable Cal.com’s own confirmation email FIRST so customers don’t receive two messages. Placeholders:', 'handik-booking-app' ); ?>
				<code>{{customer_name}}</code> · <code>{{booking_when_long}}</code> · <code>{{booking_when}}</code> · <code>{{address}}</code> · <code>{{tasks_list_html}}</code> · <code>{{tasks_list_text}}</code> · <code>{{cal_url}}</code> · <code>{{operator_first_name}}</code> · <code>{{from_name}}</code> · <code>{{site_name}}</code> · <code>{{brand_logo_html}}</code> · <code>{{brand_logo_url}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::checkbox_field( 'customer_confirmations_enabled', __( 'Send our own confirmation emails (replaces Cal.com’s)', 'handik-booking-app' ), ! empty( $s['customer_confirmations_enabled'] ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'brand_logo_url', __( 'Brand logo URL', 'handik-booking-app' ), $s['brand_logo_url'], 'url' ); ?>
			<p class="handik-admin-muted"><?php esc_html_e( 'Public HTTPS URL of your logo (e.g. https://handik.pro/wp-content/uploads/handik-logo.png). The default HTML template renders it centered at the top via {{brand_logo_html}}; leave empty if you don’t want a logo. Recommended size: 240–360px wide, transparent PNG.', 'handik-booking-app' ); ?></p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'customer_confirmation_subject', __( 'Subject', 'handik-booking-app' ), $s['customer_confirmation_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'customer_confirmation_body_html', __( 'HTML body', 'handik-booking-app' ), $s['customer_confirmation_body_html'], __( 'HTML allowed (same allow-list as post content). Project flows additionally support {{days_list_html}} and {{days_count}}.', 'handik-booking-app' ), 12 ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'customer_confirmation_body_text', __( 'Plain-text body', 'handik-booking-app' ), $s['customer_confirmation_body_text'], __( 'Sent as the multipart/alternative fallback for clients that block HTML.', 'handik-booking-app' ), 10 ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'customer_confirmation_reply_to', __( 'Reply-To address', 'handik-booking-app' ), $s['customer_confirmation_reply_to'], 'email' ); ?>
			<p class="handik-admin-muted"><?php esc_html_e( 'Leave empty to fall back to the From address below.', 'handik-booking-app' ); ?></p>
			<p>
				<button type="submit" class="button button-secondary" name="handik_action" value="send_test_email">
					<?php esc_html_e( 'Send test email to me', 'handik-booking-app' ); ?>
				</button>
				<small class="handik-admin-muted" style="margin-left:8px;">
					<?php
					/* translators: %s: effective test recipient email address. */
					echo esc_html( sprintf( __( 'Renders the templates with sample data and ships to %s. Bypasses the master toggle so you can preview before going live.', 'handik-booking-app' ), $test_recipient_effective ) );
					?>
				</small>
			</p>
			<p class="handik-admin-muted" style="margin-top: 16px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
				<?php esc_html_e( 'Templates above came in pre-filled when you first activated the plugin. If you’ve been using the plugin since before 2.1.21.4, your saved HTML body is the older bare-paragraph version (since defaults only land on fresh activation). Click below to refresh to the current bundled default — Cal-style structured layout with a checkmark badge, "What / When / Where" rows, and a Reschedule/Cancel button. Your custom edits are overwritten on click.', 'handik-booking-app' ); ?>
			</p>
			<p>
				<button type="submit" class="button" name="handik_action" value="reset_template_customer_confirmation_subject"><?php esc_html_e( 'Reset subject', 'handik-booking-app' ); ?></button>
				<button type="submit" class="button" name="handik_action" value="reset_template_customer_confirmation_body_html"><?php esc_html_e( 'Reset HTML body', 'handik-booking-app' ); ?></button>
				<button type="submit" class="button" name="handik_action" value="reset_template_customer_confirmation_body_text"><?php esc_html_e( 'Reset plain-text body', 'handik-booking-app' ); ?></button>
			</p>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Customer cancellation email', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'Sent to the customer when Cal.com reports a booking as cancelled (BOOKING_CANCELLED webhook). Independent toggle — you can enable cancellation without enabling reschedule. Includes a METHOD:CANCEL .ics attachment so calendar apps remove the original event from the customer’s calendar on import. Placeholders: same as the booking-confirmation email plus', 'handik-booking-app' ); ?>
				<code>{{cancellation_reason}}</code>.
			</p>
			<?php Handik_Booking_App_Admin_Helpers::checkbox_field( 'customer_cancellation_enabled', __( 'Send cancellation email to customer', 'handik-booking-app' ), ! empty( $s['customer_cancellation_enabled'] ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'customer_cancellation_subject', __( 'Subject', 'handik-booking-app' ), $s['customer_cancellation_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'customer_cancellation_body_html', __( 'HTML body', 'handik-booking-app' ), $s['customer_cancellation_body_html'], '', 10 ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'customer_cancellation_body_text', __( 'Plain-text body', 'handik-booking-app' ), $s['customer_cancellation_body_text'], '', 8 ); ?>
			<p>
				<button type="submit" class="button button-secondary" name="handik_action" value="send_test_customer_cancellation">
					<?php esc_html_e( 'Send customer-cancellation test', 'handik-booking-app' ); ?>
				</button>
				<small class="handik-admin-muted" style="margin-left:8px;">
					<?php
					/* translators: %s: effective test recipient. */
					echo esc_html( sprintf( __( 'Ships sample-data preview to %s.', 'handik-booking-app' ), $test_recipient_effective ) );
					?>
				</small>
			</p>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Customer reschedule email', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'Sent to the customer when Cal.com reports a booking time change (BOOKING_RESCHEDULED webhook). Includes an updated .ics attachment with SEQUENCE bumped so calendar apps move the original event to the new time instead of duplicating it. Reschedule-specific placeholders:', 'handik-booking-app' ); ?>
				<code>{{old_booking_when_long}}</code> · <code>{{old_booking_when}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::checkbox_field( 'customer_reschedule_enabled', __( 'Send reschedule email to customer', 'handik-booking-app' ), ! empty( $s['customer_reschedule_enabled'] ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'customer_reschedule_subject', __( 'Subject', 'handik-booking-app' ), $s['customer_reschedule_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'customer_reschedule_body_html', __( 'HTML body', 'handik-booking-app' ), $s['customer_reschedule_body_html'], '', 10 ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'customer_reschedule_body_text', __( 'Plain-text body', 'handik-booking-app' ), $s['customer_reschedule_body_text'], '', 8 ); ?>
			<p>
				<button type="submit" class="button button-secondary" name="handik_action" value="send_test_customer_reschedule">
					<?php esc_html_e( 'Send customer-reschedule test', 'handik-booking-app' ); ?>
				</button>
				<small class="handik-admin-muted" style="margin-left:8px;">
					<?php
					/* translators: %s: effective test recipient. */
					echo esc_html( sprintf( __( 'Ships sample-data preview to %s.', 'handik-booking-app' ), $test_recipient_effective ) );
					?>
				</small>
			</p>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Owner booking notification', 'handik-booking-app' ) ); ?>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'When enabled, the plugin sends a plain-text notification to the owner address every time a new booking lands — main flow, direct preset, and project work-days. Independent of the customer-side toggle. The owner email’s Reply-To is set to the customer’s email so a quick "got it" reply lands directly with them. Placeholders:', 'handik-booking-app' ); ?>
				<code>{{customer_name}}</code> · <code>{{customer_phone}}</code> · <code>{{customer_email}}</code> · <code>{{booking_when_long}}</code> · <code>{{booking_when}}</code> · <code>{{address}}</code> · <code>{{tasks_list_text}}</code> · <code>{{source_label}}</code> · <code>{{open_request_admin_link}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::checkbox_field( 'owner_notification_enabled', __( 'Notify the owner on every new booking', 'handik-booking-app' ), ! empty( $s['owner_notification_enabled'] ) ); ?>
			<?php Handik_Booking_App_Admin_Helpers::field( 'owner_notification_address', __( 'Recipient address', 'handik-booking-app' ), $s['owner_notification_address'], 'email' ); ?>
			<p class="handik-admin-muted"><?php esc_html_e( 'Leave empty to fall back to the From address below. Useful if you want bookings to go to a phone-pinned alias (e.g. ops@) instead of your main inbox.', 'handik-booking-app' ); ?></p>
			<h4 style="margin: 16px 0 8px;"><?php esc_html_e( 'New booking', 'handik-booking-app' ); ?></h4>
			<?php Handik_Booking_App_Admin_Helpers::field( 'owner_notification_subject', __( 'Subject', 'handik-booking-app' ), $s['owner_notification_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'owner_notification_body', __( 'Body', 'handik-booking-app' ), $s['owner_notification_body'], __( 'Plain-text only. No HTML. Use {{open_request_admin_link}} to drop a deep-link to the admin booking detail.', 'handik-booking-app' ), 10 ); ?>
			<p>
				<button type="submit" class="button button-secondary" name="handik_action" value="send_test_owner_email">
					<?php esc_html_e( 'Send owner test email to me', 'handik-booking-app' ); ?>
				</button>
				<small class="handik-admin-muted" style="margin-left:8px;">
					<?php
					/* translators: %s: effective test recipient email address. */
					echo esc_html( sprintf( __( 'Renders the owner template with sample data and ships to %s. Bypasses the toggle so you can preview before going live.', 'handik-booking-app' ), $test_recipient_effective ) );
					?>
				</small>
			</p>

			<h4 style="margin: 24px 0 8px;"><?php esc_html_e( 'Cancellation', 'handik-booking-app' ); ?></h4>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'Sent to the owner when Cal.com reports a cancellation. Reuses the toggle above. Reschedule-specific placeholder available:', 'handik-booking-app' ); ?>
				<code>{{cancellation_reason}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'owner_cancellation_subject', __( 'Subject', 'handik-booking-app' ), $s['owner_cancellation_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'owner_cancellation_body', __( 'Body', 'handik-booking-app' ), $s['owner_cancellation_body'], '', 9 ); ?>
			<p>
				<button type="submit" class="button button-secondary" name="handik_action" value="send_test_owner_cancellation">
					<?php esc_html_e( 'Send owner-cancellation test', 'handik-booking-app' ); ?>
				</button>
			</p>

			<h4 style="margin: 24px 0 8px;"><?php esc_html_e( 'Reschedule', 'handik-booking-app' ); ?></h4>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'Sent to the owner when Cal.com reports a time change. Reschedule-specific placeholders available:', 'handik-booking-app' ); ?>
				<code>{{old_booking_when_long}}</code> · <code>{{old_booking_when}}</code>
			</p>
			<?php Handik_Booking_App_Admin_Helpers::field( 'owner_reschedule_subject', __( 'Subject', 'handik-booking-app' ), $s['owner_reschedule_subject'] ); ?>
			<?php Handik_Booking_App_Admin_Helpers::textarea_field( 'owner_reschedule_body', __( 'Body', 'handik-booking-app' ), $s['owner_reschedule_body'], '', 9 ); ?>
			<p>
				<button type="submit" class="button button-secondary" name="handik_action" value="send_test_owner_reschedule">
					<?php esc_html_e( 'Send owner-reschedule test', 'handik-booking-app' ); ?>
				</button>
			</p>
		<?php $this->section_close(); ?>

		<?php $this->section_open( __( 'Email envelope', 'handik-booking-app' ) ); ?>
			<div class="handik-admin-grid">
				<?php Handik_Booking_App_Admin_Helpers::field( 'email_from_name', __( 'From name', 'handik-booking-app' ), $s['email_from_name'] ); ?>
				<?php Handik_Booking_App_Admin_Helpers::field( 'email_from_address', __( 'From address', 'handik-booking-app' ), $s['email_from_address'], 'email' ); ?>
			</div>
		<?php $this->section_close(); ?>
		<?php
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	protected function section_open( $title ) {
		// Sprint 11 fix: settings sections now opt into the shared
		// sessionStorage memory (data-handik-details-key from Sprint 10).
		// First render keeps `open` so first-time admins see all the
		// fields; subsequent renders restore whatever the owner closed.
		// Key derived from the title slug so each section persists
		// independently across the multi-tab Setup page.
		$key = 'settings-' . sanitize_title( (string) $title );
		echo '<details class="handik-admin-details" open data-handik-details-key="' . esc_attr( $key ) . '">';
		echo '<summary><strong>' . esc_html( $title ) . '</strong></summary>';
		echo '<div class="handik-admin-details__body">';
	}

	protected function section_close() {
		echo '</div></details>';
	}
}
