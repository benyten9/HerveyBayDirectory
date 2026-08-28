# JavaScript Libraries — Standardized Stack

All Etchedy artifacts that need JS-powered interactivity MUST use the libraries listed below. Never introduce a different library for the same purpose. Consistency across the library means users load each dependency once and every component works together.

---

## The Stack

### Sliders / Carousels → **Swiper**

**CDN**: `https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js`
**CSS**: `https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css`

Use Swiper for all slider/carousel needs: image carousels, testimonial sliders, card carousels, logo marquees, hero sliders, before/after sliders.

**Why Swiper**: No jQuery dependency, touch-friendly, SSR-safe, accessible, widely used, excellent responsive API, CSS-driven transitions. Replaces Slick, Flickity, Owl, Glide, Splide, Embla, Keen-Slider.

**Init pattern for Etchedy** (base64-encoded in `attrs.script.code`):

```js
(function() {
  function init() {
    if (typeof Swiper === 'undefined') {
      setTimeout(init, 100);
      return;
    }
    var el = document.querySelector('.{component-class}__track');
    if (!el || el.classList.contains('swiper-initialized')) return;

    new Swiper(el, {
      slidesPerView: 1,
      spaceBetween: 16,
      loop: true,
      autoplay: { delay: 4000, disableOnInteraction: false },
      pagination: { el: '.{component-class}__pagination', clickable: true },
      navigation: { nextEl: '.{component-class}__next', prevEl: '.{component-class}__prev' },
      breakpoints: {
        640: { slidesPerView: 2 },
        1024: { slidesPerView: 3 }
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
```

**Required HTML structure**:
```html
<div class="{class}__track swiper">
  <div class="swiper-wrapper">
    <div class="swiper-slide">…</div>
    <div class="swiper-slide">…</div>
  </div>
  <div class="{class}__pagination swiper-pagination"></div>
  <button class="{class}__prev swiper-button-prev"></button>
  <button class="{class}__next swiper-button-next"></button>
</div>
```

**Note**: The existing `top-attractions` carousel uses Slick (legacy). New artifacts MUST use Swiper. Do not add more Slick-based components.

---

### Scroll Animations / Entrance Effects → **CSS + IntersectionObserver**

**No library needed.** Use native CSS animations + a small vanilla JS IntersectionObserver pattern.

**Why native**: No dependency, tiny footprint, hardware-accelerated, works everywhere. Replaces GSAP ScrollTrigger, AOS, Sal.js, ScrollReveal for entrance animations.

**Standard pattern**:

```css
/* In the style object's css string: */
.component__animated {
  opacity: 0;
  transform: translateY(var(--space-m, 1rem));
  transition: opacity 0.6s ease, transform 0.6s ease;
}
.component__animated[data-visible="true"] {
  opacity: 1;
  transform: translateY(0);
}
```

```js
// In attrs.script.code (base64-encoded):
(function() {
  var els = document.querySelectorAll('.component__animated');
  if (!els.length) return;
  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.setAttribute('data-visible', 'true');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });
  els.forEach(function(el) { observer.observe(el); });
})();
```

**For staggered entrance** (cards appearing one by one):

```css
.component__card {
  opacity: 0;
  transform: translateY(var(--space-m, 1rem));
  transition: opacity 0.5s ease, transform 0.5s ease;
  transition-delay: calc(var(--stagger-index, 0) * 0.1s);
}
.component__card[data-visible="true"] {
  opacity: 1;
  transform: translateY(0);
}
```

```js
// Add data-stagger-index to each card:
els.forEach(function(el, i) { el.style.setProperty('--stagger-index', i); });
```

---

### Complex Timeline Animations → **GSAP** (when CSS alone can't do it)

**CDN**: `https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js`
**ScrollTrigger**: `https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollTrigger.min.js`

Use GSAP **only** when the animation requires:
- Complex sequenced timelines (multi-step choreography)
- Scroll-linked parallax (not just entrance — continuous scroll position binding)
- Physics-based motion (spring, bounce, elastic)
- SVG path morphing
- Pinned scroll sections

**Do NOT use GSAP** for simple entrance animations, hover effects, or single transitions — use CSS for those.

**Init pattern**:

```js
(function() {
  function init() {
    if (typeof gsap === 'undefined') {
      setTimeout(init, 100);
      return;
    }
    gsap.registerPlugin(ScrollTrigger);

    gsap.from('.component__heading', {
      y: 40,
      opacity: 0,
      duration: 0.8,
      scrollTrigger: {
        trigger: '.component',
        start: 'top 80%',
        toggleActions: 'play none none none'
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
```

---

### Accordions / Tabs / Toggles → **CSS `<details>` + vanilla JS**

**No library needed.** Use the native `<details>` / `<summary>` HTML elements for accordions, styled with CSS. For tabs, use a small vanilla JS pattern.

**Accordion pattern** (block tree):
```json
{
  "blockName": "etch/element",
  "attrs": { "tag": "details", "attributes": { "class": "component__accordion-item" } },
  "innerBlocks": [
    {
      "blockName": "etch/element",
      "attrs": { "tag": "summary", "attributes": { "class": "component__accordion-trigger" } },
      "innerBlocks": [/* question text */]
    },
    {
      "blockName": "etch/element",
      "attrs": { "tag": "div", "attributes": { "class": "component__accordion-content" } },
      "innerBlocks": [/* answer content or slot */]
    }
  ]
}
```

**Animated open/close CSS**:
```css
.component__accordion-item {
  border-block-end: 1px solid var(--border-color-light, #e8e8e8);
}
.component__accordion-trigger {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-m, 1rem) 0;
  cursor: pointer;
  font-weight: 600;
  list-style: none;
  &::marker, &::-webkit-details-marker { display: none; }
  &::after {
    content: '+';
    font-size: 1.25em;
    transition: transform 0.3s;
  }
}
.component__accordion-item[open] > .component__accordion-trigger::after {
  transform: rotate(45deg);
}
.component__accordion-content {
  padding-block-end: var(--space-m, 1rem);
}
```

**Tab pattern** (vanilla JS, no library):
```js
(function() {
  var tabContainer = document.querySelector('.component__tabs');
  if (!tabContainer) return;
  var buttons = tabContainer.querySelectorAll('[data-tab]');
  var panels = tabContainer.querySelectorAll('[data-tab-panel]');
  
  buttons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var target = btn.getAttribute('data-tab');
      buttons.forEach(function(b) { b.setAttribute('aria-selected', 'false'); });
      panels.forEach(function(p) { p.hidden = true; });
      btn.setAttribute('aria-selected', 'true');
      var panel = tabContainer.querySelector('[data-tab-panel="' + target + '"]');
      if (panel) panel.hidden = false;
    });
  });
})();
```

---

### Lightbox / Modal Images → **CSS `<dialog>` + vanilla JS**

**No library needed.** Use the native `<dialog>` element.

```js
(function() {
  document.querySelectorAll('[data-lightbox]').forEach(function(trigger) {
    trigger.addEventListener('click', function() {
      var dialog = document.querySelector('#' + trigger.getAttribute('data-lightbox'));
      if (dialog) dialog.showModal();
    });
  });
  document.querySelectorAll('dialog').forEach(function(d) {
    d.addEventListener('click', function(e) {
      if (e.target === d) d.close();
    });
  });
})();
```

---

### Marquee / Infinite Scroll → **CSS animation**

**No library needed.** Pure CSS `@keyframes` + `animation`.

```css
.component__marquee-track {
  display: flex;
  gap: var(--space-l, 2rem);
  animation: marquee var(--marquee-duration, 30s) linear infinite;
  inline-size: max-content;
}
@keyframes marquee {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}
```

Duplicate the children once in the block tree so the loop is seamless.

---

### Counter / Number Animation → **IntersectionObserver + requestAnimationFrame**

```js
(function() {
  document.querySelectorAll('[data-count-to]').forEach(function(el) {
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (!entry.isIntersecting) return;
        observer.unobserve(el);
        var target = parseInt(el.getAttribute('data-count-to'), 10);
        var duration = 1500;
        var start = performance.now();
        function tick(now) {
          var progress = Math.min((now - start) / duration, 1);
          el.textContent = Math.floor(progress * target);
          if (progress < 1) requestAnimationFrame(tick);
          else el.textContent = target;
        }
        requestAnimationFrame(tick);
      });
    }, { threshold: 0.5 });
    observer.observe(el);
  });
})();
```

---

### Parallax → **CSS or GSAP ScrollTrigger**

For simple parallax (background scrolling at different speed):
```css
.component__parallax-bg {
  background-attachment: fixed;
  background-size: cover;
  background-position: center;
}
```

For element-level parallax (elements moving at different rates relative to scroll), use GSAP ScrollTrigger (see above).

---

## How to embed scripts in Etchedy artifacts

Scripts are embedded in the root block's `attrs.script` object:

```json
{
  "blockName": "etch/element",
  "attrs": {
    "script": {
      "code": "<base64-encoded JS>",
      "id": "unique-script-id"
    },
    "tag": "section",
    ...
  }
}
```

**Rules:**
1. The JS code is **base64-encoded** in the `code` field.
2. The `id` field is a short unique identifier (e.g. `"hero-slider-init"`).
3. Scripts execute on the front-end after the DOM is ready.
4. Always wrap in an IIFE `(function() { ... })();` to avoid polluting global scope.
5. Always poll for the library's global (`typeof Swiper`, `typeof gsap`) before initializing — CDN may load after the script runs.
6. Always guard against re-initialization (check for `.swiper-initialized`, a data attribute, etc.).

**To base64-encode** (for writing the JSON):
```bash
echo -n '(function() { /* your JS */ })();' | base64
```

---

## CDN Dependencies — what to load

Etchedy artifacts assume the page loads these CDNs when the corresponding component type is present. The EtchWP builder handles loading. For standalone use, include in the page `<head>`:

| Need | CSS CDN | JS CDN |
|---|---|---|
| Slider/Carousel | `swiper@11/swiper-bundle.min.css` | `swiper@11/swiper-bundle.min.js` |
| Complex animations | (none) | `gsap@3/dist/gsap.min.js` + `ScrollTrigger.min.js` |

Everything else (accordions, tabs, lightbox, marquee, counters, entrance animations) is **zero-dependency** — native CSS + vanilla JS.

---

## Decision Matrix

| Behavior | Solution | Library |
|---|---|---|
| Carousel / slider | Swiper init pattern | **Swiper** |
| Fade-in on scroll | CSS transition + IntersectionObserver | **None** |
| Staggered card entrance | CSS transition-delay + IntersectionObserver | **None** |
| Complex scroll-linked animation | GSAP + ScrollTrigger | **GSAP** |
| SVG path morphing | GSAP MorphSVG | **GSAP** |
| Parallax background | CSS `background-attachment: fixed` | **None** |
| Parallax elements | GSAP ScrollTrigger | **GSAP** |
| Accordion | `<details>` + CSS | **None** |
| Tabs | Vanilla JS data-tab pattern | **None** |
| Lightbox / modal | `<dialog>` + vanilla JS | **None** |
| Marquee / infinite scroll | CSS `@keyframes` | **None** |
| Counter animation | IntersectionObserver + rAF | **None** |
| Hover effects | CSS `&:hover` / `transition` | **None** |
| Typewriter effect | Vanilla JS + CSS | **None** |
| Sticky header | CSS `position: sticky` | **None** |

**Rule: if CSS can do it, don't load a library.**
