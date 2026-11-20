(function() {
	var root = document.getElementById('perfmatters-cache-status');
	if(!root || typeof ajaxurl === 'undefined') {
		return;
	}

	var timer = null;

	function applyProgress(data) {
		if(!data) {
			return;
		}

		if(typeof data.pages_cached_label !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-pages]'), function(pages) {
				pages.textContent = data.pages_cached_label;
			});
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-disk-card]'), function(card) {
				var count = typeof data.pages_cached !== 'undefined' ? parseInt(data.pages_cached, 10) : 0;
				card.classList.toggle('has-pages', count > 0);
				card.classList.toggle('is-empty', !(count > 0));
			});
		}

		if(typeof data.pages_meta_parts !== 'undefined' || typeof data.size_label !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-pages-meta]'), function(pagesMeta) {
				var parts = data.pages_meta_parts || {};
				var status = typeof parts.status !== 'undefined' ? parts.status : '';
				var size = typeof data.size_label !== 'undefined' ? data.size_label : '';
				var html = '';
				if(status) {
					html += '<span class="perfmatters-cache-status-meta" data-pages-status></span>';
				}
				if(size) {
					html += '<span class="perfmatters-cache-meta-chip" title="Disk size"><span class="dashicons dashicons-database" aria-hidden="true"></span><span data-pages-size></span></span>';
				}
				pagesMeta.innerHTML = html;
				var statusEl = pagesMeta.querySelector('[data-pages-status]');
				var sizeEl = pagesMeta.querySelector('[data-pages-size]');
				if(statusEl) {
					statusEl.textContent = status;
				}
				if(sizeEl) {
					sizeEl.textContent = size;
				}
			});
		}

		if(typeof data.warmer_meta !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-warmer-meta]'), function(warmerMeta) {
				warmerMeta.textContent = data.warmer_meta;
			});
		}

		if(typeof data.warmer_value !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-warmer-value]'), function(warmerValue) {
				warmerValue.textContent = data.warmer_value;
			});
		}

		Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-warmer-meta-row]'), function(row) {
			row.classList.toggle('is-warming', !!data.running);
			row.classList.toggle('is-complete', !data.running && !!data.warmer_complete);
		});

		Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-warmer-button]'), function(button) {
			var label = button.querySelector('.perfmatters-button-text') || button;
			if(typeof data.warmer_button !== 'undefined') {
				label.textContent = data.warmer_button;
			}
			button.setAttribute('data-pm-action', data.running ? 'cache_warmer_stop' : 'cache_warmer_start');
		});

		if(typeof data.cf_value !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-cf-value]'), function(el) {
				el.textContent = data.cf_value;
			});
		}

		if(typeof data.cf_meta !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-cf-meta]'), function(el) {
				el.textContent = data.cf_meta;
			});
		}

		if(typeof data.cf_class !== 'undefined') {
			Array.prototype.forEach.call(document.querySelectorAll('#perfmatters-admin [data-cf-card]'), function(card) {
				card.classList.remove('is-ready', 'is-error', 'is-setup');
				if(data.cf_class) {
					card.classList.add(data.cf_class);
				}
			});
		}

		root.setAttribute('data-running', data.running ? '1' : '0');
	}

	function pollOnce() {
		var body = new window.FormData();
		body.append('action', 'perfmatters_cache_warmer_status');
		body.append('_ajax_nonce', root.getAttribute('data-nonce'));
		window.fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', body: body}).then(function(response) {
			return response.json();
		}).then(function(payload) {
			if(!payload || !payload.success || !payload.data) {
				return;
			}
			applyProgress(payload.data);
			if(!payload.data.running && timer) {
				window.clearInterval(timer);
				timer = null;
			}
		}).catch(function() {});
	}

	function startPoll() {
		if(timer) {
			return;
		}
		pollOnce();
		timer = window.setInterval(pollOnce, 3000);
	}

	window.perfmattersCacheApplyProgress = applyProgress;
	window.perfmattersCacheWarmerPoll = startPoll;

	if(root.getAttribute('data-running') === '1') {
		startPoll();
	}
})();
