# navbar — checklist

## Identify
- [ ] Source is `<header>` / `<nav>` containing brand mark + nav links + optional CTA.
- [ ] Decided variant: sticky / fixed / static / transparent-on-hero.
- [ ] BEM block name: `{brand}-nav`.

## Tokens
- [ ] Logo `font-size`: `--text-l` (1.125rem) when text logo; otherwise sized by SVG `block-size`.
- [ ] Nav link `font-size`: `--text-xs` (0.75rem) or `--text-s` (0.875rem). **Never `clamp()`**.
- [ ] Nav link `padding`: `--space-xs` block, `--space-s` inline.
- [ ] CTA padding: `--space-s` block, `--space-m` inline.
- [ ] Border-bottom: `var(--border-size, 1px) solid var(--border-color-light, #e8e8e8)`.
- [ ] Background on transparent navbars: `rgba(255, 255, 255, 0.92)` + `backdrop-filter: blur(12px)` (justified raw because it's structural).

## Structure
- [ ] Outer: `<nav>` with `aria-label="Primary"` (or "Main navigation").
- [ ] Logo: `<a href="/" rel="home">` wrapping the brand mark.
- [ ] Nav list: `<ul>` with `list-style: none; padding: 0; margin: 0;`.
- [ ] Mobile hamburger: `<button type="button" aria-label="Open menu" aria-controls="mobile-menu" aria-expanded="false">`.
- [ ] Skip-link: invisible link at top: `<a class="{brand}-nav__skip" href="#main">Skip to content</a>` — visible on focus.

## Behavior
- [ ] Sticky behavior via `position: fixed; inset-block-start: 0;` + body `padding-top` equal to navbar height.
- [ ] Scroll-shadow: toggle `.is-scrolled` class via vanilla JS `window.addEventListener('scroll', …)` when `scrollY > 20`.
- [ ] Mobile menu toggle: vanilla JS `classList.toggle` + `aria-expanded` flip.
- [ ] Mobile menu focus trap: when open, `Tab` cycles only within the menu. **Vanilla JS only — no library** (js-libraries.md).
- [ ] Mobile menu closes on Escape, on link click, on focus leaving the menu.
- [ ] Submenu hover-to-tap: on touch devices, submenu opens on first tap, link follows on second tap. Use `@media (hover: hover)` to detect hover-capable devices.

## Responsive
- [ ] `@container (max-width: to-rem(768px))` hides desktop nav list, shows hamburger.
- [ ] Mobile menu uses `position: fixed; inset: var(--nav-height, 68px) 0 0 0;` to slide down full-width.

## Pixel-perfect
- [ ] Navbar height matches source exactly (typically 60–80px).
- [ ] Logo / hamburger vertically centered (the source likely uses `align-items: center`).
- [ ] Active link state matches source (often `&[aria-current="page"]` with bold weight + underline).

## Accessibility
- [ ] `aria-current="page"` on the active link (let the parent template set this dynamically).
- [ ] Focus visible on every link + button.
- [ ] Hamburger button has `aria-label` (NOT just an icon).
- [ ] Mobile menu has `role="dialog"` + `aria-modal="true"` when open.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
