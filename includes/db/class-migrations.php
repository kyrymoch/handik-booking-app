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
		'1.5.0' => 'Handik_Booking_App_Migration_150',
		'1.5.1' => 'Handik_Booking_App_Migration_151',
		'1.5.2' => 'Handik_Booking_App_Migration_152',
		'1.6.0' => 'Handik_Booking_App_Migration_160',
		'1.6.1' => 'Handik_Booking_App_Migration_161',
		'1.6.2' => 'Handik_Booking_App_Migration_162',
		'1.6.3' => 'Handik_Booking_App_Migration_163',
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
		// 2.1.15.1 audit fix (P2 #1): atomic SETNX via `add_option`.
		// The previous version was a classic TOCTOU — get_option then
		// update_option — which two near-simultaneous boots could both
		// pass, defeating the parallel-run protection the lock was meant
		// to add. `add_option` is enforced by the wp_options
		// `option_name` UNIQUE index at the DB level, so exactly one
		// caller wins the insert.
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}
		// Insert lost — someone holds the lock. Recover if the existing
		// row is older than 60s (assumed crashed/timed-out caller).
		// This recovery branch isn't atomic, but the worst case is the
		// SAME race the activation path now prevents — rare and bounded.
		$existing = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $existing && ( $now - $existing ) >= 60 ) {
			update_option( self::LOCK_OPTION, $now, false );
			return true;
		}
		return false;
	}

	protected function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
