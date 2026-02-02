<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Sites {
	public static function create_site( array $data ) {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = RW_Maint_DB::table( 'sites' );

		$defaults = array(
			'status'     => 'active',
			'created_at' => $now,
			'updated_at' => $now,
		);

		$payload = array_merge( $defaults, $data );
		$formats = self::build_formats( $payload );

		$inserted = $wpdb->insert( $table, $payload, $formats );
		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public static function get_by_portal_site_id( $portal_site_id ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'sites' );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE portal_site_id = %d", $portal_site_id )
		);
	}

	public static function get_site( $site_id ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'sites' );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $site_id )
		);
	}

	public static function update_site( $site_id, array $data ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'sites' );

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

	public static function update_status( $portal_site_id, $status ) {
		$site = self::get_by_portal_site_id( $portal_site_id );
		if ( ! $site ) {
			return false;
		}

		return self::update_site( (int) $site->id, array( 'status' => $status ) );
	}

	private static function column_formats() {
		return array(
			'id'               => '%d',
			'portal_site_id'   => '%d',
			'subscription_id'  => '%d',
			'user_id'          => '%d',
			'site_url'         => '%s',
			'mainwp_site_id'   => '%d',
			'status'           => '%s',
			'client_name'      => '%s',
			'client_email'     => '%s',
			'client_company'   => '%s',
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
