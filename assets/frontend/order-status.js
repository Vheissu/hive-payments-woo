(function () {
	var settings = window.hivePaymentsOrderCheck || null;
	var attempts = 0;
	var maxAttempts = settings && settings.maxAttempts ? settings.maxAttempts : 20;
	var intervalMs = settings && settings.intervalMs ? settings.intervalMs : 15000;
	var statusEl = document.querySelector('[data-hive-order-status]');
	var messageEl = statusEl ? statusEl.querySelector('[data-hive-order-status-message]') : null;
	var countdownEls = Array.prototype.slice.call(document.querySelectorAll('[data-hive-countdown]'));
	var pollingStopped = false;

	function setStatusClass(className) {
		if (!statusEl) {
			return;
		}

		statusEl.classList.remove('is-pending', 'is-paid', 'is-expired', 'is-neutral');
		statusEl.classList.add(className);
	}

	function updateStatus(message) {
		if (!messageEl) {
			return;
		}

		messageEl.textContent = message;
	}

	function formatDuration(seconds) {
		var remaining = Math.max(0, parseInt(seconds, 10) || 0);
		var hours = Math.floor(remaining / 3600);
		var minutes = Math.floor((remaining % 3600) / 60);
		var secs = remaining % 60;
		var parts = [];

		if (hours > 0) {
			parts.push(hours + 'h');
		}
		if (minutes > 0 || hours > 0) {
			parts.push(minutes + 'm');
		}
		parts.push(secs + 's');

		return parts.join(' ');
	}

	function updateCountdowns() {
		if (!countdownEls.length) {
			return;
		}

		var now = Math.floor(Date.now() / 1000);
		countdownEls.forEach(function (countdownEl) {
			var expiresAt = parseInt(countdownEl.getAttribute('data-hive-countdown'), 10) || 0;
			if (!expiresAt) {
				countdownEl.textContent = '';
				return;
			}

			var remaining = Math.max(0, expiresAt - now);
			if (remaining <= 0) {
				countdownEl.textContent = settings && settings.expiredMessage
					? settings.expiredMessage
					: 'The payment window has expired.';
				return;
			}

			countdownEl.textContent = 'Time remaining: ' + formatDuration(remaining);
		});
	}

	function copyText(value) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(value);
		}

		return new Promise(function (resolve, reject) {
			try {
				var field = document.createElement('textarea');
				field.value = value;
				field.setAttribute('readonly', 'readonly');
				field.style.position = 'absolute';
				field.style.left = '-9999px';
				document.body.appendChild(field);
				field.select();
				document.execCommand('copy');
				document.body.removeChild(field);
				resolve();
			} catch (error) {
				reject(error);
			}
		});
	}

	function flashButton(button, label) {
		var original = button.getAttribute('data-hive-copy-label') || button.textContent;
		button.setAttribute('data-hive-copy-label', original);
		button.textContent = label;

		window.setTimeout(function () {
			button.textContent = original;
		}, 1800);
	}

	function initCopyButtons() {
		var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-hive-copy]'));
		if (!buttons.length) {
			return;
		}

		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var value = button.getAttribute('data-hive-copy') || '';
				if (!value) {
					return;
				}

				copyText(value)
					.then(function () {
						flashButton(button, 'Copied');
					})
					.catch(function () {
						flashButton(button, 'Copy failed');
					});
			});
		});
	}

	function markExpired() {
		pollingStopped = true;
		setStatusClass('is-expired');
		updateStatus(
			settings && settings.expiredMessage
				? settings.expiredMessage
				: 'The payment window has expired.'
		);
		countdownEls.forEach(function (countdownEl) {
			countdownEl.textContent = settings && settings.expiredMessage
				? settings.expiredMessage
				: 'The payment window has expired.';
		});
	}

	function markPaid() {
		pollingStopped = true;
		setStatusClass('is-paid');
		updateStatus(
			settings && settings.paidMessage
				? settings.paidMessage
				: 'Payment confirmed.'
		);
		countdownEls.forEach(function (countdownEl) {
			countdownEl.textContent = '';
		});

		window.setTimeout(function () {
			window.location.reload();
		}, 4000);
	}

	function scheduleNext() {
		if (!settings || !settings.shouldPoll || pollingStopped || attempts >= maxAttempts) {
			return;
		}

		window.setTimeout(checkOrder, intervalMs);
	}

	function checkOrder() {
		if (!settings || !settings.shouldPoll || pollingStopped) {
			return;
		}

		if (settings.expiresAt && settings.expiresAt <= Math.floor(Date.now() / 1000)) {
			markExpired();
			return;
		}

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
				var result = payload.data.result || {};
				if (status === 'processing' || status === 'completed') {
					markPaid();
					return;
				}

				if (status === 'cancelled' || result.status === 'expired' || payload.data.expiredAt) {
					markExpired();
					return;
				}

				updateStatus(
					settings && settings.pendingMessage
						? settings.pendingMessage
						: 'Waiting for the exact Hive payment.'
				);
				scheduleNext();
			})
			.catch(function () {
				scheduleNext();
			});
	}

	initCopyButtons();
	updateCountdowns();
	if (countdownEls.length) {
		window.setInterval(updateCountdowns, 1000);
	}

	if (settings && settings.shouldPoll) {
		checkOrder();
	}
})();
