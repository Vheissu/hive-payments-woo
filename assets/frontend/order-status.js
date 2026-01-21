(function () {
	if (typeof window === 'undefined' || !window.hivePaymentsOrderCheck) {
		return;
	}

	var settings = window.hivePaymentsOrderCheck;
	var attempts = 0;
	var maxAttempts = settings.maxAttempts || 20;
	var intervalMs = settings.intervalMs || 15000;
	var statusEl = document.querySelector('[data-hive-order-status]');

	function updateStatus(message) {
		if (!statusEl) {
			return;
		}
		statusEl.innerHTML = '<p>' + message + '</p>';
	}

	function scheduleNext() {
		if (attempts >= maxAttempts) {
			return;
		}
		setTimeout(checkOrder, intervalMs);
	}

	function checkOrder() {
		attempts += 1;
		var params = new URLSearchParams();
		params.append('action', 'hive_payments_check_order');
		params.append('order_id', settings.orderId);
		params.append('order_key', settings.orderKey);
		params.append('nonce', settings.nonce);

		fetch(settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: params.toString(),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					scheduleNext();
					return;
				}

				var status = payload.data.status || '';
				if (status === 'processing' || status === 'completed') {
					updateStatus(settings.paidMessage || 'Payment confirmed.');
					setTimeout(function () {
						window.location.reload();
					}, 4000);
					return;
				}

				scheduleNext();
			})
			.catch(function () {
				scheduleNext();
			});
	}

	checkOrder();
})();
