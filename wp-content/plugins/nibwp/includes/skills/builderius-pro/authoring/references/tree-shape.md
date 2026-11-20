# The config shape

## Storage

A template's authored content is a **config**, committed onto a branch. It is a
flat map of modules keyed by a unique id:

```json
{
  "modules": {
    "ua39009f29": {
      "id": "ua39009f29",
      "name": "HtmlElement",
      "label": "header",
      "settings": [
        {"name": "tag", "value": "header"},
        {"name": "tagClass", "value": ["outer-wrapper", "site-header"]}
      ],
      "parent": ""
    },
    "u3e858f64c": {
      "id": "u3e858f64c",
      "name": "HtmlElement",
      "label": "a",
      "settings": [{"name": "tag", "value": "a"}],
      "parent": "ua39009f29",
      "index": 0
    }
  }
}
```

- `id` — unique within the config, and equal to the map key. Builderius's own ids
  look like `ua39009f29`: `u` plus hex.
- `name` — a module type from the library.
- `label` — what a human sees in the structure panel. Make it mean something;
  `refine` targets modules by label.
- `settings` — **always a list of `{name, value}`**.
- `parent` — the id of the parent module, or `""` for a root module.
- `index` — position among siblings. Root modules carry none.

There is no `children` key in a stored config. Parentage is by reference.

## Authoring

You write the nested form and let the integration flatten it:

```json
[
  {
    "name": "HtmlElement",
    "label": "hero",
    "settings": {"tag": "section", "tagClass": ["hero"]},
    "children": [
      {"name": "HtmlElement", "label": "hero__title", "settings": {"tag": "h1", "content": "Hello"}}
    ]
  }
]
```

`nibwp/builderius-pro-convert` returns both: the tree it built and the config it
flattens to. Hand the **config** to build.

Settings may be written as a map here — `{"tag": "section"}` — and are converted
to the `{name, value}` list. That convenience exists for authoring only; a config
you pass in already flattened must use the list form.

## Why the flat map matters

Two mistakes follow from ignoring it:

1. Writing `children` into a stored config. Builderius reads `parent`, finds
   nothing, and the modules are not in the tree.
2. A `parent` that names an id the config does not contain. The module exists,
   renders nowhere, and is almost impossible to find in the builder. The
   validator calls this an orphan and refuses it.
