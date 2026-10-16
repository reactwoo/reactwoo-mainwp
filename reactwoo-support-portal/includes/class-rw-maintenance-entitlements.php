<?php
/**
 * Maintenance entitlement resolver.
 *
 * Product source of truth: `_rw_maintenance_plan_key` in API Manager.
 * Subscription contract: `_rw_maintenance_entitlements` written by this class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maintenance_Entitlements {
	const SNAPSHOT_META = '_rw_maintenance_entitlements';

	/**
	 * @param mixed $subscription Woo subscription or null.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function resolve( $subscription ) {
		if ( ! $subscription || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return new WP_Error( 'rw_entitlement_missing', 'Subscription not found.' );
		}

		$snapshot = self::read_snapshot( $subscription );
		if ( is_array( $snapshot ) && ! empty( $snapshot['is_maintenance'] ) ) {
			return $snapshot;
		}

		$key = self::plan_key_from_subscription( $subscription );
		return RW_Maintenance_Plans::resolve( $key );
	}

	/**
	 * @param mixed $subscription Woo subscription.
	 * @return string
	 */
	public static function plan_key_from_subscription( $subscription ) {
		if ( ! $subscription || ! method_exists( $subscription, 'get_items' ) ) {
			return 'none';
		}

		foreach ( $subscription->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			$parent_id    = (int) $item->get_product_id();
			$key          = self::plan_key_for_ids( $variation_id, $parent_id );
			if ( 'none' !== $key ) {
				return $key;
			}
		}

		return 'none';
	}

	/**
	 * A variation override applies only when it names a real plan.
	 * Empty and none inherit the parent product.
	 *
	 * @param int $variation_id
	 * @param int $parent_id
	 * @return string
	 */
	public static function plan_key_for_ids( $variation_id, $parent_id ) {
		if ( $variation_id > 0 ) {
			$variation = RW_Maintenance_Plans::normalize_key( get_post_meta( $variation_id, RW_Maintenance_Plans::META_KEY, true ) );
			if ( 'none' !== $variation ) {
				return $variation;
			}
		}

		if ( $parent_id > 0 ) {
			return RW_Maintenance_Plans::normalize_key( get_post_meta( $parent_id, RW_Maintenance_Plans::META_KEY, true ) );
		}

		return 'none';
	}

	/**
	 * Write the contract snapshot when a Maintenance subscription is created
	 * or switched. Non-maintenance subscriptions are left untouched.
	 *
	 * @param mixed $subscription Woo subscription.
	 * @return void
	 */
	public static function snapshot( $subscription ) {
		self::maybe_snapshot( $subscription );
	}

	/**
	 * Fail closed. A missing or non-positive limit never means unlimited.
	 *
	 * @param array<string,mixed>|WP_Error $entitlement
	 * @param int                           $existing_count
	 * @return true|WP_Error
	 */
	public static function allows_another_site( $entitlement, $existing_count ) {
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			return new WP_Error( 'rw_entitlement_not_maintenance', 'This subscription is not a Maintenance plan.' );
		}

		$limit = isset( $entitlement['site_limit'] ) ? (int) $entitlement['site_limit'] : 0;
		if ( $limit <= 0 ) {
			return new WP_Error( 'rw_site_limit', 'This plan does not include a website slot.' );
		}

		if ( (int) $existing_count >= $limit ) {
			return new WP_Error( 'rw_site_limit', 'This plan already includes its website.' );
		}

		return true;
	}

	public static function maybe_snapshot( $subscription ) {
		if ( ! $subscription || ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
			return;
		}

		$key = self::plan_key_from_subscription( $subscription );
		if ( 'none' === $key ) {
			return;
		}

		$resolved = RW_Maintenance_Plans::resolve( $key );
		$subscription->update_meta_data( self::SNAPSHOT_META, wp_json_encode( $resolved ) );
		if ( method_exists( $subscription, 'save' ) ) {
			$subscription->save();
		}
	}

	/**
	 * @param mixed $subscription
	 * @return array<string,mixed>|null
	 */
	private static function read_snapshot( $subscription ) {
		if ( ! method_exists( $subscription, 'get_meta' ) ) {
			return null;
		}

		$raw = $subscription->get_meta( self::SNAPSHOT_META, true );
		if ( is_array( $raw ) ) {
			return self::sanitize_snapshot( $raw );
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? self::sanitize_snapshot( $decoded ) : null;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>|null
	 */
	private static function sanitize_snapshot( array $raw ) {
		if ( empty( $raw['plan_key'] ) ) {
			return null;
		}

		$resolved = RW_Maintenance_Plans::resolve( $raw['plan_key'] );
		if ( empty( $resolved['is_maintenance'] ) ) {
			return null;
		}

		if ( isset( $raw['site_limit'] ) ) {
			$limit = absint( $raw['site_limit'] );
			if ( $limit > 0 ) {
				$resolved['site_limit'] = $limit;
			}
		}

		return $resolved;
	}

	/**
	 * On when an admin enables the flag, or when a Maintenance product exists
	 * and the flag has not been explicitly turned off.
	 *
	 * @return bool
	 */
	public static function portal_v2_enabled() {
		$flag = (string) get_option( 'rw_maintenance_portal_v2', '' );
		if ( 'no' === $flag ) {
			return false;
		}
		if ( 'yes' === $flag ) {
			return true;
		}

		return self::maintenance_product_exists();
	}

	/**
	 * @return bool
	 */
	public static function maintenance_product_exists() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return false;
		}

		$products = wc_get_products(
			array(
				'limit'  => 20,
				'status' => array( 'publish', 'private', 'draft' ),
				'return' => 'ids',
			)
		);
		if ( ! is_array( $products ) ) {
			return false;
		}

		foreach ( $products as $product_id ) {
			if ( 'none' !== RW_Maintenance_Plans::normalize_key( get_post_meta( (int) $product_id, RW_Maintenance_Plans::META_KEY, true ) ) ) {
				return true;
			}
		}

		return false;
	}
}
