# Workflow — a Paper file → a WordPress design system

Not one page. The tokens and the component set, so every later page is cheap.

Run this **first** when a client has a Paper file and multiple pages to build. One
design-system pass then makes each page a short job instead of a repeat of the
same token work.

## What "design system" means here

Three deliverables:

1. a token set in the builder's own globals — colors, type ramp, spacing, radii;
2. a component set in the builder's own reusable format;
3. a written record of what exists, so the next session does not re-derive it.

Not a Paper library mirror. WordPress has no equivalent of a design-tool
component library, and pretending otherwise produces a set of orphaned patterns
nobody uses.

## 1. Read the whole file, not one artboard

Ask Paper for the file overview — every artboard with its size. Then decide which
artboards are the **source of truth**:

- a dedicated styles or foundations artboard, if the designer made one: that is the
  token source, and it is better than any frequency count;
- a component sheet: the component source;
- page artboards: evidence of how tokens and components are actually used.

If there is no foundations artboard, derive tokens from the page artboards and say
that you did. A derived palette is a guess at the designer's intent; a foundations
artboard is the intent.

## 2. Tokens, properly this time

The per-page shortcut in `../core/tokens.md` is frequency ranking. For a design
system, do better:

- name by **role**, not frequency: primary is the button color, surface is the
  background, text is the body color;
- collapse near-duplicates aggressively — a system with six colors beats one with
  twenty-three;
- collect the spacing scale by looking at `gap` and `padding` across every artboard
  and snapping to the cluster: 8, 16, 24, 32, 48, 64, 96;
- same for radii;
- build the type ramp from the real styles in use, collapsed by rounded px.

Then sync into the builder's globals before building anything.
`../builders/globals-sync.md`. On this workflow, syncing is the point — the warning
about changing every page does not apply when there are no pages yet.

If `acss-pro` is active, let it own the vocabulary. Two token systems is worse than
either. `../core/composition.md`.

## 3. Components

For each component in the file, decide what it becomes
(`../core/components.md`):

| In Paper | In WordPress |
|---|---|
| a button with variants | one component, variants as props or conditions |
| a card with variants | one component with props |
| a repeated data-backed item | a loop template, plus the CPT/fields it needs |
| a header | a header template part |
| a footer | a footer template part |
| a one-off decorative block | nothing — leave it in the page that uses it |

Convert each component as its own small NDO — ingest it as a separate design
record with a clear title. One record per component means you can re-convert one
without touching the rest.

Then ask the builder for its native reusable format: an Etch component, a Bricks
template or global class set, an Elementor template or global widget. On a
Gutenberg target, say plainly that reuse means synced patterns created in the
editor, because the emitter cannot produce them.

## 4. The shader inventory

Do this once, for the whole file, and it pays for itself.

List every shader and effect layer across every artboard, with the decision for
each: flatten, approximate, reproduce in the browser, or drop
(`../core/assets.md`). Agree the list with the user **now**, before any page is
built, so the same decision is not relitigated six times.

A file with one shader treatment used on every hero is one decision. Discovering
that on page four is three wasted conversions.

## 5. Fonts

Collect every family used across the file, compare with what the theme loads, and
resolve the gap once — enqueue, self-host, or substitute. A font decided per page is
a font that ends up inconsistent.

## 6. Write it down

The record is the deliverable that makes the next session cheap. Capture:

- the token map, with the role each name plays;
- the component list, with where each lives in WordPress and which Paper artboard
  it came from;
- the design-record ids in WordPress, so `get_design` can find them again;
- the shader decisions;
- the font resolution;
- the builder chosen, and why.

Without it, the next session re-derives the palette from frequency and gets a
different answer.

## 7. Then build pages

Each page becomes `landing-page.md` with steps 4 and 11 already done: tokens exist,
components exist, shader decisions are made. That is the payoff, and it is why this
workflow goes first.
