<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Audit {
	public static function log( $event_type, array $context = array() ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'audit_log' );
		if ( ! is_object( $wpdb ) || ! RW_Maint_DB::table_exists( $table ) ) {
			self::report_write_failure( $event_type );
			return;
		}

		$user_id         = isset( $context['user_id'] ) ? (int) $context['user_id'] : null;
		$subscription_id = isset( $context['subscription_id'] ) ? (int) $context['subscription_id'] : null;
		$portal_site_id  = isset( $context['portal_site_id'] ) ? (int) $context['portal_site_id'] : null;

		unset( $context['user_id'], $context['subscription_id'], $context['portal_site_id'] );

		$message = ! empty( $context ) ? wp_json_encode( $context ) : null;

		$inserted = $wpdb->insert(
			$table,
			array(
				'event_type'      => sanitize_text_field( $event_type ),
				'portal_site_id'  => $portal_site_id,
				'subscription_id' => $subscription_id,
				'user_id'         => $user_id,
				'message'         => $message,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			self::report_write_failure( $event_type );
		}
	}

	/**
	 * One log line: event type and the database error. No event payload.
	 *
	 * @param string $event_type Audit event name.
	 */
	private static function report_write_failure( $event_type ) {
		global $wpdb;

		$event = sanitize_text_field( (string) $event_type );
		$event = str_replace( array( "\r", "\n" ), ' ', $event );
		$error = '';
		if ( is_object( $wpdb ) && isset( $wpdb->last_error ) ) {
			$error = str_replace( array( "\r", "\n" ), ' ', (string) $wpdb->last_error );
		}

		error_log( 'rw_maint_audit_failed ' . $event . ' ' . $error );
	}

	public static function get_recent( $limit = 10 ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'audit_log' );
		if ( ! RW_Maint_DB::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, absint( $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);
	}
}
