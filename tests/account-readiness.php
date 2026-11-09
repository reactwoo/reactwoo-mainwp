<?php
/**
 * Account access, cart eligibility, and lifecycle fixtures.
 * Loaded by tests/run.php. No orders, charges, or site calls.
 */

$now = strtotime( '2026-11-08 12:00:00 UTC' );
// Entitlement checks use the real clock, so the paid-through date must stay ahead of it.
$future = max( $now + 86400, time() + 86400 );
$past = $now - 86400;

rw_test(
	'account lifecycle distinguishes plan states',
	'no_plan' === RW_Account_Access::lifecycle( 'active', false, 0, $now )
		&& 'active' === RW_Account_Access::lifecycle( 'active', true, 0, $now )
		&& 'pending_activation' === RW_Account_Access::lifecycle( 'pending', true, 0, $now )
		&& 'payment_failed' === RW_Account_Access::lifecycle( 'on-hold', true, 0, $now )
		&& 'cancelled_paid_through' === RW_Account_Access::lifecycle( 'cancelled', true, $future, $now )
		&& 'cancelled_paid_through' === RW_Account_Access::lifecycle( 'pending-cancel', true, $future, $now )
		&& 'expired' === RW_Account_Access::lifecycle( 'cancelled', true, $past, $now )
		&& 'expired' === RW_Account_Access::lifecycle( 'cancelled', true, 0, $now )
		&& 'expired' === RW_Account_Access::lifecycle( 'expired', true, $future, $now )
		&& 'expired' === RW_Account_Access::lifecycle( 'pending-cancel', true, $past, $now )
);

rw_test(
	'the maintenance menu follows entitlement, including paid-through cancel',
	RW_Account_Access::menu_visible( 'active' )
		&& RW_Account_Access::menu_visible( 'pending_activation' )
		&& RW_Account_Access::menu_visible( 'payment_failed' )
		&& RW_Account_Access::menu_visible( 'cancelled_paid_through' )
		&& ! RW_Account_Access::menu_visible( 'expired' )
		&& ! RW_Account_Access::menu_visible( 'no_plan' )
		&& RW_Account_Access::can_connect( 'active' )
		&& RW_Account_Access::can_connect( 'cancelled_paid_through' )
		&& ! RW_Account_Access::can_connect( 'payment_failed' )
		&& ! RW_Account_Access::can_connect( 'pending_activation' )
		&& ! RW_Account_Access::can_connect( 'expired' )
);

rw_test(
	'screens keep a connected dashboard and separate disconnected or revoked sites',
	'connected' === RW_Account_Access::screen_for( 'active', 'connected', 'connected' )
		&& 'connected' === RW_Account_Access::screen_for( 'cancelled_paid_through', 'connected', 'connected' )
		&& 'disconnected' === RW_Account_Access::screen_for( 'active', 'disconnected', 'connected' )
		&& 'disconnected' === RW_Account_Access::screen_for( 'cancelled_paid_through', 'revoked', '' )
		&& 'payment_failed' === RW_Account_Access::screen_for( 'payment_failed', 'connected', 'connected' )
		&& 'pending_activation' === RW_Account_Access::screen_for( 'pending_activation', '', '' )
		&& 'expired' === RW_Account_Access::screen_for( 'expired', 'connected', 'connected' )
		&& 'setup' === RW_Account_Access::screen_for( 'active', '', '' )
		&& 'attention' === RW_Account_Access::screen_for( 'active', 'pending', 'action_required' )
);

$paid_copy = RW_Account_Access::lifecycle_message( 'cancelled_paid_through', '8 Dec 2026' );
rw_test(
	'lifecycle copy points billing at Subscriptions and does not invent terms',
	false !== strpos( $paid_copy, 'Subscriptions' )
		&& false !== strpos( $paid_copy, '8 Dec 2026' )
		&& false === stripos( $paid_copy, 'refund' )
		&& false === stripos( $paid_copy, 'vat' )
		&& false === stripos( RW_Account_Access::lifecycle_message( 'payment_failed', '' ), 'refund' )
);

$menu_base = array(
	'dashboard'     => 'Dashboard',
	'subscriptions' => 'Subscriptions',
	'hosting'       => 'Hosting',
	'license'       => 'Products & licences',
	'orders'        => 'Orders',
);
$holder_menu = RW_Account_Access::shape_menu( $menu_base, true, true );
$guest_menu  = RW_Account_Access::shape_menu( $menu_base, false, false );
$unknown_licences = RW_Account_Access::shape_menu( $menu_base, false, null );
rw_test(
	'menu keeps Hosting and Subscriptions separate and limits Maintenance and licences',
	'Maintenance' === $holder_menu['maintenance']
		&& 'Subscriptions' === $holder_menu['subscriptions']
		&& 'Hosting' === $holder_menu['hosting']
		&& 'Products & licences' === $holder_menu['license']
		&& array( 'dashboard', 'maintenance', 'subscriptions', 'hosting', 'license', 'orders' ) === array_keys( $holder_menu )
		&& ! isset( $guest_menu['maintenance'] )
		&& ! isset( $guest_menu['license'] )
		&& isset( $guest_menu['hosting'] )
		&& isset( $guest_menu['subscriptions'] )
		&& isset( $unknown_licences['license'] )
		&& ! isset( $unknown_licences['maintenance'] )
);

$seeded = RW_Account_Access::shape_menu(
	array(
		'maintenance'   => 'Already there',
		'dashboard'     => 'Dashboard',
		'subscriptions' => 'Subscriptions',
	),
	true,
	null
);
rw_test( 'an entitled menu keeps a custom Maintenance label', 'Already there' === $seeded['maintenance'] && isset( $seeded['subscriptions'] ) );

$removed = RW_Portal_Account::shape_menu_for_user(
	array(
		'dashboard'   => 'Dashboard',
		'maintenance' => 'Maintenance',
		'hosting'     => 'Hosting',
	),
	false
);
rw_test( 'a non-holder loses a pre-existing Maintenance item and keeps Hosting', ! isset( $removed['maintenance'] ) && 'Hosting' === $removed['hosting'] );

$pending = rw_menu_subscription( 'pending', 3654 );
$pending->id = 41;
$paid_cancel = rw_menu_subscription( 'cancelled', 3657, $future );
$paid_cancel->id = 42;
$ended = rw_menu_subscription( 'expired', 3660 );
$ended->id = 43;
$foreign = rw_menu_subscription( 'active', 3654 );
$foreign->id = 99;
$GLOBALS['rw_current_user_id'] = 21;
$GLOBALS['rw_user_subscriptions'][21] = array( $pending, $paid_cancel, $ended );
$GLOBALS['rw_user_subscriptions'][22] = array( $foreign );
rw_test(
	'pending activation and paid-through cancel are entitled and an ended plan is not',
	array( 41, 42 ) === RW_Portal_Account::entitled_subscription_ids( 21 )
		&& true === RW_Portal_Account::user_has_maintenance_for_menu( 21 )
		&& array() === RW_Portal_Account::entitled_subscription_ids( 23 )
);
rw_test(
	'another customers plan stays on that customers account',
	array( 99 ) === RW_Portal_Account::entitled_subscription_ids( 22 )
		&& ! in_array( 99, RW_Portal_Account::entitled_subscription_ids( 21 ), true )
);

$holder_decision = RW_Portal_Account::endpoint_response( 21, 0 );
$own_decision = RW_Portal_Account::endpoint_response( 21, 42 );
$foreign_decision = RW_Portal_Account::endpoint_response( 21, 99 );
$empty_decision = RW_Portal_Account::endpoint_response( 23, 42 );
rw_test(
	'endpoint access allows an owned plan and refuses another customers plan',
	! empty( $holder_decision['allow'] )
		&& 200 === $holder_decision['status']
		&& ! empty( $own_decision['allow'] )
		&& empty( $foreign_decision['allow'] )
		&& 403 === $foreign_decision['status']
		&& 'not_owned' === $foreign_decision['code']
		&& empty( $empty_decision['allow'] )
		&& 'no_plan' === $empty_decision['code']
		&& false === strpos( $foreign_decision['message'], '99' )
);

$GLOBALS['rw_current_user_id'] = 23;
ob_start();
RW_Portal_Account::render();
$denied_markup = ob_get_clean();
rw_test(
	'a customer without a plan cannot open the maintenance endpoint',
	false !== strpos( $denied_markup, 'data-access="no_plan"' )
		&& false !== strpos( $denied_markup, 'Maintenance is not on this account.' )
		&& false === strpos( $denied_markup, 'Connect WordPress' )
);

$GLOBALS['rw_current_user_id'] = 21;
$GLOBALS['rw_query_vars']['maintenance'] = '99';
ob_start();
RW_Portal_Account::render();
$foreign_markup = ob_get_clean();
unset( $GLOBALS['rw_query_vars']['maintenance'] );
rw_test(
	'a direct request for another subscription is refused',
	false !== strpos( $foreign_markup, 'data-access="not_owned"' )
		&& false === strpos( $foreign_markup, 'Your website is protected' )
);

rw_test(
	'products and licences endpoint requires at least one licensed record',
	! RW_Account_Access::licences_endpoint_allowed( 0 )
		&& RW_Account_Access::licences_endpoint_allowed( 1 )
);

$mixed_blocked = RW_Support_Offers::blocked_cart_product_ids(
	array(
		array( 'id' => 3657, 'slug' => 'wordpress-maintenance-standard' ),
		array( 'id' => 880, 'slug' => 'geo-core-pro' ),
		array( 'id' => 3663, 'slug' => 'additional-support-1h' ),
		array( 'id' => 3664, 'slug' => 'additional-support-5h' ),
		array( 'id' => 3665, 'slug' => 'technical-support-1h' ),
		array( 'id' => 3666, 'slug' => 'technical-support-5h' ),
	),
	false
);
$mixed_allowed = RW_Support_Offers::blocked_cart_product_ids(
	array(
		array( 'id' => 880, 'slug' => 'geo-core-pro' ),
		array( 'id' => 3663, 'slug' => 'additional-support-1h' ),
		array( 'id' => 3665, 'slug' => 'technical-support-1h' ),
	),
	true
);
rw_test(
	'mixed carts block only additional support when there is no active plan',
	array( 3663, 3664 ) === $mixed_blocked
		&& array() === $mixed_allowed
);

$stolen = RW_Support_Offers::checkout_context_from_request( 21, 3665, 'technical-support-1h', 42, 7, 22, 9, 'revolut_cc' );
$own_card = RW_Support_Offers::checkout_context_from_request( 21, 3665, 'technical-support-1h', 42, 7, 21, 9, 'revolut_cc' );
$licence_context = RW_Support_Offers::checkout_context_from_request( 21, 880, 'geo-core-pro', 0, 0, 21, 9, 'revolut_cc' );
rw_test(
	'checkout initiation keeps a saved card only for its owner and ignores licence products',
	is_array( $stolen )
		&& ! isset( $stolen['token_id'] )
		&& 42 === $stolen['subscription_id']
		&& 9 === $own_card['token_id']
		&& 'revolut_cc' === $own_card['gateway_id']
		&& null === $licence_context
		&& false === RW_Support_Purchase::saved_method_charge_authorised()
		&& true === RW_Support_Purchase::saved_method_charge_authorised( true )
);

rw_test(
	'purchased minute expiry stays unset until the product configures it',
	null === RW_Support_Balance::configured_validity_days( '' )
		&& null === RW_Support_Balance::configured_validity_days( '0' )
		&& null === RW_Support_Balance::purchase_row( array(), 88, 60, 0, '2026-11-08 12:00:00' )
		&& 365 === RW_Support_Balance::configured_validity_days( '365' )
		&& false === strpos( file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-credits.php' ), '$validity = 365' )
		&& false !== strpos( file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-credits.php' ), 'flag_policy_unset' )
		&& 'rw_support_credit_policy_unset' === RW_Support_Balance::CREDIT_POLICY_UNSET_OPTION
);

$token = RW_Portal_Account::issue_action_token( 21 );
$replay_first = RW_Account_Access::authorize_post(
	array(
		'user_id'     => 21,
		'nonce_ok'    => true,
		'token_ok'    => RW_Portal_Account::action_token_is_open( 21, $token ),
		'entitled'    => true,
		'owns_target' => true,
	)
);
RW_Portal_Account::consume_action_token( 21, $token );
$replay_second = RW_Account_Access::authorize_post(
	array(
		'user_id'     => 21,
		'nonce_ok'    => true,
		'token_ok'    => RW_Portal_Account::action_token_is_open( 21, $token ),
		'entitled'    => true,
		'owns_target' => true,
	)
);
$csrf = RW_Account_Access::authorize_post(
	array(
		'user_id'     => 21,
		'nonce_ok'    => false,
		'token_ok'    => true,
		'entitled'    => true,
		'owns_target' => true,
	)
);
$not_owner = RW_Account_Access::authorize_post(
	array(
		'user_id'     => 21,
		'nonce_ok'    => true,
		'token_ok'    => true,
		'entitled'    => true,
		'owns_target' => RW_Account_Access::target_owned( 99, array( 41, 42 ), 0, 0, 21 ),
	)
);
$site_owner = RW_Account_Access::target_owned( 0, array( 41, 42 ), 21, 42, 21 );
$site_thief = RW_Account_Access::target_owned( 0, array( 41, 42 ), 22, 42, 21 );
rw_test(
	'account actions require a fresh token, a valid nonce, and ownership',
	! empty( $replay_first['allow'] )
		&& 'replay' === $replay_second['code']
		&& 'csrf' === $csrf['code']
		&& 'ownership' === $not_owner['code']
		&& $site_owner
		&& ! $site_thief
		&& false === RW_Portal_Account::consume_action_token( 21, $token )
);

$offers_src = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-support-offers.php' );
$account_src_gate = file_get_contents( __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-account.php' );
rw_test(
	'cart and endpoint gates are registered for support packs and maintenance',
	false !== strpos( $offers_src, 'woocommerce_check_cart_items' )
		&& false !== strpos( $offers_src, 'woocommerce_add_to_cart_validation' )
		&& false !== strpos( $account_src_gate, 'maybe_block_unlicensed_endpoint' )
		&& false !== strpos( $account_src_gate, 'endpoint_response' )
);

$checkout_js = file_get_contents( __DIR__ . '/../reactwoo-support-portal/assets/js/account-support-checkout.js' );
rw_test(
	'stored-card checkout selects the method and does not submit payment',
	false !== strpos( $checkout_js, 'payment_method' )
		&& false !== strpos( $checkout_js, 'payment-token' )
		&& false === strpos( $checkout_js, 'submit' )
);

$GLOBALS['rw_current_user_id'] = 0;
unset( $GLOBALS['rw_user_subscriptions'] );
