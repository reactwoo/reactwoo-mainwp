<?php
/**
 * Wordfence settings template stored on the maintenance hub.
 *
 * Draft v1 is awaiting approval. The connector installs Wordfence and records
 * conflicts, and it writes these settings only after approval_status is
 * approved. Replace the stored option to publish a later version without
 * shipping a new connector.
 *
 * No licence key belongs in this template. Pro keys arrive separately, and
 * only when a future source provides one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Wordfence_Template {
	const OPTION = 'rw_maint_wordfence_template';

	const SECRET_KEYS = array(
		'apikey',
		'api_key',
		'licence',
		'licence_key',
		'license',
		'license_key',
		'password',
		'secret',
		'authorization',
		'ispaid',
		'keytype',
	);

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		self::seed();
	}

	public static function register_routes() {
		register_rest_route(
			'reactwoo-maint/v1',
			'/wordfence-template',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_template' ),
				'permission_callback' => array( 'RW_Maint_Rest_Controller', 'authorize_request' ),
			)
		);

		register_rest_route(
			'reactwoo-maint/v1',
			'/wordfence-licence',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_licence' ),
				'permission_callback' => array( 'RW_Maint_Rest_Controller', 'authorize_request' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function draft() {
		return array(
			'id'                   => 'reactwoo-wordfence',
			'version'              => 'v1',
			'approval_status'      => 'awaiting_approval',
			'support_alert_email'  => 'support@reactwoo.com',
			'disable_xmlrpc_authentication' => 0,
			'customer_summary'     => 'Wordfence firewall, a daily scan at 03:00, login protection and alert emails.',
			'wordfence'            => array(
				'firewallEnabled'                      => 1,
				'autoBlockScanners'                    => 1,
				'liveTrafficEnabled'                   => 0,
				'other_blockBadPOST'                   => 1,
				'other_hideWPVersion'                  => 1,
				'other_WFNet'                          => 1,
				'scheduledScansEnabled'                => 1,
				'schedMode'                            => 'manual',
				'manualScanType'                       => 'onceDaily',
				'schedStartHour'                       => 3,
				'lowResourceScansEnabled'              => 1,
				'scanType'                             => 'standard',
				'scansEnabled_checkGSB'                => 1,
				'scansEnabled_core'                    => 1,
				'scansEnabled_themes'                  => 1,
				'scansEnabled_plugins'                 => 1,
				'scansEnabled_malware'                 => 1,
				'scansEnabled_fileContents'            => 1,
				'scansEnabled_posts'                   => 1,
				'scansEnabled_comments'                => 1,
				'scansEnabled_suspiciousOptions'       => 1,
				'scansEnabled_oldVersions'             => 1,
				'scansEnabled_suspectedFiles'          => 1,
				'scansEnabled_suspiciousAdminUsers'    => 1,
				'scansEnabled_passwds'                 => 1,
				'scansEnabled_diskSpace'               => 1,
				'scansEnabled_dns'                     => 1,
				'scansEnabled_wafStatus'               => 1,
				'scansEnabled_scanImages'              => 0,
				'scansEnabled_highSense'               => 0,
				'other_scanOutside'                    => 0,
				'loginSecurityEnabled'                 => 1,
				'loginSec_maxFailures'                 => 5,
				'loginSec_maxForgotPasswd'             => 5,
				'loginSec_countFailMins'               => 240,
				'loginSec_lockoutMins'                 => 60,
				'loginSec_lockInvalidUsers'            => 1,
				'loginSec_breachPasswds_enabled'       => 1,
				'loginSec_breachPasswds'               => 'admins',
				'loginSec_strongPasswds_enabled'       => 1,
				'loginSec_strongPasswds'               => 'admins',
				'loginSec_maskLoginErrors'             => 1,
				'loginSec_blockAdminReg'               => 1,
				'loginSec_disableAuthorScan'           => 1,
				'loginSec_disableApplicationPasswords' => 0,
				'alertOn_update'                       => 1,
				'alertOn_wordfenceDeactivated'         => 1,
				'alertOn_wafDeactivated'               => 1,
				'alertOn_block'                        => 0,
				'alertOn_loginLockout'                 => 1,
				'alertOn_breachLogin'                  => 1,
				'alertOn_lostPasswdForm'               => 0,
				'alertOn_adminLogin'                   => 1,
				'alertOn_firstAdminLoginOnly'          => 1,
				'alertOn_nonAdminLogin'                => 0,
				'alertOn_firstNonAdminLoginOnly'       => 0,
				'alertOn_scanIssues'                   => 1,
				'alertOn_severityLevel'                => 50,
				'email_summary_enabled'                => 1,
				'email_summary_interval'               => 'weekly',
				'email_summary_dashboard_widget_enabled' => 0,
			),
			'waf'                  => array(
				'wafStatus'                     => 'learning-mode',
				'learningModeGracePeriodEnabled' => 1,
				'learningModeGracePeriodDays'   => 7,
				'blockedTime'                   => 1800,
			),
			'serialized'           => array(
				'scanSched' => self::daily_scan_at( 3 ),
			),
			'login_security'       => array(
				'require-2fa.administrator'        => 1,
				'require-2fa-grace-period-enabled' => 1,
				'require-2fa-grace-period'         => 14,
				'xmlrpc-enabled'                   => 1,
				'allow-xml-rpc'                    => 1,
			),
			'notes'                => self::notes(),
		);
	}

	/**
	 * @return string[]
	 */
	public static function notes() {
		return array(
			'Draft v1 is awaiting approval. Onboarding installs Wordfence Free and records conflicts, but these settings are not written until approval_status is approved.',
			'The firewall is on. The web application firewall starts in learning mode for 7 days, then Wordfence ends that grace period itself.',
			'Extended Protection is a later, separate step. This template does not write an auto_prepend file or change .user.ini.',
			'Settings are applied with wfConfig::set, wfConfig::set_ser, the WAF storage setConfig method, and Wordfence Login Security Controller_Settings::set. The database tables are not written directly. wordfence::importSettings needs a Wordfence cloud token, which this template does not have.',
			'Live traffic recording is off. Automatic blocking of scanners is on for 30 minutes. The Wordfence security network (other_WFNet) is on, which sends threat data to Wordfence.',
			'A standard scan runs once a day at 03:00 in the site timezone. Low resource mode is on. Image scanning, high sensitivity, and scans outside WordPress are off.',
			'Sign-in locks after 5 failures or 5 lost-password attempts in 4 hours, for 60 minutes. Unknown usernames are included. Leaked-password and strong-password checks apply to administrators.',
			'Application passwords stay enabled. Nothing in this template disables them, including login security, two-factor authentication, XML-RPC, or the REST API. loginSec_disableApplicationPasswords stays off.',
			'Administrators are required to use two-factor authentication after a 14 day grace period. That requirement does not turn off application passwords. ReactWoo cannot enrol an authenticator app for them. reCAPTCHA is not switched on because there is no site key.',
			'XML-RPC authentication stays on for Jetpack and mobile apps. Set disable_xmlrpc_authentication to 1 to switch it off. That opt-in is off in this draft.',
			'Alert email is the client report address and support@reactwoo.com. Block alerts and lost-password alerts are off so the inbox is not flooded. Scan alerts start at severity 50. A weekly summary is on.',
			'Wordfence Free and Pro are the same plugin. A Pro licence is applied in place. No licence key is stored here, and none is shipped in this draft.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function current() {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) ) {
			$valid = self::validate( $stored );
			if ( is_array( $valid ) ) {
				return $valid;
			}
		}

		return self::draft();
	}

	/**
	 * @return void
	 */
	public static function seed() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			update_option( self::OPTION, self::draft(), false );
		}
	}

	/**
	 * @param mixed $template
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( $template ) {
		$valid = self::validate( $template );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		update_option( self::OPTION, $valid, false );

		return $valid;
	}

	/**
	 * @param mixed $template
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( $template ) {
		if ( ! is_array( $template ) || self::contains_secret_key( $template ) ) {
			return new WP_Error( 'rw_wordfence_template_rejected', 'The Wordfence settings template was rejected.', array( 'status' => 400 ) );
		}
		$version = isset( $template['version'] ) ? (string) $template['version'] : '';
		$status  = isset( $template['approval_status'] ) ? (string) $template['approval_status'] : '';
		if ( ! preg_match( '/^v[0-9]+$/', $version ) || ( 'awaiting_approval' !== $status && 'approved' !== $status ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence settings template was not recognised.', array( 'status' => 400 ) );
		}

		$wordfence = self::scalar_map( isset( $template['wordfence'] ) ? $template['wordfence'] : array() );
		$waf       = self::scalar_map( isset( $template['waf'] ) ? $template['waf'] : array() );
		$login     = self::scalar_map( isset( $template['login_security'] ) ? $template['login_security'] : array() );
		if ( is_wp_error( $wordfence ) || is_wp_error( $waf ) || is_wp_error( $login ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence settings template contained a value that is not allowed.', array( 'status' => 400 ) );
		}
		$wordfence = self::allow_application_passwords( $wordfence );
		$login     = self::allow_application_passwords( $login );
		$wordfence['loginSec_disableApplicationPasswords'] = 0;
		$disable_xmlrpc = ! empty( $template['disable_xmlrpc_authentication'] ) ? 1 : 0;
		$login['xmlrpc-enabled'] = $disable_xmlrpc ? 0 : 1;
		$login['allow-xml-rpc']  = $disable_xmlrpc ? 0 : 1;

		$serial = array();
		if ( isset( $template['serialized']['scanSched'] ) ) {
			$matrix = self::scan_matrix( $template['serialized']['scanSched'] );
			if ( is_wp_error( $matrix ) ) {
				return $matrix;
			}
			$serial['scanSched'] = $matrix;
		}

		$support = '';
		if ( isset( $template['support_alert_email'] ) && '' !== (string) $template['support_alert_email'] ) {
			$support = function_exists( 'sanitize_email' ) ? sanitize_email( (string) $template['support_alert_email'] ) : '';
			if ( '' === $support ) {
				return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence support alert address was not recognised.', array( 'status' => 400 ) );
			}
		}

		$notes = array();
		if ( isset( $template['notes'] ) && is_array( $template['notes'] ) ) {
			foreach ( $template['notes'] as $note ) {
				if ( is_scalar( $note ) ) {
					$notes[] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $note ) : (string) $note;
				}
			}
		}

		return array(
			'id'                  => isset( $template['id'] ) ? sanitize_key( (string) $template['id'] ) : 'reactwoo-wordfence',
			'version'             => $version,
			'approval_status'     => $status,
			'support_alert_email' => $support,
			'disable_xmlrpc_authentication' => isset( $disable_xmlrpc ) ? (int) $disable_xmlrpc : 0,
			'customer_summary'    => isset( $template['customer_summary'] ) ? sanitize_text_field( (string) $template['customer_summary'] ) : '',
			'wordfence'           => $wordfence,
			'waf'                 => $waf,
			'serialized'          => $serial,
			'login_security'      => $login,
			'notes'               => $notes,
		);
	}

	/**
	 * @param WP_REST_Request|array<string,mixed> $request
	 * @return WP_REST_Response|WP_Error|array<string,mixed>
	 */
	public static function handle_template( $request ) {
		$params = self::params( $request );
		if ( ! empty( $params['save'] ) ) {
			$saved = self::save( isset( $params['template'] ) ? $params['template'] : null );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return self::respond( array( 'template' => self::current() ) );
	}

	/**
	 * @param WP_REST_Request|array<string,mixed> $request
	 * @return WP_REST_Response|WP_Error|array<string,mixed>
	 */
	public static function handle_licence( $request ) {
		$params   = self::params( $request );
		$site_id  = isset( $params['portal_site_id'] ) ? (int) $params['portal_site_id'] : 0;
		$supplied = '';
		if ( isset( $params['licence_key'] ) && is_string( $params['licence_key'] ) ) {
			$supplied = $params['licence_key'];
		} elseif ( isset( $params['license_key'] ) && is_string( $params['license_key'] ) ) {
			$supplied = $params['license_key'];
		}

		$payload = self::licence_payload( $site_id, $supplied );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( class_exists( 'RW_Maint_Audit' ) ) {
			RW_Maint_Audit::log( 'wordfence_licence_requested', self::audit_context( $payload, $site_id ) );
		}

		return self::respond( $payload );
	}

	/**
	 * The filter rw_maint_wordfence_licence_key may return array{key:string,portal_site_id:int}.
	 * A key on the signed request is accepted only when that filter does not supply one.
	 * Nothing in this plugin stores the key.
	 *
	 * @param int    $portal_site_id
	 * @param string $supplied
	 * @return string|WP_Error
	 */
	public static function resolve_licence_key( $portal_site_id, $supplied = '' ) {
		$from_filter = '';
		if ( function_exists( 'apply_filters' ) ) {
			$pack = apply_filters(
				'rw_maint_wordfence_licence_key',
				array(
					'key'            => '',
					'portal_site_id' => (int) $portal_site_id,
				)
			);
			if ( is_array( $pack ) && isset( $pack['key'] ) && is_string( $pack['key'] ) ) {
				$from_filter = $pack['key'];
			} elseif ( is_string( $pack ) ) {
				$from_filter = $pack;
			}
		}
		$chosen = '' !== trim( (string) $from_filter ) ? $from_filter : (string) $supplied;

		return self::normalise_licence_key( $chosen );
	}

	/**
	 * @param int    $portal_site_id
	 * @param string $supplied
	 * @return array<string,mixed>|WP_Error
	 */
	public static function licence_payload( $portal_site_id, $supplied = '' ) {
		$key = self::resolve_licence_key( $portal_site_id, $supplied );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$present = '' !== $key;
		$payload = array(
			'target'          => $present ? 'pro' : 'free',
			'licence_present' => $present,
		);
		if ( $present ) {
			$payload['licence_key'] = $key;
		}

		return $payload;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param int                 $portal_site_id
	 * @return array<string,mixed>
	 */
	public static function audit_context( array $payload, $portal_site_id ) {
		unset( $payload['licence_key'], $payload['license_key'], $payload['apiKey'], $payload['api_key'] );
		$payload['portal_site_id'] = (int) $portal_site_id;

		return $payload;
	}

	/**
	 * @param string $key
	 * @return string|WP_Error
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
	 * @param int $hour Hour from 0 to 23, site timezone.
	 * @return array<int,array<int,int>>
	 */
	public static function daily_scan_at( $hour ) {
		$hour   = max( 0, min( 23, (int) $hour ) );
		$matrix = array();
		for ( $day = 0; $day < 7; $day++ ) {
			$matrix[ $day ] = array_fill( 0, 24, 0 );
			$matrix[ $day ][ $hour ] = 1;
		}

		return $matrix;
	}

	/**
	 * Keys that would turn off WordPress application passwords or the REST API
	 * are stored as off. Two-factor sign-in for administrators does not count.
	 *
	 * @param array<string,mixed> $map
	 * @return array<string,mixed>
	 */
	private static function allow_application_passwords( array $map ) {
		foreach ( $map as $key => $value ) {
			unset( $value );
			if ( self::names_application_password_block( $key ) ) {
				$map[ $key ] = 0;
			}
		}

		return $map;
	}

	/**
	 * @param mixed $key
	 * @return bool
	 */
	private static function names_application_password_block( $key ) {
		$name = strtolower( (string) $key );

		return false !== strpos( $name, 'applicationpassword' )
			|| false !== strpos( $name, 'application_password' )
			|| false !== strpos( $name, 'application-password' )
			|| false !== strpos( $name, 'disablerest' )
			|| false !== strpos( $name, 'disable_rest' )
			|| false !== strpos( $name, 'disable-rest' );
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private static function contains_secret_key( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( in_array( strtolower( (string) $key ), self::SECRET_KEYS, true ) ) {
				return true;
			}
			if ( is_array( $item ) && self::contains_secret_key( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $map
	 * @return array<string,mixed>|WP_Error
	 */
	private static function scalar_map( $map ) {
		if ( ! is_array( $map ) ) {
			return new WP_Error( 'invalid', 'invalid' );
		}
		$clean = array();
		foreach ( $map as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/', $key ) ) {
				return new WP_Error( 'invalid', 'invalid' );
			}
			if ( is_bool( $value ) ) {
				$clean[ $key ] = $value ? 1 : 0;
			} elseif ( is_int( $value ) || ( is_float( $value ) && (float) (int) $value === $value ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( is_string( $value ) && strlen( $value ) <= 2000 ) {
				$clean[ $key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
			} else {
				return new WP_Error( 'invalid', 'invalid' );
			}
		}

		return $clean;
	}

	/**
	 * @param mixed $matrix
	 * @return array<int,array<int,int>>|WP_Error
	 */
	private static function scan_matrix( $matrix ) {
		if ( ! is_array( $matrix ) || 7 !== count( $matrix ) ) {
			return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
		}
		$clean = array();
		$day   = 0;
		foreach ( $matrix as $hours ) {
			if ( ! is_array( $hours ) || 24 !== count( $hours ) ) {
				return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
			}
			$clean[ $day ] = array();
			$hour          = 0;
			foreach ( $hours as $enabled ) {
				if ( ! in_array( $enabled, array( 0, 1, '0', '1', true, false ), true ) ) {
					return new WP_Error( 'rw_wordfence_template_invalid', 'The Wordfence scan schedule was not recognised.', array( 'status' => 400 ) );
				}
				$clean[ $day ][ $hour ] = (int) ( (bool) $enabled );
				$hour++;
			}
			$day++;
		}

		return $clean;
	}

	/**
	 * @param WP_REST_Request|array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	private static function params( $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$params = $request->get_json_params();
			return is_array( $params ) ? $params : array();
		}

		return is_array( $request ) ? $request : array();
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private static function respond( $data ) {
		if ( function_exists( 'rest_ensure_response' ) ) {
			return rest_ensure_response( $data );
		}

		return $data;
	}
}
