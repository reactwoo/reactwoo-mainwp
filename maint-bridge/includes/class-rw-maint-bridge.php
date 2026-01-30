<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RW_Maint_Bridge {
	public static function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'init' ) );
	}

	public static function init() {
		self::load_dependencies();
		RW_Maint_DB::maybe_upgrade();
		RW_Maint_Cleanup::register();
		RW_Maint_MainWP::register();
		RW_Maint_Rest_Controller::register_routes();
	}

	public static function activate() {
		self::load_dependencies();
		RW_Maint_DB::create_tables();
	}

	private static function load_dependencies() {
		require_once RW_MAINT_DIR . 'includes/class-rw-maint-db.php';
		require_once RW_MAINT_DIR . 'includes/class-rw-maint-sites.php';
		require_once RW_MAINT_DIR . 'includes/class-rw-maint-audit.php';
		require_once RW_MAINT_DIR . 'includes/class-rw-maint-security.php';
		require_once RW_MAINT_DIR . 'includes/class-rw-maint-cleanup.php';
		require_once RW_MAINT_DIR . 'includes/class-rw-maint-mainwp.php';
		require_once RW_MAINT_DIR . 'includes/rest/class-rw-maint-rest-controller.php';
	}
}
