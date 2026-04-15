<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Auth_Service {
	const COOKIE_NAME = 'handik_booking_app_client';

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var Handik_Booking_App_Contacts_Service
	 */
	protected $contacts;

	/**
	 * @var Handik_Booking_App_Addresses_Service
	 */
	protected $addresses;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @param Handik_Booking_App_Settings             $settings Settings.
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Contacts_Service     $contacts Contacts.
	 * @param Handik_Booking_App_Addresses_Service    $addresses Addresses.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 */
	public function __construct( $settings, $logger, $contacts, $addresses, $job_requests ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->job_requests = $job_requests;
	}

	/**
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @return array<string, mixed>
	 */
	public function request_code( $email, $phone, $redirect = '' ) {
		$email = sanitize_email( $email );
		$phone = $this->contacts->normalize_phone( $phone );
		$redirect = $redirect ? esc_url_raw( $redirect ) : home_url( '/' );

		if ( ! $email && ! $phone ) {
			return array( 'error' => __( 'Email or phone is required.', 'handik-booking-app' ), 'status' => 400 );
		}

		$limit = $this->rate_limit( $email ? $email : $phone );
		if ( is_wp_error( $limit ) ) {
			return array( 'error' => $limit->get_error_message(), 'status' => 429 );
		}

		$contact = $this->contacts->find_by_email_or_phone( $email, $phone );
		if ( ! $contact ) {
			return array(
				'success' => true,
				'message' => __( 'If we found a matching client, a one-time code has been sent.', 'handik-booking-app' ),
			);
		}

		$target_email = ! empty( $contact['email'] ) ? sanitize_email( $contact['email'] ) : $email;
		$target_phone = ! empty( $contact['phone'] ) ? $this->contacts->normalize_phone( $contact['phone'] ) : $phone;
		$issued       = $this->create_token( (int) $contact['id'], $target_email, $target_phone );
		$this->send_message( $contact, $issued['code'], $issued['token'], $target_email, $target_phone, $redirect );

		return array(
			'success' => true,
			'message' => __( 'If we found a matching client, a one-time code has been sent.', 'handik-booking-app' ),
		);
	}

	/**
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @param string $code Code.
	 * @param string $token Token.
	 * @return array<string, mixed>
	 */
	public function verify( $email, $phone, $code, $token = '' ) {
		$record = $this->consume_token( sanitize_email( $email ), $this->contacts->normalize_phone( $phone ), sanitize_text_field( $code ), sanitize_text_field( $token ) );
		if ( ! $record ) {
			return array( 'error' => __( 'Code or magic link is invalid or expired.', 'handik-booking-app' ), 'status' => 403 );
		}

		$contact_id = (int) $record['contact_id'];
		$this->set_cookie( $contact_id );

		return array(
			'success'    => true,
			'contact_id' => $contact_id,
			'profile'    => $this->profile( $contact_id ),
		);
	}

	/**
	 * @return int
	 */
	public function current_contact_id() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return 0;
		}
		$parts = explode( '|', wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		if ( 3 !== count( $parts ) ) {
			return 0;
		}
		list( $contact_id, $expires_at, $signature ) = $parts;
		if ( time() >= (int) $expires_at ) {
			return 0;
		}
		$expected = hash_hmac( 'sha256', $contact_id . '|' . $expires_at, wp_salt( 'auth' ) );
		return hash_equals( $expected, $signature ) ? absint( $contact_id ) : 0;
	}

	/**
	 * @return bool
	 */
	public function maybe_process_magic_link() {
		$token = isset( $_GET['handik_magic_token'] ) ? sanitize_text_field( wp_unslash( $_GET['handik_magic_token'] ) ) : '';
		$ref   = isset( $_GET['handik_redirect'] ) ? esc_url_raw( wp_unslash( $_GET['handik_redirect'] ) ) : home_url( '/' );

		if ( ! $token ) {
			return false;
		}

		$record = $this->consume_token( '', '', '', $token );
		if ( ! $record ) {
			wp_safe_redirect( add_query_arg( 'handik_magic', 'invalid', $ref ) );
			exit;
		}

		$this->set_cookie( (int) $record['contact_id'] );
		wp_safe_redirect( add_query_arg( 'handik_magic', 'success', $ref ) );
		exit;
	}

	/**
	 * @param int $contact_id Contact ID.
	 * @return array<string, mixed>
	 */
	public function profile( $contact_id ) {
		$contact = $this->contacts->get( $contact_id );
		if ( ! $contact ) {
			return array();
		}

		return array(
			'contact'       => $contact,
			'addresses'     => $this->addresses->list_for_contact( $contact_id ),
			'recentRequests' => array_values(
				array_map(
					function ( $request ) {
						return array(
							'id'                => (int) $request['id'],
							'status'            => $request['status'],
							'booking_type'      => $request['booking_type'],
							'assistant_summary' => $request['assistant_summary'],
							'created_at'        => $request['created_at'],
						);
					},
					array_filter(
						$this->job_requests->list_recent( 20 ),
						function ( $request ) use ( $contact_id ) {
							return (int) $request['contact_id'] === (int) $contact_id;
						}
					)
				)
			),
		);
	}

	/**
	 * @param int    $contact_id Contact.
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @return array<string, string>
	 */
	protected function create_token( $contact_id, $email, $phone ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'login_tokens' );
		$code  = (string) wp_rand( 100000, 999999 );
		$token = wp_generate_password( 48, false, false );
		$wpdb->insert(
			$table,
			array(
				'contact_id' => $contact_id,
				'email'      => $email ? $email : null,
				'phone'      => $phone ? $phone : null,
				'code_hash'  => wp_hash_password( $code ),
				'token_hash' => wp_hash_password( $token ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 15 * MINUTE_IN_SECONDS ) ),
			)
		);
		return array( 'code' => $code, 'token' => $token );
	}

	/**
	 * @param string $email Email.
	 * @param string $phone Phone.
	 * @param string $code Code.
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	protected function consume_token( $email, $phone, $code, $token ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'login_tokens' );
		$args  = array( current_time( 'mysql', true ) );
		$where = "WHERE consumed_at IS NULL AND expires_at >= %s";

		if ( $email ) {
			$where .= ' AND email = %s';
			$args[] = $email;
		} elseif ( $phone ) {
			$where .= ' AND phone = %s';
			$args[] = $phone;
		}

		$limit  = ( $token && ! $email && ! $phone ) ? 100 : 5;
		$query  = $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT {$limit}", $args );
		$tokens = $wpdb->get_results( $query, ARRAY_A );

		foreach ( $tokens as $row ) {
			$match = false;
			if ( $code && wp_check_password( $code, $row['code_hash'] ) ) {
				$match = true;
			}
			if ( $token && wp_check_password( $token, $row['token_hash'] ) ) {
				$match = true;
			}
			if ( ! $match ) {
				continue;
			}
			$wpdb->update( $table, array( 'consumed_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ) );
			return $row;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $contact Contact.
	 * @param string               $code Code.
	 * @param string               $token Token.
	 * @param string               $email Email.
	 * @param string               $phone Phone.
	 */
	protected function send_message( array $contact, $code, $token, $email, $phone, $redirect ) {
		$magic_link = add_query_arg(
			array(
				'handik_magic_token' => $token,
				'handik_redirect'    => $redirect,
			),
			$redirect
		);

		if ( $email ) {
			add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
			add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
			wp_mail(
				$email,
				__( 'Your Handik sign-in code', 'handik-booking-app' ),
				sprintf(
					"Hi %s,\n\nYour Handik one-time code is: %s\n\nSign in with this secure link:\n%s\n\nThis code expires in 15 minutes.",
					! empty( $contact['full_name'] ) ? $contact['full_name'] : __( 'there', 'handik-booking-app' ),
					$code,
					$magic_link
				)
			);
			remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
			remove_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
			return;
		}

		do_action( 'handik_booking_app_send_sms_code', $phone, $code, $magic_link, $contact );
	}

	/**
	 * @param string $from From.
	 * @return string
	 */
	public function mail_from( $from ) {
		$custom = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		return $custom ? $custom : $from;
	}

	/**
	 * @param string $name Name.
	 * @return string
	 */
	public function mail_from_name( $name ) {
		$custom = sanitize_text_field( (string) $this->settings->get( 'email_from_name', '' ) );
		return $custom ? $custom : $name;
	}

	/**
	 * @param int $contact_id ID.
	 */
	protected function set_cookie( $contact_id ) {
		$expires_at = time() + DAY_IN_SECONDS;
		$value      = $contact_id . '|' . $expires_at;
		$signature  = hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
		$cookie     = $value . '|' . $signature;

		setcookie(
			self::COOKIE_NAME,
			$cookie,
			array(
				'expires'  => $expires_at,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $cookie;
	}

	/**
	 * @param string $key Key.
	 * @return true|WP_Error
	 */
	protected function rate_limit( $key ) {
		$cache_key = 'handik_booking_app_auth_' . md5( strtolower( $key . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$count     = (int) get_transient( $cache_key );
		if ( $count >= 5 ) {
			return new WP_Error( 'rate_limited', __( 'Too many verification attempts. Please try again later.', 'handik-booking-app' ) );
		}
		set_transient( $cache_key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}
