<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Identity_Sync {
	public static function register() {
		add_action( 'profile_update', array( __CLASS__, 'handle_profile_update' ), 10, 2 );
		add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'handle_address_update' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_user_meta', array( __CLASS__, 'handle_checkout_update' ), 10, 2 );
		add_action( 'woocommerce_subscription_owner_changed', array( __CLASS__, 'handle_subscription_owner_change' ), 10, 3 );
		add_action( 'woocommerce_subscription_ownership_changed', array( __CLASS__, 'handle_subscription_owner_change' ), 10, 3 );
	}

	public static function handle_profile_update( $user_id ) {
		self::sync_user_sites( $user_id, 'profile_update' );
	}

	public static function handle_address_update( $user_id, $load_address ) {
		if ( 'billing' !== $load_address ) {
			return;
		}

		self::sync_user_sites( $user_id, 'billing_update' );
	}

	public static function handle_checkout_update( $user_id ) {
		self::sync_user_sites( $user_id, 'checkout_update' );
	}

	public static function handle_subscription_owner_change( $subscription, $new_user_id = 0, $old_user_id = 0 ) {
		$subscription_id = 0;
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
			$subscription_id = (int) $subscription->get_id();
		} else {
			$subscription_id = absint( $subscription );
		}

		if ( ! $subscription_id ) {
			return;
		}

		$new_user_id = absint( $new_user_id );
		if ( ! $new_user_id && is_object( $subscription ) && method_exists( $subscription, 'get_user_id' ) ) {
			$new_user_id = (int) $subscription->get_user_id();
		}

		if ( ! $new_user_id ) {
			return;
		}

		RW_Sites::update_user_by_subscription( $subscription_id, $new_user_id );

		$sites = RW_Sites::get_sites_by_subscription( $subscription_id );
		self::sync_sites( $sites, $new_user_id, 'subscription_owner_change', $subscription_id );

		RW_Audit::log(
			'subscription_owner_changed',
			array(
				'subscription_id' => $subscription_id,
				'user_id'         => $new_user_id,
				'previous_user_id' => absint( $old_user_id ),
			)
		);
	}

	private static function sync_user_sites( $user_id, $trigger ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$sites = RW_Sites::get_sites_by_user( $user_id );
		self::sync_sites( $sites, $user_id, $trigger );
	}

	private static function sync_sites( $sites, $user_id, $trigger, $subscription_id = 0 ) {
		if ( empty( $sites ) ) {
			return;
		}

		$identity     = RW_Identity::from_user( $user_id );
		$updated      = 0;
		$maint_failed = 0;

		foreach ( $sites as $site ) {
			$report_email = self::resolve_report_email( $site, $identity );

			RW_Sites::update_identity(
				(int) $site->id,
				array(
					'client_name'    => $identity['client_name'],
					'client_email'   => $identity['client_email'],
					'client_company' => $identity['client_company'],
					'client_phone'   => $identity['client_phone'],
					'client_locale'  => $identity['client_locale'],
					'report_email'   => $report_email,
					'report_enabled' => (int) $site->report_enabled,
				)
			);

			$updated++;

			$refreshed = RW_Sites::get_site( (int) $site->id );
			if ( $refreshed ) {
				$maint_result = RW_Maint_Client::enroll_site( $refreshed );
				if ( is_wp_error( $maint_result ) ) {
					$maint_failed++;
					RW_Audit::log(
						'maint_sync_failed',
						array(
							'user_id'         => $user_id,
							'subscription_id' => (int) $site->subscription_id,
							'managed_site_id' => (int) $site->id,
							'error'           => $maint_result->get_error_message(),
						)
					);
				}
			}
		}

		RW_Audit::log(
			'client_sync_auto',
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id ? (int) $subscription_id : null,
				'trigger'         => sanitize_text_field( $trigger ),
				'updated_sites'   => $updated,
				'maint_failed'    => $maint_failed,
			)
		);
	}

	private static function resolve_report_email( $site, array $identity ) {
		if ( ! empty( $site->report_email ) && $site->report_email !== $site->client_email ) {
			return $site->report_email;
		}

		return $identity['report_email'];
	}
}
