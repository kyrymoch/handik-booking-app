<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless presentation helpers shared across the admin pages.
 *
 * All methods here only format/escape — never query the DB or mutate state —
 * so each admin page can call them freely. Times are normalized to Eastern (ET).
 */
class Handik_Booking_App_Admin_Helpers {

	const TIMEZONE = 'America/New_York';

	// ----- Times -----------------------------------------------------------

	/**
	 * @param string $datetime DATETIME (UTC).
	 * @return DateTimeImmutable|null
	 */
	public static function utc_to_eastern( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime ) {
			return null;
		}
		try {
			$utc = new DateTimeZone( 'UTC' );
			$et  = new DateTimeZone( self::TIMEZONE );
			$value = new DateTimeImmutable( $datetime, $utc );
			return $value->setTimezone( $et );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Render a booking start/end window in ET.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param string $mode 'default' | 'compact' | 'card'.
	 * @return string
	 */
	public static function format_booking_window( array $booking, $mode = 'default' ) {
		$start = ! empty( $booking['start_time'] ) ? (string) $booking['start_time'] : '';
		$end   = ! empty( $booking['end_time'] ) ? (string) $booking['end_time'] : '';

		if ( $start && $end ) {
			$start_dt = self::utc_to_eastern( $start );
			$end_dt   = self::utc_to_eastern( $end );
			if ( ! $start_dt || ! $end_dt ) {
				return $start . ' - ' . $end;
			}
			if ( 'compact' === $mode ) {
				return $start_dt->format( 'D, M j' ) . ' · ' . $start_dt->format( 'g:i A' ) . ' – ' . $end_dt->format( 'g:i A' );
			}
			if ( 'card' === $mode ) {
				return $start_dt->format( 'D, M j' ) . ' · ' . $start_dt->format( 'g:i A' ) . ' – ' . $end_dt->format( 'g:i A' );
			}
			return $start_dt->format( 'l, F j, Y g:i A' ) . ' – ' . $end_dt->format( 'g:i A' ) . ' ET';
		}

		$single = $start ? $start : $end;
		if ( '' === $single ) {
			return __( 'Not scheduled', 'handik-booking-app' );
		}
		$single_dt = self::utc_to_eastern( $single );
		return $single_dt ? $single_dt->format( 'l, F j, Y g:i A' ) . ' ET' : $single;
	}

	/**
	 * @param string $utc_datetime DATETIME (UTC).
	 * @return string
	 */
	public static function relative_time( $utc_datetime ) {
		$utc_datetime = trim( (string) $utc_datetime );
		if ( '' === $utc_datetime ) {
			return '';
		}
		$ts = strtotime( $utc_datetime );
		if ( ! $ts ) {
			return '';
		}
		return human_time_diff( $ts, time() ) . ' ' . __( 'ago', 'handik-booking-app' );
	}

	/**
	 * Sprint 8: unified short datetime — `Mon, Jan 15 · 2:00 PM`.
	 *
	 * Replaces the drift across admin pages where `created_at` / `updated_at`
	 * / `last_seen_at` were printed as raw MySQL DATETIME strings (no
	 * timezone conversion, no human-readable formatting). Use this for
	 * single-timestamp cells in tables and list rows. For booking start/end
	 * windows keep using `format_booking_window()` — that helper renders a
	 * range, not a point in time.
	 *
	 * @param string $datetime  MySQL DATETIME.
	 * @param bool   $assume_utc Whether the value is UTC (default true; pass
	 *                          false for `Logger::log()` entries which use
	 *                          `current_time('mysql')` = site local time).
	 * @return string
	 */
	public static function format_short( $datetime, $assume_utc = true ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime ) {
			return '';
		}
		try {
			$source_tz = $assume_utc ? new DateTimeZone( 'UTC' ) : wp_timezone();
			$value     = new DateTimeImmutable( $datetime, $source_tz );
			$value     = $value->setTimezone( new DateTimeZone( self::TIMEZONE ) );
			return $value->format( 'D, M j · g:i A' );
		} catch ( Exception $e ) {
			return $datetime;
		}
	}

	/**
	 * Sprint 8: unified long datetime — `Monday, January 15, 2024 · 2:00 PM ET`.
	 *
	 * @param string $datetime   MySQL DATETIME.
	 * @param bool   $assume_utc See format_short().
	 * @return string
	 */
	public static function format_long( $datetime, $assume_utc = true ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime ) {
			return '';
		}
		try {
			$source_tz = $assume_utc ? new DateTimeZone( 'UTC' ) : wp_timezone();
			$value     = new DateTimeImmutable( $datetime, $source_tz );
			$value     = $value->setTimezone( new DateTimeZone( self::TIMEZONE ) );
			return $value->format( 'l, F j, Y · g:i A' ) . ' ET';
		} catch ( Exception $e ) {
			return $datetime;
		}
	}

	// ----- Strings ---------------------------------------------------------

	public static function client_type_label( $client_type ) {
		switch ( (string) $client_type ) {
			case 'returning_client':
				return __( 'Returning Client', 'handik-booking-app' );
			case 'new_client':
				return __( 'New Client', 'handik-booking-app' );
			// Sprint 13 hotfix (F12): admin-initiated rows have a new
			// client_type set by Direct_Booking_Service::admin_submit;
			// give them a friendly label instead of leaking the slug.
			case 'admin_initiated':
				return __( 'Admin booking', 'handik-booking-app' );
		}
		return (string) $client_type;
	}

	/**
	 * Combine task IDs into "Label1, Label2 (+N more)" using the catalog.
	 *
	 * @param array $task_ids Task IDs.
	 * @param Handik_Booking_App_Service_Catalog_Service $catalog Catalog.
	 * @param int $max_inline How many labels to show before collapsing rest into "+N more".
	 * @return string
	 */
	public static function task_summary_text( array $task_ids, $catalog, $max_inline = 2 ) {
		$labels = array();
		foreach ( $task_ids as $task_id ) {
			$task = $catalog ? $catalog->find_task( (string) $task_id ) : null;
			$labels[] = $task ? (string) $task['label'] : (string) $task_id;
		}
		$labels = array_values( array_filter( array_unique( $labels ) ) );
		if ( empty( $labels ) ) {
			return '';
		}
		if ( count( $labels ) <= $max_inline ) {
			return implode( ' · ', $labels );
		}
		$head = array_slice( $labels, 0, $max_inline );
		$rest = count( $labels ) - $max_inline;
		return implode( ' · ', $head ) . sprintf( ' · +%d more', $rest );
	}

	/**
	 * Markup variant of task_summary_text for booking detail (with rates).
	 *
	 * @return string
	 */
	public static function task_summary_with_rates_html( array $task_ids, $catalog ) {
		if ( empty( $task_ids ) ) {
			return '<p class="handik-admin-muted">' . esc_html__( 'No tasks selected.', 'handik-booking-app' ) . '</p>';
		}
		$html = '<ul class="handik-admin-task-list">';
		foreach ( $task_ids as $task_id ) {
			$task = $catalog ? $catalog->find_task( (string) $task_id ) : null;
			$label = $task ? (string) $task['label'] : (string) $task_id;
			$rate  = ( $task && ! empty( $task['rate_label'] ) ) ? (string) $task['rate_label'] : '';
			$html .= '<li><span class="handik-admin-task-list__label">' . esc_html( $label ) . '</span>';
			if ( $rate ) {
				$html .= ' <span class="handik-admin-task-list__rate">' . esc_html( $rate ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	public static function full_request_address( $request, $address ) {
		$base = '';
		$unit = '';
		if ( is_array( $address ) ) {
			$base = ! empty( $address['address_full'] ) ? (string) $address['address_full'] : $base;
			$unit = ! empty( $address['address_unit'] ) ? (string) $address['address_unit'] : $unit;
		}
		if ( '' === $base && is_array( $request ) ) {
			$base = ! empty( $request['address_full'] ) ? (string) $request['address_full'] : '';
			$unit = ! empty( $request['address_unit'] ) ? (string) $request['address_unit'] : $unit;
		}
		$base = trim( $base );
		$unit = trim( $unit );
		if ( '' === $unit || '' === $base ) {
			return $base;
		}
		if ( false !== stripos( $base, $unit ) ) {
			return $base;
		}
		return trim( $base . ', Apt ' . $unit );
	}

	public static function request_city( $request, $address ) {
		if ( is_array( $address ) && ! empty( $address['city'] ) ) {
			return (string) $address['city'];
		}
		if ( is_array( $request ) && ! empty( $request['address_full'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) $request['address_full'] ) );
			if ( count( $parts ) >= 2 && '' !== $parts[1] ) {
				return $parts[1];
			}
		}
		return '';
	}

	// ----- Map / phone links -----------------------------------------------

	public static function apple_maps_url( $address ) {
		$address = trim( (string) $address );
		return '' === $address ? '' : 'https://maps.apple.com/?q=' . rawurlencode( $address );
	}

	public static function google_maps_url( $address ) {
		$address = trim( (string) $address );
		return '' === $address ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
	}

	public static function map_embed_url( $address ) {
		$address = trim( (string) $address );
		return '' === $address ? '' : 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&z=15&output=embed';
	}

	public static function tel_url( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		return 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );
	}

	/**
	 * 2.1.25.0 — `sms:` URL for the mobile-compact action bar icon. iOS
	 * Safari opens the Messages composer pre-filled with the recipient
	 * when this is the link href, Android does the same via the
	 * default messaging app. Strips formatting the same way `tel_url`
	 * does (digits + leading +).
	 *
	 * @param string $phone Phone in any format.
	 * @return string `sms:+18005551234` or '' when no phone is set.
	 */
	public static function sms_url( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		return 'sms:' . preg_replace( '/[^0-9+]/', '', $phone );
	}

	public static function mailto_url( $email ) {
		$email = sanitize_email( (string) $email );
		return '' === $email ? '' : 'mailto:' . $email;
	}

	// ----- Photos / media --------------------------------------------------

	public static function photos_gallery_markup( array $photos ) {
		if ( empty( $photos ) ) {
			return '<p class="handik-admin-muted">' . esc_html__( 'No photos uploaded for this job.', 'handik-booking-app' ) . '</p>';
		}
		$html = '<div class="handik-admin-photo-grid">';
		foreach ( $photos as $photo ) {
			if ( empty( $photo['url'] ) ) {
				continue;
			}
			$url  = esc_url( (string) $photo['url'] );
			$name = ! empty( $photo['name'] ) ? (string) $photo['name'] : basename( (string) $photo['url'] );
			$html .= '<a class="handik-admin-photo" href="' . $url . '" target="_blank" rel="noopener noreferrer" data-handik-lightbox>';
			$html .= '<img src="' . $url . '" alt="' . esc_attr( $name ) . '" loading="lazy" />';
			$html .= '<span class="handik-admin-photo__name">' . esc_html( $name ) . '</span>';
			$html .= '</a>';
		}
		$html .= '</div>';
		return $html;
	}

	public static function photo_count_label( array $photos ) {
		$total = 0;
		$videos = 0;
		foreach ( $photos as $photo ) {
			$total++;
			$mime = (string) ( $photo['mime_type'] ?? '' );
			if ( 0 === strpos( $mime, 'video/' ) ) {
				$videos++;
			}
		}
		if ( ! $total ) {
			return __( 'No media', 'handik-booking-app' );
		}
		$photos_count = $total - $videos;
		$parts = array();
		if ( $photos_count > 0 ) {
			$parts[] = sprintf( _n( '%d photo', '%d photos', $photos_count, 'handik-booking-app' ), $photos_count );
		}
		if ( $videos > 0 ) {
			$parts[] = sprintf( _n( '%d video', '%d videos', $videos, 'handik-booking-app' ), $videos );
		}
		return implode( ', ', $parts );
	}

	// ----- Detail list / cards / pills -------------------------------------

	public static function detail_list_markup( array $items ) {
		$html = '<dl class="handik-admin-detail-list">';
		foreach ( $items as $label => $value ) {
			if ( null === $value || '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
		}
		$html .= '</dl>';
		return $html;
	}

	public static function status_pill_markup( $status, $label = '' ) {
		// Sprint 8: differentiate `booked` / `confirmed` / `completed`. They
		// all collapsed to `success` (one shade of green), so admins lost
		// the "is this actually done?" signal at a glance — every future
		// visit looked the same as a finished one. New mapping:
		//   booked     → info     (blue) — Cal.com webhook acknowledged, on the schedule
		//   confirmed  → success  (green) — fulfilled by the contractor (post-visit, pre-completed)
		//   completed  → done     (deep teal) — final, archived
		// Everything else keeps its prior tone, so existing CSS for
		// danger/warning/muted/info/neutral still applies.
		$status = sanitize_key( (string) $status );
		$label  = '' !== $label ? (string) $label : ucfirst( str_replace( '_', ' ', $status ) );
		$tone   = 'neutral';
		switch ( $status ) {
			case 'booked':
				$tone = 'info';
				break;
			case 'confirmed':
				$tone = 'success';
				break;
			case 'completed':
				$tone = 'done';
				break;
			case 'cancelled':
			case 'no_show':
				$tone = 'danger';
				break;
			case 'pending':
			case 'booking_pending':
			case 'rescheduled':
				$tone = 'warning';
				break;
			case 'draft':
				$tone = 'muted';
				break;
			case 'ready_for_booking':
				$tone = 'info';
				break;
		}
		return '<span class="handik-admin-pill handik-admin-pill--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function chip_markup( $href, $label, $count, $tone = 'muted' ) {
		$count = (int) $count;
		$active = $count > 0;
		$cls = 'handik-admin-chip handik-admin-chip--' . esc_attr( $tone );
		if ( ! $active ) {
			$cls .= ' is-zero';
		}
		$dot = $active ? '<span class="handik-admin-chip__dot" aria-hidden="true"></span>' : '';
		$inner = $dot . '<strong>' . esc_html( (string) $count ) . '</strong> <span>' . esc_html( $label ) . '</span>';
		// Sprint 11 fix: render the chip as an `<a>` even when count is 0
		// IF a href is provided. Was P2 — owners who just filtered "ready
		// not booked" couldn't tap the chip to navigate back to the
		// filtered list. The `is-zero` class still mutes it visually.
		if ( $href ) {
			return '<a class="' . $cls . '" href="' . esc_url( $href ) . '">' . $inner . '</a>';
		}
		return '<span class="' . $cls . '">' . $inner . '</span>';
	}

	// ----- Form fields (settings pages) ------------------------------------

	public static function field( $name, $label, $value, $type = 'text', $step = '' ) {
		$id = 'handik-' . sanitize_key( $name );
		printf(
			'<label class="handik-admin-field" for="%5$s"><span class="handik-admin-field__label">%1$s</span><input id="%5$s" type="%2$s" name="%3$s" value="%4$s" %6$s autocomplete="off" /></label>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( $id ),
			$step ? 'step="' . esc_attr( $step ) . '"' : ''
		);
	}

	public static function textarea_field( $name, $label, $value, $description = '', $rows = 3 ) {
		$id = 'handik-' . sanitize_key( $name );
		echo '<label class="handik-admin-field handik-admin-field--textarea" for="' . esc_attr( $id ) . '">';
		echo '<span class="handik-admin-field__label">' . esc_html( $label ) . '</span>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( (string) (int) $rows ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		if ( $description ) {
			echo '<small class="handik-admin-field__help">' . esc_html( $description ) . '</small>';
		}
		echo '</label>';
	}

	public static function select_field( $name, $label, $value, array $options ) {
		$id = 'handik-' . sanitize_key( $name );
		echo '<label class="handik-admin-field" for="' . esc_attr( $id ) . '">';
		echo '<span class="handik-admin-field__label">' . esc_html( $label ) . '</span>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( (string) $option_value ) . '"' . selected( (string) $value, (string) $option_value, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
		}
		echo '</select></label>';
	}

	public static function checkbox_field( $name, $label, $value ) {
		$id = 'handik-' . sanitize_key( $name );
		echo '<label class="handik-admin-checkbox" for="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
		echo '<input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . ' />';
		echo '<span>' . esc_html( $label ) . '</span></label>';
	}

	// ----- Tabs ------------------------------------------------------------

	public static function current_tab( array $allowed, $default ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $default;
		return in_array( $tab, $allowed, true ) ? $tab : $default;
	}

	public static function tabs_markup( array $tabs, $active, $page = '' ) {
		if ( '' === $page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'handik-booking-app';
		}
		$html = '<nav class="handik-admin-tabs nav-tab-wrapper" aria-label="' . esc_attr__( 'Section tabs', 'handik-booking-app' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url  = add_query_arg(
				array( 'page' => $page, 'tab' => $key ),
				admin_url( 'admin.php' )
			);
			$cls  = 'nav-tab handik-admin-tab' . ( $active === $key ? ' nav-tab-active is-active' : '' );
			$html .= '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	// ----- Page chrome -----------------------------------------------------

	public static function page_start( $title, $subtitle = '' ) {
		echo '<div class="wrap handik-admin-wrap">';
		echo '<header class="handik-admin-page-header">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p class="handik-admin-page-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</header>';
	}

	public static function page_end() {
		echo '</div>';
	}

	public static function admin_url_for( $page, array $extra = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => $page ), $extra ),
			admin_url( 'admin.php' )
		);
	}

	// ----- Misc ------------------------------------------------------------

	public static function task_count_label( array $task_ids ) {
		$n = count( $task_ids );
		return sprintf( _n( '%d task', '%d tasks', $n, 'handik-booking-app' ), $n );
	}

	public static function format_money_range( $low, $high ) {
		$low  = (float) $low;
		$high = (float) $high;
		if ( $low <= 0 && $high <= 0 ) {
			return '';
		}
		if ( $low > 0 && $high > 0 && $low !== $high ) {
			return sprintf( '$%s – $%s', number_format_i18n( $low ), number_format_i18n( $high ) );
		}
		$value = $high > 0 ? $high : $low;
		return '$' . number_format_i18n( $value );
	}

	/**
	 * Apply {{placeholders}} to a template string.
	 *
	 * @param string $template Template body.
	 * @param array<string, string> $placeholders Map.
	 * @return string
	 */
	public static function render_template( $template, array $placeholders ) {
		$out = (string) $template;
		foreach ( $placeholders as $key => $value ) {
			$out = str_replace( '{{' . $key . '}}', (string) $value, $out );
		}
		return $out;
	}
}
