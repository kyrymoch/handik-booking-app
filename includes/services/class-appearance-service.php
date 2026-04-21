<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Appearance_Service {
	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @param Handik_Booking_App_Settings $settings Settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return array<string, string>
	 */
	public function css_variables() {
		return array(
			'--handik-accent'         => (string) $this->settings->get( 'app_accent_color', '#283618' ),
			'--handik-background'     => (string) $this->settings->get( 'app_background', '#f8fafc' ),
			'--handik-surface'        => (string) $this->settings->get( 'app_surface', '#ffffff' ),
			'--handik-text'           => (string) $this->settings->get( 'app_text_color', '#0f172a' ),
			'--handik-border'         => (string) $this->settings->get( 'app_border_color', '#dbe3ea' ),
			'--handik-muted-text'     => (string) $this->settings->get( 'app_muted_text_color', '#64748b' ),
			'--handik-primary-text'   => (string) $this->settings->get( 'app_button_text_color', '#ffffff' ),
			'--handik-footer-button-border' => (string) $this->settings->get( 'app_button_border_color', 'var(--e-global-color-d50b40a)' ),
			'--handik-footer-button-inactive-bg' => (string) $this->settings->get( 'app_footer_button_inactive_bg', '#f9f9f9' ),
			'--handik-footer-button-inactive-text' => (string) $this->settings->get( 'app_footer_button_inactive_text', '#1a1a1c' ),
			'--handik-footer-button-active-bg' => (string) $this->settings->get( 'app_footer_button_active_bg', '#1a1a1c' ),
			'--handik-footer-button-active-text' => (string) $this->settings->get( 'app_footer_button_active_text', '#f9f9f9' ),
			'--handik-secondary-bg'   => (string) $this->settings->get( 'app_secondary_button_bg', '#e2e8f0' ),
			'--handik-secondary-text' => (string) $this->settings->get( 'app_secondary_button_text', '#0f172a' ),
			'--handik-pending-bg'     => (string) $this->settings->get( 'app_pending_button_bg', '#cbd5e1' ),
			'--handik-pending-text'   => (string) $this->settings->get( 'app_pending_button_text', '#334155' ),
			'--handik-progress-track' => (string) $this->settings->get( 'app_progress_track', '#dbe3ea' ),
			'--handik-font-family'    => (string) $this->settings->get( 'app_font_family', 'inherit' ),
			'--handik-radius'         => (string) $this->settings->get( 'app_radius', '18' ) . 'px',
			'--handik-shadow'         => (string) $this->settings->get( 'app_shadow', '0 24px 60px rgba(15, 23, 42, 0.12)' ),
			'--handik-spacing'        => (string) $this->settings->get( 'app_spacing', '20' ) . 'px',
			'--handik-max-width'      => (string) $this->settings->get( 'app_max_width', '980' ) . 'px',
			'--handik-font-scale'     => (string) $this->settings->get( 'app_font_scale', '1' ),
			'--handik-field-gap'      => (string) $this->settings->get( 'app_field_gap', '8' ) . 'px',
			'--handik-field-padding-bottom' => (string) $this->settings->get( 'app_field_padding_bottom', '0' ) . 'px',
			'--handik-task-group-gap' => (string) $this->settings->get( 'app_task_group_gap', '10' ) . 'px',
			'--handik-button-radius'  => 'pill' === $this->settings->get( 'app_button_style', 'pill' ) ? '999px' : (string) $this->settings->get( 'app_radius', '18' ) . 'px',
		);
	}

	/**
	 * @return string
	 */
	public function inline_style_string() {
		$parts = array();
		foreach ( $this->css_variables() as $key => $value ) {
			$parts[] = $key . ': ' . $value;
		}

		return implode( '; ', $parts );
	}

	/**
	 * @param string $wrapper_selector Wrapper selector.
	 * @return string
	 */
	public function custom_css( $wrapper_selector ) {
		$css = trim( (string) $this->settings->get( 'app_custom_css', '' ) );
		if ( '' === $css ) {
			return '';
		}

		return str_replace( '{{WRAPPER}}', $wrapper_selector, $css );
	}
}
