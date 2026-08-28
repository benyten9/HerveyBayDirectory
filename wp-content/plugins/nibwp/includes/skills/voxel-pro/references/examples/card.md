# Example — a preview card

Built and verified against a live install. Every dynamic tag here renders
against a real listing.

```json
[
  {
    "elType": "container",
    "settings": {"content_width": "full"},
    "elements": [
      {
        "elType": "widget", "widgetType": "image",
        "settings": {
          "image": {
            "url": "@tags()@post(_thumbnail_id.id)@endtags()",
            "id": "@tags()@post(_thumbnail_id.id)@endtags()",
            "source": "library"
          },
          "image_size": "medium",
          "link_to": "custom",
          "link": {"url": "@tags()@post(:url)@endtags()"}
        }
      },
      {
        "elType": "widget", "widgetType": "heading",
        "settings": {
          "title": "@tags()@post(:title)@endtags()",
          "header_size": "h3",
          "link": {"url": "@tags()@post(:url)@endtags()"}
        }
      },
      {
        "elType": "widget", "widgetType": "ts-advanced-list",
        "settings": {
          "ts_actions": [
            {"ts_action_type": "action_link",
             "ts_acw_text": "@tags()@post(location.address)@endtags()"},
            {"ts_action_type": "action_link",
             "ts_acw_text": "@tags()@post(:reviews.average).fallback(0)@endtags()",
             "_voxel_visibility_rules": [[{"type": "post:is_verified"}]]}
          ]
        }
      }
    ]
  }
]
```

Submitted as:

```json
{"template": {"kind": "card", "post_type": "places", "title": "Places: Compact card"},
 "elements": [ … ],
 "assign": {"post_type": "places", "pt_slot": "card",
            "mode": "custom", "label": "Compact card"},
 "dry_run": true}
```

Notes on the shape:

- The image widget wants the attachment **id** in both `url` and `id`; Voxel
  resolves the tag and Elementor takes it from there.
- `_thumbnail_id`, `location` and `reviews` are this site's keys. Read the real
  ones — a card built against another site's field names renders blank.
- The second action only appears on verified listings. Visibility rules go on
  the repeater row, not on the widget.
- `mode: "custom"` adds this as a named alternate rather than replacing the
  post type's existing card. Choose it per feed with
  `ts_card_template__places: <id>`.
