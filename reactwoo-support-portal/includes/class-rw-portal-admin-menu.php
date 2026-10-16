<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Admin_Menu {
	const PARENT_SLUG = 'rw-support-portal';

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 9 );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'Support Portal', 'reactwoo-support-portal' ),
			__( 'Support Portal', 'reactwoo-support-portal' ),
			'manage_options',
			self::PARENT_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-portfolio',
			30
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Support Portal', 'reactwoo-support-portal' ),
			__( 'Dashboard', 'reactwoo-support-portal' ),
			'manage_options',
			self::PARENT_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sites_url = admin_url( 'admin.php?page=' . RW_Portal_Sites_Admin::MENU_SLUG );
		$audit_url = admin_url( 'admin.php?page=' . RW_Portal_Audit_Admin::MENU_SLUG );
		$settings_url = admin_url( 'admin.php?page=rw-portal-settings' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Support Portal', 'reactwoo-support-portal' ); ?></h1>
			<p><?php esc_html_e( 'Manage sites, view the audit log, and configure the maintenance hub connection.', 'reactwoo-support-portal' ); ?></p>
			<div class="rw-portal-dashboard-cards" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; margin-top: 1.5rem;">
				<div class="rw-portal-card" style="border: 1px solid #c3c4c7; padding: 1.25rem; border-radius: 4px;">
					<h2 style="margin-top: 0; font-size: 1rem;"><a href="<?php echo esc_url( $sites_url ); ?>"><?php esc_html_e( 'Managed Sites', 'reactwoo-support-portal' ); ?></a></h2>
					<p style="margin-bottom: 0; color: #646970;"><?php esc_html_e( 'View and manage enrolled sites.', 'reactwoo-support-portal' ); ?></p>
				</div>
				<div class="rw-portal-card" style="border: 1px solid #c3c4c7; padding: 1.25rem; border-radius: 4px;">
					<h2 style="margin-top: 0; font-size: 1rem;"><a href="<?php echo esc_url( $audit_url ); ?>"><?php esc_html_e( 'Audit Log', 'reactwoo-support-portal' ); ?></a></h2>
					<p style="margin-bottom: 0; color: #646970;"><?php esc_html_e( 'Recent portal and enrollment events.', 'reactwoo-support-portal' ); ?></p>
				</div>
				<div class="rw-portal-card" style="border: 1px solid #c3c4c7; padding: 1.25rem; border-radius: 4px;">
					<h2 style="margin-top: 0; font-size: 1rem;"><a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'reactwoo-support-portal' ); ?></a></h2>
					<p style="margin-bottom: 0; color: #646970;"><?php esc_html_e( 'Maintenance hub URL and shared secret.', 'reactwoo-support-portal' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
