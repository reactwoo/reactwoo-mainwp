<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Dashboard {
	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widgets' ) );
	}

	public static function register_widgets() {
		wp_add_dashboard_widget(
			'rw_maint_recent_audit',
			'ReactWoo Maintenance Recent Audit Events',
			array( __CLASS__, 'render_recent_audit' )
		);
	}

	public static function render_recent_audit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>Insufficient permissions.</p>';
			return;
		}

		$events = RW_Maint_Audit::get_recent( 8 );
		if ( empty( $events ) ) {
			echo '<p>No recent events.</p>';
			return;
		}

		echo '<ul>';
		foreach ( $events as $event ) {
			echo '<li>' . esc_html( $event->event_type ) . ' (' . esc_html( $event->created_at ) . ')</li>';
		}
		echo '</ul>';

		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . RW_Maint_Audit_Admin::MENU_SLUG ) ) . '">View audit log</a></p>';
	}
}
