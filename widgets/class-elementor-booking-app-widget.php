<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( '\Elementor\Widget_Base' ) ) {
	class Handik_Booking_App_Elementor_Widget extends \Elementor\Widget_Base {
		public function get_name() {
			return 'handik_booking_app';
		}

		public function get_title() {
			return __( 'Handik Booking App', 'handik-booking-app' );
		}

		public function get_icon() {
			return 'eicon-form-horizontal';
		}

		public function get_categories() {
			return array( 'general' );
		}

		protected function register_controls() {
			$this->start_controls_section( 'content_section', array( 'label' => __( 'Booking App', 'handik-booking-app' ) ) );
			$this->add_control( 'title', array( 'label' => __( 'Title', 'handik-booking-app' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Book a visit', 'handik-booking-app' ) ) );
			$this->add_control( 'accent', array( 'label' => __( 'Accent Color Override', 'handik-booking-app' ), 'type' => \Elementor\Controls_Manager::COLOR ) );
			$this->add_control( 'max_width', array( 'label' => __( 'Max Width (px)', 'handik-booking-app' ), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 980 ) );
			$this->end_controls_section();
		}

		protected function render() {
			echo handik_booking_app()->frontend_app->render(
				array(
					'title'     => $this->get_settings_for_display( 'title' ),
					'accent'    => $this->get_settings_for_display( 'accent' ),
					'max_width' => $this->get_settings_for_display( 'max_width' ),
					'display'   => 'widget',
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
