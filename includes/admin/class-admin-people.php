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

		if ( $spam_count > 0 ) {
			$spam_url = add_query_arg( 'show_spam', 1, Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm' ) );
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
		$html = '<nav class="handik-admin-segmented" aria-label="' . esc_attr__( 'People filter', 'handik-booking-app' ) . '">';
		foreach ( $chips as $key => $label ) {
			$url = Handik_Booking_App_Admin_Helpers::admin_url_for( $page, array( 'filter' => $key ) );
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
		$last_seen_ts = ! empty( $row['last_seen_at'] ) ? strtotime( (string) $row['last_seen_at'] ) : 0;
		$last_seen_text = $last_seen_ts ? human_time_diff( $last_seen_ts, time() ) . ' ' . __( 'ago', 'handik-booking-app' ) : __( 'never', 'handik-booking-app' );

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
				$title = __( 'Abandoned drafts (24h+)', 'handik-booking-app' );
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

		ob_start();
		?>
		<section class="handik-admin-block">
			<h2 class="handik-admin-section-title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<div class="handik-admin-empty"><p><?php esc_html_e( 'Nothing here. Nice.', 'handik-booking-app' ); ?></p></div>
			<?php else : ?>
				<ul class="handik-admin-request-list">
					<?php foreach ( $rows as $r ) :
						$cid = (int) ( $r['contact_id'] ?? 0 );
						$contact = $cid && isset( $focus_contacts[ $cid ] )
							? $focus_contacts[ $cid ]
							: ( $cid ? $this->contacts->get( $cid ) : null );
						$url = Handik_Booking_App_Admin_Helpers::admin_url_for( 'handik-booking-app-crm', array( 'request_id' => (int) $r['id'] ) );
					?>
						<li><a href="<?php echo esc_url( $url ); ?>">
							<strong><?php echo esc_html( (string) ( $contact['full_name'] ?? __( 'Unknown', 'handik-booking-app' ) ) ); ?></strong>
							<span class="handik-admin-muted">·
								<?php echo esc_html( (string) ( $r['app_step'] ?? '' ) ); ?>
								·
								<?php echo esc_html( (string) ( $r['updated_at'] ?? '' ) ); ?>
							</span>
							<?php echo Handik_Booking_App_Admin_Helpers::status_pill_markup( (string) ( $r['status'] ?? '' ) ); ?>
						</a></li>
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
				<?php if ( $tel ) : ?><a class="button" href="<?php echo esc_url( $tel ); ?>">📞 <?php echo esc_html( (string) $contact['phone'] ); ?></a><?php endif; ?>
				<?php if ( $mail ) : ?><a class="button" href="<?php echo esc_url( $mail ); ?>">✉️ <?php echo esc_html( (string) $contact['email'] ); ?></a><?php endif; ?>
			</div>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	protected function person_edit_form_markup( array $contact ) {
		ob_start();
		?>
		<section class="handik-admin-block">
			<details class="handik-admin-details" data-handik-person-edit>
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
					<?php foreach ( $addresses as $a ) : ?>
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
							$when = (string) ( $r['updated_at'] ?? '' );
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
			$body    = rawurlencode(
				sprintf(
					"%s\n\n%s\n\n%s",
					sprintf( __( 'Hi %s,', 'handik-booking-app' ), (string) ( $contact['full_name'] ?? '' ) ),
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

		Handik_Booking_App_Admin_Helpers::page_end();
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
				<div class="handik-admin-grid">
					<label class="handik-admin-field"><span><?php esc_html_e( 'Full name', 'handik-booking-app' ); ?>*</span><input type="text" name="full_name" required autocomplete="name" /></label>
					<label class="handik-admin-field"><span><?php esc_html_e( 'Phone', 'handik-booking-app' ); ?>*</span><input type="tel" name="phone" required autocomplete="tel" inputmode="tel" placeholder="+1 617 555 0123" /></label>
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
