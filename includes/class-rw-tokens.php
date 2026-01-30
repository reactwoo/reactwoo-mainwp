<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Tokens {
	const DEFAULT_TTL_HOURS = 24;

	public static function create_token( $managed_site_id, $ttl_hours = self::DEFAULT_TTL_HOURS ) {
		global $wpdb;

		$token      = self::random_hex( 32 );
		$token_hash = hash( 'sha256', $token );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( (int) $ttl_hours * HOUR_IN_SECONDS ) );
		$now        = current_time( 'mysql', true );
		$table      = RW_DB::table( 'site_tokens' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'managed_site_id' => (int) $managed_site_id,
				'token_hash'      => $token_hash,
				'expires_at'      => $expires_at,
				'created_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'rw_token_create_failed', 'Unable to create token.', array( 'status' => 500 ) );
		}

		return array(
			'token'      => $token,
			'token_hash' => $token_hash,
			'expires_at' => $expires_at,
		);
	}

	public static function verify_token( $token ) {
		global $wpdb;

		$token_hash = hash( 'sha256', $token );
		$table      = RW_DB::table( 'site_tokens' );
		$row        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $token_hash )
		);

		if ( ! $row ) {
			return new WP_Error( 'rw_token_invalid', 'Token is invalid.', array( 'status' => 404 ) );
		}

		if ( ! empty( $row->used_at ) ) {
			return new WP_Error( 'rw_token_used', 'Token already used.', array( 'status' => 409 ) );
		}

		if ( strtotime( $row->expires_at ) < time() ) {
			return new WP_Error( 'rw_token_expired', 'Token has expired.', array( 'status' => 410 ) );
		}

		$wpdb->update(
			$table,
			array( 'used_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		return $row;
	}

	public static function generate_site_secret() {
		return hash( 'sha256', self::random_hex( 32 ) );
	}

	public static function store_site_secret( $site_id, $secret ) {
		update_option( self::secret_option_key( $site_id ), $secret, false );
	}

	public static function get_site_secret( $site_id ) {
		return get_option( self::secret_option_key( $site_id ) );
	}

	private static function secret_option_key( $site_id ) {
		return 'rw_site_secret_' . (int) $site_id;
	}

	private static function random_hex( $bytes ) {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( Exception $e ) {
			return wp_generate_password( $bytes * 2, false, false );
		}
	}
}
