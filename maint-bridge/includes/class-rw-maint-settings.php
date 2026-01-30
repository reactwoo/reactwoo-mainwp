<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_Settings {
	const OPTION_PORTAL_SECRET = 'rw_maint_portal_secret';
	const OPTION_MAINWP_URL    = 'rw_maint_mainwp_url';
	const OPTION_MAINWP_KEY    = 'rw_maint_mainwp_key';
	const OPTION_MAINWP_SECRET = 'rw_maint_mainwp_secret';
	const OPTION_MAINWP_AUTH   = 'rw_maint_mainwp_auth';

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_menu() {
		add_options_page(
			'ReactWoo Maintenance',
			'ReactWoo Maintenance',
			'manage_options',
			'rw-maint-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		register_setting(
			'rw_maint_settings',
			self::OPTION_PORTAL_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_secret' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'rw_maint_portal_section',
			'Portal Authentication',
			array( __CLASS__, 'render_section' ),
			'rw-maint-settings'
		);

		add_settings_field(
			self::OPTION_PORTAL_SECRET,
			'Shared Secret',
			array( __CLASS__, 'render_secret_field' ),
			'rw-maint-settings',
			'rw_maint_portal_section'
		);

		add_settings_section(
			'rw_maint_mainwp_section',
			'MainWP REST API',
			array( __CLASS__, 'render_mainwp_section' ),
			'rw-maint-settings'
		);

		register_setting(
			'rw_maint_settings',
			self::OPTION_MAINWP_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'rw_maint_settings',
			self::OPTION_MAINWP_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'rw_maint_settings',
			self::OPTION_MAINWP_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'rw_maint_settings',
			self::OPTION_MAINWP_AUTH,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_mainwp_auth' ),
				'default'           => 'basic',
			)
		);

		add_settings_field(
			self::OPTION_MAINWP_URL,
			'API Base URL',
			array( __CLASS__, 'render_mainwp_url' ),
			'rw-maint-settings',
			'rw_maint_mainwp_section'
		);

		add_settings_field(
			self::OPTION_MAINWP_KEY,
			'API Key',
			array( __CLASS__, 'render_mainwp_key' ),
			'rw-maint-settings',
			'rw_maint_mainwp_section'
		);

		add_settings_field(
			self::OPTION_MAINWP_SECRET,
			'API Secret',
			array( __CLASS__, 'render_mainwp_secret' ),
			'rw-maint-settings',
			'rw_maint_mainwp_section'
		);

		add_settings_field(
			self::OPTION_MAINWP_AUTH,
			'Auth Mode',
			array( __CLASS__, 'render_mainwp_auth' ),
			'rw-maint-settings',
			'rw_maint_mainwp_section'
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1>ReactWoo Maintenance Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'rw_maint_settings' );
				do_settings_sections( 'rw-maint-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function render_section() {
		echo '<p>Configure the shared secret used to validate portal requests.</p>';
	}

	public static function render_secret_field() {
		$value = (string) get_option( self::OPTION_PORTAL_SECRET, '' );

		printf(
			'<input type="password" name="%s" class="regular-text" value="%s" autocomplete="new-password" />',
			esc_attr( self::OPTION_PORTAL_SECRET ),
			esc_attr( $value )
		);
		echo '<p class="description">Use the same secret configured on the portal.</p>';
	}

	public static function render_mainwp_section() {
		echo '<p>Configure MainWP REST API access. Prefer filters or constants for secrets.</p>';
	}

	public static function render_mainwp_url() {
		$value = esc_url( get_option( self::OPTION_MAINWP_URL, '' ) );

		printf(
			'<input type="url" name="%s" class="regular-text" value="%s" placeholder="https://maint.reactwoo.com" />',
			esc_attr( self::OPTION_MAINWP_URL ),
			esc_attr( $value )
		);
	}

	public static function render_mainwp_key() {
		$value = (string) get_option( self::OPTION_MAINWP_KEY, '' );

		printf(
			'<input type="text" name="%s" class="regular-text" value="%s" autocomplete="off" />',
			esc_attr( self::OPTION_MAINWP_KEY ),
			esc_attr( $value )
		);
	}

	public static function render_mainwp_secret() {
		$value = (string) get_option( self::OPTION_MAINWP_SECRET, '' );

		printf(
			'<input type="password" name="%s" class="regular-text" value="%s" autocomplete="new-password" />',
			esc_attr( self::OPTION_MAINWP_SECRET ),
			esc_attr( $value )
		);
		echo '<p class="description">Used to authenticate MainWP REST requests.</p>';
	}

	public static function render_mainwp_auth() {
		$value = (string) get_option( self::OPTION_MAINWP_AUTH, 'basic' );

		echo '<select name="' . esc_attr( self::OPTION_MAINWP_AUTH ) . '">';
		echo '<option value="basic"' . selected( $value, 'basic', false ) . '>Basic (Authorization header)</option>';
		echo '<option value="query"' . selected( $value, 'query', false ) . '>Query Params</option>';
		echo '</select>';
		echo '<p class="description">Use filters to customize auth headers or query parameter names.</p>';
	}

	public static function sanitize_mainwp_auth( $value ) {
		$value = sanitize_text_field( $value );
		return in_array( $value, array( 'basic', 'query' ), true ) ? $value : 'basic';
	}

	public static function sanitize_secret( $value ) {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( self::is_sha256( $value ) ) {
			return strtolower( $value );
		}

		return hash( 'sha256', $value );
	}

	private static function is_sha256( $value ) {
		return 64 === strlen( $value ) && ctype_xdigit( $value );
	}
}
