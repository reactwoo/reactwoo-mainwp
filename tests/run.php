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
	public $status = '';
	public $end    = 0;
	public function get_status() {
		return $this->status;
	}
	public function get_time( $type ) {
		return ( 'end' === $type ) ? (int) $this->end : 0;
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
	$GLOBALS['rw_transients'][ $key ] = $value;
	$GLOBALS['rw_transient_ttl'][ $key ] = (int) $ttl;
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
$deleted_mainwp = RW_Maint_MainWP::resolve_reenroll_target( 12, null, 'https://customer.example', 0 );
rw_test( 'a stale row with a deleted MainWP site is created again', 0 === $deleted_mainwp['reuse_id'] && 0 === $deleted_mainwp['blocked_id'] && ! empty( $deleted_mainwp['create'] ) && ! RW_Maint_MainWP::may_operate_on( 12, 'https://customer.example', null ) );
$mismatched_mainwp = RW_Maint_MainWP::resolve_reenroll_target( 12, 'https://aplenty.co.uk', 'https://customer.example', 0 );
rw_test( 'a URL mismatch does not reuse or change the other MainWP site', 0 === $mismatched_mainwp['reuse_id'] && 12 === $mismatched_mainwp['blocked_id'] && ! empty( $mismatched_mainwp['create'] ) && ! RW_Maint_MainWP::may_operate_on( 12, 'https://customer.example', 'https://aplenty.co.uk' ) );
$null_mainwp = RW_Maint_MainWP::resolve_reenroll_target( 0, null, 'https://customer.example', 0 );
rw_test( 'a null MainWP id creates a site', 0 === $null_mainwp['reuse_id'] && 0 === $null_mainwp['blocked_id'] && ! empty( $null_mainwp['create'] ) );
$valid_mainwp = RW_Maint_MainWP::resolve_reenroll_target( 12, 'https://customer.example/', 'https://www.customer.example', 0 );
rw_test( 'a valid existing MainWP row is reused for check and sync', 12 === $valid_mainwp['reuse_id'] && empty( $valid_mainwp['create'] ) && 0 === $valid_mainwp['blocked_id'] && RW_Maint_MainWP::may_operate_on( 12, 'https://www.customer.example', 'https://customer.example/' ) );

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
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-account-access.php';
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
$connected_site = (object) array(
	'status'                     => 'connected',
	'connection_state'           => 'connected',
	'connection_error_code'      => '',
	'connection_error_message'   => '',
);
$reconnect_fail = RW_Connection_Orchestrator::outcome_for_attempt( $connected_site, RW_Connection_State::FAILED, 'bootstrap_failed', 'ReactWoo could not finish the secure connection.' );
$reconnect_foreign = RW_Connection_Orchestrator::outcome_for_attempt( $connected_site, RW_Connection_State::ACTION_REQUIRED, 'existing_connection', 'This website already has an existing maintenance connection.' );
$first_attempt = (object) array(
	'status'           => 'pending',
	'connection_state' => 'bootstrapping',
);
$first_fail = RW_Connection_Orchestrator::outcome_for_attempt( $first_attempt, RW_Connection_State::FAILED, 'bootstrap_failed', 'ReactWoo could not finish the secure connection.' );
rw_test(
	'a failed reconnect keeps the record connected',
	! $reconnect_fail['downgraded']
		&& 'connected' === $reconnect_fail['status']
		&& 'connected' === $reconnect_fail['connection_state']
		&& '' === $reconnect_fail['connection_error_code']
		&& 'failed' === $reconnect_fail['attempt_status']
		&& 'bootstrap_failed' === $reconnect_fail['attempt_error_code']
		&& false !== strpos( $reconnect_fail['attempt_error_message'], 'could not finish' )
		&& 'connected' === $reconnect_foreign['status']
		&& 'connected' === $reconnect_foreign['connection_state']
		&& '' === $reconnect_foreign['connection_error_code']
		&& 'action_required' === $reconnect_foreign['attempt_status']
		&& 'existing_connection' === $reconnect_foreign['attempt_error_code']
		&& $first_fail['downgraded']
		&& 'failed' === $first_fail['connection_state']
		&& 'bootstrap_failed' === $first_fail['connection_error_code']
);
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-mainwp-child-adapter.php';
rw_test( 'takeover stays off unless it is explicit', ! RW_Connection_Orchestrator::is_explicit_takeover( null ) && ! RW_Connection_Orchestrator::is_explicit_takeover( false ) && ! RW_Connection_Orchestrator::is_explicit_takeover( 'yes' ) && ! RW_Connection_Orchestrator::is_explicit_takeover( '0' ) && RW_Connection_Orchestrator::is_explicit_takeover( '1' ) && RW_Connection_Orchestrator::is_explicit_takeover( true ) && RW_MainWP_Child_Adapter::is_explicit_takeover( 'true' ) );
update_option( RW_Connection_Orchestrator::takeover_option_key( 44 ), '1', false );
rw_test( 'the portal authorises takeover only when the attempt was marked', true === RW_Connection_Orchestrator::consume_takeover( 44, true ) && false === RW_Connection_Orchestrator::consume_takeover( 44, true ) && false === RW_Connection_Orchestrator::consume_takeover( 45, '1' ) );

require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector.php';
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		$active = isset( $GLOBALS['rw_active_plugins'] ) ? $GLOBALS['rw_active_plugins'] : array();
		return in_array( (string) $plugin, (array) $active, true );
	}
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/rw-child-plugins-' . getmypid() );
}
$child_plugin_file = WP_PLUGIN_DIR . '/mainwp-child/mainwp-child.php';
$GLOBALS['rw_active_plugins'] = array( 'mainwp-child/mainwp-child.php' );
update_option( 'mainwp_child_pubkey', 'dashboard-key', false );
update_option( 'mainwp_child_siteid', 12, false );
delete_option( 'rw_connector_child_link_backup' );
$absent_linked  = RW_MainWP_Child_Adapter::has_existing_link();
$absent_cleared = RW_MainWP_Child_Adapter::backup_and_clear_inactive_link();
$absent_backup  = get_option( 'rw_connector_child_link_backup' );
rw_test(
	'leftover options with Child absent are not a live link',
	! is_file( $child_plugin_file )
		&& ! $absent_linked
		&& $absent_cleared
		&& false === get_option( 'mainwp_child_pubkey' )
		&& false === get_option( 'mainwp_child_siteid' )
		&& is_array( $absent_backup )
		&& 'dashboard-key' === $absent_backup['options']['mainwp_child_pubkey']
);
if ( ! is_dir( dirname( $child_plugin_file ) ) ) {
	mkdir( dirname( $child_plugin_file ), 0777, true );
}
file_put_contents( $child_plugin_file, "<?php\n" );
$GLOBALS['rw_active_plugins'] = array();
update_option( 'mainwp_child_pubkey', 'dashboard-key', false );
update_option( 'mainwp_child_siteid', 12, false );
delete_option( 'rw_connector_child_link_backup' );
$inactive_linked  = RW_MainWP_Child_Adapter::has_existing_link();
$inactive_cleared = RW_MainWP_Child_Adapter::backup_and_clear_inactive_link();
rw_test(
	'leftover options with Child inactive are not a live link',
	RW_MainWP_Child_Adapter::is_installed()
		&& ! RW_MainWP_Child_Adapter::is_active()
		&& ! $inactive_linked
		&& $inactive_cleared
		&& false === get_option( 'mainwp_child_pubkey' )
		&& 'dashboard-key' === get_option( 'rw_connector_child_link_backup' )['options']['mainwp_child_pubkey']
);
$GLOBALS['rw_active_plugins'] = array( 'mainwp-child/mainwp-child.php' );
update_option( 'mainwp_child_pubkey', 'dashboard-key', false );
update_option( 'mainwp_child_siteid', 12, false );
rw_test(
	'an active child with a public key still blocks',
	RW_MainWP_Child_Adapter::is_installed()
		&& RW_MainWP_Child_Adapter::is_active()
		&& RW_MainWP_Child_Adapter::has_existing_link()
		&& ! RW_MainWP_Child_Adapter::backup_and_clear_inactive_link()
		&& 'dashboard-key' === get_option( 'mainwp_child_pubkey' )
		&& 12 === (int) get_option( 'mainwp_child_siteid' )
);
delete_option( 'rw_connector_mainwp_site_id' );
delete_option( 'mainwp_child_server' );
update_option( 'mainwp_child_server', 'https://maint.reactwoo.com/', false );
$own_by_server = RW_MainWP_Child_Adapter::is_own_dashboard_link() && ! RW_MainWP_Child_Adapter::blocks_bootstrap();
delete_option( 'mainwp_child_server' );
update_option( 'rw_connector_mainwp_site_id', 12, false );
$own_by_id = RW_MainWP_Child_Adapter::is_own_dashboard_link() && ! RW_MainWP_Child_Adapter::has_existing_link() && ! RW_MainWP_Child_Adapter::blocks_bootstrap();
$bootstrap_src = file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-bootstrap.php' );
rw_test(
	'own-dashboard re-enrolment proceeds',
	$own_by_server
		&& $own_by_id
		&& false !== strpos( $bootstrap_src, 'is_own_dashboard_link' )
		&& false !== strpos( $bootstrap_src, 'blocks_bootstrap' )
);
delete_option( 'rw_connector_mainwp_site_id' );
update_option( 'mainwp_child_siteid', 12, false );
update_option( 'mainwp_child_server', 'https://maint.reactwoo.com/wp-admin/', false );
$stored_before_admin_path = (int) get_option( 'rw_connector_mainwp_site_id', 0 );
$wp_admin_server = RW_MainWP_Child_Adapter::is_own_dashboard_link() && ! RW_MainWP_Child_Adapter::blocks_bootstrap();
rw_test(
	'a Child server stored with /wp-admin/ is our dashboard',
	0 === $stored_before_admin_path
		&& $wp_admin_server
		&& 12 === (int) get_option( 'rw_connector_mainwp_site_id' )
);
delete_option( 'rw_connector_mainwp_site_id' );
update_option( 'mainwp_child_server', 'HTTP://Maint.ReactWoo.com/wp-admin/', false );
$missing_stored_id = 0 === (int) get_option( 'rw_connector_mainwp_site_id', 0 );
$reenrol_without_stored_id = $missing_stored_id && RW_MainWP_Child_Adapter::is_own_dashboard_link() && ! RW_MainWP_Child_Adapter::blocks_bootstrap();
rw_test(
	'a site missing the stored MainWP id still re-enrols from the dashboard host',
	$reenrol_without_stored_id && 12 === RW_MainWP_Child_Adapter::stored_mainwp_site_id()
);
update_option( 'mainwp_child_server', 'https://other-dashboard.example', false );
update_option( 'mainwp_child_siteid', 12, false );
update_option( 'rw_connector_mainwp_site_id', 99, false );
rw_test(
	'a foreign dashboard is blocked',
	! RW_MainWP_Child_Adapter::is_own_dashboard_link()
		&& RW_MainWP_Child_Adapter::has_existing_link()
		&& RW_MainWP_Child_Adapter::blocks_bootstrap()
		&& 'dashboard-key' === get_option( 'mainwp_child_pubkey' )
);
delete_option( 'mainwp_child_server' );
delete_option( 'rw_connector_mainwp_site_id' );
update_option( 'mainwp_child_siteid', 12, false );
rw_test( 'takeover re-keys the child to the ReactWoo dashboard', 'https://maint.reactwoo.com' === RW_MainWP_Child_Adapter::rekey_to_reactwoo() && ! RW_MainWP_Child_Adapter::has_existing_link() && false === get_option( 'mainwp_child_siteid' ) && 'https://maint.reactwoo.com' === get_option( 'mainwp_child_server' ) );
$installer_src = file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-component-installer.php' );
rw_test( 'a fresh child install clears leftover link options first', false !== strpos( $installer_src, 'backup_and_clear_inactive_link' ) && false !== strpos( $installer_src, 'plugin_file_exists' ) );
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

$portal_main = file_get_contents( __DIR__ . '/../reactwoo-support-portal/reactwoo-support-portal.php' );
$version_match = array();
preg_match( "/define\\( 'RW_PORTAL_VERSION', '([^']+)' \\)/", $portal_main, $version_match );
if ( ! defined( 'RW_PORTAL_VERSION' ) ) {
	define( 'RW_PORTAL_VERSION', $version_match[1] );
}
if ( ! defined( 'RW_PORTAL_URL' ) ) {
	define( 'RW_PORTAL_URL', 'https://portal.example/wp-content/plugins/reactwoo-support-portal/' );
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $endpoint = false ) {
		$current = isset( $GLOBALS['rw_wc_endpoint'] ) ? $GLOBALS['rw_wc_endpoint'] : false;
		if ( false === $endpoint ) {
			return false !== $current && '' !== $current;
		}
		return (string) $current === (string) $endpoint;
	}
}
if ( ! function_exists( 'is_account_page' ) ) {
	function is_account_page() {
		return ! empty( $GLOBALS['rw_is_account_page'] );
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $var, $default = '' ) {
		if ( isset( $GLOBALS['rw_query_vars'] ) && is_array( $GLOBALS['rw_query_vars'] ) && array_key_exists( $var, $GLOBALS['rw_query_vars'] ) ) {
			return $GLOBALS['rw_query_vars'][ $var ];
		}
		return $default;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		unset( $deps, $media );
		if ( isset( $GLOBALS['rw_enqueued_styles'][ $handle ] ) ) {
			return;
		}
		$GLOBALS['rw_enqueued_styles'][ $handle ] = array(
			'src'  => $src,
			'ver'  => $ver,
			'done' => false,
		);
	}
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		if ( empty( $GLOBALS['rw_enqueued_styles'][ $handle ] ) || ! is_array( $GLOBALS['rw_enqueued_styles'][ $handle ] ) ) {
			return false;
		}
		if ( 'done' === $status ) {
			return ! empty( $GLOBALS['rw_enqueued_styles'][ $handle ]['done'] );
		}
		return true;
	}
}
if ( ! function_exists( 'wp_print_styles' ) ) {
	function wp_print_styles( $handles = false ) {
		foreach ( (array) $handles as $handle ) {
			if ( isset( $GLOBALS['rw_enqueued_styles'][ $handle ] ) ) {
				$GLOBALS['rw_enqueued_styles'][ $handle ]['done'] = true;
				$GLOBALS['rw_printed_styles'][] = $handle;
			}
		}
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['rw_enqueued_scripts'][ $handle ] = array(
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		);
	}
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		if ( 'enqueued' === $status ) {
			return isset( $GLOBALS['rw_enqueued_scripts'][ $handle ] );
		}
		if ( 'done' === $status ) {
			return ! empty( $GLOBALS['rw_printed_scripts'][ $handle ] );
		}
		return false;
	}
}
if ( ! function_exists( 'wp_print_scripts' ) ) {
	function wp_print_scripts( $handles = false ) {
		if ( is_string( $handles ) ) {
			$GLOBALS['rw_printed_scripts'][ $handles ] = true;
		}
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['rw_localized_scripts'][ $handle ] = array( 'name' => $name, 'data' => $data );
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) {
		return 'nonce-' . $action;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return isset( $GLOBALS['rw_did_action'][ $hook ] ) ? (int) $GLOBALS['rw_did_action'][ $hook ] : 0;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return isset( $GLOBALS['rw_current_user_id'] ) ? (int) $GLOBALS['rw_current_user_id'] : 0;
	}
}

$account_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-account.php' );
$query_vars  = RW_Portal_Account::add_query_var( array( 'orders' => 'orders' ) );
rw_test(
	'maintenance is a WooCommerce account query var',
	false !== strpos( $account_src, "'woocommerce_get_query_vars'" )
		&& isset( $query_vars['maintenance'] )
		&& 'maintenance' === $query_vars['maintenance']
		&& 'orders' === $query_vars['orders']
);

$GLOBALS['rw_enqueued_styles'] = array();
$GLOBALS['rw_printed_styles']  = array();
$GLOBALS['rw_enqueued_scripts'] = array();
$GLOBALS['rw_printed_scripts']  = array();
$GLOBALS['rw_localized_scripts'] = array();
$GLOBALS['rw_wc_endpoint']     = 'maintenance';
$GLOBALS['rw_is_account_page'] = false;
$GLOBALS['rw_query_vars']      = array();
$GLOBALS['rw_did_action']      = array();
RW_Portal_Account::enqueue_assets();
$maintenance_style = isset( $GLOBALS['rw_enqueued_styles']['rw-portal-maintenance'] ) ? $GLOBALS['rw_enqueued_styles']['rw-portal-maintenance'] : null;
rw_test(
	'maintenance endpoint enqueues the account stylesheet',
	is_array( $maintenance_style )
		&& false !== strpos( (string) $maintenance_style['src'], 'assets/css/account-maintenance.css' )
		&& (string) filemtime( __DIR__ . '/../reactwoo-support-portal/assets/css/account-maintenance.css' ) === (string) $maintenance_style['ver']
		&& '0.5.9' === RW_PORTAL_VERSION
		&& false !== strpos( $portal_main, 'Version: ' . RW_PORTAL_VERSION )
);

$maintenance_theme = isset( $GLOBALS['rw_enqueued_scripts']['rw-portal-maintenance-theme'] ) ? $GLOBALS['rw_enqueued_scripts']['rw-portal-maintenance-theme'] : null;
rw_test(
	'maintenance endpoint enqueues the theme toggle script',
	is_array( $maintenance_theme )
		&& false !== strpos( (string) $maintenance_theme['src'], 'assets/js/account-maintenance-theme.js' )
);


$GLOBALS['rw_enqueued_styles'] = array();
$GLOBALS['rw_wc_endpoint']     = '';
$GLOBALS['rw_is_account_page'] = true;
$GLOBALS['rw_query_vars']      = array( 'maintenance' => '' );
RW_Portal_Account::enqueue_assets();
rw_test(
	'an account page with the maintenance query var still enqueues the stylesheet',
	isset( $GLOBALS['rw_enqueued_styles']['rw-portal-maintenance'] )
);

$GLOBALS['rw_enqueued_styles'] = array();
$GLOBALS['rw_is_account_page'] = true;
$GLOBALS['rw_query_vars']      = array();
RW_Portal_Account::enqueue_assets();
rw_test( 'other account screens do not enqueue the maintenance stylesheet', ! isset( $GLOBALS['rw_enqueued_styles']['rw-portal-maintenance'] ) );

$GLOBALS['rw_enqueued_styles'] = array();
$GLOBALS['rw_printed_styles']  = array();
$GLOBALS['rw_wc_endpoint']     = '';
$GLOBALS['rw_is_account_page'] = false;
$GLOBALS['rw_did_action']      = array( 'wp_head' => 1 );
ob_start();
RW_Portal_Account::render();
$maintenance_markup = ob_get_clean();
rw_test(
	'rendering the maintenance endpoint enqueues the stylesheet',
	isset( $GLOBALS['rw_enqueued_styles']['rw-portal-maintenance'] )
		&& in_array( 'rw-portal-maintenance', (array) $GLOBALS['rw_printed_styles'], true )
		&& false !== strpos( $maintenance_markup, 'Please log in' )
);

$ordered_blocks = RW_Portal_Account::sort_subscription_blocks(
	array(
		array( 'view' => array( 'state' => 'attention' ), 'id' => 'attention' ),
		array( 'view' => array( 'state' => 'connected' ), 'id' => 'connected-first' ),
		array( 'view' => array( 'state' => 'setup' ), 'id' => 'setup' ),
		array( 'view' => array( 'state' => 'connected' ), 'id' => 'connected-second' ),
	)
);
$ordered_ids = array();
foreach ( $ordered_blocks as $ordered_block ) {
	$ordered_ids[] = $ordered_block['id'];
}
rw_test( 'connected subscriptions are shown first', array( 'connected-first', 'connected-second', 'attention', 'setup' ) === $ordered_ids );

$attention_template = file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/account-attention.php' );
rw_test(
	'a subscription that needs attention collapses the connection steps',
	false !== strpos( $attention_template, '<details class="rw-maint-finish">' )
		&& false !== strpos( $attention_template, 'Finish connecting' )
		&& false !== strpos( $attention_template, 'rw-maint-finish-steps' )
		&& false === strpos( $attention_template, '<details class="rw-maint-finish" open' )
);

$maintenance_css = file_get_contents( __DIR__ . '/../reactwoo-support-portal/assets/css/account-maintenance.css' );
rw_test(
	'Elementor account colours are overridden for maintenance headings and buttons',
	false !== strpos( $maintenance_css, "Elementor's My Account widget overrides link and heading colours via CSS vars." )
		&& false !== strpos( $maintenance_css, '--typography-section-titles-color: currentColor' )
		&& false !== strpos( $maintenance_css, '.rw-maint .rw-maint-button { --links-normal-color:#fff; --links-hover-color:#fff; color:#fff; }' )
		&& false !== strpos( $maintenance_css, '.rw-maint-onboarding h2' )
		&& false !== strpos( $maintenance_css, '.rw-maint-onboarding .rw-maint-button' )
		&& false !== strpos( $maintenance_css, '.rw-maint-onboarding .rw-maint-hero .rw-maint-button-secondary' )
		&& false !== strpos( $maintenance_css, '--general-text-color: #fff' )
);
rw_test(
	'plan blocks are separate cards with space between them',
	false !== strpos( $maintenance_css, '.rw-maint-plan-block {' )
		&& false !== strpos( $maintenance_css, 'gap: 28px' )
		&& false !== strpos( $maintenance_css, 'border-radius: 20px' )
);

require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-site-snapshot.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-maintenance-status.php';

$scoped_site = (object) array(
	'portal_site_id'  => 7,
	'subscription_id' => 3,
	'site_url'        => 'https://shop.example',
	'mainwp_site_id'  => 12,
);
$other_site = (object) array(
	'portal_site_id'  => 9,
	'subscription_id' => 4,
	'site_url'        => 'https://other.example',
	'mainwp_site_id'  => 99,
);

rw_test( 'a site belongs only to its own subscription', RW_Maint_Site_Snapshot::site_is_in_scope( $scoped_site, 3 ) && ! RW_Maint_Site_Snapshot::site_is_in_scope( $scoped_site, 4 ) && ! RW_Maint_Site_Snapshot::site_is_in_scope( $scoped_site, 0 ) && ! RW_Maint_Site_Snapshot::site_is_in_scope( null, 3 ) );

$denied = RW_Maint_Site_Snapshot::guard(
	$other_site,
	3,
	array(
		'website' => array(
			'url'     => 'https://other.example',
			'privkey' => 'SECRETKEY',
		),
	)
);
$denied_json = wp_json_encode( $denied );
rw_test(
	'another subscription cannot read the site',
	is_wp_error( $denied )
		&& 'rw_maint_not_found' === $denied->get_error_code()
		&& 404 === (int) $denied->get_error_data()['status']
		&& false === stripos( (string) $denied_json, 'other.example' )
		&& false === strpos( (string) $denied_json, 'SECRETKEY' )
);

rw_test( 'backup tasks must name this site', RW_Maint_Site_Snapshot::backup_task_includes_site( serialize( array( 12, 13 ) ), 12 ) );
rw_test( 'backup tasks for another site stay out', ! RW_Maint_Site_Snapshot::backup_task_includes_site( serialize( array( 99 ) ), 12 ) && ! RW_Maint_Site_Snapshot::backup_task_includes_site( 'all', 12 ) );

$snapshot_now = 1700604900;
$history      = array();
for ( $backup_index = 1; $backup_index <= 7; $backup_index++ ) {
	$history[] = array(
		'time'   => 1700000000 + ( $backup_index * 86400 ),
		'status' => ( 0 === $backup_index % 2 ) ? 'failed' : 'success',
	);
}
$activity_base = 1700000000;
$activity_logs = array(
	array( 'created' => $activity_base, 'action' => 'plugin updated', 'context' => 'plugins', 'item' => wp_json_encode( array( 'name' => 'WooCommerce', 'from' => '9.0.0', 'to' => '9.1.2' ) ) ),
	array( 'created' => $activity_base + 20000, 'action' => 'backup completed', 'context' => 'backups', 'item' => 'Daily backup' ),
	array( 'created' => $activity_base + 40000, 'action' => 'security scan finished', 'context' => 'security', 'item' => 'Scan' ),
	array( 'created' => $activity_base + 60000, 'action' => 'database optimised', 'context' => 'maintenance', 'item' => 'Database tidy' ),
	array( 'created' => $activity_base + 80000, 'action' => 'theme upgraded', 'context' => 'themes', 'item' => 'Storefront' ),
	array( 'created' => $activity_base + 100000, 'action' => 'plugin updated', 'context' => 'plugins', 'item' => 'Akismet' ),
	array( 'created' => $activity_base + 100500, 'action' => 'backup completed', 'context' => 'backups', 'item' => 'Nightly backup' ),
	array( 'created' => $activity_base + 100200, 'action' => 'plugin updated', 'context' => 'plugins', 'item' => 'MainWP Child' ),
);
$full = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'website' => array(
			'url'              => 'https://other.example',
			'privkey'          => 'SECRETKEY',
			'http_pass'        => 'password',
			'plugin_upgrades'  => array(
				'woocommerce/woocommerce.php' => array(
					'Name'    => 'WooCommerce',
					'Version' => '9.0.0',
					'update'  => array( 'new_version' => '9.1.2' ),
				),
				'mainwp-child/mainwp-child.php' => array(
					'Name'        => 'MainWP Child',
					'Version'     => '6.4',
					'new_version' => '6.5',
				),
				'mainwp-child-reports/mainwp-child-reports.php' => array(
					'Name'        => 'MainWP Child Reports',
					'Version'     => '2.3.1',
					'new_version' => '2.4.0',
				),
				'branded/branded.php' => array(
					'Name'        => 'ReactWoo Maintenance',
					'Version'     => '1.0.0',
					'new_version' => '1.1.0',
				),
			),
			'theme_upgrades' => array(
				'storefront' => array(
					'Name'        => 'Storefront',
					'Version'     => '4.5.0',
					'new_version' => '4.6.0',
				),
			),
			'securityIssues' => array(
				'php_outdated' => 'PHP is old',
				'ssl'          => 'SSL',
			),
			'pages'          => array(
				array( 'title' => 'Home', 'status' => 'publish', 'dts' => 1710000000 ),
				array( 'title' => 'Draft page', 'status' => 'draft', 'dts' => 1720000000 ),
			),
			'users'          => array(
				array( 'login' => 'shopper', 'roles' => 'customer', 'last_login' => 1700001000 ),
				array( 'login' => 'ghost', 'last_login' => 1900000000 ),
				array( 'login' => 'older', 'role' => 'administrator', 'last_login' => 1700000000 ),
				array( 'login' => 'jane', 'roles' => array( 'administrator' ), 'last_login' => 1700002000 ),
			),
		),
		'sync'    => array(
			'version'      => '5.4.0.1',
			'wpversion'    => '6.7.1',
			'dtsSync'      => 1700500000,
			'health_value' => 100,
			'phpversion'   => '8.2.12',
			'last_post_gmt' => 1600000000,
		),
		'options' => array(
			'wp_upgrades'          => array( 'current' => '6.7.1', 'new' => '6.7.2' ),
			'updraftplus_history'  => $history,
			'child_reports_login'  => array(
				'user_login' => 'sam',
				'time'       => 1800000000,
				'role'       => 'administrator',
			),
			'woocommerce'          => array(
				'products' => array(
					'published'    => 12,
					'draft'        => 2,
					'out_of_stock' => 1,
				),
			),
			'published_posts'      => 4,
			'recent_posts'         => array(
				array( 'title' => 'Older', 'status' => 'publish', 'dts' => 1700000000 ),
				array( 'title' => 'Newer', 'status' => 'publish', 'dts' => 1700005000 ),
				array( 'title' => 'Draft', 'status' => 'draft', 'dts' => 1700009000 ),
			),
			'next_maintenance'     => $snapshot_now + 86400,
		),
		'monitors' => array(
			array( 'last_status' => 1, 'lasttime_check' => 1700600000, 'active' => 1, 'issub' => 0 ),
		),
		'logs'     => $activity_logs,
		'audit'    => array(
			array(
				'event_type' => 'maintenance_run',
				'message'    => wp_json_encode(
					array(
						'updates_applied' => false,
						'backup'          => true,
						'security_scan'   => true,
						'optimisation'    => false,
					)
				),
				'created_at' => gmdate( 'Y-m-d H:i:s', $activity_base + 120000 ),
			),
		),
		'backup_tasks' => array(
			array(
				'schedule'   => 'daily',
				'last_run'   => 1700000000 + ( 7 * 86400 ),
				'last'       => 1700000000 + ( 7 * 86400 ),
				'completed'  => 1,
			),
		),
	),
	$snapshot_now
);
$full_json = wp_json_encode( $full );
rw_test(
	'the snapshot is scoped to the enrolled site',
	7 === $full['portal_site_id']
		&& 3 === $full['subscription_id']
		&& 'https://shop.example' === $full['site_url']
		&& false === stripos( $full_json, 'other.example' )
		&& false === strpos( $full_json, 'SECRETKEY' )
		&& false === strpos( $full_json, 'password' )
		&& false === stripos( $full_json, 'mainwp' )
		&& false === strpos( $full_json, 'ReactWoo Maintenance' )
);
$pending_names = array();
foreach ( $full['plugins']['pending'] as $pending_plugin ) {
	$pending_names[] = $pending_plugin['name'];
}
rw_test(
	'maintenance plugins are hidden and customer plugins remain',
	1 === $full['plugins']['pending_count']
		&& array( 'WooCommerce' ) === $pending_names
		&& '9.0.0' === $full['plugins']['pending'][0]['current_version']
		&& '9.1.2' === $full['plugins']['pending'][0]['new_version']
		&& 1 === $full['themes']['pending_count']
		&& 'Storefront' === $full['themes']['pending'][0]['name']
);
rw_test(
	'core, php, health, security, sync and uptime come from the synced site',
	'6.7.1' === $full['core']['wordpress_version']
		&& true === $full['core']['update_available']
		&& '6.7.2' === $full['core']['new_version']
		&& '8.2.12' === $full['php_version']
		&& 'good' === $full['health']['status']
		&& 2 === $full['security']['issues_count']
		&& null !== $full['last_sync_at']
		&& true === $full['uptime']['monitored']
		&& 'up' === $full['uptime']['status']
);
$update_names = array();
foreach ( $full['recent_updates'] as $recent_update ) {
	$update_names[] = $recent_update['name'];
}
rw_test(
	'recent updates keep customer plugins and record versions',
	in_array( 'WooCommerce', $update_names, true )
		&& in_array( 'Akismet', $update_names, true )
		&& ! in_array( 'MainWP Child', $update_names, true )
);
$woo_update = null;
foreach ( $full['recent_updates'] as $recent_update ) {
	if ( 'WooCommerce' === $recent_update['name'] ) {
		$woo_update = $recent_update;
	}
}
rw_test( 'an applied plugin update keeps both versions', is_array( $woo_update ) && '9.0.0' === $woo_update['from_version'] && '9.1.2' === $woo_update['to_version'] && 'plugin' === $woo_update['type'] );

$real_micro = 1700000000000000;
$real_mainwp = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'website' => array(
			'plugins' => array(
				'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '9.1.2' ),
				'reactwoo-geo-core/reactwoo-geo-core.php' => array( 'Name' => 'ReactWoo Geo Core', 'Version' => '1.4.0' ),
			),
			'themes' => array(
				'storefront' => array( 'Name' => 'Storefront', 'Version' => '4.6.0' ),
			),
			'plugin_upgrades' => array(
				'woocommerce/woocommerce.php' => array(
					'Name'    => 'WooCommerce',
					'Version' => '9.1.2',
					'update'  => array( 'new_version' => '9.2.0' ),
				),
			),
			'theme_upgrades' => array(
				'storefront' => array(
					'Name'        => 'Storefront',
					'Version'     => '4.6.0',
					'new_version' => '4.6.1',
				),
			),
			'premium_upgrades' => array(),
			'securityIssues' => array(
				'php_outdated' => 'PHP is old',
			),
			'wp_upgrades' => array( 'current' => '7.1.4', 'new' => '7.1.5' ),
			'users' => array(
				array( 'login' => 'jane', 'role' => 'administrator' ),
			),
		),
		'sync' => array(
			'version'   => '5.4.0.6',
			'dtsSync'   => $real_micro,
			'health_value' => 72,
		),
		'options' => array(
			'plugins'          => '[]',
			'themes'           => '[]',
			'plugin_upgrades'  => '[]',
			'theme_upgrades'   => '[]',
			'premium_upgrades' => '[]',
			'securityIssues'   => '[]',
			'wp_upgrades'      => '[]',
			'site_info'        => wp_json_encode(
				array(
					'wpversion'       => '7.1.4',
					'child_version'   => '5.4.0.6',
					'format_datetime' => array(
						'timezone_string' => 'Europe/Paris',
						'gmt_offset'      => 1,
					),
				)
			),
			'primary_lasttime_backup' => (string) $real_micro,
			'recent_posts' => array(
				array( 'title' => 'Hello', 'status' => 'publish', 'dts' => 1700000000 ),
			),
			'health_site_status' => array(
				'good'        => 12,
				'recommended' => 1,
				'critical'    => 2,
			),
		),
		'logs' => array(
			array(
				'created' => $real_micro,
				'action'  => 'updated',
				'context' => 'plugins',
				'item'    => wp_json_encode( array( 'name' => 'ReactWoo Geo Core', 'from' => '1.3.0', 'to' => '1.4.0' ) ),
			),
		),
	),
	1700604900
);
$real_names = array();
foreach ( $real_mainwp['plugins']['pending'] as $real_pending ) {
	$real_names[] = $real_pending['name'];
}
$geo = null;
foreach ( $real_mainwp['recent_updates'] as $real_update ) {
	if ( 'ReactWoo Geo Core' === $real_update['name'] ) {
		$geo = $real_update;
	}
}
$explicit_critical = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array(
			'health_site_status' => array(
				'status'   => 'critical',
				'critical' => 4,
			),
		),
	)
);
rw_test(
	'a real MainWP row uses the site WordPress version, lists, and microsecond times',
	'7.1.4' === $real_mainwp['core']['wordpress_version']
		&& true === $real_mainwp['core']['update_available']
		&& '7.1.5' === $real_mainwp['core']['new_version']
		&& array( 'WooCommerce' ) === $real_names
		&& 1 === $real_mainwp['themes']['pending_count']
		&& 'Storefront' === $real_mainwp['themes']['pending'][0]['name']
		&& 2 === $real_mainwp['inventory']['plugins']
		&& 1 === $real_mainwp['inventory']['themes']
		&& 1 === $real_mainwp['security']['issues_count']
		&& 'Europe/Paris' === $real_mainwp['timezone']
		&& gmdate( 'c', 1700000000 ) === $real_mainwp['last_sync_at']
		&& gmdate( 'c', 1700000000 ) === $real_mainwp['backups']['last']['time']
		&& is_array( $geo )
		&& 'plugin' === $geo['type']
		&& gmdate( 'c', 1700000000 ) === $geo['applied_at']
		&& 'needs_attention' === $real_mainwp['health']['status']
		&& 'critical' === $explicit_critical['health']['status']
		&& null === $real_mainwp['last_admin_login']['time']
		&& null === $real_mainwp['content']['posts']['published']
		&& gmdate( 'c', 1700000000 ) === $real_mainwp['content']['posts']['last_published_at']
		&& null === $real_mainwp['content']['products']['published']
);
$health_view = RW_Portal_Maintenance_Status::present( $real_mainwp );
$health_card = null;
foreach ( $health_view['cards'] as $health_row ) {
	if ( 'health' === $health_row['id'] ) {
		$health_card = $health_row;
	}
}
rw_test( 'a few critical checks are needs attention, not critical', is_array( $health_card ) && 'Needs attention' === $health_card['state_label'] );

$indexed_run = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'logs' => array(
			array(
				'created' => '17000000004521',
				'action'  => 'backup completed',
				'context' => 'backups',
				'item'    => 'Nightly',
			),
		),
	)
);
$absurd_when = RW_Portal_Maintenance_Status::format_when( '4378-05-06T15:06:40+00:00' );
$indexed_when = RW_Portal_Maintenance_Status::format_when( '17000000004521' );
$micro_when = RW_Portal_Maintenance_Status::format_when( '1700000000000000' );
rw_test(
	'maintenance run times ignore millisecond, microsecond and indexed stamps',
	is_array( $indexed_run['activity']['last_run'] )
		&& gmdate( 'c', 1700000000 ) === $indexed_run['activity']['last_run']['date']
		&& '' === $absurd_when
		&& false !== strpos( $indexed_when, '2023' )
		&& false !== strpos( $micro_when, '2023' )
		&& false === strpos( $indexed_when, '4377' )
		&& false === strpos( $micro_when, '5292' )
);
rw_test(
	'the header is needs attention when a status card is',
	RW_Portal_Maintenance_Status::needs_attention( array( 'flags' => array(), 'cards' => array( array( 'state' => 'good' ) ) ) ) === false
		&& RW_Portal_Maintenance_Status::needs_attention( array( 'flags' => array( 'Site health needs attention' ), 'cards' => array() ) )
		&& RW_Portal_Maintenance_Status::needs_attention( $health_view )
);

rw_test(
	'backup history keeps the newest five and the next daily run',
	5 === count( $full['backups']['recent'] )
		&& gmdate( 'c', 1700000000 + ( 7 * 86400 ) ) === $full['backups']['last']['time']
		&& 'success' === $full['backups']['last']['status']
		&& gmdate( 'c', 1700000000 + ( 7 * 86400 ) ) === $full['backups']['recent'][0]['time']
		&& gmdate( 'c', 1700000000 + ( 3 * 86400 ) ) === $full['backups']['recent'][4]['time']
		&& gmdate( 'c', 1700000000 + ( 8 * 86400 ) ) === $full['backups']['next_scheduled']
);
rw_test(
	'the latest administrator login is returned',
	'sam' === $full['last_admin_login']['username']
		&& gmdate( 'c', 1800000000 ) === $full['last_admin_login']['time']
		&& false === strpos( $full_json, 'shopper' )
		&& false === strpos( $full_json, 'ghost' )
);
rw_test(
	'catalogue counts and last published dates are kept apart from recent lists',
	12 === $full['content']['products']['published']
		&& 2 === $full['content']['products']['draft']
		&& 1 === $full['content']['products']['out_of_stock']
		&& 4 === $full['content']['posts']['published']
		&& gmdate( 'c', 1700005000 ) === $full['content']['posts']['last_published_at']
		&& 1 === $full['content']['pages']['published']
		&& gmdate( 'c', 1710000000 ) === $full['content']['pages']['last_published_at']
);
rw_test(
	'the last five maintenance runs keep what was done',
	5 === count( $full['activity']['recent'] )
		&& gmdate( 'c', $activity_base + 120000 ) === $full['activity']['last_run']['date']
		&& false === $full['activity']['last_run']['updates_applied']
		&& true === $full['activity']['last_run']['backup']
		&& true === $full['activity']['last_run']['security_scan']
		&& false === $full['activity']['last_run']['optimisation']
		&& gmdate( 'c', $snapshot_now + 86400 ) === $full['activity']['next_scheduled']
);

$missing = RW_Maint_Site_Snapshot::build( $scoped_site, array() );
rw_test(
	'missing synced fields stay empty instead of invented values',
	null === $missing['core']['wordpress_version']
		&& null === $missing['core']['update_available']
		&& null === $missing['plugins']['pending_count']
		&& null === $missing['themes']['pending_count']
		&& array() === $missing['recent_updates']
		&& null === $missing['php_version']
		&& null === $missing['health']['status']
		&& null === $missing['security']['issues_count']
		&& null === $missing['last_sync_at']
		&& null === $missing['backups']['last']['time']
		&& null === $missing['backups']['last']['status']
		&& null === $missing['backups']['next_scheduled']
		&& array() === $missing['backups']['recent']
		&& false === $missing['uptime']['monitored']
		&& null === $missing['uptime']['status']
		&& null === $missing['last_admin_login']['time']
		&& null === $missing['last_admin_login']['username']
		&& null === $missing['content']['products']['published']
		&& null === $missing['content']['products']['draft']
		&& null === $missing['content']['products']['out_of_stock']
		&& null === $missing['content']['posts']['published']
		&& null === $missing['content']['posts']['last_published_at']
		&& null === $missing['content']['pages']['published']
		&& null === $missing['content']['pages']['last_published_at']
		&& null === $missing['activity']['last_run']
		&& null === $missing['activity']['next_scheduled']
		&& array() === $missing['activity']['recent']
);

$recent_only = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array(
			'recent_posts' => array(
				array( 'title' => 'One', 'status' => 'publish', 'date' => '2026-01-02 00:00:00' ),
				array( 'title' => 'Two', 'status' => 'publish', 'date' => '2026-03-04 00:00:00' ),
			),
			'recent_pages' => array(
				array( 'title' => 'About', 'status' => 'publish', 'dts' => 1711000000 ),
			),
		),
	)
);
rw_test(
	'recent posts do not become a published count',
	null === $recent_only['content']['posts']['published']
		&& null === $recent_only['content']['pages']['published']
		&& gmdate( 'c', strtotime( '2026-03-04 00:00:00' ) ) === $recent_only['content']['posts']['last_published_at']
		&& gmdate( 'c', 1711000000 ) === $recent_only['content']['pages']['last_published_at']
);

$zeros = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array(
			'content' => array(
				'products' => array(
					'published'    => 0,
					'draft'        => 0,
					'out_of_stock' => 0,
				),
				'posts'    => array( 'published' => 0 ),
				'pages'    => array( 'published' => 0 ),
			),
			'securityIssues' => array(),
			'wp_upgrades'    => array(),
		),
		'sync' => array( 'version' => '6.7.1' ),
	)
);
rw_test(
	'a reported zero stays zero',
	0 === $zeros['content']['products']['published']
		&& 0 === $zeros['content']['products']['draft']
		&& 0 === $zeros['content']['products']['out_of_stock']
		&& 0 === $zeros['content']['posts']['published']
		&& 0 === $zeros['security']['issues_count']
		&& false === $zeros['core']['update_available']
);

$down = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'monitors' => array(
			array( 'last_status' => 0, 'active' => 1, 'issub' => 0, 'lasttime_check' => 1700000000 ),
		),
		'options'  => array(
			'primary_lasttime_backup' => 1690000000,
		),
	)
);
rw_test( 'a monitor can report the site offline', true === $down['uptime']['monitored'] && 'down' === $down['uptime']['status'] );
rw_test( 'a backup time without a result is not marked successful', gmdate( 'c', 1690000000 ) === $down['backups']['last']['time'] && null === $down['backups']['last']['status'] );

$unknown_monitor = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'monitors' => array(
			array( 'last_status' => 99, 'active' => 1, 'issub' => 0 ),
		),
	)
);
rw_test( 'an unchecked monitor does not invent uptime', true === $unknown_monitor['uptime']['monitored'] && null === $unknown_monitor['uptime']['status'] );

$blank_view = RW_Portal_Maintenance_Status::present( array() );
$blank_states = array();
foreach ( $blank_view['cards'] as $blank_card ) {
	$blank_states[] = $blank_card['state'];
}
rw_test(
	'missing data renders unknown status cards',
	array( 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown' ) === $blank_states
		&& ! $blank_view['login']['available']
		&& 'Not available yet' === $blank_view['content']['groups'][0]['rows'][0]['value']
		&& 'Not available yet' === $blank_view['content']['groups'][1]['rows'][1]['value']
		&& ! $blank_view['activity']['last']['available']
		&& ! $blank_view['activity']['next']['available']
		&& array() === $blank_view['activity']['recent']
);
$live_view = RW_Portal_Maintenance_Status::present( $full );
$live_ids  = array();
foreach ( $live_view['cards'] as $live_card ) {
	$live_ids[] = $live_card['id'];
}
rw_test(
	'status cards cover the customer maintenance areas',
	array( 'core', 'updates', 'security', 'health', 'backups', 'uptime' ) === $live_ids
		&& 'attention' === $live_view['cards'][0]['state']
		&& 'good' === $live_view['cards'][3]['state']
		&& 'good' === $live_view['cards'][5]['state']
		&& 'sam' === $live_view['login']['username']
		&& '12' === $live_view['content']['groups'][0]['rows'][0]['value']
		&& true === $live_view['activity']['last']['available']
		&& in_array( 'Backup', $live_view['activity']['last']['done'], true )
		&& in_array( 'Security scan', $live_view['activity']['last']['done'], true )
);

$cache_calls = 0;
$cache_site  = (object) array(
	'id'              => 7,
	'subscription_id' => 3,
);
$cache_fetch = function ( $site ) use ( &$cache_calls ) {
	$cache_calls++;
	return array(
		'portal_site_id'  => (int) $site->id,
		'subscription_id' => (int) $site->subscription_id,
		'core'            => array( 'wordpress_version' => '6.7.1' ),
	);
};
$GLOBALS['rw_transients']    = array();
$GLOBALS['rw_transient_ttl'] = array();
$cached_first  = RW_Portal_Maintenance_Status::for_site( $cache_site, $cache_fetch );
$cached_second = RW_Portal_Maintenance_Status::for_site( $cache_site, $cache_fetch );
$cache_key     = RW_Portal_Maintenance_Status::cache_key( $cache_site );
rw_test(
	'a successful snapshot is cached',
	1 === $cache_calls
		&& $cached_first === $cached_second
		&& 7 === $cached_second['portal_site_id']
		&& isset( $GLOBALS['rw_transient_ttl'][ $cache_key ] )
		&& $GLOBALS['rw_transient_ttl'][ $cache_key ] >= 600
		&& $GLOBALS['rw_transient_ttl'][ $cache_key ] <= 900
);
$other_cache = (object) array(
	'id'              => 8,
	'subscription_id' => 3,
);
$cached_other = RW_Portal_Maintenance_Status::for_site( $other_cache, $cache_fetch );
$moved_cache  = (object) array(
	'id'              => 7,
	'subscription_id' => 9,
);
$cached_moved = RW_Portal_Maintenance_Status::for_site( $moved_cache, $cache_fetch );
rw_test(
	'the cache is separate for each site and subscription',
	2 === $cache_calls || 3 === $cache_calls
);
rw_test( 'another site is fetched on its own', 8 === $cached_other['portal_site_id'] && 9 === $cached_moved['subscription_id'] && 3 === $cache_calls );

$failed_calls = 0;
$failed_site  = (object) array(
	'id'              => 11,
	'subscription_id' => 3,
);
$failed_fetch = function () use ( &$failed_calls ) {
	$failed_calls++;
	return new WP_Error( 'rw_maint_bad_response', 'Maintenance hub responded with an error.' );
};
RW_Portal_Maintenance_Status::for_site( $failed_site, $failed_fetch );
RW_Portal_Maintenance_Status::for_site( $failed_site, $failed_fetch );
rw_test( 'a failed status fetch is not cached', 2 === $failed_calls && ! get_transient( RW_Portal_Maintenance_Status::cache_key( $failed_site ) ) );

$status_controller = file_get_contents( __DIR__ . '/../reactwoo-maint-bridge/includes/rest/class-rw-maint-rest-controller.php' );
$status_pos        = strpos( $status_controller, 'function site_status' );
$scope_pos         = strpos( $status_controller, 'site_is_in_scope', $status_pos );
$collect_pos       = strpos( $status_controller, 'RW_Maint_MainWP_Status::collect', $status_pos );
rw_test(
	'the status endpoint is signed and checks the subscription before reading',
	false !== strpos( $status_controller, "'/status'" )
		&& false !== $status_pos
		&& false !== $scope_pos
		&& false !== $collect_pos
		&& $scope_pos < $collect_pos
);
$portal_client = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-maint-client.php' );
$fetch_pos     = strpos( $portal_client, 'function fetch_site_status' );
rw_test(
	'the portal requests status for the site subscription',
	false !== $fetch_pos
		&& false !== strpos( substr( $portal_client, $fetch_pos, 500 ), "'status'" )
		&& false !== strpos( substr( $portal_client, $fetch_pos, 500 ), 'subscription_id' )
);
rw_test(
	'status cards stack on small screens and use the account tokens',
	false !== strpos( $maintenance_css, '.rw-maint-status-grid' )
		&& false !== strpos( $maintenance_css, 'var(--rw-ink)' )
		&& false !== strpos( $maintenance_css, 'var(--rw-card)' )
		&& false !== strpos( $maintenance_css, '.rw-maint-status-grid,' )
		&& false !== strpos( $maintenance_css, 'grid-template-columns: 1fr' )
);

$premium_snapshot = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'website' => array(
			'plugins' => array(
				'seo-plugin/seo-plugin.php' => array(
					'Name'      => 'SEO Plugin',
					'slug'      => 'seo-plugin',
					'Version'   => '1.2.0',
					'UpdateURI' => 'https://updates.example.com/seo',
				),
				'akismet/akismet.php' => array(
					'Name'    => 'Akismet',
					'slug'    => 'akismet',
					'Version' => '5.0',
				),
			),
			'themes' => array(
				'storefront' => array(
					'Name'    => 'Storefront',
					'Version' => '4.5.0',
				),
			),
			'plugin_upgrades' => array(
				'akismet/akismet.php' => array(
					'Name'    => 'Akismet',
					'slug'    => 'akismet',
					'Version' => '5.0',
					'update'  => array(
						'new_version' => '5.1',
						'package'     => 'https://downloads.wordpress.org/plugin/akismet.5.1.zip',
					),
				),
				'elementor-pro/elementor-pro.php' => array(
					'Name'    => 'Elementor Pro',
					'slug'    => 'elementor-pro',
					'Version' => '3.20.0',
					'update'  => array(
						'new_version' => '3.21.0',
						'package'     => 'https://my.elementor.com/download/elementor-pro.zip',
					),
				),
			),
			'premium_upgrades' => array(
				'gravityforms/gravityforms.php' => array(
					'Name'        => 'Gravity Forms',
					'slug'        => 'gravityforms',
					'Version'     => '2.8.0',
					'new_version' => '2.8.1',
				),
				'mainwp-child/mainwp-child.php' => array(
					'Name'        => 'MainWP Child',
					'Version'     => '6.4',
					'new_version' => '6.5',
				),
			),
		),
		'options' => array(
			'elementor_pro_license_data' => array(
				'license' => 'valid',
				'expires' => '2027-01-01 00:00:00',
				'timeout' => 1700000000,
			),
			'_transient_gravityforms_license_status' => 'expired',
			'gravityforms_license_expires' => '2025-06-01 00:00:00',
			'seo_plugin_license_status' => 'invalid',
			'unrelated_plugin_license_status' => 'active',
			'akismet_license_status' => 'valid',
		),
	)
);
$premium_by_name = array();
foreach ( (array) $premium_snapshot['plugins']['premium'] as $premium_item ) {
	$premium_by_name[ $premium_item['name'] ] = $premium_item;
}
$akismet_pending = null;
foreach ( $premium_snapshot['plugins']['pending'] as $pending_plugin ) {
	if ( 'Akismet' === $pending_plugin['name'] ) {
		$akismet_pending = $pending_plugin;
	}
}
rw_test(
	'wordpress.org updates are not treated as premium',
	! isset( $premium_by_name['Akismet'] )
		&& is_array( $akismet_pending )
		&& 'wordpress.org' === $akismet_pending['update_source']
		&& true === $akismet_pending['update_available']
		&& ! isset( $premium_by_name['MainWP Child'] )
);
rw_test(
	'external update sources keep licence status and expiry',
	isset( $premium_by_name['Elementor Pro'] )
		&& 'my.elementor.com' === $premium_by_name['Elementor Pro']['update_source']
		&& true === $premium_by_name['Elementor Pro']['update_available']
		&& '3.21.0' === $premium_by_name['Elementor Pro']['new_version']
		&& 'active' === $premium_by_name['Elementor Pro']['licence_status']
		&& gmdate( 'c', strtotime( '2027-01-01 00:00:00' ) ) === $premium_by_name['Elementor Pro']['licence_expires_at']
		&& isset( $premium_by_name['Gravity Forms'] )
		&& 'external' === $premium_by_name['Gravity Forms']['update_source']
		&& true === $premium_by_name['Gravity Forms']['update_available']
		&& 'expired' === $premium_by_name['Gravity Forms']['licence_status']
		&& gmdate( 'c', strtotime( '2025-06-01 00:00:00' ) ) === $premium_by_name['Gravity Forms']['licence_expires_at']
		&& isset( $premium_by_name['SEO Plugin'] )
		&& 'updates.example.com' === $premium_by_name['SEO Plugin']['update_source']
		&& false === $premium_by_name['SEO Plugin']['update_available']
		&& 'unknown' === $premium_by_name['SEO Plugin']['licence_status']
		&& null === $premium_by_name['SEO Plugin']['licence_expires_at']
);
rw_test(
	'installed plugin and theme counts ignore the maintenance plugins',
	2 === $premium_snapshot['inventory']['plugins']
		&& 1 === $premium_snapshot['inventory']['themes']
);
$premium_view = RW_Portal_Maintenance_Status::present( $premium_snapshot );
$premium_names = array();
foreach ( $premium_view['premium']['items'] as $premium_row ) {
	$premium_names[] = $premium_row['name'];
}
rw_test(
	'premium licences render without guessing an unmatched plugin',
	in_array( 'Elementor Pro', $premium_names, true )
		&& in_array( 'Gravity Forms', $premium_names, true )
		&& ! in_array( 'Akismet', $premium_names, true )
		&& 'ink' === $premium_view['premium']['tone']
		&& 'ink' === RW_Portal_Maintenance_Status::card_tone( 'backups', 'good' )
		&& 'ink' === RW_Portal_Maintenance_Status::card_tone( 'health', 'good' )
		&& 'ink' === RW_Portal_Maintenance_Status::card_tone( 'updates', 'attention' )
		&& 'ink' === RW_Portal_Maintenance_Status::card_tone( 'security', 'attention' )
		&& 'paper' === RW_Portal_Maintenance_Status::card_tone( 'security', 'good' )
		&& 'paper' === RW_Portal_Maintenance_Status::card_tone( 'core', 'good' )
);
$licence_by_name = array();
foreach ( $premium_view['premium']['items'] as $licence_row ) {
	$licence_by_name[ $licence_row['name'] ] = $licence_row;
}
rw_test(
	'licence pills use a date and no clock time',
	'Active · 1 Jan 2027' === $licence_by_name['Elementor Pro']['licence']
		&& 'good' === $licence_by_name['Elementor Pro']['licence_tone']
		&& 'Update 3.21.0' === $licence_by_name['Elementor Pro']['update']
		&& 'warn' === $licence_by_name['Elementor Pro']['update_tone']
		&& 'Expired · 1 Jun 2025' === $licence_by_name['Gravity Forms']['licence']
		&& 'bad' === $licence_by_name['Gravity Forms']['licence_tone']
		&& 'Unknown' === $licence_by_name['SEO Plugin']['licence']
		&& false === strpos( $licence_by_name['Elementor Pro']['licence'], ':' )
);
$flag_lines = RW_Portal_Maintenance_Status::attention_flags( $premium_snapshot );
rw_test( 'an expired premium licence is an attention flag', in_array( '1 premium licence expired', $flag_lines, true ) );

$backup_detail = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array(
			'updraftplus_history' => array(
				array( 'time' => 1700000000, 'status' => 'success', 'type' => 'full', 'size' => 1288490188 ),
				array( 'time' => 1699900000, 'status' => 'failed', 'type' => 'database', 'size' => '240 MB' ),
				array( 'time' => 1699800000, 'status' => 'success' ),
			),
		),
	),
	1700001000
);
rw_test(
	'backup history keeps type and size when they are recorded',
	3 === count( $backup_detail['backups']['recent'] )
		&& 'Full' === $backup_detail['backups']['recent'][0]['type']
		&& '1.2 GB' === $backup_detail['backups']['recent'][0]['size']
		&& 'Database' === $backup_detail['backups']['recent'][1]['type']
		&& '240 MB' === $backup_detail['backups']['recent'][1]['size']
		&& null === $backup_detail['backups']['recent'][2]['type']
		&& null === $backup_detail['backups']['recent'][2]['size']
);
$history_view = RW_Portal_Maintenance_Status::present( $backup_detail );
rw_test(
	'backup history is a card with date, type, status and size',
	'Complete' === $history_view['backup_panel']['history'][0]['status']
		&& 'good' === $history_view['backup_panel']['history'][0]['status_tone']
		&& '1.2 GB' === $history_view['backup_panel']['history'][0]['size']
		&& 'Not reported' === $history_view['backup_panel']['history'][2]['type']
		&& 'Not reported' === $history_view['backup_panel']['history'][2]['size']
);
$history_template = file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/partials/maintenance-status.php' );
rw_test(
	'the backup history template is its own card',
	false !== strpos( $history_template, 'rw-maint-history-card' )
		&& false !== strpos( $history_template, 'Date' )
		&& false !== strpos( $history_template, 'Type' )
		&& false !== strpos( $history_template, 'Status' )
		&& false !== strpos( $history_template, 'Size' )
);

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$name = isset( $GLOBALS['rw_wp_timezone'] ) ? (string) $GLOBALS['rw_wp_timezone'] : 'UTC';
		return new DateTimeZone( $name );
	}
}

$zoned_site = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array( 'timezone_string' => 'Europe/London' ),
		'sync'    => array( 'dtsSync' => 1700000000 ),
	)
);
$offset_site = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array( 'gmt_offset' => 5.5 ),
	)
);
$invalid_zone = RW_Maint_Site_Snapshot::build(
	$scoped_site,
	array(
		'options' => array( 'timezone_string' => 'Not/AZone' ),
	)
);
rw_test( 'the snapshot keeps the site timezone', 'Europe/London' === $zoned_site['timezone'] );
rw_test( 'a numeric offset becomes a fixed zone', '+05:30' === $offset_site['timezone'] );
rw_test( 'an unrecognised zone is left unset', null === $invalid_zone['timezone'] );

$london_time = RW_Portal_Maintenance_Status::format_when( '2023-11-14T22:13:20+00:00', 'Europe/London' );
$paris_time  = RW_Portal_Maintenance_Status::format_when( '2023-11-14T22:13:20+00:00', 'Europe/Paris' );
$ny_time     = RW_Portal_Maintenance_Status::format_when( '2023-11-14T22:13:20+00:00', 'America/New_York' );
rw_test( 'winter London time is labelled GMT', '14 Nov 2023, 22:13 GMT' === $london_time && false === strpos( $london_time, 'UTC' ) );
rw_test( 'a site zone is not shown as UTC', '14 Nov 2023, 23:13 CET' === $paris_time );
rw_test( 'New York winter time is labelled EST', '14 Nov 2023, 17:13 EST' === $ny_time );

$GLOBALS['rw_wp_timezone'] = 'Europe/Paris';
$wordpress_zone = RW_Portal_Maintenance_Status::present(
	array(
		'last_admin_login' => array(
			'time'     => '2023-11-14T22:13:20+00:00',
			'username' => 'jane',
		),
	)
);
$GLOBALS['rw_wp_timezone'] = 'UTC';
$site_zone = RW_Portal_Maintenance_Status::present(
	array(
		'timezone'         => 'America/New_York',
		'last_admin_login' => array(
			'time'     => '2023-11-14T22:13:20+00:00',
			'username' => 'jane',
		),
	)
);
rw_test(
	'a missing site zone uses the WordPress zone',
	false !== strpos( $wordpress_zone['login']['when'], 'CET' )
		&& false === strpos( $wordpress_zone['login']['when'], 'UTC' )
);
rw_test( 'the site zone wins over the WordPress zone', false !== strpos( $site_zone['login']['when'], 'EST' ) );

require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-mainwp-status.php';

class RW_Status_Url_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	public $sites = array();

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

	public function get_row( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/FROM wp_mainwp_wp WHERE id = (\d+)/', $sql, $match ) ) {
			$id = (int) $match[1];
			return isset( $this->sites[ $id ] ) ? $this->sites[ $id ] : null;
		}
		return null;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return null;
	}

	public function get_results( $sql ) {
		$this->queries[] = $sql;
		return array();
	}
}

$saved_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
$url_wpdb   = new RW_Status_Url_Wpdb();
$url_wpdb->sites[12] = (object) array(
	'id'  => 12,
	'url' => 'https://other.example',
);
$GLOBALS['wpdb'] = $url_wpdb;
$guard_site = (object) array(
	'portal_site_id' => 7,
	'mainwp_site_id' => 12,
	'site_url'       => 'https://shop.example',
);
$blocked_sources = RW_Maint_MainWP_Status::collect( $guard_site );
$blocked_sql = implode( "\n", $url_wpdb->queries );
$url_wpdb->queries = array();
$url_wpdb->sites[12]->url = 'https://shop.example/';
$allowed_sources = RW_Maint_MainWP_Status::collect( $guard_site );
$allowed_sql = implode( "\n", $url_wpdb->queries );
$GLOBALS['wpdb'] = $saved_wpdb;
rw_test(
	'status does not read a MainWP site whose URL does not match',
	array() === $blocked_sources['website']
		&& array() === $blocked_sources['options']
		&& array() === $blocked_sources['logs']
		&& false === strpos( $blocked_sql, 'SELECT *' )
		&& false === strpos( $blocked_sql, 'mainwp_wp_options' )
		&& false !== strpos( $allowed_sql, 'SHOW TABLES' )
);

$index_blocks = array(
	array(
		'view' => array(
			'subscription_id' => 11,
			'plan_name'       => 'Standard Maintenance',
			'status_label'    => 'Connected',
			'state'           => 'connected',
			'renews_on'       => '1 Dec 2026',
			'site'            => (object) array( 'site_url' => 'https://example-site.com' ),
			'maintenance'     => $premium_view,
		),
	),
	array(
		'view' => array(
			'subscription_id' => 12,
			'plan_name'       => 'Essential Maintenance',
			'status_label'    => 'Finish connecting',
			'state'           => 'setup',
			'renews_on'       => '',
			'site'            => (object) array( 'site_url' => 'https://second-example.com' ),
		),
	),
);
rw_test( 'several plans open the site chooser until one is selected', RW_Portal_Account::show_site_index( 2, 0 ) && ! RW_Portal_Account::show_site_index( 2, 11 ) && ! RW_Portal_Account::show_site_index( 1, 0 ) );
rw_test( 'an unknown selection falls back to the chooser', 'index' === RW_Portal_Account::blocks_for_request( $index_blocks, 99 )['mode'] && 'dashboard' === RW_Portal_Account::blocks_for_request( $index_blocks, 11 )['mode'] );
$switcher = RW_Portal_Account::switcher_model( $index_blocks, 11 );
$index_cards = RW_Portal_Account::site_index_cards( $index_blocks );
rw_test(
	'the switcher lists every plan and marks the open site',
	2 === $switcher['count']
		&& true === $switcher['sites'][0]['current']
		&& false === $switcher['sites'][1]['current']
		&& 'example-site.com' === $switcher['current']['host']
		&& false !== strpos( $switcher['sites'][1]['url'], '/12/' )
		&& 'Not checked yet' === $index_cards[1]['updates']
		&& 'Not available yet' === $index_cards[1]['backup']
		&& array( 'Connect the plugin to start checks' ) === $index_cards[1]['flags']
);
$dash_css = file_get_contents( __DIR__ . '/../reactwoo-support-portal/assets/css/account-maintenance.css' );
$sites_template = file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/account-sites.php' );
$switcher_template = file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/partials/site-switcher.php' );
rw_test(
	'attention panels use the ink colour and rows stretch',
	false !== strpos( $dash_css, '#171c34' )
		&& false !== strpos( $dash_css, 'align-items: stretch' )
		&& false !== strpos( $dash_css, 'height: 100%' )
		&& false !== strpos( $dash_css, 'overflow-wrap: anywhere' )
		&& false === strpos( $dash_css, 'line-clamp' )
		&& false !== strpos( $sites_template, 'Your sites' )
		&& false !== strpos( $switcher_template, 'Viewing site' )
		&& false !== strpos( $switcher_template, 'All sites' )
);

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-offers.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-purchase.php';

class RW_Offer_Product {
	public $slug;
	public $id;
	public function __construct( $slug, $id ) {
		$this->slug = $slug;
		$this->id   = $id;
	}
	public function get_slug() {
		return $this->slug;
	}
	public function get_id() {
		return $this->id;
	}
}

class RW_Offer_Order {
	public $meta = array();
	public $status = 'pending';
	public $id;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function set_payment_method( $gateway ) {
		unset( $gateway );
	}
	public function save() {
	}
	public function get_status() {
		return $this->status;
	}
	public function update_status( $status, $note = '' ) {
		unset( $note );
		$this->status = $status;
	}
}

class RW_Offer_Gateway {
	public $id = 'revolut_cc';
	public $calls = array();
	public $result = array( 'result' => 'success' );
	public function supports( $feature ) {
		return 'tokenization' === $feature;
	}
	public function process_payment( $wc_order_id, $revolut_public_id = '', $is_express_checkout = false, $revolut_payment_error = '', $reload_checkout = false, $is_captured = false, $is_using_saved_payment_method = false, $save_payment_method_requested = false, $wc_payment_token_id = '' ) {
		$this->calls[] = func_get_args();
		return $this->result;
	}
}

$offer_catalogue = RW_Support_Offers::catalogue();
$offer_prices = false;
foreach ( $offer_catalogue as $offer_row ) {
	if ( isset( $offer_row['price'] ) || isset( $offer_row['amount'] ) ) {
		$offer_prices = true;
	}
}
rw_test(
	'support packs are matched by slug and do not store a price',
	3663 === $offer_catalogue['additional-support-1h']['id']
		&& 3666 === $offer_catalogue['technical-support-5h']['id']
		&& ! $offer_prices
		&& 'additional' === RW_Support_Offers::kind_of( 'additional-support-5h', 0 )
		&& 'technical' === RW_Support_Offers::kind_of( 'renamed', 3665 )
);
rw_test(
	'additional support needs an active maintenance product',
	RW_Support_Offers::subscription_grants_additional( 'active', array( 3657 ) )
		&& RW_Support_Offers::subscription_grants_additional( 'pending-cancel', array( 3660 ) )
		&& ! RW_Support_Offers::subscription_grants_additional( 'on-hold', array( 3654 ) )
		&& ! RW_Support_Offers::subscription_grants_additional( 'active', array( 999 ) )
);
rw_test(
	'the purchasable guard blocks additional support without a plan',
	! RW_Support_Offers::allows_purchase( true, 'additional', false )
		&& RW_Support_Offers::allows_purchase( true, 'additional', true )
		&& RW_Support_Offers::allows_purchase( true, 'technical', false )
		&& ! RW_Support_Offers::allows_purchase( false, 'technical', true )
		&& ! rw_additional_support_guard_purchasable( true, new RW_Offer_Product( 'additional-support-1h', 3663 ) )
		&& rw_additional_support_guard_purchasable( true, new RW_Offer_Product( 'technical-support-1h', 3665 ) )
);
$offer_card = RW_Support_Offers::present(
	array(
		array( 'slug' => 'additional-support-1h', 'id' => 3663, 'name' => 'Additional support, 1 hour', 'price_html' => '<span class="woocommerce-Price-amount">£40.00</span>' ),
		array( 'slug' => 'technical-support-5h', 'id' => 3666, 'name' => 'Technical support, 5 hours', 'price_html' => wc_price_missing() ),
	),
	false,
	array( 'token_id' => 9, 'gateway_id' => 'revolut_cc', 'last4' => '1234' ),
	'https://reactwoo.com/checkout/',
	11,
	7
);
function wc_price_missing() {
	return '<span class="woocommerce-Price-amount">£120.00</span>';
}
rw_test(
	'without a maintenance plan only technical support is offered at the live price',
	1 === count( $offer_card['options'] )
		&& 'technical-support-5h' === $offer_card['options'][0]['slug']
		&& '£120.00' === $offer_card['options'][0]['price_text']
		&& false !== strpos( $offer_card['options'][0]['checkout_url'], 'add-to-cart=3666' )
		&& false !== strpos( $offer_card['options'][0]['checkout_url'], 'rw_subscription_id=11' )
		&& false !== strpos( $offer_card['options'][0]['checkout_url'], 'rw_site_id=7' )
		&& 'Checkout with card ending ••••1234' === $offer_card['card']['label']
		&& false !== strpos( $offer_card['options'][0]['checkout_url'], 'rw_payment_token=9' )
		&& empty( $offer_card['charge'] )
);
$offer_with_plan = RW_Support_Offers::present(
	array(
		array( 'slug' => 'additional-support-1h', 'id' => 3663, 'name' => 'Additional support, 1 hour', 'price_html' => '£40.00' ),
		array( 'slug' => 'plugin', 'id' => 1, 'name' => 'Other', 'price_html' => '£9.00' ),
	),
	true,
	null,
	'https://reactwoo.com/checkout/',
	11,
	7
);
rw_test(
	'an active plan shows additional support and drops unknown products',
	1 === count( $offer_with_plan['options'] )
		&& 'additional-support-1h' === $offer_with_plan['options'][0]['slug']
		&& null === $offer_with_plan['card']
);

$offer_args = RW_Support_Purchase::payment_arguments(
	array( 'wc_order_id', 'revolut_public_id', 'is_express_checkout', 'revolut_payment_error', 'reload_checkout', 'is_captured', 'is_using_saved_payment_method', 'save_payment_method_requested', 'wc_payment_token_id' ),
	440,
	9
);
rw_test(
	'revolut process_payment receives the order and the saved token',
	440 === $offer_args[0]
		&& '' === $offer_args[1]
		&& false === $offer_args[2]
		&& true === $offer_args[6]
		&& false === $offer_args[7]
		&& '9' === $offer_args[8]
);

$offer_orders = 0;
$offer_order = new RW_Offer_Order( 440 );
RW_Support_Purchase::$order_factory = function () use ( &$offer_orders, $offer_order ) {
	$offer_orders++;
	return $offer_order;
};
$offer_gateway = new RW_Offer_Gateway();
$offer_request = array(
	'user_id'             => 4,
	'can_act'             => true,
	'nonce_ok'            => true,
	'slug'                => 'technical-support-1h',
	'subscription_id'     => 11,
	'subscription_owned'  => true,
	'subscription_status' => 'active',
	'product_ids'         => array( 3654 ),
	'site_id'             => 7,
	'idempotency_key'     => 'click-once-key-1234',
	'product'             => array(
		'id'          => 3665,
		'slug'        => 'technical-support-1h',
		'purchasable' => true,
	),
	'token'               => array(
		'id'         => 9,
		'user_id'    => 4,
		'gateway_id' => 'revolut_cc',
		'last4'      => '1234',
	),
	'gateway'             => $offer_gateway,
);
$offer_unauthorised = RW_Support_Purchase::attempt( $offer_request );
rw_test(
	'a saved card is not charged unless that charge is explicitly authorised',
	'failure' === $offer_unauthorised['result']
		&& false !== strpos( $offer_unauthorised['message'], 'checkout' )
		&& 0 === $offer_orders
		&& 0 === count( $offer_gateway->calls )
);
$offer_request['charge_authorised'] = true;
$offer_request['idempotency_key'] = 'click-once-key-1234';
$offer_first = RW_Support_Purchase::attempt( $offer_request );
$offer_second = RW_Support_Purchase::attempt( $offer_request );
rw_test(
	'a saved card charge is idempotent and stores the plan and site',
	'success' === $offer_first['result']
		&& 440 === $offer_first['order_id']
		&& $offer_first === $offer_second
		&& 1 === $offer_orders
		&& 1 === count( $offer_gateway->calls )
		&& 11 === $offer_order->meta['_rw_maintenance_subscription_id']
		&& 7 === $offer_order->meta['_rw_managed_site_id']
		&& '9' === $_POST['wc-revolut_cc-payment-token']
);
$offer_request['idempotency_key'] = 'someone-elses-card1';
$offer_request['token']['user_id'] = 8;
$offer_rejected = RW_Support_Purchase::attempt( $offer_request );
rw_test( 'another customer token is not charged', 'failure' === $offer_rejected['result'] && 1 === $offer_orders );
$offer_request['token']['user_id'] = 4;
$offer_request['can_act'] = false;
$offer_request['idempotency_key'] = 'missing-capability';
$offer_forbidden = RW_Support_Purchase::attempt( $offer_request );
rw_test( 'a purchase without the account capability is refused', 'failure' === $offer_forbidden['result'] && 1 === $offer_orders );
$offer_request['can_act'] = true;
$offer_request['nonce_ok'] = false;
$offer_request['idempotency_key'] = 'expired-nonce-key';
$offer_nonce = RW_Support_Purchase::attempt( $offer_request );
rw_test( 'a purchase with a bad nonce is refused', 'failure' === $offer_nonce['result'] && 1 === $offer_orders );
$offer_request['nonce_ok'] = true;
$offer_request['product']['slug'] = 'additional-support-1h';
$offer_request['product']['id'] = 3663;
$offer_request['subscription_status'] = 'cancelled';
$offer_request['idempotency_key'] = 'no-active-plan-key';
$offer_blocked = RW_Support_Purchase::attempt( $offer_request );
rw_test( 'additional support is refused server-side without an active plan', 'failure' === $offer_blocked['result'] && 1 === $offer_orders );
$offer_template = file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/partials/support-offer.php' );
$duplicate_packs = RW_Support_Offers::present(
	array(
		array( 'slug' => 'technical-support-5h', 'id' => 3666, 'name' => 'ReactWoo Technical Support 5h', 'price_html' => '£220.00' ),
		array( 'slug' => 'technical-support-5h', 'id' => 3666, 'name' => 'ReactWoo Technical Support 5h', 'price_html' => '£220.00' ),
		array( 'slug' => 'technical-support-1h', 'id' => 3665, 'name' => 'ReactWoo Technical Support 1h', 'price_html' => '£55.00' ),
		array( 'slug' => 'technical-support-5h', 'id' => 3666, 'name' => 'ReactWoo Technical Support 5h', 'price_html' => '£220.00' ),
		array( 'slug' => 'additional-support-1h', 'id' => 3663, 'name' => 'Additional support 1h', 'price_html' => '£40.00' ),
		array( 'slug' => 'not-a-pack', 'id' => 9999, 'name' => 'ReactWoo Technical Support 5h', 'price_html' => '£220.00' ),
	),
	false,
	null,
	'https://reactwoo.com/checkout/',
	11,
	7
);
$pack_names = array();
foreach ( $duplicate_packs['options'] as $pack_option ) {
	$pack_names[] = $pack_option['name'];
}
rw_test(
	'support packs are the four product ids, without duplicates or non-member additional support',
	array( 'ReactWoo Technical Support 5h', 'ReactWoo Technical Support 1h' ) === $pack_names
);
rw_test(
	'the support card has a pack menu, saved-card purchase, and checkout fallback',
	false !== strpos( $offer_template, 'Buy extra support' )
		&& false !== strpos( $offer_template, 'data-offer-open' )
		&& false !== strpos( $offer_template, 'Continue to checkout' )
		&& false !== strpos( $offer_template, 'rw-maint-pill--good' )
);

if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
	function wcs_get_users_subscriptions( $user_id ) {
		$all = isset( $GLOBALS['rw_user_subscriptions'] ) && is_array( $GLOBALS['rw_user_subscriptions'] ) ? $GLOBALS['rw_user_subscriptions'] : array();
		$user_id = (int) $user_id;
		return isset( $all[ $user_id ] ) ? $all[ $user_id ] : array();
	}
}

function rw_menu_subscription( $status, $product_id, $end = 0 ) {
	$item                 = new RW_Test_Item();
	$item->product_id     = (int) $product_id;
	$subscription         = new RW_Test_Subscription();
	$subscription->status = $status;
	$subscription->end    = (int) $end;
	$subscription->items  = array( $item );
	return $subscription;
}

$menu_items = array(
	'dashboard' => 'Dashboard',
	'orders'    => 'Orders',
);
rw_test( 'a logged-out customer does not see the maintenance menu', false === RW_Portal_Account::user_has_maintenance_for_menu( 0 ) && ! isset( RW_Portal_Account::add_menu_item( $menu_items )['maintenance'] ) );
$GLOBALS['rw_user_subscriptions'][8] = array( rw_menu_subscription( 'active', 999 ) );
rw_test( 'a subscription without a maintenance product does not show the menu', false === RW_Portal_Account::user_has_maintenance_for_menu( 8 ) );
$GLOBALS['rw_user_subscriptions'][8] = array( rw_menu_subscription( 'cancelled', 3654 ) );
rw_test( 'a cancelled maintenance subscription does not show the menu', false === RW_Portal_Account::user_has_maintenance_for_menu( 8 ) );
$GLOBALS['rw_user_subscriptions'][8] = array( rw_menu_subscription( 'on-hold', 3660 ) );
$GLOBALS['rw_current_user_id'] = 8;
$shown_menu = RW_Portal_Account::add_menu_item( $menu_items );
rw_test(
	'an on-hold maintenance subscription adds the menu after Dashboard',
	true === RW_Portal_Account::user_has_maintenance_for_menu( 8 )
		&& array( 'dashboard', 'maintenance', 'orders' ) === array_keys( $shown_menu )
		&& 'Maintenance' === $shown_menu['maintenance']
);
$GLOBALS['rw_user_subscriptions'][9] = array( rw_menu_subscription( 'pending-cancel', 3657 ) );
rw_test( 'pending-cancel still counts as maintenance for the menu', true === RW_Portal_Account::user_has_maintenance_for_menu( 9 ) );
$kept_menu = RW_Portal_Account::add_menu_item( array( 'maintenance' => 'Already there', 'dashboard' => 'Dashboard' ) );
rw_test( 'an existing maintenance menu item is left unchanged', 'Already there' === $kept_menu['maintenance'] && 2 === count( $kept_menu ) );
$GLOBALS['rw_current_user_id'] = 0;
unset( $GLOBALS['rw_user_subscriptions'] );

require __DIR__ . '/account-readiness.php';
require __DIR__ . '/support-sites.php';

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_GB';
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		unset( $field );
		return (object) array(
			'ID'           => (int) $value,
			'display_name' => 'Ada Lovelace',
			'user_email'   => 'ada@example.com',
		);
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		global $rw_requests;
		$rw_requests[] = array( 'url' => $url, 'args' => $args );
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"mainwp_client_id":5,"matched_by":"email"}',
		);
	}
}

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-identity.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-maint-client.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-identity-sync.php';
require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-mainwp-clients.php';

if ( ! class_exists( 'RW_Sites' ) ) {
	class RW_Sites {
		public static $sites = array();

		public static function get_sites_by_user( $user_id ) {
			$found = array();
			foreach ( self::$sites as $site ) {
				if ( (int) $site->user_id === (int) $user_id ) {
					$found[] = $site;
				}
			}
			return $found;
		}

		public static function update_identity( $site_id, array $identity ) {
			foreach ( self::$sites as $site ) {
				if ( (int) $site->id === (int) $site_id ) {
					foreach ( $identity as $key => $value ) {
						$site->$key = $value;
					}
				}
			}
			return true;
		}

		public static function get_site( $site_id ) {
			foreach ( self::$sites as $site ) {
				if ( (int) $site->id === (int) $site_id ) {
					return $site;
				}
			}
			return null;
		}
	}
}

function rw_mainwp_directory() {
	return array(
		'clients' => array(
			array(
				'client_id'      => 5,
				'name'           => 'Ada Lovelace',
				'client_email'   => 'ada@example.com',
				'client_phone'   => '02070000000',
				'company'        => 'Analytical Engines',
				'rw_customer_id' => '42',
			),
			array(
				'client_id'      => 8,
				'name'           => 'Other Person',
				'client_email'   => 'other@example.com',
				'client_phone'   => '02071111111',
				'company'        => 'Other Co',
				'rw_customer_id' => '77',
			),
		),
		'sites'   => array(
			12 => array(
				'id'              => 12,
				'url'             => 'https://aplenty.co.uk',
				'client_id'       => 3,
				'tags'            => array( 'live', 'subscription-1' ),
				'subscription_id' => 1,
			),
			21 => array(
				'id'              => 21,
				'url'             => 'https://ada.example',
				'client_id'       => 5,
				'tags'            => array( 'subscription-9', 'client-42' ),
				'subscription_id' => 9,
			),
			30 => array(
				'id'              => 30,
				'url'             => 'https://new.example',
				'client_id'       => 0,
				'tags'            => array( 'subscription-15', 'client-100' ),
				'subscription_id' => 15,
			),
			40 => array(
				'id'              => 40,
				'url'             => 'https://other.example',
				'client_id'       => 8,
				'tags'            => array( 'subscription-77' ),
				'subscription_id' => 77,
			),
		),
	);
}

function rw_client_row( array $directory, $client_id ) {
	foreach ( $directory['clients'] as $client ) {
		if ( (int) $client['client_id'] === (int) $client_id ) {
			return $client;
		}
	}
	return null;
}

function rw_sync_client( array $directory, array $subscriber, $site_id, $enrolled_url = '' ) {
	RW_Maint_Audit::$events = array();
	RW_Maint_MainWP_Clients::use_directory( $directory );
	$context = array( 'user_id' => (int) $subscriber['customer_id'] );
	if ( '' !== (string) $enrolled_url ) {
		$context['site_url'] = (string) $enrolled_url;
	}
	$result = RW_Maint_MainWP_Clients::sync( $subscriber, $site_id, $context );
	return array( $result, RW_Maint_MainWP_Clients::directory(), RW_Maint_Audit::$events );
}

$enrol_site = (object) array(
	'id'              => 7,
	'subscription_id' => 9,
	'user_id'         => 42,
	'site_url'        => 'https://ada.example',
	'site_name'       => 'ada.example',
	'client_name'     => 'Ada Lovelace',
	'client_email'    => 'ada@example.com',
	'client_company'  => 'Analytical Engines',
	'client_phone'    => '',
	'client_locale'   => 'en_GB',
	'report_email'    => 'ada@example.com',
	'report_enabled'  => 1,
);
$enrol_payload = RW_Maint_Client::enrol_payload( $enrol_site );
rw_test(
	'enrol payload includes name, company, email, and the customer id',
	42 === $enrol_payload['client']['customer_id']
		&& 'Ada Lovelace' === $enrol_payload['client']['client_name']
		&& 'Analytical Engines' === $enrol_payload['client']['client_company']
		&& 'ada@example.com' === $enrol_payload['client']['client_email']
		&& ! array_key_exists( 'client_phone', $enrol_payload['client'] )
);
$enrol_site->client_phone = '02070000000';
$enrol_with_phone         = RW_Maint_Client::enrol_payload( $enrol_site );
rw_test( 'enrol payload adds the billing phone when it is present', '02070000000' === $enrol_with_phone['client']['client_phone'] );

$GLOBALS['rw_options']['rw_portal_maint_url']    = 'https://maint.example';
$GLOBALS['rw_options']['rw_portal_maint_secret'] = 'test-secret';
$GLOBALS['rw_user_meta'][42]                     = array(
	'billing_first_name' => 'Ada',
	'billing_last_name'  => 'Lovelace',
	'billing_email'      => 'ada@example.com',
	'billing_company'    => 'Analytical Engines',
	'billing_phone'      => '',
	'locale'             => 'en_GB',
);
RW_Sites::$sites = array(
	(object) array(
		'id'              => 7,
		'user_id'         => 42,
		'subscription_id' => 9,
		'client_email'    => 'old@example.com',
		'report_email'    => 'old@example.com',
		'report_enabled'  => 1,
	),
);
$requests_before = count( $rw_requests );
RW_Portal_Identity_Sync::handle_profile_update( 42 );
$profile_call = $rw_requests[ count( $rw_requests ) - 1 ];
$profile_body = json_decode( $profile_call['args']['body'], true );
rw_test(
	'profile update sends one signed client-update',
	1 === ( count( $rw_requests ) - $requests_before )
		&& false !== strpos( $profile_call['url'], 'client-update' )
		&& false === strpos( $profile_call['url'], 'enroll' )
		&& isset( $profile_call['args']['headers']['X-RW-Signature'] )
		&& '/reactwoo-maint/v1/client-update' === $profile_call['args']['headers']['X-RW-Path']
		&& 42 === $profile_body['client']['customer_id']
		&& ! array_key_exists( 'client_phone', $profile_body['client'] )
);
RW_Portal_Identity_Sync::register();
$registered_hooks = array();
foreach ( $GLOBALS['rw_actions'] as $action ) {
	$registered_hooks[] = $action['hook'];
}
rw_test(
	'profile update and WooCommerce customer save are both hooked',
	in_array( 'profile_update', $registered_hooks, true ) && in_array( 'woocommerce_update_customer', $registered_hooks, true )
);

$site_assignment = RW_Maint_MainWP_Clients::assignment_update( 9, 30, 'https://new.example' );
rw_test(
	'site assignment writes only client_id on an approved site',
	is_array( $site_assignment )
		&& array( 'client_id' => 9 ) === $site_assignment['data']
		&& array( 'id' => 30 ) === $site_assignment['where']
);
rw_test(
	'assignment refuses site 12, aplenty.co.uk, and a URL mismatch',
	null === RW_Maint_MainWP_Clients::assignment_update( 9, 12, 'https://aplenty.co.uk' )
		&& null === RW_Maint_MainWP_Clients::assignment_update( 9, 12, 'https://customer.example', 'https://customer.example' )
		&& null === RW_Maint_MainWP_Clients::assignment_update( 9, 50, 'https://www.aplenty.co.uk' )
		&& null === RW_Maint_MainWP_Clients::assignment_update( 9, 30, 'https://new.example', 'https://customer.example' )
		&& ! RW_Maint_MainWP::may_operate_on( 30, 'https://customer.example', 'https://new.example' )
);

$fresh = rw_mainwp_directory();
unset( $fresh['clients'][0] );
$fresh['clients'] = array_values( $fresh['clients'] );
list( $created, $created_dir, $created_audit ) = rw_sync_client(
	$fresh,
	array(
		'customer_id' => 100,
		'name'        => 'New Person',
		'company'     => 'New Co',
		'email'       => 'new@example.com',
	),
	30,
	'https://new.example'
);
$created_client = rw_client_row( $created_dir, $created['mainwp_client_id'] );
rw_test(
	'a new client is created and the enrolled site is assigned',
	! is_wp_error( $created )
		&& 'created' === $created['matched_by']
		&& 2 === count( $created_dir['clients'] )
		&& 30 === $created['mainwp_site_id']
		&& is_array( $created_client )
		&& '100' === $created_client['rw_customer_id']
		&& (int) $created_client['client_id'] === (int) $created_dir['sites'][30]['client_id']
		&& array( 'subscription-15', 'client-100' ) === $created_dir['sites'][30]['tags']
		&& 3 === $created_dir['sites'][12]['client_id']
		&& 'mainwp_client_linked' === $created_audit[0]['event']
);

list( $by_customer, $customer_dir, ) = rw_sync_client(
	rw_mainwp_directory(),
	array(
		'customer_id' => 42,
		'name'        => 'Ada Updated',
		'company'     => 'Ada Ltd',
		'email'       => 'ada@new.example',
	),
	21,
	'https://ada.example'
);
rw_test(
	'an existing client is matched by customer id',
	! is_wp_error( $by_customer )
		&& 'customer_id' === $by_customer['matched_by']
		&& 5 === $by_customer['mainwp_client_id']
		&& 2 === count( $customer_dir['clients'] )
		&& 'ada@new.example' === rw_client_row( $customer_dir, 5 )['client_email']
		&& 'other@example.com' === rw_client_row( $customer_dir, 8 )['client_email']
		&& 5 === $customer_dir['sites'][21]['client_id']
		&& array( 'subscription-9', 'client-42' ) === $customer_dir['sites'][21]['tags']
		&& 3 === $customer_dir['sites'][12]['client_id']
);

$email_directory = rw_mainwp_directory();
$email_directory['clients'][0]['rw_customer_id'] = '';
$email_directory['sites'][21]['client_id']       = 0;
list( $by_email, $email_dir, ) = rw_sync_client(
	$email_directory,
	array(
		'customer_id' => 42,
		'name'        => 'Ada Claimed',
		'company'     => 'Claimed Co',
		'email'       => 'ada@example.com',
	),
	21,
	'https://ada.example'
);
rw_test(
	'an existing client is matched by email before a duplicate is created',
	! is_wp_error( $by_email )
		&& 'email' === $by_email['matched_by']
		&& 5 === $by_email['mainwp_client_id']
		&& 2 === count( $email_dir['clients'] )
		&& '42' === rw_client_row( $email_dir, 5 )['rw_customer_id']
		&& 5 === $email_dir['sites'][21]['client_id']
		&& array( 'subscription-9', 'client-42' ) === $email_dir['sites'][21]['tags']
		&& 9 === $email_dir['sites'][21]['subscription_id']
		&& 3 === $email_dir['sites'][12]['client_id']
		&& 8 === $email_dir['sites'][40]['client_id']
);

$backfill = rw_mainwp_directory();
$backfill['clients'][0]['rw_customer_id'] = '';
$backfill['sites'][21]['client_id']       = 0;
list( $filled, $filled_dir, ) = rw_sync_client(
	$backfill,
	array(
		'customer_id' => 42,
		'name'        => 'Ada Lovelace',
		'company'     => 'Analytical Engines',
		'email'       => 'Ada@Example.com',
	),
	21,
	'https://www.ada.example/'
);
rw_test(
	'a later check backfill links the approved site to the existing email client',
	! is_wp_error( $filled )
		&& 'email' === $filled['matched_by']
		&& 5 === $filled['mainwp_client_id']
		&& 2 === count( $filled_dir['clients'] )
		&& 5 === $filled_dir['sites'][21]['client_id']
		&& 0 === $filled_dir['sites'][30]['client_id']
		&& 3 === $filled_dir['sites'][12]['client_id']
);

$conflict_before = rw_mainwp_directory();
$conflict_before['clients'][0]['rw_customer_id'] = '';
list( $conflict, $conflict_dir, ) = rw_sync_client(
	$conflict_before,
	array(
		'customer_id' => 42,
		'name'        => 'Should Not Write',
		'company'     => 'Nope',
		'email'       => 'other@example.com',
	),
	30,
	'https://new.example'
);
rw_test(
	'an email owned by another customer is not reused or duplicated',
	is_wp_error( $conflict )
		&& 'rw_mainwp_client_conflict' === $conflict->get_error_code()
		&& 2 === count( $conflict_dir['clients'] )
		&& '77' === rw_client_row( $conflict_dir, 8 )['rw_customer_id']
		&& 0 === $conflict_dir['sites'][30]['client_id']
);

list( $details, $details_dir, ) = rw_sync_client(
	rw_mainwp_directory(),
	array(
		'customer_id' => 42,
		'name'        => 'Augusta Ada',
		'company'     => 'Difference Engine',
		'email'       => 'ada@updated.example',
	),
	0
);
rw_test(
	'a details update changes name, company, and email without moving sites',
	! is_wp_error( $details )
		&& 0 === $details['mainwp_site_id']
		&& 'Augusta Ada' === rw_client_row( $details_dir, 5 )['name']
		&& 'Difference Engine' === rw_client_row( $details_dir, 5 )['company']
		&& 'ada@updated.example' === rw_client_row( $details_dir, 5 )['client_email']
		&& '02070000000' === rw_client_row( $details_dir, 5 )['client_phone']
		&& $details_dir['sites'] === rw_mainwp_directory()['sites']
);

$second = rw_mainwp_directory();
$second['sites'][22] = array(
	'id'              => 22,
	'url'             => 'https://ada-two.example',
	'client_id'       => 0,
	'tags'            => array( 'subscription-9', 'client-42' ),
	'subscription_id' => 9,
);
list( $second_result, $second_dir, ) = rw_sync_client(
	$second,
	array(
		'customer_id' => 42,
		'name'        => 'Ada Lovelace',
		'company'     => 'Analytical Engines',
		'email'       => 'ada@example.com',
	),
	22,
	'https://ada-two.example'
);
rw_test(
	'a second site for the same client does not create another client',
	! is_wp_error( $second_result )
		&& 5 === $second_result['mainwp_client_id']
		&& 2 === count( $second_dir['clients'] )
		&& 5 === $second_dir['sites'][22]['client_id']
		&& array( 'subscription-9', 'client-42' ) === $second_dir['sites'][22]['tags']
		&& 5 === $second_dir['sites'][21]['client_id']
		&& 3 === $second_dir['sites'][12]['client_id']
		&& array( 'live', 'subscription-1' ) === $second_dir['sites'][12]['tags']
);

list( $rejected, $rejected_dir, ) = rw_sync_client(
	rw_mainwp_directory(),
	array(
		'customer_id' => 42,
		'name'        => 'Ada Lovelace',
		'company'     => 'Analytical Engines',
		'email'       => 'ada@example.com',
	),
	30,
	'https://customer.example'
);
rw_test(
	'client sync does not touch a site may_operate_on rejects',
	! is_wp_error( $rejected )
		&& 0 === $rejected['mainwp_site_id']
		&& 0 === $rejected_dir['sites'][30]['client_id']
		&& array( 'subscription-15', 'client-100' ) === $rejected_dir['sites'][30]['tags']
		&& 5 === $rejected_dir['sites'][21]['client_id']
		&& 3 === $rejected_dir['sites'][12]['client_id']
);

list( $protected, $protected_dir, $protected_audit ) = rw_sync_client(
	rw_mainwp_directory(),
	array(
		'customer_id' => 42,
		'name'        => 'Ada Lovelace',
		'company'     => 'Analytical Engines',
		'email'       => 'ada@example.com',
	),
	12,
	'https://aplenty.co.uk'
);
rw_test(
	'linking never changes MainWP site 12',
	! is_wp_error( $protected )
		&& 0 === $protected['mainwp_site_id']
		&& ! empty( $protected['site_protected'] )
		&& 3 === $protected_dir['sites'][12]['client_id']
		&& array( 'live', 'subscription-1' ) === $protected_dir['sites'][12]['tags']
		&& 12 === $protected_audit[0]['data']['protected_site']
);
RW_Maint_MainWP_Clients::use_directory( null );

$bridge_php = file_get_contents( __DIR__ . '/../reactwoo-maint-bridge/reactwoo-maint-bridge.php' );
rw_test(
	'bridge version is 0.3.7',
	false !== strpos( $bridge_php, 'Version: 0.3.7' ) && false !== strpos( $bridge_php, "define( 'RW_MAINT_VERSION', '0.3.7' )" )
);
$hardening_cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/audit-hardening.php' );
$hardening_output = array();
$hardening_status = 0;
exec( $hardening_cmd, $hardening_output, $hardening_status );
foreach ( $hardening_output as $hardening_line ) {
	echo $hardening_line . "\n";
}
rw_test( 'audit log failures and a missing table are reported without SHOW TABLES on every request', 0 === $hardening_status );


// --- Customer actions helpers (no WP) ---
require_once dirname( __DIR__ ) . '/reactwoo-support-portal/includes/class-rw-portal-customer-actions.php';

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $e ) {
		$e = filter_var( $e, FILTER_SANITIZE_EMAIL );
		return is_string( $e ) ? $e : '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $e ) {
		return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
	}
}

$parsed = RW_Portal_Customer_Actions::parse_emails( 'a@example.com, b@example.com; bad;; a@example.com' );
rw_test( 'parse_emails unique valid', array( 'a@example.com', 'b@example.com' ) === $parsed );

$pdf = RW_Portal_Customer_Actions::build_simple_pdf( array( 'Hello', 'World' ) );
rw_test( 'build_simple_pdf header', 0 === strpos( $pdf, '%PDF-1.4' ) );
rw_test( 'build_simple_pdf eof', false !== strpos( $pdf, '%%EOF' ) );
rw_test( 'build_simple_pdf has text', false !== strpos( $pdf, 'Hello' ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} failed\n" );
	exit( 1 );
}

echo "\nAll maintenance connection tests passed\n";
