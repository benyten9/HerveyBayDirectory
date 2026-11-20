<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-catalog.
 *
 * The catalogue work the Free wc-* abilities do not reach. They create, read and
 * update a product, and they create one variation at a time. They cannot create
 * the global attribute a variable product needs before any variation can exist,
 * cannot generate the grid of variations from it, and have nothing for linked
 * products, downloadable files, tags, bulk pricing or scheduled sales.
 *
 * Authorisation here is per product, not per role: a handler that touches
 * product 42 asks current_user_can('edit_post', 42), which WordPress maps
 * through the post type, the author and the status. Global attributes and tags
 * are taxonomy terms, so they ask the taxonomy's own capabilities.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';

/**
 * The action enum. Single source of truth — the registration and
 * tests/skills/woocommerce-pro-check.php both read this, and the switch in
 * nibwp_wcpro_catalog_execute() must match it exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_catalog_actions(): array
{
    return [
        // Global attributes and their terms.
        'list_attributes',
        'create_attribute',
        'update_attribute',
        'delete_attribute',
        'list_attribute_terms',
        'create_attribute_term',
        'update_attribute_term',
        'delete_attribute_term',
        // Attributes on a product, and the variations they make possible.
        'assign_attributes',
        'generate_variations',
        // Relationships.
        'set_linked_products',
        // Downloadable files.
        'list_downloads',
        'set_downloads',
        'add_download',
        'remove_download',
        // Media.
        'set_image',
        // Tags.
        'list_tags',
        'create_tag',
        'update_tag',
        'delete_tag',
        // Pricing.
        'bulk_price_change',
        'schedule_sale',
        'clear_sale',
        // Copying.
        'duplicate_product',
    ];
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-catalog',
    __('WooCommerce Pro — deep catalogue work', 'nibwp'),
    'The product work the Free WooCommerce abilities do not cover: global product attributes and their terms, assigning attributes to a product, generating every variation of a variable product, cross-sells and upsells, grouped children, external product links, downloadable files, setting a product image from a URL, product tags, bulk price changes across a filtered set, scheduling and clearing sale prices, and duplicating a product. For a single product, variation, order, coupon or customer use the Free nibwp/wc-* abilities instead.',
    nibwp_wcpro_catalog_actions(),
    [
        'product_id' => ['type' => 'integer', 'description' => 'The product to act on. Most actions need it.'],
        'sku' => ['type' => 'string', 'description' => 'Resolve the product by SKU instead of id.'],
        'attribute_id' => ['type' => 'integer', 'description' => 'Global attribute id, for update_attribute / delete_attribute and the term actions.'],
        'attribute' => [
            'type' => 'object',
            'description' => 'Global attribute fields: name, slug, type (select|text), order_by (menu_order|name|name_num|id), has_archives.',
        ],
        'term_id' => ['type' => 'integer', 'description' => 'Term id, for update_attribute_term / delete_attribute_term / update_tag / delete_tag.'],
        'term' => ['type' => 'object', 'description' => 'Term fields: name, slug, description, parent (tags only).'],
        'attributes' => [
            'type' => 'array',
            'description' => 'For assign_attributes: [{name, options:[…], variation:true|false, visible:true|false, position:0}]. A global attribute is named by its taxonomy ("pa_size") or its slug ("size"); anything else becomes a per-product custom attribute. variation:true is REQUIRED before generate_variations can build anything from it.',
            'items' => ['type' => 'object'],
        ],
        'links' => [
            'type' => 'object',
            'description' => 'For set_linked_products: {cross_sell_ids:[…], upsell_ids:[…], children:[…] (grouped), product_url:"…", button_text:"…" (external)}.',
        ],
        'downloads' => [
            'type' => 'array',
            'description' => 'For set_downloads / add_download: [{name, file}]. file is a URL or a path inside the uploads directory.',
            'items' => ['type' => 'object'],
        ],
        'download_id' => ['type' => 'string', 'description' => 'For remove_download: the download id list_downloads returned.'],
        'download_limit' => ['type' => 'integer', 'description' => 'Downloads allowed per purchase. -1 is unlimited.'],
        'download_expiry' => ['type' => 'integer', 'description' => 'Days the download link stays valid. -1 never expires.'],
        'image_url' => ['type' => 'string', 'description' => 'For set_image: an http(s) URL to sideload into the media library.'],
        'image_role' => ['type' => 'string', 'enum' => ['featured', 'gallery'], 'default' => 'featured'],
        'alt' => ['type' => 'string', 'description' => 'Alt text for the sideloaded image. Always provide it.'],
        'filter' => [
            'type' => 'object',
            'description' => 'For bulk_price_change / schedule_sale / clear_sale: which products. {ids:[…], category:"slug", tag:"slug", type:"simple|variable|…", status:"publish", include_variations:true, limit:200}. Without a filter nothing is touched.',
        ],
        'change' => [
            'type' => 'object',
            'description' => 'For bulk_price_change: {field:"regular_price|sale_price", mode:"increase_percent|decrease_percent|increase_fixed|decrease_fixed|set_fixed", amount:10}.',
        ],
        'sale' => [
            'type' => 'object',
            'description' => 'For schedule_sale: {mode:"percent|fixed|price", amount:20, date_from:"2026-11-27", date_to:"2026-12-02"}.',
        ],
        'name' => ['type' => 'string', 'description' => 'Attribute name, term name, or tag name.'],
        'slug' => ['type' => 'string'],
        'taxonomy' => ['type' => 'string', 'description' => 'Attribute taxonomy for the term actions, e.g. "pa_size". Given instead of attribute_id if you prefer.'],
        'search' => ['type' => 'string', 'description' => 'Filter for list_tags / list_attribute_terms.'],
        'per_page' => ['type' => 'integer', 'default' => 50],
        'page' => ['type' => 'integer', 'default' => 1],
        'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'For bulk_price_change / schedule_sale / clear_sale: report what would change and write nothing.'],
        'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Required to delete an attribute that still has terms, or to generate more than 100 variations at once.'],
        'limit' => ['type' => 'integer', 'description' => 'For generate_variations: how many to create in this call. Default 50; call again to continue.'],
    ],
    'nibwp_wcpro_catalog_execute',
    implode("\n", [
        'A variable product is built in this order, and skipping a step leaves variations that exist but cannot be bought:',
        '  1. create_attribute { attribute:{name:"Size"} } — a GLOBAL attribute, once per store.',
        '  2. create_attribute_term per value — Small, Medium, Large.',
        '  3. assign_attributes { product_id, attributes:[{name:"pa_size", options:["small","medium"], variation:true}] } — variation:true is what makes variations possible. Without it the next step has nothing to combine and WooCommerce says nothing about it.',
        '  4. generate_variations { product_id } — builds every missing combination. Prices are empty; set them with the Free nibwp/wc-update-variation, or with bulk_price_change.',
        'bulk_price_change, schedule_sale and clear_sale all accept dry_run:true. Use it: they walk a filtered set and a wrong filter reprices the catalogue.',
        'set_image sideloads from a URL into the media library. Pass alt text — an image with none is a accessibility defect this skill will warn about but not invent copy for.',
        'For one product, one variation, one order, one coupon or one customer, the Free nibwp/wc-* abilities own it. Do not route here.',
    ]),
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_catalog_execute(array $input): array|WP_Error
{
    $guard = nibwp_wcpro_guard($input, false);
    if (is_wp_error($guard)) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'list_attributes':
            return nibwp_wcpro_list_attributes();

        case 'create_attribute':
            return nibwp_wcpro_write_attribute($input, 0);

        case 'update_attribute':
            return nibwp_wcpro_write_attribute($input, (int) ($input['attribute_id'] ?? 0));

        case 'delete_attribute':
            return nibwp_wcpro_delete_attribute($input);

        case 'list_attribute_terms':
            return nibwp_wcpro_list_attribute_terms($input);

        case 'create_attribute_term':
            return nibwp_wcpro_write_attribute_term($input, 0);

        case 'update_attribute_term':
            return nibwp_wcpro_write_attribute_term($input, (int) ($input['term_id'] ?? 0));

        case 'delete_attribute_term':
            return nibwp_wcpro_delete_attribute_term($input);

        case 'assign_attributes':
            return nibwp_wcpro_assign_attributes($input);

        case 'generate_variations':
            return nibwp_wcpro_generate_variations($input);

        case 'set_linked_products':
            return nibwp_wcpro_set_linked_products($input);

        case 'list_downloads':
            return nibwp_wcpro_list_downloads($input);

        case 'set_downloads':
            return nibwp_wcpro_write_downloads($input, false);

        case 'add_download':
            return nibwp_wcpro_write_downloads($input, true);

        case 'remove_download':
            return nibwp_wcpro_remove_download($input);

        case 'set_image':
            return nibwp_wcpro_set_image($input);

        case 'list_tags':
            return nibwp_wcpro_list_tags($input);

        case 'create_tag':
            return nibwp_wcpro_write_tag($input, 0);

        case 'update_tag':
            return nibwp_wcpro_write_tag($input, (int) ($input['term_id'] ?? 0));

        case 'delete_tag':
            return nibwp_wcpro_delete_tag($input);

        case 'bulk_price_change':
            return nibwp_wcpro_bulk_price_change($input);

        case 'schedule_sale':
            return nibwp_wcpro_schedule_sale($input);

        case 'clear_sale':
            return nibwp_wcpro_clear_sale($input);

        case 'duplicate_product':
            return nibwp_wcpro_duplicate_product($input);
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_catalog_actions());
}

/* ----------------------------------------------------------------- helpers */

/**
 * Resolve a product by id or SKU and check the connected account may act on it.
 *
 * The capability question is asked about THIS product, not about products in
 * general: current_user_can('edit_post', $id) maps through the product's type,
 * author and status, so a shop manager gets the right answer and a contributor
 * does not reach somebody else's catalogue.
 *
 * @return WC_Product|WP_Error
 */
function nibwp_wcpro_product(array $input, string $cap = 'edit_post'): WC_Product|WP_Error
{
    $id = (int) ($input['product_id'] ?? 0);
    $sku = trim((string) ($input['sku'] ?? ''));

    if ($id <= 0 && $sku === '') {
        return nibwp_wcpro_err('missing_product_ref', 'Provide either product_id or sku.', 422);
    }
    if ($id <= 0) {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return nibwp_wcpro_err('wc_function_missing', 'wc_get_product_id_by_sku() is not available on this site.', 409);
        }
        $id = (int) wc_get_product_id_by_sku($sku);
        if ($id <= 0) {
            return nibwp_wcpro_err('sku_not_found', sprintf('No product with SKU "%s".', $sku), 404);
        }
    }

    // Authorisation before the read, so a refusal tells the caller nothing about
    // whether the product exists.
    $denied = nibwp_wcpro_require_post_cap($cap, $id, 'product');
    if ($denied !== null) {
        return $denied;
    }

    $product = wc_get_product($id);
    if (!$product instanceof WC_Product) {
        return nibwp_wcpro_err('product_not_found', sprintf('Product %d not found.', $id), 404);
    }

    return $product;
}

/**
 * The attribute taxonomy for a caller-supplied name.
 *
 * Accepts "pa_size", "size" or "Size" — all three turn up — and answers with the
 * taxonomy WooCommerce stores, or '' when this is a per-product custom attribute
 * rather than a global one.
 */
function nibwp_wcpro_attribute_taxonomy(string $name): string
{
    if (!function_exists('wc_attribute_taxonomy_name') || !function_exists('wc_attribute_taxonomy_id_by_name')) {
        return '';
    }

    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $candidate = str_starts_with($name, 'pa_') ? $name : wc_attribute_taxonomy_name(sanitize_title($name));
    $slug = preg_replace('/^pa_/', '', $candidate) ?? $candidate;

    return (int) wc_attribute_taxonomy_id_by_name((string) $slug) > 0 ? (string) $candidate : '';
}

/**
 * Make sure an attribute taxonomy is registered in THIS request.
 *
 * wc_create_attribute() writes the row, but the taxonomy is registered on
 * `init`, which already ran. Without this, the very next call —
 * wp_insert_term() on the attribute we just created — fails with
 * invalid_taxonomy, and the agent concludes the attribute was not created.
 */
function nibwp_wcpro_register_attribute_taxonomy(string $taxonomy): bool
{
    if ($taxonomy === '') {
        return false;
    }
    if (taxonomy_exists($taxonomy)) {
        return true;
    }

    register_taxonomy(
        $taxonomy,
        ['product', 'product_variation'],
        [
            'hierarchical' => false,
            'show_ui'      => false,
            'query_var'    => false,
            'rewrite'      => false,
            'public'       => false,
            'labels'       => ['name' => $taxonomy],
        ],
    );

    return taxonomy_exists($taxonomy);
}

/* -------------------------------------------------------- global attributes */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_attributes(): array|WP_Error
{
    $denied = nibwp_wcpro_require_function('wc_get_attribute_taxonomies', 'Listing global attributes');
    if ($denied !== null) {
        return $denied;
    }

    $items = [];
    foreach ((array) wc_get_attribute_taxonomies() as $attribute) {
        $slug = (string) ($attribute->attribute_name ?? '');
        $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($slug) : 'pa_' . $slug;
        nibwp_wcpro_register_attribute_taxonomy($taxonomy);
        $items[] = [
            'attribute_id' => (int) ($attribute->attribute_id ?? 0),
            'name'         => (string) ($attribute->attribute_label ?? $slug),
            'slug'         => $slug,
            'taxonomy'     => $taxonomy,
            'type'         => (string) ($attribute->attribute_type ?? 'select'),
            'order_by'     => (string) ($attribute->attribute_orderby ?? 'menu_order'),
            'has_archives' => (bool) ($attribute->attribute_public ?? false),
            'term_count'   => taxonomy_exists($taxonomy) ? (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]) : 0,
        ];
    }

    return nibwp_wcpro_ok('list_attributes', sprintf('%d global attribute(s).', count($items)), ['items' => $items, 'count' => count($items)]);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_write_attribute(array $input, int $attribute_id): array|WP_Error
{
    $creating = $attribute_id <= 0;
    $denied = nibwp_wcpro_require_function($creating ? 'wc_create_attribute' : 'wc_update_attribute', 'Writing a global attribute');
    if ($denied !== null) {
        return $denied;
    }
    // A global attribute is a taxonomy for the whole store, so it is gated the
    // way WooCommerce gates the Attributes screen.
    $denied = nibwp_wcpro_require_manage('Creating or changing a global product attribute');
    if ($denied !== null) {
        return $denied;
    }

    $fields = (array) ($input['attribute'] ?? []);
    $name = nibwp_wcpro_text($fields['name'] ?? $input['name'] ?? '', 100);
    if ($creating && $name === '') {
        return nibwp_wcpro_err('missing_name', 'Provide a "name" for the attribute, e.g. "Size".', 422);
    }

    $args = [];
    if ($name !== '') {
        $args['name'] = $name;
    }
    $slug = nibwp_wcpro_text($fields['slug'] ?? $input['slug'] ?? '', 60);
    if ($slug !== '') {
        $args['slug'] = sanitize_title($slug);
    }
    if (array_key_exists('type', $fields)) {
        $type = nibwp_wcpro_enum($fields['type'], array_keys(function_exists('wc_get_attribute_types') ? (array) wc_get_attribute_types() : ['select' => '', 'text' => '']), 'attribute.type');
        if (is_wp_error($type)) {
            return $type;
        }
        $args['type'] = $type;
    }
    if (array_key_exists('order_by', $fields)) {
        $order_by = nibwp_wcpro_enum($fields['order_by'], ['menu_order', 'name', 'name_num', 'id'], 'attribute.order_by');
        if (is_wp_error($order_by)) {
            return $order_by;
        }
        $args['order_by'] = $order_by;
    }
    if (array_key_exists('has_archives', $fields)) {
        $args['has_archives'] = nibwp_wcpro_bool($fields['has_archives']);
    }
    if ($args === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide an "attribute" object with at least one field.', 422);
    }

    $result = $creating ? wc_create_attribute($args) : wc_update_attribute($attribute_id, $args);
    if (is_wp_error($result)) {
        return $result;
    }
    $attribute_id = $creating ? (int) $result : $attribute_id;

    $taxonomy = function_exists('wc_attribute_taxonomy_name_by_id') ? (string) wc_attribute_taxonomy_name_by_id($attribute_id) : '';
    nibwp_wcpro_register_attribute_taxonomy($taxonomy);

    return nibwp_wcpro_ok(
        $creating ? 'create_attribute' : 'update_attribute',
        sprintf('Global attribute %d (%s) %s.', $attribute_id, $taxonomy, $creating ? 'created' : 'updated'),
        [
            'result' => ['attribute_id' => $attribute_id, 'taxonomy' => $taxonomy, 'name' => $name],
            'next_steps' => $creating
                ? [
                    sprintf('Add its values with action:"create_attribute_term", taxonomy:"%s".', $taxonomy),
                    'Then assign_attributes on the product with variation:true, and only then generate_variations.',
                ]
                : [],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_attribute(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_function('wc_delete_attribute', 'Deleting a global attribute');
    if ($denied !== null) {
        return $denied;
    }
    $denied = nibwp_wcpro_require_manage('Deleting a global product attribute');
    if ($denied !== null) {
        return $denied;
    }

    $attribute_id = (int) ($input['attribute_id'] ?? 0);
    if ($attribute_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "attribute_id" to delete. action:"list_attributes" returns it.', 422);
    }

    $taxonomy = function_exists('wc_attribute_taxonomy_name_by_id') ? (string) wc_attribute_taxonomy_name_by_id($attribute_id) : '';
    nibwp_wcpro_register_attribute_taxonomy($taxonomy);
    $terms = $taxonomy !== '' && taxonomy_exists($taxonomy)
        ? (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false])
        : 0;

    // Deleting the attribute deletes its terms, and any variation keyed on one of
    // them stops matching. That is worth a deliberate second call.
    if ($terms > 0 && !nibwp_wcpro_bool($input['force'] ?? false)) {
        return nibwp_wcpro_err(
            'attribute_in_use',
            sprintf('Attribute %d (%s) still has %d term(s). Deleting it deletes them, and variations using them stop matching. Pass force:true to go ahead.', $attribute_id, $taxonomy, $terms),
            409,
            ['term_count' => $terms],
        );
    }

    if (!wc_delete_attribute($attribute_id)) {
        return nibwp_wcpro_err('not_found', sprintf('No global attribute with id %d.', $attribute_id), 404);
    }

    return nibwp_wcpro_ok('delete_attribute', sprintf('Global attribute %d (%s) deleted.', $attribute_id, $taxonomy), [
        'result' => ['attribute_id' => $attribute_id, 'taxonomy' => $taxonomy, 'terms_deleted' => $terms],
    ]);
}

/**
 * Resolve the attribute taxonomy a term action is about, from attribute_id,
 * taxonomy or name — whichever the caller gave.
 *
 * @return string|WP_Error
 */
function nibwp_wcpro_term_taxonomy(array $input): string|WP_Error
{
    $taxonomy = '';
    $attribute_id = (int) ($input['attribute_id'] ?? 0);
    if ($attribute_id > 0 && function_exists('wc_attribute_taxonomy_name_by_id')) {
        $taxonomy = (string) wc_attribute_taxonomy_name_by_id($attribute_id);
    }
    if ($taxonomy === '') {
        $taxonomy = nibwp_wcpro_attribute_taxonomy((string) ($input['taxonomy'] ?? $input['name'] ?? ''));
    }
    if ($taxonomy === '') {
        return nibwp_wcpro_err(
            'unknown_attribute',
            'Name the attribute with attribute_id, or taxonomy:"pa_size". action:"list_attributes" returns both.',
            422,
        );
    }
    if (!nibwp_wcpro_register_attribute_taxonomy($taxonomy)) {
        return nibwp_wcpro_err('unknown_taxonomy', sprintf('The attribute taxonomy "%s" could not be registered.', $taxonomy), 409);
    }

    return $taxonomy;
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_attribute_terms(array $input): array|WP_Error
{
    $taxonomy = nibwp_wcpro_term_taxonomy($input);
    if (is_wp_error($taxonomy)) {
        return $taxonomy;
    }
    $denied = nibwp_wcpro_require_term_cap('assign_terms', $taxonomy, 0, 'attribute terms');
    if ($denied !== null) {
        return $denied;
    }

    $page = nibwp_wcpro_paginate($input, 100, 500);
    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => $page['per_page'],
        'offset'     => $page['offset'],
        'search'     => nibwp_wcpro_text($input['search'] ?? ''),
    ]);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $items = [];
    foreach ((array) $terms as $term) {
        $items[] = ['term_id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'count' => (int) $term->count];
    }

    return nibwp_wcpro_ok('list_attribute_terms', sprintf('%d term(s) in %s.', count($items), $taxonomy), [
        'items' => $items,
        'count' => count($items),
        'result' => ['taxonomy' => $taxonomy],
    ]);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_write_attribute_term(array $input, int $term_id): array|WP_Error
{
    $taxonomy = nibwp_wcpro_term_taxonomy($input);
    if (is_wp_error($taxonomy)) {
        return $taxonomy;
    }

    return nibwp_wcpro_write_term($input, $taxonomy, $term_id, $term_id > 0 ? 'update_attribute_term' : 'create_attribute_term');
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_attribute_term(array $input): array|WP_Error
{
    $taxonomy = nibwp_wcpro_term_taxonomy($input);
    if (is_wp_error($taxonomy)) {
        return $taxonomy;
    }

    return nibwp_wcpro_delete_term_in($input, $taxonomy, 'delete_attribute_term');
}

/* -------------------------------------------------------- terms, generically */

/**
 * Create or update one term of a taxonomy.
 *
 * Shared by the attribute terms and the product tags: the only difference
 * between them is which taxonomy, and which capability set it carries.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_write_term(array $input, string $taxonomy, int $term_id, string $action): array|WP_Error
{
    $creating = $term_id <= 0;
    $denied = nibwp_wcpro_require_term_cap($creating ? 'edit_terms' : 'edit_terms', $taxonomy, $term_id, 'a term');
    if ($denied !== null) {
        return $denied;
    }

    $fields = (array) ($input['term'] ?? []);
    $name = nibwp_wcpro_text($fields['name'] ?? $input['name'] ?? '', 200);
    if ($creating && $name === '') {
        return nibwp_wcpro_err('missing_name', 'Provide a "name" for the term.', 422);
    }

    $args = [];
    $slug = nibwp_wcpro_text($fields['slug'] ?? $input['slug'] ?? '', 200);
    if ($slug !== '') {
        $args['slug'] = sanitize_title($slug);
    }
    if (array_key_exists('description', $fields)) {
        $args['description'] = wp_kses_post((string) $fields['description']);
    }
    if (array_key_exists('parent', $fields) && is_taxonomy_hierarchical($taxonomy)) {
        $args['parent'] = max(0, (int) $fields['parent']);
    }

    if ($creating) {
        $result = wp_insert_term($name, $taxonomy, $args);
    } else {
        $existing = get_term($term_id, $taxonomy);
        if (!$existing instanceof WP_Term) {
            return nibwp_wcpro_err('not_found', sprintf('No term %d in %s.', $term_id, $taxonomy), 404);
        }
        if ($name !== '') {
            $args['name'] = $name;
        }
        if ($args === []) {
            return nibwp_wcpro_err('nothing_to_do', 'Provide a "term" object with at least one field to change.', 422);
        }
        $result = wp_update_term($term_id, $taxonomy, $args);
    }
    if (is_wp_error($result)) {
        return $result;
    }

    $term_id = (int) ($result['term_id'] ?? 0);
    $term = get_term($term_id, $taxonomy);

    return nibwp_wcpro_ok(
        $action,
        sprintf('Term %d in %s %s.', $term_id, $taxonomy, $creating ? 'created' : 'updated'),
        [
            'result' => [
                'term_id'  => $term_id,
                'taxonomy' => $taxonomy,
                'name'     => $term instanceof WP_Term ? (string) $term->name : $name,
                'slug'     => $term instanceof WP_Term ? (string) $term->slug : '',
            ],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_term_in(array $input, string $taxonomy, string $action): array|WP_Error
{
    $term_id = (int) ($input['term_id'] ?? 0);
    if ($term_id <= 0) {
        return nibwp_wcpro_err('missing_id', 'Provide the "term_id" to delete.', 422);
    }
    $denied = nibwp_wcpro_require_term_cap('delete_terms', $taxonomy, $term_id, 'a term');
    if ($denied !== null) {
        return $denied;
    }

    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return nibwp_wcpro_err('not_found', sprintf('No term %d in %s.', $term_id, $taxonomy), 404);
    }
    if ((int) $term->count > 0 && !nibwp_wcpro_bool($input['force'] ?? false)) {
        return nibwp_wcpro_err(
            'term_in_use',
            sprintf('"%s" is still on %d product(s). Pass force:true to delete it anyway.', $term->name, (int) $term->count),
            409,
            ['product_count' => (int) $term->count],
        );
    }

    $deleted = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($deleted)) {
        return $deleted;
    }

    return nibwp_wcpro_ok($action, sprintf('Term %d ("%s") deleted from %s.', $term_id, (string) $term->name, $taxonomy), [
        'result' => ['term_id' => $term_id, 'taxonomy' => $taxonomy, 'name' => (string) $term->name],
    ]);
}

/* ----------------------------------------------------------- product tags */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_tags(array $input): array|WP_Error
{
    if (!taxonomy_exists('product_tag')) {
        return nibwp_wcpro_err('unknown_taxonomy', 'The product_tag taxonomy is not registered, so WooCommerce is not fully loaded.', 409);
    }
    $denied = nibwp_wcpro_require_term_cap('assign_terms', 'product_tag', 0, 'product tags');
    if ($denied !== null) {
        return $denied;
    }

    $page = nibwp_wcpro_paginate($input, 50, 500);
    $terms = get_terms([
        'taxonomy'   => 'product_tag',
        'hide_empty' => false,
        'number'     => $page['per_page'],
        'offset'     => $page['offset'],
        'search'     => nibwp_wcpro_text($input['search'] ?? ''),
    ]);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $items = [];
    foreach ((array) $terms as $term) {
        $items[] = ['term_id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'count' => (int) $term->count];
    }

    return nibwp_wcpro_ok('list_tags', sprintf('%d product tag(s).', count($items)), ['items' => $items, 'count' => count($items)]);
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_write_tag(array $input, int $term_id): array|WP_Error
{
    if (!taxonomy_exists('product_tag')) {
        return nibwp_wcpro_err('unknown_taxonomy', 'The product_tag taxonomy is not registered, so WooCommerce is not fully loaded.', 409);
    }

    return nibwp_wcpro_write_term($input, 'product_tag', $term_id, $term_id > 0 ? 'update_tag' : 'create_tag');
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_delete_tag(array $input): array|WP_Error
{
    if (!taxonomy_exists('product_tag')) {
        return nibwp_wcpro_err('unknown_taxonomy', 'The product_tag taxonomy is not registered, so WooCommerce is not fully loaded.', 409);
    }

    return nibwp_wcpro_delete_term_in($input, 'product_tag', 'delete_tag');
}

/* ------------------------------------------------- attributes on a product */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_assign_attributes(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Product_Attribute', 'Assigning attributes to a product');
    if ($denied !== null) {
        return $denied;
    }
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }

    $given = (array) ($input['attributes'] ?? []);
    if ($given === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide an "attributes" array: [{name, options:[…], variation:true}].', 422);
    }

    $attributes = [];
    $warnings = [];
    $position = 0;
    foreach ($given as $entry) {
        $entry = (array) $entry;
        $name = nibwp_wcpro_text($entry['name'] ?? '', 200);
        if ($name === '') {
            return nibwp_wcpro_err('invalid_attribute', 'Every attribute needs a "name".', 422);
        }
        $options = is_array($entry['options'] ?? null) ? $entry['options'] : array_filter(array_map('trim', explode('|', (string) ($entry['options'] ?? ''))));
        if ($options === []) {
            return nibwp_wcpro_err('invalid_attribute', sprintf('Attribute "%s" has no options, so nothing would be assigned.', $name), 422);
        }

        $attribute = new WC_Product_Attribute();
        $taxonomy = nibwp_wcpro_attribute_taxonomy($name);

        if ($taxonomy !== '') {
            nibwp_wcpro_register_attribute_taxonomy($taxonomy);
            $resolved = nibwp_wcpro_resolve_attribute_terms($taxonomy, $options);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $attribute->set_id((int) wc_attribute_taxonomy_id_by_name(preg_replace('/^pa_/', '', $taxonomy) ?? $taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options($resolved);
        } else {
            // A name that matches no global attribute becomes a per-product
            // custom attribute. Say so, because a typo in "pa_size" lands here
            // and then generate_variations cannot use it.
            $warnings[] = sprintf('"%s" matched no global attribute, so it was stored as a per-product custom attribute. Create the global one first if you meant that.', $name);
            $attribute->set_id(0);
            $attribute->set_name($name);
            $attribute->set_options(array_map(static fn ($option) => nibwp_wcpro_text($option, 200), $options));
        }

        $attribute->set_position(array_key_exists('position', $entry) ? max(0, (int) $entry['position']) : $position);
        $attribute->set_visible(nibwp_wcpro_bool($entry['visible'] ?? true, true));
        $attribute->set_variation(nibwp_wcpro_bool($entry['variation'] ?? false));
        $attributes[] = $attribute;
        $position++;
    }

    $product->set_attributes($attributes);
    $product->save();

    $for_variations = array_values(array_map(
        static fn (WC_Product_Attribute $attribute): string => (string) $attribute->get_name(),
        array_filter($attributes, static fn (WC_Product_Attribute $attribute): bool => $attribute->get_variation()),
    ));

    if ($for_variations !== [] && $product->get_type() !== 'variable') {
        $warnings[] = sprintf('Attributes are marked for variations but product %d is type "%s". Change it to "variable" with nibwp/wc-update-product first, or generate_variations will refuse.', $product->get_id(), $product->get_type());
    }

    return nibwp_wcpro_ok(
        'assign_attributes',
        sprintf('Assigned %d attribute(s) to product %d, %d of them used for variations.', count($attributes), $product->get_id(), count($for_variations)),
        [
            'result' => [
                'product_id' => $product->get_id(),
                'attributes' => array_map(
                    static fn (WC_Product_Attribute $attribute): array => [
                        'name'      => (string) $attribute->get_name(),
                        'taxonomy'  => $attribute->is_taxonomy(),
                        'options'   => $attribute->get_options(),
                        'variation' => $attribute->get_variation(),
                        'visible'   => $attribute->get_visible(),
                    ],
                    $attributes,
                ),
                'used_for_variations' => $for_variations,
            ],
            'next_steps' => array_values(array_filter(array_merge($warnings, [
                $for_variations !== [] ? sprintf('Now call action:"generate_variations", product_id:%d.', $product->get_id()) : '',
            ]))),
        ],
    );
}

/**
 * Turn caller-supplied option names or slugs into the term slugs a taxonomy
 * attribute has to be given.
 *
 * A term that does not exist is created rather than refused: the caller asked
 * for a Medium, and failing the whole call because the store has no Medium yet
 * is a worse answer than making one.
 *
 * @param array<int, mixed> $options
 * @return array<int, int>|WP_Error term ids
 */
function nibwp_wcpro_resolve_attribute_terms(string $taxonomy, array $options): array|WP_Error
{
    $denied = nibwp_wcpro_require_term_cap('edit_terms', $taxonomy, 0, 'attribute terms');
    if ($denied !== null) {
        return $denied;
    }

    $ids = [];
    foreach ($options as $option) {
        $label = nibwp_wcpro_text($option, 200);
        if ($label === '') {
            continue;
        }
        $term = get_term_by('slug', sanitize_title($label), $taxonomy);
        if (!$term instanceof WP_Term) {
            $term = get_term_by('name', $label, $taxonomy);
        }
        if (!$term instanceof WP_Term) {
            $created = wp_insert_term($label, $taxonomy);
            if (is_wp_error($created)) {
                return $created;
            }
            $ids[] = (int) ($created['term_id'] ?? 0);
            continue;
        }
        $ids[] = (int) $term->term_id;
    }

    if ($ids === []) {
        return nibwp_wcpro_err('invalid_attribute', sprintf('None of the options given for %s resolved to a term.', $taxonomy), 422);
    }

    return array_values(array_unique($ids));
}

/**
 * Build every missing combination of a variable product's variation attributes.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_generate_variations(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Product_Variation', 'Generating variations');
    if ($denied !== null) {
        return $denied;
    }
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }

    if ($product->get_type() !== 'variable') {
        return nibwp_wcpro_err(
            'not_variable',
            sprintf('Product %d is type "%s". Only a variable product can have variations — change the type with nibwp/wc-update-product first.', $product->get_id(), $product->get_type()),
            422,
        );
    }

    // The trap this whole action exists to prevent: an attribute that is not
    // marked "used for variations" contributes nothing, and a variation built
    // without it is invisible in the shop and unbuyable, with no warning from
    // WooCommerce.
    $axes = [];
    foreach ((array) $product->get_attributes() as $key => $attribute) {
        if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_variation()) {
            continue;
        }
        $slugs = [];
        foreach ((array) $attribute->get_options() as $option) {
            if ($attribute->is_taxonomy()) {
                $term = get_term((int) $option, (string) $attribute->get_name());
                $slugs[] = $term instanceof WP_Term ? (string) $term->slug : '';
            } else {
                $slugs[] = (string) $option;
            }
        }
        $slugs = array_values(array_filter($slugs, static fn (string $slug): bool => $slug !== ''));
        if ($slugs !== []) {
            $axes[sanitize_title((string) $key)] = $slugs;
        }
    }

    if ($axes === []) {
        return nibwp_wcpro_err(
            'no_variation_attributes',
            sprintf('Product %d has no attribute marked "used for variations", so there is nothing to combine. Call action:"assign_attributes" with variation:true first.', $product->get_id()),
            422,
        );
    }

    $combinations = nibwp_wcpro_cartesian($axes);
    $existing = [];
    foreach ((array) $product->get_children() as $child_id) {
        $variation = wc_get_product((int) $child_id);
        if ($variation instanceof WC_Product_Variation) {
            $existing[nibwp_wcpro_combination_key((array) $variation->get_attributes())] = (int) $child_id;
        }
    }

    $missing = array_values(array_filter(
        $combinations,
        static fn (array $combination): bool => !isset($existing[nibwp_wcpro_combination_key($combination)]),
    ));

    $limit = (int) ($input['limit'] ?? 50);
    $limit = $limit > 0 ? min($limit, 500) : 50;
    if (count($missing) > 100 && !nibwp_wcpro_bool($input['force'] ?? false) && $limit >= count($missing)) {
        return nibwp_wcpro_err(
            'too_many_variations',
            sprintf('That is %d variations. Pass a smaller "limit" and call again until done, or force:true to accept the whole batch in one request.', count($missing)),
            409,
            ['missing' => count($missing), 'existing' => count($existing)],
        );
    }

    $created = [];
    foreach (array_slice($missing, 0, $limit) as $combination) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_attributes($combination);
        $variation->set_status('publish');
        $id = (int) $variation->save();
        if ($id > 0) {
            $created[] = ['variation_id' => $id, 'attributes' => $combination];
        }
    }

    if ($created !== [] && function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product->get_id());
    }
    if ($created !== [] && class_exists('WC_Product_Variable') && method_exists('WC_Product_Variable', 'sync')) {
        WC_Product_Variable::sync($product->get_id());
    }

    $remaining = max(0, count($missing) - count($created));

    return nibwp_wcpro_ok(
        'generate_variations',
        sprintf('Created %d variation(s) on product %d; %d combination(s) still missing.', count($created), $product->get_id(), $remaining),
        [
            'result' => [
                'product_id'   => $product->get_id(),
                'axes'         => $axes,
                'possible'     => count($combinations),
                'already_there' => count($existing),
                'created'      => $created,
                'remaining'    => $remaining,
            ],
            'count' => count($created),
            'next_steps' => array_values(array_filter([
                $remaining > 0 ? sprintf('Call again with the same product_id to create the next %d.', min($limit, $remaining)) : '',
                $created !== [] ? 'Every new variation has no price and is therefore not purchasable. Set prices with nibwp/wc-update-variation, or across the set with action:"bulk_price_change".' : '',
            ])),
        ],
    );
}

/**
 * Cartesian product of the variation axes.
 *
 * @param array<string, array<int, string>> $axes
 * @return array<int, array<string, string>>
 */
function nibwp_wcpro_cartesian(array $axes): array
{
    $out = [[]];
    foreach ($axes as $key => $values) {
        $next = [];
        foreach ($out as $row) {
            foreach ($values as $value) {
                $next[] = $row + [$key => $value];
            }
        }
        $out = $next;
    }

    return $out;
}

/** A stable key for one attribute combination, so duplicates are detectable. */
function nibwp_wcpro_combination_key(array $attributes): string
{
    $normalized = [];
    foreach ($attributes as $key => $value) {
        $normalized[strtolower(sanitize_title((string) $key))] = strtolower((string) $value);
    }
    ksort($normalized);

    return (string) wp_json_encode($normalized);
}

/* ------------------------------------------------------- linked products */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_set_linked_products(array $input): array|WP_Error
{
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }

    $links = (array) ($input['links'] ?? []);
    if ($links === []) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "links" object: {cross_sell_ids, upsell_ids, children, product_url, button_text}.', 422);
    }

    $applied = [];

    foreach (['cross_sell_ids' => 'set_cross_sell_ids', 'upsell_ids' => 'set_upsell_ids'] as $field => $setter) {
        if (!array_key_exists($field, $links)) {
            continue;
        }
        $ids = nibwp_wcpro_ids($links[$field]);
        $checked = nibwp_wcpro_existing_product_ids($ids, $product->get_id());
        if (is_wp_error($checked)) {
            return $checked;
        }
        if (!method_exists($product, $setter)) {
            return nibwp_wcpro_err('wc_api_missing', sprintf('This product type has no %s().', $setter), 409);
        }
        $product->$setter($checked);
        $applied[$field] = $checked;
    }

    if (array_key_exists('children', $links)) {
        if ($product->get_type() !== 'grouped') {
            return nibwp_wcpro_err(
                'not_grouped',
                sprintf('"children" only applies to a grouped product, and product %d is type "%s".', $product->get_id(), $product->get_type()),
                422,
            );
        }
        $ids = nibwp_wcpro_existing_product_ids(nibwp_wcpro_ids($links['children']), $product->get_id());
        if (is_wp_error($ids)) {
            return $ids;
        }
        $product->set_children($ids);
        $applied['children'] = $ids;
    }

    if (array_key_exists('product_url', $links) || array_key_exists('button_text', $links)) {
        if ($product->get_type() !== 'external') {
            return nibwp_wcpro_err(
                'not_external',
                sprintf('product_url and button_text only apply to an external/affiliate product, and product %d is type "%s".', $product->get_id(), $product->get_type()),
                422,
            );
        }
        if (array_key_exists('product_url', $links)) {
            $url = nibwp_wcpro_url($links['product_url'], 'links.product_url');
            if (is_wp_error($url)) {
                return $url;
            }
            $product->set_product_url($url);
            $applied['product_url'] = $url;
        }
        if (array_key_exists('button_text', $links)) {
            $text = nibwp_wcpro_text($links['button_text'], 100);
            $product->set_button_text($text);
            $applied['button_text'] = $text;
        }
    }

    if ($applied === []) {
        return nibwp_wcpro_err('nothing_to_do', 'None of the keys in "links" were recognised. Use cross_sell_ids, upsell_ids, children, product_url or button_text.', 422);
    }

    $product->save();

    return nibwp_wcpro_ok(
        'set_linked_products',
        sprintf('Updated %s on product %d.', implode(', ', array_keys($applied)), $product->get_id()),
        ['result' => ['product_id' => $product->get_id()] + $applied],
    );
}

/**
 * Keep only ids that are really products, and never the product itself.
 *
 * A cross-sell pointing at a deleted post renders as a gap on the product page,
 * and one pointing at itself is an infinite loop in the related-products query.
 *
 * @param array<int, int> $ids
 * @return array<int, int>|WP_Error
 */
function nibwp_wcpro_existing_product_ids(array $ids, int $self): array|WP_Error
{
    $out = [];
    foreach ($ids as $id) {
        if ($id === $self) {
            return nibwp_wcpro_err('self_link', sprintf('Product %d cannot be linked to itself.', $id), 422);
        }
        if (get_post_type($id) !== 'product') {
            return nibwp_wcpro_err('not_a_product', sprintf('%d is not a product.', $id), 422);
        }
        $out[] = $id;
    }

    return array_values(array_unique($out));
}

/* ----------------------------------------------------------------- downloads */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_downloads(array $input): array|WP_Error
{
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }

    $items = [];
    foreach ((array) $product->get_downloads() as $id => $download) {
        $items[] = [
            'download_id' => (string) $id,
            'name'        => is_object($download) && method_exists($download, 'get_name') ? (string) $download->get_name() : '',
            'file'        => is_object($download) && method_exists($download, 'get_file') ? (string) $download->get_file() : '',
            'enabled'     => !is_object($download) || !method_exists($download, 'get_enabled') || (bool) $download->get_enabled(),
        ];
    }

    return nibwp_wcpro_ok('list_downloads', sprintf('%d downloadable file(s) on product %d.', count($items), $product->get_id()), [
        'items' => $items,
        'count' => count($items),
        'result' => [
            'product_id'      => $product->get_id(),
            'downloadable'    => (bool) $product->is_downloadable(),
            'download_limit'  => (int) $product->get_download_limit(),
            'download_expiry' => (int) $product->get_download_expiry(),
        ],
    ]);
}

/**
 * Replace or extend the downloadable file list.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_write_downloads(array $input, bool $append): array|WP_Error
{
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }

    $given = (array) ($input['downloads'] ?? []);
    if ($given === [] && !$append && !array_key_exists('download_limit', $input) && !array_key_exists('download_expiry', $input)) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "downloads" array of {name, file}, or a download_limit / download_expiry to change.', 422);
    }
    if ($given === [] && $append) {
        return nibwp_wcpro_err('nothing_to_do', 'Provide a "downloads" array of {name, file} to add.', 422);
    }

    $prepared = [];
    foreach ($given as $entry) {
        $entry = (array) $entry;
        $file = trim((string) ($entry['file'] ?? ''));
        if ($file === '') {
            return nibwp_wcpro_err('invalid_download', 'Every download needs a "file" — a URL, or a path inside the uploads directory.', 422);
        }
        // Either an http(s) URL, or a path this skill can prove is inside
        // uploads. Never an arbitrary server path: the file becomes downloadable
        // to anyone who buys the product.
        if (preg_match('#^https?://#i', $file)) {
            $resolved = nibwp_wcpro_url($file, 'downloads[].file');
            if (is_wp_error($resolved)) {
                return $resolved;
            }
        } else {
            require_once __DIR__ . '/../lib/files.php';
            $absolute = nibwp_wcpro_uploads_path($file, true, []);
            if (is_wp_error($absolute)) {
                return $absolute;
            }
            $resolved = nibwp_wcpro_uploads_url($absolute);
            if ($resolved === '') {
                return nibwp_wcpro_err('unresolvable_file', sprintf('Could not turn uploads/%s into a URL.', $file), 500);
            }
        }
        $prepared[] = ['name' => nibwp_wcpro_text($entry['name'] ?? basename($resolved), 200), 'file' => $resolved];
    }

    $existing = [];
    if ($append) {
        foreach ((array) $product->get_downloads() as $download) {
            if (is_object($download) && method_exists($download, 'get_file')) {
                $existing[] = ['name' => (string) $download->get_name(), 'file' => (string) $download->get_file()];
            }
        }
    }

    $downloads = array_merge($existing, $prepared);
    if ($downloads !== [] || !$append) {
        $product->set_downloads($downloads);
    }
    if ($downloads !== []) {
        $product->set_downloadable(true);
    }
    if (array_key_exists('download_limit', $input)) {
        $product->set_download_limit((int) $input['download_limit']);
    }
    if (array_key_exists('download_expiry', $input)) {
        $product->set_download_expiry((int) $input['download_expiry']);
    }
    $product->save();

    // set_downloads() disables a file it cannot validate instead of throwing, so
    // read the result back rather than reporting what was asked for.
    $stored = [];
    foreach ((array) $product->get_downloads() as $id => $download) {
        $stored[] = [
            'download_id' => (string) $id,
            'name'        => is_object($download) && method_exists($download, 'get_name') ? (string) $download->get_name() : '',
            'file'        => is_object($download) && method_exists($download, 'get_file') ? (string) $download->get_file() : '',
            'enabled'     => !is_object($download) || !method_exists($download, 'get_enabled') || (bool) $download->get_enabled(),
        ];
    }
    $disabled = array_values(array_filter($stored, static fn (array $row): bool => $row['enabled'] === false));

    return nibwp_wcpro_ok(
        $append ? 'add_download' : 'set_downloads',
        sprintf('Product %d now has %d downloadable file(s).', $product->get_id(), count($stored)),
        [
            'items' => $stored,
            'count' => count($stored),
            'result' => ['product_id' => $product->get_id(), 'downloadable' => (bool) $product->is_downloadable()],
            'next_steps' => $disabled === []
                ? []
                : [sprintf('%d file(s) were stored disabled because WooCommerce could not validate them — check the paths are inside the uploads directory and the file types are allowed.', count($disabled))],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_remove_download(array $input): array|WP_Error
{
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }

    $download_id = nibwp_wcpro_text($input['download_id'] ?? '', 64);
    if ($download_id === '') {
        return nibwp_wcpro_err('missing_id', 'Provide the "download_id" to remove. action:"list_downloads" returns it.', 422);
    }

    $kept = [];
    $found = false;
    foreach ((array) $product->get_downloads() as $id => $download) {
        if ((string) $id === $download_id) {
            $found = true;
            continue;
        }
        if (is_object($download) && method_exists($download, 'get_file')) {
            $kept[] = ['name' => (string) $download->get_name(), 'file' => (string) $download->get_file()];
        }
    }
    if (!$found) {
        return nibwp_wcpro_err('not_found', sprintf('Product %d has no download "%s".', $product->get_id(), $download_id), 404);
    }

    $product->set_downloads($kept);
    if ($kept === []) {
        $product->set_downloadable(false);
    }
    $product->save();

    return nibwp_wcpro_ok('remove_download', sprintf('Removed download "%s" from product %d.', $download_id, $product->get_id()), [
        'result' => ['product_id' => $product->get_id(), 'remaining' => count($kept), 'downloadable' => (bool) $product->is_downloadable()],
    ]);
}

/* --------------------------------------------------------------------- media */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_set_image(array $input): array|WP_Error
{
    $product = nibwp_wcpro_product($input);
    if (is_wp_error($product)) {
        return $product;
    }
    // Sideloading writes a file and creates an attachment, which is its own
    // authority — an account that may edit a product is not automatically one
    // that may add to the media library.
    $denied = nibwp_wcpro_require_cap('upload_files', 'Adding an image to the media library');
    if ($denied !== null) {
        return $denied;
    }

    $url = nibwp_wcpro_url($input['image_url'] ?? '', 'image_url');
    if (is_wp_error($url)) {
        return $url;
    }
    $role = nibwp_wcpro_enum($input['image_role'] ?? 'featured', ['featured', 'gallery'], 'image_role', 'featured');
    if (is_wp_error($role)) {
        return $role;
    }

    if (!function_exists('media_sideload_image')) {
        foreach (['/wp-admin/includes/media.php', '/wp-admin/includes/file.php', '/wp-admin/includes/image.php'] as $include) {
            $path = ABSPATH . ltrim($include, '/');
            if (is_readable($path)) {
                require_once $path;
            }
        }
    }
    $denied = nibwp_wcpro_require_function('media_sideload_image', 'Setting a product image from a URL');
    if ($denied !== null) {
        return $denied;
    }

    $attachment_id = media_sideload_image($url, $product->get_id(), nibwp_wcpro_text($input['alt'] ?? '', 200), 'id');
    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }
    $attachment_id = (int) $attachment_id;

    $alt = nibwp_wcpro_text($input['alt'] ?? '', 200);
    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }

    if ($role === 'featured') {
        $product->set_image_id($attachment_id);
    } else {
        $gallery = array_values(array_unique(array_merge(
            array_map('intval', (array) $product->get_gallery_image_ids()),
            [$attachment_id],
        )));
        $product->set_gallery_image_ids($gallery);
    }
    $product->save();

    return nibwp_wcpro_ok(
        'set_image',
        sprintf('Attached image %d to product %d as the %s image.', $attachment_id, $product->get_id(), $role),
        [
            'result' => [
                'product_id'    => $product->get_id(),
                'attachment_id' => $attachment_id,
                'url'           => (string) wp_get_attachment_url($attachment_id),
                'role'          => $role,
                'alt'           => $alt,
            ],
            'next_steps' => $alt === ''
                ? ['The image has no alt text. Pass "alt" describing what is in it — a product image without one is unreadable to a screen reader and invisible to image search.']
                : [],
        ],
    );
}

/* ------------------------------------------------------------------ pricing */

/**
 * The products a bulk action applies to.
 *
 * Refuses an empty filter rather than defaulting to the whole catalogue, which
 * is the difference between "put 10% on the winter range" and "put 10% on
 * everything".
 *
 * @return array<int, int>|WP_Error
 */
function nibwp_wcpro_filtered_product_ids(array $filter): array|WP_Error
{
    $ids = nibwp_wcpro_ids($filter['ids'] ?? null);
    $category = nibwp_wcpro_text($filter['category'] ?? '', 200);
    $tag = nibwp_wcpro_text($filter['tag'] ?? '', 200);
    $type = nibwp_wcpro_text($filter['type'] ?? '', 40);

    if ($ids === [] && $category === '' && $tag === '' && $type === '') {
        return nibwp_wcpro_err(
            'filter_required',
            'Provide a "filter" naming which products: ids, category, tag or type. An empty filter would mean the whole catalogue, and this ability will not assume that.',
            422,
        );
    }

    if ($ids !== []) {
        $checked = [];
        foreach ($ids as $id) {
            if (get_post_type($id) === 'product') {
                $checked[] = $id;
            }
        }
        return $checked;
    }

    $limit = (int) ($filter['limit'] ?? 200);
    $args = [
        'post_type'      => 'product',
        'post_status'    => nibwp_wcpro_text($filter['status'] ?? 'publish', 20) ?: 'publish',
        'posts_per_page' => $limit > 0 ? min($limit, 1000) : 200,
        'fields'         => 'ids',
        'tax_query'      => [],
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    if ($category !== '') {
        $args['tax_query'][] = ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $category];
    }
    if ($tag !== '') {
        $args['tax_query'][] = ['taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => $tag];
    }
    if ($type !== '') {
        $args['tax_query'][] = ['taxonomy' => 'product_type', 'field' => 'slug', 'terms' => $type];
    }
    if ($args['tax_query'] === []) {
        unset($args['tax_query']);
    }

    return array_map('intval', (array) get_posts($args));
}

/**
 * Walk a filtered set, applying a callback to each product the account may edit.
 *
 * The per-product capability check happens HERE, on each id, so a bulk action
 * can never become a way to edit a product the account could not open in
 * wp-admin. A product that fails it is skipped and counted, not fatal.
 *
 * @param callable(WC_Product): (array<string, mixed>|null|WP_Error) $apply
 * @return array{changed: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}|WP_Error
 */
function nibwp_wcpro_walk_products(array $ids, bool $include_variations, bool $dry_run, callable $apply): array|WP_Error
{
    $changed = [];
    $skipped = [];

    foreach ($ids as $id) {
        if (!current_user_can('edit_post', $id)) {
            $skipped[] = ['product_id' => $id, 'reason' => 'not allowed to edit this product'];
            continue;
        }
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product) {
            $skipped[] = ['product_id' => $id, 'reason' => 'not a product'];
            continue;
        }

        $targets = [$product];
        if ($include_variations && $product->get_type() === 'variable') {
            foreach ((array) $product->get_children() as $child_id) {
                $child = wc_get_product((int) $child_id);
                if ($child instanceof WC_Product) {
                    $targets[] = $child;
                }
            }
        }

        foreach ($targets as $target) {
            $result = $apply($target);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($result === null) {
                $skipped[] = ['product_id' => $target->get_id(), 'reason' => 'nothing to change'];
                continue;
            }
            if (!$dry_run) {
                $target->save();
            }
            $changed[] = ['product_id' => $target->get_id()] + $result;
        }
    }

    return ['changed' => $changed, 'skipped' => $skipped];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_bulk_price_change(array $input): array|WP_Error
{
    $change = (array) ($input['change'] ?? []);
    $field = nibwp_wcpro_enum($change['field'] ?? 'regular_price', ['regular_price', 'sale_price'], 'change.field', 'regular_price');
    if (is_wp_error($field)) {
        return $field;
    }
    $mode = nibwp_wcpro_enum(
        $change['mode'] ?? '',
        ['increase_percent', 'decrease_percent', 'increase_fixed', 'decrease_fixed', 'set_fixed'],
        'change.mode',
    );
    if (is_wp_error($mode)) {
        return $mode;
    }
    if (!is_numeric($change['amount'] ?? null)) {
        return nibwp_wcpro_err('invalid_amount', 'change.amount must be a number — a percentage for the _percent modes, a currency amount for the _fixed ones.', 422);
    }
    $amount = (float) $change['amount'];
    if ($amount < 0) {
        return nibwp_wcpro_err('invalid_amount', 'change.amount is never negative — use the decrease_ mode instead.', 422);
    }

    $filter = (array) ($input['filter'] ?? []);
    $ids = nibwp_wcpro_filtered_product_ids($filter);
    if (is_wp_error($ids)) {
        return $ids;
    }
    if ($ids === []) {
        return nibwp_wcpro_ok('bulk_price_change', 'No product matched that filter, so nothing was changed.', ['count' => 0, 'items' => []]);
    }

    $dry_run = nibwp_wcpro_bool($input['dry_run'] ?? false);
    $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
    $getter = 'get_' . $field;
    $setter = 'set_' . $field;

    $walked = nibwp_wcpro_walk_products(
        $ids,
        nibwp_wcpro_bool($filter['include_variations'] ?? true, true),
        $dry_run,
        static function (WC_Product $product) use ($getter, $setter, $mode, $amount, $decimals, $field): ?array {
            $before = (string) $product->$getter('edit');
            if (trim($before) === '' && $mode !== 'set_fixed') {
                return null; // Nothing to scale. A variable parent has no own price.
            }
            $after = nibwp_wcpro_apply_price_change($before === '' ? '0' : $before, $mode, $amount, $decimals);
            if ($after === $before) {
                return null;
            }
            $product->$setter($after);

            return ['field' => $field, 'before' => $before, 'after' => $after];
        },
    );
    if (is_wp_error($walked)) {
        return $walked;
    }

    return nibwp_wcpro_ok(
        'bulk_price_change',
        $dry_run
            ? sprintf('Dry run: %d price(s) would change, %d skipped. Nothing was written.', count($walked['changed']), count($walked['skipped']))
            : sprintf('Changed %d price(s); %d skipped.', count($walked['changed']), count($walked['skipped'])),
        [
            'dry_run' => $dry_run,
            'items'   => $walked['changed'],
            'count'   => count($walked['changed']),
            'result'  => ['skipped' => $walked['skipped'], 'matched_products' => count($ids), 'mode' => $mode, 'amount' => $amount, 'field' => $field],
            'next_steps' => $dry_run && $walked['changed'] !== [] ? ['Re-send with dry_run:false to apply exactly this.'] : [],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_schedule_sale(array $input): array|WP_Error
{
    $sale = (array) ($input['sale'] ?? []);
    $mode = nibwp_wcpro_enum($sale['mode'] ?? '', ['percent', 'fixed', 'price'], 'sale.mode');
    if (is_wp_error($mode)) {
        return $mode;
    }
    if (!is_numeric($sale['amount'] ?? null)) {
        return nibwp_wcpro_err('invalid_amount', 'sale.amount must be a number: percent off, currency off, or the sale price itself when mode is "price".', 422);
    }
    $amount = (float) $sale['amount'];
    if ($amount < 0) {
        return nibwp_wcpro_err('invalid_amount', 'sale.amount cannot be negative.', 422);
    }
    if ($mode === 'percent' && $amount >= 100) {
        return nibwp_wcpro_err('invalid_amount', 'A discount of 100% or more would price the products at nothing. Use mode:"price" with amount:0 if that is really the intention.', 422);
    }

    $from = nibwp_wcpro_date($sale['date_from'] ?? '', 'sale.date_from');
    if (is_wp_error($from)) {
        return $from;
    }
    $to = nibwp_wcpro_date($sale['date_to'] ?? '', 'sale.date_to');
    if (is_wp_error($to)) {
        return $to;
    }
    if ($from !== '' && $to !== '' && strtotime($to) < strtotime($from)) {
        return nibwp_wcpro_err('invalid_dates', 'sale.date_to is before sale.date_from, so the sale would never run.', 422);
    }

    $filter = (array) ($input['filter'] ?? []);
    $ids = nibwp_wcpro_filtered_product_ids($filter);
    if (is_wp_error($ids)) {
        return $ids;
    }
    if ($ids === []) {
        return nibwp_wcpro_ok('schedule_sale', 'No product matched that filter, so nothing was changed.', ['count' => 0, 'items' => []]);
    }

    $dry_run = nibwp_wcpro_bool($input['dry_run'] ?? false);
    $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;

    $walked = nibwp_wcpro_walk_products(
        $ids,
        nibwp_wcpro_bool($filter['include_variations'] ?? true, true),
        $dry_run,
        static function (WC_Product $product) use ($mode, $amount, $from, $to, $decimals): ?array {
            $regular = (string) $product->get_regular_price('edit');
            if (trim($regular) === '') {
                return null; // No regular price to discount from.
            }
            $sale_price = match ($mode) {
                'percent' => nibwp_wcpro_apply_price_change($regular, 'decrease_percent', $amount, $decimals),
                'fixed'   => nibwp_wcpro_apply_price_change($regular, 'decrease_fixed', $amount, $decimals),
                'price'   => number_format($amount, $decimals, '.', ''),
                default   => $regular,
            };
            if ((float) $sale_price >= (float) $regular) {
                return null; // Not a discount; leave it alone rather than raise the price.
            }

            $product->set_sale_price($sale_price);
            $product->set_date_on_sale_from($from !== '' ? $from : null);
            $product->set_date_on_sale_to($to !== '' ? $to : null);

            return ['regular_price' => $regular, 'sale_price' => $sale_price, 'date_from' => $from, 'date_to' => $to];
        },
    );
    if (is_wp_error($walked)) {
        return $walked;
    }

    return nibwp_wcpro_ok(
        'schedule_sale',
        $dry_run
            ? sprintf('Dry run: %d product(s) would go on sale, %d skipped. Nothing was written.', count($walked['changed']), count($walked['skipped']))
            : sprintf('%d product(s) put on sale; %d skipped.', count($walked['changed']), count($walked['skipped'])),
        [
            'dry_run' => $dry_run,
            'items'   => $walked['changed'],
            'count'   => count($walked['changed']),
            'result'  => ['skipped' => $walked['skipped'], 'matched_products' => count($ids), 'date_from' => $from, 'date_to' => $to],
            'next_steps' => array_values(array_filter([
                $dry_run && $walked['changed'] !== [] ? 'Re-send with dry_run:false to apply exactly this.' : '',
                !$dry_run && $to === '' ? 'No end date was given, so the sale runs until action:"clear_sale" removes it.' : '',
            ])),
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_clear_sale(array $input): array|WP_Error
{
    $filter = (array) ($input['filter'] ?? []);
    $ids = nibwp_wcpro_filtered_product_ids($filter);
    if (is_wp_error($ids)) {
        return $ids;
    }
    if ($ids === []) {
        return nibwp_wcpro_ok('clear_sale', 'No product matched that filter, so nothing was changed.', ['count' => 0, 'items' => []]);
    }

    $dry_run = nibwp_wcpro_bool($input['dry_run'] ?? false);

    $walked = nibwp_wcpro_walk_products(
        $ids,
        nibwp_wcpro_bool($filter['include_variations'] ?? true, true),
        $dry_run,
        static function (WC_Product $product): ?array {
            $before = (string) $product->get_sale_price('edit');
            $had_dates = $product->get_date_on_sale_from('edit') !== null || $product->get_date_on_sale_to('edit') !== null;
            if (trim($before) === '' && !$had_dates) {
                return null;
            }
            $product->set_sale_price('');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);

            return ['sale_price_removed' => $before];
        },
    );
    if (is_wp_error($walked)) {
        return $walked;
    }

    return nibwp_wcpro_ok(
        'clear_sale',
        $dry_run
            ? sprintf('Dry run: %d sale price(s) would be cleared. Nothing was written.', count($walked['changed']))
            : sprintf('Cleared %d sale price(s); %d skipped.', count($walked['changed']), count($walked['skipped'])),
        [
            'dry_run' => $dry_run,
            'items'   => $walked['changed'],
            'count'   => count($walked['changed']),
            'result'  => ['skipped' => $walked['skipped'], 'matched_products' => count($ids)],
            'next_steps' => $dry_run && $walked['changed'] !== [] ? ['Re-send with dry_run:false to apply exactly this.'] : [],
        ],
    );
}

/* -------------------------------------------------------------- duplication */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_duplicate_product(array $input): array|WP_Error
{
    // Read authority on the source, create authority for the copy. Both, because
    // duplicating is a read of one object and a write of another.
    $product = nibwp_wcpro_product($input, 'read_post');
    if (is_wp_error($product)) {
        return $product;
    }
    $denied = nibwp_wcpro_require_create_product();
    if ($denied !== null) {
        return $denied;
    }

    // WooCommerce's own duplicator handles variations, meta exclusions and SKU
    // uniqueness. It lives in wp-admin, so it is included on demand rather than
    // reimplemented — a hand-rolled clone is how a duplicate ends up sharing a
    // SKU with its original.
    if (!class_exists('WC_Admin_Duplicate_Product') && defined('WC_ABSPATH')) {
        $path = WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
    $denied = nibwp_wcpro_require_class('WC_Admin_Duplicate_Product', 'Duplicating a product');
    if ($denied !== null) {
        return $denied;
    }

    $duplicator = new WC_Admin_Duplicate_Product();
    if (!method_exists($duplicator, 'product_duplicate')) {
        return nibwp_wcpro_err('wc_api_missing', 'WC_Admin_Duplicate_Product::product_duplicate() is not available on this site.', 409);
    }

    $copy = $duplicator->product_duplicate($product);
    if (!$copy instanceof WC_Product || $copy->get_id() <= 0) {
        return nibwp_wcpro_err('duplicate_failed', sprintf('WooCommerce did not return a copy of product %d.', $product->get_id()), 500);
    }

    $name = nibwp_wcpro_text($input['name'] ?? '', 200);
    if ($name !== '') {
        $copy->set_name($name);
        $copy->save();
    }

    return nibwp_wcpro_ok(
        'duplicate_product',
        sprintf('Product %d duplicated as %d ("%s"), as a draft.', $product->get_id(), $copy->get_id(), (string) $copy->get_name()),
        [
            'result' => [
                'source_id'  => $product->get_id(),
                'product_id' => $copy->get_id(),
                'name'       => (string) $copy->get_name(),
                'status'     => (string) $copy->get_status(),
                'sku'        => (string) $copy->get_sku(),
                'variations' => count((array) $copy->get_children()),
                'edit_url'   => admin_url('post.php?post=' . $copy->get_id() . '&action=edit'),
            ],
            'next_steps' => ['The copy is a draft with a new SKU. Publish it with nibwp/wc-update-product once its prices and content are right.'],
        ],
    );
}
