<?php
/**
 * Maintenance scanner, auditor, and report checks. Loaded by tests/run.php.
 */

require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-maintenance-feed.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-maintenance-scanner.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-maintenance-auditor.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-maintenance-reporter.php';
require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector-maintenance.php';

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		if ( 'version' === $show ) {
			return isset( $GLOBALS['rw_wp_version'] ) ? (string) $GLOBALS['rw_wp_version'] : '6.4.1';
		}
		return '';
	}
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $key ) {
		return isset( $GLOBALS['rw_site_transients'][ $key ] ) ? $GLOBALS['rw_site_transients'][ $key ] : false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = (int) $post;
		return isset( $GLOBALS['rw_permalinks'][ $id ] ) ? $GLOBALS['rw_permalinks'][ $id ] : false;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return isset( $GLOBALS['rw_scheduled'][ $hook ] ) ? $GLOBALS['rw_scheduled'][ $hook ] : false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['rw_scheduled'][ $hook ] = (int) $timestamp;
		$GLOBALS['rw_schedule_log'][]     = array( 'event', (string) $recurrence, (string) $hook );
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['rw_scheduled'][ $hook ] = (int) $timestamp;
		$GLOBALS['rw_schedule_log'][]     = array( 'single', (string) $hook );
		return true;
	}
}

function rw_schema_node( array $schema, $ref ) {
	$ref = (string) $ref;
	if ( 0 !== strpos( $ref, '#/' ) ) {
		return null;
	}
	$node = $schema;
	foreach ( explode( '/', substr( $ref, 2 ) ) as $part ) {
		$part = str_replace( '~1', '/', str_replace( '~0', '~', $part ) );
		if ( ! is_array( $node ) || ! isset( $node[ $part ] ) ) {
			return null;
		}
		$node = $node[ $part ];
	}
	return $node;
}

function rw_schema_errors( array $root, $schema, $data, $path = '$' ) {
	if ( isset( $schema['$ref'] ) ) {
		$target = rw_schema_node( $root, $schema['$ref'] );
		if ( ! is_array( $target ) ) {
			return array( $path . ' bad ref' );
		}
		return rw_schema_errors( $root, $target, $data, $path );
	}
	if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
		foreach ( $schema['anyOf'] as $option ) {
			if ( is_array( $option ) && array() === rw_schema_errors( $root, $option, $data, $path ) ) {
				return array();
			}
		}
		return array( $path . ' anyOf' );
	}
	$errors = array();
	if ( isset( $schema['const'] ) && $data !== $schema['const'] ) {
		$errors[] = $path . ' const';
	}
	if ( isset( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
		$errors[] = $path . ' enum';
	}
	if ( isset( $schema['type'] ) ) {
		$type = $schema['type'];
		$ok   = ( 'object' === $type && is_array( $data ) && rw_schema_is_object( $data ) )
			|| ( 'array' === $type && is_array( $data ) && ! rw_schema_is_object( $data ) )
			|| ( 'string' === $type && is_string( $data ) )
			|| ( 'boolean' === $type && is_bool( $data ) )
			|| ( 'integer' === $type && is_int( $data ) )
			|| ( 'null' === $type && null === $data );
		if ( ! $ok ) {
			$errors[] = $path . ' type';
			return $errors;
		}
	}
	if ( is_string( $data ) && isset( $schema['pattern'] ) && ! preg_match( '#' . str_replace( '#', '\#', $schema['pattern'] ) . '#', $data ) ) {
		$errors[] = $path . ' pattern';
	}
	if ( is_string( $data ) && isset( $schema['minLength'] ) && strlen( $data ) < (int) $schema['minLength'] ) {
		$errors[] = $path . ' minLength';
	}
	if ( is_string( $data ) && isset( $schema['maxLength'] ) && strlen( $data ) > (int) $schema['maxLength'] ) {
		$errors[] = $path . ' maxLength';
	}
	if ( is_int( $data ) && isset( $schema['minimum'] ) && $data < (int) $schema['minimum'] ) {
		$errors[] = $path . ' minimum';
	}
	if ( is_int( $data ) && isset( $schema['maximum'] ) && $data > (int) $schema['maximum'] ) {
		$errors[] = $path . ' maximum';
	}
	if ( is_array( $data ) && isset( $schema['type'] ) && 'array' === $schema['type'] ) {
		if ( isset( $schema['maxItems'] ) && count( $data ) > (int) $schema['maxItems'] ) {
			$errors[] = $path . ' maxItems';
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $data as $index => $item ) {
				$errors = array_merge( $errors, rw_schema_errors( $root, $schema['items'], $item, $path . '[' . $index . ']' ) );
			}
		}
	}
	if ( is_array( $data ) && isset( $schema['type'] ) && 'object' === $schema['type'] ) {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $key ) {
				if ( ! array_key_exists( $key, $data ) ) {
					$errors[] = $path . ' missing ' . $key;
				}
			}
		}
		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			foreach ( $data as $key => $unused ) {
				unset( $unused );
				if ( ! isset( $properties[ $key ] ) ) {
					$errors[] = $path . ' extra ' . $key;
				}
			}
		}
		foreach ( $properties as $key => $child ) {
			if ( array_key_exists( $key, $data ) && is_array( $child ) ) {
				$errors = array_merge( $errors, rw_schema_errors( $root, $child, $data[ $key ], $path . '.' . $key ) );
			}
		}
	}
	return $errors;
}

function rw_schema_is_object( array $data ) {
	if ( array() === $data ) {
		return false;
	}
	return array_keys( $data ) !== range( 0, count( $data ) - 1 );
}

$woo_record = array(
	'id'    => 'woo-1',
	'title' => 'WooCommerce <= 8.2.1 - XSS {not a break} "quoted"',
	'software' => array(
		array(
			'type'              => 'plugin',
			'slug'              => 'woocommerce',
			'remediation'       => '<p>Update to version 8.2.2, or a newer patched version.</p>',
			'affected_versions' => array(
				'*' => array(
					'from_version'   => '*',
					'from_inclusive' => true,
					'to_version'     => '8.2.1',
					'to_inclusive'   => true,
				),
			),
		),
	),
	'cvss' => array(
		'score'  => 6.4,
		'rating' => 'Medium',
		'vector' => 'CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:C/C:L/I:L/A:N',
	),
);
$edge_record = array(
	'id'    => 'edge-1',
	'title' => 'Edge',
	'software' => array(
		array(
			'type'              => 'plugin',
			'slug'              => 'edge-plugin',
			'affected_versions' => array(
				array(
					'from_version'   => '1.0.0',
					'from_inclusive' => false,
					'to_version'     => '2.0.0',
					'to_inclusive'   => false,
				),
			),
		),
	),
);
$feed_json = wp_json_encode( array( $woo_record, $edge_record ) );
$parsed    = RW_Connector_Maintenance_Feed::records_from_body( $feed_json, array( 'woocommerce', 'edge-plugin' ) );
$woo_hit   = is_array( $parsed ) && isset( $parsed['woocommerce'][0] ) ? $parsed['woocommerce'][0] : array();
$edge_hit  = is_array( $parsed ) && isset( $parsed['edge-plugin'][0] ) ? $parsed['edge-plugin'][0] : array();
rw_test(
	'the scanner feed keeps wanted slugs and ignores markup',
	is_array( $parsed )
		&& ! isset( $parsed['other-plugin'] )
		&& 'plugin' === $woo_hit['type']
		&& 'medium' === $woo_hit['severity']
		&& '*' === $woo_hit['from']
		&& '8.2.1' === $woo_hit['to']
		&& true === $woo_hit['to_inclusive']
		&& 'Update to version 8.2.2, or a newer patched version.' === $woo_hit['excerpt']
		&& false === strpos( (string) $woo_hit['excerpt'], '<' )
		&& false === $edge_hit['from_inclusive']
		&& false === $edge_hit['to_inclusive']
		&& RW_Connector_Maintenance_Feed::version_in_range( '8.2.1', $woo_hit )
		&& ! RW_Connector_Maintenance_Feed::version_in_range( '8.2.2', $woo_hit )
		&& ! RW_Connector_Maintenance_Feed::version_in_range( '1.0.0', $edge_hit )
		&& RW_Connector_Maintenance_Feed::version_in_range( '1.0.1', $edge_hit )
		&& ! RW_Connector_Maintenance_Feed::version_in_range( '2.0.0', $edge_hit )
);

$map_json = '{"abc":' . wp_json_encode( $woo_record ) . '}';
$map_hit  = RW_Connector_Maintenance_Feed::records_from_body( $map_json, array( 'woocommerce' ) );
rw_test(
	'a feed keyed by id is read one record at a time',
	is_array( $map_hit )
		&& isset( $map_hit['woocommerce'][0]['excerpt'] )
		&& 'Update to version 8.2.2, or a newer patched version.' === $map_hit['woocommerce'][0]['excerpt']
);
$empty_list = RW_Connector_Maintenance_Feed::records_from_body( '[]', array( 'woocommerce' ) );
$bad_feed   = RW_Connector_Maintenance_Feed::records_from_body( '<html>not json</html>', array( 'woocommerce' ) );
$open_feed  = RW_Connector_Maintenance_Feed::records_from_body( '[{"id":"x"', array( 'woocommerce' ) );
rw_test(
	'a truncated or non-JSON feed is not treated as an empty success',
	is_array( $empty_list ) && array() === $empty_list
		&& is_wp_error( $bad_feed ) && 'rw_feed_invalid' === $bad_feed->get_error_code()
		&& is_wp_error( $open_feed )
);

$long = trim( str_repeat( 'word ', 50 ) );
$cut  = RW_Connector_Maintenance_Feed::excerpt( '<p>' . $long . '</p> and an em dash ' . "\u{2014}" . ' here' );
rw_test(
	'excerpts are plain, short, and free of em dashes',
	strlen( $cut ) <= 180
		&& false === strpos( $cut, '<' )
		&& false === strpos( $cut, "\u{2014}" )
		&& '...' === substr( $cut, -3 )
);

$calls   = 0;
$fetcher = function ( $url, $key ) use ( &$calls, $feed_json ) {
	$calls++;
	$GLOBALS['rw_feed_seen_url'] = $url;
	$GLOBALS['rw_feed_seen_key'] = $key;
	return $feed_json;
};
$first = RW_Connector_Maintenance_Feed::resolve( array( 'woocommerce', 'WordPress' ), 'super-secret-key', 1700000000, array(), 43200, $fetcher, 'https://evil.example/steal' );
$second = RW_Connector_Maintenance_Feed::resolve( array( 'woocommerce', 'wordpress' ), 'super-secret-key', 1700000000 + 10, $first['cache'], 43200, $fetcher, RW_Connector_Maintenance_Feed::SCANNER_URL );
$miss = RW_Connector_Maintenance_Feed::resolve( array( 'woocommerce', 'wordpress', 'extra-plugin' ), 'super-secret-key', 1700000000 + 20, $first['cache'], 43200, $fetcher, RW_Connector_Maintenance_Feed::SCANNER_URL );
$limited = RW_Connector_Maintenance_Feed::resolve(
	array( 'woocommerce' ),
	'super-secret-key',
	1700000000 + 90000,
	$first['cache'],
	43200,
	function () {
		return new WP_Error( 'rw_feed_limited', 'slow down' );
	},
	RW_Connector_Maintenance_Feed::SCANNER_URL
);
$unconfigured = RW_Connector_Maintenance_Feed::resolve( array( 'woocommerce' ), '  ', 1700000000, array(), 43200, $fetcher, RW_Connector_Maintenance_Feed::SCANNER_URL );
rw_test(
	'a fresh cache is reused and a new slug fetches again',
	false === $second['fetched']
		&& 'cached' === $second['reason']
		&& true === $miss['fetched']
		&& 2 === $calls
		&& RW_Connector_Maintenance_Feed::SCANNER_URL === $GLOBALS['rw_feed_seen_url']
		&& 'super-secret-key' === $GLOBALS['rw_feed_seen_key']
		&& false === strpos( wp_json_encode( $first['cache'] ), 'super-secret-key' )
		&& 'rw_feed_limited' === $limited['reason']
		&& isset( $limited['findings']['woocommerce'] )
		&& 'unconfigured' === $unconfigured['reason']
		&& array() === $unconfigured['findings']
		&& RW_Connector_Maintenance_Feed::SCANNER_URL === RW_Connector_Maintenance_Feed::allowed_feed_url( 'http://www.wordfence.com/api/intelligence/v3/vulnerabilities/production' )
		&& RW_Connector_Maintenance_Feed::PRODUCTION_URL === RW_Connector_Maintenance_Feed::allowed_feed_url( RW_Connector_Maintenance_Feed::PRODUCTION_URL )
);
$many = array();
foreach ( array( 'critical', 'high', 'medium', 'low', 'unknown', 'low' ) as $index => $rating ) {
	$record = $edge_record;
	$record['id'] = 'n-' . $index;
	$record['software'][0]['slug'] = 'stacked';
	$record['software'][0]['affected_versions'][0]['from_version'] = '*';
	$record['software'][0]['affected_versions'][0]['from_inclusive'] = true;
	$record['software'][0]['affected_versions'][0]['to_version'] = '*';
	$record['cvss'] = array( 'rating' => $rating, 'score' => 1, 'vector' => 'CVSS:3.1/AV:N' );
	if ( 'unknown' === $rating ) {
		unset( $record['cvss'] );
	}
	$many[] = $record;
}
$capped = RW_Connector_Maintenance_Feed::resolve( array( 'stacked' ), 'k', 100, array(), 10, function () use ( $many ) {
	return wp_json_encode( $many );
}, RW_Connector_Maintenance_Feed::SCANNER_URL );
$capped_rows = isset( $capped['findings']['stacked'] ) ? $capped['findings']['stacked'] : array();
$capped_sev  = array();
foreach ( $capped_rows as $capped_row ) {
	$capped_sev[] = $capped_row['severity'];
}
rw_test(
	'the cache keeps the five highest findings for a slug',
	5 === count( $capped_rows ) && 'critical' === $capped_sev[0] && ! in_array( 'unknown', $capped_sev, true )
);

$auth = RW_Connector_Maintenance_Feed::request_args( 'super-secret-key', '' );
rw_test(
	'the feed request uses a bearer token and a bounded download',
	'Bearer super-secret-key' === $auth['headers']['Authorization']
		&& 20 === $auth['timeout']
		&& RW_Connector_Maintenance_Feed::MAX_BODY_BYTES === $auth['limit_response_size']
		&& empty( $auth['stream'] )
);

$core_transient = (object) array(
	'updates' => array(
		(object) array(
			'response' => 'latest',
			'current'  => '6.4.1',
			'version'  => '6.4.1',
		),
		(object) array(
			'response' => 'upgrade',
			'current'  => '6.4.2',
			'version'  => '6.4.2',
		),
	),
);
$plugin_transient = (object) array(
	'checked'  => array(
		'woocommerce/woocommerce.php' => '8.2.1',
		'hello.php'                   => '1.7.2',
		'vendor-pro/vendor-pro.php'   => '2.0.0',
	),
	'response' => array(
		'woocommerce/woocommerce.php' => (object) array(
			'slug'           => 'woocommerce',
			'new_version'    => '8.2.2',
			'upgrade_notice' => '<strong>Security fix for checkout</strong>',
		),
		'hello.php' => (object) array(
			'new_version' => '1.7.2',
		),
		'vendor-pro/vendor-pro.php' => array(
			'new_version'    => '2.1.0',
			'upgrade_notice' => 'Vendor changelog: faster imports.',
		),
	),
);
$theme_transient = (object) array(
	'checked'  => array( 'twentytwentyfour' => '1.0' ),
	'response' => array(
		'twentytwentyfour' => array(
			'theme'       => 'twentytwentyfour',
			'new_version' => '1.2',
		),
	),
);
$core_row = RW_Connector_Maintenance_Scanner::core_from_transient( $core_transient, '6.4.1' );
$plugins  = RW_Connector_Maintenance_Scanner::plugins_from_transient( $plugin_transient );
$themes   = RW_Connector_Maintenance_Scanner::themes_from_transient( $theme_transient );
$plugin_slugs = array();
foreach ( $plugins as $plugin_row ) {
	$plugin_slugs[ $plugin_row['slug'] ] = $plugin_row['current'] . '>' . $plugin_row['latest'];
}
rw_test(
	'core, plugin, and vendor updates come from the WordPress transients',
	is_array( $core_row )
		&& 'wordpress' === $core_row['slug']
		&& '6.4.1' === $core_row['current']
		&& '6.4.2' === $core_row['latest']
		&& array( 'woocommerce' => '8.2.1>8.2.2', 'vendor-pro' => '2.0.0>2.1.0' ) === $plugin_slugs
		&& 1 === count( $themes )
		&& 'twentytwentyfour' === $themes[0]['slug']
		&& '1.2' === $themes[0]['latest']
);

$findings = array(
	'woocommerce' => array(
		array(
			'type'           => 'plugin',
			'from'           => '*',
			'from_inclusive' => true,
			'to'             => '8.2.1',
			'to_inclusive'   => true,
			'severity'       => 'medium',
			'excerpt'        => 'Update to version 8.2.2, or a newer patched version.',
		),
		array(
			'type'           => 'theme',
			'from'           => '*',
			'from_inclusive' => true,
			'to'             => '*',
			'to_inclusive'   => true,
			'severity'       => 'critical',
			'excerpt'        => 'Theme only.',
		),
	),
	'wordpress' => array(
		array(
			'type'           => 'core',
			'from'           => '*',
			'from_inclusive' => true,
			'to'             => '6.4.1',
			'to_inclusive'   => true,
			'severity'       => 'high',
			'excerpt'        => 'Update WordPress.',
		),
	),
);
$updates = RW_Connector_Maintenance_Scanner::build_updates( $core_row, $plugins, $themes, $findings );
$by_slug = array();
foreach ( $updates as $update ) {
	$by_slug[ $update['slug'] ] = $update;
}
rw_test(
	'security updates are flagged from the installed version and keep both field names',
	true === $by_slug['woocommerce']['is_security']
		&& 'medium' === $by_slug['woocommerce']['severity_hint']
		&& '8.2.1' === $by_slug['woocommerce']['current']
		&& '8.2.2' === $by_slug['woocommerce']['latest']
		&& $by_slug['woocommerce']['from'] === $by_slug['woocommerce']['current']
		&& $by_slug['woocommerce']['to'] === $by_slug['woocommerce']['latest']
		&& 'plugin' === $by_slug['woocommerce']['type']
		&& 'Update to version 8.2.2, or a newer patched version.' === $by_slug['woocommerce']['changelog_excerpt']
		&& false === strpos( $by_slug['woocommerce']['changelog_excerpt'], '<strong>' )
		&& false === $by_slug['vendor-pro']['is_security']
		&& '' === $by_slug['vendor-pro']['severity_hint']
		&& 'Vendor changelog: faster imports.' === $by_slug['vendor-pro']['changelog_excerpt']
		&& true === $by_slug['wordpress']['is_security']
		&& 'high' === $by_slug['wordpress']['severity_hint']
		&& false === $by_slug['twentytwentyfour']['is_security']
		&& ( 'woocommerce' === $updates[0]['slug'] || 'wordpress' === $updates[0]['slug'] )
);
$security_first = ! empty( $updates[0]['is_security'] ) && ! empty( $updates[1]['is_security'] );
rw_test( 'security updates are listed before other updates', $security_first );

$filler = array();
for ( $i = 0; $i < 101; $i++ ) {
	$filler[] = array(
		'slug'    => 'plugin-' . $i,
		'current' => '1.0.0',
		'latest'  => '1.0.1',
		'notice'  => '',
	);
}
$filler[40]['notice'] = 'kept';
$limited_updates = RW_Connector_Maintenance_Scanner::build_updates( null, $filler, array(), array() );
$limited_slugs   = array();
foreach ( $limited_updates as $limited_update ) {
	$limited_slugs[] = $limited_update['slug'];
}
rw_test( 'update rows are capped at 100', 100 === count( $limited_updates ) && in_array( 'plugin-0', $limited_slugs, true ) && ! in_array( 'plugin-100', $limited_slugs, true ) );

$paths = RW_Connector_Maintenance_Auditor::select_paths(
	'/',
	array(
		'cart'       => '/cart/',
		'checkout'   => '/checkout/',
		'my-account' => 'https://shop.example/my-account/?customer=SECRETTOKEN',
	),
	array( '/cart/', 'https://shop.example/about/?utm=1', 'https://evil.test/phish', '/../secret' ),
	array( '/about/', '/blog/hello/' ),
	20,
	'shop.example'
);
$missing_checkout = RW_Connector_Maintenance_Auditor::select_paths( '/', array( 'cart' => '/cart/' ), array(), array(), 20 );
$capped_paths     = RW_Connector_Maintenance_Auditor::select_paths( '/', array(), array( '/a/', '/b/', '/c/' ), array(), 2 );
rw_test(
	'key pages include home, cart, checkout, and my-account, then stop at the cap',
	array( '/', '/cart/', '/checkout/', '/my-account/', '/about/', '/blog/hello/' ) === $paths
		&& false === strpos( implode( ' ', $paths ), 'SECRETTOKEN' )
		&& false === strpos( implode( ' ', $paths ), 'phish' )
		&& array( '/', '/cart/' ) === $missing_checkout
		&& array( '/', '/a/' ) === $capped_paths
);

$sitemap_xml = '<sitemapindex><sitemap><loc>https://shop.example/wp-sitemap-pages-1.xml</loc></sitemap></sitemapindex>';
$child_xml   = '<urlset><url><loc>https://shop.example/from-child/?x=1</loc></url><url><loc>https://evil.test/stolen/</loc></url><url><loc>SECRET-SITEMAP-HTML</loc></url></urlset>';
$index_xml   = $sitemap_xml . '<urlset><url><loc>https://shop.example/from-index/</loc></url></urlset>';
$sitemap     = RW_Connector_Maintenance_Auditor::sitemap_paths(
	'https://shop.example/blog',
	function ( $url ) use ( $index_xml, $child_xml ) {
		if ( false !== strpos( $url, 'wp-sitemap-pages-1.xml' ) ) {
			return $child_xml;
		}
		return $index_xml;
	},
	20
);
rw_test(
	'sitemap paths stay on the site and drop the markup',
	in_array( '/from-index/', $sitemap, true )
		&& in_array( '/from-child/', $sitemap, true )
		&& ! in_array( '/stolen/', $sitemap, true )
		&& false === strpos( implode( ' ', $sitemap ), 'SECRET-SITEMAP-HTML' )
);

$pages = array(
	RW_Connector_Maintenance_Auditor::judge( '/ok/', array( 'status' => 200, 'body' => '<html><body>SECRET-PAGE-HTML ' . str_repeat( 'a', 40 ) . '</body></html>', 'load_time_ms' => 120 ) ),
	RW_Connector_Maintenance_Auditor::judge( '/checkout/', array( 'status' => 500, 'body' => '', 'load_time_ms' => 40 ) ),
	RW_Connector_Maintenance_Auditor::judge( '/missing/', array( 'status' => 404, 'body' => str_repeat( 'x', 80 ), 'load_time_ms' => 10 ) ),
	RW_Connector_Maintenance_Auditor::judge( '/slow/', array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out', 'load_time_ms' => 8000 ) ),
	RW_Connector_Maintenance_Auditor::judge( '/empty/', array( 'status' => 200, 'body' => '<html></html>', 'load_time_ms' => 15 ) ),
	RW_Connector_Maintenance_Auditor::judge( '/deny/', array( 'status' => 403, 'body' => str_repeat( 'nope ', 20 ), 'load_time_ms' => 12 ) ),
	RW_Connector_Maintenance_Auditor::judge( '/boom/', array( 'status' => 500, 'body' => '<html><body>' . str_repeat( 'Server error. ', 4 ) . '</body></html>', 'load_time_ms' => 30 ) ),
);
$summary = RW_Connector_Maintenance_Auditor::summarise( $pages );
$local   = RW_Connector_Maintenance_Auditor::local_page( $pages[0] );
$budget  = RW_Connector_Maintenance_Auditor::check_paths(
	array( '/one/', '/two/', '/three/' ),
	function ( $path ) {
		return array(
			'status'       => 200,
			'body'         => str_repeat( 'content ', 8 ),
			'load_time_ms' => 5,
			'path'         => $path,
		);
	},
	0
);
rw_test(
	'the auditor records status, time, and content without keeping HTML',
	'' === $pages[0]['note']
		&& true === $pages[0]['content_present']
		&& 'empty response' === $pages[1]['note']
		&& 500 === $pages[1]['status']
		&& 'The page was not found.' === $pages[2]['note']
		&& 'The page timed out.' === $pages[3]['note']
		&& 'empty response' === $pages[4]['note']
		&& 'The page returned an error.' === $pages[5]['note']
		&& true === $pages[6]['content_present']
		&& 'The page returned an error.' === $pages[6]['note']
		&& 7 === $summary['pages_checked']
		&& '/checkout/' === $summary['failures'][0]['url']
		&& false === strpos( wp_json_encode( $summary ), 'SECRET-PAGE-HTML' )
		&& array( 'url', 'status', 'load_time_ms', 'content_present' ) === array_keys( $local )
		&& 1 === count( $budget )
		&& false === strpos( implode( ' ', array( $pages[1]['note'], $pages[2]['note'], $pages[3]['note'], $pages[5]['note'] ) ), "\u{2014}" )
);

$now = strtotime( '2026-11-09 12:00:00 UTC' );
$snapshots = array(
	'../etc/passwd' => array( 'created_at' => gmdate( 'c', $now - 30 ), 'purpose' => 'pre_update' ),
	'rwsnap_20261109T060000Z_aaaaaaa1' => array( 'created_at' => gmdate( 'c', $now - 6 * 3600 - 60 ), 'purpose' => 'pre_update' ),
	'rwsnap_20261109T110000Z_bbbbbbb2' => array( 'created_at' => gmdate( 'c', $now - 3600 ), 'purpose' => 'pre_update' ),
	'rwsnap_20261109T114500Z_ccccccc3' => array( 'created_at' => gmdate( 'c', $now - 900 ), 'purpose' => 'customer' ),
);
rw_test(
	'the report prefers a recent pre-update snapshot reference',
	'rwsnap_20261109T110000Z_bbbbbbb2' === RW_Connector_Maintenance_Reporter::select_backup_ref( $snapshots, $now )
		&& 'rwsnap_20261109T114500Z_ccccccc3' === RW_Connector_Maintenance_Reporter::select_backup_ref( array( 'rwsnap_20261109T114500Z_ccccccc3' => $snapshots['rwsnap_20261109T114500Z_ccccccc3'] ), $now )
		&& '' === RW_Connector_Maintenance_Reporter::select_backup_ref( array( '../etc/passwd' => array() ), $now )
);

$partial = RW_Connector_Maintenance_Reporter::normalise_wordfence(
	array(
		'version'           => '<b>8.0.5</b>',
		'pro'               => '0',
		'template_applied'  => 'v3',
		'conflicts_removed' => array( 'wordfence', 'wordfence', '../evil' ),
		'licence_key'       => 'do-not-send',
	)
);
rw_test(
	'a partial Wordfence block drops unknown and empty values',
	is_array( $partial )
		&& '8.0.5' === $partial['version']
		&& false === $partial['pro']
		&& 'v3' === $partial['template_applied']
		&& array( 'wordfence' ) === $partial['conflicts_removed']
		&& ! isset( $partial['licence_key'] )
		&& null === RW_Connector_Maintenance_Reporter::normalise_wordfence( null )
		&& null === RW_Connector_Maintenance_Reporter::normalise_wordfence( array() )
);

$report = RW_Connector_Maintenance_Reporter::build(
	'9',
	'2026-11-09T12:00:00Z',
	$updates,
	$summary,
	'rwsnap_20261109T110000Z_bbbbbbb2',
	null
);
$report['backup_ref'] = RW_Connector_Maintenance_Reporter::select_backup_ref( $snapshots, $now );
$bad_ref = RW_Connector_Maintenance_Reporter::build( '9', '2026-11-09T12:00:00Z', array(), array( 'pages_checked' => 0, 'failures' => array() ), '../etc/passwd', array( 'version' => '8.0.5' ) );
$schema  = json_decode( (string) file_get_contents( __DIR__ . '/../docs/maintenance-report-1.0.0.schema.json' ), true );
$schema_errors = is_array( $schema ) ? rw_schema_errors( $schema, $schema, $report ) : array( 'schema json' );
$endpoint = RW_Connector_Maintenance_Reporter::endpoint( 'https://reactwoo.com/' );
rw_test(
	'the report matches the versioned schema and the plan shape',
	array() === $schema_errors
		&& '1.0.0' === $report['schema_version']
		&& '9' === $report['site_id']
		&& '2026-11-09T12:00:00Z' === $report['timestamp']
		&& null === $report['wordfence']
		&& 'rwsnap_20261109T110000Z_bbbbbbb2' === $report['backup_ref']
		&& '' === $bad_ref['backup_ref']
		&& '8.0.5' === $bad_ref['wordfence']['version']
		&& ! isset( $bad_ref['wordfence']['pro'] )
		&& 7 === $report['audit']['pages_checked']
		&& '/checkout/' === $report['audit']['failures'][0]['url']
		&& 500 === $report['audit']['failures'][0]['status']
		&& 'empty response' === $report['audit']['failures'][0]['note']
		&& false !== strpos( $endpoint, 'index.php?rest_route=' )
		&& '/reactwoo/v1/sites/maintenance-report' === RW_Connector_Signing::route_from_url( $endpoint )
		&& false === strpos( $endpoint, 'wp-json' )
);
$rejected = RW_Connector_Maintenance_Reporter::interpret_response(
	array(
		'response' => array( 'code' => 503 ),
		'body'     => '{"debug":"SECRET-RESPONSE"}',
	)
);
rw_test(
	'a rejected report does not look successful',
	is_wp_error( $rejected ) && 'rw_report_rejected' === $rejected->get_error_code() && 503 === (int) $rejected->get_error_data()['status']
);

$GLOBALS['rw_scheduled']    = array();
$GLOBALS['rw_schedule_log'] = array();
RW_Connector_Maintenance::ensure_schedule();
RW_Connector_Maintenance::ensure_schedule();
update_option( 'rw_connector_site_id', 9, false );
RW_Connector_Maintenance::schedule_after_update( null, array( 'action' => 'install', 'type' => 'plugin' ) );
RW_Connector_Maintenance::schedule_after_update( null, array( 'action' => 'update', 'type' => 'plugin' ) );
RW_Connector_Maintenance::schedule_after_update( null, array( 'action' => 'update', 'type' => 'theme' ) );
delete_option( 'rw_connector_site_id' );
RW_Connector_Maintenance::schedule_after_update( null, array( 'action' => 'update', 'type' => 'core' ) );
$daily = 0;
$single = 0;
foreach ( $GLOBALS['rw_schedule_log'] as $logged ) {
	if ( 'event' === $logged[0] && 'daily' === $logged[1] && RW_Connector_Maintenance::HOOK === $logged[2] ) {
		$daily++;
	}
	if ( 'single' === $logged[0] && RW_Connector_Maintenance::AFTER === $logged[1] ) {
		$single++;
	}
}
rw_test( 'the scan is scheduled daily and once after an update', 1 === $daily && 1 === $single );

delete_option( 'rw_connector_site_id' );
delete_option( 'rw_connector_site_secret' );
delete_option( 'rw_connector_portal_url' );
$skipped = RW_Connector_Maintenance::run_due();
rw_test( 'an unenrolled site does not send a report', is_wp_error( $skipped ) && 'rw_connector_not_connected' === $skipped->get_error_code() );

$GLOBALS['rw_home_url'] = 'https://portal.example';
$GLOBALS['rw_wp_version'] = '6.4.1';
$GLOBALS['rw_site_transients'] = array(
	'update_core'    => $core_transient,
	'update_plugins' => $plugin_transient,
	'update_themes'  => $theme_transient,
);
$GLOBALS['rw_permalinks'] = array(
	10 => 'https://portal.example/cart/',
	11 => 'https://portal.example/checkout/?session=SECRETTOKEN',
	12 => 'https://portal.example/my-account/',
);
update_option( 'rw_connector_site_id', 9, false );
update_option( 'rw_connector_site_secret', 'site-secret', false );
update_option( 'rw_connector_portal_url', 'https://reactwoo.com', false );
update_option( 'woocommerce_cart_page_id', 10, false );
update_option( 'woocommerce_checkout_page_id', 11, false );
update_option( 'woocommerce_myaccount_page_id', 12, false );
update_option( 'rw_connector_audit_pages', array( '/contact/', 'https://evil.test/phish', 'https://portal.example/about/?token=SECRETTOKEN' ), false );
update_option( 'rw_connector_snapshots', $snapshots, false );
update_option(
	RW_Connector_Maintenance_Feed::OPTION,
	array(
		'fetched_at' => time(),
		'covered'    => array( 'wordpress', 'woocommerce', 'vendor-pro', 'twentytwentyfour' ),
		'findings'   => $findings,
	),
	false
);
$page_html = '<html><body>SECRET-PAGE-HTML ' . str_repeat( 'welcome ', 8 ) . '</body></html>';
$ok_page   = array(
	'response' => array( 'code' => 200 ),
	'body'     => $page_html,
);
$GLOBALS['rw_http_get'] = array(
	'https://portal.example/wp-sitemap.xml' => array(
		'response' => array( 'code' => 200 ),
		'body'     => '<urlset><url><loc>https://portal.example/from-index/</loc></url><url><loc>https://evil.test/stolen/</loc></url></urlset>',
	),
	'https://portal.example/'            => $ok_page,
	'https://portal.example/cart/'       => $ok_page,
	'https://portal.example/checkout/'   => array(
		'response' => array( 'code' => 500 ),
		'body'     => '',
	),
	'https://portal.example/my-account/' => $ok_page,
	'https://portal.example/contact/'    => $ok_page,
	'https://portal.example/about/'      => $ok_page,
	'https://portal.example/from-index/' => $ok_page,
);
$before_posts = isset( $GLOBALS['rw_requests'] ) ? count( $GLOBALS['rw_requests'] ) : 0;
$sent         = RW_Connector_Maintenance::run_due();
$posted       = array();
if ( isset( $GLOBALS['rw_requests'] ) && count( $GLOBALS['rw_requests'] ) > $before_posts ) {
	$posted = $GLOBALS['rw_requests'][ count( $GLOBALS['rw_requests'] ) - 1 ];
}
$posted_body = isset( $posted['args']['body'] ) ? (string) $posted['args']['body'] : '';
$posted_url  = isset( $posted['url'] ) ? (string) $posted['url'] : '';
$posted_headers = isset( $posted['args']['headers'] ) && is_array( $posted['args']['headers'] ) ? $posted['args']['headers'] : array();
$signed_path = 'POST|' . RW_Connector_Signing::route_from_url( $posted_url ) . '|' . ( isset( $posted_headers['X-RW-Timestamp'] ) ? $posted_headers['X-RW-Timestamp'] : '' ) . '|' . ( isset( $posted_headers['X-RW-Nonce'] ) ? $posted_headers['X-RW-Nonce'] : '' ) . '|' . hash( 'sha256', $posted_body );
$stored_last = get_option( RW_Connector_Maintenance::LAST );
$stored_json = wp_json_encode( $stored_last );
rw_test(
	'a due run signs the maintenance route and does not send page HTML',
	is_array( $sent )
		&& '9' === $sent['site_id']
		&& null === $sent['wordfence']
		&& true === $sent['updates'][0]['is_security']
		&& 'rwsnap_20261109T110000Z_bbbbbbb2' === $sent['backup_ref']
		&& 1 === count( $sent['audit']['failures'] )
		&& '/checkout/' === $sent['audit']['failures'][0]['url']
		&& 500 === $sent['audit']['failures'][0]['status']
		&& 'empty response' === $sent['audit']['failures'][0]['note']
		&& $sent['audit']['pages_checked'] >= 6
		&& false === strpos( wp_json_encode( $sent ), 'SECRET-PAGE-HTML' )
		&& false === strpos( wp_json_encode( $sent ), 'SECRETTOKEN' )
		&& false === strpos( wp_json_encode( $sent ), 'evil.test' )
		&& false === strpos( $posted_body, 'SECRET-PAGE-HTML' )
		&& false === strpos( (string) $stored_json, 'SECRET-PAGE-HTML' )
		&& false === strpos( (string) $stored_json, 'SECRETTOKEN' )
		&& is_array( $stored_last )
		&& ! empty( $stored_last['sent'] )
		&& isset( $stored_last['pages'][0]['load_time_ms'] )
		&& array( 'url', 'status', 'load_time_ms', 'content_present' ) === array_keys( $stored_last['pages'][0] )
		&& '/reactwoo/v1/sites/maintenance-report' === RW_Connector_Signing::route_from_url( $posted_url )
		&& hash_hmac( 'sha256', $signed_path, 'site-secret' ) === $posted_headers['X-RW-Signature']
		&& '9' === $posted_headers['X-RW-Site-ID']
);

$connector_php = (string) file_get_contents( __DIR__ . '/../reactwoo-support-connector/reactwoo-support-connector.php' );
$connector_src = (string) file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-connector.php' );
$copy_blob     = '';
foreach ( array(
	'reactwoo-support-connector/includes/class-rw-connector-maintenance.php',
	'reactwoo-support-connector/includes/class-rw-connector-maintenance-feed.php',
	'reactwoo-support-connector/includes/class-rw-connector-maintenance-scanner.php',
	'reactwoo-support-connector/includes/class-rw-connector-maintenance-auditor.php',
	'reactwoo-support-connector/includes/class-rw-connector-maintenance-reporter.php',
	'docs/maintenance-report-1.0.0.md',
	'docs/maintenance-report-1.0.0.schema.json',
) as $relative ) {
	$copy_blob .= (string) file_get_contents( __DIR__ . '/../' . $relative );
}
rw_test(
	'the connector version is unchanged and the report code does not load on the front end',
	false !== strpos( $connector_php, 'Version: 0.5.12' )
		&& false !== strpos( $connector_php, "define( 'RW_CONNECTOR_VERSION', '0.5.12' )" )
		&& false !== strpos( $connector_src, 'class-rw-connector-maintenance.php' )
		&& false !== strpos( $connector_src, 'RW_Connector_Maintenance::register' )
		&& false === strpos( $copy_blob, 'wp_enqueue' )
		&& false === strpos( $copy_blob, "\u{2014}" )
);

unset( $GLOBALS['rw_home_url'], $GLOBALS['rw_http_get'], $GLOBALS['rw_permalinks'], $GLOBALS['rw_site_transients'] );
