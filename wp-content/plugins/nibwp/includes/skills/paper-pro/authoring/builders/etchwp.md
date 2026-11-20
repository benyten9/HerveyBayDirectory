# NDO → EtchWP

## There is no Etch emitter. Read this first.

The Paper and Figma integrations ship exactly two emitters:
`NIBWP_Figma_Gutenberg` and `NIBWP_Figma_Html`. **There is no
`class-figma-etch.php` and no Etch branch anywhere in the integration.** Do not
look for one, and do not document a path to the user that does not exist.

The real route is two steps:

```
NDO ──NIBWP_Figma_Html──► neutral semantic HTML ──► nibwp/etchwp-pro-html-to-component
```

Etch's own skill owns the conversion to Etch's native format, because it owns the
validation, the CSS regeneration and the backups. That is also why you must not
write Etch meta directly from here.

### Not `nibwp/etchwp-pro-figma-to-component`

That ability exists, and it is **not usable for Paper**. It takes a `figma_url` or
a `file_key` and goes and reads Figma itself. There is no Paper equivalent of
either — no URL the site can resolve, no key, no API (`../integrations/paper-mcp.md`).
Use `html-to-component`.

## The handoff

1. `nibwp/paper-manage { action: "preview_convert", builder: "html" }` → neutral
   HTML plus the token CSS.
2. Read Etch's own rules first: `nibwp/load-skill-playbook { skill_id: "etchwp-pro" }`.
   Do not skip this. `html-to-component` validates and persists a native payload;
   it does not forgive a payload that ignores Etch's conventions.
3. `nibwp/etchwp-pro-html-to-component` with `dry_run: true`. Fix every failure.
4. Re-submit with `dry_run: false`.
5. `sync_tokens`, then `audit`.

## What the neutral HTML gives Etch

`NIBWP_Figma_Html` emits:

- the root as `<section>`, every other box as `<div>`;
- text as `<h1>`–`<h6>` or `<p>`, with the tag validated against `/^h[1-6]$/` and
  falling back to `<p>`;
- a class on every box derived from the Paper layer name, slugified and prefixed
  `figma-` — so a layer called `Hero / CTA row` arrives as
  `class="figma-hero-cta-row"`;
- `layout` and `style` merged into one inline `style` attribute, `style` winning.

Those `figma-`-prefixed class names are the most useful thing in the output for
Etch. They are the designer's own vocabulary, and they are the natural seed for
BEM class names and for Etch's component boundaries. Keep them in your plan even
though you will rename them.

The prefix says `figma-` for a Paper design. It is a shared emitter and the prefix
is hardcoded; it is cosmetic and not worth forking the emitter over.

## What Etch can represent well

Etch is the priority target for a reason — of the builders it is the closest fit
to what the NDO holds:

- real flexbox, which is what `layout` already describes;
- real CSS classes rather than per-element inline styles, so the heavy inline CSS
  from the emitter becomes a class-based system during the handoff;
- ACSS tokens, so the Paper palette and type ramp become first-class site tokens
  rather than CSS variables bolted on;
- components with props, conditions and loops, which is where repeated Paper
  subtrees should land;
- semantic element choice, so a `<section>` can stay a `<section>`.

## What it cannot take from the design

- **Nothing the NDO does not carry.** No links or hrefs, no interaction, no
  animation, no overlap or z-order, no per-side borders, no layered fills. These
  are NDO limits, not Etch limits — see `../core/schema.md` and
  `../core/nodes.md`.
- **No shaders.** `../core/assets.md`.
- **No automatic component dedupe.** `component` and `component_instance` in the
  NDO are a signal for your plan. The HTML emitter renders both as plain divs.
  Turning six instances into one Etch component plus a loop is work the handoff
  does on your instruction, not something that happens because the type was set.

## House rules that bite here

- **Never `clamp()` for font-size.** Etch's ACSS validator rejects it outright. Use
  `var(--text-l, 20px)` with a px fallback. This is the single most common reason a
  `dry_run` fails on a converted design.
- **Never write Etch meta directly.** Bypassing the ability skips slashing, CSS
  regeneration and backups, and the documented outcome is a blank page.
- **One builder per output.** Etch or Bricks or Elementor, never two. Enhancer
  skills (`acss-pro`, `seo-pro`) compose; builders do not.
  `../core/composition.md`.
