# Voxel's widgets

Names and required settings are also available live, which is the version to
trust: `nibwp/voxel-pro-catalog { topic: "widgets" }`, and
`{ topic: "widget", widget: "ts-search-form" }` for one widget's full control
list read from this install.

## Search and results

### `ts-search-form`

| Setting | Notes |
|---|---|
| `ts_choose_post_types` | Array of post type keys. **Required.** |
| `ts_filter_list__{post_type}` | Repeater, one row per filter, per chosen post type. |
| `ts_on_submit` | `post-to-feed` (default) · `submit-to-archive` · `submit-to-page` |
| `ts_search_on` | `submit` · `filter_update` (search as you type) |
| `ts_search_text`, `ts_reset_text` | Button labels |
| `ts_show_search_btn`, `ts_show_reset_btn` | `"true"` / `"false"` as strings |
| `ts_sf_input_label` | `"yes"` shows labels above each filter |
| `ts_card_template__{post_type}` | Which card the results use |

Each row of `ts_filter_list__{post_type}`:

```json
{"ts_choose_filter": "terms",
 "terms:display_as": "popup",
 "terms:inl_set_menu_cols": 2,
 "ts_filter_width": {"unit": "px", "size": 50, "sizes": []},
 "ts_default_value": "yes", "ts_reset_value": "default_value"}
```

`ts_choose_filter` names a filter configured on that post type — read the real
keys from the catalog, they are per site. A filter's own controls are
namespaced `{filter_key}:{control}`; `location:display_as`,
`keywords:display_as`, `post-status:choices` and so on.

`ts_filter_width` is a percentage of the row despite the `px` unit.

### `ts-post-feed`

| Setting | Notes |
|---|---|
| `ts_source` | `search-form` (default when omitted) · `search-filters` · `manual` · `archive` |
| `ts_posts_per_page` | With `search-form` |
| `ts_card_template__{post_type}` | `"main"` or a card template id |
| `ts_wrap_feed` | `ts-feed-grid-default` (grid) · `ts-feed-nowrap` (carousel) |
| `ts_feed_column_no` | Plus `_laptop`, `_tablet`, `_mobile` |
| `ts_feed_col_gap` | `{unit, size, sizes}` |
| `ts_pagination` | `load_more` · `prev_next` · `none` |
| `ts_noresults_text` | Shown on an empty result |
| `ts_manual_posts` | With `manual`: repeater of `{post_id}` |

### `ts-map`

`ts_map_height`, `ts_default_lat`, `ts_default_lng`, `ts_default_zoom`,
`ts_clusters`, `ts_drag_search`, `ts_card_template_map__{post_type}`.

Marker appearance is configured on the post type, not here.

### `quick-search`, `ts-term-feed`

`quick-search` is a typeahead overlay — `ts_choose_post_types`, `ts_qr_text`.
It does not wire to a feed. `ts-term-feed` lists taxonomy terms as cards —
`ts_choose_taxonomy` **required**, `ts_parent_term_id`, `ts_card_template`.

## Listing content

`ts-advanced-list` — the actions row, and the workhorse of a preview card.
Repeater `ts_actions`, one row per action: follow, share, direct message,
verified badge, opening status, plain text. Put per-item visibility rules on
the row itself.

`ts-gallery` / `ts-slider` — images from a gallery field, via a dynamic tag.

`ts-work-hours` — `ts_source_field` names the post type field holding the
hours. `ts_wh_collapse` collapses to today only.

`ts-review-stats`, `ts-ring-chart`, `ts-visits-chart`, `ts-countdown`.

`ts-timeline` — `ts_mode` decides whether it is a newsfeed, a wall, reviews or
comments.

## Commerce and accounts

`ts-create-post` (`ts_post_type` **required**) is the front-end submission
form; it belongs on the post type's `form` template, which is a page.
`ts-product-form`, `ts-product-price`, `ts-cart-summary`, `ts-orders`,
`ts-login` (on the `auth` template), `ts-current-role`.

## Navigation

`ts-navbar` — `ts_choose_menu`, `ts_choose_mobile_menu`, `ts_collapsed`. Can
bind to a tabs or search widget on the same document.

`ts-user-bar` — repeater `user_area_repeater`, one row per component
(messages, notifications, cart, a menu), each with its own visibility per
breakpoint.

## Composition

`ts-print-template` (`ts_template_id` **required**) embeds another template —
use it instead of duplicating a section. `ts-template-tabs` (`ts_tabs`
**required**) makes tabs whose panels are other templates.

## Style kits

`ts-test-widget-1` is the popup kit — the name is historical, the widget is
current. `ts-timeline-kit` is the timeline kit. Each is the only widget on its
template, and assigning that template restyles the whole site.
