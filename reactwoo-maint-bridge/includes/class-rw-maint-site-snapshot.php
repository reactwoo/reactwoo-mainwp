<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns synced maintenance records into a customer-safe site snapshot.
 *
 * The input is already limited to one enrolled site. This class does not query
 * other sites and does not include fields that were not present in the source.
 */
class RW_Maint_Site_Snapshot {
	const RECENT_BACKUPS = 5;
	const RECENT_RUNS    = 5;
	const RECENT_UPDATES = 10;
	const RUN_GAP        = 10800;

	/**
	 * The enrolled site must belong to the subscription on the signed request.
	 *
	 * @param object|array|null $site Bridge site row.
	 * @param int               $subscription_id Subscription claimed by the caller.
	 * @return bool
	 */
	public static function site_is_in_scope( $site, $subscription_id ) {
		$subscription_id = (int) $subscription_id;
		$site_subscription = (int) self::site_value( $site, 'subscription_id' );

		return $subscription_id > 0 && $site_subscription > 0 && $site_subscription === $subscription_id;
	}

	/**
	 * @param object|array|null    $site Bridge site row.
	 * @param int                  $subscription_id Subscription claimed by the caller.
	 * @param array<string,mixed>  $sources Synced records for this site only.
	 * @param int|null             $now Unix time used for schedules.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function guard( $site, $subscription_id, array $sources, $now = null ) {
		if ( ! self::site_is_in_scope( $site, $subscription_id ) ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}

		return self::build( $site, $sources, $now );
	}

	/**
	 * @param object|array         $site Bridge site row.
	 * @param array<string,mixed>  $sources Synced records for this site only.
	 * @param int|null             $now Unix time used for schedules.
	 * @return array<string,mixed>
	 */
	public static function build( $site, array $sources, $now = null ) {
		$now      = null === $now ? time() : (int) $now;
		$website  = self::row( isset( $sources['website'] ) ? $sources['website'] : array() );
		$sync     = self::row( isset( $sources['sync'] ) ? $sources['sync'] : array() );
		$options  = self::option_map( isset( $sources['options'] ) ? $sources['options'] : array() );
		$monitors = isset( $sources['monitors'] ) && is_array( $sources['monitors'] ) ? $sources['monitors'] : array();
		$logs     = isset( $sources['logs'] ) && is_array( $sources['logs'] ) ? $sources['logs'] : array();
		$audit    = isset( $sources['audit'] ) && is_array( $sources['audit'] ) ? $sources['audit'] : array();
		$tasks    = isset( $sources['backup_tasks'] ) && is_array( $sources['backup_tasks'] ) ? $sources['backup_tasks'] : array();

		$plugins = self::pending_components(
			self::has_key( $website, 'plugin_upgrades' ) || self::has_key( $options, 'plugin_upgrades' ),
			array(
				self::value_of( $website, $options, 'plugin_upgrades' ),
				self::value_of( $website, $options, 'premium_upgrades' ),
			)
		);
		$plugins['premium'] = self::premium_plugins( $website, $options );
		$themes = self::pending_components(
			self::has_key( $website, 'theme_upgrades' ) || self::has_key( $options, 'theme_upgrades' ),
			array( self::value_of( $website, $options, 'theme_upgrades' ) )
		);

		$backups  = self::backups( $website, $options, $tasks, $now );
		$activity = self::activity( $logs, $audit, $options, $now );

		return array(
			'portal_site_id'  => (int) self::site_value( $site, 'portal_site_id' ),
			'subscription_id' => (int) self::site_value( $site, 'subscription_id' ),
			'site_url'        => self::public_url( self::site_value( $site, 'site_url' ) ),
			'core'            => self::core( $sync, $options, $website ),
			'plugins'         => $plugins,
			'themes'          => $themes,
			'inventory'       => array(
				'plugins' => self::inventory_count( $website, $options, 'plugins' ),
				'themes'  => self::inventory_count( $website, $options, 'themes' ),
			),
			'recent_updates'  => self::recent_updates( $logs ),
			'php_version'     => self::php_version( $sync, $options ),
			'health'          => self::health( $sync, $options ),
			'security'        => array(
				'issues_count' => self::security_count( self::value_of( $website, $options, 'securityIssues' ), self::has_key( $website, 'securityIssues' ) || self::has_key( $options, 'securityIssues' ) ),
			),
			'timezone'        => self::reported_timezone( $website, $sync, $options ),
			'last_sync_at'    => self::iso( self::unix_of( isset( $sync['dtsSync'] ) ? $sync['dtsSync'] : null ) ),
			'backups'         => array(
				'last'           => $backups['last'],
				'next_scheduled' => $backups['next_scheduled'],
				'next_schedule'  => isset( $backups['next_schedule'] ) ? $backups['next_schedule'] : null,
				'recent'         => $backups['recent'],
			),
			'uptime'          => self::uptime( $website, $monitors ),
			'last_admin_login' => self::last_admin_login( $website, $options ),
			'content'         => self::content( $website, $sync, $options, isset( $sources['content'] ) ? $sources['content'] : null ),
			'activity'        => array(
				'last_run'       => $activity['last_run'],
				'next_scheduled' => $activity['next_scheduled'],
				'recent'         => $activity['recent'],
			),
		);
	}

	/**
	 * A backup task list belongs to this site only when the site id is named.
	 *
	 * @param mixed $sites_field Serialized or decoded site list.
	 * @param int   $mainwp_id MainWP site id.
	 * @return bool
	 */
	public static function backup_task_includes_site( $sites_field, $mainwp_id ) {
		$mainwp_id = (int) $mainwp_id;
		if ( $mainwp_id <= 0 ) {
			return false;
		}

		$decoded = self::decode_blob( $sites_field );
		$ids     = array();
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $key => $value ) {
				if ( is_numeric( $value ) ) {
					$ids[] = (int) $value;
				} elseif ( is_numeric( $key ) && ( true === $value || 1 === $value || '1' === $value ) ) {
					$ids[] = (int) $key;
				}
			}
		} elseif ( is_string( $sites_field ) && preg_match_all( '/\d+/', $sites_field, $matches ) ) {
			if ( false !== strpos( strtolower( $sites_field ), 'all' ) ) {
				return false;
			}
			foreach ( $matches[0] as $id ) {
				$ids[] = (int) $id;
			}
		}

		return in_array( $mainwp_id, $ids, true );
	}

	/**
	 * @param string $name Plugin or theme name.
	 * @param string $file Plugin file or theme stylesheet.
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function is_hidden_component( $name, $file = '', $slug = '' ) {
		$path = strtolower( str_replace( '\\', '/', (string) $file . ' ' . (string) $slug ) );
		if ( false !== strpos( $path, 'mainwp-child-reports' ) || false !== strpos( $path, 'mainwp-child' ) ) {
			return true;
		}

		$label = strtolower( trim( wp_strip_all_tags( (string) $name ) ) );

		return in_array(
			$label,
			array(
				'mainwp child',
				'mainwp child reports',
				'mainwp child report',
				'child reports',
				'reactwoo maintenance',
			),
			true
		);
	}

	/**
	 * @param array<string,mixed> $sync Sync row.
	 * @param array<string,mixed> $options Option map.
	 * @param array<string,mixed> $website Website row.
	 * @return array{wordpress_version:?string,update_available:?bool,new_version:?string}
	 */
	private static function core( array $sync, array $options, array $website ) {
		$installed = self::wordpress_version( $sync, $options, $website );

		$offered     = null;
		$known       = self::has_key( $website, 'wp_upgrades' ) || self::has_key( $options, 'wp_upgrades' );
		$upgrade_raw = $known ? self::value_of( $website, $options, 'wp_upgrades' ) : null;
		if ( $known ) {
			$upgrade = self::decode_blob( $upgrade_raw );
			if ( is_array( $upgrade ) ) {
				$from_blob = self::version_string( self::first_scalar( $upgrade, array( 'installed', 'current_version' ) ) );
				$offered   = self::version_string( self::first_scalar( $upgrade, array( 'new', 'new_version', 'latest' ) ) );
				if ( null === $installed && null !== $from_blob && null !== $offered ) {
					$installed = $from_blob;
				} elseif ( null === $offered ) {
					$current = self::version_string( isset( $upgrade['current'] ) ? $upgrade['current'] : null );
					if ( null !== $installed && null !== $current && version_compare( $current, $installed, '>' ) ) {
						$offered = $current;
					} elseif ( null === $installed ) {
						$installed = $current;
					}
				}
			}
		}

		$available = null;
		if ( null !== $offered && null !== $installed ) {
			$available = version_compare( $offered, $installed, '>' );
			if ( ! $available ) {
				$offered = null;
			}
		} elseif ( null !== $offered ) {
			$available = true;
		} elseif ( $known ) {
			$available = false;
		}

		return array(
			'wordpress_version' => $installed,
			'update_available'  => $available,
			'new_version'       => $available ? $offered : null,
		);
	}

	/**
	 * WordPress version from the site record. sync.version and site_info.child_version
	 * are the MainWP Child plugin, not WordPress.
	 *
	 * @param array<string,mixed> $sync Sync row.
	 * @param array<string,mixed> $options Option map.
	 * @param array<string,mixed> $website Website row.
	 * @return string|null
	 */
	private static function wordpress_version( array $sync, array $options, array $website ) {
		$candidates = array();
		foreach ( array( $options, $sync, $website ) as $bag ) {
			$info = isset( $bag['site_info'] ) ? self::decode_blob( $bag['site_info'] ) : null;
			if ( is_array( $info ) && isset( $info['wpversion'] ) ) {
				$candidates[] = $info['wpversion'];
			}
		}
		foreach ( array( $website, $sync, $options ) as $bag ) {
			if ( isset( $bag['wpversion'] ) ) {
				$candidates[] = $bag['wpversion'];
			}
		}
		foreach ( $candidates as $candidate ) {
			$version = self::version_string( $candidate );
			if ( null !== $version ) {
				return $version;
			}
		}

		return null;
	}

	/**
	 * @param bool  $known Whether the source included this list.
	 * @param array $blobs Raw upgrade blobs.
	 * @return array{pending_count:?int,pending:array<int,array<string,?string>>}
	 */
	private static function pending_components( $known, array $blobs ) {
		if ( ! $known ) {
			return array(
				'pending_count' => null,
				'pending'       => array(),
			);
		}

		$pending = array();
		foreach ( $blobs as $blob ) {
			if ( null === $blob || '' === $blob ) {
				continue;
			}
			$rows = self::decode_blob( $blob );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $key => $row ) {
				$item = self::pending_item( $key, $row );
				if ( $item ) {
					$pending[] = $item;
				}
			}
		}

		return array(
			'pending_count' => count( $pending ),
			'pending'       => $pending,
		);
	}

	/**
	 * @param mixed $key List key, often a plugin file.
	 * @param mixed $row Upgrade row.
	 * @return array{name:string,current_version:?string,new_version:?string}|null
	 */
	private static function pending_item( $key, $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$file = is_string( $key ) ? $key : '';
		foreach ( array( 'plugin', 'theme', 'file', 'slug' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$file = $row[ $field ];
				break;
			}
		}
		$name = self::label( self::first_scalar( $row, array( 'Name', 'name', 'title' ) ) );
		$slug = self::label( self::first_scalar( $row, array( 'slug' ) ) );
		if ( self::is_hidden_component( $name, $file, $slug ) ) {
			return null;
		}
		if ( '' === $name ) {
			if ( self::is_hidden_component( '', $file, $slug ) ) {
				return null;
			}
			$base = trim( str_replace( '\\', '/', '' !== $file ? $file : (string) $key ), '/' );
			if ( false !== strpos( $base, '/' ) ) {
				$base = dirname( $base );
			}
			$name = self::label( basename( $base ) );
			if ( '' === $name || self::is_hidden_component( $name, $file, $slug ) ) {
				return null;
			}
		}

		$current = self::version_string( self::first_scalar( $row, array( 'Version', 'version', 'old_version', 'current_version' ) ) );
		$new     = null;
		if ( isset( $row['update'] ) && is_array( $row['update'] ) ) {
			$new = self::version_string( self::first_scalar( $row['update'], array( 'new_version', 'version', 'new' ) ) );
		}
		if ( null === $new ) {
			$new = self::version_string( self::first_scalar( $row, array( 'new_version', 'new', 'available_version' ) ) );
		}

		$source = self::update_source_from_row( $row );

		return array(
			'name'             => $name,
			'current_version'  => $current,
			'new_version'      => $new,
			'update_available' => null !== $new,
			'update_source'    => $source,
		);
	}

	/**
	 * Plugins whose updates come from somewhere other than wordpress.org.
	 *
	 * Licence status stays unknown unless the plugin's own option or transient
	 * states active or expired. A missing date stays empty.
	 *
	 * @param array<string,mixed> $website Website row.
	 * @param array<string,mixed> $options Option map.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function premium_plugins( array $website, array $options ) {
		$known = false;
		foreach ( array( 'plugins', 'plugin_upgrades', 'premium_upgrades' ) as $key ) {
			if ( self::has_key( $website, $key ) || self::has_key( $options, $key ) ) {
				$known = true;
			}
		}
		if ( ! $known ) {
			return null;
		}

		$catalog  = array();
		$licences = self::licence_index( $options );
		foreach ( array( 'plugins', 'plugin_upgrades', 'premium_upgrades' ) as $field ) {
			$blob = self::value_of( $website, $options, $field );
			$rows = self::decode_blob( $blob );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$item = self::pending_item( $key, $row );
				if ( ! $item ) {
					continue;
				}
				$id = self::plugin_identity( is_string( $key ) ? $key : '', isset( $row['slug'] ) ? $row['slug'] : '' );
				if ( '' === $id ) {
					$id = self::plugin_identity( '', $item['name'] );
				}
				if ( '' === $id ) {
					continue;
				}
				if ( ! isset( $catalog[ $id ] ) ) {
					$catalog[ $id ] = $item;
					$catalog[ $id ]['from_premium_list'] = false;
				}
				if ( 'premium_upgrades' === $field ) {
					$catalog[ $id ]['from_premium_list'] = true;
				}
				if ( null === $catalog[ $id ]['update_source'] && null !== $item['update_source'] ) {
					$catalog[ $id ]['update_source'] = $item['update_source'];
				}
				if ( null !== $item['new_version'] ) {
					$catalog[ $id ]['new_version']      = $item['new_version'];
					$catalog[ $id ]['update_available'] = true;
				}
				if ( null === $catalog[ $id ]['current_version'] && null !== $item['current_version'] ) {
					$catalog[ $id ]['current_version'] = $item['current_version'];
				}
			}
		}

		$premium = array();
		foreach ( $catalog as $id => $item ) {
			$source = $item['update_source'];
			if ( 'wordpress.org' === $source ) {
				continue;
			}
			if ( null === $source && empty( $item['from_premium_list'] ) ) {
				continue;
			}
			if ( null === $source ) {
				$source = 'external';
			}
			$licence = isset( $licences[ $id ] ) ? $licences[ $id ] : array(
				'status'  => 'unknown',
				'expires' => null,
			);
			$premium[] = array(
				'name'               => $item['name'],
				'current_version'    => $item['current_version'],
				'new_version'        => $item['new_version'],
				'update_available'   => (bool) $item['update_available'],
				'update_source'      => $source,
				'licence_status'     => $licence['status'],
				'licence_expires_at' => $licence['expires'],
			);
		}

		return $premium;
	}

	/**
	 * @param array<string,mixed> $website Website row.
	 * @param array<string,mixed> $options Option map.
	 * @param string              $key plugins or themes.
	 * @return int|null
	 */
	private static function inventory_count( array $website, array $options, $key ) {
		if ( ! self::has_key( $website, $key ) && ! self::has_key( $options, $key ) ) {
			return null;
		}
		$rows = self::decode_blob( self::value_of( $website, $options, $key ) );
		if ( ! is_array( $rows ) ) {
			return null;
		}
		$count = 0;
		foreach ( $rows as $index => $row ) {
			$file = is_string( $index ) ? $index : '';
			$name = is_array( $row ) ? self::label( self::first_scalar( $row, array( 'Name', 'name', 'title' ) ) ) : '';
			$slug = is_array( $row ) ? self::label( self::first_scalar( $row, array( 'slug' ) ) ) : '';
			if ( self::is_hidden_component( $name, $file, $slug ) ) {
				continue;
			}
			$count++;
		}

		return $count;
	}

	/**
	 * @param array<string,mixed> $row Plugin or upgrade row.
	 * @return string|null wordpress.org, a package host, or null when no source is recorded.
	 */
	private static function update_source_from_row( array $row ) {
		$bags = array( $row );
		if ( isset( $row['update'] ) && is_array( $row['update'] ) ) {
			$bags[] = $row['update'];
		}
		foreach ( $bags as $bag ) {
			foreach ( array( 'package', 'package_url', 'download_link', 'UpdateURI', 'update_uri' ) as $key ) {
				if ( empty( $bag[ $key ] ) || ! is_string( $bag[ $key ] ) ) {
					continue;
				}
				$host = wp_parse_url( $bag[ $key ], PHP_URL_HOST );
				if ( ! is_string( $host ) || '' === $host ) {
					continue;
				}
				$host = strtolower( $host );
				if ( 0 === strpos( $host, 'www.' ) ) {
					$host = substr( $host, 4 );
				}
				if ( 'wordpress.org' === $host || substr( $host, -14 ) === '.wordpress.org' ) {
					return 'wordpress.org';
				}

				return $host;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $options Option map, including synced transients.
	 * @return array<string,array{status:string,expires:?string}>
	 */
	private static function licence_index( array $options ) {
		$index = array();
		foreach ( $options as $name => $value ) {
			$identity = self::licence_identity( $name );
			$decoded  = self::decode_blob( $value );
			$parsed   = self::parse_licence( null !== $decoded ? $decoded : $value );
			if ( null === $parsed['expires'] && preg_match( '/(?:expires|expiry|expiration)$/', strtolower( (string) $name ) ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$parsed['expires'] = self::licence_expiry( array( 'expires' => $value ) );
			}
			if ( '' === $identity && is_array( $decoded ) ) {
				$named = self::first_scalar( $decoded, array( 'slug', 'plugin', 'plugin_slug' ) );
				$identity = self::plugin_identity( '', $named );
			}
			if ( strlen( $identity ) < 4 ) {
				continue;
			}
			if ( 'unknown' === $parsed['status'] && null === $parsed['expires'] && ! preg_match( '/licen[cs]e/i', (string) $name ) ) {
				continue;
			}
			if ( ! isset( $index[ $identity ] ) ) {
				$index[ $identity ] = $parsed;
				continue;
			}
			if ( 'unknown' === $index[ $identity ]['status'] && 'unknown' !== $parsed['status'] ) {
				$index[ $identity ]['status'] = $parsed['status'];
			}
			if ( null === $index[ $identity ]['expires'] && null !== $parsed['expires'] ) {
				$index[ $identity ]['expires'] = $parsed['expires'];
			}
		}

		return $index;
	}

	/**
	 * @param string $name Option or transient name.
	 * @return string
	 */
	private static function licence_identity( $name ) {
		$name = strtolower( (string) $name );
		$name = preg_replace( '/^_transient_(timeout_)?/', '', $name );
		$name = preg_replace( '/^edd_/', '', $name );
		if ( ! preg_match( '/licen[cs]e/', $name ) ) {
			return '';
		}
		$name = preg_replace( '/_?licen[cs]e(?:_(?:status|data|key|expires|expiry|expiration))?.*$/', '', $name );

		return self::plugin_identity( '', $name );
	}

	/**
	 * @param string $file Plugin file.
	 * @param mixed  $slug Slug.
	 * @return string
	 */
	private static function plugin_identity( $file, $slug ) {
		$slug = strtolower( (string) $slug );
		$file = strtolower( str_replace( '\\', '/', (string) $file ) );
		if ( '' === $slug && false !== strpos( $file, '/' ) ) {
			$slug = dirname( $file );
		}
		if ( '' === $slug ) {
			$slug = $file;
		}
		$slug = preg_replace( '/\.php$/', '', $slug );

		return preg_replace( '/[^a-z0-9]+/', '', $slug );
	}

	/**
	 * @param mixed $payload Licence option value.
	 * @return array{status:string,expires:?string}
	 */
	private static function parse_licence( $payload ) {
		if ( is_string( $payload ) || is_numeric( $payload ) ) {
			return array(
				'status'  => self::normalise_licence_status( (string) $payload ),
				'expires' => null,
			);
		}
		if ( ! is_array( $payload ) ) {
			return array(
				'status'  => 'unknown',
				'expires' => null,
			);
		}

		$status_raw = self::first_scalar( $payload, array( 'license', 'licence', 'status', 'license_status', 'licence_status' ) );
		$expires    = self::licence_expiry( $payload );
		if ( null === $status_raw && null === $expires && isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			return self::parse_licence( $payload['data'] );
		}

		return array(
			'status'  => self::normalise_licence_status( null === $status_raw ? '' : (string) $status_raw ),
			'expires' => $expires,
		);
	}

	/**
	 * @param array<string,mixed> $payload Licence payload.
	 * @return string|null
	 */
	private static function licence_expiry( array $payload ) {
		$raw = self::first_scalar( $payload, array( 'expires', 'expiry', 'expiration', 'license_expires', 'licence_expires', 'expires_at', 'expiration_date' ) );
		if ( null === $raw || is_array( $raw ) ) {
			return null;
		}
		$text = strtolower( trim( (string) $raw ) );
		if ( '' === $text || in_array( $text, array( 'lifetime', 'never', 'unlimited' ), true ) ) {
			return null;
		}

		return self::iso( self::unix_of( $raw ) );
	}

	/**
	 * @param string $value Raw licence status.
	 * @return string active, expired, or unknown.
	 */
	private static function normalise_licence_status( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'valid', 'active', 'activated' ), true ) ) {
			return 'active';
		}
		if ( 'expired' === $value ) {
			return 'expired';
		}

		return 'unknown';
	}

	/**
	 * @param array<int,mixed> $logs MainWP log rows.
	 * @return array<int,array<string,?string>>
	 */
	private static function recent_updates( array $logs ) {
		$updates = array();
		foreach ( $logs as $log ) {
			$row  = self::row( $log );
			$text = strtolower( self::log_text( $row ) );
			if ( ! preg_match( '/\b(updated|upgraded|update installed|updates applied)\b/', $text ) ) {
				continue;
			}
			if ( preg_match( '/\b(available|ignored|check)\b/', $text ) && ! preg_match( '/\b(updated|upgraded)\b/', $text ) ) {
				continue;
			}

			$item = self::decode_blob( isset( $row['item'] ) ? $row['item'] : null );
			$name = '';
			$from = null;
			$to   = null;
			$file = '';
			if ( is_array( $item ) && ! self::looks_like_list( $item ) ) {
				$name = self::label( self::first_scalar( $item, array( 'name', 'Name', 'title' ) ) );
				$file = (string) self::first_scalar( $item, array( 'plugin', 'file', 'slug' ) );
				$from = self::version_string( self::first_scalar( $item, array( 'from', 'old_version', 'current_version', 'Version' ) ) );
				$to   = self::version_string( self::first_scalar( $item, array( 'to', 'new_version', 'new' ) ) );
			}
			if ( '' === $name ) {
				$name = self::label( isset( $row['item'] ) && is_scalar( $row['item'] ) ? $row['item'] : '' );
			}
			if ( self::is_hidden_component( $name, $file, $file ) || '' === $name ) {
				continue;
			}

			$type = self::update_type( $row, is_array( $item ) ? $item : array() );

			$updates[] = array(
				'name'         => $name,
				'type'         => $type,
				'from_version' => $from,
				'to_version'   => $to,
				'applied_at'   => self::iso( self::row_time( $row, array( 'created', 'created_at', 'time', 'date' ) ) ),
				'_sort'        => (int) self::row_time( $row, array( 'created', 'created_at', 'time', 'date' ) ),
			);
		}

		usort(
			$updates,
			function ( $a, $b ) {
				return $b['_sort'] - $a['_sort'];
			}
		);
		$updates = array_slice( $updates, 0, self::RECENT_UPDATES );
		foreach ( $updates as $index => $update ) {
			unset( $updates[ $index ]['_sort'] );
		}

		return array_values( $updates );
	}

	/**
	 * Core, plugin, or theme comes from the log context or an explicit type.
	 * A plugin whose name contains "core" stays a plugin.
	 *
	 * @param array<string,mixed> $row Log row.
	 * @param array<string,mixed> $item Decoded item.
	 * @return string|null
	 */
	private static function update_type( array $row, array $item ) {
		$context = strtolower( trim( (string) self::first_scalar( $row, array( 'context', 'connector' ) ) ) );
		$named   = strtolower( trim( (string) self::first_scalar( $item, array( 'type', 'update_type', 'kind' ) ) ) );
		foreach ( array( $named, $context ) as $value ) {
			if ( in_array( $value, array( 'plugin', 'plugins' ), true ) ) {
				return 'plugin';
			}
			if ( in_array( $value, array( 'theme', 'themes' ), true ) ) {
				return 'theme';
			}
			if ( in_array( $value, array( 'core', 'wordpress', 'wp_core', 'wpcore' ), true ) ) {
				return 'core';
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $sync Sync row.
	 * @param array<string,mixed> $options Option map.
	 * @return ?string
	 */
	private static function php_version( array $sync, array $options ) {
		foreach ( array( $sync, $options ) as $bag ) {
			if ( isset( $bag['phpversion'] ) ) {
				$version = self::version_string( $bag['phpversion'] );
				if ( null !== $version ) {
					return $version;
				}
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $sync Sync row.
	 * @param array<string,mixed> $options Option map.
	 * @return array{status:?string}
	 */
	private static function health( array $sync, array $options ) {
		$status = null;
		$blob   = null;
		if ( isset( $options['health_site_status'] ) ) {
			$blob = self::decode_blob( $options['health_site_status'] );
		} elseif ( isset( $sync['health_site_status'] ) ) {
			$blob = self::decode_blob( $sync['health_site_status'] );
		}
		if ( is_array( $blob ) ) {
			$named       = strtolower( (string) self::first_scalar( $blob, array( 'status', 'site_status' ) ) );
			$critical    = self::first_count( $blob, array( 'critical' ) );
			$recommended = self::first_count( $blob, array( 'recommended' ) );
			$good        = self::first_count( $blob, array( 'good' ) );
			if ( in_array( $named, array( 'critical', 'error' ), true ) ) {
				$status = 'critical';
			} elseif ( 'good' === $named && ( null === $critical || 0 === $critical ) ) {
				$status = 'good';
			} elseif ( null !== $critical && $critical > 0 ) {
				$status = 'needs_attention';
			} elseif ( in_array( $named, array( 'recommended', 'should_be_improved', 'should be improved' ), true ) || ( null !== $recommended && $recommended > 0 ) ) {
				$status = 'should_be_improved';
			} elseif ( 'good' === $named || ( null !== $good && $good > 0 ) ) {
				$status = 'good';
			}
		}

		if ( null === $status && isset( $sync['health_value'] ) && is_numeric( $sync['health_value'] ) && (int) $sync['health_value'] > 0 ) {
			$score  = (int) $sync['health_value'];
			$status = $score >= 80 ? 'good' : 'should_be_improved';
		}

		return array( 'status' => $status );
	}

	/**
	 * @param mixed $raw Security issue blob.
	 * @param bool  $known Whether the field was present.
	 * @return int|null
	 */
	private static function security_count( $raw, $known ) {
		if ( ! $known || null === $raw ) {
			return null;
		}
		if ( is_numeric( $raw ) && ! is_array( $raw ) ) {
			return max( 0, (int) $raw );
		}
		if ( is_string( $raw ) && '' === trim( $raw ) ) {
			return 0;
		}
		$decoded = self::decode_blob( $raw );
		if ( null === $decoded ) {
			return null;
		}
		if ( ! is_array( $decoded ) ) {
			return is_numeric( $decoded ) ? max( 0, (int) $decoded ) : null;
		}

		return count( $decoded );
	}

	/**
	 * @param array<string,mixed> $website Website row.
	 * @param array<int,mixed>    $monitors Monitor rows.
	 * @return array{monitored:bool,status:?string,checked_at:?string}
	 */
	private static function uptime( array $website, array $monitors ) {
		$chosen = null;
		foreach ( $monitors as $monitor ) {
			$row = self::row( $monitor );
			if ( isset( $row['active'] ) && 0 === (int) $row['active'] ) {
				continue;
			}
			if ( null === $chosen ) {
				$chosen = $row;
			}
			if ( isset( $row['issub'] ) && 0 === (int) $row['issub'] ) {
				$chosen = $row;
				break;
			}
		}

		if ( null !== $chosen ) {
			$status = self::monitor_status( isset( $chosen['last_status'] ) ? $chosen['last_status'] : null );
			return array(
				'monitored'  => true,
				'status'     => $status,
				'checked_at' => self::iso( self::unix_of( isset( $chosen['lasttime_check'] ) ? $chosen['lasttime_check'] : null ) ),
			);
		}

		if ( ! array_key_exists( 'offline_check_result', $website ) ) {
			return array(
				'monitored'  => false,
				'status'     => null,
				'checked_at' => null,
			);
		}

		$result = (int) $website['offline_check_result'];
		if ( 1 === $result || -1 === $result ) {
			return array(
				'monitored'  => true,
				'status'     => 1 === $result ? 'up' : 'down',
				'checked_at' => self::iso( self::unix_of( isset( $website['offline_checks_last'] ) ? $website['offline_checks_last'] : null ) ),
			);
		}

		return array(
			'monitored'  => false,
			'status'     => null,
			'checked_at' => null,
		);
	}

	/**
	 * @param mixed $status MainWP monitor last_status.
	 * @return string|null
	 */
	private static function monitor_status( $status ) {
		if ( null === $status || '' === $status || ! is_numeric( $status ) ) {
			return null;
		}
		$code = (int) $status;
		if ( 1 === $code ) {
			return 'up';
		}
		if ( 0 === $code || ( $code >= 20 && $code <= 23 ) ) {
			return 'down';
		}

		return null;
	}

	/**
	 * Last backup comes from a primary backup timestamp or a backup plugin history
	 * option such as UpdraftPlus. MainWP leaves both empty when no backup method
	 * is selected, and the site row has no backup history column.
	 *
	 * @param array<string,mixed> $website Website row.
	 * @param array<string,mixed> $options Option map.
	 * @param array<int,mixed>    $tasks Backup tasks already limited to this site.
	 * @param int                 $now Unix time.
	 * @return array{last:array,next_scheduled:?string,next_unix:?int,recent:array}
	 */
	private static function backups( array $website, array $options, array $tasks, $now ) {
		$points = array();
		self::collect_backup_points( $points, isset( $website['backups'] ) ? $website['backups'] : null );
		foreach ( $options as $name => $value ) {
			if ( ! self::is_backup_option( $name ) ) {
				continue;
			}
			if ( in_array( $name, array( 'primary_lasttime_backup', 'primaryLasttimeBackup', 'mainwp_lasttime_backup' ), true ) && ! is_array( $value ) ) {
				$time = self::unix_of( $value );
				if ( $time ) {
					$points[] = array( 'time' => $time, 'status' => null );
				}
				continue;
			}
			self::collect_backup_points( $points, $value, null );
			$decoded = self::decode_blob( $value );
			if ( is_array( $decoded ) && ! self::looks_like_list( $decoded ) ) {
				foreach ( array( 'next', 'next_scheduled', 'next_backup', 'next_scheduled_backup' ) as $key ) {
					if ( isset( $decoded[ $key ] ) ) {
						$next = self::unix_of( $decoded[ $key ] );
						if ( $next && $next > $now ) {
							$points[] = array( 'time' => null, 'status' => null, 'next' => $next );
						}
					}
				}
			}
		}

		$next_candidates = array();
		foreach ( $points as $point ) {
			if ( ! empty( $point['next'] ) ) {
				$next_candidates[] = array(
					'time'     => (int) $point['next'],
					'schedule' => null,
				);
			}
		}

		foreach ( $tasks as $task ) {
			$row  = self::row( $task );
			$time = self::unix_of( isset( $row['last'] ) ? $row['last'] : null );
			if ( ! $time ) {
				$time = self::unix_of( isset( $row['last_run'] ) ? $row['last_run'] : null );
			}
			if ( $time ) {
				$status = null;
				if ( ! empty( $row['backup_errors'] ) ) {
					$status = 'failed';
				} elseif ( ! empty( $row['completed'] ) ) {
					$status = 'success';
				}
				$points[] = array(
					'time'   => $time,
					'status' => $status,
					'type'   => isset( $row['type'] ) ? $row['type'] : null,
					'size'   => isset( $row['size'] ) ? $row['size'] : null,
				);
			}
			$scheduled = self::next_from_schedule(
				isset( $row['schedule'] ) ? $row['schedule'] : '',
				self::unix_of( isset( $row['last_run'] ) ? $row['last_run'] : ( isset( $row['last'] ) ? $row['last'] : null ) ),
				$now
			);
			if ( $scheduled ) {
				$next_candidates[] = array(
					'time'     => $scheduled,
					'schedule' => self::schedule_label( isset( $row['schedule'] ) ? $row['schedule'] : '' ),
				);
			}
		}

		$history = array();
		foreach ( $points as $point ) {
			if ( empty( $point['time'] ) ) {
				continue;
			}
			$key  = (int) $point['time'];
			$type = self::backup_type_label( isset( $point['type'] ) ? $point['type'] : null );
			$size = self::backup_size_label( isset( $point['size'] ) ? $point['size'] : null );
			if ( ! isset( $history[ $key ] ) ) {
				$history[ $key ] = array(
					'time'   => $key,
					'status' => self::backup_status( isset( $point['status'] ) ? $point['status'] : null ),
					'type'   => $type,
					'size'   => $size,
				);
				continue;
			}
			if ( null === $history[ $key ]['status'] && null !== self::backup_status( isset( $point['status'] ) ? $point['status'] : null ) ) {
				$history[ $key ]['status'] = self::backup_status( $point['status'] );
			}
			if ( null === $history[ $key ]['type'] && null !== $type ) {
				$history[ $key ]['type'] = $type;
			}
			if ( null === $history[ $key ]['size'] && null !== $size ) {
				$history[ $key ]['size'] = $size;
			}
		}
		krsort( $history );
		$history = array_values( $history );

		$last = array(
			'time'   => null,
			'status' => null,
		);
		if ( ! empty( $history ) ) {
			$last = array(
				'time'   => self::iso( $history[0]['time'] ),
				'status' => $history[0]['status'],
			);
		}

		$recent = array();
		foreach ( array_slice( $history, 0, self::RECENT_BACKUPS ) as $point ) {
			$recent[] = array(
				'time'   => self::iso( $point['time'] ),
				'status' => $point['status'],
				'type'   => $point['type'],
				'size'   => $point['size'],
			);
		}

		$next_unix     = null;
		$next_schedule = null;
		foreach ( $next_candidates as $candidate ) {
			if ( $candidate['time'] > $now && ( null === $next_unix || $candidate['time'] < $next_unix ) ) {
				$next_unix     = $candidate['time'];
				$next_schedule = $candidate['schedule'];
			}
		}

		return array(
			'last'           => $last,
			'next_scheduled' => self::iso( $next_unix ),
			'next_schedule'  => $next_schedule,
			'next_unix'      => $next_unix,
			'recent'         => $recent,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $points Points collected so far.
	 * @param mixed                          $value Raw backup value.
	 * @param string|null                    $default_status Status when a record does not say.
	 */
	private static function collect_backup_points( array &$points, $value, $default_status = null ) {
		$decoded = self::decode_blob( $value );
		if ( ! is_array( $decoded ) ) {
			return;
		}
		$rows = self::looks_like_list( $decoded ) ? $decoded : array_values( $decoded );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$time = self::row_time( $row, array( 'time', 'timestamp', 'backup_time', 'lasttime', 'date', 'dts', 'created', 'gmttime', 'lastbackup_gmttime' ) );
			if ( ! $time ) {
				continue;
			}
			$status = self::first_scalar( $row, array( 'status', 'result', 'state' ) );
			if ( null === $status || '' === $status ) {
				if ( ! empty( $row['error'] ) || ! empty( $row['errors'] ) || ! empty( $row['backup_errors'] ) ) {
					$status = 'failed';
				} elseif ( isset( $row['success'] ) ) {
					$status = $row['success'] ? 'success' : 'failed';
				} else {
					$status = $default_status;
				}
			}
			$points[] = array(
				'time'   => $time,
				'status' => $status,
				'type'   => self::first_scalar( $row, array( 'type', 'backup_type', 'kind' ) ),
				'size'   => self::first_scalar( $row, array( 'size', 'filesize', 'bytes', 'archive_size' ) ),
			);
		}
	}

	/**
	 * @param string   $schedule MainWP schedule name.
	 * @param int|null $last Last run.
	 * @param int      $now Unix time.
	 * @return int|null
	 */
	private static function next_from_schedule( $schedule, $last, $now ) {
		$last = (int) $last;
		if ( $last <= 0 ) {
			return null;
		}
		$step = 0;
		switch ( strtolower( trim( (string) $schedule ) ) ) {
			case 'hourly':
				$step = 3600;
				break;
			case 'daily':
				$step = 86400;
				break;
			case 'weekly':
				$step = 604800;
				break;
			case 'biweekly':
			case 'fortnightly':
				$step = 1209600;
				break;
			case 'monthly':
				$step = 2592000;
				break;
			default:
				return null;
		}

		$next = $last + $step;
		$guard = 0;
		while ( $next <= $now && $guard < 36 ) {
			$next += $step;
			$guard++;
		}

		return $next > $now ? $next : null;
	}

	/**
	 * @param mixed $status Raw status.
	 * @return string|null
	 */
	private static function backup_status( $status ) {
		if ( null === $status || '' === $status || is_array( $status ) ) {
			return null;
		}
		$text = strtolower( trim( (string) $status ) );
		if ( in_array( $text, array( '1', 'true', 'success', 'ok', 'complete', 'completed', 'finished', 'done' ), true ) ) {
			return 'success';
		}
		if ( in_array( $text, array( '0', 'false', 'failed', 'fail', 'error' ), true ) ) {
			return 'failed';
		}
		if ( in_array( $text, array( 'warning', 'partial' ), true ) ) {
			return 'warning';
		}
		if ( in_array( $text, array( 'running', 'in progress', 'in_progress' ), true ) ) {
			return 'running';
		}

		return null;
	}

	/**
	 * @param mixed $value Raw backup type.
	 * @return string|null
	 */
	private static function backup_type_label( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$text = strtolower( trim( (string) $value ) );
		if ( '' === $text ) {
			return null;
		}
		if ( in_array( $text, array( 'full', 'complete', 'all' ), true ) ) {
			return 'Full';
		}
		if ( in_array( $text, array( 'db', 'database' ), true ) ) {
			return 'Database';
		}
		if ( in_array( $text, array( 'file', 'files' ), true ) ) {
			return 'Files';
		}
		if ( strlen( $text ) > 24 ) {
			return null;
		}

		return ucfirst( $text );
	}

	/**
	 * @param mixed $value Raw size in bytes or a unit string.
	 * @return string|null
	 */
	private static function backup_size_label( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			$bytes = (int) $value;
			if ( $bytes <= 0 ) {
				return null;
			}
			if ( $bytes >= 1099511627776 ) {
				return self::size_unit( $bytes / 1099511627776, 'TB' );
			}
			if ( $bytes >= 1073741824 ) {
				return self::size_unit( $bytes / 1073741824, 'GB' );
			}
			if ( $bytes >= 1048576 ) {
				return self::size_unit( $bytes / 1048576, 'MB' );
			}
			if ( $bytes >= 1024 ) {
				return self::size_unit( $bytes / 1024, 'KB' );
			}

			return $bytes . ' B';
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = trim( $value );
		if ( preg_match( '/^\d+(?:\.\d+)?\s?(?:B|KB|MB|GB|TB)$/i', $text ) ) {
			return preg_replace( '/\s*(B|KB|MB|GB|TB)$/i', ' $1', $text );
		}

		return null;
	}

	/**
	 * @param float  $amount Amount.
	 * @param string $unit Unit.
	 * @return string
	 */
	private static function size_unit( $amount, $unit ) {
		$rounded = round( $amount, 1 );
		if ( abs( $rounded - round( $rounded ) ) < 0.05 ) {
			return (string) (int) round( $rounded ) . ' ' . $unit;
		}

		return number_format( $rounded, 1, '.', '' ) . ' ' . $unit;
	}

	/**
	 * @param mixed $schedule Schedule token.
	 * @return string|null
	 */
	private static function schedule_label( $schedule ) {
		switch ( strtolower( trim( (string) $schedule ) ) ) {
			case 'hourly':
				return 'Hourly';
			case 'daily':
				return 'Daily';
			case 'weekly':
				return 'Weekly';
			case 'biweekly':
			case 'fortnightly':
				return 'Every two weeks';
			case 'monthly':
				return 'Monthly';
			default:
				return null;
		}
	}

	/**
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function is_backup_option( $name ) {
		$name = strtolower( (string) $name );
		foreach ( array( 'backup', 'updraft', 'backwpup', 'timecapsule', 'time_capsule', 'wpvivid' ) as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * MainWP's synced user rows have a login and a role, not a last-login time.
	 * WordPress core does not record one, so this stays empty unless a plugin
	 * field such as last_login is actually present.
	 *
	 * @param array<string,mixed> $website Website row.
	 * @param array<string,mixed> $options Option map.
	 * @return array{time:?string,username:?string}
	 */
	private static function last_admin_login( array $website, array $options ) {
		$best = null;
		$bags = array( $options );
		if ( isset( $website['users'] ) ) {
			$bags[] = array( 'users' => $website['users'] );
		}
		foreach ( $bags as $bag ) {
			foreach ( $bag as $name => $value ) {
				self::collect_login_candidates( strtolower( (string) $name ), self::decode_blob( $value ), $value, $best );
			}
		}

		if ( null === $best ) {
			return array(
				'time'     => null,
				'username' => null,
			);
		}

		return array(
			'time'     => self::iso( $best['time'] ),
			'username' => $best['username'],
		);
	}

	/**
	 * @param string   $name Source name.
	 * @param mixed    $decoded Decoded value.
	 * @param mixed    $raw Raw value.
	 * @param array|null $best Best candidate so far.
	 */
	private static function collect_login_candidates( $name, $decoded, $raw, &$best ) {
		$forced = in_array( $name, array( 'last_admin_login', 'admin_last_login', 'last_administrator_login' ), true );
		if ( is_array( $decoded ) && self::looks_like_list( $decoded ) ) {
			foreach ( $decoded as $row ) {
				self::consider_login( $row, $forced, $best );
			}
			return;
		}
		if ( is_array( $decoded ) ) {
			if ( self::row_time( $decoded, array( 'time', 'timestamp', 'date', 'last_login', 'created' ) ) ) {
				self::consider_login( $decoded, $forced, $best );
			}
			foreach ( $decoded as $key => $value ) {
				if ( is_array( $value ) ) {
					self::collect_login_candidates( is_string( $key ) ? $key : $name, $value, $value, $best );
				}
			}
			return;
		}
		if ( $forced ) {
			self::consider_login(
				array(
					'time'     => $raw,
					'username' => null,
					'role'     => 'administrator',
				),
				true,
				$best
			);
		}
	}

	/**
	 * @param mixed      $row Login row.
	 * @param bool       $forced Source is already an admin-login field.
	 * @param array|null $best Best candidate.
	 */
	private static function consider_login( $row, $forced, &$best ) {
		if ( ! is_array( $row ) ) {
			return;
		}
		$time = self::row_time( $row, array( 'time', 'timestamp', 'date', 'last_login', 'created', 'created_at' ) );
		if ( ! $time ) {
			return;
		}
		$role = self::first_scalar( $row, array( 'role', 'roles', 'user_role' ) );
		if ( is_array( $role ) ) {
			$role = implode( ' ', $role );
		}
		$is_admin = $forced || ( is_string( $role ) && ( false !== strpos( strtolower( $role ), 'administrator' ) || 'admin' === strtolower( trim( $role ) ) ) );
		if ( ! $is_admin ) {
			return;
		}
		$username = self::username( self::first_scalar( $row, array( 'username', 'user_login', 'login', 'user' ) ) );
		if ( null === $best || $time > $best['time'] || ( $time === $best['time'] && null === $best['username'] && null !== $username ) ) {
			$best = array(
				'time'     => $time,
				'username' => $username,
			);
		}
	}

	/**
	 * @param array<string,mixed> $website Website row.
	 * @param array<string,mixed> $sync Sync row.
	 * @param array<string,mixed> $options Option map.
	 * @param mixed               $explicit Explicit content payload.
	 * @return array<string,array<string,mixed>>
	 */
	private static function content( array $website, array $sync, array $options, $explicit ) {
		$products = array(
			'published'    => null,
			'draft'        => null,
			'out_of_stock' => null,
		);
		$posts = array(
			'published'         => null,
			'last_published_at' => null,
		);
		$pages = array(
			'published'         => null,
			'last_published_at' => null,
		);

		if ( is_array( $explicit ) ) {
			self::apply_content_blob( $explicit, $products, $posts, $pages );
		}
		foreach ( $options as $name => $value ) {
			if ( ! preg_match( '/woo|product|content|catalog|post_count|page_count|published_post|published_page/i', (string) $name ) ) {
				continue;
			}
			$decoded = self::decode_blob( $value );
			if ( is_array( $decoded ) ) {
				self::apply_content_blob( array( $name => $decoded ), $products, $posts, $pages );
			} elseif ( is_numeric( $value ) ) {
				self::apply_content_blob( array( $name => $value ), $products, $posts, $pages );
			}
		}
		if ( isset( $website['pages'] ) ) {
			$pages_blob = self::decode_blob( $website['pages'] );
			if ( is_array( $pages_blob ) ) {
				self::fill_publication( $pages_blob, $pages, false );
			}
		}

		if ( isset( $options['recent_posts'] ) ) {
			self::fill_publication( self::decode_blob( $options['recent_posts'] ), $posts, true );
		}
		if ( isset( $options['recent_pages'] ) ) {
			self::fill_publication( self::decode_blob( $options['recent_pages'] ), $pages, true );
		}
		if ( null === $posts['last_published_at'] && isset( $sync['last_post_gmt'] ) ) {
			$posts['last_published_at'] = self::iso( self::unix_of( $sync['last_post_gmt'] ) );
		}

		return array(
			'products' => $products,
			'posts'    => $posts,
			'pages'    => $pages,
		);
	}

	/**
	 * @param mixed $blob Content blob.
	 * @param array $products Product counts.
	 * @param array $posts Post counts.
	 * @param array $pages Page counts.
	 */
	private static function apply_content_blob( $blob, array &$products, array &$posts, array &$pages ) {
		if ( ! is_array( $blob ) ) {
			return;
		}
		if ( self::looks_like_list( $blob ) ) {
			return;
		}
		foreach ( $blob as $key => $value ) {
			$key_l = strtolower( (string) $key );
			if ( preg_match( '/product/', $key_l ) ) {
				self::fill_products( $value, $products );
				continue;
			}
			if ( preg_match( '/^posts?$|post_count|published_posts/', $key_l ) ) {
				self::fill_publication( $value, $posts, false );
				continue;
			}
			if ( preg_match( '/^pages?$|page_count|published_pages/', $key_l ) ) {
				self::fill_publication( $value, $pages, false );
				continue;
			}
			if ( in_array( $key_l, array( 'out_of_stock', 'outofstock', 'out-of-stock' ), true ) ) {
				$count = self::count_value( $value );
				if ( null !== $count && null === $products['out_of_stock'] ) {
					$products['out_of_stock'] = $count;
				}
				continue;
			}
			if ( is_array( $value ) ) {
				self::apply_content_blob( $value, $products, $posts, $pages );
			}
		}
	}

	/**
	 * @param mixed $value Product payload.
	 * @param array $products Product counts.
	 */
	private static function fill_products( $value, array &$products ) {
		if ( is_numeric( $value ) ) {
			if ( null === $products['published'] ) {
				$products['published'] = self::count_value( $value );
			}
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		if ( isset( $value['counts'] ) && is_array( $value['counts'] ) ) {
			self::fill_products( $value['counts'], $products );
		}
		if ( isset( $value['stock'] ) && is_array( $value['stock'] ) ) {
			$stock = self::first_count( $value['stock'], array( 'out_of_stock', 'outofstock', 'out-of-stock' ) );
			if ( null !== $stock && null === $products['out_of_stock'] ) {
				$products['out_of_stock'] = $stock;
			}
		}
		$published = self::first_count( $value, array( 'published', 'publish' ) );
		$draft     = self::first_count( $value, array( 'draft', 'drafts' ) );
		$stock     = self::first_count( $value, array( 'out_of_stock', 'outofstock', 'out-of-stock' ) );
		if ( null !== $published && null === $products['published'] ) {
			$products['published'] = $published;
		}
		if ( null !== $draft && null === $products['draft'] ) {
			$products['draft'] = $draft;
		}
		if ( null !== $stock && null === $products['out_of_stock'] ) {
			$products['out_of_stock'] = $stock;
		}
	}

	/**
	 * @param mixed $value Posts or pages payload.
	 * @param array $target Target counts.
	 * @param bool  $dates_only Recent lists must not become a total.
	 */
	private static function fill_publication( $value, array &$target, $dates_only ) {
		if ( is_numeric( $value ) ) {
			if ( ! $dates_only && null === $target['published'] ) {
				$target['published'] = self::count_value( $value );
			}
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		if ( self::looks_like_list( $value ) ) {
			$count  = 0;
			$saw    = false;
			$latest = null;
			foreach ( $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$has_status = array_key_exists( 'status', $row ) || array_key_exists( 'post_status', $row );
				$status     = strtolower( (string) self::first_scalar( $row, array( 'status', 'post_status' ) ) );
				$published  = ! $has_status || in_array( $status, array( 'publish', 'published' ), true );
				if ( ! $published ) {
					continue;
				}
				if ( $has_status ) {
					$saw = true;
					$count++;
				}
				$time = self::row_time( $row, array( 'published_at', 'post_date_gmt', 'post_date', 'date', 'dts' ) );
				if ( $time && ( null === $latest || $time > $latest ) ) {
					$latest = $time;
				}
			}
			if ( ! $dates_only && $saw && null === $target['published'] ) {
				$target['published'] = $count;
			}
			if ( $latest && null === $target['last_published_at'] ) {
				$target['last_published_at'] = self::iso( $latest );
			}
			return;
		}

		if ( ! $dates_only ) {
			$published = self::first_count( $value, array( 'published', 'publish' ) );
			if ( null !== $published && null === $target['published'] ) {
				$target['published'] = $published;
			}
		}
		$when = self::row_time( $value, array( 'last_published_at', 'last_published', 'published_at' ) );
		if ( $when && null === $target['last_published_at'] ) {
			$target['last_published_at'] = self::iso( $when );
		}
	}

	/**
	 * @param array<int,mixed>    $logs MainWP logs.
	 * @param array<int,mixed>    $audit Bridge audit rows for this portal site.
	 * @param array<string,mixed> $options Option map.
	 * @param int                 $now Unix time.
	 * @return array{last_run:?array,next_scheduled:?string,recent:array}
	 */
	private static function activity( array $logs, array $audit, array $options, $now ) {
		$events = array();
		foreach ( $logs as $log ) {
			$event = self::event_from_text( self::log_text( self::row( $log ) ), self::row_time( self::row( $log ), array( 'created', 'created_at', 'time', 'date' ) ), self::row( $log ) );
			if ( $event ) {
				$events[] = $event;
			}
		}
		foreach ( $audit as $row ) {
			$record = self::row( $row );
			$event  = self::event_from_text(
				trim( (string) ( isset( $record['event_type'] ) ? $record['event_type'] : '' ) . ' ' . ( isset( $record['message'] ) ? $record['message'] : '' ) ),
				self::row_time( $record, array( 'created_at', 'created', 'time' ) ),
				$record
			);
			if ( $event ) {
				$events[] = $event;
			}
		}
		foreach ( $options as $name => $value ) {
			if ( ! preg_match( '/report|activit|child_report|stream/i', (string) $name ) ) {
				continue;
			}
			$decoded = self::decode_blob( $value );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$rows = self::looks_like_list( $decoded ) ? $decoded : array( $decoded );
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$text  = self::log_text( $row );
				$event = self::event_from_text( $text, self::row_time( $row, array( 'created', 'created_at', 'time', 'date', 'dts' ) ), $row );
				if ( $event ) {
					$events[] = $event;
				}
			}
		}

		usort(
			$events,
			function ( $a, $b ) {
				return $a['at'] - $b['at'];
			}
		);

		$runs    = array();
		$current = null;
		foreach ( $events as $event ) {
			if ( null === $current || ( $event['at'] - $current['end'] ) > self::RUN_GAP ) {
				if ( null !== $current ) {
					$runs[] = $current;
				}
				$current = $event;
				$current['end'] = $event['at'];
				continue;
			}
			$current['end'] = $event['at'];
			foreach ( array( 'updates_applied', 'backup', 'security_scan', 'optimisation' ) as $flag ) {
				if ( true === $event[ $flag ] ) {
					$current[ $flag ] = true;
				} elseif ( null === $current[ $flag ] && false === $event[ $flag ] ) {
					$current[ $flag ] = false;
				}
			}
		}
		if ( null !== $current ) {
			$runs[] = $current;
		}

		$public = array();
		foreach ( $runs as $run ) {
			$public[] = array(
				'date'             => self::iso( $run['at'] ),
				'updates_applied'  => $run['updates_applied'],
				'backup'           => $run['backup'],
				'security_scan'    => $run['security_scan'],
				'optimisation'     => $run['optimisation'],
				'_sort'            => $run['at'],
			);
		}
		usort(
			$public,
			function ( $a, $b ) {
				return $b['_sort'] - $a['_sort'];
			}
		);
		foreach ( $public as $index => $run ) {
			unset( $public[ $index ]['_sort'] );
		}

		$next = null;
		foreach ( $options as $name => $value ) {
			if ( ! preg_match( '/next_maintenance|maintenance_next|next_scheduled_run|next_run/i', (string) $name ) ) {
				continue;
			}
			$when = self::unix_of( $value );
			if ( ! $when ) {
				$decoded = self::decode_blob( $value );
				$when    = is_array( $decoded ) ? self::row_time( $decoded, array( 'time', 'date', 'next', 'timestamp' ) ) : null;
			}
			if ( $when && $when > $now && ( null === $next || $when < $next ) ) {
				$next = $when;
			}
		}

		$recent = array_slice( $public, 0, self::RECENT_RUNS );

		return array(
			'last_run'       => ! empty( $recent ) ? $recent[0] : null,
			'next_scheduled' => self::iso( $next ),
			'recent'         => $recent,
		);
	}

	/**
	 * @param string   $text Log text.
	 * @param int|null $at Unix time.
	 * @param array    $row Original row.
	 * @return array<string,mixed>|null
	 */
	private static function event_from_text( $text, $at, array $row ) {
		if ( ! $at || self::activity_event_is_request( $row, $text ) ) {
			return null;
		}
		$flags = array(
			'updates_applied' => null,
			'backup'          => null,
			'security_scan'   => null,
			'optimisation'    => null,
		);
		$decoded = self::decode_blob( isset( $row['message'] ) ? $row['message'] : null );
		if ( ! is_array( $decoded ) ) {
			$decoded = self::decode_blob( isset( $row['item'] ) ? $row['item'] : null );
		}
		if ( is_array( $decoded ) && ! self::looks_like_list( $decoded ) ) {
			foreach ( array(
				'updates_applied' => array( 'updates_applied', 'updates' ),
				'backup'          => array( 'backup' ),
				'security_scan'   => array( 'security_scan', 'security' ),
				'optimisation'    => array( 'optimisation', 'optimization' ),
			) as $flag => $keys ) {
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $decoded ) && is_bool( $decoded[ $key ] ) ) {
						$flags[ $flag ] = $decoded[ $key ];
					} elseif ( array_key_exists( $key, $decoded ) && is_numeric( $decoded[ $key ] ) ) {
						$flags[ $flag ] = (bool) $decoded[ $key ];
					}
				}
			}
		}

		$plain = strtolower( wp_strip_all_tags( (string) $text ) );
		$finished = (bool) preg_match( '/\b(completed|finished|succeeded|success|complete)\b/', $plain );
		if ( null === $flags['updates_applied'] && preg_match( '/\b(updated|upgraded|updates applied|applied updates|update installed)\b/', $plain ) && ! preg_match( '/\bavailable\b/', $plain ) ) {
			$flags['updates_applied'] = true;
		}
		if ( null === $flags['backup'] && $finished && preg_match( '/\b(backup|updraft|backwpup|time capsule|wpvivid)\b/', $plain ) ) {
			$flags['backup'] = true;
		}
		if ( null === $flags['security_scan'] && $finished && preg_match( '/\b(security scan|malware scan|vulnerability scan|wordfence scan|sucuri scan)\b/', $plain ) ) {
			$flags['security_scan'] = true;
		}
		if ( null === $flags['optimisation'] && preg_match( '/\b(optimised|optimized|cache purged|cache flushed|cleaned database|database cleaned)\b/', $plain ) ) {
			$flags['optimisation'] = true;
		}

		$useful = false;
		foreach ( $flags as $flag ) {
			if ( null !== $flag ) {
				$useful = true;
				break;
			}
		}
		if ( ! $useful ) {
			return null;
		}

		return array_merge( array( 'at' => (int) $at ), $flags );
	}

	/**
	 * A request, dispatch, or schedule mention is not a completed service result.
	 *
	 * @param array<string,mixed> $row Source row.
	 * @param string              $text Combined log text.
	 * @return bool
	 */
	private static function activity_event_is_request( array $row, $text ) {
		$type = strtolower( trim( (string) ( isset( $row['event_type'] ) ? $row['event_type'] : '' ) ) );
		if ( '' !== $type && preg_match( '/(?:^|_)(requested|sent|failed)$/', $type ) ) {
			return true;
		}

		$plain = strtolower( wp_strip_all_tags( (string) $text ) );
		$finished = (bool) preg_match( '/\b(completed|finished|succeeded|success|complete)\b/', $plain );
		if ( ! $finished && preg_match( '/\b(requested|queued|dispatched|starting|scheduled)\b/', $plain ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $row Log row.
	 * @return string
	 */
	private static function log_text( array $row ) {
		$parts = array();
		foreach ( array( 'action', 'context', 'connector', 'item', 'summary', 'message', 'event_type', 'name' ) as $key ) {
			if ( ! isset( $row[ $key ] ) || is_array( $row[ $key ] ) ) {
				continue;
			}
			$parts[] = (string) $row[ $key ];
		}

		return trim( implode( ' ', $parts ) );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array|null
	 */
	private static function decode_blob( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return self::row( $value );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return array();
		}
		$first = $value[0];
		if ( '{' === $first || '[' === $first ) {
			$json = json_decode( $value, true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}
		if ( preg_match( '/^(a|s|i|b|d|N|O|C):/', $value ) ) {
			$decoded = unserialize( $value, array( 'allowed_classes' => false ) );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * @param mixed $row Row.
	 * @return array<string,mixed>
	 */
	private static function row( $row ) {
		if ( is_array( $row ) ) {
			return $row;
		}
		if ( is_object( $row ) ) {
			return get_object_vars( $row );
		}

		return array();
	}

	/**
	 * @param mixed $options Options.
	 * @return array<string,mixed>
	 */
	private static function option_map( $options ) {
		if ( ! is_array( $options ) ) {
			return array();
		}
		$map = array();
		foreach ( $options as $key => $value ) {
			if ( is_object( $value ) && isset( $value->name ) ) {
				$map[ (string) $value->name ] = isset( $value->value ) ? $value->value : null;
				continue;
			}
			if ( is_array( $value ) && isset( $value['name'] ) && array_key_exists( 'value', $value ) ) {
				$map[ (string) $value['name'] ] = $value['value'];
				continue;
			}
			if ( is_string( $key ) ) {
				$map[ $key ] = $value;
			}
		}

		return $map;
	}

	/**
	 * @param array<string,mixed> $website Website fields.
	 * @param array<string,mixed> $options Options.
	 * @param string              $key Field name.
	 * @return mixed
	 */
	private static function value_of( array $website, array $options, $key ) {
		$has_option = array_key_exists( $key, $options );
		$has_site   = array_key_exists( $key, $website );
		if ( $has_site && $has_option && self::list_has_rows( $website[ $key ] ) && ! self::list_has_rows( $options[ $key ] ) ) {
			return $website[ $key ];
		}
		if ( $has_option ) {
			return $options[ $key ];
		}
		if ( $has_site ) {
			return $website[ $key ];
		}

		return null;
	}

	/**
	 * An empty [] copy in the options table is not the site record.
	 *
	 * @param mixed $value Raw list.
	 * @return bool
	 */
	private static function list_has_rows( $value ) {
		if ( null === $value ) {
			return false;
		}
		if ( is_string( $value ) && in_array( trim( $value ), array( '', '[]', '{}', 'a:0:{}' ), true ) ) {
			return false;
		}
		$decoded = self::decode_blob( $value );
		if ( is_array( $decoded ) ) {
			return count( $decoded ) > 0;
		}

		return is_numeric( $value ) || ( is_string( $value ) && '' !== trim( $value ) );
	}

	/**
	 * @param array<string,mixed> $bag Bag.
	 * @param string              $key Key.
	 * @return bool
	 */
	private static function has_key( array $bag, $key ) {
		return array_key_exists( $key, $bag );
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @param array<int,string>   $keys Candidate keys.
	 * @return mixed
	 */
	private static function first_scalar( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) {
				return $row[ $key ];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @param array<int,string>   $keys Candidate keys.
	 * @return int|null
	 */
	private static function first_count( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) ) {
				return self::count_value( $row[ $key ] );
			}
		}

		return null;
	}

	/**
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function count_value( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$count = (int) $value;

		return $count >= 0 ? $count : null;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @param array<int,string>   $keys Candidate keys.
	 * @return int|null
	 */
	private static function row_time( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$time = self::unix_of( $row[ $key ] );
			if ( $time ) {
				return $time;
			}
		}

		return null;
	}

	/**
	 * @param mixed $value Time value.
	 * @return int|null
	 */
	private static function unix_of( $value ) {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			return self::unix_from_magnitude( $value );
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$time = strtotime( $value );
			return $time ? (int) $time : null;
		}

		return null;
	}

	/**
	 * MainWP log times are sometimes microseconds. Seconds stay below 1e11,
	 * milliseconds sit around 1e12, and microseconds sit around 1e15.
	 *
	 * @param mixed $value Numeric time.
	 * @return int|null
	 */
	private static function unix_from_magnitude( $value ) {
		if ( is_string( $value ) && preg_match( '/^\d+\.\d+$/', trim( $value ) ) ) {
			$value = (float) $value;
		}
		$time = (int) $value;
		if ( $time <= 0 ) {
			return null;
		}

		$digits = strlen( (string) $time );
		// MainWP action rows are indexed with time() concatenated to a 4-digit suffix.
		if ( $digits >= 13 && $digits <= 15 ) {
			$stripped = (int) floor( $time / 10000 );
			if ( self::plausible_unix( $stripped ) ) {
				return $stripped;
			}
		}

		$guard = 0;
		while ( $time > 4102444800 && $guard < 4 ) {
			$time = (int) round( $time / 1000 );
			$guard++;
		}

		return self::plausible_unix( $time ) ? $time : null;
	}

	/**
	 * @param int $time Unix seconds.
	 * @return bool
	 */
	private static function plausible_unix( $time ) {
		return $time >= 946684800 && $time <= 4102444800;
	}

	/**
	 * Named zone from the synced site, or a fixed offset when only gmt_offset is stored.
	 *
	 * @param array<string,mixed> $website Website row.
	 * @param array<string,mixed> $sync Sync row.
	 * @param array<string,mixed> $options Option map.
	 * @return string|null
	 */
	private static function reported_timezone( array $website, array $sync, array $options ) {
		$bags = array();
		foreach ( array( $options, $sync, $website ) as $bag ) {
			$info = isset( $bag['site_info'] ) ? self::decode_blob( $bag['site_info'] ) : null;
			if ( is_array( $info ) ) {
				if ( isset( $info['format_datetime'] ) && is_array( $info['format_datetime'] ) ) {
					$bags[] = $info['format_datetime'];
				}
				$bags[] = $info;
			}
			$bags[] = $bag;
		}
		foreach ( $bags as $bag ) {
			foreach ( array( 'timezone_string', 'timezone', 'site_timezone' ) as $key ) {
				if ( empty( $bag[ $key ] ) || ! is_string( $bag[ $key ] ) ) {
					continue;
				}
				if ( self::is_timezone( $bag[ $key ] ) ) {
					return $bag[ $key ];
				}
			}
		}
		foreach ( $bags as $bag ) {
			if ( ! isset( $bag['gmt_offset'] ) || ! is_numeric( $bag['gmt_offset'] ) ) {
				continue;
			}
			$offset = self::offset_timezone( $bag['gmt_offset'] );
			if ( self::is_timezone( $offset ) ) {
				return $offset;
			}
		}

		return null;
	}

	/**
	 * @param string $name Timezone identifier.
	 * @return bool
	 */
	private static function is_timezone( $name ) {
		try {
			new DateTimeZone( $name );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * @param mixed $offset Hours east of GMT. May include a half hour.
	 * @return string
	 */
	private static function offset_timezone( $offset ) {
		$offset = (float) $offset;
		$sign   = $offset >= 0 ? '+' : '-';
		$abs    = abs( $offset );
		$hours  = (int) floor( $abs );
		$mins   = (int) round( ( $abs - $hours ) * 60 );
		if ( $mins >= 60 ) {
			$hours++;
			$mins = 0;
		}

		return sprintf( '%s%02d:%02d', $sign, $hours, $mins );
	}

	/**
	 * @param int|null $time Unix time.
	 * @return string|null
	 */
	private static function iso( $time ) {
		$time = (int) $time;
		if ( $time <= 0 ) {
			return null;
		}

		return gmdate( 'c', $time );
	}

	/**
	 * @param mixed $value Version.
	 * @return string|null
	 */
	private static function version_string( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$text = trim( (string) $value );
		if ( '' === $text || strlen( $text ) > 40 ) {
			return null;
		}
		if ( ! preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $text, $match ) ) {
			return null;
		}

		return $match[1];
	}

	/**
	 * @param mixed $value Label.
	 * @return string
	 */
	private static function label( $value ) {
		$text = trim( wp_strip_all_tags( (string) $value ) );
		if ( strlen( $text ) > 180 ) {
			$text = substr( $text, 0, 180 );
		}

		return $text;
	}

	/**
	 * @param mixed $value Username.
	 * @return string|null
	 */
	private static function username( $value ) {
		$text = self::label( $value );
		if ( '' === $text || false !== strpos( $text, '@' ) || strlen( $text ) > 60 ) {
			return null;
		}
		if ( ! preg_match( '/^[A-Za-z0-9._ -]+$/', $text ) ) {
			return null;
		}

		return $text;
	}

	/**
	 * @param mixed $url URL.
	 * @return string
	 */
	private static function public_url( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		return preg_replace( '/\s+/', '', $url );
	}

	/**
	 * @param object|array|null $site Site.
	 * @param string            $key Field.
	 * @return mixed
	 */
	private static function site_value( $site, $key ) {
		if ( is_object( $site ) && isset( $site->{$key} ) ) {
			return $site->{$key};
		}
		if ( is_array( $site ) && isset( $site[ $key ] ) ) {
			return $site[ $key ];
		}

		return null;
	}

	/**
	 * @param array $value Value.
	 * @return bool
	 */
	private static function looks_like_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}
		$index = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}
			$index++;
		}

		return true;
	}
}
