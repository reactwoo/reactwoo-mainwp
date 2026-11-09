<?php
/**
 * The website a maintenance plan or support pack covers.
 *
 * Checkout stores that website as `_rw_service_domain` (reactwoo-api-manager).
 * Comparison uses the host only. `_reactwoo_domain` and `_reactwoo_license_domain`
 * are licence fields and are never read here.
 *
 * Additional Support applies only when its line-item site matches one entitled
 * maintenance plan for the same customer. Technical Support is standalone and
 * stays on its own line-item site. Minutes with no stored site are kept and
 * flagged for an admin to assign. They are not offered or spent on a site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Sites {
	const SERVICE_DOMAIN_META = '_rw_service_domain';

	/**
	 * Plans and purchased packs an admin still needs to tie to one website.
	 * Listing an id does not change the minutes.
	 */
	const ASSIGNMENT_OPTION = 'rw_support_site_needs_assignment';

	/**
	 * Host only. Lower case, no scheme, no leading www, no port, no path, no trailing dot.
	 *
	 * @param mixed $raw URL or domain.
	 * @return string
	 */
	public static function host_key( $raw ) {
		$value = strtolower( trim( (string) $raw ) );
		if ( '' === $value ) {
			return '';
		}
		$value = preg_replace( '~^[a-z][a-z0-9+.-]*://~', '', $value );
		$value = preg_replace( '~^[^/@\s]+@~', '', $value );
		$value = preg_replace( '~[/?#].*$~', '', $value );
		if ( preg_match( '/^\[([0-9a-f:]+)\](?::\d+)?$/', $value, $bracket ) ) {
			$value = $bracket[1];
		} else {
			$value = preg_replace( '#:\d+$#', '', $value );
		}
		$value = rtrim( (string) $value, '.' );
		if ( 0 === strpos( $value, 'www.' ) ) {
			$value = substr( $value, 4 );
			$value = rtrim( $value, '.' );
		}

		return $value;
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 * @return bool
	 */
	public static function hosts_match( $left, $right ) {
		$a = self::host_key( $left );
		$b = self::host_key( $right );

		return '' !== $a && $a === $b;
	}

	/**
	 * @param mixed              $raw
	 * @param array<int,mixed>   $hosts
	 * @return bool
	 */
	public static function host_matches_any( $raw, array $hosts ) {
		foreach ( $hosts as $host ) {
			if ( self::hosts_match( $raw, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Subscription meta wins. Line items are used only when that meta is empty.
	 * Disagreeing or empty maintenance lines are not a plan site.
	 *
	 * @param mixed              $subscription_domain Subscription meta.
	 * @param array<int,mixed>   $line_domains Maintenance line meta, including empties.
	 * @return array{host:string,status:string}
	 */
	public static function plan_site_from_values( $subscription_domain, array $line_domains ) {
		$host = self::host_key( $subscription_domain );
		if ( '' !== $host ) {
			return array(
				'host'   => $host,
				'status' => 'subscription',
			);
		}
		if ( empty( $line_domains ) ) {
			return array(
				'host'   => '',
				'status' => 'empty',
			);
		}

		$keys = array();
		foreach ( $line_domains as $domain ) {
			$keys[] = self::host_key( $domain );
		}
		$unique = array_values( array_unique( $keys ) );
		if ( 1 === count( $unique ) && '' !== $unique[0] ) {
			return array(
				'host'   => $unique[0],
				'status' => 'lines',
			);
		}
		$filled = array();
		foreach ( $unique as $key ) {
			if ( '' !== $key ) {
				$filled[] = $key;
			}
		}
		if ( empty( $filled ) ) {
			return array(
				'host'   => '',
				'status' => 'empty',
			);
		}

		return array(
			'host'   => '',
			'status' => 'disagree',
		);
	}

	/**
	 * @param object $subscription Woo subscription.
	 * @return array{host:string,status:string}
	 */
	public static function plan_site( $subscription ) {
		if ( ! is_object( $subscription ) ) {
			return array(
				'host'   => '',
				'status' => 'empty',
			);
		}
		if ( ! self::subscription_is_maintenance( $subscription ) ) {
			return array(
				'host'   => '',
				'status' => 'not_maintenance',
			);
		}

		$lines = array();
		if ( method_exists( $subscription, 'get_items' ) ) {
			foreach ( $subscription->get_items() as $item ) {
				if ( ! self::item_is_maintenance( $item ) ) {
					continue;
				}
				$lines[] = self::read_service_domain( $item );
			}
		}

		return self::plan_site_from_values( self::read_service_domain( $subscription ), $lines );
	}

	/**
	 * @param object $subscription Woo subscription.
	 * @return bool
	 */
	public static function subscription_is_maintenance( $subscription ) {
		if ( ! is_object( $subscription ) || ! class_exists( 'RW_Support_Offers' ) || ! method_exists( $subscription, 'get_items' ) ) {
			return false;
		}
		foreach ( $subscription->get_items() as $item ) {
			if ( self::item_is_maintenance( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Entitled maintenance plans for one customer. A plan grants Additional
	 * Support only while it is active or pending cancellation.
	 *
	 * @param int $user_id Customer.
	 * @return array<int,array<string,mixed>>
	 */
	public static function plans_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) || ! class_exists( 'RW_Support_Offers' ) ) {
			return array();
		}
		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( ! is_array( $subscriptions ) ) {
			return array();
		}

		$plans = array();
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) || ! method_exists( $subscription, 'get_status' ) ) {
				continue;
			}
			if ( ! self::subscription_is_maintenance( $subscription ) ) {
				continue;
			}
			$product_ids = RW_Support_Offers::product_ids_from_subscription( $subscription );
			$site        = self::plan_site( $subscription );
			$plans[]     = array(
				'subscription_id' => (int) $subscription->get_id(),
				'user_id'         => $user_id,
				'host'            => $site['host'],
				'status'          => $site['status'],
				'grants'          => RW_Support_Offers::subscription_grants_additional( (string) $subscription->get_status(), $product_ids ),
			);
		}

		return $plans;
	}

	/**
	 * Hosts of entitled plans that name one website. Empty and disagreeing
	 * plans are flagged and are not returned.
	 *
	 * @param int  $user_id Customer.
	 * @param bool $flag Record plans that have no single site.
	 * @return string[]
	 */
	public static function resolved_hosts_for_user( $user_id, $flag = true ) {
		$hosts = array();
		foreach ( self::plans_for_user( $user_id ) as $plan ) {
			if ( empty( $plan['grants'] ) ) {
				continue;
			}
			$status = isset( $plan['status'] ) ? (string) $plan['status'] : '';
			$host   = isset( $plan['host'] ) ? (string) $plan['host'] : '';
			if ( '' !== $host && in_array( $status, array( 'subscription', 'lines' ), true ) ) {
				$hosts[] = $host;
				if ( $flag ) {
					self::clear_plan_flag( $plan['subscription_id'] );
				}
				continue;
			}
			if ( $flag && in_array( $status, array( 'empty', 'disagree' ), true ) ) {
				self::flag_plan( $plan['subscription_id'], $plan['user_id'], $status );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * @param int $user_id Customer.
	 * @return bool
	 */
	public static function customer_has_resolved_plan_site( $user_id ) {
		return ! empty( self::resolved_hosts_for_user( $user_id, true ) );
	}

	/**
	 * Where a purchased pack's minutes may be applied.
	 *
	 * Additional Support is applied only onto the entitled plan whose site
	 * matches. Technical Support is applied on its own site and not onto a plan.
	 * A refusal does not write a balance. Technical Support with no site is
	 * still recorded, with an empty host, so the minutes are not dropped.
	 *
	 * @param string                         $kind additional, technical, or empty.
	 * @param string                         $requires_maintenance Product meta.
	 * @param mixed                          $pack_domain Line-item site.
	 * @param array<int,array<string,mixed>> $plans plans_for_user() rows.
	 * @return array{apply:bool,subscription_id:int,host:string,flag:string,has_maintenance:bool}
	 */
	public static function credit_decision( $kind, $requires_maintenance, $pack_domain, array $plans ) {
		$kind      = (string) $kind;
		$pack_host = self::host_key( $pack_domain );
		$granting  = array();
		foreach ( $plans as $plan ) {
			if ( is_array( $plan ) && ! empty( $plan['grants'] ) ) {
				$granting[] = $plan;
			}
		}
		$has_maintenance = ! empty( $granting );
		$needs_plan      = ( 'additional' === $kind ) || ( 'yes' === (string) $requires_maintenance );

		if ( $needs_plan ) {
			foreach ( $granting as $plan ) {
				$status = isset( $plan['status'] ) ? (string) $plan['status'] : '';
				$host   = isset( $plan['host'] ) ? self::host_key( $plan['host'] ) : '';
				if ( '' === $host || ! in_array( $status, array( 'subscription', 'lines' ), true ) ) {
					continue;
				}
				if ( self::hosts_match( $pack_host, $host ) ) {
					return array(
						'apply'            => true,
						'subscription_id'  => isset( $plan['subscription_id'] ) ? (int) $plan['subscription_id'] : 0,
						'host'             => $host,
						'flag'             => '',
						'has_maintenance'  => true,
					);
				}
			}

			$resolved = false;
			foreach ( $granting as $plan ) {
				$status = isset( $plan['status'] ) ? (string) $plan['status'] : '';
				$host   = isset( $plan['host'] ) ? self::host_key( $plan['host'] ) : '';
				if ( '' !== $host && in_array( $status, array( 'subscription', 'lines' ), true ) ) {
					$resolved = true;
					break;
				}
			}
			if ( ! $resolved ) {
				$flag = 'no_plan_site';
			} elseif ( '' === $pack_host ) {
				$flag = 'missing_pack_site';
			} else {
				$flag = 'site_mismatch';
			}

			return array(
				'apply'           => false,
				'subscription_id' => 0,
				'host'            => '',
				'flag'            => $flag,
				'has_maintenance' => $has_maintenance,
			);
		}

		return array(
			'apply'           => true,
			'subscription_id' => 0,
			'host'            => $pack_host,
			'flag'            => '' === $pack_host ? 'unassigned' : '',
			'has_maintenance' => $has_maintenance,
		);
	}

	/**
	 * Minutes a support request on one website may use.
	 *
	 * Included plan minutes apply only when that website is the plan site.
	 * Purchased minutes apply only when their stored host matches. Minutes
	 * with no host are not included.
	 *
	 * @param array<int,array<string,mixed>> $rows Ledger rows.
	 * @param string                         $now Datetime.
	 * @param mixed                          $context_host Site asking for support.
	 * @param mixed                          $plan_host Plan site. Empty when the plan has none.
	 * @return array{host:string,plan_host:string,purchased:int,unassigned:int,included_applies:bool}
	 */
	public static function context_balance( array $rows, $now, $context_host, $plan_host ) {
		$context_host = self::host_key( $context_host );
		$plan_host    = self::host_key( $plan_host );
		$included     = ( '' !== $context_host && '' !== $plan_host && self::hosts_match( $context_host, $plan_host ) );

		return array(
			'host'              => $context_host,
			'plan_host'         => $plan_host,
			'purchased'         => ( '' !== $context_host && class_exists( 'RW_Support_Balance' ) ) ? RW_Support_Balance::purchased_remainder_for_host( $rows, $now, $context_host ) : 0,
			'unassigned'        => class_exists( 'RW_Support_Balance' ) ? RW_Support_Balance::unassigned_purchased_remainder( $rows, $now ) : 0,
			'included_applies'  => $included,
		);
	}

	/**
	 * @param object|null $object Subscription or line item.
	 * @return string
	 */
	public static function read_service_domain( $object ) {
		if ( is_object( $object ) && method_exists( $object, 'get_meta' ) ) {
			return (string) $object->get_meta( self::SERVICE_DOMAIN_META, true );
		}

		return '';
	}

	/**
	 * @param int    $subscription_id Subscription.
	 * @param int    $user_id Customer.
	 * @param string $reason empty or disagree.
	 * @return void
	 */
	public static function flag_plan( $subscription_id, $user_id, $reason ) {
		$subscription_id = (int) $subscription_id;
		if ( $subscription_id <= 0 ) {
			return;
		}
		$queue = self::queue();
		$queue['plans'][ (string) $subscription_id ] = array(
			'subscription_id' => $subscription_id,
			'user_id'         => (int) $user_id,
			'reason'          => (string) $reason,
		);
		self::store_queue( $queue );
	}

	/**
	 * @param int $subscription_id Subscription.
	 * @return void
	 */
	public static function clear_plan_flag( $subscription_id ) {
		$subscription_id = (int) $subscription_id;
		$queue           = self::queue();
		if ( ! isset( $queue['plans'][ (string) $subscription_id ] ) ) {
			return;
		}
		unset( $queue['plans'][ (string) $subscription_id ] );
		self::store_queue( $queue );
	}

	/**
	 * Keep the order item visible for assignment. Does not change a balance.
	 *
	 * @param int    $order_item_id Order line.
	 * @param int    $user_id Customer.
	 * @param int    $order_id Order.
	 * @param string $reason Why it cannot be used on a site yet.
	 * @param int    $minutes Minutes on the line. Not written to the ledger by this call.
	 * @return void
	 */
	public static function flag_credit( $order_item_id, $user_id, $order_id, $reason, $minutes ) {
		$order_item_id = (int) $order_item_id;
		if ( $order_item_id <= 0 ) {
			return;
		}
		$queue = self::queue();
		$queue['credits'][ (string) $order_item_id ] = array(
			'order_item_id' => $order_item_id,
			'user_id'       => (int) $user_id,
			'order_id'      => (int) $order_id,
			'reason'        => (string) $reason,
			'minutes'       => (int) $minutes,
		);
		self::store_queue( $queue );
	}

	/**
	 * @param int $order_item_id Order line.
	 * @return void
	 */
	public static function clear_credit_flag( $order_item_id ) {
		$order_item_id = (int) $order_item_id;
		$queue         = self::queue();
		if ( ! isset( $queue['credits'][ (string) $order_item_id ] ) ) {
			return;
		}
		unset( $queue['credits'][ (string) $order_item_id ] );
		self::store_queue( $queue );
	}

	/**
	 * Purchased rows that still have no website. The rows are not changed.
	 *
	 * @param array<int,array<string,mixed>> $rows Ledger rows.
	 * @param string                         $now Datetime.
	 * @param int                            $user_id Customer.
	 * @return void
	 */
	public static function flag_unassigned_rows( array $rows, $now, $user_id ) {
		if ( ! class_exists( 'RW_Support_Balance' ) ) {
			return;
		}
		foreach ( RW_Support_Balance::unassigned_packs( $rows, $now ) as $pack ) {
			self::flag_credit( $pack['order_item_id'], $user_id, 0, 'unassigned', $pack['left'] );
		}
	}

	/**
	 * @return array{plans:array<string,array<string,mixed>>,credits:array<string,array<string,mixed>>}
	 */
	public static function queue() {
		$stored = function_exists( 'get_option' ) ? get_option( self::ASSIGNMENT_OPTION, array() ) : array();
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$plans   = ( isset( $stored['plans'] ) && is_array( $stored['plans'] ) ) ? $stored['plans'] : array();
		$credits = ( isset( $stored['credits'] ) && is_array( $stored['credits'] ) ) ? $stored['credits'] : array();

		return array(
			'plans'   => $plans,
			'credits' => $credits,
		);
	}

	/**
	 * @return string
	 */
	public static function assignment_notice() {
		$queue  = self::queue();
		$plans  = count( $queue['plans'] );
		$credits = count( $queue['credits'] );
		if ( 0 === $plans && 0 === $credits ) {
			return '';
		}
		$parts = array();
		if ( $plans > 0 ) {
			$parts[] = $plans . ' maintenance plan' . ( 1 === $plans ? '' : 's' ) . ' with no single website';
		}
		if ( $credits > 0 ) {
			$parts[] = $credits . ' support credit' . ( 1 === $credits ? '' : 's' ) . ' with no website';
		}

		return 'Needs assignment: ' . implode( ', and ', $parts ) . '. Those minutes are kept and are not offered on a site until a website is stored on them.';
	}

	/**
	 * @param object $item Subscription or order line.
	 * @return bool
	 */
	private static function item_is_maintenance( $item ) {
		if ( ! is_object( $item ) || ! class_exists( 'RW_Support_Offers' ) ) {
			return false;
		}
		$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
		$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
		if ( $variation_id > 0 ) {
			return RW_Support_Offers::product_is_maintenance( $variation_id, $product_id );
		}

		return RW_Support_Offers::product_is_maintenance( $product_id, 0 );
	}

	/**
	 * @param array{plans:array<string,array<string,mixed>>,credits:array<string,array<string,mixed>>} $queue
	 * @return void
	 */
	private static function store_queue( array $queue ) {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::ASSIGNMENT_OPTION, $queue, false );
		}
	}
}
