<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs page (E1) with level filter, time filter, request_id, thread_id, search,
 * card-list rendering, and CSV export. request_id/thread_id render as links to
 * the booking/request detail (E2).
 */
class Handik_Booking_App_Admin_Logs {

	/** @var Handik_Booking_App_Logger */
	protected $logger;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;

	public function __construct( $logger, $job_requests, $bookings ) {
		$this->logger       = $logger;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;

		// CSV export hook — runs before any HTML.
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
	}

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter_level = isset( $_GET['filter_level'] ) ? sanitize_key( wp_unslash( $_GET['filter_level'] ) ) : 'all';
		$filter_time  = isset( $_GET['filter_time'] ) ? sanitize_key( wp_unslash( $_GET['filter_time'] ) ) : 'all';
		$query        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$request_id   = isset( $_GET['request_id'] ) ? absint( wp_unslash( $_GET['request_id'] ) ) : 0;
		$thread_id    = isset( $_GET['thread_id'] ) ? sanitize_text_field( wp_unslash( $_GET['thread_id'] ) ) : '';
		$show_debug   = ! empty( $_GET['show_debug'] );
		// phpcs:enable

		Handik_Booking_App_Admin_Helpers::page_start( __( 'Logs', 'handik-booking-app' ) );

		echo $this->filter_bar_markup( $filter_level, $filter_time, $query, $request_id, $thread_id, $show_debug );

		$logs = $this->collect_logs( $filter_level, $filter_time, $query, $request_id, $thread_id, $show_debug );

		// Newest first.
		$logs = array_reverse( $logs );

		echo '<p class="handik-admin-muted">' . esc_html( sprintf( _n( '%d entry', '%d entries', count( $logs ), 'handik-booking-app' ), count( $logs ) ) ) . '</p>';

		if ( empty( $logs ) ) {
			echo '<div class="handik-admin-empty"><p>' . esc_html__( 'No log entries match these filters.', 'handik-booking-app' ) . '</p></div>';
		} else {
			echo '<ul class="handik-admin-log-cards">';
			foreach ( $logs as $entry ) {
				echo $this->log_card_markup( $entry );
			}
			echo '</ul>';
		}

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function filter_bar_markup( $filter_level, $filter_time, $query, $request_id, $thread_id, $show_debug ) {
		$page = 'handik-booking-app-operations';
		$levels = array(
			'all'      => __( 'All', 'handik-booking-app' ),
			'critical' => __( 'Critical', 'handik-booking-app' ),
			'error'    => __( 'Error', 'handik-booking-app' ),
			'warning'  => __( 'Warning', 'handik-booking-app' ),
			'notice'   => __( 'Notice', 'handik-booking-app' ),
			'info'     => __( 'Info', 'handik-booking-app' ),
		);
		$times = array(
			'all'  => __( 'All time', 'handik-booking-app' ),
			'24h'  => __( 'Last 24h', 'handik-booking-app' ),
			'7d'   => __( 'Last 7 days', 'handik-booking-app' ),
			'30d'  => __( 'Last 30 days', 'handik-booking-app' ),
		);

		$csv_url = add_query_arg(
			array(
				'page'         => $page,
				'tab'          => 'logs',
				'filter_level' => $filter_level,
				'filter_time'  => $filter_time,
				'q'            => $query,
				'request_id'   => $request_id ? $request_id : '',
				'thread_id'    => $thread_id,
				'show_debug'   => $show_debug ? 1 : 0,
				'export'       => 'csv',
				'_wpnonce'     => wp_create_nonce( 'handik_logs_csv' ),
			),
			admin_url( 'admin.php' )
		);

		ob_start();
		?>
		<form class="handik-admin-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<input type="hidden" name="tab" value="logs" />
			<div class="handik-admin-filter-row">
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Level', 'handik-booking-app' ); ?></span>
					<select name="filter_level">
						<?php foreach ( $levels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filter_level, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Time', 'handik-booking-app' ); ?></span>
					<select name="filter_time">
						<?php foreach ( $times as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filter_time, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Request ID', 'handik-booking-app' ); ?></span>
					<input type="number" name="request_id" value="<?php echo esc_attr( $request_id ? (string) $request_id : '' ); ?>" />
				</label>
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Thread ID', 'handik-booking-app' ); ?></span>
					<input type="text" name="thread_id" value="<?php echo esc_attr( $thread_id ); ?>" />
				</label>
				<label class="handik-admin-filter handik-admin-filter--search">
					<span><?php esc_html_e( 'Search', 'handik-booking-app' ); ?></span>
					<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" />
				</label>
				<label class="handik-admin-checkbox">
					<input type="checkbox" name="show_debug" value="1"<?php checked( $show_debug ); ?> />
					<span><?php esc_html_e( 'Show debug', 'handik-booking-app' ); ?></span>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'handik-booking-app' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv_url ); ?>">⬇ <?php esc_html_e( 'CSV', 'handik-booking-app' ); ?></a>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	protected function collect_logs( $filter_level, $filter_time, $query, $request_id, $thread_id, $show_debug ) {
		if ( ! $this->logger ) {
			return array();
		}

		$args = array(
			'query'         => $query,
			'request_id'    => $request_id,
			'thread_id'     => $thread_id,
			'include_debug' => $show_debug,
		);
		if ( 'all' !== $filter_level ) {
			$args['level'] = array( $filter_level );
		}
		switch ( $filter_time ) {
			case '24h':
				$args['since_ts'] = time() - DAY_IN_SECONDS;
				break;
			case '7d':
				$args['since_ts'] = time() - 7 * DAY_IN_SECONDS;
				break;
			case '30d':
				$args['since_ts'] = time() - 30 * DAY_IN_SECONDS;
				break;
		}
		return $this->logger->query( $args );
	}

	protected function log_card_markup( array $entry ) {
		$level = (string) ( $entry['level'] ?? 'info' );
		$message = (string) ( $entry['message'] ?? '' );
		// Sprint 8: route through the unified helper. Logger writes
		// `current_time('mysql')` which returns site-local time, not UTC,
		// so pass `$assume_utc = false` to avoid a wrong shift.
		$time = Handik_Booking_App_Admin_Helpers::format_short( (string) ( $entry['time'] ?? '' ), false );
		$context = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();

		$pieces = array();
		if ( ! empty( $context['request_id'] ) ) {
			$rid = (int) $context['request_id'];
			$url = $this->resolve_request_url( $rid );
			$pieces[] = '<a href="' . esc_url( $url ) . '">request_id: ' . esc_html( (string) $rid ) . '</a>';
		}
		if ( ! empty( $context['thread_id'] ) ) {
			$tid = (string) $context['thread_id'];
			$url = $this->resolve_thread_url( $tid );
			$pieces[] = $url ? '<a href="' . esc_url( $url ) . '">thread_id: ' . esc_html( $tid ) . '</a>' : 'thread_id: ' . esc_html( $tid );
		}
		$meta = $pieces ? implode( ' · ', $pieces ) : '';

		ob_start();
		?>
		<li class="handik-admin-log-card handik-admin-log-card--<?php echo esc_attr( sanitize_html_class( $level ) ); ?>">
			<div class="handik-admin-log-card__head">
				<?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $level, ucfirst( $level ) ); ?>
				<time><?php echo esc_html( $time ); ?></time>
			</div>
			<p class="handik-admin-log-card__message"><?php echo esc_html( $message ); ?></p>
			<?php if ( $meta ) : ?>
				<p class="handik-admin-log-card__meta"><?php echo $meta; // already escaped piecemeal ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $context ) ) : ?>
				<details class="handik-admin-log-card__details">
					<summary><?php esc_html_e( 'Show details', 'handik-booking-app' ); ?></summary>
					<pre><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				</details>
			<?php endif; ?>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	protected function resolve_request_url( $request_id ) {
		// Prefer booking detail if a booking exists for this request.
		if ( $this->bookings && $request_id ) {
			$booking = $this->bookings->find_latest_for_request( $request_id );
			if ( $booking ) {
				return Handik_Booking_App_Admin_Helpers::admin_url_for(
					'handik-booking-app-bookings',
					array( 'booking_id' => (int) $booking['id'] )
				);
			}
		}
		return Handik_Booking_App_Admin_Helpers::admin_url_for(
			'handik-booking-app-crm',
			array( 'request_id' => (int) $request_id )
		);
	}

	protected function resolve_thread_url( $thread_id ) {
		if ( ! $this->job_requests || ! $thread_id ) {
			return '';
		}
		// Look up which request owns this thread; fall back to logs filter.
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'job_requests' );
		$request_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE chat_thread_id = %s LIMIT 1", $thread_id )
		);
		if ( $request_id ) {
			return $this->resolve_request_url( $request_id );
		}
		return Handik_Booking_App_Admin_Helpers::admin_url_for(
			'handik-booking-app-operations',
			array( 'tab' => 'logs', 'thread_id' => $thread_id )
		);
	}

	// ---------- CSV export ------------------------------------------------

	public function maybe_export_csv() {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'handik-booking-app-operations' !== $_GET['page'] ) {
			return;
		}
		if ( empty( $_GET['export'] ) || 'csv' !== $_GET['export'] ) {
			return;
		}
		if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'handik_logs_csv' ) ) {
			return;
		}

		$filter_level = isset( $_GET['filter_level'] ) ? sanitize_key( wp_unslash( $_GET['filter_level'] ) ) : 'all';
		$filter_time  = isset( $_GET['filter_time'] ) ? sanitize_key( wp_unslash( $_GET['filter_time'] ) ) : 'all';
		$query        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$request_id   = isset( $_GET['request_id'] ) ? absint( wp_unslash( $_GET['request_id'] ) ) : 0;
		$thread_id    = isset( $_GET['thread_id'] ) ? sanitize_text_field( wp_unslash( $_GET['thread_id'] ) ) : '';
		$show_debug   = ! empty( $_GET['show_debug'] );
		// phpcs:enable

		$logs = $this->collect_logs( $filter_level, $filter_time, $query, $request_id, $thread_id, $show_debug );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="handik-logs-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'time', 'level', 'message', 'request_id', 'thread_id', 'context' ) );
		foreach ( $logs as $entry ) {
			$ctx = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();
			fputcsv( $out, array(
				(string) ( $entry['time'] ?? '' ),
				(string) ( $entry['level'] ?? '' ),
				(string) ( $entry['message'] ?? '' ),
				(string) ( $ctx['request_id'] ?? '' ),
				(string) ( $ctx['thread_id'] ?? '' ),
				wp_json_encode( $ctx ),
			) );
		}
		fclose( $out );
		exit;
	}
}
