<?php
/**
 * Compact report ingest, triage, and portal action rules. No WordPress.
 *
 * Usage: php tests/maintenance-reports.php
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

$failures = 0;

function rw_test( $name, $ok ) {
	global $failures;
	if ( $ok ) {
		echo "ok  {$name}\n";
		return;
	}
	$failures++;
	echo "FAIL {$name}\n";
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
}
function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_parse_url( $url, $component = -1 ) {
	$parsed = parse_url( (string) $url );
	if ( -1 === $component ) {
		return $parsed;
	}
	$map = array(
		PHP_URL_HOST   => 'host',
		PHP_URL_PATH   => 'path',
		PHP_URL_SCHEME => 'scheme',
	);
	$key = isset( $map[ $component ] ) ? $map[ $component ] : '';
	return ( $parsed && $key && isset( $parsed[ $key ] ) ) ? $parsed[ $key ] : null;
}
function current_time( $type, $gmt = false ) {
	unset( $type, $gmt );
	return '2026-11-09 12:00:00';
}
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['rw_options'][ $key ] ) ? $GLOBALS['rw_options'][ $key ] : $default;
}

require dirname( __DIR__ ) . '/reactwoo-maint-bridge/includes/class-rw-maint-report.php';

$now     = 1700000000;
$example = array(
	'site_id'        => '42',
	'timestamp'      => '2026-11-09T12:00:00Z',
	'updates'        => array(
		array(
			'slug'                => 'woocommerce',
			'from'                => '8.0.0',
			'to'                  => '8.0.1',
			'is_security'         => true,
			'changelog_excerpt'   => 'Fixes a checkout auth check.',
			'severity_hint'       => 'critical',
			'extra_vendor_field'  => 'ignore me',
		),
	),
	'audit'          => array(
		'pages_checked' => 42,
		'failures'      => array(
			array(
				'url'    => '/checkout/',
				'status' => 500,
				'note'   => 'empty response',
			),
		),
	),
	'backup_ref'     => 'rwsnap_20261109T120000Z_abcd1234',
	'wordfence'      => array(
		'version'           => '8.0.1',
		'pro'               => true,
		'template_applied'  => 'v3',
		'conflicts_removed' => array(),
	),
	'schema_version' => '9',
);

$prepared = RW_Maint_Report::prepare( $example, $now );
$report   = is_array( $prepared ) ? $prepared['report'] : array();
$with_extra = $example;
$with_extra['another_optional'] = array( 'kept' => false );
$again = RW_Maint_Report::prepare( $with_extra, $now );
rw_test(
	'the example report parses and ignores extra fields',
	is_array( $prepared )
		&& 42 === $report['portal_site_id']
		&& 'exposure' === $report['phase']
		&& 'rwsnap_20261109T120000Z_abcd1234' === $report['backup_ref']
		&& 1 === count( $report['updates'] )
		&& '' === $report['schema_version']
		&& is_array( $again )
		&& $report['hash'] === $again['report']['hash']
);

$sparse = array(
	'site_id'   => '42',
	'updates'   => array(
		array(
			'slug' => 'akismet',
			'from' => '5.0',
			'to'   => '5.1',
		),
	),
);
$sparse_report = RW_Maint_Report::prepare( $sparse, $now );
rw_test(
	'missing optional audit, wordfence and timestamp are tolerated',
	is_array( $sparse_report )
		&& in_array( 'timestamp_missing', $sparse_report['report']['warnings'], true )
		&& null === $sparse_report['report']['audit']
		&& array() === $sparse_report['report']['wordfence']['conflicts_removed']
);

$schema_report = array(
	'schema_version' => '1.0.0',
	'site_id'        => '42',
	'timestamp'      => '2026-11-09T15:04:05Z',
	'updates'        => array(
		array(
			'slug'               => 'wordpress',
			'type'               => 'core',
			'current'            => '6.4.1',
			'latest'             => '6.4.2',
			'from'               => '6.4.1',
			'to'                 => '6.4.2',
			'is_security'        => true,
			'changelog_excerpt'  => 'Security fix.',
			'severity_hint'      => 'unknown',
		),
	),
	'audit'          => array(
		'pages_checked' => 42,
		'failures'      => array(
			array(
				'url'             => 'https://shop.example/checkout/?utm=1#pay',
				'status'          => 500,
				'note'            => 'empty response',
				'load_time_ms'    => 900,
				'content_present' => false,
			),
		),
	),
	'backup_ref'     => '',
	'wordfence'      => null,
);
$schema_parsed = RW_Maint_Report::prepare( $schema_report, $now );
$schema_row    = is_array( $schema_parsed ) ? $schema_parsed['report'] : array();
$schema_triage = is_array( $schema_parsed ) ? RW_Maint_Report::triage( $schema_row, $now, 24 ) : array();
rw_test(
	'schema 1.0.0 keeps core, unknown severity, a path-only failure and a null Wordfence block',
	is_array( $schema_parsed )
		&& '1.0.0' === $schema_row['schema_version']
		&& 'wordpress' === $schema_row['updates'][0]['slug']
		&& 'core' === $schema_row['updates'][0]['type']
		&& '6.4.1' === $schema_row['updates'][0]['current']
		&& '6.4.2' === $schema_row['updates'][0]['latest']
		&& 'unknown' === $schema_row['updates'][0]['severity_hint']
		&& null === $schema_row['wordfence']
		&& '' === $schema_row['backup_ref']
		&& ! in_array( 'backup_ref_ignored', $schema_row['warnings'], true )
		&& 20 === $schema_row['audit']['pages_checked']
		&& '/checkout/' === $schema_row['audit']['failures'][0]['url']
		&& ! isset( $schema_row['audit']['failures'][0]['load_time_ms'] )
		&& ! isset( $schema_row['audit']['failures'][0]['content_present'] )
		&& 'high' === $schema_triage['criticality']
		&& 'wordpress' === $schema_triage['exposed'][0]['slug']
);

$bad_ref = $example;
$bad_ref['backup_ref'] = 'not-a-snapshot';
$bad_prepared = RW_Maint_Report::prepare( $bad_ref, $now );
rw_test(
	'an invalid snapshot reference is dropped and the report is kept',
	is_array( $bad_prepared )
		&& '' === $bad_prepared['report']['backup_ref']
		&& in_array( 'backup_ref_ignored', $bad_prepared['report']['warnings'], true )
);

$too_big = str_repeat( 'a', RW_Maint_Report::MAX_ENVELOPE + 1 );
$oversize = RW_Maint_Report::prepare( $too_big, $now );
rw_test(
	'an oversized body is rejected',
	is_wp_error( $oversize ) && 'rw_report_too_large' === $oversize->get_error_code() && 413 === (int) $oversize->get_error_data()['status']
);

$triage = RW_Maint_Report::triage( $report, $now, 24 );
rw_test(
	'a critical security update is exposed and a 500 is high breakage',
	'critical' === $triage['criticality']
		&& 'woocommerce' === $triage['exposed'][0]['slug']
		&& 'high' === $triage['breakage_risk']
		&& '/checkout/' === $triage['failed_pages'][0]['url']
		&& 'soon' === $triage['window_key']
		&& false === strpos( $triage['window_label'], "\u{2014}" )
);

$low_security = RW_Maint_Report::prepare(
	array(
		'site_id' => '42',
		'updates' => array(
			array(
				'slug'          => 'hello',
				'from'          => '1.0',
				'to'            => '1.1',
				'is_security'   => 'yes',
				'severity_hint' => 'low',
			),
		),
	),
	$now
);
$low_triage = RW_Maint_Report::triage( $low_security['report'], $now, 24 );
rw_test(
	'a security update the reporter marks as low stays medium and exposed',
	'medium' === $low_triage['criticality'] && 'hello' === $low_triage['exposed'][0]['slug']
);

$plain = RW_Maint_Report::triage( $sparse_report['report'], $now, 24 );
rw_test(
	'a non-security update waits and does not count as exposure',
	'medium' === $plain['criticality']
		&& array() === $plain['exposed']
		&& 'akismet' === $plain['waiting'][0]['slug']
);

$post = $example;
$post['phase'] = 'post_patch';
$post['wordfence']['conflicts_removed'] = array( 'All In One WP Security' );
$post_prepared = RW_Maint_Report::prepare( $post, $now );
$post_triage   = RW_Maint_Report::triage( $post_prepared['report'], $now, 24 );
$post_notices  = RW_Maint_Report::notices(
	$post_prepared['report'],
	$post_triage,
	array(
		'host'           => 'shop.example',
		'account_url'    => 'https://reactwoo.com/my-account/maintenance/3/',
		'sender'         => 'portal',
		'notice_hours'   => 24,
		'now'            => $now,
		'report_enabled' => true,
	)
);
$post_body = isset( $post_notices[0]['body'] ) ? $post_notices[0]['body'] : '';
rw_test(
	'a post-update report lists what was fixed, the failed page and the three choices',
	1 === count( $post_notices )
		&& 'post_patch' === $post_notices[0]['notice_type']
		&& false !== strpos( $post_body, 'woocommerce 8.0.0 to 8.0.1' )
		&& false !== strpos( $post_body, 'Conflict removed: All In One WP Security' )
		&& false !== strpos( $post_body, '/checkout/ status 500: empty response' )
		&& false !== strpos( $post_body, '#rw-maint-report-rollback' )
		&& false !== strpos( $post_body, '#rw-maint-report-support' )
		&& false !== strpos( $post_body, '#rw-maint-report-self' )
		&& false !== strpos( $post_body, 'rwsnap_20261109T120000Z_abcd1234' )
		&& false === strpos( $post_body, "\u{2014}" )
		&& false === strpos( strtolower( $post_body ), 'guarantee' )
);

$exposure_notices = RW_Maint_Report::notices(
	$report,
	$triage,
	array(
		'host'           => 'shop.example',
		'sender'         => 'maint',
		'notice_hours'   => 24,
		'now'            => $now,
		'report_enabled' => true,
	)
);
$kinds = array();
foreach ( $exposure_notices as $notice ) {
	$kinds[] = $notice['notice_type'];
}
$planned_at = '';
foreach ( $exposure_notices as $notice ) {
	if ( 'planned_patch' === $notice['notice_type'] ) {
		$planned_at = $notice['send_after'];
	}
}
rw_test(
	'exposure mail is queued and the planned mail is not sent before the notice period allows',
	array( 'exposure', 'planned_patch' ) === $kinds
		&& gmdate( 'Y-m-d H:i:s', $now ) === $planned_at
		&& 'maint' === $exposure_notices[0]['sender']
);

$opt_out = RW_Maint_Report::notices(
	$report,
	$triage,
	array(
		'now'            => $now,
		'notice_hours'   => 24,
		'report_enabled' => false,
	)
);
rw_test( 'a customer who turned summaries off does not get a pending email', 'skipped' === $opt_out[0]['status'] );

$settings = RW_Maint_Report::normalise_settings( 0, 'maint', 24 );
$pending  = array(
	'status'     => 'pending',
	'sender'     => 'maint',
	'send_after' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
	'recipient'  => 'a@example.com',
);
rw_test(
	'disabled email settings do not send, and a stale notice expires',
	'disabled' === RW_Maint_Report::notice_decision( $settings, $pending, $now )
		&& 'send' === RW_Maint_Report::notice_decision( array( 'enabled' => true, 'sender' => 'maint' ), $pending, $now )
		&& 'other_sender' === RW_Maint_Report::notice_decision( array( 'enabled' => true, 'sender' => 'portal' ), $pending, $now )
		&& 'expire' === RW_Maint_Report::notice_decision( array( 'enabled' => true, 'sender' => 'maint' ), $pending, $now + RW_Maint_Report::EXPIRE_SECONDS + 120 )
);

$ref = 'rwsnap_20261109T120000Z_abcd1234';
$rollback_no = RW_Maint_Report::rollback_decision( $ref, $ref, false );
$rollback_bad = RW_Maint_Report::rollback_decision( $ref, 'rwsnap_20261109T120000Z_ffffeeee', true );
$rollback_ok = RW_Maint_Report::rollback_decision( $ref, $ref, true );
$rollback_missing = RW_Maint_Report::rollback_decision( '', $ref, true );
rw_test(
	'rollback needs confirmation and the stored snapshot reference',
	is_wp_error( $rollback_no ) && 'rw_report_confirm' === $rollback_no->get_error_code()
		&& is_wp_error( $rollback_bad ) && 'rw_report_backup' === $rollback_bad->get_error_code()
		&& is_wp_error( $rollback_missing )
		&& is_array( $rollback_ok ) && $ref === $rollback_ok['backup_ref']
);

$identity = RW_Maint_Report::identity_conflict( 42, array( 'site_id' => 'other-label' ) );
$identity_bad = RW_Maint_Report::identity_conflict( 42, array( 'site_id' => '7' ) );
rw_test(
	'a numeric site id must match the signed site and a label may differ',
	null === $identity && is_wp_error( $identity_bad )
);

class RW_Maint_DB {
	public static function table( $name ) {
		return 'wp_rw_maint_' . $name;
	}
}
class RW_Maint_Audit {
	public static $events = array();
	public static function log( $event, $data = array() ) {
		self::$events[] = array( 'event' => $event, 'data' => $data );
	}
}
class RW_Maint_Sites {
	public static $rows = array();
	public static function get_by_portal_site_id( $id ) {
		return isset( self::$rows[ (int) $id ] ) ? self::$rows[ (int) $id ] : null;
	}
	public static function get_by_mainwp_site_id( $id ) {
		foreach ( self::$rows as $row ) {
			if ( (int) $row->mainwp_site_id === (int) $id ) {
				return $row;
			}
		}
		return null;
	}
}
class RW_Report_Wpdb {
	public $insert_id = 0;
	public $inserts = array();
	public function insert( $table, $data, $format = array() ) {
		unset( $format );
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);
		return 1;
	}
	public function get_row( $sql ) {
		unset( $sql );
		return null;
	}
	public function get_results( $sql ) {
		unset( $sql );
		return array();
	}
	public function prepare( $sql, ...$args ) {
		unset( $args );
		return $sql;
	}
	public function update( $table, $data, $where, $format = array(), $where_format = array() ) {
		unset( $table, $data, $where, $format, $where_format );
		return 1;
	}
	public function query( $sql ) {
		unset( $sql );
		return 0;
	}
}

require dirname( __DIR__ ) . '/reactwoo-maint-bridge/includes/class-rw-maint-report-store.php';

$site = (object) array(
	'portal_site_id'  => 42,
	'mainwp_site_id'  => 12,
	'subscription_id' => 3,
	'user_id'         => 9,
	'site_url'        => 'https://shop.example',
	'report_email'    => 'owner@example.com',
	'report_enabled'  => 1,
);
RW_Maint_Sites::$rows[42] = $site;
$GLOBALS['wpdb'] = new RW_Report_Wpdb();

$stored = RW_Maint_Report_Store::ingest( $example, array( 'enabled' => 0, 'sender' => 'portal', 'notice_hours' => 24 ), $now );
$report_insert = null;
foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
	if ( false !== strpos( $insert['table'], 'reports' ) && false === strpos( $insert['table'], 'notice' ) ) {
		$report_insert = $insert['data'];
	}
}
rw_test(
	'a report is stored on the client account mapped from the MainWP site',
	is_array( $stored )
		&& 9 === (int) $report_insert['user_id']
		&& 12 === (int) $report_insert['mainwp_site_id']
		&& 3 === (int) $report_insert['subscription_id']
		&& 42 === (int) $report_insert['portal_site_id']
		&& 'maintenance_report_stored' === RW_Maint_Audit::$events[0]['event']
		&& 9 === (int) RW_Maint_Audit::$events[0]['data']['user_id']
);

$wrapped = array(
	'portal_site_id'  => 42,
	'subscription_id' => 3,
	'user_id'         => 8,
	'report'          => $example,
);
$mismatch = RW_Maint_Report_Store::ingest( $wrapped, null, $now );
$wrong_mainwp = $example;
$wrong_mainwp['mainwp_site_id'] = 99;
$mainwp_mismatch = RW_Maint_Report_Store::ingest( $wrong_mainwp, null, $now );
rw_test(
	'a report for another client or MainWP site is refused',
	is_wp_error( $mismatch ) && 'rw_report_site' === $mismatch->get_error_code()
		&& is_wp_error( $mainwp_mismatch ) && 'rw_report_site' === $mainwp_mismatch->get_error_code()
);

require dirname( __DIR__ ) . '/reactwoo-support-portal/includes/class-rw-support-sites.php';
require dirname( __DIR__ ) . '/reactwoo-support-portal/includes/class-rw-portal-reports.php';
require dirname( __DIR__ ) . '/reactwoo-support-portal/includes/class-rw-portal-settings.php';

$portal_identity = RW_Portal_Reports::identity_conflict( 42, array( 'portal_site_id' => 42, 'site_id' => 'label' ) );
$portal_conflict = RW_Portal_Reports::identity_conflict( 42, array( 'portal_site_id' => 7 ) );
$forward = RW_Portal_Reports::forward_body(
	(object) array(
		'id'              => 42,
		'subscription_id' => 3,
		'user_id'         => 9,
		'report_enabled'  => 1,
	),
	$example
);
rw_test(
	'the portal forwards the signed site and its email settings',
	null === $portal_identity
		&& is_wp_error( $portal_conflict )
		&& 42 === $forward['portal_site_id']
		&& 9 === $forward['user_id']
		&& 'portal' === $forward['email']['sender']
		&& 0 === $forward['email']['enabled']
		&& 24 === $forward['email']['notice_hours']
		&& $example === $forward['report']
);

$strip = RW_Portal_Reports::strip_for(
	array(
		'criticality'   => 'critical',
		'breakage_risk' => 'high',
		'summary'       => 'Security updates are waiting.',
	)
);
rw_test(
	'the report uses the light status strip rather than a second dark banner',
	is_array( $strip ) && 'warning' === $strip['tone'] && '#rw-maint-update-reports' === $strip['cta']['href']
);

$support = RW_Portal_Reports::support_request_fields( 9, 3, 42, 'https://shop.example/checkout', 'shop.example', 15 );
$support_other = RW_Portal_Reports::support_request_fields( 9, 3, 42, 'https://shop.example', 'other.example', 15 );
$support_bad = RW_Portal_Reports::support_request_fields( 9, 3, 42, '', '', 15 );
rw_test(
	'a support request is tied to the subscription website',
	is_array( $support )
		&& 'shop.example' === $support['service_host']
		&& 3 === $support['subscription_id']
		&& 42 === $support['managed_site_id']
		&& false === strpos( $support['summary'], 'other.example' )
		&& false !== strpos( $support_other['summary'], 'other.example' )
		&& is_wp_error( $support_bad )
);

$portal_site = (object) array(
	'id'              => 42,
	'subscription_id' => 3,
);
$rollback_body = RW_Portal_Reports::rollback_request_body( $portal_site, 15, $ref, true );
$rollback_open = RW_Portal_Reports::rollback_request_body( $portal_site, 15, $ref, false );
$restore_body = RW_Portal_Reports::restore_body( $ref );
$restore_bad = RW_Portal_Reports::restore_body( 'backup-1' );
rw_test(
	'rollback confirmation is required before the stored reference is sent',
	is_array( $rollback_body ) && 1 === $rollback_body['confirmed'] && 42 === $rollback_body['portal_site_id'] && $ref === $rollback_body['backup_ref']
		&& is_wp_error( $rollback_open )
		&& is_string( $restore_body ) && false !== strpos( $restore_body, $ref )
		&& is_wp_error( $restore_bad )
);

$dash = file_get_contents( dirname( __DIR__ ) . '/reactwoo-support-portal/templates/account-dashboard.php' );
$onboarding = file_get_contents( dirname( __DIR__ ) . '/reactwoo-support-portal/templates/account-onboarding.php' );
$sites_tpl = file_get_contents( dirname( __DIR__ ) . '/reactwoo-support-portal/templates/account-sites.php' );
$reports_src = file_get_contents( dirname( __DIR__ ) . '/reactwoo-support-portal/includes/class-rw-portal-reports.php' );
$bridge_header = file_get_contents( dirname( __DIR__ ) . '/reactwoo-maint-bridge/reactwoo-maint-bridge.php' );
$portal_header = file_get_contents( dirname( __DIR__ ) . '/reactwoo-support-portal/reactwoo-support-portal.php' );
$copy_files = array(
	dirname( __DIR__ ) . '/reactwoo-maint-bridge/includes/class-rw-maint-report.php',
	dirname( __DIR__ ) . '/reactwoo-support-portal/includes/class-rw-portal-reports.php',
	dirname( __DIR__ ) . '/reactwoo-support-portal/templates/partials/maintenance-reports.php',
	dirname( __DIR__ ) . '/docs/MAINTENANCE-REPORT-INGEST.md',
);
$copy = '';
foreach ( $copy_files as $copy_file ) {
	$copy .= (string) file_get_contents( $copy_file );
}
rw_test(
	'report screens are only included on the connected dashboard and emails stay off by default',
	false !== strpos( $dash, 'maintenance-reports.php' )
		&& false === strpos( $onboarding, 'maintenance-reports.php' )
		&& false === strpos( $sites_tpl, 'maintenance-reports.php' )
		&& false === strpos( $reports_src, 'wp_enqueue_scripts' )
		&& false !== strpos( $bridge_header, 'Version: 0.3.9' )
		&& false !== strpos( $portal_header, 'Version: 0.5.12' )
		&& RW_Portal_Reports::OPTION_ENABLED === RW_Portal_Settings::OPTION_REPORT_EMAILS
		&& false === RW_Portal_Reports::email_settings()['enabled']
		&& 'portal' === RW_Portal_Reports::email_settings()['sender']
		&& 24 === RW_Portal_Reports::email_settings()['notice_hours']
		&& false === strpos( $copy, "\u{2014}" )
		&& RW_Maint_Report::BACKUP_REF_PATTERN === RW_Portal_Reports::BACKUP_REF_PATTERN
		&& false !== strpos(
			(string) file_get_contents( dirname( __DIR__ ) . '/reactwoo-support-portal/includes/rest/class-rw-rest-controller.php' ),
			"'/sites/maintenance-report'"
		)
		&& false !== strpos( $copy, 'maintenance-report-1.0.0.schema.json' )
		&& ! is_file( dirname( __DIR__ ) . '/docs/maintenance-report-1.0.0.schema.json' )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} maintenance report checks failed\n" );
	exit( 1 );
}

echo "maintenance report checks passed\n";
