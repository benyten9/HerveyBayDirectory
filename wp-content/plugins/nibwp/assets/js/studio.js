/**
 * NibWP Agent View.
 *
 * The person browses their site in the frame, pins notes on what they see and
 * asks for changes in the composer. Each message is queued on the site; the
 * person's own AI agent picks it up through nibwp/studio-listen and answers
 * with nibwp/studio-reply while they watch. This file is the browser half: it
 * never changes the site, it only queues, reads back and shows.
 */
(function () {
    'use strict';

    var boot = window.nibwpStudio;
    if (!boot) {
        return;
    }

    var i18n = (window.wp && window.wp.i18n) || {
        __: function (s) { return s; },
        _n: function (s, p, n) { return n === 1 ? s : p; },
        sprintf: function (s) { var a = arguments, i = 1; return s.replace(/%(\d+\$)?[sd]/g, function () { return a[i++]; }); }
    };
    var __ = i18n.__, _n = i18n._n, sprintf = i18n.sprintf;
    // Shortcuts are written "Ctrl+Shift+M"; on a Mac they read ⌘⇧M.
    var MAC = /Mac|iPhone|iPad/.test(navigator.platform || '');
    window.nibwpKeyLabel = function (s) { return MAC ? String(s).replace(/Ctrl\+/g, '⌘').replace(/Shift\+/g, '⇧').replace(/Alt\+/g, '⌥') : String(s); };

    var $ = function (id) { return document.getElementById(id); };
    var root = $('nst');
    var feed = $('nst-feed');
    // Folded requests (see foldNode): all folded or not, and the ones flipped.
    var foldAll = store('nibwp-studio-fold') === '1';
    var flips = new Set();
    var curTurn = '';
    var input = $('nst-input');
    var frame = $('nst-frame');
    var artboard = $('nst-artboard');

    var homeUrl = new URL(boot.home);

    /* ------------------------------------------------------------------
     * Small helpers.
     * ---------------------------------------------------------------- */

    function icon(name, size) {
        size = size || 16;
        return '<svg class="nst-i' + (/^(back|forward|chevron|enter|undo)$/.test(name) ? ' nst-dir' : '') + '" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (boot.icons[name] || '') + '</svg>';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html != null) { n.innerHTML = html; }
        return n;
    }

    function clip(s, max) {
        s = String(s || '').replace(/\s+/g, ' ').trim();
        return s.length > max ? s.slice(0, max - 1) + '…' : s;
    }

    function uid() {
        return Math.random().toString(36).slice(2, 10);
    }

    function store(key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            window.localStorage.setItem(key, value);
        } catch (e) { /* private mode */ }
        return null;
    }

    function api(path, opts) {
        opts = opts || {};
        var init = {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': boot.nonce }
        };
        if (opts.body !== undefined) {
            if (opts.body instanceof FormData) {
                init.body = opts.body;
            } else {
                init.headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(opts.body);
            }
        }
        var url = /^https?:/.test(path) ? path : boot.rest + path;
        return fetch(url, init).then(function (res) {
            return res.text().then(readJson).then(function (data) {
                if (!res.ok) {
                    var err = new Error((data && data.message) || sprintf(__('The site answered with error %s.', 'nibwp'), res.status));
                    err.status = res.status;
                    err.code = data && data.code;
                    throw err;
                }
                return data;
            });
        });
    }

    // A site with display_errors on prints PHP notices from any plugin ahead of
    // the JSON in every REST response. Read past them rather than treat a
    // working answer as an empty one.
    function readJson(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            var at = text.search(/[{[]\s*["{[\]}]/);
            for (var tries = 0; at > -1 && tries < 40; tries++) {
                try {
                    return JSON.parse(text.slice(at));
                } catch (e2) {
                    var next = text.slice(at + 1).search(/[{[]\s*["{[\]}]/);
                    at = next > -1 ? at + 1 + next : -1;
                }
            }
            return {};
        }
    }

    var toastTimer = 0;
    function toast(msg, tone) {
        var t = $('nst-toast');
        t.textContent = msg;
        t.dataset.tone = tone || '';
        showToast(t, true);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { showToast(t, false); }, 3800);
    }

    // The toast is a popover, so it lives in the top layer: an open dialog and
    // its blurred backdrop are in the top layer too, and anything outside it
    // shows underneath them. Hiding and showing again puts it on top of a
    // dialog opened after it. Browsers without popovers fall back to hidden.
    function showToast(t, on) {
        if (typeof t.showPopover === 'function') {
            try {
                if (t.matches(':popover-open')) { t.hidePopover(); }
                if (on) { t.showPopover(); }
                return;
            } catch (e) { /* fall back below */ }
        }
        t.hidden = !on;
    }

    // Tells Niby, the helper (studio-buddy.js), what just happened. It decides
    // whether that is worth a word; nothing here waits on it.
    function buddy(type, data) {
        document.dispatchEvent(new CustomEvent('nibwp-studio', { detail: { type: type, data: data || {} } }));
    }

    function failed(err) {
        buddy('error');
        if (err && (err.code === 'rest_cookie_invalid_nonce' || err.status === 401)) {
            toast(__('Your session has expired. Reload Agent View to carry on.', 'nibwp'), 'bad');
            return;
        }
        toast((err && err.message) || __('Something went wrong.', 'nibwp'), 'bad');
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = el('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            ta.remove();
            if (ok) { resolve(); } else { reject(new Error(__('Copying is blocked in this browser. Select the text and copy it instead.', 'nibwp'))); }
        });
    }

    function isTyping(e) {
        var t = e.target;
        return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
    }

    // This site, judged by host name: a link on http or with a port the site
    // does not use still points here, so it is rebuilt on the site's own
    // scheme and port instead of being sent to a new tab.
    function sameSite(url) {
        try {
            var u = new URL(url, boot.home);
            if (u.hostname.toLowerCase() !== homeUrl.hostname.toLowerCase()) { return null; }
            u.protocol = homeUrl.protocol;
            u.port = homeUrl.port;
            return u;
        } catch (e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------
     * Markdown, the small subset agents actually write. Everything is
     * escaped first, so nothing in a reply can become markup it did not
     * earn.
     * ---------------------------------------------------------------- */

    function inline(s) {
        return s
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
            .replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g, function (m, label, href) {
                var local = sameSite(href.replace(/&amp;/g, '&'));
                return local
                    ? '<a href="' + href + '" data-nav="' + href + '">' + label + '</a>'
                    : '<a href="' + href + '" target="_blank" rel="noopener">' + label + '</a>';
            })
            .replace(/(^|\s)(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, function (m, lead, href) {
                var local = sameSite(href.replace(/&amp;/g, '&'));
                return lead + (local
                    ? '<a href="' + href + '" data-nav="' + href + '">' + href + '</a>'
                    : '<a href="' + href + '" target="_blank" rel="noopener">' + href + '</a>');
            });
    }

    function md(text) {
        var src = esc(String(text || '').replace(/\r\n/g, '\n'));
        var blocks = [];
        src = src.replace(/```[a-z0-9_-]*\n?([\s\S]*?)```/gi, function (m, code) {
            blocks.push('<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
            return '\u0000' + (blocks.length - 1) + '\u0000';
        });

        var out = [];
        var list = null;
        src.split(/\n/).forEach(function (line) {
            var t = line.trim();
            var bullet = t.match(/^[-*•]\s+(.*)$/);
            var numbered = t.match(/^\d+[.)]\s+(.*)$/);
            if (bullet || numbered) {
                var kind = bullet ? 'ul' : 'ol';
                if (!list || list.kind !== kind) {
                    if (list) { out.push('</' + list.kind + '>'); }
                    list = { kind: kind };
                    out.push('<' + kind + '>');
                }
                out.push('<li>' + inline((bullet || numbered)[1]) + '</li>');
                return;
            }
            if (list) { out.push('</' + list.kind + '>'); list = null; }
            if (t === '') { out.push('\n'); return; }
            var h = t.match(/^#{1,4}\s+(.*)$/);
            if (h) { out.push('<p class="nst-md-h">' + inline(h[1]) + '</p>'); return; }
            var block = t.match(/^\u0000(\d+)\u0000$/);
            if (block) { out.push(blocks[+block[1]]); return; }
            out.push('<p>' + inline(t).replace(/\u0000(\d+)\u0000/g, function (m, i) { return blocks[+i]; }) + '</p>');
        });
        if (list) { out.push('</' + list.kind + '>'); }

        return out.join('').replace(/<\/p>\n?<p>/g, '</p><p>').replace(/\n/g, '');
    }

    /* ------------------------------------------------------------------
     * State.
     * ---------------------------------------------------------------- */

    var state = {
        lastId: 0,
        items: {},
        pending: {},
        presence: boot.presence || { state: 'none', seen: 0 },
        thread: boot.thread,
        threadName: boot.threadName || '',
        loaded: false,
        page: { url: boot.home, title: '' },
        device: 'desktop',
        proofing: false
    };

    var draft = {
        usePage: true,
        annotations: [],
        attachments: [],
        skills: [],
        workflows: [],
        pages: []
    };

    // Notes already sent, read from the thread itself, so their pins stay on
    // the page as a record of what was asked, after a reload too.
    function sentNotes() {
        var out = [];
        Object.keys(state.items).forEach(function (id) {
            var it = state.items[id];
            if (it.kind !== 'prompt' || it.state === 'cancelled') { return; }
            ((it.data || {}).annotations || []).forEach(function (a) {
                if (!a.hidden) { out.push(Object.assign({ msg: it.id, state: it.state }, a)); }
            });
        });
        return out;
    }

    var showAllNotes = store('nibwp-studio-allnotes') !== '0';

    // Numbers run on through the thread: the first note after a sent one is
    // 2, not another 1.
    function noteNo(i) {
        var top = 0;
        sentNotes().forEach(function (a) { top = Math.max(top, a.n || 0); });
        return top + i + 1;
    }

    /* ------------------------------------------------------------------
     * Theme.
     * ---------------------------------------------------------------- */

    function theme() {
        var forced = document.documentElement.getAttribute('data-theme');
        if (forced) { return forced; }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    $('nst-theme').addEventListener('click', function () {
        var next = theme() === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        store('nibwp-studio-theme', next);
    });

    /* ------------------------------------------------------------------
     * Presence: whether an agent is there to read what is sent.
     * ---------------------------------------------------------------- */

    var presenceText = {
        listening: __('Agent listening', 'nibwp'),
        working: __('Agent working', 'nibwp'),
        idle: __('Agent idle', 'nibwp'),
        none: __('No agent listening', 'nibwp')
    };

    function setPresence(p) {
        if (!p) { return; }
        buddy('presence', { state: p.state });
        var was = state.presence.state;
        state.presence = p;
        var pill = $('nst-presence');
        pill.dataset.state = p.state;
        $('nst-presence-text').textContent = p.state === 'none' && !boot.connected
            ? __('No agent connected', 'nibwp')
            /* translators: %d: how many agents are listening */
            : p.agents > 1 ? sprintf(_n('%d agent listening', '%d agents listening', p.agents, 'nibwp'), p.agents) : (presenceText[p.state] || presenceText.none);
        pill.title = p.seen ? sprintf(__('Last heard from %s', 'nibwp'), new Date(p.seen * 1000).toLocaleTimeString()) : '';
        renderConnectState();
        if (was !== p.state && (p.state === 'listening' || p.state === 'working') && (was === 'none' || was === 'idle') && state.loaded) {
            toast(__('Your agent is listening.', 'nibwp'), 'ok');
            var dlg = $('nst-connect');
            if (dlg.open) { dlg.close(); }
        }
        refreshStaleBadges();
        renderWelcome();
        keepPresence(was, p);
    }

    function agentHere() {
        return state.presence.state === 'listening' || state.presence.state === 'working';
    }

    function renderConnectState() {
        var s = state.presence.state;
        var line = $('nst-connect-state');
        line.dataset.state = s;
        if (s === 'listening' || s === 'working') {
            line.textContent = __('Your agent is here and listening. Anything you send goes straight to it.', 'nibwp');
        } else if (s === 'idle') {
            line.textContent = __('Your agent was here a moment ago but is not listening now. Paste the message below to bring it back.', 'nibwp');
        } else if (boot.connected) {
            line.textContent = __('An AI client is connected to this site, but it is not listening to Agent View yet.', 'nibwp');
        } else {
            line.textContent = __('No AI client is connected to this site yet.', 'nibwp');
        }
    }

    $('nst-presence').addEventListener('click', function () { $('nst-connect').showModal(); });

    /* ------------------------------------------------------------------
     * Keeping the agent. An agent listens by calling back every few seconds,
     * and each quiet call costs it a turn, so Agent View checks in after a
     * quiet stretch ("Still Nibbing?") and lets it rest when nobody answers:
     * a tab left open does not keep an agent spending. An agent that stops
     * on its own gets a line with the wake-up message, and more than one
     * agent listening at once is fine, with a note saying so.
     * ---------------------------------------------------------------- */

    var QUIET_MS = 10 * 60000;
    var NO_ANSWER_MS = 5 * 60000;
    // For trying it out: &nibwp_quick_checkin=1 on the address checks in after 30 seconds.
    if (/[?&]nibwp_quick_checkin=1\b/.test(location.search)) { QUIET_MS = 30000; NO_ANSWER_MS = 30000; }
    // When the agent last had something to do: a request came in, it picked
    // one up, worked, answered or asked. What the person does in the page does
    // not count; idle is about the agent.
    var lastWork = Date.now();
    var workKnown = false;
    var keepAfter = 0;
    var restAsked = 0;
    var nudge = null;
    var multiNote = null;
    var agentsNoted = 1;

    function agentWorked(at) {
        lastWork = Math.max(lastWork, at || Date.now());
        if (nudge && nudge.kind === 'still') { dropNudge(); }
    }
    // When the person last used Agent View (the page frames too): while they
    // are at it, an unanswered check-in waits instead of resting the agent.
    var personAt = Date.now();
    var personPolled = 0;
    function seenPerson() { personAt = Date.now(); }
    function watchPerson(doc) {
        ['pointerdown', 'pointermove', 'keydown', 'wheel'].forEach(function (t) { doc.addEventListener(t, seenPerson, { capture: true, passive: true }); });
    }
    watchPerson(document);
    // Answers to Niby and Kind Guy are the agent working too.
    document.addEventListener('nibwp-buddy-work', function () { agentWorked(); });
    // When the agent went quiet. A short gap is it busy between two calls;
    // only a long one means it stopped.
    var awaySince = 0;
    var DOZE_MS = 2 * 60000;

    // Busy right now: a request of the person's is running or waiting for it.
    function agentHasWork() {
        return state.presence.state === 'working' || Object.keys(state.pending).some(function (id) {
            var it = state.items[id];
            return it && it.kind === 'prompt' && (it.state === 'claimed' || it.state === 'queued');
        });
    }

    function dropNudge() {
        if (nudge) { nudge.node.remove(); nudge = null; }
    }

    function nudgeCard(kind, title, text, actions) {
        var n = el('article', 'nst-msg nst-nudge is-' + kind);
        n.innerHTML = '<div class="nst-nudge__body"><strong>' + esc(title) + '</strong><p>' + esc(text) + '</p></div>' +
            '<div class="nst-nudge__acts">' + actions.map(function (a, i) {
                return '<button type="button" class="nst-btn' + (i ? ' nst-btn--ghost' : '') + '" data-nudge="' + a[1] + '">' + esc(a[0]) + '</button>';
            }).join('') + '</div>';
        feed.appendChild(n);
        scrollFeed(true);
        return n;
    }

    function showNudge(kind, title, text, actions) {
        dropNudge();
        nudge = { kind: kind, at: Date.now(), node: nudgeCard(kind, title, text, actions) };
        if (document.hidden) { notifyTitle(); }
    }

    var WAKE = [__('Copy the wake-up line', 'nibwp'), 'wake'];

    // Asked by the person: shown at once, the agent gets the word at its next
    // check-in. Unanswered: the account decides (another window may have kept
    // it on, or the person is busy there), and the card shows only if it rests.
    function restAgent(auto) {
        if (nudge && nudge.kind === 'rest') { return; }
        restAsked = Date.now();
        var show = function () {
            showNudge('rest',
                __('Your agent is resting 😴', 'nibwp'),
                (auto ? __('No answer, so I let it rest to save its energy (and your tokens). ', 'nibwp') : '') +
                    __('When you need it again, paste the wake-up line in your agent. Anything you send meanwhile waits here.', 'nibwp'),
                [WAKE]);
        };
        if (!auto) { show(); }
        api('stop', { method: 'POST', body: auto ? { reason: 'rest', auto: 1, quiet: Math.round(QUIET_MS / 1000) } : { reason: 'rest' } }).then(function (r) {
            if (!auto) { return; }
            if (r && r.kept) {
                restAsked = 0;
                if (nudge && nudge.kind === 'still') { dropNudge(); }
                keepAfter = Math.max(keepAfter, Date.now() + NO_ANSWER_MS);
                return;
            }
            show();
        }, failed);
    }

    // Arrivals and departures, as presence reports them.
    function keepPresence(was, p) {
        var here = p.state === 'listening' || p.state === 'working';
        var wasHere = was === 'listening' || was === 'working';
        // The account's clock, the same in every window and across a reload:
        // when the agent last worked (it arriving counts), and "Keep listening"
        // clicked in any window.
        var skew = p.now ? Date.now() - p.now * 1000 : 0;
        if (p.work) {
            lastWork = workKnown ? Math.max(lastWork, p.work * 1000 + skew) : p.work * 1000 + skew;
            workKnown = true;
        }
        if (p.keep) { keepAfter = Math.max(keepAfter, p.keep * 1000 + skew); }
        if (nudge && nudge.kind === 'still' && (Date.now() < keepAfter || Date.now() - lastWork < QUIET_MS)) { dropNudge(); }
        if (here) {
            // Back after a rest or a nap: the card that said so goes.
            awaySince = 0;
            if (!wasHere) {
                if (state.loaded) { agentWorked(); }
                if (nudge && nudge.kind !== 'still') { dropNudge(); }
            }
        } else if (wasHere && state.loaded && Date.now() - restAsked > 3 * 60000) {
            awaySince = Date.now();
        }
        var agents = here ? (p.agents || 1) : 0;
        if (agents > 1 && agents > agentsNoted && state.loaded) {
            if (multiNote) { multiNote.remove(); }
            multiNote = nudgeCard('multi',
                /* translators: %d: how many agents are listening */
                sprintf(_n('%d agent is listening', '%d agents are listening', agents, 'nibwp'), agents),
                __('That works: each request goes to whichever picks it up first, and they can work at the same time. Close the extra ones if you want a single agent on the job.', 'nibwp'),
                [[__('Got it', 'nibwp'), 'multi-ok']]);
        }
        if (agents <= 1 && multiNote) { multiNote.remove(); multiNote = null; }
        agentsNoted = Math.max(1, agents);
    }

    // The check-in, and the rest when nobody answers it.
    setInterval(function () {
        var s = state.presence.state;
        if (!state.loaded) { return; }
        if (awaySince && Date.now() - awaySince > DOZE_MS) {
            awaySince = 0;
            if (!agentHere() && !(nudge && nudge.kind === 'rest')) {
                showNudge('dozed',
                    __('Your agent nodded off 😴', 'nibwp'),
                    __('It stopped listening on its own. Paste the wake-up line in your agent to bring it back. Anything you send meanwhile waits here.', 'nibwp'),
                    [WAKE]);
            }
        }
        if (agentHasWork()) { agentWorked(); return; }
        if (nudge && nudge.kind === 'still') {
            if (s !== 'listening') { dropNudge(); return; }
            if (Date.now() - nudge.at > NO_ANSWER_MS && Date.now() - personAt > NO_ANSWER_MS) { restAgent(true); }
            return;
        }
        if (s === 'listening' && !nudge && Date.now() - lastWork > QUIET_MS && Date.now() > keepAfter && Date.now() - restAsked > 3 * 60000) {
            showNudge('still',
                __('Still Nibbing? 👀', 'nibwp'),
                __('Your agent has had nothing to do for 10 minutes. Keep it listening, or let it rest and save its energy (and your tokens). It rests by itself in 5 minutes if no one answers.', 'nibwp'),
                [[__('Keep listening', 'nibwp'), 'keep'], [__('Let it rest', 'nibwp'), 'rest']]);
        }
    }, 15000);

    feed.addEventListener('click', function (e) {
        var b = e.target.closest('[data-nudge]');
        if (!b) { return; }
        switch (b.dataset.nudge) {
            case 'keep':
                keepAfter = Date.now() + 20 * 60000;
                api('keep', { method: 'POST' }).then(null, function () { /* this window keeps it anyway */ });
                agentWorked();
                dropNudge();
                toast(__('Your agent stays on. I’ll check in again later.', 'nibwp'), 'ok');
                break;
            case 'rest':
                restAgent(false);
                break;
            case 'wake':
                copyText(boot.startPrompt).then(function () {
                    b.textContent = __('Copied: paste it in your agent', 'nibwp');
                }, failed);
                break;
            case 'multi-ok':
                if (multiNote) { multiNote.remove(); multiNote = null; }
                break;
        }
    });

    var copyTimer = 0;
    $('nst-copy-start').addEventListener('click', function () {
        var btn = this;
        var label = btn.querySelector('span');
        copyText(boot.startPrompt).then(function () {
            btn.classList.add('is-done');
            label.textContent = __('Copied', 'nibwp');
            toast(__('Copied. Paste it into your AI client.', 'nibwp'), 'ok');
            clearTimeout(copyTimer);
            copyTimer = setTimeout(function () {
                btn.classList.remove('is-done');
                label.textContent = __('Copy', 'nibwp');
            }, 2200);
        }, failed);
    });

    /* ------------------------------------------------------------------
     * The thread.
     * ---------------------------------------------------------------- */

    var stateLabel = {
        queued: __('Waiting for your agent', 'nibwp'),
        claimed: __('Your agent is on it', 'nibwp'),
        paused: __('Paused', 'nibwp'),
        done: __('Done', 'nibwp'),
        cancelled: __('Taken back', 'nibwp')
    };

    // The welcome stays until the thread holds a real message; steps the
    // agent took in the browser outside a request can sit above it.
    function renderWelcome() {
        var empty = !Object.keys(state.items).length;
        $('nst-welcome').hidden = !empty;
        root.classList.toggle('is-empty', !feed.children.length);
        var hint = $('nst-welcome-agent');
        if (!empty) { return; }
        if (!hint) {
            hint = el('div', 'nst-welcome__agent');
            hint.id = 'nst-welcome-agent';
            $('nst-welcome').insertBefore(hint, $('nst-welcome').querySelector('.nst-welcome__label'));
        }
        if (agentHere()) {
            hint.innerHTML = '<span class="nst-dot is-on"></span>' + esc(__('Your agent is listening. Send something.', 'nibwp'));
        } else {
            hint.innerHTML = '<span class="nst-dot"></span><span>' + esc(__('No agent yet. You can still browse, pin notes and send; messages wait for it.', 'nibwp')) + '</span> <button type="button" class="nst-link" data-open-connect>' + esc(__('Connect one', 'nibwp')) + '</button>';
        }
    }

    function chipsFor(data) {
        var html = '';
        if (data.page && data.page.url) {
            html += '<a class="nst-tag" data-nav="' + esc(data.page.url) + '" href="' + esc(data.page.url) + '">' + icon('page', 13) + '<span>' + esc(clip(data.page.title || data.page.url, 40)) + '</span></a>';
        }
        (data.annotations || []).forEach(function (a) {
            html += '<button type="button" class="nst-tag nst-tag--note" data-show="' + esc(a.selector || '') + '" data-page="' + esc(a.page_url || '') + '"><span class="nst-n">' + esc(a.n) + '</span><span>' + esc(clip(noteLabel(a), 60)) + '</span></button>';
        });
        (data.attachments || []).forEach(function (f) {
            var img = /^image\//.test(f.mime || '');
            html += '<a class="nst-tag" href="' + esc(f.url) + '" target="_blank" rel="noopener">' + (img ? '<img src="' + esc(f.url) + '" alt="">' : icon('file', 13)) + '<span>' + esc(clip(f.name, 28)) + '</span></a>';
        });
        (data.pages || []).forEach(function (p) {
            html += '<a class="nst-tag" data-nav="' + esc(p.url) + '" href="' + esc(p.url) + '">' + icon('page', 13) + '<span>' + esc(clip(p.title, 36)) + '</span></a>';
        });
        (data.skills || []).forEach(function (s) {
            html += '<span class="nst-tag nst-tag--skill">' + icon('skill', 13) + '<span>' + esc(skillName(s)) + '</span></span>';
        });
        (data.workflows || []).forEach(function (w) {
            html += '<span class="nst-tag nst-tag--skill">' + icon('workflow', 13) + '<span>' + esc(workflowName(w)) + '</span></span>';
        });
        return html ? '<div class="nst-msg__ctx">' + html + '</div>' : '';
    }

    function skillName(id) {
        var s = (boot.skills || []).find(function (x) { return x.id === id; });
        return s ? s.name : id;
    }

    function workflowName(slug) {
        var w = (boot.workflows || []).find(function (x) { return x.slug === slug; });
        return w ? w.title : slug;
    }

    // While the agent works: a spark that twinkles, a plain word that
    // changes now and then, and how long it has been at it.
    var SPARK = '<span class="nst-spark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 1c.8 6 5 10.2 11 11-6 .8-10.2 5-11 11-.8-6-5-10.2-11-11 6-.8 10.2-5 11-11Z"/></svg><svg viewBox="0 0 24 24"><path d="M12 1c.8 6 5 10.2 11 11-6 .8-10.2 5-11 11-.8-6-5-10.2-11-11 6-.8 10.2-5 11-11Z"/></svg></span>';
    var VERBS = [
        __('Working on it', 'nibwp'),
        __('Reading the page', 'nibwp'),
        __('Thinking it through', 'nibwp'),
        __('Nibbling', 'nibwp'),
        __('Building', 'nibwp'),
        __('Tinkering', 'nibwp'),
        __('Measuring twice', 'nibwp'),
        __('Polishing', 'nibwp'),
        __('Fine-tuning', 'nibwp'),
        __('Checking the details', 'nibwp')
    ];
    var REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;
    function dur(ms) {
        var t = Math.max(0, Math.floor(ms / 1000));
        var h = Math.floor(t / 3600);
        var m = Math.floor(t % 3600 / 60);
        var sec = t % 60;
        var two = function (n) { return (n < 10 ? '0' : '') + n; };
        /* translators: elapsed time; 1: hours, 2: minutes */
        if (h) { return sprintf(__('%1$dh %2$sm', 'nibwp'), h, two(m)); }
        /* translators: elapsed time; 1: minutes, 2: seconds */
        if (m) { return sprintf(__('%1$dm %2$ss', 'nibwp'), m, two(sec)); }
        /* translators: elapsed time in seconds */
        return sprintf(__('%ds', 'nibwp'), sec);
    }
    function since(item) {
        return item.started ? item.started * 1000 : (Date.parse(item.at) || Date.now());
    }
    var clockT = 0;
    function clock() {
        clearInterval(clockT);
        clockT = setInterval(function () {
            var live = document.querySelectorAll('[data-since]');
            if (!live.length) { clearInterval(clockT); clockT = 0; return; }
            live.forEach(function (n) { n.textContent = dur(Date.now() - +n.dataset.since); });
            if (REDUCED) { return; }
            document.querySelectorAll('.nst-badge__verb').forEach(function (n) {
                var started = +((n.parentNode.querySelector('[data-since]') || {}).dataset || {}).since || Date.now();
                // "Working on it" first, then a new word every few seconds.
                var k = Math.floor((Date.now() - started) / 3500);
                var word = k < 1 ? VERBS[0] : VERBS[1 + (k + (+n.dataset.seed || 0)) % (VERBS.length - 1)];
                if (n.dataset.word !== word) { n.dataset.word = word; n.textContent = word + '…'; }
            });
        }, 1000);
    }
    function liveBadge(item) {
        var t0 = since(item);
        if (!clockT) { setTimeout(clock, 0); }
        return '<span class="nst-badge nst-badge--live" data-state="claimed" title="' + esc(stateLabel.claimed) + '">' + SPARK +
            '<span class="nst-badge__verb" data-seed="' + (item.id % 7) + '" aria-hidden="true">' + esc(VERBS[0]) + '…</span>' +
            '<span class="screen-reader-text">' + esc(stateLabel.claimed) + '</span>' +
            '<span class="nst-badge__time" data-since="' + t0 + '" aria-hidden="true">' + esc(dur(Date.now() - t0)) + '</span></span>';
    }

    function badgeHtml(item) {
        var s = item.state;
        if (!s) { return ''; }
        if (s === 'claimed') { return liveBadge(item) + (state.presence.state === 'none' ? '<button type="button" class="nst-link" data-act="requeue">' + icon('retry', 12) + esc(__('Send again', 'nibwp')) + '</button>' : ''); }
        var html = '<span class="nst-badge" data-state="' + esc(s) + '">';
        if (s === 'done') { html += icon('check', 12); }
        /* translators: %s: how long the request took, e.g. "2m 14s" */
        html += '<span>' + esc(s === 'done' && item.started && item.finished >= item.started ? sprintf(__('Done in %s', 'nibwp'), dur((item.finished - item.started) * 1000)) : (stateLabel[s] || s)) + '</span></span>';
        if (s === 'queued') {
            html += '<button type="button" class="nst-link" data-act="cancel">' + esc(__('Take back', 'nibwp')) + '</button>';
        }
        return html;
    }

    function renderPrompt(item) {
        var n = el('article', 'nst-msg nst-msg--you');
        n.dataset.id = item.id;
        var text = item.text ? '<div class="nst-msg__text">' + esc(item.text).replace(/\n/g, '<br>') + '</div>' : '';
        n.innerHTML = text + chipsFor(item.data || {}) +
            '<footer class="nst-msg__meta"><span class="nst-msg__state">' + badgeHtml(item) + '</span>' +
            (item.at ? '<time class="nst-msg__at" datetime="' + esc(item.at) + '">' + esc(ago(item.at)) + '</time>' : '') +
            '<button type="button" class="nst-ib nst-ib--sm" data-act="copy" aria-label="' + esc(__('Copy as prompt', 'nibwp')) + '" data-tip="' + esc(__('Copy as prompt', 'nibwp')) + '">' + icon('copy', 14) + '</button>' +
            '<button type="button" class="nst-ib nst-ib--sm nst-fold" data-act="fold" aria-expanded="true"></button></footer>';
        if (item.state === 'queued' && !agentHere()) {
            n.appendChild(noAgentNote());
        }
        return n;
    }

    function noAgentNote() {
        var d = el('div', 'nst-msg__alone');
        d.innerHTML = '<span>' + esc(__('No agent is listening, so this is waiting.', 'nibwp')) + '</span>' +
            '<button type="button" class="nst-link" data-open-connect>' + esc(__('Bring your agent in', 'nibwp')) + '</button>' +
            '<button type="button" class="nst-link" data-act="copy">' + esc(__('or copy it as a prompt', 'nibwp')) + '</button>';
        return d;
    }

    function agentHead() {
        return '<img class="nst-msg__mark" src="' + esc(boot.icon) + '" alt="">';
    }

    function renderReply(item) {
        var n = el('article', 'nst-msg nst-msg--agent' + (item.kind === 'progress' ? ' is-progress' : ''));
        n.dataset.id = item.id;
        var d = item.data || {};
        var acts = '';
        if (d.navigate) {
            acts += '<a class="nst-act" href="' + esc(d.navigate) + '" data-nav="' + esc(d.navigate) + '">' + icon('page', 13) + esc(sprintf(__('Open %s', 'nibwp'), clip(new URL(d.navigate, boot.home).pathname, 40))) + '</a>';
        }
        if (d.highlight) {
            acts += '<button type="button" class="nst-act" data-show="' + esc(d.highlight) + '">' + icon('target', 13) + esc(__('Show on the page', 'nibwp')) + '</button>';
        }
        if (d.reload) {
            acts += '<button type="button" class="nst-act" data-act="reload">' + icon('reload', 13) + esc(__('Reload the page', 'nibwp')) + '</button>';
        }
        n.innerHTML = agentHead() + '<div class="nst-msg__md">' + md(item.text || '') + '</div>' + (acts ? '<div class="nst-msg__acts">' + acts + '</div>' : '');
        return n;
    }

    // One answer as a full-width row with a radio mark: the choices read as a
    // list to pick from, one above the other, not as a row of buttons.
    function askOption(o, disabled, img) {
        img = /^https?:\/\//i.test(img || '') ? img : '';
        return '<button type="button" class="nst-ask__opt' + (img ? ' has-img' : '') + '" data-answer="' + esc(o) + '"' + (img ? ' data-img="' + esc(img) + '"' : '') + (disabled ? ' disabled' : '') + '>' +
            '<span class="nst-ask__radio" aria-hidden="true"></span>' +
            (img ? '<img class="nst-ask__thumb" src="' + esc(img) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '') +
            '<span class="nst-ask__opt-text">' + esc(o) + '</span></button>';
    }

    // A choice with a picture shows it large while hovered or focused.
    var askPeek = document.createElement('div');
    askPeek.className = 'nst-ask-peek';
    askPeek.hidden = true;
    askPeek.setAttribute('aria-hidden', 'true');
    askPeek.innerHTML = '<img alt="" referrerpolicy="no-referrer"><span></span>';
    root.appendChild(askPeek);
    function askPeekShow(btn) {
        var img = askPeek.querySelector('img');
        if (img.getAttribute('src') !== btn.dataset.img) { img.src = btn.dataset.img; }
        askPeek.querySelector('span').textContent = btn.dataset.answer;
        askPeek.hidden = false;
        var r = btn.getBoundingClientRect();
        var w = askPeek.offsetWidth;
        var h = askPeek.offsetHeight;
        var right = r.right + 12 + w <= window.innerWidth - 8;
        askPeek.style.left = (right ? r.right + 12 : Math.max(8, r.left - w - 12)) + 'px';
        askPeek.style.top = Math.max(8, Math.min(window.innerHeight - h - 8, r.top + r.height / 2 - h / 2)) + 'px';
    }
    function askPeekHide() { askPeek.hidden = true; }
    ['mouseover', 'focusin'].forEach(function (type) {
        document.addEventListener(type, function (e) {
            var btn = e.target.closest && e.target.closest('.nst-ask__opt[data-img]');
            if (btn) { askPeekShow(btn); } else if (!askPeek.hidden && !(e.target.closest && e.target.closest('.nst-ask-peek'))) { askPeekHide(); }
        });
    });
    ['scroll', 'resize'].forEach(function (type) { window.addEventListener(type, askPeekHide, true); });
    document.addEventListener('click', askPeekHide);

    function renderAsk(item) {
        var n = el('article', 'nst-msg nst-msg--ask');
        n.dataset.id = item.id;
        var d = item.data || {};
        var answered = item.state !== 'open';
        var opts = (d.options || []).map(function (o, i) { return askOption(o, answered, (d.images || [])[i]); }).join('');
        var free = !answered && d.allow_text
            ? '<form class="nst-ask__free" data-ask-form><textarea rows="1" aria-label="' + esc(__('Your answer', 'nibwp')) + '" placeholder="' + esc(opts ? __('Or write an answer', 'nibwp') : __('Your answer', 'nibwp')) + '"></textarea>' +
                '<button type="submit" class="nst-btn">' + esc(__('Answer', 'nibwp')) + '</button></form>'
            : '';
        n.innerHTML = agentHead() + '<div class="nst-msg__md"><p class="nst-ask__label">' + esc(sprintf(__('Your %s asks', 'nibwp'), boot.brand)) + '</p>' + md(item.text) + '</div>' +
            (opts ? '<div class="nst-msg__opts">' + opts + '</div>' : '') + free;
        return n;
    }

    function renderAnswer(item) {
        var n = el('article', 'nst-msg nst-msg--you nst-msg--answer');
        n.dataset.id = item.id;
        var d = item.data || {};
        n.innerHTML = '<div class="nst-msg__text">' + (d.choice ? '<strong>' + esc(d.choice) + '</strong>' : '') + (d.choice && item.text ? ' · ' : '') + esc(item.text || '') + '</div>';
        var ask = state.items[d.ask_id];
        if (ask) { markAnswered(ask.id, d.choice || item.text); }
        return n;
    }

    // Everything the agent does between two messages lands in one quiet block:
    // the steps fold into a single line, the saves stay in view for Undo.
    function stepGroup() {
        var last = feed.lastElementChild;
        if (last && last.classList.contains('nst-steps')) { return last; }
        var g = el('div', 'nst-steps');
        g.innerHTML = '<button type="button" class="nst-steps__sum" aria-expanded="false" hidden>' + icon('tool', 13) + '<span class="nst-spin" aria-hidden="true"></span><span class="nst-steps__n"></span>' + icon('chevron', 13) + '</button>' +
            '<ol class="nst-steps__list" hidden></ol><div class="nst-steps__changes"></div>';
        return g;
    }

    var STEP_NAMES = {
        open: __('Opened', 'nibwp'),
        read: __('Read the page', 'nibwp'),
        click: __('Clicked', 'nibwp'),
        hover: __('Pointed at', 'nibwp'),
        fill: __('Typed into', 'nibwp'),
        viewport: __('Resized the view', 'nibwp'),
        audit: __('Checked the page', 'nibwp'),
        console: __('Checked for errors', 'nibwp'),
        tabs: __('Looked at the tabs', 'nibwp'),
        blocks: __('Read the blocks', 'nibwp'),
        batch: __('Ran several steps', 'nibwp'),
        follow: __('Followed along', 'nibwp'),
        capabilities: __('Checked what it can do', 'nibwp')
    };

    // "visual-click" reads "Clicked", "wp-update-post" reads "WP update post".
    function stepName(raw) {
        var t = String(raw || '').replace(/^visual-/, '');
        if (STEP_NAMES[t]) { return STEP_NAMES[t]; }
        t = t.replace(/[-_]+/g, ' ').replace(/\b(wp|php|css|html|seo|acf|url|api|rest|acss)\b/gi, function (w) { return w.toUpperCase(); });
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    function addStep(group, item) {
        var li = el('li');
        var detail = item.data && item.data.detail ? String(item.data.detail) : '';
        li.title = item.text + (detail ? ' · ' + detail : '');
        li.innerHTML = '<span class="nst-step__t">' + esc(stepName(item.text)) + '</span>' + (detail ? '<span class="nst-step__d">' + esc(detail) + '</span>' : '');
        group.querySelector('.nst-steps__list').appendChild(li);
        var count = group.querySelectorAll('.nst-steps__list li').length;
        group.querySelector('.nst-steps__sum').hidden = false;
        group.querySelector('.nst-steps__n').innerHTML = '<strong>' + esc(sprintf(_n('%d step', '%d steps', count, 'nibwp'), count)) + '</strong><span>' + esc(stepName(item.text)) + '</span>';
    }

    function renderEvent(item) {
        var d = item.data || {};
        var n = el('div', 'nst-line');
        n.dataset.id = item.id;
        var view = d.url ? '<a class="nst-link" href="' + esc(d.url) + '" data-nav="' + esc(d.url) + '">' + esc(__('View', 'nibwp')) + '</a>' : '';
        var undo = d.undo ? '<button type="button" class="nst-link" data-restore="' + esc(d.undo) + '" data-post="' + esc(d.post_id || 0) + '">' + esc(__('Undo', 'nibwp')) + '</button>' : '';
        var mark;
        var text;
        if (d.type === 'saved') {
            mark = icon('check', 12);
            text = sprintf(__('Saved “%s”', 'nibwp'), item.text || __('(no title)', 'nibwp'));
        } else if (d.type === 'restored') {
            n.classList.add('is-restore');
            mark = icon('retry', 12);
            text = sprintf(__('Put “%1$s” back to %2$s', 'nibwp'), item.text || __('(no title)', 'nibwp'), when(d.from));
        } else if (d.type === 'stopped') {
            n.classList.add('is-stop');
            mark = icon('stop', 12);
            text = d.reason === 'rest' ? __('Your agent is resting. Paste the wake-up line to bring it back.', 'nibwp') : __('The agent session ended.', 'nibwp');
        } else if (d.type === 'halted') {
            n.classList.add('is-stop');
            mark = icon('stop', 12);
            text = __('You stopped this request.', 'nibwp');
        } else if (d.type === 'undone') {
            n.classList.add('is-restore');
            mark = icon('undo', 12);
            /* translators: %d: pages put back */
            text = sprintf(_n('You undid a job: %d page put back.', 'You undid a job: %d pages put back.', d.pages || 1, 'nibwp'), d.pages || 1);
        } else {
            return null;
        }
        n.innerHTML = '<span class="nst-line__i">' + mark + '</span><span class="nst-line__text" title="' + esc(text) + '">' + esc(text) + '</span>' +
            (view || undo ? '<span class="nst-line__acts">' + view + undo + '</span>' : '');
        return n;
    }

    function syncWorking() {
        feed.classList.toggle('is-working', Object.keys(state.pending).some(function (id) {
            var it = state.items[id];
            return it && it.kind === 'prompt' && it.state === 'claimed';
        }));
        // Stop shows while something of the person's is running or waiting.
        $('nst-halt').hidden = !Object.keys(state.pending).some(function (id) {
            var it = state.items[id];
            return it && it.kind === 'prompt' && (it.state === 'claimed' || it.state === 'queued');
        });
        renderJobs();
    }

    /* ------------------------------------------------------------------
     * Jobs: everything the person asked of the agent, as a queue. The chip by
     * the agent pill counts what is running, waiting or paused; hover or click
     * opens the list. Waiting jobs can be dragged into a new order (the agent
     * takes the top one next), run next, paused or removed; the running one
     * stopped; a finished one undone, which puts back the pages it saved.
     * ---------------------------------------------------------------- */

    var jobsPop = $('nst-jobs-pop');
    var jobsBtn = $('nst-jobs');
    var jobsPinned = false;
    var jobsHoverT = 0;
    var jobDrag = null;

    function jobsOf() {
        var all = Object.keys(state.items).map(function (id) { return state.items[id]; }).filter(function (it) { return it.kind === 'prompt'; });
        var place = function (it) { return typeof it.order === 'number' ? it.order : it.id; };
        var byOrder = function (a, b) { return place(a) - place(b) || a.id - b.id; };
        return {
            running: all.filter(function (it) { return it.state === 'claimed'; }),
            next: all.filter(function (it) { return it.state === 'queued'; }).sort(byOrder),
            paused: all.filter(function (it) { return it.state === 'paused'; }).sort(byOrder),
            done: all.filter(function (it) { return it.state === 'done'; }).sort(function (a, b) { return b.id - a.id; }).slice(0, 6)
        };
    }

    function jobTitle(it) {
        return smartTitle(it.text, it.data) || __('(a pinned note)', 'nibwp');
    }

    // The pages a finished job saved: what its Undo puts back.
    function jobSaves(it) {
        return Object.keys(state.items).filter(function (id) {
            var d = state.items[id].data || {};
            return state.items[id].kind === 'event' && d.type === 'saved' && +d.request_id === it.id && d.undo;
        }).length;
    }

    function jobRow(it, kind, i) {
        var b = function (act, ic, label, cls) {
            return '<button type="button" class="nst-ib nst-ib--sm' + (cls ? ' ' + cls : '') + '" data-job="' + act + '" aria-label="' + esc(label) + '" data-tip="' + esc(label) + '">' + icon(ic, 14) + '</button>';
        };
        var acts = '';
        var meta = ago(it.at);
        var clockHtml = '';
        if (kind === 'running') {
            meta = __('Your agent is on it', 'nibwp');
            clockHtml = ' · <span data-since="' + since(it) + '">' + esc(dur(Date.now() - since(it))) + '</span>';
            if (!clockT) { setTimeout(clock, 0); }
            acts = b('remove', 'stop', __('Stop this job', 'nibwp'), 'is-danger');
        } else if (kind === 'next') {
            /* translators: %d: place in the queue */
            meta = i === 0 ? __('Next up', 'nibwp') : sprintf(__('#%d in line', 'nibwp'), i + 1);
            acts = (i ? b('next', 'top', __('Run next', 'nibwp')) : '') + b('pause', 'pause', __('Pause', 'nibwp')) + b('remove', 'x', __('Remove', 'nibwp'), 'is-danger');
        } else if (kind === 'paused') {
            meta = __('Paused: your agent skips it', 'nibwp');
            acts = b('run', 'play', __('Run', 'nibwp')) + b('remove', 'x', __('Remove', 'nibwp'), 'is-danger');
        } else {
            var saves = jobSaves(it);
            if (it.undone) {
                meta = __('Undone', 'nibwp');
            } else if (saves) {
                /* translators: %d: pages the job saved */
                meta = ago(it.at) + ' · ' + sprintf(_n('%d page saved', '%d pages saved', saves, 'nibwp'), saves);
                acts = '<button type="button" class="nst-btn nst-btn--ghost nst-job__undo" data-job="undo">' + icon('undo', 13) + esc(__('Undo', 'nibwp')) + '</button>';
            }
        }
        return '<li class="nst-job is-' + kind + (it.undone ? ' is-undone' : '') + '" data-job-id="' + it.id + '"' + (kind === 'next' ? ' draggable="true"' : '') + '>' +
            (kind === 'next' ? '<span class="nst-job__grip" aria-hidden="true">' + icon('grip', 14) + '</span>' : '<span class="nst-job__dot" aria-hidden="true">' + (kind === 'running' ? SPARK : kind === 'done' ? icon('check', 12) : icon('pause', 12)) + '</span>') +
            '<span class="nst-job__txt"><b title="' + esc(jobTitle(it)) + '">' + esc(jobTitle(it)) + '</b>' + (jobTitle(it).length > 90 ? '<button type="button" class="nst-job__more" data-job-more>' + esc(__('Show more', 'nibwp')) + '</button>' : '') + '<small>' + esc(meta) + clockHtml + '</small></span>' +
            '<span class="nst-job__acts">' + acts + '</span></li>';
    }

    function renderJobs() {
        if (!jobsPop) { return; }
        var j = jobsOf();
        var open = j.running.length + j.next.length + j.paused.length;
        $('nst-jobs-n').textContent = String(open);
        jobsBtn.classList.toggle('has-jobs', open > 0);
        jobsBtn.title = '';
        if (jobsPop.hidden || jobDrag) { return; }
        var sec = function (title, list, kind, empty) {
            if (!list.length && !empty) { return ''; }
            return '<section class="nst-jobs__sec"><h3>' + esc(title) + (list.length ? ' <span>' + list.length + '</span>' : '') + '</h3>' +
                (list.length ? '<ol class="nst-jobs__list" data-kind="' + kind + '">' + list.map(function (it, i) { return jobRow(it, kind, i); }).join('') + '</ol>' : '<p class="nst-jobs__empty">' + esc(empty) + '</p>') + '</section>';
        };
        var agents = state.presence.agents || 0;
        jobsPop.innerHTML =
            '<header class="nst-jobs__head"><b>' + esc(__('Jobs', 'nibwp')) + '</b><small>' + esc(agentHere()
                /* translators: %d: how many agents are listening */
                ? sprintf(_n('%d agent takes them from the top', '%d agents take them from the top', Math.max(1, agents), 'nibwp'), Math.max(1, agents))
                : __('No agent listening: they wait here', 'nibwp')) + '</small></header>' +
            sec(__('Running', 'nibwp'), j.running, 'running', __('Nothing running.', 'nibwp')) +
            sec(__('Up next', 'nibwp'), j.next, 'next', j.running.length ? '' : __('Nothing waiting. Send a request and it lands here.', 'nibwp')) +
            sec(__('Paused', 'nibwp'), j.paused, 'paused', '') +
            sec(__('Done', 'nibwp'), j.done, 'done', '') +
            (j.next.length > 1 ? '<p class="nst-jobs__tip">' + esc(__('Drag to change the order.', 'nibwp')) + '</p>' : '');
    }

    function placeJobs() {
        var r = jobsBtn.getBoundingClientRect();
        jobsPop.style.top = (r.bottom + 6) + 'px';
        jobsPop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - jobsPop.offsetWidth - 8)) + 'px';
    }

    function openJobs(pin) {
        jobsPinned = jobsPinned || !!pin;
        if (!jobsPop.hidden) { return; }
        jobsPop.hidden = false;
        jobsBtn.setAttribute('aria-expanded', 'true');
        renderJobs();
        placeJobs();
    }

    function closeJobs() {
        jobsPop.hidden = true;
        jobsPinned = false;
        jobsBtn.setAttribute('aria-expanded', 'false');
    }

    jobsBtn.addEventListener('click', function () {
        if (!jobsPop.hidden && jobsPinned) { closeJobs(); } else { openJobs(true); }
    });
    jobsBtn.addEventListener('mouseenter', function () { clearTimeout(jobsHoverT); jobsHoverT = setTimeout(function () { openJobs(false); }, 160); });
    [jobsBtn, jobsPop].forEach(function (n) {
        n.addEventListener('mouseleave', function () {
            clearTimeout(jobsHoverT);
            jobsHoverT = setTimeout(function () { if (!jobsPinned && !jobDrag && !jobsPop.matches(':hover') && !jobsBtn.matches(':hover')) { closeJobs(); } }, 350);
        });
    });
    jobsPop.addEventListener('mouseenter', function () { clearTimeout(jobsHoverT); });
    document.addEventListener('click', function (e) {
        if (!jobsPop.hidden && !e.composedPath().some(function (n) { return n === jobsPop || n === jobsBtn || n === $('nst-confirm'); })) { closeJobs(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !jobsPop.hidden && !$('nst-confirm').open) { closeJobs(); jobsBtn.focus(); }
    });
    window.addEventListener('resize', function () { if (!jobsPop.hidden) { placeJobs(); } });

    function jobAct(id, act) {
        return api('job', { method: 'POST', body: { id: id, act: act } }).then(function (r) {
            var it = state.items[id];
            if (it && r.job) {
                it.order = r.job.order;
                it.undone = r.job.undone;
                updateState(id, r.job.state);
            }
            (r.items || []).forEach(function (x) { append(x, false); });
            if (r.items && r.items.length) { scrollFeed(true); }
            syncWorking();
            schedule(200);
            return r;
        }, function (err) { failed(err); renderJobs(); });
    }

    jobsPop.addEventListener('click', function (e) {
        var more = e.target.closest('[data-job-more]');
        if (more) {
            var open = more.closest('.nst-job').classList.toggle('is-open');
            more.textContent = open ? __('Show less', 'nibwp') : __('Show more', 'nibwp');
            return;
        }
        var b = e.target.closest('[data-job]');
        if (!b) { return; }
        var li = b.closest('[data-job-id]');
        var id = +li.dataset.jobId;
        var act = b.dataset.job;
        if (act === 'undo') {
            var it = state.items[id];
            var n = it ? jobSaves(it) : 0;
            confirmBox({
                title: __('Undo this job?', 'nibwp'),
                /* translators: 1: the job, 2: how many pages */
                text: sprintf(_n('“%1$s” saved %2$d page. It goes back to how it was before the job. Settings or files the job changed stay as they are.', '“%1$s” saved %2$d pages. They go back to how they were before the job. Settings or files the job changed stay as they are.', n, 'nibwp'), it ? jobTitle(it) : '', n),
                ok: __('Undo the job', 'nibwp'),
                cancel: __('Keep it', 'nibwp'),
                icon: 'undo'
            }).then(function (yes) {
                if (!yes) { return; }
                jobAct(id, 'undo').then(function (r) {
                    /* translators: %d: pages put back */
                    if (r) { toast(sprintf(_n('Undone: %d page put back.', 'Undone: %d pages put back.', (r.items || []).length - 1, 'nibwp'), Math.max(1, (r.items || []).length - 1)), 'ok'); freshPage(); }
                });
            });
            return;
        }
        b.disabled = true;
        jobAct(id, act).then(function () {
            if (act === 'remove' && li.classList.contains('is-running')) { toast(__('Stopped. Your agent stops at its next step and says what it finished.', 'nibwp'), 'ok'); }
        });
    });

    // Drag a waiting job up or down; the new order goes to the server.
    jobsPop.addEventListener('dragstart', function (e) {
        var li = e.target.closest('.nst-job.is-next');
        if (!li) { return; }
        jobDrag = li;
        li.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', li.dataset.jobId); } catch (x) {}
    });
    jobsPop.addEventListener('dragover', function (e) {
        if (!jobDrag) { return; }
        var over = e.target.closest('.nst-job.is-next');
        if (!over || over === jobDrag || over.parentNode !== jobDrag.parentNode) { return; }
        e.preventDefault();
        var r = over.getBoundingClientRect();
        over.parentNode.insertBefore(jobDrag, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
    });
    jobsPop.addEventListener('drop', function (e) { if (jobDrag) { e.preventDefault(); } });
    jobsPop.addEventListener('dragend', function () {
        if (!jobDrag) { return; }
        var list = jobDrag.parentNode;
        jobDrag.classList.remove('is-dragging');
        jobDrag = null;
        var ids = Array.prototype.map.call(list.querySelectorAll('.nst-job.is-next'), function (li) { return +li.dataset.jobId; });
        ids.forEach(function (id, i) { if (state.items[id]) { state.items[id].order = (i + 1) * 10; } });
        renderJobs();
        api('queue', { method: 'POST', body: { ids: ids } }).then(function () {
            toast(__('New order saved. Your agent takes the top one next.', 'nibwp'), 'ok');
        }, failed);
    });

    $('nst-halt').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        api('halt', { method: 'POST' }).then(function (r) {
            [r.halted].concat(r.cancelled || []).forEach(function (id) {
                if (id && state.items[id]) { updateState(id, 'cancelled'); }
            });
            btn.disabled = false;
            syncWorking();
            toast(r.halted ? __('Stopped. Your agent stops at its next step and says what it finished.', 'nibwp') : __('Stopped. Nothing was left waiting.', 'nibwp'), 'ok');
            schedule(0);
        }, function (err) {
            btn.disabled = false;
            failed(err);
        });
    });

    function append(item, live) {
        if (state.items[item.id]) { return; }
        state.items[item.id] = item;
        state.lastId = Math.max(state.lastId, item.id);

        if (item.kind === 'prompt' && (item.state === 'queued' || item.state === 'claimed' || item.state === 'paused')) { state.pending[item.id] = true; }
        if (item.kind === 'ask' && item.state === 'open') { state.pending[item.id] = true; }

        if (item.kind === 'prompt') {
            curTurn = String(item.id);
            // A request sent while all are folded stays open, to watch it.
            if (live && foldAll) { flips.add(curTurn); }
        }
        var node = null;
        switch (item.kind) {
            case 'prompt': node = renderPrompt(item); break;
            case 'reply':
            case 'progress': node = renderReply(item); break;
            case 'ask': node = renderAsk(item); break;
            case 'answer': node = renderAnswer(item); break;
            case 'activity': {
                var g = stepGroup();
                addStep(g, item);
                if (!g.parentNode) { feed.appendChild(g); }
                tagFold(g);
                break;
            }
            case 'event': {
                var line = renderEvent(item);
                if (line) {
                    var eg = stepGroup();
                    eg.querySelector('.nst-steps__changes').appendChild(line);
                    if (!eg.parentNode) { feed.appendChild(eg); }
                    tagFold(eg);
                }
                break;
            }
        }
        if (node) {
            feed.appendChild(node);
            tagFold(node);
        }
        if (item.kind === 'prompt') {
            threadTitle();
            renderNotesMenu();
            paintPins();
        }
        syncWorking();

        if (item.kind === 'progress' || item.kind === 'reply' || item.kind === 'ask') { stopWorking(); }
        if (item.kind === 'ask') { askWaiting(); }
        if (item.kind === 'progress' || item.kind === 'reply') {
            feed.querySelectorAll('.nst-msg--agent.is-progress.is-latest').forEach(function (p) { p.classList.remove('is-latest'); });
            if (item.kind === 'progress' && node) { node.classList.add('is-latest'); }
        }

        if (live) {
            act(item);
            agentWorked(Date.parse(item.at) || Date.now());
            buddy('item', { kind: item.kind, state: item.state, type: (item.data || {}).type, text: /^(reply|progress|event|ask)$/.test(item.kind) ? String(item.text || '').slice(0, 300) : '' });
        }
    }

    // What a new line asks of the screen: open a page, reload, point at
    // something, or answer a question. History does none of this on load —
    // a reply from yesterday navigating the frame would be a haunting.
    function act(item) {
        var d = item.data || {};
        if (item.kind === 'reply' || item.kind === 'progress') {
            var bh = window.nibwpBuilder && window.nibwpBuilder.hold;
            if (d.navigate) { if (!(bh && bh(function () { navigate(d.navigate); }))) { if (pageBusy()) { freshPill('open', d.navigate); } else { navigate(d.navigate); } } }
            else if (d.reload) { freshPage(); }
            // A job done in a builder: its editor shows it only once loaded
            // again, and builders often save things other than the page.
            else if (item.kind === 'reply' && onBuilder() && Date.now() - freshAt > 15000) { freshPage(); }
            if (d.highlight) { setTimeout(function () { flash(d.highlight); }, d.navigate || d.reload ? 1600 : 0); }
            if (item.kind === 'reply' && document.hidden) { notifyTitle(); }
        }
        if (item.kind === 'event' && d.type === 'stopped') { restAsked = Date.now(); awaySince = 0; }
        if (item.kind === 'ask' && item.state === 'open') {
            attendAsk(item);
            if (document.hidden) { notifyTitle(); }
        }
        if (item.kind === 'event' && (d.type === 'saved' || d.type === 'restored') && d.post_id) {
            if (+d.post_id === +state.page.postId) {
                freshPage();
                if (activeTab === 'history') { loadVersions(); }
            }
            tabs.forEach(function (t) {
                if (t.id === activeTabId || !t.frame || t.loading || !t.page || +t.page.postId !== +d.post_id) { return; }
                if (t.frame.hasAttribute('data-nst-unsaved') || isBuilderUrl(t.page.url)) { t.stale = true; } else { softRefresh(t, true); }
            });
        }
        if (item.kind === 'reply' || item.kind === 'progress' || item.kind === 'ask') {
            peek(item);
        }
    }

    var baseTitle = document.title;
    function notifyTitle() {
        document.title = '● ' + baseTitle;
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { document.title = baseTitle; }
        schedule(200);
    });

    function updateState(id, s) {
        var item = state.items[id];
        if (!item || item.state === s) { return; }
        item.state = s;
        if (s === 'claimed') { item.started = Math.floor(Date.now() / 1000); item.finished = 0; }
        if (s === 'done' && item.started && !item.finished) { item.finished = Math.floor(Date.now() / 1000); }
        buddy('state', { kind: item.kind, state: s, text: String(item.text || '').slice(0, 300) });
        var n = feed.querySelector('[data-id="' + id + '"]');
        if (item.kind === 'prompt') {
            if (s !== 'queued' && s !== 'claimed' && s !== 'paused') { delete state.pending[id]; }
            if (n) {
                n.querySelector('.nst-msg__state').innerHTML = badgeHtml(item);
                var alone = n.querySelector('.nst-msg__alone');
                if (alone && s !== 'queued') { alone.remove(); }
            }
            paintPins();
            renderNotesMenu();
        }
        if (item.kind === 'ask' && s !== 'open') {
            delete state.pending[id];
            markAnswered(id);
        }
        syncWorking();
    }

    function refreshStaleBadges() {
        Object.keys(state.pending).forEach(function (id) {
            var item = state.items[id];
            var n = item && item.kind === 'prompt' && feed.querySelector('[data-id="' + id + '"] .nst-msg__state');
            if (n) { n.innerHTML = badgeHtml(item); }
        });
    }

    function markAnswered(askId, answer) {
        var item = state.items[askId];
        if (item) { item.state = 'answered'; }
        delete state.pending[askId];
        var n = feed.querySelector('[data-id="' + askId + '"]');
        if (n) {
            n.querySelectorAll('[data-answer]').forEach(function (b) {
                b.disabled = true;
                if (answer && b.dataset.answer === answer) { b.classList.add('is-picked'); }
            });
            var w = n.querySelector('[data-ask-form]');
            if (w) { w.remove(); }
        }
        askWaiting();
    }

    function scrollFeed(force) {
        var near = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 160;
        if (force || near) { feed.scrollTop = feed.scrollHeight; }
    }

    /* ------------------------------------------------------------------
     * Polling. Fast while something is in flight, slow when nothing is,
     * slower still when the tab is in the background.
     * ---------------------------------------------------------------- */

    var pollTimer = 0;
    var polling = false;

    function schedule(ms) {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(poll, ms);
    }

    function cadence() {
        if (document.hidden) { return 12000; }
        if (Object.keys(state.pending).length || agentHere()) { return 1500; }
        return 4000;
    }

    function poll() {
        if (polling) { return; }
        polling = true;
        var known = Object.keys(state.pending).join(',');
        var here = personAt > personPolled;
        personPolled = Date.now();
        api('thread?after=' + state.lastId + (known ? '&known=' + known : '') + (here ? '&here=1' : ''))
            .then(function (res) {
                if (res.thread !== state.thread) {
                    // A new thread was started elsewhere. This answer only holds
                    // rows after the old thread's last id, so start over from zero
                    // rather than show half of the new thread.
                    state.thread = res.thread;
                    clearFeed();
                    state.loaded = false;
                    state.refetch = true;
                    setPresence(res.presence);
                    return;
                }
                var live = state.loaded;
                (res.items || []).forEach(function (item) { append(item, live); });
                Object.keys(res.states || {}).forEach(function (id) { updateState(+id, res.states[id]); });
                setPresence(res.presence);
                if (!state.loaded) {
                    state.loaded = true;
                    scrollFeed(true);
                } else if ((res.items || []).length) {
                    scrollFeed(false);
                }
                renderWelcome();
            })
            .catch(function (err) {
                if (err && (err.status === 401 || err.status === 403)) { failed(err); }
            })
            .then(function () {
                polling = false;
                schedule(state.refetch ? 50 : cadence());
                state.refetch = false;
            });
    }

    function clearFeed() {
        feed.innerHTML = '';
        curTurn = '';
        flips.clear();
        state.items = {};
        state.pending = {};
        state.lastId = 0;
        syncWorking();
        paintPins();
        renderNotesMenu();
        renderWelcome();
        threadTitle();
    }

    /* Folding: under a folded request, the agent's work and answer hide and
     * the request stays, with how much it hides. An open question is never
     * hidden. The button by "New thread" folds or opens all (remembered);
     * a request's own chevron flips just that one until the next fold-all. */
    function folded(turn) {
        return !!turn && foldAll !== flips.has(turn);
    }

    function tagFold(n) {
        if (!n.dataset.turn) { n.dataset.turn = curTurn; }
        foldNode(n);
        foldCount(n.dataset.turn);
        if (findQ) { findReqs(); }
    }

    function foldNode(n) {
        var turn = n.dataset.turn;
        var shut = folded(turn);
        if (n.classList.contains('nst-msg--you')) {
            n.classList.toggle('is-folded', shut);
            var b = n.querySelector('.nst-fold');
            if (b) {
                var label = shut ? __('Show the work on this request', 'nibwp') : __('Hide the work on this request', 'nibwp');
                b.setAttribute('aria-expanded', String(!shut));
                b.setAttribute('aria-label', label);
                b.dataset.tip = label;
                b.innerHTML = icon(shut ? 'chevron' : 'chevdown', 14);
            }
            return;
        }
        var it = state.items[n.dataset.id];
        n.classList.toggle('is-folded-away', shut && !(it && it.kind === 'ask' && it.state === 'open'));
    }

    // "Show the work (3)" under a folded request.
    function foldCount(turn) {
        var p = turn && feed.querySelector('.nst-msg--you[data-turn="' + turn + '"]');
        if (!p) { return; }
        var more = p.querySelector('.nst-fold__more');
        var hidden = feed.querySelectorAll('.is-folded-away[data-turn="' + turn + '"]').length;
        if (!p.classList.contains('is-folded') || !hidden) {
            if (more) { more.remove(); }
            return;
        }
        if (!more) {
            more = el('button', 'nst-fold__more');
            more.type = 'button';
            more.dataset.act = 'fold';
            p.appendChild(more);
        }
        /* translators: %d: how many parts of the agent's work are hidden */
        more.innerHTML = icon('chevron', 12) + esc(sprintf(__('Show the work (%d)', 'nibwp'), hidden));
    }

    function applyFold() {
        feed.querySelectorAll('[data-turn]').forEach(foldNode);
        feed.querySelectorAll('.nst-msg--you[data-turn]').forEach(function (p) { foldCount(p.dataset.turn); });
        var all = $('nst-fold-all');
        var label = foldAll ? __('Open all requests', 'nibwp') : __('Fold all requests', 'nibwp');
        all.setAttribute('aria-pressed', String(foldAll));
        all.setAttribute('aria-label', label);
        all.dataset.tip = label;
        all.innerHTML = icon(foldAll ? 'unfoldall' : 'foldall', 15);
    }

    function toggleTurn(turn) {
        if (!turn) { return; }
        if (flips.has(turn)) { flips.delete(turn); } else { flips.add(turn); }
        applyFold();
    }

    /* Search through the requests: every word of the search must appear
     * somewhere in a request, its work or its answer. The others hide; the
     * words are marked where the browser can (CSS highlights). */
    var findQ = '';
    var findBox = $('nst-reqfind');
    var findIn = $('nst-reqfind-q');

    function findReqs() {
        var words = findQ.toLowerCase().split(/\s+/).filter(Boolean);
        var turns = Array.prototype.map.call(feed.querySelectorAll('.nst-msg--you[data-turn]'), function (p) { return p.dataset.turn; });
        var hits = 0;
        var ranges = [];
        turns.forEach(function (turn) {
            var nodes = feed.querySelectorAll('[data-turn="' + turn + '"]');
            var text = Array.prototype.map.call(nodes, function (n) { return n.textContent; }).join(' ').toLowerCase();
            var hit = words.every(function (w) { return text.indexOf(w) !== -1; });
            if (hit) { hits++; }
            nodes.forEach(function (n) {
                n.classList.toggle('is-found-not', !!words.length && !hit);
                if (hit && words.length && window.Highlight) { ranges = ranges.concat(markWords(n, words)); }
            });
        });
        if (window.CSS && CSS.highlights && window.Highlight) {
            if (ranges.length) { var hl = new Highlight(); ranges.forEach(function (r) { hl.add(r); }); CSS.highlights.set('nst-find', hl); } else { CSS.highlights.delete('nst-find'); }
        }
        /* translators: 1: requests found, 2: requests in the thread */
        $('nst-reqfind-n').textContent = words.length ? sprintf(__('%1$d of %2$d', 'nibwp'), hits, turns.length) : '';
    }

    function markWords(root, words) {
        var out = [];
        var walk = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        var t;
        while ((t = walk.nextNode())) {
            var low = t.data.toLowerCase();
            words.forEach(function (w) {
                var i = low.indexOf(w);
                while (i !== -1) {
                    var r = document.createRange();
                    r.setStart(t, i);
                    r.setEnd(t, i + w.length);
                    out.push(r);
                    i = low.indexOf(w, i + w.length);
                }
            });
        }
        return out;
    }

    function setFind(open) {
        findBox.hidden = !open;
        $('nst-find-req').setAttribute('aria-expanded', String(open));
        if (open) {
            findIn.focus();
            findIn.select();
            return;
        }
        findQ = '';
        findIn.value = '';
        findReqs();
    }

    $('nst-find-req').addEventListener('click', function () { setFind(findBox.hidden); });
    $('nst-reqfind-x').addEventListener('click', function () { setFind(false); $('nst-find-req').focus(); });
    findIn.addEventListener('input', debounce(function () {
        findQ = findIn.value.trim();
        findReqs();
    }, 80));
    findIn.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            setFind(false);
            $('nst-find-req').focus();
        }
    });

    $('nst-fold-all').addEventListener('click', function () {
        foldAll = !foldAll;
        flips.clear();
        store('nibwp-studio-fold', foldAll ? '1' : '0');
        applyFold();
    });
    applyFold();

    feed.addEventListener('click', function (e) {
        var t = e.target.closest('[data-act], [data-show], [data-nav], [data-answer], [data-open-connect], [data-restore], .nst-steps__sum');
        if (!t) { return; }
        var msg = t.closest('[data-id]');
        var item = msg && state.items[msg.dataset.id];

        if (t.hasAttribute('data-restore')) { askRestore(t); return; }
        if (t.matches('.nst-steps__sum')) {
            var list = t.parentNode.querySelector('.nst-steps__list');
            list.hidden = !list.hidden;
            t.setAttribute('aria-expanded', String(!list.hidden));
            return;
        }
        if (t.hasAttribute('data-open-connect')) { $('nst-connect').showModal(); return; }
        if (t.hasAttribute('data-nav')) { e.preventDefault(); navigate(t.dataset.nav); return; }
        if (t.hasAttribute('data-show')) {
            var page = t.dataset.page;
            if (page && sameSite(page) && stripHash(page) !== stripHash(state.page.url)) {
                navigate(page);
                setTimeout(function () { flash(t.dataset.show); }, 1600);
            } else {
                flash(t.dataset.show);
            }
            return;
        }
        if (t.hasAttribute('data-answer') && item) { answer(item.id, t.dataset.answer, ''); return; }
        if (t.dataset.act === 'reload') { reloadFrame(true); return; }
        if (t.dataset.act === 'fold' && msg) { toggleTurn(msg.dataset.turn); return; }
        if (!item) { return; }

        switch (t.dataset.act) {
            case 'copy':
                if (item) {
                    copyText(asPrompt(item)).then(function () { toast(__('Copied as a prompt.', 'nibwp'), 'ok'); }, failed);
                }
                break;
            case 'cancel':
                api('cancel', { method: 'POST', body: { id: item.id } }).then(function (r) { updateState(item.id, r.state); }, failed);
                break;
            case 'requeue':
                api('requeue', { method: 'POST', body: { id: item.id } }).then(function (r) {
                    state.pending[item.id] = true;
                    updateState(item.id, r.state);
                    schedule(100);
                }, failed);
                break;
        }
    });

    $('nst-welcome').addEventListener('click', function (e) {
        if (e.target.closest('[data-open-connect]')) { $('nst-connect').showModal(); }
    });

    /* A request written out in full, for an agent that is not listening. */
    function asPrompt(item) {
        var d = item.data || {};
        var lines = [];
        if (item.text) { lines.push(item.text, ''); }
        lines.push(sprintf(__('This is about my WordPress site %s, which you can work on through NibWP.', 'nibwp'), boot.home));
        if (d.page && d.page.url) {
            lines.push(sprintf(__('Page: %1$s (%2$s)', 'nibwp'), d.page.title || d.page.url, d.page.url) + (d.page.post_id ? ' · ' + sprintf(__('post %d', 'nibwp'), d.page.post_id) : ''));
        }
        if ((d.annotations || []).length) {
            lines.push('', __('Notes I pinned on the page:', 'nibwp'));
            d.annotations.forEach(function (a) {
                lines.push(a.n + '. ' + (a.note || __('(marked, see my message above)', 'nibwp')));
                lines.push('   ' + sprintf(__('Element: %1$s, “%2$s”', 'nibwp'), a.selector || a.tag || '', clip(a.text || '', 120)));
                if (a.canvas) { lines.push('   ' + sprintf(__('Inside the editor canvas %s', 'nibwp'), a.canvas)); }
                if (a.shape) {
                    lines.push('   ' + sprintf(__('Drawn: %1$s, %2$d×%3$d px at %4$d, %5$d on the page', 'nibwp'), TOOL_NAME[a.shape.type], a.shape.w, a.shape.h, a.shape.x, a.shape.y));
                }
            });
        }
        (d.pages || []).forEach(function (p) { lines.push(sprintf(__('Also see: %1$s (%2$s)', 'nibwp'), p.title, p.url)); });
        (d.attachments || []).forEach(function (f) { lines.push(sprintf(__('File: %1$s %2$s', 'nibwp'), f.name, f.url)); });
        if ((d.skills || []).length) {
            lines.push(sprintf(__('Use these NibWP skills (load each with nibwp/load-skill-playbook): %s', 'nibwp'), d.skills.join(', ')));
        }
        if ((d.workflows || []).length) {
            lines.push(sprintf(__('Follow these NibWP workflows (read each with nibwp/get-workflow): %s', 'nibwp'), d.workflows.join(', ')));
        }
        return lines.join('\n');
    }

    /* ------------------------------------------------------------------
     * Questions from the agent.
     * ---------------------------------------------------------------- */

    // A question comes to the person where the talk is, never over the page
    // they may need to look at to answer: the panel opens, the card scrolls
    // into view and glows, and its first choice takes focus unless they are
    // in the middle of typing.
    function attendAsk(item) {
        if (root.classList.contains('is-collapsed')) { setCollapsed(false); }
        setTab('chat');
        var n = feed.querySelector('[data-id="' + item.id + '"]');
        if (!n) { return; }
        callOut(n);
        var typing = document.activeElement === input && input.value.trim();
        if (!typing) {
            setTimeout(function () {
                var first = n.querySelector('.nst-ask__opt:not(:disabled), [data-ask-form] textarea');
                if (first) { first.focus({ preventScroll: true }); }
            }, 450);
        }
        askWaiting();
    }

    function callOut(n) {
        n.scrollIntoView({ block: 'center', behavior: 'smooth' });
        n.classList.remove('is-calling');
        void n.offsetWidth;
        n.classList.add('is-calling');
        setTimeout(function () { n.classList.remove('is-calling'); }, 2600);
    }

    // Over the message box while a question waits: one click back to it.
    var waitBar = document.createElement('button');
    waitBar.type = 'button';
    waitBar.className = 'nst-waiting';
    waitBar.hidden = true;
    $('nst-composer').parentNode.insertBefore(waitBar, $('nst-composer'));
    function askWaiting() {
        if (!waitBar) { return; }
        var open = Array.prototype.filter.call(feed.querySelectorAll('.nst-msg--ask'), function (n) {
            var it = state.items[n.dataset.id];
            return it && it.state === 'open';
        });
        waitBar.hidden = !open.length;
        waitBar.dataset.id = open.length ? open[open.length - 1].dataset.id : '';
        if (open.length) {
            waitBar.innerHTML = icon('chat', 14) + '<span>' + esc(__('Your agent is waiting for your answer', 'nibwp')) + '</span><span aria-hidden="true">↑</span>';
        }
    }
    waitBar.addEventListener('click', function () {
        var n = feed.querySelector('[data-id="' + waitBar.dataset.id + '"]');
        if (!n) { return; }
        callOut(n);
        var first = n.querySelector('.nst-ask__opt:not(:disabled), [data-ask-form] textarea');
        if (first) { setTimeout(function () { first.focus({ preventScroll: true }); }, 400); }
    });

    // A written answer, from the card itself. Enter sends, Shift+Enter breaks the line.
    feed.addEventListener('submit', function (e) {
        var f = e.target.closest('[data-ask-form]');
        if (!f) { return; }
        e.preventDefault();
        var msg = f.closest('[data-id]');
        var text = f.querySelector('textarea').value.trim();
        if (text && msg) { answer(+msg.dataset.id, '', text); }
    });
    feed.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.target.closest('[data-ask-form] textarea')) {
            e.preventDefault();
            e.target.closest('form').requestSubmit();
        }
    });

    // The picked row spins from the click until the agent's next message, so
    // the person sees the answer was taken and is being worked on.
    function askRows(askId) {
        return document.querySelectorAll('[data-id="' + askId + '"] .nst-ask__opt');
    }
    function setWorking(askId, choice, on) {
        askRows(askId).forEach(function (b) {
            var picked = on && b.dataset.answer === choice;
            b.classList.toggle('is-working', picked);
            if (picked) { b.setAttribute('aria-busy', 'true'); } else { b.removeAttribute('aria-busy'); }
            if (on) { b.disabled = true; }
        });
    }
    function stopWorking() {
        document.querySelectorAll('.nst-ask__opt.is-working').forEach(function (b) {
            b.classList.remove('is-working');
            b.removeAttribute('aria-busy');
        });
    }

    function answer(askId, choice, text) {
        if (choice) { setWorking(askId, choice, true); }
        return api('answer', { method: 'POST', body: { ask_id: askId, choice: choice || '', text: text || '' } })
            .then(function (r) {
                markAnswered(askId, choice || text);
                if (r.item) { append(r.item, false); scrollFeed(true); }
                schedule(200);
            }, function (err) {
                stopWorking();
                askRows(askId).forEach(function (b) { b.disabled = false; });
                failed(err);
            });
    }

    // Up and Down walk the answer rows.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
        var cur = e.target.closest && e.target.closest('.nst-ask__opt');
        if (!cur) { return; }
        var rows = Array.prototype.filter.call(cur.parentNode.querySelectorAll('.nst-ask__opt'), function (b) { return !b.disabled; });
        var next = rows[(rows.indexOf(cur) + (e.key === 'ArrowDown' ? 1 : rows.length - 1)) % rows.length];
        if (next) { e.preventDefault(); next.focus(); }
    });

    /* ------------------------------------------------------------------
     * Composer.
     * ---------------------------------------------------------------- */

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 240) + 'px';
    }
    input.addEventListener('input', function () {
        autosize();
        if (input.value === '/') {
            input.value = '';
            openLightbox();
        }
        renderHint();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            send();
        }
    });

    $('nst-composer').addEventListener('submit', function (e) {
        e.preventDefault();
        send();
    });

    function renderHint() {
        var hint = $('nst-hint');
        if (uploading > 0) {
            hint.textContent = __('Uploading…', 'nibwp');
        } else if (!input.value && !draft.annotations.length) {
            hint.textContent = __('Type / for pages, skills and more', 'nibwp');
        } else {
            hint.textContent = __('Enter to send · Shift+Enter for a new line', 'nibwp');
        }
        $('nst-send').disabled = uploading > 0 || (!input.value.trim() && !draft.annotations.length && !draft.attachments.length);
    }

    function renderChips() {
        renderNotesMenu();
        var box = $('nst-chips');
        var html = '';
        if (draft.usePage && state.page.url) {
            html += '<span class="nst-chip">' + icon('page', 13) + '<span>' + esc(clip(state.page.title || state.page.path || state.page.url, 34)) + '</span><button type="button" data-drop="page" aria-label="' + esc(__('Do not send the current page', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 12) + '</button></span>';
        }
        draft.annotations.forEach(function (a, i) {
            html += '<span class="nst-chip nst-chip--note" data-key="' + esc(a.key) + '"><span class="nst-n">' + noteNo(i) + '</span><button type="button" class="nst-chip__body" data-focus="' + esc(a.key) + '">' + esc(clip(noteLabel(a), 44)) + '</button><button type="button" data-drop="note" data-key="' + esc(a.key) + '" aria-label="' + esc(__('Remove this note', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 12) + '</button></span>';
        });
        draft.attachments.forEach(function (f) {
            var img = /^image\//.test(f.mime || '');
            html += '<span class="nst-chip' + (f.busy ? ' is-busy' : '') + '" data-key="' + esc(f.key) + '">' +
                (f.busy ? '<span class="nst-spin"></span>' : (img ? '<img src="' + esc(f.url) + '" alt="">' : icon('file', 13))) +
                '<span>' + esc(clip(f.name, 26)) + '</span>' +
                (f.busy ? '' : '<button type="button" data-drop="file" data-key="' + esc(f.key) + '" aria-label="' + esc(__('Remove this file', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 12) + '</button>') + '</span>';
        });
        draft.pages.forEach(function (p) {
            html += '<span class="nst-chip">' + icon('page', 13) + '<span>' + esc(clip(p.title, 30)) + '</span><button type="button" data-drop="pg" data-key="' + p.id + '" aria-label="' + esc(__('Remove', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 12) + '</button></span>';
        });
        draft.skills.forEach(function (s) {
            html += '<span class="nst-chip nst-chip--skill">' + icon('skill', 13) + '<span>' + esc(skillName(s)) + '</span><button type="button" data-drop="skill" data-key="' + esc(s) + '" aria-label="' + esc(__('Remove', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 12) + '</button></span>';
        });
        draft.workflows.forEach(function (w) {
            html += '<span class="nst-chip nst-chip--skill">' + icon('workflow', 13) + '<span>' + esc(workflowName(w)) + '</span><button type="button" data-drop="wf" data-key="' + esc(w) + '" aria-label="' + esc(__('Remove', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 12) + '</button></span>';
        });
        if (!draft.usePage && state.page.url) {
            html += '<button type="button" class="nst-chip nst-chip--add" data-add-page>' + icon('plus', 12) + '<span>' + esc(__('This page', 'nibwp')) + '</span></button>';
        }
        box.innerHTML = html;
        renderHint();
    }

    $('nst-chips').addEventListener('click', function (e) {
        if (e.target.closest('[data-add-page]')) { draft.usePage = true; renderChips(); return; }
        var f = e.target.closest('[data-focus]');
        if (f) {
            var a = draft.annotations.find(function (x) { return x.key === f.dataset.focus; });
            if (a) { flash(a.selector); }
            return;
        }
        var b = e.target.closest('[data-drop]');
        if (!b) { return; }
        var key = b.dataset.key;
        switch (b.dataset.drop) {
            case 'page': draft.usePage = false; break;
            case 'note': draft.annotations = draft.annotations.filter(function (x) { return x.key !== key; }); paintPins(); break;
            case 'file': draft.attachments = draft.attachments.filter(function (x) { return x.key !== key; }); break;
            case 'pg': draft.pages = draft.pages.filter(function (x) { return String(x.id) !== key; }); break;
            case 'skill': draft.skills = draft.skills.filter(function (x) { return x !== key; }); break;
            case 'wf': draft.workflows = draft.workflows.filter(function (x) { return x !== key; }); break;
        }
        renderChips();
        input.focus();
    });

    function context() {
        var c = {};
        var p = state.page;
        if (draft.usePage && p.url) {
            c.page = { url: stripVersion(p.url), title: p.title, post_id: p.postId || 0, post_type: p.postType || '', edit_url: p.edit || '', builder: p.builder || '', viewport: state.device };
        }
        if (draft.annotations.length) {
            c.annotations = draft.annotations.map(function (a, i) {
                return { n: noteNo(i), at: a.at, note: a.note, canvas: a.canvas || '', selector: a.selector, tag: a.tag, text: a.text, html: a.html, box: a.box, hints: a.hints, page_url: a.page_url, shape: a.shape };
            });
        }
        var files = draft.attachments.filter(function (f) { return f.id; });
        if (files.length) { c.attachments = files.map(function (f) { return f.id; }); }
        if (draft.pages.length) { c.pages = draft.pages.map(function (x) { return x.id; }); }
        if (draft.skills.length) { c.skills = draft.skills.slice(); }
        if (draft.workflows.length) { c.workflows = draft.workflows.slice(); }
        // Kind Guy is on duty: the agent is told he inspects the work.
        if ($('nst') && $('nst').dataset.kgDuty === 'on') { c.expert = 'on_duty'; }
        if (draft.design) { c.design = draft.design; }
        return c;
    }

    var sending = false;
    // A request on its way: a little card flies from where it was written
    // (Niby, Kind Guy, a comment, a drawing) into the message box, which
    // catches it. `from` is an element or a rectangle.
    function throwToChat(from, text) {
        var target = $('nst-composer');
        if (!target) { return; }
        target.classList.remove('is-catch');
        void target.offsetWidth;
        target.classList.add('is-catch');
        setTimeout(function () { target.classList.remove('is-catch'); }, 1100);
        var fr = from && from.getBoundingClientRect ? from.getBoundingClientRect() : from;
        if (REDUCED || !fr || !fr.width || !target.animate) { return; }
        var tr = target.getBoundingClientRect();
        var card = el('div', 'nst-throw');
        card.setAttribute('aria-hidden', 'true');
        text = String(text || '');
        card.innerHTML = '<i></i><span>' + esc(text.length > 54 ? text.slice(0, 52) + '…' : text) + '</span>';
        $('nst').appendChild(card);
        var x0 = fr.left + fr.width / 2;
        var y0 = fr.top + Math.min(fr.height, 60) / 2;
        var dx = tr.left + tr.width / 2 - x0;
        var dy = tr.top + 22 - y0;
        card.style.left = x0 + 'px';
        card.style.top = y0 + 'px';
        var at = function (k, lift, sc, rot) { return 'translate(-50%,-50%) translate(' + (dx * k).toFixed(1) + 'px,' + (dy * k - lift).toFixed(1) + 'px) scale(' + sc + ') rotate(' + rot + 'deg)'; };
        card.animate([
            { transform: at(0, 0, 0.6, 0), opacity: 0 },
            { transform: at(0.12, 46, 1, -5), opacity: 1, offset: 0.18 },
            { transform: at(0.6, 96, 0.92, 5), opacity: 1, offset: 0.6 },
            { transform: at(1, 0, 0.35, 0), opacity: 0 }
        ], { duration: 820, easing: 'cubic-bezier(.45,.05,.35,1)' }).onfinish = function () { card.remove(); };
    }

    // A comment or a drawing sent from its note: it flies into the chat.
    function sendNote() {
        var box = $('nst-note').getBoundingClientRect();
        var said = $('nst-note-text').value.trim() || (pick && pick.shape ? __('Your drawing', 'nibwp') : __('Your comment', 'nibwp'));
        if (!commitNote(true)) { return; }
        if (mode !== 'queue') { throwToChat(box, said); }
        send(box);
    }

    /* ------------------------------------------------------------------
     * Modes: how a request is handled. Auto hands it to the agent now;
     * Plan asks for a plan and changes nothing; Queue keeps it to run with
     * others later; Bypass lets the agent decide without asking.
     * ---------------------------------------------------------------- */

    var MODES = {
        auto: { label: __('Auto', 'nibwp'), icon: 'play', hint: __('Your agent starts on it right away', 'nibwp'), tip: __('Starts right away', 'nibwp'), send: __('Send', 'nibwp') },
        plan: { label: __('Plan', 'nibwp'), icon: 'list', hint: __('Your agent looks and plans; nothing changes', 'nibwp'), tip: __('Plans, no changes', 'nibwp'), send: __('Plan it', 'nibwp') },
        queue: { label: __('Queue', 'nibwp'), icon: 'queue', hint: __('Collect requests, run them together later', 'nibwp'), tip: __('Runs later', 'nibwp'), send: __('Add to queue', 'nibwp') },
        bypass: { label: __('Bypass', 'nibwp'), icon: 'bolt', hint: __('Your agent decides on its own, no questions', 'nibwp'), tip: __('No questions asked', 'nibwp'), send: __('Do it', 'nibwp') }
    };
    var mode = (function () { try { var m = localStorage.getItem('nibwp-studio-mode'); return MODES[m] ? m : 'auto'; } catch (e) { return 'auto'; } }());
    var modeMenu = el('div', 'nst-modemenu');
    modeMenu.hidden = true;
    modeMenu.setAttribute('role', 'menu');
    modeMenu.innerHTML = Object.keys(MODES).map(function (k) {
        return '<button type="button" role="menuitemradio" data-mode="' + k + '"><span class="nst-modemenu__i">' + icon(MODES[k].icon, 15) + '</span><span><b>' + esc(MODES[k].label) + '</b><small>' + esc(MODES[k].hint) + '</small></span>' + icon('check', 14) + '</button>';
    }).join('');
    document.body.appendChild(modeMenu);
    var modeFor = null;
    function setMode(m) {
        if (!MODES[m]) { return; }
        mode = m;
        try { localStorage.setItem('nibwp-studio-mode', m); } catch (e) { }
        paintMode();
        document.dispatchEvent(new CustomEvent('nibwp-studio-mode', { detail: m }));
    }
    function paintMode() {
        var b = $('nst-mode');
        if (b) {
            b.dataset.mode = mode;
            b.innerHTML = icon(MODES[mode].icon, 13) + '<span>' + esc(MODES[mode].label) + '</span>' + icon('chevron', 11);
            b.setAttribute('data-tip', MODES[mode].tip);
        }
        $('nst-send').setAttribute('aria-label', MODES[mode].send);
        $('nst-send').setAttribute('data-tip', MODES[mode].send);
        var nb = $('nst-note-mode');
        if (nb) { nb.dataset.mode = mode; nb.innerHTML = icon(MODES[mode].icon, 12) + '<span>' + esc(MODES[mode].label) + '</span>'; nb.setAttribute('data-tip', MODES[mode].tip); }
        var ns = $('nst-note-send');
        if (ns && ns.lastChild) { ns.lastChild.textContent = mode === 'auto' ? __('Send', 'nibwp') : MODES[mode].send; ns.setAttribute('data-tip', ns.lastChild.textContent + ' (Enter)'); }
        root.dataset.mode = mode;
        modeMenu.querySelectorAll('[data-mode]').forEach(function (x) { x.setAttribute('aria-checked', String(x.dataset.mode === mode)); });
    }
    function openModes(anchor) {
        if (!modeMenu.hidden && modeFor === anchor) { modeMenu.hidden = true; return; }
        modeFor = anchor;
        modeMenu.hidden = false;
        var r = anchor.getBoundingClientRect();
        var w = modeMenu.offsetWidth;
        var h = modeMenu.offsetHeight;
        modeMenu.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left)) + 'px';
        modeMenu.style.top = (r.top - h - 8 > 8 ? r.top - h - 8 : r.bottom + 8) + 'px';
        var cur = modeMenu.querySelector('[aria-checked="true"]');
        if (cur) { cur.focus(); }
    }
    modeMenu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-mode]');
        if (!b) { return; }
        setMode(b.dataset.mode);
        modeMenu.hidden = true;
        if (modeFor && modeFor.id === 'nst-mode') { input.focus(); }
    });
    modeMenu.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(modeMenu.querySelectorAll('[data-mode]'));
        var i = items.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); modeMenu.hidden = true; if (modeFor) { modeFor.focus(); } }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus(); }
    });
    document.addEventListener('pointerdown', function (e) { if (!modeMenu.hidden && !modeMenu.contains(e.target) && e.target !== modeFor && !(modeFor && modeFor.contains(e.target))) { modeMenu.hidden = true; } }, true);

    // A short, plain name for a request: what was asked, without the
    // "please could you", and where it is.
    function smartTitle(text, data) {
        data = data || {};
        if (data.title) { return clip(String(data.title), 80); }
        var note = (data.annotations || [])[0] || null;
        var t = String(text || (note && note.note) || '').split('\n')[0].replace(/\s+/g, ' ').trim();
        var before;
        do {
            before = t;
            t = t.replace(/^(please|pls|kindly|hey|hi|ok|okay|so|nib(wp)?|can you|could you|would you|will you|i want( you)? to|i'd like( you)? to|i would like( you)? to|we need to|we want to|let's|lets|make sure( to)?|try to|go ahead and)[\s,:!-]+/i, '').trim();
        } while (t !== before);
        t = t.split(/(?<=[.!?])\s|\s[—–-]\s/)[0].replace(/[.!?]+$/, '');
        if (t.length > 64) { t = t.slice(0, 64).replace(/\s+\S*$/, '') + '…'; }
        t = t.charAt(0).toUpperCase() + t.slice(1);
        var where = note && note.tag ? '<' + note.tag + '>' : '';
        return (t || __('A note on the page', 'nibwp')) + (where && t.toLowerCase().indexOf(note.tag) < 0 ? ' · ' + where : '');
    }

    /* ------------------------------------------------------------------
     * The queue: requests kept to run together. Pick, reorder, run.
     * ---------------------------------------------------------------- */

    var queue = (function () { try { return JSON.parse(localStorage.getItem('nibwp-studio-queue') || '[]') || []; } catch (e) { return []; } }());
    var queueRun = 'auto';
    function saveQueue() { try { localStorage.setItem('nibwp-studio-queue', JSON.stringify(queue)); } catch (e) { } renderQueue(); }
    var queueBtn = $('nst-queue');
    var queuePop = $('nst-queue-pop');
    function renderQueue() {
        if (!queueBtn) { return; }
        $('nst-queue-n').textContent = String(queue.length);
        queueBtn.classList.toggle('has-items', queue.length > 0);
        if (queuePop.hidden) { return; }
        var picked = queue.filter(function (q) { return q.on !== false; }).length;
        queuePop.innerHTML = '<header class="nst-queue__h"><b>' + esc(__('Queue', 'nibwp')) + '</b><small>' + esc(queue.length ? sprintf(_n('%d request waiting', '%d requests waiting', queue.length, 'nibwp'), queue.length) : __('Nothing waiting', 'nibwp')) + '</small></header>' +
            (queue.length ? '<ol class="nst-queue__list">' + queue.map(function (q, i) {
                return '<li class="nst-queue__it" draggable="true" data-q="' + i + '"><span class="nst-queue__grip" aria-hidden="true">' + icon('grip', 13) + '</span>' +
                    '<label><input type="checkbox" data-q-on="' + i + '"' + (q.on !== false ? ' checked' : '') + '><span><b>' + esc(q.title) + '</b>' + (q.text && q.text !== q.title ? '<small>' + esc(clip(q.text, 140)) + '</small>' : '') + '</span></label>' +
                    '<button type="button" class="nst-ib nst-ib--sm" data-q-del="' + i + '" aria-label="' + esc(__('Remove from the queue', 'nibwp')) + '" data-tip="' + esc(__('Remove', 'nibwp')) + '">' + icon('x', 13) + '</button></li>';
            }).join('') + '</ol>' +
            '<footer class="nst-queue__f"><label class="nst-queue__as">' + esc(__('Run as', 'nibwp')) + ' <select data-q-as>' + ['auto', 'bypass', 'plan'].map(function (k) { return '<option value="' + k + '"' + (k === queueRun ? ' selected' : '') + '>' + esc(MODES[k].label) + '</option>'; }).join('') + '</select></label>' +
                '<button type="button" class="nst-btn" data-q-clear>' + esc(__('Clear', 'nibwp')) + '</button>' +
                '<button type="button" class="nst-btn nst-btn--primary" data-q-run' + (picked ? '' : ' disabled') + '>' + icon('play', 13) + esc(picked === queue.length ? __('Run all', 'nibwp') : sprintf(__('Run %d', 'nibwp'), picked)) + '</button></footer>'
            : '<p class="nst-queue__none">' + esc(__('Switch the chat to Queue mode and your requests wait here, to run together when you are done planning.', 'nibwp')) + '</p>');
    }
    function queueAdd(text, ctx, from) {
        queue.push({ id: uid(), text: text, title: smartTitle(text, ctx), context: ctx, at: Date.now(), on: true });
        saveQueue();
        throwTo(from, queueBtn, text || smartTitle(text, ctx));
        queueBtn.classList.remove('is-catch');
        void queueBtn.offsetWidth;
        queueBtn.classList.add('is-catch');
        toast(sprintf(_n('Added to your queue (%d waiting). Run them together from the Queue at the top.', 'Added to your queue (%d waiting). Run them together from the Queue at the top.', queue.length, 'nibwp'), queue.length), 'ok');
    }
    function runQueue() {
        var list = queue.filter(function (q) { return q.on !== false; });
        if (!list.length) { return; }
        queue = queue.filter(function (q) { return q.on === false; });
        saveQueue();
        queuePop.hidden = true;
        queueBtn.setAttribute('aria-expanded', 'false');
        var i = 0;
        var next = function () {
            if (i >= list.length) {
                schedule(300);
                var card = el('div', 'nst-runcard');
                card.innerHTML = icon('play', 14) + '<span><b>' + esc(sprintf(_n('Your agent is working on %d task', 'Your agent is working on %d tasks', list.length, 'nibwp'), list.length)) + '</b><small>' + esc(__('In the order you set. Follow each one in Jobs.', 'nibwp')) + '</small></span><button type="button" class="nst-btn" data-open-jobs>' + esc(__('Jobs', 'nibwp')) + '</button>';
                $('nst-feed').appendChild(card);
                scrollFeed(true);
                return;
            }
            var q = list[i++];
            var c = q.context || {};
            c.mode = queueRun;
            c.title = q.title;
            api('prompt', { method: 'POST', body: { text: q.text, context: c } }).then(function (res) {
                if (res.presence) { setPresence(res.presence); }
                append(res.item, false);
                next();
            }, function (x) { queue.unshift(q); saveQueue(); failed(x); });
        };
        next();
        showPane('chat');
    }
    if (queueBtn) {
        queueBtn.addEventListener('click', function () {
            queuePop.hidden = !queuePop.hidden;
            queueBtn.setAttribute('aria-expanded', String(!queuePop.hidden));
            renderQueue();
            if (!queuePop.hidden) {
                var qr = queueBtn.getBoundingClientRect();
                queuePop.style.top = (qr.bottom + 6) + 'px';
                queuePop.style.left = Math.max(8, Math.min(qr.left, window.innerWidth - queuePop.offsetWidth - 8)) + 'px';
            }
        });
        document.addEventListener('pointerdown', function (e) { if (!queuePop.hidden && !queuePop.contains(e.target) && !queueBtn.contains(e.target)) { queuePop.hidden = true; queueBtn.setAttribute('aria-expanded', 'false'); } }, true);
        queuePop.addEventListener('change', function (e) {
            var on = e.target.closest('[data-q-on]');
            if (on) { queue[+on.dataset.qOn].on = on.checked; saveQueue(); }
            var as = e.target.closest('[data-q-as]');
            if (as) { queueRun = as.value; }
        });
        queuePop.addEventListener('click', function (e) {
            var d = e.target.closest('[data-q-del]');
            if (d) { queue.splice(+d.dataset.qDel, 1); saveQueue(); return; }
            if (e.target.closest('[data-q-clear]')) { queue = []; saveQueue(); return; }
            if (e.target.closest('[data-q-run]')) { runQueue(); }
        });
        var qDrag = -1;
        queuePop.addEventListener('dragstart', function (e) { var it = e.target.closest('[data-q]'); if (it) { qDrag = +it.dataset.q; e.dataTransfer.effectAllowed = 'move'; it.classList.add('is-dragging'); } });
        queuePop.addEventListener('dragover', function (e) { if (qDrag > -1 && e.target.closest('[data-q]')) { e.preventDefault(); } });
        queuePop.addEventListener('drop', function (e) {
            var it = e.target.closest('[data-q]');
            if (qDrag < 0 || !it) { return; }
            e.preventDefault();
            var to = +it.dataset.q;
            var moved = queue.splice(qDrag, 1)[0];
            queue.splice(to, 0, moved);
            qDrag = -1;
            saveQueue();
        });
        queuePop.addEventListener('dragend', function () { qDrag = -1; renderQueue(); });
        renderQueue();
    }
    document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-open-jobs]')) { var j = $('nst-jobs'); if (j) { j.click(); } } });

    // A card flies from `from` to `to`: where the request went.
    function throwTo(from, to, text) {
        var fr = from && from.getBoundingClientRect ? from.getBoundingClientRect() : from;
        if (REDUCED || !fr || !fr.width || !to || !to.animate) { return; }
        var tr = to.getBoundingClientRect();
        var card = el('div', 'nst-throw');
        card.setAttribute('aria-hidden', 'true');
        text = String(text || '');
        card.innerHTML = '<i></i><span>' + esc(text.length > 54 ? text.slice(0, 52) + '…' : text) + '</span>';
        $('nst').appendChild(card);
        var x0 = fr.left + fr.width / 2;
        var y0 = fr.top + Math.min(fr.height, 60) / 2;
        var dx = tr.left + tr.width / 2 - x0;
        var dy = tr.top + tr.height / 2 - y0;
        card.style.left = x0 + 'px';
        card.style.top = y0 + 'px';
        var at = function (k, lift, sc) { return 'translate(-50%,-50%) translate(' + (dx * k).toFixed(1) + 'px,' + (dy * k - lift).toFixed(1) + 'px) scale(' + sc + ')'; };
        card.animate([
            { transform: at(0, 0, 0.6), opacity: 0 },
            { transform: at(0.15, 40, 1), opacity: 1, offset: 0.2 },
            { transform: at(0.7, 70, 0.8), opacity: 1, offset: 0.7 },
            { transform: at(1, 0, 0.25), opacity: 0 }
        ], { duration: 860, easing: 'cubic-bezier(.45,.05,.35,1)' }).onfinish = function () { card.remove(); };
    }
    $('nst-mode').addEventListener('click', function () { openModes(this); });
    if ($('nst-note-mode')) { $('nst-note-mode').addEventListener('click', function () { openModes(this); }); }
    paintMode();

    function send(from) {
        keepMark();
        var text = input.value.trim();
        if (sending || uploading > 0) { return; }
        if (!text && !draft.annotations.length && !draft.attachments.length && !draft.design) {
            input.focus();
            return;
        }
        if (mode === 'queue') {
            var qc = context();
            if (draft.title) { qc.title = draft.title; }
            queueAdd(text, qc, from && from.getBoundingClientRect ? from : $('nst-composer'));
            input.value = '';
            autosize();
            draft.annotations = [];
            draft.attachments = [];
            draft.pages = [];
            draft.skills = [];
            draft.workflows = [];
            draft.design = null;
            draft.title = '';
            draft.usePage = true;
            setProofing(false);
            renderChips();
            paintPins();
            return;
        }
        agentWorked();
        sending = true;
        $('nst-send').disabled = true;
        var sc = context();
        if (mode !== 'auto') { sc.mode = mode; }
        if (draft.title) { sc.title = draft.title; }
        api('prompt', { method: 'POST', body: { text: text, context: sc } })
            .then(function (res) {
                buddy('sent', { notes: draft.annotations.length, text: text.slice(0, 300) });
                draft.annotations.forEach(function (a) { if (a.replaces) { hideNote({ msg: a.replaces.msg, n: a.replaces.n }, true); } });
                input.value = '';
                autosize();
                draft.annotations = [];
                draft.attachments = [];
                draft.pages = [];
                draft.skills = [];
                draft.workflows = [];
                draft.design = null;
                draft.title = '';
                draft.usePage = true;
                setProofing(false);
                renderChips();
                paintPins();
                if (res.presence) { setPresence(res.presence); }
                append(res.item, false);
                scrollFeed(true);
                renderWelcome();
                tCountLater();
                schedule(300);
                showPane('chat');
            }, failed)
            .then(function () {
                sending = false;
                renderHint();
            });
    }

    // Every idea at once, in small groups, each with its own colour: a
    // short menu to scan, right at the top of an empty thread.
    function renderStarters() {
        var list = boot.starters || [];
        var groups = boot.starterGroups || {};
        var order = Object.keys(groups);
        list.forEach(function (s) { if (order.indexOf(s.group || '') < 0) { order.push(s.group || ''); } });
        var n = 0;
        $('nst-starters').innerHTML = order.map(function (g, gi) {
            var rows = list.map(function (s, i) { return [s, i]; }).filter(function (x) { return (x[0].group || '') === g; });
            if (!rows.length) { return ''; }
            return '<div class="nst-starters__group" data-g="' + gi % 4 + '">' + (groups[g] ? '<p class="nst-starters__g">' + esc(groups[g]) + '</p>' : '') +
                rows.map(function (x) {
                    var s = x[0];
                    return '<button type="button" class="nst-starter" data-starter="' + x[1] + '" style="--i:' + (n++) + '">' +
                        '<span class="nst-starter__i">' + icon(s.icon || 'spark', 15) + '</span>' +
                        '<span class="nst-starter__t">' + esc(s.label) + '</span>' +
                        (s.hint ? '<span class="nst-starter__d">' + esc(s.hint) + '</span>' : '') +
                        icon('forward', 14) + '</button>';
                }).join('') + '</div>';
        }).join('');
    }

    $('nst-starters').addEventListener('click', function (e) {
        var b = e.target.closest('[data-starter]');
        if (!b) { return; }
        useStarter(boot.starters[+b.dataset.starter]);
    });

    function useStarter(s) {
        if (!s) { return; }
        input.value = s.text;
        autosize();
        renderHint();
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    }

    /* ------------------------------------------------------------------
     * Files: picked, pasted or dropped, uploaded to the media library so
     * the agent can open them by URL.
     * ---------------------------------------------------------------- */

    var uploading = 0;

    // Sending waits for files still on their way up.
    function whenUploaded(fn) {
        if (!uploading) { fn(); return; }
        var t = setInterval(function () { if (!uploading) { clearInterval(t); fn(); } }, 200);
    }

    function upload(files) {
        Array.prototype.forEach.call(files, function (file) {
            if (boot.maxUpload && file.size > boot.maxUpload) {
                toast(sprintf(__('%s is larger than this site accepts.', 'nibwp'), file.name), 'bad');
                return;
            }
            var entry = { key: uid(), name: file.name || __('Pasted image', 'nibwp'), mime: file.type, busy: true };
            if (/^image\//.test(file.type)) { entry.url = URL.createObjectURL(file); }
            draft.attachments.push(entry);
            uploading++;
            renderChips();

            var fd = new FormData();
            fd.append('file', file, file.name || ('pasted-' + Date.now() + '.png'));
            fd.append('nibwp_studio', '1');
            api(boot.media, { method: 'POST', body: fd })
                .then(function (m) {
                    entry.id = m.id;
                    entry.url = m.source_url || entry.url;
                    entry.mime = m.mime_type || entry.mime;
                    entry.busy = false;
                }, function (err) {
                    draft.attachments = draft.attachments.filter(function (x) { return x !== entry; });
                    failed(err);
                })
                .then(function () {
                    uploading--;
                    renderChips();
                });
        });
    }

    $('nst-attach').addEventListener('click', function () { $('nst-file').click(); });
    $('nst-file').addEventListener('change', function () {
        upload(this.files);
        this.value = '';
    });

    input.addEventListener('paste', function (e) {
        var files = e.clipboardData && e.clipboardData.files;
        if (files && files.length) {
            e.preventDefault();
            upload(files);
        }
    });

    var side = document.querySelector('.nst-side');
    ['dragenter', 'dragover'].forEach(function (ev) {
        side.addEventListener(ev, function (e) {
            if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') > -1) {
                e.preventDefault();
                side.classList.add('is-drop');
            }
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        side.addEventListener(ev, function (e) {
            if (ev === 'dragleave' && side.contains(e.relatedTarget)) { return; }
            side.classList.remove('is-drop');
        });
    });
    side.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) {
            e.preventDefault();
            upload(e.dataTransfer.files);
        }
    });

    /* ------------------------------------------------------------------
     * The frame.
     * ---------------------------------------------------------------- */

    var progressTimer = 0;
    function progress(on) {
        var bar = $('nst-progress');
        clearTimeout(progressTimer);
        if (on) {
            bar.classList.remove('is-done');
            bar.classList.add('is-on');
        } else if (bar.classList.contains('is-on')) {
            bar.classList.add('is-done');
            progressTimer = setTimeout(function () { bar.classList.remove('is-on', 'is-done'); }, 400);
        }
    }

    // A page on this site loads as itself; any other web address loads as a
    // snapshot the site serves (see nibwp_studio_snapshot()), so it can be
    // pointed at, read and copied like the site's own pages.
    function targetFor(url) {
        var u = sameSite(url);
        if (u) { return u.href; }
        try { u = new URL(url, boot.home); } catch (e) { return null; }
        if (!/^https?:$/.test(u.protocol) || !boot.snapshot) { return null; }
        return boot.snapshot + '&url=' + encodeURIComponent(u.href);
    }

    // The address a frame really shows: a snapshot's own page, a preview's post.
    function realUrl(href) {
        try {
            var u = new URL(href, boot.home);
            if (u.searchParams.get('action') === 'nibwp_studio_snapshot' && u.searchParams.get('url')) { return u.searchParams.get('url'); }
        } catch (e) { /* not a URL */ }
        return stripVersion(href);
    }

    function navigate(url, opts) {
        var target = targetFor(url);
        if (!target) { return; }
        if (opts && opts.newTab) {
            openTab(target, { beside: true });
            showPane('site');
            return;
        }
        var tab = activeTabRec();
        var here = '';
        try { here = frame.contentWindow.location.href; } catch (e) { here = ''; }
        if (tab && tab.frame === frame && isBuilderUrl(here)) {
            var f = frame;
            okToLeave(f, 'nav').then(function (go) {
                if (!go) { return; }
                tab.url = target;
                swapBuilder(tab, f, f.contentWindow, target);
            });
            showPane('site');
            return;
        }
        var here2 = frame;
        builderLeave([here2], 'nav').then(function (go) {
            if (!go || here2 !== frame) { return; }
            progress(true);
            if (tab) { tab.loading = true; tab.url = target; renderTabs(); }
            frame.src = target;
            showPane('site');
        });
    }

    var keepScroll = null;
    /* Before Agent View reloads a builder's editor: our own question, not
     * the browser's "Leave site?", and only when the editor says it has
     * unsaved edits. why: 'agent' when the reload is to show the agent's
     * saved changes. When it goes ahead, the editor's leave-guard is
     * silenced for that one reload, so no second prompt follows. */
    function okToLeave(f, why) {
        var win = null;
        var url = '';
        try { win = f.contentWindow; url = win.location.href; } catch (e) { return Promise.resolve(true); }
        if (!isBuilderUrl(url)) { return Promise.resolve(true); }
        if (!hasUnsaved(win)) {
            quietLeave(win);
            return Promise.resolve(true);
        }
        var agent = why === 'agent';
        var away = why === 'nav';
        return confirmBox({
            title: agent ? __('Show your agent’s changes?', 'nibwp') : away ? __('Leave the editor?', 'nibwp') : __('Reload the editor?', 'nibwp'),
            text: agent
                ? __('Your agent saved changes to this page, but the editor still shows the page from before, with your unsaved edits in it. Reloading the editor shows your agent’s changes and drops your unsaved edits. Keeping your edits is fine too, but saving them later writes over your agent’s changes.', 'nibwp')
                : away
                    ? __('The editor has unsaved edits. Leaving drops them. Save them in the editor first if you want to keep them.', 'nibwp')
                    : __('The editor has unsaved edits. Reloading drops them. Save them in the editor first if you want to keep them.', 'nibwp'),
            ok: agent ? __('Reload and show the changes', 'nibwp') : away ? __('Leave anyway', 'nibwp') : __('Reload anyway', 'nibwp'),
            cancel: agent ? __('Keep my edits', 'nibwp') : __('Stay', 'nibwp')
        }).then(function (yes) {
            if (yes) { quietLeave(win); }
            return yes;
        });
    }

    // Runs before the editor's own leave-guard and stops it, once.
    function quietLeave(win) {
        try {
            win.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, { capture: true, once: true });
        } catch (e) { /* cross-origin */ }
    }

    function reloadFrame(keep, why) {
        reloadTabFrame(activeTabRec(), frame, keep, why);
    }

    // done: called once the page is back (bridge commands wait for it).
    function reloadTabFrame(tab, f, keep, why, done) {
        if (f && f.hasAttribute('data-nst-unsaved')) {
            builderLeave([f], 'reload').then(function (go) { if (go) { reloadTabFrame(tab, f, keep, why, done); } else if (done) { done(); } });
            return;
        }
        var url = '';
        try { url = f.contentWindow.location.href; } catch (e) { url = ''; }
        if (tab && tab.frame === f && isBuilderUrl(url)) {
            okToLeave(f, why).then(function (go) {
                if (go) { swapBuilder(tab, f, f.contentWindow, null, done); } else if (done) { done(); }
            });
            return;
        }
        if (done) { f.addEventListener('load', function () { done(); }, { once: true }); }
        reloadNow(f, keep);
    }

    function reloadNow(f, keep) {
        try {
            if (keep) { keepScroll = f.contentWindow.scrollY; }
            progress(true);
            f.contentWindow.location.reload();
        } catch (e) {
            f.src = f.src;
        }
    }

    /* A builder's editor (Etch, Bricks) keeps the page in its own memory: the
     * agent's saved change shows only once the editor loads again. That
     * happens out of sight: a fresh editor loads in a hidden twin frame and
     * takes the old one's place when its canvas is ready, with no blank
     * screen and no "Leave site?" prompt. Never over the person's own
     * unsaved edits (the editor's own leave-guard says so): then a note
     * offers to update when they are ready. Other pages reload as before. */
    // Builders and editors that keep the page in their own memory: Etch,
    // Bricks, Oxygen, Breakdance, Beaver Builder, Divi, Elementor, and the
    // WordPress editors.
    function isBuilderUrl(url) {
        return /[?&](etch|bricks|ct_builder|breakdance|fl_builder|et_fb)=|[?&]action=(elementor|edit)(&|$)|\/post-new\.php/.test(url || '');
    }

    function onBuilder() {
        try { return isBuilderUrl(frame.contentWindow.location.href); } catch (e) { return isBuilderUrl(state.page.url); }
    }

    var freshAt = 0;

    /* NibWP's changes reach the page smoothly, never mid-gesture:
     *  - gathered: a job's saves make one refresh when it pauses (or every
     *    8 s on a long one), not one per save;
     *  - out of sight: the page loads again behind the one on screen, at the
     *    same scroll, and takes its place when ready (no flash, no jump);
     *  - not under the person's hands: while they draw, write a note or
     *    select text, a calm pill says so and it shows once they are done;
     *    the Builder holds it while it has unsaved edits. */
    var freshFirst = 0;
    var freshT = 0;
    function freshPage() {
        if (!freshFirst) { freshFirst = Date.now(); }
        clearTimeout(freshT);
        freshT = setTimeout(freshNow, 1200);
    }
    function agentAtWork() { return feed.classList.contains('is-working'); }
    // What the person is doing on the page that a refresh would break.
    function pageBusy() {
        if (!$('nst-note').hidden) { return 'note'; }
        if (state.proofing) { return 'marking'; }
        try {
            var s = frame.contentWindow.getSelection();
            if (s && !s.isCollapsed && String(s).trim()) { return 'selecting'; }
        } catch (e) { /* cross-origin */ }
        return '';
    }
    function freshNow(force) {
        clearTimeout(freshT);
        if (force !== true && agentAtWork() && Date.now() - freshFirst < 8000) { freshT = setTimeout(freshNow, 1200); return; }
        // Mid-edit in the Builder: it calls back once they pause (its banner says so).
        if (window.nibwpBuilder && window.nibwpBuilder.hold && window.nibwpBuilder.hold(function () { freshNow(true); })) { freshPill(''); return; }
        var busy = force === true ? '' : pageBusy();
        if (busy) { freshPill(busy); freshT = setTimeout(freshNow, 1000); return; }
        freshFirst = 0;
        var tab = activeTabRec();
        if (!tab || !tab.frame) { freshPill(''); return; }
        if (onBuilder()) { freshAt = Date.now(); freshPill(''); refreshBuilder(tab); return; }
        softRefresh(tab);
    }
    // The page again, out of sight: loaded behind the one on screen, at its
    // scroll, it takes its place when ready.
    function softRefresh(tab, quiet) {
        if (!tab || !tab.frame || tab.twin) { return; }
        var f = tab.frame;
        var win = f.contentWindow;
        if (tab.id === activeTabId) {
            try { keepScroll = win.scrollY; } catch (e) { keepScroll = null; }
        }
        swapBuilder(tab, f, win, null, function () { if (tab.id === activeTabId && !quiet) { freshPill('done'); } });
    }
    var freshEl = el('div', 'nst-fresh');
    freshEl.setAttribute('role', 'status');
    freshEl.hidden = true;
    artboard.appendChild(freshEl);
    var freshPillT = 0;
    function freshPill(kind, url) {
        clearTimeout(freshPillT);
        if (!kind) { freshEl.hidden = true; return; }
        var text = kind === 'done' ? __('Updated', 'nibwp')
            : kind === 'note' ? __('NibWP updated this page. It shows once your note is sent or closed.', 'nibwp')
                : kind === 'open' ? __('NibWP worked on another page.', 'nibwp')
                    : __('NibWP updated this page. It shows when you are done.', 'nibwp');
        var btn = kind === 'marking' || kind === 'selecting' ? '<button type="button" data-fresh="now">' + esc(__('Show now', 'nibwp')) + '</button>'
            : kind === 'open' ? '<button type="button" data-fresh="open" data-url="' + esc(url || '') + '">' + esc(__('Open it', 'nibwp')) + '</button><button type="button" class="is-x" data-fresh="x" aria-label="' + esc(__('Close', 'nibwp')) + '">' + icon('x', 12) + '</button>' : '';
        freshEl.className = 'nst-fresh' + (kind === 'done' ? ' is-done' : '');
        freshEl.innerHTML = (kind === 'done' ? icon('check', 13) : '<i class="nst-fresh__dot" aria-hidden="true"></i>') + '<span>' + esc(text) + '</span>' + btn;
        freshEl.hidden = false;
        if (kind === 'done') { freshPillT = setTimeout(function () { freshEl.hidden = true; }, 1600); }
    }
    freshEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-fresh]');
        if (!b) { return; }
        if (b.dataset.fresh === 'now') {
            if (state.proofing) { setProofing(false); }
            try { frame.contentWindow.getSelection().removeAllRanges(); } catch (x) { /* cross-origin */ }
            freshNow(true);
        } else if (b.dataset.fresh === 'open') {
            freshEl.hidden = true;
            navigate(b.dataset.url);
        } else {
            freshEl.hidden = true;
        }
    });

    function hasUnsaved(win) {
        try {
            var ev = new win.Event('beforeunload', { cancelable: true });
            win.dispatchEvent(ev);
            return ev.defaultPrevented;
        } catch (e) {
            return false;
        }
    }

    function refreshBuilder(tab) {
        var old = tab.frame;
        if (tab.twin) { return; }
        var win = old.contentWindow;
        if (hasUnsaved(win)) {
            okToLeave(old, 'agent').then(function (go) { if (go) { swapBuilder(tab, old, win); } });
            return;
        }
        swapBuilder(tab, old, win);
    }

    function swapBuilder(tab, old, win, to, done) {
        if (tab.twin || tab.frame !== old) { if (done) { done(); } return; }
        quietLeave(win);
        var url = tab.url;
        try { url = win.location.href; } catch (e) { /* the tab's own address */ }
        if (to) { url = to; }
        if (tab.id === activeTabId) { progress(true); }
        var twin = document.createElement('iframe');
        twin.className = 'nst-frame is-twin';
        twin.setAttribute('data-vs-frame', tab.id);
        twin.setAttribute('sandbox', frameSandbox);
        twin.setAttribute('aria-hidden', 'true');
        twin.tabIndex = -1;
        twin.title = old.title;
        artboard.insertBefore(twin, old);
        tab.twin = twin;
        var started = Date.now();
        var swap = function () {
            if (tab.twin !== twin) { return; }
            tab.twin = null;
            twin.classList.remove('is-twin');
            twin.removeAttribute('aria-hidden');
            twin.removeAttribute('tabindex');
            twin.hidden = tab.id !== activeTabId;
            // Where the person is on the page now, not when it began loading.
            // At the same place before it shows, at once: a site's smooth scrolling would glide there.
            if (tab.id === activeTabId && keepScroll !== null) { try { keepScroll = old.contentWindow.scrollY; twin.contentWindow.scrollTo({ top: keepScroll, behavior: 'instant' }); } catch (e) { /* cross-origin */ } }
            old.remove();
            tab.frame = twin;
            if (tab.id === activeTabId) { frame = twin; }
            wireFrame(tab, twin);
            onTabLoad(tab);
            if (done) { done(); }
        };
        twin.addEventListener('load', function () {
            (function ready() {
                if (tab.twin !== twin) { return; }
                var doc = null;
                try { doc = twin.contentDocument; } catch (e) { doc = null; }
                var big = doc ? Array.prototype.filter.call(doc.querySelectorAll('iframe'), function (f) { return f.getBoundingClientRect().width > 200; }) : [];
                var canvas = big.some(function (f) {
                    try { return f.contentDocument && f.contentDocument.body && f.contentDocument.body.children.length > 0; } catch (e) { return false; }
                }) || (!big.length && Date.now() - started > 2500);
                // Ready when the canvas holds the page, or after a while anyway;
                // a page that is not a builder is ready when it has loaded.
                if (canvas || !isBuilderUrl(url) || Date.now() - started > 15000) { setTimeout(swap, 300); return; }
                setTimeout(ready, 250);
            }());
        }, { once: true });
        twin.src = url;
    }

    function stripHash(url) {
        return String(url || '').split('#')[0];
    }

    function frameDoc() {
        try {
            var d = frame.contentWindow.document;
            return d && d.body ? d : null;
        } catch (e) {
            return null;
        }
    }

    function readPage(win, doc) {
        var q0 = new URLSearchParams(win.location.search);
        if (q0.get('action') === 'nibwp_studio_snapshot') {
            var shown = q0.get('url') || '';
            var host = '';
            try { host = new URL(shown).hostname.replace(/^www\./, ''); } catch (e) { /* empty */ }
            var st = (doc.title || host).split(/\s[–—|-]\s/)[0];
            return { url: shown, path: shown, title: st || host, admin: false, external: true, host: host, postId: 0, postType: '', edit: '', builder: '' };
        }
        var cls = ' ' + (doc.body.className || '') + ' ';
        var path = win.location.pathname;
        var admin = path.indexOf('/wp-admin/') > -1;
        var page = { url: win.location.href, path: path + win.location.search, title: doc.title || '', admin: admin, postId: 0, postType: '', edit: '', builder: '' };

        var m = cls.match(/\s(?:postid|page-id)-(\d+)\s/);
        if (m) { page.postId = +m[1]; }
        if (admin) {
            var q = new URLSearchParams(win.location.search);
            if (q.get('post')) { page.postId = +q.get('post'); }
            var pt = doc.getElementById('post_type');
            if (pt) { page.postType = pt.value; }
        } else {
            var single = cls.match(/\ssingle-([a-z0-9_-]+)\s/);
            if (single) { page.postType = single[1]; }
            else if (/\spage\s/.test(cls) && page.postId) { page.postType = 'page'; }
        }

        var edit = doc.querySelector('#wp-admin-bar-edit > a');
        if (edit) { page.edit = edit.href; }
        else if (page.postId && !admin) { page.edit = boot.admin + 'post.php?post=' + page.postId + '&action=edit'; }

        if (doc.querySelector('[data-etch-element]')) { page.builder = 'etch'; }
        else if (doc.querySelector('[class*="brxe-"]')) { page.builder = 'bricks'; }
        else if (doc.querySelector('.elementor')) { page.builder = 'elementor'; }
        else if (doc.querySelector('[class*="wp-block-"]')) { page.builder = 'blocks'; }

        var t = page.title.split(/\s[–—|-]\s/)[0];
        if (t) { page.title = t; }
        return page;
    }

    /* ------------------------------------------------------------------
     * Tabs: one frame per open page, the active one shown. `frame` always
     * points at the active tab's frame, so everything that reads the page
     * reads the one on screen. A tab restored from last time gets its frame
     * only when it is first shown.
     * ---------------------------------------------------------------- */

    var TAB_MAX = 12;
    var tabs = [{ id: 'main', url: frame.src, frame: frame, page: null, loading: true }];
    var activeTabId = 'main';
    var tabSeq = 0;
    var tabbar = $('nst-tabbar');
    var frameSandbox = frame.getAttribute('sandbox');

    function tabRec(id) {
        return tabs.find(function (t) { return t.id === id; }) || null;
    }

    function activeTabRec() {
        return tabRec(activeTabId);
    }

    function makeFrame(tab) {
        var f = document.createElement('iframe');
        f.className = 'nst-frame';
        f.setAttribute('data-vs-frame', tab.id);
        f.setAttribute('sandbox', frameSandbox);
        f.title = frame.title;
        f.hidden = true;
        artboard.insertBefore(f, $('nst-progress'));
        wireFrame(tab, f);
        f.src = tab.url;
        return f;
    }

    function wireFrame(tab, f) {
        f.addEventListener('load', function () { onTabLoad(tab); });
    }

    function onTabLoad(tab) {
        var f = tab.frame;
        var doc = null;
        try {
            doc = f.contentWindow.document;
            if (!doc || !doc.body) { doc = null; }
        } catch (e) { doc = null; }
        tab.loading = false;
        if (doc) {
            tab.page = readPage(f.contentWindow, doc);
            // The site's own icon, as a browser tab shows it.
            var fav = doc.querySelector('link[rel~="icon"][href]');
            tab.fav = fav && /^https?:/.test(fav.href) ? fav.href : '';
            prepareFrame(f.contentWindow, doc, tab);
        } else {
            tab.page = { url: '', title: __('A page outside this site', 'nibwp') };
        }
        renderTabs();
        saveTabs();
        if (tab.id !== activeTabId) { return; }

        progress(false);
        state.page = tab.page;
        buddy('page', { external: !!tab.page.external, admin: !!tab.page.admin, builder: tab.page.builder || '', url: tab.page.url || '' });
        renderAddress();
        renderChips();
        if (!doc) { return; }
        paintPins();
        if (keepScroll !== null) {
            var y = keepScroll;
            var win = f.contentWindow;
            keepScroll = null;
            win.scrollTo({ top: y, behavior: 'instant' });
            setTimeout(function () { win.scrollTo({ top: y, behavior: 'instant' }); }, 250);
        }
        renderVersionBar();
        if (activeTab === 'history') { loadVersions(); }
    }

    function activateTab(id) {
        var tab = tabRec(id);
        if (!tab) { return; }
        if (tab.id !== activeTabId) {
            if (state.proofing) { setProofing(false); }
            if (!$('nst-note').hidden) { closeNote(); }
            hideHover();
        }
        var moved = tab.id !== activeTabId;
        activeTabId = tab.id;
        if (!tab.frame) {
            tab.loading = true;
            tab.frame = makeFrame(tab);
        }
        tabs.forEach(function (t) { if (t.frame) { t.frame.hidden = t !== tab; } });
        frame = tab.frame;
        progress(!!tab.loading);
        state.page = tab.page || { url: realUrl(tab.url), title: '' };
        renderAddress();
        renderChips();
        paintPins();
        renderVersionBar();
        if (activeTab === 'history') { loadVersions(); }
        renderTabs();
        saveTabs();
        if (moved && !tab.loading) { buddy('tab', { url: state.page.url || '' }); }
        if (moved) { freshPill(''); }
        // Changed by NibWP while out of sight with edits on it: shown now, smoothly.
        if (moved && tab.stale && !tab.loading) { tab.stale = false; freshPage(); }
    }

    /* Unsaved Builder edits live on their tab's page: closing that tab,
     * reloading it or sending it to another address asks first. */
    function unsavedIn(frames) {
        return frames.filter(function (f) { return f && f.hasAttribute('data-nst-unsaved'); });
    }
    function builderLeave(frames, how) {
        var hit = unsavedIn(frames);
        if (!hit.length) { return Promise.resolve(true); }
        var names = hit.map(function (f) { var t = tabs.find(function (x) { return x.frame === f; }); return t ? tabLabel(t) : ''; }).filter(Boolean);
        return confirmBox({
            title: __('Leave without saving?', 'nibwp'),
            text: sprintf(how === 'close' ? __('The Builder has changes on %s that are not saved. Closing drops them. Save them first if you want to keep them.', 'nibwp') : how === 'reload' ? __('The Builder has changes on %s that are not saved. Reloading drops them. Save them first if you want to keep them.', 'nibwp') : __('The Builder has changes on %s that are not saved. Going to another page drops them. Save them first if you want to keep them.', 'nibwp'), names.join(', ')),
            ok: how === 'close' ? __('Close without saving', 'nibwp') : how === 'reload' ? __('Reload without saving', 'nibwp') : __('Leave without saving', 'nibwp'),
            cancel: __('Stay', 'nibwp'),
            icon: how === 'reload' ? 'reload' : 'x',
            danger: true
        }).then(function (yes) {
            if (yes) { hit.forEach(function (f) { f.removeAttribute('data-nst-unsaved'); }); }
            return yes;
        });
    }
    function closeTabs(list, after) {
        builderLeave(list.map(function (x) { return x.frame; }), 'close').then(function (go) {
            if (!go) { return; }
            list.forEach(function (x) { closeTab(x.id); });
            if (after) { after(); }
        });
    }

    // A new tab, shown at once. A link opened from a tab lands beside it and
    // joins its group; full up, the page opens in the tab on screen.
    function openTab(target, opts) {
        opts = opts || {};
        if (tabs.length >= TAB_MAX) {
            navigate(target);
            return activeTabRec();
        }
        var from = activeTabRec();
        var tab = { id: 't' + (++tabSeq), url: target, frame: null, page: null, loading: true, group: opts.beside && from ? from.group || null : null };
        if (opts.beside && from) {
            tabs.splice(tabs.indexOf(from) + 1, 0, tab);
        } else {
            tabs.push(tab);
        }
        activateTab(tab.id);
        return tab;
    }

    function closeTab(id) {
        if (tabs.length < 2) { return; }
        var i = tabs.findIndex(function (t) { return t.id === id; });
        if (i < 0) { return; }
        var tab = tabs[i];
        tabs.splice(i, 1);
        if (tab.id === activeTabId) { activateTab(tabs[Math.min(i, tabs.length - 1)].id); }
        if (tab.frame) { tab.frame.remove(); }
        dropEmptyGroups();
        renderTabs();
        saveTabs();
    }

    function tabLabel(tab) {
        var p = tab.page || {};
        if (p.title) { return p.title; }
        var u = realUrl(tab.url);
        try {
            var parsed = new URL(u);
            return sameSite(u) ? (decodeURI(parsed.pathname) === '/' ? boot.siteName : decodeURI(parsed.pathname)) : parsed.hostname.replace(/^www\./, '');
        } catch (e) {
            return __('New tab', 'nibwp');
        }
    }

    /* Groups: a named, coloured run of tabs. Members always sit together, so
     * a group is drawn as one label followed by its tabs; collapsing it hides
     * all of them but the one on screen. */

    var groups = [];
    var groupSeq = 0;
    var GROUP_COLORS = ['blue', 'green', 'orange', 'pink', 'purple', 'cyan', 'red', 'grey'];

    function groupRec(id) {
        return groups.find(function (g) { return g.id === id; }) || null;
    }

    function groupName(g) {
        return g.name || __('Group', 'nibwp');
    }

    function newGroup() {
        var used = groups.map(function (g) { return g.color; });
        var color = GROUP_COLORS.find(function (c) { return used.indexOf(c) < 0; }) || GROUP_COLORS[groups.length % GROUP_COLORS.length];
        var g = { id: 'g' + (++groupSeq), name: '', color: color, collapsed: false };
        groups.push(g);
        return g;
    }

    // Put a tab into a group, after the group's last tab; null takes it out
    // and leaves it just after the group it was in.
    function setTabGroup(tab, gid) {
        if (!tab || (tab.group || null) === (gid || null)) { return; }
        var old = tab.group;
        tabs.splice(tabs.indexOf(tab), 1);
        tab.group = gid || null;
        var anchor = gid || old;
        var last = -1;
        tabs.forEach(function (t, i) { if (anchor && t.group === anchor) { last = i; } });
        if (last > -1) {
            tabs.splice(last + 1, 0, tab);
        } else {
            tabs.push(tab);
        }
        var g = groupRec(gid);
        if (g) { g.collapsed = false; }
        dropEmptyGroups();
    }

    function dropEmptyGroups() {
        groups = groups.filter(function (g) { return tabs.some(function (t) { return t.group === g.id; }); });
    }

    function moveTab(tab, toIndex, gid) {
        var from = tabs.indexOf(tab);
        if (from < 0) { return; }
        tabs.splice(from, 1);
        if (toIndex > from) { toIndex--; }
        tab.group = gid || null;
        tabs.splice(Math.max(0, Math.min(toIndex, tabs.length)), 0, tab);
        // Keep each group in one run: a member left stranded rejoins its group.
        groups.forEach(function (g) {
            var first = tabs.findIndex(function (t) { return t.group === g.id; });
            var run = tabs.filter(function (t) { return t.group === g.id; });
            tabs = tabs.filter(function (t) { return t.group !== g.id; });
            Array.prototype.splice.apply(tabs, [Math.min(first, tabs.length), 0].concat(run));
        });
        dropEmptyGroups();
    }

    function renderTabs() {
        var many = tabs.length > 1;
        var html = '';
        var current = null;
        tabs.forEach(function (t) {
            var g = groupRec(t.group);
            if (g && current !== g.id) {
                html += '<button type="button" class="nst-tgroup' + (g.collapsed ? ' is-collapsed' : '') + '" data-group="' + esc(g.id) + '" data-color="' + esc(g.color) + '" aria-expanded="' + !g.collapsed + '" title="' + esc(g.collapsed ? __('Show this group', 'nibwp') : __('Fold this group', 'nibwp')) + '">' +
                    (g.name ? '<span>' + esc(g.name) + '</span>' : '<span class="nst-tgroup__dot" aria-label="' + esc(groupName(g)) + '"></span>') +
                    (g.collapsed ? '<span class="nst-tgroup__n">' + tabs.filter(function (x) { return x.group === g.id; }).length + '</span>' : '') + '</button>';
            }
            current = g ? g.id : null;
            var on = t.id === activeTabId;
            if (g && g.collapsed && !on) { return; }
            var ext = t.page ? !!t.page.external : !sameSite(realUrl(t.url));
            var label = tabLabel(t);
            var unsaved = t.frame && t.frame.hasAttribute('data-nst-unsaved');
            html += '<div class="nst-tab' + (on ? ' is-active' : '') + (ext ? ' is-external' : '') + (g ? ' in-group' : '') + (unsaved ? ' has-unsaved' : '') + '"' + (g ? ' data-color="' + esc(g.color) + '"' : '') + ' data-tab="' + esc(t.id) + '" draggable="true"' + (unsaved ? ' data-tip="' + esc(__('Builder changes not saved', 'nibwp')) + '"' : '') + '>' +
                '<button type="button" role="tab" class="nst-tab__btn" data-tab-id="' + esc(t.id) + '" aria-selected="' + on + '" title="' + esc(label + ' · ' + realUrl(t.page && t.page.url ? t.page.url : t.url)) + '">' +
                (t.loading ? '<span class="nst-spin" aria-hidden="true"></span>' : t.fav ? '<img class="nst-tab__fav" src="' + esc(t.fav) + '" alt="" width="16" height="16">' : icon(ext ? 'globe' : 'page', 14)) +
                '<span>' + esc(label) + '</span></button>' +
                (many ? '<button type="button" class="nst-tab__x" data-tab-close="' + esc(t.id) + '" aria-label="' + esc(sprintf(__('Close %s', 'nibwp'), label)) + '">' + icon('x', 12) + '</button>' : '') +
                '</div>';
        });
        tabbar.innerHTML = html +
            '<button type="button" class="nst-ib nst-ib--sm nst-tabbar__new" id="nst-tab-new" aria-label="' + esc(__('New tab', 'nibwp')) + '" data-tip="' + esc(__('New tab', 'nibwp')) + '"' + (tabs.length >= TAB_MAX ? ' disabled' : '') + '>' + icon('plus', 14) + '</button>';
    }

    function saveTabs() {
        var i = tabs.findIndex(function (t) { return t.id === activeTabId; });
        store('nibwp-studio-tabs', JSON.stringify({
            tabs: tabs.map(function (t) { return { u: realUrl(t.page && t.page.url ? t.page.url : t.url), g: t.group || '', f: t.fav || '' }; }),
            groups: groups.map(function (g) { return { id: g.id, name: g.name, color: g.color, collapsed: !!g.collapsed }; }),
            active: Math.max(0, i)
        }));
    }

    // An icon that does not load falls back to the plain page icon.
    tabbar.addEventListener('error', function (e) {
        if (!e.target.classList || !e.target.classList.contains('nst-tab__fav')) { return; }
        var t = tabRec(e.target.closest('[data-tab]').dataset.tab);
        if (t) { t.fav = ''; renderTabs(); }
    }, true);

    tabbar.addEventListener('click', function (e) {
        var x = e.target.closest('[data-tab-close]');
        if (x) { var xt = tabRec(x.dataset.tabClose); if (xt) { closeTabs([xt]); } return; }
        if (e.target.closest('#nst-tab-new')) {
            openTab(boot.home);
            var omni = $('nst-omni');
            omni.focus();
            omni.select();
            return;
        }
        var gb = e.target.closest('[data-group]');
        if (gb) {
            var g = groupRec(gb.dataset.group);
            if (g) {
                g.collapsed = !g.collapsed;
                renderTabs();
                saveTabs();
            }
            return;
        }
        var b = e.target.closest('[data-tab-id]');
        if (b) { activateTab(b.dataset.tabId); }
    });

    tabbar.addEventListener('auxclick', function (e) {
        var b = e.button === 1 && e.target.closest('[data-tab-id]');
        if (b) { e.preventDefault(); closeTab(b.dataset.tabId); }
    });

    /* Drag a tab to reorder it. Dropped beside a grouped tab it joins that
     * group; dropped on a group's label it joins at the front; dropped beside
     * a loose tab it leaves its group. */

    var dragId = null;
    tabbar.addEventListener('dragstart', function (e) {
        var el = e.target.closest && e.target.closest('[data-tab]');
        if (!el) { return; }
        dragId = el.dataset.tab;
        el.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragId); } catch (err) { /* old browsers */ }
    });
    tabbar.addEventListener('dragend', function () {
        dragId = null;
        tabbar.querySelectorAll('.is-dragging, .is-drop-before, .is-drop-after, .is-drop-in').forEach(function (n) { n.classList.remove('is-dragging', 'is-drop-before', 'is-drop-after', 'is-drop-in'); });
    });
    tabbar.addEventListener('dragover', function (e) {
        if (!dragId) { return; }
        var over = e.target.closest('[data-tab], [data-group]');
        tabbar.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-in').forEach(function (n) { n.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-in'); });
        if (!over || over.dataset.tab === dragId) { return; }
        e.preventDefault();
        if (over.dataset.group) {
            over.classList.add('is-drop-in');
            return;
        }
        var r = over.getBoundingClientRect();
        var after = (e.clientX - r.left) > r.width / 2;
        if (document.documentElement.dir === 'rtl') { after = !after; }
        over.classList.add(after ? 'is-drop-after' : 'is-drop-before');
    });
    tabbar.addEventListener('drop', function (e) {
        if (!dragId) { return; }
        e.preventDefault();
        var tab = tabRec(dragId);
        var over = e.target.closest('[data-tab], [data-group]');
        if (tab && over) {
            if (over.dataset.group) {
                var first = tabs.findIndex(function (t) { return t.group === over.dataset.group; });
                moveTab(tab, first, over.dataset.group);
            } else if (over.dataset.tab !== tab.id) {
                var target = tabRec(over.dataset.tab);
                var at = tabs.indexOf(target) + (over.classList.contains('is-drop-after') ? 1 : 0);
                moveTab(tab, at, target.group || null);
            }
            renderTabs();
            saveTabs();
        }
        dragId = null;
    });

    /* Right-click a tab or a group label for everything else. The same menu
     * opens from the keyboard with the context-menu key or Shift+F10. */

    var tabMenu = $('nst-tabmenu');

    function menuItem(act, label, extra) {
        return '<button type="button" role="menuitem" class="nst-tabmenu__item" data-tm="' + act + '"' + (extra || '') + '>' + esc(label) + '</button>';
    }

    function openTabMenu(anchor, x, y) {
        var html = '';
        var tabId = anchor.dataset.tab || anchor.dataset.tabId;
        if (anchor.dataset.group) {
            var g = groupRec(anchor.dataset.group);
            if (!g) { return; }
            html += '<div class="nst-tabmenu__name"><input type="text" id="nst-tm-name" maxlength="40" value="' + esc(g.name) + '" placeholder="' + esc(__('Name this group', 'nibwp')) + '" aria-label="' + esc(__('Group name', 'nibwp')) + '"></div>';
            html += '<div class="nst-tabmenu__colors" role="group" aria-label="' + esc(__('Group colour', 'nibwp')) + '">' + GROUP_COLORS.map(function (c) {
                return '<button type="button" class="nst-swatch' + (c === g.color ? ' is-on' : '') + '" data-tm="color" data-color="' + c + '" aria-label="' + esc(c) + '" aria-pressed="' + (c === g.color) + '"></button>';
            }).join('') + '</div><hr>';
            html += menuItem('tab-in-group', __('New tab in this group', 'nibwp'));
            html += menuItem('fold', g.collapsed ? __('Show this group', 'nibwp') : __('Fold this group', 'nibwp'));
            html += menuItem('ungroup', __('Ungroup', 'nibwp'));
            html += menuItem('close-group', __('Close the group', 'nibwp'));
            tabMenu.dataset.group = g.id;
            delete tabMenu.dataset.tab;
        } else if (tabId) {
            var t = tabRec(tabId);
            if (!t) { return; }
            html += menuItem('reload', __('Reload', 'nibwp'));
            html += menuItem('duplicate', __('Duplicate', 'nibwp'), tabs.length >= TAB_MAX ? ' disabled' : '');
            html += '<hr>' + menuItem('new-group', __('Add to a new group', 'nibwp'));
            groups.forEach(function (gr) {
                if (gr.id !== t.group) {
                    html += '<button type="button" role="menuitem" class="nst-tabmenu__item" data-tm="join" data-group-id="' + esc(gr.id) + '"><span class="nst-swatch nst-swatch--sm" data-color="' + esc(gr.color) + '"></span>' + esc(sprintf(__('Move to %s', 'nibwp'), groupName(gr))) + '</button>';
                }
            });
            if (t.group) { html += menuItem('leave', __('Remove from group', 'nibwp')); }
            html += '<hr>' + menuItem('close', __('Close tab', 'nibwp'), tabs.length < 2 ? ' disabled' : '');
            var at = tabs.indexOf(t);
            html += menuItem('close-left', __('Close tabs to the left', 'nibwp'), at < 1 ? ' disabled' : '');
            html += menuItem('close-right', __('Close tabs to the right', 'nibwp'), at >= tabs.length - 1 ? ' disabled' : '');
            html += menuItem('close-others', __('Close other tabs', 'nibwp'), tabs.length < 2 ? ' disabled' : '');
            tabMenu.dataset.tab = t.id;
            delete tabMenu.dataset.group;
        } else {
            return;
        }
        tabMenu.innerHTML = html;
        tabMenu.hidden = false;
        var stage = $('nst-stage').getBoundingClientRect();
        var w = tabMenu.offsetWidth;
        tabMenu.style.left = Math.max(8, Math.min(x - stage.left, stage.width - w - 8)) + 'px';
        tabMenu.style.top = (y - stage.top + 4) + 'px';
        var focusable = tabMenu.querySelector('input, [data-tm]:not([disabled])');
        if (focusable) { focusable.focus(); }
    }

    function closeTabMenu() {
        if (tabMenu.hidden) { return; }
        var gid = tabMenu.dataset.group;
        var name = $('nst-tm-name');
        if (gid && name) {
            var g = groupRec(gid);
            if (g && g.name !== name.value.trim()) {
                g.name = name.value.trim();
                renderTabs();
                saveTabs();
            }
        }
        tabMenu.hidden = true;
    }

    tabbar.addEventListener('contextmenu', function (e) {
        var el = e.target.closest('[data-tab], [data-group]');
        if (!el) { return; }
        e.preventDefault();
        var r = el.getBoundingClientRect();
        openTabMenu(el, e.clientX || r.left, e.clientX ? e.clientY : r.bottom);
    });

    tabMenu.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); closeTabMenu(); return; }
        if (e.key === 'Enter' && e.target.id === 'nst-tm-name') { e.preventDefault(); closeTabMenu(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            var items = Array.prototype.slice.call(tabMenu.querySelectorAll('[data-tm]:not([disabled]):not(.nst-swatch)'));
            var i = items.indexOf(document.activeElement);
            e.preventDefault();
            var next = items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
            if (next) { next.focus(); }
        }
    });

    tabMenu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tm]');
        if (!b || b.disabled) { return; }
        var t = tabRec(tabMenu.dataset.tab);
        var g = groupRec(tabMenu.dataset.group);
        switch (b.dataset.tm) {
            case 'reload':
                if (t && t.frame) { reloadTabFrame(t, t.frame); }
                break;
            case 'duplicate':
                if (t) {
                    activateTab(t.id);
                    openTab(targetFor(realUrl(t.page && t.page.url ? t.page.url : t.url)) || t.url, { beside: true });
                }
                break;
            case 'new-group':
                if (t) {
                    var made = newGroup();
                    setTabGroup(t, made.id);
                    renderTabs();
                    saveTabs();
                    var label = tabbar.querySelector('[data-group="' + made.id + '"]');
                    if (label) {
                        var r = label.getBoundingClientRect();
                        openTabMenu(label, r.left, r.bottom);
                    }
                    return;
                }
                break;
            case 'join':
                if (t) { setTabGroup(t, b.dataset.groupId); }
                break;
            case 'leave':
                if (t) { setTabGroup(t, null); }
                break;
            case 'close':
                if (t) { closeTabs([t]); }
                break;
            case 'close-left':
            case 'close-right':
                if (t) {
                    var cut = tabs.indexOf(t);
                    var left = b.dataset.tm === 'close-left';
                    closeTabs(tabs.filter(function (x, i) { return left ? i < cut : i > cut; }), function () { activateTab(t.id); });
                }
                break;
            case 'close-others':
                if (t) {
                    closeTabs(tabs.filter(function (x) { return x.id !== t.id; }), function () { activateTab(t.id); });
                }
                break;
            case 'color':
                if (g) {
                    g.color = b.dataset.color;
                    tabMenu.querySelectorAll('.nst-swatch').forEach(function (s) {
                        s.classList.toggle('is-on', s === b);
                        s.setAttribute('aria-pressed', String(s === b));
                    });
                    renderTabs();
                    saveTabs();
                }
                return;
            case 'tab-in-group':
                if (g) {
                    var member = tabs.filter(function (x) { return x.group === g.id; }).pop();
                    if (member) { activateTab(member.id); }
                    openTab(boot.home, { beside: true });
                }
                break;
            case 'fold':
                if (g) { g.collapsed = !g.collapsed; }
                break;
            case 'ungroup':
                if (g) {
                    tabs.forEach(function (x) { if (x.group === g.id) { x.group = null; } });
                    dropEmptyGroups();
                }
                break;
            case 'close-group':
                if (g) {
                    var members = tabs.filter(function (x) { return x.group === g.id; });
                    // The last tab stays open: Agent View always shows a page.
                    closeTabs(members, null);
                    if (members.length === tabs.length && !unsavedIn(members.map(function (x) { return x.frame; })).length) { openTab(boot.home); }
                }
                break;
        }
        closeTabMenu();
        renderTabs();
        saveTabs();
    });

    document.addEventListener('mousedown', function (e) {
        if (!tabMenu.hidden && !tabMenu.contains(e.target)) { closeTabMenu(); }
    }, true);
    window.addEventListener('blur', closeTabMenu);

    wireFrame(tabs[0], frame);

    function renderAddress() {
        var omni = $('nst-omni');
        if (document.activeElement !== omni) {
            var shown = null;
            try { shown = state.page.url ? new URL(stripVersion(state.page.url)) : null; } catch (e) { shown = null; }
            omni.value = !shown ? '' : state.page.external ? decodeURI(shown.host + shown.pathname + shown.search) : decodeURI(shown.pathname + shown.search);
        }
        $('nst-snap').hidden = !state.page.external;
        var edit = $('nst-edit');
        edit.hidden = !state.page.edit || state.page.admin;
        if (state.page.edit) { edit.href = state.page.edit; }
        $('nst-open').href = state.page.url || boot.home;
        document.title = baseTitle = sprintf(__('%s · Agent View', 'nibwp'), state.page.title || boot.siteName);
    }

    $('nst-back').addEventListener('click', function () { try { frame.contentWindow.history.back(); } catch (e) { /* cross-origin */ } });
    $('nst-forward').addEventListener('click', function () { try { frame.contentWindow.history.forward(); } catch (e) { /* cross-origin */ } });
    $('nst-reload').addEventListener('click', function () { reloadFrame(true); });

    function setDevice(device) {
        buddy('device', { device: device });
        state.device = device;
        artboard.dataset.device = device;
        artboard.style.width = artboard.style.height = artboard.style.margin = '';
        // A page the Builder drew smaller to fit is drawn at its own size again.
        artboard.classList.remove('is-zoomed');
        ['--ab-w', '--ab-h', '--ab-s'].forEach(function (p) { artboard.style.removeProperty(p); });
        document.querySelectorAll('.nst-devices [data-device]').forEach(function (b) {
            b.setAttribute('aria-checked', String(b.dataset.device === device));
        });
        store('nibwp-studio-device', device);
        setTimeout(paintPins, 320);
    }
    document.querySelector('.nst-devices').addEventListener('click', function (e) {
        var b = e.target.closest('[data-device]');
        if (b) { setDevice(b.dataset.device); }
    });

    /* Everything Studio adds to the page it shows lives under one class
     * prefix, so reading the page never mistakes it for the site. */
    var FRAME_CSS = [
        '.nst-x-hl{position:absolute;pointer-events:none;z-index:2147483646;border:2px solid #ffd400;background:rgba(255,212,0,.13);border-radius:4px;box-shadow:0 0 0 1px rgba(26,21,0,.35);transition:top .07s,left .07s,width .07s,height .07s}',
        '.nst-x-hl[hidden]{display:none}',
        '.nst-x-tag{position:absolute;left:-2px;bottom:100%;margin-bottom:4px;background:#ffd400;color:#1a1500;font:600 11px/1.2 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;padding:4px 7px;border-radius:4px;white-space:nowrap;max-width:360px;overflow:hidden;text-overflow:ellipsis}',
        '.nst-x-tag.is-below{bottom:auto;top:100%;margin:4px 0 0}',
        '.nst-x-pins{position:absolute;left:0;top:0;width:0;height:0;z-index:2147483645}',
        '.nst-x-box{position:absolute;border:2px dashed #ffd400;border-radius:4px;pointer-events:none}',
        '.nst-x-box.is-sent{border-color:rgba(255,212,0,.5)}',
        '.nst-x-live{position:absolute;left:0;top:0;width:0;height:0;z-index:2147483646;pointer-events:none}',
        '.nst-x-shape{position:absolute!important;display:block!important;max-width:none!important;max-height:none!important;overflow:visible!important;pointer-events:none;filter:none!important}',
        '.nst-x-shape *{stroke-linecap:round!important;stroke-linejoin:round!important;vector-effect:none!important}',
        '.nst-x-shape .i{fill:rgba(239,106,53,.04)!important;stroke:#ef6a35!important;stroke-width:3px!important}',
        '.nst-x-shape.is-pen .i{fill:none!important;stroke-width:3.5px!important}',
        '.nst-x-shape.is-sent{opacity:.5}',
        '.nst-x-pin{position:absolute;width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:13px 13px 13px 2px;background:#ffd400;color:#1a1500;font:700 12px/26px system-ui,-apple-system,"Segoe UI",sans-serif;text-align:center;box-shadow:0 0 0 2px #1a1500,0 4px 12px rgba(0,0,0,.35);cursor:pointer;border:0;padding:0}',
        '.nst-x-pin.is-sent{background:#fff3b0;box-shadow:0 0 0 2px rgba(26,21,0,.55),0 2px 6px rgba(0,0,0,.25)}',
        '.nst-x-pin.is-done{background:#d7f5dd;color:#0d3b1a;box-shadow:0 0 0 2px #0d3b1a,0 2px 6px rgba(0,0,0,.25)}',
        'html.nst-x-proof,html.nst-x-proof *{cursor:crosshair!important}',
        '.nst-x-flash{outline:3px solid #ef6a35!important;outline-offset:3px!important;transition:outline-color .4s}'
    ].join('');

    function prepareFrame(win, doc, tab) {
        var shown = tab && tab.page ? tab.page : state.page;
        if (!doc.getElementById('nst-x-css')) {
            var css = doc.createElement('style');
            css.id = 'nst-x-css';
            css.textContent = FRAME_CSS + (shown.admin ? '' : 'html{margin-top:0!important}#wpadminbar{display:none!important}');
            (doc.head || doc.documentElement).appendChild(css);
            watchPerson(doc);
        }

        // Links stay in Agent View. Another site opens as a snapshot: in a new
        // tab from the person's own pages, in the same tab from a snapshot.
        // Ctrl/Cmd-click, middle-click and target=_blank open a new tab.
        var follow = function (e) {
            if (state.proofing) { return; }
            // The Builder editing the page: a click selects; a link opens nothing (not even a new tab).
            if (window.nibwpBuilder && window.nibwpBuilder.editing && window.nibwpBuilder.editing()) { return; }
            var a = e.target.closest && e.target.closest('a[href]');
            if (!a) { return; }
            // In a snapshot, <base> points at the other site, so "#part" would
            // load it for real; scroll to the part instead.
            var raw = a.getAttribute('href') || '';
            if (shown.external && raw.charAt(0) === '#') {
                e.preventDefault();
                var part = raw.length > 1 && doc.getElementById(decodeURIComponent(raw.slice(1)));
                if (part) { part.scrollIntoView({ behavior: 'smooth' }); }
                return;
            }
            if (!/^https?:/.test(a.href)) { return; }
            var ext = !sameSite(a.href);
            var fresh = e.ctrlKey || e.metaKey || e.button === 1 || a.target === '_blank';
            if (!ext && !fresh) { return; }
            e.preventDefault();
            navigate(a.href, { newTab: fresh || (ext && !shown.external) });
        };
        doc.addEventListener('click', follow, true);
        doc.addEventListener('auxclick', function (e) { if (e.button === 1) { follow(e); } }, true);

        win.addEventListener('beforeunload', function () {
            if (tab) { tab.loading = true; renderTabs(); }
            if (!tab || tab.id === activeTabId) { progress(true); hideHover(); }
        });
        win.addEventListener('resize', debounce(paintPins, 120));

        FRAME_EVENTS.forEach(function (type) {
            doc.addEventListener(type, function (e) { onFrameEvent(e, win, doc); }, true);
        });
        doc.addEventListener('keydown', onKey, true);
        doc.documentElement.classList.toggle('nst-x-proof', state.proofing);
        // A builder's canvas frame loads after the page does.
        doc.addEventListener('load', function (e) {
            if (e.target && e.target.localName === 'iframe') { setTimeout(hookCanvases, 0); }
        }, true);
        setTimeout(hookCanvases, 0);
    }

    var FRAME_EVENTS = ['mousemove', 'click', 'mousedown', 'mouseup', 'pointerdown', 'pointermove', 'pointerup', 'dblclick', 'auxclick', 'submit', 'contextmenu'];

    /* A builder's editor (Etch, Bricks) shows the page in a frame of its own
     * inside the page shown here, so the outer page never sees a click on
     * it. Each such canvas gets the same styles and listeners, and a note
     * made in one remembers which frame ("canvas", a selector in the outer
     * page). Its box and shape are in that frame's own page coordinates. */
    function canvases() {
        var doc = frameDoc();
        if (!doc) { return []; }
        return Array.prototype.filter.call(doc.querySelectorAll('iframe'), function (f) {
            var r = f.getBoundingClientRect();
            try { return r.width > 200 && r.height > 200 && !!(f.contentDocument && f.contentDocument.body); } catch (e) { return false; }
        });
    }

    // The canvas page can be replaced (written anew, or reloaded), taking
    // the styles with it; their absence is the sign to hook it again.
    function hookCanvases() {
        canvases().forEach(function (f) {
            var d = f.contentDocument;
            var w = f.contentWindow;
            if (d.getElementById('nst-x-css')) { return; }
            var css = d.createElement('style');
            css.id = 'nst-x-css';
            css.textContent = FRAME_CSS;
            (d.head || d.documentElement).appendChild(css);
            FRAME_EVENTS.forEach(function (type) {
                w.addEventListener(type, function (e) { onFrameEvent(e, w, d); }, true);
            });
            w.addEventListener('resize', debounce(paintPins, 120));
            d.documentElement.classList.toggle('nst-x-proof', state.proofing);
            paintPins();
        });
    }

    // Every page a note can sit on now: the one shown, then its canvases,
    // each with where its viewport starts inside the shown one.
    function views() {
        var doc = frameDoc();
        if (!doc) { return []; }
        return [{ doc: doc, win: frame.contentWindow, x: 0, y: 0, canvas: '' }].concat(canvases().map(function (f) {
            var r = f.getBoundingClientRect();
            return { doc: f.contentDocument, win: f.contentWindow, x: r.left + f.clientLeft, y: r.top + f.clientTop, canvas: cssPath(f) };
        }));
    }

    function viewOf(canvas) {
        return views().find(function (v) { return v.canvas === (canvas || ''); }) || null;
    }

    function debounce(fn, ms) {
        var t = 0;
        return function () {
            clearTimeout(t);
            t = setTimeout(fn, ms);
        };
    }

    /* ------------------------------------------------------------------
     * Comment mode: the page becomes a proof. Hover shows what a click
     * would pick; ↑ widens it to the parent; a click drops a numbered
     * pin and asks what should change there.
     * ---------------------------------------------------------------- */

    var hover = { el: null, base: null };
    var pick = null;

    // Point picks an element; box, circle and pen draw on the page instead.
    // Either way the mark becomes a numbered note with the same popup.
    var tool = 'point';
    var lastDraw = 'box';
    var TOOL_HINT = {
        point: __('Click anything on the page to leave a note on it.', 'nibwp'),
        box: __('Drag to draw a box. Draw as many marks as you like, then say what they are about.', 'nibwp'),
        circle: __('Drag to draw a circle. Draw as many marks as you like, then say what they are about.', 'nibwp'),
        pen: __('Draw freely on the page. Draw as many marks as you like, then say what they are about.', 'nibwp')
    };
    var TOOL_NAME = { box: __('Box', 'nibwp'), circle: __('Circle', 'nibwp'), pen: __('Drawing', 'nibwp') };

    // What a note is called in lists: its words, or for a mark left without
    // words, what was drawn (the element under a mark can be the whole page).
    function noteLabel(a) {
        return a.note || (a.shape && TOOL_NAME && TOOL_NAME[a.shape.type]) || a.text || a.tag || '';
    }
    var TOOL_KEY = { v: 'point', b: 'box', o: 'circle', p: 'pen' };

    function setTool(t) {
        tool = t;
        if (t !== 'point') { lastDraw = t; }
        document.querySelectorAll('[data-tool]').forEach(function (b) { b.setAttribute('aria-checked', String(b.dataset.tool === t)); });
        $('nst-proof-hint').textContent = TOOL_HINT[t];
        hideHover();
        syncModes();
    }

    function syncModes() {
        $('nst-comment').setAttribute('aria-pressed', String(state.proofing && tool === 'point'));
        $('nst-draw').setAttribute('aria-pressed', String(state.proofing && tool !== 'point'));
    }

    // Comment and Draw are one mode with two starting tools: pressing the
    // lit one again leaves it, pressing the other switches tool.
    function toggleMode(draw) {
        if (state.proofing && (tool !== 'point') === draw) {
            setProofing(false);
            return;
        }
        setTool(draw ? lastDraw : 'point');
        setProofing(true);
    }

    function setProofing(on) {
        state.proofing = !!on;
        buddy('proof', { on: state.proofing, tool: tool });
        root.classList.toggle('is-proofing', state.proofing);
        syncModes();
        $('nst-pin').setAttribute('aria-pressed', String(state.proofing));
        $('nst-proofbar').hidden = !state.proofing;
        // A canvas can come and go while the person comments (a builder
        // swaps its frame), so look for new ones now and then meanwhile.
        clearInterval(setProofing.t);
        if (state.proofing) {
            hookCanvases();
            setProofing.t = setInterval(hookCanvases, 1000);
        }
        views().forEach(function (v) { v.doc.documentElement.classList.toggle('nst-x-proof', state.proofing); });
        if (!state.proofing) {
            hideHover();
            if (!keepMark()) { closeNote(); }
            paintPins();
        } else {
            showPane('site');
            paintPins();
        }
    }

    $('nst-comment').addEventListener('click', function () { toggleMode(false); });
    $('nst-draw').addEventListener('click', function () { toggleMode(true); });
    $('nst-pin').addEventListener('click', function () { setProofing(!state.proofing); });
    $('nst-proof-done').addEventListener('click', function () { setProofing(false); });
    document.querySelector('.nst-tools').addEventListener('click', function (e) {
        var b = e.target.closest('[data-tool]');
        if (b) { setTool(b.dataset.tool); }
    });

    function ours(node) {
        return node && node.closest && node.closest('.nst-x-hl, .nst-x-pins, .nst-x-live');
    }

    function onFrameEvent(e, win, doc) {
        var target = e.target;
        if (target && target.nodeType !== 1) { target = target.parentElement; }
        var sentPin = target && target.closest && target.closest('.nst-x-pin[data-sent]');
        if (sentPin && (e.type === 'click' || e.type === 'pointerdown' || e.type === 'mousedown')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (e.type === 'click') { showNoteInMenu(sentPin.dataset.sent); }
            return;
        }
        if (!state.proofing) { return; }
        if (tool !== 'point') {
            onDraw(e, win, doc, target);
            return;
        }

        if (e.type === 'mousemove') {
            if (!target || ours(target) || pick) { return; }
            hover.base = target;
            showHover(target, doc, win);
            return;
        }

        var pin = target && target.closest && target.closest('.nst-x-pin');
        e.preventDefault();
        e.stopImmediatePropagation();

        if (e.type !== 'click') { return; }
        if (pin) {
            editNote(pin.dataset.key);
            return;
        }
        if (!target || ours(target)) { return; }
        var chosen = hover.el && hover.el.contains(target) ? hover.el : target;
        startNote(chosen, doc, win, e);
    }

    /* Drawing: points are page coordinates of the framed page, so a mark
     * stays on what it was drawn over when the page scrolls. */
    var drawing = null;

    // Pointer events, not mouse ones: cancelling pointerdown (so the page
    // does not react) also stops the browser sending mousemove and mouseup.
    function onDraw(e, win, doc, target) {
        if ((e.type === 'pointermove' || e.type === 'mousemove') && !drawing) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var pin = target && target.closest && target.closest('.nst-x-pin');
        if (pin && !drawing) {
            if (e.type === 'click') { editNote(pin.dataset.key); }
            return;
        }
        if (e.type === 'pointerdown' && e.button === 0) {
            if (pick && !keepMark()) { closeNote(); }
            drawing = { type: tool, pts: [[e.pageX, e.pageY]] };
            try { doc.documentElement.setPointerCapture(e.pointerId); } catch (err) { /* the line still ends on pointerup */ }
        } else if (e.type === 'pointermove') {
            // The button came up outside the page: finish where it left.
            if (!e.buttons) { endDraw(doc, win); return; }
            var last = drawing.pts[drawing.pts.length - 1];
            if (drawing.type !== 'pen') {
                drawing.pts = [drawing.pts[0], [e.pageX, e.pageY]];
            } else if (Math.abs(e.pageX - last[0]) + Math.abs(e.pageY - last[1]) > 3) {
                drawing.pts.push([e.pageX, e.pageY]);
            }
            paintLive(doc);
        } else if (e.type === 'pointerup' && drawing) {
            endDraw(doc, win);
        }
    }

    function areaOf(pts) {
        var xs = pts.map(function (p) { return p[0]; });
        var ys = pts.map(function (p) { return p[1]; });
        var x = Math.min.apply(null, xs);
        var y = Math.min.apply(null, ys);
        return { x: Math.round(x), y: Math.round(y), w: Math.round(Math.max.apply(null, xs) - x), h: Math.round(Math.max.apply(null, ys) - y) };
    }

    // The mark as drawn, relative to its own area. A long freehand line is
    // thinned to 200 points, plenty to redraw it.
    function shapeOf(d) {
        var a = areaOf(d.pts);
        var s = { type: d.type, x: a.x, y: a.y, w: a.w, h: a.h };
        if (d.type === 'pen') {
            var step = Math.ceil(d.pts.length / 200);
            s.pts = d.pts.filter(function (p, i) { return i % step === 0 || i === d.pts.length - 1; }).map(function (p) {
                return [Math.round(p[0] - a.x), Math.round(p[1] - a.y)];
            });
        }
        return s;
    }

    // A freehand line through the midpoints of its points, so it comes out
    // as one smooth stroke instead of a chain of short straight bits.
    function smoothPath(pts) {
        var at = function (p) { return p[0] + ',' + p[1]; };
        var d = 'M' + at(pts[0]);
        for (var i = 1; i < pts.length - 1; i++) {
            d += ' Q' + at(pts[i]) + ' ' + at([(pts[i][0] + pts[i + 1][0]) / 2, (pts[i][1] + pts[i + 1][1]) / 2]);
        }
        return d + (pts.length > 1 ? ' L' + at(pts[pts.length - 1]) : '');
    }

    // One clean orange line, no shadow, the faintest tint inside so the page
    // under a mark stays readable.
    function shapeSvg(s, x, y, cls) {
        var pad = 6;
        var w = s.w + pad * 2;
        var h = s.h + pad * 2;
        var inner;
        if (s.type === 'circle') {
            inner = '<ellipse cx="' + (w / 2) + '" cy="' + (h / 2) + '" rx="' + Math.max(1, s.w / 2) + '" ry="' + Math.max(1, s.h / 2) + '"';
        } else if (s.type === 'pen') {
            inner = '<path d="' + smoothPath((s.pts || [[0, 0]]).map(function (p) { return [p[0] + pad, p[1] + pad]; })) + '"';
        } else {
            inner = '<rect x="' + pad + '" y="' + pad + '" width="' + s.w + '" height="' + s.h + '" rx="' + Math.min(10, s.w / 4, s.h / 4) + '"';
        }
        return '<svg class="nst-x-shape is-' + s.type + ' ' + cls + '" style="left:' + (x - pad) + 'px;top:' + (y - pad) + 'px;width:' + w + 'px;height:' + h + 'px" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true">' + inner + ' class="i"/></svg>';
    }

    function paintLive(doc) {
        var live = doc.querySelector('.nst-x-live');
        if (!live) {
            live = doc.createElement('div');
            live.className = 'nst-x-live';
            doc.documentElement.appendChild(live);
        }
        live.innerHTML = drawing && drawing.pts.length > 1 ? shapeSvg(shapeOf(drawing), areaOf(drawing.pts).x, areaOf(drawing.pts).y, '') : '';
    }

    function endDraw(doc, win) {
        var d = drawing;
        drawing = null;
        paintLive(doc);
        var s = shapeOf(d);
        if (s.w < 8 && s.h < 8) { return; }
        startNote(holder(doc, win, s), doc, win, null, s);
    }

    // The smallest element on the page that holds the whole mark, found from
    // the element under its middle and walking out.
    function holder(doc, win, s) {
        var cx = s.x + s.w / 2 - win.scrollX;
        var cy = s.y + s.h / 2 - win.scrollY;
        var node = (doc.elementsFromPoint(cx, cy) || []).find(function (n) { return !ours(n) && n !== doc.documentElement; });
        while (node && node !== doc.body) {
            var r = node.getBoundingClientRect();
            var x = r.left + win.scrollX;
            var y = r.top + win.scrollY;
            if (x <= s.x + 2 && y <= s.y + 2 && x + r.width >= s.x + s.w - 2 && y + r.height >= s.y + s.h - 2) { return node; }
            node = node.parentElement;
        }
        return doc.body;
    }

    function hl(doc) {
        var box = doc.querySelector('.nst-x-hl');
        if (!box) {
            box = doc.createElement('div');
            box.className = 'nst-x-hl';
            box.innerHTML = '<span class="nst-x-tag"></span>';
            doc.documentElement.appendChild(box);
        }
        return box;
    }

    function describe(node) {
        var d = node.localName;
        if (node.id) { d += '#' + node.id; }
        else {
            var c = Array.prototype.filter.call(node.classList, function (x) { return !/^nst-x-/.test(x); }).slice(0, 2);
            if (c.length) { d += '.' + c.join('.'); }
        }
        var w = Math.round(node.getBoundingClientRect().width);
        var h = Math.round(node.getBoundingClientRect().height);
        return d + '  ' + w + '×' + h;
    }

    function showHover(node, doc, win) {
        if (hover.el && hover.el.ownerDocument !== doc) { hideHover(); }
        hover.el = node;
        var r = node.getBoundingClientRect();
        var box = hl(doc);
        box.hidden = false;
        box.style.top = (r.top + win.scrollY) + 'px';
        box.style.left = (r.left + win.scrollX) + 'px';
        box.style.width = r.width + 'px';
        box.style.height = r.height + 'px';
        var tag = box.firstChild;
        tag.textContent = describe(node);
        tag.classList.toggle('is-below', r.top < 26);
    }

    function hideHover() {
        views().forEach(function (v) {
            var box = v.doc.querySelector('.nst-x-hl');
            if (box) { box.hidden = true; }
        });
        hover.el = null;
    }

    function widen(up) {
        if (!hover.el) { return; }
        var doc = hover.el.ownerDocument;
        var next = up ? hover.el.parentElement : null;
        if (!up && hover.base && hover.base !== hover.el) {
            next = hover.base;
            while (next.parentElement && next.parentElement !== hover.el) { next = next.parentElement; }
        }
        if (next && next !== doc.documentElement && next !== doc.body) {
            showHover(next, doc, doc.defaultView);
        }
    }

    function cssPath(node) {
        var doc = node.ownerDocument;
        var parts = [];
        var cur = node;
        while (cur && cur.nodeType === 1 && cur !== doc.documentElement) {
            if (cur.id && /^[A-Za-z][\w-]*$/.test(cur.id) && doc.querySelectorAll('#' + CSS.escape(cur.id)).length === 1) {
                parts.unshift('#' + CSS.escape(cur.id));
                break;
            }
            var part = cur.localName;
            if (cur === doc.body) {
                parts.unshift('body');
                break;
            }
            var cls = Array.prototype.filter.call(cur.classList, function (c) {
                return /^[A-Za-z][\w-]{1,40}$/.test(c) && !/^(is-|has-|nst-x-|wp-elements-)/.test(c);
            }).slice(0, 2);
            if (cls.length) { part += '.' + cls.map(function (c) { return CSS.escape(c); }).join('.'); }
            var parent = cur.parentElement;
            if (parent) {
                // The Builder's wireframes and drop placeholders are not on the
                // live page: counted, every path after them would be one off there.
                var same = Array.prototype.filter.call(parent.children, function (c) {
                    return c.localName === cur.localName && (c === cur || typeof c.className !== 'string' || !/(^|\s)nst-x-bd-(frame|sketch)(\s|$)/.test(c.className));
                });
                if (same.length > 1) { part += ':nth-of-type(' + (same.indexOf(cur) + 1) + ')'; }
            }
            parts.unshift(part);
            cur = parent;
        }
        return parts.join(' > ');
    }

    function hintsFor(node) {
        var h = {};
        var etch = node.closest('[data-etch-element]');
        if (etch) { h.etch = etch.getAttribute('data-etch-element') || 'element'; }
        var block = node.closest('[class*="wp-block-"]');
        if (block) {
            var bc = Array.prototype.find.call(block.classList, function (c) { return c.indexOf('wp-block-') === 0; });
            if (bc) { h.block = bc; }
        }
        var bricks = node.closest('[id^="brxe-"]');
        if (bricks) { h.bricks = bricks.id; }
        var elem = node.closest('[data-element_type]');
        if (elem) { h.elementor = (elem.getAttribute('data-widget_type') || elem.getAttribute('data-element_type')) + ' ' + elem.getAttribute('data-id'); }
        var section = node.parentElement && node.parentElement.closest('[id]');
        if (section && section !== node.ownerDocument.body) { h.inside = '#' + section.id; }
        if (node.matches('a[href]')) { h.href = node.getAttribute('href'); }
        if (node.matches('img')) { h.src = node.currentSrc || node.src; h.alt = node.getAttribute('alt') || ''; }
        return h;
    }

    function startNote(node, doc, win, ev, shape) {
        keepMark();
        var r = node.getBoundingClientRect();
        var html = node === doc.body ? '' : (node.outerHTML || '');
        pick = {
            key: uid(),
            node: node,
            selector: cssPath(node),
            tag: node.localName,
            text: clip(node.innerText || node.textContent || node.getAttribute('alt') || '', 400),
            html: html.length > 3000 ? html.slice(0, 3000) + '…' : html,
            box: { x: Math.round(r.left + win.scrollX), y: Math.round(r.top + win.scrollY), w: Math.round(r.width), h: Math.round(r.height) },
            hints: hintsFor(node),
            page_url: state.page.url,
            // Made inside a builder's canvas: the frame it is in.
            canvas: doc !== frameDoc() && win.frameElement ? cssPath(win.frameElement) : '',
            at: ev ? { dx: Math.round(ev.clientX - r.left), dy: Math.round(ev.clientY - r.top) } : null,
            note: ''
        };
        if (shape) {
            // Placed inside its element so it follows that element; the pin
            // sits on the mark's top right corner.
            shape.dx = shape.x - pick.box.x;
            shape.dy = shape.y - pick.box.y;
            pick.shape = shape;
            pick.at = { dx: shape.dx + shape.w, dy: shape.dy };
        }
        hideHover();
        paintPins();
        openNote(pick, noteNo(draft.annotations.length), false);
    }

    function editNote(key) {
        var a = draft.annotations.find(function (x) { return x.key === key; });
        if (!a) { return; }
        if (pick !== a) { keepMark(); }
        pick = a;
        openNote(a, noteNo(draft.annotations.indexOf(a)), true);
    }

    function openNote(a, n, editing) {
        var pop = $('nst-note');
        pop.dataset.editing = editing ? '1' : '';
        $('nst-note-n').textContent = n;
        $('nst-note-what').textContent = (a.shape ? TOOL_NAME[a.shape.type] + ' · ' : '') + a.tag + (a.text ? ' · “' + clip(a.text, 46) + '”' : '');
        $('nst-note-text').value = a.note || '';
        // Add another is the plus alone (its tooltip says what it does); Save note keeps its words.
        var add = $('nst-note-add');
        add.innerHTML = icon(editing ? 'check' : 'plus', 15);
        add.setAttribute('aria-label', editing ? __('Save note', 'nibwp') : __('Add another', 'nibwp'));
        add.setAttribute('data-tip', editing ? __('Save note', 'nibwp') : __('Add another', 'nibwp'));
        $('nst-note-cancel').hidden = !editing;
        pop.hidden = false;
        placeNote(a);
        setTimeout(function () { $('nst-note-text').focus(); }, 20);
    }

    function placeNote(a) {
        var pop = $('nst-note');
        var stage = $('nst-stage').getBoundingClientRect();
        var v = viewOf(a.canvas) || { win: frame.contentWindow, x: 0, y: 0 };
        var win = v.win;
        var fr = frame.getBoundingClientRect();
        // The Builder may draw a wide page smaller to fit: k is that scale.
        var k = frame.offsetWidth ? fr.width / frame.offsetWidth : 1;
        // A drawing: beside the drawing itself, not the (often page-wide) element that holds it.
        var b = a.shape ? { x: a.box.x + a.shape.dx, y: a.box.y + a.shape.dy, w: a.shape.w, h: a.shape.h } : a.box;
        var sx = fr.left + (v.x + b.x - win.scrollX) * k - stage.left;
        var sy = fr.top + (v.y + b.y - win.scrollY) * k - stage.top;
        var w = pop.offsetWidth || 320;
        var h = pop.offsetHeight || 170;
        // Below it; above when there is no room; beside it when it is too tall for either.
        var top = sy + b.h * k + 10;
        var left = sx;
        if (top + h > stage.height - 12) {
            if (sy - h - 10 >= 12) { top = sy - h - 10; }
            else {
                top = Math.min(Math.max(12, sy), stage.height - h - 12);
                left = sx + b.w * k + 10 + w <= stage.width - 12 ? sx + b.w * k + 10 : sx - w - 10;
            }
        }
        left = Math.max(12, Math.min(left, stage.width - w - 12));
        pop.style.top = Math.max(12, top) + 'px';
        pop.style.left = left + 'px';
    }

    function closeNote() {
        $('nst-note').hidden = true;
        pick = null;
        paintPins();
    }

    // The note box moves by its top line, anywhere over the page.
    (function () {
        var pop = $('nst-note');
        var head = pop.querySelector('.nst-note__head');
        head.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) { return; }
            e.preventDefault();
            var stage = $('nst-stage').getBoundingClientRect();
            var dx = e.clientX - pop.offsetLeft;
            var dy = e.clientY - pop.offsetTop;
            head.setPointerCapture(e.pointerId);
            pop.classList.add('is-dragging');
            var move = function (ev) {
                pop.style.left = Math.max(0, Math.min(ev.clientX - dx, stage.width - pop.offsetWidth)) + 'px';
                pop.style.top = Math.max(0, Math.min(ev.clientY - dy, stage.height - pop.offsetHeight)) + 'px';
            };
            var up = function () {
                head.removeEventListener('pointermove', move);
                head.removeEventListener('pointerup', up);
                head.removeEventListener('pointercancel', up);
                pop.classList.remove('is-dragging');
                $('nst-note-text').focus();
            };
            head.addEventListener('pointermove', move);
            head.addEventListener('pointerup', up);
            head.addEventListener('pointercancel', up);
        });
    })();

    // A drawn mark is kept as soon as the next one starts, or the box is
    // closed, with or without words of its own: draw several, then say once
    // what they are about. True when there was a mark to keep.
    function keepMark() {
        if (!pick || !pick.shape) { return false; }
        var text = $('nst-note-text').value.trim();
        if (draft.annotations.indexOf(pick) === -1) {
            pick.note = text;
            delete pick.node;
            draft.annotations.push(pick);
        } else if (text) {
            pick.note = text;
        }
        closeNote();
        renderChips();
        return true;
    }

    // sending: words must be somewhere, on this note, another one or in the
    // message box. A mark may go without its own until then.
    function commitNote(sending) {
        if (!pick) { return false; }
        var text = $('nst-note-text').value.trim();
        var said = sending !== true || input.value.trim() || draft.annotations.some(function (a) { return a.note && a !== pick; });
        if (!text && !(pick.shape && said)) {
            $('nst-note-text').focus();
            toast(__('Write what should change here first.', 'nibwp'));
            return false;
        }
        pick.note = text;
        if (draft.annotations.indexOf(pick) === -1) {
            delete pick.node;
            draft.annotations.push(pick);
        }
        closeNote();
        renderChips();
        return true;
    }

    // One line, whatever the mode and the language: when the buttons do not fit, the
    // mode button keeps only its icon (its colour and tooltip still say the mode).
    // Re-checked whenever a button changes size, so a new mode or label refits it.
    function fitRow(row) {
        row.classList.remove('is-tight');
        if (row.scrollWidth > row.clientWidth + 1) { row.classList.add('is-tight'); }
    }
    function watchRow(row) {
        if (!window.ResizeObserver) { return; }
        var ro = new ResizeObserver(function () { fitRow(row); });
        ro.observe(row);
        Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) { ro.observe(b); });
    }
    watchRow(document.querySelector('.nst-note__row'));
    $('nst-note-add').addEventListener('click', commitNote);
    $('nst-note-send').addEventListener('click', sendNote);
    $('nst-note-x').addEventListener('click', closeNote);
    $('nst-note-cancel').addEventListener('click', function () {
        if ($('nst-note').dataset.editing && pick) {
            var k = pick.key;
            draft.annotations = draft.annotations.filter(function (x) { return x.key !== k; });
            renderChips();
        }
        closeNote();
    });
    // A note is a request: Enter sends it to the agent straight away, with
    // anything already typed in the prompt box. Ctrl/⌘+Enter keeps it and
    // goes on pointing, to send several notes as one message.
    $('nst-note-text').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            commitNote();
        } else if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            sendNote();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            if (!keepMark()) { closeNote(); }
        }
    });

    // Each page shown (the page, and any builder canvas in it) carries the
    // pins of the notes made on it.
    function paintPins() {
        views().forEach(paintPinsIn);
    }

    function paintPinsIn(v) {
        var doc = v.doc;
        var win = v.win;
        var layer = doc.querySelector('.nst-x-pins');
        // Out of comment mode the page is just the page.
        if (!state.proofing) {
            if (layer) { layer.innerHTML = ''; }
            return;
        }
        if (!layer) {
            layer = doc.createElement('div');
            layer.className = 'nst-x-pins';
            doc.documentElement.appendChild(layer);
        }
        var here = stripHash(state.page.url);
        var html = '';
        var place = function (a, n, cls) {
            if ((a.canvas || '') !== v.canvas) { return; }
            var node = null;
            try { node = a.selector ? doc.querySelector(a.selector) : null; } catch (e) { node = null; }
            var box = a.box;
            if (node) {
                var r = node.getBoundingClientRect();
                box = { x: r.left + win.scrollX, y: r.top + win.scrollY, w: r.width, h: r.height };
            }
            if (!box) { return; }
            // The pin sits where the click landed, kept inside the page so a
            // full-width element does not push it off the edge.
            var at = a.at || (a.shape ? { dx: a.shape.dx + a.shape.w, dy: a.shape.dy } : null);
            var px = box.x + (at ? Math.min(at.dx, box.w) : box.w - 14);
            var py = box.y + (at ? Math.min(at.dy, box.h) : 14);
            px = Math.max(win.scrollX + 16, Math.min(px, win.scrollX + win.innerWidth - 16));
            html += a.shape
                ? shapeSvg(a.shape, box.x + a.shape.dx, box.y + a.shape.dy, cls)
                : '<div class="nst-x-box ' + cls + '" style="left:' + box.x + 'px;top:' + box.y + 'px;width:' + box.w + 'px;height:' + box.h + 'px"></div>';
            html += '<button type="button" class="nst-x-pin ' + cls + '" data-key="' + esc(a.key || '') + '"' + (a.draft === undefined && !a.key ? ' data-sent="n:' + esc(a.n) + '"' : '') + ' style="left:' + px + 'px;top:' + py + 'px" title="' + esc(a.note || '') + '">' + n + '</button>';
        };
        (showAllNotes ? sentNotes() : []).forEach(function (a) {
            if (stripHash(a.page_url) === here) { place(a, a.n, a.state === 'done' ? 'is-sent is-done' : 'is-sent'); }
        });
        draft.annotations.forEach(function (a, i) {
            if (stripHash(a.page_url) === here) { place(a, noteNo(i), ''); }
        });
        if (pick && draft.annotations.indexOf(pick) === -1 && stripHash(pick.page_url) === here) {
            place(pick, noteNo(draft.annotations.length), '');
        }
        layer.innerHTML = html;
    }

    function flash(selector, canvas) {
        var view = viewOf(canvas);
        var doc = view && view.doc;
        if (!doc || !selector) { return; }
        var node = null;
        try { node = doc.querySelector(selector); } catch (e) { node = null; }
        if (!node) {
            toast(__('That element is not on the page shown now.', 'nibwp'));
            return;
        }
        showPane('site');
        node.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        node.classList.add('nst-x-flash');
        setTimeout(function () { node.classList.remove('nst-x-flash'); }, 2600);
    }

    /* All comments: every note in this thread, on any page, newest first.
     * Picking one goes to its page and shows it. "Show all comments" off
     * leaves only the notes not sent yet on the page. */
    var NOTE_STATE = { draft: __('Not sent yet', 'nibwp'), queued: __('Waiting for your agent', 'nibwp'), claimed: __('Your agent is on it', 'nibwp'), done: __('Done', 'nibwp') };

    function allNotes() {
        return draft.annotations.map(function (a, i) { return Object.assign({}, a, { draft: true, n: noteNo(i) }); })
            .concat(sentNotes())
            .sort(function (a, b) { return b.n - a.n; });
    }

    function noteId(a) {
        return a.draft ? 'k:' + a.key : 'n:' + a.n;
    }

    function notePlace(url) {
        try {
            var u = new URL(url);
            return sameSite(url) ? (decodeURI(u.pathname) === '/' ? boot.siteName : decodeURI(u.pathname)) : u.hostname;
        } catch (e) {
            return '';
        }
    }

    function renderNotesMenu() {
        var list = allNotes();
        $('nst-notes-n').textContent = list.length;
        $('nst-notes-all').setAttribute('aria-checked', String(showAllNotes));
        if ($('nst-notes-menu').hidden) { return; }
        $('nst-notes-list').innerHTML = list.length ? list.map(function (a) {
            var st = a.draft ? 'draft' : a.state;
            var acts = a.draft
                ? noteBtn('edit', a, 'pen', __('Edit', 'nibwp')) + noteBtn('remove', a, 'trash', __('Remove', 'nibwp'))
                : noteBtn('resend', a, 'pen', __('Edit and send again', 'nibwp')) + noteBtn('hide', a, 'eyeoff', __('Hide this comment', 'nibwp'));
            return '<div class="nst-notes__item" data-row="' + esc(noteId(a)) + '"><button type="button" role="menuitem" class="nst-notes__row" data-note="' + esc(noteId(a)) + '">' +
                '<span class="nst-notes__pin" data-state="' + esc(st) + '">' + esc(a.n) + '</span>' +
                '<span class="nst-notes__body"><span class="nst-notes__text">' + (a.shape ? icon(a.shape.type === 'box' ? 'square' : a.shape.type, 12) : '') + esc(clip(noteLabel(a), 80)) + '</span>' +
                '<span class="nst-notes__meta">' + esc([notePlace(a.page_url), NOTE_STATE[st] || st].filter(Boolean).join(' · ')) + '</span></span></button>' +
                '<span class="nst-notes__acts">' + acts + '</span></div>';
        }).join('') : '<p class="nst-notes__none">' + esc(__('No comments yet. Press C and click the page, or D to draw on it.', 'nibwp')) + '</p>';
    }

    function setNotesMenu(open) {
        $('nst-notes-menu').hidden = !open;
        $('nst-notes-btn').setAttribute('aria-expanded', String(open));
        if (open) { renderNotesMenu(); }
    }

    function goToNote(a) {
        if (!state.proofing) { setTool('point'); setProofing(true); }
        var show = function () {
            flash(a.selector, a.canvas);
            if (a.draft) { setTimeout(function () { editNote(a.key); }, 450); }
        };
        if (!a.draft && !showAllNotes) {
            showAllNotes = true;
            store('nibwp-studio-allnotes', '1');
            renderNotesMenu();
        }
        if (stripHash(a.page_url) === stripHash(state.page.url)) {
            paintPins();
            show();
            return;
        }
        navigate(a.page_url);
        var tries = 0;
        var wait = setInterval(function () {
            var tab = activeTabRec();
            if (++tries > 60) { clearInterval(wait); return; }
            if (stripHash(state.page.url) === stripHash(a.page_url) && frameDoc() && !(tab && tab.loading)) {
                clearInterval(wait);
                show();
            }
        }, 200);
    }

    $('nst-notes-btn').addEventListener('click', function () { setNotesMenu($('nst-notes-menu').hidden); });
    document.addEventListener('click', function (e) {
        if (!$('nst-notes-menu').hidden && !e.target.closest('.nst-notes')) { setNotesMenu(false); }
    });
    $('nst-notes-all').addEventListener('click', function () {
        showAllNotes = !showAllNotes;
        store('nibwp-studio-allnotes', showAllNotes ? '1' : '0');
        // Comments show in comment mode: asking to see them all turns it on.
        if (showAllNotes && !state.proofing) { setTool('point'); setProofing(true); }
        renderNotesMenu();
        paintPins();
    });
    function noteBtn(act, a, ic, label) {
        return '<button type="button" class="nst-ib nst-ib--sm" data-note-act="' + act + '" data-id="' + esc(noteId(a)) + '" aria-label="' + esc(label) + '" data-tip="' + esc(label) + '">' + icon(ic, 14) + '</button>';
    }

    // A sent comment's own record, inside its message.
    function sentRecord(a) {
        var it = state.items[a.msg];
        return it && ((it.data || {}).annotations || []).find(function (x) { return +x.n === +a.n; });
    }

    function hideNote(a, quiet) {
        var rec = sentRecord(a);
        if (!rec) { return Promise.resolve(); }
        rec.hidden = true;
        renderNotesMenu();
        paintPins();
        return api('note', { method: 'POST', body: { id: a.msg, n: a.n, hidden: true } }).then(function () {
            if (!quiet) { toast(__('Comment hidden.', 'nibwp'), 'ok'); }
        }, function (err) {
            rec.hidden = false;
            renderNotesMenu();
            paintPins();
            failed(err);
        });
    }

    // Edit and send again: a new note on the same spot with the same words,
    // ready to change. The old one hides once the new one is sent.
    function resendNote(a) {
        var copy = {
            key: uid(), selector: a.selector, tag: a.tag, text: a.text, html: a.html, box: a.box, hints: a.hints,
            page_url: a.page_url, canvas: a.canvas || '', at: a.at, note: a.note || '',
            shape: a.shape ? JSON.parse(JSON.stringify(a.shape)) : undefined,
            replaces: { msg: a.msg, n: a.n }
        };
        draft.annotations.push(copy);
        renderChips();
        goToNote(Object.assign({ draft: true }, copy));
    }

    function noteAct(act, id) {
        var a = allNotes().find(function (x) { return noteId(x) === id; });
        if (!a) { return; }
        if (act === 'hide') { hideNote(a); return; }
        setNotesMenu(false);
        if (act === 'resend') { resendNote(a); return; }
        if (act === 'edit') { goToNote(a); return; }
        if (act === 'remove') {
            draft.annotations = draft.annotations.filter(function (x) { return x.key !== a.key; });
            renderChips();
            paintPins();
            renderNotesMenu();
        }
    }

    // A sent pin on the page opens the list at its comment.
    function showNoteInMenu(id) {
        setNotesMenu(true);
        var row = $('nst-notes-list').querySelector('[data-row="' + id + '"]');
        if (!row) { return; }
        row.scrollIntoView({ block: 'nearest' });
        row.classList.add('is-hit');
        setTimeout(function () { row.classList.remove('is-hit'); }, 1600);
    }

    $('nst-notes-list').addEventListener('click', function (e) {
        var act = e.target.closest('[data-note-act]');
        if (act) {
            // The list redraws; the click must not then look like one outside it.
            e.stopPropagation();
            noteAct(act.dataset.noteAct, act.dataset.id);
            return;
        }
        var b = e.target.closest('[data-note]');
        if (!b) { return; }
        var a = allNotes().find(function (x) { return noteId(x) === b.dataset.note; });
        setNotesMenu(false);
        if (a) { goToNote(a); }
    });

    /* ------------------------------------------------------------------
     * The address bar: search the install, or type a path.
     * ---------------------------------------------------------------- */

    var omni = $('nst-omni');
    var omniMenu = $('nst-omni-menu');
    var omniItems = [];
    var omniIndex = -1;
    var omniSeq = 0;
    var omniMoved = false;
    var omniTyped = false;
    var omniOpen = {};
    var pageCache = {};
    var shortcuts = boot.shortcuts || [];

    function typeIcon(row) {
        if (row.kind === 'nibwp') { return '<img class="nst-omni__logo" src="' + esc(boot.icon) + '" alt="" width="15" height="15">'; }
        var named = { go: 'forward', plugin: 'plug', content: 'file', site: 'screen', admin: 'screen', starter: row.icon || 'spark', workflow: 'workflow', skill: 'skill', integration: 'tool', more: 'chevdown' }[row.kind];
        return icon(named || (row.type === 'page' ? 'page' : 'file'), 15);
    }

    function statusLabel(s) {
        return { draft: __('Draft', 'nibwp'), pending: __('Pending', 'nibwp'), private: __('Private', 'nibwp'), future: __('Scheduled', 'nibwp') }[s] || '';
    }

    // pending: pages are still on their way.
    function drawOmni(rows, pending) {
        // A redraw keeps the row the person arrowed to.
        var was = omniMoved && omniItems[omniIndex] ? omniItems[omniIndex].uid : null;
        omniItems = rows;
        omniIndex = rows.length ? 0 : -1;
        if (was) {
            var keep = rows.findIndex(function (r) { return r.uid === was; });
            if (keep > -1) { omniIndex = keep; } else { omniMoved = false; }
        }
        if (!rows.length) {
            omniMenu.innerHTML = '<p class="nst-omni__none">' + esc(pending ? __('Searching…', 'nibwp') : __('Nothing found on this site.', 'nibwp')) + '</p>';
        } else {
            var group = null;
            omniMenu.innerHTML = rows.map(function (r, i) {
                var head = '';
                if (r.group !== group) {
                    group = r.group;
                    head = group ? '<p class="nst-omni__h">' + esc(group) + '</p>' : '';
                }
                var status = statusLabel(r.status);
                return head + '<button type="button" role="option" class="nst-omni__item' + (r.more ? ' nst-omni__more' : '') + '" id="nst-omni-' + i + '" data-i="' + i + '" aria-selected="' + (i === omniIndex) + '"' + (r.tip ? ' title="' + esc(r.tip) + '"' : '') + '>' +
                    typeIcon(r) +
                    '<span class="nst-omni__title">' + esc(r.title) + '</span>' +
                    (status ? '<span class="nst-pill">' + esc(status) + '</span>' : '') +
                    (r.front ? '<span class="nst-pill">' + esc(__('Home', 'nibwp')) + '</span>' : '') +
                    (r.added ? '<span class="nst-pill">' + esc(__('Added', 'nibwp')) + '</span>' : '') +
                    '<span class="nst-omni__meta">' + esc(r.meta || '') + '</span></button>';
            }).join('');
        }
        omniMenu.hidden = false;
        omni.setAttribute('aria-expanded', 'true');
        markOmni();
    }

    function markOmni() {
        omniMenu.querySelectorAll('[role="option"]').forEach(function (b, i) { b.setAttribute('aria-selected', String(i === omniIndex)); });
        if (omniIndex < 0) { omni.removeAttribute('aria-activedescendant'); return; }
        omni.setAttribute('aria-activedescendant', 'nst-omni-' + omniIndex);
        var cur = $('nst-omni-' + omniIndex);
        if (cur && omniMoved) { cur.scrollIntoView({ block: 'nearest' }); }
    }

    function closeOmni() {
        omniMenu.hidden = true;
        omni.setAttribute('aria-expanded', 'false');
        omni.removeAttribute('aria-activedescendant');
    }

    // Every typed word must appear in what is searched; a name that starts
    // with the first word scores best. -1: not a match.
    function scoreOf(words, title, more) {
        var t = String(title || '').toLowerCase();
        var hay = t + ' ' + String(more || '').toLowerCase();
        if (!words.every(function (w) { return hay.indexOf(w) > -1; })) { return -1; }
        return t === words.join(' ') ? 0 : t.indexOf(words[0]) === 0 ? 1 : t.indexOf(words[0]) > -1 ? 2 : 3;
    }

    // Everything the bar offers, a section at a time: on click each shows
    // its usual few; typed words filter them and the best section leads.
    function omniSections(res, q) {
        var words = q.toLowerCase().split(/\s+/).filter(Boolean);
        var typed = words.length > 0;
        var secs = [];
        var add = function (key, title, rows, keepOrder) {
            if (typed) {
                rows.forEach(function (r) { r.score = scoreOf(words, r.title, r.hay); });
                rows = rows.filter(function (r) { return r.score > -1; });
                if (!keepOrder) { rows.sort(function (a, b) { return a.score - b.score; }); }
            }
            if (rows.length) {
                secs.push({ key: key, title: title, rows: rows, best: Math.min.apply(null, rows.map(function (r) { return r.score || 0; })) });
            }
        };
        var pages = ((res && res.items) || []).map(function (p) {
            return { title: p.title, url: p.url, type: p.type, status: p.status, front: p.front, meta: p.type_label, hay: p.type_label };
        });
        // The server already matched these, in the words or in the text.
        if (typed) { pages.forEach(function (p) { p.hay = q; }); }
        add('pages', typed ? __('On this site', 'nibwp') : __('Recently changed', 'nibwp'), pages, true);

        var bucket = { site: [], content: [], plugins: [], nibwp: [] };
        shortcuts.forEach(function (x) {
            if (!typed && !x.top) { return; }
            var key = x.kind === 'admin' ? 'site' : x.kind === 'plugin' ? 'plugins' : x.kind;
            if (!bucket[key]) { return; }
            bucket[key].push({ kind: x.kind, title: x.title, url: x.url, meta: typed ? x.meta : '', hay: x.meta + ' ' + x.group });
        });
        add('site', __('Jump to', 'nibwp'), bucket.site);
        add('starters', __('Start from', 'nibwp'), (boot.starters || []).map(function (s) {
            return { kind: 'starter', icon: s.icon, title: s.label, meta: s.hint, hay: s.hint, starter: s };
        }));
        add('workflows', __('Workflows', 'nibwp'), (boot.workflows || []).map(function (w) {
            return { kind: 'workflow', id: w.slug, title: w.title, tip: w.summary, hay: w.slug, added: draft.workflows.indexOf(w.slug) > -1 };
        }));
        add('skills', __('Skills', 'nibwp'), (boot.skills || []).filter(function (s) { return s.ready; }).map(function (s) {
            return { kind: 'skill', id: s.id, title: s.name, meta: clip(s.tagline, 48), tip: s.tagline, hay: s.tagline + ' ' + s.id, added: draft.skills.indexOf(s.id) > -1 };
        }));
        add('content', __('Content', 'nibwp'), bucket.content);
        add('plugins', __('Plugins', 'nibwp'), bucket.plugins);
        add('integrations', __('Integrations', 'nibwp'), (boot.integrations || []).map(function (x) {
            return { kind: 'integration', id: x.key, title: x.name, meta: x.on ? '' : __('Off', 'nibwp'), hay: x.key };
        }));
        add('nibwp', boot.brand || 'NibWP', bucket.nibwp);

        if (typed) {
            // Equally good matches: places first, then what goes in a message.
            var order = ['pages', 'site', 'content', 'plugins', 'nibwp', 'integrations', 'starters', 'workflows', 'skills'];
            secs.sort(function (a, b) { return a.best - b.best || order.indexOf(a.key) - order.indexOf(b.key); });
        }
        return secs;
    }

    // res: page results, or null while they are on their way.
    function rowsFrom(res, q) {
        var rows = [];
        var addr = addressOf(q);
        if (addr) {
            rows.push({ kind: 'go', uid: 'go', title: sprintf(__('Go to %s', 'nibwp'), q), url: addr, group: '' });
        }
        if (/^\//.test(q)) { return rows; }
        omniSections(res, q).forEach(function (sec) {
            var few = sec.key === 'pages' && !q ? 5 : 4;
            var shown = omniOpen[sec.key] ? sec.rows : sec.rows.slice(0, few);
            shown.forEach(function (r) {
                r.group = sec.title;
                r.uid = sec.key + ':' + (r.url || r.id || r.title);
                rows.push(r);
            });
            if (sec.rows.length > shown.length) {
                rows.push({ kind: 'more', more: sec.key, uid: 'more:' + sec.key, group: sec.title, title: sprintf(__('Show %d more', 'nibwp'), sec.rows.length - shown.length) });
            }
        });
        return rows;
    }

    // What a typed line means as an address: a path, a full URL, or
    // "example.com/page", which is an address rather than a search.
    function addressOf(q) {
        if (!q || /\s/.test(q)) { return null; }
        if (/^\//.test(q) || /^https?:\/\//i.test(q)) { return q; }
        if (/^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?(\/\S*)?$/i.test(q)) { return 'https://' + q; }
        return null;
    }

    function searchSite(q) {
        return api('search?q=' + encodeURIComponent(q)).then(function (res) {
            pageCache[q] = res;
            return res;
        });
    }

    // What the person typed; the address shown on arrival is not a search.
    function omniQuery() {
        return omniTyped ? omni.value.trim() : '';
    }

    // Shortcuts, and pages seen before, draw at once; fresh pages follow.
    function omniNow() {
        var q = omniQuery();
        ++omniSeq;
        var cached = pageCache[q] || null;
        var path = /^\//.test(q);
        drawOmni(rowsFrom(cached, q), !cached && !path);
        if (!path) { omniFetch(); }
    }

    var omniFetch = debounce(function () {
        var q = omniQuery();
        var seq = omniSeq;
        searchSite(q).then(function (res) {
            // An answer that lands after the person typed on or left stays out.
            if (seq === omniSeq && document.activeElement === omni) { drawOmni(rowsFrom(res, q)); }
        }).catch(function () { /* the next keystroke tries again */ });
    }, 120);

    // An integration opens on its own card in the Integrations screen.
    function goIntegration(key) {
        var before = null;
        try { before = frame.contentDocument; } catch (e) { before = null; }
        navigate(boot.integrationsUrl);
        var tries = 0;
        (function look() {
            var d = null;
            try { d = frame.contentDocument; } catch (e) { return; }
            var card = d && d !== before && d.readyState === 'complete' ? d.querySelector('.nibwp-integration-card[data-key="' + String(key).replace(/["\\]/g, '') + '"]') : null;
            if (card) {
                card.scrollIntoView({ block: 'center' });
                if (card.animate) { card.animate([{ boxShadow: '0 0 0 3px rgba(239,106,53,.9)' }, { boxShadow: '0 0 0 3px rgba(239,106,53,0)' }], { duration: 1800 }); }
                return;
            }
            if (++tries < 80) { setTimeout(look, 250); }
        })();
    }

    // Do what a row offers: go somewhere, show more of a section, start a
    // message, or add a workflow or skill to the message.
    function omniAct(i) {
        var row = omniItems[i];
        if (!row) { return; }
        if (row.more) {
            omniOpen[row.more] = true;
            var q = omniQuery();
            drawOmni(rowsFrom(pageCache[q] || null, q));
            omniIndex = Math.min(i, omniItems.length - 1);
            omniMoved = true;
            markOmni();
            return;
        }
        omni.blur();
        if (row.kind === 'starter') { useStarter(row.starter); return; }
        if (row.kind === 'workflow' || row.kind === 'skill') {
            var list = row.kind === 'workflow' ? 'workflows' : 'skills';
            if (draft[list].indexOf(row.id) < 0) { draft[list] = draft[list].concat(row.id).slice(-6); }
            renderChips();
            toast(sprintf(__('%s is added to your message.', 'nibwp'), row.title));
            input.focus();
            return;
        }
        if (row.kind === 'integration') { goIntegration(row.id); return; }
        navigate(row.url);
    }

    omni.addEventListener('focus', function () {
        omni.select();
        omniMoved = false;
        omniTyped = false;
        omniOpen = {};
        omniNow();
    });
    omni.addEventListener('input', function () {
        omniMoved = false;
        omniTyped = true;
        omniOpen = {};
        omniNow();
    });
    omni.addEventListener('blur', function () {
        setTimeout(function () {
            if (document.activeElement === omni) { return; }
            closeOmni();
            renderAddress();
        }, 150);
    });
    omni.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!omniItems.length) { return; }
            omniIndex = (omniIndex + (e.key === 'ArrowDown' ? 1 : -1) + omniItems.length) % omniItems.length;
            omniMoved = true;
            markOmni();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // Enter on the untouched address stays put, as in a browser.
            if (!omniTyped && !omniMoved) { omni.blur(); return; }
            var addr = addressOf(omniQuery());
            if (omniItems[omniIndex]) { omniAct(omniIndex); }
            else if (addr) { navigate(addr); omni.blur(); }
        } else if (e.key === 'Escape') {
            omni.blur();
        }
    });
    omniMenu.addEventListener('mousedown', function (e) {
        var b = e.target.closest('[data-i]');
        if (!b) { return; }
        e.preventDefault();
        omniAct(+b.dataset.i);
    });

    /* ------------------------------------------------------------------
     * The + lightbox: everything that can ride along with a message.
     * ---------------------------------------------------------------- */

    var lb = $('nst-lightbox');
    var lbFind = $('nst-lb-find');
    var lbSeq = 0;
    var lbPages = null;

    var lbMore = {};
    var lbActive = 0;

    function openLightbox() {
        lbFind.value = '';
        lbPages = null;
        lbMore = {};
        drawLightbox();
        lb.showModal();
        setTimeout(function () { lbFind.focus(); }, 20);
    }

    $('nst-plus').addEventListener('click', openLightbox);

    // Niby's "Try it" opens the palette already searching for the thing.
    document.addEventListener('nibwp-studio-find', function (e) {
        openLightbox();
        lbFind.value = String((e.detail && e.detail.q) || '');
        drawLightbox();
    });

    function matches(q, s) {
        return !q || String(s || '').toLowerCase().indexOf(q) > -1;
    }

    // One row per thing: icon, name, a short line of what it does, and a
    // check or a key on the right. Same shape everywhere, so the eye can scan.
    function cmdRow(o) {
        return '<button type="button" class="nst-cmd' + (o.on ? ' is-on' : '') + (o.cls ? ' ' + o.cls : '') + '" data-lb="' + o.lb + '"' +
            (o.id !== undefined ? ' data-id="' + esc(o.id) + '"' : '') + (o.attrs || '') + (o.disabled ? ' disabled' : '') + '>' +
            '<span class="nst-cmd__i">' + icon(o.icon, 15) + '</span>' +
            '<span class="nst-cmd__name">' + esc(o.name) + '</span>' +
            '<span class="nst-cmd__desc">' + esc(o.desc || '') + '</span>' +
            '<span class="nst-cmd__end">' + (o.pill ? '<span class="nst-pill">' + esc(o.pill) + '</span>' : '') +
            (o.on ? icon('check', 15) : '') + (o.key ? '<kbd class="nst-kbd">' + esc(o.key) + '</kbd>' : '') + icon('enter', 13) + '</span></button>';
    }

    function cmdSection(title, rows, key, limit, q) {
        if (!rows.length) { return ''; }
        var all = q || lbMore[key] || rows.length <= limit + 1;
        var html = '<section class="nst-lb__sec"><h3>' + esc(title) + (rows.length > 1 ? ' <span>' + rows.length + '</span>' : '') + '</h3>' + (all ? rows : rows.slice(0, limit)).join('');
        if (!all) {
            html += '<button type="button" class="nst-cmd nst-cmd--more" data-lb="more" data-id="' + key + '"><span class="nst-cmd__i">' + icon('more', 15) + '</span>' +
                '<span class="nst-cmd__name">' + esc(sprintf(__('Show all %d', 'nibwp'), rows.length)) + '</span><span class="nst-cmd__desc"></span><span class="nst-cmd__end">' + icon('enter', 13) + '</span></button>';
        }
        return html + '</section>';
    }

    function drawLightbox(pages, keep) {
        var q = lbFind.value.trim().toLowerCase();
        var html = '';

        if (!q) {
            html += cmdSection(__('Add to this message', 'nibwp'), [
                cmdRow({ lb: 'page', icon: 'page', name: __('This page', 'nibwp'), desc: clip(state.page.title || state.page.path || '', 48), on: draft.usePage }),
                cmdRow({ lb: 'point', icon: 'pin', name: __('Point at something', 'nibwp'), desc: __('Leave a note on an element', 'nibwp'), key: 'C' }),
                cmdRow({ lb: 'file', icon: 'image', name: __('A file or screenshot', 'nibwp'), desc: __('Upload, paste or drop', 'nibwp') })
            ], 'add', 3, q);
        }

        var starters = [];
        (boot.starters || []).forEach(function (s, i) {
            if (matches(q, s.label + ' ' + (s.hint || ''))) {
                starters.push(cmdRow({ lb: 'starter', icon: s.icon || 'spark', name: s.label, desc: s.hint, attrs: ' data-i="' + i + '"' }));
            }
        });
        html += cmdSection(__('Start from', 'nibwp'), starters, 'starters', 4, q);

        if (pages && pages.length) {
            html += cmdSection(__('Pages to include', 'nibwp'), pages.map(function (p) {
                return cmdRow({
                    lb: 'pg', id: p.id, icon: p.type === 'page' ? 'page' : 'file', name: p.title,
                    desc: p.type_label + (statusLabel(p.status) ? ' · ' + statusLabel(p.status) : ''),
                    on: draft.pages.some(function (x) { return x.id === p.id; }),
                    attrs: ' data-title="' + esc(p.title) + '"'
                });
            }), 'pages', 6, q);
        }

        html += cmdSection(__('Skills', 'nibwp'), (boot.skills || []).filter(function (s) {
            return matches(q, s.name + ' ' + s.tagline);
        }).map(function (s) {
            return cmdRow({ lb: 'skill', id: s.id, cls: 'nst-skill', icon: 'skill', name: s.name, desc: s.tagline, on: draft.skills.indexOf(s.id) > -1, disabled: !s.ready, pill: s.ready ? '' : __('Pro', 'nibwp') });
        }), 'skills', 5, q);

        html += cmdSection(__('Workflows', 'nibwp'), (boot.workflows || []).filter(function (w) {
            return matches(q, w.title + ' ' + w.summary);
        }).map(function (w) {
            return cmdRow({ lb: 'wf', id: w.slug, icon: 'workflow', name: w.title, desc: w.summary, on: draft.workflows.indexOf(w.slug) > -1 });
        }), 'workflows', 4, q);

        if (!html) {
            html = '<p class="nst-lb__none">' + esc(__('Nothing matches. Try another word.', 'nibwp')) + '</p>';
        }
        var body = $('nst-lb-body');
        var top = body.scrollTop;
        body.innerHTML = html;
        if (keep) { body.scrollTop = top; }
        lbMark(keep ? lbActive : 0, false);
    }

    function lbRows() {
        return Array.prototype.slice.call($('nst-lb-body').querySelectorAll('.nst-cmd:not([disabled])'));
    }

    function lbMark(i, scroll) {
        var rows = lbRows();
        if (!rows.length) { lbActive = 0; return; }
        lbActive = (i + rows.length) % rows.length;
        rows.forEach(function (r, k) { r.classList.toggle('is-active', k === lbActive); });
        if (scroll) { rows[lbActive].scrollIntoView({ block: 'nearest' }); }
    }

    lbFind.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            lbMark(lbActive + (e.key === 'ArrowDown' ? 1 : -1), true);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var row = lbRows()[lbActive];
            if (row) { row.click(); }
        }
    });

    $('nst-lb-body').addEventListener('mousemove', function (e) {
        var row = e.target.closest('.nst-cmd:not([disabled])');
        if (!row) { return; }
        var i = lbRows().indexOf(row);
        if (i > -1 && i !== lbActive) { lbMark(i, false); }
    });

    lbFind.addEventListener('input', debounce(function () {
        var q = lbFind.value.trim();
        var seq = ++lbSeq;
        lbPages = null;
        drawLightbox();
        if (!q) { return; }
        searchSite(q).then(function (res) {
            if (seq === lbSeq) {
                lbPages = res.items || [];
                drawLightbox(lbPages);
            }
        }).catch(function () { /* skills and workflows still show */ });
    }, 160));

    $('nst-lb-body').addEventListener('click', function (e) {
        var b = e.target.closest('[data-lb]');
        if (!b || b.disabled) { return; }
        var id = b.dataset.id;
        switch (b.dataset.lb) {
            case 'page':
                draft.usePage = !draft.usePage;
                break;
            case 'point':
                lb.close();
                setProofing(true);
                break;
            case 'file':
                lb.close();
                $('nst-file').click();
                break;
            case 'starter':
                lb.close();
                useStarter(boot.starters[+b.dataset.i]);
                break;
            case 'pg': {
                var pid = +id;
                if (draft.pages.some(function (x) { return x.id === pid; })) {
                    draft.pages = draft.pages.filter(function (x) { return x.id !== pid; });
                } else {
                    draft.pages.push({ id: pid, title: b.dataset.title });
                }
                break;
            }
            case 'skill':
                draft.skills = draft.skills.indexOf(id) > -1 ? draft.skills.filter(function (x) { return x !== id; }) : draft.skills.concat(id).slice(-6);
                break;
            case 'wf':
                draft.workflows = draft.workflows.indexOf(id) > -1 ? draft.workflows.filter(function (x) { return x !== id; }) : draft.workflows.concat(id).slice(-6);
                break;
            case 'more':
                lbMore[id] = true;
                break;
        }
        renderChips();
        if (lb.open) {
            drawLightbox(lbPages, true);
            lbFind.focus();
        }
    });

    lb.addEventListener('close', function () { input.focus(); });

    /* ------------------------------------------------------------------
     * Time, said the way people say it.
     * ---------------------------------------------------------------- */

    function ago(iso) {
        var t = Date.parse(iso || '');
        if (!t) { return ''; }
        var s = Math.max(0, (Date.now() - t) / 1000);
        if (s < 50) { return __('just now', 'nibwp'); }
        if (s < 3600) { return sprintf(_n('%d min ago', '%d min ago', Math.round(s / 60), 'nibwp'), Math.round(s / 60)); }
        if (s < 86400) { return sprintf(_n('%d hour ago', '%d hours ago', Math.round(s / 3600), 'nibwp'), Math.round(s / 3600)); }
        return new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    }

    function stamp(iso) {
        var t = Date.parse(iso || '');
        if (!t) { return ''; }
        var d = new Date(t);
        var time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        var day = new Date();
        if (day.toDateString() === d.toDateString()) { return time; }
        day.setDate(day.getDate() - 1);
        if (day.toDateString() === d.toDateString()) { return sprintf(__('Yesterday %s', 'nibwp'), time); }
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + ', ' + time;
    }

    function when(iso) {
        var t = Date.parse(iso || '');
        if (!t) { return __('an earlier version', 'nibwp'); }
        var d = new Date(t);
        var today = new Date().toDateString() === d.toDateString();
        return today
            ? sprintf(__('the version from %s', 'nibwp'), d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }))
            : sprintf(__('the version from %s', 'nibwp'), d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }));
    }

    /* ------------------------------------------------------------------
     * Going back. Undo on a saved line and Restore in History put a page
     * back to a saved version, after a small window says what will happen —
     * a misclick here rewrites a live page.
     * ---------------------------------------------------------------- */

    function confirmBox(opts) {
        var dlg = $('nst-confirm');
        $('nst-confirm-h').textContent = opts.title;
        $('nst-confirm-p').textContent = opts.text;
        $('nst-confirm-ok').textContent = opts.ok;
        dlg.querySelector('button[value="cancel"]').textContent = opts.cancel || __('Cancel', 'nibwp');
        dlg.querySelector('.nst-confirm__icon').innerHTML = icon(opts.icon || 'retry', 18);
        dlg.classList.toggle('is-danger', !!opts.danger);
        dlg.returnValue = '';
        return new Promise(function (resolve) {
            dlg.addEventListener('close', function done() {
                dlg.removeEventListener('close', done);
                resolve(dlg.returnValue === 'ok');
            });
            dlg.showModal();
        });
    }

    // Asks first, then restores. An Undo line already says which save it
    // undoes; a version in History is named by when it was saved.
    function askRestore(btn) {
        var id = +btn.dataset.restore;
        var v = versionsById[id];
        var undo = !!btn.closest('.nst-line');
        var title = (v && versionsTitle) || state.page.title || __('this page', 'nibwp');
        var opts = undo
            ? { title: __('Undo this change?', 'nibwp'), text: sprintf(__('“%s” goes back to how it was before this save. What is there now is kept as a version, so you can switch back.', 'nibwp'), title), ok: __('Undo', 'nibwp') }
            : { title: __('Restore this version?', 'nibwp'), text: sprintf(__('“%1$s” goes back to %2$s. What is there now is kept as a version, so you can switch back.', 'nibwp'), title, v && v.first ? __('its first version', 'nibwp') : when(v && v.at)), ok: __('Restore', 'nibwp') };
        return confirmBox(opts).then(function (yes) {
            if (!yes) { return; }
            btn.disabled = true;
            return restoreVersion(id).then(function () { btn.disabled = false; }, function () { btn.disabled = false; });
        });
    }

    function restoreVersion(revisionId) {
        return api('restore', { method: 'POST', body: { revision_id: revisionId } }).then(function (r) {
            toast(__('Page restored.', 'nibwp'), 'ok');
            buddy('restored');
            if (r.item) {
                append(r.item, true);
                scrollFeed(true);
            }
            // Leaving the preview: the frame shows the page as it now is.
            if (previewing()) { navigate(stripVersion(state.page.url)); }
            if (activeTab === 'history') { loadVersions(); }
        }, failed);
    }

    /* Previewing a version: the frame shows the page as it was, with a bar
     * to restore it or go back to the current page. */

    var versionsById = {};
    var versionsTitle = '';

    function previewing() {
        try { return new URL(state.page.url).searchParams.get('nibwp_version'); } catch (e) { return null; }
    }

    function stripVersion(url) {
        try {
            var u = new URL(url);
            u.searchParams.delete('nibwp_version');
            u.searchParams.delete('_nst');
            return u.href;
        } catch (e) {
            return url;
        }
    }

    function renderVersionBar() {
        var id = +previewing();
        var bar = $('nst-versionbar');
        root.classList.toggle('is-previewing', !!id);
        bar.hidden = !id;
        if (!id) { return; }
        var v = versionsById[id];
        $('nst-versionbar-text').textContent = v && v.first
            ? __('Previewing the first version of this page', 'nibwp')
            : sprintf(__('Previewing %s', 'nibwp'), when(v && v.at));
        $('nst-version-restore').dataset.restore = id;
        document.querySelectorAll('.nst-version').forEach(function (row) {
            row.classList.toggle('is-shown', +row.dataset.id === id);
        });
    }

    $('nst-version-back').addEventListener('click', function () { navigate(stripVersion(state.page.url)); });
    $('nst-version-restore').addEventListener('click', function () { askRestore(this); });

    /* ------------------------------------------------------------------
     * Panel: Chat and History tabs, and collapsing it to a rail. Collapsed,
     * the composer floats over the site, so asking never needs the panel.
     * ---------------------------------------------------------------- */

    var activeTab = 'chat';
    var side = $('nst-side');
    var composer = $('nst-composer');

    function setTab(tab) {
        activeTab = tab;
        document.querySelectorAll('.nst-tabs [role="tab"]').forEach(function (b) {
            b.setAttribute('aria-selected', String(b.dataset.tab === tab));
        });
        $('nst-chat').hidden = tab !== 'chat';
        $('nst-history').hidden = tab !== 'history';
        root.classList.toggle('is-history', tab === 'history');
        if (tab === 'history') {
            loadVersions();
            loadThreads();
        } else {
            scrollFeed(true);
        }
    }

    document.querySelector('.nst-tabs').addEventListener('click', function (e) {
        var b = e.target.closest('[data-tab]');
        if (b) { setTab(b.dataset.tab); }
    });

    function setCollapsed(on, remember) {
        root.classList.toggle('is-collapsed', on);
        $('nst-collapse').setAttribute('aria-expanded', String(!on));
        (on ? $('nst-stage') : side).appendChild(composer);
        if (!on) {
            $('nst-peek').hidden = true;
            document.querySelector('.nst-rail__dot').hidden = true;
        }
        if (remember !== false) { store('nibwp-studio-collapsed', on ? '1' : '0'); }
        setTimeout(paintPins, 260);
    }

    $('nst-collapse').addEventListener('click', function () { setCollapsed(true); });
    $('nst-new').addEventListener('click', newThread);

    $('nst-rail').addEventListener('click', function (e) {
        var b = e.target.closest('[data-rail]');
        if (!b) { return; }
        switch (b.dataset.rail) {
            case 'open': setCollapsed(false); break;
            case 'chat': setCollapsed(false); setTab('chat'); break;
            case 'history': setCollapsed(false); setTab('history'); break;
            case 'new': newThread(); break;
        }
    });

    var peekTimer = 0;
    function peek(item) {
        if (!root.classList.contains('is-collapsed')) { return; }
        document.querySelector('.nst-rail__dot').hidden = false;
        var text = item.kind === 'ask' ? sprintf(__('Your %1$s asks: %2$s', 'nibwp'), boot.brand, item.text) : item.text;
        $('nst-peek-text').textContent = clip(text.replace(/[*_`#>]/g, ''), 150);
        var p = $('nst-peek');
        p.hidden = false;
        p.classList.toggle('is-progress', item.kind === 'progress');
        clearTimeout(peekTimer);
        peekTimer = setTimeout(function () { p.hidden = true; }, item.kind === 'reply' ? 12000 : 7000);
    }

    $('nst-peek').addEventListener('click', function () {
        setCollapsed(false);
        setTab('chat');
    });

    function newThread() {
        tCountLater();
        // Already a fresh thread: say so instead of starting another empty one.
        var started = Object.keys(state.items).some(function (id) { return state.items[id].kind === 'prompt'; });
        if (!started) {
            setTab('chat');
            if (root.classList.contains('is-collapsed')) { setCollapsed(false); }
            toast(__('This is already a new thread. Type below to start it.', 'nibwp'));
            input.focus();
            return Promise.resolve();
        }
        return api('new', { method: 'POST' }).then(function (r) {
            state.thread = r.thread;
            state.threadName = '';
            clearFeed();
            setTab('chat');
            toast(__('New thread. Anything still waiting in the last one was taken back.', 'nibwp'), 'ok');
            input.focus();
        }, failed);
    }

    function threadTitle() {
        var first = null;
        Object.keys(state.items).some(function (id) {
            var it = state.items[id];
            if (it.kind === 'prompt') { first = it; return true; }
            return false;
        });
        var title = state.threadName || (first ? (first.text || ((first.data.annotations || [])[0] || {}).note || '') : '');
        // Empty until the first message names the thread: a label reading "New
        // thread" next to the New thread button looked like a second button.
        var t = $('nst-thread-title');
        t.textContent = title ? clip(title, 42) : '';
        t.title = title ? sprintf(__('%s · Show all threads', 'nibwp'), title) : '';
        t.hidden = !title;
    }

    $('nst-thread-title').addEventListener('click', function () { setTab('history'); });

    /* History: versions of the page on screen, and earlier threads. */

    function loadVersions() {
        var box = $('nst-versions');
        var pid = state.page.postId;
        if (!pid || state.page.admin && !/post\.php/.test(state.page.path || '')) {
            box.innerHTML = '<p class="nst-hint">' + esc(__('Open a page or post to see its saved versions.', 'nibwp')) + '</p>';
            return;
        }
        box.innerHTML = '<p class="nst-hint"><span class="nst-spin"></span></p>';
        api('versions?post_id=' + pid).then(function (r) {
            if (+r.post_id !== +state.page.postId) { return; }
            if (!r.enabled) {
                box.innerHTML = '<p class="nst-hint">' + esc(__('This page does not keep saved versions.', 'nibwp')) + '</p>';
                return;
            }
            if (!r.versions.length) {
                box.innerHTML = '<p class="nst-hint">' + esc(__('No saved versions yet. Each save from now on adds one here.', 'nibwp')) + '</p>';
                return;
            }
            versionsById = {};
            versionsTitle = r.title || '';
            r.versions.forEach(function (v) { versionsById[v.id] = v; });
            box.innerHTML = '<ol class="nst-versions">' + r.versions.map(function (v, i) {
                var name = i === 0 ? __('Current', 'nibwp') : (v.first ? __('First version', 'nibwp') : stamp(v.at));
                var meta = [i === 0 ? sprintf(__('Saved %s', 'nibwp'), ago(v.at)) : (v.first ? stamp(v.at) : ago(v.at)), v.author, v.autosave ? __('Autosave', 'nibwp') : ''].filter(Boolean).join(' · ');
                return '<li class="nst-version' + (i === 0 ? ' is-current' : '') + (v.first ? ' is-first' : '') + '" data-id="' + v.id + '">' +
                    '<span class="nst-version__dot" aria-hidden="true"></span>' +
                    '<button type="button" class="nst-version__main" data-preview="' + (i === 0 ? 'current' : esc(v.preview)) + '" title="' + esc(i === 0 ? __('Show the page as it is now', 'nibwp') : __('Show the page as it was then', 'nibwp')) + '">' +
                    '<strong>' + esc(name) + '</strong><span>' + esc(meta) + '</span></button>' +
                    (i === 0 ? '' : '<span class="nst-version__acts"><button type="button" class="nst-act" data-restore="' + v.id + '">' + esc(__('Restore', 'nibwp')) + '</button></span>') +
                    '</li>';
            }).join('') + '</ol>';
            renderVersionBar();
        }).catch(function (err) {
            box.innerHTML = '<p class="nst-hint">' + esc(err.message) + '</p>';
        });
    }

    function loadThreads() {
        var box = $('nst-threads');
        api('threads').then(function (r) {
            var list = r.threads || [];
            if (!list.length) {
                box.innerHTML = '<p class="nst-hint">' + esc(__('Your threads show up here once you send something.', 'nibwp')) + '</p>';
                return;
            }
            box.innerHTML = '<ul class="nst-threads">' + list.map(function (t) {
                return '<li><button type="button" class="nst-thread' + (t.current ? ' is-current' : '') + '" data-thread="' + t.thread + '"' + (t.current ? ' aria-current="true"' : '') + '>' +
                    '<span class="nst-thread__i">' + icon('chat', 14) + '</span>' +
                    '<span class="nst-thread__title">' + esc(t.title) + '</span>' +
                    '<span class="nst-thread__meta">' + esc([t.current ? __('Open now', 'nibwp') : '', sprintf(_n('%d message', '%d messages', t.prompts, 'nibwp'), t.prompts), ago(t.at)].filter(Boolean).join(' · ')) + '</span>' +
                    '</button></li>';
            }).join('') + '</ul>';
        }).catch(function (err) {
            box.innerHTML = '<p class="nst-hint">' + esc(err.message) + '</p>';
        });
    }

    $('nst-history').addEventListener('click', function (e) {
        var pv = e.target.closest('[data-preview]');
        if (pv) {
            navigate(pv.dataset.preview === 'current' ? stripVersion(state.page.url) : pv.dataset.preview);
            return;
        }
        var rs = e.target.closest('[data-restore]');
        if (rs) { askRestore(rs); return; }
        var th = e.target.closest('[data-thread]');
        if (th && !th.classList.contains('is-current')) {
            openThread(+th.dataset.thread);
        }
    });

    function openThread(n) {
        return api('switch', { method: 'POST', body: { thread: n } }).then(function (r) {
            state.thread = r.thread;
            state.threadName = r.name || '';
            clearFeed();
            state.loaded = false;
            setTab('chat');
            threadTitle();
            schedule(0);
        }, failed);
    }

    /* ------------------------------------------------------------------
     * The threads folder, in the panel's head: every thread (a session with
     * the agent), newest first. Open one, find one by name, rename it in
     * place (saved as you type), or delete it for good after a confirm.
     * ---------------------------------------------------------------- */

    var tpop = $('nst-tpop');
    var tpopBtn = $('nst-threads-btn');
    var tlist = [];
    var tSaveT = 0;

    function tpopOpen(open) {
        if (!!open === !tpop.hidden) { return; }
        tpop.hidden = !open;
        tpopBtn.setAttribute('aria-expanded', String(!!open));
        if (open) {
            $('nst-tpop-q').value = '';
            tpopLoad();
            setTimeout(function () { $('nst-tpop-q').focus(); }, 20);
        }
    }

    // How many threads: on the folder, and in its title.
    function tCount(n) {
        var b = $('nst-threads-n');
        b.textContent = n > 99 ? '99+' : String(n);
        b.hidden = !n;
        $('nst-tpop-n').textContent = n ? String(n) : '';
        tpopBtn.setAttribute('aria-label', n ? sprintf(_n('Your threads (%d)', 'Your threads (%d)', n, 'nibwp'), n) : __('Your threads', 'nibwp'));
    }
    var tCountT = 0;
    function tCountLater() {
        clearTimeout(tCountT);
        // Only the count while the folder is open: a row may be mid-rename.
        tCountT = setTimeout(function () { api('threads').then(function (r) { tCount((r.threads || []).length); if (tpop.hidden) { tlist = r.threads || []; } }, function () {}); }, 1200);
    }

    function tpopLoad() {
        $('nst-tpop-list').innerHTML = '<p class="nst-hint"><span class="nst-spin"></span></p>';
        api('threads').then(function (r) {
            tlist = r.threads || [];
            tCount(tlist.length);
            tpopRender();
        }, function (err) {
            $('nst-tpop-list').innerHTML = '<p class="nst-hint">' + esc(err.message) + '</p>';
        });
    }

    function tpopMeta(t) {
        return [t.current ? __('Open now', 'nibwp') : '', sprintf(_n('%d message', '%d messages', t.prompts, 'nibwp'), t.prompts), ago(t.at)].filter(Boolean).join(' · ');
    }

    function tpopRow(t) {
        var long = String(t.title).length > 64;
        return '<li class="nst-trow' + (t.current ? ' is-current' : '') + '" data-t="' + t.thread + '">' +
            '<button type="button" class="nst-trow__main" data-topen' + (t.current ? ' aria-current="true"' : '') + ' title="' + esc(t.title) + '">' +
                '<span class="nst-trow__i">' + icon('chat', 14) + '</span>' +
                '<span class="nst-trow__txt"><strong>' + esc(t.title) + '</strong><span class="nst-trow__meta">' + esc(tpopMeta(t)) + '</span></span>' +
            '</button>' +
            (long ? '<button type="button" class="nst-trow__more" data-tmore>' + esc(__('Show more', 'nibwp')) + '</button>' : '') +
            '<span class="nst-trow__acts">' +
                (t.current ? '' : '<button type="button" class="nst-ib nst-ib--sm" data-topen aria-label="' + esc(sprintf(__('Open “%s”', 'nibwp'), t.title)) + '" data-tip="' + esc(__('Open', 'nibwp')) + '">' + icon('forward', 13) + '</button>') +
                '<button type="button" class="nst-ib nst-ib--sm" data-trename aria-label="' + esc(sprintf(__('Rename “%s”', 'nibwp'), t.title)) + '" data-tip="' + esc(__('Rename', 'nibwp')) + '">' + icon('pen', 13) + '</button>' +
                '<button type="button" class="nst-ib nst-ib--sm nst-trow__del" data-tdel aria-label="' + esc(sprintf(__('Delete “%s”', 'nibwp'), t.title)) + '" data-tip="' + esc(__('Delete', 'nibwp')) + '">' + icon('trash', 13) + '</button>' +
            '</span></li>';
    }

    function tpopRender() {
        var q = $('nst-tpop-q').value.trim().toLowerCase();
        var rows = tlist.filter(function (t) { return !q || t.title.toLowerCase().indexOf(q) > -1; });
        var day = 86400000;
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var when = function (t) {
            var d = Date.parse(t.at) || 0;
            if (d >= today.getTime()) { return __('Today', 'nibwp'); }
            if (d >= today.getTime() - day) { return __('Yesterday', 'nibwp'); }
            if (d >= today.getTime() - 6 * day) { return __('This week', 'nibwp'); }
            return __('Earlier', 'nibwp');
        };
        var last = '';
        $('nst-tpop-list').innerHTML = rows.length
            ? '<ul class="nst-tlist">' + rows.map(function (t) {
                var w = when(t);
                var head = w !== last ? '<li class="nst-tlist__when" aria-hidden="true">' + esc(w) + '</li>' : '';
                last = w;
                return head + tpopRow(t);
            }).join('') + '</ul>'
            : '<p class="nst-hint">' + esc(q ? __('No thread by that name.', 'nibwp') : __('Your threads show up here once you send something.', 'nibwp')) + '</p>';
    }

    function tpopFind(li) { return tlist.find(function (t) { return t.thread === +li.dataset.t; }); }

    // Rename in place: saved as you type, on Enter and when you leave the field.
    function tpopRename(li) {
        var t = tpopFind(li);
        if (!t) { return; }
        var main = li.querySelector('.nst-trow__main');
        var txt = main.querySelector('.nst-trow__txt');
        var was = t.title;
        li.classList.add('is-editing');
        txt.innerHTML = '<input type="text" class="nst-trow__name" maxlength="90" value="' + esc(t.named ? t.title : '') + '" placeholder="' + esc(was) + '" aria-label="' + esc(__('Thread name', 'nibwp')) + '"><span class="nst-trow__meta" aria-live="polite">' + esc(__('Saves as you type. Empty brings back the first message.', 'nibwp')) + '</span>';
        main.disabled = true;
        var field = txt.querySelector('input');
        var note = txt.querySelector('.nst-trow__meta');
        field.focus();
        field.select();
        var saved = t.named ? t.title : '';
        var save = function () {
            clearTimeout(tSaveT);
            var name = field.value.trim();
            if (name === saved) { return Promise.resolve(); }
            saved = name;
            return api('thread-name', { method: 'POST', body: { thread: t.thread, name: name } }).then(function (r) {
                t.title = r.title || was;
                t.named = !!r.name;
                if (t.current) { state.threadName = r.name || ''; threadTitle(); }
                if (field.isConnected) { note.textContent = __('Saved', 'nibwp'); }
            }, failed);
        };
        var done = function (keep) {
            if (!li.classList.contains('is-editing')) { return; }
            li.classList.remove('is-editing');
            (keep ? save() : Promise.resolve()).then(function () {
                if (li.isConnected) { li.outerHTML = tpopRow(t); }
            });
        };
        field.addEventListener('input', function () {
            note.textContent = __('Saving…', 'nibwp');
            clearTimeout(tSaveT);
            tSaveT = setTimeout(save, 600);
        });
        field.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); done(true); }
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                field.value = saved = t.named ? t.title : '';
                done(false);
            }
        });
        field.addEventListener('blur', function () { done(true); });
    }

    // Delete for good, after a confirm in the row itself.
    // Delete, confirmed twice: first in the row itself, then in a dialog that
    // says what goes. Nothing is deleted until both say yes.
    function tpopDelete(li) {
        var t = tpopFind(li);
        if (!t) { return; }
        li.classList.add('is-confirm');
        li.innerHTML = '<span class="nst-trow__warn" aria-hidden="true">' + icon('trash', 14) + '</span>' +
            /* translators: %s: the thread's name */
            '<p class="nst-trow__ask">' + esc(sprintf(__('Delete “%s”?', 'nibwp'), t.title)) + '</p>' +
            '<span class="nst-trow__yesno"><button type="button" class="nst-btn nst-btn--danger" data-tyes>' + esc(__('Delete', 'nibwp')) + '</button>' +
            '<button type="button" class="nst-btn nst-btn--ghost" data-tno>' + esc(__('Keep it', 'nibwp')) + '</button></span>';
        li.querySelector('[data-tno]').focus();
    }

    function tpopDeleteYes(li) {
        var t = tpopFind(li);
        if (!t) { return; }
        confirmBox({
            title: __('Delete this thread for good?', 'nibwp'),
            /* translators: 1: the thread's name, 2: how many messages it has */
            text: sprintf(_n('“%1$s” and its %2$d message are deleted. This can’t be undone.', '“%1$s” and its %2$d messages are deleted. This can’t be undone.', t.prompts, 'nibwp'), t.title, t.prompts),
            ok: __('Delete for good', 'nibwp'),
            cancel: __('Keep it', 'nibwp'),
            icon: 'trash',
            danger: true
        }).then(function (yes) {
            if (!yes) {
                if (li.isConnected) { li.outerHTML = tpopRow(t); }
                return;
            }
            tpopDeleteNow(li, t);
        });
    }

    function tpopDeleteNow(li, t) {
        li.classList.add('is-busy');
        api('thread-delete', { method: 'POST', body: { thread: t.thread } }).then(function (r) {
            tlist = tlist.filter(function (x) { return x !== t; });
            tCount(tlist.length);
            if (t.current) {
                state.thread = r.thread;
                state.threadName = '';
                clearFeed();
                setTab('chat');
                threadTitle();
            }
            tpopRender();
            toast(t.current ? __('Thread deleted. You’re in a fresh one.', 'nibwp') : __('Thread deleted.', 'nibwp'), 'ok');
        }, function (err) {
            li.classList.remove('is-busy');
            failed(err);
        });
    }

    tpopBtn.addEventListener('click', function () { tpopOpen(tpop.hidden); });
    tCountLater();
    $('nst-tpop-q').addEventListener('input', tpopRender);
    $('nst-tpop-new').addEventListener('click', function () {
        tpopOpen(false);
        newThread();
    });
    tpop.addEventListener('click', function (e) {
        var li = e.target.closest('.nst-trow');
        if (!li) { return; }
        if (e.target.closest('[data-tmore]')) {
            var open = li.classList.toggle('is-open');
            e.target.closest('[data-tmore]').textContent = open ? __('Show less', 'nibwp') : __('Show more', 'nibwp');
            return;
        }
        if (e.target.closest('[data-trename]')) { tpopRename(li); return; }
        if (e.target.closest('[data-tdel]')) { tpopDelete(li); return; }
        if (e.target.closest('[data-tyes]')) { tpopDeleteYes(li); return; }
        if (e.target.closest('[data-tno]')) { var t = tpopFind(li); li.outerHTML = tpopRow(t); return; }
        if (e.target.closest('[data-topen]') && !li.classList.contains('is-current')) {
            tpopOpen(false);
            openThread(+li.dataset.t);
        }
    });
    tpop.addEventListener('dblclick', function (e) {
        var li = e.target.closest('.nst-trow');
        if (li && e.target.closest('.nst-trow__txt strong')) { tpopRename(li); }
    });
    document.addEventListener('click', function (e) {
        // The path as dispatched: a row swapped for its confirm is no longer in
        // the folder. The final confirm is a dialog above it, not outside it.
        if (!tpop.hidden && !$('nst-confirm').open && !e.composedPath().some(function (n) { return n === tpop || n === tpopBtn || n === $('nst-confirm'); })) { tpopOpen(false); }
    });
    // A click on the page itself lands in its frame, never here: the window loses focus instead.
    window.addEventListener('blur', function () {
        setTimeout(function () {
            if (!tpop.hidden && document.activeElement && document.activeElement.tagName === 'IFRAME') { tpopOpen(false); }
        }, 0);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !tpop.hidden && !$('nst-confirm').open) { tpopOpen(false); tpopBtn.focus(); }
    });

    /* ------------------------------------------------------------------
     * Media: the site's media library. Browse by source, type and month,
     * search as you type, load more, pick many (Shift for a run), then add
     * them to the message, copy their links, or delete them. Delete moves
     * files to the trash, where they can come back.
     * ---------------------------------------------------------------- */

    var MEDIA_TYPES = [
        ['', __('All types', 'nibwp'), 'image'],
        ['image', __('Images', 'nibwp'), 'image'],
        ['video', __('Videos', 'nibwp'), 'screen'],
        ['audio', __('Audio', 'nibwp'), 'chat'],
        ['document', __('Documents', 'nibwp'), 'file']
    ];
    var MONTHS = Array.from({ length: 12 }, function (x, i) { return new Date(2000, i, 1).toLocaleString(document.documentElement.lang || undefined, { month: 'long' }); });
    var mq = { view: 'all', source: 'all', type: '', y: 0, m: 0, s: '' };
    var media = { items: [], total: 0, page: 0, pages: 0, counts: null, dates: [], days: 30, seq: 0 };
    var picked = {};
    var lastPick = -1;
    var mediaGrid = $('nst-media-grid');

    // The Builder asks for one file: the dialog's Add button uses it instead.
    var mediaPick = null;
    var mediaPickLabel = '';
    $('nst-media-dlg').addEventListener('close', function () { mediaPick = null; });
    function openMedia() {
        buddy('media');
        var dlg = $('nst-media-dlg');
        if (!dlg.open) { dlg.showModal(); }
        loadMedia(false);
        setTimeout(function () { $('nst-media-q').focus(); }, 30);
    }

    function mediaQuery(page) {
        var p = ['page=' + page];
        if (mq.view === 'trash') { p.push('view=trash'); }
        if (mq.source === 'agent') { p.push('source=agent'); }
        if (mq.type) { p.push('type=' + mq.type); }
        if (mq.y) { p.push('y=' + mq.y); }
        if (mq.y && mq.m) { p.push('m=' + mq.m); }
        if (mq.s) { p.push('s=' + encodeURIComponent(mq.s)); }
        return 'media?' + p.join('&');
    }

    // more: the next page added below; otherwise the list starts over and
    // the picks go, since what was picked may no longer be shown.
    function loadMedia(more) {
        var seq = ++media.seq;
        if (!more) {
            picked = {};
            lastPick = -1;
        }
        mediaGrid.setAttribute('aria-busy', 'true');
        return api(mediaQuery(more ? media.page + 1 : 1)).then(function (r) {
            // A slower answer for an older filter must not paint over this one.
            if (seq !== media.seq) { return; }
            mediaGrid.removeAttribute('aria-busy');
            media.items = more ? media.items.concat(r.items) : r.items;
            media.total = r.total;
            media.page = r.page;
            media.pages = r.pages;
            media.days = r.days;
            if (r.counts) { media.counts = r.counts; }
            if (r.dates) { media.dates = r.dates; }
            renderMedia();
        }, function (err) {
            mediaGrid.removeAttribute('aria-busy');
            failed(err);
        });
    }

    function sideRow(key, val, label, n, ic) {
        var on = mq[key] === val && (key === 'view' || mq.view !== 'trash');
        return '<button type="button" class="nst-media__row' + (on ? ' is-on' : '') + '" data-mk="' + key + '" data-mv="' + esc(val) + '"' + (on ? ' aria-current="true"' : '') + '>' +
            (ic ? icon(ic, 15) : '') + '<span>' + esc(label) + '</span>' + (n !== undefined && n !== null ? '<b>' + esc(n) + '</b>' : '') + '</button>';
    }

    function renderSide() {
        var c = media.counts || {};
        var trash = mq.view === 'trash';
        var html = '<p class="nst-media__h">' + esc(__('Library', 'nibwp')) + '</p>' +
            sideRow('source', 'all', __('All media', 'nibwp'), c.all, 'image') +
            sideRow('source', 'agent', __('From Agent View', 'nibwp'), c.agent, 'pin') +
            '<p class="nst-media__h">' + esc(__('Type', 'nibwp')) + '</p>' +
            MEDIA_TYPES.map(function (t) { return sideRow('type', t[0], t[1], t[0] ? c[t[0]] : null, t[2]); }).join('');

        var years = [];
        media.dates.forEach(function (d) {
            var y = years.find(function (x) { return x.y === d.y; });
            if (!y) { y = { y: d.y, n: 0, months: [] }; years.push(y); }
            y.n += d.n;
            y.months.push(d);
        });
        html += '<p class="nst-media__h">' + esc(trash ? __('Date deleted from', 'nibwp') : __('Date', 'nibwp')) + '</p>' +
            '<button type="button" class="nst-media__row' + (!mq.y ? ' is-on' : '') + '" data-my="0"><span>' + esc(__('All dates', 'nibwp')) + '</span></button>';
        years.forEach(function (y, i) {
            var open = mq.y === y.y || (!mq.y && i === 0);
            html += '<details class="nst-media__year"' + (open ? ' open' : '') + '><summary class="nst-media__row' + (mq.y === y.y && !mq.m ? ' is-on' : '') + '" data-my="' + y.y + '">' + icon('chevron', 13) + '<span>' + y.y + '</span><b>' + y.n + '</b></summary>' +
                y.months.map(function (m) {
                    return '<button type="button" class="nst-media__row nst-media__month' + (mq.y === m.y && mq.m === m.m ? ' is-on' : '') + '" data-my="' + m.y + '" data-mm="' + m.m + '"><span>' + esc(MONTHS[m.m - 1]) + '</span><b>' + m.n + '</b></button>';
                }).join('') + '</details>';
        });
        html += '<div class="nst-media__trash"><button type="button" class="nst-media__row' + (trash ? ' is-on' : '') + '" data-mk="view"' + (trash ? ' aria-current="true"' : '') + '>' + icon('trash', 15) + '<span>' + esc(__('Trash', 'nibwp')) + '</span><b>' + (c.trash || 0) + '</b></button></div>';
        $('nst-media-side').innerHTML = html;
    }

    function pickedIds() {
        return Object.keys(picked).map(Number);
    }

    function renderBar() {
        var ids = pickedIds();
        var n = ids.length;
        var all = n > 0 && n === media.items.length;
        var trash = mq.view === 'trash';
        var box = '<button type="button" class="nst-media__all" role="checkbox" aria-checked="' + (all ? 'true' : n ? 'mixed' : 'false') + '" data-mact="all">' +
            '<span class="nst-check" aria-hidden="true">' + icon(all ? 'check' : 'minus', 12) + '</span>' + esc(all ? __('Clear', 'nibwp') : __('Select all', 'nibwp')) + '</button>';
        var html = box;
        if (!n) {
            html += '<span class="nst-media__count">' + esc(media.total === 1 ? __('1 file', 'nibwp') : sprintf(__('%s files', 'nibwp'), media.total.toLocaleString())) + '</span>';
            if (trash) {
                html += '<span class="nst-media__note">' + esc(media.days ? sprintf(__('Deleted for good after %d days.', 'nibwp'), media.days) : __('The trash is turned off on this site.', 'nibwp')) + '</span>';
            }
        } else {
            html += '<span class="nst-media__count is-picked">' + esc(n === 1 ? __('1 selected', 'nibwp') : sprintf(__('%d selected', 'nibwp'), n)) + '</span><span class="nst-media__acts">';
            if (trash) {
                html += '<button type="button" class="nst-btn nst-btn--ghost" data-mact="restore">' + icon('retry', 13) + esc(__('Restore', 'nibwp')) + '</button>' +
                    '<button type="button" class="nst-btn nst-btn--danger" data-mact="delete">' + icon('trash', 13) + esc(__('Delete permanently', 'nibwp')) + '</button>';
            } else {
                html += '<button type="button" class="nst-btn" data-mact="attach">' + icon(mediaPick ? 'check' : 'clip', 13) + esc(mediaPick ? mediaPickLabel : __('Add to message', 'nibwp')) + '</button>' +
                    '<button type="button" class="nst-btn nst-btn--ghost" data-mact="copy">' + icon('copy', 13) + esc(__('Copy links', 'nibwp')) + '</button>' +
                    (media.days
                        ? '<button type="button" class="nst-btn nst-btn--ghost" data-mact="trash">' + icon('trash', 13) + esc(__('Delete', 'nibwp')) + '</button>'
                        : '<button type="button" class="nst-btn nst-btn--danger" data-mact="delete">' + icon('trash', 13) + esc(__('Delete permanently', 'nibwp')) + '</button>');
            }
            html += '</span>';
        }
        $('nst-media-bar').innerHTML = html;
        mediaGrid.classList.toggle('has-picks', n > 0);
    }

    function mediaCard(f, i) {
        var on = !!picked[f.id];
        var meta = [f.dims, f.size, ago(f.at)].filter(Boolean).join(' · ');
        var ext = (f.name.split('.').pop() || '').slice(0, 5).toUpperCase();
        return '<figure class="nst-file' + (on ? ' is-picked' : '') + '" data-id="' + esc(f.id) + '" data-i="' + i + '" tabindex="0" aria-selected="' + on + '">' +
            '<span class="nst-file__pick nst-check" role="checkbox" aria-checked="' + on + '" aria-label="' + esc(sprintf(__('Select %s', 'nibwp'), f.name)) + '">' + icon('check', 12) + '</span>' +
            '<div class="nst-file__thumb">' + (f.thumb ? '<img src="' + esc(f.thumb) + '" alt="" loading="lazy">' : icon(f.kind === 'video' ? 'screen' : f.kind === 'audio' ? 'chat' : 'file', 28) + '<span class="nst-file__ext">' + esc(ext) + '</span>') +
            (f.agent ? '<span class="nst-file__badge">' + esc(__('Agent View', 'nibwp')) + '</span>' : '') + '</div>' +
            '<figcaption><span class="nst-file__name" title="' + esc(f.title && f.title !== f.name ? f.title + ' · ' + f.name : f.name) + '">' + esc(f.name) + '</span>' +
            '<span class="nst-file__meta">' + esc(meta) + '</span></figcaption>' +
            '<div class="nst-file__acts">' +
            '<a class="nst-ib nst-ib--sm" href="' + esc(f.url) + '" target="_blank" rel="noopener" aria-label="' + esc(sprintf(__('Open %s in a new tab', 'nibwp'), f.name)) + '" data-tip="' + esc(__('Open', 'nibwp')) + '">' + icon('external', 14) + '</a>' +
            '<button type="button" class="nst-ib nst-ib--sm" data-fact="copy" aria-label="' + esc(__('Copy link', 'nibwp')) + '" data-tip="' + esc(__('Copy link', 'nibwp')) + '">' + icon('copy', 14) + '</button>' +
            '</div></figure>';
    }

    function renderMedia() {
        renderSide();
        renderBar();
        mediaGrid.innerHTML = media.items.length
            ? media.items.map(mediaCard).join('')
            : '<p class="nst-files__none">' + esc(mq.s ? __('Nothing matches. Try another word.', 'nibwp') : mq.view === 'trash' ? __('The trash is empty.', 'nibwp') : __('No files here yet.', 'nibwp')) + '</p>';
        var more = $('nst-media-more');
        more.hidden = media.page >= media.pages;
        more.textContent = sprintf(__('Load more · %1$s of %2$s', 'nibwp'), media.items.length.toLocaleString(), media.total.toLocaleString());
    }

    function setPick(id, on) {
        if (on) { picked[id] = true; } else { delete picked[id]; }
        var card = mediaGrid.querySelector('.nst-file[data-id="' + id + '"]');
        if (card) {
            card.classList.toggle('is-picked', on);
            card.setAttribute('aria-selected', String(on));
            card.querySelector('.nst-file__pick').setAttribute('aria-checked', String(on));
        }
    }

    // A click picks or unpicks one; Shift+click picks the whole run since the
    // last one.
    function togglePick(card, shift) {
        var i = +card.dataset.i;
        var on = !picked[+card.dataset.id];
        if (shift && lastPick > -1) {
            var a = Math.min(lastPick, i);
            var b = Math.max(lastPick, i);
            for (var k = a; k <= b; k++) { setPick(media.items[k].id, true); }
        } else {
            setPick(+card.dataset.id, on);
        }
        lastPick = i;
        renderBar();
    }

    function mediaAct(act, ids) {
        var label = { trash: __('Moved to the trash.', 'nibwp'), restore: __('Restored.', 'nibwp'), delete: __('Deleted for good.', 'nibwp') };
        var go = function () {
            return api('media/bulk', { method: 'POST', body: { act: act, ids: ids } }).then(function (r) {
                var miss = (r.failed || []).length;
                toast(miss ? sprintf(__('%1$s %2$d could not be changed.', 'nibwp'), label[act], miss) : label[act], miss ? 'bad' : 'ok');
                loadMedia(false);
            }, failed);
        };
        if (act !== 'delete') { return go(); }
        var one = ids.length === 1 && media.items.find(function (f) { return f.id === ids[0]; });
        return confirmBox({
            title: ids.length === 1 ? __('Delete this file for good?', 'nibwp') : sprintf(__('Delete %d files for good?', 'nibwp'), ids.length),
            text: (one ? sprintf(__('“%s” is removed from the site and cannot come back.', 'nibwp'), one.name) : __('They are removed from the site and cannot come back.', 'nibwp')) + ' ' + __('A page that shows one will show a broken image.', 'nibwp'),
            ok: __('Delete permanently', 'nibwp')
        }).then(function (yes) { if (yes) { return go(); } });
    }

    // Picked files ride along with the next message, like an upload.
    function attachPicked(ids) {
        var have = draft.attachments.map(function (x) { return x.id; });
        var room = Math.max(0, 10 - draft.attachments.length);
        var add = media.items.filter(function (f) { return ids.indexOf(f.id) > -1 && have.indexOf(f.id) === -1; }).slice(0, room);
        add.forEach(function (f) { draft.attachments.push({ key: uid(), id: f.id, name: f.name, mime: f.mime, url: f.thumb || f.url, busy: false }); });
        renderChips();
        $('nst-media-dlg').close();
        toast(add.length ? sprintf(__('Added %d to your message.', 'nibwp'), add.length) : __('A message holds 10 files at most.', 'nibwp'), add.length ? 'ok' : 'bad');
    }

    $('nst-media').addEventListener('click', openMedia);

    var mediaTyping = 0;
    $('nst-media-q').addEventListener('input', function () {
        var v = this.value.trim();
        clearTimeout(mediaTyping);
        mediaTyping = setTimeout(function () {
            if (v === mq.s) { return; }
            mq.s = v;
            loadMedia(false);
        }, 220);
    });

    $('nst-media-side').addEventListener('click', function (e) {
        var r = e.target.closest('[data-mk], [data-my]');
        if (!r) { return; }
        if (r.dataset.my !== undefined) {
            // A year's summary also folds it; only its first click filters.
            if (r.localName === 'summary' && mq.y === +r.dataset.my && !mq.m) { return; }
            mq.y = +r.dataset.my;
            mq.m = +(r.dataset.mm || 0);
        } else if (r.dataset.mk === 'view') {
            mq.view = mq.view === 'trash' ? 'all' : 'trash';
            mq.y = 0;
            mq.m = 0;
        } else {
            mq[r.dataset.mk] = r.dataset.mv;
            mq.view = 'all';
        }
        loadMedia(false);
    });

    $('nst-media-bar').addEventListener('click', function (e) {
        var b = e.target.closest('[data-mact]');
        if (!b) { return; }
        var ids = pickedIds();
        switch (b.dataset.mact) {
            case 'all':
                var every = ids.length !== media.items.length;
                media.items.forEach(function (f) { setPick(f.id, every); });
                renderBar();
                break;
            case 'attach':
                if (mediaPick) {
                    var pf = media.items.filter(function (x) { return picked[x.id]; })[0];
                    var cb = mediaPick;
                    mediaPick = null;
                    $('nst-media-dlg').close();
                    if (pf) { cb({ id: pf.id, url: pf.url, mime: pf.mime || '', name: pf.name || '', alt: pf.alt || '' }); }
                    break;
                }
                attachPicked(ids);
                break;
            case 'copy':
                copyText(media.items.filter(function (f) { return picked[f.id]; }).map(function (f) { return f.url; }).join('\n')).then(function () {
                    toast(ids.length === 1 ? __('Link copied.', 'nibwp') : sprintf(__('%d links copied.', 'nibwp'), ids.length), 'ok');
                }, failed);
                break;
            default: mediaAct(b.dataset.mact, ids);
        }
    });

    mediaGrid.addEventListener('click', function (e) {
        if (e.target.closest('a')) { return; }
        var card = e.target.closest('.nst-file');
        if (!card) { return; }
        var c = e.target.closest('[data-fact="copy"]');
        if (c) {
            var f = media.items[+card.dataset.i];
            copyText(f.url).then(function () { toast(__('Link copied.', 'nibwp'), 'ok'); }, failed);
            return;
        }
        togglePick(card, e.shiftKey);
    });
    mediaGrid.addEventListener('keydown', function (e) {
        var card = e.target.closest('.nst-file');
        if (card && (e.key === ' ' || e.key === 'Enter') && e.target === card) {
            e.preventDefault();
            togglePick(card, e.shiftKey);
        }
    });

    $('nst-media-more').addEventListener('click', function () { loadMedia(true); });

    $('nst-media-up').addEventListener('click', function () { $('nst-media-file').click(); });
    $('nst-media-file').addEventListener('change', function () {
        var files = Array.prototype.slice.call(this.files);
        this.value = '';
        if (!files.length) { return; }
        toast(files.length === 1 ? __('Uploading…', 'nibwp') : sprintf(__('Uploading %d files…', 'nibwp'), files.length));
        var ok = 0;
        files.reduce(function (chain, file) {
            return chain.then(function () {
                if (boot.maxUpload && file.size > boot.maxUpload) {
                    toast(sprintf(__('%s is larger than this site accepts.', 'nibwp'), file.name), 'bad');
                    return;
                }
                var fd = new FormData();
                fd.append('file', file, file.name);
                fd.append('nibwp_studio', '1');
                return api(boot.media, { method: 'POST', body: fd }).then(function () { ok++; }, failed);
            });
        }, Promise.resolve()).then(function () {
            if (ok) { toast(ok === 1 ? __('Uploaded.', 'nibwp') : sprintf(__('%d files uploaded.', 'nibwp'), ok), 'ok'); }
            mq.view = 'all';
            loadMedia(false);
        });
    });

    /* ------------------------------------------------------------------
     * Menus, panes and keys.
     * ---------------------------------------------------------------- */

    var moreBtn = $('nst-more');
    var moreMenu = $('nst-more-menu');

    function setMore(open) {
        moreMenu.hidden = !open;
        moreBtn.setAttribute('aria-expanded', String(open));
        if (open) {
            var first = moreMenu.querySelector('[role="menuitem"]');
            if (first) { first.focus(); }
        }
    }
    moreBtn.addEventListener('click', function () { setMore(moreMenu.hidden); });
    document.addEventListener('click', function (e) {
        if (!moreMenu.hidden && !e.target.closest('.nst-more')) { setMore(false); }
    });
    moreMenu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (!b) { return; }
        setMore(false);
        switch (b.dataset.act) {
            case 'new':
                newThread();
                break;
            case 'connect':
                $('nst-connect').showModal();
                break;
            case 'approval':
                approvalOn = !approvalOn;
                renderApproval();
                if (approvalHandler) { approvalHandler(approvalOn); }
                toast(approvalOn ? __('Your agent asks before it clicks or types on the page.', 'nibwp') : __('Your agent clicks and types without asking.', 'nibwp'));
                break;
            case 'stop':
                api('stop', { method: 'POST' }).then(function () {
                    toast(__('Your agent will stop at its next check-in.', 'nibwp'));
                }, failed);
                break;
        }
    });

    function showPane(pane) {
        root.dataset.pane = pane;
        document.querySelectorAll('.nst-panes [data-pane]').forEach(function (b) {
            b.setAttribute('aria-selected', String(b.dataset.pane === pane));
        });
    }
    document.querySelector('.nst-panes').addEventListener('click', function (e) {
        var b = e.target.closest('[data-pane]');
        if (b) { showPane(b.dataset.pane); }
    });

    function onKey(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            omni.focus();
            return;
        }
        if (state.proofing && hover.el && !isTyping(e) && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            widen(e.key === 'ArrowUp');
            return;
        }
        if (e.key === 'Escape') {
            if (!$('nst-note').hidden) { closeNote(); return; }
            if (state.proofing) { setProofing(false); return; }
            if (!moreMenu.hidden) { setMore(false); moreBtn.focus(); return; }
        }
        if (isTyping(e) || document.querySelector('dialog[open]') || root.classList.contains('is-wiring') || root.classList.contains('is-building')) {
            return;
        }
        var k = e.key.toLowerCase();
        // Shortcuts take Ctrl (⌘ on a Mac): a letter typed by mistake does nothing.
        var mod = e.ctrlKey || e.metaKey;
        if (mod && !e.shiftKey && !e.altKey && k === 'b' && !state.proofing && window.nibwpBuilder) {
            e.preventDefault();
            // The Builder's own keys must not see the key that opened it.
            e.stopImmediatePropagation();
            window.nibwpBuilder.toggle();
        } else if (mod && e.shiftKey && !e.altKey && (k === 'm' || k === 'd')) {
            e.preventDefault();
            toggleMode(k === 'd');
        } else if (mod || e.altKey) {
            return;
        } else if (state.proofing && TOOL_KEY[k]) {
            e.preventDefault();
            setTool(TOOL_KEY[k]);
            syncModes();
        } else if (e.key === '[') {
            e.preventDefault();
            setCollapsed(!root.classList.contains('is-collapsed'));
        }
    }
    document.addEventListener('keydown', onKey);
    if (MAC) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-tip*="Ctrl+"], [placeholder*="Ctrl+"], [title*="Ctrl+"]'), function (n) {
            ['data-tip', 'placeholder', 'title'].forEach(function (a) { if (n.hasAttribute(a)) { n.setAttribute(a, window.nibwpKeyLabel(n.getAttribute(a))); } });
        });
        Array.prototype.forEach.call(root.querySelectorAll('kbd[data-key]'), function (k) { k.textContent = window.nibwpKeyLabel(k.textContent); });
    }

    if (/Mac|iPhone|iPad/.test(navigator.platform || '')) {
        $('nst-omni-kbd').textContent = '⌘K';
    }

    /* ------------------------------------------------------------------
     * The bridge to the agent's browser commands (studio-bus.js): the frame
     * they run in, and the screen they report to.
     * ---------------------------------------------------------------- */

    var approvalOn = !!boot.approval;
    var approvalHandler = null;

    function requestActive() {
        return Object.keys(state.items).some(function (id) {
            var it = state.items[id];
            return it.kind === 'prompt' && it.state === 'claimed';
        });
    }

    // A browser command the agent ran, as a step in the thread. Kept on screen
    // only: the agent already has the result, and the thread keeps what was
    // asked and answered.
    function busLog(action, detail, tone) {
        var g = stepGroup();
        addStep(g, { text: action, data: { detail: clip(detail || '', 140) } });
        if (tone === 'bad') { g.querySelector('.nst-steps__list').lastElementChild.classList.add('is-bad'); }
        if (!g.parentNode) { feed.appendChild(g); }
        renderWelcome();
        scrollFeed(false);
    }

    function renderApproval() {
        var b = document.querySelector('#nst-more-menu [data-act="approval"]');
        if (b) { b.setAttribute('aria-checked', String(approvalOn)); }
    }

    window.nibwpStudioBridge = {
        get frame() { return frame; },
        okToLeave: okToLeave,
        reloadFrame: function (f) {
            var t = tabs.find(function (x) { return x.frame === f; });
            return new Promise(function (resolve) { reloadTabFrame(t, f, false, 'agent', resolve); });
        },
        readJson: readJson,
        realUrl: realUrl,
        throwTo: throwToChat,
        // What the Builder borrows from Agent View.
        kit: {
            esc: esc,
            api: api,
            toast: toast,
            cssPath: cssPath,
            frame: function () { return frame; },
            frameDoc: frameDoc,
            tabsChanged: function () { renderTabs(); },
            device: function () { return state.device || 'desktop'; },
            page: function () { return { url: state.page.url ? stripVersion(state.page.url) : '', builder: state.page.builder || '' }; },
            stopMarking: function () { if (state.proofing) { setProofing(false); } if (!$('nst-note').hidden) { closeNote(); } },
            // A request about one element of the page, with whatever files are attached.
            commentOn: function (node, text, from, title) {
                var r = node.getBoundingClientRect();
                var win = node.ownerDocument.defaultView;
                var html = node.outerHTML || '';
                draft.annotations.push({
                    key: uid(),
                    selector: cssPath(node),
                    tag: node.localName,
                    text: clip(node.innerText || node.textContent || node.getAttribute('alt') || '', 400),
                    html: html.length > 3000 ? html.slice(0, 3000) + '…' : html,
                    box: { x: Math.round(r.left + win.scrollX), y: Math.round(r.top + win.scrollY), w: Math.round(r.width), h: Math.round(r.height) },
                    hints: hintsFor(node),
                    page_url: state.page.url,
                    canvas: '',
                    at: null,
                    note: text
                });
                draft.title = title || '';
                renderChips();
                if (mode !== 'queue') { throwToChat(from, text); }
                whenUploaded(function () { send(from); });
            },
            pickMedia: function (cb, label) { openMedia(); mediaPick = cb; mediaPickLabel = label || __('Use this', 'nibwp'); },
            mode: function () { return mode; },
            modes: function () { return MODES; },
            icon: function (n, s) { return icon(n, s); },
            pickMode: function (anchor) { openModes(anchor); },
            // Start a message in the chat box, for the person to finish.
            prefill: function (text) {
                if (root.classList.contains('is-collapsed')) { setCollapsed(false, false); }
                input.value = text;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                input.setSelectionRange(text.length, text.length);
            },
            sendText: function (text, from, title) {
                var typed = input.value.trim();
                input.value = text + (typed ? '\n\n' + typed : '');
                draft.title = title || '';
                autosize();
                if (mode !== 'queue') { throwToChat(from, text); }
                whenUploaded(function () { send(from); });
            },
            addFiles: function (files) { upload(files); },
            // What is on the canvas: one of this site's pages, or not.
            pageInfo: function () {
                var p = state.page || {};
                return { url: p.url ? stripVersion(p.url) : '', external: !!p.external, admin: !!p.admin, postId: p.postId || 0, postType: p.postType || '', title: p.title || '' };
            },
            // Fold or unfold the chat column; says whether it was open.
            chat: function (show) {
                var was = !root.classList.contains('is-collapsed');
                if (was !== !!show) { setCollapsed(!show, false); }
                return was;
            },
            files: function () { return draft.attachments.length; },
            attachments: function () { return draft.attachments.map(function (a) { return { name: a.name, url: a.url || '', mime: a.mime || '', busy: !!a.busy }; }); },
            sendDesign: function (spec, text, from) {
                draft.design = spec;
                var typed = input.value.trim();
                input.value = text + (typed ? '\n\n' + typed : '');
                autosize();
                throwToChat(from, spec.name || text);
                send();
            }
        },
        // Kind Guy hands a workflow to the next message.
        attachWorkflow: function (slug) {
            slug = String(slug || '');
            if (!slug || !(boot.workflows || []).some(function (w) { return w.slug === slug; })) { return false; }
            if (draft.workflows.indexOf(slug) < 0) { draft.workflows = draft.workflows.concat(slug).slice(-6); }
            renderChips();
            return true;
        },
        // And a skill, the same way.
        attachSkill: function (id) {
            id = String(id || '');
            if (!(boot.skills || []).some(function (s) { return s.id === id && s.ready; })) { return false; }
            if (draft.skills.indexOf(id) < 0) { draft.skills = draft.skills.concat(id); }
            renderChips();
            return true;
        },
        tabs: function () {
            return tabs.map(function (t) {
                var g = groupRec(t.group);
                return { id: t.id, frame: t.frame, url: realUrl(t.page && t.page.url ? t.page.url : t.url), title: tabLabel(t), active: t.id === activeTabId, group: g ? groupName(g) : '' };
            });
        },
        focusTab: function (id) { activateTab(id); },
        closeTab: function (id) { closeTab(id); },
        // The agent's "open": the tab already showing that page, reloaded, or
        // a new one. Resolves once it has loaded.
        openTab: function (url) {
            var target = targetFor(url);
            if (!target) { return Promise.reject(new Error('Not a web address: ' + url)); }
            var want = stripHash(realUrl(target));
            var same = tabs.find(function (t) { return stripHash(realUrl(t.page && t.page.url ? t.page.url : t.url)) === want; });
            if (same) { activateTab(same.id); }
            var tab = same || openTab(target);
            return new Promise(function (resolve) {
                var f = tab.frame;
                var done = false;
                var finish = function () {
                    if (done) { return; }
                    done = true;
                    f.removeEventListener('load', finish);
                    resolve(tab.id);
                };
                f.addEventListener('load', finish);
                setTimeout(finish, 15000);
                if (same) {
                    tab.loading = true;
                    renderTabs();
                    reloadTabFrame(tab, f, false, 'agent', finish);
                }
            });
        },
        // During a request the server already lists each tool the agent
        // calls; the browser only adds the steps that failed.
        log: function (action, detail, tone) {
            if (!requestActive() || tone === 'bad') { busLog(action, detail, tone); }
        },
        // What the agent did elsewhere. While it works on an Agent View
        // request, the thread already lists each tool, so this stays quiet.
        note: function (label, detail, tone) {
            if (!requestActive()) { busLog(label, detail, tone); }
        },
        open: function (url) {
            return new Promise(function (resolve) {
                var done = false;
                var finish = function () {
                    if (done) { return; }
                    done = true;
                    frame.removeEventListener('load', finish);
                    resolve();
                };
                frame.addEventListener('load', finish);
                setTimeout(finish, 15000);
                navigate(url);
            });
        },
        setWidth: function (px) {
            if (!px) { setDevice('desktop'); return; }
            if (px === 390) { setDevice('mobile'); return; }
            setDevice(px <= 420 ? 'mobile' : 'tablet');
            artboard.style.width = 'min(' + px + 'px, calc(100% - 32px))';
        },
        confirm: confirmBox,
        showingPost: function (id) { return !!id && +id === +state.page.postId; },
        busy: function () { return state.proofing || !$('nst-note').hidden; },
        approval: function (on) { approvalOn = !!on; renderApproval(); },
        onApproval: function (fn) { approvalHandler = fn; },
        takenOver: function () { $('nst-stood').hidden = false; }
    };

    $('nst-stood-reload').addEventListener('click', function () { window.location.reload(); });

    /* ------------------------------------------------------------------
     * Start.
     * ---------------------------------------------------------------- */

    var savedDevice = store('nibwp-studio-device');
    if (savedDevice === 'tablet' || savedDevice === 'mobile') { setDevice(savedDevice); }

    // The tabs open last time come back; only the one on screen loads now.
    var savedTabs = null;
    try { savedTabs = JSON.parse(store('nibwp-studio-tabs') || 'null'); } catch (e) { savedTabs = null; }
    if (savedTabs && Array.isArray(savedTabs.groups)) {
        savedTabs.groups.forEach(function (g) {
            if (!g || !/^g\d+$/.test(String(g.id))) { return; }
            groups.push({ id: String(g.id), name: String(g.name || '').slice(0, 40), color: GROUP_COLORS.indexOf(g.color) > -1 ? g.color : 'grey', collapsed: !!g.collapsed });
            groupSeq = Math.max(groupSeq, +String(g.id).slice(1));
        });
    }
    var savedList = [];
    if (savedTabs && Array.isArray(savedTabs.tabs)) {
        savedTabs.tabs.slice(0, TAB_MAX).forEach(function (entry) {
            var u = typeof entry === 'string' ? entry : entry && entry.u;
            var target = u ? targetFor(u) : null;
            if (target) { savedList.push({ target: target, group: entry && groupRec(entry.g) ? entry.g : null, fav: entry && /^https?:/.test(entry.f || '') ? entry.f : '' }); }
        });
    }
    if (!savedList.length) {
        var lastUrl = store('nibwp-studio-url');
        if (lastUrl && sameSite(lastUrl)) { savedList = [{ target: lastUrl, group: null }]; }
    }
    savedList.forEach(function (s, i) {
        if (i === 0) {
            tabs[0].url = s.target;
            tabs[0].group = s.group;
            tabs[0].fav = s.fav;
            if (s.target !== frame.src) { frame.src = s.target; }
            return;
        }
        tabs.push({ id: 't' + (++tabSeq), url: s.target, frame: null, page: null, loading: false, group: s.group, fav: s.fav });
    });
    dropEmptyGroups();
    var savedActive = savedTabs && tabs[+savedTabs.active] ? tabs[+savedTabs.active].id : 'main';
    renderTabs();
    if (savedActive !== 'main') { activateTab(savedActive); }
    progress(true);

    if (window.matchMedia('(max-width: 900px)').matches) {
        showPane('chat');
    } else if (store('nibwp-studio-collapsed') === '1') {
        setCollapsed(true, false);
    }

    // Phones get Chat and Site tabs instead of a side panel, so a panel left
    // collapsed on a wide window opens again when the window gets narrow.
    var narrow = window.matchMedia('(max-width: 900px)');
    var onNarrow = function () {
        if (narrow.matches && root.classList.contains('is-collapsed')) { setCollapsed(false, false); }
    };
    if (narrow.addEventListener) { narrow.addEventListener('change', onNarrow); } else { narrow.addListener(onNarrow); }

    renderApproval();
    threadTitle();
    if (typeof $('nst-toast').showPopover !== 'function') { $('nst-toast').hidden = true; }
    renderStarters();
    renderChips();
    renderConnectState();
    setPresence(state.presence);
    renderWelcome();
    autosize();
    poll();
})();
