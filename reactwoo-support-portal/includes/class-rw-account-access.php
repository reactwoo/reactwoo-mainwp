<?php
/**
 * Account menu, endpoint access, and maintenance lifecycle decisions.
 *
 * Prices, tax, refunds, and credit expiry are not decided here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Account_Access {
	/**
	 * Lifecycles that may open the Maintenance account screen.
	 *
	 * @return array<int,string>
	 */
	public static function menu_lifecycles() {
		return array( 'active', 'pending_activation', 'payment_failed', 'cancelled_paid_through' );
	}

	/**
	 * @param string   $status WooCommerce subscription status.
	 * @param bool     $is_maintenance Subscription contains a maintenance plan product.
	 * @param int      $end_timestamp Paid-through unix time, or 0 when unknown.
	 * @param int      $now Unix time.
	 * @return string
	 */
	public static function lifecycle( $status, $is_maintenance, $end_timestamp, $now ) {
		if ( ! $is_maintenance ) {
			return 'no_plan';
		}

		$status = (string) $status;
		$end    = (int) $end_timestamp;
		$now    = (int) $now;

		if ( 'pending' === $status ) {
			return 'pending_activation';
		}
		if ( 'on-hold' === $status ) {
			return 'payment_failed';
		}
		if ( 'expired' === $status ) {
			return 'expired';
		}
		if ( 'cancelled' === $status ) {
			return ( $end > $now ) ? 'cancelled_paid_through' : 'expired';
		}
		if ( 'pending-cancel' === $status ) {
			if ( $end > 0 && $end <= $now ) {
				return 'expired';
			}
			return 'cancelled_paid_through';
		}
		if ( 'active' === $status ) {
			return 'active';
		}

		return 'expired';
	}

	/**
	 * @param string $lifecycle Lifecycle key.
	 * @return bool
	 */
	public static function menu_visible( $lifecycle ) {
		return in_array( (string) $lifecycle, self::menu_lifecycles(), true );
	}

	/**
	 * Website connection is allowed while the paid period is still open.
	 *
	 * @param string $lifecycle Lifecycle key.
	 * @return bool
	 */
	public static function can_connect( $lifecycle ) {
		return in_array( (string) $lifecycle, array( 'active', 'cancelled_paid_through' ), true );
	}

	/**
	 * Screen key for the account template. Connected dashboards stay on "connected".
	 *
	 * @param string $lifecycle Lifecycle key.
	 * @param string $site_status Site row status.
	 * @param string $connection_state Provisioning state.
	 * @return string
	 */
	public static function screen_for( $lifecycle, $site_status, $connection_state ) {
		$lifecycle = (string) $lifecycle;
		if ( in_array( $lifecycle, array( 'no_plan', 'pending_activation', 'payment_failed', 'expired' ), true ) ) {
			return $lifecycle;
		}

		$site_status = (string) $site_status;
		if ( in_array( $site_status, array( 'disconnected', 'revoked' ), true ) ) {
			return 'disconnected';
		}
		if ( 'suspended' === $site_status ) {
			return 'paused';
		}

		$connection_state = (string) $connection_state;
		if ( class_exists( 'RW_Connection_State' ) ) {
			if ( RW_Connection_State::CONNECTED === $connection_state || ( '' === $connection_state && 'connected' === $site_status ) ) {
				return 'connected';
			}
			if ( in_array( $connection_state, array( RW_Connection_State::ACTION_REQUIRED, RW_Connection_State::FAILED ), true ) ) {
				return 'attention';
			}
			if ( in_array( $connection_state, array( RW_Connection_State::NOT_STARTED, RW_Connection_State::AWAITING_SITE, '' ), true ) ) {
				return 'setup';
			}
			return 'connecting';
		}

		if ( 'connected' === $site_status || 'connected' === $connection_state ) {
			return 'connected';
		}
		if ( '' === $site_status && '' === $connection_state ) {
			return 'setup';
		}

		return 'connecting';
	}

	/**
	 * @param string $screen Screen key.
	 * @return string
	 */
	public static function status_label( $screen ) {
		switch ( (string) $screen ) {
			case 'pending_activation':
				return 'Waiting for activation';
			case 'payment_failed':
				return 'Payment failed';
			case 'expired':
				return 'Ended';
			case 'disconnected':
				return class_exists( 'RW_Connection_State' ) ? RW_Connection_State::LABEL_DISCONNECTED : 'Disconnected';
			case 'paused':
				return class_exists( 'RW_Connection_State' ) ? RW_Connection_State::LABEL_PAUSED : 'Paused';
			default:
				return '';
		}
	}

	/**
	 * @param string $screen Screen key.
	 * @return string
	 */
	public static function heading( $screen ) {
		switch ( (string) $screen ) {
			case 'disconnected':
				return 'Website disconnected';
			case 'pending_activation':
				return 'Waiting for activation';
			case 'payment_failed':
				return 'Maintenance is paused';
			case 'expired':
				return 'This plan has ended';
			case 'paused':
				return 'Maintenance is paused';
			default:
				return '';
		}
	}

	/**
	 * Customer copy for a lifecycle. No refund, tax, or credit rule is stated.
	 *
	 * @param string $lifecycle Lifecycle key.
	 * @param string $paid_through_label Already formatted date, or empty.
	 * @return string
	 */
	public static function lifecycle_message( $lifecycle, $paid_through_label ) {
		$paid_through_label = trim( (string) $paid_through_label );
		switch ( (string) $lifecycle ) {
			case 'pending_activation':
				return 'This plan is waiting to be activated. Billing is managed in Subscriptions.';
			case 'payment_failed':
				return 'The last payment did not complete, so maintenance is paused. Billing is managed in Subscriptions.';
			case 'cancelled_paid_through':
				if ( '' !== $paid_through_label ) {
					return 'This plan is paid through ' . $paid_through_label . '. Billing and renewal are in Subscriptions.';
				}
				return 'This plan remains available until the paid period ends. Billing and renewal are in Subscriptions.';
			case 'expired':
			case 'no_plan':
				return 'no_plan' === (string) $lifecycle
					? 'Maintenance is not on this account.'
					: 'This plan has ended. Billing is in Subscriptions.';
			default:
				return '';
		}
	}

	/**
	 * @param int   $user_id Customer.
	 * @param int   $selected_id Subscription id from the endpoint, or 0.
	 * @param array $owned_ids Entitled subscription ids.
	 * @return array{allow:bool,status:int,code:string,message:string}
	 */
	public static function endpoint_decision( $user_id, $selected_id, array $owned_ids ) {
		$user_id     = (int) $user_id;
		$selected_id = (int) $selected_id;
		$owned       = array();
		foreach ( $owned_ids as $id ) {
			$owned[] = (int) $id;
		}

		if ( $user_id <= 0 ) {
			return array(
				'allow'   => false,
				'status'  => 401,
				'code'    => 'auth',
				'message' => 'Please log in to manage maintenance.',
			);
		}
		if ( empty( $owned ) ) {
			return array(
				'allow'   => false,
				'status'  => 403,
				'code'    => 'no_plan',
				'message' => 'Maintenance is not on this account.',
			);
		}
		if ( $selected_id > 0 && ! in_array( $selected_id, $owned, true ) ) {
			return array(
				'allow'   => false,
				'status'  => 403,
				'code'    => 'not_owned',
				'message' => 'That maintenance plan is not on this account.',
			);
		}

		return array(
			'allow'   => true,
			'status'  => 200,
			'code'    => 'ok',
			'message' => '',
		);
	}

	/**
	 * Products & licences is only for customers who have a licensed record.
	 *
	 * @param int $record_count Licensed records.
	 * @return bool
	 */
	public static function licences_endpoint_allowed( $record_count ) {
		return (int) $record_count > 0;
	}

	/**
	 * Keep Hosting and Subscriptions as their own items. Maintenance is separate.
	 *
	 * $show_licences null leaves a Products & licences item untouched.
	 * false removes it. true keeps it under that label.
	 *
	 * @param array    $items Menu items.
	 * @param bool     $show_maintenance Entitled maintenance plan.
	 * @param bool|null $show_licences Licensed products, or null when unknown.
	 * @return array
	 */
	public static function shape_menu( array $items, $show_maintenance, $show_licences ) {
		if ( false === $show_licences ) {
			unset( $items['license'] );
		} elseif ( true === $show_licences && isset( $items['license'] ) ) {
			$items['license'] = 'Products & licences';
		}

		if ( ! $show_maintenance ) {
			unset( $items['maintenance'] );
			return $items;
		}
		if ( isset( $items['maintenance'] ) ) {
			return $items;
		}

		$new    = array();
		$placed = false;
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new['maintenance'] = 'Maintenance';
				$placed             = true;
			}
		}
		if ( ! $placed ) {
			$new = array( 'maintenance' => 'Maintenance' ) + $new;
		}

		return $new;
	}

	/**
	 * Account form gate. Token consumption is the caller's job.
	 *
	 * @param array<string,mixed> $input user_id, nonce_ok, token_ok, entitled, owns_target.
	 * @return array{allow:bool,code:string,message:string}
	 */
	public static function authorize_post( array $input ) {
		if ( (int) ( isset( $input['user_id'] ) ? $input['user_id'] : 0 ) <= 0 ) {
			return array(
				'allow'   => false,
				'code'    => 'auth',
				'message' => 'Please log in to manage maintenance.',
			);
		}
		if ( empty( $input['nonce_ok'] ) ) {
			return array(
				'allow'   => false,
				'code'    => 'csrf',
				'message' => 'Security check failed.',
			);
		}
		if ( empty( $input['token_ok'] ) ) {
			return array(
				'allow'   => false,
				'code'    => 'replay',
				'message' => 'This form was already used. Reload the page and try again.',
			);
		}
		if ( empty( $input['entitled'] ) ) {
			return array(
				'allow'   => false,
				'code'    => 'no_plan',
				'message' => 'Maintenance is not on this account.',
			);
		}
		if ( empty( $input['owns_target'] ) ) {
			return array(
				'allow'   => false,
				'code'    => 'ownership',
				'message' => 'That maintenance plan is not on this account.',
			);
		}

		return array(
			'allow'   => true,
			'code'    => 'ok',
			'message' => '',
		);
	}

	/**
	 * @param int   $subscription_id Posted subscription id, or 0.
	 * @param int[] $owned_subscription_ids Entitled ids.
	 * @param int   $site_user_id Owner of the posted site, or 0.
	 * @param int   $site_subscription_id Subscription on that site, or 0.
	 * @param int   $user_id Customer.
	 * @return bool
	 */
	public static function target_owned( $subscription_id, array $owned_subscription_ids, $site_user_id, $site_subscription_id, $user_id ) {
		$user_id = (int) $user_id;
		$owned   = array();
		foreach ( $owned_subscription_ids as $id ) {
			$owned[] = (int) $id;
		}
		$subscription_id = (int) $subscription_id;
		if ( $subscription_id > 0 ) {
			return in_array( $subscription_id, $owned, true );
		}
		$site_user_id = (int) $site_user_id;
		if ( $site_user_id <= 0 || $site_user_id !== $user_id ) {
			return false;
		}
		$site_subscription_id = (int) $site_subscription_id;
		if ( $site_subscription_id > 0 && ! in_array( $site_subscription_id, $owned, true ) ) {
			return false;
		}

		return true;
	}
}
