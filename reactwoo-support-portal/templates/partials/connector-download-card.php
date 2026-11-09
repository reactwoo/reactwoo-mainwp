<?php
/**
 * Connected-dashboard card to re-download the site connector plugin.
 *
 * @var object|null $site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article class="rw-maint-panel rw-maint-panel--paper rw-maint-connector-card">
	<p class="rw-maint-kicker">Site plugin</p>
	<h3>Download site plugin</h3>
	<p class="rw-maint-panel-copy">Install or reinstall the ReactWoo connector on your WordPress site. Maintenance plans include this plugin — it is not listed under Products &amp; licences.</p>
	<?php RW_Portal_Account::render_plugin_download(); ?>
</article>
