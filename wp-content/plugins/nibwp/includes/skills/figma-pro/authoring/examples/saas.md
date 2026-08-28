# Worked Example — Figma "SaaS Marketing" site → Bricks (native build)

This walks the **figma-pro** orchestrator end-to-end on a SaaS marketing frame, targeting
**Bricks** instead of Etch — to show the same pipeline delegating to a different builder
adapter. figma-pro never hand-authors markup: it resolves the frame, reads the Figma model,
establishes a token system, dedupes components, then **delegates the build to the
bricks-pro builder adapter** and **verifies** the render against the Figma export before
saving a draft.

Canonical hierarchy used throughout:

> **Figma Frame → Sections → Containers → Components → Variables → Styles → Assets → WordPress Elements**

---

## 1. Input

**User prompt**

> Convert this Figma page to my site. It's built with Bricks.
> `https://www.figma.com/design/pV7hR2nQ4kLmZ8bWc1/Nimbus-SaaS?node-id=88-742&t=Ka9d...`

**Resolved target**

```
fileKey  = pV7hR2nQ4kLmZ8bWc1
node-id  = 88:742          (Figma URL encodes it as 88-742)
frame    = "Marketing / Home" (1440 × 6960)
builder  = Bricks          (user override; auto-detect also found Bricks active)
```

figma-pro pulls three things through NibWP's Figma integration abilities before it
reasons about anything:

- `figma-get-node` → the node tree for `88:742` (sections, auto-layout, text, instances)
- `figma-export-node` → a **2× PNG** of the frame (2880 px wide) — the verification ground truth
- `figma-get-variables` → the file's published Variables (colors, spacing, type ramp)

---

## 2. Step 1 — Analyze / detect

figma-pro reports what it found before touching the builder. This frame is markedly
denser than the agency landing page — more sections, a variant-bearing component, and a
9-step neutral scale — which pushes the **design-complexity-score to 62** (agency was 34).

```
DETECTION REPORT — "Marketing / Home" (88:742)
──────────────────────────────────────────────
Page:      Marketing / Home  (1440 × 6960, 8 top-level auto-layout frames)
Complexity score: 62 / 100   (variant component + 9-step scale + Display-72 ramp)

Sections (top-level frames, in flow order):
  1. Nav            88:760    auto-layout ▸ horizontal, space-between   (sticky)
  2. Hero           88:781    auto-layout ▸ horizontal (copy + product screenshot)
  3. Logos strip    88:840    auto-layout ▸ horizontal, wrap  (6 grayscale logos)
  4. Feature Grid   88:872    auto-layout ▸ wrap  (Feature Card × 6, 3-up desktop)
  5. Pricing        88:951    auto-layout ▸ horizontal (3 tiers, 1 "Popular" variant)
  6. FAQ            88:1030   auto-layout ▸ vertical  (accordion, 7 rows)
  7. CTA banner     88:1104   auto-layout ▸ vertical, center  (gradient fill)
  8. Footer         88:1160   auto-layout ▸ horizontal, 5 columns

Components detected (Figma component/instance sets):
  • Button          master 20:11   variants: primary / secondary / outline  → 11 instances
  • Feature Card    master 34:60   → 6 instances                             ⟲ DEDUPE
  • Pricing Tier    master 34:88   variant: default / popular  → 3 instances ⟲ DEDUPE
  • Nav Link        master 20:44   → 5 instances                             ⟲ DEDUPE

Design tokens (sampled from Variables + styles):
  Color    primary #6D28D9   neutral scale 50→900 (9 steps)   surface #FFFFFF
  Spacing  scale   4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96
  Type     Display 72/80 · H1 48/56 · H2 36/44 · Body 18/28 · Caption 14/20  (Inter)

Plan: 8 sections, 3 reusable components (1 with a variant), ~14 unique styles.
Proceeding to token establishment.
```

---

## 3. Step 2 — Establish tokens

Figma Variables map onto CSS custom properties as `var(--token, fallback)`. figma-pro
emits **two** views of the token system: the portable `var(--token, fallback)` map used
inside the tree, **and** a registration list for **Bricks Global Colors** (Bricks stores
palette entries with stable IDs that class settings reference). Every value that came
from a real Variable becomes a token; raw hex with no Variable backing is flagged.

> Rule: **never `clamp()` for font-size.** Type tokens are `var(--text-*, <px>)` only.

```jsonc
// Figma Variable  →  CSS token (with fallback)  +  Bricks Global Color id
{
  "colors": {
    "Brand/Primary":     "var(--primary,   #6D28D9)",   // bricks: gc_primary
    "Neutral/50":        "var(--n-50,       #FAFAFA)",   // bricks: gc_n50
    "Neutral/100":       "var(--n-100,      #F4F4F5)",   // bricks: gc_n100
    "Neutral/300":       "var(--n-300,      #D4D4D8)",   // bricks: gc_n300
    "Neutral/500":       "var(--n-500,      #71717A)",   // bricks: gc_n500
    "Neutral/700":       "var(--n-700,      #3F3F46)",   // bricks: gc_n700
    "Neutral/900":       "var(--n-900,      #18181B)",   // bricks: gc_n900
    "Surface/Base":      "var(--surface,    #FFFFFF)"    // bricks: gc_surface
  },
  "spacing": {
    "space/1": "var(--space-3xs,  4px)",
    "space/2": "var(--space-2xs,  8px)",
    "space/3": "var(--space-xs,  12px)",
    "space/4": "var(--space-s,   16px)",
    "space/5": "var(--space-m,   24px)",
    "space/6": "var(--space-l,   32px)",
    "space/7": "var(--space-xl,  48px)",
    "space/8": "var(--space-2xl, 64px)",
    "space/9": "var(--space-3xl, 96px)"
  },
  "type": {
    "Display": "var(--text-3xl, 72px)/80px",   // NO clamp()
    "H1":      "var(--text-2xl, 48px)/56px",
    "H2":      "var(--text-xl,  36px)/44px",
    "Body":    "var(--text-m,   18px)/28px",
    "Caption": "var(--text-s,   14px)/20px"
  }
}
```

The `gc_*` ids are handed to the bricks adapter so Bricks class settings reference the
**global palette** (edit `--primary` once, every element follows) instead of baking hex.

**Flagged (no Variable backing):**

```
⚠ CTA banner fill  linear-gradient(#6D28D9 → #4C1D95)  — raw gradient stops, not Variables
⚠ Logos strip tint #A1A1AA (grayscale filter)          — raw hex, suggest --n-400
```

---

## 4. Step 3 — Component dedupe

The frame contains **6 Feature Card instances** and **3 Pricing Tier instances** (one
carrying the `popular` variant). figma-pro does **not** emit 6 + 3 duplicated block trees.
It collapses each instance set into **one Bricks component** driven by data, and represents
the Figma **variant** as a **modifier class**, not a second component.

**Feature Card → one component, 6 data rows**

```jsonc
{
  "component": "nimbus-feature-card",
  "fields": { "icon": "asset", "title": "text", "body": "text" },
  "instances": [
    { "icon": "spark.svg",  "title": "Instant deploys", "body": "Push to live in seconds." },
    { "icon": "shield.svg", "title": "SOC 2 secure",    "body": "Audited, encrypted, logged." },
    { "icon": "graph.svg",  "title": "Live metrics",    "body": "Dashboards update in real time." },
    { "icon": "plug.svg",   "title": "80+ integrations", "body": "Connect your whole stack." },
    { "icon": "clock.svg",  "title": "99.99% uptime",   "body": "Backed by an SLA." },
    { "icon": "users.svg",  "title": "Team roles",      "body": "Granular access control." }
  ]
}
```

**Pricing Tier → one component + a modifier, 3 data rows**

The Figma `popular` variant differs only in border, badge, and elevation — so it becomes
a single `--popular` modifier applied to the middle instance, **not** a duplicate component.

```jsonc
{
  "component": "nimbus-pricing-tier",
  "fields": { "plan": "text", "price": "text", "period": "text",
              "features": "text[]", "cta": "button", "modifier": "enum(default|popular)" },
  "instances": [
    { "plan": "Starter", "price": "$0",  "period": "/mo", "modifier": "default",
      "features": ["1 project", "Community support"],           "cta": "Start free" },
    { "plan": "Pro",     "price": "$29", "period": "/mo", "modifier": "popular",
      "features": ["Unlimited projects", "Priority support", "SSO"], "cta": "Start trial" },
    { "plan": "Scale",   "price": "$99", "period": "/mo", "modifier": "default",
      "features": ["Everything in Pro", "SLA", "Dedicated CSM"],  "cta": "Contact sales" }
  ]
}
```

Result: **3 component definitions** (one variant-bearing), driven by data, instead of
**14 hand-duplicated trees**. Editing the card radius or the "Popular" badge changes every
instance at once.

---

## 5. Step 4 — Structure mapping

Figma **auto-layout** maps directly onto Bricks **container flex**. The Feature Grid is a
wrapping auto-layout row; on desktop it resolves to a 3-column flex container. Bricks class
names follow the generated `nimbus-{block}__{el}` convention with `--mod` for variants.

**Feature Grid — before (Figma auto-layout)**

```
Frame "Feature Grid" 88:872
  layoutMode      = HORIZONTAL
  layoutWrap      = WRAP
  itemSpacing     = 24        (space/5)
  counterSpacing  = 24
  padding         = 96 x, 64 y (space/9, space/8)
  child (Feature Card) preferredWidth = FILL, minWidth = 320
```

**Feature Grid — after (Bricks container flex, tokenized)**

```
section.nimbus-features                (Bricks: Section)
  └ .nimbus-features__grid             (Bricks: Container)
        display:flex; flex-wrap:wrap;
        gap: var(--space-m, 24px);
        padding: var(--space-2xl,64px) var(--space-3xl,96px);
      └ .nimbus-feature-card           flex: 1 1 calc(33.333% - var(--space-m,24px));
                                       min-width: 320px;      /* 3-up desktop, wraps to 1-up mobile */
```

Every section repeats this pass: auto-layout axis → `flex-direction`, `layoutWrap` →
`flex-wrap`, `itemSpacing` → `gap` token, `padding` → padding token, `FILL` child →
`flex: 1 1 …`. Bricks stores these as **class settings**, so the tokens live on the class,
not inline on each element.

**Variant mapping — Pricing "Popular"**

```
Figma variant  Pricing Tier / popular
  ▸ border      2px  #6D28D9
  ▸ badge       "Most popular"  (shown)
  ▸ elevation   shadow/lg
        ↓  maps to
.nimbus-pricing-tier--popular {
  border: 2px solid var(--primary, #6D28D9);
  box-shadow: var(--shadow-lg, 0 20px 40px rgba(24,24,27,.12));
}
.nimbus-pricing-tier--popular .nimbus-pricing-tier__badge { display: flex; }
```

The variant is **one modifier class**, applied to the single `popular` instance — not a
forked component tree.

---

## 6. Step 5 — Delegate to Bricks

figma-pro assembles a single hand-off payload and calls the **bricks-pro** builder adapter.
It does **not** write the Bricks element JSON itself — the adapter owns the element array,
the class-settings store, the global-color registration, and the validator.

```jsonc
// hand-off → bricks-pro builder adapter
{
  "target": { "post_type": "page", "title": "Home", "status": "draft", "builder": "bricks" },
  "globalColors": [
    { "id": "gc_primary", "name": "Primary", "raw": "#6D28D9", "var": "--primary" },
    { "id": "gc_n900",    "name": "Neutral 900", "raw": "#18181B", "var": "--n-900" }
    // …full neutral scale + surface
  ],
  "tokens": { /* the --primary / --space-* / --text-* map from Step 2 */ },
  "components": ["nimbus-feature-card", "nimbus-pricing-tier", "nimbus-button", "nimbus-nav-link"],
  "assets": [
    { "id": "hero-shot", "src": "figma-export://88:812@2x", "w": 1240, "h": 820 },
    { "id": "spark.svg", "src": "figma-export://34:61" }
    // …6 card icons, 6 grayscale logos
  ],
  "tree": {
    "element": "section", "name": "nimbus-hero",
    "settings": { "_cssGlobalClasses": ["nimbus-hero"] },
    "children": [
      { "element": "container", "settings": { "_cssGlobalClasses": ["nimbus-hero__inner"] },
        "children": [
          { "element": "heading", "settings": { "text": "Ship product, not infrastructure",
            "_cssGlobalClasses": ["nimbus-hero__title"] } },
          { "element": "text", "settings": { "text": "Nimbus runs your backend so you don't have to.",
            "_cssGlobalClasses": ["nimbus-hero__sub"] } },
          { "element": "image", "settings": { "image": { "id": "hero-shot" },
            "_cssGlobalClasses": ["nimbus-hero__shot"] } }
        ] }
    ]
  }
}
```

The adapter returns Bricks' native `_bricks_page_content` element array (each element a
`{ id, name, parent, settings, children }` node) after running its pipeline:

```
bricks-pro:  validate ▸ score ▸ dry_run ▸ persist

  validate  ✔ all classes match convention  ✔ tokens resolve  ✔ no clamp() font-size
            ✔ global colors registered      ✔ no invented tokens  ✔ variant = modifier only
  score     87 / 100   (−13: gradient approximated, 1 raw tint, 1 missing font)
  dry_run   ✔ renders in Bricks canvas, 0 "element failed to load" prompts
  persist   → page #5308 (draft), _bricks_page_content written by the adapter
```

The validator would have **rejected** the payload on any invented token, a `clamp()`
font-size, a non-conforming class, or a duplicated variant component — so those never
reach the DB. figma-pro itself never writes Bricks meta; the adapter does.

---

## 7. Step 6 — Verify

A Playwright render of the draft at **1440** is pixel-diffed against the Figma **2× export**.

```jsonc
// verification report
{
  "viewport": "1440×6960",
  "reference": "figma-export://88:742@2x  (2880px, downscaled to 1440)",
  "render":    "playwright://page-5308?preview=1",
  "diff": {
    "match": 98.2,          // percent identical pixels
    "regions": [
      { "section": "Hero",         "match": 99.1 },
      { "section": "Feature Grid", "match": 99.5 },
      { "section": "Pricing",      "match": 98.9, "note": "Popular border + badge exact" },
      { "section": "CTA banner",   "match": 94.3, "note": "gradient approximated" },
      { "section": "Footer",       "match": 99.4 }
    ]
  }
}
```

**Triage of the 1.8% gap** — almost all of it is the **CTA banner**. Figma used a
two-stop diagonal gradient with a subtle noise overlay; Bricks reproduces a clean
two-stop linear gradient (no noise layer), so the banner reads at 94.3%. This is a
**faithful approximation, not a build defect** — figma-pro surfaces it rather than
injecting a raster image to force a pixel match:

```
ℹ Diff 98.2% (target ≥ 98%). Remaining 1.8% concentrated in CTA banner:
  Figma gradient has a noise/grain overlay not represented as a CSS token.
  Rendered as a clean linear-gradient(--primary → #4C1D95). Visually equivalent;
  add a noise PNG overlay manually if exact grain is required.
```

---

## 8. Step 7 — Result

```
✅ Draft created — "Home" (page #5308, status: draft)

Builder        bricks-pro   (native Bricks: _bricks_page_content element array)
Complexity     62 / 100     (variant component + 9-step neutral scale + Display-72 ramp)
Diff score     98.2% vs Figma export 88:742@2x   (target ≥ 98% ✔)
Components      3 reusable   (feature-card ×6, pricing-tier ×3 incl. 1 Popular variant, +button/nav-link)
Tokens         17 CSS vars mapped from Figma Variables → registered as Bricks Global Colors
Variants       1 (Pricing "Popular" → .nimbus-pricing-tier--popular modifier, not a 4th tier)

Warnings
  ⚠ Missing font: Inter not loaded on site → headings use fallback (minor metric drift)
  ⚠ Gradient approximated: CTA banner grain overlay not tokenizable → clean gradient used
       (accounts for the CTA region's 94.3% sub-score)

Preview  https://nimbus.example/?page_id=5308&preview=true
Next     Review draft → enroll Inter → optional noise overlay on CTA → publish.
```

The page is left as a **draft**. figma-pro never auto-publishes: the human reviews the
Bricks render, clears the warnings, and publishes when satisfied.
