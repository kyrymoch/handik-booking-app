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

		$assistant_greeting = (string) $this->settings->get( 'ui_assistant_greeting', 'Describe the job.' );
		if ( '' === trim( $assistant_greeting ) || in_array( trim( $assistant_greeting ), array( 'Describe the task and I will help estimate time, materials, and the next step.', 'Describe the task and I will help estimate time, materials, and the next step', 'Describe the job.' ), true ) ) {
			$assistant_greeting = 'Describe the job.';
		}

		$assistant_intro = (string) $this->settings->get( 'ui_assistant_intro', (string) $this->settings->get( 'ui_assistant_helper', '' ) );
		if ( '' === trim( $assistant_intro ) || in_array( trim( $assistant_intro ), array( 'Describe the task, ask any questions you have, and continue when you are ready to choose a time.', 'This AI assistant can help estimate the job, time, materials, and next steps while collecting the details we need to prepare properly.', 'This AI assistant helps estimate the job, time, materials, and next steps while collecting the details we need to prepare properly.' ), true ) ) {
			$assistant_intro = 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping us collect the details needed to prepare for the job properly.';
		}

		$assistant_continue = (string) $this->settings->get( 'ui_assistant_continue_button', 'Book a time' );
		if ( '' === trim( $assistant_continue ) || in_array( trim( $assistant_continue ), array( 'Go to time and date selection', 'Choose time', 'Choose a time' ), true ) ) {
			$assistant_continue = 'Book a time';
		}

		$task_intro = (string) $this->settings->get( 'ui_task_selection_intro', 'Choose the option that best matches your request.' );
		if ( '' === trim( $task_intro ) || in_array( trim( $task_intro ), array( 'Choose one or more services so we can route your booking correctly.', 'Tap one or more services to select or remove them so we can route your booking correctly.' ), true ) ) {
			$task_intro = 'Choose the option that best matches your request.';
		}

		$contact_continue = (string) $this->settings->get( 'ui_contact_continue_button', 'Continue to Assistant' );
		if ( '' === trim( $contact_continue ) || 'Go to AI estimate' === trim( $contact_continue ) ) {
			$contact_continue = 'Continue to Assistant';
		}

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
					'loading'            => (string) $this->settings->get( 'ui_loading_title', 'Loading...' ),
					'loadingSubtext'     => (string) $this->settings->get( 'ui_loading_subtitle', '' ),
					'verify'             => (string) $this->settings->get( 'ui_verify_button', 'Verify' ),
					'sendCode'           => (string) $this->settings->get( 'ui_send_code_button', 'Send one-time code' ),
					'continue'           => (string) $this->settings->get( 'ui_continue_button', 'Continue' ),
					'back'               => (string) $this->settings->get( 'ui_back_button', 'Back' ),
					'bookNow'            => __( 'Book a visit', 'handik-booking-app' ),
					'openBooking'        => (string) $this->settings->get( 'ui_open_booking_button', 'Open calendar in new tab' ),
					'completeBooking'    => (string) $this->settings->get( 'ui_complete_booking_button', 'Check booking status' ),
					'launchAssistant'    => __( 'Open assistant', 'handik-booking-app' ),
					'uploading'          => (string) $this->settings->get( 'ui_photos_loading', 'Uploading your photos...' ),
					'unsafeTitle'        => (string) $this->settings->get( 'ui_unsafe_title', 'We need to stop the normal booking flow' ),
					'assistantGreeting'  => $assistant_greeting,
					'assistantReadyNotice' => (string) $this->settings->get( 'ui_assistant_ready_notice', 'The virtual assistant has enough information. Continue when you are ready.' ),
					'bookingWaiting'     => (string) $this->settings->get( 'ui_booking_waiting', 'Stay on this screen while we wait for Cal.com to confirm the booking.' ),
					'bookingConfirmed'   => (string) $this->settings->get( 'ui_booking_confirmed', 'Booking confirmed. Finishing your request...' ),
					'bookingCancelled'   => (string) $this->settings->get( 'ui_booking_cancelled', 'This booking was cancelled. You can choose another slot below.' ),
					'addressPlaceholder' => (string) $this->settings->get( 'ui_address_placeholder', 'Start typing the address of the job' ),
					'loadingAssistant'   => (string) $this->settings->get( 'ui_loading_assistant_title', 'Loading...' ),
					'loadingAssistantSubtext' => (string) $this->settings->get( 'ui_loading_assistant_subtitle', '' ),
					'clientTypeTitle'    => (string) $this->settings->get( 'ui_client_type_title', 'Who is booking today?' ),
					'clientTypeIntro'    => (string) $this->settings->get( 'ui_client_type_intro', 'Choose the option that best matches your situation.' ),
					'newClientLabel'     => (string) $this->settings->get( 'ui_new_client_label', 'New client' ),
					'returningClientLabel' => (string) $this->settings->get( 'ui_returning_client_label', 'Returning client' ),
					'verifyTitle'        => (string) $this->settings->get( 'ui_returning_verify_title', 'Returning client verification' ),
					'verifyIntro'        => (string) $this->settings->get( 'ui_returning_verify_intro', 'Enter your email or phone to receive a one-time code.' ),
					'taskTitle'          => (string) $this->settings->get( 'ui_task_selection_title', 'What do you need help with?' ),
					'taskIntro'          => $task_intro,
					'projectLabel'       => (string) $this->settings->get( 'ui_project_label', 'Complex Project Work' ),
					'addressTitle'       => (string) $this->settings->get( 'ui_address_title', 'Address details' ),
					'addressLabel'       => (string) $this->settings->get( 'ui_address_label', 'Address of the job' ),
					'addressHelp'        => (string) $this->settings->get( 'ui_address_help', '' ),
					'addressValidHelp'   => (string) $this->settings->get( 'ui_address_valid_help', '' ),
					'unitLabel'          => (string) $this->settings->get( 'ui_address_unit_label', 'Unit or apartment (optional)' ),
					'photosTitle'        => (string) $this->settings->get( 'ui_photos_title', 'Photos' ),
					'photosIntro'        => (string) $this->settings->get( 'ui_photos_intro', 'Upload a few clear photos if you have them. We review them before the AI assistant opens.' ),
					'savedAddressLabel'  => (string) $this->settings->get( 'ui_saved_address_label', 'Saved address' ),
					'savedAddressPlaceholder' => (string) $this->settings->get( 'ui_saved_address_placeholder', 'Choose saved address' ),
					'photosLabel'        => (string) $this->settings->get( 'ui_photos_label', 'Photos' ),
					'photosHelp'         => (string) $this->settings->get( 'ui_photos_help', 'Add a few clear photos so we can review the job visually before the assistant starts.' ),
					'photosCta'          => (string) $this->settings->get( 'ui_photos_cta', 'Tap to add photos' ),
					'photosEmpty'        => (string) $this->settings->get( 'ui_photos_empty', 'No photos added yet' ),
					'assistantTitle'     => (string) $this->settings->get( 'ui_assistant_title', 'Virtual assistant' ),
					'assistantIntro'     => $assistant_intro,
					'assistantContinue'  => $assistant_continue,
					'contactContinue'    => $contact_continue,
					'contactIntro'       => (string) $this->settings->get( 'ui_contact_intro', 'This is the last step where you can change the booking details before the AI review starts.' ),
					'projectNotice'      => (string) $this->settings->get( 'ui_project_notice', 'Project / Large Job means a bigger scope that usually needs a consultation-style visit before the work is scheduled.' ),
					'contactTitle'       => (string) $this->settings->get( 'ui_contact_title', 'Contact details' ),
					'bookingTitle'       => (string) $this->settings->get( 'ui_booking_title', 'Book your time slot' ),
					'unsafeBody'         => (string) $this->settings->get( 'ui_unsafe_body', 'This request needs manual review before booking.' ),
					'restart'            => (string) $this->settings->get( 'ui_restart_button', 'Start another booking' ),
					'errors'             => array(
						'pickClientType' => (string) $this->settings->get( 'ui_error_pick_client_type', 'Choose whether you are a new client or a returning client to continue.' ),
						'selectTask'     => (string) $this->settings->get( 'ui_error_select_task', 'Select at least one task or mark this as a project.' ),
						'addressRequired'=> (string) $this->settings->get( 'ui_error_address_required', 'Choose a valid address from the Google suggestions before continuing.' ),
						'invalidCode'    => (string) $this->settings->get( 'ui_error_invalid_code', 'Code or magic link is invalid or expired.' ),
						'assistantRequired' => (string) $this->settings->get( 'ui_error_assistant_required', 'Please send the virtual assistant a short description of the job before continuing.' ),
						'nameEmailRequired' => (string) $this->settings->get( 'ui_error_name_email_required', 'Name and email are required before you can continue.' ),
						'phoneOrEmailRequired' => (string) $this->settings->get( 'ui_error_phone_or_email_required', 'Enter your email or phone, then request a code.' ),
						'invalidName'    => (string) $this->settings->get( 'ui_error_invalid_name', 'Enter a real full name using letters only.' ),
						'invalidEmail'   => (string) $this->settings->get( 'ui_error_invalid_email', 'Enter a valid email address before continuing.' ),
						'invalidPhone'   => (string) $this->settings->get( 'ui_error_invalid_phone', 'Enter a phone number in the format +1 123 456 78 90.' ),
					),
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

