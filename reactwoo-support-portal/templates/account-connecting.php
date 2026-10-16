<?php
/**
 * Connection in progress. No percentage is shown.
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
	<section class="rw-maint-hero" aria-labelledby="rw-maint-progress-title">
		<div>
			<p class="rw-maint-pill"><?php echo esc_html( $rw_view['status_label'] ); ?></p>
			<h2 id="rw-maint-progress-title">Setting up <?php echo esc_html( $host ); ?></h2>
			<p class="rw-maint-lead">Stay on this page or come back later. We'll keep going from the same connection.</p>
			<p class="rw-maint-helper">No WordPress password is stored.</p>
		</div>
		<aside class="rw-maint-plan" aria-label="Your plan">
			<p class="rw-maint-kicker">Your plan</p>
			<h3><?php echo esc_html( $rw_view['plan_name'] ); ?></h3>
			<p><?php echo esc_html( $host ); ?></p>
		</aside>
	</section>

	<section class="rw-maint-band" aria-labelledby="rw-maint-steps-live">
		<h2 id="rw-maint-steps-live">Where setup is up to</h2>
		<ol class="rw-maint-progress">
			<?php foreach ( $rw_view['progress'] as $step ) : ?>
				<li class="is-<?php echo esc_attr( sanitize_html_class( strtolower( str_replace( ' ', '-', $step['mark'] ) ) ) ); ?>">
					<p class="rw-maint-kicker"><?php echo esc_html( $step['mark'] ); ?></p>
					<h3><?php echo esc_html( $step['title'] ); ?></h3>
					<p><?php echo esc_html( $step['text'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
</article>
