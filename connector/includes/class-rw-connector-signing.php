<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Signing {
	public static function build_headers( $method, $url, $body, $site_id, $secret ) {
		$timestamp = (string) time();
		$nonce     = self::random_nonce();
		$path      = self::extract_path( $url );

		$payload  = strtoupper( $method ) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . hash( 'sha256', (string) $body );
		$signature = hash_hmac( 'sha256', $payload, $secret );

		return array(
			'X-RW-Site-ID'   => (string) $site_id,
			'X-RW-Timestamp' => $timestamp,
			'X-RW-Nonce'     => $nonce,
			'X-RW-Signature' => $signature,
		);
	}

	public static function verify_request( WP_REST_Request $request ) {
		$site_id = (int) get_option( 'rw_connector_site_id' );
		$secret  = (string) get_option( 'rw_connector_site_secret' );

		if ( ! $site_id || '' === $secret ) {
			return new WP_Error( 'rw_connector_secret_missing', 'Connector secret unavailable.', array( 'status' => 401 ) );
		}

		$signature = (string) $request->get_header( 'x-rw-signature' );
		$timestamp = (string) $request->get_header( 'x-rw-timestamp' );
		$nonce     = (string) $request->get_header( 'x-rw-nonce' );
		$header_id = (int) $request->get_header( 'x-rw-site-id' );

		if ( '' === $signature || '' === $timestamp || '' === $nonce ) {
			return new WP_Error( 'rw_connector_signature_missing', 'Missing signature headers.', array( 'status' => 401 ) );
		}

		if ( $header_id && $header_id !== $site_id ) {
			return new WP_Error( 'rw_connector_site_mismatch', 'Site header mismatch.', array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error( 'rw_connector_timestamp', 'Invalid timestamp.', array( 'status' => 401 ) );
		}

		$timestamp_int = (int) $timestamp;
		if ( abs( time() - $timestamp_int ) > 300 ) {
			return new WP_Error( 'rw_connector_skew', 'Timestamp skew too large.', array( 'status' => 401 ) );
		}

		$nonce_key = 'rw_connector_nonce_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'rw_connector_replay', 'Nonce already used.', array( 'status' => 401 ) );
		}

		$body_hash = hash( 'sha256', (string) $request->get_body() );
		$payload   = strtoupper( $request->get_method() ) . '|' . $request->get_route() . '|' . $timestamp . '|' . $nonce . '|' . $body_hash;
		$expected  = hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'rw_connector_signature_invalid', 'Signature mismatch.', array( 'status' => 401 ) );
		}

		set_transient( $nonce_key, 1, 5 * MINUTE_IN_SECONDS );

		return true;
	}

	private static function extract_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			$path = '/';
		}

		return $path;
	}

	private static function random_nonce() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return wp_generate_password( 32, false, false );
		}
	}
}
