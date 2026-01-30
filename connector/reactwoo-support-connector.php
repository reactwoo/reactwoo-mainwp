<?php
/**
 * Plugin Name: ReactWoo Support Connector
 * Description: Client site connector for ReactWoo maintenance enrollment.
 * Version: 0.1.0
 * Author: ReactWoo
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RW_CONNECTOR_VERSION', '0.1.0' );
define( 'RW_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'RW_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );

require_once RW_CONNECTOR_DIR . 'includes/class-rw-connector.php';

register_activation_hook( __FILE__, array( 'RW_Connector', 'activate' ) );

RW_Connector::register();
