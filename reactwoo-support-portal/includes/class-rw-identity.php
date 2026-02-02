<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Identity {
	public static function from_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		$first_name = get_user_meta( $user_id, 'billing_first_name', true );
		$last_name  = get_user_meta( $user_id, 'billing_last_name', true );
		$full_name  = trim( $first_name . ' ' . $last_name );

		if ( '' === $full_name && $user ) {
			$full_name = $user->display_name;
		}

		$email = get_user_meta( $user_id, 'billing_email', true );
		if ( '' === $email && $user ) {
			$email = $user->user_email;
		}

		$company = get_user_meta( $user_id, 'billing_company', true );
		$phone   = get_user_meta( $user_id, 'billing_phone', true );
		$locale  = get_user_meta( $user_id, 'locale', true );
		if ( '' === $locale ) {
			$locale = get_locale();
		}

		$identity = array(
			'client_name'    => sanitize_text_field( $full_name ),
			'client_email'   => sanitize_email( $email ),
			'client_company' => sanitize_text_field( $company ),
			'client_phone'   => sanitize_text_field( $phone ),
			'client_locale'  => sanitize_text_field( $locale ),
			'report_email'   => sanitize_email( $email ),
			'report_enabled' => 1,
		);

		return apply_filters( 'rw_portal_identity_from_user', $identity, $user_id, $user );
	}

	public static function apply_overrides( array $identity, array $overrides ) {
		$fields = array(
			'client_name'    => 'sanitize_text_field',
			'client_email'   => 'sanitize_email',
			'client_company' => 'sanitize_text_field',
			'client_phone'   => 'sanitize_text_field',
			'client_locale'  => 'sanitize_text_field',
			'report_email'   => 'sanitize_email',
			'report_enabled' => 'intval',
		);

		foreach ( $fields as $field => $sanitize ) {
			if ( ! array_key_exists( $field, $overrides ) ) {
				continue;
			}

			$identity[ $field ] = call_user_func( $sanitize, $overrides[ $field ] );
		}

		if ( isset( $identity['report_enabled'] ) ) {
			$identity['report_enabled'] = $identity['report_enabled'] ? 1 : 0;
		}

		return $identity;
	}
}
