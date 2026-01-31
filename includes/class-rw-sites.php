<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Sites {
	public static function create_pending_site( array $data ) {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = RW_DB::table( 'managed_sites' );

		$defaults = array(
			'status'        => 'pending',
			'report_enabled'=> 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$payload = array_merge( $defaults, $data );
		$formats = self::build_formats( $payload );

		$inserted = $wpdb->insert( $table, $payload, $formats );
		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public static function get_site( $site_id ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $site_id )
		);
	}

	public static function count_sites_for_subscription( $subscription_id ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE subscription_id = %d AND status != %s",
				$subscription_id,
				'disconnected'
			)
		);
	}

	public static function get_sites_by_user( $user_id ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id )
		);
	}

	public static function get_sites_by_subscription( $subscription_id, array $statuses = array() ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );

		$where  = array( 'subscription_id = %d' );
		$params = array( (int) $subscription_id );

		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$params       = array_merge( $params, array_map( 'strval', $statuses ) );
		}

		$where_sql = implode( ' AND ', $where );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql}", $params )
		);
	}

	public static function update_user_by_subscription( $subscription_id, $user_id ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );
		$now   = current_time( 'mysql', true );

		$query = $wpdb->prepare(
			"UPDATE {$table} SET user_id = %d, updated_at = %s WHERE subscription_id = %d",
			(int) $user_id,
			$now,
			(int) $subscription_id
		);

		return (int) $wpdb->query( $query );
	}

	public static function get_stale_sites( $max_age_seconds ) {
		global $wpdb;

		$table  = RW_DB::table( 'managed_sites' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $max_age_seconds );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE (last_seen IS NULL OR last_seen < %s) AND status = %s ORDER BY last_seen ASC",
				$cutoff,
				'connected'
			)
		);
	}

	public static function update_site( $site_id, array $data ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );

		$data['updated_at'] = current_time( 'mysql', true );
		$formats            = self::build_formats( $data );

		return (bool) $wpdb->update(
			$table,
			$data,
			array( 'id' => $site_id ),
			$formats,
			array( '%d' )
		);
	}

	public static function update_status_by_subscription( $subscription_id, $status, array $exclude_statuses = array( 'disconnected' ) ) {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );
		$now   = current_time( 'mysql', true );

		$where_parts = array( 'subscription_id = %d' );
		$params      = array( (int) $subscription_id );

		if ( ! empty( $exclude_statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude_statuses ), '%s' ) );
			$where_parts[] = "status NOT IN ({$placeholders})";
			$params        = array_merge( $params, array_map( 'strval', $exclude_statuses ) );
		}

		$where_sql = implode( ' AND ', $where_parts );
		$values    = array_merge( array( $status, $now ), $params );
		$query     = $wpdb->prepare(
			"UPDATE {$table} SET status = %s, updated_at = %s WHERE {$where_sql}",
			$values
		);

		return (int) $wpdb->query( $query );
	}

	public static function update_identity( $site_id, array $identity ) {
		$allowed = array(
			'client_name',
			'client_email',
			'client_company',
			'client_phone',
			'client_locale',
			'report_email',
			'report_enabled',
		);

		$update = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $identity ) ) {
				$update[ $field ] = $identity[ $field ];
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		return self::update_site( $site_id, $update );
	}

	private static function column_formats() {
		return array(
			'id'               => '%d',
			'user_id'          => '%d',
			'subscription_id'  => '%d',
			'site_url'         => '%s',
			'site_name'        => '%s',
			'status'           => '%s',
			'fingerprint'      => '%s',
			'maint_site_id'    => '%d',
			'connector_version'=> '%s',
			'wp_version'       => '%s',
			'php_version'      => '%s',
			'last_seen'        => '%s',
			'last_check_at'    => '%s',
			'last_sync_at'     => '%s',
			'last_reconnect_at'=> '%s',
			'client_name'      => '%s',
			'client_email'     => '%s',
			'client_company'   => '%s',
			'client_phone'     => '%s',
			'client_locale'    => '%s',
			'report_email'     => '%s',
			'report_enabled'   => '%d',
			'created_at'       => '%s',
			'updated_at'       => '%s',
		);
	}

	private static function build_formats( array $data ) {
		$formats = self::column_formats();
		$result  = array();

		foreach ( $data as $key => $value ) {
			if ( isset( $formats[ $key ] ) ) {
				$result[] = $formats[ $key ];
				continue;
			}

			$result[] = is_int( $value ) ? '%d' : '%s';
		}

		return $result;
	}
}
