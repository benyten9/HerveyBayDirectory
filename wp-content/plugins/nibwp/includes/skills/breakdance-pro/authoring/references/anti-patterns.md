# Anti-patterns

Each of these fails silently in Breakdance. That is why they are rules.

## Single-escaped element slugs
`"EssentialElements\Heading"` in JSON becomes `EssentialElementsHeading`, which
is not registered. Nothing errors; the node renders as nothing.
**Always** `"EssentialElements\Heading"`.

## Guessed element slugs
The element set varies by license, active subplugins and third-party packs.
`nibwp/breakdance-pro-elements` is one call. Guessing costs a blank section and
a confused user.

## Writing to post_content
A Breakdance page is a tree in post meta. Anything written to the post body is
ignored by the front end. If you find yourself reaching for `wp-create-post`
with HTML, stop.

## Inline @media
Breakdance stores per-breakpoint values on the control itself. An inline media
query inside a property is not applied and cannot be edited in the builder.

## Hardcoded values that already have a variable
`#1a73e8` when the site defines `brand-primary` with that exact value. It looks
identical today and stops matching the moment someone edits the palette.

## Rebuilding a page to change one node
Re-converting discards every hand edit made since. Use `breakdance-pro-refine`.

## Five static cards
If the content will change or grow, five copies is five places to edit. Offer
the loop. If it genuinely will not change, static is fine — say which you chose.

## A template with no conditions
It exists, it is built, and it appears nowhere. Either assign conditions or tell
the user it is unassigned.

## Raw form and video markup
Breakdance has a form builder and a video element. Raw `<form>` collects nothing
and raw `<iframe>` skips lazy loading and aspect-ratio handling.

## Deep wrapper nesting
A section inside a container inside a div inside another div is four things to
style and three that do nothing. Breakdance sections and containers already
handle width and spacing.

## Images with no alt text
Describe the image, or mark it decorative on purpose. Silence is neither.
