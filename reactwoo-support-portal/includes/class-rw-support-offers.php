<?php
/**
 * Extra support packs sold from the maintenance account.
 *
 * Prices are never stored here. The shop product supplies get_price_html()
 * or wc_price(). Additional Support can be bought only with an active
 * maintenance plan. Technical Support is standalone.
 *
 * The purchasable callback uses the same function name as the temporary
 * mu-plugin rw-additional-support-guard.php. Whichever copy loads first
 * defines it, and the filter is added only once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'rw_additional_support_guard_purchasable' ) ) {
	/**
	 * @param bool   $purchasable Current flag.
	 * @param object $product Woo product.
	 * @return bool
	 */
	function rw_additional_support_guard_purchasable( $purchasable, $product ) {
		return RW_Support_Offers::filter_purchasable( $purchasable, $product );
	}
}

class RW_Support_Offers {
	const MAINTENANCE_PRODUCT_IDS = array( 3654, 3657, 3660 );

	/**
	 * Slug is the match. The id is the current shop product, used when a
	 * slug lookup misses and to recognise the product if its slug changes.
	 *
	 * @return array<string,array{id:int,kind:string}>
	 */
	/**
	 * @return array<int,int>
	 */
	public static function offer_ids() {
		$ids = array();
		foreach ( self::catalogue() as $row ) {
			$ids[] = (int) $row['id'];
		}

		return $ids;
	}

	public static function catalogue() {
		return array(
			'additional-support-1h' => array(
				'id'   => 3663,
				'kind' => 'additional',
			),
			'additional-support-5h' => array(
				'id'   => 3664,
				'kind' => 'additional',
			),
			'technical-support-1h'  => array(
				'id'   => 3665,
				'kind' => 'technical',
			),
			'technical-support-5h'  => array(
				'id'   => 3666,
				'kind' => 'technical',
			),
		);
	}

	public static function register() {
		static $done = false;
		if ( function_exists( 'has_filter' ) && has_filter( 'woocommerce_is_purchasable', 'rw_additional_support_guard_purchasable' ) ) {
			return;
		}
		if ( $done ) {
			return;
		}
		$done = true;
		add_filter( 'woocommerce_is_purchasable', 'rw_additional_support_guard_purchasable', 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'remember_checkout_context' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_checkout_order' ), 20, 1 );
	}

	/**
	 * @param bool   $purchasable Current flag.
	 * @param object $product Woo product.
	 * @return bool
	 */
	public static function filter_purchasable( $purchasable, $product ) {
		$slug = ( is_object( $product ) && method_exists( $product, 'get_slug' ) ) ? (string) $product->get_slug() : '';
		$id   = ( is_object( $product ) && method_exists( $product, 'get_id' ) ) ? (int) $product->get_id() : 0;
		$kind = self::kind_of( $slug, $id );
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return self::allows_purchase( (bool) $purchasable, $kind, self::user_has_active_maintenance( $user ) );
	}

	/**
	 * @param bool   $purchasable Current flag.
	 * @param string $kind additional, technical, or empty.
	 * @param bool   $has_maintenance Active maintenance plan on products 3654, 3657, or 3660.
	 * @return bool
	 */
	public static function allows_purchase( $purchasable, $kind, $has_maintenance ) {
		if ( ! $purchasable ) {
			return false;
		}
		if ( 'additional' === $kind && ! $has_maintenance ) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $slug Product slug.
	 * @param int    $id Product id.
	 * @return string
	 */
	public static function kind_of( $slug, $id ) {
		$slug = (string) $slug;
		$id   = (int) $id;
		$catalogue = self::catalogue();
		if ( isset( $catalogue[ $slug ] ) ) {
			return $catalogue[ $slug ]['kind'];
		}
		foreach ( $catalogue as $row ) {
			if ( (int) $row['id'] === $id && $id > 0 ) {
				return $row['kind'];
			}
		}

		return '';
	}

	/**
	 * @param string $status Subscription status.
	 * @param array  $product_ids Product ids on the subscription.
	 * @return bool
	 */
	public static function subscription_grants_additional( $status, array $product_ids ) {
		if ( ! in_array( (string) $status, array( 'active', 'pending-cancel' ), true ) ) {
			return false;
		}
		foreach ( $product_ids as $product_id ) {
			if ( in_array( (int) $product_id, self::MAINTENANCE_PRODUCT_IDS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $products Slug, id, name, price_html.
	 * @param bool                           $has_maintenance Plan gate.
	 * @param array<string,mixed>|null       $card Saved token, or null.
	 * @param string                         $checkout_base Checkout URL.
	 * @param int                            $subscription_id Subscription.
	 * @param int                            $site_id Managed site.
	 * @return array<string,mixed>|null
	 */
	public static function present( array $products, $has_maintenance, $card, $checkout_base, $subscription_id, $site_id ) {
		$options = array();
		$seen    = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$slug = isset( $product['slug'] ) ? (string) $product['slug'] : '';
			$id   = isset( $product['id'] ) ? (int) $product['id'] : 0;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$kind = self::kind_of( $slug, $id );
			if ( '' === $kind || ! in_array( $id, self::offer_ids(), true ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( 'additional' === $kind && ! $has_maintenance ) {
				continue;
			}
			$price_html = isset( $product['price_html'] ) ? (string) $product['price_html'] : '';
			$price_text = trim( wp_strip_all_tags( $price_html ) );
			if ( '' === $price_text ) {
				continue;
			}
			$options[] = array(
				'slug'         => $slug,
				'product_id'   => $id,
				'name'         => isset( $product['name'] ) ? (string) $product['name'] : $slug,
				'kind'         => $kind,
				'price_html'   => $price_html,
				'price_text'   => $price_text,
				'checkout_url' => self::checkout_url( $checkout_base, $id, $subscription_id, $site_id ),
			);
		}
		if ( empty( $options ) ) {
			return null;
		}

		$saved = null;
		if ( is_array( $card ) && ! empty( $card['last4'] ) && ! empty( $card['token_id'] ) ) {
			$last4 = preg_replace( '/\D+/', '', (string) $card['last4'] );
			if ( strlen( $last4 ) >= 4 ) {
				$last4 = substr( $last4, -4 );
				$saved = array(
					'token_id'   => (int) $card['token_id'],
					'gateway_id' => isset( $card['gateway_id'] ) ? (string) $card['gateway_id'] : '',
					'last4'      => $last4,
					'label'      => 'Buy now with card ending ••••' . $last4,
				);
			}
		}

		return array(
			'options'         => $options,
			'card'            => $saved,
			'subscription_id' => (int) $subscription_id,
			'site_id'         => (int) $site_id,
		);
	}

	/**
	 * @param string $base Checkout URL.
	 * @param int    $product_id Product.
	 * @param int    $subscription_id Subscription.
	 * @param int    $site_id Managed site.
	 * @return string
	 */
	public static function checkout_url( $base, $product_id, $subscription_id, $site_id ) {
		return add_query_arg(
			array(
				'add-to-cart'         => (int) $product_id,
				'rw_subscription_id'  => (int) $subscription_id,
				'rw_site_id'          => (int) $site_id,
			),
			(string) $base
		);
	}

	/**
	 * @param int $user_id Customer.
	 * @return bool
	 */
	public static function user_has_active_maintenance( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return false;
		}
		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( ! is_array( $subscriptions ) ) {
			return false;
		}
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_status' ) ) {
				continue;
			}
			if ( self::subscription_grants_additional( $subscription->get_status(), self::product_ids_from_subscription( $subscription ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param object $subscription Woo subscription.
	 * @return array<int,int>
	 */
	public static function product_ids_from_subscription( $subscription ) {
		$ids = array();
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) ) {
			return $ids;
		}
		foreach ( $subscription->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
				$ids[] = (int) $item->get_product_id();
			}
			if ( is_object( $item ) && method_exists( $item, 'get_variation_id' ) ) {
				$ids[] = (int) $item->get_variation_id();
			}
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Card for a connected dashboard. Null when WooCommerce has no packs.
	 *
	 * @param object $subscription Subscription.
	 * @param int    $user_id Customer.
	 * @param object|null $site Managed site row.
	 * @return array<string,mixed>|null
	 */
	public static function card_for_subscription( $subscription, $user_id, $site ) {
		$records = array();
		foreach ( self::catalogue() as $slug => $row ) {
			$product = self::load_product( $slug, (int) $row['id'] );
			if ( ! $product || ! method_exists( $product, 'get_id' ) || (int) $product->get_id() !== (int) $row['id'] ) {
				continue;
			}
			$records[] = array(
				'slug'       => $slug,
				'id'         => (int) $row['id'],
				'name'       => self::product_label( $product, $slug ),
				'price_html' => self::price_html( $product ),
			);
		}
		$site_id = 0;
		if ( is_object( $site ) && ! empty( $site->id ) ) {
			$site_id = (int) $site->id;
		}
		$subscription_id = ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) ? (int) $subscription->get_id() : 0;
		$status          = ( is_object( $subscription ) && method_exists( $subscription, 'get_status' ) ) ? (string) $subscription->get_status() : '';
		$has_plan        = self::subscription_grants_additional( $status, self::product_ids_from_subscription( $subscription ) );
		$base            = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		$presented       = self::present( $records, $has_plan, self::saved_card( $user_id ), $base, $subscription_id, $site_id );
		if ( ! is_array( $presented ) ) {
			return null;
		}
		$presented['nonce']    = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'rw_buy_extra_support' ) : '';
		$presented['ajax_url'] = function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '';

		return $presented;
	}

	/**
	 * Variation attributes are part of the label. The parent title alone is not.
	 *
	 * @param object $product Woo product or variation.
	 * @param string $fallback Slug used when the product has no name.
	 * @return string
	 */
	public static function product_label( $product, $fallback = '' ) {
		$name = ( is_object( $product ) && method_exists( $product, 'get_name' ) ) ? trim( (string) $product->get_name() ) : '';
		if ( is_object( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			$parent_name = '';
			if ( method_exists( $product, 'get_parent_id' ) && function_exists( 'wc_get_product' ) ) {
				$parent = wc_get_product( (int) $product->get_parent_id() );
				if ( is_object( $parent ) && method_exists( $parent, 'get_name' ) ) {
					$parent_name = trim( (string) $parent->get_name() );
				}
			}
			$formatted = function_exists( 'wc_get_formatted_variation' ) ? (string) wc_get_formatted_variation( $product, true, false, false ) : '';
			$formatted = trim( wp_strip_all_tags( $formatted ) );
			if ( '' !== $parent_name && '' !== $formatted && false === strpos( $name, $formatted ) ) {
				return $parent_name . ' — ' . $formatted;
			}
		}
		if ( '' !== $name ) {
			return $name;
		}

		return (string) $fallback;
	}

	public static function load_product( $slug, $fallback_id ) {
		if ( function_exists( 'wc_get_product' ) && (int) $fallback_id > 0 ) {
			$by_id = wc_get_product( (int) $fallback_id );
			if ( is_object( $by_id ) && method_exists( $by_id, 'get_id' ) && (int) $by_id->get_id() === (int) $fallback_id ) {
				return $by_id;
			}
		}

		$product = null;
		if ( function_exists( 'wc_get_products' ) ) {
			$found = wc_get_products(
				array(
					'slug'   => $slug,
					'limit'  => 1,
					'status' => 'publish',
				)
			);
			if ( is_array( $found ) && ! empty( $found[0] ) ) {
				$product = $found[0];
			}
		}
		if ( ! $product && function_exists( 'wc_get_product' ) && $fallback_id > 0 ) {
			$by_id = wc_get_product( $fallback_id );
			if ( is_object( $by_id ) && method_exists( $by_id, 'get_slug' ) && (string) $by_id->get_slug() === (string) $slug ) {
				$product = $by_id;
			}
		}

		return is_object( $product ) ? $product : null;
	}

	/**
	 * @param object $product Woo product.
	 * @return string
	 */
	public static function price_html( $product ) {
		$html = ( is_object( $product ) && method_exists( $product, 'get_price_html' ) ) ? (string) $product->get_price_html() : '';
		if ( '' === trim( wp_strip_all_tags( $html ) ) && function_exists( 'wc_price' ) && is_object( $product ) && method_exists( $product, 'get_price' ) ) {
			$html = (string) wc_price( $product->get_price() );
		}

		return $html;
	}

	/**
	 * Prefer a tokenizing gateway. revolut_cc is the one enabled for saved cards here.
	 *
	 * @param int $user_id Customer.
	 * @return array<string,mixed>|null
	 */
	public static function saved_card( $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'WC' ) || ! class_exists( 'WC_Payment_Tokens' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'payment_gateways' ) ) {
			return null;
		}
		$registry = $wc->payment_gateways();
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_available_payment_gateways' ) ) {
			return null;
		}
		$gateways = $registry->get_available_payment_gateways();
		if ( ! is_array( $gateways ) ) {
			return null;
		}
		$ordered = array();
		if ( isset( $gateways['revolut_cc'] ) ) {
			$ordered['revolut_cc'] = $gateways['revolut_cc'];
		}
		foreach ( $gateways as $gateway_id => $gateway ) {
			if ( ! isset( $ordered[ $gateway_id ] ) ) {
				$ordered[ $gateway_id ] = $gateway;
			}
		}
		foreach ( $ordered as $gateway ) {
			if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'supports' ) || ! $gateway->supports( 'tokenization' ) ) {
				continue;
			}
			$tokens = WC_Payment_Tokens::get_customer_tokens( (int) $user_id, (string) $gateway->id );
			if ( ! is_array( $tokens ) ) {
				continue;
			}
			$chosen = null;
			foreach ( $tokens as $token ) {
				if ( ! is_object( $token ) || ! method_exists( $token, 'get_last4' ) ) {
					continue;
				}
				if ( method_exists( $token, 'is_default' ) && $token->is_default() ) {
					$chosen = $token;
					break;
				}
				if ( ! $chosen ) {
					$chosen = $token;
				}
			}
			if ( $chosen ) {
				return array(
					'token_id'   => method_exists( $chosen, 'get_id' ) ? (int) $chosen->get_id() : 0,
					'gateway_id' => (string) $gateway->id,
					'last4'      => (string) $chosen->get_last4(),
				);
			}
		}

		return null;
	}

	public static function remember_checkout_context() {
		if ( ! function_exists( 'WC' ) || empty( $_GET['add-to-cart'] ) ) {
			return;
		}
		$product_id = absint( wp_unslash( $_GET['add-to-cart'] ) );
		$slug       = isset( $_GET['rw_support_slug'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_support_slug'] ) ) : '';
		if ( '' === self::kind_of( $slug, $product_id ) && '' === self::kind_of( '', $product_id ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'session' ) || ! $wc->session ) {
			return;
		}
		$wc->session->set(
			'rw_support_purchase',
			array(
				'subscription_id' => isset( $_GET['rw_subscription_id'] ) ? absint( wp_unslash( $_GET['rw_subscription_id'] ) ) : 0,
				'site_id'         => isset( $_GET['rw_site_id'] ) ? absint( wp_unslash( $_GET['rw_site_id'] ) ) : 0,
			)
		);
	}

	/**
	 * @param object $order Checkout order.
	 */
	public static function stamp_checkout_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'session' ) || ! $wc->session ) {
			return;
		}
		$context = $wc->session->get( 'rw_support_purchase' );
		if ( ! is_array( $context ) ) {
			return;
		}
		RW_Support_Purchase::stamp_order( $order, isset( $context['subscription_id'] ) ? (int) $context['subscription_id'] : 0, isset( $context['site_id'] ) ? (int) $context['site_id'] : 0 );
	}
}
