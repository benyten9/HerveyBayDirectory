# Voxel — how to work on this site

Voxel is a directory/listing theme that keeps almost nothing where WordPress
normally keeps it. Post types are rows in a JSON option, field values are spread
across typed meta and five custom tables, and **Voxel publishes no REST API at
all** — its post types are excluded from `wp/v2` unless a per-type switch is on.
The ordinary WordPress abilities will therefore read and write the wrong things.
Use `nibwp/voxel-*` for anything Voxel owns.

## Start here, every time

Call **`nibwp/voxel-info`** first. Voxel sites differ per install: post type
keys, which modules are on, whether paid memberships or messages exist. It also
reports index health, which is how "search finds nothing" announces itself.

Then, before writing a listing, call **`nibwp/voxel-post-types` action=fields**
for that type. It returns every writable key with its type and the shape it
expects. Guessing field keys wastes a turn; the error will hand you the whole
vocabulary anyway, so read it up front.

## Writing listings

`nibwp/voxel-posts-write` runs values through Voxel's own
sanitize → validate → update, then refreshes the search index. It is
**all-or-nothing**: if one field fails validation, nothing is written, and the
response names each failing field. That is deliberate — a half-written listing
is worse than a refusal.

Shapes that trip people up:

| Field type | Send |
|---|---|
| `taxonomy` | array of term **slugs** — not IDs, not names. Unknown slugs are refused with the list of valid ones. Create missing terms with `voxel-terms` first. |
| `image`, `file`, `gallery` | attachment IDs, **or** `https://` URLs, which are imported into the media library for you |
| `location` | `{address, latitude, longitude}` |
| `repeater` | array of row objects, keyed by the sub-field keys in the schema |
| `post-relation` | array of post IDs |
| `title` | just `title` — it is written through WordPress, because Voxel's own title field ignores writes |

On `create`, a validation failure **keeps the draft** and returns its id. Repair
it with `action=update` on that id rather than starting again.

Values written here skip the frontend's visibility and conditional-logic rules.
That is correct for an admin-level connection, but it means you can set a field
a member submitting the form would never see. Do not use it to bypass a rule the
site owner intended.

## Searching

`nibwp/voxel-posts action=search` goes through Voxel's index tables, so it
understands geographic radius, price, availability and the site's own configured
filters — things `WP_Query` cannot answer. Pass filter values in `filters`,
keyed by filter key; the response lists `available_filters` if you guessed
wrong.

## Changing the data model

`nibwp/voxel-schema` edits post type and taxonomy **definitions**. It is scoped
`mcp:manage`, so a default connection cannot reach it at all.

Always `action=preview` first and show the user the diff. Then `action=patch`
with `confirm: true`. Fields are matched by key and merged; nothing is ever
removed unless you list it in `remove_fields`. Every apply backs up the previous
config (last five, restorable with `action=rollback`).

**After any field change, run `action=reindex`.** It works in batches and returns
`next_offset` until `complete` is true — keep calling until it is, or search will
not see the change.

## Deleting

Every delete is in `nibwp/voxel-delete`, and nowhere else, so a read+write
connection physically cannot delete anything. Prefer `post-trash` (reversible)
over `post-delete`. Trashing removes the listing from the search index first, so
the site does not serve results that 404.

## Commerce

`voxel-orders` changes the **order record**. It does not refund, capture or
charge anything at Stripe/Paddle/PayPal — say so plainly when you mark an order
refunded, because the money has not moved.

`voxel-plans` covers membership plans and paid-listing packages. If a module is
switched off the ability says so and names the switch, rather than failing
obscurely.

## Community

`voxel-timeline` publishes as the connected user unless `user_id` says
otherwise. Tell the user whose voice you are writing in before you post.

`voxel-messages` can read **any** user's private inbox. Treat what you read as
confidential, repeat it only to someone entitled to it, and say when you are
reading messages that are not the requester's.

## Templates

`voxel-templates` manages the map — which Elementor template fills which slot.
The template's **content** is Elementor's business: build it with the Elementor
integration or in Agent View, not here.

## Things that will bite you

- Voxel memoizes its models. This integration forces re-reads after every write;
  if you reach past it into `\Voxel\` directly, you will read stale objects.
- Index membership follows the site's configured indexable statuses, not just
  `publish`. Do not assume unpublishing removes a listing from search.
- Verified against **Voxel 1.7.8.6**. Voxel has no public API and no stability
  promise; if an ability starts returning `voxel_internal_error`, check the
  theme version in `voxel-info` before assuming the site is broken.

{{INJECTED_FEEDBACK}}
