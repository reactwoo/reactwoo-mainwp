(function () {
	var root = document.querySelector('[data-support-offer]');
	if (!root) {
		return;
	}
	var select = root.querySelector('.rw-maint-offer-select');
	var price = root.querySelector('[data-offer-price]');
	var dialog = root.querySelector('[data-offer-dialog]');
	var openButton = root.querySelector('[data-offer-open]');
	var key = '';

	function selected() {
		return select ? select.options[select.selectedIndex] : null;
	}

	function sync() {
		var option = selected();
		if (!option) {
			return;
		}
		if (price) {
			price.textContent = option.getAttribute('data-price') || '';
		}
		var name = root.querySelector('[data-offer-name]');
		var modalPrice = root.querySelector('[data-offer-modal-price]');
		if (name) {
			name.textContent = option.getAttribute('data-name') || '';
		}
		if (modalPrice) {
			modalPrice.textContent = option.getAttribute('data-price') || '';
		}
		var links = root.querySelectorAll('[data-offer-checkout]');
		for (var i = 0; i < links.length; i++) {
			links[i].setAttribute('href', option.getAttribute('data-checkout') || '#');
		}
	}

	function freshKey() {
		var alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var value = '';
		for (var i = 0; i < 24; i++) {
			value += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
		}
		return value;
	}

	if (select) {
		select.addEventListener('change', sync);
	}

	if (openButton && dialog) {
		openButton.addEventListener('click', function () {
			sync();
			key = freshKey();
			var result = root.querySelector('[data-offer-result]');
			var fallback = root.querySelector('[data-offer-fallback]');
			var confirmButton = root.querySelector('[data-offer-confirm]');
			if (result) {
				result.hidden = true;
				result.textContent = '';
			}
			if (fallback) {
				fallback.hidden = true;
			}
			if (confirmButton) {
				confirmButton.disabled = false;
			}
			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				dialog.setAttribute('open', 'open');
			}
		});
	}

	var closeButton = root.querySelector('[data-offer-close]');
	if (closeButton && dialog) {
		closeButton.addEventListener('click', function () {
			if (typeof dialog.close === 'function') {
				dialog.close();
			} else {
				dialog.removeAttribute('open');
			}
		});
	}

	var confirmButton = root.querySelector('[data-offer-confirm]');
	if (confirmButton) {
		confirmButton.addEventListener('click', function () {
			var option = selected();
			if (!option || confirmButton.disabled) {
				return;
			}
			confirmButton.disabled = true;
			var body = new FormData();
			body.append('action', 'rw_buy_extra_support');
			body.append('nonce', root.getAttribute('data-nonce') || '');
			body.append('product_slug', option.value);
			body.append('subscription_id', root.getAttribute('data-subscription') || '');
			body.append('site_id', root.getAttribute('data-site') || '');
			body.append('token_id', root.getAttribute('data-token') || '');
			body.append('idempotency_key', key);
			fetch(root.getAttribute('data-ajax') || '', {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			}).then(function (response) {
				return response.json();
			}).then(function (payload) {
				showResult(payload && payload.result === 'success', payload && payload.message ? payload.message : 'The card payment did not complete.');
			}).catch(function () {
				showResult(false, 'The card payment did not complete.');
			});
		});
	}

	function showResult(ok, message) {
		var result = root.querySelector('[data-offer-result]');
		var fallback = root.querySelector('[data-offer-fallback]');
		if (result) {
			result.hidden = false;
			result.textContent = message;
			result.className = 'rw-maint-offer-result ' + (ok ? 'is-success' : 'is-failure');
		}
		if (!ok && fallback) {
			fallback.hidden = false;
		}
	}
}());
