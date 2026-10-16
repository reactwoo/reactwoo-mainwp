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
		add_action( 'wp_ajax_rw_maint_generate_secret', array( __CLASS__, 'ajax_generate_secret' ) );
	}

	/**
	 * AJAX: generate a new shared secret (for portal auth). Capability and nonce checked.
	 */
	public static function ajax_generate_secret() {
		check_ajax_referer( 'rw_maint_generate_secret', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$secret = self::generate_shared_secret();
		wp_send_json_success( array( 'secret' => $secret ) );
	}

	/**
	 * Generate a cryptographically secure shared secret (64-char hex).
	 *
	 * @return string
	 */
	public static function generate_shared_secret() {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( 32 ) );
		}
		return bin2hex( hash( 'sha256', wp_generate_password( 32, true, true ) . microtime(), true ) );
	}

	public static function add_menu() {
		add_options_page(
			RW_MAINT_MENU_NAME,
			RW_MAINT_MENU_NAME,
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
				'default'           => 'bearer',
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
			<style>.rw-maint-tooltip { cursor: help; }</style>
			<h1><?php echo esc_html( RW_MAINT_MENU_NAME ); ?> Settings</h1>
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
		$value  = (string) get_option( self::OPTION_PORTAL_SECRET, '' );
		$id     = 'rw-maint-portal-secret';
		$nonce  = wp_create_nonce( 'rw_maint_generate_secret' );

		printf(
			'<input type="password" id="%s" name="%s" class="regular-text" value="%s" autocomplete="new-password" />',
			esc_attr( $id ),
			esc_attr( self::OPTION_PORTAL_SECRET ),
			esc_attr( $value )
		);
		echo ' <button type="button" class="button" id="rw-maint-generate-secret" data-nonce="' . esc_attr( $nonce ) . '">' . esc_html__( 'Generate', 'reactwoo-maint-bridge' ) . '</button>';
		echo ' <button type="button" class="button" id="rw-maint-copy-secret">' . esc_html__( 'Copy', 'reactwoo-maint-bridge' ) . '</button>';
		echo '<p class="description"><strong>Generate only here.</strong> Click Generate, save this page, then paste the same value into the Support Portal (selling site) under Support Portal → Settings → Shared Secret.</p>';
		?>
		<script>
		(function() {
			var input = document.getElementById('<?php echo esc_js( $id ); ?>');
			var genBtn = document.getElementById('rw-maint-generate-secret');
			var copyBtn = document.getElementById('rw-maint-copy-secret');
			if (!input || !genBtn) return;
			genBtn.addEventListener('click', function() {
				genBtn.disabled = true;
				genBtn.textContent = '…';
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=rw_maint_generate_secret&nonce=' + encodeURIComponent(genBtn.getAttribute('data-nonce')), {
					method: 'GET',
					credentials: 'same-origin'
				}).then(function(r) { return r.json(); }).then(function(data) {
					if (data.success && data.data && data.data.secret) {
						input.value = data.data.secret;
						input.type = 'text';
					}
				}).finally(function() {
					genBtn.disabled = false;
					genBtn.textContent = '<?php echo esc_js( __( 'Generate', 'reactwoo-maint-bridge' ) ); ?>';
				});
			});
			if (copyBtn) {
				copyBtn.addEventListener('click', function() {
					input.select();
					input.setSelectionRange(0, 99999);
					try {
						document.execCommand('copy');
						copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'reactwoo-maint-bridge' ) ); ?>';
						setTimeout(function() { copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'reactwoo-maint-bridge' ) ); ?>'; }, 2000);
					} catch (e) {}
				});
			}
		})();
		</script>
		<?php
	}

	public static function render_mainwp_section() {
		$same_site = RW_Maint_MainWP_Client::is_mainwp_same_site();
		$mainwp_available = RW_Maint_MainWP_Client::is_mainwp_rest_available();

		if ( $mainwp_available && $same_site ) {
			echo '<p><strong>MainWP is on this site.</strong> Leave "API Base URL" empty—it will use this site automatically. Use <strong>Auth Mode: Bearer token (v2)</strong> and copy the <strong>API key (Bearer token)</strong> from <strong>Dashboard → REST API Credentials</strong> in MainWP (enable the API key, then copy the token).</p>';
		} else {
			echo '<p>Configure MainWP REST API access. Use <strong>Bearer token (v2)</strong> with the API key from MainWP <strong>Dashboard → REST API Credentials</strong>. If MainWP runs on this same WordPress, leave API Base URL empty.</p>';
		}
	}

	public static function render_mainwp_url() {
		$value = (string) get_option( self::OPTION_MAINWP_URL, '' );
		$use_this_site = ( '' === $value || self::url_points_to_this_site( $value ) );

		if ( $use_this_site ) {
			$display_url = esc_url( '' === $value ? home_url() : $value );
			echo '<p class="description"><strong>This site</strong> — ' . $display_url . '</p>';
			printf(
				'<input type="hidden" name="%s" value="" />',
				esc_attr( self::OPTION_MAINWP_URL )
			);
			echo '<p class="description">MainWP is on this WordPress. No need to enter a URL.</p>';
			return;
		}

		$url_tip = __( 'Enter the full URL of your MainWP Dashboard (e.g. https://your-mainwp.com). Only needed when MainWP runs on a different server.', 'reactwoo-maint-bridge' );
		echo '<span class="rw-maint-tooltip" style="margin-right: 4px;" title="' . esc_attr( $url_tip ) . '"><span class="dashicons dashicons-editor-help" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span></span>';
		printf(
			'<input type="url" name="%s" class="regular-text" value="%s" placeholder="https://your-mainwp-dashboard.com" />',
			esc_attr( self::OPTION_MAINWP_URL ),
			esc_attr( esc_url( $value ) )
		);
	}

	/**
	 * Whether the given URL points to this WordPress (same host as home_url()).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function url_points_to_this_site( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return true;
		}
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $url_host !== false && $url_host === $home_host;
	}

	public static function render_mainwp_key() {
		$value = (string) get_option( self::OPTION_MAINWP_KEY, '' );
		$auth  = (string) get_option( self::OPTION_MAINWP_AUTH, 'bearer' );
		if ( 'bearer' === $auth ) {
			$tip = __( 'In MainWP: go to Dashboard → REST API Credentials. Enable the API key, name it (e.g. MainWP Bridge), then copy the API key (Bearer token) and paste it here. Copy it when generated—MainWP will not show it again after you leave the page.', 'reactwoo-maint-bridge' );
		} else {
			$tip = __( 'Legacy v1 only: In MainWP enable MainWP REST API v1 Compatibility, then click Show Legacy API Credentials and copy the Consumer Key here.', 'reactwoo-maint-bridge' );
		}
		echo '<span class="rw-maint-tooltip" style="margin-right: 4px;" title="' . esc_attr( $tip ) . '"><span class="dashicons dashicons-editor-help" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span></span>';
		printf(
			'<input type="text" name="%s" class="regular-text" value="%s" autocomplete="off" />',
			esc_attr( self::OPTION_MAINWP_KEY ),
			esc_attr( $value )
		);
		if ( 'bearer' === $auth ) {
			echo '<p class="description">From MainWP <strong>Dashboard → REST API Credentials</strong>: copy the <strong>API key (Bearer token)</strong> (v2).</p>';
		} elseif ( RW_Maint_MainWP_Client::is_mainwp_rest_available() ) {
			echo '<p class="description">Legacy only: use <strong>Consumer Key</strong> from MainWP → Show Legacy API Credentials.</p>';
		}
	}

	public static function render_mainwp_secret() {
		$value = (string) get_option( self::OPTION_MAINWP_SECRET, '' );
		$auth  = (string) get_option( self::OPTION_MAINWP_AUTH, 'bearer' );
		$tip   = __( 'Only for Legacy (v1): enable MainWP REST API v1 Compatibility in MainWP, click “Show Legacy API Credentials”, then copy the Consumer Secret here. Leave blank when using Bearer token (v2).', 'reactwoo-maint-bridge' );

		echo '<span class="rw-maint-tooltip" style="margin-right: 4px;" title="' . esc_attr( $tip ) . '"><span class="dashicons dashicons-editor-help" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span></span>';
		printf(
			'<input type="password" name="%s" class="regular-text" value="%s" autocomplete="new-password" />',
			esc_attr( self::OPTION_MAINWP_SECRET ),
			esc_attr( $value )
		);
		if ( 'bearer' === $auth ) {
			echo '<p class="description">Not used for Bearer token (v2). Only needed for <strong>Legacy – Consumer Key &amp; Secret (v1)</strong>.</p>';
		} elseif ( RW_Maint_MainWP_Client::is_mainwp_rest_available() ) {
			echo '<p class="description">Use <strong>Consumer Secret</strong> from MainWP Legacy API Credentials.</p>';
		} else {
			echo '<p class="description">Used to authenticate MainWP REST requests (Legacy mode).</p>';
		}
	}

	public static function render_mainwp_auth() {
		$value = (string) get_option( self::OPTION_MAINWP_AUTH, 'bearer' );

		echo '<select name="' . esc_attr( self::OPTION_MAINWP_AUTH ) . '">';
		echo '<option value="bearer"' . selected( $value, 'bearer', false ) . '>Bearer token (v2) — recommended</option>';
		echo '<option value="basic"' . selected( $value, 'basic', false ) . '>Legacy – Consumer Key &amp; Secret (v1, Basic)</option>';
		echo '<option value="query"' . selected( $value, 'query', false ) . '>Legacy – Consumer Key &amp; Secret (v1, Query)</option>';
		echo '</select>';
		echo '<p class="description">MainWP uses <strong>Bearer token (v2)</strong> by default. Use Legacy only if you have v1 compatibility enabled.</p>';
	}

	public static function sanitize_mainwp_auth( $value ) {
		$value = sanitize_text_field( $value );
		return in_array( $value, array( 'bearer', 'basic', 'query' ), true ) ? $value : 'bearer';
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
