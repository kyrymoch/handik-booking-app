<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests pipeline (Sprint 6 / IA reorg part 2).
 *
 * "Requests" is the work-in-progress view: everything a customer started
 * but that hasn't become a confirmed booking yet. It unifies three
 * sources that used to be scattered across People (drafts focus lists) and
 * Additional Forms (Direct / Project submission tabs):
 *
 *   - handik_job_requests        main-SPA requests with no booking row yet
 *                                (drafts / ready-not-booked / unsafe)
 *   - handik_direct_booking_requests   direct-form rows still in-flight
 *                                (opened / ready, not yet booked or cancelled)
 *   - handik_project_scheduling_requests   project rows not yet confirmed
 *                                (draft / selecting / selected / failed)
 *
 * Confirmed bookings live in the Bookings list (filterable by source since
 * 2.1.32.0); this page is purely the pipeline before that point. Read +
 * navigate: every row links to the customer profile (main / direct) or the
 * project-schedule detail (project), and carries a source + status pill.
 */
class Handik_Booking_App_Admin_Requests {
	const PAGE_SLUG = 'handik-booking-app-requests';
	const QUERY_CAP = 500;

	/** @var Handik_Booking_App_Contacts_Service|null */
	protected $contacts;
	/** @var Handik_Booking_App_Service_Catalog_Service|null */
	protected $catalog;

	public function __construct( $contacts = null, $catalog = null ) {
		$this->contacts = $contacts;
		$this->catalog  = $catalog;
	}

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$source = isset( $_GET['filter_source'] ) ? sanitize_key( wp_unslash( $_GET['filter_source'] ) ) : 'all';
		$status = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : 'all';
		$query  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable

		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'Requests', 'handik-booking-app' ),
			__( 'In-progress work — drafts, ready-not-booked, and unconfirmed form submissions. Confirmed visits live in Bookings.', 'handik-booking-app' )
		);

		echo $this->filter_bar_markup( $source, $status, $query );

		$rows = $this->load_rows();
		$rows = $this->apply_filters( $rows, $source, $status, $query );

		if ( empty( $rows ) ) {
			echo '<div class="handik-admin-empty"><p>' . esc_html__( 'No open requests match these filters. Everything is either booked or cleared.', 'handik-booking-app' ) . '</p></div>';
			Handik_Booking_App_Admin_Helpers::page_end();
			return;
		}

		// Batch-load contacts for the customer links.
		$contact_ids = array();
		foreach ( $rows as $r ) {
			if ( $r['contact_id'] > 0 ) {
				$contact_ids[] = $r['contact_id'];
			}
		}
		$contacts = ( $contact_ids && $this->contacts ) ? $this->contacts->get_many( $contact_ids ) : array();

		echo '<div class="handik-admin-table-wrap">';
		echo '<table class="widefat striped handik-admin-bookings-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'What', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$contact = $r['contact_id'] > 0 && isset( $contacts[ $r['contact_id'] ] ) ? $contacts[ $r['contact_id'] ] : null;
			$attention = in_array( $r['status'], array( 'unsafe', 'partial_failed', 'rolled_back' ), true );
			echo '<tr' . ( $attention ? ' class="handik-admin-row-attention"' : '' ) . '>';
			echo '<td>' . esc_html( Handik_Booking_App_Admin_Helpers::format_short( (string) $r['created_at'] ) ) . '</td>';
			echo '<td>' . ( $contact ? Handik_Booking_App_Admin_Helpers::customer_link( $contact ) : '<span class="handik-admin-muted">' . esc_html__( 'Unknown', 'handik-booking-app' ) . '</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . Handik_Booking_App_Admin_Helpers::booking_source_pill( $this->source_to_row_shape( $r ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $r['summary'] ) . '</td>';
			echo '<td>' . Handik_Booking_App_Admin_Helpers::status_pill_markup( (string) $r['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . ( $r['detail_url'] ? '<a class="button button-small" href="' . esc_url( $r['detail_url'] ) . '">' . esc_html__( 'Open', 'handik-booking-app' ) . '</a>' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	/**
	 * Build a minimal handik_bookings-shaped row so the shared source-pill
	 * helper classifies it correctly (it keys off the FK columns).
	 *
	 * @param array<string,mixed> $r Normalized request row.
	 * @return array<string,mixed>
	 */
	protected function source_to_row_shape( array $r ) {
		switch ( $r['source'] ) {
			case 'main':
				return array( 'job_request_id' => $r['pk'] );
			case 'direct':
				return array( 'direct_request_id' => $r['pk'] );
			case 'project':
				return array( 'project_work_day_id' => $r['pk'] );
			default:
				return array();
		}
	}

	/**
	 * Union the three in-flight sources into a normalized row list, newest
	 * first. Each row: source / pk / contact_id / created_at / status /
	 * summary / detail_url.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function load_rows() {
		global $wpdb;
		$jr  = Handik_Booking_App_DB::table( 'job_requests' );
		$bk  = Handik_Booking_App_DB::table( 'bookings' );
		$dbr = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$psr = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$cap = self::QUERY_CAP;

		$out = array();

		// Main-SPA requests with no booking row and not completed.
		$main = $wpdb->get_results(
			"SELECT jr.id, jr.contact_id, jr.created_at, jr.status, jr.short_description
			 FROM {$jr} jr LEFT JOIN {$bk} bk ON bk.job_request_id = jr.id
			 WHERE bk.id IS NULL AND jr.status <> 'completed'
			 ORDER BY jr.created_at DESC LIMIT {$cap}",
			ARRAY_A
		);
		foreach ( (array) $main as $row ) {
			$desc = trim( (string) ( $row['short_description'] ?? '' ) );
			$out[] = array(
				'source'     => 'main',
				'pk'         => (int) $row['id'],
				'contact_id' => (int) ( $row['contact_id'] ?? 0 ),
				'created_at' => (string) $row['created_at'],
				'status'     => (string) $row['status'],
				'summary'    => '' !== $desc ? $this->truncate( $desc ) : __( 'Assistant request', 'handik-booking-app' ),
				'detail_url' => $this->customer_detail_url( (int) ( $row['contact_id'] ?? 0 ) ),
			);
		}

		// Direct-form rows still in-flight.
		if ( $this->table_exists( $dbr ) ) {
			$direct = $wpdb->get_results(
				"SELECT id, contact_id, created_at, status, form_title
				 FROM {$dbr}
				 WHERE status NOT IN ( 'booked', 'cancelled' )
				 ORDER BY created_at DESC LIMIT {$cap}",
				ARRAY_A
			);
			foreach ( (array) $direct as $row ) {
				$out[] = array(
					'source'     => 'direct',
					'pk'         => (int) $row['id'],
					'contact_id' => (int) ( $row['contact_id'] ?? 0 ),
					'created_at' => (string) $row['created_at'],
					'status'     => (string) $row['status'],
					'summary'    => (string) ( $row['form_title'] ?? __( 'Direct booking', 'handik-booking-app' ) ),
					'detail_url' => $this->customer_detail_url( (int) ( $row['contact_id'] ?? 0 ) ),
				);
			}
		}

		// Project rows not yet confirmed.
		if ( $this->table_exists( $psr ) ) {
			$project = $wpdb->get_results(
				"SELECT id, contact_id, created_at, status, form_title, required_days
				 FROM {$psr}
				 WHERE status <> 'confirmed'
				 ORDER BY created_at DESC LIMIT {$cap}",
				ARRAY_A
			);
			foreach ( (array) $project as $row ) {
				$title = (string) ( $row['form_title'] ?? __( 'Project schedule', 'handik-booking-app' ) );
				$days  = (int) ( $row['required_days'] ?? 0 );
				$out[] = array(
					'source'     => 'project',
					'pk'         => (int) $row['id'],
					'contact_id' => (int) ( $row['contact_id'] ?? 0 ),
					'created_at' => (string) $row['created_at'],
					'status'     => (string) $row['status'],
					/* translators: 1: form title, 2: number of days */
					'summary'    => $days > 0 ? sprintf( __( '%1$s · %2$d days', 'handik-booking-app' ), $title, $days ) : $title,
					'detail_url' => $this->project_detail_url( (int) $row['id'] ),
				);
			}
		}

		// Newest first across all sources.
		usort( $out, static function ( $a, $b ) {
			return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
		} );
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows   Rows.
	 * @param string                           $source Source filter.
	 * @param string                           $status Status-group filter.
	 * @param string                           $query  Search.
	 * @return array<int, array<string, mixed>>
	 */
	protected function apply_filters( array $rows, $source, $status, $query ) {
		$query = strtolower( trim( (string) $query ) );
		$out   = array();
		$names = array();
		if ( '' !== $query && $this->contacts ) {
			// Pre-resolve contacts for search match on name/phone.
			$ids = array();
			foreach ( $rows as $r ) {
				if ( $r['contact_id'] > 0 ) {
					$ids[] = $r['contact_id'];
				}
			}
			$names = $ids ? $this->contacts->get_many( $ids ) : array();
		}
		foreach ( $rows as $r ) {
			if ( 'all' !== $source && $r['source'] !== $source ) {
				continue;
			}
			if ( 'all' !== $status && $this->classify_status( $r['source'], $r['status'] ) !== $status ) {
				continue;
			}
			if ( '' !== $query ) {
				$c        = isset( $names[ $r['contact_id'] ] ) ? $names[ $r['contact_id'] ] : null;
				$haystack = strtolower(
					(string) ( $c['full_name'] ?? '' ) . ' ' .
					(string) ( $c['phone'] ?? '' ) . ' ' .
					(string) $r['summary']
				);
				if ( false === strpos( $haystack, $query ) ) {
					continue;
				}
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Coarse cross-source status grouping for the status filter.
	 *
	 * @param string $source Source.
	 * @param string $status Raw status.
	 * @return string Group key.
	 */
	protected function classify_status( $source, $status ) {
		if ( 'unsafe' === $status || 'partial_failed' === $status || 'rolled_back' === $status ) {
			return 'attention';
		}
		if ( 'draft' === $status ) {
			return 'drafts';
		}
		if ( in_array( $status, array( 'ready_for_booking', 'booking_pending', 'booking_opened' ), true ) ) {
			return 'ready';
		}
		return 'in_progress';
	}

	protected function filter_bar_markup( $source, $status, $query ) {
		$source_options = array(
			'all'     => __( 'All sources', 'handik-booking-app' ),
			'main'    => __( 'Main SPA', 'handik-booking-app' ),
			'direct'  => __( 'Direct form', 'handik-booking-app' ),
			'project' => __( 'Project form', 'handik-booking-app' ),
		);
		$status_options = array(
			'all'         => __( 'All statuses', 'handik-booking-app' ),
			'drafts'      => __( 'Drafts', 'handik-booking-app' ),
			'ready'       => __( 'Ready, not booked', 'handik-booking-app' ),
			'in_progress' => __( 'In progress', 'handik-booking-app' ),
			'attention'   => __( 'Needs attention', 'handik-booking-app' ),
		);
		ob_start();
		?>
		<form class="handik-admin-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<div class="handik-admin-filter-row">
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Source', 'handik-booking-app' ); ?></span>
					<select name="filter_source">
						<?php foreach ( $source_options as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $source, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Status', 'handik-booking-app' ); ?></span>
					<select name="filter_status">
						<?php foreach ( $status_options as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter handik-admin-filter--search">
					<span><?php esc_html_e( 'Search by name or phone', 'handik-booking-app' ); ?></span>
					<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" data-handik-debounced-submit placeholder="<?php esc_attr_e( 'e.g. Zinkin or 617…', 'handik-booking-app' ); ?>" />
				</label>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'handik-booking-app' ); ?></button>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Reset', 'handik-booking-app' ); ?></a>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	protected function customer_detail_url( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return '';
		}
		return Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'contact_id' => $contact_id ) );
	}

	protected function project_detail_url( $schedule_id ) {
		return Handik_Booking_App_Admin_Helpers::admin_url_for(
			Handik_Booking_App_Admin_Additional_Forms::PAGE_SLUG,
			array( 'tab' => 'project', 'schedule_id' => (int) $schedule_id )
		);
	}

	protected function truncate( $text, $len = 80 ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $len ) {
			return mb_substr( $text, 0, $len - 1 ) . '…';
		}
		if ( strlen( $text ) > $len ) {
			return substr( $text, 0, $len - 1 ) . '…';
		}
		return $text;
	}

	protected function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
