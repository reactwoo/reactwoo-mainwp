<?php
/**
 * Stores compact reports, triage, notices, and customer actions
 * against the bridge site row (portal site, MainWP site, client account).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Report_Store {
	/**
	 * @param mixed              $input           Raw JSON, decoded report, or portal wrapper.
	 * @param array<string,mixed>|null $bridge_settings Used when the wrapper does not include email settings.
	 * @param int|null           $now
	 * @return array<string,mixed>|WP_Error
	 */
	public static function ingest( $input, $bridge_settings = null, $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$prepared  = RW_Maint_Report::prepare( $input, $now );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$site = self::resolve_site( $prepared['report'] );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$composed = self::compose( $site, $prepared, is_array( $bridge_settings ) ? $bridge_settings : array(), $now );
		if ( is_wp_error( $composed ) ) {
			return $composed;
		}

		return self::persist( $composed, $now );
	}

	/**
	 * @param array<string,mixed> $report
	 * @return object|WP_Error
	 */
	public static function resolve_site( array $report ) {
		$portal = isset( $report['portal_site_id'] ) ? (int) $report['portal_site_id'] : 0;
		$mainwp = isset( $report['mainwp_site_id'] ) ? (int) $report['mainwp_site_id'] : 0;
		$site   = null;
		if ( $portal > 0 ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal );
		} elseif ( $mainwp > 0 && method_exists( 'RW_Maint_Sites', 'get_by_mainwp_site_id' ) ) {
			$site = RW_Maint_Sites::get_by_mainwp_site_id( $mainwp );
		}
		if ( ! is_object( $site ) || empty( $site->user_id ) ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}
		$mapped = isset( $site->mainwp_site_id ) ? (int) $site->mainwp_site_id : 0;
		if ( $mainwp > 0 && $mapped > 0 && $mainwp !== $mapped ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}

		return $site;
	}

	/**
	 * Build the rows that will be stored. The client account comes from the site row.
	 *
	 * @param object              $site
	 * @param array<string,mixed> $prepared
	 * @param array<string,mixed> $bridge_settings
	 * @param int                 $now
	 * @return array<string,mixed>|WP_Error
	 */
	public static function compose( $site, array $prepared, array $bridge_settings, $now ) {
		$report = $prepared['report'];
		$meta   = isset( $prepared['meta'] ) && is_array( $prepared['meta'] ) ? $prepared['meta'] : array();
		if ( ! empty( $meta['claimed_user_id'] ) && (int) $meta['claimed_user_id'] !== (int) $site->user_id ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}
		if ( ! empty( $meta['subscription_id'] ) && (int) $meta['subscription_id'] !== (int) $site->subscription_id ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}

		$settings = isset( $meta['settings'] ) && is_array( $meta['settings'] )
			? $meta['settings']
			: RW_Maint_Report::normalise_settings(
				isset( $bridge_settings['enabled'] ) ? $bridge_settings['enabled'] : 0,
				isset( $bridge_settings['sender'] ) ? $bridge_settings['sender'] : 'portal',
				isset( $bridge_settings['notice_hours'] ) ? $bridge_settings['notice_hours'] : RW_Maint_Report::NOTICE_DEFAULT
			);
		$notice_hours = (int) $settings['notice_hours'];
		$triage       = RW_Maint_Report::triage( $report, (int) $now, $notice_hours );
		$enabled      = array_key_exists( 'report_enabled', $meta ) && null !== $meta['report_enabled']
			? (bool) $meta['report_enabled']
			: ( ! isset( $site->report_enabled ) || (int) $site->report_enabled === 1 );
		$host         = '';
		if ( ! empty( $site->site_url ) ) {
			$parsed = wp_parse_url( (string) $site->site_url, PHP_URL_HOST );
			$host   = is_string( $parsed ) ? $parsed : '';
		}
		$notices = RW_Maint_Report::notices(
			$report,
			$triage,
			array(
				'host'           => $host,
				'account_url'    => isset( $meta['account_url'] ) ? $meta['account_url'] : '',
				'sender'         => $settings['sender'],
				'notice_hours'   => $notice_hours,
				'now'            => (int) $now,
				'report_enabled' => $enabled,
			)
		);
		$recipients = isset( $meta['recipients'] ) && is_array( $meta['recipients'] ) ? $meta['recipients'] : array();
		if ( empty( $recipients ) ) {
			$recipients = self::fallback_recipients( $site );
		}
		$recipient = implode( ', ', $recipients );
		foreach ( $notices as $index => $notice ) {
			$notices[ $index ]['recipient'] = $recipient;
			if ( '' === $recipient && 'pending' === $notices[ $index ]['status'] ) {
				$notices[ $index ]['status'] = 'skipped';
			}
		}

		$mainwp = isset( $site->mainwp_site_id ) ? (int) $site->mainwp_site_id : 0;
		if ( $mainwp <= 0 && ! empty( $report['mainwp_site_id'] ) ) {
			$mainwp = (int) $report['mainwp_site_id'];
		}

		return array(
			'report_row' => array(
				'portal_site_id'  => (int) $site->portal_site_id,
				'mainwp_site_id'  => $mainwp > 0 ? $mainwp : 0,
				'subscription_id' => (int) $site->subscription_id,
				'user_id'         => (int) $site->user_id,
				'reported_at'     => (string) $report['reported_at'],
				'received_at'     => gmdate( 'Y-m-d H:i:s', (int) $now ),
				'phase'           => (string) $report['phase'],
				'criticality'     => (string) $triage['criticality'],
				'backup_ref'      => (string) $report['backup_ref'],
				'body_hash'       => (string) $report['hash'],
				'payload'         => wp_json_encode( $report ),
				'triage'          => wp_json_encode( $triage ),
				'created_at'      => gmdate( 'Y-m-d H:i:s', (int) $now ),
			),
			'notices'    => $notices,
			'triage'     => $triage,
			'report'     => $report,
		);
	}

	/**
	 * @param int $portal_site_id
	 * @param int $subscription_id
	 * @param int $limit
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function list_for_site( $portal_site_id, $subscription_id, $limit = 20 ) {
		global $wpdb;

		$portal_site_id  = (int) $portal_site_id;
		$subscription_id = (int) $subscription_id;
		$site            = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! is_object( $site ) ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}
		if ( $subscription_id > 0 && (int) $site->subscription_id !== $subscription_id ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}

		$limit = max( 1, min( 20, (int) $limit ) );
		$table = RW_Maint_DB::table( 'reports' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE portal_site_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d",
				$portal_site_id,
				(int) $site->user_id,
				$limit
			)
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row->id;
		}
		$actions = self::actions_for_reports( $ids );
		$public  = array();
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			$triage  = json_decode( (string) $row->triage, true );
			if ( ! is_array( $payload ) || ! is_array( $triage ) ) {
				continue;
			}
			$public[] = RW_Maint_Report::public_view(
				$payload,
				$triage,
				isset( $actions[ (int) $row->id ] ) ? $actions[ (int) $row->id ] : array(),
				(int) $row->id,
				(string) $row->received_at
			);
		}

		return $public;
	}

	/**
	 * @param int    $portal_site_id
	 * @param int    $subscription_id
	 * @param int    $report_id
	 * @param string $action rollback|support|self_fix
	 * @param string $backup_ref
	 * @param bool   $confirmed
	 * @param string $outcome Empty on the first request. started|restored|failed updates a rollback.
	 * @param string $detail  Short customer-safe note.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function record_action( $portal_site_id, $subscription_id, $report_id, $action, $backup_ref, $confirmed, $outcome = '', $detail = '' ) {
		global $wpdb;

		$action = sanitize_key( $action );
		if ( ! in_array( $action, array( 'rollback', 'support', 'self_fix' ), true ) ) {
			return new WP_Error( 'rw_report_invalid', 'That action is not available.', array( 'status' => 400 ) );
		}
		$row = self::report_row( (int) $report_id, (int) $portal_site_id, (int) $subscription_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$outcome = sanitize_key( $outcome );
		$detail  = sanitize_text_field( (string) $detail );
		if ( strlen( $detail ) > 180 ) {
			$detail = substr( $detail, 0, 180 );
		}

		if ( 'rollback' === $action && '' !== $outcome ) {
			if ( ! in_array( $outcome, array( 'started', 'restored', 'failed' ), true ) ) {
				return new WP_Error( 'rw_report_invalid', 'That action is not available.', array( 'status' => 400 ) );
			}
			$updated = $wpdb->update(
				RW_Maint_DB::table( 'report_actions' ),
				array(
					'status' => $outcome,
					'detail' => $detail,
				),
				array(
					'report_id' => (int) $row->id,
					'action'    => 'rollback',
					'status'    => 'accepted',
				),
				array( '%s', '%s' ),
				array( '%d', '%s', '%s' )
			);

			return array(
				'report_id'  => (int) $row->id,
				'action'     => 'rollback',
				'status'     => false === $updated ? 'accepted' : $outcome,
				'backup_ref' => (string) $row->backup_ref,
			);
		}

		if ( ! $confirmed ) {
			return new WP_Error( 'rw_report_confirm', 'Tick the confirmation before this action can start.', array( 'status' => 400 ) );
		}

		$status = 'recorded';
		$ref    = '';
		if ( 'rollback' === $action ) {
			$decision = RW_Maint_Report::rollback_decision( $row->backup_ref, $backup_ref, true );
			if ( is_wp_error( $decision ) ) {
				return $decision;
			}
			$status = 'accepted';
			$ref    = $decision['backup_ref'];
		}

		$wpdb->insert(
			RW_Maint_DB::table( 'report_actions' ),
			array(
				'report_id'       => (int) $row->id,
				'portal_site_id'  => (int) $row->portal_site_id,
				'user_id'         => (int) $row->user_id,
				'subscription_id' => (int) $row->subscription_id,
				'mainwp_site_id'  => (int) $row->mainwp_site_id,
				'action'          => $action,
				'backup_ref'      => $ref,
				'status'          => $status,
				'detail'          => $detail,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'action_id'  => (int) $wpdb->insert_id,
			'report_id'  => (int) $row->id,
			'action'     => $action,
			'status'     => $status,
			'backup_ref' => $ref,
		);
	}

	/**
	 * Expire stale notices, then return pending ones for this sender.
	 *
	 * @param string $sender portal|maint
	 * @param int    $now
	 * @param int    $limit
	 * @return array<int,array<string,mixed>>
	 */
	public static function due_notices( $sender, $now, $limit = 20 ) {
		global $wpdb;

		$sender = RW_Maint_Report::normalise_settings( 0, $sender, RW_Maint_Report::NOTICE_DEFAULT )['sender'];
		$now    = (int) $now;
		self::expire_before( $now );
		$limit = max( 1, min( 20, (int) $limit ) );
		$table = RW_Maint_DB::table( 'report_notices' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, report_id, portal_site_id, user_id, notice_type, sender, recipient, subject, body, send_after, status FROM {$table} WHERE status = %s AND sender = %s AND send_after <= %s ORDER BY id ASC LIMIT %d",
				'pending',
				$sender,
				gmdate( 'Y-m-d H:i:s', $now ),
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int[]  $ids
	 * @param string $status sent|skipped|expired
	 * @return int
	 */
	public static function complete_notices( array $ids, $status ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'sent', 'skipped', 'expired' ), true ) ) {
			return 0;
		}
		$table = RW_Maint_DB::table( 'report_notices' );
		$count = 0;
		$now   = current_time( 'mysql', true );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$updated = $wpdb->update(
				$table,
				array(
					'status'  => $status,
					'sent_at' => $now,
				),
				array(
					'id'     => $id,
					'status' => 'pending',
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( $updated ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @param int $now
	 * @return void
	 */
	public static function expire_before( $now ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'report_notices' );
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $now - RW_Maint_Report::EXPIRE_SECONDS );
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE status = %s AND send_after < %s",
				'expired',
				'pending',
				$cutoff
			)
		);
	}

	/**
	 * @param object $site
	 * @return string[]
	 */
	private static function fallback_recipients( $site ) {
		$candidates = array();
		if ( ! empty( $site->report_email ) ) {
			$candidates = preg_split( '/[\s,;]+/', (string) $site->report_email );
		}
		if ( empty( $candidates ) && ! empty( $site->client_email ) ) {
			$candidates = array( (string) $site->client_email );
		}
		$clean = array();
		foreach ( (array) $candidates as $email ) {
			$email = trim( (string) $email );
			if ( '' === $email ) {
				continue;
			}
			if ( function_exists( 'is_email' ) && ! is_email( $email ) ) {
				continue;
			}
			$clean[ strtolower( $email ) ] = $email;
			if ( count( $clean ) >= 5 ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * @param array<string,mixed> $composed
	 * @param int                 $now
	 * @return array<string,mixed>|WP_Error
	 */
	private static function persist( array $composed, $now ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'reports' );
		$row   = $composed['report_row'];
		$ok    = $wpdb->insert(
			$table,
			$row,
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			$existing = self::find_hash( (int) $row['portal_site_id'], (string) $row['body_hash'] );
			if ( is_object( $existing ) ) {
				$payload = json_decode( (string) $existing->payload, true );
				$triage  = json_decode( (string) $existing->triage, true );
				$view    = RW_Maint_Report::public_view(
					is_array( $payload ) ? $payload : array(),
					is_array( $triage ) ? $triage : array(),
					array(),
					(int) $existing->id,
					(string) $existing->received_at
				);

				return array(
					'report_id'   => (int) $existing->id,
					'criticality' => (string) $existing->criticality,
					'phase'       => (string) $existing->phase,
					'duplicate'   => true,
					'report'      => $view,
				);
			}

			return new WP_Error( 'rw_report_store', 'The report could not be stored.', array( 'status' => 500 ) );
		}

		$report_id = (int) $wpdb->insert_id;
		$notice_table = RW_Maint_DB::table( 'report_notices' );
		foreach ( $composed['notices'] as $notice ) {
			$wpdb->insert(
				$notice_table,
				array(
					'report_id'      => $report_id,
					'portal_site_id' => (int) $row['portal_site_id'],
					'user_id'        => (int) $row['user_id'],
					'subscription_id'=> (int) $row['subscription_id'],
					'notice_type'    => (string) $notice['notice_type'],
					'sender'         => (string) $notice['sender'],
					'recipient'      => (string) $notice['recipient'],
					'send_after'     => (string) $notice['send_after'],
					'status'         => (string) $notice['status'],
					'subject'        => (string) $notice['subject'],
					'body'           => (string) $notice['body'],
					'created_at'     => gmdate( 'Y-m-d H:i:s', (int) $now ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		if ( class_exists( 'RW_Maint_Audit' ) ) {
			RW_Maint_Audit::log(
				'maintenance_report_stored',
				array(
					'portal_site_id'  => (int) $row['portal_site_id'],
					'subscription_id' => (int) $row['subscription_id'],
					'user_id'         => (int) $row['user_id'],
					'report_id'       => $report_id,
					'phase'           => (string) $row['phase'],
					'criticality'     => (string) $row['criticality'],
				)
			);
		}

		return array(
			'report_id'   => $report_id,
			'criticality' => (string) $row['criticality'],
			'phase'       => (string) $row['phase'],
			'duplicate'   => false,
			'report'      => RW_Maint_Report::public_view( $composed['report'], $composed['triage'], array(), $report_id, (string) $row['received_at'] ),
		);
	}

	/**
	 * @param int $portal_site_id
	 * @param string $hash
	 * @return object|null
	 */
	private static function find_hash( $portal_site_id, $hash ) {
		global $wpdb;

		$table = RW_Maint_DB::table( 'reports' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE portal_site_id = %d AND body_hash = %s",
				(int) $portal_site_id,
				$hash
			)
		);
	}

	/**
	 * @param int $report_id
	 * @param int $portal_site_id
	 * @param int $subscription_id
	 * @return object|WP_Error
	 */
	private static function report_row( $report_id, $portal_site_id, $subscription_id ) {
		global $wpdb;

		$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		if ( ! is_object( $site ) ) {
			return new WP_Error( 'rw_maint_not_found', 'Site not found.', array( 'status' => 404 ) );
		}
		if ( $subscription_id > 0 && (int) $site->subscription_id !== $subscription_id ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}
		$table = RW_Maint_DB::table( 'reports' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND portal_site_id = %d AND user_id = %d",
				(int) $report_id,
				(int) $portal_site_id,
				(int) $site->user_id
			)
		);
		if ( ! is_object( $row ) ) {
			return new WP_Error( 'rw_maint_not_found', 'Report not found.', array( 'status' => 404 ) );
		}

		return $row;
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private static function actions_for_reports( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$table = RW_Maint_DB::table( 'report_actions' );
		$list  = implode( ',', $ids );
		$rows  = $wpdb->get_results(
			"SELECT report_id, action, status, backup_ref, detail, created_at FROM {$table} WHERE report_id IN ({$list}) ORDER BY id ASC",
			ARRAY_A
		);
		$grouped = array();
		if ( ! is_array( $rows ) ) {
			return $grouped;
		}
		foreach ( $rows as $row ) {
			$grouped[ (int) $row['report_id'] ][] = array(
				'action'     => (string) $row['action'],
				'status'     => (string) $row['status'],
				'backup_ref' => (string) $row['backup_ref'],
				'detail'     => (string) $row['detail'],
				'created_at' => (string) $row['created_at'],
			);
		}

		return $grouped;
	}
}
