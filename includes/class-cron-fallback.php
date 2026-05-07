<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprint 8 — cron fallback heartbeat (P2 from the v2.1.11.1 QA report).
 *
 * Sites that set `DISABLE_WP_CRON` (a common nginx/page-cache config) lose
 * the auto-trigger that fires `wp-cron.php` on every page load. Without an
 * external scheduler, plugin events queued via `wp_schedule_single_event`
 * never fire — the photo-analysis refresh stalls, abandoned-draft GC
 * stops, and admins eventually notice via "stale data" complaints.
 *
 * This class registers a tiny `wp_loaded` heartbeat that, when WP cron is
 * disabled, walks the cron array, fires any overdue **handik_** events
 * inline, and removes them from the queue so the next run can re-schedule
 * cleanly. It deliberately scopes to handik hooks only — we don't want to
 * second-guess unrelated plugins that opted into DISABLE_WP_CRON.
 *
 * Throttle: a 60-second transient prevents the walk from running on every
 * page load. Late-arriving events still fire on the next browse.
 *
 * Async dispatch: when fastcgi_finish_request() is available (PHP-FPM),
 * we flush the response and run the events after the user's page is
 * delivered, so a 3–15s photo-analysis refresh doesn't stretch a page load.
 */
class Handik_Booking_App_Cron_Fallback {

	const HEARTBEAT_LOCK   = 'handik_cron_heartbeat_lock';
	const HEARTBEAT_WINDOW = 60; // Seconds.
	const HOOK_PREFIX      = 'handik_';

	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'heartbeat' ), 99 );
	}

	public static function heartbeat() {
		// Only meaningful when wp-cron is suppressed. Sites with normal
		// cron enabled don't need (or want) us second-guessing the queue.
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return;
		}
		// Don't run on AJAX / cron / CLI to keep request paths predictable.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// Throttle: at most one walk per HEARTBEAT_WINDOW seconds.
		if ( get_transient( self::HEARTBEAT_LOCK ) ) {
			return;
		}
		set_transient( self::HEARTBEAT_LOCK, 1, self::HEARTBEAT_WINDOW );

		$now      = time();
		$cron     = _get_cron_array();
		$pending  = array();
		if ( ! is_array( $cron ) ) {
			return;
		}
		foreach ( $cron as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				continue; // Not yet due.
			}
			foreach ( (array) $hooks as $hook => $occurrences ) {
				if ( 0 !== strpos( (string) $hook, self::HOOK_PREFIX ) ) {
					continue; // Other plugins manage their own queue.
				}
				foreach ( (array) $occurrences as $occurrence ) {
					$pending[] = array(
						'timestamp' => $timestamp,
						'hook'      => (string) $hook,
						'args'      => isset( $occurrence['args'] ) ? (array) $occurrence['args'] : array(),
					);
				}
			}
		}
		if ( empty( $pending ) ) {
			return;
		}

		// Push work past response delivery on PHP-FPM so the user's page
		// (which is what triggered us) doesn't wait.
		$dispatch = function () use ( $pending ) {
			foreach ( $pending as $event ) {
				// 2.1.15.1 audit fix (P1 #11): dispatch BEFORE unschedule.
				// The old order — unschedule then do_action — silently
				// dropped the event if a service hadn't registered its
				// listener yet (race against the wp_loaded:99 ordering)
				// because the unschedule was already committed when the
				// no-op `do_action` returned. Now we only unschedule when
				// at least one listener actually consumed the event.
				$had_listener = has_action( $event['hook'] );
				$dispatched   = false;
				try {
					do_action_ref_array( $event['hook'], $event['args'] );
					$dispatched = true;
				} catch ( \Throwable $e ) {
					if ( function_exists( 'error_log' ) ) {
						error_log( '[handik cron-fallback] ' . $event['hook'] . ' threw: ' . $e->getMessage() );
					}
				}
				// Unschedule on success OR on definite failure (had listeners
				// but they threw — same as WP cron's own behaviour). Skip
				// only the "no listeners at all" case so the event has a
				// chance to fire once the missing service comes online.
				if ( $dispatched || $had_listener ) {
					wp_unschedule_event( $event['timestamp'], $event['hook'], $event['args'] );
				}
			}
		};

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Best path: ship the response to the browser and keep PHP
			// running to do the work.
			ignore_user_abort( true );
			fastcgi_finish_request();
			$dispatch();
			return;
		}
		// Fallback: run inline. Acceptable because handik single-events
		// are typically wp_remote_post wrappers (photo refresh) that we
		// already keep under a few seconds; recurring jobs are rare.
		$dispatch();
	}
}
