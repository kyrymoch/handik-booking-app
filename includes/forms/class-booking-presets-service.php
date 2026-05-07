<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of public booking-form presets (Direct Visit + Project Work Days).
 *
 * Defaults are seeded from a hardcoded list the first time the form_presets
 * table is empty. Admin can override per-row in the database, but defaults
 * stay shipped with the plugin so a fresh install just works.
 */
class Handik_Booking_App_Booking_Presets_Service {
	const FORM_TYPE_DIRECT  = 'direct_cal_booking';
	const FORM_TYPE_PROJECT = 'project_work_days';

	const DEFAULT_PROJECT_DAY_MINUTES = 480;
	const DEFAULT_PROJECT_START_TIME  = '09:00';

	/** @var Handik_Booking_App_Logger|null */
	protected $logger;

	/** @var array<string, array<string, mixed>>|null */
	protected $cache = null;

	public function __construct( $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * @return array<string, array<string, mixed>> Keyed by preset_slug.
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_presets' );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			// Table not migrated yet — return defaults so admin pages don't 500.
			$this->cache = $this->defaults_indexed();
			return $this->cache;
		}

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY form_type, duration_minutes, required_days, id",
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			$this->seed_defaults();
			$rows = $wpdb->get_results(
				"SELECT * FROM {$table} ORDER BY form_type, duration_minutes, required_days, id",
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$slug = (string) ( $row['preset_slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = $this->hydrate_row( $row );
		}

		$this->cache = $out;
		return $out;
	}

	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * @param string $slug Preset slug.
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}
		$all = $this->all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function enabled() {
		$out = array();
		foreach ( $this->all() as $slug => $row ) {
			if ( ! empty( $row['enabled'] ) ) {
				$out[ $slug ] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param int                  $id      Preset ID.
	 * @param array<string, mixed> $payload Patch.
	 * @return bool
	 */
	public function update( $id, array $payload ) {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_presets' );

		$allowed = array(
			'form_title'                => 'sanitize_text_field',
			'cal_event_url'             => 'esc_url_raw',
			'cal_event_type_id'         => 'sanitize_text_field',
			'cal_event_slug'            => 'sanitize_title',
			'allowed_start_time'        => 'sanitize_text_field',
			'allowed_weekdays'          => 'sanitize_text_field',
			'confirmation_mode'         => 'sanitize_key',
			'duration_minutes'          => 'absint',
			'required_days'             => 'absint',
			'work_day_duration_minutes' => 'absint',
			'enabled'                   => 'absint',
			'admin_notes'               => 'sanitize_textarea_field',
		);
		$data = array();
		foreach ( $allowed as $key => $sanitizer ) {
			if ( array_key_exists( $key, $payload ) ) {
				$value = $payload[ $key ];
				if ( 'enabled' === $key ) {
					$data[ $key ] = ! empty( $value ) ? 1 : 0;
				} else {
					$data[ $key ] = call_user_func( $sanitizer, $value );
				}
			}
		}
		if ( empty( $data ) ) {
			return false;
		}
		$wpdb->update( $table, $data, array( 'id' => (int) $id ) );
		$this->flush_cache();
		return true;
	}

	// ---------- defaults --------------------------------------------------

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function defaults() {
		return array(
			$this->direct_default( 'standard-visit-60', 'Standard Visit — 1 hour', 'standard_visit', 60 ),
			$this->direct_default( 'standard-visit-120', 'Standard Visit — 2 hours', 'standard_visit', 120 ),
			$this->direct_default( 'extended-visit-180', 'Extended Visit — 3 hours', 'extended_visit', 180 ),
			$this->direct_default( 'extended-visit-240', 'Extended Visit — 4 hours', 'extended_visit', 240 ),
			$this->direct_default( 'extended-visit-300', 'Extended Visit — 5 hours', 'extended_visit', 300 ),
			$this->direct_default( 'large-visit-360', 'Large Visit — 6 hours', 'large_visit', 360 ),
			$this->direct_default( 'large-visit-420', 'Large Visit — 7 hours', 'large_visit', 420 ),
			$this->direct_default( 'large-visit-480', 'Large Visit — 8 hours', 'large_visit', 480 ),
			$this->project_default( 'larger-scale-work-2', 'Project Work — 2 days', 2 ),
			$this->project_default( 'larger-scale-work-3', 'Project Work — 3 days', 3 ),
			$this->project_default( 'larger-scale-work-4', 'Project Work — 4 days', 4 ),
			$this->project_default( 'larger-scale-work-5', 'Project Work — 5 days', 5 ),
			$this->project_default( 'larger-scale-work-6', 'Project Work — 6 days', 6 ),
		);
	}

	protected function defaults_indexed() {
		$out = array();
		foreach ( $this->defaults() as $row ) {
			$out[ $row['preset_slug'] ] = $this->hydrate_row( $row );
		}
		return $out;
	}

	protected function direct_default( $slug, $title, $booking_type, $duration_minutes ) {
		return array(
			'preset_slug'                => $slug,
			'form_title'                 => $title,
			'form_type'                  => self::FORM_TYPE_DIRECT,
			'booking_type'               => $booking_type,
			'duration_minutes'           => (int) $duration_minutes,
			'required_days'              => 0,
			'work_day_duration_minutes'  => 0,
			'cal_event_url'              => '',
			'cal_event_type_id'          => '',
			'cal_event_slug'             => '',
			'allowed_start_time'         => '',
			'allowed_weekdays'           => '',
			'confirmation_mode'          => 'pending_alex_confirmation',
			'enabled'                    => 1,
			'is_default'                 => 1,
			'admin_notes'                => '',
		);
	}

	protected function project_default( $slug, $title, $required_days ) {
		return array(
			'preset_slug'                => $slug,
			'form_title'                 => $title,
			'form_type'                  => self::FORM_TYPE_PROJECT,
			'booking_type'               => 'project_consultation',
			'duration_minutes'           => 0,
			'required_days'              => (int) $required_days,
			'work_day_duration_minutes'  => self::DEFAULT_PROJECT_DAY_MINUTES,
			'cal_event_url'              => '',
			'cal_event_type_id'          => '',
			'cal_event_slug'             => '',
			'allowed_start_time'         => self::DEFAULT_PROJECT_START_TIME,
			'allowed_weekdays'           => '',
			'confirmation_mode'          => 'pending_alex_confirmation',
			'enabled'                    => 1,
			'is_default'                 => 1,
			'admin_notes'                => '',
		);
	}

	/**
	 * Seed the 13 default presets the first time the table is empty.
	 *
	 * Race-safe: two concurrent requests that both observed an empty table
	 * will both call this. `preset_slug` carries a UNIQUE index (migration
	 * 1.4.0 line 60 — `UNIQUE KEY preset_slug_unique`), so the loser of the
	 * race used to take a `WP_DB_Error` on the duplicate insert and bail
	 * mid-way through, leaving a partially-seeded table. We pre-flight each
	 * row with a SELECT and only insert if it's not already there. The
	 * UNIQUE index still backs us up at the storage layer.
	 */
	protected function seed_defaults() {
		global $wpdb;
		$table = Handik_Booking_App_DB::table( 'form_presets' );
		foreach ( $this->defaults() as $row ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE preset_slug = %s",
					(string) $row['preset_slug']
				)
			);
			if ( $exists > 0 ) {
				continue;
			}
			$wpdb->insert( $table, $row );
		}
	}

	protected function hydrate_row( array $row ) {
		$out = $row;
		$out['enabled']                   = ! empty( $row['enabled'] );
		$out['is_default']                = ! empty( $row['is_default'] );
		$out['duration_minutes']          = (int) ( $row['duration_minutes'] ?? 0 );
		$out['required_days']             = (int) ( $row['required_days'] ?? 0 );
		$out['work_day_duration_minutes'] = (int) ( $row['work_day_duration_minutes'] ?? 0 );
		return $out;
	}
}
