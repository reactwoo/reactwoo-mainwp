<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Redact {
	const SECRET_KEYS = array(
		'password',
		'adminpassword',
		'admin_password',
		'uniqueid',
		'unique_id',
		'application_password',
		'app_password',
		'secret',
		'site_secret',
		'license_key',
		'licence_key',
		'authorization',
	);

	public static function scrub( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $key => $item ) {
			if ( in_array( strtolower( (string) $key ), self::SECRET_KEYS, true ) ) {
				continue;
			}
			$clean[ $key ] = is_array( $item ) ? self::scrub( $item ) : $item;
		}

		return $clean;
	}
}
