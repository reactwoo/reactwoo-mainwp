<?php
/**
 * Additional support products credit minutes from product meta only.
 * Nothing is sent to the licence server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Credits {
	public static function register() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'credit_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'credit_order' ), 10, 1 );
	}

	public static function credit_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			self::credit_item( $user_id, (int) $order->get_id(), (int) $item_id, $product );
		}
	}

	public static function credit_item( $user_id, $order_id, $item_id, $product ) {
		$product_id = $product->get_id();
		$parent_id  = $product->get_parent_id();
		$meta       = array(
			'_rw_product_type'         => (string) get_post_meta( $product_id, '_rw_product_type', true ),
			'_rw_support_minutes'      => (string) get_post_meta( $product_id, '_rw_support_minutes', true ),
			'_rw_requires_maintenance' => (string) get_post_meta( $product_id, '_rw_requires_maintenance', true ),
			'_rw_credit_validity_days' => (string) get_post_meta( $product_id, '_rw_credit_validity_days', true ),
		);
		if ( '' === $meta['_rw_product_type'] && $parent_id ) {
			$meta['_rw_product_type']         = (string) get_post_meta( $parent_id, '_rw_product_type', true );
			$meta['_rw_support_minutes']      = (string) get_post_meta( $parent_id, '_rw_support_minutes', true );
			$meta['_rw_requires_maintenance'] = (string) get_post_meta( $parent_id, '_rw_requires_maintenance', true );
			$meta['_rw_credit_validity_days'] = (string) get_post_meta( $parent_id, '_rw_credit_validity_days', true );
		}

		$subscription = self::maintenance_subscription_for_user( $user_id );
		if ( ! RW_Support_Balance::should_credit( $meta, (bool) $subscription ) ) {
			return 0;
		}

		$subscription_id = $subscription ? (int) $subscription->get_id() : 0;
		$existing        = $subscription_id ? RW_Support_Ledger::rows_for_subscription( $subscription_id ) : self::rows_for_order_item( $item_id );
		$validity        = (int) $meta['_rw_credit_validity_days'];
		if ( $validity <= 0 ) {
			$validity = 365;
		}
		$row = RW_Support_Balance::purchase_row( $existing, $item_id, (int) $meta['_rw_support_minutes'], $validity, current_time( 'mysql' ) );
		if ( ! $row ) {
			return 0;
		}
		$row['order_id'] = $order_id;

		return RW_Support_Ledger::append( $user_id, $subscription_id, $row, 0 );
	}

	private static function maintenance_subscription_for_user( $user_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return null;
		}
		$subscriptions = wcs_get_users_subscriptions( (int) $user_id );
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'has_status' ) || ! $subscription->has_status( array( 'active', 'pending-cancel' ) ) ) {
				continue;
			}
			$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
			if ( ! is_wp_error( $entitlement ) && ! empty( $entitlement['is_maintenance'] ) ) {
				return $subscription;
			}
		}

		return null;
	}

	private static function rows_for_order_item( $item_id ) {
		global $wpdb;

		$table = RW_DB::table( 'support_ledger' );
		if ( ! RW_DB::table_exists( $table ) ) {
			return array();
		}
		$loaded = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_item_id = %d AND credit_type = 'purchased'",
				(int) $item_id
			),
			ARRAY_A
		);

		return is_array( $loaded ) ? $loaded : array();
	}
}
