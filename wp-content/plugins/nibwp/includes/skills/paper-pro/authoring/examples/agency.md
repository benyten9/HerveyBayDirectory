# Example — typography-led page → Etch

An illustrative walkthrough. The lesson is **the neutral-HTML handoff**: how a
design reaches a builder when no emitter for that builder exists.

## The situation

An agency homepage in Paper. Typography-led — enormous display type, generous
whitespace, two photographs, no shaders. Two artboards: desktop at 1440 and mobile
at 390. The site runs Etch with ACSS, and `etchwp-pro` and `acss-pro` are both
active.

This is the best case for Paper → WordPress, and it is worth knowing why: Paper's
canvas is HTML and CSS, so the layout is already flexbox; Etch builds in real CSS
classes; ACSS owns the tokens. Every layer of the stack speaks the same language.

## 1. Both artboards

The agent asks, and the answer is yes — there is a mobile artboard. So it reads
both and diffs them. The differences are the designer's real responsive intent:

- display type drops from 128px to 56px — much steeper than the default ratio in
  `../core/responsive.md`;
- section padding drops from 160px horizontal to 24px;
- the two-column case-study row becomes one column, image first.

Those three facts replace three guesses. They go into the plan marked *from the
mobile artboard*, not *default applied*.

## 2. Tokens, and ACSS owns them

`acss-pro` is active, so it runs **before** the builder
(`../core/composition.md`). The agent does not invent a `--color-*` set beside
ACSS's own vocabulary; it maps the Paper palette onto real ACSS tokens.

Four colors: near-black text, off-white surface, one acid-green accent, one mid
grey. Three type slots after collapsing: 128/56 display, 24 body, 16 small.

The display size is where the house rule bites. The tempting thing is
`clamp(56px, 8vw, 128px)`. **Not allowed** — the Etch/ACSS validator rejects
`clamp()` for font-size. So it ships as an ACSS text token with a px fallback and
Etch's own breakpoint controls handle the step from 128 to 56.

## 3. The NDO

Clean, because the design is clean. Notable:

- Layer names are good — `Hero`, `Hero / Headline`, `Work / Case 01`. The HTML
  emitter slugifies these into `figma-hero`, `figma-hero-headline`,
  `figma-work-case-01`. Those are the designer's own vocabulary and they become the
  seed for Etch's BEM class names. Worth keeping in the plan even though they get
  renamed.
- The two case-study rows are structurally identical: one `component`, two
  `component_instance`.
- One `h1`, the hero headline. The 24px intro paragraph is a `p` even though 24px
  would make it an `h3` by size — fixed in the NDO.
- The two photographs become `attach_asset` uploads, with alt text derived from the
  layer names.

## 4. The handoff — this is the part worth reading

There is **no Etch emitter**. `../builders/etchwp.md` says it plainly: the
integration ships only `NIBWP_Figma_Gutenberg` and `NIBWP_Figma_Html`.

So:

```
preview_convert, builder: "html"      → neutral semantic HTML + token CSS
```

The output is `<section>` and `<div>` boxes with `figma-`-prefixed classes and
inline styles, `<h1>`/`<p>` for text. That is the artifact Etch consumes.

Then — and this step is not skippable:

```
nibwp/load-skill-playbook { skill_id: "etchwp-pro" }
```

Etch's own rules, read before synthesizing anything. `html-to-component` validates
and persists a native payload; it does not forgive one that ignores Etch's
conventions.

The agent synthesizes Etch's native payload from the HTML plus the token CSS, then:

```
nibwp/etchwp-pro-html-to-component   dry_run: true
```

First `dry_run` fails. Two reasons, both predictable:

- a font-size came through as a raw `128px` instead of an ACSS token;
- one container had no class, only inline styles.

Both fixed. Second `dry_run` passes. Then `dry_run: false` to commit.

### The ability not to use

`nibwp/etchwp-pro-figma-to-component` exists and looks right. It is **not usable
here** — it takes a `figma_url` or `file_key` and goes and reads Figma itself.
Paper has no URL the site can resolve and no API
(`../integrations/paper-mcp.md`). `html-to-component` is the route.

## 5. Breakpoints in Etch

The mobile artboard's numbers go in as explicit per-breakpoint instructions, not
as guesses: display type 128 → 56, padding 160 → 24, the case-study row stacking
image-first.

## 6. `seo-pro` last

It runs after the builder, on finished markup: heading order, landmarks, the alt
text on the two photographs, meta. `../core/composition.md`.

## The report

> Draft is up, built as an Etch component with ACSS tokens.
>
> Your mobile artboard did the responsive work — display type steps 128 → 56 and
> padding 160 → 24, both from your design rather than my defaults.
>
> The two case studies are one Etch component used twice, so editing the structure
> once updates both. Content is per-instance.
>
> Four colors and three type slots are now real ACSS tokens, editable in your
> token panel rather than hardcoded in the page.
>
> Chain: paper-pro → acss-pro → etchwp-pro → seo-pro.

## What this example teaches

- No Etch emitter exists. Neutral HTML plus Etch's own ability is the route, and
  saying so beats documenting a path that is not there.
- `figma-to-component` is Figma-only. It needs a URL Paper cannot provide.
- Read the builder's playbook before synthesizing. `dry_run` until clean.
- A second artboard is worth more than every responsive heuristic. Ask for it.
- `clamp()` for font-size fails the validator. Token plus px fallback.
