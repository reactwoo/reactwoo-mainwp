<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Settings {
	const OPTION_URL    = 'rw_portal_maint_url';
	const OPTION_SECRET = 'rw_portal_maint_secret';

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_menu() {
		add_options_page(
			'ReactWoo Portal',
			'ReactWoo Portal',
			'manage_options',
			'rw-portal-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		register_setting(
			'rw_portal_settings',
			self::OPTION_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'rw_portal_settings',
			self::OPTION_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'rw_portal_maint_section',
			'Maintenance Hub Connection',
			array( __CLASS__, 'render_section' ),
			'rw-portal-settings'
		);

		add_settings_field(
			self::OPTION_URL,
			'Maintenance Hub URL',
			array( __CLASS__, 'render_url_field' ),
			'rw-portal-settings',
			'rw_portal_maint_section'
		);

		add_settings_field(
			self::OPTION_SECRET,
			'Shared Secret',
			array( __CLASS__, 'render_secret_field' ),
			'rw-portal-settings',
			'rw_portal_maint_section'
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1>ReactWoo Portal Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'rw_portal_settings' );
				do_settings_sections( 'rw-portal-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function render_section() {
		echo '<p>Configure the maintenance hub connection and shared secret for signed requests.</p>';
	}

	public static function render_url_field() {
		$value = esc_url( get_option( self::OPTION_URL, '' ) );

		printf(
			'<input type="url" name="%s" class="regular-text" value="%s" placeholder="https://maint.reactwoo.com" />',
			esc_attr( self::OPTION_URL ),
			esc_attr( $value )
		);
	}

	public static function render_secret_field() {
		$value = (string) get_option( self::OPTION_SECRET, '' );

		printf(
			'<input type="password" name="%s" class="regular-text" value="%s" autocomplete="new-password" />',
			esc_attr( self::OPTION_SECRET ),
			esc_attr( $value )
		);
		echo '<p class="description">Use the same shared secret configured on the maintenance hub.</p>';
	}
}
