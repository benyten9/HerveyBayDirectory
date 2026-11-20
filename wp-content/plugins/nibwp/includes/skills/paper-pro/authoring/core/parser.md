# Parsing a Paper design

The reasoning step between reading Paper and building WordPress. `nodes.md` is
the mechanical mapping; this is the judgement.

## Order of operations

```
1. size the job        how big is this tree, really?
2. tokens first        colors + type before structure
3. walk and prune      tree → NDO nodes, noise dropped
4. assign roles        what is each section FOR?
5. fix the tag ramp    semantics, not font sizes
6. dedupe components   repeated subtrees → one definition
7. classify fidelity   what cannot convert, and what to do
8. score + pick a mode exact clone / native / design system
```

Do not reverse 2 and 3. If you build structure first you will hardcode fifty
hex values and then have to find them all again.

## 1. Size the job before you read it

Ask Paper for a subtree summary before pulling full node data. A 40-node artboard
and a 600-node artboard are different projects and the user deserves to know which
one they asked for.

| Nodes (after pruning) | What to say |
|---|---|
| < 50 | convert it, no ceremony |
| 50–150 | normal page; plan first, show the plan |
| 150–400 | offer to split by section and convert one band at a time |
| > 400 | say plainly it is a multi-pass job; agree a section order before starting |

Pruning (`nodes.md`) often halves the count, so prune before you quote a number.

## 2. Roles — what each section is for

Paper will not tell you a band is a pricing table. Infer it, because the role
drives everything downstream: which builder primitive, whether content should be
dynamic, what responsive behaviour is right.

Signals worth reading:

- **Layer names.** Designers name things. A layer called `Testimonials` is a
  stronger signal than any geometric heuristic.
- **Repetition.** Three structurally identical siblings is a card grid, and almost
  certainly one component plus data.
- **Position.** First band with the largest type is a hero. Last band with many
  small links is a footer.
- **Content shape.** A short line, a long line, a button → headline, subhead, CTA.
  An avatar, a quote, a name → testimonial.

Write the role onto your working plan, not into the NDO — there is no `role` key,
and inventing one does nothing (`schema.md`).

## 3. Component detection

Find subtrees that repeat. For each candidate ask two questions:

1. **Is the structure identical and only the content different?** Then it is one
   component with N sets of content. Build the definition once.
2. **Does the content look like it comes from somewhere?** Posts, products, team
   members, testimonials — then propose dynamic data (a query loop, ACF fields)
   rather than N hardcoded copies.

Mark the definition node `type: 'component'` and each use
`type: 'component_instance'`. Be honest that the emitters do not treat those two
specially — `class-figma-html.php` and `class-figma-gutenberg.php` both render
them as plain boxes. The types are a signal for the **builder adapter** and for
your own plan, not a built-in deduplication. If you want real reuse you have to
ask the builder's own ability for a reusable block, pattern or template.

Detail lives in `components.md`.

## 4. Complexity score

A rough number to choose a mode and set expectations. Count, after pruning:

| Signal | Weight |
|---|---|
| nodes | 1 each |
| distinct text styles | 3 each |
| distinct colors | 2 each |
| shader / effect layers that cannot convert | 15 each |
| absolutely positioned overlaps | 8 each |
| layers needing an image export | 4 each |
| distinct repeated components | 5 each |

Bands, and what they mean in practice:

| Score | Reading |
|---|---|
| < 80 | straightforward |
| 80–250 | normal marketing page |
| 250–600 | plan it, convert section by section, expect iteration |
| > 600 | set expectations explicitly before starting; this will not be one-shot |

The weights are deliberately lopsided. A single shader layer costs more than
fifteen nodes because it is the thing that will come back as a complaint.

## 5. Conversion modes

| Mode | Optimizes for | Use when |
|---|---|---|
| `exact_clone` | pixel similarity | a one-off campaign page nobody will edit |
| `native` (default) | maintainability | almost always — real components, tokens, dynamic data |
| `design_system` | reusability | a whole Paper file → tokens + component set + templates |

Default to `native`. A maintainable site the client can edit beats a brittle pixel
clone they have to come back to you for.

Choosing `exact_clone` is a real decision with a real cost: inline styles
everywhere, no tokens in the builder's panels, no responsive intent. Say that out
loud before committing to it.

## 6. Fidelity classification

Every layer gets one of four verdicts before you convert. The table and the
reasoning are in `assets.md`; the output of this step is a list, and the list goes
into `warnings` on the NDO.

```
translate   → it is CSS, it converts
flatten     → export as an image, note that it is static
approximate → rebuild the intent in CSS, label it an approximation
drop        → record it and tell the user
```

`NIBWP_Paper_Normalize` populates `warnings` with the mechanical problems it can
see — an image reference with no URL yet, for instance. It cannot see the
judgements: a shader you chose to flatten, a font the theme does not load, a
heading level you overrode. Those are yours to add, and if you skip this step the
audit has nothing to report and the user finds the gaps themselves.

Entries are **plain strings**, matching what the normalizer produces — do not mix
two shapes in one array. Write them for a human: name the layer, say what
happened, say what it means.

```php
'Hero/Field is a Paper shader — flattened to a 2x PNG. Identical, but static.'
'Pricing/Tier price was sized as an h1; set to p — it is a number, not a heading.'
'Font "Söhne" is not loaded by the active theme; falling back to the system sans.'
```

Cover at least: unsupported shaders, dropped effects, lost layered fills,
reflowed absolute positioning, missing fonts, lost gradient angles, rasterized
vectors, and overridden heading tags.

## 7. Dynamic data candidates

Flag, do not implement. Propose and let the user choose:

- a card grid whose items are obviously posts or products → a query loop;
- a repeated field that varies per item → ACF or post meta;
- a header or footer that appears on every artboard → a template part, not a
  page section;
- a testimonial set → a CPT.

Getting this right is the difference between a converted design and a working
website. Getting it wrong by assuming is worse than asking.

## 8. What to hand over

The planning output, before any `convert`:

1. the target builder, and why that one;
2. the section list with roles, in order;
3. the component list with instance counts and dynamic-data proposals;
4. the token map;
5. the tag ramp you are going to set, with the `h1` named;
6. the fidelity list, every `flatten` / `approximate` / `drop` named by layer;
7. the complexity score and what it implies about iteration.

Show it. Then `preview_convert`. Then `convert`.
