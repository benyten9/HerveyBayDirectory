# Footer — Bricks element checklist

## Identify
- [ ] Source has a bottom-of-page footer: multi-column links / newsletter / social / legal
- [ ] BEM block: `{brand}-footer` with children `__col`, `__brand`, `__nav`, `__newsletter`, `__social`, `__legal`
- [ ] Bricks structure: `section` (tag="footer") → `container` → `block` (grid columns) → child `block`s per column → `legal` band at bottom

## Section
- [ ] `section.settings.tag = "footer"`
- [ ] `section.settings._cssGlobalClasses = ["{brand}-footer"]`
- [ ] When this footer is the global site footer, persist as `template_type = "footer"` (Bricks renders it on every page automatically)

## Columns
- [ ] Multi-column block uses `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: var(--space-l, 2rem);`
- [ ] Per column: `block` with heading + `list` element (NOT hand-rolled ul/li in `html`)
- [ ] Use `nav-menu` element when the source links match a WP menu (saves manual editing)

## Brand col
- [ ] Logo via `logo` element (auto-pulls from Customizer) OR `image` element
- [ ] Brief about/tagline via `text` element

## Newsletter (if present)
- [ ] STOP — treat as a form. Either Bricks `form` element (with Fluent Forms / Mailchimp action) OR `shortcode` wrapping a real form plugin
- [ ] See `checklists/form.md`

## Social
- [ ] Use Bricks `social-icons` element with `settings.items = [{name: "twitter", url: "..."}, {name: "linkedin", url: "..."}, ...]`
- [ ] DO NOT hand-roll SVG buttons in `html` element

## Legal band
- [ ] Wrap copyright + legal links in a separate `block` at the bottom with `border-top: 1px solid var(--border-color-light, rgba(0,0,0,0.08));`
- [ ] Dynamic year: use Bricks dynamic data `{wp:current_year}` or a `text` element with `text = "&copy; {wp:current_year} {site_name}"`
- [ ] `<time>` semantic tag for the year: use Bricks `text-basic` with `settings.tag = "time"`

## Tokens
- [ ] Background: `var(--footer-bg, var(--surface-dark, #1d2327))` for dark footer
- [ ] Text color: `var(--footer-text, var(--text-muted, rgba(255,255,255,0.75)))`
- [ ] Heading: `var(--text-m, 1rem)` — footers don't need huge type
- [ ] Padding: `var(--section-space-l, 4rem) var(--content-padding, 2rem)`

## Per-breakpoint
- [ ] Columns collapse via grid auto-fit (no `@media` needed)
- [ ] Social icons stay horizontal across breakpoints; legal band stacks on `_mobile_portrait`

## Accessibility
- [ ] Every social icon has `aria-label="{platform name}"` via `social-icons settings.items[i].label`
- [ ] Newsletter form has visible label even when only the placeholder is shown

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
