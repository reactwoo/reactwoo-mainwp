<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RW_Portal {
	const DB_VERSION = '1.0';

	public static function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'init' ) );
	}

	public static function init() {
		self::load_dependencies();
		RW_DB::maybe_upgrade();
		RW_Rest_Controller::register_routes();
	}

	public static function activate() {
		self::load_dependencies();
		RW_DB::create_tables();
	}

	private static function load_dependencies() {
		require_once RW_PORTAL_DIR . 'includes/class-rw-db.php';
		require_once RW_PORTAL_DIR . 'includes/class-rw-sites.php';
		require_once RW_PORTAL_DIR . 'includes/class-rw-tokens.php';
		require_once RW_PORTAL_DIR . 'includes/class-rw-identity.php';
		require_once RW_PORTAL_DIR . 'includes/class-rw-security.php';
		require_once RW_PORTAL_DIR . 'includes/class-rw-audit.php';
		require_once RW_PORTAL_DIR . 'includes/rest/class-rw-rest-controller.php';
	}
}
