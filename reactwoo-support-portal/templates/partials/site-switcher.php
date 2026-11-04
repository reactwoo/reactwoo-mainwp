<?php
/**
 * Site switcher shown above a dashboard when the customer has several plans.
 *
 * @var array $switcher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $switcher ) || empty( $switcher['sites'] ) || count( $switcher['sites'] ) < 2 ) {
	return;
}

$current = isset( $switcher['current'] ) && is_array( $switcher['current'] ) ? $switcher['current'] : $switcher['sites'][0];
?>
<div class="rw-maint-switcher-bar">
	<p class="rw-maint-switcher-label">Viewing site</p>
	<details class="rw-maint-switcher">
		<summary>
			<strong><?php echo esc_html( $current['host'] ); ?></strong>
			<span><?php echo esc_html( $current['plan'] ); ?> · <?php echo esc_html( $current['status'] ); ?></span>
		</summary>
		<div class="rw-maint-switcher-menu">
			<p>Switch site · <?php echo esc_html( (string) count( $switcher['sites'] ) ); ?> plans</p>
			<ul>
				<?php foreach ( $switcher['sites'] as $site_choice ) : ?>
					<li>
						<a class="<?php echo ! empty( $site_choice['current'] ) ? 'is-current' : ''; ?>" href="<?php echo esc_url( $site_choice['url'] ); ?>">
							<span>
								<strong><?php echo esc_html( $site_choice['host'] ); ?></strong>
								<small><?php echo esc_html( $site_choice['plan'] ); ?></small>
							</span>
							<span class="rw-maint-switcher-meta">
								<?php if ( ! empty( $site_choice['attention'] ) ) : ?>
									<em><?php echo esc_html( (string) (int) $site_choice['attention'] ); ?> need attention</em>
								<?php endif; ?>
								<span class="rw-maint-pill rw-maint-pill--<?php echo 'connected' === $site_choice['state'] ? 'good' : 'attention'; ?>"><?php echo esc_html( $site_choice['status'] ); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<a class="rw-maint-switcher-index" href="<?php echo esc_url( $switcher['index_url'] ); ?>">View all sites</a>
		</div>
	</details>
	<a class="rw-maint-switcher-all" href="<?php echo esc_url( $switcher['index_url'] ); ?>">All sites (<?php echo esc_html( (string) count( $switcher['sites'] ) ); ?>)</a>
</div>
