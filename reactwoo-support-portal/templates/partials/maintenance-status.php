<?php
/**
 * Live maintenance status.
 *
 * Expects $maintenance from RW_Portal_Maintenance_Status::present().
 * Each region is its own component so the layout can be restyled later.
 *
 * @var array $maintenance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $maintenance ) || ! is_array( $maintenance ) ) {
	return;
}

$cards    = isset( $maintenance['cards'] ) && is_array( $maintenance['cards'] ) ? $maintenance['cards'] : array();
$login    = isset( $maintenance['login'] ) && is_array( $maintenance['login'] ) ? $maintenance['login'] : array();
$content  = isset( $maintenance['content'] ) && is_array( $maintenance['content'] ) ? $maintenance['content'] : array();
$activity = isset( $maintenance['activity'] ) && is_array( $maintenance['activity'] ) ? $maintenance['activity'] : array();
?>
<section class="rw-maint-status" aria-labelledby="rw-maint-live-status">
	<div class="rw-maint-status-intro">
		<p class="rw-maint-kicker">This website</p>
		<h2 id="rw-maint-live-status">How your website is doing</h2>
	</div>

	<div class="rw-maint-status-grid">
		<?php foreach ( $cards as $card ) : ?>
			<?php if ( empty( $card['id'] ) ) { continue; } ?>
			<article class="rw-maint-status-card rw-maint-status-card--<?php echo esc_attr( $card['state'] ); ?>" data-status-card="<?php echo esc_attr( $card['id'] ); ?>">
				<p class="rw-maint-kicker"><?php echo esc_html( $card['kicker'] ); ?></p>
				<h3><?php echo esc_html( $card['title'] ); ?></h3>
				<p class="rw-maint-status-state"><?php echo esc_html( $card['state_label'] ); ?></p>
				<?php if ( ! empty( $card['summary'] ) ) : ?>
					<p><?php echo esc_html( $card['summary'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $card['items'] ) ) : ?>
					<ul class="rw-maint-status-list">
						<?php foreach ( $card['items'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>

	<div class="rw-maint-detail-grid">
		<article class="rw-maint-detail-card" data-status-card="login">
			<p class="rw-maint-kicker">Access</p>
			<h3><?php echo esc_html( isset( $login['title'] ) ? $login['title'] : 'Last admin login' ); ?></h3>
			<?php if ( ! empty( $login['available'] ) ) : ?>
				<p class="rw-maint-detail-value"><?php echo esc_html( $login['username'] ? $login['username'] : 'An administrator' ); ?></p>
				<?php if ( ! empty( $login['when'] ) ) : ?>
					<p><?php echo esc_html( $login['when'] ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php echo esc_html( isset( $login['empty'] ) ? $login['empty'] : 'Not available yet' ); ?></p>
			<?php endif; ?>
		</article>

		<article class="rw-maint-detail-card" data-status-card="activity">
			<p class="rw-maint-kicker">Activity</p>
			<h3><?php echo esc_html( isset( $activity['title'] ) ? $activity['title'] : 'Maintenance activity' ); ?></h3>
			<?php $last = isset( $activity['last'] ) && is_array( $activity['last'] ) ? $activity['last'] : array(); ?>
			<?php $next = isset( $activity['next'] ) && is_array( $activity['next'] ) ? $activity['next'] : array(); ?>
			<?php if ( ! empty( $last['available'] ) ) : ?>
				<p class="rw-maint-detail-value"><?php echo esc_html( $last['when'] ); ?></p>
				<?php if ( ! empty( $last['done'] ) ) : ?>
					<ul class="rw-maint-status-list">
						<?php foreach ( $last['done'] as $done ) : ?>
							<li><?php echo esc_html( $done ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( ! empty( $last['detail'] ) ) : ?>
					<p><?php echo esc_html( $last['detail'] ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php echo esc_html( isset( $last['empty'] ) ? $last['empty'] : 'Not available yet' ); ?></p>
			<?php endif; ?>
			<p class="rw-maint-detail-next">
				<?php if ( ! empty( $next['available'] ) ) : ?>
					Next scheduled run: <?php echo esc_html( $next['when'] ); ?>
				<?php else : ?>
					Next scheduled run: <?php echo esc_html( isset( $next['empty'] ) ? $next['empty'] : 'Not scheduled yet' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $activity['recent'] ) ) : ?>
				<h4>Recent runs</h4>
				<ol class="rw-maint-status-list rw-maint-run-list">
					<?php foreach ( $activity['recent'] as $run ) : ?>
						<li>
							<span><?php echo esc_html( isset( $run['when'] ) ? $run['when'] : '' ); ?></span>
							<span><?php echo esc_html( isset( $run['done'] ) ? $run['done'] : '' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p><?php echo esc_html( isset( $activity['recent_empty'] ) ? $activity['recent_empty'] : 'No recent runs yet' ); ?></p>
			<?php endif; ?>
		</article>
	</div>

	<section class="rw-maint-catalogue" aria-labelledby="rw-maint-catalogue-title">
		<h3 id="rw-maint-catalogue-title"><?php echo esc_html( isset( $content['title'] ) ? $content['title'] : 'Catalogue' ); ?></h3>
		<div class="rw-maint-content-grid">
			<?php foreach ( isset( $content['groups'] ) ? $content['groups'] : array() as $group ) : ?>
				<article class="rw-maint-detail-card" data-status-card="<?php echo esc_attr( strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', isset( $group['title'] ) ? $group['title'] : 'group' ), '-' ) ) ); ?>">
					<h4><?php echo esc_html( isset( $group['title'] ) ? $group['title'] : '' ); ?></h4>
					<dl class="rw-maint-count-list">
						<?php foreach ( isset( $group['rows'] ) ? $group['rows'] : array() as $row ) : ?>
							<div>
								<dt><?php echo esc_html( $row['label'] ); ?></dt>
								<dd><?php echo esc_html( $row['value'] ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
</section>
