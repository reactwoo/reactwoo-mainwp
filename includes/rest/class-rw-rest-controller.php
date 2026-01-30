<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Rest_Controller {
	public static function register_routes() {
		register_rest_route(
			'reactwoo/v1',
			'/sites/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_site' ),
				'permission_callback' => array( __CLASS__, 'require_user' ),
				'args'                => array(
					'subscription_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'site_url'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'site_name'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'client'          => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			'reactwoo/v1',
			'/tokens/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'reactwoo/v1',
			'/sites/heartbeat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'heartbeat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'reactwoo/v1',
			'/sites/(?P<id>\d+)/client-sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'client_sync' ),
				'permission_callback' => array( __CLASS__, 'require_user' ),
			)
		);
	}

	public static function require_user() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error( 'rw_auth_required', 'Authentication required.', array( 'status' => 401 ) );
	}

	public static function create_site( WP_REST_Request $request ) {
		$user_id         = get_current_user_id();
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );
		$site_url        = esc_url_raw( (string) $request->get_param( 'site_url' ) );
		$site_name       = sanitize_text_field( (string) $request->get_param( 'site_name' ) );

		if ( ! $subscription_id || '' === $site_url ) {
			return new WP_Error( 'rw_invalid_payload', 'Subscription ID and site URL are required.', array( 'status' => 400 ) );
		}

		$subscription = self::validate_subscription( $subscription_id, $user_id );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$allowed_sites = self::get_allowed_sites( $subscription, $user_id );
		$site_count    = RW_Sites::count_sites_for_subscription( $subscription_id );

		if ( $allowed_sites > 0 && $site_count >= $allowed_sites ) {
			return new WP_Error( 'rw_site_limit', 'Site limit reached for this subscription.', array( 'status' => 403 ) );
		}

		if ( '' === $site_name ) {
			$host      = wp_parse_url( $site_url, PHP_URL_HOST );
			$site_name = $host ? $host : $site_url;
		}

		$identity = RW_Identity::from_user( $user_id );
		$identity = RW_Identity::apply_overrides( $identity, self::extract_identity_overrides( $request ) );

		$site_id = RW_Sites::create_pending_site(
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
				'site_url'        => $site_url,
				'site_name'       => $site_name,
				'client_name'     => $identity['client_name'],
				'client_email'    => $identity['client_email'],
				'client_company'  => $identity['client_company'],
				'client_phone'    => $identity['client_phone'],
				'client_locale'   => $identity['client_locale'],
				'report_email'    => $identity['report_email'],
				'report_enabled'  => $identity['report_enabled'],
			)
		);

		if ( ! $site_id ) {
			return new WP_Error( 'rw_site_create_failed', 'Unable to create site.', array( 'status' => 500 ) );
		}

		$token = RW_Tokens::create_token( $site_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		RW_Audit::log(
			'site_created',
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
				'managed_site_id' => $site_id,
				'site_url'        => $site_url,
			)
		);

		RW_Audit::log(
			'token_created',
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
				'managed_site_id' => $site_id,
				'expires_at'      => $token['expires_at'],
			)
		);

		return new WP_REST_Response(
			array(
				'managed_site_id' => $site_id,
				'token'           => $token['token'],
				'expires_at'      => $token['expires_at'],
			),
			201
		);
	}

	public static function verify_token( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( '' === $token ) {
			return new WP_Error( 'rw_token_missing', 'Token is required.', array( 'status' => 400 ) );
		}

		$result = RW_Tokens::verify_token( $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$site = RW_Sites::get_site( (int) $result->managed_site_id );
		if ( ! $site ) {
			return new WP_Error( 'rw_site_missing', 'Managed site not found.', array( 'status' => 404 ) );
		}

		$site_secret = RW_Tokens::generate_site_secret();
		RW_Tokens::store_site_secret( (int) $site->id, $site_secret );
		RW_Sites::update_site( (int) $site->id, array( 'status' => 'connected' ) );

		RW_Audit::log(
			'token_verified',
			array(
				'user_id'         => (int) $site->user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		return rest_ensure_response(
			array(
				'site_id'            => (int) $site->id,
				'site_secret'        => $site_secret,
				'maintenance_payload' => array(
					'portal_site_id'  => (int) $site->id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'site_url'        => $site->site_url,
					'client'          => array(
						'client_name'    => $site->client_name,
						'client_email'   => $site->client_email,
						'client_company' => $site->client_company,
						'client_phone'   => $site->client_phone,
						'client_locale'  => $site->client_locale,
						'report_email'   => $site->report_email,
						'report_enabled' => (int) $site->report_enabled,
					),
				),
			)
		);
	}

	public static function heartbeat( WP_REST_Request $request ) {
		$site_id = absint( $request->get_header( 'x-rw-site-id' ) );

		if ( ! $site_id ) {
			return new WP_Error( 'rw_site_id_missing', 'Site ID header missing.', array( 'status' => 400 ) );
		}

		$verified = RW_Security::verify_signed_request( $request, $site_id );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$params = (array) $request->get_json_params();
		$update = array(
			'status' => 'connected',
		);

		if ( isset( $params['connector_version'] ) ) {
			$update['connector_version'] = sanitize_text_field( $params['connector_version'] );
		}

		if ( isset( $params['wp_version'] ) ) {
			$update['wp_version'] = sanitize_text_field( $params['wp_version'] );
		}

		if ( isset( $params['php_version'] ) ) {
			$update['php_version'] = sanitize_text_field( $params['php_version'] );
		}

		RW_Sites::update_site( $site_id, $update );

		RW_Audit::log(
			'site_heartbeat',
			array(
				'managed_site_id' => $site_id,
			)
		);

		return rest_ensure_response(
			array(
				'received' => true,
			)
		);
	}

	public static function client_sync( WP_REST_Request $request ) {
		$site_id = absint( $request->get_param( 'id' ) );

		if ( ! $site_id ) {
			return new WP_Error( 'rw_site_id_missing', 'Site ID is required.', array( 'status' => 400 ) );
		}

		$site = RW_Sites::get_site( $site_id );
		if ( ! $site ) {
			return new WP_Error( 'rw_site_missing', 'Managed site not found.', array( 'status' => 404 ) );
		}

		$identity = RW_Identity::from_user( (int) $site->user_id );
		$identity = RW_Identity::apply_overrides( $identity, self::extract_identity_overrides( $request ) );

		RW_Sites::update_identity( $site_id, $identity );

		RW_Audit::log(
			'client_sync',
			array(
				'user_id'         => (int) $site->user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => $site_id,
			)
		);

		return rest_ensure_response(
			array(
				'site_id' => $site_id,
				'client'  => $identity,
			)
		);
	}

	private static function validate_subscription( $subscription_id, $user_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return new WP_Error( 'rw_subscriptions_missing', 'WooCommerce Subscriptions is required.', array( 'status' => 503 ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return new WP_Error( 'rw_subscription_missing', 'Subscription not found.', array( 'status' => 404 ) );
		}

		if ( (int) $subscription->get_user_id() !== (int) $user_id ) {
			return new WP_Error( 'rw_subscription_owner', 'Subscription owner mismatch.', array( 'status' => 403 ) );
		}

		if ( ! $subscription->has_status( 'active' ) ) {
			return new WP_Error( 'rw_subscription_inactive', 'Subscription is not active.', array( 'status' => 403 ) );
		}

		$allowed = apply_filters( 'rw_portal_subscription_access', true, $subscription, $user_id );
		if ( ! $allowed ) {
			return new WP_Error( 'rw_subscription_denied', 'Subscription access denied.', array( 'status' => 403 ) );
		}

		return $subscription;
	}

	private static function get_allowed_sites( $subscription, $user_id ) {
		$allowed = 0;

		if ( is_object( $subscription ) && method_exists( $subscription, 'get_meta' ) ) {
			$allowed = (int) $subscription->get_meta( 'rw_allowed_sites', true );
			if ( ! $allowed ) {
				$allowed = (int) $subscription->get_meta( 'allowed_sites', true );
			}
		}

		$allowed = apply_filters( 'rw_portal_allowed_sites', $allowed, $subscription, $user_id );

		return (int) $allowed;
	}

	private static function extract_identity_overrides( WP_REST_Request $request ) {
		$overrides = $request->get_param( 'client' );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}

		$fields = array(
			'client_name',
			'client_email',
			'client_company',
			'client_phone',
			'client_locale',
			'report_email',
			'report_enabled',
		);

		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) && ! array_key_exists( $field, $overrides ) ) {
				$overrides[ $field ] = $request->get_param( $field );
			}
		}

		return $overrides;
	}
}
