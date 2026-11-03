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
	private $method;
	private $route;
	public function __construct( $body = '', array $headers = array(), $method = 'POST', $route = '/reactwoo-maint/v1/enroll' ) {
		$this->body    = (string) $body;
		$this->headers = array();
		$this->method  = $method;
		$this->route   = $route;
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
		return $this->method;
	}
	public function get_route() {
		return $this->route;
	}
	public function get_json_params() {
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : array();
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
		PHP_URL_QUERY  => 'query',
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
function add_action( $hook = null, $callback = null, $priority = 10, $accepted_args = 1 ) {
	unset( $accepted_args );
	$GLOBALS['rw_actions'][] = array(
		'hook'     => $hook,
		'callback' => $callback,
		'priority' => (int) $priority,
	);
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
function update_option( $key, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['rw_options'][ $key ] = $value;
	return true;
}
function wp_using_ext_object_cache() {
	return false;
}
function add_query_arg( $args, $url = '', $maybe_url = null ) {
	if ( is_string( $args ) && null !== $maybe_url ) {
		$args = array( $args => $url );
		$url  = $maybe_url;
	}
	// WordPress add_query_arg() does not encode values it adds. Callers that
	// put a URL in a query value must rawurlencode it themselves.
	$parts = array();
	foreach ( (array) $args as $key => $value ) {
		$parts[] = rawurlencode( (string) $key ) . '=' . (string) $value;
	}
	$join = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
	return $url . $join . implode( '&', $parts );
}
function remove_query_arg( $key, $url ) {
	$parts = parse_url( (string) $url );
	if ( ! is_array( $parts ) ) {
		return (string) $url;
	}
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	foreach ( (array) $key as $name ) {
		unset( $query[ $name ] );
	}
	$scheme  = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host    = isset( $parts['host'] ) ? $parts['host'] : '';
	$port    = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$path    = isset( $parts['path'] ) ? $parts['path'] : '';
	$rebuilt = $scheme . $host . $port . $path;
	if ( ! empty( $query ) ) {
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}
		$rebuilt .= '?' . implode( '&', $pairs );
	}
	return $rebuilt;
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
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}
function wp_remote_retrieve_headers( $response ) {
	return isset( $response['headers'] ) ? $response['headers'] : array();
}
function wp_remote_retrieve_header( $response, $header ) {
	$headers = wp_remote_retrieve_headers( $response );
	$name    = strtolower( (string) $header );
	if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
		$all = $headers->getAll();
		if ( is_array( $all ) && isset( $all[ $name ] ) ) {
			return $all[ $name ];
		}
		return '';
	}
	if ( is_array( $headers ) ) {
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $name ) {
				return $value;
			}
		}
	}
	return '';
}
function wp_remote_get( $url, $args = array() ) {
	unset( $args );
	$GLOBALS['rw_http_get_log'][] = $url;
	if ( isset( $GLOBALS['rw_http_get'][ $url ] ) ) {
		return $GLOBALS['rw_http_get'][ $url ];
	}
	return array(
		'response' => array( 'code' => 404 ),
		'headers'  => array(),
		'body'     => '',
	);
}

class RW_Test_HeaderBag {
	private $all;
	public function __construct( array $all ) {
		$this->all = $all;
	}
	public function getAll() {
		return $this->all;
	}
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

$client_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-maint-client.php' );
rw_test( 'enrol waits long enough for MainWP', false !== strpos( $client_src, 'const ENROLL_TIMEOUT  = 180' ) && false !== strpos( $client_src, 'timeout_for( $action )' ) );

$orchestrator_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-connection-orchestrator.php' );
$plugin_lookup_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-wordpress-client.php' );
rw_test( 'plugin ids match with or without .php', false !== strpos( $orchestrator_src, 'get_plugin(' ) && false !== strpos( $plugin_lookup_src, 'plugin_route( $plugin_file )' ) );
rw_test(
	'onboarding installs components after the connector is enrolled',
	false === strpos( $orchestrator_src, 'maintenance_component_missing' )
		&& false !== strpos( $orchestrator_src, 'install_components' )
		&& false !== strpos( $orchestrator_src, 'connector_identity' )
		&& false !== strpos( $orchestrator_src, 'components_timeout' )
);

require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-signing.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-component-installer.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-whitelabel.php';
require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-mainwp.php';

$GLOBALS['rw_options']['rw_connector_site_id']     = 9;
$GLOBALS['rw_options']['rw_connector_site_secret'] = 'connector-site-secret';
$install_route = '/reactwoo-connector/v2/install-components';
$install_body  = '{"components":["mainwp-child","mainwp-child-reports"]}';

function rw_connector_signed( $secret, $nonce, $body, $route, $signature = null ) {
	$timestamp = (string) time();
	$payload   = 'POST|' . $route . '|' . $timestamp . '|' . $nonce . '|' . hash( 'sha256', $body );
	if ( null === $signature ) {
		$signature = hash_hmac( 'sha256', $payload, $secret );
	}
	return new WP_REST_Request(
		$body,
		array(
			'x-rw-signature' => $signature,
			'x-rw-timestamp' => $timestamp,
			'x-rw-nonce'     => $nonce,
			'x-rw-site-id'   => '9',
		),
		'POST',
		$route
	);
}

$rejected = RW_Connector_Signing::verify_request( rw_connector_signed( 'connector-site-secret', 'install-nonce', $install_body, $install_route, str_repeat( 'b', 64 ) ) );
$accepted = RW_Connector_Signing::verify_request( rw_connector_signed( 'connector-site-secret', 'install-nonce', $install_body, $install_route ) );
$replayed = RW_Connector_Signing::verify_request( rw_connector_signed( 'connector-site-secret', 'install-nonce', $install_body, $install_route ) );
rw_test( 'a bad component signature is rejected and does not burn the nonce', is_wp_error( $rejected ) && 'rw_connector_signature_invalid' === $rejected->get_error_code() && true === $accepted );
rw_test( 'a component signature cannot be replayed', is_wp_error( $replayed ) && 'rw_connector_replay' === $replayed->get_error_code() );

$unknown = RW_Component_Installer::requested_slugs( array( 'components' => array( 'hello-dolly' ) ) );
$supplied = RW_Component_Installer::requested_slugs(
	array(
		'components'   => array( 'mainwp-child' ),
		'download_url' => 'https://evil.example/plugin.zip',
	)
);
$allowed = RW_Component_Installer::requested_slugs( array( 'components' => array( 'mainwp-child', 'mainwp-child-reports' ) ) );
rw_test( 'only the two maintenance components are allowed', is_wp_error( $unknown ) && 'rw_component_not_allowed' === $unknown->get_error_code() );
rw_test( 'a caller-supplied package URL is rejected', is_wp_error( $supplied ) && 'rw_component_url_rejected' === $supplied->get_error_code() );
rw_test( 'the allowlist accepts both pinned components', array( 'mainwp-child', 'mainwp-child-reports' ) === $allowed );
rw_test( 'wordpress.org and R2 hosts are the only package hosts', RW_Component_Installer::allowed_package_url( 'https://downloads.wordpress.org/plugin/mainwp-child.6.4.zip' ) && RW_Component_Installer::allowed_package_url( 'https://example.r2.cloudflarestorage.com/bucket/mainwp-child.zip?X-Amz-Signature=abc' ) && ! RW_Component_Installer::allowed_package_url( 'https://evil.example/plugin.zip' ) && ! RW_Component_Installer::allowed_package_url( 'http://downloads.wordpress.org/plugin/mainwp-child.6.4.zip' ) );

$package_path = tempnam( sys_get_temp_dir(), 'rwhash' );
file_put_contents( $package_path, 'maintenance-package' );
$package_hash = hash( 'sha256', 'maintenance-package' );
rw_test( 'a matching package hash is accepted', RW_Component_Installer::package_hash_matches( $package_path, $package_hash ) );
rw_test( 'a package hash mismatch is rejected', ! RW_Component_Installer::package_hash_matches( $package_path, str_repeat( 'a', 64 ) ) && ! RW_Component_Installer::package_hash_matches( $package_path, 'not-a-hash' ) );
unlink( $package_path );

$pins = json_decode( (string) file_get_contents( __DIR__ . '/../scripts/component-pins.json' ), true );
$catalog = RW_Component_Installer::catalog();
$pins_match = is_array( $pins ) && isset( $pins['components'] ) && 2 === count( $pins['components'] );
foreach ( (array) $pins['components'] as $pin ) {
	$slug = $pin['slug'];
	$pins_match = $pins_match && isset( $catalog[ $slug ] ) && $catalog[ $slug ]['version'] === $pin['version'] && $catalog[ $slug ]['sha256'] === $pin['sha256'];
}
rw_test( 'connector catalog matches the pinned component manifest', $pins_match );

$plugins = array(
	'mainwp-child/mainwp-child.php'                         => array( 'Name' => 'MainWP Child' ),
	'mainwp-child-reports/mainwp-child-reports.php'         => array( 'Name' => 'MainWP Child Reports' ),
	'reactwoo-support-connector/reactwoo-support-connector.php' => array( 'Name' => 'ReactWoo Support Connector' ),
	'akismet/akismet.php'                                   => array( 'Name' => 'Akismet' ),
);
$visible = RW_Whitelabel::filter_plugins( $plugins );
rw_test( 'plugins stay visible before the site is managed', isset( $visible['mainwp-child/mainwp-child.php'] ) && isset( $visible['akismet/akismet.php'] ) );
$GLOBALS['rw_options']['rw_connector_managed'] = '1';
$hidden = RW_Whitelabel::filter_plugins( $plugins );
rw_test( 'managed sites hide the child and reports plugins', ! isset( $hidden['mainwp-child/mainwp-child.php'] ) && ! isset( $hidden['mainwp-child-reports/mainwp-child-reports.php'] ) && isset( $hidden['reactwoo-support-connector/reactwoo-support-connector.php'] ) && isset( $hidden['akismet/akismet.php'] ) );
rw_test( 'action links for a hidden plugin are removed', array() === RW_Whitelabel::filter_action_links( array( 'deactivate' => 'Deactivate' ), 'mainwp-child/mainwp-child.php' ) && isset( RW_Whitelabel::filter_action_links( array( 'deactivate' => 'Deactivate' ), 'akismet/akismet.php' )['deactivate'] ) );
$updates = new stdClass();
$updates->response = array(
	'mainwp-child/mainwp-child.php' => (object) array( 'new_version' => '6.4' ),
	'akismet/akismet.php'           => (object) array( 'new_version' => '5.0' ),
);
$updates->no_update = array( 'mainwp-child-reports/mainwp-child-reports.php' => (object) array( 'new_version' => '2.3.1' ) );
$filtered_updates = RW_Whitelabel::filter_updates( $updates );
rw_test( 'update notices for hidden plugins are removed', ! isset( $filtered_updates->response['mainwp-child/mainwp-child.php'] ) && ! isset( $filtered_updates->no_update['mainwp-child-reports/mainwp-child-reports.php'] ) && isset( $filtered_updates->response['akismet/akismet.php'] ) );
rw_test( 'child and reports admin pages are blocked', RW_Whitelabel::is_blocked_page( 'mainwp_child_tab' ) && RW_Whitelabel::is_blocked_page( 'mainwp-reports-page' ) && RW_Whitelabel::is_blocked_page( 'mainwp-reports-settings' ) && ! RW_Whitelabel::is_blocked_page( 'plugins' ) );
$notice_path = new ReflectionMethod( 'RW_Whitelabel', 'path_is_hidden' );
$notice_path->setAccessible( true );
rw_test( 'notices from the hidden plugin directories are selected', $notice_path->invoke( null, '/wp-content/plugins/mainwp-child/class/class-mainwp-pages.php' ) && $notice_path->invoke( null, '/wp-content/plugins/mainwp-child-reports/classes/class-admin.php' ) && ! $notice_path->invoke( null, '/wp-content/plugins/akismet/akismet.php' ) );
rw_test( 'a ReactWoo support email bypasses the hide', RW_Whitelabel::is_reactwoo_support_email( 'support@reactwoo.com' ) && ! RW_Whitelabel::is_reactwoo_support_email( 'owner@example.com' ) );
$GLOBALS['rw_options']['rw_connector_hide_self'] = '1';
$hidden_self = RW_Whitelabel::filter_plugins( $plugins );
rw_test( 'the connector is hidden only when that flag is set', ! isset( $hidden_self['reactwoo-support-connector/reactwoo-support-connector.php'] ) );
unset( $GLOBALS['rw_options']['rw_connector_managed'], $GLOBALS['rw_options']['rw_connector_hide_self'] );

$branding = RW_Maint_MainWP::branding_calls();
$branding_settings = json_decode( base64_decode( $branding[0]['params']['settings'] ), true );
rw_test( 'enrol pushes the hide-child branding settings', 'branding_child_plugin' === $branding[0]['what'] && 1 === (int) $branding_settings['child_plugin_hide'] && 'client_report' === $branding[1]['what'] && 'hide' === $branding[1]['params']['showhide'] );

$component_versions = RW_Maint_MainWP_Client::extract_component_versions(
	array(
		'plugins' => array(
			'mainwp-child/mainwp-child.php'                 => array( 'version' => '6.4' ),
			'mainwp-child-reports/mainwp-child-reports.php' => array( 'version' => '2.3.1' ),
		),
	)
);
rw_test( 'check and sync can read both component versions', '6.4' === $component_versions['mainwp_child_version'] && '2.3.1' === $component_versions['mainwp_child_reports_version'] );

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-wordpress-client.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-account.php';

$link_values = array(
	'<https://example.com/?p=1>; rel="shortlink"',
	'<https://example.com/blog/wp-json/>; rel="https://api.w.org/"',
);
$from_array = '';
foreach ( RW_WordPress_Client::header_values( array( 'headers' => array( 'link' => $link_values ) ), 'link' ) as $value ) {
	$found = RW_WordPress_Client::rest_root_from_link( $value );
	if ( '' !== $found ) {
		$from_array = $found;
	}
}
rw_test( 'multiple Link headers still expose the REST root', 'https://example.com/blog/wp-json/' === $from_array );
$from_bag = '';
foreach ( RW_WordPress_Client::header_values( array( 'headers' => new RW_Test_HeaderBag( array( 'link' => $link_values ) ) ), 'link' ) as $value ) {
	$found = RW_WordPress_Client::rest_root_from_link( $value );
	if ( '' !== $found ) {
		$from_bag = $found;
	}
}
rw_test( 'header getAll() exposes the REST root', 'https://example.com/blog/wp-json/' === $from_bag );
rw_test(
	'an HTML API link is read in either attribute order',
	'https://example.com/blog/wp-json/' === RW_WordPress_Client::rest_root_from_html( '<html><link href="https://example.com/blog/wp-json/" rel="https://api.w.org/"></html>' )
		&& 'https://example.com/blog/wp-json/' === RW_WordPress_Client::rest_root_from_html( "<html><link rel='https://api.w.org/' href='https://example.com/blog/wp-json/' /></html>" )
);

function rw_rest_index_body() {
	return wp_json_encode(
		array(
			'namespaces'       => array( 'wp/v2' ),
			'authentication'   => array(
				'application-passwords' => array(
					'endpoints' => array(
						'authorization' => 'https://example.com/wp-admin/authorize-application.php',
					),
				),
			),
		)
	);
}
function rw_http_page( $code, $body, $headers = array() ) {
	return array(
		'response' => array( 'code' => $code ),
		'headers'  => $headers,
		'body'     => $body,
	);
}

$home = RW_Site_Url::assert_public_https( 'https://example.com' );
$blog_root = RW_Site_Url::assert_public_https( 'https://example.com/blog/wp-json/' );
$GLOBALS['rw_http_get_log'] = array();
$GLOBALS['rw_http_get'] = array(
	$home => rw_http_page( 200, '<html>front</html>', array( 'link' => $link_values ) ),
	$blog_root => rw_http_page( 200, rw_rest_index_body() ),
);
$discovered = RW_WordPress_Client::discover( 'https://example.com' );
rw_test( 'discovery follows an array of Link headers', is_array( $discovered ) && $blog_root === $discovered['rest_root'] && false === array_search( RW_WordPress_Client::rest_probe_urls( $home )[0], $GLOBALS['rw_http_get_log'], true ) );

$GLOBALS['rw_http_get_log'] = array();
$GLOBALS['rw_http_get'] = array(
	$home => rw_http_page( 200, '<html><link href="https://example.com/blog/wp-json/" rel="https://api.w.org/"></html>' ),
	$blog_root => rw_http_page( 200, rw_rest_index_body() ),
);
$from_html = RW_WordPress_Client::discover( 'https://example.com' );
rw_test( 'discovery reads the HTML API link when no Link header is present', is_array( $from_html ) && $blog_root === $from_html['rest_root'] );

$probes = RW_WordPress_Client::rest_probe_urls( $home );
$pretty = RW_Site_Url::assert_public_https( $probes[0] );
$plain  = $probes[1];
$GLOBALS['rw_http_get_log'] = array();
$GLOBALS['rw_http_get'] = array(
	$home => rw_http_page( 403, '<html>staging login wall</html>' ),
	$pretty => rw_http_page( 200, rw_rest_index_body() ),
);
$walled = RW_WordPress_Client::discover( 'https://example.com' );
rw_test( 'a hidden front page still discovers /wp-json/', is_array( $walled ) && $pretty === $walled['rest_root'] && in_array( $pretty, $GLOBALS['rw_http_get_log'], true ) );

$GLOBALS['rw_http_get_log'] = array();
$GLOBALS['rw_http_get'] = array(
	$home => rw_http_page( 200, '<html>staging login wall</html>' ),
	$pretty => rw_http_page( 404, 'missing' ),
	$plain => rw_http_page( 200, rw_rest_index_body() ),
);
$plain_discovered = RW_WordPress_Client::discover( 'https://example.com' );
rw_test( 'discovery falls through to ?rest_route=/ when /wp-json/ is hidden', is_array( $plain_discovered ) && false !== strpos( $plain_discovered['rest_root'], 'rest_route' ) && in_array( $plain, $GLOBALS['rw_http_get_log'], true ) );
unset( $GLOBALS['rw_http_get'], $GLOBALS['rw_http_get_log'] );

rw_test( 'a newer local connector zip wins over the published version', RW_Portal_Account::connector_download_prefers_local( '0.2.1', '0.3.0' ) && ! RW_Portal_Account::connector_download_prefers_local( '0.3.0', '0.2.1' ) && ! RW_Portal_Account::connector_download_prefers_local( '0.3.0', '0.3.0' ) && ! RW_Portal_Account::connector_download_prefers_local( '', '0.3.0' ) );
if ( class_exists( 'ZipArchive' ) ) {
	$zip_path = tempnam( sys_get_temp_dir(), 'rwzip' );
	$zip = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::OVERWRITE );
	$zip->addFromString( 'reactwoo-support-connector/reactwoo-support-connector.php', "<?php\n/**\n * Version: 0.3.0\n */\n" );
	$zip->close();
	rw_test( 'the local connector zip version is read from the plugin header', '0.3.0' === RW_Portal_Account::connector_zip_version( $zip_path ) );
	unlink( $zip_path );
}

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-connection-orchestrator.php';

$flow_site = (object) array(
	'id'              => 7,
	'user_id'         => 3,
	'subscription_id' => 9,
	'site_url'        => 'https://customer.example',
);
$flow_attempt = RW_Connection_Attempts::create( $flow_site );
$flow_state   = $flow_attempt['state'];
$auth_url     = RW_Connection_Orchestrator::authorization_redirect_url(
	'https://customer.example/wp-admin/authorize-application.php?action=authorize-application',
	'https://example.com/my-account/maintenance/?utm=1',
	$flow_state,
	'11111111-1111-4111-8111-111111111111'
);
$auth_query = array();
parse_str( (string) parse_url( $auth_url, PHP_URL_QUERY ), $auth_query );
$success_query = array();
parse_str( (string) parse_url( $auth_query['success_url'], PHP_URL_QUERY ), $success_query );
$reject_query = array();
parse_str( (string) parse_url( $auth_query['reject_url'], PHP_URL_QUERY ), $reject_query );
rw_test(
	'parsed success_url keeps state',
	! isset( $auth_query['state'] )
		&& $flow_state === $success_query['state']
		&& 'callback' === $success_query['rw_connect']
		&& '1' === (string) $success_query['utm']
		&& $flow_state === $reject_query['state']
		&& 'rejected' === $reject_query['rw_connect']
);

$returned = add_query_arg(
	array(
		'site_url'   => 'https://customer.example',
		'user_login' => 'owner',
		'password'   => 'app-password',
	),
	$auth_query['success_url']
);
$returned_query = array();
parse_str( (string) parse_url( $returned, PHP_URL_QUERY ), $returned_query );
$callback_state = RW_Connection_Orchestrator::state_from_callback_query( $returned_query['state'] );
$authorised     = RW_Connection_Attempts::consume( $callback_state );
$missing_state  = RW_Connection_Attempts::consume( '' );
rw_test( 'callback state still matches the attempt after WordPress appends the credential', is_object( $authorised ) && RW_Connection_State::AUTHORISING === $authorised->status && $flow_state === $callback_state );
rw_test( 'a callback without state is the missing-state error', is_wp_error( $missing_state ) && 'Connection state is missing.' === $missing_state->get_error_message() );

$query_root = 'https://customer.example/?rest_route=/';
$route_ok   = true;
foreach ( array( 'reactwoo-connector/v2/install-components', 'reactwoo-connector/v2/status', 'reactwoo-connector/v2/identity' ) as $route ) {
	$endpoint = RW_WordPress_Client::api_url( $query_root, $route );
	$endpoint_query = array();
	parse_str( (string) parse_url( $endpoint, PHP_URL_QUERY ), $endpoint_query );
	if ( ! isset( $endpoint_query['rest_route'] ) || '/' . $route !== $endpoint_query['rest_route'] ) {
		$route_ok = false;
	}
}
$pretty_install = RW_WordPress_Client::api_url( 'https://customer.example/wp-json/', 'reactwoo-connector/v2/install-components' );
rw_test( 'install, status, and identity keep one REST route parameter', $route_ok && 'https://customer.example/wp-json/reactwoo-connector/v2/install-components' === $pretty_install );

$flow_body  = '{"components":["mainwp-child","mainwp-child-reports"]}';
$flow_route = '/reactwoo-connector/v2/install-components';
$flow_nonce = 'flow-install-nonce';
$flow_time  = (string) time();
$flow_sign  = hash_hmac( 'sha256', 'POST|' . $flow_route . '|' . $flow_time . '|' . $flow_nonce . '|' . hash( 'sha256', $flow_body ), 'connector-site-secret' );
$GLOBALS['rw_options']['rw_connector_site_id']     = 9;
$GLOBALS['rw_options']['rw_connector_site_secret'] = 'connector-site-secret';
$flow_request = new WP_REST_Request(
	$flow_body,
	array(
		'x-rw-signature' => $flow_sign,
		'x-rw-timestamp' => $flow_time,
		'x-rw-nonce'     => $flow_nonce,
		'x-rw-site-id'   => '9',
	),
	'POST',
	$flow_route
);
$client_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-wordpress-client.php' );
rw_test( 'install-components stays signed against its route and the challenge stays a header', true === RW_Connector_Signing::verify_request( $flow_request ) && false !== strpos( $client_src, "'X-RW-Enrolment-Challenge' => \$challenge" ) );

$GLOBALS['rw_actions'] = array();
RW_Connection_Orchestrator::register();
$return_hook = null;
foreach ( $GLOBALS['rw_actions'] as $hook ) {
	if ( is_array( $hook['callback'] ) && isset( $hook['callback'][1] ) && 'handle_return' === $hook['callback'][1] ) {
		$return_hook = $hook;
	}
}
rw_test(
	'the connection callback waits until subscriptions are registered',
	is_array( $return_hook ) && 'wp_loaded' === $return_hook['hook'] && $return_hook['priority'] >= 10
);

$early_wc = array();
foreach ( array( 'reactwoo-support-portal', 'reactwoo-support-connector', 'reactwoo-maint-bridge' ) as $plugin_dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../' . $plugin_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		$source = file_get_contents( $file->getPathname() );
		if ( ! preg_match_all( "/add_action\\(\\s*'init'\\s*,\\s*array\\(\\s*(?:__CLASS__|'[A-Za-z0-9_]+')\\s*,\\s*'([A-Za-z0-9_]+)'\\s*\\)(?:\\s*,\\s*(\\d+))?/", $source, $hits, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $hits as $hit ) {
			$priority = ( isset( $hit[2] ) && '' !== $hit[2] ) ? (int) $hit[2] : 10;
			if ( $priority >= 20 ) {
				continue;
			}
			if ( ! preg_match( '/function\\s+' . $hit[1] . '\\s*\\(/', $source, $fn, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$start = $fn[0][1];
			$end   = strlen( $source );
			if ( preg_match( '/\\n\\t(?:public|private|protected)\\s+static\\s+function\\s+/', $source, $next, PREG_OFFSET_CAPTURE, $start + 10 ) ) {
				$end = $next[0][1];
			}
			$body = substr( $source, $start, $end - $start );
			if ( preg_match( '/wcs_get_subscription|wcs_get_subscriptions|\\\\\\\\MainWP\\\\\\\\|wc_get_order\\s*\\(/', $body ) ) {
				$early_wc[] = $file->getFilename() . ':' . $hit[1] . '@' . $priority;
			}
		}
	}
}
rw_test( 'no early init hook calls WooCommerce Subscriptions or MainWP', array() === $early_wc );
$denied_status = new WP_Error( 'rw_connector_signature_invalid', 'Signature mismatch.', array( 'status' => 401 ) );
$retry_status  = new WP_Error( 'rw_wp_request_failed', 'The website could not be reached.', array( 'status' => 502 ) );
rw_test( 'a rejected status poll stops instead of waiting out the timeout', RW_Connection_Orchestrator::status_failure_is_terminal( $denied_status ) && ! RW_Connection_Orchestrator::status_failure_is_terminal( $retry_status ) );
rw_test( 'a writable plugins directory uses the direct filesystem', 'direct' === RW_Component_Installer::direct_filesystem_method( 'ftpext' ) );

$existing_link = RW_WordPress_Client::rest_error_from_http(
	409,
	array(
		'code'    => 'rw_existing_maintenance_connection',
		'message' => 'This website already has an existing maintenance connection.',
	)
);
$generic_reject = RW_WordPress_Client::rest_error_from_http( 500, array( 'code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed.' ) );
rw_test(
	'bootstrap keeps the existing-connection error',
	is_wp_error( $existing_link )
		&& 'rw_existing_maintenance_connection' === $existing_link->get_error_code()
		&& 'This website already has an existing maintenance connection.' === $existing_link->get_error_message()
		&& 'rw_wp_request_rejected' === $generic_reject->get_error_code()
		&& false === strpos( $generic_reject->get_error_message(), 'not allowed' )
);
rw_test(
	'an existing connection is action required',
	false !== strpos( $orchestrator_src, "'rw_existing_maintenance_connection' === \$bootstrap->get_error_code() ? 'existing_connection' : 'bootstrap_failed'" )
		&& false !== strpos( $orchestrator_src, "'existing_connection' === \$code ? RW_Connection_State::ACTION_REQUIRED : RW_Connection_State::FAILED" )
);
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-mainwp-child-adapter.php';
rw_test( 'takeover stays off unless it is explicit', ! RW_Connection_Orchestrator::is_explicit_takeover( null ) && ! RW_Connection_Orchestrator::is_explicit_takeover( false ) && ! RW_Connection_Orchestrator::is_explicit_takeover( 'yes' ) && ! RW_Connection_Orchestrator::is_explicit_takeover( '0' ) && RW_Connection_Orchestrator::is_explicit_takeover( '1' ) && RW_Connection_Orchestrator::is_explicit_takeover( true ) && RW_MainWP_Child_Adapter::is_explicit_takeover( 'true' ) );
update_option( RW_Connection_Orchestrator::takeover_option_key( 44 ), '1', false );
rw_test( 'the portal authorises takeover only when the attempt was marked', true === RW_Connection_Orchestrator::consume_takeover( 44, true ) && false === RW_Connection_Orchestrator::consume_takeover( 44, true ) && false === RW_Connection_Orchestrator::consume_takeover( 45, '1' ) );

require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector.php';
update_option( 'mainwp_child_pubkey', 'dashboard-key', false );
update_option( 'mainwp_child_siteid', 12, false );
rw_test( 'a stored child public key is an existing link', RW_MainWP_Child_Adapter::has_existing_link() );
rw_test( 'takeover re-keys the child to the ReactWoo dashboard', 'https://maint.reactwoo.com' === RW_MainWP_Child_Adapter::rekey_to_reactwoo() && ! RW_MainWP_Child_Adapter::has_existing_link() && false === get_option( 'mainwp_child_siteid' ) && 'https://maint.reactwoo.com' === get_option( 'mainwp_child_server' ) );
update_option( 'rw_connector_site_id', 9, false );
update_option( 'rw_connector_site_secret', 'leftover-secret', false );
update_option( 'rw_connector_connected_at', '2026-01-01 00:00:00', false );
RW_Connector::clear_enrolment();
rw_test( 'a failed bootstrap clears the connector enrolment', false === get_option( 'rw_connector_site_id' ) && false === get_option( 'rw_connector_site_secret' ) && false === get_option( 'rw_connector_connected_at' ) );

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-balance.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-maintenance-plans.php';

$annual_start = '2026-01-16 09:00:00';
$first        = RW_Support_Balance::period_containing( $annual_start, '2026-01-16 09:00:00' );
$grant        = RW_Support_Balance::grant_rows( array(), 60, $first, '2026-01-16 09:00:00' );
rw_test( 'annual standard grants 60 for the first service period', 1 === count( $grant ) && 60 === $grant[0]['minutes_delta'] && '2026-01-16' === $first['start_date'] );
$again = RW_Support_Balance::grant_rows( $grant, 60, $first, '2026-01-20 09:00:00' );
rw_test( 'the same service period does not grant another 60', array() === $again );
rw_test( 'twelve months are not granted up front', 60 === RW_Support_Balance::included_remainder( $grant, '2026-01-20 09:00:00' ) );

$second = RW_Support_Balance::period_containing( $annual_start, '2026-02-16 09:00:00' );
$closed = RW_Support_Balance::grant_rows( $grant, 60, $second, '2026-02-16 09:00:00' );
$live   = array_merge( $grant, $closed );
rw_test( 'the next service month grants 60 and expires the unused allowance', 60 === RW_Support_Balance::included_remainder( $live, '2026-02-16 09:00:00' ) && 60 === RW_Support_Balance::current_grant( $live, '2026-02-16' ) );
$expiry = 0;
foreach ( $closed as $row ) {
	if ( 'expiry' === $row['credit_type'] ) {
		$expiry += $row['minutes_delta'];
	}
}
rw_test( 'unused included minutes are closed with an expiry row', -60 === $expiry );

$used = $grant;
$used[] = array(
	'minutes_delta' => -30,
	'credit_type'   => 'usage',
	'reference'     => 'included:2026-01-16',
	'expires_at'    => $first['end'],
);
$pack = RW_Support_Balance::purchase_row( array(), 88, 300, 365, '2026-01-16 09:00:00' );
$with_pack = array_merge( $used, array( $pack ) );
rw_test( 'using 30 included minutes leaves the purchased pack untouched', 30 === RW_Support_Balance::included_remainder( $with_pack, '2026-01-20 09:00:00' ) && 300 === RW_Support_Balance::purchased_remainder( $with_pack, '2026-01-20 09:00:00' ) );
rw_test( 'the same order item does not credit twice', null === RW_Support_Balance::purchase_row( array( $pack ), 88, 300, 365, '2026-01-17 09:00:00' ) );
rw_test( 'essential has no included minutes to show', 0 === RW_Support_Balance::current_grant( RW_Support_Balance::grant_rows( array(), 0, $first, '2026-01-16 09:00:00' ), '2026-01-16' ) );
rw_test( 'support credit reads minutes meta and ignores a title', false === RW_Support_Balance::should_credit( array( '_rw_product_type' => 'simple' ), true ) && true === RW_Support_Balance::should_credit( array( '_rw_product_type' => 'support_credit', '_rw_support_minutes' => '60', '_rw_requires_maintenance' => 'yes' ), true ) && false === RW_Support_Balance::should_credit( array( '_rw_product_type' => 'support_credit', '_rw_support_minutes' => '60', '_rw_requires_maintenance' => 'yes' ), false ) );

$month_end = RW_Support_Balance::period_containing( '2026-01-31 08:00:00', '2026-02-15 08:00:00' );
rw_test( 'a 31st start stays inside the shortened next month', is_array( $month_end ) && '2026-01-31' === $month_end['start_date'] && '2026-02-28 08:00:00' === $month_end['end'] );

$portal_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-credits.php' );
rw_test( 'support credits are not sent to the licence server', false === strpos( $portal_src, '/v1/licenses/provision' ) && false === strpos( $portal_src, 'GBP' ) && false === strpos( $portal_src, '£' ) );
$plans = RW_Maintenance_Plans::resolve( 'business' );
rw_test( 'third-party premium list is empty', array() === $plans['third_party_premium_entitlements'] && array() === $plans['reactwoo_product_entitlements'] );

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} failed\n" );
	exit( 1 );
}

echo "\nAll maintenance connection tests passed\n";
