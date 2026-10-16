<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Credential_Vault {
	const TTL = 600;

	/**
	 * Store a short-lived encrypted bootstrap payload. The plaintext is not logged.
	 *
	 * @param string $public_id Connection attempt id.
	 * @param array  $payload Secret fields.
	 * @return true|WP_Error
	 */
	public static function store( $public_id, array $payload ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'rw_vault_unavailable', 'Secure credential storage is unavailable.', array( 'status' => 500 ) );
		}

		$key    = self::key();
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( wp_json_encode( $payload ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher || '' === $tag ) {
			return new WP_Error( 'rw_vault_unavailable', 'Secure credential storage is unavailable.', array( 'status' => 500 ) );
		}

		set_transient( self::transient_key( $public_id ), base64_encode( $iv . $tag . $cipher ), self::TTL );

		return true;
	}

	/**
	 * Read and delete a payload.
	 *
	 * @param string $public_id Connection attempt id.
	 * @return array|WP_Error
	 */
	public static function pull( $public_id ) {
		$key_name = self::transient_key( $public_id );
		$blob     = get_transient( $key_name );
		delete_transient( $key_name );

		if ( ! is_string( $blob ) || '' === $blob || ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'rw_credential_missing', 'The setup session expired. Connect the website again.', array( 'status' => 410 ) );
		}

		$raw = base64_decode( $blob, true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 29 ) {
			return new WP_Error( 'rw_credential_missing', 'The setup session expired. Connect the website again.', array( 'status' => 410 ) );
		}

		$json = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'rw_credential_missing', 'The setup session expired. Connect the website again.', array( 'status' => 410 ) );
		}

		return $data;
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|rw-maint-bootstrap', true );
	}

	private static function transient_key( $public_id ) {
		return 'rw_boot_' . hash( 'sha256', (string) $public_id );
	}
}
