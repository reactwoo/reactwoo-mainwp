<?php
/**
 * Chooser for customers with more than one maintenance plan.
 *
 * @var array $rw_sites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cards = isset( $rw_sites ) && is_array( $rw_sites ) ? $rw_sites : array();
$count = count( $cards );
?>
<section class="rw-maint-sites-index" aria-labelledby="rw-maint-sites-index-title">
	<header class="rw-maint-sites-index-head">
		<h2 id="rw-maint-sites-index-title">Your sites</h2>
		<p>You have <?php echo esc_html( (string) $count ); ?> maintenance <?php echo 1 === $count ? 'plan' : 'plans'; ?>. Choose a site to view its dashboard.</p>
	</header>
	<div class="rw-maint-row rw-maint-row-3 rw-maint-site-cards">
		<?php foreach ( $cards as $card ) : ?>
			<article class="rw-maint-site-card">
				<div class="rw-maint-site-card-top">
					<p class="rw-maint-kicker"><?php echo esc_html( $card['plan'] ); ?></p>
					<p class="rw-maint-pill rw-maint-pill--<?php echo ! empty( $card['connected'] ) ? 'good' : 'attention'; ?>"><?php echo esc_html( $card['status'] ); ?></p>
				</div>
				<h3><?php echo esc_html( $card['host'] ); ?></h3>
				<p class="rw-maint-site-meta"><?php echo '' !== $card['renews'] ? 'Renews ' . esc_html( $card['renews'] ) : esc_html( $card['plan'] ); ?></p>
				<dl class="rw-maint-count-list">
					<div>
						<dt>Updates pending</dt>
						<dd<?php echo ! empty( $card['updates_attention'] ) ? ' class="is-attention"' : ''; ?>><?php echo esc_html( $card['updates'] ); ?></dd>
					</div>
					<div>
						<dt>Last backup</dt>
						<dd><?php echo esc_html( $card['backup'] ); ?></dd>
					</div>
				</dl>
				<?php if ( ! empty( $card['flags'] ) ) : ?>
					<div class="rw-maint-attention">
						<p>Needs attention <span><?php echo esc_html( (string) count( $card['flags'] ) ); ?></span></p>
						<ul>
							<?php foreach ( $card['flags'] as $flag ) : ?>
								<li><?php echo esc_html( $flag ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php else : ?>
					<p class="rw-maint-site-clear">Attention flags <strong>None</strong></p>
				<?php endif; ?>
				<div class="rw-maint-site-actions">
					<?php if ( empty( $card['connected'] ) ) : ?>
						<a class="rw-maint-button" href="<?php echo esc_url( $card['url'] ); ?>">Finish connecting</a>
					<?php endif; ?>
					<a class="<?php echo empty( $card['connected'] ) ? 'rw-maint-button-secondary' : 'rw-maint-button'; ?>" href="<?php echo esc_url( $card['url'] ); ?>">View dashboard</a>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
