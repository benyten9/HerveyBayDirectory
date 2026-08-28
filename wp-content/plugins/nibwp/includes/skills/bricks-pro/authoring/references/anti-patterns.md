# Bricks Pro anti-patterns

Every DO/DO-NOT pair the validator policies. Use this as the canonical reference before submitting `dry_run: true`.

## 1. Element name not in catalog
**DO NOT** invent element names (`super-button`, `cool-card`, `custom-hero`).
**DO** use names from `references/bricks-elements.md`. The validator rejects unknown names with rule id `bricks_element_unknown`.

## 2. Inline `style="..."` attribute
**DO NOT** put `style="color:red; padding:1rem"` inside `settings.text` or `settings.code`.
**DO** move declarations to a global class (`_cssGlobalClasses = ["{brand}-foo"]`) or `settings._cssCustom`. Inline `style` overrides defeat Bricks' per-breakpoint system. Rule id `bricks_inline_style_attr`.

## 3. `@media` inside `_cssCustom`
**DO NOT** write `@media (max-width: 768px) { … }` inside `settings._cssCustom`.
**DO** set per-breakpoint values using the Bricks setting shape:
```json
{ "padding": { "_base": "6rem 2rem", "_mobile_portrait": "3rem 1rem" } }
```
Bricks renders per-breakpoint blocks. Rule id `bricks_inline_media_query`.

## 4. Hardcoded color literals
**DO NOT** write `background: #336699;` or `color: rgb(50, 50, 50);` outside a `var()` fallback.
**DO** wrap in a token: `background: var(--primary, #336699);`
**Exception:** values stored in `nibwp_user_defaults.bricks_brand_color` / `bricks_brand_color_2` (configured via `nibwp/preferences-set`). Rule id `bricks_hardcoded_color`.

## 5. Hardcoded `font-size` literals
**DO NOT** write `font-size: 32px;` directly.
**DO** wrap in a token: `font-size: var(--text-xxl, 2rem);`.
Pick the closest `--text-*` from `acss-tokens.md` (when ACSS active) or Bricks Design System (when not). Rule id `bricks_hardcoded_font_size`.

## 6. Missing brand prefix on global classes
**DO NOT** name a global class `card`, `button-primary`, or `nav`.
**DO** prefix with the brand: `{brand}-card`, `{brand}-button--primary`, `{brand}-nav`. Utility hooks like `is-active`, `has-error`, `sr-only` are exempt (allowlist in `validator.php`). Rule id `bricks_missing_brand_prefix`.

## 7. Per-element CSS instead of a global class
**DO NOT** put every style declaration in `settings._cssCustom`. Two cards with identical styling end up with two copies of the CSS.
**DO** define a global class once + reference it from both cards. Rule id `bricks_missing_global_class` (warning).

## 8. Raw `<form>` HTML
**DO NOT** put `<form action="/submit">…</form>` inside a `code` / `html` element.
**DO** use the native Bricks `form` element with `settings.fields`, OR the `shortcode` element wrapping a real form plugin via `nibwp/forms-manage`. Rule id `bricks_static_form_html`.

## 9. Raw YouTube/Vimeo iframe
**DO NOT** paste `<iframe src="https://youtube.com/embed/abc">…</iframe>` in a `code` element.
**DO** use the native `video` element: `{ name: "video", settings: { videoType: "youtube", url: "https://youtube.com/watch?v=abc" } }`. Rule id `bricks_raw_iframe_provider`.

## 10. Invalid template_type
**DO NOT** invent template types (`landing`, `homepage`, `page`).
**DO** pick from: `header / footer / content / section / archive / error / popup`. Rule id `bricks_template_type_invalid`.

## 11. Query Loop without `settings.query`
**DO NOT** use the `posts` element with empty settings.
**DO** provide `{ post_type, posts_per_page, orderby, order, ... }`. See `references/query-loops.md`. Rule id `bricks_invalid_query_loop`.

## 12. ACSS-namespace tokens when ACSS isn't active
**DO NOT** use `var(--text-xl)` / `var(--space-l)` / `var(--primary)` on a site that doesn't have Automatic.css installed.
**DO** EITHER: install ACSS, OR bake fallback literals (`var(--text-xl, 1.25rem)` is fine — but on no-ACSS sites the validator suggests baking the literal directly), OR use Bricks Design System variables (Bricks → Settings → Design System) instead. Rule id `acss_absent_tokens_used`.

## 13. Static repetition when ≥3 sibling cards exist
**DO NOT** persist 6 identical static `block` cards.
**DO** accept the recommender's `loop_to_query_cpt` suggestion: register CPT, create ACF fields, replace with a `posts` query loop. Soft rule via recommender, not validator.

## 14. Static text in `content` templates
**DO NOT** set `heading.text = "Welcome to my site"` in a template that will be rendered for every single post.
**DO** use `heading.text = "{post_title}"` so each post renders its own title. Soft rule via recommender (`dynamic_data_hint`).

## 15. Hand-rolled nav in `html` element
**DO NOT** paste `<ul><li><a href="/">Home</a></li></ul>` in a `code` / `html` element.
**DO** use Bricks `nav-menu` (renders a WP menu by ID) or `nav-nested` (build inside Bricks). Both handle mobile toggle + a11y.

## 16. Mixed reuse — copy-paste across templates
**DO NOT** copy a 200-element subtree from one template to another.
**DO** persist as a Bricks `section`-type template + reference it via the `template` element. One source of truth.
