<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Idempotency {
	/**
	 * Reserve an idempotency key, or return the stored response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return null|WP_REST_Response|WP_Error Null when the header is absent.
	 */
	public static function begin( WP_REST_Request $request ) {
		global $wpdb;

		$key = self::key_from_request( $request );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( '' === $key ) {
			return null;
		}

		$table     = RW_Maint_DB::table( 'idempotency' );
		$body_hash = hash( 'sha256', (string) $request->get_body() );
		$now       = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'idempotency_key' => $key,
				'body_hash'       => $body_hash,
				'http_status'     => 0,
				'response_body'   => '',
				'created_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			return null;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key )
		);

		if ( ! $existing ) {
			return new WP_Error( 'rw_maint_idempotency', 'Unable to reserve this request.', array( 'status' => 409 ) );
		}

		if ( ! hash_equals( (string) $existing->body_hash, $body_hash ) ) {
			return new WP_Error( 'rw_maint_idempotency_conflict', 'Idempotency key was already used for a different request.', array( 'status' => 409 ) );
		}

		if ( (int) $existing->http_status < 200 ) {
			return new WP_Error( 'rw_maint_idempotency_pending', 'This request is already in progress.', array( 'status' => 409 ) );
		}

		$data = json_decode( (string) $existing->response_body, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return new WP_REST_Response( $data, (int) $existing->http_status );
	}

	/**
	 * Store the finished response when a key was reserved.
	 *
	 * @param WP_REST_Request           $request Request.
	 * @param WP_REST_Response|WP_Error $result  Handler result.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finish( WP_REST_Request $request, $result ) {
		global $wpdb;

		$key = self::key_from_request( $request );
		if ( is_wp_error( $key ) || '' === $key ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			$status = 500;
			$data   = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
			$body = array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
		} else {
			$status = $result instanceof WP_REST_Response ? (int) $result->get_status() : 200;
			$raw    = $result instanceof WP_REST_Response ? $result->get_data() : $result;
			$body   = is_array( $raw ) ? $raw : array();
		}

		$table = RW_Maint_DB::table( 'idempotency' );
		$wpdb->update(
			$table,
			array(
				'http_status'   => $status,
				'response_body' => wp_json_encode( $body ),
			),
			array(
				'idempotency_key' => $key,
				'http_status'     => 0,
			),
			array( '%d', '%s' ),
			array( '%s', '%d' )
		);

		return $result;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error Empty string when the header is absent.
	 */
	private static function key_from_request( WP_REST_Request $request ) {
		$key = trim( (string) $request->get_header( 'x-rw-idempotency-key' ) );
		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) < 8 || strlen( $key ) > 128 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $key ) ) {
			return new WP_Error( 'rw_maint_idempotency_key', 'Idempotency key is invalid.', array( 'status' => 400 ) );
		}

		return $key;
	}
}
