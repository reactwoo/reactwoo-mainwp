<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_MainWP_Child_Adapter {
	const PLUGIN_FILE   = 'mainwp-child/mainwp-child.php';
	const UNIQUE_OPTION = 'mainwp_child_uniqueId';
	const PUBKEY_OPTION = 'mainwp_child_pubkey';
	const SERVER_OPTION = 'mainwp_child_server';
	const SITE_ID_OPTION = 'mainwp_child_siteid';
	const DASHBOARD_URL = 'https://maint.reactwoo.com';

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
		if ( self::has_existing_link() && ! $allow ) {
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

	/**
	 * True only for an explicit customer confirmation. Missing and other strings stay off.
	 *
	 * @param mixed $value Request or stored flag.
	 * @return bool
	 */
	public static function is_explicit_takeover( $value ) {
		if ( true === $value || 1 === $value ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}

		return '1' === $value || 'true' === $value;
	}

	/**
	 * A live foreign link is an installed, active Child with a dashboard public key.
	 * Options left behind after Child was removed or deactivated are not a link.
	 *
	 * @return bool
	 */
	public static function has_existing_link() {
		if ( ! self::is_installed() || ! self::is_active() ) {
			return false;
		}

		$pubkey = get_option( self::PUBKEY_OPTION );
		return is_string( $pubkey ) && '' !== trim( $pubkey );
	}

	/**
	 * Options that tie Child to a dashboard. The unique security ID is kept.
	 *
	 * @return string[]
	 */
	public static function link_options() {
		return array(
			self::PUBKEY_OPTION,
			self::SITE_ID_OPTION,
			'mainwp_child_nonce',
			'mainwp_security',
			'mainwp_child_connected_admin',
			'mainwp_child_openssl_sign_algo',
			self::SERVER_OPTION,
		);
	}

	/**
	 * Back up and remove a dashboard link left behind when Child is not installed or not active.
	 * An active Child is left unchanged so a real foreign connection still blocks setup.
	 *
	 * @return bool True when at least one option was saved and removed.
	 */
	public static function backup_and_clear_inactive_link() {
		if ( self::is_installed() && self::is_active() ) {
			return false;
		}

		$missing = new stdClass();
		$saved   = array();
		foreach ( self::link_options() as $option ) {
			$value = get_option( $option, $missing );
			if ( $missing === $value || null === $value || false === $value ) {
				continue;
			}
			if ( is_string( $value ) && '' === trim( $value ) ) {
				delete_option( $option );
				continue;
			}

			$saved[ $option ] = $value;
			delete_option( $option );
		}

		if ( ! $saved ) {
			return false;
		}

		update_option(
			'rw_connector_child_link_backup',
			array(
				'backed_up_at' => gmdate( 'c' ),
				'options'      => $saved,
			),
			false
		);

		return true;
	}

	/**
	 * Drop the previous dashboard link and point Child at ReactWoo.
	 *
	 * @return string Dashboard URL that was stored.
	 */
	public static function rekey_to_reactwoo() {
		foreach ( self::link_options() as $option ) {
			delete_option( $option );
		}

		if ( class_exists( '\MainWP\Child\MainWP_Child_Keys_Manager' ) && method_exists( '\MainWP\Child\MainWP_Child_Keys_Manager', 'update_encrypted_option' ) ) {
			\MainWP\Child\MainWP_Child_Keys_Manager::update_encrypted_option( self::SERVER_OPTION, self::DASHBOARD_URL );
		} else {
			update_option( self::SERVER_OPTION, self::DASHBOARD_URL, false );
		}

		return self::DASHBOARD_URL;
	}

	/**
	 * Child's main file is on disk. A stale active_plugins row does not count.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		if ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) {
			return is_file( trailingslashit( WP_PLUGIN_DIR ) . self::PLUGIN_FILE );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			return false;
		}

		$plugins = get_plugins();
		return is_array( $plugins ) && isset( $plugins[ self::PLUGIN_FILE ] );
	}

	public static function is_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_api = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/plugin.php' : '';
			if ( '' === $plugin_api || ! is_readable( $plugin_api ) ) {
				return false;
			}
			require_once $plugin_api;
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE );
	}
}
