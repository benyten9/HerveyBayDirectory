# Card-grid — Bricks element checklist

## Identify
- [ ] Source has 3+ sibling cards with the same structure (image / heading / text / optional CTA)
- [ ] BEM block: `{brand}-card-grid` (container) + `{brand}-card` (item) with children `__media`, `__title`, `__body`, `__cta`

## STOP — loop check first
- [ ] Did the recommender flag `loop_to_query_cpt`? If yes, did the user accept dynamic CPT+ACF?
  - **Yes** → register CPT, create ACF fields, replace cards with a `posts` element (query loop)
  - **No** → continue as static repetition (each card is a separate Bricks element subtree)

## Static path

- [ ] Outer `block` element with `_cssGlobalClasses = ["{brand}-card-grid"]`
- [ ] Grid via global class: `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, var(--card-min-width, 16rem)), 1fr)); gap: var(--card-gap, 1.5rem);`
- [ ] Card root: `block` with `tag="article"` + `_cssGlobalClasses = ["{brand}-card"]`
- [ ] Card content stack: `display:flex; flex-direction:column; gap: var(--card-stack-gap, 0.75rem);`
- [ ] Equal-height cards: cards stretch (`grid` does this automatically; explicit `align-items:stretch` not needed)

## Dynamic path (Query Loop)

- [ ] One `posts` element with `settings.query = { post_type: [...], posts_per_page: N, orderby, order }`
- [ ] Single card subtree as child of `posts` — Bricks renders one copy per post
- [ ] Heading: `heading.settings.text = "{post_title}"`
- [ ] Body: `text.settings.text = "{post_excerpt:25}"`
- [ ] Image: `image.settings.image.useFeaturedImage = true`
- [ ] CTA link: `button.settings.link.type = "internal"` + `settings.link.postId = "{post_id}"`
- [ ] ACF fields: bind via `{acf:field_name}`

## States
- [ ] Card hover: subtle lift (`transform: translateY(-2px); transition: transform 200ms ease, box-shadow 200ms ease;`) — defined on the global class
- [ ] Card focus-within: visible outline for keyboard navigation

## Tokens
- [ ] Card padding: `var(--card-padding, 1.25rem)` or `var(--space-l)`
- [ ] Card radius: `var(--radius-l, 12px)`
- [ ] Card shadow: `var(--card-shadow, 0 1px 2px rgba(0,0,0,.05))`
- [ ] Heading: `var(--text-l, 1.25rem)` (cards are not h1 territory)

## Per-breakpoint
- [ ] Card-grid collapses to single column below `_mobile_portrait` automatically via auto-fit + minmax
- [ ] No `@media` overrides for column count

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
