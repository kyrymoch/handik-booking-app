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

		$this->logger->info(
			'Returning client code requested.',
			array(
				'email_present'      => (bool) $email,
				'phone_present'      => (bool) $phone,
				'normalized_phone'   => $phone,
				'redirect_provided'  => (bool) $redirect,
			)
		);

		if ( ! $email && ! $phone ) {
			$this->logger->error( 'Returning client code request rejected: missing email and phone.' );
			return array( 'error' => (string) $this->settings->get( 'ui_error_phone_or_email_required', __( 'Email or phone is required.', 'handik-booking-app' ) ), 'status' => 400 );
		}

		$limit = $this->rate_limit( $email ? $email : $phone );
		if ( is_wp_error( $limit ) ) {
			$this->logger->error(
				'Returning client code request rate limited.',
				array(
					'email_present'    => (bool) $email,
					'normalized_phone' => $phone,
					'error'            => $limit->get_error_message(),
				)
			);
			return array( 'error' => $limit->get_error_message(), 'status' => 429 );
		}

		$contact = $this->contacts->find_by_email_or_phone( $email, $phone );
		if ( ! $contact ) {
			$this->logger->info(
				'Returning client code request: no matching contact found.',
				array(
					'email_present'    => (bool) $email,
					'normalized_phone' => $phone,
				)
			);
			return array(
				'success' => true,
				'message' => __( 'If we found a matching client, a one-time code has been sent.', 'handik-booking-app' ),
			);
		}

		$target_email = ! empty( $contact['email'] ) ? sanitize_email( $contact['email'] ) : $email;
		$target_phone = ! empty( $contact['phone'] ) ? $this->contacts->normalize_phone( $contact['phone'] ) : $phone;

		$this->logger->info(
			'Returning client code request matched contact.',
			array(
				'contact_id'        => (int) $contact['id'],
				'target_email'      => (bool) $target_email,
				'target_phone'      => (bool) $target_phone,
				'normalized_phone'  => $target_phone,
			)
		);

		if ( $phone ) {
			if ( ! $this->twilio_verify_configured() ) {
				$this->logger->error(
					'Twilio Verify is not configured for phone verification.',
					array(
						'requested_phone' => $phone,
						'contact_id'      => (int) $contact['id'],
					)
				);
				return array(
					'error'  => __( 'Phone verification is not configured yet. Enter your email instead, or finish Twilio Verify setup in Integrations.', 'handik-booking-app' ),
					'status' => 400,
				);
			}

			$this->logger->info(
				'Returning client code request using Twilio Verify SMS.',
				array(
					'contact_id'       => (int) $contact['id'],
					'normalized_phone' => $target_phone,
				)
			);
			return $this->start_twilio_phone_verification( (int) $contact['id'], $target_phone );
		}

		$this->logger->info(
			'Returning client code request using email verification.',
			array(
				'contact_id'    => (int) $contact['id'],
				'email_present' => (bool) $target_email,
			)
		);
		$issued = $this->create_token( (int) $contact['id'], $target_email, $target_phone );
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
		$email = sanitize_email( $email );
		$phone = $this->contacts->normalize_phone( $phone );
		$code  = sanitize_text_field( $code );
		$token = sanitize_text_field( $token );

		$this->logger->info(
			'Returning client verify requested.',
			array(
				'email_present'    => (bool) $email,
				'phone_present'    => (bool) $phone,
				'normalized_phone' => $phone,
				'code_present'     => (bool) $code,
				'token_present'    => (bool) $token,
				'twilio_enabled'   => $this->twilio_verify_configured(),
			)
		);

		if ( $phone && $this->twilio_verify_configured() ) {
			$twilio = $this->check_twilio_phone_verification( $phone, $code );
			if ( ! empty( $twilio['error'] ) ) {
				return $twilio;
			}

			$contact = $this->contacts->find_by_email_or_phone( $email, $phone );
			if ( ! $contact ) {
				return array( 'error' => (string) $this->settings->get( 'ui_error_invalid_code', __( 'Code or magic link is invalid or expired.', 'handik-booking-app' ) ), 'status' => 403 );
			}

			$contact_id = (int) $contact['id'];
			$this->set_cookie( $contact_id );

			return array(
				'success'    => true,
				'contact_id' => $contact_id,
				'profile'    => $this->profile( $contact_id ),
			);
		}

		$record = $this->consume_token( $email, $phone, $code, $token );
		if ( ! $record ) {
			return array( 'error' => (string) $this->settings->get( 'ui_error_invalid_code', __( 'Code or magic link is invalid or expired.', 'handik-booking-app' ) ), 'status' => 403 );
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
	 * @param int  $contact_id Contact ID.
	 * @param bool $for_lookup Whether this is an unauthenticated phone lookup payload.
	 * @return array<string, mixed>
	 */
	public function profile( $contact_id, $for_lookup = false ) {
		$contact = $this->contacts->get( $contact_id );
		if ( ! $contact ) {
			return array();
		}

		if ( $for_lookup ) {
			$addresses = array_values(
				array_map(
					function ( $address ) {
						return array(
							'id'             => (int) ( $address['id'] ?? 0 ),
							'address_full'   => sanitize_text_field( $address['address_full'] ?? '' ),
							'address_line_1' => sanitize_text_field( $address['address_line_1'] ?? '' ),
							'address_unit'   => sanitize_text_field( $address['address_unit'] ?? '' ),
							'city'           => sanitize_text_field( $address['city'] ?? '' ),
							'state'          => sanitize_text_field( $address['state'] ?? '' ),
							'zip_code'       => sanitize_text_field( $address['zip_code'] ?? '' ),
						);
					},
					$this->addresses->list_for_contact( $contact_id )
				)
			);

			return array(
				'contact'   => array(
					'id'        => (int) ( $contact['id'] ?? 0 ),
					'full_name' => sanitize_text_field( $contact['full_name'] ?? '' ),
					'email'     => sanitize_email( $contact['email'] ?? '' ),
					'phone'     => sanitize_text_field( $contact['phone'] ?? '' ),
				),
				'addresses' => $addresses,
			);
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
					$this->job_requests->list_recent_for_contact( $contact_id, 20 )
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
			$this->logger->info(
				'Returning client email verification message sending.',
				array(
					'contact_id'    => (int) ( $contact['id'] ?? 0 ),
					'email_present' => (bool) $email,
				)
			);
			add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
			add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );

			$customer_name = ! empty( $contact['full_name'] ) ? (string) $contact['full_name'] : __( 'there', 'handik-booking-app' );
			$placeholders = array(
				'customer_name' => $customer_name,
				'magic_link'    => $magic_link,
				'code'          => $code,
				'site_name'     => (string) get_bloginfo( 'name' ),
			);

			$default_subject = __( 'Your Handik sign-in code', 'handik-booking-app' );
			$default_body    = "Hi {{customer_name}},\n\nYour Handik one-time code is: {{code}}\n\nSign in with this secure link:\n{{magic_link}}\n\nThis code expires in 15 minutes.";

			$subject_template = trim( (string) $this->settings->get( 'magic_link_email_subject', '' ) );
			$body_template    = trim( (string) $this->settings->get( 'magic_link_email_body', '' ) );
			$subject = '' !== $subject_template ? $subject_template : $default_subject;
			$body    = '' !== $body_template    ? $body_template    : $default_body;

			foreach ( $placeholders as $key => $value ) {
				$subject = str_replace( '{{' . $key . '}}', (string) $value, $subject );
				$body    = str_replace( '{{' . $key . '}}', (string) $value, $body );
			}

			wp_mail( $email, $subject, $body );
			remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
			remove_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
			return;
		}

		$this->logger->info(
			'Returning client SMS verification delegated to custom hook.',
			array(
				'contact_id'       => (int) ( $contact['id'] ?? 0 ),
				'normalized_phone' => $phone,
			)
		);
		do_action( 'handik_booking_app_send_sms_code', $phone, $code, $magic_link, $contact );
	}

	/**
	 * @return bool
	 */
	protected function twilio_verify_configured() {
		return (bool) (
			trim( (string) $this->settings->get( 'twilio_account_sid', '' ) ) &&
			trim( (string) $this->settings->get( 'twilio_auth_token', '' ) ) &&
			trim( (string) $this->settings->get( 'twilio_verify_service_sid', '' ) )
		);
	}

	/**
	 * @param int    $contact_id Contact ID.
	 * @param string $phone Phone.
	 * @return array<string, mixed>
	 */
	protected function start_twilio_phone_verification( $contact_id, $phone ) {
		$this->logger->info(
			'Twilio Verify SMS start requested.',
			array(
				'contact_id'       => $contact_id,
				'normalized_phone' => $phone,
				'service_sid'      => $this->masked_service_sid(),
			)
		);

		$response = $this->twilio_verify_request(
			'Verifications',
			array(
				'To'      => $phone,
				'Channel' => 'sms',
			)
		);

		if ( ! empty( $response['error'] ) ) {
			$this->logger->error(
				'Twilio Verify SMS start failed.',
				array(
					'contact_id' => $contact_id,
					'phone'      => $phone,
					'error'      => $response['error'],
				)
			);
			return $response;
		}

		$this->logger->info(
			'Twilio Verify SMS started.',
			array(
				'contact_id' => $contact_id,
				'phone'      => $phone,
				'status'     => $response['status'] ?? '',
				'sid'        => $response['sid'] ?? '',
			)
		);

		return array(
			'success' => true,
			'message' => __( 'If we found a matching client, a one-time code has been sent.', 'handik-booking-app' ),
		);
	}

	/**
	 * @param string $phone Phone.
	 * @param string $code Code.
	 * @return array<string, mixed>
	 */
	protected function check_twilio_phone_verification( $phone, $code ) {
		$this->logger->info(
			'Twilio Verify SMS check requested.',
			array(
				'normalized_phone' => $phone,
				'code_present'     => (bool) $code,
				'service_sid'      => $this->masked_service_sid(),
			)
		);

		$response = $this->twilio_verify_request(
			'VerificationCheck',
			array(
				'To'   => $phone,
				'Code' => $code,
			)
		);

		if ( ! empty( $response['error'] ) ) {
			$this->logger->error(
				'Twilio Verify SMS check failed.',
				array(
					'phone' => $phone,
					'error' => $response['error'],
				)
			);
			return $response;
		}

		if ( 'approved' !== strtolower( (string) ( $response['status'] ?? '' ) ) ) {
			return array( 'error' => (string) $this->settings->get( 'ui_error_invalid_code', __( 'Code or magic link is invalid or expired.', 'handik-booking-app' ) ), 'status' => 403 );
		}

		$this->logger->info(
			'Twilio Verify SMS approved.',
			array(
				'phone'  => $phone,
				'status' => $response['status'] ?? '',
				'sid'    => $response['sid'] ?? '',
			)
		);

		return array( 'success' => true );
	}

	/**
	 * @param string               $resource Resource.
	 * @param array<string,string> $body Body.
	 * @return array<string, mixed>
	 */
	protected function twilio_verify_request( $resource, array $body ) {
		$account_sid = trim( (string) $this->settings->get( 'twilio_account_sid', '' ) );
		$auth_token  = trim( (string) $this->settings->get( 'twilio_auth_token', '' ) );
		$service_sid = trim( (string) $this->settings->get( 'twilio_verify_service_sid', '' ) );

		if ( ! $account_sid || ! $auth_token || ! $service_sid ) {
			$this->logger->error(
				'Twilio Verify request skipped: missing configuration.',
				array(
					'has_account_sid' => (bool) $account_sid,
					'has_auth_token'  => (bool) $auth_token,
					'has_service_sid' => (bool) $service_sid,
					'resource'        => $resource,
				)
			);
			return array(
				'error'  => __( 'Twilio Verify is not configured.', 'handik-booking-app' ),
				'status' => 400,
			);
		}

		$url      = sprintf( 'https://verify.twilio.com/v2/Services/%1$s/%2$s', rawurlencode( $service_sid ), rawurlencode( $resource ) );
		$this->logger->info(
			'Twilio Verify HTTP request started.',
			array(
				'resource'        => $resource,
				'service_sid'     => $this->masked_service_sid(),
				'body_keys'       => array_keys( $body ),
				'to'              => isset( $body['To'] ) ? $body['To'] : '',
				'channel'         => isset( $body['Channel'] ) ? $body['Channel'] : '',
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'httpversion' => '1.1', // Sprint 1 E3: keep-alive for Twilio.
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $auth_token ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'Twilio Verify HTTP request failed before response.',
				array(
					'resource'    => $resource,
					'service_sid' => $this->masked_service_sid(),
					'error'       => $response->get_error_message(),
				)
			);
			return array(
				'error'  => $response->get_error_message(),
				'status' => 502,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body   = is_array( $body ) ? $body : array();

		$this->logger->info(
			'Twilio Verify HTTP response received.',
			array(
				'resource'        => $resource,
				'service_sid'     => $this->masked_service_sid(),
				'status'          => $status,
				'twilio_status'   => isset( $body['status'] ) ? (string) $body['status'] : '',
				'twilio_sid'      => isset( $body['sid'] ) ? (string) $body['sid'] : '',
				'twilio_message'  => isset( $body['message'] ) ? (string) $body['message'] : '',
				'twilio_code'     => isset( $body['code'] ) ? (string) $body['code'] : '',
			)
		);

		if ( $status < 200 || $status >= 300 ) {
			$message = '';
			if ( ! empty( $body['message'] ) ) {
				$message = (string) $body['message'];
			} elseif ( ! empty( $body['detail'] ) ) {
				$message = (string) $body['detail'];
			}
			return array(
				'error'  => $message ? $message : __( 'Twilio Verify request failed.', 'handik-booking-app' ),
				'status' => $status ? $status : 502,
			);
		}

		return $body;
	}

	/**
	 * @return string
	 */
	protected function masked_service_sid() {
		$service_sid = trim( (string) $this->settings->get( 'twilio_verify_service_sid', '' ) );
		if ( strlen( $service_sid ) <= 8 ) {
			return $service_sid;
		}

		return substr( $service_sid, 0, 4 ) . '...' . substr( $service_sid, -4 );
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

	/**
	 * Aggressive rate limit for unauthenticated contact lookup.
	 *
	 * @param string $key Bucket key.
	 * @return true|WP_Error
	 */
	public function rate_limit_lookup( $key ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$cache_key = 'handik_booking_app_lookup_' . md5( strtolower( $key . '|' . $ip ) );
		$count     = (int) get_transient( $cache_key );
		if ( $count >= 5 ) {
			return new WP_Error( 'rate_limited', __( 'Too many lookup attempts. Please try again later.', 'handik-booking-app' ) );
		}
		set_transient( $cache_key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
