/*
 * Help center (page and articles): the search panel (⌘K), the chat panel,
 * the "Topics" / "On this page" folds on narrow screens, "Copy link",
 * "On this page" following the reader and the ticket form for visitors with
 * no account. Runs after
 * window.doublescaleHelpUi = { root, nonce, page, topics, ask, ticket, i18n }.
 * Plain script, no build step. Without it every link and form still works.
 */
(function () {
	var cfg = window.doublescaleHelpUi;
	if (!cfg || !document.querySelector('.ds-hc')) {
		return;
	}
	var t = cfg.i18n || {};
	var SVG = 'http://www.w3.org/2000/svg';

	var icon = function (paths, size) {
		var svg = document.createElementNS(SVG, 'svg');
		svg.setAttribute('class', 'ds-hc__icon');
		svg.setAttribute('width', size || 18);
		svg.setAttribute('height', size || 18);
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '1.8');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		paths.forEach(function (d) {
			var p = document.createElementNS(SVG, 'path');
			p.setAttribute('d', d);
			svg.appendChild(p);
		});
		return svg;
	};
	var ICON = {
		search: ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z', 'm20 20-3.5-3.5'],
		doc: ['M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z', 'M14 3v5h5M9 13h6M9 17h4'],
		book: ['M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z', 'M4 19a2 2 0 0 1 2-2h13v4H6a2 2 0 0 1-2-2z'],
		sparkle: ['M12 3l1.8 4.6L18.5 9l-4.7 1.5L12 15l-1.8-4.5L5.5 9l4.7-1.4z'],
		arrow: ['M5 12h14M13 6l6 6-6 6'],
	};
	var el = function (tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) {
			node.className = cls;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	};
	var fmt = function (s, v) {
		return String(s || '').replace('%s', v).replace('%d', v);
	};
	// Text with each searched word in <mark>, built as nodes (never HTML).
	var marked = function (text, q) {
		var frag = document.createDocumentFragment();
		var words = q.split(/\s+/).filter(function (w) {
			return w.length > 1;
		}).map(function (w) {
			return w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		});
		if (!words.length) {
			frag.appendChild(document.createTextNode(text));
			return frag;
		}
		var re = new RegExp('(' + words.join('|') + ')', 'gi');
		String(text).split(re).forEach(function (part, i) {
			if (i % 2) {
				frag.appendChild(el('mark', '', part));
			} else if (part) {
				frag.appendChild(document.createTextNode(part));
			}
		});
		return frag;
	};
	var accent = function () {
		var root = document.querySelector('.ds-hc');
		return root ? window.getComputedStyle(root).getPropertyValue('--ds-hc-accent').trim() : '';
	};

	// ------------------------------------------------------------------ chat
	// Pro's floating chat (window.doublescaleHelpWidget, from its loader):
	// "Chat with our team", "Ask the assistant", "Still stuck?" and the
	// search's "Ask the assistant about …" open it.
	var widget = function () {
		return window.doublescaleHelpWidget && window.doublescaleHelpWidget.open ? window.doublescaleHelpWidget : null;
	};
	var openChat = function (question) {
		if (widget()) {
			widget().open(question || '');
			return;
		}
		// The loader runs later in the page: open once it is there.
		window.addEventListener('doublescale:help-widget-ready', function () {
			if (widget()) {
				widget().open(question || '');
			}
		}, { once: true });
	};
	var chat = true;
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ds-hc-open-chat]');
		if (button) {
			event.preventDefault();
			openChat('');
		}
	});
	if (/[?&#]chat(=1)?\b/.test(window.location.search + window.location.hash)) {
		openChat('');
	}

	// ---------------------------------------------------------- search panel
	var panel = null;
	var input = null;
	var list = null;
	var count = null;
	var opener = null;
	var active = -1;
	var timer = 0;
	var latest = 0;

	var options = function () {
		return list ? Array.prototype.slice.call(list.querySelectorAll('[role="option"]')) : [];
	};
	var select = function (index) {
		var all = options();
		if (!all.length) {
			active = -1;
			input.removeAttribute('aria-activedescendant');
			return;
		}
		active = (index + all.length) % all.length;
		all.forEach(function (o, i) {
			o.setAttribute('aria-selected', i === active ? 'true' : 'false');
		});
		input.setAttribute('aria-activedescendant', all[active].id);
		all[active].scrollIntoView({ block: 'nearest' });
	};
	var go = function (option) {
		if (!option) {
			return;
		}
		if (option.getAttribute('data-ask')) {
			var q = input.value.trim();
			close();
			openChat(q);
			return;
		}
		var href = option.getAttribute('data-href');
		if (href) {
			window.location.href = href;
		}
	};
	var allUrl = function (q) {
		var url = new URL(cfg.page || window.location.href, window.location.href);
		url.searchParams.set('q', q);
		return url.toString();
	};
	var option = function (id, parts) {
		var li = el('li', 'ds-hc__palette-item' + (parts.cls ? ' ' + parts.cls : ''));
		li.id = 'ds-hc-opt-' + id;
		li.setAttribute('role', 'option');
		li.setAttribute('aria-selected', 'false');
		if (parts.href) {
			li.setAttribute('data-href', parts.href);
		}
		if (parts.ask) {
			li.setAttribute('data-ask', '1');
		}
		li.appendChild(parts.icon);
		var body = el('span', 'ds-hc__palette-body');
		var title = el('span', 'ds-hc__palette-title');
		title.appendChild(parts.title);
		body.appendChild(title);
		if (parts.text) {
			var text = el('span', 'ds-hc__palette-text');
			text.appendChild(parts.text);
			body.appendChild(text);
		}
		if (parts.meta) {
			body.appendChild(el('span', 'ds-hc__palette-meta', parts.meta));
		}
		li.appendChild(body);
		li.addEventListener('mousemove', function () {
			var i = options().indexOf(li);
			if (i !== active) {
				select(i);
			}
		});
		li.addEventListener('click', function () {
			go(li);
		});
		return li;
	};
	var group = function (label) {
		var li = el('li', 'ds-hc__palette-group', label);
		li.setAttribute('role', 'presentation');
		return li;
	};
	var text = function (s) {
		return document.createTextNode(s);
	};

	var render = function (q, items) {
		list.textContent = '';
		var n = 0;
		var lower = q.toLowerCase();
		var topics = (cfg.topics || []).filter(function (topic) {
			return !q || String(topic.name).toLowerCase().indexOf(lower) !== -1;
		}).slice(0, q ? 3 : 8);

		if (q && items.length) {
			list.appendChild(group(t.articles));
			items.forEach(function (item) {
				var excerpt = String(item.summary || item.excerpt || '');
				if (excerpt.length > 160) {
					excerpt = excerpt.slice(0, 157).replace(/\s+\S*$/, '') + '…';
				}
				list.appendChild(option(n++, {
					href: item.url,
					icon: icon(ICON.doc),
					title: marked(String(item.title || ''), q),
					text: excerpt ? marked(excerpt, q) : null,
					meta: item.category && item.category.name ? item.category.name : '',
				}));
			});
		}
		if (topics.length) {
			list.appendChild(group(t.topics));
			topics.forEach(function (topic) {
				list.appendChild(option(n++, {
					href: topic.url,
					icon: icon(ICON.book),
					title: q ? marked(topic.name, q) : text(topic.name),
					meta: fmt(t.count, topic.count),
				}));
			});
		}
		if (q && !items.length) {
			list.appendChild(el('li', 'ds-hc__palette-empty', t.none));
		}
		if (q && cfg.ask && chat) {
			var badge = el('span', 'ds-hc__help-icon');
			badge.appendChild(icon(ICON.sparkle, 17));
			list.appendChild(option(n++, {
				cls: 'ds-hc__palette-ask',
				ask: true,
				icon: badge,
				title: text(fmt(t.ask, q)),
				text: text(t.askText),
			}));
		}
		if (q && items.length) {
			list.appendChild(option(n++, {
				href: allUrl(q),
				icon: icon(ICON.arrow),
				title: text(fmt(t.all, q)),
			}));
		}
		count.textContent = q ? fmt(t.found, items.length) : '';
		select(0);
	};

	var search = function () {
		var q = input.value.trim();
		window.clearTimeout(timer);
		if (q.length < 2) {
			render(q.length ? q : '', []);
			return;
		}
		timer = window.setTimeout(function () {
			var mine = ++latest;
			var url = cfg.root + (cfg.root.indexOf('?') === -1 ? '?' : '&') + 'search=' + encodeURIComponent(q) + '&per_page=6';
			var headers = cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {};
			window.fetch(url, { credentials: 'same-origin', headers: headers })
				.then(function (r) {
					return r.ok ? r.json() : { data: [] };
				})
				.catch(function () {
					return { data: [] };
				})
				.then(function (body) {
					if (mine === latest && panel && !panel.hidden) {
						render(q, Array.isArray(body) ? body : body.data || []);
					}
				});
		}, 180);
	};

	var build = function () {
		panel = el('div', 'ds-hc-palette');
		panel.hidden = true;
		var color = accent();
		if (color) {
			panel.style.setProperty('--ds-hc-accent', color);
		}
		var box = el('div', 'ds-hc__palette-box');
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');
		box.setAttribute('aria-label', t.label);

		var form = el('form', 'ds-hc__search');
		form.setAttribute('role', 'search');
		form.appendChild(icon(ICON.search, 20));
		input = el('input');
		input.type = 'search';
		input.setAttribute('aria-label', t.label);
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'true');
		input.setAttribute('aria-controls', 'ds-hc-palette-list');
		input.setAttribute('autocomplete', 'off');
		form.appendChild(input);
		var esc = el('kbd', 'ds-hc__kbd', 'Esc');
		esc.setAttribute('aria-hidden', 'true');
		form.appendChild(esc);

		list = el('ul', 'ds-hc__palette-list');
		list.id = 'ds-hc-palette-list';
		list.setAttribute('role', 'listbox');
		list.setAttribute('aria-label', t.label);

		var foot = el('div', 'ds-hc__palette-foot');
		var hint = function (keys, label) {
			var span = el('span');
			keys.forEach(function (k) {
				span.appendChild(el('kbd', '', k));
				span.appendChild(text(' '));
			});
			span.appendChild(text(label));
			return span;
		};
		foot.appendChild(hint(['↑', '↓'], t.move));
		foot.appendChild(hint(['Enter'], t.open));
		count = el('span', 'ds-hc__palette-count');
		count.setAttribute('role', 'status');
		foot.appendChild(count);

		box.appendChild(form);
		box.appendChild(list);
		box.appendChild(foot);
		panel.appendChild(box);
		document.body.appendChild(panel);

		input.addEventListener('input', search);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var all = options();
			if (active >= 0 && all[active]) {
				go(all[active]);
			} else if (input.value.trim()) {
				window.location.href = allUrl(input.value.trim());
			}
		});
		input.addEventListener('keydown', function (event) {
			if ('ArrowDown' === event.key) {
				event.preventDefault();
				select(active + 1);
			} else if ('ArrowUp' === event.key) {
				event.preventDefault();
				select(active - 1);
			} else if ('Escape' === event.key) {
				event.preventDefault();
				close();
			} else if ('Tab' === event.key) {
				// Keep the keyboard in the panel: arrows move, Enter opens.
				event.preventDefault();
				select(active + (event.shiftKey ? -1 : 1));
			}
		});
		panel.addEventListener('mousedown', function (event) {
			if (event.target === panel) {
				close();
			}
		});
	};

	var open = function (value, from) {
		if (!window.fetch) {
			return false;
		}
		if (!panel) {
			build();
		}
		opener = from || document.activeElement;
		panel.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		input.value = value || '';
		input.focus();
		search();
		return true;
	};
	var close = function () {
		if (!panel || panel.hidden) {
			return;
		}
		panel.hidden = true;
		document.documentElement.style.overflow = '';
		if (opener && opener.blur) {
			opener.blur();
		}
	};

	document.querySelectorAll('[data-ds-hc-search]').forEach(function (box) {
		var start = function (event) {
			if (open(box.value, box)) {
				event.preventDefault();
				box.blur();
			}
		};
		box.addEventListener('focus', start);
		box.addEventListener('mousedown', start);
	});
	document.addEventListener('keydown', function (event) {
		var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement && document.activeElement.tagName) ||
			(document.activeElement && document.activeElement.isContentEditable);
		if ((event.metaKey || event.ctrlKey) && 'k' === String(event.key).toLowerCase()) {
			if (panel && !panel.hidden) {
				close();
			} else {
				open('');
			}
			event.preventDefault();
		} else if ('/' === event.key && !typing && (!panel || panel.hidden)) {
			event.preventDefault();
			open('');
		}
	});

	// ------------------------------------------------------------------ folds
	// "Topics" and "On this page" are open beside the article on wide pages,
	// folded into a button on narrow ones (the reader can still open them).
	var folds = Array.prototype.slice.call(document.querySelectorAll('details[data-ds-hc-fold]'));
	var fit = function () {
		folds.forEach(function (d) {
			var root = d.closest('.ds-hc');
			var wide = root ? root.clientWidth : window.innerWidth;
			var needed = 'toc' === d.getAttribute('data-ds-hc-fold') ? 1140 : 820;
			var shouldOpen = wide >= needed;
			if (d.getAttribute('data-ds-hc-wide') !== String(shouldOpen)) {
				d.setAttribute('data-ds-hc-wide', String(shouldOpen));
				d.open = shouldOpen;
			}
		});
	};
	if (folds.length) {
		fit();
		if (window.ResizeObserver) {
			new window.ResizeObserver(fit).observe(document.querySelector('.ds-hc'));
		} else {
			window.addEventListener('resize', fit);
		}
		// On wide pages the side panels stay open.
		folds.forEach(function (d) {
			d.addEventListener('toggle', function () {
				if ('true' === d.getAttribute('data-ds-hc-wide') && !d.open) {
					d.open = true;
				}
			});
		});
	}

	// -------------------------------------------------------------- copy link
	document.querySelectorAll('[data-ds-hc-copy]').forEach(function (button) {
		var label = button.querySelector('span');
		var original = label ? label.textContent : '';
		button.addEventListener('click', function () {
			var url = button.getAttribute('data-ds-hc-copy') || window.location.href;
			var done = function () {
				if (label) {
					label.textContent = t.copied;
					window.setTimeout(function () {
						label.textContent = original;
					}, 2000);
				}
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(done, function () {});
			} else {
				window.prompt('', url);
			}
		});
	});

	// ------------------------------------------------- "On this page" follows
	var links = Array.prototype.slice.call(document.querySelectorAll('.ds-hc__toc-nav a[href^="#"]'));
	if (links.length && window.IntersectionObserver) {
		var targets = links.map(function (a) {
			try {
				return document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1)));
			} catch (e) {
				return null;
			}
		});
		var mark = function () {
			var current = 0;
			targets.forEach(function (h, i) {
				if (h && h.getBoundingClientRect().top < window.innerHeight * 0.3) {
					current = i;
				}
			});
			links.forEach(function (a, i) {
				a.classList.toggle('is-current', i === current);
				if (i === current) {
					a.setAttribute('aria-current', 'location');
				} else {
					a.removeAttribute('aria-current');
				}
			});
		};
		var observer = new window.IntersectionObserver(mark, { rootMargin: '0px 0px -60% 0px' });
		targets.forEach(function (h) {
			if (h) {
				observer.observe(h);
			}
		});
		window.addEventListener('scroll', function () {
			window.requestAnimationFrame(mark);
		}, { passive: true });
		mark();
	}

	// ------------------------------------------------------------ ticket form
	// Visitors with no account open a ticket here (Helpdesk → Settings → Help
	// center tickets); cfg.ticket is false otherwise and the option is a link.
	var tk = cfg.ticket;
	if (tk) {
		var tt = tk.i18n || {};
		var tPanel = null;
		var tOpener = null;
		// When the form opened: the server drops forms sent faster than anyone types.
		var tOpenedAt = 0;

		var field = function (name, label, type, required) {
			var wrap = el('label', 'ds-hc__ticket-field');
			wrap.appendChild(el('span', 'ds-hc__ticket-label', label));
			var input = el(type === 'textarea' ? 'textarea' : 'input');
			if (type !== 'textarea') {
				input.type = type;
			} else {
				input.rows = 5;
			}
			input.name = name;
			if (required) {
				input.required = true;
			}
			if (name === 'email') {
				input.autocomplete = 'email';
			}
			if (name === 'name') {
				input.autocomplete = 'name';
			}
			wrap.appendChild(input);
			return wrap;
		};

		var closeTicket = function () {
			if (!tPanel || tPanel.hidden) {
				return;
			}
			tPanel.hidden = true;
			document.documentElement.classList.remove('ds-hc-locked');
			if (tOpener) {
				tOpener.focus();
			}
		};

		var done = function (box, heading, text, url) {
			box.textContent = '';
			var head = el('div', 'ds-hc__ticket-done');
			head.appendChild(icon(['M20 6 9 17l-5-5'], 22));
			box.appendChild(head);
			box.appendChild(el('h2', 'ds-hc__ticket-title', heading));
			box.appendChild(el('p', 'ds-hc__ticket-intro', text));
			var actions = el('div', 'ds-hc__ticket-actions');
			if (url) {
				var view = el('a', 'ds-hc__button ds-hc__button--primary', tt.view);
				view.href = url;
				actions.appendChild(view);
			}
			var ok = el('button', 'ds-hc__button', tt.close);
			ok.type = 'button';
			ok.addEventListener('click', closeTicket);
			actions.appendChild(ok);
			box.appendChild(actions);
			ok.focus();
		};

		var buildTicket = function (notice) {
			tPanel = el('div', 'ds-hc-palette ds-hc-ticket');
			tPanel.hidden = true;
			var color = accent();
			if (color) {
				tPanel.style.setProperty('--ds-hc-accent', color);
			}
			var box = el('div', 'ds-hc__ticket-box');
			box.setAttribute('role', 'dialog');
			box.setAttribute('aria-modal', 'true');
			box.setAttribute('aria-labelledby', 'ds-hc-ticket-title');

			var x = el('button', 'ds-hc__ticket-x');
			x.type = 'button';
			x.setAttribute('aria-label', tt.close);
			x.appendChild(icon(['M18 6 6 18', 'M6 6l12 12'], 18));
			x.addEventListener('click', closeTicket);
			box.appendChild(x);

			var heading = el('h2', 'ds-hc__ticket-title', tt.title);
			heading.id = 'ds-hc-ticket-title';
			box.appendChild(heading);
			box.appendChild(el('p', 'ds-hc__ticket-intro', tt.intro));

			var form = el('form', 'ds-hc__ticket-form');
			form.noValidate = false;
			var error = el('p', 'ds-hc__ticket-error');
			error.setAttribute('role', 'alert');
			error.hidden = !notice;
			error.textContent = notice || '';
			form.appendChild(error);
			var row = el('div', 'ds-hc__ticket-row');
			row.appendChild(field('name', tt.name, 'text', false));
			row.appendChild(field('email', tt.email, 'email', true));
			form.appendChild(row);
			form.appendChild(field('title', tt.subject, 'text', true));
			form.appendChild(field('content', tt.message, 'textarea', true));
			// People never see this field; bots fill it in.
			var trap = el('label', 'ds-hc__ticket-trap');
			trap.setAttribute('aria-hidden', 'true');
			trap.appendChild(el('span', '', tt.trap));
			var trapInput = el('input');
			trapInput.type = 'text';
			trapInput.name = tk.trap;
			trapInput.tabIndex = -1;
			trapInput.autocomplete = 'off';
			trap.appendChild(trapInput);
			form.appendChild(trap);

			var actions = el('div', 'ds-hc__ticket-actions');
			var cancel = el('button', 'ds-hc__button', tt.cancel);
			cancel.type = 'button';
			cancel.addEventListener('click', closeTicket);
			var send = el('button', 'ds-hc__button ds-hc__button--primary', tt.send);
			send.type = 'submit';
			actions.appendChild(cancel);
			actions.appendChild(send);
			form.appendChild(actions);
			box.appendChild(form);

			form.addEventListener('submit', function (event) {
				event.preventDefault();
				var data = {};
				Array.prototype.forEach.call(form.elements, function (input) {
					if (input.name) {
						data[input.name] = input.value;
					}
				});
				data[tk.elapsed] = Date.now() - tOpenedAt;
				send.disabled = true;
				send.textContent = tt.sending;
				error.hidden = true;
				window
					.fetch(tk.url, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						credentials: 'same-origin',
						body: JSON.stringify(data),
					})
					.then(function (r) {
						return r.json().then(function (body) {
							return { ok: r.ok, body: body };
						});
					})
					.then(function (res) {
						if (!res.ok) {
							throw new Error((res.body && res.body.message) || tt.error);
						}
						// The ticket's link only ever goes by email, to the address's owner.
						if (res.body.status === 'created') {
							done(box, tt.sent, fmt(tt.sentText, res.body.email), '');
						} else {
							done(box, tt.check, fmt(tt.checkText, res.body.email), '');
						}
					})
					.catch(function (e) {
						error.textContent = (e && e.message) || tt.error;
						error.hidden = false;
						send.disabled = false;
						send.textContent = tt.send;
					});
			});

			tPanel.appendChild(box);
			tPanel.addEventListener('mousedown', function (event) {
				if (event.target === tPanel) {
					closeTicket();
				}
			});
			tPanel.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					closeTicket();
				}
			});
			document.body.appendChild(tPanel);
		};

		var openTicket = function (opener, notice) {
			tOpener = opener || null;
			if (!tPanel || notice) {
				if (tPanel) {
					tPanel.remove();
				}
				buildTicket(notice);
			}
			tPanel.hidden = false;
			tOpenedAt = Date.now();
			document.documentElement.classList.add('ds-hc-locked');
			var first = tPanel.querySelector('input[name="name"]');
			if (first) {
				first.focus();
			}
		};

		document.addEventListener('click', function (event) {
			var button = event.target.closest('[data-ds-hc-open-ticket]');
			if (button) {
				event.preventDefault();
				openTicket(button);
			}
		});
		if (tk.expired) {
			openTicket(null, tt.expiredMsg);
		}
	}
})();
