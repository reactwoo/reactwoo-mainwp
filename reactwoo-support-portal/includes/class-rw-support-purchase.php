<?php
/**
 * One-click extra support purchase against a saved WooCommerce token.
 *
 * Revolut's card gateway reads the saved token from process_payment's later
 * arguments and from the wc-revolut_cc-payment-token request field. A public
 * Revolut order id is not available from the account page, so that argument
 * stays empty and a gateway refusal is shown as a failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Purchase {
	const NONCE_ACTION = 'rw_buy_extra_support';

	/**
	 * Immediate saved-card charges stay off unless the owner turns them on.
	 * One-click otherwise starts checkout with the stored method selected.
	 *
	 * @param bool|null $override Test override. Null reads the option.
	 * @return bool
	 */
	public static function saved_method_charge_authorised( $override = null ) {
		if ( null !== $override ) {
			return (bool) $override;
		}
		if ( defined( 'RW_SUPPORT_CHARGE_SAVED_METHOD' ) && RW_SUPPORT_CHARGE_SAVED_METHOD ) {
			return true;
		}
		if ( function_exists( 'get_option' ) && 'yes' === (string) get_option( 'rw_support_charge_saved_method', 'no' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @var callable|null
	 */
	public static $order_factory = null;

	public static function register() {
		add_action( 'wp_ajax_rw_buy_extra_support', array( __CLASS__, 'ajax' ) );
	}

	public static function ajax() {
		$result = self::from_request();
		if ( function_exists( 'wp_send_json' ) ) {
			wp_send_json( $result );
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function from_request() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$nonce_ok = function_exists( 'wp_verify_nonce' ) ? (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION ) : false;
		$can_act  = $user_id > 0 && function_exists( 'current_user_can' ) && current_user_can( 'read' );
		$slug     = isset( $_POST['product_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['product_slug'] ) ) : '';
		$key      = isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$site_id         = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		$token_id        = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;

		$owned_status = '';
		$owned_products = array();
		$owned = false;
		if ( $user_id > 0 && function_exists( 'wcs_get_users_subscriptions' ) ) {
			$subscriptions = wcs_get_users_subscriptions( $user_id );
			if ( is_array( $subscriptions ) ) {
				foreach ( $subscriptions as $subscription ) {
					if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) && (int) $subscription->get_id() === $subscription_id ) {
						$owned = true;
						$owned_status = method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : '';
						$owned_products = RW_Support_Offers::product_ids_from_subscription( $subscription );
						break;
					}
				}
			}
		}

		$product = null;
		$catalogue = RW_Support_Offers::catalogue();
		if ( isset( $catalogue[ $slug ] ) ) {
			$product = RW_Support_Offers::load_product( $slug, (int) $catalogue[ $slug ]['id'] );
		}
		$token = null;
		$gateway = null;
		if ( $token_id > 0 && class_exists( 'WC_Payment_Tokens' ) && function_exists( 'WC' ) ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( is_object( $token ) && method_exists( $token, 'get_gateway_id' ) ) {
				$wc = WC();
				$registry = ( is_object( $wc ) && method_exists( $wc, 'payment_gateways' ) ) ? $wc->payment_gateways() : null;
				$gateways = ( is_object( $registry ) && method_exists( $registry, 'payment_gateways' ) ) ? $registry->payment_gateways() : array();
				$gateway_id = (string) $token->get_gateway_id();
				$gateway = ( is_array( $gateways ) && isset( $gateways[ $gateway_id ] ) ) ? $gateways[ $gateway_id ] : null;
			}
		}

		return self::attempt(
			array(
				'user_id'            => $user_id,
				'can_act'            => $can_act,
				'nonce_ok'           => $nonce_ok,
				'slug'               => $slug,
				'subscription_id'    => $subscription_id,
				'subscription_owned' => $owned,
				'subscription_status'=> $owned_status,
				'product_ids'        => $owned_products,
				'site_id'            => $site_id,
				'idempotency_key'    => $key,
				'product'            => self::product_payload( $product, $slug ),
				'token'              => self::token_payload( $token ),
				'gateway'            => $gateway,
			)
		);
	}

	/**
	 * @param object|null $product Woo product.
	 * @param string      $slug Requested slug.
	 * @return array<string,mixed>|null
	 */
	private static function product_payload( $product, $slug ) {
		if ( ! is_object( $product ) ) {
			return null;
		}
		$purchasable = method_exists( $product, 'is_purchasable' ) ? (bool) $product->is_purchasable() : false;

		return array(
			'id'          => method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0,
			'slug'        => method_exists( $product, 'get_slug' ) ? (string) $product->get_slug() : (string) $slug,
			'name'        => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : (string) $slug,
			'purchasable' => $purchasable,
			'object'      => $product,
		);
	}

	/**
	 * @param object|null $token Woo token.
	 * @return array<string,mixed>|null
	 */
	private static function token_payload( $token ) {
		if ( ! is_object( $token ) || ! method_exists( $token, 'get_id' ) ) {
			return null;
		}

		return array(
			'id'         => (int) $token->get_id(),
			'user_id'    => method_exists( $token, 'get_user_id' ) ? (int) $token->get_user_id() : 0,
			'gateway_id' => method_exists( $token, 'get_gateway_id' ) ? (string) $token->get_gateway_id() : '',
			'last4'      => method_exists( $token, 'get_last4' ) ? (string) $token->get_last4() : '',
		);
	}

	/**
	 * @param array<string,mixed> $input Resolved request.
	 * @return array<string,mixed>
	 */
	public static function attempt( array $input ) {
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		if ( $user_id <= 0 || empty( $input['can_act'] ) ) {
			return self::failure( 'You need to be logged in to buy support.' );
		}
		if ( empty( $input['nonce_ok'] ) ) {
			return self::failure( 'The purchase form expired. Reload the page and try again.' );
		}
		$key = isset( $input['idempotency_key'] ) ? (string) $input['idempotency_key'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{12,80}$/', $key ) ) {
			return self::failure( 'The purchase could not be started. Reload the page and try again.' );
		}
		if ( empty( $input['subscription_owned'] ) ) {
			return self::failure( 'That maintenance plan is not on this account.' );
		}

		$product = isset( $input['product'] ) && is_array( $input['product'] ) ? $input['product'] : null;
		if ( ! $product ) {
			return self::failure( 'That support pack is not available.' );
		}
		$kind = RW_Support_Offers::kind_of( isset( $product['slug'] ) ? (string) $product['slug'] : '', isset( $product['id'] ) ? (int) $product['id'] : 0 );
		if ( '' === $kind ) {
			return self::failure( 'That support pack is not available.' );
		}
		$has_plan = RW_Support_Offers::subscription_grants_additional(
			isset( $input['subscription_status'] ) ? (string) $input['subscription_status'] : '',
			isset( $input['product_ids'] ) && is_array( $input['product_ids'] ) ? $input['product_ids'] : array()
		);
		if ( ! RW_Support_Offers::allows_purchase( ! empty( $product['purchasable'] ), $kind, $has_plan ) ) {
			return self::failure( 'Additional support is available with an active maintenance plan.' );
		}

		$charge_override = array_key_exists( 'charge_authorised', $input ) ? $input['charge_authorised'] : null;
		if ( ! self::saved_method_charge_authorised( $charge_override ) ) {
			return self::failure( 'Confirm this pack at checkout. A saved card is not charged from this page.' );
		}

		$token = isset( $input['token'] ) && is_array( $input['token'] ) ? $input['token'] : null;
		$gateway = isset( $input['gateway'] ) ? $input['gateway'] : null;
		if ( ! $token || (int) $token['user_id'] !== $user_id ) {
			return self::failure( 'The saved card is not on this account.' );
		}
		if ( ! is_object( $gateway ) || ! isset( $gateway->id ) || (string) $gateway->id !== (string) $token['gateway_id'] ) {
			return self::failure( 'The saved card cannot be used for this payment.' );
		}
		if ( ! method_exists( $gateway, 'supports' ) || ! $gateway->supports( 'tokenization' ) ) {
			return self::failure( 'The saved card cannot be charged from this page.' );
		}

		$lock = self::lock_name( $user_id, $key );
		$existing = self::read_lock( $lock );
		if ( is_array( $existing ) && isset( $existing['result'] ) && is_array( $existing['result'] ) ) {
			return $existing['result'];
		}
		if ( is_array( $existing ) && isset( $existing['state'] ) && 'pending' === $existing['state'] ) {
			return self::failure( 'This payment is already being processed.' );
		}
		self::write_lock( $lock, array( 'state' => 'pending' ) );

		$order = self::create_order( $input, $product );
		if ( is_wp_error( $order ) ) {
			$result = self::failure( $order->get_error_message() );
			self::write_lock( $lock, array( 'state' => 'done', 'result' => $result ) );
			return $result;
		}

		self::stamp_order(
			$order,
			isset( $input['subscription_id'] ) ? (int) $input['subscription_id'] : 0,
			isset( $input['site_id'] ) ? (int) $input['site_id'] : 0
		);
		if ( method_exists( $order, 'set_payment_method' ) ) {
			$order->set_payment_method( $gateway );
		}
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		try {
			$payment = self::invoke_payment( $gateway, (int) $order->get_id(), (int) $token['id'] );
		} catch ( Exception $e ) {
			$payment = new WP_Error( 'rw_support_charge', 'The card payment did not complete.' );
		}

		if ( is_wp_error( $payment ) || ! is_array( $payment ) || ! isset( $payment['result'] ) || 'success' !== $payment['result'] ) {
			if ( method_exists( $order, 'update_status' ) ) {
				$order->update_status( 'failed', 'Saved card payment was not completed.' );
			}
			$result = self::failure( 'The card payment did not complete. No extra support was added.' );
			self::write_lock( $lock, array( 'state' => 'done', 'result' => $result ) );
			return $result;
		}

		$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		if ( in_array( $status, array( 'failed', 'cancelled', 'refunded' ), true ) ) {
			$result = self::failure( 'The card payment did not complete. No extra support was added.' );
			self::write_lock( $lock, array( 'state' => 'done', 'result' => $result ) );
			return $result;
		}

		$result = array(
			'result'   => 'success',
			'order_id' => (int) $order->get_id(),
			'message'  => 'Payment received. Order ' . (int) $order->get_id() . ' is confirmed.',
		);
		self::write_lock( $lock, array( 'state' => 'done', 'result' => $result ) );

		return $result;
	}

	/**
	 * Map a gateway's process_payment parameters onto this charge.
	 *
	 * @param array<int,string> $names Parameter names.
	 * @param int               $order_id Order.
	 * @param int               $token_id WooCommerce token id.
	 * @return array<int,mixed>
	 */
	public static function payment_arguments( array $names, $order_id, $token_id ) {
		$args = array();
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( in_array( $name, array( 'order_id', 'wc_order_id' ), true ) ) {
				$args[] = (int) $order_id;
			} elseif ( false !== strpos( $name, 'token' ) ) {
				$args[] = (string) $token_id;
			} elseif ( false !== strpos( $name, 'saved' ) ) {
				$args[] = true;
			} elseif ( false !== strpos( $name, 'save' ) || false !== strpos( $name, 'express' ) || false !== strpos( $name, 'reload' ) || false !== strpos( $name, 'capture' ) ) {
				$args[] = false;
			} else {
				$args[] = '';
			}
		}

		return $args;
	}

	/**
	 * @param object $gateway Payment gateway.
	 * @param int    $order_id Order.
	 * @param int    $token_id WooCommerce token id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function invoke_payment( $gateway, $order_id, $token_id ) {
		if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'process_payment' ) ) {
			return new WP_Error( 'rw_support_gateway', 'The saved card cannot be charged from this page.' );
		}
		$gateway_id = isset( $gateway->id ) ? (string) $gateway->id : '';
		$_POST[ 'wc-' . $gateway_id . '-payment-token' ] = (string) (int) $token_id;
		$method = new ReflectionMethod( $gateway, 'process_payment' );
		$names  = array();
		foreach ( $method->getParameters() as $param ) {
			$names[] = $param->getName();
		}

		return $method->invokeArgs( $gateway, self::payment_arguments( $names, $order_id, $token_id ) );
	}

	/**
	 * @param object $order Order.
	 * @param int    $subscription_id Subscription.
	 * @param int    $site_id Managed site.
	 */
	public static function stamp_order( $order, $subscription_id, $site_id ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		if ( $subscription_id > 0 ) {
			$order->update_meta_data( '_rw_maintenance_subscription_id', (int) $subscription_id );
		}
		if ( $site_id > 0 ) {
			$order->update_meta_data( '_rw_managed_site_id', (int) $site_id );
		}
	}

	/**
	 * @param array<string,mixed> $input Request.
	 * @param array<string,mixed> $product Product payload.
	 * @return object|WP_Error
	 */
	private static function create_order( array $input, array $product ) {
		if ( is_callable( self::$order_factory ) ) {
			return call_user_func( self::$order_factory, $input, $product );
		}
		if ( ! function_exists( 'wc_create_order' ) || empty( $product['object'] ) ) {
			return new WP_Error( 'rw_support_order', 'The order could not be created.' );
		}
		try {
			$order = wc_create_order(
				array(
					'customer_id' => (int) $input['user_id'],
					'created_via' => 'rw_support_offer',
				)
			);
			if ( is_wp_error( $order ) || ! is_object( $order ) ) {
				return new WP_Error( 'rw_support_order', 'The order could not be created.' );
			}
			$order->add_product( $product['object'], 1 );
			$order->calculate_totals();
			return $order;
		} catch ( Exception $e ) {
			return new WP_Error( 'rw_support_order', 'The order could not be created.' );
		}
	}

	/**
	 * @param string $message Customer message.
	 * @return array<string,mixed>
	 */
	private static function failure( $message ) {
		return array(
			'result'  => 'failure',
			'message' => $message,
		);
	}

	/**
	 * @param int    $user_id Customer.
	 * @param string $key Client key.
	 * @return string
	 */
	private static function lock_name( $user_id, $key ) {
		return 'rw_support_buy_' . md5( (int) $user_id . '|' . $key );
	}

	/**
	 * @param string $name Lock.
	 * @return array<string,mixed>|null
	 */
	private static function read_lock( $name ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$value = get_transient( $name );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * @param string              $name Lock.
	 * @param array<string,mixed> $value State.
	 */
	private static function write_lock( $name, array $value ) {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $name, $value, 900 );
		}
	}
}
