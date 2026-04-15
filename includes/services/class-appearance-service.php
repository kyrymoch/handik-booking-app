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
			'--handik-accent'         => (string) $this->settings->get( 'app_accent_color', '#0f766e' ),
			'--handik-background'     => (string) $this->settings->get( 'app_background', '#f8fafc' ),
			'--handik-surface'        => (string) $this->settings->get( 'app_surface', '#ffffff' ),
			'--handik-text'           => (string) $this->settings->get( 'app_text_color', '#0f172a' ),
			'--handik-border'         => (string) $this->settings->get( 'app_border_color', '#dbe3ea' ),
			'--handik-radius'         => (string) $this->settings->get( 'app_radius', '18' ) . 'px',
			'--handik-shadow'         => (string) $this->settings->get( 'app_shadow', '0 24px 60px rgba(15, 23, 42, 0.12)' ),
			'--handik-spacing'        => (string) $this->settings->get( 'app_spacing', '20' ) . 'px',
			'--handik-max-width'      => (string) $this->settings->get( 'app_max_width', '980' ) . 'px',
			'--handik-font-scale'     => (string) $this->settings->get( 'app_font_scale', '1' ),
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
}
