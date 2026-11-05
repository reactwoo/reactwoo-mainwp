<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cached customer view of one site's maintenance snapshot.
 *
 * The bridge response is stored for 15 minutes. Cards are plain data so the
 * template can be restyled without changing how the snapshot is read.
 */
class RW_Portal_Maintenance_Status {
	const CACHE_TTL = 900;

	/**
	 * Zone used while a snapshot is being turned into customer text.
	 *
	 * @var string|null
	 */
	private static $display_timezone = null;

	/**
	 * @param object        $site Portal site.
	 * @param callable|null $fetcher Optional replacement for the bridge call.
	 * @return array<string,mixed>
	 */
	public static function for_site( $site, $fetcher = null ) {
		if ( ! is_object( $site ) || empty( $site->id ) ) {
			return array();
		}

		$key    = self::cache_key( $site );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['portal_site_id'] ) ) {
			return $cached;
		}

		$fresh = is_callable( $fetcher ) ? call_user_func( $fetcher, $site ) : RW_Maint_Client::fetch_site_status( $site );
		if ( is_wp_error( $fresh ) || ! is_array( $fresh ) || ! isset( $fresh['portal_site_id'] ) ) {
			return array();
		}

		set_transient( $key, $fresh, self::CACHE_TTL );

		return $fresh;
	}

	/**
	 * @param object $site Portal site.
	 * @return array<string,mixed>
	 */
	public static function view_for_site( $site ) {
		$payload = self::for_site( $site );
		if ( is_array( $payload ) && ( ! isset( $payload['timezone'] ) || null === $payload['timezone'] || '' === $payload['timezone'] ) && is_object( $site ) && ! empty( $site->timezone_string ) ) {
			$payload['timezone'] = (string) $site->timezone_string;
		}

		return self::present( $payload );
	}

	/**
	 * @param object $site Portal site.
	 * @return string
	 */
	public static function cache_key( $site ) {
		$site_id         = is_object( $site ) && isset( $site->id ) ? (int) $site->id : 0;
		$subscription_id = is_object( $site ) && isset( $site->subscription_id ) ? (int) $site->subscription_id : 0;

		return 'rw_maint_status_' . $site_id . '_' . $subscription_id;
	}

	/**
	 * @param array<string,mixed> $payload Bridge snapshot. Missing keys stay unknown.
	 * @return array<string,mixed>
	 */
	public static function present( array $payload ) {
		$previous                 = self::$display_timezone;
		self::$display_timezone   = isset( $payload['timezone'] ) ? $payload['timezone'] : null;
		try {
			return self::present_in_zone( $payload );
		} finally {
			self::$display_timezone = $previous;
		}
	}

	/**
	 * @param array<string,mixed> $payload Bridge snapshot.
	 * @return array<string,mixed>
	 */
	private static function present_in_zone( array $payload ) {
		$cards = array(
			self::card_core( $payload ),
			self::card_updates( $payload ),
			self::card_security( $payload ),
			self::card_health( $payload ),
			self::card_backups( $payload ),
			self::card_uptime( $payload ),
		);
		foreach ( $cards as $index => $card ) {
			$cards[ $index ]['tone'] = self::card_tone( $card['id'], $card['state'] );
		}
		$login = self::section_login( $payload );
		$login['tone'] = 'ink';

		return array(
			'cards'    => $cards,
			'login'    => $login,
			'content'  => self::section_content( $payload ),
			'activity' => self::section_activity( $payload ),
			'premium'   => self::premium_panel( $payload ),
			'summary'   => self::site_summary( $payload ),
			'flags'     => self::attention_flags( $payload ),
			'inventory'      => self::inventory_panel( $payload ),
			'backup_panel'   => self::backup_panel( $payload ),
		);
	}

	/**
	 * Dark cards are the attention areas from the account design.
	 * Security stays on a white card until there is an open issue.
	 *
	 * @param string $id Card id.
	 * @param string $state good, attention, or unknown.
	 * @return string ink or paper.
	 */
	public static function card_tone( $id, $state ) {
		if ( 'security' === $id ) {
			return 'attention' === $state ? 'ink' : 'paper';
		}
		if ( in_array( $id, array( 'updates', 'health', 'backups', 'premium', 'login' ), true ) ) {
			return 'ink';
		}

		return 'paper';
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<int,string>
	 */
	public static function attention_flags( array $payload ) {
		$lines   = array();
		$premium = isset( $payload['plugins']['premium'] ) && is_array( $payload['plugins']['premium'] ) ? $payload['plugins']['premium'] : array();
		$expired = 0;
		foreach ( $premium as $item ) {
			if ( is_array( $item ) && isset( $item['licence_status'] ) && 'expired' === $item['licence_status'] ) {
				$expired++;
			}
		}
		if ( 1 === $expired ) {
			$lines[] = '1 premium licence expired';
		} elseif ( $expired > 1 ) {
			$lines[] = $expired . ' premium licences expired';
		}

		$backups = isset( $payload['backups'] ) && is_array( $payload['backups'] ) ? $payload['backups'] : array();
		$last    = isset( $backups['last']['status'] ) ? (string) $backups['last']['status'] : '';
		$failed  = 0;
		if ( ! empty( $backups['recent'] ) && is_array( $backups['recent'] ) ) {
			foreach ( $backups['recent'] as $row ) {
				if ( is_array( $row ) && isset( $row['status'] ) && 'failed' === $row['status'] ) {
					$failed++;
				}
			}
		}
		if ( 'failed' === $last ) {
			$lines[] = 'Last backup failed';
		} elseif ( 'success' === $last && 1 === $failed ) {
			$lines[] = 'Backup retried once (OK)';
		} elseif ( 'success' === $last && $failed > 1 ) {
			$lines[] = $failed . ' earlier backups failed';
		}

		$issues = isset( $payload['security']['issues_count'] ) ? $payload['security']['issues_count'] : null;
		if ( is_numeric( $issues ) && (int) $issues > 0 ) {
			$count   = (int) $issues;
			$lines[] = $count . ' security ' . ( 1 === $count ? 'issue' : 'issues' );
		}

		$health = isset( $payload['health']['status'] ) ? (string) $payload['health']['status'] : '';
		if ( in_array( $health, array( 'critical', 'needs_attention' ), true ) ) {
			$lines[] = 'Site health needs attention';
		} elseif ( 'should_be_improved' === $health ) {
			$lines[] = 'Site health should be improved';
		}

		return $lines;
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function site_summary( array $payload ) {
		$plugins = isset( $payload['plugins']['pending_count'] ) ? $payload['plugins']['pending_count'] : null;
		$themes  = isset( $payload['themes']['pending_count'] ) ? $payload['themes']['pending_count'] : null;
		$pending = null;
		if ( null !== $plugins || null !== $themes ) {
			$pending = ( null === $plugins ? 0 : (int) $plugins ) + ( null === $themes ? 0 : (int) $themes );
		}
		$backup = '';
		if ( isset( $payload['backups']['last']['time'] ) ) {
			$backup = self::format_when( $payload['backups']['last']['time'] );
		}

		return array(
			'updates_pending'   => $pending,
			'updates_label'     => null === $pending ? 'Not checked yet' : $pending . ' pending',
			'updates_attention' => null !== $pending && $pending > 0,
			'backup_label'      => '' !== $backup ? $backup : 'Not available yet',
			'flags'             => self::attention_flags( $payload ),
		);
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array{known:bool,total:?int,plugins:?int,themes:?int,label:string,detail:string}
	 */
	private static function inventory_panel( array $payload ) {
		$inventory = isset( $payload['inventory'] ) && is_array( $payload['inventory'] ) ? $payload['inventory'] : array();
		$plugins   = isset( $inventory['plugins'] ) && is_numeric( $inventory['plugins'] ) ? (int) $inventory['plugins'] : null;
		$themes    = isset( $inventory['themes'] ) && is_numeric( $inventory['themes'] ) ? (int) $inventory['themes'] : null;
		if ( null === $plugins && null === $themes ) {
			return array(
				'known'   => false,
				'total'   => null,
				'plugins' => null,
				'themes'  => null,
				'label'   => 'Not available yet',
				'detail'  => 'Installed plugins and themes have not been reported yet.',
			);
		}
		$total  = ( null === $plugins ? 0 : $plugins ) + ( null === $themes ? 0 : $themes );
		$detail = ( null === $plugins ? 'Plugins not reported' : $plugins . ' ' . ( 1 === $plugins ? 'plugin' : 'plugins' ) );
		$detail .= ' and ' . ( null === $themes ? 'themes not reported' : $themes . ' ' . ( 1 === $themes ? 'theme' : 'themes' ) );

		return array(
			'known'   => true,
			'total'   => $total,
			'plugins' => $plugins,
			'themes'  => $themes,
			'label'   => $total . ' ' . ( 1 === $total ? 'item' : 'items' ),
			'detail'  => $detail,
		);
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function premium_panel( array $payload ) {
		$premium = isset( $payload['plugins']['premium'] ) ? $payload['plugins']['premium'] : null;
		$items   = array();
		if ( is_array( $premium ) ) {
			foreach ( $premium as $row ) {
				if ( ! is_array( $row ) || empty( $row['name'] ) ) {
					continue;
				}
				$status = isset( $row['licence_status'] ) ? (string) $row['licence_status'] : 'unknown';
				if ( ! in_array( $status, array( 'active', 'expired', 'unknown' ), true ) ) {
					$status = 'unknown';
				}
				$expires = self::format_day( isset( $row['licence_expires_at'] ) ? $row['licence_expires_at'] : null );
				$licence = 'Unknown';
				if ( 'active' === $status ) {
					$licence = '' !== $expires ? 'Active · ' . $expires : 'Active';
				} elseif ( 'expired' === $status ) {
					$licence = '' !== $expires ? 'Expired · ' . $expires : 'Expired';
				}
				$update = ! empty( $row['update_available'] );
				$new    = isset( $row['new_version'] ) ? (string) $row['new_version'] : '';
				$items[] = array(
					'name'         => (string) $row['name'],
					'version'      => isset( $row['current_version'] ) && '' !== (string) $row['current_version'] ? (string) $row['current_version'] : 'Version not reported',
					'source'       => isset( $row['update_source'] ) ? (string) $row['update_source'] : '',
					'update'       => $update ? ( '' !== $new ? 'Update ' . $new : 'Update' ) : 'Up to date',
					'update_tone'  => $update ? 'warn' : 'good',
					'licence'      => $licence,
					'licence_tone' => 'active' === $status ? 'good' : ( 'expired' === $status ? 'bad' : 'muted' ),
				);
			}
		}

		$count = is_array( $premium ) ? count( $items ) : null;

		return array(
			'tone'  => 'ink',
			'known' => is_array( $premium ),
			'count' => $count,
			'title' => null === $count ? 'Premium plugins' : $count . ' premium ' . ( 1 === $count ? 'plugin' : 'plugins' ) . ' tracked',
			'empty' => null === $count ? 'Premium plugin information has not been reported yet.' : 'No premium plugins were reported.',
			'items' => $items,
		);
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function card_core( array $payload ) {
		$core    = isset( $payload['core'] ) && is_array( $payload['core'] ) ? $payload['core'] : array();
		$version = isset( $core['wordpress_version'] ) ? (string) $core['wordpress_version'] : '';
		$offered = isset( $core['new_version'] ) ? (string) $core['new_version'] : '';
		$update  = array_key_exists( 'update_available', $core ) ? $core['update_available'] : null;

		if ( '' === $version && null === $update ) {
			return self::card( 'core', 'Core', 'WordPress', 'unknown', 'Not available yet', 'The WordPress version has not been reported yet.' );
		}
		if ( true === $update ) {
			$items = array();
			if ( '' !== $version && '' !== $offered ) {
				$items[] = $version . ' to ' . $offered;
			} elseif ( '' !== $offered ) {
				$items[] = 'Version ' . $offered . ' is available';
			}
			return self::card( 'core', 'Core', '' !== $version ? 'WordPress ' . $version : 'WordPress', 'attention', 'Update available', 'A WordPress update is waiting.', $items );
		}

		$summary = false === $update ? 'WordPress is on the current version we have recorded.' : 'A newer version has not been reported.';
		$state   = false === $update ? 'good' : 'unknown';
		$label   = false === $update ? 'Up to date' : 'Version recorded';

		return self::card( 'core', 'Core', '' !== $version ? 'WordPress ' . $version : 'WordPress', $state, $label, $summary );
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function card_updates( array $payload ) {
		$plugins = isset( $payload['plugins'] ) && is_array( $payload['plugins'] ) ? $payload['plugins'] : array();
		$themes  = isset( $payload['themes'] ) && is_array( $payload['themes'] ) ? $payload['themes'] : array();
		$plugin_count = array_key_exists( 'pending_count', $plugins ) ? $plugins['pending_count'] : null;
		$theme_count  = array_key_exists( 'pending_count', $themes ) ? $themes['pending_count'] : null;

		if ( null === $plugin_count && null === $theme_count ) {
			return self::card( 'updates', 'Updates', 'Plugins and themes', 'unknown', 'Not available yet', 'Update information has not been reported yet.' );
		}

		$plugin_count = null === $plugin_count ? 0 : (int) $plugin_count;
		$theme_count  = null === $theme_count ? 0 : (int) $theme_count;
		$total        = $plugin_count + $theme_count;
		$items        = array();
		foreach ( array( $plugins, $themes ) as $group ) {
			if ( empty( $group['pending'] ) || ! is_array( $group['pending'] ) ) {
				continue;
			}
			foreach ( $group['pending'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['name'] ) ) {
					continue;
				}
				$items[] = self::update_line( $row );
			}
		}
		$extra = count( $items ) - 8;
		if ( $extra > 0 ) {
			$items   = array_slice( $items, 0, 8 );
			$items[] = $extra . ' more updates';
		}

		if ( $total > 0 ) {
			$summary = self::count_phrase( $plugin_count, 'plugin update' ) . ' and ' . self::count_phrase( $theme_count, 'theme update' ) . ' waiting.';
			$label   = $total . ' ' . ( 1 === $total ? 'update' : 'updates' ) . ' pending';
			return self::card( 'updates', 'Updates', 'Plugins and themes', 'attention', $label, ucfirst( $summary ), $items );
		}

		return self::card( 'updates', 'Updates', 'Plugins and themes', 'good', 'Up to date', 'No plugin or theme updates are waiting.' );
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function card_security( array $payload ) {
		$security = isset( $payload['security'] ) && is_array( $payload['security'] ) ? $payload['security'] : array();
		if ( ! array_key_exists( 'issues_count', $security ) || null === $security['issues_count'] ) {
			return self::card( 'security', 'Security', 'Security issues', 'unknown', 'Not available yet', 'Security information has not been reported yet.' );
		}

		$count = (int) $security['issues_count'];
		if ( $count > 0 ) {
			return self::card( 'security', 'Security', 'Security issues', 'attention', $count . ' ' . ( 1 === $count ? 'issue' : 'issues' ), $count . ' open security ' . ( 1 === $count ? 'issue' : 'issues' ) . '.' );
		}

		return self::card( 'security', 'Security', 'Security issues', 'good', '0 issues', 'No open security issues were reported.' );
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function card_health( array $payload ) {
		$health = isset( $payload['health'] ) && is_array( $payload['health'] ) ? $payload['health'] : array();
		$status = isset( $health['status'] ) ? (string) $health['status'] : '';
		$php    = isset( $payload['php_version'] ) ? (string) $payload['php_version'] : '';
		$items  = array();
		if ( '' !== $php ) {
			$items[] = 'PHP ' . $php;
		}

		if ( '' === $status && '' === $php ) {
			return self::card( 'health', 'Health', 'Site health', 'unknown', 'Not available yet', 'Health and PHP have not been reported yet.' );
		}
		if ( 'critical' === $status ) {
			return self::card( 'health', 'Health', 'Site health', 'attention', 'Critical', 'MainWP reports site health as critical.', $items );
		}
		if ( 'needs_attention' === $status ) {
			return self::card( 'health', 'Health', 'Site health', 'attention', 'Needs attention', 'A few site health checks need attention.', $items );
		}
		if ( 'should_be_improved' === $status ) {
			return self::card( 'health', 'Health', 'Site health', 'attention', 'Should be improved', 'Site health should be improved.', $items );
		}
		if ( 'good' === $status ) {
			return self::card( 'health', 'Health', 'Site health', 'good', 'Good', 'Site health looks good.', $items );
		}

		return self::card( 'health', 'Health', 'Site health', 'unknown', 'PHP recorded', 'Site health has not been reported yet.', $items );
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function card_backups( array $payload ) {
		$backups = isset( $payload['backups'] ) && is_array( $payload['backups'] ) ? $payload['backups'] : array();
		$last    = isset( $backups['last'] ) && is_array( $backups['last'] ) ? $backups['last'] : array();
		$when    = self::format_when( isset( $last['time'] ) ? $last['time'] : null );
		$next    = self::format_when( isset( $backups['next_scheduled'] ) ? $backups['next_scheduled'] : null );
		$status  = isset( $last['status'] ) ? (string) $last['status'] : '';
		$items   = array();
		if ( '' !== $next ) {
			$items[] = 'Next scheduled backup: ' . $next;
		}
		if ( ! empty( $backups['recent'] ) && is_array( $backups['recent'] ) ) {
			foreach ( $backups['recent'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$line = self::format_when( isset( $row['time'] ) ? $row['time'] : null );
				if ( '' === $line ) {
					continue;
				}
				if ( ! empty( $row['status'] ) ) {
					$line .= ' — ' . self::status_word( (string) $row['status'] );
				}
				$items[] = $line;
			}
		}

		if ( '' === $when && '' === $next && empty( $items ) ) {
			return self::card( 'backups', 'Backups', 'Backups', 'unknown', 'Not available yet', 'Backup history has not been reported yet.' );
		}

		$state   = 'unknown';
		$label   = 'Recorded';
		$summary = '' !== $when ? 'Last backup ' . $when . '.' : 'A backup time has not been reported yet.';
		if ( 'success' === $status ) {
			$state   = 'good';
			$label   = 'Last backup succeeded';
			$summary = '' !== $when ? 'The last backup succeeded on ' . $when . '.' : 'The last backup succeeded.';
		} elseif ( 'failed' === $status ) {
			$state   = 'attention';
			$label   = 'Last backup failed';
			$summary = '' !== $when ? 'The last backup failed on ' . $when . '.' : 'The last backup failed.';
		} elseif ( 'warning' === $status ) {
			$state   = 'attention';
			$label   = 'Last backup needs a look';
			$summary = '' !== $when ? 'The last backup on ' . $when . ' completed with a warning.' : 'The last backup completed with a warning.';
		} elseif ( '' !== $when ) {
			$summary = 'Last backup ' . $when . '. The result was not reported.';
		}

		return self::card( 'backups', 'Backups', 'Backups', $state, $label, $summary, $items );
	}

	/**
	 * Last backup, next backup, and the last five history rows.
	 *
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function backup_panel( array $payload ) {
		$backups  = isset( $payload['backups'] ) && is_array( $payload['backups'] ) ? $payload['backups'] : array();
		$last     = isset( $backups['last'] ) && is_array( $backups['last'] ) ? $backups['last'] : array();
		$status   = isset( $last['status'] ) ? (string) $last['status'] : '';
		$last_date = self::format_when( isset( $last['time'] ) ? $last['time'] : null );
		$next_date = self::format_when( isset( $backups['next_scheduled'] ) ? $backups['next_scheduled'] : null );
		$schedule  = isset( $backups['next_schedule'] ) ? (string) $backups['next_schedule'] : '';
		$mapped    = self::backup_status_view( $status );

		$history = array();
		if ( ! empty( $backups['recent'] ) && is_array( $backups['recent'] ) ) {
			foreach ( array_slice( $backups['recent'], 0, 5 ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row_status = self::backup_status_view( isset( $row['status'] ) ? (string) $row['status'] : '' );
				$history[]  = array(
					'date'        => self::format_when( isset( $row['time'] ) ? $row['time'] : null ),
					'type'        => ! empty( $row['type'] ) ? (string) $row['type'] : 'Not reported',
					'type_known'  => ! empty( $row['type'] ),
					'status'      => $row_status['label'],
					'status_tone' => $row_status['tone'],
					'size'        => ! empty( $row['size'] ) ? (string) $row['size'] : 'Not reported',
					'size_known'  => ! empty( $row['size'] ),
				);
			}
		}

		$detail = 'A backup result has not been reported yet.';
		if ( 'success' === $status && '' !== $last_date ) {
			$detail = 'The last backup completed on ' . $last_date . '.';
		} elseif ( 'failed' === $status && '' !== $last_date ) {
			$detail = 'The last backup failed on ' . $last_date . '.';
		} elseif ( 'warning' === $status && '' !== $last_date ) {
			$detail = 'The last backup on ' . $last_date . ' completed with a warning.';
		} elseif ( '' !== $last_date ) {
			$detail = 'Recorded on ' . $last_date . '. The result was not reported.';
		}

		$next_detail = 'The schedule has not been reported.';
		if ( '' !== $schedule ) {
			$next_detail = $schedule . ' backup.';
		}

		return array(
			'last_date'   => '' !== $last_date ? $last_date : 'Not available yet',
			'last_status' => $mapped['label'],
			'last_tone'   => $mapped['tone'],
			'last_detail' => $detail,
			'next_date'   => '' !== $next_date ? $next_date : 'Not scheduled yet',
			'next_detail' => $next_detail,
			'history'     => $history,
		);
	}

	/**
	 * @param string $status Raw backup status.
	 * @return array{label:string,tone:string}
	 */
	private static function backup_status_view( $status ) {
		switch ( $status ) {
			case 'success':
				return array(
					'label' => 'Complete',
					'tone'  => 'good',
				);
			case 'failed':
				return array(
					'label' => 'Failed',
					'tone'  => 'bad',
				);
			case 'warning':
				return array(
					'label' => 'Warning',
					'tone'  => 'warn',
				);
			case 'running':
				return array(
					'label' => 'Running',
					'tone'  => 'muted',
				);
			default:
				return array(
					'label' => 'Not reported',
					'tone'  => 'muted',
				);
		}
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function card_uptime( array $payload ) {
		$uptime = isset( $payload['uptime'] ) && is_array( $payload['uptime'] ) ? $payload['uptime'] : array();
		if ( empty( $uptime['monitored'] ) ) {
			return self::card( 'uptime', 'Uptime', 'Uptime', 'unknown', 'Not available yet', 'Uptime monitoring has not been reported yet.' );
		}

		$status = isset( $uptime['status'] ) ? (string) $uptime['status'] : '';
		$checked = self::format_when( isset( $uptime['checked_at'] ) ? $uptime['checked_at'] : null );
		$items   = array();
		if ( '' !== $checked ) {
			$items[] = 'Last check ' . $checked;
		}
		if ( 'up' === $status ) {
			return self::card( 'uptime', 'Uptime', 'Uptime', 'good', 'Online', 'The website was online at the last check.', $items );
		}
		if ( 'down' === $status ) {
			return self::card( 'uptime', 'Uptime', 'Uptime', 'attention', 'Offline', 'The website was offline at the last check.', $items );
		}

		return self::card( 'uptime', 'Uptime', 'Uptime', 'unknown', 'Not available yet', 'A monitor exists, but the latest result was not reported.', $items );
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function section_login( array $payload ) {
		$login = isset( $payload['last_admin_login'] ) && is_array( $payload['last_admin_login'] ) ? $payload['last_admin_login'] : array();
		$when  = self::format_when( isset( $login['time'] ) ? $login['time'] : null );
		$user  = isset( $login['username'] ) ? trim( (string) $login['username'] ) : '';

		return array(
			'title'     => 'Last admin login',
			'available' => '' !== $when || '' !== $user,
			'username'  => $user,
			'when'      => $when,
			'empty'     => 'Not available yet',
		);
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function section_content( array $payload ) {
		$content  = isset( $payload['content'] ) && is_array( $payload['content'] ) ? $payload['content'] : array();
		$products = isset( $content['products'] ) && is_array( $content['products'] ) ? $content['products'] : array();
		$posts    = isset( $content['posts'] ) && is_array( $content['posts'] ) ? $content['posts'] : array();
		$pages    = isset( $content['pages'] ) && is_array( $content['pages'] ) ? $content['pages'] : array();

		return array(
			'title'  => 'Catalogue',
			'groups' => array(
				array(
					'title' => 'Products',
					'rows'  => array(
						self::count_row( 'Published', isset( $products['published'] ) ? $products['published'] : null ),
						self::count_row( 'Draft', isset( $products['draft'] ) ? $products['draft'] : null ),
						self::count_row( 'Out of stock', isset( $products['out_of_stock'] ) ? $products['out_of_stock'] : null ),
					),
				),
				array(
					'title' => 'Posts',
					'rows'  => array(
						self::count_row( 'Published', isset( $posts['published'] ) ? $posts['published'] : null ),
						self::date_row( 'Last published', isset( $posts['last_published_at'] ) ? $posts['last_published_at'] : null ),
					),
				),
				array(
					'title' => 'Pages',
					'rows'  => array(
						self::count_row( 'Published', isset( $pages['published'] ) ? $pages['published'] : null ),
						self::date_row( 'Last published', isset( $pages['last_published_at'] ) ? $pages['last_published_at'] : null ),
					),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $payload Snapshot.
	 * @return array<string,mixed>
	 */
	private static function section_activity( array $payload ) {
		$activity = isset( $payload['activity'] ) && is_array( $payload['activity'] ) ? $payload['activity'] : array();
		$last     = isset( $activity['last_run'] ) && is_array( $activity['last_run'] ) ? $activity['last_run'] : array();
		$recent   = array();
		if ( ! empty( $activity['recent'] ) && is_array( $activity['recent'] ) ) {
			foreach ( $activity['recent'] as $run ) {
				if ( ! is_array( $run ) ) {
					continue;
				}
				$recent[] = array(
					'when' => self::format_when( isset( $run['date'] ) ? $run['date'] : null ),
					'done' => self::done_label( $run ),
				);
			}
		}

		$last_when = self::format_when( isset( $last['date'] ) ? $last['date'] : null );
		$next_when = self::format_when( isset( $activity['next_scheduled'] ) ? $activity['next_scheduled'] : null );

		return array(
			'title' => 'Maintenance activity',
			'last'  => array(
				'available' => '' !== $last_when,
				'when'      => $last_when,
				'done'      => self::done_list( $last ),
				'detail'    => '' !== $last_when && empty( self::done_list( $last ) ) ? 'Details were not recorded.' : '',
				'empty'     => 'Not available yet',
			),
			'next'  => array(
				'available' => '' !== $next_when,
				'when'      => $next_when,
				'empty'     => 'Not scheduled yet',
			),
			'recent'       => $recent,
			'recent_empty' => 'No recent runs yet',
		);
	}

	/**
	 * @param string $id Card id.
	 * @param string $kicker Kicker.
	 * @param string $title Title.
	 * @param string $state good, attention, or unknown.
	 * @param string $state_label Short status.
	 * @param string $summary Sentence.
	 * @param array  $items Lines.
	 * @return array<string,mixed>
	 */
	private static function card( $id, $kicker, $title, $state, $state_label, $summary, array $items = array() ) {
		return array(
			'id'          => $id,
			'kicker'      => $kicker,
			'title'       => $title,
			'state'       => $state,
			'state_label' => $state_label,
			'summary'     => $summary,
			'items'       => array_values( $items ),
		);
	}

	/**
	 * @param array<string,mixed> $row Update row.
	 * @return string
	 */
	private static function update_line( array $row ) {
		$line = (string) $row['name'];
		$from = isset( $row['current_version'] ) ? (string) $row['current_version'] : '';
		$to   = isset( $row['new_version'] ) ? (string) $row['new_version'] : '';
		if ( '' !== $from && '' !== $to ) {
			$line .= ' ' . $from . ' to ' . $to;
		} elseif ( '' !== $to ) {
			$line .= ' to ' . $to;
		} elseif ( '' !== $from ) {
			$line .= ' ' . $from;
		}

		return $line;
	}

	/**
	 * @param int    $count Count.
	 * @param string $noun Singular noun.
	 * @return string
	 */
	private static function count_phrase( $count, $noun ) {
		$count = (int) $count;
		return $count . ' ' . $noun . ( 1 === $count ? '' : 's' );
	}

	/**
	 * @param string $label Row label.
	 * @param mixed  $value Count.
	 * @return array{label:string,value:string,known:bool}
	 */
	private static function count_row( $label, $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return array(
				'label' => $label,
				'value' => 'Not available yet',
				'known' => false,
			);
		}

		return array(
			'label' => $label,
			'value' => (string) (int) $value,
			'known' => true,
		);
	}

	/**
	 * @param string $label Row label.
	 * @param mixed  $value Date.
	 * @return array{label:string,value:string,known:bool}
	 */
	private static function date_row( $label, $value ) {
		$when = self::format_when( $value );
		if ( '' === $when ) {
			return array(
				'label' => $label,
				'value' => 'Not available yet',
				'known' => false,
			);
		}

		return array(
			'label' => $label,
			'value' => $when,
			'known' => true,
		);
	}

	/**
	 * @param array<string,mixed> $run Run.
	 * @return array<int,string>
	 */
	private static function done_list( array $run ) {
		$map = array(
			'updates_applied' => 'Updates applied',
			'backup'          => 'Backup',
			'security_scan'   => 'Security scan',
			'optimisation'    => 'Optimisation',
		);
		$done = array();
		foreach ( $map as $key => $label ) {
			if ( ! empty( $run[ $key ] ) ) {
				$done[] = $label;
			}
		}

		return $done;
	}

	/**
	 * @param array<string,mixed> $run Run.
	 * @return string
	 */
	private static function done_label( array $run ) {
		$done = self::done_list( $run );
		if ( empty( $done ) ) {
			return 'Details were not recorded';
		}

		return implode( ', ', $done );
	}

	/**
	 * @param string $status Raw status.
	 * @return string
	 */
	private static function status_word( $status ) {
		switch ( $status ) {
			case 'success':
				return 'succeeded';
			case 'failed':
				return 'failed';
			case 'warning':
				return 'completed with a warning';
			case 'running':
				return 'still running';
			default:
				return $status;
		}
	}

	/**
	 * @param mixed $value ISO time.
	 * @return string
	 */
	public static function format_day( $value, $timezone = null ) {
		$moment = self::local_moment( $value, $timezone );
		if ( ! $moment ) {
			return '';
		}

		return $moment->format( 'j M Y' );
	}

	public static function format_when( $value, $timezone = null ) {
		$moment = self::local_moment( $value, $timezone );
		if ( ! $moment ) {
			return '';
		}

		return $moment->format( 'j M Y, H:i' ) . ' ' . self::zone_label( $moment );
	}

	/**
	 * @param mixed $value ISO time.
	 * @param mixed $timezone Optional zone override.
	 * @return DateTimeImmutable|null
	 */
	private static function local_moment( $value, $timezone = null ) {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d+(?:\.\d+)?$/', trim( $value ) ) ) ) {
			$unix = self::sane_unix( $value );
			if ( ! $unix ) {
				return null;
			}
			$moment = new DateTimeImmutable( '@' . $unix );
			return $moment->setTimezone( self::resolve_timezone( null !== $timezone ? $timezone : self::$display_timezone ) );
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		try {
			$moment = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			$time = strtotime( $value );
			if ( ! $time ) {
				return null;
			}
			$moment = new DateTimeImmutable( '@' . $time );
		}

		$year = (int) $moment->format( 'Y' );
		if ( $year > 2100 || $year < 2000 ) {
			$unix = self::sane_unix( $moment->getTimestamp() );
			if ( ! $unix ) {
				return null;
			}
			$moment = new DateTimeImmutable( '@' . $unix );
		}

		return $moment->setTimezone( self::resolve_timezone( null !== $timezone ? $timezone : self::$display_timezone ) );
	}

	/**
	 * Seconds, milliseconds, microseconds, or a MainWP time().rand index.
	 * Values that still fall outside 2000–2100 are dropped.
	 *
	 * @param mixed $value Numeric time.
	 * @return int|null
	 */
	public static function sane_unix( $value ) {
		if ( is_string( $value ) && preg_match( '/^\d+\.\d+$/', trim( $value ) ) ) {
			$value = (float) $value;
		}
		$time = (int) $value;
		if ( $time <= 0 ) {
			return null;
		}
		$digits = strlen( (string) $time );
		if ( $digits >= 13 && $digits <= 15 ) {
			$stripped = (int) floor( $time / 10000 );
			if ( $stripped >= 946684800 && $stripped <= 4102444800 ) {
				return $stripped;
			}
		}
		$guard = 0;
		while ( $time > 4102444800 && $guard < 4 ) {
			$time = (int) round( $time / 1000 );
			$guard++;
		}
		if ( $time < 946684800 || $time > 4102444800 ) {
			return null;
		}

		return $time;
	}

	/**
	 * @param array<string,mixed> $view Presented maintenance view.
	 * @return bool
	 */
	public static function needs_attention( array $view ) {
		if ( ! empty( $view['flags'] ) && is_array( $view['flags'] ) ) {
			return true;
		}
		if ( empty( $view['cards'] ) || ! is_array( $view['cards'] ) ) {
			return false;
		}
		foreach ( $view['cards'] as $card ) {
			if ( is_array( $card ) && isset( $card['state'] ) && 'attention' === $card['state'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Site-reported zone first, then the WordPress zone for this account.
	 *
	 * @param mixed $reported Timezone identifier from the synced site.
	 * @return DateTimeZone
	 */
	public static function resolve_timezone( $reported ) {
		if ( is_string( $reported ) && '' !== $reported ) {
			try {
				return new DateTimeZone( $reported );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}
		if ( function_exists( 'wp_timezone' ) ) {
			$zone = wp_timezone();
			if ( $zone instanceof DateTimeZone ) {
				return $zone;
			}
		}

		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Short customer-facing zone name. UTC is shown as GMT.
	 *
	 * @param DateTimeInterface $moment Local moment.
	 * @return string
	 */
	public static function zone_label( DateTimeInterface $moment ) {
		$abbr = $moment->format( 'T' );
		if ( in_array( $abbr, array( 'UTC', 'GMT', 'Z' ), true ) ) {
			return 'GMT';
		}
		if ( preg_match( '/^[+-]\d+$/', $abbr ) ) {
			$offset = $moment->getOffset();
			$sign   = $offset >= 0 ? '+' : '-';
			$abs    = abs( $offset );
			$hours  = (int) ( $abs / 3600 );
			$mins   = (int) ( ( $abs % 3600 ) / 60 );
			if ( $mins > 0 ) {
				return sprintf( 'GMT%s%d:%02d', $sign, $hours, $mins );
			}

			return 'GMT' . $sign . $hours;
		}

		return $abbr;
	}
}
