<?php
/**
 * Signed maintenance report for the portal.
 *
 * The body matches docs/maintenance-report-1.0.0.schema.json. Signing uses
 * the same route rules as the connector heartbeat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Maintenance_Reporter {
	const SCHEMA_VERSION = '1.0.0';
	const ROUTE          = '/reactwoo/v1/sites/maintenance-report';
	const PRE_UPDATE_MAX = 21600;

	/**
	 * @param string                             $site_id Portal site id.
	 * @param string                             $timestamp UTC timestamp.
	 * @param array<int,array<string,mixed>>     $updates Scanner rows.
	 * @param array<string,mixed>                $audit Auditor summary.
	 * @param string                             $backup_ref Snapshot reference or empty.
	 * @param mixed                              $wordfence Filter result, partial array, or null.
	 * @return array<string,mixed>
	 */
	public static function build( $site_id, $timestamp, array $updates, array $audit, $backup_ref, $wordfence ) {
		$failures = array();
		if ( isset( $audit['failures'] ) && is_array( $audit['failures'] ) ) {
			foreach ( $audit['failures'] as $failure ) {
				if ( ! is_array( $failure ) ) {
					continue;
				}
				$failures[] = array(
					'url'    => isset( $failure['url'] ) ? (string) $failure['url'] : '',
					'status' => isset( $failure['status'] ) ? (int) $failure['status'] : 0,
					'note'   => isset( $failure['note'] ) ? (string) $failure['note'] : '',
				);
			}
		}

		$rows = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			$current = isset( $update['current'] ) ? (string) $update['current'] : '';
			$latest  = isset( $update['latest'] ) ? (string) $update['latest'] : '';
			$rows[]  = array(
				'slug'              => isset( $update['slug'] ) ? (string) $update['slug'] : '',
				'type'              => isset( $update['type'] ) ? (string) $update['type'] : '',
				'current'           => $current,
				'latest'            => $latest,
				'from'              => isset( $update['from'] ) ? (string) $update['from'] : $current,
				'to'                => isset( $update['to'] ) ? (string) $update['to'] : $latest,
				'is_security'       => ! empty( $update['is_security'] ),
				'changelog_excerpt' => isset( $update['changelog_excerpt'] ) ? (string) $update['changelog_excerpt'] : '',
				'severity_hint'     => isset( $update['severity_hint'] ) ? (string) $update['severity_hint'] : '',
			);
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'site_id'        => (string) absint( $site_id ),
			'timestamp'      => self::timestamp( $timestamp ),
			'updates'        => $rows,
			'audit'          => array(
				'pages_checked' => isset( $audit['pages_checked'] ) ? (int) $audit['pages_checked'] : 0,
				'failures'      => $failures,
			),
			'backup_ref'     => self::clean_backup_ref( $backup_ref ),
			'wordfence'      => self::normalise_wordfence( $wordfence ),
		);
	}

	/**
	 * Prefer a recent pre-update snapshot, then the newest valid reference.
	 *
	 * @param array<string,mixed> $snapshots Option rw_connector_snapshots.
	 * @param int                 $now Unix time.
	 * @return string
	 */
	public static function select_backup_ref( array $snapshots, $now ) {
		$best_pre      = '';
		$best_pre_time = 0;
		$best_any      = '';
		$best_any_time = 0;
		$now           = (int) $now;

		foreach ( $snapshots as $ref => $meta ) {
			if ( ! is_string( $ref ) || ! class_exists( 'RW_Connector_Snapshot' ) || ! RW_Connector_Snapshot::valid_reference( $ref ) ) {
				continue;
			}
			$created = 0;
			if ( is_array( $meta ) && ! empty( $meta['created_at'] ) ) {
				$parsed = strtotime( (string) $meta['created_at'] );
				$created = false === $parsed ? 0 : (int) $parsed;
			}
			if ( $created >= $best_any_time ) {
				$best_any_time = $created;
				$best_any      = $ref;
			}
			$purpose = is_array( $meta ) && isset( $meta['purpose'] ) ? (string) $meta['purpose'] : '';
			$age     = $now - $created;
			if ( 'pre_update' === $purpose && $created >= $best_pre_time && $age <= self::PRE_UPDATE_MAX && $age >= -300 ) {
				$best_pre_time = $created;
				$best_pre      = $ref;
			}
		}

		return '' !== $best_pre ? $best_pre : $best_any;
	}

	/**
	 * @param mixed $value Filter or stored wordfence block.
	 * @return array<string,mixed>|null
	 */
	public static function normalise_wordfence( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		if ( isset( $value['version'] ) ) {
			$version = self::short_text( $value['version'], 40 );
			if ( '' !== $version ) {
				$out['version'] = $version;
			}
		}
		if ( array_key_exists( 'pro', $value ) ) {
			$out['pro'] = self::bool_flag( $value['pro'] );
		}
		if ( isset( $value['template_applied'] ) ) {
			$template = self::short_text( $value['template_applied'], 40 );
			if ( '' !== $template ) {
				$out['template_applied'] = $template;
			}
		}
		if ( isset( $value['conflicts_removed'] ) && is_array( $value['conflicts_removed'] ) ) {
			$conflicts = array();
			foreach ( $value['conflicts_removed'] as $slug ) {
				$raw   = strtolower( trim( wp_strip_all_tags( (string) $slug ) ) );
				$clean = sanitize_key( $raw );
				if ( '' !== $clean && $clean === $raw ) {
					$conflicts[ $clean ] = $clean;
				}
			}
			$out['conflicts_removed'] = array_slice( array_values( $conflicts ), 0, 20 );
		}

		return empty( $out ) ? null : $out;
	}

	/**
	 * @param string $portal_url Enrolled portal origin.
	 * @return string
	 */
	public static function endpoint( $portal_url ) {
		return rtrim( (string) $portal_url, '/' ) . '/index.php?rest_route=' . rawurlencode( self::ROUTE );
	}

	/**
	 * @param array<string,mixed> $payload Report.
	 * @param string              $portal_url Portal origin.
	 * @param int                 $site_id Site id.
	 * @param string              $secret Site secret.
	 * @return true|WP_Error
	 */
	public static function send( array $payload, $portal_url, $site_id, $secret ) {
		$endpoint = self::endpoint( $portal_url );
		$body     = wp_json_encode( $payload );
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'rw_report_encode', 'The maintenance report could not be encoded.' );
		}
		$headers                 = RW_Connector_Signing::build_headers( 'POST', $endpoint, $body, $site_id, $secret );
		$headers['Content-Type'] = 'application/json';
		$headers['Accept']       = 'application/json';
		$response                = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		return self::interpret_response( $response );
	}

	/**
	 * @param mixed $response HTTP response or WP_Error.
	 * @return true|WP_Error
	 */
	public static function interpret_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'rw_report_rejected', 'The maintenance report was not accepted.', array( 'status' => $code ) );
		}

		return true;
	}

	/**
	 * @param mixed $value Candidate reference.
	 * @return string
	 */
	private static function clean_backup_ref( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( class_exists( 'RW_Connector_Snapshot' ) && RW_Connector_Snapshot::valid_reference( $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * @param string $timestamp Candidate timestamp.
	 * @return string
	 */
	private static function timestamp( $timestamp ) {
		$timestamp = trim( (string) $timestamp );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp ) ) {
			return $timestamp;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 * @return string
	 */
	private static function short_text( $value, $limit ) {
		$text = trim( wp_strip_all_tags( (string) $value ) );
		if ( strlen( $text ) > (int) $limit ) {
			$text = substr( $text, 0, (int) $limit );
		}

		return $text;
	}

	/**
	 * @param mixed $value Flag.
	 * @return bool
	 */
	private static function bool_flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true );
		}

		return false;
	}
}
