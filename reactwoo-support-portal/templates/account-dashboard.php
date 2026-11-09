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
if ( $site && class_exists( 'RW_Portal_Reports' ) ) {
	$rw_view['update_reports'] = RW_Portal_Reports::for_dashboard( $site );
}
$host = $site ? wp_parse_url( (string) $site->site_url, PHP_URL_HOST ) : '';
$host = $host ? $host : 'your website';
$granted   = isset( $rw_view['included_minutes_granted'] ) ? (int) $rw_view['included_minutes_granted'] : 0;
$remaining = isset( $rw_view['included_minutes_remaining'] ) ? (int) $rw_view['included_minutes_remaining'] : 0;
$purchased_for_site = isset( $rw_view['purchased_minutes_remaining'] ) ? (int) $rw_view['purchased_minutes_remaining'] : 0;
$unassigned_minutes = isset( $rw_view['unassigned_purchased_minutes'] ) ? (int) $rw_view['unassigned_purchased_minutes'] : 0;
$support_host = isset( $rw_view['support_site_host'] ) ? (string) $rw_view['support_site_host'] : '';
$plan_host = isset( $rw_view['plan_site_host'] ) ? (string) $rw_view['plan_site_host'] : '';
$included_for_site = ! isset( $rw_view['included_for_site'] ) || ! empty( $rw_view['included_for_site'] );
$used      = $granted > 0 ? max( 0, $granted - $remaining ) : 0;
$used_pct  = $granted > 0 ? min( 100, (int) round( ( $used / $granted ) * 100 ) ) : 0;
$switcher  = isset( $rw_view['switcher'] ) && is_array( $rw_view['switcher'] ) ? $rw_view['switcher'] : array();
$needs_attention = ! empty( $rw_view['maintenance'] ) && is_array( $rw_view['maintenance'] ) && RW_Portal_Maintenance_Status::needs_attention( $rw_view['maintenance'] );
?>
<div class="rw-maint-dash">
	<?php
	if ( ! empty( $switcher ) ) {
		include RW_PORTAL_DIR . 'templates/partials/site-switcher.php';
	}
	?>
	<div class="rw-maint-dash-toolbar">
		<?php include RW_PORTAL_DIR . 'templates/partials/theme-toggle.php'; ?>
	</div>
	<section class="rw-maint-hero<?php echo $needs_attention ? ' rw-maint-hero--attention' : ''; ?>" aria-labelledby="rw-maint-dash-title">
		<div>
			<p class="rw-maint-hero-host"><?php echo esc_html( $host ); ?></p>
			<h2 id="rw-maint-dash-title"><?php echo $needs_attention ? 'Needs attention' : 'Your website is protected'; ?></h2>
			<p class="rw-maint-lead"><?php echo $needs_attention ? 'A check on this website needs attention.' : 'We are keeping WordPress, plugins and themes up to date, backing up your site and running regular security checks.'; ?></p>
			<div class="rw-maint-actions">
				<?php if ( ! empty( $rw_view['support_mailto'] ) ) : ?>
					<a class="rw-maint-button" href="<?php echo esc_url( $rw_view['support_mailto'] ); ?>">Request support</a>
				<?php endif; ?>
				<a class="rw-maint-button-secondary" href="#rw-maint-update-reports">View latest report</a>
			</div>
		</div>
		<aside class="rw-maint-plan" aria-label="Plan and support">
			<p class="rw-maint-kicker">Your plan</p>
			<h3><?php echo esc_html( $rw_view['plan_name'] ); ?></h3>
			<?php if ( ! empty( $rw_view['show_included_minutes'] ) && $granted > 0 && $included_for_site ) : ?>
				<p class="rw-maint-plan-label">Support minutes this period<?php echo '' !== $plan_host ? ' for ' . esc_html( $plan_host ) : ''; ?></p>
				<div class="rw-maint-meter" role="img" aria-label="<?php echo esc_attr( $used . ' of ' . $granted . ' minutes used' ); ?>">
					<span style="width: <?php echo esc_attr( (string) $used_pct ); ?>%"></span>
				</div>
				<dl class="rw-maint-minutes-list">
					<div><dt>Used</dt><dd><?php echo esc_html( (string) $used ); ?> min</dd></div>
					<div><dt>Remaining</dt><dd><?php echo esc_html( (string) $remaining ); ?> of <?php echo esc_html( (string) $granted ); ?> min</dd></div>
					<?php if ( ! empty( $rw_view['renews_on'] ) ) : ?>
						<div><dt>Renews on</dt><dd><?php echo esc_html( $rw_view['renews_on'] ); ?></dd></div>
					<?php endif; ?>
				</dl>
			<?php elseif ( ! empty( $rw_view['renews_on'] ) ) : ?>
				<p class="rw-maint-plan-label">Renews on <?php echo esc_html( $rw_view['renews_on'] ); ?></p>
			<?php endif; ?>
			<?php if ( $purchased_for_site > 0 && '' !== $support_host ) : ?>
				<p class="rw-maint-plan-note"><?php echo esc_html( (string) $purchased_for_site ); ?> purchased minutes remain for <?php echo esc_html( $support_host ); ?>, separate from the monthly allowance.</p>
			<?php endif; ?>
			<?php if ( ! $included_for_site && '' !== $plan_host ) : ?>
				<p class="rw-maint-plan-note">Included support on this plan is for <?php echo esc_html( $plan_host ); ?>.</p>
			<?php endif; ?>
			<?php if ( $unassigned_minutes > 0 ) : ?>
				<p class="rw-maint-plan-note"><?php echo esc_html( (string) $unassigned_minutes ); ?> purchased minutes are not tied to a website yet. They stay on the account until we assign them, and they are not available on this site.</p>
			<?php endif; ?>
		</aside>
	</section>

	<?php if ( ! empty( $rw_view['support_offer'] ) && is_array( $rw_view['support_offer'] ) ) : ?>
		<?php
		$support_offer = $rw_view['support_offer'];
		include RW_PORTAL_DIR . 'templates/partials/support-offer.php';
		?>
	<?php endif; ?>

	<section class="rw-maint-tools-band" aria-labelledby="rw-maint-tools-title">
		<h2 id="rw-maint-tools-title">Tools</h2>
		<div class="rw-maint-row rw-maint-row-2 rw-maint-tools-row">
			<?php include RW_PORTAL_DIR . 'templates/partials/connector-download-card.php'; ?>
			<article class="rw-maint-panel rw-maint-panel--paper">
				<p class="rw-maint-kicker">Help</p>
				<h3>Need the connector again?</h3>
				<p class="rw-maint-panel-copy">Use Download site plugin on the left anytime. After installing it in WordPress, your site stays linked to this maintenance plan.</p>
			</article>
		</div>
	</section>

	<?php
	if ( $site ) {
		include RW_PORTAL_DIR . 'templates/partials/status-strip.php';
		include RW_PORTAL_DIR . 'templates/partials/customer-actions.php';
	}
	?>

	<?php if ( ! empty( $rw_view['maintenance'] ) && is_array( $rw_view['maintenance'] ) ) : ?>
		<?php
		$maintenance = $rw_view['maintenance'];
		include RW_PORTAL_DIR . 'templates/partials/maintenance-status.php';
		?>
	<?php endif; ?>

	<?php include RW_PORTAL_DIR . 'templates/partials/maintenance-reports.php'; ?>

	<section class="rw-maint-reports-band" id="rw-maint-reports" aria-labelledby="rw-maint-reports-title">
		<h2 id="rw-maint-reports-title">Reports and support</h2>
		<div class="rw-maint-row rw-maint-row-2">
			<article class="rw-maint-panel rw-maint-panel--paper">
				<h3>Report preferences</h3>
				<p class="rw-maint-panel-copy">Choose where maintenance summaries are sent.</p>
				<?php if ( $site ) : ?>
					<?php RW_Portal_Account::render_report_preferences( $site ); ?>
				<?php endif; ?>
			</article>
			<article class="rw-maint-panel rw-maint-panel--paper">
				<h3>Need a hand?</h3>
				<p class="rw-maint-panel-copy">
					<?php if ( ! empty( $rw_view['show_included_minutes'] ) && $granted > 0 && $included_for_site ) : ?>
						<?php echo esc_html( (string) $remaining ); ?> of <?php echo esc_html( (string) $granted ); ?> included minutes remain<?php echo '' !== $plan_host ? ' for ' . esc_html( $plan_host ) : ' in this period'; ?>.
					<?php else : ?>
						Technical support for this plan is available from your account.
					<?php endif; ?>
					<?php if ( $purchased_for_site > 0 && '' !== $support_host ) : ?>
						<?php echo esc_html( (string) $purchased_for_site ); ?> purchased minutes remain for <?php echo esc_html( $support_host ); ?>.
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $rw_view['support_mailto'] ) ) : ?>
					<a class="rw-maint-button" href="<?php echo esc_url( $rw_view['support_mailto'] ); ?>">Request support</a>
				<?php endif; ?>
				<?php if ( $site ) : ?>
					<div class="rw-maint-disconnect">
						<?php RW_Portal_Account::render_disconnect_form( $site ); ?>
					</div>
				<?php endif; ?>
			</article>
		</div>
	</section>

	<?php if ( ! empty( $rw_view['others'] ) && is_array( $rw_view['others'] ) ) : ?>
		<section class="rw-maint-others" aria-labelledby="rw-maint-others-title">
			<h2 id="rw-maint-others-title">Other subscriptions</h2>
			<ul>
				<?php foreach ( $rw_view['others'] as $other ) : ?>
					<li>
						<a href="<?php echo esc_url( $other['url'] ); ?>">
							<strong><?php echo esc_html( $other['host'] ); ?></strong>
							<span><?php echo esc_html( $other['plan'] ); ?> · <?php echo esc_html( $other['status'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
