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
			'/client-update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'client_update' ),
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

		register_rest_route(
			'reactwoo-maint/v1',
			'/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sync' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/reconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reconnect' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/offboard',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'offboard' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_connection' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);


		register_rest_route(
			'reactwoo-maint/v1',
			'/backup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'backup' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/run-updates',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run_updates' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/security-scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'security_scan' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'site_status' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/support-mail',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'RW_Maint_Support_Mail', 'rest_response' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);
	}

	/**
	 * Lightweight endpoint for the portal to verify shared-secret connection. No body required.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function test_connection( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Connection verified. Shared secret is correct.', 'reactwoo-maint-bridge' ),
			)
		);
	}

	public static function authorize_request( WP_REST_Request $request ) {
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

	/**
	 * Signed subscriber update. Does not assign a MainWP site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function client_update( WP_REST_Request $request ) {
		$payload    = (array) $request->get_json_params();
		$subscriber = RW_Maint_MainWP_Clients::subscriber_from_payload( $payload );
		if ( ! $subscriber['customer_id'] || '' === $subscriber['email'] ) {
			return new WP_Error( 'rw_maint_missing', 'Subscriber email and customer ID are required.', array( 'status' => 400 ) );
		}

		$result = RW_Maint_MainWP_Clients::sync(
			$subscriber,
			0,
			array(
				'user_id' => (int) $subscriber['customer_id'],
			)
		);
		if ( is_wp_error( $result ) ) {
			$status = 409;
			if ( 'rw_mainwp_client_missing' === $result->get_error_code() ) {
				$status = 400;
			} elseif ( 'rw_mainwp_clients_unavailable' === $result->get_error_code() ) {
				$status = 502;
			}

			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		RW_Maint_Sites::update_identity_for_user(
			(int) $subscriber['customer_id'],
			array(
				'client_name'    => $subscriber['name'],
				'client_email'   => $subscriber['email'],
				'client_company' => $subscriber['company'],
			)
		);

		return rest_ensure_response(
			array(
				'customer_id'      => (int) $result['customer_id'],
				'mainwp_client_id' => (int) $result['mainwp_client_id'],
				'matched_by'       => $result['matched_by'],
			)
		);
	}

	public static function enroll( WP_REST_Request $request ) {
		$replay = RW_Maint_Idempotency::begin( $request );
		if ( is_wp_error( $replay ) || $replay instanceof WP_REST_Response ) {
			return $replay;
		}

		return RW_Maint_Idempotency::finish( $request, self::enroll_site( $request ) );
	}

	private static function enroll_site( WP_REST_Request $request ) {
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
			$stored_id  = ! empty( $existing->mainwp_site_id ) ? (int) $existing->mainwp_site_id : 0;
			$record     = $stored_id ? RW_Maint_MainWP_Client::lookup_mainwp_site( $stored_id ) : null;
			$record_url = ( $record && isset( $record->url ) ) ? (string) $record->url : null;
			$match_id   = RW_Maint_MainWP_Client::lookup_mainwp_id_for_url( $site_url );
			$target     = RW_Maint_MainWP::resolve_reenroll_target( $stored_id, $record_url, $site_url, $match_id );
			$reuse_id   = ( ! empty( $target['reuse_id'] ) && (int) $target['reuse_id'] !== (int) $target['blocked_id'] ) ? (int) $target['reuse_id'] : 0;

			RW_Maint_Sites::update_site(
				(int) $existing->id,
				array(
					'status'          => 'active',
					'site_url'        => $site_url,
					'subscription_id' => $subscription_id,
					'mainwp_site_id'  => $reuse_id,
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

			$response = self::operational_result( (int) $existing->id );
			$response['updated'] = true;

			return rest_ensure_response( $response );
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

		return new WP_REST_Response( self::operational_result( $site_id ), 201 );
	}

	private static function operational_result( $maint_site_id ) {
		$site      = RW_Maint_Sites::get_site( $maint_site_id );
		$mainwp_id = ( $site && ! empty( $site->mainwp_site_id ) ) ? (int) $site->mainwp_site_id : 0;
		$check     = false;
		$sync      = false;

		if ( $mainwp_id && $site ) {
			$check  = ! is_wp_error( RW_Maint_MainWP_Client::check_site( $site ) );
			$sync   = ! is_wp_error( RW_Maint_MainWP_Client::sync_site( $site ) );
			$site   = RW_Maint_Sites::get_site( $maint_site_id );
		}

		return array(
			'maint_site_id'         => (int) $maint_site_id,
			'mainwp_site_id'        => $mainwp_id,
			'management_connected'  => $mainwp_id > 0 && $check && $sync,
			'initial_check'         => $check,
			'initial_sync'          => $sync,
			'status'                => ( $site && ! empty( $site->status ) ) ? $site->status : 'active',
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

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );

		RW_Maint_Audit::log(
			'site_suspended',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_suspended', $portal_site_id, $site );

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

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );

		RW_Maint_Audit::log(
			'site_resumed',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_resumed', $portal_site_id, $site );

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => 'active',
			)
		);
	}

	public static function offboard( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		if ( ! empty( $site->mainwp_site_id ) ) {
			$removed = RW_Maint_MainWP_Client::disconnect_site( $site );
			if ( is_wp_error( $removed ) ) {
				return new WP_Error( 'rw_maint_offboard_failed', 'Operational access could not be removed.', array( 'status' => 502 ) );
			}
		}

		$updated = RW_Maint_Sites::update_status( $portal_site_id, 'disconnected' );
		if ( ! $updated ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		RW_Maint_Audit::log(
			'site_offboarded',
			array(
				'portal_site_id' => $portal_site_id,
				'mainwp_site_id' => ! empty( $site->mainwp_site_id ) ? (int) $site->mainwp_site_id : 0,
			)
		);

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'mainwp_site_id' => ! empty( $site->mainwp_site_id ) ? (int) $site->mainwp_site_id : 0,
				'status'         => 'disconnected',
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

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );

		RW_Maint_Audit::log(
			'site_disconnected',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_disconnected', $portal_site_id, $site );

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => 'disconnected',
			)
		);
	}

	public static function check( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		RW_Maint_Audit::log(
			'site_checked',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_checked', $portal_site_id, $site );

		return rest_ensure_response(
			array_merge(
				array(
					'portal_site_id' => $portal_site_id,
					'status'         => $site->status,
				),
				RW_Maint_MainWP_Client::component_versions( $portal_site_id )
			)
		);
	}

	public static function sync( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		RW_Maint_Audit::log(
			'site_sync',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_synced', $portal_site_id, $site );

		return rest_ensure_response(
			array_merge(
				array(
					'portal_site_id' => $portal_site_id,
					'status'         => $site->status,
				),
				RW_Maint_MainWP_Client::component_versions( $portal_site_id )
			)
		);
	}

	/**
	 * Read-only synced status for the site linked to this subscription.
	 *
	 * A subscription mismatch uses the same not-found response as a missing site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function site_status( WP_REST_Request $request ) {
		$portal_site_id  = self::extract_portal_site_id( $request );
		$subscription_id = self::extract_subscription_id( $request );
		if ( ! $portal_site_id || ! $subscription_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID and subscription ID are required.', array( 'status' => 400 ) );
		}

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! RW_Maint_Site_Snapshot::site_is_in_scope( $site, $subscription_id ) ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			RW_Maint_Site_Snapshot::build( $site, RW_Maint_MainWP_Status::collect( $site ) )
		);
	}

	public static function reconnect( WP_REST_Request $request ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$updated = RW_Maint_Sites::update_status( $portal_site_id, 'active' );
		if ( ! $updated ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );

		RW_Maint_Audit::log(
			'site_reconnected',
			array(
				'portal_site_id' => $portal_site_id,
			)
		);

		do_action( 'rw_maint_site_reconnected', $portal_site_id, $site );

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => 'active',
			)
		);
	}

	private static function extract_subscription_id( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		if ( isset( $payload['subscription_id'] ) ) {
			return absint( $payload['subscription_id'] );
		}

		if ( $request->has_param( 'subscription_id' ) ) {
			return absint( $request->get_param( 'subscription_id' ) );
		}

		return 0;
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

	public static function backup( WP_REST_Request $request ) {
		return self::customer_mainwp_action( $request, 'backup', 'site_backup_requested', 'rw_maint_site_backup_requested' );
	}

	public static function run_updates( WP_REST_Request $request ) {
		return self::customer_mainwp_action( $request, 'run_updates', 'site_run_updates_requested', 'rw_maint_site_run_updates_requested' );
	}

	public static function security_scan( WP_REST_Request $request ) {
		return self::customer_mainwp_action( $request, 'security_scan', 'site_security_scan_requested', 'rw_maint_site_security_scan_requested' );
	}

	/**
	 * Shared handler for customer-triggered MainWP actions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $client_method MainWP client method suffix (backup|run_updates|security_scan).
	 * @param string          $audit_event Audit event name.
	 * @param string          $hook Unused. The action is not fired again after the client call.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function customer_mainwp_action( WP_REST_Request $request, $client_method, $audit_event, $hook ) {
		$portal_site_id = self::extract_portal_site_id( $request );
		if ( ! $portal_site_id ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! $site ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		$map = array(
			'backup'        => 'backup_site',
			'run_updates'   => 'run_updates',
			'security_scan' => 'security_scan',
		);
		$method = isset( $map[ $client_method ] ) ? $map[ $client_method ] : '';
		if ( '' === $method || ! is_callable( array( 'RW_Maint_MainWP_Client', $method ) ) ) {
			return new WP_Error( 'rw_mainwp_action_unsupported', 'Maintenance action is not available.', array( 'status' => 501 ) );
		}

		$guard = null;
		if ( 'run_updates' === $client_method ) {
			$payload = (array) $request->get_json_params();
			$guard   = RW_Maint_Update_Guard::decide(
				isset( $payload['backup_ref'] ) ? $payload['backup_ref'] : '',
				isset( $payload['snapshot_at'] ) ? $payload['snapshot_at'] : ''
			);
			if ( is_wp_error( $guard ) ) {
				RW_Maint_Audit::log(
					'update_blocked_unprotected',
					array(
						'portal_site_id'  => $portal_site_id,
						'subscription_id' => (int) $site->subscription_id,
						'user_id'         => (int) $site->user_id,
						'reason'          => 'missing_fresh_snapshot',
					)
				);
				return $guard;
			}
			RW_Maint_Sites::update_site(
				(int) $site->id,
				array(
					'last_backup_ref' => $guard['backup_ref'],
					'last_backup_at'  => $guard['backup_at'],
				)
			);
		}

		$result = call_user_func( array( 'RW_Maint_MainWP_Client', $method ), $site );
		if ( is_wp_error( $result ) ) {
			$status = 502;
			if ( 'rw_mainwp_action_unsupported' === $result->get_error_code() ) {
				$status = 501;
			}
			$result->add_data( array( 'status' => $status ) );
			return $result;
		}

		$state = RW_Maint_MainWP_Client::update_run_state( is_array( $result ) ? $result : array() );
		RW_Maint_Audit::log(
			$audit_event,
			array(
				'portal_site_id' => $portal_site_id,
				'started'        => $state['started'],
				'finished'       => $state['finished'],
				'job_id'         => $state['job_id'],
			)
		);

		// The client method already performed the action. Firing $hook here
		// would run it a second time through RW_Maint_MainWP.

		return rest_ensure_response(
			array(
				'portal_site_id' => $portal_site_id,
				'status'         => $site->status,
				'action'         => $client_method,
				'ok'             => true,
				'started'        => $state['started'],
				'finished'       => $state['finished'],
				'job_id'         => $state['job_id'],
				'backup_ref'     => is_array( $guard ) ? $guard['backup_ref'] : '',
			)
		);
	}

}
