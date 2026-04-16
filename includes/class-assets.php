<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Assets {
	/**
	 * @var Handik_Booking_App_Appearance_Service
	 */
	protected $appearance;

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @param Handik_Booking_App_Appearance_Service $appearance Appearance.
	 * @param Handik_Booking_App_Settings           $settings Settings.
	 */
	public function __construct( $appearance, $settings ) {
		$this->appearance = $appearance;
		$this->settings   = $settings;

		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_assets' ) );
	}

	public function register_frontend_assets() {
		wp_register_style( 'handik-booking-app', HANDIK_BOOKING_APP_URL . 'assets/booking-app.css', array(), HANDIK_BOOKING_APP_VERSION );
		wp_register_script( 'handik-booking-app-chatkit-hosted', 'https://cdn.platform.openai.com/deployments/chatkit/chatkit.js', array(), null, true );
		wp_register_script( 'handik-booking-app-chatkit-bridge', $this->bridge_url(), array( 'handik-booking-app-chatkit-hosted' ), HANDIK_BOOKING_APP_VERSION, true );
		wp_register_script( 'handik-booking-app', HANDIK_BOOKING_APP_URL . 'assets/booking-app.js', array( 'handik-booking-app-chatkit-bridge' ), HANDIK_BOOKING_APP_VERSION, true );
	}

	public function register_admin_assets() {
		wp_register_style( 'handik-booking-app-admin', HANDIK_BOOKING_APP_URL . 'assets/booking-app-admin.css', array(), HANDIK_BOOKING_APP_VERSION );
		wp_register_script( 'handik-booking-app-admin', HANDIK_BOOKING_APP_URL . 'assets/booking-app-admin.js', array( 'jquery' ), HANDIK_BOOKING_APP_VERSION, true );
	}

	public function enqueue_frontend() {
		wp_enqueue_style( 'handik-booking-app' );
		wp_enqueue_script( 'handik-booking-app-chatkit-hosted' );
		wp_enqueue_script( 'handik-booking-app-chatkit-bridge' );
		wp_enqueue_script( 'handik-booking-app' );
		wp_localize_script(
			'handik-booking-app',
			'HandikBookingAppConfig',
			array(
				'restBase'   => esc_url_raw( rest_url( 'handik-booking-app/v1/' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'version'    => HANDIK_BOOKING_APP_VERSION,
				'appearance' => $this->appearance->css_variables(),
				'googleMapsApiKey' => (string) $this->settings->get( 'google_maps_api_key', '' ),
				'googleMapsCountry' => strtolower( (string) $this->settings->get( 'google_maps_country', 'us' ) ),
				'strings'    => array(
					'loading'            => __( 'Loading booking app...', 'handik-booking-app' ),
					'verify'             => __( 'Verify', 'handik-booking-app' ),
					'sendCode'           => __( 'Send one-time code', 'handik-booking-app' ),
					'continue'           => __( 'Continue', 'handik-booking-app' ),
					'back'               => __( 'Back', 'handik-booking-app' ),
					'bookNow'            => __( 'Book a visit', 'handik-booking-app' ),
					'openBooking'        => __( 'Open calendar in new tab', 'handik-booking-app' ),
					'completeBooking'    => __( 'Check booking status', 'handik-booking-app' ),
					'launchAssistant'    => __( 'Open assistant', 'handik-booking-app' ),
					'uploading'          => __( 'Uploading photos...', 'handik-booking-app' ),
					'unsafeTitle'        => __( 'We need to stop the normal booking flow', 'handik-booking-app' ),
					'successTitle'       => __( 'Your booking flow is in progress', 'handik-booking-app' ),
					'assistantGreeting'  => __( 'Describe the job, and I will help estimate time, materials, and the next step.', 'handik-booking-app' ),
					'bookingWaiting'     => __( 'Stay on this screen while we wait for Cal.com to confirm the booking.', 'handik-booking-app' ),
					'bookingConfirmed'   => __( 'Booking confirmed. Finishing your request...', 'handik-booking-app' ),
					'bookingCancelled'   => __( 'This booking was cancelled. You can book another slot below.', 'handik-booking-app' ),
					'addressPlaceholder' => __( 'Start typing the address of the job', 'handik-booking-app' ),
				),
			)
		);
	}

	public function enqueue_admin() {
		wp_enqueue_style( 'handik-booking-app-admin' );
		wp_enqueue_script( 'handik-booking-app-admin' );
	}

	/**
	 * @return string
	 */
	protected function bridge_url() {
		$custom = trim( (string) $this->settings->get( 'chatkit_script_url', '' ) );
		return $custom ? esc_url_raw( $custom ) : HANDIK_BOOKING_APP_URL . 'assets/handik-chatkit-bridge.js';
	}
}
