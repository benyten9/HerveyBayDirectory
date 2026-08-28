# The document format

## An element

```json
{"id": "a1b2c3d",
 "elType": "container",
 "settings": {},
 "elements": []}
```

A widget adds `widgetType`:

```json
{"id": "e4f5a6b", "elType": "widget", "widgetType": "heading",
 "settings": {"title": "…", "header_size": "h3"}, "elements": []}
```

`elType` is `container` or `widget` for anything new. `section` and `column`
are the older layout pair — valid, and worth matching if you are editing a
document already built that way.

## Ids

Seven hexadecimal characters, unique across the document. Elementor keys its
generated CSS off them, so a duplicate makes two elements share styling.

Leave them out and they are minted. Supply one that is not valid hex and it is
replaced — the build response lists what each became under `renamed_ids`, and
those are the ids a later refine has to use.

Repeater rows carry their own `_id`, in the same shape, minted the same way.

## Repeaters

A setting whose value is a list of objects is a repeater. Each row is one
entry, keyed by that repeater's own sub-settings:

```json
"ts_actions": [
  {"_id": "1a2b3c4", "ts_action_type": "action_link", "ts_acw_text": "…"},
  {"_id": "5d6e7f8", "ts_action_type": "action_share"}
]
```

## Responsive and global values

A control takes `_tablet` and `_mobile` suffixes for its breakpoint values:
`ts_feed_column_no`, `ts_feed_column_no_tablet`, `ts_feed_column_no_mobile`.

Sizes are objects: `{"unit": "px", "size": 24, "sizes": []}`.

Global colors and fonts are referenced through `__globals__` on the same
settings object, not by value:

```json
"__globals__": {"typography_typography": "globals/typography?id=text",
                "title_color": "globals/colors?id=accent"}
```

## Page settings

`_elementor_page_settings` holds document-level settings. Card, single and
archive templates also carry `voxel_preview_post` — the listing the editor
previews against. The build sets it from the post type's newest published
listing unless `preview_post_id` says otherwise.

## What the build writes

| Meta | Contents |
|---|---|
| `_elementor_data` | The element tree, JSON |
| `_elementor_edit_mode` | `builder` |
| `_elementor_template_type` | `card`, `single-post`, `archive`, `header`, `footer`, `single-term`, or `page` |
| `_elementor_page_settings` | Merged, plus `voxel_preview_post` |
| `_voxel_page_settings` | The search wiring |
| `_nibwp_voxel_pro_backup` | The previous version, on a rebuild |

And it deletes `_elementor_css` and `_elementor_page_assets`, which is how
Elementor is told to regenerate the stylesheet. Skip that and the old styling
keeps being served against the new markup.

Templates are `elementor_library` posts and get Voxel's own library terms.
Pages are ordinary pages. Which one a kind produces is fixed —
`nibwp/voxel-pro-catalog { topic: "widgets" }` lists them.

## Don't write this by hand

Every one of these is set for you. Writing `_elementor_data` through a generic
post-meta ability skips the checks, skips the wiring, and skips the cache
purge — the three things that make the difference between a document that
exists and a template that works.
