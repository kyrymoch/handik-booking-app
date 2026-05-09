<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project Work Days flow.
 *
 * State machine for the schedule row:
 *
 *   draft → selecting_days → days_selected → creating_bookings →
 *     confirmed                  (all N bookings created)
 *     partial_failed             (created some, rollback also failed)
 *     rolled_back                (created some, rollback succeeded → 0 bookings)
 *     cancelled                  (admin cancelled)
 *
 * Per work-day:
 *   selected → booking_creating → booking_created → confirmed
 *                              ↘ failed
 *                              ↘ rolled_back
 *                              ↘ cancelled
 */
class Handik_Booking_App_Project_Schedule_Service {
	const STATUS_DRAFT          = 'draft';
	const STATUS_SELECTING      = 'selecting_days';
	const STATUS_SELECTED       = 'days_selected';
	const STATUS_CREATING       = 'creating_bookings';
	const STATUS_CONFIRMED      = 'confirmed';
	const STATUS_PARTIAL_FAILED = 'partial_failed';
	const STATUS_ROLLED_BACK    = 'rolled_back';
	const STATUS_CANCELLED      = 'cancelled';

	const DAY_STATUS_SELECTED   = 'selected';
	const DAY_STATUS_CREATING   = 'booking_creating';
	const DAY_STATUS_CREATED    = 'booking_created';
	const DAY_STATUS_CONFIRMED  = 'confirmed';
	const DAY_STATUS_FAILED     = 'failed';
	const DAY_STATUS_CANCELLED  = 'cancelled';
	const DAY_STATUS_ROLLED_BACK = 'rolled_back';

	const SLOT_WINDOW_DAYS = 30;

	/** @var Handik_Booking_App_Booking_Presets_Service */
	protected $presets;
	/** @var Handik_Booking_App_Cal_Api_Service */
	protected $cal_api;
	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	public function __construct( $presets, $cal_api, $contacts, $addresses, $logger = null ) {
		$this->presets   = $presets;
		$this->cal_api   = $cal_api;
		$this->contacts  = $contacts;
		$this->addresses = $addresses;
		$this->logger    = $logger;

		// Daily cleanup cron — deletes abandoned schedules (still in
		// SELECTING / DRAFT after 7 days). Without this, every customer who
		// opened a project preset and walked away leaves a row + token in
		// the table forever. Cleanup is idempotent and skips anything that
		// has live Cal bookings or is in any "in progress" state.
		add_action( self::CRON_HOOK_GC, array( $this, 'gc_abandoned' ) );
		add_action( 'init', array( $this, 'maybe_schedule_gc' ) );
	}

	const CRON_HOOK_GC      = 'handik_booking_app_form_gc_abandoned';
	const GC_ABANDON_DAYS   = 7;

	/**
	 * Look up the operator's first name (default "Alex"). Convenience for
	 * the few error strings inside this class that mention them by name.
	 */
	protected static function operator_name() {
		$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
		if ( $plugin && isset( $plugin->settings ) ) {
			$name = trim( (string) $plugin->settings->get( 'operator_first_name', 'Alex' ) );
			if ( '' !== $name ) {
				return $name;
			}
		}
		return 'Alex';
	}

	/**
	 * Register the daily cron event if it isn't already scheduled.
	 */
	public function maybe_schedule_gc() {
		if ( ! wp_next_scheduled( self::CRON_HOOK_GC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_GC );
		}
	}

	/**
	 * Delete project_scheduling_requests rows that have been abandoned in a
	 * non-terminal state for longer than GC_ABANDON_DAYS, plus their
	 * project_work_days children. Logs the count.
	 */
	public function gc_abandoned() {
		global $wpdb;
		$schedules_table  = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$days_table       = Handik_Booking_App_DB::table( 'project_work_days' );
		$cutoff           = gmdate( 'Y-m-d H:i:s', time() - ( self::GC_ABANDON_DAYS * DAY_IN_SECONDS ) );

		// Only the customer-abandoned states — never touch CONFIRMED,
		// PARTIAL_FAILED (admin must intervene), or anything CREATING.
		$sql = $wpdb->prepare(
			"SELECT id FROM {$schedules_table}
			 WHERE status IN ( %s, %s )
			   AND updated_at < %s",
			self::STATUS_DRAFT,
			self::STATUS_SELECTING,
			$cutoff
		);
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $ids ) ) {
			return;
		}
		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$days_table} WHERE scheduling_request_id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$deleted = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$schedules_table} WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $this->logger && $deleted > 0 ) {
			$this->logger->info(
				'Garbage-collected abandoned project schedules.',
				array(
					'count'         => $deleted,
					'cutoff_days'   => self::GC_ABANDON_DAYS,
				)
			);
		}
	}

	/**
	 * Step 1+2: contact + address. Returns schedule_id and public_token.
	 *
	 * @param string               $slug    Preset slug.
	 * @param array<string, mixed> $payload Same shape as Direct submit().
	 * @return array{schedule_id?: int, public_token?: string, required_days?: int, error?: string, status?: int}
	 */
	public function open_schedule( $slug, array $payload ) {
		$preset = $this->presets->find_by_slug( $slug );
		if ( ! $preset || empty( $preset['enabled'] ) || self::form_type( $preset ) !== Handik_Booking_App_Booking_Presets_Service::FORM_TYPE_PROJECT ) {
			return array(
				'error'  => __( 'This booking form is not available right now.', 'handik-booking-app' ),
				'status' => 404,
			);
		}

		$required_days = (int) ( $preset['required_days'] ?? 0 );
		if ( $required_days < 1 ) {
			return array(
				'error'  => __( 'This project preset is misconfigured (required days missing).', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$contact_payload = array(
			'full_name' => isset( $payload['full_name'] ) ? sanitize_text_field( (string) $payload['full_name'] ) : '',
			'phone'     => isset( $payload['phone'] ) ? sanitize_text_field( (string) $payload['phone'] ) : '',
			'email'     => isset( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '',
			'source'    => 'project_work_days_form',
		);
		if ( '' === $contact_payload['full_name'] ) {
			return array(
				'error'  => __( 'Please enter your full name.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		if ( '' === $contact_payload['phone'] ) {
			return array(
				'error'  => __( 'Please enter a phone number.', 'handik-booking-app' ),
				'status' => 400,
			);
		}

		$contact_id = $this->contacts->upsert( $contact_payload );
		if ( ! $contact_id ) {
			return array(
				'error'  => __( 'Could not save contact details. Please try again.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		$address_full = isset( $payload['address_full'] ) ? sanitize_textarea_field( (string) $payload['address_full'] ) : '';
		if ( '' === $address_full ) {
			return array(
				'error'  => __( 'Please enter the address.', 'handik-booking-app' ),
				'status' => 400,
			);
		}
		$address_id = (int) $this->addresses->sync(
			$contact_id,
			array(
				'address_full' => $address_full,
				'address_unit' => isset( $payload['address_unit'] ) ? sanitize_text_field( (string) $payload['address_unit'] ) : '',
				'is_default'   => 1,
			)
		);
		// Defensive: addresses->sync returns 0 on validation failure. We
		// already validated address_full above; bail explicitly rather than
		// persisting a row pointing at #0.
		if ( $address_id <= 0 ) {
			return array(
				'error'  => __( 'Could not save the address. Please try again.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_scheduling_requests' );

		$row = array(
			'contact_id'                => $contact_id,
			'address_id'                => $address_id,
			'preset_slug'               => (string) $preset['preset_slug'],
			'form_title'                => (string) $preset['form_title'],
			'required_days'             => $required_days,
			'work_day_duration_minutes' => (int) ( $preset['work_day_duration_minutes'] ?? 480 ),
			'cal_event_type_id'         => (string) ( $preset['cal_event_type_id'] ?? '' ),
			'cal_event_slug'            => (string) ( $preset['cal_event_slug'] ?? '' ),
			'status'                    => self::STATUS_SELECTING,
			'public_token'              => wp_generate_password( 32, false, false ),
			'source_url'                => isset( $payload['source_url'] ) ? esc_url_raw( (string) $payload['source_url'] ) : '',
			'client_type'               => isset( $payload['client_type'] ) ? sanitize_key( (string) $payload['client_type'] ) : 'new_client',
			'client_ip'                 => Handik_Booking_App_Forms_Helpers::client_ip_packed(),
		);
		$wpdb->insert( $table, $row );
		$schedule_id = (int) $wpdb->insert_id;
		if ( ! $schedule_id ) {
			return array(
				'error'  => __( 'Could not save your project request. Please try again.', 'handik-booking-app' ),
				'status' => 500,
			);
		}

		if ( $this->logger ) {
			$this->logger->info(
				'Project schedule opened.',
				array(
					'schedule_id'  => $schedule_id,
					'preset_slug'  => $preset['preset_slug'],
					'required_days' => $required_days,
				)
			);
		}

		return array(
			'schedule_id'   => $schedule_id,
			'public_token'  => $row['public_token'],
			'required_days' => $required_days,
			'preset_slug'   => $preset['preset_slug'],
		);
	}

	/**
	 * Fetch slots from Cal.com for the configured event type.
	 *
	 * @param string $preset_slug Preset slug.
	 * @param string $start_iso   Optional ISO start (defaults to now).
	 * @param string $end_iso     Optional ISO end (defaults to now+30d).
	 * @return array{slots?: array<int, array<string, mixed>>, error?: string, status?: int}
	 */
	public function fetch_slots( $preset_slug, $start_iso = '', $end_iso = '' ) {
		$preset = $this->presets->find_by_slug( $preset_slug );
		if ( ! $preset || empty( $preset['enabled'] ) ) {
			return array(
				'error'  => __( 'This booking form is not available right now.', 'handik-booking-app' ),
				'status' => 404,
			);
		}
		$tz = $this->cal_api->timezone();

		try {
			$now = new DateTimeImmutable( 'now', new DateTimeZone( $tz ) );
		} catch ( Exception $e ) {
			$now = new DateTimeImmutable( 'now' );
		}
		if ( '' === $start_iso ) {
			$start_iso = $now->format( 'Y-m-d\TH:i:sP' );
		}
		if ( '' === $end_iso ) {
			try {
				$end_iso = $now->modify( '+' . self::SLOT_WINDOW_DAYS . ' days' )->format( 'Y-m-d\TH:i:sP' );
			} catch ( Exception $e ) {
				$end_iso = $now->format( 'Y-m-d\TH:i:sP' );
			}
		}

		$result = $this->cal_api->get_slots(
			array(
				'event_type_id'    => $preset['cal_event_type_id'] ?? '',
				'event_slug'       => $preset['cal_event_slug'] ?? '',
				'duration_minutes' => (int) $preset['work_day_duration_minutes'],
				'start'            => $start_iso,
				'end'              => $end_iso,
				'timezone'         => $tz,
			)
		);
		if ( ! empty( $result['error'] ) ) {
			return $result;
		}
		return array(
			'slots'     => $result['slots'] ?? array(),
			'timezone'  => $tz,
		);
	}

	/**
	 * Step 3: persist client's selected slots. Idempotent: re-running with
	 * the same selection produces the same rows, refreshing day_index.
	 *
	 * @param int                                  $schedule_id Schedule ID.
	 * @param array<int, array{start: string, end?: string}> $selected   Slots.
	 * @return array{success?: true, days?: array<int, array<string, mixed>>, error?: string, status?: int}
	 */
	public function save_selection( $schedule_id, array $selected ) {
		$schedule = $this->get( $schedule_id );
		if ( ! $schedule ) {
			return array( 'error' => __( 'Schedule not found.', 'handik-booking-app' ), 'status' => 404 );
		}
		// Allow editing from any state where the customer can still legitimately
		// change their mind:
		//   - SELECTING     fresh schedule, no selection saved yet
		//   - SELECTED      already saved a selection, picking again before confirm
		//   - ROLLED_BACK   previous confirm hit a stale slot mid-flight, all
		//                   created bookings were cancelled, customer should be
		//                   able to pick a fresh set without admin intervention
		// CREATING / CONFIRMED / PARTIAL_FAILED / CANCELLED are terminal-ish
		// and require an admin to unstick them.
		$editable = array(
			self::STATUS_SELECTING,
			self::STATUS_SELECTED,
			self::STATUS_ROLLED_BACK,
		);
		if ( ! in_array( $schedule['status'], $editable, true ) ) {
			return array(
				'error'  => __( 'This schedule can no longer be edited.', 'handik-booking-app' ),
				'status' => 409,
			);
		}
		$required = (int) $schedule['required_days'];
		if ( count( $selected ) !== $required ) {
			return array(
				'error'  => sprintf(
					/* translators: 1: required count, 2: selected count */
					__( 'Please select exactly %1$d days. You selected %2$d.', 'handik-booking-app' ),
					$required,
					count( $selected )
				),
				'status' => 400,
			);
		}

		// Sort by start time so day_index reflects chronological order.
		usort(
			$selected,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['start'] ?? '' ), (string) ( $b['start'] ?? '' ) );
			}
		);

		global $wpdb;
		$days_table = Handik_Booking_App_DB::table( 'project_work_days' );
		$wpdb->delete( $days_table, array( 'scheduling_request_id' => $schedule_id ) );

		$now    = current_time( 'mysql' );
		$rows   = array();
		foreach ( $selected as $i => $slot ) {
			$start_iso = isset( $slot['start'] ) ? sanitize_text_field( (string) $slot['start'] ) : '';
			$end_iso   = isset( $slot['end'] ) ? sanitize_text_field( (string) $slot['end'] ) : '';
			if ( '' === $start_iso ) {
				return array(
					'error'  => __( 'One of the selected days is missing a start time.', 'handik-booking-app' ),
					'status' => 400,
				);
			}
			if ( '' === $end_iso ) {
				$end_iso = $this->derive_end_iso( $start_iso, (int) $schedule['work_day_duration_minutes'] );
			}
			$wpdb->insert(
				$days_table,
				array(
					'scheduling_request_id' => $schedule_id,
					'day_index'             => $i + 1,
					'start_iso'             => $start_iso,
					'end_iso'               => $end_iso,
					'start_time'            => $this->iso_to_mysql_utc( $start_iso ),
					'end_time'              => $this->iso_to_mysql_utc( $end_iso ),
					'status'                => self::DAY_STATUS_SELECTED,
					'client_selected_at'    => $now,
				)
			);
			$rows[] = array(
				'day_index' => $i + 1,
				'start_iso' => $start_iso,
				'end_iso'   => $end_iso,
			);
		}

		$wpdb->update(
			Handik_Booking_App_DB::table( 'project_scheduling_requests' ),
			array( 'status' => self::STATUS_SELECTED ),
			array( 'id' => $schedule_id )
		);

		if ( $this->logger ) {
			$this->logger->info(
				'Project schedule selection saved.',
				array(
					'schedule_id'   => $schedule_id,
					'required_days' => $required,
					'selected'      => count( $rows ),
				)
			);
		}
		return array( 'success' => true, 'days' => $rows );
	}

	/**
	 * Step 5: re-check availability and create N Cal.com bookings. Rolls
	 * back on partial failure.
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return array{success?: true, status?: string, missing?: array<int, array<string, mixed>>, error?: string}
	 */
	public function confirm_schedule( $schedule_id ) {
		$schedule = $this->get( $schedule_id );
		if ( ! $schedule ) {
			return array( 'error' => __( 'Schedule not found.', 'handik-booking-app' ), 'status' => 404 );
		}
		// Idempotent: a confirmed schedule is a confirmed schedule.
		if ( self::STATUS_CONFIRMED === $schedule['status'] ) {
			return array( 'success' => true, 'status' => self::STATUS_CONFIRMED );
		}
		// State machine — the only state that can confirm is SELECTED. Earlier
		// builds also listed CREATING / PARTIAL_FAILED / ROLLED_BACK in the
		// allow-list, but the CAS lock below only matches SELECTED → CREATING,
		// so those branches just 409'd forever instead of retrying. Now we say
		// it explicitly:
		//   - CREATING        another request is in flight; reject as 409
		//   - PARTIAL_FAILED  some bookings live on Cal, rollback failed —
		//                     admin must intervene before any retry
		//   - ROLLED_BACK     all bookings cancelled, customer must redo
		//                     save_selection (which moves us back to SELECTED)
		//                     before confirming again
		if ( self::STATUS_CREATING === $schedule['status'] ) {
			return array(
				'error'  => __( 'This schedule is already being processed.', 'handik-booking-app' ),
				'status' => 409,
				'state'  => self::STATUS_CREATING,
			);
		}
		if ( self::STATUS_PARTIAL_FAILED === $schedule['status'] ) {
			return array(
				/* translators: %s: operator first name (Alex by default). */
				'error'  => sprintf( __( 'This schedule needs admin attention. %s will follow up.', 'handik-booking-app' ), self::operator_name() ),
				'status' => 409,
				'state'  => self::STATUS_PARTIAL_FAILED,
			);
		}
		if ( self::STATUS_ROLLED_BACK === $schedule['status'] ) {
			return array(
				'error'  => __( 'Please pick your project days again before confirming.', 'handik-booking-app' ),
				'status' => 409,
				'state'  => self::STATUS_ROLLED_BACK,
			);
		}
		if ( self::STATUS_SELECTED !== $schedule['status'] ) {
			return array(
				'error'  => __( 'This schedule cannot be confirmed.', 'handik-booking-app' ),
				'status' => 409,
				'state'  => (string) $schedule['status'],
			);
		}

		$days = $this->list_days( $schedule_id );
		if ( count( $days ) !== (int) $schedule['required_days'] ) {
			return array(
				'error'  => __( 'Please re-select the project days before confirming.', 'handik-booking-app' ),
				'status' => 400,
			);
		}

		// Lock state to prevent re-entrancy via double-click.
		$locked = $this->try_set_status( $schedule_id, self::STATUS_SELECTED, self::STATUS_CREATING );
		if ( ! $locked ) {
			$fresh = $this->get( $schedule_id );
			return array(
				'error'  => __( 'This schedule is already being processed.', 'handik-booking-app' ),
				'status' => 409,
				'state'  => $fresh ? $fresh['status'] : '',
			);
		}

		// Re-check availability in a 1-day window around each selected slot.
		$missing = $this->find_missing( $schedule, $days );
		if ( ! empty( $missing ) ) {
			$this->try_set_status( $schedule_id, self::STATUS_CREATING, self::STATUS_SELECTED );
			if ( $this->logger ) {
				$this->logger->warning(
					'Project schedule re-check found missing slots.',
					array(
						'schedule_id' => $schedule_id,
						'missing'     => count( $missing ),
					)
				);
			}
			return array(
				'error'   => __( 'One or more selected days are no longer available. Please pick replacement days.', 'handik-booking-app' ),
				'status'  => 409,
				'missing' => $missing,
			);
		}

		// Create bookings sequentially. Track created UIDs for rollback.
		$contact   = $this->contacts->get( (int) $schedule['contact_id'] );
		$address   = $this->get_address_full( (int) $schedule['address_id'] );
		$created   = array();
		$preset    = $this->presets->find_by_slug( (string) $schedule['preset_slug'] );

		foreach ( $days as $day ) {
			$this->update_day(
				(int) $day['id'],
				array( 'status' => self::DAY_STATUS_CREATING )
			);
			$booking = $this->cal_api->create_booking(
				array(
					'event_type_id'    => $schedule['cal_event_type_id'],
					'event_slug'       => $schedule['cal_event_slug'],
					'start'            => $day['start_iso'],
					'end'              => $day['end_iso'],
					'duration_minutes' => (int) $schedule['work_day_duration_minutes'],
					'attendee'         => array(
						'name'        => (string) ( $contact['full_name'] ?? '' ),
						'email'       => (string) ( $contact['email'] ?? '' ),
						'phoneNumber' => (string) ( $contact['phone'] ?? '' ),
					),
					'location'         => '' !== $address ? array(
						'type'    => 'attendeeAddress',
						'address' => $address,
					) : null,
					'metadata'         => $this->booking_metadata( $schedule, $day, $preset ),
					'notes'            => $this->booking_notes( $schedule, $day, $contact, $address ),
					'idempotency_key'  => sprintf( 'handik-project-%d-day-%d', (int) $schedule['id'], (int) $day['day_index'] ),
				)
			);

			if ( ! empty( $booking['error'] ) ) {
				$this->update_day(
					(int) $day['id'],
					array(
						'status'        => self::DAY_STATUS_FAILED,
						'error_message' => substr( (string) $booking['error'], 0, 500 ),
					)
				);
				if ( $this->logger ) {
					$this->logger->error(
						'Project schedule booking creation failed.',
						array(
							'schedule_id' => $schedule_id,
							'day_index'   => $day['day_index'],
							'error'       => $booking['error'],
						)
					);
				}
				return $this->rollback_after_failure( $schedule_id, $created, (string) $booking['error'] );
			}

			$cal_booking = $booking['booking'];
			$this->update_day(
				(int) $day['id'],
				array(
					'status'           => self::DAY_STATUS_CREATED,
					'cal_booking_id'   => (string) $cal_booking['id'],
					'cal_booking_uid'  => (string) $cal_booking['uid'],
					'cal_booking_url'  => (string) $cal_booking['url'],
				)
			);
			$created[] = array(
				'day_id'  => (int) $day['id'],
				'uid'     => (string) $cal_booking['uid'],
			);
		}

		// All N bookings created. Mark confirmed.
		global $wpdb;
		$wpdb->update(
			Handik_Booking_App_DB::table( 'project_scheduling_requests' ),
			array(
				'status'       => self::STATUS_CONFIRMED,
				'confirmed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $schedule_id )
		);
		// Mark each day as confirmed.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Handik_Booking_App_DB::table( 'project_work_days' ) . ' SET status = %s, confirmed_at = %s WHERE scheduling_request_id = %d AND status = %s',
				self::DAY_STATUS_CONFIRMED,
				current_time( 'mysql' ),
				$schedule_id,
				self::DAY_STATUS_CREATED
			)
		);

		if ( $this->logger ) {
			$this->logger->info(
				'Project schedule confirmed.',
				array(
					'schedule_id' => $schedule_id,
					'days'        => count( $days ),
				)
			);
		}

		// Sprint 14a — single email per schedule (not per day); the
		// .ics attachment carries one VEVENT per day so the customer
		// gets the full picture in one calendar invite.
		if ( class_exists( 'Handik_Booking_App_Notifications_Service' ) ) {
			Handik_Booking_App_Notifications_Service::dispatch_for_project( (int) $schedule_id );
		}

		return array( 'success' => true, 'status' => self::STATUS_CONFIRMED );
	}

	/**
	 * Rollback partially-created bookings. If all cancellations succeed →
	 * STATUS_ROLLED_BACK; otherwise STATUS_PARTIAL_FAILED with admin warning.
	 *
	 * @param int                                            $schedule_id Schedule ID.
	 * @param array<int, array{day_id: int, uid: string}>   $created     Created so far.
	 * @param string                                         $error       Original error.
	 * @return array{error: string, status: int}
	 */
	protected function rollback_after_failure( $schedule_id, array $created, $error ) {
		global $wpdb;
		$days_table = Handik_Booking_App_DB::table( 'project_work_days' );
		$schedule_table = Handik_Booking_App_DB::table( 'project_scheduling_requests' );

		$rollback_failed = false;
		foreach ( $created as $entry ) {
			$cancel = $this->cal_api->cancel_booking( $entry['uid'], 'Rollback after partial failure' );
			$status = empty( $cancel['error'] ) ? self::DAY_STATUS_ROLLED_BACK : self::DAY_STATUS_FAILED;
			$wpdb->update(
				$days_table,
				array(
					'status'        => $status,
					'error_message' => empty( $cancel['error'] ) ? null : substr( (string) $cancel['error'], 0, 500 ),
				),
				array( 'id' => $entry['day_id'] )
			);
			if ( ! empty( $cancel['error'] ) ) {
				$rollback_failed = true;
				if ( $this->logger ) {
					$this->logger->error(
						'Project schedule rollback cancel failed.',
						array(
							'schedule_id' => $schedule_id,
							'cal_uid'     => $entry['uid'],
							'error'       => $cancel['error'],
						)
					);
				}
			}
		}

		$final_status = $rollback_failed ? self::STATUS_PARTIAL_FAILED : self::STATUS_ROLLED_BACK;
		$wpdb->update(
			$schedule_table,
			array(
				'status'        => $final_status,
				'error_message' => substr( $error, 0, 500 ),
			),
			array( 'id' => $schedule_id )
		);

		$message = $rollback_failed
			/* translators: %s: operator first name (Alex by default). */
			? sprintf( __( 'Some selected days could not be confirmed. %s will review and follow up.', 'handik-booking-app' ), self::operator_name() )
			: __( 'One of your selected days could not be confirmed and the others were released. Please pick a new set.', 'handik-booking-app' );

		return array(
			'error'  => $message,
			'status' => 502,
			'state'  => $final_status,
		);
	}

	// ---------- queries ---------------------------------------------------

	/**
	 * @param int $id Schedule ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param int $schedule_id Schedule ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_days( $schedule_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_work_days' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE scheduling_request_id = %d ORDER BY day_index ASC", $schedule_id ),
			ARRAY_A
		);
	}

	/**
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", max( 1, (int) $limit ) ),
			ARRAY_A
		);
	}

	/**
	 * @param string $uid Cal booking UID.
	 * @return array<string, mixed>|null
	 */
	public function find_day_by_uid( $uid ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_work_days' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE cal_booking_uid = %s LIMIT 1", (string) $uid ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function update_day_status_by_uid( $uid, $status ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_work_days' );
		$wpdb->update(
			$table,
			array( 'status' => sanitize_key( $status ) ),
			array( 'cal_booking_uid' => (string) $uid )
		);
	}

	/**
	 * Admin action: cancel a single project work day on Cal.com and mark
	 * the local row CANCELLED. Returns the operation result so the admin
	 * page can render a toast.
	 *
	 * @param int $day_id  Project work day primary key.
	 * @param string $reason Optional reason logged in Cal.
	 * @return array{success?: true, error?: string, status?: int}
	 */
	public function admin_cancel_day( $day_id, $reason = '' ) {
		global $wpdb;
		$days_table = Handik_Booking_App_DB::table( 'project_work_days' );
		$row        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$days_table} WHERE id = %d LIMIT 1", (int) $day_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return array(
				'error'  => __( 'Work day not found.', 'handik-booking-app' ),
				'status' => 404,
			);
		}
		$uid = (string) $row['cal_booking_uid'];
		if ( '' !== $uid ) {
			$cancel = $this->cal_api->cancel_booking( $uid, '' !== $reason ? $reason : 'Cancelled by admin' );
			if ( ! empty( $cancel['error'] ) ) {
				if ( $this->logger ) {
					$this->logger->error(
						'Admin cancel_day Cal API failed.',
						array(
							'day_id' => (int) $day_id,
							'uid'    => $uid,
							'error'  => $cancel['error'],
						)
					);
				}
				return $cancel;
			}
		}
		$wpdb->update(
			$days_table,
			array( 'status' => self::DAY_STATUS_CANCELLED ),
			array( 'id' => (int) $day_id )
		);
		if ( $this->logger ) {
			$this->logger->info(
				'Admin cancelled project work day.',
				array( 'day_id' => (int) $day_id, 'uid' => $uid )
			);
		}
		return array( 'success' => true );
	}

	// ---------- internals -------------------------------------------------

	protected function update_day( $day_id, array $data ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'project_work_days' );
		$wpdb->update( $table, $data, array( 'id' => (int) $day_id ) );
	}

	/**
	 * Compare-and-swap on schedule.status. Returns true if updated.
	 *
	 * @param int    $schedule_id  Schedule ID.
	 * @param string $expected     Expected current status.
	 * @param string $next         New status.
	 * @return bool
	 */
	protected function try_set_status( $schedule_id, $expected, $next ) {
		global $wpdb;
		$table   = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE id = %d AND status = %s",
				$next,
				$schedule_id,
				$expected
			)
		);
		return (int) $updated > 0;
	}

	/**
	 * @param array<string, mixed>           $schedule Schedule row.
	 * @param array<int, array<string, mixed>> $days   Day rows.
	 * @return array<int, array<string, mixed>> Missing days (empty if all ok).
	 */
	protected function find_missing( array $schedule, array $days ) {
		if ( empty( $days ) ) {
			return array();
		}

		$starts = array();
		foreach ( $days as $d ) {
			$starts[] = (string) $d['start_iso'];
		}
		sort( $starts );

		try {
			$first = new DateTimeImmutable( $starts[0] );
			$last  = new DateTimeImmutable( end( $starts ) );
			$start_iso = $first->modify( '-1 day' )->format( 'Y-m-d\TH:i:sP' );
			$end_iso   = $last->modify( '+1 day' )->format( 'Y-m-d\TH:i:sP' );
		} catch ( Exception $e ) {
			$start_iso = $starts[0];
			$end_iso   = end( $starts );
		}

		$result = $this->cal_api->get_slots(
			array(
				'event_type_id'    => $schedule['cal_event_type_id'],
				'event_slug'       => $schedule['cal_event_slug'],
				'duration_minutes' => (int) $schedule['work_day_duration_minutes'],
				'start'            => $start_iso,
				'end'              => $end_iso,
			)
		);
		if ( ! empty( $result['error'] ) ) {
			// Treat API failure as "we cannot verify" — be conservative and
			// mark all selected days as missing so the client picks again.
			return $days;
		}

		$available = array();
		foreach ( ( $result['slots'] ?? array() ) as $slot ) {
			if ( ! empty( $slot['start_iso'] ) ) {
				$available[ (string) $slot['start_iso'] ] = true;
			}
		}

		$missing = array();
		foreach ( $days as $d ) {
			if ( empty( $available[ (string) $d['start_iso'] ] ) ) {
				$missing[] = array(
					'day_index' => (int) $d['day_index'],
					'start_iso' => (string) $d['start_iso'],
					'end_iso'   => (string) $d['end_iso'],
				);
			}
		}
		return $missing;
	}

	/**
	 * @param array<string, mixed> $schedule Schedule row.
	 * @param array<string, mixed> $day      Day row.
	 * @param array<string, mixed>|null $preset Preset row.
	 * @return array<string, string>
	 */
	protected function booking_metadata( array $schedule, array $day, $preset ) {
		return array(
			'handik_booking_source'    => 'project_work_days_form',
			'handik_project_schedule_id' => (string) (int) $schedule['id'],
			'handik_project_day_index' => (string) (int) $day['day_index'],
			'handik_project_total_days' => (string) (int) $schedule['required_days'],
			'handik_preset_slug'       => (string) $schedule['preset_slug'],
			'handik_contact_id'        => (string) (int) $schedule['contact_id'],
			'handik_address_id'        => (string) (int) $schedule['address_id'],
			'handik_booking_context'   => 'approved_project_work',
		);
	}

	/**
	 * @param array<string, mixed> $schedule Schedule row.
	 * @param array<string, mixed> $day      Day row.
	 * @param array<string, mixed>|null $contact Contact row.
	 * @param string $address Address line.
	 * @return string
	 */
	protected function booking_notes( array $schedule, array $day, $contact, $address ) {
		$total = (int) $schedule['required_days'];
		$idx   = (int) $day['day_index'];
		$lines = array(
			sprintf( 'Handik Project Work Day — Day %1$d of %2$d', $idx, $total ),
			sprintf( 'Schedule #%d', (int) $schedule['id'] ),
			sprintf( 'Client: %s', (string) ( $contact['full_name'] ?? '' ) ),
			sprintf( 'Phone: %s', (string) ( $contact['phone'] ?? '' ) ),
		);
		if ( '' !== $address ) {
			$lines[] = 'Address: ' . $address;
		}
		return implode( "\n", $lines );
	}

	protected function get_address_full( $address_id ) {
		if ( $address_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'addresses' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT address_full, address_unit FROM {$table} WHERE id = %d LIMIT 1", $address_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return '';
		}
		return trim( implode( ', ', array_filter( array( (string) $row['address_full'], (string) $row['address_unit'] ) ) ) );
	}

	protected function iso_to_mysql_utc( $iso ) {
		try {
			$dt = new DateTimeImmutable( (string) $iso );
			$dt = $dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	protected function derive_end_iso( $start_iso, $minutes ) {
		try {
			$dt = new DateTimeImmutable( (string) $start_iso );
			$dt = $dt->modify( '+' . max( 1, (int) $minutes ) . ' minutes' );
			return $dt->format( 'Y-m-d\TH:i:sP' );
		} catch ( Exception $e ) {
			return $start_iso;
		}
	}

	// `client_ip_packed` moved to Forms_Helpers (Cloudflare-aware, shared).

	protected static function form_type( array $preset ) {
		return isset( $preset['form_type'] ) ? (string) $preset['form_type'] : '';
	}
}
