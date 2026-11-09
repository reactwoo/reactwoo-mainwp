<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector {
	const HEARTBEAT_HOOK = 'rw_connector_heartbeat';

	public static function register() {
		self::load_dependencies();
		RW_Connector_Updater::init();
		RW_Whitelabel::register();
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::HEARTBEAT_HOOK, array( __CLASS__, 'send_heartbeat' ) );
	}

	public static function activate() {
		self::schedule_heartbeat();
	}

	private static function load_dependencies() {
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-connector-signing.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-mainwp-child-adapter.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-component-installer.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-whitelabel.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-connector-bootstrap.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-connector-updater.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-connector-security-scan.php';
		require_once RW_CONNECTOR_DIR . 'includes/class-rw-connector-snapshot.php';
	}

	public static function register_routes() {
		RW_Connector_Bootstrap::register_routes();
		RW_Component_Installer::register_routes();
		RW_Connector_Security_Scan::register_routes();
		RW_Connector_Snapshot::register_routes();

		register_rest_route(
			'reactwoo-connector/v1',
			'/connect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'connect' ),
				'permission_callback' => array( __CLASS__, 'require_enrolment_admin' ),
				'args'                => array(
					'token'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'portal_url' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'reactwoo-connector/v1',
			'/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * First enrolment only. An administrator and a single-use portal token are
	 * both required. An enrolled site cannot have its secret replaced here.
	 *
	 * @return true|WP_Error
	 */
	public static function require_enrolment_admin() {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error( 'rw_connector_forbidden', 'An administrator must approve this connection.', array( 'status' => 403 ) );
	}

	/**
	 * @param int    $site_id Stored site id.
	 * @param string $secret Stored secret.
	 * @return bool
	 */
	public static function blocks_secret_replacement( $site_id, $secret ) {
		return (int) $site_id > 0 && '' !== (string) $secret;
	}

	public static function connect( WP_REST_Request $request ) {
		$token     = (string) $request->get_param( 'token' );
		$portal_url = esc_url_raw( (string) $request->get_param( 'portal_url' ) );

		if ( '' === $token || '' === $portal_url ) {
			return new WP_Error( 'rw_connector_missing', 'Token and portal URL are required.', array( 'status' => 400 ) );
		}

		if ( self::blocks_secret_replacement( self::get_site_id(), self::get_site_secret() ) ) {
			return new WP_Error( 'rw_connector_already_enrolled', 'This website is already enrolled. The connection secret was not changed.', array( 'status' => 409 ) );
		}

		$portal_check = RW_Connector_Bootstrap::allowed_portal( $portal_url );
		if ( is_wp_error( $portal_check ) ) {
			return $portal_check;
		}

		$verify_url = rtrim( $portal_url, '/' ) . '/index.php?rest_route=' . rawurlencode( '/reactwoo/v1/tokens/verify' );
		$response   = wp_remote_post(
			$verify_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'token'    => $token,
						'site_url' => home_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_connector_portal_error', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'rw_connector_portal_invalid', 'Unexpected response from portal.', array( 'status' => 502 ) );
		}

		if ( empty( $body['site_id'] ) || empty( $body['site_secret'] ) ) {
			return new WP_Error( 'rw_connector_portal_missing', 'Portal response missing site details.', array( 'status' => 502 ) );
		}

		self::store_connection(
			$portal_url,
			(int) $body['site_id'],
			sanitize_text_field( (string) $body['site_secret'] ),
			isset( $body['maintenance_payload'] ) ? $body['maintenance_payload'] : array()
		);

		self::schedule_heartbeat();

		return rest_ensure_response(
			array(
				'connected' => true,
				'site_id'   => (int) $body['site_id'],
				'payload'   => isset( $body['maintenance_payload'] ) ? $body['maintenance_payload'] : array(),
			)
		);
	}

	public static function status( WP_REST_Request $request ) {
		$site_id = self::get_site_id();
		if ( ! $site_id ) {
			return new WP_Error( 'rw_connector_not_connected', 'Connector is not enrolled.', array( 'status' => 404 ) );
		}

		$verified = RW_Connector_Signing::verify_request( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		return rest_ensure_response(
			array(
				'site_id'           => $site_id,
				'connector_version' => RW_CONNECTOR_VERSION,
				'wp_version'        => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
			)
		);
	}

	public static function send_heartbeat() {
		$site_id   = self::get_site_id();
		$secret    = self::get_site_secret();
		$portal_url = self::get_portal_url();

		if ( ! $site_id || ! $secret || ! $portal_url ) {
			return;
		}

		$route    = '/reactwoo/v1/sites/heartbeat';
		$endpoint = rtrim( $portal_url, '/' ) . '/index.php?rest_route=' . rawurlencode( $route );
		$timezone = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : (string) get_option( 'timezone_string' );
		$body     = wp_json_encode(
			array(
				'connector_version' => RW_CONNECTOR_VERSION,
				'wp_version'        => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'timezone_string'   => $timezone,
				'gmt_offset'        => (float) get_option( 'gmt_offset' ),
			)
		);

		$headers = RW_Connector_Signing::build_headers( 'POST', $endpoint, $body, $site_id, $secret );
		$headers['Content-Type'] = 'application/json';

		wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body,
			)
		);
	}

	private static function schedule_heartbeat() {
		if ( wp_next_scheduled( self::HEARTBEAT_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HEARTBEAT_HOOK );
	}

	public static function persist_enrolment( $portal_url, $site_id, $secret, array $payload ) {
		self::store_connection( $portal_url, $site_id, $secret, $payload );
		self::schedule_heartbeat();
	}

	public static function clear_enrolment() {
		delete_option( 'rw_connector_portal_url' );
		delete_option( 'rw_connector_site_id' );
		delete_option( 'rw_connector_site_secret' );
		delete_option( 'rw_connector_payload' );
		delete_option( 'rw_connector_connected_at' );
		delete_option( 'rw_connector_admin_login' );
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );
		}
	}

	private static function store_connection( $portal_url, $site_id, $secret, array $payload ) {
		update_option( 'rw_connector_portal_url', esc_url_raw( $portal_url ), false );
		update_option( 'rw_connector_site_id', (int) $site_id, false );
		update_option( 'rw_connector_site_secret', $secret, false );
		update_option( 'rw_connector_payload', wp_json_encode( $payload ), false );
		update_option( 'rw_connector_connected_at', current_time( 'mysql', true ), false );
	}

	private static function get_site_id() {
		return (int) get_option( 'rw_connector_site_id' );
	}

	private static function get_site_secret() {
		return (string) get_option( 'rw_connector_site_secret' );
	}

	private static function get_portal_url() {
		return (string) get_option( 'rw_connector_portal_url' );
	}
}
