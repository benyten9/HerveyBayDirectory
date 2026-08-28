# Pricing table — Bricks element checklist

## Identify
- [ ] Source shows 2-4 plan tiers side-by-side with: tier name, price, feature list, CTA
- [ ] BEM block: `{brand}-pricing` (grid root), `{brand}-plan` (single tier) with children `__tier`, `__price`, `__features`, `__cta`, `__badge`
- [ ] STOP — this is the canonical `extract_to_component` case. 3-4 fixed tiers = component with `variant` property, NOT a loop.

## Component-first approach (preferred for fixed tier count)
- [ ] Define ONE Bricks `section` template named `pricing-tier` with the per-tier subtree + a `_conditions` block for the "featured" badge
- [ ] On the main pricing page, use the `template` element N times with different conditional values OR persist N static instances each with overrides
- [ ] Featured tier styling via `_conditions`: badge element with `_conditions: [{key: "acf-field", acfFieldKey: "is_featured", operator: "==", value: "1"}]`

## Loop approach (only when plans live in a CPT for ops reasons)
- [ ] Use `posts` query loop with `post_type: ["plan"]`
- [ ] ACF fields per plan: `price` (number), `period` (text), `features` (repeater of {label, included}), `cta_url` (url), `is_featured` (bool)
- [ ] Child subtree of `posts` uses dynamic tags: `{post_title}`, `{acf:price}`, `{acf:period}`, etc.

## Grid
- [ ] Outer `block` with `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr)); gap: var(--space-l, 2rem);`
- [ ] Single column below `_mobile_portrait` via auto-fit

## Price formatting
- [ ] Currency symbol + amount + period clearly separated
- [ ] Big amount: `heading` element with `tag="span"` and `var(--text-xxl, 3rem)` font-size
- [ ] Currency: smaller, baseline-aligned, `var(--text-l, 1.25rem)`
- [ ] Period: `text-basic` with `var(--text-s, 0.875rem)`, color `var(--text-muted, rgba(0,0,0,.65))`

## Feature list
- [ ] Use Bricks `list` element (`settings.tag = "ul"`, `settings.items[] = [...]`)
- [ ] Each item icon: `settings.items[i].icon = "check"` for included, `"x"` (struck) for excluded
- [ ] Excluded items: muted color + line-through OR just omit (cleaner)

## CTA
- [ ] Bricks `button` element per tier
- [ ] Featured tier: `button.settings.style = "primary"`. Other tiers: `style = "ghost"` or `outline`
- [ ] CTA url: `{acf:cta_url}` (loop) or static (component variant)

## Featured tier
- [ ] Highlighted via `_cssGlobalClasses = ["{brand}-plan", "{brand}-plan--featured"]`
- [ ] Visual lift: `box-shadow`, slightly larger border-radius, optional ribbon (`{brand}-plan__badge`)
- [ ] Ribbon text: "★ Most popular" or "Best value" — defined via `_conditions` or property variant

## Tokens
- [ ] Card padding: `var(--space-xl, 3rem) var(--space-l, 2rem)`
- [ ] Card radius: `var(--radius-l, 12px)`
- [ ] Card border: `1px solid var(--border-color-light, rgba(0,0,0,.08))`
- [ ] Featured card border: `2px solid var(--primary, #2271b1)`

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
