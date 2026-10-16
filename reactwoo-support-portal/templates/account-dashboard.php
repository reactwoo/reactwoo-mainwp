<?php
/**
 * Connected maintenance dashboard.
 *
 * @var array $rw_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site = $rw_view['site'];
$host = $site ? wp_parse_url( (string) $site->site_url, PHP_URL_HOST ) : '';
$host = $host ? $host : 'your website';
?>
<article class="rw-maint-plan-block">
	<section class="rw-maint-hero" aria-labelledby="rw-maint-dash-title">
		<div>
			<p class="rw-maint-pill"><?php echo esc_html( $rw_view['status_label'] ); ?></p>
			<h2 id="rw-maint-dash-title">Your website is protected</h2>
			<p class="rw-maint-lead"><?php echo esc_html( $host ); ?> is connected to ReactWoo Maintenance.</p>
			<div class="rw-maint-actions">
				<a class="rw-maint-button" href="#rw-maint-reports">View report</a>
				<?php if ( ! empty( $rw_view['support_mailto'] ) ) : ?>
					<a class="rw-maint-button-secondary" href="<?php echo esc_url( $rw_view['support_mailto'] ); ?>">Request support</a>
				<?php endif; ?>
			</div>
		</div>
		<aside class="rw-maint-plan" aria-label="Plan and support">
			<p class="rw-maint-kicker">Your plan</p>
			<h3><?php echo esc_html( $rw_view['plan_name'] ); ?></h3>
			<p><?php echo esc_html( (string) (int) $rw_view['minutes'] ); ?> minutes of technical support this billing period.</p>
			<?php if ( ! empty( $rw_view['last_seen'] ) ) : ?>
				<p>Last update from your website: <?php echo esc_html( $rw_view['last_seen'] ); ?></p>
			<?php endif; ?>
		</aside>
	</section>

	<section class="rw-maint-band" aria-labelledby="rw-maint-status-title">
		<h2 id="rw-maint-status-title">Overall status</h2>
		<dl class="rw-maint-facts">
			<div><dt>Website</dt><dd><?php echo esc_html( $host ); ?></dd></div>
			<div><dt>Site status</dt><dd><?php echo esc_html( $rw_view['status_label'] ); ?></dd></div>
			<div><dt>Maintenance</dt><dd>Active</dd></div>
			<div><dt>Backups</dt><dd><?php echo esc_html( $rw_view['backup_label'] ); ?></dd></div>
			<div><dt>Monitoring</dt><dd>Availability, SSL and domain health are included</dd></div>
			<div><dt>Database tidy</dt><dd>Scheduled. The last tidy appears after it runs.</dd></div>
		</dl>
	</section>

	<section class="rw-maint-reports" id="rw-maint-reports" aria-labelledby="rw-maint-reports-title">
		<div class="rw-maint-reports-intro">
			<p class="rw-maint-kicker">Latest report</p>
			<h2 id="rw-maint-reports-title">What we report for <?php echo esc_html( $host ); ?></h2>
		</div>
		<?php foreach ( $rw_view['reports'] as $report ) : ?>
			<article class="rw-maint-report">
				<h3><?php echo esc_html( $report['title'] ); ?></h3>
				<p><?php echo esc_html( $report['text'] ); ?></p>
			</article>
		<?php endforeach; ?>
	</section>

	<?php if ( count( $rw_view['sites'] ) > 1 ) : ?>
		<section class="rw-maint-band" aria-labelledby="rw-maint-sites-title">
			<h2 id="rw-maint-sites-title">Websites on this plan</h2>
			<ul class="rw-maint-site-list">
				<?php foreach ( $rw_view['sites'] as $row ) : ?>
					<li><strong><?php echo esc_html( $row['host'] ); ?></strong> <span><?php echo esc_html( $row['label'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( $site ) : ?>
		<section class="rw-maint-split" aria-labelledby="rw-maint-prefs-title">
			<div>
				<h2 id="rw-maint-prefs-title">Report preferences</h2>
				<p class="rw-maint-intro">Choose where maintenance summaries are sent.</p>
				<?php RW_Portal_Account::render_report_preferences( $site ); ?>
			</div>
			<div>
				<h2>Connection</h2>
				<p class="rw-maint-intro">You can disconnect this website. Maintenance reports stop until you connect it again.</p>
				<?php RW_Portal_Account::render_disconnect_form( $site ); ?>
			</div>
		</section>
	<?php endif; ?>
</article>
