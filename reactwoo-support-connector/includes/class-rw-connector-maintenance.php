<?php
/**
 * Schedules the maintenance scan, the page audit, and the signed report.
 *
 * Nothing here runs during a front-end page view. The daily event and the
 * delayed post-update event both call the same run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Maintenance {
	const HOOK  = 'rw_connector_maintenance_report';
	const AFTER = 'rw_connector_maintenance_after_update';
	const LAST  = 'rw_connector_maintenance_last';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_due' ) );
		add_action( self::AFTER, array( __CLASS__, 'run_due' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'schedule_after_update' ), 10, 2 );
		self::ensure_schedule();
	}

	/**
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$delay = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		wp_schedule_event( time() + $delay, 'daily', self::HOOK );
	}

	/**
	 * Queue one audit after WordPress finishes a core, plugin, or theme update.
	 *
	 * @param mixed $upgrader Upgrader instance. Unused.
	 * @param mixed $options Upgrader hook arguments.
	 * @return void
	 */
	public static function schedule_after_update( $upgrader, $options ) {
		unset( $upgrader );
		if ( ! is_array( $options ) ) {
			return;
		}
		$type   = isset( $options['type'] ) ? (string) $options['type'] : '';
		$action = isset( $options['action'] ) ? (string) $options['action'] : '';
		if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
			return;
		}
		if ( '' !== $action && 'update' !== $action ) {
			return;
		}
		if ( ! (int) get_option( 'rw_connector_site_id' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::AFTER ) ) {
			return;
		}
		$delay = defined( 'MINUTE_IN_SECONDS' ) ? 2 * MINUTE_IN_SECONDS : 120;
		wp_schedule_single_event( time() + $delay, self::AFTER );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run_due() {
		$site_id = (int) get_option( 'rw_connector_site_id' );
		$secret  = (string) get_option( 'rw_connector_site_secret' );
		$portal  = (string) get_option( 'rw_connector_portal_url' );
		if ( ! $site_id || '' === $secret || '' === $portal ) {
			return new WP_Error( 'rw_connector_not_connected', 'This website is not enrolled, so the maintenance report was not sent.' );
		}

		$found    = RW_Connector_Maintenance_Scanner::from_wordpress();
		$feed     = RW_Connector_Maintenance_Feed::current( $found['slugs'] );
		$updates  = RW_Connector_Maintenance_Scanner::build_updates( $found['core'], $found['plugins'], $found['themes'], $feed['findings'] );
		$pages    = RW_Connector_Maintenance_Auditor::audit_site();
		$audit    = RW_Connector_Maintenance_Auditor::summarise( $pages );
		$stored   = get_option( 'rw_connector_snapshots', array() );
		$backup   = RW_Connector_Maintenance_Reporter::select_backup_ref( is_array( $stored ) ? $stored : array(), time() );
		$wordfence = apply_filters( 'rw_connector_maintenance_wordfence', null );
		$payload  = RW_Connector_Maintenance_Reporter::build(
			(string) $site_id,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$updates,
			$audit,
			$backup,
			$wordfence
		);
		$sent = RW_Connector_Maintenance_Reporter::send( $payload, $portal, $site_id, $secret );
		self::remember( $payload, $pages, ! is_wp_error( $sent ) );

		return is_wp_error( $sent ) ? $sent : $payload;
	}

	/**
	 * @param array<string,mixed>            $payload Sent report.
	 * @param array<int,array<string,mixed>> $pages Judged pages.
	 * @param bool                           $sent Whether the portal accepted it.
	 * @return void
	 */
	public static function remember( array $payload, array $pages, $sent ) {
		$local = array();
		foreach ( $pages as $page ) {
			if ( is_array( $page ) ) {
				$local[] = RW_Connector_Maintenance_Auditor::local_page( $page );
			}
		}
		update_option(
			self::LAST,
			array(
				'schema_version' => RW_Connector_Maintenance_Reporter::SCHEMA_VERSION,
				'sent'           => (bool) $sent,
				'backup_ref'     => isset( $payload['backup_ref'] ) ? (string) $payload['backup_ref'] : '',
				'update_count'   => isset( $payload['updates'] ) && is_array( $payload['updates'] ) ? count( $payload['updates'] ) : 0,
				'pages_checked'  => isset( $payload['audit']['pages_checked'] ) ? (int) $payload['audit']['pages_checked'] : 0,
				'pages'          => $local,
			),
			false
		);
	}
}
