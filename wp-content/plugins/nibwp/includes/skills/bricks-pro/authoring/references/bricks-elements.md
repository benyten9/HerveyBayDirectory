# Bricks element catalog

Canonical list of Bricks core element `name` values the validator accepts. Custom elements registered by add-ons (Bricks Forge, Frames, etc.) extend this via the `nibwp_bricks_pro_element_whitelist` filter.

The exhaustive whitelist lives in `lib/element-registry.php` — this doc is the agent-facing summary.

## Layout
| name | when to use |
|---|---|
| `section` | Top-level wrapper. Settable `tag`: section / header / footer / article / main / aside / nav |
| `container` | Constrains content-width inside a section |
| `block` | Generic wrapper for grouping (display: block/flex/grid). Settable `tag` |
| `div` | Bare wrapper (no semantic) |

## Content
| name | when to use |
|---|---|
| `heading` | h1–h6 headings (`settings.tag = "h1"`). Use `{post_title}` for content templates. |
| `text` | Multi-paragraph rich text. |
| `text-basic` | Single-paragraph; faster render. Use for short strings. |
| `text-link` | Inline link styled as text (NOT a button). |
| `rich-text` | WP editor block content. |
| `list` | `<ul>` or `<ol>` with `items[]`. |
| `code` | `<pre><code>` block. Settable language for highlighting. |

## Media
| name | when to use |
|---|---|
| `image` | Single image. `settings.image.useFeaturedImage` for content templates. |
| `image-gallery` | 2+ images in a grid (Lightbox + lazy). 4+ siblings → switch to this. |
| `video` | YouTube / Vimeo / MP4. NEVER use raw `<iframe>` in code element. |
| `audio` | Audio file. |
| `icon` | Bricks icon picker (Font Awesome / Heroicons / Custom). |
| `svg` | Inline SVG (source or paste code). For decorative graphics. |
| `logo` | Auto-renders site logo from WP Customizer. |

## Interactive
| name | when to use |
|---|---|
| `button` | CTA. Default for any clickable action. |
| `form` | Bricks native form (`settings.fields[]`). Simple contact / newsletter. |
| `accordion-nested` | New-style accordion (children are individual items). Prefer over `accordion`. |
| `tabs-nested` | New-style tabs. |
| `slider-nested` | New-style slider. Children are slides. |
| `toggle` | Single show/hide toggle. |

## Site / Nav
| name | when to use |
|---|---|
| `nav-menu` | Render an existing WP menu by ID. |
| `nav-nested` | Build nav inside Bricks (dropdowns via children). |
| `breadcrumbs` | Site breadcrumbs (uses Yoast/RankMath/SEOPress when available). |
| `search` | Site search form. |
| `social-icons` | Footer / header social row. `settings.items[]`. |

## Posts / Loops
| name | when to use |
|---|---|
| `posts` | **Query Loop**. `settings.query = WP_Query args`. Children are the template per post. |
| `post-title` | Single-post template: prints `{post_title}` |
| `post-content` | Single-post template: prints the_content() |
| `post-excerpt` | `settings.wordsLimit` |
| `post-meta` | Author + date + categories |
| `post-author` | Author byline |
| `post-comments` | Comments block |
| `post-taxonomy` | Settable `taxonomy` |
| `post-sharing` | Share buttons |
| `related-posts` | Related posts |
| `pagination` | Archive pagination |

## WooCommerce (Woo must be active)
`woocommerce-cart-page`, `woocommerce-checkout-page`, `woocommerce-account-page`, `woocommerce-products`, `woocommerce-product-title`, `woocommerce-product-price`, `woocommerce-product-images`, `woocommerce-product-short-description`, `woocommerce-product-add-to-cart`, `woocommerce-product-meta`, `woocommerce-product-tabs`, `woocommerce-related-products`, `woocommerce-mini-cart`

## Other
| name | when to use |
|---|---|
| `map` | Google Maps. `settings.addresses[]`. |
| `countdown` | Countdown timer. `settings.date`. |
| `counter` | Animated number counter. |
| `progress-bar` | Linear progress bar. |
| `pie-chart` | Pie/donut chart. |
| `animated-typing` | Typing effect with multiple strings. |
| `alert` | Alert / notice box. |
| `divider` | Horizontal rule. |
| `shape-divider` | SVG shape between sections. |
| `shortcode` | Wraps a WP shortcode. Use for form plugins, EDD/Woo widgets, etc. |
| `html` | Raw HTML escape hatch. **Last resort** — validator policies apply. |
| `template` | Embed another Bricks template. |

## Common settings (cross-element)

Every element accepts:

- `_id` (6-char hex) — element ID. Persister mints if omitted.
- `_cssGlobalClasses` (array of class names) — global class references.
- `_cssCustom` (string) — per-element custom CSS. NO `@media`.
- `_padding`, `_margin` — `{ top, right, bottom, left }` per-breakpoint.
- `_typography` — `{ font-family, font-size, font-weight, line-height, letter-spacing, color, text-align }` per-breakpoint.
- `_background` — `{ color, image: {id, url}, position, size, repeat, ... }` per-breakpoint.
- `_border` — `{ width: {top, right, bottom, left}, color, style, radius: {top-left, ...} }` per-breakpoint.
- `_position` — static / relative / absolute / fixed / sticky.
- `_zIndex` — int.
- `_display` — block / inline / inline-block / flex / grid / inline-flex / inline-grid / none.
- `tag` — HTML5 tag override (when the element supports it).
- `_anchor` — id="..." for in-page anchor links.

Per-breakpoint shape (everywhere applicable):

```json
{ "_padding": { "_base": { "top": "6rem" }, "_mobile_portrait": { "top": "3rem" } } }
```

NEVER:

```css
@media (max-width: 480px) { .x { padding-top: 3rem; } }
```
