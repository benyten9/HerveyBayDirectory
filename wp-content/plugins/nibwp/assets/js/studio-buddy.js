/**
 * Niby, the Agent View helper: the NibWP icon come to life, just as it is. It
 * sits wherever the person drags it, looks around through its own eye slots,
 * flips and squeezes now and then by itself, and talks in a speech bubble.
 *
 * What it says comes from the person's own agent when one is listening and
 * free: Niby asks it for one short line about the moment (a finished task, a
 * question the person typed, a tip), shows that it is thinking, and says the
 * answer. With no agent free it says one of the lines kept here.
 *
 * Rarely, and only right after something went well, it mentions NibWP
 * itself: sharing, the affiliate program, a review. Never in the first two
 * visits, at most once every few days, never twice after a "Not now", never
 * on a renamed (white-label) install. Saying yes teaches Niby a new trick.
 *
 * It only listens to Agent View ('nibwp-studio' events from studio.js) and
 * never changes the thread, the page or the site.
 */
(function () {
    'use strict';

    var boot = window.nibwpStudio || {};
    var cfg = boot.buddy || {};
    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;
    // The Comment and Draw keys, as this computer writes them.
    var KEY_C = window.nibwpKeyLabel ? window.nibwpKeyLabel('Ctrl+Shift+M') : 'Ctrl+Shift+M';
    var KEY_D = window.nibwpKeyLabel ? window.nibwpKeyLabel('Ctrl+Shift+D') : 'Ctrl+Shift+D';
    var KEY = function (k) { return window.nibwpKeyLabel ? window.nibwpKeyLabel(k) : k; };
    var host = document.getElementById('nst');
    var toggle = document.getElementById('nst-buddy-btn');
    if (!host || !toggle) { return; }

    var DAY = 86400000;
    var brand = boot.brand || 'NibWP';
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Tricks Niby learns when the person says yes to a suggestion.
    var TRICKS = {
        dance: __('a happy dance', 'nibwp'),
        double: __('a double back flip', 'nibwp'),
        moonwalk: __('the moonwalk', 'nibwp')
    };

    /* ------------------------------------------------------------------
     * Memory, per browser: on or off, where it sits, which tips were said,
     * how the suggestions went, and the tricks it learned.
     * ---------------------------------------------------------------- */

    var mem = (function () {
        var m = {};
        try { m = JSON.parse(window.localStorage.getItem('nibwp-buddy') || '{}') || {}; } catch (e) { m = {}; }
        var obj = function (v) { return v && typeof v === 'object' && !Array.isArray(v) ? v : {}; };
        return {
            on: m.on !== false,
            pos: m.pos && isFinite(m.pos.x) && isFinite(m.pos.y) ? { x: +m.pos.x, y: +m.pos.y } : null,
            seen: obj(m.seen),
            visits: (+m.visits || 0) + 1,
            promoAt: +m.promoAt || 0,
            promoNo: obj(m.promoNo),
            promoYes: obj(m.promoYes),
            promoOff: !!m.promoOff,
            tipNo: +m.tipNo || 0,
            dykNo: +m.dykNo || 0,
            tricks: Array.isArray(m.tricks) ? m.tricks.filter(function (o) { return TRICKS[o]; }) : []
        };
    }());

    function save() {
        try { window.localStorage.setItem('nibwp-buddy', JSON.stringify(mem)); } catch (e) { /* private window: Niby forgets, nothing breaks */ }
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ------------------------------------------------------------------
     * The character: the icon's head, antenna and ball, with a comic face
     * drawn over it. Coordinates inside the head group are the icon's own.
     * ---------------------------------------------------------------- */

    var ICON = {
        head: 'M203.086-363.49c-52.021,5.6-92.252,42.884-102.936,95.494-2.284,11.126-2.432,37.063-.368,44.8a54.206,54.206,0,0,0,27.926,34.779c8.547,4.2,13.558,5.305,24.168,5.305,12.01,0,15.916-1.032,30.579-8.326,15.4-7.663,18.568-8.474,32.052-8.474,13.558,0,16.726.811,32.052,8.474,6.484,3.168,13.926,6.41,16.579,7.074,6.779,1.768,21.589,1.768,28-.074,18.716-5.158,32.937-19.673,38.094-38.758,2.063-7.737,1.916-33.673-.368-44.726-9.8-48.631-45.168-84.662-92.031-93.8C229.1-363.269,210.159-364.227,203.086-363.49Zm-31.021,55.115c5.01,3.758,5.6,5.968,5.6,21.073,0,13.853-.368,15.695-3.61,19.452-1.916,2.211-6.41,4.126-9.653,4.126s-7.737-1.916-9.653-4.126c-3.242-3.758-3.611-5.6-3.611-19.452,0-16.284.811-18.568,7.737-22.4C162.339-311.544,168.749-310.954,172.065-308.375ZM269.99-309.7a12.941,12.941,0,0,1,6.484,6.337c1.253,2.358,1.4,4.421,1.4,16.063,0,13.853-.368,15.695-3.61,19.452-5.232,5.895-15.4,5.158-20.484-1.547l-2.063-2.653v-30.579l2.211-2.8C258.127-310.586,264.685-312.354,269.99-309.7ZM202.57-258.27a15.428,15.428,0,0,1,3.463,2.653,11.139,11.139,0,0,0,8.621,3.684c3.242,0,4.863-.811,9.8-4.716,5.821-4.716,15.4-2.874,19.158,3.537a14.768,14.768,0,0,1,.884,12.452c-1.4,3.242-8.179,9.284-13.337,11.789a36.444,36.444,0,0,1-32.568.147c-9.505-4.642-15.031-10.979-15.031-17.242,0-5.158,2.21-8.989,7-11.937C193.212-259.6,199.7-259.744,202.57-258.27Z',
        antenna: 'M244.1-504.937a18.922,18.922,0,0,0-12.747,15.326,18.119,18.119,0,0,0,9.505,17.905l4.2,2.211v29.842H253.9v-15.031c0-14.958,0-15.105,1.695-15.621,3.537-1.105,7.663-4.716,9.726-8.547a18.22,18.22,0,0,0-11.053-26.3A15.954,15.954,0,0,0,244.1-504.937Z',
        ball: 'M243.785-103.037c-3.61,1.179-9.8,7.516-10.905,11.274-2.063,6.926-.589,12.453,4.642,17.61,5.158,5.232,10.684,6.705,17.61,4.642,4.053-1.179,10.168-7.295,11.347-11.347,2.063-6.926.589-12.453-4.642-17.61C256.679-103.7,250.637-105.174,243.785-103.037Z'
    };

    var el = document.createElement('div');
    el.className = 'nst-buddy';
    el.id = 'nst-buddy';
    el.dataset.mood = 'idle';
    el.dataset.mouth = 'smile';
    el.dataset.eyes = 'open';
    el.dataset.brows = '';
    el.innerHTML =
        '<div class="nst-buddy__bubble" role="status" aria-live="polite" hidden>' +
            '<button type="button" class="nst-buddy__x" data-b="close" aria-label="' + esc(__('Close', 'nibwp')) + '">×</button>' +
            '<i class="nst-buddy__tail" aria-hidden="true"></i><span class="nst-buddy__status"></span><span class="nst-buddy__who"></span><p class="nst-buddy__q"></p><p class="nst-buddy__text"></p><div class="nst-buddy__acts"></div><div class="nst-buddy__more"></div><div class="nst-buddy__foot"></div>' +
        '</div>' +
        '<button type="button" class="nst-buddy__me" data-b="menu" aria-label="' + esc(__('Niby, your helper. Click for a tip, drag to move.', 'nibwp')) + '">' +
            '<svg viewBox="0 0 90 104" width="78" height="90" aria-hidden="true">' +
                '<defs><linearGradient id="nst-buddy-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffa765"/><stop offset="1" stop-color="#ff8a3d"/></linearGradient><radialGradient id="nst-buddy-hole"><stop offset="0" stop-color="#030205"/><stop offset=".62" stop-color="#110a18"/><stop offset=".88" stop-color="#2a1309"/><stop offset="1" stop-color="#ff8a3d" stop-opacity=".45"/></radialGradient><clipPath id="nst-buddy-pit"><rect x="-60" y="-140" width="210" height="237"/></clipPath></defs>' +
                '<ellipse class="nst-buddy__shadow" cx="45" cy="97" rx="16" ry="3"/>' +
                '<ellipse class="nst-buddy__rim" cx="45" cy="97" rx="16" ry="3" vector-effect="non-scaling-stroke"/>' +
                '<g class="nst-buddy__pit"><g class="nst-buddy__fig">' +
                    '<g transform="translate(17 12) scale(.24)">' +
                        // Seen only through the icon's holes: eyes, lids and mouth.
                        '<g class="nst-buddy__eye is-l"><rect class="nst-buddy__white" x="40" y="116" width="50" height="70"/><g class="nst-buddy__pupil"><circle cx="65" cy="156" r="11"/><circle class="nst-buddy__glint" cx="69" cy="151" r="3.4"/></g><rect class="nst-buddy__lid" x="40" y="116" width="50" height="70"/><rect class="nst-buddy__lid is-low" x="40" y="116" width="50" height="70"/><path class="nst-buddy__lash" d="M46 158 Q65 172 84 158"/></g>' +
                        '<g class="nst-buddy__eye is-r"><rect class="nst-buddy__white" x="144" y="116" width="46" height="68"/><g class="nst-buddy__pupil"><circle cx="167" cy="155" r="11"/><circle class="nst-buddy__glint" cx="171" cy="150" r="3.4"/></g><rect class="nst-buddy__lid" x="144" y="116" width="46" height="68"/><rect class="nst-buddy__lid is-low" x="144" y="116" width="46" height="68"/><path class="nst-buddy__lash" d="M150 157 Q167 171 184 157"/></g>' +
                        '<rect class="nst-buddy__fill" x="82" y="172" width="72" height="56"/>' +
                        '<g transform="translate(-98.33 477.88)">' +
                            '<g class="nst-buddy__antenna"><path transform="translate(-34.969 28)" d="' + ICON.antenna + '"/></g>' +
                            '<path class="nst-buddy__head" transform="translate(0 -37.405)" d="' + ICON.head + '"/>' +
                            '<g class="nst-buddy__ball"><path transform="translate(-35.173 -105.766)" d="' + ICON.ball + '"/></g>' +
                        '</g>' +
                        '<g class="nst-buddy__brows"><path class="nst-buddy__brow is-l" d="M46 108 Q65 99 84 106"/><path class="nst-buddy__brow is-r" d="M148 106 Q167 99 186 108"/></g>' +
                        // His Etch hat, the same cap Kind Guy wears; the antenna pokes through a hole on top.
                        '<g class="nst-buddy__cap">' +
                            '<path class="nst-buddy__cap-crown" d="M20 104 C14 58 58 38 116 38 C174 38 218 58 212 104 Q116 92 20 104 Z"/>' +
                            '<path class="nst-buddy__cap-mesh" d="M168 46 C198 58 216 78 212 104 Q194 100 178 99 C182 80 180 62 168 46 Z"/>' +
                            '<path class="nst-buddy__cap-seam" d="M116 44 V94"/>' +
                            '<circle cx="98" cy="70" r="17" fill="#f4f2f2"/><circle cx="98" cy="70" r="14" fill="#e23a35"/><circle cx="98" cy="70" r="10" fill="#5d5c63"/><circle cx="98" cy="70" r="6" fill="none" stroke="#2c2b30" stroke-width="2.5"/><circle cx="98" cy="70" r="2.4" fill="#2c2b30"/>' +
                            '<ellipse class="nst-buddy__cap-hole" cx="116.2" cy="41" rx="10" ry="3.6"/>' +
                            '<path class="nst-buddy__cap-visor" d="M0 110 Q116 82 232 106 Q226 122 116 118 Q14 124 0 110 Z"/>' +
                            '<path class="nst-buddy__cap-shine" d="M14 108 Q116 88 218 106"/>' +
                        '</g>' +
                        '<g class="nst-buddy__mouth">' +
                            '<g data-m="smile"><clipPath id="nst-buddy-smile"><path transform="translate(-98.33 440.475)" d="M202.57-258.27a15.428,15.428,0,0,1,3.463,2.653,11.139,11.139,0,0,0,8.621,3.684c3.242,0,4.863-.811,9.8-4.716,5.821-4.716,15.4-2.874,19.158,3.537a14.768,14.768,0,0,1,.884,12.452c-1.4,3.242-8.179,9.284-13.337,11.789a36.444,36.444,0,0,1-32.568.147c-9.505-4.642-15.031-10.979-15.031-17.242,0-5.158,2.21-8.989,7-11.937C193.212-259.6,199.7-259.744,202.57-258.27Z"/></clipPath><path class="nst-buddy__dark is-icon" transform="translate(-98.33 440.475)" d="M202.57-258.27a15.428,15.428,0,0,1,3.463,2.653,11.139,11.139,0,0,0,8.621,3.684c3.242,0,4.863-.811,9.8-4.716,5.821-4.716,15.4-2.874,19.158,3.537a14.768,14.768,0,0,1,.884,12.452c-1.4,3.242-8.179,9.284-13.337,11.789a36.444,36.444,0,0,1-32.568.147c-9.505-4.642-15.031-10.979-15.031-17.242,0-5.158,2.21-8.989,7-11.937C193.212-259.6,199.7-259.744,202.57-258.27Z"/><ellipse class="nst-buddy__tongue is-icon" cx="118" cy="216" rx="21" ry="10" clip-path="url(#nst-buddy-smile)"/></g>' +
                            '<g data-m="grin"><path class="nst-buddy__dark" d="M86 186 Q118 198 150 186 Q148 232 118 234 Q88 232 86 186 Z"/><path class="nst-buddy__teeth" d="M89 189 Q118 200 147 189 L145 201 Q118 209 91 201 Z"/></g>' +
                            '<g data-m="laugh"><path class="nst-buddy__dark" d="M82 182 Q118 194 154 182 Q152 242 118 244 Q84 242 82 182 Z"/><path class="nst-buddy__teeth" d="M85 185 Q118 196 151 185 L149 197 Q118 205 87 197 Z"/><ellipse class="nst-buddy__tongue" cx="118" cy="230" rx="20" ry="9"/></g>' +
                            '<g data-m="talk"><ellipse class="nst-buddy__dark" cx="118" cy="206" rx="18" ry="16"/><ellipse class="nst-buddy__tongue" cx="118" cy="215" rx="11" ry="5"/></g>' +
                            '<ellipse data-m="o" class="nst-buddy__dark" cx="118" cy="206" rx="10" ry="13"/>' +
                            '<path data-m="flat" class="nst-buddy__line" d="M98 206 H138"/>' +
                            '<path data-m="sad" class="nst-buddy__line" d="M94 218 Q118 194 142 218"/>' +
                            '<path data-m="wavy" class="nst-buddy__line" d="M92 208 q6.5 -8 13 0 t13 0 t13 0 t13 0"/>' +
                            '<path data-m="smirk" class="nst-buddy__line" d="M96 212 Q126 216 146 194"/>' +
                            '<g data-m="tongue"><path class="nst-buddy__dark" d="M92 192 Q118 202 144 192 Q140 214 118 216 Q96 214 92 192 Z"/><path class="nst-buddy__tongue" d="M104 210 Q118 244 132 210 Z"/></g>' +
                            '<g data-m="teeth"><rect class="nst-buddy__teeth" x="90" y="194" width="56" height="22" rx="10"/><path class="nst-buddy__gap" d="M90 205 H146 M104 195 V215 M118 194 V216 M132 195 V215"/></g>' +
                        '</g>' +
                    '</g>' +
                '</g></g>' +
                '<g class="nst-buddy__zz"><text x="64" y="22">z</text><text x="64" y="22">z</text><text x="64" y="22">Z</text></g>' +
            '</svg>' +
        '</button>' +
        '<div class="nst-buddy__fx" aria-hidden="true"></div>';
    host.appendChild(el);

    var bubble = el.querySelector('.nst-buddy__bubble');
    var me = el.querySelector('.nst-buddy__me');
    var pupils = el.querySelectorAll('.nst-buddy__pupil');

    /* ------------------------------------------------------------------
     * Faces and moods. A mood is a pose and its default face; a passing
     * one goes back to the steady one by itself.
     * ---------------------------------------------------------------- */

    var FACE = {
        idle: ['smile', 'open', ''],
        talk: ['talk', 'open', ''],
        happy: ['grin', 'happy', 'up'],
        laugh: ['laugh', 'happy', 'up'],
        worry: ['wavy', 'wide', 'sad'],
        sad: ['sad', 'sleepy', 'sad'],
        think: ['wavy', 'up', 'one'],
        work: ['flat', 'squint', 'cross'],
        wave: ['grin', 'open', 'up'],
        sleep: ['flat', 'shut', '']
    };
    var moodTimer = 0;
    var steady = 'idle';

    function face(mouth, eyes, brows) {
        if (mouth) { el.dataset.mouth = mouth; }
        if (eyes) { el.dataset.eyes = eyes; }
        if (brows !== undefined) { el.dataset.brows = brows; }
    }

    function mood(m, ms) {
        clearTimeout(moodTimer);
        el.dataset.mood = m;
        face(FACE[m][0], FACE[m][1], FACE[m][2]);
        if (ms) {
            moodTimer = setTimeout(function () { el.dataset.mood = steady; face(FACE[steady][0], FACE[steady][1], FACE[steady][2]); }, ms);
        } else {
            steady = m;
        }
    }

    // While Niby speaks the mouth runs through shapes, like a cartoon's.
    var lipTimer = 0;
    function lips(ms) {
        clearInterval(lipTimer);
        if (reduced) { return; }
        var shapes = ['talk', 'o', 'grin', 'talk', 'smile', 'flat', 'laugh', 'talk', 'o'];
        var end = Date.now() + ms;
        lipTimer = setInterval(function () {
            if (Date.now() > end) {
                clearInterval(lipTimer);
                face(FACE[el.dataset.mood] ? FACE[el.dataset.mood][0] : 'smile');
                return;
            }
            face(shapes[Math.floor(Math.random() * shapes.length)]);
        }, 95 + Math.random() * 60);
    }

    // A trick plays once and goes. Some are attitude: a face more than a move.
    var ANTICS = ['backflip', 'backflip', 'backflip', 'backflip', 'flip', 'jump', 'squeeze', 'hop', 'spin', 'tilt', 'shrug', 'peek', 'wobble', 'wink', 'side', 'roll', 'yawn', 'smug', 'pout', 'gasp', 'giggle', 'meh', 'ponder'];
    var ANTIC_FACE = {
        backflip: ['laugh', 'happy', 'up'], flip: ['grin', 'open', 'up'], jump: ['o', 'wide', 'up'], squeeze: ['teeth', 'shut', 'cross'],
        wink: ['tongue', 'wink', 'up'], side: ['smirk', 'side', 'one'], roll: ['flat', 'roll', 'one'], yawn: ['talk', 'sleepy', ''],
        tilt: ['wavy', 'up', 'one'], shrug: ['flat', 'squint', 'up'], peek: ['o', 'wide', 'up'], smug: ['smirk', 'side', 'one'],
        pout: ['sad', 'sleepy', 'sad'], gasp: ['o', 'wide', 'up'], giggle: ['laugh', 'happy', 'up'], meh: ['flat', 'squint', ''],
        ponder: ['wavy', 'up', 'one'], dance: ['grin', 'happy', 'up'], double: ['laugh', 'happy', 'up'], moonwalk: ['smirk', 'squint', 'one'], pop: null
    };
    var dragging = false;
    function antic(name) {
        if (el.dataset.portal) { return; }
        el.dataset.antic = name;
        if (ANTIC_FACE[name]) { face(ANTIC_FACE[name][0], ANTIC_FACE[name][1], ANTIC_FACE[name][2]); }
        clearTimeout(antic.t);
        antic.t = setTimeout(function () {
            delete el.dataset.antic;
            face(FACE[el.dataset.mood][0], FACE[el.dataset.mood][1], FACE[el.dataset.mood][2]);
        }, name === 'double' || name === 'moonwalk' || name === 'dance' ? 2400 : 1600);
    }

    (function antics() {
        setTimeout(function () {
            if (mem.on && !el.hidden && bubble.hidden && el.dataset.mood === 'idle' && !document.hidden && !dragging && !el.dataset.guest) {
                var all = ANTICS.concat(mem.tricks);
                antic(all[Math.floor(Math.random() * all.length)]);
            }
            antics();
        }, 16000 + Math.random() * 30000);
    }());

    (function blink() {
        setTimeout(function () {
            if (el.dataset.eyes === 'open' || el.dataset.eyes === 'up') {
                el.classList.add('is-blink');
                setTimeout(function () { el.classList.remove('is-blink'); }, 150);
            }
            blink();
        }, 2400 + Math.random() * 3600);
    }());

    // Chat chips sit on one line. The pointer slides the line, slowly, so
    // chips that do not fit come into view; elsewhere they just drift a little.
    var chipX = 0;
    function chipsSlide(row, x, y) {
        chipX = x;
        var b = bubble.getBoundingClientRect();
        var my = (y - (b.top + b.height / 2)) / window.innerHeight;
        row.querySelectorAll('.nst-buddy__act').forEach(function (a, i) {
            a.style.transform = 'translate(' + x.toFixed(1) + 'px,' + (my * (3 + (i % 3))).toFixed(1) + 'px)';
        });
    }
    function chipsFollow(cx, cy) {
        var row = bubble.querySelector('.nst-buddy__acts');
        if (!row || !row.children.length) { return; }
        var over = Math.max(0, row.scrollWidth - row.clientWidth);
        var rtl = getComputedStyle(row).direction === 'rtl';
        row.style.webkitMaskImage = row.style.maskImage = over ? 'linear-gradient(' + (rtl ? 270 : 90) + 'deg,#000 calc(100% - 22px),transparent)' : '';
        var r = row.getBoundingClientRect();
        // A margin at each end, so the first and last chips are easy to reach.
        var t = Math.max(0, Math.min(1, (cx - r.left - 24) / Math.max(1, r.width - 48)));
        var b = bubble.getBoundingClientRect();
        var drift = (cx - (b.left + b.width / 2)) / window.innerWidth * 6;
        chipsSlide(row, over ? (rtl ? over * (1 - t) : -over * t) : drift, cy);
    }
    // A chip reached with Tab slides into view.
    bubble.addEventListener('focusin', function (e) {
        var a = e.target.closest('.nst-buddy__bubble.is-chat .nst-buddy__act');
        if (!a) { return; }
        var row = a.parentNode;
        var ar = a.getBoundingClientRect();
        var rr = row.getBoundingClientRect();
        var x = chipX;
        if (ar.right > rr.right) { x -= ar.right - rr.right + 4; }
        if (ar.left < rr.left) { x += rr.left - ar.left + 4; }
        chipsSlide(row, x, 0);
    });

    // The eyes follow the pointer.
    var look = 0;
    document.addEventListener('mousemove', function (e) {
        if (look || el.hidden) { return; }
        look = requestAnimationFrame(function () {
            look = 0;
            var r = me.getBoundingClientRect();
            var dx = e.clientX - (r.left + r.width / 2);
            var dy = e.clientY - (r.top + r.height * 0.4);
            var d = Math.max(1, Math.hypot(dx, dy));
            var k = Math.min(8, d / 18);
            var t = 'translate(' + (dx / d * k).toFixed(1) + 'px,' + (dy / d * k).toFixed(1) + 'px)';
            pupils.forEach(function (p) { p.style.transform = t; });
            if (!reduced && chatOpen()) { chipsFollow(e.clientX, e.clientY); }
        });
    });

    // Confetti, for real wins.
    function confetti() {
        if (reduced) { return; }
        var fx = el.querySelector('.nst-buddy__fx');
        var colors = ['#ff9148', '#ffd400', '#6c5ce7', '#2bb673', '#e2582a', '#3fa7ff'];
        var html = '';
        for (var i = 0; i < 22; i++) {
            var a = Math.random() * Math.PI - Math.PI;
            var dist = 40 + Math.random() * 70;
            html += '<i style="--x:' + (Math.cos(a) * dist).toFixed(0) + 'px;--y:' + (Math.sin(a) * dist - 20).toFixed(0) + 'px;--r:' + Math.floor(Math.random() * 540) + 'deg;background:' + colors[i % colors.length] + ';animation-delay:' + (Math.random() * 0.12).toFixed(2) + 's"></i>';
        }
        fx.innerHTML = html;
        setTimeout(function () { fx.innerHTML = ''; }, 1500);
    }

    /* ------------------------------------------------------------------
     * Where Niby sits: wherever it was dropped, kept inside the window.
     * ---------------------------------------------------------------- */

    function place() {
        var h = me.offsetHeight || 90;
        var w = me.offsetWidth || 78;
        var pos = el.dataset.guest ? null : mem.pos;
        var side = document.getElementById('nst-side');
        var sr = side && side.offsetWidth ? side.getBoundingClientRect() : null;
        var x = pos ? pos.x * window.innerWidth : (sr && sr.right < window.innerWidth - w - 40 ? sr.right + 18 : 18);
        var y = pos ? pos.y * window.innerHeight : window.innerHeight - h - 10;
        x = Math.max(4, Math.min(x, window.innerWidth - w - 4));
        y = Math.max(4, Math.min(y, window.innerHeight - h - 4));
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        if (el.dataset.guest && mem.kgPos && gEl && gEl.style.position === 'fixed') { kgPlace(mem.kgPos.x * window.innerWidth, mem.kgPos.y * window.innerHeight); } else if (el.dataset.guest && gEl && !gEl.hidden && !kgWalking && !kgHome) { var kc = kgCorner(); kgPlace(kc.x, kc.y); }
        if (gEl && gEl.style.position !== 'fixed') { kgSide(); }
        aim();
    }

    function kgSide() {
        var r = el.getBoundingClientRect();
        var onLeft = r.left + r.width / 2 < window.innerWidth / 2;
        gEl.style.right = onLeft ? 'auto' : 'calc(100% - 6px)';
        gEl.style.left = onLeft ? 'calc(100% - 6px)' : '';
    }

    // The bubble opens to whichever side has room. Set here, by the
    // screen's own left and right, so a right-to-left admin does not flip it.
    function aim() {
        if (el.dataset.guest && gEl && !gEl.hidden) {
            // The tail points at whoever is talking: Kind Guy, or Niby in hiding
            // (mostly below the edge, so the bubble stays on screen above it).
            var nibyTalks = bubble.dataset.who !== 'max';
            var g = (nibyTalks ? me : gEl).getBoundingClientRect();
            if (nibyTalks) { g = { left: g.left, width: g.width, top: Math.min(g.top, window.innerHeight - 56), bottom: g.bottom }; }
            var c = el.getBoundingClientRect();
            var bw = bubble.offsetWidth || 300;
            var bh = bubble.offsetHeight || 160;
            var under = g.top < bh + 16;
            var bx = Math.max(8, Math.min(g.left + g.width / 2 - bw / 2, window.innerWidth - bw - 8));
            var t = bubble.querySelector('.nst-buddy__tail');
            el.classList.toggle('is-below', under);
            bubble.style.left = (bx - c.left) + 'px';
            bubble.style.right = 'auto';
            bubble.style.bottom = under ? 'auto' : (c.bottom - g.top + 30) + 'px';
            bubble.style.top = under ? (g.bottom - c.top + 6) + 'px' : '';
            t.style.left = Math.max(16, Math.min(g.left + g.width / 2 - bx - 7, bw - 30)) + 'px';
            t.style.right = 'auto';
            return;
        }
        bubble.style.bottom = '';
        bubble.style.top = '';
        var r = me.getBoundingClientRect();
        var left = r.left + r.width / 2 < window.innerWidth / 2;
        var tail = bubble.querySelector('.nst-buddy__tail');
        el.classList.toggle('is-below', r.top < (bubble.offsetHeight || 160) + 16);
        bubble.style.left = left ? '0' : 'auto';
        bubble.style.right = left ? 'auto' : '0';
        // With Kind Guy on, the tail points at him, left of Niby.
        var at = el.dataset.guest ? '120px' : '30px';
        tail.style.left = left ? at : 'auto';
        tail.style.right = left ? 'auto' : at;
    }

    me.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || el.dataset.guest) { return; }
        var r = me.getBoundingClientRect();
        var ox = e.clientX - r.left;
        var oy = e.clientY - r.top;
        var sx = e.clientX;
        var sy = e.clientY;
        var moved = false;
        me.setPointerCapture(e.pointerId);
        var move = function (ev) {
            if (!moved && Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 5) { return; }
            if (!moved) {
                moved = true;
                dragging = true;
                el.classList.add('is-dragging');
                hush();
                face('o', 'wide');
            }
            var x = Math.max(4, Math.min(ev.clientX - ox, window.innerWidth - r.width - 4));
            var y = Math.max(4, Math.min(ev.clientY - oy, window.innerHeight - r.height - 4));
            mem.pos = { x: x / window.innerWidth, y: y / window.innerHeight };
            el.style.left = x + 'px';
            el.style.top = y + 'px';
        };
        var up = function () {
            me.removeEventListener('pointermove', move);
            me.removeEventListener('pointerup', up);
            me.removeEventListener('pointercancel', up);
            if (!moved) { return; }
            el.classList.remove('is-dragging');
            save();
            place();
            antic('squeeze');
            // A drag is not a click.
            me.dataset.dragged = '1';
            setTimeout(function () { delete me.dataset.dragged; dragging = false; }, 60);
        };
        me.addEventListener('pointermove', move);
        me.addEventListener('pointerup', up);
        me.addEventListener('pointercancel', up);
    });
    window.addEventListener('resize', function () {
        if (el.hidden) { return; }
        if (touring) { tourShow(touring.i); return; }
        place();
    });
    if (window.ResizeObserver && document.getElementById('nst-side')) {
        new ResizeObserver(function () { if (!el.hidden && !mem.pos && !touring) { place(); } }).observe(document.getElementById('nst-side'));
    }

    /* ------------------------------------------------------------------
     * Speaking.
     * ---------------------------------------------------------------- */

    var hideTimer = 0;
    var lastSaid = 0;
    var lastTyped = 0;
    var current = null;

    // Not while the person types, picks in a window, or just heard from Niby.
    function canTalk(gap) {
        if (!mem.on || el.hidden || dragging) { return false; }
        if (document.querySelector('dialog[open]')) { return false; }
        if (Date.now() - lastTyped < 4000) { return false; }
        if (current && current.chat && !bubble.hidden) { return false; }
        if (touring) { return false; }
        return Date.now() - lastSaid > (gap === undefined ? 45000 : gap);
    }

    // acts: [[label, action], ...]; opts: { mood, stay, foot, more, thinking }.
    function say(text, acts, opts) {
        opts = opts || {};
        if (typeof kgModes !== 'undefined' && kgModes && !kgModes.hidden) { return; }
        lastSaid = Date.now();
        current = opts;
        var p = bubble.querySelector('.nst-buddy__text');
        p.textContent = String(text).replace(/\*\*([^*]+)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1');
        p.classList.toggle('is-thinking', !!opts.thinking);
        var maxTalks = !!el.dataset.guest && opts.who !== 'niby';
        bubble.querySelector('.nst-buddy__who').textContent = el.dataset.guest ? (maxTalks ? 'Kind Guy' : 'Niby') : '';
        bubble.dataset.who = maxTalks ? 'max' : '';
        // The first button is the main one, unless another says it is (a[2]).
        var mainAt = Math.max(0, (acts || []).findIndex(function (a) { return a[2]; }));
        bubble.querySelector('.nst-buddy__acts').innerHTML = (acts || []).map(function (a, i) {
            return '<button type="button" class="nst-buddy__act' + (i === mainAt ? ' is-main' : '') + '" data-b="' + esc(a[1]) + '">' + esc(a[0]) + '</button>';
        }).join('');
        bubble.querySelector('.nst-buddy__more').innerHTML = opts.more || '';
        bubble.classList.toggle('is-chat', !!opts.chat);
        bubble.classList.toggle('is-tour', !!opts.tour);
        bubble.querySelector('.nst-buddy__q').textContent = opts.q || '';
        if (opts.chat) { paintStatus(); }
        bubble.querySelector('.nst-buddy__foot').innerHTML = opts.foot || '';
        bubble.hidden = false;
        aim();
        if (maxTalks) {
            if (!opts.thinking) {
                if (opts.act !== 'keep') { gAct(opts.act || (/!/.test(text) ? 'stomp' : (/\bbest\b|nobody|beat me/i.test(text) ? 'brag' : (text.length > 70 && Math.random() < 0.55 ? 'explain' : 'talk')))); }
                kgLips(Math.min(3000, 500 + text.length * 34));
            }
        } else if (!opts.thinking) {
            antic('pop');
            mood(opts.mood || feel(text), 2400);
            lips(Math.min(3200, 600 + text.length * 38));
        }
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hush, opts.stay || Math.max(7000, text.length * 75));
        var field = bubble.querySelector('.nst-buddy__ask input, .nst-buddy__ask textarea');
        if (field && opts.focus) { setTimeout(function () { field.focus(); }, 30); }
    }

    // The face that fits a line.
    function feel(text) {
        if (/sorry|oops|sadly|unfortunately|didn.t work|did not work|can.t|cannot|hmm/i.test(text)) { return 'sad'; }
        if (/haha|lol|😄|😂|joke|kidding/i.test(text)) { return 'laugh'; }
        if (/!|great|nice|done|yay|awesome|perfect|love|bravo|thank/i.test(text)) { return 'happy'; }
        if (/\?\s*$/.test(text)) { return 'think'; }
        return 'talk';
    }

    function hush() {
        clearTimeout(hideTimer);
        clearInterval(lipTimer);
        kgQuiet();
        bubble.hidden = true;
        var was = current;
        current = null;
        if (was && was.onClose) { was.onClose(); }
    }

    bubble.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    bubble.addEventListener('focusin', function () { clearTimeout(hideTimer); });
    bubble.addEventListener('mouseleave', function () {
        if (current && (current.chat || current.tour)) { return; }
        if (!bubble.contains(document.activeElement)) { hideTimer = setTimeout(hush, 5000); }
    });

    // One tip, once ever.
    function tip(id, text, acts, opts) {
        if (mem.seen[id] || !canTalk(opts && opts.gap)) { return false; }
        mem.seen[id] = Date.now();
        save();
        say(text, acts || [[__('Got it', 'nibwp'), 'close']], opts);
        return true;
    }

    /* ------------------------------------------------------------------
     * Lines from the agent. Niby asks, thinks out loud while it waits, and
     * says its own line when no agent is free or none answers in time.
     * ---------------------------------------------------------------- */

    var agentFree = !!(boot.presence && boot.presence.state === 'listening');
    var noAgent = !(boot.presence && (boot.presence.state === 'listening' || boot.presence.state === 'working'));
    var lastAsk = '';
    var lastReply = '';
    var talk = [];
    var thinking = 0;

    function rest(path, body) {
        return fetch(boot.rest + path, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: body ? { 'X-WP-Nonce': boot.nonce, 'Content-Type': 'application/json' } : { 'X-WP-Nonce': boot.nonce },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.ok ? r.json() : {}; }, function () { return {}; });
    }

    function context(extra) {
        var tab = document.querySelector('.nst-tab.is-active .nst-tab__btn');
        var lines = [
            'Site: ' + (boot.siteName || ''),
            'Page on screen: ' + (tab ? tab.getAttribute('title') || tab.textContent : '')
        ];
        if (lastAsk) { lines.push('The person last asked their agent: ' + lastAsk); }
        if (lastReply) { lines.push('The agent last answered: ' + lastReply); }
        if (talk.length) {
            var who = guest === 'on' ? 'Kind Guy' : 'Niby';
            lines.push('Talk with ' + who + ' so far:\n' + talk.map(function (t) { return (t[0] === 'you' ? 'Person: ' : who + ': ') + String(t[1]).slice(0, 600); }).join('\n'));
        }
        if (extra) { lines.push(extra); }
        return lines.join('\n');
    }

    // instant: a line Niby says right away (from its guide); the agent's own
    // answer follows it when it lands. With nothing to say at once, Niby says
    // it asked, and the answer pops up whenever it comes, up to a minute
    // later, even if the bubble was closed. opts.quiet: no word if none comes.
    var held = null;
    function ai(moment, extra, instant, acts, opts) {
        opts = opts || {};
        if (instant) { say(instant, acts, opts); }
        if (!agentFree && !instant && !opts.quiet && opts.chat && agentState === 'working') {
            held = function () { ai(moment, extra, instant, acts, opts); };
            mood('think');
            /* translators: %s: the job the agent is working on */
            say(jobNow ? sprintf(__('Your agent is busy with “%s”. I’ll answer right after.', 'nibwp'), jobClip(jobNow)) : __('Your agent is busy right now. I’ll answer right after.', 'nibwp'),
                [], { stay: CHAT_MS, chat: true, q: opts.q, more: opts.more, thinking: true });
            return;
        }
        if (!agentFree) {
            if (!instant && opts.fallback) { say(opts.fallback, acts, opts); }
            return;
        }
        var job = ++thinking;
        var waiting = !instant && !opts.quiet;
        if (waiting) {
            mood('think');
            say(opts.chat ? __('Thinking', 'nibwp') : __('Asking your agent, one moment', 'nibwp'), [], { thinking: true, stay: opts.chat ? CHAT_MS : 65000, more: opts.more, chat: opts.chat, q: opts.q });
        }
        var started = Date.now();
        rest('buddy', { moment: moment, context: context(extra) }).then(function (r) {
            if (!r.id) { return null; }
            return new Promise(function (done) {
                (function check() {
                    if (job !== thinking) { return done(null); }
                    rest('buddy?id=' + encodeURIComponent(r.id)).then(function (x) {
                        if (x.text) { worked(); return done(x.text); }
                        if (Date.now() - started > 60000) { return done(null); }
                        // Say why it is taking a while, once.
                        if (waiting && Date.now() - started > 15000 && current && current.thinking && !current.slow) {
                            current.slow = true;
                            bubble.querySelector('.nst-buddy__text').textContent = __('Still thinking. Your agent may be busy with something else', 'nibwp');
                        }
                        setTimeout(check, 1500);
                    });
                }());
            });
        }).then(function (text) {
            if (job !== thinking) { return; }
            if (steady === 'think' || el.dataset.mood === 'think') { mood('idle'); }
            if (text) {
                if (opts.onText) { opts.onText(text); }
                /* translators: %s: what the agent wrote for Niby to say */
                say(instant ? sprintf(__('And my agent adds: %s', 'nibwp'), text) : text, acts, opts);
            } else if (waiting) {
                say(opts.fallback || __('My agent did not answer this time. It may be busy; ask me again in a bit.', 'nibwp'), acts, opts);
            }
        });
    }

    /* ------------------------------------------------------------------
     * Lines kept here, for when no agent is free.
     * ---------------------------------------------------------------- */

    var TIPS = [
        __('Press Ctrl K to search your whole site, or paste any web address to open a safe snapshot of it.', 'nibwp'),
        __('Press [ to fold the side panel and give the page more room.', 'nibwp'),
        __('History shows every saved version of a page. Preview one in place before you restore it.', 'nibwp'),
        __('Right-click a tab to put related tabs in a coloured group.', 'nibwp'),
        __('Type / in the message box for starters, skills and your NibWP workflows.', 'nibwp'),
        __('Press D to draw a box, a circle or a line around what you mean.', 'nibwp'),
        __('The phone and tablet buttons show the page at those widths. Notes pinned there tell the agent which one to fix.', 'nibwp'),
        __('Media holds every file on the site. Pick a few and add them to your message.', 'nibwp'),
        __('Pin several notes with Add another, then send them as one message. Your agent gets them numbered.', 'nibwp'),
        __('The count next to Comment and Draw lists every comment in this thread. Click one to jump to it.', 'nibwp'),
        __('Ctrl-click a link on the page to open it in a new tab, like in a browser.', 'nibwp'),
        __('No agent at hand? Every message has Copy as prompt, ready to paste into any AI chat.', 'nibwp'),
        __('In the … menu, Ask before page actions makes your agent ask you before it clicks or types on a page.', 'nibwp'),
        __('Opened another site as a snapshot? Point at what you like there and ask for the same on yours.', 'nibwp')
    ];
    var QUIPS = [
        __('Hey! I bend, I don’t break.', 'nibwp'),
        __('Poke me once more and I’ll start reading your CSS out loud.', 'nibwp'),
        __('I’m not idle. I’m in energy-saving mode. Very different.', 'nibwp'),
        __('Yes? I was busy looking important.', 'nibwp'),
        __('Careful, this antenna picks up compliments too.', 'nibwp'),
        __('I’ve never forgotten a semicolon. Mostly because I don’t write code.', 'nibwp')
    ];
    var GUIDE = [
        [/comment|note|pin|point|click/i, sprintf(__('Press %s (or Comment up top), then click anything on the page. Write what should change and press Enter to send it, or Add another to pin a few and send them together.', 'nibwp'), KEY_C)],
        [/draw|box|circle|pen|sketch|mark/i, sprintf(__('Press %s (or Draw up top) and pick Box, Circle or Pen in the bar at the bottom. Drag on the page, then write what should change. The drawing goes to your agent with the note.', 'nibwp'), KEY_D)],
        [/tab|group/i, __('The + opens a new tab. Ctrl-click a link to open it in one. Right-click a tab to group tabs, colour or name the group, or close tabs.', 'nibwp')],
        [/snapshot|other site|external|competitor|website/i, __('Paste any web address in the bar up top. Another site opens as a safe snapshot: you can point at it and copy from it, though its own scripts stay off.', 'nibwp')],
        [/histor|undo|restore|version|back/i, __('Every save shows in the thread with Undo. The History tab lists all versions of the page: preview one in place, then Restore. What is there now is kept, so you can switch back.', 'nibwp')],
        [/media|file|image|upload|screenshot|photo/i, __('The picture icon up top opens Media: browse by type or month, search, pick several (Shift for a run), then Add to message, Copy links, or Delete. Deleted files wait in Trash.', 'nibwp')],
        [/connect|agent|claude|cursor|mcp|listen/i, __('Click the agent status at the top left. Connect your AI client once, then paste the message it shows into your agent. It then listens to everything you send from here.', 'nibwp')],
        [/workflow|skill|slash|starter|\//i,__('Type / in the message box: starters, pages to include, skills and your NibWP workflows. Pick one and it rides along with your message.', 'nibwp')],
        [/phone|mobile|tablet|responsive|width/i, __('The phone and tablet buttons up top show the page at those widths. Notes you pin there tell the agent which layout to fix.', 'nibwp')],
        [/search|find|open page|ctrl k/i, __('Press Ctrl K and type: it searches pages, posts and products, or takes a path like /contact/.', 'nibwp')],
        [/approv|permission|ask before|safe/i, __('In the … menu, Ask before page actions makes your agent ask you before it clicks or types on a page.', 'nibwp')]
    ];
    var GUIDE_TEXT = 'How Agent View works, for answering the person: ' + GUIDE.map(function (g) { return g[1]; }).join(' ');
    function guideAnswer(q) {
        var hit = GUIDE.find(function (g) { return g[0].test(q); });
        return hit ? hit[1] : null;
    }
    var pick = function (list) { return list[Math.floor(Math.random() * list.length)]; };
    // Kind Guy talks Etch only where Etch is open; anywhere else he is the pro
    // developer (PHP, HTML, CSS, JavaScript), and lines about Etch stay unsaid.
    var kgCtx = 'code';
    var kgPick = function (list) {
        if (kgCtx === 'etch') { return pick(list); }
        var plain = list.filter(function (x) { return !/\b(Etch|ACSS)\b/.test(typeof x === 'string' ? x : (x && x.text) || ''); });
        return pick(plain.length ? plain : list);
    };

    // "Did you know?": what this NibWP can do, read from its own skills and
    // workflows, so every line is true here, and each has a Try it. Skills and
    // workflows take turns, with a few about Agent View itself between.
    var DYK = (function () {
        var lower = function (t) { return /^[A-Z][a-z]/.test(t) ? t.charAt(0).toLowerCase() + t.slice(1) : t; };
        var clip = function (t) {
            t = String(t || '').trim().replace(/[.\s]+$/, '');
            return t.length > 150 ? t.slice(0, t.lastIndexOf(' ', 150)) + '…' : t;
        };
        var skills = (boot.skills || []).filter(function (k) { return k.ready && k.tagline; }).map(function (k) {
            /* translators: 1: a NibWP skill, e.g. "Bricks Pro", 2: what it does */
            return { text: sprintf(__('Did you know? With %1$s, NibWP can %2$s.', 'nibwp'), k.name, lower(clip(k.tagline))), q: k.name };
        });
        var flows = (boot.workflows || []).filter(function (w) { return w.title; }).map(function (w) {
            /* translators: 1: a workflow title, 2: what it does */
            return { text: sprintf(__('Did you know? NibWP has a ready workflow for “%1$s”. %2$s', 'nibwp'), w.title, clip(w.summary)), q: w.title };
        });
        var own = [
            __('Did you know? NibWP can work on any page you open here. Pin a note on it and say what you want; your agent does the rest.', 'nibwp'),
            __('Did you know? NibWP keeps every version of a page. If a change is not right, one click on Undo brings the old one back.', 'nibwp'),
            __('Did you know? NibWP can open any other website as a safe snapshot, so you can point at a design you like and ask for the same.', 'nibwp'),
            __('Did you know? NibWP can read a file or screenshot you drop in the message box and build from it.', 'nibwp')
        ].map(function (t) { return { text: t, q: '' }; });
        var out = [];
        for (var i = 0; i < Math.max(skills.length, flows.length, own.length * 3); i++) {
            if (skills[i]) { out.push(skills[i]); }
            if (flows[i]) { out.push(flows[i]); }
            if (i % 3 === 2 && own[(i - 2) / 3]) { out.push(own[(i - 2) / 3]); }
        }
        return out;
    }());

    function dyk() {
        if (!DYK.length) { return false; }
        mem.dykNo = (mem.dykNo + 1) % DYK.length;
        save();
        var d = DYK[mem.dykNo];
        var acts = d.q ? [[__('Try it', 'nibwp'), 'find:' + d.q], [__('Another one', 'nibwp'), 'dyk']] : [[__('Another one', 'nibwp'), 'dyk'], [__('Nice', 'nibwp'), 'close']];
        antic('peek');
        say(d.text, acts, { mood: 'happy', stay: 14000 });
        return true;
    }

    // The next tip in turn, remembered, so the same one does not come twice in a row.
    function nextTip() {
        mem.tipNo = (mem.tipNo + 1) % TIPS.length;
        save();
        return TIPS[mem.tipNo];
    }

    /* ------------------------------------------------------------------
     * Suggestions about NibWP: rare, after a good moment, easy to stop.
     * ---------------------------------------------------------------- */

    var shareText = __('My AI agent just fixed my WordPress site while I watched, with NibWP.', 'nibwp');
    var shareUrl = cfg.site || 'https://www.nibwp.com/';
    var PROMOS = [
        {
            id: 'share', gift: 'dance',
            text: __('That went well! Want to tell people how you fix your site now? A share helps NibWP grow, and I’ll learn a new trick for it.', 'nibwp'),
            acts: [['X', 'share-x'], ['LinkedIn', 'share-in'], ['Facebook', 'share-fb']]
        },
        {
            id: 'affiliate', gift: 'double', need: 'affiliate',
            /* translators: %s: the commission, e.g. "25% per sale" */
            text: sprintf(__('Enjoying NibWP? Bring other site owners and earn %s with the affiliate program. Joining takes two clicks.', 'nibwp'), cfg.rate || __('a commission', 'nibwp')),
            acts: [[__('See the program', 'nibwp'), 'affiliate']]
        },
        {
            id: 'review', gift: 'moonwalk', need: 'review',
            text: __('Would you leave NibWP a quick review? It helps other site owners find it.', 'nibwp'),
            acts: [[__('Write a review', 'nibwp'), 'review']]
        }
    ];
    var promoShown = false;

    function nextPromo() {
        return PROMOS.filter(function (p) {
            return !mem.promoYes[p.id] && (mem.promoNo[p.id] || 0) < 2 && (!p.need || cfg[p.need]);
        }).sort(function (a, b) { return (mem.promoNo[a.id] || 0) - (mem.promoNo[b.id] || 0); })[0] || null;
    }

    function maybePromo() {
        if (!cfg.promote || mem.promoOff || promoShown || mem.visits < 3) { return false; }
        if (Date.now() - mem.promoAt < 3 * DAY || !canTalk(8000)) { return false; }
        var p = nextPromo();
        if (!p) { return false; }
        promoShown = true;
        mem.promoAt = Date.now();
        save();
        var answered = false;
        var opts = {
            mood: 'happy', stay: 24000, promo: p,
            foot: '<button type="button" class="nst-buddy__off" data-b="promo-off">' + esc(__('Don’t suggest these', 'nibwp')) + '</button>',
            onClose: function () {
                if (answered) { return; }
                mem.promoNo[p.id] = (mem.promoNo[p.id] || 0) + 1;
                save();
            }
        };
        opts.answer = function () { answered = true; };
        say(p.text, p.acts.concat([[__('Not now', 'nibwp'), 'later']]), opts);
        return true;
    }

    function gift(p) {
        mem.promoYes[p.id] = Date.now();
        if (p.gift && mem.tricks.indexOf(p.gift) === -1) { mem.tricks.push(p.gift); }
        save();
        confetti();
        /* translators: %s: a trick, e.g. "the moonwalk" */
        say(sprintf(__('Thank you! For that I learned %s. Watch.', 'nibwp'), TRICKS[p.gift] || ''), [[__('Bravo', 'nibwp'), 'close']], { mood: 'happy', stay: 9000 });
        setTimeout(function () { antic(p.gift); }, 700);
    }

    function open(url) {
        if (url) { window.open(url, '_blank', 'noopener'); }
    }

    /* ------------------------------------------------------------------
     * Clicks: the menu, brainstorming, tricks.
     * ---------------------------------------------------------------- */

    var askBox = function (placeholder) {
        return '<form class="nst-buddy__ask" data-b="ask-form"><input type="text" maxlength="400" autocomplete="off" placeholder="' + esc(placeholder) + '" aria-label="' + esc(__('Ask Niby', 'nibwp')) + '"><button type="submit" aria-label="' + esc(__('Ask', 'nibwp')) + '" data-tip="' + esc(__('Ask Niby', 'nibwp')) + '">↑</button></form>';
    };

    var CHAT_MS = 600000;
    var chatBox = function (placeholder) {
        return '<form class="nst-buddy__ask is-big" data-b="ask-form"><textarea rows="2" maxlength="600" autocomplete="off" placeholder="' + esc(placeholder) + '" aria-label="' + esc(__('Chat with Niby', 'nibwp')) + '"></textarea><button type="submit" aria-label="' + esc(__('Send', 'nibwp')) + '">↑</button></form>';
    };
    var chatOpen = function () { return !!(current && current.chat && !bubble.hidden); };

    // What the agent is up to, in one line.
    var agentState = (boot.presence && boot.presence.state) || 'none';
    var jobNow = '';
    var jobClip = function (t) {
        t = String(t || '').split('\n')[0].replace(/\s+/g, ' ').trim();
        return t.length > 70 ? t.slice(0, 68).trim() + '…' : t;
    };
    function statusText() {
        if (agentState === 'working') { return jobNow ? sprintf(__('Agent working on “%s”', 'nibwp'), jobClip(jobNow)) : __('Agent working', 'nibwp'); }
        if (agentState === 'listening') { return __('Agent listening', 'nibwp'); }
        if (agentState === 'idle') { return __('Agent idle', 'nibwp'); }
        return __('No agent connected', 'nibwp');
    }
    function paintStatus() {
        var line = bubble.querySelector('.nst-buddy__status');
        line.textContent = statusText();
        line.dataset.state = agentState;
        el.dataset.agent = agentState;
    }

    // Niby says what the agent does. In an open chat it only updates the
    // status line, so the talk is never pushed away.
    // The agent asked you something: whoever is out says so over his head,
    // ten seconds or until it is answered, chat open or not.
    function askAlert(text) {
        if (touring || (!guest && (!mem.on || el.hidden))) { return; }
        /* translators: %s: the agent's question, shortened */
        var line = text ? sprintf(__('Your agent is waiting for your answer: “%s”', 'nibwp'), jobClip(text)) : __('Your agent is waiting for your answer.', 'nibwp');
        var acts = [[__('Answer it', 'nibwp'), 'ask-go', true]];
        if (guest === 'on') {
            gAct('tap');
            maxSay(line, acts, { stay: 10000, loud: true, act: 'keep', askAlert: true });
            return;
        }
        mood('wave', 2400);
        say(line, acts, { mood: 'wave', stay: 10000, who: 'niby', askAlert: true });
    }

    function narrate(text, opts) {
        opts = opts || {};
        paintStatus();
        if (guest || chatOpen() || touring || !mem.on || el.hidden) { return; }
        if (!opts.force && !canTalk(opts.gap === undefined ? 6000 : opts.gap)) { return; }
        say(text, opts.acts || [], { mood: opts.mood, stay: opts.stay || 6000 });
    }

    var SLEEPY = [
        __('Zzz… huh? No agent listening, so I’m asleep. Wake up your agent and I wake up too.', 'nibwp'),
        __('Five more minutes… Connect your agent, then I’m all yours.', 'nibwp'),
        __('Mmm… I run on your agent. No agent, no Niby. Wake it up?', 'nibwp'),
        __('I’m sleep-talking. Real answers when your agent is listening.', 'nibwp')
    ];
    function sleepyMenu() {
        var acts = [[__('Wake up my agent', 'nibwp'), 'connect'], [__('Comment', 'nibwp'), 'comment'], [__('Draw', 'nibwp'), 'draw'], [__('How does this work?', 'nibwp'), 'how'], [__('Take the tour', 'nibwp'), 'tour']];
        if (cfg.guest && !guest) { acts.push([__('Call Kind Guy', 'nibwp'), 'g-summon']); }
        acts.push([__('Hide me', 'nibwp'), 'off']);
        antic('yawn');
        say(pick(SLEEPY), acts, { mood: 'sleep', stay: 20000, isMenu: true, chat: true });
    }

    function menu(hi) {
        if (noAgent) { sleepyMenu(); return; }
        var acts = [[__('Comment', 'nibwp'), 'comment'], [__('Draw', 'nibwp'), 'draw'], [__('A tip, please', 'nibwp'), 'tip'], [__('Surprise me', 'nibwp'), 'dyk'], [__('Take the tour', 'nibwp'), 'tour']];
        if (cfg.guest && !guest) { acts.push([__('Call Kind Guy', 'nibwp'), 'g-summon']); }
        acts.push([__('Hide me', 'nibwp'), 'off']);
        hi = hi || (agentState === 'working' ? __('Your agent is busy. Ask me anything meanwhile; I’ll answer once it’s done, or line up the next change.', 'nibwp')
            : __('Hi! Ask me anything, or tell me what to change. I’ll pass the work to your agent.', 'nibwp'));
        say(hi, acts, {
            mood: 'wave', stay: CHAT_MS, isMenu: true, chat: true, focus: true,
            more: chatBox(__('Ask me anything, or tell me what to change…', 'nibwp')),
            foot: cfg.promote && !mem.promoOff ? '<button type="button" class="nst-buddy__off" data-b="promo-off">' + esc(__('No NibWP suggestions, please', 'nibwp')) + '</button>' : ''
        });
    }

    var pokes = [];

    // A request to change something, in the person's words: "make the title
    // bigger", "can you fix the footer". A question stays a question.
    var TASK = /^(please\s+)?(make|change|add|remove|delete|fix|update|create|write|rewrite|replace|move|translate|set|put|turn|hide|show|insert|resize|increase|decrease|improve|optimi[sz]e|build|design|redesign|edit|rename|publish|swap|use|center|centre|align|shorten|lengthen|style|color|colour)\b/i;
    var ASKS_TO = /\b(can|could|would|will) you\b.{0,40}\b(make|change|add|remove|delete|fix|update|create|write|rewrite|replace|move|translate|set|edit|improve|redesign|build)\b/i;
    function isTask(q) {
        return (TASK.test(q) || ASKS_TO.test(q)) && !/^(how|what|why|where|when|which|who)\b/i.test(q);
    }

    // Hands a request to the agent the way the message box does, so the page
    // on screen and any pinned notes go with it.
    // The request flies from where it was asked to the chat on the left.
    function throwTo(from, text) {
        var br = window.nibwpStudioBridge;
        if (br && br.throwTo) { br.throwTo(from, text); }
    }

    function doTask(q, from) {
        var field = document.getElementById('nst-input');
        var send = document.getElementById('nst-send');
        if (!field || !send) { return; }
        throwTo(from || (chatOpen() ? bubble : null), q);
        field.value = field.value.trim() ? field.value.trim() + '\n' + q : q;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        send.disabled = false;
        send.click();
        talk.push(['you', q]);
        talk = talk.slice(-6);
        if (guest) { return; }
        antic('jump');
        var inChat = chatOpen();
        say(agentFree || steady === 'work'
            ? __('On it! I passed that to your agent. Watch the page, I’ll tell you when it’s done.', 'nibwp')
            : __('Noted! It waits in the thread for your agent, and gets done as soon as one is listening.', 'nibwp'), [], inChat
            ? { mood: 'work', stay: CHAT_MS, chat: true, q: q, more: chatBox(__('Anything else?', 'nibwp')) }
            : { mood: 'work', stay: 6000 });
    }
    el.addEventListener('submit', function (e) {
        var form = e.target.closest('.nst-buddy__ask');
        if (!form) { return; }
        e.preventDefault();
        var q = form.querySelector('input, textarea').value.trim();
        if (!q) { return; }
        if (isTask(q) && !guest) {
            pendingTask = q;
            say(__('Sounds like a job! Want a quick answer from me here, or shall I pass it to your agent to do it properly?', 'nibwp'),
                [[__('Get answer here', 'nibwp'), 'here'], [__('Pass it to the agent', 'nibwp'), 'pass', true]],
                { mood: 'think', stay: CHAT_MS, chat: true, q: q, more: chatBox(__('Or ask me something else…', 'nibwp')) });
            return;
        }
        if (isTask(q)) {
            doTask(q);
            return;
        }
        if (guest === 'on') {
            maxQuestion(q);
            return;
        }
        if (!guest && cfg.guest && KG_ASK.test(q)) {
            pendingQ = KG_ASK_ONLY.test(q) ? '' : q;
            kgxSummon();
            return;
        }
        if (!guest && canCallMax() && ETCH.test(q)) {
            pendingQ = q;
            guestOffered = true;
            kgCtx = 'etch';
            say(__('An Etch question? That is Kind Guy’s thing, our Etch pro. Shall I call him?', 'nibwp'), [[__('Call Kind Guy', 'nibwp'), 'g-call'], [__('No, you answer', 'nibwp'), 'g-self']], { mood: 'worry', stay: 20000, who: 'niby' });
            return;
        }
        nibyQuestion(q);
    });

    el.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey || e.isComposing || !e.target.matches('.nst-buddy__ask textarea')) { return; }
        e.preventDefault();
        var f = e.target.form;
        if (f.requestSubmit) { f.requestSubmit(); } else { f.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
    });

    var pendingTask = '';
    function nibyQuestion(q) {
        talk.push(['you', q]);
        talk = talk.slice(-6);
        ai('question', 'The person asks Niby, or brainstorms with it: ' + q + ' ' + GUIDE_TEXT, guideAnswer(q),
            [[__('Pass it to the agent', 'nibwp'), 'do'], [__('Use as my request', 'nibwp'), 'use'], [__('Thanks', 'nibwp'), 'close']],
            {
                stay: CHAT_MS, chat: true, q: q, more: chatBox(__('Keep going…', 'nibwp')), focus: true,
                fallback: __('I think best with your agent listening. Connect one and ask me again, I’ll be brilliant. Probably. Ask me how to use Agent View meanwhile, that I know by heart.', 'nibwp'),
                onText: function (t) { talk.push(['nib', t]); talk = talk.slice(-6); }
            });
    }

    el.addEventListener('click', function (e) {
        var b = e.target.closest('[data-b]');
        if (!b || b.dataset.b === 'ask-form') { return; }
        if (b === me && me.dataset.dragged) { return; }
        if (gEl && gEl.dataset.dragged && gEl.contains(b)) { return; }
        var act = b.dataset.b;
        // With his chat open, a poke at him lands you back in the chat.
        if (guest === 'on' && kgChatIsOpen() && gEl.contains(b) && /^g-(menu|cap|beard|foot)/.test(act)) { kgChatField.focus(); }
        var promo = current && current.promo;
        if (promo && current.answer && act !== 'close' && act !== 'later' && act !== 'promo-off') { current.answer(); }
        switch (act) {
            case 'menu':
                if (el.dataset.guest) {
                    peek();
                    say(__('Shh! I’m hiding. He is scary.', 'nibwp'), [[__('Bring Niby back', 'nibwp'), 'g-bye']], { who: 'niby', stay: 6000, mood: 'worry' });
                    return;
                }
                pokes = pokes.filter(function (t) { return Date.now() - t < 2500; }).concat(Date.now());
                if (pokes.length >= 3) {
                    pokes = [];
                    antic('squeeze');
                    say(pick(QUIPS), [], { mood: 'happy', stay: 4500 });
                    return;
                }
                if (!bubble.hidden && current && current.isMenu) { hush(); return; }
                menu();
                return;
            case 'tour': current = null; hush(); tourStart(); return;
            case 'btour':
                current = null;
                hush();
                if (touring) { tourEnd(true); }
                if (window.nibwpBuilder && !window.nibwpBuilder.active()) { window.nibwpBuilder.start(); }
                setTimeout(function () { tourStart(BTOUR, 'btour'); }, reduced ? 200 : 1100);
                return;
            case 'btour-no': mem.seen.btour = Date.now(); save(); current = null; hush(); return;
            case 'here': if (pendingTask) { var hq = pendingTask; pendingTask = ''; nibyQuestion(hq); } return;
            case 'ask-go':
                hush();
                var waitBar = document.querySelector('.nst-waiting');
                if (waitBar && !waitBar.hidden) { waitBar.click(); }
                return;
            case 'pass': if (pendingTask) { var pq = pendingTask; pendingTask = ''; doTask(pq, bubble); } return;
            case 'tour-next': if (touring) { tourShow(touring.i + 1); } return;
            case 'tour-back': if (touring) { tourShow(touring.i - 1); } return;
            case 'tour-end': tourEnd(false); return;
            case 'tour-skip':
                current = null;
                hush();
                tourEnd(true);
                setTimeout(function () { say(__('No problem! The tour waits in my chat whenever you want it: Take the tour.', 'nibwp'), [], { mood: 'happy', stay: 5500 }); }, reduced ? 0 : 850);
                return;
            case 'how':
                say(sprintf(__('Here’s how it works: browse your site on the right. Press %1$s and click anything to pin a note, or %2$s to draw around it. Send it from the chat on the left. Your agent makes the change and saves it, and every change can be undone.', 'nibwp'), KEY_C, KEY_D),
                    [[__('Wake up my agent', 'nibwp'), 'connect'], [__('A tip, please', 'nibwp'), 'tip'], [__('Thanks', 'nibwp'), 'close']], { mood: noAgent ? 'sleep' : 'talk', stay: 30000, chat: true });
                return;
            case 'tip':
                say(nextTip(), [[__('Another one', 'nibwp'), 'tip'], [__('Thanks', 'nibwp'), 'close']], { stay: 16000 });
                return;
            case 'do':
                var last = talk.filter(function (t) { return t[0] === 'you'; }).pop();
                var tip2 = talk.filter(function (t) { return t[0] === 'nib'; }).pop();
                if (last) { doTask(last[1] + (tip2 ? '\n\n' + sprintf(guest ? __('Idea from Kind Guy: %s', 'nibwp') : __('Idea from Niby: %s', 'nibwp'), tip2[1]) : '')); }
                return;
            case 'dyk':
                dyk();
                return;
            case 'g-call': callMax(); return;
            case 'g-summon': kgxSummon(); return;
            case 'g-no':
                mem.maxNo = Date.now();
                save();
                pendingQ = '';
                el.classList.add('has-cap');
                say(__('Fine, just us then. Let me put my Etch hat on.', 'nibwp'), [], { mood: 'happy', stay: 4500, who: 'niby' });
                return;
            case 'g-self':
                var own = pendingQ;
                pendingQ = '';
                if (own) { nibyQuestion(own); }
                return;
            case 'g-menu':
                if (guest !== 'on') { return; }
                if (kgChatIsOpen()) { kgChatField.focus(); return; }
                if (!bubble.hidden && current && current.isMenu) { hush(); return; }
                maxSay(kgPick(MAX_POKE), maxActs(), { isMenu: true, stay: 25000, act: pick(['eyeroll', 'smug', 'glare']) });
                return;
            case 'kg-like-yes':
                mem.kgLikes = (mem.kgLikes || 0) + 1;
                save();
                gAct('brag');
                maxSay(pick(KGX_LIKE_YES), [], { act: 'keep', stay: 6000 });
                return;
            case 'kg-like-meh':
                gAct('huff');
                maxSay(__('“Okay”?! I inspect your markup for free and I get “okay”? … Fine. I’ll earn it.', 'nibwp'), [], { act: 'keep', stay: 6500 });
                return;
            case 'kg-like-no':
                gAct('stomp');
                maxSay(__('Not really?! … Fine. I’ll inspect harder. Out of pure spite. You’ll love me by Friday.', 'nibwp'), [], { act: 'keep', stay: 7000 });
                setTimeout(function () { if (guest === 'on') { gAct('huff'); } }, 1100);
                return;
            case 'kgx-modes':
                if (guest !== 'on') { return; }
                // Found the sign on their own: no need to point it out.
                if (!mem.seen.kgSign) { mem.seen.kgSign = Date.now(); save(); }
                kgxModes(kgModes.hidden);
                return;
            case 'kgx-duty-on':
            case 'kgx-duty-off':
                kgxModes(false);
                if (duty !== act.slice(9)) { setDuty(act.slice(9)); }
                return;
            case 'kgx-open': kgxModes(false); kgxOpen(); return;
            case 'kgx-chat': kgChatOpen(); return;
            case 'kgx-flip': hush(); setDuty(duty === 'on' ? 'off' : 'on'); return;
            case 'kgx-inspect': kgxInspect(); return;
            case 'kgx-point': hush(); kgPick(); return;
            case 'kgx-checks':
                hush();
                var box = document.getElementById('nst-input');
                if (box) {
                    box.value = box.value.trim() + '\n\n' + __('Make it responsive at phone, tablet and desktop, and accessible: contrast, labels, focus states, keyboard, alt text.', 'nibwp');
                    adviceFor = box.value.trim();
                    box.dispatchEvent(new Event('input', { bubbles: true }));
                    box.focus();
                }
                return;
            case 'kgx-advice':
                hush();
                var draft = document.getElementById('nst-input');
                if (draft && draft.value.trim()) {
                    kgChatOpen();
                    kgxAsk(draft.value.trim(), 'max-advice', __('Advice on my request, before I send it', 'nibwp'), null, 'box');
                }
                return;
            case 'kgx-share':
                open('https://x.com/intent/post?text=' + encodeURIComponent(__('Built and inspected on my WordPress site in minutes, with an AI agent and an expert watching over it. NibWP Agent View.', 'nibwp')) + '&url=' + encodeURIComponent(shareUrl));
                hush();
                return;
            case 'g-story': story(); return;
            case 'g-foot-l': if (guest === 'on') { kgFoot('l'); } return;
            case 'g-foot-r': if (guest === 'on') { kgFoot('r'); } return;
            case 'g-cap':
                if (guest !== 'on') { return; }
                // One tap: he guards the cap. Three quick ones: he turns it backward, for a while.
                capHits = capHits.filter(function (t) { return Date.now() - t < 1500; }).concat(Date.now());
                if (capHits.length >= 3) {
                    capHits = [];
                    gEl.dataset.cap = 'back';
                    clearTimeout(capBackT);
                    capBackT = setTimeout(function () { delete gEl.dataset.cap; }, 9000);
                    gAct('smug');
                    maxSay(pick(KG_HAT), [], { act: 'keep', stay: 5000 });
                    return;
                }
                if (capHits.length === 1) { gAct('glare'); maxSay(pick(KG_CAP_TOUCH), [], { act: 'keep', stay: 3500 }); }
                return;
            case 'g-beard':
                if (guest !== 'on') { return; }
                gAct('scratch');
                maxSay(pick(KG_BEARD), [], { act: 'keep', stay: 5000 });
                return;
            case 'g-trick': maxTrick(); return;
            case 'g-bye': leaveMax(); return;
            case 'use':
                var field = document.getElementById('nst-input');
                var idea = talk.filter(function (t) { return t[0] === 'nib'; }).pop();
                var asked = talk.filter(function (t) { return t[0] === 'you'; }).pop();
                if (field) {
                    field.value = (asked ? asked[1] : '') + (idea ? '\n\n' + sprintf(guest ? __('Idea from Kind Guy: %s', 'nibwp') : __('Idea from Niby: %s', 'nibwp'), idea[1]) : '');
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.focus();
                }
                hush();
                return;
            case 'off': setOn(false); return;
            case 'promo-off':
                mem.promoOff = true;
                save();
                say(__('Understood. No more suggestions from me, only tips. And the occasional joke.', 'nibwp'), [[__('Thanks', 'nibwp'), 'close']], { stay: 5000 });
                return;
            case 'connect': hush(); document.getElementById('nst-connect').showModal(); return;
            case 'comment': hush(); document.getElementById('nst-comment').click(); return;
            case 'draw': hush(); document.getElementById('nst-draw').click(); return;
            case 'share-x': open('https://x.com/intent/post?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(shareUrl)); gift(promo); return;
            case 'share-in': open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(shareUrl)); gift(promo); return;
            case 'share-fb': open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(shareUrl)); gift(promo); return;
            case 'affiliate': open(cfg.affiliate); gift(promo); return;
            case 'review': open(cfg.review); gift(promo); return;
        }
        if (act.indexOf('kgx-wf:') === 0) {
            hush();
            kgxAttach(act.slice(7));
            maxSay(__('Attached. Now it gets done by the book. My book.', 'nibwp'), [], { act: 'brag', stay: 5000 });
            return;
        }
        if (act.indexOf('find:') === 0) {
            hush();
            document.dispatchEvent(new CustomEvent('nibwp-studio-find', { detail: { q: act.slice(5) } }));
            return;
        }
        if (act.indexOf('trick:') === 0) {
            hush();
            antic(act.slice(6));
            return;
        }
        hush();
    });

    // Out: the shadow opens into a black hole and pulls Niby in, wriggling.
    // In: the hole opens again and spits it out. Switching back half way
    // simply turns the trip around.
    var PORTAL_MS = { out: 1500, in: 1250 };
    function portal(dir, done) {
        clearTimeout(portal.t);
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) { done(); return; }
        clearTimeout(antic.t);
        delete el.dataset.antic;
        el.dataset.portal = dir;
        if (dir === 'out') { face('o', 'wide', 'up'); } else { face('grin', 'happy', 'up'); }
        portal.t = setTimeout(function () {
            delete el.dataset.portal;
            var f = FACE[el.dataset.mood] || FACE.idle;
            face(f[0], f[1], f[2]);
            done();
        }, PORTAL_MS[dir]);
    }

    function setOn(on) {
        mem.on = on;
        save();
        toggle.setAttribute('aria-pressed', String(on));
        hush();
        if (!on) {
            dropMax();
            portal('out', function () { el.hidden = true; });
            return;
        }
        el.hidden = false;
        place();
        lastSaid = 0;
        portal('in', function () {
            if (!mem.seen.intro) { intro(); return; }
            say(__('I’m back! Did you miss me? Don’t answer that.', 'nibwp'), [], { stay: 4500, mood: 'wave' });
        });
    }
    toggle.addEventListener('click', function () { setOn(!mem.on); });

    /* ------------------------------------------------------------------
     * Kind Guy, the Etch pro. When the work is about Etch or ACSS (the Etch
     * editor or an Etch page on screen, a request or a question naming
     * them), Niby offers to call him. Kind Guy walks in, Niby hides in the
     * corner and peeks now and then, and Kind Guy takes over the bubble: a tour
     * of what this NibWP does with Etch, tips, tricks and a temper. He
     * walks out when told to, or once the work has moved away from Etch.
     * He mocks bad habits and other builders, never by name.
     * ---------------------------------------------------------------- */

    var ETCH = /\b(etch(wp)?|acss|automatic\.?css)\b/i;
    // Asking Niby for Kind Guy, or for what he does.
    var KG_ASK = /\b(kind ?guy|the expert|inspect|code review|review (my|the|this) (code|work|page|site))\b/i;
    var KG_ASK_ONLY = /^\W*(call |get |bring( in)? |hey |hi )?(kind ?guy|the expert)\W*$/i;
    var OTHER = /\b(bricks|elementor|divi|oxygen|breakdance|beaver|wpbakery|kadence|gutenberg|block editor)\b/i;
    var guest = null;
    var guestOffered = false;
    var pendingQ = '';
    var etchAt = 0;
    var pageEtch = false;
    var storyNo = 0;
    var peekT = 0;

    var gEl = document.createElement('div');
    gEl.className = 'nst-buddy__guest';
    gEl.hidden = true;
    // Left of Niby by the screen's own sides, as the bubble is (see aim()).
    gEl.style.right = 'calc(100% - 6px)';
    // Layers in the drawing's own coordinates (563 x 1206): the legs, the body
    // (its drawn head masked away), his head with its buzz cut, the brim's shadow, eyelids,
    // mouths, the right arm with a hand for scratching, the cap, earmuffs,
    // and three touch zones on top: cap, beard, feet.
    var KG = esc(cfg.guest || '');
    // Everything of the drawn head above this jaw line is masked away; his own head takes its place.
    var KG_JAW = 'M-100 -200 L700 -200 L700 568 L470 570 L440 586 L410 611 L380 632 L352 646 L344 624 L264 624 L256 646 L230 640 L200 627 L170 613 L140 600 L100 566 L-100 566 Z';
    var KG_HEAD = '<image class="nst-kg__head" href="' + KG + 'head.webp" x="42.9" y="104.6" width="514.2" height="548.6"/>';
    var KG_SHADE = 'M96 252 L102 252 L108 252 L114 251.3 L120 247.3 L126 243.3 L132 239.5 L138 236.7 L144 233.8 L150 231 L156 228.8 L162 226.6 L168 224.4 L174 222.2 L180 219.9 L186 217.7 L192 216.6 L198 215.9 L204 215.2 L210 214.4 L216 213.4 L222 212.5 L228 212 L234 212 L240 212 L246 212.5 L252 214.1 L258 215.7 L264 217.3 L270 218.8 L276 220.4 L282 222 L288 224.3 L294 226.7 L300 229 L306 231.2 L312 233.4 L318 235.6 L324 238.4 L330 241.2 L336 244.1 L342 246.1 L348 247.6 L354 249.2 L360 250.7 L366 252 L372 253.3 L378 255.6 L384 258.7 L390 261.9 L396 264.6 L402 266.5 L408 268.4 L414 270.2 L420 271.5 L426 272.7 L432 274 L438 273.7 L444 273.3 L450 273 L456 274.6 L462 276.2 L468 277.7 L474 278.5 L480 279.2 L486 279.8 L492 282 L498 285 L504 286 L510 286 L516 286 L516 314 L510 314 L504 314 L498 313 L492 310 L486 307.8 L480 307.2 L474 306.5 L468 317.7 L462 316.2 L456 314.6 L450 313 L444 313.3 L438 313.7 L432 314 L426 312.7 L420 311.5 L414 310.2 L408 308.4 L402 306.5 L396 304.6 L390 301.9 L384 298.7 L378 295.6 L372 293.3 L366 292 L360 290.7 L354 289.2 L348 287.6 L342 286.1 L336 284.1 L330 281.2 L324 278.4 L318 275.6 L312 273.4 L306 271.2 L300 269 L294 266.7 L288 264.3 L282 262 L276 260.4 L270 258.8 L264 257.3 L258 255.7 L252 254.1 L246 252.5 L240 252 L234 252 L228 252 L222 252.5 L216 253.4 L210 254.4 L204 255.2 L198 255.9 L192 256.6 L186 257.7 L180 259.9 L174 262.2 L168 264.4 L162 266.6 L156 268.8 L150 271 L144 273.8 L138 276.7 L132 279.5 L126 283.3 L120 275.3 L114 279.3 L108 280 L102 280 L96 280 Z';
    gEl.innerHTML = '<button type="button" class="nst-buddy__max" data-b="g-menu" aria-label="' + esc(__('Kind Guy, the expert. Click to talk to him, drag to move.', 'nibwp')) + '">' +
        '<svg class="nst-kg" viewBox="0 0 563 1206" width="112" height="240" aria-hidden="true">' +
            '<defs><mask id="nst-kg-nohead" maskUnits="userSpaceOnUse" x="-100" y="-200" width="800" height="1500"><rect x="-100" y="-200" width="800" height="1500" fill="#fff"/><path d="' + KG_JAW + '" fill="#000"/></mask><filter id="nst-kg-shade" x="-10%" y="-80%" width="120%" height="260%"><feGaussianBlur stdDeviation="9"/></filter>' +
            '<linearGradient id="nst-kg-lidc" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#d99a6c"/><stop offset="1" stop-color="#f0ac79"/></linearGradient>' +
            '<filter id="nst-kg-soft" x="-20%" y="-40%" width="140%" height="180%"><feGaussianBlur stdDeviation="5"/></filter></defs>' +
            '<g class="nst-kg__leg is-l"><image href="' + KG + 'leg-l.webp" width="563" height="1206"/></g>' +
            '<g class="nst-kg__leg is-r"><image href="' + KG + 'leg-r.webp" width="563" height="1206"/></g>' +
            '<image class="nst-kg__body" href="' + KG + 'body.webp" width="563" height="1206" mask="url(#nst-kg-nohead)"/>' +
            // His head, buzz cut and all; the drawn head above the jaw is masked away.
            KG_HEAD +
            // The brim's light shadow on his forehead while the cap is on.
            '<path class="nst-kg__capshade" d="' + KG_SHADE + '" filter="url(#nst-kg-shade)"/>' +
            '<g transform="translate(8 5)">' +
            '<g class="nst-kg__lid"><path class="nst-kg__lidc" d="M116 372 Q179 318 242 372 Q179 426 116 372 Z"/><path class="nst-kg__lash" d="M122 382 Q179 402 236 382"/></g>' +
            '<g class="nst-kg__lid"><path class="nst-kg__lidc" d="M306 370 Q369 316 432 370 Q369 424 306 370 Z"/><path class="nst-kg__lash" d="M312 380 Q369 400 426 380"/></g>' +
            '<g class="nst-kg__mouth"><ellipse class="nst-kg__patch" cx="291" cy="541" rx="80" ry="24" filter="url(#nst-kg-soft)"/>' +
                '<g data-m="grr"><rect class="nst-kg__teeth" x="240" y="528" width="100" height="28" rx="10"/><path class="nst-kg__gap" d="M240 542 H340 M260 529 V555 M280 528 V556 M300 528 V556 M320 529 V555"/></g>' +
                '<g data-m="shout"><path class="nst-kg__dark" d="M246 527 Q290 516 334 527 Q342 580 290 584 Q238 580 246 527 Z"/><path class="nst-kg__teeth" d="M251 529 Q290 520 329 529 L328 539 Q290 532 252 539 Z"/><ellipse class="nst-kg__tongue" cx="290" cy="569" rx="25" ry="9"/></g>' +
                '<path data-m="frown" class="nst-kg__line" d="M244 556 Q290 528 336 556"/>' +
                '<path data-m="smirk" class="nst-kg__line" d="M238 544 Q302 560 350 526"/>' +
                '<ellipse data-m="o" class="nst-kg__dark" cx="296" cy="544" rx="14" ry="17"/>' +
                '<ellipse data-m="hum" class="nst-kg__dark" cx="300" cy="545" rx="9" ry="10"/>' +
                '<path data-m="flat" class="nst-kg__line" d="M252 543 H330"/>' +
            '</g>' +
            '</g>' +
            '<g class="nst-kg__arm"><image href="' + KG + 'arm.webp" width="563" height="1206"/>' +
                '<g class="nst-kg__hand"><g class="nst-kg__scratch"><g transform="rotate(14 397 921)">' +
                    '<path d="M375 925 C372 948 372 968 380 980 C386 990 404 992 412 982 C420 970 421 948 419 925 Z"/>' +
                    '<path d="M375 940 C362 944 360 958 370 962 C376 964 380 956 380 950 Z"/>' +
                    '<path class="nst-kg__fingers" d="M386 984 L388 964 M397 987 L398 964 M407 983 L406 964"/>' +
                '</g></g></g>' +
            '</g>' +
            '<g class="nst-kg__cap"><image href="' + KG + 'cap.webp" width="563" height="1206"/></g>' +
            // The same cap from behind, for when it turns backward: mesh on the other side, the strap on his forehead.
            '<g class="nst-kg__capb"><g transform="scale(2.3458)" stroke="#141216" stroke-linejoin="round">' +
                '<path d="M196 44 C214 30 238 28 240 38 C240 52 226 64 212 70 Z" fill="#3a393f" stroke-width="2.5"/>' +
                '<path d="M34 104 L35 72 C36 30 78 6 128 5 C178 6 222 30 222 72 L224 104 Q130 112 34 104 Z" fill="#55545b" stroke-width="3"/>' +
                '<path d="M35 104 L35 72 C36 44 54 24 80 12 C66 40 62 72 66 106 Q48 106 35 104 Z" fill="#2b2a30" stroke-width="2"/>' +
                '<path d="M128 7 C120 40 114 72 112 98 M128 7 C138 40 146 72 148 98" fill="none" stroke="#3a393f" stroke-width="2"/>' +
                '<path d="M104 108 C104 86 114 76 128 76 C142 76 152 86 152 108 Z" fill="#6b4a35" stroke-width="2.5"/>' +
                '<rect x="98" y="91" width="60" height="9" rx="2" fill="#1d1c21" stroke-width="1.5"/>' +
                '<g fill="#8a8890" stroke="none"><circle cx="107" cy="95.5" r="1.8"/><circle cx="115" cy="95.5" r="1.8"/><circle cx="123" cy="95.5" r="1.8"/><circle cx="131" cy="95.5" r="1.8"/><circle cx="139" cy="95.5" r="1.8"/><circle cx="147" cy="95.5" r="1.8"/></g>' +
                '<ellipse cx="128" cy="6" rx="10" ry="4" fill="#3d3c42" stroke-width="2"/>' +
            '</g></g>' +
            '<g class="nst-kg__muffs"><path class="nst-kg__band-o" d="M68 392 C30 -150 556 -150 518 392"/><path class="nst-kg__band" d="M68 392 C30 -150 556 -150 518 392"/>' +
                '<g class="nst-kg__muff"><ellipse cx="72" cy="397" rx="44" ry="58"/><ellipse class="nst-kg__muff-in" cx="76" cy="397" rx="26" ry="38"/></g>' +
                '<g class="nst-kg__muff"><ellipse cx="516" cy="400" rx="44" ry="58"/><ellipse class="nst-kg__muff-in" cx="512" cy="400" rx="26" ry="38"/></g></g>' +
            '<g class="nst-kg__notes"><text x="560" y="300">♪</text><text x="585" y="250">♫</text><text x="548" y="220">♪</text></g>' +
            '<g class="nst-kg__crown"><path d="M196 -34 L214 -104 L252 -60 L287 -122 L322 -60 L360 -104 L378 -34 Z"/><rect x="196" y="-40" width="182" height="22" rx="6"/><circle cx="287" cy="-29" r="7"/></g>' +
            '<g class="nst-kg__sparks"><path d="M0 -26 Q5 -5 26 0 Q5 5 0 26 Q-5 5 -26 0 Q-5 -5 0 -26 Z" transform="translate(30 170)"/><path d="M0 -26 Q5 -5 26 0 Q5 5 0 26 Q-5 5 -26 0 Q-5 -5 0 -26 Z" transform="translate(548 120) scale(.8)"/><path d="M0 -26 Q5 -5 26 0 Q5 5 0 26 Q-5 5 -26 0 Q-5 -5 0 -26 Z" transform="translate(520 520) scale(.6)"/></g>' +
            '<g class="nst-kg__dust"><circle cx="150" cy="1192" r="26"/><circle cx="230" cy="1200" r="20"/><circle cx="420" cy="1196" r="24"/><circle cx="500" cy="1202" r="18"/></g>' +
            '<rect class="nst-kg__hit" data-b="g-cap" x="0" y="-10" width="563" height="232"/>' +
            '<ellipse class="nst-kg__hit" data-b="g-beard" cx="289" cy="602" rx="100" ry="42"/>' +
            '<rect class="nst-kg__hit" data-b="g-foot-l" x="86" y="1088" width="210" height="118"/>' +
            '<rect class="nst-kg__hit" data-b="g-foot-r" x="300" y="1088" width="244" height="118"/>' +
        '</svg>' +
        '<svg class="nst-buddy__vein" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 8 Q8 8 8 3 M12 3 Q12 8 17 8 M17 12 Q12 12 12 17 M8 17 Q8 12 3 12"/></svg>' +
        '<i class="nst-buddy__steam" aria-hidden="true"><b></b><b></b><b></b></i>' +
        '</button>';
    el.appendChild(gEl);

    // His mode, on a chalkboard sidewalk sign at his feet. One face says
    // ON DUTY (a magnifier), the other OFF DUTY (sunglasses); it flips over.
    // His own face, as he looks with the cap on: stern on duty, shades off duty.
    var kgFaceNo = 0;
    function kgFace(mode) {
        var id = 'nst-kgf' + (++kgFaceNo);
        var off = mode === 'off';
        var shades = '<g fill="#15110f"><path d="M108 352 H248 Q252 352 250 360 L240 404 Q236 418 222 418 H136 Q120 418 116 404 L106 360 Q104 352 108 352 Z"/><path d="M300 350 H440 Q444 350 442 358 L432 402 Q428 416 414 416 H328 Q312 416 308 402 L298 358 Q296 350 300 350 Z"/></g>' +
            '<path d="M248 366 Q274 352 300 364" fill="none" stroke="#15110f" stroke-width="12"/><path d="M126 368 L152 368 M318 366 L344 366" fill="none" stroke="rgba(255,255,255,.65)" stroke-width="8" stroke-linecap="round"/>';
        return '<svg class="nst-kg-face is-' + (off ? 'off' : 'on') + '" viewBox="-18 40 610 610" aria-hidden="true" focusable="false">' +
            '<defs><clipPath id="' + id + '"><circle cx="287" cy="345" r="305"/></clipPath><filter id="' + id + 'b" x="-20%" y="-40%" width="140%" height="180%"><feGaussianBlur stdDeviation="6"/></filter>' +
                '<filter id="' + id + 's" x="-10%" y="-80%" width="120%" height="260%"><feGaussianBlur stdDeviation="9"/></filter>' +
                '<mask id="' + id + 'm" maskUnits="userSpaceOnUse" x="-100" y="-200" width="800" height="1500"><rect x="-100" y="-200" width="800" height="1500" fill="#fff"/><path d="' + KG_JAW + '" fill="#000"/></mask></defs>' +
            '<g clip-path="url(#' + id + ')">' +
                '<rect x="-18" y="40" width="610" height="610" fill="' + (off ? '#ebe8e4' : '#ede3f7') + '"/>' +
                '<image href="' + KG + 'body.webp" width="563" height="1206" mask="url(#' + id + 'm)"/>' + KG_HEAD +
                '<path d="' + KG_SHADE + '" fill="#8a4422" opacity=".38" style="mix-blend-mode:multiply" filter="url(#' + id + 's)"/>' +
                '<image href="' + KG + 'cap.webp" width="563" height="1206"/>' +
                '<g transform="translate(8 5)"><ellipse cx="291" cy="541" rx="82" ry="26" fill="#f9bd8c" filter="url(#' + id + 'b)"/>' +
                (off
                    ? '<path d="M238 546 Q300 566 352 530" fill="none" stroke="#3a2418" stroke-width="12" stroke-linecap="round"/>' + shades
                    : '<path d="M250 548 H332" fill="none" stroke="#3a2418" stroke-width="12" stroke-linecap="round"/>') +
            '</g></g></svg>';
    }

    var kgSign = document.createElement('div');
    kgSign.className = 'nst-kg-sign';
    kgSign.hidden = true;
    kgSign.innerHTML =
        '<button type="button" class="nst-kg-sign__board" data-b="kgx-modes" aria-haspopup="true" aria-expanded="false">' +
            '<span class="nst-kg-sign__flip">' +
                '<span class="nst-kg-sign__face is-on"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="M14.5 14.5 20 20"/></svg><b>' + esc(__('ON', 'nibwp')) + '</b><small>' + esc(__('DUTY', 'nibwp')) + '</small></span>' +
                '<span class="nst-kg-sign__face is-off"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h7l-1 4.5a2.5 2.5 0 0 1-2.4 1.9H5.4A2.5 2.5 0 0 1 3 14.4Z"/><path d="M14 10h7v4.4a2.5 2.5 0 0 1-2.4 2H17.4A2.5 2.5 0 0 1 15 14.5Z"/><path d="M10 11.5h4"/></svg><b>' + esc(__('OFF', 'nibwp')) + '</b><small>' + esc(__('DUTY', 'nibwp')) + '</small></span>' +
            '</span>' +
        '</button>' +
        '<i class="nst-kg-sign__legs" aria-hidden="true"></i>' +
        '<div class="nst-kg-modes" role="menu" aria-label="' + esc(__('Kind Guy’s mode', 'nibwp')) + '" hidden>' +
            '<p class="nst-kg-modes__h">' + esc(__('Kind Guy’s mode', 'nibwp')) + '</p>' +
            '<button type="button" role="menuitemradio" class="nst-kg-modes__opt is-on" data-b="kgx-duty-on"><i class="nst-kg-modes__face">' + kgFace('on') + '</i><span><b>' + esc(__('On duty', 'nibwp')) + '</b>' + esc(__('Code inspector, standards controller, adviser, mentor. Reads each request before it goes, inspects every finished task, suggests workflows and fixes.', 'nibwp')) + '</span></button>' +
            '<button type="button" role="menuitemradio" class="nst-kg-modes__opt is-off" data-b="kgx-duty-off"><i class="nst-kg-modes__face">' + kgFace('off') + '</i><span><b>' + esc(__('Off duty', 'nibwp')) + '</b>' + esc(__('Just company: tricks, jokes, Etch stories. Answers when you ask, inspects nothing.', 'nibwp')) + '</span></button>' +
            '<button type="button" class="nst-kg-modes__chat" data-b="kgx-chat">' + esc(__('Chat with Kind Guy', 'nibwp')) + ' →</button>' +
        '</div>';
    gEl.appendChild(kgSign);
    gEl.appendChild(kgSign.querySelector('.nst-kg-modes'));
    var kgClaps = document.createElement('div');
    kgClaps.className = 'nst-kg-claps';
    kgClaps.setAttribute('aria-hidden', 'true');
    kgClaps.innerHTML = '<i>👏</i><i>👏</i><i>👏</i>';
    gEl.appendChild(kgClaps);
    var kgModes = gEl.querySelector('.nst-kg-modes');

    var kgChat = document.createElement('section');
    kgChat.className = 'nst-kgchat';
    kgChat.hidden = true;
    kgChat.setAttribute('aria-label', __('Chat with Kind Guy', 'nibwp'));
    var KG_ICON = {
        comment: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Z"/><circle cx="12" cy="10" r="2.3"/></svg>',
        draw: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/><path d="M14 7l3 3"/></svg>'
    };
    kgChat.innerHTML =
        '<header class="nst-kgchat__head">' +
            '<span class="nst-kgchat__face" aria-hidden="true"></span>' +
            '<span class="nst-kgchat__id"><b>' + esc(__('Kind Guy', 'nibwp')) + '</b><small>' + esc(__('Ask me anything. Work goes to your chat on the left.', 'nibwp')) + '</small></span>' +
            '<button type="button" class="nst-kgchat__x" data-kgchat="close" aria-label="' + esc(__('Close the chat with Kind Guy', 'nibwp')) + '">×</button>' +
        '</header>' +
        '<div class="nst-kgchat__bar">' +
            '<button type="button" class="nst-kgchat__tool" data-kgchat="comment" title="' + esc(__('Point at something on the page (C)', 'nibwp')) + '">' + KG_ICON.comment + '<span>' + esc(__('Comment', 'nibwp')) + '</span></button>' +
            '<button type="button" class="nst-kgchat__tool" data-kgchat="draw" title="' + esc(__('Draw on the page (D)', 'nibwp')) + '">' + KG_ICON.draw + '<span>' + esc(__('Draw', 'nibwp')) + '</span></button>' +
            '<button type="button" class="nst-kgchat__duty" role="switch" aria-checked="true" data-kgchat="duty"><i aria-hidden="true"></i><span></span></button>' +
        '</div>' +
        '<div class="nst-kgchat__log" role="log" aria-live="polite"></div>' +
        '<form class="nst-kgchat__ask"><textarea rows="1" maxlength="1200" placeholder="' + esc(__('Ask Kind Guy…', 'nibwp')) + '" aria-label="' + esc(__('Your message to Kind Guy', 'nibwp')) + '"></textarea>' +
            '<button type="submit" aria-label="' + esc(__('Send to Kind Guy', 'nibwp')) + '">↑</button></form>' +
        '<div class="nst-kgchat__chips">' +
            '<button type="button" data-kgchat="inspect">' + esc(__('Inspect this page', 'nibwp')) + '</button>' +
            '<button type="button" data-kgchat="point">' + esc(__('Point at something', 'nibwp')) + '</button>' +
            '<button type="button" data-kgchat="skill">' + esc(__('Which skill helps?', 'nibwp')) + '</button>' +
            '<button type="button" data-kgchat="bye">' + esc(__('Bye, Kind Guy', 'nibwp')) + '</button>' +
        '</div>';
    // Its own layer, not part of him: selecting text in it never drags him,
    // and it stays put while he walks over to show something.
    host.appendChild(kgChat);
    var kgChatLog = kgChat.querySelector('.nst-kgchat__log');
    var kgChatField = kgChat.querySelector('textarea');

    function kgChatIsOpen() { return !kgChat.hidden && guest === 'on'; }

    function kgChatOpen() {
        if (guest !== 'on') { return; }
        kgxModes(false);
        hush();
        kgChat.querySelector('.nst-kgchat__face').innerHTML = kgFace(duty);
        kgChat.hidden = false;
        kgxDutyUi();
        kgChatPos();
        if (!kgChatLog.children.length) {
            kgxAdd({ who: 'kg', where: 'box', text: __('Go on, ask. WordPress, Etch, ACSS, code, your page. When we agree on a change, I drop it into your chat on the left and your agent does it there.', 'nibwp') });
        }
        setTimeout(function () { kgChatField.focus(); }, 30);
    }

    function kgChatClose() { kgChat.hidden = true; }

    // Beside him: left when there is room, else right; never off screen.
    function kgChatPos() {
        if (kgChat.hidden || kgWalking) { return; }
        var f = gEl.querySelector('.nst-buddy__max').getBoundingClientRect();
        var w = kgChat.offsetWidth || 340;
        var h = kgChat.offsetHeight || 460;
        // Clear of his duty sign, which stands by his feet.
        var x = f.left - w - 48;
        if (x < 8) { x = Math.min(f.right + 14, window.innerWidth - w - 8); }
        kgChat.style.left = Math.max(8, x) + 'px';
        kgChat.style.top = Math.max(8, Math.min(f.bottom - h - 6, window.innerHeight - h - 8)) + 'px';
    }

    // A message that asks for work: his quick take here, or straight to the agent.
    function kgChoice(q) {
        kgxAdd({ who: 'you', where: 'box', text: q });
        kgxAdd({
            who: 'kg', where: 'box',
            text: __('Sounds like a job. Want my quick take here, or shall I pass it to your agent to do it properly?', 'nibwp'),
            actions: [
                { label: __('Get answer here', 'nibwp'), run: function () { kgxAsk(q, 'max-chat', '', null, 'box', true); } },
                { label: __('Pass it to the agent', 'nibwp'), request: q }
            ]
        });
    }

    kgChat.querySelector('.nst-kgchat__ask').addEventListener('submit', function (e) {
        e.preventDefault();
        var q = kgChatField.value.trim();
        if (!q) { return; }
        kgChatField.value = '';
        kgChatField.style.height = '';
        if (kgMad(q)) { return; }
        if (isTask(q)) { kgChoice(q); return; }
        kgxAsk(q, 'max-chat', '', null, 'box');
    });
    kgChatField.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            kgChat.querySelector('.nst-kgchat__ask').requestSubmit();
        }
    });
    kgChatField.addEventListener('input', function () {
        kgChatField.style.height = '';
        kgChatField.style.height = Math.min(110, kgChatField.scrollHeight) + 'px';
    });
    kgChat.addEventListener('click', function (e) {
        var b = e.target.closest('[data-kgchat]');
        if (!b) { return; }
        var what = b.dataset.kgchat;
        if (what === 'close') { kgChatClose(); }
        if (what === 'comment' || what === 'draw') { var t = document.getElementById('nst-' + what); if (t) { t.click(); } }
        if (what === 'duty') {
            setDuty(duty === 'on' ? 'off' : 'on', true);
            kgxAdd({ who: 'kg', where: 'box', text: pick(duty === 'on' ? KGX_ON : KGX_OFF) });
        }
        if (what === 'inspect') { kgxInspect(); }
        if (what === 'point') { hush(); kgPick(); }
        if (what === 'skill') { kgxSkill(); }
        if (what === 'bye') { leaveMax(); }
    });

    // His own spot, unless you moved him: the bottom right corner.
    function kgCorner() {
        return { x: window.innerWidth - 112 - Math.round(window.innerWidth * 0.03), y: window.innerHeight - 240 - 6 };
    }

    function kgPlace(x, y) {
        x = Math.max(4, Math.min(x, window.innerWidth - 112 - 4));
        y = Math.max(4, Math.min(y, window.innerHeight - 240 - 4));
        gEl.style.position = 'fixed';
        gEl.style.left = x + 'px';
        gEl.style.top = y + 'px';
        gEl.style.right = 'auto';
        gEl.style.bottom = 'auto';
        aim();
        kgChatPos();
        return { x: x, y: y };
    }
    var KG_DROP = [
        __('Put me down! … Thank you. Never do that again.', 'nibwp'),
        __('I am not a sticker.', 'nibwp'),
        __('Fine. I’ll work from here. Happy?', 'nibwp')
    ];
    gEl.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || guest !== 'on') { return; }
        var btn = gEl.querySelector('.nst-buddy__max');
        var r = gEl.getBoundingClientRect();
        var ox = e.clientX - r.left;
        var oy = e.clientY - r.top;
        var sx = e.clientX;
        var sy = e.clientY;
        var moved = false;
        var at = null;
        var move = function (ev) {
            if (!moved && Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 6) { return; }
            if (!moved) {
                moved = true;
                kgHome = null;
                kgWalking = false;
                gEl.classList.remove('is-walking');
                try { btn.setPointerCapture(ev.pointerId); } catch (err) { /* it still follows while over him */ }
                gEl.classList.add('is-dragging');
                hush();
                gEl.dataset.mouth = 'shout';
            }
            at = kgPlace(ev.clientX - ox, ev.clientY - oy);
        };
        var up = function () {
            gEl.removeEventListener('pointermove', move);
            gEl.removeEventListener('pointerup', up);
            gEl.removeEventListener('pointercancel', up);
            if (!moved) { return; }
            gEl.classList.remove('is-dragging');
            delete gEl.dataset.mouth;
            if (at) {
                mem.kgPos = { x: at.x / window.innerWidth, y: at.y / window.innerHeight };
                save();
            }
            gEl.dataset.dragged = '1';
            setTimeout(function () { delete gEl.dataset.dragged; }, 60);
            maxSay(pick(KG_DROP), [], { act: 'stomp', stay: 4500 });
        };
        gEl.addEventListener('pointermove', move);
        gEl.addEventListener('pointerup', up);
        gEl.addEventListener('pointercancel', up);
    });
    if (!document.getElementById('nst-kg-css')) {
        var kgCss = document.createElement('style');
        kgCss.id = 'nst-kg-css';
        kgCss.textContent = [
            '.nst-kg{display:block;width:112px;height:240px;overflow:visible}',
            '.nst-kg image{pointer-events:none}',
            '.nst-kg__capshade{fill:#8a4422;mix-blend-mode:multiply;opacity:.38;transition:opacity .3s}',
            '.nst-buddy__guest[data-cap="back"] .nst-kg__capshade{opacity:0}',
            '.nst-buddy__guest[data-act="hat"] .nst-kg__capshade{animation:nst-kg-capshade 2.4s linear}',
            '@keyframes nst-kg-capshade{0%{opacity:.38}7%,78%{opacity:0}86%,100%{opacity:.38}}',
            '.nst-kg__lid{transform-box:fill-box;transform-origin:50% 0;transform:scaleY(0);transition:transform .11s ease-in}',
            '.nst-kg__lidc{fill:url(#nst-kg-lidc)}',
            '.nst-kg__lash{fill:none;stroke:#2b1d14;stroke-width:5;stroke-linecap:round}',
            '.nst-buddy__guest.is-blink .nst-kg__lid,.nst-buddy__guest[data-deaf]:not(.is-talking) .nst-kg__lid{transform:scaleY(1)}',
            '.nst-kg__mouth>*{opacity:0}',
            '.nst-kg__patch{fill:#f9bd8c}',
            '.nst-kg__dark{fill:#3a1c16}',
            '.nst-kg__teeth{fill:#fff;stroke:#2b1d14;stroke-width:4}',
            '.nst-kg__gap{fill:none;stroke:#2b1d14;stroke-width:3}',
            '.nst-kg__tongue{fill:#e06b75}',
            '.nst-kg__line{fill:none;stroke:#3a2418;stroke-width:6;stroke-linecap:round}',
            '.nst-buddy__guest[data-mouth] .nst-kg__patch,.nst-buddy__guest[data-deaf] .nst-kg__patch{opacity:1}',
            ['grr', 'shout', 'frown', 'smirk', 'o', 'flat'].map(function (m) { return '.nst-buddy__guest[data-mouth="' + m + '"] [data-m="' + m + '"]'; }).join(',') + ',.nst-buddy__guest[data-mouth="chew"] [data-m="flat"],.nst-buddy__guest[data-deaf]:not([data-mouth]) [data-m="hum"]{opacity:1}',
            '.nst-buddy__guest[data-mouth="chew"] [data-m="flat"]{animation:nst-kg-chew .45s ease-in-out infinite alternate}',
            '.nst-buddy__guest[data-deaf]:not([data-mouth]) [data-m="hum"]{transform-box:fill-box;transform-origin:50% 50%;animation:nst-kg-hum .9s ease-in-out infinite alternate}',
            '.nst-kg__arm{transform-box:view-box;transform-origin:432px 782px}',
            '.nst-kg__hand{opacity:0}',
            '.nst-kg__hand path{fill:#f6b985;stroke:#20160f;stroke-width:6;stroke-linejoin:round}',
            '.nst-kg__hand .nst-kg__fingers{fill:none;stroke-width:4;stroke-linecap:round}',
            '.nst-kg__scratch{transform-box:view-box;transform-origin:397px 921px}',
            '.nst-buddy__guest[data-act="scratch"] .nst-kg__arm{animation:nst-kg-raise 2.8s ease-in-out}',
            '.nst-buddy__guest[data-act="scratch"] .nst-kg__hand,.nst-buddy__guest[data-act="explain"] .nst-kg__hand{animation:nst-kg-hand 2.8s linear}',
            '.nst-buddy__guest[data-act="scratch"] .nst-kg__scratch{animation:nst-kg-scratch .24s ease-in-out .9s 8 alternate}',
            '.nst-buddy__guest[data-act="explain"] .nst-kg__arm{animation:nst-kg-explain 2.6s ease-in-out}',
            '.nst-kg__leg{transform-box:view-box}',
            '.nst-kg__leg.is-l{transform-origin:236px 1016px}',
            '.nst-kg__leg.is-r{transform-origin:375px 1016px}',
            '.nst-buddy__max{cursor:grab}',
            '.nst-buddy__guest.is-dragging .nst-buddy__max{cursor:grabbing;transform-origin:50% 8%;animation:nst-kg-dangle .55s ease-in-out infinite alternate}',
            '.nst-buddy__guest[data-act="in"] .nst-buddy__max{transform-origin:50% 100%;animation:nst-kg-walk-in var(--kg-in-t,1.9s) linear}',
            '.nst-buddy__guest[data-act="out"] .nst-buddy__max{transform-origin:50% 100%;animation:nst-kg-walk-out 1.4s linear forwards}',
            '.nst-buddy__guest[data-act="in"] .nst-kg__leg.is-l{animation:nst-kg-stride .7s ease-in-out infinite}',
            '.nst-buddy__guest[data-act="in"] .nst-kg__leg.is-r{animation:nst-kg-stride .7s ease-in-out infinite reverse}',
            '.nst-buddy__guest[data-act="out"] .nst-kg__leg.is-l,.nst-buddy__guest.is-dragging .nst-kg__leg.is-l{animation:nst-kg-step .35s ease-in-out infinite alternate}',
            '.nst-buddy__guest[data-act="out"] .nst-kg__leg.is-r,.nst-buddy__guest.is-dragging .nst-kg__leg.is-r{animation:nst-kg-step .35s ease-in-out infinite alternate-reverse}',
            '.nst-kg__dust circle{fill:#d9d2c7;opacity:0;transform-box:fill-box;transform-origin:50% 50%}',
            '.nst-buddy__guest[data-act="in"] .nst-kg__dust circle{animation:nst-kg-dust .7s ease-out 1.3s}',
            '.nst-buddy__guest[data-act="in"] .nst-kg__dust circle:nth-child(2n){animation-delay:1.4s}',
            '.nst-kg__crown{opacity:0;transform-box:view-box;transform-origin:287px -40px}',
            '.nst-kg__crown path,.nst-kg__crown rect{fill:#f5c542;stroke:#20160f;stroke-width:7;stroke-linejoin:round}',
            '.nst-kg__crown circle{fill:#e2582a;stroke:#20160f;stroke-width:4}',
            '.nst-kg__sparks path{fill:#ffd54a;stroke:#b8860b;stroke-width:2;opacity:0;transform-box:fill-box;transform-origin:50% 50%}',
            '.nst-buddy__guest[data-act="arrive"] .nst-kg__sparks path,.nst-buddy__guest[data-act="brag"] .nst-kg__sparks path{animation:nst-kg-twinkle 1.1s ease-in-out 2}',
            '.nst-buddy__guest[data-act="arrive"] .nst-kg__sparks path:nth-child(2),.nst-buddy__guest[data-act="brag"] .nst-kg__sparks path:nth-child(2){animation-delay:.3s}',
            '.nst-buddy__guest[data-act="arrive"] .nst-kg__sparks path:nth-child(3),.nst-buddy__guest[data-act="brag"] .nst-kg__sparks path:nth-child(3){animation-delay:.6s}',
            '.nst-buddy__guest[data-act="arrive"] .nst-kg__cap{animation:nst-kg-captip 1s ease-in-out}',
            '.nst-buddy__guest[data-act="brag"] .nst-buddy__max{transform-origin:50% 100%;animation:nst-kg-brag 2.6s ease-in-out}',
            '.nst-buddy__guest[data-act="brag"] .nst-kg__arm{animation:nst-kg-chest 2.6s ease-in-out}',
            '.nst-buddy__guest[data-act="brag"] .nst-kg__hand{animation:nst-kg-hand 2.6s linear}',
            '.nst-buddy__guest[data-act="brag"] .nst-kg__crown{animation:nst-kg-crown 2.6s cubic-bezier(.3,1.4,.5,1)}',
            '.nst-buddy__guest[data-act="spin"] .nst-buddy__max{transform-origin:50% 58%;animation:nst-kg-backflip 1.4s linear}',
            '.nst-buddy__guest[data-act="smug"] .nst-buddy__max{transform-origin:50% 100%;animation:nst-kg-smug 1.8s ease-in-out}',
            '.nst-buddy__guest[data-act="eyeroll"] .nst-buddy__max{transform-origin:50% 100%;animation:nst-kg-tilt 1.5s ease-in-out}',
            '.nst-buddy__guest[data-act="eyeroll"] .nst-kg__lid,.nst-buddy__guest[data-act="smug"] .nst-kg__lid{transform:scaleY(.55)}',
            '.nst-buddy__guest[data-leg="l"] .nst-kg__leg.is-l,.nst-buddy__guest[data-leg="r"] .nst-kg__leg.is-r{animation:nst-kg-kick .9s ease-out}',
            '.nst-buddy__guest[data-act="in"] .nst-kg__arm,.nst-buddy__guest[data-act="out"] .nst-kg__arm{animation:nst-kg-swing .35s ease-in-out infinite alternate}',
            '.nst-buddy__guest[data-act="hat"] .nst-buddy__vein{opacity:1;transform:none}',
            '.nst-buddy__guest[data-act="hat"] .nst-buddy__steam b{animation:nst-max-steam 1.3s ease-out .15s}',
            '.nst-buddy__guest[data-act="hat"] .nst-buddy__steam b:nth-child(2){animation-delay:.3s}',
            '.nst-buddy__guest[data-act="hat"] .nst-buddy__steam b:nth-child(3){animation-delay:.45s}',
            '.nst-kg__cap{transform-box:view-box;transform-origin:287px 160px}',
            '.nst-kg__cap{transition:transform .26s ease-out .26s}',
            '.nst-kg__capb{transform-box:view-box;transform-origin:300px 160px;transform:scaleX(0);transition:transform .26s ease-in}',
            '.nst-buddy__guest[data-cap="back"] .nst-kg__cap{transform:scaleX(0);transition:transform .26s ease-in}',
            '.nst-buddy__guest[data-cap="back"] .nst-kg__capb{transform:none;transition:transform .26s ease-out .26s}',
            '.nst-buddy__guest[data-act="hat"] .nst-kg__cap{animation:nst-kg-hat 2.4s cubic-bezier(.3,.7,.3,1)}',
            '.nst-kg__muffs{opacity:0;transform:translateY(-130px);transition:transform .6s cubic-bezier(.3,1.5,.5,1),opacity .3s}',
            '.nst-buddy__guest[data-deaf] .nst-kg__muffs{opacity:1;transform:none}',
            '.nst-kg__band-o{fill:none;stroke:#20160f;stroke-width:26;stroke-linecap:round}',
            '.nst-kg__band{fill:none;stroke:#e2582a;stroke-width:16;stroke-linecap:round}',
            '.nst-kg__muff ellipse{fill:#fbf6ee;stroke:#20160f;stroke-width:6}',
            '.nst-kg__muff .nst-kg__muff-in{fill:#f0e2d0;stroke:none}',
            '.nst-kg__notes text{font:700 64px/1 system-ui,sans-serif;fill:#3a2418;opacity:0}',
            '.nst-buddy__guest[data-deaf] .nst-kg__notes text{animation:nst-kg-note 2.4s ease-out infinite}',
            '.nst-buddy__guest[data-deaf] .nst-kg__notes text:nth-child(2){animation-delay:.8s}',
            '.nst-buddy__guest[data-deaf] .nst-kg__notes text:nth-child(3){animation-delay:1.6s}',
            '.nst-kg__hit{fill:transparent;pointer-events:all;cursor:pointer}',
            '@keyframes nst-kg-raise{0%{transform:rotate(0)}6%{transform:rotate(-5deg)}26%{transform:rotate(146deg)}33%{transform:rotate(136deg)}40%{transform:rotate(140deg)}78%{transform:rotate(139deg)}94%{transform:rotate(-4deg)}100%{transform:rotate(0)}}',
            '@keyframes nst-kg-explain{0%{transform:rotate(0)}20%{transform:rotate(88deg)}35%{transform:rotate(74deg)}50%{transform:rotate(92deg)}65%{transform:rotate(76deg)}82%{transform:rotate(84deg)}100%{transform:rotate(0)}}',
            '@keyframes nst-kg-hand{0%,10%{opacity:0}22%,84%{opacity:1}94%,100%{opacity:0}}',
            '@keyframes nst-kg-scratch{from{transform:rotate(-9deg)}to{transform:rotate(9deg) translateY(-6px)}}',
            '@keyframes nst-kg-step{from{transform:rotate(16deg)}to{transform:rotate(-16deg)}}',
            '@keyframes nst-kg-smug{0%{transform:none}20%{transform:translateY(-3px) rotate(-5deg) scale(1.03)}80%{transform:translateY(-3px) rotate(-5deg) scale(1.03)}100%{transform:none}}',
            '@keyframes nst-kg-tilt{0%{transform:none}25%{transform:rotate(3deg)}50%{transform:rotate(-2deg)}75%{transform:rotate(2deg)}100%{transform:none}}',
            '@keyframes nst-kg-stride{0%{transform:rotate(0)}25%{transform:rotate(17deg)}75%{transform:rotate(-17deg)}100%{transform:rotate(0)}}',
            '@keyframes nst-kg-dust{0%{opacity:0;transform:translate(0,0) scale(.3)}25%{opacity:.85}100%{opacity:0;transform:translate(-40px,-24px) scale(1.6)}}',
            '@keyframes nst-kg-twinkle{0%,100%{opacity:0;transform:scale(.2) rotate(0)}50%{opacity:1;transform:scale(1.15) rotate(45deg)}}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg__arm{animation:nst-kg-clap 2.6s ease-in-out}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg__sparks path{animation:nst-kg-twinkle 1.1s ease-in-out 2}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg__sparks path:nth-child(2){animation-delay:.3s}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg__sparks path:nth-child(3){animation-delay:.6s}',
            '@keyframes nst-kg-clap{0%{transform:rotate(0)}14%{transform:rotate(110deg)}24%{transform:rotate(128deg)}34%{transform:rotate(108deg)}44%{transform:rotate(128deg)}54%{transform:rotate(108deg)}64%{transform:rotate(128deg)}76%{transform:rotate(112deg)}100%{transform:rotate(0)}}',
            '.nst-kg-claps{position:absolute;top:24%;left:50%;width:0;height:0;pointer-events:none}',
            '.nst-kg-claps i{position:absolute;left:-10px;font-size:20px;font-style:normal;opacity:0}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg-claps i{animation:nst-kg-clap-pop 1.6s ease-out forwards}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg-claps i:nth-child(2){left:-44px;animation-delay:.4s}',
            '.nst-buddy__guest[data-act="clap"] .nst-kg-claps i:nth-child(3){left:22px;animation-delay:.8s}',
            '@keyframes nst-kg-clap-pop{0%{opacity:0;transform:translateY(10px) scale(.5)}25%{opacity:1;transform:translateY(-8px) scale(1.15)}100%{opacity:0;transform:translateY(-64px) scale(.9)}}',
            '@media (prefers-reduced-motion:reduce){.nst-buddy__guest[data-act="clap"] .nst-kg__arm,.nst-buddy__guest[data-act="clap"] .nst-kg-claps i{animation:none}}',
            '@keyframes nst-kg-captip{0%{transform:none}30%{transform:translateY(-16px) rotate(-10deg)}62%{transform:translateY(-4px) rotate(4deg)}100%{transform:none}}',
            '@keyframes nst-kg-brag{0%{transform:none}16%{transform:translateY(-6px) rotate(-4deg)}84%{transform:translateY(-6px) rotate(-4deg)}100%{transform:none}}',
            '@keyframes nst-kg-chest{0%{transform:rotate(0)}18%{transform:rotate(86deg)}30%{transform:rotate(79deg)}40%{transform:rotate(88deg)}52%{transform:rotate(79deg)}62%{transform:rotate(86deg)}84%{transform:rotate(84deg)}100%{transform:rotate(0)}}',
            '@keyframes nst-kg-crown{0%{opacity:0;transform:translateY(40px) scale(.3)}18%{opacity:1;transform:translateY(-6px) scale(1.12)}28%{transform:none}80%{opacity:1;transform:none}100%{opacity:0;transform:translateY(-30px) scale(.9)}}',
            '@keyframes nst-kg-backflip{0%{transform:perspective(700px) translateY(0px) rotateX(0deg) scale(1,1)}12%{transform:perspective(700px) translateY(10px) rotateX(0deg) scale(1.13,0.8)}22%{transform:perspective(700px) translateY(-37px) rotateX(0deg) scale(0.9,1.15)}34%{transform:perspective(700px) translateY(-98px) rotateX(110deg) scale(0.94,0.94)}48%{transform:perspective(700px) translateY(-122px) rotateX(200deg) scale(0.9,0.9)}62%{transform:perspective(700px) translateY(-93px) rotateX(290deg) scale(0.94,0.94)}74%{transform:perspective(700px) translateY(-21px) rotateX(350deg) scale(0.95,1.08)}82%{transform:perspective(700px) translateY(6px) rotateX(360deg) scale(1.16,0.82)}91%{transform:perspective(700px) translateY(-9px) rotateX(360deg) scale(0.97,1.04)}100%{transform:perspective(700px) translateY(0px) rotateX(360deg) scale(1,1)}}',
            '@keyframes nst-kg-swing{from{transform:rotate(-12deg)}to{transform:rotate(12deg)}}',
            '@keyframes nst-kg-kick{0%{transform:none}18%{transform:translateY(-34px) rotate(-14deg)}32%{transform:translateY(-30px) rotate(10deg)}46%{transform:translateY(-34px) rotate(-10deg)}60%{transform:translateY(-26px) rotate(6deg)}82%{transform:none}100%{transform:none}}',
            '@keyframes nst-kg-dangle{from{transform:rotate(-7deg)}to{transform:rotate(7deg)}}',
            '@keyframes nst-kg-walk-in{0%{transform:translateX(var(--kg-in,280px)) rotate(-2deg)}18%{transform:translateX(calc(var(--kg-in,280px)*.82)) translateY(-8px) rotate(2deg)}36%{transform:translateX(calc(var(--kg-in,280px)*.62)) rotate(-2deg)}54%{transform:translateX(calc(var(--kg-in,280px)*.42)) translateY(-8px) rotate(2deg)}72%{transform:translateX(calc(var(--kg-in,280px)*.2)) rotate(-2deg)}88%{transform:translateY(-4px) rotate(1deg)}100%{transform:none}}',
            '@keyframes nst-kg-walk-out{0%{transform:none}12%{transform:perspective(600px) rotateY(30deg)}30%{transform:perspective(600px) translateX(55px) translateY(-6px) rotateY(38deg)}50%{transform:perspective(600px) translateX(115px) rotateY(38deg)}70%{opacity:1;transform:perspective(600px) translateX(175px) translateY(-6px) rotateY(38deg)}100%{opacity:0;transform:perspective(600px) translateX(270px) rotateY(38deg)}}',
            '@keyframes nst-kg-hat{0%{transform:none}16%{transform:translateY(-150px) rotate(-12deg)}32%{transform:translateY(-172px) rotate(9deg)}60%{transform:translateY(-162px) rotate(-5deg)}84%{transform:translateY(0) rotate(0)}90%{transform:translateY(-10px)}100%{transform:none}}',
            '@keyframes nst-kg-chew{from{transform:translateX(-10px)}to{transform:translateX(10px)}}',
            '@keyframes nst-kg-hum{from{transform:scale(.8)}to{transform:scale(1.15)}}',
            '@keyframes nst-kg-note{0%{opacity:0;transform:translate(0,30px)}20%{opacity:1}100%{opacity:0;transform:translate(26px,-80px)}}',
            '@media (prefers-reduced-motion:reduce){.nst-kg *{animation:none!important;transition:none!important}}'
        ].join('');
        document.head.appendChild(kgCss);
    }

    var MAX_BEST = __('I am the best at Etch and ACSS. Who can beat me? Nobody. I am the best.', 'nibwp');
    var MAX_BEST_CODE = __('PHP, HTML, CSS, JavaScript: I write it all, and I write it right. Who can beat me? Nobody. I am the best.', 'nibwp');
    var MAX_BRAG = [
        MAX_BEST,
        __('Second place? Never heard of it.', 'nibwp'),
        __('Who knows Etch and ACSS better than the guy who made them? Exactly. Nobody.', 'nibwp'),
        __('I don’t make mistakes. I make components.', 'nibwp'),
        __('Fastest Etch build you will ever see. Blink and you miss it.', 'nibwp'),
        __('Some people use Etch. I am Etch’s favourite person.', 'nibwp'),
        __('Pressure? I don’t feel pressure. Pressure feels me.', 'nibwp')
    ];
    var MAX_POKE = [
        __('What now?', 'nibwp'),
        __('You clicked me. Bold move.', 'nibwp'),
        __('Make it quick. I have tokens to align.', 'nibwp'),
        __('Need the best? You found him.', 'nibwp'),
        __('Autographs after the build.', 'nibwp'),
        __('You again? Fine. Talk. Fast.', 'nibwp'),
        __('I’m busy being excellent. What is it?', 'nibwp')
    ];
    var MAX_JOKES = [
        __('!important? Do you also shout at your coffee machine?', 'nibwp'),
        __('!important is CSS for “I gave up.” I never give up.', 'nibwp'),
        __('Two CSS properties walk into a bar. A barstool in a different bar falls over.', 'nibwp'),
        __('Why was the div sad? It had no class. BEM fixes that. You’re welcome.', 'nibwp'),
        __('z-index: 9999? Bold. Let me guess, 99999 next week.', 'nibwp'),
        __('I told a joke about position: absolute. Nobody knew where it was coming from.', 'nibwp'),
        __('I don’t float. I flex. And when I’m serious, I grid.', 'nibwp'),
        __('Centring a div used to be hard. Then I showed up.', 'nibwp'),
        __('display: none. That is my patience for inline styles.', 'nibwp'),
        __('Margins collapse. My standards don’t.', 'nibwp'),
        __('Specificity wars? I don’t fight them. I write clean classes and win.', 'nibwp'),
        __('A div inside a div inside a div. Somewhere, a token cries.', 'nibwp'),
        __('I don’t have a temper. I have standards.', 'nibwp')
    ];
    var MAX_OTHER = [
        __('Did you just say another builder’s name in front of me? I’ll pretend I didn’t hear that.', 'nibwp'),
        __('Oh, that one. Sure. And I’ll write my HTML with a crayon.', 'nibwp'),
        __('Interesting. Wrong, but interesting.', 'nibwp')
    ];
    var MAX_SENT = [
        __('Fine. Sent. Now don’t touch anything.', 'nibwp'),
        __('Your agent has it. If it goes wrong, I’m blaming you.', 'nibwp'),
        __('Sent. Clean HTML is on its way. You’re welcome in advance.', 'nibwp')
    ];
    var MAX_DONE = [
        __('Done. Flawless, obviously.', 'nibwp'),
        __('Finished. Check it. Then thank me. Mostly the second part.', 'nibwp')
    ];
    var MAX_BYE = [
        __('Call me when you’re doing real Etch work.', 'nibwp'),
        __('I’m out. Don’t let Niby touch the CSS.', 'nibwp')
    ];
    var MAX_PEEK = [
        __('I can see you, Niby.', 'nibwp'),
        __('Niby, stop peeking. It’s embarrassing.', 'nibwp')
    ];

    var tidy = function (t) {
        t = String(t || '').trim().replace(/[.\s]+$/, '');
        t = t.length > 150 ? t.slice(0, t.lastIndexOf(' ', 150)) + '…' : t;
        return /^[A-Z][a-z]/.test(t) ? t.charAt(0).toLowerCase() + t.slice(1) : t;
    };
    var etchSkills = (boot.skills || []).filter(function (k) { return k.ready && k.tagline && ETCH.test(k.name + ' ' + (k.id || '')); });
    var etchFlows = (boot.workflows || []).filter(function (w) { return w.title && ETCH.test(w.title + ' ' + (w.summary || '')); });
    var skillFor = function (re) { return etchSkills.find(function (k) { return re.test(k.name + ' ' + (k.id || '')); }); };

    // The tour: what this NibWP really has (its Etch and ACSS skills and
    // workflows), between the pro tips. Facts from NibWP's Etch and ACSS
    // skill guides, so he is never wrong, only rude.
    var KG_TIPS = [
        { text: __('Etch is not static HTML. Four pillars: components, loops, conditions, dynamic data. If an editor would ever change it without opening the builder, it is a prop, a loop item, a condition or a binding. Not a literal.', 'nibwp') },
        { text: __('Repeating cards, tiers, tabs? One Etch component with properties, used everywhere. Copy-pasted markup is how weekends die.', 'nibwp') },
        { text: __('Six or more identical siblings, or content from posts, products or ACF rows? That is a loop. Not six boxes made by hand.', 'nibwp') },
        { text: __('Hiding something with CSS? No. Use an Etch condition and do not render it at all. Dead markup is still markup.', 'nibwp') },
        { text: __('Bind real data, then shape it with modifiers: fallbacks, truncation, dates, maths. Don’t type what the database already knows.', 'nibwp') },
        { text: __('Every value that maps to ACSS gets the token and a fallback: var(--text-l, 1.125rem). A bare var() with no fallback? Amateur hour.', 'nibwp') },
        { text: __('Headings: var(--h1), var(--h2), var(--h3). Body: var(--text-m). Never a hand-written clamp() for font sizes. ACSS already does the fluid maths, better than you.', 'nibwp') },
        { text: __('Sections breathe with var(--section-space-m); heroes and calls to action get --section-space-l. Side padding is var(--gutter). Stop inventing margins.', 'nibwp') },
        { text: __('Grids use var(--grid-gap), stacked content uses var(--content-gap). A margin on every child? I can hear it from here.', 'nibwp') },
        { text: __('Surfaces decide the token. --border-color-light is for dark sections; on a white card it is invisible. So is your border.', 'nibwp') },
        { text: __('Class names are BEM: brand-card__title--featured. Not .big-red-text. I mean it.', 'nibwp') },
        { text: __('Every element gets a class. A tree of bare tags cannot be styled or targeted later. Future you will cry.', 'nibwp') },
        { text: __('Responsive is not optional. Container queries when a component cares about its own width, media queries for the viewport.', 'nibwp') },
        { text: __('Focus ring: var(--focus-width) solid var(--focus-color). Keyboard users exist. Respect them.', 'nibwp') },
        { text: __('No style tags, no @import, no stylesheet links inside a page. Styles live in Etch’s styles, wired to classes.', 'nibwp') },
        { text: __('Pick one type scale and stay with it: major third 1.25, perfect fourth 1.333. Mixing scales is chaos with extra steps.', 'nibwp') },
        { text: __('Modern CSS does the heavy lifting: gap, :has(), container queries, logical properties like margin-inline. Hacks are for 2012.', 'nibwp') },
        { text: __('Animations go inside prefers-reduced-motion. Not everyone wants your hero spinning.', 'nibwp') },
        { text: __('PHP: sanitise what comes in, escape what goes out. esc_html, esc_attr, esc_url, on every echo, every time.', 'nibwp') },
        { text: __('JavaScript that blocks nothing: defer it, and listen once on the parent instead of on fifty buttons.', 'nibwp') },
        { text: __('One h1 a page, headings in order, a button for actions and a link to go somewhere. Good HTML is free accessibility.', 'nibwp') },
        { text: __('A database query inside a loop is a slow site waiting to happen. Fetch once, then loop.', 'nibwp') }
    ];
    var STORY = (function () {
        var own = [
            { text: sprintf(__('Listen up. Open a page in the Etch editor right here, press %s and click on the canvas. I tell your agent exactly which element. No guessing.', 'nibwp'), KEY_C), act: [__('Show me', 'nibwp'), 'comment'] },
            { text: __('Press D, draw a box around a section and say “make this a component”. Reusable, editable, done. Copy and paste is for amateurs.', 'nibwp'), act: [__('Draw', 'nibwp'), 'draw'] },
            { text: __('Phone view, up top. Pin notes in it and your agent fixes the phone layout, not the desktop one. Basic stuff, people.', 'nibwp') }
        ];
        etchSkills.forEach(function (k) {
            /* translators: 1: a NibWP skill, e.g. "EtchWP Pro", 2: what it does */
            own.push({ text: sprintf(__('With %1$s, NibWP can %2$s. Yes, I’m impressed too. Don’t tell Niby.', 'nibwp'), k.name, tidy(k.tagline)), act: [__('Try it', 'nibwp'), 'find:' + k.name] });
        });
        etchFlows.slice(0, 3).forEach(function (w) {
            /* translators: 1: a workflow title, 2: what it does */
            own.push({ text: sprintf(__('There is a ready workflow for “%1$s”: %2$s. Press / and type its name. Or don’t. I’m not your mom.', 'nibwp'), w.title, tidy(w.summary)), act: [__('Try it', 'nibwp'), 'find:' + w.title] });
        });
        var out = [];
        for (var i = 0; i < Math.max(own.length, KG_TIPS.length); i++) {
            if (own[i]) { out.push(own[i]); }
            if (KG_TIPS[i]) { out.push(KG_TIPS[i]); }
        }
        return out;
    }());

    // What Kind Guy knows by heart; the agent may add to it.
    var MAX_TEXT = 'Kind Guy is NibWP’s grumpy senior front-end developer and its expert on CSS, Etch and ACSS. He knows: Etch’s four pillars (components with properties, loops for 6+ siblings or query data, conditions instead of hiding with CSS, dynamic data with modifiers); ACSS tokens always with a fallback, e.g. var(--h1, 2.5rem), var(--text-m, 1rem), var(--section-space-m, 5rem), var(--gutter, 1rem), var(--grid-gap, 1.5rem), var(--content-gap, 2rem), var(--primary), focus tokens; never clamp() for font sizes; surfaces decide border and text tokens; BEM class names, a class on every element; no style tags or @import in a page; container queries or media queries for responsive; one type scale; modern CSS (gap, :has(), logical properties); reduced motion. In Agent View, comments and drawing work on the Etch editor canvas (C, D).' +
        (etchSkills.length ? ' Skills here: ' + etchSkills.map(function (k) { return k.name + ' (' + k.tagline + ')'; }).join('; ') + '.' : '') +
        (etchFlows.length ? ' Workflows here: ' + etchFlows.map(function (w) { return w.title; }).join('; ') + '.' : '');

    var tipOn = function (re) { var t = KG_TIPS.find(function (x) { return re.test(x.text); }); return t ? t.text : null; };
    function maxAnswer(q) {
        var etchSkill = skillFor(/etch/i);
        var acssSkill = skillFor(/acss|automatic/i);
        if (/\bloop|repeat|query|posts|products|cpt|acf|repeater/i.test(q)) { return tipOn(/That is a loop/); }
        if (/condition|hide|hidden|visib|show only/i.test(q)) { return tipOn(/Etch condition/); }
        if (/dynamic|bind|modifier|field|database/i.test(q)) { return tipOn(/modifiers/); }
        if (/\bclass|bem|naming|selector/i.test(q)) { return tipOn(/BEM/); }
        if (/responsive|mobile|breakpoint|container quer|media quer/i.test(q)) { return tipOn(/Container queries/); }
        if (/heading|h1|h2|h3|font|text size|typograph|type scale|clamp/i.test(q)) { return tipOn(/Headings:/); }
        if (/section|padding|gutter/i.test(q)) { return tipOn(/section-space-m/); }
        if (/\bgap|margin|spacing|space/i.test(q)) { return tipOn(/grid-gap/); }
        if (/border|surface|dark section|contrast/i.test(q)) { return tipOn(/Surfaces decide/); }
        if (/focus|accessib|a11y|keyboard/i.test(q)) { return tipOn(/Focus ring/); }
        if (/style tag|<style|@import|stylesheet/i.test(q)) { return tipOn(/No style tags/); }
        if (/animat|motion|transition/i.test(q)) { return tipOn(/reduced-motion/); }
        if (/token|variable|var\(|colou?r|fallback/i.test(q)) { return tipOn(/token and a fallback/); }
        if (/component|reus/i.test(q)) { return __('Press D, draw a box around the section, say “make this an Etch component” and send. Built once, reused everywhere, with properties for what changes. Like my insults.', 'nibwp'); }
        if (/comment|note|pin|canvas|click|editor/i.test(q)) { return sprintf(__('Open the page in the Etch editor here, press %s and click right on the canvas. I tell your agent exactly which element. Yes, inside the canvas. I’m that good.', 'nibwp'), KEY_C); }
        if (etchSkill && /convert|html|screenshot|figma|image|url|design/i.test(q)) { return sprintf(__('Drop a screenshot, HTML or a URL in the message box and say “convert this to Etch”. With %s, NibWP builds validated Etch components from it.', 'nibwp'), etchSkill.name); }
        if (/acss|automatic/i.test(q)) {
            return acssSkill
                ? sprintf(__('ACSS is the CSS framework that pairs with Etch: tokens for spacing, type and colour. With %s, NibWP can build an ACSS setup from a screenshot or a URL.', 'nibwp'), acssSkill.name)
                : __('ACSS is the CSS framework that pairs with Etch: tokens for spacing, type and colour. Use them. Always.', 'nibwp');
        }
        // Nothing more specific: one of his pro tips, a different one each time.
        if (/\bcss\b|etch|grid|flex/i.test(q)) { return kgPick(KG_TIPS).text; }
        return null;
    }

    var KG_FOOT = [
        __('Hey! Off the sneakers!', 'nibwp'),
        __('Did you just step on my foot? Did you?', 'nibwp'),
        __('Limited edition. Touch them again, I dare you.', 'nibwp'),
        __('OW. Rude.', 'nibwp')
    ];
    var KG_HAT = [
        __('Nice try. The cap stays on for a reason.', 'nibwp'),
        __('Focus on the work. Before you know it, Bev will ask for changes.', 'nibwp')
    ];
    var KG_BEARD = [
        __('Hmm. Thinking. Don’t interrupt.', 'nibwp'),
        __('This beard has seen things. Mostly divs inside divs.', 'nibwp'),
        __('Scratching my beard means I’m about to be right. Again.', 'nibwp')
    ];

    function maxActs() {
        return [[__('Chat with me', 'nibwp'), 'kgx-chat'], [__('Inspect this page', 'nibwp'), 'kgx-inspect'], [__('Point at something', 'nibwp'), 'kgx-point'], [duty === 'on' ? __('Go off duty', 'nibwp') : __('Go on duty', 'nibwp'), 'kgx-flip'], [__('Bye, Kind Guy', 'nibwp'), 'g-bye']];
    }

    function maxSay(text, acts, opts) {
        opts = opts || {};
        opts.who = 'max';
        // While you talk with him in his window, no lines above his head:
        // the window has his full attention, and yours.
        if (kgChatIsOpen() && !opts.loud) { return; }
        say(text, acts, opts);
    }

    // Kind Guy's moves: the picture itself moves, it has no face to change.
    var G_MS = { smug: 1800, eyeroll: 1500, arrive: 1100, brag: 2600, in: 1900, out: 1400, stomp: 900, huff: 1400, flip: 1000, spin: 1400, jump: 800, tap: 1600, glare: 1500, talk: 500, hat: 2400, scratch: 2800, explain: 2600, clap: 2600 };
    function gAct(name) {
        clearTimeout(gAct.t);
        delete gEl.dataset.act;
        if (name === 'hat') { delete gEl.dataset.cap; }
        void gEl.offsetWidth;
        gEl.dataset.act = name;
        gAct.t = setTimeout(function () { delete gEl.dataset.act; }, G_MS[name] || 800);
    }

    // His mouth: a shape for a moment, or a run of them while he talks.
    function kgMouth(m, ms) {
        clearTimeout(kgMouth.t);
        gEl.dataset.mouth = m;
        kgMouth.t = setTimeout(function () { delete gEl.dataset.mouth; }, ms);
    }
    function kgQuiet() {
        if (!gEl) { return; }
        clearInterval(kgLips.t);
        clearTimeout(kgLips.t);
        clearTimeout(kgMouth.t);
        delete gEl.dataset.mouth;
        gEl.classList.remove('is-talking');
    }
    function kgLips(ms) {
        clearInterval(kgLips.t);
        clearTimeout(kgMouth.t);
        gEl.classList.add('is-talking');
        var end = Date.now() + ms;
        var done = function () {
            clearInterval(kgLips.t);
            delete gEl.dataset.mouth;
            gEl.classList.remove('is-talking');
        };
        if (reduced) { kgLips.t = setTimeout(done, ms); return; }
        var shapes = ['shout', 'o', 'grr', 'flat', 'o', 'shout', 'smirk', 'flat'];
        kgLips.t = setInterval(function () {
            if (Date.now() > end) { done(); return; }
            gEl.dataset.mouth = pick(shapes);
        }, 105 + Math.random() * 50);
    }

    // Blinks, now and then twice.
    (function kgBlink() {
        setTimeout(function () {
            if (guest === 'on' && !gEl.dataset.deaf) {
                var shut = function () {
                    gEl.classList.add('is-blink');
                    setTimeout(function () { gEl.classList.remove('is-blink'); }, 130);
                };
                shut();
                if (Math.random() < 0.2) { setTimeout(shut, 290); }
            }
            kgBlink();
        }, 2200 + Math.random() * 4200);
    }());

    // Three kicks in a few seconds and his cap flies off.
    var footHits = [];
    var capHits = [];
    var capBackT = 0;
    var KG_CAP_TOUCH = [
        __('Hands off the cap.', 'nibwp'),
        __('The cap stays. Don’t even think about it.', 'nibwp'),
        __('Careful. That cap has survived three redesigns.', 'nibwp')
    ];
    function kgFoot(side) {
        el.classList.remove('is-peek');
        clearTimeout(kgFoot.t);
        delete gEl.dataset.leg;
        void gEl.offsetWidth;
        gEl.dataset.leg = side;
        kgFoot.t = setTimeout(function () { delete gEl.dataset.leg; }, 900);
        footHits = footHits.filter(function (t) { return Date.now() - t < 6000; }).concat(Date.now());
        if (footHits.length >= 3) {
            footHits = [];
            gAct('hat');
            maxSay(__('THAT’S IT. You made my cap fly off. Happy now?', 'nibwp'), maxActs(), { act: 'keep', stay: 9000 });
            return;
        }
        maxSay(pick(KG_FOOT), [], { act: 'huff', stay: 4000 });
    }

    // No agent listening: earmuffs on, eyes shut, humming. Back on air when
    // one connects.
    function kgDeaf(v) {
        if (guest !== 'on' || v === !!gEl.dataset.deaf) { return; }
        if (v) {
            gEl.dataset.deaf = '1';
            setTimeout(function () {
                if (guest === 'on' && gEl.dataset.deaf && canTalk(5000)) {
                    maxSay(__('La la la. No agent, no listening. Connect one and I’ll take these off.', 'nibwp'), [[__('Connect', 'nibwp'), 'connect'], [__('Fine', 'nibwp'), 'close']], { act: 'tap', stay: 9000 });
                }
            }, 900);
        } else {
            delete gEl.dataset.deaf;
            maxSay(__('Fine. I’m listening. Don’t make me regret it.', 'nibwp'), [], { act: 'jump', stay: 5000 });
        }
    }

    function canCallMax() {
        return !!cfg.guest && mem.on && !el.hidden && Date.now() - (mem.maxNo || 0) > DAY;
    }

    // Offered once a visit, when Niby has a moment to say it.
    function offerMax(tries, why) {
        tries = tries || 0;
        why = why || 'etch';
        if (guest || guestOffered || !canCallMax() || tries > 4) { return; }
        setTimeout(function () {
            if (guest || guestOffered) { return; }
            if (!canTalk(6000) || el.dataset.portal) { offerMax(tries + 1, why); return; }
            guestOffered = true;
            kgCtx = why;
            var first = !mem.seen.max;
            antic('gasp');
            say(why === 'etch'
                ? (first ? __('Etch? Oh, that is serious work. For this one, let me bring in someone more skilled than me.', 'nibwp') : __('Etch again? Shall I call Kind Guy?', 'nibwp'))
                : (first ? __('Working with code? Let me bring in Kind Guy: PHP, HTML, CSS, JavaScript, he is a pro developer.', 'nibwp') : __('Working with code? Shall I call Kind Guy?', 'nibwp')),
                [[first ? __('Bring him in', 'nibwp') : __('Call Kind Guy', 'nibwp'), 'g-call'], [__('No, stay Niby', 'nibwp'), 'g-no']],
                { mood: 'worry', stay: 20000, who: 'niby' });
        }, tries ? 6000 : 4500);
    }

    function callMax(bySummon) {
        if (guest || !cfg.guest) { return; }
        guest = 'calling';
        guestOffered = true;
        if (pageEtch) { kgCtx = 'etch'; }
        summoned = summoned || !!bySummon;
        // Called by name, and not for the first time: no introduction, no wait.
        var quick = !!bySummon && !!mem.seen.max;
        mem.seen.max = mem.seen.max || Date.now();
        save();
        if (!quick) { say(kgCtx === 'etch' ? __('Meet Kind Guy: nobody knows Etch and ACSS like him. Brace yourself.', 'nibwp') : __('Meet Kind Guy: a pro developer. PHP, HTML, CSS, JavaScript, he knows them all. Brace yourself.', 'nibwp'), [], { mood: 'worry', stay: 2400, who: 'niby' }); }
        setTimeout(function () {
            hush();
            el.dataset.guest = 'max';
            place();
            gEl.hidden = false;
            var spot = mem.kgPos ? { x: mem.kgPos.x * window.innerWidth, y: mem.kgPos.y * window.innerHeight } : kgCorner();
            kgPlace(spot.x, spot.y);
            // He walks in from beyond the right edge of the screen to his spot.
            var fr = gEl.querySelector('.nst-buddy__max').getBoundingClientRect();
            var far = Math.max(280, Math.round(window.innerWidth - fr.left + 20));
            G_MS.in = Math.round(Math.min(4500, Math.max(1900, far * 3)));
            gEl.style.setProperty('--kg-in', far + 'px');
            gEl.style.setProperty('--kg-in-t', G_MS.in + 'ms');
            gAct('in');
            setTimeout(function () {
                guest = 'on';
                el.classList.remove('has-cap');
                kgxDutyUi();
                peekLoop();
                gAct('arrive');
                if (!mem.seen.kgSign) {
                    // His turn to speak, not over an inspection or the person's own words.
                    (function hint(tries) {
                        setTimeout(function () {
                            if (guest !== 'on' || mem.seen.kgSign) { return; }
                            if (!bubble.hidden || inspecting || !canTalk(9000)) { if (tries < 8) { hint(tries + 1); } return; }
                            mem.seen.kgSign = Date.now();
                            save();
                            maxSay(__('See my sign? Flip it. On duty, I inspect everything your agent does. Off duty, I just hang out and judge quietly.', 'nibwp'),
                                [[__('Show me the modes', 'nibwp'), 'kgx-modes'], [__('Inspect this page', 'nibwp'), 'kgx-inspect']], { act: 'explain', stay: 16000 });
                        }, reduced ? 0 : (tries ? 8000 : 13000));
                    }(0));
                }
                setTimeout(function () {
                    if (guest === 'on') { maxSay(kgCtx === 'etch' ? MAX_BEST : MAX_BEST_CODE, maxActs(), { stay: 30000, act: 'brag' }); }
                }, reduced ? 0 : 1000);
                setTimeout(function () { if (noAgent) { kgDeaf(true); } }, 6000);
                if (pendingQ) {
                    var q = pendingQ;
                    pendingQ = '';
                    // After his entrance line has had its moment.
                    setTimeout(function () { if (guest === 'on') { maxQuestion(q); } }, reduced ? 0 : 6500);
                }
            }, reduced ? 0 : G_MS.in);
        }, reduced || quick ? 0 : 1900);
    }

    function leaveMax(line) {
        if (guest !== 'on') { return; }
        guest = 'leaving';
        clearTimeout(peekT);
        maxSay(line || kgPick(MAX_BYE), [], { stay: 2400, act: 'glare' });
        setTimeout(function () {
            hush();
            gAct('out');
            setTimeout(function () {
                dropMax();
                antic('jump');
                say(__('Is he gone? … Phew. Hi again!', 'nibwp'), [], { mood: 'happy', stay: 5000 });
                if (noAgent) { mood('sleep'); }
            }, reduced ? 0 : G_MS.out);
        }, reduced ? 0 : 2000);
    }

    // Gone at once, no goodbye: Niby was switched off meanwhile.
    function dropMax() {
        clearTimeout(peekT);
        gEl.hidden = true;
        delete gEl.dataset.act;
        delete gEl.dataset.deaf;
        gEl.style.position = gEl.style.left = gEl.style.top = gEl.style.bottom = '';
        kgSide();
        delete el.dataset.guest;
        el.classList.remove('is-peek');
        guest = null;
        summoned = false;
        inspecting = null;
        kgChatClose();
        kgxScan(false);
        kgxDutyUi();
        place();
    }

    function peek() {
        el.classList.add('is-peek');
        // Scared: wide eyes, worried brows, trembling (see the shiver style).
        var f = pick([['wavy', 'wide', 'sad'], ['o', 'wide', 'sad'], ['sad', 'wide', 'sad']]);
        face(f[0], f[1], f[2]);
        clearTimeout(peek.t);
        peek.t = setTimeout(function () {
            el.classList.remove('is-peek');
            face(FACE.idle[0], FACE.idle[1], FACE.idle[2]);
        }, 1700);
    }

    // Niby checks on things now and then; sometimes Kind Guy notices.
    function peekLoop() {
        clearTimeout(peekT);
        peekT = setTimeout(function () {
            if (guest !== 'on') { return; }
            peek();
            if (Math.random() < 0.3 && bubble.hidden && canTalk(20000)) {
                setTimeout(function () {
                    if (guest === 'on' && bubble.hidden) {
                        maxSay(kgPick(MAX_PEEK), [], { act: 'glare', stay: 3500 });
                        // Caught: Niby ducks at once.
                        setTimeout(function () { el.classList.remove('is-peek'); }, 250);
                    }
                }, 900);
            }
            peekLoop();
        }, 9000 + Math.random() * 9000);
    }

    function story() {
        var c;
        var tries = 0;
        // Outside Etch, the Etch stories wait for Etch.
        do { c = STORY[storyNo % STORY.length]; storyNo++; } while (kgCtx !== 'etch' && /(Etch|ACSS)/.test(c.text) && ++tries < STORY.length);
        var acts = (c.act ? [c.act] : []).concat([[storyNo % STORY.length ? __('Next', 'nibwp') : __('From the top', 'nibwp'), 'g-story'], [__('Bye, Kind Guy', 'nibwp'), 'g-bye']]);
        maxSay(c.text, acts, { stay: 30000, act: pick(['talk', 'glare', 'tap']) });
    }

    function maxTrick() {
        hush();
        gAct(pick(['spin', 'stomp', 'jump', 'huff', 'brag']));
        setTimeout(function () {
            if (guest === 'on') { maxSay(kgPick(MAX_JOKES), maxActs(), { stay: 14000, act: 'smug' }); }
        }, reduced ? 0 : 1100);
    }

    var STUDIO = /\betch\s*studio\b/i;
    var NEG = /\b(bad|sucks?|hate[sd]?|slow|bugg?y|bugs|broken|worse|worst|expensive|ugly|terrible|awful|useless|crap|trash|garbage|meh|overrated|not good|not great|doesn.?t work|does not work|confusing|clunky|annoying|lame|boring)\b/i;
    var KG_STUDIO_MAD = [
        __('Excuse me? Say one more word against Etch Studio. One. I dare you.', 'nibwp'),
        __('Etch Studio is fine. It is MORE than fine. Take that back.', 'nibwp'),
        __('You don’t like Etch Studio? Then you don’t like good things. Next question.', 'nibwp'),
        __('I’m not angry. I’m disappointed. And angry. Mostly angry.', 'nibwp')
    ];
    var KG_STUDIO_PROUD = [
        __('Etch Studio? Now you’re talking. Finally, someone with taste.', 'nibwp'),
        __('Etch Studio. Say it with respect. Go on, ask.', 'nibwp')
    ];
    var KG_ETCH_MAD = [
        __('Bad? Etch? Wash your mouth out with semantic HTML.', 'nibwp'),
        __('Etch is the best thing that happened to WordPress and you know it.', 'nibwp')
    ];
    // True when he has reacted, so nothing else answers.
    function kgMad(text) {
        var studio = STUDIO.test(text);
        if (!studio && !ETCH.test(text)) { return false; }
        if (NEG.test(text)) {
            el.classList.remove('is-peek');
            gAct('hat');
            hush();
            setTimeout(function () {
                if (guest === 'on') { maxSay(pick(studio ? KG_STUDIO_MAD : KG_ETCH_MAD), maxActs(), { act: 'keep', stay: 14000 }); }
            }, reduced ? 0 : 900);
            return true;
        }
        if (studio) {
            maxSay(pick(KG_STUDIO_PROUD), maxActs(), { act: 'glare', stay: 14000 });
            return true;
        }
        return false;
    }

    function maxQuestion(q) {
        talk.push(['you', q]);
        talk = talk.slice(-6);
        if (kgMad(q)) { return; }
        talk.pop();
        kgChatOpen();
        kgxAsk(q, 'max-chat', '', null, 'box');
    }

    function isEtchPage(d) {
        return /[?&]etch=/.test(d.url || '');
    }
    function building() { var r = document.querySelector('.nst'); return !!(r && r.classList.contains('is-building')); }

    // What a request is about, first match wins: his word on sending it, and
    // his word when the agent is done. Facts from the Etch and ACSS guides.
    var KG_TOPICS = [
        { re: /\b(fix|bug|broken|wrong|issue|error)\b/i, act: 'glare',
            sent: [__('Something’s broken? Not for long. I’m here now.', 'nibwp'), __('A fix. Watch how the best do it: find the cause, not the symptom.', 'nibwp')],
            done: __('Fixed. Properly. Not a patch on a patch.', 'nibwp') },
        { re: /\b(remove|delete|drop|get rid)\b/i, act: 'smug',
            sent: [__('Removing things. My favourite. Less markup, fewer problems.', 'nibwp')],
            done: __('Gone. The page is lighter already. You’re welcome.', 'nibwp') },
        { re: /\b(convert|screenshot|figma|mockup|image|design|url|html)\b/i, act: 'brag',
            sent: [__('Converting? Clean Etch components before you finish your coffee.', 'nibwp'), __('A design to build. Pixel for pixel, tokens with fallbacks. Watch.', 'nibwp')],
            done: __('Built. Same as the design, cleaner than the design.', 'nibwp') },
        { re: /\bcomponents?\b|\breus/i, act: 'explain',
            sent: [__('A component. Properties for what changes, one source of truth. I’d expect nothing less.', 'nibwp')],
            done: __('Component done. Use it everywhere. That’s the point.', 'nibwp') },
        { re: /\b(loop|list|posts|products|cards|grid of|repeater|query|archive)\b/i, act: 'explain',
            sent: [__('A list? That’s a loop, not boxes made by hand. Your agent knows. I made sure.', 'nibwp')],
            done: __('Loop in. Add a post and it just shows up. Magic? No. Me.', 'nibwp') },
        { re: /\b(hero|section|landing|header|footer|banner)\b/i, act: 'brag',
            sent: [__('A section. var(--section-space-m), heroes get --section-space-l. Make it loud, keep it clean.', 'nibwp')],
            done: __('Section done. Breathing room, real tokens. Look at it. Go on.', 'nibwp') },
        { re: /\b(colou?rs?|brand|palette|background|dark mode)\b/i, act: 'explain',
            sent: [__('Colours come from ACSS tokens, var(--primary) and friends. A hex in the CSS? Not on my watch.', 'nibwp')],
            done: __('Colours in, all tokens. Change the brand once, the site follows.', 'nibwp') },
        { re: /\b(fonts?|text|headings?|titles?|typograph\w*|size)\b/i, act: 'explain',
            sent: [__('Type. var(--h1) to var(--h3), body on var(--text-m). If I see a clamp() on a font size, I’m leaving.', 'nibwp')],
            done: __('Type sorted. One scale, no clamp(). Chef’s kiss.', 'nibwp') },
        { re: /\b(spacing|padding|margins?|gaps?|space)\b/i, act: 'explain',
            sent: [__('Spacing with tokens: --space-*, --content-gap, --grid-gap. A margin on every child is a crime.', 'nibwp')],
            done: __('Spacing fixed. Consistent. Like me.', 'nibwp') },
        { re: /\b(mobile|phone|tablet|responsive|breakpoints?)\b/i, act: 'glare',
            sent: [__('Mobile. Container queries where the component cares about its own width. I’ll be watching.', 'nibwp')],
            done: __('Phone layout done. Check it in phone view, up top. Then tell me I’m great.', 'nibwp') },
        { re: /\b(button|cta|call to action|links?)\b/i, act: 'explain',
            sent: [__('A button: a BEM class, a hover, a focus ring. If a keyboard can’t reach it, it doesn’t exist.', 'nibwp')],
            done: __('Button done. Clickable, focusable, beautiful. Like all my work.', 'nibwp') },
        { re: /\bforms?\b/i, act: 'glare',
            sent: [__('A form. If a form plugin is installed, it goes through that, not raw form HTML. Rules are rules.', 'nibwp')],
            done: __('Form in, the right way. Try it, it won’t bite. I might.', 'nibwp') },
        { re: /\b(acss|tokens?|variables?)\b/i, act: 'brag',
            sent: [__('ACSS tokens with fallbacks, every time: var(--token, fallback). Non-negotiable.', 'nibwp')],
            done: __('Tokens everywhere. The site breathes ACSS now.', 'nibwp') }
    ];
    var lastTopic = null;

    function kgOnSent(text, notes) {
        lastTopic = KG_TOPICS.find(function (t) { return t.re.test(text); }) || null;
        if (lastTopic) {
            maxSay(kgPick(lastTopic.sent), [], { act: lastTopic.act, stay: 7000 });
        } else if (notes >= 2) {
            /* translators: %d: how many notes were sent together */
            maxSay(sprintf(__('%d notes on the canvas, all in one go. Precise. I like precise.', 'nibwp'), notes), [], { act: 'smug', stay: 6000 });
        } else {
            maxSay(kgPick(MAX_SENT), [], { act: 'stomp', stay: 5000 });
        }
    }

    // What happens while Kind Guy is on is his to comment on.
    function maxEvent(t, d) {
        if (guest !== 'on') { return; }
        switch (t) {
            case 'sent':
                adviceFor = '';
                advised = {};
                kgSaves = 0;
                if (duty === 'on') { inspecting = { text: d.text || '', at: Date.now(), saves: 0 }; }
                if (kgMad(d.text || '')) { break; }
                if (duty !== 'on' && !summoned && OTHER.test(d.text || '') && !ETCH.test(d.text || '')) { maxSay(kgPick(MAX_OTHER), [], { act: 'huff', stay: 7000 }); } else { kgOnSent(d.text || '', d.notes || 0); }
                break;
            case 'item':
                if (d.kind === 'event' && d.type === 'saved') { kgSaves++; if (inspecting) { inspecting.saves++; } }
                if (d.kind === 'reply' && d.text) { lastReply = d.text; }
                if (d.kind === 'reply' && inspecting && duty === 'on') {
                    kgxReview(d.text || '');
                    lastTopic = null;
                    break;
                }
                if (d.kind === 'reply' && kgSaves > 0 && Math.random() < 0.35 && canTalk(8000)) {
                    kgSaves = 0;
                    kgxApplaud();
                    lastTopic = null;
                    break;
                }
                if (d.kind === 'reply' && canTalk(8000)) {
                    maxSay(lastTopic ? lastTopic.done : kgPick(MAX_DONE), [], { act: lastTopic ? 'brag' : 'jump', stay: 7000 });
                    lastTopic = null;
                }
                break;
            case 'error':
                maxSay(__('Ugh. Something broke. Not me. Never me.', 'nibwp'), [], { act: 'huff', stay: 5500 });
                break;
            case 'proof':
                if (d.on && !maxEvent.proof) {
                    maxEvent.proof = true;
                    maxSay(__('Click the thing. The exact thing. I don’t read minds. Mostly.', 'nibwp'), [], { act: 'glare', stay: 5000 });
                }
                break;
            case 'page':
                if (isEtchPage(d)) { kgCtx = 'etch'; } else if (building()) { kgCtx = 'code'; }
                if (!summoned && duty !== 'on' && !isEtchPage(d) && !building() && Date.now() - etchAt > 120000) { leaveMax(kgCtx === 'etch' ? __('No Etch here? Then I’m out. Call me when you do real work.', 'nibwp') : __('Nothing to build here? Then I’m out. Call me when you do real work.', 'nibwp')); }
                break;
        }
    }

    // Kind Guy fidgets while he waits.
    (function maxIdle() {
        setTimeout(function () {
            if (guest === 'on' && bubble.hidden && !document.hidden && !gEl.dataset.act) {
                var g = pick(gEl.dataset.deaf ? ['tap', 'tap', 'chew'] : ['brag', 'smug', 'eyeroll', 'tap', 'scratch', 'spin', 'glare', 'chew', 'smug', 'brag', 'joke', 'huff']);
                if (g === 'chew' || g === 'grr') { kgMouth(g, 1500); } else if (g === 'brag' && canTalk(40000)) { maxSay(kgPick(MAX_BRAG), maxActs(), { act: 'brag', stay: 9000 }); } else if (g === 'joke') { if (canTalk(40000)) { maxSay(kgPick(MAX_JOKES), maxActs(), { act: 'smug', stay: 9000 }); } } else { gAct(g); }
            }
            maxIdle();
        }, 6000 + Math.random() * 9000);
    }());

    /* ------------------------------------------------------------------
     * Kind Guy, the expert. Two modes, his own call:
     *
     *  - On duty: code inspector, standards controller, adviser, mentor.
     *    He reads a request before it goes (a better workflow, what is
     *    missing), tells the agent he will check its work, and inspects
     *    every finished task: a scan over the page, then a report card
     *    with a verdict stamp, ✓ and ✗ per area, and the fixes as buttons.
     *  - Off duty: sunglasses on, just company. He still answers when asked.
     *
     * His chat window takes questions about WordPress, builders, CSS and
     * design systems. The listening agent answers for him from NibWP's
     * own guides and can offer actions; with no agent he quotes the guides.
     * The switch: the badge on his chest, the window's header, the button
     * up top. After a big win he now and then reminds the person how long
     * that would have taken without NibWP (never on a renamed install).
     * ---------------------------------------------------------------- */

    var _n = wp.i18n._n;
    var kgxInput = document.getElementById('nst-input');
    var duty = mem.kgDuty === 'off' ? 'off' : 'on';
    var summoned = false;
    var inspecting = null;
    var kgxStats = { inspected: 0, fixes: 0 };
    var KGX_ON = [
        __('Badge on. Every task gets inspected. Every single one.', 'nibwp'),
        __('On duty. Nothing ships past me without a look.', 'nibwp'),
        __('Inspector mode. Show me what you’ve got. Actually, show me what your agent’s got.', 'nibwp')
    ];
    var KGX_OFF = [
        __('Off duty. Shades on. Wake me if something’s on fire.', 'nibwp'),
        __('Fine, I’ll just hang out. I’m still watching. Out of habit.', 'nibwp'),
        __('Off the clock. Ask me anything, I won’t inspect. Much.', 'nibwp')
    ];
    var KGX_SCAN = [
        __('Hold on. Inspecting. Don’t touch anything.', 'nibwp'),
        __('Magnifier out. Let’s see what we’ve got here.', 'nibwp'),
        __('Reading the markup. Line by line. Like a pro.', 'nibwp')
    ];
    var KGX_BRAG = [
        /* translators: 1: number of changes, 2: how long it took, e.g. "4 minutes" */
        __('%1$s, inspected to standard, in %2$s. Without NibWP? That’s an afternoon of tickets. You’re welcome.', 'nibwp'),
        /* translators: 1: number of changes, 2: how long it took */
        __('%1$s in %2$s. A developer quote for that? Days. This is exactly what NibWP is for.', 'nibwp'),
        /* translators: 1: number of changes, 2: how long it took */
        __('Built, checked, shipped: %1$s in %2$s. Try that without NibWP. I’ll wait. I’m very patient. No I’m not.', 'nibwp')
    ];
    var KGX_CLAP = [
        __('Fine. That’s good. Really good. Don’t let it go to your head.', 'nibwp'),
        __('Applause. From me. Frame it, it doesn’t happen often.', 'nibwp'),
        __('Okay, okay… that’s clean. I’m clapping. Slowly. But clapping.', 'nibwp'),
        __('Look at that. Tokens, structure, all of it. I approve. Loudly.', 'nibwp'),
        __('Now THAT is how it’s done. I taught you well. Obviously.', 'nibwp')
    ];
    var KGX_LIKE_Q = [
        __('Be honest. You like me, right? Everyone does. Mostly.', 'nibwp'),
        __('Quick survey, totally unrelated to my ego: do you like me?', 'nibwp'),
        __('So… am I your favourite? You can say it. I already know.', 'nibwp')
    ];
    var KGX_LIKE_YES = [
        __('Knew it. Nobody resists this level of CSS.', 'nibwp'),
        __('Obviously. I like you too. Don’t tell Niby.', 'nibwp'),
        __('Correct answer. Your code just got ten percent cleaner. That’s how it works.', 'nibwp')
    ];
    var kgSaves = 0;
    var KGX_STOP = /^(the|and|for|with|that|this|from|into|make|please|page|site|want|need|some|have|your|about|then|also|just|like)$/;

    // What he says that matters goes in the thread, with everything the person
    // and the agent say: one place to read, one box to type in.
    var kgFeed = document.getElementById('nst-feed');
    var kgxActs = {};
    var kgxActNo = 0;
    var kgxMsgs = [];

    function kgxDutyUi() {
        gEl.dataset.duty = duty;
        kgSign.dataset.duty = duty;
        clearTimeout(kgSignT);
        if (guest !== 'on') {
            kgSign.hidden = true;
        } else if (duty === 'on') {
            kgSign.hidden = false;
        } else if (!kgSign.hidden) {
            kgSignT = setTimeout(function () { kgSign.hidden = true; }, reduced ? 0 : 1500);
        }
        var board = kgSign.querySelector('.nst-kg-sign__board');
        board.setAttribute('aria-label', duty === 'on'
            ? __('Kind Guy is on duty. Change his mode', 'nibwp')
            : __('Kind Guy is off duty. Change his mode', 'nibwp'));
        kgModes.querySelectorAll('[role="menuitemradio"]').forEach(function (b) {
            b.setAttribute('aria-checked', String(b.dataset.b === 'kgx-duty-' + duty));
        });
        if (guest !== 'on') { kgxModes(false); }
        host.dataset.kgDuty = guest === 'on' && duty === 'on' ? 'on' : '';
        var sw = kgChat.querySelector('.nst-kgchat__duty');
        sw.setAttribute('aria-checked', String(duty === 'on'));
        sw.querySelector('span').textContent = duty === 'on' ? __('On duty', 'nibwp') : __('Off duty', 'nibwp');
        sw.title = duty === 'on' ? __('On duty: I inspect every finished task. Click to go off duty.', 'nibwp') : __('Off duty: I chat, I don’t inspect. Click to go on duty.', 'nibwp');
    }

    var kgSignT = 0;
    function kgxModes(open) {
        kgModes.hidden = !open;
        el.classList.toggle('is-kg-modes', !!open);
        kgModes.style.marginLeft = '';
        if (open) {
            var mr = kgModes.getBoundingClientRect();
            var dx = mr.right > window.innerWidth - 8 ? window.innerWidth - 8 - mr.right : mr.left < 8 ? 8 - mr.left : 0;
            if (dx) { kgModes.style.marginLeft = dx + 'px'; }
        }
        kgSign.querySelector('.nst-kg-sign__board').setAttribute('aria-expanded', String(!!open));
        if (open) {
            hush();
            var first = kgModes.querySelector('[aria-checked="true"]');
            if (first) { setTimeout(function () { first.focus(); }, 20); }
        }
    }

    document.addEventListener('click', function (e) {
        if (!kgModes.hidden && !kgSign.contains(e.target) && !kgModes.contains(e.target) && !e.target.closest('[data-b="kgx-modes"]')) { kgxModes(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !kgModes.hidden) { kgxModes(false); kgSign.querySelector('.nst-kg-sign__board').focus(); }
    });

    function setDuty(v, quiet) {
        duty = v === 'off' ? 'off' : 'on';
        mem.kgDuty = duty;
        save();
        kgxDutyUi();
        if (!quiet && guest === 'on') {
            maxSay(pick(duty === 'on' ? KGX_ON : KGX_OFF), [], { act: duty === 'on' ? 'arrive' : 'smug', stay: 5000 });
        }
    }

    // Back to the thread: the panel open, the chat on screen, his latest card in view.
    function kgxOpen() {
        if (guest !== 'on') { return; }
        var root = document.getElementById('nst');
        if (root && root.classList.contains('is-collapsed')) { var open = document.querySelector('[data-rail="open"]'); if (open) { open.click(); } }
        var tab = document.getElementById('nst-tab-chat');
        if (tab && tab.getAttribute('aria-selected') !== 'true') { tab.click(); }
        var last = kgxMsgs[kgxMsgs.length - 1];
        if (last && last.card && last.card.isConnected) { last.card.scrollIntoView({ block: 'nearest', behavior: reduced ? 'auto' : 'smooth' }); }
        hush();
    }

    function kgxActButtons(actions) {
        return (actions || []).map(function (a, i) {
            var key = 'a' + (++kgxActNo);
            kgxActs[key] = a;
            return '<button type="button" class="nst-kgx__act' + (i ? '' : ' is-main') + '" data-kgx-act="' + key + '">' + esc(a.label) + '</button>';
        }).join('');
    }

    // The agent writes **bold** and `code` now and then: shown as such, safely.
    function kgxMd(text) {
        return String(text).split(/```[a-z0-9-]*[ \t]*\n?/i).map(function (part, i) {
            return i % 2
                ? '<span class="nst-kgx__code"><button type="button" data-kgx-copy>' + esc(__('Copy', 'nibwp')) + '</button><pre><code>' + esc(part.replace(/\n+$/, '')) + '</code></pre></span>'
                : kgxInline(part);
        }).join('');
    }

    function kgxInline(text) {
        var h = esc(text);
        h = h.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        h = h.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
        h = h.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,!?:;]|$)/g, '$1<em>$2</em>');
        h = h.replace(/^[ \t]*#{1,4}[ \t]+(.+)$/gm, '<strong>$1</strong>');
        h = h.replace(/^[ \t]*[-•*][ \t]+/gm, '• ');
        return h;
    }

    var NIB = '<svg class="nst-nib" viewBox="0 0 205 305" aria-hidden="true"><g transform="translate(-98.33 477.88)">' +
        '<path pathLength="100" transform="translate(-34.969 28)" d="' + ICON.antenna + '"/>' +
        '<path pathLength="100" transform="translate(0 -37.405)" d="' + ICON.head + '"/>' +
        '<path pathLength="100" transform="translate(-35.173 -105.766)" d="' + ICON.ball + '"/></g></svg>';
    var TASK_ICON = {
        queued: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
        claimed: '<span class="nst-spark"><svg viewBox="0 0 24 24"><path d="M12 1c.8 6 5 10.2 11 11-6 .8-10.2 5-11 11-.8-6-5-10.2-11-11 6-.8 10.2-5 11-11Z"/></svg><svg viewBox="0 0 24 24"><path d="M12 1c.8 6 5 10.2 11 11-6 .8-10.2 5-11 11-.8-6-5-10.2-11-11 6-.8 10.2-5 11-11Z"/></svg></span>',
        done: '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
        cancelled: '<svg viewBox="0 0 24 24"><path d="M7 7l10 10M17 7 7 17"/></svg>'
    };
    function kgTaskHtml(m) {
        var label = {
            queued: __('Passed to your agent: waiting in line', 'nibwp'),
            claimed: __('Your agent is on it', 'nibwp'),
            done: __('Done. Have a look', 'nibwp'),
            cancelled: __('Stopped', 'nibwp')
        }[m.status] || '';
        var task = String(m.task || '');
        return '<div class="nst-kgx__task" data-status="' + esc(m.status) + '">' +
            '<span class="nst-kgx__ti" aria-hidden="true">' + (TASK_ICON[m.status] || '') + '</span>' +
            '<span class="nst-kgx__tt"><b>' + esc(label) + (m.status === 'claimed' && m.since ? ' · <span data-since="' + m.since + '">0s</span>' : '') + '</b>' +
            '<small>' + esc(task.length > 110 ? task.slice(0, 107) + '…' : task) + '</small></span></div>' +
            (m.status === 'done' ? '' : '<p class="nst-kgx__tn">' + esc(__('Follow it in the Agent Chat on the left ←', 'nibwp')) + '</p>');
    }

    function kgxRender(m) {
        var node = m.node || document.createElement('div');
        if (m.status) {
            node.className = 'nst-kgx__msg is-kg is-task';
            node.innerHTML = kgTaskHtml(m);
            return node;
        }
        if (m.thinking) {
            node.className = 'nst-kgx__msg is-kg is-thinking';
            node.innerHTML = (m.source ? '<span class="nst-kgx__from">' + esc(m.source) + '</span>' : '') +
                '<div class="nst-kgx__think">' + NIB + '<span>' + esc(m.text) + '</span></div>';
            return node;
        }
        node.className = 'nst-kgx__msg is-' + m.who + (m.thinking ? ' is-thinking' : '') + (m.intro ? ' is-intro' : '');
        if (m.who === 'report') {
            var passed = (m.checks || []).filter(function (c) { return c.ok; }).length;
            node.dataset.verdict = m.verdict || 'almost';
            node.innerHTML =
                '<span class="nst-kgx__stamp">' + esc(m.verdict === 'ship' ? __('Ship it', 'nibwp') : m.verdict === 'work' ? __('Needs work', 'nibwp') : __('Almost', 'nibwp')) + '</span>' +
                '<b class="nst-kgx__rtitle">' + esc(__('Inspection report', 'nibwp')) + '</b>' +
                ((m.checks || []).length ? '<span class="nst-kgx__score">' + esc(sprintf(__('%1$d of %2$d checks pass', 'nibwp'), passed, m.checks.length)) + '</span>' : '') +
                '<ul class="nst-kgx__checks">' + (m.checks || []).map(function (c) {
                    return '<li class="' + (c.ok ? 'is-ok' : 'is-no') + '"><i aria-hidden="true">' + (c.ok ? '✓' : '✗') + '</i><span>' + kgxMd(c.text) + '</span></li>';
                }).join('') + '</ul>' +
                (m.text ? '<div class="nst-kgx__body">' + kgxMd(m.text) + '</div>' : '') +
                (m.actions && m.actions.length ? '<div class="nst-kgx__acts">' + kgxActButtons(m.actions) + '</div>' : '');
        } else {
            node.innerHTML = (m.source ? '<span class="nst-kgx__from">' + esc(m.source) + '</span>' : '') +
                '<div class="nst-kgx__body">' + kgxMd(m.text) + '</div>' +
                (m.detail ? '<details class="nst-kgx__detail"><summary>' + esc(__('The guide’s exact words', 'nibwp')) + '</summary><div class="nst-kgx__body">' + kgxMd(m.detail) + '</div></details>' : '') +
                ((m.actions && m.actions.length) || (m.acts && m.acts.length) ? '<div class="nst-kgx__acts">' + kgxActButtons(m.actions || []) +
                    (m.acts || []).map(function (a, i) { return '<button type="button" class="nst-kgx__act' + (i || (m.actions && m.actions.length) ? '' : ' is-main') + '" data-kgx-b="' + esc(a[1]) + '">' + esc(a[0]) + '</button>'; }).join('') + '</div>' : '');
        }
        return node;
    }

    // A card of his in the thread, under his face.
    function kgxAdd(m) {
        m.node = kgxRender(m);
        m.where = m.where || (kgChatIsOpen() ? 'box' : 'feed');
        if (m.where === 'box') {
            m.card = m.node;
            kgChatLog.appendChild(m.node);
            kgChatLog.scrollTop = kgChatLog.scrollHeight;
            kgxMsgs.push(m);
            if (kgxMsgs.length > 60) { var gone = kgxMsgs.shift(); if (gone.card) { gone.card.remove(); } }
            return m;
        }
        m.card = document.createElement('article');
        m.card.className = 'nst-msg nst-kgcard';
        m.card.setAttribute('aria-label', __('Kind Guy', 'nibwp'));
        m.card.innerHTML = '<span class="nst-kgcard__face" aria-hidden="true">' + kgFace(duty) + '</span>';
        m.card.appendChild(m.node);
        kgxMsgs.push(m);
        if (kgxMsgs.length > 40) { var old = kgxMsgs.shift(); if (old.card) { old.card.remove(); } }
        if (kgFeed) {
            var near = kgFeed.scrollHeight - kgFeed.scrollTop - kgFeed.clientHeight < 200;
            kgFeed.appendChild(m.card);
            if (near || !m.quiet) { kgFeed.scrollTop = kgFeed.scrollHeight; }
        }
        return m;
    }

    // His body while he thinks: a scratch of the beard now and then.
    function kgThink(on) {
        clearInterval(kgThink.t);
        if (!on || reduced) { return; }
        gAct('scratch');
        kgThink.t = setInterval(function () { if (guest === 'on' && !gEl.dataset.act) { gAct(pick(['scratch', 'explain', 'scratch'])); } }, 3200);
    }

    function kgxUpdate(m, fields) {
        if (fields.thinking === false) { kgThink(false); }
        Object.keys(fields).forEach(function (k) { m[k] = fields[k]; });
        kgxRender(m);
        if (m.where === 'box') { kgChatLog.scrollTop = kgChatLog.scrollHeight; return; }
        if (kgFeed && m.card && m.card.isConnected) { m.card.scrollIntoView({ block: 'nearest' }); }
    }

    // An answer from the agent is the agent working: Agent View's quiet clock hears it.
    function worked() { document.dispatchEvent(new CustomEvent('nibwp-buddy-work')); }

    // One question to the listening agent, for Kind Guy: his answer and his actions, or null.
    function expertCall(moment, extra, wait) {
        return rest('buddy', { moment: moment, context: context(extra) }).then(function (r) {
            if (!r.id) { return null; }
            var started = Date.now();
            return new Promise(function (done) {
                (function check() {
                    rest('buddy?id=' + encodeURIComponent(r.id)).then(function (x) {
                        if (x.text) { worked(); return done({ text: x.text, actions: Array.isArray(x.actions) ? x.actions : [], point: x.point || '' }); }
                        if (Date.now() - started > (wait || 75000)) { return done(null); }
                        setTimeout(check, 1500);
                    });
                }());
            });
        });
    }

    /* Kind Guy as a supervisor, with no agent listening: he recognises the
     * job, says how a pro handles it, asks the one thing that changes the
     * plan, then hands over the plan and a request the agent can run as
     * written, with the NibWP skill or workflow that fits. He never reads a
     * guide back at anyone. */
    var KG_CASES = [
        {
            hit: /spac|padding|margin|\bgap\b|breath|cramp|white ?space|too close|too far apart/i, skill: 'acss-pro',
            label: __('Spacing', 'nibwp'),
            take: __('Spacing is where a site looks cheap or expensive. A pro never types pixels: sections get var(--section-space-m), stacks var(--content-gap), cards var(--space-m). One scale, and the whole site breathes the same way.', 'nibwp'),
            ask: __('Where does it feel off?', 'nibwp'),
            opts: [__('Between sections', 'nibwp'), __('Inside cards and boxes', 'nibwp'), __('Text feels cramped', 'nibwp'), __('All over the page', 'nibwp')],
            plan: __('1. Find every hard-coded value in that area.\n2. Swap them for the spacing tokens: same look, one scale.\n3. Check phone, tablet and desktop.\n4. I inspect the result.', 'nibwp'),
            /* translators: %s: where the person said the problem is, e.g. "Between sections" */
            req: __('Fix the spacing on the page on screen (%s): replace hard-coded padding, margins and gaps with Automatic.css tokens (var(--section-space-m) between sections, var(--content-gap) in stacks, var(--space-m) inside cards), keep the design as it is, check phone, tablet and desktop, and tell me what changed.', 'nibwp')
        },
        {
            hit: /typo|font|heading|\bh[1-6]\b|text size|too (big|small)|readab|line.?height/i, skill: 'acss-pro',
            label: __('Typography', 'nibwp'),
            take: __('Type sets the tone before anyone reads a word. Headings ride the scale, var(--h1) to var(--h3), body text var(--text-m), small print never under var(--text-s). No random pixels, and no clamp() on font sizes.', 'nibwp'),
            ask: __('What bothers you?', 'nibwp'),
            opts: [__('Headings too big or small', 'nibwp'), __('Body text hard to read', 'nibwp'), __('Sizes all over the place', 'nibwp'), __('It breaks on phones', 'nibwp')],
            plan: __('1. List every font size, weight and line height in use.\n2. Map them onto the type scale tokens.\n3. Keep one H1, headings in order.\n4. Check it on a phone, then I inspect it.', 'nibwp'),
            /* translators: %s: what the person said bothers them */
            req: __('Fix the typography on the page on screen (%s): put headings on the Automatic.css scale (var(--h1), var(--h2), var(--h3)), body text on var(--text-m) and small text no smaller than var(--text-s), with fallbacks like var(--text-m, 1rem), no clamp() for font sizes, one H1 and headings in order. Check phone and desktop and tell me what changed.', 'nibwp')
        },
        {
            hit: /colou?r|contrast|brand|palette|dark (mode|section)|background/i, skill: 'acss-pro',
            label: __('Colours', 'nibwp'),
            take: __('Colour is a system, not a mood. var(--primary) is for action, var(--accent) for highlights, text on dark uses var(--text-light). And every pair passes contrast, or it doesn’t ship.', 'nibwp'),
            ask: __('What’s the job?', 'nibwp'),
            opts: [__('Apply our brand colours', 'nibwp'), __('Fix poor contrast', 'nibwp'), __('Dark sections', 'nibwp'), __('Button and link states', 'nibwp')],
            plan: __('1. Find every colour typed by hand.\n2. Move them onto the colour tokens.\n3. Test every text and background pair for contrast.\n4. Hover and focus states for every link and button.', 'nibwp'),
            /* translators: %s: the colour job the person picked */
            req: __('Work on the colours of the page on screen (%s): use Automatic.css colour tokens (var(--primary) for actions, var(--accent) for highlights, var(--text-light) on dark backgrounds) instead of hand-typed colours, make every text and background pair pass WCAG AA contrast, give links and buttons clear hover and focus states, and tell me what changed.', 'nibwp')
        },
        {
            hit: /mobile|phone|tablet|responsive|breakpoint|small screen|overflow/i, skill: '',
            label: __('Mobile', 'nibwp'),
            take: __('Most of your visitors are on a phone. I check three widths, tap targets of at least 44px, no sideways scrolling, and text that stays readable without zooming.', 'nibwp'),
            ask: __('Where do we look first?', 'nibwp'),
            opts: [__('This page', 'nibwp'), __('The header and menu', 'nibwp'), __('Forms', 'nibwp'), __('The whole site', 'nibwp')],
            plan: __('1. Open it at phone, tablet and desktop widths.\n2. Fix overflow, tiny tap targets and squeezed text.\n3. Use container queries where a block lives in different places.\n4. I inspect every width.', 'nibwp'),
            /* translators: %s: the part of the site the person picked */
            req: __('Make it work on phones and tablets (%s): check at 375px, 768px and desktop, remove any sideways scrolling, make tap targets at least 44px, keep text readable without zooming, and use the existing tokens. Tell me what you fixed at each width.', 'nibwp')
        },
        {
            hit: /accessib|a11y|wcag|screen reader|alt text|keyboard|focus|aria|label/i, skill: '',
            label: __('Accessibility', 'nibwp'),
            take: __('Accessibility is the floor, not a feature. Real headings in order, a label on every field, alt text that says something, a visible focus ring, and the keyboard reaches everything.', 'nibwp'),
            ask: __('Where do we start?', 'nibwp'),
            opts: [__('Audit this page', 'nibwp'), __('Images and alt text', 'nibwp'), __('Forms and buttons', 'nibwp'), __('Keyboard and focus', 'nibwp')],
            plan: __('1. Audit: headings, landmarks, labels, alt text, contrast, focus.\n2. Fix what fails, worst first.\n3. Walk it with the keyboard only.\n4. I inspect it against WCAG AA.', 'nibwp'),
            /* translators: %s: the accessibility area the person picked */
            req: __('Make the page on screen accessible (%s): headings in order with one H1, a label on every field, meaningful alt text, WCAG AA contrast, a visible focus ring using var(--focus-color), and everything reachable with the keyboard. Fix what fails and list each fix.', 'nibwp')
        },
        {
            hit: /hero|section|landing|layout|build|create a (page|section)|new page|design|grid|cards?\b|testimonial|faq|pricing|call to action|cta/i, skill: 'etchwp-pro',
            label: __('A new section', 'nibwp'),
            take: __('Before anyone drags a box: what is the section for, and what is the one thing a visitor should do next? Then it gets built right: semantic tags, BEM class names, tokens for every value, and a component if it repeats.', 'nibwp'),
            ask: __('What are we building?', 'nibwp'),
            opts: [__('A hero', 'nibwp'), __('A grid of cards', 'nibwp'), __('Testimonials', 'nibwp'), __('A call to action', 'nibwp'), __('An FAQ', 'nibwp')],
            plan: __('1. One goal, one main action.\n2. Semantic structure, BEM names, tokens for every value.\n3. A component with properties if it repeats.\n4. Phone, tablet, desktop, then I inspect it.', 'nibwp'),
            /* translators: %s: the kind of section, e.g. "A hero" */
            req: __('Build %s on the page on screen, in the builder this site uses: semantic HTML, BEM class names, Automatic.css tokens for every spacing, size and colour, one clear main action, a reusable component with properties if it repeats, responsive at phone, tablet and desktop, accessible. Match the style of the page.', 'nibwp')
        },
        {
            hit: /etch|component|loop|dynamic data|propert|slot|reusable|repeat/i, skill: 'etchwp-pro',
            label: __('Etch components', 'nibwp'),
            take: __('In Etch, anything you use twice becomes a component with properties, and lists come from a loop over real data, never copy and paste. Change it once and it changes everywhere.', 'nibwp'),
            ask: __('What repeats?', 'nibwp'),
            opts: [__('Cards or teasers', 'nibwp'), __('A section on several pages', 'nibwp'), __('A list of posts or products', 'nibwp'), __('Buttons', 'nibwp')],
            plan: __('1. Find every copy of it.\n2. One component, with properties for what changes.\n3. Lists through a loop on real data.\n4. Swap the copies for the component, then I inspect it.', 'nibwp'),
            /* translators: %s: what repeats, e.g. "Cards or teasers" */
            req: __('In Etch, turn the repeated parts on the page on screen (%s) into one reusable component with properties for what changes, feed lists from a loop over the real data instead of copies, keep BEM names and Automatic.css tokens, replace the copies with the component, and tell me what you made.', 'nibwp')
        },
        {
            hit: /post type|cpt|custom field|acf|meta box|taxonom|content model|directory|listing/i, skill: '',
            label: __('Custom content', 'nibwp'),
            take: __('Content with a shape deserves its own type: a custom post type, the fields it needs, and templates that read them. Editors fill in a form and the design never breaks.', 'nibwp'),
            ask: __('What’s the content?', 'nibwp'),
            opts: [__('Tours or products', 'nibwp'), __('Team members', 'nibwp'), __('Testimonials', 'nibwp'), __('Events', 'nibwp')],
            plan: __('1. Name the fields it really needs, nothing more.\n2. Register the post type and its fields.\n3. Templates for the list and the single page.\n4. A few real entries, then I inspect it.', 'nibwp'),
            /* translators: %s: the kind of content, e.g. "Team members" */
            req: __('Set up a content type for %s: register a custom post type with only the fields it needs (with the custom fields plugin this site uses), a list and a single template in the site’s builder that read those fields, and three sample entries. Tell me the fields and where to edit them.', 'nibwp')
        },
        {
            hit: /speed|slow|fast|performance|pagespeed|core web vitals|lcp|cls|cache|optimi[sz]/i, skill: '',
            label: __('Speed', 'nibwp'),
            take: __('Speed is a feature. Images sized and lazy, no plugin you don’t use, two fonts at most, and nothing blocking the first paint.', 'nibwp'),
            ask: __('What feels slow?', 'nibwp'),
            opts: [__('The whole site', 'nibwp'), __('Images', 'nibwp'), __('This page', 'nibwp'), __('The admin', 'nibwp')],
            plan: __('1. Measure first: what loads, how big, how long.\n2. Fix the biggest cost first.\n3. Measure again.\n4. I compare before and after.', 'nibwp'),
            /* translators: %s: what feels slow */
            req: __('Speed it up (%s): measure what loads and how long it takes, fix the biggest costs first (image sizes and lazy loading, unused plugins and scripts, fonts, render-blocking files), measure again, and give me the before and after.', 'nibwp')
        },
        {
            hit: /\bseo\b|search engine|google|meta description|title tag|ranking|schema/i, skill: 'seo-pro',
            label: __('SEO', 'nibwp'),
            take: __('Search engines read structure. One H1, a real title and description on every page, clean URLs, alt text, and content that answers the question people actually typed.', 'nibwp'),
            ask: __('Where do we start?', 'nibwp'),
            opts: [__('This page', 'nibwp'), __('Titles and descriptions', 'nibwp'), __('Heading structure', 'nibwp'), __('Images', 'nibwp')],
            plan: __('1. Check title, description, headings and alt text.\n2. Fix what’s missing or weak.\n3. Keep it human: written for people first.\n4. I inspect the result.', 'nibwp'),
            /* translators: %s: where to start */
            req: __('Improve the SEO (%s): one H1, a clear title under 60 characters and a description under 155, headings in order, descriptive alt text, a clean URL. Keep it written for people, and list every change.', 'nibwp')
        },
        {
            hit: /\bcode\b|php|javascript|\bjs\b|hook|filter|action|function|snippet|error|bug|query|plugin/i, skill: '',
            label: __('Code', 'nibwp'),
            take: __('Code goes in the right place, the WordPress way: a hook, escaped output, sanitized input, nonce and capability checks. Never pasted into a theme file the next update wipes out.', 'nibwp'),
            ask: __('What kind of job?', 'nibwp'),
            opts: [__('Add a feature', 'nibwp'), __('Fix an error', 'nibwp'), __('Review some code', 'nibwp'), __('Speed up a query', 'nibwp')],
            plan: __('1. Find the right hook and the right home for it.\n2. Write it to WordPress coding standards.\n3. Escape, sanitize, nonce, capability.\n4. Test it, then I review it line by line.', 'nibwp'),
            /* translators: %s: the kind of code job */
            req: __('Code job (%s): put it in the right place (a small plugin or the site’s snippets, never a theme file), use the right WordPress hook, follow the WordPress coding standards, escape output, sanitize input, check nonces and capabilities, test it, and explain what you did in plain words.', 'nibwp')
        }
    ];
    var KG_WHAT = [
        __('Tell me the job and I’ll take it from there. What are we working on?', 'nibwp'),
        __('Pick the job. I’ll tell you how a pro handles it, then we get it done.', 'nibwp')
    ];

    function kgxCoach(q, m) {
        var c = KG_CASES.filter(function (x) { return x.hit.test(q); })[0];
        var fields = c
            ? { thinking: false, text: c.take + '\n\n**' + c.ask + '**', actions: kgxCaseOpts(c) }
            : { thinking: false, text: pick(KG_WHAT), actions: KG_CASES.map(function (x) { return { label: x.label, run: function () { kgxCase(x); } }; }) };
        kgxUpdate(m, fields);
        gAct(c ? 'explain' : 'smug');
    }

    function kgxCaseOpts(c) {
        return c.opts.map(function (o) { return { label: o, run: function () { kgxCasePlan(c, o); } }; });
    }

    function kgxCase(c) {
        kgxAdd({ who: 'kg', source: c.label, text: c.take + '\n\n**' + c.ask + '**', actions: kgxCaseOpts(c) });
        gAct('explain');
    }

    // The plan, and the work handed over: a request the agent runs as written,
    // a chance to edit it first, and the NibWP skill or workflow that fits.
    function kgxCasePlan(c, o) {
        var req = sprintf(c.req, o);
        var acts = [{ label: __('Send it to the agent', 'nibwp'), request: req }, { label: __('Edit it first', 'nibwp'), fill: req }];
        var sk = c.skill && (boot.skills || []).filter(function (x) { return x.id === c.skill && x.ready; })[0];
        /* translators: %s: a NibWP skill name */
        if (sk) { acts.push({ label: sprintf(__('Add the %s skill', 'nibwp'), sk.name), skill: sk.id }); }
        var wf = kgxWorkflow(req);
        /* translators: %s: a NibWP workflow title */
        if (wf) { acts.push({ label: sprintf(__('Use “%s”', 'nibwp'), wf.title), attach: wf.slug }); }
        kgxAdd({
            who: 'kg',
            source: c.label + ' · ' + o,
            text: '**' + __('Here’s the plan.', 'nibwp') + '**\n' + c.plan +
                (agentFree ? '' : '\n\n' + __('No agent listening yet: the request waits in the thread and runs the moment one connects. Connect one and I can look at your site and answer anything.', 'nibwp')),
            actions: acts
        });
        gAct('brag');
    }

    // What NibWP can bring to the job, for the agent to pick from.
    function kgxPower() {
        var skills = (boot.skills || []).filter(function (x) { return x.ready; }).map(function (x) { return x.id + ' (' + x.name + ')'; });
        var flows = (boot.workflows || []).map(function (x) { return x.slug + ' (' + x.title + ')'; });
        return ('NibWP skills ready on this site: ' + (skills.join(', ') || 'none') + '\nNibWP workflows: ' + (flows.join(', ') || 'none')).slice(0, 2500);
    }

    // A question in his window: the agent's precise answer, or his guides.
    var kgHeld = null;
    function kgxAsk(q, moment, show, picked, where, noEcho) {
        if (!q) { return; }
        talk.push(['you', q]);
        talk = talk.slice(-6);
        var w = where || (kgChatIsOpen() ? 'box' : 'feed');
        var asked = String(show || q);
        if (w === 'box' && !noEcho) { kgxAdd({ who: 'you', where: 'box', text: asked }); }
        /* translators: %s: what the person asked Kind Guy */
        var m = kgxAdd({ who: 'kg', where: w, source: w === 'feed' ? sprintf(__('You asked: %s', 'nibwp'), asked.length > 90 ? asked.slice(0, 87) + '…' : asked) : '', text: __('Nibbing on it…', 'nibwp'), thinking: true });
        kgThink(true);
        if (!agentFree) {
            if (picked) { kgxUpdate(m, { thinking: false, text: __('I marked it on the page. Connect your agent and I can look inside it properly.', 'nibwp') }); return; }
            // Busy, not gone: he waits for the agent rather than reading out a guide.
            if (agentState === 'working') {
                // His take at once; the agent's full answer when it is free.
                setTimeout(function () {
                    /* translators: %s: the job the agent is working on */
                    var f = kgxQuick(q, m, w, jobNow ? sprintf(__('Your agent is busy with “%s”. I’ll add the full answer the moment it’s free.', 'nibwp'), jobClip(jobNow)) : __('Your agent is busy. I’ll add the full answer the moment it’s free.', 'nibwp'));
                    kgHeld = function () { kgxUpdate(f, { text: __('Checking your site for the full answer…', 'nibwp') }); kgxRun(q, moment, picked, f, w); };
                }, reduced ? 0 : KG_QUICK_MS);
                return;
            }
            kgThink(false);
            kgxCoach(q, m);
            return;
        }
        kgxRun(q, moment, picked, m, w, reduced ? 0 : KG_QUICK_MS);
    }

    // Never a wait in silence: a moment of Nibbing, then his take and what
    // you can do with it, at once. The agent's full answer lands under it.
    var KG_QUICK_MS = 900;
    function kgxQuick(q, m, w, wait) {
        var c = KG_CASES.filter(function (x) { return x.hit.test(q); })[0];
        var pass = { label: __('Pass it to the agent', 'nibwp'), request: q };
        kgxUpdate(m, c
            ? { thinking: false, source: c.label, text: c.take + '\n\n**' + c.ask + '**', actions: kgxCaseOpts(c).concat(pass) }
            : { thinking: false, text: __('On it. Here’s what we can do right now, while I check your site for the full answer:', 'nibwp'), actions: [pass], acts: [[__('Point at something', 'nibwp'), 'kgx-point'], [__('Inspect this page', 'nibwp'), 'kgx-inspect']] });
        gAct(c ? 'explain' : 'smug');
        return kgxAdd({ who: 'kg', where: w, thinking: true, text: wait || __('Checking your site for the full answer…', 'nibwp') });
    }

    function kgxRun(q, moment, picked, m, w, quickMs) {
        kgThink(true);
        var answered = false;
        var follow = null;
        if (quickMs != null) {
            setTimeout(function () { if (!answered) { follow = kgxQuick(q, m, w); } }, quickMs);
        }
        expertCall(moment || 'max-chat', 'The person asks Kind Guy: ' + q + (picked ? '\nThe element they pointed at (picked): ' + JSON.stringify(picked) : '') + '\n' + kgxPower()).then(function (res) {
            answered = true;
            if (!res) {
                kgThink(false);
                if (follow) { kgxUpdate(follow, { thinking: false, text: __('That’s my take for now. Pass it to the agent and it gets done properly.', 'nibwp') }); } else { kgxCoach(q, m); }
                return;
            }
            talk.push(['nib', res.text]);
            talk = talk.slice(-6);
            if (follow) { m = follow; }
            kgxUpdate(m, { thinking: false, text: res.text, actions: res.actions });
            if (w === 'feed') { kgxOpen(); }
            var at = res.point || (res.actions.filter(function (a) { return a.selector; })[0] || {}).selector || (picked && picked.selector);
            // He walks over and outlines it; the words stay in one place, his message.
            if (at && !kgWalk && !kgPicking) { kgPoint(at, { quiet: true }); }
            kgLips(Math.min(3000, 500 + res.text.length * 20));
        });
    }

    // Which NibWP skill fits what the person is doing: the agent's view when one
    // listens, otherwise a match on their request against the skills' taglines.
    function kgxSkill() {
        var text = (kgxInput && kgxInput.value.trim()) || lastAsk || '';
        if (agentFree) {
            kgxAsk(__('Which NibWP skills would help with what I’m doing on this page? Name them and say in plain words what each would do for me.', 'nibwp') + (text ? '\n' + text : ''), 'max-chat', __('Which skill helps?', 'nibwp'));
            return;
        }
        var words = text.toLowerCase().split(/[^a-z0-9]+/).filter(function (w) { return w.length >= 4 && !KGX_STOP.test(w); });
        var ready = (boot.skills || []).filter(function (x) { return x.ready; });
        var best = ready.map(function (x) {
            var hay = (x.name + ' ' + x.tagline + ' ' + x.id).toLowerCase();
            return { s: x, n: words.filter(function (w) { return hay.indexOf(w) > -1; }).length };
        }).filter(function (x) { return x.n; }).sort(function (a, b) { return b.n - a.n; }).slice(0, 2);
        if (best.length) {
            best.forEach(function (b) {
                kgxAdd({ who: 'kg', text: sprintf(__('“%1$s”: %2$s', 'nibwp'), b.s.name, b.s.tagline || ''), actions: [{ label: __('Use it', 'nibwp'), skill: b.s.id }] });
            });
            return;
        }
        kgxAdd({ who: 'kg', text: text ? __('No skill fits that one exactly. Tell me more and I’ll plan it with you.', 'nibwp') : __('Type what you want in the message box first, then ask me again. I’ll match it to a skill.', 'nibwp') });
    }

    // The NibWP workflow a request reads like, if one clearly fits.
    function kgxWorkflow(text) {
        var words = String(text || '').toLowerCase().split(/[^a-z0-9]+/).filter(function (w) { return w.length >= 4 && !KGX_STOP.test(w); });
        if (words.length < 2) { return null; }
        var best = null;
        (boot.workflows || []).forEach(function (wf) {
            var title = String(wf.title || '').toLowerCase();
            var all = title + ' ' + String(wf.summary || '').toLowerCase() + ' ' + String(wf.slug || '').replace(/-/g, ' ');
            var hits = 0;
            var score = 0;
            words.forEach(function (w) {
                if (title.indexOf(w) > -1) { score += 3; hits++; } else if (all.indexOf(w) > -1) { score += 1; hits++; }
            });
            if (hits >= 2 && score >= 4 && (!best || score > best.score)) { best = { wf: wf, score: score }; }
        });
        return best ? best.wf : null;
    }

    function kgxAttach(slug) {
        var bridge = window.nibwpStudioBridge;
        if (bridge && bridge.attachWorkflow) { bridge.attachWorkflow(slug); }
    }

    // Read a review: the verdict line, ✓ and ✗ lines, and the rest.
    function kgxParse(text) {
        var lines = String(text || '').split(/\n+/).map(function (l) { return l.trim(); }).filter(Boolean);
        var verdict = /ship it/i.test(lines[0] || '') ? 'ship' : /needs work/i.test(lines[0] || '') ? 'work' : 'almost';
        var checks = [];
        var rest_ = [];
        lines.slice(1).forEach(function (l) {
            var m = l.match(/^[-•*\s]*([✓✔✅☑]|[✗✘❌×])\s*(.+)$/);
            if (m) { checks.push({ ok: /[✓✔✅☑]/.test(m[1]), text: m[2] }); } else { rest_.push(l); }
        });
        if (!checks.length && verdict === 'almost' && /ship it/i.test(text)) { verdict = 'ship'; }
        return { verdict: verdict, checks: checks, text: rest_.join('\n') };
    }

    // The scan: a beam sweeping over the page while he looks.
    var scanEl = null;
    function kgxScan(on) {
        var stage = document.getElementById('nst-stage');
        if (!stage) { return; }
        if (on) {
            if (!scanEl) {
                scanEl = document.createElement('div');
                scanEl.className = 'nst-kgx-scan';
                scanEl.setAttribute('aria-hidden', 'true');
                scanEl.innerHTML = '<i></i><span>' + esc(__('Kind Guy is inspecting…', 'nibwp')) + '</span>';
            }
            stage.appendChild(scanEl);
            // He goes over to the page while he inspects it.
            var c = (document.getElementById('nst-canvas') || stage).getBoundingClientRect();
            if (!kgpAt) { kgWalkTo(c.right - 150, c.top + 24); }
            return;
        }
        if (scanEl) { scanEl.remove(); }
        if (!kgpAt) { kgWalkHome(); }
    }

    function kgxDuration(ms) {
        var min = Math.max(1, Math.round(ms / 60000));
        /* translators: %d: minutes */
        return ms < 60000 ? __('under a minute', 'nibwp') : sprintf(_n('%d minute', '%d minutes', min, 'nibwp'), min);
    }

    // After a big win, now and then: the NibWP word. Not on a renamed
    // install, not in the first visits, at most once in two days.
    function kgxBrag(task, verdict) {
        if (!cfg.promote || mem.promoOff || mem.visits < 2 || Date.now() - (mem.kgBragAt || 0) < 2 * DAY) { return; }
        if (verdict !== 'ship' && task.saves < 2) { return; }
        mem.kgBragAt = Date.now();
        save();
        var changes = task.saves > 1
            /* translators: %d: number of changes */
            ? sprintf(_n('%d change', '%d changes', task.saves, 'nibwp'), task.saves)
            : __('One clean change', 'nibwp');
        var line = sprintf(pick(KGX_BRAG), changes, kgxDuration(Date.now() - task.at));
        // After the verdict has had its moment, and not over the person's own typing.
        (function brag(tries) {
            setTimeout(function () {
                if (guest !== 'on') { return; }
                if ((Date.now() - lastTyped < 4000 || (current && current.isAdvice)) && tries < 6) { brag(tries + 1); return; }
                gAct('brag');
                maxSay(line, [[__('Share the win', 'nibwp'), 'kgx-share'], [__('Ha, fair', 'nibwp'), 'close']], { act: 'keep', stay: 16000 });
            }, tries ? 5000 : 9000);
        }(0));
    }

    // Applause, Kind Guy style: grudging, and loud about it.
    function kgxApplaud(acts) {
        gAct('clap');
        maxSay(pick(KGX_CLAP), acts || [], { act: 'keep', stay: acts ? 12000 : 7000 });
        kgxLikeLater();
    }

    // Now and then, after a good moment: does the person like him?
    function kgxLikeLater() {
        if (Date.now() - (mem.kgLikeAt || 0) < 3 * DAY || mem.visits < 2) { return; }
        (function ask(tries) {
            setTimeout(function () {
                if (guest !== 'on' || Date.now() - (mem.kgLikeAt || 0) < 3 * DAY) { return; }
                if (!canTalk(9000) || !bubble.hidden) { if (tries < 5) { ask(tries + 1); } return; }
                mem.kgLikeAt = Date.now();
                save();
                gAct('smug');
                maxSay(pick(KGX_LIKE_Q), [[__('Love you, Kind Guy', 'nibwp'), 'kg-like-yes'], [__('You’re okay', 'nibwp'), 'kg-like-meh'], [__('Not really', 'nibwp'), 'kg-like-no']], { act: 'keep', stay: 22000 });
            }, tries ? 7000 : 14000);
        }(0));
    }

    // Every finished task, while he is on duty: inspected.
    function kgxReview(replyText) {
        var task = inspecting;
        inspecting = null;
        if (!task || duty !== 'on' || guest !== 'on') { return; }
        kgxStats.inspected++;
        if (!agentFree) {
            maxSay(__('Done? I’d inspect it, but your agent went quiet. No eyes, no inspection.', 'nibwp'), [], { act: 'huff', stay: 6000 });
            return;
        }
        kgxScan(true);
        gAct('explain');
        maxSay(pick(KGX_SCAN), [], { act: 'keep', stay: 6000 });
        var extra = 'The request: ' + task.text + '\nThe agent answered: ' + String(replyText || '').slice(0, 900) + '\nChanges saved: ' + task.saves;
        expertCall('max-review', extra, 120000).then(function (res) {
            kgxScan(false);
            if (!res) {
                maxSay(__('Couldn’t get a proper look this time. Ask me to inspect the page when you’re ready.', 'nibwp'), [[__('Inspect this page', 'nibwp'), 'kgx-inspect']], { act: 'huff', stay: 9000 });
                return;
            }
            var rep = kgxParse(res.text);
            kgxStats.fixes += (res.actions || []).length;
            kgxAdd({ who: 'report', where: 'feed', verdict: rep.verdict, checks: rep.checks, text: rep.text, actions: res.actions });
            var say_ = rep.verdict === 'ship'
                ? __('Inspected. Ship it. I don’t say that often.', 'nibwp')
                : rep.verdict === 'work'
                    ? __('Inspected. It needs work. I’ve listed the fixes. One click each.', 'nibwp')
                    : __('Inspected. Almost there. A couple of fixes and it’s mine-level.', 'nibwp');
            if (rep.verdict === 'ship') {
                kgxApplaud([[__('See the report', 'nibwp'), 'kgx-open'], [__('Later', 'nibwp'), 'close']]);
            } else {
                maxSay(say_, [[__('See the report', 'nibwp'), 'kgx-open'], [__('Later', 'nibwp'), 'close']], { act: 'glare', stay: 12000 });
            }
            kgxBrag(task, rep.verdict);
        });
    }

    // Before a request goes, while on duty: a workflow that fits, what is missing.
    // Said once per request: each workflow, and the checks reminder. A new
    // request (sent) starts afresh.
    var adviceFor = '';
    var advised = {};
    var adviceT = 0;
    function kgxAdvise(text) {
        if (guest !== 'on' || duty !== 'on' || text.length < 25 || text === adviceFor) { return; }
        // Not over his own thinking or a question he is waiting on.
        if (current && (current.thinking || current.isAdvice)) { return; }
        var draftFlows = document.querySelectorAll('#nst-chips [data-drop="wf"]').length;
        var wf = draftFlows ? null : kgxWorkflow(text);
        if (wf && advised['wf:' + wf.slug]) { wf = null; }
        var builds = /\b(build|create|add|design|redesign|section|page|hero|layout|landing|component|header|footer|grid|cards?)\b/i.test(text);
        var covers = /\b(mobile|phone|tablet|responsive|breakpoint|accessib|a11y|contrast|keyboard|focus)\b/i.test(text);
        if (!wf && (!builds || covers || advised.checks)) { return; }
        adviceFor = text;
        advised[wf ? 'wf:' + wf.slug : 'checks'] = true;
        var acts = [];
        var line;
        if (wf) {
            /* translators: %s: a workflow title */
            line = sprintf(__('Hold on. This is the “%s” workflow’s job. It knows the steps. Attach it?', 'nibwp'), wf.title);
            acts.push([__('Attach the workflow', 'nibwp'), 'kgx-wf:' + wf.slug]);
        } else {
            line = __('Hold on. You didn’t say phone, tablet or accessibility. I’ll check them anyway, but say it and you get it built right the first time.', 'nibwp');
            acts.push([__('Add those checks', 'nibwp'), 'kgx-checks']);
        }
        if (agentFree) { acts.push([__('Full advice', 'nibwp'), 'kgx-advice']); }
        acts.push([__('Send as is', 'nibwp'), 'close']);
        maxSay(line, acts, { act: 'glare', stay: 16000, isAdvice: true });
    }

    function kgxSummon() {
        if (guest === 'on') { maxSay(kgPick(MAX_POKE), maxActs(), { isMenu: true, stay: 25000, act: 'smug' }); return; }
        if (guest) { return; }
        summoned = true;
        var go = function () { callMax(true); };
        if (!mem.on) { setOn(true); setTimeout(go, reduced ? 0 : 1400); } else { go(); }
    }

    /* ------------------------------------------------------------------
     * Kind Guy points, both ways. He puts his hand on the element he means,
     * highlights it and says his piece beside it, with the code when that
     * helps, and asks before anything changes. The person can point too:
     * click anything on the page and pick what he should do with it.
     * ---------------------------------------------------------------- */

    var kgp = document.createElement('div');
    kgp.className = 'nst-kgp';
    kgp.hidden = true;
    kgp.innerHTML = '<div class="nst-kgp__box"></div>' +
        '<div class="nst-kgp__tip" role="status"></div>' +
        '<div class="nst-kgp__card" role="dialog" aria-label="' + esc(__('Kind Guy shows you', 'nibwp')) + '">' +
            '<div class="nst-kgp__who"><i></i><b>' + esc(__('Kind Guy', 'nibwp')) + '</b><em></em></div>' +
            '<div class="nst-kgp__text"></div>' +
            '<pre class="nst-kgp__code" hidden></pre>' +
            '<div class="nst-kgp__acts"></div>' +
        '</div>';
    host.appendChild(kgp);
    var kgpBox = kgp.querySelector('.nst-kgp__box');
    var kgpCard = kgp.querySelector('.nst-kgp__card');
    var kgpTip = kgp.querySelector('.nst-kgp__tip');
    var kgpAt = null;
    var kgpRaf = 0;
    var kgpBtns = [];
    var kgWalk = null;
    var kgPicking = null;

    function kgFrame() {
        var f = window.nibwpStudioBridge && window.nibwpStudioBridge.frame;
        try { return f && f.contentDocument && f.contentDocument.body ? f : null; } catch (e) { return null; }
    }

    // Where an element of the page sits on screen, the frame's scale included.
    function kgRect(f, node) {
        var fr = f.getBoundingClientRect();
        var k = f.offsetWidth ? fr.width / f.offsetWidth : 1;
        var r = node.getBoundingClientRect();
        return { left: fr.left + r.left * k, top: fr.top + r.top * k, width: r.width * k, height: r.height * k, frame: fr };
    }

    function kgVisible(node) {
        var r = node.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) { return false; }
        var cs = node.ownerDocument.defaultView.getComputedStyle(node);
        return cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
    }

    // A selector for an element: its id, or a short path of tags, classes and positions.
    function kgSel(node) {
        var doc = node.ownerDocument;
        var ok = function (id) { return /^[A-Za-z][\w-]*$/.test(id); };
        if (node.id && ok(node.id) && doc.querySelectorAll('#' + node.id).length === 1) { return '#' + node.id; }
        var parts = [];
        for (var n = node; n && n.nodeType === 1 && n !== doc.body && n !== doc.documentElement && parts.length < 6; n = n.parentElement) {
            if (n.id && ok(n.id)) { parts.unshift('#' + n.id); break; }
            var p = n.tagName.toLowerCase();
            var cls = Array.prototype.filter.call(n.classList, function (c) { return /^[A-Za-z_][\w-]*$/.test(c); }).slice(0, 2);
            if (cls.length) { p += '.' + cls.join('.'); }
            var same = n.parentElement ? Array.prototype.filter.call(n.parentElement.children, function (c) { return c.tagName === n.tagName; }) : [];
            if (same.length > 1) { p += ':nth-of-type(' + (same.indexOf(n) + 1) + ')'; }
            parts.unshift(p);
            try { if (doc.querySelectorAll(parts.join(' > ')).length === 1) { break; } } catch (e) { break; }
        }
        return parts.join(' > ');
    }

    // The element's markup, short enough to read: the opening tag lit up.
    function kgCode(node) {
        var html = node.outerHTML.replace(/\s+/g, ' ').trim();
        var open = html.slice(0, html.indexOf('>') + 1);
        if (html.length > 420) {
            var tag = node.tagName.toLowerCase();
            html = open + ' … </' + tag + '>';
            if (html.length > 420) { html = html.slice(0, 417) + '…'; }
        }
        var rest = html.slice(open.length);
        return '<mark>' + esc(open.length > 380 ? open.slice(0, 377) + '…' : open) + '</mark>' + esc(rest);
    }

    function kgpPlace() {
        kgpRaf = 0;
        if (kgp.hidden || !kgpAt) { return; }
        var f = kgpAt.frame;
        if (!f.isConnected || !kgpAt.node.isConnected) { kgpHide(); return; }
        var r = kgRect(f, kgpAt.node);
        var fr = r.frame;
        var L = Math.max(fr.left, r.left) - 4;
        var T = Math.max(fr.top, r.top) - 4;
        var R = Math.min(fr.right, r.left + r.width) + 4;
        var B = Math.min(fr.bottom, r.top + r.height) + 4;
        if (R - L < 8 || B - T < 8) { R = L + 8; B = T + 8; }
        kgpBox.style.left = L + 'px';
        kgpBox.style.top = T + 'px';
        kgpBox.style.width = (R - L) + 'px';
        kgpBox.style.height = (B - T) + 'px';
        if (!kgpCard.hidden) {
            var cw = kgpCard.offsetWidth;
            var ch = kgpCard.offsetHeight;
            var top = B + 10;
            if (top + ch > window.innerHeight - 8) { top = T - ch - 10; }
            if (top < 8) { top = Math.max(8, Math.min(window.innerHeight - ch - 8, T + 12)); }
            kgpCard.style.top = top + 'px';
            kgpCard.style.left = Math.max(8, Math.min(window.innerWidth - cw - 8, L)) + 'px';
        }
        kgpRaf = requestAnimationFrame(kgpPlace);
    }

    function kgpTrack() {
        if (!kgpRaf) { kgpRaf = requestAnimationFrame(kgpPlace); }
    }

    function kgpHide() {
        if (kgpAt) { kgWalkHome(); }
        kgp.hidden = true;
        kgpAt = null;
        if (kgpRaf) { cancelAnimationFrame(kgpRaf); kgpRaf = 0; }
    }

    // He walks over to what he shows, and back to his spot afterwards.
    var kgHome = null;
    var kgWalking = false;
    function kgWalkTo(x, y) {
        if (guest !== 'on') { return; }
        var r0 = gEl.getBoundingClientRect();
        if (!kgHome) { kgHome = { x: r0.left, y: r0.top, fixed: gEl.style.position === 'fixed' }; }
        kgWalking = true;
        if (gEl.style.position !== 'fixed') { kgPlace(r0.left, r0.top); void gEl.offsetWidth; }
        gEl.classList.add('is-walking');
        clearTimeout(kgWalkHome.t);
        gAct('jump');
        kgPlace(x, y);
    }
    function kgWalkNear(r) {
        var w = 112;
        var x = r.left + r.width + 16;
        if (x + w > window.innerWidth - 8) { x = r.left - w - 16; }
        if (x < 8) { x = Math.min(window.innerWidth - w - 8, r.left + r.width - w); }
        kgWalkTo(x, r.top + Math.min(r.height, 120) / 2 - 70);
    }
    function kgWalkHome() {
        clearTimeout(kgWalkHome.t);
        var h = kgHome;
        kgHome = null;
        if (!h || guest !== 'on') { kgWalking = false; gEl.classList.remove('is-walking'); return; }
        gEl.classList.add('is-walking');
        kgPlace(h.x, h.y);
        kgWalkHome.t = setTimeout(function () {
            kgWalking = false;
            gEl.classList.remove('is-walking');
            if (!h.fixed && !mem.kgPos) {
                gEl.style.position = gEl.style.left = gEl.style.top = gEl.style.bottom = '';
                kgSide();
            }
            aim();
        }, reduced ? 0 : 850);
    }

    // His hand on an element: a selector or the element itself. opts: text,
    // actions [{label, request, selector, connect}], code, step, intents, onDone.
    function kgPoint(target, opts) {
        opts = opts || {};
        var f = kgFrame();
        if (!f) { return false; }
        var node = target;
        if (typeof target === 'string') {
            try { node = f.contentDocument.querySelector(target); } catch (e) { node = null; }
        }
        if (!node || !node.isConnected || !kgVisible(node)) { return false; }
        hush();
        kgxModes(false);
        try { node.scrollIntoView({ block: 'center', inline: 'nearest', behavior: reduced ? 'auto' : 'smooth' }); } catch (e) { node.scrollIntoView(); }
        kgpAt = { frame: f, node: node, sel: typeof target === 'string' ? target : kgSel(node), opts: opts };
        kgp.classList.remove('is-picking');
        kgp.hidden = false;
        kgpTip.hidden = true;
        setTimeout(function () { if (kgpAt && kgpAt.node === node) { kgWalkNear(kgRect(f, node)); } }, reduced ? 0 : 380);
        clearTimeout(kgPoint.t);
        if (opts.quiet) {
            kgpCard.hidden = true;
            kgpTrack();
            kgPoint.t = setTimeout(kgpHide, 9000);
            return true;
        }
        kgpCard.hidden = false;
        kgpCard.querySelector('.nst-kgp__who i').innerHTML = kgFace(duty);
        kgpCard.querySelector('.nst-kgp__who em').textContent = opts.step || '';
        kgpCard.querySelector('.nst-kgp__text').innerHTML = kgxMd(opts.text || '');
        var code = kgpCard.querySelector('.nst-kgp__code');
        code.hidden = !opts.code;
        if (opts.code) { code.innerHTML = kgCode(node); }
        kgpButtons(opts);
        gAct(pick(['glare', 'smug']));
        kgpTrack();
        setTimeout(function () { var b = kgpCard.querySelector('.nst-kgp__acts button'); if (b && !kgp.hidden) { b.focus({ preventScroll: true }); } }, 60);
        return true;
    }

    function kgpButtons(opts) {
        kgpBtns = [];
        var html = '';
        var add = function (label, run, main, quiet) {
            kgpBtns.push(run);
            html += '<button type="button"' + (main ? ' class="is-main"' : quiet ? ' class="is-quiet"' : '') + ' data-kgp="' + (kgpBtns.length - 1) + '">' + esc(label) + '</button>';
        };
        if (opts.intents) {
            KGP_JOBS.forEach(function (job, i) { add(job[0], function () { kgSendJob(job); }, !i); });
            add(__('Inspect it', 'nibwp'), kgInspectPicked, false, true);
            add(__('Something else…', 'nibwp'), kgSomethingElse, false, true);
            add(__('Show the code', 'nibwp'), function () {
                var code = kgpCard.querySelector('.nst-kgp__code');
                code.hidden = !code.hidden;
                if (!code.hidden) { code.innerHTML = kgCode(kgpAt.node); }
            }, false, true);
        }
        (opts.actions || []).forEach(function (a, i) {
            add(a.label, function () {
                if (a.connect) { var dlg = document.getElementById('nst-connect'); if (dlg && dlg.showModal) { dlg.showModal(); } return; }
                var sel = a.selector || kgpAt.sel;
                doTask(a.request + (sel && a.request.indexOf(sel) < 0 ? '\n\n' + sprintf(__('The element: %s', 'nibwp'), sel) : ''));
                if (kgWalk) { kgWalkNext(); return; }
                kgSent();
            }, !i && !opts.intents);
        });
        if (opts.next) {
            add(kgWalk && kgWalk.i < kgWalk.list.length - 1 ? __('Next', 'nibwp') : __('Done', 'nibwp'), kgWalkNext, !(opts.actions || []).length);
        }
        add(opts.next ? __('Stop', 'nibwp') : opts.intents ? __('Cancel', 'nibwp') : (opts.actions || []).length ? __('No thanks', 'nibwp') : __('Got it', 'nibwp'), function () {
            kgWalk = null;
            kgpHide();
            if ((opts.actions || []).length && !opts.next) { gAct('huff'); }
        });
        kgpCard.querySelector('.nst-kgp__acts').innerHTML = html;
    }

    kgpCard.addEventListener('click', function (e) {
        var b = e.target.closest('[data-kgp]');
        if (b && kgpBtns[+b.dataset.kgp]) { kgpBtns[+b.dataset.kgp](); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !kgp.hidden && !kgPicking) { kgWalk = null; kgpHide(); }
    });

    // What to do with something pointed at: real work, sent to the agent as a
    // precise request with the element attached. %s is the element.
    var KGP_JOBS = [
        /* translators: %s: the element, e.g. the nav "Home Tours Contact" (header > nav) */
        [__('Fix it on phones', 'nibwp'), __('Make %s work well on phones and tablets: no sideways scrolling, tap targets at least 44px, text readable without zooming, a layout that stacks cleanly. Keep how it looks on desktop. Tell me what you changed.', 'nibwp')],
        /* translators: %s: the element */
        [__('Fix accessibility', 'nibwp'), __('Make %s accessible to WCAG AA: enough contrast, a clear name or label for every control, a visible focus state, usable with the keyboard alone, correct heading and landmark structure. Keep the design. List each fix.', 'nibwp')],
        /* translators: %s: the element */
        [__('Use the design system', 'nibwp'), __('Clean up %s: replace hard-coded colours, font sizes and spacing with the site’s design tokens (Automatic.css variables when the site uses them, with fallbacks), clear BEM class names, no inline styles or !important. Keep the look the same. Tell me what you replaced.', 'nibwp')],
        /* translators: %s: the element */
        [__('Tighten the spacing', 'nibwp'), __('Fix the spacing of %s: consistent padding, gaps and margins from the site’s spacing scale (Automatic.css tokens when available), even at every screen size. Keep the design. Tell me what changed.', 'nibwp')],
        /* translators: %s: the element */
        [__('Explain it', 'nibwp'), __('Explain %s in plain words: what it is, how it is built (builder, template or code), and what to watch before changing it. Change nothing.', 'nibwp')]
    ];

    // The element, in words the agent can find it by.
    function kgDescribe(node) {
        var info = kgPickedInfo(node);
        /* translators: 1: tag name, 2: its text, 3: a CSS selector */
        return info.text
            ? sprintf(__('the <%1$s> “%2$s” (%3$s)', 'nibwp'), info.tag, info.text.slice(0, 70), info.selector)
            : sprintf(__('the <%1$s> at %2$s', 'nibwp'), info.tag, info.selector);
    }

    function kgSendJob(job) {
        var node = kgpAt && kgpAt.node;
        if (!node || !node.isConnected) { kgpHide(); return; }
        doTask(sprintf(job[1], kgDescribe(node)));
        kgSent();
    }

    // The card says where it went, then steps aside.
    function kgSent() {
        kgpCard.querySelector('.nst-kgp__text').textContent = agentFree
            ? __('Sent to your agent. Follow it in the chat; I’ll inspect it when it’s done.', 'nibwp')
            : __('Sent. It waits in the chat and runs as soon as your agent listens again.', 'nibwp');
        kgpCard.querySelector('.nst-kgp__acts').innerHTML = '';
        kgpCard.querySelector('.nst-kgp__code').hidden = true;
        gAct('jump');
        setTimeout(kgpHide, 2200);
    }

    // Something else: the element goes in the message box, the person says what.
    function kgSomethingElse() {
        var node = kgpAt && kgpAt.node;
        var box = document.getElementById('nst-input');
        if (!node || !box) { kgpHide(); return; }
        /* translators: %s: the element */
        box.value = sprintf(__('About %s: ', 'nibwp'), kgDescribe(node));
        box.dispatchEvent(new Event('input', { bubbles: true }));
        kgpHide();
        box.focus();
        box.setSelectionRange(box.value.length, box.value.length);
    }

    // His own look at the element, at once and without the agent.
    function kgInspectPicked() {
        var node = kgpAt && kgpAt.node;
        if (!node || !node.isConnected) { kgpHide(); return; }
        var found = kgLook(node, 4);
        if (!found.length) {
            kgpCard.querySelector('.nst-kgp__text').textContent = __('Nothing obviously wrong at first look: labels, descriptions, headings and sizes are fine. Pick a job above to go further.', 'nibwp');
            return;
        }
        kgPoint(found[0].node, {
            text: found[0].text + (found.length > 1 ? '\n' + sprintf(_n('And %d more thing in there.', 'And %d more things in there.', found.length - 1, 'nibwp'), found.length - 1) : ''),
            code: true,
            actions: [{ label: __('Fix it', 'nibwp'), request: found.length > 1 ? found.map(function (x) { return '- ' + x.request; }).join('\n') : found[0].request }]
        });
    }

    // The person points: hover lights up what is under the cursor, a click picks it.
    function kgPick() {
        var f = kgFrame();
        if (!f) {
            kgxAdd({ who: 'kg', text: __('Open a page first, then point at something on it.', 'nibwp') });
            return;
        }
        if (kgPicking) { kgPicking.stop(); }
        kgWalk = null;
        var doc = f.contentDocument;
        hush();
        kgp.hidden = false;
        kgp.classList.add('is-picking');
        kgpCard.hidden = true;
        kgpTip.hidden = false;
        kgpTip.textContent = __('Click the thing you mean. Esc to cancel.', 'nibwp');
        var fr = f.getBoundingClientRect();
        kgpTip.style.left = (fr.left + fr.width / 2) + 'px';
        kgpTip.style.top = (fr.top + 10) + 'px';
        kgpBox.style.width = '0px';
        var cursor = doc.documentElement.style.cursor;
        doc.documentElement.style.cursor = 'crosshair';
        var move = function (e) {
            var t = e.target;
            if (!t || t.nodeType !== 1 || t === doc.body || t === doc.documentElement) { return; }
            kgpAt = { frame: f, node: t, sel: '', opts: {} };
            kgpTrack();
        };
        var stopIt = function (e) { e.preventDefault(); e.stopPropagation(); };
        var click = function (e) {
            stopIt(e);
            var t = e.target;
            if (!t || t.nodeType !== 1 || t === doc.documentElement) { return; }
            stop();
            kgPicked(t);
        };
        var key = function (e) { if (e.key === 'Escape') { stop(); kgpHide(); } };
        var stop = function () {
            doc.removeEventListener('mouseover', move, true);
            doc.removeEventListener('click', click, true);
            doc.removeEventListener('mousedown', stopIt, true);
            doc.removeEventListener('keydown', key, true);
            document.removeEventListener('keydown', key, true);
            doc.documentElement.style.cursor = cursor;
            kgPicking = null;
            kgp.classList.remove('is-picking');
            kgpTip.hidden = true;
        };
        doc.addEventListener('mouseover', move, true);
        doc.addEventListener('click', click, true);
        doc.addEventListener('mousedown', stopIt, true);
        doc.addEventListener('keydown', key, true);
        document.addEventListener('keydown', key, true);
        kgPicking = { stop: stop };
        gAct('smug');
    }

    var kgPickedNode = null;
    function kgPicked(node) {
        kgPickedNode = node;
        kgPoint(node, {
            text: pick([
                __('This one? Good eye. What should I do with it?', 'nibwp'),
                __('Got it. Pick what you want and I’ll handle the thinking.', 'nibwp'),
                __('That one. Tell me what you’re after.', 'nibwp')
            ]),
            intents: true
        });
    }

    // What the person picked, for the agent: where it is, what it says, its markup.
    function kgPickedInfo(node) {
        var text = (node.innerText || node.textContent || '').replace(/\s+/g, ' ').trim();
        return {
            selector: kgSel(node),
            tag: node.tagName.toLowerCase(),
            text: text.length > 160 ? text.slice(0, 157) + '…' : text,
            html: node.outerHTML.replace(/\s+/g, ' ').slice(0, 900)
        };
    }

    // A quick look he can do himself: real, plain-language issues, each on its element.
    function kgLook(root, max) {
        var doc = root.ownerDocument;
        var win = doc.defaultView;
        var out = [];
        var kinds = {};
        var add = function (kind, node, text, request) {
            if (out.length >= (max || 6) || (kinds[kind] || 0) >= 2 || !kgVisible(node)) { return; }
            kinds[kind] = (kinds[kind] || 0) + 1;
            var sel = kgSel(node);
            out.push({ node: node, text: text, request: sprintf(request, sel) });
        };
        var named = function (n) {
            if (n.getAttribute('aria-label') || n.getAttribute('aria-labelledby') || n.getAttribute('title')) { return true; }
            if ((n.textContent || '').trim()) { return true; }
            return Array.prototype.some.call(n.querySelectorAll('img[alt], svg title'), function (x) { return (x.getAttribute('alt') || x.textContent || '').trim(); });
        };
        var all = function (q) { return Array.prototype.slice.call(root.matches && root.matches(q) ? [root] : []).concat(Array.prototype.slice.call(root.querySelectorAll(q))); };
        all('img:not([alt])').forEach(function (n) {
            if (n.getAttribute('aria-hidden') === 'true' || n.getAttribute('role') === 'presentation') { return; }
            add('alt', n, __('This picture has no description. Screen readers skip it, and search engines can’t tell what it shows.', 'nibwp'), __('Add a short, descriptive alt text to the image %s.', 'nibwp'));
        });
        all('a[href], button, [role="button"]').forEach(function (n) {
            if (!named(n)) { add('name', n, __('This button says nothing. Someone using a screen reader just hears “button”, with no idea what it does.', 'nibwp'), __('Give %s a name people can hear: visible text or an aria-label that says what it does.', 'nibwp')); }
        });
        all('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea').forEach(function (n) {
            var labelled = n.getAttribute('aria-label') || n.getAttribute('aria-labelledby') || n.getAttribute('title') || n.closest('label') || (n.id && doc.querySelector('label[for="' + n.id.replace(/"/g, '') + '"]'));
            if (!labelled) { add('label', n, __('This field has no label. People can’t be sure what to type, and screen readers can’t tell them.', 'nibwp'), __('Add a visible label to the field %s.', 'nibwp')); }
        });
        var prev = 0;
        all('h1, h2, h3, h4, h5, h6').forEach(function (n) {
            var lv = +n.tagName.slice(1);
            if (prev && lv > prev + 1) {
                add('heading', n, sprintf(__('The headings jump from H%1$d to H%2$d here. Like a book skipping chapter numbers: confusing for screen readers and search engines.', 'nibwp'), prev, lv), __('Fix the heading levels around %s so none are skipped, without changing how they look.', 'nibwp'));
            }
            prev = lv;
        });
        var width = doc.documentElement.clientWidth;
        var scan = Array.prototype.slice.call(root.querySelectorAll('*'), 0, 2500);
        scan.some(function (n) {
            var r = n.getBoundingClientRect();
            var pr = n.parentElement ? n.parentElement.getBoundingClientRect() : null;
            if (r.right > width + 2 && r.width > 20 && pr && pr.right <= width + 2) {
                add('overflow', n, __('This sticks out past the edge of the page. On phones that means annoying sideways scrolling.', 'nibwp'), __('Stop %s from running past the page width at every screen size.', 'nibwp'));
                return true;
            }
            return false;
        });
        scan.some(function (n) {
            if (!Array.prototype.some.call(n.childNodes, function (c) { return c.nodeType === 3 && c.textContent.trim().length > 3; })) { return false; }
            var size = parseFloat(win.getComputedStyle(n).fontSize);
            if (size && size < 12) {
                add('tiny', n, sprintf(__('This text is tiny (%dpx). Hard to read, especially on phones.', 'nibwp'), Math.round(size)), __('Make the text in %s easier to read: at least 14px, using the site’s own type sizes.', 'nibwp'));
                return true;
            }
            return false;
        });
        return out;
    }

    function kgxInspect() {
        hush();
        if (agentFree) { kgxAsk(__('Inspect the page on screen: code quality, standards, tokens and naming, accessibility, responsiveness and UX. What would you fix first?', 'nibwp'), 'max-chat', __('Inspect this page', 'nibwp'), null, 'feed'); }
        kgLookWalk();
    }

    // Walk through what the quick look found, one element at a time.
    function kgLookWalk() {
        var f = kgFrame();
        if (!f) { return 0; }
        var list = kgLook(f.contentDocument.body, 6);
        if (!list.length) {
            kgxAdd({ who: 'kg', where: 'feed', text: __('Quick look first: nothing obvious jumps out. No missing labels, no pictures without descriptions, nothing spilling off the page.', 'nibwp') });
            return 0;
        }
        kgxAdd({ who: 'kg', where: 'feed', text: sprintf(_n('Quick look: %d thing caught my eye. I’ll show you on the page.', 'Quick look: %d things caught my eye. I’ll show you each one on the page.', list.length, 'nibwp'), list.length) });
        kgWalk = { list: list, i: -1 };
        kgWalkNext();
        return list.length;
    }

    function kgWalkNext() {
        if (!kgWalk) { kgpHide(); return; }
        kgWalk.i++;
        var item = kgWalk.list[kgWalk.i];
        if (!item) {
            kgWalk = null;
            kgpHide();
            gAct('brag');
            return;
        }
        var shown = kgPoint(item.node, {
            text: item.text,
            step: sprintf(__('%1$d of %2$d', 'nibwp'), kgWalk.i + 1, kgWalk.list.length),
            code: true,
            next: true,
            actions: [{ label: __('Fix it', 'nibwp'), request: item.request }]
        });
        if (!shown) { kgWalkNext(); }
    }


    function kgCardClick(e) {
        var inBox = !!e.target.closest('.nst-kgchat');
        var cp = e.target.closest('[data-kgx-copy]');
        if (cp) {
            var code = cp.parentNode.querySelector('code').textContent;
            var done = function () { cp.textContent = __('Copied', 'nibwp'); setTimeout(function () { cp.textContent = __('Copy', 'nibwp'); }, 1400); };
            if (navigator.clipboard) { navigator.clipboard.writeText(code).then(done, function () {}); }
            return;
        }
        var fwd = e.target.closest('[data-kgx-b]');
        if (fwd) {
            var t = document.createElement('button');
            t.type = 'button';
            t.hidden = true;
            t.dataset.b = fwd.dataset.kgxB;
            el.appendChild(t);
            t.click();
            t.remove();
            return;
        }
        var b = e.target.closest('[data-kgx-act]');
        if (!b) { return; }
        if (b.dataset.kgxAct) {
            var a = kgxActs[b.dataset.kgxAct];
            if (!a) { return; }
            if (a.connect) { var dlg = document.getElementById('nst-connect'); if (dlg && dlg.showModal) { dlg.showModal(); } return; }
            if (a.run) { a.run(); return; }
            if (a.reply) { kgxAsk(a.reply, 'max-chat', '', null, inBox ? 'box' : 'feed'); return; }
            if (a.workflow) { a.attach = a.workflow; }
            if (a.fill) {
                var box = document.getElementById('nst-input');
                if (box) {
                    box.value = a.fill;
                    box.dispatchEvent(new Event('input', { bubbles: true }));
                    box.focus();
                }
                kgxAdd({ who: 'kg', text: __('It’s in your message box. Change what you like, then send it.', 'nibwp') });
                return;
            }
            if (a.attach) { kgxAttach(a.attach); kgxAdd({ who: 'kg', text: __('Attached. It goes with your next request.', 'nibwp') }); return; }
            if (a.skill) {
                var br = window.nibwpStudioBridge;
                kgxAdd({ who: 'kg', text: br && br.attachSkill && br.attachSkill(a.skill) ? __('Added. It goes with your next request.', 'nibwp') : __('That skill isn’t ready on this site yet.', 'nibwp') });
                return;
            }
            if (a.selector && !a.request) { kgPoint(a.selector, { text: a.label }); return; }
            if (a.request) {
                doTask(a.request, b);
                b.disabled = true;
                b.classList.add('is-sent');
                gAct('jump');
                if (inBox) { kgTasks.push(kgxAdd({ who: 'kg', where: 'box', status: 'queued', task: a.request })); }
            }
        }
    }
    (kgFeed || document).addEventListener('click', function (e) {
        if (e.target.closest('.nst-kgcard')) { kgCardClick(e); }
    });
    kgChat.addEventListener('click', function (e) {
        if (e.target.closest('.nst-kgx__msg')) { kgCardClick(e); }
    });

    if (kgxInput) {
        kgxInput.addEventListener('input', function () {
            clearTimeout(adviceT);
            adviceT = setTimeout(function () { kgxAdvise(kgxInput.value.trim()); }, 1400);
        });
    }

    /* ------------------------------------------------------------------
     * What happens in Agent View, and what Niby makes of it.
     * ---------------------------------------------------------------- */

    var savedThisTurn = false;
    var doneCount = 0;
    var singles = 0;
    var errorAt = 0;
    var focusCount = 0;
    var quipAt = 0;

    // A finished request: a cheer, and now and then a word from the agent.
    var DONE_JOKE = [
        __('Not bad, if I say so myself.', 'nibwp'),
        __('I supervised. You’re welcome.', 'nibwp'),
        __('Fresh out of the oven.', 'nibwp'),
        __('Smooth. Like my head.', 'nibwp')
    ];
    function finished() {
        var good = savedThisTurn;
        savedThisTurn = false;
        mood('happy', 2600);
        if (good) { confetti(); antic('backflip'); }
        var job = jobNow;
        jobNow = '';
        if (agentState === 'working') { agentState = 'listening'; }
        /* translators: 1: the request the agent finished, 2: a short joke */
        narrate(job ? sprintf(__('Done: “%1$s”. %2$s Have a look.', 'nibwp'), jobClip(job), pick(DONE_JOKE)) : __('Done! Have a look.', 'nibwp'),
            { mood: 'happy', stay: 8000, gap: 0, force: true, acts: good ? [[__('Nice', 'nibwp'), 'close']] : [] });
        if (good || doneCount === 3) { setTimeout(function () { if (!chatOpen()) { maybePromo(); } }, 9000); }
    }

    // Tasks he handed over: their state, live, in his window.
    var kgTasks = [];
    function kgTrack(d) {
        if (d.kind !== 'prompt' || !d.text) { return; }
        kgTasks.forEach(function (m) {
            if (m.status === 'done' || m.status === 'cancelled') { return; }
            if (String(d.text).indexOf(String(m.task).slice(0, 120)) < 0) { return; }
            var st = d.state === 'claimed' || d.state === 'done' || d.state === 'cancelled' ? d.state : 'queued';
            kgxUpdate(m, { status: st, since: st === 'claimed' ? Date.now() : m.since });
        });
        kgTasks = kgTasks.filter(function (m) { return m.status !== 'done' && m.status !== 'cancelled'; });
    }

    document.addEventListener('nibwp-studio', function (e) {
        var t = e.detail.type;
        var d = e.detail.data || {};
        if (t === 'state') { kgTrack(d); }
        // The agent's question, for Niby and Kind Guy alike.
        if (t === 'item' && d.kind === 'ask' && d.state === 'open') { askAlert(d.text); }
        if (t === 'state' && d.kind === 'ask' && d.state !== 'open' && current && current.askAlert) { hush(); }
        if (t === 'presence') {
            if (d.state === 'listening' && kgHeld) { var kh = kgHeld; kgHeld = null; setTimeout(kh, 1500); }
            if (guest) { agentState = d.state || 'none'; }
        }
        if (t === 'page') { pageEtch = isEtchPage(d); }
        if ((t === 'page' && isEtchPage(d)) || (t === 'sent' && ETCH.test(d.text || ''))) {
            etchAt = Date.now();
            if (guest) { kgCtx = 'etch'; } else { offerMax(0, 'etch'); }
        }
        if (guest && t === 'presence') { setNoAgent(d.state !== 'listening' && d.state !== 'working'); agentFree = d.state === 'listening'; return; }
        if (guest && t !== 'presence') {
            maxEvent(t, d);
            return;
        }
        switch (t) {
            case 'presence':
                agentFree = d.state === 'listening';
                var was = agentState;
                agentState = d.state || 'none';
                paintStatus();
                if (was === 'working' && agentState === 'listening' && jobNow) { jobNow = ''; }
                if ((was === 'listening' || was === 'working') && (agentState === 'none' || agentState === 'idle')) {
                    narrate(agentState === 'idle' ? __('Your agent went quiet. I’ll keep the chair warm.', 'nibwp') : __('Your agent disconnected. Nap time until it’s back.', 'nibwp'), { mood: 'sleep', force: true });
                }
                setNoAgent(d.state !== 'listening' && d.state !== 'working');
                var on = function (x) { return x === 'listening' || x === 'working'; };
                if (chatOpen() && on(was) !== on(agentState)) {
                    var typed = bubble.querySelector('.nst-buddy__ask textarea');
                    if (!typed || !typed.value.trim()) {
                        menu(on(agentState) ? __('I’m up! Your agent is listening. What shall we do?', 'nibwp') : '');
                    }
                }
                if (agentState === 'listening' && held) {
                    var h = held;
                    held = null;
                    setTimeout(h, 1500);
                }
                if (d.state === 'working') { mood('work'); } else if (steady === 'work') { mood(noAgent ? 'sleep' : 'idle'); }
                break;
            case 'state':
                if (d.kind === 'prompt' && d.state === 'claimed') {
                    mood('work');
                    jobNow = d.text || lastAsk || '';
                    agentState = 'working';
                    antic('jump');
                    narrate(jobNow ? sprintf(__('Your agent is on it: “%s”. I’ll supervise.', 'nibwp'), jobClip(jobNow)) : __('Your agent is on it. I’ll supervise. Someone has to.', 'nibwp'), { mood: 'work', stay: 6000, gap: 2000 });
                } else if (d.kind === 'prompt' && d.state === 'done' && steady === 'work') {
                    mood('idle');
                }
                break;
            case 'item':
                if (d.kind === 'event' && d.type === 'saved') {
                    savedThisTurn = true;
                    antic('hop');
                    narrate(d.text ? sprintf(__('Saved “%s”.', 'nibwp'), jobClip(d.text)) : __('Saved a change.', 'nibwp'), { mood: 'happy', stay: 4000, gap: 5000 });
                }
                if (d.kind === 'progress' && d.text) {
                    narrate(jobClip(d.text), { mood: 'work', stay: 5000, gap: 8000 });
                }
                if (d.kind === 'reply') {
                    if (d.text) { lastReply = d.text; }
                    mood('idle');
                    doneCount++;
                    finished();
                }
                break;
            case 'sent':
                if (d.text) { lastAsk = d.text; }
                mood('work', 2500);
                singles = d.notes === 1 ? singles + 1 : singles;
                if (singles >= 2) { tip('several', __('Asking for a few things? Use Add another on each note, then send them together.', 'nibwp'), null, { gap: 15000 }); }
                break;
            case 'restored':
                mood('happy', 2600);
                antic('backflip');
                maybePromo();
                break;
            case 'proof':
                if (d.on && d.tool === 'point') {
                    setTimeout(function () {
                        tip('draw', __('Tip: press D to draw a box, a circle or a line around what you mean. A drawing and a few words say it best.', 'nibwp'), [[__('Try it', 'nibwp'), 'draw'], [__('Later', 'nibwp'), 'close']], { gap: 15000 });
                    }, 1800);
                }
                break;
            case 'page':
                if (d.external) { tip('snapshot', __('This is a snapshot of another site. Point at what you like and say “make ours like this”. I won’t tell them.', 'nibwp'), null, { gap: 15000 }); }
                if (document.querySelectorAll('.nst-tab').length >= 4) { tip('groups', __('Lots of tabs! Right-click one to put related tabs in a coloured group.', 'nibwp'), null, { gap: 20000 }); }
                break;
            case 'device':
                if (d.device === 'mobile') { tip('phone', __('Phone view is on. Notes you pin now tell the agent to fix the phone layout.', 'nibwp'), null, { gap: 15000 }); }
                break;
            case 'media':
                setTimeout(function () { tip('media', __('Pick several files with Shift, then Add to message to hand them to your agent.', 'nibwp'), null, { gap: 15000 }); }, 600);
                break;
            case 'error':
                mood('worry', 2600);
                if (Date.now() - errorAt > 600000) {
                    errorAt = Date.now();
                    if (canTalk(8000)) { say(__('Hmm, that did not work. The message just shown says why. Not my fault, for the record.', 'nibwp'), [], { mood: 'worry', stay: 5500 }); }
                }
                break;
        }
    });

    // Typing quiets Niby; after a few visits to the message box it mentions "/".
    var input = document.getElementById('nst-input');
    document.addEventListener('keydown', function (e) {
        if (!bubble.contains(e.target)) { lastTyped = Date.now(); }
        wake();
    }, true);
    if (input) {
        input.addEventListener('focus', function () {
            if (++focusCount === 3) {
                setTimeout(function () { tip('slash', __('Type / in the message box for starters, skills and your NibWP workflows.', 'nibwp'), null, { gap: 15000 }); }, 600);
            }
        });
        input.addEventListener('input', function () { if (input.value.charAt(0) === '/') { mem.seen.slash = mem.seen.slash || Date.now(); } });
    }

    // Asleep after a long pause, awake on the next move.
    var idleTimer = 0;
    // Asleep while no agent listens; awake, and saying so, when one does.
    function setNoAgent(v) {
        if (v === noAgent) { return; }
        noAgent = v;
        kgDeaf(v);
        if (v) {
            if (steady !== 'work') { mood('sleep'); }
        } else if (steady === 'sleep') {
            mood('idle');
            antic('jump');
            narrate(__('Agent connected! I’m awake. I was totally awake. It’s listening now.', 'nibwp'), { mood: 'happy', stay: 5000, gap: 0, force: true });
        }
    }

    function wake() {
        clearTimeout(idleTimer);
        if (el.dataset.mood === 'sleep' && !noAgent) { mood('idle'); antic('stretch'); }
        idleTimer = setTimeout(function () { if (steady !== 'work' && !touring) { mood('sleep'); hush(); } }, 180000);
    }
    document.addEventListener('mousemove', wake, { passive: true });

    // Tips on its own: first soon after it says hello, then every few
    // minutes, only while the person is around and not busy.
    var lastMove = Date.now();
    document.addEventListener('mousemove', function () { lastMove = Date.now(); }, { passive: true });
    var autoNo = 0;
    (function autoTips() {
        setTimeout(function () {
            if (mem.on && !el.hidden && bubble.hidden && !document.hidden && el.dataset.mood !== 'sleep' && Date.now() - lastMove < 60000 && canTalk(60000)) {
                var acts = [[__('Another tip', 'nibwp'), 'tip'], [__('Thanks', 'nibwp'), 'close']];
                if (guest === 'on') {
                    if (++autoNo % 2) { story(); } else { maxSay(kgPick(MAX_JOKES), maxActs(), { stay: 11000 }); }
                } else if (++autoNo % 2 === 0 || !dyk()) { say(nextTip(), acts, { stay: 11000 }); }
            }
            autoTips();
        }, autoNo || mem.tipNo ? 150000 + Math.random() * 90000 : 45000);
    }());

    /* ------------------------------------------------------------------
     * The tour: Niby walks the person round Agent View, one part at a
     * time, under a spotlight, and says what it is and what it does.
     * ---------------------------------------------------------------- */

    function intro() {
        mem.seen.intro = Date.now();
        save();
        antic('backflip');
        say(sprintf(__('Hi, I’m Niby! I live in %s with you: I chat, I pass your requests to your agent, and I tell you what it’s up to. New here? Let me show you around, it takes a minute.', 'nibwp'), brand),
            [[__('Show me around', 'nibwp'), 'tour'], [__('Maybe later', 'nibwp'), 'close']], { mood: 'wave', stay: 30000 });
    }

    var TOUR = [
        { sel: ['#nst-presence'], title: __('Your agent', 'nibwp'), text: __('Your agent’s light. Green: it’s listening. Pulsing: it’s working. Grey: nobody home, so click it to connect one. I nap when it’s grey, so please do.', 'nibwp') },
        { sel: ['#nst-jobs'], title: __('The jobs queue', 'nibwp'), text: __('Everything you asked for, in line. Hover it to stop what’s running, drag jobs into a new order, pause one, or undo a finished one. Bossing an AI around has never been this tidy.', 'nibwp') },
        { sel: ['#nst-queue'], title: __('The queue', 'nibwp'), text: __('Requests you put aside to run together later. Open it to run them all in one go, change their order or drop one. Queue mode in the message box fills it.', 'nibwp') },
        { sel: ['#nst-buddy-btn'], title: __('My switch', 'nibwp'), text: __('That’s me up there! Click it to hide me when you need quiet, and again to bring me back. I won’t sulk. Much.', 'nibwp') },
        { sel: ['#nst-back', '#nst-reload'], title: __('Back, forward, reload', 'nibwp'), text: __('Just like a browser. Walk around your site here; whatever page is open is the one your agent works on.', 'nibwp') },
        { sel: ['#nst-omni-wrap'], title: __('The address bar', 'nibwp'), text: __('Search your pages, posts and products, or type a path like /about. Paste any website’s address to open a safe snapshot of it and point at what you like. Ctrl K jumps here.', 'nibwp') },
        { sel: ['.nst-devices'], title: __('Desktop, tablet, phone', 'nibwp'), text: __('Switch the preview width. Notes you pin in phone view tell your agent to fix the phone layout, not the desktop one.', 'nibwp') },
        { sel: ['#nst-tabbar'], title: __('Tabs', 'nibwp'), text: __('Keep several pages open, like in a browser. Ctrl-click a link for a new tab; right-click a tab to close the others, or the ones to its left or right. A dot means Builder edits on that page are not saved yet.', 'nibwp') },
        { sel: ['#nst-comment'], title: __('Comment', 'nibwp'), text: sprintf(__('My favourite. Press %s, click anything on the page and say what to change. Your agent gets the exact element. No more “the blue thing, top-ish, left-ish”.', 'nibwp'), KEY_C) },
        { sel: ['#nst-draw'], title: __('Draw', 'nibwp'), text: sprintf(__('Press %s and draw a box, a circle or a line around what you mean. A doodle and a few words beat a long email.', 'nibwp'), KEY_D) },
        { sel: ['#nst-notes-btn'], title: __('Your notes', 'nibwp'), text: __('Every note you pinned, in one list. Jump to one, edit it, or send them all together.', 'nibwp') },
        { sel: ['#nst-wire'], title: __('The Builder', 'nibwp'), text: sprintf(__('Edit the page yourself: click anything to select it, type on it, drag it, style it, add sections, and save straight to your site. %s opens it.', 'nibwp'), KEY('Ctrl+B')), act: [__('Show me the Builder', 'nibwp'), 'btour'] },
        { sel: ['.nst-exportbtn'], title: __('Static site', 'nibwp'), text: __('Turn your site into plain HTML files: download them as a zip, or publish to Cloudflare Pages, Netlify or your own server, and keep it up to date by itself whenever you change something.', 'nibwp') },
        { sel: ['#nst-media'], title: __('Media', 'nibwp'), text: __('Your media manager, right here in the top bar. Browse by type or date, search, upload or drop files, copy links, or add a file to your message. No trip to the Media Library.', 'nibwp') },
        { sel: ['#nst-edit', '#nst-open'], title: __('Editor and new tab', 'nibwp'), text: __('This page in its own editor, and this page in a new tab. The usual suspects, right where you need them.', 'nibwp') },
        { sel: ['#nst-theme', '#nst-more'], title: __('Light, dark and more', 'nibwp'), text: __('Switch between light and dark, and find the rest under the three dots. Night owls, you’re welcome.', 'nibwp') },
        { sel: ['.nst-side__head'], title: __('Chat and History', 'nibwp'), text: __('Chat is your talk with the agent. History holds every saved version of a page, ready to restore. The folder keeps your threads: rename one, delete one, or start fresh. Press [ to fold the panel away.', 'nibwp') },
        { sel: ['#nst-starters', '#nst-feed'], one: true, title: __('The conversation', 'nibwp'), text: __('Your requests and your agent’s answers live here, with each saved change and an Undo next to it. Not sure where to start? Pick a starter.', 'nibwp') },
        { sel: ['#nst-mode'], title: __('How your agent takes it', 'nibwp'), text: __('Auto: it starts right away. Plan: it looks and plans, and nothing changes. Queue: collect requests and run them together later. Bypass: it decides on its own, no questions asked.', 'nibwp') },
        { sel: ['#nst-composer'], title: __('The message box', 'nibwp'), text: __('Say what you want in plain words. + adds a page, a file, a skill or a workflow; / opens starters; the paper clip attaches files. Send it, and Stop it if you change your mind.', 'nibwp') },
        { sel: ['#nst-canvas'], title: __('Your site, live', 'nibwp'), text: __('The real thing, not a picture. Click around like a visitor, and when something needs changing, point at it.', 'nibwp') },
        { self: true, title: __('And me, Niby', 'nibwp'), text: __('Drag me anywhere. Click me to chat: I answer questions, hand work to your agent and tell you what it’s doing. No agent? I nap. That’s the whole job, and I’m great at it.', 'nibwp') }
    ];
    var BTOUR = [
        { sel: ['.nst-bd [data-bd-view="add"]'], title: __('Add', 'nibwp'), text: __('Elements to drop on the page, or click one to add it after what you selected (inside it, when it is an empty box). Draw a wireframe and NibWP builds it. Images from your desktop drop right onto the page.', 'nibwp') },
        { sel: ['.nst-bd [data-bd-view="tree"]'], title: __('Structure', 'nibwp'), text: __('Every element on the page, as a tree. Click to select, type to rename, right-click for BEM names, copy and paste, or hiding on phones, tablets or desktop.', 'nibwp') },
        { sel: ['.nst-bd [data-bd-view="classes"]'], title: __('Classes', 'nibwp'), text: __('Your saved classes and the site’s variables. Edit a class once and every element that uses it follows; rename it and the whole site follows.', 'nibwp') },
        { sel: ['.nst-bd [data-bd-view="templates"]'], title: __('Templates', 'nibwp'), text: __('Each kind of content with the template that shows it, and a small picture of it. Open one to edit it, or make a new one.', 'nibwp') },
        { sel: ['.nst-bd [data-bd-view="design"]'], title: __('Design', 'nibwp'), text: __('Your colours, type and spacing as one set of tokens. Change a few settings and every token follows.', 'nibwp') },
        { sel: ['.nst-bd__hub-h'], title: __('Content hub', 'nibwp'), text: __('Make a new page, post or product from a starting point, and rename or remove recent ones, without leaving the Builder.', 'nibwp') },
        { sel: ['.nst-bd-bar__size', '[data-bd="sizes"]'], title: __('Canvas size', 'nibwp'), text: __('Type a width or pick a screen size. Wider than your screen? The page is shown smaller at its real width, so its layout is the real one.', 'nibwp') },
        { sel: ['[data-bd="dock"]'], title: __('Code', 'nibwp'), text: sprintf(__('The HTML, CSS and JavaScript of what you selected, in a code editor that suggests as you type. %s opens it.', 'nibwp'), KEY('Ctrl+E')) },
        { sel: ['[data-bd="undo"]', '[data-bd="history"]'], title: __('Undo and saved changes', 'nibwp'), text: __('Undo and redo while you work. Once saved, the clock lists your saves: undo the last one if you change your mind.', 'nibwp') },
        { sel: ['[data-bd="save"]'], title: __('Save', 'nibwp'), text: sprintf(__('Puts your changes on the live site. %s saves too.', 'nibwp'), KEY('Ctrl+S')) },
        { sel: ['[data-bd="view"]'], title: __('Your way', 'nibwp'), text: sprintf(__('Show or hide parts of the Builder. %s lists every shortcut.', 'nibwp'), KEY('Ctrl+/')) },
        { sel: ['#nst-canvas'], title: __('The page', 'nibwp'), text: sprintf(__('Click to select, double-click text to type it, drag to move, right-click for more. %1$s duplicates; %2$s and %3$s copy and paste, even into another tab.', 'nibwp'), KEY('Ctrl+D'), KEY('Ctrl+C'), KEY('Ctrl+V')) }
    ];
    var touring = null;
    var spot = null;

    function tourVisible(n) {
        if (!n) { return false; }
        var r = n.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < window.innerHeight && getComputedStyle(n).visibility !== 'hidden';
    }
    function tourRect(step) {
        if (step.self) { return me.getBoundingClientRect(); }
        var nodes = step.sel.map(function (q) { return document.querySelector(q); }).filter(tourVisible);
        if (step.one) { nodes = nodes.slice(0, 1); }
        if (!nodes.length) { return null; }
        var u = { left: Infinity, top: Infinity, right: -Infinity, bottom: -Infinity };
        nodes.forEach(function (n) {
            var r = n.getBoundingClientRect();
            u.left = Math.min(u.left, r.left);
            u.top = Math.min(u.top, r.top);
            u.right = Math.max(u.right, r.right);
            u.bottom = Math.min(Math.max(u.bottom, r.bottom), window.innerHeight);
        });
        u.width = u.right - u.left;
        u.height = u.bottom - u.top;
        return u;
    }
    function tourSpot(r) {
        if (!spot) {
            spot = document.createElement('div');
            spot.className = 'nst-tour-spot';
            spot.setAttribute('aria-hidden', 'true');
            host.appendChild(spot);
        }
        var p = 6;
        spot.style.left = (r.left - p) + 'px';
        spot.style.top = (r.top - p) + 'px';
        spot.style.width = (r.width + p * 2) + 'px';
        spot.style.height = (r.height + p * 2) + 'px';
        spot.hidden = false;
    }
    // Where he stands: under something in the top bar, beside something
    // tall, inside something big.
    function tourPark(r) {
        var w = me.offsetWidth || 78;
        var h = me.offsetHeight || 90;
        var W = window.innerWidth;
        var H = window.innerHeight;
        var x;
        var y;
        if (r.bottom < H * 0.3 && r.height < H * 0.3) {
            x = r.left + r.width / 2 - w / 2;
            y = r.bottom + 18;
        } else if (r.width < W * 0.6 && r.right + w + 24 < W) {
            x = r.right + 18;
            y = r.top + r.height / 2 - h / 2;
        } else if (r.width < W * 0.6 && r.left - w - 24 > 0) {
            x = r.left - w - 18;
            y = r.top + r.height / 2 - h / 2;
        } else {
            x = r.left + 40;
            y = r.bottom - h - 30;
        }
        el.style.left = Math.max(4, Math.min(x, W - w - 4)) + 'px';
        el.style.top = Math.max(4, Math.min(y, H - h - 4)) + 'px';
    }
    function tourShow(i) {
        if (!touring) { return; }
        var steps = touring.steps;
        i = Math.max(0, Math.min(i, steps.length - 1));
        var s = steps[i];
        var r = s.self ? null : tourRect(s);
        if (!s.self && !r) {
            // Gone from the screen since the tour began: on to the next one.
            steps.splice(i, 1);
            if (!steps.length) { tourEnd(true); return; }
            tourShow(i);
            return;
        }
        touring.i = i;
        current = null;
        hush();
        if (s.self) { place(); } else { tourPark(r); tourSpot(r); }
        antic('hop');
        clearTimeout(tourShow.t);
        tourShow.t = setTimeout(function () {
            if (!touring || touring.i !== i) { return; }
            tourSpot(s.self ? me.getBoundingClientRect() : (tourRect(s) || r));
            var last = i === steps.length - 1;
            var acts = i ? [[__('Back', 'nibwp'), 'tour-back']] : [];
            acts.push([last ? __('Finish', 'nibwp') : __('Next', 'nibwp'), last ? 'tour-end' : 'tour-next', true]);
            if (s.act) { acts.push(s.act); }
            if (!last) { acts.push([__('Skip the tour', 'nibwp'), 'tour-skip']); }
            say(s.text, acts, {
                tour: true, q: s.title, stay: CHAT_MS, mood: 'talk',
                /* translators: 1: this step, 2: how many steps */
                foot: '<span class="nst-tour__n">' + esc(sprintf(__('%1$d of %2$d', 'nibwp'), i + 1, steps.length)) + '</span>' +
                    /* translators: 1: left arrow key, 2: right arrow key, 3: the Escape key */
                    '<small class="nst-tour__keys">' + sprintf(esc(__('Use %1$s %2$s to move, %3$s to close.', 'nibwp')), '<kbd>←</kbd>', '<kbd>→</kbd>', '<kbd>' + esc(KEY('Esc')) + '</kbd>') + '</small>',
                onClose: function () { tourEnd(true); }
            });
            var b = bubble.querySelector('.nst-buddy__act.is-main');
            if (b) { b.focus({ preventScroll: true }); }
        }, reduced ? 0 : 760);
    }
    function tourStart(list, kind) {
        if (guest || !mem.on || el.hidden || touring) { return; }
        var steps = (list || TOUR).filter(function (s) { return s.self || !!tourRect(s); });
        if (!steps.length) { return; }
        touring = { i: 0, steps: steps, kind: kind || 'tour' };
        el.classList.add('is-touring');
        mood('idle');
        tourShow(0);
    }
    function tourEnd(quiet) {
        if (!touring) { return; }
        var kind = touring.kind;
        touring = null;
        clearTimeout(tourShow.t);
        mem.seen[kind] = Date.now();
        save();
        if (spot) { spot.hidden = true; }
        place();
        setTimeout(function () { el.classList.remove('is-touring'); }, reduced ? 0 : 800);
        if (noAgent) { mood('sleep'); }
        if (quiet) { return; }
        current = null;
        hush();
        setTimeout(function () {
            antic('backflip');
            confetti();
            say(kind === 'btour'
                ? __('That’s the Builder! Click anything on the page and make it yours. I’m here if you need me.', 'nibwp')
                : __('That’s the tour! Replay it any time: click me and pick Take the tour. Now go make something lovely.', 'nibwp'), [[__('Thanks, Niby', 'nibwp'), 'close']], { mood: 'happy', stay: 9000 });
        }, reduced ? 0 : 850);
    }
    document.addEventListener('keydown', function (e) {
        if (!touring || e.target.closest('input, textarea, [contenteditable="true"]')) { return; }
        if (e.key === 'Escape') { e.preventDefault(); current = null; hush(); tourEnd(true); }
        // Forward is the reading direction: the left arrow on a right-to-left screen.
        var fwd = document.documentElement.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
        if (e.key === fwd) { e.preventDefault(); if (touring.i < touring.steps.length - 1) { tourShow(touring.i + 1); } else { tourEnd(false); } }
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); tourShow(touring.i - 1); }
    });

    (function () {
        var root = document.querySelector('.nst');
        if (!root || !window.MutationObserver) { return; }
        var was = root.classList.contains('is-building');
        new MutationObserver(function () {
            var now = root.classList.contains('is-building');
            if (now && !was && mem.seen.btour && !guest) { offerMax(0, 'code'); }
            if (now && !was && !mem.seen.btour && !touring && mem.on && !guest && !el.hidden) {
                setTimeout(function () {
                    if (!root.classList.contains('is-building') || touring || mem.seen.btour) { return; }
                    antic('hop');
                    say(__('Welcome to the Builder! Want a quick look around it? It takes a minute.', 'nibwp'),
                        [[__('Show me', 'nibwp'), 'btour', true], [__('Not now', 'nibwp'), 'btour-no']], { mood: 'wave', stay: 20000 });
                }, reduced ? 300 : 1400);
            }
            was = now;
        }).observe(root, { attributes: true, attributeFilter: ['class'] });
    }());

    /* ------------------------------------------------------------------
     * Start.
     * ---------------------------------------------------------------- */

    save();
    toggle.setAttribute('aria-pressed', String(mem.on));
    el.hidden = !mem.on;
    place();
    wake();
    if (noAgent) { mood('sleep'); }
    if (mem.on) { portal('in', function () {}); }

    setTimeout(function () {
        if (!mem.on) { return; }
        if (!mem.seen.intro) { intro(); return; }
        var p = agentState;
        paintStatus();
        if (p === 'listening' || p === 'working') {
            antic('stretch');
            narrate(p === 'working' ? __('Your agent is connected and already working. I’m watching it.', 'nibwp') : __('Your agent is connected and listening. Click me to chat.', 'nibwp'), { mood: 'happy', stay: 5500, gap: 0, force: true });
            return;
        }
        if (p !== 'listening' && p !== 'working') {
            tip('noagent', __('No agent yet? You can still pin notes and send them; they wait here for it. Want to connect one?', 'nibwp'), [[__('Connect', 'nibwp'), 'connect'], [__('Later', 'nibwp'), 'close']], { gap: 0, stay: 12000 });
        } else {
            tip('comment', sprintf(__('Press %s and click anything on the page to leave a note on it.', 'nibwp'), KEY_C), [[__('Show me', 'nibwp'), 'comment'], [__('Later', 'nibwp'), 'close']], { gap: 0 });
        }
    }, 2500);
}());
