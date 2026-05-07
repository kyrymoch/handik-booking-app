<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public router + asset loader for the Additional Booking Forms module.
 *
 *   1. Shortcode  [handik_booking_form preset="standard-visit-60"]
 *      Drop-in to any WP page/post.
 *
 *   2. Rewrite route  /booking/{preset_slug}
 *      Auto-generated public URLs that work without creating a WP page each
 *      time. Renders inside the active theme's `the_content` so site chrome
 *      (header/footer/SEO) is preserved.
 *
 * Visual contract: the module reuses the main app's full stylesheet
 * (`booking-app.css`) so colors, fonts, fields, sticky footer, toasts and the
 * Cal.com embed match the [handik_booking_app] flow pixel-for-pixel. The
 * appearance tokens (--handik-* CSS variables) and Google Maps API key are
 * passed in the same JSON shape as the main app so booking-forms.js can apply
 * them without an extra round-trip.
 */
class Handik_Booking_App_Forms_Router {
	const QUERY_VAR  = 'handik_booking_preset';
	const ROUTE_BASE = 'booking';

	/** @var Handik_Booking_App_Booking_Presets_Service */
	protected $presets;

	/** @var Handik_Booking_App_Project_Schedule_Service|null */
	protected $project;

	/** @var Handik_Booking_App_Settings */
	protected $settings;

	/** @var Handik_Booking_App_Appearance_Service|null */
	protected $appearance;

	/**
	 * @param Handik_Booking_App_Booking_Presets_Service       $presets    Presets service.
	 * @param Handik_Booking_App_Project_Schedule_Service|null $project    Project schedule service.
	 * @param Handik_Booking_App_Settings                      $settings   Settings.
	 * @param Handik_Booking_App_Appearance_Service|null       $appearance Appearance service.
	 */
	public function __construct( $presets, $project, $settings, $appearance = null ) {
		$this->presets    = $presets;
		$this->project    = $project;
		$this->settings   = $settings;
		$this->appearance = $appearance;

		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		add_shortcode( 'handik_booking_form', array( $this, 'shortcode' ) );

		register_activation_hook( HANDIK_BOOKING_APP_FILE, array( __CLASS__, 'flush_rewrite_on_activation' ) );
	}

	public static function flush_rewrite_on_activation() {
		flush_rewrite_rules( false );
	}

	public function register_rewrite() {
		add_rewrite_rule(
			'^' . preg_quote( self::ROUTE_BASE, '/' ) . '/([a-z0-9-]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'handik_schedule_token';
		return $vars;
	}

	/**
	 * Register assets. Note that `handik-booking-forms` style depends on
	 * `handik-booking-app` (the main app stylesheet) so all shared classes
	 * inherit identical theming.
	 */
	public function register_assets() {
		// Main app stylesheet — registered by class-assets.php; declare it as a
		// dependency so the additional forms inherit the design system.
		wp_register_style(
			'handik-booking-forms',
			HANDIK_BOOKING_APP_URL . 'assets/booking-forms.css',
			array( 'handik-booking-app' ),
			HANDIK_BOOKING_APP_VERSION
		);
		wp_register_script(
			'handik-booking-forms',
			HANDIK_BOOKING_APP_URL . 'assets/booking-forms.js',
			array(),
			HANDIK_BOOKING_APP_VERSION,
			true
		);
	}

	/**
	 * Shortcode entry point.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'preset' => '',
			),
			$atts,
			'handik_booking_form'
		);
		$slug = sanitize_title( (string) $atts['preset'] );
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
		}
		return $this->render_form( $slug );
	}

	/**
	 * /booking/{slug} → inject content via the_content filter so the active
	 * theme provides chrome.
	 */
	public function maybe_render_route() {
		$slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
		if ( '' === $slug ) {
			return;
		}

		$preset = $this->presets->find_by_slug( $slug );
		// Disabled or missing presets MUST NOT advertise the form title in
		// <title> — that was leaking offerings the admin had explicitly
		// turned off. Fall back to a generic "Book a visit" label.
		$is_public = $preset && ! empty( $preset['enabled'] );

		add_filter(
			'pre_get_document_title',
			function () use ( $preset, $is_public ) {
				$title = $is_public
					? (string) $preset['form_title']
					: __( 'Book a visit', 'handik-booking-app' );
				return $title . ' — ' . get_bloginfo( 'name' );
			}
		);
		add_filter(
			'the_content',
			function ( $content ) use ( $slug ) {
				return $this->render_form( $slug );
			}
		);
		add_filter(
			'wp_robots',
			static function ( $robots ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
				return $robots;
			}
		);
	}

	// ---------- render core ----------------------------------------------

	/**
	 * Build the preset card markup the SPA hydrates.
	 *
	 * @param string $slug Preset slug.
	 * @return string
	 */
	protected function render_form( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return $this->error_markup( __( 'This booking form is not available right now.', 'handik-booking-app' ) );
		}
		$preset = $this->presets->find_by_slug( $slug );
		if ( ! $preset || empty( $preset['enabled'] ) ) {
			return $this->error_markup( __( 'This booking form is not available right now.', 'handik-booking-app' ) );
		}

		// Make sure the main app stylesheet is registered before we depend on it.
		// In some contexts (admin previews, REST template routing) the public
		// asset hook hasn't fired yet.
		if ( ! wp_style_is( 'handik-booking-app', 'registered' ) ) {
			wp_register_style(
				'handik-booking-app',
				HANDIK_BOOKING_APP_URL . 'assets/booking-app.css',
				array(),
				HANDIK_BOOKING_APP_VERSION
			);
		}
		wp_enqueue_style( 'handik-booking-app' );
		wp_enqueue_style( 'handik-booking-forms' );
		wp_enqueue_script( 'handik-booking-forms' );

		$config = array(
			'restBase'  => esc_url_raw( trailingslashit( rest_url( 'handik-booking-app/v1' ) ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'version'   => HANDIK_BOOKING_APP_VERSION,
			'preset'    => array(
				'preset_slug'                => (string) $preset['preset_slug'],
				'form_title'                 => (string) $preset['form_title'],
				'form_type'                  => (string) $preset['form_type'],
				'booking_type'               => (string) $preset['booking_type'],
				'duration_minutes'           => (int) $preset['duration_minutes'],
				'required_days'              => (int) $preset['required_days'],
				'work_day_duration_minutes'  => (int) $preset['work_day_duration_minutes'],
			),
			'sourceUrl'         => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) ) : '',
			'timezone'          => (string) $this->settings->get( 'cal_api_timezone', 'America/New_York' ),
			'appearance'        => $this->appearance ? $this->appearance->css_variables() : array(),
			'googleMapsApiKey'  => (string) $this->settings->get( 'google_maps_api_key', '' ),
			'googleMapsCountry' => strtolower( (string) $this->settings->get( 'google_maps_country', 'us' ) ),
			'i18n'              => $this->i18n_strings(),
		);

		ob_start();
		?>
		<div class="handik-booking-form handik-booking-app" data-handik-booking-form>
			<script type="application/json" data-handik-booking-form-config><?php echo wp_json_encode( $config ); ?></script>
			<div class="handik-booking-form__shell" data-handik-booking-form-shell></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, string>
	 */
	protected function i18n_strings() {
		return array(
			// Step titles (h2 in each screen header) — match main app naming.
			'contactTitle'      => __( 'Contact details', 'handik-booking-app' ),
			'addressTitle'      => __( 'Address details', 'handik-booking-app' ),
			'calTitle'          => __( 'Pick a time', 'handik-booking-app' ),
			'pickDaysTitle'     => __( 'Choose project work days', 'handik-booking-app' ),
			'reviewTitle'       => __( 'Review your selected days', 'handik-booking-app' ),
			'successHeading'    => __( 'You\'re all set', 'handik-booking-app' ),

			// Buttons.
			'continueLabel'     => __( 'Continue', 'handik-booking-app' ),
			'backLabel'         => __( 'Back', 'handik-booking-app' ),

			// Field labels.
			'fullNameLabel'     => __( 'Full name', 'handik-booking-app' ),
			'phoneLabel'        => __( 'Phone', 'handik-booking-app' ),
			'emailLabel'        => __( 'Email (optional)', 'handik-booking-app' ),
			'addressLabel'      => __( 'Address of the job', 'handik-booking-app' ),
			'addressPlaceholder' => __( 'Start typing the address of the job', 'handik-booking-app' ),
			'unitLabel'         => __( 'Unit or apartment (optional)', 'handik-booking-app' ),
			'savedAddressLabel' => __( 'Choose a saved address or enter a new one', 'handik-booking-app' ),
			'savedAddressPlaceholder' => __( 'Choose saved address', 'handik-booking-app' ),
			'savedAddressEmpty' => __( 'No saved addresses yet — enter the address below.', 'handik-booking-app' ),

			// Step intros.
			'contactIntro'      => __( "Tell us how to reach you. If you've booked here before, we'll recognize you.", 'handik-booking-app' ),
			'calIntro'          => __( 'Pick a time that works for you.', 'handik-booking-app' ),
			'reviewIntro'       => __( 'Quick review before we confirm with Cal.com.', 'handik-booking-app' ),

			// Validation errors.
			'errorRequired'     => __( 'Please fill in this field.', 'handik-booking-app' ),
			'errorPhone'        => __( 'Please enter a valid phone number.', 'handik-booking-app' ),
			'errorEmail'        => __( 'Please enter a valid email or leave it blank.', 'handik-booking-app' ),
			'errorAddressInvalid' => __( 'Choose a valid address from the suggestions to continue.', 'handik-booking-app' ),
			'genericError'      => __( 'Something went wrong. Please try again.', 'handik-booking-app' ),

			// Loaders / Cal embed.
			'loading'           => __( 'Loading available days…', 'handik-booking-app' ),
			'calNotReady'       => __( 'Booking calendar is not ready yet.', 'handik-booking-app' ),
			'openInNewTab'      => __( 'Open the booking page in a new tab', 'handik-booking-app' ),

			// Project flow.
			'reviewSelectedDaysHeading' => __( 'Selected work days', 'handik-booking-app' ),
			'confirmCta'        => __( 'Confirm selected days', 'handik-booking-app' ),
			'selectionCounter'  => __( 'Selected %1$d of %2$d days', 'handik-booking-app' ),
			'pickHelper'        => __( 'Please select %d work days.', 'handik-booking-app' ),
			'noSlots'           => __( 'No work days are available in the next 30 days. Please contact Alex directly.', 'handik-booking-app' ),
			'replacementNeeded' => __( 'One or more selected days are no longer available. Please pick replacements.', 'handik-booking-app' ),

			// Success copy.
			'successTitle'      => __( 'You\'re all set!', 'handik-booking-app' ),
			'projectSuccess'    => __( 'Your project work days have been selected. Alex will follow up if anything needs to be adjusted.', 'handik-booking-app' ),
			'directSuccess'     => __( 'Your visit is booked. Alex will be in touch before the visit.', 'handik-booking-app' ),

			// Returning client.
			'welcomeBack'       => __( 'Welcome back — we found your saved addresses.', 'handik-booking-app' ),
			'savedAddressChecking' => __( 'Checking saved addresses…', 'handik-booking-app' ),

			// Success disclaimer + notifications landmark.
			'allSet'            => __( 'All set. Alex will be in touch before the visit.', 'handik-booking-app' ),
			'bookAnother'       => __( 'Book another visit', 'handik-booking-app' ),
			'notificationsRegionLabel' => __( 'Notifications', 'handik-booking-app' ),

			// Footer disclaimer + progress.
			'stuckPrefix'       => __( 'Stuck?', 'handik-booking-app' ),
			'restartCta'        => __( 'Start a new booking', 'handik-booking-app' ),
			'openDirectCta'     => __( 'Open the booking page directly', 'handik-booking-app' ),
			'progressLabel'     => __( 'Booking progress', 'handik-booking-app' ),

			// Restart confirmation modal.
			'restartConfirmTitle'  => __( 'Start over?', 'handik-booking-app' ),
			'restartConfirmBody'   => __( 'This will clear what you\'ve typed so far and take you back to the start. Continue?', 'handik-booking-app' ),
			'restartConfirmCancel' => __( 'Keep my booking', 'handik-booking-app' ),
			'restartConfirmCta'    => __( 'Start over', 'handik-booking-app' ),
		);
	}

	protected function error_markup( $message ) {
		return '<div class="handik-booking-form handik-booking-app handik-booking-form--error"><div class="handik-booking-app__alert is-error">' . esc_html( (string) $message ) . '</div></div>';
	}
}
