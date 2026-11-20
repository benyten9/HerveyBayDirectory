# Tokens → the builder's own globals

```
nibwp/paper-manage { action: "sync_tokens" }
```

## Why this step is not optional

Without it, the converted page has the right colors and the user cannot change
them. Every value lives in an inline style or a `:root` block they did not write
and cannot find. They will open their builder's color panel, see the theme's old
palette, and conclude the conversion is broken.

With it, the Paper palette is in the builder's own color picker and the type ramp
in its own typography settings. The user edits the design system, not the page.

That is the whole argument. One extra call.

## What it writes, per target

| Builder | Where the tokens land |
|---|---|
| Gutenberg | `theme.json` — `settings.color.palette` and `settings.typography.fontSizes` |
| Etch / ACSS | ACSS tokens, which is Etch's native vocabulary |
| Bricks | global colors, plus global classes for the type ramp |
| Elementor | the Kit's Global Colors and Global Fonts (Site Settings) |

Read the builder's own playbook before syncing. Each one has a different idea of
how many palette slots exist and what they are called, and the write goes through
the builder's own path — never by hand.

## It changes every page on the site

Say this before running it. Global colors are global. A site with existing content
styled against the current palette will shift when you replace it.

Three questions to ask first:

1. Does the site already have a palette the user cares about? If so, **map onto it**
   rather than replacing it — find the existing slot closest to each Paper color and
   reuse it.
2. Is this the site's first real design system, or the fifth page? First means
   replace; fifth means map.
3. Does the user want the Paper design to become the site's identity, or to be one
   page that happens to look different? If it is one page, do **not** sync — scope
   the tokens to the page instead.

Expect `sync_tokens` to be gated on a settings-level capability, because changing
every page on the site is not an `edit_posts` action.

## Mapping, not dumping

The token map from `../core/tokens.md` has up to eight colors and six type slots.
Builders have their own slot counts and names. Map deliberately:

- the color on the primary button → the builder's primary, whatever its
  frequency rank;
- the page background → the builder's background or surface slot, **not** primary;
- body text color → the text slot;
- leftover accents → the numbered slots.

Dumping `--color-1` … `--color-8` into eight builder slots in frequency order
produces a palette where "primary" is the page background. That is worse than not
syncing, because it is wrong in a place the user trusts.

## Type ramp

The ramp slots are `--text-display`, `--text-h1`, `--text-h2`, `--text-h3`,
`--text-body`, `--text-small`, largest first.

Two rules:

- **Never `clamp()` for font-size.** The Etch/ACSS validator rejects it and it is a
  NibWP house rule. Ship `var(--text-l, 20px)`-shaped references with a px
  fallback and let the token system scale.
- The generated `css` from the token extractor contains the ramp's **size
  only** — weight and family are in the map but not in the `:root` block. If the
  builder's typography settings take weight and family, read them from the map, not
  from the CSS.

## If ACSS is active

ACSS already owns a token vocabulary and `acss-pro` runs **before** the builder
(`../core/composition.md`). Let it map the Paper palette and ramp onto real ACSS
tokens rather than creating a parallel `--color-*` set alongside. Two token
systems on one site is worse than either alone.

## After syncing

Re-render and look. A sync that silently replaced a slot the theme depended on
shows up immediately as a wrong button or an unreadable nav. `audit` reports token
coverage — how much of the converted page references a token versus a raw value —
and that number is the honest measure of whether this step worked.
