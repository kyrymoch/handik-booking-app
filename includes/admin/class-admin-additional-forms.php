<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: Handik Booking → Additional Forms.
 *
 * Three tabs:
 *   1. Presets       — list of public form presets, copy shortcode/URL, toggle enabled.
 *   2. Direct        — submissions from direct-visit forms (Standard/Extended/Large).
 *   3. Project       — Project Work Days scheduling requests with day-level detail.
 */
class Handik_Booking_App_Admin_Additional_Forms {
	const PAGE_SLUG = 'handik-booking-app-additional-forms';

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
	}

	public function render() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'presets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$schedule_id = isset( $_GET['schedule_id'] ) ? absint( wp_unslash( $_GET['schedule_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap handik-admin">';
		echo '<h1>' . esc_html__( 'Additional Forms', 'handik-booking-app' ) . '</h1>';
		$this->render_tabs( $tab );

		if ( 'project' === $tab && $schedule_id > 0 ) {
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

	// ---------- presets ---------------------------------------------------

	protected function render_presets_list() {
		$presets = $this->presets->all();
		echo '<p class="description">' . esc_html__( 'These are the public booking-form presets shipped with the plugin. Each has a shortcode and a public URL. Direct visit presets use the Cal.com iframe; project work days presets use the Cal.com API.', 'handik-booking-app' ) . '</p>';
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration / Days', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Embed', 'handik-booking-app' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $presets as $preset ) {
			$slug      = (string) $preset['preset_slug'];
			$shortcode = '[handik_booking_form preset="' . $slug . '"]';
			$public    = home_url( '/booking/' . $slug . '/' );
			$type      = (string) $preset['form_type'];
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
			echo '<tr>';
			echo '<td>' . esc_html( (string) $preset['form_title'] ) . '</td>';
			echo '<td><code>' . esc_html( $slug ) . '</code></td>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . $detail . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped (already escaped above)
			echo '<td>' . ( ! empty( $preset['enabled'] ) ? '<span class="dashicons dashicons-yes-alt"></span>' : '—' ) . '</td>';
			echo '<td>';
			echo '<code>' . esc_html( $shortcode ) . '</code><br>';
			echo '<a href="' . esc_url( $public ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $public ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	// ---------- direct list -----------------------------------------------

	protected function render_direct_list() {
		$rows = $this->direct->list_recent( 100 );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No direct booking submissions yet.', 'handik-booking-app' ) . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Client', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Preset', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th>';
		echo '<th>' . esc_html__( 'Cal booking', 'handik-booking-app' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$contact   = $this->contacts->get( (int) $row['contact_id'] );
			$cal_link  = '';
			if ( ! empty( $row['cal_booking_uid'] ) ) {
				$cal_link = '<code>' . esc_html( (string) $row['cal_booking_uid'] ) . '</code>';
			} elseif ( ! empty( $row['cal_booking_id'] ) ) {
				$cal_link = '<code>' . esc_html( (string) $row['cal_booking_id'] ) . '</code>';
			} elseif ( ! empty( $row['cal_booking_url'] ) ) {
				$cal_link = '<a href="' . esc_url( (string) $row['cal_booking_url'] ) . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Open Cal URL', 'handik-booking-app' ) . '</a>';
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['full_name'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $contact['phone'] ?? '—' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['preset_slug'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['duration_minutes'] ) . ' min</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . $cal_link . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	// ---------- project list ----------------------------------------------

	protected function render_project_list() {
		$rows = $this->project->list_recent( 100 );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No project scheduling requests yet.', 'handik-booking-app' ) . '</p>';
			return;
		}
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
			$contact = $this->contacts->get( (int) $row['contact_id'] );
			$detail  = add_query_arg(
				array(
					'tab'         => 'project',
					'schedule_id' => (int) $row['id'],
				),
				$base
			);
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
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

		echo '<h2>' . esc_html__( 'Project schedule', 'handik-booking-app' ) . ' #' . (int) $schedule['id'] . '</h2>';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th><td>' . esc_html( (string) $schedule['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Client', 'handik-booking-app' ) . '</th><td>' . esc_html( (string) ( $contact['full_name'] ?? '—' ) ) . '<br>' . esc_html( (string) ( $contact['phone'] ?? '' ) ) . ' · ' . esc_html( (string) ( $contact['email'] ?? '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Preset', 'handik-booking-app' ) . '</th><td><code>' . esc_html( (string) $schedule['preset_slug'] ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Days requested', 'handik-booking-app' ) . '</th><td>' . esc_html( (string) $schedule['required_days'] ) . ' × ' . esc_html( (string) $schedule['work_day_duration_minutes'] ) . ' min</td></tr>';
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
		echo '<thead><tr><th>#</th><th>' . esc_html__( 'Start', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'End', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Status', 'handik-booking-app' ) . '</th><th>' . esc_html__( 'Cal booking', 'handik-booking-app' ) . '</th></tr></thead><tbody>';
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
			echo '<tr>';
			echo '<td>' . (int) $day['day_index'] . '</td>';
			echo '<td>' . esc_html( (string) $day['start_iso'] ) . '</td>';
			echo '<td>' . esc_html( (string) $day['end_iso'] ) . '</td>';
			echo '<td>' . esc_html( (string) $day['status'] ) . '</td>';
			echo '<td>' . $cal . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
