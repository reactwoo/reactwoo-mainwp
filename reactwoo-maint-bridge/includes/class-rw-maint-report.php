<?php
/**
 * Compact maintenance report: size cap, tolerant parse, and deterministic triage.
 *
 * The connector reporter may add fields later. Unknown keys are ignored.
 * Optional blocks may be missing. Customer wording is British English.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

class RW_Maint_Report {
	const MAX_BYTES           = 65536;
	const MAX_ENVELOPE        = 98304;
	const MAX_UPDATES         = 40;
	const MAX_FAILURES        = 25;
	const MAX_CONFLICTS       = 20;
	const MAX_EXCERPT         = 180;
	const NOTICE_DEFAULT      = 24;
	const NOTICE_MIN          = 1;
	const NOTICE_MAX          = 168;
	const EXPIRE_SECONDS      = 604800;
	const CRITICAL_LEAD_HOURS = 4;
	const MEDIUM_LEAD_HOURS   = 72;
	const BACKUP_REF_PATTERN  = '/^rwsnap_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{8,32}$/';

	/**
	 * Decode a raw body or accept an array. Rejects oversized and non-object JSON.
	 *
	 * @param mixed $input Raw JSON or decoded array.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function decode( $input ) {
		if ( is_string( $input ) ) {
			if ( strlen( $input ) > self::MAX_ENVELOPE ) {
				return new WP_Error( 'rw_report_too_large', 'The report is too large.', array( 'status' => 413 ) );
			}
			$input = preg_replace( '/^\xEF\xBB\xBF/', '', $input );
			$decoded = json_decode( (string) $input, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'rw_report_invalid', 'The report was not valid JSON.', array( 'status' => 400 ) );
			}
			$input = $decoded;
		}
		if ( ! is_array( $input ) || ! self::is_assoc( $input ) ) {
			return new WP_Error( 'rw_report_invalid', 'The report must be a JSON object.', array( 'status' => 400 ) );
		}

		return $input;
	}

	/**
	 * Split a portal wrapper from the compact report and sanitise the report.
	 *
	 * @param mixed    $input Raw JSON or decoded array.
	 * @param int|null $now   Unix time the report was received.
	 * @return array{report:array<string,mixed>,meta:array<string,mixed>}|WP_Error
	 */
	public static function prepare( $input, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$decoded = self::decode( $input );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$meta   = array(
			'settings'         => null,
			'account_url'      => '',
			'subscription_id'  => 0,
			'claimed_user_id'  => 0,
			'recipients'       => array(),
			'report_enabled'   => null,
		);
		$source = $decoded;
		if ( isset( $decoded['report'] ) && is_array( $decoded['report'] ) && self::is_assoc( $decoded['report'] ) ) {
			$source = $decoded['report'];
			if ( isset( $decoded['email'] ) && is_array( $decoded['email'] ) ) {
				$meta['settings'] = self::normalise_settings(
					isset( $decoded['email']['enabled'] ) ? $decoded['email']['enabled'] : 0,
					isset( $decoded['email']['sender'] ) ? $decoded['email']['sender'] : 'portal',
					isset( $decoded['email']['notice_hours'] ) ? $decoded['email']['notice_hours'] : self::NOTICE_DEFAULT
				);
			}
			$meta['account_url']     = self::clean_account_url( isset( $decoded['account_url'] ) ? $decoded['account_url'] : '' );
			$meta['subscription_id'] = self::digits( isset( $decoded['subscription_id'] ) ? $decoded['subscription_id'] : 0 );
			$meta['claimed_user_id'] = self::digits( isset( $decoded['user_id'] ) ? $decoded['user_id'] : 0 );
			if ( isset( $decoded['report_enabled'] ) ) {
				$meta['report_enabled'] = self::flag( $decoded['report_enabled'] );
			}
			if ( isset( $decoded['recipients'] ) && is_array( $decoded['recipients'] ) ) {
				$meta['recipients'] = self::clean_emails( $decoded['recipients'] );
			}
			$wrapped_site = self::digits( isset( $decoded['portal_site_id'] ) ? $decoded['portal_site_id'] : 0 );
			$inner_site   = self::digits( isset( $source['portal_site_id'] ) ? $source['portal_site_id'] : 0 );
			if ( $wrapped_site > 0 && 0 === $inner_site ) {
				$source['portal_site_id'] = $wrapped_site;
			} elseif ( $wrapped_site > 0 && $inner_site > 0 && $wrapped_site !== $inner_site ) {
				return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
			}
		}

		$encoded = wp_json_encode( $source );
		// Re-encoding can be a little larger than the connector body, which is already capped.
		if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_BYTES + 8192 ) {
			return new WP_Error( 'rw_report_too_large', 'The report is too large.', array( 'status' => 413 ) );
		}

		$report = self::parse_fields( $source, $now );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$report['hash'] = hash( 'sha256', self::canonical( $report ) );

		return array(
			'report' => $report,
			'meta'   => $meta,
		);
	}

	/**
	 * Header site id must agree with a numeric site_id or portal_site_id in the body.
	 * A non-numeric site_id is an extra label and is allowed.
	 *
	 * @param int                 $header_site_id Portal site id from the signature.
	 * @param array<string,mixed> $data           Decoded body.
	 * @return WP_Error|null
	 */
	public static function identity_conflict( $header_site_id, array $data ) {
		$header = self::digits( $header_site_id );
		if ( $header <= 0 ) {
			return new WP_Error( 'rw_site_id_missing', 'Site ID header missing.', array( 'status' => 400 ) );
		}
		$portal = self::digits( isset( $data['portal_site_id'] ) ? $data['portal_site_id'] : 0 );
		if ( $portal > 0 && $portal !== $header ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}
		if ( array_key_exists( 'site_id', $data ) && self::digits( $data['site_id'] ) > 0 && self::digits( $data['site_id'] ) !== $header ) {
			return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
		}

		return null;
	}

	/**
	 * @param bool|int|string $enabled
	 * @param mixed           $sender
	 * @param mixed           $notice_hours
	 * @return array{enabled:bool,sender:string,notice_hours:int}
	 */
	public static function normalise_settings( $enabled, $sender, $notice_hours ) {
		$sender = is_string( $sender ) ? strtolower( trim( $sender ) ) : '';
		if ( ! in_array( $sender, array( 'portal', 'maint' ), true ) ) {
			$sender = 'portal';
		}
		$hours = is_numeric( $notice_hours ) ? (int) $notice_hours : self::NOTICE_DEFAULT;
		if ( $hours < self::NOTICE_MIN || $hours > self::NOTICE_MAX ) {
			$hours = self::NOTICE_DEFAULT;
		}

		return array(
			'enabled'      => self::flag( $enabled ),
			'sender'       => $sender,
			'notice_hours' => $hours,
		);
	}

	/**
	 * @param string $reference Candidate snapshot reference.
	 * @return bool
	 */
	public static function valid_backup_ref( $reference ) {
		return 1 === preg_match( self::BACKUP_REF_PATTERN, (string) $reference );
	}

	/**
	 * @param array<string,mixed> $report     Sanitised report.
	 * @param int                 $now        Receive time.
	 * @param int                 $notice_hours Configured notice period.
	 * @return array<string,mixed>
	 */
	public static function triage( array $report, $now, $notice_hours ) {
		$now    = (int) $now;
		$notice = self::normalise_settings( 0, 'portal', $notice_hours )['notice_hours'];
		$phase  = isset( $report['phase'] ) ? (string) $report['phase'] : 'status';
		$items  = isset( $report['updates'] ) && is_array( $report['updates'] ) ? $report['updates'] : array();

		$exposed = array();
		$fixed   = array();
		$waiting = array();
		$highest = 'none';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$rank    = self::item_criticality( $item );
			$highest = self::higher_criticality( $highest, $rank );
			$public  = self::public_item( $item, 'update' );
			$applied = array_key_exists( 'applied', $item ) ? $item['applied'] : null;
			if ( 'post_patch' === $phase ) {
				if ( false === $applied ) {
					if ( self::counts_as_exposed( $item ) ) {
						$exposed[] = $public;
					} else {
						$waiting[] = $public;
					}
				} else {
					$fixed[] = $public;
				}
				continue;
			}
			if ( true === $applied ) {
				$fixed[] = $public;
				continue;
			}
			if ( self::counts_as_exposed( $item ) ) {
				$exposed[] = $public;
			} else {
				$waiting[] = $public;
			}
		}

		$conflicts = isset( $report['wordfence']['conflicts_removed'] ) && is_array( $report['wordfence']['conflicts_removed'] )
			? $report['wordfence']['conflicts_removed']
			: array();
		foreach ( $conflicts as $name ) {
			$fixed[] = array(
				'slug'     => (string) $name,
				'from'     => '',
				'to'       => '',
				'severity' => '',
				'excerpt'  => '',
				'kind'     => 'conflict',
			);
		}

		$failures = array();
		$pages    = 0;
		$audit    = ( isset( $report['audit'] ) && is_array( $report['audit'] ) ) ? $report['audit'] : null;
		if ( is_array( $audit ) ) {
			$pages = isset( $audit['pages_checked'] ) ? (int) $audit['pages_checked'] : 0;
			if ( isset( $audit['failures'] ) && is_array( $audit['failures'] ) ) {
				$failures = $audit['failures'];
			}
		}

		$breakage = self::breakage_risk( $report, $items, $failures, $pages );
		$window   = self::window_for( $phase, $highest, $breakage, $now, $notice );

		return array(
			'criticality'        => $highest,
			'exposed'            => $exposed,
			'fixed'              => $fixed,
			'waiting'            => $waiting,
			'breakage_risk'      => $breakage,
			'failed_pages'       => $failures,
			'pages_checked'      => $pages,
			'window_key'         => $window['key'],
			'window_label'       => $window['label'],
			'suggested_patch_at' => $window['at'],
			'summary'            => self::summary( $phase, $highest, $breakage, $exposed, $failures ),
		);
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $triage
	 * @param array<string,mixed> $context host, account_url, sender, notice_hours, now, report_enabled.
	 * @return array<int,array<string,mixed>>
	 */
	public static function notices( array $report, array $triage, array $context ) {
		$now      = isset( $context['now'] ) ? (int) $context['now'] : time();
		$sender   = self::normalise_settings( 0, isset( $context['sender'] ) ? $context['sender'] : 'portal', self::NOTICE_DEFAULT )['sender'];
		$notice   = self::normalise_settings( 0, $sender, isset( $context['notice_hours'] ) ? $context['notice_hours'] : self::NOTICE_DEFAULT )['notice_hours'];
		$host     = isset( $context['host'] ) ? self::plain( $context['host'], 190 ) : '';
		$host     = '' !== $host ? $host : 'your website';
		$account  = isset( $context['account_url'] ) ? self::clean_account_url( $context['account_url'] ) : '';
		$phase    = isset( $report['phase'] ) ? (string) $report['phase'] : 'status';
		$enabled  = ! isset( $context['report_enabled'] ) || null === $context['report_enabled'] || ! empty( $context['report_enabled'] );
		$kinds    = array();

		if ( 'post_patch' !== $phase && ! empty( $triage['exposed'] ) ) {
			$kinds[] = 'exposure';
		}
		if ( 'post_patch' !== $phase && '' !== (string) $triage['suggested_patch_at'] && in_array( $triage['criticality'], array( 'critical', 'high', 'medium' ), true ) ) {
			$kinds[] = 'planned_patch';
		}
		if ( 'post_patch' === $phase ) {
			$kinds[] = 'post_patch';
		}

		$rows = array();
		foreach ( $kinds as $kind ) {
			$send_after = $now;
			if ( 'planned_patch' === $kind ) {
				$when = strtotime( (string) $triage['suggested_patch_at'] . ' UTC' );
				$when = $when ? (int) $when : $now;
				$send_after = $when - ( $notice * HOUR_IN_SECONDS );
				if ( $send_after < $now ) {
					$send_after = $now;
				}
			}
			$copy = self::email_copy( $kind, $host, $account, $report, $triage );
			$rows[] = array(
				'notice_type' => $kind,
				'sender'      => $sender,
				'send_after'  => gmdate( 'Y-m-d H:i:s', $send_after ),
				'status'      => $enabled ? 'pending' : 'skipped',
				'subject'     => $copy['subject'],
				'body'        => $copy['body'],
			);
		}

		return $rows;
	}

	/**
	 * Whether a queued notice should be sent from this site.
	 *
	 * @param array{enabled:bool,sender:string} $settings This site's email settings.
	 * @param array<string,mixed>               $notice   Stored notice.
	 * @param int                                $now     Unix time.
	 * @return string send|wait|disabled|other_sender|expire|leave
	 */
	public static function notice_decision( array $settings, array $notice, $now ) {
		$status = isset( $notice['status'] ) ? (string) $notice['status'] : '';
		if ( 'pending' !== $status ) {
			return 'leave';
		}
		$send_after = strtotime( (string) ( isset( $notice['send_after'] ) ? $notice['send_after'] : '' ) . ' UTC' );
		$send_after = $send_after ? (int) $send_after : 0;
		$now        = (int) $now;
		if ( $send_after > $now + 30 ) {
			return 'wait';
		}
		if ( $send_after > 0 && ( $now - $send_after ) > self::EXPIRE_SECONDS ) {
			return 'expire';
		}
		if ( empty( $settings['enabled'] ) ) {
			return 'disabled';
		}
		$sender = isset( $settings['sender'] ) ? (string) $settings['sender'] : '';
		if ( $sender !== (string) ( isset( $notice['sender'] ) ? $notice['sender'] : '' ) ) {
			return 'other_sender';
		}
		if ( '' === trim( (string) ( isset( $notice['recipient'] ) ? $notice['recipient'] : '' ) ) ) {
			return 'expire';
		}

		return 'send';
	}

	/**
	 * Rollback uses the snapshot reference stored with the report, and only after confirmation.
	 *
	 * @param string $stored_ref Reference on the report.
	 * @param string $posted_ref Reference the customer submitted.
	 * @param bool   $confirmed  Explicit confirmation.
	 * @return array{backup_ref:string}|WP_Error
	 */
	public static function rollback_decision( $stored_ref, $posted_ref, $confirmed ) {
		if ( ! $confirmed ) {
			return new WP_Error( 'rw_report_confirm', 'Tick the confirmation before a rollback can start.', array( 'status' => 400 ) );
		}
		$stored = trim( (string) $stored_ref );
		if ( ! self::valid_backup_ref( $stored ) ) {
			return new WP_Error( 'rw_report_backup', 'This report has no snapshot reference, so rollback is not available.', array( 'status' => 409 ) );
		}
		$posted = trim( (string) $posted_ref );
		if ( '' === $posted ) {
			$posted = $stored;
		}
		if ( strlen( $posted ) !== strlen( $stored ) || ! hash_equals( $stored, $posted ) ) {
			return new WP_Error( 'rw_report_backup', 'The snapshot reference does not match this report.', array( 'status' => 409 ) );
		}

		return array( 'backup_ref' => $stored );
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $triage
	 * @param array<int,array<string,mixed>> $actions
	 * @param int    $id
	 * @param string $received_at
	 * @return array<string,mixed>
	 */
	public static function public_view( array $report, array $triage, array $actions, $id, $received_at ) {
		return array(
			'id'                 => (int) $id,
			'reported_at'        => isset( $report['reported_at'] ) ? (string) $report['reported_at'] : '',
			'received_at'        => (string) $received_at,
			'phase'              => isset( $report['phase'] ) ? (string) $report['phase'] : '',
			'criticality'        => isset( $triage['criticality'] ) ? (string) $triage['criticality'] : 'none',
			'breakage_risk'      => isset( $triage['breakage_risk'] ) ? (string) $triage['breakage_risk'] : 'none',
			'window_label'       => isset( $triage['window_label'] ) ? (string) $triage['window_label'] : '',
			'suggested_patch_at' => isset( $triage['suggested_patch_at'] ) ? (string) $triage['suggested_patch_at'] : '',
			'backup_ref'         => isset( $report['backup_ref'] ) ? (string) $report['backup_ref'] : '',
			'summary'            => isset( $triage['summary'] ) ? (string) $triage['summary'] : '',
			'exposed'            => isset( $triage['exposed'] ) && is_array( $triage['exposed'] ) ? $triage['exposed'] : array(),
			'fixed'              => isset( $triage['fixed'] ) && is_array( $triage['fixed'] ) ? $triage['fixed'] : array(),
			'waiting'            => isset( $triage['waiting'] ) && is_array( $triage['waiting'] ) ? $triage['waiting'] : array(),
			'failed_pages'       => isset( $triage['failed_pages'] ) && is_array( $triage['failed_pages'] ) ? $triage['failed_pages'] : array(),
			'pages_checked'      => isset( $triage['pages_checked'] ) ? (int) $triage['pages_checked'] : 0,
			'actions'            => $actions,
		);
	}

	/**
	 * @param array<string,mixed> $source
	 * @param int                 $now
	 * @return array<string,mixed>|WP_Error
	 */
	private static function parse_fields( array $source, $now ) {
		$warnings = array();
		$portal   = self::digits( isset( $source['portal_site_id'] ) ? $source['portal_site_id'] : 0 );
		$mainwp   = self::digits( isset( $source['mainwp_site_id'] ) ? $source['mainwp_site_id'] : 0 );
		$label    = '';
		if ( array_key_exists( 'site_id', $source ) ) {
			$site_id = $source['site_id'];
			$as_int  = self::digits( $site_id );
			if ( $as_int > 0 ) {
				if ( 0 === $portal ) {
					$portal = $as_int;
				} elseif ( $portal !== $as_int ) {
					return new WP_Error( 'rw_report_site', 'The report site does not match this connection.', array( 'status' => 409 ) );
				}
			} elseif ( is_scalar( $site_id ) && '' !== trim( (string) $site_id ) ) {
				$label = self::plain( $site_id, 64 );
			}
		}
		if ( $portal <= 0 && $mainwp <= 0 ) {
			return new WP_Error( 'rw_report_site', 'A site id is required.', array( 'status' => 400 ) );
		}

		$supplied = array_key_exists( 'timestamp', $source ) && '' !== trim( (string) $source['timestamp'] );
		$reported = $supplied ? self::parse_time( $source['timestamp'] ) : 0;
		if ( $supplied && $reported <= 0 ) {
			$warnings[] = 'timestamp_unusable';
			$reported   = $now;
		} elseif ( ! $supplied ) {
			$warnings[] = 'timestamp_missing';
			$reported   = $now;
		} elseif ( $reported > $now + ( 2 * DAY_IN_SECONDS ) ) {
			$warnings[] = 'timestamp_future';
			$reported   = $now;
		} elseif ( $reported < $now - ( 30 * DAY_IN_SECONDS ) ) {
			$warnings[] = 'timestamp_old';
		}

		$phase = self::phase_of( $source );
		$updates = self::parse_updates( isset( $source['updates'] ) ? $source['updates'] : null, $warnings );
		if ( '' === $phase ) {
			$phase = self::infer_phase( $updates, isset( $source['audit'] ) ? $source['audit'] : null );
		}

		$audit = self::parse_audit( isset( $source['audit'] ) ? $source['audit'] : null, $warnings );
		$backup = '';
		if ( array_key_exists( 'backup_ref', $source ) && null !== $source['backup_ref'] && '' !== trim( (string) $source['backup_ref'] ) ) {
			$candidate = trim( (string) $source['backup_ref'] );
			if ( self::valid_backup_ref( $candidate ) ) {
				$backup = $candidate;
			} else {
				$warnings[] = 'backup_ref_ignored';
			}
		}

		return array(
			'portal_site_id'     => $portal,
			'mainwp_site_id'     => $mainwp,
			'site_label'         => $label,
			'reported_at'        => gmdate( 'Y-m-d H:i:s', $reported ),
			'timestamp_supplied' => $supplied && ! in_array( 'timestamp_unusable', $warnings, true ),
			'phase'              => $phase,
			'updates'            => $updates,
			'audit'              => $audit,
			'audit_supplied'     => is_array( $audit ),
			'backup_ref'         => $backup,
			'wordfence'          => self::parse_wordfence( isset( $source['wordfence'] ) ? $source['wordfence'] : null ),
			'warnings'           => $warnings,
		);
	}

	/**
	 * @param array<string,mixed> $source
	 * @return string Empty when the reporter did not name a phase.
	 */
	private static function phase_of( array $source ) {
		$raw = '';
		foreach ( array( 'phase', 'report_type', 'kind' ) as $key ) {
			if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
				$raw = strtolower( trim( (string) $source[ $key ] ) );
				break;
			}
		}
		$raw = str_replace( array( ' ', '-' ), '_', $raw );
		if ( in_array( $raw, array( 'exposure', 'available', 'pending' ), true ) ) {
			return 'exposure';
		}
		if ( in_array( $raw, array( 'post_patch', 'applied', 'after_update', 'result' ), true ) ) {
			return 'post_patch';
		}
		if ( in_array( $raw, array( 'status', 'heartbeat', 'summary', 'scan' ), true ) ) {
			return 'status';
		}

		return '';
	}

	/**
	 * @param array<int,array<string,mixed>> $updates
	 * @param mixed                          $audit
	 * @return string
	 */
	private static function infer_phase( array $updates, $audit ) {
		foreach ( $updates as $item ) {
			if ( isset( $item['applied'] ) && true === $item['applied'] ) {
				return 'post_patch';
			}
		}
		if ( ! empty( $updates ) ) {
			return 'exposure';
		}
		if ( is_array( $audit ) ) {
			$pages    = isset( $audit['pages_checked'] ) ? self::digits( $audit['pages_checked'] ) : 0;
			$failures = ( isset( $audit['failures'] ) && is_array( $audit['failures'] ) ) ? $audit['failures'] : array();
			if ( $pages > 0 || ! empty( $failures ) ) {
				return 'post_patch';
			}
		}

		return 'status';
	}

	/**
	 * @param mixed    $updates
	 * @param string[] $warnings
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse_updates( $updates, array &$warnings ) {
		if ( null === $updates ) {
			return array();
		}
		if ( ! is_array( $updates ) ) {
			$warnings[] = 'updates_ignored';
			return array();
		}
		$rows = array();
		foreach ( $updates as $item ) {
			if ( count( $rows ) >= self::MAX_UPDATES ) {
				$warnings[] = 'updates_truncated';
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = self::slug( isset( $item['slug'] ) ? $item['slug'] : '' );
			if ( '' === $slug ) {
				continue;
			}
			$applied = null;
			if ( array_key_exists( 'applied', $item ) ) {
				$applied = self::flag( $item['applied'] );
			} elseif ( isset( $item['state'] ) && is_scalar( $item['state'] ) ) {
				$state = strtolower( trim( (string) $item['state'] ) );
				if ( in_array( $state, array( 'applied', 'installed', 'updated' ), true ) ) {
					$applied = true;
				} elseif ( in_array( $state, array( 'available', 'pending' ), true ) ) {
					$applied = false;
				}
			}
			$rows[] = array(
				'slug'               => $slug,
				'from'               => self::version_text( isset( $item['from'] ) ? $item['from'] : ( isset( $item['current'] ) ? $item['current'] : '' ) ),
				'to'                 => self::version_text( isset( $item['to'] ) ? $item['to'] : ( isset( $item['latest'] ) ? $item['latest'] : '' ) ),
				'is_security'        => self::flag( isset( $item['is_security'] ) ? $item['is_security'] : false ),
				'changelog_excerpt'  => self::plain( isset( $item['changelog_excerpt'] ) ? $item['changelog_excerpt'] : '', self::MAX_EXCERPT ),
				'severity_hint'      => self::severity( isset( $item['severity_hint'] ) ? $item['severity_hint'] : '' ),
				'applied'            => $applied,
			);
		}

		return $rows;
	}

	/**
	 * @param mixed    $audit
	 * @param string[] $warnings
	 * @return array<string,mixed>|null
	 */
	private static function parse_audit( $audit, array &$warnings ) {
		if ( null === $audit ) {
			return null;
		}
		if ( ! is_array( $audit ) || ! self::is_assoc( $audit ) ) {
			$warnings[] = 'audit_ignored';
			return null;
		}
		$pages = self::digits( isset( $audit['pages_checked'] ) ? $audit['pages_checked'] : 0 );
		if ( $pages > 100000 ) {
			$pages = 100000;
		}
		$failures = array();
		$raw      = isset( $audit['failures'] ) && is_array( $audit['failures'] ) ? $audit['failures'] : array();
		foreach ( $raw as $failure ) {
			if ( count( $failures ) >= self::MAX_FAILURES ) {
				$warnings[] = 'failures_truncated';
				break;
			}
			if ( ! is_array( $failure ) ) {
				continue;
			}
			$url = self::clean_page_url( isset( $failure['url'] ) ? $failure['url'] : '' );
			if ( '' === $url ) {
				continue;
			}
			$status = self::digits( isset( $failure['status'] ) ? $failure['status'] : 0 );
			if ( $status > 599 ) {
				$status = 0;
			}
			$failures[] = array(
				'url'    => $url,
				'status' => $status,
				'note'   => self::plain( isset( $failure['note'] ) ? $failure['note'] : '', 180 ),
			);
		}

		return array(
			'pages_checked' => $pages,
			'failures'      => $failures,
		);
	}

	/**
	 * @param mixed $wordfence
	 * @return array{version:string,pro:?bool,template_applied:string,conflicts_removed:string[]}
	 */
	private static function parse_wordfence( $wordfence ) {
		$empty = array(
			'version'           => '',
			'pro'               => null,
			'template_applied'  => '',
			'conflicts_removed' => array(),
		);
		if ( ! is_array( $wordfence ) || ! self::is_assoc( $wordfence ) ) {
			return $empty;
		}
		$conflicts = array();
		if ( isset( $wordfence['conflicts_removed'] ) && is_array( $wordfence['conflicts_removed'] ) ) {
			foreach ( $wordfence['conflicts_removed'] as $name ) {
				if ( count( $conflicts ) >= self::MAX_CONFLICTS ) {
					break;
				}
				$clean = self::plain( $name, 80 );
				if ( '' !== $clean ) {
					$conflicts[] = $clean;
				}
			}
		}
		$pro = null;
		if ( array_key_exists( 'pro', $wordfence ) ) {
			$pro = self::flag( $wordfence['pro'] );
		}

		return array(
			'version'           => self::plain( isset( $wordfence['version'] ) ? $wordfence['version'] : '', 40 ),
			'pro'               => $pro,
			'template_applied'  => self::plain( isset( $wordfence['template_applied'] ) ? $wordfence['template_applied'] : '', 40 ),
			'conflicts_removed' => $conflicts,
		);
	}

	/**
	 * @param array<string,mixed> $item
	 * @return string
	 */
	private static function item_criticality( array $item ) {
		$hint     = isset( $item['severity_hint'] ) ? (string) $item['severity_hint'] : '';
		$security = ! empty( $item['is_security'] );
		if ( 'critical' === $hint ) {
			return 'critical';
		}
		if ( 'high' === $hint || ( $security && ! in_array( $hint, array( 'low', 'info' ), true ) ) ) {
			return 'high';
		}
		if ( $security ) {
			return 'medium';
		}
		if ( in_array( $hint, array( 'low', 'info' ), true ) ) {
			return 'low';
		}

		return 'medium';
	}

	/**
	 * @param array<string,mixed> $item
	 * @return bool
	 */
	private static function counts_as_exposed( array $item ) {
		$hint = isset( $item['severity_hint'] ) ? (string) $item['severity_hint'] : '';
		return ! empty( $item['is_security'] ) || in_array( $hint, array( 'critical', 'high' ), true );
	}

	/**
	 * @param string $left
	 * @param string $right
	 * @return string
	 */
	private static function higher_criticality( $left, $right ) {
		$rank = array(
			'none'     => 0,
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
		);
		$left  = isset( $rank[ $left ] ) ? $left : 'none';
		$right = isset( $rank[ $right ] ) ? $right : 'none';

		return $rank[ $right ] > $rank[ $left ] ? $right : $left;
	}

	/**
	 * @param array<string,mixed>                $report
	 * @param array<int,array<string,mixed>>     $items
	 * @param array<int,array<string,mixed>>     $failures
	 * @param int                                $pages
	 * @return string
	 */
	private static function breakage_risk( array $report, array $items, array $failures, $pages ) {
		$high = false;
		foreach ( $failures as $failure ) {
			$status = isset( $failure['status'] ) ? (int) $failure['status'] : 0;
			$note   = isset( $failure['note'] ) ? strtolower( (string) $failure['note'] ) : '';
			if ( $status >= 500 || 0 === $status || preg_match( '/empty|blank|timeout|fatal/', $note ) ) {
				$high = true;
			}
		}
		if ( $high ) {
			return 'high';
		}
		if ( ! empty( $failures ) || self::has_major_bump( $items ) ) {
			return 'medium';
		}
		$phase = isset( $report['phase'] ) ? (string) $report['phase'] : '';
		if ( ! empty( $report['audit_supplied'] ) && $pages > 0 ) {
			return 'low';
		}
		if ( 'post_patch' === $phase ) {
			return 'unknown';
		}

		return 'none';
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return bool
	 */
	private static function has_major_bump( array $items ) {
		foreach ( $items as $item ) {
			$from = self::major_component( isset( $item['from'] ) ? $item['from'] : '' );
			$to   = self::major_component( isset( $item['to'] ) ? $item['to'] : '' );
			if ( null !== $from && null !== $to && $from !== $to ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $version
	 * @return int|null
	 */
	private static function major_component( $version ) {
		if ( preg_match( '/(\d+)/', (string) $version, $match ) ) {
			return (int) $match[1];
		}

		return null;
	}

	/**
	 * @param string $phase
	 * @param string $criticality
	 * @param string $breakage
	 * @param int    $now
	 * @param int    $notice
	 * @return array{key:string,label:string,at:string}
	 */
	private static function window_for( $phase, $criticality, $breakage, $now, $notice ) {
		if ( 'post_patch' === $phase ) {
			if ( in_array( $breakage, array( 'high', 'medium' ), true ) ) {
				return array(
					'key'   => 'review',
					'label' => 'Review the failed pages before another change is made on this website.',
					'at'    => '',
				);
			}

			return array(
				'key'   => 'routine',
				'label' => 'No separate patch window is suggested.',
				'at'    => '',
			);
		}
		if ( 'critical' === $criticality ) {
			return array(
				'key'   => 'soon',
				'label' => 'Suggested window: as soon as you can. This is a suggestion, not a booked change.',
				'at'    => gmdate( 'Y-m-d H:i:s', $now + ( self::CRITICAL_LEAD_HOURS * HOUR_IN_SECONDS ) ),
			);
		}
		if ( 'high' === $criticality ) {
			$lead = max( $notice, 24 );
			return array(
				'key'   => 'next_day',
				'label' => 'Suggested window: after the notice period, in the next planned check.',
				'at'    => gmdate( 'Y-m-d H:i:s', $now + ( $lead * HOUR_IN_SECONDS ) ),
			);
		}
		if ( 'medium' === $criticality ) {
			$lead = max( $notice, self::MEDIUM_LEAD_HOURS );
			return array(
				'key'   => 'scheduled',
				'label' => 'Suggested window: the next planned maintenance.',
				'at'    => gmdate( 'Y-m-d H:i:s', $now + ( $lead * HOUR_IN_SECONDS ) ),
			);
		}

		return array(
			'key'   => 'routine',
			'label' => 'No separate patch window is suggested.',
			'at'    => '',
		);
	}

	/**
	 * @param string                             $phase
	 * @param string                             $criticality
	 * @param string                             $breakage
	 * @param array<int,array<string,mixed>>     $exposed
	 * @param array<int,array<string,mixed>>     $failures
	 * @return string
	 */
	private static function summary( $phase, $criticality, $breakage, array $exposed, array $failures ) {
		if ( 'post_patch' === $phase && ! empty( $failures ) ) {
			return 'The update check found pages that did not respond as expected.';
		}
		if ( 'post_patch' === $phase ) {
			return 'The update check has been recorded for this website.';
		}
		if ( ! empty( $exposed ) && 'critical' === $criticality ) {
			return 'Security updates are waiting. The suggested window is as soon as you can.';
		}
		if ( ! empty( $exposed ) ) {
			return 'Security updates are waiting for this website.';
		}
		if ( 'high' === $breakage ) {
			return 'The page check found pages that did not respond as expected.';
		}

		return 'No urgent updates were reported.';
	}

	/**
	 * @param string              $kind
	 * @param string              $host
	 * @param string              $account
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $triage
	 * @return array{subject:string,body:string}
	 */
	private static function email_copy( $kind, $host, $account, array $report, array $triage ) {
		$link = '' !== $account ? $account : '';
		$open = '' !== $link ? "Open Maintenance in your account:\n" . $link . "\n" : "Open Maintenance in your ReactWoo account.\n";
		if ( 'exposure' === $kind ) {
			$body  = "Security updates are waiting for {$host}.\n\n";
			$body .= 'Criticality: ' . (string) $triage['criticality'] . ".\n\n";
			$body .= "Exposed:\n" . self::item_lines( $triage['exposed'] ) . "\n";
			if ( ! empty( $triage['failed_pages'] ) ) {
				$body .= "\nThe page check recorded:\n" . self::failure_lines( $triage['failed_pages'] ) . "\n";
			}
			$body .= "\n" . (string) $triage['window_label'] . "\n\nThis note describes what the report contained. It is not a confirmed booking.\n\n" . $open;

			return array(
				'subject' => self::subject( 'Security updates are waiting for ' . $host ),
				'body'    => $body,
			);
		}
		if ( 'planned_patch' === $kind ) {
			$when = '' !== (string) $triage['suggested_patch_at'] ? (string) $triage['suggested_patch_at'] . ' UTC' : 'the suggested window';
			$body = "A suggested patch time for {$host} is {$when}.\n\n" . (string) $triage['window_label'] . "\n\nThis is a suggestion, not a confirmed booking.\n\n" . $open;

			return array(
				'subject' => self::subject( 'Suggested patch time for ' . $host ),
				'body'    => $body,
			);
		}

		$body = "An update check has been recorded for {$host}.\n\n";
		if ( ! empty( $triage['fixed'] ) ) {
			$body .= "Fixed:\n" . self::item_lines( $triage['fixed'] ) . "\n\n";
		}
		if ( ! empty( $triage['exposed'] ) ) {
			$body .= "Still exposed:\n" . self::item_lines( $triage['exposed'] ) . "\n\n";
		}
		$pages = isset( $triage['pages_checked'] ) ? (int) $triage['pages_checked'] : 0;
		if ( $pages > 0 ) {
			$body .= 'Pages checked: ' . $pages . ".\n\n";
		}
		if ( ! empty( $triage['failed_pages'] ) ) {
			$body .= "Pages that did not respond as expected:\n" . self::failure_lines( $triage['failed_pages'] ) . "\n\n";
		} else {
			$body .= "No failed pages were recorded.\n\n";
		}
		$body .= "You can choose one of these in your account. None of them runs until you confirm it there.\n\n";
		$ref   = isset( $report['backup_ref'] ) ? (string) $report['backup_ref'] : '';
		if ( self::valid_backup_ref( $ref ) ) {
			$body .= 'Roll back the stored snapshot ' . $ref . ":\n" . self::anchor( $link, 'rw-maint-report-rollback' ) . "\n\n";
		} else {
			$body .= "A snapshot reference was not stored with this report, so rollback is not available from this check.\n\n";
		}
		$body .= "Contact support about this website:\n" . self::anchor( $link, 'rw-maint-report-support' ) . "\n\n";
		$body .= "Fix the pages yourself:\n" . self::anchor( $link, 'rw-maint-report-self' ) . "\n\n";
		if ( self::valid_backup_ref( $ref ) ) {
			$body .= "Rolling back uses the snapshot reference stored with this report. It does not start until you confirm it in your account.\n";
		}

		return array(
			'subject' => self::subject( 'Update check for ' . $host ),
			'body'    => $body,
		);
	}

	/**
	 * @param string $link
	 * @param string $anchor
	 * @return string
	 */
	private static function anchor( $link, $anchor ) {
		if ( '' === $link ) {
			return 'Open Maintenance in your ReactWoo account and look for ' . $anchor . '.';
		}

		return $link . '#' . $anchor;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return string
	 */
	private static function item_lines( array $items ) {
		$lines = array();
		foreach ( $items as $item ) {
			if ( isset( $item['kind'] ) && 'conflict' === $item['kind'] ) {
				$lines[] = '- Conflict removed: ' . (string) $item['slug'];
				continue;
			}
			$line = '- ' . (string) $item['slug'];
			if ( '' !== (string) $item['from'] || '' !== (string) $item['to'] ) {
				$line .= ' ' . (string) $item['from'] . ' to ' . (string) $item['to'];
			}
			if ( '' !== (string) $item['severity'] ) {
				$line .= ' (' . (string) $item['severity'] . ')';
			}
			$lines[] = $line;
		}

		return empty( $lines ) ? '- None listed.' : implode( "\n", $lines );
	}

	/**
	 * @param array<int,array<string,mixed>> $failures
	 * @return string
	 */
	private static function failure_lines( array $failures ) {
		$lines = array();
		foreach ( $failures as $failure ) {
			$line = '- ' . (string) $failure['url'];
			if ( ! empty( $failure['status'] ) ) {
				$line .= ' status ' . (int) $failure['status'];
			}
			if ( ! empty( $failure['note'] ) ) {
				$line .= ': ' . (string) $failure['note'];
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,mixed> $item
	 * @param string              $kind
	 * @return array<string,string>
	 */
	private static function public_item( array $item, $kind ) {
		return array(
			'slug'     => isset( $item['slug'] ) ? (string) $item['slug'] : '',
			'from'     => isset( $item['from'] ) ? (string) $item['from'] : '',
			'to'       => isset( $item['to'] ) ? (string) $item['to'] : '',
			'severity' => isset( $item['severity_hint'] ) ? (string) $item['severity_hint'] : '',
			'excerpt'  => isset( $item['changelog_excerpt'] ) ? (string) $item['changelog_excerpt'] : '',
			'kind'     => $kind,
		);
	}

	/**
	 * @param array<string,mixed> $report
	 * @return string
	 */
	private static function canonical( array $report ) {
		$copy = $report;
		unset( $copy['warnings'], $copy['hash'] );

		return (string) wp_json_encode( $copy );
	}

	/**
	 * @param mixed $value
	 * @return int
	 */
	private static function digits( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		$text = trim( (string) $value );
		if ( '' === $text || ! ctype_digit( $text ) ) {
			return 0;
		}

		return (int) $text;
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private static function flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value === 1;
		}
		$text = strtolower( trim( (string) $value ) );

		return in_array( $text, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * @param mixed $value
	 * @return int
	 */
	private static function parse_time( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) {
			$number = (int) $value;
			if ( $number > 9999999999 ) {
				$number = (int) floor( $number / 1000 );
			}

			return $number > 0 ? $number : 0;
		}
		$parsed = strtotime( (string) $value );

		return $parsed ? (int) $parsed : 0;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function severity( $value ) {
		$text = strtolower( trim( self::plain( $value, 20 ) ) );
		if ( in_array( $text, array( 'critical', 'high', 'medium', 'low', 'info' ), true ) ) {
			return $text;
		}

		return '';
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function slug( $value ) {
		$text = strtolower( trim( self::plain( $value, 120 ) ) );
		$text = (string) preg_replace( '/[^a-z0-9._\/-]/', '', $text );

		return substr( $text, 0, 120 );
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function version_text( $value ) {
		return self::plain( $value, 32 );
	}

	/**
	 * @param mixed $value
	 * @param int   $limit
	 * @return string
	 */
	private static function plain( $value, $limit ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$text = trim( wp_strip_all_tags( (string) $value ) );
		$text = (string) preg_replace( '/\s+/', ' ', $text );
		if ( strlen( $text ) > $limit ) {
			$text = substr( $text, 0, $limit );
		}

		return $text;
	}

	/**
	 * @param mixed $url
	 * @return string
	 */
	private static function clean_page_url( $url ) {
		$url = self::plain( $url, 300 );
		if ( '' === $url ) {
			return '';
		}
		if ( isset( $url[0] ) && '/' === $url[0] ) {
			if ( isset( $url[1] ) && '/' === $url[1] ) {
				return '';
			}

			return $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		return '';
	}

	/**
	 * @param mixed $url
	 * @return string
	 */
	private static function clean_account_url( $url ) {
		$url = self::plain( $url, 300 );
		if ( ! preg_match( '#^https://#i', $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * @param array<int,mixed> $emails
	 * @return string[]
	 */
	private static function clean_emails( array $emails ) {
		$clean = array();
		foreach ( $emails as $email ) {
			if ( count( $clean ) >= 5 ) {
				break;
			}
			$text = trim( (string) $email );
			if ( function_exists( 'is_email' ) && ! is_email( $text ) ) {
				continue;
			}
			if ( ! function_exists( 'is_email' ) && ! filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}
			$clean[ strtolower( $text ) ] = $text;
		}

		return array_values( $clean );
	}

	/**
	 * @param string $subject
	 * @return string
	 */
	private static function subject( $subject ) {
		$subject = self::plain( $subject, 180 );

		return '' !== $subject ? $subject : 'Maintenance report';
	}

	/**
	 * @param array<mixed> $value
	 * @return bool
	 */
	private static function is_assoc( array $value ) {
		if ( array() === $value ) {
			return false;
		}

		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
