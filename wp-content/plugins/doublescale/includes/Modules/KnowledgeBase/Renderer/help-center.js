/*
 * Help center article page: send the "Was this helpful?" vote and count the
 * view. After "No", ask what was missing (optional). Runs after
 * window.doublescaleHelpCenter = { root, nonce, thanks, missing, send, skip }.
 * Plain script, no build step; failures are silent (the page still works).
 */
(function () {
	var cfg = window.doublescaleHelpCenter;
	if (!cfg || !cfg.root || !window.fetch) {
		return;
	}

	var post = function (path, body) {
		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		return window.fetch(cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(body || {}),
			keepalive: true,
		});
	};

	post('/view').catch(function () {});

	var box = document.querySelector('[data-ds-hc-feedback]');
	if (!box) {
		return;
	}

	var thank = function () {
		box.setAttribute('role', 'status');
		box.textContent = cfg.thanks;
	};

	// "What was missing?" after a "No": the vote is already counted; the
	// reason (a quick choice and/or a few words) is extra and may be skipped.
	var askWhy = function () {
		box.textContent = '';
		var form = document.createElement('form');
		form.className = 'ds-hc__reason';
		form.setAttribute('data-ds-hc-reason', '');

		var question = document.createElement('p');
		question.className = 'ds-hc__reason-q';
		question.id = 'ds-hc-reason-q';
		question.textContent = cfg.missing;
		form.appendChild(question);

		var picked = '';
		var chips = document.createElement('div');
		chips.className = 'ds-hc__reason-chips';
		chips.setAttribute('role', 'group');
		chips.setAttribute('aria-labelledby', question.id);
		(cfg.reasons || []).forEach(function (reason) {
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'ds-hc__button';
			chip.setAttribute('aria-pressed', 'false');
			chip.textContent = reason;
			chip.addEventListener('click', function () {
				picked = picked === reason ? '' : reason;
				chips.querySelectorAll('button').forEach(function (b) {
					b.setAttribute('aria-pressed', b === chip && picked ? 'true' : 'false');
				});
			});
			chips.appendChild(chip);
		});
		form.appendChild(chips);

		var label = document.createElement('label');
		label.setAttribute('for', 'ds-hc-reason-text');
		label.textContent = cfg.more || '';
		var text = document.createElement('textarea');
		text.id = 'ds-hc-reason-text';
		text.rows = 3;
		text.maxLength = 900;
		text.dir = 'auto';
		label.appendChild(text);
		form.appendChild(label);

		var actions = document.createElement('div');
		actions.className = 'ds-hc__reason-actions';
		var send = document.createElement('button');
		send.type = 'submit';
		send.className = 'ds-hc__button ds-hc__button--primary';
		send.textContent = cfg.send;
		var skip = document.createElement('button');
		skip.type = 'button';
		skip.className = 'ds-hc__button';
		skip.textContent = cfg.skip;
		actions.appendChild(send);
		actions.appendChild(skip);
		form.appendChild(actions);
		box.appendChild(form);
		var first = chips.querySelector('button');
		(first || text).focus();

		skip.addEventListener('click', thank);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var words = text.value.trim();
			var reason = picked && words ? picked + ': ' + words : picked || words;
			if (!reason) {
				thank();
				return;
			}
			send.disabled = true;
			skip.disabled = true;
			post('/feedback', { helpful: false, comment: reason })
				.catch(function () {})
				.then(thank);
		});
	};

	box.addEventListener('click', function (event) {
		var button = event.target.closest('button[data-helpful]');
		if (!button) {
			return;
		}
		var helpful = '1' === button.getAttribute('data-helpful');
		box.querySelectorAll('button').forEach(function (b) {
			b.disabled = true;
		});
		var sent = post('/feedback', { helpful: helpful }).catch(function () {});
		if (helpful || !cfg.missing) {
			sent.then(thank);
		} else {
			askWhy();
		}
	});
})();
