# Tokens

Establish the token set **before** structure. If you build first you hardcode
every hex value and then have to find them all again.

## Who builds the token map

Two possibilities, and the choice is not symmetrical.

**You build it.** This is the normal Paper path. You walk Paper's tree, collect
the colors and text styles actually in use, name them, and put the map in the
NDO's `tokens` array at ingest.

**The integration builds it.** The Paper integration has its own extractor,
`NIBWP_Paper_Tokens`, which produces a map with `'source' => 'paper'` and a
`css()` helper that renders the `:root` block. `ingest` runs it when you did not
supply a map, so a design always arrives with *something*.

Note that this is a **Paper-specific class, not the Figma one**. `NIBWP_Figma_Tokens`
cannot be used here: it walks a raw Figma node tree, looking for `fills` arrays with
Figma's `SOLID` type and nodes whose type is the literal string `TEXT`. A Paper
payload does not have that shape.

Prefer supplying your own map anyway. The extractor ranks by frequency; you know
which color is the button and which is the background, and that distinction is the
difference between a usable palette and a wrong one — see "Naming by frequency"
below.

## The output shape to match

Match it so that `list_tokens`, `sync_tokens` and the builder adapters all see
something familiar.

```php
[
  'source' => 'paper',
  'colors' => [
    '--color-primary'   => '#1b2a4a',
    '--color-secondary' => '#f4f1ea',
    '--color-accent'    => '#e4572e',
    // '--color-4' … '--color-8' for the rest
  ],
  'text' => [
    '--text-display' => ['size' => '72px', 'weight' => 700, 'family' => 'Söhne'],
    '--text-h1'      => ['size' => '56px', 'weight' => 700, 'family' => 'Söhne'],
    '--text-h2'      => ['size' => '36px', 'weight' => 600, 'family' => 'Söhne'],
    '--text-h3'      => ['size' => '24px', 'weight' => 600, 'family' => 'Söhne'],
    '--text-body'    => ['size' => '18px', 'weight' => 400, 'family' => 'Söhne'],
    '--text-small'   => ['size' => '14px', 'weight' => 400, 'family' => 'Söhne'],
  ],
  'css' => ":root {\n  --color-primary: #1b2a4a;\n  …\n}",
]
```

The names are not arbitrary — they are the slots both extractors hand out, in this
order:

- **Colors:** `--color-primary`, `--color-secondary`, `--color-accent`, then
  `--color-4` … `--color-8`. Eight maximum.
- **Type ramp:** `--text-display`, `--text-h1`, `--text-h2`, `--text-h3`,
  `--text-body`, `--text-small`, largest first. Beyond six, `--text-7` and so on.

Use the same names. A Paper design and a Figma design arriving at the same site
should not produce two competing token vocabularies.

Note what the generated `css` contains: the colors, and the ramp's **size only**.
Weight and family live in the map but not in the `:root` block. If you want them
in CSS, write them yourself.

## Naming by frequency is a starting point, not an answer

The extractor ranks colors by how often they appear and calls the most
frequent one `--color-primary`. That is a reasonable default and it is often
wrong: on most marketing pages the most-used color is the background.

You have more context than a frequency count. Use it:

- the color on the primary button is the primary, whatever its frequency;
- the page background is `--color-surface` or `--color-bg`, not `--color-primary`;
- body text color is `--color-text`;
- a color used once, on one accent, is still an accent and still deserves a name.

If you rename away from the default slots, keep the `--color-*` prefix and say in
the plan what you changed and why. Do not invent a parallel scheme.

## Collapse near-duplicates

Design files accumulate colors. `#1b2a4a` and `#1b2a4b` are the same intention
and two tokens. Same with type: 14.6px and 15.2px both round to 15px and should be
one ramp slot, not two.

The extractor already collapses the type ramp by **rounded px**, taking the
weight and family from the most-used style at that size. Do the same, and collapse
colors that are within a couple of points of each other, naming the one the
designer meant.

A token set with six colors and five type slots is usable. One with twenty-three
colors is a list, not a system.

## Spacing and radius

Neither extractor produces spacing or radius tokens — colors and type only. But Paper's canvas is CSS, so
spacing and radii are available to you and worth collecting:

- Look at the `gap` and `padding` values across the artboard. Real designs cluster
  — 8, 16, 24, 32, 48, 64, 96. Snap to the cluster and name the scale
  `--space-1` … `--space-n`.
- Same for `border-radius`: `--radius-sm`, `--radius-md`, `--radius-lg`,
  `--radius-full`.

Snapping is the point. A design with `gap: 23px` in one place and `24px` in
another has a 24px scale and a rounding error, and the converted page should have
the scale.

## Using tokens in the NDO

The NDO's `style` and `layout` values are raw CSS strings that the emitters
concatenate. So a token reference goes in as a CSS string, with a fallback:

```php
'style' => [
  'color'     => 'var(--color-text, #1b2a4a)',
  'font-size' => 'var(--text-h1, 56px)',
],
```

Two hard rules:

- **Always include the px fallback.** The inline style ships before the token
  stylesheet is guaranteed to be present, and a missing variable with no fallback
  renders as nothing at all.
- **Never `clamp()` for font-size.** The Etch/ACSS validator rejects it and it is
  a house rule across NibWP. Use `var(--text-l, 20px)` and let the token system
  handle scaling.

## Syncing into the builder

```
nibwp/paper-manage { action: "sync_tokens" }
```

This pushes the record's map into the builder's native globals so the colors
appear in the builder's own color panel and the type ramp in its own typography
settings. Skip it and you ship a page with correct colors the user cannot edit
from their builder — which they will, reasonably, call broken.

`sync_tokens` changes **every page on the site**, not just the converted one. Say
so before running it, and expect it to be gated on a settings-level capability.

Mechanics and per-builder detail: `../builders/globals-sync.md`.

## If ACSS is active

ACSS already owns a token vocabulary. When `acss-pro` is present, let it map the
Paper palette and ramp onto real ACSS tokens rather than inventing a parallel
`--color-*` set beside it. Two token systems on one site is worse than either one
alone. `composition.md` covers the ordering — ACSS runs **before** the builder.
