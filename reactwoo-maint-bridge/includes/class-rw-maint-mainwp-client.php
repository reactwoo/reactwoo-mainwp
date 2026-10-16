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
		add_action( 'rw_maint_mainwp_check_site', array( __CLASS__, 'check_site' ), 10, 1 );
		add_action( 'rw_maint_mainwp_sync_site', array( __CLASS__, 'sync_site' ), 10, 1 );
		add_action( 'rw_maint_mainwp_reconnect_site', array( __CLASS__, 'reconnect_site' ), 10, 1 );
		add_action( 'rw_maint_mainwp_purge_site', array( __CLASS__, 'purge_site' ), 10, 1 );
	}

	public static function create_site( $existing_id, $site, $payload = array(), $groups = array() ) {
		if ( $existing_id ) {
			return $existing_id;
		}

		if ( ! $site ) {
			return $existing_id;
		}

		$credentials = self::resolve_credentials( $site, $payload );
		if ( empty( $credentials['admin'] ) || ( empty( $credentials['adminpassword'] ) && empty( $credentials['uniqueid'] ) ) ) {
			RW_Maint_Audit::log(
				'mainwp_create_skipped',
				array(
					'portal_site_id'  => (int) $site->portal_site_id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'reason'          => 'missing_credentials',
				)
			);
			return $existing_id;
		}

		$query = array(
			'url'   => $site->site_url,
			'admin' => $credentials['admin'],
		);

		$mode = isset( $credentials['auth_mode'] ) ? (string) $credentials['auth_mode'] : 'unique_id';
		if ( ( 'unique_id' === $mode || 'both' === $mode ) && ! empty( $credentials['uniqueid'] ) ) {
			$query['uniqueid'] = $credentials['uniqueid'];
		}
		if ( ( 'password' === $mode || 'both' === $mode ) && ! empty( $credentials['adminpassword'] ) ) {
			$query['adminpassword'] = $credentials['adminpassword'];
		}

		$name = self::resolve_site_name( $site, $payload );
		if ( '' !== $name ) {
			$query['name'] = $name;
		}

		$query = apply_filters( 'rw_maint_mainwp_create_query', $query, $site, $payload, $groups );

		$body     = $query;
		$response = self::request( 'POST', 'sites/add/', $body, $query );
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

		$group_ids = self::resolve_group_ids( $groups, $site, $payload );
		$query     = array();

		if ( ! empty( $group_ids ) ) {
			$query['groupids'] = implode( ',', $group_ids );
		}

		$name = self::resolve_site_name( $site, $payload );
		if ( '' !== $name ) {
			$query['name'] = $name;
		}

		$query = apply_filters( 'rw_maint_mainwp_reporting_query', $query, $site, $payload, $groups );

		if ( empty( $query ) ) {
			return;
		}

		$path   = sprintf( 'sites/%d/edit/', (int) $site->mainwp_site_id );
		$method = apply_filters( 'rw_maint_mainwp_update_method', 'PUT', $site );

		$response = self::request( $method, $path, array(), $query );
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
		return self::send_lifecycle_action( $site, 'disconnect' );
	}

	public static function check_site( $site ) {
		return self::send_lifecycle_action( $site, 'check' );
	}

	public static function sync_site( $site ) {
		return self::send_lifecycle_action( $site, 'sync' );
	}

	public static function reconnect_site( $site ) {
		self::send_lifecycle_action( $site, 'reconnect' );
	}

	public static function purge_site( $site ) {
		self::send_lifecycle_action( $site, 'purge' );
	}

	private static function send_lifecycle_action( $site, $action ) {
		if ( ! $site || empty( $site->mainwp_site_id ) ) {
			return new WP_Error( 'rw_mainwp_missing_site', 'Operational site is not connected.' );
		}

		$path = self::default_action_path( $action, $site );
		$path = apply_filters( 'rw_maint_mainwp_action_path', $path, $action, $site );
		if ( '' === $path ) {
			return new WP_Error( 'rw_mainwp_action_unsupported', 'Maintenance action is not available.' );
		}

		$method = self::default_action_method( $action );
		$method = apply_filters( 'rw_maint_mainwp_action_method', $method, $action, $site );
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
			return $response;
		}

		RW_Maint_Audit::log(
			'mainwp_action_sent',
			array(
				'portal_site_id'  => (int) $site->portal_site_id,
				'subscription_id' => (int) $site->subscription_id,
				'user_id'         => (int) $site->user_id,
				'action'          => $action,
				'result'          => self::extract_action_result_summary( $response ),
			)
		);

		return $response;
	}

	private static function request( $method, $path, array $body = array(), array $query = array() ) {
		$base_url = self::get_api_base();
		if ( '' === $base_url ) {
			return new WP_Error( 'rw_mainwp_missing_url', 'MainWP API URL not configured.' );
		}

		$use_internal = self::use_internal_rest( $base_url );
		if ( $use_internal ) {
			return self::request_internal( $method, $path, $body, $query );
		}

		$url = trailingslashit( $base_url ) . ltrim( $path, '/' );
		$url = self::apply_auth_query( $url );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

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
			return new WP_Error( 'rw_mainwp_bad_response', 'MainWP API error.', array( 'status' => $code, 'body' => self::safe_body( $data ) ) );
		}

		return self::reject_failed_envelope( $data );
	}

	/**
	 * MainWP site add returns HTTP 200 with success 0 when the child rejects the connection.
	 *
	 * @param mixed $data Decoded response.
	 * @return array|WP_Error
	 */
	private static function reject_failed_envelope( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( ! array_key_exists( 'success', $data ) || ! empty( $data['success'] ) ) {
			return $data;
		}

		$message = isset( $data['error'] ) ? wp_strip_all_tags( (string) $data['error'] ) : '';
		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
		if ( strlen( $message ) > 180 ) {
			$message = substr( $message, 0, 180 );
		}
		if ( '' === $message ) {
			$message = 'MainWP rejected the request.';
		}

		return new WP_Error( 'rw_mainwp_rejected', $message, array( 'status' => 200 ) );
	}

	/**
	 * Whether to use internal REST (same WordPress) instead of HTTP.
	 *
	 * @param string $base_url MainWP API base URL.
	 * @return bool
	 */
	private static function use_internal_rest( $base_url ) {
		if ( self::is_mainwp_same_site() ) {
			return true;
		}

		$home = rtrim( (string) home_url(), '/' );
		$base_host = wp_parse_url( $base_url, PHP_URL_HOST );
		$home_host = wp_parse_url( $home, PHP_URL_HOST );
		if ( $base_host !== $home_host ) {
			return false;
		}
		$rest_root = rest_url();
		$rest_host = wp_parse_url( $rest_root, PHP_URL_HOST );
		return $rest_host === $home_host && 0 === strpos( $base_url, $rest_root );
	}

	/**
	 * Perform MainWP REST request via internal dispatch (same WordPress, no HTTP).
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to API base (e.g. sites/add/).
	 * @param array  $body   JSON body.
	 * @param array  $query  Query args.
	 * @return array|WP_Error
	 */
	private static function request_internal( $method, $path, array $body = array(), array $query = array() ) {
		$api_path = (string) apply_filters( 'rw_maint_mainwp_api_path', 'wp-json/mainwp/v2', home_url() );
		$route_prefix = trim( preg_replace( '#^wp-json/#', '', $api_path ), '/' );
		$path = trim( $path, '/' );
		$route = '/' . $route_prefix . ( $path ? '/' . $path : '' );

		$request = new WP_REST_Request( strtoupper( $method ), $route );
		$headers = self::auth_headers();
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $body ) ) {
			$request->set_body( wp_json_encode( $body ) );
		}
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		// MainWP authenticates from the server header and the rest_route query var.
		// An internal dispatch has neither until they are set for this call.
		$previous_authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : null;
		$previous_route         = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : null;
		if ( isset( $headers['Authorization'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
		}
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
		if ( class_exists( 'MainWP_REST_Authentication' ) ) {
			$mainwp_user_id = MainWP_REST_Authentication::get_instance()->authenticate( false );
			if ( $mainwp_user_id ) {
				wp_set_current_user( $mainwp_user_id );
			}
		}

		$response = rest_do_request( $request );

		if ( null === $previous_authorization ) {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		} else {
			$_SERVER['HTTP_AUTHORIZATION'] = $previous_authorization;
		}
		if ( null === $previous_route ) {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		} else {
			$GLOBALS['wp']->query_vars['rest_route'] = $previous_route;
		}
		$code = $response->get_status();
		$data = $response->get_data();

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'rw_mainwp_bad_response', 'MainWP API error.', array( 'status' => $code, 'body' => self::safe_body( $data ) ) );
		}

		return self::reject_failed_envelope( $data );
	}

	private static function extract_site_id( array $response ) {
		return self::extract_id( $response );
	}

	private static function extract_tag_id( array $response ) {
		return self::extract_id( $response );
	}

	private static function extract_id( array $response ) {
		if ( isset( $response['id'] ) ) {
			return absint( $response['id'] );
		}

		if ( isset( $response['data']['id'] ) ) {
			return absint( $response['data']['id'] );
		}

		if ( isset( $response['data']['tag_id'] ) ) {
			return absint( $response['data']['tag_id'] );
		}

		if ( isset( $response['tag_id'] ) ) {
			return absint( $response['tag_id'] );
		}

		return 0;
	}

	private static function extract_action_result_summary( array $response ) {
		$summary = array();
		$keys    = array( 'id', 'task_id', 'job_id', 'status', 'message' );

		foreach ( $keys as $key ) {
			if ( isset( $response[ $key ] ) ) {
				$summary[ $key ] = $response[ $key ];
				continue;
			}

			if ( isset( $response['data'][ $key ] ) ) {
				$summary[ $key ] = $response['data'][ $key ];
			}
		}

		if ( empty( $summary ) ) {
			$summary['response'] = 'ok';
		}

		return $summary;
	}

	/**
	 * Whether MainWP REST API is available on this WordPress instance (e.g. MainWP plugin active).
	 *
	 * @return bool
	 */
	public static function is_mainwp_rest_available() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}
		$server = rest_get_server();
		if ( ! $server ) {
			return false;
		}
		$routes = $server->get_routes();
		foreach ( array_keys( $routes ) as $route ) {
			if ( false !== strpos( $route, 'mainwp' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the configured MainWP API is on this same WordPress (no URL/key/secret needed for URL).
	 *
	 * @return bool
	 */
	public static function is_mainwp_same_site() {
		$base = (string) get_option( 'rw_maint_mainwp_url', '' );
		$base = (string) apply_filters( 'rw_maint_mainwp_url', $base );
		$base = rtrim( $base, '/' );
		if ( '' === $base && self::is_mainwp_rest_available() ) {
			return true;
		}
		if ( '' === $base ) {
			return false;
		}
		$home = rtrim( (string) home_url(), '/' );
		$base_host = wp_parse_url( $base, PHP_URL_HOST );
		$home_host = wp_parse_url( $home, PHP_URL_HOST );
		return $base_host === $home_host && 0 === strpos( $base, $home );
	}

	private static function get_api_base() {
		$base = (string) get_option( 'rw_maint_mainwp_url', '' );
		$base = (string) apply_filters( 'rw_maint_mainwp_url', $base );
		$base = rtrim( $base, '/' );

		if ( '' === $base && self::is_mainwp_rest_available() ) {
			$base = home_url();
		}

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

		$mode = self::get_auth_mode( $key );

		// MainWP REST API v2: Bearer token (default on MainWP dashboard).
		if ( 'bearer' === $mode ) {
			if ( '' === $key ) {
				return array();
			}
			return array(
				'Authorization' => 'Bearer ' . $key,
			);
		}

		if ( '' === $key || '' === $secret ) {
			return array();
		}

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

		$mode = self::get_auth_mode( $key );
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

	private static function resolve_credentials( $site, $payload ) {
		$payload = (array) $payload;

		$credentials = apply_filters( 'rw_maint_mainwp_credentials', array(), $site, $payload );
		if ( ! empty( $credentials ) ) {
			return array_merge(
				array(
					'admin'         => '',
					'adminpassword' => '',
					'uniqueid'      => '',
				),
				$credentials
			);
		}

		$mainwp     = array();
		$connection = array();
		if ( isset( $payload['mainwp'] ) && is_array( $payload['mainwp'] ) ) {
			$mainwp = $payload['mainwp'];
		}
		if ( isset( $payload['connection'] ) && is_array( $payload['connection'] ) ) {
			$connection = $payload['connection'];
		}

		$admin = self::first_non_empty(
			array(
				$connection['admin'] ?? '',
				$mainwp['admin'] ?? '',
				$mainwp['admin_user'] ?? '',
				$mainwp['admin_username'] ?? '',
				$payload['mainwp_admin'] ?? '',
				$payload['admin'] ?? '',
			)
		);

		$admin_password = self::first_non_empty(
			array(
				$mainwp['adminpassword'] ?? '',
				$mainwp['admin_password'] ?? '',
				$mainwp['adminpwd'] ?? '',
				$payload['mainwp_admin_password'] ?? '',
				$payload['adminpassword'] ?? '',
				$payload['admin_password'] ?? '',
			)
		);

		$uniqueid = self::first_non_empty(
			array(
				$connection['uniqueid'] ?? '',
				$connection['unique_id'] ?? '',
				$mainwp['uniqueid'] ?? '',
				$mainwp['unique_id'] ?? '',
				$payload['mainwp_uniqueid'] ?? '',
				$payload['uniqueid'] ?? '',
			)
		);

		return array(
			'admin'         => sanitize_text_field( $admin ),
			'adminpassword' => (string) $admin_password,
			'uniqueid'      => sanitize_text_field( $uniqueid ),
			'auth_mode'     => self::auth_mode( $admin, $admin_password, $uniqueid, $payload ),
		);
	}

	/**
	 * @param string               $admin
	 * @param string               $password
	 * @param string               $uniqueid
	 * @param array<string,mixed>  $payload
	 * @return string unique_id|password|both
	 */
	public static function auth_mode( $admin, $password, $uniqueid, $payload = array() ) {
		unset( $admin );
		$requested = '';
		if ( isset( $payload['connection']['auth_mode'] ) ) {
			$requested = (string) $payload['connection']['auth_mode'];
		} elseif ( isset( $payload['auth_mode'] ) ) {
			$requested = (string) $payload['auth_mode'];
		}

		$has_id = '' !== (string) $uniqueid;
		$has_pw = '' !== (string) $password;
		if ( $has_id && $has_pw && in_array( $requested, array( 'password', 'both' ), true ) ) {
			return $requested;
		}
		if ( $has_id ) {
			return 'unique_id';
		}
		if ( $has_pw ) {
			return 'password';
		}

		return '';
	}

	private static function safe_body( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( class_exists( 'RW_Maint_Redact' ) ) {
			return RW_Maint_Redact::scrub( $data );
		}
		return array( 'redacted' => true );
	}

	private static function resolve_site_name( $site, $payload ) {
		$payload = (array) $payload;

		if ( isset( $payload['site_name'] ) ) {
			return sanitize_text_field( $payload['site_name'] );
		}

		if ( isset( $payload['mainwp'] ) && is_array( $payload['mainwp'] ) && isset( $payload['mainwp']['site_name'] ) ) {
			return sanitize_text_field( $payload['mainwp']['site_name'] );
		}

		if ( isset( $payload['mainwp'] ) && is_array( $payload['mainwp'] ) && isset( $payload['mainwp']['name'] ) ) {
			return sanitize_text_field( $payload['mainwp']['name'] );
		}

		return '';
	}

	private static function first_non_empty( array $values ) {
		foreach ( $values as $value ) {
			if ( '' !== (string) $value ) {
				return $value;
			}
		}

		return '';
	}

	private static function resolve_group_ids( $groups, $site, $payload ) {
		$group_ids = apply_filters( 'rw_maint_mainwp_group_ids', array(), $groups, $site, $payload );
		$group_ids = array_filter( array_map( 'absint', (array) $group_ids ) );
		$group_ids = array_values( $group_ids );

		if ( ! empty( $group_ids ) ) {
			return $group_ids;
		}

		$groups = array_filter( array_map( 'sanitize_text_field', (array) $groups ) );
		if ( empty( $groups ) ) {
			return array();
		}

		$map = self::ensure_tags( $groups, $site, $payload );
		if ( empty( $map ) ) {
			return array();
		}

		$resolved = array();
		foreach ( $groups as $group_name ) {
			if ( isset( $map[ $group_name ] ) ) {
				$resolved[] = (int) $map[ $group_name ];
			}
		}

		return array_values( array_filter( array_map( 'absint', $resolved ) ) );
	}

	private static function default_action_path( $action, $site ) {
		switch ( $action ) {
			case 'suspend':
				return sprintf( 'sites/%d/suspend/', (int) $site->mainwp_site_id );
			case 'resume':
				return sprintf( 'sites/%d/unsuspend/', (int) $site->mainwp_site_id );
			case 'disconnect':
				return sprintf( 'sites/%d/disconnect/', (int) $site->mainwp_site_id );
			case 'check':
				return sprintf( 'sites/%d/check/', (int) $site->mainwp_site_id );
			case 'sync':
				return sprintf( 'sites/%d/sync/', (int) $site->mainwp_site_id );
			case 'reconnect':
				return sprintf( 'sites/%d/reconnect/', (int) $site->mainwp_site_id );
			case 'purge':
				return sprintf( 'sites/%d/remove/', (int) $site->mainwp_site_id );
			default:
				return '';
		}
	}

	private static function default_action_method( $action ) {
		return 'purge' === $action ? 'DELETE' : 'POST';
	}

	private static function get_auth_mode( $key ) {
		$mode = (string) get_option( 'rw_maint_mainwp_auth', 'bearer' );
		$mode = apply_filters( 'rw_maint_mainwp_auth_mode', $mode, $key );

		return in_array( $mode, array( 'bearer', 'basic', 'query' ), true ) ? $mode : 'bearer';
	}

	private static function ensure_tags( array $names, $site, $payload ) {
		$payload = (array) $payload;

		$names = array_values( array_filter( array_unique( $names ) ) );
		if ( empty( $names ) ) {
			return array();
		}

		$cache_key = 'rw_maint_mainwp_tags';
		$map       = get_transient( $cache_key );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		$missing = array_diff( $names, array_keys( $map ) );
		if ( ! empty( $missing ) ) {
			$response = self::request( 'GET', 'tags/' );
			if ( ! is_wp_error( $response ) ) {
				$map = array_merge( $map, self::extract_tag_map( $response ) );
			}
		}

		$missing = array_diff( $names, array_keys( $map ) );
		if ( ! empty( $missing ) ) {
			foreach ( $missing as $name ) {
				$query = array(
					'name' => $name,
				);

				$color = self::resolve_tag_color( $name, $site, $payload );
				if ( '' !== $color ) {
					$query['color'] = $color;
				}

				$response = self::request( 'POST', 'tags/add/', array(), $query );
				if ( is_wp_error( $response ) ) {
					RW_Maint_Audit::log(
						'mainwp_tag_failed',
						array(
							'portal_site_id'  => (int) $site->portal_site_id,
							'subscription_id' => (int) $site->subscription_id,
							'user_id'         => (int) $site->user_id,
							'tag_name'        => $name,
							'error'           => $response->get_error_message(),
						)
					);
					continue;
				}

				$tag_id = self::extract_tag_id( $response );
				if ( $tag_id ) {
					$map[ $name ] = $tag_id;
					RW_Maint_Audit::log(
						'mainwp_tag_created',
						array(
							'portal_site_id'  => (int) $site->portal_site_id,
							'subscription_id' => (int) $site->subscription_id,
							'user_id'         => (int) $site->user_id,
							'tag_name'        => $name,
							'tag_id'          => $tag_id,
						)
					);
				}
			}
		}

		if ( ! empty( $map ) ) {
			set_transient( $cache_key, $map, HOUR_IN_SECONDS );
		}

		return $map;
	}

	private static function extract_tag_map( array $response ) {
		$items = array();

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			$items = $response['data'];
		} elseif ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
			$items = $response['items'];
		} elseif ( isset( $response['tags'] ) && is_array( $response['tags'] ) ) {
			$items = $response['tags'];
		} elseif ( self::is_list( $response ) ) {
			$items = $response;
		}

		$map = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( empty( $item['id'] ) || empty( $item['name'] ) ) {
				continue;
			}

			$map[ sanitize_text_field( $item['name'] ) ] = absint( $item['id'] );
		}

		return $map;
	}

	private static function resolve_tag_color( $name, $site, $payload ) {
		$color = apply_filters( 'rw_maint_mainwp_tag_color', '', $name, $site, $payload );
		$color = sanitize_text_field( $color );
		if ( '' === $color ) {
			return '';
		}

		if ( 0 !== strpos( $color, '#' ) ) {
			$color = '#' . $color;
		}

		return $color;
	}

	private static function is_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
