<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Settings {
	const OPTION_URL    = 'rw_portal_maint_url';
	const OPTION_SECRET = 'rw_portal_maint_secret';
	const OPTION_STALE_HOURS = 'rw_portal_stale_hours';
	const OPTION_ENROLL_STRICT = 'rw_portal_enforce_site_url';

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_rw_portal_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/**
	 * AJAX: test connection to the maintenance hub. Requires saved URL and secret (uses current form is not submitted yet).
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'rw_portal_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'reactwoo-support-portal' ) ), 403 );
		}
		$result = RW_Maint_Client::test_connection();
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] === 401 ) {
				$msg = __( 'Connection refused. The shared secret does not match the one in MainWP Bridge. Generate in the bridge and paste the same value here.', 'reactwoo-support-portal' );
			} elseif ( $result->get_error_code() === 'rw_maint_url_missing' ) {
				$msg = __( 'Maintenance hub URL is not set. Enter the URL above and save.', 'reactwoo-support-portal' );
			} elseif ( $result->get_error_code() === 'rw_maint_secret_missing' ) {
				$msg = __( 'Shared secret is not set. Paste the secret from MainWP Bridge and save.', 'reactwoo-support-portal' );
			}
			wp_send_json_error( array( 'message' => $msg ) );
		}
		wp_send_json_success( array( 'message' => __( 'Connection successful. Shared secret is correct.', 'reactwoo-support-portal' ) ) );
	}

	public static function add_menu() {
		add_submenu_page(
			RW_Portal_Admin_Menu::PARENT_SLUG,
			__( 'ReactWoo Portal Settings', 'reactwoo-support-portal' ),
			__( 'Settings', 'reactwoo-support-portal' ),
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
				'sanitize_callback' => array( __CLASS__, 'sanitize_secret' ),
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

		register_setting(
			'rw_portal_settings',
			self::OPTION_ENROLL_STRICT,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_flag' ),
				'default'           => 0,
			)
		);

		add_settings_field(
			self::OPTION_ENROLL_STRICT,
			'Require Site URL Match',
			array( __CLASS__, 'render_enroll_strict_field' ),
			'rw-portal-settings',
			'rw_portal_maint_section'
		);

		register_setting(
			'rw_portal_settings',
			self::OPTION_STALE_HOURS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_stale_hours' ),
				'default'           => 24,
			)
		);

		add_settings_field(
			self::OPTION_STALE_HOURS,
			'Stale Heartbeat Threshold (hours)',
			array( __CLASS__, 'render_stale_hours_field' ),
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
		$nonce = wp_create_nonce( 'rw_portal_test_connection' );
		?>
		<p>
			<button type="button" class="button" id="rw-portal-test-connection" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Test connection', 'reactwoo-support-portal' ); ?></button>
			<span id="rw-portal-test-result" style="margin-left: 8px;"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Save your settings above, then click to verify that the hub URL and shared secret are correct.', 'reactwoo-support-portal' ); ?></p>
		<script>
		(function() {
			var btn = document.getElementById('rw-portal-test-connection');
			var result = document.getElementById('rw-portal-test-result');
			if (!btn || !result) return;
			btn.addEventListener('click', function() {
				var nonce = btn.getAttribute('data-nonce');
				result.textContent = '';
				result.className = '';
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Testing…', 'reactwoo-support-portal' ) ); ?>';
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=rw_portal_test_connection&nonce=' + encodeURIComponent(nonce)
				}).then(function(r) { return r.json(); }).then(function(data) {
					if (data.success && data.data && data.data.message) {
						result.textContent = data.data.message;
						result.style.color = '#00a32a';
					} else {
						result.textContent = (data.data && data.data.message) ? data.data.message : (data.message || '<?php echo esc_js( __( 'Connection failed.', 'reactwoo-support-portal' ) ); ?>');
						result.style.color = '#d63638';
					}
				}).catch(function() {
					result.textContent = '<?php echo esc_js( __( 'Request failed.', 'reactwoo-support-portal' ) ); ?>';
					result.style.color = '#d63638';
				}).finally(function() {
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Test connection', 'reactwoo-support-portal' ) ); ?>';
				});
			});
		})();
		</script>
		<?php
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
		$id = 'rw-portal-maint-secret';

		printf(
			'<input type="password" id="%s" name="%s" class="regular-text" value="%s" autocomplete="new-password" />',
			esc_attr( $id ),
			esc_attr( self::OPTION_SECRET ),
			esc_attr( $value )
		);
		echo ' <button type="button" class="button" id="rw-portal-copy-secret">' . esc_html__( 'Copy', 'reactwoo-support-portal' ) . '</button>';
		echo '<p class="description"><strong>Do not generate here.</strong> Generate the secret in <strong>MainWP Bridge</strong>: on the site where the bridge is installed, go to Settings → MainWP Bridge → Shared Secret, click <strong>Generate</strong>, then copy and paste the value here.</p>';
		?>
		<script>
		(function() {
			var input = document.getElementById('<?php echo esc_js( $id ); ?>');
			var copyBtn = document.getElementById('rw-portal-copy-secret');
			if (input && copyBtn) {
				copyBtn.addEventListener('click', function() {
					input.select();
					input.setSelectionRange(0, 99999);
					try {
						document.execCommand('copy');
						copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'reactwoo-support-portal' ) ); ?>';
						setTimeout(function() { copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'reactwoo-support-portal' ) ); ?>'; }, 2000);
					} catch (e) {}
				});
			}
		})();
		</script>
		<?php
	}

	public static function render_stale_hours_field() {
		$value = (int) get_option( self::OPTION_STALE_HOURS, 24 );

		printf(
			'<input type="number" name="%s" class="small-text" value="%d" min="1" max="168" />',
			esc_attr( self::OPTION_STALE_HOURS ),
			(int) $value
		);
		echo '<p class="description">Controls when a site is marked stale in the dashboard widget.</p>';
	}

	public static function render_enroll_strict_field() {
		$value = (int) get_option( self::OPTION_ENROLL_STRICT, 0 );

		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> Enforce enrollment URL match</label>',
			esc_attr( self::OPTION_ENROLL_STRICT ),
			checked( 1, $value, false )
		);
		echo '<p class="description">If enabled, enrollment tokens require the connector to report a matching site URL.</p>';
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

	public static function sanitize_stale_hours( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			return 24;
		}

		return min( 168, $value );
	}

	public static function sanitize_flag( $value ) {
		return (int) (bool) $value;
	}

	private static function is_sha256( $value ) {
		return 64 === strlen( $value ) && ctype_xdigit( $value );
	}
}
