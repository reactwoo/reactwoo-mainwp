<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default ReactWoo values for the MainWP White Label extension.
 *
 * The extension has no REST route for these fields. The child update_branding
 * action replaces the whole branding record, so the hub does not push it.
 * Enter the values in the extension, globally or per site with Override
 * general settings.
 */
class RW_Maint_White_Label_Preset {
	const LOGIN_IMAGE      = 'reactwoo-login-logo.svg';
	const LOGIN_IMAGE_DARK = 'reactwoo-login-logo-dark-bg.svg';

	/**
	 * @return string[]
	 */
	public static function asset_files() {
		return array(
			self::LOGIN_IMAGE,
			'reactwoo-login-logo-320x84.png',
			'reactwoo-login-logo-640x168.png',
			self::LOGIN_IMAGE_DARK,
			'reactwoo-login-logo-dark-bg-640x168.png',
		);
	}

	/**
	 * @param string $file File name from asset_files().
	 * @return string
	 */
	public static function asset_path( $file ) {
		$file = ltrim( (string) $file, '/' );
		if ( ! in_array( $file, self::asset_files(), true ) || ! defined( 'RW_MAINT_DIR' ) ) {
			return '';
		}

		return RW_MAINT_DIR . 'assets/white-label/' . $file;
	}

	/**
	 * @param string $file File name from asset_files().
	 * @return string
	 */
	public static function asset_url( $file ) {
		$file = ltrim( (string) $file, '/' );
		if ( ! in_array( $file, self::asset_files(), true ) || ! defined( 'RW_MAINT_URL' ) ) {
			return '';
		}

		return RW_MAINT_URL . 'assets/white-label/' . $file;
	}

	/**
	 * Light login CSS. The box is not locked to the current export size.
	 *
	 * MainWP Child paints the login image at a fixed 70 by 310 pixel box.
	 * These rules override that so the Maintained by line is not cropped
	 * when the artwork changes.
	 *
	 * @return string
	 */
	public static function login_css() {
		return trim(
			<<<'CSS'
body.login {
	background-color: #F0F0F1;
}
body.login #login h1 a {
	background-size: contain !important;
	background-repeat: no-repeat !important;
	background-position: center center !important;
	width: min(100%, 20rem) !important;
	height: auto !important;
	min-height: 4.5rem !important;
	max-height: 9rem !important;
}
body.login .button.button-primary {
	background: #7D4DFF !important;
	border-color: #7D4DFF !important;
}
body.login input[type="text"]:focus,
body.login input[type="password"]:focus {
	border-color: #7D4DFF !important;
	box-shadow: 0 0 0 1px #7D4DFF !important;
}
CSS
		);
	}

	/**
	 * Use only together with the dark login image. Not the default.
	 *
	 * @return string
	 */
	public static function login_css_dark() {
		return trim(
			<<<'CSS'
body.login {
	background-color: #090A0F;
	color: #FFFFFF;
}
body.login #login h1 a {
	background-size: contain !important;
	background-repeat: no-repeat !important;
	background-position: center center !important;
	width: min(100%, 20rem) !important;
	height: auto !important;
	min-height: 4.5rem !important;
	max-height: 9rem !important;
}
body.login #login form {
	background: #11121A;
	border: 0;
}
body.login label,
body.login #nav a,
body.login #backtoblog a {
	color: #FFFFFF;
}
body.login .button.button-primary {
	background: #7D4DFF !important;
	border-color: #7D4DFF !important;
}
CSS
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function dark_variant() {
		return array(
			'when'        => 'Only when a dark login background is applied. Upload this image instead of the light one, and paste the dark login CSS. Do not use both images at once.',
			'login_image' => self::LOGIN_IMAGE_DARK,
			'login_css'   => self::login_css_dark(),
		);
	}

	/**
	 * Fields to type into MainWP > Add-ons > White Label.
	 *
	 * @return array<int,array{screen:string,field:string,value:string}>
	 */
	public static function extension_fields() {
		$support = class_exists( 'RW_Maint_Support_Mail' ) ? RW_Maint_Support_Mail::defaults() : array( 'support_email' => 'support@reactwoo.com' );
		$email   = isset( $support['support_email'] ) ? (string) $support['support_email'] : 'support@reactwoo.com';

		return array(
			array(
				'screen' => 'White Label',
				'field'  => 'Visually hide the MainWP Child plugin',
				'value'  => 'Enabled',
			),
			array(
				'screen' => 'White Label',
				'field'  => 'Plugin name',
				'value'  => 'ReactWoo Maintenance',
			),
			array(
				'screen' => 'White Label',
				'field'  => 'Plugin description',
				'value'  => 'Secure connection for ReactWoo website maintenance.',
			),
			array(
				'screen' => 'White Label',
				'field'  => 'Plugin URI',
				'value'  => 'https://reactwoo.com/',
			),
			array(
				'screen' => 'White Label',
				'field'  => 'Plugin author',
				'value'  => 'ReactWoo',
			),
			array(
				'screen' => 'White Label',
				'field'  => 'Author URI',
				'value'  => 'https://reactwoo.com/',
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Custom login page image',
				'value'  => self::LOGIN_IMAGE,
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Custom login image URL',
				'value'  => 'https://reactwoo.com/',
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Custom login image title',
				'value'  => 'ReactWoo',
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Dashboard Footer Content',
				'value'  => 'Managed by <a href="https://reactwoo.com/">ReactWoo</a>',
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Global Footer Content',
				'value'  => '',
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Custom Login CSS',
				'value'  => self::login_css(),
			),
			array(
				'screen' => 'WordPress Options',
				'field'  => 'Custom Admin CSS',
				'value'  => '',
			),
			array(
				'screen' => 'Remove & Disable Options',
				'field'  => 'Hide the MainWP Child Settings page',
				'value'  => 'Enabled',
			),
			array(
				'screen' => 'Remove & Disable Options',
				'field'  => 'Hide the MainWP Child Server Information page',
				'value'  => 'Enabled',
			),
			array(
				'screen' => 'Remove & Disable Options',
				'field'  => 'Hide the MainWP Child Restore (Clone) page',
				'value'  => 'Enabled',
			),
			array(
				'screen' => 'Support Form',
				'field'  => 'Activate the Support feature',
				'value'  => 'Enabled',
			),
			array(
				'screen' => 'Support Form',
				'field'  => 'Designated support contact email',
				'value'  => $email,
			),
			array(
				'screen' => 'Support Form',
				'field'  => 'Position of the Contact Support button',
				'value'  => 'Admin menu',
			),
		);
	}

	/**
	 * @return string[]
	 */
	public static function manual_steps() {
		return array(
			'Open MainWP, then Add-ons, then White Label. Enter the preset and save it so child sites receive the global values.',
			'For one site, open Sites, then Manage Sites, then that site\'s Settings. Open the White Label tab, enable Override general settings, and enter the same values.',
			'Upload the light login SVG as the custom login page image. If the uploader rejects SVG, use the larger PNG of the same lockup.',
			'Paste the light login CSS. It replaces the child plugin\'s fixed login box so the Maintained by line is not cropped, and it does not assume the current artwork size.',
			'Use the dark login image and the dark login CSS only when you want a dark login background. Upload the dark image in place of the light one. Do not add the dark image to the default CSS.',
			'Set the support form recipient to the same address as the hub support email. The form stays off while that address is empty. The connector cannot set this field on its own.',
			'MainWP REST API v2 has no White Label route. Do not send update_branding from the hub. That child action replaces logo, footer, CSS and the support form together.',
		);
	}

	/**
	 * What this preset deliberately leaves unset.
	 *
	 * @return string[]
	 */
	public static function not_set() {
		return array(
			'Admin bar logo. The extension has no admin bar image field, and the connector does not add one.',
			'Front-end footer. Global Footer Content stays empty so the public site keeps its own footer.',
			'A connector opt-out for login branding. Clear the login image on that site, or leave Override general settings off, in the extension.',
		);
	}
}
