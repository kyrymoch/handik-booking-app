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
		return $this->upload_media( $file, $context );
	}

	/**
	 * @param array<string, mixed> $file File.
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	public function upload_media( array $file, array $context = array() ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$allowed_images = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif' );
		$allowed_videos = array( 'video/mp4', 'video/quicktime', 'video/webm' );
		$mime_type      = ! empty( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '';
		$is_image       = in_array( $mime_type, $allowed_images, true );
		$is_video       = in_array( $mime_type, $allowed_videos, true );

		if ( empty( $file['tmp_name'] ) || ! $mime_type || ( ! $is_image && ! $is_video ) ) {
			return array( 'error' => __( 'Only photo and short video uploads are allowed.', 'handik-booking-app' ), 'status' => 400 );
		}

		if ( $is_image && ! empty( $file['size'] ) && (int) $file['size'] > 10 * MB_IN_BYTES ) {
			return array( 'error' => __( 'Images must be 10MB or smaller.', 'handik-booking-app' ), 'status' => 400 );
		}
		if ( $is_video && ! empty( $file['size'] ) && (int) $file['size'] > 50 * MB_IN_BYTES ) {
			return array( 'error' => __( 'Videos must be 50MB or smaller.', 'handik-booking-app' ), 'status' => 400 );
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
		$handled = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
					'heic|heif'    => 'image/heic',
					'mp4|m4v'      => 'video/mp4',
					'mov|qt'       => 'video/quicktime',
					'webm'         => 'video/webm',
				),
			)
		);
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
		if ( ! is_wp_error( $attachment_id ) && $is_image ) {
			$meta = wp_generate_attachment_metadata( $attachment_id, $handled['file'] );
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		return array(
			'success'       => true,
			'url'           => esc_url_raw( $handled['url'] ),
			'attachment_id' => ! is_wp_error( $attachment_id ) ? (int) $attachment_id : 0,
			'name'          => sanitize_file_name( wp_basename( $handled['file'] ) ),
			'mime_type'     => sanitize_text_field( (string) $handled['type'] ),
			'media_type'    => $is_video ? 'video' : 'image',
			'type'          => $is_video ? 'video' : 'image',
			'filesize'      => ! empty( $file['size'] ) ? (int) $file['size'] : 0,
			'width'         => ! empty( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => ! empty( $meta['height'] ) ? (int) $meta['height'] : 0,
		);
	}
}
