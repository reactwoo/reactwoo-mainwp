<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Account {
	const ENDPOINT = 'maintenance';

	private static $token_messages = array();

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'shape_menu_late' ), 30 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block_unlicensed_endpoint' ), 9 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download_site_plugin' ) );
	}

	/**
	 * WooCommerce only treats a rewrite endpoint as an account endpoint after
	 * it is present in this list. Without it, is_wc_endpoint_url() is false
	 * and the maintenance stylesheet never loads.
	 *
	 * @param array<string,string> $vars Query vars.
	 * @return array<string,string>
	 */
	public static function add_query_var( $vars ) {
		if ( ! is_array( $vars ) ) {
			$vars = array();
		}
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public static function enqueue_assets() {
		if ( ! self::is_maintenance_request() ) {
			return;
		}

		self::enqueue_maintenance_style();
		self::enqueue_support_offer_script();
	}

	public static function enqueue_support_offer_script() {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		wp_enqueue_script(
			'rw-portal-support-offer',
			RW_PORTAL_URL . 'assets/js/account-support-offer.js',
			array(),
			RW_PORTAL_VERSION,
			true
		);
	}

	/**
	 * True on the maintenance account screen.
	 *
	 * is_wc_endpoint_url() needs the query var above. The account-page check
	 * still matches when that helper is unavailable and the query var is set,
	 * including the empty value WooCommerce uses for an endpoint with no extra path.
	 *
	 * @return bool
	 */
	public static function is_maintenance_request() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) ) {
			return true;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}
		if ( ! function_exists( 'get_query_var' ) ) {
			return false;
		}

		$missing = '__rw_query_var_missing__';
		return $missing !== get_query_var( self::ENDPOINT, $missing );
	}

	/**
	 * Stylesheet version is the file's modification time so a CSS edit is fetched
	 * without waiting for the plugin version to change.
	 *
	 * @return string
	 */
	public static function maintenance_style_version() {
		$dir  = defined( 'RW_PORTAL_DIR' ) ? RW_PORTAL_DIR : dirname( __DIR__ ) . '/';
		$path = $dir . 'assets/css/account-maintenance.css';
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		if ( $mtime ) {
			return (string) $mtime;
		}

		return defined( 'RW_PORTAL_VERSION' ) ? RW_PORTAL_VERSION : '0.5.6';
	}

	public static function enqueue_maintenance_style() {
		$handle = 'rw-portal-maintenance';
		$done   = function_exists( 'wp_style_is' ) && wp_style_is( $handle, 'done' );
		if ( ! $done && ( ! function_exists( 'wp_style_is' ) || ! wp_style_is( $handle, 'enqueued' ) ) ) {
			wp_enqueue_style(
				$handle,
				RW_PORTAL_URL . 'assets/css/account-maintenance.css',
				array(),
				self::maintenance_style_version()
			);
		}

		$js_handle = 'rw-portal-maintenance-theme';
		$js_path   = ( defined( 'RW_PORTAL_DIR' ) ? RW_PORTAL_DIR : dirname( __DIR__ ) . '/' ) . 'assets/js/account-maintenance-theme.js';
		$js_ver    = file_exists( $js_path ) ? (string) filemtime( $js_path ) : ( defined( 'RW_PORTAL_VERSION' ) ? RW_PORTAL_VERSION : '0.5.6' );
		if ( function_exists( 'wp_enqueue_script' ) && ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( $js_handle, 'enqueued' ) ) ) {
			wp_enqueue_script(
				$js_handle,
				RW_PORTAL_URL . 'assets/js/account-maintenance-theme.js',
				array(),
				$js_ver,
				true
			);
			if ( function_exists( 'wp_localize_script' ) ) {
				wp_localize_script(
					$js_handle,
					'rwMaintTheme',
					array(
						'ajaxUrl' => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
						'nonce'   => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'rw_portal_maint_theme' ) : '',
					)
				);
			}
		}

		$late = function_exists( 'did_action' ) && ( did_action( 'wp_head' ) || did_action( 'wp_footer' ) );
		if ( $late && function_exists( 'wp_print_styles' ) && ( ! function_exists( 'wp_style_is' ) || ! wp_style_is( $handle, 'done' ) ) ) {
			wp_print_styles( $handle );
		}
		if ( $late && function_exists( 'wp_print_scripts' ) && function_exists( 'wp_enqueue_script' ) && ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( $js_handle, 'done' ) ) ) {
			wp_print_scripts( $js_handle );
		}
	}

	/**
	 * @param int $user_id
	 * @return string light|dark|system
	 */
	public static function maintenance_theme_pref( $user_id ) {
		$meta = $user_id ? get_user_meta( (int) $user_id, 'rw_maint_theme', true ) : '';
		if ( in_array( $meta, array( 'light', 'dark', 'system' ), true ) ) {
			return $meta;
		}
		return 'system';
	}

	/**
	 * @param string $pref
	 * @return string light|dark
	 */
	public static function maintenance_theme_effective( $pref ) {
		if ( 'dark' === $pref ) {
			return 'dark';
		}
		if ( 'light' === $pref ) {
			return 'light';
		}
		# Server cannot know prefers-color-scheme; default light and let JS correct.
		return 'light';
	}

	/**
	 * Persist dashboard theme preference.
	 *
	 * @return void
	 */
	public static function ajax_maint_theme() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'auth' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rw_portal_maint_theme' ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		$theme = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';
		if ( ! in_array( $theme, array( 'light', 'dark', 'system' ), true ) ) {
			wp_send_json_error( array( 'message' => 'theme' ), 400 );
		}
		update_user_meta( get_current_user_id(), 'rw_maint_theme', $theme );
		wp_send_json_success( array( 'theme' => $theme ) );
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
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		return self::shape_menu_for_user( $items, null );
	}

	/**
	 * Runs after other account plugins so a hidden Maintenance link cannot be put back,
	 * and Products & licences follows licensed records when that catalogue is available.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function shape_menu_late( $items ) {
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$licences = null;
		if ( function_exists( 'reactwoo_api_manager_get_customer_account_records' ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$records  = reactwoo_api_manager_get_customer_account_records( get_current_user_id() );
			$licences = is_array( $records ) && ! empty( $records );
		}

		return self::shape_menu_for_user( $items, $licences );
	}

	/**
	 * @param array     $items Menu items.
	 * @param bool|null $licences Licensed-product flag, or null to leave that item alone.
	 * @return array
	 */
	public static function shape_menu_for_user( array $items, $licences ) {
		if ( ! class_exists( 'RW_Account_Access' ) ) {
			return $items;
		}

		return RW_Account_Access::shape_menu( $items, self::user_has_maintenance_for_menu( get_current_user_id() ), $licences );
	}

	/**
	 * Refuse Products & licences when the API Manager reports no licensed records.
	 * Hosting endpoints are not handled here.
	 *
	 * @return void
	 */
	public static function maybe_block_unlicensed_endpoint() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'license' ) ) {
			return;
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'reactwoo_api_manager_get_customer_account_records' ) || ! class_exists( 'RW_Account_Access' ) ) {
			return;
		}
		$records = reactwoo_api_manager_get_customer_account_records( get_current_user_id() );
		$count   = is_array( $records ) ? count( $records ) : 0;
		if ( RW_Account_Access::licences_endpoint_allowed( $count ) ) {
			return;
		}
		if ( function_exists( 'status_header' ) ) {
			status_header( 403 );
		}
		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'Products and licences are not on this account.', 'reactwoo-support-portal' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Whether this customer should see the Maintenance account menu item.
	 * Direct endpoint access uses the same subscription set.
	 *
	 * @param int $user_id WordPress user id.
	 * @return bool
	 */
	public static function user_has_maintenance_for_menu( $user_id ) {
		return ! empty( self::entitled_subscription_ids( $user_id ) );
	}

	/**
	 * Maintenance subscriptions this customer may open.
	 *
	 * @param int $user_id Customer.
	 * @return int[]
	 */
	public static function entitled_subscription_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) || ! class_exists( 'RW_Support_Offers' ) || ! class_exists( 'RW_Account_Access' ) ) {
			return array();
		}
		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( ! is_array( $subscriptions ) ) {
			return array();
		}

		$ids = array();
		$now = time();
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_status' ) || ! method_exists( $subscription, 'get_id' ) ) {
				continue;
			}
			$product_ids = RW_Support_Offers::product_ids_from_subscription( $subscription );
			$maintenance = false;
			foreach ( $product_ids as $product_id ) {
				if ( in_array( (int) $product_id, RW_Support_Offers::MAINTENANCE_PRODUCT_IDS, true ) ) {
					$maintenance = true;
					break;
				}
			}
			$end       = method_exists( $subscription, 'get_time' ) ? (int) $subscription->get_time( 'end' ) : 0;
			$lifecycle = RW_Account_Access::lifecycle( (string) $subscription->get_status(), $maintenance, $end, $now );
			if ( RW_Account_Access::menu_visible( $lifecycle ) ) {
				$ids[] = (int) $subscription->get_id();
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param int $user_id Customer.
	 * @param int $selected_id Endpoint subscription id.
	 * @return array{allow:bool,status:int,code:string,message:string}
	 */
	public static function endpoint_response( $user_id, $selected_id ) {
		if ( ! class_exists( 'RW_Account_Access' ) ) {
			return array(
				'allow'   => false,
				'status'  => 403,
				'code'    => 'no_plan',
				'message' => 'Maintenance is not on this account.',
			);
		}

		return RW_Account_Access::endpoint_decision( $user_id, $selected_id, self::entitled_subscription_ids( $user_id ) );
	}

	public static function render() {
		self::enqueue_maintenance_style();

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			echo '<p>Please log in to manage maintenance.</p>';
			return;
		}

		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			echo '<p>WooCommerce Subscriptions is required to manage maintenance plans.</p>';
			return;
		}

		$decision = self::endpoint_response( $user_id, self::selected_subscription_id() );
		if ( empty( $decision['allow'] ) ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( (int) $decision['status'] );
			}
			$code    = isset( $decision['code'] ) ? (string) $decision['code'] : 'no_plan';
			$message = isset( $decision['message'] ) ? (string) $decision['message'] : 'Maintenance is not on this account.';
			$code    = function_exists( 'esc_attr' ) ? esc_attr( $code ) : htmlspecialchars( $code, ENT_QUOTES, 'UTF-8' );
			$message = function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' );
			echo '<p class="rw-maint-denied" data-access="' . $code . '">' . $message . '</p>';
			return;
		}

		self::handle_actions();

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

		$blocks = array();
		foreach ( $subscriptions as $subscription ) {
			$blocks[] = array(
				'subscription' => $subscription,
				'view'         => self::view_model( $subscription, $user_id ),
			);
		}

		$blocks   = self::sort_subscription_blocks( $blocks );
		$decision = self::blocks_for_request( $blocks, self::selected_subscription_id() );

		$theme_pref = self::maintenance_theme_pref( $user_id );
		$theme_eff  = self::maintenance_theme_effective( $theme_pref );
		echo '<div class="rw-maint" data-theme="' . esc_attr( $theme_eff ) . '" data-theme-pref="' . esc_attr( $theme_pref ) . '">';
		if ( 'index' === $decision['mode'] ) {
			$rw_sites = self::site_index_cards( $decision['blocks'] );
			include RW_PORTAL_DIR . 'templates/account-sites.php';
		} else {
			foreach ( $decision['blocks'] as $block ) {
				$view = $block['view'];
				if ( count( $blocks ) > 1 ) {
					$view['switcher'] = self::switcher_model( $blocks, $view['subscription_id'] );
					$view['others']   = self::other_subscriptions( $blocks, $view['subscription_id'] );
				}
				self::render_subscription( $block['subscription'], $user_id, $view );
			}
		}
		echo '</div>';
	}

	/**
	 * More than one plan, and no plan selected, opens the site chooser.
	 *
	 * @param int $count Number of maintenance subscriptions.
	 * @param int $selected_id Selected subscription id, or 0.
	 * @return bool
	 */
	public static function show_site_index( $count, $selected_id ) {
		return (int) $count > 1 && (int) $selected_id < 1;
	}

	/**
	 * @return int
	 */
	public static function selected_subscription_id() {
		return absint( get_query_var( self::ENDPOINT ) );
	}

	/**
	 * @param int $subscription_id Subscription id, or 0 for the chooser.
	 * @return string
	 */
	public static function maintenance_url( $subscription_id = 0 ) {
		$base = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/my-account/maintenance/' );
		$base = trailingslashit( $base );
		$subscription_id = absint( $subscription_id );
		if ( $subscription_id > 0 ) {
			return $base . $subscription_id . '/';
		}

		return $base;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Subscription blocks.
	 * @param int                            $selected_id Selected subscription.
	 * @return array{mode:string,blocks:array<int,array<string,mixed>>}
	 */
	public static function blocks_for_request( array $blocks, $selected_id ) {
		$selected_id = (int) $selected_id;
		if ( self::show_site_index( count( $blocks ), $selected_id ) ) {
			return array(
				'mode'   => 'index',
				'blocks' => $blocks,
			);
		}
		if ( $selected_id > 0 ) {
			$matched = array();
			foreach ( $blocks as $block ) {
				$view_id = isset( $block['view']['subscription_id'] ) ? (int) $block['view']['subscription_id'] : 0;
				if ( $view_id === $selected_id ) {
					$matched[] = $block;
				}
			}
			if ( ! empty( $matched ) ) {
				return array(
					'mode'   => 'dashboard',
					'blocks' => $matched,
				);
			}
			if ( count( $blocks ) > 1 ) {
				return array(
					'mode'   => 'index',
					'blocks' => $blocks,
				);
			}
		}

		return array(
			'mode'   => 'dashboard',
			'blocks' => $blocks,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Subscription blocks.
	 * @param int                            $current_id Current subscription.
	 * @return array<string,mixed>
	 */
	public static function switcher_model( array $blocks, $current_id ) {
		$current_id = (int) $current_id;
		$sites      = array();
		$current    = null;
		foreach ( $blocks as $block ) {
			$view = isset( $block['view'] ) && is_array( $block['view'] ) ? $block['view'] : array();
			$id   = isset( $view['subscription_id'] ) ? (int) $view['subscription_id'] : 0;
			$site = array(
				'id'        => $id,
				'host'      => self::subscription_host( $view ),
				'plan'      => isset( $view['plan_name'] ) ? (string) $view['plan_name'] : '',
				'status'    => isset( $view['status_label'] ) ? (string) $view['status_label'] : '',
				'state'     => isset( $view['state'] ) ? (string) $view['state'] : '',
				'attention' => self::attention_count( $view ),
				'url'       => self::maintenance_url( $id ),
				'current'   => $id === $current_id,
			);
			if ( $site['current'] ) {
				$current = $site;
			}
			$sites[] = $site;
		}

		return array(
			'count'     => count( $sites ),
			'index_url' => self::maintenance_url( 0 ),
			'current'   => $current,
			'sites'     => $sites,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Subscription blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function site_index_cards( array $blocks ) {
		$cards = array();
		foreach ( $blocks as $block ) {
			$view      = isset( $block['view'] ) && is_array( $block['view'] ) ? $block['view'] : array();
			$state     = isset( $view['state'] ) ? (string) $view['state'] : '';
			$connected = 'connected' === $state;
			$summary   = ( $connected && isset( $view['maintenance']['summary'] ) && is_array( $view['maintenance']['summary'] ) ) ? $view['maintenance']['summary'] : array();
			$flags     = ( $connected && isset( $summary['flags'] ) && is_array( $summary['flags'] ) ) ? $summary['flags'] : array();
			if ( ! $connected && in_array( $state, array( 'setup', 'attention', 'connecting' ), true ) ) {
				$flags = array( 'Connect the plugin to start checks' );
			}
			$cards[] = array(
				'host'              => self::subscription_host( $view ),
				'plan'              => isset( $view['plan_name'] ) ? (string) $view['plan_name'] : '',
				'renews'            => isset( $view['renews_on'] ) ? (string) $view['renews_on'] : '',
				'status'            => isset( $view['status_label'] ) ? (string) $view['status_label'] : '',
				'state'             => $state,
				'updates'           => $connected && isset( $summary['updates_label'] ) ? (string) $summary['updates_label'] : 'Not checked yet',
				'updates_attention' => $connected && ! empty( $summary['updates_attention'] ),
				'backup'            => $connected && isset( $summary['backup_label'] ) ? (string) $summary['backup_label'] : 'Not available yet',
				'flags'             => $flags,
				'url'               => self::maintenance_url( isset( $view['subscription_id'] ) ? $view['subscription_id'] : 0 ),
				'connected'         => $connected,
			);
		}

		return $cards;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Subscription blocks.
	 * @param int                            $current_id Current subscription.
	 * @return array<int,array<string,string>>
	 */
	private static function other_subscriptions( array $blocks, $current_id ) {
		$current_id = (int) $current_id;
		$others     = array();
		foreach ( $blocks as $block ) {
			$view = isset( $block['view'] ) && is_array( $block['view'] ) ? $block['view'] : array();
			$id   = isset( $view['subscription_id'] ) ? (int) $view['subscription_id'] : 0;
			if ( $id === $current_id ) {
				continue;
			}
			$others[] = array(
				'host'   => self::subscription_host( $view ),
				'plan'   => isset( $view['plan_name'] ) ? (string) $view['plan_name'] : '',
				'status' => isset( $view['status_label'] ) ? (string) $view['status_label'] : '',
				'url'    => self::maintenance_url( $id ),
			);
		}

		return $others;
	}

	/**
	 * @param array<string,mixed> $view Subscription view.
	 * @return int
	 */
	private static function attention_count( array $view ) {
		if ( isset( $view['maintenance']['flags'] ) && is_array( $view['maintenance']['flags'] ) ) {
			return count( $view['maintenance']['flags'] );
		}
		if ( isset( $view['state'] ) && 'connected' !== $view['state'] ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Connected plans stay expanded at the top. Later states keep their relative order.
	 *
	 * @param array<int,array<string,mixed>> $blocks Subscription blocks with a view.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_subscription_blocks( array $blocks ) {
		$ranked = array();
		foreach ( array_values( $blocks ) as $index => $block ) {
			$state    = ( isset( $block['view']['state'] ) ) ? (string) $block['view']['state'] : '';
			$ranked[] = array(
				'block' => $block,
				'index' => $index,
				'rank'  => 'connected' === $state ? 0 : 1,
			);
		}

		usort( $ranked, array( __CLASS__, 'compare_subscription_blocks' ) );

		$ordered = array();
		foreach ( $ranked as $row ) {
			$ordered[] = $row['block'];
		}
		return $ordered;
	}

	private static function compare_subscription_blocks( $a, $b ) {
		if ( $a['rank'] === $b['rank'] ) {
			if ( $a['index'] === $b['index'] ) {
				return 0;
			}
			return $a['index'] < $b['index'] ? -1 : 1;
		}
		return $a['rank'] < $b['rank'] ? -1 : 1;
	}

	private static function render_subscription( $subscription, $user_id, $rw_view = null ) {
		if ( null === $rw_view ) {
			$rw_view = self::view_model( $subscription, $user_id );
		}
		$partials = array(
			'connecting'         => 'account-connecting.php',
			'attention'          => 'account-attention.php',
			'connected'          => 'account-dashboard.php',
			'paused'             => 'account-paused.php',
			'disconnected'       => 'account-paused.php',
			'pending_activation' => 'account-paused.php',
			'payment_failed'     => 'account-paused.php',
			'expired'            => 'account-paused.php',
		);
		$file    = isset( $partials[ $rw_view['state'] ] ) ? $partials[ $rw_view['state'] ] : 'account-onboarding.php';
		$host    = self::subscription_host( $rw_view );
		$classes = array( 'rw-maint-plan-block' );
		if ( 'setup' === $rw_view['state'] ) {
			$classes[] = 'rw-maint-plan-block--onboarding';
		}
		if ( 'attention' === $rw_view['state'] ) {
			$classes[] = 'rw-maint-plan-block--attention';
		}

		if ( 'connected' === $rw_view['state'] ) {
			$classes[] = 'rw-maint-plan-block--dashboard';
		}

		echo '<article class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		if ( 'attention' !== $rw_view['state'] && 'connected' !== $rw_view['state'] ) {
			echo '<header class="rw-maint-plan-block-heading">';
			echo '<h2>' . esc_html( $host ) . '</h2>';
			echo '<p>' . esc_html( $rw_view['plan_name'] ) . '</p>';
			echo '</header>';
		}
		include RW_PORTAL_DIR . 'templates/' . $file;
		echo '</article>';
	}

	/**
	 * @param object $subscription WooCommerce subscription.
	 * @return string
	 */
	private static function renews_label( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_date' ) ) {
			return '';
		}
		$raw = (string) $subscription->get_date( 'next_payment' );
		if ( '' === $raw ) {
			$raw = (string) $subscription->get_date( 'end' );
		}
		try {
			$moment = new DateTimeImmutable( $raw );
		} catch ( Exception $e ) {
			return '';
		}
		if ( function_exists( 'wp_timezone' ) ) {
			$zone = wp_timezone();
			if ( $zone instanceof DateTimeZone ) {
				$moment = $moment->setTimezone( $zone );
			}
		}

		return $moment->format( 'j M Y' );
	}

	private static function subscription_host( $rw_view ) {
		$site = ( isset( $rw_view['site'] ) && is_object( $rw_view['site'] ) ) ? $rw_view['site'] : null;
		$host = ( $site && ! empty( $site->site_url ) ) ? wp_parse_url( (string) $site->site_url, PHP_URL_HOST ) : '';
		return $host ? $host : 'your website';
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

		$signed = self::connector_signed_download_url();
		if ( '' !== $signed ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Content-Type-Options: nosniff' );
			wp_redirect( $signed, 302 );
			exit;
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

	/**
	 * Short-lived package URL from api.reactwoo.com.
	 *
	 * Empty when the service does not return a public HTTPS address, and when
	 * the zip shipped with this portal is a newer connector than the API.
	 *
	 * @return string
	 */
	private static function connector_signed_download_url() {
		$package = self::connector_update_package();
		if ( ! is_array( $package ) || '' === $package['url'] ) {
			return '';
		}

		$local = self::connector_zip_version( RW_PORTAL_DIR . 'downloads/reactwoo-site-plugin.zip' );
		if ( self::connector_download_prefers_local( $package['version'], $local ) ) {
			return '';
		}

		return $package['url'];
	}

	/**
	 * @return array{url:string,version:string}|null
	 */
	private static function connector_update_package() {
		$slug = 'reactwoo-support-connector';
		$urls = array(
			add_query_arg(
				array(
					'slug'    => $slug,
					'channel' => 'stable',
				),
				'https://api.reactwoo.com/api/v5/updates'
			),
		);

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 12,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
			$package = self::package_from_updates_response( $response );
			if ( is_array( $package ) ) {
				return $package;
			}
		}

		$response = wp_remote_post(
			'https://api.reactwoo.com/api/v5/updates/check',
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'slug'            => $slug,
						'current_version' => '0.0.0',
						'channel'         => 'stable',
						'site_host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					)
				),
			)
		);

		return self::package_from_updates_response( $response );
	}

	/**
	 * Use the portal zip when it is newer than the published connector.
	 *
	 * A missing version on either side keeps the signed URL, when one exists.
	 *
	 * @param string $api_version Version advertised by api.reactwoo.com.
	 * @param string $local_version Version inside the local zip.
	 * @return bool
	 */
	public static function connector_download_prefers_local( $api_version, $local_version ) {
		$api_version   = trim( (string) $api_version );
		$local_version = trim( (string) $local_version );
		if ( '' === $api_version || '' === $local_version ) {
			return false;
		}

		return version_compare( $local_version, $api_version, '>' );
	}

	/**
	 * Plugin header version from a connector zip.
	 *
	 * @param string $file Zip path.
	 * @return string
	 */
	public static function connector_zip_version( $file ) {
		if ( ! is_string( $file ) || ! is_readable( $file ) || ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return '';
		}

		$version = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( ! preg_match( '#(^|/)reactwoo-support-connector\.php$#', $name ) ) {
				continue;
			}
			$contents = $zip->getFromIndex( $i );
			if ( is_string( $contents ) && preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)/m', $contents, $matches ) ) {
				$version = $matches[1];
				break;
			}
		}
		$zip->close();

		return $version;
	}

	/**
	 * @param mixed $response HTTP API response.
	 * @return array{url:string,version:string}|null
	 */
	private static function package_from_updates_response( $response ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$url = '';
		foreach ( array( 'download_url', 'signed_url', 'url' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$url = $data[ $key ];
				break;
			}
		}

		$url = self::public_download_redirect( $url );
		if ( '' === $url ) {
			return null;
		}

		$version = '';
		if ( ! empty( $data['version'] ) && is_string( $data['version'] ) ) {
			$version = trim( $data['version'] );
		}

		return array(
			'url'     => $url,
			'version' => $version,
		);
	}

	/**
	 * Keep the query string. Signed package URLs are useless without it.
	 *
	 * @param string $url Candidate redirect.
	 * @return string
	 */
	private static function public_download_redirect( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return '';
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		if ( RW_Site_Url::is_blocked_host( $host ) ) {
			return '';
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && RW_Site_Url::is_blocked_ip( $host ) ) {
			return '';
		}

		return trim( (string) $url );
	}

	public static function render_connect_form( $subscription_id, $compact = false ) {
		echo '<form class="rw-maint-form" method="post">';
		self::action_fields();
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
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="connect_existing" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<button type="submit" class="rw-maint-button">Connect WordPress</button>';
		echo '</form>';
	}

	public static function render_replace_connection_form( $site ) {
		echo '<form class="rw-maint-form" method="post">';
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="connect_existing" />';
		echo '<input type="hidden" name="takeover" value="1" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<button type="submit" class="rw-maint-button">Replace existing maintenance connection</button>';
		echo '</form>';
		echo '<p class="rw-maint-helper">This replaces the maintenance link already on the website with ReactWoo. Your WordPress password is not stored.</p>';
	}

	public static function render_report_preferences( $site ) {
		$site_id = (int) $site->id;
		$email   = isset( $site->report_email ) ? (string) $site->report_email : '';
		$enabled = ! isset( $site->report_enabled ) || (int) $site->report_enabled;
		echo '<form class="rw-maint-form" method="post">';
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="update_reporting" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<label for="rw-report-recipients-' . esc_attr( $site_id ) . '">Report recipients</label>';
		echo '<textarea id="rw-report-recipients-' . esc_attr( $site_id ) . '" name="report_recipients" rows="3" required placeholder="you@example.com, ops@example.com">' . esc_textarea( $email ) . '</textarea>';
		echo '<p class="rw-maint-helper">Add one or more emails, separated by commas. The first address is the primary contact on the maintenance hub.</p>';
		echo '<label class="rw-maint-check"><input type="checkbox" name="report_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> Send maintenance summaries</label>';
		echo '<button type="submit" class="rw-maint-button">Save recipients</button>';
		echo '</form>';
	}

	public static function render_manual_request( $site ) {
		echo '<form class="rw-maint-form" method="post">';
		self::action_fields();
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
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="disconnect_site" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( (int) $site->id ) . '" />';
		echo '<button type="submit" class="rw-maint-button-secondary">Disconnect website</button>';
		echo '</form>';
	}

	private static function subscription_can_connect( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_status' ) || ! class_exists( 'RW_Account_Access' ) ) {
			return false;
		}
		$end       = method_exists( $subscription, 'get_time' ) ? (int) $subscription->get_time( 'end' ) : 0;
		$lifecycle = RW_Account_Access::lifecycle( (string) $subscription->get_status(), true, $end, time() );
		return RW_Account_Access::can_connect( $lifecycle );
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

		$end       = method_exists( $subscription, 'get_time' ) ? (int) $subscription->get_time( 'end' ) : 0;
		$lifecycle = class_exists( 'RW_Account_Access' )
			? RW_Account_Access::lifecycle( (string) $subscription->get_status(), true, $end, time() )
			: 'active';
		$paid_through_label = '';
		if ( 'cancelled_paid_through' === $lifecycle && $end > 0 ) {
			$paid_through_label = function_exists( 'date_i18n' ) ? date_i18n( 'j M Y', $end ) : gmdate( 'j M Y', $end );
		}
		$lifecycle_message = class_exists( 'RW_Account_Access' ) ? RW_Account_Access::lifecycle_message( $lifecycle, $paid_through_label ) : '';
		if ( '' !== $lifecycle_message && 'active' !== $lifecycle ) {
			$message = $lifecycle_message;
		} elseif ( ! self::subscription_can_connect( $subscription ) ) {
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
		$included_remaining = null;
		$included_granted   = $minutes;
		$purchased_remaining = 0;
		$show_included      = $minutes > 0;
		if ( ! is_wp_error( $entitlement ) && class_exists( 'RW_Support_Allowance' ) ) {
			RW_Support_Allowance::grant_subscription( $subscription );
			$balances            = RW_Support_Ledger::balances( $subscription_id );
			$period              = RW_Support_Balance::period_containing( (string) $subscription->get_date( 'start' ), current_time( 'mysql' ) );
			$included_granted    = $period ? RW_Support_Balance::current_grant( $balances['rows'], $period['start_date'] ) : 0;
			$included_remaining  = (int) $balances['included'];
			$purchased_remaining = (int) $balances['purchased'];
			$show_included       = $included_granted > 0;
		}

		return array(
			'subscription_id' => $subscription_id,
			'plan_name'       => $plan_name,
			'minutes'         => $minutes,
			'included_minutes_remaining' => $included_remaining,
			'included_minutes_granted'   => $included_granted,
			'purchased_minutes_remaining' => $purchased_remaining,
			'show_included_minutes'      => $show_included,
			'site'            => $site,
			'sites'           => self::site_summaries( $sites ),
			'state'           => $state,
			'lifecycle'       => $lifecycle,
			'status_label'    => self::state_label( $state, $site ),
			'heading'         => class_exists( 'RW_Account_Access' ) ? RW_Account_Access::heading( $state ) : '',
			'message'         => $message,
			'subscriptions_url' => self::subscriptions_url(),
			'lifecycle_notice' => ( 'connected' === $state && 'cancelled_paid_through' === $lifecycle ) ? $lifecycle_message : '',
			'can_connect'     => $can_connect,
			'backup_label'    => '' !== $backup ? ucfirst( $backup ) : 'Included on your plan',
			'last_seen'       => ( $site && ! empty( $site->last_seen ) ) ? self::format_datetime( $site->last_seen ) : '',
			'support_mailto'  => $support ? 'mailto:' . $support . '?subject=' . rawurlencode( 'Maintenance support' ) : '',
			'progress'        => self::progress_steps( $site ),
			'plan_points'     => self::plan_points( $plan_name, $show_included, $included_granted ),
			'benefits'        => self::benefit_cards( $reports_on, $speed_on ),
			'reports'         => self::report_cards( $reports_on, $speed_on ),
			'manual_pending'  => $site && class_exists( 'RW_Temporary_Access' ) && RW_Temporary_Access::is_pending( $site ),
			'replace_connection' => $site && isset( $site->connection_error_code ) && 'existing_connection' === $site->connection_error_code,
			'maintenance'     => ( 'connected' === $state && $site && class_exists( 'RW_Portal_Maintenance_Status' ) ) ? RW_Portal_Maintenance_Status::view_for_site( $site ) : null,
			'renews_on'       => self::renews_label( $subscription ),
			'support_offer'   => ( 'connected' === $state && class_exists( 'RW_Support_Offers' ) ) ? RW_Support_Offers::card_for_subscription( $subscription, $user_id, $site ) : null,
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

	private static function subscriptions_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'subscriptions' );
		}

		return home_url( '/my-account/subscriptions/' );
	}

	private static function state_label( $state, $site ) {
		if ( class_exists( 'RW_Account_Access' ) && in_array( (string) $state, array( 'pending_activation', 'payment_failed', 'expired' ), true ) ) {
			return RW_Account_Access::status_label( $state );
		}
		if ( $site ) {
			return self::customer_status_label( $site );
		}
		if ( 'paused' === $state ) {
			return RW_Connection_State::LABEL_PAUSED;
		}

		return RW_Connection_State::LABEL_SETUP_REQUIRED;
	}

	private static function view_state( $subscription, $site ) {
		$end       = ( is_object( $subscription ) && method_exists( $subscription, 'get_time' ) ) ? (int) $subscription->get_time( 'end' ) : 0;
		$status    = ( is_object( $subscription ) && method_exists( $subscription, 'get_status' ) ) ? (string) $subscription->get_status() : '';
		$lifecycle = class_exists( 'RW_Account_Access' ) ? RW_Account_Access::lifecycle( $status, true, $end, time() ) : 'active';
		$site_status = ( $site && isset( $site->status ) ) ? (string) $site->status : '';
		$connection  = ( $site && isset( $site->connection_state ) ) ? (string) $site->connection_state : '';

		return class_exists( 'RW_Account_Access' )
			? RW_Account_Access::screen_for( $lifecycle, $site_status, $connection )
			: 'setup';
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
			RW_Connection_State::INSTALLING             => 2,
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

	private static function plan_points( $plan_name, $show_included, $included_granted ) {
		$support_title  = 'Technical support packs';
		$support_mobile = 'Technical support';
		$support_text   = 'Included minutes are not part of this plan';
		if ( $show_included ) {
			if ( 60 === (int) $included_granted ) {
				$support_title  = '1 hour of technical support';
				$support_mobile = '1 hour technical support';
			} else {
				$support_title  = (int) $included_granted . ' minutes of technical support';
				$support_mobile = $support_title;
			}
			$short_plan   = trim( (string) preg_replace( '/\s+maintenance$/i', '', (string) $plan_name ) );
			$support_text = '' !== $short_plan ? 'On ' . $short_plan . ', each billing period' : 'Each billing period';
		}

		return array(
			array( 'title' => 'Managed WordPress updates', 'text' => 'Core, themes and supported plugins' ),
			array( 'title' => 'Security checks', 'text' => 'For eligible plugins while subscribed' ),
			array( 'title' => 'Cloud backups', 'text' => 'Recovery points stored off-site' ),
			array(
				'title'        => 'Monthly summary report',
				'mobile_title' => 'Monthly reporting',
				'text'         => 'Speed, store status, changes and uptime',
			),
			array(
				'title'        => $support_title,
				'mobile_title' => $support_mobile,
				'text'         => $support_text,
			),
		);
	}

	private static function benefit_cards( $reports_on, $speed_on ) {
		unset( $reports_on, $speed_on );

		return array(
			array(
				'kicker'      => 'Updates',
				'title'       => 'Managed updates',
				'text'        => 'Routine WordPress, theme and supported plugin updates are handled and recorded.',
				'mobile_text' => 'WordPress, themes and supported plugins kept current.',
				'tone'        => 'blue',
			),
			array(
				'kicker'      => 'Security',
				'title'       => 'Security checks',
				'text'        => 'Routine checks help catch problems early.',
				'mobile_text' => 'Routine security checks on your site.',
				'tone'        => 'green',
			),
			array(
				'kicker'      => 'Backups',
				'title'       => 'Cloud backups',
				'text'        => 'Scheduled off-site backups make recovery possible if an update causes trouble.',
				'mobile_text' => 'Off-site recovery points for when something goes wrong.',
				'tone'        => 'purple',
			),
			array(
				'kicker'      => 'Reporting',
				'title'       => 'Monthly reports',
				'text'        => 'Availability, SSL and domain health, plus a scheduled database tidy.',
				'mobile_text' => 'Availability, SSL, domain health and a scheduled database tidy.',
				'tone'        => 'green',
			),
			array(
				'kicker'       => 'Security',
				'title'        => 'Security checks',
				'text'         => 'Vulnerability and security signals inform maintenance priorities.',
				'tone'         => 'green',
				'desktop_only' => true,
			),
			array(
				'kicker'       => 'Reporting',
				'title'        => 'Clear reporting',
				'mobile_title' => 'Reporting',
				'text'         => 'Page speed, store status, changes, updates and backups, in plain language.',
				'mobile_text'  => 'Speed, store status, changes, updates and backups.',
				'tone'         => 'blue',
			),
			array(
				'kicker'      => 'Page speed',
				'title'       => 'Page speed',
				'text'        => 'Google PageSpeed scores for mobile and desktop, when that connection is on.',
				'mobile_text' => 'Google PageSpeed scores when that connection is on.',
				'tone'        => 'blue',
			),
			array(
				'kicker'      => 'Store',
				'title'       => 'Store status',
				'text'        => 'Orders waiting, stock and this month\'s sales, when WooCommerce is on the site.',
				'mobile_text' => 'Orders, stock and sales when WooCommerce is on the site.',
				'tone'        => 'blue',
			),
			array(
				'kicker'      => 'Changes',
				'title'       => 'Change history',
				'text'        => 'Who changed the site, what they changed, and when.',
				'mobile_text' => 'Who changed the site, what changed, and when.',
				'tone'        => 'blue',
			),
		);
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
			self::action_fields();
			echo '<input type="hidden" name="rw_portal_action" value="connect_existing" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button button-primary">Connect WordPress</button>';
			echo '</form>';
		}

		echo '<form method="post" style="margin-bottom:8px;">';
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="update_reporting" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<input type="email" name="report_email" value="' . esc_attr( $site->report_email ) . '" placeholder="Report email" />';
		echo '<label style="margin-left:6px;"><input type="checkbox" name="report_enabled" value="1" ' . checked( (int) $site->report_enabled, 1, false ) . ' /> Reports</label>';
		echo '<button type="submit" class="button" style="margin-left:6px;">Save</button>';
		echo '</form>';

		echo '<form method="post" style="margin-bottom:8px;">';
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="resync_site" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<button type="submit" class="button">Resync Client Details</button>';
		echo '</form>';

		echo '<form method="post" style="margin-bottom:8px;">';
		self::action_fields();
		echo '<input type="hidden" name="rw_portal_action" value="toggle_url_override" />';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
		echo '<input type="hidden" name="override_value" value="' . esc_attr( (int) ! $site->enroll_url_override ) . '" />';
		$label = $site->enroll_url_override ? 'Disable URL Override' : 'Allow URL Override';
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';

		if ( in_array( $site->status, array( 'connected', 'suspended', 'error' ), true ) ) {
			echo '<form method="post" style="margin-bottom:8px;">';
			self::action_fields();
			echo '<input type="hidden" name="rw_portal_action" value="check_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Run Health Check</button>';
			echo '</form>';

			echo '<form method="post" style="margin-bottom:8px;">';
			self::action_fields();
			echo '<input type="hidden" name="rw_portal_action" value="sync_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Sync Now</button>';
			echo '</form>';
		}

		if ( in_array( $site->status, array( 'disconnected', 'error' ), true ) ) {
			echo '<form method="post" style="margin-bottom:8px;">';
			self::action_fields();
			echo '<input type="hidden" name="rw_portal_action" value="reconnect_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Reconnect Site</button>';
			echo '</form>';
		}

		if ( 'disconnected' !== $site->status ) {
			echo '<form method="post">';
			self::action_fields();
			echo '<input type="hidden" name="rw_portal_action" value="disconnect_site" />';
			echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '" />';
			echo '<button type="submit" class="button">Disconnect Site</button>';
			echo '</form>';
		}
	}

	private static function render_create_form( $subscription_id ) {
		echo '<h4>Add a Site</h4>';
		echo '<form method="post">';
		self::action_fields();
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

	/**
	 * Nonce plus a single-use token. A repeated POST is refused.
	 *
	 * @return void
	 */
	public static function action_fields() {
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'rw_portal_account_action', 'rw_portal_nonce' );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$token   = self::issue_action_token( $user_id );
		echo '<input type="hidden" name="rw_action_token" value="' . htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ) . '" />';
	}

	/**
	 * @param int $user_id Customer.
	 * @return string
	 */
	public static function issue_action_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return '';
		}
		set_transient( self::action_token_key( $user_id, $token ), 'open', 12 * 3600 );

		return $token;
	}

	/**
	 * @param int    $user_id Customer.
	 * @param string $token Form token.
	 * @return bool
	 */
	public static function action_token_is_open( $user_id, $token ) {
		$token = (string) $token;
		if ( (int) $user_id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}

		return 'open' === get_transient( self::action_token_key( $user_id, $token ) );
	}

	/**
	 * @param int    $user_id Customer.
	 * @param string $token Form token.
	 * @return bool
	 */
	public static function consume_action_token( $user_id, $token ) {
		if ( ! self::action_token_is_open( $user_id, $token ) ) {
			return false;
		}
		delete_transient( self::action_token_key( $user_id, $token ) );

		return true;
	}

	/**
	 * @param int    $user_id Customer.
	 * @param string $token Form token.
	 * @return string
	 */
	private static function action_token_key( $user_id, $token ) {
		return 'rw_portal_action_' . (int) $user_id . '_' . $token;
	}

	private static function handle_actions() {
		if ( empty( $_POST['rw_portal_action'] ) ) {
			return;
		}

		$user_id  = get_current_user_id();
		$nonce    = isset( $_POST['rw_portal_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rw_portal_nonce'] ) ) : '';
		$nonce_ok = function_exists( 'wp_verify_nonce' ) && '' !== $nonce && wp_verify_nonce( $nonce, 'rw_portal_account_action' );
		$token    = isset( $_POST['rw_action_token'] ) ? sanitize_text_field( wp_unslash( $_POST['rw_action_token'] ) ) : '';
		$token_ok = $nonce_ok && self::action_token_is_open( $user_id, $token );
		if ( $token_ok ) {
			self::consume_action_token( $user_id, $token );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		$site_id         = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
		$site_user_id    = 0;
		$site_sub_id     = 0;
		if ( $site_id > 0 && class_exists( 'RW_Sites' ) ) {
			$site = RW_Sites::get_site( $site_id );
			if ( is_object( $site ) ) {
				$site_user_id = isset( $site->user_id ) ? (int) $site->user_id : 0;
				$site_sub_id  = isset( $site->subscription_id ) ? (int) $site->subscription_id : 0;
			}
		}
		$owns = class_exists( 'RW_Account_Access' ) && RW_Account_Access::target_owned(
			$subscription_id,
			self::entitled_subscription_ids( $user_id ),
			$site_user_id,
			$site_sub_id,
			$user_id
		);
		$decision = class_exists( 'RW_Account_Access' )
			? RW_Account_Access::authorize_post(
				array(
					'user_id'     => $user_id,
					'nonce_ok'    => $nonce_ok,
					'token_ok'    => $token_ok,
					'entitled'    => self::user_has_maintenance_for_menu( $user_id ),
					'owns_target' => $owns,
				)
			)
			: array(
				'allow'   => false,
				'message' => 'Security check failed.',
			);
		if ( empty( $decision['allow'] ) ) {
			self::add_notice( isset( $decision['message'] ) ? (string) $decision['message'] : 'Security check failed.', 'error' );
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['rw_portal_action'] ) );

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
			case 'sync_site':
			case 'request_backup':
			case 'run_updates':
			case 'security_scan':
			case 'pause_maintenance':
			case 'resume_maintenance':
			case 'download_report':
				RW_Portal_Customer_Actions::handle( $user_id, $action );
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
				RW_Portal_Customer_Actions::handle_update_reporting( $user_id );
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

		$details = array();
		if ( isset( $_POST['takeover'] ) ) {
			$details['takeover'] = wp_unslash( $_POST['takeover'] );
		}
		$result = RW_Connection_Orchestrator::start( $user_id, (int) $site->subscription_id, (string) $site->site_url, (int) $site->id, $details );
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
