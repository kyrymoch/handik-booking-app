<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Soft phone gate for Additional Forms presets.
 *
 * Owner workflow: when Alex emails / texts a direct link to one specific
 * preset (e.g. `/booking/large-visit-360/`) and wants to discourage the
 * customer from re-using that link for unrelated jobs, he pre-approves
 * the customer's phone number for that preset in the admin. The Forms
 * SPA, after the OTP step, hits `check_for_phone()` and only shows the
 * "this wasn't pre-approved" warning screen when zero active rows match
 * (preset_slug + phone). Each successful booking consumes one active
 * row via `consume_one_for_phone()` — once consumed, a follow-up booking
 * with the same phone will trip the warning again.
 *
 * This is intentionally a SOFT gate. The customer can always click
 * "Continue anyway" — the operator wants visibility, not a hard block.
 *
 * Statuses:
 *   active    — usable; counts toward check_for_phone().
 *   consumed  — booking already used it; ignored by check_for_phone().
 *   revoked   — operator killed it; ignored.
 *   expired   — past expires_at; ignored. Recomputed on read.
 */
class Handik_Booking_App_Form_Approvals_Service {
	const STATUS_ACTIVE   = 'active';
	const STATUS_CONSUMED = 'consumed';
	const STATUS_REVOKED  = 'revoked';
	const STATUS_EXPIRED  = 'expired';

	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	public function __construct( $contacts, $logger = null ) {
		$this->contacts = $contacts;
		$this->logger   = $logger;
	}

	/**
	 * Insert a new approval row.
	 *
	 * @param string      $preset_slug Preset slug from handik_form_presets.
	 * @param string      $phone       Raw phone (will be normalized to E.164).
	 * @param string      $notes       Operator memo, free-form.
	 * @param string|null $expires_at  ISO datetime or NULL for no expiry.
	 * @return array{success?: true, id?: int, error?: string, status?: int}
	 */
	public function create( $preset_slug, $phone, $notes = '', $expires_at = null, $contact_id = null ) {
		$slug = sanitize_title( (string) $preset_slug );
		if ( '' === $slug ) {
			return array( 'error' => __( 'Preset slug is required.', 'handik-booking-app' ), 'status' => 400 );
		}

		// Sprint 2 — when the admin picked a customer from the search picker,
		// the contact is authoritative: pull the canonical phone off the
		// contact so a stale typed value can't desync the approval from the
		// customer the operator actually chose. When no contact was picked,
		// backfill the link if the phone already matches a known contact.
		$contact_id = (int) $contact_id;
		if ( $contact_id > 0 && $this->contacts ) {
			$contact = $this->contacts->get( $contact_id );
			if ( $contact && ! empty( $contact['phone'] ) ) {
				$phone = (string) $contact['phone'];
			} else {
				$contact_id = 0; // contact vanished — fall back to phone-only
			}
		}

		$phone_e164 = $this->contacts ? $this->contacts->normalize_phone( $phone ) : (string) $phone;
		if ( '' === $phone_e164 ) {
			return array( 'error' => __( 'Phone number is required.', 'handik-booking-app' ), 'status' => 400 );
		}

		if ( $contact_id <= 0 && $this->contacts ) {
			$existing = $this->contacts->find_by_email_or_phone( '', $phone_e164 );
			if ( $existing && ! empty( $existing['id'] ) ) {
				$contact_id = (int) $existing['id'];
			}
		}

		$row = array(
			'preset_slug' => $slug,
			'phone'       => $phone_e164,
			'contact_id'  => $contact_id > 0 ? $contact_id : null,
			'notes'       => sanitize_textarea_field( (string) $notes ),
			'created_by'  => (int) get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
			'status'      => self::STATUS_ACTIVE,
		);
		if ( null !== $expires_at && '' !== $expires_at ) {
			$ts = strtotime( (string) $expires_at );
			if ( false !== $ts ) {
				$row['expires_at'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_approvals' );
		$ok    = $wpdb->insert( $table, $row );
		if ( false === $ok ) {
			if ( $this->logger ) {
				$this->logger->error( 'Failed to insert form approval.', array( 'preset_slug' => $slug ) );
			}
			return array( 'error' => __( 'Failed to save approval.', 'handik-booking-app' ), 'status' => 500 );
		}
		$id = (int) $wpdb->insert_id;
		if ( $this->logger ) {
			$this->logger->info(
				'Form approval created.',
				array( 'id' => $id, 'preset_slug' => $slug, 'phone' => $phone_e164, 'contact_id' => $contact_id )
			);
		}
		return array( 'success' => true, 'id' => $id );
	}

	/**
	 * Mark an approval as revoked. Idempotent.
	 *
	 * @param int $id Approval id.
	 * @return array{success?: true, error?: string, status?: int}
	 */
	public function revoke( $id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_approvals' );
		$ok    = $wpdb->update(
			$table,
			array( 'status' => self::STATUS_REVOKED ),
			array( 'id' => (int) $id )
		);
		if ( false === $ok ) {
			return array( 'error' => __( 'Failed to revoke approval.', 'handik-booking-app' ), 'status' => 500 );
		}
		if ( $this->logger ) {
			$this->logger->info( 'Form approval revoked.', array( 'id' => (int) $id ) );
		}
		return array( 'success' => true );
	}

	/**
	 * Count active approvals for (preset_slug, phone). Expired rows are
	 * auto-flipped to STATUS_EXPIRED as a side effect so admin views show
	 * accurate status without a separate cron pass.
	 *
	 * @param string $preset_slug Preset slug.
	 * @param string $phone       Phone (will be normalized).
	 * @return int Active count.
	 */
	public function count_active_for_phone( $preset_slug, $phone ) {
		$slug       = sanitize_title( (string) $preset_slug );
		$phone_e164 = $this->contacts ? $this->contacts->normalize_phone( $phone ) : (string) $phone;
		if ( '' === $slug || '' === $phone_e164 ) {
			return 0;
		}

		$this->expire_stale_for_phone( $slug, $phone_e164 );

		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_approvals' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE preset_slug = %s AND phone = %s AND status = %s",
				$slug,
				$phone_e164,
				self::STATUS_ACTIVE
			)
		);
		return $count;
	}

	/**
	 * Lock-and-consume the oldest active approval for (preset_slug, phone).
	 * Returns the consumed row id, or 0 if nothing was active. The conditional
	 * UPDATE makes the transition atomic — two concurrent captures cannot
	 * consume the same row.
	 *
	 * @param string $preset_slug Preset slug.
	 * @param string $phone       Phone (will be normalized).
	 * @param int    $booking_id  handik_bookings.id of the consuming booking.
	 * @return int Consumed row id, 0 if none consumed.
	 */
	public function consume_one_for_phone( $preset_slug, $phone, $booking_id = 0 ) {
		$slug       = sanitize_title( (string) $preset_slug );
		$phone_e164 = $this->contacts ? $this->contacts->normalize_phone( $phone ) : (string) $phone;
		if ( '' === $slug || '' === $phone_e164 ) {
			return 0;
		}

		$this->expire_stale_for_phone( $slug, $phone_e164 );

		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_approvals' );

		// Oldest first so revoking the most recent doesn't burn the
		// already-issued one.
		$candidate = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE preset_slug = %s AND phone = %s AND status = %s ORDER BY created_at ASC, id ASC LIMIT 1",
				$slug,
				$phone_e164,
				self::STATUS_ACTIVE
			)
		);
		if ( $candidate <= 0 ) {
			return 0;
		}

		$now     = current_time( 'mysql' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, consumed_at = %s, consumed_booking_id = %d WHERE id = %d AND status = %s",
				self::STATUS_CONSUMED,
				$now,
				(int) $booking_id,
				$candidate,
				self::STATUS_ACTIVE
			)
		);
		if ( 1 !== (int) $updated ) {
			// Lost the race to another consumer — try once more.
			return $this->consume_one_for_phone( $preset_slug, $phone, $booking_id );
		}
		if ( $this->logger ) {
			$this->logger->info(
				'Form approval consumed.',
				array(
					'id'          => $candidate,
					'preset_slug' => $slug,
					'phone'       => $phone_e164,
					'booking_id'  => (int) $booking_id,
				)
			);
		}
		return $candidate;
	}

	/**
	 * List approvals for admin tables. Filter by preset_slug or status.
	 *
	 * @param array{preset_slug?: string, status?: string} $filter Filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_filtered( array $filter = array() ) {
		global $wpdb;
		$table  = Handik_Booking_App_DB::table( 'form_approvals' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filter['preset_slug'] ) ) {
			$where[]  = 'preset_slug = %s';
			$params[] = sanitize_title( (string) $filter['preset_slug'] );
		}
		if ( ! empty( $filter['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $filter['status'] );
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC';
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 2.1.30.1 — operator-facing self-test. Exercises the full lifecycle
	 * (create x2, count, count-with-other-phone, consume, count, revoke,
	 * count, phone-normalization-parity) against a synthetic slug + phone
	 * pair that won't collide with real data. Cleans up after itself.
	 * Surfaced via a "Run self-test" button under the Pre-approvals block
	 * in admin → Additional Forms → Presets so the operator can verify
	 * the gate end-to-end without booking through the customer flow.
	 *
	 * @return array<int, array{step: string, pass: bool, detail: string}>
	 */
	public function self_test() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_approvals' );
		$slug  = '__handik_self_test__';
		$phone = '+15555550199';
		$other = '+15555550100';
		$results = array();

		$record = function ( $step, $pass, $detail = '' ) use ( &$results ) {
			$results[] = array(
				'step'   => (string) $step,
				'pass'   => (bool) $pass,
				'detail' => (string) $detail,
			);
		};

		// Cleanup any prior run so the test is hermetic.
		$wpdb->delete( $table, array( 'preset_slug' => $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$a1 = $this->create( $slug, $phone, 'self-test 1' );
		$a2 = $this->create( $slug, $phone, 'self-test 2' );
		$record(
			'create two approvals',
			! empty( $a1['id'] ) && ! empty( $a2['id'] ),
			'a1=' . wp_json_encode( $a1 ) . ' a2=' . wp_json_encode( $a2 )
		);

		$count = $this->count_active_for_phone( $slug, $phone );
		$record( 'count active for matched phone == 2', 2 === $count, 'count=' . $count );

		$other_count = $this->count_active_for_phone( $slug, $other );
		$record( 'count active for other phone == 0', 0 === $other_count, 'count=' . $other_count );

		$consumed_id = $this->consume_one_for_phone( $slug, $phone, 0 );
		$record( 'consume_one_for_phone returns id', $consumed_id > 0, 'id=' . $consumed_id );

		$count_after_consume = $this->count_active_for_phone( $slug, $phone );
		$record( 'count active after one consume == 1', 1 === $count_after_consume, 'count=' . $count_after_consume );

		// Phone normalization parity — raw + dashes should match E.164.
		$count_via_raw = $this->count_active_for_phone( $slug, '1-555-555-0199' );
		$record( 'phone normalization parity', 1 === $count_via_raw, 'raw_match=' . $count_via_raw );

		// Revoke remaining active rows.
		$active_rows = $this->list_filtered( array( 'preset_slug' => $slug, 'status' => self::STATUS_ACTIVE ) );
		foreach ( $active_rows as $row ) {
			$this->revoke( (int) $row['id'] );
		}
		$count_after_revoke = $this->count_active_for_phone( $slug, $phone );
		$record( 'count active after revoke == 0', 0 === $count_after_revoke, 'count=' . $count_after_revoke );

		// Idempotent revoke.
		if ( ! empty( $a1['id'] ) ) {
			$repeat = $this->revoke( (int) $a1['id'] );
			$record( 'revoke is idempotent', ! empty( $repeat['success'] ), wp_json_encode( $repeat ) );
		}

		// Final cleanup — pull every row we touched.
		$wpdb->delete( $table, array( 'preset_slug' => $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $results;
	}

	/**
	 * Bulk-flip stale rows to STATUS_EXPIRED for a single (slug, phone). We
	 * limit the rewrite to the rows the check / consume call is about to
	 * read so the runtime cost stays O(rows for this phone) instead of
	 * scanning the full table on every booking.
	 *
	 * @param string $preset_slug Preset slug (already sanitized).
	 * @param string $phone_e164  Normalized phone.
	 * @return void
	 */
	protected function expire_stale_for_phone( $preset_slug, $phone_e164 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_approvals' );
		$now   = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE preset_slug = %s AND phone = %s AND status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				self::STATUS_EXPIRED,
				$preset_slug,
				$phone_e164,
				self::STATUS_ACTIVE,
				$now
			)
		);
	}
}
