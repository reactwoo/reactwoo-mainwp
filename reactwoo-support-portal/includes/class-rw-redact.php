<?php
/**
 * Strip credentials before anything is logged or returned in an error.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Redact {
	const SECRET_KEYS = array(
		'password',
		'adminpassword',
		'admin_password',
		'uniqueid',
		'unique_id',
		'application_password',
		'app_password',
		'hmac',
		'hmac_secret',
		'secret',
		'site_secret',
		'connector_secret',
		'license_key',
		'licence_key',
		'api_key',
		'consumer_secret',
		'authorization',
		'temporary_login_url',
		'login_url',
	);

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function scrub( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				if ( self::is_secret_key( $key ) ) {
					continue;
				}
				$clean[ $key ] = self::scrub( $item );
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			return self::scrub_string( $value );
		}

		return $value;
	}

	/**
	 * @param mixed $key
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		$normalized = strtolower( (string) $key );
		return in_array( $normalized, self::SECRET_KEYS, true );
	}

	/**
	 * @param string $value
	 * @return string
	 */
	public static function scrub_string( $value ) {
		$patterns = array(
			'/("(?:adminpassword|uniqueid|uniqueId|password|application_password|site_secret|license_key|licence_key)"\s*:\s*")[^"]*(")/i',
			'/(authorization\s*:\s*bearer\s+)[^\s]+/i',
		);
		$replacements = array( '$1[redacted]$2', '$1[redacted]' );

		return (string) preg_replace( $patterns, $replacements, $value );
	}
}
