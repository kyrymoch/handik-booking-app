<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Shortcode {
	/**
	 * @var Handik_Booking_App_Frontend_App
	 */
	protected $frontend_app;

	/**
	 * @param Handik_Booking_App_Frontend_App $frontend_app Frontend app.
	 */
	public function __construct( $frontend_app ) {
		$this->frontend_app = $frontend_app;
		add_shortcode( 'handik_booking_app', array( $this, 'render' ) );
	}

	/**
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'     => __( 'Book a visit', 'handik-booking-app' ),
				'accent'    => '',
				'max_width' => '',
				'display'   => 'full',
			),
			(array) $atts,
			'handik_booking_app'
		);
		return $this->frontend_app->render( $atts );
	}
}
