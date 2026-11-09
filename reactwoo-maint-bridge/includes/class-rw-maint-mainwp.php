<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_MainWP {
	public static function register() {
		add_action( 'rw_maint_site_enrolled', array( __CLASS__, 'handle_enroll' ), 10, 2 );
		add_action( 'rw_maint_site_suspended', array( __CLASS__, 'handle_suspend' ), 10, 2 );
		add_action( 'rw_maint_site_resumed', array( __CLASS__, 'handle_resume' ), 10, 2 );
		add_action( 'rw_maint_site_disconnected', array( __CLASS__, 'handle_disconnect' ), 10, 2 );
		add_action( 'rw_maint_site_checked', array( __CLASS__, 'handle_check' ), 10, 2 );
		add_action( 'rw_maint_site_synced', array( __CLASS__, 'handle_sync' ), 10, 2 );
		add_action( 'rw_maint_site_reconnected', array( __CLASS__, 'handle_reconnect' ), 10, 2 );
		add_action( 'rw_maint_before_purge', array( __CLASS__, 'handle_purge' ), 10, 1 );
		add_action( 'rw_maint_site_backup_requested', array( __CLASS__, 'handle_backup' ), 10, 2 );
		add_action( 'rw_maint_site_run_updates_requested', array( __CLASS__, 'handle_run_updates' ), 10, 2 );
		add_action( 'rw_maint_site_security_scan_requested', array( __CLASS__, 'handle_security_scan' ), 10, 2 );
	}

	public static function handle_enroll( $maint_site_id, $payload = array() ) {
		$site = RW_Maint_Sites::get_site( $maint_site_id );
		if ( ! $site ) {
			return;
		}

		if ( ! empty( $site->mainwp_site_id ) && self::current_mainwp_target( $site ) ) {
			self::apply_hide_child_branding( $site );
			do_action( 'rw_maint_mainwp_sync_reporting', $site, $payload );
			self::link_subscriber( $site, $payload );
			return;
		}

		if ( ! empty( $site->mainwp_site_id ) ) {
			RW_Maint_Sites::update_site( (int) $site->id, array( 'mainwp_site_id' => 0 ) );
			$site->mainwp_site_id = 0;
		}

		$groups         = self::build_groups( $site, $payload );
		$mainwp_site_id = apply_filters( 'rw_maint_mainwp_create_site', null, $site, $payload, $groups );
		$mainwp_site_id = absint( $mainwp_site_id );
		if ( $mainwp_site_id ) {
			$candidate                 = clone $site;
			$candidate->mainwp_site_id = $mainwp_site_id;
			if ( ! self::current_mainwp_target( $candidate ) ) {
				$mainwp_site_id = 0;
			}
		}

		if ( $mainwp_site_id ) {
			RW_Maint_Sites::update_site( (int) $site->id, array( 'mainwp_site_id' => $mainwp_site_id ) );
			$site = RW_Maint_Sites::get_site( $maint_site_id );
			if ( ! $site ) {
				return;
			}

			RW_Maint_Audit::log(
				'mainwp_site_created',
				array(
					'portal_site_id'  => (int) $site->portal_site_id,
					'subscription_id' => (int) $site->subscription_id,
					'user_id'         => (int) $site->user_id,
					'mainwp_site_id'  => $mainwp_site_id,
				)
			);
		}

		if ( ! empty( $site->mainwp_site_id ) && self::current_mainwp_target( $site ) ) {
			self::apply_hide_child_branding( $site );
			do_action( 'rw_maint_mainwp_sync_reporting', $site, $payload, $groups );
		}

		self::link_subscriber( $site, $payload );
	}

	/**
	 * Create or update the MainWP client and assign only the site may_operate_on accepts.
	 * A failure does not undo enrolment. Already-enrolled sites are linked on the next
	 * re-enrol or check.
	 *
	 * @param object              $site Bridge site row.
	 * @param array<string,mixed> $payload Enrol or stored subscriber fields.
	 */
	private static function link_subscriber( $site, $payload ) {
		if ( ! $site || ! class_exists( 'RW_Maint_MainWP_Clients' ) ) {
			return;
		}

		try {
			$subscriber = RW_Maint_MainWP_Clients::subscriber_from_payload( (array) $payload );
			RW_Maint_MainWP_Clients::sync(
				$subscriber,
				self::approved_client_site( $site ),
				array(
					'portal_site_id'  => isset( $site->portal_site_id ) ? (int) $site->portal_site_id : 0,
					'subscription_id' => isset( $site->subscription_id ) ? (int) $site->subscription_id : 0,
					'user_id'         => isset( $site->user_id ) ? (int) $site->user_id : 0,
					'site_url'        => isset( $site->site_url ) ? (string) $site->site_url : '',
				)
			);
		} catch ( Throwable $e ) {
			if ( class_exists( 'RW_Maint_Audit' ) ) {
				RW_Maint_Audit::log(
					'mainwp_client_failed',
					array(
						'portal_site_id' => isset( $site->portal_site_id ) ? (int) $site->portal_site_id : 0,
						'error'          => 'client_link_failed',
					)
				);
			}
		}
	}

	/**
	 * @param object $site Bridge site row.
	 * @return int MainWP site id, or 0 when this enrolment must not touch it.
	 */
	private static function approved_client_site( $site ) {
		if ( ! $site || empty( $site->mainwp_site_id ) ) {
			return 0;
		}

		$id  = (int) $site->mainwp_site_id;
		$url = isset( $site->site_url ) ? (string) $site->site_url : '';
		if ( class_exists( 'RW_Maint_MainWP_Clients' ) && RW_Maint_MainWP_Clients::is_protected_site( $id, $url ) ) {
			return 0;
		}
		if ( ! self::current_mainwp_target( $site ) ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Subscriber details already stored for an enrolled site.
	 *
	 * @param object $site Bridge site row.
	 * @return array<string,mixed>
	 */
	private static function payload_from_site( $site ) {
		$user_id = ( $site && isset( $site->user_id ) ) ? (int) $site->user_id : 0;

		return array(
			'user_id'  => $user_id,
			'site_url' => ( $site && isset( $site->site_url ) ) ? (string) $site->site_url : '',
			'client'   => array(
				'client_name'    => ( $site && isset( $site->client_name ) ) ? (string) $site->client_name : '',
				'client_company' => ( $site && isset( $site->client_company ) ) ? (string) $site->client_company : '',
				'client_email'   => ( $site && isset( $site->client_email ) ) ? (string) $site->client_email : '',
				'customer_id'    => $user_id,
			),
		);
	}

	/**
	 * Child Reports are outside the White Label extension.
	 * Plugin name, login, footer and the support form stay in that extension.
	 * update_branding is not sent: it replaces the whole child branding record.
	 *
	 * @return array<int,array{what:string,params:array<string,mixed>}>
	 */
	public static function branding_calls() {
		return array(
			array(
				'what'   => 'client_report',
				'params' => array(
					'mwp_action' => 'set_showhide',
					'showhide'   => 'hide',
				),
			),
		);
	}

	/**
	 * Decide whether a stored MainWP id can be reused for this enrolment URL.
	 *
	 * A missing row or a different URL never reuses that id. Site 12 stays untouched
	 * when it belongs to another website.
	 *
	 * @param int         $stored_id Saved mainwp_site_id, or 0.
	 * @param string|null $record_url URL currently stored in mainwp_wp, or null when the row is gone.
	 * @param string      $enrolling_url URL being enrolled.
	 * @param int         $url_match_id Existing MainWP id whose URL already matches, or 0.
	 * @return array{reuse_id:int,blocked_id:int,create:bool}
	 */
	public static function resolve_reenroll_target( $stored_id, $record_url, $enrolling_url, $url_match_id = 0 ) {
		$stored_id    = (int) $stored_id;
		$url_match_id = (int) $url_match_id;
		$reuse        = 0;
		$blocked      = 0;

		if ( $stored_id > 0 && is_string( $record_url ) && '' !== $record_url ) {
			if ( self::urls_match( $record_url, $enrolling_url ) ) {
				$reuse = $stored_id;
			} else {
				$blocked = $stored_id;
			}
		}

		if ( ! $reuse && $url_match_id > 0 && $url_match_id !== $blocked ) {
			$reuse = $url_match_id;
		}

		return array(
			'reuse_id'   => $reuse,
			'blocked_id' => $blocked,
			'create'     => 0 === $reuse,
		);
	}

	/**
	 * Check and sync may run only when this id still belongs to the enrolled URL.
	 *
	 * @param int         $mainwp_id MainWP site id.
	 * @param string      $site_url Enrolled site URL.
	 * @param string|null $record_url URL from mainwp_wp, or null.
	 * @return bool
	 */
	public static function may_operate_on( $mainwp_id, $site_url, $record_url ) {
		if ( (int) $mainwp_id <= 0 || ! is_string( $record_url ) || '' === $record_url ) {
			return false;
		}

		return self::urls_match( $record_url, $site_url );
	}

	/**
	 * @param string $left First URL.
	 * @param string $right Second URL.
	 * @return bool
	 */
	public static function urls_match( $left, $right ) {
		$a = self::url_key( $left );
		$b = self::url_key( $right );

		return '' !== $a && $a === $b;
	}

	/**
	 * @param object $site Bridge site row.
	 * @return bool
	 */
	public static function current_mainwp_target( $site ) {
		if ( ! $site || empty( $site->mainwp_site_id ) ) {
			return false;
		}

		$record = RW_Maint_MainWP_Client::lookup_mainwp_site( (int) $site->mainwp_site_id );
		$url    = ( $record && isset( $record->url ) ) ? (string) $record->url : null;

		return self::may_operate_on( $site->mainwp_site_id, isset( $site->site_url ) ? $site->site_url : '', $url );
	}

	/**
	 * @param string $url Site URL.
	 * @return string
	 */
	private static function url_key( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		if ( '/' === $path ) {
			$path = '';
		}

		return $host . $path;
	}

	/**
	 * Hide Child Reports on the enrolled site.
	 * Enrolment still succeeds when the dashboard classes are not loaded.
	 * The call runs only for the id whose MainWP URL is this website.
	 * White Label fields are not included.
	 *
	 * @param object $site Operational site row.
	 * @return bool
	 */
	public static function apply_hide_child_branding( $site ) {
		if ( ! $site || empty( $site->mainwp_site_id ) || ! self::current_mainwp_target( $site ) ) {
			return false;
		}

		$applied = false;
		try {
			if ( class_exists( '\MainWP\Dashboard\MainWP_DB' ) && class_exists( '\MainWP\Dashboard\MainWP_Connect' ) && method_exists( '\MainWP\Dashboard\MainWP_Connect', 'fetch_url_authed' ) ) {
				$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( (int) $site->mainwp_site_id );
				if ( $website ) {
					foreach ( self::branding_calls() as $call ) {
						$result = \MainWP\Dashboard\MainWP_Connect::fetch_url_authed( $website, $call['what'], $call['params'] );
						if ( empty( $result ) || ( is_array( $result ) && ! empty( $result['error'] ) ) ) {
							continue;
						}
						$applied = true;
					}
				}
			}
		} catch ( Throwable $e ) {
			$applied = false;
		}

		if ( class_exists( 'RW_Maint_Audit' ) ) {
			RW_Maint_Audit::log(
				$applied ? 'mainwp_child_hidden' : 'mainwp_branding_skipped',
				array(
					'portal_site_id' => isset( $site->portal_site_id ) ? (int) $site->portal_site_id : 0,
					'mainwp_site_id' => (int) $site->mainwp_site_id,
				)
			);
		}

		return $applied;
	}

	public static function handle_suspend( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_suspend_site', $site );
	}

	public static function handle_resume( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_resume_site', $site );
	}

	public static function handle_disconnect( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_disconnect_site', $site );
	}

	public static function handle_check( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_check_site', $site );
		self::link_subscriber( $site, self::payload_from_site( $site ) );
	}

	public static function handle_sync( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_sync_site', $site );
	}

	public static function handle_reconnect( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}

		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_reconnect_site', $site );
	}

	public static function handle_purge( $site ) {
		if ( ! $site ) {
			return;
		}

		do_action( 'rw_maint_mainwp_purge_site', $site );
	}

	private static function build_groups( $site, $payload ) {
		$groups = array();

		if ( $site ) {
			$groups[] = 'client-' . (int) $site->user_id;
			$groups[] = 'subscription-' . (int) $site->subscription_id;
		}

		return apply_filters( 'rw_maint_mainwp_groups', $groups, $site, $payload );
	}

	public static function handle_backup( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}
		if ( ! $site ) {
			return;
		}
		do_action( 'rw_maint_mainwp_backup_site', $site );
	}

	public static function handle_run_updates( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}
		if ( ! $site ) {
			return;
		}
		do_action( 'rw_maint_mainwp_run_updates', $site );
	}

	public static function handle_security_scan( $portal_site_id, $site = null ) {
		if ( ! $site ) {
			$site = RW_Maint_Sites::get_by_portal_site_id( $portal_site_id );
		}
		if ( ! $site ) {
			return;
		}
		do_action( 'rw_maint_mainwp_security_scan', $site );
	}

}
