# Worked Example — Figma "Homepage" landing page → EtchWP (native build)

This walks the **figma-pro** orchestrator end-to-end on a real conversion. figma-pro
never hand-authors HTML: it resolves the frame, reads the Figma model, establishes
a token system, dedupes components, then **delegates the build to the etchwp-pro
builder skill** and **verifies** the render against the Figma export before saving a draft.

Canonical hierarchy used throughout:

> **Figma Frame → Sections → Containers → Components → Variables → Styles → Assets → WordPress Elements**

---

## 1. Input

**User prompt**

> Convert this Figma landing page into EtchWP:
> `https://www.figma.com/design/kQ9xLm2pR7aVtN4wZ8/Acme-Marketing?node-id=214-1183&t=Xh2f...`

**Resolved target**

```
fileKey  = kQ9xLm2pR7aVtN4wZ8
node-id  = 214:1183        (Figma URL encodes it as 214-1183)
frame    = "Homepage" (1440 × 5820)
```

figma-pro pulls three things through NibWP's Figma integration abilities before it
reasons about anything:

- `figma-get-node` → the node tree for `214:1183` (sections, auto-layout, text, instances)
- `figma-export-node` → a **2× PNG** of the frame (2880 px wide) — the verification ground truth
- `figma-get-variables` → the file's published Variables (colors, spacing, type)

---

## 2. Step 1 — Analyze / detect

figma-pro reports what it found before touching the builder:

```
DETECTION REPORT — "Homepage" (214:1183)
────────────────────────────────────────
Page:      Homepage  (1440 × 5820, 6 top-level auto-layout frames)

Sections (top-level frames, in flow order):
  1. Hero          214:1201   auto-layout ▸ vertical, center
  2. Features      214:1240   auto-layout ▸ vertical  (grid of 6)
  3. Testimonials  214:1305   auto-layout ▸ horizontal (scroll row)
  4. Pricing       214:1350   auto-layout ▸ horizontal (grid of 3)
  5. CTA           214:1402   auto-layout ▸ vertical, center
  6. Footer        214:1440   auto-layout ▸ horizontal, 4 columns

Components detected (Figma component/instance sets):
  • Button Primary     master 12:88    → 5 instances
  • Button Secondary   master 12:92    → 3 instances
  • Feature Card       master 30:40    → 6 instances   ⟲ DEDUPE
  • Pricing Box        master 30:77    → 3 instances   ⟲ DEDUPE

Design tokens (sampled from Variables + styles):
  Color    primary  #2563EB   accent #F59E0B   base #0F172A   surface #FFFFFF
  Spacing  scale    4 · 8 · 16 · 24 · 32 · 64
  Type     Display 64/72 · H2 40/48 · Body 18/28   (family: Inter)

Plan: 6 sections, 2 reusable components, ~9 unique styles.
Proceeding to token establishment.
```

---

## 3. Step 2 — Establish tokens

Figma Variables map **1:1** onto ACSS `var(--token, fallback)` tokens. Every value that
came from a real Variable becomes a token reference with a px/em fallback. Values with
**no backing Variable** (raw hex, magic numbers) are flagged, not silently tokenized.

> Rule: **never `clamp()` for font-size.** Type tokens are `var(--text-*, <px>)` only.

```jsonc
// Figma Variable  →  ACSS token (with fallback)
{
  "colors": {
    "Brand/Primary":   "var(--primary, #2563EB)",
    "Brand/Accent":    "var(--accent,  #F59E0B)",
    "Text/Base":       "var(--base,    #0F172A)",
    "Surface/Card":    "var(--surface, #FFFFFF)"
  },
  "spacing": {
    "space/1": "var(--space-xs,  4px)",
    "space/2": "var(--space-s,   8px)",
    "space/3": "var(--space-m,  16px)",
    "space/4": "var(--space-l,  24px)",
    "space/5": "var(--space-xl, 32px)",
    "space/6": "var(--space-xxl,64px)"
  },
  "type": {
    "Display": "var(--text-xxl, 64px)/72px",   // NO clamp()
    "H2":      "var(--text-xl,  40px)/48px",
    "Body":    "var(--text-m,   18px)/28px"
  }
}
```

**Flagged (no Variable backing):**

```
⚠ Hero badge fill  #EAF1FF   — raw hex, not a Variable → suggest tokenizing as --primary-050
⚠ Footer divider   #1E293B   — raw hex, not a Variable → suggest tokenizing as --base-800
```

These two raws are carried through the build as literals but surfaced as warnings in
the final report so the user can promote them to Variables later.

---

## 4. Step 3 — Component dedupe

The Figma frame contains **6 Feature Card instances** and **3 Pricing Box instances**.
figma-pro does **not** emit 6 + 3 duplicated block trees. It collapses each instance set
into **one Etch component** with content fields, then reuses it.

**Feature Card → one component, 6 data rows**

```jsonc
{
  "component": "nibwp-feature-card",
  "fields": { "icon": "asset", "title": "text", "description": "text", "link": "url" },
  "instances": [
    { "icon": "bolt.svg",    "title": "Fast",       "description": "Sub-second loads.",      "link": "#speed" },
    { "icon": "lock.svg",    "title": "Secure",     "description": "Encrypted by default.",  "link": "#security" },
    { "icon": "scale.svg",   "title": "Scalable",   "description": "Grows with you.",        "link": "#scale" },
    { "icon": "wrench.svg",  "title": "Flexible",   "description": "Configure anything.",    "link": "#flex" },
    { "icon": "chart.svg",   "title": "Insightful", "description": "Live analytics.",        "link": "#stats" },
    { "icon": "heart.svg",   "title": "Loved",      "description": "9.6/10 satisfaction.",   "link": "#love" }
  ]
}
```

**Pricing Box → one component, 3 data rows** (Starter / Pro / Enterprise) — same shape,
fields `{ plan, price, period, features[], cta }`.

Result: **2 component definitions**, driven by data, instead of **9 hand-duplicated trees**.
Any later edit to the card's padding or radius changes all six at once.

---

## 5. Step 4 — Structure mapping

Figma **auto-layout** maps directly onto Etch **section/container flex**. BEM class names
are generated flat: `{brand}-{component}__{element}--{mod}` → here brand = `nibwp`.

**Hero — before (Figma auto-layout)**

```
Frame "Hero" 214:1201
  layoutMode      = VERTICAL
  primaryAxisAlign= CENTER
  counterAxisAlign= CENTER
  itemSpacing     = 24        (space/4)
  padding         = 64 all    (space/6)
  child row "CTA group"
    layoutMode    = HORIZONTAL
    itemSpacing   = 24
    align         = CENTER
```

**Hero — after (Etch section/container flex, tokenized)**

```
section.nibwp-hero
  └ .nibwp-hero__inner        display:flex; flex-direction:column;
                              align-items:center; justify-content:center;
                              gap: var(--space-l, 24px);
                              padding: var(--space-xxl, 64px);
      ├ .nibwp-hero__eyebrow  (etch/text)
      ├ .nibwp-hero__title    (etch/text)  font-size: var(--text-xxl, 64px)
      ├ .nibwp-hero__sub      (etch/text)  font-size: var(--text-m, 18px)
      └ .nibwp-hero__cta      display:flex; flex-direction:row;
                              align-items:center; gap: var(--space-l, 24px)
          ├ .nibwp-hero__cta--primary   (Button Primary instance)
          └ .nibwp-hero__cta--secondary (Button Secondary instance)
```

Every section repeats this pass: auto-layout axis → `flex-direction`, `itemSpacing` →
`gap` token, `padding` → padding token, alignment → `align-items`/`justify-content`.

---

## 6. Step 5 — Delegate to Etch

figma-pro assembles a single hand-off payload and calls the **etchwp-pro** builder. It
does **not** write the block markup itself — Etch owns the `etch/element` + `etch/text`
tree, the flat BEM classes, and the validator.

```jsonc
// hand-off → etchwp-pro builder
{
  "target": { "post_type": "page", "title": "Homepage", "status": "draft" },
  "tokens": { /* the --primary / --space-* / --text-* map from Step 2 */ },
  "components": ["nibwp-feature-card", "nibwp-pricing-box"],
  "assets": [
    { "id": "hero-shot", "src": "figma-export://214:1210@2x", "w": 1200, "h": 720 },
    { "id": "bolt.svg", "src": "figma-export://30:41" }
    // …6 card icons, 4 footer logos
  ],
  "tree": {
    "block": "etch/element", "tag": "section", "class": "nibwp-hero",
    "children": [
      { "block": "etch/element", "tag": "div", "class": "nibwp-hero__inner",
        "children": [
          { "block": "etch/text", "class": "nibwp-hero__title", "text": "Ship faster with Acme" },
          { "block": "etch/text", "class": "nibwp-hero__sub",   "text": "The platform teams love." },
          { "block": "etch/element", "tag": "div", "class": "nibwp-hero__cta", "children": [ /* buttons */ ] }
        ] }
    ]
  }
}
```

The builder returns the standard Etch payload `{ __libraryMeta, styles, gutenbergBlock }`
after running its pipeline:

```
etchwp-pro:  validate ▸ score ▸ dry_run ▸ persist

  validate  ✔ all classes BEM  ✔ tokens resolve  ✔ no clamp() font-size
            ✔ no raw <form>/<style>  ✔ no invented tokens
  score     91 / 100   (−9: two literal hex fills not yet tokenized)
  dry_run   ✔ renders, 0 block-recovery prompts
  persist   → page #4127 (draft)
```

The validator would have **rejected** the payload outright on any invented token, a
`clamp()` font-size, a non-BEM class, or a raw `form`/`style` tag — so those never reach the DB.

---

## 7. Step 6 — Verify

A Playwright render of the draft is pixel-diffed against the Figma **2× export PNG**.

```jsonc
// verification report
{
  "viewport": "1440×5820",
  "reference": "figma-export://214:1183@2x  (2880px, downscaled to 1440)",
  "render":    "playwright://page-4127?preview=1",
  "diff": {
    "match": 98.7,          // percent identical pixels
    "regions": [
      { "section": "Hero",    "match": 97.4, "note": "title metrics shifted ↓2px" },
      { "section": "Features","match": 99.6 },
      { "section": "Pricing", "match": 99.1 },
      { "section": "Footer",  "match": 99.8 }
    ]
  }
}
```

**Triage of the 1.3% gap** — the Hero title sits ~2px lower and slightly wider than the
export. Root cause: the site is falling back to a system font because **Inter** is not
enrolled on the WordPress install, so line metrics differ from Figma's Inter render.
This is a **content/site issue, not a build defect**, so figma-pro surfaces it to the
user rather than trying to "fix" it by distorting the layout:

```
ℹ Diff 98.7% (target ≥ 98%). Remaining 1.3% is font-metric drift in Hero:
  webfont "Inter" not loaded on this site → system fallback used.
  Fix: enroll Inter (Google Fonts / local) and re-verify. Layout is otherwise pixel-accurate.
```

---

## 8. Step 7 — Result

```
✅ Draft created — "Homepage" (page #4127, status: draft)

Builder        etchwp-pro   (native Etch: etch/element + etch/text)
Diff score     98.7% vs Figma export 214:1183@2x   (target ≥ 98% ✔)
Components      2 reusable  (nibwp-feature-card ×6, nibwp-pricing-box ×3)
Tokens         12 ACSS vars mapped from Figma Variables (colors/spacing/type)

Warnings
  ⚠ Missing font: Inter not loaded on site → Hero uses fallback (accounts for 1.3% diff)
  ⚠ 2 raw colors not backed by a Variable → flagged for tokenizing:
       #EAF1FF (hero badge) → suggest --primary-050
       #1E293B (footer rule) → suggest --base-800

Preview  https://acme.example/?page_id=4127&preview=true
Next     Review draft → enroll Inter → publish.
```

The page is left as a **draft**. figma-pro never auto-publishes: the human reviews the
render, clears the two warnings, and publishes when satisfied.
