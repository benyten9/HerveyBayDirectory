# Testimonial — Bricks element checklist

## Identify
- [ ] Source has a customer quote with attribution (name, role/company, optional avatar)
- [ ] BEM block: `{brand}-testimonial` with children `__quote`, `__author`, `__role`, `__avatar`, `__rating`

## Single vs slider
- [ ] One testimonial → static element subtree
- [ ] Two testimonials → `extract_to_component` candidate (props-based variants)
- [ ] Three+ testimonials → `slider-nested` (1-slide-per-view) OR Query Loop + CPT (if testimonials grow over time — recommender flags this)

## Structure (single)
- [ ] `block` root with `tag="figure"` + `_cssGlobalClasses = ["{brand}-testimonial"]`
- [ ] Quote via `text` (or `rich-text` for formatted) with `tag="blockquote"` + decorative quote marks via `::before` on the global class
- [ ] Attribution: `block` with `tag="figcaption"` containing author + role
- [ ] Avatar via `image` element (round via `border-radius: 50%`)

## Settings
- [ ] Quote text: `{post_excerpt}` when bound to a CPT (e.g. "testimonial" CPT), OR literal text for static
- [ ] Author name: `{post_title}` (CPT) OR literal
- [ ] Role/company: `{acf:role}` + `{acf:company}` OR literal
- [ ] Avatar: `image.settings.image.useFeaturedImage = true` (CPT) OR media-library id

## Rating (when source has stars)
- [ ] Bricks doesn't have a dedicated star-rating element. Use `icon` element repeated 5x with `settings.icon = "star"` + `settings.color` per filled/empty state
- [ ] OR use `svg` element with inline 5-star SVG bound to a numeric ACF field via CSS class swap

## Tokens
- [ ] Quote font-size: `var(--text-l, 1.25rem)` — bigger than body, smaller than h1
- [ ] Quote font-style: `italic` (convention; skip if brand says otherwise)
- [ ] Quote marks: `var(--text-xxl, 3rem)` decorative glyph, `var(--primary, ...)` color, `position: absolute; top: 0; left: 0; opacity: 0.2;`
- [ ] Avatar size: `var(--avatar-size, 3rem)`
- [ ] Card padding: `var(--space-l, 2rem)`

## Accessibility
- [ ] `tag="blockquote"` on the quote (semantic)
- [ ] `tag="figure"` + `tag="figcaption"` on root + attribution (semantic pair)
- [ ] Avatar alt: "Photo of {author name}"

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
