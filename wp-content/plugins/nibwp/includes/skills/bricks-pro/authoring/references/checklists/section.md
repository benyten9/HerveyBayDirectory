# Section template — Bricks checklist

`template_type = "section"` — a reusable block embedded via the `template` element on consuming templates.

## When to use

| Situation | Use section? |
|---|---|
| Same hero appears on 3+ landing pages | YES — section template |
| Footer is fully site-global | NO — use `footer` template type instead |
| One-off block, only used once | NO — persist directly on the consuming page |
| Block has logic that varies per page (e.g. headline differs) | YES — section + dynamic data tags resolved by the consuming template's scope |

The recommender flags `extract_to_section_template` when it detects ≥3 sibling structures that look like a clean reusable unit AND the template_type isn't already "section".

## Structure
- [ ] Root: `section` element (`tag="section"`) with `_cssGlobalClasses = ["{brand}-section-<slug>"]`
- [ ] Single subtree — no top-level siblings inside a section template (Bricks expects one root)
- [ ] No display conditions (`_conditions` is per-element, applies normally; template-level conditions don't apply to sections)

## Two-step workflow

1. **First call**: persist the subtree as `template_type = "section"`. Server returns `{ template_id: N }`.
2. **Second call**: on the consuming template, place a `template` element where the section should appear:

```json
{
  "name": "template",
  "settings": {
    "_id": "tplref01",
    "template": <template_id>
  }
}
```

Bricks renders the section's elements in-place at render time. Edit the section template → every consuming page updates.

## Dynamic data within sections

- Dynamic tags (`{post_title}`, `{acf:field}`) resolve against the CONSUMING template's scope, NOT the section's.
- A section embedded on a content template gets the current post in scope.
- A section embedded on a generic page (front-page, static page) has the consuming page's post in scope.
- A section embedded inside a `posts` query loop child resolves against EACH iterated post.

## Global classes — share or scope?

- Section's global classes are merged into `wp_options[bricks_global_classes]` like any other template. They're available site-wide once persisted.
- Naming convention: section-specific classes use `{brand}-section-<slug>__` (so they don't collide with consumer-page classes).

## Performance
- [ ] Bricks caches rendered section markup (when caching enabled at site level)
- [ ] No additional DB query per section embed — element tree is part of the parent render
- [ ] Heavy media inside section (slider, video) — still lazy-loaded per Bricks defaults

## Versioning + safety
- [ ] When updating a section heavily used across pages, persist with `mode = "replace_template"` so existing embeds keep working
- [ ] Renaming the section's slug breaks dynamic-data references that include the slug — avoid post-publish slug edits

## Lessons learned (auto-injected)
{{INJECTED_FEEDBACK}}
