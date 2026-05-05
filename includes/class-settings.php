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
		'twilio_account_sid'     => 'HANDIK_BOOKING_APP_TWILIO_ACCOUNT_SID',
		'twilio_auth_token'      => 'HANDIK_BOOKING_APP_TWILIO_AUTH_TOKEN',
		'twilio_verify_service_sid' => 'HANDIK_BOOKING_APP_TWILIO_VERIFY_SERVICE_SID',
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
			'twilio_account_sid'     => '',
			'twilio_auth_token'      => '',
			'twilio_verify_service_sid' => '',
			'debug_mode'             => 0,
			'app_custom_css'         => '',
			'app_accent_color'       => '#283618',
			'app_background'         => '#f8fafc',
			'app_surface'            => '#ffffff',
			'app_text_color'         => '#0f172a',
			'app_border_color'       => '#dbe3ea',
			'app_muted_text_color'   => '#64748b',
			'app_button_text_color'  => '#ffffff',
			'app_button_border_color'   => 'var(--e-global-color-d50b40a)',
			'app_secondary_button_bg'   => '#e2e8f0',
			'app_secondary_button_text' => '#0f172a',
			'app_pending_button_bg'     => '#cbd5e1',
			'app_pending_button_text'   => '#334155',
			'app_footer_button_inactive_bg'   => '#f9f9f9',
			'app_footer_button_inactive_text' => '#1a1a1c',
			'app_footer_button_active_bg'     => '#1a1a1c',
			'app_footer_button_active_text'   => '#f9f9f9',
			'app_progress_track'        => '#dbe3ea',
			'app_font_family'        => 'inherit',
			'app_radius'             => '18',
			'app_shadow'             => '0 24px 60px rgba(15, 23, 42, 0.12)',
			'app_spacing'            => '20',
			'app_max_width'          => '980',
			'app_font_scale'         => '1',
			'app_button_style'       => 'pill',
			'app_field_gap'          => '8',
			'app_field_padding_bottom' => '0',
			'app_task_group_gap'     => '10',
			'service_catalog_json'   => '',
			'ui_loading_title'       => 'Loading...',
			'ui_loading_subtitle'    => '',
			'ui_client_type_title'   => 'Who is booking today?',
			'ui_client_type_intro'   => 'Choose the option that best matches your situation.',
			'ui_new_client_label'    => 'New client',
			'ui_returning_client_label' => 'Returning client',
			'ui_new_client_tooltip_title' => 'New client',
			'ui_new_client_tooltip_text'  => 'New client means someone who has never booked through this form before.',
			'ui_returning_client_tooltip_title' => 'Returning client',
			'ui_returning_client_tooltip_text'  => 'Returning client means someone who has already booked through this form before.',
			'ui_returning_verify_title' => 'Returning client verification',
			'ui_returning_verify_intro' => 'Enter your email or phone to receive a one-time code.',
			'ui_task_selection_title'   => 'What do you need help with?',
			'ui_task_selection_intro'   => 'Choose the option that best matches your request.',
			'ui_project_label'          => 'Complex Project Work',
			'ui_address_title'          => 'Address details',
			'ui_address_label'          => 'Address of the job',
			'ui_address_unit_label'     => 'Unit or apartment (optional)',
			'ui_address_help'           => '',
			'ui_address_valid_help'     => '',
			'ui_photos_title'           => 'Photos of the Work Area',
			'ui_photos_intro'           => 'Upload clear photos of the area, item, fixture, wall, or problem you need help with. Photos help the assistant estimate time, materials, and the right booking type.',
			'ui_skip_photos_button'     => '',
			'ui_saved_address_label'    => 'Saved address',
			'ui_saved_address_placeholder' => 'Choose saved address',
			'ui_photos_label'           => 'Photos',
			'ui_photos_help'            => 'Add a few clear photos so we can understand the job faster.',
			'ui_photos_cta'             => 'Tap to add photos',
			'ui_photos_empty'           => 'No photos added yet',
			'ui_photos_loading'         => 'Uploading your photos...',
			'ui_assistant_title'        => 'Virtual assistant',
			'ui_assistant_intro'        => 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping us collect the details needed to prepare for the job properly.',
			'ui_assistant_helper'       => 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping us collect the details needed to prepare for the job properly.',
			'ui_assistant_greeting'     => 'Describe the job.',
			'ui_assistant_ready_notice' => 'The virtual assistant has enough information. Continue when you are ready.',
			'ui_assistant_continue_button' => 'Book a time',
			'ui_contact_continue_button' => 'Continue to Assistant',
			'ui_contact_intro'         => 'This is the last step where you can change the booking details before the AI review starts.',
			'ui_project_notice'        => 'Complex Project Work means a bigger scope that usually needs a consultation-style visit before the work is scheduled.',
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
			'ui_loading_assistant_title'=> 'Loading...',
			'ui_loading_assistant_subtitle' => '',
			'ui_error_pick_client_type' => 'Choose whether you are a new client or a returning client to continue.',
			'ui_error_select_task'      => 'Select at least one task or mark this as a project.',
			'ui_error_address_required' => 'Add the address of the job before continuing.',
			'ui_error_invalid_code'     => 'Code or magic link is invalid or expired.',
			'ui_error_assistant_required' => 'Please send the virtual assistant a short description of the job before continuing.',
			'ui_error_name_email_required' => 'Name and email are required before you can continue.',
			'ui_error_phone_or_email_required' => 'Enter your email or phone, then request a code.',
			'ui_booking_waiting'        => '',
			'ui_booking_confirmed'      => 'Your time slot is booked. Finishing your request now...',
			'ui_booking_cancelled'      => 'This booking was cancelled. You can choose another slot below.',
			'ui_address_placeholder'    => 'Start typing the address of the job',

			// --- v2.1.8.2 admin additions ---------------------------------
			// Comma- or newline-separated list of allowed ZIPs (5-digit US codes).
			'service_area_zips'                 => '',
			// Customer-facing emails / Cal.com notes (D4).
			'cal_confirmation_note'             => 'Booking #{{request_id}} for {{customer_name}} at {{address}}. Tasks: {{task_summary}}.',
			'magic_link_email_subject'          => 'Your Handik booking link',
			'magic_link_email_body'             => "Hi {{customer_name}},\n\nUse the link below to continue your Handik booking:\n{{magic_link}}\n\nThe link expires in 15 minutes.",
			// Log retention (E1) — 0 means use defaults.
			'log_max_entries_info'              => '2000',
			'log_max_entries_debug'             => '500',
			// Cal.com fallback URL (D3).
			'cal_fallback_url'                  => '',
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
		$current        = wp_parse_args( get_option( HANDIK_BOOKING_APP_OPTION, array() ), $this->defaults() );
		$sanitized      = $this->sanitize_settings( $input );
		$this->settings = array_merge( $current, $sanitized );
		update_option( HANDIK_BOOKING_APP_OPTION, $this->settings, false );

		/**
		 * Fires after plugin settings have been persisted.
		 *
		 * Services that memoize derived data (e.g. service catalog) should listen
		 * to this action and flush their request-scope caches.
		 *
		 * @param array<string, mixed> $sanitized The sanitized fields that were applied.
		 */
		do_action( 'handik_booking_app_settings_updated', $sanitized );
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
		if ( ! empty( $input['service_catalog_groups'] ) && is_array( $input['service_catalog_groups'] ) ) {
			$catalog = $this->sanitize_service_catalog_groups( $input['service_catalog_groups'] );
			if ( ! empty( $catalog ) ) {
				$input['service_catalog_json'] = wp_json_encode( $catalog );
			}
		}

		$output = array();
		foreach ( $this->defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) && 'service_catalog_json' !== $key ) {
				continue;
			}

			if ( isset( $this->constant_map[ $key ] ) && defined( $this->constant_map[ $key ] ) ) {
				$output[ $key ] = constant( $this->constant_map[ $key ] );
				continue;
			}

			$value = array_key_exists( $key, $input ) ? $input[ $key ] : $default;
			switch ( $key ) {
				case 'openai_api_base':
				case 'chatkit_script_url':
				case 'github_repo_url':
				case 'cal_standard_event_url':
				case 'cal_extended_event_url':
				case 'cal_large_event_url':
				case 'cal_project_event_url':
				case 'cal_fallback_url':
					$output[ $key ] = esc_url_raw( (string) $value );
					break;
				case 'service_area_zips':
				case 'cal_confirmation_note':
				case 'magic_link_email_body':
					$output[ $key ] = trim( str_replace( "\0", '', (string) $value ) );
					break;
				case 'log_max_entries_info':
				case 'log_max_entries_debug':
					$output[ $key ] = (string) max( 100, absint( $value ) );
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
				case 'twilio_account_sid':
				case 'twilio_auth_token':
				case 'twilio_verify_service_sid':
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
				case 'app_field_gap':
				case 'app_field_padding_bottom':
				case 'app_task_group_gap':
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
				case 'ui_address_help':
				case 'ui_address_valid_help':
				case 'ui_assistant_intro':
				case 'ui_assistant_helper':
				case 'ui_assistant_ready_notice':
				case 'ui_contact_intro':
				case 'ui_project_notice':
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

	/**
	 * @param array<int|string, mixed> $groups Raw catalog groups.
	 * @return array<int, array<string, mixed>>
	 */
	protected function sanitize_service_catalog_groups( array $groups ) {
		$sanitized = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$group_name = sanitize_text_field( $group['group'] ?? '' );
			$raw_tasks   = isset( $group['tasks'] ) && is_array( $group['tasks'] ) ? $group['tasks'] : array();
			$tasks       = array();

			foreach ( $raw_tasks as $task ) {
				if ( ! is_array( $task ) ) {
					continue;
				}

				$task_id = sanitize_key( $task['id'] ?? '' );
				$label   = sanitize_text_field( $task['label'] ?? '' );

				if ( '' === $task_id || '' === $label ) {
					continue;
				}

				$tasks[] = array(
					'id'             => $task_id,
					'label'          => $label,
					'description'    => sanitize_textarea_field( $task['description'] ?? '' ),
					'rate_label'     => sanitize_text_field( $task['rate_label'] ?? '' ),
					'service_family' => sanitize_key( $task['service_family'] ?? '' ),
					'rate_family'    => sanitize_key( $task['rate_family'] ?? '' ),
				);
			}

			if ( '' === $group_name || empty( $tasks ) ) {
				continue;
			}

			$sanitized[] = array(
				'group' => $group_name,
				'tasks' => $tasks,
			);
		}

		return $sanitized;
	}
}
