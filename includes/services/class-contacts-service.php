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
