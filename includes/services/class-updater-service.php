<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Updater_Service {
	const CHECK_PERIOD_HOURS = 24;
	const INIT_LOG_OPTION    = 'handik_booking_app_updater_init_log';

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var object|null
	 */
	protected $checker = null;

	/**
	 * @param Handik_Booking_App_Settings $settings Settings.
	 * @param Handik_Booking_App_Logger   $logger Logger.
	 */
	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;

		$this->boot();
		add_action( 'puc_api_error', array( $this, 'handle_api_error' ), 10, 4 );
	}

	protected function boot() {
		$library_file = HANDIK_BOOKING_APP_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $library_file ) ) {
			$this->logger->error( 'Plugin updater library is missing.', array( 'path' => $library_file ) );
			return;
		}

		require_once $library_file;

		$factory_class = class_exists( '\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory' )
			? '\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory'
			: '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';

		if ( ! class_exists( $factory_class ) ) {
			$this->logger->error( 'Plugin updater factory class could not be loaded.' );
			return;
		}

		$repo_url      = trailingslashit( (string) $this->settings->get( 'github_repo_url', 'https://github.com/kyrymoch/handik-booking-app/' ) );
		$branch        = trim( (string) $this->settings->get( 'github_repo_branch', 'main' ) );
		$token         = trim( (string) $this->settings->get( 'github_access_token', '' ) );
		$asset_pattern = trim( (string) $this->settings->get( 'github_release_asset_pattern', '/handik-booking-app\.zip($|[?&#])/i' ) );

		$this->checker = $factory_class::buildUpdateChecker(
			$repo_url,
			HANDIK_BOOKING_APP_FILE,
			'handik-booking-app',
			self::CHECK_PERIOD_HOURS
		);

		if ( ! empty( $branch ) ) {
			$this->checker->setBranch( $branch );
		}

		if ( ! empty( $token ) ) {
			$this->checker->setAuthentication( $token );
		}

		if ( method_exists( $this->checker, 'getVcsApi' ) ) {
			$api = $this->checker->getVcsApi();
			if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
				$api->enableReleaseAssets( ! empty( $asset_pattern ) ? $asset_pattern : '/handik-booking-app\.zip($|[?&#])/i' );
			}
		}

		$this->maybe_log_initialization(
			array(
				'repo_url'          => $repo_url,
				'branch'            => $branch,
				'uses_token'        => ! empty( $token ),
				'asset_pattern'     => $asset_pattern,
				'plugin_version'    => HANDIK_BOOKING_APP_VERSION,
				'auto_update_ui'    => true,
				'check_period_hours'=> self::CHECK_PERIOD_HOURS,
				'manual_check_link' => true,
			)
		);
	}

	/**
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	protected function maybe_log_initialization( array $context ) {
		$signature = md5( wp_json_encode( $context ) );
		$stored    = get_option( self::INIT_LOG_OPTION, array() );
		$last_time = ! empty( $stored['time'] ) ? strtotime( (string) $stored['time'] ) : 0;
		$last_sig  = ! empty( $stored['signature'] ) ? (string) $stored['signature'] : '';

		$should_log = ( $signature !== $last_sig ) || ( ! $last_time ) || ( time() - $last_time >= DAY_IN_SECONDS );
		if ( ! $should_log ) {
			return;
		}

		$this->logger->info( 'GitHub updater initialized.', $context );
		update_option(
			self::INIT_LOG_OPTION,
			array(
				'signature' => $signature,
				'time'      => current_time( 'mysql' ),
			),
			false
		);
	}

	/**
	 * @param WP_Error|mixed $error Error.
	 * @param mixed          $result Result.
	 * @param mixed          $url URL.
	 * @param string         $slug Slug.
	 */
	public function handle_api_error( $error, $result = null, $url = null, $slug = '' ) {
		if ( 'handik-booking-app' !== (string) $slug || ! is_wp_error( $error ) ) {
			return;
		}

		$this->logger->error(
			'GitHub updater API error.',
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'url'     => $url,
				'result'  => $result,
			)
		);
	}
}
