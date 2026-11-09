<?php
/**
 * Available core, plugin, and theme updates already known to WordPress.
 *
 * WordPress.org and vendor updaters both write into the update transients.
 * This does not refresh those transients and does not download package zips.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Maintenance_Scanner {
	const MAX_UPDATES = 100;

	/**
	 * @param array<string,mixed>|null $core Installed core row, or null when it is current.
	 * @param array<int,array<string,string>> $plugins Plugin updates.
	 * @param array<int,array<string,string>> $themes Theme updates.
	 * @param array<string,array<int,array<string,mixed>>> $findings Slug to vulnerability ranges.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_updates( $core, array $plugins, array $themes, array $findings ) {
		$rows = array();
		if ( is_array( $core ) ) {
			$row = self::row( 'core', $core, $findings );
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}
		foreach ( $plugins as $plugin ) {
			$row = self::row( 'plugin', $plugin, $findings );
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}
		foreach ( $themes as $theme ) {
			$row = self::row( 'theme', $theme, $findings );
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}

		return self::limit_updates( $rows );
	}

	/**
	 * @param mixed  $transient update_core transient.
	 * @param string $installed Installed WordPress version.
	 * @return array<string,string>|null
	 */
	public static function core_from_transient( $transient, $installed ) {
		$installed = self::version( $installed );
		if ( '' === $installed || ! is_object( $transient ) || empty( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return null;
		}

		foreach ( $transient->updates as $update ) {
			$response = (string) self::item_value( $update, 'response' );
			if ( 'upgrade' !== $response ) {
				continue;
			}
			$offered = self::version( self::item_value( $update, 'version' ) );
			if ( '' === $offered ) {
				$offered = self::version( self::item_value( $update, 'current' ) );
			}
			if ( '' === $offered || version_compare( $installed, $offered, '>=' ) ) {
				continue;
			}

			return array(
				'slug'    => 'wordpress',
				'current' => $installed,
				'latest'  => $offered,
				'notice'  => trim( (string) self::item_value( $update, 'upgrade_notice' ) ),
			);
		}

		return null;
	}

	/**
	 * @param mixed $transient update_plugins transient.
	 * @return array<int,array<string,string>>
	 */
	public static function plugins_from_transient( $transient ) {
		return self::items_from_transient( $transient, 'plugin' );
	}

	/**
	 * @param mixed $transient update_themes transient.
	 * @return array<int,array<string,string>>
	 */
	public static function themes_from_transient( $transient ) {
		return self::items_from_transient( $transient, 'theme' );
	}

	/**
	 * Read the transients WordPress has already filled.
	 *
	 * @return array{core:array<string,string>|null,plugins:array<int,array<string,string>>,themes:array<int,array<string,string>>,slugs:string[]}
	 */
	public static function from_wordpress() {
		$core_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		$core         = function_exists( 'get_site_transient' ) ? self::core_from_transient( get_site_transient( 'update_core' ), $core_version ) : null;
		$plugins      = function_exists( 'get_site_transient' ) ? self::plugins_from_transient( get_site_transient( 'update_plugins' ) ) : array();
		$themes       = function_exists( 'get_site_transient' ) ? self::themes_from_transient( get_site_transient( 'update_themes' ) ) : array();
		$slugs        = array( 'wordpress' );
		foreach ( array_merge( $plugins, $themes ) as $item ) {
			if ( ! empty( $item['slug'] ) ) {
				$slugs[] = $item['slug'];
			}
		}

		return array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
			'slugs'   => $slugs,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Update rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function limit_updates( array $rows ) {
		$security = array();
		$other    = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_security'] ) ) {
				$security[] = $row;
			} else {
				$other[] = $row;
			}
		}
		$ordered = array_merge( $security, $other );
		if ( count( $ordered ) <= self::MAX_UPDATES ) {
			return $ordered;
		}

		return array_slice( $ordered, 0, self::MAX_UPDATES );
	}

	/**
	 * @param string               $type core, plugin, or theme.
	 * @param array<string,mixed>  $item Normalised update.
	 * @param array<string,array<int,array<string,mixed>>> $findings Findings.
	 * @return array<string,mixed>
	 */
	private static function row( $type, array $item, array $findings ) {
		$slug    = sanitize_key( isset( $item['slug'] ) ? (string) $item['slug'] : '' );
		$current = self::version( isset( $item['current'] ) ? $item['current'] : '' );
		$latest  = self::version( isset( $item['latest'] ) ? $item['latest'] : '' );
		if ( '' === $slug || '' === $current || '' === $latest || version_compare( $current, $latest, '>=' ) ) {
			return array();
		}
		if ( 'core' === $type ) {
			$slug = 'wordpress';
		}

		$match   = self::match( $type, $slug, $current, isset( $findings[ $slug ] ) ? $findings[ $slug ] : array() );
		$notice  = isset( $item['notice'] ) ? (string) $item['notice'] : '';
		$excerpt = RW_Connector_Maintenance_Feed::excerpt( $notice );
		if ( ! empty( $match['excerpt'] ) ) {
			$excerpt = (string) $match['excerpt'];
		}

		return array(
			'slug'               => $slug,
			'type'               => $type,
			'current'            => $current,
			'latest'             => $latest,
			'from'               => $current,
			'to'                 => $latest,
			'is_security'        => ! empty( $match ),
			'changelog_excerpt'  => $excerpt,
			'severity_hint'      => ! empty( $match['severity'] ) ? (string) $match['severity'] : '',
		);
	}

	/**
	 * @param string                             $type Item type.
	 * @param string                             $slug Slug.
	 * @param string                             $current Installed version.
	 * @param array<int,array<string,mixed>>     $rows Findings for the slug.
	 * @return array<string,string>
	 */
	private static function match( $type, $slug, $current, array $rows ) {
		unset( $slug );
		$best = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( '' !== $row_type && $row_type !== $type ) {
				continue;
			}
			if ( ! RW_Connector_Maintenance_Feed::version_in_range( $current, $row ) ) {
				continue;
			}
			$severity = isset( $row['severity'] ) ? (string) $row['severity'] : 'unknown';
			if ( empty( $best ) || RW_Connector_Maintenance_Feed::severity_rank( $severity ) > RW_Connector_Maintenance_Feed::severity_rank( $best['severity'] ) ) {
				$best = array(
					'severity' => '' === $severity ? 'unknown' : $severity,
					'excerpt'  => isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '',
				);
			}
		}

		return $best;
	}

	/**
	 * @param mixed  $transient Update transient.
	 * @param string $kind plugin or theme.
	 * @return array<int,array<string,string>>
	 */
	private static function items_from_transient( $transient, $kind ) {
		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}
		$checked = isset( $transient->checked ) && is_array( $transient->checked ) ? $transient->checked : array();
		$rows    = array();
		foreach ( $transient->response as $file => $item ) {
			$current = self::version( isset( $checked[ $file ] ) ? $checked[ $file ] : '' );
			$latest  = self::version( self::item_value( $item, 'new_version' ) );
			if ( '' === $current || '' === $latest || version_compare( $current, $latest, '>=' ) ) {
				continue;
			}
			$slug = 'theme' === $kind ? sanitize_key( (string) $file ) : self::plugin_slug( (string) $file, $item );
			if ( '' === $slug ) {
				continue;
			}
			$rows[] = array(
				'slug'    => $slug,
				'current' => $current,
				'latest'  => $latest,
				'notice'  => trim( (string) self::item_value( $item, 'upgrade_notice' ) ),
			);
		}

		return $rows;
	}

	/**
	 * @param string $file Plugin file.
	 * @param mixed  $item Update row.
	 * @return string
	 */
	private static function plugin_slug( $file, $item ) {
		$slug = sanitize_key( (string) self::item_value( $item, 'slug' ) );
		if ( '' !== $slug ) {
			return $slug;
		}
		$file = str_replace( '\\', '/', $file );
		$dir  = dirname( $file );
		if ( '.' !== $dir && '' !== $dir ) {
			return sanitize_key( $dir );
		}

		return sanitize_key( basename( $file, '.php' ) );
	}

	/**
	 * @param mixed  $item Object or array.
	 * @param string $key Field name.
	 * @return mixed
	 */
	private static function item_value( $item, $key ) {
		if ( is_array( $item ) && isset( $item[ $key ] ) ) {
			return $item[ $key ];
		}
		if ( is_object( $item ) && isset( $item->$key ) ) {
			return $item->$key;
		}

		return '';
	}

	/**
	 * @param mixed $value Version or notice.
	 * @return string
	 */
	private static function version( $value ) {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		if ( strlen( $value ) > 40 ) {
			$value = substr( $value, 0, 40 );
		}

		return $value;
	}
}
