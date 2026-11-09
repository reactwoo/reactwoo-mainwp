<?php
/**
 * Customer-triggered maintenance dashboard actions.
 *
 * Rate limits, confirmations, ownership, and audit logging for sync/health,
 * pause/resume, backup, updates, scan, and report download.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Customer_Actions {
	const RATE_WINDOW = 900; // 15 minutes.
	const RATE_MAX    = 3;   // Per action per site per window.

	/**
	 * Actions that require an explicit confirm checkbox.
	 *
	 * @var string[]
	 */
	const CONFIRM_ACTIONS = array(
		'check_site',
		'sync_site',
		'request_backup',
		'run_updates',
		'security_scan',
		'pause_maintenance',
		'resume_maintenance',
	);

	/**
	 * Bridge route slug for each portal action (null = portal-local).
	 *
	 * @var array<string,string|null>
	 */
	const BRIDGE_ACTIONS = array(
		'check_site'           => 'check',
		'sync_site'            => 'sync',
		'pause_maintenance'    => 'suspend',
		'resume_maintenance'   => 'resume',
		'request_backup'       => 'backup',
		'run_updates'          => 'run-updates',
		'security_scan'        => 'security-scan',
		'download_report'      => null,
	);

	/**
	 * @return void
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download_report' ), 4 );
	}

	/**
	 * Handle a dashboard POST action after nonce + login checks in RW_Portal_Account.
	 *
	 * @param int    $user_id Current user.
	 * @param string $action  Sanitized rw_portal_action.
	 * @return bool True when this class handled the action.
	 */
	public static function handle( $user_id, $action ) {
		$user_id = (int) $user_id;
		$action  = sanitize_key( $action );

		if ( ! array_key_exists( $action, self::BRIDGE_ACTIONS ) ) {
			return false;
		}

		$site = self::load_owned_site( $user_id );
		if ( ! $site ) {
			return true;
		}

		if ( in_array( $action, self::CONFIRM_ACTIONS, true ) && empty( $_POST['rw_confirm_action'] ) ) {
			self::set_status_notice(
				$user_id,
				'warning',
				'Confirm required',
				'Tick the confirmation checkbox before this action can run.'
			);
			return true;
		}

		$rate = self::check_rate_limit( $user_id, (int) $site->id, $action );
		if ( is_wp_error( $rate ) ) {
			self::set_card_notice(
				$user_id,
				$action,
				'rate_limited',
				self::rate_limit_message()
			);
			return true;
		}

		if ( 'download_report' === $action ) {
			self::stream_report_pdf( $site, $user_id );
			return true;
		}

		if ( 'request_backup' === $action || 'security_scan' === $action ) {
			$result = self::run_connector_action( $site, $action );
		} else {
			$bridge = self::BRIDGE_ACTIONS[ $action ];
			$result = RW_Maint_Client::update_site_status( $site, $bridge );
		}
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			RW_Audit::log(
				'maint_action_failed',
				array(
					'user_id'         => $user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'action'          => $action,
					'error'           => $result->get_error_message(),
					'error_code'      => $code,
				)
			);
			self::set_status_notice(
				$user_id,
				'critical',
				'Action failed',
				self::friendly_error( $result )
			);
			return true;
		}

		self::bump_rate_limit( $user_id, (int) $site->id, $action );
		if ( 'request_backup' === $action ) {
			self::remember_backup_ref( (int) $site->id, $result );
		}

		$timestamp = current_time( 'mysql', true );
		if ( 'check_site' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'last_check_at' => $timestamp ) );
		} elseif ( 'sync_site' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'last_sync_at' => $timestamp ) );
		} elseif ( 'pause_maintenance' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'status' => 'suspended' ) );
		} elseif ( 'resume_maintenance' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'status' => 'active' ) );
		}

		RW_Audit::log(
			'customer_' . $action,
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		self::set_status_notice(
			$user_id,
			'success',
			'Done',
			self::outcome_message( $action, is_array( $result ) ? $result : array() )
		);
		return true;
	}

	/**
	 * Signed connector call for actions MainWP 6.4.1 cannot start.
	 *
	 * @param object $site Portal site.
	 * @param string $action request_backup|security_scan.
	 * @return array|WP_Error
	 */
	private static function run_connector_action( $site, $action ) {
		$secret = class_exists( 'RW_Tokens' ) ? RW_Tokens::get_site_secret( (int) $site->id ) : '';
		if ( ! is_string( $secret ) || '' === $secret ) {
			return new WP_Error( 'rw_connector_secret_missing', 'This website has not finished enrolment, so the action could not run.', array( 'status' => 409 ) );
		}

		if ( 'request_backup' === $action ) {
			$route   = '/reactwoo-connector/v2/snapshot';
			$timeout = 120;
			$body    = wp_json_encode( array( 'purpose' => 'customer' ) );
		} else {
			$route   = '/reactwoo-connector/v2/security-scan';
			$timeout = 90;
			$body    = wp_json_encode( array( 'purpose' => 'customer' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $timeout + 30 );
		}

		return RW_WordPress_Client::connector_command( (string) $site->site_url, $route, (string) $body, (int) $site->id, $secret, $timeout );
	}

	/**
	 * Keep the latest snapshot reference for a later report or rollback.
	 *
	 * @param int                 $site_id Portal site id.
	 * @param array<string,mixed> $result Connector snapshot body.
	 * @return void
	 */
	public static function remember_backup_ref( $site_id, $result ) {
		if ( ! is_array( $result ) || empty( $result['backup_ref'] ) ) {
			return;
		}
		$ref = sanitize_text_field( (string) $result['backup_ref'] );
		if ( ! class_exists( 'RW_Connector_Snapshot' ) || ! RW_Connector_Snapshot::valid_reference( $ref ) ) {
			if ( ! preg_match( '/^rwsnap_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{8,32}$/', $ref ) ) {
				return;
			}
		}
		$includes = array();
		if ( isset( $result['includes'] ) && is_array( $result['includes'] ) ) {
			foreach ( $result['includes'] as $part ) {
				$includes[] = sanitize_key( (string) $part );
			}
		}
		update_option(
			'rw_portal_backup_' . (int) $site_id,
			array(
				'backup_ref' => $ref,
				'created_at' => isset( $result['created_at'] ) ? sanitize_text_field( (string) $result['created_at'] ) : '',
				'bytes'      => isset( $result['bytes'] ) ? absint( $result['bytes'] ) : 0,
				'location'   => 'onsite',
				'includes'   => $includes,
				'purpose'    => isset( $result['purpose'] ) ? sanitize_key( (string) $result['purpose'] ) : 'customer',
			),
			false
		);
	}

	/**
	 * Parse and persist multiple report recipient emails.
	 *
	 * @param int $user_id Current user.
	 * @return bool
	 */
	public static function handle_update_reporting( $user_id ) {
		$site = self::load_owned_site( $user_id );
		if ( ! $site ) {
			return true;
		}

		$raw = '';
		if ( isset( $_POST['report_recipients'] ) ) {
			$raw = (string) wp_unslash( $_POST['report_recipients'] );
		} elseif ( isset( $_POST['report_email'] ) ) {
			$raw = (string) wp_unslash( $_POST['report_email'] );
		}

		$emails = self::parse_emails( $raw );
		if ( empty( $emails ) ) {
			self::notice( 'Enter at least one valid report email address.', 'error' );
			return true;
		}

		$report_enabled = isset( $_POST['report_enabled'] ) ? 1 : 0;
		$primary        = $emails[0];
		$recipients     = implode( ', ', $emails );

		// Store comma-separated list in report_email (TEXT column).
		RW_Sites::update_identity(
			(int) $site->id,
			array(
				'report_email'   => $recipients,
				'report_enabled' => $report_enabled,
			)
		);

		$site = RW_Sites::get_site( (int) $site->id );
		if ( $site ) {
			// Bridge enroll still expects a primary email — send the first.
			$site->report_email = $primary;
			$maint_result       = RW_Maint_Client::enroll_site( $site );
			if ( is_wp_error( $maint_result ) ) {
				RW_Audit::log(
					'maint_sync_failed',
					array(
						'user_id'         => $user_id,
						'subscription_id' => (int) $site->subscription_id,
						'managed_site_id' => (int) $site->id,
						'error'           => $maint_result->get_error_message(),
					)
				);
				self::notice( 'Recipients saved, but maintenance hub sync failed.', 'error' );
				return true;
			}
		}

		RW_Audit::log(
			'reporting_updated',
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
				'recipient_count' => count( $emails ),
			)
		);

		self::notice( 'Report recipients updated.', 'success' );
		return true;
	}

	/**
	 * @param string $raw Comma/newline separated emails.
	 * @return string[]
	 */
	public static function parse_emails( $raw ) {
		$parts  = preg_split( '/[\s,;]+/', (string) $raw );
		$emails = array();
		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( $part );
			if ( $email && is_email( $email ) ) {
				$emails[ strtolower( $email ) ] = $email;
			}
		}
		return array_values( $emails );
	}

	/**
	 * @param int $user_id
	 * @return object|null
	 */
	private static function load_owned_site( $user_id ) {
		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		if ( ! $site_id ) {
			self::notice( 'Site is required.', 'error' );
			return null;
		}

		$site = RW_Sites::get_site( $site_id );
		if ( ! $site || (int) $site->user_id !== (int) $user_id ) {
			self::notice( 'Site not found.', 'error' );
			return null;
		}

		return $site;
	}

	/**
	 * @param int    $user_id
	 * @param int    $site_id
	 * @param string $action
	 * @return true|WP_Error
	 */
	public static function check_rate_limit( $user_id, $site_id, $action ) {
		$key   = self::rate_key( $user_id, $site_id, $action );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX ) {
			return new WP_Error(
				'rw_rate_limited',
				'Please wait a few minutes before running this action again on this site.'
			);
		}
		return true;
	}

	/**
	 * @param int    $user_id
	 * @param int    $site_id
	 * @param string $action
	 * @return void
	 */
	public static function bump_rate_limit( $user_id, $site_id, $action ) {
		$key   = self::rate_key( $user_id, $site_id, $action );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}

	/**
	 * @param int    $user_id
	 * @param int    $site_id
	 * @param string $action
	 * @return string
	 */
	private static function rate_key( $user_id, $site_id, $action ) {
		return 'rw_ca_' . md5( (int) $user_id . ':' . (int) $site_id . ':' . sanitize_key( $action ) );
	}

	/**
	 * @param WP_Error $error
	 * @return string
	 */
	private static function friendly_error( $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$direct = array(
			'rw_scan_unavailable',
			'rw_scan_failed',
			'rw_snapshot_failed',
			'rw_snapshot_unavailable',
			'rw_connector_unreachable',
			'rw_connector_route_missing',
			'rw_connector_secret_missing',
			'rw_connector_already_enrolled',
			'rw_update_unprotected',
		);
		if ( in_array( $code, $direct, true ) ) {
			return self::safe_customer_message( $error->get_error_message() );
		}
		if ( 'rw_maint_bad_response' === $code ) {
			$message = self::safe_customer_message( $error->get_error_message() );
			if ( 'Maintenance hub responded with an error.' !== $message && 'The action failed.' !== $message ) {
				return $message;
			}
		}
		if (
			in_array( $code, array( 'rw_maint_http_404', 'rw_maint_http_error', 'http_request_failed', 'rw_maint_bad_response' ), true )
			|| in_array( $status, array( 404, 501 ), true )
			|| false !== stripos( $error->get_error_message(), 'not found' )
			|| false !== stripos( $error->get_error_message(), '404' )
		) {
			return 'This action is not available on the maintenance hub yet. Your request was not applied.';
		}
		if ( 'rw_mainwp_action_unsupported' === $code ) {
			return 'This action is not available for this site yet.';
		}
		return 'Maintenance hub action failed. Please try again later or contact support.';
	}

	/**
	 * @param string $message Candidate customer copy.
	 * @return string
	 */
	private static function safe_customer_message( $message ) {
		$message = sanitize_text_field( (string) $message );
		if ( '' === $message || strlen( $message ) > 240 ) {
			return 'The action failed.';
		}

		return $message;
	}

	/**
	 * Customer copy for a rate limit. Shown on the action card.
	 *
	 * @return string
	 */
	public static function rate_limit_message() {
		return 'You can run this action up to ' . (int) self::RATE_MAX . ' times every ' . (int) ( self::RATE_WINDOW / 60 ) . ' minutes for this site. Try again shortly.';
	}

	/**
	 * Honest result copy. A started job is not described as finished.
	 *
	 * @param string              $action Portal action.
	 * @param array<string,mixed> $result Action body.
	 * @return string
	 */
	public static function outcome_message( $action, array $result = array() ) {
		if ( 'request_backup' === $action && ! empty( $result['backup_ref'] ) ) {
			return 'A snapshot of the plugins, themes and database has been saved on this website. Reference: ' . sanitize_text_field( (string) $result['backup_ref'] ) . '.';
		}
		if ( 'security_scan' === $action && isset( $result['state'] ) && 'already_running' === $result['state'] ) {
			return 'A Wordfence security scan is already running on this website.';
		}
		if ( 'security_scan' === $action ) {
			return 'A Wordfence security scan has been started on this website. Results appear when the scan finishes.';
		}
		if ( 'run_updates' === $action ) {
			$ref = isset( $result['backup_ref'] ) ? sanitize_text_field( (string) $result['backup_ref'] ) : '';
			if ( '' !== $ref ) {
				return 'A snapshot was saved (' . $ref . ') and pending updates have been started. Completion has not been confirmed.';
			}
			return 'Pending updates have been started on the maintenance hub. Completion has not been confirmed.';
		}

		$messages = array(
			'check_site'         => 'Health check requested.',
			'sync_site'          => 'Sync requested.',
			'pause_maintenance'  => 'Maintenance paused for this site.',
			'resume_maintenance' => 'Maintenance resumed for this site.',
		);
		return isset( $messages[ $action ] ) ? $messages[ $action ] : 'Action completed.';
	}

	/**
	 * @param string $message
	 * @param string $type
	 * @return void
	 */
	private static function notice( $message, $type = 'success' ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	/**
	 * Stream a simple PDF report built from the cached maintenance snapshot.
	 *
	 * @param object $site
	 * @param int    $user_id
	 * @return void
	 */
	public static function stream_report_pdf( $site, $user_id ) {
		$snapshot = class_exists( 'RW_Portal_Maintenance_Status' )
			? RW_Portal_Maintenance_Status::for_site( $site )
			: array();
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$host = wp_parse_url( (string) $site->site_url, PHP_URL_HOST );
		$host = $host ? $host : 'site';
		$lines = array(
			'ReactWoo maintenance report',
			'Site: ' . $host,
			'URL: ' . (string) $site->site_url,
			'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'',
		);
		if ( ! empty( $snapshot['summary'] ) && is_array( $snapshot['summary'] ) ) {
			foreach ( $snapshot['summary'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$lines[] = ucwords( str_replace( '_', ' ', (string) $key ) ) . ': ' . (string) $value;
				}
			}
		}
		if ( ! empty( $snapshot['flags'] ) && is_array( $snapshot['flags'] ) ) {
			$lines[] = '';
			$lines[] = 'Attention flags:';
			foreach ( $snapshot['flags'] as $flag ) {
				$lines[] = '- ' . ( is_scalar( $flag ) ? (string) $flag : wp_json_encode( $flag ) );
			}
		}

		$pdf = self::build_simple_pdf( $lines );
		$filename = 'reactwoo-report-' . sanitize_file_name( $host ) . '.pdf';

		RW_Audit::log(
			'customer_download_report',
			array(
				'user_id'         => (int) $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * GET ?rw_maint_report=1 handler (nonce + ownership).
	 *
	 * @return void
	 */
	public static function maybe_download_report() {
		if ( empty( $_GET['rw_maint_report'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$nonce = isset( $_GET['rw_maint_report_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_maint_report_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rw_download_maint_report' ) ) {
			wp_die( esc_html__( 'This download link has expired.', 'reactwoo-support-portal' ), '', array( 'response' => 403 ) );
		}
		$site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;
		$site    = $site_id ? RW_Sites::get_site( $site_id ) : null;
		$user_id = get_current_user_id();
		if ( ! $site || (int) $site->user_id !== (int) $user_id ) {
			wp_die( esc_html__( 'Site not found.', 'reactwoo-support-portal' ), '', array( 'response' => 404 ) );
		}
		self::stream_report_pdf( $site, $user_id );
	}

	/**
	 * Minimal single-page PDF (Helvetica) from plain text lines.
	 *
	 * @param string[] $lines
	 * @return string
	 */
	public static function build_simple_pdf( array $lines ) {
		$content = "BT /F1 11 Tf 50 780 Td 14 TL\n";
		foreach ( $lines as $i => $line ) {
			$safe = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), (string) $line );
			if ( $i > 0 ) {
				$content .= "T*\n";
			}
			$content .= '(' . $safe . ") Tj\n";
		}
		$content .= "ET\n";

		$objects   = array();
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
		$objects[] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "endstream";
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		$pdf    = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $i => $obj ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $obj . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	/**
	 * Report download URL for a site.
	 *
	 * @param object $site
	 * @return string
	 */
	public static function report_download_url( $site ) {
		$base = RW_Portal_Account::maintenance_url( (int) $site->subscription_id );
		return wp_nonce_url(
			add_query_arg(
				array(
					'rw_maint_report' => '1',
					'site_id'         => (int) $site->id,
				),
				$base
			),
			'rw_download_maint_report',
			'rw_maint_report_nonce'
		);
	}

	/**
	 * Render a confirmed action form.
	 *
	 * @param object $site
	 * @param string $action
	 * @param string $label
	 * @param string $confirm_label
	 * @param string $button_class
	 * @return void
	 */
	public static function render_action_form( $site, $action, $label, $confirm_label, $button_class = 'rw-maint-button-secondary' ) {
		$card_notice = self::consume_card_notice_for_action( get_current_user_id(), $action );
		echo '<form class="rw-maint-action-form" method="post">';
		RW_Portal_Account::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<label class="rw-maint-confirm"><input type="checkbox" name="rw_confirm_action" value="1" required /> ' . esc_html( $confirm_label ) . '</label>';
		echo '<button type="submit" class="' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
		if ( $card_notice ) {
			echo '<p class="rw-maint-card-notice rw-maint-card-notice--' . esc_attr( $card_notice['tone'] ) . '" role="status">';
			echo '<span class="rw-maint-pill">' . esc_html( $card_notice['label'] ) . '</span> ';
			echo '<span class="rw-maint-card-notice__text">' . esc_html( $card_notice['message'] ) . '</span>';
			echo '</p>';
		}
	}

	/**
	 * Persist a short-lived page status notice (light severity strip).
	 *
	 * @param int    $user_id
	 * @param string $tone    info|warning|critical
	 * @param string $label
	 * @param string $message
	 * @return void
	 */
	public static function set_status_notice( $user_id, $tone, $label, $message ) {
		set_transient(
			self::status_notice_key( $user_id ),
			array(
				'tone'    => sanitize_key( $tone ),
				'label'   => (string) $label,
				'message' => (string) $message,
			),
			120
		);
	}

	/**
	 * @param int $user_id
	 * @return array{tone:string,label:string,message:string}|null
	 */
	public static function consume_status_notice( $user_id ) {
		$key = self::status_notice_key( $user_id );
		$val = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $val ) || empty( $val['message'] ) ) {
			return null;
		}
		return array(
			'tone'    => isset( $val['tone'] ) ? sanitize_key( $val['tone'] ) : 'info',
			'label'   => isset( $val['label'] ) ? (string) $val['label'] : '',
			'message' => (string) $val['message'],
		);
	}

	/**
	 * Rate-limit (and similar) messages belong on the action card, not a page banner.
	 *
	 * @param int    $user_id
	 * @param string $action
	 * @param string $tone
	 * @param string $message
	 * @return void
	 */
	public static function set_card_notice( $user_id, $action, $tone, $message ) {
		set_transient(
			self::card_notice_key( $user_id ),
			array(
				'action'  => sanitize_key( $action ),
				'tone'    => sanitize_key( $tone ),
				'label'   => 'rate_limited' === $tone ? 'Rate limited' : 'Notice',
				'message' => (string) $message,
			),
			120
		);
	}

	/**
	 * @param int    $user_id
	 * @param string $action
	 * @return array{tone:string,label:string,message:string}|null
	 */
	public static function consume_card_notice_for_action( $user_id, $action ) {
		$key = self::card_notice_key( $user_id );
		$val = get_transient( $key );
		if ( ! is_array( $val ) || empty( $val['action'] ) || sanitize_key( $action ) !== sanitize_key( $val['action'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'tone'    => isset( $val['tone'] ) ? sanitize_key( $val['tone'] ) : 'warning',
			'label'   => isset( $val['label'] ) ? (string) $val['label'] : 'Notice',
			'message' => isset( $val['message'] ) ? (string) $val['message'] : '',
		);
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	private static function status_notice_key( $user_id ) {
		return 'rw_ca_status_' . (int) $user_id;
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	private static function card_notice_key( $user_id ) {
		return 'rw_ca_card_' . (int) $user_id;
	}
}
