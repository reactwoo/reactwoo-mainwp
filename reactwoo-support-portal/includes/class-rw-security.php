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

		$secret = RW_Tokens::get_site_secret( $site_id );
		if ( empty( $secret ) ) {
			return new WP_Error( 'rw_signature_secret', 'Site secret unavailable.', array( 'status' => 401 ) );
		}

		$body_hash = hash( 'sha256', (string) $request->get_body() );
		$matched   = false;
		foreach ( self::candidate_paths( (string) $request->get_route() ) as $path ) {
			$payload  = strtoupper( $request->get_method() ) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $body_hash;
			$expected = hash_hmac( 'sha256', $payload, $secret );
			if ( hash_equals( $expected, $signature ) ) {
				$matched = true;
				break;
			}
		}

		if ( ! $matched ) {
			return new WP_Error( 'rw_signature_invalid', 'Signature mismatch.', array( 'status' => 401 ) );
		}

		if ( ! self::claim_nonce( $nonce_key ) ) {
			return new WP_Error( 'rw_signature_replay', 'Nonce already used.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Paths a heartbeat may have been signed with.
	 *
	 * Current connectors sign the REST route. Connectors already in the field
	 * sign the public URL path, which includes /wp-json and any site directory.
	 *
	 * @param string $route REST route from the request.
	 * @return string[]
	 */
	public static function candidate_paths( $route ) {
		$route = '/' . ltrim( (string) $route, '/' );
		$paths = array( $route, '/wp-json' . $route );

		$home      = function_exists( 'home_url' ) ? (string) home_url() : '';
		$home_path = (string) wp_parse_url( $home, PHP_URL_PATH );
		$prefix    = trim( $home_path, '/' );
		if ( '' !== $prefix ) {
			$paths[] = '/' . $prefix . '/wp-json' . $route;
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri_path = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
			$uri_path = rawurldecode( $uri_path );
			if ( '' !== $uri_path && strlen( $uri_path ) > strlen( $route ) && substr( $uri_path, -strlen( $route ) ) === $route ) {
				$extra = rtrim( substr( $uri_path, 0, -strlen( $route ) ), '/' );
				if ( '/wp-json' === $extra || ( strlen( $extra ) > strlen( '/wp-json' ) && substr( $extra, -strlen( '/wp-json' ) ) === '/wp-json' ) ) {
					$paths[] = $uri_path;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Reserve a nonce once. A failed signature does not consume it.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	private static function claim_nonce( $key ) {
		$ttl = 5 * MINUTE_IN_SECONDS;
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $key, 1, 'transient', $ttl );
		}

		$timeout = '_transient_timeout_' . $key;
		$value   = '_transient_' . $key;
		$expires = time() + $ttl;

		if ( add_option( $timeout, $expires, '', false ) ) {
			add_option( $value, 1, '', false );
			return true;
		}

		if ( (int) get_option( $timeout ) > time() ) {
			return false;
		}

		delete_option( $timeout );
		delete_option( $value );

		if ( ! add_option( $timeout, $expires, '', false ) ) {
			return false;
		}

		add_option( $value, 1, '', false );
		return true;
	}
}
