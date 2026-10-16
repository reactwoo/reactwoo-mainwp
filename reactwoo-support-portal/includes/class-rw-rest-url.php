<?php
/**
 * One REST URL builder for the store and the maintenance bridge.
 *
 * Pretty `/wp-json/` is broken on the current staging hosts (store 500,
 * maint 404). Callers pass the REST route. This class decides whether to
 * use a pretty URL or `index.php?rest_route=`. HMAC signatures still use
 * the route, not the URL path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Rest_Url {
	/**
	 * @param string $base  Site URL, with or without a trailing slash.
	 * @param string $route REST route beginning with a slash, without wp-json.
	 * @param string $style pretty|query
	 * @return string
	 */
	public static function build( $base, $route, $style = 'query' ) {
		$route = '/' . ltrim( (string) $route, '/' );
		$base  = rtrim( (string) $base, '/' );
		if ( 'pretty' === $style ) {
			return $base . '/wp-json' . $route;
		}

		return $base . '/index.php?rest_route=' . rawurlencode( $route );
	}

	/**
	 * @param string $route
	 * @return string
	 */
	public static function sign_path( $route ) {
		return '/' . ltrim( (string) $route, '/' );
	}

	/**
	 * Portal option. Query style is the safe default until permalinks are fixed.
	 *
	 * @return string
	 */
	public static function configured_style() {
		$style = function_exists( 'get_option' ) ? (string) get_option( 'rw_portal_rest_style', 'query' ) : 'query';
		return 'pretty' === $style ? 'pretty' : 'query';
	}
}
