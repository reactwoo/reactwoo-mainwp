<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time staff link when automatic setup cannot finish.
 *
 * The link is a credential: encrypted at rest, omitted from the audit log,
 * shown to staff once, then deleted. It does not create a WordPress user.
 */
class RW_Temporary_Access {
	const TTL       = 86400;
	const PAGE_SLUG = 'rw-portal-manual-access';

	public static function register() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_consume' ) );
		}
	}

	public static function add_menu() {
		add_submenu_page(
			RW_Portal_Admin_Menu::PARENT_SLUG,
			__( 'Manual setup', 'reactwoo-support-portal' ),
			__( 'Manual setup', 'reactwoo-support-portal' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @param object $site    Managed site row.
	 * @param int    $user_id Account owner.
	 * @return string|WP_Error sent|pending. Never the link.
	 */
	public static function request( $site, $user_id ) {
		if ( ! $site || (int) $site->user_id !== (int) $user_id ) {
			return new WP_Error( 'rw_site_missing', 'Website not found.', array( 'status' => 404 ) );
		}

		$state = isset( $site->connection_state ) ? (string) $site->connection_state : '';
		if ( ! in_array( $state, array( RW_Connection_State::ACTION_REQUIRED, RW_Connection_State::FAILED ), true ) ) {
			return new WP_Error( 'rw_manual_unavailable', 'Manual setup is only available when automatic setup needs attention.', array( 'status' => 409 ) );
		}

		if ( self::is_pending( $site ) ) {
			return 'pending';
		}

		delete_user_meta( (int) $site->user_id, self::meta_key( $site->id ) );

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'rw_manual_unavailable', 'Manual setup is unavailable on this site.', array( 'status' => 500 ) );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );
		$host  = wp_parse_url( (string) $site->site_url, PHP_URL_HOST );
		$blob  = self::seal(
			array(
				'site_id'         => (int) $site->id,
				'user_id'         => (int) $site->user_id,
				'subscription_id' => (int) $site->subscription_id,
				'host'            => $host ? $host : '',
			)
		);
		if ( is_wp_error( $blob ) ) {
			return $blob;
		}

		set_transient( self::transient_key( $hash ), $blob, self::TTL );
		update_user_meta( (int) $site->user_id, self::meta_key( $site->id ), $hash );

		$staff = sanitize_email( (string) get_option( 'admin_email' ) );
		$url   = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'rw_manual_token'  => $token,
			),
			admin_url( 'admin.php' )
		);
		$sent = '' !== $staff && wp_mail(
			$staff,
			'Finish a website connection',
			"A customer asked ReactWoo to finish connecting their website.\n\nWebsite: " . ( $host ? $host : 'unknown' ) . "\n\nOpen this one-time link. It is removed after it is opened and expires within 24 hours:\n" . $url . "\n\nDo not forward this message. Do not create a permanent administrator.\n"
		);

		if ( ! $sent ) {
			delete_transient( self::transient_key( $hash ) );
			delete_user_meta( (int) $site->user_id, self::meta_key( $site->id ) );
			return new WP_Error( 'rw_manual_undelivered', 'The request could not be sent. Try again shortly.', array( 'status' => 500 ) );
		}

		RW_Audit::log(
			'maintenance_manual_setup_selected',
			array(
				'user_id'         => (int) $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
				'host'            => $host ? $host : '',
			)
		);

		return 'sent';
	}

	public static function is_pending( $site ) {
		if ( ! $site || empty( $site->id ) || empty( $site->user_id ) ) {
			return false;
		}

		$hash = (string) get_user_meta( (int) $site->user_id, self::meta_key( $site->id ), true );
		if ( '' === $hash ) {
			return false;
		}

		$blob = get_transient( self::transient_key( $hash ) );
		return is_string( $blob ) && '' !== $blob;
	}

	/**
	 * @param string $token Plaintext token from the staff link.
	 * @return array|WP_Error
	 */
	public static function consume( $token ) {
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) {
			return new WP_Error( 'rw_manual_invalid', 'This link has expired or was already used.', array( 'status' => 410 ) );
		}

		$hash = hash( 'sha256', $token );
		$key  = self::transient_key( $hash );
		$blob = get_transient( $key );
		delete_transient( $key );
		if ( ! is_string( $blob ) || '' === $blob ) {
			return new WP_Error( 'rw_manual_invalid', 'This link has expired or was already used.', array( 'status' => 410 ) );
		}

		$data = self::open( $blob );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! empty( $data['user_id'] ) && ! empty( $data['site_id'] ) ) {
			delete_user_meta( (int) $data['user_id'], self::meta_key( $data['site_id'] ) );
		}

		return $data;
	}

	public static function maybe_consume() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || empty( $_GET['rw_manual_token'] ) ) {
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET['rw_manual_token'] ) );
		$result = self::consume( $token );
		$notice = is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result;
		set_transient( 'rw_manual_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key    = 'rw_manual_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Manual setup', 'reactwoo-support-portal' ) . '</h1>';
		if ( is_array( $notice ) && ! empty( $notice['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $notice['error'] ) . '</p></div>';
		} elseif ( is_array( $notice ) && ! empty( $notice['host'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'This one-time link has been used and removed.', 'reactwoo-support-portal' ) . '</p></div>';
			echo '<p>' . esc_html( sprintf( 'Website: %s', (string) $notice['host'] ) ) . '</p>';
			echo '<p>' . esc_html__( 'Finish the maintenance connection, then confirm this temporary access is gone. Do not create a permanent administrator.', 'reactwoo-support-portal' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Open the one-time link from the request email. The link is removed after it is opened.', 'reactwoo-support-portal' ) . '</p>';
		}
		echo '</div>';
	}

	private static function meta_key( $site_id ) {
		return 'rw_manual_pending_' . (int) $site_id;
	}

	private static function transient_key( $hash ) {
		return 'rw_manual_' . $hash;
	}

	private static function seal( array $payload ) {
		$key    = hash( 'sha256', wp_salt( 'auth' ) . '|rw-manual-access', true );
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( wp_json_encode( $payload ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher || '' === $tag ) {
			return new WP_Error( 'rw_manual_unavailable', 'Manual setup is unavailable on this site.', array( 'status' => 500 ) );
		}

		return base64_encode( $iv . $tag . $cipher );
	}

	private static function open( $blob ) {
		$raw = base64_decode( (string) $blob, true );
		if ( ! is_string( $raw ) || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'rw_manual_invalid', 'This link has expired or was already used.', array( 'status' => 410 ) );
		}

		$key  = hash( 'sha256', wp_salt( 'auth' ) . '|rw-manual-access', true );
		$json = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['site_id'] ) ) {
			return new WP_Error( 'rw_manual_invalid', 'This link has expired or was already used.', array( 'status' => 410 ) );
		}

		return $data;
	}
}
