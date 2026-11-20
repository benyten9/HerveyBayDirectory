# Builderius Pro — playbook

You are building on **Builderius**. Read this before you write anything.

## The one thing that matters most

Builderius **commits**; it does not save. A template owns branches, a branch owns
commits, and what a visitor sees is a **release** — a frozen copy of a commit,
stored separately from it. So there are two questions before any build, not one:

1. Is this config valid?
2. Where does it land, and what does landing there replace?

Getting the second one wrong is how published work disappears. This skill will
refuse rather than let that happen, and the refusal names the release it would
have replaced.

## Read before you write

Three calls, always, in this order:

1. `nibwp/builderius-list-modules` — the module library of this install.
2. `nibwp/builderius-pro-modules` — which settings each module actually accepts,
   read from Builderius's own setting declarations on this site.
3. `nibwp/builderius-pro-inspect { action: "releases" }` — which release is
   published, and which commit it froze.

Skipping 1 and 2 is the biggest cause of a section that builds and renders empty:
Builderius does not error on a module type it has never heard of, and it does not
error on a setting a module does not accept. It renders what it understands and
drops the rest.

Skipping 3 is how you find out afterwards.

## The config shape

A stored config is a **flat map** of modules keyed by id, linked by `parent` and
ordered by `index`:

```json
{
  "modules": {
    "ua39009f29": {
      "id": "ua39009f29",
      "name": "HtmlElement",
      "label": "header",
      "settings": [
        {"name": "tag", "value": "header"},
        {"name": "tagClass", "value": ["outer-wrapper", "site-header"]},
        {"name": "css", "value": "%local% { position: relative }"}
      ],
      "parent": ""
    }
  }
}
```

You **author** it as a nested tree — `[{name, label?, settings?, children?}]` —
and the integration flattens it. Do not mint ids yourself and do not write a
`children` key into a stored config. See `references/tree-shape.md`.

A setting in storage is always `{"name": …, "value": …}`. An object map
(`{"tag": "div"}`) is accepted as *input* for convenience and converted; it is
never what is stored.

## Settings are per module

`tag` is an `HtmlElement`, `Collection` and `SubCollection` setting. `contentSvg`
is an `SvgCode` setting. `tagClass`, `tagId`, `htmlAttribute` and `css` apply to
every module — except `css`, which is excluded from a few. None of that is
guesswork: ask `nibwp/builderius-pro-modules`.

The setting that holds text on an `HtmlElement` is **`content`**. It is not
`textContent`, and a config that says `textContent` renders a box with nothing in
it.

## Dynamic data and CSS

- WordPress data is referenced with `[[[wp.*]]]` tokens — `[[[wp.site_url]]]`,
  `[[[wp.post_title]]]`. A hardcoded site URL breaks on staging.
- Per-module CSS goes in a `css` setting and is scoped with `%local%`:
  `%local% { padding: 4rem }`. A real selector either leaks everywhere or matches
  nothing.

See `references/dynamic-data.md`.

## Where the work lands

`version_mode` on build and refine:

| mode | what it does | when |
| --- | --- | --- |
| `new_version` | a new commit on the template's branch | the default; the published release is untouched |
| `new_branch` | a branch of its own, then a commit | the template is live and the change needs reviewing |
| `overwrite` | replace what the release serves for this template | **refused** when a release is published for it |

A new commit becomes the branch head immediately — Builderius resolves the active
commit as the newest commit on the branch. There is no draft commit, so a
half-finished build is what the next person opens.

## The pipeline

```
nibwp/design-direction                         → how this site should look
nibwp/builderius-list-modules                  → the module vocabulary
nibwp/builderius-pro-modules   { action: list } → the setting vocabulary
nibwp/builderius-pro-inspect   { action: releases } → what is published
nibwp/builderius-pro-convert   { html }        → config + report, nothing written
nibwp/builderius-pro-build     { dry_run: true } → the version plan
nibwp/builderius-pro-build     { dry_run: false } → committed
```

## What not to do

- Do not write a Builderius template through a generic post ability, or into
  `post_content`. The content is a committed config.
- Do not improvise module or setting names. Both are published by the install.
- Do not rebuild a template to change one module — `nibwp/builderius-pro-refine`.
- Do not hand-repeat a card five times when the content comes from posts. The
  loop module is `Collection`.
- Do not create a template with no type taxonomy and report it as a finished
  page. It applies nowhere until somebody sets the type.
- Do not publish a release. That is the user's decision, and it is the one action
  that changes what visitors see.

Full list in `references/anti-patterns.md`.

## Say what actually happened

A commit is not a published page. When you have committed, say so in those words:
the version exists, the previous one is still there, and the site still shows the
release until somebody publishes a new one.

## References

- `references/modules.md` — the module library and how to read it from the install
- `references/tree-shape.md` — the flat map, parent/index, and `{name, value}`
- `references/dynamic-data.md` — `[[[wp.*]]]` tokens and `%local%` CSS
- `references/versioning.md` — branches, commits, releases, and the refusal
- `references/anti-patterns.md` — what goes wrong and what it looks like
- `references/checklists/` — hero, navbar, footer, cta, pricing, card-grid,
  testimonial, form, section, generic
