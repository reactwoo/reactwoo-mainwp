<?php
/**
 * Customer maintenance actions (v1 + v2) on the connected dashboard.
 *
 * @var array  $rw_view
 * @var object $site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $site ) || ! is_object( $site ) ) {
	return;
}

$status = isset( $site->status ) ? (string) $site->status : '';
$paused = ( 'suspended' === $status );
?>
<section class="rw-maint-actions-band" id="rw-maint-actions" aria-labelledby="rw-maint-actions-title">
	<h2 id="rw-maint-actions-title">Site actions</h2>
	<p class="rw-maint-panel-copy">Request a check, sync, backup or update for this website. Each action needs confirmation and is rate-limited.</p>

	<div class="rw-maint-row rw-maint-row-3 rw-maint-action-cards">
		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-kicker">Health</p>
			<h3>Health check</h3>
			<p class="rw-maint-panel-copy">Ask the maintenance hub to re-check this website now.</p>
			<?php RW_Portal_Customer_Actions::render_action_form( $site, 'check_site', 'Run health check', 'I confirm I want a health check on this site.' ); ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-kicker">Sync</p>
			<h3>Sync site data</h3>
			<p class="rw-maint-panel-copy">Refresh plugins, themes and status from WordPress.</p>
			<?php RW_Portal_Customer_Actions::render_action_form( $site, 'sync_site', 'Sync now', 'I confirm I want to sync this site.' ); ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--ink">
			<p class="rw-maint-kicker">Backup</p>
			<h3>Request backup</h3>
			<p class="rw-maint-panel-copy">Queue a fresh backup for this website.</p>
			<?php RW_Portal_Customer_Actions::render_action_form( $site, 'request_backup', 'Request backup now', 'I confirm I want a backup of this site.', 'rw-maint-button' ); ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-kicker">Updates</p>
			<h3>Run pending updates</h3>
			<p class="rw-maint-panel-copy">Approve and run WordPress, plugin and theme updates that are waiting.</p>
			<?php RW_Portal_Customer_Actions::render_action_form( $site, 'run_updates', 'Approve and run updates', 'I understand updates will be applied to this site now.' ); ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-kicker">Security</p>
			<h3>Security scan</h3>
			<p class="rw-maint-panel-copy">Request a security scan for this website.</p>
			<?php RW_Portal_Customer_Actions::render_action_form( $site, 'security_scan', 'Request security scan', 'I confirm I want a security scan on this site.' ); ?>
		</article>

		<article class="rw-maint-panel rw-maint-panel--ink">
			<p class="rw-maint-kicker">Report</p>
			<h3>Download report</h3>
			<p class="rw-maint-panel-copy">Download a PDF summary of the latest maintenance status.</p>
			<a class="rw-maint-button" href="<?php echo esc_url( RW_Portal_Customer_Actions::report_download_url( $site ) ); ?>">Download report PDF</a>
		</article>

		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-kicker">Maintenance</p>
			<h3><?php echo $paused ? 'Resume maintenance' : 'Pause maintenance'; ?></h3>
			<p class="rw-maint-panel-copy">
				<?php
				echo $paused
					? 'Maintenance is paused. Resume automated updates and checks.'
					: 'Temporarily pause automated maintenance for this website.';
				?>
			</p>
			<?php
			if ( $paused ) {
				RW_Portal_Customer_Actions::render_action_form(
					$site,
					'resume_maintenance',
					'Resume maintenance',
					'I confirm I want to resume maintenance for this site.',
					'rw-maint-button'
				);
			} else {
				RW_Portal_Customer_Actions::render_action_form(
					$site,
					'pause_maintenance',
					'Pause maintenance',
					'I understand maintenance will pause for this site until I resume it.'
				);
			}
			?>
		</article>
	</div>
</section>
