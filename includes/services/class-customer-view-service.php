<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer 360 — single source of truth for "everything about this customer".
 *
 * Sprint 1 (v2.1.31) of the customer-unification roadmap. Before this
 * service, every admin surface resolved the customer + address for a
 * booking with its own inline logic, and they disagreed:
 *
 *   - Bookings detail resolved address ONLY from the booking's source
 *     row (job_request / direct / project). External Cal bookings —
 *     which carry `external_contact_id` but no `address_id` — always
 *     rendered "No address", even though the linked contact has a
 *     primary address sitting in handik_addresses (and People showed it
 *     just fine). That was "Боль 1" in the roadmap doc.
 *   - Additional Forms direct/project lists printed the customer name as
 *     plain text with no link back to the profile ("Боль 2").
 *
 * This service centralizes the resolution so every surface agrees, and
 * adds the missing primary-address fallback. It deliberately starts as a
 * thin composition over the existing CRM services (contacts / addresses
 * / job_requests / bookings) — later sprints (§7 attributes, §Sprint 7
 * timeline) flesh out `get()`.
 *
 * Per-request instance caches (`$cache_get`, `$cache_for_booking`) keep
 * repeated lookups inside one admin render from re-hitting the DB.
 */
class Handik_Booking_App_Customer_View_Service {
	const SOURCE_MAIN               = 'main';
	const SOURCE_DIRECT             = 'direct';
	const SOURCE_PROJECT            = 'project';
	const SOURCE_EXTERNAL           = 'external';
	const SOURCE_EXTERNAL_UNMATCHED = 'external_unmatched';

	/** People (Customers) admin page slug — for profile deep-links. */
	const CUSTOMER_PAGE_SLUG = 'handik-booking-app-crm';

	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	/** @var array<int, array<string, mixed>> */
	protected $cache_get = array();
	/** @var array<int, array<string, mixed>> */
	protected $cache_for_booking = array();

	public function __construct( $contacts, $addresses, $job_requests, $bookings, $logger = null ) {
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->logger       = $logger;
	}

	/**
	 * Resolve contact + address + source for an arbitrary booking row.
	 *
	 * Returns the source-specific rows too (`request` / `direct` /
	 * `project_day` / `project_schedule`) so callers that still render
	 * source-specific blocks don't have to re-query them.
	 *
	 * Key behavior (the Боль 1 fix): when the booking's source row has no
	 * `address_id` (external Cal bookings always; occasionally a partial
	 * direct/project row), we fall back to the linked contact's PRIMARY
	 * address from handik_addresses. `list_for_contact` already orders
	 * `is_primary DESC`, so element 0 is the primary when one is flagged.
	 *
	 * @param array<string, mixed> $booking handik_bookings row.
	 * @return array{
	 *   contact: array<string,mixed>|null,
	 *   address: array<string,mixed>|null,
	 *   source: string,
	 *   contact_id: int,
	 *   request: array<string,mixed>|null,
	 *   direct: array<string,mixed>|null,
	 *   project_day: array<string,mixed>|null,
	 *   project_schedule: array<string,mixed>|null
	 * }
	 */
	public function for_booking( array $booking ) {
		global $wpdb;

		$booking_id = (int) ( $booking['id'] ?? 0 );
		if ( $booking_id > 0 && isset( $this->cache_for_booking[ $booking_id ] ) ) {
			return $this->cache_for_booking[ $booking_id ];
		}

		$request          = ( ! empty( $booking['job_request_id'] ) && $this->job_requests )
			? $this->job_requests->get( (int) $booking['job_request_id'] )
			: null;
		$direct           = null;
		$project_day      = null;
		$project_schedule = null;

		if ( ! $request && ! empty( $booking['direct_request_id'] ) ) {
			$direct = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Handik_Booking_App_DB::table( 'direct_booking_requests' ) . ' WHERE id = %d LIMIT 1',
					(int) $booking['direct_request_id']
				),
				ARRAY_A
			);
		}
		if ( ! $request && ! $direct && ! empty( $booking['project_work_day_id'] ) ) {
			$project_day = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_work_days' ) . ' WHERE id = %d LIMIT 1',
					(int) $booking['project_work_day_id']
				),
				ARRAY_A
			);
			if ( $project_day && ! empty( $project_day['scheduling_request_id'] ) ) {
				$project_schedule = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM ' . Handik_Booking_App_DB::table( 'project_scheduling_requests' ) . ' WHERE id = %d LIMIT 1',
						(int) $project_day['scheduling_request_id']
					),
					ARRAY_A
				);
			}
		}

		$contact_id = $request
			? (int) ( $request['contact_id'] ?? 0 )
			: ( $direct
				? (int) ( $direct['contact_id'] ?? 0 )
				: ( $project_schedule
					? (int) ( $project_schedule['contact_id'] ?? 0 )
					: (int) ( $booking['external_contact_id'] ?? 0 ) ) );

		$address_id = $request
			? (int) ( $request['address_id'] ?? 0 )
			: ( $direct
				? (int) ( $direct['address_id'] ?? 0 )
				: ( $project_schedule ? (int) ( $project_schedule['address_id'] ?? 0 ) : 0 ) );

		$contact = ( $contact_id && $this->contacts ) ? $this->contacts->get( $contact_id ) : null;
		$address = ( $address_id && $this->addresses ) ? $this->addresses->get( $address_id ) : null;

		// Боль 1 fix — no source address but we know the contact: use their
		// primary address. Closes the "external booking shows no address but
		// People has it" gap.
		if ( ! $address && $contact_id && $this->addresses ) {
			$addr_list = $this->addresses->list_for_contact( $contact_id, false );
			if ( ! empty( $addr_list[0] ) && is_array( $addr_list[0] ) ) {
				$address = $addr_list[0];
			}
		}

		// Determine the source marker.
		if ( $request ) {
			$source = self::SOURCE_MAIN;
		} elseif ( $direct ) {
			$source = self::SOURCE_DIRECT;
		} elseif ( $project_schedule || $project_day ) {
			$source = self::SOURCE_PROJECT;
		} elseif ( $contact_id > 0 ) {
			$source = self::SOURCE_EXTERNAL; // external Cal booking with a matched contact
		} else {
			$source = self::SOURCE_EXTERNAL_UNMATCHED;
		}

		// External booking with no matched contact: synthesize a display-only
		// contact from the stored Cal webhook payload so the UI shows
		// something useful in the "Client" slot.
		if ( ! $contact && self::SOURCE_EXTERNAL_UNMATCHED === $source && ! empty( $booking['raw_webhook_json'] ) ) {
			$contact = $this->synthesize_external_contact( (string) $booking['raw_webhook_json'] );
		}

		$resolved = array(
			'contact'          => $contact,
			'address'          => $address,
			'source'           => $source,
			'contact_id'       => $contact_id,
			'request'          => $request,
			'direct'           => $direct,
			'project_day'      => $project_day,
			'project_schedule' => $project_schedule,
		);

		if ( $booking_id > 0 ) {
			$this->cache_for_booking[ $booking_id ] = $resolved;
		}
		return $resolved;
	}

	/**
	 * Everything about one customer. Sprint 1 ships a solid baseline;
	 * §Sprint 7 fleshes out stats + timeline further.
	 *
	 * @param int $contact_id Contact id.
	 * @return array<string, mixed>|null
	 */
	public function get( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return null;
		}
		if ( isset( $this->cache_get[ $contact_id ] ) ) {
			return $this->cache_get[ $contact_id ];
		}
		$contact = $this->contacts ? $this->contacts->get( $contact_id ) : null;
		if ( ! $contact ) {
			return null;
		}
		$addresses = $this->addresses ? $this->addresses->list_for_contact( $contact_id, false ) : array();
		$requests  = $this->job_requests ? $this->job_requests->list_recent_for_contact( $contact_id, 100 ) : array();
		$counts    = $this->job_requests ? $this->job_requests->counts_for_contact( $contact_id ) : array();

		$primary_address = null;
		foreach ( (array) $addresses as $addr ) {
			if ( ! empty( $addr['is_primary'] ) ) {
				$primary_address = $addr;
				break;
			}
		}
		if ( ! $primary_address && ! empty( $addresses[0] ) ) {
			$primary_address = $addresses[0];
		}

		$requests = is_array( $requests ) ? $requests : array();

		// Sprint 7 — resolve the contact's bookings (main-SPA flow) keyed by
		// request id, then derive richer stats + a chronological timeline.
		$bookings_by_request = $this->bookings_for_requests( $requests );

		$total_visits     = 0;
		$completed_visits = 0;
		$revenue_high     = 0.0;
		$timestamps       = array();
		foreach ( $requests as $req ) {
			$ts = (string) ( $req['created_at'] ?? '' );
			if ( '' !== $ts ) {
				$timestamps[] = $ts;
			}
			$rid = (int) ( $req['id'] ?? 0 );
			$bk  = $rid > 0 && isset( $bookings_by_request[ $rid ] ) ? $bookings_by_request[ $rid ] : null;
			if ( ! $bk ) {
				continue;
			}
			++$total_visits;
			$status = $this->bookings ? $this->bookings->effective_status( $bk ) : (string) ( $bk['status'] ?? '' );
			$bts    = (string) ( $bk['start_time'] ?? ( $bk['created_at'] ?? '' ) );
			if ( '' !== $bts ) {
				$timestamps[] = $bts;
			}
			if ( 'completed' === $status ) {
				++$completed_visits;
				$state         = ! empty( $req['app_state'] ) && is_array( $req['app_state'] ) ? $req['app_state'] : array();
				$revenue_high += (float) ( $state['total_estimate_high'] ?? 0 );
			}
		}

		sort( $timestamps );
		$first_seen = $timestamps ? reset( $timestamps ) : '';
		$last_seen_ts = (int) ( $counts['last_seen'] ?? 0 );

		$out = array(
			'contact'         => $contact,
			'addresses'       => is_array( $addresses ) ? $addresses : array(),
			'primary_address' => $primary_address,
			'requests'        => $requests,
			'bookings'        => $bookings_by_request,
			'timeline'        => $this->build_timeline( $requests, $bookings_by_request ),
			'stats'           => array(
				'requests_count'    => (int) ( $counts['requests'] ?? 0 ),
				'bookings_count'    => (int) ( $counts['bookings'] ?? 0 ),
				'addresses_count'   => (int) ( $counts['addresses'] ?? count( (array) $addresses ) ),
				'total_visits'      => $total_visits,
				'completed_visits'  => $completed_visits,
				'total_revenue_high' => $revenue_high,
				'first_seen'        => $first_seen,
				'last_seen'         => $last_seen_ts,
				'is_returning'      => (int) ( $counts['bookings'] ?? 0 ) > 0,
				'is_spam'           => ! empty( $contact['is_spam'] ),
			),
		);

		$this->cache_get[ $contact_id ] = $out;
		return $out;
	}

	/**
	 * Resolve bookings for a list of request rows, keyed by request id.
	 *
	 * @param array<int, array<string,mixed>> $requests Request rows.
	 * @return array<int, array<string,mixed>>
	 */
	protected function bookings_for_requests( array $requests ) {
		if ( empty( $requests ) || ! $this->bookings ) {
			return array();
		}
		$ids = array();
		foreach ( $requests as $r ) {
			$rid = (int) ( $r['id'] ?? 0 );
			if ( $rid > 0 ) {
				$ids[] = $rid;
			}
		}
		if ( method_exists( $this->bookings, 'find_latest_for_requests' ) ) {
			return $this->bookings->find_latest_for_requests( $ids );
		}
		$out = array();
		foreach ( $ids as $rid ) {
			$b = method_exists( $this->bookings, 'find_latest_for_request' ) ? $this->bookings->find_latest_for_request( $rid ) : null;
			if ( $b ) {
				$out[ $rid ] = $b;
			}
		}
		return $out;
	}

	/**
	 * Build a chronological (newest-first) timeline merging requests +
	 * their bookings. Each entry: ts / kind / label / status.
	 *
	 * @param array<int, array<string,mixed>> $requests Request rows.
	 * @param array<int, array<string,mixed>> $bookings Bookings keyed by request id.
	 * @return array<int, array<string,mixed>>
	 */
	protected function build_timeline( array $requests, array $bookings ) {
		$events = array();
		foreach ( $requests as $req ) {
			$rid = (int) ( $req['id'] ?? 0 );
			$created = (string) ( $req['created_at'] ?? '' );
			if ( '' !== $created ) {
				$desc = trim( (string) ( $req['short_description'] ?? '' ) );
				$events[] = array(
					'ts'     => $created,
					'kind'   => 'request',
					'label'  => '' !== $desc ? $desc : __( 'Submitted a request', 'handik-booking-app' ),
					'status' => (string) ( $req['status'] ?? '' ),
				);
			}
			$bk = $rid > 0 && isset( $bookings[ $rid ] ) ? $bookings[ $rid ] : null;
			if ( $bk ) {
				$bts    = (string) ( $bk['start_time'] ?? ( $bk['created_at'] ?? '' ) );
				$status = $this->bookings ? $this->bookings->effective_status( $bk ) : (string) ( $bk['status'] ?? '' );
				if ( '' !== $bts ) {
					$events[] = array(
						'ts'     => $bts,
						'kind'   => 'booking',
						'label'  => __( 'Visit', 'handik-booking-app' ),
						'status' => $status,
					);
				}
			}
		}
		usort( $events, static function ( $a, $b ) {
			return strcmp( (string) $b['ts'], (string) $a['ts'] );
		} );
		return $events;
	}

	/**
	 * Search across name / phone / email for autocomplete pickers
	 * (pre-approval, add-booking). Returns a minimal shape.
	 *
	 * @param string $query   Free text.
	 * @param int    $limit   Max rows.
	 * @param bool   $exclude_spam Drop spam-flagged contacts.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( $query, $limit = 10, $exclude_spam = true ) {
		$query = trim( (string) $query );
		if ( '' === $query || ! $this->contacts ) {
			return array();
		}
		$rows = $this->contacts->list_people(
			array(
				'search'       => $query,
				'limit'        => max( 1, (int) $limit ),
				'include_spam' => ! $exclude_spam,
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$phone = (string) ( $row['phone'] ?? '' );
			$out[] = array(
				'id'            => (int) ( $row['id'] ?? 0 ),
				'full_name'     => (string) ( $row['full_name'] ?? '' ),
				'phone'         => $phone,
				'phone_display' => $this->format_phone_display( $phone ),
				'email'         => (string) ( $row['email'] ?? '' ),
				'is_returning'  => (int) ( $row['bookings_count'] ?? 0 ) > 0,
				'is_spam'       => ! empty( $row['is_spam'] ),
				'last_seen'     => ! empty( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}
		return $out;
	}

	/**
	 * Assemble a pre-visit briefing from a contact + address — the
	 * "what should I know before knocking on this door" summary the
	 * Bookings detail surfaces. Returns three groups (customer / property /
	 * internal), each a list of `{ label, value, sensitive, tone }` rows;
	 * only set attributes are included, so an empty group means nothing to
	 * show. Sensitive rows (access codes) are flagged so the renderer can
	 * mask + offer copy-to-clipboard.
	 *
	 * @param array<string,mixed>|null $contact Contact row.
	 * @param array<string,mixed>|null $address Address row.
	 * @return array{customer: array, property: array, internal: array}
	 */
	public function pre_visit_briefing( $contact, $address ) {
		$contact = is_array( $contact ) ? $contact : array();
		$address = is_array( $address ) ? $address : array();

		$labels = self::briefing_value_labels();
		$row    = static function ( $label, $value, $sensitive = false, $tone = '' ) {
			return array(
				'label'     => (string) $label,
				'value'     => (string) $value,
				'sensitive' => (bool) $sensitive,
				'tone'      => (string) $tone,
			);
		};

		// ----- Customer group -----
		$customer = array();
		if ( ! empty( $contact['language'] ) ) {
			$customer[] = $row( __( 'Language', 'handik-booking-app' ), $labels['language'][ $contact['language'] ] ?? $contact['language'] );
		}
		if ( ! empty( $contact['preferred_channel'] ) && 'no_preference' !== $contact['preferred_channel'] ) {
			$customer[] = $row( __( 'Prefers', 'handik-booking-app' ), $labels['preferred_channel'][ $contact['preferred_channel'] ] ?? $contact['preferred_channel'] );
		}
		if ( ! empty( $contact['preferred_time'] ) && 'no_preference' !== $contact['preferred_time'] ) {
			$customer[] = $row( __( 'Best time', 'handik-booking-app' ), $labels['preferred_time'][ $contact['preferred_time'] ] ?? $contact['preferred_time'] );
		}
		if ( ! empty( $contact['do_not_text'] ) ) {
			$customer[] = $row( __( 'SMS', 'handik-booking-app' ), __( 'Do NOT text', 'handik-booking-app' ), false, 'warn' );
		}
		if ( ! empty( $contact['payment_method_preferred'] ) && 'no_preference' !== $contact['payment_method_preferred'] ) {
			$customer[] = $row( __( 'Payment', 'handik-booking-app' ), $labels['payment_method_preferred'][ $contact['payment_method_preferred'] ] ?? $contact['payment_method_preferred'] );
		}
		if ( ! empty( $contact['requires_invoice'] ) ) {
			$customer[] = $row( __( 'Billing', 'handik-booking-app' ), __( 'Requires invoice', 'handik-booking-app' ) );
		}
		if ( ! empty( $contact['brand_preferences'] ) ) {
			$customer[] = $row( __( 'Brands', 'handik-booking-app' ), $contact['brand_preferences'] );
		}

		// ----- Property group -----
		$property = array();
		if ( ! empty( $address['building_type'] ) ) {
			$property[] = $row( __( 'Building', 'handik-booking-app' ), $labels['building_type'][ $address['building_type'] ] ?? $address['building_type'] );
		}
		if ( ! empty( $address['building_age_class'] ) && 'unknown' !== $address['building_age_class'] ) {
			$tone = 'pre_1978_lead_paint' === $address['building_age_class'] ? 'warn' : '';
			$property[] = $row( __( 'Age', 'handik-booking-app' ), $labels['building_age_class'][ $address['building_age_class'] ] ?? $address['building_age_class'], false, $tone );
		}
		foreach ( array(
			'gate_code'    => __( 'Gate code', 'handik-booking-app' ),
			'lockbox_code' => __( 'Lockbox', 'handik-booking-app' ),
			'alarm_code'   => __( 'Alarm code', 'handik-booking-app' ),
		) as $field => $label ) {
			if ( ! empty( $address[ $field ] ) ) {
				$property[] = $row( $label, $address[ $field ], true );
			}
		}
		if ( ! empty( $address['doorman'] ) ) {
			$property[] = $row( __( 'Doorman', 'handik-booking-app' ), __( 'Yes', 'handik-booking-app' ) );
		}
		if ( ! empty( $address['freight_elevator_required'] ) ) {
			$hours = ! empty( $address['freight_elevator_hours'] ) ? ' (' . $address['freight_elevator_hours'] . ')' : '';
			$property[] = $row( __( 'Freight elevator', 'handik-booking-app' ), __( 'Required', 'handik-booking-app' ) . $hours );
		}
		if ( ! empty( $address['parking'] ) && 'none' !== $address['parking'] ) {
			$parking = $labels['parking'][ $address['parking'] ] ?? $address['parking'];
			if ( ! empty( $address['parking_notes'] ) ) {
				$parking .= ' — ' . $address['parking_notes'];
			}
			$property[] = $row( __( 'Parking', 'handik-booking-app' ), $parking );
		} elseif ( ! empty( $address['parking_notes'] ) ) {
			$property[] = $row( __( 'Parking', 'handik-booking-app' ), $address['parking_notes'] );
		}
		if ( ! empty( $address['pets_present'] ) ) {
			$pets = ! empty( $address['pets_notes'] ) ? $address['pets_notes'] : __( 'Pets at this address', 'handik-booking-app' );
			$property[] = $row( '🐕 ' . __( 'Pets', 'handik-booking-app' ), $pets );
		}
		foreach ( array(
			'asbestos_warning'   => __( 'Asbestos warning', 'handik-booking-app' ),
			'mold_present'       => __( 'Mold present', 'handik-booking-app' ),
			'hoarding_situation' => __( 'Hoarding situation', 'handik-booking-app' ),
		) as $field => $label ) {
			if ( ! empty( $address[ $field ] ) ) {
				$property[] = $row( '⚠ ' . $label, __( 'Yes', 'handik-booking-app' ), false, 'danger' );
			}
		}
		if ( ! empty( $address['property_notes'] ) ) {
			$property[] = $row( __( 'Property notes', 'handik-booking-app' ), $address['property_notes'] );
		}

		// ----- Internal flags -----
		$internal = array();
		if ( ! empty( $contact['vip'] ) ) {
			$internal[] = $row( 'VIP', __( 'Top-tier customer', 'handik-booking-app' ), false, 'info' );
		}
		foreach ( array(
			'do_not_service'  => __( 'Do not service', 'handik-booking-app' ),
			'scope_creeper'   => __( 'Scope creeper — set clear expectations', 'handik-booking-app' ),
			'negotiates_hard' => __( 'Negotiates hard', 'handik-booking-app' ),
			'complains_after' => __( 'Complains after', 'handik-booking-app' ),
		) as $field => $label ) {
			if ( ! empty( $contact[ $field ] ) ) {
				$internal[] = $row( '⚠', $label, false, 'do_not_service' === $field ? 'danger' : 'warn' );
			}
		}
		if ( ! empty( $contact['tips_well'] ) && 'unknown' !== $contact['tips_well'] ) {
			$internal[] = $row( __( 'Tips', 'handik-booking-app' ), $labels['tips_well'][ $contact['tips_well'] ] ?? $contact['tips_well'] );
		}
		if ( ! empty( $contact['payment_on_time'] ) && 'unknown' !== $contact['payment_on_time'] ) {
			$tone = 'chronically_late' === $contact['payment_on_time'] ? 'warn' : '';
			$internal[] = $row( __( 'Pays', 'handik-booking-app' ), $labels['payment_on_time'][ $contact['payment_on_time'] ] ?? $contact['payment_on_time'], false, $tone );
		}

		return array(
			'customer' => $customer,
			'property' => $property,
			'internal' => $internal,
		);
	}

	/**
	 * Display labels for the enum values surfaced in the briefing. Display-
	 * only; the authoritative allowed-value sets live on the services.
	 *
	 * @return array<string, array<string, string>>
	 */
	protected static function briefing_value_labels() {
		return array(
			'language' => array(
				'en' => __( 'English', 'handik-booking-app' ),
				'ru' => __( 'Russian', 'handik-booking-app' ),
				'both' => __( 'English + Russian', 'handik-booking-app' ),
			),
			'preferred_channel' => array(
				'sms' => __( 'SMS', 'handik-booking-app' ),
				'email' => __( 'Email', 'handik-booking-app' ),
				'call' => __( 'Phone call', 'handik-booking-app' ),
			),
			'preferred_time' => array(
				'morning' => __( 'Morning', 'handik-booking-app' ),
				'afternoon' => __( 'Afternoon', 'handik-booking-app' ),
				'evening' => __( 'Evening', 'handik-booking-app' ),
			),
			'payment_method_preferred' => array(
				'cash' => __( 'Cash', 'handik-booking-app' ),
				'venmo' => __( 'Venmo', 'handik-booking-app' ),
				'zelle' => __( 'Zelle', 'handik-booking-app' ),
				'check' => __( 'Check', 'handik-booking-app' ),
				'card' => __( 'Card', 'handik-booking-app' ),
			),
			'tips_well' => array(
				'always' => __( 'Always', 'handik-booking-app' ),
				'sometimes' => __( 'Sometimes', 'handik-booking-app' ),
				'never' => __( 'Never', 'handik-booking-app' ),
			),
			'payment_on_time' => array(
				'on_time' => __( 'On time', 'handik-booking-app' ),
				'sometimes_late' => __( 'Sometimes late', 'handik-booking-app' ),
				'chronically_late' => __( 'Chronically late', 'handik-booking-app' ),
			),
			'building_type' => array(
				'single_family' => __( 'Single family', 'handik-booking-app' ),
				'apartment' => __( 'Apartment', 'handik-booking-app' ),
				'condo' => __( 'Condo', 'handik-booking-app' ),
				'townhouse' => __( 'Townhouse', 'handik-booking-app' ),
				'commercial' => __( 'Commercial', 'handik-booking-app' ),
			),
			'building_age_class' => array(
				'pre_1978_lead_paint' => __( 'Pre-1978 (lead paint awareness)', 'handik-booking-app' ),
				'modern' => __( 'Modern', 'handik-booking-app' ),
			),
			'parking' => array(
				'driveway' => __( 'Driveway', 'handik-booking-app' ),
				'street_free' => __( 'Street (free)', 'handik-booking-app' ),
				'street_metered' => __( 'Street (metered)', 'handik-booking-app' ),
				'building_lot' => __( 'Building lot', 'handik-booking-app' ),
				'specific_spot' => __( 'Specific spot', 'handik-booking-app' ),
			),
		);
	}

	/**
	 * Classify a booking row by source using ONLY its FK columns — no DB
	 * queries. Cheap enough to call per-row in a list render. Mirrors the
	 * (heavier) resolution `for_booking()` does, minus external_unmatched
	 * vs external distinction (both report `external` here since telling
	 * them apart needs the raw payload).
	 *
	 * @param array<string,mixed> $row handik_bookings row.
	 * @return string One of the SOURCE_* constants (never external_unmatched).
	 */
	public static function source_for_row( array $row ) {
		if ( ! empty( $row['job_request_id'] ) ) {
			return self::SOURCE_MAIN;
		}
		if ( ! empty( $row['direct_request_id'] ) ) {
			return self::SOURCE_DIRECT;
		}
		if ( ! empty( $row['project_work_day_id'] ) ) {
			return self::SOURCE_PROJECT;
		}
		return self::SOURCE_EXTERNAL;
	}

	/**
	 * Human label for a source key.
	 *
	 * @param string $source SOURCE_* key.
	 * @return string
	 */
	public static function source_label( $source ) {
		switch ( $source ) {
			case self::SOURCE_MAIN:
				return __( 'Main SPA', 'handik-booking-app' );
			case self::SOURCE_DIRECT:
				return __( 'Direct form', 'handik-booking-app' );
			case self::SOURCE_PROJECT:
				return __( 'Project form', 'handik-booking-app' );
			case self::SOURCE_EXTERNAL:
			case self::SOURCE_EXTERNAL_UNMATCHED:
				return __( 'External Cal', 'handik-booking-app' );
			default:
				return ucfirst( (string) $source );
		}
	}

	/**
	 * Build the admin Customer-profile URL for a contact id.
	 *
	 * @param int $contact_id Contact id.
	 * @return string
	 */
	public static function profile_url( $contact_id ) {
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return '';
		}
		return add_query_arg(
			array(
				'page'       => self::CUSTOMER_PAGE_SLUG,
				'contact_id' => $contact_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param string $raw_webhook_json Stored Cal webhook payload.
	 * @return array<string, mixed>|null
	 */
	protected function synthesize_external_contact( $raw_webhook_json ) {
		$decoded = json_decode( (string) $raw_webhook_json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$name  = '';
		$email = '';
		if ( isset( $decoded['attendees'][0] ) && is_array( $decoded['attendees'][0] ) ) {
			$name  = (string) ( $decoded['attendees'][0]['name'] ?? '' );
			$email = (string) ( $decoded['attendees'][0]['email'] ?? '' );
		}
		if ( '' === $name && ! empty( $decoded['responses']['name']['value'] ) ) {
			$name = (string) $decoded['responses']['name']['value'];
		}
		if ( '' === $email && ! empty( $decoded['responses']['email']['value'] ) ) {
			$email = (string) $decoded['responses']['email']['value'];
		}
		if ( '' === $name && '' === $email ) {
			return null;
		}
		return array(
			'id'        => 0,
			'full_name' => $name ?: $email,
			'email'     => $email,
			'phone'     => '',
			'_external' => true,
		);
	}

	/**
	 * Pretty-print a US E.164 phone for display, falling back to raw.
	 *
	 * @param string $phone E.164 phone.
	 * @return string
	 */
	protected function format_phone_display( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( 10 === strlen( $digits ) ) {
			return sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) );
		}
		return (string) $phone;
	}
}
