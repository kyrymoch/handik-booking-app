<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Widget_Registry {
	public function __construct() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
	}

	/**
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once HANDIK_BOOKING_APP_PATH . 'widgets/class-elementor-booking-app-widget.php';
		$widgets_manager->register( new Handik_Booking_App_Elementor_Widget() );
	}
}
