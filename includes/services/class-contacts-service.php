<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Contacts_Service {
	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @param Handik_Booking_App_Logger $logger Logger.
	 */
	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	// =====================================================================
	// Customer-level structured attributes (Sprint 3 / migration 1.6.4).
	//
	// Single source of truth for the attribute schema, shared by the
	// sanitizer (admin_update), the admin edit UI, the Customers-list
	// filters, and — later — the notifications language switch (Sprint 8)
	// and pre-visit briefing (Sprint 4). Enums map field => allowed values
	// (first value is the implicit "unset" default and stays ''). Booleans
	// are a flat list. Tags live in `tags_json`.
	// =====================================================================

	/**
	 * @return array<string, array<int, string>> field => allowed enum values.
	 */
	public static function attribute_enums() {
		return array(
			'language'                 => array( '', 'en', 'ru', 'both' ),
			'preferred_channel'        => array( '', 'sms', 'email', 'call', 'no_preference' ),
			'preferred_time'           => array( '', 'morning', 'afternoon', 'evening', 'no_preference' ),
			'payment_method_preferred' => array( '', 'cash', 'venmo', 'zelle', 'check', 'card', 'no_preference' ),
			'tips_well'                => array( '', 'always', 'sometimes', 'never', 'unknown' ),
			'payment_on_time'          => array( '', 'on_time', 'sometimes_late', 'chronically_late', 'unknown' ),
		);
	}

	/**
	 * @return array<int, string> boolean attribute field names.
	 */
	public static function attribute_booleans() {
		return array(
			'do_not_text',
			'requires_invoice',
			'vip',
			'do_not_service',
			'scope_creeper',
			'negotiates_hard',
			'complains_after',
			'eco_friendly_only',
		);
	}

	/**
	 * Normalize a tags input (array OR comma-separated string) to a clean,
	 * de-duped, lower-cased slug-ish array. Tags allow spaces (display) but
	 * are trimmed + collapsed; capped at 30 tags / 40 chars each.
	 *
	 * @param mixed $input Array or comma-separated string.
	 * @return array<int, string>
	 */
	public static function normalize_tags( $input ) {
		if ( is_string( $input ) ) {
			$input = explode( ',', $input );
		}
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $tag ) {
			$tag = trim( preg_replace( '/\s+/', ' ', (string) $tag ) );
			if ( '' === $tag ) {
				continue;
			}
			if ( function_exists( 'mb_substr' ) ) {
				$tag = mb_substr( $tag, 0, 40 );
			} else {
				$tag = substr( $tag, 0, 40 );
			}
			$key = strtolower( $tag );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $tag;
			}
			if ( count( $out ) >= 30 ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * Decode a contact row's tags_json to an array.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @return array<int, string>
	 */
	public static function decode_tags( array $contact ) {
		$raw = $contact['tags_json'] ?? '';
		if ( '' === $raw || null === $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? array_values( array_filter( array_map( 'strval', $decoded ) ) ) : array();
	}

	/**
	 * Top-N tags across all contacts, most-used first — powers the admin
	 * tags-input autocomplete datalist.
	 *
	 * @param int $limit Max tags.
	 * @return array<int, string>
	 */
	public function top_tags( $limit = 20 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );
		if ( ! Handik_Booking_App_DB::column_exists( $table, 'tags_json' ) ) {
			return array();
		}
		$rows = $wpdb->get_col( "SELECT tags_json FROM {$table} WHERE tags_json IS NOT NULL AND tags_json <> '' AND tags_json <> '[]'" );
		$counts = array();
		foreach ( (array) $rows as $json ) {
			$decoded = json_decode( (string) $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $tag ) {
				$tag = (string) $tag;
				if ( '' === $tag ) {
					continue;
				}
				$counts[ $tag ] = ( $counts[ $tag ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, max( 1, (int) $limit ) );
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $contact_id Contact ID.
	 * @return int
	 */
	public function upsert( array $payload, $contact_id = 0 ) {
		global $wpdb;

		$table = Handik_Booking_App_DB::table( 'contacts' );
		$email = ! empty( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		$phone = ! empty( $payload['phone'] ) ? $this->normalize_phone( $payload['phone'] ) : '';
		$row   = $contact_id ? $this->get( $contact_id ) : $this->find_by_email_or_phone( $email, $phone );

		$data = array(
			'first_name'   => ! empty( $payload['first_name'] ) ? sanitize_text_field( $payload['first_name'] ) : '',
			'last_name'    => ! empty( $payload['last_name'] ) ? sanitize_text_field( $payload['last_name'] ) : '',
			'full_name'    => ! empty( $payload['full_name'] ) ? sanitize_text_field( $payload['full_name'] ) : trim( ( $payload['first_name'] ?? '' ) . ' ' . ( $payload['last_name'] ?? '' ) ),
			'email'        => $email ? $email : null,
			'phone'        => $phone ? $phone : null,
			'source'       => ! empty( $payload['source'] ) ? sanitize_key( $payload['source'] ) : 'booking_app',
			'is_returning' => ! empty( $payload['is_returning'] ) ? 1 : 0,
		);

		if ( ! empty( $payload['notes'] ) ) {
			$data['notes'] = sanitize_textarea_field( $payload['notes'] );
		}

		if ( $row ) {
			$merged = array_merge(
				$row,
				array_filter(
					$data,
					function ( $value ) {
						return null !== $value && '' !== $value;
					}
				)
			);
			unset( $merged['id'], $merged['created_at'], $merged['updated_at'] );
			$wpdb->update( $table, $merged, array( 'id' => (int) $row['id'] ) );
			return (int) $row['id'];
		}

		if ( empty( $data['full_name'] ) && empty( $email ) && empty( $phone ) ) {
			return 0;
		}

		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $contact_id Contact ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $contact_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $contact_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Batch fetch — admin list pages used to call get() per row, hitting the
	 * DB N times. Returns a map of id → contact row so callers can resolve
	 * by id without N+1.
	 *
	 * @param array<int, int> $ids Contact ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_many( array $ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table       = Handik_Booking_App_DB::table( 'contacts' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows        = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @return array<string, mixed>|null
	 */
	public function find_by_email_or_phone( $email, $phone ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );

		if ( $email ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", sanitize_email( $email ) ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}

		if ( $phone ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE phone = %s LIMIT 1", $this->normalize_phone( $phone ) ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public function count_all() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Sprint 11 — find groups of contacts that share a phone OR email
	 * (normalized at write time, so a plain equality GROUP BY is correct).
	 * Returns each group as `array{ key: phone|email, members: array<int> }`.
	 * Spam-flagged contacts are included so the operator can also clean them.
	 *
	 * @param int $limit Max groups to return.
	 * @return array<int, array{key: string, kind: string, members: array<int, array<string,mixed>>}>
	 */
	public function find_duplicate_groups( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );
		$limit = max( 1, (int) $limit );
		$out   = array();

		$phone_groups = $wpdb->get_results(
			"SELECT phone, GROUP_CONCAT(id ORDER BY id) AS ids
			 FROM {$table}
			 WHERE phone IS NOT NULL AND phone <> ''
			 GROUP BY phone HAVING COUNT(*) > 1
			 ORDER BY COUNT(*) DESC, MIN(id) ASC LIMIT {$limit}",
			ARRAY_A
		);
		foreach ( (array) $phone_groups as $g ) {
			$ids = array_map( 'intval', explode( ',', (string) $g['ids'] ) );
			$out[] = array(
				'key'     => (string) $g['phone'],
				'kind'    => 'phone',
				'members' => $this->get_many( $ids ),
			);
		}

		$email_groups = $wpdb->get_results(
			"SELECT email, GROUP_CONCAT(id ORDER BY id) AS ids
			 FROM {$table}
			 WHERE email IS NOT NULL AND email <> ''
			 GROUP BY email HAVING COUNT(*) > 1
			 ORDER BY COUNT(*) DESC, MIN(id) ASC LIMIT {$limit}",
			ARRAY_A
		);
		foreach ( (array) $email_groups as $g ) {
			$ids = array_map( 'intval', explode( ',', (string) $g['ids'] ) );
			$key = strtolower( (string) $g['email'] );
			$dup = false;
			foreach ( $out as $existing ) {
				if ( 'phone' === $existing['kind'] && $this->members_overlap( $existing['members'], $ids ) ) {
					$dup = true; break;
				}
			}
			if ( ! $dup ) {
				$out[] = array(
					'key'     => $key,
					'kind'    => 'email',
					'members' => $this->get_many( $ids ),
				);
			}
		}
		return $out;
	}

	protected function members_overlap( array $existing_members, array $ids ) {
		$ex_ids = array_map( static function ( $m ) { return (int) ( $m['id'] ?? 0 ); }, $existing_members );
		return (bool) array_intersect( $ex_ids, $ids );
	}

	/**
	 * Sprint 11 — merge: reparent every child row (addresses / job_requests /
	 * direct_booking_requests / project_scheduling_requests / messages /
	 * bookings.external_contact_id / form_approvals) from $loser_id onto
	 * $winner_id, then hard-delete the loser. Fills empty fields on the
	 * winner from the loser (name / email / notes). Idempotent on a
	 * non-existent loser; rejects merging into self.
	 *
	 * @param int $winner_id Surviving contact id.
	 * @param int $loser_id  Contact id to absorb + delete.
	 * @return bool
	 */
	public function merge_into( $winner_id, $loser_id ) {
		global $wpdb;
		$winner_id = (int) $winner_id;
		$loser_id  = (int) $loser_id;
		if ( $winner_id <= 0 || $loser_id <= 0 || $winner_id === $loser_id ) {
			return false;
		}
		$winner = $this->get( $winner_id );
		$loser  = $this->get( $loser_id );
		if ( ! $winner || ! $loser ) {
			return false;
		}

		// Fill empty winner fields from loser.
		$fill = array();
		foreach ( array( 'full_name', 'first_name', 'last_name', 'email', 'notes', 'birthday' ) as $col ) {
			if ( empty( $winner[ $col ] ) && ! empty( $loser[ $col ] ) ) {
				$fill[ $col ] = $loser[ $col ];
			}
		}
		if ( $fill ) {
			$wpdb->update( Handik_Booking_App_DB::table( 'contacts' ), $fill, array( 'id' => $winner_id ) );
		}

		// Reparent child rows. Each guarded by table_exists so a partial
		// install (Additional Forms tables missing) doesn't fatal.
		$reparent = function ( $short, $col ) use ( $loser_id, $winner_id ) {
			global $wpdb;
			$t = Handik_Booking_App_DB::table( $short );
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				return;
			}
			$wpdb->update( $t, array( $col => $winner_id ), array( $col => $loser_id ) );
		};
		$reparent( 'addresses', 'contact_id' );
		$reparent( 'job_requests', 'contact_id' );
		$reparent( 'messages', 'contact_id' );
		$reparent( 'direct_booking_requests', 'contact_id' );
		$reparent( 'project_scheduling_requests', 'contact_id' );
		$reparent( 'bookings', 'external_contact_id' );
		$reparent( 'form_approvals', 'contact_id' );

		// Hard-delete the loser.
		$wpdb->delete( Handik_Booking_App_DB::table( 'contacts' ), array( 'id' => $loser_id ) );

		if ( $this->logger ) {
			$this->logger->info(
				'Contacts merged.',
				array(
					'winner_id' => $winner_id,
					'loser_id'  => $loser_id,
					'admin_id'  => get_current_user_id(),
					'filled'    => array_keys( $fill ),
				)
			);
		}
		return true;
	}

	public function count_spam() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'contacts' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_spam = 1" );
	}

	/**
	 * Insert a contact manually from the admin (C3). Returns inserted id or 0.
	 *
	 * @param array<string, mixed> $payload Payload with full_name/phone/email/notes.
	 * @return int
	 */
	public function admin_create( array $payload ) {
		global $wpdb;
		$full_name = sanitize_text_field( (string) ( $payload['full_name'] ?? '' ) );
		$phone     = $this->normalize_phone( $payload['phone'] ?? '' );
		$email     = sanitize_email( (string) ( $payload['email'] ?? '' ) );

		if ( '' === $full_name || ( '' === $phone && '' === $email ) ) {
			return 0;
		}

		$table = Handik_Booking_App_DB::table( 'contacts' );
		$wpdb->insert(
			$table,
			array(
				'full_name'    => $full_name,
				'first_name'   => sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) ),
				'last_name'    => sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) ),
				'email'        => $email ? $email : null,
				'phone'        => $phone ? $phone : null,
				'notes'        => isset( $payload['notes'] ) ? sanitize_textarea_field( (string) $payload['notes'] ) : null,
				'source'       => 'admin',
				'is_returning' => 1,
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->logger->info( 'Admin created contact.', array( 'contact_id' => $id, 'admin_id' => get_current_user_id() ) );
		return $id;
	}

	/**
	 * Update editable fields on a contact. Phone changes log a warning.
	 *
	 * @param int                  $contact_id Contact ID.
	 * @param array<string, mixed> $patch      Patch.
	 * @return bool
	 */
	public function admin_update( $contact_id, array $patch ) {
		global $wpdb;
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return false;
		}
		$existing = $this->get( $contact_id );
		if ( ! $existing ) {
			return false;
		}

		$update = array();
		if ( array_key_exists( 'full_name', $patch ) ) {
			$update['full_name'] = sanitize_text_field( (string) $patch['full_name'] );
		}
		if ( array_key_exists( 'email', $patch ) ) {
			$email          = sanitize_email( (string) $patch['email'] );
			$update['email'] = $email ?: null;
		}
		if ( array_key_exists( 'phone', $patch ) ) {
			$phone          = $this->normalize_phone( (string) $patch['phone'] );
			$update['phone'] = $phone ?: null;
			if ( ( $existing['phone'] ?? '' ) !== $update['phone'] ) {
				$this->logger->warning(
					'Admin changed contact phone (returning-client lookup affected).',
					array(
						'contact_id' => $contact_id,
						'admin_id'   => get_current_user_id(),
						'previous'   => (string) $existing['phone'],
						'next'       => (string) $update['phone'],
					)
				);
			}
		}
		if ( array_key_exists( 'notes', $patch ) ) {
			$update['notes'] = sanitize_textarea_field( (string) $patch['notes'] );
		}
		if ( array_key_exists( 'is_returning', $patch ) ) {
			$update['is_returning'] = ! empty( $patch['is_returning'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'is_spam', $patch ) ) {
			$update['is_spam'] = ! empty( $patch['is_spam'] ) ? 1 : 0;
		}

		// Sprint 3 — customer-level structured attributes. Enums are
		// validated against the allowed set (anything else → '' unset);
		// booleans coerced to 0/1; tags normalized + JSON-encoded;
		// brand_preferences is short free text.
		foreach ( self::attribute_enums() as $field => $allowed ) {
			if ( array_key_exists( $field, $patch ) ) {
				$value = sanitize_key( (string) $patch[ $field ] );
				$update[ $field ] = in_array( $value, $allowed, true ) ? $value : '';
			}
		}
		foreach ( self::attribute_booleans() as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$update[ $field ] = ! empty( $patch[ $field ] ) ? 1 : 0;
			}
		}
		if ( array_key_exists( 'brand_preferences', $patch ) ) {
			$update['brand_preferences'] = sanitize_text_field( (string) $patch['brand_preferences'] );
		}
		if ( array_key_exists( 'tags', $patch ) || array_key_exists( 'tags_json', $patch ) ) {
			$tags_input = array_key_exists( 'tags', $patch ) ? $patch['tags'] : $patch['tags_json'];
			$tags       = self::normalize_tags( $tags_input );
			$update['tags_json'] = $tags ? wp_json_encode( $tags ) : null;
		}

		// Sprint 11 — birthday/anniversary. Empty string clears.
		if ( array_key_exists( 'birthday', $patch ) ) {
			$raw = trim( (string) $patch['birthday'] );
			if ( '' === $raw ) {
				$update['birthday'] = null;
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				$update['birthday'] = $raw;
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$wpdb->update( Handik_Booking_App_DB::table( 'contacts' ), $update, array( 'id' => $contact_id ) );
		$this->logger->info(
			'Admin updated contact.',
			array(
				'contact_id' => $contact_id,
				'fields'     => array_keys( $update ),
				'admin_id'   => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * People list (C1). Returns aggregated rows: contact + counts + last_seen.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_people( array $args = array() ) {
		global $wpdb;
		$contacts = Handik_Booking_App_DB::table( 'contacts' );
		$jr       = Handik_Booking_App_DB::table( 'job_requests' );
		$bk       = Handik_Booking_App_DB::table( 'bookings' );
		$ad       = Handik_Booking_App_DB::table( 'addresses' );

		$include_spam  = ! empty( $args['include_spam'] );
		$with_bookings = ! empty( $args['with_bookings'] );
		$drafts_only   = ! empty( $args['drafts_only'] );
		$no_address    = ! empty( $args['no_address'] );
		$search        = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$attr          = isset( $args['attr'] ) ? sanitize_key( (string) $args['attr'] ) : '';
		$tag           = isset( $args['tag'] ) ? trim( (string) $args['tag'] ) : '';
		$limit         = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100;
		$offset        = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$where  = array( '1=1' );
		$params = array();
		if ( ! $include_spam ) {
			$where[] = 'c.is_spam = 0';
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( c.full_name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Sprint 3 — customer-attribute filters. Guarded by column_exists so
		// a pre-migration call (or a failed migration) degrades to "no
		// filter" instead of a SQL error.
		$has_attr_cols = Handik_Booking_App_DB::column_exists( $contacts, 'vip' );
		if ( $has_attr_cols && '' !== $attr ) {
			if ( 'vip' === $attr ) {
				$where[] = 'c.vip = 1';
			} elseif ( 'do_not_service' === $attr ) {
				$where[] = 'c.do_not_service = 1';
			} elseif ( 'language_ru' === $attr ) {
				$where[] = "c.language IN ( 'ru', 'both' )";
			} elseif ( 'do_not_text' === $attr ) {
				$where[] = 'c.do_not_text = 1';
			}
		}
		if ( $has_attr_cols && '' !== $tag ) {
			// tags_json is a JSON array of strings; match the quoted tag so
			// "vip" doesn't match "vip-lite". Case-insensitive LIKE.
			$where[]  = 'c.tags_json LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . $tag . '"' ) . '%';
		}

		$having = array( '1=1' );
		if ( $with_bookings ) {
			$having[] = 'bookings_count > 0';
		}
		if ( $drafts_only ) {
			$having[] = 'bookings_count = 0';
			$having[] = 'drafts_count > 0';
		}
		if ( $no_address ) {
			$having[] = 'addresses_count = 0';
		}

		$sql = "SELECT
				c.*,
				( SELECT COUNT(*) FROM {$jr} WHERE contact_id = c.id ) AS requests_count,
				( SELECT COUNT(*) FROM {$jr} WHERE contact_id = c.id AND status = 'draft' ) AS drafts_count,
				( SELECT COUNT(*) FROM {$bk} bk INNER JOIN {$jr} jr ON jr.id = bk.job_request_id WHERE jr.contact_id = c.id ) AS bookings_count,
				( SELECT COUNT(*) FROM {$ad} WHERE contact_id = c.id AND ( deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' ) ) AS addresses_count,
				GREATEST(
					c.updated_at,
					COALESCE( ( SELECT MAX( updated_at ) FROM {$jr} WHERE contact_id = c.id ), '1970-01-01' ),
					COALESCE( ( SELECT MAX( bk.updated_at ) FROM {$bk} bk INNER JOIN {$jr} jr ON jr.id = bk.job_request_id WHERE jr.contact_id = c.id ), '1970-01-01' )
				) AS last_seen_at
			FROM {$contacts} c
			WHERE " . implode( ' AND ', $where ) . "
			HAVING " . implode( ' AND ', $having ) . "
			ORDER BY last_seen_at DESC
			LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		$sql = $wpdb->prepare( $sql, $params );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $phone Phone.
	 * @return string
	 */
	public function normalize_phone( $phone ) {
		$raw    = trim( (string) $phone );
		$digits = preg_replace( '/\D+/', '', $raw );

		if ( empty( $digits ) ) {
			return '';
		}

		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}

		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			return '+' . $digits;
		}

		if ( '+' === substr( $raw, 0, 1 ) ) {
			return '+' . $digits;
		}

		return '+' . $digits;
	}

	/**
	 * Sprint 12 — drop a single contact row. Solo low-level step the
	 * Cascade_Delete_Service calls last after wiping all child rows.
	 *
	 * @param int $contact_id ID.
	 * @return bool
	 */
	public function delete_hard_solo( $contact_id ) {
		global $wpdb;
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return false;
		}
		$table = Handik_Booking_App_DB::table( 'contacts' );
		$ok = $wpdb->delete( $table, array( 'id' => $contact_id ), array( '%d' ) );
		return false !== $ok && $ok > 0;
	}

	/**
	 * Sprint 12 — pre-flight counts for the admin confirm modal so it
	 * can list every dependent table at a glance:
	 *   "Deletes 3 addresses, 7 requests (and their 12 messages /
	 *   2 bookings / 5 photos), 1 project schedule, 4 login tokens."
	 *
	 * @param int $contact_id ID.
	 * @return array<string, int>
	 */
	public function count_dependents( $contact_id ) {
		global $wpdb;
		$contact_id = (int) $contact_id;
		$out = array(
			'addresses'                    => 0,
			'job_requests'                 => 0,
			'bookings'                     => 0,
			'messages'                     => 0,
			'photos'                       => 0,
			'login_tokens'                 => 0,
			'direct_booking_requests'      => 0,
			'project_scheduling_requests'  => 0,
			'project_work_days'            => 0,
		);
		if ( $contact_id <= 0 ) {
			return $out;
		}
		$addresses        = Handik_Booking_App_DB::table( 'addresses' );
		$requests         = Handik_Booking_App_DB::table( 'job_requests' );
		$bookings         = Handik_Booking_App_DB::table( 'bookings' );
		$messages         = Handik_Booking_App_DB::table( 'messages' );
		$login_tokens     = Handik_Booking_App_DB::table( 'login_tokens' );
		$direct_requests  = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$project_requests = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$work_days        = Handik_Booking_App_DB::table( 'project_work_days' );

		$out['addresses']                    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$addresses} WHERE contact_id = %d", $contact_id ) );
		$out['job_requests']                 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$requests} WHERE contact_id = %d", $contact_id ) );
		$out['login_tokens']                 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$login_tokens} WHERE contact_id = %d", $contact_id ) );
		$out['direct_booking_requests']      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$direct_requests} WHERE contact_id = %d", $contact_id ) );
		$out['project_scheduling_requests']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$project_requests} WHERE contact_id = %d", $contact_id ) );

		// Aggregate child counts via JOIN through the request id.
		$out['bookings'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$bookings} b INNER JOIN {$requests} r ON b.job_request_id = r.id WHERE r.contact_id = %d",
			$contact_id
		) );
		$out['messages'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$messages} m INNER JOIN {$requests} r ON m.request_id = r.id WHERE r.contact_id = %d",
			$contact_id
		) );

		// project_work_days through scheduling_request join.
		if ( $out['project_scheduling_requests'] > 0 ) {
			$out['project_work_days'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$work_days} d INNER JOIN {$project_requests} pr ON d.scheduling_request_id = pr.id WHERE pr.contact_id = %d",
				$contact_id
			) );
		}

		// Photos: walk requests to sum photos arrays. For now the
		// admin confirm modal can render a coarse "N requests" line
		// and skip a granular photo count (would require N round
		// trips to hydrate each request's photos_json).
		return $out;
	}
}
