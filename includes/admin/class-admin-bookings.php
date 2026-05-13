<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookings list (B1) + booking detail (B2) + actions (B4).
 *
 * The detail page renders 8 blocks:
 *   - Sticky action bar (Call / Maps / Cal.com)
 *   - At a glance grid
 *   - Photos / videos
 *   - What the customer wrote (real transcript via Messages_Service if any)
 *   - Assistant summary + estimate
 *   - Selected tasks
 *   - Address with embedded map
 *   - Technical details (collapsed)
 *   - Chat activity (collapsed legacy log-grep view)
 */
class Handik_Booking_App_Admin_Bookings {

	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Service_Catalog_Service */
	protected $catalog;
	/** @var Handik_Booking_App_Logger */
	protected $logger;
	/** @var Handik_Booking_App_Messages_Service|null */
	protected $messages;
	/** @var Handik_Booking_App_Booking_Presets_Service|null */
	protected $booking_presets;

	public function __construct( $bookings, $job_requests, $contacts, $addresses, $catalog, $logger, $messages, $booking_presets = null ) {
		$this->bookings        = $bookings;
		$this->job_requests    = $job_requests;
		$this->contacts        = $contacts;
		$this->addresses       = $addresses;
		$this->catalog         = $catalog;
		$this->logger          = $logger;
		$this->messages        = $messages;
		// Sprint 13 — used by the Add Booking page to populate the
		// preset picker with currently-enabled direct presets.
		$this->booking_presets = $booking_presets;
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'new' === $action ) {
			// Sprint 13 — admin-initiated direct booking. Lives under
			// the Bookings page slug so the operator's mental model
			// ("Bookings → + Add") matches the URL. Contact_id can be
			// passed from the Person detail page to pre-fill the form.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$contact_id = isset( $_GET['contact_id'] ) ? absint( wp_unslash( $_GET['contact_id'] ) ) : 0;
			$this->render_new_booking( $contact_id );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$booking_id = isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;
		if ( $booking_id ) {
			$this->render_detail( $booking_id );
			return;
		}
		$this->render_list();
	}

	// =====================================================================
	// LIST (B1)
	// =====================================================================

	const PAGE_SIZE = 50;

	/**
	 * Sprint 10 fix: capture the current list filter / search / page in
	 * `from_*` query params so the booking-detail "Back" link can restore
	 * the same view. Used by both the card grid and the desktop table.
	 *
	 * @return array<string, string|int>
	 */
	protected function detail_back_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$out = array();
		if ( ! empty( $_GET['filter_time'] ) ) {
			$out['from_filter_time'] = sanitize_key( wp_unslash( $_GET['filter_time'] ) );
		}
		if ( ! empty( $_GET['filter_status'] ) ) {
			$out['from_filter_status'] = sanitize_key( wp_unslash( $_GET['filter_status'] ) );
		}
		if ( ! empty( $_GET['q'] ) ) {
			$out['from_q'] = sanitize_text_field( wp_unslash( $_GET['q'] ) );
		}
		if ( ! empty( $_GET['paged'] ) ) {
			$out['from_paged'] = absint( wp_unslash( $_GET['paged'] ) );
		}
		// phpcs:enable
		return $out;
	}

	protected function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter_time   = isset( $_GET['filter_time'] ) ? sanitize_key( wp_unslash( $_GET['filter_time'] ) ) : 'all';
		$filter_status = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : 'all';
		$query         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'Bookings', 'handik-booking-app' ),
			__( 'Upcoming first, then past visits.', 'handik-booking-app' )
		);

		// Sprint 13 — admin can book on behalf of a customer through
		// the same Cal.com flow the public direct-booking form uses.
		// The CTA shows for any user with the bookings cap; capability
		// for the underlying REST is the same (admin_permission gate).
		$add_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', array( 'action' => 'new' ) );
		echo '<p class="handik-admin-bookings-list__cta"><a class="button button-primary" href="' . esc_url( $add_url ) . '">+ ' . esc_html__( 'Add booking', 'handik-booking-app' ) . '</a></p>';

		echo $this->filter_bar_markup( $filter_time, $filter_status, $query );

		$rows = $this->load_filtered_bookings( $filter_time, $filter_status, $query );

		if ( empty( $rows ) ) {
			echo '<div class="handik-admin-empty"><p>' . esc_html__( 'No bookings match these filters.', 'handik-booking-app' ) . '</p></div>';
			Handik_Booking_App_Admin_Helpers::page_end();
			return;
		}

		// Split upcoming vs past, upcoming asc, past desc.
		$now = gmdate( 'Y-m-d H:i:s' );
		$upcoming = array();
		$past     = array();
		foreach ( $rows as $row ) {
			$start = (string) ( $row['start_time'] ?? '' );
			if ( $start && $start >= $now ) {
				$upcoming[] = $row;
			} else {
				$past[] = $row;
			}
		}
		usort( $upcoming, static function( $a, $b ) {
			return strcmp( (string) ( $a['start_time'] ?? '' ), (string) ( $b['start_time'] ?? '' ) );
		} );
		usort( $past, static function( $a, $b ) {
			return strcmp( (string) ( $b['start_time'] ?? '' ), (string) ( $a['start_time'] ?? '' ) );
		} );

		// Sprint 10 fix: pagination. Was capped at 500 rows total with no
		// paging UI — at 10k bookings the owner silently lost everything
		// older than ~9 months. Now `load_filtered_bookings` pulls 2000
		// (covers 18+ months for solo-owner volumes), and the page
		// slices to PAGE_SIZE here. We always show all upcoming (rare
		// to have >50 future bookings) and paginate the PAST set.
		$total_upcoming = count( $upcoming );
		$total_past     = count( $past );
		$total_pages    = max( 1, (int) ceil( $total_past / self::PAGE_SIZE ) );
		$paged          = min( $paged, $total_pages );
		$past_offset    = ( $paged - 1 ) * self::PAGE_SIZE;
		$past_page      = array_slice( $past, $past_offset, self::PAGE_SIZE );

		// Sprint 7 (admin perf): bulk-load decorations (request/contact/address)
		// once and pass them down to the card + table loops.
		$decorations = $this->decorate_bookings( array_merge( $upcoming, $past_page ) );

		echo '<div class="handik-admin-bookings-list">';

		// Cards on mobile, table on desktop.
		echo '<div class="handik-admin-bookings-cards" data-handik-bookings-cards>';
		foreach ( $upcoming as $row ) {
			echo $this->booking_card( $row, false, $decorations );
		}
		if ( $past_page ) {
			$divider = sprintf(
				/* translators: 1 = page row count, 2 = total past rows */
				_n( '%1$d of %2$d past booking', '%1$d of %2$d past bookings', $total_past, 'handik-booking-app' ),
				count( $past_page ),
				$total_past
			);
			echo '<div class="handik-admin-divider"><span>' . esc_html( $divider ) . '</span></div>';
			foreach ( $past_page as $row ) {
				echo $this->booking_card( $row, true, $decorations );
			}
		}
		echo '</div>';

		echo $this->bookings_table_markup( $upcoming, $past_page, $decorations );

		echo $this->pagination_markup( $paged, $total_pages, $total_upcoming, $total_past );
		echo '</div>';

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function pagination_markup( $paged, $total_pages, $total_upcoming, $total_past ) {
		// Sprint 10: status row always visible (so owner sees the
		// total volume), prev/next links only when there's something
		// to navigate to. Filters preserved across pages.
		$base_args = array_filter( array(
			'page'          => 'handik-booking-app-bookings',
			'filter_time'   => isset( $_GET['filter_time'] ) ? sanitize_key( wp_unslash( $_GET['filter_time'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'filter_status' => isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'q'             => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		), static function ( $v ) { return '' !== $v && 'all' !== $v; } );

		$page_url = function ( $n ) use ( $base_args ) {
			$args = $base_args;
			if ( $n > 1 ) { $args['paged'] = $n; }
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		};

		$out  = '<nav class="handik-admin-pagination" aria-label="' . esc_attr__( 'Bookings pagination', 'handik-booking-app' ) . '">';
		$out .= '<span class="handik-admin-pagination__summary">';
		$out .= esc_html( sprintf(
			/* translators: 1 = upcoming, 2 = past total, 3 = current page, 4 = total pages */
			__( '%1$d upcoming · %2$d past · page %3$d of %4$d', 'handik-booking-app' ),
			$total_upcoming, $total_past, $paged, $total_pages
		) );
		$out .= '</span>';
		if ( $total_pages > 1 ) {
			$out .= '<span class="handik-admin-pagination__nav">';
			if ( $paged > 1 ) {
				$out .= '<a class="button" href="' . esc_url( $page_url( $paged - 1 ) ) . '">‹ ' . esc_html__( 'Newer', 'handik-booking-app' ) . '</a>';
			}
			if ( $paged < $total_pages ) {
				$out .= '<a class="button" href="' . esc_url( $page_url( $paged + 1 ) ) . '">' . esc_html__( 'Older', 'handik-booking-app' ) . ' ›</a>';
			}
			$out .= '</span>';
		}
		$out .= '</nav>';
		return $out;
	}

	protected function filter_bar_markup( $filter_time, $filter_status, $query ) {
		$page = 'handik-booking-app-bookings';
		$time_options = array(
			'all'        => __( 'All', 'handik-booking-app' ),
			'today'      => __( 'Today', 'handik-booking-app' ),
			'tomorrow'   => __( 'Tomorrow', 'handik-booking-app' ),
			'this_week'  => __( 'This week', 'handik-booking-app' ),
			'this_month' => __( 'This month', 'handik-booking-app' ),
			'past'       => __( 'Past', 'handik-booking-app' ),
		);
		// Sprint 10 fix: Sprint 8 split `booked` (Cal webhook acknowledged,
		// blue pill) from `confirmed` (contractor-confirmed, green pill)
		// from `completed` (final, teal pill), but the filter still
		// bundled `booked` ∪ `confirmed` under one "Confirmed" chip — so
		// the filter surfaced blue rows next to the Confirmed label and
		// the owner couldn't isolate the truly-confirmed-by-me set.
		// Filter chips now mirror the pill taxonomy 1:1, plus a wider
		// "On the schedule" chip that matches both for the legacy mental
		// model (booked + confirmed = "this is going to happen").
		$status_options = array(
			'all'         => __( 'All', 'handik-booking-app' ),
			'on_schedule' => __( 'On the schedule', 'handik-booking-app' ),
			'booked'      => __( 'Booked (Cal)', 'handik-booking-app' ),
			'confirmed'   => __( 'Confirmed', 'handik-booking-app' ),
			'pending'     => __( 'Pending', 'handik-booking-app' ),
			'cancelled'   => __( 'Cancelled', 'handik-booking-app' ),
			'completed'   => __( 'Completed', 'handik-booking-app' ),
		);

		ob_start();
		?>
		<form class="handik-admin-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<div class="handik-admin-filter-row">
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Time', 'handik-booking-app' ); ?></span>
					<select name="filter_time">
						<?php foreach ( $time_options as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filter_time, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter">
					<span><?php esc_html_e( 'Status', 'handik-booking-app' ); ?></span>
					<select name="filter_status">
						<?php foreach ( $status_options as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filter_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="handik-admin-filter handik-admin-filter--search">
					<span><?php esc_html_e( 'Search by name or phone', 'handik-booking-app' ); ?></span>
					<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" data-handik-debounced-submit placeholder="<?php esc_attr_e( 'e.g. Zinkin or 617…', 'handik-booking-app' ); ?>" />
				</label>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'handik-booking-app' ); ?></button>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>"><?php esc_html_e( 'Reset', 'handik-booking-app' ); ?></a>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	protected function load_filtered_bookings( $filter_time, $filter_status, $query ) {
		if ( ! $this->bookings ) {
			return array();
		}

		// Pull a wide window first, then apply in-PHP filters that don't fit
		// the shipped service interface cleanly (status, search). Cap raised
		// from 500 → 2000 in Sprint 10 to cover ~18 months of solo-owner
		// volume; pagination at the page level keeps the rendered list
		// to PAGE_SIZE rows so a 5-year archive doesn't blow up the page.
		$tz = new DateTimeZone( Handik_Booking_App_Admin_Helpers::TIMEZONE );
		$now_et = new DateTimeImmutable( 'now', $tz );

		switch ( $filter_time ) {
			case 'today':
				$from = $now_et->setTime( 0, 0 );
				$to   = $from->modify( '+1 day' );
				break;
			case 'tomorrow':
				$from = $now_et->setTime( 0, 0 )->modify( '+1 day' );
				$to   = $from->modify( '+1 day' );
				break;
			case 'this_week':
				$from = $now_et->setTime( 0, 0 );
				$dow  = (int) $now_et->format( 'N' );
				$to   = $from->modify( '+' . ( ( 7 - $dow ) + 1 ) . ' days' );
				break;
			case 'this_month':
				$from = $now_et->modify( 'first day of this month' )->setTime( 0, 0 );
				$to   = $from->modify( '+1 month' );
				break;
			case 'past':
				$from = $now_et->modify( '-3 months' );
				$to   = $now_et;
				break;
			default:
				$from = $now_et->modify( '-3 months' );
				$to   = $now_et->modify( '+6 months' );
		}

		$rows = $this->bookings->list_in_window(
			$from->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$to->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			2000
		);

		$query = strtolower( trim( (string) $query ) );

		$out = array();
		foreach ( $rows as $row ) {
			$status = $this->bookings->effective_status( $row );
			if ( 'all' !== $filter_status ) {
				$matches = false;
				// Sprint 10 fix: 'confirmed' matches ONLY confirmed
				// (green pill); 'booked' is its own chip (blue pill);
				// 'on_schedule' is the legacy union chip for the
				// "anything that's on the calendar" mental model.
				if ( 'on_schedule' === $filter_status && in_array( $status, array( 'booked', 'confirmed' ), true ) ) {
					$matches = true;
				}
				if ( 'booked' === $filter_status && 'booked' === $status ) {
					$matches = true;
				}
				if ( 'confirmed' === $filter_status && 'confirmed' === $status ) {
					$matches = true;
				}
				if ( 'pending' === $filter_status && in_array( $status, array( 'pending', 'booking_pending' ), true ) ) {
					$matches = true;
				}
				if ( 'cancelled' === $filter_status && 'cancelled' === $status ) {
					$matches = true;
				}
				if ( 'completed' === $filter_status && 'completed' === $status ) {
					$matches = true;
				}
				if ( ! $matches ) {
					continue;
				}
			}

			if ( '' !== $query ) {
				$request = ! empty( $row['job_request_id'] ) ? $this->job_requests->get( (int) $row['job_request_id'] ) : null;
				$contact = ( $request && ! empty( $request['contact_id'] ) ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
				$haystack = strtolower(
					(string) ( $contact['full_name'] ?? '' ) . ' ' .
					(string) ( $contact['phone'] ?? '' ) . ' ' .
					(string) ( $contact['email'] ?? '' )
				);
				if ( false === strpos( $haystack, $query ) ) {
					continue;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Sprint 7 (admin perf): bulk-load the request/contact/address records
	 * referenced by a list of booking rows. Returned shape is keyed by the
	 * booking id so the cards/table loops can do an O(1) lookup instead of
	 * three per-row `->get()` calls. When the underlying services lack a
	 * `get_many()` (legacy install of this method-set) we fall back to the
	 * single-row path so the page still renders.
	 *
	 * @param array<int, array<string, mixed>> $rows Booking rows.
	 * @return array<int, array{request: ?array, contact: ?array, address: ?array}>
	 */
	protected function decorate_bookings( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return array();
		}
		// Sprint 13.5 — split rows by source. Main-SPA Cal bookings hang
		// off `job_request_id`; direct-form bookings (Sprint 4 onward,
		// admin-initiated since Sprint 13) hang off `direct_request_id`
		// and have NO job_request — their contact/address come from
		// `handik_direct_booking_requests` instead.
		// 1.6.0 — third source: project work days hang off
		// `project_work_day_id`, and their contact/address come from
		// `handik_project_scheduling_requests` (joined via work_day).
		$request_ids         = array();
		$direct_request_ids  = array();
		$project_day_ids     = array();
		$external_contact_ids = array();
		foreach ( $rows as $row ) {
			$rid  = (int) ( $row['job_request_id']      ?? 0 );
			$drid = (int) ( $row['direct_request_id']   ?? 0 );
			$pwid = (int) ( $row['project_work_day_id'] ?? 0 );
			$ecid = (int) ( $row['external_contact_id'] ?? 0 );
			if ( $rid > 0 )  { $request_ids[]         = $rid; }
			if ( $drid > 0 ) { $direct_request_ids[]  = $drid; }
			if ( $pwid > 0 ) { $project_day_ids[]     = $pwid; }
			if ( $ecid > 0 ) { $external_contact_ids[] = $ecid; }
		}

		$requests = ( $this->job_requests && method_exists( $this->job_requests, 'get_many' ) )
			? $this->job_requests->get_many( $request_ids )
			: array();

		// Bulk-fetch direct rows. There's no get_many on
		// Direct_Booking_Service today, so single SELECT IN(...) here
		// matches the perf shape of get_many.
		$direct_rows = array();
		if ( ! empty( $direct_request_ids ) ) {
			$direct_request_ids = array_values( array_unique( array_filter( array_map( 'absint', $direct_request_ids ) ) ) );
			if ( ! empty( $direct_request_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $direct_request_ids ), '%d' ) );
				$direct_table = Handik_Booking_App_DB::table( 'direct_booking_requests' );
				$rows_db = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$direct_table} WHERE id IN ({$placeholders})", $direct_request_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
				foreach ( (array) $rows_db as $r ) {
					$direct_rows[ (int) $r['id'] ] = $r;
				}
			}
		}

		// 1.6.0 — Bulk-fetch project work-day rows + their parent
		// scheduling_requests (which carry the contact_id/address_id).
		// Mirrors the direct_rows SELECT-IN shape. Two queries instead
		// of a JOIN so the keys stay parallel to the other arrays
		// (`$project_day_rows` keyed by day id, `$project_schedule_rows`
		// keyed by schedule id).
		$project_day_rows      = array();
		$project_schedule_rows = array();
		if ( ! empty( $project_day_ids ) ) {
			$project_day_ids = array_values( array_unique( array_filter( array_map( 'absint', $project_day_ids ) ) ) );
			if ( ! empty( $project_day_ids ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $project_day_ids ), '%d' ) );
				$days_table     = Handik_Booking_App_DB::table( 'project_work_days' );
				$days_db        = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$days_table} WHERE id IN ({$placeholders})", $project_day_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
				$schedule_ids   = array();
				foreach ( (array) $days_db as $d ) {
					$project_day_rows[ (int) $d['id'] ] = $d;
					if ( ! empty( $d['scheduling_request_id'] ) ) {
						$schedule_ids[] = (int) $d['scheduling_request_id'];
					}
				}
				$schedule_ids = array_values( array_unique( array_filter( $schedule_ids ) ) );
				if ( ! empty( $schedule_ids ) ) {
					$placeholders     = implode( ',', array_fill( 0, count( $schedule_ids ), '%d' ) );
					$schedules_table  = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
					$schedules_db     = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM {$schedules_table} WHERE id IN ({$placeholders})", $schedule_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						ARRAY_A
					);
					foreach ( (array) $schedules_db as $s ) {
						$project_schedule_rows[ (int) $s['id'] ] = $s;
					}
				}
			}
		}

		$contact_ids = array();
		$address_ids = array();
		foreach ( $requests as $request ) {
			$cid = (int) ( $request['contact_id'] ?? 0 );
			$aid = (int) ( $request['address_id'] ?? 0 );
			if ( $cid > 0 ) { $contact_ids[] = $cid; }
			if ( $aid > 0 ) { $address_ids[] = $aid; }
		}
		foreach ( $direct_rows as $dr ) {
			$cid = (int) ( $dr['contact_id'] ?? 0 );
			$aid = (int) ( $dr['address_id'] ?? 0 );
			if ( $cid > 0 ) { $contact_ids[] = $cid; }
			if ( $aid > 0 ) { $address_ids[] = $aid; }
		}
		foreach ( $project_schedule_rows as $ps ) {
			$cid = (int) ( $ps['contact_id'] ?? 0 );
			$aid = (int) ( $ps['address_id'] ?? 0 );
			if ( $cid > 0 ) { $contact_ids[] = $cid; }
			if ( $aid > 0 ) { $address_ids[] = $aid; }
		}
		// 1.6.1 — external Cal.com bookings: external_contact_id is a
		// direct FK to handik_contacts, no intermediate request row to
		// JOIN through. Just add to the contact bulk fetch.
		foreach ( $external_contact_ids as $ecid ) {
			$contact_ids[] = (int) $ecid;
		}
		$contacts  = ( $this->contacts  && method_exists( $this->contacts,  'get_many' ) ) ? $this->contacts->get_many( $contact_ids )   : array();
		$addresses = ( $this->addresses && method_exists( $this->addresses, 'get_many' ) ) ? $this->addresses->get_many( $address_ids ) : array();

		$out = array();
		foreach ( $rows as $row ) {
			$bid  = (int) $row['id'];
			$rid  = (int) ( $row['job_request_id']      ?? 0 );
			$drid = (int) ( $row['direct_request_id']   ?? 0 );
			$pwid = (int) ( $row['project_work_day_id'] ?? 0 );
			$ecid = (int) ( $row['external_contact_id'] ?? 0 );
			if ( $rid && isset( $requests[ $rid ] ) ) {
				$req = $requests[ $rid ];
				$cid = (int) ( $req['contact_id'] ?? 0 );
				$aid = (int) ( $req['address_id'] ?? 0 );
				$out[ $bid ] = array(
					'request'          => $req,
					'direct_request'   => null,
					'project_day'      => null,
					'project_schedule' => null,
					'contact'          => $cid && isset( $contacts[ $cid ] )  ? $contacts[ $cid ]  : null,
					'address'          => $aid && isset( $addresses[ $aid ] ) ? $addresses[ $aid ] : null,
				);
			} elseif ( $drid && isset( $direct_rows[ $drid ] ) ) {
				$dr  = $direct_rows[ $drid ];
				$cid = (int) ( $dr['contact_id'] ?? 0 );
				$aid = (int) ( $dr['address_id'] ?? 0 );
				$out[ $bid ] = array(
					'request'          => null,
					'direct_request'   => $dr,
					'project_day'      => null,
					'project_schedule' => null,
					'contact'          => $cid && isset( $contacts[ $cid ] )  ? $contacts[ $cid ]  : null,
					'address'          => $aid && isset( $addresses[ $aid ] ) ? $addresses[ $aid ] : null,
				);
			} elseif ( $pwid && isset( $project_day_rows[ $pwid ] ) ) {
				$pday  = $project_day_rows[ $pwid ];
				$psid  = (int) ( $pday['scheduling_request_id'] ?? 0 );
				$psch  = $psid && isset( $project_schedule_rows[ $psid ] ) ? $project_schedule_rows[ $psid ] : null;
				$cid   = $psch ? (int) ( $psch['contact_id'] ?? 0 ) : 0;
				$aid   = $psch ? (int) ( $psch['address_id'] ?? 0 ) : 0;
				$out[ $bid ] = array(
					'request'          => null,
					'direct_request'   => null,
					'project_day'      => $pday,
					'project_schedule' => $psch,
					'contact'          => $cid && isset( $contacts[ $cid ] )  ? $contacts[ $cid ]  : null,
					'address'          => $aid && isset( $addresses[ $aid ] ) ? $addresses[ $aid ] : null,
				);
			} elseif ( $ecid && isset( $contacts[ $ecid ] ) ) {
				// 1.6.1 — external Cal booking matched to an existing
				// contact via Bookings_Service::upsert_external_booking's
				// email/phone lookup.
				$out[ $bid ] = array(
					'request'          => null,
					'direct_request'   => null,
					'project_day'      => null,
					'project_schedule' => null,
					'contact'          => $contacts[ $ecid ],
					'address'          => null,
				);
			} else {
				// 1.6.1 — external Cal booking with no matched contact.
				// The render code falls back to raw_webhook_json to
				// pull the attendee name + email out of the payload.
				$out[ $bid ] = array(
					'request'          => null,
					'direct_request'   => null,
					'project_day'      => null,
					'project_schedule' => null,
					'contact'          => null,
					'address'          => null,
				);
			}
		}
		return $out;
	}

	/**
	 * Sprint 7 (admin perf): the optional `$decorations` map is the
	 * bulk-loaded `decorate_bookings()` output, keyed by booking id. When
	 * passed, we skip three per-row `get()` lookups (request/contact/address);
	 * when omitted we fall back to the per-row path so older callers keep
	 * working.
	 *
	 * @param array<string, mixed>                                                            $row         Booking row.
	 * @param bool                                                                            $is_past     Past-bookings styling.
	 * @param array<int, array{request: ?array, contact: ?array, address: ?array}>|null      $decorations Bulk decorations keyed by booking id.
	 */
	protected function booking_card( array $row, $is_past = false, ?array $decorations = null ) {
		global $wpdb;
		$detail_url = Handik_Booking_App_Admin_Helpers::admin_url_for(
			'handik-booking-app-bookings',
			array_merge(
				array( 'booking_id' => (int) $row['id'] ),
				$this->detail_back_params()
			)
		);
		if ( null !== $decorations && isset( $decorations[ (int) $row['id'] ] ) ) {
			$bundle           = $decorations[ (int) $row['id'] ];
			$request          = $bundle['request'];
			$direct           = $bundle['direct_request']   ?? null;
			$project_day      = $bundle['project_day']      ?? null;
			$project_schedule = $bundle['project_schedule'] ?? null;
			$contact          = $bundle['contact'];
			$address          = $bundle['address'];
		} else {
			$request          = ! empty( $row['job_request_id'] ) && $this->job_requests ? $this->job_requests->get( (int) $row['job_request_id'] ) : null;
			$direct           = null;
			$project_day      = null;
			$project_schedule = null;
			global $wpdb;
			if ( ! $request && ! empty( $row['direct_request_id'] ) ) {
				$direct = $wpdb->get_row( $wpdb->prepare(
					'SELECT * FROM ' . Handik_Booking_App_DB::table( 'direct_booking_requests' ) . ' WHERE id = %d LIMIT 1',
					(int) $row['direct_request_id']
				), ARRAY_A );
			}
			// 1.6.0 — third source: project work day rows hang off
			// `project_work_day_id`. Two lookups (day → schedule) to
			// reach the contact/address that the schedule carries.
			if ( ! $request && ! $direct && ! empty( $row['project_work_day_id'] ) ) {
				$project_day = $wpdb->get_row( $wpdb->prepare(
					'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_work_days' ) . ' WHERE id = %d LIMIT 1',
					(int) $row['project_work_day_id']
				), ARRAY_A );
				if ( $project_day && ! empty( $project_day['scheduling_request_id'] ) ) {
					$project_schedule = $wpdb->get_row( $wpdb->prepare(
						'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_scheduling_requests' ) . ' WHERE id = %d LIMIT 1',
						(int) $project_day['scheduling_request_id']
					), ARRAY_A );
				}
			}
			$contact_id = $request
				? (int) ( $request['contact_id'] ?? 0 )
				: ( $direct
					? (int) ( $direct['contact_id'] ?? 0 )
					: ( $project_schedule
						? (int) ( $project_schedule['contact_id'] ?? 0 )
						// 1.6.1 — fourth source: external Cal.com booking, FK directly on the booking row.
						: (int) ( $row['external_contact_id'] ?? 0 ) ) );
			$address_id = $request
				? (int) ( $request['address_id'] ?? 0 )
				: ( $direct
					? (int) ( $direct['address_id'] ?? 0 )
					: ( $project_schedule ? (int) ( $project_schedule['address_id'] ?? 0 ) : 0 ) );
			$contact = ( $contact_id && $this->contacts ) ? $this->contacts->get( $contact_id ) : null;
			$address = ( $address_id && $this->addresses ) ? $this->addresses->get( $address_id ) : null;
		}

		// 1.6.1 — external Cal booking with no matched contact: extract
		// attendee name from the stored raw_webhook_json so the card
		// shows SOMETHING useful instead of "Unknown".
		$external_attendee_name  = '';
		$external_attendee_email = '';
		$is_external_booking     = ! $request && ! $direct && ! $project_day && empty( $row['external_contact_id'] );
		if ( $is_external_booking && ! empty( $row['raw_webhook_json'] ) ) {
			$decoded = json_decode( (string) $row['raw_webhook_json'], true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['attendees'] ) && is_array( $decoded['attendees'] ) && ! empty( $decoded['attendees'][0] ) ) {
					$first_att              = $decoded['attendees'][0];
					$external_attendee_name = (string) ( $first_att['name']  ?? '' );
					$external_attendee_email = (string) ( $first_att['email'] ?? '' );
				}
				if ( '' === $external_attendee_name && ! empty( $decoded['responses']['name']['value'] ) ) {
					$external_attendee_name = (string) $decoded['responses']['name']['value'];
				}
				if ( '' === $external_attendee_email && ! empty( $decoded['responses']['email']['value'] ) ) {
					$external_attendee_email = (string) $decoded['responses']['email']['value'];
				}
			}
		}

		if ( $contact ) {
			$client = (string) ( $contact['full_name'] ?? '' );
		} elseif ( '' !== $external_attendee_name ) {
			$client = $external_attendee_name;
		} elseif ( '' !== $external_attendee_email ) {
			$client = $external_attendee_email;
		} else {
			$client = __( 'Unknown', 'handik-booking-app' );
		}
		// Sprint 13.5 — task summary: main-SPA bookings have a list of
		// catalog task ids; direct bookings only have the preset's
		// form_title (single label). Fall back to whichever applies.
		// 1.6.0 — project work-day rows use the schedule's form_title
		// + day index ("Form title — Day 2 of 5") so the admin list
		// can tell which day of the project each row represents.
		if ( $request ) {
			$task_summary = Handik_Booking_App_Admin_Helpers::task_summary_text(
				is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(),
				$this->catalog
			);
		} elseif ( $direct ) {
			$task_summary = (string) ( $direct['form_title'] ?? __( 'Direct booking', 'handik-booking-app' ) );
		} elseif ( $project_schedule || $project_day ) {
			$title = (string) ( $project_schedule['form_title'] ?? __( 'Project work day', 'handik-booking-app' ) );
			$day_n = $project_day ? ( (int) ( $project_day['day_index'] ?? 0 ) + 1 ) : 0;
			$total = (int) ( $project_schedule['required_days'] ?? 0 );
			if ( $day_n > 0 && $total > 0 ) {
				/* translators: 1: form title, 2: day number, 3: total days */
				$task_summary = sprintf( __( '%1$s — Day %2$d of %3$d', 'handik-booking-app' ), $title, $day_n, $total );
			} else {
				$task_summary = $title;
			}
		} elseif ( $is_external_booking || ! empty( $row['external_contact_id'] ) ) {
			// 1.6.1 — external Cal booking. Tag prominently so admin
			// knows the booking didn't come through the plugin form
			// (and may need a manual follow-up to collect address /
			// scope details that the form would normally capture).
			$slug = (string) ( $row['event_type_slug'] ?? '' );
			$task_summary = $slug
				/* translators: %s: Cal.com event type slug */
				? sprintf( __( 'External Cal.com booking — %s', 'handik-booking-app' ), $slug )
				: __( 'External Cal.com booking', 'handik-booking-app' );
		} else {
			$task_summary = '';
		}
		$city = Handik_Booking_App_Admin_Helpers::request_city( $request, $address );
		$status = $this->bookings ? $this->bookings->effective_status( $row ) : (string) ( $row['status'] ?? '' );
		$when_text = Handik_Booking_App_Admin_Helpers::format_booking_window( $row, 'card' );

		$cls = 'handik-admin-booking-card';
		if ( $is_past ) {
			$cls .= ' is-past';
		}
		if ( $direct ) {
			$cls .= ' is-direct';
		}

		ob_start();
		?>
		<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $detail_url ); ?>">
			<div class="handik-admin-booking-card__when"><?php echo esc_html( $when_text ); ?></div>
			<div class="handik-admin-booking-card__client"><strong><?php echo esc_html( $client ); ?></strong></div>
			<div class="handik-admin-booking-card__what">
				<?php echo esc_html( $task_summary ); ?>
				<?php if ( $city ) : ?> <span class="handik-admin-muted">· <?php echo esc_html( $city ); ?></span><?php endif; ?>
			</div>
			<div class="handik-admin-booking-card__status">
				<?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $status ); ?>
			</div>
		</a>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, array<string, mixed>>                                                $upcoming    Upcoming rows.
	 * @param array<int, array<string, mixed>>                                                $past        Past rows.
	 * @param array<int, array{request: ?array, contact: ?array, address: ?array}>|null      $decorations Bulk decorations keyed by booking id (Sprint 7 perf).
	 */
	protected function bookings_table_markup( array $upcoming, array $past, ?array $decorations = null ) {
		global $wpdb;
		$rows = array_merge( $upcoming, $past );

		ob_start();
		?>
		<div class="handik-admin-table-wrap" data-handik-bookings-table>
			<table class="widefat striped handik-admin-bookings-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'handik-booking-app' ); ?></th>
						<th><?php esc_html_e( 'Client', 'handik-booking-app' ); ?></th>
						<th><?php esc_html_e( 'Task', 'handik-booking-app' ); ?></th>
						<th><?php esc_html_e( 'City', 'handik-booking-app' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'handik-booking-app' ); ?></th>
						<th><?php esc_html_e( 'Status', 'handik-booking-app' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$past_divider_inserted = false;
					$back_params = $this->detail_back_params();
					foreach ( $rows as $row ) :
						$detail_url = Handik_Booking_App_Admin_Helpers::admin_url_for(
							'handik-booking-app-bookings',
							array_merge( array( 'booking_id' => (int) $row['id'] ), $back_params )
						);
						$direct = null;
						if ( null !== $decorations && isset( $decorations[ (int) $row['id'] ] ) ) {
							$bundle           = $decorations[ (int) $row['id'] ];
							$request          = $bundle['request'];
							$direct           = $bundle['direct_request']   ?? null;
							$project_schedule = $bundle['project_schedule'] ?? null;
							$contact          = $bundle['contact'];
							$address          = $bundle['address'];
						} else {
							$request          = ! empty( $row['job_request_id'] ) && $this->job_requests ? $this->job_requests->get( (int) $row['job_request_id'] ) : null;
							$project_schedule = null;
							if ( ! $request && ! empty( $row['direct_request_id'] ) ) {
								$direct = $wpdb->get_row( $wpdb->prepare(
									'SELECT * FROM ' . Handik_Booking_App_DB::table( 'direct_booking_requests' ) . ' WHERE id = %d LIMIT 1',
									(int) $row['direct_request_id']
								), ARRAY_A );
							}
							// 1.6.0 — project work-day branch.
							if ( ! $request && ! $direct && ! empty( $row['project_work_day_id'] ) ) {
								$pday = $wpdb->get_row( $wpdb->prepare(
									'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_work_days' ) . ' WHERE id = %d LIMIT 1',
									(int) $row['project_work_day_id']
								), ARRAY_A );
								if ( $pday && ! empty( $pday['scheduling_request_id'] ) ) {
									$project_schedule = $wpdb->get_row( $wpdb->prepare(
										'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_scheduling_requests' ) . ' WHERE id = %d LIMIT 1',
										(int) $pday['scheduling_request_id']
									), ARRAY_A );
								}
							}
							$cid = $request
								? (int) ( $request['contact_id'] ?? 0 )
								: ( $direct
									? (int) ( $direct['contact_id'] ?? 0 )
									: ( $project_schedule
										? (int) ( $project_schedule['contact_id'] ?? 0 )
										// 1.6.1 — fourth source: external Cal.com booking.
										: (int) ( $row['external_contact_id'] ?? 0 ) ) );
							$aid = $request
								? (int) ( $request['address_id'] ?? 0 )
								: ( $direct
									? (int) ( $direct['address_id'] ?? 0 )
									: ( $project_schedule ? (int) ( $project_schedule['address_id'] ?? 0 ) : 0 ) );
							$contact = ( $cid && $this->contacts ) ? $this->contacts->get( $cid ) : null;
							$address = ( $aid && $this->addresses ) ? $this->addresses->get( $aid ) : null;
						}
						$is_past = empty( $row['start_time'] ) || $row['start_time'] < gmdate( 'Y-m-d H:i:s' );
						if ( $is_past && ! $past_divider_inserted && ! empty( $past ) ) {
							echo '<tr class="handik-admin-table-divider"><td colspan="6">' . esc_html__( 'Past bookings', 'handik-booking-app' ) . '</td></tr>';
							$past_divider_inserted = true;
						}
						$status = $this->bookings ? $this->bookings->effective_status( $row ) : (string) ( $row['status'] ?? '' );
						// 1.6.1 — extract external attendee from raw_webhook_json
						// when this row has no linked source (4th-source case).
						$external_name  = '';
						$external_email = '';
						if ( ! $request && ! $direct && ! $project_schedule && empty( $row['external_contact_id'] ) && ! empty( $row['raw_webhook_json'] ) ) {
							$decoded_w = json_decode( (string) $row['raw_webhook_json'], true );
							if ( is_array( $decoded_w ) ) {
								if ( isset( $decoded_w['attendees'][0] ) && is_array( $decoded_w['attendees'][0] ) ) {
									$external_name  = (string) ( $decoded_w['attendees'][0]['name']  ?? '' );
									$external_email = (string) ( $decoded_w['attendees'][0]['email'] ?? '' );
								}
								if ( '' === $external_name && ! empty( $decoded_w['responses']['name']['value'] ) ) {
									$external_name = (string) $decoded_w['responses']['name']['value'];
								}
							}
						}
						// Sprint 13.5 — Direct rows show preset title; main rows show task summary.
						if ( $request ) {
							$tasks = Handik_Booking_App_Admin_Helpers::task_summary_text( is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(), $this->catalog );
						} elseif ( $direct ) {
							$tasks = (string) ( $direct['form_title'] ?? __( 'Direct booking', 'handik-booking-app' ) );
						} elseif ( $project_schedule ) {
							$tasks = (string) ( $project_schedule['form_title'] ?? __( 'Project work day', 'handik-booking-app' ) );
						} elseif ( $external_name || ! empty( $row['external_contact_id'] ) || ! empty( $row['cal_booking_id'] ) ) {
							$slug = (string) ( $row['event_type_slug'] ?? '' );
							$tasks = $slug
								? sprintf( __( 'External — %s', 'handik-booking-app' ), $slug )
								: __( 'External Cal.com booking', 'handik-booking-app' );
						} else {
							$tasks = '';
						}
						// Override the Client cell for external bookings without a contact match.
						$client_label = $contact
							? (string) ( $contact['full_name'] ?? '' )
							: ( '' !== $external_name ? $external_name : ( '' !== $external_email ? $external_email : __( 'Unknown', 'handik-booking-app' ) ) );
					?>
					<tr class="handik-admin-row-link" tabindex="0" data-href="<?php echo esc_url( $detail_url ); ?>">
						<td><?php echo esc_html( Handik_Booking_App_Admin_Helpers::format_booking_window( $row, 'compact' ) ); ?></td>
						<td><?php echo esc_html( $client_label ); ?></td>
						<td><?php echo esc_html( $tasks ); ?></td>
						<td><?php echo esc_html( Handik_Booking_App_Admin_Helpers::request_city( $request, $address ) ); ?></td>
						<td><?php echo esc_html( ! empty( $row['duration_minutes'] ) ? $row['duration_minutes'] . ' min' : '—' ); ?></td>
						<td><?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $status ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// =====================================================================
	// DETAIL (B2 + B4)
	// =====================================================================

	// =====================================================================
	// NEW BOOKING (Sprint 13) — admin-initiated direct booking
	// =====================================================================

	/**
	 * Render the "Add booking" page. Two flows in one form:
	 *   - Existing customer: pre-filled contact picker (autocomplete via
	 *     /admin/contact/search) + saved-address dropdown + preset
	 *     picker + Cal.com inline embed.
	 *   - New walk-in: explicit name / phone / address inputs (toggled
	 *     by the "+ New customer" button).
	 *
	 * Submit calls /admin/booking/new which forwards to
	 * Direct_Booking_Service::admin_submit; the response includes a
	 * ready-to-mount cal_booking_url that the JS swaps into the embed
	 * container. The Cal-side bookingSuccessful event POSTs to the
	 * existing /forms/direct/{id}/capture so the local row flips to
	 * BOOKED, and the cal-webhook will keep doing its dispatch as if
	 * a real customer had used the public form.
	 *
	 * @param int $contact_id Optional pre-selected contact (e.g. when
	 *                        the operator clicks "Book a visit" on the
	 *                        Person detail page).
	 */
	protected function render_new_booking( $contact_id = 0 ) {
		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'Add booking', 'handik-booking-app' ),
			__( 'Book on behalf of a customer using the same Cal.com flow as the public direct-booking form.', 'handik-booking-app' )
		);

		$presets = array();
		if ( $this->booking_presets && method_exists( $this->booking_presets, 'enabled' ) ) {
			foreach ( $this->booking_presets->enabled() as $preset ) {
				if ( ( $preset['form_type'] ?? '' ) === 'direct_cal_booking' ) {
					$presets[] = $preset;
				}
			}
		}

		$prefilled = $contact_id > 0 && $this->contacts ? $this->contacts->get( $contact_id ) : null;
		$prefilled_addresses = array();
		if ( $prefilled && $this->addresses && method_exists( $this->addresses, 'list_for_contact' ) ) {
			$prefilled_addresses = $this->addresses->list_for_contact( $contact_id );
		}

		$back_url     = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings' );
		$rest_base    = trailingslashit( rest_url( 'handik-booking-app/v1/' ) );
		$rest_nonce   = wp_create_nonce( 'wp_rest' );
		?>
		<p><a class="handik-admin-muted" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to bookings', 'handik-booking-app' ); ?></a></p>

		<?php if ( empty( $presets ) ) : ?>
			<div class="notice notice-warning"><p>
				<?php esc_html_e( 'No enabled direct-booking presets found. Create one in App Setup → Additional Forms before booking from the admin.', 'handik-booking-app' ); ?>
			</p></div>
			<?php Handik_Booking_App_Admin_Helpers::page_end(); ?>
			<?php return; ?>
		<?php endif; ?>

		<section class="handik-admin-block handik-admin-new-booking"
			data-handik-new-booking
			data-handik-rest-base="<?php echo esc_attr( $rest_base ); ?>"
			data-handik-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
			data-handik-redirect-base="<?php echo esc_attr( Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings' ) ); ?>"
		>
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Step 1 — Customer & address', 'handik-booking-app' ); ?></h2>

			<div class="handik-admin-new-booking__customer-mode">
				<button type="button" class="button" data-handik-mode="existing" aria-pressed="true"><?php esc_html_e( 'Existing customer', 'handik-booking-app' ); ?></button>
				<button type="button" class="button" data-handik-mode="new" aria-pressed="false"><?php esc_html_e( '+ New customer', 'handik-booking-app' ); ?></button>
			</div>

			<div class="handik-admin-new-booking__existing" data-handik-pane="existing"<?php echo $prefilled ? '' : ''; ?>>
				<label class="handik-admin-field">
					<span><?php esc_html_e( 'Search by name or phone', 'handik-booking-app' ); ?></span>
					<input type="search"
						data-handik-contact-search
						placeholder="<?php esc_attr_e( 'e.g. Smith or 617…', 'handik-booking-app' ); ?>"
						value="<?php echo esc_attr( $prefilled ? (string) ( $prefilled['full_name'] ?? '' ) : '' ); ?>"
						autocomplete="off" />
				</label>
				<ul class="handik-admin-new-booking__results" data-handik-results hidden></ul>
				<div class="handik-admin-new-booking__chosen" data-handik-chosen<?php echo $prefilled ? '' : ' hidden'; ?>>
					<?php if ( $prefilled ) : ?>
						<input type="hidden" data-handik-contact-id value="<?php echo esc_attr( (string) (int) $prefilled['id'] ); ?>" />
						<p>
							<strong data-handik-chosen-name><?php echo esc_html( (string) $prefilled['full_name'] ); ?></strong>
							<span class="handik-admin-muted" data-handik-chosen-phone>· <?php echo esc_html( (string) $prefilled['phone'] ); ?></span>
						</p>
					<?php else : ?>
						<input type="hidden" data-handik-contact-id value="" />
						<p><strong data-handik-chosen-name></strong> <span class="handik-admin-muted" data-handik-chosen-phone></span></p>
					<?php endif; ?>
					<label class="handik-admin-field">
						<span><?php esc_html_e( 'Address', 'handik-booking-app' ); ?></span>
						<select data-handik-address-picker>
							<option value=""><?php esc_html_e( '— Pick an address —', 'handik-booking-app' ); ?></option>
							<?php foreach ( $prefilled_addresses as $addr ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $addr['id'] ); ?>" data-full="<?php echo esc_attr( (string) ( $addr['address_full'] ?? '' ) ); ?>" data-unit="<?php echo esc_attr( (string) ( $addr['address_unit'] ?? '' ) ); ?>">
									<?php echo esc_html( trim( ( (string) ( $addr['address_full'] ?? '' ) ) . ' ' . ( ! empty( $addr['address_unit'] ) ? '· ' . (string) $addr['address_unit'] : '' ) ) ); ?>
								</option>
							<?php endforeach; ?>
							<option value="__new"><?php esc_html_e( '+ New address', 'handik-booking-app' ); ?></option>
						</select>
					</label>
					<div data-handik-new-address hidden>
						<label class="handik-admin-field"><span><?php esc_html_e( 'Address', 'handik-booking-app' ); ?></span><input type="text" data-handik-address-full autocomplete="street-address" /></label>
						<label class="handik-admin-field"><span><?php esc_html_e( 'Unit (optional)', 'handik-booking-app' ); ?></span><input type="text" data-handik-address-unit autocomplete="address-line2" /></label>
					</div>
				</div>
			</div>

			<div class="handik-admin-new-booking__new" data-handik-pane="new" hidden>
				<div class="handik-admin-grid">
					<label class="handik-admin-field"><span><?php esc_html_e( 'Full name', 'handik-booking-app' ); ?>*</span><input type="text" data-handik-new-name autocomplete="name" /></label>
					<?php /* Sprint 13 hotfix (F10): require ≥10 digits before
				 * submission. minlength on type=tel covers the raw
				 * keystroke count; the server still re-validates after
				 * stripping non-digits. */ ?>
				<label class="handik-admin-field"><span><?php esc_html_e( 'Phone', 'handik-booking-app' ); ?>*</span><input type="tel" data-handik-new-phone autocomplete="tel" inputmode="tel" minlength="10" pattern=".*\d{10,}.*" title="<?php esc_attr_e( '10-digit phone, e.g. +1 617 555 0123', 'handik-booking-app' ); ?>" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Email (optional)', 'handik-booking-app' ); ?></span><input type="email" data-handik-new-email autocomplete="email" /></label>
				</div>
				<label class="handik-admin-field"><span><?php esc_html_e( 'Address', 'handik-booking-app' ); ?>*</span><input type="text" data-handik-new-address-full autocomplete="street-address" /></label>
				<label class="handik-admin-field"><span><?php esc_html_e( 'Unit (optional)', 'handik-booking-app' ); ?></span><input type="text" data-handik-new-address-unit autocomplete="address-line2" /></label>
			</div>

			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Step 2 — Pick a booking type', 'handik-booking-app' ); ?></h2>
			<label class="handik-admin-field">
				<span><?php esc_html_e( 'Preset', 'handik-booking-app' ); ?></span>
				<select data-handik-preset-picker>
					<option value=""><?php esc_html_e( '— Select preset —', 'handik-booking-app' ); ?></option>
					<?php foreach ( $presets as $preset ) : ?>
						<option value="<?php echo esc_attr( (string) $preset['preset_slug'] ); ?>">
							<?php echo esc_html( (string) $preset['form_title'] ); ?>
							<?php echo esc_html( ' · ' . (int) $preset['duration_minutes'] . ' min' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<p>
				<button type="button" class="button button-primary" data-handik-new-booking-submit disabled>
					<?php esc_html_e( 'Open Cal.com to pick a slot →', 'handik-booking-app' ); ?>
				</button>
				<span class="handik-admin-muted" data-handik-new-booking-status></span>
			</p>
		</section>

		<section class="handik-admin-block handik-admin-new-booking__cal" data-handik-cal-section hidden>
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Step 3 — Pick a slot', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-muted"><?php esc_html_e( 'Pick a date and time below. The booking is recorded the moment Cal.com confirms it; you can leave the page after the success message appears.', 'handik-booking-app' ); ?></p>
			<div class="handik-admin-new-booking__cal-frame" data-handik-cal-frame></div>
		</section>
		<?php

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function render_detail( $booking_id ) {
		global $wpdb;
		$booking = $this->bookings ? $this->bookings->get( $booking_id ) : null;
		if ( ! $booking ) {
			Handik_Booking_App_Admin_Helpers::page_start( __( 'Booking', 'handik-booking-app' ) );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Booking not found.', 'handik-booking-app' ) . '</p></div>';
			Handik_Booking_App_Admin_Helpers::page_end();
			return;
		}

		// Sprint 13.5 — direct bookings have no job_request. Resolve
		// contact + address from the linked direct_booking_requests row
		// instead, so the detail page renders something useful.
		// 1.6.0 — same shape for project work-day bookings (third
		// source): day_id → schedule_id → contact/address.
		$request          = ! empty( $booking['job_request_id'] ) && $this->job_requests ? $this->job_requests->get( (int) $booking['job_request_id'] ) : null;
		$direct           = null;
		$project_day      = null;
		$project_schedule = null;
		if ( ! $request && ! empty( $booking['direct_request_id'] ) ) {
			$direct = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . Handik_Booking_App_DB::table( 'direct_booking_requests' ) . ' WHERE id = %d LIMIT 1',
				(int) $booking['direct_request_id']
			), ARRAY_A );
		}
		if ( ! $request && ! $direct && ! empty( $booking['project_work_day_id'] ) ) {
			$project_day = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_work_days' ) . ' WHERE id = %d LIMIT 1',
				(int) $booking['project_work_day_id']
			), ARRAY_A );
			if ( $project_day && ! empty( $project_day['scheduling_request_id'] ) ) {
				$project_schedule = $wpdb->get_row( $wpdb->prepare(
					'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_scheduling_requests' ) . ' WHERE id = %d LIMIT 1',
					(int) $project_day['scheduling_request_id']
				), ARRAY_A );
			}
		}
		$contact_id = $request
			? (int) ( $request['contact_id'] ?? 0 )
			: ( $direct
				? (int) ( $direct['contact_id'] ?? 0 )
				: ( $project_schedule
					? (int) ( $project_schedule['contact_id'] ?? 0 )
					// 1.6.1 — fourth source: external Cal.com booking with linked contact.
					: (int) ( $booking['external_contact_id'] ?? 0 ) ) );
		$address_id = $request
			? (int) ( $request['address_id'] ?? 0 )
			: ( $direct
				? (int) ( $direct['address_id'] ?? 0 )
				: ( $project_schedule ? (int) ( $project_schedule['address_id'] ?? 0 ) : 0 ) );
		$contact = ( $contact_id && $this->contacts ) ? $this->contacts->get( $contact_id ) : null;
		$address = ( $address_id && $this->addresses ) ? $this->addresses->get( $address_id ) : null;
		// 1.6.1 — external Cal booking without a linked contact: pull
		// attendee from the stashed webhook payload so the sticky
		// action bar / detail page show something useful in the
		// "Client" slot. External bookings never carry photos.
		if ( ! $request && ! $direct && ! $project_schedule && ! $contact && ! empty( $booking['raw_webhook_json'] ) ) {
			$decoded_w = json_decode( (string) $booking['raw_webhook_json'], true );
			if ( is_array( $decoded_w ) ) {
				$external_name  = '';
				$external_email = '';
				$external_phone = '';
				if ( isset( $decoded_w['attendees'][0] ) && is_array( $decoded_w['attendees'][0] ) ) {
					$external_name  = (string) ( $decoded_w['attendees'][0]['name']  ?? '' );
					$external_email = (string) ( $decoded_w['attendees'][0]['email'] ?? '' );
				}
				if ( '' === $external_name && ! empty( $decoded_w['responses']['name']['value'] ) ) {
					$external_name = (string) $decoded_w['responses']['name']['value'];
				}
				if ( '' === $external_email && ! empty( $decoded_w['responses']['email']['value'] ) ) {
					$external_email = (string) $decoded_w['responses']['email']['value'];
				}
				if ( $external_name || $external_email ) {
					$contact = array(
						'id'         => 0,
						'full_name'  => $external_name ?: $external_email,
						'email'      => $external_email,
						'phone'      => $external_phone,
						'_external'  => true, // marker so downstream renderers know this isn't a real contact row
					);
				}
			}
		}
		$photos  = is_array( $request['photos'] ?? null ) ? $request['photos'] : array(); // direct/project/external bookings have no photos
		$full_address = Handik_Booking_App_Admin_Helpers::full_request_address( $request, $address );

		echo '<div class="wrap handik-admin-wrap handik-admin-booking-detail">';
		echo $this->sticky_action_bar_markup( $booking, $contact, $full_address );
		echo $this->actions_bar_markup( $booking );
		echo $this->at_a_glance_markup( $booking, $contact, $full_address, $request );
		// Photos / transcript / summary / tasks blocks all expect a
		// job_request — for direct rows render a simpler "preset"
		// block instead so the page isn't broken.
		if ( $request ) {
			echo $this->photos_block_markup( $photos );
			echo $this->transcript_block_markup( $request, $booking );
			echo $this->summary_estimate_block_markup( $request );
			echo $this->tasks_block_markup( $request );
		} elseif ( $direct ) {
			echo $this->direct_preset_block_markup( $direct );
		} elseif ( $project_schedule || ! empty( $booking['project_work_day_id'] ) ) {
			echo $this->project_preset_block_markup( $project_schedule, $project_day );
		} elseif ( empty( $booking['job_request_id'] ) && empty( $booking['direct_request_id'] ) && empty( $booking['project_work_day_id'] ) ) {
			// 1.6.1 — external Cal booking has no source row at all.
			echo $this->external_preset_block_markup( $booking );
		}
		echo $this->address_block_markup( $full_address );
		echo $this->technical_block_markup( $request, $booking );
		if ( $request ) {
			echo $this->chat_logs_block_markup( $request, $booking );
		}
		echo $this->danger_zone_markup( $booking );
		echo '</div>';
	}

	/**
	 * Sprint 13.5 — minimal "what was booked" block for direct-form
	 * rows (no assistant, no photos, no chat). Renders the preset
	 * title + booking_type + duration so the operator sees what
	 * service the customer signed up for.
	 *
	 * @param array<string, mixed> $direct Row from handik_direct_booking_requests.
	 * @return string
	 */
	protected function direct_preset_block_markup( array $direct ) {
		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Service', 'handik-booking-app' ); ?></h2>
			<?php
			echo Handik_Booking_App_Admin_Helpers::detail_list_markup( array(
				__( 'Form preset', 'handik-booking-app' )       => (string) ( $direct['form_title'] ?? '' ),
				__( 'Booking type', 'handik-booking-app' )      => (string) ( $direct['booking_type'] ?? '' ),
				__( 'Duration', 'handik-booking-app' )          => ! empty( $direct['duration_minutes'] ) ? ( (int) $direct['duration_minutes'] . ' ' . __( 'min', 'handik-booking-app' ) ) : '',
				__( 'Source', 'handik-booking-app' )            => 'admin:bookings' === ( $direct['source_url'] ?? '' )
					? __( 'Admin booking (added on behalf of customer)', 'handik-booking-app' )
					: __( 'Public direct booking form', 'handik-booking-app' ),
			) );
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * 1.6.0 — minimal "what was booked" block for project work-day
	 * rows (no chat, no photos). Renders the schedule form title +
	 * Day N of M + duration so the operator sees which day of which
	 * project this row represents.
	 *
	 * @param array<string, mixed>|null $schedule project_scheduling_requests row.
	 * @param array<string, mixed>|null $day      project_work_days row.
	 * @return string
	 */
	protected function project_preset_block_markup( $schedule, $day ) {
		$title = $schedule ? (string) ( $schedule['form_title'] ?? '' ) : '';
		$day_n = $day ? ( (int) ( $day['day_index'] ?? 0 ) + 1 ) : 0;
		$total = $schedule ? (int) ( $schedule['required_days'] ?? 0 ) : 0;
		$duration_min = $schedule ? (int) ( $schedule['work_day_duration_minutes'] ?? 0 ) : 0;

		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Project work day', 'handik-booking-app' ); ?></h2>
			<?php
			echo Handik_Booking_App_Admin_Helpers::detail_list_markup( array(
				__( 'Project form', 'handik-booking-app' ) => $title,
				__( 'Day', 'handik-booking-app' )          => $day_n > 0 && $total > 0
					/* translators: 1: day number, 2: total days */
					? sprintf( __( 'Day %1$d of %2$d', 'handik-booking-app' ), $day_n, $total )
					: '',
				__( 'Per-day duration', 'handik-booking-app' ) => $duration_min > 0
					? ( $duration_min . ' ' . __( 'min', 'handik-booking-app' ) )
					: '',
				__( 'Source', 'handik-booking-app' )         => __( 'Public project work days form', 'handik-booking-app' ),
			) );
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * 1.6.1 — minimal block for "external" Cal.com bookings — ones
	 * that arrived via webhook without any handik metadata (customer
	 * abandoned our form, used the "Open the booking page directly"
	 * link, booked on Cal.com directly). Pulls the attendee name +
	 * email + Cal event type slug straight out of `raw_webhook_json`
	 * so the operator sees who booked + which event type + can
	 * follow up manually to collect the address that Cal's stock
	 * embed didn't capture.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return string
	 */
	protected function external_preset_block_markup( array $booking ) {
		$decoded = ! empty( $booking['raw_webhook_json'] )
			? json_decode( (string) $booking['raw_webhook_json'], true )
			: array();
		$decoded = is_array( $decoded ) ? $decoded : array();

		$attendee_name  = '';
		$attendee_email = '';
		$attendee_phone = '';
		if ( isset( $decoded['attendees'][0] ) && is_array( $decoded['attendees'][0] ) ) {
			$attendee_name  = (string) ( $decoded['attendees'][0]['name']  ?? '' );
			$attendee_email = (string) ( $decoded['attendees'][0]['email'] ?? '' );
		}
		if ( '' === $attendee_name && ! empty( $decoded['responses']['name']['value'] ) ) {
			$attendee_name = (string) $decoded['responses']['name']['value'];
		}
		if ( '' === $attendee_email && ! empty( $decoded['responses']['email']['value'] ) ) {
			$attendee_email = (string) $decoded['responses']['email']['value'];
		}
		foreach ( array( 'phone', 'attendeePhone', 'attendeePhoneNumber', 'smsReminderNumber' ) as $k ) {
			if ( '' === $attendee_phone && ! empty( $decoded[ $k ] ) && is_string( $decoded[ $k ] ) ) {
				$attendee_phone = (string) $decoded[ $k ];
			}
		}
		if ( '' === $attendee_phone && ! empty( $decoded['responses']['phone']['value'] ) ) {
			$attendee_phone = (string) $decoded['responses']['phone']['value'];
		}

		$event_type_slug = (string) ( $booking['event_type_slug'] ?? '' );
		$linked_label    = ! empty( $booking['external_contact_id'] )
			? __( 'Yes — linked via webhook email/phone match', 'handik-booking-app' )
			: __( 'No — customer is not in People & Requests yet', 'handik-booking-app' );

		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'External Cal.com booking', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-muted">
				<?php esc_html_e( 'This booking was made directly on Cal.com (not through the plugin\'s form). Address and job details below may be missing — follow up with the customer to complete them.', 'handik-booking-app' ); ?>
			</p>
			<?php
			echo Handik_Booking_App_Admin_Helpers::detail_list_markup( array(
				__( 'Attendee name', 'handik-booking-app' )    => $attendee_name,
				__( 'Attendee email', 'handik-booking-app' )   => $attendee_email,
				__( 'Attendee phone', 'handik-booking-app' )   => $attendee_phone,
				__( 'Cal event type', 'handik-booking-app' )   => $event_type_slug,
				__( 'Duration', 'handik-booking-app' )         => ! empty( $booking['duration_minutes'] )
					? ( (int) $booking['duration_minutes'] . ' ' . __( 'min', 'handik-booking-app' ) )
					: '',
				__( 'Linked contact', 'handik-booking-app' )   => $linked_label,
			) );
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sprint 12 — danger zone with hard-delete button. Visible only to
	 * users holding the new MANAGE_DELETE cap, so a manage_bookings-only
	 * editor doesn't see it. The button carries `data-handik-delete=...`
	 * with the entity + id; the JS handler (initDangerZone in
	 * booking-app-admin.js) opens a typed-confirm modal that requires
	 * the operator to type "DELETE" verbatim before the REST DELETE
	 * fires. On success it redirects back to the bookings list.
	 */
	protected function danger_zone_markup( array $booking ) {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_DELETE ) ) {
			return '';
		}
		$id = (int) ( $booking['id'] ?? 0 );
		ob_start();
		?>
		<section class="handik-admin-block handik-admin-danger-zone" aria-label="<?php esc_attr_e( 'Danger zone', 'handik-booking-app' ); ?>">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Danger zone', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-muted"><?php esc_html_e( 'Permanently remove this booking row from the local database. The Cal.com booking on the contractor calendar is NOT cancelled — only the local mirror is wiped.', 'handik-booking-app' ); ?></p>
			<button type="button"
				class="button button-link-delete"
				data-handik-delete="booking"
				data-handik-id="<?php echo esc_attr( (string) $id ); ?>"
				data-handik-redirect="<?php echo esc_attr( Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings' ) ); ?>"
			>🗑 <?php esc_html_e( 'Delete this booking…', 'handik-booking-app' ); ?></button>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function sticky_action_bar_markup( array $booking, $contact, $full_address ) {
		// Sprint 10 fix: preserve filter + paged + search params when the
		// owner taps Back. Was P1 — the back arrow always landed on the
		// unfiltered, un-paged list, forcing the owner to re-apply
		// "this week" / "completed" / page 3 every time they peeked at
		// a single row's detail.
		$back_args = array_filter( array(
			'filter_time'   => isset( $_GET['from_filter_time'] ) ? sanitize_key( wp_unslash( $_GET['from_filter_time'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'filter_status' => isset( $_GET['from_filter_status'] ) ? sanitize_key( wp_unslash( $_GET['from_filter_status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'q'             => isset( $_GET['from_q'] ) ? sanitize_text_field( wp_unslash( $_GET['from_q'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged'         => isset( $_GET['from_paged'] ) ? absint( wp_unslash( $_GET['from_paged'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		), static function ( $v ) { return '' !== $v && 0 !== $v && 'all' !== $v; } );
		$back_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', $back_args );
		$dt = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $booking['start_time'] ?? '' ) );
		$when_short = $dt ? $dt->format( 'D, M j · g:i A' ) : __( 'Not scheduled', 'handik-booking-app' );

		$phone = is_array( $contact ) ? trim( (string) ( $contact['phone'] ?? '' ) ) : '';
		$tel   = $phone ? Handik_Booking_App_Admin_Helpers::tel_url( $phone ) : '';
		$apple = $full_address ? Handik_Booking_App_Admin_Helpers::apple_maps_url( $full_address ) : '';

		$cal_url = '';
		if ( ! empty( $booking['cal_booking_id'] ) ) {
			$cal_url = 'https://app.cal.com/bookings/upcoming?bookingUid=' . rawurlencode( (string) $booking['cal_booking_id'] );
		}

		ob_start();
		?>
		<div class="handik-admin-sticky-bar" data-handik-sticky>
			<a class="handik-admin-sticky-bar__back" href="<?php echo esc_url( $back_url ); ?>" aria-label="<?php esc_attr_e( 'Back to bookings', 'handik-booking-app' ); ?>">←</a>
			<span class="handik-admin-sticky-bar__title"><?php echo esc_html( $when_short ); ?></span>
			<div class="handik-admin-sticky-bar__actions">
				<?php if ( $tel ) : ?>
					<a class="handik-admin-sticky-bar__cta is-call" href="<?php echo esc_url( $tel ); ?>">📞 <span><?php echo esc_html( $phone ); ?></span></a>
				<?php endif; ?>
				<?php if ( $apple ) : ?>
					<a class="handik-admin-sticky-bar__cta" href="<?php echo esc_url( $apple ); ?>" target="_blank" rel="noopener noreferrer">🗺️ <?php esc_html_e( 'Apple Maps', 'handik-booking-app' ); ?></a>
				<?php endif; ?>
				<?php if ( $cal_url ) : ?>
					<a class="handik-admin-sticky-bar__cta" href="<?php echo esc_url( $cal_url ); ?>" target="_blank" rel="noopener noreferrer">📅 Cal.com</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	protected function actions_bar_markup( array $booking ) {
		$rest = trailingslashit( rest_url( 'handik-booking-app/v1' ) );
		$booking_id = (int) $booking['id'];
		$current_status = $this->bookings ? $this->bookings->effective_status( $booking ) : '';
		$has_admin_status_override = ! empty( $booking['admin_status_override'] );

		ob_start();
		?>
		<div class="handik-admin-actions-bar"
			data-handik-booking-actions
			data-booking-id="<?php echo esc_attr( (string) $booking_id ); ?>"
			data-rest-base="<?php echo esc_attr( esc_url_raw( $rest ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<button type="button" class="button" data-handik-action="add-note">📝 <?php esc_html_e( 'Add note', 'handik-booking-app' ); ?></button>
			<button type="button" class="button" data-handik-action="mark-completed">✅ <?php esc_html_e( 'Mark as completed', 'handik-booking-app' ); ?></button>
			<button type="button" class="button" data-handik-action="mark-cancelled">⛔ <?php esc_html_e( 'Mark as cancelled', 'handik-booking-app' ); ?></button>
			<?php if ( $has_admin_status_override ) : ?>
				<button type="button" class="button-link" data-handik-action="clear-override"><?php esc_html_e( 'Clear manual status', 'handik-booking-app' ); ?></button>
			<?php endif; ?>
			<span class="handik-admin-current-status"><?php esc_html_e( 'Current status:', 'handik-booking-app' ); ?> <?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $current_status ); ?></span>
		</div>
		<?php if ( ! empty( $booking['admin_notes'] ) ) : ?>
			<div class="handik-admin-callout">
				<strong><?php esc_html_e( 'Private note', 'handik-booking-app' ); ?></strong>
				<p data-handik-admin-notes-display><?php echo nl2br( esc_html( (string) $booking['admin_notes'] ) ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	protected function at_a_glance_markup( array $booking, $contact, $full_address, $request ) {
		$dt = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $booking['start_time'] ?? '' ) );
		$dt_end = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $booking['end_time'] ?? '' ) );
		$when = $dt ? $dt->format( 'l, F j, Y' ) : '';
		$time_range = $dt && $dt_end ? $dt->format( 'g:i A' ) . ' – ' . $dt_end->format( 'g:i A' ) . ' ET' : ( $dt ? $dt->format( 'g:i A' ) . ' ET' : '' );
		$duration = ! empty( $booking['duration_minutes'] ) ? sprintf( '%d min', (int) $booking['duration_minutes'] ) : '';

		$client_name  = is_array( $contact ) ? (string) ( $contact['full_name'] ?? __( 'Unknown', 'handik-booking-app' ) ) : __( 'Unknown', 'handik-booking-app' );
		$client_phone = is_array( $contact ) ? (string) ( $contact['phone'] ?? '' ) : '';
		$client_email = is_array( $contact ) ? (string) ( $contact['email'] ?? '' ) : '';

		$task_count = $request ? count( is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array() ) : 0;
		$task_summary = $request ? Handik_Booking_App_Admin_Helpers::task_summary_text(
			is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(),
			$this->catalog
		) : '';

		$estimate_text = '';
		if ( $request && ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ) {
			$state = $request['app_state'];
			$estimate_text = Handik_Booking_App_Admin_Helpers::format_money_range(
				$state['total_estimate_low'] ?? 0,
				$state['total_estimate_high'] ?? 0
			);
			if ( $estimate_text ) {
				$estimate_text = '~' . $estimate_text . ' ' . __( 'estimate', 'handik-booking-app' );
			}
		}

		$apple = $full_address ? Handik_Booking_App_Admin_Helpers::apple_maps_url( $full_address ) : '';
		$google = $full_address ? Handik_Booking_App_Admin_Helpers::google_maps_url( $full_address ) : '';

		ob_start();
		?>
		<section class="handik-admin-glance">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'At a glance', 'handik-booking-app' ); ?></h2>
			<div class="handik-admin-glance__grid">
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'When', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( $when ); ?></strong>
					<span><?php echo esc_html( $time_range ); ?> <?php if ( $duration ) : ?>(<?php echo esc_html( $duration ); ?>)<?php endif; ?></span>
				</div>
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Client', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( $client_name ); ?></strong>
					<?php if ( $client_phone ) : ?>
						<span><a href="<?php echo esc_url( Handik_Booking_App_Admin_Helpers::tel_url( $client_phone ) ); ?>"><?php echo esc_html( $client_phone ); ?></a> <button type="button" class="handik-admin-copy-btn" data-handik-copy="<?php echo esc_attr( $client_phone ); ?>" aria-label="<?php esc_attr_e( 'Copy phone', 'handik-booking-app' ); ?>">⧉</button></span>
					<?php endif; ?>
					<?php if ( $client_email ) : ?>
						<span><a href="<?php echo esc_url( Handik_Booking_App_Admin_Helpers::mailto_url( $client_email ) ); ?>"><?php echo esc_html( $client_email ); ?></a> <button type="button" class="handik-admin-copy-btn" data-handik-copy="<?php echo esc_attr( $client_email ); ?>" aria-label="<?php esc_attr_e( 'Copy email', 'handik-booking-app' ); ?>">⧉</button></span>
					<?php endif; ?>
				</div>
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Where', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( $full_address ?: __( 'No address', 'handik-booking-app' ) ); ?></strong>
					<?php if ( $apple || $google ) : ?>
					<span class="handik-admin-glance__links">
						<?php if ( $apple ) : ?><a href="<?php echo esc_url( $apple ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Apple Maps', 'handik-booking-app' ); ?></a><?php endif; ?>
						<?php if ( $google ) : ?> · <a href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Maps', 'handik-booking-app' ); ?></a><?php endif; ?>
					</span>
					<?php endif; ?>
				</div>
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Job', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( $task_summary ?: __( 'No tasks selected', 'handik-booking-app' ) ); ?></strong>
					<span>
						<?php echo esc_html( Handik_Booking_App_Admin_Helpers::task_count_label( is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array() ) ); ?>
						<?php if ( $estimate_text ) : ?>· <?php echo esc_html( $estimate_text ); ?><?php endif; ?>
					</span>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function photos_block_markup( array $photos ) {
		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Photos', 'handik-booking-app' ); ?> <span class="handik-admin-muted"><?php echo esc_html( Handik_Booking_App_Admin_Helpers::photo_count_label( $photos ) ); ?></span></h2>
			<?php echo Handik_Booking_App_Admin_Helpers::photos_gallery_markup( $photos ); ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function transcript_block_markup( $request, $booking ) {
		$request_id = $request ? (int) $request['id'] : 0;
		$messages   = ( $this->messages && $request_id ) ? $this->messages->list_for_request( $request_id, 200 ) : array();
		$booking_id = $booking ? (int) ( $booking['id'] ?? 0 ) : 0;
		$thread_id  = $request ? (string) ( $request['chat_thread_id'] ?? '' ) : '';
		// 2.1.23.1 — A5: structured fallback when the local transcript
		// is empty AND we have a ChatKit thread id, plus a button that
		// hits /admin/booking/{id}/fetch-chat to backfill from OpenAI.
		// The fallback shows the structured booking metadata we DO
		// have (assistant_summary, selected_tasks, short_description)
		// so the panel is never just a dead empty-state.
		$has_fetch_target = ( $booking_id > 0 && '' !== $thread_id );

		ob_start();
		?>
		<section class="handik-admin-block" data-handik-transcript-block>
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'What the customer wrote', 'handik-booking-app' ); ?></h2>
			<?php if ( empty( $messages ) ) : ?>
				<div class="handik-admin-transcript-fallback">
					<?php
					$short_description = $request ? trim( (string) ( $request['short_description'] ?? '' ) ) : '';
					$assistant_summary = $request ? trim( (string) ( $request['assistant_summary'] ?? '' ) ) : '';
					$selected_tasks    = $request && is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array();
					$tasks_summary     = ! empty( $selected_tasks ) && $this->catalog
						? Handik_Booking_App_Admin_Helpers::task_summary_text( $selected_tasks, $this->catalog )
						: '';
					if ( '' !== $short_description ) :
						?>
						<p class="handik-admin-muted"><strong><?php esc_html_e( 'Initial job description (typed by the customer):', 'handik-booking-app' ); ?></strong></p>
						<p><?php echo esc_html( $short_description ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $tasks_summary ) : ?>
						<p class="handik-admin-muted"><strong><?php esc_html_e( 'Tasks the customer selected:', 'handik-booking-app' ); ?></strong></p>
						<p><?php echo esc_html( $tasks_summary ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $assistant_summary ) : ?>
						<p class="handik-admin-muted"><strong><?php esc_html_e( 'AI assistant\'s summary of the conversation:', 'handik-booking-app' ); ?></strong></p>
						<p><?php echo nl2br( esc_html( $assistant_summary ) ); ?></p>
					<?php endif; ?>
					<p class="handik-admin-muted">
						<?php
						if ( $request_id && $request_id < $this->first_request_with_messages_id() ) {
							esc_html_e( 'No chat transcript stored locally for this older booking.', 'handik-booking-app' );
						} else {
							esc_html_e( 'Chat transcript was not captured locally. Fetch it from OpenAI to populate this panel.', 'handik-booking-app' );
						}
						?>
					</p>
					<?php if ( $has_fetch_target ) : ?>
						<button
							type="button"
							class="button button-secondary"
							data-handik-fetch-chat
							data-booking-id="<?php echo esc_attr( (string) $booking_id ); ?>"
						>
							<?php esc_html_e( 'Load chat from OpenAI', 'handik-booking-app' ); ?>
						</button>
						<span class="handik-admin-fetch-chat-status" data-handik-fetch-chat-status aria-live="polite"></span>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="handik-admin-transcript">
					<?php foreach ( $messages as $msg ) : ?>
						<?php echo $this->transcript_bubble( $msg ); ?>
					<?php endforeach; ?>
				</div>
				<?php if ( $has_fetch_target ) : ?>
					<p>
						<button
							type="button"
							class="button button-link"
							data-handik-fetch-chat
							data-booking-id="<?php echo esc_attr( (string) $booking_id ); ?>"
						>
							<?php esc_html_e( 'Refresh from OpenAI', 'handik-booking-app' ); ?>
						</button>
						<span class="handik-admin-fetch-chat-status" data-handik-fetch-chat-status aria-live="polite"></span>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function transcript_bubble( array $msg ) {
		$role = (string) ( $msg['role'] ?? 'user' );
		$cls  = 'handik-admin-bubble handik-admin-bubble--' . sanitize_html_class( $role );
		$dt   = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $msg['created_at'] ?? '' ) );
		$time = $dt ? $dt->format( 'g:i A' ) : '';

		ob_start();
		?>
		<div class="<?php echo esc_attr( $cls ); ?>">
			<div class="handik-admin-bubble__body"><?php echo nl2br( esc_html( (string) ( $msg['content'] ?? '' ) ) ); ?></div>
			<div class="handik-admin-bubble__time"><?php echo esc_html( $time ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	protected function first_request_with_messages_id() {
		// We can't know exactly without an extra query — assume the migration
		// runs at upgrade, so any request older than the option flip predates
		// the feature.
		$ts = (int) get_option( 'handik_admin_messages_ready_at', 0 );
		if ( ! $ts ) {
			update_option( 'handik_admin_messages_ready_at', time(), false );
			return PHP_INT_MAX; // Until we know — show "older booking" message conservatively.
		}
		return PHP_INT_MAX;
	}

	protected function summary_estimate_block_markup( $request ) {
		if ( ! $request ) {
			return '';
		}
		$state = is_array( $request['app_state'] ?? null ) ? $request['app_state'] : array();
		$summary = (string) ( $request['assistant_summary'] ?? '' );

		$labor_low  = (float) ( $state['labor_estimate_low'] ?? 0 );
		$labor_high = (float) ( $state['labor_estimate_high'] ?? 0 );
		$mat_low    = (float) ( $state['materials_estimate_low'] ?? 0 );
		$mat_high   = (float) ( $state['materials_estimate_high'] ?? 0 );
		$total_low  = (float) ( $state['total_estimate_low'] ?? 0 );
		$total_high = (float) ( $state['total_estimate_high'] ?? 0 );
		$rate       = (float) ( $state['applied_hourly_rate'] ?? 0 );
		$posture    = (string) ( $state['pricing_posture'] ?? '' );
		$mat_notes  = (string) ( $state['materials_notes'] ?? '' );
		$disclaimer = (string) ( $state['estimate_disclaimer'] ?? '' );

		$has_summary = '' !== trim( $summary );
		$has_estimate = $rate > 0 || $total_high > 0 || $labor_high > 0;

		if ( ! $has_summary && ! $has_estimate ) {
			return '';
		}

		ob_start();
		?>
		<section class="handik-admin-block handik-admin-block--summary-estimate">
			<div class="handik-admin-twocol">
				<?php if ( $has_summary ) : ?>
				<div>
					<h3><?php esc_html_e( 'Assistant summary', 'handik-booking-app' ); ?></h3>
					<div class="handik-admin-prose"><?php echo nl2br( esc_html( $summary ) ); ?></div>
				</div>
				<?php endif; ?>
				<?php if ( $has_estimate ) : ?>
				<div>
					<h3><?php esc_html_e( 'Estimate', 'handik-booking-app' ); ?></h3>
					<dl class="handik-admin-estimate">
						<?php if ( $rate > 0 ) : ?>
							<div><dt><?php esc_html_e( 'Hourly rate', 'handik-booking-app' ); ?></dt><dd>$<?php echo esc_html( number_format_i18n( $rate ) ); ?>/hr</dd></div>
						<?php endif; ?>
						<?php if ( $labor_high > 0 ) : ?>
							<div><dt><?php esc_html_e( 'Labor', 'handik-booking-app' ); ?></dt><dd><?php echo esc_html( Handik_Booking_App_Admin_Helpers::format_money_range( $labor_low, $labor_high ) ); ?></dd></div>
						<?php endif; ?>
						<?php if ( 'hourly_only' === $posture ) : ?>
							<div><dt><?php esc_html_e( 'Materials', 'handik-booking-app' ); ?></dt><dd><?php esc_html_e( 'no materials', 'handik-booking-app' ); ?></dd></div>
						<?php elseif ( $mat_high > 0 ) : ?>
							<div><dt><?php esc_html_e( 'Materials', 'handik-booking-app' ); ?></dt><dd><?php echo esc_html( Handik_Booking_App_Admin_Helpers::format_money_range( $mat_low, $mat_high ) ); ?></dd></div>
						<?php endif; ?>
						<?php if ( $total_high > 0 ) : ?>
							<div><dt><?php esc_html_e( 'Total', 'handik-booking-app' ); ?></dt><dd><strong><?php echo esc_html( Handik_Booking_App_Admin_Helpers::format_money_range( $total_low, $total_high ) ); ?></strong></dd></div>
						<?php endif; ?>
					</dl>
					<?php if ( $mat_notes ) : ?><p class="handik-admin-muted"><?php echo esc_html( $mat_notes ); ?></p><?php endif; ?>
					<?php if ( $disclaimer ) : ?><p class="handik-admin-disclaimer"><?php echo esc_html( $disclaimer ); ?></p><?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function tasks_block_markup( $request ) {
		if ( ! $request ) {
			return '';
		}
		$task_ids = is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array();
		$mismatch = ! empty( $request['app_state']['selected_task_mismatch'] );

		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Selected tasks', 'handik-booking-app' ); ?> <span class="handik-admin-muted">(<?php echo (int) count( $task_ids ); ?>)</span></h2>
			<?php if ( $mismatch ) : ?>
				<div class="handik-admin-warning">⚠ <?php esc_html_e( 'Customer selected something the assistant routed differently. See the assistant notes above.', 'handik-booking-app' ); ?></div>
			<?php endif; ?>
			<?php echo Handik_Booking_App_Admin_Helpers::task_summary_with_rates_html( $task_ids, $this->catalog ); ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function address_block_markup( $full_address ) {
		if ( ! $full_address ) {
			return '';
		}
		$apple = Handik_Booking_App_Admin_Helpers::apple_maps_url( $full_address );
		$google = Handik_Booking_App_Admin_Helpers::google_maps_url( $full_address );
		$embed = Handik_Booking_App_Admin_Helpers::map_embed_url( $full_address );

		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Address', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-address"><?php echo esc_html( $full_address ); ?></p>
			<p class="handik-admin-address-links">
				<?php if ( $apple ) : ?><a class="button button-secondary" href="<?php echo esc_url( $apple ); ?>" target="_blank" rel="noopener noreferrer">📍 <?php esc_html_e( 'Apple Maps', 'handik-booking-app' ); ?></a><?php endif; ?>
				<?php if ( $google ) : ?><a class="button" href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener noreferrer">🗺️ <?php esc_html_e( 'Google Maps', 'handik-booking-app' ); ?></a><?php endif; ?>
			</p>
			<?php if ( $embed ) : ?>
				<div class="handik-admin-map-wrap"><iframe title="<?php esc_attr_e( 'Job address map', 'handik-booking-app' ); ?>" src="<?php echo esc_url( $embed ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function technical_block_markup( $request, $booking ) {
		ob_start();
		?>
		<section class="handik-admin-block">
			<details class="handik-admin-details">
				<summary><?php esc_html_e( 'Technical details (debug)', 'handik-booking-app' ); ?></summary>
				<div class="handik-admin-details__body">
					<?php
					echo Handik_Booking_App_Admin_Helpers::detail_list_markup( array(
						__( 'Request ID', 'handik-booking-app' )         => $request ? '#' . (int) $request['id'] : '',
						__( 'Booking ID', 'handik-booking-app' )         => '#' . (int) $booking['id'],
						__( 'Cal.com Booking ID', 'handik-booking-app' )  => (string) ( $booking['cal_booking_id'] ?? '' ),
						__( 'Cal Event Slug', 'handik-booking-app' )     => (string) ( $booking['event_type_slug'] ?? '' ),
						__( 'Job shape', 'handik-booking-app' )          => $request ? (string) ( $request['job_shape'] ?? '' ) : '',
						__( 'Project', 'handik-booking-app' )            => $request && ! empty( $request['is_project'] ) ? __( 'Yes', 'handik-booking-app' ) : __( 'No', 'handik-booking-app' ),
						__( 'Service family', 'handik-booking-app' )     => $request ? (string) ( $request['service_family'] ?? '' ) : '',
						__( 'Rate family', 'handik-booking-app' )        => $request ? (string) ( $request['rate_family'] ?? '' ) : '',
						__( 'Duration bucket', 'handik-booking-app' )    => $request ? (string) ( $request['duration_bucket'] ?? '' ) : '',
						__( 'Routing status', 'handik-booking-app' )     => $request ? (string) ( $request['routing_status'] ?? '' ) : '',
						__( 'Booking status (raw)', 'handik-booking-app' ) => (string) ( $booking['status'] ?? '' ),
						__( 'Admin status override', 'handik-booking-app' ) => (string) ( $booking['admin_status_override'] ?? '' ),
						__( 'Duration', 'handik-booking-app' )           => ! empty( $booking['duration_minutes'] ) ? (int) $booking['duration_minutes'] . ' min' : '',
						__( 'Updated', 'handik-booking-app' )            => Handik_Booking_App_Admin_Helpers::format_short( (string) ( $booking['updated_at'] ?? '' ) ),
						__( 'Thread ID', 'handik-booking-app' )          => $request ? (string) ( $request['chat_thread_id'] ?? '' ) : '',
					) );

					if ( $request && ! empty( $request['chat_thread_id'] ) ) {
						$url = 'https://platform.openai.com/logs/' . rawurlencode( (string) $request['chat_thread_id'] );
						echo '<p><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html__( 'Open thread in OpenAI logs', 'handik-booking-app' ) . '</a></p>';
					}

					$assistant_result = $request && is_array( $request['assistant_result'] ?? null ) ? $request['assistant_result'] : array();
					if ( $assistant_result ) {
						echo '<h3>' . esc_html__( 'Latest assistant output', 'handik-booking-app' ) . '</h3>';
						echo '<pre>' . esc_html( wp_json_encode( $assistant_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
					}

					$photo_analysis = $request && ! empty( $request['app_state']['photo_analysis'] ) && is_array( $request['app_state']['photo_analysis'] ) ? $request['app_state']['photo_analysis'] : array();
					if ( $photo_analysis ) {
						echo '<h3>' . esc_html__( 'Photo analysis', 'handik-booking-app' ) . '</h3>';
						echo '<pre>' . esc_html( wp_json_encode( $photo_analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
					}
					?>
				</div>
			</details>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function chat_logs_block_markup( $request, $booking ) {
		// Legacy log-grep view.
		if ( ! $this->logger ) {
			return '';
		}
		$request_id = $request ? (int) $request['id'] : 0;
		$thread_id  = $request ? (string) ( $request['chat_thread_id'] ?? '' ) : '';
		$logs = $this->logger->query( array(
			'request_id' => $request_id,
			'thread_id'  => $thread_id,
		) );
		// Limit to 50 most-recent.
		$logs = array_slice( $logs, -50 );

		ob_start();
		?>
		<section class="handik-admin-block">
			<details class="handik-admin-details">
				<summary><?php esc_html_e( 'Chat activity (debug)', 'handik-booking-app' ); ?></summary>
				<div class="handik-admin-details__body">
					<?php if ( empty( $logs ) ) : ?>
						<p class="handik-admin-muted"><?php esc_html_e( 'No log entries match this request or thread.', 'handik-booking-app' ); ?></p>
					<?php else : ?>
						<ul class="handik-admin-log-list">
							<?php foreach ( $logs as $entry ) : ?>
								<li>
									<span class="handik-admin-pill handik-admin-pill--<?php echo esc_attr( sanitize_html_class( (string) ( $entry['level'] ?? 'info' ) ) ); ?>"><?php echo esc_html( (string) ( $entry['level'] ?? '' ) ); ?></span>
									<time><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></time>
									<strong><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></strong>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</details>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
