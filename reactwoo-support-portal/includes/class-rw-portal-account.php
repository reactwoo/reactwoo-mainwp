<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Account {
	const ENDPOINT = 'maintenance';

	private static $token_messages = array();

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
	}

	public static function activate() {
		self::register_endpoint();
		flush_rewrite_rules();
	}

	public static function register_endpoint() {
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		}
	}

	public static function add_menu_item( $items ) {
		if ( isset( $items[ self::ENDPOINT ] ) ) {
			return $items;
		}

		$new_items = array();
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new_items[ self::ENDPOINT ] = 'Maintenance';
			}
		}

		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = 'Maintenance';
		}

		return $new_items;
	}

	public static function render() {
		self::handle_actions();

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			echo '<p>Please log in to manage maintenance.</p>';
			return;
		}

		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			echo '<p>WooCommerce Subscriptions is required to manage maintenance plans.</p>';
			return;
		}

		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( empty( $subscriptions ) ) {
			echo '<p>No maintenance subscriptions found.</p>';
			return;
		}

		echo '<h2>Maintenance Plans</h2>';

		foreach ( $subscriptions as $subscription ) {
			self::render_subscription( $subscription, $user_id );
		}
	}

	private static function render_subscription( $subscription, $user_id ) {
		$subscription_id = $subscription->get_id();
		$status          = $subscription->get_status();
		$allowed_sites   = RW_Subscriptions::get_allowed_sites( $subscription, $user_id );
		$site_count      = RW_Sites::count_sites_for_subscription( $subscription_id );
		$sites           = RW_Sites::get_sites_by_subscription( $subscription_id );

		echo '<div class="rw-maint-subscription">';
		echo '<h3>Subscription #' . esc_html( $subscription_id ) . '</h3>';
		echo '<p>Status: <strong>' . esc_html( ucfirst( $status ) ) . '</strong></p>';
		echo '<p>Sites used: ' . esc_html( $site_count ) . ' / ' . esc_html( $allowed_sites > 0 ? $allowed_sites : 'Unlimited' ) . '</p>';

		self::render_sites_table( $sites );

		if ( 'active' !== $status ) {
			echo '<p class="rw-maint-note">Subscription is not active. Site enrollment is disabled.</p>';
			echo '</div>';
			return;
		}

		if ( $allowed_sites > 0 && $site_count >= $allowed_sites ) {
			echo '<p class="rw-maint-note">Site limit reached for this subscription.</p>';
			echo '</div>';
			return;
		}

		self::render_create_form( $subscription_id );

		echo '</div>';
	}

	private static function render_sites_table( $sites ) {
		echo '<table class="shop_table shop_table_responsive">';
		echo '<thead><tr>';
		echo '<th>Site</th><th>Status</th><th>Report Email</th><th>Health</th><th>Last Seen</th><th>Last Check</th><th>Last Sync</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $sites ) ) {
			echo '<tr><td colspan="8">No sites linked yet.</td></tr>';
		} else {
			foreach ( $sites as $site ) {
				self::render_site_row( $site );
			}
		}

		echo '</tbody></table>';
	}

	private static function render_site_row( $site ) {
		$health = array();
		if ( ! empty( $site->connector_version ) ) {
			$health[] = 'Connector ' . esc_html( $site->connector_version );
		}
		if ( ! empty( $site->wp_version ) ) {
			$health[] = 'WP ' . esc_html( $site->wp_version );
		}
		if ( ! empty( $site->php_version ) ) {
			$health[] = 'PHP ' . esc_html( $site->php_version );
		}

		$freshness = self::format_freshness( $site->last_seen );

		echo '<tr>';
		echo '<td><strong>' . esc_html( $site->site_name ) . '</strong><br />';
		echo '<small>' . esc_html( $site->site_url ) . '</small></td>';
		echo '<td>' . esc_html( ucfirst( $site->status ) ) . '</td>';
		echo '<td>' . esc_html( $site->report_email ) . '</td>';
		echo '<td>' . ( $health ? implode( ' | ', $health ) : 'Waiting for heartbeat' );
		echo '<br /><small>' . esc_html( $freshness ) . '</small></td>';
		echo '<td>' . self::format_datetime( $site->last_seen ) . '</td>';
		echo '<td>' . self::format_datetime( $site->last_check_at ) . '</td>';
		echo '<td>' . self::format_datetime( $site->last_sync_at ) . '</td>';
		echo '<td>';

		self::render_site_actions( $site );

		echo '</td>';
		echo '</tr>';

		if ( isset( self::$token_messages[ $site->id ] ) ) {
			echo '<tr><td colspan="8"><strong>Enrollment token:</strong> ' . esc_html( self::$token_messages[ $site->id ] ) . '</td></tr>';
		}
	}

	private static function render_site_actions( $site ) {
		$site_id = (int) $site->id;

		echo '<form method="post" style="margin-bottom:8px;">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="update_reporting" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<input type="email" name="report_email" value="' . esc_attr( $site->report_email ) . '" placeholder="Report email" />';
		echo '<label style="margin-left:6px;"><input type="checkbox" name="report_enabled" value="1" ' . checked( (int) $site->report_enabled, 1, false ) . ' /> Reports</label>';
		echo '<button type="submit" class="button" style="margin-left:6px;">Save</button>';
		echo '</form>';

		echo '<form method="post" style="margin-bottom:8px;">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="resync_site" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<button type="submit" class="button">Resync Client Details</button>';
		echo '</form>';

		echo '<form method="post" style="margin-bottom:8px;">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="toggle_url_override" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<input type="hidden" name="override_value" value="' . esc_attr( (int) ! $site->enroll_url_override ) . '" />';
		$label = $site->enroll_url_override ? 'Disable URL Override' : 'Allow URL Override';
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';

		if ( in_array( $site->status, array( 'connected', 'suspended', 'error' ), true ) ) {
			echo '<form method="post" style="margin-bottom:8px;">';
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
			echo '<input type="hidden" name="rw_portal_action" value="check_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Run Health Check</button>';
			echo '</form>';

			echo '<form method="post" style="margin-bottom:8px;">';
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
			echo '<input type="hidden" name="rw_portal_action" value="sync_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Sync Now</button>';
			echo '</form>';
		}

		if ( in_array( $site->status, array( 'disconnected', 'error' ), true ) ) {
			echo '<form method="post" style="margin-bottom:8px;">';
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
			echo '<input type="hidden" name="rw_portal_action" value="reconnect_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Reconnect Site</button>';
			echo '</form>';
		}

		if ( in_array( $site->status, array( 'pending', 'disconnected' ), true ) ) {
			echo '<form method="post" style="margin-bottom:8px;">';
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
			echo '<input type="hidden" name="rw_portal_action" value="issue_token" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Generate Enrollment Token</button>';
			echo '</form>';
		}

		if ( 'disconnected' !== $site->status ) {
			echo '<form method="post">';
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
			echo '<input type="hidden" name="rw_portal_action" value="disconnect_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Disconnect Site</button>';
			echo '</form>';
		}
	}

	private static function render_create_form( $subscription_id ) {
		echo '<h4>Add a Site</h4>';
		echo '<form method="post">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="create_site" />';
		echo '<input type="hidden" name="subscription_id" value="' . esc_attr( $subscription_id ) . '" />';
		echo '<p><label>Site URL<br /><input type="url" name="site_url" required placeholder="https://example.com" /></label></p>';
		echo '<p><label>Site Name (optional)<br /><input type="text" name="site_name" /></label></p>';
		echo '<p><label>Report Email (optional)<br /><input type="email" name="report_email" /></label></p>';
		echo '<p><label><input type="checkbox" name="report_enabled" value="1" checked /> Enable reports</label></p>';
		echo '<p><button type="submit" class="button">Create Site</button></p>';
		echo '</form>';
	}

	private static function handle_actions() {
		if ( empty( $_POST['rw_portal_action'] ) ) {
			return;
		}

		if ( empty( $_POST['rw_portal_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rw_portal_nonce'] ) ), 'rw_portal_account_action' ) ) {
			self::add_notice( 'Security check failed.', 'error' );
			return;
		}

		$action  = sanitize_key( wp_unslash( $_POST['rw_portal_action'] ) );
		$user_id = get_current_user_id();

		switch ( $action ) {
			case 'create_site':
				self::handle_create_site( $user_id );
				break;
			case 'issue_token':
				self::handle_issue_token( $user_id );
				break;
			case 'disconnect_site':
				self::handle_disconnect_site( $user_id );
				break;
			case 'check_site':
				self::handle_maint_action( $user_id, 'check', 'site_check_requested', 'Health check requested.' );
				break;
			case 'sync_site':
				self::handle_maint_action( $user_id, 'sync', 'site_sync_requested', 'Sync requested.' );
				break;
			case 'reconnect_site':
				self::handle_maint_action( $user_id, 'reconnect', 'site_reconnect_requested', 'Reconnect requested.' );
				break;
			case 'toggle_url_override':
				self::handle_toggle_url_override( $user_id );
				break;
			case 'resync_site':
				self::handle_resync_site( $user_id );
				break;
			case 'update_reporting':
				self::handle_update_reporting( $user_id );
				break;
		}
	}

	private static function handle_create_site( $user_id ) {
		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$site_url        = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		$site_name       = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '';
		$report_email    = isset( $_POST['report_email'] ) ? sanitize_email( wp_unslash( $_POST['report_email'] ) ) : '';
		$report_enabled  = isset( $_POST['report_enabled'] ) ? 1 : 0;

		if ( ! $subscription_id || '' === $site_url ) {
			self::add_notice( 'Subscription and site URL are required.', 'error' );
			return;
		}

		$subscription = RW_Subscriptions::validate_subscription_for_user( $subscription_id, $user_id );
		if ( is_wp_error( $subscription ) ) {
			self::add_notice( $subscription->get_error_message(), 'error' );
			return;
		}

		$allowed_sites = RW_Subscriptions::get_allowed_sites( $subscription, $user_id );
		$site_count    = RW_Sites::count_sites_for_subscription( $subscription_id );
		if ( $allowed_sites > 0 && $site_count >= $allowed_sites ) {
			self::add_notice( 'Site limit reached for this subscription.', 'error' );
			return;
		}

		if ( '' === $site_name ) {
			$host      = wp_parse_url( $site_url, PHP_URL_HOST );
			$site_name = $host ? $host : $site_url;
		}

		$identity  = RW_Identity::from_user( $user_id );
		$overrides = array(
			'report_email'   => $report_email,
			'report_enabled' => $report_enabled,
		);
		$identity = RW_Identity::apply_overrides( $identity, $overrides );

		$site_id = RW_Sites::create_pending_site(
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
				'site_url'        => $site_url,
				'site_name'       => $site_name,
				'client_name'     => $identity['client_name'],
				'client_email'    => $identity['client_email'],
				'client_company'  => $identity['client_company'],
				'client_phone'    => $identity['client_phone'],
				'client_locale'   => $identity['client_locale'],
				'report_email'    => $identity['report_email'],
				'report_enabled'  => $identity['report_enabled'],
			)
		);

		if ( ! $site_id ) {
			self::add_notice( 'Unable to create site.', 'error' );
			return;
		}

		$token = RW_Tokens::create_token( $site_id );
		if ( is_wp_error( $token ) ) {
			self::add_notice( $token->get_error_message(), 'error' );
			return;
		}

		RW_Audit::log(
			'site_created_ui',
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
				'managed_site_id' => $site_id,
				'site_url'        => $site_url,
			)
		);

		RW_Audit::log(
			'token_created',
			array(
				'user_id'         => $user_id,
				'subscription_id' => $subscription_id,
				'managed_site_id' => $site_id,
				'expires_at'      => $token['expires_at'],
			)
		);

		self::$token_messages[ $site_id ] = $token['token'];
		self::add_notice( 'Site created. Enrollment token generated below.', 'success' );
	}

	private static function handle_issue_token( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$token = RW_Tokens::create_token( (int) $site->id );
		if ( is_wp_error( $token ) ) {
			self::add_notice( $token->get_error_message(), 'error' );
			return;
		}

		RW_Audit::log(
			'token_created',
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
				'expires_at'      => $token['expires_at'],
			)
		);

		self::$token_messages[ $site->id ] = $token['token'];
		self::add_notice( 'Enrollment token generated.', 'success' );
	}

	private static function handle_disconnect_site( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		if ( 'disconnected' === $site->status ) {
			self::add_notice( 'Site is already disconnected.', 'error' );
			return;
		}

		RW_Sites::update_site( (int) $site->id, array( 'status' => 'disconnected' ) );
		$maint_result = RW_Maint_Client::update_site_status( $site, 'disconnect' );
		if ( is_wp_error( $maint_result ) ) {
			RW_Audit::log(
				'maint_disconnect_failed',
				array(
					'user_id'         => $user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'error'           => $maint_result->get_error_message(),
				)
			);
			self::add_notice( 'Site disconnected, but maintenance hub update failed.', 'error' );
		} else {
			RW_Audit::log(
				'maint_disconnect_sent',
				array(
					'user_id'         => $user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
				)
			);
		}

		RW_Audit::log(
			'site_disconnected',
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		if ( ! is_wp_error( $maint_result ) ) {
			self::add_notice( 'Site disconnected.', 'success' );
		}
	}

	private static function handle_resync_site( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$maint_result = null;
		$identity = RW_Identity::from_user( $user_id );
		RW_Sites::update_identity( (int) $site->id, $identity );

		$site = RW_Sites::get_site( (int) $site->id );
		if ( $site ) {
			$maint_result = RW_Maint_Client::enroll_site( $site );
			if ( is_wp_error( $maint_result ) ) {
				RW_Audit::log(
					'maint_sync_failed',
					array(
						'user_id'         => $user_id,
						'subscription_id' => (int) $site->subscription_id,
						'managed_site_id' => (int) $site->id,
						'error'           => $maint_result->get_error_message(),
					)
				);
				self::add_notice( 'Client details updated, but maintenance hub sync failed.', 'error' );
			}
		}

		RW_Audit::log(
			'client_sync',
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		if ( empty( $maint_result ) || ! is_wp_error( $maint_result ) ) {
			self::add_notice( 'Client details resynced.', 'success' );
		}
	}

	private static function handle_update_reporting( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$maint_result  = null;
		$report_email   = isset( $_POST['report_email'] ) ? sanitize_email( wp_unslash( $_POST['report_email'] ) ) : '';
		$report_enabled = isset( $_POST['report_enabled'] ) ? 1 : 0;

		RW_Sites::update_identity(
			(int) $site->id,
			array(
				'report_email'   => $report_email,
				'report_enabled' => $report_enabled,
			)
		);

		$site = RW_Sites::get_site( (int) $site->id );
		if ( $site ) {
			$maint_result = RW_Maint_Client::enroll_site( $site );
			if ( is_wp_error( $maint_result ) ) {
				RW_Audit::log(
					'maint_sync_failed',
					array(
						'user_id'         => $user_id,
						'subscription_id' => (int) $site->subscription_id,
						'managed_site_id' => (int) $site->id,
						'error'           => $maint_result->get_error_message(),
					)
				);
				self::add_notice( 'Reporting updated, but maintenance hub sync failed.', 'error' );
			}
		}

		RW_Audit::log(
			'reporting_updated',
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		if ( empty( $maint_result ) || ! is_wp_error( $maint_result ) ) {
			self::add_notice( 'Reporting preferences updated.', 'success' );
		}
	}

	private static function load_site_for_user( $user_id ) {
		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		if ( ! $site_id ) {
			self::add_notice( 'Site is required.', 'error' );
			return null;
		}

		$site = RW_Sites::get_site( $site_id );
		if ( ! $site || (int) $site->user_id !== (int) $user_id ) {
			self::add_notice( 'Site not found.', 'error' );
			return null;
		}

		return $site;
	}

	private static function add_notice( $message, $type = 'success' ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	private static function handle_maint_action( $user_id, $action, $audit_event, $success_notice ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$result = RW_Maint_Client::update_site_status( $site, $action );
		if ( is_wp_error( $result ) ) {
			RW_Audit::log(
				'maint_action_failed',
				array(
					'user_id'         => $user_id,
					'subscription_id' => (int) $site->subscription_id,
					'managed_site_id' => (int) $site->id,
					'action'          => $action,
					'error'           => $result->get_error_message(),
				)
			);
			self::add_notice( 'Maintenance hub action failed.', 'error' );
			return;
		}

		RW_Audit::log(
			$audit_event,
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
			)
		);

		$timestamp = current_time( 'mysql', true );
		if ( 'check' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'last_check_at' => $timestamp ) );
		} elseif ( 'sync' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'last_sync_at' => $timestamp ) );
		} elseif ( 'reconnect' === $action ) {
			RW_Sites::update_site( (int) $site->id, array( 'last_reconnect_at' => $timestamp ) );
		}

		self::add_notice( $success_notice, 'success' );
	}

	private static function handle_toggle_url_override( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$value = isset( $_POST['override_value'] ) ? absint( $_POST['override_value'] ) : 0;
		RW_Sites::set_url_override( (int) $site->id, (bool) $value );

		RW_Audit::log(
			'url_override_toggled',
			array(
				'user_id'         => $user_id,
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
				'enabled'         => (int) $value,
			)
		);

		self::add_notice( $value ? 'URL override enabled for this site.' : 'URL override disabled for this site.', 'success' );
	}

	private static function format_datetime( $value ) {
		if ( empty( $value ) ) {
			return 'Never';
		}

		$timestamp = strtotime( $value );
		if ( ! $timestamp ) {
			return 'Unknown';
		}

		return esc_html( date_i18n( 'M j, Y H:i', $timestamp ) );
	}

	private static function format_freshness( $value ) {
		if ( empty( $value ) ) {
			return 'No heartbeat yet';
		}

		$timestamp = strtotime( $value );
		if ( ! $timestamp ) {
			return 'Heartbeat timestamp unavailable';
		}

		$age = time() - $timestamp;
		$diff = human_time_diff( $timestamp, time() );

		if ( $age > DAY_IN_SECONDS ) {
			return 'Stale (' . $diff . ' ago)';
		}

		return 'Fresh (' . $diff . ' ago)';
	}
}
