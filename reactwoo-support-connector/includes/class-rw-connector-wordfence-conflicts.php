<?php
/**
 * Security plugins that clash with Wordfence.
 *
 * Detection is a pure list check. Nothing here deactivates or deletes a plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Connector_Wordfence_Conflicts {
	/**
	 * Plugins ReactWoo must never remove, even if a template or request names them.
	 *
	 * @return string[]
	 */
	public static function never_remove() {
		return array(
			'wordfence/wordfence.php',
			'reactwoo-support-connector/reactwoo-support-connector.php',
			'mainwp-child/mainwp-child.php',
			'mainwp-child-reports/mainwp-child-reports.php',
		);
	}

	/**
	 * @return array<int,array{file:string,name:string,action:string,reason:string}>
	 */
	public static function catalog() {
		return array(
			self::entry( 'sucuri-scanner/sucuri.php', 'Sucuri Security', 'remove', 'Sucuri has its own firewall and malware scanner. Running it beside Wordfence can block the site or hide the cause of a lockout.' ),
			self::entry( 'all-in-one-wp-security-and-firewall/wp-security.php', 'All-In-One Security (AIOS)', 'remove', 'All-In-One Security changes login protection and firewall rules that overlap with Wordfence.' ),
			self::entry( 'better-wp-security/better-wp-security.php', 'Solid Security (iThemes Security)', 'remove', 'Solid Security, formerly iThemes Security, overlaps Wordfence on the firewall, scans and login protection.' ),
			self::entry( 'ithemes-security-pro/ithemes-security-pro.php', 'iThemes Security Pro', 'remove', 'iThemes Security Pro overlaps Wordfence on the firewall, scans and login protection.' ),
			self::entry( 'ithemes-security/ithemes-security.php', 'iThemes Security', 'remove', 'iThemes Security overlaps Wordfence on the firewall, scans and login protection.' ),
			self::entry( 'solid-security/solid-security.php', 'Solid Security', 'remove', 'Solid Security overlaps Wordfence on the firewall, scans and login protection.' ),
			self::entry( 'wp-simple-firewall/icwp-wpsf.php', 'Shield Security', 'remove', 'Shield Security runs its own firewall, which clashes with Wordfence.' ),
			self::entry( 'wp-cerber/wp-cerber.php', 'WP Cerber Security', 'remove', 'WP Cerber runs its own firewall and login protection, which clash with Wordfence.' ),
			self::entry( 'malcare-security/malcare.php', 'MalCare', 'remove', 'MalCare runs its own firewall and malware cleaner, which clash with Wordfence.' ),
			self::entry( 'defender-security/wp-defender.php', 'Defender Security', 'remove', 'Defender runs its own firewall and scan, which clash with Wordfence.' ),
			self::entry( 'wp-defender/wp-defender.php', 'Defender', 'remove', 'Defender runs its own firewall and scan, which clash with Wordfence.' ),
			self::entry( 'ninjafirewall/ninjafirewall.php', 'NinjaFirewall', 'remove', 'NinjaFirewall is a separate firewall and clashes with Wordfence.' ),
			self::entry( 'gotmls/index.php', 'Anti-Malware Security and Brute-Force Firewall', 'remove', 'This plugin runs its own firewall and malware scan, which clash with Wordfence.' ),
			self::entry( 'sg-security/sg-security.php', 'SiteGround Security', 'remove', 'SiteGround Security overlaps Wordfence on login protection and hardening.' ),
			self::entry( 'secupress/secupress.php', 'SecuPress', 'remove', 'SecuPress runs its own firewall and scan, which clash with Wordfence.' ),
			self::entry( 'bulletproof-security/bulletproof-security.php', 'BulletProof Security', 'remove', 'BulletProof Security rewrites server rules in a way that clashes with Wordfence.' ),
			self::entry( 'security-malware-firewall/security-malware-firewall.php', 'CleanTalk Security', 'remove', 'CleanTalk Security runs its own firewall, which clashes with Wordfence.' ),
			self::entry( 'wordfence-login-security/wordfence-login-security.php', 'Wordfence Login Security', 'remove', 'This is the old standalone Wordfence Login Security plugin. Current Wordfence already includes it, and the two copies clash.' ),
			self::entry( 'patchstack/patchstack.php', 'Patchstack', 'remove', 'Patchstack runs its own firewall, which clashes with Wordfence.' ),
			self::entry( 'webarx/webarx.php', 'WebARX', 'remove', 'WebARX, now Patchstack, runs its own firewall, which clashes with Wordfence.' ),
			self::entry( 'jetpack-protect/jetpack-protect.php', 'Jetpack Protect', 'remove', 'Jetpack Protect adds a firewall that overlaps with Wordfence.' ),
			self::entry( 'block-bad-queries/block-bad-queries.php', 'Block Bad Queries', 'remove', 'Block Bad Queries is a firewall and overlaps with Wordfence.' ),
			self::entry( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', 'Limit Login Attempts Reloaded', 'review', 'This overlaps Wordfence login protection. You can leave it. Tick it only if you want it removed.' ),
			self::entry( 'loginizer/loginizer.php', 'Loginizer', 'review', 'This overlaps Wordfence login protection. You can leave it. Tick it only if you want it removed.' ),
			self::entry( 'wps-limit-login/wps-limit-login.php', 'Limit Login Attempts', 'review', 'This overlaps Wordfence login protection. You can leave it. Tick it only if you want it removed.' ),
			self::entry( 'wp-fail2ban/wp-fail2ban.php', 'WP fail2ban', 'review', 'This overlaps Wordfence login protection at the server. You can leave it. Tick it only if you want it removed.' ),
			self::entry( 'wps-hide-login/wps-hide-login.php', 'WPS Hide Login', 'review', 'A hidden login address can clash with Wordfence login security. You can leave it. Tick it only if you want it removed.' ),
			self::entry( 'hide-my-wp/hide-my-wp.php', 'Hide My WP', 'review', 'Hiding WordPress paths can clash with Wordfence. You can leave it. Tick it only if you want it removed.' ),
			self::entry( 'hide-my-wp-ghost/hide-my-wp-ghost.php', 'Hide My WP Ghost', 'review', 'Hiding WordPress paths can clash with Wordfence. You can leave it. Tick it only if you want it removed.' ),
		);
	}

	/**
	 * @param array<string,mixed> $plugins get_plugins() shape.
	 * @param bool                $pro_targeted True when a Pro licence will be applied.
	 * @return array<int,array<string,mixed>>
	 */
	public static function conflicts_in( array $plugins, $pro_targeted ) {
		$found = array();
		$seen  = array();

		foreach ( self::catalog() as $entry ) {
			$match = self::match_entry( $plugins, $entry['file'] );
			if ( '' === $match || isset( $seen[ strtolower( $match ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $match ) ] = true;
			$found[]                       = self::row_from_plugin( $entry, $match, $plugins );
		}

		$wordfence = self::match_entry( $plugins, 'wordfence/wordfence.php' );
		if ( $pro_targeted && '' !== $wordfence && ! isset( $seen[ strtolower( $wordfence ) ] ) ) {
			$found[] = array(
				'file'     => $wordfence,
				'name'     => 'Wordfence',
				'action'   => 'licence',
				'reason'   => 'Wordfence Free is already installed. Pro uses the same plugin, so it stays and the licence is applied in place.',
				'blocking' => false,
			);
		}

		return $found;
	}

	/**
	 * @param array<int,array<string,mixed>> $conflicts
	 * @return bool
	 */
	public static function has_blocking( array $conflicts ) {
		foreach ( $conflicts as $row ) {
			if ( is_array( $row ) && ! empty( $row['blocking'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Files the client confirmed, limited to plugins we detected and allow.
	 *
	 * @param mixed                          $requested
	 * @param array<int,array<string,mixed>> $detected
	 * @return string[]
	 */
	public static function removal_plan( $requested, array $detected ) {
		if ( ! is_array( $requested ) ) {
			return array();
		}

		$allowed = array();
		foreach ( $detected as $row ) {
			if ( ! is_array( $row ) || empty( $row['file'] ) ) {
				continue;
			}
			$action = isset( $row['action'] ) ? (string) $row['action'] : '';
			if ( 'remove' !== $action && 'review' !== $action ) {
				continue;
			}
			$file = self::normalise_plugin_file( $row['file'] );
			if ( '' === $file || self::is_protected_file( $file ) ) {
				continue;
			}
			$allowed[ strtolower( $file ) ] = $file;
		}

		$plan = array();
		foreach ( $requested as $file ) {
			$normal = self::normalise_plugin_file( $file );
			if ( '' === $normal || self::is_protected_file( $normal ) ) {
				continue;
			}
			$key = strtolower( $normal );
			if ( isset( $allowed[ $key ] ) ) {
				$plan[] = $allowed[ $key ];
			}
		}

		return array_values( array_unique( $plan ) );
	}

	/**
	 * @param mixed $file
	 * @return string
	 */
	public static function normalise_plugin_file( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		$file = ltrim( $file, '/' );
		if ( '' === $file || false !== strpos( $file, '..' ) || false !== strpos( $file, "\0" ) ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9._/-]*\.php$#i', $file ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * @param string $file
	 * @return bool
	 */
	public static function is_protected_file( $file ) {
		$file = strtolower( self::normalise_plugin_file( $file ) );
		if ( '' === $file ) {
			return true;
		}
		if ( 0 === strpos( $file, 'wordfence/' ) ) {
			return true;
		}
		foreach ( self::never_remove() as $protected ) {
			if ( $file === strtolower( $protected ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $file
	 * @param string $name
	 * @param string $action remove|review
	 * @param string $reason
	 * @return array{file:string,name:string,action:string,reason:string}
	 */
	private static function entry( $file, $name, $action, $reason ) {
		return array(
			'file'   => $file,
			'name'   => $name,
			'action' => $action,
			'reason' => $reason,
		);
	}

	/**
	 * @param array<string,mixed>             $plugins
	 * @param array<string,string>            $entry
	 * @param string                          $file
	 * @return array<string,mixed>
	 */
	private static function row_from_plugin( array $entry, $file, array $plugins ) {
		$name = $entry['name'];
		if ( isset( $plugins[ $file ] ) && is_array( $plugins[ $file ] ) && ! empty( $plugins[ $file ]['Name'] ) ) {
			$name = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $plugins[ $file ]['Name'] ) : (string) $plugins[ $file ]['Name'];
		}

		return array(
			'file'     => $file,
			'name'     => $name,
			'action'   => $entry['action'],
			'reason'   => $entry['reason'],
			'blocking' => 'remove' === $entry['action'],
		);
	}

	/**
	 * @param array<string,mixed> $plugins
	 * @param string              $catalog_file
	 * @return string Installed plugin file, or empty.
	 */
	private static function match_entry( array $plugins, $catalog_file ) {
		$catalog_file = strtolower( $catalog_file );
		$folder       = strtolower( dirname( $catalog_file ) );
		foreach ( $plugins as $file => $header ) {
			unset( $header );
			$normal = self::normalise_plugin_file( $file );
			if ( '' === $normal ) {
				continue;
			}
			$lower = strtolower( $normal );
			if ( $lower === $catalog_file || 0 === strpos( $lower, $folder . '/' ) ) {
				return $normal;
			}
		}

		return '';
	}
}
