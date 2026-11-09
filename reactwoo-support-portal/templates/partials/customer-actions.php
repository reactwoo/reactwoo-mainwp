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

	<div class="rw-maint-action-categories">
		<section class="rw-maint-action-cat rw-maint-action-cat--protect" aria-labelledby="rw-maint-cat-protect">
			<h3 class="rw-maint-action-cat__label" id="rw-maint-cat-protect">Protect</h3>
			<div class="rw-maint-action-cat__cards">
				<article class="rw-maint-panel rw-maint-action-card rw-maint-action-card--primary">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'backup' ); ?>
						<h4>Request backup</h4>
					</div>
					<p class="rw-maint-panel-copy">Queue a full site backup.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'request_backup', 'Request backup', 'I confirm I want a backup of this site.', 'rw-maint-button' ); ?>
				</article>
				<article class="rw-maint-panel rw-maint-action-card rw-maint-action-card--primary">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'security' ); ?>
						<h4>Security scan</h4>
					</div>
					<p class="rw-maint-panel-copy">Start a security scan for this website.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'security_scan', 'Security scan', 'I confirm I want a security scan on this site.', 'rw-maint-button' ); ?>
				</article>
			</div>
		</section>

		<section class="rw-maint-action-cat rw-maint-action-cat--maintain" aria-labelledby="rw-maint-cat-maintain">
			<h3 class="rw-maint-action-cat__label" id="rw-maint-cat-maintain">Maintain</h3>
			<div class="rw-maint-action-cat__cards">
				<article class="rw-maint-panel rw-maint-action-card rw-maint-action-card--primary" id="rw-maint-run-updates">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'updates' ); ?>
						<h4>Run pending updates</h4>
					</div>
					<p class="rw-maint-panel-copy">Approve and run WordPress, plugin and theme updates that are waiting.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'run_updates', 'Run pending updates', 'I understand updates will be applied to this site now.', 'rw-maint-button' ); ?>
				</article>
				<article class="rw-maint-panel rw-maint-action-card">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'health' ); ?>
						<h4>Health check</h4>
					</div>
					<p class="rw-maint-panel-copy">Run a live health check now.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'check_site', 'Health check', 'I confirm I want a health check on this site.' ); ?>
				</article>
				<article class="rw-maint-panel rw-maint-action-card">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'sync' ); ?>
						<h4>Sync now</h4>
					</div>
					<p class="rw-maint-panel-copy">Pull the latest site snapshot.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'sync_site', 'Sync now', 'I confirm I want to sync this site.' ); ?>
				</article>
			</div>
		</section>

		<section class="rw-maint-action-cat rw-maint-action-cat--reports" aria-labelledby="rw-maint-cat-reports">
			<h3 class="rw-maint-action-cat__label" id="rw-maint-cat-reports">Reports</h3>
			<div class="rw-maint-action-cat__cards">
				<article class="rw-maint-panel rw-maint-action-card">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'reports' ); ?>
						<h4>Download report</h4>
					</div>
					<p class="rw-maint-panel-copy">PDF maintenance report.</p>
					<a class="rw-maint-button-secondary" href="<?php echo esc_url( RW_Portal_Customer_Actions::report_download_url( $site ) ); ?>">Download report</a>
				</article>
			</div>
		</section>

		<section class="rw-maint-action-cat rw-maint-action-cat--pause" aria-labelledby="rw-maint-cat-pause">
			<h3 class="rw-maint-action-cat__label" id="rw-maint-cat-pause">Pause</h3>
			<div class="rw-maint-action-cat__cards">
				<?php if ( $paused ) : ?>
				<article class="rw-maint-panel rw-maint-action-card rw-maint-action-card--primary">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'resume' ); ?>
						<h4>Resume maintenance</h4>
					</div>
					<p class="rw-maint-panel-copy">Resume monitoring &amp; jobs.</p>
					<?php
					RW_Portal_Customer_Actions::render_action_form(
						$site,
						'resume_maintenance',
						'Resume maintenance',
						'I confirm I want to resume maintenance for this site.',
						'rw-maint-button'
					);
					?>
				</article>
				<?php else : ?>
				<article class="rw-maint-panel rw-maint-action-card">
					<div class="rw-maint-action-card__head">
						<?php RW_Portal_Customer_Actions::render_icon( 'pause' ); ?>
						<h4>Pause maintenance</h4>
					</div>
					<p class="rw-maint-panel-copy">Temporarily pause monitoring.</p>
					<?php
					RW_Portal_Customer_Actions::render_action_form(
						$site,
						'pause_maintenance',
						'Pause maintenance',
						'I understand maintenance will pause for this site until I resume it.'
					);
					?>
				</article>
				<?php endif; ?>
			</div>
		</section>
	</div>
</section>
