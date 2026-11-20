# Portability rules

Every save into the library runs these — through `nibwp/etchedy-pro-save-component`, and through `nibwp/etchedy-manage` too, on `save` and on an `update` that sends a block. A component is going to a second site; these check that nothing in it is true of this one alone.

`move` and `duplicate` are exempt: they copy what is already stored, and must not fail because the rules were tightened after it went in. `__libraryMeta` is Etchedy's bookkeeping and is not scanned.

Three **block** the save. Two **warn**. Each is reported with a `rule` (also `id`), a `msg` and a `path` pointing at the offending spot.

---

## `site_specific_id` — blocks

A post id, term id, user id or `wp_block` ref that belongs to this site.

On any other site that number is a different post or none at all, so the component renders the wrong thing or nothing, with no error.

**What it looks for**

- `attrs.attributes` carrying `data-post-id`, `data-page-id`, `data-term-id`, `data-user-id`, `postId` or `post_id` with a value.
- `attrs.ref` that matches **no key in the payload's own `components`**.

**What it does not flag.** A `ref` that does match. Etchedy files point an instance at a definition they carry — `"ref": 947301` is a key in `components`, and the persister rewrites it to a real post id on the way in. That is the format working as designed.

**Failing**

```json
{ "blockName": "etch/component", "attrs": { "ref": 1041 } }
```

with nothing at `components["1041"]`.

**Fixed.** Add the definition and reference it by its key:

```json
"components": { "1041": { "name": "Card", "key": "card", "properties": [ ... ], "blocks": [ ... ] } }
```

Or, for a post id: replace the literal with a property.

```json
{ "name": "Post", "key": "post", "keyTouched": false, "type": { "primitive": "number" }, "default": 0 }
```

---

## `site_specific_url` — blocks

An absolute URL containing `/wp-content/`, anywhere in the payload.

It points into this install's uploads. It will 404 on any other site.

**Failing**

```css
.acme-hero { background: url(https://client.test/wp-content/uploads/2026/01/hero.jpg); }
```

**Fixed.** Make the image a property and read it through a custom property with a fallback:

```css
.acme-hero { background: var(--hero-image, none); }
```

An absolute URL to somewhere that is not a WordPress install — a font host, a CDN — is fine.

---

## `unbound_field` — blocks

A field binding (`data-bind`, `data-field` or `binding`, written `source:field` or just `field`) whose field name matches no property the payload declares.

The binding reads a field. The property is what tells the next site which field to supply. Without it the binding resolves to nothing on a site that has not already got a field of that name, and the component renders empty.

**Failing**

```json
{ "attrs": { "attributes": { "data-bind": "acf:hero_headline" } } }
```

with no property keyed `hero_headline`.

**Fixed**

```json
{ "name": "Hero headline", "key": "hero_headline", "keyTouched": false, "type": { "primitive": "string" }, "default": "" }
```

---

## `no_properties` — warns

A component definition with an empty `properties` list.

Every value in it is fixed. Reusing it means editing it, and an edited copy is a different component. Expose the text, images and links that would change between uses.

Not every definition needs many. A pure layout shell with nothing that varies is a legitimate exception — say so to the user rather than inventing a property to silence the warning.

---

## `property_no_default` — warns

A property with no `default` key.

A fresh instance renders blank, and the person placing it sees an empty box with no hint of what belongs there. Every property in the official library has one.

**Failing**

```json
{ "name": "Title", "key": "title", "keyTouched": false, "type": { "primitive": "string" } }
```

**Fixed.** Add one, even if it is a placeholder sentence:

```json
{ "name": "Title", "key": "title", "keyTouched": false, "type": { "primitive": "string" }, "default": "Your headline here" }
```

An empty string `""` is a valid default — the rule checks the key exists, not that it is non-empty. Use it for an image or a link that is genuinely optional.
