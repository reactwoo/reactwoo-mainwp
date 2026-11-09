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
			$domain = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::read_service_domain( $item ) : '';
			self::credit_item( $user_id, (int) $order->get_id(), (int) $item_id, $product, $domain );
		}
	}

	/**
	 * @param int         $user_id Customer.
	 * @param int         $order_id Order.
	 * @param int         $item_id Order line.
	 * @param object      $product Product.
	 * @param string      $service_domain Line-item `_rw_service_domain`. Licence domains are not accepted here.
	 * @return int Ledger row id, or 0 when nothing was applied.
	 */
	public static function credit_item( $user_id, $order_id, $item_id, $product, $service_domain = '' ) {
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

		$kind = '';
		if ( class_exists( 'RW_Support_Offers' ) ) {
			$slug = ( is_object( $product ) && method_exists( $product, 'get_slug' ) ) ? (string) $product->get_slug() : '';
			$kind = RW_Support_Offers::kind_of( $slug, (int) $product_id );
			if ( '' === $kind && $parent_id ) {
				$kind = RW_Support_Offers::kind_of( '', (int) $parent_id );
			}
		}
		$plans    = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::plans_for_user( $user_id ) : array();
		$decision = class_exists( 'RW_Support_Sites' )
			? RW_Support_Sites::credit_decision( $kind, $meta['_rw_requires_maintenance'], $service_domain, $plans )
			: array(
				'apply'           => true,
				'subscription_id' => 0,
				'host'            => '',
				'flag'            => '',
				'has_maintenance' => false,
			);
		if ( ! RW_Support_Balance::should_credit( $meta, ! empty( $decision['has_maintenance'] ) ) ) {
			return 0;
		}

		if ( empty( $decision['apply'] ) ) {
			if ( class_exists( 'RW_Support_Sites' ) ) {
				RW_Support_Sites::flag_credit( $item_id, $user_id, $order_id, $decision['flag'], (int) $meta['_rw_support_minutes'] );
				if ( 'no_plan_site' === $decision['flag'] ) {
					foreach ( $plans as $plan ) {
						if ( ! empty( $plan['grants'] ) && in_array( $plan['status'], array( 'empty', 'disagree' ), true ) ) {
							RW_Support_Sites::flag_plan( $plan['subscription_id'], $user_id, $plan['status'] );
						}
					}
				}
			}
			return 0;
		}

		$validity = RW_Support_Balance::configured_validity_days( $meta['_rw_credit_validity_days'] );
		if ( null === $validity ) {
			self::flag_policy_unset( $product_id );
			return 0;
		}

		$subscription_id = isset( $decision['subscription_id'] ) ? (int) $decision['subscription_id'] : 0;
		$existing        = $subscription_id ? RW_Support_Ledger::rows_for_subscription( $subscription_id ) : self::rows_for_order_item( $item_id );
		$host            = isset( $decision['host'] ) ? (string) $decision['host'] : '';
		$row             = RW_Support_Balance::purchase_row( $existing, $item_id, (int) $meta['_rw_support_minutes'], $validity, current_time( 'mysql' ), $host );
		if ( ! $row ) {
			return 0;
		}
		$row['order_id']     = $order_id;
		$row['service_host'] = $host;
		if ( '' === $host && class_exists( 'RW_Support_Sites' ) ) {
			RW_Support_Sites::flag_credit( $item_id, $user_id, $order_id, 'unassigned', (int) $meta['_rw_support_minutes'] );
		} elseif ( class_exists( 'RW_Support_Sites' ) ) {
			RW_Support_Sites::clear_credit_flag( $item_id );
		}

		return RW_Support_Ledger::append( $user_id, $subscription_id, $row, 0 );
	}

	/**
	 * Record that a support product has no owner-set expiry. Does not invent one.
	 *
	 * @param int $product_id Product.
	 * @return void
	 */
	public static function flag_policy_unset( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$existing = get_option( RW_Support_Balance::CREDIT_POLICY_UNSET_OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing[] = $product_id;
		update_option( RW_Support_Balance::CREDIT_POLICY_UNSET_OPTION, array_values( array_unique( array_map( 'intval', $existing ) ) ), false );
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
