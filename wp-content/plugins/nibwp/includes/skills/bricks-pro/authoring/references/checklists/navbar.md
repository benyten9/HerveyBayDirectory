# Navbar — Bricks element checklist

## Identify
- [ ] Source has a horizontal navigation bar at top of page: logo + nav links + optional CTA
- [ ] BEM block: `{brand}-navbar` (root) with children `__brand`, `__nav`, `__cta`
- [ ] Bricks structure: `section` (tag="header") → `container` → `block` (flex row) with the 3 children

## Use Bricks nav elements
- [ ] **Use `nav-menu` element** when there's an existing WP menu the user wants to render — picks the menu via `settings.menu`
- [ ] **Use `nav-nested` element** when the nav structure should be built inside Bricks (more design control). Children are individual `text-link` / `dropdown-nested` elements.
- [ ] DO NOT hand-roll `<ul><li>` inside an `html` element

## Brand mark
- [ ] `image` element for logo with `settings.image = {id, url, alt: "{site name} logo"}`
- [ ] OR Bricks `logo` element which auto-renders the site logo from WP Customizer
- [ ] Brand height = `var(--navbar-brand-height, 2.5rem)` — never a hardcoded px

## Sticky behavior
- [ ] If source nav sticks: `section.settings.position = "sticky"` + `section.settings._cssCustom = ".brxe-{id} { top: 0; }"` (or a global class with position: sticky)
- [ ] Scroll-shadow class swap: set up via Bricks Interactions panel (NOT raw JS in an `html` element)

## Mobile menu
- [ ] Use `nav-nested` element's built-in mobile toggle (Bricks renders a hamburger automatically when configured)
- [ ] OR add a `button` element with `settings.icon = "hamburger"` + a Bricks Interaction `[On click] → Toggle class` on the nav

## States
- [ ] Active link: `nav-menu` settings handles `aria-current` automatically when a menu item matches the current URL; style it via the global class with `[aria-current]` selector
- [ ] Hover: `nav-nested` items use `var(--nav-link-color-hover, var(--primary))` — defined on a global class

## Tokens
- [ ] Padding: `var(--navbar-padding, 1rem 2rem)` per-breakpoint
- [ ] Background: `var(--surface-light, #fff)` (or transparent over a hero with `backgroundOverlay`)
- [ ] Border-bottom: `1px solid var(--border-color-light, rgba(0,0,0,0.08))` for separation

## Per-breakpoint
- [ ] Nav collapses to mobile menu at `_mobile_landscape` (768px default)
- [ ] Brand stays visible at all breakpoints (consider smaller variant on `_mobile_portrait`)

## Accessibility
- [ ] `section.settings.tag = "header"` OR use a separate `nav` element inside the section
- [ ] Nav element gets `aria-label="Primary navigation"` (Bricks renders this when set via `settings.label`)
- [ ] Mobile menu toggle button has `aria-expanded` (Bricks Interactions binding)
- [ ] Focus-trap when mobile menu open (Bricks renders this for `nav-nested` mobile-menu)

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
