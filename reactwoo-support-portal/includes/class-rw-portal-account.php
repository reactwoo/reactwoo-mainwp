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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download_site_plugin' ) );
	}

	public static function enqueue_assets() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}

		wp_enqueue_style(
			'rw-portal-maintenance',
			RW_PORTAL_URL . 'assets/css/account-maintenance.css',
			array(),
			RW_PORTAL_VERSION
		);
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

		if ( ! RW_Maintenance_Entitlements::portal_v2_enabled() ) {
			echo '<p>Maintenance setup is not available on this account yet.</p>';
			return;
		}

		$subscriptions = array();
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			$entitlement = RW_Maintenance_Entitlements::resolve( $subscription );
			if ( is_wp_error( $entitlement ) || empty( $entitlement['is_maintenance'] ) ) {
				continue;
			}
			$subscriptions[] = $subscription;
		}
		if ( empty( $subscriptions ) ) {
			echo '<p>No maintenance subscriptions found.</p>';
			return;
		}

		echo '<div class="rw-maint">';
		foreach ( $subscriptions as $subscription ) {
			self::render_subscription( $subscription, $user_id );
		}
		echo '</div>';
	}

	private static function render_subscription( $subscription, $user_id ) {
		$rw_view  = self::view_model( $subscription, $user_id );
		$partials = array(
			'connecting'   => 'account-connecting.php',
			'attention'    => 'account-attention.php',
			'connected'    => 'account-dashboard.php',
			'paused'       => 'account-paused.php',
			'disconnected' => 'account-paused.php',
		);
		$file = isset( $partials[ $rw_view['state'] ] ) ? $partials[ $rw_view['state'] ] : 'account-onboarding.php';
		include RW_PORTAL_DIR . 'templates/' . $file;
	}

	public static function download_url() {
		$base = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/' );

		return wp_nonce_url( add_query_arg( 'rw_site_plugin', '1', $base ), 'rw_download_site_plugin', 'rw_site_plugin_nonce' );
	}

	public static function render_plugin_download() {
		echo '<a class="rw-maint-button rw-maint-button-secondary rw-maint-download" href="' . esc_url( self::download_url() ) . '">Download site plugin</a>';
		echo '<p class="rw-maint-helper">In WordPress, open Plugins, choose Add New, then Upload Plugin. Install the file and activate it. Then connect below.</p>';
	}

	public static function maybe_download_site_plugin() {
		if ( empty( $_GET['rw_site_plugin'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$nonce = isset( $_GET['rw_site_plugin_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_site_plugin_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rw_download_site_plugin' ) ) {
			wp_die( esc_html__( 'This download link has expired. Return to your account and try again.', 'reactwoo-support-portal' ), '', array( 'response' => 403 ) );
		}

		$file = RW_PORTAL_DIR . 'downloads/reactwoo-site-plugin.zip';
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'The site plugin is not available for download yet.', 'reactwoo-support-portal' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="reactwoo-site-plugin.zip"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file );
		exit;
	}

	public static function render_connect_form( $subscription_id, $compact = false ) {
		echo '<form class="rw-maint-form" method="post">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="connect_wordpress" />';
		echo '<input type="hidden" name="subscription_id" value="' . esc_attr( $subscription_id ) . '" />';
		echo '<label>Website address<input type="url" name="site_url" required placeholder="https://example.com" autocomplete="url" /></label>';
		if ( ! $compact ) {
			echo '<label><span>Site name <span class="rw-maint-helper">optional</span></span><input type="text" name="site_name" autocomplete="organization" /></label>';
			echo '<label><span>Report email <span class="rw-maint-helper">optional</span></span><input type="email" name="report_email" autocomplete="email" /></label>';
			echo '<label><input type="checkbox" name="report_enabled" value="1" checked /> Enable reports</label>';
		}
		echo '<button type="submit" class="rw-maint-button">Connect WordPress</button>';
		echo '</form>';
	}

	public static function render_connect_existing_form( $site ) {
		echo '<form class="rw-maint-form" method="post">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="connect_existing" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<button type="submit" class="rw-maint-button">Connect WordPress</button>';
		echo '</form>';
	}

	public static function render_report_preferences( $site ) {
		$site_id = (int) $site->id;
		$email   = isset( $site->report_email ) ? (string) $site->report_email : '';
		$enabled = ! isset( $site->report_enabled ) || (int) $site->report_enabled;
		echo '<form class="rw-maint-form" method="post">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="update_reporting" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<label for="rw-report-email-' . esc_attr( $site_id ) . '">Report email</label>';
		echo '<input id="rw-report-email-' . esc_attr( $site_id ) . '" type="email" name="report_email" value="' . esc_attr( $email ) . '" required />';
		echo '<label class="rw-maint-check"><input type="checkbox" name="report_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> Send maintenance summaries</label>';
		echo '<button type="submit" class="rw-maint-button">Save report email</button>';
		echo '</form>';
	}

	public static function render_manual_request( $site ) {
		echo '<form class="rw-maint-form" method="post">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="request_manual_setup" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<button type="submit" class="rw-maint-button-secondary">Let ReactWoo connect it for you</button>';
		echo '</form>';
	}

	public static function render_disconnect_form( $site ) {
		if ( ! isset( $site->status ) || 'disconnected' === $site->status ) {
			return;
		}
		echo '<form method="post">';
		wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		echo '<input type="hidden" name="rw_portal_action" value="disconnect_site" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<button type="submit" class="rw-maint-button-secondary">Disconnect website</button>';
		echo '</form>';
	}

	private static function subscription_can_connect( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'has_status' ) ) {
			return false;
		}
		if ( $subscription->has_status( 'active' ) ) {
			return true;
		}
		if ( ! $subscription->has_status( 'pending-cancel' ) ) {
			return false;
		}
		$end = method_exists( $subscription, 'get_time' ) ? (int) $subscription->get_time( 'end' ) : 0;
		return RW_Subscription_Policy::entitlement_still_paid( $end > 0 ? $end : null, time() );
	}

	private static function view_model( $subscription, $user_id ) {
		$subscription_id = (int) $subscription->get_id();
		$sites           = RW_Sites::get_sites_by_subscription( $subscription_id );
		$site            = self::primary_site( $sites );
		$entitlement     = RW_Maintenance_Entitlements::resolve( $subscription );
		$plan_name       = 'Standard Maintenance';
		$minutes         = 60;
		$can_connect     = self::subscription_can_connect( $subscription );
		$message         = '';

		if ( ! is_wp_error( $entitlement ) ) {
			$plan_name = ucwords( str_replace( array( '-', '_' ), ' ', (string) $entitlement['plan_key'] ) ) . ' Maintenance';
			$minutes   = isset( $entitlement['technical_support_minutes_monthly'] )
				? (int) $entitlement['technical_support_minutes_monthly']
				: (int) $entitlement['technical_support_minutes'];
			if ( $can_connect && ! $site ) {
				$allowed = RW_Maintenance_Entitlements::allows_another_site(
					$entitlement,
					RW_Sites::count_sites_for_subscription( $subscription_id )
				);
				if ( is_wp_error( $allowed ) ) {
					$can_connect = false;
					$message     = $allowed->get_error_message();
				}
			}
		} elseif ( $can_connect ) {
			$can_connect = false;
			$message     = $entitlement->get_error_message();
		}

		if ( ! self::subscription_can_connect( $subscription ) ) {
			$message = 'This plan is not active, so website connection is paused.';
		}

		$state = self::view_state( $subscription, $site );
		if ( $site && ! empty( $site->connection_error_message ) && 'attention' === $state ) {
			$message = (string) $site->connection_error_message;
		}

		$reports_on = is_wp_error( $entitlement ) || ! empty( $entitlement['reports'] );
		$speed_on   = ! is_wp_error( $entitlement ) && ! empty( $entitlement['performance_monitoring'] );
		$backup     = '';
		if ( ! is_wp_error( $entitlement ) ) {
			$backup = ! empty( $entitlement['backup_profile'] ) ? (string) $entitlement['backup_profile'] : (string) $entitlement['backup_frequency'];
		}
		$support    = sanitize_email( (string) get_option( 'admin_email' ) );

		return array(
			'subscription_id' => $subscription_id,
			'plan_name'       => $plan_name,
			'minutes'         => $minutes,
			'site'            => $site,
			'sites'           => self::site_summaries( $sites ),
			'state'           => $state,
			'status_label'    => self::state_label( $state, $site ),
			'message'         => $message,
			'can_connect'     => $can_connect,
			'backup_label'    => '' !== $backup ? ucfirst( $backup ) : 'Included on your plan',
			'last_seen'       => ( $site && ! empty( $site->last_seen ) ) ? self::format_datetime( $site->last_seen ) : '',
			'support_mailto'  => $support ? 'mailto:' . $support . '?subject=' . rawurlencode( 'Maintenance support' ) : '',
			'progress'        => self::progress_steps( $site ),
			'plan_points'     => array(
				array( 'title' => 'Managed WordPress updates', 'text' => 'Core, themes and supported plugins' ),
				array( 'title' => 'Cloud backups', 'text' => 'Recovery points stored off-site' ),
				array( 'title' => 'Monitoring and reporting', 'text' => 'Uptime, security and changes' ),
				array( 'title' => $minutes . ' minutes of technical support', 'text' => 'Each month of your plan' ),
			),
			'benefits'        => self::benefit_cards( $reports_on, $speed_on ),
			'reports'         => self::report_cards( $reports_on, $speed_on ),
			'manual_pending'  => $site && class_exists( 'RW_Temporary_Access' ) && RW_Temporary_Access::is_pending( $site ),
		);
	}

	private static function primary_site( $sites ) {
		if ( empty( $sites ) ) {
			return null;
		}

		foreach ( $sites as $site ) {
			if ( isset( $site->status ) && 'disconnected' !== $site->status ) {
				return $site;
			}
		}

		return $sites[0];
	}

	private static function state_label( $state, $site ) {
		if ( $site ) {
			return self::customer_status_label( $site );
		}
		if ( 'paused' === $state ) {
			return RW_Connection_State::LABEL_PAUSED;
		}

		return RW_Connection_State::LABEL_SETUP_REQUIRED;
	}

	private static function view_state( $subscription, $site ) {
		if ( 'active' !== $subscription->get_status() ) {
			return 'paused';
		}
		if ( ! $site ) {
			return 'setup';
		}
		if ( isset( $site->status ) && 'suspended' === $site->status ) {
			return 'paused';
		}
		if ( isset( $site->status ) && 'disconnected' === $site->status ) {
			return 'disconnected';
		}

		$state = isset( $site->connection_state ) ? (string) $site->connection_state : '';
		if ( RW_Connection_State::CONNECTED === $state || ( '' === $state && isset( $site->status ) && 'connected' === $site->status ) ) {
			return 'connected';
		}
		if ( in_array( $state, array( RW_Connection_State::ACTION_REQUIRED, RW_Connection_State::FAILED ), true ) ) {
			return 'attention';
		}
		if ( in_array( $state, array( RW_Connection_State::NOT_STARTED, RW_Connection_State::AWAITING_SITE, '' ), true ) ) {
			return 'setup';
		}

		return 'connecting';
	}

	private static function site_summaries( $sites ) {
		$rows = array();
		foreach ( (array) $sites as $site ) {
			$host = wp_parse_url( (string) $site->site_url, PHP_URL_HOST );
			$rows[] = array(
				'host'  => $host ? $host : (string) $site->site_url,
				'label' => self::customer_status_label( $site ),
			);
		}

		return $rows;
	}

	private static function progress_steps( $site ) {
		$state = ( $site && isset( $site->connection_state ) ) ? (string) $site->connection_state : '';
		$map   = array(
			RW_Connection_State::AWAITING_AUTHORISATION => 0,
			RW_Connection_State::AUTHORISING            => 0,
			RW_Connection_State::INSTALLING             => 1,
			RW_Connection_State::BOOTSTRAPPING          => 1,
			RW_Connection_State::CONNECTING_MAINTENANCE => 2,
			RW_Connection_State::VERIFYING              => 4,
		);
		$current = isset( $map[ $state ] ) ? $map[ $state ] : 0;
		$labels  = array(
			array( 'WordPress authorised', 'You approve the connection on your website.' ),
			array( 'ReactWoo connected', 'We confirm the website and start setup.' ),
			array( 'Setting up maintenance', 'Updates, backups and monitoring are turned on.' ),
			array( 'Preparing backups', 'The first recovery point is prepared.' ),
			array( 'Running initial checks', 'We confirm the website is looked after.' ),
		);
		$steps = array();
		foreach ( $labels as $index => $label ) {
			$mark = 'Waiting';
			if ( $index < $current ) {
				$mark = 'Done';
			} elseif ( $index === $current ) {
				$mark = 'In progress';
			}
			$steps[] = array(
				'title' => $label[0],
				'text'  => $label[1],
				'mark'  => $mark,
			);
		}

		return $steps;
	}

	private static function benefit_cards( $reports_on, $speed_on ) {
		$cards = array(
			array( 'kicker' => 'Updates', 'title' => 'Managed updates', 'text' => 'Routine WordPress, theme and supported plugin updates are handled and recorded.' ),
			array( 'kicker' => 'Licences', 'title' => 'Premium licences', 'text' => 'Eligible premium plugins are covered by the plan, reducing separate renewals.' ),
			array( 'kicker' => 'Backups', 'title' => 'Cloud backups', 'text' => 'Scheduled off-site backups make recovery possible if an update causes trouble.' ),
			array( 'kicker' => 'Monitoring', 'title' => 'Site monitoring', 'text' => 'Availability, SSL and domain health, plus a scheduled database tidy.' ),
			array( 'kicker' => 'Security', 'title' => 'Security checks', 'text' => 'Vulnerability and security signals inform maintenance priorities.' ),
		);

		if ( $reports_on ) {
			$cards[] = array( 'kicker' => 'Reporting', 'title' => 'Clear reporting', 'text' => 'Page speed, store status, changes, updates and backups, in plain language.' );
			$cards[] = array( 'kicker' => 'Changes', 'title' => 'Change history', 'text' => 'Who changed the site, what they changed, and when.' );
			$cards[] = array( 'kicker' => 'Store', 'title' => 'Store status', 'text' => 'Orders waiting, stock and this month\'s sales, when WooCommerce is on the site.' );
		}
		if ( $speed_on ) {
			$cards[] = array( 'kicker' => 'Page speed', 'title' => 'Page speed', 'text' => 'Google PageSpeed scores for mobile and desktop, when that connection is on.' );
		}

		return $cards;
	}

	private static function report_cards( $reports_on, $speed_on ) {
		$cards = array(
			array( 'title' => 'Updates and backups', 'text' => 'Completed updates and backup points appear here after the first maintenance run.' ),
			array( 'title' => 'Database tidy', 'text' => 'Old revisions and clutter are cleared on a schedule. The last tidy is shown after it runs.' ),
		);
		if ( $reports_on ) {
			$cards[] = array( 'title' => 'Change history', 'text' => 'Who changed the site, what they changed, and when. This list starts once activity reporting is on.' );
			$cards[] = array( 'title' => 'Store status', 'text' => 'Orders, stock and sales, shown when WooCommerce is on this website.' );
		}
		if ( $speed_on ) {
			$cards[] = array( 'title' => 'Page speed', 'text' => 'Mobile and desktop Google PageSpeed scores appear here when that connection is on.' );
		}

		return $cards;
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
		echo '<td>' . esc_html( self::customer_status_label( $site ) ) . '</td>';
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

	private static function customer_status_label( $site ) {
		if ( isset( $site->status ) && 'suspended' === $site->status ) {
			return RW_Connection_State::LABEL_PAUSED;
		}
		if ( isset( $site->status ) && 'disconnected' === $site->status ) {
			return RW_Connection_State::LABEL_DISCONNECTED;
		}

		$state = isset( $site->connection_state ) ? (string) $site->connection_state : '';
		if ( '' === $state && isset( $site->status ) && 'connected' === $site->status ) {
			return RW_Connection_State::LABEL_CONNECTED;
		}

		return RW_Connection_State::customer_label( $state );
	}

	private static function render_site_actions( $site ) {
		$site_id = (int) $site->id;
		$state   = isset( $site->connection_state ) ? (string) $site->connection_state : '';

		if ( 'disconnected' !== $site->status && RW_Connection_State::CONNECTED !== $state ) {
			echo '<form method="post" style="margin-bottom:8px;">';
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
			echo '<input type="hidden" name="rw_portal_action" value="connect_existing" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button button-primary">Connect WordPress</button>';
			echo '</form>';
		}

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
		echo '<input type="hidden" name="rw_portal_action" value="connect_wordpress" />';
		echo '<input type="hidden" name="subscription_id" value="' . esc_attr( $subscription_id ) . '" />';
		echo '<p><label>Site URL<br /><input type="url" name="site_url" required placeholder="https://example.com" /></label></p>';
		echo '<p><label>Site Name (optional)<br /><input type="text" name="site_name" /></label></p>';
		echo '<p><label>Report Email (optional)<br /><input type="email" name="report_email" /></label></p>';
		echo '<p><label><input type="checkbox" name="report_enabled" value="1" checked /> Enable reports</label></p>';
		echo '<p><button type="submit" class="button button-primary">Connect WordPress</button></p>';
		echo '<p>No WordPress password required.</p>';
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
			case 'connect_wordpress':
				self::handle_connect_wordpress( $user_id );
				break;
			case 'connect_existing':
				self::handle_connect_existing( $user_id );
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
			case 'request_manual_setup':
				self::handle_manual_setup( $user_id );
				break;
		}
	}

	private static function handle_connect_wordpress( $user_id ) {
		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$site_url        = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		$details         = array(
			'site_name'      => isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '',
			'report_email'   => isset( $_POST['report_email'] ) ? sanitize_email( wp_unslash( $_POST['report_email'] ) ) : '',
			'report_enabled' => ! empty( $_POST['report_enabled'] ),
		);
		$result          = RW_Connection_Orchestrator::start( $user_id, $subscription_id, $site_url, 0, $details );
		if ( is_wp_error( $result ) ) {
			self::add_notice( $result->get_error_message(), 'error' );
		}
	}

	private static function handle_connect_existing( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$result = RW_Connection_Orchestrator::start( $user_id, (int) $site->subscription_id, (string) $site->site_url, (int) $site->id );
		if ( is_wp_error( $result ) ) {
			self::add_notice( $result->get_error_message(), 'error' );
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
		if ( $allowed_sites <= 0 || $site_count >= $allowed_sites ) {
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

	private static function handle_manual_setup( $user_id ) {
		$site = self::load_site_for_user( $user_id );
		if ( ! $site ) {
			return;
		}

		$result = RW_Temporary_Access::request( $site, $user_id );
		if ( is_wp_error( $result ) ) {
			self::add_notice( $result->get_error_message(), 'error' );
			return;
		}

		if ( 'pending' === $result ) {
			self::add_notice( 'We already have your request. ReactWoo will finish connecting this website.', 'notice' );
			return;
		}

		self::add_notice( 'We received your request. ReactWoo will finish connecting this website.', 'success' );
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
