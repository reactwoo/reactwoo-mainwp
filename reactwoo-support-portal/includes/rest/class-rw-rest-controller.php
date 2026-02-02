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
					'site_url' => array(
						'type'     => 'string',
						'required' => false,
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
		$provided_url = esc_url_raw( (string) $request->get_param( 'site_url' ) );

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

		$url_check = self::check_enrollment_url( $site, $provided_url );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}

		$site_secret = RW_Tokens::generate_site_secret();
		RW_Tokens::store_site_secret( (int) $site->id, $site_secret );
		RW_Sites::update_site( (int) $site->id, array( 'status' => 'connected' ) );

		$site = RW_Sites::get_site( (int) $site->id );
		if ( ! $site ) {
			return new WP_Error( 'rw_site_missing', 'Managed site not found.', array( 'status' => 404 ) );
		}

		$maint_response = RW_Maint_Client::enroll_site( $site );
		if ( is_wp_error( $maint_response ) ) {
			RW_Audit::log(
				'maint_enroll_failed',
				array(
					'user_id'         => (int) $site->user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'error'           => $maint_response->get_error_message(),
				)
			);
		} else {
			RW_Audit::log(
				'maint_enroll_sent',
				array(
					'user_id'         => (int) $site->user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'maint_site_id'   => isset( $maint_response['maint_site_id'] ) ? (int) $maint_response['maint_site_id'] : null,
				)
			);
		}

		if ( is_array( $maint_response ) && isset( $maint_response['maint_site_id'] ) ) {
			RW_Sites::update_site( (int) $site->id, array( 'maint_site_id' => (int) $maint_response['maint_site_id'] ) );
		}

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
			'status'    => 'connected',
			'last_seen' => current_time( 'mysql', true ),
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

		$site = RW_Sites::get_site( $site_id );
		if ( $site ) {
			$maint_response = RW_Maint_Client::enroll_site( $site );
			if ( is_wp_error( $maint_response ) ) {
				RW_Audit::log(
					'maint_sync_failed',
					array(
						'user_id'         => (int) $site->user_id,
						'subscription_id' => (int) $site->subscription_id,
						'managed_site_id' => (int) $site->id,
						'error'           => $maint_response->get_error_message(),
					)
				);
			} else {
				RW_Audit::log(
					'maint_sync_sent',
					array(
						'user_id'         => (int) $site->user_id,
						'subscription_id' => (int) $site->subscription_id,
						'managed_site_id' => (int) $site->id,
						'maint_site_id'   => isset( $maint_response['maint_site_id'] ) ? (int) $maint_response['maint_site_id'] : null,
					)
				);

				if ( isset( $maint_response['maint_site_id'] ) ) {
					RW_Sites::update_site( (int) $site->id, array( 'maint_site_id' => (int) $maint_response['maint_site_id'] ) );
				}
			}
		}

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
		return RW_Subscriptions::validate_subscription_for_user( $subscription_id, $user_id );
	}

	private static function get_allowed_sites( $subscription, $user_id ) {
		return RW_Subscriptions::get_allowed_sites( $subscription, $user_id );
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

	private static function check_enrollment_url( $site, $provided_url ) {
		$strict = (int) get_option( RW_Portal_Settings::OPTION_ENROLL_STRICT, 0 );

		if ( '' === $provided_url ) {
			if ( $strict ) {
				return new WP_Error( 'rw_site_url_missing', 'Site URL is required for enrollment.', array( 'status' => 400 ) );
			}

			return true;
		}

		$expected = self::normalize_site_url( $site->site_url );
		$provided = self::normalize_site_url( $provided_url );

		if ( '' === $expected || '' === $provided ) {
			return true;
		}

		if ( $expected !== $provided ) {
			RW_Audit::log(
				'token_url_mismatch',
				array(
					'user_id'         => (int) $site->user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'expected'        => $expected,
					'provided'        => $provided,
				)
			);

			if ( $strict ) {
				return new WP_Error( 'rw_token_url_mismatch', 'Site URL does not match enrollment record.', array( 'status' => 409 ) );
			}
		}

		return true;
	}

	private static function normalize_site_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			$parts = wp_parse_url( 'https://' . ltrim( $url, '/' ) );
		}

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

		return $host . $port . $path;
	}
}
