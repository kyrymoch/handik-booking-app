<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Upload_Service {
	/**
	 * @param array<string, mixed> $file File.
	 * @return array<string, mixed>
	 */
	public function upload_image( array $file ) {
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

		$handled = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( ! empty( $handled['error'] ) ) {
			return array( 'error' => $handled['error'], 'status' => 400 );
		}

		$attachment = array(
			'post_mime_type' => $handled['type'],
			'post_title'     => sanitize_file_name( wp_basename( $handled['file'] ) ),
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $handled['file'] );
		if ( ! is_wp_error( $attachment_id ) ) {
			$meta = wp_generate_attachment_metadata( $attachment_id, $handled['file'] );
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		return array(
			'success'       => true,
			'url'           => esc_url_raw( $handled['url'] ),
			'path'          => $handled['file'],
			'attachment_id' => ! is_wp_error( $attachment_id ) ? (int) $attachment_id : 0,
			'name'          => sanitize_file_name( wp_basename( $handled['file'] ) ),
		);
	}
}
