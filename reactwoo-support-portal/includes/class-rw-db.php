<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_DB {
	const DB_VERSION = '1.6';

	public static function table( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'rw_' . $suffix;
	}

	public static function maybe_upgrade() {
		$installed = (string) get_option( 'rw_portal_db_version' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();

		if ( '' === $installed || version_compare( $installed, '1.4', '<' ) ) {
			self::backfill_connection_state();
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate     = $wpdb->get_charset_collate();
		$managed_sites_table = self::table( 'managed_sites' );
		$tokens_table        = self::table( 'site_tokens' );
		$audit_table         = self::table( 'audit_log' );
		$ledger_table        = self::table( 'support_ledger' );

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
			enroll_url_override TINYINT(1) NOT NULL DEFAULT 0,
			client_name VARCHAR(190) DEFAULT NULL,
			client_email VARCHAR(190) DEFAULT NULL,
			client_company VARCHAR(190) DEFAULT NULL,
			client_phone VARCHAR(50) DEFAULT NULL,
			client_locale VARCHAR(10) DEFAULT NULL,
			report_email VARCHAR(190) DEFAULT NULL,
			report_enabled TINYINT(1) NOT NULL DEFAULT 1,
			connection_state VARCHAR(32) NOT NULL DEFAULT 'not_started',
			connection_method VARCHAR(32) DEFAULT NULL,
			connected_at DATETIME DEFAULT NULL,
			last_connection_attempt_at DATETIME DEFAULT NULL,
			connection_error_code VARCHAR(64) DEFAULT NULL,
			connection_error_message VARCHAR(255) DEFAULT NULL,
			connector_protocol VARCHAR(16) DEFAULT NULL,
			mainwp_child_version VARCHAR(50) DEFAULT NULL,
			last_bootstrap_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY subscription_id (subscription_id),
			KEY status (status),
			KEY connection_state (connection_state)
		) {$charset_collate};";

		$attempts_table    = self::table( 'connection_attempts' );
		$attempts_sql      = "CREATE TABLE {$attempts_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			managed_site_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			subscription_id BIGINT(20) UNSIGNED NOT NULL,
			expected_site_url VARCHAR(255) NOT NULL,
			rest_root VARCHAR(255) DEFAULT NULL,
			app_id CHAR(36) DEFAULT NULL,
			state_hash CHAR(64) NOT NULL,
			challenge_hash CHAR(64) DEFAULT NULL,
			challenge_used_at DATETIME DEFAULT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'awaiting_authorisation',
			attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY state_hash (state_hash),
			KEY managed_site_id (managed_site_id),
			KEY expires_at (expires_at)
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

		$ledger_sql = "CREATE TABLE {$ledger_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			subscription_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			managed_site_id BIGINT(20) UNSIGNED DEFAULT NULL,
			order_id BIGINT(20) UNSIGNED DEFAULT NULL,
			order_item_id BIGINT(20) UNSIGNED DEFAULT NULL,
			minutes_delta INT NOT NULL,
			credit_type ENUM('plan_allowance','purchased','usage','adjustment','expiry') NOT NULL,
			reference VARCHAR(190) NOT NULL DEFAULT '',
			expires_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY subscription_id (subscription_id),
			KEY credit_lookup (subscription_id, credit_type, reference),
			KEY order_item_id (order_item_id)
		) {$charset_collate};";

		dbDelta( $managed_sites_sql );
		dbDelta( $attempts_sql );
		dbDelta( $tokens_sql );
		dbDelta( $audit_sql );
		dbDelta( $ledger_sql );

		update_option( 'rw_portal_db_version', self::DB_VERSION );
	}

	/**
	 * Existing V1 rows keep their customer meaning. New provisioning uses connection_state.
	 */
	public static function backfill_connection_state() {
		global $wpdb;

		$table = self::table( 'managed_sites' );
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$wpdb->query(
			"UPDATE {$table}
			SET connection_state = 'connected', connection_method = 'v1_token'
			WHERE status IN ('connected','suspended')
				AND (connection_state = '' OR connection_state = 'not_started')"
		);

		$wpdb->query(
			"UPDATE {$table}
			SET connection_state = 'failed'
			WHERE status = 'error'
				AND (connection_state = '' OR connection_state = 'not_started')"
		);
	}

	public static function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
