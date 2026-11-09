<?php
/**
 * Updates started through the bridge wait for a fresh on-site snapshot.
 *
 * The hub's own daily automatic updates do not pass through this gate. Those
 * are logged as unprotected. Turning them off is a production setting and is
 * not changed here. MainWP's backup-before-upgrade flag is recorded, but it is
 * not treated as a ReactWoo snapshot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Update_Guard {
	/**
	 * Default age, in seconds, of a snapshot that may still gate an update.
	 * Override with the option rw_maint_snapshot_fresh_seconds, or the filter
	 * of the same name. This is the only place the number is defined.
	 */
	const FRESH_SECONDS = 1800;
	const FRESH_OPTION  = 'rw_maint_snapshot_fresh_seconds';
	const AUDIT_HOOK    = 'rw_maint_audit_auto_updates';

	/**
	 * Freshness window in seconds. The option and filter replace the default.
	 *
	 * @return int
	 */
	public static function fresh_seconds() {
		$window = self::FRESH_SECONDS;
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( self::FRESH_OPTION, '' );
			if ( is_numeric( $stored ) && (int) $stored > 0 ) {
				$window = (int) $stored;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$window = (int) apply_filters( self::FRESH_OPTION, $window );
		}

		return $window > 0 ? $window : self::FRESH_SECONDS;
	}

	public static function register() {
		add_action( self::AUDIT_HOOK, array( __CLASS__, 'audit_auto_updates' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::AUDIT_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::AUDIT_HOOK );
		}
	}

	/**
	 * @param string   $backup_ref Snapshot reference.
	 * @param string   $snapshot_at ISO-8601 or unix time.
	 * @param int|null $now Unix time.
	 * @return array{backup_ref:string,backup_at:string}|WP_Error
	 */
	public static function decide( $backup_ref, $snapshot_at, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$ref = trim( (string) $backup_ref );
		if ( ! self::valid_reference( $ref ) || ! self::snapshot_is_fresh( $snapshot_at, $now, self::fresh_seconds() ) ) {
			return new WP_Error(
				'rw_update_unprotected',
				'Updates were not started because there is no fresh snapshot for this website.',
				array( 'status' => 409 )
			);
		}

		return array(
			'backup_ref' => $ref,
			'backup_at'  => gmdate( 'Y-m-d H:i:s', self::snapshot_timestamp( $snapshot_at ) ),
		);
	}

	/**
	 * @param string $reference Candidate reference.
	 * @return bool
	 */
	public static function valid_reference( $reference ) {
		return 1 === preg_match( '/^rwsnap_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{8,32}$/', (string) $reference );
	}

	/**
	 * @param string $created_at Snapshot time.
	 * @param int    $now Unix time.
	 * @param int    $window Seconds.
	 * @return bool
	 */
	public static function snapshot_is_fresh( $created_at, $now, $window ) {
		$created = self::snapshot_timestamp( $created_at );
		if ( $created <= 0 ) {
			return false;
		}
		$now = (int) $now;
		if ( $created > $now + 120 ) {
			return false;
		}

		return ( $now - $created ) <= (int) $window;
	}

	/**
	 * @param mixed $automatic_update MainWP automatic_update value.
	 * @param bool  $readable Whether that column could be read.
	 * @param mixed $backup_before_upgrade MainWP flag.
	 * @param string $backup_ref Latest ReactWoo snapshot reference.
	 * @param string $backup_at Latest snapshot time.
	 * @param int    $now Unix time.
	 * @return array{automatic:bool,protected:?bool,reason:string,backup_before_upgrade:?bool,backup_ref:string}
	 */
	public static function hub_update_exposure( $automatic_update, $readable, $backup_before_upgrade, $backup_ref, $backup_at, $now ) {
		$ref = trim( (string) $backup_ref );
		if ( ! $readable ) {
			return array(
				'automatic'             => true,
				'protected'             => false,
				'reason'                => 'automatic_update_not_readable',
				'backup_before_upgrade' => null,
				'backup_ref'            => $ref,
			);
		}

		$before    = (int) $backup_before_upgrade === 1 || '1' === (string) $backup_before_upgrade || true === $backup_before_upgrade;
		$automatic = (int) $automatic_update === 1 || '1' === (string) $automatic_update || true === $automatic_update;
		if ( ! $automatic ) {
			return array(
				'automatic'             => false,
				'protected'             => null,
				'reason'                => 'automatic_updates_off',
				'backup_before_upgrade' => $before,
				'backup_ref'            => $ref,
			);
		}

		if ( self::valid_reference( $ref ) && self::snapshot_is_fresh( $backup_at, (int) $now, self::fresh_seconds() ) ) {
			return array(
				'automatic'             => true,
				'protected'             => true,
				'reason'                => 'fresh_snapshot',
				'backup_before_upgrade' => $before,
				'backup_ref'            => $ref,
			);
		}

		return array(
			'automatic'             => true,
			'protected'             => false,
			'reason'                => $before ? 'mainwp_backup_before_upgrade_only' : 'unprotected',
			'backup_before_upgrade' => $before,
			'backup_ref'            => $ref,
		);
	}

	/**
	 * Log hub sites whose automatic updates do not have a fresh ReactWoo snapshot.
	 *
	 * @return void
	 */
	public static function audit_auto_updates() {
		foreach ( self::auto_update_rows() as $row ) {
			$exposure = self::hub_update_exposure(
				isset( $row['automatic_update'] ) ? $row['automatic_update'] : 0,
				! empty( $row['readable'] ),
				isset( $row['backup_before_upgrade'] ) ? $row['backup_before_upgrade'] : 0,
				isset( $row['last_backup_ref'] ) ? $row['last_backup_ref'] : '',
				isset( $row['last_backup_at'] ) ? $row['last_backup_at'] : '',
				time()
			);
			if ( empty( $exposure['automatic'] ) || ! empty( $exposure['protected'] ) ) {
				continue;
			}
			RW_Maint_Audit::log(
				'hub_auto_updates_unprotected',
				array(
					'portal_site_id'        => isset( $row['portal_site_id'] ) ? (int) $row['portal_site_id'] : 0,
					'subscription_id'       => isset( $row['subscription_id'] ) ? (int) $row['subscription_id'] : 0,
					'user_id'               => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
					'reason'                => $exposure['reason'],
					'backup_before_upgrade' => $exposure['backup_before_upgrade'],
					'backup_ref'            => $exposure['backup_ref'],
					'note'                  => 'This website is included in the hub automatic updates. Those updates do not wait for a ReactWoo snapshot.',
				)
			);
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function auto_update_rows() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}

		$sites = RW_Maint_DB::table( 'sites' );
		$rows  = $wpdb->get_results(
			"SELECT portal_site_id, subscription_id, user_id, mainwp_site_id, last_backup_ref, last_backup_at FROM {$sites} WHERE status = 'active' AND mainwp_site_id > 0",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$mainwp = $wpdb->prefix . 'mainwp_wp';
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $mainwp ) ) {
			return array();
		}
		$columns  = $wpdb->get_col( 'SHOW COLUMNS FROM `' . $mainwp . '`', 0 );
		$readable = is_array( $columns ) && in_array( 'automatic_update', $columns, true );
		$flags    = array();
		if ( $readable && ! empty( $rows ) ) {
			$ids = array();
			foreach ( $rows as $row ) {
				$id = absint( $row['mainwp_site_id'] );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			if ( ! empty( $ids ) ) {
				$select = 'SELECT id, automatic_update';
				if ( in_array( 'backup_before_upgrade', $columns, true ) ) {
					$select .= ', backup_before_upgrade';
				}
				$found = $wpdb->get_results( $select . ' FROM `' . $mainwp . '` WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A );
				if ( is_array( $found ) ) {
					foreach ( $found as $flag ) {
						$flags[ (int) $flag['id'] ] = $flag;
					}
				}
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$id     = (int) $row['mainwp_site_id'];
			$flag   = isset( $flags[ $id ] ) ? $flags[ $id ] : array();
			$out[]  = array(
				'portal_site_id'        => (int) $row['portal_site_id'],
				'subscription_id'       => (int) $row['subscription_id'],
				'user_id'               => (int) $row['user_id'],
				'last_backup_ref'       => isset( $row['last_backup_ref'] ) ? (string) $row['last_backup_ref'] : '',
				'last_backup_at'        => isset( $row['last_backup_at'] ) ? (string) $row['last_backup_at'] : '',
				'readable'              => $readable,
				'automatic_update'      => isset( $flag['automatic_update'] ) ? $flag['automatic_update'] : 0,
				'backup_before_upgrade' => isset( $flag['backup_before_upgrade'] ) ? $flag['backup_before_upgrade'] : 0,
			);
		}

		return $out;
	}

	/**
	 * @param string $created_at Snapshot time.
	 * @return int
	 */
	private static function snapshot_timestamp( $created_at ) {
		if ( is_numeric( $created_at ) ) {
			return (int) $created_at;
		}
		$parsed = strtotime( (string) $created_at );

		return $parsed ? (int) $parsed : 0;
	}
}
