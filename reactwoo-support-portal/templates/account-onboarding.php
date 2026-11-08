<?php
/**
 * Setup required. Approved onboarding frames.
 *
 * @var array $rw_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plan_name  = $rw_view['plan_name'];
$site       = $rw_view['site'];
$host       = $site ? wp_parse_url( (string) $site->site_url, PHP_URL_HOST ) : '';
$host       = $host ? $host : 'your website';
$block_id   = (int) $rw_view['subscription_id'];
$plan_short = trim( (string) preg_replace( '/\s+maintenance$/i', '', (string) $plan_name ) );
$granted    = (int) $rw_view['included_minutes_granted'];
$hour_phrase = 60 === $granted ? 'one hour' : $granted . ' minutes';
$support_id = 'rw-maint-report-value-' . $block_id;
?>
<div class="rw-maint-onboarding">
	<section class="rw-maint-hero" aria-labelledby="rw-maint-hero-title-<?php echo esc_attr( (string) $block_id ); ?>">
		<div class="rw-maint-hero-copy">
			<p class="rw-maint-pill"><?php echo esc_html( $plan_name ); ?></p>
			<h2 id="rw-maint-hero-title-<?php echo esc_attr( (string) $block_id ); ?>">Your website,<br class="rw-maint-copy-desktop" /> looked after.</h2>
			<p class="rw-maint-lead">
				<span class="rw-maint-copy-desktop">Connect the website to see reported updates, backups and checks. A result stays unavailable until it is recorded.</span>
				<span class="rw-maint-copy-mobile">Reported updates, backups and checks appear after they are recorded.</span>
			</p>
			<ul class="rw-maint-checks">
				<li><span aria-hidden="true">✓</span> Secure WordPress connection, approved by you</li>
				<li><span aria-hidden="true">✓</span> Reported status in this account, including when a result is not available yet</li>
			</ul>
			<?php if ( $rw_view['can_connect'] && ! $site ) : ?>
				<?php RW_Portal_Account::render_connect_form( $rw_view['subscription_id'], false ); ?>
			<?php elseif ( $rw_view['can_connect'] && $site ) : ?>
				<?php RW_Portal_Account::render_connect_existing_form( $site ); ?>
			<?php else : ?>
				<p class="rw-maint-note"><?php echo esc_html( $rw_view['message'] ); ?></p>
			<?php endif; ?>
			<p class="rw-maint-helper">
				<span class="rw-maint-copy-desktop">Download the site plugin, install and activate it, then connect. No WordPress password is stored.</span>
				<span class="rw-maint-copy-mobile">No WordPress password required · Secure setup</span>
			</p>
		</div>
		<aside class="rw-maint-plan" aria-label="Your plan">
			<div class="rw-maint-plan-card">
				<p class="rw-maint-kicker">Your plan</p>
				<h3><?php echo esc_html( $plan_name ); ?></h3>
				<p class="rw-maint-plan-site">Website: <?php echo esc_html( $host ); ?></p>
				<ul class="rw-maint-plan-points">
					<?php foreach ( $rw_view['plan_points'] as $point ) : ?>
						<li>
							<span class="rw-maint-tick" aria-hidden="true">✓</span>
							<span>
								<strong>
									<?php if ( ! empty( $point['mobile_title'] ) ) : ?>
										<span class="rw-maint-copy-desktop"><?php echo esc_html( $point['title'] ); ?></span>
										<span class="rw-maint-copy-mobile"><?php echo esc_html( $point['mobile_title'] ); ?></span>
									<?php else : ?>
										<?php echo esc_html( $point['title'] ); ?>
									<?php endif; ?>
								</strong>
								<span class="rw-maint-point-text"><?php echo esc_html( $point['text'] ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</aside>
	</section>

	<section class="rw-maint-band" aria-labelledby="rw-maint-includes-title-<?php echo esc_attr( (string) $block_id ); ?>">
		<p class="rw-maint-kicker">
			<span class="rw-maint-copy-desktop">What your plan includes</span>
			<span class="rw-maint-copy-mobile">What your plan does</span>
		</p>
		<h2 id="rw-maint-includes-title-<?php echo esc_attr( (string) $block_id ); ?>">
			<span class="rw-maint-copy-desktop">Your website maintenance, covered.</span>
			<span class="rw-maint-copy-mobile">Everything working behind the scenes.</span>
		</h2>
		<p class="rw-maint-intro rw-maint-copy-desktop">What this account can show for your plan. A service stays unavailable until a result is reported.</p>
		<div class="rw-maint-grid">
			<?php foreach ( $rw_view['benefits'] as $benefit ) : ?>
				<?php
				$tone = isset( $benefit['tone'] ) ? (string) $benefit['tone'] : 'blue';
				$card_class = 'rw-maint-card';
				if ( ! empty( $benefit['desktop_only'] ) ) {
					$card_class .= ' rw-maint-card--desktop';
				}
				$kicker_class = 'rw-maint-kicker';
				if ( 'green' === $tone ) {
					$kicker_class .= ' rw-maint-kicker--green';
				} elseif ( 'purple' === $tone ) {
					$kicker_class .= ' rw-maint-kicker--purple';
				}
				?>
				<article class="<?php echo esc_attr( $card_class ); ?>">
					<p class="<?php echo esc_attr( $kicker_class ); ?>"><?php echo esc_html( $benefit['kicker'] ); ?></p>
					<h3>
						<?php if ( ! empty( $benefit['mobile_title'] ) ) : ?>
							<span class="rw-maint-copy-desktop"><?php echo esc_html( $benefit['title'] ); ?></span>
							<span class="rw-maint-copy-mobile"><?php echo esc_html( $benefit['mobile_title'] ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $benefit['title'] ); ?>
						<?php endif; ?>
					</h3>
					<p>
						<?php if ( ! empty( $benefit['mobile_text'] ) ) : ?>
							<span class="rw-maint-copy-desktop"><?php echo esc_html( $benefit['text'] ); ?></span>
							<span class="rw-maint-copy-mobile"><?php echo esc_html( $benefit['mobile_text'] ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $benefit['text'] ); ?>
						<?php endif; ?>
					</p>
				</article>
			<?php endforeach; ?>
			<?php if ( ! empty( $rw_view['show_included_minutes'] ) ) : ?>
				<article class="rw-maint-card rw-maint-card--mobile">
					<p class="rw-maint-kicker rw-maint-kicker--purple">Support</p>
					<h3>Technical support</h3>
					<p><?php echo esc_html( (string) $granted ); ?> minutes included each service period<?php echo 'Standard' === $plan_short ? ' on Standard' : ''; ?>.</p>
				</article>
			<?php endif; ?>
		</div>
	</section>

	<section class="rw-maint-split" aria-labelledby="rw-maint-report-title-<?php echo esc_attr( (string) $block_id ); ?>">
		<div>
			<p class="rw-maint-kicker">More value from maintenance</p>
			<h2 id="rw-maint-report-title-<?php echo esc_attr( (string) $block_id ); ?>">See what has been reported.</h2>
			<p class="rw-maint-intro">A report lists updates, backups and checks only when those results are recorded.</p>
			<a class="rw-maint-report-link" href="#<?php echo esc_attr( $support_id ); ?>">View your reports →</a>
		</div>
		<aside class="rw-maint-aside" id="<?php echo esc_attr( $support_id ); ?>" aria-label="Reports in your plan">
			<p class="rw-maint-kicker">Reports in your plan</p>
			<ul class="rw-maint-plan-points">
				<li>
					<span class="rw-maint-tick" aria-hidden="true">✓</span>
					<span>
						<strong>Included plugins</strong>
						<span class="rw-maint-point-text">Subject to eligible catalogue</span>
					</span>
				</li>
				<li>
					<span class="rw-maint-tick" aria-hidden="true">✓</span>
					<span>
						<strong>Single entitlement source</strong>
						<span class="rw-maint-point-text">Reports from ReactWoo</span>
					</span>
				</li>
			</ul>
			<p class="rw-maint-aside-note">Products shown here depend on your active plan and may change.</p>
		</aside>
	</section>

	<section class="rw-maint-band rw-maint-band--steps" aria-labelledby="rw-maint-steps-title-<?php echo esc_attr( (string) $block_id ); ?>">
		<p class="rw-maint-kicker rw-maint-copy-desktop">Getting started</p>
		<h2 id="rw-maint-steps-title-<?php echo esc_attr( (string) $block_id ); ?>">Install the plugin. Then connect.</h2>
		<p class="rw-maint-intro rw-maint-copy-desktop">Download the site plugin, install it on your website, then come back and connect.</p>
		<ol class="rw-maint-steps">
			<li>
				<p class="rw-maint-kicker">Step 01</p>
				<h3>1. Download the site plugin</h3>
				<p>Use the download on this page. In WordPress, open Plugins, choose Add New, then Upload Plugin.</p>
			</li>
			<li>
				<p class="rw-maint-kicker">Step 02</p>
				<h3>2. Install and activate it</h3>
				<p>Choose the file, install it, and activate it. Leave it in place.</p>
			</li>
			<li>
				<p class="rw-maint-kicker">Step 03</p>
				<h3>3. Connect WordPress</h3>
				<p>Come back here and approve the connection. No WordPress password is stored.</p>
			</li>
		</ol>
	</section>

	<section class="rw-maint-support" aria-labelledby="rw-maint-support-title-<?php echo esc_attr( (string) $block_id ); ?>">
		<div class="rw-maint-allowance">
			<?php if ( ! empty( $rw_view['show_included_minutes'] ) ) : ?>
				<p class="rw-maint-pill"><?php echo esc_html( $plan_short ); ?> plan</p>
				<p class="rw-maint-minutes"><?php echo esc_html( (string) $granted ); ?> minutes</p>
				<p>of technical support per service period</p>
				<p class="rw-maint-allowance-note">For small fixes, troubleshooting and WordPress help.</p>
				<?php if ( null !== $rw_view['included_minutes_remaining'] ) : ?>
					<p class="rw-maint-allowance-note"><?php echo esc_html( (string) (int) $rw_view['included_minutes_remaining'] ); ?> minutes remain in this service period.</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="rw-maint-pill"><?php echo esc_html( $plan_short ); ?> plan</p>
				<p>This plan does not include technical support minutes.</p>
			<?php endif; ?>
			<?php if ( isset( $rw_view['purchased_minutes_remaining'] ) && (int) $rw_view['purchased_minutes_remaining'] > 0 ) : ?>
				<p class="rw-maint-allowance-note"><?php echo esc_html( (string) (int) $rw_view['purchased_minutes_remaining'] ); ?> purchased minutes remain.</p>
			<?php endif; ?>
		</div>
		<div class="rw-maint-support-copy">
			<h2 id="rw-maint-support-title-<?php echo esc_attr( (string) $block_id ); ?>">Real help when your site needs it.</h2>
			<?php if ( ! empty( $rw_view['show_included_minutes'] ) ) : ?>
				<p>Your <?php echo esc_html( $plan_short ); ?> plan includes <?php echo esc_html( $hour_phrase ); ?> of hands-on technical support each service period.</p>
			<?php else : ?>
				<p>Hands-on technical support is available when a plan includes it.</p>
			<?php endif; ?>
			<p class="rw-maint-reassure">If a request needs additional billable work, we'll confirm this with you first.</p>
		</div>
	</section>

	<?php if ( $rw_view['can_connect'] && ! $site ) : ?>
		<section class="rw-maint-final" aria-labelledby="rw-maint-final-title-<?php echo esc_attr( (string) $block_id ); ?>">
			<h2 id="rw-maint-final-title-<?php echo esc_attr( (string) $block_id ); ?>">
				<span class="rw-maint-copy-desktop">Ready to look after <?php echo esc_html( $host ); ?>?</span>
				<span class="rw-maint-copy-mobile">Ready to connect?</span>
			</h2>
			<p>
				<span class="rw-maint-copy-desktop">Download the site plugin, install and activate it, then connect your WordPress website.</span>
				<span class="rw-maint-copy-mobile">Approve ReactWoo from WordPress and we'll take it from there.</span>
			</p>
			<?php RW_Portal_Account::render_connect_form( $rw_view['subscription_id'], true ); ?>
			<?php RW_Portal_Account::render_plugin_download(); ?>
			<p class="rw-maint-intro">You control access and can disconnect ReactWoo when needed.</p>
		</section>
	<?php endif; ?>
</div>
