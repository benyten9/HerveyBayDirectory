/**
 * Agent View — the agent's browser commands.
 *
 * The nibwp/visual-* abilities (open, read, click, hover, fill, screenshot,
 * audit, console, viewport, blocks, batch, tabs) queue commands on the visual
 * bus; this long-polls for them, runs them in Agent View's page frame and posts
 * the answers back. The handlers are the classic workspace's, moved here as
 * they were: an agent that drove the old screen drives this one the same way.
 *
 * The pages are Agent View's tabs, the same ones the person sees. "open" shows
 * the page in the tab already holding it or in a new one, "tabs" lists, focuses
 * and closes them, and anything that changes a page still stops for the person
 * watching when the approval gate is on.
 */
(function () {
    'use strict';

    var bridge = window.nibwpStudioBridge;
    var cfg = window.nibwpVisual || {};
    if (!bridge || !cfg.ajax || !window.wp || !window.wp.i18n) {
        return;
    }

    var __ = wp.i18n.__, _n = wp.i18n._n, _x = wp.i18n._x, sprintf = wp.i18n.sprintf;

    // This tab's identity. Opening Agent View takes the bus over from any older
    // tab, so two open copies cannot split the agent's commands between them.
    var session = Math.random().toString(36).slice(2, 12);
    var standDown = false;
    var pollAbort = null;

    // One page per Agent View tab; `page` is the tab on screen. The runner
    // finds each tab's frame by its data-vs-frame, which is the tab id.
    var pages = new Map();
    var page = null;
    var activeId = '';

    function pageFor(tab) {
        var pg = pages.get(tab.id);
        if (!pg) {
            pg = { id: tab.id, url: '', title: '', frame: null, errors: [] };
            pages.set(tab.id, pg);
        }
        if (tab.frame && pg.frame !== tab.frame) {
            pg.frame = tab.frame;
            pg.watched = null;
            pg.frame.addEventListener('load', function () { syncOne(pg); });
        }
        pg.url = pg.url || tab.url;
        pg.title = tab.title || pg.title;
        return pg;
    }

    // Catch up with the person's tabs: a closed tab is forgotten, the one on
    // screen is the active page.
    function syncTabs() {
        var list = bridge.tabs();
        var seen = {};
        list.forEach(function (t) {
            seen[t.id] = true;
            var pg = pageFor(t);
            if (t.active) {
                page = pg;
                activeId = t.id;
            }
        });
        pages.forEach(function (pg, id) {
            if (!seen[id]) { pages.delete(id); }
        });
        return list;
    }

    // admin-ajax, not REST: a REST request boots the MCP adapter and registers
    // every ability before it answers "anything for me?", and this asks that
    // question all day.
    function api(action, fields, signal) {
        var body = new URLSearchParams();
        body.set('action', 'nibwp_visual_' + action);
        body.set('nonce', cfg.nonce);
        Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });

        return fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            signal: signal || undefined
        });
    }

    function log(action, detail, tone) {
        bridge.log(action, detail || '', tone || '');
    }

    // Only this site, judged by host rather than exact origin: an agent builds
    // URLs from whatever it was told, and http vs https or a port present in one
    // and not the other made an identical site look like a different one.
    function sameSite(url) {
        try {
            var target = new URL(url, cfg.home);
            var home = new URL(cfg.home);
            if (target.hostname.toLowerCase() !== home.hostname.toLowerCase()) {
                return null;
            }
            target.protocol = home.protocol;
            target.port = home.port;
            return target.href;
        } catch (e) {
            return null;
        }
    }

    function activePage() {
        return page;
    }

    function doc(pg) {
        try {
            return pg && pg.frame ? pg.frame.contentDocument : null;
        } catch (e) {
            return null;
        }
    }

    function syncOne(pg) {
        var d = doc(pg);
        if (!d || !d.body) { return false; }
        pg.title = d.title || pg.title;
        pg.url = bridge.realUrl(d.location.href);
        pg.watched = null;
        watchErrors(pg);
        return true;
    }

    function syncPage() {
        return syncOne(page);
    }

    syncTabs();

    function focusPage(id) {
        if (id && id !== activeId) {
            bridge.focusTab(id);
            syncTabs();
        }
        return summarise(page);
    }

    // This site only: a snapshot of another site is something the person
    // opens; the agent reads it once it is on screen.
    function openPage(url) {
        var safe = sameSite(url);
        if (!safe) {
            return Promise.reject(new Error('Refused to open "' + url + '" — Agent View only opens pages on ' + cfg.home));
        }
        return bridge.openTab(safe).then(function () {
            syncTabs();
            if (!syncPage()) {
                throw new Error(
                    'This site refused to be displayed in a frame, so Agent View cannot show ' + safe
                    + '. A security plugin is sending X-Frame-Options: DENY or a frame-ancestors rule. '
                    + 'Allow same-origin framing for this site and try again.'
                );
            }
            return summarise(page);
        });
    }

    /* Errors the page throws, re-attached after every navigation: listeners
     * belong to a window, and a new document is a new window. */
    function watchErrors(pg) {
        var win = pg.frame.contentWindow;
        if (!win || pg.watched === win) { return; }
        pg.watched = win;
        win.addEventListener('error', function (e) {
            pg.errors.push({ type: 'error', message: String(e.message), source: String(e.filename || ''), line: e.lineno || 0 });
        });
        win.addEventListener('unhandledrejection', function (e) {
            pg.errors.push({ type: 'rejection', message: String((e.reason && e.reason.message) || e.reason || '') });
        });
    }

    /* ----------------------------------------------------------- reading -- */

    function cssPath(el) {
        if (el.id) { return '#' + CSS.escape(el.id); }
        var parts = [];
        var node = el;
        while (node && node.nodeType === 1 && parts.length < 5) {
            var part = node.tagName.toLowerCase();
            if (node.classList.length) {
                part += '.' + Array.from(node.classList).slice(0, 2).map(function (c) { return CSS.escape(c); }).join('.');
            }
            var parent = node.parentElement;
            if (parent) {
                var same = Array.from(parent.children).filter(function (c) { return c.tagName === node.tagName; });
                if (same.length > 1) { part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')'; }
            }
            parts.unshift(part);
            if (node.id) { parts[0] = '#' + CSS.escape(node.id); break; }
            node = parent;
        }
        return parts.join(' > ');
    }

    function visible(el) {
        var r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) { return false; }
        var s = el.ownerDocument.defaultView.getComputedStyle(el);
        return s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0';
    }

    function summarise(page) {
        var d = doc(page);
        if (!d) {
            return { id: page.id, url: page.url, title: page.title, note: 'The page is on another origin, so it cannot be read.' };
        }
        var shown = bridge.realUrl(d.location.href);
        var out = { id: page.id, url: shown, title: d.title, ready: d.readyState };
        if (shown !== d.location.href && !sameSite(shown)) {
            out.snapshot = 'A copy of another site the person opened, with its scripts off. Read it and point at it; it cannot be changed from here.';
        }
        return out;
    }

    /* Page coordinates rather than viewport ones. A click scrolls its target
       into view first, so viewport boxes from two reads either side of it
       would disagree about where the same element is. */
    function boxOf(el) {
        var r = el.getBoundingClientRect();
        var win = el.ownerDocument.defaultView;
        return {
            x: Math.round(r.left + win.scrollX),
            y: Math.round(r.top + win.scrollY),
            w: Math.round(r.width),
            h: Math.round(r.height)
        };
    }

    /* The handful of computed properties that decide whether something looks
       the way it was meant to. Not the whole computed style: an agent handed
       three hundred properties per element diffs none of them. */
    function styleOf(el) {
        var cs = el.ownerDocument.defaultView.getComputedStyle(el);
        var out = { color: cs.color };

        // A transparent background says nothing about what the element sits
        // on, and "transparent" is what almost every element reports. What
        // the agent needs then is the colour actually showing through.
        var own = readColor(cs.backgroundColor);
        if (own && own.a > 0) {
            out.background = cs.backgroundColor;
        } else {
            out.surface = colorText(surfaceFrom(el.parentElement));
        }

        out['font-size'] = cs.fontSize;
        out['font-weight'] = cs.fontWeight;

        var radius = cs.borderRadius || cs.borderTopLeftRadius;
        if (radius && radius !== '0px') { out['border-radius'] = radius; }

        // Only borders that paint. Every element has four border colours in
        // its computed style, most of them on a zero-width or none border.
        var sides = bordersOf(cs);
        if (sides.length) {
            out.border = { width: sides[0].width, style: sides[0].style, color: sides[0].color };
            if (sides.length < 4) {
                out.border.sides = sides.map(function (s) { return s.side; });
            }
        }

        return out;
    }

    // Styles make each entry several times larger, and a read too big for the
    // agent's context is a read it never sees. The ceiling drops when they are
    // asked for, and the answer says when it was reached.
    var STYLED_CAP = 100;

    function readPage(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }
        var d = doc(page);
        if (!d) { throw new Error('The active page is on another origin and cannot be read.'); }

        var root = payload.selector ? d.querySelector(payload.selector) : d.body;
        if (!root) { throw new Error('Nothing matches the selector ' + payload.selector); }

        var styles = !!payload.styles;
        // max_elements as well, because a batch step carries the ability's own
        // argument names rather than the ones the ability translates them to.
        var cap = Math.max(10, Math.min(payload.maxElements || payload.max_elements || 150, styles ? STYLED_CAP : 400));
        var out = { url: bridge.realUrl(d.location.href), title: d.title, headings: [], elements: [], text: '' };
        var dropped = 0;

        // The region itself is often the thing in question — a card whose
        // border cannot be seen has no interactive element to carry that.
        if (styles && payload.selector) {
            out.region = { selector: payload.selector, box: boxOf(root), style: styleOf(root) };
        }

        root.querySelectorAll('h1, h2, h3, h4').forEach(function (h) {
            if (!visible(h)) { return; }
            if (styles && out.headings.length >= cap) { dropped++; return; }
            var heading = { level: Number(h.tagName[1]), text: h.textContent.trim().slice(0, 160) };
            if (styles) {
                heading.box = boxOf(h);
                heading.style = styleOf(h);
            }
            out.headings.push(heading);
        });

        var selector = 'a[href], button, input, select, textarea, [role="button"], [contenteditable="true"]';
        var seen = 0;
        root.querySelectorAll(selector).forEach(function (el) {
            if (!visible(el)) { return; }
            if (seen >= cap) { dropped++; return; }
            seen++;
            var item = {
                tag: el.tagName.toLowerCase(),
                selector: cssPath(el),
                label: (el.getAttribute('aria-label') || el.value || el.textContent || el.getAttribute('placeholder') || '').trim().slice(0, 120)
            };
            if (el.type) { item.type = el.type; }
            if (el.name) { item.name = el.name; }
            if (el.href) { item.href = el.href; }
            if (el.disabled) { item.disabled = true; }
            if (styles) {
                item.box = boxOf(el);
                item.style = styleOf(el);
            }
            out.elements.push(item);
        });

        out.text = (root.innerText || '').replace(/\s+\n/g, '\n').trim().slice(0, 6000);
        out.truncated = dropped > 0;
        if (out.truncated && styles) {
            out.note = 'Styles were requested, so at most ' + cap + ' headings and ' + cap
                + ' elements are returned. Pass a selector to read one section at a time.';
        }

        return out;
    }

    /* ------------------------------------------------------- interaction -- */

    /** Show the person what is about to be touched before touching it. */
    function spotlight(el) {
        var d = el.ownerDocument;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        var mark = d.createElement('div');
        var r = el.getBoundingClientRect();
        mark.style.cssText = [
            'position:fixed', 'z-index:2147483647', 'pointer-events:none',
            'border:2px solid #00a32a', 'border-radius:4px',
            'box-shadow:0 0 0 4px rgba(0,163,42,.25)',
            'left:' + (r.left - 2) + 'px', 'top:' + (r.top - 2) + 'px',
            'width:' + r.width + 'px', 'height:' + r.height + 'px',
            'transition:opacity .3s ease'
        ].join(';');
        d.body.appendChild(mark);
        setTimeout(function () { mark.style.opacity = '0'; }, 500);
        setTimeout(function () { mark.remove(); }, 900);
    }

    function pick(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }
        var d = doc(page);
        if (!d) { throw new Error('The active page is on another origin.'); }
        var el = d.querySelector(payload.selector);
        if (!el) { throw new Error('Nothing matches the selector ' + payload.selector); }
        return { page: page, doc: d, el: el };
    }

    function clickIt(payload) {
        var found = pick(payload);
        spotlight(found.el);
        var before = found.doc.location.href;
        found.el.click();

        return new Promise(function (resolve) {
            setTimeout(function () {
                var d = doc(found.page);
                resolve({
                    clicked: payload.selector,
                    navigated: d ? d.location.href !== before : true,
                    url: d ? bridge.realUrl(d.location.href) : found.page.url,
                    title: d ? d.title : found.page.title
                });
            }, 700);
        });
    }

    /* Styles worth comparing across a hover. Kept short on purpose: the point
       is to answer "did the hover state change, and to what", not to dump a
       full computed-style object the agent then has to diff itself. */
    var HOVER_PROPS = [
        'color', 'background-color', 'border-color', 'outline-color',
        'text-decoration-line', 'opacity', 'visibility', 'transform', 'box-shadow'
    ];

    function styleSnapshot(win, el) {
        var cs = win.getComputedStyle(el);
        var out = {};
        HOVER_PROPS.forEach(function (prop) { out[prop] = cs.getPropertyValue(prop); });
        return out;
    }

    /* A hover cannot be read from the stylesheet — :hover rules can come from
       anywhere in the cascade, and a menu that only appears on hover is not in
       the DOM's visible set until it does. So dispatch a real pointer sequence
       and read what the page actually became. */
    function hoverIt(payload) {
        var found = pick(payload);
        var el = found.el;
        var win = found.doc.defaultView;
        spotlight(el);

        var before = styleSnapshot(win, el);
        var visibleBefore = found.doc.querySelectorAll('*').length;

        var r = el.getBoundingClientRect();
        var at = {
            bubbles: true,
            cancelable: true,
            clientX: r.left + r.width / 2,
            clientY: r.top + r.height / 2,
            view: win
        };
        // pointerenter/mouseenter do not bubble, so they are dispatched on the
        // element itself rather than relied on to propagate from a parent.
        ['pointerover', 'pointerenter', 'mouseover', 'mouseenter', 'mousemove'].forEach(function (type) {
            var Ctor = type.indexOf('pointer') === 0 && win.PointerEvent ? win.PointerEvent : win.MouseEvent;
            el.dispatchEvent(new Ctor(type, at));
        });

        var settle = typeof payload.settle === 'number' ? Math.min(Math.max(payload.settle, 0), 3000) : 400;

        return new Promise(function (resolve) {
            setTimeout(function () {
                var after = styleSnapshot(win, el);
                var changed = {};
                HOVER_PROPS.forEach(function (prop) {
                    if (before[prop] !== after[prop]) {
                        changed[prop] = { from: before[prop], to: after[prop] };
                    }
                });

                // Something that appears on hover — a submenu, a tooltip — is
                // the other half of what a hover check is looking for, and it
                // shows up as nodes the page did not have a moment ago.
                var appeared = found.doc.querySelectorAll('*').length - visibleBefore;

                if (!payload.hold) {
                    ['mouseout', 'mouseleave', 'pointerout', 'pointerleave'].forEach(function (type) {
                        var Ctor = type.indexOf('pointer') === 0 && win.PointerEvent ? win.PointerEvent : win.MouseEvent;
                        el.dispatchEvent(new Ctor(type, at));
                    });
                }

                resolve({
                    hovered: payload.selector,
                    changed: changed,
                    unchanged: Object.keys(changed).length === 0,
                    before: before,
                    after: after,
                    nodes_added: appeared,
                    held: !!payload.hold,
                    url: found.page.url
                });
            }, settle);
        });
    }

    /* A page cannot photograph itself. Rasterising the DOM in JavaScript looks
       like an answer and is not — it re-renders rather than captures, so canvas,
       WebGL and cross-origin images come out wrong, and a screenshot that is not
       what the browser drew is worse than none for spotting a visual regression.

       So the pixels come from outside the page: the headless runner drives this
       workspace through a real browser and hands it a binding. When that binding
       is absent this is a human-attended tab, and saying so plainly beats
       returning an approximation nobody can trust. */
    function shootIt(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }

        if (typeof window.__nibwpShot !== 'function') {
            throw new Error(
                'Screenshots need the headless runner. This workspace is a browser tab, '
                + 'which cannot capture its own pixels. Start the runner (npx nibwp-runner) '
                + 'and it will serve this workspace instead.'
            );
        }

        return Promise.resolve(window.__nibwpShot({
            frame: page.id,
            selector: payload.selector || '',
            fullPage: !!payload.full_page
        })).then(function (shot) {
            if (!shot || shot.error) {
                throw new Error(shot && shot.error ? shot.error : 'The runner returned no image.');
            }

            return {
                url: shot.url || null,
                media_id: shot.media_id || null,
                width: shot.width || null,
                height: shot.height || null,
                bytes: shot.bytes || null,
                page_url: page.url,
                selector: payload.selector || null,
                full_page: !!payload.full_page
            };
        });
    }

    function fillIt(payload) {
        var found = pick(payload);
        var el = found.el;
        spotlight(el);

        var setter = Object.getOwnPropertyDescriptor(
            el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype
                : el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype
                    : window.HTMLInputElement.prototype,
            'value'
        );
        // Assign through the prototype setter so frameworks watching the field
        // see the change; a plain el.value = x is invisible to React and friends.
        if (setter && setter.set) { setter.set.call(el, payload.value); } else { el.value = payload.value; }

        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));

        if (payload.submit && el.form) {
            el.form.submit();
            return { filled: payload.selector, submitted: true };
        }

        return { filled: payload.selector, value: el.value, submitted: false };
    }

    /* ------------------------------------------------------------ colour -- */

    /* The colour maths touches no DOM, so it can be checked without a browser
       (tests/visual-border-contrast-check.js lifts these by name). Computed
       styles do not come back in one shape: legacy rgba() with commas, rgb()
       with a slash, or color(srgb …) when a token was built with color-mix(). */
    function parseColor(value) {
        var s = String(value || '').trim().toLowerCase();
        if (s === 'transparent') { return { r: 0, g: 0, b: 0, a: 0 }; }

        var hex = s.match(/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/);
        if (hex) {
            var h = hex[1].length < 6
                ? hex[1].split('').map(function (c) { return c + c; }).join('')
                : hex[1];
            return {
                r: parseInt(h.slice(0, 2), 16),
                g: parseInt(h.slice(2, 4), 16),
                b: parseInt(h.slice(4, 6), 16),
                a: h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1
            };
        }

        var fn = s.match(/^(rgba?|color)\(\s*(.*?)\s*\)$/);
        if (!fn) { return null; }

        var body = fn[2];
        // color() is only read in sRGB, where channels run 0-1. Wider spaces
        // need gamut mapping this does not attempt; readColor asks the browser.
        var unit = 1;
        if (fn[1] === 'color') {
            if (body.indexOf('srgb ') !== 0) { return null; }
            body = body.slice(5);
            unit = 255;
        }

        var alpha = '1';
        var halves = body.split('/');
        if (halves.length > 2) { return null; }
        if (halves.length === 2) {
            alpha = halves[1].trim();
            body = halves[0];
        }

        var parts = body.split(/[\s,]+/).filter(Boolean);
        if (parts.length === 4 && halves.length === 1 && fn[1] !== 'color') { alpha = parts.pop(); }
        if (parts.length !== 3) { return null; }

        function channel(raw) {
            var n = raw === 'none' ? 0 : parseFloat(raw);
            n = raw.slice(-1) === '%' ? n * 2.55 : n * unit;
            return Math.min(255, Math.max(0, n));
        }

        var a = alpha === 'none' ? 0 : parseFloat(alpha);
        if (alpha.slice(-1) === '%') { a = a / 100; }

        var color = { r: channel(parts[0]), g: channel(parts[1]), b: channel(parts[2]), a: Math.min(1, Math.max(0, a)) };
        // NaN survives min/max, so one bad channel poisons the sum. Better no
        // answer than a confident ratio computed from garbage.
        return isNaN(color.r + color.g + color.b + color.a) ? null : color;
    }

    /* Source-over, the way the browser paints one colour on another. A border
       at 20% is not the colour its token names: it is mostly whatever lies
       underneath, and that blend is what the eye actually compares. */
    function blendOver(top, bottom) {
        var a = top.a + bottom.a * (1 - top.a);
        if (a <= 0) { return { r: 0, g: 0, b: 0, a: 0 }; }
        function mix(t, b) { return (t * top.a + b * bottom.a * (1 - top.a)) / a; }
        return { r: mix(top.r, bottom.r), g: mix(top.g, bottom.g), b: mix(top.b, bottom.b), a: a };
    }

    /* WCAG relative luminance. Alpha is ignored on purpose: callers blend
       first, because a translucent colour has no luminance of its own. */
    function relativeLuminance(c) {
        var lin = [c.r, c.g, c.b].map(function (v) {
            var x = v / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
    }

    function contrastRatio(x, y) {
        var a = relativeLuminance(x), b = relativeLuminance(y);
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }

    // Below this a border separates nothing. It is not a WCAG number — WCAG's
    // 3:1 for non-text UI would flag every hairline divider on the web — it is
    // the point under which a border stops being visible at all.
    var BORDER_FLOOR = 1.5;

    /* How far a border stands out, as the better of its two edges: against
       the surface outside the element and against the element's own fill.
       Judging the outside alone flagged a border that matched the page but
       plainly framed a dark card; judging the inside alone missed a white
       card on a white page, which is exactly the build that shipped with
       invisible borders. Painted over the fill when the background runs under
       the border (background-clip: border-box, the default), else the surface. */
    function borderContrast(border, fill, surface, underBorder) {
        var inside = blendOver(fill, surface);
        var drawn = blendOver(border, underBorder ? inside : surface);
        return Math.max(contrastRatio(drawn, surface), contrastRatio(drawn, inside));
    }

    var colorCache = {};
    var colorCanvas = null;

    /* parseColor first; anything it cannot read — oklch(), lab(), display-p3 —
       the browser can, so let it paint one pixel and read back what it drew.
       Tokens built in those spaces were otherwise skipped by every check. */
    function readColor(value) {
        var key = String(value || '');
        if (Object.prototype.hasOwnProperty.call(colorCache, key)) { return colorCache[key]; }

        var color = parseColor(key);
        if (!color && key) {
            try {
                if (!colorCanvas) {
                    colorCanvas = document.createElement('canvas');
                    colorCanvas.width = 1;
                    colorCanvas.height = 1;
                }
                var ctx = colorCanvas.getContext('2d');
                // An invalid colour leaves fillStyle untouched, so a sentinel
                // that survives the assignment means the browser refused it.
                ctx.fillStyle = '#010203';
                ctx.fillStyle = key;
                if (ctx.fillStyle !== '#010203') {
                    ctx.clearRect(0, 0, 1, 1);
                    ctx.fillRect(0, 0, 1, 1);
                    var px = ctx.getImageData(0, 0, 1, 1).data;
                    color = { r: px[0], g: px[1], b: px[2], a: px[3] / 255 };
                }
            } catch (e) { color = null; }
        }

        // A page has a few dozen distinct colours; a workspace left open all
        // day visits many pages, so the cache is dropped rather than grown.
        if (Object.keys(colorCache).length > 500) { colorCache = {}; }
        colorCache[key] = color;
        return color;
    }

    function colorText(c) {
        var rgb = Math.round(c.r) + ', ' + Math.round(c.g) + ', ' + Math.round(c.b);
        return c.a < 1 ? 'rgba(' + rgb + ', ' + Math.round(c.a * 100) / 100 + ')' : 'rgb(' + rgb + ')';
    }

    /* The opaque colour showing behind a node: every translucent background up
       to the first solid one, blended in paint order. Taking the nearest
       non-transparent background alone reported a 5% tint as the surface and
       lost the dark section it sat on. Background images are not seen. */
    function surfaceFrom(node) {
        var layers = [];
        while (node && node.nodeType === 1) {
            var c = readColor(node.ownerDocument.defaultView.getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                layers.push(c);
                if (c.a >= 1) { break; }
            }
            node = node.parentElement;
        }
        // Nothing painted at all is the browser's white canvas.
        var surface = { r: 255, g: 255, b: 255, a: 1 };
        for (var i = layers.length - 1; i >= 0; i--) { surface = blendOver(layers[i], surface); }
        return surface;
    }

    var BORDER_SIDES = ['top', 'right', 'bottom', 'left'];

    /* Sides whose border actually paints. Under 1px is left out: it renders as
       a hairline or not at all depending on the screen, and is not a border
       anyone designed to be seen. */
    function bordersOf(cs) {
        var out = [];
        BORDER_SIDES.forEach(function (side) {
            var width = cs.getPropertyValue('border-' + side + '-width');
            var kind = cs.getPropertyValue('border-' + side + '-style');
            if ((parseFloat(width) || 0) >= 1 && kind !== 'none' && kind !== 'hidden') {
                out.push({ side: side, width: width, style: kind, color: cs.getPropertyValue('border-' + side + '-color') });
            }
        });
        return out;
    }

    /* ------------------------------------------------------------ audits -- */

    function contrast(fg, bg) {
        var a = readColor(fg), b = readColor(bg);
        if (!a || !b) { return null; }
        return contrastRatio(a, b);
    }

    function backdrop(el) {
        var node = el;
        while (node && node.nodeType === 1) {
            var bg = node.ownerDocument.defaultView.getComputedStyle(node).backgroundColor;
            if (bg && bg !== 'transparent' && !bg.startsWith('rgba(0, 0, 0, 0')) { return bg; }
            node = node.parentElement;
        }
        return 'rgb(255, 255, 255)';
    }

    function auditPage(payload) {
        var page = activePage();
        if (!page) { throw new Error('No page is open. Use visual-open first.'); }
        var d = doc(page);
        if (!d) { throw new Error('The active page is on another origin.'); }

        var want = (payload.checks && payload.checks.length) ? payload.checks
            : ['contrast', 'alt', 'labels', 'headings', 'overflow', 'borders'];
        var findings = [];
        var win = d.defaultView;

        if (want.indexOf('contrast') !== -1) {
            var texts = d.querySelectorAll('p, span, a, li, td, th, h1, h2, h3, h4, h5, h6, button, label');
            var checked = 0;
            texts.forEach(function (el) {
                if (checked >= 300 || !visible(el) || !el.textContent.trim()) { return; }
                checked++;
                var s = win.getComputedStyle(el);
                var ratio = contrast(s.color, backdrop(el));
                if (ratio === null) { return; }
                var size = parseFloat(s.fontSize);
                var large = size >= 24 || (size >= 18.66 && Number(s.fontWeight) >= 700);
                var floor = large ? 3 : 4.5;
                if (ratio < floor) {
                    findings.push({
                        check: 'contrast',
                        selector: cssPath(el),
                        detail: ratio.toFixed(2) + ':1 against a floor of ' + floor + ':1',
                        text: el.textContent.trim().slice(0, 60)
                    });
                }
            });
        }

        if (want.indexOf('alt') !== -1) {
            d.querySelectorAll('img').forEach(function (img) {
                if (!img.hasAttribute('alt')) {
                    findings.push({ check: 'alt', selector: cssPath(img), detail: 'No alt attribute at all. Use alt="" if it is decorative.', text: img.currentSrc || img.src });
                }
            });
        }

        if (want.indexOf('labels') !== -1) {
            d.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') { return; }
                var labelled = el.getAttribute('aria-label')
                    || el.getAttribute('aria-labelledby')
                    || (el.id && d.querySelector('label[for="' + CSS.escape(el.id) + '"]'))
                    || el.closest('label');
                if (!labelled) {
                    findings.push({ check: 'labels', selector: cssPath(el), detail: 'No label a screen reader can announce.', text: el.name || el.type });
                }
            });
        }

        if (want.indexOf('headings') !== -1) {
            var last = 0;
            d.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(function (h) {
                if (!visible(h)) { return; }
                var level = Number(h.tagName[1]);
                if (last && level > last + 1) {
                    findings.push({ check: 'headings', selector: cssPath(h), detail: 'Jumps from h' + last + ' to h' + level + '.', text: h.textContent.trim().slice(0, 60) });
                }
                last = level;
            });
        }

        if (want.indexOf('overflow') !== -1) {
            var width = d.documentElement.clientWidth;
            d.querySelectorAll('body *').forEach(function (el) {
                if (findings.length > 200 || !visible(el)) { return; }
                var r = el.getBoundingClientRect();
                if (r.width > 0 && r.right > width + 1) {
                    findings.push({ check: 'overflow', selector: cssPath(el), detail: 'Extends ' + Math.round(r.right - width) + 'px past the right edge.', text: (el.textContent || '').trim().slice(0, 40) });
                }
            });
        }

        // A border can be set, sized and styled and still not be there: its
        // token resolved to white at 20% on a white page, and nothing in the
        // markup, the stylesheet or a text read says so. Only the rendered
        // colours, blended the way the browser paints them, show it.
        if (want.indexOf('borders') !== -1) {
            var faint = 0;
            d.querySelectorAll('body *').forEach(function (el) {
                if (faint >= 200) { return; }
                var s = win.getComputedStyle(el);
                var sides = bordersOf(s);
                if (!sides.length || !visible(el)) { return; }

                var surface = surfaceFrom(el.parentElement);
                var fill = readColor(s.backgroundColor) || { r: 0, g: 0, b: 0, a: 0 };
                // The background colour is clipped by the bottom-most layer,
                // which is the last entry in the list.
                var clip = String(s.backgroundClip || 'border-box').split(',').pop().trim();

                for (var i = 0; i < sides.length; i++) {
                    var border = readColor(sides[i].color);
                    if (!border) { continue; }
                    var ratio = borderContrast(border, fill, surface, clip === 'border-box');
                    if (ratio < BORDER_FLOOR) {
                        faint++;
                        findings.push({
                            check: 'borders',
                            selector: cssPath(el),
                            detail: 'Border is effectively invisible against its background: '
                                + ratio.toFixed(2) + ':1 against a floor of ' + BORDER_FLOOR + ':1.',
                            text: (el.textContent || '').trim().slice(0, 40),
                            border: sides[i].color,
                            surface: colorText(surface),
                            ratio: Math.round(ratio * 100) / 100
                        });
                        // One finding per element; four identical sides are
                        // one mistake, not four.
                        break;
                    }
                }
            });
        }

        return {
            url: bridge.realUrl(d.location.href),
            viewport: d.documentElement.clientWidth,
            checked: want,
            issues: findings.slice(0, 100),
            total: findings.length
        };
    }




    /* ------------------------------------------------------------- blocks -- */

    /**
     * The block editor's own data store, from the frame it is running in.
     *
     * Same origin, so this is a property access — no bundle has to be enqueued
     * on every editor screen in the site to let an agent edit blocks, and
     * nothing loads at all for people who never open a workspace.
     */
    function editorIn(page) {
        if (!page || page.blocked) { return null; }
        try {
            var win = page.frame && page.frame.contentWindow;
            var data = win && win.wp && win.wp.data;
            if (data && data.select('core/block-editor') && win.wp.blocks) {
                return { win: win, data: data, blocks: win.wp.blocks, page: page };
            }
        } catch (e) { /* another origin, or not booted yet */ }
        return null;
    }

    /**
     * Find the block editor among the open tabs, preferring the active one.
     *
     * Looking only at the active tab meant a stray tab opened afterwards — or
     * restored from a previous session — silently took the block commands and
     * the agent was told there was no editor, while the editor sat one tab away.
     */
    function editor() {
        if (!pages.size) { throw new Error('No page is open. Use visual-open on a post or page editor first.'); }

        var found = editorIn(activePage());
        if (found) { return found; }

        var other = null;
        pages.forEach(function (page) {
            if (!other) { other = editorIn(page); }
        });

        if (other) {
            // Bring it forward so the person watching sees what is being edited.
            focusPage(other.page.id, true);
            return other;
        }

        throw new Error('None of the open tabs is a block editor. Open a post or page in the editor first.');
    }

    /**
     * The words inside a scrap of block markup, without running any of it.
     *
     * The obvious way — innerHTML on a detached div — still belongs to a live
     * document, so `<img src=x onerror=…>` in a block attribute would fetch and
     * fire while we were only trying to read a preview. DOMParser builds an
     * inert document instead: nothing loads, nothing executes.
     */
    function plainText(html, win) {
        try {
            var doc = new (win.DOMParser || DOMParser)().parseFromString(String(html), 'text/html');
            return doc.body ? (doc.body.textContent || '') : String(html);
        } catch (e) {
            // Older engine, or a document that refused to parse: fall back to
            // dropping tags rather than to executing them.
            return String(html).replace(/<[^>]*>/g, '');
        }
    }

    function blockText(block, win) {
        var bits = [];
        Object.keys(block.attributes || {}).forEach(function (k) {
            var v = block.attributes[k];
            // Rich text is a RichTextData object in current Gutenberg, not a
            // string, so a typeof check alone finds nothing. Which shape it
            // takes depends on how the block was made: created blocks carry the
            // object, blocks whose attributes were set later carry a string.
            if (v && typeof v === 'object' && typeof v.toString === 'function') {
                var asText = String(v);
                if (asText !== '[object Object]') { v = asText; }
            }
            if (typeof v === 'string' && v && bits.join(' ').length < 160) {
                bits.push(plainText(v, win));
            }
        });
        return bits.join(' ').replace(/\s+/g, ' ').trim().slice(0, 160);
    }

    function mapBlocks(list, e, opts, depth, inside) {
        var out = [];
        (list || []).forEach(function (b) {
            // Once a block matches, everything under it is part of the answer.
            // Re-testing children against the filter dropped the contents of
            // the very block that was asked for.
            var matches = inside || !opts.name || b.name.toLowerCase().indexOf(opts.name) !== -1;
            if (!matches) {
                // Keep walking: the match may be nested inside a group.
                out = out.concat(mapBlocks(b.innerBlocks, e, opts, depth + 1, false));
                return;
            }
            var item = { name: b.name, clientId: b.clientId, attributes: b.attributes || {} };
            if (opts.text) { item.text = blockText(b, e.win); }
            if ((b.innerBlocks || []).length) {
                if (depth + 1 < opts.depth) {
                    item.innerBlocks = mapBlocks(b.innerBlocks, e, opts, depth + 1, true);
                } else {
                    item.innerBlocks_count = b.innerBlocks.length;
                }
            }
            out.push(item);
        });
        return out;
    }

    function readBlocks(p) {
        var e = editor();
        var opts = {
            depth: Math.max(1, Math.min(p.depth || 3, 8)),
            text: p.text !== false,
            name: (p.name || '').toLowerCase()
        };
        var top = e.data.select('core/block-editor').getBlocks();
        var ed = e.data.select('core/editor');
        return {
            count: top.length,
            blocks: mapBlocks(top, e, opts, 0, false),
            postTitle: ed ? ed.getEditedPostAttribute('title') : ''
        };
    }

    /** Turn the agent's plain objects into real blocks, children and all. */
    function buildBlock(spec, e) {
        var name = spec.blockName || spec.block_name;
        if (!name) { throw new Error('Every block needs a block_name.'); }
        if (!e.blocks.getBlockType(name)) {
            throw new Error('No block type "' + name + '" is registered here. Use visual-block-schema to see what is.');
        }
        var kids = (spec.innerBlocks || spec.inner_blocks || []).map(function (k) { return buildBlock(k, e); });
        return e.blocks.createBlock(name, spec.attributes || {}, kids);
    }

    /**
     * Let the editor store settle before answering.
     *
     * A dispatch returns before the block tree has re-rendered, so an agent
     * that inserts and immediately reads back saw its own change missing.
     */
    function settled(value) {
        return new Promise(function (resolve) {
            setTimeout(function () { resolve(value); }, 300);
        });
    }

    function insertBlock(p) {
        var e = editor();
        var block = buildBlock(p, e);
        var at = (typeof p.position === 'number' && p.position >= 0) ? p.position : undefined;
        var parent = p.parent || undefined;

        e.data.dispatch('core/block-editor').insertBlock(block, at, parent);

        return settled({
            inserted: block.name,
            clientId: block.clientId,
            parent: parent || null,
            note: 'Inserted in the editor, not saved. Saving is a separate step.'
        });
    }

    function updateBlock(p) {
        var e = editor();
        var existing = e.data.select('core/block-editor').getBlock(p.clientId);
        if (!existing) { throw new Error('No block with clientId ' + p.clientId + ' is on this page.'); }

        e.data.dispatch('core/block-editor').updateBlockAttributes(p.clientId, p.attributes || {});

        return settled({
            updated: existing.name,
            clientId: p.clientId,
            changed: Object.keys(p.attributes || {}),
            note: 'Changed in the editor, not saved.'
        });
    }

    function deleteBlock(p) {
        var e = editor();
        var existing = e.data.select('core/block-editor').getBlock(p.clientId);
        if (!existing) { throw new Error('No block with clientId ' + p.clientId + ' is on this page.'); }

        e.data.dispatch('core/block-editor').removeBlock(p.clientId);

        return settled({ removed: existing.name, clientId: p.clientId, note: 'Removed in the editor, not saved.' });
    }

    function blockSchema(p) {
        var e = editor();
        var types = e.blocks.getBlockTypes();

        if (p.blockName) {
            var t = e.blocks.getBlockType(p.blockName);
            if (!t) { throw new Error('No block type "' + p.blockName + '" is registered here.'); }
            return {
                name: t.name,
                title: t.title,
                category: t.category,
                description: t.description,
                attributes: t.attributes || {},
                supports: t.supports || {},
                parent: t.parent || null
            };
        }

        var q = (p.search || '').toLowerCase();
        var list = types.filter(function (t) {
            return !q || t.name.toLowerCase().indexOf(q) !== -1 || String(t.title).toLowerCase().indexOf(q) !== -1;
        }).map(function (t) { return { name: t.name, title: t.title, category: t.category }; });

        return { total: types.length, matched: list.length, blocks: list.slice(0, 200) };
    }

    /* ---------------------------------------------------------- viewport -- */


    var WIDTHS = { mobile: 390, tablet: 768, laptop: 1280, desktop: 1600, full: 0 };

    function setViewport(payload) {
        var width = payload.width > 0 ? payload.width : WIDTHS[payload.preset];
        if (width === undefined) { width = 0; }
        bridge.setWidth(width);
        return { width: width || 'full' };
    }

    /* --------------------------------------------------------- approvals -- */

    function ask(command, payload) {
        // Nobody is watching a headless run, so a prompt would hang until the
        // command timed out. Decline at once and say why instead.
        if (window.__nibwpHeadless) {
            return Promise.resolve(false);
        }
        return bridge.confirm({
            title: __('Let your agent do this?', 'nibwp'),
            text: describe(command, payload),
            ok: __('Allow', 'nibwp'),
            cancel: __('Refuse', 'nibwp')
        });
    }

    function describe(command, payload) {
        switch (command) {
            case 'click': return payload.selector
                /* translators: %s: CSS selector of the element to click */
                ? sprintf(__('Click %s on this page.', 'nibwp'), payload.selector)
                : __('Click an element on this page.', 'nibwp');
            case 'hover': return payload.selector
                /* translators: %s: CSS selector of the element to hover over */
                ? sprintf(__('Hover over %s and report what changes.', 'nibwp'), payload.selector)
                : __('Hover over an element and report what changes.', 'nibwp');
            case 'screenshot': return payload.selector
                /* translators: %s: CSS selector of the element to capture */
                ? sprintf(__('Take a screenshot of %s.', 'nibwp'), payload.selector)
                : __('Take a screenshot of this page.', 'nibwp');
            case 'fill': {
                var field = payload.selector || __('a field', 'nibwp');
                return payload.submit
                    /* translators: 1: the text to type, 2: CSS selector of the field */
                    ? sprintf(__('Type “%1$s” into %2$s, then submit the form.', 'nibwp'), payload.value || '', field)
                    /* translators: 1: the text to type, 2: CSS selector of the field */
                    : sprintf(__('Type “%1$s” into %2$s.', 'nibwp'), payload.value || '', field);
            }
            case 'batch': {
                var steps = (payload.steps || []).map(function (st) { return describe(st.command, st.payload || {}); });
                /* translators: %d: number of steps */
                return sprintf(_n('%d step in one go:', '%d steps in one go:', steps.length, 'nibwp'), steps.length)
                    + String.fromCharCode(10, 10) + steps.join(String.fromCharCode(10));
            }
            case 'block-insert': return payload.blockName
                /* translators: %s: block name, e.g. core/heading */
                ? sprintf(__('Insert a %s block into the page being edited.', 'nibwp'), payload.blockName)
                : __('Insert a block into the page being edited.', 'nibwp');
            /* translators: 1: comma-separated attribute names, 2: block client id */
            case 'block-update': return sprintf(__('Change %1$s on block %2$s.', 'nibwp'), Object.keys(payload.attributes || {}).join(', '), payload.clientId);
            /* translators: %s: block client id */
            case 'block-delete': return sprintf(__('Delete block %s and everything inside it.', 'nibwp'), payload.clientId);
            case 'open': return payload.url
                /* translators: %s: address of the page to open */
                ? sprintf(__('Open %s in Agent View.', 'nibwp'), payload.url)
                : __('Open a page in Agent View.', 'nibwp');
            case 'read': return __('Read the current page.', 'nibwp');
            case 'audit': return __('Check the current page for accessibility and layout problems.', 'nibwp');
            default: return command + ' ' + JSON.stringify(payload);
        }
    }


    /* ------------------------------------------------------------- pump -- */

    function run(cmd) {
        var p = cmd.payload || {};
        syncTabs();
        switch (cmd.command) {
            case 'open': return openPage(p.url, p.title);
            case 'read': return readPage(p);
            case 'click': return clickIt(p);
            case 'hover': return hoverIt(p);
            case 'screenshot': return shootIt(p);
            case 'fill': return fillIt(p);
            case 'audit': return auditPage(p);
            case 'viewport': return setViewport(p);
            case 'console': return { errors: page.errors.slice(-50), url: page.url || null };
            case 'tabs': {
                // The last tab is never closed: there would be nothing left
                // to work in.
                if (p.focus) { bridge.focusTab(String(p.focus)); }
                if (p.close) { bridge.closeTab(String(p.close)); }
                syncTabs();
                if (p.reload && page && page.frame) {
                    // Builders are swapped, never reloaded in place (Agent View asks first).
                    var f = page.frame;
                    if (bridge.reloadFrame) {
                        bridge.reloadFrame(f);
                    } else {
                        try { f.contentWindow.location.reload(); } catch (e) { /* cross-origin */ }
                    }
                }
                return {
                    tabs: syncTabs().map(function (t) { return { id: t.id, title: t.title, url: t.url, active: t.active, group: t.group || undefined }; }),
                    active: activeId
                };
            }
            case 'follow': return followStudio(p);
            case 'blocks': return readBlocks(p);
            case 'block-insert': return insertBlock(p);
            case 'block-update': return updateBlock(p);
            case 'block-delete': return deleteBlock(p);
            case 'block-schema': return blockSchema(p);
            case 'batch': {
                // Several steps for one round trip, stopping at the first failure:
                // step three usually assumes step two worked.
                var steps = Array.isArray(p.steps) ? p.steps : [];
                var results = [];
                var chain = Promise.resolve();
                steps.forEach(function (step, i) {
                    chain = chain.then(function () {
                        if (results.some(function (r) { return r.error; })) { return; }
                        return Promise.resolve()
                            .then(function () { return run({ command: step.command, payload: step.payload || {} }); })
                            .then(function (data) { results.push({ step: i, command: step.command, data: data }); })
                            .catch(function (e) { results.push({ step: i, command: step.command, error: e.message }); });
                    });
                });
                return chain.then(function () {
                    var failed = results.filter(function (r) { return r.error; }).length;
                    return { ran: results.length, of: steps.length, failed: failed, results: results };
                });
            }
            default: throw new Error('Unknown command: ' + cmd.command);
        }
    }

    /* The agent saved a post. If it is the one on screen, Agent View reloads it
     * by itself once the save reaches the thread; reloading here as well made
     * the page flash twice. Any other post opens, unless the person is in the
     * middle of pointing at the page they are on. */
    function followStudio(p) {
        var url = p.view || p.edit;
        if (!url) { return { followed: false }; }
        var hay = page.url || '';
        if (bridge.showingPost(p.postId) || hay.indexOf('post=' + p.postId) !== -1 || hay === url) {
            return { followed: true, focused: true, url: page.url };
        }
        if (bridge.busy()) {
            return { followed: false, reason: 'The person is pointing at the page on screen, so it was left where it is.' };
        }
        /* translators: %s: title or address of the post being opened */
        log('follow', sprintf(__('opening %s', 'nibwp'), p.title || url), '');
        return openPage(url).then(function (summary) {
            return { followed: true, opened: true, url: summary.url };
        });
    }

    function handle(cmd) {
        // A note is the site telling Agent View what the agent just did
        // somewhere else: no approval, no result.
        if (cmd.command === 'note') {
            var n = cmd.payload || {};
            bridge.note(n.label || 'step', n.detail || '', n.tone || '');
            return Promise.resolve();
        }

        var gate = cmd.needs_approval ? ask(cmd.command, cmd.payload || {}) : Promise.resolve(true);

        return gate.then(function (allowed) {
            if (!allowed) {
                log(cmd.command, __('refused', 'nibwp'), 'bad');
                return {
                    error: window.__nibwpHeadless
                        ? 'This workspace is running headless, so there is nobody to approve "' + cmd.command
                            + '". Run it with Agent View open, or turn the approval gate off if this site is meant to run unattended.'
                        : 'The person watching refused this action.'
                };
            }
            return Promise.resolve()
                .then(function () { return run(cmd); })
                .then(function (data) {
                    log(cmd.command, summaryOf(cmd, data), '');
                    return { data: data };
                })
                .catch(function (e) {
                    log(cmd.command, e.message, 'bad');
                    return { error: e.message };
                });
        }).then(function (result) {
            if (cmd.fireAndForget) { return; }
            return api('result', {
                id: cmd.id,
                data: JSON.stringify(result.data || null),
                error: result.error || ''
            });
        });
    }

    function summaryOf(cmd, data) {
        if (!data) { return ''; }
        if (cmd.command === 'audit') {
            /* translators: %d: number of problems the audit found */
            return sprintf(_n('%d issue', '%d issues', data.total, 'nibwp'), data.total);
        }
        if (cmd.command === 'read') {
            var count = (data.elements || []).length;
            /* translators: %d: number of elements read from the page */
            return sprintf(_n('%d element', '%d elements', count, 'nibwp'), count);
        }
        if (data.url) { return data.url; }
        return '';
    }

    var backoff = 250;

    function pump() {
        pollAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        api('poll', { session: session }, pollAbort ? pollAbort.signal : null)
            .then(function (r) {
                if (!r.ok) { throw new Error('poll ' + r.status); }
                backoff = 250;
                return r.text();
            })
            .then(function (text) {
                var res = bridge.readJson(text);
                if (res && typeof res.approval === 'boolean') { bridge.approval(res.approval); }
                if (res && res.standDown) {
                    standDown = true;
                    bridge.takenOver();
                    return;
                }
                var chain = Promise.resolve();
                (res.commands || []).forEach(function (cmd) {
                    chain = chain.then(function () { return handle(cmd); });
                });
                return chain;
            })
            .catch(function () {
                // Back off rather than hammering a server that just refused us.
                backoff = Math.min(backoff * 2, 15000);
            })
            .then(function () {
                if (!standDown) { setTimeout(pump, backoff); }
            });
    }

    // The approval switch in the menu flips the same option the gate reads.
    bridge.onApproval(function (on) {
        api('state', { approval: on ? '1' : '0' });
    });

    // Claim first, then poll: polling before the claim lands means this tab
    // asks whether it holds a workspace it has not claimed yet, and stands
    // itself down on the answer.
    api('state', { claim: session })
        .catch(function () { /* claim is best effort; the poll still works */ })
        .then(function () { pump(); });
})();
