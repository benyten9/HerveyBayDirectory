# Workflow — a Paper file → theme templates

When the design covers the whole site, not one page: header, footer, archive,
single post, 404.

Run `design-system.md` first. Templates built before tokens exist get hardcoded
values baked into every page on the site, which is the most expensive version of
this mistake.

## The distinction that matters

A **page** is content. A **template** is structure that content flows into. Getting
this wrong is the single most common failure in design-to-site conversion, and it is
expensive both ways:

- A header converted as a page section means the client edits it once per page,
  forever.
- An archive layout converted as a static page means it never shows a real post.

So before converting anything, sort the artboards:

| Artboard | Becomes |
|---|---|
| the band at the top of every artboard | a **header** template part |
| the band at the bottom of every artboard | a **footer** template part |
| a blog or news listing | an **archive** template, with a query loop |
| a single article layout | a **single** template, with dynamic fields |
| a product listing | a product archive template |
| a single product layout | a single product template |
| homepage, about, pricing | **pages**, built with `landing-page.md` |
| a 404 or search-results design | those templates |

## Header and footer

Read them once, from one artboard, and convert them as their own design records.

Two things the NDO cannot give you, which you will have to add in the builder:

- **Links.** The NDO has no href key at all, so every nav item arrives as styled
  text in a box. Wire the real menu in the builder — a WordPress nav menu, not
  hardcoded links, or the client cannot edit it.
- **Behaviour.** Sticky headers, mobile hamburgers, dropdowns. None of it travels
  with the design. Read the design's intent, then ask the builder for its own
  sticky/mobile-menu features.

If the mobile header is drawn as a separate artboard, read it — that is the
designer's actual mobile intent, and it beats any default.

## Archive templates

A listing artboard shows, say, six post cards. The template needs **one** card
inside a loop.

So:

1. Convert one card as a component (`../core/components.md`).
2. Identify which part of the card is which field: title, excerpt, featured image,
   date, author, term.
3. Ask the builder for a query loop wrapping that card.
4. Report what you bound and what you guessed. A card showing a category chip needs
   to know whether that is a taxonomy term or decoration.

The card's content in the design is placeholder. Do not convert "Lorem ipsum dolor"
as literal text and leave it in a live template.

## Single templates

Map each design element to a real source:

| In the design | In WordPress |
|---|---|
| the article title | `the_title()` / the title dynamic tag |
| the hero image | the featured image |
| body copy | `the_content()` |
| author name and avatar | author fields |
| a date | the post date, formatted |
| tags or categories | the taxonomy terms |
| related posts | a secondary query loop |
| anything else | ask — it is probably a custom field the client needs |

The last row is where the real work is. A design with a reading-time badge, a
difficulty rating or a price needs a field created and populated. Flag it; do not
invent it.

**Styling `the_content()` output** is a specific job. The design shows styled
paragraphs, headings, lists and quotes inside the article body, but those elements
come from the editor, not from the template. Extract that typography into CSS
scoped to the content area rather than trying to model each element as a node.

## Order of work

```
1. tokens + globals        design-system.md
2. header + footer         template parts
3. single                  the template that proves dynamic binding works
4. archive                 the loop
5. pages                   landing-page.md, repeatedly
6. 404, search             last, and quickly
```

Do `single` before `archive`. It is the smaller template and it surfaces every
field-mapping problem the archive will also have, at a quarter of the cost.

## What to expect to be imperfect

Say these at the start, not the end:

- **Nav menus** need wiring; the design has no links.
- **Dynamic fields** beyond the WordPress defaults need creating and populating.
- **Editor content styling** is CSS, not nodes, and will need a pass.
- **Interactive behaviour** — sticky, dropdown, hamburger, carousel — is the
  builder's, not the design's.
- **Shader treatments** in a template apply to every page that uses it, so a
  flattened PNG in a header is on every page. That raises the bar for choosing
  "approximate in CSS" over "flatten". `../core/assets.md`.
