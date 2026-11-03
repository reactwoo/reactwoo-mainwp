<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides the maintenance plugins on a managed site.
 *
 * Gated by the rw_connector_managed flag. ReactWoo support users bypass it.
 * The connector itself stays visible unless rw_connector_hide_self is set.
 *
 * Admin page slugs verified against MainWP Child 6.4 and Child Reports 2.3.1:
 * - options-general.php?page=mainwp_child_tab
 * - options-general.php?page=mainwp-reports-page (subpage slug reports-page)
 * - options-general.php?page=mainwp-reports-settings (subpage slug reports-settings)
 */
class RW_Whitelabel {
	const CHILD_FILE   = 'mainwp-child/mainwp-child.php';
	const REPORTS_FILE = 'mainwp-child-reports/mainwp-child-reports.php';
	const CONNECTOR_FILE = 'reactwoo-support-connector/reactwoo-support-connector.php';

	public static function register() {
		add_filter( 'all_plugins', array( __CLASS__, 'filter_plugins' ) );
		add_filter( 'plugin_action_links', array( __CLASS__, 'filter_action_links' ), 100, 2 );
		add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'filter_action_links' ), 100, 2 );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'filter_updates' ) );
		add_action( 'admin_menu', array( __CLASS__, 'hide_menus' ), 999 );
		add_action( 'network_admin_menu', array( __CLASS__, 'hide_menus' ), 999 );
		add_action( 'admin_notices', array( __CLASS__, 'silence_notices' ), 0 );
		add_action( 'all_admin_notices', array( __CLASS__, 'silence_notices' ), 0 );
		add_action( 'network_admin_notices', array( __CLASS__, 'silence_notices' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'block_admin_pages' ) );
	}

	public static function hiding() {
		$managed = get_option( 'rw_connector_managed' );
		if ( '1' !== (string) $managed && 1 !== $managed && true !== $managed ) {
			return false;
		}

		return ! self::bypass();
	}

	public static function bypass() {
		if ( defined( 'RW_CONNECTOR_WHITELABEL_BYPASS' ) && RW_CONNECTOR_WHITELABEL_BYPASS ) {
			return true;
		}

		$user = null;
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
		}

		if ( is_object( $user ) && ! empty( $user->ID ) ) {
			if ( function_exists( 'user_can' ) && user_can( $user, 'rw_view_managed_plugins' ) ) {
				return true;
			}
			$email = isset( $user->user_email ) ? (string) $user->user_email : '';
			if ( self::is_reactwoo_support_email( $email ) ) {
				return true;
			}
			if ( function_exists( 'get_user_meta' ) && get_user_meta( (int) $user->ID, 'rw_reactwoo_support', true ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'rw_connector_whitelabel_bypass', false, $user );
	}

	public static function is_reactwoo_support_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return strlen( $email ) > 13 && '@reactwoo.com' === substr( $email, -13 );
	}

	public static function hide_connector() {
		$option  = get_option( 'rw_connector_hide_self' );
		$enabled = ( '1' === (string) $option || 1 === $option || true === $option );

		return (bool) apply_filters( 'rw_connector_whitelabel_hide_connector', $enabled );
	}

	/**
	 * @return string[]
	 */
	public static function hidden_files() {
		if ( ! self::hiding() ) {
			return array();
		}

		$files = array( self::CHILD_FILE, self::REPORTS_FILE );
		if ( self::hide_connector() ) {
			$files[] = self::CONNECTOR_FILE;
		}

		return $files;
	}

	public static function filter_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::hiding() ) {
			return $plugins;
		}

		foreach ( self::hidden_files() as $file ) {
			unset( $plugins[ $file ] );
		}

		return $plugins;
	}

	public static function filter_action_links( $actions, $plugin_file = '' ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		if ( self::hiding() && in_array( (string) $plugin_file, self::hidden_files(), true ) ) {
			return array();
		}

		return $actions;
	}

	public static function filter_updates( $transient ) {
		if ( ! is_object( $transient ) || ! self::hiding() ) {
			return $transient;
		}

		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( empty( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}
			foreach ( self::hidden_files() as $file ) {
				unset( $transient->$bucket[ $file ] );
			}
		}

		return $transient;
	}

	public static function hide_menus() {
		if ( ! self::hiding() ) {
			return;
		}

		remove_submenu_page( 'options-general.php', 'mainwp_child_tab' );
		remove_submenu_page( 'options-general.php', 'mainwp-reports-page' );
		remove_submenu_page( 'options-general.php', 'mainwp-reports-settings' );
		remove_menu_page( 'mainwp_child_tab' );
		remove_menu_page( 'mainwp_tab' );
		remove_menu_page( 'mainwp-reports-page' );
	}

	public static function is_blocked_page( $page ) {
		$page = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $page ) : strtolower( (string) $page );
		if ( '' === $page ) {
			return false;
		}

		$known = array(
			'mainwp_child_tab',
			'mainwp_tab',
			'mainwp-reports-page',
			'mainwp-reports-settings',
			'wp_mainwp_stream_network_settings',
		);
		if ( in_array( $page, $known, true ) ) {
			return true;
		}

		return 0 === strpos( $page, 'mainwp-reports' );
	}

	public static function block_admin_pages() {
		if ( ! self::hiding() ) {
			return;
		}
		if ( function_exists( 'is_admin' ) && ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page || ! self::is_blocked_page( $page ) ) {
			return;
		}

		wp_die( esc_html__( 'This page is not available.', 'reactwoo-support-connector' ), '', array( 'response' => 403 ) );
	}

	public static function silence_notices() {
		if ( ! self::hiding() ) {
			return;
		}

		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices' ) as $hook ) {
			self::remove_hidden_callbacks( $hook );
		}
	}

	private static function remove_hidden_callbacks( $hook ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}
			foreach ( $callbacks as $id => $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( self::path_is_hidden( self::callback_file( $function ) ) ) {
					unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
				}
			}
		}
	}

	private static function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
				return (string) $reflection->getFileName();
			}
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
				return (string) $reflection->getFileName();
			}
			if ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
				return (string) $reflection->getFileName();
			}
		} catch ( Exception $e ) {
			return '';
		}

		return '';
	}

	private static function path_is_hidden( $file ) {
		$file = str_replace( '\\', '/', strtolower( (string) $file ) );
		if ( '' === $file ) {
			return false;
		}

		$dirs = array( 'mainwp-child', 'mainwp-child-reports' );
		if ( self::hide_connector() ) {
			$dirs[] = 'reactwoo-support-connector';
		}
		foreach ( $dirs as $dir ) {
			if ( false !== strpos( $file, '/' . $dir . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
