<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes named site notices to the ReactWoo support address.
 *
 * Login logos, footers, plugin names and login CSS belong to the MainWP
 * White Label extension. This class does not print them and does not change
 * the site admin_email option. Password resets and other account mail stay
 * with the site.
 */
class RW_Whitelabel_Mail {
	const OPTION = 'rw_wl_support_mail';

	public static function register() {
		add_filter( 'wp_mail', array( __CLASS__, 'filter_mail' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'support_email'           => 'support@reactwoo.com',
			'support_email_name'      => 'ReactWoo Support',
			'notify_mode'             => 'security_only',
			'notify_categories'       => self::default_categories(),
			'notify_keep_admin_copy'  => true,
			'client_notify_email'     => '',
		);
	}

	/**
	 * @return string[]
	 */
	public static function modes() {
		return array( 'security_only', 'cc_all', 'cc', 'redirect', 'leave' );
	}

	/**
	 * @return string[]
	 */
	public static function default_categories() {
		return array( 'core_updates', 'plugin_updates', 'theme_updates', 'auto_updates', 'wordfence', 'connector' );
	}

	/**
	 * @return string[]
	 */
	public static function routable_categories() {
		return array( 'core_updates', 'plugin_updates', 'theme_updates', 'auto_updates', 'wordfence', 'connector', 'comments' );
	}

	/**
	 * @return string[]
	 */
	public static function protected_categories() {
		return array( 'user_password', 'user_account' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function config() {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @param array<string,mixed> $input Hub or portal payload.
	 * @return array<string,mixed>
	 */
	public static function apply_config( array $input ) {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Read support_mail from a portal heartbeat response.
	 *
	 * @param mixed $response wp_remote_post result.
	 */
	public static function apply_remote_response( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return;
		}

		$code = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
		if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
		}
		if ( $code < 200 || $code >= 300 ) {
			return;
		}

		$body = isset( $response['body'] ) ? (string) $response['body'] : '';
		if ( function_exists( 'wp_remote_retrieve_body' ) ) {
			$body = (string) wp_remote_retrieve_body( $response );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['support_mail'] ) || ! is_array( $data['support_mail'] ) ) {
			return;
		}

		self::apply_config( $data['support_mail'] );
	}

	/**
	 * Header a connector generated message can set. Account mail must not use it.
	 *
	 * @param string $category Mail category.
	 * @return string
	 */
	public static function category_header( $category ) {
		$category = sanitize_key( (string) $category );
		if ( ! in_array( $category, self::routable_categories(), true ) ) {
			return '';
		}

		return 'X-RW-Mail-Category: ' . $category;
	}

	/**
	 * @param array<string,mixed> $args wp_mail arguments.
	 * @return array<string,mixed>
	 */
	public static function filter_mail( $args ) {
		if ( ! is_array( $args ) || ! self::managed() ) {
			return $args;
		}

		$category = self::category_for( $args );
		$args     = self::strip_category_header( $args );
		if ( in_array( $category, self::protected_categories(), true ) ) {
			return $args;
		}

		$config = self::config();
		if ( ! self::should_route( $config, $category ) ) {
			return $args;
		}

		return self::route_recipients( $args, $config );
	}

	/**
	 * @param array<string,mixed> $input Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
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

		if ( array_key_exists( 'notify_categories', $input ) && is_array( $input['notify_categories'] ) ) {
			$categories = array();
			foreach ( $input['notify_categories'] as $category ) {
				$category = sanitize_key( (string) $category );
				if ( 'security' === $category ) {
					$category = 'wordfence';
				}
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

		return array(
			'support_email'          => $email,
			'support_email_name'     => $name,
			'notify_mode'            => $mode,
			'notify_categories'      => array_values( $categories ),
			'notify_keep_admin_copy' => $keep,
			'client_notify_email'    => $client,
		);
	}

	/**
	 * @param array<string,mixed> $config Stored config.
	 * @param string              $category Classified category.
	 * @return bool
	 */
	public static function should_route( array $config, $category ) {
		$category = (string) $category;
		if ( '' === $category || in_array( $category, self::protected_categories(), true ) ) {
			return false;
		}
		if ( 'security' === $category ) {
			$category = 'wordfence';
		}

		$mode = isset( $config['notify_mode'] ) ? (string) $config['notify_mode'] : 'leave';
		if ( 'leave' === $mode ) {
			return false;
		}
		if ( 'security_only' === $mode ) {
			return 'wordfence' === $category;
		}
		if ( 'cc_all' === $mode ) {
			return in_array( $category, self::default_categories(), true );
		}

		$selected = isset( $config['notify_categories'] ) && is_array( $config['notify_categories'] ) ? $config['notify_categories'] : array();
		return in_array( $category, $selected, true );
	}

	/**
	 * @param array<string,mixed> $args wp_mail arguments.
	 * @return string
	 */
	public static function category_for( array $args ) {
		$subject = isset( $args['subject'] ) ? (string) $args['subject'] : '';
		if ( self::subject_is_account_mail( $subject ) ) {
			return 'user_account';
		}

		$explicit = self::explicit_category( $args );
		if ( in_array( $explicit, self::protected_categories(), true ) ) {
			return $explicit;
		}
		if ( 'security' === $explicit ) {
			return 'wordfence';
		}
		if ( in_array( $explicit, self::routable_categories(), true ) ) {
			return $explicit;
		}

		return self::category_from_subject( $subject );
	}

	private static function managed() {
		$managed = get_option( 'rw_connector_managed' );
		return '1' === (string) $managed || 1 === $managed || true === $managed;
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

	/**
	 * @param string $subject Mail subject.
	 * @return bool
	 */
	private static function subject_is_account_mail( $subject ) {
		return 1 === preg_match(
			'/password reset|reset your password|lost your password|password changed|new user registration|login details|username and password|email address changed|confirm your (email|account)|personal data|erase your|data erasure/i',
			$subject
		);
	}

	/**
	 * @param string $subject Mail subject.
	 * @return string
	 */
	private static function category_from_subject( $subject ) {
		if ( 1 === preg_match( '/wordfence|security alert/i', $subject ) ) {
			return 'wordfence';
		}
		if ( 1 === preg_match( '/plugins and themes have automatically updated/i', $subject ) ) {
			return 'auto_updates';
		}
		if ( 1 === preg_match( '/plugins were automatically updated|plugin updates?/i', $subject ) ) {
			return 'plugin_updates';
		}
		if ( 1 === preg_match( '/themes were automatically updated|theme updates?/i', $subject ) ) {
			return 'theme_updates';
		}
		if ( 1 === preg_match( '/automatically updated/i', $subject ) ) {
			return 'auto_updates';
		}
		if ( 1 === preg_match( '/wordpress [0-9]/i', $subject ) && 1 === preg_match( '/available|please update|updated to/i', $subject ) ) {
			return 'core_updates';
		}
		if ( 1 === preg_match( '/comment/i', $subject ) ) {
			return 'comments';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $args wp_mail arguments.
	 * @return string
	 */
	private static function explicit_category( array $args ) {
		foreach ( self::header_lines( isset( $args['headers'] ) ? $args['headers'] : array() ) as $line ) {
			if ( preg_match( '/^X-RW-Mail-Category\s*:\s*([a-z0-9_]+)/i', $line, $match ) ) {
				return sanitize_key( $match[1] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $args wp_mail arguments.
	 * @return array<string,mixed>
	 */
	private static function strip_category_header( array $args ) {
		if ( ! isset( $args['headers'] ) ) {
			return $args;
		}

		$lines = array();
		$seen  = false;
		foreach ( self::header_lines( $args['headers'] ) as $line ) {
			if ( preg_match( '/^X-RW-Mail-Category\s*:/i', $line ) ) {
				$seen = true;
				continue;
			}
			$lines[] = $line;
		}
		if ( $seen ) {
			$args['headers'] = $lines;
		}

		return $args;
	}

	/**
	 * @param array<string,mixed> $args wp_mail arguments.
	 * @param array<string,mixed> $config Sanitised config.
	 * @return array<string,mixed>
	 */
	private static function route_recipients( array $args, array $config ) {
		$support = isset( $config['support_email'] ) ? self::email( $config['support_email'] ) : '';
		if ( '' === $support ) {
			return $args;
		}

		$original = self::addresses_from( isset( $args['to'] ) ? $args['to'] : '' );
		$lines    = self::header_lines( isset( $args['headers'] ) ? $args['headers'] : array() );
		$keep     = ! empty( $config['notify_keep_admin_copy'] );
		$redirect = isset( $config['notify_mode'] ) && 'redirect' === $config['notify_mode'];

		if ( $redirect ) {
			$args['to'] = $support;
			if ( $keep ) {
				foreach ( $original as $address ) {
					if ( $address !== $support ) {
						$lines = self::add_cc( $lines, $address );
					}
				}
			} else {
				$lines = self::remove_addresses_from_cc( $lines, $original );
			}
		} elseif ( ! in_array( $support, $original, true ) ) {
			$lines = self::add_cc( $lines, $support );
		}

		$name = isset( $config['support_email_name'] ) ? self::name( $config['support_email_name'] ) : '';
		$reply = '' !== $name ? $name . ' <' . $support . '>' : $support;
		$lines = self::add_header_once( $lines, 'Reply-To', $reply );
		$args['headers'] = $lines;

		return $args;
	}

	/**
	 * @param mixed $headers String or list of headers.
	 * @return string[]
	 */
	private static function header_lines( $headers ) {
		if ( is_string( $headers ) ) {
			$parts = preg_split( "/\r\n|\n|\r/", $headers );
			return is_array( $parts ) ? array_values( array_filter( $parts, 'strlen' ) ) : array();
		}
		if ( ! is_array( $headers ) ) {
			return array();
		}

		$lines = array();
		foreach ( $headers as $key => $value ) {
			if ( is_int( $key ) ) {
				$lines[] = (string) $value;
			} else {
				$lines[] = $key . ': ' . $value;
			}
		}

		return $lines;
	}

	/**
	 * @param mixed $value To or Cc value.
	 * @return string[]
	 */
	private static function addresses_from( $value ) {
		$parts = is_array( $value ) ? $value : preg_split( '/,/', (string) $value );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$found = array();
		foreach ( $parts as $part ) {
			$part = (string) $part;
			if ( preg_match( '/<([^>]+)>/', $part, $match ) ) {
				$email = self::email( $match[1] );
			} else {
				$email = self::email( $part );
			}
			if ( '' !== $email && ! in_array( $email, $found, true ) ) {
				$found[] = $email;
			}
		}

		return $found;
	}

	/**
	 * @param string[] $lines Header lines.
	 * @param string   $email Address to add.
	 * @return string[]
	 */
	private static function add_cc( array $lines, $email ) {
		foreach ( $lines as $index => $line ) {
			if ( ! preg_match( '/^Cc\s*:/i', $line ) ) {
				continue;
			}
			$have = self::addresses_from( (string) preg_replace( '/^Cc\s*:/i', '', $line ) );
			if ( in_array( strtolower( $email ), $have, true ) ) {
				return $lines;
			}
			$lines[ $index ] = rtrim( $line ) . ', ' . $email;
			return $lines;
		}

		$lines[] = 'Cc: ' . $email;
		return $lines;
	}

	/**
	 * @param string[] $lines Header lines.
	 * @param string[] $drop Addresses to remove from Cc.
	 * @return string[]
	 */
	private static function remove_addresses_from_cc( array $lines, array $drop ) {
		$kept = array();
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^Cc\s*:/i', $line ) ) {
				$kept[] = $line;
				continue;
			}
			$addresses = self::addresses_from( (string) preg_replace( '/^Cc\s*:/i', '', $line ) );
			$remain    = array();
			foreach ( $addresses as $address ) {
				if ( ! in_array( $address, $drop, true ) ) {
					$remain[] = $address;
				}
			}
			if ( ! empty( $remain ) ) {
				$kept[] = 'Cc: ' . implode( ', ', $remain );
			}
		}

		return $kept;
	}

	/**
	 * @param string[] $lines Header lines.
	 * @param string   $name Header name.
	 * @param string   $value Header value.
	 * @return string[]
	 */
	private static function add_header_once( array $lines, $name, $value ) {
		foreach ( $lines as $line ) {
			if ( preg_match( '/^' . preg_quote( $name, '/' ) . '\s*:/i', $line ) ) {
				return $lines;
			}
		}
		$lines[] = $name . ': ' . $value;
		return $lines;
	}
}
