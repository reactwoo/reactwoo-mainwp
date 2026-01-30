<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Client {
	const DEFAULT_TIMEOUT = 20;

	public static function enroll_site( $site ) {
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_missing_site', 'Managed site is required.' );
		}

		$payload = array(
			'portal_site_id'  => (int) $site->id,
			'subscription_id' => (int) $site->subscription_id,
			'user_id'         => (int) $site->user_id,
			'site_url'        => $site->site_url,
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

		return self::post( 'enroll', $payload );
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

	private static function post( $action, array $payload ) {
		$endpoint = self::endpoint_url( $action );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type' => 'application/json',
		);

		$signature_headers = self::build_signature_headers( 'POST', $endpoint, $body );
		if ( is_wp_error( $signature_headers ) ) {
			return $signature_headers;
		}

		$headers = array_merge( $headers, $signature_headers );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => (int) apply_filters( 'rw_portal_maint_timeout', self::DEFAULT_TIMEOUT ),
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
					'body'   => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	private static function build_signature_headers( $method, $url, $body ) {
		$secret = self::get_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'rw_maint_secret_missing', 'Maintenance hub secret is not configured.' );
		}

		$timestamp = (string) time();
		$nonce     = self::random_nonce();
		$path      = self::signing_path( $url );
		$payload   = strtoupper( $method ) . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . hash( 'sha256', (string) $body );
		$signature = hash_hmac( 'sha256', $payload, $secret );

		return array(
			'X-RW-Timestamp' => $timestamp,
			'X-RW-Nonce'     => $nonce,
			'X-RW-Signature' => $signature,
			'X-RW-Path'      => $path,
		);
	}

	private static function endpoint_url( $action ) {
		$base = self::get_base_url();
		if ( '' === $base ) {
			return new WP_Error( 'rw_maint_url_missing', 'Maintenance hub URL is not configured.' );
		}

		$base = trailingslashit( $base );

		return $base . 'wp-json/reactwoo-maint/v1/' . ltrim( $action, '/' );
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

	private static function signing_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			$path = '/';
		}

		$prefix = (string) apply_filters( 'rw_portal_maint_rest_prefix', 'wp-json', $url );
		$prefix = '/' . ltrim( $prefix, '/' );

		if ( 0 === strpos( $path, $prefix ) ) {
			$path = substr( $path, strlen( $prefix ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		return $path;
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
