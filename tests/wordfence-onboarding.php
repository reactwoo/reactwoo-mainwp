<?php
/**
 * Wordfence onboarding checks. Included from tests/run.php.
 */

require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-conflicts.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-template.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-onboarding.php';
require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-wordfence-template.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-wordfence.php';
require_once __DIR__ . '/wordfence-ls-double.php';

class wfConfig {
	public static $store = array();
	public static $serialized = array();

	public static function set( $key, $val, $autoload = null ) {
		unset( $autoload );
		self::$store[ $key ] = $val;
	}

	public static function get( $key, $default = false ) {
		return array_key_exists( $key, self::$store ) ? self::$store[ $key ] : $default;
	}

	public static function set_ser( $key, $val, $autoload = null ) {
		unset( $autoload );
		self::$store[ $key ] = $val;
		self::$serialized[]  = $key;
	}
}

class wfWAFStorageFake {
	public function setConfig( $key, $val, $autoload = null ) {
		unset( $autoload );
		wfWAF::$config[ $key ] = $val;
	}
}

class wfWAF {
	public static $config = array();

	public static function getInstance() {
		return new self();
	}

	public function getStorageEngine() {
		return new wfWAFStorageFake();
	}
}

class wfScanner {
	public static $runs = 0;

	public static function shared() {
		return new self();
	}

	public function scheduleScans() {
		self::$runs++;
	}
}

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins() {
		return isset( $GLOBALS['rw_plugins'] ) && is_array( $GLOBALS['rw_plugins'] ) ? $GLOBALS['rw_plugins'] : array();
	}
}

$wf_plugins = array(
	'sucuri-scanner/sucuri.php'                         => array( 'Name' => 'Sucuri Security' ),
	'all-in-one-wp-security-and-firewall/wp-security.php' => array( 'Name' => 'All-In-One Security' ),
	'better-wp-security/better-wp-security.php'          => array( 'Name' => 'Solid Security' ),
	'ithemes-security-pro/ithemes-security-pro.php'      => array( 'Name' => 'iThemes Security Pro' ),
	'solid-security/solid-security.php'                  => array( 'Name' => 'Solid Security' ),
	'wp-simple-firewall/icwp-wpsf.php'                   => array( 'Name' => 'Shield Security' ),
	'wp-cerber/wp-cerber.php'                            => array( 'Name' => 'WP Cerber' ),
	'malcare-security/malcare.php'                       => array( 'Name' => 'MalCare' ),
	'defender-security/wp-defender.php'                  => array( 'Name' => 'Defender' ),
	'ninjafirewall/ninjafirewall.php'                    => array( 'Name' => 'NinjaFirewall' ),
	'gotmls/index.php'                                   => array( 'Name' => 'Anti-Malware' ),
	'sg-security/sg-security.php'                        => array( 'Name' => 'SiteGround Security' ),
	'wordfence-login-security/wordfence-login-security.php' => array( 'Name' => 'Wordfence Login Security' ),
	'patchstack/patchstack.php'                          => array( 'Name' => 'Patchstack' ),
	'loginizer/loginizer.php'                            => array( 'Name' => 'Loginizer' ),
	'hello.php'                                          => array( 'Name' => 'Hello' ),
	'wordfence/wordfence.php'                            => array( 'Name' => 'Wordfence' ),
);
$wf_found = array();
foreach ( RW_Connector_Wordfence_Conflicts::conflicts_in( $wf_plugins, false ) as $row ) {
	$wf_found[ $row['file'] ] = $row['action'];
}
$wf_pro = array();
foreach ( RW_Connector_Wordfence_Conflicts::conflicts_in( $wf_plugins, true ) as $row ) {
	$wf_pro[ $row['file'] ] = $row['action'];
}
$wf_blocking = RW_Connector_Wordfence_Conflicts::has_blocking( RW_Connector_Wordfence_Conflicts::conflicts_in( $wf_plugins, false ) );
$wf_review_only = RW_Connector_Wordfence_Conflicts::conflicts_in(
	array( 'loginizer/loginizer.php' => array( 'Name' => 'Loginizer' ) ),
	false
);
rw_test(
	'conflicting security plugins are listed and Wordfence Free is kept unless Pro is targeted',
	'remove' === $wf_found['sucuri-scanner/sucuri.php']
		&& 'remove' === $wf_found['all-in-one-wp-security-and-firewall/wp-security.php']
		&& 'remove' === $wf_found['better-wp-security/better-wp-security.php']
		&& 'remove' === $wf_found['ithemes-security-pro/ithemes-security-pro.php']
		&& 'remove' === $wf_found['solid-security/solid-security.php']
		&& 'remove' === $wf_found['wp-simple-firewall/icwp-wpsf.php']
		&& 'remove' === $wf_found['wp-cerber/wp-cerber.php']
		&& 'remove' === $wf_found['malcare-security/malcare.php']
		&& 'remove' === $wf_found['defender-security/wp-defender.php']
		&& 'remove' === $wf_found['ninjafirewall/ninjafirewall.php']
		&& 'remove' === $wf_found['gotmls/index.php']
		&& 'remove' === $wf_found['sg-security/sg-security.php']
		&& 'remove' === $wf_found['wordfence-login-security/wordfence-login-security.php']
		&& 'remove' === $wf_found['patchstack/patchstack.php']
		&& 'review' === $wf_found['loginizer/loginizer.php']
		&& ! isset( $wf_found['wordfence/wordfence.php'] )
		&& ! isset( $wf_found['hello.php'] )
		&& 'licence' === $wf_pro['wordfence/wordfence.php']
		&& $wf_blocking
		&& ! RW_Connector_Wordfence_Conflicts::has_blocking( $wf_review_only )
		&& ! $wf_review_only[0]['blocking']
);

$wf_plan = RW_Connector_Wordfence_Conflicts::removal_plan(
	array(
		'sucuri-scanner/sucuri.php',
		'loginizer/loginizer.php',
		'wordfence/wordfence.php',
		'mainwp-child/mainwp-child.php',
		'../evil.php',
	),
	RW_Connector_Wordfence_Conflicts::conflicts_in( $wf_plugins, true )
);
rw_test(
	'removal waits for a confirmed plugin and never selects Wordfence or the connector',
	array( 'sucuri-scanner/sucuri.php', 'loginizer/loginizer.php' ) === $wf_plan
		&& array() === RW_Connector_Wordfence_Conflicts::removal_plan(
			array( 'wordfence/wordfence.php' ),
			array(
				array(
					'file'   => 'wordfence/wordfence.php',
					'action' => 'remove',
				),
			)
		)
);

rw_test(
	'Wordfence is only downloaded from WordPress.org',
	RW_Connector_Wordfence_Onboarding::allowed_package_url( 'https://downloads.wordpress.org/plugin/wordfence.8.1.0.zip' )
		&& RW_Connector_Wordfence_Onboarding::allowed_package_url( 'https://downloads.wordpress.org/plugin/wordfence.latest-stable.zip' )
		&& ! RW_Connector_Wordfence_Onboarding::allowed_package_url( 'http://downloads.wordpress.org/plugin/wordfence.zip' )
		&& ! RW_Connector_Wordfence_Onboarding::allowed_package_url( 'https://evil.example/plugin/wordfence.zip' )
		&& ! RW_Connector_Wordfence_Onboarding::allowed_package_url( 'https://user:pass@downloads.wordpress.org/plugin/wordfence.zip' )
		&& is_wp_error( RW_Connector_Wordfence_Onboarding::package_from_plugin_info( array( 'download_link' => 'https://evil.example/wordfence.zip', 'version' => '8.1.0' ) ) )
);

$wf_draft = RW_Maint_Wordfence_Template::draft();
$wf_bad   = $wf_draft;
$wf_bad['wordfence']['apiKey'] = 'abc123def4567890';
$wf_rejected = RW_Connector_Wordfence_Template::sanitise( $wf_bad );
$wf_saved_bad = RW_Maint_Wordfence_Template::save( $wf_bad );
rw_test(
	'a template that carries a licence or API key is rejected',
	is_wp_error( $wf_rejected )
		&& 'rw_wordfence_template_rejected' === $wf_rejected->get_error_code()
		&& is_wp_error( $wf_saved_bad )
		&& false === strpos( wp_json_encode( $wf_draft ), 'abc123def4567890' )
);

$GLOBALS['rw_wordfence_now'] = 1700000000;
wfConfig::$store = array();
wfConfig::$serialized = array();
wfWAF::$config = array();
\WordfenceLS\Controller_Settings::$values = array();
$wf_held = RW_Connector_Wordfence_Template::apply( $wf_draft, 'client@example.com' );
rw_test(
	'an unapproved template is not written to Wordfence',
	is_array( $wf_held )
		&& false === $wf_held['applied']
		&& '' === $wf_held['version']
		&& 'awaiting_approval' === $wf_held['approval_status']
		&& in_array( 'template_awaiting_approval', $wf_held['limits'], true )
		&& ! isset( wfConfig::$store['schedMode'] )
		&& ! isset( wfConfig::$store['alertEmails'] )
		&& array() === wfWAF::$config
		&& array() === \WordfenceLS\Controller_Settings::$values
);

$wf_approved = $wf_draft;
$wf_approved['approval_status'] = 'approved';
$wf_applied = RW_Connector_Wordfence_Template::apply( $wf_approved, 'client@example.com' );
$wf_grace   = 1700000000 + ( 7 * 86400 );
rw_test(
	'an approved template v1 is applied through Wordfence methods',
	is_array( $wf_applied )
		&& true === $wf_applied['applied']
		&& 'v1' === $wf_applied['version']
		&& 'approved' === $wf_applied['approval_status']
		&& 'awaiting_approval' === $wf_draft['approval_status']
		&& 'support@reactwoo.com' === $wf_draft['support_alert_email']
		&& 0 === (int) $wf_draft['disable_xmlrpc_authentication']
		&& 1 === $wf_draft['wordfence']['firewallEnabled']
		&& 0 === $wf_draft['wordfence']['loginSec_disableApplicationPasswords']
		&& 1 === (int) $wf_draft['login_security']['xmlrpc-enabled']
		&& 1 === (int) $wf_draft['login_security']['allow-xml-rpc']
		&& 7 === (int) $wf_draft['waf']['learningModeGracePeriodDays']
		&& 1 === $wf_draft['serialized']['scanSched'][0][3]
		&& 0 === $wf_draft['serialized']['scanSched'][0][4]
		&& 'manual' === wfConfig::$store['schedMode']
		&& 1 === wfConfig::$store['scanSched'][2][3]
		&& in_array( 'scanSched', wfConfig::$serialized, true )
		&& 'client@example.com,support@reactwoo.com' === wfConfig::$store['alertEmails']
		&& 'learning-mode' === wfWAF::$config['wafStatus']
		&& $wf_grace === (int) wfWAF::$config['learningModeGracePeriod']
		&& 1 === \WordfenceLS\Controller_Settings::$values['require-2fa.administrator']
		&& 14 === \WordfenceLS\Controller_Settings::$values['require-2fa-grace-period']
		&& 1 === \WordfenceLS\Controller_Settings::$values['xmlrpc-enabled']
		&& 1 === \WordfenceLS\Controller_Settings::$values['allow-xml-rpc']
		&& 0 === (int) wfConfig::$store['loginSec_disableApplicationPasswords']
		&& wfScanner::$runs >= 1
		&& ! isset( wfConfig::$store['apiKey'] )
		&& ! in_array( 'login_security_2fa_not_applied', $wf_applied['limits'], true )
		&& ! in_array( 'waf_storage_unavailable', $wf_applied['limits'], true )
);

$wf_hostile = $wf_approved;
$wf_hostile['wordfence']['loginSec_disableApplicationPasswords'] = 1;
$wf_hostile['wordfence']['disableApplicationPasswords'] = 1;
$wf_hostile['login_security']['disable-application-passwords'] = 1;
$wf_hostile['login_security']['disable-rest-api'] = 1;
$wf_hostile['login_security']['xmlrpc-enabled'] = 0;
$wf_hostile['login_security']['allow-xml-rpc'] = 0;
$wf_hostile_saved = RW_Maint_Wordfence_Template::validate( $wf_hostile );
$wf_forced = RW_Connector_Wordfence_Template::apply( $wf_hostile, 'client@example.com' );
rw_test(
	'the template never disables WordPress application passwords',
	array() === RW_Connector_Wordfence_Template::application_password_blocks( $wf_draft )
		&& is_array( $wf_hostile_saved )
		&& 0 === (int) $wf_hostile_saved['wordfence']['loginSec_disableApplicationPasswords']
		&& 0 === (int) $wf_hostile_saved['wordfence']['disableApplicationPasswords']
		&& 0 === (int) $wf_hostile_saved['login_security']['disable-application-passwords']
		&& 0 === (int) $wf_hostile_saved['login_security']['disable-rest-api']
		&& array() === RW_Connector_Wordfence_Template::application_password_blocks( $wf_hostile_saved )
		&& is_array( $wf_forced )
		&& true === $wf_forced['applied']
		&& 0 === (int) wfConfig::$store['loginSec_disableApplicationPasswords']
		&& 0 === (int) wfConfig::$store['disableApplicationPasswords']
		&& 0 === (int) \WordfenceLS\Controller_Settings::$values['disable-application-passwords']
		&& 0 === (int) \WordfenceLS\Controller_Settings::$values['disable-rest-api']
		&& 1 === (int) \WordfenceLS\Controller_Settings::$values['require-2fa.administrator']
		&& 1 === (int) \WordfenceLS\Controller_Settings::$values['xmlrpc-enabled']
		&& 1 === (int) \WordfenceLS\Controller_Settings::$values['allow-xml-rpc']
);

$wf_xmlrpc_off = $wf_approved;
$wf_xmlrpc_off['disable_xmlrpc_authentication'] = 1;
$wf_xmlrpc_applied = RW_Connector_Wordfence_Template::apply( $wf_xmlrpc_off, 'client@example.com' );
rw_test(
	'XML-RPC authentication stays on unless the template opts out',
	0 === (int) $wf_draft['disable_xmlrpc_authentication']
		&& is_array( $wf_xmlrpc_applied )
		&& true === $wf_xmlrpc_applied['applied']
		&& 0 === (int) \WordfenceLS\Controller_Settings::$values['xmlrpc-enabled']
		&& 0 === (int) \WordfenceLS\Controller_Settings::$values['allow-xml-rpc']
		&& 0 === (int) wfConfig::$store['loginSec_disableApplicationPasswords']
);

$wf_missing_key = RW_Connector_Wordfence_Onboarding::activate_licence( 'abc123def4567890' );
rw_test(
	'a Pro key is not stored when Wordfence cannot accept it',
	is_wp_error( $wf_missing_key )
		&& 'rw_wordfence_licence_unavailable' === $wf_missing_key->get_error_code()
		&& false === strpos( $wf_missing_key->get_error_message(), 'abc123def4567890' )
		&& ! isset( wfConfig::$store['apiKey'] )
);

require_once __DIR__ . '/wordfence-api-double.php';

$wf_bad_key = RW_Connector_Wordfence_Onboarding::normalise_licence_key( 'not-a-real-key' );
rw_test(
	'a malformed Pro key is refused without echoing it',
	is_wp_error( $wf_bad_key ) && false === strpos( $wf_bad_key->get_error_message(), 'not-a-real-key' )
);

delete_option( RW_Connector_Wordfence_Onboarding::STATE_OPTION );
$GLOBALS['rw_plugins'] = array(
	'sucuri-scanner/sucuri.php' => array( 'Name' => 'Sucuri Security' ),
);
$GLOBALS['rw_wordfence_active'] = false;
unset( $GLOBALS['rw_wordfence_installed_version'] );
RW_Connector_Wordfence_Onboarding::on_enrolled();
$wf_enrolled = get_option( RW_Connector_Wordfence_Onboarding::STATE_OPTION );
$wf_calls    = 0;
$wf_blocked  = RW_Connector_Wordfence_Onboarding::run(
	array(
		'template'     => $wf_draft,
		'client_email' => 'client@example.com',
		'confirm'      => false,
		'files'        => array( 'sucuri-scanner/sucuri.php' ),
		'download_url' => 'https://evil.example/wordfence.zip',
		'licence_key'  => 'abc123def4567890',
	),
	function () use ( &$wf_calls ) {
		$wf_calls++;
		return array( 'status' => 'active', 'version' => '8.1.0' );
	}
);
rw_test(
	'first enrolment records conflicts and does not install or remove anything',
	is_array( $wf_enrolled )
		&& 'conflicts' === $wf_enrolled['status']
		&& array() === $wf_enrolled['conflicts_removed']
		&& is_array( $wf_blocked )
		&& 'conflicts' === $wf_blocked['status']
		&& 0 === $wf_calls
		&& '' === $wf_blocked['report']['template_applied']
		&& false === strpos( wp_json_encode( $wf_blocked ), 'abc123def4567890' )
		&& false !== strpos( $wf_blocked['message'], 'confirm' )
);

$wf_removed = array();
$GLOBALS['rw_wordfence_active'] = false;
wfConfig::$store = array();
wfConfig::$serialized = array();
wfWAF::$config = array();
\WordfenceLS\Controller_Settings::$values = array();
$wf_done = RW_Connector_Wordfence_Onboarding::run(
	array(
		'template'     => $wf_draft,
		'client_email' => 'client@example.com',
		'confirm'      => true,
		'files'        => array( 'sucuri-scanner/sucuri.php' ),
		'licence_key'  => 'abc123def4567890',
	),
	function () use ( &$wf_calls ) {
		$wf_calls++;
		$GLOBALS['rw_wordfence_active'] = true;
		return array(
			'status'  => 'active',
			'version' => '8.1.0',
		);
	},
	function ( $files ) use ( &$wf_removed ) {
		foreach ( $files as $file ) {
			unset( $GLOBALS['rw_plugins'][ $file ] );
			$wf_removed[] = $file;
		}
		return $files;
	}
);
$wf_state  = get_option( RW_Connector_Wordfence_Onboarding::STATE_OPTION );
$wf_report = rw_connector_wordfence_report();
$wf_blob   = wp_json_encode( $wf_done ) . wp_json_encode( $wf_state ) . wp_json_encode( $wf_report );
rw_test(
	'confirmed removal installs Wordfence without applying an unapproved template',
	1 === $wf_calls
		&& array( 'sucuri-scanner/sucuri.php' ) === $wf_removed
		&& is_array( $wf_done )
		&& 'ready' === $wf_done['status']
		&& '8.1.0' === $wf_done['report']['version']
		&& true === $wf_done['report']['pro']
		&& '' === $wf_done['report']['template_applied']
		&& 'awaiting_approval' === $wf_done['approval_status']
		&& in_array( 'template_awaiting_approval', $wf_done['limits'], true )
		&& array( 'sucuri-scanner/sucuri.php' ) === $wf_done['report']['conflicts_removed']
		&& $wf_report === $wf_done['report']
		&& 'abc123def4567890' === wfConfig::$store['apiKey']
		&& ! isset( wfConfig::$store['schedMode'] )
		&& ! isset( wfConfig::$store['alertEmails'] )
		&& ! isset( wfConfig::$store['loginSec_disableApplicationPasswords'] )
		&& false === strpos( $wf_blob, 'abc123def4567890' )
		&& false !== strpos( $wf_done['message'], 'waiting for approval' )
		&& false !== strpos( $wf_done['message'], 'have not been applied' )
		&& false === strpos( $wf_done['message'], "\u{2014}" )
);

$GLOBALS['rw_wordfence_active'] = true;
$wf_live = $wf_draft;
$wf_live['approval_status'] = 'approved';
$wf_live_done = RW_Connector_Wordfence_Onboarding::run(
	array(
		'template'     => $wf_live,
		'client_email' => 'client@example.com',
	)
);
rw_test(
	'an approved template is applied after Wordfence is installed',
	is_array( $wf_live_done )
		&& 'v1' === $wf_live_done['report']['template_applied']
		&& 'approved' === $wf_live_done['approval_status']
		&& 'client@example.com,support@reactwoo.com' === wfConfig::$store['alertEmails']
		&& 0 === (int) wfConfig::$store['loginSec_disableApplicationPasswords']
		&& 1 === (int) \WordfenceLS\Controller_Settings::$values['allow-xml-rpc']
		&& 'learning-mode' === wfWAF::$config['wafStatus']
);

$wf_clean = RW_Connector_Wordfence_Onboarding::normalise_report(
	array(
		'version'           => '8.1.0',
		'pro'               => 1,
		'template_applied'  => 'nope',
		'conflicts_removed' => array( 'sucuri-scanner/sucuri.php', 'wordfence/wordfence.php', '../etc/passwd' ),
		'licence_key'       => 'abc123def4567890',
	)
);
rw_test(
	'the reporter filter payload keeps only the compact wordfence block',
	'8.1.0' === $wf_clean['version']
		&& true === $wf_clean['pro']
		&& '' === $wf_clean['template_applied']
		&& array( 'sucuri-scanner/sucuri.php' ) === $wf_clean['conflicts_removed']
		&& array( 'version', 'pro', 'template_applied', 'conflicts_removed' ) === array_keys( $wf_clean )
);

$wf_free = RW_Maint_Wordfence_Template::licence_payload( 9, '' );
$wf_paid = RW_Maint_Wordfence_Template::licence_payload( 9, 'ABCD-1234-EFAB-5678' );
$wf_audit = RW_Maint_Wordfence_Template::audit_context( $wf_paid, 9 );
$wf_hub_saved = RW_Maint_Wordfence_Template::save( $wf_draft );
rw_test(
	'the hub stores template v1 and passes a Pro key only in memory',
	is_array( $wf_free )
		&& 'free' === $wf_free['target']
		&& empty( $wf_free['licence_present'] )
		&& ! isset( $wf_free['licence_key'] )
		&& is_array( $wf_paid )
		&& 'pro' === $wf_paid['target']
		&& ! empty( $wf_paid['licence_present'] )
		&& isset( $wf_paid['licence_key'] )
		&& ! isset( $wf_audit['licence_key'] )
		&& false === strpos( wp_json_encode( $wf_audit ), 'abcd1234efab5678' )
		&& is_array( $wf_hub_saved )
		&& 'v1' === RW_Maint_Wordfence_Template::current()['version']
		&& 'awaiting_approval' === RW_Maint_Wordfence_Template::current()['approval_status']
		&& 'support@reactwoo.com' === RW_Maint_Wordfence_Template::current()['support_alert_email']
		&& 0 === (int) RW_Maint_Wordfence_Template::current()['disable_xmlrpc_authentication']
		&& 1 === (int) RW_Maint_Wordfence_Template::current()['login_security']['allow-xml-rpc']
);

$wf_mail_before = isset( $GLOBALS['rw_mail'] ) ? count( $GLOBALS['rw_mail'] ) : 0;
$wf_site        = (object) array(
	'id'           => 9,
	'site_url'     => 'https://client.example',
	'report_email' => 'owner@example.com, other@example.com',
	'user_id'      => 4,
);
$wf_mailed = RW_Portal_Wordfence::maybe_notify_conflicts(
	$wf_site,
	array(
		array(
			'name'   => 'Sucuri Security',
			'action' => 'remove',
		),
	)
);
$wf_body = RW_Portal_Wordfence::email_body( $wf_site, array( array( 'name' => 'Sucuri Security' ) ) );
update_option(
	'rw_portal_wordfence_9',
	array(
		'status'  => 'conflicts',
		'message' => 'Wordfence was not installed. Other security plugins are still on this website. Nothing is removed until you confirm.',
		'conflicts' => array(
			array(
				'file'     => 'sucuri-scanner/sucuri.php',
				'name'     => 'Sucuri Security',
				'action'   => 'remove',
				'reason'   => 'Sucuri has its own firewall.',
				'blocking' => true,
			),
			array(
				'file'     => 'loginizer/loginizer.php',
				'name'     => 'Loginizer',
				'action'   => 'review',
				'reason'   => 'You can leave it.',
				'blocking' => false,
			),
		),
		'report' => array(
			'version'           => '',
			'pro'               => false,
			'template_applied'  => '',
			'conflicts_removed' => array(),
		),
		'licence_key' => 'abc123def4567890',
	),
	false
);
$wf_view = RW_Portal_Wordfence::view_for_site( $wf_site );
$wf_public = RW_Portal_Wordfence::public_record(
	array(
		'status'      => 'ready',
		'licence_key' => 'abc123def4567890',
		'report'      => array(
			'version'     => '8.1.0',
			'licence_key' => 'abc123def4567890',
		),
	)
);
rw_test(
	'conflict email stays off and the portal asks before removal',
	false === RW_Portal_Wordfence::conflict_email_enabled()
		&& false === $wf_mailed
		&& $wf_mail_before === ( isset( $GLOBALS['rw_mail'] ) ? count( $GLOBALS['rw_mail'] ) : 0 )
		&& false !== strpos( $wf_body, 'confirm' )
		&& false === strpos( $wf_body, "\u{2014}" )
		&& 'owner@example.com' === RW_Portal_Wordfence::client_email( $wf_site )
		&& 'conflicts' === $wf_view['status']
		&& 'sucuri-scanner/sucuri.php' === $wf_view['removable'][0]['file']
		&& 'loginizer/loginizer.php' === $wf_view['optional'][0]['file']
		&& ! empty( $wf_view['show_conflicts'] )
		&& empty( $wf_view['show_setup'] )
		&& ! isset( $wf_public['licence_key'] )
		&& ! isset( $wf_public['report']['licence_key'] )
		&& false === strpos( wp_json_encode( $wf_view ), 'abc123def4567890' )
);

$wf_copy = '';
foreach (
	array(
		__DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-conflicts.php',
		__DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-template.php',
		__DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-onboarding.php',
		__DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-wordfence-template.php',
		__DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-wordfence.php',
		__DIR__ . '/../reactwoo-support-portal/templates/partials/wordfence-onboarding.php',
	) as $wf_file
) {
	$wf_copy .= (string) file_get_contents( $wf_file );
}
$wf_partial = (string) file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/partials/wordfence-onboarding.php' );
$wf_apply_src = (string) file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-wordfence-template.php' );
rw_test(
	'onboarding copy is British English and settings are not raw database writes',
	false === strpos( $wf_copy, "\u{2014}" )
		&& false === strpos( $wf_copy, "\u{2013}" )
		&& false === stripos( $wf_partial, 'license' )
		&& false !== strpos( $wf_partial, 'deactivated and removed' )
		&& false === strpos( $wf_apply_src, '$wpdb' )
		&& false !== strpos( $wf_apply_src, 'wfConfig::set' )
		&& false !== strpos( $wf_apply_src, 'setConfig' )
		&& false !== strpos( $wf_copy, 'function rw_connector_wordfence_report' )
		&& false !== strpos( (string) file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector.php' ), 'rw_connector_enrolled' )
		&& false !== strpos( (string) file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/account-dashboard.php' ), 'wordfence-onboarding.php' )
);
