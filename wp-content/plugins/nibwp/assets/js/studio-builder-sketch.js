/**
 * Agent View Builder, the sketch sheet: plan a page or a section on a sheet the width of the
 * screen size you picked, by hand or with ready-made parts, say what goes in
 * each part and how the design system should look, then have the agent build
 * it with the site's own builder. Everything stays in this one window: a
 * Preview flips the sheet to the live page and back, and dropping it on a
 * spot of the page happens in that preview. Every design is kept in a
 * library to open, copy into another one, drop again or delete.
 */
(function () {
    'use strict';

    var boot = window.nibwpStudio || {};
    var bridge = window.nibwpStudioBridge;
    var kit = bridge && bridge.kit;
    var root = document.getElementById('nst');
    var openBtn = document.getElementById('nst-wire');
    if (!kit || !root || !openBtn) { return; }

    var __ = wp.i18n.__;
    var _n = wp.i18n._n;
    var sprintf = wp.i18n.sprintf;
    var esc = kit.esc;
    var cfg = boot.builder || {};
    var REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;

    var WIDTH = { desktop: 1200, tablet: 768, mobile: 390 };
    var SNAP = 8;
    var MIN = 16;
    var SYS_KEY = 'nibwp-builder-system';

    /* ------------------------------------------------------------------
     * The parts you can draw. Containers hold other parts.
     * ---------------------------------------------------------------- */

    var ICONS = {
        select: '<path d="M5 3l13 8-6 1.6L9 19z"/>',
        sketch: '<path d="M4 20l4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/><path d="M14 7l3 3"/>',
        section: '<path d="M3 6h18v12H3z"/><path d="M3 9h18"/>',
        container: '<path d="M4 4h16v16H4z"/><path d="M8 8h8v8H8z"/>',
        columns: '<path d="M3 5h18v14H3zM9 5v14M15 5v14"/>',
        column: '<path d="M8 3h8v18H8z"/>',
        box: '<path d="M4 4h16v16H4z" stroke-dasharray="3 2.5"/>',
        heading: '<path d="M6 5v14M18 5v14M6 12h12"/>',
        text: '<path d="M4 7h16M4 12h16M4 17h10"/>',
        image: '<path d="M3 5h18v14H3z"/><path d="M3 16l5-5 4 4 3-3 6 6"/><circle cx="15.5" cy="9" r="1.5"/>',
        button: '<path d="M6 8h12a4 4 0 0 1 0 8H6a4 4 0 0 1 0-8z"/><path d="M9.5 12h5"/>',
        card: '<path d="M5 3h14v18H5z"/><path d="M5 10h14M8 13.5h8M8 17h5"/>',
        form: '<path d="M4 4h16v4H4zM4 11h16v4H4z"/><path d="M4 18h7v3H4z"/>',
        nav: '<path d="M3 7h4M10 7h4M17 7h4M3 16h18"/>',
        list: '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        video: '<path d="M3 5h18v14H3z"/><path d="M10 9l5 3-5 3z"/>',
        icon: '<circle cx="12" cy="12" r="8.5"/><path d="M8.8 12.2l2.2 2.2 4.3-4.6"/>',
        spacer: '<path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4"/>',
        undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        redo: '<path d="M15 14l5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
        library: '<path d="M4 4h6v16H4zM14 4l6 1.5-3.5 15L11 19"/>',
        trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        copy: '<path d="M8 8h12v12H8z"/><path d="M16 8V4H4v12h4"/>',
        edit: '<path d="M4 20l4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/>',
        insert: '<path d="M12 4v10M7 9l5 5 5-5"/><path d="M4 18h16v2H4z"/>',
        drop: '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 20h16"/>',
        build: '<path d="M12 1.5c.8 6 5 10.2 10.5 10.5-5.5.3-9.7 4.5-10.5 10.5-.8-6-5-10.2-10.5-10.5C7 11.7 11.2 7.5 12 1.5Z"/>',
        eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        x: '<path d="M6 6l12 12M18 6 6 18"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        builder: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M10 9v12"/>'
    };
    function ico(name, size) {
        return '<svg class="nst-bld-i" viewBox="0 0 24 24" width="' + (size || 16) + '" height="' + (size || 16) + '" aria-hidden="true" focusable="false">' + (ICONS[name] || '') + '</svg>';
    }

    var TYPES = {
        section: { name: __('Section', 'nibwp'), key: 's', box: true, hint: __('e.g. Our three best tours, with a short intro', 'nibwp') },
        container: { name: __('Container', 'nibwp'), key: 'r', box: true, hint: __('e.g. Groups the text and the button', 'nibwp') },
        columns: { name: __('Columns', 'nibwp'), key: 'g', box: true, hint: __('e.g. Three columns, one per service', 'nibwp') },
        column: { name: __('Column', 'nibwp'), key: '', box: true, hint: __('e.g. The text side', 'nibwp') },
        box: { name: __('Box', 'nibwp'), key: 'b', box: true, hint: __('Anything: say what it is', 'nibwp') },
        heading: { name: __('Heading', 'nibwp'), key: 'h', hint: __('e.g. Rome by golf cart, in under 8 words', 'nibwp') },
        text: { name: __('Text', 'nibwp'), key: 't', hint: __('e.g. Two sentences on why people love it', 'nibwp') },
        image: { name: __('Image', 'nibwp'), key: 'i', hint: __('e.g. The Colosseum at sunset, from the media library', 'nibwp') },
        button: { name: __('Button', 'nibwp'), key: 'u', hint: __('e.g. Book now, links to the booking page', 'nibwp') },
        card: { name: __('Card', 'nibwp'), key: 'k', box: true, hint: __('e.g. Photo, tour name, price and a button', 'nibwp') },
        form: { name: __('Form', 'nibwp'), key: 'f', hint: __('e.g. Name, email, date, message', 'nibwp') },
        nav: { name: __('Menu', 'nibwp'), key: 'n', hint: __('e.g. The main menu', 'nibwp') },
        list: { name: __('List', 'nibwp'), key: 'l', hint: __('e.g. Six questions as an accordion', 'nibwp') },
        video: { name: __('Video', 'nibwp'), key: 'm', hint: __('e.g. Our 30-second tour teaser', 'nibwp') },
        icon: { name: __('Icon', 'nibwp'), key: 'o', hint: __('e.g. A check mark', 'nibwp') },
        spacer: { name: __('Space', 'nibwp'), key: 'p', hint: __('Breathing room', 'nibwp') }
    };
    var TOOLS = ['select', 'sketch', 'section', 'columns', 'container', 'box', 'heading', 'text', 'image', 'button', 'card', 'form', 'nav', 'list', 'video', 'icon', 'spacer'];
    var KEYS = { v: 'select', d: 'sketch' };
    Object.keys(TYPES).forEach(function (t) { if (TYPES[t].key) { KEYS[TYPES[t].key] = t; } });
    // The kinds offered right where you drew, most asked for first.
    var QUICK = ['section', 'columns', 'heading', 'text', 'image', 'button', 'card', 'form', 'nav', 'list', 'video', 'icon'];

    // What a few words say a part is: "a big photo" is an image.
    var GUESS = [
        [/\b(columns?|side by side|split)\b/i, 'columns'],
        [/\b(hero|section|band|banner|block)\b/i, 'section'],
        [/\b(cards?|tiles?|testimonials?|reviews?|plans?|pricing)\b/i, 'card'],
        [/\b(title|heading|headline|h1|h2|h3|tagline)\b/i, 'heading'],
        [/\b(photo|image|picture|logo|gallery|map|illustration)\b/i, 'image'],
        [/\b(button|cta|call to action|book now|link button)\b/i, 'button'],
        [/\b(form|sign.?up|newsletter|contact form|subscribe|search)\b/i, 'form'],
        [/\b(menu|nav|navigation|links)\b/i, 'nav'],
        [/\b(faq|list|accordion|steps|bullets?)\b/i, 'list'],
        [/\b(video|reel|teaser|youtube)\b/i, 'video'],
        [/\b(icon|badge|check mark|emoji)\b/i, 'icon'],
        [/\b(space|gap|spacer|breathing)\b/i, 'spacer'],
        [/\b(text|paragraph|copy|intro|description|sentence)\b/i, 'text']
    ];
    function guess(text) {
        for (var i = 0; i < GUESS.length; i++) { if (GUESS[i][0].test(text)) { return GUESS[i][1]; } }
        return '';
    }

    /* ------------------------------------------------------------------
     * Starting points: the sections people ask for most, already drawn,
     * each part saying what it is for. Drawn at desktop width.
     * ---------------------------------------------------------------- */

    var STARTERS = [
        { id: 'hero', name: __('Hero', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 560, '', __('The first thing visitors see', 'nibwp')],
            ['c', 's', 'container', 80, 120, 520, 330, '', ''],
            ['h', 'c', 'heading', 80, 130, 520, 120, '', __('The promise, in under 8 words', 'nibwp')],
            ['t', 'c', 'text', 80, 266, 470, 80, '', __('One or two sentences that back it up', 'nibwp')],
            ['b', 'c', 'button', 80, 372, 200, 56, '', __('The main action', 'nibwp')],
            ['i', 's', 'image', 680, 80, 440, 400, '', __('A strong, real photo', 'nibwp')]
        ] },
        { id: 'features', name: __('Features', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 600, '', ''],
            ['h', 's', 'heading', 300, 64, 600, 64, '', __('What you get', 'nibwp')],
            ['t', 's', 'text', 360, 140, 480, 48, '', __('One line of intro', 'nibwp')],
            ['a', 's', 'card', 80, 232, 320, 300, '', __('Icon, short title, two lines', 'nibwp')],
            ['b', 's', 'card', 440, 232, 320, 300, '', __('Icon, short title, two lines', 'nibwp')],
            ['c', 's', 'card', 800, 232, 320, 300, '', __('Icon, short title, two lines', 'nibwp')]
        ] },
        { id: 'pricing', name: __('Pricing', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 680, '', ''],
            ['h', 's', 'heading', 300, 64, 600, 64, '', __('Simple plans', 'nibwp')],
            ['a', 's', 'card', 80, 176, 320, 440, '', __('Plan name, price, 4 features, button', 'nibwp')],
            ['b', 's', 'card', 440, 160, 320, 472, '', __('The plan to pick: highlighted, with a badge', 'nibwp')],
            ['c', 's', 'card', 800, 176, 320, 440, '', __('Plan name, price, 4 features, button', 'nibwp')]
        ] },
        { id: 'testimonials', name: __('Testimonials', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 520, '', ''],
            ['h', 's', 'heading', 300, 64, 600, 64, '', __('What people say', 'nibwp')],
            ['a', 's', 'card', 80, 176, 320, 280, '', __('Quote, name, photo, stars', 'nibwp')],
            ['b', 's', 'card', 440, 176, 320, 280, '', __('Quote, name, photo, stars', 'nibwp')],
            ['c', 's', 'card', 800, 176, 320, 280, '', __('Quote, name, photo, stars', 'nibwp')]
        ] },
        { id: 'faq', name: __('FAQ', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 600, '', ''],
            ['h', 's', 'heading', 300, 64, 600, 64, '', __('Questions, answered', 'nibwp')],
            ['l', 's', 'list', 240, 168, 720, 368, '', __('Six real questions as an accordion', 'nibwp')]
        ] },
        { id: 'cta', name: __('Call to action', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 360, '', __('A bold band in the brand colour', 'nibwp')],
            ['h', 's', 'heading', 260, 80, 680, 72, '', __('One clear ask', 'nibwp')],
            ['t', 's', 'text', 330, 168, 540, 48, '', __('Why now, in one line', 'nibwp')],
            ['b', 's', 'button', 500, 240, 200, 56, '', __('The action', 'nibwp')]
        ] },
        { id: 'contact', name: __('Contact', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 600, '', ''],
            ['c', 's', 'column', 80, 80, 460, 440, '', ''],
            ['h', 'c', 'heading', 80, 96, 440, 64, '', __('Get in touch', 'nibwp')],
            ['t', 'c', 'text', 80, 176, 420, 80, '', __('Address, phone, opening hours', 'nibwp')],
            ['m', 'c', 'image', 80, 280, 440, 224, '', __('A small map', 'nibwp')],
            ['f', 's', 'form', 640, 80, 480, 440, '', __('Name, email, message, send', 'nibwp')]
        ] },
        { id: 'header', name: __('Header', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 88, '', __('Sticky on scroll', 'nibwp')],
            ['o', 's', 'icon', 40, 20, 140, 48, __('Logo', 'nibwp'), ''],
            ['n', 's', 'nav', 380, 28, 440, 32, '', __('The main menu', 'nibwp')],
            ['b', 's', 'button', 980, 20, 180, 48, '', __('Book now', 'nibwp')]
        ] },
        { id: 'footer', name: __('Footer', 'nibwp'), boxes: [
            ['s', '', 'section', 0, 0, 1200, 360, '', ''],
            ['a', 's', 'column', 80, 64, 240, 200, __('About', 'nibwp'), __('Logo and one line', 'nibwp')],
            ['b', 's', 'nav', 360, 64, 220, 200, '', __('Useful links', 'nibwp')],
            ['c', 's', 'nav', 620, 64, 220, 200, '', __('Tours', 'nibwp')],
            ['d', 's', 'form', 880, 64, 240, 200, '', __('Newsletter sign-up', 'nibwp')],
            ['t', 's', 'text', 80, 296, 1040, 32, '', __('Copyright and legal links', 'nibwp')]
        ] }
    ];

    /* ------------------------------------------------------------------
     * State.
     * ---------------------------------------------------------------- */

    var items = Array.isArray(cfg.items) ? cfg.items : [];
    var wf = null;
    var sel = '';
    var tool = 'sketch';
    var side = 'start';
    var past = [];
    var future = [];
    var dirty = false;
    var saveT = 0;
    var zoom = 1;
    var brief = null;
    var preview = false;
    var lastSent = null;

    function uid() { return Math.random().toString(36).slice(2, 9); }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function snap(v) { return Math.round(v / SNAP) * SNAP; }
    function box(id) { return wf ? wf.boxes.find(function (b) { return b.id === id; }) : null; }
    function kids(id) { return wf.boxes.filter(function (b) { return b.parent === id; }); }
    function family(id) {
        var out = [id];
        kids(id).forEach(function (k) { out = out.concat(family(k.id)); });
        return out;
    }
    function depth(b) {
        var d = 0;
        var p = b;
        while (p && p.parent && d < 12) { p = box(p.parent); d++; }
        return d;
    }
    function bottom(w) {
        return (w.boxes || []).reduce(function (m, b) { return Math.max(m, b.y + b.h); }, 0);
    }
    function savedSystem() {
        try { return localStorage.getItem(SYS_KEY) || ''; } catch (e) { return ''; }
    }
    function blank(device) {
        device = WIDTH[device] ? device : 'desktop';
        return { id: '', name: '', device: device, width: WIDTH[device], boxes: [], brief: { text: '', system: savedSystem(), builder: 'auto', layout: 'auto', style: 'system', content: 'real' } };
    }

    /* ------------------------------------------------------------------
     * The window.
     * ---------------------------------------------------------------- */

    var board = document.createElement('div');
    board.className = 'nst-bld';
    board.id = 'nst-bld';
    board.hidden = true;
    board.setAttribute('role', 'dialog');
    board.setAttribute('aria-label', __('Builder', 'nibwp'));
    board.innerHTML =
        '<header class="nst-bld__head">' +
            '<span class="nst-bld__logo">' + ico('builder', 18) + '</span>' +
            '<input class="nst-bld__name" type="text" maxlength="80" aria-label="' + esc(__('Design name', 'nibwp')) + '" placeholder="' + esc(__('Untitled design', 'nibwp')) + '">' +
            '<span class="nst-bld__saved" aria-live="polite"></span>' +
            '<div class="nst-bld__devs" role="radiogroup" aria-label="' + esc(__('Screen size', 'nibwp')) + '">' +
                ['desktop', 'tablet', 'mobile'].map(function (d) {
                    return '<button type="button" role="radio" data-bld-dev="' + d + '">' + esc({ desktop: __('Desktop', 'nibwp'), tablet: __('Tablet', 'nibwp'), mobile: __('Phone', 'nibwp') }[d]) + '</button>';
                }).join('') +
            '</div>' +
            '<div class="nst-bld__hist">' +
                '<button type="button" class="nst-bld__ib" data-bld="undo" aria-label="' + esc(__('Undo', 'nibwp')) + '" data-tip="' + esc(__('Undo (Ctrl+Z)', 'nibwp')) + '">' + ico('undo') + '</button>' +
                '<button type="button" class="nst-bld__ib" data-bld="redo" aria-label="' + esc(__('Redo', 'nibwp')) + '" data-tip="' + esc(__('Redo (Ctrl+Shift+Z)', 'nibwp')) + '">' + ico('redo') + '</button>' +
            '</div>' +
            '<span class="nst-bld__grow"></span>' +
            '<button type="button" class="nst-bld__lib" data-bld="preview" aria-pressed="false">' + ico('eye') + '<span class="nst-bld__pv">' + esc(__('Preview', 'nibwp')) + '</span></button>' +
            '<button type="button" class="nst-bld__lib" data-bld="library" aria-pressed="false">' + ico('library') + '<span>' + esc(__('Library', 'nibwp')) + '</span><b class="nst-bld__count">0</b></button>' +
            '<button type="button" class="nst-bld__done" data-bld="brief">' + esc(__('Describe it and build', 'nibwp')) + ' <span aria-hidden="true">→</span></button>' +
            '<button type="button" class="nst-bld__ib nst-bld__close" data-bld="close" aria-label="' + esc(__('Close the Builder', 'nibwp')) + '">' + ico('x', 18) + '</button>' +
        '</header>' +
        '<div class="nst-bld__body">' +
            '<div class="nst-bld__tools" role="toolbar" aria-orientation="vertical" aria-label="' + esc(__('Parts to draw', 'nibwp')) + '">' +
                TOOLS.map(function (t) {
                    var name = t === 'select' ? __('Select and move', 'nibwp') : t === 'sketch' ? __('Draw by hand', 'nibwp') : TYPES[t].name;
                    var key = t === 'select' ? 'V' : t === 'sketch' ? 'D' : TYPES[t].key.toUpperCase();
                    var label = t === 'select' ? __('Select', 'nibwp') : t === 'sketch' ? __('Sketch', 'nibwp') : name;
                    return '<button type="button" class="nst-bld__tool" data-bld-tool="' + t + '" aria-pressed="false" aria-label="' + esc(name + ' (' + key + ')') + '">' + ico(t, 18) + '<span>' + esc(label) + '</span></button>' +
                        (t === 'sketch' ? '<i class="nst-bld__sep" aria-hidden="true"></i>' : '');
                }).join('') +
            '</div>' +
            '<div class="nst-bld__stage">' +
                '<div class="nst-bld__paper" tabindex="-1"><div class="nst-bld__cols" aria-hidden="true"></div><svg class="nst-bld__ink" aria-hidden="true"><path/></svg><div class="nst-bld__ghost" hidden></div></div>' +
                '<p class="nst-bld__empty">' + esc(__('Draw a rough box by hand and say what goes in it. Or pick a part on the left, or drop a ready-made section from the right.', 'nibwp')) + '</p>' +
            '</div>' +
            '<aside class="nst-bld__side" aria-live="polite"></aside>' +
        '</div>';
    root.appendChild(board);

    var nameIn = board.querySelector('.nst-bld__name');
    var savedEl = board.querySelector('.nst-bld__saved');
    var stage = board.querySelector('.nst-bld__stage');
    var paper = board.querySelector('.nst-bld__paper');
    var ghost = board.querySelector('.nst-bld__ghost');
    var ink = board.querySelector('.nst-bld__ink path');
    var sideEl = board.querySelector('.nst-bld__side');
    var emptyEl = board.querySelector('.nst-bld__empty');
    var countEl = board.querySelector('.nst-bld__count');
    var pvBtn = board.querySelector('[data-bld="preview"]');

    function fitBoard() {
        var s = document.getElementById('nst-stage');
        var r = s ? s.getBoundingClientRect() : { left: 0, top: 48, width: window.innerWidth, height: window.innerHeight - 48 };
        board.style.left = r.left + 'px';
        board.style.top = r.top + 'px';
        board.style.width = r.width + 'px';
        board.style.height = preview ? '' : r.height + 'px';
    }

    function open(w) {
        if (kit.stopMarking) { kit.stopMarking(); }
        wf = w ? clone(w) : blank(kit.device());
        wf.brief = wf.brief || blank('desktop').brief;
        sel = '';
        past = [];
        future = [];
        dirty = false;
        brief = null;
        lastSent = null;
        setPreview(false);
        setTool(wf.boxes.length ? 'select' : 'sketch');
        nameIn.value = wf.name || '';
        savedEl.textContent = '';
        board.hidden = false;
        root.classList.add('is-wiring');
        fitBoard();
        side = 'start';
        render();
        setTimeout(function () { paper.focus({ preventScroll: true }); }, 30);
    }

    function close() {
        flushSave();
        endPlace(true);
        closePop(false);
        setPreview(false);
        board.hidden = true;
        root.classList.remove('is-wiring');
        document.dispatchEvent(new CustomEvent('nibwp-sketch-closed'));
    }

    // Preview: the sheet flips over to the live page, in the same window,
    // with this bar still on top to flip back.
    function setPreview(on) {
        preview = !!on;
        board.classList.toggle('is-preview', preview);
        pvBtn.setAttribute('aria-pressed', String(preview));
        pvBtn.querySelector('.nst-bld__pv').textContent = preview ? __('Back to the Builder', 'nibwp') : __('Preview', 'nibwp');
        if (!preview) { endPlace(true); }
        if (!board.hidden) {
            fitBoard();
            if (!preview && !REDUCED && paper.animate) {
                paper.animate([{ transform: 'perspective(1400px) rotateY(-80deg)', opacity: 0.3 }, { transform: 'none', opacity: 1 }], { duration: 380, easing: 'cubic-bezier(.2,.8,.2,1)' });
            }
            if (!preview) { render(); }
        }
    }

    /* ------------------------------------------------------------------
     * Drawing it.
     * ---------------------------------------------------------------- */

    function layoutPaper() {
        var avail = Math.max(320, stage.clientWidth - 56);
        zoom = Math.min(1, avail / wf.width);
        var h = Math.max(stage.clientHeight / zoom - 40, bottom(wf) + 240, 560);
        paper.style.width = Math.round(wf.width * zoom) + 'px';
        paper.style.height = Math.round(h * zoom) + 'px';
        paper.style.setProperty('--bld-col', (wf.width / 12 * zoom) + 'px');
    }

    function render() {
        if (!wf) { return; }
        layoutPaper();
        Array.prototype.slice.call(paper.querySelectorAll('.nst-bld__box')).forEach(function (n) { n.remove(); });
        var ordered = wf.boxes.slice().sort(function (a, b) { return depth(a) - depth(b); });
        ordered.forEach(function (b) {
            var n = document.createElement('div');
            n.className = 'nst-bld__box' + (b.id === sel ? ' is-sel' : '') + (TYPES[b.type] && TYPES[b.type].box ? ' is-holder' : ' is-leaf');
            n.dataset.id = b.id;
            n.dataset.type = b.type;
            n.dataset.depth = String(Math.min(depth(b), 4));
            n.tabIndex = 0;
            n.setAttribute('role', 'button');
            n.setAttribute('aria-pressed', String(b.id === sel));
            n.setAttribute('aria-label', TYPES[b.type].name + (b.label ? ' “' + b.label + '”' : '') + (b.note ? ': ' + b.note : ''));
            place(n, b);
            n.innerHTML = '<span class="nst-bld__tag">' + ico(b.type, 13) + '<b>' + esc(b.label || TYPES[b.type].name) + '</b>' + (b.note ? '<i class="nst-bld__noted" aria-hidden="true"></i>' : '') + '</span>' +
                '<span class="nst-bld__fill" aria-hidden="true"></span>' +
                (b.note && b.h * zoom > 56 && b.w * zoom > 90 ? '<span class="nst-bld__say" aria-hidden="true">' + esc(b.note) + '</span>' : '') +
                (b.id === sel ? '<i class="nst-bld__h" data-h="nw"></i><i class="nst-bld__h" data-h="ne"></i><i class="nst-bld__h" data-h="sw"></i><i class="nst-bld__h" data-h="se"></i><i class="nst-bld__h" data-h="e"></i><i class="nst-bld__h" data-h="s"></i>' : '');
            paper.appendChild(n);
        });
        emptyEl.hidden = wf.boxes.length > 0;
        board.querySelectorAll('[data-bld-dev]').forEach(function (b) { b.setAttribute('aria-checked', String(b.dataset.bldDev === wf.device)); });
        board.querySelector('[data-bld="undo"]').disabled = !past.length;
        board.querySelector('[data-bld="redo"]').disabled = !future.length;
        board.querySelector('[data-bld="brief"]').disabled = !wf.boxes.length;
        countEl.textContent = String(items.length);
        renderSide();
    }

    function place(n, b) {
        n.style.left = (b.x * zoom) + 'px';
        n.style.top = (b.y * zoom) + 'px';
        n.style.width = (b.w * zoom) + 'px';
        n.style.height = (b.h * zoom) + 'px';
    }

    function setTool(t) {
        tool = TOOLS.indexOf(t) > -1 ? t : 'select';
        board.querySelectorAll('[data-bld-tool]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.bldTool === tool)); });
        board.classList.toggle('is-drawing', tool !== 'select');
        board.classList.toggle('is-sketching', tool === 'sketch');
    }

    function remember() {
        past.push(JSON.stringify(wf.boxes));
        if (past.length > 80) { past.shift(); }
        future = [];
    }
    function changed() {
        dirty = true;
        savedEl.textContent = '';
        clearTimeout(saveT);
        saveT = setTimeout(save, 900);
    }
    function undo(back) {
        var from = back ? past : future;
        var to = back ? future : past;
        if (!from.length) { return; }
        closePop(false);
        to.push(JSON.stringify(wf.boxes));
        wf.boxes = JSON.parse(from.pop());
        if (sel && !box(sel)) { sel = ''; }
        render();
        changed();
    }

    // The smallest box that holds this rectangle, which becomes its parent.
    function holderOf(r, skip) {
        var best = null;
        wf.boxes.forEach(function (b) {
            if (skip && skip.indexOf(b.id) > -1) { return; }
            if (!TYPES[b.type].box) { return; }
            if (r.x >= b.x - 2 && r.y >= b.y - 2 && r.x + r.w <= b.x + b.w + 2 && r.y + r.h <= b.y + b.h + 2) {
                if (!best || b.w * b.h < best.w * best.h) { best = b; }
            }
        });
        return best ? best.id : '';
    }

    function pt(e) {
        var r = paper.getBoundingClientRect();
        return { x: (e.clientX - r.left) / zoom, y: (e.clientY - r.top) / zoom };
    }

    // Edges stick to the 8px grid and to the 12 column lines.
    function snapX(x) {
        var col = wf.width / 12;
        var near = Math.round(x / col) * col;
        return Math.abs(near - x) < 10 ? Math.round(near) : snap(x);
    }

    var drag = null;
    paper.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || !wf || e.target.closest('.nst-bld__pop')) { return; }
        closePop(true);
        var handle = e.target.closest('.nst-bld__h');
        var hit = e.target.closest('.nst-bld__box');
        var p = pt(e);
        paper.focus({ preventScroll: true });
        if (handle && sel) {
            remember();
            drag = { kind: 'size', h: handle.dataset.h, id: sel, start: p, orig: clone(box(sel)) };
        } else if (tool === 'select' && hit) {
            select(hit.dataset.id, true);
            remember();
            var fam = family(hit.dataset.id);
            drag = { kind: 'move', id: hit.dataset.id, start: p, orig: fam.map(function (id) { return clone(box(id)); }), moved: false };
        } else if (tool === 'select') {
            select('');
            return;
        } else if (tool === 'sketch') {
            drag = { kind: 'sketch', pts: [p] };
            ink.setAttribute('d', 'M' + (p.x * zoom).toFixed(1) + ' ' + (p.y * zoom).toFixed(1));
            ink.parentNode.classList.add('is-on');
        } else {
            drag = { kind: 'draw', start: { x: snapX(p.x), y: snap(p.y) } };
            ghost.hidden = false;
        }
        paper.setPointerCapture(e.pointerId);
        e.preventDefault();
    });

    paper.addEventListener('pointermove', function (e) {
        if (!drag) { return; }
        var p = pt(e);
        if (drag.kind === 'sketch') {
            drag.pts.push(p);
            ink.setAttribute('d', ink.getAttribute('d') + ' L' + (p.x * zoom).toFixed(1) + ' ' + (p.y * zoom).toFixed(1));
            if (p.y * zoom > paper.offsetHeight - 80) { paper.style.height = Math.round((p.y + 240) * zoom) + 'px'; }
        } else if (drag.kind === 'draw') {
            var x0 = Math.max(0, Math.min(drag.start.x, snapX(p.x)));
            var y0 = Math.max(0, Math.min(drag.start.y, snap(p.y)));
            var x1 = Math.min(wf.width, Math.max(drag.start.x, snapX(p.x)));
            var y1 = Math.max(drag.start.y, snap(p.y));
            drag.r = { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
            place(ghost, drag.r);
            if (y1 * zoom > paper.offsetHeight - 80) { paper.style.height = Math.round((y1 + 240) * zoom) + 'px'; }
        } else if (drag.kind === 'move') {
            var dx = snap(p.x - drag.start.x);
            var dy = snap(p.y - drag.start.y);
            var lead = drag.orig[0];
            dx = Math.max(-lead.x, Math.min(wf.width - lead.x - lead.w, dx));
            dy = Math.max(-lead.y, dy);
            if (dx || dy) { drag.moved = true; }
            drag.orig.forEach(function (o) {
                var b = box(o.id);
                b.x = o.x + dx;
                b.y = o.y + dy;
                var n = paper.querySelector('.nst-bld__box[data-id="' + o.id + '"]');
                if (n) { place(n, b); }
            });
        } else if (drag.kind === 'size') {
            var b = box(drag.id);
            var o = drag.orig;
            var h = drag.h;
            if (/e/.test(h)) { b.w = Math.max(MIN, Math.min(wf.width - o.x, snapX(o.x + o.w + p.x - drag.start.x) - o.x)); }
            if (/s/.test(h)) { b.h = Math.max(MIN, snap(o.h + p.y - drag.start.y)); }
            if (/w/.test(h)) { var nx = Math.max(0, Math.min(o.x + o.w - MIN, snapX(o.x + p.x - drag.start.x))); b.w = o.w + o.x - nx; b.x = nx; }
            if (/n/.test(h)) { var ny = Math.max(0, Math.min(o.y + o.h - MIN, snap(o.y + p.y - drag.start.y))); b.h = o.h + o.y - ny; b.y = ny; }
            var n = paper.querySelector('.nst-bld__box[data-id="' + b.id + '"]');
            if (n) { place(n, b); }
        }
    });

    // A rough shape drawn by hand becomes the neat box it was meant to be.
    function fromSketch(pts) {
        var xs = pts.map(function (p) { return p.x; });
        var ys = pts.map(function (p) { return p.y; });
        var x0 = Math.max(0, snapX(Math.min.apply(null, xs)));
        var y0 = Math.max(0, snap(Math.min.apply(null, ys)));
        var x1 = Math.min(wf.width, snapX(Math.max.apply(null, xs)));
        var y1 = snap(Math.max.apply(null, ys));
        return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
    }

    function endDrag() {
        if (!drag) { return; }
        var d = drag;
        drag = null;
        ghost.hidden = true;
        if (d.kind === 'sketch' || d.kind === 'draw') {
            var r = d.kind === 'sketch' ? fromSketch(d.pts) : d.r;
            var inkSvg = ink.parentNode;
            if (!r || r.w < MIN || r.h < MIN) { inkSvg.classList.remove('is-on'); return; }
            remember();
            var type = d.kind === 'sketch' ? 'box' : tool;
            var b = { id: 'b' + uid(), parent: holderOf(r), type: type, label: '', note: '', layout: 'auto', x: r.x, y: r.y, w: r.w, h: r.h };
            wf.boxes.push(b);
            // Columns come split: three to start with, changed in the panel.
            if (type === 'columns') { split(b, 3, true); }
            sel = b.id;
            side = 'part';
            changed();
            render();
            if (d.kind === 'sketch') {
                inkSvg.classList.add('is-fading');
                setTimeout(function () { inkSvg.classList.remove('is-on', 'is-fading'); }, REDUCED ? 0 : 260);
                var n = paper.querySelector('.nst-bld__box[data-id="' + b.id + '"]');
                if (n && !REDUCED && n.animate) { n.animate([{ transform: 'scale(.94)', opacity: 0.4 }, { transform: 'none', opacity: 1 }], { duration: 240, easing: 'cubic-bezier(.2,1.4,.4,1)' }); }
            }
            askAt(b, d.kind === 'sketch');
            return;
        }
        if (d.kind === 'move' && !d.moved) { past.pop(); return; }
        // Moved or resized: it may now sit in another box.
        var m = box(d.id);
        var fam = family(d.id);
        m.parent = holderOf(m, fam);
        changed();
        render();
    }
    paper.addEventListener('pointerup', endDrag);
    paper.addEventListener('pointercancel', endDrag);
    paper.addEventListener('dblclick', function (e) {
        var hit = e.target.closest('.nst-bld__box');
        if (!hit) { return; }
        select(hit.dataset.id);
        askAt(box(hit.dataset.id), false);
    });

    function select(id, quiet) {
        sel = id || '';
        side = sel ? 'part' : (side === 'part' ? 'start' : side);
        if (!quiet || !paper.querySelector('.nst-bld__box[data-id="' + sel + '"].is-sel')) { render(); } else { renderSide(); }
    }

    /* ------------------------------------------------------------------
     * Right where you drew it: what goes here? The words pick the kind
     * ("a big photo" is an image) unless you pick one yourself.
     * ---------------------------------------------------------------- */

    var pop = null;
    function askAt(b, fresh) {
        closePop(true);
        if (!b) { return; }
        var node = document.createElement('div');
        node.className = 'nst-bld__pop';
        node.setAttribute('role', 'dialog');
        node.setAttribute('aria-label', __('What goes here?', 'nibwp'));
        node.innerHTML =
            '<label class="nst-bld__pop-q"><span>' + esc(__('What goes here?', 'nibwp')) + '</span>' +
                '<textarea rows="2" placeholder="' + esc(fresh ? __('e.g. A big title, three cards with prices, a photo of the Colosseum…', 'nibwp') : TYPES[b.type].hint) + '">' + esc(b.note) + '</textarea></label>' +
            '<div class="nst-bld__pop-kinds" role="radiogroup" aria-label="' + esc(__('Kind', 'nibwp')) + '">' +
                QUICK.map(function (k) { return '<button type="button" role="radio" aria-checked="' + (b.type === k) + '" data-bld-kind="' + k + '" aria-label="' + esc(TYPES[k].name) + '" data-tip="' + esc(TYPES[k].name) + '">' + ico(k, 15) + '</button>'; }).join('') +
            '</div>' +
            '<p class="nst-bld__pop-foot"><span>' + esc(__('Enter to keep · Esc to skip', 'nibwp')) + '</span><button type="button" class="nst-bld__btn is-main" data-bld-pop="ok">' + esc(__('Done', 'nibwp')) + '</button></p>';
        pop = { node: node, id: b.id, picked: !fresh, snap: JSON.stringify(wf.boxes) };
        paper.appendChild(node);
        var top = (b.y + b.h) * zoom + 8;
        var left = Math.min(b.x * zoom, paper.offsetWidth - 320);
        if (top + 190 > paper.offsetHeight && b.y * zoom > 200) { top = b.y * zoom - 198; }
        node.style.left = Math.max(4, left) + 'px';
        node.style.top = Math.max(4, top) + 'px';
        var f = node.querySelector('textarea');
        setTimeout(function () { f.focus({ preventScroll: true }); node.scrollIntoView({ block: 'nearest', behavior: REDUCED ? 'auto' : 'smooth' }); }, 20);
    }

    function closePop(keep) {
        if (!pop) { return; }
        var p = pop;
        pop = null;
        if (keep) { applyPop(p); }
        p.node.remove();
    }

    function applyPop(p) {
        var b = box(p.id);
        if (!b) { return; }
        var text = p.node.querySelector('textarea').value.trim();
        var before = JSON.stringify(wf.boxes);
        b.note = text;
        var picked = p.node.querySelector('[data-bld-kind][aria-checked="true"]');
        var kind = picked ? picked.dataset.bldKind : b.type;
        if (kind !== b.type) { b.type = kind; }
        // "three columns" splits it in three.
        var many = /\b(two|three|four|2|3|4)\s+(columns?|cards?|tiles?|plans?)\b/i.exec(text);
        if (many && TYPES[b.type].box && !kids(b.id).length) {
            var n = { two: 2, three: 3, four: 4 }[many[1].toLowerCase()] || +many[1];
            if (/column/i.test(many[2])) { b.type = 'columns'; split(b, n, true); } else { splitInto(b, n, 'card'); }
        }
        if (JSON.stringify(wf.boxes) !== before) {
            past.push(p.snap);
            future = [];
            changed();
        }
        render();
    }

    paper.addEventListener('input', function (e) {
        if (!pop || !e.target.closest('.nst-bld__pop textarea')) { return; }
        if (pop.picked) { return; }
        var g = guess(e.target.value);
        pop.node.querySelectorAll('[data-bld-kind]').forEach(function (k) { k.setAttribute('aria-checked', String(!!g && k.dataset.bldKind === g)); });
    });
    paper.addEventListener('click', function (e) {
        if (!pop) { return; }
        var k = e.target.closest('[data-bld-kind]');
        if (k) {
            pop.picked = true;
            pop.node.querySelectorAll('[data-bld-kind]').forEach(function (x) { x.setAttribute('aria-checked', String(x === k)); });
            pop.node.querySelector('textarea').focus();
            return;
        }
        if (e.target.closest('[data-bld-pop="ok"]')) { closePop(true); paper.focus({ preventScroll: true }); }
    });
    paper.addEventListener('keydown', function (e) {
        if (!pop || !e.target.closest('.nst-bld__pop')) { return; }
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); closePop(true); paper.focus({ preventScroll: true }); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePop(false); render(); paper.focus({ preventScroll: true }); }
    });

    // Columns side by side inside a box, evenly, with a gap.
    function split(b, n, fresh) {
        if (!fresh) {
            remember();
            kids(b.id).filter(function (k) { return k.type === 'column'; }).forEach(function (k) { removeBox(k.id); });
        }
        splitInto(b, n, 'column');
        if (b.layout === 'auto') { b.layout = 'row'; }
    }
    function splitInto(b, n, type) {
        var gap = wf.width >= 1000 ? 32 : 16;
        var pad = 16;
        var w = Math.floor((b.w - pad * 2 - gap * (n - 1)) / n);
        for (var i = 0; i < n; i++) {
            wf.boxes.push({ id: 'b' + uid(), parent: b.id, type: type, label: '', note: '', layout: 'auto', x: b.x + pad + i * (w + gap), y: b.y + pad + 20, w: w, h: Math.max(MIN, b.h - pad * 2 - 20) });
        }
    }

    var ADD_H = { heading: 56, text: 72, image: 200, button: 48, card: 240, list: 160, form: 200, video: 220 };
    function addInside(b, type) {
        remember();
        var inner = kids(b.id);
        var y = inner.length ? inner.reduce(function (m, k) { return Math.max(m, k.y + k.h); }, 0) + 16 : b.y + 40;
        var w = type === 'button' ? Math.min(220, b.w - 32) : b.w - 32;
        var h = ADD_H[type] || 80;
        var c = { id: 'b' + uid(), parent: b.id, type: type, label: '', note: '', layout: 'auto', x: b.x + 16, y: snap(y), w: Math.max(MIN, w), h: h };
        wf.boxes.push(c);
        // The box, and every box around it, grows to hold it.
        var p = b;
        var need = c.y + c.h + 16;
        while (p) {
            if (p.y + p.h < need) { p.h = snap(need - p.y); }
            need = p.y + p.h + 16;
            p = p.parent ? box(p.parent) : null;
        }
        sel = c.id;
        side = 'part';
        changed();
        render();
        askAt(c, false);
    }

    function removeBox(id) {
        var gone = family(id);
        wf.boxes = wf.boxes.filter(function (b) { return gone.indexOf(b.id) < 0; });
    }

    function duplicate(id) {
        var b = box(id);
        if (!b) { return; }
        remember();
        var fam = family(id).map(box);
        var map = {};
        fam.forEach(function (x) { map[x.id] = 'b' + uid(); });
        var dy = b.h + 24;
        fam.forEach(function (x) {
            var c = clone(x);
            c.id = map[x.id];
            c.parent = map[x.parent] || x.parent;
            c.y += dy;
            wf.boxes.push(c);
        });
        sel = map[id];
        changed();
        render();
    }

    // Another design's parts, laid under this one's.
    function insertBoxes(list, srcWidth) {
        remember();
        var k = wf.width / (srcWidth || 1200);
        var top = wf.boxes.length ? bottom(wf) + 32 : 0;
        var y0 = list.reduce(function (m, b) { return Math.min(m, b.y); }, Infinity);
        var map = {};
        list.forEach(function (b) { map[b.id] = 'b' + uid(); });
        list.forEach(function (b) {
            wf.boxes.push({ id: map[b.id], parent: b.parent && map[b.parent] ? map[b.parent] : '', type: TYPES[b.type] ? b.type : 'box', label: b.label || '', note: b.note || '', layout: b.layout || 'auto', x: Math.round(b.x * k), y: b.y - y0 + top, w: Math.max(MIN, Math.round(b.w * k)), h: b.h });
        });
        var first = list.filter(function (b) { return !b.parent || !map[b.parent]; })[0];
        sel = first ? map[first.id] : '';
        side = sel ? 'part' : 'start';
        setTool('select');
        changed();
        render();
        var n = sel && paper.querySelector('.nst-bld__box[data-id="' + sel + '"]');
        if (n) { n.scrollIntoView({ block: 'nearest', behavior: REDUCED ? 'auto' : 'smooth' }); }
    }

    /* ------------------------------------------------------------------
     * The side panel: a part, the starting points, the brief, the library,
     * and what happened after sending.
     * ---------------------------------------------------------------- */

    function chips(name, value, opts) {
        return '<div class="nst-bld__chips" role="radiogroup" data-bld-group="' + name + '">' + opts.map(function (o) {
            return '<button type="button" role="radio" aria-checked="' + (o[0] === value) + '" data-bld-pick="' + esc(o[0]) + '"' + (o[2] ? ' data-tip="' + esc(o[2]) + '"' : '') + '>' + esc(o[1]) + '</button>';
        }).join('') + '</div>';
    }

    function renderSide() {
        board.querySelector('[data-bld="library"]').setAttribute('aria-pressed', String(side === 'library'));
        if (side === 'part' && box(sel)) { sideEl.innerHTML = partHtml(box(sel)); return; }
        if (side === 'brief') { sideEl.innerHTML = briefHtml(); return; }
        if (side === 'library') { sideEl.innerHTML = libraryHtml(); return; }
        if (side === 'sent' && lastSent) { sideEl.innerHTML = sentHtml(); return; }
        sideEl.innerHTML = startHtml();
    }

    function startHtml() {
        return '<div class="nst-bld__pane">' +
            '<h3>' + esc(__('Drop in a section', 'nibwp')) + '</h3>' +
            '<p class="nst-bld__muted">' + esc(__('Lands under what you have, ready to change.', 'nibwp')) + '</p>' +
            '<div class="nst-bld__starters">' + STARTERS.map(function (s) {
                return '<button type="button" class="nst-bld__starter" data-bld-starter="' + s.id + '">' + thumb({ width: 1200, boxes: s.boxes.map(rowToBox) }, 'nst-bld__mini') + '<span>' + esc(s.name) + '</span></button>';
            }).join('') + '</div>' +
            (items.filter(function (w) { return !wf || w.id !== wf.id; }).length ? '<button type="button" class="nst-bld__btn" data-bld="library">' + ico('library') + esc(__('Drop in one of your designs', 'nibwp')) + '</button>' : '') +
            '<h3>' + esc(__('How it works', 'nibwp')) + '</h3>' +
            '<ol class="nst-bld__how">' +
                '<li>' + esc(__('Sketch a rough box by hand, or pick a part on the left. Say what goes in it.', 'nibwp')) + '</li>' +
                '<li>' + esc(__('Draw inside a box to put parts in it. Columns split themselves.', 'nibwp')) + '</li>' +
                '<li>' + esc(__('Describe it: what it is for and how your design system should look. Your agent builds it with your builder, responsive and accessible.', 'nibwp')) + '</li>' +
                '<li>' + esc(__('Preview flips to your page and back, right here.', 'nibwp')) + '</li>' +
            '</ol>' +
            '<p class="nst-bld__keys">' + esc(__('Keys: D sketch · V select · S section · G columns · H heading · T text · I image · U button · K card · double-click a part to say what goes in it · Delete removes · Ctrl+D copies · arrows nudge', 'nibwp')) + '</p>' +
        '</div>';
    }

    function partHtml(b) {
        var t = TYPES[b.type];
        var inner = family(b.id).length - 1;
        return '<div class="nst-bld__pane">' +
            '<div class="nst-bld__part-head">' + ico(b.type, 18) + '<h3>' + esc(b.label || t.name) + '</h3>' +
                '<button type="button" class="nst-bld__ib" data-bld="deselect" aria-label="' + esc(__('Back', 'nibwp')) + '">' + ico('x') + '</button></div>' +
            '<label class="nst-bld__field"><span>' + esc(__('What goes here?', 'nibwp')) + '</span>' +
                '<textarea data-bld-field="note" rows="4" placeholder="' + esc(t.hint) + '">' + esc(b.note) + '</textarea></label>' +
            '<div class="nst-bld__row2">' +
                '<label class="nst-bld__field"><span>' + esc(__('Kind', 'nibwp')) + '</span><select data-bld-field="type">' +
                    Object.keys(TYPES).map(function (k) { return '<option value="' + k + '"' + (k === b.type ? ' selected' : '') + '>' + esc(TYPES[k].name) + '</option>'; }).join('') +
                '</select></label>' +
                '<label class="nst-bld__field"><span>' + esc(__('Name', 'nibwp')) + '</span><input data-bld-field="label" type="text" maxlength="80" value="' + esc(b.label) + '" placeholder="' + esc(t.name) + '"></label>' +
            '</div>' +
            (t.box ? '<div class="nst-bld__field"><span>' + esc(__('Add inside', 'nibwp')) + '</span><div class="nst-bld__adds">' +
                    ['heading', 'text', 'image', 'button', 'card', 'list', 'form', 'video'].map(function (k) {
                        return '<button type="button" data-bld-add="' + k + '" data-tip="' + esc(TYPES[k].name) + '" aria-label="' + esc(sprintf(__('Add a %s inside', 'nibwp'), TYPES[k].name.toLowerCase())) + '">' + ico(k, 17) + '</button>';
                    }).join('') + '</div></div>' +
                '<div class="nst-bld__row2">' +
                    '<div class="nst-bld__field"><span>' + esc(__('Split in columns', 'nibwp')) + '</span><div class="nst-bld__seg">' +
                        [2, 3, 4].map(function (n) { return '<button type="button" data-bld-split="' + n + '">' + n + '</button>'; }).join('') + '</div></div>' +
                    '<label class="nst-bld__field"><span>' + esc(__('Parts inside sit', 'nibwp')) + '</span><select data-bld-field="layout">' +
                        [['auto', __('Auto', 'nibwp')], ['row', __('In a row', 'nibwp')], ['column', __('Stacked', 'nibwp')], ['grid', __('In a grid', 'nibwp')]].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === b.layout ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') +
                    '</select></label>' +
                '</div>' : '') +
            '<p class="nst-bld__muted">' + esc(sprintf(_n('%d part inside', '%d parts inside', inner, 'nibwp'), inner)) + (inner ? '' : ' · ' + esc(__('draw inside it to add one', 'nibwp'))) + '</p>' +
            '<div class="nst-bld__acts">' +
                '<button type="button" class="nst-bld__btn" data-bld="dup">' + ico('copy') + esc(__('Copy', 'nibwp')) + '</button>' +
                '<button type="button" class="nst-bld__btn is-danger" data-bld="del">' + ico('trash') + esc(__('Remove', 'nibwp')) + '</button>' +
            '</div>' +
            '<div class="nst-bld__ask">' +
                '<p>' + esc(__('Already on the page? Have just this part built or filled in.', 'nibwp')) + '</p>' +
                '<button type="button" class="nst-bld__btn is-main" data-bld="part">' + ico('build') + esc(__('Build just this part', 'nibwp')) + '</button>' +
            '</div>' +
        '</div>';
    }

    function briefHtml() {
        var b = brief;
        var builders = [['auto', __('Auto', 'nibwp'), __('The builder this page uses, or the block editor', 'nibwp')]].concat((cfg.builders || []).map(function (x) { return [x.id, x.name]; }));
        var sys = cfg.system ? sprintf(__('Design system (%s)', 'nibwp'), cfg.system) : __('Design system', 'nibwp');
        return '<div class="nst-bld__pane nst-bld__brief">' +
            '<h3>' + esc(__('What should this be?', 'nibwp')) + '</h3>' +
            '<label class="nst-bld__field"><span class="screen-reader-text">' + esc(__('What should this be?', 'nibwp')) + '</span>' +
                '<textarea data-bld-brief="text" rows="3" placeholder="' + esc(__('e.g. A pricing section for our three tour packages: friendly, clear, the middle one stands out', 'nibwp')) + '">' + esc(b.text) + '</textarea></label>' +
            '<div class="nst-bld__always" role="note">' +
                '<span data-tip="' + esc(__('Always included: phone, tablet and desktop', 'nibwp')) + '">' + ico('check', 13) + esc(__('Responsive', 'nibwp')) + '</span>' +
                '<span data-tip="' + esc(__('Always included: headings, labels, alt text, contrast, keyboard', 'nibwp')) + '">' + ico('check', 13) + esc(__('Accessible', 'nibwp')) + '</span>' +
            '</div>' +
            '<label class="nst-bld__field"><span>' + esc(__('Design system and style', 'nibwp')) + '</span>' +
                '<textarea data-bld-brief="system" rows="3" placeholder="' + esc(cfg.system === 'Automatic.css' ? __('e.g. Our Automatic.css tokens, primary colour for buttons, generous section spacing, rounded cards, Inter for headings', 'nibwp') : __('e.g. Brand orange #ff8a3d for buttons, Inter headings, 8px spacing scale, rounded cards with soft shadows', 'nibwp')) + '">' + esc(b.system) + '</textarea></label>' +
            // The choices that rarely change sit in one line; Adjust opens them.
            '<p class="nst-bld__defaults">' + esc([sprintf(__('Built with %s', 'nibwp'), builderName(b.builder)),
                { auto: __('best-fit layout', 'nibwp'), flex: __('flexbox', 'nibwp'), grid: __('CSS grid', 'nibwp') }[b.layout],
                { system: sys.toLowerCase(), page: __('matches this page', 'nibwp'), fresh: __('fresh look', 'nibwp') }[b.style],
                { real: __('real copy', 'nibwp'), placeholder: __('placeholders', 'nibwp'), empty: __('empty slots', 'nibwp') }[b.content]].join(' · ')) +
                ' <button type="button" class="nst-bld__link" data-bld="adjust" aria-expanded="' + !!b.adjust + '">' + esc(b.adjust ? __('Done', 'nibwp') : __('Adjust', 'nibwp')) + '</button></p>' +
            (b.adjust ? '<div class="nst-bld__adjust">' +
                sel3('builder', __('Build it with', 'nibwp'), b.builder, builders) +
                sel3('blayout', __('Layout', 'nibwp'), b.layout, [['auto', __('Best fit: grid for cards, flex for rows', 'nibwp')], ['flex', __('Flexbox', 'nibwp')], ['grid', __('CSS grid', 'nibwp')]]) +
                sel3('style', __('Look', 'nibwp'), b.style, [['system', sys], ['page', __('Match this page', 'nibwp')], ['fresh', __('Fresh look', 'nibwp')]]) +
                sel3('content', __('Words and pictures', 'nibwp'), b.content, [['real', __('Real copy', 'nibwp')], ['placeholder', __('Placeholders', 'nibwp')], ['empty', __('Empty slots', 'nibwp')]]) +
            '</div>' : '') +
            '<div class="nst-bld__go">' +
                '<button type="button" class="nst-bld__go-btn is-main" data-bld="build" data-where="drop">' + ico('drop', 18) + '<span><b>' + esc(__('Drop it on the page', 'nibwp')) + '</b><small>' + esc(__('Pick the spot in the preview', 'nibwp')) + '</small></span></button>' +
                '<button type="button" class="nst-bld__go-btn" data-bld="build" data-where="end">' + ico('plus', 18) + '<span><b>' + esc(__('Add it at the end', 'nibwp')) + '</b><small>' + esc(__('Of the page on screen', 'nibwp')) + '</small></span></button>' +
                '<button type="button" class="nst-bld__go-btn" data-bld="build" data-where="new">' + ico('copy', 18) + '<span><b>' + esc(__('Build a new page', 'nibwp')) + '</b><small>' + esc(__('A draft named after it', 'nibwp')) + '</small></span></button>' +
            '</div>' +
            '<div class="nst-bld__acts">' +
                '<button type="button" class="nst-bld__btn" data-bld="back">' + esc(__('Back to drawing', 'nibwp')) + '</button>' +
                '<button type="button" class="nst-bld__btn" data-bld="save">' + esc(__('Save to library', 'nibwp')) + '</button>' +
            '</div>' +
        '</div>';
    }

    function sel3(name, label, value, opts) {
        return '<label class="nst-bld__field"><span>' + esc(label) + '</span><select data-bld-setting="' + name + '">' +
            opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>';
    }

    function sentHtml() {
        return '<div class="nst-bld__pane nst-bld__sent">' +
            '<span class="nst-bld__sent-ok">' + ico('check', 22) + '</span>' +
            '<h3>' + esc(__('Sent to your agent', 'nibwp')) + '</h3>' +
            '<p class="nst-bld__muted">' + esc(lastSent) + '</p>' +
            '<p class="nst-bld__muted">' + esc(__('Follow it in the chat on the left. Preview flips to the page so you can watch it being built.', 'nibwp')) + '</p>' +
            '<div class="nst-bld__acts">' +
                '<button type="button" class="nst-bld__btn is-main" data-bld="preview">' + ico('eye') + esc(__('Preview the page', 'nibwp')) + '</button>' +
                '<button type="button" class="nst-bld__btn" data-bld="back">' + esc(__('Keep drawing', 'nibwp')) + '</button>' +
            '</div>' +
        '</div>';
    }

    function when(ts) {
        if (!ts) { return ''; }
        var d = new Date(ts * 1000);
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' · ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    function libraryHtml() {
        return '<div class="nst-bld__pane">' +
            '<div class="nst-bld__part-head"><h3>' + esc(__('Your designs', 'nibwp')) + '</h3>' +
                '<button type="button" class="nst-bld__btn" data-bld="new">' + ico('plus') + esc(__('New', 'nibwp')) + '</button></div>' +
            (items.length ? '<ul class="nst-bld__lib-list">' + items.map(function (w) {
                var on = wf && w.id === wf.id;
                var act = function (a, ic, label, cls) { return '<button type="button"' + (cls ? ' class="' + cls + '"' : '') + ' data-bld-act="' + a + '" data-tip="' + esc(label) + '" aria-label="' + esc(label) + '">' + ico(ic, 14) + '</button>'; };
                return '<li class="nst-bld__item' + (on ? ' is-on' : '') + '" data-bld-item="' + esc(w.id) + '">' +
                    '<button type="button" class="nst-bld__item-open" data-bld-act="open" aria-label="' + esc(sprintf(__('Open %s', 'nibwp'), w.name)) + '">' + thumb(w, 'nst-bld__thumb') + '</button>' +
                    '<div class="nst-bld__item-body">' +
                        '<b class="nst-bld__item-name">' + esc(w.name) + (on ? ' <small>' + esc(__('open', 'nibwp')) + '</small>' : '') + '</b>' +
                        '<span class="nst-bld__muted">' + esc(sprintf(_n('%d part', '%d parts', (w.boxes || []).length, 'nibwp'), (w.boxes || []).length)) + ' · ' + esc(when(w.updated)) + '</span>' +
                        '<div class="nst-bld__item-acts">' +
                            (on ? '' : act('insert', 'insert', __('Drop it into this design', 'nibwp'))) +
                            act('open', 'edit', __('Open and edit', 'nibwp')) +
                            act('drop', 'drop', __('Drop it on the page', 'nibwp')) +
                            act('copy', 'copy', __('Make a copy', 'nibwp')) +
                            act('delete', 'trash', __('Delete', 'nibwp'), 'is-danger') +
                        '</div>' +
                    '</div>' +
                '</li>';
            }).join('') + '</ul>' : '<p class="nst-bld__muted">' + esc(__('Nothing saved yet. Every design you draw is kept here on its own.', 'nibwp')) + '</p>') +
        '</div>';
    }

    function rowToBox(r) {
        return { id: r[0], parent: r[1], type: r[2], x: r[3], y: r[4], w: r[5], h: r[6], label: r[7] || '', note: r[8] || '', layout: 'auto' };
    }

    // A small picture of a design: its boxes, darker the deeper they sit.
    function thumb(w, cls) {
        var h = Math.max(120, bottom(w));
        var byId = {};
        (w.boxes || []).forEach(function (b) { byId[b.id] = b; });
        var lvl = function (b) { var d = 0; while (b && b.parent && byId[b.parent] && d < 6) { b = byId[b.parent]; d++; } return d; };
        return '<svg class="' + cls + '" viewBox="0 0 ' + w.width + ' ' + h + '" preserveAspectRatio="xMidYMin meet" aria-hidden="true" focusable="false">' +
            (w.boxes || []).slice().sort(function (a, b) { return lvl(a) - lvl(b); }).map(function (b) {
                var leaf = !(TYPES[b.type] && TYPES[b.type].box);
                return '<rect x="' + b.x + '" y="' + b.y + '" width="' + b.w + '" height="' + b.h + '" rx="' + (leaf ? 6 : 10) + '" class="is-d' + Math.min(lvl(b), 3) + (leaf ? ' is-leaf' : '') + '"/>';
            }).join('') + '</svg>';
    }

    /* ------------------------------------------------------------------
     * Clicks and keys.
     * ---------------------------------------------------------------- */

    board.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('.nst-bld__pop')) { return; }
        var dev = t.closest('[data-bld-dev]');
        if (dev) { setDevice(dev.dataset.bldDev); return; }
        var tl = t.closest('[data-bld-tool]');
        if (tl) { if (preview) { setPreview(false); } setTool(tl.dataset.bldTool); return; }
        var st = t.closest('[data-bld-starter]');
        if (st) { addStarter(st.dataset.bldStarter); return; }
        var ad = t.closest('[data-bld-add]');
        if (ad && box(sel)) { addInside(box(sel), ad.dataset.bldAdd); return; }
        var sp = t.closest('[data-bld-split]');
        if (sp && box(sel)) { split(box(sel), +sp.dataset.bldSplit); changed(); render(); return; }
        var pk = t.closest('[data-bld-pick]');
        if (pk) { pick(pk.closest('[data-bld-group]').dataset.bldGroup, pk.dataset.bldPick); return; }
        var ia = t.closest('[data-bld-act]');
        if (ia) { libAct(ia.dataset.bldAct, ia.closest('[data-bld-item]').dataset.bldItem, ia); return; }
        var a = t.closest('[data-bld]');
        if (!a) { return; }
        switch (a.dataset.bld) {
            case 'close': close(); break;
            case 'undo': undo(true); break;
            case 'redo': undo(false); break;
            case 'preview': setPreview(!preview); break;
            case 'library': if (preview) { setPreview(false); } side = side === 'library' ? (sel ? 'part' : 'start') : 'library'; renderSide(); break;
            case 'brief': if (preview) { setPreview(false); } openBrief(); break;
            case 'back': if (preview) { setPreview(false); } side = sel ? 'part' : 'start'; renderSide(); break;
            case 'deselect': select(''); break;
            case 'dup': duplicate(sel); break;
            case 'del': remember(); removeBox(sel); sel = ''; side = 'start'; changed(); render(); break;
            case 'part': buildPart(a); break;
            case 'save': readBrief(); save(true); break;
            case 'build': readBrief(); if (a.dataset.where) { brief.where = a.dataset.where; } build(a); break;
            case 'adjust': readBrief(); brief.adjust = !brief.adjust; renderSide(); break;
            case 'new': flushSave(); open(null); break;
        }
    });

    function pick(group, value) {
        if (group === 'layout' && box(sel)) {
            remember();
            box(sel).layout = value;
            changed();
            renderSide();
            return;
        }
        if (!brief) { return; }
        readBrief();
        var key = { builder: 'builder', blayout: 'layout', style: 'style', content: 'content', where: 'where' }[group];
        if (key) { brief[key] = value; }
        renderSide();
    }

    // Typing in a part's fields is one undo step, taken at the first key.
    var typedSnap = null;
    sideEl.addEventListener('focusin', function (e) {
        if (e.target.closest('[data-bld-field="note"], [data-bld-field="label"]') && box(sel)) { typedSnap = JSON.stringify(wf.boxes); }
    });
    sideEl.addEventListener('input', function (e) {
        var f = e.target.closest('[data-bld-field]');
        if (!f || !box(sel)) { return; }
        var b = box(sel);
        var k = f.dataset.bldField;
        if (k === 'type' || k === 'layout') { return; }
        if (typedSnap) { past.push(typedSnap); future = []; typedSnap = null; }
        b[k] = f.value;
        var n = paper.querySelector('.nst-bld__box[data-id="' + b.id + '"] .nst-bld__tag b');
        if (n && k === 'label') { n.textContent = b.label || TYPES[b.type].name; }
        changed();
    });
    sideEl.addEventListener('change', function (e) {
        var st = e.target.closest('[data-bld-setting]');
        if (st && brief) {
            readBrief();
            brief[st.dataset.bldSetting === 'blayout' ? 'layout' : st.dataset.bldSetting] = st.value;
            renderSide();
            return;
        }
        var f = e.target.closest('[data-bld-field="type"], [data-bld-field="layout"]');
        if (!f || !box(sel)) { return; }
        remember();
        box(sel)[f.dataset.bldField] = f.value;
        changed();
        render();
    });

    nameIn.addEventListener('input', function () { if (wf) { wf.name = nameIn.value; changed(); } });

    document.addEventListener('keydown', function (e) {
        if (board.hidden || placing) { return; }
        var typing = e.target.closest && e.target.closest('input, textarea, select, [contenteditable]');
        if (e.key === 'Escape') {
            e.preventDefault();
            if (typing) { e.target.blur(); return; }
            if (preview) { setPreview(false); return; }
            if (side === 'brief' || side === 'sent' || side === 'library') { side = sel ? 'part' : 'start'; renderSide(); return; }
            if (sel) { select(''); return; }
            if (tool !== 'sketch' && tool !== 'select') { setTool('sketch'); return; }
            close();
            return;
        }
        if (typing || preview) { return; }
        var k = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); undo(!e.shiftKey); return; }
        if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); undo(false); return; }
        if ((e.ctrlKey || e.metaKey) && k === 'd') { e.preventDefault(); if (sel) { duplicate(sel); } return; }
        if (e.ctrlKey || e.metaKey || e.altKey) { return; }
        if ((e.key === 'Delete' || e.key === 'Backspace') && sel) {
            e.preventDefault();
            remember();
            removeBox(sel);
            sel = '';
            side = 'start';
            changed();
            render();
            return;
        }
        if (/^Arrow/.test(e.key) && sel) {
            e.preventDefault();
            var step = e.shiftKey ? 1 : SNAP;
            var dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0;
            var dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0;
            remember();
            family(sel).forEach(function (id) { var b = box(id); b.x = Math.max(0, b.x + dx); b.y = Math.max(0, b.y + dy); });
            changed();
            render();
            return;
        }
        if (e.key === 'Enter' && e.target.closest && e.target.closest('.nst-bld__box')) {
            e.preventDefault();
            select(e.target.closest('.nst-bld__box').dataset.id);
            askAt(box(sel), false);
            return;
        }
        if (KEYS[k]) { e.preventDefault(); setTool(KEYS[k]); }
    });

    // Tab onto a part selects it.
    paper.addEventListener('focusin', function (e) {
        var n = e.target.closest('.nst-bld__box');
        if (n && n.dataset.id !== sel) {
            sel = n.dataset.id;
            side = 'part';
            renderSide();
            paper.querySelectorAll('.nst-bld__box').forEach(function (x) { x.classList.toggle('is-sel', x.dataset.id === sel); x.setAttribute('aria-pressed', String(x.dataset.id === sel)); });
        }
    });

    function setDevice(d) {
        if (!WIDTH[d] || !wf || d === wf.device) { return; }
        closePop(true);
        remember();
        var k = WIDTH[d] / wf.width;
        wf.boxes.forEach(function (b) { b.x = Math.round(b.x * k); b.w = Math.max(MIN, Math.round(b.w * k)); });
        wf.width = WIDTH[d];
        wf.device = d;
        changed();
        render();
    }

    function addStarter(id) {
        var s = STARTERS.find(function (x) { return x.id === id; });
        if (!s) { return; }
        if (!wf.name) { wf.name = s.name; nameIn.value = s.name; }
        insertBoxes(s.boxes.map(rowToBox), 1200);
    }

    /* ------------------------------------------------------------------
     * Saving and the library.
     * ---------------------------------------------------------------- */

    function save(loud) {
        clearTimeout(saveT);
        if (!wf || !wf.boxes.length) { return Promise.resolve(null); }
        if (!dirty && wf.id && !loud) { return Promise.resolve(wf); }
        dirty = false;
        savedEl.textContent = __('Saving…', 'nibwp');
        var body = clone(wf);
        if (brief) { body.brief = briefOut(); }
        return kit.api('designs', { method: 'POST', body: { design: body } }).then(function (r) {
            if (!r || !r.design) { return null; }
            if (wf && (!wf.id || wf.id === r.design.id)) { wf.id = r.design.id; }
            items = [r.design].concat(items.filter(function (x) { return x.id !== r.design.id; }));
            savedEl.textContent = __('Saved', 'nibwp');
            countEl.textContent = String(items.length);
            if (side === 'library') { renderSide(); }
            if (loud) { kit.toast(__('Design saved to your library.', 'nibwp'), 'ok'); }
            return r.design;
        }, function () {
            dirty = true;
            savedEl.textContent = __('Not saved', 'nibwp');
            return null;
        });
    }
    function flushSave() { if (dirty) { save(); } }

    function libAct(act, id, btn) {
        var w = items.find(function (x) { return x.id === id; });
        if (!w) { return; }
        if (act === 'open') { flushSave(); open(w); return; }
        if (act === 'insert') { insertBoxes(clone(w.boxes || []), w.width); kit.toast(sprintf(__('“%s” dropped in.', 'nibwp'), w.name), 'ok'); return; }
        if (act === 'copy') {
            var c = clone(w);
            c.id = '';
            c.name = sprintf(__('%s (copy)', 'nibwp'), w.name);
            kit.api('designs', { method: 'POST', body: { design: c } }).then(function (r) {
                if (r && r.design) { items = [r.design].concat(items); renderSide(); countEl.textContent = String(items.length); }
            });
            return;
        }
        if (act === 'delete') {
            if (btn.dataset.sure !== '1') {
                btn.dataset.sure = '1';
                btn.classList.add('is-sure');
                btn.setAttribute('data-tip', __('Click again to delete', 'nibwp'));
                setTimeout(function () { if (btn.isConnected) { btn.dataset.sure = ''; btn.classList.remove('is-sure'); btn.setAttribute('data-tip', __('Delete', 'nibwp')); } }, 3000);
                return;
            }
            kit.api('design-delete', { method: 'POST', body: { id: id } }).then(function () {
                items = items.filter(function (x) { return x.id !== id; });
                if (wf && wf.id === id) { wf.id = ''; dirty = false; }
                renderSide();
                countEl.textContent = String(items.length);
                kit.toast(__('Design deleted.', 'nibwp'), 'ok');
            });
            return;
        }
        if (act === 'drop') {
            flushSave();
            open(w);
            openBrief();
            brief.where = 'drop';
            renderSide();
        }
    }

    /* ------------------------------------------------------------------
     * The brief, and sending it to the agent.
     * ---------------------------------------------------------------- */

    function openBrief() {
        if (!wf || !wf.boxes.length) { return; }
        closePop(true);
        var b = wf.brief || {};
        brief = { text: b.text || '', system: b.system || savedSystem(), builder: b.builder || 'auto', layout: b.layout || 'auto', style: b.style || 'system', content: b.content || 'real', where: brief ? brief.where : 'drop' };
        if (!kit.page().url) { brief.where = 'new'; }
        side = 'brief';
        renderSide();
        setTimeout(function () { var f = sideEl.querySelector('[data-bld-brief="text"]'); if (f) { f.focus(); } }, 30);
    }
    function readBrief() {
        if (!brief) { return; }
        var f = sideEl.querySelector('[data-bld-brief="text"]');
        var s = sideEl.querySelector('[data-bld-brief="system"]');
        if (f) { brief.text = f.value.trim(); }
        if (s) {
            brief.system = s.value.trim();
            try { localStorage.setItem(SYS_KEY, brief.system); } catch (e) { }
        }
    }
    function briefOut() {
        return { text: brief.text, system: brief.system, builder: brief.builder, layout: brief.layout, style: brief.style, content: brief.content };
    }

    function builderName(id) {
        if (id === 'auto') { return kit.page().builder || __('this page’s builder', 'nibwp'); }
        var b = (cfg.builders || []).find(function (x) { return x.id === id; });
        return b ? b.name : id;
    }

    function build(btn) {
        if (!wf || !wf.boxes.length) { return; }
        wf.brief = briefOut();
        if (brief.where === 'drop') {
            if (!kit.page().url) { kit.toast(__('Open a page first, then pick the spot.', 'nibwp')); return; }
            startPlace();
            return;
        }
        send({ mode: brief.where }, '', btn);
    }

    function buildPart(btn) {
        var b = box(sel);
        if (!b) { return; }
        if (!b.note.trim()) {
            var f = sideEl.querySelector('[data-bld-field="note"]');
            if (f) { f.focus(); }
            kit.toast(__('Say what goes in this part first.', 'nibwp'));
            return;
        }
        wf.brief = wf.brief || blank('desktop').brief;
        send({ mode: 'end' }, b.id, btn);
    }

    function send(place, part, from) {
        save(false);
        var spec = clone(wf);
        spec.place = place;
        if (part) { spec.part = part; }
        var name = wf.name || __('Untitled design', 'nibwp');
        var pb = part ? box(part) : null;
        /* translators: 1: a part of the design, 2: the design's name, 3: what should go in that part */
        var text = pb ? sprintf(__('Build the “%1$s” part of my design “%2$s”: %3$s', 'nibwp'), pb.label || TYPES[pb.type].name, name, pb.note)
            /* translators: 1: the design's name, 2: what it is for */
            : sprintf(__('Build my design “%1$s”%2$s', 'nibwp'), name, wf.brief.text ? ': ' + wf.brief.text : '.');
        text += '\n' + sprintf(
            /* translators: 1: number of parts, 2: a page builder name */
            _n('(%1$d part, built with %2$s, responsive and accessible)', '(%1$d parts, built with %2$s, responsive and accessible)', wf.boxes.length, 'nibwp'),
            wf.boxes.length, builderName(wf.brief.builder));
        kit.sendDesign(spec, text, from);
        // Stays open: what happened, and a way to watch it on the page.
        lastSent = place.mode === 'drop'
            ? sprintf(__('“%1$s” goes %2$s “%3$s”.', 'nibwp'), name, place.position === 'before' ? __('before', 'nibwp') : __('after', 'nibwp'), place.near_text || place.selector)
            : place.mode === 'new' ? sprintf(__('“%s” goes on a new page.', 'nibwp'), name) : sprintf(__('“%s” goes at the end of this page.', 'nibwp'), name);
        side = 'sent';
        savedEl.textContent = __('Sent', 'nibwp');
        if (!preview) { renderSide(); }
    }

    /* ------------------------------------------------------------------
     * Picking the spot, in the preview: a line follows the pointer between
     * the page's big blocks; a click drops the design there.
     * ---------------------------------------------------------------- */

    var placing = null;
    function startPlace() {
        var frame = kit.frame();
        var doc = kit.frameDoc();
        if (!frame || !doc) { kit.toast(__('The page is not ready yet. Try again in a moment.', 'nibwp')); return; }
        setPreview(true);
        var shield = document.createElement('div');
        shield.className = 'nst-bld-place';
        shield.innerHTML = '<div class="nst-bld-place__bar">' + ico('drop', 16) + '<span>' + esc(__('Click where it should go. Esc to go back.', 'nibwp')) + '</span><button type="button" data-bld-place="cancel">' + esc(__('Back', 'nibwp')) + '</button></div>' +
            '<div class="nst-bld-place__line" hidden><span></span></div>' +
            '<div class="nst-bld-place__card">' + thumb(wf, 'nst-bld__mini') + '<b>' + esc(wf.name || __('Untitled design', 'nibwp')) + '</b></div>';
        root.appendChild(shield);
        var fr = frame.getBoundingClientRect();
        var head = board.querySelector('.nst-bld__head').getBoundingClientRect();
        var top = Math.max(fr.top, head.bottom);
        shield.style.left = fr.left + 'px';
        shield.style.top = top + 'px';
        shield.style.width = fr.width + 'px';
        shield.style.height = (fr.bottom - top) + 'px';
        // k: the Builder may draw a wide page smaller to fit; the page's own
        // coordinates are the sheet's divided by it.
        placing = { shield: shield, frame: frame, doc: doc, dy: top - fr.top, k: frame.offsetWidth ? fr.width / frame.offsetWidth : 1, target: null, pos: 'after' };
        shield.addEventListener('pointermove', placeMove);
        shield.addEventListener('click', placeClick);
        shield.addEventListener('wheel', function (e) { e.preventDefault(); frame.contentWindow.scrollBy(0, e.deltaY); placeMove(e); }, { passive: false });
    }

    // The block under the pointer worth dropping next to: a full-width part
    // of the page with neighbours, but not the wrapper around everything.
    function blockAt(doc, x, y) {
        var n = doc.elementFromPoint(x, y);
        var vw = doc.documentElement.clientWidth;
        var tall = Math.max(doc.documentElement.scrollHeight, 1) * 0.6;
        var fits = [];
        while (n && n !== doc.body && n !== doc.documentElement) {
            var r = n.getBoundingClientRect();
            var sibs = n.parentElement ? Array.prototype.filter.call(n.parentElement.children, function (c) { return c.getBoundingClientRect().height > 0; }).length : 0;
            if (r.width >= vw * 0.6 && r.height >= 24 && sibs > 1 && !/^(script|style|link)$/.test(n.localName)) { fits.push(n); }
            n = n.parentElement;
        }
        var parts = fits.filter(function (f) { return f.getBoundingClientRect().height < tall; });
        return parts.length ? parts[parts.length - 1] : fits[0] || null;
    }

    function placeMove(e) {
        if (!placing) { return; }
        var sr = placing.shield.getBoundingClientRect();
        var x = e.clientX - sr.left;
        var y = e.clientY - sr.top + placing.dy;
        var card = placing.shield.querySelector('.nst-bld-place__card');
        card.style.transform = 'translate(' + (x + 16) + 'px,' + (y - placing.dy + 16) + 'px)';
        var k = placing.k;
        var t = blockAt(placing.doc, x / k, y / k);
        var line = placing.shield.querySelector('.nst-bld-place__line');
        if (!t) { line.hidden = true; placing.target = null; return; }
        var r = t.getBoundingClientRect();
        placing.target = t;
        placing.pos = y / k < r.top + r.height / 2 ? 'before' : 'after';
        line.hidden = false;
        line.style.top = Math.max(0, (placing.pos === 'before' ? r.top : r.bottom) * k - placing.dy) + 'px';
        line.style.left = Math.max(0, r.left * k) + 'px';
        line.style.width = Math.min(sr.width, r.width * k) + 'px';
        var what = t.localName + (t.id ? '#' + t.id : t.classList[0] ? '.' + t.classList[0] : '');
        var said = (t.innerText || '').trim().split('\n')[0].slice(0, 40);
        line.querySelector('span').textContent = (placing.pos === 'before' ? __('Before', 'nibwp') : __('After', 'nibwp')) + ' ' + what + (said ? ' · “' + said + '”' : '');
    }

    function placeClick(e) {
        if (!placing) { return; }
        if (e.target.closest('[data-bld-place="cancel"]')) { endPlace(false); return; }
        if (!placing.target) { kit.toast(__('Point at a part of the page first.', 'nibwp')); return; }
        var t = placing.target;
        var spot = {
            mode: 'drop',
            selector: kit.cssPath(t),
            position: placing.pos,
            near_text: (t.innerText || '').trim().split('\n')[0].slice(0, 120),
            page_url: kit.page().url
        };
        var lr = placing.shield.querySelector('.nst-bld-place__line').getBoundingClientRect();
        endPlace(true);
        send(spot, '', { left: lr.left, top: lr.top - 20, width: lr.width, height: 40 });
    }

    function endPlace(done) {
        if (!placing) { return; }
        placing.shield.remove();
        placing = null;
        if (!done) { setPreview(false); }
    }
    document.addEventListener('keydown', function (e) {
        if (placing && e.key === 'Escape') { e.preventDefault(); endPlace(false); }
    });

    /* ------------------------------------------------------------------
     * Opening it.
     * ---------------------------------------------------------------- */

    window.addEventListener('resize', function () { if (!board.hidden) { fitBoard(); if (!preview) { render(); } } });
    // The Builder opens the sketch sheet from its bar.
    window.nibwpSketch = {
        open: open,
        close: close,
        isOpen: function () { return !board.hidden; },
        list: function () { return items.slice(); }
    };
}());
