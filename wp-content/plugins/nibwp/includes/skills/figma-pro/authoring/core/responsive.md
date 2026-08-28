# Responsive Intelligence — Figma → responsive native build

The point of "pixel-perfect" is **not** a matching desktop screenshot. It is that
the built page reflows the way the designer intended, at every breakpoint. This
reference is how figma-pro infers responsive behavior from the real node tree and
Variables, maps Figma signals to responsive CSS, and hands correct per-breakpoint
settings to the builder (EtchWP / Bricks / Elementor / Gutenberg).

Read this alongside `core/nodes.md` (field semantics) and
`builders/etchwp.md` (how the target builder receives the plan).

## Table of contents
1. Reading responsive intent from frames
2. Figma signals → responsive CSS
3. Grid reflow heuristics
4. Breakpoint table (NibWP defaults + per-builder)
5. Mobile-first vs desktop-first
6. Typography responsiveness (token steps, never clamp)
7. Spacing responsiveness (--space-* scale)
8. Worked example — Features section
9. Warnings to surface

---

## 1. Reading responsive intent from frames

Establish how many breakpoints the designer actually implied before building any.
**Never invent a breakpoint the designer did not imply.**

| What Figma contains | What to do |
|---|---|
| One desktop frame (~1280–1440px wide) only | Build desktop from the tree; **infer** tablet + mobile from auto-layout signals (§2–§3). Tell the user mobile is inferred (§9). |
| Two frames of the same screen (e.g. 1440 + 390) | Treat as explicit desktop + mobile. Diff them: what stacks, what hides, what reorders, what resizes. The mobile frame **overrides** any inference. |
| Three frames (e.g. 1440 + 768 + 390) | Explicit desktop + tablet + mobile. Match each frame to the nearest NibWP breakpoint band (§4). Do not add a 4th. |
| Named variants / a "Mobile" page | Same as multi-frame: pair by matching layer names / component instances across frames. |

**Matching multiple frames.** Pair frames by screen identity (name, duplicated
component instances, matching section order). For each matched section, compute the
delta: layout direction change (row→column), column count change, visibility change
(present in one frame, absent in the other → responsive hide/show), and order change
(node index differs → CSS `order`). Encode those deltas as per-breakpoint overrides,
not as a second independent build.

**Frame width → band.** Snap the frame's width to a band, don't treat it as a custom
breakpoint: ≥1024 → desktop, 768–1023 → tablet, <768 → mobile. A 390px frame is
"mobile", a 1440px frame is "desktop" — the exact px is design canvas, not a CSS
breakpoint.

## 2. Figma signals → responsive CSS

The normalized node exposes auto-layout, sizing and constraint fields. Map them:

| Figma signal | Meaning | Responsive CSS |
|---|---|---|
| `layoutMode = HORIZONTAL` | row of children | `display:flex; flex-direction:row` |
| `layoutMode = VERTICAL` | stacked | `display:flex; flex-direction:column` |
| `layoutWrap = WRAP` | children reflow when they don't fit | `flex-wrap:wrap` — cards drop to next line on small screens automatically |
| `layoutWrap = NO_WRAP` | fixed single line | keep on one line desktop; force stack at mobile via override |
| `layoutSizingHorizontal = FILL` | fluid, fills parent | `width:100%` / `flex:1 1 0` — **preferred**, already responsive |
| `layoutSizingHorizontal = HUG` | fits content | `width:auto` / `width:fit-content` |
| `layoutSizingHorizontal = FIXED` | hard px width | **convert**: `max-width:<px>; width:100%` so it shrinks below that width |
| `primaryAxisSizingMode`/`counterAxisSizingMode` | grow vs fixed on each axis | same FILL/HUG/FIXED logic per axis |
| `itemSpacing` | gap between children | `gap:<n>px` → prefer `--space-*` token (§7) |

**Fixed widths are the enemy of responsive.** Any `FIXED` horizontal size becomes
`max-width:<px>; width:100%` (or a `%` of its container where the parent is FILL), so
the element never forces horizontal overflow on a narrow viewport. Only keep a hard
`width` when the element is genuinely size-locked (icon, logo lockup, avatar).

**Constraints** (`constraints.horizontal` / `.vertical`) describe how a layer behaves
when its **parent resizes** — the closest Figma has to responsive rules. Map them:

| Constraint | Behavior | CSS |
|---|---|---|
| `LEFT` / `TOP` | pin to that edge | default flow / `margin` on that side; `left`/`top` if absolute |
| `RIGHT` / `BOTTOM` | pin to opposite edge | `margin-left:auto` / `right`/`bottom` if absolute |
| `CENTER` | stay centered | `margin-inline:auto` / centered flex item |
| `LEFT_RIGHT` (STRETCH) | stretch with parent | `width:100%` / `align-self:stretch` — fluid |
| `TOP_BOTTOM` | stretch vertically | `height:100%` / `align-self:stretch` |
| `SCALE` | scale proportionally | fluid `%` width + `aspect-ratio` to preserve ratio |

`STRETCH`/`SCALE` constraints are strong responsive signals — honor them as fluid.
`LEFT`+`FIXED` together on a wide element is the classic overflow risk (§9).

## 3. Grid reflow heuristics

A horizontal auto-layout of **N roughly-equal children** is a grid. Reflow it by
breakpoint. Apply these heuristics **only when the designer gave no mobile frame** —
an explicit frame always wins.

| Desktop columns (N equal children) | Tablet (768–1023) | Mobile (<768) |
|---|---|---|
| 2-col | 2-col (or 1 if cramped) | 1-col stacked |
| 3-col | 2-col | 1-col stacked |
| 4-col | 2-col | 1-col (or 2 for compact cards) |
| 6-col (logo wall, small tiles) | 3-col | 2-col |

Common rule of thumb: **3-col grid → 2-col tablet → 1-col mobile stack.** Children
that are FILL-sized and live in a `WRAP` container often reflow correctly with just
`flex-wrap:wrap` + a `flex-basis` floor (e.g. `flex:1 1 300px`) — prefer that to
hard per-breakpoint column counts when the design is a simple card row. Use explicit
column counts (CSS Grid `grid-template-columns`) when the designer clearly intends a
fixed count per breakpoint.

## 4. Breakpoint table (NibWP defaults + per-builder)

NibWP default bands:

| Band | Range | Notes |
|---|---|---|
| Desktop | ≥ 1024px | base / largest layout |
| Tablet | 768–1023px | intermediate reflow |
| Mobile | < 768px | single-column, reduced spacing |

How each builder expresses those bands — emit settings in the builder's own model,
never raw `<style>` overrides where a native control exists:

| Builder | Mechanism | How figma-pro targets a band |
|---|---|---|
| **EtchWP / ACSS** | ACSS breakpoint tokens + responsive classes | Use ACSS breakpoint variables/classes; rely on `--text-*` and `--space-*` fluid tokens so most reflow is automatic; add `@include` breakpoint blocks only for column-count changes. |
| **Bricks** | Built-in breakpoints: Desktop / Tablet Portrait / Mobile Portrait (matches 1024 / 768 / <768) | Set the base (desktop) then switch the Bricks breakpoint and set overrides (direction, columns, padding) per element. |
| **Elementor** | Per-widget responsive controls (`desktop` / `tablet` / `mobile`) on each control | Set base value on desktop, then tablet + mobile values on the same control (columns, gap, font-size, padding, alignment). |
| **Gutenberg** | Block layout attrs + CSS media queries | Use block `layout` (flex/grid) + `columns`; add media queries at 1023.98px / 767.98px for what block controls can't express. |

## 5. Mobile-first vs desktop-first

**Author base = the frame the designer gave the most detail; cascade the rest.**

- **Prefer mobile-first CSS** (base styles = mobile, `min-width` media queries add
  desktop). It degrades safely: an unhandled larger viewport just keeps the readable
  stacked layout instead of overflowing. This is the default for the raw CSS layer.
- **When only a desktop frame exists**, you still *think* desktop-down (that's the
  source of truth) but **emit mobile-first CSS** — set the stacked/fluid version as
  base and layer desktop columns via `min-width:1024px`. Fluid FILL sizing (§2) makes
  this cheap.
- **Builder reality:** Bricks and Elementor are desktop-first in their UI (you set
  desktop, then override down to tablet/mobile). Match the builder's model when
  emitting through it — set desktop base, then tablet, then mobile overrides. The
  mobile-first preference governs hand-written CSS (EtchWP/ACSS, Gutenberg media
  queries), not the builder's native cascade direction.

## 6. Typography responsiveness

Scale the type ramp across breakpoints using ACSS text tokens with a px/em fallback:
`font-size: var(--text-l, 20px)`. Step **down one or two ramp slots per breakpoint**
rather than computing a new size.

**HARD RULE — NEVER use `clamp()` for `font-size`.** The NibWP/Etch validator rejects
`clamp()` on font-size and the build will fail preflight. Use discrete token steps per
breakpoint instead.

| Ramp slot | Desktop token | Tablet | Mobile |
|---|---|---|---|
| Display / H1 | `var(--text-xxl, 48px)` | `var(--text-xl, 36px)` | `var(--text-l, 28px)` |
| H2 | `var(--text-xl, 36px)` | `var(--text-l, 28px)` | `var(--text-m, 22px)` |
| H3 | `var(--text-l, 24px)` | `var(--text-m, 20px)` | `var(--text-m, 20px)` |
| Body | `var(--text-m, 16px)` | `var(--text-m, 16px)` | `var(--text-s, 15px)` |

Map Figma text-style sizes onto the nearest ACSS `--text-*` slot at build time (see
`core/nodes.md` §8). Fallback px = the Figma desktop value so the type is
correct even before ACSS tokens resolve. In Elementor/Bricks set the font-size control
per breakpoint to the same token/fallback; do not clamp.

## 7. Spacing responsiveness

Reduce section padding and gaps on mobile via the `--space-*` scale, **never hardcoded
px**. Large desktop section padding crushes mobile readability and causes overflow.

| Property | Desktop | Tablet | Mobile |
|---|---|---|---|
| Section block padding | `var(--space-xl, 96px)` | `var(--space-l, 64px)` | `var(--space-m, 40px)` |
| Container inline padding | `var(--space-l, 48px)` | `var(--space-m, 32px)` | `var(--space-s, 20px)` |
| Grid / flex `gap` | `var(--space-m, 24px)` | `var(--space-s, 16px)` | `var(--space-s, 16px)` |

Map Figma `paddingTop/Right/Bottom/Left` and `itemSpacing` to the nearest `--space-*`
slot, then step down one slot per breakpoint. Keep the px fallback = the Figma desktop
value.

## 8. Worked example — Features section

**Figma input:** a `FRAME` "Features", `layoutMode=HORIZONTAL`, `layoutWrap=WRAP`,
`itemSpacing=24`, three child `INSTANCE` cards each `layoutSizingHorizontal=FILL`. No
mobile frame provided → reflow inferred (§3), user told (§9).

**Target reflow:** desktop 3-col, tablet 2-col, mobile 1-col stacked.

Raw CSS (mobile-first, token-driven, no clamp):

```css
.features {
  display: flex;
  flex-wrap: wrap;              /* from layoutWrap=WRAP */
  gap: var(--space-s, 16px);    /* mobile gap */
}
.features > .card {
  flex: 1 1 100%;              /* FILL → 1 col mobile */
}
@media (min-width: 768px) {    /* tablet */
  .features { gap: var(--space-s, 16px); }
  .features > .card { flex-basis: calc(50% - var(--space-s, 16px)); } /* 2 col */
}
@media (min-width: 1024px) {   /* desktop */
  .features { gap: var(--space-m, 24px); }           /* itemSpacing=24 */
  .features > .card { flex-basis: calc(33.333% - var(--space-m, 24px)); } /* 3 col */
}
```

Per-builder equivalent:

| Builder | Settings |
|---|---|
| EtchWP/ACSS | Flex container, `flex-wrap:wrap`, `gap:var(--space-m,24px)`; ACSS grid utility `grid-3` desktop → `grid-2` tablet → `grid-1` mobile. |
| Bricks | Container `Direction: row`, `Wrap: wrap`; child width 33.33% (Desktop) → 50% (Tablet Portrait) → 100% (Mobile Portrait); gap 24→16. |
| Elementor | Inner section / flex container, `Columns`: 3 (desktop) / 2 (tablet) / 1 (mobile); Gap 24/16/16 per device. |
| Gutenberg | Grid variation `columns: 3`; media queries at 1023.98px→2 and 767.98px→1 columns; `blockGap` via `--space-*`. |

## 9. Warnings to surface

Surface these to the user in the conversion report — they are the difference between
"looks right in the desktop frame" and "actually works on a phone":

- **Absolute-positioned layers don't reflow.** Any node with `layoutPositioning=ABSOLUTE`
  (or a non-auto-layout parent) is positioned by coordinates and will **not** restack on
  mobile. Warn per layer; recommend converting to auto-layout flow or providing explicit
  mobile positions.
- **Fixed-width elements may overflow mobile.** Any `layoutSizingHorizontal=FIXED` wider
  than ~360px, or a hard `width` in px, risks horizontal scroll on small screens. Warn and
  note it was converted to `max-width + 100%` where safe; flag the ones that couldn't be.
- **No mobile frame → mobile is inferred.** When the designer supplied only a desktop
  frame, tell the user the mobile (and tablet) layout was **inferred** from auto-layout
  signals and the §3 heuristics, and invite them to add a mobile frame if a specific
  reflow is required.
- **Non-equal children in a "grid".** If children in a horizontal auto-layout have very
  different sizes, the N-col heuristic may be wrong — surface it rather than forcing equal
  columns.
- **Hidden-on-breakpoint layers.** When a section exists in one frame but not the matched
  frame, confirm it's an intentional responsive hide (`display:none` at that band), not a
  missing export.
