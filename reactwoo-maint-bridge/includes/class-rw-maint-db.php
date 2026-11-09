<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_DB {
	const DB_VERSION = '1.3';

	/**
	 * True once this request knows the audit table is present.
	 *
	 * @var bool
	 */
	private static $audit_log_known = false;

	public static function table( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . 'rw_maint_' . $suffix;
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'rw_maint_db_version' );
		if ( self::DB_VERSION !== $installed || ! self::audit_log_ready() ) {
			self::create_tables();
			self::mark_audit_log_ready();
		}
	}

	/**
	 * Whether the audit table is known to exist without a SHOW TABLES on later requests.
	 *
	 * @return bool
	 */
	private static function audit_log_ready() {
		if ( self::$audit_log_known ) {
			return true;
		}

		$cached = get_transient( 'rw_maint_audit_log_ready' );
		if ( $cached ) {
			self::$audit_log_known = true;
			return true;
		}

		if ( self::table_exists( self::table( 'audit_log' ) ) ) {
			self::mark_audit_log_ready();
			return true;
		}

		return false;
	}

	private static function mark_audit_log_ready() {
		self::$audit_log_known = true;
		$ttl                   = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		set_transient( 'rw_maint_audit_log_ready', 1, $ttl );
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sites_table   = self::table( 'sites' );
		$audit_table   = self::table( 'audit_log' );
		$idem_table    = self::table( 'idempotency' );
		$reports_table = self::table( 'reports' );
		$notice_table  = self::table( 'report_notices' );
		$action_table  = self::table( 'report_actions' );

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
			last_backup_ref VARCHAR(64) DEFAULT NULL,
			last_backup_at DATETIME DEFAULT NULL,
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

		$idem_sql = "CREATE TABLE {$idem_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			idempotency_key VARCHAR(128) NOT NULL,
			body_hash CHAR(64) NOT NULL,
			http_status SMALLINT NOT NULL DEFAULT 0,
			response_body LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key)
		) {$charset_collate};";

		$reports_sql = "CREATE TABLE {$reports_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			portal_site_id BIGINT(20) UNSIGNED NOT NULL,
			mainwp_site_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			subscription_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			reported_at DATETIME NOT NULL,
			received_at DATETIME NOT NULL,
			phase VARCHAR(20) NOT NULL DEFAULT '',
			criticality VARCHAR(20) NOT NULL DEFAULT '',
			backup_ref VARCHAR(64) DEFAULT NULL,
			body_hash CHAR(64) NOT NULL,
			payload LONGTEXT NOT NULL,
			triage LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_report (portal_site_id, body_hash),
			KEY user_id (user_id),
			KEY mainwp_site_id (mainwp_site_id),
			KEY subscription_id (subscription_id)
		) {$charset_collate};";

		$notice_sql = "CREATE TABLE {$notice_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT(20) UNSIGNED NOT NULL,
			portal_site_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			subscription_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			notice_type VARCHAR(32) NOT NULL,
			sender VARCHAR(16) NOT NULL DEFAULT 'portal',
			recipient TEXT NOT NULL,
			send_after DATETIME NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			subject VARCHAR(190) NOT NULL DEFAULT '',
			body LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			sent_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY due_notice (status, sender, send_after),
			KEY report_id (report_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		$action_sql = "CREATE TABLE {$action_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT(20) UNSIGNED NOT NULL,
			portal_site_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			subscription_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			mainwp_site_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(20) NOT NULL,
			backup_ref VARCHAR(64) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT '',
			detail VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY report_id (report_id),
			KEY portal_site_id (portal_site_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sites_sql );
		dbDelta( $audit_sql );
		dbDelta( $idem_sql );
		dbDelta( $reports_sql );
		dbDelta( $notice_sql );
		dbDelta( $action_sql );

		update_option( 'rw_maint_db_version', self::DB_VERSION );
	}

	public static function table_exists( $table ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
