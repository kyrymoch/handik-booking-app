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
	const ACTION_BOOKING_CONFIRMED   = 'handik_booking_confirmed';
	const ACTION_BOOKING_CANCELLED   = 'handik_booking_cancelled';
	const ACTION_BOOKING_RESCHEDULED = 'handik_booking_rescheduled';

	/**
	 * Sprint 14c — short event-type code persisted in the new
	 * `last_status_emailed` column. Used as both the idempotency key
	 * and the human-facing label in admin logs.
	 */
	const EVENT_BOOKED      = 'booked';
	const EVENT_CANCELLED   = 'cancelled';
	const EVENT_RESCHEDULED = 'rescheduled';

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

		// 2.1.26.5 — wrap each action handler so a fatal inside email
		// rendering / .ics building / wp_mail SMTP doesn't take down
		// the booking-flow request that fired the action. Booking is
		// already committed by the time these run; email is best-
		// effort. Throwables get logged with file + line so we have
		// a forensic breadcrumb the next time this fires. Plus the
		// caller-side try/catch in confirm_schedule etc. is a second
		// belt-and-suspenders.
		add_action( self::ACTION_BOOKING_CONFIRMED, function ( $context ) {
			try { $this->handle_booking_confirmed( $context ); }
			catch ( \Throwable $e ) { $this->log_handler_throwable( 'handle_booking_confirmed', $e, $context ); }
		}, 10, 1 );
		add_action( self::ACTION_BOOKING_CANCELLED, function ( $context ) {
			try { $this->handle_booking_cancelled( $context ); }
			catch ( \Throwable $e ) { $this->log_handler_throwable( 'handle_booking_cancelled', $e, $context ); }
		}, 10, 1 );
		add_action( self::ACTION_BOOKING_RESCHEDULED, function ( $context ) {
			try { $this->handle_booking_rescheduled( $context ); }
			catch ( \Throwable $e ) { $this->log_handler_throwable( 'handle_booking_rescheduled', $e, $context ); }
		}, 10, 1 );

		// 2.1.26.7 — cron-deferred dispatch hook for the Project Work
		// Days form. confirm_schedule() now schedules this event with
		// `wp_schedule_single_event( time(), ... )` instead of calling
		// dispatch_for_project() synchronously, so the customer's
		// "You're all set" screen appears immediately after the Cal
		// API loop completes (saving 1-3s of synchronous wp_mail /
		// SMTP per confirm). The cron event fires on the next page
		// request (typically the success-page asset load, within
		// seconds on any active site).
		add_action( self::CRON_HOOK_DISPATCH_PROJECT, function ( $schedule_id ) {
			try { self::dispatch_for_project( (int) $schedule_id ); }
			catch ( \Throwable $e ) {
				if ( $this->logger ) {
					$this->logger->error(
						'Cron dispatch_for_project threw.',
						array(
							'schedule_id' => (int) $schedule_id,
							'message'     => $e->getMessage(),
							'file'        => $e->getFile(),
							'line'        => $e->getLine(),
						)
					);
				}
			}
		}, 10, 1 );
	}

	const CRON_HOOK_DISPATCH_PROJECT = 'handik_booking_app_dispatch_project_email';

	/**
	 * 2.1.26.5 — shared throwable-logger for the wrapped action
	 * handlers. Records the action name + the throwable's message,
	 * file, line, and a small slice of the context (enough to
	 * identify which booking, not so much that we leak template
	 * data into the log). Action handlers swallow the throwable
	 * after this — booking flow returns success.
	 *
	 * @param string         $handler_name Action handler method name.
	 * @param \Throwable     $e            The thrown error / exception.
	 * @param mixed          $context      Original action context (forensic identifiers).
	 * @return void
	 */
	protected function log_handler_throwable( $handler_name, \Throwable $e, $context ) {
		if ( ! $this->logger ) {
			return;
		}
		$slim = array();
		if ( is_array( $context ) ) {
			$slim['source']     = (string) ( $context['source']     ?? '' );
			$slim['request_id'] = (int)    ( $context['request_id'] ?? 0 );
			$slim['booking_id'] = (int)    ( $context['booking_id'] ?? 0 );
			$idem               = isset( $context['idempotency'] ) && is_array( $context['idempotency'] ) ? $context['idempotency'] : array();
			$slim['idempotency_table']  = (string) ( $idem['table']  ?? '' );
			$slim['idempotency_row_id'] = (int)    ( $idem['row_id'] ?? 0 );
		}
		$this->logger->error(
			sprintf( 'Notifications: %s threw — email skipped, booking flow continues.', $handler_name ),
			array(
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
				'context' => $slim,
			)
		);
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

		// Sprint 14b — both toggles are independent, both default OFF.
		// If neither side wants the email there's nothing to do; bail
		// before consuming an idempotency stamp so a future toggle-on
		// doesn't see "already sent" on every existing row.
		$want_customer = $this->customer_confirmations_enabled();
		$want_owner    = $this->owner_notifications_enabled();
		if ( ! $want_customer && ! $want_owner ) {
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

		$contact        = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$customer_email = isset( $contact['email'] ) ? sanitize_email( (string) $contact['email'] ) : '';
		$owner_email    = $want_owner ? $this->resolve_owner_address() : '';

		// Effective dispatch: customer needs the toggle on AND a valid
		// recipient; owner needs the toggle on AND a configured address
		// (which falls back to email_from_address if owner_notification_address
		// is empty).
		$do_customer = $want_customer && '' !== $customer_email;
		$do_owner    = $want_owner && '' !== $owner_email;
		if ( ! $do_customer && ! $do_owner ) {
			if ( $this->logger ) {
				$this->logger->info(
					'Notifications: skipping booking-confirmed dispatch — no valid recipients.',
					array(
						'source'        => $context['source'] ?? '',
						'contact_id'    => $contact['id'] ?? 0,
						'want_customer' => $want_customer,
						'want_owner'    => $want_owner,
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

		// One combined idempotency stamp covers both sides. Per plan
		// §6: "if either email fails, both retry". We consider the
		// dispatch successful iff every side we attempted succeeded;
		// any failure releases the stamp so a manual retry re-fires.
		// Sides that were never attempted (toggle off or no recipient)
		// don't count toward failure.
		$customer_ok = $do_customer ? $this->send_customer_confirmation( $context, $customer_email ) : true;
		$owner_ok    = $do_owner    ? $this->send_owner_notification( $context, $owner_email )       : true;

		if ( ! ( $customer_ok && $owner_ok ) ) {
			$this->release_idempotency( $table_short, $row_id );
		}
	}

	/**
	 * Sprint 14c — handle_booking_cancelled action callback.
	 * Fires when a Cal `BOOKING_CANCELLED` webhook lands. Idempotent
	 * via the new `last_status_emailed` column: webhook retries with
	 * the same status are no-ops, real state changes (booked →
	 * cancelled, or repeat cancellations after a re-book) each get one
	 * email.
	 *
	 * @param array<string, mixed> $context See dispatch_for_cal_cancel /
	 *                                       dispatch_for_direct_cancel.
	 * @return void
	 */
	public function handle_booking_cancelled( $context ) {
		$this->handle_status_event( $context, self::EVENT_CANCELLED );
	}

	/**
	 * Sprint 14c — handle_booking_rescheduled action callback.
	 *
	 * @param array<string, mixed> $context See dispatch_for_cal_reschedule /
	 *                                       dispatch_for_direct_reschedule.
	 * @return void
	 */
	public function handle_booking_rescheduled( $context ) {
		$this->handle_status_event( $context, self::EVENT_RESCHEDULED );
	}

	/**
	 * Shared body for cancelled + rescheduled handlers.
	 *
	 * @param mixed  $context Booking context (raw — validated here).
	 * @param string $event   self::EVENT_CANCELLED | self::EVENT_RESCHEDULED.
	 * @return void
	 */
	protected function handle_status_event( $context, $event ) {
		if ( ! is_array( $context ) ) {
			return;
		}

		// Toggle-gating: each side has its own enable flag. If both
		// are off, bail before consuming the idempotency stamp.
		$want_customer = self::EVENT_CANCELLED === $event
			? $this->customer_cancellation_enabled()
			: $this->customer_reschedule_enabled();
		$want_owner = $this->owner_notifications_enabled();
		if ( ! $want_customer && ! $want_owner ) {
			return;
		}

		$idempotency = isset( $context['idempotency'] ) && is_array( $context['idempotency'] )
			? $context['idempotency']
			: array();
		$table_short = isset( $idempotency['table'] ) ? (string) $idempotency['table'] : '';
		$row_id      = isset( $idempotency['row_id'] ) ? (int) $idempotency['row_id'] : 0;

		// Allow only the two tables that actually have last_status_emailed
		// (project schedules don't ship with cancel/reschedule support
		// in 14c — Migration 1.5.2 didn't add the column there).
		$status_idempotency_tables = array( 'bookings', 'direct_booking_requests' );
		if ( ! $row_id || ! in_array( $table_short, $status_idempotency_tables, true ) ) {
			if ( $this->logger ) {
				$this->logger->warning(
					'Notifications: status event fired with invalid idempotency context.',
					array( 'event' => $event, 'table_short' => $table_short, 'row_id' => $row_id )
				);
			}
			return;
		}

		$contact        = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$customer_email = isset( $contact['email'] ) ? sanitize_email( (string) $contact['email'] ) : '';
		$owner_email    = $want_owner ? $this->resolve_owner_address() : '';

		$do_customer = $want_customer && '' !== $customer_email;
		$do_owner    = $want_owner && '' !== $owner_email;
		if ( ! $do_customer && ! $do_owner ) {
			return;
		}

		if ( ! $this->claim_status_idempotency( $table_short, $row_id, $event ) ) {
			return; // duplicate event for this booking; already emailed.
		}

		// Customer first, owner second — same ordering as the booked
		// dispatch so LAST_EMAIL_ERROR_OPTION semantics match.
		$customer_ok = true;
		$owner_ok    = true;
		if ( $do_customer ) {
			$customer_ok = self::EVENT_CANCELLED === $event
				? $this->send_customer_cancellation( $context, $customer_email )
				: $this->send_customer_reschedule( $context, $customer_email );
		}
		if ( $do_owner ) {
			$owner_ok = self::EVENT_CANCELLED === $event
				? $this->send_owner_cancellation( $context, $owner_email )
				: $this->send_owner_reschedule( $context, $owner_email );
		}

		if ( ! ( $customer_ok && $owner_ok ) ) {
			$this->release_status_idempotency( $table_short, $row_id );
		}
	}

	/**
	 * Sprint 14c — atomic check-and-set on the `last_status_emailed`
	 * column. Returns true iff THIS caller is the one whose UPDATE
	 * succeeded (i.e. the column was either NULL or held a different
	 * status). Cal webhook retries with the same status get false here
	 * and bail.
	 *
	 * @param string $table_short Short table name.
	 * @param int    $row_id      Row ID.
	 * @param string $event       self::EVENT_* constant.
	 * @return bool
	 */
	protected function claim_status_idempotency( $table_short, $row_id, $event ) {
		global $wpdb;
		$table   = Handik_Booking_App_DB::table( $table_short );
		$updated = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET last_status_emailed = %s
			 WHERE id = %d AND ( last_status_emailed IS NULL OR last_status_emailed != %s )",
			$event,
			$row_id,
			$event
		) );
		return ( (int) $updated ) > 0;
	}

	/**
	 * Roll back the last_status_emailed value when a status email
	 * fails. Sets to NULL so a manual retry can re-fire.
	 *
	 * @param string $table_short Short table name.
	 * @param int    $row_id      Row ID.
	 * @return void
	 */
	protected function release_status_idempotency( $table_short, $row_id ) {
		global $wpdb;
		$table  = Handik_Booking_App_DB::table( $table_short );
		$result = $wpdb->update(
			$table,
			array( 'last_status_emailed' => null ),
			array( 'id' => (int) $row_id )
		);
		if ( false === $result && $this->logger ) {
			$this->logger->error(
				'Notifications: status idempotency rollback failed.',
				array( 'table_short' => $table_short, 'row_id' => (int) $row_id, 'wpdb_error' => $wpdb->last_error )
			);
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
		$table   = Handik_Booking_App_DB::table( $table_short );
		$result  = $wpdb->update(
			$table,
			array( 'confirmation_email_sent_at' => null ),
			array( 'id' => (int) $row_id )
		);
		// Hotfix 2.1.21.2 — surface a transient DB-error rollback failure
		// in the log. Plan §4.6 promised "manual retry can re-fire";
		// silently failing here would leave the stamp set forever and
		// block all subsequent retries on this booking.
		if ( false === $result && $this->logger ) {
			$this->logger->error(
				'Notifications: idempotency rollback failed; manual retry will be blocked.',
				array(
					'table_short' => $table_short,
					'row_id'      => (int) $row_id,
					'wpdb_error'  => $wpdb->last_error,
				)
			);
		}
	}

	/**
	 * Sprint 14a — admin "Send test email" handler. Builds a sample
	 * context (no DB lookups, no idempotency stamp) and runs through the
	 * same send pipeline production uses, so the operator can preview
	 * their template edits before flipping the master toggle on.
	 *
	 * Bypasses both the master toggle AND the atomic idempotency lock —
	 * test sends never touch the bookings tables.
	 *
	 * @param string                    $recipient_email Where to send.
	 * @param array<string, string>|null $template_overrides Optional
	 *        unsaved template values from the settings form (subject /
	 *        html / text / reply_to). Keys: customer_confirmation_subject,
	 *        customer_confirmation_body_html, customer_confirmation_body_text,
	 *        customer_confirmation_reply_to. Pass null to use saved.
	 * @return array{sent: bool, recipient: string, error?: string}
	 */
	public function send_test( $recipient_email, $template_overrides = null, $which = 'customer' ) {
		$recipient = sanitize_email( (string) $recipient_email );
		if ( '' === $recipient ) {
			return array( 'sent' => false, 'recipient' => '', 'error' => 'invalid_recipient' );
		}

		$overrides = is_array( $template_overrides ) ? $template_overrides : array();
		$context   = $this->build_sample_context();

		// Sprint 14c — sample context needs cancel/reschedule extras
		// for the cancel/reschedule preview emails. Pre-populated here
		// so the rendered placeholders ({{old_booking_when_long}},
		// {{cancellation_reason}}) show realistic sample data instead
		// of empty strings.
		if ( 'customer_reschedule' === $which || 'owner_reschedule' === $which ) {
			$context['old_when'] = array(
				'start_iso' => ( new DateTimeImmutable( '-2 days 14:00', wp_timezone() ) )->format( DATE_ATOM ),
				'end_iso'   => ( new DateTimeImmutable( '-2 days 16:00', wp_timezone() ) )->format( DATE_ATOM ),
				'timezone'  => (string) wp_timezone_string(),
			);
		}
		if ( 'customer_cancellation' === $which || 'owner_cancellation' === $which ) {
			$context['cancellation_reason'] = 'Customer asked to cancel — schedule conflict';
		}

		switch ( $which ) {
			case 'owner':
				$sent = $this->send_owner_notification( $context, $recipient, $overrides );
				break;
			case 'customer_cancellation':
				$sent = $this->send_customer_cancellation( $context, $recipient, $overrides );
				break;
			case 'customer_reschedule':
				$sent = $this->send_customer_reschedule( $context, $recipient, $overrides );
				break;
			case 'owner_cancellation':
				$sent = $this->send_owner_cancellation( $context, $recipient, $overrides );
				break;
			case 'owner_reschedule':
				$sent = $this->send_owner_reschedule( $context, $recipient, $overrides );
				break;
			case 'customer':
			default:
				$sent = $this->send_customer_confirmation( $context, $recipient, $overrides );
				break;
		}

		return array(
			'sent'      => $sent,
			'recipient' => $recipient,
			'which'     => $which,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function build_sample_context() {
		$tomorrow_2pm = ( new DateTimeImmutable( 'tomorrow 14:00', wp_timezone() ) )->format( DATE_ATOM );
		$tomorrow_4pm = ( new DateTimeImmutable( 'tomorrow 16:00', wp_timezone() ) )->format( DATE_ATOM );

		return array(
			'source'          => 'cal',
			'idempotency'     => array( 'table' => 'bookings', 'row_id' => 0 ),
			'contact'         => array(
				'id'        => 0,
				'full_name' => 'Jane Doe',
				'phone'     => '+1 555 123 4567',
				'email'     => 'jane@example.com',
			),
			'address'         => array(
				'address_full' => '123 Main St, Cambridge MA 02139',
				'address_unit' => '',
			),
			'tasks'           => array(
				array( 'label' => 'Plumbing', 'rate_label' => '' ),
				array( 'label' => 'Electrical (small fixture)', 'rate_label' => '' ),
				array( 'label' => 'Drywall patch', 'rate_label' => '' ),
			),
			'when'            => array(
				'start_iso' => $tomorrow_2pm,
				'end_iso'   => $tomorrow_4pm,
				'timezone'  => (string) wp_timezone_string(),
			),
			'booking_url'     => 'https://cal.com/handik/sample-booking',
			'restart_url'     => home_url( '/' ),
			'request_id'      => 0,
			'booking_id'      => null,
			'cal_booking_uid' => 'handik-test-' . substr( wp_generate_uuid4(), 0, 8 ),
		);
	}

	/**
	 * Send the branded customer-confirmation email.
	 *
	 * Builds subject + HTML body + plain-text body from the admin
	 * templates (with `{{placeholder}}` substitution), generates a
	 * .ics attachment via Ics_Builder, and ships through `wp_mail`
	 * configured for multipart/alternative. The plain-text alternative
	 * is set on the PHPMailer instance via `phpmailer_init` so clients
	 * that block HTML still see the message.
	 *
	 * Filter lifecycle is wrapped in try/finally so other plugins'
	 * wp_mail hooks aren't disturbed if our send throws.
	 *
	 * On wp_mail returning false:
	 *   - logs an error with the recipient + source for triage
	 *   - persists `LAST_EMAIL_ERROR_OPTION` (System info will surface
	 *     this in 14b)
	 *   - returns false so the caller releases the idempotency stamp
	 *     (allowing a manual retry via the admin "Resend" path that
	 *     Sprint 14b will add)
	 *
	 * @param array<string, mixed> $context        Booking context.
	 * @param string               $customer_email Sanitized recipient.
	 * @return bool True iff wp_mail accepted the message.
	 */
	protected function send_customer_confirmation( array $context, $customer_email, array $template_overrides = array() ) {
		$placeholders = $this->build_placeholders( $context );

		$resolve = function ( $key ) use ( $template_overrides ) {
			if ( array_key_exists( $key, $template_overrides ) ) {
				return (string) $template_overrides[ $key ];
			}
			return (string) $this->settings->get( $key, '' );
		};

		$subject_template = $resolve( 'customer_confirmation_subject' );
		$html_template    = $resolve( 'customer_confirmation_body_html' );
		$text_template    = $resolve( 'customer_confirmation_body_text' );

		// Hotfix 2.1.21.2 — render context-aware. Subject + HTML body
		// inherit user-controlled scalar placeholders (customer name,
		// address, from-name) which previously rendered raw; an
		// attacker controlling a CRM contact's full_name could land
		// `<img onerror>` in the email body. The pre-rendered list
		// tokens (`tasks_list_html`, `days_list_html`) stay raw because
		// they're already built with esc_html() inside build_placeholders().
		$subject = Handik_Booking_App_Admin_Helpers::render_template( $subject_template, $this->placeholders_for_subject( $placeholders ) );
		$html    = Handik_Booking_App_Admin_Helpers::render_template( $html_template, $this->placeholders_for_html( $placeholders ) );
		$text    = Handik_Booking_App_Admin_Helpers::render_template( $text_template, $placeholders );

		// Plain-text bodies should wrap at 78 octets (RFC 5322) — long
		// auto-generated lines render badly in legacy clients.
		$text = wordwrap( $text, 78, "\n", false );

		$ics_path = $this->write_ics_temp_file( $context );

		// Hotfix 2.1.21.2 — strip CR/LF from From-name to defend against
		// header injection if a future settings-save path stops sanitizing.
		// `sanitize_email` already rejects CR/LF in addresses; this hardens
		// the display-name half of the From: header.
		$from_name    = self::strip_header_breaks( (string) $this->settings->get( 'email_from_name', '' ) );
		$from_address = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		$reply_to     = sanitize_email( $resolve( 'customer_confirmation_reply_to' ) );
		if ( '' === $reply_to ) {
			$reply_to = $from_address;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $from_address ) {
			$from_label = '' !== $from_name
				? sprintf( '%s <%s>', $from_name, $from_address )
				: $from_address;
			$headers[] = 'From: ' . $from_label;
		}
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$attachments = ( '' !== $ics_path && file_exists( $ics_path ) ) ? array( $ics_path ) : array();

		// Filter callbacks live in closures so they reference the right
		// $text body without bleeding via $this. Both register before
		// wp_mail and remove() inside finally so failure can't leak them.
		$content_type_cb = static function () {
			return 'text/html';
		};
		$altbody_cb = static function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		// Tag the .ics attachment with the right Content-Type so calendar
		// apps render it as an invite instead of a generic file. Without
		// this Outlook + Apple Mail show "ics file" as a download icon.
		$ics_filename = '' !== $ics_path ? basename( $ics_path ) : '';
		$attachment_cb = static function ( $phpmailer ) use ( $ics_filename ) {
			if ( '' === $ics_filename ) {
				return;
			}
			$attachments = $phpmailer->getAttachments();
			$rebuilt     = array();
			$phpmailer->clearAttachments();
			foreach ( $attachments as $attachment ) {
				// PHPMailer's getAttachments() returns an indexed array per attachment:
				// 0:path, 1:filename, 2:name (basename), 3:encoding, 4:type, 5:isStringAttachment, 6:disposition, 7:CID
				$path     = $attachment[0];
				$filename = $attachment[2];
				if ( $filename === $ics_filename ) {
					$phpmailer->addAttachment(
						$path,
						$filename,
						'base64',
						'text/calendar; charset=UTF-8; method=REQUEST',
						'attachment'
					);
				} else {
					$phpmailer->addAttachment(
						$path,
						$filename,
						$attachment[3],
						$attachment[4],
						$attachment[6]
					);
				}
				$rebuilt[] = $filename;
			}
		};

		add_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );
		add_action( 'phpmailer_init', $altbody_cb, PHP_INT_MAX );
		if ( '' !== $ics_filename ) {
			// Run AFTER wp_mail's own attachment processing — PHPMailer
			// adds attachments before phpmailer_init fires, so this
			// callback (also at PHP_INT_MAX, but registered after the
			// AltBody one) re-attaches the .ics with the right MIME
			// type. Order between same-priority callbacks is insertion
			// order in WP.
			add_action( 'phpmailer_init', $attachment_cb, PHP_INT_MAX );
		}

		$sent = false;
		try {
			$sent = (bool) wp_mail( $customer_email, $subject, $html, $headers, $attachments );
		} catch ( \Throwable $e ) {
			$sent = false;
			if ( $this->logger ) {
				$this->logger->error(
					'Notifications: wp_mail threw.',
					array(
						'source'    => $context['source'] ?? '',
						'recipient' => $customer_email,
						'message'   => $e->getMessage(),
					)
				);
			}
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );
			remove_action( 'phpmailer_init', $altbody_cb, PHP_INT_MAX );
			if ( '' !== $ics_filename ) {
				remove_action( 'phpmailer_init', $attachment_cb, PHP_INT_MAX );
			}
			if ( '' !== $ics_path && file_exists( $ics_path ) ) {
				@unlink( $ics_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( ! $sent ) {
			update_option(
				'handik_booking_app_last_email_error',
				array(
					'time'    => time(),
					'message' => 'wp_mail returned false',
					'context' => array(
						'request_id' => (int) ( $context['request_id'] ?? 0 ),
						'source'     => (string) ( $context['source'] ?? '' ),
						'to'         => $customer_email,
					),
				),
				false
			);
			if ( $this->logger ) {
				$this->logger->error(
					'Notifications: wp_mail returned false.',
					array(
						'source'    => $context['source'] ?? '',
						'recipient' => $customer_email,
					)
				);
			}
			return false;
		}

		// Clear any prior error now that we've sent successfully.
		delete_option( 'handik_booking_app_last_email_error' );

		if ( $this->logger ) {
			$this->logger->info(
				'Notifications: customer confirmation sent.',
				array(
					'source'          => $context['source'] ?? '',
					'request_id'      => $context['request_id'] ?? 0,
					'recipient'       => $customer_email,
					'cal_booking_uid' => $context['cal_booking_uid'] ?? '',
				)
			);
		}
		return true;
	}

	/**
	 * Sprint 14b — owner-side "new booking from Jane" notification.
	 *
	 * Plain-text only; no .ics attachment (the owner sees the booking
	 * on Cal.com and in the admin Bookings list — adding a calendar
	 * invite would just clutter their personal calendar). Same
	 * `{{placeholder}}` engine as the customer side, plus the four
	 * owner-only tokens documented in the readme.
	 *
	 * @param array<string, mixed>    $context            Booking context.
	 * @param string                  $owner_email        Sanitized recipient.
	 * @param array<string, string>   $template_overrides Optional unsaved
	 *                                                    template values from
	 *                                                    the Send-Test path.
	 * @return bool True iff wp_mail accepted the message.
	 */
	protected function send_owner_notification( array $context, $owner_email, array $template_overrides = array() ) {
		$placeholders = $this->build_placeholders( $context );

		$resolve = function ( $key ) use ( $template_overrides ) {
			if ( array_key_exists( $key, $template_overrides ) ) {
				return (string) $template_overrides[ $key ];
			}
			return (string) $this->settings->get( $key, '' );
		};

		$subject_template = $resolve( 'owner_notification_subject' );
		$body_template    = $resolve( 'owner_notification_body' );

		// Hotfix 2.1.21.2 — same subject sanitization as customer side
		// (CR/LF + tag stripping defends against header injection via a
		// CRM contact name). Body stays raw — it's plain-text only,
		// HTML escaping would render literally.
		$subject = Handik_Booking_App_Admin_Helpers::render_template( $subject_template, $this->placeholders_for_subject( $placeholders ) );
		$body    = Handik_Booking_App_Admin_Helpers::render_template( $body_template, $placeholders );
		$body    = wordwrap( $body, 78, "\n", false );

		$from_name    = self::strip_header_breaks( (string) $this->settings->get( 'email_from_name', '' ) );
		$from_address = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );

		// Plain-text headers; owner-side never uses HTML. Reply-To is
		// the customer's email so a quick "got it, see you Tuesday"
		// reply lands directly with them — most useful single default.
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( '' !== $from_address ) {
			$from_label = '' !== $from_name
				? sprintf( '%s <%s>', $from_name, $from_address )
				: $from_address;
			$headers[] = 'From: ' . $from_label;
		}
		$contact         = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$customer_email  = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( '' !== $customer_email ) {
			$headers[] = 'Reply-To: ' . $customer_email;
		}

		// Owner email is plain-text: force wp_mail's content-type to
		// text/plain for the duration of this call (other plugins may
		// have set HTML as the global default). try/finally so any
		// throw still removes our filter.
		$content_type_cb = static function () {
			return 'text/plain';
		};
		add_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );

		$sent = false;
		try {
			$sent = (bool) wp_mail( $owner_email, $subject, $body, $headers );
		} catch ( \Throwable $e ) {
			$sent = false;
			if ( $this->logger ) {
				$this->logger->error(
					'Notifications: owner-notification wp_mail threw.',
					array(
						'source'    => $context['source'] ?? '',
						'recipient' => $owner_email,
						'message'   => $e->getMessage(),
					)
				);
			}
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );
		}

		if ( ! $sent ) {
			update_option(
				'handik_booking_app_last_email_error',
				array(
					'time'    => time(),
					'message' => 'wp_mail (owner notification) returned false',
					'context' => array(
						'request_id' => (int) ( $context['request_id'] ?? 0 ),
						'source'     => (string) ( $context['source'] ?? '' ),
						'to'         => $owner_email,
						'side'       => 'owner',
					),
				),
				false
			);
			if ( $this->logger ) {
				$this->logger->error(
					'Notifications: owner-notification wp_mail returned false.',
					array(
						'source'    => $context['source'] ?? '',
						'recipient' => $owner_email,
					)
				);
			}
			return false;
		}

		// Don't clear LAST_EMAIL_ERROR here — the customer-side path
		// already clears on its own success, and clearing twice from
		// two callers in the same dispatch race is fine but noisy in
		// the System info "Last attempt" tracker. Leaving it to
		// customer-side (or a future explicit "ok" stamp).

		if ( $this->logger ) {
			$this->logger->info(
				'Notifications: owner notification sent.',
				array(
					'source'          => $context['source'] ?? '',
					'request_id'      => $context['request_id'] ?? 0,
					'recipient'       => $owner_email,
					'cal_booking_uid' => $context['cal_booking_uid'] ?? '',
				)
			);
		}
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Sprint 14c — cancellation + reschedule send paths.                 *
	 * ------------------------------------------------------------------ *
	 * Each `send_*` mirrors the structure of send_customer_confirmation
	 * (HTML + plain-text customer side) or send_owner_notification
	 * (plain-text owner side). The customer-side methods build a
	 * customised .ics:
	 *   - cancellation: METHOD:CANCEL + STATUS:CANCELLED + SEQUENCE:1
	 *     so calendar apps DELETE the original invite from the user's
	 *     calendar on import.
	 *   - reschedule: METHOD:REQUEST + STATUS:CONFIRMED + SEQUENCE:1
	 *     so calendar apps UPDATE the original invite with the new
	 *     time. (RFC 5546 — incrementing SEQUENCE is what makes
	 *     calendars recognise the .ics as an update vs. a duplicate.)
	 * Owner-side emails are plain-text only with no .ics attachment
	 * (the owner already has booking visibility via the admin list).
	 */

	/**
	 * @param array<string, mixed>    $context        Booking context.
	 * @param string                  $customer_email Sanitized recipient.
	 * @param array<string, string>   $template_overrides Unsaved POST values
	 *                                                     for Send-Test.
	 * @return bool
	 */
	protected function send_customer_cancellation( array $context, $customer_email, array $template_overrides = array() ) {
		return $this->send_customer_status_email(
			$context,
			$customer_email,
			$template_overrides,
			array(
				'subject_key' => 'customer_cancellation_subject',
				'html_key'    => 'customer_cancellation_body_html',
				'text_key'    => 'customer_cancellation_body_text',
				'event'       => self::EVENT_CANCELLED,
				'ics_method'  => 'CANCEL',
				'ics_status'  => 'CANCELLED',
				'log_label'   => 'cancellation',
			)
		);
	}

	/**
	 * @param array<string, mixed>    $context        Booking context.
	 * @param string                  $customer_email Sanitized recipient.
	 * @param array<string, string>   $template_overrides Unsaved POST values.
	 * @return bool
	 */
	protected function send_customer_reschedule( array $context, $customer_email, array $template_overrides = array() ) {
		return $this->send_customer_status_email(
			$context,
			$customer_email,
			$template_overrides,
			array(
				'subject_key' => 'customer_reschedule_subject',
				'html_key'    => 'customer_reschedule_body_html',
				'text_key'    => 'customer_reschedule_body_text',
				'event'       => self::EVENT_RESCHEDULED,
				'ics_method'  => 'REQUEST',
				'ics_status'  => 'CONFIRMED',
				'log_label'   => 'reschedule',
			)
		);
	}

	/**
	 * Shared send pipeline for cancel / reschedule customer emails. Same
	 * shape as send_customer_confirmation but parameterised on the
	 * settings keys + .ics METHOD/STATUS/SEQUENCE.
	 *
	 * @param array<string, mixed>    $context           Booking context.
	 * @param string                  $customer_email    Recipient.
	 * @param array<string, string>   $template_overrides POST overrides.
	 * @param array<string, string>   $config            Per-event config.
	 * @return bool
	 */
	protected function send_customer_status_email( array $context, $customer_email, array $template_overrides, array $config ) {
		$placeholders = $this->build_placeholders( $context );
		$resolve = function ( $key ) use ( $template_overrides ) {
			if ( array_key_exists( $key, $template_overrides ) ) {
				return (string) $template_overrides[ $key ];
			}
			return (string) $this->settings->get( $key, '' );
		};

		$subject = Handik_Booking_App_Admin_Helpers::render_template( $resolve( $config['subject_key'] ), $this->placeholders_for_subject( $placeholders ) );
		$html    = Handik_Booking_App_Admin_Helpers::render_template( $resolve( $config['html_key'] ), $this->placeholders_for_html( $placeholders ) );
		$text    = Handik_Booking_App_Admin_Helpers::render_template( $resolve( $config['text_key'] ), $placeholders );
		$text    = wordwrap( $text, 78, "\n", false );

		$ics_path = $this->write_ics_temp_file_for_status( $context, $config['ics_method'], $config['ics_status'] );

		$from_name    = self::strip_header_breaks( (string) $this->settings->get( 'email_from_name', '' ) );
		$from_address = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		$reply_to     = sanitize_email( (string) $this->settings->get( 'customer_confirmation_reply_to', '' ) );
		if ( '' === $reply_to ) {
			$reply_to = $from_address;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $from_address ) {
			$from_label = '' !== $from_name ? sprintf( '%s <%s>', $from_name, $from_address ) : $from_address;
			$headers[]  = 'From: ' . $from_label;
		}
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$attachments = ( '' !== $ics_path && file_exists( $ics_path ) ) ? array( $ics_path ) : array();

		$content_type_cb = static function () { return 'text/html'; };
		$altbody_cb      = static function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		$ics_filename = '' !== $ics_path ? basename( $ics_path ) : '';
		// .ics method matters for cancellation: must be application/ics
		// with method=CANCEL so calendar apps DELETE the event.
		$ics_method      = $config['ics_method'];
		$attachment_cb   = static function ( $phpmailer ) use ( $ics_filename, $ics_method ) {
			if ( '' === $ics_filename ) {
				return;
			}
			$attachments = $phpmailer->getAttachments();
			$phpmailer->clearAttachments();
			foreach ( $attachments as $att ) {
				if ( $att[2] === $ics_filename ) {
					$phpmailer->addAttachment(
						$att[0],
						$att[2],
						'base64',
						'text/calendar; charset=UTF-8; method=' . $ics_method,
						'attachment'
					);
				} else {
					$phpmailer->addAttachment( $att[0], $att[2], $att[3], $att[4], $att[6] );
				}
			}
		};

		add_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );
		add_action( 'phpmailer_init', $altbody_cb, PHP_INT_MAX );
		if ( '' !== $ics_filename ) {
			add_action( 'phpmailer_init', $attachment_cb, PHP_INT_MAX );
		}

		$sent = false;
		try {
			$sent = (bool) wp_mail( $customer_email, $subject, $html, $headers, $attachments );
		} catch ( \Throwable $e ) {
			$sent = false;
			if ( $this->logger ) {
				$this->logger->error(
					sprintf( 'Notifications: customer-%s wp_mail threw.', $config['log_label'] ),
					array( 'source' => $context['source'] ?? '', 'recipient' => $customer_email, 'message' => $e->getMessage() )
				);
			}
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );
			remove_action( 'phpmailer_init', $altbody_cb, PHP_INT_MAX );
			if ( '' !== $ics_filename ) {
				remove_action( 'phpmailer_init', $attachment_cb, PHP_INT_MAX );
			}
			if ( '' !== $ics_path && file_exists( $ics_path ) ) {
				@unlink( $ics_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( ! $sent ) {
			update_option(
				'handik_booking_app_last_email_error',
				array(
					'time'    => time(),
					'message' => sprintf( 'wp_mail (customer %s) returned false', $config['log_label'] ),
					'context' => array(
						'request_id' => (int) ( $context['request_id'] ?? 0 ),
						'source'     => (string) ( $context['source'] ?? '' ),
						'to'         => $customer_email,
						'side'       => 'customer',
						'event'      => $config['event'],
					),
				),
				false
			);
			return false;
		}

		delete_option( 'handik_booking_app_last_email_error' );

		if ( $this->logger ) {
			$this->logger->info(
				sprintf( 'Notifications: customer %s sent.', $config['log_label'] ),
				array(
					'source'     => $context['source'] ?? '',
					'request_id' => $context['request_id'] ?? 0,
					'recipient'  => $customer_email,
				)
			);
		}
		return true;
	}

	/**
	 * @param array<string, mixed>    $context     Booking context.
	 * @param string                  $owner_email Recipient.
	 * @param array<string, string>   $template_overrides POST overrides.
	 * @return bool
	 */
	protected function send_owner_cancellation( array $context, $owner_email, array $template_overrides = array() ) {
		return $this->send_owner_status_email( $context, $owner_email, $template_overrides, 'owner_cancellation_subject', 'owner_cancellation_body', self::EVENT_CANCELLED, 'cancellation' );
	}

	/**
	 * @param array<string, mixed>    $context     Booking context.
	 * @param string                  $owner_email Recipient.
	 * @param array<string, string>   $template_overrides POST overrides.
	 * @return bool
	 */
	protected function send_owner_reschedule( array $context, $owner_email, array $template_overrides = array() ) {
		return $this->send_owner_status_email( $context, $owner_email, $template_overrides, 'owner_reschedule_subject', 'owner_reschedule_body', self::EVENT_RESCHEDULED, 'reschedule' );
	}

	/**
	 * Shared owner-side plain-text send. Mirrors send_owner_notification
	 * but parameterised on the subject/body keys + event tag for logs.
	 *
	 * @param array<string, mixed>  $context             Booking context.
	 * @param string                $owner_email         Recipient.
	 * @param array<string, string> $template_overrides  POST overrides.
	 * @param string                $subject_key         Settings key.
	 * @param string                $body_key            Settings key.
	 * @param string                $event               EVENT_* constant.
	 * @param string                $log_label           Log tag.
	 * @return bool
	 */
	protected function send_owner_status_email( array $context, $owner_email, array $template_overrides, $subject_key, $body_key, $event, $log_label ) {
		$placeholders = $this->build_placeholders( $context );
		$resolve = function ( $key ) use ( $template_overrides ) {
			if ( array_key_exists( $key, $template_overrides ) ) {
				return (string) $template_overrides[ $key ];
			}
			return (string) $this->settings->get( $key, '' );
		};

		$subject = Handik_Booking_App_Admin_Helpers::render_template( $resolve( $subject_key ), $this->placeholders_for_subject( $placeholders ) );
		$body    = Handik_Booking_App_Admin_Helpers::render_template( $resolve( $body_key ), $placeholders );
		$body    = wordwrap( $body, 78, "\n", false );

		$from_name    = self::strip_header_breaks( (string) $this->settings->get( 'email_from_name', '' ) );
		$from_address = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( '' !== $from_address ) {
			$from_label = '' !== $from_name ? sprintf( '%s <%s>', $from_name, $from_address ) : $from_address;
			$headers[]  = 'From: ' . $from_label;
		}
		$contact        = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$customer_email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( '' !== $customer_email ) {
			$headers[] = 'Reply-To: ' . $customer_email;
		}

		$content_type_cb = static function () { return 'text/plain'; };
		add_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );

		$sent = false;
		try {
			$sent = (bool) wp_mail( $owner_email, $subject, $body, $headers );
		} catch ( \Throwable $e ) {
			$sent = false;
			if ( $this->logger ) {
				$this->logger->error(
					sprintf( 'Notifications: owner-%s wp_mail threw.', $log_label ),
					array( 'source' => $context['source'] ?? '', 'recipient' => $owner_email, 'message' => $e->getMessage() )
				);
			}
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_cb, PHP_INT_MAX );
		}

		if ( ! $sent ) {
			update_option(
				'handik_booking_app_last_email_error',
				array(
					'time'    => time(),
					'message' => sprintf( 'wp_mail (owner %s) returned false', $log_label ),
					'context' => array(
						'request_id' => (int) ( $context['request_id'] ?? 0 ),
						'source'     => (string) ( $context['source'] ?? '' ),
						'to'         => $owner_email,
						'side'       => 'owner',
						'event'      => $event,
					),
				),
				false
			);
			return false;
		}

		if ( $this->logger ) {
			$this->logger->info(
				sprintf( 'Notifications: owner %s sent.', $log_label ),
				array( 'source' => $context['source'] ?? '', 'request_id' => $context['request_id'] ?? 0, 'recipient' => $owner_email )
			);
		}
		return true;
	}

	/**
	 * Sprint 14c variant of write_ics_temp_file that builds the .ics
	 * with caller-provided METHOD + STATUS + SEQUENCE (cancellation:
	 * CANCEL/CANCELLED/1, reschedule: REQUEST/CONFIRMED/1). Returns
	 * the temp-file path or '' on failure.
	 *
	 * @param array<string, mixed> $context     Booking context.
	 * @param string               $ics_method  RFC 5545 METHOD value.
	 * @param string               $ics_status  RFC 5545 STATUS value.
	 * @return string Absolute path or ''.
	 */
	protected function write_ics_temp_file_for_status( array $context, $ics_method, $ics_status ) {
		if ( ! class_exists( 'Handik_Booking_App_Ics_Builder' ) ) {
			return '';
		}
		$contact      = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$address_line = $this->format_address( isset( $context['address'] ) && is_array( $context['address'] ) ? $context['address'] : array() );
		$site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $site_host ) {
			$site_host = 'handik.local';
		}

		$organizer_email = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		$organizer_name  = trim( (string) $this->settings->get( 'email_from_name', '' ) );
		$attendee_email  = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		$attendee_name   = (string) ( $contact['full_name'] ?? '' );

		$tasks       = isset( $context['tasks'] ) && is_array( $context['tasks'] ) ? $context['tasks'] : array();
		$task_labels = array();
		foreach ( $tasks as $task ) {
			$label = (string) ( $task['label'] ?? '' );
			if ( '' !== $label ) {
				$task_labels[] = $label;
			}
		}
		$summary_suffix = ! empty( $task_labels ) ? ' · ' . implode( ', ', $task_labels ) : '';

		$when    = isset( $context['when'] ) && is_array( $context['when'] ) ? $context['when'] : array();
		$cal_uid = (string) ( $context['cal_booking_uid'] ?? '' );
		// CRITICAL: the UID must match the original booking's UID so calendar
		// apps know to UPDATE / CANCEL the existing event vs creating a new
		// one. Same UID-derivation as write_ics_temp_file (booked path).
		$uid = '' !== $cal_uid
			? 'handik-booking-' . $cal_uid . '@' . $site_host
			: 'handik-booking-' . (int) ( $context['request_id'] ?? 0 ) . '@' . $site_host;

		$event_data = array(
			'uid'             => $uid,
			'summary'         => 'Handik visit' . $summary_suffix,
			'description'     => $this->build_ics_description( $context ),
			'location'        => $address_line,
			'dtstart_iso'     => (string) ( $when['start_iso'] ?? '' ),
			'dtend_iso'       => (string) ( $when['end_iso'] ?? '' ),
			'organizer_name'  => $organizer_name,
			'organizer_email' => $organizer_email,
			'attendee_name'   => $attendee_name,
			'attendee_email'  => $attendee_email,
			'sequence'        => 1, // bumped from 0 so calendar apps treat as update.
			'status'          => $ics_status,
		);

		$ics = Handik_Booking_App_Ics_Builder::build_single( $event_data, $ics_method );
		if ( '' === $ics ) {
			return '';
		}

		$tmp_dir  = sys_get_temp_dir();
		// 2.1.26.6 P0 fix: wp_tempnam() is defined in
		// wp-admin/includes/file.php which is NOT loaded on REST API
		// requests (or front-end / cron contexts). Calling it from a
		// REST endpoint produces "Call to undefined function
		// wp_tempnam()" → request 500. Owner-reported in 2.1.26.5
		// where our defensive try/catch caught the throw, logged
		// `file=class-notifications-service.php, line=1782, message=
		// Call to undefined function wp_tempnam()`. Load the file
		// just-in-time. require_once is cheap on subsequent calls.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path     = wp_tempnam( 'handik-booking-' . wp_generate_uuid4() . '.ics', $tmp_dir );
		if ( ! $path ) {
			return '';
		}
		$ics_path = $path . '.ics';
		if ( ! rename( $path, $ics_path ) ) {
			$ics_path = $path;
		}
		if ( false === file_put_contents( $ics_path, $ics ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $ics_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return '';
		}
		return $ics_path;
	}

	/**
	 * @param array<string, mixed> $context Booking context.
	 * @return array<string, string>
	 */
	protected function build_placeholders( array $context ) {
		$contact = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$address = isset( $context['address'] ) && is_array( $context['address'] ) ? $context['address'] : array();
		$tasks   = isset( $context['tasks'] ) && is_array( $context['tasks'] ) ? $context['tasks'] : array();
		$when    = isset( $context['when'] ) && is_array( $context['when'] ) ? $context['when'] : array();
		$source  = (string) ( $context['source'] ?? '' );

		$customer_name = '' !== (string) ( $contact['full_name'] ?? '' )
			? (string) $contact['full_name']
			: __( 'there', 'handik-booking-app' );

		$address_line = $this->format_address( $address );

		// Project flow has `when.days[]`; cal/direct have `when.start_iso`.
		$is_project    = 'project' === $source;
		$booking_when      = '';
		$booking_when_long = '';
		$days_list_html    = '';
		$days_list_text    = '';
		$days_count        = 0;
		if ( $is_project ) {
			$days = isset( $when['days'] ) && is_array( $when['days'] ) ? $when['days'] : array();
			$days_count    = count( $days );
			$days_list_html = '<ol>';
			$days_text_lines = array();
			foreach ( $days as $day ) {
				$start = (string) ( $day['start_iso'] ?? '' );
				$end   = (string) ( $day['end_iso'] ?? '' );
				$line  = $this->format_when_long( $start, $end );
				$days_list_html .= '<li>' . esc_html( $line ) . '</li>';
				$days_text_lines[] = '- ' . $line;
			}
			$days_list_html .= '</ol>';
			$days_list_text = implode( "\n", $days_text_lines );
			// First day stands in for the singular {{booking_when}}.
			$first = $days_count > 0 ? $days[0] : null;
			if ( $first ) {
				$booking_when      = $this->format_when_short( (string) ( $first['start_iso'] ?? '' ) );
				$booking_when_long = $this->format_when_long( (string) ( $first['start_iso'] ?? '' ), (string) ( $first['end_iso'] ?? '' ) );
			}
		} else {
			$booking_when      = $this->format_when_short( (string) ( $when['start_iso'] ?? '' ) );
			$booking_when_long = $this->format_when_long( (string) ( $when['start_iso'] ?? '' ), (string) ( $when['end_iso'] ?? '' ) );
		}

		$tasks_html_parts = array();
		$tasks_text_parts = array();
		foreach ( $tasks as $task ) {
			$label = (string) ( $task['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$tasks_html_parts[] = '<li>' . esc_html( $label ) . '</li>';
			$tasks_text_parts[] = '- ' . $label;
		}
		$tasks_list_html = empty( $tasks_html_parts )
			? ''
			: '<ul>' . implode( '', $tasks_html_parts ) . '</ul>';
		$tasks_list_text = implode( "\n", $tasks_text_parts );

		// 2.1.21.4 — pre-render the optional brand-logo block. We can't
		// do conditional rendering inside the template (str_replace has
		// no branching), so this resolves to a complete `<img>` block
		// when a URL is configured and to an empty string otherwise.
		// `placeholders_for_html()` will skip its esc_html pass on this
		// key; the URL itself is already URL-escaped here so a forged
		// `javascript:` setting can't slip through.
		$brand_logo_url  = trim( (string) $this->settings->get( 'brand_logo_url', '' ) );
		$brand_logo_html = '';
		if ( '' !== $brand_logo_url ) {
			$safe_url       = esc_url( $brand_logo_url );
			$alt            = esc_attr( (string) get_bloginfo( 'name' ) );
			$brand_logo_html = '<img src="' . $safe_url . '" alt="' . $alt . '" width="120" style="display: block; margin: 0 auto; max-width: 120px; height: auto;">';
		}

		return array(
			'customer_name'        => $customer_name,
			'booking_when'         => $booking_when,
			'booking_when_long'    => $booking_when_long,
			'address'              => $address_line,
			'tasks_list_html'      => $tasks_list_html,
			'tasks_list_text'      => $tasks_list_text,
			'cal_url'              => (string) ( $context['booking_url'] ?? '' ),
			'restart_url'          => (string) ( $context['restart_url'] ?? '' ),
			'site_name'            => (string) get_bloginfo( 'name' ),
			'from_name'            => (string) $this->settings->get( 'email_from_name', '' ),
			'site_url'             => (string) home_url( '/' ),
			'operator_first_name'  => (string) $this->settings->get( 'operator_first_name', 'Alex' ),
			'days_list_html'       => $days_list_html,
			'days_list_text'       => $days_list_text,
			'days_count'           => (string) $days_count,
			'brand_logo_url'       => $brand_logo_url,
			'brand_logo_html'      => $brand_logo_html,

			// Sprint 14b — owner-notification-specific tokens. Harmless
			// in customer templates (just unused), but only defined for
			// the owner email's mailto: / tel: links and "where did this
			// come from?" provenance line.
			'customer_phone'           => (string) ( $contact['phone'] ?? '' ),
			'customer_email'           => (string) ( $contact['email'] ?? '' ),
			'source_label'             => $this->source_label( $source ),
			'open_request_admin_link'  => $this->open_request_admin_link( $context ),

			// Sprint 14c — cancel + reschedule extras. Empty for booked
			// dispatches; populated by the cancel/reschedule send paths
			// from `$context['cancellation_reason']` / `$context['old_when']`
			// which the dispatch helpers attach.
			'cancellation_reason'      => (string) ( $context['cancellation_reason'] ?? '' ),
			'old_booking_when'         => $this->format_when_short( (string) ( $context['old_when']['start_iso'] ?? '' ) ),
			'old_booking_when_long'    => $this->format_when_long(
				(string) ( $context['old_when']['start_iso'] ?? '' ),
				(string) ( $context['old_when']['end_iso'] ?? '' )
			),

			// Hotfix 2.1.22.1 — pre-rendered conditional blocks. The
			// `_html` suffix means they bypass placeholders_for_html()'s
			// esc_html pass (allow-listed), so the markup survives. The
			// blocks are EMPTY STRINGS when their data is missing —
			// dropping them into a template gracefully hides the
			// section instead of leaving a dangling label like
			// "Where: " (the bug from the user's first test send).
			'checkmark_block_html'     => $this->render_checkmark_block(),
			'booking_summary_block_html' => $this->render_booking_summary_block(
				$booking_when_long,
				$address_line,
				$tasks_list_html
			),
			'cal_links_block_html'     => $this->render_cal_links_block(
				(string) ( $context['booking_url'] ?? '' )
			),
		);
	}

	/**
	 * Hotfix 2.1.22.1 — fixed green-checkmark badge for the customer
	 * email. Pure HTML (no images), so it renders identically in
	 * Gmail / Apple Mail / Outlook web. Uses `display: inline-block`
	 * + `border-radius: 50%` for the circle, Unicode ✓ inside.
	 *
	 * @return string Self-contained HTML block.
	 */
	protected function render_checkmark_block() {
		return '<div style="text-align: center; margin: 0 0 16px;">'
			. '<div style="display: inline-block; width: 56px; height: 56px; background: #dcfce7; border-radius: 50%; line-height: 56px; font-size: 28px; color: #16a34a; font-weight: 700;">&#10003;</div>'
			. '</div>';
	}

	/**
	 * Hotfix 2.1.22.1 — Cal-style structured "What / When / Where"
	 * table. Each row is included ONLY if its data is non-empty so a
	 * customer email never shows an empty "Where: " line. All values
	 * are esc_html'd individually here (we're inside build_placeholders
	 * which runs BEFORE placeholders_for_html's escape pass).
	 *
	 * @param string $booking_when_long Pre-formatted "Mon Jan 15 · 2:00–4:00 PM ET".
	 * @param string $address_line      Pre-formatted single-line address.
	 * @param string $tasks_list_html   Pre-built `<ul>` with esc_html'd items, or ''.
	 * @return string HTML block or '' when no rows would render.
	 */
	protected function render_booking_summary_block( $booking_when_long, $address_line, $tasks_list_html ) {
		$rows = '';
		$row_style    = 'padding: 14px 0; vertical-align: top;';
		$label_style  = 'color: #64748b; font-size: 13px; font-weight: 500; width: 80px; padding-right: 12px;';
		$value_style  = 'color: #0f172a; font-size: 15px;';
		$border_style = 'border-top: 1px solid #e2e8f0;';

		if ( '' !== $booking_when_long ) {
			$rows .= '<tr>'
				. '<td style="' . $row_style . $label_style . '">' . esc_html__( 'When', 'handik-booking-app' ) . '</td>'
				. '<td style="' . $row_style . $value_style . '"><strong>' . esc_html( $booking_when_long ) . '</strong></td>'
				. '</tr>';
		}
		if ( '' !== $tasks_list_html ) {
			$rows .= '<tr>'
				. '<td style="' . $row_style . $label_style . $border_style . '">' . esc_html__( 'What', 'handik-booking-app' ) . '</td>'
				. '<td style="' . $row_style . $value_style . $border_style . '">' . $tasks_list_html . '</td>'
				. '</tr>';
		}
		if ( '' !== $address_line ) {
			$rows .= '<tr>'
				. '<td style="' . $row_style . $label_style . $border_style . '">' . esc_html__( 'Where', 'handik-booking-app' ) . '</td>'
				. '<td style="' . $row_style . $value_style . $border_style . '">' . esc_html( $address_line ) . '</td>'
				. '</tr>';
		}
		if ( '' === $rows ) {
			return '';
		}
		return '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0;">' . $rows . '</table>';
	}

	/**
	 * Hotfix 2.1.22.1 — Reschedule / Cancel button row. Renders only
	 * if a Cal URL is present. Cal exposes one URL that handles BOTH
	 * reschedule + cancel (Cal's confirmation page); we link to it as
	 * "Reschedule or cancel" — single button, low ambiguity.
	 *
	 * @param string $cal_url Reschedule/cancel URL from the Cal payload.
	 * @return string HTML block or '' when no URL is available.
	 */
	protected function render_cal_links_block( $cal_url ) {
		$cal_url = trim( (string) $cal_url );
		if ( '' === $cal_url ) {
			return '';
		}
		$safe_url = esc_url( $cal_url );
		return '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0 0;">'
			. '<tr><td style="text-align: center;">'
			. '<a href="' . $safe_url . '" style="display: inline-block; padding: 12px 28px; background: #283618; color: #ffffff; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 15px;">'
			. esc_html__( 'Reschedule or cancel', 'handik-booking-app' )
			. '</a>'
			. '</td></tr>'
			. '</table>';
	}

	/**
	 * @param string $source Context source (cal | direct | project).
	 * @return string Human-readable label for the owner email "Source:" line.
	 */
	protected function source_label( $source ) {
		switch ( (string) $source ) {
			case 'cal':
				return __( 'Main SPA', 'handik-booking-app' );
			case 'direct':
				return __( 'Direct booking form', 'handik-booking-app' );
			case 'project':
				return __( 'Project work-days', 'handik-booking-app' );
			default:
				return (string) $source;
		}
	}

	/**
	 * Build a deep-link the owner can paste into a phone or click in
	 * their inbox to land on the relevant admin booking-detail page.
	 *
	 * The admin Bookings list (`?page=handik-booking-app-bookings`) reads
	 * the unified `handik_bookings` table, which Sprint 13.5 made the
	 * single visibility surface for both main-SPA and direct-form rows.
	 * Project schedules don't mirror there (yet) — for the project
	 * source we link to the Additional Forms admin page instead.
	 *
	 * @param array<string, mixed> $context Booking context.
	 * @return string Absolute admin URL or '' when we can't resolve one.
	 */
	protected function open_request_admin_link( array $context ) {
		$source     = (string) ( $context['source'] ?? '' );
		$booking_id = isset( $context['booking_id'] ) ? (int) $context['booking_id'] : 0;
		$request_id = isset( $context['request_id'] ) ? (int) $context['request_id'] : 0;

		switch ( $source ) {
			case 'cal':
				if ( $booking_id ) {
					return admin_url( 'admin.php?page=handik-booking-app-bookings&booking_id=' . $booking_id );
				}
				return admin_url( 'admin.php?page=handik-booking-app-bookings' );
			case 'direct':
				// Direct rows are visible in the unified Bookings list as
				// of 2.1.20.2 — best link is the same list filtered, but
				// without a stable per-direct-row deep-link we just send
				// the operator there. They'll see the latest row at the top.
				return admin_url( 'admin.php?page=handik-booking-app-bookings' );
			case 'project':
				return $request_id
					? admin_url( 'admin.php?page=handik-booking-app-additional-forms&schedule_id=' . $request_id )
					: admin_url( 'admin.php?page=handik-booking-app-additional-forms' );
			default:
				return admin_url( 'admin.php?page=handik-booking-app' );
		}
	}

	/**
	 * Hotfix 2.1.21.2 — return a copy of the placeholder map suitable for
	 * substitution into an HTML context. User-controlled scalar tokens
	 * (customer name, address, from-name, etc.) are HTML-escaped so a
	 * CRM contact whose full_name is `<img onerror=…>` can't land
	 * working markup in the customer's inbox.
	 *
	 * Two categories are NOT escaped:
	 *   - Pre-rendered HTML lists (`tasks_list_html`, `days_list_html`)
	 *     which `build_placeholders` already constructs with esc_html()
	 *     on each item — escaping again would render the `<ul>` tags
	 *     literally.
	 *   - URLs (`cal_url`, `restart_url`, `site_url`,
	 *     `open_request_admin_link`) which go through `esc_url()`
	 *     instead — strips dangerous schemes (`javascript:`, `data:`)
	 *     that would otherwise render as a clickable link.
	 *
	 * @param array<string, mixed> $base Placeholder map from build_placeholders().
	 * @return array<string, string>
	 */
	protected function placeholders_for_html( array $base ) {
		$out                  = array();
		$pre_rendered_html    = array(
			'tasks_list_html',
			'days_list_html',
			'brand_logo_html',
			// Hotfix 2.1.22.1 — conditional pre-rendered blocks. Each
			// is built with internal escape passes inside build_placeholders.
			'checkmark_block_html',
			'booking_summary_block_html',
			'cal_links_block_html',
		);
		$url_keys             = array( 'cal_url', 'restart_url', 'site_url', 'open_request_admin_link', 'brand_logo_url' );
		foreach ( $base as $key => $value ) {
			if ( in_array( $key, $pre_rendered_html, true ) ) {
				$out[ $key ] = (string) $value;
				continue;
			}
			if ( in_array( $key, $url_keys, true ) ) {
				$out[ $key ] = esc_url( (string) $value );
				continue;
			}
			$out[ $key ] = esc_html( (string) $value );
		}
		return $out;
	}

	/**
	 * Hotfix 2.1.21.2 — sanitize placeholders for substitution into the
	 * Subject header. Email subjects are plain text but a CR/LF in the
	 * value would let an attacker inject additional headers (Bcc, etc.).
	 * `sanitize_text_field` collapses whitespace + strips tags — both
	 * desirable for a one-line subject.
	 *
	 * @param array<string, mixed> $base Placeholder map from build_placeholders().
	 * @return array<string, string>
	 */
	protected function placeholders_for_subject( array $base ) {
		$out = array();
		foreach ( $base as $key => $value ) {
			$out[ $key ] = sanitize_text_field( (string) $value );
		}
		return $out;
	}

	/**
	 * Hotfix 2.1.21.2 — strip CR/LF (and NUL) from a string destined for
	 * a single-line email header value. Defends against From-name header
	 * injection if a future settings code path stops sanitizing.
	 *
	 * @param string $value Raw header-component value.
	 * @return string
	 */
	protected static function strip_header_breaks( $value ) {
		return trim( str_replace( array( "\r", "\n", "\0" ), '', (string) $value ) );
	}

	/**
	 * @param array<string, mixed> $address Flattened address.
	 * @return string Single-line address with optional unit.
	 */
	protected function format_address( array $address ) {
		$full = trim( (string) ( $address['address_full'] ?? '' ) );
		$unit = trim( (string) ( $address['address_unit'] ?? '' ) );
		if ( '' === $full ) {
			return '';
		}
		if ( '' !== $unit ) {
			return sprintf( '%s, Unit %s', $full, $unit );
		}
		return $full;
	}

	/**
	 * @param string $start_iso ISO 8601 datetime.
	 * @return string e.g. "Mon, Jan 15 · 2:00 PM"
	 */
	protected function format_when_short( $start_iso ) {
		$ts = $this->iso_to_timestamp( $start_iso );
		if ( ! $ts ) {
			return '';
		}
		// Site timezone — owner-configured tz wins over server tz.
		return wp_date( 'D, M j · g:i A', $ts );
	}

	/**
	 * @param string $start_iso ISO 8601 datetime.
	 * @param string $end_iso   ISO 8601 datetime.
	 * @return string e.g. "Monday, January 15, 2026 · 2:00 PM – 4:00 PM ET"
	 */
	protected function format_when_long( $start_iso, $end_iso ) {
		$start_ts = $this->iso_to_timestamp( $start_iso );
		if ( ! $start_ts ) {
			return '';
		}
		$end_ts = $this->iso_to_timestamp( $end_iso );
		$tz_abbr = wp_date( 'T', $start_ts );
		if ( $end_ts && $end_ts > $start_ts ) {
			return sprintf(
				'%s · %s – %s %s',
				wp_date( 'l, F j, Y', $start_ts ),
				wp_date( 'g:i A', $start_ts ),
				wp_date( 'g:i A', $end_ts ),
				$tz_abbr
			);
		}
		return sprintf(
			'%s · %s %s',
			wp_date( 'l, F j, Y', $start_ts ),
			wp_date( 'g:i A', $start_ts ),
			$tz_abbr
		);
	}

	/**
	 * @param string $iso ISO 8601 datetime.
	 * @return int|false Unix timestamp or false on parse failure.
	 */
	protected function iso_to_timestamp( $iso ) {
		if ( '' === (string) $iso ) {
			return false;
		}
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( \Exception $e ) {
			return false;
		}
		return $dt->getTimestamp();
	}

	/**
	 * Write the booking's .ics to a temp file. Returns the path or '' if
	 * we couldn't produce one (e.g. payload missing dtstart). Caller is
	 * responsible for unlinking after wp_mail returns.
	 *
	 * @param array<string, mixed> $context Booking context.
	 * @return string Absolute path or ''.
	 */
	protected function write_ics_temp_file( array $context ) {
		if ( ! class_exists( 'Handik_Booking_App_Ics_Builder' ) ) {
			return '';
		}

		$contact      = isset( $context['contact'] ) && is_array( $context['contact'] ) ? $context['contact'] : array();
		$address_line = $this->format_address( isset( $context['address'] ) && is_array( $context['address'] ) ? $context['address'] : array() );
		$source       = (string) ( $context['source'] ?? '' );
		$site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $site_host ) {
			$site_host = 'handik.local';
		}

		$organizer_email = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		$organizer_name  = trim( (string) $this->settings->get( 'email_from_name', '' ) );
		$attendee_email  = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		$attendee_name   = (string) ( $contact['full_name'] ?? '' );

		$tasks       = isset( $context['tasks'] ) && is_array( $context['tasks'] ) ? $context['tasks'] : array();
		$task_labels = array();
		foreach ( $tasks as $task ) {
			$label = (string) ( $task['label'] ?? '' );
			if ( '' !== $label ) {
				$task_labels[] = $label;
			}
		}
		$summary_suffix = ! empty( $task_labels ) ? ' · ' . implode( ', ', $task_labels ) : '';

		$ics = '';
		if ( 'project' === $source ) {
			$days = isset( $context['when']['days'] ) && is_array( $context['when']['days'] ) ? $context['when']['days'] : array();
			$events = array();
			foreach ( $days as $day ) {
				$day_index = (int) ( $day['day_index'] ?? 0 );
				$events[]  = array(
					'uid'             => sprintf(
						'handik-project-%d-day-%d@%s',
						(int) ( $context['request_id'] ?? 0 ),
						$day_index,
						$site_host
					),
					'summary'         => 'Handik visit' . $summary_suffix . ( $day_index ? ' (day ' . $day_index . ')' : '' ),
					'description'     => $this->build_ics_description( $context ),
					'location'        => $address_line,
					'dtstart_iso'     => (string) ( $day['start_iso'] ?? '' ),
					'dtend_iso'       => (string) ( $day['end_iso'] ?? '' ),
					'organizer_name'  => $organizer_name,
					'organizer_email' => $organizer_email,
					'attendee_name'   => $attendee_name,
					'attendee_email'  => $attendee_email,
				);
			}
			$ics = Handik_Booking_App_Ics_Builder::build_multi( $events );
		} else {
			$when = isset( $context['when'] ) && is_array( $context['when'] ) ? $context['when'] : array();
			$cal_uid = (string) ( $context['cal_booking_uid'] ?? '' );
			$uid     = '' !== $cal_uid
				? 'handik-booking-' . $cal_uid . '@' . $site_host
				: 'handik-booking-' . (int) ( $context['request_id'] ?? 0 ) . '@' . $site_host;
			$ics = Handik_Booking_App_Ics_Builder::build_single( array(
				'uid'             => $uid,
				'summary'         => 'Handik visit' . $summary_suffix,
				'description'     => $this->build_ics_description( $context ),
				'location'        => $address_line,
				'dtstart_iso'     => (string) ( $when['start_iso'] ?? '' ),
				'dtend_iso'       => (string) ( $when['end_iso'] ?? '' ),
				'organizer_name'  => $organizer_name,
				'organizer_email' => $organizer_email,
				'attendee_name'   => $attendee_name,
				'attendee_email'  => $attendee_email,
			) );
		}

		if ( '' === $ics ) {
			return '';
		}

		$tmp_dir = sys_get_temp_dir();
		// 2.1.26.6 P0 fix — see write_ics_temp_file_for_status above.
		// wp_tempnam() lives in wp-admin/includes/file.php which the
		// REST API doesn't auto-load.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path    = wp_tempnam( 'handik-booking-' . wp_generate_uuid4() . '.ics', $tmp_dir );
		if ( ! $path ) {
			return '';
		}
		// wp_tempnam returns a path with no extension; rename to .ics so
		// PHPMailer + downstream clients see the file extension when
		// inspecting the attachment metadata.
		$ics_path = $path . '.ics';
		if ( ! rename( $path, $ics_path ) ) {
			$ics_path = $path;
		}
		$bytes = file_put_contents( $ics_path, $ics ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			@unlink( $ics_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return '';
		}
		return $ics_path;
	}

	/**
	 * @param array<string, mixed> $context Booking context.
	 * @return string Body for the .ics DESCRIPTION property.
	 */
	protected function build_ics_description( array $context ) {
		$parts = array();
		$tasks = isset( $context['tasks'] ) && is_array( $context['tasks'] ) ? $context['tasks'] : array();
		$labels = array();
		foreach ( $tasks as $task ) {
			$label = (string) ( $task['label'] ?? '' );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}
		if ( ! empty( $labels ) ) {
			$parts[] = 'Tasks: ' . implode( ', ', $labels );
		}
		$address = $this->format_address( isset( $context['address'] ) && is_array( $context['address'] ) ? $context['address'] : array() );
		if ( '' !== $address ) {
			$parts[] = 'Address: ' . $address;
		}
		$cal_url = (string) ( $context['booking_url'] ?? '' );
		if ( '' !== $cal_url ) {
			$parts[] = 'Reschedule / cancel: ' . $cal_url;
		}
		return implode( "\n", $parts );
	}

	/**
	 * @return bool
	 */
	protected function customer_confirmations_enabled() {
		$value = $this->settings->get( 'customer_confirmations_enabled', '' );
		// Treat any truthy stored value as enabled (1, '1', true).
		return ! empty( $value );
	}

	/**
	 * @return bool
	 */
	protected function owner_notifications_enabled() {
		return ! empty( $this->settings->get( 'owner_notification_enabled', '' ) );
	}

	/**
	 * Sprint 14c — independent customer-side cancel/reschedule toggles.
	 * Both default OFF on upgrade; an owner can enable cancel without
	 * reschedule and vice versa.
	 *
	 * @return bool
	 */
	protected function customer_cancellation_enabled() {
		return ! empty( $this->settings->get( 'customer_cancellation_enabled', '' ) );
	}

	/**
	 * @return bool
	 */
	protected function customer_reschedule_enabled() {
		return ! empty( $this->settings->get( 'customer_reschedule_enabled', '' ) );
	}

	/**
	 * Resolve the owner-notification recipient. Picker setting wins; falls
	 * back to `email_from_address` so a fresh install works without
	 * extra configuration. Empty return means no usable recipient — the
	 * dispatcher will skip the owner branch.
	 *
	 * @return string Sanitized email or ''.
	 */
	protected function resolve_owner_address() {
		$picked = sanitize_email( (string) $this->settings->get( 'owner_notification_address', '' ) );
		if ( '' !== $picked ) {
			return $picked;
		}
		return sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
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

		$tasks = self::flatten_tasks( $request['selected_tasks'] ?? array() );
		// Hotfix 2.1.22.1 — same Cal-payload fallback as direct flow.
		// Empty selected_tasks happens when the customer skipped task
		// selection (just opened the booking page directly) or when an
		// admin-created request hasn't been hydrated. Use Cal's
		// eventType.title so the email body isn't empty.
		if ( empty( $tasks ) ) {
			$fallback_label = self::extract_event_label( $cal_payload );
			if ( '' !== $fallback_label ) {
				$tasks[] = array( 'label' => $fallback_label, 'rate_label' => '' );
			}
		}

		$context = array(
			'source'          => 'cal',
			'idempotency'     => array(
				'table'  => 'bookings',
				'row_id' => (int) $bookings_row_id,
			),
			'contact'         => self::flatten_contact( $contact ),
			'address'         => self::flatten_address( $address ),
			'tasks'           => $tasks,
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
		$preset_label = $preset ? trim( (string) ( $preset['label'] ?? '' ) ) : '';
		if ( '' === $preset_label ) {
			// Hotfix 2.1.22.1 — fall back to Cal payload's eventType.title
			// (e.g. "Standard Visit") when the preset has no human label.
			// Otherwise the customer email shows "What we'll be doing:"
			// followed by an empty list.
			$preset_label = self::extract_event_label( $cal_payload );
		}
		if ( '' !== $preset_label ) {
			$tasks[] = array(
				'label'      => $preset_label,
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
		// 2.1.26.5 — diagnostic breadcrumb so we know dispatch_for_project
		// actually started running. The owner-reported 500 in 2.1.26.4
		// fatals SOMEWHERE between this call and the email actually
		// going out, but PHP dies before any later log line writes.
		// Pairing this trace entry with the existing "Project schedule
		// confirmed" entry tells us whether we even reached this method
		// (vs. fatal'ing before).
		if ( $plugin->logger ) {
			$plugin->logger->info(
				'Project email dispatch starting.',
				array( 'schedule_id' => (int) $schedule_id )
			);
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

		// Hotfix 2.1.21.2 — defensive skip: confirm_schedule() is the
		// only caller and only fires after every day is persisted, so in
		// practice $flat_days is always non-empty. But sending a "your
		// visit is confirmed" email with zero days listed (e.g. a future
		// rollback edge that empties list_days() between the trigger and
		// our hydration) would produce a confusing customer email and an
		// empty .ics. Skip silently.
		if ( empty( $flat_days ) ) {
			return;
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
	 * Sprint 14c — cancellation + reschedule dispatch helpers.            *
	 * ------------------------------------------------------------------ *
	 * Each helper mirrors the corresponding `dispatch_for_*` but fires
	 * a different action constant and adds event-specific extras to the
	 * context (e.g. reschedule includes `old_when` from the persisted
	 * `start_time` we had before the webhook arrived).
	 */

	/**
	 * @param int                  $job_request_id   Job request ID.
	 * @param int                  $bookings_row_id  Row ID in handik_bookings.
	 * @param array<string, mixed> $cal_payload      Cal webhook payload.
	 * @return void
	 */
	public static function dispatch_for_cal_cancel( $job_request_id, $bookings_row_id, array $cal_payload ) {
		$context = self::build_cal_context( $job_request_id, $bookings_row_id, $cal_payload );
		if ( null === $context ) {
			return;
		}
		$context['event']              = self::EVENT_CANCELLED;
		$context['cancellation_reason'] = self::extract_cancellation_reason( $cal_payload );
		do_action( self::ACTION_BOOKING_CANCELLED, $context );
	}

	/**
	 * @param int                  $job_request_id   Job request ID.
	 * @param int                  $bookings_row_id  Row ID in handik_bookings.
	 * @param array<string, mixed> $cal_payload      Cal webhook payload.
	 * @return void
	 */
	public static function dispatch_for_cal_reschedule( $job_request_id, $bookings_row_id, array $cal_payload ) {
		$context = self::build_cal_context( $job_request_id, $bookings_row_id, $cal_payload );
		if ( null === $context ) {
			return;
		}
		// The stored `start_time` was the time BEFORE this reschedule
		// webhook arrived (because upsert_from_cal calls dispatch_for_*
		// AFTER the upsert). Reading it back would give the new time.
		// Cal's BOOKING_RESCHEDULED payload typically carries the old
		// time in `rescheduledFrom` / `previousStartTime` / metadata —
		// pull from there. Plain-text fallback is just empty.
		$context['event']     = self::EVENT_RESCHEDULED;
		$context['old_when']  = self::extract_old_when( $cal_payload );
		do_action( self::ACTION_BOOKING_RESCHEDULED, $context );
	}

	/**
	 * @param int                  $direct_request_id Direct request row ID.
	 * @param array<string, mixed> $cal_payload       Cal payload.
	 * @return void
	 */
	public static function dispatch_for_direct_cancel( $direct_request_id, array $cal_payload ) {
		$context = self::build_direct_context( $direct_request_id, $cal_payload );
		if ( null === $context ) {
			return;
		}
		$context['event']               = self::EVENT_CANCELLED;
		$context['cancellation_reason'] = self::extract_cancellation_reason( $cal_payload );
		do_action( self::ACTION_BOOKING_CANCELLED, $context );
	}

	/**
	 * @param int                  $direct_request_id Direct request row ID.
	 * @param array<string, mixed> $cal_payload       Cal payload.
	 * @return void
	 */
	public static function dispatch_for_direct_reschedule( $direct_request_id, array $cal_payload ) {
		$context = self::build_direct_context( $direct_request_id, $cal_payload );
		if ( null === $context ) {
			return;
		}
		$context['event']    = self::EVENT_RESCHEDULED;
		$context['old_when'] = self::extract_old_when( $cal_payload );
		do_action( self::ACTION_BOOKING_RESCHEDULED, $context );
	}

	/**
	 * Shared context builder for the Cal flow (main SPA). Returns null
	 * if the job request can't be hydrated (deleted contact, etc.).
	 *
	 * @param int                  $job_request_id  Request ID.
	 * @param int                  $bookings_row_id Row ID.
	 * @param array<string, mixed> $cal_payload     Payload.
	 * @return array<string, mixed>|null
	 */
	protected static function build_cal_context( $job_request_id, $bookings_row_id, array $cal_payload ) {
		$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
		if ( ! $plugin || ! $plugin->job_requests || ! $plugin->contacts || ! $plugin->addresses ) {
			return null;
		}
		$request = $plugin->job_requests->get( (int) $job_request_id );
		if ( ! $request ) {
			return null;
		}
		$contact = $plugin->contacts->get( (int) ( $request['contact_id'] ?? 0 ) );
		$address = isset( $request['address_id'] ) ? $plugin->addresses->get( (int) $request['address_id'] ) : null;

		return array(
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
	}

	/**
	 * Shared context builder for the direct flow.
	 *
	 * @param int                  $direct_request_id Direct request ID.
	 * @param array<string, mixed> $cal_payload       Payload.
	 * @return array<string, mixed>|null
	 */
	protected static function build_direct_context( $direct_request_id, array $cal_payload ) {
		$plugin = function_exists( 'handik_booking_app' ) ? handik_booking_app() : null;
		if ( ! $plugin || ! $plugin->direct_booking || ! $plugin->contacts || ! $plugin->addresses ) {
			return null;
		}
		$row = $plugin->direct_booking->get( (int) $direct_request_id );
		if ( ! $row ) {
			return null;
		}
		$contact = $plugin->contacts->get( (int) ( $row['contact_id'] ?? 0 ) );
		$address = isset( $row['address_id'] ) ? $plugin->addresses->get( (int) $row['address_id'] ) : null;

		$tasks = array();
		$preset = ( $plugin->booking_presets && ! empty( $row['preset_slug'] ) )
			? $plugin->booking_presets->find_by_slug( (string) $row['preset_slug'] )
			: null;
		$preset_label = $preset ? trim( (string) ( $preset['label'] ?? '' ) ) : '';
		if ( '' === $preset_label ) {
			// Hotfix 2.1.22.1 — fall back to Cal payload's eventType.title
			// (e.g. "Standard Visit") when the preset has no human label.
			// Otherwise the customer email shows "What we'll be doing:"
			// followed by an empty list.
			$preset_label = self::extract_event_label( $cal_payload );
		}
		if ( '' !== $preset_label ) {
			$tasks[] = array(
				'label'      => $preset_label,
				'rate_label' => '',
			);
		}

		return array(
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
	}

	/**
	 * Pull the original (pre-reschedule) time from a Cal payload. Cal's
	 * BOOKING_RESCHEDULED webhook may include this in several
	 * field names depending on Cal version; we check the common ones.
	 *
	 * @param array<string, mixed> $payload Cal payload.
	 * @return array<string, string> { start_iso, end_iso, timezone }
	 */
	protected static function extract_old_when( array $payload ) {
		$start = '';
		$end   = '';
		foreach ( array( 'rescheduledFrom', 'previousStartTime', 'oldStartTime', 'fromTime' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$start = (string) $payload[ $key ];
				break;
			}
		}
		foreach ( array( 'previousEndTime', 'oldEndTime', 'rescheduledFromEnd' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$end = (string) $payload[ $key ];
				break;
			}
		}
		return array(
			'start_iso' => $start,
			'end_iso'   => $end,
			'timezone'  => (string) ( $payload['organizer']['timeZone'] ?? wp_timezone_string() ),
		);
	}

	/**
	 * @param array<string, mixed> $payload Cal payload.
	 * @return string
	 */
	protected static function extract_cancellation_reason( array $payload ) {
		foreach ( array( 'cancellationReason', 'cancellation_reason', 'reason' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				return (string) $payload[ $key ];
			}
		}
		return '';
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
	/**
	 * Pull start/end times from a Cal payload, handling all the shapes
	 * Cal embed + webhook ship.
	 *
	 * Hotfix 2.1.22.1 — earlier versions only checked top-level
	 * `startTime` / `start`. Cal v2 embed's `bookingSuccessful` event
	 * detail.data uses `date` at top level OR nests `startTime` /
	 * `endTime` inside a `booking` sub-object, so the prior code got
	 * an empty string back, which made `{{booking_when_long}}` render
	 * empty in the customer email AND made `Ics_Builder::build_single`
	 * bail (no DTSTART → returns ''), so no .ics was attached. Both
	 * customer-visible bugs.
	 *
	 * Probe order (first non-empty wins):
	 *   1. Top-level: startTime, start, date
	 *   2. Nested in booking: booking.startTime, booking.start, booking.date
	 *   3. Webhook payload: payload.startTime (handled by Cal webhook)
	 *
	 * If end_iso is missing but start_iso + duration are present, derive
	 * end_iso = start_iso + duration minutes.
	 *
	 * @param array<string, mixed> $payload Cal payload (any shape).
	 * @return array<string, string>
	 */
	protected static function flatten_when_single( array $payload ) {
		$booking = ( isset( $payload['booking'] ) && is_array( $payload['booking'] ) ) ? $payload['booking'] : array();

		$start = '';
		foreach ( array(
			$payload['startTime'] ?? null,
			$payload['start']     ?? null,
			$payload['date']      ?? null,
			$booking['startTime'] ?? null,
			$booking['start']     ?? null,
			$booking['date']      ?? null,
		) as $candidate ) {
			if ( ! empty( $candidate ) ) {
				$start = (string) $candidate;
				break;
			}
		}

		$end = '';
		foreach ( array(
			$payload['endTime'] ?? null,
			$payload['end']     ?? null,
			$booking['endTime'] ?? null,
			$booking['end']     ?? null,
		) as $candidate ) {
			if ( ! empty( $candidate ) ) {
				$end = (string) $candidate;
				break;
			}
		}

		// Derive end from start + duration. Cal often ships duration in
		// minutes at top-level; sometimes inside eventType.length or
		// booking.duration. Best-effort.
		if ( '' === $end && '' !== $start ) {
			$duration_minutes = 0;
			foreach ( array(
				$payload['duration']                            ?? null,
				$payload['lengthInMinutes']                     ?? null,
				$booking['duration']                            ?? null,
				$booking['lengthInMinutes']                     ?? null,
				$payload['eventType']['length']                 ?? null,
				$payload['eventType']['lengthInMinutes']        ?? null,
			) as $candidate ) {
				if ( null !== $candidate && '' !== $candidate ) {
					$duration_minutes = (int) $candidate;
					if ( $duration_minutes > 0 ) {
						break;
					}
				}
			}
			if ( $duration_minutes > 0 ) {
				try {
					$start_dt = new DateTimeImmutable( $start );
					$end      = $start_dt->modify( '+' . $duration_minutes . ' minutes' )->format( DATE_ATOM );
				} catch ( \Exception $e ) {
					// Leave $end empty; downstream rendering will hide it.
					$end = '';
				}
			}
		}

		$timezone = '';
		foreach ( array(
			$payload['organizer']['timeZone']  ?? null,
			$payload['organizer']['timezone']  ?? null,
			$booking['organizer']['timeZone']  ?? null,
			$payload['eventType']['timeZone']  ?? null,
		) as $candidate ) {
			if ( ! empty( $candidate ) ) {
				$timezone = (string) $candidate;
				break;
			}
		}
		if ( '' === $timezone ) {
			$timezone = (string) wp_timezone_string();
		}

		return array(
			'start_iso' => $start,
			'end_iso'   => $end,
			'timezone'  => $timezone,
		);
	}

	/**
	 * @param array<string, mixed> $payload Cal payload.
	 * @return string
	 */
	protected static function extract_cal_url( array $payload ) {
		// Hotfix 2.1.22.1 — Cal v2 nests rescheduleUrl/cancelUrl inside
		// `booking`. Prior code only checked top-level keys → empty
		// {{cal_url}} → no Reschedule/Cancel link in the customer email.
		$booking = ( isset( $payload['booking'] ) && is_array( $payload['booking'] ) ) ? $payload['booking'] : array();
		$probes  = array(
			$payload['rescheduleUrl']  ?? null,
			$payload['reschedule_url'] ?? null,
			$payload['bookingUrl']     ?? null,
			$payload['booking_url']    ?? null,
			$payload['url']            ?? null,
			$booking['rescheduleUrl']  ?? null,
			$booking['reschedule_url'] ?? null,
			$booking['bookingUrl']     ?? null,
			$booking['booking_url']    ?? null,
			$booking['url']            ?? null,
		);
		foreach ( $probes as $candidate ) {
			if ( ! empty( $candidate ) ) {
				return esc_url_raw( (string) $candidate );
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
		// Hotfix 2.1.22.1 — same nested-payload issue as extract_cal_url.
		$booking = ( isset( $payload['booking'] ) && is_array( $payload['booking'] ) ) ? $payload['booking'] : array();
		$probes  = array(
			$payload['uid']        ?? null,
			$payload['bookingUid'] ?? null,
			$payload['id']         ?? null,
			$payload['bookingId']  ?? null,
			$booking['uid']        ?? null,
			$booking['id']         ?? null,
		);
		foreach ( $probes as $candidate ) {
			if ( ! empty( $candidate ) ) {
				return (string) $candidate;
			}
		}
		return '';
	}

	/**
	 * Hotfix 2.1.22.1 — derive a customer-facing "task" label from a Cal
	 * payload when our local preset/job_request data didn't supply one.
	 * Cal payload typically carries `eventType.title` (e.g. "Standard
	 * Visit") and sometimes `type` at the top level. Used by
	 * dispatch_for_cal / dispatch_for_direct as a graceful fallback so
	 * the "What we'll be doing:" line in the email body isn't empty.
	 *
	 * @param array<string, mixed> $payload Cal payload.
	 * @return string Label or '' when nothing usable.
	 */
	protected static function extract_event_label( array $payload ) {
		$probes = array(
			$payload['eventType']['title']    ?? null,
			$payload['eventType']['name']     ?? null,
			$payload['type']                  ?? null,
			$payload['title']                 ?? null,
			$payload['booking']['title']      ?? null,
		);
		foreach ( $probes as $candidate ) {
			if ( ! empty( $candidate ) ) {
				return trim( (string) $candidate );
			}
		}
		return '';
	}
}
