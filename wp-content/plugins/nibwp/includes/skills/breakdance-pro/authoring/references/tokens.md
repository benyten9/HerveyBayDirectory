# Tokens

Three layers, all readable with `nibwp/breakdance-pro-tokens`.

## Variables
The design tokens — colors, sizes, spacing. Use them in properties instead of
literals. When a literal you set matches one exactly, the validator says so.

## Selectors
Global classes. A class used on forty pages is styled once. Reuse an existing
selector before creating a new treatment for the same job.

## Presets
Saved element styling. A preset is how the site already answers "what does a
primary button look like". Adopt it rather than restyling from scratch.

## When there is no token layer
`has_token_layer: false` means the site defines none. Literals are then correct.
Do not invent a palette and do not pretend the site has a system it does not.
Say what you did.
