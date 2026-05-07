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
		if ( '' === trim( $assistant_intro ) || in_array( trim( $assistant_intro ), array( 'Describe the task, ask any questions you have, and continue when you are ready to choose a time.', 'This AI assistant can help estimate the job, time, materials, and next steps while collecting the details we need to prepare properly.', 'This AI assistant helps estimate the job, time, materials, and next steps while collecting the details we need to prepare properly.', 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping us collect the details needed to prepare for the job properly.' ), true ) ) {
			$assistant_intro = 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping Alex collect the details needed to prepare for the job properly.';
		}

		$assistant_continue = (string) $this->settings->get( 'ui_assistant_continue_button', 'Book a time' );
		if ( '' === trim( $assistant_continue ) || in_array( trim( $assistant_continue ), array( 'Go to time and date selection', 'Choose time', 'Choose a time' ), true ) ) {
			$assistant_continue = 'Book a time';
		}

		$task_intro = (string) $this->settings->get( 'ui_task_selection_intro', 'Choose the option that best matches your request.' );
		if ( '' === trim( $task_intro ) || in_array( trim( $task_intro ), array( 'Choose one or more services so we can route your booking correctly.', 'Tap one or more services to select or remove them so we can route your booking correctly.' ), true ) ) {
			$task_intro = 'Choose the option that best matches your request.';
		}

		$contact_continue = (string) $this->settings->get( 'ui_contact_continue_button', 'Continue' );
		if ( '' === trim( $contact_continue ) || in_array( trim( $contact_continue ), array( 'Go to AI estimate', 'Continue to Assistant' ), true ) ) {
			$contact_continue = 'Continue';
		}

		$saved_address_label = (string) $this->settings->get( 'ui_saved_address_label', 'Choose a saved address or enter a new one' );
		if ( '' === trim( $saved_address_label ) || 'Saved address' === trim( $saved_address_label ) ) {
			$saved_address_label = 'Choose a saved address or enter a new one';
		}

		$photos_cta = (string) $this->settings->get( 'ui_photos_cta', 'Add photos or videos' );
		if ( '' === trim( $photos_cta ) || in_array( trim( $photos_cta ), array( 'Tap to add photos', 'Tap to add photos or videos' ), true ) ) {
			$photos_cta = 'Add photos or videos';
		}

		$photos_empty = (string) $this->settings->get( 'ui_photos_empty', 'No photos or videos added yet' );
		if ( '' === trim( $photos_empty ) || 'No photos added yet' === trim( $photos_empty ) ) {
			$photos_empty = 'No photos or videos added yet';
		}

		$photos_title = (string) $this->settings->get( 'ui_photos_title', 'Photos / Videos of the Work Area' );
		if ( '' === trim( $photos_title ) || in_array( trim( $photos_title ), array( 'Photos', 'Photos of the Work Area' ), true ) ) {
			$photos_title = 'Photos / Videos of the Work Area';
		}

		$photos_intro = (string) $this->settings->get( 'ui_photos_intro', 'Upload photos or short videos of the problem area, item, fixture, wall, appliance, or installation spot.' );
		if ( '' === trim( $photos_intro ) || in_array( trim( $photos_intro ), array( 'Photos really help us understand the job faster, but you can continue without them if needed.', 'Upload a few clear photos if you have them. We review them before the AI assistant opens.', 'Upload clear photos of the area, item, fixture, wall, or problem you need help with. Photos help the assistant estimate time, materials, and the right booking type.' ), true ) ) {
			$photos_intro = 'Upload photos or short videos of the problem area, item, fixture, wall, appliance, or installation spot.';
		}

		$contact_intro = (string) $this->settings->get( 'ui_contact_intro', "Tell us how to reach you. If you've booked here before, we'll recognize you." );
		if ( '' === trim( $contact_intro ) || 'This is the last step where you can change the booking details before the AI review starts.' === trim( $contact_intro ) ) {
			$contact_intro = "Tell us how to reach you. If you've booked here before, we'll recognize you.";
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
				'calFallbackUrl' => esc_url_raw( (string) ( $this->settings->get( 'cal_fallback_url', '' ) ?: $this->settings->get( 'cal_standard_event_url', '' ) ) ),
				'serviceableZips' => array_values( array_filter( array_map( 'trim', preg_split( '/\R+/', (string) $this->settings->get( 'serviceable_zips', '' ) ) ?: array() ) ) ),
				'strings'    => array(
					'loading'            => (string) $this->settings->get( 'ui_loading_title', 'Loading...' ),
					'loadingSubtext'     => (string) $this->settings->get( 'ui_loading_subtitle', '' ),
					'continue'           => (string) $this->settings->get( 'ui_continue_button', 'Continue' ),
					'back'               => (string) $this->settings->get( 'ui_back_button', 'Back' ),
					// Sprint 6 — phone-first OTP flow.
					'phoneLabel'         => __( 'Phone', 'handik-booking-app' ),
					'phonePlaceholder'   => __( '+1 555 123 4567', 'handik-booking-app' ),
					'fullNameLabel'      => __( 'Full name', 'handik-booking-app' ),
					'fullNamePlaceholder' => __( 'Jane Smith', 'handik-booking-app' ),
					'emailLabel'         => __( 'Email (optional)', 'handik-booking-app' ),
					'emailPlaceholder'   => __( 'you@example.com', 'handik-booking-app' ),
					'phoneStepIntro'     => __( "We'll text you a one-time code to confirm.", 'handik-booking-app' ),
					'sendCodeCta'        => __( 'Send code', 'handik-booking-app' ),
					'otpStepTitle'       => __( 'Enter the code', 'handik-booking-app' ),
					/* translators: %s: phone number with country code. */
					'otpIntro'           => __( 'Enter the 6-digit code we just sent to %s. We will verify it automatically.', 'handik-booking-app' ),
					'otpCodeLabel'       => __( 'Verification code', 'handik-booking-app' ),
					'otpPlaceholder'     => __( '6-digit code', 'handik-booking-app' ),
					'otpInvalid'         => __( 'That code is invalid or expired. Please try again.', 'handik-booking-app' ),
					'otpResendCta'       => __( 'Resend code', 'handik-booking-app' ),
					/* translators: %d: seconds remaining. */
					'otpResendIn'        => __( 'You can resend in %ds', 'handik-booking-app' ),
					'otpDifferentNumberCta' => __( 'Use a different number', 'handik-booking-app' ),
					'otpSentToast'       => __( 'Code sent.', 'handik-booking-app' ),
					'verifyCta'          => __( 'Verify', 'handik-booking-app' ),
					'welcomeBack'        => __( 'Welcome back — we found your saved details.', 'handik-booking-app' ),
					'bookNow'            => __( 'Book a visit', 'handik-booking-app' ),
					'openBooking'        => (string) $this->settings->get( 'ui_open_booking_button', 'Open calendar in new tab' ),
					'completeBooking'    => (string) $this->settings->get( 'ui_complete_booking_button', 'Check booking status' ),
					'launchAssistant'    => __( 'Open assistant', 'handik-booking-app' ),
					'uploading'          => (string) $this->settings->get( 'ui_photos_loading', 'Uploading your files...' ),
					'unsafeTitle'        => (string) $this->settings->get( 'ui_unsafe_title', 'We need to stop the normal booking flow' ),
					'assistantGreeting'  => $assistant_greeting,
					'assistantReadyNotice' => (string) $this->settings->get( 'ui_assistant_ready_notice', 'The virtual assistant has enough information. Continue when you are ready.' ),
					'bookingWaiting'     => (string) $this->settings->get( 'ui_booking_waiting', 'Hang tight - confirming your booking.' ),
					'bookingConfirmed'   => (string) $this->settings->get( 'ui_booking_confirmed', 'Booking confirmed. Finishing your request...' ),
					'bookingCancelled'   => (string) $this->settings->get( 'ui_booking_cancelled', 'This booking was cancelled. You can choose another slot below.' ),
					'addressPlaceholder' => (string) $this->settings->get( 'ui_address_placeholder', 'Start typing the address of the job' ),
					'loadingAssistant'   => (string) $this->settings->get( 'ui_loading_assistant_title', 'Loading...' ),
					'loadingAssistantSubtext' => (string) $this->settings->get( 'ui_loading_assistant_subtitle', '' ),
					'taskTitle'          => (string) $this->settings->get( 'ui_task_selection_title', 'What do you need help with?' ),
					'taskIntro'          => $task_intro,
					'projectLabel'       => (string) $this->settings->get( 'ui_project_label', 'Complex Project Work' ),
					'addressTitle'       => (string) $this->settings->get( 'ui_address_title', 'Address details' ),
					'addressLabel'       => (string) $this->settings->get( 'ui_address_label', 'Address of the job' ),
					'addressHelp'        => (string) $this->settings->get( 'ui_address_help', '' ),
					'addressValidHelp'   => (string) $this->settings->get( 'ui_address_valid_help', '' ),
					'unitLabel'          => (string) $this->settings->get( 'ui_address_unit_label', 'Unit or apartment (optional)' ),
					'photosTitle'        => $photos_title,
					'photosIntro'        => $photos_intro,
					'savedAddressLabel'  => $saved_address_label,
					'savedAddressPlaceholder' => (string) $this->settings->get( 'ui_saved_address_placeholder', 'Choose saved address' ),
					'photosCta'          => $photos_cta,
					'photosEmpty'        => $photos_empty,
					'assistantTitle'     => (string) $this->settings->get( 'ui_assistant_title', 'Virtual assistant' ),
					'assistantIntro'     => $assistant_intro,
					'assistantContinue'  => $assistant_continue,
					// v2.1.8.9 UX: thinking indicator + Plan-B copy.
					'assistantThinking'  => (string) $this->settings->get( 'ui_assistant_thinking', 'Thinking…' ),
					'assistantStuckTitle' => (string) $this->settings->get( 'ui_assistant_stuck_title', 'The assistant is taking longer than usual' ),
					'assistantStuckBody'  => (string) $this->settings->get( 'ui_assistant_stuck_body', 'You can keep waiting, or open the booking page directly and Alex will sort out the details on site.' ),
					'assistantStuckCta'   => (string) $this->settings->get( 'ui_assistant_stuck_cta', 'Open the booking page directly →' ),
					'contactContinue'    => $contact_continue,
					'contactIntro'       => $contact_intro,
					'projectNotice'      => (string) $this->settings->get( 'ui_project_notice', 'Project / Large Job means a bigger scope that usually needs a consultation-style visit before the work is scheduled.' ),
					'contactTitle'       => (string) $this->settings->get( 'ui_contact_title', 'Contact details' ),
					'bookingTitle'       => (string) $this->settings->get( 'ui_booking_title', 'Book your time slot' ),
					'unsafeBody'         => (string) $this->settings->get( 'ui_unsafe_body', 'This request needs manual review before booking.' ),
					'restart'            => (string) $this->settings->get( 'ui_restart_button', 'Start another booking' ),
					'errors'             => array(
						'selectTask'     => (string) $this->settings->get( 'ui_error_select_task', 'Select at least one task or mark this as a project.' ),
						'addressRequired'=> (string) $this->settings->get( 'ui_error_address_required', 'Choose a valid address from the Google suggestions before continuing.' ),
						'assistantRequired' => (string) $this->settings->get( 'ui_error_assistant_required', 'Please send the virtual assistant a short description of the job before continuing.' ),
						'nameEmailRequired' => (string) $this->settings->get( 'ui_error_name_email_required', 'Name and phone are required before you can continue.' ),
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

