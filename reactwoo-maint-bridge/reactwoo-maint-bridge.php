<?php
/**
 * Plugin Name: ReactWoo Maintenance Bridge
 * Description: MainWP automation and site lifecycle enforcement for ReactWoo.
 * Version: 0.3.0
 * Author: ReactWoo
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RW_MAINT_VERSION', '0.3.0' );
define( 'RW_MAINT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RW_MAINT_URL', plugin_dir_url( __FILE__ ) );
/** Short name for admin menu (sellable product). Use "MainWP Bridge" or "Woo–MainWP Bridge". */
define( 'RW_MAINT_MENU_NAME', 'MainWP Bridge' );

require_once RW_MAINT_DIR . 'includes/class-rw-maint-bridge.php';

register_activation_hook( __FILE__, array( 'RW_Maint_Bridge', 'activate' ) );

RW_Maint_Bridge::register();
