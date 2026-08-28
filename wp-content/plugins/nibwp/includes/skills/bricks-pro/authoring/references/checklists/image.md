# Image — Bricks element checklist

## Identify
- [ ] Source contains an `<img>` (single image, not gallery — for galleries see `image-gallery`)
- [ ] BEM block: `{brand}-image` (rare — usually just inline on the parent)
- [ ] Bricks element: `image`

## Settings
- [ ] `settings.image` = `{ id, url, alt, title, size }` (use WP media-library id when available, falls back to URL)
- [ ] `settings.image.useFeaturedImage = true` ONLY on content/archive templates (binds to current post)
- [ ] `settings.image.size` set: `thumbnail`, `medium`, `medium_large`, `large`, `full`, OR custom name registered via add_image_size

## Alt + a11y (mandatory)
- [ ] `settings.image.alt` non-empty, generated from: nearest heading > sibling caption > filename > vision description
- [ ] Decorative-only: `settings.image.alt = ""` AND `settings._customAttributes` includes `role="presentation"`
- [ ] If image has a caption visible in source: `settings.caption = "..."` (Bricks wraps in `<figcaption>`)

## Performance
- [ ] `settings.lazyLoad = true` for below-the-fold (default)
- [ ] `settings.lazyLoad = false` + `settings.fetchPriority = "high"` for the FIRST hero image (above-the-fold)
- [ ] `settings.decoding = "async"` on every image
- [ ] Width + height set when known — Bricks renders width/height attrs to reserve layout space (no CLS)

## Responsive (srcset)
- [ ] Use WP media-library images when possible — Bricks auto-generates srcset from registered sizes
- [ ] `settings.sizes` attr: `"(min-width: 60rem) 50vw, 100vw"` (tune to actual layout)
- [ ] If using external URL (not WP attachment), provide multiple `settings.imageMobile` / `settings.imageTablet` for art-direction

## Link wrap
- [ ] When the image links somewhere: `settings.link.type = "internal" | "external" | "lightbox"`. Avoid wrapping a Bricks image element in a separate `text-link` — `settings.link` is the right place.
- [ ] `settings.link.type = "lightbox"` opens fullscreen view (Bricks lightbox); use for portfolio/gallery shots

## Tokens
- [ ] `border-radius` if rounded: `var(--radius, 8px)` or `var(--radius-l, 12px)`
- [ ] `aspect-ratio` if the image must keep a fixed ratio regardless of source dimensions: `aspect-ratio: 4/3;` in `_cssCustom` (Bricks doesn't have a dedicated aspect-ratio setting yet — this is a one-off)

## Per-breakpoint
- [ ] Different image per breakpoint (art-direction) via `settings.imageMobile` etc.
- [ ] `_aspectRatio` per breakpoint if portrait-on-mobile / landscape-on-desktop

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
