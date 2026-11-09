<?php
/**
 * Applies a hub settings template through Wordfence's own configuration API.
 *
 * Supported paths, in the order Wordfence and the MainWP child use them:
 * - wfConfig::set() for ordinary options
 * - wfConfig::set_ser() for the scan schedule, then wfScanner::scheduleScans()
 * - wfWAF::getInstance()->getStorageEngine()->setConfig() for firewall options
 * - WordfenceLS\Controller_Settings::set() for login security and 2FA
 *
 * This class does not write wp_wfConfig, wp_wfls_settings, or any other table.
 * If those methods are not loaded, application stops and reports the gap.
 *
 * Extended Protection (the auto_prepend file and .user.ini change) is not
 * installed. importSettings() needs a Wordfence cloud token, which this
 * template does not have, so values are applied locally with the methods above.
 * reCAPTCHA is not switched on, because the template has no site key.
 * Two-factor authentication can be required, but each person still has to
 * enrol an authenticator app. A grace period covers that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Wordfence_Template {
	const SECRET_KEYS = array(
		'apikey',
		'api_key',
		'licence',
		'licence_key',
		'license',
		'license_key',
		'password',
		'secret',
		'authorization',
		'ispaid',
		'keytype',
	);

	/**
	 * @param mixed  $template Hub template.
	 * @param string $client_email Client alert address.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( $template, $client_email ) {
		$template = self::sanitise( $template );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		if ( ! self::config_api_ready() ) {
			return new WP_Error(
				'rw_wordfence_config_unavailable',
				'Wordfence settings were not changed because its configuration methods are not loaded. Nothing was written directly to the database.',
				array( 'status' => 409 )
			);
		}

		$limits = array();
		$emails = self::alert_emails( $template, $client_email );
		if ( '' === $emails ) {
			$limits[] = 'alert_emails_missing';
		} else {
			$template['wordfence']['alertEmails'] = $emails;
		}

		foreach ( $template['wordfence'] as $key => $value ) {
			wfConfig::set( $key, $value );
		}

		$waf_ready = self::waf_api_ready();
		foreach ( $template['waf'] as $key => $value ) {
			if ( 'learningModeGracePeriodDays' === $key ) {
				continue;
			}
			wfConfig::set( $key, $value );
			if ( $waf_ready ) {
				wfWAF::getInstance()->getStorageEngine()->setConfig( $key, $value );
			}
		}

		if ( isset( $template['waf']['learningModeGracePeriodDays'] ) ) {
			$days = (int) $template['waf']['learningModeGracePeriodDays'];
			$until = self::now() + ( max( 1, $days ) * 86400 );
			wfConfig::set( 'learningModeGracePeriod', $until );
			if ( $waf_ready ) {
				wfWAF::getInstance()->getStorageEngine()->setConfig( 'learningModeGracePeriodEnabled', 1 );
				wfWAF::getInstance()->getStorageEngine()->setConfig( 'learningModeGracePeriod', $until );
			} else {
				$limits[] = 'waf_storage_unavailable';
			}
		} elseif ( ! $waf_ready && ! empty( $template['waf'] ) ) {
			$limits[] = 'waf_storage_unavailable';
		}

		if ( isset( $template['serialized']['scanSched'] ) ) {
			if ( method_exists( 'wfConfig', 'set_ser' ) ) {
				wfConfig::set_ser( 'scanSched', $template['serialized']['scanSched'] );
			} else {
				wfConfig::set( 'scanSched', $template['serialized']['scanSched'] );
				$limits[] = 'scan_schedule_not_serialised';
			}
			if ( class_exists( 'wfScanner' ) && method_exists( 'wfScanner', 'shared' ) ) {
				$scanner = wfScanner::shared();
				if ( is_object( $scanner ) && method_exists( $scanner, 'scheduleScans' ) ) {
					$scanner->scheduleScans();
				} else {
					$limits[] = 'scan_not_rescheduled';
				}
			} else {
				$limits[] = 'scan_not_rescheduled';
			}
		}

		if ( ! empty( $template['login_security'] ) ) {
			if ( self::login_security_api_ready() ) {
				$settings = \WordfenceLS\Controller_Settings::shared();
				foreach ( $template['login_security'] as $key => $value ) {
					$settings->set( $key, $value );
				}
			} else {
				$limits[] = 'login_security_2fa_not_applied';
			}
		}

		return array(
			'applied'         => true,
			'version'         => $template['version'],
			'approval_status' => $template['approval_status'],
			'limits'          => array_values( array_unique( $limits ) ),
		);
	}

	/**
	 * @param mixed $template
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sanitise( $template ) {
		if ( ! is_array( $template ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence settings template was not recognised.', array( 'status' => 400 ) );
		}
		if ( self::contains_secret_key( $template ) ) {
			return new WP_Error( 'rw_wordfence_template_rejected', 'The Wordfence settings template was rejected because it contained a secret.', array( 'status' => 400 ) );
		}

		$version = isset( $template['version'] ) ? (string) $template['version'] : '';
		if ( ! preg_match( '/^v[0-9]+$/', $version ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence settings template version was not recognised.', array( 'status' => 400 ) );
		}

		$status = isset( $template['approval_status'] ) ? (string) $template['approval_status'] : '';
		if ( 'awaiting_approval' !== $status && 'approved' !== $status ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence settings template approval state was not recognised.', array( 'status' => 400 ) );
		}

		$wordfence = self::scalar_map( isset( $template['wordfence'] ) ? $template['wordfence'] : array() );
		$waf       = self::scalar_map( isset( $template['waf'] ) ? $template['waf'] : array() );
		$login     = self::scalar_map( isset( $template['login_security'] ) ? $template['login_security'] : array() );
		if ( is_wp_error( $wordfence ) || is_wp_error( $waf ) || is_wp_error( $login ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence settings template contained a value that is not allowed.', array( 'status' => 400 ) );
		}

		$serial = array();
		if ( isset( $template['serialized'] ) ) {
			if ( ! is_array( $template['serialized'] ) ) {
				return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
			}
			if ( isset( $template['serialized']['scanSched'] ) ) {
				$matrix = self::scan_matrix( $template['serialized']['scanSched'] );
				if ( is_wp_error( $matrix ) ) {
					return $matrix;
				}
				$serial['scanSched'] = $matrix;
			}
		}

		$support = '';
		if ( isset( $template['support_alert_email'] ) && '' !== (string) $template['support_alert_email'] ) {
			$support = function_exists( 'sanitize_email' ) ? sanitize_email( (string) $template['support_alert_email'] ) : (string) $template['support_alert_email'];
			if ( '' === $support ) {
				return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence support alert address was not recognised.', array( 'status' => 400 ) );
			}
		}

		return array(
			'version'              => $version,
			'approval_status'      => $status,
			'support_alert_email'  => $support,
			'wordfence'            => $wordfence,
			'waf'                  => $waf,
			'serialized'           => $serial,
			'login_security'       => $login,
		);
	}

	/**
	 * @param array<string,mixed> $template Sanitised template.
	 * @param string              $client_email
	 * @return string
	 */
	public static function alert_emails( array $template, $client_email ) {
		$emails = array();
		$client = self::email_address( $client_email );
		if ( '' !== $client ) {
			$emails[] = $client;
		}
		$support = isset( $template['support_alert_email'] ) ? self::email_address( $template['support_alert_email'] ) : '';
		if ( '' !== $support && ! in_array( $support, $emails, true ) ) {
			$emails[] = $support;
		}

		return implode( ',', $emails );
	}

	/**
	 * @return bool
	 */
	public static function config_api_ready() {
		return class_exists( 'wfConfig' ) && method_exists( 'wfConfig', 'set' );
	}

	/**
	 * @return bool
	 */
	public static function waf_api_ready() {
		if ( ! class_exists( 'wfWAF' ) || ! method_exists( 'wfWAF', 'getInstance' ) ) {
			return false;
		}
		$waf = wfWAF::getInstance();
		if ( ! is_object( $waf ) || ! method_exists( $waf, 'getStorageEngine' ) ) {
			return false;
		}
		$engine = $waf->getStorageEngine();

		return is_object( $engine ) && method_exists( $engine, 'setConfig' );
	}

	/**
	 * @return bool
	 */
	public static function login_security_api_ready() {
		return class_exists( '\WordfenceLS\Controller_Settings' ) && method_exists( '\WordfenceLS\Controller_Settings', 'shared' );
	}

	/**
	 * @return int
	 */
	public static function now() {
		if ( isset( $GLOBALS['rw_wordfence_now'] ) ) {
			return (int) $GLOBALS['rw_wordfence_now'];
		}

		return time();
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public static function contains_secret_key( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( in_array( strtolower( (string) $key ), self::SECRET_KEYS, true ) ) {
				return true;
			}
			if ( is_array( $item ) && self::contains_secret_key( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $map
	 * @return array<string,mixed>|WP_Error
	 */
	private static function scalar_map( $map ) {
		if ( ! is_array( $map ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'Invalid settings.' );
		}
		$clean = array();
		foreach ( $map as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/', $key ) ) {
				return new WP_Error( 'rw_wordfence_template_invalid', 'Invalid setting name.' );
			}
			if ( is_bool( $value ) ) {
				$clean[ $key ] = $value ? 1 : 0;
				continue;
			}
			if ( is_int( $value ) || ( is_float( $value ) && (float) (int) $value === $value ) ) {
				$clean[ $key ] = (int) $value;
				continue;
			}
			if ( is_string( $value ) && strlen( $value ) <= 2000 ) {
				$clean[ $key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
				continue;
			}

			return new WP_Error( 'rw_wordfence_template_invalid', 'Invalid setting value.' );
		}

		return $clean;
	}

	/**
	 * Seven days, each with 24 hours of 0 or 1.
	 *
	 * @param mixed $matrix
	 * @return array<int,array<int,int>>|WP_Error
	 */
	private static function scan_matrix( $matrix ) {
		if ( ! is_array( $matrix ) || 7 !== count( $matrix ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
		}
		$clean = array();
		$day   = 0;
		foreach ( $matrix as $hours ) {
			if ( ! is_array( $hours ) || 24 !== count( $hours ) ) {
				return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
			}
			$clean[ $day ] = array();
			$hour          = 0;
			foreach ( $hours as $enabled ) {
				if ( 0 !== $enabled && 1 !== $enabled && '0' !== $enabled && '1' !== $enabled && true !== $enabled && false !== $enabled ) {
					return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
				}
				$clean[ $day ][ $hour ] = (int) ( (bool) $enabled );
				$hour++;
			}
			$day++;
		}

		return $clean;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	private static function email_address( $value ) {
		$parts = explode( ',', (string) $value );
		$first = trim( (string) $parts[0] );
		if ( function_exists( 'sanitize_email' ) ) {
			return sanitize_email( $first );
		}

		return filter_var( $first, FILTER_VALIDATE_EMAIL ) ? $first : '';
	}
}
