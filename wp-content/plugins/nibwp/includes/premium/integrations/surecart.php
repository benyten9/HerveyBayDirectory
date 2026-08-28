<?php

declare(strict_types=1);

/**
 * SureCart integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Nine domain-grouped abilities give an AI agent control of a SureCart store:
 * discovery, products, prices, collections, coupons, customers, orders, the
 * local checkout forms + store pages, and a catalog of SureCart's blocks (used
 * by the EtchWP/Bricks Pro skills to design SureCart storefront UIs).
 *
 * Mechanism: SureCart is a SaaS-backed store. Catalog/commerce data
 * (products, prices, orders, customers…) lives on SureCart's API and is reached
 * through the bundled PHP SDK (SureCart\Models\{Product,Price,Order,Customer,
 * Collection,Coupon,Account}). Checkout forms and the store pages are LOCAL —
 * the sc_form custom post type + the surecart_*_page_id options. Every SDK call
 * is wrapped so an unconnected/limited store degrades to a clear error instead
 * of a fatal. Verified against SureCart 4.x (Models present, 268 surecart/*
 * blocks).
 *
 * Detection: class SureCart / SURECART_PLUGIN_FILE.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is SureCart active? */
function nibwp_sc_available(): bool
{
    return class_exists('SureCart') || defined('SURECART_PLUGIN_FILE');
}

/** Resolve a SureCart model FQCN, or null. */
function nibwp_sc_model(string $short): ?string
{
    $cls = 'SureCart\\Models\\' . $short;
    return class_exists($cls) ? $cls : null;
}

/** House WP_Error wrapper. */
function nibwp_sc_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Convert a SureCart model / collection to a plain array. */
function nibwp_sc_arr($value): array
{
    if (is_object($value)) {
        if (method_exists($value, 'toArray')) {
            return (array) $value->toArray();
        }
        $json = wp_json_encode($value);
        if (is_string($json)) {
            $d = json_decode($json, true);
            if (is_array($d)) {
                return $d;
            }
        }
        return (array) $value;
    }
    return is_array($value) ? $value : [];
}

/** Map a SureCart collection/iterable to an array of arrays. */
function nibwp_sc_list($collection): array
{
    $out = [];
    if (is_iterable($collection)) {
        foreach ($collection as $item) {
            $out[] = nibwp_sc_arr($item);
        }
    }
    return $out;
}

/** Clamp pagination. */
function nibwp_sc_paginate(array $input): array
{
    return [min(max((int) ($input['per_page'] ?? 20), 1), 100), max((int) ($input['page'] ?? 1), 1)];
}

/**
 * Run a SureCart SDK closure with graceful error handling. Returns the result
 * or a WP_Error describing the API/connection failure.
 *
 * @return mixed|WP_Error
 */
function nibwp_sc_try(callable $fn)
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_sc_err('surecart_api_error', $e->getMessage(), 502);
    }
}

/**
 * Generic CRUD dispatcher for an API-backed SureCart model. Actions:
 * list, get, create, update, archive/delete.
 *
 * @return array|WP_Error
 */
function nibwp_sc_crud(array $input, string $model_short, array $list_query = [], array $writable = []): array|WP_Error
{
    if (!nibwp_sc_available()) {
        return nibwp_sc_err('surecart_inactive', 'SureCart is not active on this site.', 404);
    }
    $Model = nibwp_sc_model($model_short);
    if (!$Model) {
        return nibwp_sc_err('no_model', "SureCart {$model_short} model is unavailable.");
    }
    $action = (string) ($input['action'] ?? '');
    [$per, $page] = nibwp_sc_paginate($input);

    switch ($action) {
        case 'list':
            $query = array_merge(['per_page' => $per, 'page' => $page], $list_query, (array) ($input['query'] ?? []));
            $res = nibwp_sc_try(static fn () => $Model::where($query)->get());
            return is_wp_error($res) ? $res : ['items' => nibwp_sc_list($res), 'count' => is_countable($res) ? count($res) : null];

        case 'get':
            $id = (string) ($input['id'] ?? '');
            if ($id === '') {
                return nibwp_sc_err('no_id', 'Provide an "id".');
            }
            $res = nibwp_sc_try(static fn () => $Model::find($id));
            return is_wp_error($res) ? $res : ['item' => nibwp_sc_arr($res)];

        case 'create':
            $data = $writable ? array_intersect_key((array) ($input['data'] ?? []), array_flip($writable)) : (array) ($input['data'] ?? []);
            if ($data === []) {
                return nibwp_sc_err('no_data', 'Provide a non-empty "data" object.');
            }
            $res = nibwp_sc_try(static fn () => $Model::create($data));
            return is_wp_error($res) ? $res : ['created' => true, 'item' => nibwp_sc_arr($res)];

        case 'update':
            $id = (string) ($input['id'] ?? '');
            $data = $writable ? array_intersect_key((array) ($input['data'] ?? []), array_flip($writable)) : (array) ($input['data'] ?? []);
            if ($id === '' || $data === []) {
                return nibwp_sc_err('bad_update', 'update needs "id" + "data".');
            }
            $res = nibwp_sc_try(static function () use ($Model, $id, $data) {
                $obj = $Model::find($id);
                return method_exists($obj, 'update') ? $obj->update($data) : $Model::update(array_merge(['id' => $id], $data));
            });
            return is_wp_error($res) ? $res : ['updated' => true, 'item' => nibwp_sc_arr($res)];

        case 'archive':
        case 'delete':
            $id = (string) ($input['id'] ?? '');
            if ($id === '') {
                return nibwp_sc_err('no_id', 'Provide an "id".');
            }
            $res = nibwp_sc_try(static function () use ($Model, $id, $action) {
                $obj = $Model::find($id);
                if ($action === 'archive' && method_exists($obj, 'archive')) {
                    return $obj->archive();
                }
                return method_exists($obj, 'delete') ? $obj->delete() : null;
            });
            return is_wp_error($res) ? $res : [$action . 'd' => true, 'id' => $id];
    }
    return nibwp_sc_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 1) surecart-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/surecart-info', [
    'label'       => __('SureCart — Info', 'nibwp'),
    'description' => __('Detect SureCart, its version, store connection state, the shop/checkout/dashboard page assignments, local checkout-form count, and counts of products/prices when reachable.', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_sc_info_execute(array $input): array|WP_Error
{
    if (!nibwp_sc_available()) {
        return nibwp_sc_err('surecart_inactive', 'SureCart is not active on this site.', 404);
    }
    $connected = false;
    $Account = nibwp_sc_model('Account');
    if ($Account) {
        $acc = nibwp_sc_try(static fn () => $Account::find());
        $connected = !is_wp_error($acc) && $acc !== null;
    }
    $reg = function_exists('WP_Block_Type_Registry') || class_exists('WP_Block_Type_Registry') ? WP_Block_Type_Registry::get_instance()->get_all_registered() : [];
    $blocks = array_values(array_filter(array_keys($reg), static fn ($b) => strpos($b, 'surecart/') === 0));

    return [
        'surecart_active' => true,
        'version'      => defined('SURECART_VERSION') ? SURECART_VERSION : '',
        'connected'    => $connected,
        'pages'        => [
            'shop'      => (int) get_option('surecart_shop_page_id'),
            'checkout'  => (int) get_option('surecart_checkout_page_id'),
            'dashboard' => (int) get_option('surecart_dashboard_page_id'),
        ],
        'checkout_forms' => (int) wp_count_posts('sc_form')->publish + (int) wp_count_posts('sc_form')->draft,
        'local_products' => post_type_exists('sc_product') ? ((int) wp_count_posts('sc_product')->publish) : 0,
        'block_count'  => count($blocks),
        'abilities'    => ['products', 'prices', 'collections', 'coupons', 'customers', 'orders', 'forms', 'blocks'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2-7) API-backed models (products, prices, collections, coupons, customers, orders)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/surecart-products', [
    'label'       => __('SureCart — Products', 'nibwp'),
    'description' => __('Manage SureCart products. Actions: list, get, create, update, archive. "data" carries product fields (name, description, image, metadata, tax_enabled, slug…).', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'archive']], 'id' => ['type' => 'string'], 'data' => ['type' => 'object'], 'query' => ['type' => 'object'], 'per_page' => ['type' => 'integer'], 'page' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => true],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_products_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);
function nibwp_sc_products_execute(array $input): array|WP_Error
{
    return nibwp_sc_crud($input, 'Product', ['expand' => ['prices']]);
}

wp_register_ability('nibwp/surecart-prices', [
    'label'       => __('SureCart — Prices', 'nibwp'),
    'description' => __('Manage SureCart prices (a product can have many). Actions: list, get, create, update, archive. For create, "data" needs product (id), amount (in cents), currency, and optionally recurring {interval, interval_count}, name, trial_duration_days.', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'archive']], 'id' => ['type' => 'string'], 'data' => ['type' => 'object'], 'query' => ['type' => 'object'], 'per_page' => ['type' => 'integer'], 'page' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => true],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_prices_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);
function nibwp_sc_prices_execute(array $input): array|WP_Error
{
    return nibwp_sc_crud($input, 'Price');
}

wp_register_ability('nibwp/surecart-collections', [
    'label'       => __('SureCart — Collections', 'nibwp'),
    'description' => __('Manage SureCart product collections (groupings used by the storefront). Actions: list, get, create, update, archive.', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'archive']], 'id' => ['type' => 'string'], 'data' => ['type' => 'object'], 'query' => ['type' => 'object'], 'per_page' => ['type' => 'integer'], 'page' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => true],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_collections_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);
function nibwp_sc_collections_execute(array $input): array|WP_Error
{
    return nibwp_sc_crud($input, 'ProductCollection');
}

wp_register_ability('nibwp/surecart-coupons', [
    'label'       => __('SureCart — Coupons', 'nibwp'),
    'description' => __('Manage SureCart coupons / discounts. Actions: list, get, create, update, archive. For create, "data" needs name + (amount_off or percent_off).', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'archive']], 'id' => ['type' => 'string'], 'data' => ['type' => 'object'], 'query' => ['type' => 'object'], 'per_page' => ['type' => 'integer'], 'page' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => true],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_coupons_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);
function nibwp_sc_coupons_execute(array $input): array|WP_Error
{
    return nibwp_sc_crud($input, 'Coupon');
}

wp_register_ability('nibwp/surecart-customers', [
    'label'       => __('SureCart — Customers', 'nibwp'),
    'description' => __('Read SureCart customers. Actions: list, get. (Create/update is intentionally omitted — customers are created through checkout.)', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'get']], 'id' => ['type' => 'string'], 'query' => ['type' => 'object'], 'per_page' => ['type' => 'integer'], 'page' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => true],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_customers_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);
function nibwp_sc_customers_execute(array $input): array|WP_Error
{
    return nibwp_sc_crud($input, 'Customer');
}

wp_register_ability('nibwp/surecart-orders', [
    'label'       => __('SureCart — Orders', 'nibwp'),
    'description' => __('Read SureCart orders. Actions: list, get. Returns order/checkout records with line items, customer and totals.', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['list', 'get']], 'id' => ['type' => 'string'], 'query' => ['type' => 'object'], 'per_page' => ['type' => 'integer'], 'page' => ['type' => 'integer']], 'required' => ['action'], 'additionalProperties' => true],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_orders_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);
function nibwp_sc_orders_execute(array $input): array|WP_Error
{
    return nibwp_sc_crud($input, 'Order', ['expand' => ['checkout', 'customer']]);
}

/* ----------------------------------------------------------------------------
 * 8) surecart-forms — local checkout forms + store pages
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/surecart-forms', [
    'label'       => __('SureCart — Checkout Forms & Pages', 'nibwp'),
    'description' => __('Manage the LOCAL SureCart checkout forms (sc_form posts, whose content is SureCart blocks) and the store page assignments. Actions: list, get, create, update, set_pages. The form content is block markup you can build with the EtchWP/Bricks Pro skills.', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'set_pages']],
            'id'      => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string', 'description' => 'Block markup (SureCart blocks).'],
            'status'  => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
            'pages'   => ['type' => 'object', 'description' => 'For set_pages: { shop, checkout, dashboard } page IDs.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_forms_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_sc_forms_execute(array $input): array|WP_Error
{
    if (!nibwp_sc_available()) {
        return nibwp_sc_err('surecart_inactive', 'SureCart is not active on this site.', 404);
    }
    if (!post_type_exists('sc_form')) {
        return nibwp_sc_err('no_forms', 'The SureCart checkout-form post type (sc_form) is not registered.', 404);
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'list':
            $items = [];
            foreach (get_posts(['post_type' => 'sc_form', 'post_status' => ['publish', 'draft'], 'numberposts' => 100]) as $p) {
                $items[] = ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'is_default' => (int) get_option('surecart_checkout_sc_form_id') === $p->ID];
            }
            return ['forms' => $items, 'count' => count($items)];

        case 'get':
            $p = get_post((int) ($input['id'] ?? 0));
            if (!$p || $p->post_type !== 'sc_form') {
                return nibwp_sc_err('not_found', 'Checkout form not found.', 404);
            }
            return ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'content' => $p->post_content];

        case 'create':
            $pid = wp_insert_post([
                'post_type'    => 'sc_form',
                'post_status'  => in_array($input['status'] ?? 'publish', ['publish', 'draft'], true) ? (string) $input['status'] : 'publish',
                'post_title'   => sanitize_text_field((string) ($input['title'] ?? 'Checkout form')),
                'post_content' => wp_slash((string) ($input['content'] ?? '')),
            ], true);
            return is_wp_error($pid) ? $pid : ['created' => true, 'id' => (int) $pid];

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if (get_post_type($id) !== 'sc_form') {
                return nibwp_sc_err('not_found', 'Checkout form not found.', 404);
            }
            $postarr = ['ID' => $id];
            if (isset($input['title']))   { $postarr['post_title']   = sanitize_text_field((string) $input['title']); }
            if (isset($input['content'])) { $postarr['post_content'] = wp_slash((string) $input['content']); }
            if (isset($input['status']))  { $postarr['post_status']  = in_array($input['status'], ['publish', 'draft'], true) ? (string) $input['status'] : 'publish'; }
            if (count($postarr) > 1) {
                wp_update_post($postarr);
            }
            return ['updated' => true, 'id' => $id];

        case 'set_pages':
            $pages = (array) ($input['pages'] ?? []);
            $changed = [];
            foreach (['shop' => 'surecart_shop_page_id', 'checkout' => 'surecart_checkout_page_id', 'dashboard' => 'surecart_dashboard_page_id'] as $k => $opt) {
                if (isset($pages[$k])) {
                    update_option($opt, (int) $pages[$k]);
                    $changed[] = $k;
                }
            }
            return ['updated' => $changed];
    }
    return nibwp_sc_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 9) surecart-blocks — storefront block catalog (feeds the design skills)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/surecart-blocks', [
    'label'       => __('SureCart — Block Catalog', 'nibwp'),
    'description' => __('List the available SureCart storefront blocks (surecart/*) and their attributes, optionally filtered by group (product, checkout, cart, customer, collection, price). The EtchWP / Bricks Pro skills use this to design SureCart product pages, pricing tables, buy buttons, checkout and customer dashboards.', 'nibwp'),
    'category'    => 'ecommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'group'  => ['type' => 'string', 'description' => 'Filter: product | checkout | cart | customer | collection | price | review. Omit for all.'],
            'search' => ['type' => 'string'],
        ],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_sc_blocks_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_sc_blocks_execute(array $input): array|WP_Error
{
    if (!nibwp_sc_available()) {
        return nibwp_sc_err('surecart_inactive', 'SureCart is not active on this site.', 404);
    }
    $reg = WP_Block_Type_Registry::get_instance()->get_all_registered();
    $group  = strtolower((string) ($input['group'] ?? ''));
    $search = strtolower((string) ($input['search'] ?? ''));
    $out = [];
    foreach ($reg as $name => $type) {
        if (strpos($name, 'surecart/') !== 0) {
            continue;
        }
        $short = substr($name, strlen('surecart/'));
        if ($group !== '' && strpos($short, $group) === false) {
            continue;
        }
        if ($search !== '' && strpos($name, $search) === false && stripos((string) ($type->title ?? ''), $search) === false) {
            continue;
        }
        $out[$name] = [
            'title'      => $type->title ?? '',
            'attributes' => array_keys((array) ($type->attributes ?? [])),
        ];
    }
    return ['blocks' => $out, 'count' => count($out), 'note' => 'Bind product/price blocks with product_id / price_id / product_post_id attributes. surecart/product-page wraps a full product form.'];
}
