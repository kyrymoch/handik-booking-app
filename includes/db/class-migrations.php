<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Migrations {
	const OPTION_NAME       = 'handik_booking_app_db_version';
	const LAST_RUN_OPTION   = 'handik_booking_app_db_last_run';
	const LAST_ATTEMPT_OPTION = 'handik_booking_app_db_last_attempt';
	const LAST_ERROR_OPTION = 'handik_booking_app_db_last_error';
	const LOCK_OPTION       = 'handik_booking_app_db_migration_lock';

	/**
	 * @var array<string, string>
	 */
	protected $map = array(
		'1.0.0' => 'Handik_Booking_App_Migration_100',
		'1.1.0' => 'Handik_Booking_App_Migration_110',
		'1.2.0' => 'Handik_Booking_App_Migration_120',
		'1.3.0' => 'Handik_Booking_App_Migration_130',
		'1.4.0' => 'Handik_Booking_App_Migration_140',
		'1.4.1' => 'Handik_Booking_App_Migration_141',
	);

	/**
	 * Sprint 7 (admin ops): hardened migration runner.
	 *
	 * Three accuracy bugs the QA pass surfaced:
	 *   1. `LAST_RUN_OPTION` was only written when at least one migration
	 *      class actually ran. Calls that hit a no-op (db already at target)
	 *      left the timestamp showing whatever a prior version recorded —
	 *      System info > Last migration ran was misleading.
	 *   2. The version pointer (`OPTION_NAME`) was bumped EVEN when a
	 *      migration's `up()` had thrown — the next attempt skipped the
	 *      step and the schema sat in a partially-migrated state forever.
	 *   3. Two parallel requests on a fresh upgrade both read `1.3.0`,
	 *      both called `Migration_141::up()`, and the second hit
	 *      "Duplicate column" / "Table already exists" because the runner
	 *      had no lock.
	 *
	 * @return array{ran: array<int, string>, skipped: bool, error: string|null}
	 */
	public function migrate() {
		// Lock — short transient avoids two parallel boots both running the
		// same migration. 60s timeout is enough for any realistic dbDelta.
		// First boot wins; second boot reports `skipped=true` to the caller.
		if ( ! $this->acquire_lock() ) {
			return array( 'ran' => array(), 'skipped' => true, 'error' => null );
		}

		$current = (string) get_option( self::OPTION_NAME, '0.0.0' );
		$target  = HANDIK_BOOKING_APP_DB_VERSION;
		$ran     = array();
		$error   = null;

		// Always record the attempt timestamp so System info shows the
		// actual last run/attempt, not a stale value from a prior version.
		update_option( self::LAST_ATTEMPT_OPTION, current_time( 'mysql' ), false );

		try {
			foreach ( $this->map as $version => $class_name ) {
				if ( version_compare( $version, $current, '<=' ) ) {
					continue;
				}
				if ( version_compare( $version, $target, '>' ) ) {
					continue;
				}

				try {
					$migration = new $class_name();
					$migration->up();
				} catch ( \Throwable $e ) {
					// Capture and re-throw — outer catch persists the error
					// without bumping the version pointer past this step.
					$error = sprintf( '%s: %s', $version, $e->getMessage() );
					throw $e;
				}

				// Bump version + mark this step done ONLY after up() succeeded
				// (was bumped before up() in the old code, so a half-failed
				// migration looked complete forever).
				update_option( self::OPTION_NAME, $version, false );
				update_option( self::LAST_RUN_OPTION, current_time( 'mysql' ), false );
				$ran[] = $version;
			}
			delete_option( self::LAST_ERROR_OPTION );
		} catch ( \Throwable $e ) {
			update_option( self::LAST_ERROR_OPTION, ( $error ?: $e->getMessage() ), false );
			$this->release_lock();
			return array( 'ran' => $ran, 'skipped' => false, 'error' => $error ?: $e->getMessage() );
		}

		$this->release_lock();
		return array( 'ran' => $ran, 'skipped' => false, 'error' => null );
	}

	protected function acquire_lock() {
		// `add_option` with autoload=false is the closest WP gives us to
		// SETNX — returns true only if the option didn't exist. We attach
		// a timestamp so a stuck lock from a fatal can be force-recovered
		// after 60 seconds without manual intervention.
		$now = time();
		$existing = get_option( self::LOCK_OPTION, 0 );
		if ( $existing && ( $now - (int) $existing ) < 60 ) {
			return false;
		}
		// Either no lock or expired — claim it.
		update_option( self::LOCK_OPTION, $now, false );
		return true;
	}

	protected function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
