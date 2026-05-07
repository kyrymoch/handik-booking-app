<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Controller {
	/**
	 * @var Handik_Booking_App_State
	 */
	protected $state;

	/**
	 * @var Handik_Booking_App_Schema
	 */
	protected $schema;

	/**
	 * @var Handik_Booking_App_Upload_Service
	 */
	protected $upload_service;

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Appearance_Service
	 */
	protected $appearance;

	/**
	 * @var Handik_Booking_App_Auth_Service
	 */
	protected $auth;

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
	 * @var Handik_Booking_App_Bookings_Service
	 */
	protected $bookings;

	/**
	 * @var Handik_Booking_App_Routing_Service
	 */
	protected $routing;

	/**
	 * @var Handik_Booking_App_Cal_Service
	 */
	protected $cal;

	/**
	 * @var Handik_Booking_App_Changelog_Service
	 */
	protected $changelog;

	/**
	 * @param Handik_Booking_App_State                 $state State.
	 * @param Handik_Booking_App_Schema                $schema Schema.
	 * @param Handik_Booking_App_Upload_Service        $upload_service Uploads.
	 * @param Handik_Booking_App_Settings              $settings Settings.
	 * @param Handik_Booking_App_Appearance_Service    $appearance Appearance.
	 * @param Handik_Booking_App_Auth_Service          $auth Auth.
	 * @param Handik_Booking_App_Contacts_Service      $contacts Contacts.
	 * @param Handik_Booking_App_Addresses_Service     $addresses Addresses.
	 * @param Handik_Booking_App_Job_Requests_Service  $job_requests Requests.
	 * @param Handik_Booking_App_Bookings_Service      $bookings Bookings.
	 * @param Handik_Booking_App_Routing_Service       $routing Routing.
	 * @param Handik_Booking_App_Cal_Service           $cal Cal.
	 * @param Handik_Booking_App_Changelog_Service     $changelog Changelog.
	 */
	public function __construct( $state, $schema, $upload_service, $settings, $appearance, $auth, $contacts, $addresses, $job_requests, $bookings, $routing, $cal, $changelog ) {
		$this->state          = $state;
		$this->schema         = $schema;
		$this->upload_service = $upload_service;
		$this->settings       = $settings;
		$this->appearance     = $appearance;
		$this->auth           = $auth;
		$this->contacts       = $contacts;
		$this->addresses      = $addresses;
		$this->job_requests   = $job_requests;
		$this->bookings       = $bookings;
		$this->routing        = $routing;
		$this->cal            = $cal;
		$this->changelog      = $changelog;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function bootstrap() {
		$contact_id    = $this->auth->current_contact_id();
		$settings_hash = md5( wp_json_encode( $this->settings->all() ) );
		$cache_key     = 'bootstrap_static_' . HANDIK_BOOKING_APP_VERSION . '_' . $settings_hash;
		$cache_group   = 'handik_booking_app';
		$static        = wp_cache_get( $cache_key, $cache_group );
		if ( ! is_array( $static ) ) {
			$static = get_transient( $cache_key );
		}

		if ( ! is_array( $static ) ) {
			$cal_fallback_url = trim( (string) $this->settings->get( 'cal_fallback_url', '' ) );
			if ( '' === $cal_fallback_url ) {
				$cal_fallback_url = trim( (string) $this->settings->get( 'cal_standard_event_url', '' ) );
			}
			$static = array(
				'version'         => HANDIK_BOOKING_APP_VERSION,
				'db_version'      => (string) get_option( Handik_Booking_App_Migrations::OPTION_NAME, '0.0.0' ),
				'task_catalog'    => $this->state->task_catalog(),
				'steps'           => $this->state->steps(),
				'default_state'   => $this->schema->default_state(),
				'appearance'      => $this->appearance->css_variables(),
				'changelog'       => $this->changelog->get_entries(),
				'cal_configured'  => array_filter( $this->cal->event_map() ),
				'cal_fallback_url'=> $cal_fallback_url,
				'serviceable_zips'=> $this->serviceable_zips(),
			);
			wp_cache_set( $cache_key, $static, $cache_group, HOUR_IN_SECONDS );
			set_transient( $cache_key, $static, HOUR_IN_SECONDS );
		}

		return array_merge(
			array(
				'success'          => true,
				'verified_profile' => $contact_id ? $this->auth->profile( $contact_id ) : null,
			),
			$static
		);
	}

	/**
	 * Silently recognize returning customers by phone without OTP friction.
	 *
	 * @param string $phone Phone.
	 * @return array<string, mixed>
	 */
	public function contact_lookup( $phone ) {
		$normalized = $this->contacts->normalize_phone( $phone );
		$digits     = preg_replace( '/\D/', '', (string) $normalized );
		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			$digits = substr( $digits, 1 );
		}

		if ( ! $normalized || 10 !== strlen( $digits ) ) {
			return array( 'success' => true, 'profile' => null );
		}

		$limit = $this->auth->rate_limit_lookup( 'lookup:' . $digits );
		if ( is_wp_error( $limit ) ) {
			return array( 'error' => $limit->get_error_message(), 'status' => 429 );
		}

		$contact = $this->contacts->find_by_email_or_phone( '', $normalized );
		if ( ! $contact || empty( $contact['id'] ) ) {
			return array( 'success' => true, 'profile' => null );
		}

		return array(
			'success' => true,
			'profile' => $this->auth->profile( (int) $contact['id'], true ),
		);
	}

	/**
	 * Sprint 9 fix: read from `service_area_zips` (the key the admin
	 * "Allowed ZIP codes" textarea writes to — see App Setup → Service area)
	 * with `serviceable_zips` as a legacy fallback for installs that may
	 * have populated the older orphan key. Before this fix the two keys
	 * were unrelated and the setting was effectively ignored end-to-end.
	 *
	 * @return array<int, string>
	 */
	protected function serviceable_zips() {
		$raw = (string) $this->settings->get( 'service_area_zips', '' );
		if ( '' === trim( $raw ) ) {
			$raw = (string) $this->settings->get( 'serviceable_zips', '' );
		}
		$zips = preg_split( '/[\s,;]+/', $raw );
		$zips = is_array( $zips ) ? $zips : array();
		return array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $zip ) {
							$digits = preg_replace( '/\D/', '', (string) $zip );
							return 5 === strlen( $digits ) ? $digits : '';
						},
						$zips
					)
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>
	 */
	public function save_draft( array $payload ) {
		$request_id  = ! empty( $payload['request_id'] ) ? absint( $payload['request_id'] ) : 0;
		$draft_token = ! empty( $payload['draft_token'] ) ? sanitize_text_field( $payload['draft_token'] ) : '';

		if ( $request_id && ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		// Sprint 9 fix: server-side enforcement of "Allowed ZIP codes"
		// (App Setup → Service area). The SPA already shows a hint at
		// `BookingApp.isServiceableZip()` and refuses to advance, but a
		// stale or hostile client could still POST a save with an
		// out-of-area ZIP — the addresses table would happily accept it
		// and the job would route to assistant. Defense in depth: when
		// the admin has populated a non-empty list, reject saves that
		// carry a 5-digit ZIP not on the list. Empty list = accept any.
		$serviceable = $this->serviceable_zips();
		if ( ! empty( $serviceable ) ) {
			$submitted_zip = preg_replace( '/\D/', '', (string) ( $payload['zip_code'] ?? '' ) );
			if ( '' !== $submitted_zip && 5 === strlen( $submitted_zip ) && ! in_array( $submitted_zip, $serviceable, true ) ) {
				return array(
					'error'  => __( "We don't currently provide service to this ZIP code. Please contact us if you need help in this area.", 'handik-booking-app' ),
					'status' => 422,
				);
			}
		}

		$selected_tasks = array_values(
			array_filter(
				array_map( 'sanitize_text_field', array_map( 'strval', $payload['selected_tasks'] ?? array() ) )
			)
		);
		$is_project = ! empty( $payload['is_project'] );
		$job_shape  = $is_project ? 'project' : ( count( $selected_tasks ) > 1 ? 'multiple_tasks' : 'single_task' );
		$contact_id = $this->auth->current_contact_id();
		$is_returning = $contact_id > 0 || ( ! empty( $payload['client_type'] ) && 'returning_client' === $payload['client_type'] );

		$contact_payload = array(
			'first_name'   => sanitize_text_field( $payload['first_name'] ?? '' ),
			'last_name'    => sanitize_text_field( $payload['last_name'] ?? '' ),
			'full_name'    => sanitize_text_field( $payload['full_name'] ?? '' ),
			'email'        => sanitize_email( $payload['email'] ?? '' ),
			'phone'        => $this->contacts->normalize_phone( $payload['phone'] ?? '' ),
			'source'       => 'booking_app',
			'is_returning' => $is_returning,
		);
		$contact_id = $this->contacts->upsert( $contact_payload, $contact_id );

		$address_id = $this->addresses->sync(
			$contact_id,
			array(
				'address_id'     => absint( $payload['address_id'] ?? 0 ),
				'address_full'   => $payload['address_full'] ?? '',
				'address_line_1' => $payload['address_line_1'] ?? '',
				'address_unit'   => $payload['address_unit'] ?? '',
				'city'           => $payload['city'] ?? '',
				'state'          => $payload['state'] ?? '',
				'zip_code'       => $payload['zip_code'] ?? '',
				'is_default'     => true,
			)
		);

		$saved = $this->job_requests->save_draft(
			array(
				'client_type'         => ! empty( $payload['client_type'] ) ? sanitize_key( $payload['client_type'] ) : ( $is_returning ? 'returning_client' : 'new_client' ),
				'job_shape'           => $job_shape,
				'selected_tasks'      => $selected_tasks,
				'is_project'          => $is_project,
				'address_full'        => sanitize_textarea_field( $payload['address_full'] ?? '' ),
				'address_unit'        => sanitize_text_field( $payload['address_unit'] ?? '' ),
				'short_description'   => sanitize_textarea_field( $payload['short_description'] ?? '' ),
				'photos'              => is_array( $payload['photos'] ?? null ) ? $payload['photos'] : array(),
				'preferred_timeframe' => sanitize_text_field( $payload['preferred_timeframe'] ?? '' ),
				'app_step'            => sanitize_key( $payload['app_step'] ?? 'address_details' ),
				'app_session_key'     => sanitize_text_field( $payload['app_session_key'] ?? '' ),
				'app_state'           => is_array( $payload['app_state'] ?? null ) ? $payload['app_state'] : array(),
				'status'              => sanitize_key( $payload['status'] ?? 'draft' ),
				'lookup_verified'     => $is_returning,
			),
			$contact_id,
			$address_id,
			$request_id
		);

		$request         = $saved['request'];
		$effective_token = ! empty( $saved['draft_token'] ) ? $saved['draft_token'] : $draft_token;
		$routing_preview = $this->routing->route( $request );

		return array(
			'success'       => true,
			'request_id'    => (int) $request['id'],
			'draft_token'   => $effective_token,
			'request'       => $request,
			'routing'       => $routing_preview,
			'contact'       => $contact_id ? $this->contacts->get( $contact_id ) : null,
			'addresses'     => $contact_id ? $this->addresses->list_for_contact( $contact_id ) : array(),
		);
	}

	/**
	 * @param array<string, mixed> $file File.
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	public function upload_photo( array $file, array $context = array() ) {
		$request_id  = ! empty( $context['request_id'] ) ? absint( $context['request_id'] ) : 0;
		$draft_token = ! empty( $context['draft_token'] ) ? sanitize_text_field( (string) $context['draft_token'] ) : '';

		if ( ! $request_id || ! $draft_token || ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Valid draft request is required before uploading files.', 'handik-booking-app' ), 'status' => 403 );
		}
		$request = $this->job_requests->get( $request_id );
		$current_files = $request && ! empty( $request['photos'] ) && is_array( $request['photos'] ) ? $request['photos'] : array();
		if ( count( $current_files ) >= 8 ) {
			return array( 'error' => __( 'You can upload up to 8 photos or videos for one request.', 'handik-booking-app' ), 'status' => 400 );
		}

		$context['request_id'] = $request_id;
		$result = method_exists( $this->upload_service, 'upload_media' ) ? $this->upload_service->upload_media( $file, $context ) : $this->upload_service->upload_image( $file, $context );
		if ( empty( $result['error'] ) ) {
			$app_state_patch = array(
				'photo_analysis_status' => ( ! empty( $result['media_type'] ) && 'video' === $result['media_type'] ) ? 'video_saved' : 'queued',
			);
			if ( ! empty( $result['media_type'] ) && 'video' === $result['media_type'] ) {
				$app_state_patch['has_uploaded_videos'] = true;
			}
			$this->job_requests->update_app_state(
				$request_id,
				$app_state_patch
			);
		}

		return $result;
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function booking_url( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}
		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}
		if ( ! $this->has_complete_saved_routing( $request ) ) {
			return array( 'error' => __( 'Assistant is still preparing the booking recommendation.', 'handik-booking-app' ), 'status' => 409 );
		}
		$url = $this->cal->build_booking_url( $request_id );
		if ( ! $url ) {
			return array( 'error' => __( 'Cal.com is not configured for this booking type.', 'handik-booking-app' ), 'status' => 400 );
		}
		$this->job_requests->mark_booking_pending( $request_id );
		return array( 'success' => true, 'booking_url' => $url, 'booking_url_locked' => true );
	}

	/**
	 * @param array<string, mixed> $request Request.
	 * @return bool
	 */
	protected function has_complete_saved_routing( array $request ) {
		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$assistant = ! empty( $request['assistant_result'] ) && is_array( $request['assistant_result'] ) ? $request['assistant_result'] : array();
		$suggested_duration = ! empty( $assistant['suggested_duration_hours'] ) ? $assistant['suggested_duration_hours'] : ( $app_state['suggested_duration_hours'] ?? '' );
		return ! empty( $request['booking_type'] )
			&& ! empty( $request['duration_bucket'] )
			&& ! empty( $suggested_duration )
			&& ! empty( $assistant['enough_information'] )
			&& empty( $assistant['unsafe'] )
			&& empty( $request['unsafe_flag'] );
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function complete_booking_step( $request_id, $draft_token ) {
		return $this->booking_status( $request_id, $draft_token );
	}

	/**
	 * @param int    $request_id Request.
	 * @param string $draft_token Token.
	 * @return array<string, mixed>
	 */
	public function booking_status( $request_id, $draft_token ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}
		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$booking       = $this->bookings->find_latest_for_request( $request_id );
		$status        = sanitize_key( (string) ( $booking['status'] ?? $request['status'] ?? 'booking_pending' ) );
		$is_confirmed  = in_array( $status, array( 'booked', 'rescheduled' ), true );

		if ( $is_confirmed && empty( $request['completed_at'] ) ) {
			$this->job_requests->mark_complete( $request_id, $status );
			$request = $this->job_requests->get( $request_id );
		}

		return array(
			'success'         => true,
			'request_id'      => $request_id,
			'status'          => $status,
			'is_confirmed'    => $is_confirmed,
			'cal_booking_id'  => $request['cal_booking_id'] ?? '',
			'booking'         => $booking,
			'request'         => $request,
		);
	}

	/**
	 * @param int                  $request_id Request.
	 * @param string               $draft_token Token.
	 * @param array<string, mixed> $booking_payload Booking payload from Cal embed.
	 * @return array<string, mixed>
	 */
	public function capture_booking( $request_id, $draft_token, array $booking_payload ) {
		if ( ! $this->job_requests->verify_draft_token( $request_id, $draft_token ) ) {
			return array( 'error' => __( 'Invalid draft token.', 'handik-booking-app' ), 'status' => 403 );
		}

		$request = $this->job_requests->get( $request_id );
		if ( ! $request ) {
			return array( 'error' => __( 'Draft request not found.', 'handik-booking-app' ), 'status' => 404 );
		}

		$booking_uid = sanitize_text_field( (string) ( $booking_payload['uid'] ?? $booking_payload['bookingUid'] ?? $booking_payload['bookingId'] ?? $booking_payload['id'] ?? '' ) );
		if ( ! $booking_uid ) {
			return array( 'error' => __( 'Booking payload did not include a Cal.com booking ID.', 'handik-booking-app' ), 'status' => 400 );
		}

		$payload = array(
			'uid'           => $booking_uid,
			'bookingUid'    => $booking_uid,
			'bookingId'     => $booking_uid,
			'booking_type'  => sanitize_key( (string) ( $request['booking_type'] ?? $booking_payload['booking_type'] ?? '' ) ),
			'eventTypeSlug' => sanitize_text_field( (string) ( $booking_payload['eventTypeSlug'] ?? $booking_payload['eventSlug'] ?? '' ) ),
			'title'         => sanitize_text_field( (string) ( $booking_payload['title'] ?? '' ) ),
			'startTime'     => sanitize_text_field( (string) ( $booking_payload['startTime'] ?? '' ) ),
			'endTime'       => sanitize_text_field( (string) ( $booking_payload['endTime'] ?? '' ) ),
			'status'        => sanitize_key( (string) ( $booking_payload['status'] ?? 'booked' ) ),
		);

		$raw_status     = strtolower( (string) $payload['status'] );
		$booking_status = in_array( $raw_status, array( 'cancelled', 'rescheduled' ), true ) ? $raw_status : 'booked';

		$this->bookings->upsert_from_cal( $request_id, $payload, $booking_status );
		if ( in_array( $booking_status, array( 'booked', 'rescheduled' ), true ) ) {
			$this->job_requests->mark_complete( $request_id, $booking_status );
		}

		if ( function_exists( 'handik_booking_app' ) ) {
			$plugin = handik_booking_app();
			if ( ! empty( $plugin->logger ) ) {
				$plugin->logger->info(
					'Cal embed booking captured.',
					array(
						'request_id' => $request_id,
						'booking_id' => $booking_uid,
						'status'     => $booking_status,
					)
				);
			}
		}

		$request = $this->job_requests->get( $request_id );
		$booking = $this->bookings->find_latest_for_request( $request_id );

		return array(
			'success'        => true,
			'request_id'     => $request_id,
			'status'         => $booking_status,
			'is_confirmed'   => in_array( $booking_status, array( 'booked', 'rescheduled' ), true ),
			'cal_booking_id' => $booking_uid,
			'booking'        => $booking,
			'request'        => $request,
		);
	}
}
