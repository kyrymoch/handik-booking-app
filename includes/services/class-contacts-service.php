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
}
