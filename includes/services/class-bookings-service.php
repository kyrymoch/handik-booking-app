<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Bookings_Service {
	/**
	 * @var Handik_Booking_App_Logger
	 */
	protected $logger;

	/**
	 * @var Handik_Booking_App_Job_Requests_Service
	 */
	protected $job_requests;

	/**
	 * @var Handik_Booking_App_Contacts_Service|null
	 */
	protected $contacts;

	/**
	 * @param Handik_Booking_App_Logger               $logger Logger.
	 * @param Handik_Booking_App_Job_Requests_Service $job_requests Requests.
	 * @param Handik_Booking_App_Contacts_Service|null $contacts Contacts (1.6.1 — needed for external-booking attendee
	 *                                                                       resolution; optional / null-default so
	 *                                                                       legacy construction sites keep working).
	 */
	public function __construct( $logger, $job_requests, $contacts = null ) {
		$this->logger       = $logger;
		$this->job_requests = $job_requests;
		$this->contacts     = $contacts;
	}

	/**
	 * @param int                  $job_request_id Request.
	 * @param array<string, mixed> $payload Payload.
	 * @param string               $status Status.
	 * @return int
	 */
	public function upsert_from_cal( $job_request_id, array $payload, $status ) {
		global $wpdb;
		$table      = Handik_Booking_App_DB::table( 'bookings' );
		$booking_id = $this->extract_booking_id( $payload );

		if ( ! $booking_id ) {
			$this->logger->error( 'Missing Cal booking ID.', array( 'payload' => $payload ) );
			return 0;
		}

		$record = array(
			'job_request_id'   => $job_request_id,
			'cal_booking_id'   => $booking_id,
			'booking_type'     => ! empty( $payload['booking_type'] ) ? sanitize_key( $payload['booking_type'] ) : '',
			'event_type_slug'  => ! empty( $payload['eventTypeSlug'] ) ? sanitize_key( $payload['eventTypeSlug'] ) : sanitize_key( (string) ( $payload['type'] ?? '' ) ),
			'duration_minutes' => absint( $payload['duration'] ?? $payload['lengthInMinutes'] ?? 0 ),
			'start_time'       => $this->normalize_datetime( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_time'         => $this->normalize_datetime( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'status'           => sanitize_key( $status ),
			'raw_webhook_json' => wp_json_encode( $payload ),
		);

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", $booking_id ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing['id'] ) );
			$row_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, $record );
			$row_id = (int) $wpdb->insert_id;
		}

		$this->job_requests->set_cal_booking( $job_request_id, $booking_id, $status );

		// Sprint 14a — fire the booking-confirmed action ONLY on first
		// transition into a confirmed state. Atomic UPDATE on the new
		// `confirmation_email_sent_at` column inside Notifications_Service
		// handles webhook-retry deduping; we only need to gate on status
		// here so cancellations / reschedules don't trigger a new email.
		// Sprint 14c — same dispatch shape, but for cancelled/rescheduled
		// statuses. Idempotency via the new `last_status_emailed` column
		// (Migration 1.5.2) so webhook retries with the same status are
		// no-ops while real state changes (booked→cancelled→rebooked)
		// each get one email.
		$normalized_status = sanitize_key( $status );
		if ( class_exists( 'Handik_Booking_App_Notifications_Service' ) ) {
			if ( 'booked' === $normalized_status ) {
				Handik_Booking_App_Notifications_Service::dispatch_for_cal( (int) $job_request_id, $row_id, $payload );
			} elseif ( 'cancelled' === $normalized_status ) {
				Handik_Booking_App_Notifications_Service::dispatch_for_cal_cancel( (int) $job_request_id, $row_id, $payload );
			} elseif ( 'rescheduled' === $normalized_status ) {
				Handik_Booking_App_Notifications_Service::dispatch_for_cal_reschedule( (int) $job_request_id, $row_id, $payload );
			}
		}
		return $row_id;
	}

	/**
	 * Sprint 13.5 — mirror a direct-booking-form Cal booking into the
	 * canonical `handik_bookings` table.
	 *
	 * Owner-reported visibility bug: admin's "+ Add booking" flow + every
	 * public direct-booking submission was creating rows ONLY in
	 * `handik_direct_booking_requests`. The unified Bookings list reads
	 * `handik_bookings` only, so direct rows were invisible there. This
	 * upsert closes the gap — called from BOTH `Direct_Booking_Service::
	 * capture_booking()` (leading-edge, fires on Cal embed
	 * `bookingSuccessful`) and `Webhook_Service::dispatch_direct()`
	 * (trailing-edge, fires when Cal webhook lands). Whichever path
	 * arrives first wins; the other is a no-op idempotent UPDATE on
	 * the UNIQUE `cal_booking_id` row.
	 *
	 * Schema 1.5.0 made `job_request_id` NULLable + added
	 * `direct_request_id`, so a direct booking lives as
	 * `(job_request_id=NULL, direct_request_id=N, cal_booking_id=X)`.
	 *
	 * @param int                  $direct_request_id ID from `handik_direct_booking_requests`.
	 * @param array<string, mixed> $payload          Cal payload (same shape as
	 *                                                 `upsert_from_cal`'s `$data`).
	 * @param string               $status           Mapped status (e.g. 'booked', 'cancelled').
	 * @return int Row id in handik_bookings (0 on failure).
	 */
	public function upsert_from_direct_capture( $direct_request_id, array $payload, $status ) {
		global $wpdb;
		$table      = Handik_Booking_App_DB::table( 'bookings' );
		// 1.6.0 P0 fix: Cal's modern embed nests the booking id under
		// $payload['booking'] (id/uid) and the event-type slug under
		// $payload['eventType']. The 1.5.0 implementation read only
		// top-level keys and silently dropped both, so the mirror row
		// was never written. Flatten once at the top so every
		// downstream field-extraction in this method works against
		// either embed schema. See flatten_cal_embed_payload() for the
		// owner-reported failure mode and the additive-only rule.
		$payload    = self::flatten_cal_embed_payload( $payload );
		$booking_id = $this->extract_booking_id( $payload );

		// Unlike upsert_from_cal, a direct capture path can sometimes
		// fire BEFORE the webhook hands us a real Cal id (the JS-side
		// capture flow used to send empty UIDs — that bug was fixed in
		// 2.1.20.1 hotfix F1+F2 — but be defensive). If we don't have
		// a Cal id yet, bail; the trailing webhook will retry with a
		// proper id.
		if ( ! $booking_id ) {
			if ( $this->logger ) {
				$this->logger->info( 'Skipped direct-capture mirror — no Cal booking id yet (will retry on webhook).', array(
					'direct_request_id' => $direct_request_id,
				) );
			}
			return 0;
		}

		$record = array(
			'job_request_id'   => null,
			'direct_request_id' => (int) $direct_request_id,
			'cal_booking_id'   => $booking_id,
			'booking_type'     => ! empty( $payload['booking_type'] ) ? sanitize_key( $payload['booking_type'] ) : '',
			'event_type_slug'  => ! empty( $payload['eventTypeSlug'] ) ? sanitize_key( $payload['eventTypeSlug'] ) : sanitize_key( (string) ( $payload['type'] ?? '' ) ),
			'duration_minutes' => absint( $payload['duration'] ?? $payload['lengthInMinutes'] ?? 0 ),
			'start_time'       => $this->normalize_datetime( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_time'         => $this->normalize_datetime( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'status'           => sanitize_key( $status ),
			'raw_webhook_json' => wp_json_encode( $payload ),
		);

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", $booking_id ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing['id'] ) );
			$row_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, $record );
			$row_id = (int) $wpdb->insert_id;
		}

		// Sprint 14a — also fire from the trailing-edge webhook path so a
		// customer who abandoned the Cal embed (no leading-edge capture)
		// still gets the email when Cal eventually webhooks the booking.
		// Direct flow's leading-edge path in `Direct_Booking_Service::
		// capture_booking()` ALSO dispatches; idempotency on the
		// `direct_booking_requests.confirmation_email_sent_at` column
		// guarantees only one email goes out per real booking.
		// Sprint 14c — same dispatch for cancel/reschedule via the new
		// `last_status_emailed` column.
		$normalized_status = sanitize_key( $status );
		if ( class_exists( 'Handik_Booking_App_Notifications_Service' ) ) {
			if ( 'booked' === $normalized_status ) {
				Handik_Booking_App_Notifications_Service::dispatch_for_direct( (int) $direct_request_id, $payload );
			} elseif ( 'cancelled' === $normalized_status ) {
				Handik_Booking_App_Notifications_Service::dispatch_for_direct_cancel( (int) $direct_request_id, $payload );
			} elseif ( 'rescheduled' === $normalized_status ) {
				Handik_Booking_App_Notifications_Service::dispatch_for_direct_reschedule( (int) $direct_request_id, $payload );
			}
		}
		return $row_id;
	}

	/**
	 * Mirror a single project work-day's Cal booking into
	 * `handik_bookings` so the row surfaces in the unified admin
	 * Bookings list. Parallel to `upsert_from_direct_capture()` — same
	 * `cal_booking_id` UNIQUE-key idempotency, same flatten step at
	 * the top — but keyed on `project_work_day_id` rather than
	 * `direct_request_id`.
	 *
	 * Called from two places:
	 *   1. Leading edge: `Project_Schedule_Service::confirm_schedule()`
	 *      right after `Cal_Api_Service::create_booking()` succeeds for
	 *      each work day, with the normalized booking payload from
	 *      `Cal_Api_Service::normalize_booking()` (flat top-level
	 *      `id`/`uid`/`start`/`end`).
	 *   2. Trailing edge: `Webhook_Service::dispatch_project()` after
	 *      `update_day_status_by_uid()`, with the Cal-webhook payload
	 *      (already flat). The cal_booking_id UNIQUE constraint
	 *      collapses the second call into an UPDATE of the row the
	 *      leading edge already wrote.
	 *
	 * Notifications: deliberately NOT dispatched here. Project flow
	 * sends a SINGLE confirmation email per schedule (with one .ics
	 * carrying all N VEVENTs) — wired in
	 * `Project_Schedule_Service::confirm_schedule()`. Per-day emails
	 * would spam the customer for a 5-day project.
	 *
	 * @param int                  $work_day_id Row id in `handik_project_work_days`.
	 * @param array<string, mixed> $payload     Cal booking payload (embed shape OR webhook shape OR normalized).
	 * @param string               $status      Mapped status (`booked`, `cancelled`, `rescheduled`).
	 * @param array<string, mixed> $context     Optional per-schedule metadata the Cal payload may not carry:
	 *                                          `booking_type`, `event_type_slug`, `duration_minutes`.
	 * @return int Row id in handik_bookings (0 on failure / no booking id yet).
	 */
	public function upsert_from_project( $work_day_id, array $payload, $status, array $context = array() ) {
		global $wpdb;
		$table      = Handik_Booking_App_DB::table( 'bookings' );
		$payload    = self::flatten_cal_embed_payload( $payload );
		$booking_id = $this->extract_booking_id( $payload );

		// Same defensive guard as upsert_from_direct_capture: if the
		// caller doesn't have a Cal booking id yet (rare — Cal returns
		// it inline in the API response — but the webhook can fire
		// without one if metadata is malformed), bail and let the
		// trailing-edge call retry.
		if ( ! $booking_id ) {
			if ( $this->logger ) {
				$this->logger->info( 'Skipped project mirror — no Cal booking id yet (will retry on webhook).', array(
					'project_work_day_id' => $work_day_id,
				) );
			}
			return 0;
		}

		$record = array(
			'job_request_id'       => null,
			'direct_request_id'    => null,
			'project_work_day_id'  => (int) $work_day_id,
			'cal_booking_id'       => $booking_id,
			'booking_type'         => ! empty( $context['booking_type'] ) ? sanitize_key( $context['booking_type'] ) : ( ! empty( $payload['booking_type'] ) ? sanitize_key( $payload['booking_type'] ) : '' ),
			'event_type_slug'      => ! empty( $context['event_type_slug'] ) ? sanitize_key( $context['event_type_slug'] ) : ( ! empty( $payload['eventTypeSlug'] ) ? sanitize_key( $payload['eventTypeSlug'] ) : sanitize_key( (string) ( $payload['type'] ?? '' ) ) ),
			'duration_minutes'     => ! empty( $context['duration_minutes'] ) ? absint( $context['duration_minutes'] ) : absint( $payload['duration'] ?? $payload['lengthInMinutes'] ?? 0 ),
			'start_time'           => $this->normalize_datetime( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_time'             => $this->normalize_datetime( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'status'               => sanitize_key( $status ),
			'raw_webhook_json'     => wp_json_encode( $payload ),
		);

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", $booking_id ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing['id'] ) );
			$row_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, $record );
			$row_id = (int) $wpdb->insert_id;
		}
		return $row_id;
	}

	/**
	 * 1.6.1 — Mirror an "external" Cal.com booking into handik_bookings.
	 * Called from Webhook_Service::handle_cal_webhook when none of the
	 * normal routing paths (handik_booking_source metadata, handik_job
	 * _request_id, cal_booking_id match, pending-contact fallback)
	 * resolve to one of our local request rows — which is exactly the
	 * "customer abandoned our form, used the 'Open the booking page
	 * directly' link, booked on Cal.com" case the owner reported.
	 *
	 * All foreign-key columns (job_request_id, direct_request_id,
	 * project_work_day_id) stay NULL. We DO try to attach an existing
	 * contact when the attendee email/phone matches via
	 * Contacts_Service::find_by_email_or_phone, populating the new
	 * `external_contact_id` column so People & Requests can show the
	 * booking under that person — but we never auto-create a contact
	 * here. Cal's stock embed only collects email + name (phone only
	 * when the event type has a custom phone question), so the
	 * match rate is best-effort. When no match, the booking still
	 * surfaces in the admin Bookings list with the attendee name +
	 * email pulled out of raw_webhook_json by the render code.
	 *
	 * Idempotent on cal_booking_id UNIQUE — duplicate webhook
	 * deliveries collapse into an UPDATE.
	 *
	 * @param array<string, mixed> $payload Cal webhook `payload` block.
	 * @param string               $status  Mapped status (booked / cancelled / rescheduled).
	 * @return int Row id in handik_bookings (0 on failure / no Cal id).
	 */
	public function upsert_external_booking( array $payload, $status ) {
		global $wpdb;
		$table      = Handik_Booking_App_DB::table( 'bookings' );
		$payload    = self::flatten_cal_embed_payload( $payload );
		$booking_id = $this->extract_booking_id( $payload );
		if ( ! $booking_id ) {
			if ( $this->logger ) {
				$this->logger->info( 'Skipped external-booking mirror — no Cal booking id in payload.' );
			}
			return 0;
		}

		// Try to resolve the attendee to an existing contact. Best-effort:
		// no match → external_contact_id stays NULL and the admin sees
		// the attendee pulled from raw_webhook_json instead.
		$attendee_email = $this->extract_attendee_email( $payload );
		$attendee_phone = $this->extract_attendee_phone( $payload );
		$contact_id     = 0;
		if ( $this->contacts && method_exists( $this->contacts, 'find_by_email_or_phone' ) ) {
			$matched = $this->contacts->find_by_email_or_phone( $attendee_email, $attendee_phone );
			if ( $matched && ! empty( $matched['id'] ) ) {
				$contact_id = (int) $matched['id'];
			}
		}

		$record = array(
			'job_request_id'       => null,
			'direct_request_id'    => null,
			'project_work_day_id'  => null,
			'external_contact_id'  => $contact_id > 0 ? $contact_id : null,
			'cal_booking_id'       => $booking_id,
			'booking_type'         => ! empty( $payload['booking_type'] ) ? sanitize_key( $payload['booking_type'] ) : '',
			'event_type_slug'      => ! empty( $payload['eventTypeSlug'] ) ? sanitize_key( $payload['eventTypeSlug'] ) : sanitize_key( (string) ( $payload['type'] ?? '' ) ),
			'duration_minutes'     => absint( $payload['duration'] ?? $payload['lengthInMinutes'] ?? 0 ),
			'start_time'           => $this->normalize_datetime( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_time'             => $this->normalize_datetime( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'status'               => sanitize_key( $status ),
			'raw_webhook_json'     => wp_json_encode( $payload ),
		);

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_id = %s LIMIT 1", $booking_id ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $table, $record, array( 'id' => (int) $existing['id'] ) );
			$row_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $table, $record );
			$row_id = (int) $wpdb->insert_id;
		}

		if ( $this->logger ) {
			$this->logger->info(
				'External Cal.com booking captured.',
				array(
					'cal_booking_id' => $booking_id,
					'row_id'         => $row_id,
					'matched_contact'=> $contact_id,
					'attendee_email' => $attendee_email,
					'status'         => $status,
				)
			);
		}
		return $row_id;
	}

	/**
	 * Pull the attendee email out of a Cal webhook payload. Cal puts it
	 * in different shapes depending on the event/version: the booking-
	 * questions response (`responses.email.value`), the attendees array
	 * (`attendees[0].email`), or sometimes flat at top level.
	 *
	 * @param array<string, mixed> $payload Flattened payload.
	 * @return string Lowercased email, '' if not found.
	 */
	protected function extract_attendee_email( array $payload ) {
		$candidates = array();
		if ( ! empty( $payload['responses']['email']['value'] ) ) {
			$candidates[] = $payload['responses']['email']['value'];
		}
		if ( ! empty( $payload['responses']['email'] ) && is_string( $payload['responses']['email'] ) ) {
			$candidates[] = $payload['responses']['email'];
		}
		if ( isset( $payload['attendees'] ) && is_array( $payload['attendees'] ) ) {
			foreach ( $payload['attendees'] as $att ) {
				if ( is_array( $att ) && ! empty( $att['email'] ) ) {
					$candidates[] = $att['email'];
					break;
				}
			}
		}
		if ( ! empty( $payload['attendeeEmail'] ) ) {
			$candidates[] = $payload['attendeeEmail'];
		}
		foreach ( $candidates as $email ) {
			$email = sanitize_email( strtolower( trim( (string) $email ) ) );
			if ( $email && is_email( $email ) ) {
				return $email;
			}
		}
		return '';
	}

	/**
	 * Pull the attendee phone out of a Cal webhook payload. Cal only
	 * collects this when the event type explicitly adds a phone-question
	 * to the booking form, so most payloads don't carry one. Returns
	 * '' when missing.
	 *
	 * @param array<string, mixed> $payload Flattened payload.
	 * @return string Phone, '' if not found.
	 */
	protected function extract_attendee_phone( array $payload ) {
		$candidates = array();
		foreach ( array( 'phone', 'attendeePhone', 'attendeePhoneNumber', 'smsReminderNumber' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) && is_string( $payload[ $key ] ) ) {
				$candidates[] = $payload[ $key ];
			}
		}
		if ( ! empty( $payload['responses']['phone']['value'] ) ) {
			$candidates[] = $payload['responses']['phone']['value'];
		}
		if ( ! empty( $payload['responses']['phone'] ) && is_string( $payload['responses']['phone'] ) ) {
			$candidates[] = $payload['responses']['phone'];
		}
		if ( isset( $payload['attendees'] ) && is_array( $payload['attendees'] ) ) {
			foreach ( $payload['attendees'] as $att ) {
				if ( is_array( $att ) && ! empty( $att['phoneNumber'] ) ) {
					$candidates[] = $att['phoneNumber'];
					break;
				}
				if ( is_array( $att ) && ! empty( $att['phone'] ) ) {
					$candidates[] = $att['phone'];
					break;
				}
			}
		}
		foreach ( $candidates as $phone ) {
			$phone = trim( (string) $phone );
			if ( $phone ) {
				return $phone;
			}
		}
		return '';
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Cheap COUNT for dashboard widgets — avoids fetching full rows just to count them.
	 *
	 * @return int
	 */
	public function count_all() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Effective status taking the manual admin override into account.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return string
	 */
	public function effective_status( array $booking ) {
		if ( ! empty( $booking['admin_status_override'] ) ) {
			return (string) $booking['admin_status_override'];
		}
		return (string) ( $booking['status'] ?? '' );
	}

	/**
	 * @param string $from_utc DATETIME string in UTC (inclusive).
	 * @param string $to_utc   DATETIME string in UTC (exclusive).
	 * @param int|null $limit  Max rows or null for unlimited.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_in_window( $from_utc, $to_utc, $limit = null ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$sql   = "SELECT * FROM {$table} WHERE start_time >= %s AND start_time < %s ORDER BY start_time ASC";
		if ( null !== $limit ) {
			$sql .= ' LIMIT ' . max( 1, (int) $limit );
		}
		return $wpdb->get_results( $wpdb->prepare( $sql, $from_utc, $to_utc ), ARRAY_A );
	}

	public function count_in_window( $from_utc, $to_utc ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE start_time >= %s AND start_time < %s", $from_utc, $to_utc )
		);
	}

	public function avg_duration_in_window( $from_utc, $to_utc ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$avg   = $wpdb->get_var(
			$wpdb->prepare( "SELECT AVG(duration_minutes) FROM {$table} WHERE start_time >= %s AND start_time < %s AND duration_minutes > 0", $from_utc, $to_utc )
		);
		return null === $avg ? 0.0 : (float) $avg;
	}

	public function list_upcoming( $limit = 5 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE start_time >= %s ORDER BY start_time ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Update admin-only fields on a booking. Logs the change.
	 *
	 * @param int                  $booking_id Booking ID.
	 * @param array<string, mixed> $patch      Allowed keys: admin_notes, admin_status_override.
	 * @return bool
	 */
	public function update_admin_fields( $booking_id, array $patch ) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 ) {
			return false;
		}
		$update = array();
		if ( array_key_exists( 'admin_notes', $patch ) ) {
			$update['admin_notes'] = is_null( $patch['admin_notes'] ) ? null : sanitize_textarea_field( (string) $patch['admin_notes'] );
		}
		if ( array_key_exists( 'admin_status_override', $patch ) ) {
			$value = $patch['admin_status_override'];
			if ( is_null( $value ) || '' === $value ) {
				$update['admin_status_override'] = null;
			} else {
				$allowed = array( 'cancelled', 'completed', 'rescheduled', 'no_show' );
				$value   = sanitize_key( (string) $value );
				$update['admin_status_override'] = in_array( $value, $allowed, true ) ? $value : null;
			}
		}
		if ( empty( $update ) ) {
			return false;
		}
		$wpdb->update(
			Handik_Booking_App_DB::table( 'bookings' ),
			$update,
			array( 'id' => $booking_id )
		);
		$this->logger->info(
			'Admin updated booking fields.',
			array(
				'booking_id' => $booking_id,
				'fields'     => array_keys( $update ),
				'admin_id'   => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $booking_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $booking_id ), ARRAY_A );
		return $row ?: null;
	}

	/** Allowed payment statuses + methods for the Sprint 10 money fields. */
	public static function payment_statuses() {
		return array( '', 'unpaid', 'partial', 'paid' );
	}
	public static function payment_methods() {
		return array( '', 'cash', 'venmo', 'zelle', 'check', 'card', 'other' );
	}

	/**
	 * Sprint 10 — record per-booking money fields. Dollar inputs arrive as
	 * decimal strings and are stored as integer cents (no float drift);
	 * enums validated against the allow-lists; mileage as a 0.1-mile decimal.
	 * Only keys present in $patch are touched (empty string clears).
	 *
	 * @param int                 $booking_id Booking id.
	 * @param array<string,mixed> $patch      Raw input.
	 * @return bool
	 */
	public function update_payment( $booking_id, array $patch ) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 || ! $this->get( $booking_id ) ) {
			return false;
		}
		$update = array();
		if ( array_key_exists( 'actual_amount', $patch ) ) {
			$update['actual_amount_cents'] = $this->dollars_to_cents( $patch['actual_amount'] );
		}
		if ( array_key_exists( 'materials_amount', $patch ) ) {
			$update['materials_amount_cents'] = $this->dollars_to_cents( $patch['materials_amount'] );
		}
		if ( array_key_exists( 'payment_status', $patch ) ) {
			$v = sanitize_key( (string) $patch['payment_status'] );
			$update['payment_status'] = in_array( $v, self::payment_statuses(), true ) ? $v : '';
		}
		if ( array_key_exists( 'payment_method_used', $patch ) ) {
			$v = sanitize_key( (string) $patch['payment_method_used'] );
			$update['payment_method_used'] = in_array( $v, self::payment_methods(), true ) ? $v : '';
		}
		if ( array_key_exists( 'invoice_number', $patch ) ) {
			$update['invoice_number'] = sanitize_text_field( (string) $patch['invoice_number'] );
		}
		if ( array_key_exists( 'mileage_miles', $patch ) ) {
			$miles = (string) $patch['mileage_miles'];
			$update['mileage_miles'] = ( '' === trim( $miles ) ) ? null : round( (float) $miles, 1 );
		}
		if ( empty( $update ) ) {
			return false;
		}
		$wpdb->update( Handik_Booking_App_DB::table( 'bookings' ), $update, array( 'id' => $booking_id ) );
		if ( $this->logger ) {
			$this->logger->info( 'Admin updated booking payment.', array( 'booking_id' => $booking_id, 'fields' => array_keys( $update ) ) );
		}
		return true;
	}

	/**
	 * Parse a dollar input ("$1,234.50", "1234.5", "") to integer cents, or
	 * null for an empty input.
	 *
	 * @param mixed $value Raw dollars.
	 * @return int|null
	 */
	protected function dollars_to_cents( $value ) {
		$clean = preg_replace( '/[^0-9.\-]/', '', (string) $value );
		if ( '' === $clean ) {
			return null;
		}
		return (int) round( ( (float) $clean ) * 100 );
	}

	/**
	 * Sprint 7 (admin perf): bulk fetch bookings by ID for the dashboard's
	 * `ensure_next_visit_decorations` loops which used to do one `get()` per
	 * booking ID across today/tomorrow/week + the next-visits cache.
	 *
	 * @param array<int, int> $ids Booking ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_many( array $ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = Handik_Booking_App_DB::table( 'bookings' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
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
	 * @param int $job_request_id Request.
	 * @return array<string, mixed>|null
	 */
	public function find_latest_for_request( $job_request_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_request_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1", $job_request_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Sprint 7 (admin perf): bulk equivalent of `find_latest_for_request`,
	 * keyed by job_request_id. Used by `class-admin-people.php::bookings_for_contact`
	 * which used to fan out N single-row lookups per contact card. Picks
	 * the highest (updated_at, id) per request_id with one window-style
	 * pass: ORDER BY descending, then in PHP take the first row we see for
	 * each request_id (cheaper than a SQL window function on MySQL 5.7).
	 *
	 * @param array<int, int> $job_request_ids Job request ids.
	 * @return array<int, array<string, mixed>> Keyed by job_request_id.
	 */
	public function find_latest_for_requests( array $job_request_ids ) {
		$ids = array_values( array_unique( array_map( 'absint', $job_request_ids ) ) );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = Handik_Booking_App_DB::table( 'bookings' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_request_id IN ({$placeholders}) ORDER BY job_request_id ASC, updated_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$rid = (int) $row['job_request_id'];
			if ( ! isset( $out[ $rid ] ) ) {
				$out[ $rid ] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	public function extract_booking_id( array $payload ) {
		$payload = self::flatten_cal_embed_payload( $payload );
		return sanitize_text_field( (string) ( $payload['bookingId'] ?? $payload['bookingUid'] ?? $payload['uid'] ?? $payload['id'] ?? '' ) );
	}

	/**
	 * Normalize a Cal-embed `bookingSuccessful` event payload so all
	 * downstream extraction (id/uid/start/end/eventTypeSlug) can read
	 * flat top-level keys regardless of which Cal embed schema version
	 * delivered the event.
	 *
	 * Background: Cal's modern embed wraps the booking under a
	 * `booking` subkey and the event type under an `eventType` subkey:
	 *
	 *   { booking: { id, uid, startTime, endTime, ... },
	 *     eventType: { id, slug, ... },
	 *     date, duration, organizer, ... }
	 *
	 * 1.5.0 wired `Direct_Booking_Service::capture_booking` and
	 * `Bookings_Service::upsert_from_direct_capture` to read only
	 * top-level keys (`$payload['id']`, `$payload['uid']`, etc.) —
	 * which matched the OLDER pre-v2 embed shape but stopped finding
	 * the IDs once Cal switched to the nested shape. Result:
	 * `extract_booking_id` returned ''. `upsert_from_direct_capture`
	 * bailed at "no Cal booking id yet — will retry on webhook".
	 * Webhook arrived with the same id missing on the local row, so
	 * `direct->find_by_cal_booking_id` returned nothing. The
	 * `handik_bookings` mirror was never written. Customer's contact
	 * appeared in People & Requests, but the booking was invisible in
	 * the Bookings list — owner-reported 1.6.0 P0.
	 *
	 * Hoisting is additive: we never overwrite a key that's ALREADY
	 * at the top level. That keeps the older-shape payload path
	 * (and the Cal webhook path, whose payload arrives pre-flat) a
	 * no-op.
	 *
	 * @param array<string, mixed> $payload Raw Cal embed/webhook payload.
	 * @return array<string, mixed> Flattened payload, safe to pass to top-level extractors.
	 */
	public static function flatten_cal_embed_payload( array $payload ) {
		if ( isset( $payload['booking'] ) && is_array( $payload['booking'] ) ) {
			foreach ( $payload['booking'] as $key => $value ) {
				if ( ! array_key_exists( $key, $payload ) || $payload[ $key ] === '' || $payload[ $key ] === null ) {
					$payload[ $key ] = $value;
				}
			}
		}
		if ( isset( $payload['eventType'] ) && is_array( $payload['eventType'] ) ) {
			if ( empty( $payload['eventTypeSlug'] ) && ! empty( $payload['eventType']['slug'] ) ) {
				$payload['eventTypeSlug'] = (string) $payload['eventType']['slug'];
			}
			if ( empty( $payload['eventTypeId'] ) && ! empty( $payload['eventType']['id'] ) ) {
				$payload['eventTypeId'] = (int) $payload['eventType']['id'];
			}
		}
		return $payload;
	}

	/**
	 * Sprint 12 — hard-delete a single booking row.
	 *
	 * Per the audit, `cal_booking_id` is denormalized: the same Cal UID
	 * is mirrored on `handik_job_requests.cal_booking_id` /
	 * `cal_booking_url`. After we drop the booking row that parent
	 * back-pointer would still claim "there's a Cal booking attached"
	 * even though the row is gone, which corrupts the bookings-list
	 * status logic. We clear those columns first; the request itself
	 * stays (use Job_Requests_Service::delete_hard for that).
	 *
	 * The Cal.com side is intentionally NOT cancelled — owner-decided
	 * scope for Sprint 12 (visit happened; we're cleaning local DB).
	 *
	 * @param int $booking_id Booking id.
	 * @return bool True if a row was deleted.
	 */
	public function delete_hard( $booking_id ) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 ) {
			return false;
		}
		$table         = Handik_Booking_App_DB::table( 'bookings' );
		$requests      = Handik_Booking_App_DB::table( 'job_requests' );
		$row           = $this->get( $booking_id );
		if ( ! $row ) {
			return false;
		}
		$cal_uid       = (string) ( $row['cal_booking_id'] ?? '' );
		$request_id    = (int) ( $row['job_request_id'] ?? 0 );

		// Clear the parent's back-pointer if THIS booking was the
		// authoritative one for the request (matched by uid). Multiple
		// bookings on one request would each clear in turn — that's OK,
		// the column is a single-value pointer that the next booking-
		// upsert re-populates.
		if ( '' !== $cal_uid && $request_id > 0 ) {
			$wpdb->update(
				$requests,
				array( 'cal_booking_id' => '', 'cal_booking_url' => null ),
				array( 'id' => $request_id, 'cal_booking_id' => $cal_uid )
			);
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $booking_id ), array( '%d' ) );
		return false !== $deleted && $deleted > 0;
	}

	/**
	 * @param string $value Datetime.
	 * @return string|null
	 */
	protected function normalize_datetime( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		$timestamp = strtotime( (string) $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}
