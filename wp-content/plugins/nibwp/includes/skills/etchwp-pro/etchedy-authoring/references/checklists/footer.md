# footer — checklist

## Identify
- [ ] Source is `<footer>` at the page bottom, typically multi-column with brand, nav, contact, social, legal.
- [ ] Decided variant: minimal (logo + copyright) / standard (4-col) / mega (6+ col with newsletter).
- [ ] BEM block name: `{brand}-footer`.

## Tokens
- [ ] Background: `--surface-dark` (#0f1117) on dark footers; raw decorative gradient inside brand-scoped files only.
- [ ] Text color: `--footer-text` (rgba(255,255,255,0.65)) for body; `--white` for strong text.
- [ ] Heading `font-size`: `--text-m` (1rem) or `--text-l` (1.125rem). **Never `clamp()`**.
- [ ] Link `font-size`: `--text-s` (0.875rem).
- [ ] Padding-block: `--section-space-m` (5rem) top, `--space-xl` (3rem) bottom.

## Structure
- [ ] Outer `<footer>` with semantic `<nav aria-label="Footer">` wrapping link lists.
- [ ] Each column wraps its `<h3>` heading + `<ul>` link list — heading is always present (visually hidden via `.sr-only` if the design hides it).
- [ ] Copyright year is dynamic: `<time>2024</time>` updated server-side (NOT a hardcoded "2024"). Etch can pass it via a prop or via WP-template tag in the parent template.
- [ ] Newsletter signup (if present): treat as a form — see [form.md](form.md). Do NOT emit raw `<form>`.
- [ ] Social icons: `<a>` with `aria-label` (NOT just an icon — screen readers see nothing without it).

## Behavior
- [ ] No JS unless the footer has a back-to-top button (vanilla `window.scrollTo({top:0, behavior:'smooth'})`).
- [ ] Hover on links: color shift + underline. Transition ≤ 200ms.

## Responsive
- [ ] Multi-column grid collapses to single column under `to-rem(640px)` container width.
- [ ] Brand column stays at the top on mobile; social icons follow.
- [ ] Copyright bar always last.

## Pixel-perfect
- [ ] Column widths match source (often 2fr 1fr 1fr 1fr or 1.5fr 1fr 1fr 1fr 1fr).
- [ ] Border between footer body and copyright bar matches source.

## Accessibility
- [ ] `<footer>` is the page's primary footer (only one per page).
- [ ] All links have visible focus indicators (footer is often forgotten — verify).
- [ ] Social icon contrast against the dark background ≥ 3:1.

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
