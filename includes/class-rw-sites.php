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
