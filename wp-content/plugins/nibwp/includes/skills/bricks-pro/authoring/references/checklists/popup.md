# Popup template — Bricks checklist

## Identify
- [ ] User wants a modal, newsletter signup overlay, exit-intent popup, or info dialog
- [ ] `template_type = "popup"` is the right Bricks template type

## Trigger
- [ ] Bricks popups are triggered via Bricks Interactions panel:
  - **On page load** — set delay (3-10s typically)
  - **On click** — element click triggers popup ID
  - **On hover** — rare; UX rule of thumb says no
  - **On exit intent** — mouseleave at top of viewport
  - **On scroll** — % of page scrolled
  - **After idle** — N seconds of no interaction
- [ ] DO NOT bake the trigger into `_cssCustom` or JS — use Bricks Interactions

## Display conditions
- [ ] `_conditions` on the popup template controls WHO sees it:
  - `user-logged-in: false` — only non-logged-in visitors
  - `is-front-page: true` — only on homepage
  - `cookie: popup-dismissed = absent` — only when user hasn't dismissed before
- [ ] Once-per-session: Bricks built-in `settings.popupOncePerSession = true`
- [ ] Skip on specific URLs: `_conditions[].key = "url-path"`, `operator = "not_contains"`, `value = "/checkout"`

## Structure
- [ ] Popup is its own `bricks_template` post (template_type=popup)
- [ ] Root element: `section` with `_cssGlobalClasses = ["{brand}-popup"]`
- [ ] Bricks renders the backdrop automatically — DO NOT hand-roll a fullscreen wrapper
- [ ] Close button: Bricks renders an X via `settings.closeButton = true`; styling configurable

## Common variants
- [ ] **Newsletter** — heading + sub + `form` element (or shortcode) + dismiss link
- [ ] **Promo banner** — image + CTA + dismiss
- [ ] **Cookie consent** — text + Accept/Reject buttons (legal compliance — usually a dedicated plugin)
- [ ] **Exit-intent** — strong copy + CTA + dismiss

## Accessibility (mandatory)
- [ ] Bricks renders `role="dialog"` + `aria-modal="true"` automatically when configured
- [ ] First focusable element receives focus on open
- [ ] Escape key dismisses (Bricks built-in)
- [ ] Click outside dismisses (Bricks setting)
- [ ] Focus trap (Bricks built-in)
- [ ] After dismiss, focus returns to the trigger element

## Performance
- [ ] Lazy-render — Bricks delays popup HTML until trigger fires (default)
- [ ] Background image inside popup: `settings.lazyLoad = true`
- [ ] Heavy media (video, animation) — load on trigger, not on parent page load

## Sizing
- [ ] Max-width: `var(--popup-max-width, 32rem)` for narrow informational popups
- [ ] Full-width on mobile (`_mobile_portrait`) with internal padding
- [ ] Max-height: `90vh` with `overflow: auto` for long content

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
