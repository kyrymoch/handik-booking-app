<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprint 14a — RFC 5545 minimal-subset VCALENDAR builder.
 *
 * Notifications_Service uses this to attach a calendar invite (.ics)
 * to the customer-confirmation email. With Cal.com's own confirmation
 * email disabled (the new master toggle replaces it), the .ics is the
 * only way the customer gets a calendar entry — so it has to import
 * cleanly into Apple Calendar AND Google Calendar AND Outlook.
 *
 * Scope intentionally minimal: VCALENDAR + 1..N VEVENT blocks. No
 * RECURRENCE, no VALARM, no VTIMEZONE block (we emit DTSTART/DTEND
 * with explicit UTC offsets instead, the simplest interop path).
 *
 * Spec compliance:
 *   - Lines folded at 75 octets (RFC 5545 §3.1).
 *   - CRLF line endings (RFC 5545 §3.1).
 *   - Text fields escape `\`, `;`, `,`, and newlines (§3.3.11).
 *   - PRODID identifies us so other parsers can spot quirks.
 */
class Handik_Booking_App_Ics_Builder {
	const PRODID = '-//Handik Booking App//EN';
	const CRLF   = "\r\n";

	/**
	 * Build a one-event VCALENDAR.
	 *
	 * @param array<string, mixed> $event Event shape — see build_multi() docblock.
	 * @return string The .ics document.
	 */
	public static function build_single( array $event ) {
		return self::build_multi( array( $event ) );
	}

	/**
	 * Build an N-event VCALENDAR (used for project work-days schedules).
	 *
	 * Event shape:
	 *   uid             (string, required)  Globally unique identifier — anything stable per event.
	 *   summary         (string, required)  Short title shown in calendar UI.
	 *   description     (string, optional)  Long body. Newlines preserved (escaped per spec).
	 *   location        (string, optional)  Postal address.
	 *   dtstart_iso     (string, required)  ISO 8601 with offset, e.g. "2026-01-15T14:00:00-05:00".
	 *   dtend_iso       (string, required)  Same shape as dtstart_iso.
	 *   organizer_name  (string, optional)
	 *   organizer_email (string, optional)
	 *   attendee_name   (string, optional)
	 *   attendee_email  (string, optional)
	 *
	 * @param array<int, array<string, mixed>> $events List of events.
	 * @return string The .ics document. Empty string if no valid events.
	 */
	public static function build_multi( array $events ) {
		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:' . self::PRODID;
		$lines[] = 'METHOD:REQUEST';
		$lines[] = 'CALSCALE:GREGORIAN';

		$any_valid = false;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$block = self::build_vevent( $event );
			if ( '' === $block ) {
				continue;
			}
			$lines[] = $block;
			$any_valid = true;
		}

		if ( ! $any_valid ) {
			return '';
		}

		$lines[] = 'END:VCALENDAR';

		// Fold each top-level line then join with CRLF. The VEVENT block
		// is already a multi-line CRLF-delimited string with each line
		// pre-folded; passing it through fold_line again would fold lines
		// that were already short. So fold individually before assembly.
		$out = '';
		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, self::CRLF ) ) {
				// Pre-built block (VEVENT). Already folded.
				$out .= $line . self::CRLF;
			} else {
				$out .= self::fold_line( $line ) . self::CRLF;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $event Event.
	 * @return string CRLF-joined VEVENT block, or '' if required fields are missing.
	 */
	protected static function build_vevent( array $event ) {
		$uid          = trim( (string) ( $event['uid'] ?? '' ) );
		$summary      = trim( (string) ( $event['summary'] ?? '' ) );
		$dtstart_iso  = trim( (string) ( $event['dtstart_iso'] ?? '' ) );
		$dtend_iso    = trim( (string) ( $event['dtend_iso'] ?? '' ) );
		if ( '' === $uid || '' === $summary || '' === $dtstart_iso || '' === $dtend_iso ) {
			return '';
		}

		$dtstart = self::format_local( $dtstart_iso );
		$dtend   = self::format_local( $dtend_iso );
		$dtstamp = self::format_utc_now();
		if ( '' === $dtstart || '' === $dtend ) {
			return '';
		}

		$lines   = array();
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . self::escape_text( $uid );
		$lines[] = 'DTSTAMP:' . $dtstamp;
		$lines[] = 'DTSTART:' . $dtstart;
		$lines[] = 'DTEND:' . $dtend;
		$lines[] = 'SUMMARY:' . self::escape_text( $summary );

		$description = trim( (string) ( $event['description'] ?? '' ) );
		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::escape_text( $description );
		}

		$location = trim( (string) ( $event['location'] ?? '' ) );
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::escape_text( $location );
		}

		$organizer_email = sanitize_email( (string) ( $event['organizer_email'] ?? '' ) );
		if ( '' !== $organizer_email ) {
			$organizer_name = trim( (string) ( $event['organizer_name'] ?? '' ) );
			$cn             = '' !== $organizer_name ? ';CN=' . self::escape_param( $organizer_name ) : '';
			$lines[]        = 'ORGANIZER' . $cn . ':mailto:' . $organizer_email;
		}

		$attendee_email = sanitize_email( (string) ( $event['attendee_email'] ?? '' ) );
		if ( '' !== $attendee_email ) {
			$attendee_name = trim( (string) ( $event['attendee_name'] ?? '' ) );
			$cn            = '' !== $attendee_name ? ';CN=' . self::escape_param( $attendee_name ) : '';
			$lines[]       = 'ATTENDEE' . $cn . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:' . $attendee_email;
		}

		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'SEQUENCE:0';
		$lines[] = 'END:VEVENT';

		// Fold each VEVENT line individually then CRLF-join.
		$folded = array_map( array( self::class, 'fold_line' ), $lines );
		return implode( self::CRLF, $folded );
	}

	/**
	 * RFC 5545 §3.1 line-folding: any line longer than 75 octets must be
	 * split, with continuation lines starting with a single whitespace
	 * (space or tab). We use space.
	 *
	 * @param string $line Single line (no CRLF).
	 * @return string Folded line (CRLF + space at fold points).
	 */
	protected static function fold_line( $line ) {
		$line = (string) $line;
		// Multibyte safety: count bytes, not characters. RFC 5545 says
		// "octets" explicitly. PHP strlen() with no mbstring overload
		// returns bytes; we don't override mbstring.func_overload here.
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$first = true;
		while ( strlen( $line ) > 0 ) {
			$chunk_len = $first ? 75 : 74; // continuation lines lose 1 octet to the leading space
			$chunk     = substr( $line, 0, $chunk_len );
			$line      = substr( $line, $chunk_len );
			$out      .= ( $first ? '' : self::CRLF . ' ' ) . $chunk;
			$first     = false;
		}
		return $out;
	}

	/**
	 * RFC 5545 §3.3.11 TEXT escaping.
	 *
	 * @param string $s Raw text.
	 * @return string Escaped text safe for property values.
	 */
	protected static function escape_text( $s ) {
		$s = (string) $s;
		// Order matters: backslash first so we don't double-escape.
		$s = str_replace( '\\', '\\\\', $s );
		$s = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $s );
		$s = str_replace( ',', '\\,', $s );
		$s = str_replace( ';', '\\;', $s );
		return $s;
	}

	/**
	 * RFC 5545 §3.2 parameter values: double quotes can't contain double
	 * quotes; if the value contains `:`, `;`, or `,` we MUST quote. Strip
	 * the dangerous double-quote and quote the value.
	 *
	 * @param string $s Raw parameter value.
	 * @return string Quoted/escaped value.
	 */
	protected static function escape_param( $s ) {
		$s = str_replace( '"', '', (string) $s );
		if ( preg_match( '/[:;,]/', $s ) ) {
			return '"' . $s . '"';
		}
		return $s;
	}

	/**
	 * Format an ISO 8601 datetime as a "local time with UTC offset" form
	 * appropriate for inline DTSTART/DTEND when the event has a fixed
	 * offset known at email-send time. Emits the form the calendar
	 * apps interpret unambiguously.
	 *
	 * Returns "20260115T140000Z" for UTC, or "20260115T140000" + offset-
	 * adjusted UTC variant. Simplest interop: convert to UTC and emit Z.
	 *
	 * Cal.com timestamps are always ISO 8601 with offset, so this works
	 * for every payload we get from Cal.
	 *
	 * @param string $iso ISO 8601 datetime.
	 * @return string YYYYMMDDTHHMMSSZ or '' on parse failure.
	 */
	protected static function format_local( $iso ) {
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( \Exception $e ) {
			return '';
		}
		$dt = $dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Ymd\THis\Z' );
	}

	/**
	 * @return string DTSTAMP — current UTC time in basic ISO format.
	 */
	protected static function format_utc_now() {
		$dt = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Ymd\THis\Z' );
	}
}
