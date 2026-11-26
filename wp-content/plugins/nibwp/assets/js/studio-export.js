/**
 * Agent View: the static site. One window to publish the site to a host
 * and keep it fresh, or to export it as a zip.
 *
 * Set up once (what goes in, where it goes, checks), then the window opens
 * on the live site: what is waiting to go live, Publish changes, Rebuild
 * everything, auto publish, and the last publishes. The work runs on the
 * server in short steps (studio-publish.php, studio-export.php) and keeps
 * going when the window closes.
 */
(function () {
    'use strict';

    var bridge = window.nibwpStudioBridge;
    var kit = bridge && bridge.kit;
    if (!kit) { return; }
    var __ = wp.i18n.__;
    var _n = wp.i18n._n;
    var sprintf = wp.i18n.sprintf;
    var esc = kit.esc;

    var I = {
        box: '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        x: '<path d="M6 6l12 12M18 6L6 18"/>',
        warn: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        down: '<path d="M12 4v12M7 11l5 5 5-5M5 20h14"/>',
        back: '<path d="M15 6l-6 6 6 6"/>',
        next: '<path d="M9 6l6 6-6 6"/>',
        play: '<path d="M7 5l12 7-12 7z"/>',
        cloud: '<path d="M7 18a4.5 4.5 0 0 1-.5-9 6 6 0 0 1 11.6 1.5A3.75 3.75 0 0 1 17.5 18z"/>',
        bolt: '<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>',
        server: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        rocket: '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2M9 15l-3-3c1-4 4.5-8 12-9-1 7.5-5 11-9 12z"/><circle cx="14.5" cy="9.5" r="1.5"/>',
        gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        redo: '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>',
        out: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'
    };
    function ico(n, s) { return '<svg class="nst-sx-i" viewBox="0 0 24 24" width="' + (s || 16) + '" height="' + (s || 16) + '" aria-hidden="true">' + I[n] + '</svg>'; }

    var TARGETS = {
        cloudflare: { icon: 'bolt', name: __('Cloudflare Pages', 'nibwp'), sub: __('Free, fast all over the world. Our pick.', 'nibwp') },
        netlify: { icon: 'cloud', name: __('Netlify', 'nibwp'), sub: __('Free plan, simple to run.', 'nibwp') },
        ftp: { icon: 'server', name: __('Your own host', 'nibwp'), sub: __('Any web host, over SFTP, FTPS or FTP.', 'nibwp') },
        zip: { icon: 'box', name: __('Download a zip', 'nibwp'), sub: __('Host it yourself, or keep a copy.', 'nibwp') }
    };
    var STEPS = [__('Content', 'nibwp'), __('Where', 'nibwp'), __('System check', 'nibwp'), __('Publish', 'nibwp')];
    // What the system check looks at, shown while it runs; the server answers with the results.
    var SYS = [
        ['loopback', __('Opening your own pages', 'nibwp')],
        ['parallel', __('Reading pages side by side', 'nibwp')],
        ['write', __('Saving files', 'nibwp')],
        ['space', __('Room on the disk', 'nibwp')],
        ['zip', __('Making a zip', 'nibwp')],
        ['memory', __('PHP memory', 'nibwp')],
        ['permalinks', __('Permalinks', 'nibwp')]
    ];

    var dlg = document.createElement('dialog');
    dlg.className = 'nst-sx';
    dlg.setAttribute('aria-labelledby', 'nst-sx-h');
    dlg.innerHTML = '<header class="nst-sx__head"><span class="nst-sx__logo">' + ico('box', 20) + '</span><div><h2 id="nst-sx-h">' + esc(__('Static site', 'nibwp')) + ' <i class="nst-betatag">' + esc(__('Beta', 'nibwp')) + '</i></h2><p>' + esc(__('Your site as plain, fast files, live on a host and kept up to date.', 'nibwp')) + '</p></div><button type="button" class="nst-sx__x" data-sx="close" aria-label="' + esc(__('Close', 'nibwp')) + '">' + ico('x', 16) + '</button></header>' +
        '<ol class="nst-sx__steps">' + STEPS.map(function (s, i) { return '<li data-step="' + i + '"><b>' + (i + 1) + '</b><span>' + esc(s) + '</span></li>'; }).join('') + '</ol>' +
        '<div class="nst-sx__body" aria-live="polite"></div>' +
        '<footer class="nst-sx__foot"></footer>';
    document.body.appendChild(dlg);
    var body = dlg.querySelector('.nst-sx__body');
    var foot = dlg.querySelector('.nst-sx__foot');
    var stepsEl = dlg.querySelector('.nst-sx__steps');

    var pub = null;
    var scan = null;
    var view = 'home';
    var step = 0;
    var target = 'cloudflare';
    var form = {};
    var accounts = null;
    var connecting = false;
    var running = false;
    var looping = false;
    var sys = null;
    var sysFor = '';
    var checking = false;
    var last = null;
    var topPc = 0;
    var tries = 0;
    var clock = 0;
    var seenLog = {};
    var opts ={ types: [], taxonomies: [], archives: true, follow: true, media: 'used', links: 'relative', base: '' };

    function api(path, o) { return kit.api(path, o); }
    function activeRun(r) { return r && (r.state === 'build' || r.state === 'finish' || r.state === 'send'); }
    function connected(t) { return pub && pub.settings.target === t && !!pub.live; }
    function host(u) { return String(u || '').replace(/^https?:\/\//, '').replace(/\/$/, ''); }
    function ago(ts) {
        var s = Math.max(0, (pub ? pub.now : Date.now() / 1000) - ts);
        if (s < 60) { return __('just now', 'nibwp'); }
        if (s < 3600) { return sprintf(_n('%d minute ago', '%d minutes ago', Math.floor(s / 60), 'nibwp'), Math.floor(s / 60)); }
        if (s < 86400) { return sprintf(_n('%d hour ago', '%d hours ago', Math.floor(s / 3600), 'nibwp'), Math.floor(s / 3600)); }
        return sprintf(_n('%d day ago', '%d days ago', Math.floor(s / 86400), 'nibwp'), Math.floor(s / 86400));
    }
    function fmt(bytes) {
        var u = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
        return (i ? bytes.toFixed(1) : bytes) + ' ' + u[i];
    }

    /* The Export button in the top bar says when changes wait or a publish runs. */
    function badge() {
        var b = document.querySelector('[data-act="export"]');
        if (!b || !pub || !pub.settings) { return; }
        var n = pub.built && pub.settings.target ? pub.dirty.n : 0;
        var run = activeRun(pub.run);
        b.classList.toggle('has-wait', n > 0 && !run);
        b.classList.toggle('is-busy', run);
        b.setAttribute('data-tip', run ? __('Publishing…', 'nibwp') : n ? sprintf(_n('%d change to publish', '%d changes to publish', n, 'nibwp'), n) : __('Static site', 'nibwp'));
    }
    function refresh() {
        return api('publish').then(function (r) { pub = r; badge(); return r; });
    }

    function open() {
        if (!dlg.open) { dlg.showModal(); }
        body.innerHTML = '<div class="nst-sx__busy"><i></i>' + esc(__('Looking at your site…', 'nibwp')) + '</div>';
        foot.innerHTML = '';
        refresh().then(function () {
            var s = pub.settings;
            if (s.opts && s.opts.types && s.opts.types.length) { ['types', 'taxonomies', 'archives', 'follow', 'media'].forEach(function (k) { opts[k] = s.opts[k]; }); }
            target = s.target || target;
            if (activeRun(pub.run)) { view = 'run'; fresh(); paint(); loop(); return; }
            if (running) { view = 'wizard'; step = 3; paint(); return; }
            if (s.target && pub.built) { view = 'home'; paint(); return; }
            wizard(0);
        }, function (e) { body.innerHTML = '<p class="nst-sx__err">' + esc(e.message || __('Could not look at the site.', 'nibwp')) + '</p>'; });
    }
    function wizard(at) {
        view = 'wizard';
        step = at;
        if (scan) { paint(); return; }
        body.innerHTML = '<div class="nst-sx__busy"><i></i>' + esc(__('Looking at your site…', 'nibwp')) + '</div>';
        foot.innerHTML = '';
        api('export/scan').then(function (r) {
            scan = r;
            if (!opts.types.length) {
                opts.types = r.types.filter(function (t) { return t.count > 0; }).map(function (t) { return t.type; });
                opts.taxonomies = r.taxonomies.map(function (t) { return t.tax; });
            }
            paint();
        }, function (e) { body.innerHTML = '<p class="nst-sx__err">' + esc(e.message || __('Could not look at the site.', 'nibwp')) + '</p>'; });
    }

    function paintSteps() {
        stepsEl.hidden = view !== 'wizard';
        dlg.querySelectorAll('.nst-sx__steps li').forEach(function (li) {
            var i = +li.dataset.step;
            li.classList.toggle('is-on', i === step);
            li.classList.toggle('is-done', i < step);
        });
        // The last step says what it does: an export for a zip, a publish for a host.
        dlg.querySelector('.nst-sx__steps li[data-step="3"] span').textContent = target === 'zip' ? __('Export', 'nibwp') : __('Publish', 'nibwp');
    }
    function blocked() { return !sys || checking ? [true] : sys.filter(function (c) { return c.level === 'bad'; }); }
    /* The system check, run for real on the server each time the step opens. */
    function sysCheck() {
        checking = true;
        sys = null;
        sysFor = target;
        if (step === 2) { paint(); }
        api('export/check', { method: 'POST', body: { target: target } }).then(function (r) {
            checking = false;
            sys = r.checks;
            if (step === 2 && view === 'wizard') { paint(); }
        }, function (e) {
            checking = false;
            sys = [{ key: 'rest', level: 'bad', text: (e && e.message) || __('The check could not run.', 'nibwp'), fix: friendly(e) }];
            if (step === 2 && view === 'wizard') { paint(); }
        });
    }
    function picked() {
        if (!scan) { return 0; }
        return scan.types.filter(function (t) { return opts.types.indexOf(t.type) > -1; }).reduce(function (n, t) { return n + t.count; }, 0);
    }

    function paint() {
        paintSteps();
        var html = '';
        if (view === 'home') { html = homeHtml(); }
        else if (view === 'run') { html = runHtml(); }
        else if (step === 0) { html = contentHtml(); }
        else if (step === 1) { html = whereHtml(); }
        else if (step === 2) { html = checksHtml(); }
        else { html = runHtml(); }
        body.innerHTML = html;
        paintFoot();
        if (view === 'run') { show(pub && pub.run && pub.run.state !== 'idle' ? pub.run : null); }
        else if (step === 3) { show(last); }
    }

    /* The live site at a glance. */
    function tabsHtml() {
        return '<div class="nst-sx__tabs" role="group" aria-label="' + esc(__('Where it goes', 'nibwp')) + '">' + Object.keys(TARGETS).map(function (k) {
            var on = k === pub.settings.target;
            return '<button type="button" data-sx="tab" data-t="' + k + '" class="' + (on ? 'is-on' : '') + '" aria-pressed="' + on + '">' + ico(TARGETS[k].icon, 15) + '<span>' + esc(TARGETS[k].name) + '</span>' + (on ? '<i>' + esc(__('Live', 'nibwp')) + '</i>' : '') + '</button>';
        }).join('') + '</div>';
    }
    function homeHtml() {
        var s = pub.settings;
        var t = TARGETS[s.target] || TARGETS.cloudflare;
        var b = pub.built;
        var d = pub.dirty;
        var last = pub.history[0];
        var wait = d.n ? sprintf(_n('%d change waiting', '%d changes waiting', d.n, 'nibwp'), d.n) + (d.what.length ? ': ' + d.what.join(', ') : '') : __('Everything is live.', 'nibwp');
        return tabsHtml() + '<div class="nst-sx__live"><span class="nst-sx__ci">' + ico(t.icon, 20) + '</span><div><b>' + (last && !last.ok ? esc(__('Live, last publish failed', 'nibwp')) : esc(__('Live', 'nibwp'))) + ' <a href="' + esc(pub.live) + '" target="_blank" rel="noopener">' + esc(host(pub.live)) + ico('out', 12) + '</a></b>' +
                '<small>' + esc(t.name) + (b ? ' · ' + esc(sprintf(_n('%d page', '%d pages', b.pages, 'nibwp'), b.pages)) + ' · ' + esc(sprintf(__('built %s', 'nibwp'), ago(b.built))) : '') + '</small></div></div>' +
            '<div class="nst-sx__wait' + (d.n ? '' : ' is-none') + '">' + ico(d.n ? 'info' : 'check', 14) + '<span>' + esc(wait) + '</span></div>' +
            '<div class="nst-sx__acts"><button type="button" class="nst-sx__btn is-main" data-sx="pub"' + (d.n ? '' : ' disabled') + '>' + ico('rocket', 14) + '<span>' + esc(__('Publish changes', 'nibwp')) + '</span></button><button type="button" class="nst-sx__btn is-ghost" data-sx="full">' + ico('redo', 14) + '<span>' + esc(__('Rebuild everything', 'nibwp')) + '</span></button></div>' +
            '<label class="nst-sx__switch"><input type="checkbox" data-o="auto"' + (s.auto ? ' checked' : '') + '><i aria-hidden="true"></i><span><b>' + esc(__('Publish by itself', 'nibwp')) + '</b><small>' + esc(__('When you change content, menus or design, it goes live about two minutes after your last edit.', 'nibwp')) + '</small></span></label>' +
            (pub.history.length ? '<h3>' + esc(__('Last publishes', 'nibwp')) + '</h3><ul class="nst-sx__hist">' + pub.history.slice(0, 5).map(function (h) {
                var what = (h.mode === 'full' ? __('Everything', 'nibwp') : __('Changes', 'nibwp')) + ' · ' + sprintf(_n('%d page built', '%d pages built', h.fetched, 'nibwp'), h.fetched) + ' · ' + sprintf(_n('%d file sent', '%d files sent', h.sent, 'nibwp'), h.sent);
                return '<li class="' + (h.ok ? 'is-ok' : 'is-bad') + '">' + ico(h.ok ? 'check' : 'x', 13) + '<span><b>' + esc(ago(h.at)) + '</b>' + esc(h.ok ? what : h.msg) + '</span><small>' + esc(h.by === 'auto' ? __('by itself', 'nibwp') : h.by === 'agent' ? __('by NibWP', 'nibwp') : __('by you', 'nibwp')) + '</small></li>';
            }).join('') + '</ul>' : '');
    }

    function allOn(k) {
        if (k === 'types') { return scan.types.every(function (t) { return !t.count || opts.types.indexOf(t.type) > -1; }); }
        return opts.archives && scan.taxonomies.every(function (t) { return opts.taxonomies.indexOf(t.tax) > -1; });
    }
    function allToggle(k) {
        return '<label class="nst-sx__all"><input type="checkbox" data-all="' + k + '"' + (allOn(k) ? ' checked' : '') + '><i aria-hidden="true"></i><span>' + esc(__('Select all', 'nibwp')) + '</span></label>';
    }
    function contentHtml() {
        return '<h3 class="nst-sx__h">' + esc(__('What goes in', 'nibwp')) + allToggle('types') + '</h3><div class="nst-sx__cards">' + scan.types.map(function (t) {
            return '<label class="nst-sx__card' + (t.count ? '' : ' is-empty') + '"><input type="checkbox" data-o="types" value="' + esc(t.type) + '"' + (opts.types.indexOf(t.type) > -1 ? ' checked' : '') + (t.count ? '' : ' disabled') + '><span><b>' + esc(t.label) + '</b><small>' + esc(sprintf(_n('%d published', '%d published', t.count, 'nibwp'), t.count)) + '</small></span><i aria-hidden="true">' + ico('check', 12) + '</i></label>';
        }).join('') + '</div>' +
            (scan.taxonomies.length ? '<h3 class="nst-sx__h">' + esc(__('List pages', 'nibwp')) + allToggle('lists') + '</h3><p class="nst-sx__help nst-sx__lead">' + esc(__('Pages that list your content by group, like all posts in a category (/category/news/). Ticked ones are copied too.', 'nibwp')) + '</p><div class="nst-sx__cards is-small">' + scan.taxonomies.map(function (t) {
                return '<label class="nst-sx__card"><input type="checkbox" data-o="taxonomies" value="' + esc(t.tax) + '"' + (opts.taxonomies.indexOf(t.tax) > -1 ? ' checked' : '') + '><span><b>' + esc(t.label) + '</b><small>' + esc(sprintf(_n('%d list page', '%d list pages', t.count, 'nibwp'), t.count)) + '</small></span><i aria-hidden="true">' + ico('check', 12) + '</i></label>';
            }).join('') + '<label class="nst-sx__card"><input type="checkbox" data-o="archives"' + (opts.archives ? ' checked' : '') + '><span><b>' + esc(__('Content type lists', 'nibwp')) + '</b><small>' + esc(__('Like /blog/', 'nibwp')) + '</small></span><i aria-hidden="true">' + ico('check', 12) + '</i></label></div>' : '') +
            '<h3>' + esc(__('Also', 'nibwp')) + '</h3>' +
            '<label class="nst-sx__switch"><input type="checkbox" data-o="follow"' + (opts.follow ? ' checked' : '') + '><i aria-hidden="true"></i><span><b>' + esc(__('Follow the links on the pages', 'nibwp')) + '</b><small>' + esc(__('Picks up pagination and anything linked that the lists above miss.', 'nibwp')) + '</small></span></label>' +
            '<div class="nst-sx__seg" role="radiogroup" aria-label="' + esc(__('Media', 'nibwp')) + '"><label><input type="radio" name="sx-media" value="used"' + (opts.media === 'used' ? ' checked' : '') + '><span>' + esc(__('Only the media the pages use', 'nibwp')) + '</span></label><label><input type="radio" name="sx-media" value="all"' + (opts.media === 'all' ? ' checked' : '') + '><span>' + esc(__('The whole media library', 'nibwp')) + '</span></label></div>';
    }

    function field(key, label, type, extra) {
        var s = pub.settings[target] || {};
        var v = form[target] && form[target][key] !== undefined ? form[target][key] : (type === 'password' ? '' : (s[key] !== undefined ? s[key] : ''));
        return '<label class="nst-sx__f' + (extra && extra.wide ? ' is-wide' : '') + '"><span>' + esc(label) + (extra && extra.opt ? ' <small>' + esc(__('optional', 'nibwp')) + '</small>' : '') + '</span>' +
            '<input type="' + type + '" data-c="' + key + '" value="' + esc(v === 0 ? '' : v) + '"' + (extra && extra.ph ? ' placeholder="' + esc(extra.ph) + '"' : '') + ' autocomplete="off" spellcheck="false"></label>';
    }
    function safeNote(text) {
        return '<p class="nst-sx__safe">' + ico('lock', 14) + '<span>' + esc(text) + '</span></p>';
    }
    function whereHtml() {
        var html = '<h3>' + esc(__('Where it goes', 'nibwp')) + '</h3><div class="nst-sx__targets" role="radiogroup" aria-label="' + esc(__('Where it goes', 'nibwp')) + '">' + Object.keys(TARGETS).map(function (k) {
            var t = TARGETS[k];
            return '<label class="nst-sx__choice"><input type="radio" name="sx-target" value="' + k + '"' + (target === k ? ' checked' : '') + '><span class="nst-sx__ci">' + ico(t.icon, 18) + '</span><span><b>' + esc(t.name) + (connected(k) ? ' <em>' + esc(__('Connected', 'nibwp')) + '</em>' : '') + '</b><small>' + esc(t.sub) + '</small></span></label>';
        }).join('') + '</div>';
        var s = pub.settings;
        if (target === 'cloudflare') {
            html += '<div class="nst-sx__fields">' + field('token', __('API token', 'nibwp'), 'password', { ph: s.cloudflare.saved ? sprintf(__('Saved (…%s). Paste one to replace it.', 'nibwp'), s.cloudflare.saved) : __('Paste the token', 'nibwp') }) +
                field('project', __('Project name', 'nibwp'), 'text', { ph: (pub.host || 'my-site').split('.')[0] }) +
                (accounts ? '<label class="nst-sx__f is-wide"><span>' + esc(__('Account', 'nibwp')) + '</span><select data-c="account">' + accounts.map(function (a) { return '<option value="' + esc(a.id) + '">' + esc(a.name) + '</option>'; }).join('') + '</select></label>' : '') + '</div>' +
                '<p class="nst-sx__help">' + sprintf(esc(__('Make a token in %s: Create Token, Custom token, permission Account › Cloudflare Pages › Edit. A new project is made for you.', 'nibwp')), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">' + esc(__('Cloudflare › My Profile › API Tokens', 'nibwp')) + '</a>') + '</p>' +
                safeNote(__('Your token is encrypted with this site’s own secret keys and kept only on this site. It is used only to send your site to Cloudflare, and it is never shown again or shared.', 'nibwp'));
        } else if (target === 'netlify') {
            html += '<div class="nst-sx__fields">' + field('token', __('Access token', 'nibwp'), 'password', { ph: s.netlify.saved ? sprintf(__('Saved (…%s). Paste one to replace it.', 'nibwp'), s.netlify.saved) : __('Paste the token', 'nibwp') }) +
                field('site', __('Site name', 'nibwp'), 'text', { ph: (pub.host || 'my-site').split('.')[0] }) + '</div>' +
                '<p class="nst-sx__help">' + sprintf(esc(__('Make a token in %s: New access token. A new site is made for you.', 'nibwp')), '<a href="https://app.netlify.com/user/applications#personal-access-tokens" target="_blank" rel="noopener">' + esc(__('Netlify › User settings › Applications', 'nibwp')) + '</a>') + '</p>' +
                safeNote(__('Your token is encrypted with this site’s own secret keys and kept only on this site. It is used only to send your site to Netlify, and it is never shown again or shared.', 'nibwp'));
        } else if (target === 'ftp') {
            var proto = (form.ftp && form.ftp.proto) || s.ftp.proto || 'sftp';
            html += '<div class="nst-sx__seg is-3" role="radiogroup" aria-label="' + esc(__('How to connect', 'nibwp')) + '">' + ['sftp', 'ftps', 'ftp'].map(function (p) {
                var off = (p === 'sftp' && !pub.can.sftp) || (p !== 'sftp' && !pub.can.ftp);
                return '<label><input type="radio" name="sx-proto" value="' + p + '"' + (proto === p ? ' checked' : '') + (off ? ' disabled' : '') + '><span>' + esc(p.toUpperCase()) + '</span></label>';
            }).join('') + '</div>' +
                '<div class="nst-sx__fields">' + field('host', __('Server', 'nibwp'), 'text', { ph: 'ftp.example.com' }) + field('port', __('Port', 'nibwp'), 'number', { ph: proto === 'sftp' ? '22' : '21', opt: true }) +
                field('user', __('User', 'nibwp'), 'text') + field('pass', __('Password', 'nibwp'), 'password', { ph: s.ftp.saved ? __('Saved. Type one to replace it.', 'nibwp') : '' }) +
                field('path', __('Folder', 'nibwp'), 'text', { ph: 'public_html', wide: true }) + '</div>' +
                '<p class="nst-sx__help">' + esc(__('The folder the website is served from, from where you log in (public_html, www, htdocs…). Only files that changed are sent each time.', 'nibwp')) + '</p>' +
                safeNote(__('Your password is encrypted with this site’s own secret keys and kept only on this site, so publishing can run without you. It is used only to log in to your server, and it is never shown again or shared.', 'nibwp'));
        } else {
            return html + '<h3>' + esc(__('How the links work', 'nibwp')) + '</h3><div class="nst-sx__choices">' +
                '<label class="nst-sx__choice"><input type="radio" name="sx-links" value="relative"' + (opts.links === 'relative' ? ' checked' : '') + '><span class="nst-sx__ci">' + ico('folder', 20) + '</span><span><b>' + esc(__('Works anywhere', 'nibwp')) + ' <em>' + esc(__('Recommended', 'nibwp')) + '</em></b><small>' + esc(__('Links point from file to file, so the site opens from any host, a subfolder, or a folder on your computer.', 'nibwp')) + '</small></span></label>' +
                '<label class="nst-sx__choice"><input type="radio" name="sx-links" value="absolute"' + (opts.links === 'absolute' ? ' checked' : '') + '><span class="nst-sx__ci">' + ico('globe', 20) + '</span><span><b>' + esc(__('For a new address', 'nibwp')) + '</b><small>' + esc(__('Clean links on the domain you will host it on, with a sitemap for search engines.', 'nibwp')) + '</small><input type="url" class="nst-sx__base" placeholder="https://example.com" value="' + esc(opts.base) + '"' + (opts.links === 'absolute' ? '' : ' disabled') + ' aria-label="' + esc(__('The new address', 'nibwp')) + '"></span></label></div>';
        }
        var dom = form.domain !== undefined ? form.domain : (target === 'ftp' && connected('ftp') ? pub.live : s.domain);
        html += '<div class="nst-sx__fields"><label class="nst-sx__f is-wide"><span>' + esc(target === 'ftp' ? __('Website address', 'nibwp') : __('Your own domain', 'nibwp')) + (target === 'ftp' ? '' : ' <small>' + esc(__('optional, once it points at the host', 'nibwp')) + '</small>') + '</span><input type="url" data-c="domain" value="' + esc(dom || '') + '" placeholder="https://www.example.com" spellcheck="false"></label></div>';
        html += '<p class="nst-sx__conn" role="status">' + (connecting ? '<i class="nst-sx__spin"></i>' + esc(__('Connecting…', 'nibwp')) : connected(target) ? ico('check', 14) + esc(sprintf(__('Connected. The site will be at %s', 'nibwp'), host(pub.live))) : '') + '</p>';
        return html;
    }

    function checksHtml() {
        if (!checking && (!sys || sysFor !== target)) { setTimeout(sysCheck, 0); checking = true; }
        var bad = checking ? [] : blocked();
        var warn = sys && !checking ? sys.filter(function (c) { return c.level === 'warn'; }).length : 0;
        var where = target === 'zip' ? __('a zip to download', 'nibwp') : TARGETS[target].name + ' · ' + host(pub.live);
        var head = checking ? '<div class="nst-sx__verdict is-run"><i class="nst-sx__spin"></i><span><b>' + esc(__('Checking your server…', 'nibwp')) + '</b><small>' + esc(__('Each part is tested for real, so the export does not stop halfway.', 'nibwp')) + '</small></span></div>'
            : '<div class="nst-sx__verdict is-' + (bad.length ? 'bad' : warn ? 'warn' : 'ok') + '">' + ico(bad.length ? 'x' : warn ? 'warn' : 'check', 18) + '<span><b>' + esc(bad.length ? sprintf(_n('%d thing to fix first', '%d things to fix first', bad.length, 'nibwp'), bad.length) : warn ? __('Ready, with a few notes', 'nibwp') : __('All set: your server is ready', 'nibwp')) + '</b><small>' + esc(bad.length ? __('Each one says what to do. Then check again.', 'nibwp') : sprintf(__('About %1$d pages, to %2$s.', 'nibwp'), picked() + 1, where)) + '</small></span><button type="button" class="nst-sx__btn is-ghost is-sm" data-sx="recheck">' + ico('redo', 13) + '<span>' + esc(__('Check again', 'nibwp')) + '</span></button></div>';
        var rows = checking ? SYS.filter(function (c) { return target === 'zip' || c[0] !== 'zip'; }).map(function (c, i) {
            return '<li class="is-run" style="--i:' + i + '"><i class="nst-sx__spin"></i><span>' + esc(c[1]) + '</span></li>';
        }) : sys.map(function (c, i) {
            return '<li class="is-' + esc(c.level) + '" style="--i:' + i + '"><span class="nst-sx__ck">' + ico(c.level === 'ok' ? 'check' : c.level === 'warn' ? 'warn' : 'x', 13) + '</span><span><b>' + esc(c.text) + '</b>' + (c.fix ? '<small>' + esc(c.fix) + '</small>' : '') + '</span></li>';
        });
        return head + '<ul class="nst-sx__checks">' + rows.join('') + '</ul>' +
            '<h3>' + esc(__('Good to know', 'nibwp')) + '</h3><ul class="nst-sx__notes">' + scan.warnings.map(function (w) {
                return '<li class="is-' + esc(w.level) + '">' + ico(w.level === 'warn' ? 'warn' : 'info', 14) + '<span>' + esc(w.text) + '</span></li>';
            }).join('') + '</ul>' +
            (target !== 'zip' ? '<label class="nst-sx__switch nst-sx__gap"><input type="checkbox" data-o="auto"' + (pub.settings.auto ? ' checked' : '') + '><i aria-hidden="true"></i><span><b>' + esc(__('Publish by itself', 'nibwp')) + '</b><small>' + esc(__('When you change content, menus or design, it goes live about two minutes after your last edit.', 'nibwp')) + '</small></span></label>' : '');
    }

    // The run's stages, for a zip and for a publish.
    var KIND = {
        zip: [__('Read pages', 'nibwp'), __('Finish up', 'nibwp'), __('Pack the zip', 'nibwp'), __('Ready', 'nibwp')],
        pub: [__('Build pages', 'nibwp'), __('Put together', 'nibwp'), __('Upload', 'nibwp'), __('Live', 'nibwp')]
    };
    function tile(k, icon, label) {
        return '<div class="is-' + k + '"><span class="nst-sx__ti">' + ico(icon, 15) + '</span><dt>' + esc(label) + '</dt><dd data-sx-v="' + k + '">' + (k === 'left' ? '…' : '0') + '</dd></div>';
    }
    function runHtml() {
        var kind = view === 'run' ? 'pub' : 'zip';
        var burst = '';
        for (var i = 0; i < 14; i++) { burst += '<i style="--a:' + (i * 360 / 14) + 'deg;--d:' + (i % 3) + '"></i>'; }
        return '<div class="nst-sx__run is-going" data-kind="' + kind + '">' +
            '<div class="nst-sx__hero"><div class="nst-sx__ring"><svg viewBox="0 0 120 120" aria-hidden="true"><defs><linearGradient id="nst-sx-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff8a3d"/><stop offset=".55" stop-color="#f43f5e"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient><linearGradient id="nst-sx-gok" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22c55e"/><stop offset="1" stop-color="#14b8a6"/></linearGradient></defs><circle class="nst-sx__track" cx="60" cy="60" r="52"/><circle class="nst-sx__arc" cx="60" cy="60" r="52"/></svg>' +
            '<i class="nst-sx__orbit" aria-hidden="true"></i><span class="nst-sx__burst" aria-hidden="true">' + burst + '</span><span class="nst-sx__mid"><b class="nst-sx__pc">0%</b><small class="nst-sx__clock">0:00</small></span></div>' +
            '<div class="nst-sx__stats"><p class="nst-sx__state">' + esc(__('Starting…', 'nibwp')) + '</p><p class="nst-sx__now"><i class="nst-sx__dots" aria-hidden="true"><i></i><i></i><i></i></i><span></span></p>' +
            '<ol class="nst-sx__stages">' + KIND[kind].map(function (s, i) { return '<li data-s="' + i + '"><i>' + ico('check', 10) + '</i><span>' + esc(s) + '</span></li>'; }).join('') + '</ol></div></div>' +
            '<dl class="nst-sx__tiles">' + tile('pages', 'globe', __('Pages', 'nibwp')) + tile('files', 'folder', kind === 'zip' ? __('Files', 'nibwp') : __('Sent', 'nibwp')) + tile('size', 'box', __('Size', 'nibwp')) + tile('left', 'bolt', __('Time left', 'nibwp')) + '</dl>' +
            '<div class="nst-sx__feed"><p>' + esc(__('Just done', 'nibwp')) + '</p><ol class="nst-sx__log"></ol></div>' +
            '<ul class="nst-sx__notes nst-sx__after" hidden></ul><details class="nst-sx__errs" hidden><summary></summary><ul></ul></details><div class="nst-sx__done" hidden></div></div>';
    }

    function paintFoot() {
        var back = '<span></span>';
        var main = '';
        if (view === 'home') {
            foot.innerHTML = '<button type="button" class="nst-sx__btn is-ghost" data-sx="setup">' + ico('gear', 14) + '<span>' + esc(__('Settings', 'nibwp')) + '</span></button>';
            return;
        }
        if (view === 'run') {
            foot.innerHTML = activeRun(pub && pub.run) ? '<span class="nst-sx__hint">' + esc(__('You can close this window: publishing goes on.', 'nibwp')) + '</span><button type="button" class="nst-sx__btn is-ghost" data-sx="stop">' + ico('x', 13) + '<span>' + esc(__('Stop', 'nibwp')) + '</span></button>' : '';
            return;
        }
        if (step > 0 && step < 3) { back = '<button type="button" class="nst-sx__btn is-ghost" data-sx="back">' + ico('back', 14) + '<span>' + esc(__('Back', 'nibwp')) + '</span></button>'; }
        else if (step === 0 && pub && pub.built && pub.settings.target) { back = '<button type="button" class="nst-sx__btn is-ghost" data-sx="home">' + ico('back', 14) + '<span>' + esc(__('Back', 'nibwp')) + '</span></button>'; }
        if (step === 0) { main = '<button type="button" class="nst-sx__btn is-main" data-sx="next"' + (!opts.types.length ? ' disabled' : '') + '><span>' + esc(__('Next', 'nibwp')) + '</span>' + ico('next', 14) + '</button>'; }
        if (step === 1) {
            var need = target !== 'zip' && (!connected(target) || dirtyForm());
            main = '<button type="button" class="nst-sx__btn is-main" data-sx="next"' + (connecting ? ' disabled' : '') + '><span>' + esc(need ? __('Connect', 'nibwp') : __('Next', 'nibwp')) + '</span>' + ico(need ? 'bolt' : 'next', 14) + '</button>';
        }
        if (step === 2) { main = '<button type="button" class="nst-sx__btn is-main" data-sx="go"' + (blocked().length ? ' disabled' : '') + (checking ? ' aria-busy="true"' : '') + '>' + ico(target === 'zip' ? 'play' : 'rocket', 13) + '<span>' + esc(target === 'zip' ? __('Export the site', 'nibwp') : __('Publish now', 'nibwp')) + '</span></button>'; }
        if (step === 3 && !running) { foot.innerHTML = ''; return; }
        if (step === 3) { main = running ? '<button type="button" class="nst-sx__btn is-ghost" data-sx="cancel">' + ico('x', 13) + '<span>' + esc(__('Stop', 'nibwp')) + '</span></button>' : ''; }
        foot.innerHTML = back + main;
    }
    function dirtyForm() {
        var f = form[target] || {};
        return Object.keys(f).some(function (k) { return f[k] !== '' && f[k] !== undefined; }) || (form.domain !== undefined && form.domain !== (target === 'ftp' ? pub.live : pub.settings.domain));
    }

    /* Connect: save the host's details, check them, learn the live address. */
    function connect() {
        connecting = true;
        paint();
        var data = { target: target };
        data[target] = Object.assign({}, form[target] || {});
        var s = pub.settings[target];
        if (target === 'cloudflare') { data.cloudflare.project = data.cloudflare.project || s.project || (pub.host || 'site').split('.')[0]; data.cloudflare.account = data.cloudflare.account || (accounts ? accounts[0].id : s.account); }
        if (target === 'netlify') { data.netlify.site = data.netlify.site || s.site || (pub.host || 'site').split('.')[0]; }
        if (target === 'ftp') { ['proto', 'host', 'port', 'user', 'path'].forEach(function (k) { if (data.ftp[k] === undefined) { data.ftp[k] = s[k]; } }); data.ftp.proto = data.ftp.proto || 'sftp'; }
        var dom = form.domain !== undefined ? form.domain : (target === 'ftp' ? pub.live : pub.settings.domain);
        if (target === 'ftp') { data.domain = dom; }
        return api('publish/connect', { method: 'POST', body: data }).then(function (r) {
            connecting = false;
            pub = r;
            if (r.accounts) { accounts = r.accounts; paint(); kit.toast(__('This token sees more than one account. Pick one, then Connect.', 'nibwp')); return false; }
            accounts = null;
            form = {};
            if (target !== 'ftp' && dom !== undefined && dom !== pub.settings.domain) {
                return api('publish/settings', { method: 'POST', body: { domain: dom } }).then(function (p) { pub = p; return true; });
            }
            return true;
        }, function (e) {
            connecting = false;
            kit.toast((e && e.message) || __('Could not connect.', 'nibwp'), 'bad');
            return refresh().then(function () { paint(); return false; }, function () { paint(); return false; });
        });
    }

    /* Progress, for a publish and for a zip export: never still while work goes on. */
    var startT = 0;
    var skew = 0;
    var took = 0;
    var nextI = 0;
    var phaseT = {};
    function now() { return Date.now() / 1000; }
    function mmss(s) {
        s = Math.max(0, Math.round(s));
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }
    function going(st) {
        if (!st) { return view === 'run' || running; }
        return st.target === undefined ? running && st.state !== 'done' : activeRun(st);
    }
    function elapsed(st) {
        if (took) { return took; }
        // A zip counts from the click; a publish from when the server began it (it may have started without this window).
        return st && st.started && st.target !== undefined ? now() + skew - st.started : now() - startT;
    }
    function kindOf(p) {
        var m = /\.([a-z0-9]+)$/i.exec(p);
        var e = m ? m[1].toLowerCase() : 'html';
        if (e === 'html' || e === 'htm') { return ['page', __('Page', 'nibwp')]; }
        if (e === 'css') { return ['css', 'CSS']; }
        if (e === 'js' || e === 'mjs') { return ['js', 'JS']; }
        if (/^(png|jpe?g|gif|webp|avif|svg|ico|bmp)$/.test(e)) { return ['img', __('Image', 'nibwp')]; }
        if (/^(woff2?|ttf|otf|eot)$/.test(e)) { return ['font', __('Font', 'nibwp')]; }
        return ['file', __('File', 'nibwp')];
    }
    function stageOf(st, zip) {
        if (!st) { return 0; }
        if (st.state === 'done') { return 4; }
        if (st.state === 'finish') { return 1; }
        if (zip) { return st.state === 'zip' ? 2 : 0; }
        if (st.state === 'send') { return st.phase === 'deploy' || st.phase === 'upsert' || st.phase === 'wait' ? 3 : 2; }
        return 0;
    }
    /* How far the measurable part of the current stage is, 0 to 1, or -1. */
    function part(st, zip) {
        if (!st) { return -1; }
        if (st.state === 'crawl' || st.state === 'build') { return st.total ? st.pages / st.total : -1; }
        if (st.state === 'finish') { return st.media && st.media.total ? 1 - st.media.left / st.media.total : -1; }
        if (zip && st.state === 'zip') { return st.ztotal ? st.zipped / st.ztotal : -1; }
        if (st.state === 'send' && st.phase === 'upload') { return st.need ? st.sent / st.need : -1; }
        return -1;
    }
    function codeIn(text, path) {
        return esc(text).replace('\u0001', '<code>' + esc(path) + '</code>');
    }
    /* What is being worked on right now: the next pages in line, the file being packed or sent. */
    function nowHtml(st, zip) {
        if (!st) { return esc(zip ? __('Listing the pages to copy…', 'nibwp') : __('Looking at what changed…', 'nibwp')); }
        if (st.state === 'crawl' || st.state === 'build') {
            var next = st.next || [];
            if (!next.length) { return esc(__('Reading pages…', 'nibwp')); }
            /* translators: %s: the address of a page */
            return codeIn(sprintf(__('Reading %s', 'nibwp'), '\u0001'), next[nextI % next.length]);
        }
        if (st.state === 'finish') {
            return esc(st.media && st.media.left ? sprintf(__('Copying media: %1$d of %2$d', 'nibwp'), st.media.total - st.media.left, st.media.total) : __('Adding the 404 page and the sitemap…', 'nibwp'));
        }
        if (zip && st.state === 'zip') {
            return esc(st.ztotal ? sprintf(__('Packing file %1$d of %2$d', 'nibwp'), st.zipped, st.ztotal) : __('Listing the files to pack…', 'nibwp'));
        }
        if (st.state === 'send') {
            if (st.phase === 'upload') { return esc(sprintf(__('Sending file %1$d of %2$d', 'nibwp'), st.sent, st.need)); }
            return esc(stageOf(st, false) === 3 ? __('Telling the host to go live…', 'nibwp') : __('Comparing with what is live…', 'nibwp'));
        }
        return '';
    }
    /* The parts that move every half second: the clock, what is being read, the time left. */
    function live() {
        var run = dlg.querySelector('.nst-sx__run');
        if (!run) { return; }
        var st = last;
        var zip = run.dataset.kind === 'zip';
        var el = elapsed(st);
        dlg.querySelector('.nst-sx__clock').textContent = mmss(el);
        if (!run.classList.contains('is-going')) { return; }
        var nowEl = dlg.querySelector('.nst-sx__now span');
        if (!run.classList.contains('is-retry')) { nowEl.innerHTML = nowHtml(st, zip); }
        var f = part(st, zip);
        var key = st ? st.state + (st.phase || '') : '';
        if (!phaseT[key]) { phaseT[key] = { t: now(), f: Math.max(0, f) }; }
        var p = phaseT[key];
        var left = '…';
        if (st && (st.state === 'crawl' || st.state === 'build') && st.pages >= 3 && st.total) {
            var rate = st.pages / Math.max(1, el);
            left = '~' + mmss((st.total - st.pages) / rate + (zip ? 4 + st.total * 0.05 : 15));
        } else if (f > p.f + 0.02 && now() - p.t > 2) {
            left = '~' + mmss((now() - p.t) * (1 - f) / (f - p.f));
        }
        dlg.querySelector('[data-sx-v="left"]').textContent = left;
    }
    function tickOn() {
        if (clock) { return; }
        var n = 0;
        clock = setInterval(function () {
            var run = dlg.querySelector('.nst-sx__run');
            if (!dlg.open || !run || !run.classList.contains('is-going')) { clearInterval(clock); clock = 0; return; }
            if (++n % 2 === 0) { nextI++; }
            live();
        }, 500);
    }
    /* Count up to a number, so a jump reads as progress. */
    function tween(el, to, show) {
        var from = +el.dataset.n || 0;
        el.dataset.n = to;
        if (from === to || !window.requestAnimationFrame) { el.textContent = show(to); return; }
        var t0 = 0;
        var frame = function (t) {
            t0 = t0 || t;
            var k = Math.min(1, (t - t0) / 650);
            el.textContent = show(Math.round(from + (to - from) * (1 - Math.pow(1 - k, 3))));
            if (k < 1) { window.requestAnimationFrame(frame); }
        };
        window.requestAnimationFrame(frame);
    }
    function show(st) {
        var run = dlg.querySelector('.nst-sx__run');
        if (!run) { return; }
        var zip = run.dataset.kind === 'zip';
        if (st) { last = st; skew = st.now ? st.now - now() : skew; }
        var on = going(st);
        var bad = !!st && (st.state === 'failed' || st.state === 'stopped');
        var done = !!st && st.state === 'done';
        if (!done) { took = 0; } else if (!took) { took = st.ended && st.started ? st.ended - st.started : elapsed(st); }
        run.classList.toggle('is-going', on);
        run.classList.toggle('is-done', done);
        run.classList.toggle('is-bad', bad);
        run.classList.remove('is-retry');
        var pc;
        if (!st) {
            pc = 1;
        } else if (zip) {
            var f = part(st, true);
            pc = done ? 100 : st.state === 'zip' ? 88 + Math.round(Math.max(0, f) * 11) : st.state === 'finish' ? 82 + (f < 0 ? 3 : Math.round(f * 6)) : 2 + Math.round(Math.max(0, f) * 80);
        } else if (st.state === 'build') {
            pc = 3 + Math.round(st.pages / Math.max(1, st.total) * 60);
        } else if (st.state === 'finish') {
            pc = 65;
        } else if (st.state === 'send') {
            pc = st.phase === 'upload' ? 70 + Math.round(st.sent / Math.max(1, st.need) * 25) : stageOf(st, false) === 3 ? 97 : 68;
        } else {
            pc = done ? 100 : 0;
        }
        if (!bad) { pc = Math.max(pc, topPc); topPc = pc; }
        var text = !st ? __('Starting…', 'nibwp')
            : zip ? (done ? __('Your static site is ready.', 'nibwp') : st.state === 'zip' ? __('Packing the zip…', 'nibwp') : st.state === 'finish' ? __('Finishing up…', 'nibwp') : __('Copying pages and the files they use…', 'nibwp'))
            : st.state === 'build' ? (st.mode === 'full' ? __('Building every page…', 'nibwp') : __('Building the pages that changed…', 'nibwp'))
            : st.state === 'finish' ? __('Putting the build together…', 'nibwp')
            : st.state === 'send' ? (stageOf(st, false) === 3 ? __('Going live…', 'nibwp') : st.phase === 'upload' ? __('Sending the files that changed…', 'nibwp') : __('Getting the files ready…', 'nibwp'))
            : done ? __('Your site is live.', 'nibwp')
            : st.state === 'stopped' ? __('Stopped.', 'nibwp') : __('Publishing stopped.', 'nibwp');
        dlg.querySelector('.nst-sx__arc').style.strokeDashoffset = String(327 - 327 * pc / 100);
        dlg.querySelector('.nst-sx__pc').textContent = pc + '%';
        dlg.querySelector('.nst-sx__state').textContent = text;
        var stage = bad ? -1 : stageOf(st, zip);
        dlg.querySelectorAll('.nst-sx__stages li').forEach(function (li) {
            var i = +li.dataset.s;
            li.classList.toggle('is-done', i < stage || done);
            li.classList.toggle('is-on', i === stage && !done);
        });
        if (st) {
            tween(dlg.querySelector('[data-sx-v="pages"]'), st.pages, function (v) { return v + (st.total ? ' / ' + st.total : ''); });
            if (zip || !st.need) { tween(dlg.querySelector('[data-sx-v="files"]'), zip ? st.files : st.sent || 0, String); }
            else { dlg.querySelector('[data-sx-v="files"]').textContent = st.sent + ' / ' + st.need; }
            tween(dlg.querySelector('[data-sx-v="size"]'), st.bytes || 0, fmt);
            var log = (st.log || []).slice(-7).reverse();
            dlg.querySelector('.nst-sx__log').innerHTML = log.map(function (l) {
                var k = kindOf(l || '/');
                var fresh = !seenLog[l];
                seenLog[l] = 1;
                return '<li' + (fresh ? ' class="is-new"' : '') + '><em class="is-' + k[0] + '">' + esc(k[1]) + '</em><code>' + esc(l || '/') + '</code></li>';
            }).join('');
        }
        var tl = dlg.querySelector('.is-left dt');
        tl.textContent = done ? __('Took', 'nibwp') : __('Time left', 'nibwp');
        if (done) { dlg.querySelector('[data-sx-v="left"]').textContent = mmss(took); }
        if (bad) { dlg.querySelector('[data-sx-v="left"]').textContent = '–'; }
        dlg.querySelector('.nst-sx__now span').innerHTML = on ? nowHtml(st, zip) : '';
        var errs = dlg.querySelector('.nst-sx__errs');
        if (st && st.errors && st.errors.length) {
            errs.hidden = false;
            errs.querySelector('summary').textContent = sprintf(_n('%d thing could not be copied', '%d things could not be copied', st.errors.length, 'nibwp'), st.errors.length);
            errs.querySelector('ul').innerHTML = st.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('');
        }
        live();
        if (on) { tickOn(); }
        var d = dlg.querySelector('.nst-sx__done');
        if (zip && done) {
            d.hidden = false;
            d.innerHTML = '<a class="nst-sx__btn is-main is-big" href="' + esc(st.download) + '" data-sx="download">' + ico('down', 16) + '<span>' + esc(sprintf(__('Download the zip (%s)', 'nibwp'), st.size)) + '</span></a><button type="button" class="nst-sx__btn is-ghost" data-sx="again">' + esc(__('Export again', 'nibwp')) + '</button>';
        }
        if (!zip && (done || bad)) {
            notes(st);
            d.hidden = false;
            d.innerHTML = (done ? '<a class="nst-sx__btn is-main is-big" href="' + esc(st.url) + '" target="_blank" rel="noopener">' + ico('out', 16) + '<span>' + esc(sprintf(__('Open %s', 'nibwp'), host(st.url))) + '</span></a>' : '<p class="nst-sx__err">' + esc(st.msg || __('Publishing stopped.', 'nibwp')) + '</p>') +
                '<button type="button" class="nst-sx__btn is-ghost" data-sx="home">' + esc(__('Done', 'nibwp')) + '</button>';
        }
    }
    /* A new run: everything back to the start. */
    function fresh() {
        last = null;
        topPc = 0;
        tries = 0;
        took = 0;
        nextI = 0;
        phaseT = {};
        seenLog = {};
        startT = now();
    }
    /* After a publish: pages that still need WordPress. */
    function notes(st) {
        var n = st.notes || {};
        var out = [];
        if (n.forms && n.forms.n) { out.push(sprintf(_n('%1$d page has a form that sends to WordPress, so it will not send from the static site: %2$s', '%1$d pages have a form that sends to WordPress, so it will not send from the static site: %2$s', n.forms.n, 'nibwp'), n.forms.n, n.forms.pages.join(', '))); }
        if (n.search && n.search.n) { out.push(sprintf(_n('%d page has a search box; search needs WordPress.', '%d pages have a search box; search needs WordPress.', n.search.n, 'nibwp'), n.search.n)); }
        if (n.calls && n.calls.n) { out.push(sprintf(_n('%1$d page still calls your WordPress (a script or a link it could not change): %2$s', '%1$d pages still call your WordPress (scripts or links it could not change): %2$s', n.calls.n, 'nibwp'), n.calls.n, n.calls.pages.join(', '))); }
        var ul = dlg.querySelector('.nst-sx__after');
        if (!ul || !out.length) { return; }
        ul.hidden = false;
        ul.innerHTML = out.map(function (t) { return '<li class="is-warn">' + ico('warn', 14) + '<span>' + esc(t) + '</span></li>'; }).join('');
    }

    /* A publish: the server works on its own; while the window is open it helps it along. */
    function publish(mode) {
        view = 'run';
        fresh();
        if (pub) { pub.run = null; }
        paint();
        api('publish/start', { method: 'POST', body: { mode: mode } }).then(function (st) {
            pub.run = st;
            badge();
            show(st);
            paintFoot();
            loop();
        }, function (e) {
            kit.toast((e && e.message) || __('Could not start publishing.', 'nibwp'), 'bad');
            view = 'home';
            refresh().then(paint);
        });
    }
    function loop() {
        if (looping) { return; }
        looping = true;
        var tick = function () {
            if (!dlg.open || view !== 'run') { looping = false; return; }
            api('publish/step', { method: 'POST' }).then(function (st) {
                pub.run = st;
                show(st);
                if (activeRun(st)) { setTimeout(tick, st.busy ? 1500 : 50); return; }
                looping = false;
                paintFoot();
                refresh().then(function () { show(st); });
                if (st.state === 'done') { kit.toast(sprintf(__('Live: %s', 'nibwp'), host(st.url)), 'ok'); }
            }, function () { setTimeout(tick, 3000); });
        };
        tick();
    }

    /* The zip export: steps on the server until it is packed; a hiccup is tried again by itself. */
    var WAITS = [1500, 3000, 6000, 10000];
    function runZip() {
        running = true;
        step = 3;
        fresh();
        paint();
        api('export/start', { method: 'POST', body: opts }).then(pump, fail);
    }
    function pump(st) {
        show(st);
        if (!running) { return; }
        if (st.state === 'done') {
            running = false;
            paintFoot();
            kit.toast(__('Your static site is ready to download.', 'nibwp'), 'ok');
            return;
        }
        api(st.state === 'zip' ? 'export/zip' : 'export/step', { method: 'POST' }).then(function (s) { tries = 0; pump(s); }, function (e) {
            if (!running) { return; }
            if (tries < WAITS.length && (!e.status || e.status >= 500 || e.status === 429 || e.status === 408)) {
                tries++;
                var run = dlg.querySelector('.nst-sx__run');
                if (run) {
                    run.classList.add('is-retry');
                    dlg.querySelector('.nst-sx__now span').textContent = sprintf(__('The server is busy. Trying again (%1$d of %2$d)…', 'nibwp'), tries, WAITS.length);
                }
                setTimeout(function () { if (running && last) { pump(last); } }, WAITS[tries - 1]);
                return;
            }
            fail(e);
        });
    }
    /* What an error means, in plain words. */
    function friendly(e) {
        var s = e && e.status;
        if (!s) { return __('The connection to your site dropped.', 'nibwp'); }
        if (s === 403 && e.code === 'rest_cookie_invalid_nonce') { return __('Your login ran out. Reload the page and try again.', 'nibwp'); }
        if (s === 403) { return __('A firewall or security plugin blocked the request.', 'nibwp'); }
        if (s === 500) { return __('The server hit an error, often memory or a plugin. The system check can tell more.', 'nibwp'); }
        if (s >= 502) { return __('The server took too long to answer: a host time limit stopped it.', 'nibwp'); }
        return '';
    }
    function fail(e) {
        running = false;
        paintFoot();
        var run = dlg.querySelector('.nst-sx__run');
        var d = dlg.querySelector('.nst-sx__done');
        if (!run || !d) { kit.toast((e && e.message) || __('The export stopped.', 'nibwp'), 'bad'); return; }
        run.classList.remove('is-going', 'is-retry');
        run.classList.add('is-bad');
        dlg.querySelector('.nst-sx__state').textContent = __('The export stopped.', 'nibwp');
        dlg.querySelector('.nst-sx__now span').textContent = '';
        var why = friendly(e);
        d.hidden = false;
        d.innerHTML = '<div class="nst-sx__why">' + ico('warn', 16) + '<span><b>' + esc((e && e.message) || __('The export stopped.', 'nibwp')) + '</b>' + (why ? '<small>' + esc(why) + '</small>' : '') + (last ? '<small>' + esc(__('What is done is kept: Try again goes on from where it stopped.', 'nibwp')) + '</small>' : '') + '</span></div>' +
            (last ? '<button type="button" class="nst-sx__btn is-main" data-sx="resume">' + ico('redo', 14) + '<span>' + esc(__('Try again', 'nibwp')) + '</span></button>' : '') +
            '<button type="button" class="nst-sx__btn is-ghost" data-sx="syscheck">' + ico('gear', 14) + '<span>' + esc(__('Run the system check', 'nibwp')) + '</span></button>' +
            '<button type="button" class="nst-sx__btn is-ghost" data-sx="again">' + esc(__('Start over', 'nibwp')) + '</button>';
    }

    dlg.addEventListener('change', function (e) {
        var t = e.target;
        if (t.dataset.all === 'types') { opts.types = t.checked ? scan.types.filter(function (x) { return x.count > 0; }).map(function (x) { return x.type; }) : []; paint(); return; }
        if (t.dataset.all === 'lists') { opts.taxonomies = t.checked ? scan.taxonomies.map(function (x) { return x.tax; }) : []; opts.archives = t.checked; paint(); return; }
        if (t.dataset.o === 'types' || t.dataset.o === 'taxonomies' || t.dataset.o === 'archives') {
            dlg.querySelectorAll('[data-all]').forEach(function (x) { setTimeout(function () { x.checked = allOn(x.dataset.all); }, 0); });
        }
        if (t.dataset.o === 'types' || t.dataset.o === 'taxonomies') {
            var list = opts[t.dataset.o];
            var at = list.indexOf(t.value);
            if (t.checked && at < 0) { list.push(t.value); }
            if (!t.checked && at > -1) { list.splice(at, 1); }
            paintFoot();
        }
        if (t.dataset.o === 'archives' || t.dataset.o === 'follow') { opts[t.dataset.o] = t.checked; }
        if (t.dataset.o === 'auto') {
            api('publish/settings', { method: 'POST', body: { auto: t.checked } }).then(function (r) { pub = r; badge(); kit.toast(t.checked ? __('It publishes by itself after you edit.', 'nibwp') : __('You publish when you choose.', 'nibwp'), 'ok'); });
        }
        if (t.name === 'sx-media') { opts.media = t.value; }
        if (t.name === 'sx-links') { opts.links = t.value; var b = dlg.querySelector('.nst-sx__base'); if (b) { b.disabled = t.value !== 'absolute'; if (!b.disabled) { b.focus(); } } }
        if (t.name === 'sx-target') { target = t.value; accounts = null; sys = null; paint(); var first = dlg.querySelector('.nst-sx__fields input'); if (first) { first.focus(); } }
        if (t.name === 'sx-proto') { form.ftp = form.ftp || {}; form.ftp.proto = t.value; paintFoot(); }
        if (t.dataset.c === 'account') { form.cloudflare = form.cloudflare || {}; form.cloudflare.account = t.value; }
    });
    dlg.addEventListener('input', function (e) {
        var t = e.target;
        if (t.classList.contains('nst-sx__base')) { opts.base = t.value.trim(); }
        if (t.dataset.c === 'domain') { form.domain = t.value.trim(); paintFoot(); return; }
        if (t.dataset.c) { form[target] = form[target] || {}; form[target][t.dataset.c] = t.value.trim(); paintFoot(); }
    });
    dlg.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.nst-sx__fields input') && step === 1) { e.preventDefault(); var n = foot.querySelector('[data-sx="next"]'); if (n && !n.disabled) { n.click(); } }
    });
    dlg.addEventListener('click', function (e) {
        var b = e.target.closest('[data-sx]');
        if (!b) { return; }
        var a = b.dataset.sx;
        if (a === 'close') { dlg.close(); }
        if (a === 'back') { step = Math.max(0, step - 1); paint(); }
        if (a === 'home') { view = 'home'; refresh().then(function () { if (pub.built && pub.settings.target) { paint(); } else { wizard(0); } }); }
        if (a === 'setup') { wizard(0); }
        if (a === 'zip') { target = 'zip'; wizard(1); }
        if (a === 'tab' && b.dataset.t !== pub.settings.target) { target = b.dataset.t; sys = null; wizard(1); }
        if (a === 'pub') { publish('changes'); }
        if (a === 'full') { publish('full'); }
        if (a === 'stop') { api('publish/stop', { method: 'POST' }).then(function (r) { pub = r; badge(); show(r.run); paintFoot(); kit.toast(__('Publishing stopped. Your changes are still waiting.', 'nibwp')); }); }
        if (a === 'next') {
            if (step === 1 && target === 'zip' && opts.links === 'absolute' && !/^https?:\/\/[^\s/]+\.[^\s]+/i.test(opts.base)) {
                var inp = dlg.querySelector('.nst-sx__base');
                if (inp) { inp.focus(); inp.classList.add('is-bad'); }
                kit.toast(__('Write the full new address, like https://example.com', 'nibwp'), 'bad');
                return;
            }
            if (step === 1 && target !== 'zip' && (!connected(target) || dirtyForm())) {
                connect().then(function (ok) { if (ok) { step = 2; paint(); kit.toast(sprintf(__('Connected: %s', 'nibwp'), host(pub.live)), 'ok'); } });
                return;
            }
            step++;
            paint();
        }
        if (a === 'go') {
            if (target === 'zip') { runZip(); return; }
            api('publish/settings', { method: 'POST', body: { opts: opts } }).then(function (r) { pub = r; publish('changes'); });
        }
        if (a === 'cancel') { running = false; last = null; api('export/cancel', { method: 'POST' }); step = 2; paint(); kit.toast(__('Export stopped.', 'nibwp')); }
        if (a === 'again') { step = 0; paint(); }
        if (a === 'recheck' || a === 'syscheck') { view = 'wizard'; step = 2; sysCheck(); }
        if (a === 'resume' && last) { running = true; tries = 0; paintFoot(); pump(last); }
    });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-act="export"]');
        if (b) { open(); }
    });
    // The top bar's dot: once now, then every minute while a host is set.
    var watch = function () { refresh().then(function (r) { if (r && r.settings && r.settings.target) { setTimeout(watch, activeRun(r.run) ? 10000 : 60000); } }, function () { }); };
    setTimeout(watch, 1500);
    window.nibwpExport = { open: open };
})();
