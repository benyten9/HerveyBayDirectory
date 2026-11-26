# Section checklists

Each blueprint (`section_type`) already follows these. Check your own trees against them.

## Every section
- A `section` band with vertical padding that shrinks on tablet and mobile.
- A width-capped inner `div` (`max-width` ~1200px, `margin: 0 auto`).
- One heading per section, the right level for the page (one h1 per page).
- No `[bracketed]` placeholder left.

## hero
- h1 with the promise; a supporting paragraph under it.
- One primary action, at most one secondary.
- Two columns on desktop, one on tablet and phone; the image has `alt`.
- h1 font size has a mobile value.

## header
- `section` with `tag: header`; brand, `navbar` (no children: Mosaic builds it), one action.
- Belongs in the **master** if every page should have it.

## features / card grid
- A grid that goes 3 → 2 → 1 columns; cards share one class, not repeated styles.
- A card that repeats across pages is a **component**.

## cta
- One ask, one button; contrast between band and button checked.

## pricing
- Plans in a grid; the recommended plan is visually distinct (class modifier, not copy-paste).
- Every plan lists what is included with `list` / `list-item`.

## testimonials
- Real quotes with name and role; no stock "Lorem".

## faq
- `accordion` sent empty, then each title/content set with refine (`match: { text }`).
- Questions phrased as the visitor would ask them.

## stats
- 3–4 numbers in a grid that stacks; each with what it counts.

## footer
- `section` with `tag: footer`; link columns as lists; small print last.
- Belongs in the **master**.
