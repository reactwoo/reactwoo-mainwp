<?php
/**
 * Grants included support minutes once per service-entitlement month.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Allowance {
	const HOOK = 'rw_portal_grant_support_allowances';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function run() {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => -1,
				'subscription_status'    => array( 'active', 'pending-cancel' ),
			)
		);
		foreach ( $subscriptions as $subscription ) {
			self::grant_subscription( $subscription );
		}
	}

	public static function grant_subscription( $subscription, $now = '' ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'has_status' ) ) {
			return array();
		}
		if ( ! $subscription->has_status( array( 'active', 'pending-cancel' ) ) ) {
			return array();
		}

		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			return array();
		}

		$now   = $now ? $now : current_time( 'mysql' );
		$start = method_exists( $subscription, 'get_date' ) ? (string) $subscription->get_date( 'start' ) : '';
		$period = RW_Support_Balance::period_containing( $start, $now );
		if ( ! $period ) {
			return array();
		}

		$minutes  = isset( $entitlement['technical_support_minutes_monthly'] ) ? (int) $entitlement['technical_support_minutes_monthly'] : 0;
		$existing = RW_Support_Ledger::rows_for_subscription( (int) $subscription->get_id() );
		$grant    = RW_Support_Balance::grant_rows( $existing, $minutes, $period, $now );
		$user_id  = method_exists( $subscription, 'get_user_id' ) ? (int) $subscription->get_user_id() : 0;
		$ids      = array();
		foreach ( $grant as $row ) {
			$ids[] = RW_Support_Ledger::append( $user_id, (int) $subscription->get_id(), $row, 0 );
		}

		return $ids;
	}
}
