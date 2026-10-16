<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maintenance_Emails {
	const REMINDER_HOOK = 'rw_portal_connection_reminders';

	public static function register() {
		add_action( self::REMINDER_HOOK, array( __CLASS__, 'send_reminders' ) );
		if ( ! wp_next_scheduled( self::REMINDER_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REMINDER_HOOK );
		}
	}

	public static function maybe_send_welcome( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_user_id' ) ) {
			return;
		}

		$user_id = (int) $subscription->get_user_id();
		if ( ! $user_id || get_user_meta( $user_id, 'rw_maint_welcome_sent', true ) ) {
			return;
		}

		$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
		if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$minutes = isset( $entitlement['technical_support_minutes_monthly'] )
			? (int) $entitlement['technical_support_minutes_monthly']
			: (int) $entitlement['technical_support_minutes'];
		$sent    = wp_mail(
			$user->user_email,
			'Your ReactWoo Maintenance plan is ready',
			"Your ReactWoo Maintenance plan is ready.\n\nFrom your account, choose Connect WordPress. You approve access in WordPress. ReactWoo does not ask for your normal WordPress password.\n\n" . self::account_url() . "\n\nIncluded technical support: {$minutes} minutes each month of your plan.\n"
		);

		if ( $sent ) {
			update_user_meta( $user_id, 'rw_maint_welcome_sent', time() );
		}
	}

	public static function send_connected( $site ) {
		$email = self::recipient( $site );
		if ( '' === $email || ! $site ) {
			return;
		}

		wp_mail(
			$email,
			'Your website is now connected',
			sprintf(
				"Your website is now connected to ReactWoo Maintenance.\n\nWebsite: %s\n\nYou can view status, backups and reports from your account:\n%s\n",
				$site->site_url,
				self::account_url()
			)
		);
	}

	public static function send_action_required( $site, $message ) {
		$email = self::recipient( $site );
		if ( '' === $email || ! $site ) {
			return;
		}

		wp_mail(
			$email,
			'Finish connecting your website',
			"Finish connecting your website to ReactWoo Maintenance.\n\n" . sanitize_text_field( (string) $message ) . "\n\n" . self::account_url() . "\n"
		);
	}

	public static function send_reminders() {
		global $wpdb;

		$table = RW_DB::table( 'managed_sites' );
		if ( ! RW_DB::table_exists( $table ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE connection_method = %s
					AND connection_state != %s
					AND status != %s
					AND created_at <= %s
				LIMIT 50",
				'v2',
				RW_Connection_State::CONNECTED,
				'disconnected',
				$cutoff
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $site ) {
			$age = time() - strtotime( (string) $site->created_at . ' UTC' );
			$day = $age >= ( 3 * DAY_IN_SECONDS ) ? '3d' : '24h';
			if ( '24h' === $day && $age < DAY_IN_SECONDS ) {
				continue;
			}
			$meta = 'rw_maint_reminder_' . $day . '_' . (int) $site->id;
			if ( get_user_meta( (int) $site->user_id, $meta, true ) ) {
				continue;
			}

			$email = self::recipient( $site );
			if ( '' === $email ) {
				continue;
			}

			$sent = wp_mail(
				$email,
				'Finish connecting your website',
				"Your ReactWoo Maintenance setup is still waiting for the website connection.\n\n" . self::account_url() . "\n"
			);
			if ( $sent ) {
				update_user_meta( (int) $site->user_id, $meta, time() );
			}
		}
	}

	private static function recipient( $site ) {
		if ( ! $site ) {
			return '';
		}
		if ( ! empty( $site->report_email ) && is_email( $site->report_email ) ) {
			return $site->report_email;
		}
		if ( ! empty( $site->client_email ) && is_email( $site->client_email ) ) {
			return $site->client_email;
		}
		$user = get_userdata( (int) $site->user_id );

		return ( $user && is_email( $user->user_email ) ) ? $user->user_email : '';
	}

	private static function account_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'maintenance' );
		}

		return home_url( '/' );
	}
}
