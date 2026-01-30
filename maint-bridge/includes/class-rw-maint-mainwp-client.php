<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_MainWP_Client {
	public static function register() {
		add_filter( 'rw_maint_mainwp_create_site', array( __CLASS__, 'create_site' ), 10, 4 );
		add_action( 'rw_maint_mainwp_sync_reporting', array( __CLASS__, 'sync_reporting' ), 10, 3 );
		add_action( 'rw_maint_mainwp_suspend_site', array( __CLASS__, 'suspend_site' ), 10, 1 );
		add_action( 'rw_maint_mainwp_resume_site', array( __CLASS__, 'resume_site' ), 10, 1 );
		add_action( 'rw_maint_mainwp_disconnect_site', array( __CLASS__, 'disconnect_site' ), 10, 1 );
		add_action( 'rw_maint_mainwp_purge_site', array( __CLASS__, 'purge_site' ), 10, 1 );
	}

	public static function create_site( $existing_id, $site, $payload = array(), $groups = array() ) {
		if ( $existing_id ) {
			return $existing_id;
		}

		if ( ! $site ) {
			return $existing_id;
		}

		$body = array(
			'name'  => $site->site_name,
			'url'   => $site->site_url,
			'groups' => $groups,
			'portal_site_id'  => (int) $site->portal_site_id,
			'subscription_id' => (int) $site->subscription_id,
			'user_id'         => (int) $site->user_id,
		);

		$body = apply_filters( 'rw_maint_mainwp_create_payload', $body, $site, $payload, $groups );

		$response = self::request( 'POST', 'sites', $body );
		if ( is_wp_error( $response ) ) {
			RW_Maint_Audit::log(
				'mainwp_create_failed',
				array(
					'portal_site_id'  => (int) $site->portal_site_id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'error'           => $response->get_error_message(),
				)
			);
			return $existing_id;
		}

		$site_id = self::extract_site_id( $response );
		if ( $site_id ) {
			return $site_id;
		}

		return $existing_id;
	}

	public static function sync_reporting( $site, $payload = array(), $groups = array() ) {
		if ( ! $site || empty( $site->mainwp_site_id ) ) {
			return;
		}

		$body = array(
			'client_name'    => $site->client_name,
			'client_email'   => $site->client_email,
			'client_company' => $site->client_company,
			'client_locale'  => $site->client_locale,
			'report_email'   => $site->report_email,
			'report_enabled' => (int) $site->report_enabled,
			'groups'         => $groups,
		);

		$body = apply_filters( 'rw_maint_mainwp_reporting_payload', $body, $site, $payload, $groups );

		$path   = sprintf( 'sites/%d', (int) $site->mainwp_site_id );
		$method = apply_filters( 'rw_maint_mainwp_update_method', 'PUT', $site );

		$response = self::request( $method, $path, $body );
		if ( is_wp_error( $response ) ) {
			RW_Maint_Audit::log(
				'mainwp_reporting_failed',
				array(
					'portal_site_id'  => (int) $site->portal_site_id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'error'           => $response->get_error_message(),
				)
			);
		}
	}

	public static function suspend_site( $site ) {
		self::send_lifecycle_action( $site, 'suspend' );
	}

	public static function resume_site( $site ) {
		self::send_lifecycle_action( $site, 'resume' );
	}

	public static function disconnect_site( $site ) {
		self::send_lifecycle_action( $site, 'disconnect' );
	}

	public static function purge_site( $site ) {
		self::send_lifecycle_action( $site, 'purge' );
	}

	private static function send_lifecycle_action( $site, $action ) {
		if ( ! $site || empty( $site->mainwp_site_id ) ) {
			return;
		}

		$path = apply_filters( 'rw_maint_mainwp_action_path', '', $action, $site );
		if ( '' === $path ) {
			return;
		}

		$method = apply_filters( 'rw_maint_mainwp_action_method', 'POST', $action, $site );
		$body   = apply_filters( 'rw_maint_mainwp_action_body', array(), $action, $site );

		$response = self::request( $method, $path, $body );
		if ( is_wp_error( $response ) ) {
			RW_Maint_Audit::log(
				'mainwp_action_failed',
				array(
					'portal_site_id'  => (int) $site->portal_site_id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'action'          => $action,
					'error'           => $response->get_error_message(),
				)
			);
		}
	}

	private static function request( $method, $path, array $body = array() ) {
		$base_url = self::get_api_base();
		if ( '' === $base_url ) {
			return new WP_Error( 'rw_mainwp_missing_url', 'MainWP API URL not configured.' );
		}

		$url = trailingslashit( $base_url ) . ltrim( $path, '/' );
		$url = self::apply_auth_query( $url );

		$headers = array(
			'Content-Type' => 'application/json',
		);

		$headers = array_merge( $headers, self::auth_headers() );

		$args = array(
			'timeout' => 20,
			'headers' => $headers,
			'body'    => empty( $body ) ? null : wp_json_encode( $body ),
			'method'  => strtoupper( $method ),
		);

		$args = apply_filters( 'rw_maint_mainwp_request_args', $args, $method, $path, $body );

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'rw_mainwp_bad_response', 'MainWP API error.', array( 'status' => $code, 'body' => $data ) );
		}

		return is_array( $data ) ? $data : array();
	}

	private static function extract_site_id( array $response ) {
		if ( isset( $response['id'] ) ) {
			return absint( $response['id'] );
		}

		if ( isset( $response['data']['id'] ) ) {
			return absint( $response['data']['id'] );
		}

		return 0;
	}

	private static function get_api_base() {
		$base = (string) get_option( 'rw_maint_mainwp_url', '' );
		$base = (string) apply_filters( 'rw_maint_mainwp_url', $base );
		$base = rtrim( $base, '/' );

		if ( '' === $base ) {
			return '';
		}

		$path = (string) apply_filters( 'rw_maint_mainwp_api_path', 'wp-json/mainwp/v2', $base );
		$path = trim( $path, '/' );

		return trailingslashit( $base ) . $path;
	}

	private static function auth_headers() {
		$key    = (string) apply_filters( 'rw_maint_mainwp_key', get_option( 'rw_maint_mainwp_key', '' ) );
		$secret = (string) apply_filters( 'rw_maint_mainwp_secret', get_option( 'rw_maint_mainwp_secret', '' ) );

		if ( '' === $key || '' === $secret ) {
			return array();
		}

		$mode = (string) apply_filters( 'rw_maint_mainwp_auth_mode', 'basic', $key );
		if ( 'basic' !== $mode ) {
			return apply_filters( 'rw_maint_mainwp_auth_headers', array(), $mode, $key, $secret );
		}

		return array(
			'Authorization' => 'Basic ' . base64_encode( $key . ':' . $secret ),
		);
	}

	private static function apply_auth_query( $url ) {
		$key    = (string) apply_filters( 'rw_maint_mainwp_key', get_option( 'rw_maint_mainwp_key', '' ) );
		$secret = (string) apply_filters( 'rw_maint_mainwp_secret', get_option( 'rw_maint_mainwp_secret', '' ) );

		if ( '' === $key || '' === $secret ) {
			return $url;
		}

		$mode = (string) apply_filters( 'rw_maint_mainwp_auth_mode', 'basic', $key );
		if ( 'query' !== $mode ) {
			return $url;
		}

		$params = apply_filters(
			'rw_maint_mainwp_query_params',
			array(
				'consumer_key'    => $key,
				'consumer_secret' => $secret,
			),
			$key,
			$secret
		);

		return add_query_arg( $params, $url );
	}
}
