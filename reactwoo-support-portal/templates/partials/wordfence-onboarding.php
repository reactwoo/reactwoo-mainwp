<?php
/**
 * Wordfence onboarding on the customer dashboard.
 *
 * @var array       $wordfence
 * @var object|null $site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $wordfence ) || ! is_array( $wordfence ) ) {
	return;
}

$report   = isset( $wordfence['report'] ) && is_array( $wordfence['report'] ) ? $wordfence['report'] : array();
$version  = isset( $report['version'] ) ? (string) $report['version'] : '';
$template = isset( $report['template_applied'] ) ? (string) $report['template_applied'] : '';
$removed  = isset( $report['conflicts_removed'] ) && is_array( $report['conflicts_removed'] ) ? $report['conflicts_removed'] : array();
$message  = isset( $wordfence['message'] ) ? (string) $wordfence['message'] : '';
?>
<section class="rw-maint-panel rw-maint-panel--paper rw-wf" id="rw-wordfence" aria-labelledby="rw-wf-title">
	<p class="rw-maint-kicker">Security</p>
	<h2 id="rw-wf-title">Wordfence</h2>
	<?php if ( '' !== $message ) : ?>
		<p class="rw-wf-note"><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $version ) : ?>
		<p class="rw-wf-note">
			<?php
			$wf_line = 'Wordfence ' . $version . ' is on this website.';
			if ( ! empty( $report['pro'] ) ) {
				$wf_line .= ' The Pro licence is active.';
			}
			echo esc_html( $wf_line );
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $template ) : ?>
		<p class="rw-wf-note">
			<?php
			echo esc_html( 'Settings template ' . $template . ' has been applied.' );
			if ( isset( $wordfence['approval_status'] ) && 'awaiting_approval' === $wordfence['approval_status'] ) {
				echo ' ' . esc_html( 'This template is a draft waiting for approval.' );
			}
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $wordfence['notices'] ) && is_array( $wordfence['notices'] ) ) : ?>
		<ul class="rw-wf-list">
			<?php foreach ( $wordfence['notices'] as $row ) : ?>
				<?php if ( ! is_array( $row ) ) { continue; } ?>
				<li>
					<strong><?php echo esc_html( isset( $row['name'] ) ? (string) $row['name'] : '' ); ?></strong>
					<span><?php echo esc_html( isset( $row['reason'] ) ? (string) $row['reason'] : '' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $wordfence['show_conflicts'] ) && ( ! empty( $wordfence['removable'] ) || ! empty( $wordfence['optional'] ) ) && ! empty( $site ) && is_object( $site ) ) : ?>
		<form class="rw-maint-action-form" method="post">
			<?php RW_Portal_Account::action_fields(); ?>
			<input type="hidden" name="rw_portal_action" value="wordfence_remove_conflicts" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) (int) $site->id ); ?>" />
			<input type="hidden" name="subscription_id" value="<?php echo esc_attr( (string) (int) $site->subscription_id ); ?>" />

			<?php if ( ! empty( $wordfence['removable'] ) ) : ?>
				<h3>Plugins to remove before Wordfence</h3>
				<p class="rw-wf-note">These clash with Wordfence. They stay installed until you tick them and confirm.</p>
				<ul class="rw-wf-list">
					<?php foreach ( $wordfence['removable'] as $row ) : ?>
						<?php if ( ! is_array( $row ) || empty( $row['file'] ) ) { continue; } ?>
						<li>
							<label>
								<input type="checkbox" name="rw_wordfence_files[]" value="<?php echo esc_attr( (string) $row['file'] ); ?>" />
								<span>
									<strong><?php echo esc_html( isset( $row['name'] ) ? (string) $row['name'] : (string) $row['file'] ); ?></strong>
									<span><?php echo esc_html( isset( $row['reason'] ) ? (string) $row['reason'] : '' ); ?></span>
								</span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $wordfence['optional'] ) ) : ?>
				<h3>Optional overlaps</h3>
				<p class="rw-wf-note">You can leave these. Tick one only if you want it removed.</p>
				<ul class="rw-wf-list">
					<?php foreach ( $wordfence['optional'] as $row ) : ?>
						<?php if ( ! is_array( $row ) || empty( $row['file'] ) ) { continue; } ?>
						<li>
							<label>
								<input type="checkbox" name="rw_wordfence_files[]" value="<?php echo esc_attr( (string) $row['file'] ); ?>" />
								<span>
									<strong><?php echo esc_html( isset( $row['name'] ) ? (string) $row['name'] : (string) $row['file'] ); ?></strong>
									<span><?php echo esc_html( isset( $row['reason'] ) ? (string) $row['reason'] : '' ); ?></span>
								</span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<label class="rw-maint-confirm"><input type="checkbox" name="rw_confirm_action" value="1" required /> I confirm the ticked plugins can be deactivated and removed.</label>
			<button type="submit" class="rw-maint-button">Remove selected plugins</button>
		</form>
	<?php elseif ( ! empty( $wordfence['show_setup'] ) && ! empty( $site ) && is_object( $site ) ) : ?>
		<form class="rw-maint-action-form" method="post">
			<?php RW_Portal_Account::action_fields(); ?>
			<input type="hidden" name="rw_portal_action" value="wordfence_setup" />
			<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) (int) $site->id ); ?>" />
			<input type="hidden" name="subscription_id" value="<?php echo esc_attr( (string) (int) $site->subscription_id ); ?>" />
			<label class="rw-maint-confirm"><input type="checkbox" name="rw_confirm_action" value="1" required /> I confirm Wordfence can be installed on this website.</label>
			<button type="submit" class="rw-maint-button">Set up Wordfence</button>
		</form>
	<?php endif; ?>

	<?php if ( ! empty( $removed ) ) : ?>
		<p class="rw-wf-note"><?php echo esc_html( count( $removed ) . ' conflicting plugin' . ( 1 === count( $removed ) ? ' was' : 's were' ) . ' removed with your confirmation.' ); ?></p>
	<?php endif; ?>
</section>
