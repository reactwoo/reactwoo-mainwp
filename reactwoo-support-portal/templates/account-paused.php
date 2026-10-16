<?php
/**
 * Paused or disconnected plan/site.
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
	<section class="rw-maint-hero" aria-labelledby="rw-maint-paused-title">
		<div>
			<p class="rw-maint-pill"><?php echo esc_html( $rw_view['status_label'] ); ?></p>
			<h2 id="rw-maint-paused-title"><?php echo 'disconnected' === $rw_view['state'] ? 'Website disconnected' : 'Maintenance is paused'; ?></h2>
			<p class="rw-maint-lead">
				<?php
				echo esc_html(
					$rw_view['message']
						? $rw_view['message']
						: ( 'disconnected' === $rw_view['state']
							? $host . ' is disconnected. Connect WordPress again when you want maintenance to resume.'
							: 'This plan is paused, so website connection and reports are waiting.' )
				);
				?>
			</p>
			<?php if ( 'disconnected' === $rw_view['state'] && $rw_view['can_connect'] && $site ) : ?>
				<?php RW_Portal_Account::render_connect_existing_form( $site ); ?>
				<p class="rw-maint-helper">No WordPress password required.</p>
			<?php endif; ?>
		</div>
		<aside class="rw-maint-plan" aria-label="Your plan">
			<p class="rw-maint-kicker">Your plan</p>
			<h3><?php echo esc_html( $rw_view['plan_name'] ); ?></h3>
			<p><?php echo esc_html( $host ); ?></p>
		</aside>
	</section>
</article>
