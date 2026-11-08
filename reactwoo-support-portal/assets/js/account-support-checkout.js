(function () {
	var config = window.rwSupportCheckout || {};
	var gateway = config.gatewayId || '';
	var token = config.tokenId || '';
	if (!gateway || !token) {
		return;
	}

	function select() {
		var method = document.querySelector('input[name="payment_method"][value="' + gateway + '"]');
		if (method) {
			method.checked = true;
			method.dispatchEvent(new Event('click', { bubbles: true }));
		}
		var saved = document.querySelector('input[name="wc-' + gateway + '-payment-token"][value="' + token + '"]');
		if (saved) {
			saved.checked = true;
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', select);
	} else {
		select();
	}
	document.body.addEventListener('updated_checkout', select);
}());
