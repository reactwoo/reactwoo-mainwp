<?php
/**
 * Bounded page check after an update and on the daily schedule.
 *
 * Only the path, status, load time, and a content flag are kept. The response
 * body is discarded in the same request that read it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Maintenance_Auditor {
	const MAX_PAGES           = 20;
	const PAGE_TIMEOUT        = 8;
	const AUDIT_BUDGET        = 45;
	const MAX_SITEMAP_BYTES   = 524288;
	const MAX_CHILD_SITEMAPS  = 3;
	const MAX_BODY_SAMPLE     = 65536;

	/**
	 * @param string   $home Home path.
	 * @param array<string,string> $commerce cart, checkout, my-account paths that exist.
	 * @param string[] $configured Extra key pages.
	 * @param string[] $sitemap Paths from the sitemap.
	 * @param int      $limit Maximum pages.
	 * @return string[]
	 */
	public static function select_paths( $home, array $commerce, array $configured, array $sitemap, $limit, $home_host = '' ) {
		$limit = (int) $limit > 0 ? (int) $limit : self::MAX_PAGES;
		$out   = array();
		$push  = function ( $path ) use ( &$out, $limit, $home_host ) {
			if ( count( $out ) >= $limit ) {
				return;
			}
			$path = self::path_for_site( $path, $home_host );
			if ( '' === $path || isset( $out[ $path ] ) ) {
				return;
			}
			$out[ $path ] = $path;
		};

		$push( '' !== (string) $home ? $home : '/' );
		foreach ( array( 'cart', 'checkout', 'my-account' ) as $key ) {
			if ( ! empty( $commerce[ $key ] ) ) {
				$push( $commerce[ $key ] );
			}
		}
		foreach ( $configured as $path ) {
			$push( $path );
		}
		foreach ( $sitemap as $path ) {
			$push( $path );
		}

		return array_values( $out );
	}

	/**
	 * @param string $home Absolute home URL.
	 * @param callable $getter fn( string $url ): string
	 * @param int    $limit Maximum page paths.
	 * @return string[]
	 */
	public static function sitemap_paths( $home, $getter, $limit ) {
		$limit = (int) $limit > 0 ? (int) $limit : self::MAX_PAGES;
		$host  = (string) wp_parse_url( (string) $home, PHP_URL_HOST );
		$index = self::absolute_url( $home, self::child_path( self::home_path( $home ), 'wp-sitemap.xml' ) );
		if ( '' === $index ) {
			return array();
		}
		$parsed = self::paths_from_sitemap( call_user_func( $getter, $index ), $host, $limit );
		$paths  = $parsed['paths'];
		$used   = 0;
		foreach ( $parsed['children'] as $child_url ) {
			if ( $used >= self::MAX_CHILD_SITEMAPS || count( $paths ) >= $limit ) {
				break;
			}
			$child = self::paths_from_sitemap( call_user_func( $getter, $child_url ), $host, $limit );
			foreach ( $child['paths'] as $path ) {
				$paths[] = $path;
			}
			$used++;
		}

		return $paths;
	}

	/**
	 * @param string $xml Sitemap or sitemap index.
	 * @param string $home_host Site host.
	 * @param int    $limit Maximum page paths.
	 * @return array{paths:string[],children:string[]}
	 */
	public static function paths_from_sitemap( $xml, $home_host, $limit ) {
		$xml = (string) $xml;
		if ( strlen( $xml ) > self::MAX_SITEMAP_BYTES ) {
			$xml = substr( $xml, 0, self::MAX_SITEMAP_BYTES );
		}
		$paths    = array();
		$children = array();
		if ( ! preg_match_all( '#<loc>\s*([^<]{1,2000})\s*</loc>#i', $xml, $matches ) ) {
			return array(
				'paths'    => array(),
				'children' => array(),
			);
		}
		$host = strtolower( (string) $home_host );
		foreach ( $matches[1] as $loc ) {
			$loc = trim( html_entity_decode( $loc, ENT_QUOTES, 'UTF-8' ) );
			$parts = wp_parse_url( $loc );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			if ( strtolower( (string) $parts['host'] ) !== $host ) {
				continue;
			}
			$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
			if ( 'https' !== $scheme && 'http' !== $scheme ) {
				continue;
			}
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			if ( preg_match( '#sitemap[^/]*\.xml$#i', $path ) ) {
				if ( count( $children ) < self::MAX_CHILD_SITEMAPS ) {
					$children[] = $scheme . '://' . $parts['host'] . $path;
				}
				continue;
			}
			$normal = self::normalise_path( $path );
			if ( '' === $normal ) {
				continue;
			}
			$paths[] = $normal;
			if ( count( $paths ) >= (int) $limit ) {
				break;
			}
		}

		return array(
			'paths'    => $paths,
			'children' => $children,
		);
	}

	/**
	 * @param string[] $paths Paths to request.
	 * @param callable $transport fn( string $path ): array{status:int,body?:string,error?:string,load_time_ms?:int}
	 * @param int      $budget_seconds Stop after this many seconds. Zero still allows the first page.
	 * @return array<int,array<string,mixed>>
	 */
	public static function check_paths( array $paths, $transport, $budget_seconds ) {
		$started = microtime( true );
		$pages   = array();
		foreach ( $paths as $path ) {
			if ( (int) $budget_seconds >= 0 && ( microtime( true ) - $started ) > (float) $budget_seconds && ! empty( $pages ) ) {
				break;
			}
			$result = call_user_func( $transport, $path );
			if ( ! is_array( $result ) ) {
				$result = array(
					'status' => 0,
					'error'  => 'The page did not respond.',
				);
			}
			$pages[] = self::judge( $path, $result );
		}

		return $pages;
	}

	/**
	 * @param string              $path Requested path.
	 * @param array<string,mixed> $result Transport result. The body is not returned.
	 * @return array{url:string,status:int,load_time_ms:int,content_present:bool,note:string}
	 */
	public static function judge( $path, array $result ) {
		$status = isset( $result['status'] ) ? (int) $result['status'] : 0;
		$body   = isset( $result['body'] ) ? (string) $result['body'] : '';
		if ( strlen( $body ) > self::MAX_BODY_SAMPLE ) {
			$body = substr( $body, 0, self::MAX_BODY_SAMPLE );
		}
		$error   = isset( $result['error'] ) ? (string) $result['error'] : '';
		$elapsed = isset( $result['load_time_ms'] ) ? (int) $result['load_time_ms'] : 0;
		$present = self::content_present( $status, $body );
		unset( $body );

		return array(
			'url'             => self::normalise_path( $path ),
			'status'          => $status,
			'load_time_ms'    => max( 0, $elapsed ),
			'content_present' => $present,
			'note'            => self::failure_note( $status, $present, $error ),
		);
	}

	/**
	 * @param int    $status HTTP status.
	 * @param string $body Response body sample. Not retained by the caller.
	 * @return bool
	 */
	public static function content_present( $status, $body ) {
		unset( $status );
		$text = trim( strip_tags( (string) $body ) );

		return strlen( $text ) >= 1 && strlen( (string) $body ) >= 32;
	}

	/**
	 * @param int    $status HTTP status.
	 * @param bool   $present Content flag.
	 * @param string $error Transport error, used only to choose a fixed note.
	 * @return string Empty when the page is fine.
	 */
	public static function failure_note( $status, $present, $error ) {
		$status = (int) $status;
		if ( $status >= 200 && $status < 300 && $present ) {
			return '';
		}
		$error = strtolower( (string) $error );
		if ( false !== strpos( $error, 'timed out' ) || false !== strpos( $error, 'curl error 28' ) ) {
			return 'The page timed out.';
		}
		if ( '' !== $error && $status < 200 ) {
			return 'The page did not respond.';
		}
		if ( 404 === $status ) {
			return 'The page was not found.';
		}
		if ( ! $present && ( ( $status >= 500 ) || ( $status >= 200 && $status < 300 ) || 0 === $status ) ) {
			return 'empty response';
		}
		if ( $status >= 400 ) {
			return 'The page returned an error.';
		}
		if ( $status >= 300 ) {
			return 'The page redirected without a result.';
		}
		if ( ! $present ) {
			return 'empty response';
		}

		return 'The page did not respond.';
	}

	/**
	 * @param array<int,array<string,mixed>> $pages Judged pages.
	 * @return array{pages_checked:int,failures:array<int,array{url:string,status:int,note:string}>}
	 */
	public static function summarise( array $pages ) {
		$failures = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$note = isset( $page['note'] ) ? (string) $page['note'] : '';
			if ( '' === $note ) {
				continue;
			}
			$failures[] = array(
				'url'    => self::normalise_path( isset( $page['url'] ) ? $page['url'] : '' ),
				'status' => isset( $page['status'] ) ? (int) $page['status'] : 0,
				'note'   => $note,
			);
		}

		return array(
			'pages_checked' => count( $pages ),
			'failures'      => $failures,
		);
	}

	/**
	 * @param array<string,mixed> $page Judged page.
	 * @return array{url:string,status:int,load_time_ms:int,content_present:bool}
	 */
	public static function local_page( array $page ) {
		return array(
			'url'             => self::normalise_path( isset( $page['url'] ) ? $page['url'] : '' ),
			'status'          => isset( $page['status'] ) ? (int) $page['status'] : 0,
			'load_time_ms'    => isset( $page['load_time_ms'] ) ? max( 0, (int) $page['load_time_ms'] ) : 0,
			'content_present' => ! empty( $page['content_present'] ),
		);
	}

	/**
	 * @return string[]
	 */
	public static function gather_paths() {
		$home       = function_exists( 'home_url' ) ? (string) home_url() : '';
		$commerce   = self::commerce_paths();
		$configured = get_option( 'rw_connector_audit_pages', array() );
		if ( ! is_array( $configured ) ) {
			$configured = array();
		}
		$configured = apply_filters( 'rw_connector_audit_key_pages', $configured );
		if ( ! is_array( $configured ) ) {
			$configured = array();
		}
		$sitemap = self::sitemap_paths(
			$home,
			function ( $url ) {
				$response = wp_remote_get(
					$url,
					array(
						'timeout'             => self::PAGE_TIMEOUT,
						'redirection'         => 2,
						'sslverify'           => true,
						'limit_response_size' => self::MAX_SITEMAP_BYTES,
					)
				);
				if ( is_wp_error( $response ) ) {
					return '';
				}
				if ( (int) wp_remote_retrieve_response_code( $response ) < 200 || (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
					return '';
				}

				return (string) wp_remote_retrieve_body( $response );
			},
			self::MAX_PAGES
		);

		return self::select_paths( self::home_path( $home ), $commerce, $configured, $sitemap, self::MAX_PAGES, (string) wp_parse_url( $home, PHP_URL_HOST ) );
	}

	/**
	 * Keep a path on this site. An absolute URL for another host is dropped.
	 *
	 * @param mixed  $value Path or absolute URL.
	 * @param string $home_host Site host. Empty skips the host check.
	 * @return string
	 */
	public static function path_for_site( $value, $home_host = '' ) {
		$value = trim( (string) $value );
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $value ) && '' !== (string) $home_host ) {
			$host = wp_parse_url( $value, PHP_URL_HOST );
			if ( ! is_string( $host ) || strtolower( $host ) !== strtolower( (string) $home_host ) ) {
				return '';
			}
		}

		return self::normalise_path( $value );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function audit_site() {
		$home  = function_exists( 'home_url' ) ? (string) home_url() : '';
		$paths = self::gather_paths();

		return self::check_paths(
			$paths,
			function ( $path ) use ( $home ) {
				$url   = self::absolute_url( $home, $path );
				$start = microtime( true );
				$response = wp_remote_get(
					$url,
					array(
						'timeout'             => self::PAGE_TIMEOUT,
						'redirection'         => 3,
						'sslverify'           => true,
						'limit_response_size' => self::MAX_BODY_SAMPLE,
					)
				);
				$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );
				if ( is_wp_error( $response ) ) {
					return array(
						'status'       => 0,
						'body'         => '',
						'error'        => (string) $response->get_error_message(),
						'load_time_ms' => $elapsed,
					);
				}

				return array(
					'status'       => (int) wp_remote_retrieve_response_code( $response ),
					'body'         => (string) wp_remote_retrieve_body( $response ),
					'error'        => '',
					'load_time_ms' => $elapsed,
				);
			},
			self::AUDIT_BUDGET
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function commerce_paths() {
		$map = array(
			'cart'       => 'woocommerce_cart_page_id',
			'checkout'   => 'woocommerce_checkout_page_id',
			'my-account' => 'woocommerce_myaccount_page_id',
		);
		$out = array();
		foreach ( $map as $key => $option ) {
			$id = (int) get_option( $option, 0 );
			if ( $id < 1 || ! function_exists( 'get_permalink' ) ) {
				continue;
			}
			$url = get_permalink( $id );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$path = self::normalise_path( $url );
			if ( '' !== $path ) {
				$out[ $key ] = $path;
			}
		}

		return $out;
	}

	/**
	 * @param string $home Absolute home URL.
	 * @return string
	 */
	public static function home_path( $home ) {
		$path = wp_parse_url( (string) $home, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			return '/';
		}
		$normal = self::normalise_path( $path );

		return '' !== $normal ? $normal : '/';
	}

	/**
	 * @param string $home_path Home path.
	 * @param string $relative Relative file or path.
	 * @return string
	 */
	public static function child_path( $home_path, $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		if ( '/' === (string) $home_path ) {
			return '/' . $relative;
		}

		return rtrim( (string) $home_path, '/' ) . '/' . $relative;
	}

	/**
	 * @param string $home Absolute home URL.
	 * @param string $path Site path.
	 * @return string
	 */
	public static function absolute_url( $home, $path ) {
		$parts = wp_parse_url( (string) $home );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$path   = self::normalise_path( $path );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		if ( '' === $path ) {
			return '';
		}

		return $scheme . '://' . $parts['host'] . $port . $path;
	}

	/**
	 * @param mixed $value Path or absolute URL.
	 * @return string
	 */
	public static function normalise_path( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $value ) ) {
			$path  = wp_parse_url( $value, PHP_URL_PATH );
			$value = is_string( $path ) ? $path : '/';
		}
		if ( false !== strpos( $value, '\\' ) || false !== strpos( $value, "\n" ) || false !== strpos( $value, "\r" ) ) {
			return '';
		}
		$value = rawurldecode( $value );
		$query = strpos( $value, '?' );
		if ( false !== $query ) {
			$value = substr( $value, 0, $query );
		}
		$hash = strpos( $value, '#' );
		if ( false !== $hash ) {
			$value = substr( $value, 0, $hash );
		}
		$trailing = '/' === substr( $value, -1 );
		$parts    = array();
		foreach ( explode( '/', $value ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return '';
			}
			$parts[] = $part;
		}
		$path = '/' . implode( '/', $parts );
		if ( $trailing && ! empty( $parts ) ) {
			$path .= '/';
		}
		if ( strlen( $path ) > 200 ) {
			return '';
		}

		return $path;
	}
}
