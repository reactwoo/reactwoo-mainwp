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

		$rest_root = '';
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
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
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

			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'rw_wp_discovery_failed', 'The website did not respond for setup.', array( 'status' => 502 ) );
			}

			$rest_root = self::rest_root_from_link( (string) wp_remote_retrieve_header( $response, 'link' ) );
			if ( '' === $rest_root ) {
				$rest_root = self::rest_root_from_link( (string) wp_remote_retrieve_body( $response ) );
			}
			break;
		}

		if ( '' === $rest_root ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'WordPress REST discovery did not return an API address.', array( 'status' => 502 ) );
		}

		$raw_rest  = $rest_root;
		$rest_root = self::preserve_rest_route( $raw_rest );
		if ( is_wp_error( $rest_root ) ) {
			return $rest_root;
		}

		$index = wp_remote_get(
			$rest_root,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $index ) ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'The WordPress API could not be reached.', array( 'status' => 502 ) );
		}

		$index_code = (int) wp_remote_retrieve_response_code( $index );
		$body       = json_decode( (string) wp_remote_retrieve_body( $index ), true );
		if ( $index_code < 200 || $index_code >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'rw_wp_discovery_failed', 'The WordPress API did not return discovery data.', array( 'status' => 502 ) );
		}

		$authorization = '';
		if ( isset( $body['authentication']['application-passwords']['endpoints']['authorization'] ) ) {
			$authorization = (string) $body['authentication']['application-passwords']['endpoints']['authorization'];
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

		$query = array();
		parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
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
			return new WP_Error( 'rw_wp_request_rejected', 'WordPress rejected the setup request.', array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	public static function get_current_user( $rest_root, $user_login, $password ) {
		return self::authorized_request( $rest_root, 'wp/v2/users/me?context=edit', $user_login, $password );
	}

	public static function find_application_password_uuid( $rest_root, $user_login, $password, $app_id ) {
		$data = self::authorized_request( $rest_root, 'wp/v2/users/me/application-passwords', $user_login, $password );
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

	public static function bootstrap_connector( $rest_root, $user_login, $password, $portal_url, $challenge ) {
		return self::authorized_request(
			$rest_root,
			'reactwoo-connector/v2/bootstrap',
			$user_login,
			$password,
			'POST',
			array(
				'portal_url' => $portal_url,
			),
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

	private static function rest_root_from_link( $value ) {
		if ( preg_match( '#<([^>]+)>;\s*rel="https://api\.w\.org/"#', (string) $value, $matches ) ) {
			return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}
}
