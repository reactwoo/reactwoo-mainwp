<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Dashboard {
	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widgets' ) );
	}

	public static function register_widgets() {
		wp_add_dashboard_widget(
			'rw_portal_stale_sites',
			'ReactWoo Stale Sites',
			array( __CLASS__, 'render_stale_sites' )
		);

		wp_add_dashboard_widget(
			'rw_portal_recent_audit',
			'ReactWoo Recent Audit Events',
			array( __CLASS__, 'render_recent_audit' )
		);
	}

	public static function render_stale_sites() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>Insufficient permissions.</p>';
			return;
		}

		$hours = (int) get_option( RW_Portal_Settings::OPTION_STALE_HOURS, 24 );
		$seconds = max( 1, $hours ) * HOUR_IN_SECONDS;
		$stale = RW_Sites::get_stale_sites( $seconds );
		if ( empty( $stale ) ) {
			echo '<p>No stale sites detected.</p>';
			return;
		}

		echo '<ul>';
		foreach ( $stale as $site ) {
			echo '<li>' . esc_html( $site->site_name ) . ' (' . esc_html( $site->site_url ) . ')</li>';
		}
		echo '</ul>';

		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . RW_Portal_Audit_Admin::MENU_SLUG ) ) . '">View audit log</a></p>';
	}

	public static function render_recent_audit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>Insufficient permissions.</p>';
			return;
		}

		$events = RW_Audit::get_recent( 8 );
		if ( empty( $events ) ) {
			echo '<p>No recent events.</p>';
			return;
		}

		echo '<ul>';
		foreach ( $events as $event ) {
			echo '<li>' . esc_html( $event->event_type ) . ' (' . esc_html( $event->created_at ) . ')</li>';
		}
		echo '</ul>';

		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . RW_Portal_Audit_Admin::MENU_SLUG ) ) . '">View audit log</a></p>';
	}
}
