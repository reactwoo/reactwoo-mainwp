<?php
/**
 * Setup required. Approved onboarding frames.
 *
 * @var array $rw_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plan_name = $rw_view['plan_name'];
$minutes   = (int) $rw_view['minutes'];
$site      = $rw_view['site'];
$host      = $site ? wp_parse_url( (string) $site->site_url, PHP_URL_HOST ) : '';
$host      = $host ? $host : 'your website';
?>
<article class="rw-maint-plan-block">
	<section class="rw-maint-hero" aria-labelledby="rw-maint-hero-title">
		<div>
			<p class="rw-maint-pill"><?php echo esc_html( $plan_name ); ?></p>
			<h2 id="rw-maint-hero-title">Your website,<br />looked after.</h2>
			<p class="rw-maint-lead">We keep WordPress updated, backed up and monitored, so you can focus on your business instead of routine website maintenance.</p>
			<ul class="rw-maint-checks">
				<li>Secure WordPress connection, approved by you</li>
				<li>We set up backups, monitoring, updates and reports</li>
			</ul>
			<?php if ( $rw_view['can_connect'] && ! $site ) : ?>
				<?php RW_Portal_Account::render_connect_form( $rw_view['subscription_id'], false ); ?>
				<p class="rw-maint-helper">No WordPress password required.</p>
			<?php elseif ( $rw_view['can_connect'] && $site ) : ?>
				<?php RW_Portal_Account::render_connect_existing_form( $site ); ?>
				<p class="rw-maint-helper">No WordPress password required.</p>
			<?php else : ?>
				<p class="rw-maint-note"><?php echo esc_html( $rw_view['message'] ); ?></p>
			<?php endif; ?>
		</div>
		<aside class="rw-maint-plan" aria-label="Your plan">
			<p class="rw-maint-kicker">Your plan</p>
			<h3><?php echo esc_html( $plan_name ); ?></h3>
			<ul>
				<?php foreach ( $rw_view['plan_points'] as $point ) : ?>
					<li><?php echo esc_html( $point['title'] ); ?><span><?php echo esc_html( $point['text'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</aside>
	</section>

	<section class="rw-maint-band" aria-labelledby="rw-maint-includes-title">
		<p class="rw-maint-kicker">What your plan includes</p>
		<h2 id="rw-maint-includes-title">Your website maintenance, covered.</h2>
		<p class="rw-maint-intro">Practical protection and support for the WordPress tasks that are easy to overlook.</p>
		<div class="rw-maint-grid">
			<?php foreach ( $rw_view['benefits'] as $benefit ) : ?>
				<article class="rw-maint-card">
					<p class="rw-maint-kicker"><?php echo esc_html( $benefit['kicker'] ); ?></p>
					<h3><?php echo esc_html( $benefit['title'] ); ?></h3>
					<p><?php echo esc_html( $benefit['text'] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="rw-maint-split" aria-labelledby="rw-maint-licence-title">
		<div>
			<p class="rw-maint-kicker">More value from maintenance</p>
			<h2 id="rw-maint-licence-title">Reduce separate premium plugin costs.</h2>
			<p class="rw-maint-intro">Eligible premium licences are included while your plan is active. The exact products depend on your plan.</p>
		</div>
		<aside class="rw-maint-plan">
			<p class="rw-maint-kicker">Licences in your plan</p>
			<ul>
				<li>Included while subscribed<span>Coverage follows this plan, not a separate renewal.</span></li>
				<li>Shown from your plan<span>Products can change when the plan changes.</span></li>
			</ul>
		</aside>
	</section>

	<section class="rw-maint-band" aria-labelledby="rw-maint-steps-title">
		<p class="rw-maint-kicker">Getting started</p>
		<h2 id="rw-maint-steps-title">Install the plugin. Then connect.</h2>
		<ol class="rw-maint-steps">
			<li><h3>1. Download the site plugin</h3><p>Use the download on this page. In WordPress, open Plugins, choose Add New, then Upload Plugin.</p></li>
			<li><h3>2. Install and activate it</h3><p>Choose the file, install it, and activate it. Leave it in place.</p></li>
			<li><h3>3. Connect WordPress</h3><p>Come back here and approve the connection. No WordPress password is stored.</p></li>
		</ol>
	</section>

	<section class="rw-maint-support" aria-labelledby="rw-maint-support-title">
		<div>
			<p class="rw-maint-kicker">Included support</p>
			<?php if ( ! empty( $rw_view['show_included_minutes'] ) ) : ?>
				<p class="rw-maint-minutes"><?php echo esc_html( (string) (int) $rw_view['included_minutes_remaining'] ); ?> minutes</p>
				<p>of <?php echo esc_html( (string) (int) $rw_view['included_minutes_granted'] ); ?> remain in this service period</p>
			<?php else : ?>
				<p>This plan does not include technical support minutes.</p>
			<?php endif; ?>
			<?php if ( isset( $rw_view['purchased_minutes_remaining'] ) && (int) $rw_view['purchased_minutes_remaining'] > 0 ) : ?>
				<p><?php echo esc_html( (string) (int) $rw_view['purchased_minutes_remaining'] ); ?> purchased minutes remain.</p>
			<?php endif; ?>
		</div>
		<div>
			<h2 id="rw-maint-support-title">Real help when your site needs it.</h2>
			<p>Routine maintenance is handled for you. This plan also includes hands-on technical support each billing period.</p>
			<p>If a request needs additional billable work, we'll confirm this with you first.</p>
		</div>
	</section>

	<?php if ( $rw_view['can_connect'] && ! $site ) : ?>
		<section class="rw-maint-final" aria-labelledby="rw-maint-final-title">
			<h2 id="rw-maint-final-title">Ready to look after <?php echo esc_html( $host ); ?>?</h2>
			<p>Download the site plugin, install and activate it, then connect your WordPress website.</p>
			<?php RW_Portal_Account::render_plugin_download(); ?>
			<?php RW_Portal_Account::render_connect_form( $rw_view['subscription_id'], true ); ?>
			<p class="rw-maint-intro">You control access and can disconnect ReactWoo when needed. No WordPress password required.</p>
		</section>
	<?php endif; ?>
</article>
