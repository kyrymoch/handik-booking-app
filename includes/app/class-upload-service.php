<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Upload_Service {
	/**
	 * @var Handik_Booking_App_Contacts_Service
	 */
	protected $contacts;

	/**
	 * @param Handik_Booking_App_Contacts_Service $contacts Contacts.
	 */
	public function __construct( $contacts ) {
		$this->contacts = $contacts;
	}

	/**
	 * @param array<string, mixed> $file File.
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	public function upload_image( array $file, array $context = array() ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic' );
		if ( empty( $file['tmp_name'] ) || empty( $file['type'] ) || ! in_array( $file['type'], $allowed, true ) ) {
			return array( 'error' => __( 'Only image uploads are allowed.', 'handik-booking-app' ), 'status' => 400 );
		}
		if ( ! empty( $file['size'] ) && (int) $file['size'] > 10 * MB_IN_BYTES ) {
			return array( 'error' => __( 'Images must be 10MB or smaller.', 'handik-booking-app' ), 'status' => 400 );
		}

		$upload_filter = function( $uploads ) use ( $context ) {
			$user_folder    = ! empty( $context['contact_id'] ) ? 'contact-' . absint( $context['contact_id'] ) : 'session-' . sanitize_key( (string) ( $context['app_session_key'] ?? 'guest' ) );
			$request_folder = ! empty( $context['request_id'] ) ? 'request-' . absint( $context['request_id'] ) : 'draft';
			$subdir         = '/handik-booking-app/' . $user_folder . '/' . $request_folder;

			$uploads['subdir'] = $subdir;
			$uploads['path']   = $uploads['basedir'] . $subdir;
			$uploads['url']    = $uploads['baseurl'] . $subdir;

			return $uploads;
		};
		add_filter( 'upload_dir', $upload_filter );
		$handled = wp_handle_upload( $file, array( 'test_form' => false ) );
		remove_filter( 'upload_dir', $upload_filter );
		if ( ! empty( $handled['error'] ) ) {
			return array( 'error' => $handled['error'], 'status' => 400 );
		}

		$attachment = array(
			'post_mime_type' => $handled['type'],
			'post_title'     => sanitize_file_name( wp_basename( $handled['file'] ) ),
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $handled['file'] );
		$meta          = array();
		if ( ! is_wp_error( $attachment_id ) ) {
			$meta = wp_generate_attachment_metadata( $attachment_id, $handled['file'] );
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		return array(
			'success'       => true,
			'url'           => esc_url_raw( $handled['url'] ),
			'attachment_id' => ! is_wp_error( $attachment_id ) ? (int) $attachment_id : 0,
			'name'          => sanitize_file_name( wp_basename( $handled['file'] ) ),
			'mime_type'     => sanitize_text_field( (string) $handled['type'] ),
			'filesize'      => ! empty( $file['size'] ) ? (int) $file['size'] : 0,
			'width'         => ! empty( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => ! empty( $meta['height'] ) ? (int) $meta['height'] : 0,
		);
	}
}
