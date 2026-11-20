# Anti-patterns

Each of these has a symptom, and the symptom is rarely an error message.

## Guessed module name

**Symptom:** the section exists and part of it is missing.
Builderius renders what it understands and drops the rest. An unknown module type
is not an error at render time.
**Fix:** `nibwp/builderius-list-modules`. The validator refuses an unknown name
before anything is committed and suggests the closest real one.

## Guessed setting name

**Symptom:** the structure is right and the content is empty.
`textContent` instead of `content` is the commonest one, and it produces a
perfectly shaped box with no text in it.
**Fix:** `nibwp/builderius-pro-modules`. Settings are declared per module.

## A setting on the wrong module

**Symptom:** as above. `contentSvg` on an `HtmlElement` does nothing;
`SvgCode` is what accepts it.
**Fix:** the refusal names the module the setting belongs to.

## `children` in a stored config

**Symptom:** nothing renders.
Storage links modules by `parent`. A nested `children` key is not read.
**Fix:** author the nested tree, let the integration flatten it.

## Settings as an object map in storage

**Symptom:** the module renders with no settings applied.
A stored setting is `{"name": …, "value": …}`. The map form is input convenience
only.

## A real CSS selector instead of `%local%`

**Symptom:** the style applies to everything, or to nothing.
`%local%` is how Builderius scopes a module's CSS.

## Hardcoded site URL

**Symptom:** staging links to production.
**Fix:** `[[[wp.site_url]]]`.

## Five hand-built cards

**Symptom:** five things to edit whenever the content changes, and the client
cannot change any of them.
**Fix:** `Collection` bound to a query. Not every repetition should be a loop —
three feature blurbs nobody will touch are fine as they are.

## Hand-written links in a nav

**Symptom:** the client asks you to add a menu item.
**Fix:** `MenuBuilder` renders a WordPress menu.

## Hand-written form inputs

**Symptom:** the form looks right and collects nothing.
**Fix:** `SmartForm`, with its fields configured in Builderius.

## A template with no type

**Symptom:** the template exists and applies nowhere.
**Fix:** set the type taxonomy, or say plainly that it is unassigned. Do not
report it as a finished page.

## Rebuilding to change one thing

**Symptom:** a commit that looks like a rewrite, burying everything someone
adjusted by hand.
**Fix:** `nibwp/builderius-pro-refine`.

## Committing over a published release

**Symptom:** the published version number still says 1.0 and the content is not
the 1.0 anybody reviewed.
**Fix:** this is refused. Commit a new version or a branch and let the user
publish.

## Reporting a commit as a published page

**Symptom:** the user looks at the site and sees the old content.
A commit is a version. The site shows the release.
