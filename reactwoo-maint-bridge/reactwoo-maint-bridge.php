<?php
/**
 * Plugin Name: ReactWoo Maintenance Bridge
 * Description: MainWP automation and site lifecycle enforcement for ReactWoo.
 * Version: 0.1.0
 * Author: ReactWoo
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RW_MAINT_VERSION', '0.1.0' );
define( 'RW_MAINT_DIR', plugin_dir_path( __FILE__ ) );
define( 'RW_MAINT_URL', plugin_dir_url( __FILE__ ) );

require_once RW_MAINT_DIR . 'includes/class-rw-maint-bridge.php';

register_activation_hook( __FILE__, array( 'RW_Maint_Bridge', 'activate' ) );

RW_Maint_Bridge::register();
