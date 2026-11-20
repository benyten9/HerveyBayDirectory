# Responsive

## Start from the honest position

**The NDO carries no breakpoints.** There is no media-query key, no per-viewport
variant, nothing in `style` or `layout` that is conditional. `bbox` gives you one
width and one height. The emitters concatenate `layout` and `style` into a single
inline style attribute and stop.

So a converted page is, by default, a **fixed-width design rendered at every
width**. That is not a bug to hide; it is the format's scope. Responsive behaviour
is added in one of two places, and you choose which:

1. **In the NDO**, by writing responsive CSS values that work at any width — the
   cheap, universal option.
2. **In the builder**, by using its per-breakpoint controls after the handoff — the
   better result, available only on a real builder.

## Option 1 — make the single value responsive

You cannot write a media query, but you can write CSS that does not need one. This
works in every target including plain Gutenberg.

| Instead of | Write |
|---|---|
| `width: 1200px` | nothing — let the flex parent size it, and put `max-width: 1200px` on the container |
| `padding: "96px 160px 96px 160px"` | `padding: "96px 5vw 96px 5vw"` — or better, a token |
| a fixed row of three cards | `flex-wrap: wrap` plus a sensible `flex-basis` on the children |
| `font-size: 72px` | `var(--text-display, 72px)` and let the token system scale it |
| a fixed-height hero | `min-height` instead of `height`, so content cannot be clipped |

Two specifics worth memorising:

- **`flex-wrap: wrap` is the single highest-value responsive edit you can make in
  the NDO.** It is a real `layout` key, it survives into every target, and it turns
  a row that would overflow into a row that stacks. Set it on every horizontal
  container holding more than two children.
- **Never `clamp()` for font-size.** The obvious fluid-type trick is the one thing
  banned here — the Etch/ACSS validator rejects it and it is a NibWP house rule.
  Use a token with a px fallback.

## Option 2 — breakpoints in the builder

Etch, Bricks and Elementor all have per-breakpoint controls. The neutral HTML
handoff is where you ask for them. Pass the intent explicitly: the builder skill
cannot infer that a row should stack at tablet from markup that only describes one
width.

Standard intents to carry across:

| Pattern at desktop | Tablet | Mobile |
|---|---|---|
| horizontal row, 3+ children | 2 across | stack |
| horizontal row, 2 children | stack | stack |
| side-by-side text + image | stack, text first | stack, text first |
| large padding (96px+) | roughly 60% | roughly 40% |
| display type (56px+) | roughly 75% | roughly 55% |
| a fixed-width sidebar | below the content | below the content |

Those ratios are a starting point, not a rule. A design with deliberately tight
mobile spacing deserves the designer's numbers, not these.

## Ask for the other artboards

The most useful thing you can do costs one question. Designers who care about
responsive behaviour usually **draw** it — a desktop artboard and a mobile
artboard, side by side in the same Paper file.

So ask: *"Is there a mobile artboard? If so I'll read both and match them rather
than guessing."*

If there is, read each artboard and diff them. The differences are the designer's
actual responsive intent, and they beat any heuristic in this file. Carry them into
the builder handoff as explicit per-breakpoint instructions, and name in the plan
which rules came from the design rather than from a default.

If there is only one artboard, say which defaults you applied. A user who is told
"your three-column row will stack below 768px because there was no mobile artboard"
can correct you in one sentence.

## Paper's advantage

Paper's canvas is HTML and CSS, so the layer you are reading is already flexbox
with real CSS sizing. If a Paper layer uses a percentage width, a `min-width`, or a
flex-grow, **that is responsive intent already expressed in the target language** —
read it and keep it rather than flattening it to the computed pixel value.

This is where a computed-styles read beats a geometry read. A node that computes to
`480px` at the artboard width might be `flex: 1` in the source. The pixel value is
a fact about one width; the flex value is a fact about the design. Prefer the
latter where the tool gives you both.

## What to check after converting

Render at three widths and look. The failures are predictable:

- a horizontal row overflowing because nothing wraps;
- display type too large to fit on a phone;
- padding that was comfortable at 1440px eating the whole screen at 390px;
- a background image cropping its subject out of frame;
- a fixed-height section clipping text that reflowed taller.

Each one is a `warnings` entry if you cannot fix it, and a fix if you can.
