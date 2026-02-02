<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Cleanup {
	const HOOK                 = 'rw_maint_cleanup';
	const DISCONNECT_GRACE_DAYS = 14;

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'cleanup_disconnected' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function cleanup_disconnected() {
		global $wpdb;

		$table = RW_Maint_DB::table( 'sites' );
		if ( ! RW_Maint_DB::table_exists( $table ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::DISCONNECT_GRACE_DAYS * DAY_IN_SECONDS ) );
		$sites  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND updated_at < %s",
				'disconnected',
				$cutoff
			)
		);

		if ( empty( $sites ) ) {
			return;
		}

		foreach ( $sites as $site ) {
			do_action( 'rw_maint_before_purge', $site );

			$deleted = $wpdb->delete( $table, array( 'id' => (int) $site->id ), array( '%d' ) );
			if ( $deleted ) {
				RW_Maint_Audit::log(
					'site_purged',
					array(
						'portal_site_id'  => (int) $site->portal_site_id,
						'subscription_id' => (int) $site->subscription_id,
						'user_id'         => (int) $site->user_id,
						'mainwp_site_id'  => (int) $site->mainwp_site_id,
					)
				);
			}
		}
	}
}
