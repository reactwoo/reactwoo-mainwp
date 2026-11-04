<?php
/**
 * Live maintenance status.
 *
 * Expects $maintenance from RW_Portal_Maintenance_Status::present().
 * Attention areas use the ink panel. Other panels stay on white.
 *
 * @var array $maintenance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $maintenance ) || ! is_array( $maintenance ) ) {
	return;
}

$by_id = array();
foreach ( isset( $maintenance['cards'] ) ? $maintenance['cards'] : array() as $card ) {
	if ( ! empty( $card['id'] ) ) {
		$by_id[ $card['id'] ] = $card;
	}
}
$core      = isset( $by_id['core'] ) ? $by_id['core'] : array();
$updates   = isset( $by_id['updates'] ) ? $by_id['updates'] : array();
$security  = isset( $by_id['security'] ) ? $by_id['security'] : array();
$health    = isset( $by_id['health'] ) ? $by_id['health'] : array();
$backups   = isset( $by_id['backups'] ) ? $by_id['backups'] : array();
$uptime    = isset( $by_id['uptime'] ) ? $by_id['uptime'] : array();
$login     = isset( $maintenance['login'] ) && is_array( $maintenance['login'] ) ? $maintenance['login'] : array();
$content   = isset( $maintenance['content'] ) && is_array( $maintenance['content'] ) ? $maintenance['content'] : array();
$activity  = isset( $maintenance['activity'] ) && is_array( $maintenance['activity'] ) ? $maintenance['activity'] : array();
$premium   = isset( $maintenance['premium'] ) && is_array( $maintenance['premium'] ) ? $maintenance['premium'] : array();
$inventory = isset( $maintenance['inventory'] ) && is_array( $maintenance['inventory'] ) ? $maintenance['inventory'] : array();
$last      = isset( $activity['last'] ) && is_array( $activity['last'] ) ? $activity['last'] : array();
$next      = isset( $activity['next'] ) && is_array( $activity['next'] ) ? $activity['next'] : array();
?>
<section class="rw-maint-live" aria-labelledby="rw-maint-live-status">
	<div class="rw-maint-live-intro">
		<h2 id="rw-maint-live-status">Site status</h2>
		<p>Live checks from your connected website.</p>
	</div>

	<div class="rw-maint-row rw-maint-row-3">
		<article class="rw-maint-panel rw-maint-panel--<?php echo esc_attr( isset( $core['tone'] ) ? $core['tone'] : 'paper' ); ?>" data-status-card="core">
			<p class="rw-maint-kicker">WordPress</p>
			<?php
			$version_label = isset( $core['title'] ) ? trim( (string) preg_replace( '/^WordPress\s*/', '', (string) $core['title'] ) ) : '';
			if ( '' === $version_label ) {
				$version_label = 'Not available yet';
			}
			?>
			<h3><?php echo esc_html( $version_label ); ?></h3>
			<?php if ( ! empty( $core['state_label'] ) ) : ?>
				<p class="rw-maint-pill rw-maint-pill--<?php echo esc_attr( isset( $core['state'] ) ? $core['state'] : 'unknown' ); ?>"><?php echo esc_html( $core['state_label'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $core['summary'] ) ) : ?>
				<p class="rw-maint-panel-copy"><?php echo esc_html( $core['summary'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $core['items'] ) ) : ?>
				<p class="rw-maint-panel-copy"><?php echo esc_html( $core['items'][0] ); ?></p>
			<?php endif; ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--paper" data-status-card="inventory">
			<p class="rw-maint-kicker">Plugins and themes</p>
			<h3><?php echo esc_html( isset( $inventory['label'] ) ? $inventory['label'] : 'Not available yet' ); ?></h3>
			<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $inventory['detail'] ) ? $inventory['detail'] : 'Installed plugins and themes have not been reported yet.' ); ?></p>
		</article>

		<article class="rw-maint-panel rw-maint-panel--<?php echo esc_attr( isset( $security['tone'] ) ? $security['tone'] : 'paper' ); ?>" data-status-card="security">
			<p class="rw-maint-kicker">Security</p>
			<h3><?php echo esc_html( isset( $security['state_label'] ) ? $security['state_label'] : 'Not available yet' ); ?></h3>
			<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $security['summary'] ) ? $security['summary'] : '' ); ?></p>
		</article>
	</div>

	<div class="rw-maint-row rw-maint-row-3">
		<article class="rw-maint-panel rw-maint-panel--<?php echo esc_attr( isset( $updates['tone'] ) ? $updates['tone'] : 'ink' ); ?>" data-status-card="updates">
			<p class="rw-maint-kicker">Updates</p>
			<h3><?php echo esc_html( isset( $updates['state_label'] ) ? $updates['state_label'] : 'Updates' ); ?></h3>
			<?php if ( ! empty( $updates['summary'] ) ) : ?>
				<p class="rw-maint-panel-copy"><?php echo esc_html( $updates['summary'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $updates['items'] ) ) : ?>
				<ul class="rw-maint-lines">
					<?php foreach ( $updates['items'] as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--<?php echo esc_attr( isset( $health['tone'] ) ? $health['tone'] : 'ink' ); ?>" data-status-card="health">
			<p class="rw-maint-kicker">Site health</p>
			<h3><?php echo esc_html( isset( $health['state_label'] ) ? $health['state_label'] : 'Not available yet' ); ?></h3>
			<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $health['summary'] ) ? $health['summary'] : '' ); ?></p>
			<?php if ( ! empty( $health['items'] ) ) : ?>
				<ul class="rw-maint-lines">
					<?php foreach ( $health['items'] as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--ink" data-status-card="login">
			<p class="rw-maint-kicker">Last admin login</p>
			<?php if ( ! empty( $login['available'] ) ) : ?>
				<h3><?php echo esc_html( $login['when'] ? $login['when'] : 'Time not reported' ); ?></h3>
				<p class="rw-maint-panel-copy"><?php echo esc_html( $login['username'] ? $login['username'] : 'An administrator' ); ?></p>
			<?php else : ?>
				<h3>Not available yet</h3>
				<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $login['empty'] ) ? $login['empty'] : 'Not available yet' ); ?></p>
			<?php endif; ?>
		</article>
	</div>

	<article class="rw-maint-panel rw-maint-panel--ink rw-maint-premium" data-status-card="premium">
		<div class="rw-maint-panel-head">
			<h3><?php echo esc_html( isset( $premium['title'] ) ? $premium['title'] : 'Premium plugins' ); ?></h3>
		</div>
		<?php if ( empty( $premium['items'] ) ) : ?>
			<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $premium['empty'] ) ? $premium['empty'] : 'Premium plugin information has not been reported yet.' ); ?></p>
		<?php else : ?>
			<ul class="rw-maint-premium-list">
				<?php foreach ( $premium['items'] as $item ) : ?>
					<li>
						<div>
							<strong><?php echo esc_html( $item['name'] ); ?></strong>
							<span><?php echo esc_html( $item['version'] ); ?><?php echo '' !== $item['source'] ? ' · ' . esc_html( $item['source'] ) : ''; ?></span>
						</div>
						<div class="rw-maint-pills">
							<span class="rw-maint-pill rw-maint-pill--<?php echo esc_attr( $item['update_tone'] ); ?>"><?php echo esc_html( $item['update'] ); ?></span>
							<span class="rw-maint-pill rw-maint-pill--<?php echo esc_attr( $item['licence_tone'] ); ?>"><?php echo esc_html( $item['licence'] ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</article>

	<div class="rw-maint-row rw-maint-row-2">
		<article class="rw-maint-panel rw-maint-panel--paper" data-status-card="uptime">
			<p class="rw-maint-kicker">Uptime</p>
			<h3><?php echo esc_html( isset( $uptime['state_label'] ) ? $uptime['state_label'] : 'Not available yet' ); ?></h3>
			<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $uptime['summary'] ) ? $uptime['summary'] : '' ); ?></p>
			<?php if ( ! empty( $uptime['items'] ) ) : ?>
				<ul class="rw-maint-lines">
					<?php foreach ( $uptime['items'] as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>
		<article class="rw-maint-panel rw-maint-panel--paper" data-status-card="last-run">
			<p class="rw-maint-kicker">Last maintenance run</p>
			<?php if ( ! empty( $last['available'] ) ) : ?>
				<h3><?php echo esc_html( $last['when'] ); ?></h3>
				<?php if ( ! empty( $last['done'] ) ) : ?>
					<ul class="rw-maint-lines">
						<?php foreach ( $last['done'] as $done ) : ?>
							<li><?php echo esc_html( $done ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( ! empty( $last['detail'] ) ) : ?>
					<p class="rw-maint-panel-copy"><?php echo esc_html( $last['detail'] ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<h3>Not available yet</h3>
				<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $last['empty'] ) ? $last['empty'] : 'Not available yet' ); ?></p>
			<?php endif; ?>
		</article>
	</div>

	<div class="rw-maint-row rw-maint-row-3">
		<?php foreach ( isset( $content['groups'] ) ? $content['groups'] : array() as $group ) : ?>
			<article class="rw-maint-panel rw-maint-panel--paper" data-status-card="<?php echo esc_attr( strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', isset( $group['title'] ) ? $group['title'] : 'group' ), '-' ) ) ); ?>">
				<h3><?php echo esc_html( isset( $group['title'] ) ? $group['title'] : '' ); ?></h3>
				<dl class="rw-maint-count-list">
					<?php foreach ( isset( $group['rows'] ) ? $group['rows'] : array() as $row ) : ?>
						<div>
							<dt><?php echo esc_html( $row['label'] ); ?></dt>
							<dd><?php echo esc_html( $row['value'] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</article>
		<?php endforeach; ?>
	</div>

	<section class="rw-maint-backup" aria-labelledby="rw-maint-backup-title">
		<h2 id="rw-maint-backup-title">Your site is backed up</h2>
		<div class="rw-maint-row rw-maint-row-2">
			<article class="rw-maint-panel rw-maint-panel--ink" data-status-card="backups">
				<p class="rw-maint-kicker">Last backup</p>
				<h3><?php echo esc_html( isset( $backups['state_label'] ) ? $backups['state_label'] : 'Not available yet' ); ?></h3>
				<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $backups['summary'] ) ? $backups['summary'] : '' ); ?></p>
			</article>
			<article class="rw-maint-panel rw-maint-panel--ink" data-status-card="backup-next">
				<p class="rw-maint-kicker">Next backup</p>
				<?php
				$next_backup = '';
				if ( ! empty( $backups['items'] ) ) {
					foreach ( $backups['items'] as $item ) {
						if ( 0 === strpos( (string) $item, 'Next scheduled backup:' ) ) {
							$next_backup = trim( substr( (string) $item, strlen( 'Next scheduled backup:' ) ) );
							break;
						}
					}
				}
				?>
				<h3><?php echo esc_html( '' !== $next_backup ? $next_backup : 'Not scheduled yet' ); ?></h3>
				<p class="rw-maint-panel-copy"><?php echo '' !== $next_backup ? 'The next backup is already scheduled.' : 'A future backup has not been reported yet.'; ?></p>
			</article>
		</div>
		<?php if ( ! empty( $backups['items'] ) ) : ?>
			<h3 class="rw-maint-subhead">Backup history</h3>
			<ul class="rw-maint-history">
				<?php foreach ( $backups['items'] as $item ) : ?>
					<?php if ( 0 === strpos( (string) $item, 'Next scheduled backup:' ) ) { continue; } ?>
					<li><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="rw-maint-activity" aria-labelledby="rw-maint-activity-title">
		<h2 id="rw-maint-activity-title">Maintenance activity</h2>
		<div class="rw-maint-row rw-maint-row-2">
			<article class="rw-maint-panel rw-maint-panel--paper">
				<p class="rw-maint-kicker">Latest run</p>
				<?php if ( ! empty( $last['available'] ) ) : ?>
					<h3><?php echo esc_html( $last['when'] ); ?></h3>
					<?php if ( ! empty( $last['done'] ) ) : ?>
						<p class="rw-maint-panel-copy"><?php echo esc_html( implode( ', ', $last['done'] ) ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<h3>Not available yet</h3>
				<?php endif; ?>
			</article>
			<article class="rw-maint-panel rw-maint-panel--paper">
				<p class="rw-maint-kicker">Next scheduled run</p>
				<?php if ( ! empty( $next['available'] ) ) : ?>
					<h3><?php echo esc_html( $next['when'] ); ?></h3>
				<?php else : ?>
					<h3><?php echo esc_html( isset( $next['empty'] ) ? $next['empty'] : 'Not scheduled yet' ); ?></h3>
				<?php endif; ?>
			</article>
		</div>
		<?php if ( ! empty( $activity['recent'] ) ) : ?>
			<h3 class="rw-maint-subhead">Recent runs</h3>
			<ol class="rw-maint-runs">
				<?php foreach ( $activity['recent'] as $run ) : ?>
					<li>
						<span><?php echo esc_html( isset( $run['when'] ) ? $run['when'] : '' ); ?></span>
						<span><?php echo esc_html( isset( $run['done'] ) ? $run['done'] : '' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<p class="rw-maint-panel-copy"><?php echo esc_html( isset( $activity['recent_empty'] ) ? $activity['recent_empty'] : 'No recent runs yet' ); ?></p>
		<?php endif; ?>
	</section>
</section>
