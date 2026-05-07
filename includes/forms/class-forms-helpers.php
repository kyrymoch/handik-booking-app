<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared utility helpers for the Additional Booking Forms module.
 *
 * Existed mostly as duplicated private methods on Direct_Booking_Service,
 * Project_Schedule_Service and Forms_Rest_Api before this — different copies
 * had drifted slightly (one Cloudflare-aware, one not; two slightly different
 * Cal URL builders). Centralized here so all three flows agree on:
 *
 *   - the IP-detection chain (Cf-Connecting-Ip → X-Forwarded-For → REMOTE_ADDR),
 *   - the RFC-3986 query encoding the Cal.com iframe expects.
 *
 * Pure static methods; no state.
 */
class Handik_Booking_App_Forms_Helpers {

	/**
	 * Detect the customer's IP, honouring CDN headers.
	 *
	 * Order:
	 *   1. Cf-Connecting-Ip   (Cloudflare).
	 *   2. X-Forwarded-For    (generic proxy).
	 *   3. REMOTE_ADDR        (direct).
	 *
	 * Returns 'unknown' rather than '' when nothing is set, so per-IP rate
	 * limit buckets still hash deterministically.
	 *
	 * @return string IP string (or 'unknown').
	 */
	public static function client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$candidate = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			$candidate = trim( explode( ',', $candidate )[0] );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return 'unknown';
	}

	/**
	 * Detected IP packed for VARBINARY(16) storage. Returns null when the
	 * detected IP doesn't parse (e.g. literal 'unknown').
	 *
	 * @return string|null
	 */
	public static function client_ip_packed() {
		$ip = self::client_ip();
		if ( 'unknown' === $ip || '' === $ip ) {
			return null;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $packed ?: null;
	}

	/**
	 * Build a Cal.com booking URL with RFC-3986-encoded query parameters,
	 * preserving any base path / fragment already on the event URL.
	 *
	 * Used by the direct flow to build the Cal iframe URL. Matches the
	 * encoding the main app's Cal_Service::build_encoded_url uses, so a
	 * direct-form booking has the same query shape as an AI-flow booking.
	 *
	 * @param string                $base   Base event URL (may contain query / fragment).
	 * @param array<string, string> $params Query params to merge in.
	 * @return string
	 */
	public static function build_encoded_url( $base, array $params ) {
		$base     = (string) $base;
		$fragment = '';
		$hash_pos = strpos( $base, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $base, $hash_pos );
			$base     = substr( $base, 0, $hash_pos );
		}

		$query_args = array();
		$query_pos  = strpos( $base, '?' );
		if ( false !== $query_pos ) {
			$query = substr( $base, $query_pos + 1 );
			$base  = substr( $base, 0, $query_pos );
			if ( '' !== $query ) {
				wp_parse_str( $query, $query_args );
			}
		}

		foreach ( $params as $key => $value ) {
			$query_args[ $key ] = (string) $value;
		}

		$query = http_build_query( $query_args, '', '&', PHP_QUERY_RFC1738 );
		return $base . ( '' !== $query ? '?' . $query : '' ) . $fragment;
	}
}
