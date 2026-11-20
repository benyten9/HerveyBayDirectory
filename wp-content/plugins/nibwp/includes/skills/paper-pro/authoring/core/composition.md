# Composing with other skills

One rule, then the detail.

> **One builder per output. Any number of enhancers.**

## The order

```
paper-pro  ──►  acss-pro  ──►  the builder  ──►  seo-pro
   read          tokens         structure        semantics
```

Why that order and not another:

- **`acss-pro` before the builder.** It owns the site's real token vocabulary. If
  the builder runs first, you get a page full of raw values and then a token system
  that does not match it. Running ACSS first means the builder receives token
  references and the page is maintainable from the moment it exists.
- **`seo-pro` after the builder.** It works on the finished markup — heading order,
  landmarks, alt text, meta. There is nothing for it to read until the page exists.

## Auto-fold, do not ask

Scan for active enhancer skills and fold them in without asking. A user who has
`acss-pro` installed has already said they want ACSS tokens; asking again is
friction. Report the chain at the end instead:

> Converted via paper-pro → acss-pro → etchwp-pro → seo-pro.

Builders are the exception. The builder choice is announced, not assumed, because
it is the one decision that is expensive to reverse.

## Choosing the builder

1. Detect which builder the site actually uses.
2. Intersect with the user's entitlement — a builder skill they do not have is not
   an option.
3. Prefer the active builder. A site using Bricks should get Bricks, not the
   builder you happen to know best.
4. **Fall back to Gutenberg.** It always works and it needs no extra skill.
5. Announce the choice in one line and accept a one-word override.

```
Building in Bricks (detected active). Say "gutenberg" or "etch" to change.
```

`nibwp/paper-manage { action: "list_builders" }` tells you what this site can
reach, and it reports honestly that only Gutenberg and neutral HTML are emitted by
the integration itself — everything else goes through that builder's own
`html-to-*` ability.

## Never two builders on one output

Etch markup inside an Elementor page is not a hybrid; it is a broken page with two
CSS systems fighting. If a site has two builders installed, pick one for this
output and say which.

The same applies to re-converting: converting an already-converted page with a
different builder means replacing it, not layering. Draft a new page.

## What each enhancer actually does here

**`acss-pro`** — maps the Paper palette and type ramp onto real ACSS tokens
instead of a parallel `--color-*` set, and gives you ACSS utility classes in place
of inline CSS. On an Etch site this is close to mandatory; the Etch validator
expects ACSS conventions. It is also the skill that enforces the no-`clamp()` rule.

**`seo-pro`** — fixes the things a design conversion reliably gets wrong:
heading order, a single `h1`, alt text on images the design had no alt text for,
landmark elements, and meta. Worth running on every converted page.

Note the overlap: `../core/nodes.md` tells you to fix the tag ramp in the NDO
before converting. Do it anyway. `seo-pro` can repair a heading order; it cannot
know that your 44px price is not a heading. Fix semantics at the source, let
`seo-pro` catch what you missed.

## Not composable

- **A second design source.** One NDO per output. Merging a Paper artboard and a
  Figma frame into one page means converting them separately and assembling in the
  builder.
- **Write-back.** No skill in this chain pushes anything into Paper.
  `../integrations/paper-mcp.md` §4.
- **Image-conversion skills.** If the user only has a screenshot, that is a
  different job — hand it to the builder skill's own image path, not to this
  pipeline. There is no Paper design object to ingest.
