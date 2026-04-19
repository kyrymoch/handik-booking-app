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
					'loading'            => (string) $this->settings->get( 'ui_loading_title', 'Загрузка...' ),
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
					'successTitle'       => (string) $this->settings->get( 'ui_success_title', 'Success' ),
					'assistantGreeting'  => (string) $this->settings->get( 'ui_assistant_greeting', 'Describe the task and I will help estimate time, materials, and the next step.' ),
					'assistantHelper'    => (string) $this->settings->get( 'ui_assistant_helper', 'This is Handik\'s virtual assistant. Describe the job, ask any questions about time or materials, then tap Continue when you are ready.' ),
					'assistantReadyNotice' => (string) $this->settings->get( 'ui_assistant_ready_notice', 'The virtual assistant has enough information. Continue when you are ready.' ),
					'bookingWaiting'     => (string) $this->settings->get( 'ui_booking_waiting', 'Stay on this screen while we wait for Cal.com to confirm the booking.' ),
					'bookingConfirmed'   => (string) $this->settings->get( 'ui_booking_confirmed', 'Booking confirmed. Finishing your request...' ),
					'bookingCancelled'   => (string) $this->settings->get( 'ui_booking_cancelled', 'This booking was cancelled. You can choose another slot below.' ),
					'addressPlaceholder' => (string) $this->settings->get( 'ui_address_placeholder', 'Start typing the address of the job' ),
					'loadingAssistant'   => (string) $this->settings->get( 'ui_loading_assistant_title', 'Загрузка...' ),
					'loadingAssistantSubtext' => (string) $this->settings->get( 'ui_loading_assistant_subtitle', '' ),
					'clientTypeTitle'    => (string) $this->settings->get( 'ui_client_type_title', 'Who is booking today?' ),
					'clientTypeIntro'    => (string) $this->settings->get( 'ui_client_type_intro', 'Choose the option that best matches your situation.' ),
					'newClientLabel'     => (string) $this->settings->get( 'ui_new_client_label', 'New client' ),
					'returningClientLabel' => (string) $this->settings->get( 'ui_returning_client_label', 'Returning client' ),
					'newClientTooltipTitle' => (string) $this->settings->get( 'ui_new_client_tooltip_title', 'New client' ),
					'newClientTooltipText' => (string) $this->settings->get( 'ui_new_client_tooltip_text', 'New client means someone who has never booked through this form before.' ),
					'returningClientTooltipTitle' => (string) $this->settings->get( 'ui_returning_client_tooltip_title', 'Returning client' ),
					'returningClientTooltipText' => (string) $this->settings->get( 'ui_returning_client_tooltip_text', 'Returning client means someone who has already booked through this form before.' ),
					'verifyTitle'        => (string) $this->settings->get( 'ui_returning_verify_title', 'Returning client verification' ),
					'verifyIntro'        => (string) $this->settings->get( 'ui_returning_verify_intro', 'Enter your email or phone to receive a one-time code.' ),
					'taskTitle'          => (string) $this->settings->get( 'ui_task_selection_title', 'What do you need help with?' ),
					'taskIntro'          => (string) $this->settings->get( 'ui_task_selection_intro', 'Choose one or more services so we can route your booking correctly.' ),
					'projectLabel'       => (string) $this->settings->get( 'ui_project_label', 'Project / Large Job' ),
					'addressTitle'       => (string) $this->settings->get( 'ui_address_title', 'Address details' ),
					'addressLabel'       => (string) $this->settings->get( 'ui_address_label', 'Address of the job' ),
					'unitLabel'          => (string) $this->settings->get( 'ui_address_unit_label', 'Unit or apartment (optional)' ),
					'photosTitle'        => (string) $this->settings->get( 'ui_photos_title', 'Photos' ),
					'photosIntro'        => (string) $this->settings->get( 'ui_photos_intro', 'Photos really help us understand the job faster, but you can continue without them if needed.' ),
					'skipPhotos'         => (string) $this->settings->get( 'ui_skip_photos_button', 'Skip photos' ),
					'savedAddressLabel'  => (string) $this->settings->get( 'ui_saved_address_label', 'Saved address' ),
					'savedAddressPlaceholder' => (string) $this->settings->get( 'ui_saved_address_placeholder', 'Choose saved address' ),
					'photosLabel'        => (string) $this->settings->get( 'ui_photos_label', 'Photos' ),
					'photosHelp'         => (string) $this->settings->get( 'ui_photos_help', 'Add a few clear photos so we can understand the job faster.' ),
					'photosCta'          => (string) $this->settings->get( 'ui_photos_cta', 'Tap to add photos' ),
					'photosEmpty'        => (string) $this->settings->get( 'ui_photos_empty', 'No photos added yet' ),
					'assistantTitle'     => (string) $this->settings->get( 'ui_assistant_title', 'Virtual assistant' ),
					'assistantContinue'  => (string) $this->settings->get( 'ui_assistant_continue_button', 'Go to time and date selection' ),
					'contactContinue'    => (string) $this->settings->get( 'ui_contact_continue_button', 'Go to AI estimate' ),
					'contactIntro'       => (string) $this->settings->get( 'ui_contact_intro', 'This is the last step where you can change the booking details before the AI review starts.' ),
					'projectNotice'      => (string) $this->settings->get( 'ui_project_notice', 'Project / Large Job means a bigger scope that usually needs a consultation-style visit before the work is scheduled.' ),
					'infoModeTooltip'    => (string) $this->settings->get( 'ui_info_mode_tooltip', 'Toggle helper tips and descriptive notifications on or off.' ),
					'infoModeEnabledMessage' => (string) $this->settings->get( 'ui_info_mode_enabled_message', 'Hints are enabled.' ),
					'infoModeDisabledMessage' => (string) $this->settings->get( 'ui_info_mode_disabled_message', 'Hints are disabled.' ),
					'contactTitle'       => (string) $this->settings->get( 'ui_contact_title', 'Contact details' ),
					'bookingTitle'       => (string) $this->settings->get( 'ui_booking_title', 'Book your time slot' ),
					'successBody'        => (string) $this->settings->get( 'ui_success_body', 'Your booking has been confirmed and saved.' ),
					'unsafeBody'         => (string) $this->settings->get( 'ui_unsafe_body', 'This request needs manual review before booking.' ),
					'restart'            => (string) $this->settings->get( 'ui_restart_button', 'Start another booking' ),
					'errors'             => array(
						'pickClientType' => (string) $this->settings->get( 'ui_error_pick_client_type', 'Choose whether you are a new client or a returning client to continue.' ),
						'selectTask'     => (string) $this->settings->get( 'ui_error_select_task', 'Select at least one task or mark this as a project.' ),
						'addressRequired'=> (string) $this->settings->get( 'ui_error_address_required', 'Add the address of the job before continuing.' ),
						'invalidCode'    => (string) $this->settings->get( 'ui_error_invalid_code', 'Code or magic link is invalid or expired.' ),
						'assistantRequired' => (string) $this->settings->get( 'ui_error_assistant_required', 'Please send the virtual assistant a short description of the job before continuing.' ),
						'nameEmailRequired' => (string) $this->settings->get( 'ui_error_name_email_required', 'Name and email are required before you can continue.' ),
						'phoneOrEmailRequired' => (string) $this->settings->get( 'ui_error_phone_or_email_required', 'Enter your email or phone, then request a code.' ),
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
