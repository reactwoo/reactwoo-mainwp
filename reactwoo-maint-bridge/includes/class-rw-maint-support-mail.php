<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hub owned support address and notice routing.
 *
 * The portal reads this over the signed maintenance channel and returns it
 * on the site heartbeat. Visual branding stays in the White Label extension.
 */
class RW_Maint_Support_Mail {
	const OPTION = 'rw_maint_support_mail';

	public static function register() {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'support_email'          => 'support@reactwoo.com',
			'support_email_name'     => 'ReactWoo Support',
			'notify_mode'            => 'security_only',
			'notify_categories'      => array( 'core_updates', 'plugin_updates', 'theme_updates', 'auto_updates', 'wordfence', 'connector' ),
			'notify_keep_admin_copy' => true,
			'client_notify_email'    => '',
		);
	}

	/**
	 * @return string[]
	 */
	public static function modes() {
		return array( 'security_only', 'cc_all', 'cc', 'redirect', 'leave' );
	}

	/**
	 * @return array<string,string>
	 */
	public static function mode_labels() {
		return array(
			'security_only' => 'Security alerts only',
			'cc_all'        => 'CC all maintenance notices',
			'cc'            => 'CC selected categories',
			'redirect'      => 'Redirect selected categories',
			'leave'         => 'Leave site mail unchanged',
		);
	}

	/**
	 * @return string[]
	 */
	public static function routable_categories() {
		return array( 'core_updates', 'plugin_updates', 'theme_updates', 'auto_updates', 'wordfence', 'connector', 'comments' );
	}

	/**
	 * @return array<string,string>
	 */
	public static function category_labels() {
		return array(
			'core_updates'   => 'WordPress core updates',
			'plugin_updates' => 'Plugin updates',
			'theme_updates'  => 'Theme updates',
			'auto_updates'   => 'Automatic updates',
			'wordfence'      => 'Wordfence and security alerts',
			'connector'      => 'Connector notices',
			'comments'       => 'Comments',
		);
	}

	/**
	 * @param mixed $input Saved option or request body.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$mode     = isset( $input['notify_mode'] ) ? sanitize_key( (string) $input['notify_mode'] ) : $defaults['notify_mode'];
		if ( ! in_array( $mode, self::modes(), true ) ) {
			$mode = $defaults['notify_mode'];
		}

		$email = isset( $input['support_email'] ) ? self::email( $input['support_email'] ) : '';
		if ( '' === $email ) {
			$email = $defaults['support_email'];
		}

		$name = isset( $input['support_email_name'] ) ? self::name( $input['support_email_name'] ) : '';
		if ( '' === $name ) {
			$name = $defaults['support_email_name'];
		}

		$posted_categories = ( array_key_exists( 'notify_categories', $input ) && is_array( $input['notify_categories'] ) )
			|| ! empty( $input['notify_categories_present'] );
		if ( $posted_categories ) {
			$categories = array();
			$raw        = ( isset( $input['notify_categories'] ) && is_array( $input['notify_categories'] ) ) ? $input['notify_categories'] : array();
			foreach ( $raw as $category ) {
				$category = sanitize_key( (string) $category );
				if ( in_array( $category, self::routable_categories(), true ) && ! in_array( $category, $categories, true ) ) {
					$categories[] = $category;
				}
			}
		} else {
			$categories = $defaults['notify_categories'];
		}

		if ( array_key_exists( 'notify_keep_admin_copy', $input ) ) {
			$keep = self::truthy( $input['notify_keep_admin_copy'] );
		} else {
			$keep = 'redirect' !== $mode;
		}

		$client = '';
		if ( isset( $input['client_notify_email'] ) && '' !== (string) $input['client_notify_email'] ) {
			$client = self::email( $input['client_notify_email'] );
		}

		$text      = isset( $input['site_overrides_text'] ) ? (string) $input['site_overrides_text'] : '';
		$overrides = self::parse_overrides( $text );
		if ( '' === trim( $text ) && isset( $input['site_overrides'] ) && is_array( $input['site_overrides'] ) ) {
			$overrides = self::clean_overrides( $input['site_overrides'] );
		}

		return array(
			'support_email'          => $email,
			'support_email_name'     => $name,
			'notify_mode'            => $mode,
			'notify_categories'      => array_values( $categories ),
			'notify_keep_admin_copy' => $keep,
			'client_notify_email'    => $client,
			'site_overrides'         => $overrides,
			'site_overrides_text'    => self::format_overrides( $overrides ),
		);
	}

	/**
	 * Payload pushed to one site. Client notification email is added by the portal.
	 *
	 * @param int $portal_site_id Portal site id.
	 * @return array<string,mixed>
	 */
	public static function config_for_site( $portal_site_id ) {
		$stored = get_option( self::OPTION, array() );
		$clean  = self::sanitize( is_array( $stored ) ? $stored : array() );
		$config = array(
			'support_email'          => $clean['support_email'],
			'support_email_name'     => $clean['support_email_name'],
			'notify_mode'            => $clean['notify_mode'],
			'notify_categories'      => $clean['notify_categories'],
			'notify_keep_admin_copy' => $clean['notify_keep_admin_copy'],
			'client_notify_email'    => '',
		);

		$id = (int) $portal_site_id;
		if ( $id > 0 && isset( $clean['site_overrides'][ $id ] ) && is_array( $clean['site_overrides'][ $id ] ) ) {
			$row = $clean['site_overrides'][ $id ];
			if ( ! empty( $row['support_email'] ) ) {
				$config['support_email'] = $row['support_email'];
			}
			if ( ! empty( $row['notify_mode'] ) ) {
				$config['notify_mode'] = $row['notify_mode'];
				if ( 'redirect' === $row['notify_mode'] && ! array_key_exists( 'notify_keep_admin_copy', $row ) ) {
					$config['notify_keep_admin_copy'] = false;
				}
			}
		}

		return $config;
	}

	/**
	 * @param WP_REST_Request $request Signed portal request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_response( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$id     = isset( $params['portal_site_id'] ) ? (int) $params['portal_site_id'] : 0;
		if ( $id <= 0 ) {
			return new WP_Error( 'rw_maint_missing', 'Portal site ID is required.', array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'support_mail' => self::config_for_site( $id ),
			)
		);
	}

	public static function register_settings() {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			'rw_maint_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'rw_maint_support_mail_section',
			'Support email',
			array( __CLASS__, 'render_section' ),
			'rw-maint-settings'
		);

		add_settings_field(
			'rw_maint_support_email',
			'Support email',
			array( __CLASS__, 'render_fields' ),
			'rw-maint-settings',
			'rw_maint_support_mail_section'
		);

		add_settings_section(
			'rw_maint_white_label_section',
			'White Label preset',
			array( __CLASS__, 'render_preset' ),
			'rw-maint-settings'
		);
	}

	public static function render_section() {
		echo '<p>Security alerts from managed sites are copied to this address. The WordPress admin email on the client site is never changed. Password resets and other account emails stay on the site.</p>';
		echo '<p>The portal sends these values to each site on the signed heartbeat. Login logo, footer, plugin name and login CSS are entered in the MainWP White Label extension. They are not pushed from here.</p>';
	}

	public static function render_fields() {
		$stored     = get_option( self::OPTION, array() );
		$config     = self::sanitize( is_array( $stored ) ? $stored : array() );
		$labels     = self::mode_labels();
		$categories = self::category_labels();
		$name       = self::OPTION;

		echo '<p><label for="rw-maint-support-email">Address</label><br />';
		printf(
			'<input type="email" class="regular-text" id="rw-maint-support-email" name="%s[support_email]" value="%s" /></p>',
			esc_attr( $name ),
			esc_attr( $config['support_email'] )
		);
		echo '<p><label for="rw-maint-support-name">Name on the reply</label><br />';
		printf(
			'<input type="text" class="regular-text" id="rw-maint-support-name" name="%s[support_email_name]" value="%s" /></p>',
			esc_attr( $name ),
			esc_attr( $config['support_email_name'] )
		);
		echo '<p class="description">Replies use this name. The message is not sent from this address, so the client domain keeps its own mail authentication.</p>';

		echo '<p><label for="rw-maint-notify-mode">Routing</label><br />';
		echo '<select id="rw-maint-notify-mode" name="' . esc_attr( $name ) . '[notify_mode]">';
		foreach ( $labels as $mode => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $mode ),
				selected( $config['notify_mode'], $mode, false ),
				esc_html( $label )
			);
		}
		echo '</select></p>';
		echo '<p class="description">Security alerts only is the default. CC all maintenance notices copies update and connector mail as well. Redirect replaces the recipient for the selected categories.</p>';

		echo '<fieldset><legend class="screen-reader-text">Categories</legend>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[notify_categories_present]" value="1" />';
		foreach ( $categories as $key => $label ) {
			printf(
				'<label style="display:block;margin:4px 0;"><input type="checkbox" name="%s[notify_categories][]" value="%s"%s /> %s</label>',
				esc_attr( $name ),
				esc_attr( $key ),
				checked( in_array( $key, $config['notify_categories'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">Security alerts only uses Wordfence alerts and ignores the other boxes. CC all maintenance notices uses every maintenance category except comments. Account mail is never listed and is never routed.</p>';

		printf(
			'<p><label><input type="hidden" name="%1$s[notify_keep_admin_copy]" value="0" /><input type="checkbox" name="%1$s[notify_keep_admin_copy]" value="1"%2$s /> Keep a copy for the site admin</label></p>',
			esc_attr( $name ),
			checked( ! empty( $config['notify_keep_admin_copy'] ), true, false )
		);

		echo '<p><label for="rw-maint-site-overrides">Per site support address</label><br />';
		printf(
			'<textarea class="large-text code" rows="5" id="rw-maint-site-overrides" name="%s[site_overrides_text]">%s</textarea></p>',
			esc_attr( $name ),
			esc_textarea( $config['site_overrides_text'] )
		);
		echo '<p class="description">One site per line: portal site id, support email, then an optional mode. Example: 12 alerts@reactwoo.com security_only</p>';
	}

	public static function render_preset() {
		if ( ! class_exists( 'RW_Maint_White_Label_Preset' ) ) {
			return;
		}

		echo '<p>Type these into the White Label extension. Saving this screen does not send them to child sites.</p>';
		echo '<ol>';
		foreach ( RW_Maint_White_Label_Preset::manual_steps() as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol>';

		echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>Screen</th><th>Field</th><th>Value</th></tr></thead><tbody>';
		foreach ( RW_Maint_White_Label_Preset::extension_fields() as $row ) {
			$value = (string) $row['value'];
			if ( '' === $value ) {
				$value = 'Leave empty';
			}
			if ( strlen( $value ) > 180 ) {
				$value = 'Paste the login CSS from the box below.';
			}
			echo '<tr><td>' . esc_html( $row['screen'] ) . '</td><td>' . esc_html( $row['field'] ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>Login images</h3><ul>';
		foreach ( RW_Maint_White_Label_Preset::asset_files() as $file ) {
			$url = RW_Maint_White_Label_Preset::asset_url( $file );
			echo '<li>';
			if ( '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $file ) . '</a>';
			} else {
				echo esc_html( $file );
			}
			if ( RW_Maint_White_Label_Preset::LOGIN_IMAGE === $file ) {
				echo ' (default light image, includes the Maintained by line)';
			}
			if ( RW_Maint_White_Label_Preset::LOGIN_IMAGE_DARK === $file ) {
				echo ' (dark background only)';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<h3>Light login CSS</h3>';
		echo '<textarea class="large-text code" rows="8" readonly="readonly">' . esc_textarea( RW_Maint_White_Label_Preset::login_css() ) . '</textarea>';
		$dark = RW_Maint_White_Label_Preset::dark_variant();
		echo '<h3>Dark login CSS</h3>';
		echo '<p>' . esc_html( $dark['when'] ) . '</p>';
		echo '<textarea class="large-text code" rows="8" readonly="readonly">' . esc_textarea( $dark['login_css'] ) . '</textarea>';

		echo '<h3>Not set by this preset</h3><ul>';
		foreach ( RW_Maint_White_Label_Preset::not_set() as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * @param string $text Textarea contents.
	 * @return array<int,array<string,string>>
	 */
	public static function parse_overrides( $text ) {
		$rows = array();
		$lines = preg_split( "/\r\n|\n|\r/", (string) $text );
		if ( ! is_array( $lines ) ) {
			return $rows;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}
			$id    = (int) $parts[0];
			$email = self::email( $parts[1] );
			if ( $id <= 0 || '' === $email ) {
				continue;
			}
			$row = array( 'support_email' => $email );
			if ( isset( $parts[2] ) ) {
				$mode = sanitize_key( $parts[2] );
				if ( in_array( $mode, self::modes(), true ) ) {
					$row['notify_mode'] = $mode;
				}
			}
			$rows[ $id ] = $row;
		}

		return $rows;
	}

	/**
	 * @param array<int|string,mixed> $overrides Stored map.
	 * @return array<int,array<string,string>>
	 */
	private static function clean_overrides( array $overrides ) {
		$rows = array();
		foreach ( $overrides as $id => $row ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $row ) ) {
				continue;
			}
			$email = isset( $row['support_email'] ) ? self::email( $row['support_email'] ) : '';
			if ( '' === $email ) {
				continue;
			}
			$clean = array( 'support_email' => $email );
			if ( isset( $row['notify_mode'] ) && in_array( sanitize_key( (string) $row['notify_mode'] ), self::modes(), true ) ) {
				$clean['notify_mode'] = sanitize_key( (string) $row['notify_mode'] );
			}
			$rows[ $id ] = $clean;
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,string>> $overrides Parsed rows.
	 * @return string
	 */
	public static function format_overrides( array $overrides ) {
		$lines = array();
		foreach ( $overrides as $id => $row ) {
			$line = (int) $id . ' ' . $row['support_email'];
			if ( ! empty( $row['notify_mode'] ) ) {
				$line .= ' ' . $row['notify_mode'];
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param mixed $value Raw email.
	 * @return string
	 */
	private static function email( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( function_exists( 'sanitize_email' ) ) {
			$value = strtolower( (string) sanitize_email( $value ) );
		}
		return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
	}

	/**
	 * @param mixed $value Display name.
	 * @return string
	 */
	private static function name( $value ) {
		$value = trim( str_replace( array( "\r", "\n" ), '', (string) $value ) );
		if ( function_exists( 'sanitize_text_field' ) ) {
			$value = sanitize_text_field( $value );
		}
		if ( strlen( $value ) > 80 ) {
			$value = substr( $value, 0, 80 );
		}
		return $value;
	}

	/**
	 * @param mixed $value Checkbox or stored flag.
	 * @return bool
	 */
	private static function truthy( $value ) {
		return true === $value || 1 === $value || '1' === (string) $value || 'true' === (string) $value || 'yes' === (string) $value;
	}
}
