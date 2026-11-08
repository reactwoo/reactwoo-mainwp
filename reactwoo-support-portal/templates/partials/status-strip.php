<?php
/**
 * Combined maintenance status strip (light by severity; at most one dark critical).
 *
 * Expects optional $rw_view; pulls flash notice + pending updates.
 *
 * @var array $rw_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = get_current_user_id();
$flash   = class_exists( 'RW_Portal_Customer_Actions' )
	? RW_Portal_Customer_Actions::consume_status_notice( $user_id )
	: null;

$updates_item = null;
if ( ! empty( $rw_view['maintenance'] ) && is_array( $rw_view['maintenance'] ) ) {
	foreach ( isset( $rw_view['maintenance']['cards'] ) ? $rw_view['maintenance']['cards'] : array() as $card ) {
		if ( ! empty( $card['id'] ) && 'updates' === $card['id'] && ! empty( $card['state'] ) && 'attention' === $card['state'] ) {
			$updates_item = $card;
			break;
		}
	}
}

// Prefer flash; otherwise show updates CTA. Never stack two page banners.
$item = null;
if ( $flash ) {
	$item = array(
		'tone'    => $flash['tone'],
		'label'   => $flash['label'],
		'message' => $flash['message'],
		'cta'     => null,
	);
} elseif ( $updates_item ) {
	$summary = ! empty( $updates_item['summary'] ) ? (string) $updates_item['summary'] : 'Plugin updates are waiting — confirm before running.';
	$item    = array(
		'tone'    => 'warning',
		'label'   => ! empty( $updates_item['state_label'] ) ? (string) $updates_item['state_label'] : 'Updates waiting',
		'message' => $summary,
		'cta'     => array(
			'href'  => '#rw-maint-run-updates',
			'label' => 'Review',
		),
	);
}

if ( ! $item && isset( $rw_view ) && is_array( $rw_view ) && ! empty( $rw_view['lifecycle_notice'] ) ) {
	$item = array(
		'tone'    => 'info',
		'label'   => 'Paid through',
		'message' => (string) $rw_view['lifecycle_notice'],
		'cta'     => ! empty( $rw_view['subscriptions_url'] )
			? array(
				'href'  => (string) $rw_view['subscriptions_url'],
				'label' => 'Subscriptions',
			)
			: null,
	);
}

if ( ! $item ) {
	return;
}

$tone = sanitize_key( $item['tone'] );
if ( ! in_array( $tone, array( 'info', 'success', 'warning', 'critical' ), true ) ) {
	$tone = 'info';
}
?>
<div class="rw-maint-status-strip rw-maint-status-strip--<?php echo esc_attr( $tone ); ?>" role="status" data-status-tone="<?php echo esc_attr( $tone ); ?>">
	<div class="rw-maint-status-strip__body">
		<?php if ( ! empty( $item['label'] ) ) : ?>
			<span class="rw-maint-pill"><?php echo esc_html( $item['label'] ); ?></span>
		<?php endif; ?>
		<p class="rw-maint-status-strip__text"><?php echo esc_html( $item['message'] ); ?></p>
	</div>
	<?php if ( ! empty( $item['cta'] ) && is_array( $item['cta'] ) ) : ?>
		<a class="rw-maint-status-strip__cta" href="<?php echo esc_url( $item['cta']['href'] ); ?>"><?php echo esc_html( $item['cta']['label'] ); ?></a>
	<?php endif; ?>
</div>
