<?php
/**
 * Real audit-log classes. The main suite stubs those class names, so this runs separately.
 *
 * Usage: php tests/audit-hardening.php
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

$root = sys_get_temp_dir() . '/rw-maint-audit-hardening';
if ( ! is_dir( $root . '/wp-admin/includes' ) ) {
	mkdir( $root . '/wp-admin/includes', 0777, true );
}
file_put_contents(
	$root . '/wp-admin/includes/upgrade.php',
	"<?php\nif ( ! function_exists( 'dbDelta' ) ) {\n\tfunction dbDelta( \$sql ) {\n\t\t\$GLOBALS['rw_dbdelta'] = isset( \$GLOBALS['rw_dbdelta'] ) ? \$GLOBALS['rw_dbdelta'] + 1 : 1;\n\t}\n}\n"
);

define( 'ABSPATH', $root . '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$failures = 0;
$log_file = sys_get_temp_dir() . '/rw-maint-audit-hardening.log';
ini_set( 'log_errors', '1' );
ini_set( 'display_errors', '0' );
ini_set( 'error_log', $log_file );

function rw_test( $name, $ok ) {
	global $failures;
	if ( $ok ) {
		echo "ok  {$name}\n";
		return;
	}
	$failures++;
	echo "FAIL {$name}\n";
}

function rw_reset_log() {
	global $log_file;
	file_put_contents( $log_file, '' );
}

function rw_log_text() {
	global $log_file;
	return is_file( $log_file ) ? (string) file_get_contents( $log_file ) : '';
}

function rw_log_lines() {
	$lines = preg_split( "/\r\n|\n|\r/", rw_log_text() );
	return array_values(
		array_filter(
			$lines,
			function ( $line ) {
				return '' !== $line;
			}
		)
	);
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function current_time( $type, $gmt = false ) {
	unset( $type, $gmt );
	return '2026-11-04 12:00:00';
}
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['rw_options'][ $key ] ) ? $GLOBALS['rw_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['rw_options'][ $key ] = $value;
	return true;
}
function get_transient( $key ) {
	return isset( $GLOBALS['rw_transients'][ $key ] ) ? $GLOBALS['rw_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	unset( $ttl );
	$GLOBALS['rw_transients'][ $key ] = $value;
	return true;
}

class RW_Hardening_Wpdb {
	public $prefix      = 'wp_';
	public $last_error  = '';
	public $inserts     = 0;
	public $shows       = 0;
	public $insert_ok   = 1;
	public $tables      = array();

	public function get_charset_collate() {
		return 'utf8mb4';
	}

	public function prepare( $query, ...$args ) {
		return $query . '|' . ( isset( $args[0] ) ? (string) $args[0] : '' );
	}

	public function get_var( $sql ) {
		if ( false !== strpos( (string) $sql, 'SHOW TABLES' ) ) {
			$this->shows++;
		}
		$parts = explode( '|', (string) $sql );
		$name  = (string) end( $parts );
		return in_array( $name, $this->tables, true ) ? $name : null;
	}

	public function insert( $table, $data, $format = array() ) {
		unset( $table, $format );
		$this->inserts++;
		$this->last_insert = $data;
		return $this->insert_ok;
	}
}

$wpdb                  = new RW_Hardening_Wpdb();
$GLOBALS['rw_options'] = array();
$GLOBALS['rw_transients'] = array();
$GLOBALS['rw_dbdelta'] = 0;

require dirname( __DIR__ ) . '/reactwoo-maint-bridge/includes/class-rw-maint-db.php';
require dirname( __DIR__ ) . '/reactwoo-maint-bridge/includes/class-rw-maint-audit.php';

function rw_forget_audit_request() {
	$property = new ReflectionProperty( 'RW_Maint_DB', 'audit_log_known' );
	$property->setAccessible( true );
	$property->setValue( null, false );
}

function rw_reset_upgrade_cache() {
	rw_forget_audit_request();
	unset( $GLOBALS['rw_transients']['rw_maint_audit_log_ready'] );
}

$bridge = file_get_contents( dirname( __DIR__ ) . '/reactwoo-maint-bridge/reactwoo-maint-bridge.php' );
rw_test(
	'bridge version is 0.3.8',
	false !== strpos( $bridge, 'Version: 0.3.8' ) && false !== strpos( $bridge, "define( 'RW_MAINT_VERSION', '0.3.8' )" ) && false === strpos( $bridge, '0.3.7' )
);

rw_reset_log();
$wpdb->tables     = array();
$wpdb->last_error = "Table 'wp_rw_maint_audit_log' is missing";
RW_Maint_Audit::log(
	"site_enrolled\nsecond-line",
	array(
		'user_id'       => 4,
		'adminpassword' => 'secret-password',
		'note'          => 'hidden-note',
	)
);
$missing_lines = rw_log_lines();
$missing_text  = rw_log_text();
rw_test(
	'a missing audit table logs the event and database error on one line',
	1 === count( $missing_lines )
		&& false !== strpos( $missing_lines[0], 'site_enrolled' )
		&& false !== strpos( $missing_lines[0], "Table 'wp_rw_maint_audit_log' is missing" )
		&& false === strpos( $missing_text, 'secret-password' )
		&& false === strpos( $missing_text, 'hidden-note' )
		&& 0 === $wpdb->inserts
);

rw_reset_log();
$wpdb->tables     = array( 'wp_rw_maint_audit_log' );
$wpdb->insert_ok  = false;
$wpdb->last_error = "Deadlock found\nwhen writing";
$before_inserts   = $wpdb->inserts;
RW_Maint_Audit::log(
	'mainwp_client_linked',
	array(
		'portal_site_id' => 7,
		'uniqueid'       => 'secret-unique-id',
	)
);
$failed_lines = rw_log_lines();
$failed_text  = rw_log_text();
rw_test(
	'a failed audit insert logs the event and database error without the payload',
	1 === count( $failed_lines )
		&& false !== strpos( $failed_lines[0], 'mainwp_client_linked' )
		&& false !== strpos( $failed_lines[0], 'Deadlock found when writing' )
		&& false === strpos( $failed_lines[0], "\n" )
		&& false === strpos( $failed_text, 'secret-unique-id' )
		&& $wpdb->inserts === $before_inserts + 1
);

rw_reset_log();
$wpdb->insert_ok  = 1;
$wpdb->last_error = 'previous error';
RW_Maint_Audit::log( 'site_checked', array( 'user_id' => 4, 'note' => 'stored-note' ) );
rw_test(
	'a successful audit insert does not log an error',
	array() === rw_log_lines() && isset( $wpdb->last_insert['message'] ) && false !== strpos( (string) $wpdb->last_insert['message'], 'stored-note' )
);

$wpdb->shows               = 0;
$GLOBALS['rw_dbdelta']     = 0;
$GLOBALS['rw_options']['rw_maint_db_version'] = RW_Maint_DB::DB_VERSION;
$wpdb->tables              = array();
rw_reset_upgrade_cache();
RW_Maint_DB::maybe_upgrade();
rw_test(
	'a missing audit table is created when the schema version already matches',
	1 === $wpdb->shows && 3 === (int) $GLOBALS['rw_dbdelta']
);
$shows_after_create = $wpdb->shows;
$delta_after_create = (int) $GLOBALS['rw_dbdelta'];
RW_Maint_DB::maybe_upgrade();
rw_test(
	'the same request does not look up the audit table again',
	$wpdb->shows === $shows_after_create && (int) $GLOBALS['rw_dbdelta'] === $delta_after_create
);

rw_forget_audit_request();
$wpdb->shows = 0;
RW_Maint_DB::maybe_upgrade();
rw_test(
	'a later request trusts the cached audit table and skips SHOW TABLES',
	0 === $wpdb->shows && (int) $GLOBALS['rw_dbdelta'] === $delta_after_create && get_transient( 'rw_maint_audit_log_ready' )
);

rw_reset_upgrade_cache();
unset( $GLOBALS['rw_transients']['rw_maint_audit_log_ready'] );
$wpdb->tables          = array( 'wp_rw_maint_audit_log' );
$wpdb->shows           = 0;
$GLOBALS['rw_dbdelta'] = 0;
RW_Maint_DB::maybe_upgrade();
rw_test(
	'an existing audit table is not created again',
	1 === $wpdb->shows && 0 === (int) $GLOBALS['rw_dbdelta']
);
$shows_when_present = $wpdb->shows;
RW_Maint_DB::maybe_upgrade();
rw_test( 'a present audit table is remembered for the request', $wpdb->shows === $shows_when_present );

$GLOBALS['rw_options']['rw_maint_db_version'] = '1.0';
rw_reset_upgrade_cache();
$wpdb->shows           = 0;
$GLOBALS['rw_dbdelta'] = 0;
RW_Maint_DB::maybe_upgrade();
rw_test(
	'a schema version change still creates the tables',
	3 === (int) $GLOBALS['rw_dbdelta'] && RW_Maint_DB::DB_VERSION === get_option( 'rw_maint_db_version' )
);

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} audit hardening checks failed\n" );
	exit( 1 );
}

echo "audit hardening checks passed\n";
