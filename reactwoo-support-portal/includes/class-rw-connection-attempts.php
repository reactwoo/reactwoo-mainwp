<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connection_Attempts {
	const TTL_SECONDS = 600;

	/**
	 * Start an attempt. The plaintext state is returned once and stored as a hash.
	 *
	 * @param object $site Managed site row.
	 * @return array|WP_Error
	 */
	public static function create( $site ) {
		global $wpdb;

		if ( ! $site || empty( $site->id ) ) {
			return new WP_Error( 'rw_site_missing', 'Managed site not found.', array( 'status' => 404 ) );
		}

		$state      = self::random_hex( 32 );
		$state_hash = hash( 'sha256', $state );
		$public_id  = self::uuid();
		$now        = current_time( 'mysql', true );
		$expires    = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );
		$table      = RW_DB::table( 'connection_attempts' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'public_id'         => $public_id,
				'managed_site_id'   => (int) $site->id,
				'user_id'           => (int) $site->user_id,
				'subscription_id'   => (int) $site->subscription_id,
				'expected_site_url' => (string) $site->site_url,
				'state_hash'        => $state_hash,
				'status'            => RW_Connection_State::AWAITING_AUTHORISATION,
				'attempt_count'     => 1,
				'expires_at'        => $expires,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'rw_connection_attempt_failed', 'Unable to start website connection.', array( 'status' => 500 ) );
		}

		return array(
			'id'         => (int) $wpdb->insert_id,
			'public_id'  => $public_id,
			'state'      => $state,
			'expires_at' => $expires,
		);
	}

	/**
	 * Atomically move a valid, unused state into authorising.
	 *
	 * @param string $state Plaintext state from the callback.
	 * @return object|WP_Error Attempt row.
	 */
	public static function consume( $state ) {
		global $wpdb;

		$state = (string) $state;
		if ( '' === $state ) {
			return new WP_Error( 'rw_connection_state_missing', 'Connection state is missing.', array( 'status' => 400 ) );
		}

		$table      = RW_DB::table( 'connection_attempts' );
		$state_hash = hash( 'sha256', $state );
		$now_gmt    = gmdate( 'Y-m-d H:i:s' );
		$now        = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, updated_at = %s
				WHERE state_hash = %s
					AND status = %s
					AND expires_at >= %s
					AND completed_at IS NULL",
				RW_Connection_State::AUTHORISING,
				$now,
				$state_hash,
				RW_Connection_State::AWAITING_AUTHORISATION,
				$now_gmt
			)
		);

		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'rw_connection_state_invalid', 'This connection link is no longer valid.', array( 'status' => 409 ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE state_hash = %s", $state_hash )
		);

		if ( ! $row ) {
			return new WP_Error( 'rw_connection_state_invalid', 'This connection link is no longer valid.', array( 'status' => 409 ) );
		}

		return $row;
	}

	public static function update_attempt( $attempt_id, array $data ) {
		global $wpdb;

		$allowed = array(
			'status'            => '%s',
			'rest_root'         => '%s',
			'app_id'            => '%s',
			'challenge_hash'    => '%s',
			'challenge_used_at' => '%s',
			'completed_at'      => '%s',
			'attempt_count'     => '%d',
		);

		$update  = array();
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$update[ $key ] = $value;
			$formats[]      = $allowed[ $key ];
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		return false !== $wpdb->update(
			RW_DB::table( 'connection_attempts' ),
			$update,
			array( 'id' => (int) $attempt_id ),
			$formats,
			array( '%d' )
		);
	}

	public static function consume_challenge( $challenge ) {
		global $wpdb;

		$challenge = (string) $challenge;
		if ( '' === $challenge ) {
			return new WP_Error( 'rw_challenge_missing', 'Enrolment challenge is missing.', array( 'status' => 400 ) );
		}

		$table = RW_DB::table( 'connection_attempts' );
		$hash  = hash( 'sha256', $challenge );
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET challenge_used_at = %s, updated_at = %s
				WHERE challenge_hash = %s
					AND challenge_used_at IS NULL
					AND expires_at >= %s",
				$now,
				$now,
				$hash,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'rw_challenge_invalid', 'Enrolment challenge is no longer valid.', array( 'status' => 409 ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE challenge_hash = %s", $hash )
		);

		if ( ! $row ) {
			return new WP_Error( 'rw_challenge_invalid', 'Enrolment challenge is no longer valid.', array( 'status' => 409 ) );
		}

		return $row;
	}

	public static function get_by_state( $state ) {
		global $wpdb;

		$table = RW_DB::table( 'connection_attempts' );
		$hash  = hash( 'sha256', (string) $state );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE state_hash = %s", $hash )
		);
	}

	public static function get_by_public_id( $public_id ) {
		global $wpdb;

		$table = RW_DB::table( 'connection_attempts' );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", (string) $public_id )
		);
	}

	private static function uuid() {
		try {
			$data = random_bytes( 16 );
		} catch ( Exception $e ) {
			$data = '';
			for ( $i = 0; $i < 16; $i++ ) {
				$data .= chr( wp_rand( 0, 255 ) );
			}
		}

		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	private static function random_hex( $bytes ) {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( Exception $e ) {
			return wp_generate_password( $bytes * 2, false, false );
		}
	}
}
