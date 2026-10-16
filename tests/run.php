<?php
/**
 * Maintenance connection checks. No WordPress and no PHPUnit.
 *
 * Usage: php tests/run.php
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

class WP_REST_Request {
	private $body;
	private $headers;
	public function __construct( $body = '', array $headers = array() ) {
		$this->body    = (string) $body;
		$this->headers = array();
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( $name ) ] = $value;
		}
	}
	public function get_body() {
		return $this->body;
	}
	public function get_header( $name ) {
		$name = strtolower( $name );
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : '';
	}
	public function get_method() {
		return 'POST';
	}
	public function get_route() {
		return '/reactwoo-maint/v1/enroll';
	}
}

class WP_REST_Response {
	private $data;
	private $status;
	public function __construct( $data, $status ) {
		$this->data   = $data;
		$this->status = (int) $status;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}

class RW_DB {
	public static function table( $name ) {
		return 'rw_' . $name;
	}
}

class RW_Maint_DB {
	public static function table( $name ) {
		return 'rw_maint_' . $name;
	}
}

class RW_Audit {
	public static $events = array();
	public static function log( $event, $context = array() ) {
		self::$events[] = array( 'event' => $event, 'context' => $context );
	}
}

class RW_Tokens {
	public static function get_site_secret( $site_id ) {
		unset( $site_id );
		return 'site-secret';
	}
}

class RW_Maint_Audit {
	public static $events = array();
	public static function log( $event, $data = array() ) {
		self::$events[] = array( 'event' => $event, 'data' => $data );
	}
}

class RW_Test_Wpdb {
	public $insert_id = 0;
	public $attempts  = array();
	public $keys      = array();

	public function insert( $table, $data, $format = array() ) {
		unset( $format );
		if ( 'rw_maint_idempotency' === $table ) {
			if ( isset( $this->keys[ $data['idempotency_key'] ] ) ) {
				return false;
			}
			$this->keys[ $data['idempotency_key'] ] = (object) $data;
			return 1;
		}
		$this->insert_id++;
		$data['id']           = $this->insert_id;
		$data['completed_at'] = null;
		$this->attempts[]     = (object) $data;
		return 1;
	}

	public function prepare( $query, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $match ) use ( &$index, $args ) {
				$value = $args[ $index ];
				$index++;
				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}
				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		if ( ! preg_match( "/UPDATE rw_connection_attempts\s+SET status = '([^']*)', updated_at = '([^']*)'\s+WHERE state_hash = '([^']*)'\s+AND status = '([^']*)'\s+AND expires_at >= '([^']*)'\s+AND completed_at IS NULL/s", $sql, $match ) ) {
			throw new RuntimeException( 'Unexpected query: ' . $sql );
		}
		$count = 0;
		foreach ( $this->attempts as $row ) {
			if ( $row->state_hash === $match[3] && $row->status === $match[4] && $row->expires_at >= $match[5] && empty( $row->completed_at ) ) {
				$row->status     = $match[1];
				$row->updated_at = $match[2];
				$count++;
			}
		}
		return $count;
	}

	public function get_row( $sql ) {
		if ( preg_match( "/FROM rw_connection_attempts WHERE state_hash = '([^']*)'/", $sql, $match ) ) {
			foreach ( $this->attempts as $row ) {
				if ( $row->state_hash === $match[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( "/FROM rw_maint_idempotency WHERE idempotency_key = '([^']*)'/", $sql, $match ) ) {
			return isset( $this->keys[ $match[1] ] ) ? $this->keys[ $match[1] ] : null;
		}
		throw new RuntimeException( 'Unexpected select: ' . $sql );
	}

	public function update( $table, $data, $where, $format = array(), $where_format = array() ) {
		unset( $format, $where_format );
		if ( 'rw_maint_idempotency' !== $table || ! isset( $this->keys[ $where['idempotency_key'] ] ) ) {
			return false;
		}
		$row = $this->keys[ $where['idempotency_key'] ];
		if ( (int) $row->http_status !== (int) $where['http_status'] ) {
			return false;
		}
		foreach ( $data as $key => $value ) {
			$row->$key = $value;
		}
		return 1;
	}
}

class RW_Test_Subscription {
	public $id    = 1;
	public $meta  = array();
	public $items = array();
	public function get_id() {
		return $this->id;
	}
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
	}
	public function get_items() {
		return $this->items;
	}
}

class RW_Test_Product {
	public $meta = array();
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

class RW_Test_Item {
	public $product;
	public $product_id    = 0;
	public $variation_id  = 0;
	public function get_product() {
		return $this->product;
	}
	public function get_product_id() {
		return $this->product_id;
	}
	public function get_variation_id() {
		return $this->variation_id;
	}
}

$rw_requests = array();

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text, $domain = '' ) {
	unset( $domain );
	return $text;
}
function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function sanitize_text_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_parse_url( $url, $component = -1 ) {
	$parsed = parse_url( (string) $url );
	if ( -1 === $component ) {
		return $parsed;
	}
	$map = array(
		PHP_URL_SCHEME => 'scheme',
		PHP_URL_HOST   => 'host',
		PHP_URL_PORT   => 'port',
		PHP_URL_PATH   => 'path',
	);
	$key = isset( $map[ $component ] ) ? $map[ $component ] : '';
	return ( $parsed && $key && isset( $parsed[ $key ] ) ) ? $parsed[ $key ] : null;
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/' );
}
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/' ) . '/';
}
function absint( $value ) {
	return abs( (int) $value );
}
function current_time( $type, $gmt = false ) {
	unset( $type, $gmt );
	return gmdate( 'Y-m-d H:i:s' );
}
function home_url() {
	return 'https://portal.example';
}
function rest_url() {
	return 'https://portal.example/wp-json/';
}
function get_option( $key, $default = false ) {
	if ( isset( $GLOBALS['rw_options'][ $key ] ) ) {
		return $GLOBALS['rw_options'][ $key ];
	}
	if ( 'rw_maint_mainwp_url' === $key ) {
		return 'https://maint.example';
	}
	if ( 'rw_maint_mainwp_key' === $key ) {
		return 'test-key';
	}
	if ( 'admin_email' === $key ) {
		return 'staff@example.com';
	}
	return $default;
}
function apply_filters( $tag, $value ) {
	unset( $tag );
	return $value;
}
function add_filter() {
}
function add_action() {
}
function get_transient( $key ) {
	return isset( $GLOBALS['rw_transients'][ $key ] ) ? $GLOBALS['rw_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	unset( $ttl );
	$GLOBALS['rw_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['rw_transients'][ $key ] );
	return true;
}
function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function admin_url( $path = '' ) {
	return 'https://portal.example/wp-admin/' . ltrim( (string) $path, '/' );
}
function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function wp_mail( $to, $subject, $message ) {
	$GLOBALS['rw_mail'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	);
	return empty( $GLOBALS['rw_mail_fail'] );
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['rw_user_meta'][ (int) $user_id ][ $key ] = $value;
	return true;
}
function get_post_meta( $post_id, $key, $single = true ) {
	unset( $single );
	$post_id = (int) $post_id;
	return isset( $GLOBALS['rw_post_meta'][ $post_id ][ $key ] ) ? $GLOBALS['rw_post_meta'][ $post_id ][ $key ] : '';
}
function get_user_meta( $user_id, $key, $single = false ) {
	unset( $single );
	$user_id = (int) $user_id;
	return isset( $GLOBALS['rw_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['rw_user_meta'][ $user_id ][ $key ] : '';
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['rw_user_meta'][ (int) $user_id ][ $key ] );
	return true;
}
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	unset( $deprecated, $autoload );
	if ( isset( $GLOBALS['rw_options'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['rw_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['rw_options'][ $key ] );
	return true;
}
function wp_using_ext_object_cache() {
	return false;
}
function add_query_arg( $args, $url ) {
	$parts = array();
	foreach ( (array) $args as $key => $value ) {
		$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
	$join = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $join . implode( '&', $parts );
}
function wp_remote_request( $url, $args = array() ) {
	global $rw_requests;
	$rw_requests[] = array( 'url' => $url, 'args' => $args );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"id":44}',
	);
}
function wp_remote_retrieve_response_code( $response ) {
	return (int) $response['response']['code'];
}
function wp_remote_retrieve_body( $response ) {
	return (string) $response['body'];
}

$root = dirname( __DIR__ );
require $root . '/reactwoo-support-portal/includes/class-rw-connection-state.php';
require $root . '/reactwoo-support-portal/includes/class-rw-maintenance-plans.php';
require $root . '/reactwoo-support-portal/includes/class-rw-subscription-policy.php';
require $root . '/reactwoo-support-portal/includes/class-rw-redact.php';
require $root . '/reactwoo-support-portal/includes/class-rw-rest-url.php';
require $root . '/reactwoo-support-portal/includes/class-rw-maintenance-entitlements.php';
require $root . '/reactwoo-support-portal/includes/class-rw-site-url.php';
require $root . '/reactwoo-support-portal/includes/class-rw-connection-attempts.php';
require $root . '/reactwoo-support-portal/includes/class-rw-temporary-access.php';
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
require $root . '/reactwoo-support-portal/includes/class-rw-security.php';
require $root . '/reactwoo-maint-bridge/includes/class-rw-maint-security.php';
require $root . '/reactwoo-maint-bridge/includes/class-rw-maint-idempotency.php';
require $root . '/reactwoo-maint-bridge/includes/class-rw-maint-mainwp-client.php';

function rw_entitlement( array $overrides = array() ) {
	return array_merge(
		array(
			'is_maintenance'            => true,
			'plan_key'                  => 'standard',
			'site_limit'                => 1,
			'backup_frequency'          => 'daily',
			'technical_support_minutes' => 60,
			'reports'                   => true,
			'uptime_monitoring'         => true,
			'ssl_monitoring'            => true,
			'domain_monitoring'         => true,
			'vulnerability_monitoring'  => true,
			'performance_monitoring'    => false,
		),
		$overrides
	);
}

$none_plan = RW_Maintenance_Plans::resolve( '' );
rw_test( 'none is not maintenance', empty( $none_plan['is_maintenance'] ) && 'none' === $none_plan['plan_key'] );
rw_test( 'empty matches none', RW_Maintenance_Plans::normalize_key( '' ) === 'none' && RW_Maintenance_Plans::normalize_key( 'none' ) === 'none' );

foreach ( array( 'essential' => 0, 'standard' => 60, 'business' => 120 ) as $plan_key => $minutes ) {
	$plan = RW_Maintenance_Plans::resolve( $plan_key );
	rw_test(
		$plan_key . ' is a maintenance plan',
		! empty( $plan['is_maintenance'] )
			&& $minutes === (int) $plan['technical_support_minutes_monthly']
			&& 1 === (int) $plan['site_limit']
			&& empty( $plan['reactwoo_product_entitlements'] )
			&& ! isset( $plan['price'] )
			&& ! isset( $plan['currency'] )
	);
}
rw_test( 'business performance monitoring stays off until delivery is proven', empty( RW_Maintenance_Plans::resolve( 'business' )['performance_monitoring'] ) );

$blocked_limit = RW_Maintenance_Entitlements::allows_another_site( RW_Maintenance_Plans::resolve( 'standard' ), 1 );
$open_limit    = RW_Maintenance_Entitlements::allows_another_site( RW_Maintenance_Plans::resolve( 'standard' ), 0 );
rw_test( 'numeric limit blocks the next site', is_wp_error( $blocked_limit ) );
rw_test( 'numeric limit allows the first site', true === $open_limit );
rw_test( 'a non-maintenance entitlement cannot add a site', is_wp_error( RW_Maintenance_Entitlements::allows_another_site( $none_plan, 0 ) ) );

$GLOBALS['rw_post_meta'][10]['_rw_maintenance_plan_key'] = 'standard';
$GLOBALS['rw_post_meta'][11]['_rw_maintenance_plan_key'] = '';
$item = new RW_Test_Item();
$item->product_id = 10;
$subscription = new RW_Test_Subscription();
$subscription->items = array( $item );
$resolved = RW_Maintenance_Entitlements::resolve( $subscription );
rw_test( 'product plan key resolves when no snapshot exists', ! is_wp_error( $resolved ) && 'standard' === $resolved['plan_key'] );

$geo = new RW_Test_Subscription();
$geo_item = new RW_Test_Item();
$geo_item->product_id = 11;
$geo->items = array( $geo_item );
$geo_resolved = RW_Maintenance_Entitlements::resolve( $geo );
rw_test( 'a product without a plan key is hidden', ! is_wp_error( $geo_resolved ) && empty( $geo_resolved['is_maintenance'] ) );

$subscription->meta['_rw_maintenance_entitlements'] = wp_json_encode( array( 'plan_key' => 'business', 'site_limit' => 1 ) );
$snap = RW_Maintenance_Entitlements::resolve( $subscription );
rw_test( 'subscription snapshot wins over the product', ! is_wp_error( $snap ) && 'business' === $snap['plan_key'] && 120 === (int) $snap['technical_support_minutes_monthly'] );

$empty = new RW_Test_Subscription();
unset( $empty->id );
$none = RW_Maintenance_Entitlements::resolve( new stdClass() );
rw_test( 'no entitlement meta fails closed', is_wp_error( $none ) && 'rw_entitlement_missing' === $none->get_error_code() );

rw_test( 'enrol failure is not connected', false === RW_Subscription_Policy::operational_connected( new WP_Error( 'fail', 'no' ) ) );
rw_test( 'check failure is not connected', false === RW_Subscription_Policy::operational_connected( array( 'mainwp_site_id' => 4, 'initial_check' => false, 'initial_sync' => true ) ) );
rw_test( 'sync failure is not connected', false === RW_Subscription_Policy::operational_connected( array( 'mainwp_site_id' => 4, 'initial_check' => true, 'initial_sync' => false ) ) );
rw_test( 'check and sync together are connected', true === RW_Subscription_Policy::operational_connected( array( 'mainwp_site_id' => 4, 'initial_check' => true, 'initial_sync' => true ) ) );

rw_test( 'payment grace delays suspend', false === RW_Subscription_Policy::on_hold_should_suspend( true ) );
rw_test( 'manual on-hold suspends', true === RW_Subscription_Policy::on_hold_should_suspend( false ) );
$future = time() + 86400;
$past   = time() - 86400;
rw_test( 'pending-cancel with a future end stays entitled', RW_Subscription_Policy::entitlement_still_paid( $future, time() ) );
rw_test( 'a missing end date does not expire the service', RW_Subscription_Policy::entitlement_still_paid( null, time() ) );
rw_test( 'a passed end date starts offboarding', RW_Subscription_Policy::should_start_offboarding( $past, time() ) );
rw_test( 'a future end date does not offboard', ! RW_Subscription_Policy::should_start_offboarding( $future, time() ) );

$logged = RW_Redact::scrub(
	array(
		'portal_site_id'  => 7,
		'adminpassword'   => 'secret-password',
		'uniqueid'        => 'secret-id',
		'note'            => 'safe',
	)
);
$logged_text = wp_json_encode( $logged );
rw_test( 'failure logs drop credential fields', false === strpos( $logged_text, 'secret-password' ) && false === strpos( $logged_text, 'secret-id' ) && false !== strpos( $logged_text, 'safe' ) );
rw_test(
	'query REST reaches the bridge route',
	'https://maint.example/index.php?rest_route=' . rawurlencode( '/reactwoo-maint/v1/enroll' ) === RW_Rest_Url::build( 'https://maint.example', '/reactwoo-maint/v1/enroll', 'query' )
);
rw_test( 'the signature path is the REST route', '/reactwoo-maint/v1/enroll' === RW_Rest_Url::sign_path( '/reactwoo-maint/v1/enroll' ) );
rw_test( 'unique id is the default auth mode', 'unique_id' === RW_Maint_MainWP_Client::auth_mode( 'admin', 'pw', 'uid', array() ) );
rw_test( 'password mode is explicit', 'password' === RW_Maint_MainWP_Client::auth_mode( 'admin', 'pw', '', array() ) );

function rw_blocked( $url ) {
	$result = RW_Site_Url::assert_public_https( $url );
	return is_wp_error( $result );
}
rw_test( 'http is rejected', rw_blocked( 'http://example.com' ) );
rw_test( 'credentials in the URL are rejected', rw_blocked( 'https://user:secret@example.com' ) );
rw_test( 'localhost is rejected', rw_blocked( 'https://localhost' ) );
rw_test( 'local and internal names are rejected', rw_blocked( 'https://shop.local' ) && rw_blocked( 'https://db.internal' ) );
rw_test( 'loopback is rejected', rw_blocked( 'https://127.0.0.1' ) );
rw_test( 'private addresses are rejected', rw_blocked( 'https://10.1.2.3' ) && rw_blocked( 'https://192.168.1.20' ) );
rw_test( 'metadata address is rejected', rw_blocked( 'https://169.254.169.254' ) );
rw_test( 'www folds only for equality', RW_Site_Url::same_site( 'https://www.example.com/path', 'https://example.com/path/' ) );
rw_test( 'other subdomains stay distinct', ! RW_Site_Url::same_site( 'https://shop.example.com', 'https://example.com' ) );

$public = RW_Site_Url::assert_public_https( 'https://example.com/docs/' );
rw_test( 'public https host is canonical', is_string( $public ) && 'https://example.com/docs' === $public );

$wpdb = new RW_Test_Wpdb();
$site = (object) array(
	'id'              => 7,
	'user_id'         => 3,
	'subscription_id' => 9,
	'site_url'        => 'https://customer.example',
	'portal_site_id'  => 7,
);
$created = RW_Connection_Attempts::create( $site );
rw_test( 'attempt returns plaintext state once', is_array( $created ) && 64 === strlen( $created['state'] ) );
$stored = $wpdb->attempts[0];
rw_test( 'attempt stores only the state hash', hash( 'sha256', $created['state'] ) === $stored->state_hash && ! isset( $stored->state ) );

$first = RW_Connection_Attempts::consume( $created['state'] );
rw_test( 'state is consumed once', is_object( $first ) && RW_Connection_State::AUTHORISING === $first->status );
$replay = RW_Connection_Attempts::consume( $created['state'] );
rw_test( 'replaying a state is rejected', is_wp_error( $replay ) && 'rw_connection_state_invalid' === $replay->get_error_code() );

$expired = RW_Connection_Attempts::create( $site );
$wpdb->attempts[1]->expires_at = '2000-01-01 00:00:00';
$stale = RW_Connection_Attempts::consume( $expired['state'] );
rw_test( 'expired state is rejected', is_wp_error( $stale ) );

$body = '{"site":"https://customer.example"}';
$open = new WP_REST_Request( $body, array( 'x-rw-idempotency-key' => 'conn-attempt-1' ) );
rw_test( 'first enrol reserves the key', null === RW_Maint_Idempotency::begin( $open ) );
$done = new WP_REST_Response( array( 'mainwp_site_id' => 44 ), 200 );
RW_Maint_Idempotency::finish( $open, $done );
$again = RW_Maint_Idempotency::begin( $open );
rw_test( 'duplicate enrol replays the stored response', $again instanceof WP_REST_Response && 200 === $again->get_status() && 44 === $again->get_data()['mainwp_site_id'] );
$other = new WP_REST_Request( '{"site":"https://other.example"}', array( 'x-rw-idempotency-key' => 'conn-attempt-1' ) );
$conflict = RW_Maint_Idempotency::begin( $other );
rw_test( 'same key with a different body conflicts', is_wp_error( $conflict ) && 409 === $conflict->get_error_data()['status'] );
$bad_key = RW_Maint_Idempotency::begin( new WP_REST_Request( $body, array( 'x-rw-idempotency-key' => 'short' ) ) );
rw_test( 'invalid idempotency key is rejected', is_wp_error( $bad_key ) );
rw_test( 'missing idempotency key is not required', null === RW_Maint_Idempotency::begin( new WP_REST_Request( $body ) ) );

function rw_last_body() {
	global $rw_requests;
	$last = $rw_requests[ count( $rw_requests ) - 1 ];
	return json_decode( $last['args']['body'], true );
}

$managed = (object) array(
	'portal_site_id'  => 7,
	'subscription_id' => 9,
	'user_id'         => 3,
	'site_url'        => 'https://customer.example',
);
$before = count( $rw_requests );
$skipped = RW_Maint_MainWP_Client::create_site( 0, $managed, array( 'connection' => array( 'admin' => 'owner' ) ) );
rw_test( 'create without unique id or password does not call the API', 0 === $skipped && count( $rw_requests ) === $before && 'mainwp_create_skipped' === RW_Maint_Audit::$events[0]['event'] );

$unique = RW_Maint_MainWP_Client::create_site(
	0,
	$managed,
	array(
		'connection' => array(
			'admin'     => 'owner',
			'uniqueid'  => 'child-security-id',
			'auth_mode' => 'unique_id',
		),
	)
);
$unique_body = rw_last_body();
rw_test( 'unique id create does not send a password', 44 === $unique && 'owner' === $unique_body['admin'] && 'child-security-id' === $unique_body['uniqueid'] && ! array_key_exists( 'adminpassword', $unique_body ) );

$password = RW_Maint_MainWP_Client::create_site(
	0,
	$managed,
	array(
		'connection'    => array( 'admin' => 'owner' ),
		'adminpassword' => 'not-stored',
	)
);
$password_body = rw_last_body();
rw_test( 'password create sends the password and no unique id', 44 === $password && 'not-stored' === $password_body['adminpassword'] && ! array_key_exists( 'uniqueid', $password_body ) );
rw_test( 'existing operational site is not created again', 18 === RW_Maint_MainWP_Client::create_site( 18, $managed, array() ) );

$attention = (object) array(
	'id'               => 7,
	'user_id'          => 3,
	'subscription_id'  => 9,
	'site_url'         => 'https://customer.example/blog',
	'connection_state' => RW_Connection_State::ACTION_REQUIRED,
	'status'           => 'pending',
);
$manual = RW_Temporary_Access::request( $attention, 3 );
$mail   = $GLOBALS['rw_mail'][0]['message'];
preg_match( '/rw_manual_token=([a-f0-9]{64})/', $mail, $token_match );
$manual_token = isset( $token_match[1] ) ? $token_match[1] : '';
$audit_blob   = wp_json_encode( RW_Audit::$events );
rw_test( 'manual setup returns no link', 'sent' === $manual && '' !== $manual_token );
rw_test( 'manual setup emails the link once', 1 === substr_count( $mail, $manual_token ) && 'staff@example.com' === $GLOBALS['rw_mail'][0]['to'] );
rw_test( 'manual setup audit omits the link', false === strpos( $audit_blob, $manual_token ) && false === strpos( $audit_blob, 'rw_manual_token' ) );
$stored_blob = '';
foreach ( $GLOBALS['rw_transients'] as $stored ) {
	if ( is_string( $stored ) ) {
		$stored_blob .= $stored;
	}
}
$meta_hash = (string) get_user_meta( 3, 'rw_manual_pending_7', true );
rw_test( 'manual setup stores a hash, not the link', hash( 'sha256', $manual_token ) === $meta_hash && false === strpos( $stored_blob, $manual_token ) );
rw_test( 'a second request does not send another link', 'pending' === RW_Temporary_Access::request( $attention, 3 ) && 1 === count( $GLOBALS['rw_mail'] ) );

$opened = RW_Temporary_Access::consume( $manual_token );
rw_test( 'staff open removes the link', is_array( $opened ) && 'customer.example' === $opened['host'] && ! isset( $opened['token'] ) && ! RW_Temporary_Access::is_pending( $attention ) );
rw_test( 'the same link cannot be opened twice', is_wp_error( RW_Temporary_Access::consume( $manual_token ) ) );

$connected_site = clone $attention;
$connected_site->connection_state = RW_Connection_State::CONNECTED;
$blocked_manual = RW_Temporary_Access::request( $connected_site, 3 );
rw_test( 'manual setup is refused once connected', is_wp_error( $blocked_manual ) && 1 === count( $GLOBALS['rw_mail'] ) );

$GLOBALS['rw_mail_fail'] = true;
$undelivered = RW_Temporary_Access::request( $attention, 3 );
rw_test( 'a failed email leaves no link behind', is_wp_error( $undelivered ) && ! RW_Temporary_Access::is_pending( $attention ) );

function rw_signed_request( $secret, $nonce, $body = '{"ok":true}', $signature = null ) {
	$timestamp = (string) time();
	$payload   = 'POST|/reactwoo-maint/v1/enroll|' . $timestamp . '|' . $nonce . '|' . hash( 'sha256', $body );
	if ( null === $signature ) {
		$signature = hash_hmac( 'sha256', $payload, $secret );
	}
	return new WP_REST_Request(
		$body,
		array(
			'x-rw-signature' => $signature,
			'x-rw-timestamp' => $timestamp,
			'x-rw-nonce'     => $nonce,
			'x-rw-path'      => '/reactwoo-maint/v1/enroll',
		)
	);
}

$bad = RW_Security::verify_signed_request( rw_signed_request( 'site-secret', 'nonce-once', '{"ok":true}', str_repeat( 'a', 64 ) ), 4 );
rw_test( 'a bad signature does not consume the nonce', is_wp_error( $bad ) && 'rw_signature_invalid' === $bad->get_error_code() && empty( $GLOBALS['rw_options'] ) );
$first_sig = RW_Security::verify_signed_request( rw_signed_request( 'site-secret', 'nonce-once' ), 4 );
$replay_sig = RW_Security::verify_signed_request( rw_signed_request( 'site-secret', 'nonce-once' ), 4 );
rw_test( 'a portal nonce is accepted once', true === $first_sig && is_wp_error( $replay_sig ) && 'rw_signature_replay' === $replay_sig->get_error_code() );

$GLOBALS['rw_options']['rw_maint_portal_secret'] = str_repeat( 'ab', 32 );
$bridge_secret = str_repeat( 'ab', 32 );
$bridge_ok = RW_Maint_Security::verify_signed_request( rw_signed_request( $bridge_secret, 'bridge-once' ) );
$bridge_replay = RW_Maint_Security::verify_signed_request( rw_signed_request( $bridge_secret, 'bridge-once' ) );
rw_test( 'a bridge nonce is accepted once', true === $bridge_ok && is_wp_error( $bridge_replay ) && 'rw_maint_replay' === $bridge_replay->get_error_code() );

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} failed\n" );
	exit( 1 );
}

echo "\nAll maintenance connection tests passed\n";
