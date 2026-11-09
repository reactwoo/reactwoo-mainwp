<?php
/**
 * Account-site half of maintenance reports.
 *
 * The connector posts here with the same signature as a heartbeat. This class
 * forwards the compact JSON to the maintenance hub. Screens are rendered only
 * from the connected maintenance dashboard. No assets are enqueued here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Reports {
	const CRON_HOOK            = 'rw_portal_report_notices';
	const OPTION_ENABLED       = 'rw_portal_report_emails_enabled';
	const OPTION_SENDER        = 'rw_portal_report_email_sender';
	const OPTION_NOTICE_HOURS  = 'rw_portal_report_notice_hours';
	const MAX_BYTES            = 65536;
	const BACKUP_REF_PATTERN   = '/^rwsnap_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{8,32}$/';
	const RESTORE_ROUTE        = '/reactwoo-connector/v2/restore';

	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_due_notices' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * @return array{enabled:bool,sender:string,notice_hours:int}
	 */
	public static function email_settings() {
		$enabled = function_exists( 'get_option' ) ? get_option( self::OPTION_ENABLED, 0 ) : 0;
		$sender  = function_exists( 'get_option' ) ? get_option( self::OPTION_SENDER, 'portal' ) : 'portal';
		$hours   = function_exists( 'get_option' ) ? get_option( self::OPTION_NOTICE_HOURS, 24 ) : 24;

		return self::normalise_settings( $enabled, $sender, $hours );
	}

	/**
	 * @param mixed $enabled
	 * @param mixed $sender
	 * @param mixed $hours
	 * @return array{enabled:bool,sender:string,notice_hours:int}
	 */
	public static function normalise_settings( $enabled, $sender, $hours ) {
		$sender = is_string( $sender ) ? strtolower( trim( $sender ) ) : '';
		if ( ! in_array( $sender, array( 'portal', 'maint' ), true ) ) {
			$sender = 'portal';
		}
		$hours = is_numeric( $hours ) ? (int) $hours : 24;
		if ( $hours < 1 || $hours > 168 ) {
			$hours = 24;
		}
		$on = false;
		if ( is_bool( $enabled ) ) {
			$on = $enabled;
		} elseif ( is_numeric( $enabled ) ) {
			$on = (int) $enabled === 1;
		} else {
			$on = in_array( strtolower( trim( (string) $enabled ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return array(
			'enabled'      => $on,
			'sender'       => $sender,
			'notice_hours' => $hours,
		);
	}

	/**
	 * @param int                 $header_site_id
	 * @param array<string,mixed> $data
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
	 * Signed connector ingest. Forwards the compact body to the hub.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ingest( WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'rw_report_too_large', 'The report is too large.', array( 'status' => 413 ) );
		}
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'rw_report_invalid', 'The report was not valid JSON.', array( 'status' => 400 ) );
		}

		$site_id = self::digits( $request->get_header( 'x-rw-site-id' ) );
		if ( $site_id <= 0 ) {
			return new WP_Error( 'rw_site_id_missing', 'Site ID header missing.', array( 'status' => 400 ) );
		}
		$verified = RW_Security::verify_signed_request( $request, $site_id );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return new WP_Error( 'rw_report_invalid', 'The report must be a JSON object.', array( 'status' => 400 ) );
		}
		$conflict = self::identity_conflict( $site_id, $data );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}

		$site = RW_Sites::get_site( $site_id );
		if ( ! is_object( $site ) ) {
			return new WP_Error( 'rw_site_missing', 'Managed site not found.', array( 'status' => 404 ) );
		}
		if ( isset( $site->status ) && 'disconnected' === (string) $site->status ) {
			return new WP_Error( 'rw_report_site', 'This website is not connected.', array( 'status' => 409 ) );
		}

		$forward = self::forward_body( $site, $data );
		$result  = RW_Maint_Client::post_signed( 'maintenance-report', $forward );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'rw_report_forward', 'The maintenance hub could not store this report.', array( 'status' => 502 ) );
		}
		self::forget_cache( (int) $site->id );

		return rest_ensure_response(
			array(
				'received'    => true,
				'report_id'   => isset( $result['report_id'] ) ? (int) $result['report_id'] : 0,
				'criticality' => isset( $result['criticality'] ) ? sanitize_key( (string) $result['criticality'] ) : '',
				'phase'       => isset( $result['phase'] ) ? sanitize_key( (string) $result['phase'] ) : '',
			)
		);
	}

	/**
	 * @param object              $site Portal site.
	 * @param array<string,mixed> $report Compact report.
	 * @return array<string,mixed>
	 */
	public static function forward_body( $site, array $report ) {
		$settings = self::email_settings();
		$emails   = array();
		if ( ! empty( $site->report_email ) && class_exists( 'RW_Portal_Customer_Actions' ) ) {
			$emails = RW_Portal_Customer_Actions::parse_emails( (string) $site->report_email );
		}

		return array(
			'portal_site_id'  => (int) $site->id,
			'subscription_id' => isset( $site->subscription_id ) ? (int) $site->subscription_id : 0,
			'user_id'         => isset( $site->user_id ) ? (int) $site->user_id : 0,
			'account_url'     => class_exists( 'RW_Portal_Account' ) ? RW_Portal_Account::maintenance_url( isset( $site->subscription_id ) ? (int) $site->subscription_id : 0 ) : '',
			'report_enabled'  => ! isset( $site->report_enabled ) || (int) $site->report_enabled === 1 ? 1 : 0,
			'recipients'      => $emails,
			'email'           => array(
				'enabled'      => $settings['enabled'] ? 1 : 0,
				'sender'       => $settings['sender'],
				'notice_hours' => $settings['notice_hours'],
			),
			'report'          => $report,
		);
	}

	/**
	 * @return array{loaded:bool,unavailable:bool,items:array<int,array<string,mixed>>,latest:array<string,mixed>|null,strip:array<string,mixed>|null}
	 */
	public static function empty_view() {
		return array(
			'loaded'      => false,
			'unavailable' => false,
			'items'       => array(),
			'latest'      => null,
			'strip'       => null,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $reports
	 * @return array{loaded:bool,unavailable:bool,items:array<int,array<string,mixed>>,latest:array<string,mixed>|null,strip:array<string,mixed>|null}
	 */
	public static function present( array $reports ) {
		$items = array();
		foreach ( $reports as $report ) {
			if ( is_array( $report ) && ! empty( $report['id'] ) ) {
				$items[] = $report;
			}
		}
		$latest = ! empty( $items ) ? $items[0] : null;

		return array(
			'loaded'      => true,
			'unavailable' => false,
			'items'       => $items,
			'latest'      => $latest,
			'strip'       => self::strip_for( $latest ),
		);
	}

	/**
	 * Light status only. Critical report detail stays in the report panel.
	 *
	 * @param array<string,mixed>|null $latest
	 * @return array{tone:string,label:string,message:string,cta:array{href:string,label:string}}|null
	 */
	public static function strip_for( $latest ) {
		if ( ! is_array( $latest ) || empty( $latest['summary'] ) ) {
			return null;
		}
		$critical = isset( $latest['criticality'] ) && 'critical' === $latest['criticality'];
		$broken   = isset( $latest['breakage_risk'] ) && 'high' === $latest['breakage_risk'];
		if ( ! $critical && ! $broken ) {
			return null;
		}

		return array(
			'tone'    => 'warning',
			'label'   => $critical ? 'Security' : 'Pages',
			'message' => (string) $latest['summary'],
			'cta'     => array(
				'href'  => '#rw-maint-update-reports',
				'label' => 'View report',
			),
		);
	}

	/**
	 * @param object $site Portal site.
	 * @return array<string,mixed>
	 */
	public static function for_dashboard( $site ) {
		if ( ! is_object( $site ) || empty( $site->id ) || ! class_exists( 'RW_Maint_Client' ) ) {
			return self::empty_view();
		}
		$key    = 'rw_portal_update_reports_' . (int) $site->id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['loaded'] ) ) {
			return $cached;
		}
		$result = RW_Maint_Client::post_signed(
			'maintenance-reports',
			array(
				'portal_site_id'  => (int) $site->id,
				'subscription_id' => isset( $site->subscription_id ) ? (int) $site->subscription_id : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			$view = self::empty_view();
			$view['unavailable'] = true;
			set_transient( $key, $view, 60 );
			return $view;
		}
		$reports = ( isset( $result['reports'] ) && is_array( $result['reports'] ) ) ? $result['reports'] : array();
		$view    = self::present( $reports );
		set_transient( $key, $view, 120 );

		return $view;
	}

	/**
	 * @param int    $user_id
	 * @param string $action report_rollback|report_support|report_self_fix
	 * @return void
	 */
	public static function handle_action( $user_id, $action ) {
		$user_id = (int) $user_id;
		$action  = sanitize_key( $action );
		$site_id = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
		$site    = $site_id ? RW_Sites::get_site( $site_id ) : null;
		if ( ! is_object( $site ) || (int) $site->user_id !== $user_id ) {
			RW_Portal_Customer_Actions::set_status_notice( $user_id, 'warning', 'Notice', 'Site not found.' );
			return;
		}

		$confirmed = ! empty( $_POST['rw_confirm_action'] );
		if ( ! $confirmed ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, $action, 'warning', 'Tick the confirmation before this action can start.' );
			return;
		}

		$rate = RW_Portal_Customer_Actions::check_rate_limit( $user_id, (int) $site->id, $action );
		if ( is_wp_error( $rate ) ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, $action, 'rate_limited', RW_Portal_Customer_Actions::rate_limit_message() );
			return;
		}

		$report_id = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		$backup    = isset( $_POST['backup_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_ref'] ) ) : '';
		if ( 'report_rollback' === $action ) {
			self::handle_rollback( $user_id, $site, $report_id, $backup );
			return;
		}
		if ( 'report_support' === $action ) {
			self::handle_support( $user_id, $site, $report_id );
			return;
		}
		if ( 'report_self_fix' === $action ) {
			self::handle_self_fix( $user_id, $site, $report_id );
		}
	}

	/**
	 * @param object $site
	 * @param int    $report_id
	 * @param string $backup_ref
	 * @param bool   $confirmed
	 * @return array<string,mixed>|WP_Error
	 */
	public static function rollback_request_body( $site, $report_id, $backup_ref, $confirmed ) {
		if ( ! $confirmed ) {
			return new WP_Error( 'rw_report_confirm', 'Tick the confirmation before a rollback can start.', array( 'status' => 400 ) );
		}
		if ( (int) $report_id <= 0 ) {
			return new WP_Error( 'rw_report_invalid', 'Choose the report this rollback belongs to.', array( 'status' => 400 ) );
		}

		return array(
			'portal_site_id'  => (int) $site->id,
			'subscription_id' => isset( $site->subscription_id ) ? (int) $site->subscription_id : 0,
			'report_id'       => (int) $report_id,
			'action'          => 'rollback',
			'backup_ref'      => sanitize_text_field( (string) $backup_ref ),
			'confirmed'       => 1,
		);
	}

	/**
	 * @param string $backup_ref
	 * @return string|WP_Error
	 */
	public static function restore_body( $backup_ref ) {
		$backup_ref = trim( (string) $backup_ref );
		if ( 1 !== preg_match( self::BACKUP_REF_PATTERN, $backup_ref ) ) {
			return new WP_Error( 'rw_report_backup', 'This report has no snapshot reference, so rollback is not available.', array( 'status' => 409 ) );
		}

		return (string) wp_json_encode(
			array(
				'backup_ref' => $backup_ref,
				'confirm'    => true,
			)
		);
	}

	/**
	 * @param int    $user_id
	 * @param int    $subscription_id
	 * @param int    $managed_site_id
	 * @param string $service_host
	 * @param string $plan_host
	 * @param int    $report_id
	 * @return array<string,mixed>|WP_Error
	 */
	public static function support_request_fields( $user_id, $subscription_id, $managed_site_id, $service_host, $plan_host, $report_id ) {
		$host = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::checkout_host( $service_host ) : '';
		$plan = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::host_key( $plan_host ) : '';
		if ( '' === $host || (int) $user_id <= 0 || (int) $subscription_id <= 0 || (int) $managed_site_id <= 0 ) {
			return new WP_Error( 'rw_report_support', 'A support request needs the website on this plan.', array( 'status' => 400 ) );
		}
		$summary = 'Support request for ' . $host . ' on this maintenance plan.';
		if ( '' !== $plan && $plan !== $host ) {
			$summary .= ' The plan website recorded on the subscription is ' . $plan . '.';
		}
		if ( strlen( $summary ) > 240 ) {
			$summary = substr( $summary, 0, 240 );
		}

		return array(
			'user_id'         => (int) $user_id,
			'subscription_id' => (int) $subscription_id,
			'managed_site_id' => (int) $managed_site_id,
			'report_id'       => (int) $report_id > 0 ? (int) $report_id : 0,
			'service_host'    => $host,
			'plan_host'       => $plan,
			'status'          => 'open',
			'summary'         => $summary,
		);
	}

	/**
	 * @return array{sent:int}
	 */
	public static function send_due_notices() {
		$settings = self::email_settings();
		if ( empty( $settings['enabled'] ) || 'portal' !== $settings['sender'] ) {
			return array( 'sent' => 0 );
		}
		$result = RW_Maint_Client::post_signed(
			'maintenance-report-notices',
			array(
				'sender' => 'portal',
				'limit'  => 20,
			)
		);
		if ( is_wp_error( $result ) || empty( $result['notices'] ) || ! is_array( $result['notices'] ) ) {
			return array( 'sent' => 0 );
		}
		$sent = array();
		foreach ( $result['notices'] as $notice ) {
			if ( ! is_array( $notice ) || empty( $notice['id'] ) || empty( $notice['recipient'] ) ) {
				continue;
			}
			$recipients = class_exists( 'RW_Portal_Customer_Actions' )
				? RW_Portal_Customer_Actions::parse_emails( (string) $notice['recipient'] )
				: array();
			if ( empty( $recipients ) ) {
				continue;
			}
			$ok = wp_mail( $recipients, (string) $notice['subject'], (string) $notice['body'] );
			if ( $ok ) {
				$sent[] = (int) $notice['id'];
			}
		}
		if ( ! empty( $sent ) ) {
			RW_Maint_Client::post_signed(
				'maintenance-report-notices',
				array(
					'complete' => 1,
					'status'   => 'sent',
					'ids'      => $sent,
				)
			);
		}

		return array( 'sent' => count( $sent ) );
	}

	/**
	 * @param int    $user_id
	 * @param object $site
	 * @param int    $report_id
	 * @param string $backup_ref
	 * @return void
	 */
	private static function handle_rollback( $user_id, $site, $report_id, $backup_ref ) {
		$body = self::rollback_request_body( $site, $report_id, $backup_ref, true );
		if ( is_wp_error( $body ) ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_rollback', 'warning', $body->get_error_message() );
			return;
		}
		$decision = RW_Maint_Client::post_signed( 'maintenance-report-action', $body );
		if ( is_wp_error( $decision ) || empty( $decision['backup_ref'] ) || 'accepted' !== ( isset( $decision['status'] ) ? (string) $decision['status'] : '' ) ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_rollback', 'warning', 'Rollback was not started because the stored snapshot reference could not be checked.' );
			return;
		}
		$restore = self::restore_body( $decision['backup_ref'] );
		if ( is_wp_error( $restore ) ) {
			self::record_outcome( $site, $report_id, 'failed', 'Snapshot reference was not valid.' );
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_rollback', 'warning', $restore->get_error_message() );
			return;
		}
		$secret = class_exists( 'RW_Tokens' ) ? RW_Tokens::get_site_secret( (int) $site->id ) : '';
		if ( ! is_string( $secret ) || '' === $secret ) {
			self::record_outcome( $site, $report_id, 'failed', 'The website has not finished enrolment.' );
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_rollback', 'warning', 'Rollback was not started because this website has not finished enrolment.' );
			return;
		}
		$result = RW_WordPress_Client::connector_command( (string) $site->site_url, self::RESTORE_ROUTE, $restore, (int) $site->id, $secret, 120 );
		RW_Portal_Customer_Actions::bump_rate_limit( $user_id, (int) $site->id, 'report_rollback' );
		self::forget_cache( (int) $site->id );
		if ( is_wp_error( $result ) ) {
			self::record_outcome( $site, $report_id, 'failed', 'The website could not start the restore.' );
			$message = 'rw_connector_route_missing' === $result->get_error_code()
				? 'The snapshot reference is saved, but this website\'s connector cannot restore it yet. The rollback was not applied.'
				: 'The rollback was not applied. The website could not start the restore.';
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_rollback', 'warning', $message );
			return;
		}
		$restored = is_array( $result ) && ! empty( $result['restored'] );
		self::record_outcome( $site, $report_id, $restored ? 'restored' : 'started', '' );
		$ref = (string) $decision['backup_ref'];
		RW_Portal_Customer_Actions::set_status_notice(
			$user_id,
			'success',
			'Rollback',
			$restored
				? 'The website reported that snapshot ' . $ref . ' was restored.'
				: 'The website accepted the restore request for ' . $ref . '. Completion has not been confirmed from this page.'
		);
	}

	/**
	 * @param int    $user_id
	 * @param object $site
	 * @param int    $report_id
	 * @return void
	 */
	private static function handle_support( $user_id, $site, $report_id ) {
		$plan_host = '';
		if ( class_exists( 'RW_Support_Sites' ) && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( (int) $site->subscription_id );
			if ( $subscription ) {
				$plan      = RW_Support_Sites::plan_site( $subscription );
				$plan_host = isset( $plan['host'] ) ? (string) $plan['host'] : '';
			}
		}
		$fields = self::support_request_fields(
			$user_id,
			(int) $site->subscription_id,
			(int) $site->id,
			isset( $site->site_url ) ? (string) $site->site_url : '',
			$plan_host,
			$report_id
		);
		if ( is_wp_error( $fields ) ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_support', 'warning', $fields->get_error_message() );
			return;
		}
		$stored = self::insert_support_request( $fields );
		if ( ! $stored ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_support', 'warning', 'The support request could not be recorded.' );
			return;
		}
		if ( $report_id > 0 ) {
			RW_Maint_Client::post_signed(
				'maintenance-report-action',
				array(
					'portal_site_id'  => (int) $site->id,
					'subscription_id' => (int) $site->subscription_id,
					'report_id'       => $report_id,
					'action'          => 'support',
					'confirmed'       => 1,
					'detail'          => 'Support request ' . $stored . ' for ' . $fields['service_host'],
				)
			);
		}
		RW_Portal_Customer_Actions::bump_rate_limit( $user_id, (int) $site->id, 'report_support' );
		self::forget_cache( (int) $site->id );
		if ( class_exists( 'RW_Audit' ) ) {
			RW_Audit::log(
				'customer_report_support',
				array(
					'user_id'         => $user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'request_id'      => $stored,
					'service_host'    => $fields['service_host'],
				)
			);
		}
		RW_Portal_Customer_Actions::set_status_notice(
			$user_id,
			'success',
			'Support',
			'Support request recorded for ' . $fields['service_host'] . '. It is tied to this website and does not start work by itself.'
		);
	}

	/**
	 * @param int    $user_id
	 * @param object $site
	 * @param int    $report_id
	 * @return void
	 */
	private static function handle_self_fix( $user_id, $site, $report_id ) {
		if ( $report_id <= 0 ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_self_fix', 'warning', 'Choose the report this choice belongs to.' );
			return;
		}
		$result = RW_Maint_Client::post_signed(
			'maintenance-report-action',
			array(
				'portal_site_id'  => (int) $site->id,
				'subscription_id' => (int) $site->subscription_id,
				'report_id'       => $report_id,
				'action'          => 'self_fix',
				'confirmed'       => 1,
				'detail'          => 'Customer will fix the failed pages on the website.',
			)
		);
		if ( is_wp_error( $result ) ) {
			RW_Portal_Customer_Actions::set_card_notice( $user_id, 'report_self_fix', 'warning', 'That choice could not be recorded.' );
			return;
		}
		RW_Portal_Customer_Actions::bump_rate_limit( $user_id, (int) $site->id, 'report_self_fix' );
		self::forget_cache( (int) $site->id );
		RW_Portal_Customer_Actions::set_status_notice(
			$user_id,
			'success',
			'Recorded',
			'Recorded that you will fix the failed pages on the website. Nothing was changed from this page.'
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return int
	 */
	private static function insert_support_request( array $fields ) {
		global $wpdb;

		$table = RW_DB::table( 'support_requests' );
		$ok    = $wpdb->insert(
			$table,
			array(
				'user_id'         => (int) $fields['user_id'],
				'subscription_id' => (int) $fields['subscription_id'],
				'managed_site_id' => (int) $fields['managed_site_id'],
				'report_id'       => (int) $fields['report_id'],
				'service_host'    => (string) $fields['service_host'],
				'plan_host'       => (string) $fields['plan_host'],
				'status'          => 'open',
				'summary'         => (string) $fields['summary'],
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param object $site
	 * @param int    $report_id
	 * @param string $outcome
	 * @param string $detail
	 * @return void
	 */
	private static function record_outcome( $site, $report_id, $outcome, $detail ) {
		RW_Maint_Client::post_signed(
			'maintenance-report-action',
			array(
				'portal_site_id'  => (int) $site->id,
				'subscription_id' => (int) $site->subscription_id,
				'report_id'       => (int) $report_id,
				'action'          => 'rollback',
				'outcome'         => $outcome,
				'detail'          => $detail,
				'confirmed'       => 1,
			)
		);
	}

	/**
	 * @param int $site_id
	 * @return void
	 */
	private static function forget_cache( $site_id ) {
		delete_transient( 'rw_portal_update_reports_' . (int) $site_id );
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
}
