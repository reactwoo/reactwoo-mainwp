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
		add_action( 'woocommerce_subscription_status_pending-cancel', array( __CLASS__, 'handle_pending_cancel' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'handle_cancelled' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_expired', array( __CLASS__, 'handle_expired' ), 10, 1 );
		add_action( 'woocommerce_subscription_renewal_payment_failed', array( __CLASS__, 'handle_payment_failed' ), 10, 1 );
		add_action( 'rw_portal_suspend_subscription', array( __CLASS__, 'suspend_subscription' ), 10, 1 );
		add_action( 'rw_portal_offboard_subscription', array( __CLASS__, 'finish_offboard' ), 10, 1 );
	}

	public static function handle_active( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id || ! self::is_maintenance( $subscription ) ) {
			return;
		}

		self::clear_grace_period( $subscription_id );
		self::clear_offboard( $subscription_id );
		RW_Maintenance_Entitlements::maybe_snapshot( $subscription );
		self::update_subscription_sites( $subscription_id, 'connected', 'subscription_active', array( 'disconnected', 'pending', 'error' ) );
		RW_Maintenance_Emails::maybe_send_welcome( $subscription );
	}

	public static function handle_on_hold( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id || ! self::is_maintenance( $subscription ) ) {
			return;
		}

		if ( ! RW_Subscription_Policy::on_hold_should_suspend( self::has_grace_marker( $subscription_id ) ) ) {
			return;
		}

		self::update_subscription_sites( $subscription_id, 'suspended', 'subscription_on_hold' );
	}

	public static function handle_pending_cancel( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id || ! self::is_maintenance( $subscription ) ) {
			return;
		}

		if ( RW_Subscription_Policy::entitlement_still_paid( self::end_timestamp( $subscription ), time() ) ) {
			RW_Audit::log(
				'subscription_pending_cancel_kept',
				array(
					'subscription_id' => $subscription_id,
				)
			);
			return;
		}

		self::begin_offboarding( $subscription_id, 'subscription_pending_cancel_ended' );
	}

	public static function handle_cancelled( $subscription ) {
		self::handle_final_end( $subscription, 'subscription_cancelled' );
	}

	public static function handle_expired( $subscription ) {
		self::handle_final_end( $subscription, 'subscription_expired' );
	}

	public static function handle_payment_failed( $subscription ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id || ! self::is_maintenance( $subscription ) ) {
			return;
		}

		self::set_grace_marker( $subscription_id );
		$scheduled = wp_next_scheduled( 'rw_portal_suspend_subscription', array( $subscription_id ) );
		if ( $scheduled ) {
			return;
		}

		wp_schedule_single_event(
			time() + ( RW_Subscription_Policy::PAYMENT_GRACE_DAYS * DAY_IN_SECONDS ),
			'rw_portal_suspend_subscription',
			array( $subscription_id )
		);

		RW_Audit::log(
			'subscription_grace_period_started',
			array(
				'subscription_id' => $subscription_id,
				'grace_days'      => RW_Subscription_Policy::PAYMENT_GRACE_DAYS,
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

	public static function finish_offboard( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription || ! self::is_maintenance( $subscription ) ) {
			return;
		}

		if ( $subscription->has_status( 'active' ) || $subscription->has_status( 'pending-cancel' ) ) {
			if ( RW_Subscription_Policy::entitlement_still_paid( self::end_timestamp( $subscription ), time() ) ) {
				self::clear_offboard( $subscription_id );
				return;
			}
		}

		if ( ! RW_Subscription_Policy::should_start_offboarding( self::end_timestamp( $subscription ), time() ) && ! $subscription->has_status( array( 'cancelled', 'expired' ) ) ) {
			return;
		}

		$sites = RW_Sites::get_sites_by_subscription( $subscription_id );
		foreach ( $sites as $site ) {
			RW_Maint_Client::update_site_status( $site, 'offboard' );
		}

		self::update_subscription_sites( $subscription_id, 'disconnected', 'subscription_offboarded', array() );
		delete_post_meta( $subscription_id, '_rw_offboard_scheduled' );
	}

	public static function validate_subscription_for_user( $subscription_id, $user_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return new WP_Error( 'rw_subscriptions_missing', 'WooCommerce Subscriptions is required.', array( 'status' => 503 ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return new WP_Error( 'rw_subscription_missing', 'Subscription not found.', array( 'status' => 404 ) );
		}

		if ( (int) $subscription->get_user_id() !== (int) $user_id ) {
			return new WP_Error( 'rw_subscription_owner', 'Subscription owner mismatch.', array( 'status' => 403 ) );
		}

		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			return new WP_Error( 'rw_entitlement_not_maintenance', 'This subscription is not a Maintenance plan.', array( 'status' => 403 ) );
		}

		$paid = RW_Subscription_Policy::entitlement_still_paid( self::end_timestamp( $subscription ), time() );
		$open = $subscription->has_status( 'active' ) || ( $subscription->has_status( 'pending-cancel' ) && $paid );
		if ( ! $open ) {
			return new WP_Error( 'rw_subscription_inactive', 'Subscription is not active.', array( 'status' => 403 ) );
		}

		$allowed = apply_filters( 'rw_portal_subscription_access', true, $subscription, $user_id );
		if ( ! $allowed ) {
			return new WP_Error( 'rw_subscription_denied', 'Subscription access denied.', array( 'status' => 403 ) );
		}

		return $subscription;
	}

	public static function get_allowed_sites( $subscription, $user_id ) {
		unset( $user_id );
		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			return 0;
		}

		$limit = isset( $entitlement['site_limit'] ) ? (int) $entitlement['site_limit'] : 0;
		return $limit > 0 ? $limit : 0;
	}

	private static function update_subscription_sites( $subscription_id, $status, $event_type, array $exclude_statuses = array( 'disconnected' ) ) {
		$updated = RW_Sites::update_status_by_subscription(
			$subscription_id,
			$status,
			$exclude_statuses
		);

		RW_Audit::log(
			$event_type,
			array(
				'subscription_id' => $subscription_id,
				'status'          => $status,
				'updated_sites'   => $updated,
			)
		);

		RW_Maint_Client::update_subscription_sites( $subscription_id, $status );

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

	private static function is_maintenance( $subscription ) {
		if ( ! is_object( $subscription ) ) {
			return false;
		}
		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		return ! is_wp_error( $entitlement ) && ! empty( $entitlement['is_maintenance'] );
	}

	private static function end_timestamp( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_time' ) ) {
			return null;
		}
		$end = (int) $subscription->get_time( 'end' );
		return $end > 0 ? $end : null;
	}

	private static function handle_final_end( $subscription, $event ) {
		$subscription_id = self::get_subscription_id( $subscription );
		if ( ! $subscription_id || ! self::is_maintenance( $subscription ) ) {
			return;
		}

		self::clear_grace_period( $subscription_id );
		$end = self::end_timestamp( $subscription );
		if ( ! RW_Subscription_Policy::should_start_offboarding( $end, time() ) ) {
			RW_Audit::log(
				'subscription_end_date_missing',
				array(
					'subscription_id' => $subscription_id,
					'event'           => $event,
				)
			);
			return;
		}

		self::begin_offboarding( $subscription_id, $event );
	}

	private static function begin_offboarding( $subscription_id, $event ) {
		self::update_subscription_sites( $subscription_id, 'suspended', $event );
		if ( get_post_meta( $subscription_id, '_rw_offboard_scheduled', true ) ) {
			return;
		}

		update_post_meta( $subscription_id, '_rw_offboard_scheduled', time() );
		wp_schedule_single_event(
			time() + ( RW_Subscription_Policy::OFFBOARD_GRACE_DAYS * DAY_IN_SECONDS ),
			'rw_portal_offboard_subscription',
			array( $subscription_id )
		);
	}

	private static function clear_offboard( $subscription_id ) {
		$timestamp = wp_next_scheduled( 'rw_portal_offboard_subscription', array( $subscription_id ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'rw_portal_offboard_subscription', array( $subscription_id ) );
		}
		delete_post_meta( $subscription_id, '_rw_offboard_scheduled' );
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
