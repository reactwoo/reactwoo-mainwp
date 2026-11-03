<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_MainWP_Child_Adapter {
	const PLUGIN_FILE   = 'mainwp-child/mainwp-child.php';
	const UNIQUE_OPTION = 'mainwp_child_uniqueId';
	const PUBKEY_OPTION = 'mainwp_child_pubkey';

	/**
	 * Read the passwordless management identity without changing an existing dashboard link.
	 *
	 * @return array|WP_Error
	 */
	public static function passwordless_identity() {
		if ( ! self::is_active() ) {
			return new WP_Error( 'rw_child_missing', 'The maintenance component is not active on this website.', array( 'status' => 409 ) );
		}

		$pubkey = get_option( self::PUBKEY_OPTION );
		$pubkey = is_string( $pubkey ) ? trim( $pubkey ) : '';
		$allow  = apply_filters( 'rw_connector_allow_existing_child_connection', false, $pubkey );
		if ( '' !== $pubkey && ! $allow ) {
			return new WP_Error(
				'rw_existing_maintenance_connection',
				'This website already has an existing maintenance connection.',
				array( 'status' => 409 )
			);
		}

		$unique_id = get_option( self::UNIQUE_OPTION );
		$unique_id = apply_filters( 'rw_connector_unique_security_id', is_string( $unique_id ) ? trim( $unique_id ) : '' );
		if ( ! is_string( $unique_id ) || '' === $unique_id ) {
			$unique_id = wp_generate_password( 12, false, false );
			update_option( self::UNIQUE_OPTION, $unique_id );
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! $user_id && function_exists( 'get_user_by' ) ) {
			$login = (string) get_option( 'rw_connector_admin_login' );
			if ( '' !== $login ) {
				$stored = get_user_by( 'login', $login );
				if ( $stored && ! empty( $stored->ID ) ) {
					$user_id = (int) $stored->ID;
				}
			}
		}
		if ( $user_id && function_exists( 'update_user_option' ) ) {
			update_user_option( $user_id, 'mainwp_child_user_enable_passwd_auth_connect', '0' );
		}

		$version = '';
		if ( defined( 'MAINWP_CHILD_VERSION' ) ) {
			$version = (string) MAINWP_CHILD_VERSION;
		}

		return array(
			'uniqueid' => $unique_id,
			'version'  => $version,
		);
	}

	public static function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::PLUGIN_FILE );
	}
}
