<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System info / debug page (D5).
 *
 * Plugin metadata + total counts + raw tables view + tools (clear transients,
 * re-run migrations).
 */
class Handik_Booking_App_Admin_System {

	/** @var Handik_Booking_App_Settings */
	protected $settings;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Messages_Service|null */
	protected $messages;

	public function __construct( $settings, $job_requests, $bookings, $contacts, $addresses, $messages ) {
		$this->settings     = $settings;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->messages     = $messages;
	}

	public function render() {
		$tab = Handik_Booking_App_Admin_Helpers::current_tab( array( 'overview', 'raw', 'retention' ), 'overview' );

		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'System info', 'handik-booking-app' ),
			__( 'Plugin metadata, counts and debugging tools.', 'handik-booking-app' )
		);

		settings_errors( 'handik-booking-app' );

		echo Handik_Booking_App_Admin_Helpers::tabs_markup(
			array(
				'overview'  => __( 'Overview', 'handik-booking-app' ),
				'raw'       => __( 'Raw tables (debug)', 'handik-booking-app' ),
				'retention' => __( 'Log retention', 'handik-booking-app' ),
			),
			$tab,
			'handik-booking-app-system'
		);

		switch ( $tab ) {
			case 'raw':
				$this->render_raw_tables();
				break;
			case 'retention':
				$this->render_retention();
				break;
			default:
				$this->render_overview();
		}

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function render_overview() {
		global $wpdb;

		$counts = array(
			__( 'Job requests', 'handik-booking-app' ) => $this->job_requests ? $this->job_requests->count_all() : 0,
			__( 'Bookings', 'handik-booking-app' )     => $this->bookings ? $this->bookings->count_all() : 0,
			__( 'Contacts', 'handik-booking-app' )     => $this->contacts ? $this->contacts->count_all() : 0,
			__( 'Addresses', 'handik-booking-app' )    => $this->addresses ? $this->addresses->count_all() : 0,
			__( 'Messages', 'handik-booking-app' )     => $this->messages_count(),
		);

		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Plugin', 'handik-booking-app' ); ?></h2>
			<?php
			$cron = wp_next_scheduled( 'handik_booking_app_dummy_cron' ) ? wp_date( 'Y-m-d H:i:s', wp_next_scheduled( 'handik_booking_app_dummy_cron' ) ) : __( 'No plugin cron jobs scheduled', 'handik-booking-app' );
			// Sprint 8: surface the cron-fallback status so admins can
			// verify the wp_loaded heartbeat is doing its job on
			// DISABLE_WP_CRON installs.
			$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
			$fallback_msg  = $cron_disabled
				? __( 'WP cron disabled — wp_loaded heartbeat fires overdue handik_* events.', 'handik-booking-app' )
				: __( 'WP cron enabled — fallback heartbeat is dormant.', 'handik-booking-app' );

			// Sprint 10 fix: surface migration-attempt + last-error so a
			// failed Run-pending-migrations leaves a visible audit trail.
			// Was P0 — the runner wrote LAST_ERROR_OPTION on failure but
			// the page only showed LAST_RUN_OPTION, so a failed run
			// looked identical to a successful one.
			$db_last_attempt = (string) get_option( Handik_Booking_App_Migrations::LAST_ATTEMPT_OPTION, '' );
			$db_last_error   = (string) get_option( Handik_Booking_App_Migrations::LAST_ERROR_OPTION, '' );

			$details = array(
				__( 'Plugin version', 'handik-booking-app' )      => HANDIK_BOOKING_APP_VERSION,
				__( 'DB schema version', 'handik-booking-app' )   => (string) get_option( Handik_Booking_App_Migrations::OPTION_NAME, '0.0.0' ),
				__( 'Last migration ran', 'handik-booking-app' )  => (string) get_option( Handik_Booking_App_Migrations::LAST_RUN_OPTION, __( 'Never (or before 2.1.8.2)', 'handik-booking-app' ) ),
				__( 'Last attempt', 'handik-booking-app' )        => '' !== $db_last_attempt ? $db_last_attempt : __( '(never invoked since 2.1.14.0)', 'handik-booking-app' ),
				__( 'Required WP', 'handik-booking-app' )         => '6.4',
				__( 'Required PHP', 'handik-booking-app' )        => '7.4',
				__( 'Current PHP', 'handik-booking-app' )         => PHP_VERSION,
				__( 'Current MySQL', 'handik-booking-app' )       => (string) $wpdb->db_version(),
				__( 'WP version', 'handik-booking-app' )          => get_bloginfo( 'version' ),
				__( 'Cron status', 'handik-booking-app' )         => $cron,
				__( 'Cron fallback', 'handik-booking-app' )       => $fallback_msg,
				__( 'Site URL', 'handik-booking-app' )            => home_url(),
			);
			echo Handik_Booking_App_Admin_Helpers::detail_list_markup( $details );

			if ( '' !== $db_last_error ) {
				echo '<div class="handik-admin-callout handik-admin-callout--error" role="alert">';
				echo '<strong>' . esc_html__( 'Last migration error', 'handik-booking-app' ) . ':</strong> ';
				echo esc_html( $db_last_error );
				echo '</div>';
			}
			?>
		</section>

		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Total records', 'handik-booking-app' ); ?></h2>
			<div class="handik-admin-month-stats">
				<?php foreach ( $counts as $label => $value ) : ?>
					<div class="handik-admin-month-stat"><strong><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Tools', 'handik-booking-app' ); ?></h2>
			<div data-handik-system-tools
				data-rest-base="<?php echo esc_attr( esc_url_raw( trailingslashit( rest_url( 'handik-booking-app/v1' ) ) ) ); ?>"
				data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				<p>
					<button type="button" class="button" data-handik-action="run-migrations">↻ <?php esc_html_e( 'Run pending migrations', 'handik-booking-app' ); ?></button>
					<button type="button" class="button" data-handik-action="clear-transients">🧹 <?php esc_html_e( 'Clear plugin transients', 'handik-booking-app' ); ?></button>
				</p>
			</div>
		</section>

		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Export tables to CSV', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-muted"><?php esc_html_e( 'Each link downloads the entire table as CSV. Use this when sharing data with support or backing up before a risky change.', 'handik-booking-app' ); ?></p>
			<p class="handik-admin-export-links">
				<?php
				$rest = trailingslashit( rest_url( 'handik-booking-app/v1' ) );
				$nonce = wp_create_nonce( 'wp_rest' );
				$tables = array(
					'job_requests' => __( 'Job requests', 'handik-booking-app' ),
					'bookings'     => __( 'Bookings', 'handik-booking-app' ),
					'contacts'     => __( 'Contacts', 'handik-booking-app' ),
					'addresses'    => __( 'Addresses', 'handik-booking-app' ),
					'messages'     => __( 'Messages', 'handik-booking-app' ),
					// Additional Forms tables (added in 2.1.9.1 / 2.1.10.0).
					'form_presets'                  => __( 'Form presets', 'handik-booking-app' ),
					'direct_booking_requests'       => __( 'Direct booking requests', 'handik-booking-app' ),
					'project_scheduling_requests'   => __( 'Project schedules', 'handik-booking-app' ),
					'project_work_days'             => __( 'Project work days', 'handik-booking-app' ),
				);
				foreach ( $tables as $key => $label ) :
					$url = add_query_arg( '_wpnonce', $nonce, $rest . 'admin/export/' . $key );
				?>
					<a class="button" href="<?php echo esc_url( $url ); ?>">⬇ <?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>
		</section>
		<?php
	}

	protected function messages_count() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'messages' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	protected function render_raw_tables() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$which = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : 'requests';

		$tables = array(
			'requests'                    => __( 'Job requests', 'handik-booking-app' ),
			'contacts'                    => __( 'Contacts', 'handik-booking-app' ),
			'addresses'                   => __( 'Addresses', 'handik-booking-app' ),
			'form_presets'                => __( 'Form presets', 'handik-booking-app' ),
			'direct_booking_requests'     => __( 'Direct requests', 'handik-booking-app' ),
			'project_scheduling_requests' => __( 'Project schedules', 'handik-booking-app' ),
			'project_work_days'           => __( 'Project work days', 'handik-booking-app' ),
		);
		echo '<nav class="handik-admin-segmented">';
		foreach ( $tables as $key => $label ) {
			$url = Handik_Booking_App_Admin_Helpers::admin_url_for(
				'handik-booking-app-system',
				array( 'tab' => 'raw', 'table' => $key )
			);
			$cls = 'handik-admin-segment' . ( $which === $key ? ' is-active' : '' );
			echo '<a class="' . $cls . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $which ) {
			case 'contacts':
				$this->render_raw_table(
					$this->contacts ? $this->contacts->list_recent( 100 ) : array(),
					array( 'id', 'full_name', 'email', 'phone', 'is_returning', 'is_spam', 'updated_at' )
				);
				break;
			case 'addresses':
				$this->render_raw_table(
					$this->addresses ? $this->addresses->list_recent( 100 ) : array(),
					array( 'id', 'contact_id', 'address_full', 'city', 'state', 'zip_code', 'is_primary', 'is_default', 'deleted_at', 'updated_at' )
				);
				break;
			case 'form_presets':
				$this->render_raw_table_for(
					'form_presets',
					array( 'id', 'preset_slug', 'form_type', 'booking_type', 'duration_minutes', 'required_days', 'work_day_duration_minutes', 'enabled', 'is_default' )
				);
				break;
			case 'direct_booking_requests':
				$this->render_raw_table_for(
					'direct_booking_requests',
					array( 'id', 'contact_id', 'address_id', 'preset_slug', 'duration_minutes', 'status', 'cal_booking_uid', 'created_at', 'updated_at' )
				);
				break;
			case 'project_scheduling_requests':
				$this->render_raw_table_for(
					'project_scheduling_requests',
					array( 'id', 'contact_id', 'address_id', 'preset_slug', 'required_days', 'status', 'confirmed_at', 'created_at', 'updated_at' )
				);
				break;
			case 'project_work_days':
				$this->render_raw_table_for(
					'project_work_days',
					array( 'id', 'scheduling_request_id', 'day_index', 'start_iso', 'end_iso', 'status', 'cal_booking_uid', 'updated_at' )
				);
				break;
			default:
				$this->render_raw_table(
					$this->job_requests ? $this->job_requests->list_recent( 100 ) : array(),
					array( 'id', 'contact_id', 'client_type', 'job_shape', 'booking_type', 'status', 'app_step', 'updated_at' )
				);
		}
	}

	/**
	 * Generic raw-table dump used by the Additional Forms tables (which
	 * don't have a dedicated service::list_recent helper). Reads the last
	 * 100 rows ordered by id desc.
	 *
	 * @param string $short_name Short table name (without prefix).
	 * @param array<int, string> $cols Column whitelist.
	 */
	protected function render_raw_table_for( $short_name, array $cols ) {
		global $wpdb;
		$table  = Handik_Booking_App_DB::table( sanitize_key( $short_name ) );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			echo '<p class="handik-admin-muted">' . esc_html__( 'Table not yet created — run pending migrations.', 'handik-booking-app' ) . '</p>';
			return;
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );
		$this->render_raw_table( is_array( $rows ) ? $rows : array(), $cols );
	}

	protected function render_raw_table( array $rows, array $cols ) {
		echo '<div class="handik-admin-table-wrap"><table class="widefat striped"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $cols as $c ) {
				$value = isset( $row[ $c ] ) ? $row[ $c ] : '';
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				echo '<td>' . esc_html( (string) $value ) . '</td>';
			}
			echo '</tr>';
		}
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="' . esc_attr( (string) count( $cols ) ) . '">' . esc_html__( 'No records.', 'handik-booking-app' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	protected function render_retention() {
		$s = $this->settings ? $this->settings->all() : array();
		?>
		<form method="post">
			<?php wp_nonce_field( 'handik_booking_app_save_settings', 'handik_booking_app_settings_nonce' ); ?>
			<section class="handik-admin-block">
				<h2 class="handik-admin-section-title"><?php esc_html_e( 'Log retention', 'handik-booking-app' ); ?></h2>
				<p class="handik-admin-muted"><?php esc_html_e( 'Per-level caps. Higher numbers keep more history but use more wp_options space.', 'handik-booking-app' ); ?></p>
				<div class="handik-admin-grid">
					<?php Handik_Booking_App_Admin_Helpers::field( 'log_max_entries_info', __( 'Max entries (info+ levels)', 'handik-booking-app' ), $s['log_max_entries_info'] ?? '2000', 'number' ); ?>
					<?php Handik_Booking_App_Admin_Helpers::field( 'log_max_entries_debug', __( 'Max entries (debug)', 'handik-booking-app' ), $s['log_max_entries_debug'] ?? '500', 'number' ); ?>
				</div>
			</section>
			<?php submit_button( __( 'Save retention', 'handik-booking-app' ) ); ?>
		</form>
		<?php
	}
}
