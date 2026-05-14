<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operational dashboard (ticket A1).
 *
 * Five blocks:
 *   1. Today / Tomorrow / This week stat strip.
 *   2. Next 5 visits compact list.
 *   3. Action-needed chips (drafts, ready-not-booked, unsafe, errors).
 *   4. "This month at a glance" — count, revenue estimate, avg duration.
 *   5. Latest release notes (collapsed <details>).
 */
class Handik_Booking_App_Admin_Dashboard {

	const TRANSIENT_KEY = 'handik_admin_dashboard_v1';
	const TRANSIENT_TTL = 60;

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
	/** @var Handik_Booking_App_Changelog_Service */
	protected $changelog;

	public function __construct( $bookings, $job_requests, $contacts, $addresses, $catalog, $logger, $changelog ) {
		$this->bookings     = $bookings;
		$this->job_requests = $job_requests;
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->catalog      = $catalog;
		$this->logger       = $logger;
		$this->changelog    = $changelog;
	}

	public function render() {
		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'Dashboard', 'handik-booking-app' ),
			__( "What you've got on your plate.", 'handik-booking-app' )
		);

		$data = $this->load_dashboard_data();

		// Sprint 11 fix: surface the cache-age so an owner who just
		// marked something completed and hopped back to the Dashboard
		// can tell whether the counters reflect their action yet (the
		// 60s transient was opaque). Includes a "Refresh now" link that
		// busts the transient and re-renders.
		$cached_at  = (int) ( $data['cached_at'] ?? 0 );
		$age        = $cached_at > 0 ? max( 0, time() - $cached_at ) : 0;
		$refresh_url = add_query_arg(
			array( 'page' => 'handik-booking-app', 'refresh' => '1' ),
			admin_url( 'admin.php' )
		);
		$age_label = 0 === $age
			? __( 'Refreshed just now', 'handik-booking-app' )
			: ( $age < 60
				? sprintf(
					/* translators: %d: seconds since refresh */
					_n( 'Refreshed %ds ago', 'Refreshed %ds ago', $age, 'handik-booking-app' ),
					$age
				)
				: sprintf(
					/* translators: %d: minutes since refresh */
					__( 'Refreshed %dm ago', 'handik-booking-app' ),
					(int) round( $age / 60 )
				)
			);
		echo '<p class="handik-admin-dashboard__refresh"><span class="handik-admin-muted">' . esc_html( $age_label ) . '</span> · <a href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh now', 'handik-booking-app' ) . '</a></p>';

		echo '<div class="handik-admin-dashboard">';
		echo $this->today_strip_markup( $data );
		echo $this->next_visits_markup( $data['next_visits'] );
		echo $this->action_needed_markup( $data );
		echo $this->month_at_a_glance_markup( $data );
		echo $this->release_notes_markup();
		echo '</div>';

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	// -------- data load ---------------------------------------------------

	protected function load_dashboard_data() {
		// Sprint 11 fix: ?refresh=1 busts the 60s transient so the owner
		// can force fresh numbers from the "Refresh now" link without
		// having to wait out the cache.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['refresh'] ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			// Rehydrate the next-visits list from IDs so we always show fresh contact data.
			return $this->ensure_next_visit_decorations( $cached );
		}

		$tz = new DateTimeZone( Handik_Booking_App_Admin_Helpers::TIMEZONE );
		$utc = new DateTimeZone( 'UTC' );
		$now_et = new DateTimeImmutable( 'now', $tz );

		$today_start_et    = $now_et->setTime( 0, 0, 0 );
		$today_end_et      = $today_start_et->modify( '+1 day' );
		$tomorrow_start_et = $today_end_et;
		$tomorrow_end_et   = $tomorrow_start_et->modify( '+1 day' );

		// Through Sunday inclusive (PHP: 1=Mon..7=Sun). Days remaining including today.
		$dow = (int) $now_et->format( 'N' );
		$days_until_sunday_inclusive = ( 7 - $dow ) + 1; // today + remaining days through Sunday
		$week_end_et = $today_start_et->modify( '+' . $days_until_sunday_inclusive . ' days' );

		$month_start_et = $now_et->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$month_end_et   = $month_start_et->modify( '+1 month' );

		$today_count    = $this->bookings ? $this->bookings->count_in_window( $this->to_utc( $today_start_et ), $this->to_utc( $today_end_et ) ) : 0;
		$tomorrow_count = $this->bookings ? $this->bookings->count_in_window( $this->to_utc( $tomorrow_start_et ), $this->to_utc( $tomorrow_end_et ) ) : 0;
		$week_count     = $this->bookings ? $this->bookings->count_in_window( $this->to_utc( $today_start_et ), $this->to_utc( $week_end_et ) ) : 0;
		$month_count    = $this->bookings ? $this->bookings->count_in_window( $this->to_utc( $month_start_et ), $this->to_utc( $month_end_et ) ) : 0;
		$month_avg_min  = $this->bookings ? $this->bookings->avg_duration_in_window( $this->to_utc( $month_start_et ), $this->to_utc( $month_end_et ) ) : 0.0;
		$month_revenue  = $this->job_requests ? $this->job_requests->sum_estimate_high_for_bookings_in_window( $this->to_utc( $month_start_et ), $this->to_utc( $month_end_et ) ) : 0.0;

		$today_first    = $this->bookings ? $this->bookings->list_in_window( $this->to_utc( $today_start_et ), $this->to_utc( $today_end_et ), 1 ) : array();
		$tomorrow_first = $this->bookings ? $this->bookings->list_in_window( $this->to_utc( $tomorrow_start_et ), $this->to_utc( $tomorrow_end_et ), 1 ) : array();
		// Sprint 11 fix: was starting at $today_start_et, so the "This week"
		// preview line just duplicated whatever today's first row showed.
		// Skip past today + tomorrow so the card surfaces the next NEW
		// piece of info (a Wed/Thu/Fri visit, not "the same 8am job").
		$week_after_tomorrow = $tomorrow_end_et;
		$week_first     = $this->bookings ? $this->bookings->list_in_window( $this->to_utc( $week_after_tomorrow ), $this->to_utc( $week_end_et ), 1 ) : array();

		$next_visits = $this->bookings ? $this->bookings->list_upcoming( 5 ) : array();

		$counts = array(
			'drafts_24h'        => $this->job_requests ? $this->job_requests->count_drafts_older_than( 24 ) : 0,
			'ready_not_booked'  => $this->job_requests ? $this->job_requests->count_ready_not_booked( 7 ) : 0,
			'unsafe_7d'         => $this->job_requests ? $this->job_requests->count_unsafe_in_last_days( 7 ) : 0,
			'errors_24h'        => $this->logger ? $this->logger->count_recent_errors( 24 ) : 0,
		);

		$data = array(
			'today_count'        => $today_count,
			'tomorrow_count'     => $tomorrow_count,
			'week_count'         => $week_count,
			'today_preview_id'   => $today_first    && ! empty( $today_first[0]['id'] ) ? (int) $today_first[0]['id'] : 0,
			'tomorrow_preview_id'=> $tomorrow_first && ! empty( $tomorrow_first[0]['id'] ) ? (int) $tomorrow_first[0]['id'] : 0,
			'week_preview_id'    => $week_first     && ! empty( $week_first[0]['id'] ) ? (int) $week_first[0]['id'] : 0,
			'next_visit_ids'     => array_map(
				static function( $row ) { return (int) ( $row['id'] ?? 0 ); },
				$next_visits
			),
			'counts'             => $counts,
			'month_count'        => $month_count,
			'month_revenue'      => (float) $month_revenue,
			'month_avg_minutes'  => (float) $month_avg_min,
			// Sprint 11 fix: stamp when the dashboard data was actually
			// computed so the page can render "Refreshed Xs ago" and
			// the owner knows whether they're looking at fresh-from-DB
			// numbers or a 60s-cache snapshot. Stored as a UTC unix ts.
			'cached_at'          => time(),
		);

		set_transient( self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL );
		return $this->ensure_next_visit_decorations( $data );
	}

	protected function ensure_next_visit_decorations( array $data ) {
		// Sprint 7 (admin perf): bulk-load all the booking rows the dashboard
		// needs (next-visits + today/tomorrow/week previews) in a single
		// `bookings->get_many()` call. Was 8 single-row `get()` lookups in
		// the worst case (5 next-visits + 3 previews) — every dashboard
		// hit, on a 60-second cache miss.
		$wanted_ids = array();
		foreach ( ( $data['next_visit_ids'] ?? array() ) as $booking_id ) {
			$bid = (int) $booking_id;
			if ( $bid > 0 ) { $wanted_ids[] = $bid; }
		}
		foreach ( array( 'today', 'tomorrow', 'week' ) as $key ) {
			$bid = (int) ( $data[ $key . '_preview_id' ] ?? 0 );
			if ( $bid > 0 ) { $wanted_ids[] = $bid; }
		}

		$bulk = ( $this->bookings && method_exists( $this->bookings, 'get_many' ) )
			? $this->bookings->get_many( $wanted_ids )
			: array();
		$resolve = function ( $id ) use ( $bulk ) {
			$id = (int) $id;
			if ( ! $id ) { return null; }
			if ( isset( $bulk[ $id ] ) ) { return $bulk[ $id ]; }
			// Fallback for environments where get_many isn't available.
			return $this->bookings ? $this->bookings->get( $id ) : null;
		};

		$decorated = array();
		foreach ( ( $data['next_visit_ids'] ?? array() ) as $booking_id ) {
			$row = $resolve( $booking_id );
			if ( $row ) {
				$decorated[] = $row;
			}
		}
		$data['next_visits'] = $decorated;

		foreach ( array( 'today', 'tomorrow', 'week' ) as $key ) {
			$row = $resolve( (int) ( $data[ $key . '_preview_id' ] ?? 0 ) );
			$data[ $key . '_preview' ] = $row ? $this->preview_for_booking( $row ) : '';
		}
		return $data;
	}

	protected function preview_for_booking( array $booking ) {
		$dt = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $booking['start_time'] ?? '' ) );
		$time = $dt ? $dt->format( 'g:i A' ) : '';
		$request = ! empty( $booking['job_request_id'] ) && $this->job_requests ? $this->job_requests->get( (int) $booking['job_request_id'] ) : null;
		$contact = ( $request && ! empty( $request['contact_id'] ) && $this->contacts ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
		$name = $contact ? $this->short_name( (string) ( $contact['full_name'] ?? '' ) ) : __( 'Unknown', 'handik-booking-app' );
		$tasks_label = $request ? Handik_Booking_App_Admin_Helpers::task_summary_text(
			is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(),
			$this->catalog,
			1
		) : '';
		$pieces = array_filter( array( $time, $name, $tasks_label ) );
		return implode( ' · ', $pieces );
	}

	protected function short_name( $full_name ) {
		$full = trim( (string) $full_name );
		if ( '' === $full ) {
			return __( 'Unknown', 'handik-booking-app' );
		}
		$parts = preg_split( '/\s+/', $full );
		if ( count( $parts ) < 2 ) {
			return $parts[0];
		}
		return $parts[0] . ' ' . mb_substr( $parts[1], 0, 1 ) . '.';
	}

	protected function to_utc( DateTimeImmutable $et_datetime ) {
		return $et_datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	// -------- block 1: today strip ----------------------------------------

	protected function today_strip_markup( array $data ) {
		$bookings_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', array( 'filter_time' => 'today' ) );
		$tomorrow_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', array( 'filter_time' => 'tomorrow' ) );
		$week_url     = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', array( 'filter_time' => 'this_week' ) );

		ob_start();
		?>
		<section class="handik-admin-dashboard__section handik-admin-dashboard__section--today">
			<div class="handik-admin-dashboard__cards">
				<?php echo $this->today_card( __( 'Today', 'handik-booking-app' ), $data['today_count'], $data['today_preview'] ?? '', __( 'No visits today.', 'handik-booking-app' ), $bookings_url ); ?>
				<?php echo $this->today_card( __( 'Tomorrow', 'handik-booking-app' ), $data['tomorrow_count'], $data['tomorrow_preview'] ?? '', __( 'Nothing booked for tomorrow.', 'handik-booking-app' ), $tomorrow_url ); ?>
				<?php echo $this->today_card( __( 'This week', 'handik-booking-app' ), $data['week_count'], $data['week_preview'] ?? '', __( 'No visits scheduled this week.', 'handik-booking-app' ), $week_url ); ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function today_card( $label, $count, $preview, $empty_text, $href ) {
		$count = (int) $count;
		$cls   = 'handik-admin-today-card';
		if ( ! $count ) {
			$cls .= ' is-empty';
		}
		$inner = '<div class="' . esc_attr( $cls ) . '">';
		$inner .= '<span class="handik-admin-today-card__label">' . esc_html( $label ) . '</span>';
		$inner .= '<strong class="handik-admin-today-card__count">' . esc_html( (string) $count ) . '</strong>';
		if ( $count && $preview ) {
			$inner .= '<span class="handik-admin-today-card__preview">' . esc_html( $preview ) . '</span>';
		} else {
			$inner .= '<span class="handik-admin-today-card__preview is-empty">' . esc_html( $empty_text ) . '</span>';
		}
		$inner .= '</div>';
		// Sprint 11 fix: empty stat cards now also link to the filtered
		// list. Was P1 — tapping "0 today" felt like a dead chip; now
		// it opens Bookings filtered to today (which surfaces the empty
		// state with explicit messaging instead).
		return '<a class="handik-admin-today-card-link" href="' . esc_url( $href ) . '">' . $inner . '</a>';
	}

	// -------- block 2: next 5 visits --------------------------------------

	protected function next_visits_markup( array $rows ) {
		// Sprint 7 (admin perf): bulk-load decorations once for the 5-row
		// visit list. Each row used to fan out 3 single-row `get()` calls
		// (request/contact/address) → 15 queries per dashboard cache miss.
		$decorations = $this->decorate_next_visits( $rows );

		ob_start();
		?>
		<section class="handik-admin-dashboard__section">
			<header class="handik-admin-dashboard__section-header">
				<h2><?php esc_html_e( 'Next 5 visits', 'handik-booking-app' ); ?></h2>
			</header>
			<?php if ( empty( $rows ) ) : ?>
				<div class="handik-admin-empty">
					<p><?php esc_html_e( 'No upcoming visits — share your booking link to fill the week.', 'handik-booking-app' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="handik-admin-visit-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php echo $this->next_visit_row( $row, $decorations ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sprint 7 (admin perf): bulk-load request/contact/address records keyed
	 * by booking id. Mirrors `class-admin-bookings.php::decorate_bookings()`.
	 *
	 * @param array<int, array<string, mixed>> $rows Booking rows.
	 * @return array<int, array{request: ?array, contact: ?array, address: ?array}>
	 */
	protected function decorate_next_visits( array $rows ) {
		if ( empty( $rows ) ) {
			return array();
		}
		$request_ids = array();
		foreach ( $rows as $row ) {
			$rid = (int) ( $row['job_request_id'] ?? 0 );
			if ( $rid > 0 ) { $request_ids[] = $rid; }
		}
		$requests = ( $this->job_requests && method_exists( $this->job_requests, 'get_many' ) )
			? $this->job_requests->get_many( $request_ids )
			: array();
		$contact_ids = array();
		$address_ids = array();
		foreach ( $requests as $request ) {
			$cid = (int) ( $request['contact_id'] ?? 0 );
			$aid = (int) ( $request['address_id'] ?? 0 );
			if ( $cid > 0 ) { $contact_ids[] = $cid; }
			if ( $aid > 0 ) { $address_ids[] = $aid; }
		}
		$contacts  = ( $this->contacts  && method_exists( $this->contacts,  'get_many' ) ) ? $this->contacts->get_many( $contact_ids )  : array();
		$addresses = ( $this->addresses && method_exists( $this->addresses, 'get_many' ) ) ? $this->addresses->get_many( $address_ids ) : array();

		$out = array();
		foreach ( $rows as $row ) {
			$bid = (int) ( $row['id'] ?? 0 );
			$rid = (int) ( $row['job_request_id'] ?? 0 );
			$req = $rid && isset( $requests[ $rid ] ) ? $requests[ $rid ] : null;
			$cid = $req ? (int) ( $req['contact_id'] ?? 0 ) : 0;
			$aid = $req ? (int) ( $req['address_id'] ?? 0 ) : 0;
			$out[ $bid ] = array(
				'request' => $req,
				'contact' => $cid && isset( $contacts[ $cid ] )  ? $contacts[ $cid ]   : null,
				'address' => $aid && isset( $addresses[ $aid ] ) ? $addresses[ $aid ] : null,
			);
		}
		return $out;
	}

	protected function next_visit_row( array $booking, ?array $decorations = null ) {
		$detail_url = Handik_Booking_App_Admin_Helpers::admin_url_for(
			'handik-booking-app-bookings',
			array( 'booking_id' => (int) ( $booking['id'] ?? 0 ) )
		);
		if ( null !== $decorations && isset( $decorations[ (int) ( $booking['id'] ?? 0 ) ] ) ) {
			$bundle  = $decorations[ (int) $booking['id'] ];
			$request = $bundle['request'];
			$contact = $bundle['contact'];
			$address = $bundle['address'];
		} else {
			$request = ! empty( $booking['job_request_id'] ) && $this->job_requests ? $this->job_requests->get( (int) $booking['job_request_id'] ) : null;
			$contact = ( $request && ! empty( $request['contact_id'] ) && $this->contacts ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
			$address = ( $request && ! empty( $request['address_id'] ) && $this->addresses ) ? $this->addresses->get( (int) $request['address_id'] ) : null;
		}

		$when_text  = Handik_Booking_App_Admin_Helpers::format_booking_window( $booking, 'compact' );
		$client     = $contact ? (string) ( $contact['full_name'] ?? '' ) : __( 'Unknown client', 'handik-booking-app' );
		$city       = Handik_Booking_App_Admin_Helpers::request_city( $request, $address );
		$tasks_label = $request ? Handik_Booking_App_Admin_Helpers::task_summary_text(
			is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(),
			$this->catalog
		) : '';

		$status_label = $this->bookings ? $this->bookings->effective_status( $booking ) : (string) ( $booking['status'] ?? '' );

		ob_start();
		?>
		<li class="handik-admin-visit-row">
			<a class="handik-admin-visit-row__link" href="<?php echo esc_url( $detail_url ); ?>">
				<div class="handik-admin-visit-row__when"><?php echo esc_html( $when_text ); ?></div>
				<div class="handik-admin-visit-row__who">
					<strong><?php echo esc_html( $client ); ?></strong>
					<?php if ( $city ) : ?><span class="handik-admin-visit-row__city"><?php echo esc_html( $city ); ?></span><?php endif; ?>
				</div>
				<div class="handik-admin-visit-row__what"><?php echo esc_html( $tasks_label ); ?></div>
				<div class="handik-admin-visit-row__status"><?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $status_label ); ?></div>
				<div class="handik-admin-visit-row__cta" aria-hidden="true">→</div>
			</a>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	// -------- block 3: action needed --------------------------------------

	protected function action_needed_markup( array $data ) {
		$counts = $data['counts'];

		$drafts_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'filter' => 'drafts_old' ) );
		$ready_url  = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'filter' => 'ready_not_booked' ) );
		$unsafe_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'filter' => 'unsafe' ) );
		$errors_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-operations', array( 'tab' => 'logs', 'filter_level' => 'error', 'filter_time' => '24h' ) );

		ob_start();
		?>
		<section class="handik-admin-dashboard__section handik-admin-dashboard__section--actions">
			<header class="handik-admin-dashboard__section-header">
				<h2><?php esc_html_e( 'Action needed', 'handik-booking-app' ); ?></h2>
			</header>
			<div class="handik-admin-chip-row">
				<?php echo Handik_Booking_App_Admin_Helpers::chip_markup( $drafts_url, __( 'drafts', 'handik-booking-app' ), $counts['drafts_24h'], 'warning' ); ?>
				<?php echo Handik_Booking_App_Admin_Helpers::chip_markup( $ready_url, __( 'ready, not booked', 'handik-booking-app' ), $counts['ready_not_booked'], 'warning' ); ?>
				<?php echo Handik_Booking_App_Admin_Helpers::chip_markup( $unsafe_url, __( 'unsafe in 7d', 'handik-booking-app' ), $counts['unsafe_7d'], 'danger' ); ?>
				<?php echo Handik_Booking_App_Admin_Helpers::chip_markup( $errors_url, __( 'errors today', 'handik-booking-app' ), $counts['errors_24h'], 'danger' ); ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// -------- block 4: month at a glance ----------------------------------

	protected function month_at_a_glance_markup( array $data ) {
		$avg_hours = $data['month_avg_minutes'] > 0 ? round( $data['month_avg_minutes'] / 60, 1 ) : 0;
		$revenue_text = $data['month_revenue'] > 0
			? '~$' . number_format_i18n( (int) round( $data['month_revenue'] ) )
			: '—';

		ob_start();
		?>
		<section class="handik-admin-dashboard__section">
			<header class="handik-admin-dashboard__section-header">
				<h2><?php esc_html_e( 'This month at a glance', 'handik-booking-app' ); ?></h2>
			</header>
			<div class="handik-admin-month-stats">
				<div class="handik-admin-month-stat">
					<strong><?php echo esc_html( (string) (int) $data['month_count'] ); ?></strong>
					<span><?php esc_html_e( 'Bookings this month', 'handik-booking-app' ); ?></span>
				</div>
				<div class="handik-admin-month-stat" title="<?php esc_attr_e( 'Sum of the high end of every assistant-provided estimate for this month\'s bookings. Actual revenue is generally lower.', 'handik-booking-app' ); ?>">
					<strong><?php echo esc_html( $revenue_text ); ?></strong>
					<?php /* Sprint 11 fix: explicit "ceiling" qualifier in the
					   stat label so the owner doesn't mis-read this as
					   actual revenue. The hover title carries the full
					   explanation for desktop. */ ?>
					<span><?php esc_html_e( 'Revenue ceiling (high estimate)', 'handik-booking-app' ); ?></span>
				</div>
				<div class="handik-admin-month-stat">
					<strong><?php echo $avg_hours > 0 ? esc_html( number_format_i18n( $avg_hours, 1 ) . ' h' ) : '—'; ?></strong>
					<span><?php esc_html_e( 'Avg job duration', 'handik-booking-app' ); ?></span>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// -------- block 5: release notes (collapsed) --------------------------

	protected function release_notes_markup() {
		if ( ! $this->changelog ) {
			return '';
		}
		$entries = $this->changelog->get_entries();
		if ( empty( $entries ) ) {
			return '';
		}
		$entry = $entries[0];

		ob_start();
		?>
		<section class="handik-admin-dashboard__section">
			<details class="handik-admin-details">
				<summary><?php esc_html_e( 'Latest release notes', 'handik-booking-app' ); ?> · <code><?php echo esc_html( (string) ( $entry['version'] ?? '' ) ); ?></code></summary>
				<div class="handik-admin-details__body">
					<h3><?php echo esc_html( ( $entry['title'] ?? '' ) . ' ' . ( $entry['version'] ?? '' ) ); ?></h3>
					<?php if ( ! empty( $entry['date'] ) ) : ?><p class="handik-admin-muted"><?php echo esc_html( (string) $entry['date'] ); ?></p><?php endif; ?>
					<ul>
						<?php foreach ( ( $entry['notes'] ?? array() ) as $note ) : ?>
							<li><?php echo esc_html( (string) $note ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</details>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
