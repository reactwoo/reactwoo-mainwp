<?php
/**
 * Setup needs the customer.
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
	<section class="rw-maint-hero" aria-labelledby="rw-maint-attention-title">
		<div>
			<p class="rw-maint-pill"><?php echo esc_html( $rw_view['status_label'] ); ?></p>
			<h2 id="rw-maint-attention-title">Finish connecting <?php echo esc_html( $host ); ?></h2>
			<p class="rw-maint-lead"><?php echo esc_html( $rw_view['message'] ? $rw_view['message'] : 'Automated setup did not finish. Connect WordPress again to continue.' ); ?></p>
			<?php if ( $rw_view['can_connect'] && $site ) : ?>
				<?php RW_Portal_Account::render_plugin_download(); ?>
				<?php RW_Portal_Account::render_connect_existing_form( $site ); ?>
				<p class="rw-maint-helper">No WordPress password required.</p>
			<?php endif; ?>
			<?php if ( $site && ! empty( $rw_view['manual_pending'] ) ) : ?>
				<p class="rw-maint-helper">We already have your request. ReactWoo will finish connecting this website.</p>
			<?php elseif ( $site ) : ?>
				<?php RW_Portal_Account::render_manual_request( $site ); ?>
				<p class="rw-maint-helper">This does not create a permanent administrator, and your WordPress password is not stored.</p>
			<?php endif; ?>
		</div>
		<aside class="rw-maint-plan" aria-label="What to do">
			<p class="rw-maint-kicker">Next step</p>
			<h3>Install, then connect</h3>
			<ul>
				<li>Download the site plugin<span>The button on this page saves the file.</span></li>
				<li>Install and activate it<span>In WordPress: Plugins, Add New, Upload Plugin.</span></li>
				<li>Come back and connect<span>Approve access when your website asks. No password is stored.</span></li>
			</ul>
		</aside>
	</section>
</article>
