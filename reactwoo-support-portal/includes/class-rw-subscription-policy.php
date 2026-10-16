<?php
/**
 * Pure subscription lifecycle decisions for Maintenance.
 *
 * WooCommerce hooks call these rules. Tests call them without WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Subscription_Policy {
	const PAYMENT_GRACE_DAYS   = 3;
	const OFFBOARD_GRACE_DAYS  = 14;

	public static function on_hold_should_suspend( $has_payment_grace ) {
		return ! (bool) $has_payment_grace;
	}

	/**
	 * A future paid end date keeps the service running, including pending-cancel.
	 * A missing end date does not mean the service has already expired.
	 *
	 * @param int|null $end_timestamp Unix time, or null when unknown.
	 * @param int      $now           Unix time.
	 * @return bool
	 */
	public static function entitlement_still_paid( $end_timestamp, $now ) {
		if ( null === $end_timestamp || $end_timestamp <= 0 ) {
			return true;
		}

		return (int) $end_timestamp > (int) $now;
	}

	/**
	 * Final end starts offboarding. It does not remove MainWP immediately.
	 *
	 * @param int|null $end_timestamp Unix time, or null when unknown.
	 * @param int      $now           Unix time.
	 * @return bool
	 */
	public static function should_start_offboarding( $end_timestamp, $now ) {
		if ( null === $end_timestamp || $end_timestamp <= 0 ) {
			return false;
		}

		return (int) $end_timestamp <= (int) $now;
	}

	/**
	 * Connected is an operational claim. Token verification alone is not enough.
	 *
	 * @param array<string,mixed>|WP_Error $enrol
	 * @return bool
	 */
	public static function operational_connected( $enrol ) {
		if ( is_wp_error( $enrol ) || ! is_array( $enrol ) ) {
			return false;
		}

		return ! empty( $enrol['mainwp_site_id'] )
			&& ! empty( $enrol['initial_check'] )
			&& ! empty( $enrol['initial_sync'] );
	}
}
