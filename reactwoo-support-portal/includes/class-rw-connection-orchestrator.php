<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connection_Orchestrator {
	const CONNECTOR_PLUGIN = 'reactwoo-support-connector/reactwoo-support-connector.php';
	const CHILD_PLUGIN     = 'mainwp-child/mainwp-child.php';
	const EXPIRE_HOOK      = 'rw_portal_expire_credential';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'handle_return' ), 1 );
		add_action( self::EXPIRE_HOOK, array( __CLASS__, 'expire_credential' ) );
	}

	public static function start( $user_id, $subscription_id, $site_url, $existing_site_id = 0, array $details = array() ) {
		$subscription = RW_Subscriptions::validate_subscription_for_user( $subscription_id, $user_id );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			return new WP_Error( 'rw_entitlement_not_maintenance', 'This subscription is not a Maintenance plan.' );
		}

		$canonical = RW_Site_Url::assert_public_https( $site_url );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		if ( $existing_site_id ) {
			$site = RW_Sites::get_site( $existing_site_id );
			if ( ! $site || (int) $site->user_id !== (int) $user_id || (int) $site->subscription_id !== (int) $subscription_id ) {
				return new WP_Error( 'rw_site_missing', 'Managed site not found.', array( 'status' => 404 ) );
			}
			if ( ! RW_Site_Url::same_site( $site->site_url, $canonical ) ) {
				return new WP_Error( 'rw_site_url_mismatch', 'Use the website address already saved for this connection.', array( 'status' => 409 ) );
			}
		} else {
			$allowed = RW_Maintenance_Entitlements::allows_another_site(
				$entitlement,
				RW_Sites::count_sites_for_subscription( $subscription_id )
			);
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$site_id = self::create_site_row( $user_id, $subscription_id, $canonical );
			if ( is_wp_error( $site_id ) ) {
				return $site_id;
			}
			$site = RW_Sites::get_site( $site_id );
			self::apply_site_details( $site, $details );
			$site = RW_Sites::get_site( $site_id );
		}

		$existing_snapshot = $subscription->get_meta( RW_Maintenance_Entitlements::SNAPSHOT_META, true );
		if ( empty( $existing_snapshot ) ) {
			RW_Maintenance_Entitlements::snapshot( $subscription );
		}

		$discovery = RW_WordPress_Client::discover( $canonical );
		if ( is_wp_error( $discovery ) ) {
			self::mark_site( $site, RW_Connection_State::FAILED, 'wp_discovery_failed', $discovery->get_error_message() );
			return $discovery;
		}

		$attempt = RW_Connection_Attempts::create( $site );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		$app_id = wp_generate_uuid4();
		RW_Connection_Attempts::update_attempt(
			(int) $attempt['id'],
			array(
				'app_id'    => $app_id,
				'rest_root' => $discovery['rest_root'],
			)
		);

		self::mark_site( $site, RW_Connection_State::AWAITING_AUTHORISATION, '', '', 'v2' );

		$account = self::account_url();
		$success = add_query_arg(
			array(
				'rw_connect' => 'callback',
				'state'      => $attempt['state'],
			),
			$account
		);
		$reject  = add_query_arg(
			array(
				'rw_connect' => 'rejected',
				'state'      => $attempt['state'],
			),
			$account
		);
		$auth    = add_query_arg(
			array(
				'app_name'    => 'ReactWoo Maintenance',
				'app_id'      => $app_id,
				'success_url' => $success,
				'reject_url'  => $reject,
			),
			$discovery['authorization_url']
		);

		RW_Audit::log(
			'maintenance_authorisation_started',
			array(
				'user_id'         => (int) $user_id,
				'subscription_id' => (int) $subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		wp_redirect( $auth );
		exit;
	}

	public static function handle_return() {
		if ( empty( $_GET['rw_connect'] ) ) {
			return;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );

		$mode = sanitize_key( wp_unslash( $_GET['rw_connect'] ) );
		if ( 'rejected' === $mode ) {
			self::handle_rejected();
			return;
		}
		if ( 'resume' === $mode ) {
			self::handle_resume();
			return;
		}
		if ( 'callback' === $mode ) {
			self::handle_callback();
		}
	}

	public static function expire_credential( $public_id ) {
		$attempt = RW_Connection_Attempts::get_by_public_id( $public_id );
		$secret  = RW_Credential_Vault::pull( $public_id );
		if ( ! $attempt || is_wp_error( $secret ) || empty( $secret['password'] ) || empty( $secret['user_login'] ) ) {
			return;
		}

		if ( ! empty( $attempt->rest_root ) && ! empty( $attempt->app_id ) ) {
			$uuid = RW_WordPress_Client::find_application_password_uuid( $attempt->rest_root, $secret['user_login'], $secret['password'], $attempt->app_id );
			if ( ! is_wp_error( $uuid ) ) {
				RW_WordPress_Client::revoke_application_password( $attempt->rest_root, $secret['user_login'], $secret['password'], $uuid );
			}
		}
	}

	private static function handle_callback() {
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$success  = isset( $_GET['success'] ) ? sanitize_text_field( wp_unslash( $_GET['success'] ) ) : '';
		$login    = isset( $_GET['user_login'] ) ? sanitize_user( wp_unslash( $_GET['user_login'] ) ) : '';
		$site_url = isset( $_GET['site_url'] ) ? esc_url_raw( wp_unslash( $_GET['site_url'] ) ) : '';
		$password = isset( $_GET['password'] ) ? (string) wp_unslash( $_GET['password'] ) : '';
		$password = preg_replace( '/\s+/', '', $password );

		if ( 'false' === $success ) {
			self::handle_rejected();
			return;
		}

		$attempt = RW_Connection_Attempts::consume( $state );
		if ( is_wp_error( $attempt ) ) {
			self::finish_redirect( $attempt->get_error_message(), 'error' );
		}

		if ( ! is_user_logged_in() || (int) get_current_user_id() !== (int) $attempt->user_id ) {
			$stored = RW_Credential_Vault::store(
				$attempt->public_id,
				array(
					'password'   => $password,
					'user_login' => $login,
					'site_url'   => $site_url,
				)
			);
			$password = '';
			if ( is_wp_error( $stored ) ) {
				self::mark_attempt_site( $attempt, RW_Connection_State::FAILED, 'session_required', 'Sign in to ReactWoo and connect the website again.' );
				self::finish_redirect( 'Sign in to ReactWoo and connect the website again.', 'error' );
			}

			wp_schedule_single_event( time() + RW_Credential_Vault::TTL, self::EXPIRE_HOOK, array( $attempt->public_id ) );
			$resume = add_query_arg(
				array(
					'rw_connect' => 'resume',
					'attempt'    => $attempt->public_id,
				),
				self::account_url()
			);
			wp_safe_redirect( wp_login_url( $resume ) );
			exit;
		}

		self::finish_authorisation( $attempt, $login, $password, $site_url );
	}

	private static function handle_resume() {
		$public_id = isset( $_GET['attempt'] ) ? sanitize_text_field( wp_unslash( $_GET['attempt'] ) ) : '';
		$attempt   = RW_Connection_Attempts::get_by_public_id( $public_id );
		if ( ! $attempt || ! is_user_logged_in() || (int) get_current_user_id() !== (int) $attempt->user_id ) {
			self::finish_redirect( 'Sign in to the account that started this connection.', 'error' );
		}

		$secret = RW_Credential_Vault::pull( $public_id );
		if ( is_wp_error( $secret ) ) {
			self::finish_redirect( $secret->get_error_message(), 'error' );
		}

		self::finish_authorisation(
			$attempt,
			isset( $secret['user_login'] ) ? (string) $secret['user_login'] : '',
			isset( $secret['password'] ) ? (string) $secret['password'] : '',
			isset( $secret['site_url'] ) ? (string) $secret['site_url'] : ''
		);
	}

	private static function handle_rejected() {
		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$attempt = RW_Connection_Attempts::get_by_state( $state );
		if ( $attempt && RW_Connection_State::AWAITING_AUTHORISATION === $attempt->status ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::ACTION_REQUIRED, 'authorisation_rejected', 'WordPress authorisation was declined.' );
		}

		RW_Audit::log( 'maintenance_authorisation_rejected', array() );
		self::finish_redirect( 'WordPress authorisation was declined.', 'error' );
	}

	private static function finish_authorisation( $attempt, $user_login, $password, $reported_site_url ) {
		$site = RW_Sites::get_site( (int) $attempt->managed_site_id );
		if ( ! $site ) {
			self::finish_redirect( 'Managed site not found.', 'error' );
		}

		$subscription = RW_Subscriptions::validate_subscription_for_user( (int) $attempt->subscription_id, (int) $attempt->user_id );
		if ( is_wp_error( $subscription ) ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::FAILED, $subscription->get_error_code(), $subscription->get_error_message() );
			self::finish_redirect( $subscription->get_error_message(), 'error' );
		}

		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::FAILED, 'rw_entitlement_not_maintenance', 'This subscription is not a Maintenance plan.' );
			self::finish_redirect( 'This subscription is not a Maintenance plan.', 'error' );
		}

		$allowed = RW_Maintenance_Entitlements::allows_another_site(
			$entitlement,
			RW_Sites::count_other_sites_for_subscription( (int) $attempt->subscription_id, (int) $site->id )
		);
		if ( is_wp_error( $allowed ) ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::FAILED, $allowed->get_error_code(), $allowed->get_error_message() );
			self::finish_redirect( $allowed->get_error_message(), 'error' );
		}

		$reported = RW_Site_Url::assert_public_https( $reported_site_url );
		if ( is_wp_error( $reported ) || ! RW_Site_Url::same_site( $attempt->expected_site_url, $reported ) ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::FAILED, 'wrong_site', 'The authorised website does not match the one you entered.' );
			self::revoke_quietly( $attempt, $user_login, $password );
			self::finish_redirect( 'The authorised website does not match the one you entered.', 'error' );
		}

		if ( '' === $user_login || '' === $password || empty( $attempt->rest_root ) || empty( $attempt->app_id ) ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::FAILED, 'authorisation_incomplete', 'WordPress did not return a setup credential.' );
			self::finish_redirect( 'WordPress did not finish authorisation. Try Connect WordPress again.', 'error' );
		}

		$me = RW_WordPress_Client::get_current_user( $attempt->rest_root, $user_login, $password );
		if ( is_wp_error( $me ) ) {
			self::fail_and_revoke( $attempt, $user_login, $password, RW_Connection_State::FAILED, 'admin_check_failed', 'WordPress could not confirm the administrator account.' );
		}

		$caps = ( isset( $me['capabilities'] ) && is_array( $me['capabilities'] ) ) ? $me['capabilities'] : array();
		if ( empty( $caps['manage_options'] ) ) {
			self::fail_and_revoke( $attempt, $user_login, $password, RW_Connection_State::FAILED, 'insufficient_capability', 'The WordPress user needs administrator access.' );
		}

		self::mark_site( $site, RW_Connection_State::BOOTSTRAPPING, '', '', 'v2' );

		$ready = self::ensure_active_plugin( $attempt, $user_login, $password, self::CONNECTOR_PLUGIN, 'connector_not_installed', 'Download the site plugin from this page, install and activate it on your website, then connect again.' );
		if ( is_wp_error( $ready ) ) {
			self::fail_and_revoke( $attempt, $user_login, $password, RW_Connection_State::ACTION_REQUIRED, $ready->get_error_code(), $ready->get_error_message() );
		}

		$child = self::ensure_active_plugin( $attempt, $user_login, $password, self::CHILD_PLUGIN, 'maintenance_component_missing', 'The maintenance component is not active on this website yet.' );
		if ( is_wp_error( $child ) ) {
			self::fail_and_revoke( $attempt, $user_login, $password, RW_Connection_State::ACTION_REQUIRED, $child->get_error_code(), $child->get_error_message() );
		}

		$challenge = wp_generate_password( 32, false, false );
		RW_Connection_Attempts::update_attempt(
			(int) $attempt->id,
			array( 'challenge_hash' => hash( 'sha256', $challenge ) )
		);

		$bootstrap = RW_WordPress_Client::bootstrap_connector( $attempt->rest_root, $user_login, $password, home_url(), $challenge );
		$challenge = '';
		if ( is_wp_error( $bootstrap ) ) {
			$code  = 'rw_existing_maintenance_connection' === $bootstrap->get_error_code() ? 'existing_connection' : 'bootstrap_failed';
			$state = 'existing_connection' === $code ? RW_Connection_State::ACTION_REQUIRED : RW_Connection_State::FAILED;
			self::fail_and_revoke( $attempt, $user_login, $password, $state, $code, $bootstrap->get_error_message() );
		}

		$admin    = isset( $bootstrap['admin'] ) ? sanitize_user( (string) $bootstrap['admin'] ) : $user_login;
		$uniqueid = isset( $bootstrap['uniqueid'] ) ? (string) $bootstrap['uniqueid'] : '';
		if ( '' === $admin || '' === $uniqueid ) {
			self::fail_and_revoke( $attempt, $user_login, $password, RW_Connection_State::FAILED, 'unique_id_missing', 'Passwordless maintenance identity was not created.' );
		}

		self::mark_site( $site, RW_Connection_State::CONNECTING_MAINTENANCE, '', '', 'v2' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( RW_Maint_Client::ENROLL_TIMEOUT );
		}
		if ( ! empty( $bootstrap['child_version'] ) ) {
			RW_Sites::update_site( (int) $site->id, array( 'mainwp_child_version' => sanitize_text_field( (string) $bootstrap['child_version'] ) ) );
		}

		$site   = RW_Sites::get_site( (int) $site->id );
		$enroll = RW_Maint_Client::enroll_site(
			$site,
			array(
				'connection' => array(
					'admin'     => $admin,
					'uniqueid'  => $uniqueid,
					'auth_mode' => 'unique_id',
				),
				'maintenance_entitlements' => self::public_entitlements( $entitlement ),
			),
			'conn-' . $attempt->public_id
		);
		$uniqueid = '';

		$operational = ! is_wp_error( $enroll )
			&& ! empty( $enroll['management_connected'] )
			&& ! empty( $enroll['initial_check'] )
			&& ! empty( $enroll['initial_sync'] );

		$revoked = self::revoke_credential( $attempt, $user_login, $password );
		$password = '';

		if ( ! $revoked ) {
			self::mark_attempt_site( $attempt, RW_Connection_State::ACTION_REQUIRED, 'bootstrap_credential_revoke_failed', 'Setup finished, but the temporary WordPress credential could not be removed.' );
			RW_Audit::log(
				'maintenance_bootstrap_credential_revoke_failed',
				array(
					'user_id'         => (int) $attempt->user_id,
					'subscription_id' => (int) $attempt->subscription_id,
					'managed_site_id' => (int) $attempt->managed_site_id,
				)
			);
			self::finish_redirect( 'We connected the site, but need to remove a temporary credential. Contact ReactWoo before using the site as protected.', 'error' );
		}

		if ( ! $operational ) {
			$message = is_wp_error( $enroll ) ? $enroll->get_error_message() : 'Maintenance setup did not finish the first connection check.';
			self::mark_attempt_site( $attempt, RW_Connection_State::ACTION_REQUIRED, 'management_not_connected', $message );
			self::finish_redirect( $message, 'error' );
		}

		RW_Sites::update_site(
			(int) $site->id,
			array(
				'connection_state'   => RW_Connection_State::CONNECTED,
				'connection_method'  => 'unique_id',
				'connection_error_code' => '',
				'connection_error_message' => '',
				'status'             => 'connected',
				'connector_protocol' => 'v2',
				'connected_at'       => current_time( 'mysql', true ),
				'last_bootstrap_at'  => current_time( 'mysql', true ),
				'last_check_at'      => current_time( 'mysql', true ),
				'last_sync_at'       => current_time( 'mysql', true ),
				'maint_site_id'      => isset( $enroll['maint_site_id'] ) ? (int) $enroll['maint_site_id'] : 0,
			)
		);
		RW_Connection_Attempts::update_attempt(
			(int) $attempt->id,
			array(
				'status'       => RW_Connection_State::CONNECTED,
				'completed_at' => current_time( 'mysql', true ),
			)
		);

		RW_Maintenance_Emails::send_connected( RW_Sites::get_site( (int) $site->id ) );
		RW_Audit::log(
			'maintenance_setup_completed',
			array(
				'user_id'         => (int) $attempt->user_id,
				'subscription_id' => (int) $attempt->subscription_id,
				'managed_site_id' => (int) $site->id,
				'maint_site_id'   => isset( $enroll['maint_site_id'] ) ? (int) $enroll['maint_site_id'] : 0,
			)
		);

		self::finish_redirect( 'Your website is protected.', 'success' );
	}

	private static function ensure_active_plugin( $attempt, $user_login, $password, $plugin_file, $missing_code, $missing_message ) {
		$plugins = RW_WordPress_Client::list_plugins( $attempt->rest_root, $user_login, $password );
		if ( is_wp_error( $plugins ) ) {
			return new WP_Error( $missing_code, $missing_message, array( 'status' => 409 ) );
		}

		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) || empty( $plugin['plugin'] ) ) {
				continue;
			}
			if ( RW_WordPress_Client::plugin_route( $plugin['plugin'] ) !== RW_WordPress_Client::plugin_route( $plugin_file ) ) {
				continue;
			}
			if ( isset( $plugin['status'] ) && 'active' === $plugin['status'] ) {
				return true;
			}

			$activated = RW_WordPress_Client::activate_plugin( $attempt->rest_root, $user_login, $password, $plugin_file );
			if ( is_wp_error( $activated ) ) {
				return new WP_Error( $missing_code, $missing_message, array( 'status' => 409 ) );
			}

			return true;
		}

		return new WP_Error( $missing_code, $missing_message, array( 'status' => 409 ) );
	}

	private static function fail_and_revoke( $attempt, $user_login, $password, $state, $code, $message ) {
		self::revoke_quietly( $attempt, $user_login, $password );
		self::mark_attempt_site( $attempt, $state, $code, $message );
		if ( RW_Connection_State::ACTION_REQUIRED === $state ) {
			$site = RW_Sites::get_site( (int) $attempt->managed_site_id );
			if ( $site ) {
				RW_Maintenance_Emails::send_action_required( $site, $message );
			}
		}
		self::finish_redirect( $message, 'error' );
	}

	private static function revoke_quietly( $attempt, $user_login, $password ) {
		self::revoke_credential( $attempt, $user_login, $password );
	}

	private static function revoke_credential( $attempt, $user_login, $password ) {
		if ( '' === $user_login || '' === $password || empty( $attempt->rest_root ) || empty( $attempt->app_id ) ) {
			return false;
		}

		$uuid = RW_WordPress_Client::find_application_password_uuid( $attempt->rest_root, $user_login, $password, $attempt->app_id );
		if ( is_wp_error( $uuid ) ) {
			return false;
		}

		$revoked = RW_WordPress_Client::revoke_application_password( $attempt->rest_root, $user_login, $password, $uuid );
		if ( is_wp_error( $revoked ) ) {
			$revoked = RW_WordPress_Client::revoke_application_password( $attempt->rest_root, $user_login, $password, $uuid );
		}

		return ! is_wp_error( $revoked );
	}

	private static function public_entitlements( array $entitlement ) {
		$keys = array(
			'backup_frequency',
			'reports',
			'uptime_monitoring',
			'ssl_monitoring',
			'domain_monitoring',
			'vulnerability_monitoring',
			'performance_monitoring',
		);
		$out = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $entitlement ) ) {
				$out[ $key ] = $entitlement[ $key ];
			}
		}

		return $out;
	}

	private static function apply_site_details( $site, array $details ) {
		if ( ! $site || empty( $details ) ) {
			return;
		}

		$update = array();
		if ( ! empty( $details['site_name'] ) ) {
			$update['site_name'] = sanitize_text_field( (string) $details['site_name'] );
		}
		if ( ! empty( $details['report_email'] ) && is_email( $details['report_email'] ) ) {
			$update['report_email'] = sanitize_email( $details['report_email'] );
		}
		if ( array_key_exists( 'report_enabled', $details ) ) {
			$update['report_enabled'] = (int) (bool) $details['report_enabled'];
		}

		if ( ! empty( $update ) ) {
			RW_Sites::update_site( (int) $site->id, $update );
		}
	}

	private static function create_site_row( $user_id, $subscription_id, $site_url ) {
		$host      = wp_parse_url( $site_url, PHP_URL_HOST );
		$identity  = RW_Identity::from_user( $user_id );
		$site_id   = RW_Sites::create_pending_site(
			array(
				'user_id'           => $user_id,
				'subscription_id'   => $subscription_id,
				'site_url'          => $site_url,
				'site_name'         => $host ? $host : $site_url,
				'client_name'       => $identity['client_name'],
				'client_email'      => $identity['client_email'],
				'client_company'    => $identity['client_company'],
				'client_phone'      => $identity['client_phone'],
				'client_locale'     => $identity['client_locale'],
				'report_email'      => $identity['report_email'],
				'report_enabled'    => $identity['report_enabled'],
				'connection_state'  => RW_Connection_State::AWAITING_SITE,
				'connection_method' => 'v2',
			)
		);

		if ( ! $site_id ) {
			return new WP_Error( 'rw_site_create_failed', 'Unable to create site.', array( 'status' => 500 ) );
		}

		return (int) $site_id;
	}

	private static function mark_attempt_site( $attempt, $state, $code, $message ) {
		RW_Connection_Attempts::update_attempt(
			(int) $attempt->id,
			array(
				'status'       => $state,
				'completed_at' => current_time( 'mysql', true ),
			)
		);
		$site = RW_Sites::get_site( (int) $attempt->managed_site_id );
		if ( $site ) {
			self::mark_site( $site, $state, $code, $message, isset( $site->connection_method ) ? $site->connection_method : 'v2' );
		}
	}

	private static function mark_site( $site, $state, $code, $message, $method = '' ) {
		$data = array(
			'connection_state'           => $state,
			'connection_error_code'      => sanitize_key( (string) $code ),
			'connection_error_message'   => substr( sanitize_text_field( (string) $message ), 0, 255 ),
			'last_connection_attempt_at' => current_time( 'mysql', true ),
		);
		if ( '' !== $method ) {
			$data['connection_method'] = $method;
		}

		RW_Sites::update_site( (int) $site->id, $data );
	}

	private static function account_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( RW_Portal_Account::ENDPOINT );
		}

		return home_url( '/' );
	}

	private static function finish_redirect( $message, $type ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}

		wp_safe_redirect( self::account_url() );
		exit;
	}
}
