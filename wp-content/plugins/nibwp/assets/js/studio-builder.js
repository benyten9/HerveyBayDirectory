/**
 * Agent View Builder: an AI builder on the live page. Add any element, a
 * hand-drawn wireframe or one of your components by dragging it onto the
 * page or the structure; move, duplicate, remove; edit the HTML, CSS and JS
 * in the code dock and see it at once; undo and redo anything. Every change
 * waits in one list and Save hands them all to NibWP in one request; the
 * page refreshes only when you pause, never in the middle of an edit. Ask
 * NibWP anything about an element, with files attached. Templates joins each
 * kind of content (pages, posts, custom types, WooCommerce) to the template
 * that shows it. Only this site's own pages are edited. Esc puts it away.
 */
(function () {
    'use strict';

    var boot = window.nibwpStudio || {};
    var bridge = window.nibwpStudioBridge;
    var kit = bridge && bridge.kit;
    var root = document.getElementById('nst');
    var openBtn = document.getElementById('nst-wire');
    var canvas = document.getElementById('nst-canvas');
    var artboard = document.getElementById('nst-artboard');
    if (!kit || !root || !openBtn || !canvas || !artboard) { return; }

    var __ = wp.i18n.__;
    var _n = wp.i18n._n;
    var sprintf = wp.i18n.sprintf;
    var esc = kit.esc;
    var cfg = boot.builder || {};
    var REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;

    var SKIP = /^(script|style|link|meta|noscript|template|title|head|br|wbr|source|track|param|base)$/;
    var LEAF = /^(svg|iframe|video|audio|canvas|img|picture|input|textarea|select|object|embed|hr)$/;
    var HOLD = /^(div|section|main|header|footer|article|aside|nav|ul|ol|form|figure|details|fieldset|body|li|table|tbody|thead|tr|td|th|label|blockquote|dl)$/;

    var I = {
        box: '<path d="M4 4h16v16H4z"/>',
        section: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/>',
        container: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 4v16M16 4v16"/>',
        columns: '<path d="M3 5h18v14H3zM9 5v14M15 5v14"/>',
        grid: '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        heading: '<path d="M6 5v14M18 5v14M6 12h12"/>',
        text: '<path d="M4 7h16M4 12h16M4 17h10"/>',
        image: '<path d="M3 5h18v14H3z"/><path d="M3 16l5-5 4 4 3-3 6 6"/>',
        link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        button: '<path d="M6 8h12a4 4 0 0 1 0 8H6a4 4 0 0 1 0-8z"/>',
        card: '<path d="M5 3h14v18H5z"/><path d="M5 10h14M8 13.5h8M8 17h5"/>',
        list: '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        form: '<path d="M4 4h16v4H4zM4 11h16v4H4z"/><path d="M4 18h7v3H4z"/>',
        input: '<path d="M3 8h18v8H3z"/><path d="M7 11v2"/>',
        nav: '<path d="M3 7h4M10 7h4M17 7h4M3 16h18"/>',
        media: '<path d="M3 5h18v14H3z"/><path d="M10 9l5 3-5 3z"/>',
        video: '<path d="M3 5h18v14H3z"/><path d="M10 9l5 3-5 3z"/>',
        icon: '<circle cx="12" cy="12" r="8.5"/>',
        quote: '<path d="M7 7h4v4c0 3-2 5-4 6M15 7h4v4c0 3-2 5-4 6"/>',
        table: '<path d="M3 5h18v14H3zM3 10h18M3 15h18M9 5v14"/>',
        accordion: '<path d="M4 5h16v4H4zM4 11h16v4H4zM4 17h16v3H4z"/>',
        divider: '<path d="M3 12h18"/>',
        spacer: '<path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4"/>',
        code: '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 5l-3 14"/>',
        map: '<path d="M9 4l-6 3v13l6-3 6 3 6-3V4l-6 3z"/><path d="M9 4v13M15 7v13"/>',
        comp: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
        chev: '<path d="M9 6l6 6-6 6"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        tree: '<rect x="3" y="3.5" width="7" height="5" rx="1.2"/><rect x="12" y="10" width="9" height="4.5" rx="1.2"/><rect x="12" y="16.5" width="9" height="4.5" rx="1.2"/><path d="M6.5 8.5v10h5.5M6.5 12.25h5.5"/>',
        div: '<rect x="4" y="4" width="16" height="16" rx="2" stroke-dasharray="3 2.5"/>',
        iframe: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 8h18"/><path d="M10 12l-2 2 2 2M14 12l2 2-2 2"/>',
        star: '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z"/>',
        textaa: '<path d="M3 18l4.5-12L12 18M4.6 14h5.8"/><path d="M20.5 18v-5a2.6 2.6 0 0 0-5 0M15.5 15.5c0 1.6 1.1 2.5 2.4 2.5 1.4 0 2.6-1 2.6-2.8"/>',
        attrs: '<path d="M4 7h10M4 12h16M4 17h7"/><circle cx="17" cy="7" r="2"/><circle cx="14" cy="17" r="2"/>',
        sliders: '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        keys: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h.01M11 10h.01M15 10h.01M7 14h10"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        copy: '<path d="M8 8h12v12H8z"/><path d="M16 8V4H4v12h4"/>',
        attach: '<path d="M21 11.5l-8.6 8.6a5 5 0 0 1-7.1-7.1l8.6-8.6a3.5 3.5 0 0 1 5 5l-8.6 8.6a2 2 0 0 1-2.8-2.8l7.9-7.9"/>',
        send: '<path d="M4 12l16-8-6 16-2.5-6.5z"/>',
        spark: '<path d="M12 2c.8 5.6 4.4 9.2 10 10-5.6.8-9.2 4.4-10 10-.8-5.6-4.4-9.2-10-10 5.6-.8 9.2-4.4 10-10Z"/>',
        x: '<path d="M6 6l12 12M18 6 6 18"/>',
        sketch: '<path d="M4 20l4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/>',
        fold: '<path d="M7 9l5-5 5 5M7 15l5 5 5-5"/>',
        unfold: '<path d="M7 4l5 5 5-5M7 20l5-5 5 5"/>',
        open: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v6H4V6h6"/>',
        dup: '<path d="M8 8h12v12H8z"/><path d="M4 16V4h12"/>',
        trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        phone: '<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18h2"/>',
        tablet: '<rect x="4.5" y="2.5" width="15" height="19" rx="2"/><path d="M11 18h2"/>',
        desktop: '<rect x="2.5" y="4" width="19" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        tpl: '<path d="M3 4h18v5H3zM3 11h8v9H3zM13 11h8v9h-8z"/>',
        undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        redo: '<path d="M15 14l5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
        save: '<path d="M5 4h11l3 3v13H5z"/><path d="M8 4v5h7V4M8 20v-6h8v6"/>',
        comment: '<path d="M4 5h16v11H9l-5 4z"/>',
        play: '<path d="M7 5l12 7-12 7z"/>',
        expand: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
        down: '<path d="M6 9l6 6 6-6"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'c-size': '<path d="M4 18L8.5 6 13 18M5.5 14h6"/><path d="M15 18l3-8 3 8M16 15.5h4"/>',
        'c-bold': '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',
        'c-italic': '<path d="M10 5h8M6 19h8M14 5l-4 14"/>',
        'c-upper': '<path d="M3 18l4-12 4 12M4.5 14h5"/><path d="M14 18V6h4a3 3 0 0 1 0 6h-4M18 12a3 3 0 0 1 0 6h-4"/>',
        'c-track': '<path d="M4 6v12M20 6v12M8 17l4-10 4 10M9.5 13.5h5"/>',
        'c-lh': '<path d="M11 6h9M11 12h9M11 18h9M5 5v14M3 7l2-2 2 2M3 17l2 2 2-2"/>',
        'c-color': '<path d="M5 19l5-14 5 14M7 13h6"/><path d="M4 21h16" stroke-width="2.5"/>',
        'c-al': '<path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>',
        'c-ac': '<path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>',
        'c-ar': '<path d="M4 6h16M10 10h10M4 14h16M10 18h10"/>',
        'c-aj': '<path d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
        'c-under': '<path d="M7 4v7a5 5 0 0 0 10 0V4M5 20h14"/>',
        'c-strike': '<path d="M16 6.5A4 4 0 0 0 12.5 5h-1A3.5 3.5 0 0 0 8 8.5c0 2 1.5 2.9 4 3.5M4 12h16M8 17.5A4 4 0 0 0 11.5 19h1a3.5 3.5 0 0 0 3.5-3.5"/>',
        'c-balance': '<path d="M4 7h16M6 12h12M8 17h8"/>',
        'c-row': '<rect x="3" y="7" width="5" height="10" rx="1"/><rect x="9.5" y="7" width="5" height="10" rx="1"/><rect x="16" y="7" width="5" height="10" rx="1"/>',
        'c-col': '<rect x="6" y="3" width="12" height="5" rx="1"/><rect x="6" y="9.5" width="12" height="5" rx="1"/><rect x="6" y="16" width="12" height="5" rx="1"/>',
        'c-grid': '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
        'c-g2': '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M12 5v14"/>',
        'c-g3': '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M9 5v14M15 5v14"/>',
        'c-g4': '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M7.5 5v14M12 5v14M16.5 5v14"/>',
        'c-wrap': '<path d="M3 6h14a4 4 0 0 1 0 8H9M12 11l-3 3 3 3M3 18h4"/>',
        'c-jc': '<path d="M12 3v18"/><rect x="5" y="8" width="5" height="8" rx="1"/><rect x="14" y="8" width="5" height="8" rx="1"/>',
        'c-jb': '<path d="M3 3v18M21 3v18"/><rect x="5" y="8" width="5" height="8" rx="1"/><rect x="14" y="8" width="5" height="8" rx="1"/>',
        'c-as': '<path d="M4 4h16"/><rect x="6" y="7" width="4" height="10" rx="1"/><rect x="14" y="7" width="4" height="6" rx="1"/>',
        'c-am': '<path d="M4 12h16"/><rect x="6" y="6" width="4" height="12" rx="1"/><rect x="14" y="8.5" width="4" height="7" rx="1"/>',
        'c-ae': '<path d="M4 20h16"/><rect x="6" y="7" width="4" height="10" rx="1"/><rect x="14" y="11" width="4" height="6" rx="1"/>',
        'c-gap': '<rect x="3" y="6" width="6" height="12" rx="1"/><rect x="15" y="6" width="6" height="12" rx="1"/><path d="M11 9v6M13 9v6"/>',
        'c-block': '<rect x="4" y="6" width="16" height="12" rx="1.5"/>',
        'c-cover': '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M3 15l5-5 4 4 3-3 6 6"/>',
        'c-contain': '<rect x="3" y="5" width="18" height="14" rx="1.5"/><rect x="7" y="8" width="10" height="8" rx="1"/>',
        'c-169': '<rect x="3" y="6.5" width="18" height="11" rx="1.5"/>',
        'c-11': '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
        'c-w': '<path d="M3 12h18M6 9l-3 3 3 3M18 9l3 3-3 3"/>',
        'c-h': '<path d="M12 3v18M9 6l3-3 3 3M9 18l3 3 3-3"/>',
        'c-max': '<path d="M3 4v16M21 4v16"/><rect x="7" y="8" width="10" height="8" rx="1"/>',
        'c-pad': '<rect x="4" y="4" width="16" height="16" rx="1.5"/><rect x="8" y="8" width="8" height="8" rx="1" stroke-dasharray="2 2"/>',
        'c-border': '<rect x="4" y="4" width="16" height="16" rx="1.5" stroke-width="2.4"/>',
        'c-radius': '<path d="M4 20V11a7 7 0 0 1 7-7h9"/>',
        'c-shadow': '<rect x="4" y="4" width="13" height="13" rx="1.5"/><path d="M8 20h12V8"/>',
        'c-bg': '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor"/>',
        'c-rel': '<rect x="4" y="4" width="16" height="16" rx="1.5"/><path d="M12 8v8M8 12h8"/>',
        'c-clip': '<rect x="5" y="5" width="14" height="14" rx="1.5"/><path d="M2 9h3M19 9h3M2 15h3M19 15h3"/>',
        hash: '<path d="M9 4L7 20M17 4l-2 16M4.5 9h15M3.5 15h15"/>',
        layoutdraw: '<rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="3 2.4"/><path d="M3 9.5h18M10 9.5V21"/>',
        up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
        move: '<path d="M12 3v18M3 12h18M12 3l-3 3M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/>',
        swap: '<path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
        eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        eyeoff: '<path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-2.2 3.1M6.6 6.6C3.7 8.3 2 12 2 12s3.6 7 10 7a9.6 9.6 0 0 0 5.4-1.6"/><path d="M3 3l18 18M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        reset: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M10 10l4 4M14 10l-4 4"/>'
    };
    I.palette = '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.7-.8 1.7-1.7 0-.4-.2-.8-.5-1.1-.3-.3-.5-.7-.5-1.2 0-.9.8-1.7 1.7-1.7H16a5 5 0 0 0 5-5c0-4.1-4-7.3-9-7.3z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10.5" cy="7.2" r="1"/><circle cx="15" cy="7.5" r="1"/>';
    I.pageopen = '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>';
    I.siteoff = '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/><path d="M4 4l16 16"/>';
    I.site = '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>';
    I.states = '<path d="M8 4c-2 0-3 1-3 3v2c0 1.5-1 2.5-2 3 1 .5 2 1.5 2 3v2c0 2 1 3 3 3M16 4c2 0 3 1 3 3v2c0 1.5 1 2.5 2 3-1 .5-2 1.5-2 3v2c0 2-1 3-3 3"/>';
    I.hub = '<path d="M12.5 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6.5"/><path d="M9 13h6M9 17h4"/><path d="M18 2.5l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z"/>';
    I.retitle = '<path d="M5 7V5h14v2M12 5v14M9 19h6"/>';
    I.cut = '<circle cx="6" cy="6.5" r="2.5"/><circle cx="6" cy="17.5" r="2.5"/><path d="M8.2 7.8L20 17M8.2 16.2L20 7"/>';
    I.paste = '<rect x="8.5" y="3" width="7" height="3.5" rx="1"/><path d="M15.5 5H18a1.5 1.5 0 0 1 1.5 1.5v13A1.5 1.5 0 0 1 18 21H6a1.5 1.5 0 0 1-1.5-1.5v-13A1.5 1.5 0 0 1 6 5h2.5"/>';
    I.stylecopy = '<path d="M17 4a2.1 2.1 0 0 1 3 3l-2.5 2.5-3-3z"/><path d="M15.5 7.5L6 17l-1.5 3.5L8 19l9.5-9.5"/>';
    I.stylepaste = '<rect x="4" y="3" width="14" height="5" rx="1.5"/><path d="M18 5.5h2V11h-8.5v3"/><rect x="9.5" y="14" width="4" height="7" rx="1"/>';
    I.history = '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7.5V12l3 2"/>';
    I.laptop = '<rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>';
    I.wide = '<rect x="2" y="5" width="20" height="12" rx="1.5"/><path d="M9 21h6M12 17v4"/>';
    // Width × height, isolated so a right-to-left screen does not swap the numbers.
    function wxh(w, h) { return '\u2066' + w + ' × ' + h + '\u2069'; }
    function ico(n, s) {
        return '<svg class="nst-bd-i' + (/^(chev|send|undo|redo)$/.test(n) ? ' nst-dir' : '') + '" viewBox="0 0 24 24" width="' + (s || 15) + '" height="' + (s || 15) + '" aria-hidden="true" focusable="false">' + (I[n] || I.box) + '</svg>';
    }
    function kindOf(el) {
        var t = el.localName;
        if (/^h[1-6]$/.test(t)) { return 'heading'; }
        if (/^(p|span|strong|em|small|label|figcaption|time|b|i)$/.test(t)) { return 'text'; }
        if (t === 'blockquote') { return 'quote'; }
        if (/^(img|picture|figure)$/.test(t)) { return 'image'; }
        if (t === 'a') { return /button|btn/i.test(el.className) ? 'button' : 'link'; }
        if (t === 'button') { return 'button'; }
        if (/^(ul|ol|li|dl)$/.test(t)) { return 'list'; }
        if (/^(form|fieldset)$/.test(t)) { return 'form'; }
        if (/^(input|textarea|select)$/.test(t)) { return 'input'; }
        if (t === 'nav') { return 'nav'; }
        if (/^(video|iframe|audio|canvas)$/.test(t)) { return 'media'; }
        if (t === 'svg') { return 'icon'; }
        if (/^(table|thead|tbody|tr|td|th)$/.test(t)) { return 'table'; }
        if (t === 'details') { return 'accordion'; }
        if (t === 'hr') { return 'divider'; }
        if (/^(section|header|footer|main|article|aside)$/.test(t)) { return 'section'; }
        return 'box';
    }
    function store(k, v) {
        try { if (v === undefined) { return JSON.parse(localStorage.getItem(k) || 'null'); } localStorage.setItem(k, JSON.stringify(v)); } catch (e) { }
        return null;
    }

    /* ------------------------------------------------------------------
     * Elements to add: each one real HTML, plain enough to restyle, that
     * NibWP rebuilds with the page's own builder when you save.
     * ---------------------------------------------------------------- */

    var PH = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500" viewBox="0 0 800 500"><rect width="800" height="500" fill="#e2e8f0"/><path d="M0 400l220-180 160 130 120-90 300 230H0z" fill="#cbd5e1"/><circle cx="600" cy="140" r="56" fill="#cbd5e1"/></svg>');
    var ELEMENTS = [
        { id: 'section', group: 'layout', name: __('Section', 'nibwp'), html: '<section data-nibwp="section" style="padding:72px 24px"><div style="max-width:1120px;margin:0 auto;display:flex;flex-direction:column;gap:16px"><h2>' + __('New section', 'nibwp') + '</h2><p>' + __('Say what goes here, or drop elements in.', 'nibwp') + '</p></div></section>' },
        { id: 'container', group: 'layout', name: __('Container', 'nibwp'), html: '<div data-nibwp="container" style="display:flex;flex-direction:column;gap:16px;padding:24px"><p>' + __('Container', 'nibwp') + '</p></div>' },
        { id: 'columns', group: 'layout', name: __('Columns', 'nibwp'), html: '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;padding:24px"><div><h3>' + __('Column', 'nibwp') + '</h3><p>' + __('Text', 'nibwp') + '</p></div><div><h3>' + __('Column', 'nibwp') + '</h3><p>' + __('Text', 'nibwp') + '</p></div><div><h3>' + __('Column', 'nibwp') + '</h3><p>' + __('Text', 'nibwp') + '</p></div></div>' },
        { id: 'grid', group: 'layout', name: __('Card grid', 'nibwp'), html: '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:24px;padding:24px">' + [1, 2, 3].map(function () { return '<article style="padding:24px;border:1px solid #e5e7eb;border-radius:12px"><img src="' + PH + '" alt="" style="width:100%;height:auto;border-radius:8px"><h3>' + __('Card title', 'nibwp') + '</h3><p>' + __('Short card text.', 'nibwp') + '</p></article>'; }).join('') + '</div>' },
        { id: 'heading', group: 'basic', name: __('Heading', 'nibwp'), html: '<h2>' + __('New heading', 'nibwp') + '</h2>' },
        { id: 'text', group: 'basic', name: __('Text', 'nibwp'), html: '<p>' + __('New paragraph. Click it to change it, or ask NibWP what it should say.', 'nibwp') + '</p>' },
        { id: 'button', group: 'basic', name: __('Button', 'nibwp'), html: '<a href="#" style="display:inline-block;padding:12px 24px;border-radius:8px;background:#111827;color:#fff;text-decoration:none;font-weight:600">' + __('Button', 'nibwp') + '</a>' },
        { id: 'link', group: 'basic', name: __('Link', 'nibwp'), html: '<a href="#">' + __('A link', 'nibwp') + '</a>' },
        { id: 'image', group: 'basic', name: __('Image', 'nibwp'), html: '<img src="' + PH + '" alt="" style="display:block;max-width:100%;height:auto;border-radius:8px">' },
        { id: 'list', group: 'basic', name: __('List', 'nibwp'), html: '<ul><li>' + __('First item', 'nibwp') + '</li><li>' + __('Second item', 'nibwp') + '</li><li>' + __('Third item', 'nibwp') + '</li></ul>' },
        { id: 'quote', group: 'basic', name: __('Quote', 'nibwp'), html: '<blockquote style="margin:0;padding:16px 24px;border-left:4px solid currentColor"><p>' + __('A quote worth reading.', 'nibwp') + '</p><cite>' + __('Someone', 'nibwp') + '</cite></blockquote>' },
        { id: 'divider', group: 'basic', name: __('Divider', 'nibwp'), html: '<hr>' },
        { id: 'spacer', group: 'basic', name: __('Space', 'nibwp'), html: '<div style="height:48px" aria-hidden="true"></div>' },
        { id: 'div', group: 'layout', name: __('Div', 'nibwp'), html: '<div style="min-height:48px;padding:16px"></div>' },
        { id: 'iframe', group: 'blocks', name: __('Iframe', 'nibwp'), html: '<iframe src="about:blank" title="' + __('Embedded page', 'nibwp') + '" style="display:block;width:100%;aspect-ratio:16/9;border:0" loading="lazy"></iframe>' },
        { id: 'icon', group: 'basic', name: __('Icon', 'nibwp'), html: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>' },
        { id: 'card', group: 'blocks', name: __('Card', 'nibwp'), html: '<article style="padding:24px;border:1px solid #e5e7eb;border-radius:12px;display:flex;flex-direction:column;gap:12px"><img src="' + PH + '" alt="" style="width:100%;height:auto;border-radius:8px"><h3>' + __('Card title', 'nibwp') + '</h3><p>' + __('Short card text.', 'nibwp') + '</p><a href="#">' + __('Read more', 'nibwp') + '</a></article>' },
        { id: 'accordion', group: 'blocks', name: __('Accordion', 'nibwp'), html: '<div>' + [1, 2, 3].map(function (n) { return '<details style="padding:12px 0;border-bottom:1px solid #e5e7eb"><summary style="font-weight:600;cursor:pointer">' + sprintf(__('Question %d', 'nibwp'), n) + '</summary><p>' + __('The answer.', 'nibwp') + '</p></details>'; }).join('') + '</div>' },
        { id: 'table', group: 'blocks', name: __('Table', 'nibwp'), html: '<table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb">' + __('Name', 'nibwp') + '</th><th style="text-align:left;padding:8px;border-bottom:2px solid #e5e7eb">' + __('Value', 'nibwp') + '</th></tr></thead><tbody><tr><td style="padding:8px;border-bottom:1px solid #e5e7eb">' + __('Row', 'nibwp') + '</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">' + __('Data', 'nibwp') + '</td></tr></tbody></table>' },
        { id: 'nav', group: 'blocks', name: __('Menu', 'nibwp'), html: '<nav aria-label="' + __('Menu', 'nibwp') + '"><ul style="display:flex;gap:24px;list-style:none;margin:0;padding:0"><li><a href="#">' + __('Home', 'nibwp') + '</a></li><li><a href="#">' + __('About', 'nibwp') + '</a></li><li><a href="#">' + __('Contact', 'nibwp') + '</a></li></ul></nav>' },
        { id: 'form', group: 'blocks', name: __('Form', 'nibwp'), html: '<form style="display:grid;gap:12px;max-width:440px" onsubmit="return false"><label>' + __('Name', 'nibwp') + '<br><input type="text" style="width:100%"></label><label>' + __('Email', 'nibwp') + '<br><input type="email" style="width:100%"></label><label>' + __('Message', 'nibwp') + '<br><textarea rows="4" style="width:100%"></textarea></label><button type="submit">' + __('Send', 'nibwp') + '</button></form>' },
        { id: 'video', group: 'blocks', name: __('Video', 'nibwp'), html: '<div style="aspect-ratio:16/9;display:grid;place-items:center;background:#0f172a;color:#fff;border-radius:12px">' + __('Video', 'nibwp') + '</div>' },
        { id: 'map', group: 'blocks', name: __('Map', 'nibwp'), html: '<div style="aspect-ratio:16/9;display:grid;place-items:center;background:#e2e8f0;border-radius:12px">' + __('Map', 'nibwp') + '</div>' },
        { id: 'code', group: 'blocks', name: __('HTML', 'nibwp'), html: '<div><p>' + __('Your HTML here: paste anything in the HTML pane below.', 'nibwp') + '</p></div>' }
    ];
    var GROUPS = [['layout', __('Layout', 'nibwp')], ['basic', __('Basics', 'nibwp')], ['blocks', __('Blocks', 'nibwp')]];

    // Ready-made wireframes: a page's sections and columns, to lay out and
    // build from one prompt.
    var W = function (note, kids, cols) { return { note: note || '', h: 0, cols: cols || 0, kids: kids || [] }; };
    var COLS = function (n) { var k = []; for (var i = 0; i < n; i++) { k.push(W('')); } return k; };
    var WIRES = [
        { id: 'w-landing', name: __('Landing page', 'nibwp'), spec: W('', [W(__('Hero: big title, a short line, the main button, a photo', 'nibwp')), W(__('Logos or trust badges', 'nibwp')), W(__('Three key benefits', 'nibwp'), COLS(3), 3), W(__('How it works, in three steps', 'nibwp')), W(__('Reviews', 'nibwp'), COLS(2), 2), W(__('Call to action band', 'nibwp')), W(__('Questions and answers', 'nibwp'))]) },
        { id: 'w-about', name: __('About page', 'nibwp'), spec: W('', [W(__('Hero: who we are, in one line', 'nibwp')), W(__('Our story', 'nibwp'), [W(__('Text', 'nibwp')), W(__('Photo', 'nibwp'))], 2), W(__('The team', 'nibwp'), COLS(4), 4), W(__('What we value', 'nibwp'), COLS(3), 3), W(__('Call to action', 'nibwp'))]) },
        { id: 'w-product', name: __('Product or tour', 'nibwp'), spec: W('', [W(__('Photo gallery', 'nibwp')), W('', [W(__('Title, price, highlights, details', 'nibwp')), W(__('Booking or buy box', 'nibwp'))], 2), W(__('What is included', 'nibwp')), W(__('Reviews', 'nibwp')), W(__('Related', 'nibwp'), COLS(3), 3)]) },
        { id: 'w-contact', name: __('Contact page', 'nibwp'), spec: W('', [W(__('Short hero', 'nibwp')), W('', [W(__('Contact form', 'nibwp')), W(__('Address, phone, hours, map', 'nibwp'))], 2), W(__('Questions and answers', 'nibwp'))]) },
        { id: 'w-pricing', name: __('Pricing page', 'nibwp'), spec: W('', [W(__('Hero', 'nibwp')), W(__('Plans', 'nibwp'), COLS(3), 3), W(__('Compare plans', 'nibwp')), W(__('Questions and answers', 'nibwp')), W(__('Call to action', 'nibwp'))]) },
        { id: 'w-post', name: __('Blog post', 'nibwp'), spec: W('', [W(__('Title, author, date', 'nibwp')), W(__('Featured image', 'nibwp')), W(__('Article', 'nibwp')), W(__('About the author', 'nibwp')), W(__('Related posts', 'nibwp'), COLS(3), 3)]) },
        { id: 'w-2col', name: __('Two columns', 'nibwp'), spec: W('', COLS(2), 2) },
        { id: 'w-3col', name: __('Three columns', 'nibwp'), spec: W('', COLS(3), 3) }
    ];

    function find(name, placeholder) {
        return '<label class="nst-bd__find">' + ico('search', 14) + '<input type="search" data-bd-find="' + name + '" placeholder="' + esc(placeholder) + '" aria-label="' + esc(placeholder) + '"><span class="nst-bd__find-n"></span></label>';
    }
    var shut = store('nibwp-bd-groups') || { sketches: true };
    function group(id, title, inner) {
        return '<section class="nst-bd__grp" data-grp="' + id + '"' + (shut[id] ? ' data-shut' : '') + '><button type="button" class="nst-bd__grp-h" data-bd-grp="' + id + '" aria-expanded="' + !shut[id] + '">' + ico('down', 13) + '<span>' + esc(title) + '</span><small class="nst-bd__grp-n"></small></button><div class="nst-bd__grp-b">' + inner + '</div></section>';
    }

    /* ------------------------------------------------------------------
     * The panels: building on the left, the element and NibWP on the
     * right, the code underneath.
     * ---------------------------------------------------------------- */

    var left = document.createElement('aside');
    left.className = 'nst-bd nst-bd--left';
    left.hidden = true;
    left.setAttribute('aria-label', __('Builder', 'nibwp'));
    left.innerHTML =
        '<div class="nst-bd__drag" data-bd-drag data-tip="' + esc(__('Drag to switch sides', 'nibwp')) + '"><i></i></div>' +
        '<header class="nst-bd__head">' +
            '<div class="nst-bd__tabs" role="tablist">' +
                '<button type="button" role="tab" aria-selected="false" data-bd-view="add" data-tip="' + esc(__('Add', 'nibwp')) + '">' + ico('plus', 14) + '<span>' + esc(__('Add', 'nibwp')) + '</span></button>' +
                '<button type="button" role="tab" aria-selected="true" data-bd-view="tree" data-tip="' + esc(__('Structure', 'nibwp')) + '">' + ico('tree', 14) + '<span>' + esc(__('Structure', 'nibwp')) + '</span></button>' +
                '<button type="button" role="tab" aria-selected="false" data-bd-view="classes" data-tip="' + esc(__('Classes', 'nibwp')) + '">' + ico('hash', 14) + '<span>' + esc(__('Classes', 'nibwp')) + '</span></button>' +
                '<button type="button" role="tab" aria-selected="false" data-bd-view="templates" data-tip="' + esc(__('Templates', 'nibwp')) + '">' + ico('tpl', 14) + '<span>' + esc(__('Templates', 'nibwp')) + '</span></button>' +
                '<button type="button" role="tab" aria-selected="false" data-bd-view="design" data-tip="' + esc(__('Colours, type, spacing', 'nibwp')) + '">' + ico('palette', 14) + '<span>' + esc(__('Design', 'nibwp')) + '</span></button>' +
            '</div>' +
        '</header>' +
        '<div class="nst-bd__wgrip" data-bd-wgrip aria-hidden="true"></div>' +
        '<div class="nst-bd__off" hidden><p></p><button type="button" class="nst-bd__btn is-main" data-bd="home">' + esc(__('Open the home page', 'nibwp')) + '</button></div>' +
        '<div class="nst-bd__view" data-view="add" hidden>' +
            find('add', __('Find an element… Enter adds it', 'nibwp')) +
            '<div class="nst-bd__palette">' +
                '<button type="button" class="nst-bd__draw" data-bd="frame" aria-pressed="false"><span class="nst-bd__draw-i">' + ico('layoutdraw', 20) + '</span><span><b>' + esc(__('Draw a wireframe', 'nibwp')) + ' <kbd>' + esc(window.nibwpKeyLabel ? window.nibwpKeyLabel('Ctrl+Shift+L') : 'Ctrl+Shift+L') + '</kbd></b><small>' + esc(__('Drag a box on the page, split it into sections and columns, then tell NibWP once what to build.', 'nibwp')) + '</small></span></button>' +
                group('wires', __('Wireframes', 'nibwp'), '<div class="nst-bd__tiles nst-bd__wires"></div>') +
                GROUPS.map(function (g) {
                    return group(g[0], g[1], '<div class="nst-bd__tiles">' + ELEMENTS.filter(function (e) { return e.group === g[0]; }).map(function (e) {
                        return '<button type="button" class="nst-bd__tile" draggable="true" data-bd-add="' + e.id + '" data-find="' + esc((e.name + ' ' + e.id).toLowerCase()) + '">' + ico(e.id === 'code' ? 'code' : e.id, 18) + '<span>' + esc(e.name) + '</span></button>';
                    }).join('') + '</div>');
                }).join('') +
            '</div>' +
        '</div>' +
        '<div class="nst-bd__view" data-view="tree">' +
            find('tree', __('Find on the page… Enter for the next', 'nibwp')) +
            '<div class="nst-bd__bar"><span class="nst-bd__count"></span>' +
                '<button type="button" class="nst-bd__ib nst-bd__fold" data-bd="foldall" data-tip="' + esc(__('Unfold', 'nibwp')) + '" aria-label="' + esc(__('Unfold', 'nibwp')) + '">' + ico('unfold', 13) + '</button>' +
            '</div>' +
            '<ul class="nst-bd__tree" role="tree" tabindex="0" aria-label="' + esc(__('Elements on this page', 'nibwp')) + '"></ul>' +
        '</div>' +
        '<div class="nst-bd__view" data-view="classes" hidden>' + find('classes', __('Find a class or a variable…', 'nibwp')) + '<div class="nst-bd__cls"></div></div>' +
        '<div class="nst-bd__view" data-view="templates" hidden>' +
            '<div class="nst-bd__make"><button type="button" class="nst-bd__btn" data-bd-make="template" data-tip="' + esc(__('New template', 'nibwp')) + '">' + ico('plus', 13) + esc(__('New template', 'nibwp')) + '</button></div>' +
            '<form class="nst-bd__mkform" hidden></form>' +
            find('templates', __('Find a template… Enter opens it', 'nibwp')) + '<ul class="nst-bd__tpls"></ul></div>' +
        '<div class="nst-bd__view" data-view="design" hidden><div class="nst-fw"></div></div>' +
        '<section class="nst-bd__hub"><i class="nst-bd__hub-grip" data-bd-hubgrip data-tip="' + esc(__('Drag to resize', 'nibwp')) + '" aria-hidden="true"></i><button type="button" class="nst-bd__hub-h" data-bd-hub aria-expanded="false">' + ico('hub', 15) + '<b>' + esc(__('Content hub', 'nibwp')) + '</b><small>' + esc(__('New pages, posts and more', 'nibwp')) + '</small>' + ico('down', 13) + '</button><div class="nst-bd__hub-b" hidden></div></section>' +
        '<div class="nst-bd__saving" hidden></div>';

    // The Builder bar, along the bottom: undo and redo, the size, quick
    // inserts, then code, reset, save and close.
    var INS = [['section', 'section', __('Section', 'nibwp')], ['container', 'container', __('Container', 'nibwp')], ['div', 'div', __('Div', 'nibwp')], ['heading', 'heading', __('Heading', 'nibwp')], ['text', 'textaa', __('Text', 'nibwp')], ['link', 'link', __('Link', 'nibwp')], ['image', 'image', __('Image', 'nibwp')], ['iframe', 'iframe', __('Iframe', 'nibwp')], ['icon', 'star', __('Icon', 'nibwp')]];
    var bar = document.createElement('div');
    bar.className = 'nst-bd-bar';
    bar.hidden = true;
    bar.innerHTML =
        '<div class="nst-bd-bar__g">' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="view" aria-haspopup="dialog" data-tip="' + esc(__('Show or hide', 'nibwp')) + '" aria-label="' + esc(__('View', 'nibwp')) + '">' + ico('sliders', 16) + '</button>' +
            '<i class="nst-bd-bar__sep" aria-hidden="true"></i>' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="undo" disabled data-tip="' + esc(__('Undo', 'nibwp')) + '" aria-label="' + esc(__('Undo', 'nibwp')) + '">' + ico('undo', 16) + '</button>' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="redo" disabled data-tip="' + esc(__('Redo', 'nibwp')) + '" aria-label="' + esc(__('Redo', 'nibwp')) + '">' + ico('redo', 16) + '</button>' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="history" aria-haspopup="dialog" aria-expanded="false" data-tip="' + esc(__('Saved changes', 'nibwp')) + '" aria-label="' + esc(__('Saved changes', 'nibwp')) + '">' + ico('history', 16) + '</button>' +
            '<i class="nst-bd-bar__sep" aria-hidden="true"></i>' +
            '<label class="nst-bd-bar__size" data-tip="' + esc(__('Canvas size', 'nibwp')) + '"><input class="nst-bd-bar__wh" spellcheck="false" autocomplete="off" aria-label="' + esc(__('Canvas width × height', 'nibwp')) + '"><small class="nst-bd-bar__zoom" hidden></small></label>' +
            '<button type="button" class="nst-bd-bar__ib nst-bd-bar__sizes" data-bd="sizes" aria-haspopup="menu" aria-expanded="false" data-tip="' + esc(__('Screen sizes', 'nibwp')) + '" aria-label="' + esc(__('Screen sizes', 'nibwp')) + '">' + ico('down', 14) + '</button>' +
        '</div>' +
        '<div class="nst-bd-bar__g nst-bd-bar__mid" role="group" aria-label="' + esc(__('Add', 'nibwp')) + '">' +
            INS.map(function (x) { return '<button type="button" class="nst-bd-bar__ib" data-bd-ins="' + x[0] + '" data-tip="' + esc(sprintf(__('Add a %s', 'nibwp'), x[2].toLowerCase())) + '" aria-label="' + esc(sprintf(__('Add a %s', 'nibwp'), x[2].toLowerCase())) + '">' + ico(x[1], 16) + '</button>'; }).join('') +
            '<i class="nst-bd-bar__sep" aria-hidden="true"></i>' +
            '<button type="button" class="nst-bd-bar__ib is-draw" data-bd-ins="layout" aria-pressed="false" data-tip="' + esc(__('Draw a wireframe', 'nibwp') + ' (' + (window.nibwpKeyLabel ? window.nibwpKeyLabel('Ctrl+Shift+L') : 'Ctrl+Shift+L') + ')') + '" aria-label="' + esc(__('Draw a wireframe', 'nibwp')) + '">' + ico('sketch', 16) + '</button>' +
        '</div>' +
        '<div class="nst-bd-bar__g">' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="dock" aria-pressed="false" data-tip="' + esc(__('Code', 'nibwp')) + '" aria-label="' + esc(__('Code', 'nibwp')) + '">' + ico('code', 16) + '</button>' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="reset" data-tip="' + esc(__('Reset page', 'nibwp')) + '" aria-label="' + esc(__('Reset page', 'nibwp')) + '">' + ico('reset', 16) + '</button>' +
            '<button type="button" class="nst-bd-bar__save" data-bd="save" disabled>' + ico('save', 14) + '<span>' + esc(__('Save', 'nibwp')) + '</span><b class="nst-bd-bar__n"></b></button>' +
            '<button type="button" class="nst-bd-bar__ib" data-bd="close" data-tip="' + esc(__('Close', 'nibwp')) + '" aria-label="' + esc(__('Close the Builder', 'nibwp')) + '">' + ico('x', 16) + '</button>' +
        '</div>';

    var elBox = document.createElement('section');
    elBox.className = 'nst-bd__elbox';
    elBox.hidden = true;
    elBox.innerHTML = '<div class="nst-bd__hgrip" data-bd-hgrip aria-hidden="true"></div><header class="nst-bd__elbox-h"></header><div class="nst-bd__fields"></div>';
    // CSS tools for what is selected: typography for text, flex and grid
    // for boxes, fit and ratio for images, and the common ones for all. A
    // tool sets its declaration, or takes it out when it is already there.
    var T = function (i, t, d) { return { i: i, t: t, d: d }; };
    var CSS_TOOLS = {
        text: [
            T('c-size', __('Bigger text', 'nibwp'), [['font-size', 'var(--text-l, 1.25rem)']]),
            T('c-bold', __('Bold', 'nibwp'), [['font-weight', '700']]),
            T('c-italic', __('Italic', 'nibwp'), [['font-style', 'italic']]),
            T('c-upper', __('Uppercase', 'nibwp'), [['text-transform', 'uppercase']]),
            T('c-track', __('Letter spacing', 'nibwp'), [['letter-spacing', '0.04em']]),
            T('c-lh', __('Line height', 'nibwp'), [['line-height', '1.5']]),
            T('c-color', __('Text colour', 'nibwp'), [['color', 'var(--primary, #ff8a3d)']]),
            '|',
            T('c-al', __('Align left', 'nibwp'), [['text-align', 'left']]),
            T('c-ac', __('Align centre', 'nibwp'), [['text-align', 'center']]),
            T('c-ar', __('Align right', 'nibwp'), [['text-align', 'right']]),
            T('c-aj', __('Justify', 'nibwp'), [['text-align', 'justify']]),
            '|',
            T('c-under', __('Underline', 'nibwp'), [['text-decoration', 'underline']]),
            T('c-strike', __('Strike through', 'nibwp'), [['text-decoration', 'line-through']]),
            T('c-balance', __('Balanced lines', 'nibwp'), [['text-wrap', 'balance']])
        ],
        box: [
            T('c-row', __('Flex row', 'nibwp'), [['display', 'flex']]),
            T('c-col', __('Flex column', 'nibwp'), [['display', 'flex'], ['flex-direction', 'column']]),
            T('c-grid', __('Grid', 'nibwp'), [['display', 'grid']]),
            T('c-g2', __('Two columns', 'nibwp'), [['display', 'grid'], ['grid-template-columns', 'repeat(2, minmax(0, 1fr))']]),
            T('c-g3', __('Three columns', 'nibwp'), [['display', 'grid'], ['grid-template-columns', 'repeat(3, minmax(0, 1fr))']]),
            T('c-g4', __('Four columns', 'nibwp'), [['display', 'grid'], ['grid-template-columns', 'repeat(4, minmax(0, 1fr))']]),
            T('c-wrap', __('Wrap', 'nibwp'), [['flex-wrap', 'wrap']]),
            '|',
            T('c-jc', __('Centre along', 'nibwp'), [['justify-content', 'center']]),
            T('c-jb', __('Space between', 'nibwp'), [['justify-content', 'space-between']]),
            T('c-as', __('Align to the start', 'nibwp'), [['align-items', 'flex-start']]),
            T('c-am', __('Align to the middle', 'nibwp'), [['align-items', 'center']]),
            T('c-ae', __('Align to the end', 'nibwp'), [['align-items', 'flex-end']]),
            T('c-gap', __('Gap', 'nibwp'), [['gap', 'var(--space-m, 1.5rem)']])
        ],
        media: [
            T('c-block', __('Block', 'nibwp'), [['display', 'block']]),
            T('c-cover', __('Cover', 'nibwp'), [['object-fit', 'cover']]),
            T('c-contain', __('Contain', 'nibwp'), [['object-fit', 'contain']]),
            T('c-169', __('16:9', 'nibwp'), [['aspect-ratio', '16 / 9']]),
            T('c-11', __('Square', 'nibwp'), [['aspect-ratio', '1']])
        ],
        all: [
            T('c-w', __('Full width', 'nibwp'), [['inline-size', '100%']]),
            T('c-h', __('Full height', 'nibwp'), [['block-size', '100%']]),
            T('c-max', __('Centre with a max width', 'nibwp'), [['max-inline-size', '1200px'], ['margin-inline', 'auto']]),
            T('c-pad', __('Padding', 'nibwp'), [['padding', 'var(--space-m, 1.5rem)']]),
            '|',
            T('c-border', __('Border', 'nibwp'), [['border', '1px solid var(--neutral-light, #e5e7eb)']]),
            T('c-radius', __('Rounded corners', 'nibwp'), [['border-radius', 'var(--radius, 12px)']]),
            T('c-shadow', __('Shadow', 'nibwp'), [['box-shadow', '0 8px 30px rgba(0, 0, 0, .08)']]),
            T('c-bg', __('Background', 'nibwp'), [['background', 'var(--neutral-ultra-light, #f8fafc)']]),
            '|',
            T('c-rel', __('Position relative', 'nibwp'), [['position', 'relative']]),
            T('c-clip', __('Clip overflow', 'nibwp'), [['overflow', 'hidden']])
        ]
    };
    var TEXTY = /^(h[1-6]|p|a|span|li|button|label|blockquote|figcaption|strong|em|small|cite|dt|dd|td|th|summary)$/;
    var MEDIA = /^(img|picture|video|iframe|svg|canvas)$/;
    function toolsFor(el) {
        var t = el.localName;
        var own = MEDIA.test(t) ? CSS_TOOLS.media : TEXTY.test(t) && !el.querySelector('div, section, ul, ol, img') ? CSS_TOOLS.text : CSS_TOOLS.box;
        return own.concat(['|'], CSS_TOOLS.all.filter(function (x) { return x === '|' || own.every(function (o) { return o === '|' || o.i !== x.i; }); }));
    }
    function declRe(p) { return new RegExp('(^|\\n)([ \\t]*)' + p.replace(/[-]/g, '\\-') + '\\s*:\\s*([^;\\n]*);?[ \\t]*(?=\\n|$)'); }
    function hasDecl(text, p, v) { var m = declRe(p).exec(text); return !!m && m[3].trim().replace(/\s+/g, ' ') === v; }
    var toolList = [];
    function paintTools() {
        var box = dock.querySelector('.nst-bd-pane__tools');
        if (!box || !cel()) { return; }
        var text = eds.css ? eds.css.ta.value : '';
        toolList = toolsFor(cel());
        box.innerHTML = toolList.map(function (x, i) {
            if (x === '|') { return '<i class="nst-bd-pane__sep" aria-hidden="true"></i>'; }
            var on = x.d.every(function (d) { return hasDecl(text, d[0], d[1]); });
            return '<button type="button" data-bd-tool="' + i + '"' + (on ? ' class="is-on" aria-pressed="true"' : ' aria-pressed="false"') + ' data-tip="' + esc(x.t) + '" aria-label="' + esc(x.t) + '">' + ico(x.i, 15) + '</button>';
        }).join('');
    }
    function useTool(x) {
        var ta = eds.css.ta;
        var v = ta.value;
        var on = x.d.every(function (d) { return hasDecl(v, d[0], d[1]); });
        if (on) {
            var last = x.d[x.d.length - 1];
            v = v.replace(declRe(last[0]), '');
        } else {
            x.d.forEach(function (d) {
                var m = declRe(d[0]).exec(v);
                if (m) { v = v.replace(declRe(d[0]), m[1] + m[2] + d[0] + ': ' + d[1] + ';'); return; }
                var at = v.lastIndexOf('}');
                var line = '  ' + d[0] + ': ' + d[1] + ';\n';
                v = at > -1 ? v.slice(0, at).replace(/\s*$/, '\n') + line + v.slice(at) : v + line;
            });
        }
        ta.value = v;
        eds.css.paint();
        cssInput();
        commitCss();
        paintTools();
    }
    var dock = document.createElement('div');
    dock.className = 'nst-bd-dock';
    dock.hidden = true;
    dock.innerHTML =
        '<div class="nst-bd-dock__grip" data-bd-dockgrip aria-hidden="true"></div>' +
        ['html', 'css', 'js'].map(function (k) {
            var name = { html: 'HTML', css: 'CSS', js: 'JAVASCRIPT' }[k];
            return '<section class="nst-bd-pane" data-pane="' + k + '">' +
                '<header class="nst-bd-pane__head"><b data-tip="' + esc(k === 'css' ? __('Applies as you type', 'nibwp') : k === 'html' ? __('Applies when you leave it, or Ctrl+Enter', 'nibwp') : __('Ctrl+Enter runs it', 'nibwp')) + '">' + name + '</b>' +
                    (k === 'css' ? '<button type="button" class="nst-bd-pane__plus" data-bd="addsel" data-tip="' + esc(__('Add a class or ID (Ctrl+Enter)', 'nibwp')) + '" aria-label="' + esc(__('Add a class, ID or attribute', 'nibwp')) + '">' + ico('plus', 10) + '</button><span class="nst-bd-pane__chips"></span><i class="nst-bd-pane__fill"></i>' : '<i class="nst-bd-pane__fill"></i>') +
                    (k === 'css' ? '<button type="button" class="nst-bd-pane__ib" data-bd="cssall" aria-pressed="false" data-tip="' + esc(__('All the CSS that styles it', 'nibwp')) + '" aria-label="' + esc(__('Show all the CSS that styles this element', 'nibwp')) + '">' + ico('list', 13) + '</button>' : '') +
                    (k === 'css' ? '<button type="button" class="nst-bd-pane__ib" data-bd="states" aria-haspopup="menu" data-tip="' + esc(__('Hover, focus, phones…', 'nibwp')) + '" aria-label="' + esc(__('Add a state or a screen size', 'nibwp')) + '">' + ico('states', 13) + '</button>' : '') +
                    '<button type="button" class="nst-bd-pane__ib" data-bd-copy="' + k + '" data-tip="' + esc(__('Copy', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Copy the %s', 'nibwp'), name)) + '">' + ico('copy', 13) + '</button>' +
                '</header>' +
                (k === 'css' ? '<div class="nst-bd-pane__tools"></div>' : '') +
                (k !== 'js' ? '<i class="nst-bd-pane__wgrip" data-bd-pgrip="' + (k === 'html' ? 0 : 1) + '" aria-hidden="true"></i>' : '') +
                '<div class="nst-cd" data-lang="' + k + '"><div class="nst-cd__gutter" aria-hidden="true"></div><div class="nst-cd__wrap"><pre class="nst-cd__hi" aria-hidden="true"><code></code></pre><textarea class="nst-cd__ta" spellcheck="false" autocomplete="off" wrap="off" aria-label="' + esc(name) + '"></textarea></div></div>' +
            '</section>';
        }).join('') +
        '<button type="button" class="nst-bd-dock__x" data-bd="dock-close" data-tip="' + esc(__('Fold the code', 'nibwp')) + '" aria-label="' + esc(__('Hide the code', 'nibwp')) + '">' + ico('down', 15) + '</button>';

    // The comment bubble, right on the element.
    var pop = document.createElement('form');
    pop.className = 'nst-bd-pop';
    pop.hidden = true;
    pop.innerHTML = '<b class="nst-bd-pop__t"></b><textarea rows="3"></textarea><label class="nst-bd-pop__name" hidden><span>' + esc(__('Block name', 'nibwp')) + '</span><input spellcheck="false" autocomplete="off" maxlength="40"></label><p class="nst-bd-pop__warn" hidden><span></span><button type="button" class="nst-bd__btn is-ghost" data-bd="pop-alt"></button></p><input class="nst-bd-pop__attrs" hidden spellcheck="false" placeholder="' + esc(__('Attributes, e.g. data-tab="1" aria-label="Book now"', 'nibwp')) + '" aria-label="' + esc(__('Attributes', 'nibwp')) + '">' +
        '<input type="file" multiple hidden accept="image/*,.html,.htm,.css,.svg,.txt,.json,.pdf"><div class="nst-bd-pop__bar"><button type="button" class="nst-bd__ib" data-bd="pop-attach" data-tip="' + esc(__('Attach', 'nibwp')) + '" aria-label="' + esc(__('Attach an image or a file', 'nibwp')) + '">' + ico('attach', 15) + '</button><span class="nst-bd-pop__files"></span>' +
        '<i class="nst-bd-pop__fill"></i><button type="button" class="nst-bd-pop__mode" data-bd="pop-mode" aria-haspopup="menu"></button><button type="submit" class="nst-bd__btn is-main">' + ico('send', 12) + '<span></span></button></div>' +
        '<button type="button" class="nst-closex" data-bd="pop-close" data-tip="' + esc(__('Close (Esc)', 'nibwp')) + '" aria-label="' + esc(__('Close', 'nibwp')) + '">' + ico('x', 14) + '</button>';

    // Corners and sides that size the canvas, and its size.
    var grips = document.createElement('div');
    grips.className = 'nst-bd-grips';
    grips.hidden = true;
    grips.innerHTML = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].map(function (g) { return '<i data-grip="' + g + '"></i>'; }).join('') +
        '<div class="nst-bd-grips__size"><span class="nst-bd-grips__wh"></span></div>';

    canvas.appendChild(left);
    left.querySelector('.nst-bd__view[data-view="tree"]').appendChild(elBox);
    canvas.appendChild(dock);
    canvas.appendChild(bar);
    root.appendChild(pop);
    artboard.appendChild(grips);

    var treeEl = left.querySelector('.nst-bd__tree');
    var countEl = left.querySelector('.nst-bd__count');
    var tplsEl = left.querySelector('.nst-bd__tpls');
    var savingEl = left.querySelector('.nst-bd__saving');
    var fileIn = pop.querySelector('input[type="file"]');
    if (boot.icon) { dock.style.setProperty('--nb-icon', 'url("' + boot.icon + '")'); }
    var sizeWh = bar.querySelector('.nst-bd-bar__wh');
    var sizeZoom = bar.querySelector('.nst-bd-bar__zoom');

    /* ------------------------------------------------------------------
     * One tooltip for every Builder tool, in Agent View's look, placed so
     * no panel clips it; wireframe buttons on the page get it too.
     * ---------------------------------------------------------------- */

    var tipEl = document.createElement('div');
    tipEl.className = 'nst-bd-tip';
    tipEl.hidden = true;
    tipEl.setAttribute('role', 'tooltip');
    root.appendChild(tipEl);
    // dx, dy and k place a button on the page: where the page sits, and its scale.
    function showTip(t, dx, dy, k) {
        var text = t.getAttribute('data-tip') || t.getAttribute('data-nst-tip');
        if (!text) { hideTip(); return; }
        tipEl.textContent = text;
        tipEl.hidden = false;
        var r = t.getBoundingClientRect();
        k = k || 1;
        var cx = (dx || 0) + (r.left + r.width / 2) * k;
        var x = Math.max(6, Math.min(window.innerWidth - tipEl.offsetWidth - 6, cx - tipEl.offsetWidth / 2));
        var y = (dy || 0) + r.top * k - tipEl.offsetHeight - 9;
        var below = y < 6;
        if (below) { y = (dy || 0) + r.bottom * k + 9; }
        tipEl.classList.toggle('is-below', below);
        tipEl.style.left = x + 'px';
        tipEl.style.top = y + 'px';
        tipEl.style.setProperty('--ax', (cx - x) + 'px');
    }
    function hideTip() { tipEl.hidden = true; }
    [left, bar, dock, pop].forEach(function (p) {
        p.addEventListener('mouseover', function (e) { var t = e.target.closest('[data-tip]'); if (t && p.contains(t)) { showTip(t); } else { hideTip(); } });
        p.addEventListener('mouseleave', hideTip);
        p.addEventListener('pointerdown', hideTip);
        // Keyboard users get the same short tip on the icon they tab to.
        p.addEventListener('focusin', function (e) { var t = e.target.closest && e.target.closest('[data-tip]'); var fv = false; try { fv = e.target.matches(':focus-visible'); } catch (x) { fv = true; } if (t && fv && p.contains(t)) { showTip(t); } });
        p.addEventListener('focusout', hideTip);
    });
    function frameTip(e) {
        var t = e.target.closest && e.target.closest('[data-nst-tip]');
        if (!t) { hideTip(); return; }
        var fr = frameBox();
        showTip(t, fr.left, fr.top, fr.k);
    }
    // Where the page sits on screen, and how much smaller it is drawn there
    // (k is 1 unless the canvas is wider than the room): a point x, y on the
    // page is at left + x * k, top + y * k here.
    function frameBox() {
        var f = kit.frame();
        var r = f.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, k: f.offsetWidth ? r.width / f.offsetWidth : 1 };
    }
    /* ------------------------------------------------------------------
     * State.
     * ---------------------------------------------------------------- */

    var on = false;
    var blocked = false;
    var doc = null;
    var sel = null;
    var hover = null;
    var focusTpl = null;
    var open = new WeakSet();
    var rows = [];
    var view = 'tree';
    var marks = null;
    var chatWas = null;
    var past = [];
    var future = [];
    var cssLive = {};
    var held = null;
    var bypass = false;
    var keep = null;
    var dockOpen = store('nibwp-bd-dock') !== false;
    var pageCode = false;

    /* ------------------------------------------------------------------
     * The page: outlines drawn in it, clicks that select, drops that add.
     * ---------------------------------------------------------------- */

    // Hidden on the site: faded here, always or only while the canvas is at
    // one of the sizes it is hidden on (the sizes of the CSS states menu).
    var HIDE_LOOK = '{opacity:.4!important;outline:1px dashed #94a3b8!important;outline-offset:-1px}';
    var HIDE_AT = { phone: '(max-width:767px)', tablet: '(min-width:768px) and (max-width:1023px)', desktop: '(min-width:1024px)' };
    var FRAME_CSS = '.nst-x-bd{position:fixed;z-index:2147483646;pointer-events:none;box-sizing:border-box;display:none}' +
        'html.nst-x-tpl [data-nst-x-post]{outline:2px dashed rgba(124,58,237,.55);outline-offset:-2px}' +
        'html.nst-x-tpl :is(.wp-block-post-content,.wp-block-post-title,.wp-block-post-excerpt,.wp-block-post-date,.wp-block-post-featured-image,.wp-block-post-terms,.wp-block-post-author,.wp-block-query,.wp-block-comments,.entry-content,.entry-title,.post-content,.page-content){outline:2px dashed rgba(139,92,246,.55);outline-offset:3px}' +
        '.nst-x-bd.is-on{display:block}' +
        '.nst-x-bd--hover{outline:1.5px dashed #ff8a3d;outline-offset:-1px;background:rgba(255,138,61,.06)}' +
        '.nst-x-bd--sel{outline:2px solid #ff8a3d;outline-offset:-1px}' +
        '.nst-x-bd--also{outline-style:dashed}' +
        '.nst-x-bd--find{outline:2px solid #7c3aed;outline-offset:1px}' +
        '.nst-x-bd--drop{height:4px!important;background:#ff8a3d;border-radius:2px;box-shadow:0 0 0 3px rgba(255,138,61,.25)}' +
        '.nst-x-bd--drop.is-in{height:auto!important;background:rgba(255,138,61,.12);outline:2px dashed #ff8a3d;box-shadow:none}' +
        '.nst-x-bd__tag{position:absolute;left:-2px;top:-20px;padding:0 6px;border-radius:3px 3px 0 0;background:#ff8a3d;color:#fff;font:600 11px/20px system-ui,sans-serif;white-space:nowrap}' +
        '.nst-x-bd--sel.is-low .nst-x-bd__tag{top:auto;bottom:-20px;border-radius:0 0 3px 3px}' +
        '.nst-x-bd-new{animation:nst-x-bd-in .5s ease}' +
        'html.nst-x-building .nst-x-bd-off{display:none!important}' +
        'html.nst-x-building [data-nibwp-hide=""]' + HIDE_LOOK +
        Object.keys(HIDE_AT).map(function (k) { return '@media ' + HIDE_AT[k] + '{html.nst-x-building [data-nibwp-hide~="' + k + '"]' + HIDE_LOOK + '}'; }).join('') +
        '.nst-x-bd--sel .nst-x-bd__tag{display:none}' +
        '.nst-x-bd-tools{position:fixed;z-index:2147483647;display:none;align-items:center;gap:1px;height:36px;padding:0 4px;box-sizing:border-box;border:1px solid #2a2a2e;border-radius:8px;background:#18181b;color:#e4e4e7;font:600 12px/1 system-ui,sans-serif;box-shadow:0 10px 28px rgba(0,0,0,.28);pointer-events:auto;white-space:nowrap}' +
        '.nst-x-bd-tools.is-on{display:flex;animation:nst-x-bd-pop .14s ease-out}@keyframes nst-x-bd-pop{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}' +
        '.nst-x-bd--hover .nst-x-bd__tag{background:#18181b}' +
        '.nst-x-bd-moving{opacity:.35!important;outline:2px dashed #ff8a3d!important;pointer-events:none!important}html.nst-x-bd-dragging,html.nst-x-bd-dragging *{cursor:grabbing!important;user-select:none!important}' +
        'html.nst-x-building body *:not(input):not(textarea):not(select){-webkit-user-select:none;user-select:none}' +
        '.nst-x-bd-tools [data-bd-t="move"]{cursor:grab!important}' +
        '.nst-x-bd-tools button[data-bd-t="sitesize"]{width:14px;margin-left:-2px}.nst-x-bd-tools button[data-bd-t="sitesize"] svg{width:12px;height:12px}' +
        '.nst-x-bd-tools__n{position:absolute;left:-1px;bottom:100%;display:flex;align-items:center;gap:6px;height:20px;padding:0 8px;border-radius:6px 6px 0 0;background:#ff8a3d;color:#fff;font:600 11px/20px system-ui,sans-serif}.nst-x-bd-tools__n small{color:rgba(255,255,255,.75);font-weight:500;font-variant-numeric:tabular-nums}' +
        '.nst-x-bd-tools button{display:grid;place-items:center;width:28px;height:28px;padding:0;border:0;border-radius:6px;background:transparent;color:#a1a1aa;cursor:pointer!important;transition:background .12s,color .12s}' +
        '.nst-x-bd-tools button:hover{background:#27272a;color:#fff}.nst-x-bd-tools button.is-on{color:#ff8a3d}.nst-x-bd-tools button[data-bd-t="ask"]{color:#ff8a3d}.nst-x-bd-tools button[data-bd-t="del"]:hover{background:rgba(229,72,77,.16);color:#f87171}' +
        '.nst-x-bd-tools svg{fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;shape-rendering:geometricPrecision}' +
        '.nst-x-bd-tools__ask{display:none;align-items:center;gap:6px;padding:0 2px 0 8px;font-weight:600}.nst-x-bd-tools.is-confirm>button{display:none}.nst-x-bd-tools.is-confirm .nst-x-bd-tools__ask{display:flex}' +
        '.nst-x-bd-tools button.is-txt{width:auto;padding:0 10px;color:#e4e4e7;font:600 12px/1 system-ui,sans-serif}.nst-x-bd-tools button.is-del{background:#e5484d;color:#fff}.nst-x-bd-tools button.is-del:hover{background:#dc2626;color:#fff}' +
        '@keyframes nst-x-bd-in{from{outline:3px solid #ff8a3d;outline-offset:4px}to{outline:3px solid transparent;outline-offset:0}}' +
        '.nst-x-bd-sketch{display:grid;place-items:center;min-height:180px;padding:24px;border:2px dashed #ff8a3d;border-radius:12px;background:rgba(255,138,61,.06);color:#9a3412;font:600 15px/1.4 system-ui,sans-serif;text-align:center}' +
        '.nst-x-bd-frame{position:relative;box-sizing:border-box;display:flex;flex-direction:column;gap:8px;margin:12px 0;padding:8px 10px 10px;border:2px dashed #ff8a3d;border-radius:12px;background:rgba(255,138,61,.05);font:14px/1.45 system-ui,sans-serif;color:#1f2937}' +
        '.nst-x-bd-frame .nst-x-bd-frame{margin:0;border-width:1.5px;background:rgba(255,255,255,.55)}' +
        '.nst-x-bd-frame__bar{display:flex;align-items:center;gap:2px;min-height:24px}' +
        '.nst-x-bd-frame__tag{flex:1;min-width:0;font:700 11px/16px system-ui,sans-serif;letter-spacing:.04em;text-transform:uppercase;color:#c2410c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
        '.nst-x-bd-frame__bar button{display:inline-flex;align-items:center;gap:4px;height:24px;min-width:24px;padding:0 5px;border:0;border-radius:6px;background:transparent;color:#9a3412;font:600 12px/1 system-ui,sans-serif;cursor:pointer!important}' +
        '.nst-x-bd-frame__bar button:hover{background:rgba(255,138,61,.16)}' +
        '.nst-x-bd-frame__bar button[hidden]{display:none}' +
        '.nst-x-bd-frame__bar button.is-go{padding:0 9px;background:#ff8a3d;color:#fff}.nst-x-bd-frame__bar button.is-go:hover{background:#f97316}' +
        '.nst-x-bd-frame svg{fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}' +
        '.nst-x-bd-frame__kids{display:flex;flex-direction:column;gap:8px}.nst-x-bd-frame__kids:empty{display:none}' +
        '.nst-x-bd-frame__kids[data-cols]{display:grid;grid-template-columns:repeat(var(--c,2),minmax(0,1fr))}' +
        '.nst-x-bd-frame__kids[data-cols="3"]{--c:3}.nst-x-bd-frame__kids[data-cols="4"]{--c:4}' +
        '.nst-x-bd-frame{cursor:pointer!important}.nst-x-bd-frame:hover,.nst-x-bd-frame.is-on{border-style:solid}.nst-x-bd-frame.is-on{box-shadow:0 0 0 3px rgba(255,138,61,.22)}' +
        '.nst-x-bd-frame .nst-x-bd-frame .nst-x-bd-frame__bar button{opacity:0;transition:opacity .12s}' +
        '.nst-x-bd-frame .nst-x-bd-frame:hover>.nst-x-bd-frame__bar button,.nst-x-bd-frame .nst-x-bd-frame.is-on>.nst-x-bd-frame__bar button{opacity:1}' +
        '.nst-x-bd-frame.is-sent .nst-x-bd-frame__bar button:not([data-nst-f="x"]){display:none}' +
        '.nst-x-bd-frame__rs{position:absolute;right:-2px;bottom:-2px;width:16px;height:16px;box-sizing:border-box;border-right:3px solid #ff8a3d;border-bottom:3px solid #ff8a3d;border-bottom-right-radius:11px;opacity:.45;cursor:nwse-resize!important;touch-action:none}' +
        '.nst-x-bd-frame:hover>.nst-x-bd-frame__rs,.nst-x-bd-frame.is-on>.nst-x-bd-frame__rs{opacity:1}.nst-x-bd-frame.is-sent>.nst-x-bd-frame__rs{display:none}' +
        '.nst-x-bd-frame[data-size]::after{content:attr(data-size);position:absolute;right:20px;bottom:4px;padding:1px 7px;border-radius:5px;background:#1f2937;color:#fff;font:600 11px/18px system-ui,sans-serif}' +
        '.nst-x-bd-frame__note{margin:0;padding:2px 2px 4px;color:#1f2937;font:14px/1.45 system-ui,sans-serif;white-space:pre-wrap}' +
        '.nst-x-bd-frame__note.is-empty{color:#c2410c;opacity:.7;font-style:italic}' +
        '.nst-x-bd-frame.is-sent{border-color:#16a34a;background:rgba(22,163,74,.05)}.nst-x-bd-frame.is-sent .nst-x-bd-frame__tag{color:#15803d}' +
        'html.nst-x-building a,html.nst-x-building button{cursor:default!important}' +
        'html.nst-x-building .nst-x-bd-typing,html.nst-x-building .nst-x-bd-typing *{-webkit-user-select:text!important;user-select:text!important;cursor:text!important}' +
        'html.nst-x-building .nst-x-bd-typing{outline:2px solid #ff8a3d!important;outline-offset:3px;border-radius:2px;caret-color:#ff8a3d}' +
        'html.nst-x-bd-typing-on .nst-x-bd-tools,html.nst-x-bd-typing-on .nst-x-bd--sel{display:none!important}';

    /* One editing session per page: the Builder always works on the tab on
     * screen. Leaving a tab, its unsaved edits, undo steps and selection
     * stay with its page (its marks hidden) and come back with it; nothing
     * is dropped and nothing lands on another tab's page. */
    var sessions = new WeakMap();
    function leaveDoc() {
        if (!doc || !marks || !marks.sel.isConnected) { return; }
        if (typingEl) { typeDone(true); }
        clsCommit();
        setDrawing(false);
        closePop();
        closePalette();
        closeCtx();
        commitCss();
        sessions.set(doc, { past: past, future: future, sel: sel, also: also, open: open, marks: marks, cssLive: cssLive, held: held });
        (marks.also || []).forEach(function (m) { box(m, null); });
        [marks.hover, marks.sel, marks.drop, marks.find, marks.tools].forEach(function (m) { m.style.display = 'none'; });
        doc.documentElement.classList.remove('nst-x-building');
        forget();
    }
    // What belongs to the page being left and must not reach the next one:
    // the find results, the panes' element, a refresh it was holding.
    function forget() {
        also = [];
        codeFor = null;
        cssFor = null;
        hits = [];
        hitAt = 0;
        held = null;
        moving = false;
        if (typeof resetCssVars === 'function') { resetCssVars(); }
    }
    function hook() {
        var d = kit.frameDoc();
        if (!d || !d.body) { return false; }
        if (d === doc && marks && marks.sel.isConnected) { return true; }
        var wasTpl = doc && doc !== d && !doc.defaultView ? tplDocs.get(doc) : null;
        // A closed tab's page is gone: its session goes with it (closing asked
        // first). What was open on it closes, and typing on it is dropped.
        if (doc && doc !== d && doc.defaultView) { leaveDoc(); }
        else if (doc && doc !== d) {
            typingEl = null;
            pop.hidden = true;
            popFor = null;
            popOp = null;
            closeCtx();
            if (drawing) { setDrawing(false); }
            forget();
        }
        if (doc !== d) {
            var fi = left.querySelector('[data-bd-find="tree"]');
            if (fi && fi.value) { fi.value = ''; var fn = fi.parentNode.querySelector('.nst-bd__find-n'); if (fn) { fn.textContent = ''; } }
        }
        var back = sessions.get(d);
        doc = d;
        hover = null;
        if (wasTpl && !tplDocs.get(d) && sameUrl(kit.pageInfo().url, wasTpl.url)) { tplDocs.set(d, wasTpl); }
        if (back && back.marks.sel.isConnected) {
            sessions.delete(d);
            past = back.past;
            future = back.future;
            sel = back.sel && back.sel.isConnected ? back.sel : null;
            open = back.open;
            marks = back.marks;
            held = back.held || null;
            also = (back.also || []).filter(function (x) { return x.isConnected; });
            // Hidden while away; shown again as the page comes back.
            [marks.hover, marks.sel, marks.drop, marks.find, marks.tools].forEach(function (m) { m.style.display = ''; });
            // Site CSS saved meanwhile from another tab shows here too, under this tab's own edits.
            cssLive = back.cssLive;
            var mine = {};
            past.forEach(function (o) { if (o.kind === 'css') { mine[o.key] = 1; } (o.keys || []).forEach(function (k) { mine[k] = 1; }); });
            Object.keys(cfg.css || {}).forEach(function (k) { if (!mine[k]) { cssLive[k] = cfg.css[k]; } });
            // A rule a save undone meanwhile took off goes from here too.
            Object.keys(cssLive).forEach(function (k) { if (!mine[k] && !(k in (cfg.css || {}))) { delete cssLive[k]; } });
            writeCss();
            return true;
        }
        sel = null;
        past = [];
        future = [];
        cssLive = JSON.parse(JSON.stringify(cfg.css || {}));
        open = new WeakSet();
        var st = d.createElement('style');
        st.id = 'nst-x-bd-style';
        st.textContent = FRAME_CSS;
        (d.head || d.documentElement).appendChild(st);
        var live = d.createElement('style');
        live.id = 'nst-x-bd-live';
        (d.head || d.documentElement).appendChild(live);
        var mk = function (cls) { var n = d.createElement('div'); n.className = 'nst-x-bd ' + cls; n.setAttribute('aria-hidden', 'true'); d.documentElement.appendChild(n); return n; };
        marks = { hover: mk('nst-x-bd--hover'), sel: mk('nst-x-bd--sel'), drop: mk('nst-x-bd--drop'), find: mk('nst-x-bd--find'), tools: mk('nst-x-bd-tools'), live: live };
        marks.tools.removeAttribute('aria-hidden');
        marks.tools.setAttribute('role', 'toolbar');
        marks.tools.innerHTML = '<b class="nst-x-bd-tools__n"></b>' + [['ask', 'comment', __('Ask NibWP', 'nibwp')], ['move', 'move', __('Drag to move', 'nibwp')], ['up', 'up', __('Select parent', 'nibwp')], ['dup', 'dup', __('Duplicate', 'nibwp')], ['code', 'code', __('Code', 'nibwp')], ['hide', 'eyeoff', __('Hide here', 'nibwp')], ['sitehide', 'siteoff', __('Hide on the site', 'nibwp')], ['sitesize', 'down', __('Hide on phones, tablets or desktop', 'nibwp')], ['del', 'trash', __('Delete', 'nibwp')]].map(function (b) { return '<button type="button" data-bd-t="' + b[0] + '" data-nst-tip="' + esc(b[2]) + '" aria-label="' + esc(b[2]) + '"' + (b[0] === 'sitesize' ? ' aria-haspopup="menu"' : '') + '><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">' + I[b[1]] + '</svg></button>'; }).join('') +
            '<span class="nst-x-bd-tools__ask"><span>' + esc(__('Delete it?', 'nibwp')) + '</span><button type="button" class="is-txt" data-bd-t="no">' + esc(__('Cancel', 'nibwp')) + '</button><button type="button" class="is-txt is-del" data-bd-t="yes">' + esc(__('Delete', 'nibwp')) + '</button></span>';
        // A page in a tab out of sight keeps its listeners: they act only
        // while their page is the one the Builder works on.
        var g = function (fn) { return function () { if (d !== doc) { return; } return fn.apply(this, arguments); }; };
        d.documentElement.addEventListener('mouseleave', g(function () { if (hover) { hover = null; paint(); } }));
        marks.sel.innerHTML = '<span class="nst-x-bd__tag"></span>';
        marks.hover.innerHTML = '<span class="nst-x-bd__tag"></span>';
        d.addEventListener('mousemove', g(onMove), true);
        d.addEventListener('click', g(onClick), true);
        d.addEventListener('pointerdown', g(onResize), true);
        d.addEventListener('pointerdown', g(onPress), true);
        d.addEventListener('dragstart', g(function (e) { if (on && !blocked) { e.preventDefault(); } }), true);
        // Enter in a search box would leave the page and its unsaved edits.
        d.addEventListener('submit', g(function (e) { if (on && !blocked) { e.preventDefault(); } }), true);
        d.addEventListener('mouseover', g(frameTip), true);
        d.addEventListener('pointerdown', g(hideTip), true);
        d.addEventListener('dblclick', g(onDbl), true);
        d.addEventListener('keydown', g(onKey), true);
        d.addEventListener('contextmenu', g(onCtx), true);
        d.addEventListener('pointerdown', g(function () { if (!ctx.hidden) { closeCtx(); } sizesClose(false); }), true);
        d.addEventListener('dragover', g(onPageDragOver), true);
        d.addEventListener('dragleave', g(function (e) { if (!e.relatedTarget) { box(marks.drop, null); } }), true);
        d.addEventListener('drop', g(onPageDrop), true);
        d.defaultView.addEventListener('scroll', g(function () { paint(); placePop(); }), { passive: true });
        d.defaultView.addEventListener('resize', g(paint));
        // Saved classes come from the Builder's own sheet while it builds, so
        // a rule taken away or changed shows at once, not after Save.
        var srv = d.getElementById('nibwp-builder-css');
        if (srv) { srv.disabled = true; }
        writeCss();
        open.add(d.body);
        var main = d.querySelector('main, .wp-site-blocks, #page, #content, .site');
        for (var p = main; p && p !== d.body; p = p.parentElement) { open.add(p); }
        d.documentElement.classList.toggle('nst-x-building', on && !blocked);
        tokCache = null;
        if (fwDraft) { fwApply(); if (view === 'design' && !fwDirty()) { fwLoad(true); } }
        return true;
    }

    // Agent View's own marks in the page (pins, outlines), not the page's.
    function ours(n) {
        for (var p = n; p && p.nodeType === 1 && p !== doc.body && p !== doc.documentElement; p = p.parentElement) {
            if (typeof p.className === 'string' && /(^|\s)nst-x-bd(?!-new|-sketch|-off)|(^|\s)nst-x-(?!bd)/.test(p.className)) { return true; }
        }
        return false;
    }

    function pickable(n) {
        if (n && n.nodeType !== 1) { n = n.parentElement; }
        if (!n || ours(n)) { return null; }
        var svg = n.closest && n.closest('svg');
        if (svg) { n = svg; }
        while (n && n !== doc.body && SKIP.test(n.localName)) { n = n.parentElement; }
        return n && n !== doc.documentElement && n !== doc.body ? n : null;
    }

    function box(mark, el, rect) {
        if (!mark) { return; }
        if (!el && !rect) { mark.classList.remove('is-on'); return; }
        var r = rect || el.getBoundingClientRect();
        mark.style.left = r.left + 'px';
        mark.style.top = r.top + 'px';
        mark.style.width = r.width + 'px';
        mark.style.height = r.height + 'px';
        mark.classList.add('is-on');
        mark.classList.toggle('is-low', r.top < 22);
    }

    function paint() {
        if (!marks) { return; }
        var live = on && !blocked && !aside();
        var hv = live && hover && hover !== sel && hover.isConnected && !isFrame(hover) ? hover : null;
        box(marks.hover, hv);
        if (hv) { var hr = hv.getBoundingClientRect(); marks.hover.firstChild.textContent = nice(hv) + ' · ' + wxh(Math.round(hr.width), Math.round(hr.height)); }
        var fsel = sel && isFrame(sel);
        box(marks.sel, live && sel && sel.isConnected && !fsel ? sel : null);
        box(marks.find, null);
        if (sel && live && !fsel) { marks.sel.firstChild.textContent = label(sel, true); }
        if (doc) { Array.prototype.forEach.call(doc.querySelectorAll('.nst-x-bd-frame.is-on'), function (x) { if (x !== sel) { x.classList.remove('is-on'); } }); }
        if (fsel && live) { sel.classList.add('is-on'); }
        placeTools(live && pop.hidden && !moving && sel && sel.isConnected && !fsel ? sel : null);
        var others = live ? picked().filter(function (x) { return x !== sel; }) : [];
        if (!marks.also) { marks.also = []; }
        while (doc && marks.also.length < others.length) {
            var m = doc.createElement('div');
            m.className = 'nst-x-bd nst-x-bd--sel nst-x-bd--also';
            m.setAttribute('aria-hidden', 'true');
            doc.documentElement.appendChild(m);
            marks.also.push(m);
        }
        marks.also.forEach(function (m2, i) { box(m2, others[i] || null); });
    }

    // Drag the selected element, or the toolbar's move handle: a line shows
    // where it lands (beside items in a row, above or below otherwise, in a
    // box when you hold over its middle).
    var moving = false;
    var movedAt = 0;
    function spotXY(under, ev) {
        var r = under.getBoundingClientRect();
        var p = under.parentElement;
        var cs = p && p !== doc.body ? doc.defaultView.getComputedStyle(p) : null;
        var row = cs && ((/flex/.test(cs.display) && !/column/.test(cs.flexDirection)) || (/grid/.test(cs.display) && r.width < p.getBoundingClientRect().width * 0.8));
        var holds = HOLD.test(under.localName) && !LEAF.test(under.localName);
        var fx = (ev.clientX - r.left) / (r.width || 1);
        var fy = (ev.clientY - r.top) / (r.height || 1);
        var f = row ? fx : fy;
        var pos = holds && f > 0.3 && f < 0.7 && (row ? fy > 0.25 && fy < 0.75 : true) ? 'inside' : f < 0.5 ? 'before' : 'after';
        var rect = pos === 'inside' ? r : row ? { left: (pos === 'before' ? r.left : r.right) - 2, top: r.top, width: 4, height: r.height } : { left: r.left, width: r.width, top: (pos === 'before' ? r.top : r.bottom) - 2, height: 4 };
        return { el: under, pos: pos, rect: rect };
    }
    function onPress(e) {
        if (!on || blocked || aside() || e.button !== 0) { return; }
        if (typingEl && typingEl.el.contains(e.target)) { return; }
        var handle = e.target.closest && e.target.closest('[data-bd-t="move"]');
        if (!handle && e.target.closest && e.target.closest('.nst-x-bd-tools')) { return; }
        var el = handle ? marks.tools.el : (sel && sel.isConnected && !isFrame(sel) && sel.contains(e.target) ? sel : pickable(e.target));
        if (!el || (!handle && e.target.closest('.nst-x-bd-tools, .nst-x-bd-frame, input, textarea, select, [contenteditable]'))) { return; }
        if (handle) { e.preventDefault(); e.stopPropagation(); }
        var x0 = e.clientX;
        var y0 = e.clientY;
        var at = null;
        var move = function (ev) {
            if (!moving) {
                if (Math.abs(ev.clientX - x0) + Math.abs(ev.clientY - y0) < 6) { return; }
                moving = true;
                if (sel !== el) { sel = el; render(); inspect(); }
                el.classList.add('nst-x-bd-moving');
                doc.documentElement.classList.add('nst-x-bd-dragging');
                try { doc.getSelection().removeAllRanges(); } catch (x) { }
                hideTip();
                paint();
            }
            ev.preventDefault();
            // Near the top or bottom edge the page scrolls, to reach further.
            var vh = doc.defaultView.innerHeight;
            if (ev.clientY > vh - 48) { doc.defaultView.scrollBy(0, 24); } else if (ev.clientY < 48) { doc.defaultView.scrollBy(0, -24); }
            var under = pickable(doc.elementFromPoint(ev.clientX, ev.clientY));
            at = under && under !== el && !el.contains(under) ? spotXY(under, ev) : null;
            if (!at) { box(marks.drop, null); return; }
            marks.drop.classList.toggle('is-in', at.pos === 'inside');
            box(marks.drop, null, at.rect);
        };
        var d = doc;
        // Dropped: it moves. Cancelled (Esc, a pen or touch gesture taken
        // over): it stays where it was.
        var end = function (keep) {
            d.removeEventListener('pointermove', move, true);
            d.removeEventListener('pointerup', up, true);
            d.removeEventListener('pointercancel', cancel, true);
            d.removeEventListener('keydown', esc, true);
            box(marks.drop, null);
            el.classList.remove('nst-x-bd-moving');
            d.documentElement.classList.remove('nst-x-bd-dragging');
            if (moving) {
                moving = false;
                movedAt = Date.now();
                if (keep && at && d === doc) { moveEl(el, at.el, at.pos); } else { paint(); }
            }
        };
        var up = function () { end(true); };
        var cancel = function () { end(false); };
        var esc = function (ev) { if (ev.key === 'Escape' && moving) { ev.preventDefault(); ev.stopPropagation(); end(false); } };
        d.addEventListener('pointermove', move, true);
        d.addEventListener('pointerup', up, true);
        d.addEventListener('pointercancel', cancel, true);
        d.addEventListener('keydown', esc, true);
    }

    // The icons on the selected element, in the middle of it.
    function placeTools(el) {
        var t = marks && marks.tools;
        if (!t) { return; }
        var r = el ? el.getBoundingClientRect() : null;
        if (!el || r.bottom < 0 || r.top > doc.defaultView.innerHeight) { t.classList.remove('is-on'); t.el = null; return; }
        if (t.el !== el) {
            t.el = el;
            t.classList.remove('is-confirm');
            t.querySelector('.nst-x-bd-tools__n').innerHTML = '<span>' + esc(nice(el)) + '</span><small>' + wxh(Math.round(r.width), Math.round(r.height)) + '</small>';
            t.querySelector('[data-bd-t="code"]').classList.toggle('is-on', codeFor === el && !dock.hidden && dockOpen);
        }
        siteBtn(t.querySelector('[data-bd-t="sitehide"]'), el, 'data-nst-tip');
        t.classList.add('is-on');
        var w = t.offsetWidth;
        var h = t.offsetHeight;
        var vw = doc.documentElement.clientWidth;
        var vh = doc.defaultView.innerHeight;
        // In the middle of what shows of it; above or below a small one.
        var top = (Math.max(r.top, 0) + Math.min(r.bottom, vh)) / 2 - h / 2;
        if (r.height < h + 16) { top = r.top - h - 10 >= 4 ? r.top - h - 10 : r.bottom + 10; }
        t.style.left = Math.round(Math.max(4, Math.min(vw - w - 4, r.left + r.width / 2 - w / 2))) + 'px';
        t.style.top = Math.round(Math.max(24, Math.min(vh - h - 4, top))) + 'px';
    }
    function onMove(e) {
        if (!on || blocked || aside()) { return; }
        if (e.target.closest && e.target.closest('.nst-x-bd-tools')) { return; }
        var n = pickable(e.target);
        if (n === hover) { return; }
        hover = n;
        paint();
        treeEl.querySelectorAll('.is-hover').forEach(function (r) { r.classList.remove('is-hover'); });
        var i = rows.indexOf(n);
        if (i > -1) { var row = treeEl.querySelector('[data-i="' + i + '"]'); if (row) { row.classList.add('is-hover'); } }
    }

    function onClick(e) {
        if (!on || blocked || aside()) { return; }
        if (typingEl && typingEl.el.contains(e.target)) { e.preventDefault(); return; }
        var tb = e.target.closest && e.target.closest('.nst-x-bd-tools');
        if (tb) { e.preventDefault(); e.stopPropagation(); var tbt = e.target.closest('[data-bd-t]'); if (tbt) { hideTip(); toolDo(tbt.dataset.bdT, marks.tools.el); } return; }
        var fr = e.target.closest && e.target.closest('.nst-x-bd-frame');
        if (fr) { e.stopPropagation(); frameClick(e, fr); return; }
        var n = pickable(e.target);
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        if (Date.now() - movedAt < 200) { return; }
        var again = n && n === sel;
        if (n && (e.ctrlKey || e.metaKey || e.shiftKey) && sel && sel.isConnected && !isFrame(sel) && !isFrame(n)) { pickToggle(n); return; }
        if (n) { if (!pop.hidden) { closePop(); } select(n, true); }
        if (again && canType(n)) { typeIn(n, e); }
    }

    function onDbl(e) {
        if (!on || blocked || aside()) { return; }
        if (e.target.closest && e.target.closest('.nst-x-bd-frame')) { return; }
        if (typingEl && typingEl.el.contains(e.target)) { return; }
        var n = pickable(e.target);
        e.preventDefault();
        if (n && canType(n)) { select(n, true); typeIn(n, e); return; }
        if (n) { select(n, true); openPop(n, 'comment'); }
    }

    /* Text typed right on the page: a second click (or a double click) on a
     * heading, a paragraph, a link, a button… Enter or a click away keeps
     * it, Esc puts it back; one Ctrl+Z step, saved with the page. */
    var typingEl = null;
    var TYPE_TAGS = /^(h[1-6]|p|span|a|button|li|label|figcaption|blockquote|small|strong|em|b|i|td|th|dt|dd|cite|summary|q|caption|legend)$/;
    function canType(el) {
        if (!el || !TYPE_TAGS.test(el.localName) || isFrame(el) || !(el.textContent || '').trim()) { return false; }
        return !el.querySelector('*:not(' + 'strong,em,b,i,u,s,a,span,br,small,mark,code,sup,sub,abbr,time,cite,q' + ')');
    }
    function typeIn(el, ev) {
        if (typingEl) { typeDone(true); }
        typingEl = { el: el, before: el.innerHTML };
        el.setAttribute('contenteditable', 'true');
        el.classList.add('nst-x-bd-typing');
        doc.documentElement.classList.add('nst-x-bd-typing-on');
        el.focus();
        var sel0 = doc.getSelection();
        var r = ev && (doc.caretRangeFromPoint ? doc.caretRangeFromPoint(ev.clientX, ev.clientY) : null);
        if (!r && ev && doc.caretPositionFromPoint) { var cp = doc.caretPositionFromPoint(ev.clientX, ev.clientY); if (cp) { r = doc.createRange(); r.setStart(cp.offsetNode, cp.offset); } }
        if (!r || !el.contains(r.startContainer)) { r = doc.createRange(); r.selectNodeContents(el); r.collapse(false); }
        sel0.removeAllRanges();
        sel0.addRange(r);
        el.addEventListener('keydown', typeKey, true);
        el.addEventListener('paste', typePaste, true);
        el.addEventListener('blur', typeBlur, true);
        paint();
    }
    function typeKey(e) {
        e.stopPropagation();
        if (e.key === 'Escape') { e.preventDefault(); typeDone(false); }
        else if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); typeDone(true); }
    }
    function typePaste(e) {
        e.preventDefault();
        var t = (e.clipboardData || window.clipboardData).getData('text/plain');
        doc.execCommand('insertText', false, t);
    }
    function typeBlur() { setTimeout(function () { if (typingEl && doc.activeElement !== typingEl.el) { typeDone(true); } }, 0); }
    function typeDone(keep) {
        var t = typingEl;
        if (!t) { return; }
        typingEl = null;
        var el = t.el;
        el.removeEventListener('keydown', typeKey, true);
        el.removeEventListener('paste', typePaste, true);
        el.removeEventListener('blur', typeBlur, true);
        el.removeAttribute('contenteditable');
        el.classList.remove('nst-x-bd-typing');
        doc.documentElement.classList.remove('nst-x-bd-typing-on');
        try { doc.getSelection().removeAllRanges(); } catch (x) { }
        var after = el.innerHTML;
        if (!keep) { el.innerHTML = t.before; paint(); return; }
        if (after === t.before) { paint(); return; }
        var sS = kit.cssPath(el);
        act({
            patch: function () { return { t: 'replace', s: sS, html: cleanHtml(el) }; },
            el: el,
            label: sprintf(__('edit the text of %s', 'nibwp'), nice(el)),
            redo: function () { el.innerHTML = after; sel = el; elBox.el = null; },
            undo: function () { el.innerHTML = t.before; sel = el; elBox.el = null; }
        });
    }

    function onKey(e) {
        if (!on) { return; }
        idle();
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveAll(); return; }
        var typing = e.target.closest && e.target.closest('input, textarea, select, [contenteditable]');
        if (e.key === 'Escape' && typing) { e.preventDefault(); e.target.blur(); return; }
        if (e.key === 'Escape' && !(window.nibwpSketch && window.nibwpSketch.isOpen())) { e.preventDefault(); if (drawing) { setDrawing(false); } else if (!pop.hidden) { closePop(); } else if (sel) { unselect(); } else { stop(); } return; }
        if (!typing && moreKeys(e)) { e.preventDefault(); return; }
        if (!typing && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) { redo(); } else { undo(); } }
        if (!typing && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
        if (!typing && sel && (e.key === 'Delete' || e.key === 'Backspace')) { e.preventDefault(); removePicked(); }
    }

    /* ------------------------------------------------------------------
     * The tree.
     * ---------------------------------------------------------------- */

    function kids(el) {
        return Array.prototype.filter.call(el.children, function (c) {
            return !SKIP.test(c.localName) && c.id !== 'wpadminbar' && !ours(c);
        });
    }
    // What Structure lists under a row: the page's elements and the
    // wireframe boxes drawn on it; a box lists what it holds.
    var MARK = /(^|\s)nst-x-bd(?!-new|-sketch|-off)|(^|\s)nst-x-(?!bd)/;
    function treeKids(el) {
        return Array.prototype.filter.call(isFrame(el) ? frameKids(el).children : el.children, function (c) {
            return isFrame(c) || (!SKIP.test(c.localName) && c.id !== 'wpadminbar' && !(typeof c.className === 'string' && MARK.test(c.className)));
        });
    }
    // The row above in Structure: a box's parts sit in the box, not its inner holder.
    function treeUp(el) {
        var p = el.parentElement;
        return p && p.classList.contains('nst-x-bd-frame__kids') ? p.parentElement : p;
    }

    function label(el, short) {
        var t = el.localName;
        var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(function (c) { return c && !/^(wp-block-|is-layout|has-|nst-x)/.test(c); })[0] : '';
        var id = el.id ? '#' + el.id : '';
        if (short) { return t + (id || (cls ? '.' + cls : '')); }
        return { tag: t, name: id || (cls ? '.' + cls : ''), text: /^(h[1-6]|p|a|button|span|li|label|figcaption|strong|em|small|blockquote|summary)$/.test(t) ? (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 48) : (t === 'img' ? (el.getAttribute('alt') || '').slice(0, 40) : '') };
    }

    function render() {
        if (!doc || blocked) { treeEl.innerHTML = ''; countEl.textContent = ''; return; }
        rows = [];
        var html = '';
        var walk = function (el, depth) {
            treeKids(el).forEach(function (c) {
                var i = rows.push(c) - 1;
                var fr = isFrame(c);
                var inner = !LEAF.test(c.localName) ? treeKids(c) : [];
                var isOpen = inner.length && open.has(c);
                var l = label(c);
                var isNew = !fr && (c.classList.contains('nst-x-bd-new') || c.hasAttribute('data-nst-new'));
                html += '<li role="treeitem" id="nst-bd-ti-' + i + '" aria-level="' + (depth + 1) + '"' + (inner.length ? ' aria-expanded="' + !!isOpen + '"' : '') + ' aria-selected="' + isPicked(c) + '">' +
                    '<div class="nst-bd__row' + (isPicked(c) ? ' is-sel' : '') + (isNew ? ' is-new' : '') + (fr ? ' is-frame' : '') + (c.getAttribute('data-nibwp-hide') === '' ? ' is-sitehidden' : '') + '" data-i="' + i + '" draggable="' + !fr + '" style="--d:' + depth + '">' +
                        (inner.length ? '<button type="button" class="nst-bd__chev" data-bd-toggle tabindex="-1" data-tip="' + esc(isOpen ? __('Fold', 'nibwp') : __('Unfold', 'nibwp')) + '" aria-label="' + esc(isOpen ? __('Fold', 'nibwp') : __('Open', 'nibwp')) + '">' + ico('chev', 12) + '</button>' : '<i class="nst-bd__chev is-none"></i>') +
                        (fr ? frameRow(c) :
                        '<span class="nst-bd__k" data-k="' + kindOf(c) + '">' + ico(kindOf(c), 13) + '</span>' +
                        '<span class="nst-bd__lbl"><i class="nst-bd__tg">&lt;' + esc(l.tag) + '&gt;</i><b>' + esc(nice(c)) + '</b>' + modsOf(c).map(function (m) { return '<i class="nst-bd__mod">' + esc(m) + '</i>'; }).join('') + hidChip(c) + '</span>') +
                        (inner.length && !isOpen ? '<small class="nst-bd__n">' + inner.length + '</small>' : '') +
                        '<span class="nst-bd__row-acts">' +
                            (fr ? '' : siteBtn(null, c) +
                            '<button type="button" data-bd-row="hide" tabindex="-1"' + (c.classList.contains('nst-x-bd-off') ? ' aria-pressed="true"' : '') + ' data-tip="' + esc(c.classList.contains('nst-x-bd-off') ? __('Show in the Builder', 'nibwp') : __('Hide in the Builder', 'nibwp')) + '" aria-label="' + esc(__('Hide in the Builder', 'nibwp')) + '">' + ico(c.classList.contains('nst-x-bd-off') ? 'eyeoff' : 'eye', 13) + '</button>' +
                            '<button type="button" data-bd-row="dup" tabindex="-1" data-tip="' + esc(__('Duplicate', 'nibwp')) + '" aria-label="' + esc(__('Duplicate', 'nibwp')) + '">' + ico('dup', 13) + '</button>') +
                            '<button type="button" data-bd-row="del" tabindex="-1" data-tip="' + esc(__('Delete', 'nibwp')) + '" aria-label="' + esc(__('Delete', 'nibwp')) + '">' + ico('trash', 13) + '</button>' +
                        '</span>' +
                    '</div>' +
                    (isOpen ? '<ul role="group">' : '');
                if (isOpen) { walk(c, depth + 1); html += '</ul>'; }
                html += '</li>';
            });
        };
        walk(doc.body, 0);
        treeEl.innerHTML = html;
        var n = doc.body.getElementsByTagName('*').length;
        countEl.textContent = sprintf(_n('%d element', '%d elements', n, 'nibwp'), n);
        markActive();
    }
    // A wireframe box's row: what it is, where it sits in its plan, its note.
    function frameRow(f) {
        var part = upFrame(f) ? f.querySelector('.nst-x-bd-frame__tag').textContent : f.classList.contains('is-sent') ? __('Building', 'nibwp') : '';
        var note = noteOf(f);
        return '<span class="nst-bd__k" data-k="frame">' + ico('layoutdraw', 13) + '</span>' +
            '<span class="nst-bd__lbl"><b>' + esc(__('Wireframe', 'nibwp')) + '</b>' + (part ? '<i class="nst-bd__mod">' + esc(part) + '</i>' : '') + (note ? '<em>' + esc(clip(note.replace(/\s+/g, ' '), 80)) + '</em>' : '') + '</span>';
    }
    // Screen readers follow the selected row while focus stays on the tree.
    function markActive() {
        var i = sel ? rows.indexOf(sel) : -1;
        if (i < 0) { treeEl.removeAttribute('aria-activedescendant'); return; }
        treeEl.setAttribute('aria-activedescendant', 'nst-bd-ti-' + i);
        var row = treeEl.querySelector('.nst-bd__row[data-i="' + i + '"]');
        if (row && document.activeElement === treeEl) { row.scrollIntoView({ block: 'nearest' }); }
    }

    function reveal(el) {
        for (var p = el.parentElement; p && p !== doc.documentElement; p = p.parentElement) { open.add(p); }
        render();
        var i = rows.indexOf(el);
        var row = i > -1 && treeEl.querySelector('[data-i="' + i + '"]');
        if (row) { row.scrollIntoView({ block: 'nearest', behavior: REDUCED ? 'auto' : 'smooth' }); }
    }

    // Esc lets go of the selection first, then closes the Builder.
    /* Several at once: Ctrl/⌘-click (or Shift-click on the page) adds one or
     * takes it off, Shift-click in Structure takes the rows in between. The
     * last one picked leads (its details, its toolbar); Delete, duplicate,
     * copy, cut and hide-here act on all of them, as one undo step. */
    var also = [];
    function isPicked(x) { return !!x && (x === sel || also.indexOf(x) > -1); }
    function picked() {
        var all = sel && sel.isConnected && !isFrame(sel) ? [sel] : [];
        also.forEach(function (x) { if (x !== sel && x.isConnected && !isFrame(x) && all.indexOf(x) < 0) { all.push(x); } });
        // One inside another: the outer one carries it. In page order.
        return all.filter(function (x) { return !all.some(function (y) { return y !== x && y.contains(x); }); })
            .sort(function (a, b) { return a.compareDocumentPosition(b) & 4 ? -1 : 1; });
    }
    function pickToggle(el) {
        if (el === sel) {
            var rest = also.filter(function (x) { return x !== el && x.isConnected; });
            sel = rest.pop() || null;
            also = rest;
        } else if (also.indexOf(el) > -1) {
            also = also.filter(function (x) { return x !== el; });
        } else {
            if (sel && sel.isConnected && !isFrame(sel)) { also.push(sel); }
            sel = el;
        }
        afterPick();
    }
    function pickRange(el) {
        var a = rows.indexOf(sel);
        var b = rows.indexOf(el);
        if (a < 0 || b < 0 || isFrame(el)) { select(el, false); return; }
        also = rows.slice(Math.min(a, b), Math.max(a, b) + 1).filter(function (x) { return x !== el && !isFrame(x); });
        sel = el;
        afterPick();
    }
    function afterPick() {
        focusTpl = null;
        if (!pop.hidden) { closePop(); }
        if (sel) { reveal(sel); }
        render();
        paint();
        inspect();
    }
    // Shown above the details while more than one is picked.
    function multiNote() {
        var n = picked().length;
        var m = elBox.querySelector('.nst-bd__multi');
        if (n < 2) { if (m) { m.remove(); } return; }
        if (!m) {
            m = document.createElement('p');
            m.className = 'nst-bd__multi';
            m.setAttribute('role', 'status');
            elBox.insertBefore(m, elBox.firstChild);
        }
        /* translators: %d: how many elements are selected */
        m.textContent = sprintf(_n('%d element selected', '%d elements selected', n, 'nibwp'), n) + ' · ' +
            /* translators: 1: the duplicate shortcut, 2: the copy shortcut, 3: the cut shortcut */
            sprintf(__('Delete, %1$s, %2$s and %3$s act on all.', 'nibwp'), KEYNAME('Ctrl+D'), KEYNAME('Ctrl+C'), KEYNAME('Ctrl+X'));
    }
    // Several changes as one undo step.
    var batching = null;
    function batch(label, fn) {
        batching = [];
        var ops;
        try { fn(); } finally { ops = batching; batching = null; }
        if (!ops.length) { return; }
        if (ops.length === 1) { past.push(ops[0]); future = []; changed(); return; }
        var made = [];
        var keys = [];
        ops.forEach(function (o) {
            if (o.patch && !o.plan) { made = made.concat(o.patch()); }
            (o.keys || []).forEach(function (k) { if (keys.indexOf(k) < 0) { keys.push(k); } });
        });
        past.push({
            label: label,
            el: ops[0].el,
            plan: !made.length,
            keys: keys,
            patch: made.length ? function () { return made; } : null,
            change: function () { return ops.map(function (o) { return o.change ? o.change() : ''; }).filter(Boolean).join('\n'); },
            redo: function () { ops.forEach(function (o) { o.redo(); }); },
            undo: function () { ops.slice().reverse().forEach(function (o) { o.undo(); }); }
        });
        future = [];
        changed();
    }
    function removePicked() {
        var list = picked();
        if (list.length < 2) { if (sel) { removeEl(sel); } return; }
        /* translators: %d: how many elements */
        batch(sprintf(__('remove %d elements', 'nibwp'), list.length), function () { list.forEach(function (x) { removeEl(x, ' '); }); });
        sel = null;
        also = [];
        render();
        paint();
        inspect();
        /* translators: %d: how many elements */
        kit.toast(sprintf(_n('Removed %d element. Ctrl+Z brings it back.', 'Removed %d elements. Ctrl+Z brings them back.', list.length, 'nibwp'), list.length), 'ok');
    }
    function duplicatePicked() {
        var list = picked();
        if (list.length < 2) { if (sel) { duplicateEl(sel); } return; }
        var copies = [];
        /* translators: %d: how many elements */
        batch(sprintf(__('duplicate %d elements', 'nibwp'), list.length), function () { list.forEach(function (x) { duplicateEl(x); if (sel && sel !== x) { copies.push(sel); } }); });
        sel = copies.pop() || null;
        also = copies;
        afterPick();
    }
    function hidePicked() {
        var list = picked();
        list.forEach(function (x) { x.classList.add('nst-x-bd-off'); });
        hover = null;
        sel = null;
        also = [];
        render();
        paint();
        inspect();
        kit.toast(list.length > 1
            /* translators: %d: how many elements */
            ? sprintf(__('%d elements hidden in the Builder only. Show them again from Structure.', 'nibwp'), list.length)
            : __('Hidden in the Builder only. Show it again from Structure.', 'nibwp'));
    }
    function unselect() { sel = null; also = []; render(); paint(); inspect(); }
    function select(el, fromPage, keep) {
        if (tplOn() && inDyn(el)) { tplWarn(); return; }
        if (!keep) { also = []; }
        sel = el;
        focusTpl = null;
        if (fromPage || rows.indexOf(el) < 0) { reveal(el); } else {
            markActive();
            treeEl.querySelectorAll('.nst-bd__row').forEach(function (r) { var on = isPicked(rows[+r.dataset.i]); r.classList.toggle('is-sel', on); r.parentNode.setAttribute('aria-selected', String(on)); });
        }
        var r = el.getBoundingClientRect();
        var vh = doc.defaultView.innerHeight;
        if (r.bottom < 0 || r.top > vh) { el.scrollIntoView({ block: 'center', behavior: REDUCED ? 'auto' : 'smooth' }); }
        paint();
        inspect();
    }

    treeEl.addEventListener('click', function (e) {
        var row = e.target.closest('.nst-bd__row');
        if (!row) { return; }
        var el = rows[+row.dataset.i];
        if (!el) { return; }
        if (e.target.closest('[data-bd-toggle]')) {
            if (open.has(el)) { open.delete(el); } else { open.add(el); }
            render();
            return;
        }
        var ra = e.target.closest('[data-bd-row]');
        if (ra) {
            hideTip();
            if (ra.dataset.bdRow === 'sitehide') { siteHide(el); }
            else if (ra.dataset.bdRow === 'sitesize') { var rr = ra.getBoundingClientRect(); if (sel !== el) { select(el, false); } openCtx(el, rr.left, rr.bottom + 4, treeEl, true); }
            else if (ra.dataset.bdRow === 'hide') { el.classList.toggle('nst-x-bd-off'); render(); paint(); }
            else if (ra.dataset.bdRow === 'dup') { duplicateEl(el); }
            else { removeEl(el); }
            return;
        }
        if (e.shiftKey && sel && sel.isConnected && !isFrame(sel)) { pickRange(el); return; }
        if ((e.ctrlKey || e.metaKey) && sel && sel.isConnected && !isFrame(sel) && !isFrame(el)) { pickToggle(el); return; }
        select(el, false);
    });
    treeEl.addEventListener('dblclick', function (e) {
        clearTimeout(renT);
        var row = e.target.closest('.nst-bd__row');
        var el = row && rows[+row.dataset.i];
        if (el) { openPop(el, isFrame(el) ? 'frame' : 'comment'); }
    });

    // Click the name of the selected element (or F2) to rename it in place.
    // The name is its main class: one with saved styles is renamed on the
    // whole site at once; any other changes on this element, kept on Save.
    var renT = 0;
    // The name opens as it is, the cursor at its end; typed is what you typed on
    // the selected row: added to the name, or '\b' to take its last letter off.
    function treeRename(el, typed) {
        if (isFrame(el)) { return; }
        var i = rows.indexOf(el);
        var row = i > -1 ? treeEl.querySelector('.nst-bd__row[data-i="' + i + '"]') : null;
        var b = row && row.querySelector('.nst-bd__lbl b');
        if (!b) { return; }
        var np = nameParts(el);
        var shown = np.base ? np.shown : '';
        var inp = document.createElement('input');
        inp.className = 'nst-bd__rowin';
        inp.spellcheck = false;
        inp.placeholder = __('Name', 'nibwp');
        inp.setAttribute('aria-label', np.kind === '#' ? __('Rename: its ID', 'nibwp') : __('Rename: its class', 'nibwp'));
        row.draggable = false;
        b.replaceWith(inp);
        inp.focus();
        inp.value = typed === '\b' ? shown.slice(0, -1) : shown + (typed || '');
        inp.setSelectionRange(inp.value.length, inp.value.length);
        var done = false;
        var finish = function (keep) {
            if (done) { return; }
            done = true;
            var slug = function (t) { return t.trim().replace(/^[.#]/, '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, ''); };
            var typed2 = slug(inp.value);
            if (!keep || !typed2 || typed2 === slug(shown)) { render(); return; }
            // The hidden parts stay: card__title--big renamed "Heading" is card__heading--big.
            var old = np.base;
            var v = np.pre + typed2 + np.post;
            if (!/^-?[_a-zA-Z][-_a-zA-Z0-9]*$/.test(v)) { kit.toast(__('Use letters, digits, dashes and underscores, starting with a letter.', 'nibwp'), 'bad'); render(); return; }
            if (np.kind === '#') {
                var idRe = new RegExp('#' + old.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&') + '(?![\\w-])');
                if (Object.keys(cssLive).some(function (k) { return idRe.test(k) && (cssLive[k] || '').trim() !== ''; })) { render(); renameEverywhere('#', old, v); return; }
                attrEl(el, 'id', v);
                kit.toast(sprintf(__('Renamed to #%s. Save to keep it.', 'nibwp'), v), 'ok');
                return;
            }
            if (old && hasRules(old)) { render(); renameEverywhere('.', old, v); return; }
            var list = clsOf(el).filter(function (c) { return !/^nst-x/.test(c); });
            if (old) { list[list.indexOf(old)] = v; } else { list.unshift(v); }
            attrEl(el, 'class', list.join(' '));
            kit.toast(sprintf(__('Renamed to .%s. Save to keep it.', 'nibwp'), v), 'ok');
        };
        ['pointerdown', 'mousedown', 'click', 'dblclick'].forEach(function (t) { inp.addEventListener(t, function (e) { e.stopPropagation(); }); });
        inp.addEventListener('keydown', function (e) {
            e.stopPropagation();
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        // Clicking another row's name: the rename is kept once that click has
        // landed, so the tree is not rebuilt under it (one click, not two).
        inp.addEventListener('blur', function () {
            if (!pressing) { finish(true); return; }
            document.addEventListener('pointerup', function go() {
                document.removeEventListener('pointerup', go, true);
                setTimeout(function () { finish(true); }, 0);
            }, true);
        });
    }
    var pressing = false;
    document.addEventListener('pointerdown', function () { pressing = true; }, true);
    document.addEventListener('pointerup', function () { pressing = false; }, true);
    document.addEventListener('pointercancel', function () { pressing = false; }, true);
    // A click on a name selects it and opens it to edit, right away.
    treeEl.addEventListener('click', function (e) {
        var b = e.target.closest('.nst-bd__lbl b');
        var row = b && b.closest('.nst-bd__row');
        if (!row || e.detail > 1 || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        var el = rows[+row.dataset.i];
        clearTimeout(renT);
        renT = setTimeout(function () { if (el && sel === el) { treeRename(el); } }, 0);
    });
    treeEl.addEventListener('keydown', function (e) { if (e.key === 'F2' && sel && sel.isConnected) { e.preventDefault(); treeRename(sel); } });
    treeEl.addEventListener('mouseover', function (e) {
        var row = e.target.closest('.nst-bd__row');
        var el = row && rows[+row.dataset.i];
        if (el !== hover) { hover = el || null; paint(); }
    });
    treeEl.addEventListener('mouseleave', function () { hover = null; paint(); });
    treeEl.addEventListener('keydown', function (e) {
        if (e.target !== treeEl) { return; }
        if (!sel || rows.indexOf(sel) < 0) {
            if ((e.key === 'ArrowDown' || e.key === 'Home') && rows[0]) { e.preventDefault(); select(rows[0], false); }
            else if ((e.key === 'ArrowUp' || e.key === 'End') && rows.length) { e.preventDefault(); select(rows[rows.length - 1], false); }
            return;
        }
        var i = rows.indexOf(sel);
        if (e.key === 'Home' && rows[0]) { e.preventDefault(); select(rows[0], false); }
        else if (e.key === 'End' && rows.length) { e.preventDefault(); select(rows[rows.length - 1], false); }
        else if (e.key === 'ArrowRight' && open.has(sel) && treeKids(sel).length && rows[i + 1]) { e.preventDefault(); select(rows[i + 1], false); }
        else if (e.key === 'ArrowDown' && rows[i + 1]) { e.preventDefault(); select(rows[i + 1], false); }
        else if (e.key === 'ArrowUp' && i > 0) { e.preventDefault(); select(rows[i - 1], false); }
        else if (e.key === 'ArrowRight' && treeKids(sel).length && !open.has(sel)) { e.preventDefault(); open.add(sel); render(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); if (open.has(sel)) { open.delete(sel); render(); } else if (treeUp(sel) && treeUp(sel) !== doc.body) { select(treeUp(sel), true); } }
        else if (e.key === 'Enter') { e.preventDefault(); openPop(sel, isFrame(sel) ? 'frame' : 'comment'); }
    });

    /* ------------------------------------------------------------------
     * Changes: each one done at once, undone and redone, and listed for
     * NibWP until Save hands them over.
     * ---------------------------------------------------------------- */

    // The site's header and footer are shared by every page.
    function area(el) {
        var a = el && el.closest ? el.closest('header, footer, [role="banner"], [role="contentinfo"], .wp-block-template-part') : null;
        if (!a) { return ''; }
        // A post's own header or footer (its title, its meta) belongs to the post.
        if (!a.matches('[role="banner"], [role="contentinfo"], .wp-block-template-part, .site-header, .site-footer, body > header, body > footer') && a.closest('main, article, .entry-content')) { return ''; }
        return /footer|contentinfo/.test(a.localName + ' ' + (a.getAttribute('role') || '') + ' ' + a.className) ? 'footer' : 'header';
    }
    function describe(el) {
        var l = label(el);
        var a = area(el);
        return l.tag + (l.name ? ' ' + l.name : '') + (l.text ? ' “' + l.text + '”' : '') + ' (' + kit.cssPath(el) + ')' + (a ? ' ' + sprintf(__('[in the site-wide %s: change it once in its shared template part so every page gets it]', 'nibwp'), a) : '');
    }
    // The element as visitors should get it: without the Builder's marks, and
    // without what only the signed-in person's copy of the page holds.
    function cleanHtml(el) {
        var c = el.cloneNode(true);
        Array.prototype.forEach.call(c.querySelectorAll('input[name="_wpnonce"], input[name="_wp_http_referer"], input[name="_wp_unfiltered_html_comment"], .post-edit-link, #wpadminbar'), function (n) { n.remove(); });
        [c].concat(Array.prototype.slice.call(c.querySelectorAll('*'))).forEach(function (n) {
            n.removeAttribute('contenteditable');
            n.removeAttribute('data-nst-new');
            n.removeAttribute('data-nst-x-post');
            if (n.hasAttribute('class')) {
                var k = (n.getAttribute('class') || '').split(/\s+/).filter(function (x) { return x && !/^nst-x/.test(x); }).join(' ');
                if (k) { n.setAttribute('class', k); } else { n.removeAttribute('class'); }
            }
        });
        return c.outerHTML;
    }
    // Inside a wireframe: a plan for NibWP, never a change to the live page.
    function inPlan(n) { return !!(n && n.closest && n.closest('.nst-x-bd-frame, .nst-x-bd-sketch')); }
    function clip(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n) + '…' : s; }

    /* ------------------------------------------------------------------
     * Template mode: a template opened from the Templates tab is edited
     * here, on a page that uses it. Saves go to the template and show on
     * every page it renders; what each post fills in stays the post's own.
     * ---------------------------------------------------------------- */
    var DYN = '.wp-block-post-content,.wp-block-post-title,.wp-block-post-excerpt,.wp-block-post-date,.wp-block-post-featured-image,.wp-block-post-terms,.wp-block-post-author,.wp-block-post-author-name,.wp-block-query,.wp-block-comments,.entry-content,.entry-title,.post-content,.page-content';
    var tplDocs = new WeakMap();
    var tplWant = null;
    var sameUrl = function (a, b) { var n = function (u) { return String(u || '').replace(/#.*$/, '').replace(/\/+$/, ''); }; return !!a && n(a) === n(b); };
    function tplNow() { return (doc && tplDocs.get(doc)) || null; }
    function tplOn() { return !!tplNow(); }
    // The post's own content, as the site marks it for the Builder (between
    // <!--nibwp:post--> and <!--/nibwp:post-->), whatever the theme wraps it in.
    var postSet = new WeakSet();
    function postScan() {
        if (doc) { doc.querySelectorAll('[data-nst-x-post]').forEach(function (n) { n.removeAttribute('data-nst-x-post'); }); }
        postSet = new WeakSet();
        if (!doc || !doc.body || !tplOn()) { return; }
        var w = doc.createTreeWalker(doc.body, 128);
        var c;
        while ((c = w.nextNode())) {
            if (c.data.trim() !== 'nibwp:post') { continue; }
            for (var n = c.nextSibling; n && !(n.nodeType === 8 && n.data.trim() === '/nibwp:post'); n = n.nextSibling) {
                if (n.nodeType === 1) { postSet.add(n); n.setAttribute('data-nst-x-post', ''); }
            }
        }
    }
    function postOwn(n) {
        for (var x = n; x && x !== doc.body; x = x.parentElement) { if (postSet.has(x)) { return true; } }
        return false;
    }
    // Inside a part the post fills in: its content, title, date, a list of posts.
    function inDyn(n) { return !!n && (postOwn(n) || !!(n.parentElement && n.parentElement.closest && n.parentElement.closest(DYN))); }
    function hasDyn(html) {
        if (!html || !doc) { return false; }
        if (/<!--\s*\/?nibwp:post/.test(html) || /data-nst-x-post/.test(html)) { return true; }
        var t = doc.createElement('template');
        t.innerHTML = html;
        return !!t.content.querySelector(DYN) || !!(t.content.firstElementChild && t.content.firstElementChild.matches(DYN));
    }
    function tplTouches(patches) {
        var q = function (sl) { try { return sl ? doc.querySelector(sl) : null; } catch (e) { return null; } };
        var into = function (p) { return !/^(before|after)$/.test(p.pos || ''); };
        return patches.some(function (p) {
            var el = q(p.s);
            var to = q(p.to);
            if (el && inDyn(el)) { return true; }
            if (to && (inDyn(to) || ((p.t === 'insert' || p.t === 'move') && into(p) && to.matches(DYN)))) { return true; }
            return (p.t === 'insert' || p.t === 'replace') && hasDyn(p.html);
        });
    }
    function tplWarn(copy) {
        kit.toast(copy
            ? __('In a template, the words and pictures come from each page (its title, price, text…). Style them here and change the words on the page itself. New elements you add here can be typed in.', 'nibwp')
            : __('That part is filled in by each post (its content, title, date…). Edit it on the post itself; the template keeps it as it is.', 'nibwp'));
    }
    // Elements added while editing the template: theirs to type in.
    var tplMade = new WeakSet();
    var copyOp = false;
    function tplMine(n) { for (var x = n; x; x = x.parentElement) { if (tplMade.has(x)) { return true; } } return false; }
    var tplNote = document.createElement('div');
    tplNote.className = 'nst-bd__tplnote';
    tplNote.hidden = true;
    tplNote.setAttribute('role', 'status');
    tplNote.innerHTML = '<span class="nst-bd__tplnote-i">' + ico('tpl', 15) + '</span><span class="nst-bd__tplnote-t"><b></b><small>' + esc(__('Changes show on every page that uses it. Its words and pictures come from each page: style them here, change them on the page.', 'nibwp')) + '</small></span>' +
        '<button type="button" class="nst-bd__btn" data-bd="tpl-done">' + esc(__('Done', 'nibwp')) + '</button>';
    function tplSync() {
        // A template asked for: on as its page is on the canvas, if nothing is waiting to be saved there.
        if (tplWant && doc && sameUrl(kit.pageInfo().url, tplWant.url) && !tplDocs.get(doc)) {
            if (pending()) { kit.toast(__('Save or undo the changes on this page first.', 'nibwp')); }
            else { tplDocs.set(doc, tplWant); histRefresh(); }
            tplWant = null;
        }
        var t = tplNow();
        postScan();
        tplNote.hidden = !t || !on;
        /* translators: %s: the template's name */
        if (t) { tplNote.querySelector('b').textContent = sprintf(__('Editing the template “%s”', 'nibwp'), t.name); }
        root.classList.toggle('is-tpl', !!t);
        if (doc && doc.documentElement) { doc.documentElement.classList.toggle('nst-x-tpl', !!t); }
        tplsEl.querySelectorAll('.nst-bd__tpl').forEach(function (li) { li.classList.toggle('is-editing', !!t && li.dataset.tplid === t.id); });
    }
    left.insertBefore(tplNote, left.querySelector('.nst-bd__head').nextSibling);
    tplNote.addEventListener('click', function (e) {
        if (!e.target.closest('[data-bd="tpl-done"]')) { return; }
        if (pending()) { kit.toast(__('Save or undo your template changes first.', 'nibwp')); return; }
        if (doc) { tplDocs.delete(doc); }
        tplSync();
        histRefresh();
    });
    function tplEdit(t) {
        if (!t.url || !t.tplid) { return; }
        tplWant = { id: t.tplid, name: t.tpl ? t.tpl.title : t.template, url: t.url };
        if (sameUrl(kit.pageInfo().url, t.url)) { tplSync(); return; }
        bridge.openTab(t.url);
    }

    function act(op) {
        // Only the page on screen is edited: an element of a tab out of sight never lands here.
        if (op.el && op.el.isConnected && op.el.ownerDocument !== doc) { return; }
        var plan = inPlan(op.el) || inPlan(op.to);
        op.redo();
        op.plan = plan || inPlan(op.el);
        // The patch as the page is now: an element added and then filled is
        // saved as it was added, its later edits as their own steps.
        if (op.patch) { var made = op.patch(); op.patch = function () { return made; }; }
        // On a template, what a page fills in is never written into it: no
        // copies of it, no new words over it, nothing inside a post's content.
        if (made && tplOn()) {
            var pl = [].concat(made);
            var rewrites = pl.some(function (pt) { return pt.t === 'replace'; }) && !tplMine(op.el) && !tplMine(op.to);
            if (copyOp || rewrites || tplTouches(pl)) { op.undo(); changed(); tplWarn(copyOp || rewrites); return; }
            if (op.el && pl.some(function (pt) { return pt.t === 'insert'; })) { tplMade.add(op.el); }
        }
        if (batching) { batching.push(op); return; }
        past.push(op);
        future = [];
        changed();
    }
    function undo() {
        var op = past.pop();
        // Nothing left here: what was saved before can be undone from the saved changes.
        if (!op) { offerSaved(); return; }
        op.undo();
        future.push(op);
        elBox.el = null;
        changed();
        kit.toast(sprintf(__('Undone: %s', 'nibwp'), op.label), 'ok');
    }
    function redo() {
        var op = future.pop();
        if (!op) { return; }
        op.redo();
        past.push(op);
        elBox.el = null;
        changed();
    }
    // Saved straight to the site: canvas changes, CSS, JS. What sits in a
    // wireframe is a plan for NibWP, not the page.
    function saves(o) { return o.kind === 'css' || o.kind === 'js' || (!!o.patch && !o.plan); }
    function pending() { return past.filter(saves).length; }
    function changed() {
        if (sel && !sel.isConnected) { sel = null; }
        if (!pop.hidden && popFor && !popFor.isConnected) { closePop(); }
        // The code panes show the element as it is now (the one being typed in stays).
        codeFor = null;
        render();
        paint();
        inspect();
        var n = pending();
        var sv = bar.querySelector('[data-bd="save"]');
        sv.disabled = !n;
        sv.classList.toggle('is-dirty', !!n);
        bar.querySelector('.nst-bd-bar__n').textContent = n ? String(n) : '';
        sv.setAttribute('aria-label', n ? sprintf(_n('Save %d change (Ctrl+S)', 'Save %d changes (Ctrl+S)', n, 'nibwp'), n) : __('Nothing to save', 'nibwp'));
        bar.querySelector('[data-bd="undo"]').disabled = !past.length;
        bar.querySelector('[data-bd="redo"]').disabled = !future.length;
        if (view === 'classes' && !left.querySelector('.nst-bd__clsin, .nst-bd__clsed:focus')) { renderClasses(); }
        markTab();
        idle();
    }
    // A wireframe drawn on the page that NibWP was not asked to build yet.
    function plans() { return !!(doc && doc.querySelector('.nst-x-bd-frame:not(.is-sent)')); }
    /* The tab carries a mark while its page holds work not saved: the
     * changes Save sends (their count), else a wireframe not built yet
     * ("plan"), else a design not saved ("design", see fwMark). Closing or
     * reloading that tab asks first. */
    function markTab() {
        var n = pending();
        var mark = n ? String(n) : plans() ? 'plan' : '';
        var fr = doc && doc.defaultView && doc.defaultView.frameElement;
        if (fr && fr.hasAttribute('data-nst-unsaved') !== !!mark) {
            if (mark) { fr.setAttribute('data-nst-unsaved', mark); } else { fr.removeAttribute('data-nst-unsaved'); }
            if (kit.tabsChanged) { kit.tabsChanged(); }
        } else if (fr && mark) { fr.setAttribute('data-nst-unsaved', mark); }
        fwMark();
    }

    function place(el, target, pos) {
        if (pos === 'before') { target.parentNode.insertBefore(el, target); }
        else if (pos === 'after') { target.parentNode.insertBefore(el, target.nextSibling); }
        else { target.appendChild(el); open.add(target); }
    }
    var POS = function (p) { return { before: __('before', 'nibwp'), after: __('after', 'nibwp'), inside: __('inside, at the end of', 'nibwp') }[p]; };

    function moveEl(el, target, pos) {
        var from = { parent: el.parentNode, next: el.nextSibling };
        var what = describe(el);
        var to = describe(target);
        var sS = kit.cssPath(el);
        var tS = kit.cssPath(target);
        act({
            patch: function () { return { t: 'move', s: sS, to: tS, pos: pos }; },
            el: el,
            to: target,
            label: sprintf(__('move %s', 'nibwp'), label(el, true)),
            change: function () { return sprintf(__('Move %1$s %2$s %3$s.', 'nibwp'), what, POS(pos), to); },
            redo: function () { place(el, target, pos); sel = el; },
            undo: function () { from.parent.insertBefore(el, from.next && from.next.parentNode === from.parent ? from.next : null); sel = el; }
        });
        reveal(el);
    }

    function insertEl(el, target, pos, kind, extra, css) {
        var to = describe(target);
        var tS = kit.cssPath(target);
        el.classList.add('nst-x-bd-new');
        var op = {
            patch: function () { return { t: 'insert', to: tS, pos: pos, html: cleanHtml(el) }; },
            label: sprintf(__('add %s', 'nibwp'), kind),
            note: '',
            el: el,
            to: target,
            change: function () { return sprintf(__('Add a new %1$s %2$s %3$s. Its HTML now:', 'nibwp'), kind, POS(pos), to) + '\n```html\n' + clip(cleanHtml(el), 4000) + '\n```' + (extra ? '\n' + extra : '') + (op.note ? '\n' + sprintf(__('What it should be: %s', 'nibwp'), op.note) : ''); },
            css: css || null,
            cssWas: {},
            keys: [],
            redo: function () { place(el, target, pos); sel = el; putCss(op, true); },
            undo: function () { el.remove(); if (sel === el) { sel = null; } putCss(op, false); }
        };
        act(op);
        reveal(el);
        return op;
    }

    // said: what the toast says instead (a cut is a removal too).
    function removeEl(el, said) {
        // A wireframe box is a plan: deleting it is not a change to the live page.
        if (isFrame(el)) { dropFrame(el); return; }
        var from = { parent: el.parentNode, next: el.nextSibling };
        var what = describe(el);
        if (!pop.hidden && popFor === el) { closePop(); }
        var sS = kit.cssPath(el);
        act({
            patch: function () { return { t: 'remove', s: sS }; },
            el: el,
            to: from.parent,
            label: sprintf(__('remove %s', 'nibwp'), label(el, true)),
            change: function () { return sprintf(__('Remove %s.', 'nibwp'), what); },
            redo: function () { el.remove(); if (sel === el) { sel = null; } },
            undo: function () { from.parent.insertBefore(el, from.next && from.next.parentNode === from.parent ? from.next : null); sel = el; }
        });
        kit.toast(said || __('Removed. Ctrl+Z brings it back.', 'nibwp'), 'ok');
    }

    function duplicateEl(el) {
        var copy = el.cloneNode(true);
        var what = describe(el);
        // An id is one element's: the copy gets none (anchors, labels and the
        // original's styles keep pointing at the original).
        [copy].concat(Array.prototype.slice.call(copy.querySelectorAll('[id]'))).forEach(function (n) { n.removeAttribute('id'); });
        copy.classList.add('nst-x-bd-new');
        var sS = kit.cssPath(el);
        copyOp = true;
        act({
            patch: function () { return { t: 'insert', to: sS, pos: 'after', html: cleanHtml(copy) }; },
            el: copy,
            to: el,
            label: sprintf(__('duplicate %s', 'nibwp'), label(el, true)),
            change: function () { return sprintf(__('Duplicate %s: the copy goes right after it.', 'nibwp'), what); },
            redo: function () { el.parentNode.insertBefore(copy, el.nextSibling); sel = copy; },
            undo: function () { copy.remove(); if (sel === copy) { sel = el; } }
        });
        copyOp = false;
        if (!copy.isConnected) { return; }
        reveal(copy);
    }

    /* Copy, cut and paste. The Builder is one for all of Agent View's tabs,
     * and so is its clipboard: what is copied on one page pastes on another. */
    var board = null;
    var styleBoard = null;
    // The system clipboard too, where the browser allows it: the HTML for an
    // editor that takes it, and the same as plain text everywhere else.
    function toSystem(html) {
        try {
            var w = doc && doc.hasFocus() ? doc.defaultView : window;
            var cb = w.navigator.clipboard;
            if (!cb) { return; }
            var asText = function () { return cb.writeText(html); };
            var p = w.ClipboardItem && cb.write ? cb.write([new w.ClipboardItem({ 'text/html': new w.Blob([html], { type: 'text/html' }), 'text/plain': new w.Blob([html], { type: 'text/plain' }) })]).then(null, asText) : asText();
            p.then(null, function () { });
        } catch (x) { }
    }
    // The element as visitors get it, with the saved class rules it uses.
    function copyEl(el, quiet) {
        var names = [];
        [el].concat(Array.prototype.slice.call(el.querySelectorAll('[class]'))).forEach(function (n) {
            clsOf(n).forEach(function (c) { if (!/^nst-x/.test(c) && names.indexOf(c) < 0) { names.push(c); } });
        });
        var re = names.length ? new RegExp('\\.(' + names.map(reEsc).join('|') + ')(?![\\w-])') : null;
        var css = {};
        if (re) { Object.keys(cssLive).forEach(function (k) { if (re.test(k) && (cssLive[k] || '').trim()) { css[k] = cssLive[k]; } }); }
        board = { html: cleanHtml(el), css: css, from: kit.pageInfo().url };
        toSystem(board.html);
        if (!quiet) { kit.toast(__('Copied', 'nibwp'), 'ok'); }
    }
    function copyPicked(cut) {
        var list = picked();
        var css = {};
        var many = list.map(function (x) { copyEl(x, true); Object.keys(board.css).forEach(function (k) { css[k] = board.css[k]; }); return board.html; });
        board = { html: many.join('\n'), many: many, css: css, from: kit.pageInfo().url };
        toSystem(board.html);
        if (cut) {
            removePicked();
            /* translators: %d: how many elements */
            kit.toast(sprintf(__('Cut %d elements. Ctrl+Z brings them back.', 'nibwp'), list.length), 'ok');
        } else {
            /* translators: %d: how many elements */
            kit.toast(sprintf(__('Copied %d elements', 'nibwp'), list.length), 'ok');
        }
    }
    function cutEl(el) {
        copyEl(el, true);
        removeEl(el, __('Cut. Ctrl+Z brings it back.', 'nibwp'));
    }
    // A fresh element from the clipboard, after at (what was right-clicked)
    // or where a click on a tile adds. The saved class rules it uses that
    // this page has not got come with it, in the same step.
    function pasteEl(at, pos) {
        if (!board || !doc || blocked) { return; }
        if (board.many && board.many.length > 1) {
            var one = board;
            var spot = at && at.isConnected ? { el: at, pos: 'after' } : defaultSpot();
            var put = [];
            /* translators: %d: how many elements */
            batch(sprintf(__('paste %d elements', 'nibwp'), one.many.length), function () {
                one.many.forEach(function (h, i) {
                    board = { html: h, css: i ? {} : one.css, from: one.from };
                    pasteEl(i ? put[i - 1] : spot.el, i ? null : spot.pos);
                    put.push(sel);
                });
            });
            board = one;
            sel = put[put.length - 1] || null;
            also = put.slice(0, -1);
            afterPick();
            /* translators: %d: how many elements */
            kit.toast(sprintf(__('Pasted %d elements', 'nibwp'), put.length), 'ok');
            return;
        }
        var el = fromHtml(board.html);
        // Ids are one element's: the copy gets none, as with Duplicate.
        [el].concat(Array.prototype.slice.call(el.querySelectorAll('[id]'))).forEach(function (n) { n.removeAttribute('id'); });
        var sp = at && at.isConnected ? { el: at, pos: pos || 'after' } : defaultSpot();
        var need = {};
        Object.keys(board.css).forEach(function (k) { if (!(cssLive[k] || '').trim()) { need[k] = board.css[k]; } });
        var name = label(el, true);
        var away = board.from && board.from !== kit.pageInfo().url;
        /* translators: %s: the address of the page an element was copied from */
        copyOp = true;
        var op = insertEl(el, sp.el, sp.pos, name, away ? sprintf(__('Copied from %s.', 'nibwp'), board.from) : '', Object.keys(need).length ? need : null);
        copyOp = false;
        if (!el.isConnected) { return; }
        /* translators: %s: the element pasted, like "div.card" */
        op.label = sprintf(__('paste %s', 'nibwp'), name);
        kit.toast(__('Pasted', 'nibwp'), 'ok');
    }
    // Styles: the classes (not the Builder's own marks) and the inline style.
    function copyStyle(el) {
        styleBoard = { cls: clsOf(el).filter(function (c) { return !/^nst-x/.test(c); }).join(' '), style: el.getAttribute('style') || '' };
        kit.toast(__('Styles copied', 'nibwp'), 'ok');
    }
    // One step: the class and the style, each set or taken off. The style
    // goes first: the class changes the path the saved page finds it by.
    function pasteStyle(el) {
        var st = styleBoard;
        if (!st || !el || !el.isConnected) { return; }
        var cWas = clsOf(el).filter(function (c) { return !/^nst-x/.test(c); }).join(' ');
        var sWas = el.getAttribute('style') || '';
        if (cWas !== st.cls || sWas !== st.style) {
            var sS = kit.cssPath(el);
            var put = function (c, s) { putClass(el, c); if (s) { el.setAttribute('style', s); } else { el.removeAttribute('style'); } sel = el; };
            act({
                patch: function () {
                    return [
                        st.style ? { t: 'attr', s: sS, name: 'style', html: st.style } : { t: 'unattr', s: sS, name: 'style' },
                        st.cls ? { t: 'attr', s: sS, name: 'class', html: st.cls } : { t: 'unattr', s: sS, name: 'class' }
                    ];
                },
                el: el,
                /* translators: %s: the element, like "div.card" */
                label: sprintf(__('paste styles on %s', 'nibwp'), label(el, true)),
                redo: function () { put(st.cls, st.style); },
                undo: function () { put(cWas, sWas); }
            });
        }
        kit.toast(__('Styles pasted', 'nibwp'), 'ok');
    }

    function replaceEl(el, html) {
        var tpl = doc.createElement('template');
        tpl.innerHTML = html.trim();
        var nodes = Array.prototype.slice.call(tpl.content.childNodes).filter(function (n) { return n.nodeType === 1 || (n.nodeType === 3 && n.textContent.trim()); });
        if (!nodes.length) { kit.toast(__('That HTML has no element in it.', 'nibwp')); return; }
        var what = describe(el);
        var parent = el.parentNode;
        var first = nodes[0].nodeType === 1 ? nodes[0] : null;
        var sS = kit.cssPath(el);
        var newHtml = html.trim();
        act({
            patch: function () { return { t: 'replace', s: sS, html: newHtml }; },
            el: el,
            to: parent,
            label: sprintf(__('edit the HTML of %s', 'nibwp'), label(el, true)),
            change: function () { return sprintf(__('Replace %s with this HTML:', 'nibwp'), what) + '\n```html\n' + clip(html.trim(), 6000) + '\n```'; },
            redo: function () { nodes.forEach(function (n) { parent.insertBefore(n, el); }); el.remove(); sel = first; },
            undo: function () { parent.insertBefore(el, nodes[0]); nodes.forEach(function (n) { n.remove(); }); sel = el; }
        });
        if (first) { reveal(first); }
    }

    // A class, an id or any attribute on an element, set on the canvas.
    function attrEl(el, name, value) {
        var before = el.getAttribute(name);
        var sS = kit.cssPath(el);
        act({
            patch: function () { return { t: 'attr', s: sS, name: name, html: value }; },
            el: el,
            label: sprintf(__('set %1$s on %2$s', 'nibwp'), name, label(el, true)),
            change: function () { return ''; },
            redo: function () { el.setAttribute(name, value); sel = el; },
            undo: function () { if (before === null) { el.removeAttribute(name); } else { el.setAttribute(name, before); } sel = el; }
        });
    }
    function writeCss() { marks.live.textContent = Object.keys(cssLive).map(function (k) { return cssLive[k]; }).join('\n'); }
    function setCss(key, what, before, after) {
        act({
            kind: 'css',
            key: key,
            label: sprintf(__('change the CSS of %s', 'nibwp'), what),
            change: function () { return after.trim() ? sprintf(__('Set this CSS (it styles %s):', 'nibwp'), what) + '\n```css\n' + clip(after.trim(), 4000) + '\n```' : ''; },
            redo: function () { cssLive[key] = after; writeCss(); },
            undo: function () { cssLive[key] = before; writeCss(); }
        });
    }

    function runJs(code, what) {
        var s = doc.createElement('script');
        s.textContent = code;
        doc.body.appendChild(s);
        s.remove();
        act({
            kind: 'js',
            code: code,
            label: sprintf(__('run a script on %s', 'nibwp'), what),
            change: function () { return sprintf(__('Add this JavaScript to the page (written for %s):', 'nibwp'), what) + '\n```js\n' + clip(code.trim(), 4000) + '\n```'; },
            redo: function () { },
            undo: function () { kit.toast(__('A script that ran cannot be undone on the page. Reload to see it without.', 'nibwp')); }
        });
    }

    /* ------------------------------------------------------------------
     * Dragging: rows in the tree, and new things onto the tree or the page.
     * ---------------------------------------------------------------- */

    var dragEl = null;
    var dragNew = null;
    left.addEventListener('dragstart', function (e) {
        var tile = e.target.closest('[data-bd-add], [data-bd-sketch], [data-bd-comp], [data-bd-wire]');
        if (tile) {
            dragNew = tileWhat(tile);
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', tile.textContent);
            return;
        }
        var row = e.target.closest('.nst-bd__row');
        dragEl = row && rows[+row.dataset.i];
        if (!dragEl) { return; }
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', label(dragEl, true));
        row.classList.add('is-dragging');
    });
    function spot(el, y, h) {
        var holds = HOLD.test(el.localName) && !LEAF.test(el.localName);
        var f = y / h;
        return f < 0.3 ? 'before' : f > 0.7 ? 'after' : (holds ? 'inside' : (f < 0.5 ? 'before' : 'after'));
    }
    function rowDrop(e) {
        var row = e.target.closest('.nst-bd__row');
        var el = row && rows[+row.dataset.i];
        if (!el || (dragEl && (el === dragEl || dragEl.contains(el)))) { return null; }
        // A wireframe box's row takes it in, as the box on the page does.
        if (isFrame(el)) { return { row: row, el: frameKids(el), pos: 'inside' }; }
        var r = row.getBoundingClientRect();
        return { row: row, el: el, pos: spot(el, e.clientY - r.top, r.height) };
    }
    function clearDrop() {
        treeEl.querySelectorAll('.is-before, .is-after, .is-inside').forEach(function (r) { r.classList.remove('is-before', 'is-after', 'is-inside'); });
        if (marks) { box(marks.drop, null); }
    }
    treeEl.addEventListener('dragover', function (e) {
        if (!dragEl && !dragNew && fileDrag(e)) {
            var fa = on && !blocked && !noImages(e.dataTransfer) ? rowFile(e) : null;
            clearDrop();
            e.preventDefault();
            e.dataTransfer.dropEffect = fa ? 'copy' : 'none';
            if (fa) { fa.row.classList.add('is-' + (fa.pos === 'swap' ? 'inside' : fa.pos)); }
            return;
        }
        if (!dragEl && !dragNew) { return; }
        var at = rowDrop(e);
        clearDrop();
        if (!at) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = dragNew ? 'copy' : 'move';
        at.row.classList.add('is-' + at.pos);
    });
    treeEl.addEventListener('dragleave', function (e) { if (!treeEl.contains(e.relatedTarget)) { clearDrop(); } });
    document.addEventListener('dragend', function () {
        clearDrop();
        treeEl.querySelectorAll('.is-dragging').forEach(function (r) { r.classList.remove('is-dragging'); });
        dragEl = null;
        dragNew = null;
    });
    treeEl.addEventListener('drop', function (e) {
        if (!dragEl && !dragNew && fileDrag(e)) {
            var fa = rowFile(e);
            clearDrop();
            e.preventDefault();
            if (fa && on && !blocked) { dropFiles(e.dataTransfer, fa); }
            return;
        }
        var at = rowDrop(e);
        clearDrop();
        if (!at) { return; }
        e.preventDefault();
        if (dragNew) { addNew(dragNew, at.el, at.pos); } else if (dragEl) { moveEl(dragEl, at.el, at.pos); }
        dragEl = null;
        dragNew = null;
    });
    // A file let go anywhere else on the panel is not opened by the browser.
    left.addEventListener('dragover', function (e) { if (!e.defaultPrevented && !dragEl && !dragNew && fileDrag(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'none'; } });
    left.addEventListener('drop', function (e) { if (!dragEl && !dragNew && fileDrag(e)) { e.preventDefault(); } });

    function pageSpot(e) {
        var fr = e.target.closest && e.target.closest('.nst-x-bd-frame');
        if (fr) { return { el: frameKids(fr), pos: 'inside', rect: fr.getBoundingClientRect() }; }
        var el = pickable(e.target);
        if (!el) { return null; }
        var r = el.getBoundingClientRect();
        var pos = spot(el, e.clientY - r.top, r.height);
        return { el: el, pos: pos, rect: pos === 'inside' ? r : { left: r.left, width: r.width, top: (pos === 'before' ? r.top : r.bottom) - 2, height: 4 } };
    }
    function onPageDragOver(e) {
        if (on && !dragNew && !dragEl && fileDrag(e)) {
            // Never opened by the browser; a line where it lands, a box round an image it replaces.
            e.preventDefault();
            var fa = !blocked && !aside() && !noImages(e.dataTransfer) ? fileSpot(e) : null;
            e.dataTransfer.dropEffect = blocked || aside() ? 'none' : 'copy';
            if (!fa) { box(marks.drop, null); return; }
            marks.drop.classList.toggle('is-in', fa.pos === 'inside' || fa.pos === 'swap');
            box(marks.drop, null, fa.rect);
            return;
        }
        if (!on || blocked || (!dragNew && !dragEl)) { return; }
        var at = pageSpot(e);
        if (!at || (dragEl && (at.el === dragEl || dragEl.contains(at.el)))) { box(marks.drop, null); return; }
        e.preventDefault();
        marks.drop.classList.toggle('is-in', at.pos === 'inside');
        box(marks.drop, null, at.rect);
    }
    function onPageDrop(e) {
        if (on && !dragNew && !dragEl && fileDrag(e)) {
            e.preventDefault();
            e.stopPropagation();
            box(marks.drop, null);
            if (!blocked && !aside()) { dropFiles(e.dataTransfer, fileSpot(e)); }
            return;
        }
        if (!on || blocked || (!dragNew && !dragEl)) { return; }
        var at = pageSpot(e);
        box(marks.drop, null);
        if (!at) { return; }
        e.preventDefault();
        e.stopPropagation();
        if (dragNew) { addNew(dragNew, at.el, at.pos); } else if (dragEl && at.el !== dragEl && !dragEl.contains(at.el)) { moveEl(dragEl, at.el, at.pos); }
        dragEl = null;
        dragNew = null;
    }

    /* ------------------------------------------------------------------
     * Images dropped from the desktop: each goes to the Media Library,
     * then onto the page where it was let go, one Ctrl+Z step each.
     * Dropped on an image, the first one takes its place.
     * ---------------------------------------------------------------- */

    function fileDrag(e) {
        var dt = e.dataTransfer;
        return !!(dt && dt.types && Array.prototype.indexOf.call(dt.types, 'Files') > -1);
    }
    // While dragging only the kinds are known: none of them an image, no line.
    function noImages(dt) {
        var items = dt && dt.items ? Array.prototype.filter.call(dt.items, function (it) { return it.kind === 'file'; }) : [];
        return items.length > 0 && items.every(function (it) { return it.type && !/^image\//.test(it.type); });
    }
    // On the page: an image under the pointer is replaced, a wireframe box
    // takes them in, anything else gets them beside it or inside it.
    function fileSpot(e) {
        var t = doc.elementFromPoint(e.clientX, e.clientY) || e.target;
        var fr = t.closest && t.closest('.nst-x-bd-frame');
        if (fr) { return { el: frameKids(fr), pos: 'inside', rect: fr.getBoundingClientRect() }; }
        var el = pickable(t);
        if (!el) { return null; }
        var im = el.localName === 'picture' ? el.querySelector('img') : el;
        if (im && im.localName === 'img') { return { el: im, pos: 'swap', rect: el.getBoundingClientRect() }; }
        return spotXY(el, e);
    }
    // In Structure: an image's row replaces it, any other row as for elements.
    function rowFile(e) {
        var at = rowDrop(e);
        var im = at && (at.el.localName === 'picture' ? at.el.querySelector('img') : at.el);
        if (im && im.localName === 'img') { at.el = im; at.pos = 'swap'; }
        return at;
    }
    // A name people read from the file's: "tour-colosseum_02.jpg" is "Tour colosseum 02".
    function altFrom(name) {
        var t = String(name || '').replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[-_.+]+/g, ' ').replace(/\s+/g, ' ').trim();
        return t ? t.charAt(0).toUpperCase() + t.slice(1) : '';
    }
    var ups = 0;
    function dropFiles(dt, at) {
        var all = Array.prototype.slice.call((dt && dt.files) || []);
        var list = all.filter(function (f) { return /^image\//.test(f.type); });
        if (!list.length) { kit.toast(__('Only images can be dropped on the page.', 'nibwp'), 'bad'); return; }
        list = list.filter(function (f) {
            if (!boot.maxUpload || f.size <= boot.maxUpload) { return true; }
            kit.toast(sprintf(__('%s is larger than this site accepts.', 'nibwp'), f.name), 'bad');
            return false;
        });
        if (!list.length) { return; }
        var d = doc;
        var last = null;
        var added = 0;
        var swapped = false;
        var lost = false;
        var bad = 0;
        // Placed once it is up: in the same page, at the spot it was dropped on
        // (or after the one before it); the spot gone, where a tile click adds.
        var put = function (f, w, h) {
            if (d !== doc || !on) { lost = true; return; }
            if (at && at.pos === 'swap' && !last && at.el.isConnected) { swapImg(at.el, f, w, h); last = at.el; swapped = true; return; }
            var img = d.createElement('img');
            img.setAttribute('src', f.url);
            img.setAttribute('alt', f.alt);
            if (w > 0 && h > 0) { img.setAttribute('width', String(w)); img.setAttribute('height', String(h)); }
            img.setAttribute('loading', 'lazy');
            var s = last && last.isConnected ? { el: last, pos: 'after' } : at && at.pos !== 'swap' && at.el.isConnected ? at : at && at.el.isConnected ? { el: at.el, pos: 'after' } : defaultSpot();
            insertEl(img, s.el, s.pos, __('image', 'nibwp'));
            last = img;
            added++;
        };
        ups++;
        kit.toast(list.length === 1 ? __('Uploading…', 'nibwp') : sprintf(_n('Uploading %d image…', 'Uploading %d images…', list.length, 'nibwp'), list.length));
        list.reduce(function (chain, file) {
            return chain.then(function () {
                var fd = new FormData();
                fd.append('file', file, file.name || ('image-' + Date.now() + '.png'));
                fd.append('nibwp_studio', '1');
                return kit.api(boot.media, { method: 'POST', body: fd }).then(function (m) {
                    var md = (m && m.media_details) || {};
                    var f = { url: (m && m.source_url) || '', alt: (m && m.alt_text) || altFrom(file.name), width: +md.width || 0, height: +md.height || 0 };
                    if (!f.url) { throw new Error(''); }
                    return new Promise(function (done) { imgSize(f, function (w, h) { put(f, w, h); done(); }); });
                }).catch(function (err) {
                    bad++;
                    kit.toast(sprintf(__('Could not upload %s.', 'nibwp'), file.name) + (err && err.message ? ' ' + err.message : ''), 'bad');
                });
            });
        }, Promise.resolve()).then(function () {
            ups--;
            idle();
            // A failure stays on screen rather than being covered by the good news.
            if (lost) { kit.toast(__('Uploaded to your Media Library. The page changed meanwhile, so it was not placed.', 'nibwp')); }
            else if (bad) { return; }
            else if (added) { kit.toast(sprintf(_n('%d image added to the page.', '%d images added to the page.', added, 'nibwp'), added), 'ok'); }
            else if (swapped) { kit.toast(__('Image replaced.', 'nibwp'), 'ok'); }
        });
    }

    function fromHtml(html) {
        var tpl = doc.createElement('template');
        tpl.innerHTML = html.trim();
        if (tpl.content.childElementCount === 1) { return tpl.content.firstElementChild; }
        var wrap = doc.createElement('div');
        wrap.appendChild(tpl.content);
        return wrap;
    }

    function tileWhat(t) {
        return t.dataset.bdAdd ? { el: t.dataset.bdAdd } : t.dataset.bdSketch ? { sketch: t.dataset.bdSketch } : t.dataset.bdWire ? { wire: t.dataset.bdWire } : { comp: t.dataset.bdComp };
    }
    function addNew(what, target, pos) {
        if (what.sketch) { dropSketch(what.sketch, target, pos); return; }
        if (what.wire) { var w = wireById(what.wire); if (w) { dropWire(w, target, pos); } return; }
        if (what.comp) {
            var c = (cfg.components || []).find(function (x) { return x.id === what.comp; });
            if (!c) { return; }
            var inst = fromHtml(c.html);
            inst.setAttribute('data-nst-comp', c.name);
            insertEl(inst, target, pos, sprintf(__('“%s” component', 'nibwp'), c.name), sprintf(__('This is the component “%s”: use the builder’s own component of that name if it exists, not a copy.', 'nibwp'), c.name));
            return;
        }
        var def = ELEMENTS.find(function (x) { return x.id === what.el; });
        if (!def) { return; }
        var made = KIT[def.id] ? kitMake(def.id, target, pos) : { el: fromHtml(def.html) };
        var el = made.el;
        var op = insertEl(el, target, pos, def.name.toLowerCase(), undefined, made.css);
        if (made.name) { op.bem = made; }
        setTimeout(function () { openPop(el, 'new', op); }, 60);
    }

    // Where a click on a tile adds: inside what is selected when it is an
    // empty box (a wireframe box always takes it in), else after it, or at
    // the end of the page's main content.
    function defaultSpot() {
        if (sel && sel.isConnected) {
            if (isFrame(sel)) { return { el: frameKids(sel), pos: 'inside' }; }
            if (HOLD.test(sel.localName) && !LEAF.test(sel.localName) && emptyBox(sel)) { return { el: sel, pos: 'inside' }; }
            return { el: sel, pos: 'after' };
        }
        var main = doc.querySelector('main') || doc.querySelector('.wp-site-blocks') || doc.body;
        var last = kids(main).filter(function (c) { return !/^(footer)$/.test(c.localName); }).pop();
        return last ? { el: last, pos: 'after' } : { el: main, pos: 'inside' };
    }
    // Nothing in it yet: no element, no text (spaces and line breaks aside).
    function emptyBox(el) {
        return !el.children.length && Array.prototype.every.call(el.childNodes, function (n) { return n.nodeType !== 3 || !n.nodeValue.trim(); });
    }

    // A wireframe dropped on the page: a marked place, and the wireframe to NibWP.
    function dropSketch(id, target, pos) {
        var w = (window.nibwpSketch ? window.nibwpSketch.list() : []).find(function (x) { return x.id === id; });
        if (!w) { return; }
        var ph = doc.createElement('div');
        ph.className = 'nst-x-bd-sketch';
        ph.setAttribute('data-nst-new', 'sketch');
        ph.textContent = sprintf(__('Wireframe “%s” goes here: NibWP is building it', 'nibwp'), w.name);
        var spec = JSON.parse(JSON.stringify(w));
        spec.place = { mode: 'drop', selector: kit.cssPath(target), position: pos === 'inside' ? 'after' : pos, near_text: (target.innerText || '').trim().split('\n')[0].slice(0, 120), page_url: kit.pageInfo().url };
        place(ph, target, pos);
        reveal(ph);
        /* translators: %s: a wireframe's name */
        kit.sendDesign(spec, sprintf(__('Build my wireframe “%s” where I dropped it on the page.', 'nibwp'), w.name), ph);
        kit.toast(__('Wireframe sent to NibWP to build where you dropped it.', 'nibwp'), 'ok');
    }

    /* ------------------------------------------------------------------
     * Frames: drag one where something should go and say what goes in
     * it. A frame holds parts and columns (more frames) and elements; a
     * frame with parts is a wireframe. Build sends a frame and all it holds
     * to NibWP as one plan, built right there.
     * ---------------------------------------------------------------- */

    var drawing = false;
    var shield = document.createElement('div');
    shield.className = 'nst-bd-drawing';
    shield.hidden = true;
    shield.innerHTML = '<div class="nst-bd-drawing__line" hidden></div><div class="nst-bd-drawing__box" hidden></div><div class="nst-bd-drawing__bar">' + ico('layoutdraw', 15) + '<p class="nst-bd-drawing__tip"></p><button type="button" class="nst-bd-drawing__x" data-bd-stopdraw>' + ico('x', 12) + '<span>' + esc(__('Back to the page', 'nibwp')) + '</span><kbd>Esc</kbd></button></div>';
    artboard.appendChild(shield);
    var drawBox = shield.querySelector('.nst-bd-drawing__box');
    var drawLine = shield.querySelector('.nst-bd-drawing__line');
    var drawTip = shield.querySelector('.nst-bd-drawing__tip');
    var TIP = __('Drag a box where the wireframe goes; the orange line shows where it lands', 'nibwp');

    function setDrawing(v) {
        drawing = !!v && on && !blocked;
        shield.hidden = !drawing;
        drawLine.hidden = true;
        drawTip.textContent = TIP;
        var b = left.querySelector('[data-bd="frame"]');
        if (b) { b.setAttribute('aria-pressed', String(drawing)); }
        bar.querySelector('[data-bd-ins="layout"]').setAttribute('aria-pressed', String(drawing));
    }

    function isFrame(n) { return !!(n && n.classList && n.classList.contains('nst-x-bd-frame')); }
    function frameKids(f) { return f.querySelector(':scope > .nst-x-bd-frame__kids'); }
    function upFrame(f) { return f.parentElement ? f.parentElement.closest('.nst-x-bd-frame') : null; }
    function topFrame(f) { var t = f; for (var p = upFrame(f); p; p = upFrame(p)) { t = p; } return t; }
    // What things flow in, for placing: a frame's own box, or the page's.
    function flow(c) {
        return (c.classList.contains('nst-x-bd-frame__kids') ? Array.prototype.slice.call(c.children) : kids(c)).filter(function (k) { return k.getClientRects().length > 0; });
    }
    var DESCEND = /^(div|section|main|article|aside|header|footer|form|figure|details|fieldset|blockquote)$/;

    // Where something drawn over r lands. A frame about as wide as a block
    // is a sibling of it; a narrower one goes inside the block it is drawn
    // in. Sliders and lists are never entered. It goes before the first
    // thing whose middle is past where the drawing starts.
    function spotAt(r) {
        var docH = doc.documentElement.scrollHeight;
        var fits = function (b) { return b.left <= r.left + 6 && b.right >= r.left + r.width - 6 && b.top <= r.top + 6 && b.bottom >= r.top + r.height - 6; };
        var c = doc.body;
        for (var guard = 0; guard < 40; guard++) {
            var next = null;
            flow(c).some(function (k) {
                var b = k.getBoundingClientRect();
                if (!fits(b)) { return false; }
                if (isFrame(k)) { next = frameKids(k); return true; }
                if (!DESCEND.test(k.localName) || ours(k) || k.scrollWidth > k.clientWidth + 4) { return false; }
                if (r.width < b.width * 0.85 || b.height > docH * 0.5 || flow(k).length < 2) { next = k; return true; }
                return false;
            });
            if (!next) { break; }
            c = next;
        }
        var list = flow(c);
        var rs = list.map(function (k) { return k.getBoundingClientRect(); });
        var row = rs.length > 1 && rs[1].top < rs[0].bottom - 4 && rs[1].left >= rs[0].right - 4;
        var i = -1;
        rs.some(function (b, n) { if (row ? b.left + b.width / 2 > r.left + r.width / 2 && b.bottom > r.top : b.top + b.height / 2 > r.top) { i = n; return true; } return false; });
        var cb = c.getBoundingClientRect();
        if (!list.length) { return { c: c, el: c, pos: 'inside', row: false, line: { left: cb.left, top: cb.top + 4, width: cb.width, height: 3 } }; }
        var at = i < 0 ? rs[rs.length - 1] : rs[i];
        var line = row ? { left: (i < 0 ? at.right : at.left) - 2, top: at.top, width: 3, height: at.height } : { left: at.left, top: (i < 0 ? at.bottom : at.top) - 2, width: at.width, height: 3 };
        return { c: c, el: i < 0 ? list[list.length - 1] : list[i], pos: i < 0 ? 'after' : 'before', row: row, line: line };
    }
    function where(s) {
        var el = s.el;
        if (el.classList && el.classList.contains('nst-x-bd-frame__kids')) { el = el.parentElement; }
        return (s.pos === 'inside' ? __('Lands inside', 'nibwp') : s.pos === 'before' ? __('Lands before', 'nibwp') : __('Lands after', 'nibwp')) + ' ' + (isFrame(el) ? __('the frame', 'nibwp') : label(el, true));
    }

    // The page's coordinates from the drawing sheet's: the sheet is on
    // screen, the page may be drawn smaller (k) inside it.
    function offset() {
        var fr = frameBox();
        var sr = shield.getBoundingClientRect();
        return { x: fr.left - sr.left, y: fr.top - sr.top, k: fr.k };
    }

    // Enter or Space on "Back to the page" too, not only a pointer.
    shield.addEventListener('click', function (e) { if (e.detail === 0 && e.target.closest('[data-bd-stopdraw]')) { setDrawing(false); } });
    shield.addEventListener('pointerdown', function (e) {
        if (e.target.closest('[data-bd-stopdraw]')) { e.preventDefault(); setDrawing(false); return; }
        if (e.target.closest('.nst-bd-drawing__bar')) { return; }
        e.preventDefault();
        var sr = shield.getBoundingClientRect();
        var o = offset();
        var x0 = e.clientX - sr.left;
        var y0 = e.clientY - sr.top;
        var r = null;
        var s = null;
        drawBox.hidden = false;
        var move = function (ev) {
            var x1 = ev.clientX - sr.left;
            var y1 = ev.clientY - sr.top;
            var b = { left: Math.min(x0, x1), top: Math.min(y0, y1), width: Math.abs(x1 - x0), height: Math.abs(y1 - y0) };
            drawBox.style.left = b.left + 'px';
            drawBox.style.top = b.top + 'px';
            drawBox.style.width = b.width + 'px';
            drawBox.style.height = b.height + 'px';
            r = { left: (b.left - o.x) / o.k, top: (b.top - o.y) / o.k, width: b.width / o.k, height: b.height / o.k };
            drawBox.textContent = wxh(Math.round(r.width), Math.round(r.height));
            if (r.width > 24 && r.height > 24) {
                s = spotAt(r);
                drawLine.hidden = false;
                drawLine.style.left = (s.line.left * o.k + o.x) + 'px';
                drawLine.style.top = (s.line.top * o.k + o.y) + 'px';
                drawLine.style.width = Math.max(3, s.line.width * o.k) + 'px';
                drawLine.style.height = Math.max(3, s.line.height * o.k) + 'px';
                drawTip.textContent = where(s);
            }
        };
        var up = function (ev) {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            document.removeEventListener('pointercancel', up);
            drawBox.hidden = true;
            drawLine.hidden = true;
            if (ev.type === 'pointerup' && r && s && r.width > 24 && r.height > 24) { addFrame(r, s); } else { drawTip.textContent = TIP; }
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
        document.addEventListener('pointercancel', up);
    });

    function fBtn(k, icon, tip, text) {
        return '<button type="button" data-nst-f="' + k + '"' + (text ? ' class="is-go"' : '') + ' data-nst-tip="' + esc(tip) + '" aria-label="' + esc(tip) + '"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">' + I[icon] + '</svg>' + (text ? '<span>' + esc(text) + '</span>' : '') + '</button>';
    }
    function newFrame(note, h) {
        var el = doc.createElement('div');
        el.className = 'nst-x-bd-frame nst-x-bd-new';
        el.setAttribute('data-nst-new', 'frame');
        el.innerHTML = '<div class="nst-x-bd-frame__bar"><b class="nst-x-bd-frame__tag"></b>' +
            fBtn('sec', 'plus', __('Add section', 'nibwp')) +
            fBtn('cols', 'columns', __('Split columns', 'nibwp')) +
            fBtn('save', 'save', __('Save wireframe', 'nibwp')) +
            fBtn('x', 'x', __('Remove', 'nibwp')) +
            fBtn('send', 'spark', __('Build (Enter)', 'nibwp'), __('Build', 'nibwp')) +
            '</div><p class="nst-x-bd-frame__note"></p><div class="nst-x-bd-frame__kids"></div><i class="nst-x-bd-frame__rs" data-nst-rs aria-hidden="true"></i>';
        setNote(el, note || '');
        if (h) { el.style.minHeight = Math.round(h) + 'px'; }
        return el;
    }
    function noteOf(f) { return f.getAttribute('data-note') || ''; }
    function setNote(f, text) {
        f.setAttribute('data-note', text);
        var p = f.querySelector(':scope > .nst-x-bd-frame__note');
        p.textContent = text || (upFrame(f) ? __('Note (optional)', 'nibwp') : __('Click to say what NibWP builds here', 'nibwp'));
        p.classList.toggle('is-empty', !text);
    }
    // Names follow where a frame sits: the outer one is the plan.
    function relabel(root) {
        if (!root || !root.isConnected) { return; }
        [root].concat(Array.prototype.slice.call(root.querySelectorAll('.nst-x-bd-frame'))).forEach(function (f) {
            setNote(f, noteOf(f));
            var up = upFrame(f);
            var cols = up && +f.parentElement.getAttribute('data-cols');
            var n = Array.prototype.indexOf.call(f.parentElement.children, f) + 1;
            var parts = frameKids(f).children.length;
            f.querySelector('.nst-x-bd-frame__tag').textContent = !up ? __('Wireframe', 'nibwp') : cols ? sprintf(__('Column %d', 'nibwp'), n) : sprintf(__('Section %d', 'nibwp'), n);
            f.querySelector(':scope > .nst-x-bd-frame__note').hidden = !!up && !noteOf(f) && parts > 0;
            f.querySelector('.nst-x-bd-frame__bar [data-nst-f="send"]').hidden = !!up;
            f.querySelector('.nst-x-bd-frame__bar [data-nst-f="save"]').hidden = !!up;
        });
    }

    function addFrame(r, s) {
        var inFrame = s.c.classList.contains('nst-x-bd-frame__kids');
        var cb = (s.pos === 'inside' ? s.el : s.c).getBoundingClientRect();
        var el = newFrame('', inFrame ? 0 : r.height);
        var pct = cb.width ? Math.min(1, r.width / cb.width) : 1;
        if (s.row) { el.style.flex = '1 1 ' + Math.round(r.width) + 'px'; }
        else if (!inFrame) {
            el.style.width = pct < 0.9 ? Math.round(pct * 100) + '%' : '100%';
            el.style.alignSelf = 'stretch';
            if (pct < 0.9) { el.style.marginLeft = Math.max(0, Math.min(100 - Math.round(pct * 100), Math.round((r.left - cb.left) / cb.width * 100))) + '%'; }
        }
        act({
            label: __('draw a wireframe', 'nibwp'),
            redo: function () { place(el, s.el, s.pos); relabel(topFrame(el)); sel = el; },
            undo: function () { var t = upFrame(el); el.remove(); if (t) { relabel(topFrame(t)); } if (sel === el) { sel = null; } }
        });
        setDrawing(false);
        focusFrame(el);
    }
    // The same comment bubble as anywhere else, beside the frame.
    function focusFrame(f) {
        setTimeout(function () {
            if (!f.isConnected) { return; }
            var b = f.getBoundingClientRect();
            if (b.top < 0 || b.top > doc.defaultView.innerHeight - 80) { f.scrollIntoView({ block: 'center', behavior: 'auto' }); }
            openPop(f, 'frame');
        }, 40);
    }

    // A part inside a frame, or one more column.
    function framePart(f, cols) {
        var k = frameKids(f);
        var before = k.getAttribute('data-cols');
        var have = k.children.length;
        var n = cols ? Math.min(4, Math.max(2, (+before || have) + (before ? 1 : 0))) : 0;
        var add = [];
        var want = cols ? Math.max(n - have, have < 4 && before ? 1 : 0) : 1;
        for (var i = 0; i < want; i++) { add.push(newFrame('', 0)); }
        if (cols) { n = Math.min(4, Math.max(n, have + add.length)); }
        act({
            label: cols ? __('split into columns', 'nibwp') : __('add a section', 'nibwp'),
            redo: function () { if (cols) { k.setAttribute('data-cols', String(n)); } add.forEach(function (a) { k.appendChild(a); }); relabel(topFrame(f)); sel = f; },
            undo: function () { add.forEach(function (a) { a.remove(); }); if (before === null) { k.removeAttribute('data-cols'); } else { k.setAttribute('data-cols', before); } relabel(topFrame(f)); sel = f; }
        });
    }
    function dropFrame(f) {
        var from = { parent: f.parentNode, next: f.nextSibling };
        var up = upFrame(f);
        act({
            label: __('remove a wireframe box', 'nibwp'),
            redo: function () { f.remove(); if (up) { relabel(topFrame(up)); } if (sel === f || (sel && f.contains(sel))) { sel = up; } },
            undo: function () { from.parent.insertBefore(f, from.next && from.next.parentNode === from.parent ? from.next : null); relabel(topFrame(f)); sel = f; }
        });
    }

    // A frame and all it holds, as a plan NibWP can build in one go.
    function outline(f, depth) {
        var pad = new Array(depth + 1).join('  ');
        var k = frameKids(f);
        var cols = +k.getAttribute('data-cols');
        var r = f.getBoundingClientRect();
        var note = noteOf(f) || __('no note: make it fit what is around it', 'nibwp');
        var bits = [];
        if (!depth) { bits.push(sprintf(__('%1$dpx wide, at least %2$dpx tall', 'nibwp'), Math.round(r.width), Math.round(r.height))); }
        if (cols) { bits.push(sprintf(__('%d columns side by side', 'nibwp'), cols)); }
        var lines = [pad + '- ' + (depth ? f.querySelector('.nst-x-bd-frame__tag').textContent : __('The whole wireframe', 'nibwp')) + (bits.length ? ' (' + bits.join(', ') + ')' : '') + ': ' + note];
        Array.prototype.forEach.call(k.children, function (c) {
            if (isFrame(c)) { lines.push(outline(c, depth + 1)); }
            else { lines.push(pad + '  - ' + __('An element I placed here, use it as it is:', 'nibwp') + ' ' + clip(cleanHtml(c), 1200)); }
        });
        return lines.join('\n');
    }
    function askFrame(f) {
        f = topFrame(f);
        var plan = outline(f, 0);
        var prev = f.previousElementSibling;
        var next = f.nextElementSibling;
        var spot = prev && !ours(prev) ? sprintf(__('right after %s', 'nibwp'), describe(prev)) : next && !ours(next) ? sprintf(__('right before %s', 'nibwp'), describe(next)) : sprintf(__('inside %s', 'nibwp'), describe(f.parentElement));
        kit.commentOn(f, sprintf(__('Build this %s, in the builder this page uses, in place of the wireframe I drew. Follow its structure: each section and column as laid out, responsive, on the site’s design system.', 'nibwp'), spot) + '\n' + plan, f);
        [f].concat(Array.prototype.slice.call(f.querySelectorAll('.nst-x-bd-frame'))).forEach(function (x) { x.classList.add('is-sent'); });
        f.querySelector('.nst-x-bd-frame__tag').textContent = __('NibWP is building this…', 'nibwp');
        // Asked for: no longer work waiting on this tab.
        markTab();
        if (!kit.mode || kit.mode() !== 'queue') { kit.toast(__('Sent to NibWP. It builds it right here.', 'nibwp'), 'ok'); }
    }
    // Drag a wireframe box's corner to size it: taller, and wider or
    // narrower where it is not a column.
    var rsAt = 0;
    function onResize(e) {
        var g = on && e.target.closest && e.target.closest('[data-nst-rs]');
        if (!g) { return; }
        e.preventDefault();
        e.stopPropagation();
        var f = g.parentElement;
        var cols = f.parentElement && f.parentElement.hasAttribute('data-cols');
        var b0 = f.getBoundingClientRect();
        var room = f.parentElement.getBoundingClientRect().width || b0.width;
        var ml = parseFloat(f.style.marginLeft) || 0;
        var before = { w: f.style.width, h: f.style.minHeight };
        var x0 = e.clientX;
        var y0 = e.clientY;
        try { g.setPointerCapture(e.pointerId); } catch (x) { }
        var move = function (ev) {
            var h = Math.max(48, Math.round(b0.height + ev.clientY - y0));
            f.style.minHeight = h + 'px';
            if (!cols) { f.style.width = Math.max(10, Math.min(100 - ml, Math.round((b0.width + ev.clientX - x0) / room * 100))) + '%'; }
            f.setAttribute('data-size', wxh(Math.round(f.getBoundingClientRect().width), h));
        };
        var set = function (v) { f.style.width = v.w; f.style.minHeight = v.h; };
        var up = function (ev) {
            g.removeEventListener('pointermove', move);
            g.removeEventListener('pointerup', up);
            g.removeEventListener('pointercancel', up);
            g.removeEventListener('lostpointercapture', up);
            f.removeAttribute('data-size');
            rsAt = Date.now();
            // Taken over by the system (a pen or touch gesture): back to how it was.
            if (ev && ev.type !== 'pointerup') { set(before); return; }
            var after = { w: f.style.width, h: f.style.minHeight };
            if (after.w === before.w && after.h === before.h) { return; }
            act({
                label: __('resize a wireframe box', 'nibwp'),
                redo: function () { set(after); sel = f; },
                undo: function () { set(before); sel = f; }
            });
        };
        g.addEventListener('pointermove', move);
        g.addEventListener('pointerup', up);
        g.addEventListener('pointercancel', up);
        g.addEventListener('lostpointercapture', up);
    }
    function frameClick(e, f) {
        if (Date.now() - rsAt < 120) { e.preventDefault(); return; }
        var b = e.target.closest('[data-nst-f]');
        if (!pop.hidden) { closePop(); }
        select(f, true);
        e.preventDefault();
        if (!b) { openPop(f, 'frame'); return; }
        frameDo(b.dataset.nstF, f);
    }
    function frameDo(k, f) {
        if (k === 'send') { openPop(topFrame(f), 'frame'); }
        else if (k === 'x') { dropFrame(f); }
        else if (k === 'sec') { framePart(f, false); }
        else if (k === 'cols') { framePart(f, true); }
        else if (k === 'save') { openPop(topFrame(f), 'wireframe'); }
    }

    // A wireframe as data, to keep and to lay out again anywhere.
    function frameSpec(f) {
        var k = frameKids(f);
        return {
            note: noteOf(f),
            h: Math.round(parseFloat(f.style.minHeight) || 0),
            cols: +k.getAttribute('data-cols') || 0,
            kids: Array.prototype.filter.call(k.children, isFrame).map(frameSpec)
        };
    }
    function specFrame(s) {
        var f = newFrame(s.note || '', s.h || 0);
        var k = frameKids(f);
        if (s.cols) { k.setAttribute('data-cols', String(s.cols)); }
        (s.kids || []).forEach(function (c) { k.appendChild(specFrame(c)); });
        return f;
    }
    function dropWire(w, target, pos) {
        var f = specFrame(w.spec);
        f.style.width = '100%';
        f.style.alignSelf = 'stretch';
        if (w.id) { f.setAttribute('data-wire', w.id); f.setAttribute('data-wire-name', w.name); }
        act({
            label: sprintf(__('place the “%s” wireframe', 'nibwp'), w.name),
            redo: function () { place(f, target, pos); relabel(f); sel = f; },
            undo: function () { f.remove(); if (sel === f) { sel = null; } }
        });
        focusFrame(f);
        kit.toast(__('Wireframe placed. Shape it, then Build.', 'nibwp'), 'ok');
    }
    function saveWire(f, name) {
        var id = f.getAttribute('data-wire-name') === name ? f.getAttribute('data-wire') || '' : '';
        kit.api('components', { method: 'POST', body: { id: id, name: name, kind: 'wireframe', html: JSON.stringify(frameSpec(f)) } }).then(function (r) {
            if (!r || !r.component) { return; }
            f.setAttribute('data-wire', r.component.id);
            f.setAttribute('data-wire-name', name);
            cfg.components = [r.component].concat((cfg.components || []).filter(function (c) { return c.id !== r.component.id; }));
            renderWires();
            fly(f, r.component.id);
            kit.toast(sprintf(__('“%s” saved to your wireframes.', 'nibwp'), name), 'ok');
        }, function (x) { kit.toast((x && x.status && x.message) || __('Could not save the wireframe.', 'nibwp'), 'bad'); });
    }

    /* ------------------------------------------------------------------
     * The bubble on an element: a comment goes to NibWP at once; a note on
     * something just added waits with it for Save; a component name saves
     * it to your components and asks NibWP to make it a real one on Save.
     * ---------------------------------------------------------------- */

    var popFor = null;
    var popOp = null;
    var popMode = 'comment';
    var popFrom = null;
    // A dialog named by its title, and so is the field in it.
    pop.setAttribute('role', 'dialog');
    pop.querySelector('.nst-bd-pop__t').id = 'nst-bd-pop-t';
    pop.setAttribute('aria-labelledby', 'nst-bd-pop-t');
    pop.querySelector('textarea').setAttribute('aria-labelledby', 'nst-bd-pop-t');
    function openPop(el, mode, op, quiet) {
        var from = document.activeElement;
        if (!quiet && from && from !== document.body && !pop.contains(from)) { popFrom = from; }
        popFor = el;
        popMode = mode;
        popOp = op || null;
        var isF = mode === 'frame';
        var top = isF && el === topFrame(el);
        var parts = isF && top && el.querySelector('.nst-x-bd-frame');
        var size = function () { var r = el.getBoundingClientRect(); return ' (' + wxh(Math.round(r.width), Math.round(r.height)) + ')'; };
        var tag = isF ? el.querySelector('.nst-x-bd-frame__tag').textContent : '';
        var t = { comment: sprintf(__('Comment on %s', 'nibwp'), label(el, true)), 'new': sprintf(__('New %s: what should it be?', 'nibwp'), label(el, true)), component: sprintf(__('Save %s as a component', 'nibwp'), label(el, true)) + size(), frame: top ? __('What should NibWP build here?', 'nibwp') : sprintf(__('%s: a note (optional)', 'nibwp'), tag), wireframe: __('Save as a wireframe', 'nibwp') }[mode];
        pop.querySelector('.nst-bd-pop__t').textContent = t;
        var f = pop.querySelector('textarea');
        f.value = mode === 'new' && op ? op.note : isF ? noteOf(el) : mode === 'wireframe' ? (el.getAttribute('data-wire-name') || '') : '';
        f.rows = mode === 'component' || mode === 'wireframe' ? 1 : 3;
        f.placeholder = { comment: __('e.g. Make it bigger and orange · Put the attached photo here', 'nibwp'), 'new': __('e.g. Three tour cards with photo, price and a Book button', 'nibwp'), component: __('Name it, e.g. Tour card', 'nibwp'), frame: top ? (parts ? __('e.g. A landing page for our Rome tours, following this wireframe', 'nibwp') : __('e.g. A hero with a big title, a photo and a Book button', 'nibwp')) : __('e.g. Three reviews with stars and names', 'nibwp'), wireframe: __('Name it, e.g. Tour landing page', 'nibwp') }[mode];
        popSend();
        pop.querySelector('[data-bd="pop-attach"]').hidden = mode === 'component' || mode === 'wireframe';
        var pa = pop.querySelector('.nst-bd-pop__attrs');
        pa.hidden = mode !== 'new';
        pa.value = '';
        var nm = pop.querySelector('.nst-bd-pop__name input');
        var named = mode === 'new' && op && op.bem && op.bem.name;
        nm.parentNode.hidden = !named;
        if (named && !quiet) { nm.value = op.bem.name; }
        popName();
        pop.hidden = false;
        placePop();
        pFiles();
        if (!quiet) { setTimeout(function () { f.focus(); }, 20); }
        paintPopMode();
        paint();
    }
    function placePop() {
        if (pop.hidden || !popFor || !popFor.isConnected) { return; }
        var fr = frameBox();
        var r = popFor.getBoundingClientRect();
        var w = 300;
        var x = Math.min(Math.max(fr.left + 8, fr.left + r.left * fr.k), fr.right - w - 8);
        var y = fr.top + r.bottom * fr.k + 8;
        if (y + 170 > fr.bottom) { y = Math.max(fr.top + 8, fr.top + r.top * fr.k - 178); }
        pop.style.left = x + 'px';
        pop.style.top = y + 'px';
    }
    // The chat's mode, here too: Auto, Plan, Queue or Bypass.
    function paintPopMode() {
        var b = pop.querySelector('[data-bd="pop-mode"]');
        var asks = popMode0();
        b.hidden = !kit.mode || !asks;
        if (b.hidden) { return; }
        var m = kit.mode();
        var all = kit.modes();
        b.dataset.mode = m;
        b.innerHTML = (kit.icon ? kit.icon(all[m].icon, 13) : '') + '<span>' + esc(all[m].label) + '</span>';
        b.setAttribute('data-tip', all[m].tip || all[m].hint);
        if (m !== 'auto') { pop.querySelector('[type="submit"] span').textContent = all[m].send; popTip(); }
    }
    function popMode0() { return pop && !pop.hidden && (popMode === 'comment' || popMode === 'new' || (popMode === 'frame' && popFor && popFor === topFrame(popFor))); }
    // What the send button says for this bubble (the chat's mode may say it otherwise).
    function popSend() {
        var isF = popMode === 'frame';
        var top = isF && popFor && popFor === topFrame(popFor);
        pop.querySelector('[type="submit"] span').textContent = popMode === 'component' ? __('Save component', 'nibwp') : popMode === 'wireframe' ? __('Save wireframe', 'nibwp') : isF ? (top ? __('Build', 'nibwp') : __('Save note', 'nibwp')) : __('Ask NibWP', 'nibwp');
        popTip();
    }
    function popTip() {
        var s = pop.querySelector('[type="submit"]');
        s.setAttribute('data-tip', s.querySelector('span').textContent + ' (Enter)');
    }
    // Another mode: only the button changes, what was typed stays.
    document.addEventListener('nibwp-studio-mode', function () { if (!pop.hidden && popFor) { popSend(); paintPopMode(); } });
    function closePop() {
        popNameFlush();
        var was = !pop.hidden;
        // A wireframe keeps what was typed for it: closing the bubble never loses a brief.
        var kept = was && popMode === 'frame' && popFor && popFor.isConnected && pop.querySelector('textarea').value.trim() !== noteOf(popFor);
        if (kept) { setNote(popFor, pop.querySelector('textarea').value.trim()); }
        var inside = pop.contains(document.activeElement) || document.activeElement === document.body;
        pop.hidden = true;
        popFor = null;
        popOp = null;
        idle();
        paint();
        if (kept) { render(); }
        // Back to what opened it, so the next key does not land on the canvas.
        var to = popFrom;
        popFrom = null;
        if (was && inside && to && to.isConnected && to.getClientRects().length) { to.focus({ preventScroll: true }); }
    }
    function thumbs() {
        var list = kit.attachments ? kit.attachments() : [];
        if (!list.length) { return ''; }
        return list.slice(0, 4).map(function (a) {
            return /^image\//.test(a.mime) && a.url ? '<img class="nst-bd-thumb' + (a.busy ? ' is-busy' : '') + '" src="' + esc(a.url) + '" alt="' + esc(a.name) + '" title="' + esc(a.name) + '">' : '<span class="nst-bd-thumb is-file' + (a.busy ? ' is-busy' : '') + '" title="' + esc(a.name) + '">' + esc((a.name.split('.').pop() || '').slice(0, 4).toUpperCase()) + '</span>';
        }).join('') + '<small>' + esc(sprintf(_n('%d file', '%d files', list.length, 'nibwp'), list.length)) + '</small>';
    }
    function pFiles() {
        pop.querySelector('.nst-bd-pop__files').innerHTML = thumbs();
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
    watchRow(pop.querySelector('.nst-bd-pop__bar'));
    pop.addEventListener('click', function (e) {
        var a = e.target.closest('[data-bd]');
        if (!a) { return; }
        if (a.dataset.bd === 'pop-close') { closePop(); }
        if (a.dataset.bd === 'pop-mode' && kit.pickMode) { kit.pickMode(a); }
        if (a.dataset.bd === 'pop-attach') { fileIn.click(); }
    });
    pop.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePop(); }
        // Enter sends from the fields; on a button it presses that button.
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229 && e.target.matches('textarea, .nst-bd-pop__name input, input.nst-bd-pop__attrs')) { e.preventDefault(); pop.requestSubmit(); }
    });
    pop.addEventListener('submit', function (e) {
        e.preventDefault();
        if (popMode === 'new') { popNameNow(); }
        var text = pop.querySelector('textarea').value.trim();
        if (!popFor) { closePop(); return; }
        var addAttrs = pop.querySelector('.nst-bd-pop__attrs').value.trim();
        if (popMode === 'new' && addAttrs && popFor) { attrsEl(popFor, addAttrs, true); }
        if (popMode === 'new' && !text) { closePop(); return; }
        if (popMode === 'component') { if (text) { makeComponent(popFor, text); } closePop(); return; }
        if (popMode === 'wireframe') { if (text) { saveWire(popFor, text); } closePop(); return; }
        if (popMode === 'frame') {
            var fr = popFor;
            setNote(fr, text);
            closePop();
            if (fr === topFrame(fr)) { askFrame(fr); setTimeout(files, 50); } else { relabel(topFrame(fr)); }
            render();
            return;
        }
        if (!text && !kit.files()) { pop.querySelector('textarea').focus(); return; }
        kit.commentOn(popFor, text || __('Use the attached file here.', 'nibwp'), pop);
        closePop();
        setTimeout(files, 50);
        if (!kit.mode || kit.mode() !== 'queue') { kit.toast(__('Sent to NibWP. Follow it in the chat.', 'nibwp'), 'ok'); }
    });

    // A saved component or wireframe drops into its place in the Add tab.
    function fly(el, id) {
        setView('add');
        var grp = left.querySelector(isFrame(el) ? '[data-grp="wires"]' : '[data-grp="components"]');
        if (grp && grp.hasAttribute('data-shut')) { grp.removeAttribute('data-shut'); grp.querySelector('[data-bd-grp]').setAttribute('aria-expanded', 'true'); }
        var tile = left.querySelector('[data-bd-comp="' + id + '"], [data-bd-wire="' + id + '"]');
        if (!tile) { return; }
        tile.scrollIntoView({ block: 'nearest' });
        tile.classList.remove('is-fresh');
        void tile.offsetWidth;
        tile.classList.add('is-fresh');
        setTimeout(function () { tile.classList.remove('is-fresh'); }, 2400);
        if (REDUCED || !el.isConnected) { return; }
        var fr = frameBox();
        var a = el.getBoundingClientRect();
        var b = tile.getBoundingClientRect();
        var x0 = fr.left + a.left * fr.k;
        var y0 = fr.top + Math.max(0, a.top) * fr.k;
        var w0 = a.width * fr.k;
        var h = Math.min(a.height * fr.k, 360);
        var g = document.createElement('div');
        g.className = 'nst-bd-fly';
        g.style.cssText = 'left:' + x0 + 'px;top:' + y0 + 'px;width:' + w0 + 'px;height:' + h + 'px';
        document.body.appendChild(g);
        var dx = b.left - x0;
        var dy = b.top - y0;
        g.animate([
            { transform: 'translate(0,0) scale(1,1)', opacity: 0.95 },
            { transform: 'translate(' + dx * 0.7 + 'px,' + (dy * 0.7 - 60) + 'px) scale(' + Math.max(0.2, b.width / w0) + ',' + Math.max(0.2, b.height / h) + ')', opacity: 0.85, offset: 0.7 },
            { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + b.width / w0 + ',' + b.height / h + ')', opacity: 0.2 }
        ], { duration: 700, easing: 'cubic-bezier(.45,0,.2,1)' }).onfinish = function () { g.remove(); };
    }

    /* ------------------------------------------------------------------
     * What is selected: its details under the tree, its code in the dock.
     * ---------------------------------------------------------------- */

    var NICE = { h1: __('Heading', 'nibwp'), h2: __('Heading', 'nibwp'), h3: __('Heading', 'nibwp'), h4: __('Heading', 'nibwp'), h5: __('Heading', 'nibwp'), h6: __('Heading', 'nibwp'), p: __('Text', 'nibwp'), img: __('Image', 'nibwp'), picture: __('Image', 'nibwp'), a: __('Link', 'nibwp'), button: __('Button', 'nibwp'), ul: __('List', 'nibwp'), ol: __('List', 'nibwp'), li: __('Item', 'nibwp'), section: __('Section', 'nibwp'), header: __('Header', 'nibwp'), footer: __('Footer', 'nibwp'), nav: __('Menu', 'nibwp'), form: __('Form', 'nibwp'), figure: __('Figure', 'nibwp'), main: __('Main', 'nibwp'), article: __('Article', 'nibwp'), aside: __('Aside', 'nibwp'), svg: __('Icon', 'nibwp'), video: __('Video', 'nibwp'), iframe: __('Embed', 'nibwp'), span: __('Span', 'nibwp'), div: __('Div', 'nibwp') };
    // A name people read: from its class (the part after "__", or without a
    // short prefix), else what it is.
    // The site's own class prefix (tdr-, acme-): a short first word most classes
    // on the page share. Rows hide it; a rename puts it back.
    var nsFor = null;
    var nsPrefix = '';
    function sitePrefix() {
        if (!doc) { return ''; }
        if (nsFor === doc) { return nsPrefix; }
        var count = {};
        var all = 0;
        Array.prototype.slice.call(doc.querySelectorAll('[class]'), 0, 4000).forEach(function (n) {
            var c = typeof n.className === 'string' ? n.className.trim().split(/\s+/)[0] : '';
            var m = /^([a-z]{1,4})-(?=[a-z])/.exec(c || '');
            if (c && !/^(wp-|is-|has-|nst-x)/.test(c)) { all++; }
            if (m && !/^(wp|is|has|nst)$/.test(m[1])) { count[m[0]] = (count[m[0]] || 0) + 1; }
        });
        var best = Object.keys(count).sort(function (x, y) { return count[y] - count[x]; })[0];
        nsFor = doc;
        nsPrefix = best && count[best] >= 6 && count[best] >= all * 0.2 ? best : '';
        return nsPrefix;
    }
    function nameParts(el) {
        var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(function (c) { return c && !/^(wp-|is-|has-|nst-x|align|screen-reader)/.test(c); })[0] : '';
        var base = el.id || cls || '';
        var pre = '';
        var core = base;
        var cut = base.lastIndexOf('__');
        var ns = sitePrefix();
        if (cut > -1) { pre = base.slice(0, cut + 2); core = base.slice(cut + 2); } else if (ns && base.indexOf(ns) === 0 && base.length > ns.length) { pre = ns; core = base.slice(ns.length); }
        var mod = core.indexOf('--');
        var post = mod > -1 ? core.slice(mod) : '';
        core = mod > -1 ? core.slice(0, mod) : core;
        var shown = core.replace(/[-_]+/g, ' ').trim();
        return { kind: el.id ? '#' : '.', base: base, pre: pre, post: post, shown: shown && shown.length < 40 ? shown.replace(/\b[a-z]/g, function (c) { return c.toUpperCase(); }) : '' };
    }
    // The variants on an element's main class: card card--featured → featured.
    function modsOf(el) {
        var base = nameParts(el).base;
        if (!base || el.id === base) { return []; }
        base = base.split('--')[0];
        return clsOf(el).filter(function (c) { return c.indexOf(base + '--') === 0; }).map(function (c) { return c.slice(base.length + 2); }).slice(0, 3);
    }
    function nice(el) {
        if (isFrame(el)) { return el.querySelector('.nst-x-bd-frame__tag').textContent; }
        return nameParts(el).shown || NICE[el.localName] || el.localName;
    }

    var OWN = /^(class|id|data-nst-.*|data-nibwp|data-nibwp-hide)$/;

    /* Hide on the site: visitors do not see it once saved; here it stays,
     * faded, to find and edit. data-nibwp-hide="" hides it everywhere;
     * "phone", "tablet", "desktop" (any of them, space-separated) only on
     * those screens. One Ctrl+Z step per change. */
    var SIZES = [['phone', __('Phones', 'nibwp')], ['tablet', __('Tablets', 'nibwp')], ['desktop', __('Desktop', 'nibwp')]];
    // The sizes it is hidden on (all three when everywhere), or null when shown.
    function hidOn(el) {
        if (!el || !el.hasAttribute || !el.hasAttribute('data-nibwp-hide')) { return null; }
        var v = el.getAttribute('data-nibwp-hide').trim().split(/\s+/);
        var on = SIZES.map(function (s) { return s[0]; }).filter(function (k) { return !v[0] || v.indexOf(k) > -1; });
        return on.length ? on : null;
    }
    function hidWord(hid) {
        if (!hid) { return ''; }
        var k = hid.join(' ');
        return {
            phone: __('Hidden on phones', 'nibwp'),
            tablet: __('Hidden on tablets', 'nibwp'),
            desktop: __('Hidden on desktop', 'nibwp'),
            'phone tablet': __('Hidden on phones and tablets', 'nibwp'),
            'phone desktop': __('Hidden on phones and desktop', 'nibwp'),
            'tablet desktop': __('Hidden on tablets and desktop', 'nibwp')
        }[k] || __('Hidden everywhere', 'nibwp');
    }
    function siteBtn(b, el, tipAttr) {
        var hid = hidOn(el);
        var all = !!hid && hid.length === SIZES.length;
        var pressed = all ? 'true' : hid ? 'mixed' : 'false';
        var tip = all ? __('Show on the site', 'nibwp') : hid ? sprintf(__('%s: show it everywhere', 'nibwp'), hidWord(hid)) : __('Hide on the site', 'nibwp');
        if (!b) {
            return '<button type="button" data-bd-row="sitehide" tabindex="-1" aria-pressed="' + pressed + '" data-tip="' + esc(tip) + '" aria-label="' + esc(tip) + '">' + ico(hid ? 'siteoff' : 'site', 13) + '</button>' +
                '<button type="button" data-bd-row="sitesize" tabindex="-1" aria-haspopup="menu" data-tip="' + esc(__('Hide on phones, tablets or desktop', 'nibwp')) + '" aria-label="' + esc(__('Hide on phones, tablets or desktop', 'nibwp')) + '">' + ico('down', 11) + '</button>';
        }
        if (!el) { return ''; }
        b.classList.toggle('is-on', !!hid);
        b.setAttribute('aria-pressed', pressed);
        b.setAttribute(tipAttr || 'data-tip', tip);
        b.setAttribute('aria-label', tip);
        b.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">' + I[hid ? 'siteoff' : 'site'] + '</svg>';
        return '';
    }
    // In a Structure row: the sizes it is hidden on, when not all of them.
    function hidChip(el) {
        var hid = hidOn(el);
        if (!hid || hid.length === SIZES.length) { return ''; }
        var w = hidWord(hid);
        return '<i class="nst-bd__hid" role="img" aria-label="' + esc(w) + '" data-tip="' + esc(w) + '">' + hid.map(function (k) { return ico(k, 11); }).join('') + '</i>';
    }
    // In the element's details: where it is hidden on the site, or a way to hide it; both open the sizes.
    function hidBtn(el) {
        var hid = hidOn(el);
        var tip = hid ? __('Choose where it is hidden', 'nibwp') : __('Hide on the site', 'nibwp');
        return '<button type="button" class="nst-bd__hidbtn' + (hid ? ' is-on' : '') + '" data-bd-sizes aria-haspopup="menu" data-tip="' + esc(tip) + '" aria-label="' + esc(hid ? hidWord(hid) + '. ' + tip : tip) + '">' + ico(hid ? 'siteoff' : 'site', 13) + (hid ? '<span>' + esc(hidWord(hid)) + '</span>' : '') + '</button>';
    }
    // One click: hidden everywhere, or shown again (whatever sizes it was hidden on).
    function siteHide(el) { siteHideOn(el, hidOn(el) ? null : SIZES.map(function (s) { return s[0]; })); }
    // Hidden on these sizes (all of them is everywhere, none is shown).
    function siteHideOn(el, list) {
        var keep = SIZES.map(function (s) { return s[0]; }).filter(function (k) { return list && list.indexOf(k) > -1; });
        var v = !keep.length ? null : keep.length === SIZES.length ? '' : keep.join(' ');
        var was = el.getAttribute('data-nibwp-hide');
        if (was === v) { return; }
        var sS = kit.cssPath(el);
        var set = function (x) { if (x === null) { el.removeAttribute('data-nibwp-hide'); } else { el.setAttribute('data-nibwp-hide', x); } sel = el; elBox.el = null; if (marks && marks.tools) { marks.tools.el = null; } };
        act({
            patch: function () { return v === null ? { t: 'unattr', s: sS, name: 'data-nibwp-hide' } : { t: 'attr', s: sS, name: 'data-nibwp-hide', html: v }; },
            el: el,
            label: sprintf(v === null ? __('show %s on the site', 'nibwp') : v === '' ? __('hide %s on the site', 'nibwp') : __('hide %s on some screens', 'nibwp'), nice(el)),
            redo: function () { set(v); },
            undo: function () { set(was); }
        });
        kit.toast(v === null ? __('Shown on the site again once you Save.', 'nibwp') : v === '' ? __('Hidden on the site once you Save. It stays here, faded.', 'nibwp') : sprintf(__('%s once you Save.', 'nibwp'), hidWord(hidOn(el))), 'ok');
    }
    // A size picked in the menu: Everywhere hides it on all of them or shows
    // it again; a size is added to or taken from the ones it is hidden on.
    function siteHideToggle(el, k) {
        var hid = hidOn(el) || [];
        if (k === 'all') { siteHideOn(el, hid.length === SIZES.length ? null : SIZES.map(function (s) { return s[0]; })); return; }
        siteHideOn(el, hid.indexOf(k) > -1 ? hid.filter(function (x) { return x !== k; }) : hid.concat([k]));
    }
    function attrsOf(el) {
        var own = ownAttrs(el);
        return Array.prototype.filter.call(el.attributes, function (a) { return !OWN.test(a.name) && own.indexOf(a.name) < 0; }).map(function (a) { return a.value === '' ? a.name : a.name + '="' + a.value.replace(/"/g, '&quot;') + '"'; }).join(' ');
    }
    // 'data-x="1" aria-label="Book" hidden' into pairs; names checked. HTML
    // names are lower case; an SVG keeps viewBox as viewBox (any casing typed
    // takes the element's own).
    function parseAttrs(v, el) {
        var out = {};
        var html = !el || el.namespaceURI === 'http://www.w3.org/1999/xhtml';
        var has = {};
        if (!html) { Array.prototype.forEach.call(el.attributes, function (a) { has[a.name.toLowerCase()] = a.name; }); }
        var re = /([^\s"'=<>`\/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
        var m;
        while ((m = re.exec(v))) {
            if (!/^[a-z_:][-a-z0-9_:.]{0,60}$/i.test(m[1]) || OWN.test(m[1].toLowerCase())) { continue; }
            var name = html ? m[1].toLowerCase() : has[m[1].toLowerCase()] || m[1];
            out[name] = (m[2] !== undefined ? m[2] : m[3] !== undefined ? m[3] : m[4] !== undefined ? m[4] : '').replace(/&quot;/g, '"');
        }
        return out;
    }
    // Set many attributes at once, removing the ones taken out: one step.
    function attrsEl(el, text, merge) {
        var own = ownAttrs(el);
        var before = {};
        Array.prototype.forEach.call(el.attributes, function (a) { if (!OWN.test(a.name) && own.indexOf(a.name) < 0) { before[a.name] = a.value; } });
        var after = merge ? Object.assign({}, before, parseAttrs(text, el)) : parseAttrs(text, el);
        own.forEach(function (k) { delete after[k]; });
        var sS = kit.cssPath(el);
        var apply = function (from, to) { Object.keys(from).forEach(function (k) { if (!(k in to)) { el.removeAttribute(k); } }); Object.keys(to).forEach(function (k) { el.setAttribute(k, to[k]); }); };
        act({
            patch: function () { return Object.keys(before).filter(function (k) { return !(k in after); }).map(function (k) { return { t: 'unattr', s: sS, name: k }; }).concat(Object.keys(after).filter(function (k) { return before[k] !== after[k]; }).map(function (k) { return { t: 'attr', s: sS, name: k, html: after[k] }; })); },
            el: el,
            label: sprintf(__('change the attributes of %s', 'nibwp'), nice(el)),
            redo: function () { apply(before, after); sel = el; },
            undo: function () { apply(after, before); sel = el; }
        });
    }
    /* ------------------------------------------------------------------
     * Options for each kind of element, above Class and ID: a heading's
     * level, a box's tag, a link's new tab, a field's type… Each one is a
     * step Ctrl+Z takes back, saved with the page.
     * ---------------------------------------------------------------- */

    // The one piece of text an element shows (beside an icon, inside a
    // span), or nothing when it has several.
    function textNode(el) {
        var found = [];
        var walk = function (n) {
            Array.prototype.forEach.call(n.childNodes, function (c) {
                if (found.length > 1) { return; }
                if (c.nodeType === 3 && c.nodeValue.trim()) { found.push(c); }
                else if (c.nodeType === 1 && !/^(svg|script|style|select|textarea|iframe|video|audio|canvas)$/.test(c.localName)) { walk(c); }
            });
        };
        walk(el);
        return found.length === 1 ? found[0] : null;
    }
    // Where the Text field writes: that one text node and the spaces around
    // its words, kept even while it is empty, so the icon or the span beside
    // it is never touched.
    function textSlot(el) {
        var n = textNode(el);
        if (!n) { return null; }
        var m = /^(\s*)[\s\S]*?(\s*)$/.exec(n.nodeValue);
        return { el: el, n: n, a: m[1], b: m[2] };
    }
    function slotFor(el) {
        var s = elBox.slot;
        if (!s || s.el !== el || !el.contains(s.n)) { elBox.slot = s = textSlot(el); }
        return s;
    }
    function setText(slot, v) {
        if (!slot) { return; }
        if (!slot.el.contains(slot.n)) { var n = textNode(slot.el); if (!n) { return; } slot.n = n; }
        slot.n.nodeValue = slot.a + v + slot.b;
    }
    var BOX_TAGS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav'];
    var TAG_NAMES = { div: __('Box', 'nibwp'), section: __('Section', 'nibwp'), article: __('Article', 'nibwp'), aside: __('Aside', 'nibwp'), header: __('Header', 'nibwp'), footer: __('Footer', 'nibwp'), main: __('Main', 'nibwp'), nav: __('Navigation', 'nibwp'), p: __('Paragraph', 'nibwp'), span: __('Inline text', 'nibwp'), small: __('Small print', 'nibwp'), strong: __('Bold', 'nibwp'), em: __('Italic', 'nibwp'), blockquote: __('Quote', 'nibwp'), figcaption: __('Caption', 'nibwp'), label: __('Label', 'nibwp'), ul: __('Bullets', 'nibwp'), ol: __('Numbers', 'nibwp'),
        h1: sprintf(__('Heading %d', 'nibwp'), 1), h2: sprintf(__('Heading %d', 'nibwp'), 2), h3: sprintf(__('Heading %d', 'nibwp'), 3), h4: sprintf(__('Heading %d', 'nibwp'), 4), h5: sprintf(__('Heading %d', 'nibwp'), 5), h6: sprintf(__('Heading %d', 'nibwp'), 6),
        a: __('Link', 'nibwp'), button: __('Button', 'nibwp'), li: __('List item', 'nibwp'), figure: __('Figure', 'nibwp'), form: __('Form', 'nibwp'), menu: __('Menu list', 'nibwp'), cite: __('Citation', 'nibwp'), time: __('Time', 'nibwp'), mark: __('Highlight', 'nibwp'), code: __('Code', 'nibwp'), pre: __('Preformatted', 'nibwp'), address: __('Address', 'nibwp'), details: __('Details', 'nibwp'), summary: __('Summary', 'nibwp'), dl: __('Description list', 'nibwp'), dt: __('Term', 'nibwp'), dd: __('Description', 'nibwp'), hgroup: __('Heading group', 'nibwp'), search: __('Search area', 'nibwp'), fieldset: __('Field group', 'nibwp'), legend: __('Field group title', 'nibwp'), b: __('Bold (look)', 'nibwp'), i: __('Italic (look)', 'nibwp'), q: __('Inline quote', 'nibwp'), abbr: __('Abbreviation', 'nibwp'), sup: __('Superscript', 'nibwp'), sub: __('Subscript', 'nibwp'), dialog: __('Dialog', 'nibwp') };
    var TAG_VOID = /^(img|input|br|hr|wbr|source|track|area|col|embed|meta|link|base|param|svg|path|g|circle|rect|line|polyline|polygon|ellipse|use|iframe|video|audio|canvas|picture|select|textarea|option|table|thead|tbody|tfoot|tr|td|th)$/;
    var TAG_OTHER = ['div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'figure', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button', 'ul', 'ol', 'li', 'blockquote', 'figcaption', 'label', 'small', 'strong', 'em', 'cite', 'time', 'mark', 'code', 'pre', 'address', 'details', 'summary', 'dl', 'dt', 'dd', 'hgroup', 'search', 'fieldset', 'legend', 'b', 'i', 'q', 'abbr', 'sup', 'sub', 'menu', 'dialog'];
    // The tags this element is most likely to become, first in the list.
    function tagSuggest(t) {
        if (/^h[1-6]$/.test(t)) { return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span']; }
        if (t === 'a' || t === 'button') { return ['a', 'button', 'span', 'div']; }
        if (/^(p|span|small|strong|em|b|i|figcaption|label|cite|blockquote|q|mark|code|time|abbr|sup|sub)$/.test(t)) { return TEXT_TAGS.concat(['h2', 'h3', 'cite', 'a']); }
        if (t === 'ul' || t === 'ol' || t === 'menu') { return ['ul', 'ol', 'menu', 'div']; }
        if (t === 'li') { return ['li', 'div', 'p']; }
        if (t === 'dl' || t === 'dt' || t === 'dd') { return ['dl', 'dt', 'dd', 'div']; }
        return BOX_TAGS.concat(['figure', 'form', 'ul', 'li']);
    }
    var TEXT_TAGS = ['p', 'span', 'div', 'small', 'strong', 'em', 'blockquote', 'figcaption', 'label'];
    var optDef = function (k, label, more) { return Object.assign({ k: k, label: label }, more || {}); };
    function optsFor(el) {
        var t = el.localName;
        var out = [];
        if (!TAG_VOID.test(t) && t !== 'body' && t !== 'html') { out.push(optDef('tag', __('Tag', 'nibwp'), { tags: tagSuggest(t) })); }
        if (t === 'a') { out.push(optDef('bool', __('Open in a new tab', 'nibwp'), { name: 'target', on: '_blank' }), optDef('attr', __('Tooltip', 'nibwp'), { name: 'title' })); }
        if (t === 'button') { out.push(optDef('attr', __('Type', 'nibwp'), { name: 'type', options: ['button', 'submit', 'reset'] }), optDef('bool', __('Disabled', 'nibwp'), { name: 'disabled' })); }
        if (t === 'img') { out.push(optDef('bool', __('Load when scrolled to', 'nibwp'), { name: 'loading', on: 'lazy' })); }
        if (t === 'input') { out.push(optDef('attr', __('Type', 'nibwp'), { name: 'type', options: ['text', 'email', 'tel', 'number', 'date', 'password', 'search', 'url', 'checkbox', 'radio', 'hidden'] }), optDef('attr', __('Placeholder', 'nibwp'), { name: 'placeholder' }), optDef('attr', __('Name', 'nibwp'), { name: 'name' }), optDef('bool', __('Required', 'nibwp'), { name: 'required' })); }
        if (t === 'textarea') { out.push(optDef('attr', __('Placeholder', 'nibwp'), { name: 'placeholder' }), optDef('attr', __('Name', 'nibwp'), { name: 'name' }), optDef('attr', __('Rows', 'nibwp'), { name: 'rows' }), optDef('bool', __('Required', 'nibwp'), { name: 'required' })); }
        if (t === 'select') { out.push(optDef('attr', __('Name', 'nibwp'), { name: 'name' }), optDef('bool', __('Required', 'nibwp'), { name: 'required' })); }
        if (t === 'form') { out.push(optDef('attr', __('Sends to', 'nibwp'), { name: 'action' }), optDef('attr', __('Method', 'nibwp'), { name: 'method', options: ['get', 'post'] })); }
        if (t === 'label') { out.push(optDef('attr', __('For the field (ID)', 'nibwp'), { name: 'for' })); }
        if (t === 'iframe') { out.push(optDef('attr', __('Address', 'nibwp'), { name: 'src' }), optDef('attr', __('Title', 'nibwp'), { name: 'title' }), optDef('bool', __('Load when scrolled to', 'nibwp'), { name: 'loading', on: 'lazy' }), optDef('bool', __('Allow full screen', 'nibwp'), { name: 'allowfullscreen' })); }
        if (t === 'video' || t === 'audio') { out.push(optDef('attr', __('File', 'nibwp'), { name: 'src' }), optDef('bool', __('Controls', 'nibwp'), { name: 'controls' }), optDef('bool', __('Autoplay', 'nibwp'), { name: 'autoplay' }), optDef('bool', __('Muted', 'nibwp'), { name: 'muted' }), optDef('bool', __('Loop', 'nibwp'), { name: 'loop' })); }
        if (t === 'details') { out.push(optDef('bool', __('Open at start', 'nibwp'), { name: 'open' })); }
        if (t === 'blockquote') { out.push(optDef('attr', __('Source', 'nibwp'), { name: 'cite' })); }
        if (t === 'time') { out.push(optDef('attr', __('Date', 'nibwp'), { name: 'datetime' })); }
        return out;
    }
    function ownAttrs(el) {
        var base = { a: ['href'], img: ['src', 'alt'] }[el.localName] || [];
        return base.concat(optsFor(el).filter(function (x) { return x.name; }).map(function (x) { return x.name; }));
    }
    function optionsHtml(el) {
        var t = el.localName;
        return optsFor(el).map(function (x) {
            if (x.k === 'tag') {
                return '<div class="nst-bd__field nst-bd__combo"><label for="nst-bd-tagin">' + esc(x.label) + '</label>' +
                    '<input type="text" id="nst-bd-tagin" class="nst-bd__combo-in" data-bd-tagcombo value="' + esc(t) + '" placeholder="' + esc(__('Enter tag', 'nibwp')) + '" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="nst-bd-taglist">' +
                    '<ul class="nst-bd__combo-list" id="nst-bd-taglist" role="listbox" aria-label="' + esc(__('Tags', 'nibwp')) + '" hidden></ul></div>';
            }
            if (x.k === 'bool') {
                var on = el.hasAttribute(x.name) && (!x.on || el.getAttribute(x.name) === x.on);
                return '<label class="nst-bd__check"><input type="checkbox" data-bd-bool="' + x.name + '"' + (x.on ? ' data-on="' + esc(x.on) + '"' : '') + (on ? ' checked' : '') + '><span>' + esc(x.label) + '</span></label>';
            }
            var v = el.getAttribute(x.name) || '';
            if (x.options) {
                // "— (none)" takes the attribute off, so a choice can always be undone here.
                return '<label class="nst-bd__field"><span>' + esc(x.label) + '</span><select data-bd-f="' + x.name + '"><option value=""' + (v === '' ? ' selected' : '') + '>' + esc(__('— (none)', 'nibwp')) + '</option>' + (v && x.options.indexOf(v) < 0 ? '<option value="' + esc(v) + '" selected>' + esc(v) + '</option>' : '') + x.options.map(function (g) { return '<option' + (g === v ? ' selected' : '') + '>' + g + '</option>'; }).join('') + '</select></label>';
            }
            return '<label class="nst-bd__field"><span>' + esc(x.label) + '</span><input type="text" data-bd-f="' + x.name + '" value="' + esc(v) + '" spellcheck="false"></label>';
        }).join('');
    }
    // Tags an element cannot become without losing what is inside it: empty
    // ones, ones that hold only raw text, and the page's own frame.
    var TAG_NEVER = /^(img|br|hr|input|meta|link|source|track|wbr|area|col|embed|param|base|template|textarea|script|style|title|html|head|body|iframe|frame|frameset|noscript|noembed|noframes|xmp|plaintext|image|keygen|svg|math)$/;
    // A tag the browser knows, or a custom element (it has a "-").
    function tagOk(t) {
        if (!/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/.test(t) || TAG_NEVER.test(t)) { return false; }
        if (t.indexOf('-') > 0) { return !/^(annotation-xml|color-profile|font-face(-src|-uri|-format|-name)?|missing-glyph)$/.test(t); }
        if (TAG_NAMES[t] || TAG_OTHER.indexOf(t) > -1) { return true; }
        var w = doc && doc.defaultView;
        try { return !!w && !(doc.createElement(t) instanceof w.HTMLUnknownElement); } catch (x) { return false; }
    }
    // Another tag, same element: its attributes and everything inside kept.
    function retag(el, tag) {
        if (!tagOk(tag) || el.localName === tag) { return; }
        var n = doc.createElement(tag);
        Array.prototype.forEach.call(el.attributes, function (a) { if (tag !== 'a' && el.localName === 'a' && /^(href|target|rel|download|hreflang)$/.test(a.name)) { return; } n.setAttribute(a.name, a.value); });
        if (tag === 'a' && !n.hasAttribute('href')) { n.setAttribute('href', '#'); }
        if (tag === 'button' && !n.hasAttribute('type')) { n.setAttribute('type', 'button'); }
        Array.prototype.forEach.call(el.childNodes, function (c) { n.appendChild(c.cloneNode(true)); });
        n.classList.remove('nst-x-bd-new');
        elBox.el = null;
        replaceEl(el, cleanHtml(n));
    }
    function boolAttr(el, name, on, val) {
        var before = el.getAttribute(name);
        var after = on ? (val || '') : null;
        var sS = kit.cssPath(el);
        var set = function (v) { if (v === null) { el.removeAttribute(name); } else { el.setAttribute(name, v); } sel = el; elBox.el = null; };
        act({
            patch: function () { return after === null ? { t: 'unattr', s: sS, name: name } : { t: 'attr', s: sS, name: name, html: after }; },
            el: el,
            label: sprintf(on ? __('turn on %1$s for %2$s', 'nibwp') : __('turn off %1$s for %2$s', 'nibwp'), name, nice(el)),
            redo: function () { set(after); },
            undo: function () { set(before); }
        });
    }
    elBox.addEventListener('change', function (e) {
        var el = elBox.el;
        if (!el) { return; }
        if (e.target.matches('[data-bd-bool]')) { boolAttr(el, e.target.dataset.bdBool, e.target.checked, e.target.dataset.on); }
    });
    function tagList(inp, all) {
        var el = elBox.el;
        var list = inp.parentNode.querySelector('.nst-bd__combo-list');
        if (!el || !list) { return; }
        var q = all ? '' : inp.value.trim().toLowerCase();
        var first = tagSuggest(el.localName);
        var rest = TAG_OTHER.filter(function (g) { return first.indexOf(g) < 0; });
        var hit = function (g) { return !q || g.indexOf(q) === 0 || (TAG_NAMES[g] || '').toLowerCase().indexOf(q) > -1; };
        var a = first.filter(hit);
        var b = rest.filter(hit);
        if (q) {
            // The tag typed first, then tags that start with it, then the names that hold it.
            var rank = function (g) { return g === q ? 0 : g.indexOf(q) === 0 ? 1 : 2; };
            var pool = a.concat(b);
            a = pool.filter(function (g) { return rank(g) < 2; }).sort(function (x, y) { return rank(x) - rank(y); });
            b = pool.filter(function (g) { return rank(g) === 2; });
        }
        var own = q && a.concat(b).indexOf(q) < 0 && tagOk(q) ? [q] : [];
        var row = function (g, mine) { return '<li role="option" id="nst-bd-tag-' + g + '" data-tag="' + esc(g) + '"' + (g === el.localName ? ' class="is-now"' : '') + '><b>' + esc(g) + '</b><small>' + esc(mine ? __('Use this tag', 'nibwp') : TAG_NAMES[g] || '') + '</small></li>'; };
        list.innerHTML = own.map(function (g) { return row(g, true); }).join('') + a.map(function (g) { return row(g); }).join('') + (a.length && b.length ? '<li class="nst-bd__combo-sep" role="presentation"></li>' : '') + b.map(function (g) { return row(g); }).join('');
        var items = list.querySelectorAll('[data-tag]');
        list.hidden = !items.length;
        inp.setAttribute('aria-expanded', String(!list.hidden));
        var pick = (all && list.querySelector('.is-now')) || items[0];
        tagMark(inp, pick);
    }
    function tagMark(inp, li) {
        var list = inp.parentNode.querySelector('.nst-bd__combo-list');
        list.querySelectorAll('[aria-selected]').forEach(function (x) { x.removeAttribute('aria-selected'); });
        if (!li) { inp.removeAttribute('aria-activedescendant'); return; }
        li.setAttribute('aria-selected', 'true');
        inp.setAttribute('aria-activedescendant', li.id);
        li.scrollIntoView({ block: 'nearest' });
    }
    function tagClose(inp, take) {
        var list = inp.parentNode.querySelector('.nst-bd__combo-list');
        var el = elBox.el;
        var cur = list.querySelector('[aria-selected="true"]');
        var typed = inp.value.trim().toLowerCase();
        list.hidden = true;
        inp.setAttribute('aria-expanded', 'false');
        inp.removeAttribute('aria-activedescendant');
        if (!el) { return; }
        // Leaving the field takes only a whole tag, never a half-typed one.
        var tag = take === 'pick' && cur ? cur.dataset.tag : take === 'typed' && tagOk(typed) ? typed : '';
        if (tag && tag !== el.localName && tagOk(tag)) { retag(el, tag); } else { inp.value = el.localName; }
    }
    // After a pick the fields are drawn again: the new Tag field takes the focus, its list shut.
    var tagQuiet = false;
    function tagRefocus() {
        var n = elBox.querySelector('[data-bd-tagcombo]');
        if (!n || document.activeElement === n) { return; }
        tagQuiet = true;
        n.focus();
        tagQuiet = false;
        n.select();
    }
    elBox.addEventListener('focusin', function (e) { if (e.target.matches('[data-bd-tagcombo]') && !tagQuiet) { e.target.select(); tagList(e.target, true); } });
    // A click on the field while it already has the focus (after a pick) offers the list again, ready to type over.
    elBox.addEventListener('click', function (e) {
        if (!e.target.matches || !e.target.matches('[data-bd-tagcombo]')) { return; }
        var l = e.target.parentNode.querySelector('.nst-bd__combo-list');
        if (l && l.hidden) { var t = e.target; t.select(); tagList(t, true); setTimeout(function () { t.select(); }, 0); }
    });
    elBox.addEventListener('input', function (e) { if (e.target.matches('[data-bd-tagcombo]')) { tagList(e.target, false); } });
    elBox.addEventListener('keydown', function (e) {
        var inp = e.target.closest && e.target.closest('[data-bd-tagcombo]');
        if (!inp) { return; }
        // Nothing typed here yet: Ctrl+Z / Ctrl+Y are the Builder's undo and redo (a tag just picked comes back).
        var mod = e.ctrlKey || e.metaKey;
        if (mod && /^[zy]$/i.test(e.key) && elBox.el && inp.value.trim().toLowerCase() === elBox.el.localName) {
            e.preventDefault();
            e.stopPropagation();
            if (e.key.toLowerCase() === 'y' || e.shiftKey) { redo(); } else { undo(); }
            return;
        }
        var list = inp.parentNode.querySelector('.nst-bd__combo-list');
        var items = Array.prototype.slice.call(list.querySelectorAll('[data-tag]'));
        var at = items.indexOf(list.querySelector('[aria-selected="true"]'));
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (list.hidden) { tagList(inp, false); return; }
            tagMark(inp, items[(at + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length]);
        } else if (e.key === 'Enter' || (e.key === 'Tab' && !list.hidden)) {
            if (e.key === 'Enter') { e.preventDefault(); }
            tagClose(inp, 'pick');
            tagRefocus();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            inp.value = elBox.el ? elBox.el.localName : inp.value;
            tagClose(inp, '');
            inp.select();
        }
    });
    elBox.addEventListener('mousedown', function (e) {
        var li = e.target.closest('.nst-bd__combo-list [data-tag]');
        if (!li) { return; }
        e.preventDefault();
        var inp = li.closest('.nst-bd__combo').querySelector('[data-bd-tagcombo]');
        tagMark(inp, li);
        tagClose(inp, 'pick');
        tagRefocus();
    });
    elBox.addEventListener('focusout', function (e) {
        if (!e.target.matches('[data-bd-tagcombo]')) { return; }
        var inp = e.target;
        setTimeout(function () { if (document.activeElement !== inp && inp.isConnected && !inp.parentNode.querySelector('.nst-bd__combo-list').hidden) { tagClose(inp, 'typed'); } }, 0);
    });
    var FIELDS = { attrs: __('Attributes', 'nibwp'), text: __('Text', 'nibwp'), href: __('Link', 'nibwp'), src: __('Image', 'nibwp'), alt: __('Alt text', 'nibwp'), 'class': __('Class', 'nibwp'), id: 'ID' };
    function fields() {
        var el = sel && sel.isConnected && !blocked && !isFrame(sel) ? sel : null;
        elBox.hidden = !el;
        if (!el) { elBox.el = null; return; }
        if (elBox.el === el) { return; }
        elBox.el = el;
        var t = el.localName;
        var keys = [];
        var slot = t !== 'img' ? slotFor(el) : null;
        if (slot) { keys.push('text'); }
        if (t === 'a') { keys.push('href'); }
        if (t === 'img') { keys.push('src', 'alt'); }
        if (t === 'svg') { keys.push('icon'); }
        keys.push('class', 'id', 'attrs');
        var ar = area(el);
        var r = el.getBoundingClientRect();
        elBox.querySelector('.nst-bd__elbox-h').innerHTML = '<span class="nst-bd__k" data-k="' + kindOf(el) + '">' + ico(kindOf(el), 14) + '</span><b>' + esc(nice(el)) + '</b><code>&lt;' + esc(t) + '&gt;</code><small>' + wxh(Math.round(r.width), Math.round(r.height)) + '</small>' +
            (ar ? '<i class="nst-bd__site" data-tip="' + esc(__('Shared by every page', 'nibwp')) + '">' + esc(ar === 'header' ? __('Site header', 'nibwp') : __('Site footer', 'nibwp')) + '</i>' : '') +
            hidBtn(el);
        elBox.querySelector('.nst-bd__fields').innerHTML = optionsHtml(el) + keys.map(function (k) {
            if (k === 'icon') { return '<div class="nst-bd__field"><span>' + esc(__('Icon', 'nibwp')) + '</span><button type="button" class="nst-bd__media" data-bd-media="icon">' + ico('image', 15) + '<span>' + esc(__('Choose from Media', 'nibwp')) + '</span></button></div>'; }
            if (k === 'src') { return '<label class="nst-bd__field"><span>' + esc(FIELDS[k]) + '</span><span class="nst-bd__src"><button type="button" class="nst-bd__media is-thumb" data-bd-media="src" data-tip="' + esc(__('Choose from Media', 'nibwp')) + '" aria-label="' + esc(__('Choose from Media', 'nibwp')) + '">' + (el.getAttribute('src') ? '<img src="' + esc(el.getAttribute('src')) + '" alt="">' : ico('image', 16)) + '</button><input type="text" data-bd-f="src" value="' + esc(el.getAttribute('src') || '') + '" spellcheck="false"></span></label>'; }
            if (k === 'attrs') { return '<label class="nst-bd__field is-attrs"><span>' + esc(FIELDS[k]) + '<small>' + esc(__('name="value", as many as you like', 'nibwp')) + '</small></span><textarea rows="2" data-bd-f="attrs" spellcheck="false" placeholder="' + esc('data-tab="1" aria-label="Book now" target="_blank"') + '">' + esc(attrsOf(el)) + '</textarea></label>'; }
            var v = k === 'text' ? slot.n.nodeValue.trim() : k === 'class' ? (el.getAttribute('class') || '').split(/\s+/).filter(function (c) { return c && !/^nst-x/.test(c); }).join(' ') : (el.getAttribute(k) || '');
            return '<label class="nst-bd__field"><span>' + esc(FIELDS[k]) + '</span>' + (k === 'text' ? '<textarea rows="2" data-bd-f="text">' + esc(v) + '</textarea>' : '<input type="text" data-bd-f="' + k + '" value="' + esc(v) + '" spellcheck="false">') + '</label>';
        }).join('');
    }
    // The Builder's own marks on an element (hidden here, just added): they stay
    // on the canvas whatever the Class field says, and are never saved.
    function marksOf(el) { return (el.getAttribute('class') || '').split(/\s+/).filter(function (c) { return /^nst-x/.test(c); }); }
    function putClass(el, v) {
        var c = (v.split(/\s+/).filter(function (x) { return x && !/^nst-x/.test(x); }).concat(marksOf(el))).join(' ');
        if (c) { el.setAttribute('class', c); } else { el.removeAttribute('class'); }
    }
    // What a field holds, shown on the element as you type (not a step yet).
    // An empty field takes its attribute off; alt="" stays, it marks a decorative image.
    function setField(el, k, v, had) {
        if (k === 'text') { setText(slotFor(el), v); }
        else if (k === 'class') { putClass(el, v); }
        else if (v === '' && k !== 'alt' && !had) { el.removeAttribute(k); }
        else { el.setAttribute(k, v); }
        codeFor = null;
        paint();
    }
    function textEl(el, text, before) {
        var sS = kit.cssPath(el);
        var slot = slotFor(el);
        act({
            patch: function () { return { t: 'replace', s: sS, html: cleanHtml(el) }; },
            el: el,
            label: sprintf(__('edit the text of %s', 'nibwp'), nice(el)),
            redo: function () { setText(slot, text); sel = el; },
            undo: function () { setText(slot, before); sel = el; }
        });
    }
    // The class from the Class field or a chip: the saved value is the
    // classes alone, the canvas keeps the Builder's marks.
    function classEl(el, value) {
        var before = (el.getAttribute('class') || '').split(/\s+/).filter(function (c) { return c && !/^nst-x/.test(c); }).join(' ');
        var after = value.split(/\s+/).filter(function (c, i, a) { return c && !/^nst-x/.test(c) && a.indexOf(c) === i; }).join(' ');
        if (before === after) { return; }
        var sS = kit.cssPath(el);
        act({
            patch: function () { return after ? { t: 'attr', s: sS, name: 'class', html: after } : { t: 'unattr', s: sS, name: 'class' }; },
            el: el,
            label: sprintf(__('set %1$s on %2$s', 'nibwp'), 'class', label(el, true)),
            redo: function () { putClass(el, after); sel = el; },
            undo: function () { putClass(el, before); sel = el; }
        });
    }
    // One attribute from a field: an empty value takes it off (alt="" stays).
    function fieldAttr(el, name, value) {
        var before = el.getAttribute(name);
        var after = value === '' && name !== 'alt' ? null : value;
        if (before === after) { return; }
        var sS = kit.cssPath(el);
        var put = function (v) { if (v === null) { el.removeAttribute(name); } else { el.setAttribute(name, v); } sel = el; };
        act({
            patch: function () { return after === null ? { t: 'unattr', s: sS, name: name } : { t: 'attr', s: sS, name: name, html: after }; },
            el: el,
            label: sprintf(after === null ? __('remove %1$s from %2$s', 'nibwp') : __('set %1$s on %2$s', 'nibwp'), name, label(el, true)),
            redo: function () { put(after); },
            undo: function () { put(before); }
        });
    }
    // A new picture for an image, in one step: its srcset and sizes go (they
    // would keep showing the old one), its width and height follow the new
    // shape, and the <source>s of a <picture> around it go too.
    function swapImg(el, f, w, h) {
        var pic = el.parentElement && el.parentElement.localName === 'picture' ? el.parentElement : null;
        var srcs = pic ? Array.prototype.filter.call(pic.children, function (c) { return c.localName === 'source'; }).map(function (s) { return { n: s, next: s.nextSibling, path: kit.cssPath(s) }; }) : [];
        var names = ['src', 'alt', 'srcset', 'sizes', 'width', 'height'];
        var before = {};
        names.forEach(function (k) { before[k] = el.getAttribute(k); });
        var after = Object.assign({}, before, { src: f.url, srcset: null, sizes: null });
        if (!before.alt && f.alt) { after.alt = f.alt; }
        var ow = parseFloat(before.width);
        var oh = parseFloat(before.height);
        if (w > 0 && h > 0 && ow > 0) { after.width = String(Math.round(ow)); after.height = String(Math.round(ow * h / w)); }
        else if (w > 0 && h > 0 && oh > 0) { after.height = String(Math.round(oh)); after.width = String(Math.round(oh * w / h)); }
        if (!srcs.length && names.every(function (k) { return before[k] === after[k]; })) { return; }
        var sS = kit.cssPath(el);
        var put = function (v) { names.forEach(function (k) { if (v[k] === null) { el.removeAttribute(k); } else { el.setAttribute(k, v[k]); } }); sel = el; elBox.el = null; };
        act({
            patch: function () {
                return srcs.slice().reverse().map(function (s) { return { t: 'remove', s: s.path }; }).concat(names.filter(function (k) { return before[k] !== after[k]; }).map(function (k) { return after[k] === null ? { t: 'unattr', s: sS, name: k } : { t: 'attr', s: sS, name: k, html: after[k] }; }));
            },
            el: el,
            label: sprintf(__('change the image of %s', 'nibwp'), nice(el)),
            redo: function () { srcs.forEach(function (s) { s.n.remove(); }); put(after); },
            undo: function () { srcs.slice().reverse().forEach(function (s) { pic.insertBefore(s.n, s.next && s.next.parentNode === pic ? s.next : el); }); put(before); }
        });
    }
    function imgSize(f, done) {
        if (f.width > 0 && f.height > 0) { done(f.width, f.height); return; }
        var im = new Image();
        var t = setTimeout(function () { im.onload = im.onerror = null; done(0, 0); }, 4000);
        im.onload = function () { clearTimeout(t); done(im.naturalWidth, im.naturalHeight); };
        im.onerror = function () { clearTimeout(t); done(0, 0); };
        im.src = f.url;
    }
    // An SVG file as an icon: read as XML, only drawing elements kept, no
    // handlers or outside links, and the old icon's class, role, aria-* and
    // size carried over so it looks and reads the same.
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var SVG_OK = /^(svg|g|path|circle|ellipse|line|polyline|polygon|rect|defs|clipPath|mask|linearGradient|radialGradient|stop|symbol|use|title|desc|pattern|marker|text|tspan|filter|fe(?!Image)[A-Z][A-Za-z]*)$/;
    function cleanSvg(n) {
        Array.prototype.slice.call(n.children).forEach(function (c) {
            if (c.namespaceURI !== SVG_NS || !SVG_OK.test(c.localName)) { c.remove(); return; }
            cleanSvg(c);
        });
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
            var nm = a.name.toLowerCase();
            if (/^on/.test(nm) || ((/(^|:)href$/.test(nm) || nm === 'src') && !/^\s*#/.test(a.value)) || /(javascript|vbscript)\s*:|data\s*:\s*text\/html/i.test(a.value)) { n.removeAttribute(a.name); }
        });
    }
    function iconFrom(old, text) {
        var parse = function (t) { var x = new DOMParser().parseFromString(t, 'image/svg+xml'); return x.getElementsByTagName('parsererror').length ? null : x.documentElement; };
        var svg = parse(text);
        // A file written without its namespaces still reads as SVG.
        if (!svg || svg.namespaceURI !== SVG_NS) { svg = parse(text.replace(/<svg\b(?![^>]*\sxmlns=)/, '<svg xmlns="' + SVG_NS + '"').replace(/<svg\b(?![^>]*\sxmlns:xlink=)/, '<svg xmlns:xlink="http://www.w3.org/1999/xlink"')); }
        if (!svg || svg.localName !== 'svg' || svg.namespaceURI !== SVG_NS) { return ''; }
        cleanSvg(svg);
        var n = doc.importNode(svg, true);
        // Its own size becomes its viewBox, so the old icon's size scales it rather than crops it.
        var w = parseFloat(n.getAttribute('width'));
        var h = parseFloat(n.getAttribute('height'));
        if (!n.hasAttribute('viewBox') && w > 0 && h > 0) { n.setAttribute('viewBox', '0 0 ' + w + ' ' + h); }
        Array.prototype.forEach.call(old.attributes, function (a) { if (/^(class|role|width|height|aria-.+)$/.test(a.name)) { n.setAttribute(a.name, a.value); } });
        return cleanHtml(n);
    }
    function iconImg(old, f) {
        var hidden = old.getAttribute('aria-hidden') === 'true';
        var alt = hidden ? '' : old.getAttribute('aria-label') || f.alt || '';
        var cls = (old.getAttribute('class') || '').split(/\s+/).filter(function (c) { return c && !/^nst-x/.test(c); }).join(' ');
        return '<img src="' + esc(f.url) + '" alt="' + esc(alt) + '"' + (cls ? ' class="' + esc(cls) + '"' : '') + ' width="' + esc(old.getAttribute('width') || '40') + '" height="' + esc(old.getAttribute('height') || '40') + '">';
    }
    elBox.addEventListener('click', function (e) {
        var mb = e.target.closest('[data-bd-media]');
        var el = elBox.el;
        if (!mb || !el || !kit.pickMedia) { return; }
        e.preventDefault();
        kit.pickMedia(function (f) {
            if (!f || !f.url || !el.isConnected) { return; }
            if (mb.dataset.bdMedia === 'src') {
                imgSize(f, function (w, h) { if (el.isConnected) { swapImg(el, f, w, h); } });
                return;
            }
            // An icon: an SVG file goes in as the SVG itself, anything else as an image.
            if (/svg/.test(f.mime || '') || /\.svg(\?|$)/i.test(f.url)) {
                var failed = function () { kit.toast(__('Could not load that icon. Try another file.', 'nibwp'), 'bad'); };
                fetch(f.url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.text(); }).then(function (t) {
                    var svg = el.isConnected ? iconFrom(el, t) : '';
                    if (svg) { replaceEl(el, svg); } else if (el.isConnected) { failed(); }
                }).catch(failed);
            } else {
                replaceEl(el, iconImg(el, f));
            }
        }, mb.dataset.bdMedia === 'icon' ? __('Use as the icon', 'nibwp') : __('Use this image', 'nibwp'));
    });
    elBox.addEventListener('click', function (e) {
        var hb = e.target.closest('[data-bd-sizes]');
        if (!hb || !elBox.el) { return; }
        var r = hb.getBoundingClientRect();
        openCtx(elBox.el, r.left, r.bottom + 4, hb, true);
    });
    // A field remembers what it held when you came in, and whether its attribute was there at all.
    function hadAttr(el, k) { return k !== 'text' && k !== 'class' && k !== 'attrs' && el && el.hasAttribute(k) ? 'y' : ''; }
    elBox.addEventListener('focusin', function (e) { var i = e.target.closest('[data-bd-f]'); if (i) { i.dataset.was = i.value; i.dataset.had = hadAttr(elBox.el, i.dataset.bdF); } });
    elBox.addEventListener('input', function (e) { var i = e.target.closest('[data-bd-f]'); if (i && elBox.el && i.dataset.bdF !== 'attrs') { setField(elBox.el, i.dataset.bdF, i.value); } });
    // A field's edit becomes one step: when it changes, or on Enter.
    function fieldCommit(i) {
        var el = elBox.el;
        if (!i || !el || i.value === i.dataset.was) { return; }
        var k = i.dataset.bdF;
        var was = i.dataset.was || '';
        i.dataset.was = i.value;
        if (k === 'attrs') { attrsEl(el, i.value); return; }
        setField(el, k, was, i.dataset.had === 'y');
        if (k === 'text') { textEl(el, i.value, was); }
        else if (k === 'class') { classEl(el, i.value); }
        else if (k === 'src' && el.localName === 'img' && i.value) { swapImg(el, { url: i.value }, 0, 0); }
        else { fieldAttr(el, k, i.value); }
        i.dataset.had = hadAttr(el, k);
    }
    elBox.addEventListener('change', function (e) { fieldCommit(e.target.closest('[data-bd-f]')); });
    elBox.addEventListener('keydown', function (e) {
        var i = e.target.closest && e.target.closest('[data-bd-f]');
        if (!i || e.isComposing || e.keyCode === 229) { return; }
        // Enter keeps you in the field, its text selected, so the next key is not a canvas shortcut.
        if (e.key === 'Enter' && (i.matches('input') || (i.dataset.bdF === 'attrs' && !e.shiftKey))) {
            e.preventDefault();
            var k = i.dataset.bdF;
            fieldCommit(i);
            var n = i.isConnected ? i : elBox.querySelector('[data-bd-f="' + k + '"]');
            if (n) { n.focus(); if (n.select) { n.select(); } }
        }
        // Esc puts back what the field held; pressed again it goes to Structure.
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            var was = i.dataset.was || '';
            if (i.value === was) { if (treeEl.offsetParent) { treeEl.focus(); } return; }
            i.value = was;
            if (i.dataset.bdF !== 'attrs' && elBox.el) { setField(elBox.el, i.dataset.bdF, was, i.dataset.had === 'y'); }
            if (i.select) { i.select(); }
        }
    });

    function inspect() {
        var has = !!(sel && sel.isConnected && !blocked);
        var fr = has && isFrame(sel);
        fields();
        multiNote();
        if (has && !fr && codeFor !== sel) { fillCode(); }
        if (!has && pageCode && doc && codeFor !== doc.body) { fillCode(); }
        dock.hidden = !on || blocked || fr || (!has && !pageCode) || (!viewOn.code && !pageCode && !dockOpen);
        dock.classList.toggle('is-min', !dockOpen);
        var codeOn = !dock.hidden && dockOpen;
        bar.querySelector('[data-bd="dock"]').setAttribute('aria-pressed', String(codeOn));
        root.classList.toggle('has-bd-dock', !dock.hidden);
        if (marks && marks.tools) { marks.tools.querySelector('[data-bd-t="code"]').classList.toggle('is-on', codeOn && codeFor === marks.tools.el); }
    }

    // What the hover toolbar on the page does.
    function toolDo(k, el) {
        if (!el || !el.isConnected) { return; }
        if (k === 'ask') { select(el, true); openPop(el, 'comment'); }
        else if (k === 'dup') { if (el === sel && picked().length > 1) { duplicatePicked(); } else { duplicateEl(el); } }
        else if (k === 'copy') { copyEl(el); }
        else if (k === 'cut') { cutEl(el); }
        else if (k === 'paste') { pasteEl(el); }
        else if (k === 'copystyle') { copyStyle(el); }
        else if (k === 'pastestyle') { pasteStyle(el); }
        else if (k === 'del') { marks.tools.classList.add('is-confirm'); marks.tools.querySelector('[data-bd-t="no"]').focus(); }
        else if (k === 'yes') { marks.tools.classList.remove('is-confirm'); if (el === sel && picked().length > 1) { removePicked(); } else { removeEl(el); } }
        else if (k === 'no') { marks.tools.classList.remove('is-confirm'); }
        else if (k === 'up') { if (treeUp(el) && treeUp(el) !== doc.body) { select(treeUp(el), true); } }
        else if (k === 'sitehide') { siteHide(el); }
        else if (k === 'sitesize') {
            var sb = marks.tools.querySelector('[data-bd-t="sitesize"]').getBoundingClientRect();
            var fb = kit.frame().getBoundingClientRect();
            openCtx(el, fb.left + sb.left, fb.top + sb.bottom + 6, null, true);
        }
        else if (k === 'hide' && el === sel && picked().length > 1) { hidePicked(); }
        else if (k === 'hide') { el.classList.add('nst-x-bd-off'); hover = null; if (sel === el) { sel = null; } render(); paint(); inspect(); kit.toast(__('Hidden in the Builder only. Show it again from Structure.', 'nibwp')); }
        else if (k === 'code') {
            var same = sel === el && !dock.hidden && dockOpen;
            if (sel !== el) { select(el, true); }
            setDock(!same);
        }
    }
    /* ------------------------------------------------------------------
     * The code dock: three editors with line numbers and colours. CSS
     * applies as you type; HTML applies and JS runs with Ctrl+Enter.
     * ---------------------------------------------------------------- */

    var VOID = /^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/i;
    var BLOCK_TAGS = /^(address|article|aside|blockquote|body|caption|col|colgroup|dd|details|dialog|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|head|header|hgroup|hr|html|li|link|main|menu|meta|nav|noscript|ol|optgroup|option|p|pre|script|search|section|select|source|style|summary|table|tbody|td|template|tfoot|th|thead|title|tr|track|ul)$/;
    var RAW_TAGS = /^(script|style|textarea|pre|title|xmp)$/;
    /* The HTML laid out to read: a new line only between block-level tags
     * (and around elements holding them). Text and inline tags stay exactly
     * as they are, quoted attribute values are never split, and the inside
     * of pre, textarea, script and style is left untouched. */
    function prettyHtml(html) {
        var re = /<!--[\s\S]*?(?:-->|$)|<![^>]*>|<\/?([a-zA-Z][^\s\/>]*)(?:"[^"]*"|'[^']*'|[^'">])*>/g;
        var root = { name: '', open: '', close: '', kids: [] };
        var stack = [root];
        var at = 0;
        var m;
        var leaf = function (s, name) { if (s) { stack[stack.length - 1].kids.push({ s: s, name: name || '' }); } };
        while ((m = re.exec(html))) {
            leaf(html.slice(at, m.index));
            at = re.lastIndex;
            var s = m[0];
            var name = (m[1] || '').toLowerCase();
            if (!name) { leaf(s); continue; }
            if (s.charAt(1) === '/') {
                var i = stack.length - 1;
                while (i > 0 && stack[i].name !== name) { i--; }
                if (i > 0) { stack[i].close = s; stack.length = i; } else { leaf(s); }
                continue;
            }
            if (VOID.test(name) || /\/>$/.test(s)) { leaf(s, name); continue; }
            if (RAW_TAGS.test(name)) {
                var end = new RegExp('</' + name + '\\s*>', 'gi');
                end.lastIndex = at;
                var e = end.exec(html);
                var stop = e ? end.lastIndex : html.length;
                leaf(s + html.slice(at, stop), name);
                at = re.lastIndex = stop;
                continue;
            }
            var n = { name: name, open: s, close: '', kids: [] };
            stack[stack.length - 1].kids.push(n);
            stack.push(n);
        }
        leaf(html.slice(at));
        var flat = function (n) { return n.kids ? n.open + n.kids.map(flat).join('') + n.close : n.s; };
        var blocky = function (n) {
            if (n.b === undefined) { n.b = BLOCK_TAGS.test(n.name) || (!!n.kids && n.kids.some(blocky)); }
            return n.b;
        };
        var edge = /^[ \t\n\r\f]+|[ \t\n\r\f]+$/g;
        var lines = [];
        var lay = function (kids, d) {
            var pad = '  '.repeat(Math.min(d, 12));
            var run = '';
            var flush = function () { var t = run.replace(edge, ''); if (t) { lines.push(pad + t); } run = ''; };
            kids.forEach(function (k) {
                if (!blocky(k)) { run += flat(k); return; }
                flush();
                if (k.kids && k.kids.some(blocky)) {
                    lines.push(pad + k.open);
                    lay(k.kids, d + 1);
                    if (k.close) { lines.push(pad + k.close); }
                } else {
                    lines.push(pad + flat(k));
                }
            });
            flush();
        };
        lay(root.kids, 0);
        return lines.join('\n') + '\n';
    }
    // Only the layout differs: line breaks and the indent after them.
    function sameHtml(a, b) {
        var f = function (s) { return String(s || '').replace(/[ \t\r\f]*\n[ \t\n\r\f]*/g, '\n').trim(); };
        return f(a) === f(b);
    }
    function hi(lang, text) {
        var e = esc(text);
        if (lang === 'html') {
            return e.replace(/\sdata-nibwp=(&quot;[^&]*?&quot;)/g, ' <i class="nb">data-nibwp</i>=<i class="s">$1</i>').replace(/(&lt;!--[\s\S]*?--&gt;)/g, '<i class="c">$1</i>')
                .replace(/(&lt;\/?)([a-z0-9-]+)/gi, '$1<i class="t">$2</i>')
                .replace(/\s([a-z-:@.]+)=(&quot;[\s\S]*?&quot;)/gi, ' <i class="a">$1</i>=<i class="s">$2</i>');
        }
        if (lang === 'css') {
            return e.replace(/(\/\*[\s\S]*?\*\/)/g, '<i class="c">$1</i>')
                .replace(/^([^{}\n:;][^{}\n]*?)(\s*\{)/gm, '<i class="t">$1</i>$2')
                .replace(/([a-z-]+)(\s*:)(?![^{]*\{)/g, '<i class="a">$1</i>$2')
                .replace(/(var\(--[a-z0-9-]+)/gi, '<i class="s">$1</i>');
        }
        return e.replace(/(\/\/[^\n]*|\/\*[\s\S]*?\*\/)/g, '<i class="c">$1</i>')
            .replace(/\b(const|let|var|function|return|if|else|for|while|new|this|document|window|true|false|null)\b/g, '<i class="t">$1</i>')
            .replace(/(&#39;[^&#\n]*?&#39;|&quot;[^&\n]*?&quot;)/g, '<i class="s">$1</i>');
    }
    // Which CSS lines the browser would drop: a declaration it does not
    // understand, a missing colon, an unclosed brace at the end. Read as the
    // browser reads it: comments out (across lines too), then each declaration
    // from one ; { or } to the next, outside quotes and brackets, so two on a
    // line or one value over three lines are fine.
    function cssErrors(lines) {
        var out = {};
        var text = lines.join('\n').replace(/\/\*[\s\S]*?(?:\*\/|$)/g, function (c) { return c.replace(/[^\n]/g, ' '); });
        var depth = 0;
        var paren = 0;
        var q = '';
        var line = 0;
        var start = 0;
        var from = -1;
        var bad = function (n, msg) { if (!out[n]) { out[n] = msg; } };
        var decl = function (end, semi) {
            var s = text.slice(start, end).trim();
            var n = from;
            start = end + 1;
            from = -1;
            if (!s || depth < 1 || s.charAt(0) === '@') { return; }
            var m = /^([-a-zA-Z]+)\s*:\s*([\s\S]+)$/.exec(s);
            // A word still being typed is not an error yet.
            if (!m) { if (semi || /[:\s]/.test(s)) { bad(n, __('Not a property: value', 'nibwp')); } return; }
            if (m[1].indexOf('--') === 0) { return; }
            var v = m[2].replace(/\s+/g, ' ').replace(/\s*!\s*important\s*$/i, '').trim();
            if (window.CSS && CSS.supports && !CSS.supports(m[1], v)) { bad(n, sprintf(__('“%1$s: %2$s” is not valid CSS', 'nibwp'), m[1], v)); }
        };
        for (var i = 0; i < text.length; i++) {
            var c = text.charAt(i);
            if (c === '\n') { line++; q = ''; continue; }
            if (from < 0 && !/\s/.test(c)) { from = line; }
            if (q) { if (c === '\\' && text.charAt(i + 1) !== '\n') { i++; } else if (c === q) { q = ''; } continue; }
            if (c === '"' || c === "'") { q = c; }
            else if (c === '(') { paren++; }
            else if (c === ')') { paren = Math.max(0, paren - 1); }
            else if (paren) { continue; }
            else if (c === '{') { start = i + 1; from = -1; depth++; }
            else if (c === ';') { decl(i, true); }
            else if (c === '}') {
                decl(i);
                if (depth < 1) { bad(line, __('A closing brace with nothing to close', 'nibwp')); } else { depth--; }
            }
        }
        decl(text.length);
        if (depth > 0 && lines.length) { bad(lines.length - 1, __('A brace is not closed', 'nibwp')); }
        return out;
    }
    /* States and screen sizes for the class being edited, written for you:
     * pick one, it goes inside the rule with the cursor ready in it. */
    var STATES = [
        ['&:hover', __('When the mouse is over it', 'nibwp')],
        ['&:focus-visible', __('When it has keyboard focus', 'nibwp')],
        ['&:active', __('While it is pressed', 'nibwp')],
        ['&:has(img)', __('When it contains an image (change img to anything)', 'nibwp')],
        ['&::before', __('A decoration before it', 'nibwp'), 'content: "";'],
        ['&::after', __('A decoration after it', 'nibwp'), 'content: "";'],
        ['@media (width < 768px)', __('On phones', 'nibwp')],
        ['@media (768px <= width < 1024px)', __('On tablets', 'nibwp')],
        ['@container (width < 480px)', __('When its container is narrow (a parent must be a container)', 'nibwp')],
        ['container-type: inline-size;', __('Make it a container, for @container inside it', 'nibwp'), null, true],
        ['@media (prefers-color-scheme: dark)', __('In dark mode', 'nibwp')],
        ['@media (prefers-reduced-motion: reduce)', __('For people who turn motion off', 'nibwp')]
    ];
    var statesEl = document.createElement('div');
    statesEl.className = 'nst-bd-states';
    statesEl.hidden = true;
    statesEl.setAttribute('role', 'menu');
    statesEl.innerHTML = STATES.map(function (x, i) { return '<button type="button" role="menuitem" data-state="' + i + '"><code>' + esc(x[0]) + '</code><span>' + esc(x[1]) + '</span></button>'; }).join('');
    root.appendChild(statesEl);
    function statesOpen(btn) {
        if (!statesEl.hidden) { statesEl.hidden = true; return; }
        var r = btn.getBoundingClientRect();
        statesEl.hidden = false;
        var w = statesEl.offsetWidth;
        var h = statesEl.offsetHeight;
        statesEl.style.left = Math.max(8, Math.min(r.right - w, innerWidth - w - 8)) + 'px';
        statesEl.style.top = (r.top - h - 6 > 8 ? r.top - h - 6 : r.bottom + 6) + 'px';
        statesEl.querySelector('button').focus();
    }
    // Walk CSS text outside comments and quotes: fn(char, index, depth before it).
    function cssWalk(v, from, to, fn) {
        var depth = 0;
        var q = '';
        for (var i = from; i < to; i++) {
            var c = v.charAt(i);
            if (q) { if (c === '\\') { i++; } else if (c === q || c === '\n') { q = ''; } continue; }
            if (c === '/' && v.charAt(i + 1) === '*') { var e = v.indexOf('*/', i + 2); i = e < 0 ? to : e + 1; continue; }
            if (c === '"' || c === "'") { q = c; continue; }
            if (fn(c, i, depth) === false) { return depth; }
            if (c === '{') { depth++; } else if (c === '}' && depth) { depth--; }
        }
        return depth;
    }
    // Where the top-level rule for a selector opens and closes, braces matched.
    function ruleSpan(v, selector) {
        var want = selector.replace(/\s+/g, ' ').trim();
        var start = 0;
        var span = null;
        cssWalk(v, 0, v.length, function (c, i, d) {
            if (span) { if (c === '}' && d === 1) { span.close = i; return false; } return; }
            if (c === '}' && d === 1) { start = i + 1; return; }
            if (d) { return; }
            if (c === '{' && v.slice(start, i).replace(/\/\*[\s\S]*?\*\//g, '').split(',').map(function (s) { return s.replace(/\s+/g, ' ').trim(); }).indexOf(want) > -1) { span = { open: i, close: -1 }; }
            else if (c === '}' || c === ';') { start = i + 1; }
        });
        return span;
    }
    // A state or a screen size goes inside the rule being edited: where the
    // cursor is when it is in that rule, else at the end of the rule.
    function statesPut(x) {
        var ta = eds.css && eds.css.ta;
        if (!ta || !cel()) { return; }
        var key = cssSel || selectorFor(cel());
        var v = ta.value;
        var pos = ta.selectionStart;
        var span = ruleSpan(v, key);
        if (!span) { v = v.replace(/\s*$/, v.trim() ? '\n\n' : '') + key + ' {\n}\n'; span = ruleSpan(v, key); }
        if (span.close < 0) { v = v.replace(/\s*$/, '\n') + '}\n'; span = ruleSpan(v, key); }
        var at = span.close;
        if (pos > span.open && pos <= span.close && cssWalk(v, span.open, pos, function () { }) === 1) {
            var nl = v.indexOf('\n', pos);
            if (nl > -1 && nl < span.close) { at = nl + 1; }
        }
        var before = at === span.close ? v.slice(0, at).replace(/\s*$/, '\n') : v.slice(0, at);
        var add = x[3] ? '  ' + x[0] + '\n' : '  ' + x[0] + ' {\n    ' + (x[2] ? x[2] + '\n    ' : '');
        var caret = before.length + add.length;
        if (!x[3]) { add += '\n  }\n'; }
        ta.value = before + add + v.slice(at);
        ta.focus();
        ta.setSelectionRange(caret, caret);
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
    dock.addEventListener('click', function (e) {
        var b = e.target.closest('[data-bd="states"]');
        if (b) { statesOpen(b); }
    });
    statesEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-state]');
        if (!b) { return; }
        statesEl.hidden = true;
        statesPut(STATES[+b.dataset.state]);
    });
    statesEl.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(statesEl.querySelectorAll('[data-state]'));
        var i = items.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); statesEl.hidden = true; var b = dock.querySelector('[data-bd="states"]'); if (b) { b.focus(); } }
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus(); }
    });
    document.addEventListener('pointerdown', function (e) { if (!statesEl.hidden && !statesEl.contains(e.target) && !e.target.closest('[data-bd="states"]')) { statesEl.hidden = true; } }, true);

    var eds = {};
    dock.querySelectorAll('.nst-cd').forEach(function (cd) {
        var lang = cd.dataset.lang;
        var ta = cd.querySelector('.nst-cd__ta');
        var code = cd.querySelector('.nst-cd__hi code');
        var gut = cd.querySelector('.nst-cd__gutter');
        var paintEd = function () {
            code.innerHTML = hi(lang, ta.value) + '\n';
            var lines = ta.value.split('\n');
            var bad = lang === 'css' ? cssErrors(lines) : {};
            gut.innerHTML = lines.map(function (_, i) { return bad[i] ? '<span class="is-err" title="' + esc(bad[i]) + '">' + (i + 1) + '</span>' : '<span>' + (i + 1) + '</span>'; }).join('');
            if (lang === 'css') { setTimeout(paintTools, 0); }
        };
        ta.addEventListener('scroll', function () { code.parentNode.scrollTop = ta.scrollTop; code.parentNode.scrollLeft = ta.scrollLeft; gut.scrollTop = ta.scrollTop; });
        ta.addEventListener('input', function () { paintEd(); idle(); if (lang === 'css') { cssInput(); } });
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); applyCode(lang); }
        });
        smartEdit(ta, lang);
        // Tab indents here; the way out is said where the pane says how it applies.
        var leave = __('Esc, then Tab leaves the editor', 'nibwp');
        var head = cd.closest('.nst-bd-pane') && cd.closest('.nst-bd-pane').querySelector('.nst-bd-pane__head b[data-tip]');
        if (head) { head.setAttribute('data-tip', head.getAttribute('data-tip') + ' · ' + leave); }
        ta.setAttribute('aria-description', leave);
        ta.addEventListener('blur', function () { if (lang === 'css') { commitCss(); acClose(); } idle(); });
        if (lang === 'html') { ta.addEventListener('change', function () { applyCode('html'); }); }
        if (lang === 'css') { setTimeout(function () { autocomplete(ta, paintEd); }, 0); }
        eds[lang] = { ta: ta, paint: paintEd, shown: '', set: function (v) { ta.value = v; paintEd(); ta.scrollTop = 0; } };
    });

    /* ------------------------------------------------------------------
     * The selector palette: type ".class #id attr=\"value\"" or pick one of
     * your saved classes or the page's. Ctrl+Enter opens it.
     * ---------------------------------------------------------------- */

    var pal = document.createElement('div');
    pal.className = 'nst-bd-pal';
    pal.hidden = true;
    pal.setAttribute('role', 'dialog');
    pal.setAttribute('aria-label', __('Add a class, ID or attribute', 'nibwp'));
    pal.innerHTML = '<div class="nst-bd-pal__card"><header><span><kbd>Ctrl ↵</kbd><b>' + esc(__('Selector', 'nibwp')) + '</b></span><small>' + esc(__('Enter adds · Esc closes', 'nibwp')) + '</small></header>' +
        '<input class="nst-bd-pal__in" spellcheck="false" autocomplete="off" placeholder=".class #id attr=&quot;value&quot;" aria-label="' + esc(__('Classes, an ID or attributes to add', 'nibwp')) + '">' +
        '<div class="nst-bd-pal__list" role="listbox"></div></div>';
    root.appendChild(pal);
    var palIn = pal.querySelector('.nst-bd-pal__in');
    var palList = pal.querySelector('.nst-bd-pal__list');
    var palAt = 0;
    function palClasses() {
        var saved = Object.keys(cssLive).concat(Object.keys(cfg.css || {})).filter(function (k) { return /^\.[-_a-z0-9]+$/i.test(k); });
        var page = {};
        if (doc) { Array.prototype.forEach.call(doc.querySelectorAll('[class]'), function (n, i) { if (i < 4000 && typeof n.className === 'string') { n.className.split(/\s+/).forEach(function (c) { if (c && !/^nst-x/.test(c)) { page['.' + c] = 1; } }); } }); }
        var seen = {};
        return saved.map(function (k) { return [k, 'saved']; }).concat(Object.keys(page).sort().map(function (k) { return [k, 'page']; })).filter(function (x) { if (seen[x[0]]) { return false; } seen[x[0]] = 1; return true; });
    }
    function paintPal() {
        var q = palIn.value.trim().split(/\s+/).pop().replace(/^\./, '').toLowerCase();
        var on = cel() && typeof cel().className === 'string' ? cel().className.split(/\s+/) : [];
        var raw = palIn.value.trim();
        var tok = raw && !/[#=\[\s]/.test(raw) ? '.' + raw.replace(/^\./, '') : '';
        var list = palClasses().filter(function (x) { return !q || x[0].toLowerCase().indexOf(q) > -1; }).slice(0, 150);
        // The class typed, exactly, comes first: Enter adds what you typed.
        var exact = tok ? list.map(function (x) { return x[0].toLowerCase(); }).indexOf(tok.toLowerCase()) : -1;
        if (exact > 0) { list.unshift(list.splice(exact, 1)[0]); }
        else if (tok && exact < 0) { list.unshift([tok, 'new']); }
        palAt = Math.min(palAt, Math.max(0, list.length - 1));
        palList.innerHTML = (list.length ? '<p class="nst-bd-pal__g">' + esc(__('Available classes', 'nibwp')) + '</p>' : '<p class="nst-bd-pal__g">' + esc(__('Enter adds what you typed', 'nibwp')) + '</p>') + list.map(function (x, i) {
            return '<button type="button" role="option" data-pal="' + esc(x[0]) + '"' + (i === palAt ? ' aria-selected="true"' : '') + '><code>' + esc(x[0]) + '</code>' + (on.indexOf(x[0].slice(1)) > -1 ? '<small class="is-on">' + esc(__('on it', 'nibwp')) + '</small>' : x[1] === 'saved' ? '<small>' + esc(__('saved', 'nibwp')) + '</small>' : x[1] === 'new' ? '<small class="is-new">' + esc(__('new class', 'nibwp')) + '</small>' : '') + '</button>';
        }).join('');
        var cur = palList.querySelector('[aria-selected="true"]');
        if (cur) { cur.scrollIntoView({ block: 'nearest' }); }
    }
    var palFrom = null;
    function openPalette() {
        if (!cel()) { return; }
        var from = document.activeElement;
        if (pal.hidden) { palFrom = from && from !== document.body && !pal.contains(from) ? from : null; }
        pal.hidden = false;
        palIn.value = '';
        palAt = 0;
        paintPal();
        palIn.focus();
    }
    // Closed with the focus still in it: back to what opened it (adding a
    // class has already put you in its CSS).
    function closePalette() {
        var was = !pal.hidden;
        var inside = pal.contains(document.activeElement) || document.activeElement === document.body;
        pal.hidden = true;
        var to = palFrom;
        palFrom = null;
        if (was && inside && to && to.isConnected && to.getClientRects().length) { to.focus({ preventScroll: true }); }
    }
    // Each token on its own: ".a .b #c data-x=\"1\"".
    function palAdd(text) {
        (text.match(/\.[-_a-z0-9]+|#[-_a-z0-9]+|\[?[a-z_:][-a-z0-9_:.]*(?:=(?:"[^"]*"|'[^']*'|[^\s\]]+))?\]?/gi) || []).forEach(function (t) {
            if (t[0] !== '.' && t[0] !== '#' && t[0] !== '[' && t.indexOf('=') < 0) { t = '.' + t; }
            addSel(t);
        });
        closePalette();
    }
    palIn.addEventListener('input', function () { palAt = 0; paintPal(); });
    palIn.addEventListener('keydown', function (e) {
        var items = palList.querySelectorAll('[data-pal]');
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePalette(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); palAt = (palAt + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % Math.max(1, items.length); paintPal(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            var typed = palIn.value.trim();
            var pick = items[palAt];
            if (typed && /[#=\[\s]/.test(typed)) { palAdd(typed); } else if (pick) { palAdd(pick.dataset.pal); } else if (typed) { palAdd(typed); }
        }
    });
    palList.addEventListener('click', function (e) { var b = e.target.closest('[data-pal]'); if (b) { palAdd(b.dataset.pal); } });
    pal.addEventListener('pointerdown', function (e) { if (e.target === pal) { closePalette(); } });

    /* ------------------------------------------------------------------
     * CSS autocomplete: properties as you start a line, values after the
     * colon, the site's own variables too.
     * ---------------------------------------------------------------- */

    var CSS_PROPS = 'align-content align-items align-self animation aspect-ratio backdrop-filter background background-color background-image background-position background-repeat background-size block-size border border-block border-bottom border-color border-inline border-left border-radius border-right border-style border-top border-width bottom box-shadow box-sizing color column-gap columns container-type content cursor display filter flex flex-basis flex-direction flex-grow flex-shrink flex-wrap font font-family font-size font-style font-weight gap grid-area grid-column grid-row grid-template-areas grid-template-columns grid-template-rows height inline-size inset inset-block inset-inline isolation justify-content justify-items justify-self left letter-spacing line-height list-style margin margin-block margin-bottom margin-inline margin-left margin-right margin-top max-block-size max-height max-inline-size max-width min-block-size min-height min-inline-size min-width mix-blend-mode object-fit object-position opacity order outline outline-offset overflow overflow-x overflow-y padding padding-block padding-bottom padding-inline padding-left padding-right padding-top place-content place-items place-self pointer-events position right rotate row-gap scale scroll-behavior scroll-margin text-align text-decoration text-overflow text-shadow text-transform text-wrap top transform transition translate user-select vertical-align visibility white-space width word-break z-index'.split(' ');
    var CSS_VALS = {
        display: 'block flex grid inline inline-block inline-flex inline-grid none contents', position: 'relative absolute fixed sticky static',
        'flex-direction': 'row column row-reverse column-reverse', 'flex-wrap': 'wrap nowrap', 'justify-content': 'flex-start center flex-end space-between space-around space-evenly',
        'align-items': 'stretch center flex-start flex-end baseline', 'align-self': 'auto stretch center flex-start flex-end', 'text-align': 'left center right justify start end',
        'font-weight': '300 400 500 600 700 800 900', 'font-style': 'normal italic', overflow: 'hidden auto visible scroll clip', 'overflow-x': 'hidden auto visible scroll clip', 'overflow-y': 'hidden auto visible scroll clip',
        'object-fit': 'cover contain fill none scale-down', cursor: 'pointer default text move grab not-allowed', 'text-transform': 'uppercase lowercase capitalize none',
        'white-space': 'nowrap normal pre-wrap', 'box-sizing': 'border-box content-box', visibility: 'visible hidden', 'text-decoration': 'none underline line-through',
        'pointer-events': 'none auto', 'grid-template-columns': 'repeat(2, 1fr) repeat(3, 1fr) repeat(auto-fit, minmax(240px, 1fr))', 'container-type': 'inline-size size normal', 'text-wrap': 'balance pretty wrap nowrap'
    };
    var cssVarList = null;
    function cssVars() {
        if (cssVarList) { return cssVarList; }
        var seen = {};
        if (doc) {
            Array.prototype.forEach.call(doc.styleSheets, function (sh) {
                var walk = function (rules) {
                    Array.prototype.forEach.call(rules || [], function (r) {
                        if (r.style) { for (var i = 0; i < r.style.length; i++) { if (r.style[i].indexOf('--') === 0) { seen[r.style[i]] = 1; } } }
                        if (r.cssRules) { walk(r.cssRules); }
                    });
                };
                try { walk(sh.cssRules); } catch (x) { }
            });
        }
        cssVarList = Object.keys(seen).sort().slice(0, 1200);
        return cssVarList;
    }
    // Read the variables again next time (a new page, or the framework's tokens arrived).
    function resetCssVars() { cssVarList = null; }
    var ac = document.createElement('div');
    ac.className = 'nst-bd-ac';
    ac.hidden = true;
    ac.setAttribute('role', 'listbox');
    root.appendChild(ac);
    var acState = null;
    function acClose() { ac.hidden = true; acState = null; }
    // More values to offer: "|" separates values that hold spaces.
    ['color', 'background', 'background-color', 'border-color', 'outline-color', 'fill', 'stroke', 'text-decoration-color', 'caret-color', 'accent-color'].forEach(function (k) { CSS_VALS[k] = 'currentColor|transparent|white|black'; });
    ['width', 'height', 'max-width', 'min-height', 'inline-size', 'block-size'].forEach(function (k) { CSS_VALS[k] = '100%|auto|fit-content|min-content|max-content'; });
    ['justify-items', 'justify-self', 'place-items', 'place-content', 'place-self'].forEach(function (k) { CSS_VALS[k] = 'center|start|end|stretch'; });
    Object.assign(CSS_VALS, {
        'align-content': 'flex-start center flex-end space-between space-around stretch', transition: 'all .2s ease|opacity .2s ease|transform .2s ease|none',
        border: '1px solid currentColor|none', 'box-shadow': '0 1px 3px rgb(0 0 0 / .12)|0 10px 30px rgb(0 0 0 / .15)|none', 'font-family': 'inherit|system-ui, sans-serif|Georgia, serif|ui-monospace, monospace',
        'aspect-ratio': '1|16 / 9|4 / 3|3 / 2', inset: '0|auto', flex: '1|auto|none|0 0 auto', transform: 'translateY(-2px)|scale(1.05)|rotate(45deg)|none', content: '""',
        'list-style': 'none', 'user-select': 'none auto text all', 'scroll-behavior': 'smooth auto', isolation: 'isolate auto', 'mix-blend-mode': 'multiply screen overlay normal',
        'word-break': 'break-word break-all normal', 'text-overflow': 'ellipsis clip', 'vertical-align': 'middle top bottom baseline', 'margin-inline': 'auto', 'border-style': 'solid dashed dotted none'
    });
    var CSS_ALIAS = { bg: 'background', bgc: 'background-color', bgi: 'background-image', c: 'color', d: 'display', h: 'height', w: 'width', m: 'margin', p: 'padding', z: 'z-index', op: 'opacity', pos: 'position', bd: 'border', fz: 'font-size', ff: 'font-family', trs: 'transition', tf: 'transform' };
    var CSS_PSEUDO = [':hover', ':focus', ':focus-visible', ':focus-within', ':active', ':visited', ':first-child', ':last-child', ':nth-child()', ':not()', ':has()', ':is()', ':where()', ':empty', ':checked', ':disabled', '::before', '::after', '::placeholder', '::selection', '::marker'];
    var CSS_AT = ['@media (width < 768px)', '@media (768px <= width < 1024px)', '@media (width >= 1024px)', '@media (prefers-color-scheme: dark)', '@media (prefers-reduced-motion: reduce)', '@container (width < 480px)', '@supports ()', '@layer', '@keyframes'];
    var CSS_UNITLESS = /^(opacity|z-index|line-height|order|flex-grow|flex-shrink|scale|font-weight|aspect-ratio|columns)$/;
    function cssValList(prop) {
        var v = CSS_VALS[prop] || '';
        return !v ? [] : v.indexOf('|') > -1 ? v.split('|') : v.split(/ (?![^(]*\))/);
    }
    // Properties by what was typed: the start, a short name (jc, bg), the initials, then anywhere.
    function cssPropMatch(q) {
        var initials = function (x) { return x.split('-').map(function (w) { return w.charAt(0); }).join(''); };
        return (CSS_ALIAS[q] ? [CSS_ALIAS[q]] : [])
            .concat(CSS_PROPS.filter(function (x) { return x.indexOf(q) === 0; }))
            .concat(q.length > 1 ? CSS_PROPS.filter(function (x) { return initials(x).indexOf(q) === 0; }) : [])
            .concat(CSS_PROPS.filter(function (x) { return x.indexOf(q) > 0; }));
    }
    function autocomplete(ta, repaint) {
        var measure = document.createElement('canvas').getContext('2d');
        var suggest = function (force) {
            var pos = ta.selectionStart;
            if (pos !== ta.selectionEnd) { acClose(); return; }
            var before = ta.value.slice(0, pos);
            var line = before.slice(before.lastIndexOf('\n') + 1);
            var depth = 0;
            before.replace(/\/\*[\s\S]*?\*\//g, '').replace(/"[^"\n]*"|'[^'\n]*'/g, '').replace(/[{}]/g, function (c) { depth += c === '{' ? 1 : -1; return c; });
            var m;
            var items = [];
            var prefix = '';
            var kind = '';
            var prop = '';
            if ((m = /^\s*(@[-a-z]*)$/i.exec(line))) {
                prefix = m[1];
                kind = 'at';
                items = CSS_AT.filter(function (x) { return x.indexOf(prefix) === 0; });
            } else if ((m = /^\s*(&|[^\s:{};@][^:{};]*)?(::?[-a-z]*)$/i.exec(line)) && (depth === 0 || /^\s*&/.test(line))) {
                prefix = m[2];
                kind = 'pseudo';
                items = CSS_PSEUDO.filter(function (x) { return x.indexOf(prefix) === 0; });
            } else if (depth > 0 && (m = /^\s*([-a-z]*)$/i.exec(line))) {
                prefix = m[1].toLowerCase();
                if (!prefix && !force) { acClose(); return; }
                kind = 'prop';
                items = prefix ? cssPropMatch(prefix) : CSS_PROPS.slice();
            } else if (depth > 0 && (m = /^\s*([-a-z]+)\s*:\s*([^;{}]*)$/i.exec(line))) {
                prop = m[1].toLowerCase();
                var word = /(\S*)$/.exec(m[2])[1];
                prefix = word;
                kind = 'val';
                var vals = cssValList(prop);
                // A value already whole (block, red, var(--x)) needs no list.
                var whole = word && !/^(var\(|--|-?[\d.])/.test(word) && window.CSS && CSS.supports && CSS.supports(prop, word) && !vals.some(function (v) { return v !== word && v.indexOf(word) === 0; });
                if (whole || /^var\(--[-\w]+\)$/.test(word)) { acClose(); return; }
                if (/^!/.test(word)) {
                    items = ['!important'];
                } else if (/^-?(\d+\.?\d*|\.\d+)$/.test(word)) {
                    items = CSS_UNITLESS.test(prop) ? [] : ['px', 'rem', 'em', '%', 'vw', 'vh', 'ch'].concat(/grid/.test(prop) ? ['fr'] : [], /rotate|transform/.test(prop) ? ['deg'] : [], /transition|animation/.test(prop) ? ['s', 'ms'] : []).map(function (u) { return word + u; });
                } else {
                    var q = word.replace(/^var\(/, '').replace(/^--/, '').toLowerCase();
                    items = vals.filter(function (v) { return !word || v.indexOf(word) === 0; })
                        .concat(word.length > 0 ? ['inherit', 'initial', 'unset'].filter(function (v) { return v.indexOf(word) === 0; }) : [])
                        .concat(word || vals.length || force ? cssVars().filter(function (v) { return !q || v.toLowerCase().indexOf(q) > -1; }).map(function (v) { return 'var(' + v + ')'; }) : []);
                }
            }
            items = items.filter(function (x, i, a) { return a.indexOf(x) === i && x !== prefix; }).slice(0, 40);
            if (!items.length) { acClose(); return; }
            acState = { items: items, at: 0, prefix: prefix, kind: kind, ta: ta, moved: false };
            var cs = getComputedStyle(ta);
            measure.font = cs.fontSize + ' ' + cs.fontFamily;
            var lines = before.split('\n');
            var lh = parseFloat(cs.lineHeight) || 18;
            var r = ta.getBoundingClientRect();
            var x = r.left + parseFloat(cs.paddingLeft) + measure.measureText(line.slice(0, line.length - prefix.length)).width - ta.scrollLeft;
            var y = r.top + parseFloat(cs.paddingTop) + lines.length * lh - ta.scrollTop + 2;
            paintAc();
            ac.hidden = false;
            ac.style.left = Math.max(8, Math.min(window.innerWidth - ac.offsetWidth - 8, x)) + 'px';
            ac.style.top = (y + ac.offsetHeight > window.innerHeight - 8 ? y - lh - ac.offsetHeight - 4 : y) + 'px';
        };
        var accept = function () {
            var st = acState;
            if (!st) { return; }
            var v = st.items[st.at];
            var pos = ta.selectionStart;
            var value = ta.value;
            var lineStart = value.lastIndexOf('\n', pos - 1) + 1;
            var lineEnd = value.indexOf('\n', pos);
            var rest = value.slice(pos, lineEnd < 0 ? value.length : lineEnd);
            // The pick replaces the whole word the cursor is in, not only its start.
            var tail = (st.kind === 'val' ? /^[^\s;{},]*/ : /^[-\w]*/).exec(rest)[0];
            rest = rest.slice(tail.length);
            var ind = /^\s*/.exec(value.slice(lineStart))[0];
            var ins = st.kind === 'prop' ? v + (/^\s*:/.test(rest) ? '' : ': ') : st.kind === 'val' ? v + (/^\s*;/.test(rest) ? '' : ';') : v;
            // A selector or an at-rule comes with its block, the cursor inside.
            var block = (st.kind === 'pseudo' || st.kind === 'at') && !/\S/.test(rest) && !/\{/.test(value.slice(lineStart, pos)) && !/^@(layer|keyframes)$/.test(v);
            var from = pos - st.prefix.length;
            var text = ins + (block ? ' {\n' + ind + '  \n' + ind + '}' : '');
            var caret = ins.indexOf('()') > -1 ? from + ins.indexOf('()') + 1 : block ? from + ins.length + 3 + ind.length + 2 : from + text.length;
            acClose();
            edInsert(ta, text, from, pos + tail.length, caret);
        };
        ta.addEventListener('input', function () { suggest(false); });
        ta.addEventListener('click', acClose);
        ta.addEventListener('keydown', function (e) {
            if (e.isComposing || e.keyCode === 229) { return; }
            if ((e.ctrlKey || e.metaKey) && (e.key === ' ' || e.code === 'Space')) { e.preventDefault(); e.stopImmediatePropagation(); suggest(true); return; }
            if (!acState || ac.hidden) { return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); e.stopImmediatePropagation(); acState.moved = true; acState.at = (acState.at + (e.key === 'ArrowDown' ? 1 : acState.items.length - 1)) % acState.items.length; paintAc(); return; }
            // Tab always picks; Enter only once you chose with the arrows, else it is a new line.
            if ((e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.metaKey) || (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.shiftKey && acState.moved)) { e.preventDefault(); e.stopImmediatePropagation(); accept(); return; }
            if (e.key === 'Enter') { acClose(); return; }
            if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); acClose(); return; }
            if (/^(ArrowLeft|ArrowRight|Home|End|PageUp|PageDown)$/.test(e.key)) { acClose(); }
        }, true);
        ac.addEventListener('pointerdown', function (e) { var b = e.target.closest('[data-ac]'); if (b && acState && acState.ta === ta) { e.preventDefault(); acState.at = +b.dataset.ac; accept(); } });
    }
    function paintAc() {
        if (!acState) { return; }
        var tag = { prop: 'prop', pseudo: 'state', at: 'rule' };
        ac.innerHTML = acState.items.map(function (v, i) { return '<div role="option" data-ac="' + i + '"' + (i === acState.at ? ' aria-selected="true"' : '') + '>' + (/^var\(/.test(v) ? '<i>var</i>' : tag[acState.kind] ? '<i>' + tag[acState.kind] + '</i>' : '') + esc(v) + '</div>'; }).join('') +
            '<p class="nst-bd-ac__hint" aria-hidden="true"><kbd>Tab</kbd> ' + esc(__('pick', 'nibwp')) + ' · <kbd>↑↓</kbd> ' + esc(__('move', 'nibwp')) + ' · <kbd>Esc</kbd></p>';
        var cur = ac.querySelector('[aria-selected="true"]');
        if (cur) { cur.scrollIntoView({ block: 'nearest' }); }
    }

    /* The code panes type like a code editor: brackets and quotes close
     * themselves (and step over when typed again), Enter keeps the indent
     * and opens a block between { }, Tab / Shift+Tab indent the lines,
     * Ctrl+/ comments them. Changes go through the browser's own editing,
     * so the pane's Ctrl+Z takes them back one by one. */
    function edInsert(ta, text, from, to, caret) {
        ta.focus();
        ta.setSelectionRange(from, to);
        var done = false;
        try { done = document.execCommand('insertText', false, text); } catch (x) { done = false; }
        if (!done || ta.value.slice(from, from + text.length) !== text) {
            ta.setRangeText(text, from, to, 'end');
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (caret !== undefined) { ta.setSelectionRange(caret, caret); }
    }
    function smartEdit(ta, lang) {
        var pairs = { '{': '}', '(': ')', '[': ']', '"': '"', "'": "'" };
        if (lang === 'js') { pairs['`'] = '`'; }
        var closers = '}])"\'`';
        var marks = lang === 'js' ? ['// ', ''] : lang === 'html' ? ['<!-- ', ' -->'] : ['/* ', ' */'];
        // Esc, then Tab (or Shift+Tab) leaves the pane: Tab alone indents.
        var tabOut = false;
        ta.addEventListener('focus', function () { tabOut = false; });
        ta.addEventListener('keydown', function (e) {
            if (/^(Shift|Control|Alt|Meta|CapsLock)$/.test(e.key)) { return; }
            if (e.key === 'Tab' && tabOut && !e.ctrlKey && !e.metaKey && !e.altKey) { tabOut = false; return; }
            tabOut = e.key === 'Escape' && !e.defaultPrevented;
            if (e.defaultPrevented || e.isComposing || e.altKey || ta.readOnly) { return; }
            var v = ta.value;
            var s = ta.selectionStart;
            var en = ta.selectionEnd;
            var lineStart = v.lastIndexOf('\n', s - 1) + 1;
            var line = v.slice(lineStart, s);
            var prev = v.charAt(s - 1);
            var next = v.charAt(en);
            if ((e.ctrlKey || e.metaKey) && (e.key === '/' || e.code === 'Slash')) {
                e.preventDefault();
                var a = lineStart;
                var b = v.indexOf('\n', en > s && v.charAt(en - 1) === '\n' ? en - 1 : en);
                if (b < 0) { b = v.length; }
                var rows = v.slice(a, b).split('\n');
                var esc2 = function (t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
                var re = new RegExp('^(\\s*)' + esc2(marks[0].trim()) + ' ?(.*?)' + (marks[1] ? ' ?' + esc2(marks[1].trim()) : '') + '\\s*$');
                var all = rows.every(function (r) { return !r.trim() || re.test(r); });
                var out = rows.map(function (r) { return !r.trim() ? r : all ? r.replace(re, '$1$2') : r.replace(/^(\s*)(.*)$/, '$1' + marks[0] + '$2' + marks[1]); }).join('\n');
                edInsert(ta, out, a, b);
                ta.setSelectionRange(a, a + out.length);
                return;
            }
            if (e.ctrlKey || e.metaKey) { return; }
            if (s === en && closers.indexOf(e.key) > -1 && e.key.length === 1 && next === e.key) { e.preventDefault(); ta.setSelectionRange(s + 1, s + 1); return; }
            if (pairs[e.key]) {
                var quote = pairs[e.key] === e.key;
                if (s === en && quote && /[\w\\]/.test(prev)) { return; }
                if (s === en && /[\w$]/.test(next)) { return; }
                e.preventDefault();
                var inner = v.slice(s, en);
                edInsert(ta, e.key + inner + pairs[e.key], s, en);
                ta.setSelectionRange(s + 1, s + 1 + inner.length);
                return;
            }
            if (e.key === '}' && s === en && /^\s+$/.test(line)) {
                e.preventDefault();
                edInsert(ta, line.slice(0, Math.max(0, line.length - 2)) + '}', lineStart, s);
                return;
            }
            if (e.key === 'Backspace' && s === en && s > 0) {
                if (pairs[prev] && pairs[prev] === next) { e.preventDefault(); edInsert(ta, '', s - 1, s + 1, s - 1); return; }
                if (/^ +$/.test(line) && line.length >= 2) { e.preventDefault(); var n = line.length % 2 || 2; edInsert(ta, '', s - n, s, s - n); return; }
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                var ind = /^[ \t]*/.exec(line)[0];
                var opens = /[{(\[]\s*$/.test(line);
                var between = opens && /^\s*[})\]]/.test(v.slice(en));
                if (between) {
                    var after = /^\s*/.exec(v.slice(en))[0].length;
                    edInsert(ta, '\n' + ind + '  \n' + ind, s, en + after, s + 1 + ind.length + 2);
                } else {
                    edInsert(ta, '\n' + ind + (opens ? '  ' : ''), s, en);
                }
                return;
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                if (s === en && !e.shiftKey) { edInsert(ta, '  ', s, en); return; }
                var a2 = lineStart;
                var b2 = en > s && v.charAt(en - 1) === '\n' ? en - 1 : en;
                var lineEnd = v.indexOf('\n', b2);
                b2 = lineEnd < 0 ? v.length : lineEnd;
                var block = v.slice(a2, b2);
                var out2 = e.shiftKey ? block.replace(/^ {1,2}/gm, '') : block.replace(/^/gm, '  ');
                edInsert(ta, out2, a2, b2);
                if (s === en) { var moved = out2.length - block.length; ta.setSelectionRange(Math.max(a2, s + moved), Math.max(a2, s + moved)); } else { ta.setSelectionRange(a2, a2 + out2.length); }
            }
        });
    }
    // This page's own body class (page-id-12, postid-7), or '' off a single page.
    function pageClass() {
        var b = doc && doc.body;
        return b ? Array.prototype.filter.call(b.classList, function (c) { return /^(page-id|postid)-\d+$/.test(c); })[0] || '' : '';
    }
    // A selector with no class or ID of its own (body, a path to the element)
    // prints on every page: the page's body class keeps it to this one.
    function pageScoped(path) {
        var pc = pageClass();
        if (!pc) { return path; }
        return /^body(?![-\w.#:[])/.test(path) ? 'body.' + pc + path.slice(4) : 'body.' + pc + ' ' + path;
    }
    function scopedHere(k) {
        var pc = pageClass();
        return !!pc && k.indexOf('body.' + pc) === 0 && !/[-\w]/.test(k.charAt(5 + pc.length));
    }
    function selectorFor(el) {
        var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(function (c) { return c && !/^(nst-x|is-|has-|wp-block-group-is)/.test(c); })[0] : '';
        return el.id ? '#' + el.id : cls ? '.' + cls : pageScoped(kit.cssPath(el));
    }

    // Every rule that styles the element, wherever it comes from (the theme,
    // Etch or any builder, plugins, the Builder itself), in the order the
    // browser applies them: hover and focus states, ::before and ::after,
    // @media, @supports, @container and @layer included. To read and copy.
    function matchedCss(el) {
        var d = el.ownerDocument;
        var win = d.defaultView;
        var groups = [];
        var count = 0;
        var LIMIT = 400;
        var DYNAMIC = /:(hover|focus|focus-visible|focus-within|active|visited|target|checked|disabled|enabled|placeholder-shown)\b(\([^)]*\))?/gi;
        var PSEUDO = /::?(before|after|placeholder|marker|selection|first-line|first-letter|backdrop|file-selector-button|-webkit-[\w-]+|-moz-[\w-]+)\b.*$/i;
        var hits = function (sl) {
            var base = sl.replace(PSEUDO, '').replace(DYNAMIC, '').trim() || '*';
            try { return el.matches(base); } catch (x) { return false; }
        };
        var decls = function (text, pad) {
            return text.split(/;\s*(?=[-\w]+\s*:)/).map(function (x) { return x.replace(/;\s*$/, '').trim(); }).filter(Boolean).map(function (x) { return pad + '  ' + x + ';'; }).join('\n');
        };
        // Collected in the order the browser applies them, shown the other way
        // round: what wins comes first, the resets and defaults last.
        var flat = [];
        var add = function (src, text) { flat.push({ src: src, text: text }); count++; };
        var walk = function (list, src, wrap) {
            Array.prototype.forEach.call(list || [], function (r) {
                if (count >= LIMIT) { return; }
                if (r.selectorText && r.style) {
                    var keep = r.selectorText.split(/,(?![^(]*\))/).map(function (x) { return x.trim(); }).filter(hits);
                    if (!keep.length || !r.style.cssText.trim()) { return; }
                    var pad = wrap.map(function () { return '  '; }).join('');
                    var body = pad + keep.join(',\n' + pad) + ' {\n' + decls(r.style.cssText, pad) + '\n' + pad + '}';
                    var open = wrap.map(function (w, i) { return wrap.slice(0, i).map(function () { return '  '; }).join('') + w + ' {'; }).join('\n');
                    var close = wrap.map(function (w, i) { return wrap.slice(0, wrap.length - 1 - i).map(function () { return '  '; }).join('') + '}'; }).join('\n');
                    add(src, (open ? open + '\n' : '') + body + (close ? '\n' + close : ''));
                    return;
                }
                if (r.styleSheet) { var sub = null; try { sub = r.styleSheet.cssRules; } catch (x) { sub = null; } walk(sub, src, wrap); return; }
                if (!r.cssRules) { return; }
                var head = '';
                if (r.media && r.media.mediaText !== undefined && r.type === 4) {
                    var on = true;
                    try { on = win.matchMedia(r.media.mediaText).matches; } catch (x) { on = true; }
                    head = '@media ' + r.media.mediaText + (on ? '' : ' /* ' + __('not at this width', 'nibwp') + ' */');
                } else if (r.conditionText !== undefined) {
                    head = (r.type === 12 ? '@supports ' : '@container ' + (r.containerName ? r.containerName + ' ' : '')) + r.conditionText;
                } else if (r.name !== undefined) {
                    head = '@layer ' + r.name;
                }
                walk(r.cssRules, src, head ? wrap.concat(head) : wrap);
            });
        };
        Array.prototype.forEach.call(d.styleSheets, function (sh) {
            if (sh.disabled) { return; }
            var id = (sh.ownerNode && sh.ownerNode.id) || '';
            if (id === 'nst-x-bd-style' || id === 'nibwp-hide') { return; }
            var src = id === 'nst-x-bd-live' ? __('NibWP Builder', 'nibwp') : sh.href ? sh.href.split('?')[0].split('/').pop() : (id ? id.replace(/-css$/, '') : __('a style tag on the page', 'nibwp'));
            var rules = null;
            try { rules = sh.cssRules; } catch (x) { rules = null; }
            walk(rules, src, []);
        });
        var inline = el.getAttribute('style');
        if (inline && inline.trim()) { add(__('this element’s own style', 'nibwp'), 'element.style {\n' + decls(inline, '') + '\n}'); }
        flat.reverse().forEach(function (f) {
            var g = groups[groups.length - 1];
            if (!g || g.src !== f.src) { g = { src: f.src, rules: [] }; groups.push(g); }
            g.rules.push(f.text);
        });
        var text = groups.map(function (g) { return '/* ' + g.src.replace(/\*\//g, '') + ' */\n' + g.rules.join('\n\n'); }).join('\n\n');
        /* translators: %d: how many CSS rules are listed */
        if (count >= LIMIT) { text += '\n\n/* ' + sprintf(__('First %d rules shown.', 'nibwp'), LIMIT) + ' */'; }
        return text;
    }
    var cssAll = false;
    var allText = '';
    var allEl = document.createElement('pre');
    allEl.className = 'nst-bd-pane__all';
    allEl.hidden = true;
    allEl.tabIndex = 0;
    allEl.setAttribute('aria-label', __('All the CSS that styles this element', 'nibwp'));
    allEl.innerHTML = '<code></code>';
    (function () { var p = dock.querySelector('[data-pane="css"]'); if (p) { p.insertBefore(allEl, p.querySelector('.nst-cd')); } })();
    function renderAll() {
        var el = cel();
        allText = el ? matchedCss(el) : '';
        allEl.querySelector('code').innerHTML = hi('css', allText || '/* ' + __('Nothing in the page’s stylesheets styles this element.', 'nibwp') + ' */');
    }
    function setCssAll(onNow) {
        cssAll = onNow;
        var pane = dock.querySelector('[data-pane="css"]');
        if (pane) { pane.classList.toggle('is-all', onNow); }
        var b = dock.querySelector('[data-bd="cssall"]');
        if (b) { b.setAttribute('aria-pressed', String(onNow)); }
        allEl.hidden = !onNow;
        if (onNow) { renderAll(); }
    }
    dock.addEventListener('click', function (e) {
        if (e.target.closest('[data-bd="cssall"]')) { setCssAll(!cssAll); }
    });
    // The site's own CSS for exactly this selector, whatever wrote it (Etch,
    // another builder, the theme, a plugin): its states and pseudo-elements
    // too, inside the same @media, @supports, @container and @layer, so a copy
    // edited and saved keeps every screen size working as it did.
    function siteCss(selector) {
        var d = cel() ? cel().ownerDocument : doc;
        if (!d || !selector) { return ''; }
        var want = selector.replace(/\s+/g, ' ').trim();
        var out = [];
        var mine = function (part) {
            var x = part.replace(/\s+/g, ' ').trim();
            return x === want || (x.indexOf(want) === 0 && x.charAt(want.length) === ':');
        };
        var decls = function (text, pad) {
            return text.split(/;\s*(?=[-\w]+\s*:)/).map(function (x) { return x.replace(/;\s*$/, '').trim(); }).filter(Boolean).map(function (x) { return pad + '  ' + x + ';'; }).join('\n');
        };
        var walk = function (list, wrap) {
            Array.prototype.forEach.call(list || [], function (r) {
                if (r.selectorText && r.style) {
                    var keep = r.selectorText.split(/,(?![^(]*\))/).filter(mine).map(function (x) { return x.trim(); });
                    if (!keep.length || !r.style.cssText.trim()) { return; }
                    var pad = wrap.map(function () { return '  '; }).join('');
                    var open = wrap.map(function (w, i) { return wrap.slice(0, i).map(function () { return '  '; }).join('') + w + ' {'; }).join('\n');
                    var close = wrap.map(function (w, i) { return wrap.slice(0, wrap.length - 1 - i).map(function () { return '  '; }).join('') + '}'; }).join('\n');
                    out.push((open ? open + '\n' : '') + pad + keep.join(', ') + ' {\n' + decls(r.style.cssText, pad) + '\n' + pad + '}' + (close ? '\n' + close : ''));
                    return;
                }
                if (r.styleSheet) { var sub = null; try { sub = r.styleSheet.cssRules; } catch (x) { sub = null; } walk(sub, wrap); return; }
                if (!r.cssRules) { return; }
                var head = r.type === 4 && r.media ? '@media ' + r.media.mediaText : r.conditionText !== undefined ? (r.type === 12 ? '@supports ' : '@container ' + (r.containerName ? r.containerName + ' ' : '')) + r.conditionText : r.name !== undefined ? '@layer' + (r.name ? ' ' + r.name : '') : '';
                walk(r.cssRules, head ? wrap.concat(head) : wrap);
            });
        };
        Array.prototype.forEach.call(d.styleSheets, function (sh) {
            var id = (sh.ownerNode && sh.ownerNode.id) || '';
            if (sh.disabled || /^(nst-x-bd-style|nst-x-bd-live|nibwp-builder-css|nibwp-hide)$/.test(id)) { return; }
            var rules = null;
            try { rules = sh.cssRules; } catch (x) { rules = null; }
            walk(rules, []);
        });
        return out.length ? out.join('\n\n') + '\n' : '';
    }
    // Each block's properties ("@media … || .card" → {display: 1}).
    function cssBlocks(text) {
        var out = {};
        var stack = [];
        var buf = '';
        var q = '';
        var take = function (dcl) {
            var k = stack.join(' || ');
            var m = /^([-\w]+)\s*:/.exec(dcl);
            if (m && stack.length && stack[stack.length - 1].charAt(0) !== '@') { (out[k] = out[k] || {})[m[1].toLowerCase()] = 1; }
        };
        for (var i = 0; i < text.length; i++) {
            var c = text.charAt(i);
            if (q) { buf += c; if (c === '\\') { buf += text.charAt(++i); } else if (c === q) { q = ''; } continue; }
            if (c === '/' && text.charAt(i + 1) === '*') { var e = text.indexOf('*/', i + 2); i = e < 0 ? text.length : e + 1; continue; }
            if (c === '"' || c === "'") { q = c; buf += c; continue; }
            if (c === '{') { stack.push(buf.replace(/\s+/g, ' ').trim()); buf = ''; continue; }
            if (c === ';' || c === '}') {
                take(buf.trim());
                buf = '';
                if (c === '}') { stack.pop(); }
                continue;
            }
            buf += c;
        }
        return out;
    }
    // A line taken out of the site's own CSS is gone for real: the site's
    // rule still stands underneath, so the saved copy unsets it.
    function withUnset(site, text) {
        var was = cssBlocks(site);
        var now = cssBlocks(text);
        var add = '';
        Object.keys(was).forEach(function (k) {
            var gone = Object.keys(was[k]).filter(function (pr) { return !(now[k] && now[k][pr]); });
            if (!gone.length) { return; }
            var path = k.split(' || ');
            var pad = function (n) { return new Array(n + 1).join('  '); };
            add += '\n' + path.map(function (h, i) { return pad(i) + h + ' {'; }).join('\n') + '\n' +
                gone.map(function (pr) { return pad(path.length) + pr + ': unset;'; }).join('\n') + '\n' +
                path.map(function (h, i) { return pad(path.length - 1 - i) + '}'; }).join('\n') + '\n';
        });
        return add ? text.replace(/\s*$/, '\n') + add : text;
    }
    var cssSite = {};
    var themeEl = document.createElement('p');
    themeEl.className = 'nst-bd-pane__theme';
    themeEl.hidden = true;
    (function () { var p = dock.querySelector('[data-pane="css"]'); if (p) { p.insertBefore(themeEl, p.querySelector('.nst-cd')); } })();

    function jsFor(el) {
        var out = [];
        [el].concat(Array.prototype.slice.call(el.querySelectorAll('*'), 0, 400)).forEach(function (n) {
            Array.prototype.forEach.call(n.attributes || [], function (a) { if (/^on/i.test(a.name)) { out.push(label(n, true) + ' ' + a.name + '="' + a.value + '"'); } });
        });
        Array.prototype.forEach.call(el.querySelectorAll('script'), function (s) { out.push(s.src ? 'script ' + s.src.split('/').pop() : s.textContent.trim().slice(0, 600)); });
        return out;
    }

    // A script for one element: el is that element. Kept with the page (or the
    // template being edited) and run on it there; a newer one for the same
    // element takes the older one's place.
    var EL_MARK = /^\/\* nibwp:el (\{.*?\}) \*\/\n/;
    function elSel(el) { return el.id && doc.querySelectorAll('#' + CSS.escape(el.id)).length === 1 ? '#' + CSS.escape(el.id) : kit.cssPath(el); }
    function elWrap(el, code) {
        var sl = elSel(el);
        return '/* nibwp:el ' + JSON.stringify({ s: sl }).replace(/\*\//g, '*\\/') + ' */\n(function (el) {\n  if (!el) { return; }\n' + code.replace(/^/gm, '  ') + '\n})(document.querySelector(' + JSON.stringify(sl) + '));';
    }
    function elUnwrap(t) {
        var lines = t.replace(/\r/g, '').trim().split('\n');
        return lines.slice(3, -1).map(function (l) { return l.replace(/^  /, ''); }).join('\n').replace(/<\\\/script/gi, '</script');
    }
    function elScript(el) {
        var printed = Array.prototype.map.call(doc.querySelectorAll('script[id^="nibwp-builder-js"]'), function (n) { return n.textContent.trim(); });
        var mine = past.filter(function (o) { return o.kind === 'js'; }).map(function (o) { return o.code; });
        var found = '';
        printed.concat(mine).forEach(function (t) {
            var m = t.match(EL_MARK);
            if (!m) { return; }
            var hit = null;
            try { hit = doc.querySelector(JSON.parse(m[1]).s); } catch (x) { hit = null; }
            if (hit === el) { found = t; }
        });
        return found ? elUnwrap(found) : '';
    }

    var codeFor = null;
    var cssBefore = null;
    var cssSel = '';
    var cssFor = null;
    function cel() { return sel && sel.isConnected && !isFrame(sel) ? sel : (doc ? doc.body : null); }
    // The selectors this element answers to, as chips; the picked one is edited.
    // keepText: the CSS is being typed in, only the chips are drawn again.
    function fillCss(keepText) {
        var list = [];
        if (cel().id) { list.push('#' + cel().id); }
        (typeof cel().className === 'string' ? cel().className.split(/\s+/) : []).forEach(function (c) { if (c && !/^nst-x/.test(c)) { list.push('.' + c); } });
        if (list.indexOf(cssSel) < 0) { list.unshift(cssSel); }
        var pc = pageClass();
        dock.querySelector('.nst-bd-pane__chips').innerHTML = list.slice(0, 40).map(function (k) {
            // A rule kept to this page reads as "p · this page only"; its full selector is the tip.
            var here = scopedHere(k);
            var shown = here ? k.split(/\s*>\s*|\s+/).pop().replace('.' + pc, '') + ' · ' + __('this page only', 'nibwp') : k;
            return '<span class="nst-bd-pane__chip' + (k === cssSel ? ' is-on' : '') + '"><button type="button" data-bd-sel="' + esc(k) + '"' + (here ? ' data-tip="' + esc(k) + '"' : '') + '>' + esc(shown) + '</button>' + (/^\.[-_a-z0-9]+$/i.test(k) && cel().classList.contains(k.slice(1)) ? '<button type="button" class="nst-bd-pane__unchip" data-bd-unclass="' + esc(k.slice(1)) + '" aria-label="' + esc(sprintf(__('Take %s off this element', 'nibwp'), k)) + '" data-tip="' + esc(__('Remove from this element', 'nibwp')) + '">' + ico('x', 10) + '</button>' : '') + '</span>';
        }).join('');
        setTimeout(paintTools, 0);
        // The site's own CSS is in the editor itself now, not summed up above it.
        themeEl.hidden = true;
        var onChip = dock.querySelector('.nst-bd-pane__chip.is-on');
        if (onChip) { onChip.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
        if (cssAll) { renderAll(); }
        if (keepText) { return; }
        var v = cssLive[cssSel];
        if (!v) {
            cssSite[cssSel] = siteCss(cssSel);
            v = cssSite[cssSel] || (cssSel + ' {\n  \n}\n');
        }
        if (eds.css.ta.value !== v) { eds.css.set(v); }
    }
    var filledFor = null;
    // force: refill even the pane that has the focus (after Ctrl+Enter there).
    function fillCode(force) {
        if (cssBefore !== null) { commitCss(); }
        var el = cel();
        var same = filledFor === el;
        codeFor = el;
        filledFor = el;
        cssBefore = null;
        if (!el) { eds.html.set(''); eds.css.set(''); eds.js.set(''); return; }
        // On the same element, the pane being typed in keeps what is typed.
        var keep = function (ed) { return same && !force && document.activeElement === ed.ta; };
        if (!keep(eds.html)) {
            var h = cleanHtml(el);
            var big = h.length > 80000;
            // Too large to apply safely: shown to read and copy, never applied cut short.
            var text = (big ? '<!-- ' + sprintf(__('Too large to edit here: this shows its first %s characters. Select a smaller part to change its HTML.', 'nibwp'), (80000).toLocaleString()).replace(/--/g, '—') + ' -->\n' : '') + prettyHtml(big ? h.slice(0, 80000) : h);
            eds.html.ta.readOnly = big || el === doc.body;
            eds.html.shown = text;
            if (eds.html.ta.value !== text) { eds.html.set(text); }
        }
        if (cssFor !== el) { cssSel = el === doc.body ? pageScoped('body') : selectorFor(el); cssFor = el; }
        fillCss(keep(eds.css));
        // An unrun script in the pane stays while the same element changes.
        if (!keep(eds.js) && (!same || force || eds.js.ta.value === eds.js.shown)) {
            var js = jsFor(el);
            var jt = js.length ? '/* ' + __('Already on this element:', 'nibwp') + '\n' + js.join('\n').replace(/\*\//g, '* /') + '\n*/\n\n' : '';
            if (el !== doc.body) { jt = '/* ' + __('el is the element you selected. Ctrl+Enter runs this on it right here; saved, it runs on it on the site too.', 'nibwp') + ' */\n' + jt + elScript(el); }
            eds.js.shown = jt;
            if (eds.js.ta.value !== jt) { eds.js.set(jt); }
        }
    }

    function applyCode(lang) {
        if (!cel()) { return; }
        if (lang === 'html') {
            if (cel() === doc.body) { kit.toast(__('This is the whole page, to read and copy. Select an element to change its HTML.', 'nibwp')); return; }
            if (eds.html.ta.readOnly) { kit.toast(__('Too large to edit here. Select a smaller part to change its HTML.', 'nibwp')); return; }
            // Only the layout changed: nothing to replace.
            if (sameHtml(eds.html.ta.value, eds.html.shown)) { return; }
            replaceEl(cel(), eds.html.ta.value);
            fillCode(true);
            return;
        }
        if (lang === 'css') { commitCss(); openPalette(); return; }
        if (lang === 'js') {
            var code = eds.js.ta.value.replace(/\/\*[\s\S]*?\*\//g, '').trim();
            if (!code) { kit.toast(__('Write some JavaScript first.', 'nibwp')); return; }
            try { new Function(code); } catch (x) { if (x && x.name === 'SyntaxError') { kit.toast(sprintf(__('Not run: the script has a mistake. %s', 'nibwp'), x.message), 'bad'); return; } }
            var onEl = cel() !== doc.body;
            if (onEl) { code = elWrap(cel(), code); }
            // The same script again runs again, but is kept once.
            var again = past.some(function (o) { return o.kind === 'js' && o.code === code; });
            var win = doc.defaultView;
            var err = '';
            var onErr = function (ev) { if (!err) { err = (ev && (ev.message || (ev.error && ev.error.message))) || __('Unknown error', 'nibwp'); } };
            var n0 = past.length;
            win.addEventListener('error', onErr);
            try {
                if (again) { var s = doc.createElement('script'); s.textContent = code; doc.body.appendChild(s); s.remove(); }
                else { runJs(code, label(cel(), true)); }
            } catch (x) { err = err || String(x.message || x); }
            win.removeEventListener('error', onErr);
            if (err) {
                // A script that fails here fails on the site too: it is not kept.
                if (!again && past.length > n0 && past[past.length - 1].kind === 'js' && past[past.length - 1].code === code) { past.pop(); changed(); }
                kit.toast(again ? sprintf(__('The script stopped with an error: %s', 'nibwp'), err) : sprintf(__('The script stopped with an error, so it was not kept: %s', 'nibwp'), err), 'bad');
                return;
            }
            kit.toast(again ? __('Script ran again. It stays in your changes once.', 'nibwp') : onEl ? __('Script ran on this element. Save keeps it with the page.', 'nibwp') : __('Script ran on the page.', 'nibwp'), 'ok');
        }
    }

    var cssT = 0;
    function cssInput() {
        if (!cel()) { return; }
        var key = cssSel || selectorFor(cel());
        if (cssBefore === null) { cssBefore = cssLive[key] || ''; }
        cssLive[key] = eds.css.ta.value;
        writeCss();
        paint();
        clearTimeout(cssT);
        cssT = setTimeout(commitCss, 900);
    }
    function commitCss() {
        clearTimeout(cssT);
        if (cssBefore === null || !cel()) { return; }
        var key = cssSel || selectorFor(cel());
        var after = cssLive[key];
        var before = cssBefore;
        cssBefore = null;
        if (after === before) { return; }
        // The first change to the site's own CSS: what was taken out of it is unset.
        if (!before && cssSite[key]) {
            var full = withUnset(cssSite[key], after);
            if (full !== after) {
                after = full;
                cssLive[key] = full;
                writeCss();
                if (cssSel === key && document.activeElement !== eds.css.ta) { eds.css.set(full); }
            }
        }
        setCss(key, label(cel(), true), before, after);
    }

    // ".card", "#hero" or "[data-x=1]": it goes on the element and gets an empty rule.
    function addSel(v) {
        if (!cel()) { return; }
        var m;
        if (v[0] === '#') {
            attrEl(cel(), 'id', v.slice(1));
            cssSel = v;
        } else if ((m = /^\[?([a-z_:][-a-z0-9_:.]*)(?:=["']?([^"'\]]*)["']?)?\]?$/i.exec(v)) && (v[0] === '[' || v.indexOf('=') > -1)) {
            attrEl(cel(), m[1], m[2] || '');
            cssSel = '[' + m[1] + (m[2] ? '="' + m[2] + '"' : '') + ']';
        } else {
            var c = v.replace(/^\./, '').replace(/[^-_a-z0-9]/gi, '');
            if (!c) { return; }
            classEl(cel(), (cel().getAttribute('class') || '') + ' ' + c);
            cssSel = '.' + c;
        }
        cssLive[cssSel] = cssLive[cssSel] || cssSel + ' {\n  \n}\n';
        cssFor = cel();
        fillCss();
        var ta = eds.css.ta;
        ta.focus();
        var at = ta.value.indexOf('{') + 4;
        ta.setSelectionRange(at, at);
    }

    dock.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-bd-sel]');
        if (chip) { commitCss(); cssSel = chip.dataset.bdSel; fillCss(); return; }
        var un = e.target.closest('[data-bd-unclass]');
        if (un && cel()) {
            var c0 = un.dataset.bdUnclass;
            commitCss();
            classEl(cel(), (cel().getAttribute('class') || '').split(/\s+/).filter(function (c) { return c && c !== c0; }).join(' '));
            if (cssSel === '.' + c0) { cssSel = selectorFor(cel()); }
            cssFor = cel();
            fillCss();
            kit.toast(sprintf(__('%s taken off. Its CSS stays in your saved classes, loaded only on pages that use it.', 'nibwp'), '.' + c0), 'ok');
            return;
        }
        var plus = e.target.closest('[data-bd="addsel"]');
        if (plus) { openPalette(); return; }
        var run = e.target.closest('[data-bd-run]');
        if (run) { applyCode(run.dataset.bdRun); return; }
        var cp = e.target.closest('[data-bd-copy]');
        if (cp && navigator.clipboard) { navigator.clipboard.writeText(cp.dataset.bdCopy === 'css' && cssAll ? allText : eds[cp.dataset.bdCopy].ta.value).then(function () { kit.toast(__('Code copied.', 'nibwp'), 'ok'); }, function () { }); return; }
        var tb = e.target.closest('[data-bd-tool]');
        if (tb && cel()) { useTool(toolList[+tb.dataset.bdTool]); return; }
        var a = e.target.closest('[data-bd]');
        if (a && a.dataset.bd === 'dock-close') { setDock(!dockOpen); return; }
        // A folded dock opens again from anywhere on its bar.
        if (!dockOpen && e.target.closest('.nst-bd-pane__head')) { setDock(true); }
    });

    // The CSS tools scroll sideways with the wheel when the pane is narrow.
    var toolsEl = dock.querySelector('.nst-bd-pane__tools');
    toolsEl.addEventListener('wheel', function (e) { if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && toolsEl.scrollWidth > toolsEl.clientWidth) { toolsEl.scrollLeft += e.deltaY; e.preventDefault(); } }, { passive: false });
    // Many classes: the chips scroll sideways with the wheel or a drag, and glide with the mouse.
    var chipsEl = dock.querySelector('.nst-bd-pane__chips');
    slideRow(chipsEl);

    /* A row of chips wider than its box: the wheel and a drag scroll it,
     * moving the mouse along it glides slowly so every chip comes into
     * view (not while dragging, or after the wheel), and data-edge says
     * which side has more, for a fade there. */
    function slideRow(row) {
        var edges = function () {
            var max = row.scrollWidth - row.clientWidth;
            var rtl = getComputedStyle(row).direction === 'rtl';
            var at = rtl ? -row.scrollLeft : row.scrollLeft;
            var before = at > 1;
            var after = at < max - 1;
            row.setAttribute('data-edge', ((rtl ? after : before) ? 'l' : '') + ((rtl ? before : after) ? 'r' : ''));
        };
        row.addEventListener('scroll', edges, { passive: true });
        setTimeout(edges, 0);
        // New chips, or a wider or narrower pane: the fade follows.
        new MutationObserver(edges).observe(row, { childList: true });
        if (window.ResizeObserver) { new ResizeObserver(edges).observe(row); }
        row.addEventListener('wheel', function (e) { if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && row.scrollWidth > row.clientWidth) { row.scrollLeft += e.deltaY; e.preventDefault(); } }, { passive: false });
        row.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) { return; }
            var x0 = e.clientX;
            var l0 = row.scrollLeft;
            var moved = false;
            var move = function (ev) { if (Math.abs(ev.clientX - x0) > 4) { moved = true; row.classList.add('is-dragging'); } row.scrollLeft = l0 - (ev.clientX - x0); };
            // After a drag, the click it ends with is not a chip click; the guard
            // goes right after, so a drag released outside the row eats nothing later.
            var up = function () {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', up);
                row.classList.remove('is-dragging');
                if (!moved) { return; }
                var stop = function (ev) { ev.stopPropagation(); ev.preventDefault(); };
                row.addEventListener('click', stop, true);
                setTimeout(function () { row.removeEventListener('click', stop, true); }, 0);
            };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
        });
        var goal = null;
        var raf = 0;
        var step = function () {
            raf = 0;
            if (goal === null || row.classList.contains('is-dragging')) { return; }
            var d = goal - row.scrollLeft;
            if (Math.abs(d) < 0.5) { return; }
            row.scrollLeft += d * 0.06;
            raf = requestAnimationFrame(step);
        };
        row.addEventListener('mousemove', function (e) {
            var max = row.scrollWidth - row.clientWidth;
            if (max <= 0 || e.buttons) { return; }
            var r = row.getBoundingClientRect();
            var x = Math.max(0, Math.min(1, (e.clientX - r.left - 20) / Math.max(1, r.width - 40)));
            goal = getComputedStyle(row).direction === 'rtl' ? (x - 1) * max : x * max;
            if (REDUCED) { row.scrollLeft = goal; return; }
            if (!raf) { raf = requestAnimationFrame(step); }
        });
        row.addEventListener('mouseleave', function () { goal = null; });
        row.addEventListener('wheel', function () { goal = null; }, { passive: true });
    }

    // ---- 11: the three panes get wider or narrower by their edges
    var paneW = store('nibwp-bd-panes') || null;
    function applyPanes() { if (paneW) { dock.style.gridTemplateColumns = paneW.map(function (w) { return w + 'fr'; }).join(' '); } }
    applyPanes();
    dock.addEventListener('pointerdown', function (e) {
        var g = e.target.closest('[data-bd-pgrip]');
        if (!g) { return; }
        e.preventDefault();
        var i = +g.dataset.bdPgrip;
        var panes = Array.prototype.slice.call(dock.querySelectorAll('.nst-bd-pane'));
        var w = panes.map(function (p) { return p.getBoundingClientRect().width; });
        var x0 = e.clientX;
        var frame = kit.frame();
        if (frame) { frame.style.pointerEvents = 'none'; }
        document.documentElement.classList.add('nst-bd-sizing');
        var move = function (ev) {
            var dx = ev.clientX - x0;
            var a = Math.max(160, w[i] + dx);
            var b = Math.max(160, w[i + 1] - (a - w[i]));
            a = w[i] + w[i + 1] - b;
            var nw = w.slice();
            nw[i] = a;
            nw[i + 1] = b;
            var sum = nw.reduce(function (x, y) { return x + y; }, 0);
            paneW = nw.map(function (x) { return Math.round(x / sum * 300) / 100; });
            applyPanes();
        };
        var up = function () {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            if (frame) { frame.style.pointerEvents = ''; }
            document.documentElement.classList.remove('nst-bd-sizing');
            store('nibwp-bd-panes', paneW);
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    });
    dock.addEventListener('dblclick', function (e) { if (e.target.closest('[data-bd-pgrip]')) { paneW = null; dock.style.gridTemplateColumns = ''; store('nibwp-bd-panes', null); } });

    // Drag the dock's top edge to make it taller or shorter.
    dock.querySelector('[data-bd-dockgrip]').addEventListener('pointerdown', function (e) {
        e.preventDefault();
        var y0 = e.clientY;
        var h0 = dock.offsetHeight;
        var frame = kit.frame();
        if (frame) { frame.style.pointerEvents = 'none'; }
        var move = function (ev) { root.style.setProperty('--bd-dock', Math.max(140, Math.min(window.innerHeight * 0.7, h0 + y0 - ev.clientY)) + 'px'); };
        var up = function () { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); if (frame) { frame.style.pointerEvents = ''; } paint(); showSize(); };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    });

    function setDock(open) {
        dockOpen = !!open;
        store('nibwp-bd-dock', dockOpen);
        inspect();
        if (dockOpen) { setTimeout(function () { var t = dock.querySelector('[data-pane="css"] .nst-cd__ta'); if (t) { t.focus({ preventScroll: true }); } }, 30); }
    }

    var resetSure = 0;
    bar.addEventListener('click', function (e) {
        var ins = e.target.closest('[data-bd-ins]');
        if (ins && doc && !blocked) {
            if (ins.dataset.bdIns === 'layout') { setDrawing(!drawing); return; }
            var sp = defaultSpot();
            addNew({ el: ins.dataset.bdIns }, sp.el, sp.pos);
            return;
        }
        var a = e.target.closest('[data-bd]');
        if (!a) { return; }
        switch (a.dataset.bd) {
            case 'undo': undo(); break;
            case 'redo': redo(); break;
            case 'history': if (hist.hidden) { histOpen(false); } else { histClose(true); } break;
            case 'save': save(a); break;
            case 'close': stop(); break;
            case 'dock':
                if (sel && isFrame(sel)) { sel = null; paint(); }
                pageCode = !sel;
                setDock(!dockOpen || dock.hidden);
                break;
            case 'view': viewMenu(a); break;
            case 'sizes': sizesOpen(); break;
            case 'reset':
                if (Date.now() - resetSure > 3000) {
                    resetSure = Date.now();
                    kit.toast(tplOn()
                        ? __('Click Reset again to remove the elements, text and scripts the Builder saved on this template, on every page that uses it. Saved class styles stay: other pages share them.', 'nibwp')
                        : pending()
                        ? __('Click Reset again to remove the elements, text and scripts the Builder saved on this page, and your unsaved changes too. Saved class styles stay: other pages share them.', 'nibwp')
                        : __('Click Reset again to remove the elements, text and scripts the Builder saved on this page. Saved class styles stay: other pages share them.', 'nibwp'));
                    return;
                }
                resetSure = 0;
                var info = kit.pageInfo();
                kit.api('builder-reset', { method: 'POST', body: { post_id: info.postId, url: info.url, tpl: tplOn() ? tplNow().id : '' } }).then(function () {
                    freshPage(tplOn() ? __('Builder changes removed from this template.', 'nibwp') : __('Builder changes removed from this page.', 'nibwp'));
                    histRefresh();
                }, function (err) {
                    kit.toast(err && err.status && err.message ? err.message : __('Could not reset the page. Nothing changed: try again.', 'nibwp'), 'bad');
                });
                break;
        }
    });
    function files() { pFiles(); }
    fileIn.addEventListener('change', function () {
        if (fileIn.files.length) { kit.addFiles(fileIn.files); }
        fileIn.value = '';
        setTimeout(files, 50);
        setTimeout(files, 1500);
        setTimeout(files, 4000);
        pop.querySelector('textarea').focus();
    });
    /* ------------------------------------------------------------------
     * Save: every change on the page, to NibWP in one request. The page
     * refreshes with the saved result only when you pause.
     * ---------------------------------------------------------------- */

    var saving = null;
    var lastTry = null;
    function save() {
        // One save at a time: a second Ctrl+S while one is on its way would store it all twice.
        if (saving) { return; }
        if (cfg.direct === false) { kit.toast(__('Your account cannot save code straight to this site. Ask NibWP to make the change instead.', 'nibwp'), 'bad'); return; }
        // What is being typed counts: the text on the page, the field or pane with the caret.
        if (typingEl) { typeDone(true); }
        var a = document.activeElement;
        if (a && a.closest && a.closest('.nst-bd, .nst-bd-dock, .nst-bd-pop') && /^(TEXTAREA|INPUT|SELECT)$/.test(a.tagName)) { a.blur(); }
        commitCss();
        var ops = past.filter(saves);
        if (!ops.length) { kit.toast(__('Nothing to save yet.', 'nibwp')); return; }
        // A retry of the very same changes keeps its id, so the site stores them once.
        var same = lastTry && lastTry.ops.length === ops.length && lastTry.ops.every(function (o, i) { return o === ops[i]; });
        var saveId = same ? lastTry.id : 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        lastTry = { ops: ops, id: saveId };
        var baseEl = doc && doc.querySelector('meta[name="nibwp-builder-base"]');
        var info = kit.pageInfo();
        var css = {};
        var js = [];
        var patches = [];
        ops.forEach(function (o) {
            if (o.kind === 'css') { css[o.key] = cssLive[o.key] || ''; }
            else if (o.kind === 'js') { js.push(o.code); }
            else { patches = patches.concat(o.patch()); }
            (o.keys || []).forEach(function (k) { css[k] = cssLive[k] || ''; });
        });
        // A rule with nothing in it is no rule: not saved (and one emptied is taken off).
        Object.keys(css).forEach(function (k) { if (!declsIn(css[k])) { css[k] = ''; } });
        var btn = bar.querySelector('[data-bd="save"]');
        btn.classList.add('is-saving');
        saving = ops;
        kit.api('builder-save', { method: 'POST', body: { css: css, js: js, patches: patches, post_id: info.postId, url: info.url, tpl: tplOn() ? tplNow().id : '', save_id: saveId, base: baseEl && !tplOn() ? baseEl.getAttribute('content') : '' } }).then(function () {
            saving = null;
            lastTry = null;
            cfg.css = Object.assign({}, cfg.css || {}, css);
            // Only what was sent: an edit made while it was on its way stays to save.
            past = past.filter(function (o) { return ops.indexOf(o) < 0; });
            future = [];
            changed();
            btn.classList.remove('is-saving');
            btn.classList.add('is-saved');
            setTimeout(function () { btn.classList.remove('is-saved'); }, 1600);
            kit.toast(sprintf(_n('Saved %d change. Live on your site now.', 'Saved %d changes. Live on your site now.', ops.length, 'nibwp'), ops.length), 'ok');
            histRefresh();
        }, function (err) {
            saving = null;
            btn.classList.remove('is-saving');
            // The site's own reason when it gave one (too large, changed meanwhile, signed out).
            kit.toast(err && err.status && err.message ? err.message : __('Could not save. Nothing was lost: try again.', 'nibwp'), 'bad');
        });
    }
    function banner(text, refresh) {
        savingEl.hidden = !text;
        savingEl.innerHTML = text ? '<span class="nst-spark-lite" aria-hidden="true"></span><span>' + esc(text) + '</span>' + (refresh ? '<button type="button" class="nst-bd__btn" data-bd="refresh">' + esc(__('Refresh now', 'nibwp')) + '</button>' : '') : '';
    }

    // The page as the site has it now (after a reset, or a save undone or
    // redone): the steps kept here go, and the page loads again.
    function freshPage(msg) {
        past = [];
        future = [];
        changed();
        kit.toast(msg, 'ok');
        var f = kit.frame();
        if (f && f.contentWindow) { f.contentWindow.location.reload(); }
    }

    /* ------------------------------------------------------------------
     * Saved changes: the page's last saves, newest first. Changes replay
     * in order, so only the newest save still in force can be undone; the
     * one undone last can be redone while nothing was saved after it.
     * ---------------------------------------------------------------- */

    var hist = document.createElement('div');
    hist.className = 'nst-bd-hist';
    hist.hidden = true;
    hist.setAttribute('role', 'dialog');
    hist.setAttribute('aria-label', __('Saved changes', 'nibwp'));
    root.appendChild(hist);
    var histBtn = bar.querySelector('[data-bd="history"]');
    var histList = [];
    var histKept = 30;
    var histBusy = false;

    function histAgo(iso) {
        var t = Date.parse(iso || '');
        if (!t) { return ''; }
        var s = Math.max(0, (Date.now() - t) / 1000);
        if (s < 50) { return __('just now', 'nibwp'); }
        if (s < 3600) { return sprintf(_n('%d min ago', '%d min ago', Math.round(s / 60), 'nibwp'), Math.round(s / 60)); }
        if (s < 86400) { return sprintf(_n('%d hour ago', '%d hours ago', Math.round(s / 3600), 'nibwp'), Math.round(s / 3600)); }
        return new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    }
    // "3 changes, 1 style": what a save did, in words.
    function histWhat(c) {
        var out = [];
        if (c.patches) { out.push(sprintf(_n('%d change', '%d changes', c.patches, 'nibwp'), c.patches)); }
        if (c.css) { out.push(sprintf(_n('%d style', '%d styles', c.css, 'nibwp'), c.css)); }
        if (c.js) { out.push(sprintf(_n('%d script', '%d scripts', c.js, 'nibwp'), c.js)); }
        return out.join(', ');
    }
    function histRender(msg) {
        var had = hist.contains(document.activeElement);
        var body = msg || !histList.length
            ? '<p class="nst-bd-hist__empty">' + esc(msg || __('No saves on this page yet.', 'nibwp')) + '</p>'
            : '<ol class="nst-bd-hist__list">' + histList.map(function (b) {
                var act = b.can_undo ? '<button type="button" class="nst-bd__btn" data-bd-hist="undo" data-id="' + esc(b.id) + '">' + ico('undo', 13) + esc(__('Undo this save', 'nibwp')) + '</button>'
                    : b.can_redo ? '<button type="button" class="nst-bd__btn is-ghost" data-bd-hist="redo" data-id="' + esc(b.id) + '">' + ico('redo', 13) + esc(__('Redo', 'nibwp')) + '</button>'
                    : '<small class="nst-bd-hist__note">' + esc(b.undone ? __('Undone', 'nibwp') : __('Undo the newer ones first', 'nibwp')) + '</small>';
                var who = [histAgo(b.at), b.user || ''].concat(b.can_redo ? [__('Undone', 'nibwp')] : []).filter(function (x) { return x; }).join(' · ');
                return '<li class="nst-bd-hist__it' + (b.undone ? ' is-undone' : '') + '"><span class="nst-bd-hist__what"><b>' + esc(histWhat(b.counts || {})) + '</b><small>' + esc(who) + '</small>' +
                    (b.css_keys && b.css_keys.length ? '<code>' + esc(b.css_keys.join(' ')) + '</code>' : '') + '</span>' + act + '</li>';
            }).join('') + '</ol>';
        hist.innerHTML = '<header class="nst-bd-hist__head"><b>' + esc(__('Saved changes', 'nibwp')) + '</b><button type="button" class="nst-bd__ib" data-bd-hist="close" data-tip="' + esc(__('Close', 'nibwp')) + '" aria-label="' + esc(__('Close', 'nibwp')) + '">' + ico('x', 14) + '</button></header>' +
            body + '<p class="nst-bd-hist__foot">' + esc(sprintf(__('Only the last %d saves of a page can be undone.', 'nibwp'), histKept)) + '</p>';
        if (had) { histFocus(); }
    }
    function histPlace() {
        var r = histBtn.getBoundingClientRect();
        hist.style.top = Math.max(8, r.top - hist.offsetHeight - 10) + 'px';
        hist.style.left = Math.max(8, Math.min(r.left, window.innerWidth - hist.offsetWidth - 8)) + 'px';
    }
    // Into the panel: the save that can be undone (or redone), else Close.
    function histFocus() {
        var f = hist.querySelector('[data-bd-hist="undo"]:not(:disabled), [data-bd-hist="redo"]:not(:disabled)') || hist.querySelector('[data-bd-hist="close"]');
        if (f) { f.focus({ preventScroll: true }); }
    }
    function histLoad() {
        var info = kit.pageInfo();
        return kit.api('builder-history?post_id=' + (info.postId || 0) + '&url=' + encodeURIComponent(info.url || '') + (tplOn() ? '&tpl=' + encodeURIComponent(tplNow().id) : '')).then(function (r) {
            histList = (r && r.history) || [];
            histKept = (r && r.kept) || histKept;
            return histList;
        });
    }
    function histRefresh() {
        if (hist.hidden) { return; }
        histLoad().then(function () {
            if (!hist.hidden) { histRender(); histPlace(); }
        }, function (err) {
            if (!hist.hidden) { histRender(err && err.status && err.message ? err.message : __('Could not load the saved changes. Try again.', 'nibwp')); }
        });
    }
    // $have: the list was just read, show it as it is.
    function histOpen(have) {
        if (!hist.hidden) { histFocus(); return; }
        hist.hidden = false;
        histBtn.setAttribute('aria-expanded', 'true');
        histRender(have ? '' : __('Loading…', 'nibwp'));
        histPlace();
        histFocus();
        if (!have) { histRefresh(); }
    }
    function histClose(back) {
        if (hist.hidden) { return; }
        var had = hist.contains(document.activeElement);
        hist.hidden = true;
        histBtn.setAttribute('aria-expanded', 'false');
        if (back && had) { histBtn.focus(); }
    }
    function histDo(what, id, btn) {
        if (histBusy || !id) { return; }
        if (saving) { kit.toast(__('A save is still on its way. Try again in a moment.', 'nibwp')); return; }
        // The page loads again after it: anything not saved yet would be lost.
        if (typingEl) { typeDone(true); }
        clsCommit();
        commitCss();
        if (past.length) { kit.toast(__('Save or undo your unsaved changes first: the page reloads after this.', 'nibwp'), 'bad'); return; }
        var info = kit.pageInfo();
        histBusy = true;
        btn.disabled = true;
        kit.api('builder-' + what, { method: 'POST', body: { post_id: info.postId, url: info.url, tpl: tplOn() ? tplNow().id : '', batch: id } }).then(function (r) {
            histBusy = false;
            histList = (r && r.history) || [];
            // The canvas shows each rule as the site has it again.
            var css = (r && r.css) || {};
            cfg.css = Object.assign({}, cfg.css || {});
            Object.keys(css).forEach(function (k) {
                if (css[k] === null) { delete cfg.css[k]; delete cssLive[k]; } else { cfg.css[k] = css[k]; cssLive[k] = css[k]; }
            });
            if (marks && marks.live) { writeCss(); }
            // The busy button let go of the focus: it goes to what can be undone next.
            histRender();
            if (!hist.hidden) { histFocus(); }
            freshPage(what === 'undo' ? __('Save undone. The page is as it was before it.', 'nibwp') : __('Save redone.', 'nibwp'));
        }, function (err) {
            histBusy = false;
            btn.disabled = false;
            if (!hist.hidden && btn.isConnected) { btn.focus({ preventScroll: true }); }
            kit.toast(err && err.status && err.message ? err.message : what === 'undo' ? __('Could not undo that save. Nothing changed: try again.', 'nibwp') : __('Could not redo that save. Nothing changed: try again.', 'nibwp'), 'bad');
            // The list may be out of date (another tab, another person): read it again.
            histRefresh();
        });
    }
    // Ctrl+Z with nothing left to undo on the page: a save before can be.
    function offerSaved() {
        if (!on || !doc || blocked) { return; }
        if (!hist.hidden) { histFocus(); return; }
        histLoad().then(function (list) {
            if (!list.some(function (b) { return b.can_undo; })) { kit.toast(__('Nothing left to undo here.', 'nibwp')); return; }
            histOpen(true);
            kit.toast(__('Your earlier changes are saved: undo a save here.', 'nibwp'));
        }, function () { kit.toast(__('Nothing left to undo here.', 'nibwp')); });
    }
    hist.addEventListener('click', function (e) {
        var b = e.target.closest('[data-bd-hist]');
        if (!b) { return; }
        if (b.dataset.bdHist === 'close') { histClose(true); return; }
        histDo(b.dataset.bdHist, b.dataset.id, b);
    });
    hist.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); histClose(true); }
    });
    document.addEventListener('pointerdown', function (e) { if (!hist.hidden && !hist.contains(e.target) && !e.target.closest('[data-bd="history"]')) { histClose(false); } }, true);

    function busy() {
        var a = document.activeElement;
        var typing = a && a.closest && a.closest('.nst-bd, .nst-bd-dock, .nst-bd-pop') && /^(TEXTAREA|INPUT)$/.test(a.tagName);
        // A wireframe not yet sent to NibWP is work in progress too: a refresh would lose it.
        var plan = !!(doc && doc.querySelector('.nst-x-bd-frame:not(.is-sent)'));
        return pending() > 0 || plan || !pop.hidden || !!typing || !!typingEl || moving || !!saving || !!dragEl || !!dragNew || ups > 0;
    }
    // Called by Agent View before it reloads or moves the page for the agent.
    function hold(fn) {
        if (!on || bypass) { return false; }
        keep = { url: kit.pageInfo().url, scroll: doc ? doc.defaultView.scrollY : 0, path: sel && sel.isConnected ? kit.cssPath(sel) : '', view: view };
        if (!busy()) { return false; }
        held = fn;
        banner(pending() ? __('Saved. Your newer changes are still only on the page: save them, and it refreshes after.', 'nibwp') : doc && doc.querySelector('.nst-x-bd-frame:not(.is-sent)') ? __('Saved. Your wireframe is still only on the page: send it to NibWP or remove it, and it refreshes after.', 'nibwp') : __('Saved. The page refreshes as soon as you pause.', 'nibwp'), true);
        return true;
    }
    var idleT = 0;
    function idle() {
        clearTimeout(idleT);
        if (!held) { return; }
        idleT = setTimeout(release, 2500);
    }
    function release(force) {
        if (!held || (!force && busy())) { return; }
        // Held for a page out of sight: it waits in that tab's session (see leaveDoc).
        var fn = held;
        held = null;
        bypass = true;
        try { fn(); } finally { bypass = false; }
    }

    // After a refresh: the same place on the page, the same element.
    function restore() {
        var k = keep;
        keep = null;
        if (!k || !doc || kit.pageInfo().url !== k.url) { return; }
        doc.defaultView.scrollTo({ top: k.scroll, behavior: 'instant' });
        if (k.view) { setView(k.view); }
        var el = null;
        try { el = k.path ? doc.querySelector(k.path) : null; } catch (x) { el = null; }
        if (el) { select(el, true); }
    }

    /* ------------------------------------------------------------------
     * Search in each tab: it picks the first match as you type.
     * ---------------------------------------------------------------- */

    var hits = [];
    var hitAt = 0;
    left.addEventListener('input', function (e) {
        var f = e.target.closest('[data-bd-find]');
        if (!f) { return; }
        var q = f.value.trim().toLowerCase();
        var n = f.parentNode.querySelector('.nst-bd__find-n');
        if (f.dataset.bdFind === 'add') {
            var shown = 0;
            left.querySelectorAll('.nst-bd__palette [data-find]').forEach(function (t) { var ok = !q || t.dataset.find.indexOf(q) > -1; t.hidden = !ok; t.classList.remove('is-hit'); if (ok) { shown++; } });
            left.querySelectorAll('.nst-bd__grp').forEach(function (g) { var any = g.querySelector('[data-find]:not([hidden])'); g.hidden = !!q && !any; g.classList.toggle('is-searching', !!q); });
            var first = q && left.querySelector('.nst-bd__palette [data-find]:not([hidden])');
            if (first) { first.classList.add('is-hit'); }
            n.textContent = q ? String(shown) : '';
        } else if (f.dataset.bdFind === 'tree') {
            hits = [];
            hitAt = 0;
            if (q && doc) {
                Array.prototype.some.call(doc.body.getElementsByTagName('*'), function (el) {
                    if (SKIP.test(el.localName) || ours(el)) { return false; }
                    var hay = (el.localName + ' #' + el.id + ' .' + (typeof el.className === 'string' ? el.className.split(/\s+/).join(' .') : '') + ' ' + (el.children.length ? '' : (el.textContent || ''))).toLowerCase();
                    if (hay.indexOf(q) > -1) { hits.push(el); }
                    return hits.length >= 300;
                });
            }
            n.textContent = q ? (hits.length ? '1/' + hits.length : '0') : '';
            if (hits[0]) { select(hits[0], true); }
        } else if (f.dataset.bdFind === 'templates') {
            var any2 = false;
            tplsEl.querySelectorAll('.nst-bd__tpl').forEach(function (r) { var ok = !q || r.textContent.toLowerCase().indexOf(q) > -1; r.hidden = !ok; r.classList.remove('is-hit'); if (ok && q && !any2) { r.classList.add('is-hit'); any2 = true; } });
            tplsEl.classList.toggle('is-finding', !!q);
        } else if (f.dataset.bdFind === 'classes') {
            left.querySelectorAll('.nst-bd__cls .nst-bd__clsrow[data-find]').forEach(function (r) { r.hidden = !!q && r.dataset.find.indexOf(q) < 0; });
            // The variables are found in the whole list, not only in the rows drawn.
            var vb = left.querySelector('.nst-bd__vars');
            if (vb) { vb.innerHTML = varsHtml(q); }
        }
    });
    left.addEventListener('keydown', function (e) {
        var f = e.target.closest('[data-bd-find]');
        if (!f || e.key !== 'Enter') { return; }
        e.preventDefault();
        if (f.dataset.bdFind === 'add') { var t = left.querySelector('.nst-bd__palette .is-hit'); if (t) { t.click(); } }
        else if (f.dataset.bdFind === 'tree' && hits.length) { hitAt = (hitAt + (e.shiftKey ? hits.length - 1 : 1)) % hits.length; select(hits[hitAt], true); f.parentNode.querySelector('.nst-bd__find-n').textContent = (hitAt + 1) + '/' + hits.length; f.focus(); }
        else if (f.dataset.bdFind === 'templates') { var r = tplsEl.querySelector('.nst-bd__tpl.is-hit [data-bd-tpl="open"]'); if (r) { r.click(); } }
    });

    /* ------------------------------------------------------------------
     * Templates: each kind of content, joined to the template that shows it.
     * ---------------------------------------------------------------- */

    function renderTemplates() {
        var list = cfg.templates || [];
        var last = '';
        var first = list[0] && list[0].group;
        tplsEl.innerHTML = list.map(function (t, i) {
            var g = t.group || '';
            var isShut = shut['tpl:' + g] === undefined ? g !== first : shut['tpl:' + g];
            var n = list.filter(function (x) { return x.group === g; }).length;
            var head = g && g !== last ? '<li class="nst-bd__tpl-group"><button type="button" data-bd-tgrp="' + esc(g) + '" aria-expanded="' + !isShut + '">' + ico('down', 13) + '<span>' + esc(g) + '</span><small>' + n + '</small></button></li>' : '';
            last = g || last;
            return head + '<li class="nst-bd__tpl' + (focusTpl === t ? ' is-on' : '') + (tplNow() && tplNow().id === t.tplid ? ' is-editing' : '') + '" data-kind="' + esc(t.kind) + '" data-tplid="' + esc(t.tplid || '') + '"' + (isShut ? ' data-shut' : '') + '>' +
                '<button type="button" class="nst-bd__tpl-shot' + (t.shot ? ' has-img' : t.url ? ' is-wait' : '') + '" data-shot="' + i + '"' + (t.url ? ' data-bd-tpl="open" data-i="' + i + '" data-tip="' + esc(__('Open', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Open a %s on the canvas', 'nibwp'), t.label)) + '"' : ' tabindex="-1" aria-hidden="true" disabled') + '>' +
                    (t.shot ? '<img src="' + esc(t.shot) + '" alt="" loading="lazy" decoding="async">' : ico('tpl', 16)) + '</button>' +
                '<div class="nst-bd__tpl-main"><b class="nst-bd__tpl-label">' + esc(t.label) + (t.content ? ' <small>' + esc(t.content) + '</small>' : '') + '</b>' +
                    '<span class="nst-bd__tpl-tpl">' + ico('tpl', 12) + (t.tpl ? '<button type="button" class="nst-bd__tpl-name" data-bd-tpl="rename" data-i="' + i + '" data-tip="' + esc(__('Rename', 'nibwp')) + '">' + esc(t.tpl.title) + '</button>' : '<span>' + esc(t.template) + '</span>') + '</span>' +
                    '<small class="nst-bd__tpl-src">' + esc(t.source) + '</small></div>' +
                '<div class="nst-bd__tpl-acts">' +
                    (t.kind === 'single' && t.type ? '<button type="button" class="nst-bd__ib" data-bd-tpl="pick" data-i="' + i + '" aria-expanded="false" data-tip="' + esc(__('Find one to open', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Find a %s to open on the canvas', 'nibwp'), t.label)) + '">' + ico('search', 14) + '</button>'
                        : t.url ? '<button type="button" class="nst-bd__ib" data-bd-tpl="open" data-i="' + i + '" data-tip="' + esc(__('Open', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Open a %s on the canvas', 'nibwp'), t.label)) + '">' + ico('pageopen', 14) + '</button>' : '') +
                    (t.tplid ? (t.url ? '<button type="button" class="nst-bd__ib" data-bd-tpl="edit" data-i="' + i + '" data-tip="' + esc(__('Edit template', 'nibwp')) + '" aria-label="' + esc(sprintf(/* translators: %s: the template's name */ __('Edit the %s template here, on the canvas', 'nibwp'), t.template)) + '">' + ico('sketch', 14) + '</button>'
                        : '<button type="button" class="nst-bd__ib" disabled data-tip="' + esc(__('No page uses it yet, so there is nothing to show it on', 'nibwp')) + '" aria-label="' + esc(__('No page uses it yet, so there is nothing to show it on', 'nibwp')) + '">' + ico('sketch', 14) + '</button>') : '') +
                    (t.kind === 'custom' ? '<button type="button" class="nst-bd__ib" data-bd-tpl="page" data-i="' + i + '" data-tip="' + esc(__('New page with it', 'nibwp')) + '" aria-label="' + esc(sprintf(__('New page with the %s template', 'nibwp'), t.label)) + '">' + ico('plus', 14) + '</button>' : '') +
                    '<button type="button" class="nst-bd__ib" data-bd-tpl="ask" data-i="' + i + '" data-tip="' + esc(__('Ask NibWP', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Ask NibWP about the %s template', 'nibwp'), t.label)) + '"><i class="nst-bd__nib"></i></button>' +
                    (t.tpl ? '<button type="button" class="nst-bd__ib is-danger" data-bd-tpl="del" data-i="' + i + '" data-tip="' + esc(t.tpl.own ? __('Delete', 'nibwp') : __('Reset to the theme’s', 'nibwp')) + '" aria-label="' + esc(sprintf(t.tpl.own ? __('Delete the template %s', 'nibwp') : __('Reset the template %s to the theme’s', 'nibwp'), t.tpl.title)) + '">' + ico(t.tpl.own ? 'trash' : 'reset', 14) + '</button>'
                        : '<button type="button" class="nst-bd__ib" disabled data-tip="' + esc(__('Comes with the theme', 'nibwp')) + '" aria-label="' + esc(__('Comes with the theme: it cannot be deleted', 'nibwp')) + '">' + ico('trash', 14) + '</button>') +
                '</div>' +
                (t.kind === 'single' && t.type ? '<div class="nst-bd__tpl-pick" hidden><input type="search" placeholder="' + esc(sprintf(__('Find a %s…', 'nibwp'), t.label.toLowerCase())) + '" aria-label="' + esc(sprintf(__('Find a %s', 'nibwp'), t.label)) + '"><ul></ul></div>' : '') +
            '</li>';
        }).join('') || '<li class="nst-bd__hint">' + esc(__('No templates found.', 'nibwp')) + '</li>';
        if (boot.icon) { left.style.setProperty('--nb-icon', 'url("' + boot.icon + '")'); }
        if (tplsEl.offsetParent) { setTimeout(shotQueue, 400); }
    }

    /* Template pictures: a page that uses the template, drawn small once
     * and kept on the site (drawn again when the template changes, or after
     * a week). The page loads out of sight, without the admin bar, one at a
     * time while the Templates tab is open; the browser draws it into an
     * image itself (the page as SVG, painted on a canvas). */
    var shotQ = [];
    var shotBusy = false;
    var shotTried = {};
    function shotQueue() {
        (cfg.templates || []).forEach(function (t) {
            var k = t.shotk + t.shotv;
            if (t.url && t.shotk && (!t.shot || t.stale) && !shotTried[k]) { shotTried[k] = 1; shotQ.push(k); }
        });
        shotNext();
    }
    function shotFind(k) { return (cfg.templates || []).find(function (x) { return x.shotk + x.shotv === k; }); }
    // Templates left or the Builder closed: no more pages load; the ones not
    // drawn yet wait for the next time the tab opens.
    function shotStop() {
        shotQ.forEach(function (k) { delete shotTried[k]; });
        shotQ = [];
    }
    function shotNext() {
        if (shotBusy || !shotQ.length) { return; }
        if (!on || !tplsEl.offsetParent) { shotStop(); return; }
        var t = shotFind(shotQ.shift());
        if (!t) { shotNext(); return; }
        shotBusy = true;
        var f = document.createElement('iframe');
        f.className = 'nst-bd-shotframe';
        f.setAttribute('aria-hidden', 'true');
        f.setAttribute('tabindex', '-1');
        f.setAttribute('title', '');
        var over = false;
        var done = function (img) {
            if (over) { return; }
            over = true;
            clearTimeout(timer);
            f.remove();
            var after = function () { shotBusy = false; setTimeout(shotNext, 250); };
            if (!img) { shotPaint(t); after(); return; }
            kit.api('template-shot', { method: 'POST', body: { key: t.shotk, v: t.shotv, image: img } }).then(function (r) {
                (cfg.templates || []).forEach(function (x) { if (x.shotk === t.shotk) { x.shot = r.shot; x.stale = false; } });
                shotPaint(t);
                after();
            }, function () { shotPaint(t); after(); });
        };
        var timer = setTimeout(function () { done(null); }, 30000);
        f.onload = function () {
            setTimeout(function () {
                var d = null;
                try { d = f.contentDocument; } catch (x) { d = null; }
                if (!d || !d.body) { done(null); return; }
                snapDoc(d, 1280, 800, 320).then(done, function () { done(null); });
            }, 1600);
        };
        f.src = t.url + (t.url.indexOf('?') > -1 ? '&' : '?') + 'nibwp_shot=1';
        document.body.appendChild(f);
    }
    function shotPaint(t) {
        var i = (cfg.templates || []).indexOf(shotFind(t.shotk + t.shotv) || t);
        var box = tplsEl.querySelector('.nst-bd__tpl-shot[data-shot="' + i + '"]');
        if (!box) { return; }
        box.classList.remove('is-wait');
        var cur = (cfg.templates || [])[i];
        if (cur && cur.shot) {
            box.classList.add('has-img');
            box.innerHTML = '<img src="' + esc(cur.shot) + '" alt="" decoding="async">';
        }
    }
    // A document's first screen as a JPEG data URL, outW pixels wide.
    function snapDoc(d, w, h, outW) {
        var win = d.defaultView;
        var same = function (u) { try { return new URL(u, d.baseURI).origin === location.origin; } catch (x) { return false; } };
        var abs = function (u, base) { try { return new URL(u, base).href; } catch (x) { return u; } };
        var toData = function (u) {
            return fetch(u, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) { throw new Error('http'); } return r.blob(); }).then(function (b) {
                return new Promise(function (ok) { var fr = new FileReader(); fr.onload = function () { ok(String(fr.result)); }; fr.onerror = function () { ok(''); }; fr.readAsDataURL(b); });
            }).catch(function () { return ''; });
        };
        // The styles as text, their addresses made absolute; web fonts left out.
        var css = [];
        var walk = function (rules, base) {
            Array.prototype.forEach.call(rules || [], function (r) {
                if (r.styleSheet) { var sub = null; try { sub = r.styleSheet.cssRules; } catch (x) { sub = null; } walk(sub, r.styleSheet.href || base); return; }
                if (r.type === 5) { return; }
                // In the picture the root is the SVG, not <html>: :root becomes a class on the
                // copied <html>, with the same weight, so rules win as they do on the page.
                css.push(r.cssText.replace(/url\((['"]?)([^'")]+)\1\)/g, function (m, q, u) { return /^(data:|#)/.test(u) ? m : 'url("' + abs(u, base) + '")'; }).replace(/html:root/g, 'html.nbshot-root').replace(/:root\b/g, '.nbshot-root'));
            });
        };
        Array.prototype.forEach.call(d.styleSheets, function (sh) { var rules = null; try { rules = sh.cssRules; } catch (x) { rules = null; } walk(rules, sh.href || d.baseURI); });
        // Pictures in the first screen travel inside the image.
        var jobs = [];
        Array.prototype.slice.call(d.body.querySelectorAll('*'), 0, 5000).forEach(function (el) {
            if (jobs.length >= 40) { return; }
            var r = el.getBoundingClientRect();
            if (r.bottom < 0 || r.top > h || r.width < 2 || r.height < 2) { return; }
            if (el.tagName === 'IMG') {
                if (el.currentSrc && same(el.currentSrc)) { el.setAttribute('data-nbshot', jobs.length); jobs.push({ img: true, url: el.currentSrc }); }
                return;
            }
            var bg = win.getComputedStyle(el).backgroundImage;
            var m = /url\(["']?([^"')]+)/.exec(bg || '');
            if (m && same(m[1]) && !/^data:/.test(m[1])) { el.setAttribute('data-nbshot', jobs.length); jobs.push({ img: false, url: abs(m[1], d.baseURI) }); }
        });
        var clone = d.documentElement.cloneNode(true);
        clone.classList.add('nbshot-root');
        Array.prototype.forEach.call(d.querySelectorAll('[data-nbshot]'), function (el) { el.removeAttribute('data-nbshot'); });
        Array.prototype.forEach.call(clone.querySelectorAll('script,noscript,iframe,template,link,style,#wpadminbar,video,audio,object,embed'), function (el) { el.remove(); });
        var bodyBg = win.getComputedStyle(d.body).backgroundColor;
        var htmlBg = win.getComputedStyle(d.documentElement).backgroundColor;
        return Promise.all(jobs.map(function (j) { return toData(j.url); })).then(function (datas) {
            Array.prototype.forEach.call(clone.querySelectorAll('img'), function (im) {
                var n = im.getAttribute('data-nbshot');
                im.removeAttribute('srcset');
                im.removeAttribute('sizes');
                im.removeAttribute('loading');
                im.setAttribute('src', n !== null && datas[+n] ? datas[+n] : 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==');
            });
            Array.prototype.forEach.call(clone.querySelectorAll('[data-nbshot]'), function (el) {
                var n = +el.getAttribute('data-nbshot');
                el.removeAttribute('data-nbshot');
                if (el.tagName !== 'IMG' && datas[n]) { el.style.backgroundImage = 'url("' + datas[n] + '")'; }
            });
            var st = d.createElement('style');
            st.textContent = css.join('\n') + '\nhtml{margin-top:0!important;width:' + w + 'px;height:' + h + 'px;overflow:hidden}#wpadminbar{display:none!important}';
            (clone.querySelector('head') || clone).appendChild(st);
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '"><foreignObject x="0" y="0" width="' + w + '" height="' + h + '">' + new XMLSerializer().serializeToString(clone) + '</foreignObject></svg>';
            return new Promise(function (ok, no) {
                var img = new Image();
                img.onload = function () {
                    var c = document.createElement('canvas');
                    c.width = outW;
                    c.height = Math.round(outW * h / w);
                    var g = c.getContext('2d');
                    g.imageSmoothingQuality = 'high';
                    g.fillStyle = /rgba\(0, 0, 0, 0\)|transparent/.test(bodyBg) ? (/rgba\(0, 0, 0, 0\)|transparent/.test(htmlBg) ? '#fff' : htmlBg) : bodyBg;
                    g.fillRect(0, 0, c.width, c.height);
                    g.drawImage(img, 0, 0, c.width, c.height);
                    try { ok(c.toDataURL('image/jpeg', 0.8)); } catch (x) { no(x); }
                };
                img.onerror = function () { no(new Error('svg')); };
                img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
            });
        });
    }

    // A redraw replaces the buttons: the keyboard goes back to the same one.
    function tplRefocus(b, had) {
        if (!had) { return; }
        var same = Array.prototype.filter.call(tplsEl.querySelectorAll('button'), function (x) {
            return b.dataset.bdTgrp !== undefined ? x.dataset.bdTgrp === b.dataset.bdTgrp : x.dataset.bdTpl === b.dataset.bdTpl && x.dataset.i === b.dataset.i && x.classList.contains('nst-bd__tpl-shot') === b.classList.contains('nst-bd__tpl-shot');
        })[0];
        if (same) { same.focus({ preventScroll: true }); }
    }
    tplsEl.addEventListener('click', function (e) {
        var tg = e.target.closest('[data-bd-tgrp]');
        var had = document.activeElement === (tg || e.target.closest('[data-bd-tpl]'));
        if (tg) { shut['tpl:' + tg.dataset.bdTgrp] = tg.getAttribute('aria-expanded') === 'true'; store('nibwp-bd-groups', shut); renderTemplates(); tplRefocus(tg, had); return; }
        var b = e.target.closest('[data-bd-tpl]');
        if (!b) { return; }
        var t = (cfg.templates || [])[+b.dataset.i];
        if (!t) { return; }
        if (b.dataset.bdTpl === 'rename' && t.tpl) { tplRenameStart(b, t); return; }
        if (b.dataset.bdTpl === 'del' && t.tpl) {
            var card = b.closest('.nst-bd__tpl');
            var own = t.tpl.own;
            askDel(card, own ? __('Delete it?', 'nibwp') : __('Reset to the theme’s?', 'nibwp'), own ? 'trash' : 'reset', own ? __('Delete', 'nibwp') : __('Reset', 'nibwp'), function () {
                card.classList.add(own ? 'is-gone' : 'is-going');
                tplRemove(t);
            });
            return;
        }
        if (b.dataset.bdTpl === 'pick') { tplPick(b, t); return; }
        if (b.dataset.bdTpl === 'edit') { tplEdit(t); return; }
        focusTpl = t;
        sel = null;
        paint();
        renderTemplates();
        tplRefocus(b, had);
        inspect();
        if (b.dataset.bdTpl === 'open' && t.url) { bridge.openTab(t.url); }
        if (b.dataset.bdTpl === 'page') { makeForm('page', t.slug); return; }
        if (b.dataset.bdTpl === 'ask' && kit.prefill) {
            // The question flies from the button into the chat, like every ask.
            var q = sprintf(__('About the %1$s template (%2$s): ', 'nibwp'), t.label, t.template);
            kit.prefill(q);
            if (bridge.throwTo) { bridge.throwTo(tplsEl.querySelector('[data-bd-tpl="ask"][data-i="' + b.dataset.i + '"]') || b, q); }
        }
    });

    // Delete asks once, right on its card: "Delete it?" with Cancel and the
    // bin, like the wireframe tiles. Yes takes the card away at once.
    function askDel(card, text, icon, verb, go) {
        var open = card.querySelector('.nst-bd__sure');
        if (open) { open.remove(); return; }
        hideTip();
        // Cancel gives the keyboard back to the button that asked.
        var from = card.contains(document.activeElement) ? document.activeElement : null;
        var back = function () { if (from && from.isConnected) { from.focus({ preventScroll: true }); } };
        var ask = document.createElement('div');
        ask.className = 'nst-bd__sure';
        ask.setAttribute('role', 'group');
        ask.setAttribute('aria-label', text);
        ask.innerHTML = '<b>' + esc(text) + '</b>' +
            '<button type="button" data-ask="no" data-tip="' + esc(__('Cancel', 'nibwp')) + '" aria-label="' + esc(__('Cancel', 'nibwp')) + '">' + ico('x', 12) + '</button>' +
            '<button type="button" class="is-del" data-ask="yes" data-tip="' + esc(verb) + '" aria-label="' + esc(verb) + '">' + ico(icon, 12) + '</button>';
        card.appendChild(ask);
        ask.querySelector('[data-ask="no"]').focus();
        ask.addEventListener('click', function (e) {
            e.stopPropagation();
            var b = e.target.closest('[data-ask]');
            if (!b) { return; }
            hideTip();
            ask.remove();
            if (b.dataset.ask === 'yes') { go(); } else { back(); }
        });
        ask.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); ask.remove(); back(); } });
    }

    /* A change the site takes at once (a template renamed or removed, a
     * class renamed everywhere) becomes an undo step only once the site has
     * it. These run one at a time, in order; one the site refuses leaves
     * the undo steps as they were. */
    var siteQ = Promise.resolve();
    function siteQueue(fn) {
        var p = siteQ.then(fn);
        siteQ = p.then(null, function () { });
        return p;
    }
    function siteRun(op, dir) {
        siteQueue(function () {
            return op[dir]().then(null, function () {
                // undo() or redo() moved the step already: it goes back.
                var from = dir === 'undoSite' ? future : past;
                var i = from.lastIndexOf(op);
                if (i < 0) { return; }
                from.splice(i, 1);
                (dir === 'undoSite' ? past : future).push(op);
                changed();
            });
        });
    }
    function siteAct(op) {
        var steps = past;
        op.change = function () { return ''; };
        op.redo = function () { siteRun(op, 'redoSite'); };
        op.undo = function () { siteRun(op, 'undoSite'); };
        return siteQueue(function () {
            return op.redoSite().then(function (r) {
                steps.push(op);
                if (steps === past) { future = []; changed(); }
                return r;
            });
        });
    }
    // A template renamed in place, or removed at once: both are a step
    // Ctrl+Z takes back, done on the site straight away (not on Save).
    function tplCall(body) {
        return kit.api('template-edit', { method: 'POST', body: body }).then(function (r) { cfg.templates = r.templates || cfg.templates; renderTemplates(); return r; }, function (x) { kit.toast(x.message || __('Could not change the template.', 'nibwp'), 'bad'); renderTemplates(); throw x; });
    }
    function tplRenameStart(b, t) {
        var inp = document.createElement('input');
        inp.className = 'nst-bd__tpl-in';
        inp.value = t.tpl.title;
        inp.setAttribute('aria-label', __('Template name', 'nibwp'));
        b.replaceWith(inp);
        inp.focus();
        inp.select();
        var done = false;
        var finish = function (keep) {
            if (done) { return; }
            done = true;
            var v = inp.value.trim();
            if (!keep || !v || v === t.tpl.title) { renderTemplates(); return; }
            var id = t.tpl.id;
            var before = t.tpl.title;
            siteAct({
                label: sprintf(__('rename the template %s', 'nibwp'), before),
                redoSite: function () { return tplCall({ id: id, do: 'rename', title: v }); },
                undoSite: function () { return tplCall({ id: id, do: 'rename', title: before }); }
            }).then(function () { kit.toast(sprintf(__('Renamed to “%s”. Ctrl+Z takes it back.', 'nibwp'), v), 'ok'); }, function () { });
        };
        inp.addEventListener('keydown', function (e) {
            e.stopPropagation();
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    // Find a page, post or product of this type, right in its card, and open it.
    var pickT = 0;
    function tplPick(b, t) {
        var box = b.closest('.nst-bd__tpl').querySelector('.nst-bd__tpl-pick');
        var open = box.hidden;
        tplsEl.querySelectorAll('.nst-bd__tpl-pick').forEach(function (x) { x.hidden = true; });
        tplsEl.querySelectorAll('[data-bd-tpl="pick"]').forEach(function (x) { x.setAttribute('aria-expanded', 'false'); });
        if (!open) { return; }
        box.hidden = false;
        b.setAttribute('aria-expanded', 'true');
        var inp = box.querySelector('input');
        var ul = box.querySelector('ul');
        var load = function () {
            var q = inp.value.trim();
            ul.innerHTML = '<li class="nst-bd__hint">' + esc(__('Loading…', 'nibwp')) + '</li>';
            kit.api('content?type=' + encodeURIComponent(t.type) + (q ? '&s=' + encodeURIComponent(q) : '')).then(function (r) {
                if (inp.value.trim() !== q) { return; }
                ul.innerHTML = (r && r.items || []).map(function (it) {
                    return '<li><button type="button" data-bd-pickurl="' + esc(it.url) + '"><b>' + esc(it.title) + '</b><small>' + esc(it.status) + '</small></button></li>';
                }).join('') || '<li class="nst-bd__hint">' + esc(q ? __('Nothing matches.', 'nibwp') : __('Nothing yet.', 'nibwp')) + '</li>';
            }, function () { ul.innerHTML = '<li class="nst-bd__hint">' + esc(__('Could not load the list.', 'nibwp')) + '</li>'; });
        };
        if (!inp.dataset.wired) {
            inp.dataset.wired = '1';
            inp.addEventListener('input', function () { clearTimeout(pickT); pickT = setTimeout(load, 220); });
            inp.addEventListener('keydown', function (e) {
                e.stopPropagation();
                if (e.key === 'Escape') { e.preventDefault(); box.hidden = true; b.setAttribute('aria-expanded', 'false'); b.focus(); }
                if (e.key === 'Enter') { e.preventDefault(); var first = ul.querySelector('[data-bd-pickurl]'); if (first) { first.click(); } }
            });
            ul.addEventListener('click', function (e) {
                var it = e.target.closest('[data-bd-pickurl]');
                if (it) { box.hidden = true; b.setAttribute('aria-expanded', 'false'); bridge.openTab(it.dataset.bdPickurl); }
            });
        }
        load();
        inp.focus();
    }
    function tplRemove(t) {
        var id = t.tpl.id;
        var name = t.tpl.title;
        var own = t.tpl.own;
        siteAct({
            label: sprintf(__('remove the template %s', 'nibwp'), name),
            redoSite: function () { return tplCall({ id: id, do: 'trash' }); },
            undoSite: function () { return tplCall({ id: id, do: 'restore' }); }
        }).then(function () { kit.toast(sprintf(own ? __('Template “%s” deleted. Ctrl+Z brings it back.', 'nibwp') : __('Template “%s” is the theme’s again. Ctrl+Z brings your version back.', 'nibwp'), name), 'ok'); }, function () { });
    }

    /* ------------------------------------------------------------------
     * New pages and templates, right from the Builder: a page opens on the
     * canvas as a draft to build; a template is made for a content type,
     * or kept unassigned to pick per page.
     * ---------------------------------------------------------------- */

    var mkForm = left.querySelector('.nst-bd__mkform');
    var mkFrom = null;
    // Cancelled, the form gives the keyboard back to the button that opened it.
    function mkClose(back) {
        var had = mkForm.contains(document.activeElement);
        mkForm.hidden = true;
        if (back && had && mkFrom && mkFrom.isConnected) { mkFrom.focus({ preventScroll: true }); }
    }
    function makeForm(kind, tpl) {
        if (!mkForm.hidden && mkForm.dataset.kind === kind && !tpl) { mkForm.hidden = true; return; }
        mkFrom = document.activeElement && document.activeElement !== document.body && !mkForm.contains(document.activeElement) ? document.activeElement : left.querySelector('[data-bd-make="template"]');
        mkForm.dataset.kind = kind;
        mkForm.dataset.tpl = tpl || '';
        var types = (cfg.templates || []).filter(function (t) { return t.kind === 'single' && t.type; });
        var customs = (cfg.templates || []).filter(function (t) { return t.kind === 'custom'; });
        mkForm.innerHTML = kind === 'page'
            ? '<input type="text" name="title" required placeholder="' + esc(__('Page title, e.g. Night tours', 'nibwp')) + '" aria-label="' + esc(__('Page title', 'nibwp')) + '">' +
              (customs.length ? '<select name="tpl" aria-label="' + esc(__('Template', 'nibwp')) + '"><option value="">' + esc(__('Default template', 'nibwp')) + '</option>' + customs.map(function (t) { return '<option value="' + esc(t.slug) + '"' + (t.slug === tpl ? ' selected' : '') + '>' + esc(t.label) + '</option>'; }).join('') + '</select>' : '') +
              '<div class="nst-bd__mkbar"><button type="button" class="nst-bd__btn" data-bd-mk="cancel">' + esc(__('Cancel', 'nibwp')) + '</button><button type="submit" class="nst-bd__btn is-main">' + esc(__('Create and open', 'nibwp')) + '</button></div>'
            : '<input type="text" name="name" required placeholder="' + esc(__('Template name, e.g. Tour landing', 'nibwp')) + '" aria-label="' + esc(__('Template name', 'nibwp')) + '">' +
              '<fieldset class="nst-bd__types"><legend>' + esc(__('Used for (none: pick it per page)', 'nibwp')) + '</legend>' + types.map(function (t) { return '<label><input type="checkbox" name="types" value="' + esc(t.type) + '"><span>' + esc(t.label) + '</span></label>'; }).join('') + '</fieldset>' +
              '<div class="nst-bd__mkbar"><button type="button" class="nst-bd__btn is-ghost" data-bd-mk="cancel">' + ico('x', 12) + '<span>' + esc(__('Cancel', 'nibwp')) + '</span></button><button type="submit" class="nst-bd__btn is-main">' + ico('plus', 12) + '<span>' + esc(__('Create', 'nibwp')) + '</span></button></div>';
        mkForm.hidden = false;
        mkForm.querySelector('input').focus();
    }
    mkForm.addEventListener('click', function (e) { if (e.target.closest('[data-bd-mk="cancel"]')) { mkClose(true); } });
    mkForm.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); mkClose(true); } });
    mkForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = {};
        data.types = [];
        Array.prototype.forEach.call(mkForm.elements, function (x) { if (x.name === 'types') { if (x.checked) { data.types.push(x.value); } } else if (x.name) { data[x.name] = x.value.trim(); } });
        var go = mkForm.querySelector('[type="submit"]');
        go.disabled = true;
        if (mkForm.dataset.kind === 'page') {
            kit.api('page-new', { method: 'POST', body: { title: data.title, template: data.tpl || '' } }).then(function (r) {
                go.disabled = false;
                if (!r || !r.url) { kit.toast(__('Could not create the page.', 'nibwp'), 'bad'); return; }
                mkForm.hidden = true;
                bridge.openTab(r.url);
                setView('add');
                kit.toast(sprintf(__('Draft “%s” is open. Drop a wireframe on it, or ask NibWP to build it.', 'nibwp'), data.title), 'ok');
            }, function (x) { go.disabled = false; kit.toast((x && x.status && x.message) || __('Could not create the page.', 'nibwp'), 'bad'); });
            return;
        }
        kit.api('template-new', { method: 'POST', body: { name: data.name, types: data.types } }).then(function (r) {
            go.disabled = false;
            if (r && r.templates) {
                cfg.templates = r.templates;
                mkForm.hidden = true;
                renderTemplates();
                kit.toast(r.message || sprintf(__('Template “%s” made. Open a page that uses it to build it, or ask NibWP.', 'nibwp'), data.name), 'ok');
                return;
            }
            if (r && r.ask) {
                mkForm.hidden = true;
                kit.sendText(r.ask);
                kit.toast(__('Your theme keeps templates in files: NibWP makes this one for you.', 'nibwp'), 'ok');
                return;
            }
            kit.toast((r && r.message) || __('Could not make the template.', 'nibwp'), 'bad');
        }, function (x) { go.disabled = false; kit.toast((x && x.message) || __('Could not make the template.', 'nibwp'), 'bad'); });
    });
    /* ------------------------------------------------------------------
     * Classes: the saved classes, each loaded only where it is used, to
     * read, change or delete; and the site's CSS variables to copy.
     * ---------------------------------------------------------------- */

    var clsOpen = null;
    // The variables whose names hold q, from the whole list; 400 drawn at most.
    function varsHtml(q) {
        var root0 = doc ? doc.defaultView.getComputedStyle(doc.documentElement) : null;
        q = String(q || '').trim().toLowerCase();
        return cssVars().filter(function (v) { return !q || v.toLowerCase().indexOf(q) > -1; }).slice(0, 400).map(function (v) {
            var val = root0 ? root0.getPropertyValue(v).trim() : '';
            var colour = /^(#|rgb|hsl|oklch|color\()/i.test(val);
            return '<button type="button" class="nst-bd__var" data-var="' + esc(v) + '" data-tip="' + esc(__('Copy var()', 'nibwp')) + '">' + (colour ? '<i style="background:' + esc(val) + '"></i>' : '<i class="is-none"></i>') + '<code>' + esc(v) + '</code><small>' + esc(val.slice(0, 28)) + '</small></button>';
        }).join('');
    }
    function renderClasses() {
        var box = left.querySelector('.nst-bd__cls');
        if (!box) { return; }
        var keys = Object.keys(cssLive).filter(function (k) { return (cssLive[k] || '').trim(); }).sort();
        // ".btn:hover" or ".x::before" is on the page when ".btn" or ".x" is.
        var used = function (k) {
            var base = k.replace(/::?[a-z-]+(\([^)]*\))?/gi, '').replace(/[\s>+~,]+$/, '').trim();
            try { return !!(doc && base && doc.querySelector(base)); } catch (x) { return false; }
        };
        var vars = cssVars();
        var q = ((left.querySelector('[data-bd-find="classes"]') || {}).value || '').trim().toLowerCase();
        box.innerHTML = '<form class="nst-bd__clsadd"><input spellcheck="false" autocomplete="off" maxlength="80" placeholder="' + esc(__('New .class or #id', 'nibwp')) + '" aria-label="' + esc(__('New class or ID', 'nibwp')) + '"><button type="submit" class="nst-bd__btn">' + ico('plus', 12) + '<span>' + esc(__('Add', 'nibwp')) + '</span></button></form>' +
            '<p class="nst-bd__cls-h">' + esc(__('Saved classes', 'nibwp')) + '<small>' + keys.length + '</small></p>' +
            (keys.length ? keys.map(function (k) {
                var on = used(k);
                return '<div class="nst-bd__clsrow' + (clsOpen === k ? ' is-open' : '') + '" data-find="' + esc(k.toLowerCase()) + '" data-cls="' + esc(k) + '">' +
                    '<div class="nst-bd__clsline">' + (/^[.#]-?[_a-zA-Z][-_a-zA-Z0-9]*$/.test(k) ? '<button type="button" class="nst-bd__clsname" data-cls-act="rename" data-tip="' + esc(__('Rename everywhere', 'nibwp')) + '">' + esc(k) + '</button>' : '<code>' + esc(k) + '</code>') + '<i class="nst-bd__clsuse' + (on ? ' is-used' : '') + '" role="img" data-tip="' + esc(on ? __('On this page', 'nibwp') : __('Not on this page', 'nibwp')) + '" aria-label="' + esc(on ? __('On this page', 'nibwp') : __('Not on this page', 'nibwp')) + '">' + ico(on ? 'eye' : 'eyeoff', 13) + '</i>' +
                    '<button type="button" class="nst-bd__ib" data-cls-act="edit" data-tip="' + esc(__('Edit CSS', 'nibwp')) + '" aria-label="' + esc(__('Edit its CSS', 'nibwp')) + '">' + ico('code', 13) + '</button>' +
                    '<button type="button" class="nst-bd__ib is-danger" data-cls-act="del" data-tip="' + esc(__('Delete', 'nibwp')) + '" aria-label="' + esc(__('Delete', 'nibwp')) + '">' + ico('trash', 13) + '</button></div>' +
                    (clsOpen === k ? '<textarea class="nst-bd__clsed" rows="6" spellcheck="false">' + esc(cssLive[k]) + '</textarea>' : '') +
                    '</div>';
            }).join('') : '<p class="nst-bd__none">' + esc(__('Classes you add in the CSS pane are kept here, and load only on pages that use them.', 'nibwp')) + '</p>') +
            '<p class="nst-bd__cls-h">' + esc(__('CSS variables', 'nibwp')) + '<small>' + vars.length + '</small></p>' +
            '<div class="nst-bd__vars">' + varsHtml(q) + '</div>';
        if (q) { box.querySelectorAll('.nst-bd__clsrow[data-find]').forEach(function (r) { r.hidden = r.dataset.find.indexOf(q) < 0; }); }
    }
    left.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cls-act]');
        var v = e.target.closest('[data-var]');
        if (v) { var t = 'var(' + v.dataset.var + ')'; if (navigator.clipboard) { navigator.clipboard.writeText(t).then(function () { kit.toast(sprintf(__('%s copied.', 'nibwp'), t), 'ok'); }, function () { }); } return; }
        if (!b) { return; }
        var k = b.closest('[data-cls]').dataset.cls;
        if (b.dataset.clsAct === 'rename') { clsRenameStart(b, k); return; }
        if (b.dataset.clsAct === 'edit') { clsOpen = clsOpen === k ? null : k; renderClasses(); var ed = left.querySelector('.nst-bd__clsed'); if (ed) { ed.focus(); } return; }
        if (b.dataset.clsAct === 'del') {
            // "Delete it?" right on its row, like templates and wireframes.
            askDel(b.closest('.nst-bd__clsline'), __('Delete it?', 'nibwp'), 'trash', __('Delete', 'nibwp'), function () {
                clsCommit();
                setCss(k, k, cssLive[k] || '', '');
                kit.toast(sprintf(__('%s deleted from the saved classes. Save to keep it that way; Ctrl+Z brings it back.', 'nibwp'), k), 'ok');
            });
        }
    });
    /* A saved class's CSS edited here shows as you type and is one undo step
     * from the CSS it had when you started to when you leave the field. */
    var clsBefore = null;
    function clsCommit() {
        var b = clsBefore;
        clsBefore = null;
        if (!b || b.doc !== doc) { return; }
        var after = cssLive[b.k] || '';
        if (after !== b.v) { setCss(b.k, b.k, b.v, after); }
    }
    left.addEventListener('change', function (e) { if (e.target.closest('.nst-bd__clsed')) { clsCommit(); } });
    left.addEventListener('input', function (e) {
        var ed = e.target.closest('.nst-bd__clsed');
        if (!ed) { return; }
        var k = ed.closest('[data-cls]').dataset.cls;
        if (!clsBefore || clsBefore.k !== k || clsBefore.doc !== doc) { clsCommit(); clsBefore = { k: k, v: cssLive[k] || '', doc: doc }; }
        cssLive[k] = ed.value;
        writeCss();
    });

    /* ------------------------------------------------------------------
     * BEM: what the Builder makes is named block__element. A Section,
     * Stack or Box asks for its block's name as it lands; what lands
     * inside a block is named after it. Right-click any element to name
     * it and everything inside it the same way: inline styles move into
     * the class rules, on the site's tokens where a value matches one.
     * ---------------------------------------------------------------- */

    var BEM_SKIP = /^(script|style|link|meta|noscript|template|br|wbr|source|track|param|strong|em|b|i|u|s|mark|code|abbr|sup|sub|kbd|option|optgroup|thead|tbody|tfoot|tr|td|th|caption|colgroup|col|path|g|circle|rect|line|polyline|polygon|ellipse|use|defs|symbol|stop)$/;
    var BEM_LEAF = /^(svg|table|select|picture|iframe|video|audio|canvas|object)$/;
    // Class names themes, plugins and other frameworks style too: a block
    // the Builder names on its own never takes one of these bare.
    var RESERVED = /^(button|btn|menu|nav|navbar|card|table|form|container|row|col|column|columns|grid|wrapper|content|widget|title|list|icon|image|link|text|heading|entry|post|page|site|header|footer|main|sidebar|divider|spacer|media)$/;

    function clsOf(n) { return (n.getAttribute('class') || '').split(/\s+/).filter(Boolean); }
    function reEsc(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function bemSlug(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/^(\d)/, 'b-$1').slice(0, 40).replace(/-+$/, ''); }
    // ".hero", ".hero__x", ".hero:hover", ".hero .y" — never ".hero-2".
    function bemRe(name) { return new RegExp('\\.' + reEsc(name) + '(?=__|--|[^\\w-]|$)', 'g'); }
    function hasRules(name) {
        var re = bemRe(name);
        return Object.keys(cssLive).some(function (k) { re.lastIndex = 0; return re.test(k) && (cssLive[k] || '').trim() !== ''; });
    }
    // The Builder's block on this element: a class with saved rules, no "__"
    // and no "--" (card--featured is a variant of card, not a block).
    function blockOf(n) { return clsOf(n).filter(function (c) { return c.indexOf('__') < 0 && c.indexOf('--') < 0 && !/^(nst-x|wp-|is-|has-)/.test(c) && hasRules(c); })[0] || ''; }
    // The block a new element lands in: the nearest one up the page.
    function blockAt(n) {
        for (var p = n; p && p.nodeType === 1 && p !== doc.body && p !== doc.documentElement; p = p.parentElement) {
            var b = blockOf(p);
            if (b) { return b; }
            var e = clsOf(p).filter(function (c) { return /^[a-z][a-z0-9-]*__[a-z0-9-]+$/.test(c) && c.split('__')[0].indexOf('--') < 0 && hasRules(c.split('__')[0]); })[0];
            if (e) { return e.split('__')[0]; }
        }
        return '';
    }
    function bemPart(n) {
        var t = n.localName;
        if (/^h[1-6]$/.test(t)) { return 'heading'; }
        if (/^(p|span|small|time|figcaption|cite|dt|dd)$/.test(t)) { return 'text'; }
        if (/^(img|picture|figure)$/.test(t)) { return 'image'; }
        if (t === 'svg') { return 'icon'; }
        if (t === 'button' || (t === 'a' && (/button|btn/i.test(n.getAttribute('class') || '') || n.getAttribute('role') === 'button'))) { return 'button'; }
        if (t === 'a') { return 'link'; }
        if (/^(ul|ol|dl|menu)$/.test(t)) { return 'list'; }
        if (/^(li|details|article)$/.test(t)) { return 'item'; }
        if (t === 'summary') { return 'title'; }
        if (/^(input|textarea|select)$/.test(t)) { return 'input'; }
        if (/^(form|label|nav|table|header|footer|aside)$/.test(t)) { return t; }
        if (t === 'blockquote') { return 'quote'; }
        if (/^(iframe|video|audio|canvas|object|embed)$/.test(t)) { return 'media'; }
        if (t === 'hr') { return 'divider'; }
        var p = n.parentElement;
        return p && p.children.length === 1 ? 'inner' : 'group';
    }
    // A name to start from: the block it already is, its id or class, its
    // heading's words, or what it is.
    function bemSuggest(n) {
        var b = blockOf(n);
        if (b) { return b; }
        var own = bemSlug(n.id) || bemSlug(clsOf(n).filter(function (c) { return !/^(wp-|is-|has-|nst-x|align|elementor|et_|brxe|bricks)/.test(c) && c.indexOf('__') < 0; })[0]);
        if (own && !RESERVED.test(own)) { return own; }
        var h = n.querySelector('h1, h2, h3, h4');
        var words = h ? bemSlug(h.textContent).split('-').filter(function (w) { return w.length > 2; }).slice(0, 2).join('-') : '';
        var pre = sitePrefix() || 'nb-';
        return words || own || ({ section: 'section', header: 'top', footer: 'bottom', nav: pre + 'menu', article: pre + 'card', form: pre + 'form', ul: pre + 'list', ol: pre + 'list' }[n.localName] || 'box');
    }
    // Why a name might not be the one: [kind, message, a better name].
    function bemCheck(name, mine, own) {
        if (!/^[a-z][a-z0-9-]*$/.test(name)) { return ['bad', __('Use small letters, digits and dashes, starting with a letter.', 'nibwp'), bemSlug(name) || 'box']; }
        var used = false;
        try { used = Array.prototype.some.call(doc.querySelectorAll('.' + CSS.escape(name)), function (x) { return !mine || !mine.contains(x); }); } catch (x) { }
        if (hasRules(name) && !own) {
            var n = 2;
            while (hasRules(name + '-' + n)) { n++; }
            return ['reuse', sprintf(__('“.%s” already has saved styles: this uses them.', 'nibwp'), name), name + '-' + n];
        }
        if (RESERVED.test(name) || used) { return ['clash', sprintf(__('Themes and plugins often style “.%s” too, so styles could mix.', 'nibwp'), name), (sitePrefix() || 'nb-') + name]; }
        return null;
    }

    /* Values on the site's tokens: a size only when a token is that size on
     * the canvas now (within half a pixel), a colour within a hair. What you
     * typed never changes size or colour. */
    var tokCache = null;
    var c2d = null;
    function rgbOf(v) {
        if (!c2d) { var cv = document.createElement('canvas'); cv.width = cv.height = 1; c2d = cv.getContext('2d', { willReadFrequently: true }); }
        if (!v || !CSS.supports('color', v) || /^(transparent|inherit|initial|unset|currentcolor)$/i.test(v)) { return null; }
        c2d.clearRect(0, 0, 1, 1);
        c2d.fillStyle = '#000';
        c2d.fillStyle = v;
        c2d.fillRect(0, 0, 1, 1);
        var d = c2d.getImageData(0, 0, 1, 1).data;
        return d[3] < 250 ? null : [d[0], d[1], d[2]];
    }
    function tokenTable() {
        var vw = doc.defaultView.innerWidth;
        if (tokCache && tokCache.doc === doc && tokCache.vw === vw) { return tokCache; }
        var rootCs = doc.defaultView.getComputedStyle(doc.documentElement);
        var t = { doc: doc, vw: vw, rem: parseFloat(rootCs.fontSize) || 16, space: [], text: [], radius: [], color: [] };
        var probe = doc.createElement('div');
        probe.className = 'nst-x-bd-probe';
        probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;height:0;left:-9999px;top:0';
        doc.body.appendChild(probe);
        var cs = doc.defaultView.getComputedStyle(probe);
        var steps = ['xs', 's', 'm', 'l', 'xl', 'xxl'];
        // A fluid token is the size it has at the canvas's width now.
        var size = function (fam, n) {
            if (!rootCs.getPropertyValue(n).trim()) { return; }
            probe.style.width = 'var(' + n + ')';
            var w = parseFloat(cs.width);
            if (w > 0) { t[fam].push([n, w]); }
        };
        steps.forEach(function (s) { size('space', '--space-' + s); size('space', '--section-space-' + s); size('text', '--text-' + s); size('radius', '--radius-' + s); });
        [1, 2, 3, 4, 5, 6].forEach(function (h) { size('text', '--h' + h); });
        ['primary', 'secondary', 'tertiary', 'accent', 'base', 'neutral', 'success', 'warning', 'danger', 'info'].forEach(function (c) {
            ['', '-ultra-light', '-light', '-semi-light', '-semi-dark', '-dark', '-ultra-dark'].forEach(function (s) {
                var n = '--' + c + s;
                if (!rootCs.getPropertyValue(n).trim()) { return; }
                probe.style.color = 'var(' + n + ')';
                var rgb = rgbOf(cs.color);
                if (rgb) { t.color.push([n, rgb]); }
            });
        });
        ['--white', '--black'].forEach(function (n) { var v = rootCs.getPropertyValue(n).trim(); var rgb = v && rgbOf(v); if (rgb) { t.color.push([n, rgb]); } });
        probe.remove();
        tokCache = t;
        return t;
    }
    function snapPart(fam, v) {
        var t = tokenTable();
        if (fam === 'color') {
            var rgb = rgbOf(v);
            if (!rgb) { return v; }
            var best = null;
            t.color.forEach(function (c) { var d = Math.hypot(c[1][0] - rgb[0], c[1][1] - rgb[1], c[1][2] - rgb[2]); if (d <= 6 && (!best || d < best[1])) { best = [c[0], d]; } });
            return best ? 'var(' + best[0] + ', ' + v + ')' : v;
        }
        // em follows the element's own font size: it is never swapped for a token.
        if (!/^\d*\.?\d+(px|rem)$/.test(v) || parseFloat(v) === 0) { return v; }
        var px = /rem$/.test(v) ? parseFloat(v) * t.rem : parseFloat(v);
        var hit = null;
        (t[fam] || []).forEach(function (s) { var d = Math.abs(s[1] - px); if (d <= 0.5 && (!hit || d < hit[1])) { hit = [s[0], d]; } });
        return hit ? 'var(' + hit[0] + ', ' + v + ')' : v;
    }
    function snapValue(prop, value) {
        if (/var\(|!important/.test(value)) { return value; }
        var fam = /^(padding|margin|gap|row-gap|column-gap|inset|top|right|bottom|left)/.test(prop) ? 'space' : prop === 'font-size' ? 'text' : /radius/.test(prop) ? 'radius' : /(^|-)(color|background|fill|stroke)$|^background|^border(-(top|right|bottom|left|inline|block)(-(start|end))?)?$|^outline$/.test(prop) ? 'color' : '';
        if (!fam) { return value; }
        var parts = [];
        var depth = 0;
        var cur = '';
        value.split('').forEach(function (ch) {
            if (ch === '(') { depth++; } else if (ch === ')') { depth--; }
            if (/\s/.test(ch) && depth === 0) { if (cur) { parts.push(cur); } cur = ''; } else { cur += ch; }
        });
        if (cur) { parts.push(cur); }
        return parts.map(function (p) { return fam === 'color' ? (rgbOf(p) ? snapPart('color', p) : p) : snapPart(fam, p); }).join(' ');
    }
    function parseStyle(text) {
        var out = [];
        var depth = 0;
        var cur = '';
        var push = function () { var i = cur.indexOf(':'); if (i > 0) { var p = cur.slice(0, i).trim().toLowerCase(); var v = cur.slice(i + 1).trim(); if (p && v) { out.push([p, v]); } } cur = ''; };
        String(text || '').split('').forEach(function (ch) {
            if (ch === '(') { depth++; } else if (ch === ')') { depth--; }
            if (ch === ';' && depth <= 0) { push(); } else { cur += ch; }
        });
        push();
        return out;
    }
    // Where the body of the first plain rule in a CSS text is: [start, end],
    // past any @media or other at-rule before it; null when there is none.
    function ruleSpan(text) {
        var depth = 0;
        var open = -1;
        var at = false;
        var from = 0;
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (ch === '{') {
                if (depth === 0) { at = /^\s*@/.test(text.slice(from, i)); open = i; }
                depth++;
            } else if (ch === '}' && depth > 0) {
                depth--;
                if (depth === 0) {
                    if (!at) { return [open + 1, i]; }
                    from = i + 1;
                }
            }
        }
        return null;
    }
    // The declarations of that first plain rule.
    function topDecls(text) {
        text = String(text || '');
        var s = ruleSpan(text);
        return s ? parseStyle(text.slice(s[0], s[1])) : [];
    }
    // A CSS text that declares anything, the last declaration with or without its ";".
    function declsIn(text) { return /[^{};\s][^{};]*:[^{};]+[;}]/.test(String(text || '').replace(/^[^{]*\{/, '')); }
    // A rule with these declarations added to its first plain rule, the ones
    // it already has kept; nothing lands inside an @media.
    function ruleWith(key, decls, base) {
        var text = base && base.trim() ? base : key + ' {\n}\n';
        var span = ruleSpan(text);
        if (!span) { text = key + ' {\n}\n' + text; span = ruleSpan(text); }
        var have = parseStyle(text.slice(span[0], span[1])).map(function (d) { return d[0]; });
        var add = decls.filter(function (d) { return have.indexOf(d[0]) < 0; }).map(function (d) { return '  ' + d[0] + ': ' + d[1] + ';'; });
        if (!add.length) { return text; }
        var head = text.slice(0, span[1]).replace(/\s*$/, '');
        if (!/[;{}]$/.test(head)) { head += ';'; }
        return head + '\n' + add.join('\n') + '\n' + text.slice(span[1]);
    }
    // Another element on the page that has this class (not one of these).
    function usedBy(cls, skip) {
        try { return Array.prototype.some.call(doc.querySelectorAll('.' + CSS.escape(cls)), function (x) { return skip.indexOf(x) < 0; }); } catch (x) { return true; }
    }

    /* The names it had before. An element name of the same block (card__image
     * becoming card__photo) goes with its rules when nothing else on the page
     * has it, and stays on it when something does (or the new name already
     * has rules); one with no rules just goes. "Only this element" (leave)
     * takes it out of its block; its variants follow the new name when the
     * block's styles are copied to it. */
    function bemSwap(el, name, leave) {
        var out = { drop: [], move: [], keep: [], ren: {} };
        var fam = name.indexOf('__') > 0 ? name.split('__')[0] + '__' : '';
        var how = {};
        var copy = leave && !hasRules(name);
        clsOf(el).forEach(function (c) {
            if (leave) {
                if (c === leave) { out.drop.push(c); } else if (copy && c.indexOf(leave + '--') === 0) { out.ren[c] = name + c.slice(leave.length); }
                return;
            }
            if (!fam || c === name || c.indexOf(fam) !== 0) { return; }
            var b = c.split('--')[0];
            if (b === name) { return; }
            if (!how[b]) {
                how[b] = !hasRules(b) ? 'drop' : hasRules(name) || usedBy(b, [el]) ? 'keep' : 'move';
                if (how[b] !== 'drop') { out[how[b]].push(b); }
            }
            if (how[b] === 'drop') { out.drop.push(c); } else if (how[b] === 'move') { out.ren[c] = name + c.slice(b.length); }
        });
        return out;
    }
    // What a run would do: each element and its class, top down.
    function bemPlan(top, name, deep, leave) {
        if (deep === false) {
            var sw = bemSwap(top, name, leave);
            return { old: '', items: [{ el: top, add: name, drop: sw.drop, ren: sw.ren }], moves: sw.move, kept: sw.keep, leave: leave && !hasRules(name) ? leave : '' };
        }
        var old = blockOf(top);
        var oldRe = old && old !== name ? new RegExp('^' + reEsc(old) + '__') : null;
        var items = [{ el: top, add: name, drop: old && old !== name ? [old] : [] }];
        var walk = function (n) {
            Array.prototype.forEach.call(n.children, function (c) {
                if (ours(c) || isFrame(c) || BEM_SKIP.test(c.localName)) { return; }
                var drop = oldRe ? clsOf(c).filter(function (k) { return oldRe.test(k); }) : [];
                var own = blockOf(c);
                if (own && own !== old && own !== name) { items.push({ el: c, add: name + '__' + own, drop: drop, block: own }); return; }
                items.push({ el: c, add: name + '__' + partFor(c, name), drop: drop });
                if (!BEM_LEAF.test(c.localName)) { walk(c); }
            });
        };
        walk(top);
        return { old: old, items: items };
    }

    // The part an element of a block takes: what its classes already say
    // (hero__title, tdr-hero-title → title), else what it is (heading, text…).
    function partFor(el, block) {
        var ns = sitePrefix();
        var core = ns && block.indexOf(ns) === 0 ? block.slice(ns.length) : block;
        var mine = clsOf(el).filter(function (c) { return !/^(nst-x|wp-|is-|has-|align|screen-reader|elementor|et_|brxe|bricks)/.test(c); });
        var bem = mine.filter(function (c) { return c.indexOf('__') > 0; })[0];
        if (bem) { return bemSlug(bem.split('__').pop().split('--')[0]) || bemPart(el); }
        var own = mine.filter(function (c) { return c.indexOf('--') < 0; })[0];
        if (own) {
            var w = bemSlug(own);
            if (ns && w.indexOf(ns) === 0) { w = w.slice(ns.length); }
            if (core && w.indexOf(core + '-') === 0) { w = w.slice(core.length + 1); }
            if (w && w !== core && w.length <= 24) { return w; }
        }
        return bemPart(el);
    }
    // Right-click → "BEM": this element gets a BEM class, one undo step.
    // "block__part" inside a block, its own block otherwise.
    function bemOne(el) {
        var b = blockOf(el);
        if (b) { return b; }
        var up = el.parentElement ? blockAt(el.parentElement) : '';
        return up ? up + '__' + partFor(el, up) : bemSuggest(el);
    }
    function bemName(v) { return String(v || '').split('--')[0].split('__').map(bemSlug).filter(Boolean).slice(0, 2).join('__'); }
    // A variant typed with the name ("card--featured") or on its own.
    function bemMod(v) { var i = String(v || '').indexOf('--'); return i > -1 ? bemSlug(String(v).slice(i + 2)) : bemSlug(v); }
    function ruleHasDecls(key) { return declsIn(cssLive[key]); }
    function bemRun(top, name, deep, mod, leave) {
        var plan = bemPlan(top, name, deep, leave);
        var was = plan.items.map(function (it) { return { cls: it.el.getAttribute('class'), style: it.el.getAttribute('style') }; });
        var cssA = {};
        var get = function (k) { return k in cssA ? cssA[k] : (cssLive[k] || ''); };
        // The rules of one name made the rules of this one: moved, or copied.
        var carry = function (re, copy) {
            Object.keys(cssLive).forEach(function (k) {
                re.lastIndex = 0;
                if (!re.test(k) || !(cssLive[k] || '').trim()) { return; }
                var nk = k.replace(re, '.' + name);
                if (get(nk).trim()) { return; }
                cssA[nk] = cssLive[k].replace(re, '.' + name);
                if (!copy) { cssA[k] = ''; }
            });
        };
        if (plan.old && plan.old !== name && !hasRules(name)) { carry(bemRe(plan.old), false); }
        (plan.moves || []).forEach(function (from) { carry(bemRe(from), false); });
        // Only this element leaves its block: the block's own rules and its
        // variants are copied, so it looks the same; its elements stay as they are.
        if (plan.leave) { carry(new RegExp('\\.' + reEsc(plan.leave) + '(?=--|[^\\w-]|$)', 'g'), true); }
        if (!get('.' + name).trim()) { cssA['.' + name] = '.' + name + ' {\n}\n'; }
        var modCls = mod ? name + '--' + mod : '';
        if (modCls && !get('.' + modCls).trim()) { cssA['.' + modCls] = '.' + modCls + ' {\n}\n'; }
        var styleTo = modCls && declsIn(get('.' + name)) ? modCls : '';
        var els = plan.items.map(function (it) { return it.el; });
        // Inline styles: the first element of each class sets the rule; the
        // others keep inline only what differs from it. A rule that already
        // has styles, or that other elements use, stays as it is: what
        // differs from it stays inline.
        var lead = {};
        var shared = {};
        var inlineKept = '';
        var same = function (a, b) { return a.replace(/\s+/g, ' ').trim() === b.replace(/\s+/g, ' ').trim(); };
        var after = plan.items.map(function (it) {
            var cls = clsOf(it.el).map(function (c) { return (it.ren && it.ren[c]) || c; }).filter(function (c, i, all) { return it.drop.indexOf(c) < 0 && c !== it.add && all.indexOf(c) === i; });
            if (it.block) { cls.splice(cls.indexOf(it.block) + 1, 0, it.add); } else { cls.unshift(it.add); }
            if (modCls && it.el === top && cls.indexOf(modCls) < 0) { cls.splice(cls.indexOf(it.add) + 1, 0, modCls); }
            var style = it.el.getAttribute('style');
            var into = styleTo && it.el === top ? styleTo : it.add;
            if (style && !it.block) {
                var decls = parseStyle(style);
                if (!(into in shared)) { shared[into] = declsIn(get('.' + into)) || usedBy(into, els); }
                var keep;
                if (shared[into]) {
                    var has = topDecls(get('.' + into));
                    keep = decls.filter(function (d) { return !has.some(function (l) { return l[0] === d[0] && same(l[1], d[1]); }); });
                    if (keep.length) { inlineKept = into; }
                } else {
                    if (!lead[into]) {
                        lead[into] = decls;
                        cssA['.' + into] = ruleWith('.' + into, decls.map(function (d) { return [d[0], snapValue(d[0], d[1])]; }), get('.' + into));
                    }
                    keep = decls.filter(function (d) { return !lead[into].some(function (l) { return l[0] === d[0] && l[1] === d[1]; }); });
                }
                style = keep.length ? keep.map(function (d) { return d[0] + ': ' + d[1]; }).join('; ') : null;
            }
            return { cls: cls.join(' '), style: style };
        });
        var cssB = {};
        Object.keys(cssA).forEach(function (k) { cssB[k] = cssLive[k]; });
        var patches = null;
        var put = function (states, css) {
            plan.items.forEach(function (it, i) {
                var s = states[i];
                if (s.cls) { it.el.setAttribute('class', s.cls); } else { it.el.removeAttribute('class'); }
                if (s.style) { it.el.setAttribute('style', s.style); } else { it.el.removeAttribute('style'); }
            });
            Object.keys(css).forEach(function (k) { if (css[k] === undefined) { delete cssLive[k]; } else { cssLive[k] = css[k]; } });
            writeCss();
            codeFor = null;
            cssFor = null;
        };
        var n = plan.items.length;
        act({
            keys: Object.keys(cssA),
            patch: function () { return patches || []; },
            label: sprintf(__('name %s with BEM', 'nibwp'), '.' + name),
            change: function () { return deep === false ? (modCls ? sprintf(__('Give %1$s the BEM class “%2$s” and its variant “%3$s”.', 'nibwp'), describe(top), name, modCls) : sprintf(__('Give %1$s the BEM class “%2$s”.', 'nibwp'), describe(top), name)) : sprintf(__('Name %1$s and everything inside it with BEM classes: block “%2$s”, elements “%2$s__…”.', 'nibwp'), describe(top), name); },
            redo: function () {
                if (!patches) {
                    // Each selector as the page will be when its patch replays.
                    patches = [];
                    plan.items.forEach(function (it, i) {
                        patches.push({ t: 'attr', s: kit.cssPath(it.el), name: 'class', html: after[i].cls });
                        it.el.setAttribute('class', after[i].cls);
                        if ((was[i].style || null) !== after[i].style) {
                            patches.push(after[i].style ? { t: 'attr', s: kit.cssPath(it.el), name: 'style', html: after[i].style } : { t: 'unattr', s: kit.cssPath(it.el), name: 'style' });
                        }
                    });
                }
                put(after, cssA);
                sel = top;
            },
            undo: function () { put(was, cssB); sel = top; }
        });
        // The variant opens in the CSS pane, ready to style.
        if (modCls) { cssSel = '.' + modCls; cssFor = top; if (sel !== top) { select(top, true); } if (!dockOpen || dock.hidden) { setDock(true); } else { fillCode(); } }
        var more = (plan.moves && plan.moves.length ? ' ' + sprintf(__('The styles of .%1$s moved to .%2$s.', 'nibwp'), plan.moves.join(', .'), name) : '') +
            (plan.kept && plan.kept.length ? ' ' + sprintf(__('.%s stays on it too: other elements use it.', 'nibwp'), plan.kept.join(', .')) : '') +
            (plan.leave ? ' ' + sprintf(__('It left .%1$s; its styles were copied to .%2$s.', 'nibwp'), plan.leave, name) : '') +
            (inlineKept ? ' ' + sprintf(__('Its own styles stay inline; .%s stays as it is.', 'nibwp'), inlineKept) : '');
        kit.toast((modCls ? sprintf(__('.%1$s and its variant .%2$s are on it. Style the variant in the CSS pane; Save to keep it.', 'nibwp'), name, modCls) : sprintf(_n('Named %1$d element in block “.%2$s”. Save to keep it; Ctrl+Z undoes it.', 'Named %1$d elements in block “.%2$s”. Save to keep it; Ctrl+Z undoes it.', n, 'nibwp'), n, name)) + more, 'ok');
    }

    /* What the Add tab and quick bar add, as BEM: {B} is the element's own
     * class, {P} the prefix its insides take. Every value on a token, with
     * the old value as its fallback for sites with no framework. */
    var BTN = ['display: inline-flex', 'align-items: center', 'justify-content: center', 'padding: var(--btn-padding-block, 12px) var(--btn-padding-inline, 24px)', 'border: 0', 'border-radius: var(--btn-radius, 8px)', 'background: var(--primary, #111827)', 'color: var(--white, #fff)', 'font-weight: var(--btn-font-weight, 600)', 'line-height: var(--btn-line-height, 1.2)', 'text-decoration: none', 'cursor: pointer', 'transition: background var(--transition, 0.2s)'];
    var BTN_HOVER = ['background: var(--primary-hover, #1f2937)', 'color: var(--white, #fff)'];
    var tx = function (s) { return esc(s); };
    var KIT = {
        section: { k: 'block', def: 'section', h: '<section data-nibwp="section" class="{B}"><div class="{P}__inner"><h2 class="{P}__heading">' + tx(__('New section', 'nibwp')) + '</h2><p class="{P}__text">' + tx(__('Say what goes here, or drop elements in.', 'nibwp')) + '</p></div></section>',
            c: { '': ['padding-block: var(--section-padding-block, 72px)', 'padding-inline: var(--gutter, 24px)'], '__inner': ['display: flex', 'flex-direction: column', 'gap: var(--content-gap, 16px)', 'max-width: var(--content-width, 1120px)', 'margin-inline: auto'], '__heading': ['margin: 0'], '__text': ['margin: 0'] } },
        container: { k: 'block', def: 'stack', h: '<div data-nibwp="container" class="{B}"><p class="{P}__text">' + tx(__('Container', 'nibwp')) + '</p></div>',
            c: { '': ['display: flex', 'flex-direction: column', 'gap: var(--content-gap, 16px)', 'padding: var(--space-m, 24px)'], '__text': ['margin: 0'] } },
        div: { k: 'block', def: 'box', h: '<div class="{B}"></div>', c: { '': ['min-height: 48px', 'padding: var(--space-s, 16px)'] } },
        columns: { k: 'comp', def: 'columns', h: '<div class="{B}">' + [1, 2, 3].map(function () { return '<div class="{P}__item"><h3 class="{P}__heading">' + tx(__('Column', 'nibwp')) + '</h3><p class="{P}__text">' + tx(__('Text', 'nibwp')) + '</p></div>'; }).join('') + '</div>',
            c: { '': ['display: grid', 'grid-template-columns: var(--grid-auto-3, repeat(auto-fit, minmax(220px, 1fr)))', 'gap: var(--grid-gap, 24px)'], '__item': ['display: flex', 'flex-direction: column', 'gap: var(--space-xs, 8px)'], '__heading': ['margin: 0'], '__text': ['margin: 0'] } },
        grid: { k: 'comp', def: 'cards', h: '<div class="{B}">' + [1, 2, 3].map(function () { return '<article class="{P}__item"><img class="{P}__image" src="' + PH + '" alt=""><h3 class="{P}__heading">' + tx(__('Card title', 'nibwp')) + '</h3><p class="{P}__text">' + tx(__('Short card text.', 'nibwp')) + '</p></article>'; }).join('') + '</div>',
            c: { '': ['display: grid', 'grid-template-columns: var(--grid-auto-3, repeat(auto-fit, minmax(240px, 1fr)))', 'gap: var(--grid-gap, 24px)'], '__item': ['display: flex', 'flex-direction: column', 'gap: var(--card-gap, 12px)', 'padding: var(--card-padding, 24px)', 'border: var(--border, 1px solid #e5e7eb)', 'border-radius: var(--card-radius, 12px)', 'background: var(--card-background, #fff)'], '__image': ['display: block', 'width: 100%', 'height: auto', 'aspect-ratio: var(--card-media-aspect-ratio, 1.5)', 'object-fit: cover', 'border-radius: var(--card-media-radius, 8px)'], '__heading': ['margin: 0', 'font-size: var(--card-heading-size, 1.25rem)'], '__text': ['margin: 0', 'color: var(--card-text-color, inherit)'] } },
        card: { k: 'comp', def: 'nb-card', h: '<article class="{B}"><img class="{P}__image" src="' + PH + '" alt=""><h3 class="{P}__heading">' + tx(__('Card title', 'nibwp')) + '</h3><p class="{P}__text">' + tx(__('Short card text.', 'nibwp')) + '</p><a class="{P}__link" href="#">' + tx(__('Read more', 'nibwp')) + '</a></article>',
            c: { '': ['display: flex', 'flex-direction: column', 'gap: var(--card-gap, 12px)', 'padding: var(--card-padding, 24px)', 'border: var(--border, 1px solid #e5e7eb)', 'border-radius: var(--card-radius, 12px)', 'background: var(--card-background, #fff)'], '__image': ['display: block', 'width: 100%', 'height: auto', 'aspect-ratio: var(--card-media-aspect-ratio, 1.5)', 'object-fit: cover', 'border-radius: var(--card-media-radius, 8px)'], '__heading': ['margin: 0', 'font-size: var(--card-heading-size, 1.25rem)'], '__text': ['margin: 0', 'color: var(--card-text-color, inherit)'], '__link': ['color: var(--card-link-color, inherit)', 'font-weight: 600'] } },
        accordion: { k: 'comp', def: 'accordion', h: '<div class="{B}">' + [1, 2, 3].map(function (n) { return '<details class="{P}__item"><summary class="{P}__title">' + tx(sprintf(__('Question %d', 'nibwp'), n)) + '</summary><p class="{P}__text">' + tx(__('The answer.', 'nibwp')) + '</p></details>'; }).join('') + '</div>',
            c: { '': ['display: flex', 'flex-direction: column'], '__item': ['padding-block: var(--space-s, 12px)', 'border-bottom: var(--border, 1px solid #e5e7eb)'], '__title': ['font-weight: 600', 'cursor: pointer'], '__text': ['margin: var(--space-xs, 8px) 0 0'] } },
        table: { k: 'comp', def: 'nb-table', h: '<table class="{B}"><thead><tr><th class="{P}__head">' + tx(__('Name', 'nibwp')) + '</th><th class="{P}__head">' + tx(__('Value', 'nibwp')) + '</th></tr></thead><tbody><tr><td class="{P}__cell">' + tx(__('Row', 'nibwp')) + '</td><td class="{P}__cell">' + tx(__('Data', 'nibwp')) + '</td></tr></tbody></table>',
            c: { '': ['width: 100%', 'border-collapse: collapse'], '__head': ['padding: var(--space-xs, 8px)', 'text-align: start', 'border-bottom: 2px solid var(--neutral-light, #e5e7eb)'], '__cell': ['padding: var(--space-xs, 8px)', 'border-bottom: var(--border, 1px solid #e5e7eb)'] } },
        nav: { k: 'comp', def: 'nb-menu', h: '<nav class="{B}" aria-label="' + tx(__('Menu', 'nibwp')) + '"><ul class="{P}__list">' + [__('Home', 'nibwp'), __('About', 'nibwp'), __('Contact', 'nibwp')].map(function (x) { return '<li class="{P}__item"><a class="{P}__link" href="#">' + tx(x) + '</a></li>'; }).join('') + '</ul></nav>',
            c: { '__list': ['display: flex', 'flex-wrap: wrap', 'gap: var(--space-m, 24px)', 'margin: 0', 'padding: 0', 'list-style: none'], '__link': ['color: inherit', 'text-decoration: none'], '__link:hover': ['color: var(--link-color-hover, inherit)'] } },
        form: { k: 'comp', def: 'nb-form', h: '<form class="{B}" onsubmit="return false"><label class="{P}__label">' + tx(__('Name', 'nibwp')) + '<input class="{P}__input" type="text"></label><label class="{P}__label">' + tx(__('Email', 'nibwp')) + '<input class="{P}__input" type="email"></label><label class="{P}__label">' + tx(__('Message', 'nibwp')) + '<textarea class="{P}__input" rows="4"></textarea></label><button class="{P}__button" type="submit">' + tx(__('Send', 'nibwp')) + '</button></form>',
            c: { '': ['display: grid', 'gap: var(--space-s, 12px)', 'max-width: 440px'], '__label': ['display: grid', 'gap: var(--space-xs, 4px)', 'font-weight: 600'], '__input': ['width: 100%', 'box-sizing: border-box', 'padding: var(--space-xs, 8px) var(--space-s, 12px)', 'border: var(--border, 1px solid #d1d5db)', 'border-radius: var(--radius-s, 6px)', 'font: inherit'], '__button': BTN, '__button:hover': BTN_HOVER } },
        video: { k: 'comp', def: 'video', h: '<div class="{B}">' + tx(__('Video', 'nibwp')) + '</div>', c: { '': ['aspect-ratio: 16 / 9', 'display: grid', 'place-items: center', 'background: var(--base-ultra-dark, #0f172a)', 'color: var(--white, #fff)', 'border-radius: var(--radius-l, 12px)'] } },
        map: { k: 'comp', def: 'map', h: '<div class="{B}">' + tx(__('Map', 'nibwp')) + '</div>', c: { '': ['aspect-ratio: 16 / 9', 'display: grid', 'place-items: center', 'background: var(--neutral-light, #e2e8f0)', 'border-radius: var(--radius-l, 12px)'] } },
        quote: { k: 'comp', def: 'quote', h: '<blockquote class="{B}"><p class="{P}__text">' + tx(__('A quote worth reading.', 'nibwp')) + '</p><cite class="{P}__cite">' + tx(__('Someone', 'nibwp')) + '</cite></blockquote>',
            c: { '': ['margin: 0', 'padding: var(--space-s, 16px) var(--space-m, 24px)', 'border-inline-start: 4px solid var(--primary, currentColor)'], '__text': ['margin: 0 0 var(--space-xs, 8px)'], '__cite': ['font-style: normal', 'color: var(--text-dark-muted, inherit)'] } },
        heading: { k: 'part', def: 'heading', h: '<h2 class="{B}">' + tx(__('New heading', 'nibwp')) + '</h2>' },
        text: { k: 'part', def: 'text', h: '<p class="{B}">' + tx(__('New paragraph. Click it to change it, or ask NibWP what it should say.', 'nibwp')) + '</p>' },
        button: { k: 'part', def: 'button', h: '<a class="{B}" href="#">' + tx(__('Button', 'nibwp')) + '</a>', c: { '': BTN, ':hover': BTN_HOVER } },
        link: { k: 'part', def: 'link', h: '<a class="{B}" href="#">' + tx(__('A link', 'nibwp')) + '</a>' },
        image: { k: 'part', def: 'image', h: '<img class="{B}" src="' + PH + '" alt="">', c: { '': ['display: block', 'max-width: 100%', 'height: auto', 'border-radius: var(--radius-m, 8px)'] } },
        list: { k: 'part', def: 'list', h: '<ul class="{B}"><li class="{P}__item">' + tx(__('First item', 'nibwp')) + '</li><li class="{P}__item">' + tx(__('Second item', 'nibwp')) + '</li><li class="{P}__item">' + tx(__('Third item', 'nibwp')) + '</li></ul>' },
        divider: { k: 'part', def: 'divider', h: '<hr class="{B}">', c: { '': ['border: 0', 'border-top: var(--divider, 1px solid #e5e7eb)', 'margin-block: var(--divider-gap, 24px)'] } },
        spacer: { k: 'part', def: 'spacer', h: '<div class="{B}" aria-hidden="true"></div>', c: { '': ['height: var(--space-xl, 48px)'] } },
        iframe: { k: 'part', def: 'media', h: '<iframe class="{B}" src="about:blank" title="' + tx(__('Embedded page', 'nibwp')) + '" loading="lazy"></iframe>', c: { '': ['display: block', 'width: 100%', 'aspect-ratio: 16 / 9', 'border: 0'] } },
        icon: { k: 'part', def: 'icon', h: '<svg class="{B}" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>', c: { '': ['width: var(--icon-size, 40px)', 'height: var(--icon-size, 40px)'] } }
    };
    function kitCss(k, main) {
        var out = {};
        var c = k.c || {};
        if (k.named && !('' in c)) { out['.' + main] = '.' + main + ' {\n}\n'; }
        Object.keys(c).forEach(function (s) { var key = '.' + main + s; out[key] = key + ' {\n' + c[s].map(function (d) { return '  ' + d + ';'; }).join('\n') + '\n}\n'; });
        return out;
    }
    // A Builder element, named for where it lands: its own block (named in
    // the bubble) or an element of the block it lands in.
    function kitMake(id, target, pos) {
        var k = KIT[id];
        var host = blockAt(pos === 'inside' ? target : target.parentElement);
        var named = k.k !== 'part' || !host;
        var def = RESERVED.test(k.def) ? (sitePrefix() || 'nb-') + k.def : k.def;
        var main = named ? def : host + '__' + k.def;
        var el = fromHtml(k.h.replace(/\{B\}/g, main).replace(/\{P\}/g, named ? main : host));
        if (named && host) { el.classList.add(host + '__' + main); }
        return { el: el, css: kitCss({ c: k.c, named: named }, main), name: named ? main : '', host: host, mix: !!(named && host) };
    }
    // An added element's own rules: set with it, gone with it. A rule that
    // already has CSS is never overwritten: the block's saved look wins.
    function putCss(op, on) {
        if (!op.css) { return; }
        Object.keys(op.css).forEach(function (k) {
            if (on) {
                if (!(k in op.cssWas)) { op.cssWas[k] = cssLive[k]; }
                if (!(op.cssWas[k] || '').trim()) { cssLive[k] = op.css[k]; }
            } else if (k in op.cssWas) {
                if (op.cssWas[k] === undefined) { delete cssLive[k]; } else { cssLive[k] = op.cssWas[k]; }
            }
        });
        op.keys = Object.keys(op.css).filter(function (k) { return !(op.cssWas[k] || '').trim(); });
        writeCss();
    }
    // The name typed in the bubble: the block, its elements and its rules follow.
    function renameNew(op, name) {
        var b = op.bem;
        if (!b || !name || name === b.name || !op.el.isConnected) { return; }
        var from = b.name;
        putCss(op, false);
        op.cssWas = {};
        [op.el].concat(Array.prototype.slice.call(op.el.querySelectorAll('[class]'))).forEach(function (n) {
            n.setAttribute('class', clsOf(n).map(function (x) {
                if (x === from) { return name; }
                if (x.indexOf(from + '__') === 0) { return name + x.slice(from.length); }
                if (b.host && x === b.host + '__' + from) { return b.host + '__' + name; }
                return x;
            }).join(' '));
        });
        var next = {};
        Object.keys(op.css).forEach(function (k) { next[k.replace(bemRe(from), '.' + name)] = op.css[k].replace(bemRe(from), '.' + name); });
        op.css = next;
        b.name = name;
        putCss(op, true);
        codeFor = null;
        cssFor = null;
        render();
        paint();
        inspect();
    }

    /* ------------------------------------------------------------------
     * Right-click on the page or in Structure: a short menu, with
     * "BEM".
     * ---------------------------------------------------------------- */

    var ctx = document.createElement('div');
    ctx.className = 'nst-bd-ctx';
    ctx.hidden = true;
    root.appendChild(ctx);
    var ctxFor = null;
    var ctxBack = null;
    var ctxDryT = 0;
    var ctxDryN = 0;
    // back: where the keyboard goes when it closes (Structure, when opened there).
    // sizes: open straight on the screen sizes it is hidden on.
    function openCtx(el, x, y, back, sizes) {
        ctxFor = el;
        ctxBack = back || null;
        var it = function (k, icon, text, key, off) { return '<button type="button" role="menuitem" data-ctx="' + k + '"' + (off ? ' disabled' : '') + '>' + ico(icon, 14) + '<span>' + esc(text) + '</span>' + (key === '›' ? '<kbd aria-hidden="true">' + ico('chev', 12) + '</kbd>' : key ? '<kbd>' + esc(key) + '</kbd>' : '') + '</button>'; };
        ctx.innerHTML = '<div class="nst-bd-ctx__menu" role="menu" aria-label="' + esc(label(el, true)) + '"><b class="nst-bd-ctx__h">' + esc(label(el, true)) + '</b>' +
            it('bem', 'hash', __('BEM', 'nibwp')) +
            it('ask', 'comment', __('Ask NibWP about it', 'nibwp'), 'Enter') +
            it('up', 'up', __('Select the parent', 'nibwp'), KEYNAME('Ctrl+↑')) +
            it('dup', 'dup', __('Duplicate', 'nibwp'), KEYNAME('Ctrl+D')) +
            it('sitesize', hidOn(el) ? 'siteoff' : 'site', hidWord(hidOn(el)) || __('Hide on the site', 'nibwp'), '›') +
            '<hr>' +
            it('copy', 'copy', __('Copy', 'nibwp'), KEYNAME('Ctrl+C')) +
            it('cut', 'cut', __('Cut', 'nibwp'), KEYNAME('Ctrl+X')) +
            it('paste', 'paste', __('Paste', 'nibwp'), KEYNAME('Ctrl+V'), !board) +
            it('copystyle', 'stylecopy', __('Copy styles', 'nibwp')) +
            it('pastestyle', 'stylepaste', __('Paste styles', 'nibwp'), '', !styleBoard) + '<hr>' +
            it('del', 'trash', __('Delete', 'nibwp'), 'Del') + '</div>' +
            '<div class="nst-bd-ctx__menu nst-bd-ctx__sizes" role="menu" aria-label="' + esc(__('Hide on the site', 'nibwp')) + '" hidden></div>' +
            '<form class="nst-bd-ctx__bem" hidden><b class="nst-bd-ctx__h">' + esc(__('BEM', 'nibwp')) + '</b>' +
            '<label class="nst-bd-ctx__f"><span>' + esc(__('Class', 'nibwp')) + '</span><input data-bem-name spellcheck="false" autocomplete="off" maxlength="60"></label>' +
            '<label class="nst-bd-ctx__f"><span>' + esc(__('Variant', 'nibwp')) + ' <small>' + esc(__('optional', 'nibwp')) + '</small></span><input data-bem-mod spellcheck="false" autocomplete="off" maxlength="30" placeholder="' + esc(__('featured, dark, large…', 'nibwp')) + '"></label>' +
            '<label class="nst-bd__check nst-bd-ctx__only" hidden><input type="checkbox" data-bem-only><span>' + esc(__('Only this element', 'nibwp')) + '</span></label>' +
            '<p class="nst-bd-ctx__info" aria-live="polite"></p><p class="nst-bd-ctx__warn" hidden><span></span><button type="button" class="nst-bd__btn is-ghost" data-ctx="alt"></button></p>' +
            '<button type="button" class="nst-closex" data-ctx="x" aria-label="' + esc(__('Close', 'nibwp')) + '" title="' + esc(__('Close (Esc)', 'nibwp')) + '">' + ico('x', 14) + '</button>' +
            '<div class="nst-bd-ctx__bar"><button type="submit" class="nst-bd__btn is-main">' + ico('check', 12) + '<span>' + esc(__('Apply', 'nibwp')) + '</span></button></div></form>';
        ctx.hidden = false;
        // Right to left, it opens towards the start: its right edge at the pointer.
        ctx.style.left = Math.max(8, Math.min(getComputedStyle(ctx).direction === 'rtl' ? x - (ctx.offsetWidth || 260) : x, innerWidth - 270)) + 'px';
        ctx.style.top = Math.max(8, Math.min(y, innerHeight - Math.max(250, ctx.offsetHeight + 8))) + 'px';
        if (sizes) { ctxSizes(); } else { ctx.querySelector('[data-ctx]').focus(); }
    }
    // Hide on the site, per screen size: several can be picked, each pick one
    // step; the menu stays open and says where it is hidden now.
    function ctxSizes(at) {
        var m = ctx.querySelector('.nst-bd-ctx__sizes');
        var hid = hidOn(ctxFor) || [];
        var item = function (k, icon, text, on) { return '<button type="button" role="menuitemcheckbox" aria-checked="' + on + '" data-ctx="hideon" data-on="' + k + '">' + ico(icon, 14) + '<span>' + esc(text) + '</span><i class="nst-bd-ctx__chk">' + ico('check', 13) + '</i></button>'; };
        m.innerHTML = '<b class="nst-bd-ctx__h">' + esc(__('Hide on the site', 'nibwp')) + '</b>' +
            item('all', 'site', __('Everywhere', 'nibwp'), hid.length === SIZES.length) +
            SIZES.map(function (s) { return item(s[0], s[0], s[1], hid.indexOf(s[0]) > -1); }).join('') +
            '<p class="nst-bd-ctx__info" aria-live="polite">' + esc(hidWord(hidOn(ctxFor)) || __('Shown everywhere', 'nibwp')) + '</p>';
        ctx.querySelector('.nst-bd-ctx__menu').hidden = true;
        m.hidden = false;
        var f = m.querySelector('[data-on="' + (at || 'all') + '"]');
        if (f) { f.focus(); }
    }
    // Closed, it gives the keyboard back: to Structure when it was opened
    // there, else away (a hidden field keeping focus would mute every shortcut).
    function closeCtx() {
        var had = ctx.contains(document.activeElement);
        if (had) { document.activeElement.blur(); }
        ctx.hidden = true;
        ctxFor = null;
        clearTimeout(ctxDryT);
        ctxDryN++;
        if (had && ctxBack && ctxBack.isConnected && ctxBack.offsetParent) { ctxBack.focus({ preventScroll: true }); }
        ctxBack = null;
    }
    // What Apply will do, said before it is done.
    function ctxInfo() {
        var f = ctx.querySelector('.nst-bd-ctx__bem');
        var raw = f.querySelector('[data-bem-name]').value;
        var v = bemName(raw) || 'box';
        var mod = bemMod(f.querySelector('[data-bem-mod]').value) || (raw.indexOf('--') > -1 ? bemMod(raw) : '');
        var info = f.querySelector('.nst-bd-ctx__info');
        var old = blockOf(ctxFor);
        var site = !!old && v !== old && v.indexOf('__') < 0;
        var onlyBox = f.querySelector('.nst-bd-ctx__only');
        onlyBox.hidden = !site;
        var only = site && onlyBox.querySelector('input').checked;
        var also = mod ? ' ' + sprintf(__('Its variant: .%s.', 'nibwp'), v + '--' + mod) : '';
        clearTimeout(ctxDryT);
        var ask = ++ctxDryN;
        if (site && !only) {
            // A styled block named anew is renamed on the whole site: how many places, asked first.
            info.textContent = sprintf(__('Renames .%1$s to .%2$s on the whole site.', 'nibwp'), old, v) + also;
            ctxDryT = setTimeout(function () {
                kit.api('class-rename', { method: 'POST', body: { kind: '.', from: old, to: v, dry: true } }).then(function (r) {
                    if (ask !== ctxDryN) { return; }
                    var n = (r && r.total) || 0;
                    info.textContent = sprintf(_n('Renames .%1$s to .%2$s on the whole site: %3$d place.', 'Renames .%1$s to .%2$s on the whole site: %3$d places.', n, 'nibwp'), old, v, n) + also;
                }, function (x) { if (ask === ctxDryN && x && x.message) { info.textContent = x.message; } });
            }, 250);
        } else if (only) {
            info.textContent = sprintf(__('Only this element: .%1$s becomes .%2$s here, with a copy of its styles. What is inside stays as it is.', 'nibwp'), old, v) + also;
        } else {
            var sw = bemSwap(ctxFor, v);
            info.textContent = (mod ? sprintf(__('This element gets .%1$s and its variant .%1$s--%2$s; what is inside stays as it is.', 'nibwp'), v, mod) : sprintf(__('Only this element gets .%s; what is inside stays as it is.', 'nibwp'), v)) +
                (sw.move.length ? ' ' + sprintf(__('.%1$s goes, its styles move to .%2$s.', 'nibwp'), sw.move.join(', .'), v) : '') +
                (sw.keep.length ? ' ' + sprintf(__('.%s stays on it too: other elements use it.', 'nibwp'), sw.keep.join(', .')) : '');
        }
        var c = null;
        if (v.indexOf('__') > -1) {
            // An element of a block belongs inside it.
            var blk = v.split('__')[0];
            var inside = false;
            try { inside = !!(ctxFor.parentElement && ctxFor.parentElement.closest('.' + CSS.escape(blk))); } catch (x) { inside = false; }
            if (!inside) { c = ['away', sprintf(__('No .%s around it: an element of a block belongs inside it.', 'nibwp'), blk), bemSuggest(ctxFor)]; }
        } else if (v !== blockOf(ctxFor)) {
            c = bemCheck(v, ctxFor);
        }
        var w = f.querySelector('.nst-bd-ctx__warn');
        w.hidden = !c;
        if (c) { w.querySelector('span').textContent = c[1]; w.querySelector('button').textContent = sprintf(__('Use %s', 'nibwp'), c[2]); w.querySelector('button').dataset.name = c[2]; }
    }
    ctx.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ctx]');
        if (!b || b.disabled || !ctxFor) { return; }
        var el = ctxFor;
        var k = b.dataset.ctx;
        if (k === 'bem') {
            ctx.querySelector('.nst-bd-ctx__menu').hidden = true;
            var f = ctx.querySelector('.nst-bd-ctx__bem');
            f.hidden = false;
            var inp = f.querySelector('[data-bem-name]');
            inp.value = bemOne(el);
            var have = clsOf(el).filter(function (c) { return c.indexOf(inp.value + '--') === 0; })[0];
            f.querySelector('[data-bem-mod]').value = have ? have.slice(inp.value.length + 2) : '';
            ctxInfo();
            inp.focus();
            inp.select();
            return;
        }
        if (k === 'alt') { ctx.querySelector('.nst-bd-ctx__bem [data-bem-name]').value = b.dataset.name; ctxInfo(); return; }
        if (k === 'sitesize') { ctxSizes(); return; }
        if (k === 'hideon') { if (el.isConnected) { siteHideToggle(el, b.dataset.on); ctxSizes(b.dataset.on); } return; }
        closeCtx();
        if (k === 'del') { removeEl(el); } else if (k !== 'x') { toolDo(k, el); }
    });
    ctx.addEventListener('input', function () { if (ctxFor) { ctxInfo(); } });
    ctx.addEventListener('submit', function (e) {
        e.preventDefault();
        var el = ctxFor;
        var raw = ctx.querySelector('.nst-bd-ctx__bem [data-bem-name]').value;
        var v = bemName(raw);
        var mod = bemMod(ctx.querySelector('.nst-bd-ctx__bem [data-bem-mod]').value) || (raw.indexOf('--') > -1 ? bemMod(raw) : '');
        var old = el ? blockOf(el) : '';
        var only = ctx.querySelector('.nst-bd-ctx__bem [data-bem-only]').checked;
        closeCtx();
        if (!el || !el.isConnected || !v) { return; }
        if (old && v !== old && v.indexOf('__') < 0) {
            // Only this element: it leaves its block for the new one.
            if (only) { bemRun(el, v, false, mod, old); return; }
            // A styled block named anew: the block, its elements and variants are
            // renamed together on the whole site; the variant follows once that is done.
            renameEverywhere('.', old, v).then(function (ok) { if (ok && mod && el.isConnected) { bemRun(el, v, false, mod); } });
            return;
        }
        bemRun(el, v, false, mod);
    });
    ctx.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeCtx(); return; }
        var items = Array.prototype.slice.call(ctx.querySelectorAll('.nst-bd-ctx__menu:not([hidden]) [data-ctx]:not([disabled])'));
        var i = items.indexOf(document.activeElement);
        if (items.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus(); }
        if (items.length && (e.key === 'Home' || e.key === 'End')) { e.preventDefault(); items[e.key === 'Home' ? 0 : items.length - 1].focus(); }
    });
    // Our menu, not the browser's, over our menu.
    ctx.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    document.addEventListener('pointerdown', function (e) { if (!ctx.hidden && !ctx.contains(e.target)) { closeCtx(); } }, true);
    function onCtx(e) {
        if (!on || blocked || aside()) { return; }
        var el = pickable(e.target);
        if (!el || isFrame(el) || el === doc.body) { return; }
        e.preventDefault();
        e.stopPropagation();
        select(el, true);
        var fr = frameBox();
        openCtx(el, fr.left + e.clientX * fr.k, fr.top + e.clientY * fr.k);
    }
    // From the keyboard (the menu key or Shift+F10 on Structure) the menu
    // opens for what is selected, at its row.
    function treeCtx(el) {
        var i = rows.indexOf(el);
        var row = i > -1 ? treeEl.querySelector('.nst-bd__row[data-i="' + i + '"]') : null;
        var r = (row || treeEl).getBoundingClientRect();
        openCtx(el, r.left + 24, Math.min(r.bottom, innerHeight - 8), treeEl);
    }
    treeEl.addEventListener('contextmenu', function (e) {
        var r = e.target.closest('.nst-bd__row');
        var el = r ? rows[+r.dataset.i] : sel;
        if (!el || !el.isConnected || isFrame(el)) { return; }
        e.preventDefault();
        if (!ctx.hidden && ctxFor === el) { return; }
        if (!r) { treeCtx(el); return; }
        select(el);
        openCtx(el, e.clientX, e.clientY, treeEl);
    });
    treeEl.addEventListener('keydown', function (e) {
        if (e.key !== 'ContextMenu' && !(e.shiftKey && e.key === 'F10')) { return; }
        if (!sel || !sel.isConnected || isFrame(sel)) { return; }
        e.preventDefault();
        treeCtx(sel);
    });

    /* ------------------------------------------------------------------
     * Design: NibWP's CSS framework. A few settings make every token;
     * each change shows on the page at once, Save makes it the site's.
     * ---------------------------------------------------------------- */

    var fw = null;
    var fwDraft = null;
    var fwTok = {};
    var fwCss = '';
    var fwT = 0;
    if (!('fw-all' in shut)) { shut['fw-all'] = true; }
    if (!('fw-screens' in shut)) { shut['fw-screens'] = true; }
    var FW_COLORS = [['primary', __('Primary', 'nibwp')], ['secondary', __('Secondary', 'nibwp')], ['tertiary', __('Tertiary', 'nibwp')], ['accent', __('Accent', 'nibwp')], ['base', __('Base (text)', 'nibwp')], ['neutral', __('Neutral (greys)', 'nibwp')], ['success', __('Success', 'nibwp')], ['warning', __('Warning', 'nibwp')], ['danger', __('Danger', 'nibwp')], ['info', __('Info', 'nibwp')]];
    var SHADES = ['ultra-light', 'light', 'semi-light', '', 'semi-dark', 'dark', 'ultra-dark'];
    var RATIOS = [[1.067, __('Minor second', 'nibwp')], [1.125, __('Major second', 'nibwp')], [1.2, __('Minor third', 'nibwp')], [1.25, __('Major third', 'nibwp')], [1.333, __('Perfect fourth', 'nibwp')], [1.414, __('Augmented fourth', 'nibwp')], [1.5, __('Perfect fifth', 'nibwp')], [1.618, __('Golden ratio', 'nibwp')]];
    var fwBox = left.querySelector('.nst-fw');

    function fwCopy(s) { return { on: !!s.on, set: Object.assign({}, s.set), over: Object.assign({}, s.over || {}) }; }
    function fwDirty() { return !!(fw && fwDraft) && JSON.stringify(fwCopy(fw)) !== JSON.stringify(fwDraft); }
    function fwLoad(force) {
        if (fw && !force) { renderDesign(); return; }
        kit.api('framework').then(function (r) {
            fw = r;
            if (!fwDraft || !fwDirty()) { fwDraft = fwCopy(r); fwTok = r.tokens || {}; fwCss = r.css || ''; }
            renderDesign();
            fwApply();
        }, function (x) { fwBox.innerHTML = '<p class="nst-bd__none">' + esc(x.message || __('Could not load the design settings.', 'nibwp')) + '</p>'; });
    }
    // The page shows the draft: NibWP's tokens on top while it is on. With
    // the Builder closed it shows what is saved; the draft comes back with it.
    function fwApply() {
        if (!doc || !fwDraft) { return; }
        var dirty = fwDirty();
        var draft = on || !dirty;
        var s = draft ? fwDraft : fw;
        var css = draft ? fwCss : (fw.css || '');
        var mine = doc.getElementById('nst-x-fw');
        var printed = doc.getElementById('nibwp-framework');
        if (printed && printed.sheet) { printed.sheet.disabled = (on && dirty) || !s.on; }
        if (s.on && ((on && dirty) || !printed)) {
            if (!mine) { mine = doc.createElement('style'); mine.id = 'nst-x-fw'; (doc.head || doc.documentElement).appendChild(mine); }
            if (mine.textContent !== css) { mine.textContent = css; }
        } else if (mine) { mine.remove(); }
        tokCache = null;
        resetCssVars();
        fwMark();
    }
    /* A design not saved yet is unsaved work: the tab on screen carries the
     * mark Agent View asks about before closing or reloading. */
    var fwMarked = null;
    function fwMark() {
        var fr = doc && doc.defaultView && doc.defaultView.frameElement;
        var dirty = fwDirty();
        var tell = false;
        [fwMarked, dirty ? null : fr].forEach(function (f) {
            if (f && (f !== fr || !dirty) && f.getAttribute('data-nst-unsaved') === 'design') { f.removeAttribute('data-nst-unsaved'); tell = true; }
        });
        fwMarked = null;
        if (fr && dirty) {
            if (!fr.hasAttribute('data-nst-unsaved')) { fr.setAttribute('data-nst-unsaved', 'design'); tell = true; }
            if (fr.getAttribute('data-nst-unsaved') === 'design') { fwMarked = fr; }
        }
        if (tell && kit.tabsChanged) { kit.tabsChanged(); }
    }
    function fwPx(v) {
        var m = /^clamp\(([\d.]+)rem,.*,\s*([\d.]+)rem\)$/.exec(v || '');
        if (m) { return [m[1] * 16, m[2] * 16]; }
        m = /^([\d.]+)(rem|px)$/.exec(v || '');
        return m ? [m[1] * (m[2] === 'rem' ? 16 : 1), m[1] * (m[2] === 'rem' ? 16 : 1)] : null;
    }
    function fwRange(n) {
        var p = fwPx(fwTok[n]);
        if (!p) { return ''; }
        var a = Math.round(p[0]);
        var b = Math.round(p[1]);
        return (a === b ? a : a + '–' + b) + 'px';
    }
    // One grid for every setting: the name, then one or two boxes of the
    // same width (a single value spans both), units inside the box.
    function fwNum(k, step, unit, label, ratio) { return '<label class="nst-fw__num' + (unit ? ' has-unit' : '') + '"><input type="number" inputmode="decimal" min="0" step="' + step + '" data-fw="' + k + '" value="' + esc(String(fwDraft.set[k])) + '"' + (ratio ? ' list="nst-fw-ratios"' : '') + ' aria-label="' + esc(label) + '">' + (unit ? '<small aria-hidden="true">' + unit + '</small>' : '') + '</label>'; }
    function fwRow(text, a, b, tip) { return '<div class="nst-fw__row"' + (tip ? ' title="' + esc(tip) + '"' : '') + '><span>' + esc(text) + '</span>' + (b ? a + b : a.replace(/^<(\w+) class="/, '<$1 class="is-span ')) + '</div>'; }
    function fwHead(a, b) { return '<div class="nst-fw__row is-head" aria-hidden="true"><span></span><small>' + esc(a) + '</small><small>' + esc(b) + '</small></div>'; }
    function fwPair(text, a, b, step, unit, ratio) { return fwRow(text, fwNum(a, step, unit, sprintf(__('%s on phones', 'nibwp'), text), ratio), fwNum(b, step, unit, sprintf(__('%s on large screens', 'nibwp'), text), ratio)); }
    function fwWeight(k, text) { return '<select class="nst-fw__sel" data-fw="' + k + '" aria-label="' + esc(text) + '">' + [300, 400, 500, 600, 700, 800, 900].map(function (w) { return '<option value="' + w + '"' + (+fwDraft.set[k] === w ? ' selected' : '') + '>' + w + '</option>'; }).join('') + '</select>'; }
    function fwText(k, text, ph) { return '<input class="nst-fw__txt" data-fw="' + k + '" value="' + esc(fwDraft.set[k]) + '" spellcheck="false" aria-label="' + esc(text) + '"' + (ph ? ' placeholder="' + esc(ph) + '"' : '') + '>'; }
    function renderDesign() {
        if (!fwBox) { return; }
        closePick();
        if (!fwDraft) { fwBox.innerHTML = '<p class="nst-bd__none">' + esc(__('Loading…', 'nibwp')) + '</p>'; return; }
        var s = fwDraft.set;
        var colours = FW_COLORS.map(function (c) {
            return '<div class="nst-fw__color"><button type="button" class="nst-fw__sw" data-fw-pick="' + c[0] + '" style="--c:' + esc(s[c[0]]) + '" aria-label="' + esc(sprintf(__('Pick the %s colour', 'nibwp'), c[1])) + '" aria-haspopup="dialog"></button>' +
                '<span class="nst-fw__cn"><b>' + esc(c[1]) + '</b><input class="nst-fw__hex" data-fw="' + c[0] + '" value="' + esc(s[c[0]]) + '" maxlength="7" spellcheck="false" aria-label="' + esc(sprintf(__('%s as hex', 'nibwp'), c[1])) + '"></span>' +
                '<span class="nst-fw__shades" data-fw-shades="' + c[0] + '"></span></div>';
        }).join('');
        var phones = __('Phone', 'nibwp');
        var large = __('Desktop', 'nibwp');
        var type = fwHead(phones, large) +
            fwPair(__('Body text', 'nibwp'), 'text_min', 'text_max', 0.5, 'px') +
            fwPair(__('Text scale', 'nibwp'), 'text_ratio_min', 'text_ratio_max', 0.001, '', true) +
            fwPair(__('Heading scale', 'nibwp'), 'h_ratio_min', 'h_ratio_max', 0.001, '', true) +
            fwRow(__('Heading weight', 'nibwp'), fwWeight('heading_weight', __('Heading weight', 'nibwp'))) +
            fwRow(__('Text font', 'nibwp'), fwText('font_text', __('Text font', 'nibwp'))) +
            fwRow(__('Heading font', 'nibwp'), fwText('font_heading', __('Heading font', 'nibwp'), 'inherit')) +
            '<p class="nst-fw__hint">' + esc(__('Fonts must already load on the site; ask NibWP to add one.', 'nibwp')) + '</p>' +
            '<div class="nst-fw__sample" data-fw-sample="type"></div>';
        var space = fwHead(phones, large) +
            fwPair(__('Space (m)', 'nibwp'), 'space_min', 'space_max', 1, 'px') +
            fwPair(__('Space scale', 'nibwp'), 'space_ratio_min', 'space_ratio_max', 0.001, '', true) +
            fwPair(__('Section space (m)', 'nibwp'), 'section_min', 'section_max', 2, 'px') +
            fwPair(__('Side gutter', 'nibwp'), 'gutter_min', 'gutter_max', 1, 'px') +
            '<div class="nst-fw__sample" data-fw-sample="space"></div>';
        var shape = fwRow(__('Radius (m)', 'nibwp'), fwNum('radius', 1, 'px', __('Radius (m)', 'nibwp'))) +
            fwRow(__('Radius scale', 'nibwp'), fwNum('radius_ratio', 0.001, '', __('Radius scale', 'nibwp'), true)) +
            '<div class="nst-fw__sample" data-fw-sample="radius"></div>' +
            fwRow(__('Button radius', 'nibwp'), fwNum('btn_radius', 1, 'px', __('Button radius', 'nibwp'))) +
            fwRow(__('Button weight', 'nibwp'), fwWeight('btn_weight', __('Button weight', 'nibwp'))) +
            fwHead(__('Vertical', 'nibwp'), __('Sides', 'nibwp')) +
            fwRow(__('Button padding', 'nibwp'), fwNum('btn_pad_y', 0.05, 'em', __('Button padding, top and bottom', 'nibwp')), fwNum('btn_pad_x', 0.05, 'em', __('Button padding, sides', 'nibwp'))) +
            '<div class="nst-fw__sample" data-fw-sample="button"></div>';
        var screens = fwHead(__('Smallest', 'nibwp'), __('Largest', 'nibwp')) +
            fwPair(__('Sizes grow between', 'nibwp'), 'vp_min', 'vp_max', 10, 'px') +
            fwRow(__('Content width', 'nibwp'), fwNum('content_width', 10, 'px', __('Content width', 'nibwp')));
        var ratios = '<datalist id="nst-fw-ratios">' + RATIOS.map(function (r) { return '<option value="' + r[0] + '" label="' + esc(r[1]) + '"></option>'; }).join('') + '</datalist>';
        var names = Object.keys(fwTok);
        var all = '<label class="nst-bd__find nst-fw__find">' + ico('search', 14) + '<input type="search" data-fw-find placeholder="' + esc(__('Find a token…', 'nibwp')) + '" aria-label="' + esc(__('Find a token', 'nibwp')) + '"></label>' +
            '<p class="nst-fw__hint">' + esc(__('Type a value to set one token by hand; empty goes back to the generated one.', 'nibwp')) + '</p>' +
            '<div class="nst-fw__toks">' + names.map(function (n) {
                var o = fwDraft.over[n] || '';
                return '<label class="nst-fw__tok" data-find="' + esc(n) + '"><code>' + esc(n) + '</code><input data-fw-tok="' + esc(n) + '" value="' + esc(o) + '" placeholder="' + esc(fwTok[n]) + '" spellcheck="false"></label>';
            }).join('') + '</div>';
        fwBox.innerHTML =
            '<div class="nst-fw__top">' +
                '<label class="nst-fw__switch"><span><b>' + esc(__('NibWP framework', 'nibwp')) + '</b><small data-fw-state></small></span><input type="checkbox" role="switch" data-fw="on"' + (fwDraft.on ? ' checked' : '') + '><i aria-hidden="true"></i></label>' +
                (fw.other ? '<p class="nst-fw__note">' + esc(__('Another CSS framework is active on this site. With this on, NibWP’s values win; its own classes take them too.', 'nibwp')) + '</p>' : '') +
                '<div class="nst-fw__ask"><textarea rows="2" data-fw-ask placeholder="' + esc(__('Brand colours, a logo, a site you like… NibWP sets it all up', 'nibwp')) + '"></textarea>' +
                    '<div class="nst-fw__askbar"><button type="button" class="nst-bd__ib" data-fw-act="attach" data-tip="' + esc(__('Attach an image', 'nibwp')) + '" aria-label="' + esc(__('Attach a logo or a screenshot', 'nibwp')) + '">' + ico('attach', 14) + '</button><small class="nst-fw__files"></small><i class="nst-fw__fill"></i>' +
                    '<button type="button" class="nst-bd__btn is-main" data-fw-act="ask">' + ico('spark', 12) + '<span>' + esc(__('Ask NibWP to set it up', 'nibwp')) + '</span></button></div>' +
                    '<input type="file" accept="image/*,.pdf" multiple hidden data-fw-file></div>' +
            '</div>' +
            '<div class="nst-fw__body">' + ratios +
                group('fw-colors', __('Colours', 'nibwp'), '<div class="nst-fw__colors">' + colours + '</div>') +
                group('fw-type', __('Typography', 'nibwp'), type) +
                group('fw-space', __('Spacing', 'nibwp'), space) +
                group('fw-shape', __('Shape and buttons', 'nibwp'), shape) +
                group('fw-screens', __('Screens', 'nibwp'), screens) +
                group('fw-all', __('All tokens', 'nibwp'), all) +
            '</div>' +
            '<footer class="nst-fw__foot"><button type="button" class="nst-bd__btn is-ghost" data-fw-act="defaults" data-tip="' + esc(__('Back to defaults', 'nibwp')) + '">' + ico('reset', 12) + '<span>' + esc(__('Defaults', 'nibwp')) + '</span></button><i class="nst-fw__fill"></i>' +
                '<button type="button" class="nst-bd__btn" data-fw-act="discard">' + esc(__('Discard', 'nibwp')) + '</button>' +
                '<button type="button" class="nst-bd-bar__save" data-fw-act="save">' + ico('save', 14) + '<span>' + esc(__('Save', 'nibwp')) + '</span></button></footer>';
        var cnt = fwBox.querySelector('[data-grp="fw-all"] .nst-bd__grp-n');
        if (cnt) { cnt.textContent = String(names.length); }
        fwPaint();
    }
    // The parts that follow the values, without touching what is typed.
    function fwPaint() {
        if (!fwBox || !fwDraft || !fwBox.querySelector('.nst-fw__top')) { return; }
        var s = fwDraft.set;
        var dirty = fwDirty();
        fwBox.querySelector('[data-fw-state]').textContent = fwDraft.on ? __('On: these colours, sizes and spaces style the whole site.', 'nibwp') : __('Off: the site keeps its own styles. What you add still looks right.', 'nibwp');
        fwBox.querySelectorAll('[data-fw-shades]').forEach(function (b) {
            var c = b.dataset.fwShades;
            b.innerHTML = SHADES.map(function (sh) {
                var n = '--' + c + (sh ? '-' + sh : '');
                if (!sh) { return '<button type="button" class="is-base" data-fw-base="' + c + '" style="background:' + esc(fwTok[n] || '') + '" data-tip="' + esc(n.slice(2)) + '" aria-label="' + esc(sprintf(__('Pick the %s colour', 'nibwp'), c)) + '"></button>'; }
                var own = fwDraft.over[n];
                return '<button type="button" data-var="' + n + '"' + (own ? ' class="is-over"' : '') + ' style="background:' + esc(own || fwTok[n] || '') + '" data-tip="' + esc(n.slice(2)) + '" aria-label="' + esc(sprintf(__('Pick the %s shade', 'nibwp'), n.slice(2))) + '"></button>';
            }).join('');
        });
        var t = fwBox.querySelector('[data-fw-sample="type"]');
        if (t) {
            t.innerHTML = ['--h1', '--h2', '--h3', '--h4', '--text-l', '--text-m', '--text-s'].map(function (n) {
                var p = fwPx(fwTok[n]);
                var h = n.charAt(2) === 'h';
                return '<div class="nst-fw__ts" data-var="' + n + '"><b style="font-size:' + (p ? Math.min(p[1], 30) : 16) + 'px;font-weight:' + (h ? +s.heading_weight : 400) + '">Aa</b><code>' + n.slice(2) + '</code><small>' + fwRange(n) + '</small></div>';
            }).join('');
        }
        var sp = fwBox.querySelector('[data-fw-sample="space"]');
        if (sp) {
            sp.innerHTML = ['xs', 's', 'm', 'l', 'xl', 'xxl'].map(function (x) { return '--space-' + x; }).concat(['--section-space-m', '--gutter']).map(function (n) {
                var p = fwPx(fwTok[n]);
                return '<div class="nst-fw__bar" data-var="' + n + '"><code>' + n.slice(2) + '</code><i style="width:' + (p ? Math.min(p[1], 120) : 0) + 'px"></i><small>' + fwRange(n) + '</small></div>';
            }).join('');
        }
        var r = fwBox.querySelector('[data-fw-sample="radius"]');
        if (r) { r.innerHTML = ['xs', 's', 'm', 'l', 'xl', 'xxl'].map(function (x) { var v = fwTok['--radius-' + x] || '0'; return '<span class="nst-fw__rad" data-var="--radius-' + x + '"><i style="border-radius:' + esc(v) + '"></i><small>' + x + '</small></span>'; }).join(''); }
        var bt = fwBox.querySelector('[data-fw-sample="button"]');
        if (bt) { bt.innerHTML = '<span class="nst-fw__btnx" style="background:' + esc(fwTok['--primary'] || s.primary) + ';border-radius:' + (+s.btn_radius) + 'px;font-weight:' + (+s.btn_weight) + ';padding:' + (+s.btn_pad_y) + 'em ' + (+s.btn_pad_x) + 'em">' + esc(__('Button', 'nibwp')) + '</span><span class="nst-fw__btnx is-line" style="color:' + esc(fwTok['--primary'] || s.primary) + ';border-radius:' + (+s.btn_radius) + 'px;font-weight:' + (+s.btn_weight) + ';padding:' + (+s.btn_pad_y) + 'em ' + (+s.btn_pad_x) + 'em">' + esc(__('Outline', 'nibwp')) + '</span>'; }
        fwBox.querySelectorAll('[data-fw-tok]').forEach(function (i) { i.placeholder = fwTok[i.dataset.fwTok] || ''; });
        var sv = fwBox.querySelector('[data-fw-act="save"]');
        sv.disabled = !dirty || sv.classList.contains('is-saving');
        sv.classList.toggle('is-dirty', dirty);
        sv.setAttribute('aria-label', dirty ? __('Save the design', 'nibwp') : __('Nothing to save', 'nibwp'));
        fwBox.querySelector('.nst-fw__body').onscroll = closePick;
        fwBox.querySelector('[data-fw-act="discard"]').disabled = !dirty;
        var tab = left.querySelector('[data-bd-view="design"]');
        if (tab) { tab.classList.toggle('is-dirty', dirty); }
        fwMark();
        var nf = kit.files ? kit.files() : 0;
        fwBox.querySelector('.nst-fw__files').textContent = nf ? sprintf(_n('%d file attached', '%d files attached', nf, 'nibwp'), nf) : '';
    }
    function fwChanged() {
        fwPaint();
        clearTimeout(fwT);
        fwT = setTimeout(function () {
            var want = JSON.stringify(fwDraft);
            kit.api('framework/preview', { method: 'POST', body: { set: fwDraft.set, over: fwDraft.over } }).then(function (r) {
                if (JSON.stringify(fwDraft) !== want) { return; }
                fwCss = r.css;
                fwTok = r.tokens || {};
                fwApply();
                fwPaint();
            }, function (x) { kit.toast(x.message || __('Could not show that change.', 'nibwp'), 'bad'); });
        }, 160);
    }
    function fwSave() {
        var b = fwBox.querySelector('[data-fw-act="save"]');
        b.disabled = true;
        b.classList.add('is-saving');
        // What changes while it saves stays a draft, and stays on screen.
        var sent = JSON.stringify(fwDraft);
        kit.api('framework', { method: 'POST', body: fwDraft }).then(function (r) {
            fw = r;
            var printed = doc && doc.getElementById('nibwp-framework');
            if (printed) { printed.textContent = r.css || ''; }
            if (JSON.stringify(fwDraft) !== sent) {
                b.classList.remove('is-saving');
                fwApply();
                fwPaint();
                kit.toast(__('Saved. What you changed since is not saved yet.', 'nibwp'), 'ok');
                return;
            }
            fwDraft = fwCopy(r);
            fwTok = r.tokens || {};
            fwCss = r.css || '';
            fwApply();
            // The fields are drawn again: the keyboard stays where it was.
            var a = document.activeElement;
            var at = a && fwBox.contains(a) ? ['data-fw', 'data-fw-tok', 'data-fw-act', 'data-fw-pick'].filter(function (n) { return a.hasAttribute(n); }).map(function (n) { return '[' + n + '="' + a.getAttribute(n) + '"]'; })[0] : '';
            renderDesign();
            if (at) { var again = fwBox.querySelector(at); if (again) { again.focus({ preventScroll: true }); } }
            var nb = fwBox.querySelector('[data-fw-act="save"]');
            nb.classList.add('is-saved');
            setTimeout(function () { nb.classList.remove('is-saved'); }, 1600);
            kit.toast(r.on ? __('Saved. NibWP’s framework styles the whole site now.', 'nibwp') : __('Saved. NibWP’s framework is off; the site keeps its own styles.', 'nibwp'), 'ok');
        }, function (x) { b.classList.remove('is-saving'); b.disabled = false; kit.toast(x.message || __('Could not save. Nothing was lost: try again.', 'nibwp'), 'bad'); });
    }
    // A colour typed, picked or dragged: the hex field, the swatch and the page follow.
    function fwColor(k, hex, from) {
        fwDraft.set[k] = hex;
        fwBox.querySelectorAll('.nst-fw__hex[data-fw="' + k + '"]').forEach(function (o) { if (o !== from) { o.value = hex; } });
        var sw = fwBox.querySelector('[data-fw-pick="' + k + '"]');
        if (sw) { sw.style.setProperty('--c', hex); }
        fwChanged();
    }
    function hexToHsv(hex) {
        var n = parseInt(hex.slice(1), 16);
        var r = (n >> 16 & 255) / 255;
        var g = (n >> 8 & 255) / 255;
        var b = (n & 255) / 255;
        var mx = Math.max(r, g, b);
        var d = mx - Math.min(r, g, b);
        var h = !d ? 0 : mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
        return [(h * 60 + 360) % 360, mx ? d / mx : 0, mx];
    }
    function hsvToHex(h, sat, v) {
        var f = function (n) { var k = (n + h / 60) % 6; return Math.round((v - v * sat * Math.max(0, Math.min(k, 4 - k, 1))) * 255); };
        return '#' + [f(5), f(3), f(1)].map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }
    var pick = document.createElement('div');
    pick.className = 'nst-fw-pick';
    pick.hidden = true;
    pick.setAttribute('role', 'dialog');
    pick.innerHTML = '<div class="nst-fw-pick__sv" tabindex="0" role="slider" aria-label="' + esc(__('Saturation and brightness: arrow keys move it', 'nibwp')) + '"><i></i></div>' +
        '<input type="range" class="nst-fw-pick__hue" min="0" max="359" step="1" aria-label="' + esc(__('Hue', 'nibwp')) + '">' +
        '<div class="nst-fw-pick__row"><i class="nst-fw-pick__now"></i><input class="nst-fw-pick__hex" maxlength="7" spellcheck="false" aria-label="' + esc(__('Hex', 'nibwp')) + '">' +
        '<button type="button" class="nst-bd__btn is-main" data-pick="done">' + esc(__('Done', 'nibwp')) + '</button></div>' +
        '<div class="nst-fw-pick__more" hidden><button type="button" class="nst-bd__btn is-ghost" data-pick="copy">' + ico('copy', 12) + '<span></span></button><button type="button" class="nst-bd__btn is-ghost" data-pick="reset">' + ico('reset', 12) + '<span>' + esc(__('Back to generated', 'nibwp')) + '</span></button></div>';
    root.appendChild(pick);
    var pickTok = null;
    // A colour token's value as #rrggbb, whatever form it is written in.
    function hexOf(v) {
        var rgb = rgbOf(String(v || ''));
        return rgb ? '#' + rgb.map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('') : '#888888';
    }
    function pickMore() {
        var m = pick.querySelector('.nst-fw-pick__more');
        m.hidden = !pickTok;
        if (!pickTok) { return; }
        m.querySelector('[data-pick="copy"] span').textContent = 'var(' + pickTok + ')';
        m.querySelector('[data-pick="reset"]').hidden = !fwDraft.over[pickTok];
    }
    var pickKey = null;
    var pickFrom = null;
    var hsv = [0, 0, 0];
    var svEl = pick.querySelector('.nst-fw-pick__sv');
    function paintPick(skipHex) {
        var hex = hsvToHex(hsv[0], hsv[1], hsv[2]);
        svEl.style.setProperty('--h', String(Math.round(hsv[0])));
        svEl.querySelector('i').style.left = (hsv[1] * 100) + '%';
        svEl.querySelector('i').style.top = ((1 - hsv[2]) * 100) + '%';
        svEl.setAttribute('aria-valuetext', hex);
        pick.querySelector('.nst-fw-pick__hue').value = String(Math.round(hsv[0]));
        pick.querySelector('.nst-fw-pick__now').style.background = hex;
        if (!skipHex) { pick.querySelector('.nst-fw-pick__hex').value = hex; }
    }
    function pickSet(skipHex) {
        paintPick(skipHex);
        var hex = hsvToHex(hsv[0], hsv[1], hsv[2]);
        if (pickTok) {
            fwDraft.over[pickTok] = hex;
            if (pickFrom) { pickFrom.style.background = hex; pickFrom.classList.add('is-over'); }
            pickMore();
            fwChanged();
            return;
        }
        fwColor(pickKey, hex);
    }
    function openPick(btn, tok) {
        pickTok = tok || null;
        pickKey = tok ? null : btn.dataset.fwPick;
        pickFrom = btn;
        hsv = hexToHsv(tok ? hexOf(fwDraft.over[tok] || fwTok[tok]) : String(fwDraft.set[pickKey] || '#000000'));
        pickMore();
        pick.setAttribute('aria-label', tok ? sprintf(__('Pick the %s shade', 'nibwp'), tok.slice(2)) : btn.getAttribute('aria-label'));
        pick.hidden = false;
        paintPick();
        var r = btn.getBoundingClientRect();
        var w = pick.offsetWidth;
        var h = pick.offsetHeight;
        pick.style.left = Math.max(8, Math.min(r.left, innerWidth - w - 8)) + 'px';
        pick.style.top = (r.bottom + 6 + h > innerHeight - 8 ? Math.max(8, r.top - 6 - h) : r.bottom + 6) + 'px';
        btn.setAttribute('aria-expanded', 'true');
        svEl.focus();
    }
    function closePick() {
        if (pick.hidden) { return; }
        pick.hidden = true;
        if (pickFrom) { pickFrom.setAttribute('aria-expanded', 'false'); if (pick.contains(document.activeElement)) { pickFrom.focus(); } }
        pickKey = null;
        pickTok = null;
        pickFrom = null;
    }
    svEl.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        svEl.focus();
        svEl.setPointerCapture(e.pointerId);
        var at = function (ev) {
            var r = svEl.getBoundingClientRect();
            hsv[1] = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
            hsv[2] = 1 - Math.max(0, Math.min(1, (ev.clientY - r.top) / r.height));
            pickSet();
        };
        var up = function () { svEl.removeEventListener('pointermove', at); svEl.removeEventListener('pointerup', up); };
        svEl.addEventListener('pointermove', at);
        svEl.addEventListener('pointerup', up);
        at(e);
    });
    svEl.addEventListener('keydown', function (e) {
        var d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, 1], ArrowDown: [0, -1] }[e.key];
        if (!d) { return; }
        e.preventDefault();
        var step = e.shiftKey ? 0.1 : 0.02;
        hsv[1] = Math.max(0, Math.min(1, hsv[1] + d[0] * step));
        hsv[2] = Math.max(0, Math.min(1, hsv[2] + d[1] * step));
        pickSet();
    });
    pick.addEventListener('input', function (e) {
        if (e.target.matches('.nst-fw-pick__hue')) { hsv[0] = +e.target.value; pickSet(); return; }
        var v = e.target.value.trim();
        if (e.target.matches('.nst-fw-pick__hex') && /^#?[0-9a-f]{6}$/i.test(v)) { hsv = hexToHsv('#' + v.replace('#', '')); pickSet(true); }
    });
    pick.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || (e.key === 'Enter' && !e.target.matches('button'))) { e.preventDefault(); e.stopPropagation(); closePick(); }
    });
    pick.addEventListener('click', function (e) {
        if (e.target.closest('[data-pick="done"]')) { closePick(); return; }
        if (e.target.closest('[data-pick="copy"]') && pickTok) { var t = 'var(' + pickTok + ')'; if (navigator.clipboard) { navigator.clipboard.writeText(t).then(function () { kit.toast(sprintf(__('%s copied.', 'nibwp'), t), 'ok'); }, function () { }); } return; }
        if (e.target.closest('[data-pick="reset"]') && pickTok) {
            delete fwDraft.over[pickTok];
            var tok = pickTok;
            var from = pickFrom;
            closePick();
            if (from) { from.classList.remove('is-over'); }
            fwChanged();
            kit.toast(sprintf(__('%s follows its colour again.', 'nibwp'), tok), 'ok');
        }
    });
    document.addEventListener('pointerdown', function (e) { if (!pick.hidden && !pick.contains(e.target) && !e.target.closest('[data-fw-pick]')) { closePick(); } }, true);
    window.addEventListener('resize', closePick);
    if (fwBox) {
        fwBox.addEventListener('input', function (e) {
            var i = e.target;
            if (i.matches('[data-fw-find]')) {
                var q = i.value.trim().toLowerCase();
                fwBox.querySelectorAll('.nst-fw__tok').forEach(function (r) { r.hidden = !!q && r.dataset.find.indexOf(q) < 0; });
                return;
            }
            if (!fwDraft) { return; }
            if (i.matches('[data-fw-tok]')) {
                var n = i.dataset.fwTok;
                if (i.value.trim()) { fwDraft.over[n] = i.value.trim(); } else { delete fwDraft.over[n]; }
                fwChanged();
                return;
            }
            if (!i.matches('[data-fw]')) { return; }
            var k = i.dataset.fw;
            if (k === 'on') { fwDraft.on = i.checked; fwApply(); fwPaint(); return; }
            var v = i.value.trim();
            if (FW_COLORS.some(function (c) { return c[0] === k; })) {
                if (/^#?[0-9a-f]{6}$/i.test(v)) { fwColor(k, '#' + v.replace('#', '').toLowerCase(), i); }
                return;
            } else if (i.type === 'number' || i.tagName === 'SELECT') {
                if (v === '' || isNaN(+v)) { return; }
                v = +v;
            }
            fwDraft.set[k] = v;
            fwChanged();
        });
        fwBox.addEventListener('click', function (e) {
            var pk = e.target.closest('[data-fw-pick]');
            if (pk) { if (pickKey === pk.dataset.fwPick && !pick.hidden) { closePick(); } else { openPick(pk); } return; }
            var base = e.target.closest('[data-fw-base]');
            if (base) { var main = fwBox.querySelector('[data-fw-pick="' + base.dataset.fwBase + '"]'); if (main) { openPick(main); } return; }
            var shade = e.target.closest('[data-fw-shades] [data-var]');
            if (shade) { if (pickTok === shade.dataset.var && !pick.hidden) { closePick(); } else { openPick(shade, shade.dataset.var); } return; }
            var sw = e.target.closest('[data-var]');
            if (sw) { var t = 'var(' + sw.dataset.var + ')'; if (navigator.clipboard) { navigator.clipboard.writeText(t).then(function () { kit.toast(sprintf(__('%s copied.', 'nibwp'), t), 'ok'); }, function () { }); } return; }
            var b = e.target.closest('[data-fw-act]');
            if (!b || !fwDraft) { return; }
            var act = b.dataset.fwAct;
            if (act === 'save') { fwSave(); }
            else if (act === 'discard') { fwDraft = fwCopy(fw); fwTok = fw.tokens || {}; fwCss = fw.css || ''; fwApply(); renderDesign(); }
            else if (act === 'defaults') { fwDraft.set = Object.assign({}, fw.defaults); fwDraft.over = {}; renderDesign(); fwChanged(); }
            else if (act === 'attach') { fwBox.querySelector('[data-fw-file]').click(); }
            else if (act === 'ask') {
                var ta = fwBox.querySelector('[data-fw-ask]');
                var typed = ta.value.trim();
                if (!typed && !kit.files()) { ta.focus(); kit.toast(__('Say what you like, or attach a logo or a screenshot.', 'nibwp')); return; }
                kit.sendText(sprintf(__('Set up NibWP’s CSS framework for this site: %s Read it with nibwp/framework-get, change it with nibwp/framework-set and switch it on, then reload the page so I can see it, and tell me in a few words what you chose.', 'nibwp'), typed || __('use the attached file.', 'nibwp')), b, __('Set up the design', 'nibwp'));
                ta.value = '';
                setTimeout(fwPaint, 50);
                if (!kit.mode || kit.mode() !== 'queue') { kit.toast(__('Sent to NibWP. The Design tab shows its choices when it is done.', 'nibwp'), 'ok'); }
            }
        });
        fwBox.addEventListener('change', function (e) {
            if (e.target.matches('[data-fw-file]') && e.target.files.length) { kit.addFiles(Array.prototype.slice.call(e.target.files)); e.target.value = ''; setTimeout(fwPaint, 300); }
        });
        fwBox.addEventListener('keydown', function (e) {
            if (e.target.matches('[data-fw-ask]') && e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); fwBox.querySelector('[data-fw-act="ask"]').click(); }
        });
    }

    // The bubble's block name: typed, the block follows; a name already in
    // use says so, with a better one a click away.
    var nameT = 0;
    function popName() {
        var w = pop.querySelector('.nst-bd-pop__warn');
        var op = popOp;
        w.hidden = true;
        if (popMode !== 'new' || !op || !op.bem || !op.bem.name || !op.el.isConnected) { return; }
        var c = bemCheck(op.bem.name, op.el, op.keys.indexOf('.' + op.bem.name) > -1);
        if (!c) { return; }
        w.hidden = false;
        w.querySelector('span').textContent = c[1];
        var b = w.querySelector('button');
        b.textContent = sprintf(__('Use %s', 'nibwp'), c[2]);
        b.dataset.name = c[2];
    }
    // A name typed just before the bubble closes still names the block:
    // closePop() calls this before it lets go of the new element.
    function popNameFlush() { if (nameT && popMode === 'new' && popOp) { popNameNow(); } }
    function popNameNow() {
        clearTimeout(nameT);
        nameT = 0;
        var i = pop.querySelector('.nst-bd-pop__name input');
        var v = bemSlug(i.value);
        if (popOp && popOp.bem && v && v !== popOp.bem.name) { renameNew(popOp, v); }
        popName();
    }
    pop.addEventListener('input', function (e) {
        if (!e.target.closest('.nst-bd-pop__name')) { return; }
        clearTimeout(nameT);
        nameT = setTimeout(function () { popNameNow(); placePop(); }, 220);
    });
    pop.querySelector('.nst-bd-pop__name input').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.isComposing) { return; }
        e.preventDefault();
        e.stopPropagation();
        popNameNow();
        pop.querySelector('textarea').focus();
    });
    pop.addEventListener('click', function (e) {
        var b = e.target.closest('[data-bd="pop-alt"]');
        if (!b) { return; }
        pop.querySelector('.nst-bd-pop__name input').value = b.dataset.name;
        popNameNow();
    });

    /* A saved class or ID renamed everywhere the site uses it (on the site
     * at once, Ctrl+Z renames it back); a new one added from the top box. */
    function renameCssText(text, kind, a, b) {
        var q = reEsc(a);
        var re = kind === '#' ? new RegExp('#' + q + '(?![\\w-])', 'g') : new RegExp('\\.' + q + '(?=' + (a.indexOf('__') < 0 ? '__|' : '') + '--|[^\\w-]|$)', 'g');
        return String(text).replace(re, kind + b);
    }
    function renameToken(t, a, b) {
        if (t === a) { return b; }
        var seps = a.indexOf('__') < 0 ? ['__', '--'] : ['--'];
        for (var i = 0; i < seps.length; i++) { if (t.indexOf(a + seps[i]) === 0) { return b + t.slice(a.length); } }
        return t;
    }
    function renameHere(kind, a, b, saved) {
        var next = {};
        Object.keys(cssLive).forEach(function (k) { next[renameCssText(k, kind, a, b)] = renameCssText(cssLive[k], kind, a, b); });
        cssLive = next;
        cfg.css = saved || cfg.css;
        if (doc) {
            if (kind === '.') {
                doc.querySelectorAll('[class]').forEach(function (n) {
                    var c = clsOf(n);
                    var m = c.map(function (t) { return renameToken(t, a, b); });
                    if (m.join(' ') !== c.join(' ')) { n.setAttribute('class', m.join(' ')); }
                });
            } else {
                // Everything that points at the ID follows it, as on the site.
                doc.querySelectorAll('[id="' + CSS.escape(a) + '"]').forEach(function (n) { n.id = b; });
                ['for', 'aria-labelledby', 'aria-controls', 'aria-describedby', 'aria-owns', 'list', 'form', 'headers'].forEach(function (at) {
                    doc.querySelectorAll('[' + at + ']').forEach(function (n) {
                        var v = n.getAttribute(at);
                        var m = v.split(/(\s+)/).map(function (t) { return t === a ? b : t; }).join('');
                        if (m !== v) { n.setAttribute(at, m); }
                    });
                });
                doc.querySelectorAll('a[href], area[href]').forEach(function (n) {
                    var h = n.getAttribute('href');
                    if (h.slice(-(a.length + 1)) === '#' + a) { n.setAttribute('href', h.slice(0, -a.length) + b); }
                });
            }
        }
        if (clsOpen) { clsOpen = renameCssText(clsOpen, kind, a, b); }
        codeFor = null;
        cssFor = null;
        writeCss();
        renderClasses();
        render();
        paint();
        inspect();
    }
    function clsRename(kind, a, b) {
        return kit.api('class-rename', { method: 'POST', body: { kind: kind, from: a, to: b } }).then(function (r) {
            renameHere(kind, a, b, r.css);
            return r;
        }, function (x) { kit.toast(x.message || __('Could not rename it.', 'nibwp'), 'bad'); renderClasses(); throw x; });
    }
    /* A saved class or ID, renamed on the whole site at once (Ctrl+Z back).
     * Refused while any tab has Builder changes not saved: they still carry
     * the old name and would bring it back. Resolves true once renamed. */
    function renameEverywhere(kind, from, to) {
        var k = kind + from;
        var no = function (msg) { kit.toast(msg, 'bad'); renderClasses(); render(); return Promise.resolve(false); };
        if (!/^-?[_a-zA-Z][-_a-zA-Z0-9]*$/.test(to)) { return no(__('Use letters, digits, dashes and underscores, starting with a letter.', 'nibwp')); }
        var here = doc && doc.defaultView && doc.defaultView.frameElement;
        // A design not saved yet, or a wireframe, holds no class names: it does not stop a rename.
        var open = Array.prototype.filter.call(document.querySelectorAll('#nst-artboard iframe[data-nst-unsaved]'), function (f) { return !/^(design|plan)$/.test(f.getAttribute('data-nst-unsaved')); });
        if (pending() || open.indexOf(here) > -1) { return no(__('Save your changes first: a rename changes the whole site at once.', 'nibwp')); }
        if (open.length) { return no(__('Save the changes in your other tabs first: a rename changes the whole site at once.', 'nibwp')); }
        // Asked first without changing anything: a name in use is refused.
        return kit.api('class-rename', { method: 'POST', body: { kind: kind, from: from, to: to, dry: true } }).then(function () {
            return siteAct({
                label: sprintf(__('rename %1$s to %2$s', 'nibwp'), k, kind + to),
                redoSite: function () { return clsRename(kind, from, to); },
                undoSite: function () { return clsRename(kind, to, from); }
            }).then(function (r) {
                kit.toast(sprintf(__('Renamed %1$s to %2$s in %3$d places. Ctrl+Z renames it back.', 'nibwp'), k, kind + to, (r && r.total) || 0), 'ok');
                return true;
            }, function () { render(); return false; });
        }, function (x) { return no(x.message || __('Could not rename it.', 'nibwp')); });
    }
    function clsRenameStart(btn, k) {
        var kind = k.charAt(0);
        var from = k.slice(1);
        var inp = document.createElement('input');
        inp.className = 'nst-bd__clsin';
        inp.value = k;
        inp.spellcheck = false;
        inp.setAttribute('aria-label', sprintf(__('New name for %s', 'nibwp'), k));
        btn.replaceWith(inp);
        inp.focus();
        inp.setSelectionRange(1, k.length);
        var done = false;
        var finish = function (keep) {
            if (done) { return; }
            done = true;
            var to = inp.value.trim().replace(/^[.#]/, '');
            if (!keep || !to || to === from) { renderClasses(); return; }
            renameEverywhere(kind, from, to);
        };
        inp.addEventListener('keydown', function (e) {
            e.stopPropagation();
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    left.addEventListener('submit', function (e) {
        var f = e.target.closest('.nst-bd__clsadd');
        if (!f) { return; }
        e.preventDefault();
        var v = f.querySelector('input').value.trim();
        var kind = v.charAt(0) === '#' ? '#' : '.';
        var name = v.replace(/^[.#]/, '');
        if (!/^-?[_a-zA-Z][-_a-zA-Z0-9]*$/.test(name)) { kit.toast(__('Use letters, digits, dashes and underscores, starting with a letter.', 'nibwp'), 'bad'); return; }
        var key = kind + name;
        if (!(cssLive[key] || '').trim()) { setCss(key, key, cssLive[key] || '', key + ' {\n  \n}\n'); }
        clsOpen = key;
        renderClasses();
        var ed = left.querySelector('.nst-bd__clsed');
        if (ed) { ed.focus(); ed.setSelectionRange(key.length + 5, key.length + 5); }
        kit.toast(sprintf(__('%s is ready: write its CSS, then Save.', 'nibwp'), key), 'ok');
    });

    // The panel moves to the other side by dragging its top edge.
    left.querySelector('[data-bd-drag]').addEventListener('pointerdown', function (e) {
        e.preventDefault();
        var c = canvas.getBoundingClientRect();
        var frame = kit.frame();
        if (frame) { frame.style.pointerEvents = 'none'; }
        left.classList.add('is-moving');
        var move = function (ev) {
            var leftSide = ev.clientX < c.left + c.width / 2;
            if (leftSide !== root.classList.contains('bd-panel-left')) { viewOn.left = leftSide; store('nibwp-bd-view', viewOn); applyView(); }
        };
        var up = function () { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); if (frame) { frame.style.pointerEvents = ''; } left.classList.remove('is-moving'); };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    });
    /* ------------------------------------------------------------------
     * The Content hub: a new page, post or any other kind of content,
     * made at once as a draft and opened in a new tab; what it is about
     * goes to NibWP to build it, the way the chat's mode says.
     * ---------------------------------------------------------------- */

    var hubEl = left.querySelector('.nst-bd__hub');
    var hubB = hubEl.querySelector('.nst-bd__hub-b');
    var hubType = 'page';
    function hubTypes() {
        var seen = {};
        return (cfg.templates || []).filter(function (t) { return t.kind === 'single' && t.type && !seen[t.type] && (seen[t.type] = 1); }).map(function (t) { return { type: t.type, label: t.label }; });
    }
    function hubLabel() { var t = hubTypes().find(function (x) { return x.type === hubType; }); return t ? t.label : hubType; }
    // Starting points: a click writes a plan NibWP can build from.
    var HUB_START = [
        ['landing', __('Landing page', 'nibwp'), __('A landing page: a hero with a clear promise and the main button, three key benefits, how it works, reviews, a call to action band and questions and answers.', 'nibwp')],
        ['about', __('About', 'nibwp'), __('An about page: who we are in one line, our story with a photo, the team, what we value, and a call to action.', 'nibwp')],
        ['service', __('Service or product', 'nibwp'), __('A service or product page: a photo gallery, title, price and highlights beside a booking box, what is included, reviews and related offers.', 'nibwp')],
        ['contact', __('Contact', 'nibwp'), __('A contact page: a short hero, a contact form beside the address, phone, opening hours and a map, then questions and answers.', 'nibwp')],
        ['pricing', __('Pricing', 'nibwp'), __('A pricing page: a hero, three plans side by side, a comparison table, questions and answers and a call to action.', 'nibwp')],
        ['post', __('Article', 'nibwp'), __('An article: title, author and date, a featured image, the text in clear sections, and related posts.', 'nibwp')]
    ];
    var hubSlugEdited = false;
    // Letters and digits of any script stay (WordPress keeps them too, and
    // makes the final address); accents on Latin letters go.
    var SLUG_OFF = (function () { try { return new RegExp('[^\\p{L}\\p{M}\\p{N}]+', 'gu'); } catch (x) { return /[^a-z0-9]+/g; } }());
    function slugify(t) { return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').normalize('NFC').replace(SLUG_OFF, '-').replace(/^-+|-+$/g, '').slice(0, 80); }
    var hubReq = 0;
    // "Recent": the last ones of the type picked; an answer that comes after
    // another type was picked is dropped.
    function hubRecent() {
        var r0 = hubB.querySelector('.nst-bd__hub-r');
        if (r0) { r0.textContent = sprintf(__('Recent: %s', 'nibwp'), hubLabel()); }
        var ul0 = hubB.querySelector('.nst-bd__hub-list');
        if (ul0) { ul0.innerHTML = '<li class="nst-bd__hint">' + esc(__('Loading…', 'nibwp')) + '</li>'; }
        var ask = ++hubReq;
        kit.api('content?type=' + encodeURIComponent(hubType)).then(function (r) {
            var ul = hubB.querySelector('.nst-bd__hub-list');
            if (!ul || ask !== hubReq) { return; }
            ul.innerHTML = (r && r.items && r.items.length ? r.items : []).map(function (it) {
                return '<li data-id="' + (+it.id) + '" data-slug="' + esc(it.slug || '') + '"><span class="nst-bd__hub-main"><button type="button" class="nst-bd__hub-name" data-bd-hubren data-tip="' + esc(__('Rename', 'nibwp')) + '">' + esc(it.title) + '</button><small class="nst-bd__pill" data-state="' + esc(it.state || '') + '">' + esc(it.status) + '</small></span>' +
                    '<button type="button" class="nst-bd__ib" data-bd-hubrename data-tip="' + esc(__('Rename', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Rename %s and its address', 'nibwp'), it.title)) + '">' + ico('retitle', 14) + '</button>' +
                    '<button type="button" class="nst-bd__ib" data-bd-hubopen="' + esc(it.url) + '" data-tip="' + esc(__('Open in the Builder', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Open %s here', 'nibwp'), it.title)) + '">' + ico('pageopen', 14) + '</button>' +
                    (it.edit ? '<button type="button" class="nst-bd__ib" data-bd-hubedit="' + esc(it.edit) + '" data-tip="' + esc(__('Edit in WordPress', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Edit %s in WordPress', 'nibwp'), it.title)) + '">' + ico('sketch', 14) + '</button>' : '') +
                    '<button type="button" class="nst-bd__ib is-danger" data-bd-hubdel data-tip="' + esc(__('Delete', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Delete %s', 'nibwp'), it.title)) + '">' + ico('trash', 14) + '</button></li>';
            }).join('') || '<li class="nst-bd__hint">' + esc(__('Nothing yet.', 'nibwp')) + '</li>';
        }, function () { var ul = hubB.querySelector('.nst-bd__hub-list'); if (ul && ask === hubReq) { ul.innerHTML = ''; } });
    }
    function paintHub() {
        var types = hubTypes();
        if (!types.some(function (t) { return t.type === hubType; }) && types[0]) { hubType = types[0].type; }
        var m = kit.mode ? kit.mode() : 'auto';
        var modes = kit.modes ? kit.modes() : null;
        hubSlugEdited = false;
        hubB.innerHTML = '<div class="nst-bd__types nst-bd__hub-types" role="radiogroup" aria-label="' + esc(__('What to make', 'nibwp')) + '">' + types.map(function (t) { return '<label><input type="radio" name="hubtype" value="' + esc(t.type) + '"' + (t.type === hubType ? ' checked' : '') + '><span>' + esc(t.label) + '</span></label>'; }).join('') + '</div>' +
            '<div class="nst-bd__hub-box">' +
                '<input type="text" class="nst-bd__hub-t" placeholder="' + esc(sprintf(__('%s title', 'nibwp'), hubLabel())) + '" aria-label="' + esc(__('Title', 'nibwp')) + '">' +
                '<div class="nst-bd__hub-slug">' + ico('link', 12) + '<span>/</span><span class="nst-bd__hub-sv" data-bd-slug tabindex="0" role="button" data-tip="' + esc(__('Edit the address', 'nibwp')) + '">' + esc(__('the-address-follows-the-title', 'nibwp')) + '</span><input type="text" class="nst-bd__hub-si" hidden spellcheck="false" aria-label="' + esc(__('Address (slug)', 'nibwp')) + '"></div>' +
            '</div>' +
            '<div class="nst-bd__hub-start">' + HUB_START.map(function (x) { return '<button type="button" data-bd-start="' + x[0] + '">' + esc(x[1]) + '</button>'; }).join('') + '</div>' +
            '<div class="nst-bd__hub-ask"><textarea class="nst-bd__hub-a" rows="3" placeholder="' + esc(__('What is it about? Write it, or pick a starting point above. Leave it empty to just create it.', 'nibwp')) + '" aria-label="' + esc(__('What it is about', 'nibwp')) + '"></textarea>' +
                '<div class="nst-bd__hub-go">' + (modes ? '<button type="button" class="nst-bd-pop__mode" data-bd-hubmode data-mode="' + m + '">' + esc(modes[m].label) + '</button>' : '') + '<button type="button" class="nst-bd__btn is-main" data-bd-hubgo></button></div></div>' +
            '<p class="nst-bd__hub-r">' + esc(sprintf(__('Recent: %s', 'nibwp'), hubLabel())) + '</p><ul class="nst-bd__hub-list"><li class="nst-bd__hint">' + esc(__('Loading…', 'nibwp')) + '</li></ul>';
        hubGo();
        var typesRow = hubB.querySelector('.nst-bd__hub-types');
        slideRow(typesRow);
        var picked = typesRow.querySelector('input:checked');
        if (picked) {
            var lab = picked.closest('label');
            if (lab.offsetLeft + lab.offsetWidth > typesRow.clientWidth - 12) { typesRow.scrollLeft = lab.offsetLeft - 12; }
        }
        hubRecent();
    }
    // One button: Create when there is nothing to build, Ask NibWP when there is.
    function hubGo() {
        var go = hubB.querySelector('[data-bd-hubgo]');
        var a = hubB.querySelector('.nst-bd__hub-a');
        if (!go || !a) { return; }
        var ask = !!a.value.trim();
        var modes = kit.modes ? kit.modes() : null;
        var m = kit.mode ? kit.mode() : 'auto';
        go.innerHTML = ask ? ico('send', 13) + '<span>' + esc(m !== 'auto' && modes ? modes[m].send : __('Ask NibWP', 'nibwp')) + '</span>' : ico('plus', 13) + '<span>' + esc(__('Create', 'nibwp')) + '</span>';
        go.classList.toggle('is-ask', ask);
        var mb = hubB.querySelector('[data-bd-hubmode]');
        if (mb) { mb.hidden = !ask; }
    }
    function hubSlug() {
        var si = hubB.querySelector('.nst-bd__hub-si');
        var t = hubB.querySelector('.nst-bd__hub-t').value;
        return hubSlugEdited ? slugify(si.value) : slugify(t);
    }
    // While it is being made: a spinner in the middle of the hub, and nothing to click twice.
    var hubBusy = document.createElement('div');
    hubBusy.className = 'nst-bd__hub-busy';
    hubBusy.hidden = true;
    hubBusy.setAttribute('role', 'status');
    hubBusy.innerHTML = '<i class="nst-bd__spin" aria-hidden="true"></i><span></span>';
    hubEl.appendChild(hubBusy);
    function hubWait(title) {
        hubBusy.hidden = !title;
        hubEl.classList.toggle('is-busy', !!title);
        if (title) {
            hubBusy.style.top = hubEl.querySelector('[data-bd-hub]').offsetHeight + 'px';
            /* translators: %s: the title of the page being made */
            hubBusy.querySelector('span').textContent = sprintf(__('Creating “%s”…', 'nibwp'), title);
        }
    }
    function hubCreate() {
        if (!hubBusy.hidden) { return; }
        var title = hubB.querySelector('.nst-bd__hub-t').value.trim();
        var about = hubB.querySelector('.nst-bd__hub-a').value.trim();
        if (!title) { hubB.querySelector('.nst-bd__hub-t').focus(); return; }
        var go = hubB.querySelector('[data-bd-hubgo]');
        go.disabled = true;
        hubWait(title);
        var label = hubLabel();
        kit.api('page-new', { method: 'POST', body: { title: title, type: hubType, slug: hubSlug() } }).then(function (r) {
            go.disabled = false;
            hubWait('');
            if (!r || !r.url) { kit.toast((r && r.message) || __('Could not create it.', 'nibwp'), 'bad'); return; }
            bridge.openTab(r.url);
            if (about) {
                /* translators: 1: kind of content, 2: its title, 3: its address, 4: what it is about */
                kit.sendText(sprintf(__('Build the new %1$s “%2$s” (a draft at %3$s): %4$s Use the builder and the design system this site uses, and keep it a draft.', 'nibwp'), label.toLowerCase(), title, r.url, about), go, sprintf(__('Build %1$s: %2$s', 'nibwp'), label.toLowerCase(), title));
            }
            kit.toast(about ? sprintf(__('“%s” is made and open in a new tab. NibWP builds it from what you said.', 'nibwp'), title) : sprintf(__('“%s” is made and open in a new tab. Build it here, or ask NibWP.', 'nibwp'), title), 'ok');
            paintHub();
        }, function (x) { go.disabled = false; hubWait(''); kit.toast((x && x.message) || __('Could not create it.', 'nibwp'), 'bad'); });
    }
    hubEl.addEventListener('input', function (e) {
        if (e.target.matches('.nst-bd__hub-a')) { hubGo(); hubB.querySelectorAll('[data-bd-start]').forEach(function (b) { b.classList.remove('is-on'); }); }
        if (e.target.matches('.nst-bd__hub-t') && !hubSlugEdited) { var sv = hubB.querySelector('[data-bd-slug]'); sv.textContent = slugify(e.target.value) || __('the-address-follows-the-title', 'nibwp'); }
        if (e.target.matches('.nst-bd__hub-si')) { hubSlugEdited = true; }
    });
    hubEl.addEventListener('focusout', function (e) {
        if (e.target.matches('.nst-bd__hub-si')) {
            var sv = hubB.querySelector('[data-bd-slug]');
            var v = slugify(e.target.value);
            if (!v) { hubSlugEdited = false; v = slugify(hubB.querySelector('.nst-bd__hub-t').value); }
            sv.textContent = v || __('the-address-follows-the-title', 'nibwp');
            e.target.hidden = true;
            sv.hidden = false;
        }
    });
    // Open, the hub has the panel to itself: the tabs and their content
    // above go grey and still, and a click on them folds the hub away.
    function hubSet(open) {
        hubEl.querySelector('[data-bd-hub]').setAttribute('aria-expanded', String(open));
        hubEl.classList.toggle('is-open', open);
        left.classList.toggle('is-hub', open);
        Array.prototype.forEach.call(left.querySelectorAll(':scope > .nst-bd__head, :scope > .nst-bd__view'), function (n) { n.inert = open; });
        hubB.hidden = !open;
        if (open) { paintHub(); setTimeout(function () { var t = hubB.querySelector('.nst-bd__hub-t'); if (t) { t.focus(); } }, 30); }
    }
    left.addEventListener('click', function (e) {
        if (e.target === left && hubEl.classList.contains('is-open')) { hubSet(false); }
    });
    hubEl.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !hubEl.classList.contains('is-open') || e.target.matches('.nst-bd__hub-si, .nst-bd__hub-in')) { return; }
        e.preventDefault();
        e.stopPropagation();
        hubSet(false);
        hubEl.querySelector('[data-bd-hub]').focus();
    });
    hubEl.addEventListener('click', function (e) {
        var h = e.target.closest('[data-bd-hub]');
        if (h) {
            hubSet(h.getAttribute('aria-expanded') !== 'true');
            return;
        }
        var mb = e.target.closest('[data-bd-hubmode]');
        if (mb && kit.pickMode) { kit.pickMode(mb); return; }
        var sl = e.target.closest('[data-bd-slug]');
        if (sl) { var si = hubB.querySelector('.nst-bd__hub-si'); si.value = sl.textContent === __('the-address-follows-the-title', 'nibwp') ? '' : sl.textContent; sl.hidden = true; si.hidden = false; si.focus(); si.select(); return; }
        var st = e.target.closest('[data-bd-start]');
        if (st) { var def = HUB_START.find(function (x) { return x[0] === st.dataset.bdStart; }); var ta = hubB.querySelector('.nst-bd__hub-a'); ta.value = def[2]; hubB.querySelectorAll('[data-bd-start]').forEach(function (b) { b.classList.toggle('is-on', b === st); }); hubGo(); ta.focus(); var tt = hubB.querySelector('.nst-bd__hub-t'); if (!tt.value) { tt.value = def[1]; tt.dispatchEvent(new Event('input', { bubbles: true })); } return; }
        if (e.target.closest('[data-bd-hubgo]')) { hubCreate(); return; }
        var o = e.target.closest('[data-bd-hubopen]');
        if (o) { bridge.openTab(o.dataset.bdHubopen); }
    });
    // A recent item: its title renamed in place; deleted on a second click
    // (to the WordPress trash).
    hubEl.addEventListener('click', function (e) {
        var he = e.target.closest('[data-bd-hubedit]');
        if (he) { bridge.openTab(he.dataset.bdHubedit); return; }
        var ren = e.target.closest('[data-bd-hubren], [data-bd-hubrename]');
        var del = e.target.closest('[data-bd-hubdel]');
        var li = (ren || del) && (ren || del).closest('li[data-id]');
        if (!li) { return; }
        var id = +li.dataset.id;
        if (del) {
            askDel(li, __('Delete it?', 'nibwp'), 'trash', __('Delete', 'nibwp'), function () {
                var name = li.querySelector('.nst-bd__hub-name').textContent;
                li.classList.add('is-gone');
                kit.api('content-edit', { method: 'POST', body: { id: id, do: 'trash' } }).then(function () {
                    li.remove();
                    kit.toast(sprintf(__('“%s” is in the trash.', 'nibwp'), name), 'ok');
                }, function (x) { li.classList.remove('is-gone'); kit.toast(x.message || __('Could not delete it.', 'nibwp'), 'bad'); });
            });
            return;
        }
        if (li.classList.contains('is-editing')) { return; }
        var nameEl = li.querySelector('.nst-bd__hub-name');
        var main = li.querySelector('.nst-bd__hub-main');
        var was = nameEl.textContent;
        // WordPress keeps a non-Latin address percent-encoded: shown and compared as it reads.
        var wasSlug = li.dataset.slug || '';
        try { wasSlug = decodeURIComponent(wasSlug); } catch (x) { }
        var ed = document.createElement('span');
        ed.className = 'nst-bd__hub-ed';
        ed.innerHTML = '<input type="text" class="nst-bd__hub-in" aria-label="' + esc(__('Title', 'nibwp')) + '">' +
            '<span class="nst-bd__hub-edslug">' + ico('link', 11) + '<span>/</span><input type="text" class="nst-bd__hub-in" spellcheck="false" placeholder="' + esc(__('the-address-follows-the-title', 'nibwp')) + '" aria-label="' + esc(__('Address (slug)', 'nibwp')) + '"></span>' +
            '<small class="nst-bd__hub-edhint">' + esc(__('Enter saves · Esc cancels', 'nibwp')) + '</small>';
        var tIn = ed.querySelectorAll('input')[0];
        var sIn = ed.querySelectorAll('input')[1];
        tIn.value = was;
        sIn.value = wasSlug;
        main.after(ed);
        li.classList.add('is-editing');
        tIn.focus();
        tIn.select();
        var done = false;
        var finish = function (keep) {
            if (done) { return; }
            done = true;
            var v = tIn.value.trim();
            var sl = slugify(sIn.value);
            ed.remove();
            li.classList.remove('is-editing');
            var body = { id: id, do: 'rename', title: v || was };
            if (sl && sl !== wasSlug) { body.slug = sl; }
            if (!keep || (!v || v === was) && !body.slug) { return; }
            nameEl.textContent = body.title;
            kit.api('content-edit', { method: 'POST', body: body }).then(function (r) {
                li.dataset.slug = r.slug || '';
                var op = li.querySelector('[data-bd-hubopen]');
                if (op && r.url) { op.dataset.bdHubopen = r.url; }
                var shown = r.slug || '';
                try { shown = decodeURIComponent(shown); } catch (x2) { }
                kit.toast(r.slug && body.slug ? sprintf(__('Renamed. The address is /%s.', 'nibwp'), shown) : sprintf(__('Renamed to “%s”.', 'nibwp'), body.title), 'ok');
            }, function (x) { nameEl.textContent = was; kit.toast(x.message || __('Could not rename it.', 'nibwp'), 'bad'); });
        };
        ed.addEventListener('keydown', function (ev) {
            ev.stopPropagation();
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
        });
        ed.addEventListener('focusout', function (ev) { if (!ed.contains(ev.relatedTarget)) { finish(true); } });
    });
    // Another type: only what depends on it changes. What is typed, the
    // address and the starting point stay, and so does the keyboard.
    hubEl.addEventListener('change', function (e) {
        if (e.target.name === 'hubtype') {
            hubType = e.target.value;
            var t = hubB.querySelector('.nst-bd__hub-t');
            if (t) { t.placeholder = sprintf(__('%s title', 'nibwp'), hubLabel()); }
            hubRecent();
        }
    });
    hubEl.addEventListener('keydown', function (e) {
        // The address reads like text but opens like a button: Enter or Space edits it.
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-bd-slug]')) { e.preventDefault(); e.target.click(); return; }
        if (e.key === 'Enter' && !e.shiftKey && (e.target.matches('.nst-bd__hub-t') || e.target.matches('.nst-bd__hub-a'))) { e.preventDefault(); hubCreate(); }
        if (e.key === 'Enter' && e.target.matches('.nst-bd__hub-si')) { e.preventDefault(); e.target.blur(); }
        if (e.key === 'Escape' && e.target.matches('.nst-bd__hub-si')) { e.preventDefault(); e.stopPropagation(); hubSlugEdited = false; e.target.value = ''; e.target.blur(); }
    });
    document.addEventListener('nibwp-studio-mode', function () {
        var mb = hubEl.querySelector('[data-bd-hubmode]');
        if (mb && kit.mode) { mb.dataset.mode = kit.mode(); mb.textContent = kit.modes()[kit.mode()].label; }
        hubGo();
    });
    function counts() {
        left.querySelectorAll('.nst-bd__grp').forEach(function (g) { g.querySelector('.nst-bd__grp-n').textContent = String(g.querySelectorAll('.nst-bd__tile[data-find]').length || ''); });
    }
    function renderWires() {
        var mine = (cfg.components || []).filter(function (c) { return c.kind === 'wireframe'; });
        var old = window.nibwpSketch ? window.nibwpSketch.list() : [];
        left.querySelector('.nst-bd__wires').innerHTML =
            mine.map(function (c) {
                return '<div class="nst-bd__tile is-wire is-mine" draggable="true" data-bd-wire="' + esc(c.id) + '" data-find="' + esc((c.name + ' layout').toLowerCase()) + '" role="button" tabindex="0" title="' + esc(c.name) + '">' + ico('tpl', 18) + '<span>' + esc(c.name) + '</span><button type="button" class="nst-bd__tile-x" data-bd-comp-del="' + esc(c.id) + '" data-tip="' + esc(__('Delete', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Delete the %s wireframe', 'nibwp'), c.name)) + '">' + ico('x', 11) + '</button></div>';
            }).join('') +
            WIRES.map(function (w) {
                return '<button type="button" class="nst-bd__tile is-wire" draggable="true" data-bd-wire="' + esc(w.id) + '" data-find="' + esc((w.name + ' layout').toLowerCase()) + '" title="' + esc(w.name) + '">' + ico('tpl', 18) + '<span>' + esc(w.name) + '</span></button>';
            }).join('') +
            (old.length ? '<button type="button" class="nst-bd__sub" data-bd-old aria-expanded="' + !!shut.oldOpen + '">' + ico('down', 12) + esc(sprintf(__('Older sketches (%d)', 'nibwp'), old.length)) + '</button>' : '') +
            old.map(function (w) {
                return '<button type="button"' + (shut.oldOpen ? '' : ' data-old') + ' class="nst-bd__tile is-sketch" draggable="true" data-bd-sketch="' + esc(w.id) + '" data-find="' + esc((w.name + ' layout').toLowerCase()) + '" title="' + esc(w.name) + '">' + ico('grid', 18) + '<span>' + esc(w.name) + '</span></button>';
            }).join('');
        counts();
    }
    // A wireframe you saved: the × in its corner, then "Delete it?" to be
    // sure. The ready-made ones have no ×; the server only keeps yours.
    function askWireDel(tile, id) {
        left.querySelectorAll('.nst-bd__tile-ask').forEach(closeWireAsk);
        var w = (cfg.components || []).find(function (c) { return c.id === id && c.kind === 'wireframe'; });
        if (!tile || !w) { return; }
        tile.draggable = false;
        tile.classList.add('is-asking');
        tile.insertAdjacentHTML('beforeend', '<div class="nst-bd__tile-ask" role="group" aria-label="' + esc(sprintf(__('Delete the %s wireframe?', 'nibwp'), w.name)) + '"><b>' + esc(__('Delete it?', 'nibwp')) + '</b><span>' +
            '<button type="button" data-bd-wire-no data-tip="' + esc(__('Cancel', 'nibwp')) + '" aria-label="' + esc(__('Cancel', 'nibwp')) + '">' + ico('x', 12) + '</button>' +
            '<button type="button" class="is-del" data-bd-wire-yes="' + esc(id) + '" data-tip="' + esc(__('Delete', 'nibwp')) + '" aria-label="' + esc(sprintf(__('Delete the %s wireframe', 'nibwp'), w.name)) + '">' + ico('trash', 12) + '</button></span></div>');
        tile.querySelector('[data-bd-wire-no]').focus();
    }
    function closeWireAsk(ask) {
        var tile = ask.closest('.nst-bd__tile');
        ask.remove();
        if (tile) { tile.draggable = true; tile.classList.remove('is-asking'); }
    }
    function deleteWire(tile, id) {
        var w = (cfg.components || []).find(function (c) { return c.id === id; });
        hideTip();
        tile.classList.add('is-going');
        kit.api('component-delete', { method: 'POST', body: { id: id } }).then(function () {
            cfg.components = (cfg.components || []).filter(function (c) { return c.id !== id; });
            renderWires();
            kit.toast(sprintf(__('Wireframe “%s” deleted.', 'nibwp'), w ? w.name : id), 'ok');
        }, function (x) {
            tile.classList.remove('is-going');
            kit.toast((x && x.message) || __('Could not delete the wireframe. Try again.', 'nibwp'), 'bad');
        });
    }
    left.addEventListener('keydown', function (e) {
        var ask = e.key === 'Escape' && e.target.closest('.nst-bd__tile-ask');
        if (ask) { e.preventDefault(); e.stopPropagation(); var t = ask.closest('.nst-bd__tile'); closeWireAsk(ask); if (t) { t.querySelector('[data-bd-comp-del]').focus(); } return; }
        // A saved wireframe's tile holds its delete button, so it is not a
        // <button> itself: Enter or Space adds it all the same.
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.nst-bd__tile[role="button"]')) { e.preventDefault(); e.target.click(); }
    });
    function wireById(id) {
        var c = (cfg.components || []).find(function (x) { return x.id === id && x.kind === 'wireframe'; });
        if (c) { try { return { id: c.id, name: c.name, spec: JSON.parse(c.html) }; } catch (x) { return null; } }
        return WIRES.find(function (w) { return w.id === id; }) || null;
    }
    /* ------------------------------------------------------------------
     * The left panel.
     * ---------------------------------------------------------------- */

    left.addEventListener('click', function (e) {
        var v = e.target.closest('[data-bd-view]');
        if (v) { setView(v.dataset.bdView); return; }
        var g = e.target.closest('[data-bd-grp]');
        if (g) {
            var sec = g.closest('.nst-bd__grp');
            var isShut = !sec.hasAttribute('data-shut');
            sec.toggleAttribute('data-shut', isShut);
            g.setAttribute('aria-expanded', String(!isShut));
            shut[g.dataset.bdGrp] = isShut;
            store('nibwp-bd-groups', shut);
            return;
        }
        if (e.target.closest('[data-bd-old]')) { shut.oldOpen = !shut.oldOpen; store('nibwp-bd-groups', shut); renderWires(); return; }
        var mk = e.target.closest('[data-bd-make]');
        if (mk) { makeForm(mk.dataset.bdMake); return; }
        var del = e.target.closest('[data-bd-comp-del]');
        if (del) { hideTip(); askWireDel(del.closest('.nst-bd__tile'), del.dataset.bdCompDel); return; }
        var wask = e.target.closest('.nst-bd__tile-ask');
        if (wask) {
            var yes = e.target.closest('[data-bd-wire-yes]');
            if (yes) { deleteWire(wask.closest('.nst-bd__tile'), yes.dataset.bdWireYes); }
            else if (e.target.closest('[data-bd-wire-no]')) { closeWireAsk(wask); }
            return;
        }
        var tile = e.target.closest('[data-bd-add], [data-bd-sketch], [data-bd-comp], [data-bd-wire]');
        if (tile && doc && !blocked) {
            var s = defaultSpot();
            addNew(tileWhat(tile), s.el, s.pos);
            return;
        }
        var a = e.target.closest('[data-bd]');
        if (!a) { return; }
        switch (a.dataset.bd) {
            case 'close': stop(); break;
            case 'undo': undo(); break;
            case 'redo': redo(); break;
            case 'save': save(a); break;
            case 'refresh': release(true); break;
            case 'home': bridge.openTab(boot.home); break;
            case 'sketch': if (window.nibwpSketch) { window.nibwpSketch.open(null); } break;
            case 'frame': setDrawing(!drawing); break;
            case 'foldall':
                var opening = a.dataset.state !== 'open';
                open = new WeakSet();
                if (doc) {
                    open.add(doc.body);
                    if (opening) { Array.prototype.forEach.call(doc.body.querySelectorAll('*'), function (el, i) { if (i < 1500 && treeKids(el).length && !LEAF.test(el.localName)) { open.add(el); } }); }
                    render();
                }
                a.dataset.state = opening ? 'open' : '';
                a.innerHTML = ico(opening ? 'fold' : 'unfold', 13);
                a.setAttribute('data-tip', opening ? __('Fold', 'nibwp') : __('Unfold', 'nibwp'));
                a.setAttribute('aria-label', opening ? __('Fold', 'nibwp') : __('Unfold', 'nibwp'));
                break;
        }
    });

    // The tabs and their panels, joined for screen readers; one Tab stop.
    left.querySelectorAll('[data-bd-view]').forEach(function (b) {
        b.id = 'nst-bd-tab-' + b.dataset.bdView;
        b.setAttribute('aria-controls', 'nst-bd-view-' + b.dataset.bdView);
    });
    left.querySelectorAll('.nst-bd__view').forEach(function (p) {
        p.id = 'nst-bd-view-' + p.dataset.view;
        p.setAttribute('role', 'tabpanel');
        p.setAttribute('aria-labelledby', 'nst-bd-tab-' + p.dataset.view);
    });
    // Left and right (mirrored right to left), Home and End move between the tabs.
    left.querySelector('[role="tablist"]').addEventListener('keydown', function (e) {
        var tabs = Array.prototype.filter.call(left.querySelectorAll('[data-bd-view]'), function (b) { return b.offsetParent; });
        var i = tabs.indexOf(document.activeElement);
        if (i < 0 || e.altKey || e.ctrlKey || e.metaKey) { return; }
        var rtl = getComputedStyle(e.currentTarget).direction === 'rtl';
        var to = { ArrowRight: rtl ? i - 1 : i + 1, ArrowLeft: rtl ? i + 1 : i - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (to === undefined) { return; }
        e.preventDefault();
        var t = tabs[(to + tabs.length) % tabs.length];
        setView(t.dataset.bdView);
        t.focus();
    });
    function setView(v) {
        view = v;
        left.querySelectorAll('[data-bd-view]').forEach(function (b) { var on1 = b.dataset.bdView === v; b.setAttribute('aria-selected', String(on1)); b.setAttribute('tabindex', on1 ? '0' : '-1'); });
        left.querySelectorAll('.nst-bd__view').forEach(function (p) { p.hidden = p.dataset.view !== v || (blocked && v !== 'templates'); });
        if (v !== 'templates') { shotStop(); }
        if (v === 'templates') { renderTemplates(); }
        if (v === 'classes') { renderClasses(); }
        if (v === 'add') { renderWires(); }
        if (v === 'design') { fwLoad(); }
    }

    /* ------------------------------------------------------------------
     * View: switch parts of the Builder on and off, to work the way you
     * like; remembered. And the keyboard shortcuts, behind Ctrl+/.
     * ---------------------------------------------------------------- */

    var VIEW = [
        ['inserts', __('Quick add bar', 'nibwp'), __('Section, div, heading… along the bottom', 'nibwp')],
        ['panel', __('Side panel', 'nibwp'), __('Add, Structure and Templates', 'nibwp')],
        ['details', __('Element details', 'nibwp'), __('Text, link, class, ID, attributes', 'nibwp')],
        ['code', __('Code editor', 'nibwp'), __('Opens with what you select', 'nibwp')],
        ['add', __('Add', 'nibwp'), __('Elements and wireframes', 'nibwp')],
        ['classes', __('Classes', 'nibwp'), __('Saved classes and CSS variables', 'nibwp')],
        ['templates', __('Templates', 'nibwp'), __('Templates for each kind of content', 'nibwp')],
        ['design', __('Design', 'nibwp'), __('Colours, type and spacing for the whole site', 'nibwp')],
        ['left', __('Panel on the left', 'nibwp'), __('Or drag the panel by its top edge', 'nibwp')],
        ['hub', __('Content hub', 'nibwp'), __('New pages, posts and more', 'nibwp')]
    ];
    var viewOn = Object.assign({ inserts: true, panel: true, details: true, code: true, add: true, classes: true, templates: true, design: true, hub: true, left: store('nibwp-bd-side') === 'left' }, store('nibwp-bd-view') || {});
    function applyView() {
        VIEW.forEach(function (v) { if (v[0] !== 'left') { root.classList.toggle('bd-no-' + v[0], !viewOn[v[0]]); } });
        root.classList.toggle('bd-panel-left', !!viewOn.left);
        store('nibwp-bd-side', viewOn.left ? 'left' : 'right');
        if (!viewOn.code && !pageCode) { dockOpen = false; }
        if ((view === 'add' && !viewOn.add) || (view === 'templates' && !viewOn.templates) || (view === 'classes' && !viewOn.classes) || (view === 'design' && !viewOn.design)) { setView('tree'); }
        setTimeout(function () { showSize(false); paint(); }, 220);
    }
    // A small panel of switches (not a menu): it takes the keyboard when it
    // opens and gives it back to its button when it closes.
    var vmenu = document.createElement('div');
    vmenu.className = 'nst-bd-vmenu';
    vmenu.hidden = true;
    vmenu.setAttribute('role', 'dialog');
    vmenu.setAttribute('aria-label', __('Show in the Builder', 'nibwp'));
    root.appendChild(vmenu);
    var vmenuBtn = bar.querySelector('[data-bd="view"]');
    vmenuBtn.setAttribute('aria-haspopup', 'dialog');
    vmenuBtn.setAttribute('aria-expanded', 'false');
    function vmenuClose(back) {
        if (vmenu.hidden) { return; }
        var had = vmenu.contains(document.activeElement);
        vmenu.hidden = true;
        vmenuBtn.setAttribute('aria-expanded', 'false');
        if (back && had) { vmenuBtn.focus(); }
    }
    function viewMenu(btn) {
        if (!vmenu.hidden) { vmenuClose(true); return; }
        vmenu.innerHTML = '<b class="nst-bd-vmenu__h">' + esc(__('Show in the Builder', 'nibwp')) + '</b>' + VIEW.map(function (v) {
            return '<label class="nst-bd-vmenu__it"><span><b>' + esc(v[1]) + '</b><small>' + esc(v[2]) + '</small></span><input type="checkbox" role="switch" data-bd-v="' + v[0] + '"' + (viewOn[v[0]] ? ' checked' : '') + '><i aria-hidden="true"></i></label>';
        }).join('') + '<button type="button" class="nst-bd-vmenu__keys" data-bd-keys>' + ico('keys', 15) + '<span>' + esc(__('Keyboard shortcuts', 'nibwp')) + '</span><kbd>' + esc(KEYNAME('Ctrl+/')) + '</kbd></button>';
        vmenu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        var r = btn.getBoundingClientRect();
        vmenu.style.top = (r.top - vmenu.offsetHeight - 10) + 'px';
        vmenu.style.left = Math.max(8, r.left) + 'px';
        var first = vmenu.querySelector('input');
        if (first) { first.focus({ preventScroll: true }); }
    }
    vmenu.addEventListener('change', function (e) {
        var c = e.target.closest('[data-bd-v]');
        if (!c) { return; }
        viewOn[c.dataset.bdV] = c.checked;
        store('nibwp-bd-view', viewOn);
        applyView();
        inspect();
    });
    vmenu.addEventListener('click', function (e) { if (e.target.closest('[data-bd-keys]')) { vmenuClose(false); keysSheet(true, vmenuBtn); } });
    document.addEventListener('pointerdown', function (e) { if (!vmenu.hidden && !vmenu.contains(e.target) && !e.target.closest('[data-bd="view"]')) { vmenuClose(false); } }, true);

    var KEYS = [
        ['Ctrl+B', __('Open or close the Builder', 'nibwp')],
        ['Esc', __('Let go of the selection, then close', 'nibwp')],
        ['Enter', __('Ask NibWP about what is selected', 'nibwp')],
        [__('Type', 'nibwp'), __('Edit the name of what is selected in Structure', 'nibwp')],
        ['Ctrl+Shift+L', __('Draw a wireframe', 'nibwp')],
        ['Ctrl+Shift+D / Ctrl+Shift+M', __('Draw or comment on the page', 'nibwp')],
        ['Ctrl+S', __('Save', 'nibwp')],
        ['Ctrl+Z / Ctrl+Shift+Z', __('Undo / redo', 'nibwp')],
        ['Ctrl+D', __('Duplicate', 'nibwp')],
        ['Ctrl+C / Ctrl+X / Ctrl+V', __('Copy / cut / paste, also into another tab', 'nibwp')],
        ['Ctrl+Enter', __('Add a class, ID or attribute', 'nibwp')],
        ['Delete', __('Delete', 'nibwp')],
        ['Alt+↑ / Alt+↓', __('Move up / down among its neighbours', 'nibwp')],
        ['Ctrl+↑', __('Select the parent', 'nibwp')],
        ['Ctrl+Shift+H', __('Hide in the Builder', 'nibwp')],
        ['Ctrl+E', __('Code editor', 'nibwp')],
        ['Ctrl+Alt+1–5', __('Add, Structure, Classes, Templates, Design', 'nibwp')],
        [__('Right-click', 'nibwp'), __('BEM, and more', 'nibwp')],
        ['Ctrl+/', __('These shortcuts', 'nibwp')]
    ];
    var KEYNAME = function (s) { return window.nibwpKeyLabel ? window.nibwpKeyLabel(s) : s; };
    var MACKEYS = KEYNAME('Ctrl+') !== 'Ctrl+';
    var keysEl = document.createElement('div');
    keysEl.className = 'nst-bd-keys';
    keysEl.hidden = true;
    keysEl.setAttribute('role', 'dialog');
    keysEl.setAttribute('aria-label', __('Keyboard shortcuts', 'nibwp'));
    keysEl.innerHTML = '<div class="nst-bd-keys__card"><header><b>' + esc(__('Keyboard shortcuts', 'nibwp')) + '</b><button type="button" class="nst-bd__ib" data-bd-keys-x data-tip="' + esc(__('Close', 'nibwp')) + '" aria-label="' + esc(__('Close', 'nibwp')) + '">' + ico('x', 14) + '</button></header><dl>' +
        KEYS.map(function (k) {
            return '<div><dt>' + k[0].split(' / ').map(function (combo) {
                return combo.split('+').map(function (x) { return '<kbd>' + esc(MACKEYS ? ({ Ctrl: '⌘', Shift: '⇧', Alt: '⌥' }[x] || x) : x) + '</kbd>'; }).join('');
            }).join('<span>/</span>') + '</dt><dd>' + esc(k[1]) + '</dd></div>';
        }).join('') + '</dl></div>';
    keysEl.setAttribute('aria-modal', 'true');
    root.appendChild(keysEl);
    // Open, it holds the keyboard (Tab stays inside); closed, it gives it
    // back to where it was, or to the button it was opened from.
    var keysFrom = null;
    function keysSheet(show, from) {
        if (show === !keysEl.hidden) { return; }
        if (show) {
            keysFrom = from || document.activeElement;
            keysEl.hidden = false;
            keysEl.querySelector('[data-bd-keys-x]').focus();
            return;
        }
        var had = keysEl.contains(document.activeElement);
        keysEl.hidden = true;
        if (had && keysFrom && keysFrom.isConnected && keysFrom !== document.body) { keysFrom.focus({ preventScroll: true }); }
        keysFrom = null;
    }
    keysEl.addEventListener('click', function (e) { if (e.target === keysEl || e.target.closest('[data-bd-keys-x]')) { keysSheet(false); } });
    keysEl.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        var f = Array.prototype.filter.call(keysEl.querySelectorAll('button, [href], input, [tabindex]:not([tabindex="-1"])'), function (x) { return x.offsetParent; });
        if (!f.length) { e.preventDefault(); return; }
        var i = f.indexOf(document.activeElement);
        if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && (i < 0 || i === f.length - 1)) { e.preventDefault(); f[0].focus(); }
    });

    // Enter on a Builder control is that control's own: a button, a link, a
    // tab, the menus and sheets, and every panel but Structure.
    var OWN_ENTER = 'button, a[href], [role="button"], [role="menuitem"], [role="tab"], summary, .nst-bd-ctx, .nst-bd-vmenu, .nst-fw-pick, .nst-bd-keys, .nst-bd__view:not([data-view="tree"])';
    // Text picked out on the screen (a reply in the chat, say) is the browser's to copy.
    function textPicked(e) {
        var d = (e.target && e.target.ownerDocument) || document;
        try { var s = d.getSelection(); return !!(s && !s.isCollapsed && String(s).trim()); } catch (x) { return false; }
    }
    // Shortcuts on what is selected; true when one was used.
    function moreKeys(e) {
        var k = e.key;
        var mod = e.ctrlKey || e.metaKey;
        var L = k.length === 1 ? k.toLowerCase() : '';
        if (k === 'Enter' && !mod && e.target.ownerDocument === document && e.target.closest && e.target.closest(OWN_ENTER)) { return false; }
        if (mod && !e.altKey && (k === '/' || k === '?' || e.code === 'Slash')) { keysSheet(keysEl.hidden); return true; }
        if ((e.ctrlKey || e.metaKey) && k === 'Enter' && sel && !isFrame(sel)) { openPalette(); return true; }
        if (!keysEl.hidden && k === 'Escape') { keysSheet(false); return true; }
        if (mod && e.altKey && /^Digit[1-5]$/.test(e.code || '')) { var v = ['add', 'tree', 'classes', 'templates', 'design'][+e.code.slice(5) - 1]; if (v === 'tree' || viewOn[v]) { setView(v); } return true; }
        // Copy, cut and paste an element: never over text picked out to copy,
        // and Ctrl+V with nothing copied here stays the browser's.
        var clipKey = mod && !e.shiftKey && !e.altKey && (L === 'c' || L === 'x' || L === 'v') && !!doc && !blocked && !aside();
        if (clipKey && L === 'v') { if (!board) { return false; } pasteEl(null); return true; }
        var el = sel && sel.isConnected && !isFrame(sel) ? sel : null;
        if (!el) { return false; }
        if (clipKey && !textPicked(e)) { if (picked().length > 1) { copyPicked(L === 'x'); } else if (L === 'x') { cutEl(el); } else { copyEl(el); } return true; }
        if (mod && !e.shiftKey && !e.altKey && L === 'd') { duplicatePicked(); return true; }
        if (!mod && !e.altKey && k === 'Enter') { openPop(el, 'comment'); return true; }
        if (mod && !e.shiftKey && !e.altKey && k === 'ArrowUp') { toolDo('up', el); return true; }
        if (mod && e.shiftKey && !e.altKey && L === 'h') { toolDo('hide', el); return true; }
        if (mod && !e.shiftKey && !e.altKey && L === 'e') { toolDo('code', el); return true; }
        if (e.altKey && (k === 'ArrowUp' || k === 'ArrowDown')) {
            var to = k === 'ArrowUp' ? el.previousElementSibling : el.nextElementSibling;
            while (to && (ours(to) || SKIP.test(to.localName))) { to = k === 'ArrowUp' ? to.previousElementSibling : to.nextElementSibling; }
            if (to) { moveEl(el, to, k === 'ArrowUp' ? 'before' : 'after'); }
            return true;
        }
        return false;
    }
    /* ------------------------------------------------------------------
     * The canvas: drag a side or a corner to size it, type a size or pick
     * a screen, or double-click a grip to fill the room again. Any width
     * from 320 to 2560 is the page's real width (its media queries see
     * it); wider or taller than the room, the page is drawn smaller to
     * fit, and the size shows by how much.
     * ---------------------------------------------------------------- */

    // The room the canvas has: inside the space kept for the panel, the bar and the code.
    function roomSize() {
        var cs = getComputedStyle(canvas);
        return {
            w: Math.floor(canvas.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0)),
            h: Math.floor(canvas.clientHeight - (parseFloat(cs.paddingTop) || 0) - (parseFloat(cs.paddingBottom) || 0))
        };
    }
    var ZMIN = 320;
    var ZMAX = 2560;
    function zClamp(v) { return Math.round(Math.max(ZMIN, Math.min(ZMAX, v))); }
    function px(n) { return Math.floor(n * 100) / 100 + 'px'; }
    // The canvas's size before the Builder opened, and what the Builder set
    // since: closing gives the first back wherever the second still holds.
    var abWas = null;
    var abMine = {};
    function abSize(w, h, m) {
        artboard.style.width = w;
        artboard.style.height = h;
        artboard.style.margin = m;
        abMine = { width: artboard.style.width, height: artboard.style.height, margin: artboard.style.margin };
    }
    // The page's size the Builder set: w wide, h tall (0: as tall as the
    // room lets it be). zoomAt is what that comes to now, s its scale.
    var zoom = null;
    var zoomAt = { w: 0, h: 0, s: 1 };
    function zoomOff() {
        artboard.classList.remove('is-zoomed');
        ['--ab-w', '--ab-h', '--ab-s'].forEach(function (p) { artboard.style.removeProperty(p); });
    }
    // The canvas takes the room the page needs on screen; the page in it is
    // that wide for real, drawn at s when the room is smaller than it.
    function applyZoom() {
        if (!zoom) { zoomOff(); return; }
        var room = roomSize();
        if (room.w < 40 || room.h < 40) { return; }
        var s = Math.floor(Math.min(1, room.w / zoom.w, zoom.h ? room.h / zoom.h : 1) * 1000) / 1000;
        var h = zoom.h || Math.floor(room.h / s);
        zoomAt = { w: zoom.w, h: h, s: s };
        abSize(px(zoom.w * s), px(h * s), 'auto');
        if (s >= 1) { zoomOff(); return; }
        artboard.style.setProperty('--ab-w', zoom.w + 'px');
        artboard.style.setProperty('--ab-h', h + 'px');
        artboard.style.setProperty('--ab-s', String(s));
        artboard.classList.add('is-zoomed');
    }
    function setZoom(w, h) {
        zoom = { w: zClamp(w), h: h ? zClamp(h) : 0 };
        zoomAt = { w: zoom.w, h: zoom.h, s: 1 };
        applyZoom();
    }
    // The size now: the Builder's, or the canvas's own (a device, the room).
    function zoomNow() {
        if (zoom) { return zoomAt; }
        var r = artboard.getBoundingClientRect();
        return { w: Math.round(r.width), h: Math.round(r.height), s: 1 };
    }
    // The panel, the code or the window changed the room: the scale follows.
    // A size Agent View set since (a device) is its own, not the Builder's.
    function fitZoom() {
        if (zoom && artboard.style.width !== abMine.width) { zoom = null; }
        applyZoom();
    }
    var sizeShown = '';
    // put: the field shows it even while it has the keyboard (the arrow keys).
    function showSize(live, put) {
        if (!live && on) { fitZoom(); }
        var z = zoomNow();
        var pct = Math.min(99, Math.round(z.s * 100));
        sizeShown = z.w + ' × ' + z.h;
        if (put || document.activeElement !== sizeWh) { sizeWh.value = sizeShown; }
        sizeWh.style.width = (sizeWh.value.length + 1) + 'ch';
        sizeZoom.hidden = z.s >= 1;
        sizeZoom.textContent = '· ' + pct + '%';
        /* translators: %d: how big the page is drawn, in percent */
        sizeWh.setAttribute('aria-label', z.s < 1 ? sprintf(__('Canvas width × height, shown at %d%%', 'nibwp'), pct) : __('Canvas width × height', 'nibwp'));
        bar.classList.toggle('is-sizing', !!live);
        grips.classList.toggle('is-sizing', !!live);
        markSizes();
    }
    // A new size: the field at once, the marks and the bubble once it has settled.
    function sized() {
        showSize(false);
        setTimeout(function () { showSize(false); paint(); placePop(); }, 320);
    }
    grips.addEventListener('pointerdown', function (e) {
        var g = e.target.closest('[data-grip]');
        if (!g) { return; }
        e.preventDefault();
        var r = artboard.getBoundingClientRect();
        var gr = g.getBoundingClientRect();
        var z = zoomNow();
        // The grip's own side grows the canvas: right to left puts east on the left.
        var sx = gr.left + gr.width / 2 < r.left + r.width / 2 ? -1 : 1;
        var d = { g: g.dataset.grip, x: e.clientX, y: e.clientY, w: z.w, h: z.h, s: z.s || 1, fill: !zoom || !zoom.h };
        var frame = kit.frame();
        if (frame) { frame.style.pointerEvents = 'none'; }
        var move = function (ev) {
            // The pointer moves on screen; the page is drawn at d.s.
            var dx = (ev.clientX - d.x) * sx / d.s;
            var dy = (ev.clientY - d.y) / d.s;
            var w = /[ew]/.test(d.g) ? d.w + dx * 2 : d.w;
            var h = /n/.test(d.g) ? d.h - dy * 2 : /s/.test(d.g) ? d.h + dy * 2 : d.fill ? 0 : d.h;
            setZoom(w, h);
            showSize(true);
        };
        var up = function () {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            if (frame) { frame.style.pointerEvents = ''; }
            showSize(false);
            paint();
            placePop();
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    });
    grips.addEventListener('dblclick', function (e) { if (e.target.closest('[data-grip]')) { resetSize(); } });
    // What the field says: "1440 × 900", "1440 900" or just "390" (the
    // height stays); "fit", "auto" or nothing fills the room again.
    function readSize(v) {
        v = v.replace(/\s*[·•]\s*\d+\s*%\s*$/, '').trim();
        if (!v || /^(fit|auto)$/i.test(v)) { return 'fit'; }
        var m = /(\d{2,5})\D*(\d{2,5})?/.exec(v);
        if (!m) { return null; }
        var keep = zoom && zoom.h ? zoom.h : 0;
        // The height shown while it follows the room keeps following it.
        return { w: +m[1], h: m[2] ? (!keep && +m[2] === zoomNow().h ? 0 : +m[2]) : keep };
    }
    var sizeTyping = false;
    function typeSize() {
        // Enter blurs the field, and the blur's change event comes back here: once is enough.
        if (sizeTyping) { return; }
        var want = sizeWh.value.trim() === sizeShown ? null : readSize(sizeWh.value);
        if (want === 'fit') { resetSize(); }
        else if (want) {
            setZoom(want.w, want.h);
            // A size under the smallest or over the largest is not taken silently.
            if (zoom.w !== want.w || (want.h && zoom.h !== want.h)) { kit.toast(sprintf(__('Sizes go from 320 to 2560: the canvas is %1$d × %2$d.', 'nibwp'), zoomAt.w, zoomAt.h)); }
        }
        sizeTyping = true;
        sizeWh.blur();
        sizeTyping = false;
        if (want && want !== 'fit') { sized(); } else { showSize(false); }
    }
    sizeWh.addEventListener('focus', function () { sizeWh.select(); });
    sizeWh.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); typeSize(); }
        if (e.key === 'Escape') {
            // Esc leaves the size as it was.
            e.preventDefault();
            e.stopPropagation();
            sizeWh.value = sizeShown;
            sizeTyping = true;
            sizeWh.blur();
            sizeTyping = false;
            showSize(false);
        }
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            var typed = sizeWh.value.trim() === sizeShown ? null : readSize(sizeWh.value);
            var z = typed && typed !== 'fit' ? typed : { w: zoomNow().w, h: zoom && zoom.h ? zoom.h : 0 };
            setZoom(z.w + (e.key === 'ArrowUp' ? 1 : -1) * (e.shiftKey ? 100 : 10), z.h);
            showSize(false, true);
            sizeWh.select();
            setTimeout(function () { paint(); placePop(); }, 320);
        }
    });
    sizeWh.addEventListener('change', typeSize);
    function resetSize() { zoom = null; zoomOff(); abSize('', '', ''); sized(); }

    // Screen sizes, one pick away; the one in use is marked.
    var SIZES = [['phone', __('Phone', 'nibwp'), 390], ['tablet', __('Tablet', 'nibwp'), 768], ['laptop', __('Laptop', 'nibwp'), 1280], ['desktop', __('Desktop', 'nibwp'), 1440], ['wide', __('Wide', 'nibwp'), 1920]];
    var sizesEl = document.createElement('div');
    sizesEl.className = 'nst-bd-sizes';
    sizesEl.hidden = true;
    sizesEl.setAttribute('role', 'menu');
    sizesEl.setAttribute('aria-label', __('Screen sizes', 'nibwp'));
    sizesEl.innerHTML = SIZES.map(function (x) {
        return '<button type="button" role="menuitemradio" aria-checked="false" data-bd-size="' + x[2] + '">' + ico(x[0], 15) + '<span>' + esc(x[1]) + '</span><small>' + x[2] + '</small>' + ico('check', 13) + '</button>';
    }).join('') + '<i class="nst-bd-sizes__sep" role="separator"></i>' +
        '<button type="button" role="menuitemradio" aria-checked="false" data-bd-size="fit">' + ico('expand', 15) + '<span>' + esc(__('Fit the room', 'nibwp')) + '</span>' + ico('check', 13) + '</button>';
    root.appendChild(sizesEl);
    var sizesBtn = bar.querySelector('[data-bd="sizes"]');
    function markSizes() {
        if (!sizesEl || sizesEl.hidden) { return; }
        sizesEl.querySelectorAll('[data-bd-size]').forEach(function (b) {
            var v = b.dataset.bdSize;
            b.setAttribute('aria-checked', String(v === 'fit' ? !zoom : !!zoom && zoom.w === +v));
        });
    }
    // Closed, it gives the keyboard back to its button.
    function sizesClose(back) {
        if (!sizesEl || sizesEl.hidden) { return; }
        var had = sizesEl.contains(document.activeElement);
        sizesEl.hidden = true;
        sizesBtn.setAttribute('aria-expanded', 'false');
        if (back && had) { sizesBtn.focus({ preventScroll: true }); }
    }
    function sizesOpen() {
        if (!sizesEl.hidden) { sizesClose(true); return; }
        hideTip();
        sizesEl.hidden = false;
        markSizes();
        sizesBtn.setAttribute('aria-expanded', 'true');
        var r = sizesBtn.getBoundingClientRect();
        var w = sizesEl.offsetWidth;
        var h = sizesEl.offsetHeight;
        var x = getComputedStyle(root).direction === 'rtl' ? r.right - w : r.left;
        sizesEl.style.left = Math.max(8, Math.min(x, innerWidth - w - 8)) + 'px';
        sizesEl.style.top = (r.top - h - 8 > 8 ? r.top - h - 8 : r.bottom + 8) + 'px';
        (sizesEl.querySelector('[aria-checked="true"]') || sizesEl.querySelector('[data-bd-size]')).focus({ preventScroll: true });
    }
    sizesEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-bd-size]');
        if (!b) { return; }
        sizesClose(true);
        if (b.dataset.bdSize === 'fit') { resetSize(); } else { setZoom(+b.dataset.bdSize, 0); sized(); }
    });
    sizesEl.addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(sizesEl.querySelectorAll('[data-bd-size]'));
        var i = items.indexOf(document.activeElement);
        var n = items.length;
        if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); sizesClose(true); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % n].focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); items[i < 0 ? n - 1 : (i + n - 1) % n].focus(); }
        else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); items[e.key === 'Home' ? 0 : n - 1].focus(); }
    });
    document.addEventListener('pointerdown', function (e) { if (!sizesEl.hidden && !sizesEl.contains(e.target) && !e.target.closest('[data-bd="sizes"]')) { sizesClose(false); } }, true);

    /* ------------------------------------------------------------------
     * On and off; only for this site's own pages.
     * ---------------------------------------------------------------- */

    function checkPage() {
        var info = kit.pageInfo();
        blocked = !info.url || info.external || info.admin;
        var off = left.querySelector('.nst-bd__off');
        off.hidden = !blocked;
        off.querySelector('p').textContent = info.admin ? __('This is an admin screen: the Builder edits your site’s pages. You can still ask NibWP below to inspect and change it.', 'nibwp') : info.external ? __('This is an external site, not one of your pages. To inspect it, or rebuild it on your site, ask NibWP below.', 'nibwp') : __('This page is not one the Builder can edit directly. Ask NibWP below to inspect it and change it for you.', 'nibwp');
        if (doc) { doc.documentElement.classList.toggle('nst-x-building', on && !blocked); }
        setView(view);
    }

    function start() {
        if (kit.stopMarking) { kit.stopMarking(); }
        on = true;
        if (!abWas) { abWas = { width: artboard.style.width, height: artboard.style.height, margin: artboard.style.margin }; abMine = {}; }
        if (kit.chat && chatWas === null) { chatWas = kit.chat(true); }
        root.classList.add('is-building');
        openBtn.setAttribute('aria-pressed', 'true');
        left.hidden = false;
        grips.hidden = false;
        bar.hidden = false;
        hook();
        tplSync();
        checkPage();
        applyView();
        changed();
        // A design not saved yet shows on the page again (and marks its tab).
        fwApply();
        files();
        setTimeout(showSize, 60);
    }

    function stop() {
        // Text typed on the page, a class's CSS and a block's name are kept.
        if (typingEl) { typeDone(true); }
        clsCommit();
        popNameFlush();
        setDrawing(false);
        on = false;
        pageCode = false;
        closePop();
        closePalette();
        // What floats over the page closes with the Builder.
        closeCtx();
        closePick();
        vmenuClose(false);
        histClose(false);
        keysSheet(false);
        mkForm.hidden = true;
        shotStop();
        commitCss();
        root.classList.remove('is-building', 'has-bd-dock');
        if (kit.chat && chatWas !== null) { kit.chat(chatWas); chatWas = null; }
        openBtn.setAttribute('aria-pressed', 'false');
        left.hidden = true;
        dock.hidden = true;
        bar.hidden = true;
        grips.hidden = true;
        sizesClose(false);
        // The page is drawn at its own size again, then the canvas gets back
        // the size it had before, wherever the Builder set one.
        zoom = null;
        zoomOff();
        if (abWas) { ['width', 'height', 'margin'].forEach(function (p) { if (p in abMine && artboard.style[p] === abMine[p]) { artboard.style[p] = abWas[p]; } }); }
        abWas = null;
        abMine = {};
        hover = null;
        if (doc) { doc.documentElement.classList.remove('nst-x-building'); }
        // A design not saved yet: the page shows the saved one until the Builder opens again.
        if (fwDraft) {
            fwApply();
            if (fwDirty()) { kit.toast(__('Your design changes are not saved yet. They show again when you open the Builder.', 'nibwp')); }
        }
        paint();
        if (held) { release(true); }
    }
    // Ctrl+S saves what waits: the page's changes, and the design when it has some.
    function saveAll() {
        clsCommit();
        var design = fwDirty();
        var sb = fwBox && fwBox.querySelector('[data-fw-act="save"]');
        if (design && sb && !sb.classList.contains('is-saving')) { fwSave(); }
        if (!design || pending()) { save(); }
    }

    openBtn.addEventListener('click', function () { if (on) { stop(); } else { start(); } });
    // Agent View's Comment and Draw work on the page while building: the
    // Builder steps aside until they are off again.
    function aside() { return root.classList.contains('is-proofing'); }
    var wasAside = false;
    var asideOffAt = 0;
    new MutationObserver(function () {
        if (wasAside && !aside()) { asideOffAt = Date.now(); }
        wasAside = aside();
        if (!on) { return; }
        if (aside()) { setDrawing(false); if (!pop.hidden) { closePop(); } hover = null; }
        paint();
    }).observe(root, { attributes: true, attributeFilter: ['class'] });
    document.addEventListener('keydown', function (e) {
        if (!on) { return; }
        if (e.key === 'Escape' && (!keysEl.hidden || !vmenu.hidden || !pal.hidden || !hist.hidden || !sizesEl.hidden)) { e.preventDefault(); keysSheet(false); vmenuClose(true); histClose(true); closePalette(); sizesClose(true); return; }
        // The Esc that ended Agent View's Comment or Draw is not the Builder's.
        if (aside() || (e.key === 'Escape' && Date.now() - asideOffAt < 80)) { return; }
        idle();
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveAll(); return; }
        var typing = e.target.closest && e.target.closest('input, textarea, select, [contenteditable]');
        if (e.key === 'Escape' && drawing) { e.preventDefault(); setDrawing(false); return; }
        if (e.key === 'Escape') {
            // An Esc the sketch sheet, a menu or the bubble already used is not for the Builder.
            if (e.defaultPrevented || (window.nibwpSketch && window.nibwpSketch.isOpen()) || typing || document.querySelector('dialog[open]')) { return; }
            if (!pop.hidden) { popNameFlush(); closePop(); return; }
            if (sel) { e.preventDefault(); unselect(); return; }
            e.preventDefault();
            stop();
            return;
        }
        if (typing || (window.nibwpSketch && window.nibwpSketch.isOpen())) { return; }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) { redo(); } else { undo(); } }
        else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
        else if (moreKeys(e)) { e.preventDefault(); }
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && e.key.toLowerCase() === 'l' && !blocked) { e.preventDefault(); setDrawing(!drawing); }
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && e.key.toLowerCase() === 'd') { e.preventDefault(); setDrawing(false); var dz = document.getElementById('nst-draw'); if (dz) { dz.click(); } }
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && e.key.toLowerCase() === 'm' && sel) { e.preventDefault(); openPop(sel, 'comment'); }
        else if (sel && !e.ctrlKey && !e.metaKey && !e.altKey && (e.key === 'Backspace' || (e.key.length === 1 && /[-_.a-zA-Z0-9]/.test(e.key))) && treeEl.offsetParent && sel.isConnected && !isFrame(sel) && rows.indexOf(sel) > -1) { e.preventDefault(); treeRename(sel, e.key === 'Backspace' ? '\b' : e.key === '.' ? '' : e.key); }
        // Delete removes the selection only from the page, Structure or nowhere
        // in particular, never from a focused button in a panel.
        else if (sel && (e.key === 'Delete' || e.key === 'Backspace') && [document.body, document.documentElement, treeEl, kit.frame()].indexOf(e.target) > -1) { e.preventDefault(); removePicked(); }
    });
    document.addEventListener('focusout', function () { if (on) { idle(); } });
    // A new page on the canvas: read it again, and pick up where you were.
    document.addEventListener('nibwp-studio', function (e) {
        if (!e.detail || !on) { return; }
        if (e.detail.type === 'page' || e.detail.type === 'tab') {
            if (e.detail.type === 'page') { banner('', false); }
            setTimeout(function () { hook(); tplSync(); checkPage(); changed(); fwMark(); restore(); showSize(); histRefresh(); }, e.detail.type === 'tab' ? 0 : 80);
        }
        // Agent View picked a device or a width: that size, not the Builder's.
        if (e.detail.type === 'device') { zoom = null; zoomOff(); sized(); }
    });
    document.addEventListener('nibwp-sketch-closed', function () { if (on && view === 'add') { renderWires(); } });
    window.addEventListener('resize', function () { placePop(); if (on) { showSize(); } if (!hist.hidden) { histPlace(); } });
    // The room changes with the window, a panel, the code or a side switch:
    // the page's scale follows at once.
    if (window.ResizeObserver) {
        var roomWas = '';
        new ResizeObserver(function () {
            if (!on) { roomWas = ''; return; }
            var r = roomSize();
            if (r.w + 'x' + r.h === roomWas) { return; }
            roomWas = r.w + 'x' + r.h;
            showSize(false);
            placePop();
        }).observe(canvas);
    }

    // Panel width and the details' height, by dragging; remembered.
    var sizes = store('nibwp-bd-sizes') || {};
    function applySizes() {
        if (sizes.w) { root.style.setProperty('--bd-w', sizes.w + 'px'); }
        if (sizes.eh) { root.style.setProperty('--bd-eh', sizes.eh + 'px'); }
        if (sizes.hh) { root.style.setProperty('--bd-hh', sizes.hh + 'px'); root.style.setProperty('--bd-hh-max', 'calc(100% - 56px)'); }
    }
    applySizes();
    function dragSize(e, key, calc) {
        e.preventDefault();
        var frame = kit.frame();
        if (frame) { frame.style.pointerEvents = 'none'; }
        document.documentElement.classList.add('nst-bd-sizing');
        var move = function (ev) { sizes[key] = calc(ev); applySizes(); };
        var up = function () {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            if (frame) { frame.style.pointerEvents = ''; }
            document.documentElement.classList.remove('nst-bd-sizing');
            store('nibwp-bd-sizes', sizes);
            showSize(false);
            paint();
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    }
    left.querySelector('[data-bd-wgrip]').addEventListener('pointerdown', function (e) {
        var c = canvas.getBoundingClientRect();
        dragSize(e, 'w', function (ev) { return Math.round(Math.max(240, Math.min(560, root.classList.contains('bd-panel-left') ? ev.clientX - c.left : c.right - ev.clientX))); });
    });
    elBox.querySelector('[data-bd-hgrip]').addEventListener('pointerdown', function (e) {
        var v = left.querySelector('.nst-bd__view[data-view="tree"]').getBoundingClientRect();
        dragSize(e, 'eh', function (ev) { return Math.round(Math.max(110, Math.min(v.height - 120, v.bottom - ev.clientY))); });
    });
    elBox.querySelector('[data-bd-hgrip]').addEventListener('dblclick', function () { delete sizes.eh; root.style.removeProperty('--bd-eh'); store('nibwp-bd-sizes', sizes); });
    // The content hub, open: its grip pulls it up or down; a double-click resets it.
    var hubGrip = left.querySelector('[data-bd-hubgrip]');
    hubGrip.addEventListener('pointerdown', function (e) {
        var hub = left.querySelector('.nst-bd__hub').getBoundingClientRect();
        var top = left.getBoundingClientRect().top;
        dragSize(e, 'hh', function (ev) { return Math.round(Math.max(180, Math.min(hub.bottom - top - 56, hub.bottom - ev.clientY))); });
    });
    hubGrip.addEventListener('dblclick', function () { delete sizes.hh; root.style.removeProperty('--bd-hh'); root.style.removeProperty('--bd-hh-max'); store('nibwp-bd-sizes', sizes); });
    left.querySelector('[data-bd-wgrip]').addEventListener('dblclick', function () { delete sizes.w; root.style.removeProperty('--bd-w'); store('nibwp-bd-sizes', sizes); setTimeout(function () { showSize(false); paint(); }, 60); });

    window.nibwpBuilder = {
        toggle: function () { if (on) { stop(); } else { start(); } },
        start: start,
        stop: stop,
        hold: hold,
        active: function () { return on; },
        // Editing this page now: a click on it selects, it does not follow links.
        editing: function () { return on && !blocked && !aside(); }
    };

    // Opened from a page's "Edit with NibWP": that page, in the Builder.
    var q = new URL(location.href).searchParams;
    if (q.get('nibwp_builder') === '1') {
        var target = q.get('open');
        setTimeout(function () {
            (target ? bridge.openTab(target) : Promise.resolve()).then(function () { setTimeout(start, 300); }, start);
        }, 400);
    }
}());
