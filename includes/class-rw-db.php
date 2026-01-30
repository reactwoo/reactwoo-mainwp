<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_DB {
	const DB_VERSION = '1.2';

	public static function table( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'rw_' . $suffix;
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'rw_portal_db_version' );
		if ( self::DB_VERSION !== $installed ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate     = $wpdb->get_charset_collate();
		$managed_sites_table = self::table( 'managed_sites' );
		$tokens_table        = self::table( 'site_tokens' );
		$audit_table         = self::table( 'audit_log' );

		$managed_sites_sql = "CREATE TABLE {$managed_sites_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			subscription_id BIGINT(20) UNSIGNED NOT NULL,
			site_url VARCHAR(255) NOT NULL,
			site_name VARCHAR(190) NOT NULL,
			status ENUM('pending','connected','suspended','disconnected','error') NOT NULL DEFAULT 'pending',
			fingerprint VARCHAR(128) DEFAULT NULL,
			maint_site_id BIGINT(20) UNSIGNED DEFAULT NULL,
			connector_version VARCHAR(50) DEFAULT NULL,
			wp_version VARCHAR(50) DEFAULT NULL,
			php_version VARCHAR(50) DEFAULT NULL,
			last_seen DATETIME DEFAULT NULL,
			last_check_at DATETIME DEFAULT NULL,
			last_sync_at DATETIME DEFAULT NULL,
			last_reconnect_at DATETIME DEFAULT NULL,
			client_name VARCHAR(190) DEFAULT NULL,
			client_email VARCHAR(190) DEFAULT NULL,
			client_company VARCHAR(190) DEFAULT NULL,
			client_phone VARCHAR(50) DEFAULT NULL,
			client_locale VARCHAR(10) DEFAULT NULL,
			report_email VARCHAR(190) DEFAULT NULL,
			report_enabled TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY subscription_id (subscription_id),
			KEY status (status)
		) {$charset_collate};";

		$tokens_sql = "CREATE TABLE {$tokens_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			managed_site_id BIGINT(20) UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY managed_site_id (managed_site_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$audit_sql = "CREATE TABLE {$audit_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(190) NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			subscription_id BIGINT(20) UNSIGNED DEFAULT NULL,
			managed_site_id BIGINT(20) UNSIGNED DEFAULT NULL,
			message LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY user_id (user_id),
			KEY subscription_id (subscription_id),
			KEY managed_site_id (managed_site_id)
		) {$charset_collate};";

		dbDelta( $managed_sites_sql );
		dbDelta( $tokens_sql );
		dbDelta( $audit_sql );

		update_option( 'rw_portal_db_version', self::DB_VERSION );
	}

	public static function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
