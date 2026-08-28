# The search wiring

## What it is

A `ts-search-form` sends its results to a `ts-post-feed` and a `ts-map`. Which
ones is not recorded in the element tree. It lives in the post meta
`_voxel_page_settings` on the document that holds all three:

```json
{"relations":{
  "feedToSearch":[{"left":"09f4e9a","right":"feed001","leftPath":"0.0","rightPath":"1.0"}],
  "mapToSearch": [{"left":"09f4e9a","right":"29786a8","leftPath":"0.0","rightPath":"2.0"}],
  "tabsToNavbar":[], "searchToNavbar":[]
}}
```

`left` is the search form, `right` is the feed or the map. The two `*Path`
values are **positions in the element tree**: `"1.0"` means top-level element
1, then its child 0. Voxel resolves a pairing by matching one side's id, then
walking the other side's path.

## Why it breaks

Positions move. Insert one container above a search form and every path below
it is wrong — and nothing reports it, because a feed that cannot find its
search form simply renders nothing.

This is not hypothetical. On the demo content that ships with the theme, the
home page carries four `feedToSearch` entries; one resolves to a heading
widget, and the rest resolve to nothing at all.

## What this skill does about it

Paths are computed at write time from the tree being written, and recomputed
on every refine. A path you send is ignored.

`relations: "auto"` — the default — pairs each feed and map with the search
form that shares the most of its path, which is the one nearest it in the
layout. One search form driving several feeds works; several search forms each
driving the feed beside them works.

To override, name the elements and let the paths be derived:

```json
{"relations": {"feedToSearch": [{"left": "<search id>", "right": "<feed id>"}]}}
```

An id in that list that is not in the document fails the build with both ids
named.

## When a feed needs wiring

Only when it is sourced from a search form — `ts_source` unset (the default)
or `"search-form"`. A feed with `ts_source` of `manual`, `archive` or
`search-filters` runs its own query and is left alone.

Maps are always wired when a search form is present.

## The other two groups

`tabsToNavbar` and `searchToNavbar` connect a `ts-navbar` to a
`ts-template-tabs` or a `ts-search-form`. This skill preserves whatever is
already stored for them but does not compute them; wire those in Elementor.
