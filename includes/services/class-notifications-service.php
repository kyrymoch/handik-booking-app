<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprint 14a — Branded customer confirmation emails.
 *
 * Subscribes to a single new action `handik_booking_confirmed` fired
 * from the three booking-creation sites:
 *
 *   - Bookings_Service::upsert_from_cal()              (main SPA Cal flow)
 *   - Direct_Booking_Service::capture_booking()        (Additional Forms direct preset)
 *   - Project_Schedule_Service::confirm_schedule()     (Additional Forms project work-days)
 *
 * Owners can hook the same action themselves later for Slack / SMS / etc.
 * — same extensibility pattern as the existing `handik_booking_app_send_sms_code`
 * action on Auth_Service.
 *
 * Idempotency: every trigger site supplies a `(table, row_id)` pair pointing
 * at the table whose `confirmation_email_sent_at` column owns this booking's
 * "did we send the email?" timestamp. Migration 1.5.1 added the column to
 * three tables (bookings, direct_booking_requests, project_scheduling_requests).
 * The atomic `UPDATE … WHERE id = %d AND confirmation_email_sent_at IS NULL`
 * means whichever path arrives first wins; the other gets zero affected_rows
 * and bails. On wp_mail failure the stamp rolls back to NULL so a manual
 * retry can re-fire.
 *
 * Day 1 (this commit): skeleton + atomic idempotency lock + action wiring.
 * Day 2 wires the actual customer-email send pipeline + .ics attachment.
 * Day 3 surfaces the admin settings tab + Send Test button.
 */
class Handik_Booking_App_Notifications_Service {
	const ACTION_BOOKING_CONFIRMED = 'handik_booking_confirmed';

	/**
	 * Allow-list of short table names we accept for idempotency. Defends
	 * against a forged context payload pointing at e.g. wp_users.
	 *
	 * @var array<int, string>
	 */
	const IDEMPOTENCY_TABLES = array(
		'bookings',
		'direct_booking_requests',
		'project_scheduling_requests',
	);

	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @var Handik_Booking_App_Logger|null
	 */
	protected $logger;

	/**
	 * @param Handik_Booking_App_Settings   $settings Settings.
	 * @param Handik_Booking_App_Logger|null $logger   Logger (optional).
	 */
	public function __construct( $settings, $logger = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;

		add_action( self::ACTION_BOOKING_CONFIRMED, array( $this, 'handle_booking_confirmed' ), 10, 1 );
	}

	/**
	 * Action callback. Runs the idempotency check and (if claimed) hands off
	 * to the customer-email send path.
	 *
	 * @param array<string, mixed> $context See §4.1 of docs/SPRINT-14-EMAIL-PLAN.md
	 *                                       for the shape. Required keys:
	 *                                       `idempotency` => array( 'table' => string, 'row_id' => int ),
	 *                                       `source`, `contact`, `when`.
	 *                                       Optional: `address`, `tasks`, `booking_url`,
	 *                                       `restart_url`, `request_id`, `booking_id`,
	 *                                       `cal_booking_uid`.
	 * @return void
	 */
	public function handle_booking_confirmed( $context ) {
		if ( ! is_array( $context ) ) {
			return;
		}

		// Master toggle — defaults OFF on upgrade. Customer keeps receiving
		// Cal.com's email until the owner has both disabled Cal-side AND
		// enabled this. See readme Cal-disable instructions.
		if ( ! $this->customer_confirmations_enabled() ) {
			return;
		}

		$idempotency = isset( $context['idempotency'] ) && is_array( $context['idempotency'] )
			? $context['idempotency']
			: array();
		$table_short = isset( $idempotency['table'] ) ? (string) $idempotency['table'] : '';
		$row_id      = isset( $idempotency['row_id'] ) ? (int) $idempotency['row_id'] : 0;

		if ( ! $row_id || ! in_array( $table_short, self::IDEMPOTENCY_TABLES, true ) ) {
			if ( $this->logger ) {
				$this->logger->warning(
					'Notifications: handik_booking_confirmed fired with invalid idempotency context.',
					array(
						'source'      => $context['source'] ?? '',
						'table_short' => $table_short,
						'row_id'      => $row_id,
					)
				);
			}
			return;
		}

		// Customer must have an email — if not, nothing to send.
		$contact       = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$customer_email = isset( $contact['email'] ) ? sanitize_email( (string) $contact['email'] ) : '';
		if ( '' === $customer_email ) {
			if ( $this->logger ) {
				$this->logger->info(
					'Notifications: skipping customer confirmation — contact has no email.',
					array(
						'source'     => $context['source'] ?? '',
						'contact_id' => $contact['id'] ?? 0,
					)
				);
			}
			return;
		}

		if ( ! $this->claim_idempotency( $table_short, $row_id ) ) {
			// Lost the race (or already sent). Silent — this is the expected
			// path for Cal webhook retries after a successful first send.
			return;
		}

		$sent = $this->send_customer_confirmation( $context, $customer_email );

		if ( ! $sent ) {
			$this->release_idempotency( $table_short, $row_id );
		}
	}

	/**
	 * Atomic check-and-set. Returns true iff this caller is the one that
	 * just stamped `confirmation_email_sent_at` from NULL → now. Anyone
	 * else racing the same row gets false (zero affected_rows).
	 *
	 * @param string $table_short Short table name (allow-listed).
	 * @param int    $row_id      Row ID.
	 * @return bool
	 */
	protected function claim_idempotency( $table_short, $row_id ) {
		global $wpdb;
		$table   = Handik_Booking_App_DB::table( $table_short );
		$updated = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET confirmation_email_sent_at = UTC_TIMESTAMP()
			 WHERE id = %d AND confirmation_email_sent_at IS NULL",
			$row_id
		) );
		return ( (int) $updated ) > 0;
	}

	/**
	 * Roll the idempotency stamp back to NULL after a failed send so a
	 * manual retry can re-fire. Booking already happened — only the email
	 * failed.
	 *
	 * @param string $table_short Short table name (allow-listed).
	 * @param int    $row_id      Row ID.
	 * @return void
	 */
	protected function release_idempotency( $table_short, $row_id ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( $table_short );
		$wpdb->update(
			$table,
			array( 'confirmation_email_sent_at' => null ),
			array( 'id' => (int) $row_id )
		);
	}

	/**
	 * Day 2 wires the real send pipeline (multipart/alternative HTML +
	 * plain-text body, .ics attachment, wp_mail filter lifecycle). For
	 * now this is a stub so Day 1 can ship the skeleton + idempotency
	 * lock without sending anything.
	 *
	 * @param array<string, mixed> $context        Booking context.
	 * @param string               $customer_email Sanitized recipient.
	 * @return bool True on send success (so the idempotency stamp stays).
	 */
	protected function send_customer_confirmation( array $context, $customer_email ) {
		if ( $this->logger ) {
			$this->logger->info(
				'Notifications: customer-confirmation send path stub (Day 1 — real send lands in Day 2).',
				array(
					'source'        => $context['source'] ?? '',
					'recipient'     => $customer_email,
					'request_id'    => $context['request_id'] ?? 0,
					'cal_booking_uid' => $context['cal_booking_uid'] ?? '',
				)
			);
		}
		// Pretend the send succeeded so the idempotency stamp stays — Day 1
		// is about getting the action plumbing in place, not about producing
		// real customer-visible email. The master toggle defaults to OFF so
		// production sites won't reach this path until Day 2 is shipped.
		return true;
	}

	/**
	 * @return bool
	 */
	protected function customer_confirmations_enabled() {
		$value = $this->settings->get( 'customer_confirmations_enabled', '' );
		// Treat any truthy stored value as enabled (1, '1', true).
		return ! empty( $value );
	}

	/* ------------------------------------------------------------------ *
	 * Static dispatch helpers                                             *
	 * ------------------------------------------------------------------ *
	 * Trigger sites call these one-liners; they assemble the documented
	 * context shape (§4.1 of docs/SPRINT-14-EMAIL-PLAN.md) by reading
	 * from the plugin's already-DI'd services, then fire the action.
	 * Done as statics so the three trigger sites (Bookings_Service,
	 * Direct_Booking_Service, Project_Schedule_Service) don't need new
	 * constructor params and we avoid a circular DI chain (those three
	 * services are constructed before Notifications_Service).
	 *
	 * Returns void — failures hydrating context are logged + swallowed.
	 * The booking row is already persisted; failing to dispatch the
	 * action just means no email goes out (and no third-party listener
	 * fires for this booking).
	 */

	/**
	 * Cal flow trigger — fires after `Bookings_Service::upsert_from_cal()`
	 * stamps a `booked` status on the canonical row.
	 *
	 * @param int                  $job_request_id Job request ID.
	 * @param int                  $bookings_row_id Row ID in handik_bookings.
	 * @param array<string, mixed> $cal_payload    Cal webhook payload (already normalized).
	 * @return void
	 */
	public static function dispatch_for_cal( $job_request_id, $bookings_row_id, array $cal_payload ) {
		$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
		if ( ! $plugin || ! $plugin->job_requests || ! $plugin->contacts || ! $plugin->addresses ) {
			return;
		}
		$request = $plugin->job_requests->get( (int) $job_request_id );
		if ( ! $request ) {
			return;
		}
		$contact = $plugin->contacts->get( (int) ( $request['contact_id'] ?? 0 ) );
		$address = isset( $request['address_id'] ) ? $plugin->addresses->get( (int) $request['address_id'] ) : null;

		$context = array(
			'source'          => 'cal',
			'idempotency'     => array(
				'table'  => 'bookings',
				'row_id' => (int) $bookings_row_id,
			),
			'contact'         => self::flatten_contact( $contact ),
			'address'         => self::flatten_address( $address ),
			'tasks'           => self::flatten_tasks( $request['selected_tasks'] ?? array() ),
			'when'            => self::flatten_when_single( $cal_payload ),
			'booking_url'     => self::extract_cal_url( $cal_payload ),
			'restart_url'     => self::extract_restart_url( $cal_payload ),
			'request_id'      => (int) $job_request_id,
			'booking_id'      => (int) $bookings_row_id,
			'cal_booking_uid' => self::extract_cal_uid( $cal_payload ),
		);

		do_action( self::ACTION_BOOKING_CONFIRMED, $context );
	}

	/**
	 * Direct booking trigger — fires from `Direct_Booking_Service::capture_booking()`
	 * after the OPENED → BOOKED transition completes (and the bookings
	 * mirror has been written).
	 *
	 * @param int                  $direct_request_id Direct request row ID.
	 * @param array<string, mixed> $cal_payload       Payload from the Cal embed
	 *                                                  (`bookingSuccessful` event).
	 * @return void
	 */
	public static function dispatch_for_direct( $direct_request_id, array $cal_payload ) {
		$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
		if ( ! $plugin || ! $plugin->direct_booking || ! $plugin->contacts || ! $plugin->addresses ) {
			return;
		}
		$row = $plugin->direct_booking->get( (int) $direct_request_id );
		if ( ! $row ) {
			return;
		}
		$contact = $plugin->contacts->get( (int) ( $row['contact_id'] ?? 0 ) );
		$address = isset( $row['address_id'] ) ? $plugin->addresses->get( (int) $row['address_id'] ) : null;

		// Direct rows store the preset slug + the chosen Cal event details
		// but no per-task list — the form is one-task-per-preset by design.
		// We surface the preset label as a single "task" entry so the
		// customer email's task list isn't empty.
		$tasks = array();
		$preset = ( $plugin->booking_presets && ! empty( $row['preset_slug'] ) )
			? $plugin->booking_presets->find_by_slug( (string) $row['preset_slug'] )
			: null;
		if ( $preset && ! empty( $preset['label'] ) ) {
			$tasks[] = array(
				'label'      => (string) $preset['label'],
				'rate_label' => '',
			);
		}

		$context = array(
			'source'          => 'direct',
			'idempotency'     => array(
				'table'  => 'direct_booking_requests',
				'row_id' => (int) $direct_request_id,
			),
			'contact'         => self::flatten_contact( $contact ),
			'address'         => self::flatten_address( $address ),
			'tasks'           => $tasks,
			'when'            => self::flatten_when_single( $cal_payload ),
			'booking_url'     => self::extract_cal_url( $cal_payload ),
			'restart_url'     => self::extract_restart_url( $cal_payload ),
			'request_id'      => (int) $direct_request_id,
			'booking_id'      => null,
			'cal_booking_uid' => self::extract_cal_uid( $cal_payload ),
		);

		do_action( self::ACTION_BOOKING_CONFIRMED, $context );
	}

	/**
	 * Project work-days trigger — fires once at the END of
	 * `Project_Schedule_Service::confirm_schedule()` after every day
	 * is persisted (single email listing all days with one .ics
	 * attachment containing one VEVENT per day).
	 *
	 * @param int $schedule_id Project schedule row ID.
	 * @return void
	 */
	public static function dispatch_for_project( $schedule_id ) {
		$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
		if ( ! $plugin || ! $plugin->project_schedule || ! $plugin->contacts || ! $plugin->addresses ) {
			return;
		}
		$schedule = $plugin->project_schedule->get( (int) $schedule_id );
		if ( ! $schedule ) {
			return;
		}
		$contact = $plugin->contacts->get( (int) ( $schedule['contact_id'] ?? 0 ) );
		$address = isset( $schedule['address_id'] ) ? $plugin->addresses->get( (int) $schedule['address_id'] ) : null;
		$days    = $plugin->project_schedule->list_days( (int) $schedule_id );

		$tasks = array();
		$preset = ( $plugin->booking_presets && ! empty( $schedule['preset_slug'] ) )
			? $plugin->booking_presets->find_by_slug( (string) $schedule['preset_slug'] )
			: null;
		if ( $preset && ! empty( $preset['label'] ) ) {
			$tasks[] = array(
				'label'      => (string) $preset['label'],
				'rate_label' => '',
			);
		}

		$flat_days = array();
		foreach ( (array) $days as $day ) {
			$flat_days[] = array(
				'start_iso' => (string) ( $day['start_iso'] ?? '' ),
				'end_iso'   => (string) ( $day['end_iso'] ?? '' ),
				'day_index' => (int) ( $day['day_index'] ?? 0 ),
			);
		}

		$context = array(
			'source'          => 'project',
			'idempotency'     => array(
				'table'  => 'project_scheduling_requests',
				'row_id' => (int) $schedule_id,
			),
			'contact'         => self::flatten_contact( $contact ),
			'address'         => self::flatten_address( $address ),
			'tasks'           => $tasks,
			'when'            => array(
				'days'     => $flat_days,
				'timezone' => (string) wp_timezone_string(),
			),
			'booking_url'     => '',
			'restart_url'     => '',
			'request_id'      => (int) $schedule_id,
			'booking_id'      => null,
			'cal_booking_uid' => '',
		);

		do_action( self::ACTION_BOOKING_CONFIRMED, $context );
	}

	/* ------------------------------------------------------------------ *
	 * Context-shape helpers                                               *
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed>|null $contact Contact row.
	 * @return array<string, mixed>
	 */
	protected static function flatten_contact( $contact ) {
		if ( ! is_array( $contact ) ) {
			return array( 'id' => 0, 'full_name' => '', 'phone' => '', 'email' => '' );
		}
		return array(
			'id'        => (int) ( $contact['id'] ?? 0 ),
			'full_name' => (string) ( $contact['full_name'] ?? '' ),
			'phone'     => (string) ( $contact['phone'] ?? '' ),
			'email'     => (string) ( $contact['email'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed>|null $address Address row.
	 * @return array<string, mixed>|null
	 */
	protected static function flatten_address( $address ) {
		if ( ! is_array( $address ) ) {
			return null;
		}
		return array(
			'address_full' => (string) ( $address['address_full'] ?? '' ),
			'address_unit' => (string) ( $address['address_unit'] ?? '' ),
		);
	}

	/**
	 * @param mixed $tasks Selected tasks (array of objects/arrays).
	 * @return array<int, array<string, string>>
	 */
	protected static function flatten_tasks( $tasks ) {
		if ( ! is_array( $tasks ) ) {
			return array();
		}
		$out = array();
		foreach ( $tasks as $task ) {
			if ( is_array( $task ) ) {
				$label = (string) ( $task['label'] ?? $task['title'] ?? '' );
				$rate  = (string) ( $task['rate_label'] ?? $task['rate'] ?? '' );
			} elseif ( is_object( $task ) ) {
				$label = (string) ( $task->label ?? $task->title ?? '' );
				$rate  = (string) ( $task->rate_label ?? $task->rate ?? '' );
			} else {
				$label = (string) $task;
				$rate  = '';
			}
			if ( '' !== $label ) {
				$out[] = array( 'label' => $label, 'rate_label' => $rate );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $payload Cal payload.
	 * @return array<string, string>
	 */
	protected static function flatten_when_single( array $payload ) {
		return array(
			'start_iso' => (string) ( $payload['startTime'] ?? $payload['start'] ?? '' ),
			'end_iso'   => (string) ( $payload['endTime'] ?? $payload['end'] ?? '' ),
			'timezone'  => (string) ( $payload['organizer']['timeZone'] ?? wp_timezone_string() ),
		);
	}

	/**
	 * @param array<string, mixed> $payload Cal payload.
	 * @return string
	 */
	protected static function extract_cal_url( array $payload ) {
		// Cal's payload may include rescheduleUrl / cancelUrl / bookingUrl.
		// Customer-facing reschedule is the most useful single link.
		foreach ( array( 'rescheduleUrl', 'reschedule_url', 'bookingUrl', 'booking_url', 'url' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				return esc_url_raw( (string) $payload[ $key ] );
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $payload Cal payload.
	 * @return string
	 */
	protected static function extract_restart_url( array $payload ) {
		// Best-effort: fall back to the site home when no explicit "book again"
		// URL is in the payload. The customer email can omit the link if both
		// booking_url and restart_url are empty.
		return home_url( '/' );
	}

	/**
	 * @param array<string, mixed> $payload Cal payload.
	 * @return string
	 */
	protected static function extract_cal_uid( array $payload ) {
		foreach ( array( 'uid', 'bookingUid', 'id', 'bookingId' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				return (string) $payload[ $key ];
			}
		}
		return '';
	}
}
