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
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_contents' ) );
		add_action( 'template_redirect', array( __CLASS__, 'remember_checkout_context' ) );
		add_action( 'template_redirect', array( __CLASS__, 'preselect_saved_gateway' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_preselect' ) );
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
		$has  = self::user_has_active_maintenance( $user );
		$site = ( class_exists( 'RW_Support_Sites' ) && $has ) ? RW_Support_Sites::customer_has_resolved_plan_site( $user ) : $has;

		return self::purchasable_with_site( (bool) $purchasable, $kind, $has, $site );
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
	 * Additional Support also needs a maintenance plan that names one website.
	 * The checkout site is checked separately, against that website.
	 *
	 * @param bool   $purchasable Current flag.
	 * @param string $kind additional, technical, or empty.
	 * @param bool   $has_maintenance Active or pending-cancel maintenance plan.
	 * @param bool   $has_resolved_plan_site That plan has one stored website.
	 * @return bool
	 */
	public static function purchasable_with_site( $purchasable, $kind, $has_maintenance, $has_resolved_plan_site ) {
		if ( ! self::allows_purchase( $purchasable, $kind, $has_maintenance ) ) {
			return false;
		}
		if ( 'additional' === $kind && ! $has_resolved_plan_site ) {
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
			if ( self::product_is_maintenance( (int) $product_id, 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Products 3654, 3657 and 3660, a variation of those parents, or a product
	 * whose `_rw_maintenance_plan_key` is essential, standard or business.
	 *
	 * @param int $product_id Product or variation.
	 * @param int $parent_id Parent product. Zero when the caller does not have it.
	 * @return bool
	 */
	public static function product_is_maintenance( $product_id, $parent_id = 0 ) {
		$product_id = (int) $product_id;
		$parent_id  = (int) $parent_id;
		if ( in_array( $product_id, self::MAINTENANCE_PRODUCT_IDS, true ) || in_array( $parent_id, self::MAINTENANCE_PRODUCT_IDS, true ) ) {
			return true;
		}
		if ( ! function_exists( 'get_post_meta' ) || ! class_exists( 'RW_Maintenance_Plans' ) ) {
			return false;
		}
		$key = RW_Maintenance_Plans::normalize_key( get_post_meta( $product_id, RW_Maintenance_Plans::META_KEY, true ) );
		if ( 'none' !== $key ) {
			return true;
		}
		if ( $parent_id > 0 ) {
			$key = RW_Maintenance_Plans::normalize_key( get_post_meta( $parent_id, RW_Maintenance_Plans::META_KEY, true ) );
			if ( 'none' !== $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why an Additional Support line cannot use the site entered at checkout.
	 * Empty means the line may proceed. A missing plan is not reported here.
	 *
	 * @param bool             $has_plan Entitled maintenance plan.
	 * @param mixed            $pack_domain Site entered for this pack.
	 * @param array<int,mixed> $plan_hosts Resolved plan hosts.
	 * @return string
	 */
	public static function checkout_site_refusal( $has_plan, $pack_domain, array $plan_hosts ) {
		if ( ! $has_plan || ! class_exists( 'RW_Support_Sites' ) ) {
			return '';
		}
		if ( RW_Support_Sites::host_matches_any( $pack_domain, $plan_hosts ) ) {
			return '';
		}
		if ( empty( $plan_hosts ) ) {
			return 'plan_site';
		}
		if ( '' === RW_Support_Sites::host_key( $pack_domain ) ) {
			return 'missing_pack_site';
		}

		return 'mismatch';
	}

	/**
	 * Additional Support lines whose checkout site is not an entitled plan site.
	 *
	 * @param array<int,array<string,mixed>> $lines id, slug, parent_id, service_domain.
	 * @param bool                           $has_maintenance Active plan already on the account.
	 * @param array<int,mixed>               $plan_hosts Resolved plan hosts.
	 * @return int[]
	 */
	public static function checkout_site_blocks( array $lines, $has_maintenance, array $plan_hosts ) {
		$blocked = array();
		if ( ! $has_maintenance ) {
			return $blocked;
		}
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$id     = isset( $line['id'] ) ? (int) $line['id'] : 0;
			$slug   = isset( $line['slug'] ) ? (string) $line['slug'] : '';
			$parent = isset( $line['parent_id'] ) ? (int) $line['parent_id'] : 0;
			$kind   = self::kind_of( $slug, $id );
			if ( '' === $kind && $parent > 0 ) {
				$kind = self::kind_of( '', $parent );
			}
			if ( 'additional' !== $kind ) {
				continue;
			}
			$domain = isset( $line['service_domain'] ) ? $line['service_domain'] : '';
			if ( '' === self::checkout_site_refusal( true, $domain, $plan_hosts ) ) {
				continue;
			}
			$blocked[] = $id > 0 ? $id : $parent;
		}

		return array_values( array_unique( array_filter( $blocked ) ) );
	}

	/**
	 * @param string $reason plan_site, missing_pack_site, or mismatch.
	 * @return string
	 */
	public static function site_refusal_message( $reason ) {
		if ( 'plan_site' === $reason ) {
			return 'Additional support cannot be applied until the maintenance plan website is confirmed.';
		}
		if ( 'missing_pack_site' === $reason ) {
			return 'Enter the website covered by your maintenance plan. Additional support can only be used on that site.';
		}

		return 'Additional support can only be used on the website covered by your maintenance plan.';
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
				'checkout_url' => self::checkout_url(
					$checkout_base,
					$id,
					$subscription_id,
					$site_id,
					( is_array( $card ) && ! empty( $card['token_id'] ) ) ? (int) $card['token_id'] : 0,
					( is_array( $card ) && ! empty( $card['gateway_id'] ) ) ? (string) $card['gateway_id'] : ''
				),
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
				$charge = class_exists( 'RW_Support_Purchase' ) && RW_Support_Purchase::saved_method_charge_authorised();
				$saved  = array(
					'token_id'   => (int) $card['token_id'],
					'gateway_id' => isset( $card['gateway_id'] ) ? (string) $card['gateway_id'] : '',
					'last4'      => $last4,
					'label'      => ( $charge ? 'Buy now with card ending ••••' : 'Checkout with card ending ••••' ) . $last4,
					'charge'     => $charge,
				);
			}
		}

		return array(
			'options'         => $options,
			'card'            => $saved,
			'charge'          => is_array( $saved ) && ! empty( $saved['charge'] ),
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
	public static function checkout_url( $base, $product_id, $subscription_id, $site_id, $token_id = 0, $gateway_id = '' ) {
		$args = array(
			'add-to-cart'        => (int) $product_id,
			'rw_subscription_id' => (int) $subscription_id,
			'rw_site_id'         => (int) $site_id,
		);
		if ( (int) $token_id > 0 && '' !== (string) $gateway_id ) {
			$args['rw_payment_token']   = (int) $token_id;
			$args['rw_payment_gateway'] = (string) $gateway_id;
		}

		return add_query_arg( $args, (string) $base );
	}

	/**
	 * Additional Support lines that must leave the cart. Other lines stay.
	 *
	 * A maintenance plan in the same cart is not an active plan.
	 *
	 * @param array<int,array<string,mixed>> $lines id, slug.
	 * @param bool                           $has_maintenance Active maintenance plan already on the account.
	 * @return int[]
	 */
	public static function blocked_cart_product_ids( array $lines, $has_maintenance ) {
		$blocked = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$id   = isset( $line['id'] ) ? (int) $line['id'] : 0;
			$slug = isset( $line['slug'] ) ? (string) $line['slug'] : '';
			$parent = isset( $line['parent_id'] ) ? (int) $line['parent_id'] : 0;
			$kind = self::kind_of( $slug, $id );
			if ( '' === $kind && $parent > 0 ) {
				$kind = self::kind_of( '', $parent );
			}
			if ( 'additional' === $kind && ! $has_maintenance ) {
				$blocked[] = $id > 0 ? $id : $parent;
			}
		}

		return array_values( array_unique( array_filter( $blocked ) ) );
	}

	/**
	 * @param bool $passed Current validation result.
	 * @param int  $product_id Product.
	 * @param int  $quantity Quantity.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		unset( $quantity );
		if ( ! $passed ) {
			return false;
		}
		$line = array( 'id' => (int) $product_id, 'slug' => '' );
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( is_object( $product ) && method_exists( $product, 'get_slug' ) ) {
				$line['slug'] = (string) $product->get_slug();
			}
			if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) ) {
				$line['parent_id'] = (int) $product->get_parent_id();
			}
		}
		$user    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$has     = self::user_has_active_maintenance( $user );
		$blocked = self::blocked_cart_product_ids( array( $line ), $has );
		if ( ! empty( $blocked ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'Additional support is available with an active maintenance plan.', 'error' );
			}
			return false;
		}
		$kind   = self::kind_of( $line['slug'], (int) $line['id'] );
		$parent = isset( $line['parent_id'] ) ? (int) $line['parent_id'] : 0;
		if ( '' === $kind && $parent > 0 ) {
			$kind = self::kind_of( '', $parent );
		}
		$domain = self::posted_service_domain_for_line( '' );
		if ( 'additional' === $kind && class_exists( 'RW_Support_Sites' ) && '' !== RW_Support_Sites::host_key( $domain ) ) {
			$reason = self::checkout_site_refusal( $has, $domain, RW_Support_Sites::resolved_hosts_for_user( $user ) );
			if ( '' !== $reason ) {
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( self::site_refusal_message( $reason ), 'error' );
				}
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop ineligible Additional Support from a cart that may also hold licences or plans.
	 *
	 * @return void
	 */
	public static function validate_cart_contents() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
			return;
		}
		$lines = array();
		foreach ( $wc->cart->get_cart() as $key => $item ) {
			$product = ( is_array( $item ) && isset( $item['data'] ) ) ? $item['data'] : null;
			$id      = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) ( is_array( $item ) && isset( $item['product_id'] ) ? $item['product_id'] : 0 );
			$lines[] = array(
				'key'            => $key,
				'id'             => $id,
				'slug'           => is_object( $product ) && method_exists( $product, 'get_slug' ) ? (string) $product->get_slug() : '',
				'parent_id'      => is_object( $product ) && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0,
				'service_domain' => self::posted_service_domain_for_line( $key ),
			);
		}
		$user    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$has     = self::user_has_active_maintenance( $user );
		$blocked = self::blocked_cart_product_ids( $lines, $has );
		$site_blocked = array();
		if ( self::checking_out() && class_exists( 'RW_Support_Sites' ) ) {
			$site_blocked = self::checkout_site_blocks( $lines, $has, RW_Support_Sites::resolved_hosts_for_user( $user ) );
		}
		if ( empty( $blocked ) && empty( $site_blocked ) ) {
			return;
		}
		foreach ( $lines as $line ) {
			$remove = in_array( (int) $line['id'], $blocked, true ) || in_array( (int) $line['id'], $site_blocked, true );
			if ( $remove && method_exists( $wc->cart, 'remove_cart_item' ) ) {
				$wc->cart->remove_cart_item( $line['key'] );
			}
		}
		if ( ! empty( $blocked ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Additional support was removed. It is available with an active maintenance plan.', 'error' );
		}
		if ( ! empty( $site_blocked ) && function_exists( 'wc_add_notice' ) ) {
			$reason = empty( RW_Support_Sites::resolved_hosts_for_user( $user, false ) ) ? 'plan_site' : 'mismatch';
			wc_add_notice( self::site_refusal_message( $reason ), 'error' );
		}
	}

	/**
	 * Checkout is where the service website is entered. The cart page is not.
	 *
	 * @return bool
	 */
	public static function checking_out() {
		if ( null !== self::$force_checkout ) {
			return (bool) self::$force_checkout;
		}

		return function_exists( 'is_checkout' ) && is_checkout();
	}

	/**
	 * @var bool|null
	 */
	public static $force_checkout = null;

	/**
	 * Website posted for one service line. A licence domain is never read.
	 *
	 * @param string $cart_key Cart line key. Empty reads a single posted value.
	 * @return string
	 */
	public static function posted_service_domain_for_line( $cart_key ) {
		if ( ! isset( $_POST['rw_service_domain'] ) ) {
			return '';
		}
		$posted = $_POST['rw_service_domain'];
		if ( function_exists( 'wp_unslash' ) ) {
			$posted = wp_unslash( $posted );
		}
		if ( is_string( $posted ) ) {
			return sanitize_text_field( $posted );
		}
		if ( ! is_array( $posted ) ) {
			return '';
		}
		$cart_key = (string) $cart_key;
		if ( '' !== $cart_key && isset( $posted[ $cart_key ] ) ) {
			return sanitize_text_field( (string) $posted[ $cart_key ] );
		}
		if ( '' === $cart_key && 1 === count( $posted ) ) {
			$value = reset( $posted );
			return sanitize_text_field( (string) $value );
		}

		return '';
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
		$site_host       = '';
		$plan_host       = '';
		$offer_plan      = $has_plan;
		if ( class_exists( 'RW_Support_Sites' ) ) {
			if ( is_object( $site ) && ! empty( $site->site_url ) ) {
				$site_host = RW_Support_Sites::host_key( $site->site_url );
			}
			$plan      = RW_Support_Sites::plan_site( $subscription );
			$plan_host = $plan['host'];
			if ( $has_plan && in_array( $plan['status'], array( 'empty', 'disagree' ), true ) ) {
				RW_Support_Sites::flag_plan( $subscription_id, (int) $user_id, $plan['status'] );
				$offer_plan = false;
			} elseif ( $has_plan ) {
				$offer_plan = ( '' !== $plan_host && '' !== $site_host && RW_Support_Sites::hosts_match( $plan_host, $site_host ) );
			}
		}
		$base            = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		$presented       = self::present( $records, $offer_plan, self::saved_card( $user_id ), $base, $subscription_id, $site_id );
		if ( ! is_array( $presented ) ) {
			return null;
		}
		$presented['nonce']     = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'rw_buy_extra_support' ) : '';
		$presented['ajax_url']  = function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '';
		$presented['site_host'] = $site_host;
		$presented['plan_host'] = $plan_host;

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

	/**
	 * Session context for a support checkout. A saved token is kept only when it belongs to this customer.
	 *
	 * @param int    $user_id Customer.
	 * @param int    $product_id Product.
	 * @param string $slug Product slug.
	 * @param int    $subscription_id Subscription.
	 * @param int    $site_id Site.
	 * @param int    $token_user_id Owner stored on the token.
	 * @param int    $token_id Token.
	 * @param string $gateway_id Gateway.
	 * @return array<string,mixed>|null
	 */
	public static function checkout_context_from_request( $user_id, $product_id, $slug, $subscription_id, $site_id, $token_user_id, $token_id, $gateway_id ) {
		if ( '' === self::kind_of( (string) $slug, (int) $product_id ) && '' === self::kind_of( '', (int) $product_id ) ) {
			return null;
		}
		$context = array(
			'subscription_id' => (int) $subscription_id,
			'site_id'         => (int) $site_id,
		);
		$token_id      = (int) $token_id;
		$user_id       = (int) $user_id;
		$token_user_id = (int) $token_user_id;
		$gateway_id    = (string) $gateway_id;
		if ( $token_id > 0 && $user_id > 0 && $token_user_id === $user_id && '' !== $gateway_id ) {
			$context['token_id']   = $token_id;
			$context['gateway_id'] = $gateway_id;
		}

		return $context;
	}

	public static function remember_checkout_context() {
		if ( ! function_exists( 'WC' ) || empty( $_GET['add-to-cart'] ) ) {
			return;
		}
		$product_id = absint( wp_unslash( $_GET['add-to-cart'] ) );
		$slug       = isset( $_GET['rw_support_slug'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_support_slug'] ) ) : '';
		$token_id   = isset( $_GET['rw_payment_token'] ) ? absint( wp_unslash( $_GET['rw_payment_token'] ) ) : 0;
		$gateway_id = isset( $_GET['rw_payment_gateway'] ) ? sanitize_key( wp_unslash( $_GET['rw_payment_gateway'] ) ) : '';
		$token_user = 0;
		if ( $token_id > 0 && class_exists( 'WC_Payment_Tokens' ) ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( is_object( $token ) && method_exists( $token, 'get_user_id' ) ) {
				$token_user = (int) $token->get_user_id();
				if ( method_exists( $token, 'get_gateway_id' ) ) {
					$gateway_id = (string) $token->get_gateway_id();
				}
			}
		}
		$context = self::checkout_context_from_request(
			function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			$product_id,
			$slug,
			isset( $_GET['rw_subscription_id'] ) ? absint( wp_unslash( $_GET['rw_subscription_id'] ) ) : 0,
			isset( $_GET['rw_site_id'] ) ? absint( wp_unslash( $_GET['rw_site_id'] ) ) : 0,
			$token_user,
			$token_id,
			$gateway_id
		);
		if ( ! is_array( $context ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'session' ) || ! $wc->session ) {
			return;
		}
		$wc->session->set( 'rw_support_purchase', $context );
	}

	/**
	 * Select the stored gateway on the checkout page. The customer still confirms payment.
	 *
	 * @return void
	 */
	public static function preselect_saved_gateway() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'session' ) || ! $wc->session || ! method_exists( $wc->session, 'get' ) ) {
			return;
		}
		$context = $wc->session->get( 'rw_support_purchase' );
		if ( ! is_array( $context ) || empty( $context['gateway_id'] ) || ! method_exists( $wc->session, 'set' ) ) {
			return;
		}
		$wc->session->set( 'chosen_payment_method', (string) $context['gateway_id'] );
	}

	/**
	 * @return void
	 */
	public static function enqueue_checkout_preselect() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'session' ) || ! $wc->session || ! method_exists( $wc->session, 'get' ) ) {
			return;
		}
		$context = $wc->session->get( 'rw_support_purchase' );
		if ( ! is_array( $context ) || empty( $context['token_id'] ) || empty( $context['gateway_id'] ) ) {
			return;
		}
		wp_enqueue_script(
			'rw-portal-support-checkout',
			RW_PORTAL_URL . 'assets/js/account-support-checkout.js',
			array(),
			defined( 'RW_PORTAL_VERSION' ) ? RW_PORTAL_VERSION : '0.5.9',
			true
		);
		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script(
				'rw-portal-support-checkout',
				'rwSupportCheckout',
				array(
					'gatewayId' => (string) $context['gateway_id'],
					'tokenId'   => (string) (int) $context['token_id'],
				)
			);
		}
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
