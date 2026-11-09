<?php
/**
 * Wordfence onboarding on the managed site.
 *
 * First enrolment only records conflicting plugins. Installation, a Pro
 * licence, and the settings template wait for a signed portal request.
 * Plugins are deactivated or deleted only when that request confirms it.
 *
 * The compact report for the other reporter is rw_connector_wordfence_report()
 * and the rw_connector_wordfence_report filter. Licence keys are never stored
 * in that report, in the onboarding state, or in an error message.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Wordfence_Onboarding {
	const STATE_OPTION = 'rw_connector_wordfence_state';
	const PLUGIN_FILE  = 'wordfence/wordfence.php';
	const INFO_URL     = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=wordfence';

	public static function register() {
		add_action( 'rw_connector_enrolled', array( __CLASS__, 'on_enrolled' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'reactwoo-connector/v2',
			'/wordfence/onboard',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_onboard' ),
				'permission_callback' => array( __CLASS__, 'require_enrolled' ),
			)
		);

		register_rest_route(
			'reactwoo-connector/v2',
			'/wordfence/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( __CLASS__, 'require_enrolled' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function require_enrolled( WP_REST_Request $request ) {
		$verified = RW_Connector_Signing::verify_request( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! (int) get_option( 'rw_connector_site_id' ) ) {
			return new WP_Error( 'rw_connector_not_connected', 'Connector is not enrolled.', array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Record conflicts when the site first enrols. Do not install or remove anything.
	 *
	 * @return void
	 */
	public static function on_enrolled() {
		$conflicts = RW_Connector_Wordfence_Conflicts::conflicts_in( self::installed_plugin_headers(), false );
		$state     = self::blank_state();
		$state['conflicts'] = self::public_conflicts( $conflicts );
		$state['status']    = RW_Connector_Wordfence_Conflicts::has_blocking( $conflicts ) ? 'conflicts' : 'pending_install';
		self::store_state( $state );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error|array<string,mixed>
	 */
	public static function handle_onboard( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$result = self::run( is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::respond( $result );
	}

	/**
	 * @return WP_REST_Response|array<string,mixed>
	 */
	public static function handle_status() {
		return self::respond( self::public_state( self::load_state() ) );
	}

	/**
	 * @param array<string,mixed> $args
	 * @param callable|null       $installer
	 * @param callable|null       $deleter
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run( array $args, $installer = null, $deleter = null ) {
		$licence_raw = '';
		if ( isset( $args['licence_key'] ) && is_string( $args['licence_key'] ) ) {
			$licence_raw = $args['licence_key'];
		} elseif ( isset( $args['license_key'] ) && is_string( $args['license_key'] ) ) {
			$licence_raw = $args['license_key'];
		}
		unset( $args['licence_key'], $args['license_key'], $args['download_url'], $args['package_url'] );

		$licence = self::normalise_licence_key( $licence_raw );
		$licence_raw = '';
		$limits      = array();
		if ( is_wp_error( $licence ) ) {
			$limits[] = 'licence_rejected';
			$licence  = '';
		}
		$pro_targeted = '' !== $licence;

		$conflicts = RW_Connector_Wordfence_Conflicts::conflicts_in( self::installed_plugin_headers(), $pro_targeted );
		$removed   = self::removed_history();

		if ( self::is_confirmed( isset( $args['confirm'] ) ? $args['confirm'] : false ) ) {
			$plan = RW_Connector_Wordfence_Conflicts::removal_plan( isset( $args['files'] ) ? $args['files'] : array(), $conflicts );
			if ( ! empty( $plan ) ) {
				$deleted = is_callable( $deleter ) ? call_user_func( $deleter, $plan ) : self::delete_plugin_files( $plan );
				if ( is_wp_error( $deleted ) ) {
					self::store_state( self::state_from( $conflicts, $removed, $limits, '', false, 'conflicts' ) );
					return $deleted;
				}
				if ( is_array( $deleted ) ) {
					$removed = array_values( array_unique( array_merge( $removed, $deleted ) ) );
				}
				$conflicts = RW_Connector_Wordfence_Conflicts::conflicts_in( self::installed_plugin_headers(), $pro_targeted );
			}
		}

		$installed = self::is_wordfence_active();
		if ( ! $installed && RW_Connector_Wordfence_Conflicts::has_blocking( $conflicts ) ) {
			$state = self::state_from( $conflicts, $removed, $limits, '', false, 'conflicts' );
			self::store_state( $state );
			$licence = '';

			return self::public_state( $state );
		}

		if ( ! $installed ) {
			$install = self::ensure_active( $installer );
			if ( is_wp_error( $install ) ) {
				$state = self::state_from( $conflicts, $removed, $limits, '', false, 'install_failed' );
				self::store_state( $state );
				$licence = '';

				return $install;
			}
			$installed = true;
			if ( is_array( $install ) && ! empty( $install['version'] ) ) {
				$GLOBALS['rw_wordfence_installed_version'] = (string) $install['version'];
			}
		}

		$pro = self::is_pro();
		if ( '' !== $licence ) {
			$activation = self::activate_licence( $licence );
			$licence    = '';
			if ( is_wp_error( $activation ) ) {
				$limits[] = 'licence_not_applied';
			} elseif ( ! empty( $activation['pro'] ) ) {
				$pro = true;
			}
		}

		$template_applied = '';
		$approval         = '';
		if ( $installed && isset( $args['template'] ) && is_array( $args['template'] ) ) {
			$client = isset( $args['client_email'] ) ? (string) $args['client_email'] : '';
			$applied = RW_Connector_Wordfence_Template::apply( $args['template'], $client );
			if ( is_wp_error( $applied ) ) {
				$limits[] = 'template_not_applied';
			} else {
				$template_applied = (string) $applied['version'];
				$approval         = (string) $applied['approval_status'];
				$limits           = array_merge( $limits, $applied['limits'] );
			}
		} elseif ( $installed ) {
			$limits[] = 'template_missing';
		}

		$state = self::state_from( $conflicts, $removed, $limits, $template_applied, $pro, 'ready' );
		$state['approval_status'] = $approval;
		$state['installed']       = $installed;
		self::store_state( $state );

		return self::public_state( $state );
	}

	/**
	 * Compact wordfence block. Safe to put in a report.
	 *
	 * @return array{version:string,pro:bool,template_applied:string,conflicts_removed:string[]}
	 */
	public static function report() {
		return self::normalise_report( self::load_state() );
	}

	/**
	 * @param mixed $report
	 * @return array{version:string,pro:bool,template_applied:string,conflicts_removed:string[]}
	 */
	public static function normalise_report( $report ) {
		$report  = is_array( $report ) ? $report : array();
		$removed = array();
		if ( isset( $report['conflicts_removed'] ) && is_array( $report['conflicts_removed'] ) ) {
			foreach ( $report['conflicts_removed'] as $file ) {
				$file = RW_Connector_Wordfence_Conflicts::normalise_plugin_file( $file );
				if ( '' !== $file && ! RW_Connector_Wordfence_Conflicts::is_protected_file( $file ) ) {
					$removed[] = $file;
				}
			}
		}
		$version  = isset( $report['version'] ) && is_scalar( $report['version'] ) ? self::plain_text( $report['version'] ) : '';
		$template = isset( $report['template_applied'] ) ? self::plain_text( $report['template_applied'] ) : '';
		if ( ! preg_match( '/^v[0-9]+$/', $template ) ) {
			$template = '';
		}

		return array(
			'version'           => $version,
			'pro'               => ! empty( $report['pro'] ),
			'template_applied'  => $template,
			'conflicts_removed' => array_values( array_unique( $removed ) ),
		);
	}

	/**
	 * @param string $key
	 * @return string|WP_Error Empty string when no key was supplied.
	 */
	public static function normalise_licence_key( $key ) {
		$key = strtolower( (string) preg_replace( '/[^a-fA-F0-9]/', '', (string) $key ) );
		if ( '' === $key ) {
			return '';
		}
		if ( ! preg_match( '/^[a-f0-9]{8,128}$/', $key ) ) {
			return new WP_Error( 'rw_wordfence_licence_invalid', 'The Wordfence licence key was not accepted.', array( 'status' => 400 ) );
		}

		return $key;
	}

	/**
	 * Activate a key with the same Wordfence API call the plugin UI uses.
	 * The key is passed to wfConfig only after Wordfence accepts it. It is not
	 * written to the ReactWoo state option.
	 *
	 * @param string $key Normalised hex key.
	 * @return array{applied:bool,pro:bool}|WP_Error
	 */
	public static function activate_licence( $key ) {
		$key = self::normalise_licence_key( $key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( '' === $key ) {
			return array(
				'applied' => false,
				'pro'     => self::is_pro(),
			);
		}
		if ( ! class_exists( 'wfConfig' ) || ! method_exists( 'wfConfig', 'set' ) || ! class_exists( 'wfAPI' ) ) {
			return new WP_Error( 'rw_wordfence_licence_unavailable', 'Wordfence could not accept a licence key, so the key was not stored.', array( 'status' => 409 ) );
		}

		$existing = '';
		if ( method_exists( 'wfConfig', 'get' ) ) {
			$existing = strtolower( (string) preg_replace( '/[^a-fA-F0-9]/', '', (string) wfConfig::get( 'apiKey', '' ) ) );
		}
		if ( '' !== $existing && $existing === $key ) {
			return array(
				'applied' => true,
				'pro'     => self::is_pro(),
			);
		}

		try {
			$wp_version = class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'getWPVersion' ) ? wfUtils::getWPVersion() : '';
			$api        = new wfAPI( $key, $wp_version );
			$result     = $api->call( 'check_api_key', array(), array( 'previousLicense' => $existing ) );
		} catch ( Throwable $e ) {
			unset( $e );

			return new WP_Error( 'rw_wordfence_licence_failed', 'Wordfence did not accept the licence key.', array( 'status' => 502 ) );
		}

		if ( ! is_array( $result ) || empty( $result['ok'] ) || ! array_key_exists( 'isPaid', $result ) ) {
			return new WP_Error( 'rw_wordfence_licence_failed', 'Wordfence did not accept the licence key.', array( 'status' => 502 ) );
		}

		$paid = ! empty( $result['isPaid'] );
		wfConfig::set( 'apiKey', $key );
		wfConfig::set( 'isPaid', $paid ? 1 : 0 );
		if ( ! $paid && defined( 'wfAPI::KEY_TYPE_FREE' ) ) {
			wfConfig::set( 'keyType', constant( 'wfAPI::KEY_TYPE_FREE' ) );
		}
		if ( class_exists( 'wordfence' ) && method_exists( 'wordfence', 'licenseStatusChanged' ) ) {
			wordfence::licenseStatusChanged();
		}

		return array(
			'applied' => true,
			'pro'     => $paid,
		);
	}

	/**
	 * @param mixed $data Plugin information from WordPress.org.
	 * @return array{download_url:string,version:string}|WP_Error
	 */
	public static function package_from_plugin_info( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}
		$url = isset( $data['download_link'] ) ? (string) $data['download_link'] : '';
		if ( ! self::allowed_package_url( $url ) ) {
			return new WP_Error( 'rw_wordfence_download_rejected', 'Wordfence can only be installed from WordPress.org.', array( 'status' => 400 ) );
		}

		return array(
			'download_url' => $url,
			'version'      => isset( $data['version'] ) ? self::plain_text( $data['version'] ) : '',
		);
	}

	/**
	 * @param string $url
	 * @return bool
	 */
	public static function allowed_package_url( $url ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url ) : parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return false;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}
		if ( 'downloads.wordpress.org' !== strtolower( (string) $parts['host'] ) ) {
			return false;
		}

		return (bool) preg_match( '#^/plugin/wordfence(?:\.[A-Za-z0-9._-]+)?\.zip$#', (string) $parts['path'] );
	}

	/**
	 * @return bool
	 */
	public static function is_wordfence_active() {
		if ( isset( $GLOBALS['rw_wordfence_active'] ) ) {
			return (bool) $GLOBALS['rw_wordfence_active'];
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * @return bool
	 */
	public static function is_pro() {
		if ( class_exists( 'wfConfig' ) && method_exists( 'wfConfig', 'get' ) ) {
			return (bool) wfConfig::get( 'isPaid', false );
		}

		return false;
	}

	/**
	 * @return string
	 */
	public static function installed_version() {
		if ( defined( 'WORDFENCE_VERSION' ) ) {
			return self::plain_text( WORDFENCE_VERSION );
		}
		if ( isset( $GLOBALS['rw_wordfence_installed_version'] ) && is_string( $GLOBALS['rw_wordfence_installed_version'] ) ) {
			return self::plain_text( $GLOBALS['rw_wordfence_installed_version'] );
		}
		if ( class_exists( 'RW_Component_Installer' ) ) {
			$version = RW_Component_Installer::plugin_version( self::PLUGIN_FILE );
			if ( is_string( $version ) && '' !== $version ) {
				return self::plain_text( $version );
			}
		}

		return '';
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public static function is_confirmed( $value ) {
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function load_state() {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string,mixed> $state
	 * @return void
	 */
	private static function store_state( array $state ) {
		$report = self::normalise_report( $state );
		$limits = array();
		if ( isset( $state['limits'] ) && is_array( $state['limits'] ) ) {
			foreach ( $state['limits'] as $limit ) {
				$limit = sanitize_key( (string) $limit );
				if ( '' !== $limit ) {
					$limits[] = $limit;
				}
			}
		}

		update_option(
			self::STATE_OPTION,
			array(
				'status'            => isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'pending_install',
				'installed'         => ! empty( $state['installed'] ),
				'version'           => $report['version'],
				'pro'               => ! empty( $report['pro'] ),
				'template_applied'  => $report['template_applied'],
				'conflicts_removed' => $report['conflicts_removed'],
				'conflicts'         => isset( $state['conflicts'] ) && is_array( $state['conflicts'] ) ? self::public_conflicts( $state['conflicts'] ) : array(),
				'limits'            => array_values( array_unique( $limits ) ),
				'approval_status'   => isset( $state['approval_status'] ) ? sanitize_key( (string) $state['approval_status'] ) : '',
			),
			false
		);
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private static function public_state( array $state ) {
		$report = self::normalise_report( $state );
		$limits = array();
		if ( isset( $state['limits'] ) && is_array( $state['limits'] ) ) {
			foreach ( $state['limits'] as $limit ) {
				$limits[] = sanitize_key( (string) $limit );
			}
		}

		return array(
			'status'           => isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'pending_install',
			'installed'        => ! empty( $state['installed'] ) || '' !== $report['version'],
			'conflicts'        => isset( $state['conflicts'] ) && is_array( $state['conflicts'] ) ? self::public_conflicts( $state['conflicts'] ) : array(),
			'report'           => $report,
			'limits'           => array_values( array_unique( $limits ) ),
			'approval_status'  => isset( $state['approval_status'] ) ? sanitize_key( (string) $state['approval_status'] ) : '',
			'message'          => self::message_for( $state, $report ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $conflicts
	 * @param string[]                       $removed
	 * @param string[]                       $limits
	 * @param string                         $template
	 * @param bool                           $pro
	 * @param string                         $status
	 * @return array<string,mixed>
	 */
	private static function state_from( array $conflicts, array $removed, array $limits, $template, $pro, $status ) {
		return array(
			'status'            => $status,
			'installed'         => self::is_wordfence_active(),
			'version'           => self::installed_version(),
			'pro'               => (bool) $pro,
			'template_applied'  => (string) $template,
			'conflicts_removed' => array_values( $removed ),
			'conflicts'         => self::public_conflicts( $conflicts ),
			'limits'            => array_values( $limits ),
			'approval_status'   => '',
		);
	}

	/**
	 * @return string[]
	 */
	private static function removed_history() {
		$state = self::load_state();
		$list  = isset( $state['conflicts_removed'] ) && is_array( $state['conflicts_removed'] ) ? $state['conflicts_removed'] : array();
		$clean = array();
		foreach ( $list as $file ) {
			$file = RW_Connector_Wordfence_Conflicts::normalise_plugin_file( $file );
			if ( '' !== $file ) {
				$clean[] = $file;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param array<int,mixed> $conflicts
	 * @return array<int,array<string,mixed>>
	 */
	private static function public_conflicts( array $conflicts ) {
		$rows = array();
		foreach ( $conflicts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$file = isset( $row['file'] ) ? RW_Connector_Wordfence_Conflicts::normalise_plugin_file( $row['file'] ) : '';
			if ( '' === $file ) {
				continue;
			}
			$action = isset( $row['action'] ) ? sanitize_key( (string) $row['action'] ) : 'review';
			if ( ! in_array( $action, array( 'remove', 'review', 'licence' ), true ) ) {
				$action = 'review';
			}
			$rows[] = array(
				'file'     => $file,
				'name'     => isset( $row['name'] ) ? self::plain_text( $row['name'] ) : $file,
				'action'   => $action,
				'reason'   => isset( $row['reason'] ) ? self::plain_text( $row['reason'] ) : '',
				'blocking' => 'remove' === $action,
			);
		}

		return $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function blank_state() {
		return array(
			'status'            => 'pending_install',
			'installed'         => self::is_wordfence_active(),
			'version'           => self::installed_version(),
			'pro'               => self::is_pro(),
			'template_applied'  => '',
			'conflicts_removed' => array(),
			'conflicts'         => array(),
			'limits'            => array(),
			'approval_status'   => '',
		);
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $report
	 * @return string
	 */
	private static function message_for( array $state, array $report ) {
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( 'conflicts' === $status ) {
			return 'Wordfence was not installed. Other security plugins are still on this website. Nothing is removed until you confirm.';
		}
		if ( 'install_failed' === $status ) {
			return 'Wordfence could not be installed. No other plugin was removed.';
		}
		if ( '' !== $report['template_applied'] ) {
			$line = 'Wordfence is active. Settings template ' . $report['template_applied'] . ' has been applied.';
			if ( isset( $state['approval_status'] ) && 'awaiting_approval' === $state['approval_status'] ) {
				$line .= ' This template is a draft waiting for approval.';
			}
			return $line;
		}
		if ( ! empty( $report['version'] ) ) {
			return 'Wordfence is active. The settings template has not been applied.';
		}

		return 'Wordfence setup has not finished.';
	}

	/**
	 * @param callable|null $installer
	 * @return array<string,mixed>|WP_Error
	 */
	private static function ensure_active( $installer ) {
		if ( self::is_wordfence_active() ) {
			return array(
				'status'  => 'active',
				'version' => self::installed_version(),
			);
		}
		if ( self::wordfence_present() ) {
			return self::activate_installed();
		}
		if ( is_callable( $installer ) ) {
			$result = call_user_func( $installer );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_array( $result ) ) {
				return $result;
			}

			return new WP_Error( 'rw_wordfence_install_failed', 'Wordfence could not be installed.', array( 'status' => 500 ) );
		}

		$installed = self::install_free();
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		return self::activate_installed();
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function install_free() {
		$info = self::fetch_plugin_info();
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$package = self::package_from_plugin_info( $info );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		$staged = self::stage_zip( $package['download_url'] );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$installed = self::install_zip( $staged );
		self::delete_file( $staged );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}
		if ( '' !== $package['version'] ) {
			$GLOBALS['rw_wordfence_installed_version'] = $package['version'];
		}

		return array(
			'status'  => 'installed',
			'version' => $package['version'],
		);
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function fetch_plugin_info() {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}
		$response = wp_remote_get(
			self::INFO_URL,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}

		return $data;
	}

	/**
	 * @param string $url
	 * @param int    $hop
	 * @return string|WP_Error
	 */
	private static function stage_zip( $url, $hop = 0 ) {
		if ( $hop > 2 || ! self::allowed_package_url( $url ) || ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_array( $location ) ) {
				$location = reset( $location );
			}
			$location = is_scalar( $location ) ? (string) $location : '';
			if ( '' === $location ) {
				return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
			}
			if ( 0 !== strpos( $location, 'https://' ) ) {
				$location = 'https://downloads.wordpress.org' . '/' . ltrim( $location, '/' );
			}

			return self::stage_zip( $location, $hop + 1 );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < 4 || 'PK' !== substr( $body, 0, 2 ) ) {
			return new WP_Error( 'rw_wordfence_download_rejected', 'The Wordfence package was not a valid plugin archive.', array( 'status' => 409 ) );
		}
		$path = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'rw-wordfence.zip' ) : tempnam( sys_get_temp_dir(), 'rwwf' );
		if ( ! is_string( $path ) || '' === $path || false === file_put_contents( $path, $body ) ) {
			return new WP_Error( 'rw_wordfence_download_failed', 'Wordfence could not be downloaded from WordPress.org.', array( 'status' => 502 ) );
		}

		return $path;
	}

	/**
	 * @param string $path
	 * @return true|WP_Error
	 */
	private static function install_zip( $path ) {
		if ( defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			$skin = RW_CONNECTOR_DIR . 'includes/class-rw-quiet-upgrader-skin.php';
			if ( is_readable( $skin ) ) {
				require_once $skin;
			}
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) || ! function_exists( 'WP_Filesystem' ) ) {
			return new WP_Error( 'rw_wordfence_install_failed', 'Wordfence could not be installed.', array( 'status' => 500 ) );
		}
		$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
		if ( is_string( $plugins_dir ) && '' !== $plugins_dir && is_dir( $plugins_dir ) && is_writable( $plugins_dir ) && class_exists( 'RW_Component_Installer' ) ) {
			add_filter( 'filesystem_method', array( 'RW_Component_Installer', 'direct_filesystem_method' ) );
		}
		$context = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : false;
		if ( ! WP_Filesystem( false, $context, true ) ) {
			return new WP_Error( 'rw_wordfence_install_failed', 'Wordfence could not be installed.', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'RW_Quiet_Upgrader_Skin' ) && ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return new WP_Error( 'rw_wordfence_install_failed', 'Wordfence could not be installed.', array( 'status' => 500 ) );
		}
		$skin     = class_exists( 'RW_Quiet_Upgrader_Skin' ) ? new RW_Quiet_Upgrader_Skin() : new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $path );
		if ( is_wp_error( $result ) || true !== $result ) {
			return new WP_Error( 'rw_wordfence_install_failed', 'Wordfence could not be installed.', array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function activate_installed() {
		if ( ! function_exists( 'activate_plugin' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			return new WP_Error( 'rw_wordfence_activate_failed', 'Wordfence is installed but could not be activated.', array( 'status' => 500 ) );
		}
		$activated = activate_plugin( self::PLUGIN_FILE, '', false, true );
		if ( is_wp_error( $activated ) ) {
			return new WP_Error( 'rw_wordfence_activate_failed', 'Wordfence is installed but could not be activated.', array( 'status' => 500 ) );
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		return array(
			'status'  => 'active',
			'version' => self::installed_version(),
		);
	}

	/**
	 * @return bool
	 */
	private static function wordfence_present() {
		$plugins = self::installed_plugin_headers();

		return isset( $plugins[ self::PLUGIN_FILE ] );
	}

	/**
	 * @param string[] $files
	 * @return string[]|WP_Error
	 */
	private static function delete_plugin_files( array $files ) {
		if ( defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'deactivate_plugins' ) || ! function_exists( 'delete_plugins' ) ) {
			return new WP_Error( 'rw_wordfence_remove_unavailable', 'WordPress could not remove the selected plugin.', array( 'status' => 500 ) );
		}
		$removed = array();
		foreach ( $files as $file ) {
			if ( RW_Connector_Wordfence_Conflicts::is_protected_file( $file ) ) {
				continue;
			}
			deactivate_plugins( $file, true );
			$result = delete_plugins( array( $file ) );
			if ( is_wp_error( $result ) || true !== $result ) {
				return new WP_Error( 'rw_wordfence_remove_failed', 'The selected plugin could not be removed.', array( 'status' => 500 ) );
			}
			$removed[] = $file;
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		return $removed;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function installed_plugin_headers() {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}
		$plugins = get_plugins();

		return is_array( $plugins ) ? $plugins : array();
	}

	/**
	 * @param mixed $data
	 * @return WP_REST_Response|mixed
	 */
	private static function respond( $data ) {
		if ( function_exists( 'rest_ensure_response' ) ) {
			return rest_ensure_response( $data );
		}

		return $data;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function plain_text( $value ) {
		$text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( (string) $value );

		return str_replace( array( "\r", "\n" ), ' ', $text );
	}

	/**
	 * @param string $path
	 * @return void
	 */
	private static function delete_file( $path ) {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			unlink( $path );
		}
	}
}

/**
 * Compact wordfence block for the maintenance report.
 *
 * Another reporter can call this, or filter rw_connector_wordfence_report.
 * The return value is only version, pro, template_applied and conflicts_removed.
 *
 * @return array{version:string,pro:bool,template_applied:string,conflicts_removed:string[]}
 */
function rw_connector_wordfence_report() {
	$report = RW_Connector_Wordfence_Onboarding::report();
	if ( function_exists( 'apply_filters' ) ) {
		$filtered = apply_filters( 'rw_connector_wordfence_report', $report );
		if ( is_array( $filtered ) ) {
			$report = RW_Connector_Wordfence_Onboarding::normalise_report( $filtered );
		}
	}

	return $report;
}
