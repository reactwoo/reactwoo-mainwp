<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_DB {
	const DB_VERSION = '1.0';

	public static function table( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'rw_maint_' . $suffix;
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'rw_maint_db_version' );
		if ( self::DB_VERSION !== $installed ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sites_table = self::table( 'sites' );
		$audit_table = self::table( 'audit_log' );

		$sites_sql = "CREATE TABLE {$sites_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			portal_site_id BIGINT(20) UNSIGNED NOT NULL,
			subscription_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			site_url VARCHAR(255) NOT NULL,
			mainwp_site_id BIGINT(20) UNSIGNED DEFAULT NULL,
			status ENUM('active','suspended','disconnected','error') NOT NULL DEFAULT 'active',
			client_name VARCHAR(190) DEFAULT NULL,
			client_email VARCHAR(190) DEFAULT NULL,
			client_company VARCHAR(190) DEFAULT NULL,
			client_locale VARCHAR(10) DEFAULT NULL,
			report_email VARCHAR(190) DEFAULT NULL,
			report_enabled TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY portal_site_id (portal_site_id),
			KEY subscription_id (subscription_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		$audit_sql = "CREATE TABLE {$audit_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(190) NOT NULL,
			portal_site_id BIGINT(20) UNSIGNED DEFAULT NULL,
			subscription_id BIGINT(20) UNSIGNED DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			message LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY portal_site_id (portal_site_id),
			KEY subscription_id (subscription_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sites_sql );
		dbDelta( $audit_sql );

		update_option( 'rw_maint_db_version', self::DB_VERSION );
	}

	public static function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
