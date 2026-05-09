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

		if ( 'owner' === $which ) {
			$sent = $this->send_owner_notification( $context, $recipient, $overrides );
		} else {
			$sent = $this->send_customer_confirmation( $context, $recipient, $overrides );
		}

		return array(
			'sent'      => $sent,
			'recipient' => $recipient,
			'which'     => 'owner' === $which ? 'owner' : 'customer',
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

		$subject = Handik_Booking_App_Admin_Helpers::render_template( $subject_template, $placeholders );
		$html    = Handik_Booking_App_Admin_Helpers::render_template( $html_template, $placeholders );
		$text    = Handik_Booking_App_Admin_Helpers::render_template( $text_template, $placeholders );

		// Plain-text bodies should wrap at 78 octets (RFC 5322) — long
		// auto-generated lines render badly in legacy clients.
		$text = wordwrap( $text, 78, "\n", false );

		$ics_path = $this->write_ics_temp_file( $context );

		$from_name    = trim( (string) $this->settings->get( 'email_from_name', '' ) );
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

		$subject = Handik_Booking_App_Admin_Helpers::render_template( $subject_template, $placeholders );
		$body    = Handik_Booking_App_Admin_Helpers::render_template( $body_template, $placeholders );
		$body    = wordwrap( $body, 78, "\n", false );

		$from_name    = trim( (string) $this->settings->get( 'email_from_name', '' ) );
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

			// Sprint 14b — owner-notification-specific tokens. Harmless
			// in customer templates (just unused), but only defined for
			// the owner email's mailto: / tel: links and "where did this
			// come from?" provenance line.
			'customer_phone'           => (string) ( $contact['phone'] ?? '' ),
			'customer_email'           => (string) ( $contact['email'] ?? '' ),
			'source_label'             => $this->source_label( $source ),
			'open_request_admin_link'  => $this->open_request_admin_link( $context ),
		);
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
