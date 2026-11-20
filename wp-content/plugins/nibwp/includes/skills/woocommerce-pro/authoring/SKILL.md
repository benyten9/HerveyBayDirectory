---
name: woocommerce-pro
description: Set up and run a WooCommerce store — general settings, tax classes and rates, shipping zones and methods, payment gateways, webhooks, global product attributes, variation generation, linked products, downloadable files, tags, bulk pricing, scheduled sales, product duplication, and CSV import/export. Triggers on "set up my store", "add a tax rate", "shipping zones", "enable a payment gateway", "generate variations", "import products from CSV", "export orders".
---

# WooCommerce Pro

Seven hub abilities. Each takes an `action`.

| Ability | Owns |
|---|---|
| `nibwp/wcpro-store` | The store's own configuration: general settings, taxes, shipping, payments, webhooks. |
| `nibwp/wcpro-catalog` | Catalogue work beyond one product: attributes, variations, links, downloads, tags, bulk pricing, sales, duplication. |
| `nibwp/wcpro-import` | Bulk in and out: CSV validation, import (batched), export. |
| `nibwp/wcpro-templates` | What the shop is actually rendered with: block templates and theme overrides. |
| `nibwp/wcpro-emails` | The notification emails: what they say, which are off, and a test send. |
| `nibwp/wcpro-customers` | The customer base as a base: search, segment, order notes, guest orders. |
| `nibwp/wcpro-analytics` | The figures, each carrying where it came from: revenue, refunds, top sellers, stock, coupons, acquisition, health. |

## PART 0 — What this skill does NOT own

The **Free** WooCommerce abilities already cover single-record work, and they are better at it because they are narrower. Do not route these here:

`wc-list-products`, `wc-get-product`, `wc-create-product`, `wc-update-product`, `wc-delete-product`, `wc-list-orders`, `wc-get-order`, `wc-update-order-status`, `wc-create-order`, `wc-update-order`, `wc-create-refund`, `wc-add-order-note`, `wc-list-coupons`, `wc-create-coupon`, `wc-update-coupon`, `wc-delete-coupon`, `wc-list-customers`, `wc-get-customer`, `wc-create-customer`, `wc-update-customer`, `wc-list-variations`, `wc-create-variation`, `wc-update-variation`, `wc-delete-variation`, `wc-list-categories`, `wc-create-category`, `wc-update-category`, `wc-delete-category`, `wc-list-reviews`, `wc-moderate-review`, `wc-bulk-update-stock`, `wc-get-reports`, `wc-audit-store`.

The boundary in one line: **if it is one product, one variation, one order, one coupon, one customer, one category or one review, it is Free. If it is the store, or the whole catalogue at once, it is here.**

`wc-get-reports` is the one overlap worth naming. It gives a quick sales or
top-seller total and it is cheaper. It does not say where its number came from, it
resolves "last 30 days" against the server's UTC clock, it has no comparison period
and it reads every matching order with no bound. Use it when none of that matters;
use `nibwp/wcpro-analytics` when the number is going to be acted on.

You will use both in the same run. A variable product is built by this skill (attributes, variations) and priced by the Free one (`wc-update-variation`). That is correct, not a workaround.

## PART 1 — The order a store has to be built in

Every step below references the one before it **by slug**, and a missing reference fails silently: the rate is stored against the wrong class, the zone offers nothing, the variation is unbuyable. Nothing errors. So:

```
1. nibwp/wcpro-store   { action: "store_status" }                  ← read first, writes nothing
2. nibwp/wcpro-store   { action: "update_general",     settings: {…} }
3. nibwp/wcpro-store   { action: "update_tax_settings", settings: {…} }
4. nibwp/wcpro-store   { action: "create_tax_class",   name: "Reduced rate" }
5. nibwp/wcpro-store   { action: "create_tax_rate",    tax_class: "reduced-rate", rate: {…} }
6. nibwp/wcpro-store   { action: "create_shipping_zone", zone: {…} }
7. nibwp/wcpro-store   { action: "add_shipping_method", zone_id: N, method_type: "flat_rate", method_settings: {…} }
8. nibwp/wcpro-store   { action: "enable_payment_gateway",    gateway_id: "bacs" }
9. nibwp/wcpro-store   { action: "configure_payment_gateway", gateway_id: "bacs", gateway_settings: {…} }
10. products — Free wc-create-product, or nibwp/wcpro-import
11. nibwp/wcpro-catalog { action: "create_attribute" } → create_attribute_term → assign_attributes → generate_variations
12. nibwp/wcpro-store   { action: "create_webhook", webhook: {…} }  ← last, so the first delivery describes a finished store
```

`store_status` answers half the questions you would otherwise ask the user. Call it before asking anything.

## PART 2 — The traps

### Tax rates are rows, not a post type
There is no post id and no CRUD object. They live in `wp_woocommerce_tax_rates`, with the postcode and city lists in a second table. `list_tax_rates` returns a `rate_id`; that id is the only handle. Do not look for a `tax_rate` post type — there isn't one, and reaching for `execute-php` to "find it" is the failure this skill exists to prevent.

Two more:
- A rate naming a tax class that does not exist is **silently rewritten to the standard class** by WooCommerce. This ability refuses it instead, so create the class first.
- Deleting a tax class **deletes every rate in it**. That needs `force: true`.
- A rate with `country: "*"` applies everywhere. That is legitimate — one VAT line for a shop that charges the same in every country.

### A variation cannot exist before its attribute is marked for variations
This is the single most common way a variable product ends up broken, and WooCommerce says nothing about it. The product looks right in wp-admin and cannot be bought.

```
create_attribute        { attribute: { name: "Size" } }              → taxonomy pa_size
create_attribute_term   { taxonomy: "pa_size", term: { name: "Small" } }   (repeat per value)
assign_attributes       { product_id: 42,
                          attributes: [ { name: "pa_size",
                                          options: ["Small","Medium","Large"],
                                          variation: true } ] }      ← variation:true is the whole point
generate_variations     { product_id: 42 }
```

Without `variation: true`, `generate_variations` has nothing to combine and refuses with `no_variation_attributes`. With it, every missing combination is built. The product must already be type `variable` — change it with `wc-update-product` first, or `generate_variations` refuses with `not_variable`.

New variations have **no price**, so they are not purchasable. Set them with `wc-update-variation` one at a time, or across the set with `bulk_price_change`.

A name that matches no global attribute becomes a **per-product custom attribute** instead. It is stored, `assign_attributes` warns about it, and `generate_variations` cannot use it. That is what a typo in `pa_size` looks like.

### A CSV is dry-run first
`validate_csv` writes nothing. It returns one entry per bad row with the **spreadsheet line number**, a field, a severity and a sentence. Read it, fix the file, validate again, then commit.

An import committed on an unvalidated file fails row by row and leaves a catalogue that is half applied and looks finished.

```
nibwp/wcpro-import { action: "validate_csv", path: "imports/products.csv" }
  → problems: [ { line: 14, sku: "TS-RED", problems: [ { severity: "error", field: "sale_price",
                   message: "sale_price (30) is not below regular_price (25), so WooCommerce ignores it." } ] } ]
```

Then:

```
nibwp/wcpro-import { action: "import_csv", path: "imports/products.csv",
                     limit: 200, update_existing: false, _preflight_token: "…" }
  → { job_id: "wcpro_…", next_offset: 200, total_rows: 1840, imported: [ … ] }
nibwp/wcpro-import { action: "import_csv", path: "imports/products.csv", job_id: "wcpro_…", limit: 200, _preflight_token: "…" }
  → … until next_offset is null
```

`job_id` remembers where it got to, so you can pass `job_id` alone and leave `offset` out. `import_status { job_id }` reads the progress without importing anything.

A row whose **SKU already exists is skipped** unless `update_existing: true`. That is what you want on a first import and never what you want on a re-run.

### Paths are relative to the uploads directory
`"imports/products.csv"` — yes. `"/var/www/html/wp-content/uploads/x.csv"`, `"C:/temp/x.csv"`, `"https://example.com/x.csv"` and `"../../wp-config.php"` — all refused, before a handle is opened. `list_files` shows what is actually there.

Exports land in `uploads/nibwp-wcpro/` and the response carries the URL. An order or customer export is personal data sitting in a web-readable directory: hand it over and say it should be deleted.

### Bulk actions need a filter
`bulk_price_change`, `schedule_sale` and `clear_sale` refuse an empty filter rather than defaulting to the whole catalogue. Give `ids`, `category`, `tag` or `type`. All three take `dry_run: true`; use it, because a wrong filter reprices a shop.

### A template override is silently stale

A theme can override a WooCommerce PHP template by copying the file into its own
`woocommerce/` folder. The copy then serves forever, including after WooCommerce
changes that file. This is why a store "loses" a feature after an update with
nothing in any log: the theme is still rendering markup from two years ago.

`list_overrides` reports each override's `@version` against the version of the
core file it shadows and marks the ones that are behind. `stale_only: true` is
the short answer. It is the first thing to run when a store's checkout, add-to-cart
or product gallery is missing something it should have.

### A block template resolves from four places

In order, first hit wins: a site-editor customisation in the database, then the
child theme's `templates/<slug>.html`, then the parent theme's, then the copy
WooCommerce ships. `list_templates` says which one answered.

So `save_template` can write a perfectly good file into the theme and change
nothing, because a site-editor customisation still wins. The response says when
that is the case; `revert_template` clears the customisation.

## PART 3 — Action catalogue

### `nibwp/wcpro-store` (27 actions)

| Action | Notes |
|---|---|
| `store_status` | Read-only. What is configured, what is missing, what to do next. |
| `get_general` / `update_general` | `address_1`, `address_2`, `city`, `postcode`, `base_country`, `currency`, `currency_position`, `thousand_separator`, `decimal_separator`, `price_decimals`, `selling_locations`, `selling_countries`, `selling_except_countries`, `shipping_locations`, `shipping_countries`, `default_customer_address`, `enable_coupons`, `enable_taxes`. |
| `get_tax_settings` / `update_tax_settings` | `prices_include_tax`, `tax_based_on`, `shipping_tax_class`, `round_at_subtotal`, `display_in_shop`, `display_in_cart`, `price_display_suffix`, `total_display`. |
| `list_tax_classes` / `create_tax_class` / `delete_tax_class` | Delete needs `force` when the class has rates. |
| `list_tax_rates` / `create_tax_rate` / `update_tax_rate` / `delete_tax_rate` | `rate: { country, state, postcode, city, rate, name, priority, compound, shipping, order, tax_class }`. |
| `list_shipping_zones` / `create_shipping_zone` / `update_shipping_zone` / `delete_shipping_zone` | Zone 0 is the catch-all; it exists, it takes methods, it cannot be deleted. |
| `add_shipping_method` / `update_shipping_method` / `remove_shipping_method` | `method_settings` are validated against the method's own fields, so a typo is refused rather than stored and ignored. |
| `list_payment_gateways` / `enable_payment_gateway` / `disable_payment_gateway` / `configure_payment_gateway` | Listing returns setting **names**, never values — they include API secrets. |
| `list_webhooks` / `create_webhook` / `update_webhook` / `delete_webhook` | An unrecognised topic is refused here, because WooCommerce would blank it and the webhook would never fire. |

### `nibwp/wcpro-catalog` (24 actions)

| Action | Notes |
|---|---|
| `list_attributes` / `create_attribute` / `update_attribute` / `delete_attribute` | Global, store-wide. Delete needs `force` when terms exist. |
| `list_attribute_terms` / `create_attribute_term` / `update_attribute_term` / `delete_attribute_term` | Name the attribute with `attribute_id` or `taxonomy: "pa_size"`. |
| `assign_attributes` | `variation: true` is the prerequisite for variations. |
| `generate_variations` | `limit` (default 50) and `force` for a big grid. Returns `remaining`. |
| `set_linked_products` | `cross_sell_ids`, `upsell_ids`, `children` (grouped only), `product_url` + `button_text` (external only). |
| `list_downloads` / `set_downloads` / `add_download` / `remove_download` | `file` is a URL or a path inside uploads. Also `download_limit`, `download_expiry`. |
| `set_image` | Sideloads from a URL. `image_role: featured\|gallery`. **Pass `alt`.** |
| `list_tags` / `create_tag` / `update_tag` / `delete_tag` | Delete needs `force` when the tag is in use. |
| `bulk_price_change` | `change: { field, mode, amount }`. `dry_run` supported. |
| `schedule_sale` / `clear_sale` | `sale: { mode: percent\|fixed\|price, amount, date_from, date_to }`. `dry_run` supported. |
| `duplicate_product` | Uses WooCommerce's own duplicator: variations copied, new SKU, draft status. |

### `nibwp/wcpro-import` (8 actions)

| Action | Notes |
|---|---|
| `list_files` | CSV/TXT files in `uploads/`, `uploads/imports/`, `uploads/nibwp-wcpro/`. |
| `validate_csv` | Dry run. Writes nothing. Per-row problems with line numbers. |
| `import_csv` | Needs `_preflight_token`. `offset` + `limit` + `job_id` for batches. |
| `import_rows` | Same columns, supplied inline. Max 500 per call. Needs `_preflight_token`. |
| `import_status` | Progress of a job, or all recent jobs. |
| `export_products` / `export_orders` / `export_customers` | Written to `uploads/nibwp-wcpro/`; response carries the URL. |

### `nibwp/wcpro-templates` (8 actions)

| Action | Notes |
|---|---|
| `template_status` | Read-only. Block theme or not, how many templates are customised, how many PHP overrides are stale. Start here. |
| `list_templates` | Every WooCommerce block template the site resolves, and **which of the four sources answered**. `template_type: wp_template\|wp_template_part`. |
| `get_template` | By `slug`, or by `template_id` when two sources share a slug. |
| `save_template` | Writes `<theme>/templates/<slug>.html`. Whole file only — read it, change it, send it back. `force` to write into a theme that is not a block theme, where it would never be read. |
| `revert_template` | Clears the site-editor customisation, as the editor's own Reset does. `delete_theme_file: true` also deletes the theme's `.html` copy. |
| `list_overrides` | The theme's PHP overrides, each with `theme_version` against `core_version` and a stale flag. `stale_only: true`. |
| `get_override` / `save_override` | `path` is relative to the theme's `woocommerce/` folder, e.g. `single-product/add-to-cart/variable.php`. `save_override` only writes a path where WooCommerce actually ships a template, so you can shadow a real template, not drop a new file into the theme. |

Everything here is confined to the **active** theme directory — not the parent,
because a change written into a parent theme is deleted by its next update.
Absolute paths, URLs and `../` escapes are refused. Writes need `edit_themes`
and are refused outright when the site defines `DISALLOW_FILE_EDIT`; that is the
site's own decision, so report it rather than working around it.

### `nibwp/wcpro-emails` (7 actions)

| Action | Notes |
|---|---|
| `list_emails` | Every registered email: `id`, `title`, `recipient`, `subject`, `heading`, `enabled`, `goes_to_customer`. `disabled_only: true` narrows it. |
| `get_email` | One in full: the stored subject and heading **and** WooCommerce's defaults for them, `additional_content`, `email_type`, the template paths, the option key. Settings beyond the ones this hub understands come back as **names only**. |
| `update_email` | `email: { enabled, recipient, subject, heading, additional_content }`. Only the keys you send change. |
| `list_disabled_emails` | Read first when a customer "heard nothing". Names each switched-off email and what the silence costs. |
| `send_test_email` | **Sends real mail** to `recipient`. Needs `manage_woocommerce`, the address must pass `is_email()`. Ask the user for the address. |
| `get_email_settings` / `update_email_settings` | Store-wide: `from_name`, `from_address`, `header_image`, `footer_text`, `base_color`, `background_color`, `body_background_color`, `text_color`. |

A **customer** email has no settable recipient — WooCommerce addresses it from
the order — so `update_email` refuses one rather than storing a value that would
never be used. An **admin** email takes a comma-separated list and every address
in it is validated, because WooCommerce stores the list verbatim and silently
drops what it cannot parse at send time.

Clearing a subject or heading does not send a blank one: WooCommerce falls back
to its own default. `get_email` reports the stored value and the default side by
side so you can say which is in force.

### `nibwp/wcpro-customers` (7 actions)

Everything here reads **personal data**. It needs `list_users` to get through the
door, and it then asks `current_user_can('edit_user', id)` about each customer —
so a response can legitimately hold fewer records than the store has. Every
response carries the count of records that were skipped. **Report that count.**
Presenting a partial list as the whole base is the one mistake this hub makes
easy, and the number is there so you do not make it.

| Action | Notes |
|---|---|
| `search_customers` | By `min_orders` / `max_orders`, `min_spend` / `max_spend`, `last_order_before` / `last_order_after`, `registered_before` / `registered_after`, `role`. `scan_limit` (default 200, max 2000) + `offset` walk a large base; the response says how many were scanned and whether the window was full. |
| `get_customer_profile` | One customer's whole picture: orders, lifetime value, average order, addresses, note count. Fields come from an **allow-list**, so nothing new WordPress or a plugin stores against a user can leak through it. |
| `segment_customers` | `segments: [{name, …same criteria…}]`, or left out for the lifecycle preset — `never_ordered`, `one_time`, `repeat`, `loyal`, `lapsed`. Counts are complete for the scanned window; `max_ids` (default 50) bounds the ids listed per segment. |
| `list_customer_notes` / `add_customer_note` | Notes against that customer's orders. `note_type` decides whether the customer is emailed — a customer note **sends mail**, so say which you are adding. |
| `find_guest_orders` | Guest orders sharing an email with a registered account. Read-only. Run it before the next one. |
| `link_guest_orders` | Attaches them to the account. `dry_run: true` first. `order_ids` limits it to exactly those; left out, **every** matching guest order is attached. This rewrites order history and is not undone by running it again. |

What this hub will **never** return, whatever is asked: a password hash, a
session token, an application password, or any `_wp_*` capability meta. That is
enforced by the allow-list rather than by stripping known-bad keys, so a field a
future WooCommerce version adds is excluded until someone adds it deliberately.

For creating or editing **one** customer, use the Free `wc-create-customer` /
`wc-update-customer`. This hub is the base, not the record.

### `nibwp/wcpro-analytics` (7 actions)

Every response carries **`source`**, and it is one of exactly two values:

| `source` | What answered | What that means |
|---|---|---|
| `analytics_tables` | WooCommerce's own report data stores — `wc_order_stats`, `wc_order_product_lookup`, `wc_order_coupon_lookup`, `wc_customer_lookup`. | The same figures wp-admin **Analytics** shows. A derived copy, filled by a scheduled import, so it can be **behind** the order book. |
| `live_query` | The order book, the user table or the product record, read in a bounded window. | Current. May differ from the Analytics screens, which read the tables above. |

**Quote the source with the number.** A store mid-import disagrees with itself, and
both figures look equally authoritative in JSON. That is the whole reason this hub
exists next to the Free `wc-get-reports`.

| Action | Notes |
|---|---|
| `revenue_summary` | Gross sales, net revenue, orders, items, AOV, tax, shipping, discounts, plus the **previous period of the same length** and the change in absolute and percentage terms. `compare:false` skips the comparison. `verify:true` cross-checks against the order book. |
| `refund_rate` | Refunds as a share of takings **and** as a share of orders, against the previous period. The order-level rate is only available from `live_query`; the response says so when it is not. |
| `top_sellers` | `orderby: "items_sold"` (default) or `"net_revenue"`. Different lists. `limit` default 10, max 50. Each row carries `product_id`. |
| `low_stock` | **Always `live_query`** — stock has no analytics table, so this figure is never stale. Two lists: at or below `threshold`, and out of stock. Counts come from their own capped query, so they are not the length of the sample list. Variations are not walked. |
| `coupon_performance` | Orders and discount per coupon, **plus the published coupons used zero times** (`include_unused`, default true), plus codes on orders whose coupon no longer exists. |
| `customer_acquisition` | New customers per bucket. `buckets` default 12, max 24. Counts **registrations**, not first orders — a guest-checkout store acquires customers who never appear here. |
| `store_health` | One call: revenue and direction, refund rate, out-of-stock and low-stock counts, the top seller, new customers. Each figure carries its **own** `source`; `mixed_sources: true` says they do not all agree. **Start here.** |

#### A period is resolved in the store's timezone, and the response says so

`period.resolved` is the range actually used, with the site timezone and the UTC
offset written out. Quote that, not the words the user asked with — "last 30 days"
resolved on a UTC server for a shop in Auckland is a different month.

- `last_7_days` / `last_30_days` / `last_90_days` / `last_365_days` are **whole days
  ending yesterday**. Today is left out on purpose: N−1 whole days plus a few hours
  compared against N whole days makes every period look worse than the one before it
  for no reason but the clock.
- `today`, `this_month`, `this_year` are the partial current period and are flagged
  `partial: true`.
- `yesterday` and `last_month` are whole.
- `custom` takes `date_from` / `date_to` as `YYYY-MM-DD`, read as 00:00:00 and
  23:59:59 in the site timezone. An end date in the future is clipped to now and the
  period is reported partial.

`period.comparison_basis` names how the previous period was chosen:
`previous_equal_length`, `previous_calendar_month`, `previous_month_to_date`,
`previous_year_to_date`, `yesterday_to_the_same_time`. A month-to-date is compared
against the same slice of last month, never against the whole of it.

Longest span: **730 days**. Past that the call is refused with `period_too_long`
rather than attempting a live scan that will not finish.

#### When the two sources disagree, both numbers are reported

`verify: true` on `revenue_summary` or `store_health` reads the same window a second
time from the order book. It is **forced on** when WooCommerce reports an analytics
import in progress, because that is exactly when the two differ.

```
wcpro-analytics revenue_summary { period: "last_30_days", verify: true }
  → source: "analytics_tables"
    disputed: true
    cross_check: { orders: { analytics_tables: 812, live_query: 847, agree: false },
                   net_revenue: { analytics_tables: "41230.00", live_query: "43980.55",
                                  difference: "-2750.55", tolerance: "439.81", agree: false },
                   verdict: "…DISAGREE… The usual cause is the analytics import
                             running behind the order book…" }
```

The hub **does not reconcile them.** It reports both, the gap, and `disputed: true`.
Order count is the decisive test — an integer with no sign convention and no
definition to argue about. Revenue is compared with a 1% tolerance, because the two
paths net a partly refunded order's tax and shipping slightly differently and a
strict compare would cry wolf on any store that has ever issued a refund.

If the live read filled its window, the comparison is `inconclusive: null` rather
than a disagreement: a partial count cannot contradict anything.

#### Bounds, and that they are floors

`limit` 50 · `stock_limit` 200 · `coupon_limit` 100 · `scan_limit` 2000 ·
`buckets` 24 · `threshold` 1000 · period span 730 days. None can be raised past its
ceiling from input. When a live read fills its window, `window_full: true` and every
figure in the response is a **floor** — report it as one, or narrow the period.

#### How this differs from the Free `wc-get-reports`

`wc-get-reports` is cheaper and fine when none of the below matters. It cannot
answer "is this number current?", because it never says where its number came from.

| | Free `wc-get-reports` | `wcpro-analytics` |
|---|---|---|
| Provenance | none | `source` on every response, one of two values |
| Analytics tables | never used | preferred where present, with fallback |
| Period | `gmdate()` off the server clock | resolved in the **site** timezone, range stated |
| Comparison period | none | previous period, with a named basis |
| Bound | `limit: -1` — every matching order | every query bounded, ceilings in the schema |
| Stock, coupons, refund rate, acquisition, health | none | yes |

## PART 4 — Import columns

`id`, `sku`, `name`, `type`, `status`, `featured`, `catalog_visibility`, `description`, `short_description`, `regular_price`, `sale_price`, `tax_status`, `tax_class`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `weight`, `length`, `width`, `height`, `categories`, `tags`, `images`, `virtual`, `downloadable`, `menu_order`.

`categories`, `tags` and `images` are comma-separated. Categories and tags are created if missing. The first image becomes the featured one.

`validate_csv` reports any other column as `unknown_columns` — ignored, not an error. WooCommerce's own importer accepts more than this list; when it is available it does the parsing and those extra columns work. The documented set above is what the fallback builder handles, and it is what `validate_csv` checks.

## PART 5 — Worked examples

### A new Dutch store, from nothing

```
1. wcpro-store  store_status
   → missing: base country, currency, tax rates, shipping, gateway, products

2. wcpro-store  update_general { settings: {
     address_1: "Keizersgracht 1", city: "Amsterdam", postcode: "1015 CJ",
     base_country: "NL", currency: "EUR", currency_position: "left_space",
     selling_locations: "specific", selling_countries: ["NL","BE","DE"],
     shipping_locations: "specific", shipping_countries: ["NL","BE"],
     enable_coupons: true, enable_taxes: true } }

3. wcpro-store  update_tax_settings { settings: {
     prices_include_tax: true, tax_based_on: "shipping",
     display_in_shop: "incl", display_in_cart: "incl", total_display: "itemized" } }

4. wcpro-store  create_tax_class { name: "Reduced rate" }         → slug reduced-rate
5. wcpro-store  create_tax_rate  { rate: { country: "NL", rate: "21.0000", name: "BTW 21%" } }
6. wcpro-store  create_tax_rate  { tax_class: "reduced-rate",
                                   rate: { country: "NL", rate: "9.0000", name: "BTW 9%" } }

7. wcpro-store  create_shipping_zone { zone: { name: "Netherlands",
                                               locations: [{ code: "NL", type: "country" }] } }   → zone_id 1
8. wcpro-store  add_shipping_method  { zone_id: 1, method_type: "flat_rate",
                                       method_settings: { title: "PostNL", cost: "4.95", tax_status: "taxable" } }
9. wcpro-store  add_shipping_method  { zone_id: 1, method_type: "free_shipping",
                                       method_settings: { title: "Free over €50", requires: "min_amount", min_amount: "50" } }

10. wcpro-store enable_payment_gateway    { gateway_id: "bacs" }
11. wcpro-store configure_payment_gateway { gateway_id: "bacs",
                                            gateway_settings: { title: "Bank transfer" } }

12. wcpro-store store_status  → nothing missing but products
```

### A variable t-shirt

```
1. wc-create-product (Free)  { name: "Studio Tee", type: "variable", status: "draft" }   → 512
2. wcpro-catalog create_attribute      { attribute: { name: "Size" } }         → pa_size
3. wcpro-catalog create_attribute_term { taxonomy: "pa_size", term: { name: "S" } }   (×S, M, L)
4. wcpro-catalog create_attribute      { attribute: { name: "Colour" } }       → pa_colour
5. wcpro-catalog create_attribute_term { taxonomy: "pa_colour", term: { name: "Black" } }  (×Black, Sand)
6. wcpro-catalog assign_attributes     { product_id: 512, attributes: [
     { name: "pa_size",   options: ["S","M","L"],     variation: true },
     { name: "pa_colour", options: ["Black","Sand"],  variation: true } ] }
7. wcpro-catalog generate_variations   { product_id: 512 }      → 6 variations, no prices
8. wcpro-catalog bulk_price_change     { filter: { ids: [512], include_variations: true },
                                         change: { field: "regular_price", mode: "set_fixed", amount: 39 },
                                         dry_run: true }
9. …same with dry_run: false
10. wcpro-catalog set_image            { product_id: 512, image_url: "https://…/tee.jpg",
                                         alt: "Studio Tee in black, front view" }
11. wc-update-product (Free)           { product_id: 512, status: "publish" }
```

### A 1,840-row catalogue

```
1. wcpro-import list_files                                 → imports/products.csv, 1.4 MB
2. wcpro-import validate_csv { path: "imports/products.csv" }
   → 3 errors on lines 14, 220, 907; 11 warnings; unknown_columns: ["supplier_ref"]
3. (tell the user the three lines and what is wrong; they fix the file)
4. wcpro-import validate_csv { path: "imports/products.csv" }   → clean
5. wcpro-import import_csv   { path: "imports/products.csv", limit: 200, _preflight_token: "…" }
   → job_id wcpro_ab12…, next_offset 200
6. wcpro-import import_csv   { path: "imports/products.csv", job_id: "wcpro_ab12…", limit: 200, _preflight_token: "…" }
   → repeat until next_offset is null
7. wc-list-products (Free)   { per_page: 5 }               → check five of them really landed
```

### "How is the shop doing?"

```
1. wcpro-analytics store_health { period: "last_30_days" }
   → period.resolved: "2026-08-31 00:00:00 to 2026-09-29 23:59:59 in the site
                       timezone Europe/Amsterdam (UTC+02:00). 30 whole days ending
                       yesterday; today is left out so the comparison is like for like."
     source: "analytics_tables"   mixed_sources: true
     revenue: net 41,230.00 from 812 orders, up 8.4% on the previous 30 days
     refunds: 3.1% of takings   stock: 4 out of stock, 11 low
     top_seller: Studio Tee (source: analytics_tables)
     customers: 96 new registrations (source: analytics_tables)
     next_steps: ["There is at least one product out of stock…",
                  "The figures in this summary came from more than one source…"]

2. (tell the user the range, not "last 30 days", and say which numbers are
    analytics-table figures)

3. wcpro-analytics low_stock                      → the four to reorder
4. wcpro-analytics revenue_summary { period: "last_30_days", verify: true }
   → if disputed:true, report BOTH numbers and that the import is behind
```

### A Black Friday sale on one category

```
1. wcpro-catalog schedule_sale { filter: { category: "outerwear", include_variations: true },
                                 sale: { mode: "percent", amount: 25,
                                         date_from: "2026-11-27", date_to: "2026-12-01" },
                                 dry_run: true }
   → 38 products would go on sale, 4 skipped (no regular price)
2. …same with dry_run: false
3. after the sale:  wcpro-catalog clear_sale { filter: { category: "outerwear" } }
```

## PART 6 — Refusals you will meet, and what each means

| Code | Means |
|---|---|
| `wc_not_active` | WooCommerce is not active. Say so; do not work around it. |
| `nibwp_forbidden` | The connected account may not do this to **this object**. Report it; do not retry with different arguments. |
| `unknown_tax_class` | Create the class first. |
| `not_variable` | Change the product type with `wc-update-product`. |
| `no_variation_attributes` | Call `assign_attributes` with `variation: true` first. |
| `too_many_variations` | Pass a smaller `limit` and loop, or `force: true`. |
| `filter_required` | A bulk action was given no filter. Name the products. |
| `invalid_topic` | The webhook topic is not one WooCommerce knows. `resource.event` or `action.<hook>`. |
| `tax_class_in_use` / `attribute_in_use` / `term_in_use` / `zone_in_use` | Something still depends on it. `force: true` if that is really intended. |
| `file_not_found` / `bad_extension` | The path is relative to uploads and must be .csv or .txt. `list_files` shows what is there. |
| `job_mismatch` | The `job_id` belongs to a different file. |
| `period_too_long` | The period covers more than 730 days. Ask for a shorter range and read the years one at a time. |
| `impossible_period` | The period starts after it ends. |
| `missing_dates` | `period: "custom"` needs `date_from` and `date_to`. |
| `order_query_failed` | The live order query did not complete. Report it; do not retry with a bigger `scan_limit`. |

## PART 7 — Before you say it is done

- [ ] `store_status` was called **first**, and the answers came from it rather than from the user.
- [ ] Taxes: the class exists before the rate that names it; `enable_taxes` is on if rates were created.
- [ ] Shipping: every zone that matters has at least one method, **including zone 0** if anything ships outside the named zones.
- [ ] Payments: at least one gateway is enabled, and its title is something a customer would recognise.
- [ ] Variations: `assign_attributes` used `variation: true`, and every generated variation has a price.
- [ ] Images: every `set_image` call carried `alt`.
- [ ] Import: `validate_csv` came back clean before `import_csv`, and `next_offset` reached `null`.
- [ ] Exports: the user has the URL, and you told them a customer or order export is personal data in a web-readable folder.
- [ ] Figures: every number you quoted came with its `source`, and you said the resolved date range from `period.resolved` rather than "last 30 days".
- [ ] Figures: if `disputed: true` or `window_full: true` came back, the user was told — a floor was called a floor and a disagreement was not reconciled for them.
- [ ] You re-read a few of the records you wrote — with the Free `wc-get-product` / `wc-list-products` — rather than trusting the success response.
