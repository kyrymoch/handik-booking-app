<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprint 12 — destructive admin cascades.
 *
 * No table in the plugin uses `FOREIGN KEY` constraints, so every parent
 * delete needs to walk its children explicitly to avoid orphans. This
 * coordinator wires every dependent service together so the individual
 * data layers stay simple (each one has a tiny `delete_hard_solo` /
 * `delete_for_request` / `delete_hard_for_contact` method) and ALL the
 * order-of-operations logic lives here.
 *
 * Three public entry points:
 *
 *   delete_booking($booking_id)   — leaf. Drops the row, clears the
 *                                   parent request's denormalized
 *                                   `cal_booking_id` back-pointer.
 *
 *   delete_request($request_id)   — Drops messages → bookings →
 *                                   wp_delete_attachment() per photo →
 *                                   the request row itself.
 *
 *   delete_contact($contact_id)   — Recurses through every child request
 *                                   (so the request-level photo cleanup
 *                                   still runs), then wipes addresses,
 *                                   login_tokens, direct_booking_requests,
 *                                   project_scheduling_requests +
 *                                   project_work_days, and finally the
 *                                   contact row.
 *
 * Each method:
 *   1. Logs a `warning` BEFORE the cascade (so the audit trail survives
 *      a fatal mid-cascade — the wp_options-backed Logger flushes
 *      synchronously for warning-level entries).
 *   2. Walks dependents in safe-deletion order (children before parents,
 *      back-pointers cleared before the row they reference is dropped).
 *   3. Returns a `[ deleted: bool, summary: array<string,int> ]` shape
 *      so the REST handler can include the counts in the response.
 *
 * Cal.com bookings on the contractor's calendar are intentionally left
 * untouched — owner-decided scope for Sprint 12 ("the visit happened;
 * we're cleaning local DB"). To toggle that later, call
 * Handik_Booking_App_Cal_API_Service::cancel_booking($cal_uid) right
 * after step 1 of delete_booking().
 */
class Handik_Booking_App_Cascade_Delete_Service {

	/** @var Handik_Booking_App_Contacts_Service */
	protected $contacts;
	/** @var Handik_Booking_App_Addresses_Service */
	protected $addresses;
	/** @var Handik_Booking_App_Job_Requests_Service */
	protected $job_requests;
	/** @var Handik_Booking_App_Bookings_Service */
	protected $bookings;
	/** @var Handik_Booking_App_Messages_Service */
	protected $messages;
	/** @var Handik_Booking_App_Logger */
	protected $logger;

	public function __construct( $contacts, $addresses, $job_requests, $bookings, $messages, $logger ) {
		$this->contacts     = $contacts;
		$this->addresses    = $addresses;
		$this->job_requests = $job_requests;
		$this->bookings     = $bookings;
		$this->messages     = $messages;
		$this->logger       = $logger;
	}

	/**
	 * Hard-delete a booking. Clears the parent request's denormalized
	 * `cal_booking_id` / `cal_booking_url` back-pointer if THIS booking
	 * was the authoritative one, then drops the row.
	 *
	 * @param int $booking_id ID.
	 * @return array{deleted: bool, summary: array<string, int>}
	 */
	public function delete_booking( $booking_id ) {
		$booking_id = (int) $booking_id;
		$row = $this->bookings ? $this->bookings->get( $booking_id ) : null;
		if ( ! $row ) {
			return array( 'deleted' => false, 'summary' => array() );
		}
		$this->log_warning( 'admin hard-delete: booking', array(
			'booking_id'     => $booking_id,
			'cal_booking_id' => (string) ( $row['cal_booking_id'] ?? '' ),
			'request_id'     => (int) ( $row['job_request_id'] ?? 0 ),
		) );

		$ok = $this->bookings && method_exists( $this->bookings, 'delete_hard' )
			? $this->bookings->delete_hard( $booking_id )
			: false;

		return array(
			'deleted' => $ok,
			'summary' => array( 'bookings' => $ok ? 1 : 0 ),
		);
	}

	/**
	 * Hard-delete a job_request and ALL of its dependents (messages,
	 * bookings, photos on disk + in WP media). Used both as a top-level
	 * entry point (admin clicks "Delete this request") and as a step
	 * inside delete_contact().
	 *
	 * @param int $request_id ID.
	 * @return array{deleted: bool, summary: array<string, int>}
	 */
	public function delete_request( $request_id ) {
		global $wpdb;
		$request_id = (int) $request_id;
		$row = $this->job_requests ? $this->job_requests->get( $request_id ) : null;
		if ( ! $row ) {
			return array( 'deleted' => false, 'summary' => array() );
		}

		$photos = is_array( $row['photos'] ?? null ) ? $row['photos'] : array();
		$bookings_table = Handik_Booking_App_DB::table( 'bookings' );
		$booking_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$bookings_table} WHERE job_request_id = %d", $request_id ) );

		$this->log_warning( 'admin hard-delete: request', array(
			'request_id' => $request_id,
			'contact_id' => (int) ( $row['contact_id'] ?? 0 ),
			'photo_count'    => count( $photos ),
			'booking_count'  => count( (array) $booking_ids ),
		) );

		$msg_count = $this->messages && method_exists( $this->messages, 'delete_for_request' )
			? $this->messages->delete_for_request( $request_id )
			: 0;

		// Drop bookings rows directly (avoid recursing through
		// delete_booking — we don't need the per-row cal back-pointer
		// clear since we're dropping the parent request anyway).
		$booking_count = 0;
		if ( ! empty( $booking_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
			$booking_count = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$bookings_table} WHERE id IN ({$placeholders})", $booking_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$photos_deleted = 0;
		foreach ( $photos as $photo ) {
			$attachment_id = (int) ( $photo['attachment_id'] ?? 0 );
			if ( $attachment_id > 0 && function_exists( 'wp_delete_attachment' ) ) {
				$result = wp_delete_attachment( $attachment_id, true );
				if ( false !== $result ) {
					$photos_deleted++;
				}
			}
		}

		$this->cleanup_request_upload_dir( (int) ( $row['contact_id'] ?? 0 ), $request_id );

		$ok = $this->job_requests && method_exists( $this->job_requests, 'delete_hard_solo' )
			? $this->job_requests->delete_hard_solo( $request_id )
			: false;

		return array(
			'deleted' => $ok,
			'summary' => array(
				'job_requests' => $ok ? 1 : 0,
				'messages'     => $msg_count,
				'bookings'     => $booking_count,
				'photos'       => $photos_deleted,
			),
		);
	}

	/**
	 * Hard-delete a contact and EVERYTHING it ever touched. Designed
	 * for "right-to-be-forgotten" / spam cleanup — irreversible.
	 *
	 * @param int $contact_id ID.
	 * @return array{deleted: bool, summary: array<string, int>}
	 */
	public function delete_contact( $contact_id ) {
		global $wpdb;
		$contact_id = (int) $contact_id;
		$row = $this->contacts ? $this->contacts->get( $contact_id ) : null;
		if ( ! $row ) {
			return array( 'deleted' => false, 'summary' => array() );
		}

		$counts = $this->contacts && method_exists( $this->contacts, 'count_dependents' )
			? $this->contacts->count_dependents( $contact_id )
			: array();
		$this->log_warning( 'admin hard-delete: contact', array(
			'contact_id' => $contact_id,
			'phone'      => (string) ( $row['phone'] ?? '' ),
			'counts'     => $counts,
		) );

		$summary = array(
			'contacts'                    => 0,
			'addresses'                   => 0,
			'job_requests'                => 0,
			'bookings'                    => 0,
			'messages'                    => 0,
			'photos'                      => 0,
			'login_tokens'                => 0,
			'direct_booking_requests'     => 0,
			'project_scheduling_requests' => 0,
			'project_work_days'           => 0,
		);

		// Recurse into every owned request — that path handles
		// messages + bookings + photos for us.
		$requests_table = Handik_Booking_App_DB::table( 'job_requests' );
		$request_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$requests_table} WHERE contact_id = %d", $contact_id ) );
		foreach ( (array) $request_ids as $rid ) {
			$res = $this->delete_request( (int) $rid );
			$summary['job_requests'] += (int) ( $res['summary']['job_requests'] ?? 0 );
			$summary['messages']     += (int) ( $res['summary']['messages'] ?? 0 );
			$summary['bookings']     += (int) ( $res['summary']['bookings'] ?? 0 );
			$summary['photos']       += (int) ( $res['summary']['photos'] ?? 0 );
		}

		// Login tokens (HMAC-signed magic-link / OTP-restore tokens).
		$login_tokens = Handik_Booking_App_DB::table( 'login_tokens' );
		$summary['login_tokens'] = (int) $wpdb->delete(
			$login_tokens, array( 'contact_id' => $contact_id ), array( '%d' )
		);

		// Additional Forms — direct booking + project scheduling.
		$direct = Handik_Booking_App_DB::table( 'direct_booking_requests' );
		$summary['direct_booking_requests'] = (int) $wpdb->delete(
			$direct, array( 'contact_id' => $contact_id ), array( '%d' )
		);

		// Project work days hang off project_scheduling_requests.id —
		// pull the parent ids first so we can join-delete the children
		// before dropping the parents.
		$project = Handik_Booking_App_DB::table( 'project_scheduling_requests' );
		$work_days = Handik_Booking_App_DB::table( 'project_work_days' );
		$project_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$project} WHERE contact_id = %d",
			$contact_id
		) );
		if ( ! empty( $project_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
			$summary['project_work_days'] = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$work_days} WHERE scheduling_request_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$project_ids
			) );
			$summary['project_scheduling_requests'] = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$project} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$project_ids
			) );
		}

		// Addresses. delete_hard_for_contact wipes both soft-deleted
		// and active rows.
		$summary['addresses'] = $this->addresses && method_exists( $this->addresses, 'delete_hard_for_contact' )
			? $this->addresses->delete_hard_for_contact( $contact_id )
			: 0;

		// rmdir the contact's upload subtree — request-level cleanup
		// already nuked the request-N/ subdirs; this drops the
		// now-empty contact-N/ wrapper.
		$this->cleanup_contact_upload_dir( $contact_id );

		// Finally, the row itself.
		$ok = $this->contacts && method_exists( $this->contacts, 'delete_hard_solo' )
			? $this->contacts->delete_hard_solo( $contact_id )
			: false;
		$summary['contacts'] = $ok ? 1 : 0;

		return array( 'deleted' => $ok, 'summary' => $summary );
	}

	protected function log_warning( $message, array $context ) {
		if ( $this->logger && method_exists( $this->logger, 'warning' ) ) {
			$context['actor'] = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			$this->logger->warning( $message, $context );
		}
	}

	/**
	 * Best-effort rmdir for `wp-content/uploads/handik-booking-app/contact-{c}/request-{r}/`.
	 * Files inside have already been removed by `wp_delete_attachment`,
	 * so the directory is expected to be empty. Silently no-op on
	 * non-empty / missing dirs.
	 */
	protected function cleanup_request_upload_dir( $contact_id, $request_id ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$contact_id = (int) $contact_id;
		$request_id = (int) $request_id;
		if ( $contact_id <= 0 || $request_id <= 0 ) {
			return;
		}
		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return;
		}
		$path = trailingslashit( $uploads['basedir'] )
			. 'handik-booking-app/contact-' . $contact_id . '/request-' . $request_id;
		if ( is_dir( $path ) ) {
			@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	protected function cleanup_contact_upload_dir( $contact_id ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$contact_id = (int) $contact_id;
		if ( $contact_id <= 0 ) {
			return;
		}
		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return;
		}
		$path = trailingslashit( $uploads['basedir'] )
			. 'handik-booking-app/contact-' . $contact_id;
		if ( is_dir( $path ) ) {
			@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
