<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes hub support mail settings on the site heartbeat.
 *
 * The hub owns the support address and routing mode. This portal stores only
 * the optional client notification email, which is for portal mail to the
 * client and is not a replacement for the site admin email.
 */
class RW_Portal_Support_Mail {
	const OPTION     = 'rw_portal_client_notify_emails';
	const CACHE_TTL  = 3600;
	const MISS_TTL   = 900;

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
	 * Heartbeat body for one managed site.
	 *
	 * @param int                      $site_id Portal site id.
	 * @param array<string,mixed>|null $hub_config Preloaded hub payload. Null fetches it.
	 * @return array<string,mixed>|null Null when the hub could not be read.
	 */
	public static function payload_for_site( $site_id, $hub_config = null ) {
		$site_id = (int) $site_id;
		if ( null === $hub_config ) {
			$hub_config = self::hub_config( $site_id );
			if ( ! is_array( $hub_config ) || empty( $hub_config['support_email'] ) ) {
				return null;
			}
		}

		$config = self::sanitize( is_array( $hub_config ) ? $hub_config : array() );
		$client = self::client_email( $site_id );
		if ( '' !== $client ) {
			$config['client_notify_email'] = $client;
		}

		return $config;
	}

	/**
	 * @param int $site_id Portal site id.
	 * @return string
	 */
	public static function client_email( $site_id ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return '';
		}
		$map = isset( $stored['map'] ) && is_array( $stored['map'] ) ? $stored['map'] : array();
		$id  = (int) $site_id;
		if ( $id <= 0 || empty( $map[ $id ] ) ) {
			return '';
		}

		return self::email( $map[ $id ] );
	}

	/**
	 * @param mixed $input Settings form or stored option.
	 * @return array<string,mixed>
	 */
	public static function sanitize_clients( $input ) {
		$text = '';
		$map  = array();
		if ( is_array( $input ) && isset( $input['text'] ) ) {
			$text = (string) $input['text'];
		} elseif ( is_string( $input ) ) {
			$text = $input;
		}

		$lines = preg_split( "/\r\n|\n|\r/", $text );
		if ( is_array( $lines ) ) {
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
				if ( $id > 0 && '' !== $email ) {
					$map[ $id ] = $email;
				}
			}
		}

		$lines = array();
		foreach ( $map as $id => $email ) {
			$lines[] = (int) $id . ' ' . $email;
		}

		return array(
			'text' => implode( "\n", $lines ),
			'map'  => $map,
		);
	}

	/**
	 * @param array<string,mixed> $input Hub payload.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$modes    = array( 'security_only', 'cc_all', 'cc', 'redirect', 'leave' );
		$allowed  = array( 'core_updates', 'plugin_updates', 'theme_updates', 'auto_updates', 'wordfence', 'connector', 'comments' );

		$mode = isset( $input['notify_mode'] ) ? sanitize_key( (string) $input['notify_mode'] ) : $defaults['notify_mode'];
		if ( ! in_array( $mode, $modes, true ) ) {
			$mode = $defaults['notify_mode'];
		}

		$email = isset( $input['support_email'] ) ? self::email( $input['support_email'] ) : '';
		if ( '' === $email ) {
			$email = $defaults['support_email'];
		}

		$name = isset( $input['support_email_name'] ) ? sanitize_text_field( (string) $input['support_email_name'] ) : '';
		$name = trim( str_replace( array( "\r", "\n" ), '', $name ) );
		if ( '' === $name ) {
			$name = $defaults['support_email_name'];
		}

		if ( array_key_exists( 'notify_categories', $input ) && is_array( $input['notify_categories'] ) ) {
			$categories = array();
			foreach ( $input['notify_categories'] as $category ) {
				$category = sanitize_key( (string) $category );
				if ( in_array( $category, $allowed, true ) && ! in_array( $category, $categories, true ) ) {
					$categories[] = $category;
				}
			}
		} else {
			$categories = $defaults['notify_categories'];
		}

		if ( array_key_exists( 'notify_keep_admin_copy', $input ) ) {
			$keep = true === $input['notify_keep_admin_copy'] || 1 === $input['notify_keep_admin_copy'] || '1' === (string) $input['notify_keep_admin_copy'];
		} else {
			$keep = 'redirect' !== $mode;
		}

		$client = '';
		if ( ! empty( $input['client_notify_email'] ) ) {
			$client = self::email( $input['client_notify_email'] );
		}

		return array(
			'support_email'          => $email,
			'support_email_name'     => $name,
			'notify_mode'            => $mode,
			'notify_categories'      => array_values( $categories ),
			'notify_keep_admin_copy' => $keep,
			'client_notify_email'    => $client,
		);
	}

	public static function register_settings() {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			'rw_portal_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_clients' ),
				'default'           => array( 'text' => '', 'map' => array() ),
			)
		);

		add_settings_section(
			'rw_portal_support_mail_section',
			'Client notification email',
			array( __CLASS__, 'render_section' ),
			'rw-portal-settings'
		);

		add_settings_field(
			self::OPTION,
			'Client addresses',
			array( __CLASS__, 'render_field' ),
			'rw-portal-settings',
			'rw_portal_support_mail_section'
		);
	}

	public static function render_section() {
		echo '<p>These addresses receive portal messages such as connection updates. They do not replace the site admin email, and they do not receive password resets.</p>';
		echo '<p>The support address and security alert routing are set on the maintenance hub. Each site heartbeat carries them to the connector.</p>';
	}

	public static function render_field() {
		$stored = get_option( self::OPTION, array() );
		$clean  = self::sanitize_clients( is_array( $stored ) ? $stored : array() );
		printf(
			'<textarea class="large-text code" rows="5" name="%s[text]">%s</textarea>',
			esc_attr( self::OPTION ),
			esc_textarea( $clean['text'] )
		);
		echo '<p class="description">One site per line: portal site id, then the client email. Example: 12 owner@client.example</p>';
	}

	/**
	 * @param int $site_id Portal site id.
	 * @return array<string,mixed>
	 */
	private static function hub_config( $site_id ) {
		$key    = 'rw_portal_support_mail_hub_' . (int) $site_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached['miss'] ) ) {
			return array();
		}
		if ( is_array( $cached ) && ! empty( $cached['support_email'] ) ) {
			return $cached;
		}

		$fetched = self::fetch_hub( $site_id );
		if ( is_array( $fetched ) && ! empty( $fetched['support_email'] ) ) {
			set_transient( $key, $fetched, self::CACHE_TTL );
			return $fetched;
		}

		set_transient( $key, array( 'miss' => 1 ), self::MISS_TTL );
		return array();
	}

	/**
	 * @param int $site_id Portal site id.
	 * @return array<string,mixed>|null
	 */
	private static function fetch_hub( $site_id ) {
		if ( ! class_exists( 'RW_Maint_Client' ) || ! method_exists( 'RW_Maint_Client', 'support_mail' ) ) {
			return null;
		}

		$result = RW_Maint_Client::support_mail( (int) $site_id );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}

		return $result;
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
}
