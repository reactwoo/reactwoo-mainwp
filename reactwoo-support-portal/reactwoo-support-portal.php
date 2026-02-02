<?php
/**
 * Plugin Name: ReactWoo Support Portal
 * Description: Support portal integration for the ReactWoo maintenance platform.
 * Version: 0.1.0
 * Author: ReactWoo
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RW_PORTAL_VERSION', '0.1.0' );
define( 'RW_PORTAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'RW_PORTAL_URL', plugin_dir_url( __FILE__ ) );

require_once RW_PORTAL_DIR . 'includes/class-rw-portal.php';

register_activation_hook( __FILE__, array( 'RW_Portal', 'activate' ) );

RW_Portal::register();
