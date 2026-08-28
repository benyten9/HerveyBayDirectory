# Example — a search page

Search form, results feed and map. Built and verified against a live install:
Voxel's own resolver finds the feed from the form and the form from the feed.

```json
[
  {
    "elType": "container",
    "settings": {"content_width": "boxed"},
    "elements": [
      {
        "elType": "widget", "widgetType": "ts-search-form",
        "settings": {
          "ts_choose_post_types": ["places"],
          "ts_search_on": "filter_update",
          "ts_filter_list__places": [
            {"ts_choose_filter": "keywords", "keywords:display_as": "inline"},
            {"ts_choose_filter": "location", "location:display_as": "inline"},
            {"ts_choose_filter": "terms", "terms:display_as": "popup"}
          ]
        }
      }
    ]
  },
  {
    "elType": "container", "settings": {},
    "elements": [
      {
        "elType": "widget", "widgetType": "ts-post-feed",
        "settings": {
          "ts_source": "search-form",
          "ts_posts_per_page": 9,
          "ts_card_template__places": "main",
          "ts_noresults_text": "Nothing here yet"
        }
      }
    ]
  },
  {
    "elType": "container", "settings": {},
    "elements": [
      {"elType": "widget", "widgetType": "ts-map", "settings": {}}
    ]
  }
]
```

Submitted as:

```json
{"template": {"kind": "search-page", "title": "Find a place"},
 "elements": [ … ],
 "dry_run": true}
```

`relations` is left out, so it defaults to `"auto"`: the feed and the map are
each paired with the search form nearest them, and both paths are computed from
this tree. The dry run reports what it wired before anything is written.

`keywords`, `location` and `terms` are filter keys configured on this site's
`places` post type. They are not universal — read the real ones from
`nibwp/voxel-pro-catalog { topic: "widgets", post_type: "places" }`.

If you later insert anything above the search form, every stored position
shifts. Use `nibwp/voxel-pro-refine` for that change and it re-wires them; edit
the meta by hand and the feed goes quietly empty.
