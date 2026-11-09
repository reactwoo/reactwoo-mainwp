<?php
/**
 * Portal side of Wordfence onboarding.
 *
 * Conflicts are shown to the client. Nothing is deactivated or removed until
 * they confirm on this page. The optional email is the
 * rw_portal_wordfence_conflict_email filter and stays off unless that filter
 * returns true.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Portal_Wordfence {
	public static function register() {
		add_action( 'rw_portal_site_connected', array( __CLASS__, 'begin_onboarding' ) );
	}

	/**
	 * @param object $site Portal site.
	 * @return void
	 */
	public static function begin_onboarding( $site ) {
		try {
			self::start( $site, false, array() );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * @param int    $user_id
	 * @param string $action
	 * @return bool
	 */
	public static function handle( $user_id, $action ) {
		$user_id = (int) $user_id;
		$action  = sanitize_key( (string) $action );
		$site_id = isset( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
		$site    = ( $site_id && class_exists( 'RW_Sites' ) ) ? RW_Sites::get_site( $site_id ) : null;
		if ( ! $site || (int) $site->user_id !== $user_id ) {
			self::notice( $user_id, 'warning', 'Site not found', 'That website is not on this account.' );
			return true;
		}

		if ( empty( $_POST['rw_confirm_action'] ) ) {
			self::notice( $user_id, 'warning', 'Confirm required', 'Tick the confirmation box before this can run.' );
			return true;
		}

		if ( 'wordfence_remove_conflicts' === $action ) {
			$files = self::selected_files( isset( $_POST['rw_wordfence_files'] ) ? wp_unslash( $_POST['rw_wordfence_files'] ) : array() );
			if ( empty( $files ) ) {
				self::notice( $user_id, 'warning', 'Nothing selected', 'Select at least one plugin. Nothing was deactivated or removed.' );
				return true;
			}
			self::start( $site, true, $files );
			return true;
		}

		if ( 'wordfence_setup' === $action ) {
			self::start( $site, false, array() );
			return true;
		}

		return false;
	}

	/**
	 * @param object $site
	 * @return array<string,mixed>
	 */
	public static function view_for_site( $site ) {
		$stored = array();
		if ( is_object( $site ) && ! empty( $site->id ) ) {
			$stored = get_option( self::option_key( (int) $site->id ), array() );
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored = self::public_record( $stored );
		if ( empty( $stored ) ) {
			return array(
				'status'          => 'waiting',
				'conflicts'       => array(),
				'removable'       => array(),
				'optional'        => array(),
				'notices'         => array(),
				'report'          => array(
					'version'           => '',
					'pro'               => false,
					'template_applied'  => '',
					'conflicts_removed' => array(),
				),
				'message'         => 'Wordfence has not been set up on this website yet.',
				'show_setup'      => true,
				'show_conflicts'  => false,
				'approval_status' => '',
				'limits'          => array(),
			);
		}

		$report    = isset( $stored['report'] ) && is_array( $stored['report'] ) ? $stored['report'] : array();
		$version   = isset( $report['version'] ) ? (string) $report['version'] : '';
		$applied   = isset( $report['template_applied'] ) ? (string) $report['template_applied'] : '';
		$approval  = isset( $stored['approval_status'] ) ? (string) $stored['approval_status'] : '';
		$waiting   = ( 'awaiting_approval' === $approval && '' !== $version );
		$conflicts = isset( $stored['conflicts'] ) && is_array( $stored['conflicts'] ) ? $stored['conflicts'] : array();
		$removable = array();
		$optional  = array();
		$notices   = array();
		foreach ( $conflicts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$action = isset( $row['action'] ) ? (string) $row['action'] : '';
			if ( 'remove' === $action ) {
				$removable[] = $row;
			} elseif ( 'review' === $action ) {
				$optional[] = $row;
			} else {
				$notices[] = $row;
			}
		}

		return array(
			'status'          => isset( $stored['status'] ) ? (string) $stored['status'] : 'waiting',
			'conflicts'       => $conflicts,
			'removable'       => $removable,
			'optional'        => $optional,
			'notices'         => $notices,
			'report'          => $report,
			'message'         => isset( $stored['message'] ) ? (string) $stored['message'] : '',
			'show_setup'      => empty( $removable ) && ! $waiting && ( '' === $version || '' === $applied ),
			'show_conflicts'  => ! empty( $removable ) || ! empty( $optional ) || ! empty( $notices ),
			'approval_status' => $approval,
			'limits'          => isset( $stored['limits'] ) && is_array( $stored['limits'] ) ? $stored['limits'] : array(),
		);
	}

	/**
	 * @return bool
	 */
	public static function conflict_email_enabled() {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}

		return (bool) apply_filters( 'rw_portal_wordfence_conflict_email', false );
	}

	/**
	 * @param object                         $site
	 * @param array<int,array<string,mixed>> $conflicts
	 * @return bool
	 */
	public static function maybe_notify_conflicts( $site, array $conflicts ) {
		if ( ! self::conflict_email_enabled() || empty( $conflicts ) || ! is_object( $site ) ) {
			return false;
		}
		$email = self::client_email( $site );
		if ( '' === $email || ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		return (bool) wp_mail(
			$email,
			'Review security plugins before Wordfence is installed',
			self::email_body( $site, $conflicts )
		);
	}

	/**
	 * @param object                         $site
	 * @param array<int,array<string,mixed>> $conflicts
	 * @return string
	 */
	public static function email_body( $site, array $conflicts ) {
		$lines = array();
		foreach ( $conflicts as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$lines[] = '- ' . (string) $row['name'];
		}
		$host = ( is_object( $site ) && ! empty( $site->site_url ) ) ? (string) $site->site_url : 'your website';
		$url  = function_exists( 'home_url' ) ? home_url( '/my-account/' ) : '';

		return "Security plugins on {$host} need a decision before Wordfence is installed.\n\nNothing is deactivated or removed until you confirm in your ReactWoo account.\n\n" . implode( "\n", $lines ) . "\n\n" . $url . "\n";
	}

	/**
	 * @param object $site
	 * @return string
	 */
	public static function client_email( $site ) {
		$raw = '';
		if ( is_object( $site ) && ! empty( $site->report_email ) ) {
			$raw = (string) $site->report_email;
		} elseif ( is_object( $site ) && ! empty( $site->client_email ) ) {
			$raw = (string) $site->client_email;
		}
		$parts = explode( ',', $raw );
		$first = trim( (string) $parts[0] );

		return function_exists( 'sanitize_email' ) ? sanitize_email( $first ) : $first;
	}

	/**
	 * @param mixed $posted
	 * @return string[]
	 */
	public static function selected_files( $posted ) {
		if ( ! is_array( $posted ) ) {
			return array();
		}
		$files = array();
		foreach ( $posted as $file ) {
			if ( ! is_scalar( $file ) ) {
				continue;
			}
			$text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $file ) : trim( (string) $file );
			if ( '' !== $text ) {
				$files[] = $text;
			}
		}

		return $files;
	}

	/**
	 * @param mixed $data
	 * @return array<string,mixed>
	 */
	public static function public_record( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( class_exists( 'RW_Redact' ) ) {
			$data = RW_Redact::scrub( $data );
		}
		unset( $data['licence_key'], $data['license_key'], $data['apiKey'], $data['api_key'] );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param object   $site
	 * @param bool     $confirm
	 * @param string[] $files
	 * @return void
	 */
	private static function start( $site, $confirm, array $files ) {
		if ( ! is_object( $site ) || empty( $site->id ) || empty( $site->site_url ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 );
		}

		$secret = ( class_exists( 'RW_Tokens' ) ) ? RW_Tokens::get_site_secret( (int) $site->id ) : '';
		if ( ! is_string( $secret ) || '' === $secret ) {
			self::remember(
				(int) $site->id,
				array(
					'status'  => 'waiting',
					'message' => 'Wordfence setup is waiting until this website finishes enrolment.',
				)
			);
			return;
		}

		$template = array();
		if ( class_exists( 'RW_Maint_Client' ) ) {
			$fetched = RW_Maint_Client::wordfence_template();
			if ( ! is_wp_error( $fetched ) && isset( $fetched['template'] ) && is_array( $fetched['template'] ) ) {
				$template = $fetched['template'];
			}
		}

		$licence = '';
		if ( class_exists( 'RW_Maint_Client' ) ) {
			$licence_result = RW_Maint_Client::wordfence_licence( (int) $site->id );
			if ( ! is_wp_error( $licence_result ) && ! empty( $licence_result['licence_key'] ) && is_string( $licence_result['licence_key'] ) ) {
				$licence = $licence_result['licence_key'];
			}
			if ( is_array( $licence_result ) ) {
				unset( $licence_result['licence_key'], $licence_result['license_key'] );
			}
		}

		$body = array(
			'template'     => $template,
			'client_email' => self::client_email( $site ),
			'confirm'      => (bool) $confirm,
			'files'        => array_values( $files ),
		);
		if ( '' !== $licence ) {
			$body['licence_key'] = $licence;
		}

		$result = class_exists( 'RW_WordPress_Client' )
			? RW_WordPress_Client::connector_command( (string) $site->site_url, '/reactwoo-connector/v2/wordfence/onboard', (string) wp_json_encode( $body ), (int) $site->id, $secret, 120 )
			: new WP_Error( 'rw_wordfence_unavailable', 'Wordfence setup could not be started.' );

		$had_licence = '' !== $licence;
		$licence     = '';
		$body        = array();
		$public      = array();
		if ( is_wp_error( $result ) ) {
			$public = array(
				'status'  => 'failed',
				'message' => 'Wordfence setup could not be completed. No plugin was changed by this attempt.',
			);
		} elseif ( is_array( $result ) ) {
			$public = self::public_record( $result );
		}
		self::remember( (int) $site->id, $public );

		$conflicts = isset( $public['conflicts'] ) && is_array( $public['conflicts'] ) ? $public['conflicts'] : array();
		self::maybe_notify_conflicts( $site, $conflicts );

		if ( class_exists( 'RW_Audit' ) ) {
			RW_Audit::log(
				'wordfence_onboarding',
				array(
					'user_id'         => isset( $site->user_id ) ? (int) $site->user_id : 0,
					'subscription_id' => isset( $site->subscription_id ) ? (int) $site->subscription_id : 0,
					'managed_site_id' => (int) $site->id,
					'status'          => isset( $public['status'] ) ? sanitize_key( (string) $public['status'] ) : '',
					'template_applied' => isset( $public['report']['template_applied'] ) ? sanitize_text_field( (string) $public['report']['template_applied'] ) : '',
					'pro'             => ! empty( $public['report']['pro'] ),
					'licence_present' => $had_licence,
				)
			);
		}

		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() && class_exists( 'RW_Portal_Customer_Actions' ) ) {
			$message = isset( $public['message'] ) ? (string) $public['message'] : 'Wordfence setup has been updated.';
			$tone    = ( isset( $public['status'] ) && 'failed' === $public['status'] ) ? 'critical' : 'info';
			self::notice( (int) get_current_user_id(), $tone, 'Wordfence', $message );
		}
	}

	/**
	 * @param int                 $site_id
	 * @param array<string,mixed> $record
	 * @return void
	 */
	private static function remember( $site_id, array $record ) {
		update_option( self::option_key( (int) $site_id ), self::public_record( $record ), false );
	}

	/**
	 * @param int $site_id
	 * @return string
	 */
	private static function option_key( $site_id ) {
		return 'rw_portal_wordfence_' . (int) $site_id;
	}

	/**
	 * @param int    $user_id
	 * @param string $tone
	 * @param string $label
	 * @param string $message
	 * @return void
	 */
	private static function notice( $user_id, $tone, $label, $message ) {
		if ( class_exists( 'RW_Portal_Customer_Actions' ) ) {
			RW_Portal_Customer_Actions::set_status_notice( (int) $user_id, $tone, $label, $message );
		}
	}
}
