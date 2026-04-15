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
			'cal_standard_event_url' => '',
			'cal_extended_event_url' => '',
			'cal_large_event_url'    => '',
			'cal_project_event_url'  => '',
			'cal_webhook_secret'     => '',
			'email_from_name'        => get_bloginfo( 'name' ),
			'email_from_address'     => get_option( 'admin_email' ),
			'debug_mode'             => 0,
			'app_accent_color'       => '#0f766e',
			'app_background'         => '#f8fafc',
			'app_surface'            => '#ffffff',
			'app_text_color'         => '#0f172a',
			'app_border_color'       => '#dbe3ea',
			'app_radius'             => '18',
			'app_shadow'             => '0 24px 60px rgba(15, 23, 42, 0.12)',
			'app_spacing'            => '20',
			'app_max_width'          => '980',
			'app_font_scale'         => '1',
			'app_button_style'       => 'pill',
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
				case 'app_radius':
				case 'app_spacing':
				case 'app_max_width':
				case 'app_font_scale':
					$output[ $key ] = preg_replace( '/[^0-9.]/', '', (string) $value );
					break;
				default:
					$output[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $output;
	}
}
