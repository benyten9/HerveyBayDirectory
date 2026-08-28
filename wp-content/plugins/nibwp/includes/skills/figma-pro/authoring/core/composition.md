# core/composition.md — skill composition

figma-pro never works alone. A convert **composes multiple active NibWP Pro skills**
into one pipeline. This file is the rule for which skills combine, in what order,
and the one thing you must never do.

**Default is automatic.** When a composable skill is active + entitled, fold it in —
do **not** ask each time. The user opted into auto-composition. Only surface it in
the final report ("built with figma-pro + etchwp-pro + acss-pro").

## Table of contents
1. Two kinds of pairing
2. The composition chain (order)
3. Detection
4. The one hard rule: never two builders on one output
5. Multi-builder fan-out (when explicitly asked)
6. Reporting

---

## 1. Two kinds of pairing

**Reader + builder — the base pair, every convert.**
figma-pro reads/normalizes (→ NDO); exactly **one** builder skill builds. figma-pro
is a meta-skill — it has no output of its own without a builder.

**Builder + enhancer — stack when active.** Enhancer skills improve the build
without owning the output format:

| Active skill | Entitlement | Folds in |
|---|---|---|
| **acss-pro** | `skill:acss` (#62) | token step → real ACSS global classes/variables + palette/spacing scale, instead of ad-hoc `var()`. The token system done natively. |
| **seo-pro** | `skill:seo` (#1370) | semantic landmarks, heading order, alt/meta on the generated page. |
| a builder (etch/bricks/elementor/kadence) | `skill:<builder>` | the target builder itself (base pair) |

So one convert can chain **three**: figma-pro → builder → enhancer(s). More
enhancers can stack as new enhancer skills ship.

## 2. The composition chain (order)

Compose in this order — each stage consumes the previous:

```
figma-pro (read → NDO)
  → [acss-pro]  establish/refine the token system on the NDO   (before build)
  → builder     build native from the token-rich NDO           (etch/bricks/elementor/gutenberg)
  → [seo-pro]   pass over the built draft: semantics/meta       (after build)
  → figma-pro   pixel-diff verify → persist draft
```

- **acss-pro runs BEFORE the builder** — tokens must exist before structure
  references them (tokens-first, see `core/parser.md` §7). If acss-pro is active, it
  owns step 3 (token establishment) and writes real ACSS tokens into the NDO;
  otherwise figma-pro's own token engine does the coarser mapping.
- **seo-pro runs AFTER the builder** — it enhances the persisted draft (heading
  order, landmarks, alt text, meta), never blocks the build.
- Enhancers are **optional links** — a missing enhancer just drops its stage; the
  chain still completes.

## 3. Detection

At builder-pick time (pipeline step 7), scan for composable skills:
- Which builder skills are **active (plugin present) ∩ entitled**? → the builder set.
- Which enhancer skills are **active ∩ entitled**? → acss-pro, seo-pro, …
- `figma-pro-detect-builder` returns **both**: the chosen builder + the active
  enhancers it will auto-fold.

Entitlement + active-plugin, both required — an entitled-but-inactive skill is
skipped (nothing to call), an active-but-unentitled skill is not used.

## 4. The one hard rule: never two builders on one output

Etch, Bricks, Elementor, and Gutenberg produce **incompatible formats**. A single
page/post cannot be two of them. So for one output:

- Pick **exactly one** builder (auto-detect ∩ entitlement, user override).
- Compose only **enhancers** on top of that builder — never a second builder.

Composing two builders onto the same page corrupts the output. Don't.

## 5. Multi-builder fan-out (only when explicitly asked)

The NDO makes it cheap to target several builders — but as **separate artifacts**,
not merged. If the user explicitly wants "give me an Etch version AND a Gutenberg
version," parse Figma **once** → NDO → run each builder adapter → persist **N
separate drafts**. This is the *only* way multiple builders coexist, and only on
explicit request (never auto).

```
Figma → NDO ─┬─ etchwp-pro   → draft A (Etch)
             └─ gutenberg    → draft B (core blocks)
```

## 6. Reporting

The final report names the composed chain so the user sees what ran:
```
Built with: figma-pro + etchwp-pro + acss-pro   (seo-pro not active)
Draft: /?p=1234   diff 98.6%   warnings: 1 missing font
```
Auto-composition is silent during the run, explicit in the report.
