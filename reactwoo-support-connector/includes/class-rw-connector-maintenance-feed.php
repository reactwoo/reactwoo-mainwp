<?php
/**
 * Wordfence Intelligence v3 feed, reduced to a small on-site cache.
 *
 * The production feed is a full vulnerability database and is too large to
 * pull on a managed site. The scanner feed is the detection feed. Both need
 * a free Wordfence account key. Records for plugins that are not installed
 * are discarded, and the raw feed is not stored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Maintenance_Feed {
	const SCANNER_URL    = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';
	const PRODUCTION_URL = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production';
	const OPTION         = 'rw_connector_wf_feed_cache';
	const DEFAULT_TTL    = 43200;
	const MAX_BODY_BYTES = 33554432;
	const MAX_RECORD     = 524288;
	const EXCERPT_LENGTH = 180;

	/**
	 * @param string $url Candidate feed URL.
	 * @return string Scanner or production URL on www.wordfence.com.
	 */
	public static function allowed_feed_url( $url ) {
		$url = (string) $url;
		if ( preg_match( '#^https://www\.wordfence\.com/api/intelligence/v3/vulnerabilities/(scanner|production)$#', $url ) ) {
			return $url;
		}

		return self::SCANNER_URL;
	}

	/**
	 * @return string
	 */
	public static function api_key() {
		$key = apply_filters( 'rw_connector_wordfence_intelligence_key', '' );
		if ( is_string( $key ) && '' !== trim( $key ) ) {
			return trim( $key );
		}

		$stored = get_option( 'rw_connector_wordfence_intelligence_key', '' );

		return is_string( $stored ) ? trim( $stored ) : '';
	}

	/**
	 * @param string $api_key Key sent only to the Wordfence host.
	 * @param string $filename Stream destination. Empty skips streaming.
	 * @return array<string,mixed>
	 */
	public static function request_args( $api_key, $filename ) {
		$args = array(
			'timeout'             => 20,
			'redirection'         => 2,
			'sslverify'           => true,
			'limit_response_size' => self::MAX_BODY_BYTES,
			'headers'             => array(
				'Authorization' => 'Bearer ' . (string) $api_key,
				'Accept'        => 'application/json',
			),
		);
		if ( '' !== (string) $filename ) {
			$args['stream']   = true;
			$args['filename'] = (string) $filename;
		}

		return $args;
	}

	/**
	 * @param string[]             $slugs Slugs to keep.
	 * @param string               $api_key Wordfence key. Empty skips the network.
	 * @param int                  $now Unix time.
	 * @param array<string,mixed>  $cache Previous cache.
	 * @param int                  $ttl Seconds.
	 * @param callable             $fetcher fn( string $url, string $api_key ): string|WP_Error
	 * @param string               $url Feed URL.
	 * @return array{findings:array<string,array<int,array<string,mixed>>>,cache:array<string,mixed>,fetched:bool,reason:string}
	 */
	public static function resolve( array $slugs, $api_key, $now, array $cache, $ttl, $fetcher, $url ) {
		$slugs = self::normalise_slugs( $slugs );
		$url   = self::allowed_feed_url( $url );
		$now   = (int) $now;
		$ttl   = (int) $ttl > 0 ? (int) $ttl : self::DEFAULT_TTL;

		if ( self::cache_covers( $cache, $slugs, $now, $ttl ) ) {
			return array(
				'findings' => self::findings_for( $cache, $slugs ),
				'cache'    => $cache,
				'fetched'  => false,
				'reason'   => 'cached',
			);
		}

		if ( '' === trim( (string) $api_key ) ) {
			return array(
				'findings' => self::findings_for( $cache, $slugs ),
				'cache'    => $cache,
				'fetched'  => false,
				'reason'   => 'unconfigured',
			);
		}

		$body = call_user_func( $fetcher, $url, (string) $api_key );
		if ( is_wp_error( $body ) ) {
			return array(
				'findings' => self::findings_for( $cache, $slugs ),
				'cache'    => $cache,
				'fetched'  => false,
				'reason'   => (string) $body->get_error_code(),
			);
		}

		$parsed = self::records_from_body( (string) $body, $slugs );
		if ( is_wp_error( $parsed ) ) {
			return array(
				'findings' => self::findings_for( $cache, $slugs ),
				'cache'    => $cache,
				'fetched'  => false,
				'reason'   => (string) $parsed->get_error_code(),
			);
		}

		$stored = array();
		foreach ( $slugs as $slug ) {
			if ( ! empty( $parsed[ $slug ] ) ) {
				$stored[ $slug ] = self::top_findings( $parsed[ $slug ] );
			}
		}

		$fresh = array(
			'fetched_at' => $now,
			'covered'    => $slugs,
			'findings'   => $stored,
		);

		return array(
			'findings' => $stored,
			'cache'    => $fresh,
			'fetched'  => true,
			'reason'   => 'fetched',
		);
	}

	/**
	 * @param string[] $slugs Installed or updatable slugs.
	 * @return array{findings:array<string,array<int,array<string,mixed>>>,cache:array<string,mixed>,fetched:bool,reason:string}
	 */
	public static function current( array $slugs ) {
		$cache = get_option( self::OPTION, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		$ttl = (int) apply_filters( 'rw_connector_wordfence_feed_ttl', self::DEFAULT_TTL );
		$url = self::allowed_feed_url( apply_filters( 'rw_connector_wordfence_feed_url', self::SCANNER_URL ) );
		$result = self::resolve( $slugs, self::api_key(), time(), $cache, $ttl, array( __CLASS__, 'fetch_body' ), $url );
		if ( ! empty( $result['fetched'] ) ) {
			update_option( self::OPTION, $result['cache'], false );
		}

		return $result;
	}

	/**
	 * @param string $url Allowed feed URL.
	 * @param string $api_key Wordfence key.
	 * @return string|WP_Error
	 */
	public static function fetch_body( $url, $api_key ) {
		$url = self::allowed_feed_url( $url );
		$tmp = self::temp_path();
		$response = wp_remote_get( $url, self::request_args( $api_key, $tmp ) );
		if ( is_wp_error( $response ) ) {
			self::delete_file( $tmp );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			self::delete_file( $tmp );
			return new WP_Error( 'rw_feed_limited', 'The vulnerability feed is rate limited. The previous cache was kept.' );
		}
		if ( 401 === $code || 403 === $code ) {
			self::delete_file( $tmp );
			return new WP_Error( 'rw_feed_unauthorised', 'The vulnerability feed rejected the API key. The previous cache was kept.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			self::delete_file( $tmp );
			return new WP_Error( 'rw_feed_http', 'The vulnerability feed could not be fetched. The previous cache was kept.' );
		}

		if ( '' !== $tmp && is_file( $tmp ) && filesize( $tmp ) > 0 ) {
			$size = (int) filesize( $tmp );
			if ( $size > self::MAX_BODY_BYTES ) {
				self::delete_file( $tmp );
				return new WP_Error( 'rw_feed_too_large', 'The vulnerability feed is larger than this website will download. The previous cache was kept.' );
			}
			$raw = file_get_contents( $tmp );
			self::delete_file( $tmp );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return new WP_Error( 'rw_feed_empty', 'The vulnerability feed was empty. The previous cache was kept.' );
			}

			return $raw;
		}

		self::delete_file( $tmp );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'rw_feed_too_large', 'The vulnerability feed is larger than this website will download. The previous cache was kept.' );
		}
		if ( '' === $body ) {
			return new WP_Error( 'rw_feed_empty', 'The vulnerability feed was empty. The previous cache was kept.' );
		}

		return $body;
	}

	/**
	 * Walk a JSON array or object and keep findings for the wanted slugs.
	 *
	 * @param string   $body Raw feed.
	 * @param string[] $wanted Slugs to keep. Empty keeps every slug.
	 * @return array<string,array<int,array<string,mixed>>>|WP_Error
	 */
	public static function records_from_body( $body, array $wanted = array() ) {
		$body = (string) $body;
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'rw_feed_too_large', 'The vulnerability feed is larger than this website will download. The previous cache was kept.' );
		}
		if ( strncmp( $body, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$body = substr( $body, 3 );
		}

		$handle = fopen( 'php://temp', 'rb+' );
		if ( false === $handle ) {
			return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed could not be read. The previous cache was kept.' );
		}
		fwrite( $handle, $body );
		rewind( $handle );
		$parsed = self::collect_from_handle( $handle, $wanted );
		fclose( $handle );

		return $parsed;
	}

	/**
	 * @param resource $handle Open feed.
	 * @param string[] $wanted Slugs to keep.
	 * @return array<string,array<int,array<string,mixed>>>|WP_Error
	 */
	public static function collect_from_handle( $handle, array $wanted ) {
		$buffer = '';
		$pos    = 0;
		$first  = self::skip_ws( $handle, $buffer, $pos );
		if ( '[' !== $first && '{' !== $first ) {
			return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed was not a JSON list. The previous cache was kept.' );
		}

		$is_map = '{' === $first;
		$lookup = array();
		foreach ( self::normalise_slugs( $wanted ) as $slug ) {
			$lookup[ $slug ] = true;
		}
		$findings   = array();
		$saw_record = false;
		$closed     = false;
		$count      = 0;

		while ( true ) {
			$char = self::skip_ws( $handle, $buffer, $pos );
			if ( '' === $char ) {
				return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed ended before a record finished. The previous cache was kept.' );
			}
			if ( ( $is_map && '}' === $char ) || ( ! $is_map && ']' === $char ) ) {
				$closed = true;
				break;
			}
			if ( ',' === $char ) {
				continue;
			}
			if ( $is_map ) {
				if ( '"' !== $char ) {
					return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed was not a JSON list. The previous cache was kept.' );
				}
				$skipped = self::skip_string( $handle, $buffer, $pos );
				if ( is_wp_error( $skipped ) ) {
					return $skipped;
				}
				$colon = self::skip_ws( $handle, $buffer, $pos );
				if ( ':' !== $colon ) {
					return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed was not a JSON list. The previous cache was kept.' );
				}
				$char = self::skip_ws( $handle, $buffer, $pos );
			}
			if ( '{' !== $char && '[' !== $char ) {
				$skipped = self::skip_primitive( $handle, $buffer, $pos, $char );
				if ( is_wp_error( $skipped ) ) {
					return $skipped;
				}
				continue;
			}

			$raw = self::read_composite( $handle, $buffer, $pos, $char );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			$saw_record = true;
			$count++;
			if ( $count > 200000 ) {
				return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed was not a JSON list. The previous cache was kept.' );
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$rows = self::findings_from_records( array( $decoded ), $lookup );
			foreach ( $rows as $slug => $list ) {
				foreach ( $list as $row ) {
					$findings[ $slug ][] = $row;
				}
			}
		}

		if ( ! $closed ) {
			return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed ended before a record finished. The previous cache was kept.' );
		}
		if ( $is_map && ! $saw_record ) {
			return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed was not a JSON list. The previous cache was kept.' );
		}

		return $findings;
	}

	/**
	 * @param array<int,mixed>                  $records Feed records.
	 * @param array<string,bool>|string[]       $wanted Slug set or list. Empty keeps all.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function findings_from_records( array $records, array $wanted = array() ) {
		$lookup = $wanted;
		if ( ! empty( $wanted ) ) {
			$keys = array_keys( $wanted );
			if ( isset( $keys[0] ) && 0 === $keys[0] ) {
				$lookup = array();
				foreach ( self::normalise_slugs( $wanted ) as $slug ) {
					$lookup[ $slug ] = true;
				}
			}
		}

		$findings = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$software = isset( $record['software'] ) && is_array( $record['software'] ) ? $record['software'] : array();
			$severity = self::severity_from_record( $record );
			$title    = isset( $record['title'] ) ? (string) $record['title'] : '';
			$detail   = isset( $record['description'] ) ? (string) $record['description'] : '';
			foreach ( $software as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$slug = isset( $item['slug'] ) ? sanitize_key( (string) $item['slug'] ) : '';
				$type = isset( $item['type'] ) ? strtolower( (string) $item['type'] ) : '';
				if ( '' === $slug || ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
					continue;
				}
				if ( ! empty( $lookup ) && empty( $lookup[ $slug ] ) ) {
					continue;
				}
				$text = $title;
				if ( ! empty( $item['remediation'] ) && is_string( $item['remediation'] ) ) {
					$text = (string) $item['remediation'];
				} elseif ( '' === $text ) {
					$text = $detail;
				}
				$excerpt = self::excerpt( $text );
				foreach ( self::range_rows( isset( $item['affected_versions'] ) ? $item['affected_versions'] : array() ) as $range ) {
					$findings[ $slug ][] = array(
						'type'           => $type,
						'from'           => $range['from'],
						'from_inclusive' => $range['from_inclusive'],
						'to'             => $range['to'],
						'to_inclusive'   => $range['to_inclusive'],
						'severity'       => $severity,
						'excerpt'        => $excerpt,
					);
				}
			}
		}

		return $findings;
	}

	/**
	 * @param string $version Installed version.
	 * @param array<string,mixed> $range Normalised range.
	 * @return bool
	 */
	public static function version_in_range( $version, array $range ) {
		$version = trim( (string) $version );
		if ( '' === $version ) {
			return false;
		}

		$from = isset( $range['from'] ) ? (string) $range['from'] : '*';
		$to   = isset( $range['to'] ) ? (string) $range['to'] : '*';
		if ( '*' !== $from && '' !== $from ) {
			$cmp = version_compare( $version, $from );
			$ok  = ! empty( $range['from_inclusive'] ) ? $cmp >= 0 : $cmp > 0;
			if ( ! $ok ) {
				return false;
			}
		}
		if ( '*' !== $to && '' !== $to ) {
			$cmp = version_compare( $version, $to );
			$ok  = ! empty( $range['to_inclusive'] ) ? $cmp <= 0 : $cmp < 0;
			if ( ! $ok ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $text Source text, possibly HTML.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function excerpt( $text, $limit = self::EXCERPT_LENGTH ) {
		$text  = wp_strip_all_tags( (string) $text );
		$text  = str_replace( array( "\r\n", "\r", "\n", "\t", "\u{2014}", "\u{2013}" ), ' ', $text );
		$text  = trim( (string) preg_replace( '/ {2,}/', ' ', $text ) );
		$limit = (int) $limit > 0 ? (int) $limit : self::EXCERPT_LENGTH;
		if ( self::length( $text ) <= $limit ) {
			return $text;
		}
		$room  = max( 1, $limit - 3 );
		$cut   = self::substr( $text, 0, $room );
		$space = self::last_space( $cut );
		if ( $space > 40 ) {
			$cut = self::substr( $cut, 0, $space );
		}

		return rtrim( $cut, " .,;:" ) . '...';
	}

	/**
	 * @param array<string,mixed> $record One feed record.
	 * @return string
	 */
	public static function severity_from_record( array $record ) {
		$cvss = isset( $record['cvss'] ) && is_array( $record['cvss'] ) ? $record['cvss'] : array();
		$rating = isset( $cvss['rating'] ) ? strtolower( trim( (string) $cvss['rating'] ) ) : '';
		$map    = array(
			'critical' => 'critical',
			'high'     => 'high',
			'medium'   => 'medium',
			'low'      => 'low',
		);
		if ( isset( $map[ $rating ] ) ) {
			return $map[ $rating ];
		}
		if ( isset( $cvss['score'] ) && is_numeric( $cvss['score'] ) ) {
			$score = (float) $cvss['score'];
			if ( $score >= 9 ) {
				return 'critical';
			}
			if ( $score >= 7 ) {
				return 'high';
			}
			if ( $score >= 4 ) {
				return 'medium';
			}
			if ( $score > 0 ) {
				return 'low';
			}
		}

		return 'unknown';
	}

	/**
	 * @param string $hint Severity hint.
	 * @return int
	 */
	public static function severity_rank( $hint ) {
		$order = array(
			''        => 0,
			'unknown' => 1,
			'low'     => 2,
			'medium'  => 3,
			'high'    => 4,
			'critical'=> 5,
		);

		return isset( $order[ (string) $hint ] ) ? $order[ (string) $hint ] : 1;
	}

	/**
	 * @param mixed $affected Feed affected_versions value.
	 * @return array<int,array{from:string,from_inclusive:bool,to:string,to_inclusive:bool}>
	 */
	private static function range_rows( $affected ) {
		if ( ! is_array( $affected ) ) {
			return array();
		}
		if ( isset( $affected['from_version'] ) || isset( $affected['to_version'] ) ) {
			$affected = array( $affected );
		}
		$rows = array();
		foreach ( $affected as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$from = isset( $row['from_version'] ) ? trim( (string) $row['from_version'] ) : '*';
			$to   = isset( $row['to_version'] ) ? trim( (string) $row['to_version'] ) : '*';
			if ( '' === $from ) {
				$from = '*';
			}
			if ( '' === $to ) {
				$to = '*';
			}
			$rows[] = array(
				'from'           => $from,
				'from_inclusive' => array_key_exists( 'from_inclusive', $row ) ? (bool) $row['from_inclusive'] : true,
				'to'             => $to,
				'to_inclusive'   => array_key_exists( 'to_inclusive', $row ) ? (bool) $row['to_inclusive'] : true,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Findings for one slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function top_findings( array $rows ) {
		usort(
			$rows,
			function ( $left, $right ) {
				$rank = self::severity_rank( isset( $right['severity'] ) ? $right['severity'] : '' ) - self::severity_rank( isset( $left['severity'] ) ? $left['severity'] : '' );
				if ( 0 !== $rank ) {
					return $rank;
				}
				$left_text  = isset( $left['excerpt'] ) ? (string) $left['excerpt'] : '';
				$right_text = isset( $right['excerpt'] ) ? (string) $right['excerpt'] : '';
				return ( '' === $left_text ? 1 : 0 ) - ( '' === $right_text ? 1 : 0 );
			}
		);

		return array_slice( $rows, 0, 5 );
	}

	/**
	 * @param array<string,mixed> $cache Cache row.
	 * @param string[]            $slugs Wanted slugs.
	 * @param int                 $now Unix time.
	 * @param int                 $ttl Seconds.
	 * @return bool
	 */
	private static function cache_covers( array $cache, array $slugs, $now, $ttl ) {
		if ( empty( $cache['fetched_at'] ) || empty( $cache['covered'] ) || ! is_array( $cache['covered'] ) ) {
			return false;
		}
		$age = (int) $now - (int) $cache['fetched_at'];
		if ( $age < 0 || $age > (int) $ttl ) {
			return false;
		}
		$covered = array();
		foreach ( $cache['covered'] as $slug ) {
			$covered[ (string) $slug ] = true;
		}
		foreach ( $slugs as $slug ) {
			if ( empty( $covered[ $slug ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $cache Cache row.
	 * @param string[]            $slugs Wanted slugs.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function findings_for( array $cache, array $slugs ) {
		$stored = isset( $cache['findings'] ) && is_array( $cache['findings'] ) ? $cache['findings'] : array();
		$out    = array();
		foreach ( $slugs as $slug ) {
			if ( ! empty( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ) {
				$out[ $slug ] = $stored[ $slug ];
			}
		}

		return $out;
	}

	/**
	 * @param string[] $slugs Raw slugs.
	 * @return string[]
	 */
	private static function normalise_slugs( array $slugs ) {
		$out = array();
		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug ) {
				$out[ $slug ] = $slug;
			}
		}

		return array_values( $out );
	}

	/**
	 * @param resource $handle Stream.
	 * @param string   $buffer Unconsumed bytes.
	 * @param int      $pos Offset into the buffer.
	 * @return string
	 */
	private static function skip_ws( $handle, &$buffer, &$pos ) {
		while ( true ) {
			$char = self::pull( $handle, $buffer, $pos );
			if ( '' === $char ) {
				return '';
			}
			if ( ! ctype_space( $char ) ) {
				return $char;
			}
		}
	}

	/**
	 * @param resource $handle Stream.
	 * @param string   $buffer Unconsumed bytes.
	 * @param int      $pos Offset.
	 * @return true|WP_Error
	 */
	private static function skip_string( $handle, &$buffer, &$pos ) {
		$escape = false;
		$read   = 0;
		while ( true ) {
			$char = self::pull( $handle, $buffer, $pos );
			if ( '' === $char ) {
				return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed ended before a record finished. The previous cache was kept.' );
			}
			$read++;
			if ( $read > self::MAX_RECORD ) {
				return new WP_Error( 'rw_feed_invalid', 'A vulnerability record was too large to read. The previous cache was kept.' );
			}
			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escape = true;
				continue;
			}
			if ( '"' === $char ) {
				return true;
			}
		}
	}

	/**
	 * @param resource $handle Stream.
	 * @param string   $buffer Unconsumed bytes.
	 * @param int      $pos Offset.
	 * @param string   $first First character already read.
	 * @return true|WP_Error
	 */
	private static function skip_primitive( $handle, &$buffer, &$pos, $first ) {
		if ( '"' === $first ) {
			return self::skip_string( $handle, $buffer, $pos );
		}
		$read = 1;
		while ( true ) {
			$char = self::peek( $handle, $buffer, $pos );
			if ( '' === $char || ctype_space( $char ) || ',' === $char || ']' === $char || '}' === $char ) {
				return true;
			}
			self::pull( $handle, $buffer, $pos );
			$read++;
			if ( $read > 128 ) {
				return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed was not a JSON list. The previous cache was kept.' );
			}
		}
	}

	/**
	 * @param resource $handle Stream.
	 * @param string   $buffer Unconsumed bytes.
	 * @param int      $pos Offset.
	 * @param string   $open Opening brace or bracket.
	 * @return string|WP_Error
	 */
	private static function read_composite( $handle, &$buffer, &$pos, $open ) {
		$depth     = 1;
		$out       = $open;
		$in_string = false;
		$escape    = false;
		while ( true ) {
			$char = self::pull( $handle, $buffer, $pos );
			if ( '' === $char ) {
				return new WP_Error( 'rw_feed_invalid', 'The vulnerability feed ended before a record finished. The previous cache was kept.' );
			}
			$out .= $char;
			if ( strlen( $out ) > self::MAX_RECORD ) {
				return new WP_Error( 'rw_feed_invalid', 'A vulnerability record was too large to read. The previous cache was kept.' );
			}
			if ( $in_string ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escape = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $char ) {
				$in_string = true;
				continue;
			}
			if ( '{' === $char || '[' === $char ) {
				$depth++;
				continue;
			}
			if ( '}' === $char || ']' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return $out;
				}
			}
		}
	}

	/**
	 * @param resource $handle Stream.
	 * @param string   $buffer Unconsumed bytes.
	 * @param int      $pos Offset.
	 * @return string
	 */
	private static function peek( $handle, &$buffer, &$pos ) {
		if ( $pos >= strlen( $buffer ) ) {
			$chunk = fread( $handle, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				return '';
			}
			$buffer = $chunk;
			$pos    = 0;
		}

		return $buffer[ $pos ];
	}

	/**
	 * @param resource $handle Stream.
	 * @param string   $buffer Unconsumed bytes.
	 * @param int      $pos Offset.
	 * @return string
	 */
	private static function pull( $handle, &$buffer, &$pos ) {
		$char = self::peek( $handle, $buffer, $pos );
		if ( '' === $char ) {
			return '';
		}
		$pos++;

		return $char;
	}

	/**
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * @param string $text Text.
	 * @param int    $start Start.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function substr( $text, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, $start, $length );
		}

		return substr( $text, $start, $length );
	}

	/**
	 * @param string $text Text.
	 * @return int
	 */
	private static function last_space( $text ) {
		if ( function_exists( 'mb_strrpos' ) ) {
			$pos = mb_strrpos( $text, ' ' );
			return false === $pos ? 0 : (int) $pos;
		}
		$pos = strrpos( $text, ' ' );

		return false === $pos ? 0 : (int) $pos;
	}

	/**
	 * @return string
	 */
	private static function temp_path() {
		if ( function_exists( 'wp_tempnam' ) ) {
			$path = wp_tempnam( 'rw-wf-feed' );
			return is_string( $path ) ? $path : '';
		}
		$path = tempnam( sys_get_temp_dir(), 'rw-wf' );

		return is_string( $path ) ? $path : '';
	}

	/**
	 * @param string $path File path.
	 * @return void
	 */
	private static function delete_file( $path ) {
		if ( '' !== (string) $path && is_file( $path ) ) {
			unlink( $path );
		}
	}
}
