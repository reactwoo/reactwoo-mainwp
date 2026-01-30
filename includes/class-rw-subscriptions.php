<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Subscriptions {
	const GRACE_PERIOD_DAYS = 3;

	public static function register() {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'handle_active' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_on-hold', array( __CLASS__, 'handle_on_hold' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'handle_cancelled' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_payment_failed', array( __CLASS__, 'handle_payment_failed' ), 10, 1 );
		add_action( 'rw_portal_suspend_subscription', array( __CLASS__, 'suspend_subscription' ), 10, 1 );
	}

	public static function handle_active( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id ) {
			return;
		}

		self::clear_grace_period( $subscription_id );
		self::update_subscription_sites( $subscription_id, 'connected', 'subscription_active' );
	}

	public static function handle_on_hold( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id ) {
			return;
		}

		if ( self::has_grace_marker( $subscription_id ) ) {
			return;
		}

		self::update_subscription_sites( $subscription_id, 'suspended', 'subscription_on_hold' );
	}

	public static function handle_cancelled( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id ) {
			return;
		}

		self::clear_grace_period( $subscription_id );
		self::update_subscription_sites( $subscription_id, 'suspended', 'subscription_cancelled' );
	}

	public static function handle_payment_failed( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id ) {
			return;
		}

		self::set_grace_marker( $subscription_id );
		$scheduled = wp_next_scheduled( 'rw_portal_suspend_subscription', array( $subscription_id ) );
		if ( $scheduled ) {
			return;
		}

		wp_schedule_single_event(
			time() + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS ),
			'rw_portal_suspend_subscription',
			array( $subscription_id )
		);

		RW_Audit::log(
			'subscription_grace_period_started',
			array(
				'subscription_id' => $subscription_id,
				'grace_days'      => self::GRACE_PERIOD_DAYS,
			)
		);
	}

	public static function suspend_subscription( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription || $subscription->has_status( 'active' ) ) {
			return;
		}

		if ( $subscription->has_status( 'on-hold' ) && ! self::has_grace_marker( $subscription_id ) ) {
			return;
		}

		self::update_subscription_sites( $subscription_id, 'suspended', 'subscription_payment_failed' );
		self::clear_grace_marker( $subscription_id );
	}

	private static function update_subscription_sites( $subscription_id, $status, $event_type ) {
		$updated = RW_Sites::update_status_by_subscription(
			$subscription_id,
			$status,
			array( 'disconnected' )
		);

		RW_Audit::log(
			$event_type,
			array(
				'subscription_id' => $subscription_id,
				'status'          => $status,
				'updated_sites'   => $updated,
			)
		);

		do_action( 'rw_portal_subscription_sites_updated', $subscription_id, $status, $updated );
	}

	private static function clear_grace_period( $subscription_id ) {
		$timestamp = wp_next_scheduled( 'rw_portal_suspend_subscription', array( $subscription_id ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rw_portal_suspend_subscription', array( $subscription_id ) );
		}

		self::clear_grace_marker( $subscription_id );
	}

	private static function get_subscription_id( $subscription ) {
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
			return (int) $subscription->get_id();
		}

		return absint( $subscription );
	}

	private static function set_grace_marker( $subscription_id ) {
		update_post_meta( $subscription_id, '_rw_payment_failed_grace', time() );
	}

	private static function has_grace_marker( $subscription_id ) {
		return (bool) get_post_meta( $subscription_id, '_rw_payment_failed_grace', true );
	}

	private static function clear_grace_marker( $subscription_id ) {
		delete_post_meta( $subscription_id, '_rw_payment_failed_grace' );
	}
}
