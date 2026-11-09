<?php
/**
 * Scheduled report emails from the maintenance hub.
 *
 * Mail stays off until the setting is enabled. This class only sends notices
 * whose stored sender is the hub. Portal-sender notices are left for reactwoo.com.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Report_Mail {
	const HOOK                 = 'rw_maint_report_notices';
	const OPTION_ENABLED       = 'rw_maint_report_emails_enabled';
	const OPTION_SENDER        = 'rw_maint_report_email_sender';
	const OPTION_NOTICE_HOURS  = 'rw_maint_report_notice_hours';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'send_due' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * @return array{enabled:bool,sender:string,notice_hours:int}
	 */
	public static function settings() {
		return RW_Maint_Report::normalise_settings(
			get_option( self::OPTION_ENABLED, 0 ),
			get_option( self::OPTION_SENDER, 'portal' ),
			get_option( self::OPTION_NOTICE_HOURS, RW_Maint_Report::NOTICE_DEFAULT )
		);
	}

	/**
	 * @return array{sent:int,expired:int}
	 */
	public static function send_due() {
		$settings = self::settings();
		$now      = time();
		RW_Maint_Report_Store::expire_before( $now );
		if ( empty( $settings['enabled'] ) || 'maint' !== $settings['sender'] ) {
			return array(
				'sent'    => 0,
				'expired' => 0,
			);
		}

		return array(
			'sent'    => self::deliver( RW_Maint_Report_Store::due_notices( 'maint', $now, 20 ) ),
			'expired' => 0,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $notices
	 * @return int
	 */
	public static function deliver( array $notices ) {
		$sent = array();
		foreach ( $notices as $notice ) {
			$decision = RW_Maint_Report::notice_decision(
				array(
					'enabled' => true,
					'sender'  => 'maint',
				),
				$notice,
				time()
			);
			if ( 'send' !== $decision ) {
				continue;
			}
			$recipients = self::split_recipients( isset( $notice['recipient'] ) ? $notice['recipient'] : '' );
			if ( empty( $recipients ) ) {
				continue;
			}
			$ok = wp_mail( $recipients, (string) $notice['subject'], (string) $notice['body'] );
			if ( $ok ) {
				$sent[] = (int) $notice['id'];
			}
		}
		if ( ! empty( $sent ) ) {
			RW_Maint_Report_Store::complete_notices( $sent, 'sent' );
		}

		return count( $sent );
	}

	/**
	 * @param string $recipient
	 * @return string[]
	 */
	private static function split_recipients( $recipient ) {
		$parts = preg_split( '/[\s,;]+/', (string) $recipient );
		$clean = array();
		foreach ( (array) $parts as $email ) {
			$email = sanitize_email( $email );
			if ( $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		return $clean;
	}
}
