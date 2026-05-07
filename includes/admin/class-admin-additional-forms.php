<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: Handik Booking → Additional Forms.
 *
 * Three tabs:
 *   1. Presets       — editable list. Edit one preset at a time (form_title,
 *                      enabled, durations, required_days, Cal.com event
 *                      type id / slug / url, allowed_start_time, admin_notes).
 *   2. Direct        — submissions from direct-visit forms.
 *   3. Project       — Project Work Days scheduling requests with day-level
 *                      detail.
 *
 * All admin POSTs are nonce-protected with the `handik_manage_bookings` cap
 * via maybe_save_preset(). The list view always renders shortcode + public
 * URL so admins can copy them with one click.
 */
class Handik_Booking_App_Admin_Additional_Forms {
	const PAGE_SLUG          = 'handik-booking-app-additional-forms';
	const NONCE_ACTION_SAVE  = 'handik_save_form_preset';
	const NONCE_FIELD_SAVE   = 'handik_save_form_preset_nonce';
	const NONCE_ACTION_CANCEL_DAY = 'handik_cancel_project_day';
	const NONCE_FIELD_CANCEL_DAY  = 'handik_cancel_project_day_nonce';

	/** @var Handik_Booking_App_Booking_Presets_Service */
	protected $presets;
	/** @var Handik_Booking_App_Direct_Booking_Service */
	protected $direct;
	/** @var Handik_Booking_App_Project_Schedule_Service */
	protected $project;
	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;

	public function __construct( $presets, $direct, $project, $contacts, $addresses ) {
		$this->presets   = $presets;
		$this->direct    = $direct;
		$this->project   = $project;
		$this->contacts  = $contacts;
		$this->addresses = $addresses;

		add_action( 'admin_init', array( $this, 'maybe_save_preset' ) );
		add_action( 'admin_init', array( $this, 'maybe_cancel_day' ) );
	}

	public function render() {
		$tab         = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'presets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$schedule_id = isset( $_GET['schedule_id'] ) ? absint( wp_unslash( $_GET['schedule_id'] ) ) : 0;          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preset_id   = isset( $_GET['preset_id'] ) ? absint( wp_unslash( $_GET['preset_id'] ) ) : 0;              // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap handik-admin">';
		echo '<h1>' . esc_html__( 'Additional Forms', 'handik-booking-app' ) . '</h1>';
		settings_errors( 'handik_additional_forms' );
		$this->render_tabs( $tab );

		if ( 'presets' === $tab && $preset_id > 0 ) {
			$this->render_preset_edit( $preset_id );
		} elseif ( 'project' === $tab && $schedule_id > 0 ) {
			$this->render_project_detail( $schedule_id );
		} elseif ( 'project' === $tab ) {
			$this->render_project_list();
		} elseif ( 'direct' === $tab ) {
			$this->render_direct_list();
		} else {
			$this->render_presets_list();
		}

		echo '</div>';
	}

	protected function render_tabs( $current ) {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$tabs = array(
			'presets' => __( 'Presets', 'handik-booking-app' ),
			'direct'  => __( 'Direct Submissions', 'handik-booking-app' ),
			'project' => __( 'Project Schedules', 'handik-booking-app' ),
		);
		echo '<nav class="nav-tab-wrapper handik-admin__tabs">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg( 'tab', $key, $base );
			$class = 'nav-tab' . ( $current === $key ? ' nav-tab-active' : '' );
			printf(
				'<a class="%1$s" href="%2$s">%3$s</a>',
				esc_attr( $class ),
				esc_url( $url ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	// =====================================================================
	// Presets — list + edit
	// =====================================================================

	protected function render_presets_list() {
		$presets = $this->presets->all();
		$base    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		echo '<p class="description">' . esc_html__( 'Public booking-form presets. Direct visit presets use the Cal.com iframe; project work days presets use the Cal.com API. Edit a row to point it at a Cal.com event type or slug, change the duration, or disable it.', 'handik-booking-app' ) . '</p>';
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration / Days', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Cal.com', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Embed', 'handik-booking-app' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $presets as $preset ) {
			$slug      = (string) $preset['preset_slug'];
			$shortcode = '[handik_booking_form preset="' . $slug . '"]';
			$public    = home_url( '/booking/' . $slug . '/' );
			$type      = (string) $preset['form_type'];
			$cal       = $this->preset_cal_summary( $preset );
			if ( 'project_work_days' === $type ) {
				$detail = sprintf(
					/* translators: 1: required days, 2: minutes */
					esc_html__( '%1$d days × %2$d min', 'handik-booking-app' ),
					(int) $preset['required_days'],
					(int) $preset['work_day_duration_minutes']
				);
			} else {
				$detail = sprintf(
					/* translators: %d: minutes */
					esc_html__( '%d min', 'handik-booking-app' ),
					(int) $preset['duration_minutes']
				);
			}
			$preset_db_id = (int) ( $preset['id'] ?? 0 );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $preset['form_title'] ) . '</td>';
			echo '<td><code>' . esc_html( $slug ) . '</code></td>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . $detail . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $cal . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . ( ! empty( $preset['enabled'] ) ? '<span class="dashicons dashicons-yes-alt"></span>' : '—' ) . '</td>';
			echo '<td>';
			echo '<code>' . esc_html( $shortcode ) . '</code><br>';
			echo '<a href="' . esc_url( $public ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $public ) . '</a>';
			echo '</td>';
			echo '<td>';
			if ( $preset_db_id > 0 ) {
				$edit_url = add_query_arg(
					array(
						'tab'       => 'presets',
						'preset_id' => $preset_db_id,
					),
					$base
				);
				echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'handik-booking-app' ) . '</a>';
			} else {
				// In-memory default — table not yet seeded. Editing requires a
				// real row id, so surface a friendly hint instead of a dead
				// link to "Preset not found."
				echo '<span class="handik-admin-muted" title="' . esc_attr__( 'Activate the plugin or run pending migrations to enable editing.', 'handik-booking-app' ) . '">' . esc_html__( 'default', 'handik-booking-app' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	protected function render_preset_edit( $preset_id ) {
		$preset = $this->find_preset_by_id( $preset_id );
		if ( ! $preset ) {
			echo '<p>' . esc_html__( 'Preset not found.', 'handik-booking-app' ) . '</p>';
			return;
		}
		$is_project = Handik_Booking_App_Booking_Presets_Service::FORM_TYPE_PROJECT === (string) $preset['form_type'];
		$back_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=presets' );

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to presets', 'handik-booking-app' ) . '</a></p>';
		echo '<h2>' . esc_html( (string) $preset['form_title'] ) . '</h2>';

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION_SAVE, self::NONCE_FIELD_SAVE );
		echo '<input type="hidden" name="preset_id" value="' . (int) $preset['id'] . '">';

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_text(
			'form_title',
			__( 'Title', 'handik-booking-app' ),
			(string) $preset['form_title'],
			__( 'Shown to customers as the form heading.', 'handik-booking-app' )
		);
		$this->row_checkbox(
			'enabled',
			__( 'Enabled', 'handik-booking-app' ),
			! empty( $preset['enabled'] ),
			__( 'When unchecked, the public URL and shortcode show a friendly "not available" message.', 'handik-booking-app' )
		);

		if ( $is_project ) {
			$this->row_number(
				'required_days',
				__( 'Required days', 'handik-booking-app' ),
				(int) $preset['required_days'],
				1,
				14,
				__( 'How many days the customer must pick. The flow is configured for 1–14.', 'handik-booking-app' )
			);
			$this->row_number(
				'work_day_duration_minutes',
				__( 'Work day duration (minutes)', 'handik-booking-app' ),
				(int) $preset['work_day_duration_minutes'],
				60,
				720,
				__( 'Length of each work day. Default 480 (8h).', 'handik-booking-app' )
			);
			$this->row_text(
				'cal_event_type_id',
				__( 'Cal.com event type ID', 'handik-booking-app' ),
				(string) ( $preset['cal_event_type_id'] ?? '' ),
				__( 'Numeric Cal.com event type ID. Server-side bookings via the API need either this or a slug below.', 'handik-booking-app' )
			);
			$this->row_text(
				'cal_event_slug',
				__( 'Cal.com event type slug', 'handik-booking-app' ),
				(string) ( $preset['cal_event_slug'] ?? '' ),
				__( 'Optional. Use this when you prefer a slug-based reference over the numeric ID.', 'handik-booking-app' )
			);
			$this->row_text(
				'allowed_start_time',
				__( 'Default start time', 'handik-booking-app' ),
				(string) ( $preset['allowed_start_time'] ?? '' ),
				__( 'Informational. Day availability is set inside Cal.com directly.', 'handik-booking-app' )
			);
		} else {
			$this->row_number(
				'duration_minutes',
				__( 'Duration (minutes)', 'handik-booking-app' ),
				(int) $preset['duration_minutes'],
				15,
				600,
				__( 'Locked Cal.com slot length. Pre-selects this duration in the iframe.', 'handik-booking-app' )
			);
			$this->row_text(
				'cal_event_url',
				__( 'Cal.com event URL override', 'handik-booking-app' ),
				(string) ( $preset['cal_event_url'] ?? '' ),
				__( 'Leave empty to use the global Cal.com URL configured under App Setup → Cal.com for this booking type.', 'handik-booking-app' )
			);
		}

		$this->row_textarea(
			'admin_notes',
			__( 'Admin notes (private)', 'handik-booking-app' ),
			(string) ( $preset['admin_notes'] ?? '' ),
			__( 'Not shown to customers — for your own reference.', 'handik-booking-app' )
		);
		echo '</tbody></table>';

		submit_button( __( 'Save preset', 'handik-booking-app' ) );
		echo '</form>';
	}

	/**
	 * Save handler for the preset edit form.
	 */
	public function maybe_save_preset() {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		if ( empty( $_POST[ self::NONCE_FIELD_SAVE ] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION_SAVE, self::NONCE_FIELD_SAVE );

		$preset_id = isset( $_POST['preset_id'] ) ? absint( $_POST['preset_id'] ) : 0;
		if ( ! $preset_id ) {
			return;
		}

		$payload = array(
			'enabled'                   => ! empty( $_POST['enabled'] ),
			'form_title'                => isset( $_POST['form_title'] ) ? wp_unslash( (string) $_POST['form_title'] ) : '',
			'admin_notes'               => isset( $_POST['admin_notes'] ) ? wp_unslash( (string) $_POST['admin_notes'] ) : '',
		);
		if ( isset( $_POST['duration_minutes'] ) ) {
			$payload['duration_minutes'] = absint( $_POST['duration_minutes'] );
		}
		if ( isset( $_POST['required_days'] ) ) {
			$payload['required_days'] = absint( $_POST['required_days'] );
		}
		if ( isset( $_POST['work_day_duration_minutes'] ) ) {
			$payload['work_day_duration_minutes'] = absint( $_POST['work_day_duration_minutes'] );
		}
		if ( isset( $_POST['cal_event_type_id'] ) ) {
			$payload['cal_event_type_id'] = wp_unslash( (string) $_POST['cal_event_type_id'] );
		}
		if ( isset( $_POST['cal_event_slug'] ) ) {
			$payload['cal_event_slug'] = wp_unslash( (string) $_POST['cal_event_slug'] );
		}
		if ( isset( $_POST['cal_event_url'] ) ) {
			$payload['cal_event_url'] = wp_unslash( (string) $_POST['cal_event_url'] );
		}
		if ( isset( $_POST['allowed_start_time'] ) ) {
			$payload['allowed_start_time'] = wp_unslash( (string) $_POST['allowed_start_time'] );
		}

		$this->presets->update( $preset_id, $payload );

		add_settings_error(
			'handik_additional_forms',
			'preset_saved',
			__( 'Preset updated.', 'handik-booking-app' ),
			'updated'
		);
	}

	// =====================================================================
	// Direct + Project list/detail (unchanged behavior, polished markup)
	// =====================================================================

	protected function render_direct_list() {
		$rows = $this->direct->list_recent( 100 );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No direct booking submissions yet.', 'handik-booking-app' ) . '</p>';
			return;
		}

		// Batch-fetch contacts + addresses in one query each instead of N
		// per-row lookups. With list_recent(100) this saves up to 200 SQL
		// round-trips per page render.
		$contact_ids = array_map( static function ( $r ) { return (int) ( $r['contact_id'] ?? 0 ); }, $rows );
		$address_ids = array_map( static function ( $r ) { return (int) ( $r['address_id'] ?? 0 ); }, $rows );
		$contacts    = $this->contacts->get_many( $contact_ids );
		$addresses   = $this->addresses->get_many( $address_ids );

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Client', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Preset', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Cal booking', 'handik-booking-app' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$contact  = $contacts[ (int) ( $row['contact_id'] ?? 0 ) ] ?? null;
			$address  = $addresses[ (int) ( $row['address_id'] ?? 0 ) ] ?? null;
			$address_text = '';
			if ( $address ) {
				$line = (string) ( $address['address_full'] ?: $address['address_line_1'] );
				if ( ! empty( $address['address_unit'] ) ) {
					$line .= ', ' . (string) $address['address_unit'];
				}
				$address_text = $line;
			}
			$cal_link = '';
			if ( ! empty( $row['cal_booking_uid'] ) ) {
				$cal_link = '<code>' . esc_html( (string) $row['cal_booking_uid'] ) . '</code>';
			} elseif ( ! empty( $row['cal_booking_id'] ) ) {
				$cal_link = '<code>' . esc_html( (string) $row['cal_booking_id'] ) . '</code>';
			} elseif ( ! empty( $row['cal_booking_url'] ) ) {
				$cal_link = '<a href="' . esc_url( (string) $row['cal_booking_url'] ) . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Open Cal URL', 'handik-booking-app' ) . '</a>';
			}
			echo '<tr>';
			echo '<td>' . esc_html( Handik_Booking_App_Admin_Helpers::format_short( (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['full_name'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['phone'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( $address_text ?: '—' ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['preset_slug'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['duration_minutes'] ) . ' min</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . $cal_link . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	protected function render_project_list() {
		$rows = $this->project->list_recent( 100 );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No project scheduling requests yet.', 'handik-booking-app' ) . '</p>';
			return;
		}
		// Batch contact fetch (was N+1).
		$contact_ids = array_map( static function ( $r ) { return (int) ( $r['contact_id'] ?? 0 ); }, $rows );
		$contacts    = $this->contacts->get_many( $contact_ids );

		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Client', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Preset', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Days', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$contact = $contacts[ (int) ( $row['contact_id'] ?? 0 ) ] ?? null;
			$detail  = add_query_arg(
				array(
					'tab'         => 'project',
					'schedule_id' => (int) $row['id'],
				),
				$base
			);
			echo '<tr>';
			echo '<td>' . esc_html( Handik_Booking_App_Admin_Helpers::format_short( (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['full_name'] ?? '—' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['preset_slug'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['required_days'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td><a href="' . esc_url( $detail ) . '">' . esc_html__( 'Details', 'handik-booking-app' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	protected function render_project_detail( $schedule_id ) {
		$schedule = $this->project->get( $schedule_id );
		if ( ! $schedule ) {
			echo '<p>' . esc_html__( 'Schedule not found.', 'handik-booking-app' ) . '</p>';
			return;
		}
		$contact = $this->contacts->get( (int) $schedule['contact_id'] );
		$days    = $this->project->list_days( $schedule_id );

		// Public-customer URL for this schedule. The customer reaches this
		// via /booking/{slug} but admin sometimes needs to send the link
		// directly (returning customer who closed the tab, etc.).
		$public_url = add_query_arg(
			array( 'token' => (string) $schedule['public_token'] ),
			home_url( '/booking/' . (string) $schedule['preset_slug'] . '/' )
		);

		echo '<h2>' . esc_html__( 'Project schedule', 'handik-booking-app' ) . ' #' . (int) $schedule['id'] . '</h2>';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th><td>' . esc_html( (string) $schedule['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Client', 'handik-booking-app' ) . '</th><td>' . esc_html( (string) ( $contact['full_name'] ?? '—' ) ) . '<br>' . esc_html( (string) ( $contact['phone'] ?? '' ) ) . ' · ' . esc_html( (string) ( $contact['email'] ?? '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Preset', 'handik-booking-app' ) . '</th><td><code>' . esc_html( (string) $schedule['preset_slug'] ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Days requested', 'handik-booking-app' ) . '</th><td>' . esc_html( (string) $schedule['required_days'] ) . ' × ' . esc_html( (string) $schedule['work_day_duration_minutes'] ) . ' min</td></tr>';
		echo '<tr><th>' . esc_html__( 'Public link', 'handik-booking-app' ) . '</th><td>';
		echo '<input type="text" readonly value="' . esc_attr( $public_url ) . '" class="regular-text code" onclick="this.select()" style="width: 100%; max-width: 640px;">';
		echo '<p class="description">' . esc_html__( 'Send this URL to the customer if they need to revisit the schedule. The token is unguessable.', 'handik-booking-app' ) . '</p>';
		echo '</td></tr>';
		if ( ! empty( $schedule['error_message'] ) ) {
			echo '<tr><th>' . esc_html__( 'Error', 'handik-booking-app' ) . '</th><td><code>' . esc_html( (string) $schedule['error_message'] ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Selected days', 'handik-booking-app' ) . '</h3>';
		if ( empty( $days ) ) {
			echo '<p>' . esc_html__( 'Client has not selected any days yet.', 'handik-booking-app' ) . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr><th>#</th><th>' . esc_html__( 'Start', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'End', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Cal booking', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Action', 'handik-booking-app' ) . '</th></tr></thead><tbody>';
		foreach ( $days as $day ) {
			$cal = '';
			if ( ! empty( $day['cal_booking_uid'] ) ) {
				$cal = '<code>' . esc_html( (string) $day['cal_booking_uid'] ) . '</code>';
				if ( ! empty( $day['cal_booking_url'] ) ) {
					$cal .= '<br><a href="' . esc_url( (string) $day['cal_booking_url'] ) . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Open', 'handik-booking-app' ) . '</a>';
				}
			} elseif ( ! empty( $day['error_message'] ) ) {
				$cal = '<code>' . esc_html( (string) $day['error_message'] ) . '</code>';
			}
			// Cancel button — only meaningful while a Cal booking is live.
			$action_cell = '';
			$cancellable_states = array(
				Handik_Booking_App_Project_Schedule_Service::DAY_STATUS_CREATED,
				Handik_Booking_App_Project_Schedule_Service::DAY_STATUS_CONFIRMED,
			);
			if ( in_array( (string) $day['status'], $cancellable_states, true ) && ! empty( $day['cal_booking_uid'] ) ) {
				$action_cell = '<form method="post" action="" onsubmit="return confirm(\'' . esc_js( __( 'Cancel this work day on Cal.com?', 'handik-booking-app' ) ) . '\');" style="margin: 0;">';
				$action_cell .= wp_nonce_field( self::NONCE_ACTION_CANCEL_DAY, self::NONCE_FIELD_CANCEL_DAY, true, false );
				$action_cell .= '<input type="hidden" name="day_id" value="' . (int) $day['id'] . '">';
				$action_cell .= '<input type="hidden" name="schedule_id" value="' . (int) $schedule['id'] . '">';
				$action_cell .= '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Cancel', 'handik-booking-app' ) . '</button>';
				$action_cell .= '</form>';
			}
			echo '<tr>';
			echo '<td>' . (int) $day['day_index'] . '</td>';
			echo '<td>' . esc_html( (string) $day['start_iso'] ) . '</td>';
			echo '<td>' . esc_html( (string) $day['end_iso'] ) . '</td>';
			echo '<td>' . esc_html( (string) $day['status'] ) . '</td>';
			echo '<td>' . $cal . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $action_cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Handle the Cancel-day form post. Nonce-checked + capability-gated.
	 * Reuses the project service's admin_cancel_day which calls Cal.com,
	 * marks the local row, and logs.
	 */
	public function maybe_cancel_day() {
		if ( ! current_user_can( Handik_Booking_App_Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		if ( empty( $_POST[ self::NONCE_FIELD_CANCEL_DAY ] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION_CANCEL_DAY, self::NONCE_FIELD_CANCEL_DAY );

		$day_id      = isset( $_POST['day_id'] ) ? absint( $_POST['day_id'] ) : 0;
		$schedule_id = isset( $_POST['schedule_id'] ) ? absint( $_POST['schedule_id'] ) : 0;
		if ( ! $day_id ) {
			return;
		}

		$result = $this->project->admin_cancel_day( $day_id, 'Cancelled from admin dashboard' );
		if ( ! empty( $result['error'] ) ) {
			add_settings_error(
				'handik_additional_forms',
				'cancel_day_failed',
				$result['error'],
				'error'
			);
		} else {
			add_settings_error(
				'handik_additional_forms',
				'cancel_day_ok',
				__( 'Work day cancelled on Cal.com.', 'handik-booking-app' ),
				'updated'
			);
		}
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * @param int $preset_id Preset ID.
	 * @return array<string, mixed>|null
	 */
	protected function find_preset_by_id( $preset_id ) {
		foreach ( $this->presets->all() as $preset ) {
			if ( (int) $preset['id'] === (int) $preset_id ) {
				return $preset;
			}
		}
		return null;
	}

	protected function preset_cal_summary( array $preset ) {
		if ( ! empty( $preset['cal_event_type_id'] ) ) {
			return 'ID: <code>' . esc_html( (string) $preset['cal_event_type_id'] ) . '</code>';
		}
		if ( ! empty( $preset['cal_event_slug'] ) ) {
			return 'slug: <code>' . esc_html( (string) $preset['cal_event_slug'] ) . '</code>';
		}
		if ( ! empty( $preset['cal_event_url'] ) ) {
			return '<a href="' . esc_url( (string) $preset['cal_event_url'] ) . '" target="_blank" rel="noreferrer noopener">URL</a>';
		}
		return '<span class="handik-admin-muted">—</span>';
	}

	protected function row_text( $name, $label, $value, $help = '' ) {
		echo '<tr><th scope="row"><label for="handik-preset-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="handik-preset-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text">';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected function row_number( $name, $label, $value, $min, $max, $help = '' ) {
		echo '<tr><th scope="row"><label for="handik-preset-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" id="handik-preset-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . (int) $min . '" max="' . (int) $max . '" step="1" class="small-text">';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected function row_checkbox( $name, $label, $checked, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $checked, true, false ) . '> ' . esc_html__( 'Yes', 'handik-booking-app' ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected function row_textarea( $name, $label, $value, $help = '' ) {
		echo '<tr><th scope="row"><label for="handik-preset-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="handik-preset-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}
}
