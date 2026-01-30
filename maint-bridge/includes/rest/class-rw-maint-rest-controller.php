<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Rest_Controller {
	public static function register_routes() {
		register_rest_route(
			'reactwoo-maint/v1',
			'/enroll',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'enroll' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/suspend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'suspend' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resume' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/disconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'disconnect' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);
	}

	public static function authorize_request( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return true;
		}

		$verified = RW_Maint_Security::verify_signed_request( $request );
		if ( true === $verified ) {
			return true;
		}

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$allowed = apply_filters( 'rw_maint_allow_request', false, $request );
		if ( $allowed ) {
			return true;
		}

		return new WP_Error( 'rw_maint_auth', 'Authorization required.', array( 'status' => 401 ) );
	}

	public static function enroll( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();

		$portal_site_id  = isset( $payload['portal_site_id'] ) ? absint( $payload['portal_site_id'] ) : 0;
		$subscription_id = isset( $payload['subscription_id'] ) ? absint( $payload['subscription_id'] ) : 0;
		$user_id         = isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0;
		$site_url        = isset( $payload['site_url'] ) ? esc_url_raw( (string) $payload['site_url'] ) : '';
		$client          = isset( $payload['client'] ) && is_array( $payload['client'] ) ? $payload['client'] : array();

		if ( ! $portal_site_id || ! $subscription_id || ! $user_id || '' === $site_url ) {
			return new WP_Error( 'rw_maint_missing', 'Missing required enrollment fields.', array( 'status' => 400 ) );
		}

		$existing = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( $existing ) {
			RW_Maint_Sites::update_site(
				(int) $existing->id,
				array(
					'status'          => 'active',
					'client_name'     => isset( $client['client_name'] ) ? sanitize_text_field( $client['client_name'] ) : $existing->client_name,
					'client_email'    => isset( $client['client_email'] ) ? sanitize_email( $client['client_email'] ) : $existing->client_email,
					'client_company'  => isset( $client['client_company'] ) ? sanitize_text_field( $client['client_company'] ) : $existing->client_company,
					'client_locale'   => isset( $client['client_locale'] ) ? sanitize_text_field( $client['client_locale'] ) : $existing->client_locale,
					'report_email'    => isset( $client['report_email'] ) ? sanitize_email( $client['report_email'] ) : $existing->report_email,
					'report_enabled'  => isset( $client['report_enabled'] ) ? (int) (bool) $client['report_enabled'] : (int) $existing->report_enabled,
				)
			);

			RW_Maint_Audit::log(
				'site_reenrolled',
				array(
					'portal_site_id'  => $portal_site_id,
					'subscription_id' => $subscription_id,
					'user_id'         => $user_id,
				)
			);

			do_action( 'rw_maint_site_enrolled', $existing->id, $payload );

			return rest_ensure_response(
				array(
					'maint_site_id' => (int) $existing->id,
					'status'        => 'active',
					'updated'       => true,
				)
			);
		}

		$site_id = RW_Maint_Sites::create_site(
			array(
				'portal_site_id'  => $portal_site_id,
				'subscription_id' => $subscription_id,
				'user_id'         => $user_id,
				'site_url'        => $site_url,
				'status'          => 'active',
				'client_name'     => isset( $client['client_name'] ) ? sanitize_text_field( $client['client_name'] ) : '',
				'client_email'    => isset( $client['client_email'] ) ? sanitize_email( $client['client_email'] ) : '',
				'client_company'  => isset( $client['client_company'] ) ? sanitize_text_field( $client['client_company'] ) : '',
				'client_locale'   => isset( $client['client_locale'] ) ? sanitize_text_field( $client['client_locale'] ) : '',
				'report_email'    => isset( $client['report_email'] ) ? sanitize_email( $client['report_email'] ) : '',
				'report_enabled'  => isset( $client['report_enabled'] ) ? (int) (bool) $client['report_enabled'] : 1,
			)
		);

		if ( ! $site_id ) {
			return new WP_Error( 'rw_maint_create_failed', 'Unable to enroll site.', array( 'status' => 500 ) );
		}

		RW_Maint_Audit::log(
			'site_enrolled',
			array(
				'portal_site_id'  => $portal_site_id,
				'subscription_id' => $subscription_id,
				'user_id'         => $user_id,
				'site_url'        => $site_url,
			)
		);

		do_action( 'rw_maint_site_enrolled', $site_id, $payload );

		return new WP_REST_Response(
			array(
				'maint_site_id' => $site_id,
				'status'        => 'active',
			),
			201
		);
	}

	public static function suspend( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$updated = RW_Maint_Sites::update_status( $portal_site_id, 'suspended' );
		if ( ! $updated ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		RW_Maint_Audit::log(
			'site_suspended',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_suspended', $portal_site_id );

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => 'suspended',
			)
		);
	}

	public static function resume( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$updated = RW_Maint_Sites::update_status( $portal_site_id, 'active' );
		if ( ! $updated ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		RW_Maint_Audit::log(
			'site_resumed',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_resumed', $portal_site_id );

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => 'active',
			)
		);
	}

	public static function disconnect( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$updated = RW_Maint_Sites::update_status( $portal_site_id, 'disconnected' );
		if ( ! $updated ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		RW_Maint_Audit::log(
			'site_disconnected',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_disconnected', $portal_site_id );

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => 'disconnected',
			)
		);
	}

	private static function extract_portal_site_id( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		if ( isset( $payload['portal_site_id'] ) ) {
			return absint( $payload['portal_site_id'] );
		}

		if ( $request->has_param( 'portal_site_id' ) ) {
			return absint( $request->get_param( 'portal_site_id' ) );
		}

		return 0;
	}
}
