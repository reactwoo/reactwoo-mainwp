<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Client {
	const DEFAULT_TIMEOUT = 20;
	const ENROLL_TIMEOUT  = 180;

	public static function enroll_site( $site, array $extra = array(), $idempotency_key = '' ) {
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_missing_site', 'Managed site is required.' );
		}

		$payload = array(
			'portal_site_id'  => (int) $site->id,
			'subscription_id' => (int) $site->subscription_id,
			'user_id'         => (int) $site->user_id,
			'site_url'        => $site->site_url,
			'site_name'       => isset( $site->site_name ) ? $site->site_name : '',
			'client'          => array(
				'client_name'    => $site->client_name,
				'client_email'   => $site->client_email,
				'client_company' => $site->client_company,
				'client_phone'   => $site->client_phone,
				'client_locale'  => $site->client_locale,
				'report_email'   => $site->report_email,
				'report_enabled' => (int) $site->report_enabled,
			),
		);

		if ( isset( $extra['connection'] ) && is_array( $extra['connection'] ) ) {
			$payload['connection'] = $extra['connection'];
		}
		if ( isset( $extra['maintenance_entitlements'] ) && is_array( $extra['maintenance_entitlements'] ) ) {
			$payload['maintenance_entitlements'] = $extra['maintenance_entitlements'];
		}

		return self::post( 'enroll', $payload, $idempotency_key );
	}

	public static function update_site_status( $site, $action ) {
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_missing_site', 'Managed site is required.' );
		}

		$payload = array(
			'portal_site_id' => (int) $site->id,
		);

		return self::post( $action, $payload );
	}

	/**
	 * Test the connection to the maintenance hub (URL + shared secret). Use from Support Portal settings.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function test_connection() {
		$result = self::post( 'test', array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	public static function update_subscription_sites( $subscription_id, $portal_status ) {
		$action = self::map_portal_status_to_action( $portal_status );
		if ( ! $action ) {
			return;
		}

		$sites = RW_Sites::get_sites_by_subscription( $subscription_id );
		if ( empty( $sites ) ) {
			return;
		}

		foreach ( $sites as $site ) {
			$result = self::update_site_status( $site, $action );
			self::log_request_result( $action, $site->id, $result );
		}
	}

	private static function post( $action, array $payload, $idempotency_key = '' ) {
		$endpoint = self::endpoint_url( $action );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type' => 'application/json',
		);

		$signature_headers = self::build_signature_headers( 'POST', $action, $body );
		if ( is_wp_error( $signature_headers ) ) {
			return $signature_headers;
		}

		$headers = array_merge( $headers, $signature_headers );
		$idempotency_key = trim( (string) $idempotency_key );
		if ( strlen( $idempotency_key ) >= 8 && strlen( $idempotency_key ) <= 128 && preg_match( '/^[A-Za-z0-9._:-]+$/', $idempotency_key ) ) {
			$headers['X-RW-Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => self::timeout_for( $action ),
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_maint_request_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'rw_maint_bad_response',
				'Maintenance hub responded with an error.',
				array(
					'status' => $code,
					'body'   => RW_Redact::scrub( is_array( $data ) ? $data : array() ),
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	private static function build_signature_headers( $method, $action, $body ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'rw_maint_secret_missing', 'Maintenance hub secret is not configured.' );
		}

		$timestamp = (string) time();
		$nonce     = self::random_nonce();
		$path = self::signing_path( $action );
		$payload   = strtoupper( $method ) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . hash( 'sha256', (string) $body );
		$signature = hash_hmac( 'sha256', $payload, $secret );

		return array(
			'X-RW-Timestamp' => $timestamp,
			'X-RW-Nonce'     => $nonce,
			'X-RW-Signature' => $signature,
			'X-RW-Path'      => $path,
		);
	}

	private static function timeout_for( $action ) {
		$default = ( 'enroll' === $action ) ? self::ENROLL_TIMEOUT : self::DEFAULT_TIMEOUT;

		return (int) apply_filters( 'rw_portal_maint_timeout', $default, $action );
	}

	private static function endpoint_url( $action ) {
		$base = self::get_base_url();
		if ( '' === $base ) {
			return new WP_Error( 'rw_maint_url_missing', 'Maintenance hub URL is not configured.' );
		}

		$route = '/reactwoo-maint/v1/' . ltrim( (string) $action, '/' );
		return RW_Rest_Url::build( $base, $route, RW_Rest_Url::configured_style() );
	}

	private static function get_base_url() {
		$url = (string) get_option( 'rw_portal_maint_url', '' );
		$url = (string) apply_filters( 'rw_portal_maint_url', $url );

		return rtrim( $url, '/' );
	}

	private static function get_secret() {
		$secret = (string) get_option( 'rw_portal_maint_secret', '' );

		$secret = (string) apply_filters( 'rw_portal_maint_secret', $secret );

		return self::normalize_secret( $secret );
	}

	private static function signing_path( $action ) {
		return RW_Rest_Url::sign_path( '/reactwoo-maint/v1/' . ltrim( (string) $action, '/' ) );
	}

	private static function map_portal_status_to_action( $portal_status ) {
		switch ( $portal_status ) {
			case 'connected':
				return 'resume';
			case 'suspended':
				return 'suspend';
			case 'disconnected':
				return 'disconnect';
			default:
				return '';
		}
	}

	private static function random_nonce() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return wp_generate_password( 32, false, false );
		}
	}

	private static function normalize_secret( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}

		if ( 64 === strlen( $secret ) && ctype_xdigit( $secret ) ) {
			return strtolower( $secret );
		}

		return hash( 'sha256', $secret );
	}

	private static function log_request_result( $action, $site_id, $result ) {
		if ( is_wp_error( $result ) ) {
			RW_Audit::log(
				'maint_' . sanitize_key( $action ) . '_failed',
				array(
					'managed_site_id' => (int) $site_id,
					'error'           => $result->get_error_message(),
				)
			);

			return;
		}

		RW_Audit::log(
			'maint_' . sanitize_key( $action ) . '_sent',
			array(
				'managed_site_id' => (int) $site_id,
			)
		);
	}
}
