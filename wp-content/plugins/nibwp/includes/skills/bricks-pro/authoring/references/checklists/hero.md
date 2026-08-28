# Hero — Bricks element checklist

## Identify
- [ ] Source is a top-of-page banner: eyebrow / H1 / sub / CTA pair / background or visual
- [ ] BEM block: `{brand}-hero` with children `__eyebrow`, `__title`, `__sub`, `__cta`, `__media`
- [ ] Bricks structure: `section` (tag=section or article) → `container` → 2-3 columns/blocks for content + media

## Section
- [ ] `section.settings.tag = "section"` (or `"article"` if standalone meaning)
- [ ] `section.settings._cssGlobalClasses = ["{brand}-hero"]`
- [ ] `section.settings._padding` per-breakpoint (Bricks shape, NOT @media)
- [ ] Background: when source has a background image, set `section.settings.backgroundImage = { id, url }` and `settings.backgroundOverlay` if dark over light

## Heading
- [ ] `heading.settings.tag = "h1"` (h1 only when this hero is the page top — h2 otherwise)
- [ ] `heading.settings.text` = source title (or `{post_title}` for content templates)
- [ ] Token: `font-size: var(--text-xxl, 3rem)` for desktop; switch to `--text-xl` / `--text-l` on smaller breakpoints via Bricks settings (NEVER `clamp()`)
- [ ] Eyebrow above the heading: `text` element with decorative dash before content (`::before { content: "—"; }`)

## CTA pair
- [ ] If 2 CTAs, wrap them in a `block` element with `_cssGlobalClasses = ["{brand}-hero__cta"]`
- [ ] Block uses `display:flex; gap: var(--space-m, 1rem); flex-wrap: wrap;`
- [ ] Primary CTA = `button` with `{brand}-button--primary`
- [ ] Secondary CTA = `button` with `{brand}-button--ghost` or `text-link` for tertiary

## Media
- [ ] Image: Bricks `image` element with `lazyLoad = false`, `fetchpriority = "high"` (above-the-fold)
- [ ] Alt text mandatory (never empty for content images)
- [ ] If decorative SVG, use Bricks `svg` element, NOT inline `code`
- [ ] If video background, use Bricks `video` element with `videoType = "mp4"`, `loop=true`, `muted=true`, `autoplay=true`, `playsinline=true`

## Content width
- [ ] `container.settings._maxWidth = "var(--content-width, 1280px)"` (or `--content-width-narrow` for centered text)
- [ ] No fixed `width` literals on the container

## Per-breakpoint
- [ ] Layout switches from 2-col → 1-col below the layout breakpoint (Bricks `_mobile_landscape` or `_mobile_portrait`)
- [ ] Decorative background image hides on `_mobile_portrait` if it covers text

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
