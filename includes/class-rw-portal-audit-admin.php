<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Audit_Admin {
	const MENU_SLUG = 'rw-portal-audit';
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
			'ReactWoo Portal Audit',
			'ReactWoo Portal Audit',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table = RW_DB::table( 'audit_log' );
		if ( ! RW_DB::table_exists( $table ) ) {
			echo '<div class="notice notice-warning"><p>Audit log table not found.</p></div>';
			return;
		}

		$filters = self::collect_filters();
		$page    = max( 1, absint( $filters['paged'] ) );
		$offset  = ( $page - 1 ) * self::PER_PAGE;

		list( $logs, $total ) = self::query_logs( $filters, self::PER_PAGE, $offset );
		$total_pages          = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		echo '<div class="wrap">';
		echo '<h1>ReactWoo Portal Audit Log</h1>';
		self::render_filters( $filters );
		self::render_table( $logs );
		self::render_pagination( $page, $total_pages, $filters );
		echo '</div>';
	}

	private static function render_filters( array $filters ) {
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<table class="form-table"><tbody>';
		self::render_filter_row( 'Event Type', 'event_type', $filters['event_type'] );
		self::render_filter_row( 'Action', 'action', $filters['action'] );
		self::render_filter_row( 'User ID', 'user_id', $filters['user_id'] );
		self::render_filter_row( 'Subscription ID', 'subscription_id', $filters['subscription_id'] );
		self::render_filter_row( 'Managed Site ID', 'managed_site_id', $filters['managed_site_id'] );
		self::render_filter_row( 'Date From (YYYY-MM-DD)', 'date_from', $filters['date_from'] );
		self::render_filter_row( 'Date To (YYYY-MM-DD)', 'date_to', $filters['date_to'] );
		echo '</tbody></table>';
		submit_button( 'Filter', 'secondary', '', false );
		self::render_action_presets();
		echo '</form>';
	}

	private static function render_filter_row( $label, $name, $value ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" /></td></tr>';
	}

	private static function render_action_presets() {
		$presets = array(
			'check'     => 'Check',
			'sync'      => 'Sync',
			'reconnect' => 'Reconnect',
		);

		echo '<p>Quick filters: ';
		foreach ( $presets as $value => $label ) {
			$url = add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'action' => $value,
				),
				admin_url( 'tools.php' )
			);

			echo '<a class="button" style="margin-right:6px;" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</p>';
	}

	private static function render_table( array $logs ) {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Event</th><th>User</th><th>Subscription</th><th>Site</th><th>Action</th><th>Error</th><th>Message</th><th>Created</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $logs ) ) {
			echo '<tr><td colspan="9">No audit entries found.</td></tr>';
		} else {
			foreach ( $logs as $log ) {
				$parsed = self::decode_message( $log->message );
				$action = isset( $parsed['action'] ) ? $parsed['action'] : '';
				$error  = isset( $parsed['error'] ) ? $parsed['error'] : '';
				$details_id = 'rw-portal-log-' . (int) $log->id;

				echo '<tr>';
				echo '<td>' . esc_html( $log->id ) . '</td>';
				echo '<td>' . esc_html( $log->event_type ) . '</td>';
				echo '<td>' . esc_html( $log->user_id ) . '</td>';
				echo '<td>' . esc_html( $log->subscription_id ) . '</td>';
				echo '<td>' . esc_html( $log->managed_site_id ) . '</td>';
				echo '<td>' . esc_html( $action ) . '</td>';
				echo '<td>' . esc_html( $error ) . '</td>';
				echo '<td>' . self::render_message_cell( $log->message, $details_id ) . '</td>';
				echo '<td>' . esc_html( $log->created_at ) . '</td>';
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
			'event_type'     => isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '',
			'action'         => isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '',
			'user_id'        => isset( $_GET['user_id'] ) ? sanitize_text_field( wp_unslash( $_GET['user_id'] ) ) : '',
			'subscription_id'=> isset( $_GET['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_GET['subscription_id'] ) ) : '',
			'managed_site_id'=> isset( $_GET['managed_site_id'] ) ? sanitize_text_field( wp_unslash( $_GET['managed_site_id'] ) ) : '',
			'date_from'      => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'        => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'paged'          => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
		);
	}

	private static function query_logs( array $filters, $limit, $offset ) {
		global $wpdb;

		$table  = RW_DB::table( 'audit_log' );
		$where  = array();
		$params = array();

		if ( '' !== $filters['event_type'] ) {
			$where[] = 'event_type LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['event_type'] ) . '%';
		}

		if ( '' !== $filters['action'] ) {
			$where[] = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"action":"' . $filters['action'] . '"' ) . '%';
		}

		if ( '' !== $filters['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $filters['user_id'] );
		}

		if ( '' !== $filters['subscription_id'] ) {
			$where[]  = 'subscription_id = %d';
			$params[] = absint( $filters['subscription_id'] );
		}

		if ( '' !== $filters['managed_site_id'] ) {
			$where[]  = 'managed_site_id = %d';
			$params[] = absint( $filters['managed_site_id'] );
		}

		if ( '' !== $filters['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( '' !== $filters['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$query_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params_with_limit = array_merge( $params, array( (int) $limit, (int) $offset ) );
		$logs = $wpdb->get_results( $wpdb->prepare( $query_sql, $params_with_limit ) );

		return array( $logs, $total );
	}

	private static function decode_message( $message ) {
		if ( empty( $message ) ) {
			return array();
		}

		$decoded = json_decode( $message, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			return array();
		}

		return $decoded;
	}

	private static function format_message( $message ) {
		if ( empty( $message ) ) {
			return '';
		}

		$decoded = self::decode_message( $message );
		if ( empty( $decoded ) ) {
			return '<pre style="white-space:pre-wrap;max-width:420px;">' . esc_html( $message ) . '</pre>';
		}

		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT );
		if ( ! $pretty ) {
			$pretty = $message;
		}

		return '<pre style="white-space:pre-wrap;max-width:420px;">' . esc_html( $pretty ) . '</pre>';
	}

	private static function render_message_cell( $message, $details_id ) {
		if ( empty( $message ) ) {
			return '';
		}

		$decoded = self::decode_message( $message );
		if ( empty( $decoded ) ) {
			return '<details id="' . esc_attr( $details_id ) . '"><summary>View</summary>' . self::format_message( $message ) . '</details>';
		}

		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT );
		if ( ! $pretty ) {
			$pretty = $message;
		}

		return '<details id="' . esc_attr( $details_id ) . '"><summary>View</summary><pre style="white-space:pre-wrap;max-width:420px;">' . esc_html( $pretty ) . '</pre></details>';
	}
}
