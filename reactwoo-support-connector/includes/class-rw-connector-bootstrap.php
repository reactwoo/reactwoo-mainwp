<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Bootstrap {
	public static function register_routes() {
		register_rest_route(
			'reactwoo-connector/v2',
			'/bootstrap',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bootstrap' ),
				'permission_callback' => array( __CLASS__, 'require_admin' ),
			)
		);

		register_rest_route(
			'reactwoo-connector/v2',
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'verify_signature' ),
			)
		);

		register_rest_route(
			'reactwoo-connector/v2',
			'/identity',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'identity' ),
				'permission_callback' => array( __CLASS__, 'verify_signature' ),
			)
		);

		register_rest_route(
			'reactwoo-connector/v2',
			'/heartbeat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'heartbeat' ),
				'permission_callback' => array( __CLASS__, 'require_admin' ),
			)
		);
	}

	public static function require_admin() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error( 'rw_connector_forbidden', 'An administrator must approve this connection.', array( 'status' => 403 ) );
	}

	public static function verify_signature( WP_REST_Request $request ) {
		return RW_Connector_Signing::verify_request( $request );
	}

	public static function status() {
		$site_id = (int) get_option( 'rw_connector_site_id' );
		if ( ! $site_id ) {
			return new WP_Error( 'rw_connector_not_connected', 'Connector is not enrolled.', array( 'status' => 404 ) );
		}

		$child_active = class_exists( 'RW_MainWP_Child_Adapter' ) && RW_MainWP_Child_Adapter::is_active();
		$unique_id    = '';
		if ( $child_active ) {
			$raw       = get_option( RW_MainWP_Child_Adapter::UNIQUE_OPTION );
			$unique_id = is_string( $raw ) ? trim( $raw ) : '';
		}

		$reports_file = 'mainwp-child-reports/mainwp-child-reports.php';

		return rest_ensure_response(
			array(
				'site_id'           => $site_id,
				'connector_version' => RW_CONNECTOR_VERSION,
				'protocol'          => 'v2',
				'wp_version'        => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'admin'             => (string) get_option( 'rw_connector_admin_login' ),
				'child_active'      => $child_active,
				'child_version'     => $child_active && defined( 'MAINWP_CHILD_VERSION' ) ? (string) MAINWP_CHILD_VERSION : '',
				'reports_active'    => self::plugin_is_active( $reports_file ),
				'reports_version'   => class_exists( 'RW_Component_Installer' ) ? RW_Component_Installer::plugin_version( $reports_file ) : '',
				'uniqueid'          => $unique_id,
				'managed'           => '1' === (string) get_option( 'rw_connector_managed' ),
			)
		);
	}

	public static function identity() {
		$site_id = (int) get_option( 'rw_connector_site_id' );
		if ( ! $site_id ) {
			return new WP_Error( 'rw_connector_not_connected', 'Connector is not enrolled.', array( 'status' => 404 ) );
		}

		$identity = RW_MainWP_Child_Adapter::passwordless_identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$admin = (string) get_option( 'rw_connector_admin_login' );
		if ( '' === $admin && function_exists( 'wp_get_current_user' ) ) {
			$user  = wp_get_current_user();
			$admin = ( $user && ! empty( $user->user_login ) ) ? (string) $user->user_login : '';
		}

		update_option( 'rw_connector_managed', '1', false );

		return rest_ensure_response(
			array(
				'admin'         => $admin,
				'uniqueid'      => $identity['uniqueid'],
				'auth_mode'     => 'unique_id',
				'child_version' => $identity['version'],
				'child_active'  => true,
			)
		);
	}

	public static function bootstrap( WP_REST_Request $request ) {
		$challenge  = trim( (string) $request->get_header( 'x-rw-enrolment-challenge' ) );
		$portal_url = esc_url_raw( (string) $request->get_param( 'portal_url' ) );

		if ( '' === $challenge || '' === $portal_url ) {
			return new WP_Error( 'rw_connector_challenge_missing', 'Enrolment challenge is missing.', array( 'status' => 400 ) );
		}

		$portal_check = self::allowed_portal( $portal_url );
		if ( is_wp_error( $portal_check ) ) {
			return $portal_check;
		}

		$takeover_requested = RW_MainWP_Child_Adapter::is_explicit_takeover( $request->get_param( 'takeover' ) );
		$own_dashboard      = RW_MainWP_Child_Adapter::is_own_dashboard_link();
		if ( ! $own_dashboard && RW_MainWP_Child_Adapter::blocks_bootstrap( $takeover_requested ) ) {
			RW_Connector::clear_enrolment();
			return new WP_Error(
				'rw_existing_maintenance_connection',
				'This website already has an existing maintenance connection.',
				array( 'status' => 409 )
			);
		}

		if ( $own_dashboard ) {
			RW_MainWP_Child_Adapter::remember_mainwp_site_id( RW_MainWP_Child_Adapter::child_site_id() );
		} else {
			RW_MainWP_Child_Adapter::backup_and_clear_inactive_link();
		}

		$confirm_body = array(
			'challenge' => $challenge,
			'site_url'  => home_url(),
		);
		if ( $takeover_requested ) {
			$confirm_body['takeover'] = true;
		}

		$confirm = rtrim( $portal_url, '/' ) . '/index.php?rest_route=' . rawurlencode( '/reactwoo/v1/connection/confirm-challenge' );
		$response = wp_remote_post(
			$confirm,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $confirm_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			RW_Connector::clear_enrolment();
			return new WP_Error( 'rw_connector_portal_error', 'ReactWoo could not confirm this connection.', array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['site_id'] ) || empty( $body['site_secret'] ) ) {
			RW_Connector::clear_enrolment();
			return new WP_Error( 'rw_connector_portal_rejected', 'ReactWoo could not confirm this connection.', array( 'status' => 409 ) );
		}

		if ( ! $own_dashboard && RW_MainWP_Child_Adapter::has_existing_link() ) {
			if ( ! RW_MainWP_Child_Adapter::is_explicit_takeover( isset( $body['takeover'] ) ? $body['takeover'] : false ) ) {
				RW_Connector::clear_enrolment();
				return new WP_Error(
					'rw_existing_maintenance_connection',
					'This website already has an existing maintenance connection.',
					array( 'status' => 409 )
				);
			}
			RW_MainWP_Child_Adapter::rekey_to_reactwoo();
		}

		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$admin = ( $user && ! empty( $user->user_login ) ) ? (string) $user->user_login : '';
		if ( '' !== $admin ) {
			update_option( 'rw_connector_admin_login', sanitize_user( $admin ), false );
		}

		RW_Connector::persist_enrolment(
			$portal_url,
			(int) $body['site_id'],
			sanitize_text_field( (string) $body['site_secret'] ),
			array()
		);

		$identity = array(
			'uniqueid' => '',
			'version'  => '',
		);
		if ( RW_MainWP_Child_Adapter::is_active() ) {
			$existing = RW_MainWP_Child_Adapter::passwordless_identity();
			if ( is_wp_error( $existing ) ) {
				RW_Connector::clear_enrolment();
				return $existing;
			}
			$identity = $existing;
		}

		return rest_ensure_response(
			array(
				'admin'             => $admin,
				'uniqueid'          => $identity['uniqueid'],
				'auth_mode'         => 'unique_id',
				'child_active'      => RW_MainWP_Child_Adapter::is_active(),
				'child_version'     => $identity['version'],
				'connector_version' => RW_CONNECTOR_VERSION,
				'protocol'          => 'v2',
			)
		);
	}

	private static function plugin_is_active( $file ) {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( $file );
	}

	public static function heartbeat() {
		RW_Connector::send_heartbeat();

		return rest_ensure_response( array( 'sent' => true ) );
	}

	/**
	 * @param string $portal_url Portal origin.
	 * @return true|WP_Error
	 */
	public static function allowed_portal( $portal_url ) {
		$parts = wp_parse_url( (string) $portal_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error( 'rw_connector_portal_invalid', 'Portal address is invalid.', array( 'status' => 400 ) );
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'rw_connector_portal_invalid', 'Portal address must use HTTPS.', array( 'status' => 400 ) );
		}

		$host  = strtolower( (string) $parts['host'] );
		$hosts = apply_filters( 'rw_connector_allowed_portal_hosts', array( 'reactwoo.com', 'www.reactwoo.com' ), $portal_url );
		$hosts = array_map( 'strtolower', array_map( 'strval', (array) $hosts ) );

		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() && substr( $host, -6 ) === '.local' ) {
			$hosts[] = $host;
		}

		if ( ! in_array( $host, $hosts, true ) ) {
			return new WP_Error( 'rw_connector_portal_invalid', 'Portal address is not allowed.', array( 'status' => 400 ) );
		}

		return true;
	}
}
