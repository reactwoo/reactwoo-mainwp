<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Security {
	public static function verify_signed_request( WP_REST_Request $request, $site_id ) {
		$signature = (string) $request->get_header( 'x-rw-signature' );
		$timestamp = (string) $request->get_header( 'x-rw-timestamp' );
		$nonce     = (string) $request->get_header( 'x-rw-nonce' );

		if ( '' === $signature || '' === $timestamp || '' === $nonce ) {
			return new WP_Error( 'rw_signature_missing', 'Missing signature headers.', array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error( 'rw_signature_timestamp', 'Invalid timestamp.', array( 'status' => 401 ) );
		}

		$timestamp_int = (int) $timestamp;
		if ( abs( time() - $timestamp_int ) > 300 ) {
			return new WP_Error( 'rw_signature_skew', 'Timestamp skew too large.', array( 'status' => 401 ) );
		}

		$nonce_key = 'rw_nonce_' . (int) $site_id . '_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'rw_signature_replay', 'Nonce already used.', array( 'status' => 401 ) );
		}

		$secret = RW_Tokens::get_site_secret( $site_id );
		if ( empty( $secret ) ) {
			return new WP_Error( 'rw_signature_secret', 'Site secret unavailable.', array( 'status' => 401 ) );
		}

		$body_hash = hash( 'sha256', (string) $request->get_body() );
		$payload   = strtoupper( $request->get_method() ) . '|' . $request->get_route() . '|' . $timestamp . '|' . $nonce . '|' . $body_hash;
		$expected  = hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'rw_signature_invalid', 'Signature mismatch.', array( 'status' => 401 ) );
		}

		set_transient( $nonce_key, 1, 5 * MINUTE_IN_SECONDS );

		return true;
	}
}
