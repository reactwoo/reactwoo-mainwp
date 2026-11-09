<?php
/**
 * Admin assignment of the website for a maintenance plan or purchased support minutes.
 *
 * Saving a website does not add or remove minutes. Order and subscription notes
 * are private admin notes, not customer notes, so they do not send email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Site_Admin {
	const ACTION = 'rw_assign_support_site';

	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_screen' ), 20, 1 );
		add_action( 'woocommerce_process_shop_subscription_meta', array( __CLASS__, 'save_screen' ), 20, 1 );
	}

	/**
	 * @return void
	 */
	public static function register_meta_boxes() {
		foreach ( array( 'shop_subscription', 'shop_order' ) as $screen ) {
			if ( function_exists( 'add_meta_box' ) ) {
				add_meta_box(
					'rw_support_site',
					'Website for this service',
					array( __CLASS__, 'render_meta_box' ),
					$screen,
					'side',
					'default'
				);
			}
		}
	}

	/**
	 * Queue on Managed Sites. One host field per plan, ledger row, or order line.
	 *
	 * @param array<int,array<string,mixed>> $ledger_rows Purchased rows. Empty hosts are editable here.
	 * @return void
	 */
	public static function render_queue( array $ledger_rows = array() ) {
		if ( ! self::can_assign() || ! class_exists( 'RW_Support_Sites' ) ) {
			return;
		}
		$queue = RW_Support_Sites::queue();
		$rows  = self::queue_form_rows( $queue, $ledger_rows );
		if ( empty( $rows ) ) {
			return;
		}

		echo '<h2>Websites to assign</h2>';
		echo '<p>Enter the website for each plan or block of purchased minutes. Saving a website does not add or remove minutes.</p>';
		foreach ( $rows as $row ) {
			echo self::form_markup( $row );
		}
	}

	/**
	 * @param array<string,mixed>                       $queue Assignment queue.
	 * @param array<int,array<string,mixed>>            $ledger_rows Purchased rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function queue_form_rows( array $queue, array $ledger_rows ) {
		$forms = array();
		$plans = ( isset( $queue['plans'] ) && is_array( $queue['plans'] ) ) ? $queue['plans'] : array();
		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}
			$id = isset( $plan['subscription_id'] ) ? (int) $plan['subscription_id'] : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$forms[] = array(
				'target'  => 'subscription',
				'id'      => $id,
				'minutes' => 0,
				'label'   => 'Maintenance plan ' . $id,
				'host'    => '',
			);
		}

		$seen_items = array();
		foreach ( $ledger_rows as $row ) {
			if ( ! is_array( $row ) || 'purchased' !== ( isset( $row['credit_type'] ) ? (string) $row['credit_type'] : '' ) ) {
				continue;
			}
			$host = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::checkout_host( isset( $row['service_host'] ) ? $row['service_host'] : '' ) : '';
			if ( '' !== $host ) {
				continue;
			}
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$item_id = isset( $row['order_item_id'] ) ? (int) $row['order_item_id'] : 0;
			if ( $item_id > 0 ) {
				$seen_items[ $item_id ] = true;
			}
			$forms[] = array(
				'target'  => 'ledger',
				'id'      => $id,
				'minutes' => isset( $row['minutes_delta'] ) ? (int) $row['minutes_delta'] : 0,
				'label'   => 'Purchased minutes ' . $id,
				'host'    => '',
			);
		}

		$credits = ( isset( $queue['credits'] ) && is_array( $queue['credits'] ) ) ? $queue['credits'] : array();
		foreach ( $credits as $credit ) {
			if ( ! is_array( $credit ) ) {
				continue;
			}
			$item_id = isset( $credit['order_item_id'] ) ? (int) $credit['order_item_id'] : 0;
			if ( $item_id <= 0 || isset( $seen_items[ $item_id ] ) ) {
				continue;
			}
			$forms[] = array(
				'target'  => 'order_item',
				'id'      => $item_id,
				'minutes' => isset( $credit['minutes'] ) ? (int) $credit['minutes'] : 0,
				'label'   => 'Order line ' . $item_id,
				'host'    => '',
			);
		}

		return $forms;
	}

	/**
	 * @param array<string,mixed> $row Form description.
	 * @return string
	 */
	public static function form_markup( array $row ) {
		$target = isset( $row['target'] ) ? (string) $row['target'] : '';
		$id     = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$action = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::assignment_nonce_action( $target, $id ) : '';
		$nonce  = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( $action ) : '';
		$post   = function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : 'admin-post.php';
		$minutes = isset( $row['minutes'] ) ? (int) $row['minutes'] : 0;
		$label  = isset( $row['label'] ) ? (string) $row['label'] : '';
		$host   = isset( $row['host'] ) ? (string) $row['host'] : '';

		$html  = '<form method="post" action="' . self::esc( $post ) . '" class="rw-support-site-assign">';
		$html .= '<input type="hidden" name="action" value="' . self::esc( self::ACTION ) . '" />';
		$html .= '<input type="hidden" name="rw_assign_target" value="' . self::esc( $target ) . '" />';
		$html .= '<input type="hidden" name="rw_assign_id" value="' . self::esc( (string) $id ) . '" />';
		$html .= '<input type="hidden" name="_wpnonce" value="' . self::esc( $nonce ) . '" />';
		$html .= '<p><strong>' . self::esc( $label ) . '</strong>';
		if ( $minutes > 0 ) {
			$html .= ' <span>' . self::esc( (string) $minutes ) . ' minutes, unchanged</span>';
		}
		$html .= '</p>';
		$html .= '<label>Website <input type="text" name="rw_service_domain" value="' . self::esc( $host ) . '" placeholder="example.com" /></label> ';
		$html .= '<button type="submit">Save website</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * @param object $object Order or subscription.
	 * @return void
	 */
	public static function render_meta_box( $object ) {
		if ( ! self::can_assign() || ! is_object( $object ) || ! class_exists( 'RW_Support_Sites' ) ) {
			return;
		}
		$id   = method_exists( $object, 'get_id' ) ? (int) $object->get_id() : 0;
		$type = self::object_type( $object );
		$nonce_action = RW_Support_Sites::assignment_nonce_action( 'screen', $id );
		$nonce        = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( $nonce_action ) : '';
		echo '<input type="hidden" name="rw_support_site_screen" value="1" />';
		echo '<input type="hidden" name="rw_support_site_nonce" value="' . self::esc( $nonce ) . '" />';
		if ( 'subscription' === $type ) {
			$current = RW_Support_Sites::read_service_domain( $object );
			echo '<input type="hidden" name="rw_assign_target" value="subscription" />';
			echo '<input type="hidden" name="rw_assign_id" value="' . self::esc( (string) $id ) . '" />';
			echo '<p><label>Website <input type="text" name="rw_service_domain" value="' . self::esc( $current ) . '" placeholder="example.com" class="widefat" /></label></p>';
			echo '<p>This is the website for the maintenance plan. Saving it does not add or remove support minutes.</p>';
			return;
		}

		if ( 'order' !== $type || ! method_exists( $object, 'get_items' ) ) {
			return;
		}
		$shown = false;
		foreach ( $object->get_items() as $item_id => $item ) {
			if ( ! self::item_accepts_site( $item ) ) {
				continue;
			}
			$shown   = true;
			$current = is_object( $item ) ? RW_Support_Sites::read_service_domain( $item ) : '';
			echo '<p><label>Order line ' . self::esc( (string) (int) $item_id ) . ' <input type="text" name="rw_service_domain_item[' . self::esc( (string) (int) $item_id ) . ']" value="' . self::esc( $current ) . '" placeholder="example.com" class="widefat" /></label></p>';
		}
		if ( ! $shown ) {
			echo '<p>This order has no support or maintenance line to assign.</p>';
		}
	}

	/**
	 * @return void
	 */
	public static function handle_post() {
		$target = isset( $_POST['rw_assign_target'] ) ? sanitize_key( wp_unslash( $_POST['rw_assign_target'] ) ) : '';
		$id     = isset( $_POST['rw_assign_id'] ) ? absint( wp_unslash( $_POST['rw_assign_id'] ) ) : 0;
		$raw    = isset( $_POST['rw_service_domain'] ) ? wp_unslash( $_POST['rw_service_domain'] ) : '';
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$action = class_exists( 'RW_Support_Sites' ) ? RW_Support_Sites::assignment_nonce_action( $target, $id ) : '';
		$nonce_ok = function_exists( 'wp_verify_nonce' ) ? (bool) wp_verify_nonce( $nonce, $action ) : false;
		$result = self::assign( $target, $id, $raw, self::can_assign(), $nonce_ok );
		$arg    = ! empty( $result['ok'] ) ? 'assigned' : ( isset( $result['code'] ) ? (string) $result['code'] : 'invalid_host' );
		$url    = function_exists( 'admin_url' ) ? admin_url( 'tools.php?page=' . RW_Portal_Sites_Admin::MENU_SLUG ) : '';
		$url    = add_query_arg( 'rw_site_assign', $arg, $url );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Save the subscription or order screen field when WooCommerce posts the meta box.
	 *
	 * The Managed Sites forms post on their own. This handles the side box when
	 * the whole order screen is saved and the dedicated field is present.
	 *
	 * @param int $object_id Order or subscription id.
	 * @return void
	 */
	public static function save_screen( $object_id ) {
		if ( empty( $_POST['rw_support_site_screen'] ) || ! class_exists( 'RW_Support_Sites' ) ) {
			return;
		}
		$object_id = (int) $object_id;
		$nonce     = isset( $_POST['rw_support_site_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rw_support_site_nonce'] ) ) : '';
		$action    = RW_Support_Sites::assignment_nonce_action( 'screen', $object_id );
		$nonce_ok  = function_exists( 'wp_verify_nonce' ) ? (bool) wp_verify_nonce( $nonce, $action ) : false;
		if ( ! $nonce_ok || ! self::can_assign() ) {
			return;
		}
		$target = isset( $_POST['rw_assign_target'] ) ? sanitize_key( wp_unslash( $_POST['rw_assign_target'] ) ) : '';
		if ( 'subscription' === $target && isset( $_POST['rw_service_domain'] ) ) {
			$raw = wp_unslash( $_POST['rw_service_domain'] );
			self::assign( 'subscription', $object_id, $raw, true, true );
		}
		if ( isset( $_POST['rw_service_domain_item'] ) && is_array( $_POST['rw_service_domain_item'] ) ) {
			foreach ( wp_unslash( $_POST['rw_service_domain_item'] ) as $item_id => $raw ) {
				self::assign( 'order_item', absint( $item_id ), $raw, true, true );
			}
		}
	}

	/**
	 * Apply one assignment. The returned host is the only value written.
	 *
	 * @param string $target ledger, order_item, or subscription.
	 * @param int    $id Target id.
	 * @param mixed  $raw_host Posted website.
	 * @param bool   $can manage_woocommerce.
	 * @param bool   $nonce_ok Nonce matched.
	 * @param object|null $document Order or subscription to annotate. Null loads it when WooCommerce is present.
	 * @return array<string,mixed>
	 */
	public static function assign( $target, $id, $raw_host, $can, $nonce_ok, $document = null ) {
		if ( ! class_exists( 'RW_Support_Sites' ) ) {
			return array(
				'ok'   => false,
				'code' => 'unavailable',
			);
		}
		$decision = RW_Support_Sites::prepare_assignment( $target, $id, $raw_host, $can, $nonce_ok );
		if ( empty( $decision['ok'] ) ) {
			return $decision;
		}

		$host      = (string) $decision['host'];
		$persisted = false;
		if ( 'ledger' === $decision['target'] ) {
			$row   = class_exists( 'RW_Support_Ledger' ) ? RW_Support_Ledger::row( (int) $decision['id'] ) : null;
			$saved = class_exists( 'RW_Support_Ledger' ) ? RW_Support_Ledger::set_service_host( (int) $decision['id'], $host ) : false;
			if ( $saved && is_array( $row ) ) {
				$persisted = true;
				if ( ! empty( $row['order_item_id'] ) ) {
					self::store_item_domain( $row, $host );
					RW_Support_Sites::clear_credit_flag( (int) $row['order_item_id'] );
				}
				if ( ! is_object( $document ) ) {
					$document = self::document_for_row( $row );
				}
			}
		} elseif ( 'order_item' === $decision['target'] ) {
			$updated = class_exists( 'RW_Support_Ledger' ) ? RW_Support_Ledger::set_service_host_for_order_item( (int) $decision['id'], $host ) : 0;
			$stored  = self::store_item_domain_by_id( (int) $decision['id'], $host );
			if ( $updated > 0 ) {
				RW_Support_Sites::clear_credit_flag( (int) $decision['id'] );
			}
			$persisted = $updated > 0 || $stored;
			if ( ! is_object( $document ) ) {
				$document = self::document_for_item( (int) $decision['id'] );
			}
		} else {
			if ( ! is_object( $document ) ) {
				$document = self::document_by_id( (int) $decision['id'] );
			}
			if ( is_object( $document ) && method_exists( $document, 'update_meta_data' ) ) {
				$document->update_meta_data( RW_Support_Sites::SERVICE_DOMAIN_META, $host );
				if ( method_exists( $document, 'save' ) ) {
					$document->save();
				}
				$persisted = true;
			}
			if ( $persisted ) {
				RW_Support_Sites::clear_plan_flag( (int) $decision['id'] );
			}
		}

		if ( ! $persisted ) {
			return array(
				'ok'   => false,
				'code' => 'not_saved',
			);
		}

		self::add_audit_note( $document, (string) $decision['note'], $decision );
		return $decision;
	}

	/**
	 * @param object|null         $document Order or subscription.
	 * @param string              $note Note text.
	 * @param array<string,mixed> $decision Assignment decision.
	 * @return void
	 */
	public static function add_audit_note( $document, $note, array $decision ) {
		if ( is_object( $document ) && method_exists( $document, 'add_order_note' ) ) {
			$document->add_order_note( $note, 0, false );
		}
		if ( class_exists( 'RW_Audit' ) ) {
			$context = array(
				'message_text' => $note,
				'target'       => isset( $decision['target'] ) ? (string) $decision['target'] : '',
				'target_id'    => isset( $decision['id'] ) ? (int) $decision['id'] : 0,
				'host'         => isset( $decision['host'] ) ? (string) $decision['host'] : '',
			);
			if ( is_object( $document ) && method_exists( $document, 'get_id' ) ) {
				$type = self::object_type( $document );
				if ( 'subscription' === $type ) {
					$context['subscription_id'] = (int) $document->get_id();
				}
			}
			RW_Audit::log( 'support_site_assigned', $context );
		}
	}

	/**
	 * @return bool
	 */
	public static function can_assign() {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * @param object $object Order or subscription.
	 * @return string
	 */
	/**
	 * Support packs and maintenance lines can store a service website.
	 * Licence lines are left alone.
	 *
	 * @param object $item Order line.
	 * @return bool
	 */
	private static function item_accepts_site( $item ) {
		if ( ! is_object( $item ) || ! class_exists( 'RW_Support_Sites' ) ) {
			return false;
		}
		if ( '' !== RW_Support_Sites::read_service_domain( $item ) ) {
			return true;
		}
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
		$variation  = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
		if ( class_exists( 'RW_Support_Offers' ) ) {
			if ( '' !== RW_Support_Offers::kind_of( '', $variation > 0 ? $variation : $product_id ) ) {
				return true;
			}
			if ( '' !== RW_Support_Offers::kind_of( '', $product_id ) ) {
				return true;
			}
			if ( RW_Support_Offers::product_is_maintenance( $variation > 0 ? $variation : $product_id, $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	private static function object_type( $object ) {
		if ( is_object( $object ) && method_exists( $object, 'get_type' ) ) {
			$type = (string) $object->get_type();
			if ( 'shop_subscription' === $type ) {
				return 'subscription';
			}
			if ( 'shop_order' === $type ) {
				return 'order';
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $row Ledger row.
	 * @param string              $host Normalised host.
	 * @return void
	 */
	private static function store_item_domain( array $row, $host ) {
		$item_id = isset( $row['order_item_id'] ) ? (int) $row['order_item_id'] : 0;
		self::store_item_domain_by_id( $item_id, $host );
	}

	/**
	 * @param int    $item_id Order line.
	 * @param string $host Normalised host.
	 * @return bool
	 */
	private static function store_item_domain_by_id( $item_id, $host ) {
		if ( $item_id <= 0 || ! function_exists( 'wc_get_order_id_by_order_item_id' ) || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order_id = (int) wc_get_order_id_by_order_item_id( $item_id );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_item' ) ) {
			return false;
		}
		$item = $order->get_item( $item_id );
		if ( ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
			return false;
		}
		$item->update_meta_data( RW_Support_Sites::SERVICE_DOMAIN_META, $host );
		if ( method_exists( $item, 'save' ) ) {
			$item->save();
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $row Ledger row.
	 * @return object|null
	 */
	private static function document_for_row( array $row ) {
		if ( ! empty( $row['order_id'] ) ) {
			return self::document_by_id( (int) $row['order_id'] );
		}
		if ( ! empty( $row['subscription_id'] ) ) {
			return self::document_by_id( (int) $row['subscription_id'] );
		}

		return null;
	}

	/**
	 * @param int $item_id Order line.
	 * @return object|null
	 */
	private static function document_for_item( $item_id ) {
		if ( ! function_exists( 'wc_get_order_id_by_order_item_id' ) ) {
			return null;
		}

		return self::document_by_id( (int) wc_get_order_id_by_order_item_id( $item_id ) );
	}

	/**
	 * @param int $id Order or subscription id.
	 * @return object|null
	 */
	private static function document_by_id( $id ) {
		if ( $id <= 0 ) {
			return null;
		}
		if ( function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $id );
			if ( is_object( $subscription ) ) {
				return $subscription;
			}
		}
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $id );
			if ( is_object( $order ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function esc( $value ) {
		if ( function_exists( 'esc_attr' ) ) {
			return esc_attr( $value );
		}

		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
