<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only copy of one site's synced MainWP records.
 *
 * Queries run only when the stored MainWP id still belongs to this site's URL.
 * Backup tasks are kept only when that id is named on the task.
 */
class RW_Maint_MainWP_Status {
	const LOG_LIMIT = 80;

	/**
	 * @param object $site Bridge site row.
	 * @return array<string,mixed>
	 */
	public static function collect( $site ) {
		$sources = array(
			'website'      => array(),
			'sync'         => array(),
			'options'      => array(),
			'monitors'     => array(),
			'logs'         => array(),
			'audit'        => array(),
			'backup_tasks' => array(),
		);

		$portal_site_id = is_object( $site ) && isset( $site->portal_site_id ) ? (int) $site->portal_site_id : 0;
		$sources['audit'] = self::audit_rows( $portal_site_id );

		$mainwp_id = is_object( $site ) && isset( $site->mainwp_site_id ) ? (int) $site->mainwp_site_id : 0;
		if ( $mainwp_id <= 0 || ! self::mainwp_url_matches( $site ) ) {
			return $sources;
		}

		$website = self::safe_row( self::table( 'wp' ), 'id', $mainwp_id );
		if ( $website ) {
			$sources['website'] = self::public_website( $website );
		}

		$sync = self::safe_row( self::table( 'wp_sync' ), 'wpid', $mainwp_id );
		if ( $sync ) {
			$sources['sync'] = self::public_sync( $sync );
		}

		$sources['options']      = self::option_rows( $mainwp_id );
		$sources['monitors']     = self::monitor_rows( $mainwp_id );
		$sources['logs']         = self::log_rows( $mainwp_id );
		$sources['backup_tasks'] = self::backup_tasks( $mainwp_id );

		return $sources;
	}

	/**
	 * The same guard check and sync use: the stored id must still be this URL.
	 *
	 * @param object $site Bridge site row.
	 * @return bool
	 */
	private static function mainwp_url_matches( $site ) {
		if ( ! is_object( $site ) || empty( $site->site_url ) || ! class_exists( 'RW_Maint_MainWP' ) ) {
			return false;
		}

		return RW_Maint_MainWP::current_mainwp_target( $site );
	}

	/**
	 * @param string $suffix MainWP table suffix.
	 * @return string
	 */
	private static function table( $suffix ) {
		global $wpdb;

		$allowed = array( 'wp', 'wp_sync', 'wp_options', 'wp_backup', 'wp_logs', 'wp_actions', 'monitors' );
		if ( ! is_object( $wpdb ) || ! in_array( $suffix, $allowed, true ) ) {
			return '';
		}

		return $wpdb->prefix . 'mainwp_' . $suffix;
	}

	/**
	 * @param array<string,mixed> $row Website row.
	 * @return array<string,mixed>
	 */
	private static function public_website( array $row ) {
		$keep = array(
			'plugin_upgrades',
			'theme_upgrades',
			'premium_upgrades',
			'securityIssues',
			'plugins',
			'themes',
			'users',
			'pages',
			'backups',
			'offline_check_result',
			'offline_checks_last',
			'wp_upgrades',
		);
		$public = array();
		foreach ( $keep as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$public[ $key ] = $row[ $key ];
			}
		}

		return $public;
	}

	/**
	 * @param array<string,mixed> $row Sync row.
	 * @return array<string,mixed>
	 */
	private static function public_sync( array $row ) {
		$keep = array(
			'version',
			'wpversion',
			'dtsSync',
			'dtsSyncStart',
			'dtsAutomaticSync',
			'health_value',
			'health_site_status',
			'phpversion',
			'last_post_gmt',
		);
		$public = array();
		foreach ( $keep as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$public[ $key ] = $row[ $key ];
			}
		}

		return $public;
	}

	/**
	 * @param int $mainwp_id MainWP site id.
	 * @return array<int,array{name:string,value:mixed}>
	 */
	private static function option_rows( $mainwp_id ) {
		$table = self::table( 'wp_options' );
		if ( '' === $table || ! self::table_ready( $table ) ) {
			return array();
		}

		global $wpdb;
		$rows = self::safe_results(
			$wpdb->prepare( "SELECT name, value FROM {$table} WHERE wpid = %d", $mainwp_id )
		);
		$options = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['name'] ) ) {
				continue;
			}
			$options[] = array(
				'name'  => (string) $row['name'],
				'value' => isset( $row['value'] ) ? $row['value'] : null,
			);
		}

		return $options;
	}

	/**
	 * @param int $mainwp_id MainWP site id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function monitor_rows( $mainwp_id ) {
		$table = self::table( 'monitors' );
		if ( '' === $table || ! self::table_ready( $table ) ) {
			return array();
		}

		global $wpdb;
		return self::safe_results(
			$wpdb->prepare(
				"SELECT last_status, last_http_code, lasttime_check, active, issub FROM {$table} WHERE wpid = %d ORDER BY issub ASC LIMIT 5",
				$mainwp_id
			)
		);
	}

	/**
	 * @param int $mainwp_id MainWP site id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function log_rows( $mainwp_id ) {
		$logs  = array();
		$table = self::table( 'wp_logs' );
		if ( '' !== $table && self::table_ready( $table ) ) {
			global $wpdb;
			$logs = self::safe_results(
				$wpdb->prepare(
					"SELECT action, context, item, connector, created FROM {$table} WHERE site_id = %d ORDER BY created DESC LIMIT %d",
					$mainwp_id,
					self::LOG_LIMIT
				)
			);
		}

		$actions = self::table( 'wp_actions' );
		if ( '' !== $actions && self::table_ready( $actions ) ) {
			global $wpdb;
			$older = self::safe_results(
				$wpdb->prepare(
					"SELECT action, context, summary, created, meta_data AS item FROM {$actions} WHERE wpid = %d ORDER BY created DESC LIMIT %d",
					$mainwp_id,
					self::LOG_LIMIT
				)
			);
			$logs = array_merge( $logs, $older );
		}

		return $logs;
	}

	/**
	 * @param int $mainwp_id MainWP site id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function backup_tasks( $mainwp_id ) {
		$table = self::table( 'wp_backup' );
		if ( '' === $table || ! self::table_ready( $table ) ) {
			return array();
		}

		global $wpdb;
		$rows  = self::safe_results(
			$wpdb->prepare(
				"SELECT schedule, type, last, last_run, completed, backup_errors, sites FROM {$table} ORDER BY last_run DESC LIMIT %d",
				200
			)
		);
		$tasks = array();
		foreach ( $rows as $row ) {
			if ( ! RW_Maint_Site_Snapshot::backup_task_includes_site( isset( $row['sites'] ) ? $row['sites'] : '', $mainwp_id ) ) {
				continue;
			}
			unset( $row['sites'] );
			$tasks[] = $row;
		}

		return $tasks;
	}

	/**
	 * @param int $portal_site_id Portal site id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function audit_rows( $portal_site_id ) {
		if ( $portal_site_id <= 0 || ! class_exists( 'RW_Maint_DB' ) ) {
			return array();
		}

		global $wpdb;
		$table = RW_Maint_DB::table( 'audit_log' );
		if ( ! is_object( $wpdb ) || ! self::table_ready( $table ) ) {
			return array();
		}

		return self::safe_results(
			$wpdb->prepare(
				"SELECT event_type, message, created_at FROM {$table} WHERE portal_site_id = %d ORDER BY created_at DESC LIMIT %d",
				$portal_site_id,
				self::LOG_LIMIT
			)
		);
	}

	/**
	 * @param string $table Table name.
	 * @param string $column id or wpid.
	 * @param int    $id Row id.
	 * @return array<string,mixed>|null
	 */
	private static function safe_row( $table, $column, $id ) {
		if ( '' === $table || ! self::table_ready( $table ) || ! in_array( $column, array( 'id', 'wpid' ), true ) ) {
			return null;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %d LIMIT 1", $id );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! empty( $wpdb->last_error ) || ! is_array( $row ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * @param string $sql Prepared SQL.
	 * @return array<int,array<string,mixed>>
	 */
	private static function safe_results( $sql ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! is_string( $sql ) || '' === $sql ) {
			return array();
		}

		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$clean[] = $row;
			} elseif ( is_object( $row ) ) {
				$clean[] = get_object_vars( $row );
			}
		}

		return $clean;
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_ready( $table ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! empty( $wpdb->last_error ) ) {
			return false;
		}

		return $found === $table;
	}
}
