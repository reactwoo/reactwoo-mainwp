<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Site_Url {
	const MAX_REDIRECTS = 3;

	/**
	 * Require a public HTTPS URL. Returns the canonical URL string or WP_Error.
	 *
	 * @param string $url Customer site URL.
	 * @return string|WP_Error
	 */
	public static function assert_public_https( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error( 'rw_site_url_invalid', 'Enter a full https website address.', array( 'status' => 400 ) );
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'rw_site_url_https', 'Automated setup requires HTTPS.', array( 'status' => 400 ) );
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new WP_Error( 'rw_site_url_invalid', 'Website address cannot include credentials.', array( 'status' => 400 ) );
		}

		$host = strtolower( (string) $parts['host'] );
		if ( self::is_blocked_host( $host ) ) {
			return new WP_Error( 'rw_site_url_blocked', 'That website address cannot be used for setup.', array( 'status' => 400 ) );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) && self::is_blocked_ip( $host ) ) {
			return new WP_Error( 'rw_site_url_blocked', 'That website address cannot be used for setup.', array( 'status' => 400 ) );
		}

		$ips = self::resolve_ips( $host );
		if ( empty( $ips ) ) {
			return new WP_Error( 'rw_site_url_unresolved', 'The website address could not be resolved.', array( 'status' => 400 ) );
		}

		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				return new WP_Error( 'rw_site_url_blocked', 'That website address cannot be used for setup.', array( 'status' => 400 ) );
			}
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		$path = preg_replace( '#/+#', '/', $path );
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}

		return 'https://' . $host . $port . $path;
	}

	/**
	 * Equality key. www is ignored. Other subdomains stay distinct.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public static function equality_key( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';

		return $host . $port . $path;
	}

	public static function same_site( $left, $right ) {
		$a = self::equality_key( $left );
		$b = self::equality_key( $right );

		return '' !== $a && $a === $b;
	}

	/**
	 * @param string $host Hostname or IP.
	 * @return bool
	 */
	public static function is_blocked_host( $host ) {
		$host = strtolower( trim( (string) $host, '.' ) );
		$blocked = array(
			'localhost',
			'localhost.localdomain',
			'metadata',
			'metadata.google.internal',
			'instance-data',
		);

		if ( in_array( $host, $blocked, true ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(localhost|local|internal)$/', $host );
	}

	/**
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_blocked_ip( $ip ) {
		$ip = strtolower( (string) $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		if ( '169.254.169.254' === $ip || 'fd00:ec2::254' === $ip ) {
			return true;
		}

		return false;
	}

	/**
	 * @param string $host Public hostname.
	 * @return string[]
	 */
	private static function resolve_ips( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_A + DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$resolved = gethostbynamel( $host );
			if ( is_array( $resolved ) ) {
				$ips = $resolved;
			}
		}

		return array_values( array_unique( $ips ) );
	}
}
