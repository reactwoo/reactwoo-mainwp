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
	<p class="rw-maint-panel-copy">Request a check, sync, snapshot or update for this website. Each action needs confirmation and is rate-limited.</p>

	<div class="rw-maint-action-categories">
		<section class="rw-maint-action-cat rw-maint-action-cat--protect" aria-labelledby="rw-maint-cat-protect">
			<h3 class="rw-maint-action-cat__label" id="rw-maint-cat-protect">Protect</h3>
			<div class="rw-maint-action-cat__cards">
				<article class="rw-maint-panel rw-maint-action-card rw-maint-action-card--primary">
					<div class="rw-maint-action-card__head">
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
						</span>
						<h4>Request backup</h4>
					</div>
					<p class="rw-maint-panel-copy">Save a snapshot of the plugins, themes and database on this website.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'request_backup', 'Request backup', 'I confirm I want a snapshot of this website\'s plugins, themes and database.', 'rw-maint-button' ); ?>
				</article>
				<article class="rw-maint-panel rw-maint-action-card rw-maint-action-card--primary">
					<div class="rw-maint-action-card__head">
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
						</span>
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
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
						</span>
						<h4>Run pending updates</h4>
					</div>
					<p class="rw-maint-panel-copy">Approve and run WordPress, plugin and theme updates that are waiting.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'run_updates', 'Run pending updates', 'I understand updates will be applied to this site now.', 'rw-maint-button' ); ?>
				</article>
				<article class="rw-maint-panel rw-maint-action-card">
					<div class="rw-maint-action-card__head">
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>
						</span>
						<h4>Health check</h4>
					</div>
					<p class="rw-maint-panel-copy">Run a live health check now.</p>
					<?php RW_Portal_Customer_Actions::render_action_form( $site, 'check_site', 'Health check', 'I confirm I want a health check on this site.' ); ?>
				</article>
				<article class="rw-maint-panel rw-maint-action-card">
					<div class="rw-maint-action-card__head">
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
						</span>
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
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
						</span>
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
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
						</span>
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
						<span class="rw-maint-action-card__icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="10" x2="10" y1="15" y2="9"/><line x1="14" x2="14" y1="15" y2="9"/></svg>
						</span>
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
