<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connection_State {
	const NOT_STARTED             = 'not_started';
	const AWAITING_SITE           = 'awaiting_site';
	const AWAITING_AUTHORISATION  = 'awaiting_authorisation';
	const AUTHORISING             = 'authorising';
	const INSTALLING              = 'installing';
	const BOOTSTRAPPING           = 'bootstrapping';
	const CONNECTING_MAINTENANCE  = 'connecting_maintenance';
	const VERIFYING               = 'verifying';
	const CONNECTED               = 'connected';
	const ACTION_REQUIRED         = 'action_required';
	const FAILED                  = 'failed';

	const LABEL_SETUP_REQUIRED = 'Setup required';
	const LABEL_CONNECTING     = 'Connecting';
	const LABEL_CONNECTED      = 'Connected';
	const LABEL_NEEDS_ATTENTION = 'Needs attention';
	const LABEL_PAUSED         = 'Paused';
	const LABEL_DISCONNECTED   = 'Disconnected';

	public static function states() {
		return array(
			self::NOT_STARTED,
			self::AWAITING_SITE,
			self::AWAITING_AUTHORISATION,
			self::AUTHORISING,
			self::INSTALLING,
			self::BOOTSTRAPPING,
			self::CONNECTING_MAINTENANCE,
			self::VERIFYING,
			self::CONNECTED,
			self::ACTION_REQUIRED,
			self::FAILED,
		);
	}

	public static function is_valid( $state ) {
		return in_array( (string) $state, self::states(), true );
	}

	/**
	 * Customer label for a provisioning state.
	 *
	 * Service lifecycle (paused / disconnected) is applied by the caller from
	 * the subscription or site status, not from this value.
	 *
	 * @param string $state Internal connection state.
	 * @return string
	 */
	public static function customer_label( $state ) {
		switch ( (string) $state ) {
			case self::CONNECTED:
				return self::LABEL_CONNECTED;
			case self::ACTION_REQUIRED:
			case self::FAILED:
				return self::LABEL_NEEDS_ATTENTION;
			case self::AWAITING_AUTHORISATION:
			case self::AUTHORISING:
			case self::INSTALLING:
			case self::BOOTSTRAPPING:
			case self::CONNECTING_MAINTENANCE:
			case self::VERIFYING:
				return self::LABEL_CONNECTING;
			case self::NOT_STARTED:
			case self::AWAITING_SITE:
			default:
				return self::LABEL_SETUP_REQUIRED;
		}
	}
}
