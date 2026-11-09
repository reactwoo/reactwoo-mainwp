<?php
/**
 * Update reports on the connected maintenance dashboard.
 *
 * @var array $rw_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$update_reports = ( isset( $rw_view['update_reports'] ) && is_array( $rw_view['update_reports'] ) ) ? $rw_view['update_reports'] : array();
$latest         = ( isset( $update_reports['latest'] ) && is_array( $update_reports['latest'] ) ) ? $update_reports['latest'] : null;
$items          = ( isset( $update_reports['items'] ) && is_array( $update_reports['items'] ) ) ? $update_reports['items'] : array();
$site           = ( isset( $rw_view['site'] ) && is_object( $rw_view['site'] ) ) ? $rw_view['site'] : null;

$label_for = function ( $value ) {
	$map = array(
		'critical' => 'Critical',
		'high'     => 'High',
		'medium'   => 'Medium',
		'low'      => 'Low',
		'none'     => 'None',
		'exposure' => 'Exposure',
		'post_patch' => 'After update',
		'status'   => 'Status',
		'core'     => 'Core',
		'plugin'   => 'Plugin',
		'theme'    => 'Theme',
		'unknown'  => 'Unknown',
	);
	$key = sanitize_key( (string) $value );

	return isset( $map[ $key ] ) ? $map[ $key ] : 'Not recorded';
};
?>
<section class="rw-maint-reports-band" id="rw-maint-update-reports" aria-labelledby="rw-maint-update-reports-title">
	<h2 id="rw-maint-update-reports-title">Update reports</h2>
	<?php if ( ! empty( $update_reports['unavailable'] ) ) : ?>
		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-panel-copy">Update reports could not be loaded just now.</p>
		</article>
	<?php elseif ( ! is_array( $latest ) ) : ?>
		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-panel-copy">No update report has arrived for this website yet.</p>
		</article>
	<?php else : ?>
		<article class="rw-maint-panel rw-maint-panel--paper">
			<p class="rw-maint-kicker"><?php echo esc_html( $label_for( isset( $latest['phase'] ) ? $latest['phase'] : '' ) ); ?></p>
			<h3><?php echo esc_html( isset( $latest['summary'] ) ? (string) $latest['summary'] : 'Update report' ); ?></h3>
			<dl class="rw-maint-report-facts">
				<div><dt>Criticality</dt><dd><?php echo esc_html( $label_for( isset( $latest['criticality'] ) ? $latest['criticality'] : '' ) ); ?></dd></div>
				<div><dt>Breakage risk</dt><dd><?php echo esc_html( $label_for( isset( $latest['breakage_risk'] ) ? $latest['breakage_risk'] : '' ) ); ?></dd></div>
				<?php if ( ! empty( $latest['reported_at'] ) ) : ?>
					<div><dt>Reported</dt><dd><?php echo esc_html( (string) $latest['reported_at'] ); ?> UTC</dd></div>
				<?php endif; ?>
			</dl>
			<?php if ( ! empty( $latest['window_label'] ) ) : ?>
				<p class="rw-maint-panel-copy"><?php echo esc_html( (string) $latest['window_label'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $latest['suggested_patch_at'] ) ) : ?>
				<p class="rw-maint-panel-copy">Suggested patch time: <?php echo esc_html( (string) $latest['suggested_patch_at'] ); ?> UTC. This is a suggestion, not a booked change.</p>
			<?php endif; ?>

			<h4>What was exposed</h4>
			<?php if ( empty( $latest['exposed'] ) ) : ?>
				<p class="rw-maint-panel-copy">Nothing in this report is still marked as exposed.</p>
			<?php else : ?>
				<ul class="rw-maint-report-list">
					<?php foreach ( $latest['exposed'] as $item ) : ?>
						<li>
							<strong><?php echo esc_html( isset( $item['slug'] ) ? (string) $item['slug'] : '' ); ?></strong>
							<?php if ( ! empty( $item['type'] ) ) : ?>
								<span class="rw-maint-report-meta"><?php echo esc_html( $label_for( $item['type'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $item['from'] ) || ! empty( $item['to'] ) ) : ?>
								<span><?php echo esc_html( (string) $item['from'] ); ?> to <?php echo esc_html( (string) $item['to'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $item['excerpt'] ) ) : ?>
								<span class="rw-maint-report-meta"><?php echo esc_html( (string) $item['excerpt'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h4>What was fixed</h4>
			<?php if ( empty( $latest['fixed'] ) ) : ?>
				<p class="rw-maint-panel-copy">This report does not list anything as fixed.</p>
			<?php else : ?>
				<ul class="rw-maint-report-list">
					<?php foreach ( $latest['fixed'] as $item ) : ?>
						<li>
							<?php if ( isset( $item['kind'] ) && 'conflict' === $item['kind'] ) : ?>
								Conflict removed: <?php echo esc_html( (string) $item['slug'] ); ?>
							<?php else : ?>
								<strong><?php echo esc_html( isset( $item['slug'] ) ? (string) $item['slug'] : '' ); ?></strong>
							<?php if ( ! empty( $item['type'] ) ) : ?>
								<span class="rw-maint-report-meta"><?php echo esc_html( $label_for( $item['type'] ) ); ?></span>
							<?php endif; ?>
								<?php if ( ! empty( $item['from'] ) || ! empty( $item['to'] ) ) : ?>
									<span><?php echo esc_html( (string) $item['from'] ); ?> to <?php echo esc_html( (string) $item['to'] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h4>Failed pages</h4>
			<?php if ( ! empty( $latest['pages_checked'] ) ) : ?>
				<p class="rw-maint-report-meta">Pages checked: <?php echo esc_html( (string) (int) $latest['pages_checked'] ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $latest['failed_pages'] ) ) : ?>
				<p class="rw-maint-panel-copy">No failed pages were recorded on this report.</p>
			<?php else : ?>
				<ul class="rw-maint-report-list">
					<?php foreach ( $latest['failed_pages'] as $failure ) : ?>
						<li>
							<?php echo esc_html( isset( $failure['url'] ) ? (string) $failure['url'] : '' ); ?>
							<?php if ( ! empty( $failure['status'] ) ) : ?>
								<span>status <?php echo esc_html( (string) (int) $failure['status'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $failure['note'] ) ) : ?>
								<span class="rw-maint-report-meta"><?php echo esc_html( (string) $failure['note'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</article>

		<?php if ( $site ) : ?>
			<div class="rw-maint-action-categories">
				<section class="rw-maint-action-cat" aria-labelledby="rw-maint-report-options">
					<h3 class="rw-maint-action-cat__label" id="rw-maint-report-options">After this report</h3>
					<div class="rw-maint-action-cat__cards">
						<article class="rw-maint-panel rw-maint-action-card" id="rw-maint-report-rollback">
							<h4>Roll back</h4>
							<?php if ( ! empty( $latest['backup_ref'] ) ) : ?>
								<p class="rw-maint-panel-copy">Restore snapshot <?php echo esc_html( (string) $latest['backup_ref'] ); ?> stored with this report. This asks the website to replace the current plugins, themes and database with that snapshot. It runs only after you tick the confirmation.</p>
								<?php
								$card_notice = RW_Portal_Customer_Actions::consume_card_notice_for_action( get_current_user_id(), 'report_rollback' );
								echo '<form class="rw-maint-action-form" method="post">';
								RW_Portal_Account::action_fields();
								echo '<input type="hidden" name="rw_portal_action" value="report_rollback" />';
								echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) (int) $site->id ) . '" />';
								echo '<input type="hidden" name="report_id" value="' . esc_attr( (string) (int) $latest['id'] ) . '" />';
								echo '<input type="hidden" name="backup_ref" value="' . esc_attr( (string) $latest['backup_ref'] ) . '" />';
								echo '<label class="rw-maint-confirm"><input type="checkbox" name="rw_confirm_action" value="1" required /> I confirm I want this website restored from snapshot ' . esc_html( (string) $latest['backup_ref'] ) . '.</label>';
								echo '<button type="submit" class="rw-maint-button">Roll back this snapshot</button>';
								echo '</form>';
								if ( $card_notice ) {
									echo '<p class="rw-maint-card-notice rw-maint-card-notice--' . esc_attr( $card_notice['tone'] ) . '" role="status">';
									echo '<span class="rw-maint-pill">' . esc_html( $card_notice['label'] ) . '</span> ';
									echo '<span class="rw-maint-card-notice__text">' . esc_html( $card_notice['message'] ) . '</span>';
									echo '</p>';
								}
								?>
							<?php else : ?>
								<p class="rw-maint-panel-copy">A snapshot reference was not stored with this report, so rollback is not available.</p>
							<?php endif; ?>
						</article>

						<article class="rw-maint-panel rw-maint-action-card" id="rw-maint-report-support">
							<h4>Contact support</h4>
							<p class="rw-maint-panel-copy">Open a support request for this website on the maintenance plan. The request is recorded against the subscription website. It does not start work by itself.</p>
							<?php
							$support_notice = RW_Portal_Customer_Actions::consume_card_notice_for_action( get_current_user_id(), 'report_support' );
							echo '<form class="rw-maint-action-form" method="post">';
							RW_Portal_Account::action_fields();
							echo '<input type="hidden" name="rw_portal_action" value="report_support" />';
							echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) (int) $site->id ) . '" />';
							echo '<input type="hidden" name="report_id" value="' . esc_attr( (string) (int) $latest['id'] ) . '" />';
							echo '<label class="rw-maint-confirm"><input type="checkbox" name="rw_confirm_action" value="1" required /> I confirm I want a support request for this website.</label>';
							echo '<button type="submit" class="rw-maint-button">Open support request</button>';
							echo '</form>';
							if ( $support_notice ) {
								echo '<p class="rw-maint-card-notice rw-maint-card-notice--' . esc_attr( $support_notice['tone'] ) . '" role="status">';
								echo '<span class="rw-maint-pill">' . esc_html( $support_notice['label'] ) . '</span> ';
								echo '<span class="rw-maint-card-notice__text">' . esc_html( $support_notice['message'] ) . '</span>';
								echo '</p>';
							}
							?>
						</article>

						<article class="rw-maint-panel rw-maint-action-card" id="rw-maint-report-self">
							<h4>Fix it yourself</h4>
							<?php if ( ! empty( $latest['failed_pages'] ) ) : ?>
								<p class="rw-maint-panel-copy">These pages did not respond as expected. You can correct them on the website yourself. Choosing this records that choice and does not change the website from this page.</p>
								<?php
								$self_notice = RW_Portal_Customer_Actions::consume_card_notice_for_action( get_current_user_id(), 'report_self_fix' );
								echo '<form class="rw-maint-action-form" method="post">';
								RW_Portal_Account::action_fields();
								echo '<input type="hidden" name="rw_portal_action" value="report_self_fix" />';
								echo '<input type="hidden" name="site_id" value="' . esc_attr( (string) (int) $site->id ) . '" />';
								echo '<input type="hidden" name="report_id" value="' . esc_attr( (string) (int) $latest['id'] ) . '" />';
								echo '<label class="rw-maint-confirm"><input type="checkbox" name="rw_confirm_action" value="1" required /> I will fix the failed pages on the website myself.</label>';
								echo '<button type="submit" class="rw-maint-button-secondary">I will fix these pages</button>';
								echo '</form>';
								if ( $self_notice ) {
									echo '<p class="rw-maint-card-notice rw-maint-card-notice--' . esc_attr( $self_notice['tone'] ) . '" role="status">';
									echo '<span class="rw-maint-pill">' . esc_html( $self_notice['label'] ) . '</span> ';
									echo '<span class="rw-maint-card-notice__text">' . esc_html( $self_notice['message'] ) . '</span>';
									echo '</p>';
								}
								?>
							<?php else : ?>
								<p class="rw-maint-panel-copy">The latest report did not list any failed pages.</p>
							<?php endif; ?>
						</article>
					</div>
				</section>
			</div>
		<?php endif; ?>

		<?php if ( count( $items ) > 1 ) : ?>
			<article class="rw-maint-panel rw-maint-panel--paper">
				<h3>Earlier reports</h3>
				<ul class="rw-maint-report-list">
					<?php foreach ( array_slice( $items, 1, 12 ) as $item ) : ?>
						<li>
							<strong><?php echo esc_html( $label_for( isset( $item['phase'] ) ? $item['phase'] : '' ) ); ?></strong>
							<span><?php echo esc_html( $label_for( isset( $item['criticality'] ) ? $item['criticality'] : '' ) ); ?></span>
							<?php if ( ! empty( $item['reported_at'] ) ) : ?>
								<span class="rw-maint-report-meta"><?php echo esc_html( (string) $item['reported_at'] ); ?> UTC</span>
							<?php endif; ?>
							<?php if ( ! empty( $item['summary'] ) ) : ?>
								<span class="rw-maint-report-meta"><?php echo esc_html( (string) $item['summary'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</article>
		<?php endif; ?>
	<?php endif; ?>
</section>
