<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Settings {
	/**
	 * @var array<string, string>
	 */
	protected $constant_map = array(
		'openai_api_key'         => 'HANDIK_BOOKING_APP_OPENAI_API_KEY',
		'openai_workflow_id'     => 'HANDIK_BOOKING_APP_OPENAI_WORKFLOW_ID',
		'openai_api_base'        => 'HANDIK_BOOKING_APP_OPENAI_API_BASE',
		'openai_project_id'      => 'HANDIK_BOOKING_APP_OPENAI_PROJECT_ID',
		'openai_organization_id' => 'HANDIK_BOOKING_APP_OPENAI_ORGANIZATION_ID',
		'chatkit_script_url'     => 'HANDIK_BOOKING_APP_CHATKIT_SCRIPT_URL',
		'google_maps_api_key'    => 'HANDIK_BOOKING_APP_GOOGLE_MAPS_API_KEY',
		'google_maps_country'    => 'HANDIK_BOOKING_APP_GOOGLE_MAPS_COUNTRY',
		'github_repo_url'        => 'HANDIK_BOOKING_APP_GITHUB_REPO_URL',
		'github_repo_branch'     => 'HANDIK_BOOKING_APP_GITHUB_REPO_BRANCH',
		'github_access_token'    => 'HANDIK_BOOKING_APP_GITHUB_ACCESS_TOKEN',
		'github_release_asset_pattern' => 'HANDIK_BOOKING_APP_GITHUB_RELEASE_ASSET_PATTERN',
		'cal_standard_event_url' => 'HANDIK_BOOKING_APP_CAL_STANDARD_EVENT_URL',
		'cal_extended_event_url' => 'HANDIK_BOOKING_APP_CAL_EXTENDED_EVENT_URL',
		'cal_large_event_url'    => 'HANDIK_BOOKING_APP_CAL_LARGE_EVENT_URL',
		'cal_project_event_url'  => 'HANDIK_BOOKING_APP_CAL_PROJECT_EVENT_URL',
		'cal_webhook_secret'     => 'HANDIK_BOOKING_APP_CAL_WEBHOOK_SECRET',
		'email_from_name'        => 'HANDIK_BOOKING_APP_EMAIL_FROM_NAME',
		'email_from_address'     => 'HANDIK_BOOKING_APP_EMAIL_FROM_ADDRESS',
		'debug_mode'             => 'HANDIK_BOOKING_APP_DEBUG_MODE',
	);

	/**
	 * @var array<string, mixed>|null
	 */
	protected $settings = null;

	/**
	 * @return array<string, mixed>
	 */
	public function defaults() {
		return array(
			'openai_api_key'         => '',
			'openai_workflow_id'     => '',
			'openai_api_base'        => 'https://api.openai.com',
			'openai_project_id'      => '',
			'openai_organization_id' => '',
			'chatkit_script_url'     => '',
			'google_maps_api_key'    => '',
			'google_maps_country'    => 'us',
			'github_repo_url'        => 'https://github.com/kyrymoch/handik-booking-app/',
			'github_repo_branch'     => 'main',
			'github_access_token'    => '',
			'github_release_asset_pattern' => '/handik-booking-app\.zip($|[?&#])/i',
			'cal_standard_event_url' => '',
			'cal_extended_event_url' => '',
			'cal_large_event_url'    => '',
			'cal_project_event_url'  => '',
			'cal_webhook_secret'     => '',
			'email_from_name'        => get_bloginfo( 'name' ),
			'email_from_address'     => get_option( 'admin_email' ),
			'debug_mode'             => 0,
			'app_custom_css'         => '',
			'app_accent_color'       => '#0f766e',
			'app_background'         => '#f8fafc',
			'app_surface'            => '#ffffff',
			'app_text_color'         => '#0f172a',
			'app_border_color'       => '#dbe3ea',
			'app_muted_text_color'   => '#64748b',
			'app_button_text_color'  => '#ffffff',
			'app_secondary_button_bg'   => '#e2e8f0',
			'app_secondary_button_text' => '#0f172a',
			'app_pending_button_bg'     => '#cbd5e1',
			'app_pending_button_text'   => '#334155',
			'app_progress_track'        => '#dbe3ea',
			'app_font_family'        => 'inherit',
			'app_radius'             => '18',
			'app_shadow'             => '0 24px 60px rgba(15, 23, 42, 0.12)',
			'app_spacing'            => '20',
			'app_max_width'          => '980',
			'app_font_scale'         => '1',
			'app_button_style'       => 'pill',
			'service_catalog_json'   => '',
			'ui_loading_title'       => 'Getting your booking space ready...',
			'ui_loading_subtitle'    => 'Laying out the tools and making room for the good stuff.',
			'ui_client_type_title'   => 'Who is booking today?',
			'ui_client_type_intro'   => 'Choose the option that best matches your situation.',
			'ui_new_client_label'    => 'New client',
			'ui_returning_client_label' => 'Returning client',
			'ui_new_client_tooltip_title' => 'New client',
			'ui_new_client_tooltip_text'  => 'Choose this if this is your first time booking with Handik or you have not used our booking flow before.',
			'ui_returning_client_tooltip_title' => 'Returning client',
			'ui_returning_client_tooltip_text'  => 'Choose this if you have booked with Handik before and want to reuse your saved details.',
			'ui_returning_verify_title' => 'Returning client verification',
			'ui_returning_verify_intro' => 'Enter your email or phone to receive a one-time code.',
			'ui_task_selection_title'   => 'What do you need help with?',
			'ui_task_selection_intro'   => 'Choose one or more services so we can route your booking correctly.',
			'ui_project_label'          => 'Project / Large Job',
			'ui_address_title'          => 'Address details',
			'ui_address_label'          => 'Address of the job',
			'ui_address_unit_label'     => 'Unit or apartment (optional)',
			'ui_photos_title'           => 'Photos',
			'ui_photos_intro'           => 'Photos really help us understand the job faster, but you can skip this step if you do not have any.',
			'ui_skip_photos_button'     => 'Skip photos',
			'ui_saved_address_label'    => 'Saved address',
			'ui_saved_address_placeholder' => 'Choose saved address',
			'ui_photos_label'           => 'Photos',
			'ui_photos_help'            => 'Add a few clear photos so we can understand the job faster.',
			'ui_photos_cta'             => 'Tap to add photos',
			'ui_photos_empty'           => 'No photos added yet',
			'ui_photos_loading'         => 'Uploading your photos...',
			'ui_assistant_title'        => 'Virtual assistant',
			'ui_assistant_helper'       => 'This is Handik\'s virtual assistant. Describe the job, mention anything important, and ask questions about time, materials, or the next step. If you want to move faster, give a short description and then tap Continue.',
			'ui_assistant_greeting'     => 'Describe the task and I will help estimate time, materials, and the next step.',
			'ui_assistant_ready_notice' => 'The virtual assistant has enough information. Continue when you are ready.',
			'ui_contact_title'          => 'Contact details',
			'ui_booking_title'          => 'Book your time slot',
			'ui_success_title'          => 'Success',
			'ui_success_body'           => 'Your booking has been confirmed and saved.',
			'ui_unsafe_title'           => 'We need to stop the normal booking flow',
			'ui_unsafe_body'            => 'This request needs manual review before booking.',
			'ui_verify_button'          => 'Verify',
			'ui_send_code_button'       => 'Send one-time code',
			'ui_continue_button'        => 'Continue',
			'ui_back_button'            => 'Back',
			'ui_open_booking_button'    => 'Open calendar in new tab',
			'ui_complete_booking_button'=> 'Check booking status',
			'ui_restart_button'         => 'Start another booking',
			'ui_loading_assistant_title'=> 'Loading virtual assistant...',
			'ui_loading_assistant_subtitle' => 'Charging the tiny robot brain for your next step.',
			'ui_error_pick_client_type' => 'Choose whether you are a new client or a returning client to continue.',
			'ui_error_select_task'      => 'Select at least one task or mark this as a project.',
			'ui_error_address_required' => 'Add the address of the job before continuing.',
			'ui_error_invalid_code'     => 'Code or magic link is invalid or expired.',
			'ui_error_assistant_required' => 'Please send the virtual assistant a short description of the job before continuing.',
			'ui_error_name_email_required' => 'Name and email are required before you can continue.',
			'ui_error_phone_or_email_required' => 'Enter your email or phone, then request a code.',
			'ui_booking_waiting'        => 'Stay on this screen while we wait for Cal.com to confirm the booking.',
			'ui_booking_confirmed'      => 'Booking confirmed. Finishing your request...',
			'ui_booking_cancelled'      => 'This booking was cancelled. You can book another slot below.',
			'ui_address_placeholder'    => 'Start typing the address of the job',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null === $this->settings ) {
			$this->settings = wp_parse_args( get_option( HANDIK_BOOKING_APP_OPTION, array() ), $this->defaults() );
		}

		return $this->settings;
	}

	/**
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = '' ) {
		if ( isset( $this->constant_map[ $key ] ) && defined( $this->constant_map[ $key ] ) ) {
			return constant( $this->constant_map[ $key ] );
		}

		$all = $this->all();

		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * @param array<string, mixed> $input Input.
	 */
	public function update( array $input ) {
		$sanitized     = $this->sanitize_settings( $input );
		$this->settings = wp_parse_args( $sanitized, $this->defaults() );
		update_option( HANDIK_BOOKING_APP_OPTION, $this->settings, false );
	}

	/**
	 * @return bool
	 */
	public function is_debug() {
		return ! empty( $this->get( 'debug_mode', 0 ) );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function sanitize_settings( array $input ) {
		$output = array();
		foreach ( $this->defaults() as $key => $default ) {
			if ( isset( $this->constant_map[ $key ] ) && defined( $this->constant_map[ $key ] ) ) {
				$output[ $key ] = constant( $this->constant_map[ $key ] );
				continue;
			}

			$value = isset( $input[ $key ] ) ? $input[ $key ] : $default;
			switch ( $key ) {
				case 'openai_api_base':
				case 'chatkit_script_url':
				case 'github_repo_url':
				case 'cal_standard_event_url':
				case 'cal_extended_event_url':
				case 'cal_large_event_url':
				case 'cal_project_event_url':
					$output[ $key ] = esc_url_raw( (string) $value );
					break;
				case 'email_from_address':
					$output[ $key ] = sanitize_email( $value );
					break;
				case 'debug_mode':
					$output[ $key ] = empty( $value ) ? 0 : 1;
					break;
				case 'app_custom_css':
					$output[ $key ] = trim( str_replace( "\0", '', (string) $value ) );
					break;
				case 'github_repo_branch':
				case 'google_maps_country':
					$output[ $key ] = preg_replace( '/[^A-Za-z0-9._\/-]/', '', (string) $value );
					break;
				case 'github_release_asset_pattern':
					$output[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'service_catalog_json':
					$output[ $key ] = trim( str_replace( "\0", '', (string) $value ) );
					break;
				case 'app_font_family':
				case 'app_shadow':
					$output[ $key ] = trim( (string) $value );
					break;
				case 'app_radius':
				case 'app_spacing':
				case 'app_max_width':
				case 'app_font_scale':
					$output[ $key ] = preg_replace( '/[^0-9.]/', '', (string) $value );
					break;
				case 'ui_loading_subtitle':
				case 'ui_client_type_intro':
				case 'ui_new_client_tooltip_text':
				case 'ui_returning_client_tooltip_text':
				case 'ui_returning_verify_intro':
				case 'ui_task_selection_intro':
				case 'ui_photos_intro':
				case 'ui_photos_help':
				case 'ui_assistant_helper':
				case 'ui_assistant_ready_notice':
				case 'ui_success_body':
				case 'ui_unsafe_body':
				case 'ui_error_pick_client_type':
				case 'ui_error_select_task':
				case 'ui_error_address_required':
				case 'ui_error_invalid_code':
				case 'ui_error_assistant_required':
				case 'ui_error_name_email_required':
				case 'ui_error_phone_or_email_required':
				case 'ui_booking_waiting':
				case 'ui_booking_confirmed':
				case 'ui_booking_cancelled':
					$output[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$output[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $output;
	}
}
