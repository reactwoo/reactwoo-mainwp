<?php
/**
 * On-site snapshot of plugin files, theme files, and the database.
 *
 * MainWP 6.4.1 has no REST route that starts a backup. Its built-in backup
 * ability needs a WordPress application password, which this bridge does not
 * have. This snapshot needs no extra service. It lives on the same site, so a
 * disk failure or a compromised site can remove it with the site. It is a
 * rollback point for a bad update. Uploads are left out on purpose. There is
 * no off-site copy: a destroyed or compromised server removes the snapshot
 * with the site. The backup_ref is what a later report or rollback can carry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Snapshot {
	const MAX_ARCHIVE_BYTES = 268435456;
	const MAX_FILE_BYTES    = 33554432;
	const KEEP              = 3;

	public static function register_routes() {
		register_rest_route(
			'reactwoo-connector/v2',
			'/snapshot',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( 'RW_Connector_Signing', 'verify_request' ),
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$site_id = (int) get_option( 'rw_connector_site_id' );
		if ( ! $site_id ) {
			return new WP_Error( 'rw_connector_not_connected', 'Connector is not enrolled.', array( 'status' => 404 ) );
		}

		$purpose = (string) $request->get_param( 'purpose' );
		if ( ! in_array( $purpose, array( 'customer', 'pre_update' ), true ) ) {
			$purpose = 'customer';
		}

		$result = self::capture( $purpose );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param string $purpose customer|pre_update.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function capture( $purpose ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the website paths are not available.', array( 'status' => 500 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 );
		}

		$directory = rtrim( WP_CONTENT_DIR, '/\\' ) . '/reactwoo-private/snapshots';
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0750, true ) && ! is_dir( $directory ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot folder could not be created.', array( 'status' => 500 ) );
		}
		self::deny_web_access( dirname( $directory ) );
		self::deny_web_access( $directory );

		try {
			$token = bin2hex( random_bytes( 4 ) );
		} catch ( Exception $e ) {
			$token = substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		$sql = $directory . '/.partial-' . $token . '.sql';

		global $wpdb;
		$exported = self::export_database( $wpdb, $sql, self::max_archive_bytes() );
		if ( is_wp_error( $exported ) ) {
			if ( is_file( $sql ) ) {
				unlink( $sql );
			}
			return $exported;
		}

		$themes = function_exists( 'get_theme_root' ) ? (string) get_theme_root() : rtrim( WP_CONTENT_DIR, '/\\' ) . '/themes';
		$result = self::build_archive(
			$directory,
			array(
				array(
					'name' => 'plugins',
					'path' => WP_PLUGIN_DIR,
				),
				array(
					'name' => 'themes',
					'path' => $themes,
				),
			),
			$sql,
			array(
				'purpose'        => (string) $purpose,
				'omitted_tables' => $exported['omitted'],
			)
		);
		if ( is_file( $sql ) ) {
			unlink( $sql );
		}

		return $result;
	}

	/**
	 * @param int|null $now     Unix time.
	 * @param string|null $entropy Hex entropy.
	 * @return string
	 */
	public static function make_reference( $now = null, $entropy = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( null === $entropy || '' === (string) $entropy ) {
			try {
				$entropy = bin2hex( random_bytes( 8 ) );
			} catch ( Exception $e ) {
				$entropy = substr( md5( uniqid( '', true ) ), 0, 8 );
			}
		}
		$entropy = strtolower( (string) preg_replace( '/[^a-f0-9]/', '', (string) $entropy ) );
		$entropy = substr( str_pad( $entropy, 8, '0' ), 0, 32 );

		return 'rwsnap_' . gmdate( 'Ymd\\THis\\Z', $now ) . '_' . $entropy;
	}

	/**
	 * @param string $reference Candidate reference.
	 * @return bool
	 */
	public static function valid_reference( $reference ) {
		return 1 === preg_match( '/^rwsnap_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{8,32}$/', (string) $reference );
	}

	/**
	 * @param string               $directory Destination directory.
	 * @param array<int,array{name:string,path:string}> $trees Named source trees.
	 * @param string               $sql_path SQL file to store as database.sql.
	 * @param array<string,mixed>  $meta Manifest fields. No absolute paths.
	 * @param array<string,int>    $limits Optional max_file_bytes and max_archive_bytes.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function build_archive( $directory, array $trees, $sql_path, array $meta = array(), array $limits = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'rw_snapshot_unavailable', 'This website cannot save a snapshot because the PHP zip extension is not available.', array( 'status' => 500 ) );
		}
		if ( ! is_file( $sql_path ) || filesize( $sql_path ) < 1 ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the database export was empty.', array( 'status' => 500 ) );
		}
		if ( empty( $trees ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because there were no files to include.', array( 'status' => 500 ) );
		}

		$directory = rtrim( (string) $directory, '/\\' );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0750, true ) && ! is_dir( $directory ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot folder could not be created.', array( 'status' => 500 ) );
		}
		self::deny_web_access( $directory );

		$max_file    = isset( $limits['max_file_bytes'] ) ? (int) $limits['max_file_bytes'] : self::max_file_bytes();
		$max_archive = isset( $limits['max_archive_bytes'] ) ? (int) $limits['max_archive_bytes'] : self::max_archive_bytes();
		$reference   = isset( $meta['backup_ref'] ) && self::valid_reference( $meta['backup_ref'] ) ? (string) $meta['backup_ref'] : self::make_reference();
		$partial     = $directory . '/' . $reference . '.zip.partial';
		$final       = $directory . '/' . $reference . '.zip';
		$skipped     = array();
		$includes    = array( 'database' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $partial, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the archive could not be opened.', array( 'status' => 500 ) );
		}

		foreach ( $trees as $tree ) {
			$name = isset( $tree['name'] ) ? (string) $tree['name'] : '';
			$path = isset( $tree['path'] ) ? (string) $tree['path'] : '';
			if ( ! in_array( $name, array( 'plugins', 'themes' ), true ) || ! is_dir( $path ) ) {
				$zip->close();
				if ( is_file( $partial ) ) {
					unlink( $partial );
				}
				return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the plugin or theme files could not be read.', array( 'status' => 500 ) );
			}
			$added = self::add_tree( $zip, $path, $name, $skipped, $max_file );
			if ( is_wp_error( $added ) ) {
				$zip->close();
				if ( is_file( $partial ) ) {
					unlink( $partial );
				}
				return $added;
			}
			$includes[] = $name;
		}

		$zip->addFile( $sql_path, 'database.sql' );
		$created = gmdate( 'c' );
		$manifest = array(
			'backup_ref'     => $reference,
			'created_at'     => $created,
			'purpose'        => isset( $meta['purpose'] ) ? (string) $meta['purpose'] : 'customer',
			'includes'       => $includes,
			'excludes'       => array( 'uploads' ),
			'location'       => 'onsite',
			'omitted_tables' => isset( $meta['omitted_tables'] ) && is_array( $meta['omitted_tables'] ) ? array_values( $meta['omitted_tables'] ) : array(),
			'skipped_count'  => count( $skipped ),
			'skipped'        => array_slice( $skipped, 0, 50 ),
			'rollback'       => array(
				'supported' => false,
				'note'      => 'Uploads are not included. This snapshot stays on the website and is lost if the server is destroyed or compromised. Restoring it is a later step.',
			),
		);
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $manifest ) : json_encode( $manifest );
		$zip->addFromString( 'manifest.json', (string) $encoded );
		$zip->close();

		$bytes = is_file( $partial ) ? (int) filesize( $partial ) : 0;
		if ( $bytes < 1 || $bytes > $max_archive ) {
			if ( is_file( $partial ) ) {
				unlink( $partial );
			}
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because it would be larger than the limit. Nothing was kept.', array( 'status' => 500 ) );
		}
		if ( is_file( $final ) ) {
			unlink( $final );
		}
		if ( ! rename( $partial, $final ) ) {
			if ( is_file( $partial ) ) {
				unlink( $partial );
			}
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved.', array( 'status' => 500 ) );
		}

		self::prune( $directory, self::KEEP );
		self::remember( $reference, $created, $bytes, $includes, (string) $manifest['purpose'] );

		return array(
			'ok'         => true,
			'backup_ref' => $reference,
			'created_at' => $created,
			'bytes'      => $bytes,
			'includes'   => $includes,
			'location'   => 'onsite',
			'purpose'    => (string) $manifest['purpose'],
		);
	}

	/**
	 * @param object $wpdb Database handle.
	 * @param string $destination SQL path.
	 * @param int    $max_bytes Abort above this size.
	 * @return array{omitted:string[]}|WP_Error
	 */
	public static function export_database( $wpdb, $destination, $max_bytes ) {
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the database could not be read.', array( 'status' => 500 ) );
		}
		$prefix = (string) $wpdb->prefix;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the database could not be read.', array( 'status' => 500 ) );
		}

		$handle = fopen( $destination, 'wb' );
		if ( false === $handle ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the database export could not be written.', array( 'status' => 500 ) );
		}

		fwrite( $handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" );
		$omitted = array();
		$tables  = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $tables ) ) {
			fclose( $handle );
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the database could not be read.', array( 'status' => 500 ) );
		}

		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( 0 !== strpos( $table, $prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			$suffix = self::omitted_suffix( substr( $table, strlen( $prefix ) ) );
			if ( '' !== $suffix ) {
				$omitted[] = $suffix;
				continue;
			}
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_A );
			$create_sql = '';
			if ( is_array( $create ) ) {
				foreach ( $create as $key => $value ) {
					if ( is_string( $key ) && false !== stripos( $key, 'create' ) && is_string( $value ) ) {
						$create_sql = $value;
						break;
					}
				}
			}
			if ( '' === $create_sql ) {
				fclose( $handle );
				return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because a database table could not be read.', array( 'status' => 500 ) );
			}
			fwrite( $handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create_sql . ";\n" );

			$offset = 0;
			while ( true ) {
				$rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '` LIMIT ' . (int) $offset . ', 100', ARRAY_A );
				if ( ! is_array( $rows ) || empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					fwrite( $handle, self::insert_sql( $table, $row ) . "\n" );
				}
				$offset += 100;
				$written = ftell( $handle );
				if ( false !== $written && $written > $max_bytes ) {
					fclose( $handle );
					return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because it would be larger than the limit. Nothing was kept.', array( 'status' => 500 ) );
				}
				if ( count( $rows ) < 100 ) {
					break;
				}
			}
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );

		return array( 'omitted' => array_values( array_unique( $omitted ) ) );
	}

	/**
	 * @param string $directory Snapshot directory.
	 * @param int    $keep How many archives to retain.
	 * @return void
	 */
	public static function prune( $directory, $keep ) {
		$files = glob( rtrim( (string) $directory, '/\\' ) . '/rwsnap_*.zip' );
		if ( ! is_array( $files ) || count( $files ) <= (int) $keep ) {
			return;
		}
		usort(
			$files,
			function ( $left, $right ) {
				return filemtime( $right ) <=> filemtime( $left );
			}
		);
		foreach ( array_slice( $files, (int) $keep ) as $old ) {
			if ( is_file( $old ) ) {
				unlink( $old );
			}
		}
	}

	/**
	 * @param string $directory Snapshot directory.
	 * @return void
	 */
	public static function deny_web_access( $directory ) {
		$directory = rtrim( (string) $directory, '/\\' );
		$htaccess  = $directory . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}
		$web_config = $directory . '/web.config';
		if ( ! is_file( $web_config ) ) {
			file_put_contents(
				$web_config,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n"
			);
		}
		$index = $directory . '/index.php';
		if ( ! is_file( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * @param ZipArchive $zip Archive.
	 * @param string     $root Absolute source directory.
	 * @param string     $prefix Zip folder name.
	 * @param array      $skipped Skipped files, relative only.
	 * @param int        $max_file Bytes.
	 * @return true|WP_Error
	 */
	private static function add_tree( ZipArchive $zip, $root, $prefix, array &$skipped, $max_file ) {
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return new WP_Error( 'rw_snapshot_failed', 'The snapshot was not saved because the plugin or theme files could not be read.', array( 'status' => 500 ) );
		}

		$stack = array( $root_real );
		while ( ! empty( $stack ) ) {
			$dir   = array_pop( $stack );
			$items = scandir( $dir );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				$rel  = self::relative_path( $root_real, $path );
				if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
					continue;
				}
				if ( is_link( $path ) ) {
					$skipped[] = array(
						'path'   => $prefix . '/' . $rel,
						'reason' => 'symlink',
					);
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( in_array( $item, array( 'cache', 'node_modules', '.git', 'upgrade' ), true ) ) {
						$skipped[] = array(
							'path'   => $prefix . '/' . $rel,
							'reason' => 'excluded',
						);
						continue;
					}
					$stack[] = $path;
					continue;
				}
				if ( ! is_file( $path ) ) {
					continue;
				}
				$real = realpath( $path );
				if ( false === $real || 0 !== strpos( $real, $root_real . DIRECTORY_SEPARATOR ) ) {
					$skipped[] = array(
						'path'   => $prefix . '/' . $rel,
						'reason' => 'outside',
					);
					continue;
				}
				$size = filesize( $real );
				if ( false !== $size && $size > $max_file ) {
					$skipped[] = array(
						'path'   => $prefix . '/' . $rel,
						'reason' => 'too_large',
					);
					continue;
				}
				$zip->addFile( $real, $prefix . '/' . str_replace( '\\', '/', $rel ) );
			}
		}

		return true;
	}

	/**
	 * @param string $root Absolute root.
	 * @param string $path File path.
	 * @return string
	 */
	private static function relative_path( $root, $path ) {
		$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
		$path = str_replace( '\\', '/', (string) $path );
		if ( 0 !== strpos( $path, $root . '/' ) ) {
			return '';
		}

		return substr( $path, strlen( $root ) + 1 );
	}

	/**
	 * @param string $suffix Table name without the WordPress prefix.
	 * @return string Omitted suffix, or empty when the table is included.
	 */
	public static function omitted_suffix( $suffix ) {
		$skip = array(
			'actionscheduler_logs',
			'woocommerce_sessions',
			'wc_sessions',
			'wfhits',
			'wflogins',
			'wftrafficrates',
			'wfstatus',
		);

		return in_array( (string) $suffix, $skip, true ) ? (string) $suffix : '';
	}

	/**
	 * @param string              $table Table name, already validated.
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private static function insert_sql( $table, array $row ) {
		$columns = array();
		$values  = array();
		foreach ( $row as $column => $value ) {
			$column = (string) $column;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
				continue;
			}
			$columns[] = '`' . $column . '`';
			if ( null === $value ) {
				$values[] = 'NULL';
				continue;
			}
			$values[] = "'" . self::sql_escape( (string) $value ) . "'";
		}

		return 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ');';
	}

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sql_escape( $value ) {
		return str_replace(
			array( '\\', "'", "\n", "\r", "\x00", "\x1a" ),
			array( '\\\\', "\\'", '\\n', '\\r', '\\0', '\\Z' ),
			$value
		);
	}

	/**
	 * @param string $reference Reference.
	 * @param string $created ISO time.
	 * @param int    $bytes Size.
	 * @param array  $includes Included parts.
	 * @param string $purpose Purpose.
	 * @return void
	 */
	private static function remember( $reference, $created, $bytes, array $includes, $purpose ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$stored = get_option( 'rw_connector_snapshots', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored[ $reference ] = array(
			'created_at' => $created,
			'bytes'      => (int) $bytes,
			'includes'   => array_values( $includes ),
			'purpose'    => $purpose,
			'location'   => 'onsite',
		);
		if ( count( $stored ) > 10 ) {
			$stored = array_slice( $stored, -10, null, true );
		}
		update_option( 'rw_connector_snapshots', $stored, false );
	}

	/**
	 * @return int
	 */
	private static function max_archive_bytes() {
		$limit = self::MAX_ARCHIVE_BYTES;
		if ( function_exists( 'apply_filters' ) ) {
			$limit = (int) apply_filters( 'rw_connector_snapshot_max_bytes', $limit );
		}

		return $limit > 0 ? $limit : self::MAX_ARCHIVE_BYTES;
	}

	/**
	 * @return int
	 */
	private static function max_file_bytes() {
		$limit = self::MAX_FILE_BYTES;
		if ( function_exists( 'apply_filters' ) ) {
			$limit = (int) apply_filters( 'rw_connector_snapshot_max_file_bytes', $limit );
		}

		return $limit > 0 ? $limit : self::MAX_FILE_BYTES;
	}
}
