<?php
/**
 * Support minutes stay on one website.
 * Loaded by tests/run.php. No orders, charges, or emails.
 */

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-sites.php';

class RW_Site_Line {
	public $product_id   = 0;
	public $variation_id = 0;
	public $meta         = array();

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return $this->variation_id;
	}

	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

$site_now    = '2026-01-20 09:00:00';
$site_period = array(
	'start'      => '2026-01-16 09:00:00',
	'end'        => '2026-02-16 09:00:00',
	'start_date' => '2026-01-16',
	'end_date'   => '2026-02-15',
);

rw_test(
	'a service host ignores case, www, scheme, port, path and a trailing dot',
	'example.com' === RW_Support_Sites::host_key( 'https://WWW.Example.com:443/shop/' )
		&& 'example.com' === RW_Support_Sites::host_key( 'HTTP://www.example.com.' )
		&& 'example.com' === RW_Support_Sites::host_key( 'Example.com.' )
		&& 'shop.example.co.uk' === RW_Support_Sites::host_key( 'https://shop.example.co.uk/path?q=1' )
		&& RW_Support_Sites::hosts_match( 'https://WWW.Example.com/a', 'example.com' )
		&& ! RW_Support_Sites::hosts_match( 'shop.example.com', 'example.com' )
		&& '' === RW_Support_Sites::host_key( '' )
);

$plan_meta = RW_Support_Sites::plan_site_from_values( 'https://WWW.Example.com:443/shop/', array( 'other.com' ) );
$plan_lines = RW_Support_Sites::plan_site_from_values( '', array( 'https://www.Example.com/a', 'example.com.' ) );
$plan_empty = RW_Support_Sites::plan_site_from_values( '', array() );
$plan_blank_lines = RW_Support_Sites::plan_site_from_values( '', array( '', '' ) );
$plan_split = RW_Support_Sites::plan_site_from_values( '', array( 'example.com', 'other.com' ) );
$plan_gap = RW_Support_Sites::plan_site_from_values( '', array( 'example.com', '' ) );
rw_test(
	'the plan site is the subscription host, or agreeing maintenance lines',
	'example.com' === $plan_meta['host']
		&& 'subscription' === $plan_meta['status']
		&& 'example.com' === $plan_lines['host']
		&& 'lines' === $plan_lines['status']
		&& '' === $plan_empty['host']
		&& 'empty' === $plan_empty['status']
		&& 'empty' === $plan_blank_lines['status']
		&& '' === $plan_split['host']
		&& 'disagree' === $plan_split['status']
		&& 'disagree' === $plan_gap['status']
);

$licence_sub = new RW_Test_Subscription();
$licence_sub->meta['_reactwoo_domain'] = 'licence.example';
$licence_sub->meta['_reactwoo_license_domain'] = 'licence.example';
$licence_line = new RW_Site_Line();
$licence_line->product_id = 3654;
$licence_line->meta['_reactwoo_domain'] = 'licence.example';
$licence_sub->items = array( $licence_line );
$licence_site = RW_Support_Sites::plan_site( $licence_sub );
rw_test(
	'a plan site is not taken from a licence domain',
	'' === $licence_site['host']
		&& 'empty' === $licence_site['status']
		&& false === strpos( file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-sites.php' ), "get_meta( '_reactwoo_domain'" )
		&& false === strpos( file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-credits.php' ), '_reactwoo_domain' )
		&& false === strpos( file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-credits.php' ), '_reactwoo_license_domain' )
);

$line_sub = new RW_Test_Subscription();
$line_a = new RW_Site_Line();
$line_a->product_id = 3657;
$line_a->meta['_rw_service_domain'] = 'https://www.Example.com/blog';
$line_b = new RW_Site_Line();
$line_b->variation_id = 8801;
$line_b->product_id = 3657;
$line_b->meta['_rw_service_domain'] = 'EXAMPLE.com.';
$line_sub->items = array( $line_a, $line_b );
$from_lines = RW_Support_Sites::plan_site( $line_sub );
rw_test(
	'maintenance lines that name the same host are the plan site',
	'example.com' === $from_lines['host']
		&& 'lines' === $from_lines['status']
		&& RW_Support_Offers::product_is_maintenance( 8801, 3654 )
		&& RW_Support_Offers::subscription_grants_additional( 'active', array( 8801, 3654 ) )
);

$GLOBALS['rw_post_meta'][7001]['_rw_maintenance_plan_key'] = 'standard';
rw_test(
	'a plan key marks a maintenance product when the id is not 3654, 3657 or 3660',
	RW_Support_Offers::product_is_maintenance( 7001, 0 )
		&& RW_Support_Offers::subscription_grants_additional( 'active', array( 7001 ) )
		&& ! RW_Support_Offers::subscription_grants_additional( 'on-hold', array( 7001 ) )
		&& ! RW_Support_Offers::product_is_maintenance( 999, 0 )
);

$one_plan = array(
	array(
		'subscription_id' => 41,
		'host'            => 'example.com',
		'status'          => 'subscription',
		'grants'          => true,
	),
);
$matched = RW_Support_Sites::credit_decision( 'additional', 'yes', 'HTTPS://WWW.Example.com:443/shop/', $one_plan );
$cased = RW_Support_Sites::credit_decision( 'additional', 'yes', 'example.com.', $one_plan );
$refused = RW_Support_Sites::credit_decision( 'additional', 'yes', 'https://other.example/path', $one_plan );
rw_test(
	'additional support matches the plan site ignoring case and www',
	! empty( $matched['apply'] )
		&& 41 === $matched['subscription_id']
		&& 'example.com' === $matched['host']
		&& ! empty( $cased['apply'] )
		&& 'example.com' === $cased['host']
		&& empty( $refused['apply'] )
		&& 'site_mismatch' === $refused['flag']
		&& 0 === $refused['subscription_id']
);

$missing_plan = array(
	array(
		'subscription_id' => 42,
		'host'            => '',
		'status'          => 'empty',
		'grants'          => true,
	),
);
$missing_decision = RW_Support_Sites::credit_decision( 'additional', 'yes', 'example.com', $missing_plan );
$split_plan = array(
	array(
		'subscription_id' => 43,
		'host'            => '',
		'status'          => 'disagree',
		'grants'          => true,
	),
);
$split_decision = RW_Support_Sites::credit_decision( 'additional', 'yes', 'example.com', $split_plan );
rw_test(
	'additional support is not applied when the plan has no single site',
	empty( $missing_decision['apply'] )
		&& 'no_plan_site' === $missing_decision['flag']
		&& empty( $split_decision['apply'] )
		&& 'no_plan_site' === $split_decision['flag']
);

$two_plans = array(
	array(
		'subscription_id' => 51,
		'host'            => 'example.com',
		'status'          => 'subscription',
		'grants'          => true,
	),
	array(
		'subscription_id' => 52,
		'host'            => 'other.com',
		'status'          => 'lines',
		'grants'          => true,
	),
);
$first_plan = RW_Support_Sites::credit_decision( 'additional', 'yes', 'https://www.Example.com/', $two_plans );
$second_plan = RW_Support_Sites::credit_decision( 'additional', 'yes', 'OTHER.com', $two_plans );
$neither_plan = RW_Support_Sites::credit_decision( 'additional', 'yes', 'third.com', $two_plans );
rw_test(
	'multiple plans keep additional support on the matching site only',
	! empty( $first_plan['apply'] )
		&& 51 === $first_plan['subscription_id']
		&& 'example.com' === $first_plan['host']
		&& 52 === $second_plan['subscription_id']
		&& 'other.com' === $second_plan['host']
		&& empty( $neither_plan['apply'] )
		&& 'site_mismatch' === $neither_plan['flag']
);

$technical = RW_Support_Sites::credit_decision( 'technical', '', 'https://www.Shop.Example.com/help', array() );
$technical_open = RW_Support_Sites::credit_decision( 'technical', '', '', array() );
rw_test(
	'technical support is standalone and locked to its own site',
	! empty( $technical['apply'] )
		&& 0 === $technical['subscription_id']
		&& 'shop.example.com' === $technical['host']
		&& '' === $technical['flag']
		&& ! $technical['has_maintenance']
		&& RW_Support_Offers::allows_purchase( true, 'technical', false )
		&& ! empty( $technical_open['apply'] )
		&& '' === $technical_open['host']
		&& 'unassigned' === $technical_open['flag']
);

$checkout_match = RW_Support_Offers::checkout_site_blocks(
	array(
		array( 'id' => 3663, 'slug' => 'additional-support-1h', 'service_domain' => 'https://WWW.Example.com/cart' ),
		array( 'id' => 3665, 'slug' => 'technical-support-1h', 'service_domain' => 'nope.example' ),
	),
	true,
	array( 'example.com' )
);
$checkout_mismatch = RW_Support_Offers::checkout_site_blocks(
	array(
		array( 'id' => 3663, 'slug' => 'additional-support-1h', 'service_domain' => 'other.com' ),
		array( 'id' => 3664, 'slug' => 'additional-support-5h', 'service_domain' => 'https://www.Example.com' ),
	),
	true,
	array( 'example.com', 'shop.example.com' )
);
$checkout_missing = RW_Support_Offers::checkout_site_blocks(
	array(
		array( 'id' => 3663, 'slug' => 'additional-support-1h', 'service_domain' => 'example.com' ),
	),
	true,
	array()
);
rw_test(
	'checkout allows additional support only for an entitled plan site',
	array() === $checkout_match
		&& array( 3663 ) === $checkout_mismatch
		&& array( 3663 ) === $checkout_missing
		&& '' === RW_Support_Offers::checkout_site_refusal( true, 'https://www.Example.com', array( 'example.com' ) )
		&& 'mismatch' === RW_Support_Offers::checkout_site_refusal( true, 'other.com', array( 'example.com' ) )
		&& 'plan_site' === RW_Support_Offers::checkout_site_refusal( true, 'example.com', array() )
		&& 'missing_pack_site' === RW_Support_Offers::checkout_site_refusal( true, '', array( 'example.com' ) )
		&& '' === RW_Support_Offers::checkout_site_refusal( false, 'other.com', array( 'example.com' ) )
		&& RW_Support_Offers::purchasable_with_site( true, 'additional', true, true )
		&& ! RW_Support_Offers::purchasable_with_site( true, 'additional', true, false )
		&& RW_Support_Offers::purchasable_with_site( true, 'technical', false, false )
);

$ledger_rows = array(
	array(
		'minutes_delta' => 60,
		'credit_type'   => 'plan_allowance',
		'reference'     => 'period:2026-01-16',
		'order_item_id' => 0,
		'expires_at'    => '2026-02-16 09:00:00',
		'service_host'  => 'example.com',
	),
	array(
		'minutes_delta' => 40,
		'credit_type'   => 'purchased',
		'reference'     => 'order_item:11',
		'order_item_id' => 11,
		'expires_at'    => '2027-01-01 00:00:00',
		'service_host'  => 'example.com',
	),
	array(
		'minutes_delta' => 15,
		'credit_type'   => 'purchased',
		'reference'     => 'order_item:12',
		'order_item_id' => 12,
		'expires_at'    => '2027-01-01 00:00:00',
		'service_host'  => 'shop.example.com',
	),
	array(
		'minutes_delta' => 60,
		'credit_type'   => 'purchased',
		'reference'     => 'order_item:10',
		'order_item_id' => 10,
		'expires_at'    => '2027-01-01 00:00:00',
		'service_host'  => '',
	),
);
$on_plan = RW_Support_Balance::usage_for_site( $ledger_rows, 30, $site_period, $site_now, 'https://www.example.com', 'example.com' );
$on_other = RW_Support_Balance::usage_for_site( $ledger_rows, 30, $site_period, $site_now, 'other.com', 'example.com' );
$on_technical = RW_Support_Balance::usage_for_site( $ledger_rows, 10, $site_period, $site_now, 'https://WWW.Shop.Example.com/x', '' );
$context = RW_Support_Sites::context_balance( $ledger_rows, $site_now, 'https://www.Example.com', 'example.com' );
rw_test(
	'support on a site uses that site only and leaves legacy minutes in place',
	115 === RW_Support_Balance::purchased_remainder( $ledger_rows, $site_now )
		&& 40 === RW_Support_Balance::purchased_remainder_for_host( $ledger_rows, $site_now, 'https://WWW.Example.com' )
		&& 15 === RW_Support_Balance::purchased_remainder_for_host( $ledger_rows, $site_now, 'shop.example.com' )
		&& 60 === RW_Support_Balance::unassigned_purchased_remainder( $ledger_rows, $site_now )
		&& 0 === RW_Support_Balance::purchased_remainder_for_host( $ledger_rows, $site_now, 'other.com' )
		&& 1 === count( $on_plan['slices'] )
		&& 'included:2026-01-16' === $on_plan['slices'][0]['reference']
		&& 0 === $on_plan['unallocated']
		&& array() === $on_other['slices']
		&& 30 === $on_other['unallocated']
		&& 1 === count( $on_technical['slices'] )
		&& 'purchased:12' === $on_technical['slices'][0]['reference']
		&& 'shop.example.com' === $on_technical['slices'][0]['service_host']
		&& 40 === $context['purchased']
		&& 60 === $context['unassigned']
		&& ! empty( $context['included_applies'] )
);

delete_option( RW_Support_Sites::ASSIGNMENT_OPTION );
RW_Support_Sites::flag_unassigned_rows( $ledger_rows, $site_now, 21 );
RW_Support_Sites::flag_plan( 42, 21, 'empty' );
$queue = RW_Support_Sites::queue();
$after_flag = RW_Support_Balance::purchased_remainder( $ledger_rows, $site_now );
$notice = RW_Support_Sites::assignment_notice();
rw_test(
	'legacy minutes with no site are kept and flagged for assignment',
	115 === $after_flag
		&& isset( $queue['credits']['10'] )
		&& 'unassigned' === $queue['credits']['10']['reason']
		&& 60 === $queue['credits']['10']['minutes']
		&& isset( $queue['plans']['42'] )
		&& 'empty' === $queue['plans']['42']['reason']
		&& false !== strpos( $notice, 'kept' )
		&& false !== strpos( $notice, 'not offered' )
		&& false === strpos( file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/account-dashboard.php' ), 'rw-maint-banner' )
);

$dash_css = file_get_contents( __DIR__ . '/../reactwoo-support-portal/assets/css/account-maintenance.css' );
$dash_template = file_get_contents( __DIR__ . '/../reactwoo-support-portal/templates/account-dashboard.php' );
rw_test(
	'the maintenance dashboard still uses grouped rows and the device colour scheme',
	false !== strpos( $dash_css, '.rw-maint-row-2' )
		&& false !== strpos( $dash_css, '.rw-maint-row-3' )
		&& false !== strpos( $dash_css, 'prefers-color-scheme: dark' )
		&& false !== strpos( $dash_template, 'purchased minutes remain for' )
		&& false !== strpos( $dash_template, 'not tied to a website yet' )
		&& false !== strpos( $dash_template, 'Request support' )
);

delete_option( RW_Support_Sites::ASSIGNMENT_OPTION );

require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-site-admin.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-ledger.php';

class RW_Assign_Subscription {
	public $id = 77;
	public $meta = array();
	public $notes = array();
	public function get_id() {
		return $this->id;
	}
	public function get_type() {
		return 'shop_subscription';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
	}
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
		$this->notes[] = array( $note, (int) $is_customer_note, $added_by_user );
	}
}

rw_test(
	'an assigned website uses the checkout host check',
	'example.com' === RW_Support_Sites::checkout_host( 'https://WWW.Example.com:443/shop/' )
		&& 'example.com' === RW_Support_Sites::checkout_host( 'Example.com.' )
		&& '' === RW_Support_Sites::checkout_host( '' )
		&& '' === RW_Support_Sites::checkout_host( 'not a host' )
		&& '' === RW_Support_Sites::checkout_host( 'javascript:alert(1)' )
);

$unassigned_row = array(
	'id'            => 5,
	'minutes_delta' => 60,
	'credit_type'   => 'purchased',
	'order_item_id' => 10,
	'order_id'      => 90,
	'reference'     => 'order_item:10',
	'expires_at'    => '2027-01-01 00:00:00',
	'service_host'  => '',
);
$assigned_row = RW_Support_Sites::with_assigned_host( $unassigned_row, 'https://www.Example.com/path' );
$before_minutes = RW_Support_Balance::purchased_remainder( array( $unassigned_row ), $site_now );
$after_minutes = RW_Support_Balance::purchased_remainder( array( $assigned_row ), $site_now );
$ledger_decision = RW_Support_Sites::prepare_assignment( 'ledger', 5, 'https://WWW.Example.com', true, true );
$blocked_cap = RW_Support_Sites::prepare_assignment( 'ledger', 5, 'example.com', false, true );
$blocked_nonce = RW_Support_Sites::prepare_assignment( 'subscription', 77, 'example.com', true, false );
rw_test(
	'assigning a site keeps the minutes and only stores the host',
	is_array( $assigned_row )
		&& 60 === $assigned_row['minutes_delta']
		&& 'example.com' === $assigned_row['service_host']
		&& $before_minutes === $after_minutes
		&& 60 === RW_Support_Balance::purchased_remainder_for_host( array( $assigned_row ), $site_now, 'example.com' )
		&& 0 === RW_Support_Balance::unassigned_purchased_remainder( array( $assigned_row ), $site_now )
		&& ! empty( $ledger_decision['ok'] )
		&& array( 'service_host' => 'example.com' ) === $ledger_decision['fields']
		&& ! isset( $ledger_decision['fields']['minutes_delta'] )
		&& empty( $blocked_cap['ok'] )
		&& 'forbidden' === $blocked_cap['code']
		&& 'nonce' === $blocked_nonce['code']
		&& false === $ledger_decision['customer_note']
);

$plan_decision = RW_Support_Sites::prepare_assignment( 'subscription', 77, 'https://www.Other.com:8443/a', true, true );
RW_Support_Sites::flag_plan( 77, 21, 'empty' );
$plan_doc = new RW_Assign_Subscription();
$plan_saved = RW_Support_Site_Admin::assign( 'subscription', 77, 'https://www.Other.com/blog', true, true, $plan_doc );
$plan_queue = RW_Support_Sites::queue();
rw_test(
	'a plan website is stored on the subscription with a private note',
	! empty( $plan_decision['ok'] )
		&& '_rw_service_domain' === $plan_decision['meta_key']
		&& 'other.com' === $plan_decision['host']
		&& ! empty( $plan_saved['ok'] )
		&& 'other.com' === $plan_doc->meta['_rw_service_domain']
		&& 1 === count( $plan_doc->notes )
		&& 0 === $plan_doc->notes[0][1]
		&& false === $plan_doc->notes[0][2]
		&& false !== strpos( $plan_doc->notes[0][0], 'other.com' )
		&& false !== strpos( $plan_doc->notes[0][0], 'not changed' )
		&& ! isset( $plan_queue['plans']['77'] )
);

$queue_forms = RW_Support_Site_Admin::queue_form_rows(
	array(
		'plans'   => array(
			'77' => array( 'subscription_id' => 77, 'user_id' => 21, 'reason' => 'empty' ),
		),
		'credits' => array(
			'11' => array( 'order_item_id' => 11, 'user_id' => 21, 'order_id' => 91, 'reason' => 'unassigned', 'minutes' => 30 ),
		),
	),
	array( $unassigned_row )
);
$queue_html = '';
foreach ( $queue_forms as $queue_form ) {
	$queue_html .= RW_Support_Site_Admin::form_markup( $queue_form );
}
$admin_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-site-admin.php' );
$changelog = file_get_contents( __DIR__ . '/../reactwoo-support-portal/readme.txt' );
rw_test(
	'the assignment queue edits a host per plan, ledger row, and order line',
	3 === count( $queue_forms )
		&& 'subscription' === $queue_forms[0]['target']
		&& 'ledger' === $queue_forms[1]['target']
		&& 60 === $queue_forms[1]['minutes']
		&& 'order_item' === $queue_forms[2]['target']
		&& false !== strpos( $queue_html, 'name="rw_service_domain"' )
		&& false !== strpos( $queue_html, 'minutes, unchanged' )
		&& false === strpos( $queue_html, 'name="minutes' )
		&& false !== strpos( $admin_src, 'manage_woocommerce' )
		&& false !== strpos( $admin_src, 'wp_verify_nonce' )
		&& false !== strpos( $admin_src, 'add_order_note' )
		&& false !== strpos( $changelog, '= 0.5.10 =' )
		&& false !== strpos( $changelog, 'Minute totals are not changed' )
);

delete_option( RW_Support_Sites::ASSIGNMENT_OPTION );
