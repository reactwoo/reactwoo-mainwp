<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_MainWP {
	public static function register() {
		add_action( 'rw_maint_site_enrolled', array( __CLASS__, 'handle_enroll' ), 10, 2 );
		add_action( 'rw_maint_site_suspended', array( __CLASS__, 'handle_suspend' ), 10, 2 );
		add_action( 'rw_maint_site_resumed', array( __CLASS__, 'handle_resume' ), 10, 2 );
		add_action( 'rw_maint_site_disconnected', array( __CLASS__, 'handle_disconnect' ), 10, 2 );
		add_action( 'rw_maint_site_checked', array( __CLASS__, 'handle_check' ), 10, 2 );
		add_action( 'rw_maint_site_synced', array( __CLASS__, 'handle_sync' ), 10, 2 );
		add_action( 'rw_maint_site_reconnected', array( __CLASS__, 'handle_reconnect' ), 10, 2 );
		add_action( 'rw_maint_before_purge', array( __CLASS__, 'handle_purge' ), 10, 1 );
	}

	public static function handle_enroll( $maint_site_id, $payload = array() ) {
		$site = RW_Maint_Sites::get_site( $maint_site_id );
		if ( ! $site ) {
			return;
		}

		if ( ! empty( $site->mainwp_site_id ) ) {
			do_action( 'rw_maint_mainwp_sync_reporting', $site, $payload );
			return;
		}

		$groups         = self::build_groups( $site, $payload );
		$mainwp_site_id = apply_filters( 'rw_maint_mainwp_create_site', null, $site, $payload, $groups );
		$mainwp_site_id = absint( $mainwp_site_id );

		if ( $mainwp_site_id ) {
			RW_Maint_Sites::update_site( (int) $site->id, array( 'mainwp_site_id' => $mainwp_site_id ) );

			RW_Maint_Audit::log(
				'mainwp_site_created',
				array(
					'portal_site_id'  => (int) $site->portal_site_id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'mainwp_site_id'  => $mainwp_site_id,
				)
			);
		}

		do_action( 'rw_maint_mainwp_sync_reporting', $site, $payload, $groups );
	}

	public static function handle_suspend( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_suspend_site', $site );
	}

	public static function handle_resume( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_resume_site', $site );
	}

	public static function handle_disconnect( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_disconnect_site', $site );
	}

	public static function handle_check( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_check_site', $site );
	}

	public static function handle_sync( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_sync_site', $site );
	}

	public static function handle_reconnect( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_reconnect_site', $site );
	}

	public static function handle_purge( $site ) {
		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_purge_site', $site );
	}

	private static function build_groups( $site, $payload ) {
		$groups = array();

		if ( $site ) {
			$groups[] = 'client-' . (int) $site->user_id;
			$groups[] = 'subscription-' . (int) $site->subscription_id;
		}

		return apply_filters( 'rw_maint_mainwp_groups', $groups, $site, $payload );
	}
}
