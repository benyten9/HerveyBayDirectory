# The node tree

## Storage

The tree lives in post meta under `<prefix>data`, as a JSON string in the
`tree_json_string` key. The prefix is brand-dependent: `_breakdance_` or
`_oxygen_`. Never write this meta directly — the integration encodes it and
regenerates the CSS cache afterwards.

Template settings, **including display conditions**, live under a *separate*
key: `<prefix>template_settings`. They are not part of the tree.

## Shape

```json
{
  "root": {
    "id": 0,
    "data": {"type": "root"},
    "children": [
      {
        "id": 1,
        "data": {
          "type": "EssentialElements\Section",
          "properties": {"design": {"spacing": {"padding": {"top": "80px"}}}}
        },
        "children": [ ... ]
      }
    ]
  }
}
```

- `id` — unique integer within the tree. Allocated server-side.
- `data.type` — a registered element slug.
- `data.properties` — matches that element's control schema.
- `children` — an ordered **list**. Never a sparse array: a gap serialises as a
  JSON object and Breakdance stops recognizing the tree.

## Hierarchy

```
root
 └── Section          layout, full width, vertical rhythm
      └── Container   constrains width, holds the columns
           └── Div    grouping, flex/grid
                └── Heading / Text / Button / Image / ...
```

Only container elements may hold children. `nibwp/breakdance-pro-elements
action=containers` lists them on this site.
