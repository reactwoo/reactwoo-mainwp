<?php
/**
 * Starts a Wordfence scan on the managed site.
 *
 * MainWP 6.4.1 exposes GET /sites/{id}/security as the last sync snapshot.
 * It has no route that starts a scan. Wordfence on the child site does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Security_Scan {
	public static function register_routes() {
		register_rest_route(
			'reactwoo-connector/v2',
			'/security-scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( 'RW_Connector_Signing', 'verify_request' ),
			)
		);
	}

	public static function handle() {
		$site_id = (int) get_option( 'rw_connector_site_id' );
		if ( ! $site_id ) {
			return new WP_Error( 'rw_connector_not_connected', 'Connector is not enrolled.', array( 'status' => 404 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$result = self::start();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function start() {
		$available = class_exists( 'wfScanEngine' ) && method_exists( 'wfScanEngine', 'startScan' );
		$running   = self::scan_is_running();
		if ( ! $available ) {
			return self::interpret( false, false, false );
		}
		if ( $running ) {
			return self::interpret( true, true, false );
		}

		try {
			$started = wfScanEngine::startScan();
		} catch ( Throwable $e ) {
			unset( $e );
			return self::interpret( true, false, false, true );
		}

		return self::result_from_engine( $started );
	}

	/**
	 * Wordfence returns a sentence when a scan is already running, and false
	 * when the start did not fork. An empty result means the scan was queued.
	 *
	 * @param mixed $started Return value from wfScanEngine::startScan().
	 * @return array<string,mixed>|WP_Error
	 */
	public static function result_from_engine( $started ) {
		if ( is_string( $started ) && '' !== $started ) {
			if ( false !== stripos( $started, 'already running' ) ) {
				return self::interpret( true, true, false );
			}
			return self::interpret( true, false, false, true );
		}
		if ( false === $started ) {
			return self::interpret( true, false, false, true );
		}

		return self::interpret( true, false, true );
	}

	/**
	 * @param bool $available Wordfence scan engine is loaded.
	 * @param bool $running   A scan is already in progress.
	 * @param bool $started   startScan() returned.
	 * @param bool $failed    startScan() threw.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function interpret( $available, $running, $started, $failed = false ) {
		if ( ! $available ) {
			return new WP_Error(
				'rw_scan_unavailable',
				'Wordfence is not active on this website, so a security scan was not started.',
				array( 'status' => 409 )
			);
		}
		if ( $running ) {
			return array(
				'ok'      => true,
				'started' => false,
				'state'   => 'already_running',
				'engine'  => 'wordfence',
			);
		}
		if ( $failed || ! $started ) {
			return new WP_Error(
				'rw_scan_failed',
				'The security scan did not start. Nothing was reported as scanned.',
				array( 'status' => 502 )
			);
		}

		return array(
			'ok'      => true,
			'started' => true,
			'state'   => 'started',
			'engine'  => 'wordfence',
		);
	}

	/**
	 * @return bool
	 */
	private static function scan_is_running() {
		if ( ! class_exists( 'wfScanner' ) || ! method_exists( 'wfScanner', 'shared' ) ) {
			return false;
		}

		$scanner = wfScanner::shared();
		if ( ! is_object( $scanner ) || ! method_exists( $scanner, 'isRunning' ) ) {
			return false;
		}

		return (bool) $scanner->isRunning();
	}
}
