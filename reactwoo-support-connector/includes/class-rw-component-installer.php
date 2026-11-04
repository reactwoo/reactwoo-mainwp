<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the two maintenance components the connector is allowed to fetch.
 *
 * Callers cannot supply a download URL. Packages come from the ReactWoo update
 * service when that signed URL is on the R2 host, otherwise from the pinned
 * wordpress.org zip. Either way the bytes must match the expected SHA-256.
 */
class RW_Component_Installer {
	const UPDATES_URL = 'https://api.reactwoo.com/api/v5/updates';

	public static function register_routes() {
		register_rest_route(
			'reactwoo-connector/v2',
			'/install-components',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( 'RW_Connector_Signing', 'verify_request' ),
			)
		);
	}

	/**
	 * @return array<string,array{version:string,sha256:string,plugin_file:string}>
	 */
	public static function catalog() {
		return array(
			'mainwp-child'         => array(
				'version'     => '6.4',
				'sha256'      => 'd893fdb6326f0fe93a0668585e647acf1fa425b71ac5b1dcd7ec15649bc689eb',
				'plugin_file' => 'mainwp-child/mainwp-child.php',
			),
			'mainwp-child-reports' => array(
				'version'     => '2.3.1',
				'sha256'      => '97436d887c544c8c0b965bcda024cf62a252342fb162bcb161d2182cdd25b0a9',
				'plugin_file' => 'mainwp-child-reports/mainwp-child-reports.php',
			),
		);
	}

	/**
	 * @param mixed $params Request JSON.
	 * @return string[]|WP_Error
	 */
	public static function requested_slugs( $params ) {
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'rw_component_not_allowed', 'Maintenance components were not recognised.', array( 'status' => 400 ) );
		}

		if ( self::contains_forbidden_key( $params ) ) {
			return new WP_Error( 'rw_component_url_rejected', 'Component downloads cannot use a supplied address.', array( 'status' => 400 ) );
		}

		$catalog = self::catalog();
		if ( ! array_key_exists( 'components', $params ) ) {
			return array_keys( $catalog );
		}

		if ( ! is_array( $params['components'] ) || empty( $params['components'] ) ) {
			return new WP_Error( 'rw_component_not_allowed', 'No allowed maintenance component was requested.', array( 'status' => 400 ) );
		}

		$slugs = array();
		foreach ( $params['components'] as $slug ) {
			$slug = is_string( $slug ) ? strtolower( trim( $slug ) ) : '';
			if ( ! isset( $catalog[ $slug ] ) ) {
				return new WP_Error( 'rw_component_not_allowed', 'That maintenance component is not allowed.', array( 'status' => 400 ) );
			}
			$slugs[] = $slug;
		}

		return array_values( array_unique( $slugs ) );
	}

	public static function allowed_package_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );
		if ( 'downloads.wordpress.org' === $host ) {
			return true;
		}
		if ( self::is_r2_host( $host ) ) {
			return true;
		}

		$extra = apply_filters( 'rw_connector_r2_download_hosts', array() );
		$extra = array_map( 'strtolower', array_map( 'strval', (array) $extra ) );

		return in_array( $host, $extra, true );
	}

	public static function valid_sha256( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( $value ) );
	}

	public static function package_hash_matches( $path, $expected ) {
		if ( ! self::valid_sha256( $expected ) || ! is_string( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$actual = hash_file( 'sha256', $path );
		if ( ! is_string( $actual ) ) {
			return false;
		}

		return hash_equals( strtolower( $expected ), strtolower( $actual ) );
	}

	public static function handle( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$slugs  = self::requested_slugs( is_array( $params ) ? $params : array() );
		if ( is_wp_error( $slugs ) ) {
			return $slugs;
		}

		$result = self::install_slugs( $slugs );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param string[] $slugs Allowlisted slugs.
	 * @return array|WP_Error
	 */
	public static function install_slugs( array $slugs ) {
		$installed = array();
		foreach ( $slugs as $slug ) {
			$ready = self::ensure_slug( $slug );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$installed[ $slug ] = $ready;
		}

		$identity = null;
		if ( in_array( 'mainwp-child', $slugs, true ) || ( class_exists( 'RW_MainWP_Child_Adapter' ) && RW_MainWP_Child_Adapter::is_active() ) ) {
			$identity = RW_MainWP_Child_Adapter::passwordless_identity();
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
		}

		$child_active = class_exists( 'RW_MainWP_Child_Adapter' ) && RW_MainWP_Child_Adapter::is_active();
		if ( $child_active && ! empty( $identity['uniqueid'] ) ) {
			update_option( 'rw_connector_managed', '1', false );
		}

		return array(
			'installed'    => $installed,
			'child_active' => $child_active,
			'has_unique_id' => $child_active && ! empty( $identity['uniqueid'] ),
			'managed'      => '1' === (string) get_option( 'rw_connector_managed' ),
		);
	}

	/**
	 * @param string $slug Allowlisted slug.
	 * @return array|WP_Error
	 */
	private static function ensure_slug( $slug ) {
		$catalog = self::catalog();
		$file    = $catalog[ $slug ]['plugin_file'];
		self::load_plugin_api();

		if ( 'mainwp-child' === $slug && class_exists( 'RW_MainWP_Child_Adapter' ) ) {
			RW_MainWP_Child_Adapter::backup_and_clear_inactive_link();
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) && self::plugin_file_exists( $file ) ) {
			return array(
				'status'  => 'active',
				'version' => self::plugin_version( $file ),
			);
		}

		if ( self::plugin_installed( $file ) ) {
			$activated = activate_plugin( $file, '', false, true );
			if ( is_wp_error( $activated ) ) {
				return new WP_Error( 'rw_component_install_failed', 'The maintenance component could not be activated.', array( 'status' => 500 ) );
			}
			return array(
				'status'  => 'active',
				'version' => self::plugin_version( $file ),
			);
		}

		$package = self::resolve_package( $slug );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$staged = self::stage_package( $package['download_url'], $package['sha256'] );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		$installed = self::install_staged_package( $staged );
		self::delete_file( $staged );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$activated = activate_plugin( $file, '', false, true );
		if ( is_wp_error( $activated ) ) {
			return new WP_Error( 'rw_component_install_failed', 'The maintenance component could not be activated.', array( 'status' => 500 ) );
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		return array(
			'status'  => 'active',
			'version' => self::plugin_version( $file ),
		);
	}

	/**
	 * @param string $slug Allowlisted slug.
	 * @return array|WP_Error
	 */
	private static function resolve_package( $slug ) {
		$remote = self::package_from_api( $slug );
		if ( is_array( $remote ) ) {
			return $remote;
		}

		$pin = self::catalog()[ $slug ];
		$url = 'https://downloads.wordpress.org/plugin/' . $slug . '.' . $pin['version'] . '.zip';
		if ( ! self::allowed_package_url( $url ) || ! self::valid_sha256( $pin['sha256'] ) ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component package is not available.', array( 'status' => 502 ) );
		}

		return array(
			'download_url' => $url,
			'sha256'       => strtolower( $pin['sha256'] ),
		);
	}

	/**
	 * @param string $slug Allowlisted slug.
	 * @return array|null
	 */
	private static function package_from_api( $slug ) {
		$queries = array(
			add_query_arg(
				array(
					'slug'    => $slug,
					'channel' => 'stable',
				),
				self::UPDATES_URL
			),
		);

		foreach ( $queries as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
			$parsed = self::package_from_response( $response );
			if ( is_array( $parsed ) ) {
				return $parsed;
			}
		}

		$response = wp_remote_post(
			self::UPDATES_URL . '/check',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'slug'            => $slug,
						'current_version' => '0.0.0',
						'channel'         => 'stable',
						'site_host'       => 'connector.reactwoo',
					)
				),
			)
		);

		$parsed = self::package_from_response( $response );
		return is_array( $parsed ) ? $parsed : null;
	}

	/**
	 * @param mixed $response HTTP response.
	 * @return array|null
	 */
	private static function package_from_response( $response ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$url  = '';
		$hash = '';
		foreach ( array( 'download_url', 'signed_url', 'url' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$url = $data[ $key ];
				break;
			}
		}
		foreach ( array( 'sha256', 'checksum', 'hash' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$hash = strtolower( $data[ $key ] );
				break;
			}
		}

		if ( ! self::allowed_package_url( $url ) || ! self::valid_sha256( $hash ) ) {
			return null;
		}

		return array(
			'download_url' => $url,
			'sha256'       => $hash,
		);
	}

	/**
	 * @param string $url Package URL.
	 * @param string $expected SHA-256 hex.
	 * @return string|WP_Error Local path.
	 */
	private static function stage_package( $url, $expected ) {
		$downloaded = self::download_package( $url, 0 );
		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		if ( ! self::package_hash_matches( $downloaded, $expected ) ) {
			self::delete_file( $downloaded );
			return new WP_Error( 'rw_component_hash_mismatch', 'The maintenance component did not match the expected package.', array( 'status' => 409 ) );
		}

		$handle = fopen( $downloaded, 'rb' );
		$magic  = $handle ? fread( $handle, 2 ) : '';
		if ( $handle ) {
			fclose( $handle );
		}
		if ( 'PK' !== $magic ) {
			self::delete_file( $downloaded );
			return new WP_Error( 'rw_component_hash_mismatch', 'The maintenance component did not match the expected package.', array( 'status' => 409 ) );
		}

		return $downloaded;
	}

	/**
	 * @param string $url Package URL.
	 * @param int    $hop Redirect count.
	 * @return string|WP_Error
	 */
	private static function download_package( $url, $hop ) {
		if ( $hop > 2 || ! self::allowed_package_url( $url ) ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component could not be downloaded.', array( 'status' => 502 ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component could not be downloaded.', array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_array( $location ) ) {
				$location = reset( $location );
			}
			$location = is_scalar( $location ) ? (string) $location : '';
			$next     = self::absolute_url( $url, $location );
			return self::download_package( $next, $hop + 1 );
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component could not be downloaded.', array( 'status' => 502 ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) < 4 ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component could not be downloaded.', array( 'status' => 502 ) );
		}

		$path = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'rw-component.zip' ) : tempnam( sys_get_temp_dir(), 'rwpkg' );
		if ( ! is_string( $path ) || '' === $path ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component could not be downloaded.', array( 'status' => 502 ) );
		}

		$written = file_put_contents( $path, $body );
		if ( false === $written ) {
			return new WP_Error( 'rw_component_download_failed', 'The maintenance component could not be downloaded.', array( 'status' => 502 ) );
		}

		return $path;
	}

	/**
	 * Shared hosts often select FTP even when PHP can write the plugins directory.
	 *
	 * @param string $method Filesystem method WordPress selected.
	 * @return string
	 */
	public static function direct_filesystem_method( $method = '' ) {
		unset( $method );
		return 'direct';
	}

	private static function install_staged_package( $path ) {
		self::load_upgrader();
		if ( ! class_exists( 'Plugin_Upgrader' ) || ! self::connect_filesystem() ) {
			return new WP_Error( 'rw_component_install_failed', 'The maintenance component could not be installed.', array( 'status' => 500 ) );
		}

		$skin_file = __DIR__ . '/class-rw-quiet-upgrader-skin.php';
		if ( class_exists( 'Automatic_Upgrader_Skin' ) && is_readable( $skin_file ) ) {
			require_once $skin_file;
		}
		if ( ! class_exists( 'RW_Quiet_Upgrader_Skin' ) && ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return new WP_Error( 'rw_component_install_failed', 'The maintenance component could not be installed.', array( 'status' => 500 ) );
		}

		$skin     = class_exists( 'RW_Quiet_Upgrader_Skin' ) ? new RW_Quiet_Upgrader_Skin() : new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $path );
		if ( is_wp_error( $result ) || true !== $result ) {
			return new WP_Error( 'rw_component_install_failed', 'The maintenance component could not be installed.', array( 'status' => 500 ) );
		}

		return true;
	}

	private static function delete_file( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $path );
			return;
		}
		unlink( $path );
	}

	public static function plugin_version( $file ) {
		if ( 'mainwp-child/mainwp-child.php' === $file && defined( 'MAINWP_CHILD_VERSION' ) ) {
			return (string) MAINWP_CHILD_VERSION;
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'get_file_data' ) ) {
			return '';
		}

		$path = WP_PLUGIN_DIR . '/' . ltrim( (string) $file, '/' );
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$data = get_file_data( $path, array( 'Version' => 'Version' ) );
		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	private static function plugin_file_exists( $file ) {
		if ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) {
			return is_file( trailingslashit( WP_PLUGIN_DIR ) . ltrim( (string) $file, '/' ) );
		}

		return self::plugin_installed( $file );
	}

	private static function plugin_installed( $file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			return false;
		}
		$plugins = get_plugins();
		return isset( $plugins[ $file ] );
	}

	private static function load_plugin_api() {
		if ( defined( 'ABSPATH' ) && ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	private static function connect_filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return false;
		}

		if ( self::plugins_dir_is_writable() ) {
			add_filter( 'filesystem_method', array( __CLASS__, 'direct_filesystem_method' ) );
		}

		$context = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : false;
		if ( WP_Filesystem( false, $context, true ) ) {
			return true;
		}

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			return false;
		}

		$creds = request_filesystem_credentials( admin_url(), '', false, $context ? $context : false, null );
		if ( ! $creds ) {
			return false;
		}

		return (bool) WP_Filesystem( $creds, $context, true );
	}

	private static function plugins_dir_is_writable() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! is_string( WP_PLUGIN_DIR ) || '' === WP_PLUGIN_DIR ) {
			return false;
		}

		return is_dir( WP_PLUGIN_DIR ) && is_writable( WP_PLUGIN_DIR );
	}

	private static function load_upgrader() {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
	}

	private static function is_r2_host( $host ) {
		$host = strtolower( (string) $host );
		if ( 'r2.cloudflarestorage.com' === $host || 'r2.dev' === $host ) {
			return true;
		}

		$suffixes = array( '.r2.cloudflarestorage.com', '.r2.dev' );
		foreach ( $suffixes as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	private static function contains_forbidden_key( $params ) {
		$blocked = array( 'url', 'download_url', 'package', 'src', 'plugin_url', 'signed_url' );
		foreach ( $params as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $blocked, true ) ) {
				return true;
			}
			if ( is_array( $value ) && self::contains_forbidden_key( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function absolute_url( $base, $location ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return '';
		}
		if ( 0 === strpos( $location, 'https://' ) ) {
			return $location;
		}

		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = 'https://' . $parts['host'];
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}

		return $origin . '/' . $location;
	}
}
