<?php
/**
 * Setup needs the customer. Collapsed until the steps are opened.
 *
 * @var array $rw_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site     = $rw_view['site'];
$host     = $site ? wp_parse_url( (string) $site->site_url, PHP_URL_HOST ) : '';
$host     = $host ? $host : 'your website';
$block_id = (int) $rw_view['subscription_id'];
$message  = $rw_view['message'] ? $rw_view['message'] : 'Automated setup did not finish. Connect WordPress again to continue.';
?>
<details class="rw-maint-finish">
	<summary>
		<h2 id="rw-maint-attention-title-<?php echo esc_attr( (string) $block_id ); ?>">Finish connecting <?php echo esc_html( $host ); ?></h2>
	</summary>
	<div class="rw-maint-finish-body">
		<p class="rw-maint-finish-message"><?php echo esc_html( $message ); ?></p>
		<ol class="rw-maint-finish-steps">
			<li>
				<h3>1. Download the site plugin</h3>
				<p>Use the download on this page. In WordPress, open Plugins, choose Add New, then Upload Plugin.</p>
			</li>
			<li>
				<h3>2. Install and activate it</h3>
				<p>Choose the file, install it, and activate it. Leave it in place.</p>
			</li>
			<li>
				<h3>3. Connect WordPress</h3>
				<p>Come back here and approve the connection. No WordPress password is stored.</p>
			</li>
		</ol>
		<?php if ( $rw_view['can_connect'] && $site ) : ?>
			<?php RW_Portal_Account::render_plugin_download(); ?>
			<?php if ( ! empty( $rw_view['replace_connection'] ) ) : ?>
				<?php RW_Portal_Account::render_replace_connection_form( $site ); ?>
			<?php else : ?>
				<?php RW_Portal_Account::render_connect_existing_form( $site ); ?>
				<p class="rw-maint-helper">No WordPress password required.</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $site && ! empty( $rw_view['manual_pending'] ) ) : ?>
			<p class="rw-maint-helper">We already have your request. ReactWoo will finish connecting this website.</p>
		<?php elseif ( $site ) : ?>
			<?php RW_Portal_Account::render_manual_request( $site ); ?>
			<p class="rw-maint-helper">This does not create a permanent administrator, and your WordPress password is not stored.</p>
		<?php endif; ?>
	</div>
</details>
