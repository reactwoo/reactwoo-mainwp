<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Sites_Admin {
	const MENU_SLUG = 'rw-portal-sites';
	const PER_PAGE  = 50;

	public static function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'tools.php',
			'ReactWoo Managed Sites',
			'ReactWoo Managed Sites',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['rw_export'] ) && '1' === $_GET['rw_export'] ) {
			if ( ! self::verify_export_nonce() ) {
				echo '<div class="notice notice-error"><p>Export link expired. Please try again.</p></div>';
			} else {
				self::export_csv();
				return;
			}
		}

		self::handle_actions();
		self::render_notice();

		$table = RW_DB::table( 'managed_sites' );
		if ( ! RW_DB::table_exists( $table ) ) {
			echo '<div class="notice notice-warning"><p>Managed sites table not found.</p></div>';
			return;
		}

		$filters = self::collect_filters();
		$page    = max( 1, absint( $filters['paged'] ) );
		$offset  = ( $page - 1 ) * self::PER_PAGE;

		list( $sites, $total ) = RW_Sites::query_sites( $filters, self::PER_PAGE, $offset );
		$total_pages            = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		echo '<div class="wrap">';
		echo '<h1>ReactWoo Managed Sites</h1>';
		self::render_filters( $filters );
		self::render_table( $sites );
		self::render_pagination( $page, $total_pages, $filters );
		echo '</div>';
	}

	private static function handle_actions() {
		if ( empty( $_GET['rw_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['rw_action'] ) );
		if ( 'toggle_override' !== $action ) {
			return;
		}

		$site_id = isset( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0;
		if ( ! $site_id ) {
			self::redirect_notice( 'missing_site' );
		}

		$nonce = isset( $_GET['rw_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['rw_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rw_toggle_override_' . $site_id ) ) {
			self::redirect_notice( 'nonce_failed' );
		}

		$site = RW_Sites::get_site( $site_id );
		if ( ! $site ) {
			self::redirect_notice( 'missing_site' );
		}

		$new_value = $site->enroll_url_override ? 0 : 1;
		RW_Sites::set_url_override( $site_id, (bool) $new_value );

		RW_Audit::log(
			'url_override_toggled_admin',
			array(
				'user_id'         => get_current_user_id(),
				'subscription_id' => (int) $site->subscription_id,
				'managed_site_id' => (int) $site->id,
				'enabled'         => (int) $new_value,
			)
		);

		self::redirect_notice( $new_value ? 'override_on' : 'override_off' );
	}

	private static function redirect_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'rw_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	private static function render_notice() {
		if ( empty( $_GET['rw_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['rw_notice'] ) );
		$map    = array(
			'override_on'  => array( 'success', 'URL override enabled.' ),
			'override_off' => array( 'success', 'URL override disabled.' ),
			'nonce_failed' => array( 'error', 'Security check failed.' ),
			'missing_site' => array( 'error', 'Site not found.' ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $map[ $notice ];
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function render_filters( array $filters ) {
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<table class="form-table"><tbody>';
		self::render_filter_row( 'Status', 'status', $filters['status'] );
		self::render_filter_row( 'User ID', 'user_id', $filters['user_id'] );
		self::render_filter_row( 'Subscription ID', 'subscription_id', $filters['subscription_id'] );
		self::render_filter_row( 'Search (URL or Name)', 'search', $filters['search'] );
		echo '</tbody></table>';
		submit_button( 'Filter', 'secondary', '', false );
		self::render_export_button( $filters );
		echo '</form>';
	}

	private static function render_filter_row( $label, $name, $value ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" /></td></tr>';
	}

	private static function render_export_button( array $filters ) {
		$nonce = wp_create_nonce( 'rw_portal_sites_export' );
		$url = add_query_arg(
			array_merge(
				$filters,
				array(
					'page'            => self::MENU_SLUG,
					'rw_export'       => '1',
					'rw_export_nonce' => $nonce,
				)
			),
			admin_url( 'tools.php' )
		);

		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Export CSV</a></p>';
	}

	private static function render_table( array $sites ) {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>User</th><th>Subscription</th><th>Site</th><th>Status</th><th>Override</th><th>Last Seen</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $sites ) ) {
			echo '<tr><td colspan="8">No managed sites found.</td></tr>';
		} else {
			foreach ( $sites as $site ) {
				$nonce = wp_create_nonce( 'rw_toggle_override_' . (int) $site->id );
				$action_url = add_query_arg(
					array(
						'page'      => self::MENU_SLUG,
						'rw_action' => 'toggle_override',
						'site_id'   => (int) $site->id,
						'rw_nonce'  => $nonce,
					),
					admin_url( 'tools.php' )
				);

				$override_label = $site->enroll_url_override ? 'Disable' : 'Enable';

				echo '<tr>';
				echo '<td>' . esc_html( $site->id ) . '</td>';
				echo '<td>' . esc_html( $site->user_id ) . '</td>';
				echo '<td>' . esc_html( $site->subscription_id ) . '</td>';
				echo '<td><strong>' . esc_html( $site->site_name ) . '</strong><br /><small>' . esc_html( $site->site_url ) . '</small></td>';
				echo '<td>' . esc_html( $site->status ) . '</td>';
				echo '<td>' . ( $site->enroll_url_override ? 'Yes' : 'No' ) . '</td>';
				echo '<td>' . esc_html( $site->last_seen ? $site->last_seen : 'Never' ) . '</td>';
				echo '<td><a class="button" href="' . esc_url( $action_url ) . '">' . esc_html( $override_label ) . ' Override</a></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	private static function render_pagination( $page, $total_pages, array $filters ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base = add_query_arg(
			array_merge(
				$filters,
				array(
					'paged' => '%#%',
					'page'  => self::MENU_SLUG,
				)
			),
			admin_url( 'tools.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		echo '</div></div>';
	}

	private static function collect_filters() {
		return array(
			'status'          => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
			'user_id'         => isset( $_GET['user_id'] ) ? sanitize_text_field( wp_unslash( $_GET['user_id'] ) ) : '',
			'subscription_id' => isset( $_GET['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription_id'] ) ) : '',
			'search'          => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			'paged'           => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
		);
	}

	private static function export_csv() {
		$filters = self::collect_filters();
		list( $sites ) = RW_Sites::query_sites( $filters, 5000, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rw-managed-sites.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv(
			$output,
			array(
				'id',
				'user_id',
				'subscription_id',
				'site_name',
				'site_url',
				'status',
				'enroll_url_override',
				'last_seen',
				'created_at',
				'updated_at',
			)
		);

		foreach ( $sites as $site ) {
			fputcsv(
				$output,
				array(
					$site->id,
					$site->user_id,
					$site->subscription_id,
					$site->site_name,
					$site->site_url,
					$site->status,
					$site->enroll_url_override,
					$site->last_seen,
					$site->created_at,
					$site->updated_at,
				)
			);
		}

		fclose( $output );
		exit;
	}

	private static function verify_export_nonce() {
		if ( empty( $_GET['rw_export_nonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['rw_export_nonce'] ) ), 'rw_portal_sites_export' );
	}
}
