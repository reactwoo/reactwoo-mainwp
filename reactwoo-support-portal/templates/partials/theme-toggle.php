<?php
/**
 * Maintenance dashboard theme toggle (light / dark / system).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="rw-maint-theme-toggle" role="group" aria-label="Colour theme">
	<button type="button" class="rw-maint-theme-toggle__btn" data-rw-theme="light" aria-pressed="false" title="Light">Light</button>
	<button type="button" class="rw-maint-theme-toggle__btn" data-rw-theme="dark" aria-pressed="false" title="Dark">Dark</button>
	<button type="button" class="rw-maint-theme-toggle__btn" data-rw-theme="system" aria-pressed="false" title="Match system">Auto</button>
</div>
