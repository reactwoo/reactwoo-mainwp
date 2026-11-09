<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_WordPress_Client {
	/**
	 * Discover the REST root and Application Password authorization URL.
	 *
	 * Each redirect is checked with RW_Site_Url before it is followed.
	 * The authorization URL is taken from the REST index, not guessed.
	 *
	 * @param string $site_url Customer site URL.
	 * @return array|WP_Error
	 */
	public static function discover( $site_url ) {
		$current = RW_Site_Url::assert_public_https( $site_url );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$response = null;
		for ( $hop = 0; $hop <= RW_Site_Url::MAX_REDIRECTS; $hop++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array(
						'Accept' => 'text/html, application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'rw_wp_discovery_failed', 'The website could not be reached.', array( 'status' => 502 ) );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = self::first_header_value( $response, 'location' );
				if ( '' === $location ) {
					return new WP_Error( 'rw_wp_discovery_failed', 'The website redirect could not be followed.', array( 'status' => 502 ) );
				}
				if ( $hop === RW_Site_Url::MAX_REDIRECTS ) {
					return new WP_Error( 'rw_wp_discovery_failed', 'The website redirected too many times.', array( 'status' => 502 ) );
				}
				$next = self::resolve_redirect( $current, $location );
				if ( is_wp_error( $next ) ) {
					return $next;
				}
				$current = $next;
				continue;
			}

			break;
		}

		$rest_root = '';
		$index     = null;
		if ( is_array( $response ) && (int) wp_remote_retrieve_response_code( $response ) >= 200 && (int) wp_remote_retrieve_response_code( $response ) < 300 ) {
			$rest_root = self::rest_root_from_http_response( $response );
		}

		// A login wall can hide the front page while /wp-json/ and ?rest_route=/ still answer.
		if ( '' === $rest_root ) {
			$probed = self::probe_rest_index( $current );
			if ( is_array( $probed ) ) {
				$rest_root = $probed['rest_root'];
				$index     = $probed['index'];
			}
		}

		if ( '' === $rest_root ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'WordPress REST discovery did not return an API address.', array( 'status' => 502 ) );
		}

		$raw_rest  = $rest_root;
		$rest_root = self::preserve_rest_route( $raw_rest );
		if ( is_wp_error( $rest_root ) ) {
			return $rest_root;
		}

		if ( ! is_array( $index ) ) {
			$index = self::fetch_rest_index( $rest_root );
			if ( is_wp_error( $index ) ) {
				return $index;
			}
		}

		if ( ! self::is_rest_index( $index ) ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'The WordPress API did not return discovery data.', array( 'status' => 502 ) );
		}

		$authorization = '';
		if ( isset( $index['authentication']['application-passwords']['endpoints']['authorization'] ) ) {
			$authorization = (string) $index['authentication']['application-passwords']['endpoints']['authorization'];
		}

		if ( '' === $authorization ) {
			return new WP_Error( 'rw_wp_app_passwords_unavailable', 'This WordPress site does not advertise Application Password setup.', array( 'status' => 409 ) );
		}

		$authorization = RW_Site_Url::assert_public_https( $authorization );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		return array(
			'site_url'          => $current,
			'rest_root'         => $rest_root,
			'authorization_url' => $authorization,
		);
	}

	private static function preserve_rest_route( $url ) {
		$checked = RW_Site_Url::assert_public_https( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$query        = array();
		$query_string = wp_parse_url( (string) $url, PHP_URL_QUERY );
		if ( is_string( $query_string ) && '' !== $query_string ) {
			parse_str( $query_string, $query );
		}
		if ( isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) && 0 === strpos( $query['rest_route'], '/' ) ) {
			$checked = add_query_arg( 'rest_route', $query['rest_route'], $checked );
		}

		return $checked;
	}

	private static function resolve_redirect( $current, $location ) {
		$location = trim( (string) $location );
		if ( 0 === strpos( $location, 'https://' ) || 0 === strpos( $location, 'http://' ) ) {
			$absolute = $location;
		} else {
			$parts = wp_parse_url( $current );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return new WP_Error( 'rw_wp_discovery_failed', 'The website redirect could not be followed.', array( 'status' => 502 ) );
			}

			$origin = 'https://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
			if ( 0 === strpos( $location, '/' ) ) {
				$absolute = $origin . $location;
			} else {
				$path     = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
				$dir      = trailingslashit( $origin . untrailingslashit( dirname( $path ) ) );
				$absolute = $dir . $location;
			}
		}

		$next = RW_Site_Url::assert_public_https( $absolute );
		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$target_path = (string) wp_parse_url( $absolute, PHP_URL_PATH );
		if ( strlen( $target_path ) > 1 && '/' === substr( $target_path, -1 ) && '/' !== substr( $next, -1 ) ) {
			$next .= '/';
		}

		return $next;
	}

	public static function api_url( $rest_root, $route ) {
		$route = ltrim( (string) $route, '/' );
		$root  = (string) $rest_root;

		if ( false !== strpos( $root, 'rest_route=' ) ) {
			$base = remove_query_arg( 'rest_route', $root );
			return add_query_arg( 'rest_route', '/' . $route, $base );
		}

		return trailingslashit( $root ) . $route;
	}

	/**
	 * Authenticated REST call. Redirects are not followed.
	 *
	 * @param string      $rest_root Discovered REST root.
	 * @param string      $route Route beginning with wp/ or reactwoo-.
	 * @param string      $user_login WordPress username.
	 * @param string      $password Application Password.
	 * @param string      $method HTTP method.
	 * @param array|null  $body JSON body.
	 * @param array       $headers Extra headers.
	 * @return array|WP_Error
	 */
	public static function authorized_request( $rest_root, $route, $user_login, $password, $method = 'GET', $body = null, array $headers = array() ) {
		$query = '';
		if ( false !== strpos( $route, '?' ) ) {
			list( $route, $query ) = explode( '?', $route, 2 );
		}

		$url = self::api_url( $rest_root, $route );
		$url = self::preserve_rest_route( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		if ( '' !== $query && preg_match( '/^[a-z0-9_=&]+$/i', $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
		}

		$request_headers = array_merge(
			array(
				'Authorization' => 'Basic ' . base64_encode( $user_login . ':' . $password ),
				'Accept'        => 'application/json',
			),
			$headers
		);

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $request_headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_wp_request_failed', 'The website could not be reached.', array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error( 'rw_wp_redirect_blocked', 'The website redirected an authenticated request.', array( 'status' => 502 ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return self::rest_error_from_http( $code, $data );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Keep ReactWoo error codes. Other failures stay a generic rejection.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $data Decoded JSON body.
	 * @return WP_Error
	 */
	public static function rest_error_from_http( $code, $data ) {
		$error_code = 'rw_wp_request_rejected';
		$message    = 'WordPress rejected the setup request.';
		if ( is_array( $data ) && ! empty( $data['code'] ) ) {
			$remote = sanitize_key( (string) $data['code'] );
			if ( 0 === strpos( $remote, 'rw_' ) ) {
				$error_code = $remote;
				if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
					$message = sanitize_text_field( $data['message'] );
				}
			}
		}

		return new WP_Error( $error_code, $message, array( 'status' => (int) $code ) );
	}

	public static function get_current_user( $rest_root, $user_login, $password ) {
		return self::authorized_request( $rest_root, 'wp/v2/users/me?context=edit', $user_login, $password );
	}

	public static function find_application_password_uuid( $rest_root, $user_login, $password, $app_id ) {
		$data = self::authorized_request( $rest_root, 'wp/v2/users/me/application-passwords?context=edit', $user_login, $password );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || empty( $item['uuid'] ) ) {
				continue;
			}
			if ( isset( $item['app_id'] ) && (string) $app_id === (string) $item['app_id'] ) {
				return (string) $item['uuid'];
			}
		}

		return new WP_Error( 'rw_app_password_uuid_missing', 'The setup credential could not be identified for removal.', array( 'status' => 502 ) );
	}

	public static function revoke_application_password( $rest_root, $user_login, $password, $uuid ) {
		$uuid = strtolower( (string) $uuid );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'rw_app_password_uuid_missing', 'The setup credential could not be identified for removal.', array( 'status' => 500 ) );
		}

		return self::authorized_request(
			$rest_root,
			'wp/v2/users/me/application-passwords/' . rawurlencode( $uuid ),
			$user_login,
			$password,
			'DELETE'
		);
	}

	public static function list_plugins( $rest_root, $user_login, $password ) {
		return self::authorized_request( $rest_root, 'wp/v2/plugins?per_page=100&context=edit', $user_login, $password );
	}

	public static function get_plugin( $rest_root, $user_login, $password, $plugin_file ) {
		return self::authorized_request(
			$rest_root,
			'wp/v2/plugins/' . self::plugin_route( $plugin_file ) . '?context=edit',
			$user_login,
			$password
		);
	}

	public static function activate_plugin( $rest_root, $user_login, $password, $plugin_file ) {
		$route = 'wp/v2/plugins/' . self::plugin_route( $plugin_file );

		return self::authorized_request(
			$rest_root,
			$route,
			$user_login,
			$password,
			'POST',
			array( 'status' => 'active' )
		);
	}

	public static function install_components( $rest_root, $site_id, $secret, array $slugs ) {
		$body = wp_json_encode(
			array(
				'components' => array_values( $slugs ),
			)
		);

		return self::signed_connector_request( $rest_root, '/reactwoo-connector/v2/install-components', 'POST', $body, $site_id, $secret, 90 );
	}

	/**
	 * REST roots to try for a signed connector call. Query style first, because
	 * pretty /wp-json/ is broken on the current staging hosts.
	 *
	 * @param string $site_url Public site URL.
	 * @return string[]
	 */
	public static function connector_rest_roots( $site_url ) {
		$parts = wp_parse_url( (string) $site_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array();
		}

		$origin = 'https://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		$directory = ( '/' === $path ) ? '' : $path;
		$base      = $origin . $directory;

		return array(
			$base . '/index.php?rest_route=/',
			$base . '/wp-json/',
		);
	}

	/**
	 * Signed POST to the connector. Tries the plain REST root, then pretty /wp-json/.
	 *
	 * @param string $site_url Public site URL.
	 * @param string $route REST route beginning with a slash.
	 * @param string $body Raw JSON body.
	 * @param int    $site_id Portal site id.
	 * @param string $secret Site secret.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error
	 */
	public static function connector_command( $site_url, $route, $body, $site_id, $secret, $timeout = 60 ) {
		$last = new WP_Error( 'rw_connector_unreachable', 'The website could not be reached.', array( 'status' => 502 ) );
		$saw_missing = false;
		foreach ( self::connector_rest_roots( $site_url ) as $root ) {
			$result = self::signed_connector_request( $root, $route, 'POST', (string) $body, $site_id, $secret, (int) $timeout );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last   = $result;
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			if ( 404 === $status ) {
				$saw_missing = true;
				continue;
			}
			if ( 'rw_wp_request_failed' === $result->get_error_code() ) {
				continue;
			}
			return $result;
		}
		if ( $saw_missing ) {
			return new WP_Error( 'rw_connector_route_missing', 'This website\'s connector does not support that action yet. Update the ReactWoo connector and try again.', array( 'status' => 404 ) );
		}

		return $last;
	}

	public static function connector_status( $rest_root, $site_id, $secret ) {
		return self::signed_connector_request( $rest_root, '/reactwoo-connector/v2/status', 'GET', '', $site_id, $secret, 20 );
	}

	public static function connector_identity( $rest_root, $site_id, $secret ) {
		$body = wp_json_encode( array( 'purpose' => 'identity' ) );

		return self::signed_connector_request( $rest_root, '/reactwoo-connector/v2/identity', 'POST', $body, $site_id, $secret, 20 );
	}

	/**
	 * Sign with the REST route the connector verifies, not the public URL path.
	 *
	 * @param string $rest_root Discovered REST root.
	 * @param string $route REST route beginning with a slash.
	 * @param string $method HTTP method.
	 * @param string $body Raw JSON body. Empty for GET.
	 * @param int    $site_id Portal site id.
	 * @param string $secret Site secret.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error
	 */
	public static function signed_connector_request( $rest_root, $route, $method, $body, $site_id, $secret, $timeout ) {
		$url = self::api_url( $rest_root, ltrim( $route, '/' ) );
		$url = self::preserve_rest_route( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$timestamp = (string) time();
		try {
			$nonce = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$nonce = wp_generate_password( 32, false, false );
		}

		$payload   = strtoupper( $method ) . '|' . $route . '|' . $timestamp . '|' . $nonce . '|' . hash( 'sha256', (string) $body );
		$signature = hash_hmac( 'sha256', $payload, (string) $secret );
		$headers   = array(
			'Accept'         => 'application/json',
			'X-RW-Site-ID'   => (string) (int) $site_id,
			'X-RW-Timestamp' => $timestamp,
			'X-RW-Nonce'     => $nonce,
			'X-RW-Signature' => $signature,
		);
		$args      = array(
			'method'      => strtoupper( $method ),
			'timeout'     => (int) $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
		);
		if ( '' !== (string) $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_wp_request_failed', 'The website could not be reached.', array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error( 'rw_wp_redirect_blocked', 'The website redirected an authenticated request.', array( 'status' => 502 ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error_code = ( is_array( $data ) && ! empty( $data['code'] ) ) ? sanitize_key( (string) $data['code'] ) : 'rw_connector_rejected';
			$message    = ( is_array( $data ) && ! empty( $data['message'] ) ) ? sanitize_text_field( (string) $data['message'] ) : 'The website could not finish maintenance setup.';
			return new WP_Error( $error_code, $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	public static function bootstrap_connector( $rest_root, $user_login, $password, $portal_url, $challenge, $takeover = false ) {
		$body = array(
			'portal_url' => $portal_url,
		);
		if ( RW_Connection_Orchestrator::is_explicit_takeover( $takeover ) ) {
			$body['takeover'] = true;
		}

		return self::authorized_request(
			$rest_root,
			'reactwoo-connector/v2/bootstrap',
			$user_login,
			$password,
			'POST',
			$body,
			array(
				'X-RW-Enrolment-Challenge' => $challenge,
			)
		);
	}

	public static function plugin_route( $plugin_file ) {
		$plugin_file = preg_replace( '/\.php$/', '', (string) $plugin_file );
		$parts       = explode( '/', $plugin_file );

		return rawurlencode( $parts[0] ) . ( isset( $parts[1] ) ? '/' . rawurlencode( $parts[1] ) : '' );
	}

	/**
	 * Pretty and plain REST roots for a site whose front page does not advertise one.
	 *
	 * @param string $home Canonical public site URL.
	 * @return string[]
	 */
	public static function rest_probe_urls( $home ) {
		$parts = wp_parse_url( (string) $home );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array();
		}

		$origin = 'https://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}

		$directory = ( '/' === $path ) ? '' : $path;
		$wp_json   = $origin . $directory . '/wp-json/';
		$plain     = add_query_arg( 'rest_route', '/', $origin . ( '' === $directory ? '/' : $directory ) );

		return array( $wp_json, $plain );
	}

	/**
	 * @param mixed $data Decoded JSON.
	 * @return bool
	 */
	public static function is_rest_index( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			return true;
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			return true;
		}

		return isset( $data['authentication'] ) && is_array( $data['authentication'] );
	}

	/**
	 * Link header target for rel="https://api.w.org/".
	 *
	 * @param string $value One Link header value.
	 * @return string
	 */
	public static function rest_root_from_link( $value ) {
		if ( preg_match( '#<([^>]+)>;\s*rel=([\'"])https://api\.w\.org/\2#', (string) $value, $matches ) ) {
			return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}

	/**
	 * HTML alternate API link. Attribute order and quote style are not fixed.
	 *
	 * @param string $html Front-page HTML.
	 * @return string
	 */
	public static function rest_root_from_html( $html ) {
		$html = (string) $html;
		if ( '' === $html || ! preg_match_all( '#<link\b([^>]*)>#i', $html, $tags ) ) {
			return '';
		}

		foreach ( $tags[1] as $attrs ) {
			$rel  = self::html_attribute( $attrs, 'rel' );
			$href = self::html_attribute( $attrs, 'href' );
			if ( 'https://api.w.org/' === strtolower( $rel ) && '' !== $href ) {
				return html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			}
		}

		return '';
	}

	/**
	 * @param mixed  $response HTTP response.
	 * @param string $name Header name.
	 * @return string[]
	 */
	public static function header_values( $response, $name ) {
		$name    = strtolower( (string) $name );
		$headers = function_exists( 'wp_remote_retrieve_headers' ) ? wp_remote_retrieve_headers( $response ) : null;
		$picked  = array();

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$picked = self::pick_header( $headers->getAll(), $name );
		}
		if ( empty( $picked ) && ( is_array( $headers ) || is_object( $headers ) ) ) {
			$picked = self::pick_header( $headers, $name );
		}
		if ( empty( $picked ) && function_exists( 'wp_remote_retrieve_header' ) ) {
			$picked = self::flatten_header( wp_remote_retrieve_header( $response, $name ) );
		}

		return $picked;
	}

	/**
	 * @param mixed $response HTTP response.
	 * @return string
	 */
	private static function rest_root_from_http_response( $response ) {
		foreach ( self::header_values( $response, 'link' ) as $value ) {
			$root = self::rest_root_from_link( $value );
			if ( '' !== $root ) {
				return $root;
			}
		}

		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$root = self::rest_root_from_html( $body );
		if ( '' !== $root ) {
			return $root;
		}

		return self::rest_root_from_link( $body );
	}

	/**
	 * @param string $home Canonical site URL.
	 * @return array|null
	 */
	private static function probe_rest_index( $home ) {
		foreach ( self::rest_probe_urls( $home ) as $candidate ) {
			$url = self::preserve_rest_route( $candidate );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$index = self::fetch_rest_index( $url );
			if ( is_wp_error( $index ) ) {
				continue;
			}

			return array(
				'rest_root' => $url,
				'index'     => $index,
			);
		}

		return null;
	}

	/**
	 * @param string $url REST root.
	 * @return array|WP_Error
	 */
	private static function fetch_rest_index( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'The WordPress API could not be reached.', array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! self::is_rest_index( $data ) ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'The WordPress API did not return discovery data.', array( 'status' => 502 ) );
		}

		return $data;
	}

	/**
	 * @param mixed  $response HTTP response.
	 * @param string $name Header name.
	 * @return string
	 */
	private static function first_header_value( $response, $name ) {
		$values = self::header_values( $response, $name );
		return isset( $values[0] ) ? (string) $values[0] : '';
	}

	/**
	 * @param mixed  $headers Header map or list.
	 * @param string $name Lower-case header name.
	 * @return string[]
	 */
	private static function pick_header( $headers, $name ) {
		if ( is_object( $headers ) ) {
			$headers = (array) $headers;
		}
		if ( ! is_array( $headers ) ) {
			return array();
		}

		foreach ( $headers as $key => $value ) {
			if ( is_string( $key ) && strtolower( $key ) === $name ) {
				return self::flatten_header( $value );
			}
		}

		return array();
	}

	/**
	 * @param mixed $value Header value from WordPress, which may be a list.
	 * @return string[]
	 */
	private static function flatten_header( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out = array_merge( $out, self::flatten_header( $item ) );
			}
			return $out;
		}

		if ( is_scalar( $value ) && '' !== (string) $value ) {
			return array( (string) $value );
		}

		return array();
	}

	/**
	 * @param string $attrs Raw attribute string from a link tag.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function html_attribute( $attrs, $name ) {
		$quoted = '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*([\'"])(.*?)\1#i';
		if ( preg_match( $quoted, (string) $attrs, $matches ) ) {
			return $matches[2];
		}

		$bare = '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*([^\s"\'=<>`]+)#i';
		if ( preg_match( $bare, (string) $attrs, $matches ) ) {
			return $matches[1];
		}

		return '';
	}
}
