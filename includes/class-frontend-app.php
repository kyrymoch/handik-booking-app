<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Frontend_App {
	/**
	 * @var Handik_Booking_App_Assets
	 */
	protected $assets;

	/**
	 * @var Handik_Booking_App_Appearance_Service
	 */
	protected $appearance;

	/**
	 * @param Handik_Booking_App_Assets             $assets Assets.
	 * @param Handik_Booking_App_Appearance_Service $appearance Appearance.
	 */
	public function __construct( $assets, $appearance ) {
		$this->assets     = $assets;
		$this->appearance = $appearance;
	}

	/**
	 * @param array<string, mixed> $args Args.
	 * @return string
	 */
	public function render( array $args = array() ) {
		$this->assets->enqueue_frontend();

		$instance_id  = 'handik-booking-app-' . wp_generate_password( 8, false, false );
		$title        = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Book a visit', 'handik-booking-app' );
		$style        = $this->appearance->inline_style_string();

		if ( ! empty( $args['accent'] ) ) {
			$style .= '; --handik-accent: ' . sanitize_hex_color( $args['accent'] );
		}
		if ( ! empty( $args['max_width'] ) ) {
			$style .= '; --handik-max-width: ' . preg_replace( '/[^0-9.]/', '', (string) $args['max_width'] ) . 'px';
		}

		ob_start();
		$view_args = array(
			'instance_id' => $instance_id,
			'title'       => $title,
			'style'       => $style,
			'display'     => ! empty( $args['display'] ) ? sanitize_key( $args['display'] ) : 'full',
		);
		include HANDIK_BOOKING_APP_PATH . 'views/frontend-app.php';
		return (string) ob_get_clean();
	}
}
