<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports — money / tax summary (Sprint 10).
 *
 * Aggregates the per-booking money fields (migration 1.6.7) over a period
 * into the numbers an operator hands their accountant: gross revenue,
 * materials, mileage deduction (miles × IRS rate), and net pre-tax, plus a
 * breakdown by service family / source. Revenue is recognized on
 * COMPLETED visits in the period (by visit date). When a completed
 * booking has no recorded actual amount, the assistant's high estimate is
 * used as a fallback so a half-filled month still produces a usable total
 * (flagged in the CSV so the operator can tighten it up).
 *
 * CSV export streams the period's line items (own admin_init handler,
 * nonce-verified, mirrors Admin_Logs). PDF is intentionally out of scope —
 * CSV imports straight into spreadsheets / accounting tools.
 */
class Handik_Booking_App_Admin_Reports {
	const PAGE_SLUG = 'handik-booking-app-reports';

	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Settings */
	protected $settings;

	public function __construct( $bookings, $job_requests, $settings ) {
		$this->bookings     = $bookings;
		$this->job_requests = $job_requests;
		$this->settings     = $settings;
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
	}

	/**
	 * Resolve the active reporting window from the request. Supports
	 * ?period=Q1..Q4|year + ?year=YYYY, or explicit ?from=&to= (YYYY-MM-DD).
	 *
	 * @return array{from: string, to: string, label: string, period: string, year: int}
	 */
	protected function resolve_period() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$year   = isset( $_GET['year'] ) ? max( 2000, min( 2100, absint( $_GET['year'] ) ) ) : (int) gmdate( 'Y' );
		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '';
		$from_q = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to_q   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable

		if ( '' !== $from_q && '' !== $to_q && $this->valid_date( $from_q ) && $this->valid_date( $to_q ) ) {
			return array(
				'from'   => $from_q . ' 00:00:00',
				'to'     => $to_q . ' 23:59:59',
				'label'  => $from_q . ' → ' . $to_q,
				'period' => 'custom',
				'year'   => $year,
			);
		}

		$quarters = array(
			'Q1' => array( '01-01', '03-31' ),
			'Q2' => array( '04-01', '06-30' ),
			'Q3' => array( '07-01', '09-30' ),
			'Q4' => array( '10-01', '12-31' ),
		);
		if ( isset( $quarters[ strtoupper( $period ) ] ) ) {
			$q = $quarters[ strtoupper( $period ) ];
			return array(
				'from'   => $year . '-' . $q[0] . ' 00:00:00',
				'to'     => $year . '-' . $q[1] . ' 23:59:59',
				'label'  => sprintf( '%s %d', strtoupper( $period ), $year ),
				'period' => strtoupper( $period ),
				'year'   => $year,
			);
		}
		// Default: full year.
		return array(
			'from'   => $year . '-01-01 00:00:00',
			'to'     => $year . '-12-31 23:59:59',
			'label'  => (string) $year,
			'period' => 'year',
			'year'   => $year,
		);
	}

	protected function valid_date( $d ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d );
	}

	/**
	 * Build the report line items + totals for a window.
	 *
	 * @param string $from MySQL datetime.
	 * @param string $to   MySQL datetime.
	 * @return array{items: array, totals: array, breakdown: array}
	 */
	protected function build_report( $from, $to ) {
		global $wpdb;
		$bk = Handik_Booking_App_DB::table( 'bookings' );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$bk} WHERE start_time BETWEEN %s AND %s ORDER BY start_time ASC LIMIT 5000",
				$from,
				$to
			),
			ARRAY_A
		);

		// Batch-load job requests for estimate fallback + service family.
		$req_ids = array();
		foreach ( (array) $rows as $r ) {
			$rid = (int) ( $r['job_request_id'] ?? 0 );
			if ( $rid > 0 ) {
				$req_ids[] = $rid;
			}
		}
		$requests = ( $req_ids && $this->job_requests && method_exists( $this->job_requests, 'get_many' ) )
			? $this->job_requests->get_many( $req_ids )
			: array();

		$items     = array();
		$gross     = 0;   // cents
		$materials = 0;   // cents
		$miles     = 0.0;
		$estimated_count = 0;
		$breakdown = array();

		foreach ( (array) $rows as $row ) {
			$status = $this->bookings ? $this->bookings->effective_status( $row ) : (string) ( $row['status'] ?? '' );
			if ( 'completed' !== $status ) {
				continue;
			}
			$rid     = (int) ( $row['job_request_id'] ?? 0 );
			$req     = $rid && isset( $requests[ $rid ] ) ? $requests[ $rid ] : null;
			$is_est  = false;
			$amount  = $row['actual_amount_cents'];
			if ( null === $amount || '' === $amount ) {
				// Fallback to the assistant high estimate.
				$state  = $req && ! empty( $req['app_state'] ) && is_array( $req['app_state'] ) ? $req['app_state'] : array();
				$amount = (int) round( ( (float) ( $state['total_estimate_high'] ?? 0 ) ) * 100 );
				$is_est = $amount > 0;
				if ( $is_est ) {
					++$estimated_count;
				}
			} else {
				$amount = (int) $amount;
			}
			$mat = (int) ( $row['materials_amount_cents'] ?? 0 );
			$mi  = (float) ( $row['mileage_miles'] ?? 0 );

			$gross     += $amount;
			$materials += $mat;
			$miles     += $mi;

			$label = $this->service_label( $row, $req );
			if ( ! isset( $breakdown[ $label ] ) ) {
				$breakdown[ $label ] = array( 'count' => 0, 'gross' => 0 );
			}
			$breakdown[ $label ]['count']++;
			$breakdown[ $label ]['gross'] += $amount;

			$items[] = array(
				'id'         => (int) $row['id'],
				'date'       => (string) $row['start_time'],
				'service'    => $label,
				'amount'     => $amount,
				'estimated'  => $is_est,
				'materials'  => $mat,
				'miles'      => $mi,
				'status'     => (string) ( $row['payment_status'] ?? '' ),
				'method'     => (string) ( $row['payment_method_used'] ?? '' ),
				'invoice'    => (string) ( $row['invoice_number'] ?? '' ),
			);
		}

		$rate_cents     = (int) $this->settings->get( 'mileage_rate_cents', 70 );
		$mileage_cents  = (int) round( $miles * $rate_cents );
		$net_cents      = $gross - $materials - $mileage_cents;

		return array(
			'items'  => $items,
			'totals' => array(
				'gross_cents'     => $gross,
				'materials_cents' => $materials,
				'miles'           => $miles,
				'mileage_cents'   => $mileage_cents,
				'rate_cents'      => $rate_cents,
				'net_cents'       => $net_cents,
				'count'           => count( $items ),
				'estimated_count' => $estimated_count,
			),
			'breakdown' => $breakdown,
		);
	}

	protected function service_label( array $row, $req ) {
		if ( $req && ! empty( $req['service_family'] ) ) {
			return (string) $req['service_family'];
		}
		if ( ! empty( $row['job_request_id'] ) ) {
			return __( 'main', 'handik-booking-app' );
		}
		if ( ! empty( $row['direct_request_id'] ) ) {
			return __( 'direct', 'handik-booking-app' );
		}
		if ( ! empty( $row['project_work_day_id'] ) ) {
			return __( 'project', 'handik-booking-app' );
		}
		return __( 'external', 'handik-booking-app' );
	}

	protected function money( $cents ) {
		return '$' . number_format( (int) $cents / 100, 2 );
	}

	public function render() {
		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'Reports', 'handik-booking-app' ),
			__( 'Revenue, materials and mileage for tax prep. Recognized on completed visits in the period.', 'handik-booking-app' )
		);
		settings_errors( 'handik-booking-app' );

		$p      = $this->resolve_period();
		$report = $this->build_report( $p['from'], $p['to'] );
		$t      = $report['totals'];

		echo $this->period_picker_markup( $p );

		// Totals cards.
		$cards = array(
			array( __( 'Gross revenue', 'handik-booking-app' ), $this->money( $t['gross_cents'] ) ),
			array( __( 'Materials', 'handik-booking-app' ), $this->money( $t['materials_cents'] ) ),
			array(
				/* translators: %s: miles */
				sprintf( __( 'Mileage (%s mi)', 'handik-booking-app' ), rtrim( rtrim( number_format( $t['miles'], 1 ), '0' ), '.' ) ),
				$this->money( $t['mileage_cents'] ),
			),
			array( __( 'Net pre-tax', 'handik-booking-app' ), $this->money( $t['net_cents'] ) ),
			array( __( 'Completed visits', 'handik-booking-app' ), (string) $t['count'] ),
		);
		echo '<section class="handik-admin-block handik-admin-cust-stats"><div class="handik-admin-cust-stats__grid">';
		foreach ( $cards as $c ) {
			echo '<div class="handik-admin-cust-stats__cell"><span class="handik-admin-cust-stats__label">' . esc_html( $c[0] ) . '</span><strong class="handik-admin-cust-stats__value">' . esc_html( $c[1] ) . '</strong></div>';
		}
		echo '</div>';
		if ( $t['estimated_count'] > 0 ) {
			echo '<p class="handik-admin-muted">' . esc_html( sprintf(
				/* translators: %d: count */
				_n( '%d completed visit had no recorded amount — its high estimate was used. Fill the Payment block on those bookings to firm up the numbers.', '%d completed visits had no recorded amount — their high estimates were used. Fill the Payment block on those bookings to firm up the numbers.', $t['estimated_count'], 'handik-booking-app' ),
				$t['estimated_count']
			) ) . '</p>';
		}
		echo '<p class="handik-admin-muted">' . esc_html( sprintf(
			/* translators: %s: rate in dollars */
			__( 'Mileage deduction at %s/mile. Set the IRS rate under Settings → Booking flow.', 'handik-booking-app' ),
			'$' . number_format( $t['rate_cents'] / 100, 2 )
		) ) . '</p>';
		echo '</section>';

		// Breakdown.
		if ( ! empty( $report['breakdown'] ) ) {
			arsort( $report['breakdown'] );
			echo '<section class="handik-admin-block"><h3 class="handik-admin-section-title">' . esc_html__( 'By service', 'handik-booking-app' ) . '</h3><table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Service', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Visits', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Gross', 'handik-booking-app' ) . '</th></tr></thead><tbody>';
			foreach ( $report['breakdown'] as $label => $b ) {
				echo '<tr><td>' . esc_html( $label ) . '</td><td>' . (int) $b['count'] . '</td><td>' . esc_html( $this->money( $b['gross'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></section>';
		}

		// Export.
		$csv_url = add_query_arg(
			array_filter( array(
				'page'     => self::PAGE_SLUG,
				'period'   => $p['period'],
				'year'     => $p['year'],
				'from'     => 'custom' === $p['period'] ? gmdate( 'Y-m-d', strtotime( $p['from'] ) ) : '',
				'to'       => 'custom' === $p['period'] ? gmdate( 'Y-m-d', strtotime( $p['to'] ) ) : '',
				'export'   => 'csv',
				'_wpnonce' => wp_create_nonce( 'handik_reports_csv' ),
			), static function ( $v ) { return '' !== $v; } ),
			admin_url( 'admin.php' )
		);
		echo '<p><a class="button button-primary" href="' . esc_url( $csv_url ) . '">⬇ ' . esc_html__( 'Export CSV', 'handik-booking-app' ) . '</a></p>';

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function period_picker_markup( array $p ) {
		$years = range( (int) gmdate( 'Y' ), (int) gmdate( 'Y' ) - 4 );
		ob_start();
		?>
		<form class="handik-admin-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<div class="handik-admin-filter-row">
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Period', 'handik-booking-app' ); ?></span>
					<select name="period">
						<?php foreach ( array( 'year' => __( 'Full year', 'handik-booking-app' ), 'Q1' => 'Q1', 'Q2' => 'Q2', 'Q3' => 'Q3', 'Q4' => 'Q4' ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $p['period'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Year', 'handik-booking-app' ); ?></span>
					<select name="year">
						<?php foreach ( $years as $y ) : ?>
							<option value="<?php echo esc_attr( (string) $y ); ?>"<?php selected( $p['year'], $y ); ?>><?php echo esc_html( (string) $y ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'handik-booking-app' ); ?></button>
			</div>
			<p class="handik-admin-muted"><?php esc_html_e( 'Or a custom range:', 'handik-booking-app' ); ?>
				<input type="date" name="from" /> → <input type="date" name="to" />
				<button type="submit" class="button"><?php esc_html_e( 'Apply range', 'handik-booking-app' ); ?></button>
			</p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Stream the period's line items as CSV. admin_init hook, before HTML.
	 */
	public function maybe_export_csv() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( empty( $_GET['export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['export'] ) ) ) {
			return;
		}
		// phpcs:enable
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'handik_reports_csv' ) ) {
			return;
		}

		$p      = $this->resolve_period();
		$report = $this->build_report( $p['from'], $p['to'] );
		$t      = $report['totals'];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="handik-report-' . sanitize_file_name( $p['label'] ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Booking ID', 'Visit date', 'Service', 'Amount ($)', 'Estimated?', 'Materials ($)', 'Miles', 'Payment status', 'Method', 'Invoice' ) );
		foreach ( $report['items'] as $it ) {
			fputcsv( $out, array(
				$it['id'],
				$it['date'],
				$it['service'],
				number_format( $it['amount'] / 100, 2, '.', '' ),
				$it['estimated'] ? 'yes' : '',
				number_format( $it['materials'] / 100, 2, '.', '' ),
				rtrim( rtrim( number_format( $it['miles'], 1 ), '0' ), '.' ),
				$it['status'],
				$it['method'],
				$it['invoice'],
			) );
		}
		// Totals footer.
		fputcsv( $out, array() );
		fputcsv( $out, array( 'TOTALS (' . $p['label'] . ')' ) );
		fputcsv( $out, array( 'Gross revenue', number_format( $t['gross_cents'] / 100, 2, '.', '' ) ) );
		fputcsv( $out, array( 'Materials', number_format( $t['materials_cents'] / 100, 2, '.', '' ) ) );
		fputcsv( $out, array( 'Mileage miles', rtrim( rtrim( number_format( $t['miles'], 1 ), '0' ), '.' ) ) );
		fputcsv( $out, array( 'Mileage deduction', number_format( $t['mileage_cents'] / 100, 2, '.', '' ) ) );
		fputcsv( $out, array( 'Net pre-tax', number_format( $t['net_cents'] / 100, 2, '.', '' ) ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
