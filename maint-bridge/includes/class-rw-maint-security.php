<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Security {
	public static function verify_signed_request( WP_REST_Request $request ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return null;
		}

		$signature = (string) $request->get_header( 'x-rw-signature' );
		$timestamp = (string) $request->get_header( 'x-rw-timestamp' );
		$nonce     = (string) $request->get_header( 'x-rw-nonce' );
		$path      = (string) $request->get_header( 'x-rw-path' );

		if ( '' === $signature || '' === $timestamp || '' === $nonce ) {
			return new WP_Error( 'rw_maint_signature_missing', 'Missing signature headers.', array( 'status' => 401 ) );
		}

		if ( '' === $path ) {
			$path = (string) $request->get_route();
		} elseif ( $path !== (string) $request->get_route() ) {
			return new WP_Error( 'rw_maint_path_mismatch', 'Signature path mismatch.', array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error( 'rw_maint_timestamp', 'Invalid timestamp.', array( 'status' => 401 ) );
		}

		$timestamp_int = (int) $timestamp;
		if ( abs( time() - $timestamp_int ) > 300 ) {
			return new WP_Error( 'rw_maint_skew', 'Timestamp skew too large.', array( 'status' => 401 ) );
		}

		$nonce_key = 'rw_maint_nonce_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'rw_maint_replay', 'Nonce already used.', array( 'status' => 401 ) );
		}

		$body_hash = hash( 'sha256', (string) $request->get_body() );
		$payload   = strtoupper( $request->get_method() ) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $body_hash;
		$expected  = hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'rw_maint_signature_invalid', 'Signature mismatch.', array( 'status' => 401 ) );
		}

		set_transient( $nonce_key, 1, 5 * MINUTE_IN_SECONDS );

		return true;
	}

	private static function get_secret() {
		$secret = (string) get_option( 'rw_maint_portal_secret', '' );

		return (string) apply_filters( 'rw_maint_portal_secret', $secret );
	}
}
