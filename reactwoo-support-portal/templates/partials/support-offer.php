<?php
/**
 * Buy extra support from the connected dashboard.
 *
 * @var array $support_offer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $support_offer['options'] ) || ! is_array( $support_offer['options'] ) ) {
	return;
}

$offer_options = $support_offer['options'];
$offer_card    = isset( $support_offer['card'] ) && is_array( $support_offer['card'] ) ? $support_offer['card'] : null;
$offer_first   = $offer_options[0];
?>
<section class="rw-maint-support-offer" aria-labelledby="rw-maint-support-offer-title" data-support-offer data-ajax="<?php echo esc_attr( isset( $support_offer['ajax_url'] ) ? (string) $support_offer['ajax_url'] : '' ); ?>" data-nonce="<?php echo esc_attr( isset( $support_offer['nonce'] ) ? (string) $support_offer['nonce'] : '' ); ?>" data-subscription="<?php echo esc_attr( (string) (int) $support_offer['subscription_id'] ); ?>" data-site="<?php echo esc_attr( (string) (int) $support_offer['site_id'] ); ?>" data-token="<?php echo esc_attr( $offer_card ? (string) (int) $offer_card['token_id'] : '' ); ?>">
	<article class="rw-maint-panel">
		<p class="rw-maint-kicker">Extra support</p>
		<h3 id="rw-maint-support-offer-title">Buy extra support</h3>
		<p class="rw-maint-panel-copy">Add support time for this website. The price is the current shop price.</p>
		<label class="rw-maint-offer-label" for="rw-maint-support-pack">Support pack</label>
		<select id="rw-maint-support-pack" class="rw-maint-offer-select">
			<?php foreach ( $offer_options as $offer_option ) : ?>
				<option value="<?php echo esc_attr( $offer_option['slug'] ); ?>" data-name="<?php echo esc_attr( $offer_option['name'] ); ?>" data-price="<?php echo esc_attr( $offer_option['price_text'] ); ?>" data-checkout="<?php echo esc_url( $offer_option['checkout_url'] ); ?>"><?php echo esc_html( $offer_option['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="rw-maint-offer-price" data-offer-price><?php echo function_exists( 'wp_kses_post' ) ? wp_kses_post( $offer_first['price_html'] ) : esc_html( $offer_first['price_text'] ); ?></p>
		<div class="rw-maint-offer-actions">
			<?php if ( $offer_card ) : ?>
				<p class="rw-maint-pill rw-maint-pill--good">Card ••••<?php echo esc_html( $offer_card['last4'] ); ?></p>
				<p class="rw-maint-helper">Your saved card will be selected at checkout. Payment is confirmed there.</p>
			<?php endif; ?>
			<?php if ( $offer_card && ! empty( $support_offer['charge'] ) ) : ?>
				<button type="button" class="rw-maint-button" data-offer-open><?php echo esc_html( $offer_card['label'] ); ?></button>
			<?php else : ?>
				<a class="rw-maint-button" data-offer-checkout href="<?php echo esc_url( $offer_first['checkout_url'] ); ?>">Continue to checkout</a>
			<?php endif; ?>
		</div>
	</article>
	<?php if ( $offer_card && ! empty( $support_offer['charge'] ) ) : ?>
		<dialog class="rw-maint-offer-dialog" data-offer-dialog>
			<h3>Confirm purchase</h3>
			<p data-offer-name><?php echo esc_html( $offer_first['name'] ); ?></p>
			<p data-offer-modal-price><?php echo esc_html( $offer_first['price_text'] ); ?></p>
			<p>Card ending ••••<?php echo esc_html( $offer_card['last4'] ); ?></p>
			<p class="rw-maint-offer-result" data-offer-result hidden></p>
			<div class="rw-maint-offer-dialog-actions">
				<button type="button" class="rw-maint-button" data-offer-confirm>Pay now</button>
				<button type="button" class="rw-maint-button-secondary" data-offer-close>Cancel</button>
			</div>
			<p data-offer-fallback hidden><a data-offer-checkout href="<?php echo esc_url( $offer_first['checkout_url'] ); ?>">Continue to checkout</a></p>
		</dialog>
	<?php endif; ?>
</section>
