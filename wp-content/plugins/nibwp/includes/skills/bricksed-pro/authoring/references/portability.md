# Portability rules

Every save into the library runs these — through `nibwp/bricksed-pro-save-component`, and through `nibwp/bricksed-manage` too, on `save` and on an `update` that sends a block. A component is going to a second site; these check that nothing in it is true of this one alone.

`move` and `duplicate` are exempt: they copy what is already stored, and must not fail because the rules were tightened after it went in. `__libraryMeta` is Bricksed's bookkeeping and is not scanned.

Three **block** the save. Three **warn**. Each is reported with a `rule` (also `id`), a `msg` and a `path` pointing at the offending spot, such as `bricks.content.4.settings.image.id`.

---

## `site_specific_id` — blocks

An id of something on this site: a media-library attachment, a post, a template, a term, a menu, a form, a user. Or an instance of a Bricks component the payload does not include.

On any other site that number is a different thing or nothing at all, so the section renders the wrong thing or nothing, with no error.

**What it looks for**

- An image setting with an attachment `id` beside its `url` — every image picked from the media library has one.
- A link with `type: "internal"` and a `postId`.
- `postId`, `templateId`, `template`, `termId`, `menu`, `formId`, `userId`, `attachmentId` and similar keys with a numeric value.
- A query that names posts or people by id: `post__in`, `post__not_in`, `post_parent`, `author__in`, and the like.
- An element with a `cid` that matches no component in `bricks.components`.

**Failing**

```json
{ "name": "image", "settings": { "image": { "id": 412, "url": "https://client.test/wp-content/uploads/2026/01/team.jpg", "size": "large" } } }
```

**Fixed.** Make the image a property of the component, with a placeholder default, or point at an external URL with no `id`:

```json
{ "name": "image", "settings": { "image": { "url": "https://images.example.com/team.jpg", "external": true } } }
```

For a link, use an external or relative URL, or a component property. For a query, query by post type, taxonomy or field so the loop fills itself on any site. For a component instance, include the component under `bricks.components` — saving from a page with `source` does this for you.

---

## `site_specific_class` — blocks

An element uses a global class (`settings._cssGlobalClasses`) that is not in `bricks.globalClasses`.

That class lives in this site's global classes only. Placed anywhere else, the element arrives unstyled.

**Fixed.** Include every class the elements use. Saving from a page with `source` collects them for you.

---

## `site_specific_url` — blocks

A URL into this install's `wp-content`, anywhere in the payload: absolute (`https://client.test/wp-content/...`), root-relative (`/wp-content/...`), or an uploads folder by its dated layout (`/uploads/2026/01/`).

It will 404 on any other site.

**Failing**

```css
.acme-hero { background: url(https://client.test/wp-content/uploads/2026/01/hero.jpg); }
```

**Fixed.** Make the image a component property and read it through a custom property with a fallback:

```css
.acme-hero { background: var(--hero-image, none); }
```

An absolute URL to somewhere that is not a WordPress install — a font host, a CDN — is fine.

---

## `site_field_binding` — warns

A dynamic data tag that reads a custom field of this site: `{acf_hero_title}`, `{acf:hero_title}`, `{mb_...}`, `{jet_...}`, `{pods_...}`, `{post_meta:...}`, `{wp:user_meta:...}`.

On a site without a field of the same name the text comes out empty. It is a warning, not a refusal, because query loops built on custom fields are the point of some sections. Say which fields it needs in the component's description, or expose the value as a component property instead.

Core tags — `{post_title}`, `{post_excerpt:25}`, `{site_url}` — work everywhere and are not flagged.

---

## `no_properties` — warns

Nothing in the payload is a Bricks component, or a component declares no properties.

Every value is fixed. Reusing it means editing it, and an edited copy is a different component. Make the part that repeats or varies a component and expose the text, images and links that change.

A pure layout shell with nothing that varies is a legitimate exception — say so to the user rather than inventing a property to silence the warning.

---

## `property_no_default` — warns

A component property with no `default`.

A fresh instance renders blank, and the person placing it sees an empty box with no hint of what belongs there.

**Failing**

```json
{ "id": "mwx3at", "label": "Question", "type": "text", "connections": { "je059w": ["text"] } }
```

**Fixed.** Add one, even a placeholder sentence:

```json
{ "id": "mwx3at", "label": "Question", "type": "text", "default": "Your question here", "connections": { "je059w": ["text"] } }
```

An empty string `""` is a valid default — use it for a link or a label that is genuinely optional.
