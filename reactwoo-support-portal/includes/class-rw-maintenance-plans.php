<?php
/**
 * Maintenance plan catalogue.
 *
 * The Woo product field `_rw_maintenance_plan_key` is the only classifier.
 * This class turns that key into a capability snapshot. It does not read
 * product names, prices, licence packages, or billing cadence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maintenance_Plans {
	const SCHEMA_VERSION = 1;
	const META_KEY       = '_rw_maintenance_plan_key';

	/**
	 * @param mixed $raw Stored or submitted value.
	 * @return string none|essential|standard|business
	 */
	public static function normalize_key( $raw ) {
		$key = strtolower( trim( (string) $raw ) );
		if ( in_array( $key, array( 'essential', 'standard', 'business' ), true ) ) {
			return $key;
		}

		return 'none';
	}

	/**
	 * @param mixed $raw Plan key. Empty is none.
	 * @return array<string,mixed>
	 */
	public static function resolve( $raw ) {
		$key = self::normalize_key( $raw );
		if ( 'none' === $key ) {
			return array(
				'schema_version'                    => self::SCHEMA_VERSION,
				'is_maintenance'                    => false,
				'plan_key'                          => 'none',
				'reactwoo_product_entitlements'     => array(),
				'third_party_premium_entitlements'  => array(),
			);
		}

		$plans = self::catalogue();
		$plan  = $plans[ $key ];
		$plan['schema_version']                   = self::SCHEMA_VERSION;
		$plan['is_maintenance']                   = true;
		$plan['plan_key']                         = $key;
		$plan['reactwoo_product_entitlements']    = array();
		$plan['third_party_premium_entitlements'] = self::third_party_premium_entitlements();
		$plan['technical_support_minutes']        = (int) $plan['technical_support_minutes_monthly'];
		$plan['backup_frequency']                 = (string) $plan['backup_profile'];
		$plan['uptime_monitoring']                = (bool) $plan['uptime'];
		$plan['ssl_monitoring']                   = (bool) $plan['ssl'];

		return $plan;
	}

	/**
	 * Included support minutes belong to the service anniversary, not the
	 * Woo billing cycle and not the calendar month.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	/**
	 * Commercial eligibility for plugins such as Elementor Pro or Rank Math Pro.
	 * Empty until counsel confirms a list. These slugs are never sent to the licence server.
	 *
	 * @return array<int,string>
	 */
	public static function third_party_premium_entitlements() {
		return array();
	}

	public static function catalogue() {
		$shared = array(
			'site_limit'       => 1,
			'managed_updates'  => true,
			'reports'          => true,
			'uptime'           => true,
			'ssl'              => true,
			'domain_monitoring' => true,
			'vulnerability_monitoring' => true,
		);

		return array(
			'essential' => array_merge(
				$shared,
				array(
					'backup_profile'                     => 'essential',
					'performance_monitoring'             => false,
					'priority_support'                   => false,
					'technical_support_minutes_monthly'  => 0,
				)
			),
			'standard'  => array_merge(
				$shared,
				array(
					'backup_profile'                     => 'standard',
					'performance_monitoring'             => false,
					'priority_support'                   => false,
					'technical_support_minutes_monthly'  => 60,
				)
			),
			'business'  => array_merge(
				$shared,
				array(
					'backup_profile'                     => 'business',
					'performance_monitoring'             => false,
					'priority_support'                   => true,
					'technical_support_minutes_monthly'  => 120,
				)
			),
		);
	}
}
