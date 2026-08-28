# Worked Example — Figma "Product Page" → WooCommerce single-product template (Elementor)

This walks the **figma-pro** orchestrator through its hardest case: a Figma **product
page** converted into a **WooCommerce single-product template** built with **Elementor +
Woo widgets**. The critical difference from a static landing page: figma-pro maps the
**design** onto WooCommerce **template parts, hooks, and dynamic product data** — it does
**not** replace WooCommerce logic. The gallery, price, add-to-cart, reviews, and related
products stay bound to live Woo data; figma-pro only re-skins and re-arranges them.

Canonical hierarchy used throughout:

> **Figma Frame → Sections → Containers → Components → Variables → Styles → Assets → WordPress Elements**

---

## 1. Input

**User prompt**

> Turn this Figma product page into my WooCommerce product template. Site uses Elementor.
> `https://www.figma.com/design/Lm4Kp9Qr2Xt7Nb3Vw6/Verde-Store?node-id=140-2210&t=Zq8...`

**Resolved target**

```
fileKey  = Lm4Kp9Qr2Xt7Nb3Vw6
node-id  = 140:2210        (Figma URL encodes it as 140-2210)
frame    = "PDP / Default" (1440 × 4180)
builder  = Elementor       (auto-detected; Elementor Pro + WooCommerce Builder active)
mode     = woo_template     (target is a Theme Builder "Single Product" template, not a page)
```

figma-pro pulls the usual three inputs through NibWP's Figma integration abilities:

- `figma-get-node` → the node tree for `140:2210`
- `figma-export-node` → a **2× PNG** of the frame — the visual ground truth
- `figma-get-variables` → the file's published Variables (brand color, spacing, type)

---

## 2. Step 1 — Analyze / detect

The decisive step here is not "what sections exist" but "which regions are **dynamic Woo
data** vs **static design**." figma-pro classifies every region against the WooCommerce
single-product model. Because this requires component + dynamic-data architecture (loops,
Woo widget binding, template hooks), the **design-complexity-score is ~78** — higher than
a marketing page of similar visual weight.

```
DETECTION REPORT — "PDP / Default" (140:2210)
─────────────────────────────────────────────
Page:      PDP / Default  (1440 × 4180)   Target: WooCommerce Single Product template
Complexity score: 78 / 100   (dynamic Woo binding + 2 dynamic loops + template-hook mapping)

Regions detected  →  WooCommerce mapping  (DYNAMIC unless marked static):
  1. Product gallery     140:2231  → Woo "Product Images" widget        DYNAMIC (product media)
  2. Product title       140:2250  → Woo "Product Title" widget         DYNAMIC (post_title)
  3. Price               140:2258  → Woo "Product Price" widget         DYNAMIC (price/sale)
  4. Variation swatches  140:2266  → Woo "Additional Info"/variations   DYNAMIC (attributes)
  5. Add-to-cart         140:2280  → Woo "Add To Cart" widget           DYNAMIC (Woo form + qty)
  6. Trust badges        140:2295  → static image row                   STATIC  (design only)
  7. Description tabs     140:2310  → Woo "Product Data Tabs" widget     DYNAMIC (desc/attrs)
  8. Reviews             140:2360  → Woo "Product Reviews" module        DYNAMIC (comments/ratings)
  9. Related products    140:2402  → Woo "Related Products" widget       DYNAMIC (loop, query)

Components detected (Figma component/instance sets):
  • Review Card       master 52:14   → 3 instances   ⟲ DEDUPE → dynamic loop (comments)
  • Related Card      master 52:40   → 4 instances   ⟲ DEDUPE → dynamic loop (Woo query)
  • Swatch            master 52:70   → 5 instances   ⟲ DEDUPE → Woo variation term

Design tokens (sampled from Variables + styles):
  Color    brand #0F766E   ink #111827   muted #6B7280   surface #FFFFFF   sale #DC2626
  Spacing  scale 4 · 8 · 16 · 24 · 40 · 64
  Type     H1 40/48 · Price 32/40 · Body 16/26 · Label 13/18   (family: Manrope)

KEY INSIGHT: 8 of 9 regions are LIVE Woo data. figma-pro re-skins and re-lays-out the
Woo widgets — it does NOT reimplement gallery/cart/reviews as static markup.
Proceeding to token establishment.
```

---

## 3. Step 2 — Establish tokens

Brand color, spacing, and type map onto `var(--token, fallback)`. Elementor's own global
kit is *not* overwritten; figma-pro emits tokens as CSS custom properties consumed by the
widgets' Advanced → Custom CSS and by Elementor global classes.

> Rule: **never `clamp()` for font-size.** Price and headings are `var(--text-*, <px>)` only.

```jsonc
// Figma Variable  →  CSS token (with fallback)
{
  "colors": {
    "Brand/Green":   "var(--brand,   #0F766E)",
    "Text/Ink":      "var(--ink,     #111827)",
    "Text/Muted":    "var(--muted,   #6B7280)",
    "State/Sale":    "var(--sale,    #DC2626)",   // Woo sale price color
    "Surface/Base":  "var(--surface, #FFFFFF)"
  },
  "spacing": {
    "space/1": "var(--space-2xs,  4px)",
    "space/2": "var(--space-xs,   8px)",
    "space/3": "var(--space-s,   16px)",
    "space/4": "var(--space-m,   24px)",
    "space/5": "var(--space-l,   40px)",
    "space/6": "var(--space-xl,  64px)"
  },
  "type": {
    "H1":    "var(--text-xl,  40px)/48px",   // NO clamp()
    "Price": "var(--text-l,   32px)/40px",
    "Body":  "var(--text-m,   16px)/26px",
    "Label": "var(--text-s,   13px)/18px"
  }
}
```

**Flagged (no Variable backing):**

```
⚠ Trust-badge row bg #F0FDF4  — raw hex, not a Variable → suggest --brand-050
⚠ Star rating gold  #F59E0B   — Woo default, kept as-is (rating UI owned by Woo)
```

---

## 4. Step 3 — Component reuse (as dynamic loops, not static copies)

The Figma frame shows **3 Review Card instances** and **4 Related Card instances**. On a
static page figma-pro would dedupe these into one component fed by a data array. On a
**Woo template** it does something stronger: it maps each instance set onto a **dynamic
loop bound to live Woo data** — the Figma instances are treated as the *design of one loop
item*, and the count/content comes from the product at render time, not from the mock.

**Review Card → Woo reviews loop (comments), not 3 static cards**

```jsonc
{
  "component": "verde-review-card",
  "boundTo": "woocommerce/product-reviews",     // live comments + ratings
  "loop": true,                                  // count = actual review count, not 3
  "fields": {
    "avatar":  "comment_author_avatar",
    "author":  "comment_author",
    "rating":  "comment_meta:rating",
    "body":    "comment_content",
    "date":    "comment_date"
  },
  "designFrom": "figma://52:14"                  // 3 Figma instances = the item skin only
}
```

**Related Card → Woo related-products loop (query), not 4 static cards**

```jsonc
{
  "component": "verde-related-card",
  "boundTo": "woocommerce/related-products",     // Woo query: same cat/tag, in stock
  "loop": true,                                  // count = Woo query result, capped by widget
  "fields": {
    "image": "product_thumbnail",
    "title": "product_title",
    "price": "product_price_html",               // Woo formats sale/from pricing
    "link":  "product_permalink"
  },
  "designFrom": "figma://52:40"
}
```

Result: the mock's 3 + 4 instances become **2 dynamic loop items**, each rendering the
real product's reviews and related products. Editing a card's design updates the loop
skin; the *content* is always live Woo data.

---

## 5. Step 4 — Structure mapping + Elementor / Woo specifics

The layout maps onto Elementor **sections → columns → widgets**, but every dynamic region
resolves to a **Woo widget**, not a generic container. The two-column PDP top (gallery
left, summary right) is a classic Elementor inner section.

**PDP top — before (Figma auto-layout) → after (Elementor + Woo widgets)**

```
Frame "PDP top" 140:2220  (HORIZONTAL, itemSpacing 40, align START)
   ↓
section.verde-pdp-top
  ├ column (55%)  →  [Woo] Product Images   widget   (gallery, zoom, thumbnails — Woo owns it)
  └ column (45%)  →  container, gap: var(--space-m,24px)
        ├ [Woo] Product Title    widget   → re-skinned: font var(--text-xl,40px), color var(--ink)
        ├ [Woo] Product Price    widget   → sale color var(--sale,#DC2626)
        ├ [Woo] Product variations/swatches (verde-swatch skin on term links)
        └ [Woo] Add to Cart      widget   → button bg var(--brand,#0F766E), qty kept
```

**Elementor specifics — critical adapter contract**

```
▸ Elementor stores the whole layout as a JSON string in post meta `_elementor_data`.
▸ That JSON MUST be wp_slash()'d before update_post_meta, or Elementor corrupts on load
  (unescaped quotes/backslashes break the editor).
▸ figma-pro NEVER writes _elementor_data or any meta directly. It hands a payload to the
  elementor adapter, and the ADAPTER performs the wp_slash() + update_post_meta write.
▸ Woo dynamic widgets (Product Images / Price / Add To Cart / Reviews / Related) are
  emitted as their real widgetType — figma-pro supplies only styling + placement, never a
  static re-implementation of Woo behavior.
```

**Dynamic-data binding (ACF / Woo fields → widgets)**

```jsonc
// Elementor dynamic tags — bound at render, not baked
{
  "verde-pdp-top__title":  { "widget": "woocommerce-product-title",  "dynamic": "post_title" },
  "verde-pdp-top__price":  { "widget": "woocommerce-product-price",  "dynamic": "woo:price_html" },
  "verde-pdp-top__cart":   { "widget": "woocommerce-product-add-to-cart" },
  "verde-badge__eco":      { "widget": "heading", "dynamic": "acf:eco_certification" }, // ACF field
  "verde-tabs":            { "widget": "woocommerce-product-data-tabs" }
}
```

---

## 6. Step 5 — Delegate to Elementor

figma-pro assembles the hand-off payload and calls the **elementor** builder adapter. It
does **not** write `_elementor_data` — the adapter builds the Elementor element tree,
`wp_slash()`es the JSON, and writes the meta on the Theme-Builder template post.

```jsonc
// hand-off → elementor builder adapter
{
  "target": {
    "post_type": "elementor_library",
    "template_type": "single-product",     // Woo Theme Builder single-product template
    "title": "Verde — Single Product",
    "status": "draft",
    "builder": "elementor",
    "condition": "product_all"             // where the template applies (draft-safe)
  },
  "tokens": { /* --brand / --space-* / --text-* map from Step 2 */ },
  "components": ["verde-review-card", "verde-related-card", "verde-swatch"],
  "dynamic": true,
  "tree": [
    { "elType": "section", "settings": { "_cssClasses": "verde-pdp-top" }, "elements": [
      { "elType": "column", "settings": { "_column_size": 55 }, "elements": [
        { "elType": "widget", "widgetType": "woocommerce-product-images",
          "settings": { "_cssClasses": "verde-pdp-top__gallery" } }
      ]},
      { "elType": "column", "settings": { "_column_size": 45 }, "elements": [
        { "elType": "widget", "widgetType": "woocommerce-product-title",
          "settings": { "_cssClasses": "verde-pdp-top__title" } },
        { "elType": "widget", "widgetType": "woocommerce-product-price",
          "settings": { "_cssClasses": "verde-pdp-top__price" } },
        { "elType": "widget", "widgetType": "woocommerce-product-add-to-cart",
          "settings": { "_cssClasses": "verde-pdp-top__cart" } }
      ]}
    ]}
    // …tabs, reviews (loop), related (loop)
  ]
}
```

The adapter runs its pipeline and — crucially — owns the meta write:

```
elementor adapter:  validate ▸ score ▸ dry_run ▸ persist

  validate  ✔ Woo widgetTypes valid  ✔ tokens resolve  ✔ no clamp() font-size
            ✔ no static re-impl of dynamic regions  ✔ classes conform
  score     84 / 100   (−16: 1 missing font, gallery zoom style approximated, 1 raw hex)
  dry_run   ✔ template renders against a sample product, 0 widget errors
  persist   → elementor_library #6620 (draft)
            → _elementor_data = wp_slash(json_encode(tree))  ← ADAPTER writes it, not figma-pro
```

If the payload had re-implemented add-to-cart as a static button, or tried to write
`_elementor_data` without slashing, the adapter would **reject** it — Woo logic and the
meta-write contract are non-negotiable.

---

## 7. Step 6 — Verify (structural for dynamic regions, pixel for static)

Verification here is **two-track**, and this nuance matters. Static, design-owned regions
(hero layout, trust badges, spacing, typography) are pixel-diffed against the Figma export.
But the **dynamic Woo regions render live product data** — the reviews, prices, and related
products on the *real* store differ from the *mock* content in Figma. Pixel-diffing those
would fail for the wrong reason. So figma-pro verifies dynamic regions **structurally**:
the right Woo widget is present, in the right place, with the right skin — not that pixels
match a mock that was never meant to be the live content.

```jsonc
// verification report
{
  "viewport": "1440×4180",
  "reference": "figma-export://140:2210@2x  (sample product data)",
  "render":    "playwright://elementor-template-6620?product=sample-tote",
  "tracks": {
    "pixel":       [   // static / design-owned regions
      { "region": "PDP layout (columns/spacing)", "match": 98.9 },
      { "region": "Trust badges",                 "match": 99.3 },
      { "region": "Typography / brand color",     "match": 99.0 }
    ],
    "structural":  [   // live Woo data — presence + placement + skin, NOT pixel
      { "region": "Product gallery", "widget": "woocommerce-product-images", "present": true, "skin": "ok" },
      { "region": "Price",           "widget": "woocommerce-product-price",  "present": true, "sale_color": "ok" },
      { "region": "Add to cart",     "widget": "woocommerce-product-add-to-cart", "present": true, "brand_btn": "ok" },
      { "region": "Reviews loop",    "widget": "woocommerce-product-reviews", "present": true, "loop": "bound" },
      { "region": "Related loop",    "widget": "woocommerce-related-products","present": true, "loop": "bound" }
    ]
  },
  "note": "Dynamic regions verified structurally, not pixel-exact: content is live product data, so a mock-pixel diff is not a correctness signal."
}
```

```
ℹ Static regions ≥ 98% ✔. Dynamic Woo regions verified structurally (present, placed,
  skinned) — intentionally NOT pixel-compared, because they render the real product's
  gallery/price/reviews, which will never equal the Figma mock content.
```

---

## 8. Step 7 — Result

```
✅ Draft created — "Verde — Single Product" (elementor_library #6620, status: draft)

Builder        elementor    (Elementor Pro + Woo widgets; template_type = single-product)
Complexity     78 / 100     (dynamic Woo binding + 2 dynamic loops + template-hook mapping)
Verify         Static 98.9–99.3% pixel ✔ · Dynamic regions structurally verified ✔
Woo logic      Preserved — gallery/price/cart/reviews/related are LIVE widgets, not static
Components     2 dynamic loops (reviews → comments, related → Woo query) + 1 swatch skin
Tokens         5 brand/spacing/type vars mapped from Figma Variables (Woo star gold left to Woo)
Meta write     _elementor_data written by the elementor ADAPTER (wp_slash'd) — figma-pro wrote none

Warnings
  ⚠ Missing font: Manrope not loaded on site → headings use fallback (minor metric drift)
  ⚠ Gallery zoom/lightbox styled approximately — Woo/Elementor owns interaction; skin re-applied
  ⚠ 1 raw color not backed by a Variable → #F0FDF4 (trust-badge bg) → suggest --brand-050
  ⚠ Template condition set to "All Products" but left as DRAFT — not yet live on the store

Preview  https://verde.example/?p=6620&elementor_library=verde-single-product&preview=true
Next     Review against 2–3 real products → confirm variation swatches → publish template.
```

The template is left as a **draft** with its display condition unpublished. figma-pro never
auto-publishes a Woo template: swapping a live single-product template affects the whole
store, so the human reviews it against several real products before going live.
