<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified People view (C1) + Person detail (C1) + Request detail (C2) +
 * Add/Edit person (C3).
 */
class Handik_Booking_App_Admin_People {

	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Service_Catalog_Service */
	protected $catalog;
	/** @var Handik_Booking_App_Messages_Service|null */
	protected $messages;
	/** @var Handik_Booking_App_Logger */
	protected $logger;

	public function __construct( $contacts, $addresses, $job_requests, $bookings, $catalog, $messages, $logger ) {
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->catalog      = $catalog;
		$this->messages     = $messages;
		$this->logger       = $logger;
	}

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$contact_id = isset( $_GET['contact_id'] ) ? absint( wp_unslash( $_GET['contact_id'] ) ) : 0;
		$request_id = isset( $_GET['request_id'] ) ? absint( wp_unslash( $_GET['request_id'] ) ) : 0;
		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable

		if ( 'add' === $action ) {
			$this->render_add_form();
			return;
		}
		if ( $request_id ) {
			$this->render_request_detail( $request_id );
			return;
		}
		if ( $contact_id ) {
			$this->render_person_detail( $contact_id );
			return;
		}
		$this->render_list();
	}

	// =====================================================================
	// PEOPLE LIST (C1)
	// =====================================================================

	protected function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$query  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$show_spam = ! empty( $_GET['show_spam'] );
		// phpcs:enable

		Handik_Booking_App_Admin_Helpers::page_start(
			__( 'People & Requests', 'handik-booking-app' ),
			__( 'One row per customer — addresses, requests and bookings consolidated.', 'handik-booking-app' )
		);

		// Top toolbar: Add person + filter chips + search.
		$add_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'action' => 'add' ) );
		echo '<div class="handik-admin-toolbar">';
		echo '<a class="button button-primary" href="' . esc_url( $add_url ) . '">+ ' . esc_html__( 'Add person', 'handik-booking-app' ) . '</a>';
		echo '</div>';

		// Filter chips.
		echo $this->people_filter_chips( $filter );
		echo $this->people_search_form( $query, $filter, $show_spam );

		// Special filters that override the contact list (drafts_old, ready_not_booked, unsafe).
		if ( in_array( $filter, array( 'drafts_old', 'ready_not_booked', 'unsafe' ), true ) ) {
			echo $this->render_request_focus_list( $filter );
			Handik_Booking_App_Admin_Helpers::page_end();
			return;
		}

		// Standard people list.
		$args = array(
			'include_spam' => $show_spam,
			'search'       => $query,
		);
		switch ( $filter ) {
			case 'with_bookings':
				$args['with_bookings'] = true;
				break;
			case 'drafts_only':
				$args['drafts_only'] = true;
				break;
			case 'no_address':
				$args['no_address'] = true;
				break;
		}

		$rows = $this->contacts ? $this->contacts->list_people( $args ) : array();
		$spam_count = ( $this->contacts && ! $show_spam ) ? $this->contacts->count_spam() : 0;

		if ( empty( $rows ) ) {
			echo '<div class="handik-admin-empty"><p>' . esc_html__( 'No people match these filters.', 'handik-booking-app' ) . '</p></div>';
		} else {
			echo $this->people_list_markup( $rows );
		}

		// Sprint 11 fix: bidirectional spam toggle. Was P3 — once viewing
		// spam (show_spam=1) there was no link to hide them again except
		// editing the URL by hand. Now the link flips based on current
		// state: shows "Show N hidden" when hidden, "Hide spam" when
		// visible.
		if ( $show_spam ) {
			$hide_url = remove_query_arg( 'show_spam', Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'filter' => $filter, 'q' => $query ) ) );
			echo '<p class="handik-admin-muted handik-admin-spam-toggle"><a href="' . esc_url( $hide_url ) . '">' . esc_html__( 'Hide spam contacts', 'handik-booking-app' ) . '</a></p>';
		} elseif ( $spam_count > 0 ) {
			$spam_url = add_query_arg( 'show_spam', 1, Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'filter' => $filter, 'q' => $query ) ) );
			echo '<p class="handik-admin-muted handik-admin-spam-toggle"><a href="' . esc_url( $spam_url ) . '">' . esc_html( sprintf( _n( 'Show %d hidden spam contact', 'Show %d hidden spam contacts', $spam_count, 'handik-booking-app' ), $spam_count ) ) . '</a></p>';
		}

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function people_filter_chips( $active ) {
		$page = 'handik-booking-app-crm';
		$chips = array(
			'all'           => __( 'All people', 'handik-booking-app' ),
			'with_bookings' => __( 'With bookings', 'handik-booking-app' ),
			'drafts_only'   => __( 'Drafts only', 'handik-booking-app' ),
			'no_address'    => __( 'No address', 'handik-booking-app' ),
		);
		// Sprint 11 fix: preserve the search query + show_spam toggle on
		// chip clicks. Was P2 — typing "Smith", clicking "With bookings"
		// dropped the search and the user had to retype it.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$preserve = array_filter( array(
			'q'         => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
			'show_spam' => ! empty( $_GET['show_spam'] ) ? 1 : 0,
		), static function ( $v ) { return '' !== $v && 0 !== $v; } );
		// phpcs:enable
		$html = '<nav class="handik-admin-segmented" aria-label="' . esc_attr__( 'People filter', 'handik-booking-app' ) . '">';
		foreach ( $chips as $key => $label ) {
			$url = Handik_Booking_App_Admin_Helpers::admin_url_for(
				$page,
				array_merge( array( 'filter' => $key ), $preserve )
			);
			$cls = 'handik-admin-segment' . ( $active === $key ? ' is-active' : '' );
			$html .= '<a class="' . $cls . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	protected function people_search_form( $query, $filter, $show_spam ) {
		ob_start();
		?>
		<form class="handik-admin-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="handik-booking-app-crm" />
			<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
			<?php if ( $show_spam ) : ?><input type="hidden" name="show_spam" value="1" /><?php endif; ?>
			<label class="handik-admin-filter handik-admin-filter--search">
				<span><?php esc_html_e( 'Search by name, phone, or email', 'handik-booking-app' ); ?></span>
				<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" data-handik-debounced-submit placeholder="<?php esc_attr_e( 'e.g. Zinkin, kyrymoch, 617…', 'handik-booking-app' ); ?>" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'handik-booking-app' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	protected function people_list_markup( array $rows ) {
		ob_start();
		?>
		<div class="handik-admin-people-list">
			<?php foreach ( $rows as $row ) : ?>
				<?php echo $this->person_row_markup( $row ); ?>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	protected function person_row_markup( array $row ) {
		$detail_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'contact_id' => (int) $row['id'] ) );

		$phone = (string) ( $row['phone'] ?? '' );
		// Sprint 8: route through the helper so the "X ago" string matches
		// other admin pages that already use relative_time().
		$last_seen_text = ! empty( $row['last_seen_at'] )
			? Handik_Booking_App_Admin_Helpers::relative_time( (string) $row['last_seen_at'] )
			: __( 'never', 'handik-booking-app' );
		if ( '' === $last_seen_text ) {
			$last_seen_text = __( 'never', 'handik-booking-app' );
		}

		$counts_text = sprintf(
			/* translators: 1: addresses 2: requests 3: bookings */
			__( '%1$d addresses · %2$d requests · %3$d bookings', 'handik-booking-app' ),
			(int) ( $row['addresses_count'] ?? 0 ),
			(int) ( $row['requests_count'] ?? 0 ),
			(int) ( $row['bookings_count'] ?? 0 )
		);

		$cls = 'handik-admin-person-row';
		if ( ! empty( $row['is_spam'] ) ) {
			$cls .= ' is-spam';
		}

		ob_start();
		?>
		<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $detail_url ); ?>">
			<div class="handik-admin-person-row__name">
				<strong><?php echo esc_html( (string) ( $row['full_name'] ?? __( 'Unnamed', 'handik-booking-app' ) ) ); ?></strong>
				<?php if ( ! empty( $row['is_spam'] ) ) : ?><span class="handik-admin-pill handik-admin-pill--danger"><?php esc_html_e( 'spam', 'handik-booking-app' ); ?></span><?php endif; ?>
			</div>
			<div class="handik-admin-person-row__phone"><?php echo esc_html( $phone ); ?></div>
			<div class="handik-admin-person-row__counts"><?php echo esc_html( $counts_text ); ?></div>
			<div class="handik-admin-person-row__last"><?php echo esc_html( __( 'Last seen:', 'handik-booking-app' ) . ' ' . $last_seen_text ); ?></div>
			<div class="handik-admin-person-row__cta" aria-hidden="true">→</div>
		</a>
		<?php
		return (string) ob_get_clean();
	}

	protected function render_request_focus_list( $filter ) {
		switch ( $filter ) {
			case 'drafts_old':
				$rows = $this->job_requests ? $this->job_requests->list_drafts_older_than( 24, 100 ) : array();
				$title = __( 'Drafts', 'handik-booking-app' );
				break;
			case 'ready_not_booked':
				$rows = $this->job_requests ? $this->job_requests->list_ready_not_booked( 7, 100 ) : array();
				$title = __( 'Ready for booking but never booked', 'handik-booking-app' );
				break;
			case 'unsafe':
				$rows = $this->job_requests ? $this->job_requests->list_unsafe_in_last_days( 7, 100 ) : array();
				$title = __( 'Unsafe routings (last 7 days)', 'handik-booking-app' );
				break;
			default:
				return '';
		}

		// Sprint 7 (admin perf): bulk-load contacts for the focus list. The
		// dashboard "drafts older than X" / "ready not booked" / "unsafe"
		// chips each show up to 10 rows; without this every chip was 10
		// per-row `contacts->get()` queries. Now: one IN(...) query per chip.
		$focus_contact_ids = array();
		foreach ( $rows as $r ) {
			$cid = (int) ( $r['contact_id'] ?? 0 );
			if ( $cid > 0 ) { $focus_contact_ids[] = $cid; }
		}
		$focus_contacts = ( $this->contacts && method_exists( $this->contacts, 'get_many' ) )
			? $this->contacts->get_many( $focus_contact_ids )
			: array();

		// 2.1.26.0 — A4: bulk-delete UI for the "Abandoned drafts (24h+)"
		// focus list. Owner reported many "Unknown Drafts" left over
		// from removed test contacts that can't be cleared one at a
		// time. Only render the bulk checkboxes for `drafts_old` —
		// the other focus lists (ready_not_booked, unsafe) are for
		// follow-up review, not destructive cleanup.
		$show_bulk     = 'drafts_old' === $filter && current_user_can( Handik_Booking_App_Capabilities::MANAGE_DELETE );
		$rest          = trailingslashit( rest_url( 'handik-booking-app/v1' ) );
		$bulk_endpoint = $rest . 'admin/job-requests/bulk-delete';

		ob_start();
		?>
		<section class="handik-admin-block"
			<?php if ( $show_bulk ) : ?>
				data-handik-bulk-drafts
				data-bulk-endpoint="<?php echo esc_attr( esc_url_raw( $bulk_endpoint ) ); ?>"
				data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			<?php endif; ?>>
			<h2 class="handik-admin-section-title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<?php /* Sprint 11 fix: was "Nothing here. Nice." — friendly
				   snark that didn't match the tone of the rest of the
				   admin empty states. Aligned to a neutral, descriptive
				   copy so the page voice is consistent. */ ?>
				<div class="handik-admin-empty"><p><?php esc_html_e( 'No requests in this bucket right now.', 'handik-booking-app' ); ?></p></div>
			<?php else : ?>
				<?php if ( $show_bulk ) : ?>
					<div class="handik-admin-bulk-actions">
						<label class="handik-admin-checkbox">
							<input type="checkbox" data-handik-bulk-toggle-all aria-label="<?php esc_attr_e( 'Select all drafts', 'handik-booking-app' ); ?>" />
							<span><?php esc_html_e( 'Select all', 'handik-booking-app' ); ?></span>
						</label>
						<span class="handik-admin-bulk-actions__count" data-handik-bulk-count>0 <?php esc_html_e( 'selected', 'handik-booking-app' ); ?></span>
						<button type="button" class="button button-secondary" data-handik-bulk-apply disabled>
							<?php esc_html_e( 'Delete selected', 'handik-booking-app' ); ?>
						</button>
					</div>
				<?php endif; ?>
				<ul class="handik-admin-request-list<?php echo $show_bulk ? ' is-bulk-mode' : ''; ?>">
					<?php foreach ( $rows as $r ) :
						$cid = (int) ( $r['contact_id'] ?? 0 );
						$contact = $cid && isset( $focus_contacts[ $cid ] )
							? $focus_contacts[ $cid ]
							: ( $cid ? $this->contacts->get( $cid ) : null );
						$rid = (int) $r['id'];
						$url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'request_id' => $rid ) );
					?>
						<li data-request-id="<?php echo esc_attr( (string) $rid ); ?>">
							<?php if ( $show_bulk ) : ?>
								<label class="handik-admin-bulk-row-check" aria-label="<?php esc_attr_e( 'Select draft for bulk delete', 'handik-booking-app' ); ?>">
									<input type="checkbox" data-handik-bulk-row value="<?php echo esc_attr( (string) $rid ); ?>" />
								</label>
							<?php endif; ?>
							<a href="<?php echo esc_url( $url ); ?>">
								<strong><?php echo esc_html( (string) ( $contact['full_name'] ?? __( 'Unknown', 'handik-booking-app' ) ) ); ?></strong>
								<span class="handik-admin-muted">·
									<?php echo esc_html( (string) ( $r['app_step'] ?? '' ) ); ?>
									·
									<?php echo esc_html( Handik_Booking_App_Admin_Helpers::format_short( (string) ( $r['updated_at'] ?? '' ) ) ); ?>
								</span>
								<?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( (string) ( $r['status'] ?? '' ) ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// =====================================================================
	// PERSON DETAIL (C1) + edit (C3) + address management
	// =====================================================================

	protected function render_person_detail( $contact_id ) {
		$contact = $this->contacts ? $this->contacts->get( $contact_id ) : null;
		if ( ! $contact ) {
			Handik_Booking_App_Admin_Helpers::page_start( __( 'Person', 'handik-booking-app' ) );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Contact not found.', 'handik-booking-app' ) . '</p></div>';
			Handik_Booking_App_Admin_Helpers::page_end();
			return;
		}

		$addresses = $this->addresses ? $this->addresses->list_for_contact( $contact_id, false ) : array();
		$requests  = $this->job_requests ? $this->job_requests->list_recent_for_contact( $contact_id, 100 ) : array();
		$bookings  = $this->bookings_for_contact( $requests );

		Handik_Booking_App_Admin_Helpers::page_start( (string) ( $contact['full_name'] ?? __( 'Person', 'handik-booking-app' ) ) );

		echo $this->person_header_markup( $contact );
		echo $this->person_edit_form_markup( $contact );
		echo $this->person_addresses_markup( $contact_id, $addresses );
		echo $this->person_requests_markup( $requests, $bookings );
		echo $this->person_danger_zone_markup( $contact_id, $contact );

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	protected function bookings_for_contact( array $requests ) {
		// Sprint 7 (admin perf): one IN(...) round trip instead of N
		// `find_latest_for_request` lookups (one per request). On a person
		// with 30 historical requests this dropped from 30 queries to 1.
		if ( empty( $requests ) || ! $this->bookings ) {
			return array();
		}
		$ids = array();
		foreach ( $requests as $r ) {
			$rid = (int) ( $r['id'] ?? 0 );
			if ( $rid > 0 ) { $ids[] = $rid; }
		}
		if ( method_exists( $this->bookings, 'find_latest_for_requests' ) ) {
			return $this->bookings->find_latest_for_requests( $ids );
		}
		// Fallback if running against an older bookings service.
		$out = array();
		foreach ( $ids as $rid ) {
			$b = $this->bookings->find_latest_for_request( $rid );
			if ( $b ) {
				$out[ $rid ] = $b;
			}
		}
		return $out;
	}

	protected function person_header_markup( array $contact ) {
		$rest = trailingslashit( rest_url( 'handik-booking-app/v1' ) );
		$tel  = Handik_Booking_App_Admin_Helpers::tel_url( (string) ( $contact['phone'] ?? '' ) );
		$mail = Handik_Booking_App_Admin_Helpers::mailto_url( (string) ( $contact['email'] ?? '' ) );

		// 2.1.25.0 (B5): replace the wide text-and-emoji buttons (📞
		// +1 617 555 1234 / ✉ alex@... / 📅 Book a visit) with
		// matching-style icon-only buttons on mobile. Same component
		// (.handik-admin-icon-btn) the Bookings detail header uses
		// — call (green), SMS (blue), email (slate), Book a visit
		// (primary blue). Number/email is the call-to-action; the
		// person's full name + the addresses list below carry the
		// "is this the right person?" context.
		$phone = (string) ( $contact['phone'] ?? '' );
		$sms   = $phone ? Handik_Booking_App_Admin_Helpers::sms_url( $phone ) : '';
		$book_url = Handik_Booking_App_Admin_Helpers::admin_url_for(
			'handik-booking-app-bookings',
			array( 'action' => 'new', 'contact_id' => (int) ( $contact['id'] ?? 0 ) )
		);

		ob_start();
		?>
		<header class="handik-admin-person-header"
			data-handik-person
			data-contact-id="<?php echo esc_attr( (string) (int) $contact['id'] ); ?>"
			data-rest-base="<?php echo esc_attr( esc_url_raw( $rest ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<div>
				<a class="handik-admin-back" href="<?php echo esc_url( Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm' ) ); ?>">←</a>
				<h2><?php echo esc_html( (string) ( $contact['full_name'] ?? __( 'Unnamed', 'handik-booking-app' ) ) ); ?></h2>
				<?php if ( ! empty( $contact['is_spam'] ) ) : ?><span class="handik-admin-pill handik-admin-pill--danger"><?php esc_html_e( 'spam', 'handik-booking-app' ); ?></span><?php endif; ?>
				<?php if ( ! empty( $contact['is_returning'] ) ) : ?><span class="handik-admin-pill handik-admin-pill--info"><?php esc_html_e( 'returning', 'handik-booking-app' ); ?></span><?php endif; ?>
			</div>
			<div class="handik-admin-person-header__contacts">
				<?php if ( $tel ) : ?>
					<a class="handik-admin-icon-btn is-call" href="<?php echo esc_url( $tel ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Call %s', 'handik-booking-app' ), $phone ) ); ?>" title="<?php echo esc_attr( sprintf( __( 'Call %s', 'handik-booking-app' ), $phone ) ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56-.35-.12-.74-.03-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z"/></svg>
					</a>
				<?php endif; ?>
				<?php if ( $sms ) : ?>
					<a class="handik-admin-icon-btn is-sms" href="<?php echo esc_url( $sms ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Send SMS to %s', 'handik-booking-app' ), $phone ) ); ?>" title="<?php echo esc_attr( sprintf( __( 'Send SMS to %s', 'handik-booking-app' ), $phone ) ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM7 9h10v2H7V9zm6 5H7v-2h6v2zm4-6H7V6h10v2z"/></svg>
					</a>
				<?php endif; ?>
				<?php if ( $mail ) : ?>
					<a class="handik-admin-icon-btn is-email" href="<?php echo esc_url( $mail ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Email %s', 'handik-booking-app' ), (string) $contact['email'] ) ); ?>" title="<?php echo esc_attr( sprintf( __( 'Email %s', 'handik-booking-app' ), (string) $contact['email'] ) ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
					</a>
				<?php endif; ?>
				<a class="handik-admin-icon-btn is-book" href="<?php echo esc_url( $book_url ); ?>" aria-label="<?php esc_attr_e( 'Book a visit for this customer', 'handik-booking-app' ); ?>" title="<?php esc_attr_e( 'Book a visit', 'handik-booking-app' ); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 002 2h14c1.1 0 2-.9 2-2V6a2 2 0 00-2-2zm0 16H5V10h14v10zM11 12h2v2h-2v-2zm0 4h2v2h-2v-2zm-4-4h2v2H7v-2zm0 4h2v2H7v-2zm8-4h2v2h-2v-2zm0 4h2v2h-2v-2z"/></svg>
				</a>
			</div>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	protected function person_edit_form_markup( array $contact ) {
		ob_start();
		?>
		<?php /* Sprint 10 fix: persist `<details>` open state across
		   reloads via sessionStorage (handler in booking-app-admin.js).
		   Was P1 — the form snapped shut every time the page refreshed,
		   forcing the owner to expand it again to keep editing. */ ?>
		<section class="handik-admin-block">
			<details class="handik-admin-details" data-handik-person-edit data-handik-details-key="person-edit">
				<summary><?php esc_html_e( 'Edit person', 'handik-booking-app' ); ?></summary>
				<div class="handik-admin-details__body">
					<div class="handik-admin-grid">
						<label class="handik-admin-field"><span><?php esc_html_e( 'Full name', 'handik-booking-app' ); ?></span><input type="text" data-field="full_name" value="<?php echo esc_attr( (string) ( $contact['full_name'] ?? '' ) ); ?>" /></label>
						<label class="handik-admin-field"><span><?php esc_html_e( 'Phone', 'handik-booking-app' ); ?></span><input type="tel" data-field="phone" autocomplete="tel" inputmode="tel" value="<?php echo esc_attr( (string) ( $contact['phone'] ?? '' ) ); ?>" /></label>
						<label class="handik-admin-field"><span><?php esc_html_e( 'Email', 'handik-booking-app' ); ?></span><input type="email" data-field="email" autocomplete="email" inputmode="email" value="<?php echo esc_attr( (string) ( $contact['email'] ?? '' ) ); ?>" /></label>
					</div>
					<label class="handik-admin-field handik-admin-field--textarea"><span><?php esc_html_e( 'Admin notes', 'handik-booking-app' ); ?></span><textarea rows="3" data-field="notes"><?php echo esc_textarea( (string) ( $contact['notes'] ?? '' ) ); ?></textarea></label>
					<div class="handik-admin-grid">
						<label class="handik-admin-checkbox"><input type="checkbox" data-field="is_returning" value="1"<?php checked( ! empty( $contact['is_returning'] ) ); ?> /> <span><?php esc_html_e( 'Mark as returning client', 'handik-booking-app' ); ?></span></label>
						<label class="handik-admin-checkbox"><input type="checkbox" data-field="is_spam" value="1"<?php checked( ! empty( $contact['is_spam'] ) ); ?> /> <span><?php esc_html_e( 'Mark as test/spam (hidden by default)', 'handik-booking-app' ); ?></span></label>
					</div>
					<p><button type="button" class="button button-primary" data-handik-action="person-save">💾 <?php esc_html_e( 'Save', 'handik-booking-app' ); ?></button></p>
				</div>
			</details>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function person_addresses_markup( $contact_id, array $addresses ) {
		ob_start();
		?>
		<section class="handik-admin-block" data-handik-addresses data-contact-id="<?php echo esc_attr( (string) (int) $contact_id ); ?>">
			<h3 class="handik-admin-section-title"><?php esc_html_e( 'Addresses', 'handik-booking-app' ); ?></h3>
			<?php if ( empty( $addresses ) ) : ?>
				<p class="handik-admin-muted"><?php esc_html_e( 'No saved addresses yet.', 'handik-booking-app' ); ?></p>
			<?php else : ?>
				<ul class="handik-admin-addr-list">
					<?php foreach ( $addresses as $a ) :
						// 2.1.26.0 (A2): build the full address string for the
						// Apple Maps icon so it opens the right pin with one
						// tap. street + city + state + zip; unit is
						// intentionally NOT included since Apple Maps
						// doesn't parse "Apt 3B" reliably and the geocode
						// is the same with or without it.
						$addr_parts = array_filter( array(
							(string) ( $a['address_full'] ?? '' ),
							(string) ( $a['city'] ?? '' ),
							trim( (string) ( $a['state'] ?? '' ) . ' ' . (string) ( $a['zip_code'] ?? '' ) ),
						) );
						$addr_for_maps = implode( ', ', $addr_parts );
						$apple_url     = Handik_Booking_App_Admin_Helpers::apple_maps_url( $addr_for_maps );
					?>
						<li class="handik-admin-addr"
							data-address-id="<?php echo esc_attr( (string) (int) $a['id'] ); ?>"
							data-label="<?php echo esc_attr( (string) ( $a['label'] ?? '' ) ); ?>"
							data-address-full="<?php echo esc_attr( (string) ( $a['address_full'] ?? '' ) ); ?>"
							data-address-unit="<?php echo esc_attr( (string) ( $a['address_unit'] ?? '' ) ); ?>"
							data-city="<?php echo esc_attr( (string) ( $a['city'] ?? '' ) ); ?>"
							data-state="<?php echo esc_attr( (string) ( $a['state'] ?? '' ) ); ?>"
							data-zip="<?php echo esc_attr( (string) ( $a['zip_code'] ?? '' ) ); ?>">
							<div class="handik-admin-addr__main">
								<?php if ( ! empty( $a['label'] ) ) : ?><strong class="handik-admin-addr__label"><?php echo esc_html( (string) $a['label'] ); ?>:</strong> <?php endif; ?>
								<strong><?php echo esc_html( (string) ( $a['address_full'] ?? '' ) ); ?></strong>
								<?php if ( ! empty( $a['address_unit'] ) ) : ?><span class="handik-admin-muted">· <?php echo esc_html( (string) $a['address_unit'] ); ?></span><?php endif; ?>
								<?php if ( ! empty( $a['is_primary'] ) ) : ?><span class="handik-admin-pill handik-admin-pill--info"><?php esc_html_e( 'primary', 'handik-booking-app' ); ?></span><?php endif; ?>
								<?php if ( $apple_url ) : ?>
									<a class="handik-admin-icon-btn handik-admin-icon-btn--inline is-map"
										href="<?php echo esc_url( $apple_url ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										aria-label="<?php esc_attr_e( 'Open in Apple Maps', 'handik-booking-app' ); ?>"
										title="<?php esc_attr_e( 'Open in Apple Maps', 'handik-booking-app' ); ?>">
										<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
									</a>
								<?php endif; ?>
							</div>
							<div class="handik-admin-addr__actions">
								<?php if ( empty( $a['is_primary'] ) ) : ?>
									<button type="button" class="button-link" data-handik-action="addr-primary"><?php esc_html_e( 'Set primary', 'handik-booking-app' ); ?></button>
								<?php endif; ?>
								<button type="button" class="button-link" data-handik-action="addr-edit"><?php esc_html_e( 'Edit', 'handik-booking-app' ); ?></button>
								<button type="button" class="button-link-delete" data-handik-action="addr-delete"><?php esc_html_e( 'Delete', 'handik-booking-app' ); ?></button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sprint 12 — danger-zone for hard-delete of an entire contact.
	 * Visible only to MANAGE_DELETE holders. Pre-computes the cascade
	 * counts (addresses, requests, bookings, etc.) so the typed-confirm
	 * modal can name what's about to be wiped.
	 */
	protected function person_danger_zone_markup( $contact_id, array $contact ) {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_DELETE ) ) {
			return '';
		}
		$contact_id = (int) $contact_id;
		$counts = $this->contacts && method_exists( $this->contacts, 'count_dependents' )
			? $this->contacts->count_dependents( $contact_id )
			: array();
		$summary_lines = array();
		$labels = array(
			'addresses'                   => __( '%d addresses', 'handik-booking-app' ),
			'job_requests'                => __( '%d requests', 'handik-booking-app' ),
			'bookings'                    => __( '%d bookings', 'handik-booking-app' ),
			'messages'                    => __( '%d messages', 'handik-booking-app' ),
			'login_tokens'                => __( '%d login tokens', 'handik-booking-app' ),
			'direct_booking_requests'     => __( '%d direct-form submissions', 'handik-booking-app' ),
			'project_scheduling_requests' => __( '%d project schedules', 'handik-booking-app' ),
			'project_work_days'           => __( '%d work days', 'handik-booking-app' ),
		);
		foreach ( $labels as $key => $tpl ) {
			$n = (int) ( $counts[ $key ] ?? 0 );
			if ( $n > 0 ) {
				$summary_lines[] = sprintf( $tpl, $n );
			}
		}
		$preview = empty( $summary_lines )
			? __( 'No dependent data — only the contact row will be removed.', 'handik-booking-app' )
			: __( 'Cascade will also wipe: ', 'handik-booking-app' ) . implode( ', ', $summary_lines ) . '.';
		ob_start();
		?>
		<section class="handik-admin-block handik-admin-danger-zone" aria-label="<?php esc_attr_e( 'Danger zone', 'handik-booking-app' ); ?>">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Danger zone', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-muted"><?php esc_html_e( 'Permanently remove this person and every record that references them. Irreversible — no soft-delete, no audit-trail copy of the customer\'s data, no restore. Used for spam cleanup and right-to-be-forgotten requests.', 'handik-booking-app' ); ?></p>
			<p class="handik-admin-muted"><strong><?php echo esc_html( $preview ); ?></strong></p>
			<button type="button"
				class="button button-link-delete"
				data-handik-delete="contact"
				data-handik-id="<?php echo esc_attr( (string) $contact_id ); ?>"
				data-handik-label="<?php echo esc_attr( (string) ( $contact['full_name'] ?? '' ) ); ?>"
				data-handik-redirect="<?php echo esc_attr( Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm' ) ); ?>"
			>🗑 <?php esc_html_e( 'Delete this person…', 'handik-booking-app' ); ?></button>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	protected function person_requests_markup( array $requests, array $bookings ) {
		ob_start();
		?>
		<section class="handik-admin-block">
			<h3 class="handik-admin-section-title"><?php esc_html_e( 'Requests & bookings', 'handik-booking-app' ); ?></h3>
			<?php if ( empty( $requests ) ) : ?>
				<p class="handik-admin-muted"><?php esc_html_e( 'This person has not started any request yet.', 'handik-booking-app' ); ?></p>
			<?php else : ?>
				<ul class="handik-admin-request-list">
					<?php foreach ( $requests as $r ) :
						$rid = (int) ( $r['id'] ?? 0 );
						$booking = $bookings[ $rid ] ?? null;
						if ( $booking ) {
							$url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', array( 'booking_id' => (int) $booking['id'] ) );
							$dt  = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $booking['start_time'] ?? '' ) );
							$when = $dt ? $dt->format( 'D, M j · g:i A' ) : '—';
							$status = $this->bookings ? $this->bookings->effective_status( $booking ) : (string) ( $booking['status'] ?? '' );
						} else {
							$url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'request_id' => $rid ) );
							$when = Handik_Booking_App_Admin_Helpers::format_short( (string) ( $r['updated_at'] ?? '' ) );
							$status = (string) ( $r['status'] ?? '' );
						}
						$tasks = Handik_Booking_App_Admin_Helpers::task_summary_text(
							is_array( $r['selected_tasks'] ?? null ) ? $r['selected_tasks'] : array(),
							$this->catalog
						);
					?>
						<li><a href="<?php echo esc_url( $url ); ?>">
							<strong>#<?php echo esc_html( (string) $rid ); ?></strong>
							<span class="handik-admin-muted"><?php echo esc_html( $when ); ?></span>
							<span><?php echo esc_html( $tasks ); ?></span>
							<?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $status ); ?>
						</a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// =====================================================================
	// REQUEST DETAIL (C2)
	// =====================================================================

	protected function render_request_detail( $request_id ) {
		$request = $this->job_requests ? $this->job_requests->get( $request_id ) : null;
		if ( ! $request ) {
			Handik_Booking_App_Admin_Helpers::page_start( __( 'Request', 'handik-booking-app' ) );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Request not found.', 'handik-booking-app' ) . '</p></div>';
			Handik_Booking_App_Admin_Helpers::page_end();
			return;
		}

		$contact = ! empty( $request['contact_id'] ) ? $this->contacts->get( (int) $request['contact_id'] ) : null;
		$address = ! empty( $request['address_id'] ) ? $this->addresses->get( (int) $request['address_id'] ) : null;
		$photos  = is_array( $request['photos'] ?? null ) ? $request['photos'] : array();
		$full_address = Handik_Booking_App_Admin_Helpers::full_request_address( $request, $address );
		$booking = $this->bookings ? $this->bookings->find_latest_for_request( $request_id ) : null;

		Handik_Booking_App_Admin_Helpers::page_start(
			sprintf( __( 'Request #%d', 'handik-booking-app' ), $request_id )
		);

		// Banner with status + step.
		$step = (string) ( $request['app_step'] ?? '' );
		$status = (string) ( $request['status'] ?? '' );
		$banner_text = '';
		if ( 'draft' === $status ) {
			$banner_text = sprintf( __( 'This request was abandoned at the %s step — no booking was created.', 'handik-booking-app' ), $step );
		} elseif ( 'ready_for_booking' === $status && ! $booking ) {
			$banner_text = __( 'Customer was about to book but did not finish.', 'handik-booking-app' );
		} elseif ( $booking ) {
			$booking_url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-bookings', array( 'booking_id' => (int) $booking['id'] ) );
			$banner_text = sprintf( __( 'This request became booking #%d. ', 'handik-booking-app' ), (int) $booking['id'] );
			echo '<div class="handik-admin-callout"><p>' . esc_html( $banner_text ) . '<a href="' . esc_url( $booking_url ) . '">' . esc_html__( 'Open the booking →', 'handik-booking-app' ) . '</a></p></div>';
			$banner_text = '';
		}

		if ( $banner_text ) {
			echo '<div class="handik-admin-callout"><p>' . esc_html( $banner_text ) . '</p></div>';
		}

		// Send-link button for ready-not-booked
		if ( 'ready_for_booking' === $status && ! $booking && ! empty( $request['cal_booking_url'] ) && ! empty( $contact['email'] ) ) {
			$subject = rawurlencode( __( 'Your Handik booking link', 'handik-booking-app' ) );
			// Sprint 11 fix: a customer name containing literal `%` chars
			// would crash sprintf and bubble a PHP warning into the
			// mailto body. Use str_replace + a simple "Hi %s" template
			// so any printf format spec in the name is treated as text.
			$customer_name = (string) ( $contact['full_name'] ?? '' );
			$greeting      = str_replace( '%s', $customer_name, __( 'Hi %s,', 'handik-booking-app' ) );
			$body    = rawurlencode(
				sprintf(
					"%s\n\n%s\n\n%s",
					$greeting,
					__( 'Pick a time here:', 'handik-booking-app' ),
					(string) $request['cal_booking_url']
				)
			);
			$mailto = 'mailto:' . rawurlencode( (string) $contact['email'] ) . '?subject=' . $subject . '&body=' . $body;
			echo '<p><a class="button button-primary" href="' . esc_url( $mailto ) . '">📧 ' . esc_html__( 'Send the customer their booking link', 'handik-booking-app' ) . '</a></p>';
		}

		// At-a-glance for the request (date is "created" not "scheduled").
		ob_start();
		?>
		<section class="handik-admin-glance">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'At a glance', 'handik-booking-app' ); ?></h2>
			<div class="handik-admin-glance__grid">
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Status', 'handik-booking-app' ); ?></span>
					<strong><?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( $status ); ?></strong>
					<span><?php esc_html_e( 'Updated', 'handik-booking-app' ); ?> <?php echo esc_html( Handik_Booking_App_Admin_Helpers::relative_time( (string) ( $request['updated_at'] ?? '' ) ) ); ?></span>
				</div>
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Client', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( (string) ( $contact['full_name'] ?? __( 'Unknown', 'handik-booking-app' ) ) ); ?></strong>
					<?php if ( $contact && ! empty( $contact['phone'] ) ) : ?>
						<span><a href="<?php echo esc_url( Handik_Booking_App_Admin_Helpers::tel_url( (string) $contact['phone'] ) ); ?>"><?php echo esc_html( (string) $contact['phone'] ); ?></a></span>
					<?php endif; ?>
				</div>
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Where', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( $full_address ?: __( 'No address', 'handik-booking-app' ) ); ?></strong>
				</div>
				<div class="handik-admin-glance__cell">
					<span class="handik-admin-glance__label"><?php esc_html_e( 'Tasks', 'handik-booking-app' ); ?></span>
					<strong><?php echo esc_html( Handik_Booking_App_Admin_Helpers::task_summary_text( is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(), $this->catalog ) ); ?></strong>
				</div>
			</div>
		</section>
		<?php
		echo ob_get_clean();

		// Photos
		echo '<section class="handik-admin-block">';
		echo '<h2 class="handik-admin-section-title">' . esc_html__( 'Photos', 'handik-booking-app' ) . '</h2>';
		echo Handik_Booking_App_Admin_Helpers::photos_gallery_markup( $photos );
		echo '</section>';

		// Transcript
		$msgs = ( $this->messages && $request_id ) ? $this->messages->list_for_request( $request_id, 200 ) : array();
		echo '<section class="handik-admin-block">';
		echo '<h2 class="handik-admin-section-title">' . esc_html__( 'What the customer wrote', 'handik-booking-app' ) . '</h2>';
		if ( empty( $msgs ) ) {
			echo '<p class="handik-admin-muted">' . esc_html__( 'No transcript stored for this request.', 'handik-booking-app' ) . '</p>';
		} else {
			echo '<div class="handik-admin-transcript">';
			foreach ( $msgs as $msg ) {
				$role = (string) ( $msg['role'] ?? 'user' );
				$dt = Handik_Booking_App_Admin_Helpers::utc_to_eastern( (string) ( $msg['created_at'] ?? '' ) );
				$time = $dt ? $dt->format( 'g:i A' ) : '';
				echo '<div class="handik-admin-bubble handik-admin-bubble--' . esc_attr( sanitize_html_class( $role ) ) . '"><div class="handik-admin-bubble__body">' . nl2br( esc_html( (string) ( $msg['content'] ?? '' ) ) ) . '</div><div class="handik-admin-bubble__time">' . esc_html( $time ) . '</div></div>';
			}
			echo '</div>';
		}
		echo '</section>';

		// Tasks
		echo '<section class="handik-admin-block">';
		echo '<h2 class="handik-admin-section-title">' . esc_html__( 'Selected tasks', 'handik-booking-app' ) . '</h2>';
		echo Handik_Booking_App_Admin_Helpers::task_summary_with_rates_html( is_array( $request['selected_tasks'] ?? null ) ? $request['selected_tasks'] : array(), $this->catalog );
		echo '</section>';

		echo $this->request_danger_zone_markup( $request );

		Handik_Booking_App_Admin_Helpers::page_end();
	}

	/**
	 * Sprint 12 — request-level danger zone. Same pattern as the
	 * person + booking variants; preview lists messages / bookings /
	 * photos that the cascade will sweep.
	 */
	protected function request_danger_zone_markup( array $request ) {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_DELETE ) ) {
			return '';
		}
		$id = (int) ( $request['id'] ?? 0 );
		$counts = $this->job_requests && method_exists( $this->job_requests, 'count_dependents' )
			? $this->job_requests->count_dependents( $id )
			: array();
		$lines = array();
		if ( ! empty( $counts['messages'] ) ) {
			$lines[] = sprintf( __( '%d messages', 'handik-booking-app' ), (int) $counts['messages'] );
		}
		if ( ! empty( $counts['bookings'] ) ) {
			$lines[] = sprintf( __( '%d bookings', 'handik-booking-app' ), (int) $counts['bookings'] );
		}
		if ( ! empty( $counts['photos'] ) ) {
			$lines[] = sprintf( __( '%d photos', 'handik-booking-app' ), (int) $counts['photos'] );
		}
		$preview = empty( $lines )
			? __( 'No dependent data — only the request row will be removed.', 'handik-booking-app' )
			: __( 'Cascade will also wipe: ', 'handik-booking-app' ) . implode( ', ', $lines ) . '.';
		ob_start();
		?>
		<section class="handik-admin-block handik-admin-danger-zone" aria-label="<?php esc_attr_e( 'Danger zone', 'handik-booking-app' ); ?>">
			<h2 class="handik-admin-section-title"><?php esc_html_e( 'Danger zone', 'handik-booking-app' ); ?></h2>
			<p class="handik-admin-muted"><?php esc_html_e( 'Permanently remove this request and its transcript / bookings / photos. The contact row stays — they keep any other requests they have.', 'handik-booking-app' ); ?></p>
			<p class="handik-admin-muted"><strong><?php echo esc_html( $preview ); ?></strong></p>
			<button type="button"
				class="button button-link-delete"
				data-handik-delete="job-request"
				data-handik-id="<?php echo esc_attr( (string) $id ); ?>"
				data-handik-redirect="<?php echo esc_attr( Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm' ) ); ?>"
			>🗑 <?php esc_html_e( 'Delete this request…', 'handik-booking-app' ); ?></button>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// =====================================================================
	// ADD PERSON FORM (C3)
	// =====================================================================

	protected function render_add_form() {
		Handik_Booking_App_Admin_Helpers::page_start( __( 'Add person', 'handik-booking-app' ) );

		$rest = trailingslashit( rest_url( 'handik-booking-app/v1' ) );
		?>
		<section class="handik-admin-block">
			<form class="handik-admin-add-person"
				data-handik-add-person
				data-rest-base="<?php echo esc_attr( esc_url_raw( $rest ) ); ?>"
				data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
				<?php /* Sprint 10 fix: inline patterns for client-side
				   validation. minLength=10 catches a typo'd phone before
				   the round-trip; pattern hint stays visible via the
				   placeholder + title. Required is already on full_name
				   and phone, so the browser blocks empty submits. */ ?>
				<div class="handik-admin-grid">
					<label class="handik-admin-field"><span><?php esc_html_e( 'Full name', 'handik-booking-app' ); ?>*</span><input type="text" name="full_name" required minlength="2" autocomplete="name" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Phone', 'handik-booking-app' ); ?>*</span><input type="tel" name="phone" required minlength="10" autocomplete="tel" inputmode="tel" placeholder="+1 617 555 0123" title="<?php esc_attr_e( '10-digit phone, e.g. +1 617 555 0123', 'handik-booking-app' ); ?>" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Email (optional)', 'handik-booking-app' ); ?></span><input type="email" name="email" autocomplete="email" inputmode="email" /></label>
				</div>
				<label class="handik-admin-field handik-admin-field--textarea"><span><?php esc_html_e( 'Admin notes', 'handik-booking-app' ); ?></span><textarea name="notes" rows="3"></textarea></label>
				<details>
					<summary><?php esc_html_e( 'Initial address (optional)', 'handik-booking-app' ); ?></summary>
					<div class="handik-admin-details__body">
						<div class="handik-admin-grid">
							<label class="handik-admin-field"><span><?php esc_html_e( 'Address', 'handik-booking-app' ); ?></span><input type="text" name="address_full" autocomplete="street-address" /></label>
							<label class="handik-admin-field"><span><?php esc_html_e( 'Unit', 'handik-booking-app' ); ?></span><input type="text" name="address_unit" autocomplete="address-line2" /></label>
							<label class="handik-admin-field"><span><?php esc_html_e( 'City', 'handik-booking-app' ); ?></span><input type="text" name="city" autocomplete="address-level2" /></label>
							<label class="handik-admin-field"><span><?php esc_html_e( 'State', 'handik-booking-app' ); ?></span><input type="text" name="state" autocomplete="address-level1" /></label>
							<label class="handik-admin-field"><span><?php esc_html_e( 'ZIP', 'handik-booking-app' ); ?></span><input type="text" name="zip_code" autocomplete="postal-code" inputmode="numeric" /></label>
						</div>
					</div>
				</details>
				<p>
					<button type="submit" class="button button-primary">💾 <?php esc_html_e( 'Save person', 'handik-booking-app' ); ?></button>
					<a class="button-link" href="<?php echo esc_url( Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm' ) ); ?>"><?php esc_html_e( 'Cancel', 'handik-booking-app' ); ?></a>
				</p>
			</form>
		</section>
		<?php

		Handik_Booking_App_Admin_Helpers::page_end();
	}
}
