<?php

/**
 * White Label preset and support mail routing.
 * The extension owns login, footer and plugin branding. The connector only
 * routes named notices and never changes admin_email or account mail.
 */

require_once __DIR__ . '/../reactwoo-support-connector/includes/class-rw-whitelabel-mail.php';
require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-support-mail.php';
require_once __DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-white-label-preset.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-support-mail.php';
require_once __DIR__ . '/../reactwoo-support-portal/includes/class-rw-maintenance-emails.php';

$rw_mail_sources = array(
	__DIR__ . '/../reactwoo-support-connector/includes/class-rw-whitelabel-mail.php',
	__DIR__ . '/../reactwoo-support-connector/includes/class-rw-whitelabel.php',
	__DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-support-mail.php',
	__DIR__ . '/../reactwoo-maint-bridge/includes/class-rw-maint-white-label-preset.php',
	__DIR__ . '/../reactwoo-support-portal/includes/class-rw-portal-support-mail.php',
);
$rw_mail_copy = '';
foreach ( $rw_mail_sources as $rw_mail_source ) {
	$rw_mail_copy .= file_get_contents( $rw_mail_source );
}
rw_test( 'support mail copy has no em dash', false === strpos( $rw_mail_copy, "\u{2014}" ) );

$mail_src = file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-whitelabel-mail.php' );
$hide_src = file_get_contents( __DIR__ . '/../reactwoo-support-connector/includes/class-rw-whitelabel.php' );
rw_test(
	'the connector does not print login or footer branding',
	false === strpos( $mail_src, 'login_enqueue_scripts' )
		&& false === strpos( $mail_src, 'login_headerurl' )
		&& false === strpos( $mail_src, 'admin_footer_text' )
		&& false === strpos( $hide_src, 'login_enqueue_scripts' )
		&& false === strpos( $hide_src, 'login_headerurl' )
);

$hub_defaults = RW_Maint_Support_Mail::defaults();
$site_defaults = RW_Whitelabel_Mail::defaults();
$portal_defaults = RW_Portal_Support_Mail::defaults();
rw_test(
	'hub, portal and connector share the support mail defaults',
	'support@reactwoo.com' === $hub_defaults['support_email']
		&& 'security_only' === $hub_defaults['notify_mode']
		&& $hub_defaults['support_email'] === $site_defaults['support_email']
		&& $hub_defaults['notify_mode'] === $site_defaults['notify_mode']
		&& $hub_defaults['notify_mode'] === $portal_defaults['notify_mode']
		&& in_array( 'cc_all', RW_Whitelabel_Mail::modes(), true )
		&& ! in_array( 'user_password', $hub_defaults['notify_categories'], true )
);

$preset = array();
foreach ( RW_Maint_White_Label_Preset::extension_fields() as $row ) {
	$preset[ $row['field'] ] = $row['value'];
}
$light_css = RW_Maint_White_Label_Preset::login_css();
$dark      = RW_Maint_White_Label_Preset::dark_variant();
$asset_dir = __DIR__ . '/../reactwoo-maint-bridge/assets/white-label/';
$assets_ok = true;
foreach ( RW_Maint_White_Label_Preset::asset_files() as $file ) {
	$assets_ok = $assets_ok && is_file( $asset_dir . $file ) && filesize( $asset_dir . $file ) > 0;
}
$light_png = getimagesize( $asset_dir . 'reactwoo-login-logo-320x84.png' );
$large_png = getimagesize( $asset_dir . 'reactwoo-login-logo-640x168.png' );
$dark_png  = getimagesize( $asset_dir . 'reactwoo-login-logo-dark-bg-640x168.png' );
$light_svg = file_get_contents( $asset_dir . 'reactwoo-login-logo.svg' );
$dark_svg  = file_get_contents( $asset_dir . 'reactwoo-login-logo-dark-bg.svg' );
rw_test(
	'the white label preset uses the light login lockup',
	'reactwoo-login-logo.svg' === $preset['Custom login page image']
		&& 'https://reactwoo.com/' === $preset['Custom login image URL']
		&& 'ReactWoo' === $preset['Custom login image title']
		&& 'support@reactwoo.com' === $preset['Designated support contact email']
		&& 'Enabled' === $preset['Visually hide the MainWP Child plugin']
		&& '' === $preset['Global Footer Content']
		&& false !== strpos( $preset['Dashboard Footer Content'], 'Managed by' )
		&& false === strpos( $preset['Custom login page image'], 'dark' )
);
rw_test(
	'login css stays flexible and the dark image is not the default',
	false === strpos( $light_css, '320' )
		&& false === strpos( $light_css, '84' )
		&& false === strpos( $light_css, '70px' )
		&& false === strpos( $light_css, '310px' )
		&& false !== strpos( $light_css, 'contain' )
		&& false === strpos( $light_css, 'dark-bg' )
		&& false === strpos( $light_css, '090A0F' )
		&& 'reactwoo-login-logo-dark-bg.svg' === $dark['login_image']
		&& false !== strpos( $dark['login_css'], '090A0F' )
		&& false === strpos( $dark['login_css'], '320' )
		&& false === strpos( $dark['when'], 'default' )
);
rw_test(
	'packaged login artwork includes the Maintained by line',
	$assets_ok
		&& is_array( $light_png ) && 320 === $light_png[0] && 84 === $light_png[1]
		&& is_array( $large_png ) && 640 === $large_png[0] && 168 === $large_png[1]
		&& is_array( $dark_png ) && 640 === $dark_png[0] && 168 === $dark_png[1]
		&& false !== strpos( $light_svg, 'Maintained by' )
		&& false !== strpos( $dark_svg, 'Maintained by' )
);
$steps = implode( ' ', RW_Maint_White_Label_Preset::manual_steps() );
rw_test(
	'the preset is entered in the extension and is not pushed with update_branding',
	false !== strpos( $steps, 'Override general settings' )
		&& false !== strpos( $steps, 'update_branding' )
		&& false !== strpos( implode( ' ', RW_Maint_White_Label_Preset::not_set() ), 'Admin bar logo' )
);

$parsed = RW_Maint_Support_Mail::parse_overrides( "44 alerts@reactwoo.com cc_all\nnope\n55 desk@reactwoo.com redirect\n" );
rw_test(
	'per site support addresses parse without a mode when it is omitted',
	'alerts@reactwoo.com' === $parsed[44]['support_email']
		&& 'cc_all' === $parsed[44]['notify_mode']
		&& 'redirect' === $parsed[55]['notify_mode']
		&& ! isset( $parsed[0] )
);
update_option(
	RW_Maint_Support_Mail::OPTION,
	array(
		'support_email'  => 'support@reactwoo.com',
		'notify_mode'    => 'security_only',
		'site_overrides' => $parsed,
	)
);
$site_44 = RW_Maint_Support_Mail::config_for_site( 44 );
$site_12 = RW_Maint_Support_Mail::config_for_site( 12 );
$site_55 = RW_Maint_Support_Mail::config_for_site( 55 );
rw_test(
	'a per site override changes only that site',
	'alerts@reactwoo.com' === $site_44['support_email']
		&& 'cc_all' === $site_44['notify_mode']
		&& 'support@reactwoo.com' === $site_12['support_email']
		&& 'security_only' === $site_12['notify_mode']
		&& false === $site_55['notify_keep_admin_copy']
		&& '' === $site_44['client_notify_email']
);

$cleared = RW_Maint_Support_Mail::sanitize(
	array(
		'notify_mode'                => 'not-a-mode',
		'support_email'              => 'not-an-email',
		'notify_categories_present'  => '1',
		'admin_email'                => 'support@reactwoo.com',
	)
);
rw_test(
	'invalid hub mail settings fall back and ignore admin_email',
	'security_only' === $cleared['notify_mode']
		&& 'support@reactwoo.com' === $cleared['support_email']
		&& array() === $cleared['notify_categories']
		&& ! isset( $cleared['admin_email'] )
);

update_option(
	RW_Portal_Support_Mail::OPTION,
	RW_Portal_Support_Mail::sanitize_clients( "9 owner@client.example\nbad\n" )
);
$payload = RW_Portal_Support_Mail::payload_for_site(
	9,
	array(
		'notify_mode'   => 'security_only',
		'support_email' => 'support@reactwoo.com',
		'admin_email'   => 'owner@client.example',
	)
);
rw_test(
	'the portal adds the client address and drops admin_email',
	'owner@client.example' === $payload['client_notify_email']
		&& 'security_only' === $payload['notify_mode']
		&& 'support@reactwoo.com' === $payload['support_email']
		&& ! isset( $payload['admin_email'] )
		&& 'owner@client.example' === RW_Portal_Support_Mail::client_email( 9 )
		&& '' === RW_Portal_Support_Mail::client_email( 3 )
);

$recipient = new ReflectionMethod( 'RW_Maintenance_Emails', 'recipient' );
$recipient->setAccessible( true );
$mailed_site = (object) array(
	'id'           => 9,
	'report_email' => 'reports@example.com',
	'user_id'      => 1,
);
$fallback_site = (object) array(
	'id'           => 3,
	'report_email' => 'reports@example.com',
	'user_id'      => 1,
);
rw_test(
	'portal mail uses the client notification email when one is set',
	'owner@client.example' === $recipient->invoke( null, $mailed_site )
		&& 'reports@example.com' === $recipient->invoke( null, $fallback_site )
);

$admin_before = get_option( 'admin_email' );
$applied = RW_Whitelabel_Mail::apply_config(
	array(
		'admin_email'   => 'support@reactwoo.com',
		'notify_mode'   => 'redirect',
		'support_email' => 'support@reactwoo.com',
		'notify_keep_admin_copy' => false,
	)
);
rw_test(
	'applying support mail does not write admin_email',
	$admin_before === get_option( 'admin_email' )
		&& ! isset( $GLOBALS['rw_options']['admin_email'] )
		&& ! isset( $applied['admin_email'] )
		&& 'redirect' === $applied['notify_mode']
);

$GLOBALS['rw_options']['rw_connector_managed'] = '1';

function rw_wl_headers( $args ) {
	$headers = isset( $args['headers'] ) ? $args['headers'] : array();
	return is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
}

$reset = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Password Reset',
		'message' => 'Reset your password',
		'headers' => array(),
	)
);
$new_user = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] New User Registration',
		'message' => 'New user',
		'headers' => array( 'X-RW-Mail-Category: wordfence' ),
	)
);
$login_details = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'owner@example.com',
		'subject' => '[Example] Login Details',
		'message' => 'Username and password',
		'headers' => array(),
	)
);
rw_test(
	'password resets and account mail are never redirected',
	'staff@example.com' === $reset['to']
		&& array() === $reset['headers']
		&& 'staff@example.com' === $new_user['to']
		&& false === strpos( rw_wl_headers( $new_user ), 'support@reactwoo.com' )
		&& 'owner@example.com' === $login_details['to']
		&& $admin_before === get_option( 'admin_email' )
		&& ! isset( $GLOBALS['rw_options']['admin_email'] )
);

RW_Whitelabel_Mail::apply_config( array( 'notify_mode' => 'security_only' ) );
$alert = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'Staff <staff@example.com>',
		'subject' => '[Example] Wordfence Alert: increased attack rate',
		'message' => 'Blocked',
		'headers' => array(),
	)
);
$plugin_mail = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Some plugins were automatically updated',
		'message' => 'Akismet',
		'headers' => array(),
	)
);
$core_mail = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] WordPress 6.8 is available. Please update!',
		'message' => 'Please update',
		'headers' => array(),
	)
);
$alert_headers = rw_wl_headers( $alert );
rw_test(
	'security alerts only copies Wordfence mail and leaves update mail',
	'Staff <staff@example.com>' === $alert['to']
		&& false !== strpos( $alert_headers, 'Cc: support@reactwoo.com' )
		&& false !== strpos( $alert_headers, 'Reply-To: ReactWoo Support <support@reactwoo.com>' )
		&& false === strpos( $alert_headers, 'From:' )
		&& 'staff@example.com' === $plugin_mail['to']
		&& array() === $plugin_mail['headers']
		&& array() === $core_mail['headers']
);

RW_Whitelabel_Mail::apply_config(
	array(
		'notify_mode'       => 'cc_all',
		'notify_categories' => array( 'wordfence' ),
	)
);
$copied_plugin = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Some plugins were automatically updated',
		'message' => 'Akismet',
		'headers' => array( 'X-RW-Mail-Category: connector' ),
	)
);
$comment = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Please moderate: new comment',
		'message' => 'Comment',
		'headers' => array(),
	)
);
$copied_headers = rw_wl_headers( $copied_plugin );
rw_test(
	'cc all copies maintenance notices and not comments',
	false !== strpos( $copied_headers, 'Cc: support@reactwoo.com' )
		&& false === strpos( $copied_headers, 'X-RW-Mail-Category' )
		&& 'staff@example.com' === $copied_plugin['to']
		&& array() === $comment['headers']
);

RW_Whitelabel_Mail::apply_config(
	array(
		'notify_mode'            => 'redirect',
		'notify_categories'      => array( 'wordfence' ),
		'notify_keep_admin_copy' => false,
	)
);
$redirected = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Wordfence Alert',
		'message' => 'Blocked',
		'headers' => array( 'Cc: extra@example.com' ),
	)
);
$redirect_headers = rw_wl_headers( $redirected );
rw_test(
	'redirect sends the security alert to support and can drop the site copy',
	'support@reactwoo.com' === $redirected['to']
		&& false === strpos( $redirect_headers, 'staff@example.com' )
		&& false !== strpos( $redirect_headers, 'extra@example.com' )
		&& ! isset( $GLOBALS['rw_options']['admin_email'] )
);

RW_Whitelabel_Mail::apply_config(
	array(
		'notify_mode'            => 'redirect',
		'notify_categories'      => array( 'wordfence' ),
		'notify_keep_admin_copy' => true,
	)
);
$kept = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Wordfence Alert',
		'message' => 'Blocked',
		'headers' => array(),
	)
);
rw_test(
	'redirect can keep a copy for the site admin',
	'support@reactwoo.com' === $kept['to']
		&& false !== strpos( rw_wl_headers( $kept ), 'Cc: staff@example.com' )
);

unset( $GLOBALS['rw_options']['rw_connector_managed'] );
$ignored = RW_Whitelabel_Mail::filter_mail(
	array(
		'to'      => 'staff@example.com',
		'subject' => '[Example] Wordfence Alert',
		'message' => 'Blocked',
		'headers' => array(),
	)
);
rw_test( 'an unmanaged site does not route mail', 'staff@example.com' === $ignored['to'] && array() === $ignored['headers'] );

$stored_before = get_option( RW_Whitelabel_Mail::OPTION );
RW_Whitelabel_Mail::apply_remote_response(
	array(
		'response' => array( 'code' => 500 ),
		'body'     => '{"support_mail":{"notify_mode":"leave"}}',
	)
);
rw_test( 'a failed heartbeat does not change support mail', $stored_before === get_option( RW_Whitelabel_Mail::OPTION ) );
RW_Whitelabel_Mail::apply_remote_response(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"received":true}',
	)
);
rw_test( 'a heartbeat without support mail leaves the saved settings', $stored_before === get_option( RW_Whitelabel_Mail::OPTION ) );
RW_Whitelabel_Mail::apply_remote_response(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'received'     => true,
				'support_mail' => array(
					'notify_mode'   => 'cc_all',
					'support_email' => 'desk@reactwoo.com',
					'admin_email'   => 'desk@reactwoo.com',
				),
			)
		),
	)
);
$from_heartbeat = get_option( RW_Whitelabel_Mail::OPTION );
rw_test(
	'a signed heartbeat applies support mail and still ignores admin_email',
	is_array( $from_heartbeat )
		&& 'cc_all' === $from_heartbeat['notify_mode']
		&& 'desk@reactwoo.com' === $from_heartbeat['support_email']
		&& ! isset( $from_heartbeat['admin_email'] )
		&& ! isset( $GLOBALS['rw_options']['admin_email'] )
);

$missing_hub = RW_Portal_Support_Mail::payload_for_site( 77 );
rw_test( 'a missing hub response does not invent support mail', null === $missing_hub );

unset( $GLOBALS['rw_options']['rw_connector_managed'], $GLOBALS['rw_options'][ RW_Whitelabel_Mail::OPTION ], $GLOBALS['rw_options'][ RW_Maint_Support_Mail::OPTION ], $GLOBALS['rw_options'][ RW_Portal_Support_Mail::OPTION ] );
