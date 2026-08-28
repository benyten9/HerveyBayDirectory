<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * FluentCart integration for NIBWP (Pro tier).
 *
 * Replaces the four read-only actions this integration used to carry in
 * plugin-integrations.php (list/get products, list/get orders), which covered
 * almost nothing of what a store actually needs: no customers, no coupons, no
 * subscriptions, no refunds, no stock, no revenue.
 *
 * Everything is read through FluentCart's own Eloquent models rather than raw
 * SQL, so table renames and prefix changes stay FluentCart's problem. Verified
 * against 1.6.1; the model set has been stable since 1.3.
 *
 * MONEY SAFETY: this is a store. Canceling a subscription, refunding, or
 * deleting a customer is not reversible from here, so every destructive action
 * is confirm-gated, and the ones that move money are named explicitly rather
 * than hidden behind a generic "update".
 *
 * REQUIRES: FluentCart active. Subscriptions and licenses additionally require
 * FluentCart Pro.
 */

/** Model class name, or null when FluentCart is not loaded. */
function nibwp_fc_model(string $name): ?string
{
    $class = 'FluentCart\\App\\Models\\' . $name;

    return class_exists($class) ? $class : null;
}

/** One guard for every action: is FluentCart even here? */
function nibwp_fc_guard(): ?WP_Error
{
    if (!defined('FLUENTCART_VERSION') && !class_exists('FluentCart\\App\\App')) {
        return new WP_Error(
            'nibwp_fc_missing',
            __('FluentCart is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Pro-only surfaces (subscriptions, licenses) fail with their own reason. */
function nibwp_fc_pro_guard(): ?WP_Error
{
    if (!defined('FLUENTCART_PRO_VERSION') && !class_exists('FluentCartPro\\App\\App')) {
        return new WP_Error(
            'nibwp_fc_pro_missing',
            __('This needs FluentCart Pro, which is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/**
 * FluentCart stores money as integer minor units (cents).
 *
 * Returning the raw integer invites an agent to report "2900" as the price of
 * a €29 product, so every amount is returned both ways.
 */
function nibwp_fc_money(?int $cents): array
{
    $cents = (int) $cents;

    return ['cents' => $cents, 'amount' => round($cents / 100, 2)];
}

/** Trim a model row to something an agent can read without drowning. */
function nibwp_fc_shape(string $kind, $row): array
{
    if (!is_object($row)) {
        return [];
    }

    switch ($kind) {
        case 'product':
            return [
                'id'          => (int) $row->ID,
                'title'       => (string) $row->post_title,
                'slug'        => (string) $row->post_name,
                'status'      => (string) $row->post_status,
                'type'        => (string) ($row->post_type ?? 'fc_product'),
                'created_at'  => (string) $row->post_date,
                'permalink'   => get_permalink($row->ID) ?: null,
            ];

        case 'variation':
            return [
                'id'               => (int) $row->id,
                'product_id'       => (int) $row->post_id,
                'title'            => (string) $row->variation_title,
                'price'            => nibwp_fc_money($row->item_price ?? 0),
                'stock_status'     => (string) ($row->stock_status ?? ''),
                'stock_quantity'   => $row->total_stock === null ? null : (int) $row->total_stock,
                'manage_stock'     => (bool) ($row->manage_stock ?? false),
                'fulfillment_type' => (string) ($row->fulfillment_type ?? ''),
                'payment_type'     => (string) ($row->payment_type ?? ''),
            ];

        case 'order':
            return [
                'id'             => (int) $row->id,
                'status'         => (string) $row->status,
                'payment_status' => (string) $row->payment_status,
                'type'           => (string) ($row->type ?? ''),
                'mode'           => (string) ($row->mode ?? ''),
                'currency'       => (string) ($row->currency ?? ''),
                'total'          => nibwp_fc_money($row->total_amount ?? 0),
                'customer_id'    => (int) ($row->customer_id ?? 0),
                'created_at'     => (string) $row->created_at,
            ];

        case 'customer':
            return [
                'id'          => (int) $row->id,
                'email'       => (string) $row->email,
                'first_name'  => (string) ($row->first_name ?? ''),
                'last_name'   => (string) ($row->last_name ?? ''),
                'status'      => (string) ($row->status ?? ''),
                'user_id'     => (int) ($row->user_id ?? 0),
                'created_at'  => (string) $row->created_at,
            ];

        case 'coupon':
            return [
                'id'         => (int) $row->id,
                'code'       => (string) $row->code,
                'type'       => (string) ($row->type ?? ''),
                'amount'     => (string) ($row->amount ?? ''),
                'status'     => (string) ($row->status ?? ''),
                'start_date' => (string) ($row->start_date ?? ''),
                'end_date'   => (string) ($row->end_date ?? ''),
                'use_count'  => (int) ($row->use_count ?? 0),
            ];

        case 'subscription':
            return [
                'id'              => (int) $row->id,
                'status'          => (string) $row->status,
                'customer_id'     => (int) ($row->customer_id ?? 0),
                'parent_order_id' => (int) ($row->parent_order_id ?? 0),
                'billing_interval' => (string) ($row->billing_interval ?? ''),
                'recurring_total' => nibwp_fc_money($row->recurring_total ?? 0),
                'bill_times'      => (int) ($row->bill_times ?? 0),
                'bill_count'      => (int) ($row->bill_count ?? 0),
                'expiration_at'   => (string) ($row->expiration_at ?? ''),
                'created_at'      => (string) $row->created_at,
            ];

        case 'transaction':
            return [
                'id'             => (int) $row->id,
                'order_id'       => (int) ($row->order_id ?? 0),
                'status'         => (string) ($row->status ?? ''),
                'transaction_type' => (string) ($row->transaction_type ?? ''),
                'payment_method' => (string) ($row->payment_method ?? ''),
                'total'          => nibwp_fc_money($row->total ?? 0),
                'created_at'     => (string) $row->created_at,
            ];
    }

    return (array) $row;
}

wp_register_ability('nibwp/fluentcart-manage', [
    'label' => __('FluentCart – Store Manager', domain: 'nibwp'),
    'description' => __(
        'Run a FluentCart store: products and variations, stock, orders and their items and transactions, customers, coupons, subscriptions, abandoned carts, and revenue reporting.',
        domain: 'nibwp',
    ),
    'category' => 'ecommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Products + variations
                    'list_products', 'get_product', 'create_product', 'update_product', 'delete_product',
                    'list_variations', 'get_variation', 'update_variation', 'update_stock',
                    // Orders
                    'list_orders', 'get_order', 'list_order_items', 'update_order_status', 'list_transactions',
                    // Customers
                    'list_customers', 'get_customer', 'update_customer', 'customer_orders',
                    // Coupons
                    'list_coupons', 'get_coupon', 'create_coupon', 'update_coupon', 'delete_coupon',
                    // Subscriptions (Pro)
                    'list_subscriptions', 'get_subscription', 'cancel_subscription',
                    // Carts + activity
                    'list_abandoned_carts', 'list_activity',
                    // Reporting
                    'revenue_summary', 'top_products', 'store_info',
                ],
                'description' => 'The store operation to perform.',
            ],

            'product_id'      => ['type' => 'integer', 'description' => 'Product post ID.'],
            'variation_id'    => ['type' => 'integer', 'description' => 'Product variation ID.'],
            'order_id'        => ['type' => 'integer', 'description' => 'Order ID.'],
            'customer_id'     => ['type' => 'integer', 'description' => 'Customer ID.'],
            'coupon_id'       => ['type' => 'integer', 'description' => 'Coupon ID.'],
            'subscription_id' => ['type' => 'integer', 'description' => 'Subscription ID.'],

            'title'       => ['type' => 'string', 'description' => 'Product title.'],
            'content'     => ['type' => 'string', 'description' => 'Product description.'],
            'status'      => ['type' => 'string', 'description' => 'Status to set or filter by.'],
            'code'        => ['type' => 'string', 'description' => 'Coupon code.'],
            'type'        => ['type' => 'string', 'description' => 'Coupon type: percent or fixed.'],
            'amount'      => ['type' => 'number', 'description' => 'Coupon value.'],
            'price'       => ['type' => 'number', 'description' => 'Price in major units, e.g. 29.00 — converted to cents.'],
            'quantity'    => ['type' => 'integer', 'description' => 'Stock quantity for update_stock.'],
            'email'       => ['type' => 'string', 'description' => 'Customer email.'],
            'first_name'  => ['type' => 'string'],
            'last_name'   => ['type' => 'string'],

            'search'   => ['type' => 'string', 'description' => 'Free-text filter where the action supports it.'],
            'days'     => ['type' => 'integer', 'default' => 30, 'description' => 'Window for revenue_summary and top_products.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],

            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required for anything irreversible: delete_product, delete_coupon, cancel_subscription.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_fluentcart_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run a FluentCart store.',
                '',
                'MONEY IS IN CENTS. Every amount comes back as {cents, amount}; read `amount`',
                'when talking to a person. When writing a price, send major units (29.00).',
                '',
                'IRREVERSIBLE — needs confirm=true:',
                '- delete_product, delete_coupon, cancel_subscription',
                'cancel_subscription stops future billing for a paying customer. Check',
                'get_subscription first and say what you are about to cancel.',
                '',
                'ORDER STATUS: use update_order_status with one of processing, completed,',
                'on-hold, canceled, failed. It does not move money — refunding is done in',
                'the payment gateway, and this will not pretend otherwise.',
                '',
                'Subscriptions need FluentCart Pro; without it those actions say so.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_fluentcart_manage(array $input): array|WP_Error
{
    if ($guard = nibwp_fc_guard()) {
        return $guard;
    }

    $action   = (string) ($input['action'] ?? '');
    $per_page = max(1, min(100, (int) ($input['per_page'] ?? 20)));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $confirm  = (bool) ($input['confirm'] ?? false);

    $irreversible = ['delete_product', 'delete_coupon', 'cancel_subscription'];
    if (in_array($action, $irreversible, strict: true) && !$confirm) {
        return new WP_Error(
            'nibwp_fc_unconfirmed',
            __('This cannot be undone from here. Re-issue the call with confirm set to true if that is intended.', domain: 'nibwp')
        );
    }

    try {
        return nibwp_fc_dispatch($action, $input, $per_page, $page);
    } catch (\Throwable $e) {
        // FluentCart throws from deep inside Eloquent; a raw stack trace helps
        // nobody, but swallowing the reason helps less.
        return new WP_Error('nibwp_fc_failed', sprintf(
            /* translators: 1: action name, 2: error message. */
            __('FluentCart could not complete %1$s: %2$s', domain: 'nibwp'),
            $action,
            $e->getMessage()
        ));
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_fc_dispatch(string $action, array $in, int $per_page, int $page): array|WP_Error
{
    $offset = ($page - 1) * $per_page;

    switch ($action) {
        // ── Products ──────────────────────────────────────────────────
        case 'list_products':
            $q = get_posts([
                'post_type'      => 'fc_product',
                'post_status'    => $in['status'] ?? 'any',
                's'              => $in['search'] ?? '',
                'posts_per_page' => $per_page,
                'offset'         => $offset,
            ]);

            return [
                'products' => array_map(static fn($p) => nibwp_fc_shape('product', $p), $q),
                'total'    => (int) wp_count_posts('fc_product')->publish,
            ];

        case 'get_product':
            $id = (int) ($in['product_id'] ?? 0);
            $post = $id > 0 ? get_post($id) : null;
            if (!$post || $post->post_type !== 'fc_product') {
                return new WP_Error('nibwp_fc_no_product', __('No FluentCart product with that ID.', domain: 'nibwp'));
            }

            $variation = nibwp_fc_model('ProductVariation');

            return [
                'product'    => nibwp_fc_shape('product', $post) + ['content' => (string) $post->post_content],
                'variations' => $variation
                    ? array_map(
                        static fn($v) => nibwp_fc_shape('variation', $v),
                        $variation::query()->where('post_id', $id)->get()->all()
                    )
                    : [],
            ];

        case 'create_product':
            $title = trim((string) ($in['title'] ?? ''));
            if ($title === '') {
                return new WP_Error('nibwp_fc_no_title', __('A product needs a title.', domain: 'nibwp'));
            }

            $id = wp_insert_post([
                'post_type'    => 'fc_product',
                'post_title'   => $title,
                'post_content' => (string) ($in['content'] ?? ''),
                'post_status'  => (string) ($in['status'] ?? 'draft'),
            ], true);

            if (is_wp_error($id)) {
                return $id;
            }

            return [
                'created' => true,
                'product' => nibwp_fc_shape('product', get_post($id)),
                'note'    => __('Created without a variation, so it has no price yet and cannot be bought until one is added in FluentCart.', domain: 'nibwp'),
            ];

        case 'update_product':
            $id = (int) ($in['product_id'] ?? 0);
            if ($id <= 0 || get_post_type($id) !== 'fc_product') {
                return new WP_Error('nibwp_fc_no_product', __('No FluentCart product with that ID.', domain: 'nibwp'));
            }

            $patch = ['ID' => $id];
            foreach (['title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status'] as $k => $col) {
                if (isset($in[$k])) {
                    $patch[$col] = (string) $in[$k];
                }
            }
            if (count($patch) === 1) {
                return new WP_Error('nibwp_fc_nothing', __('Nothing to update — send title, content or status.', domain: 'nibwp'));
            }

            wp_update_post($patch);

            return ['updated' => true, 'product' => nibwp_fc_shape('product', get_post($id))];

        case 'delete_product':
            $id = (int) ($in['product_id'] ?? 0);
            if ($id <= 0 || get_post_type($id) !== 'fc_product') {
                return new WP_Error('nibwp_fc_no_product', __('No FluentCart product with that ID.', domain: 'nibwp'));
            }
            wp_delete_post($id, true);

            return ['deleted' => true, 'product_id' => $id];

        // ── Variations + stock ────────────────────────────────────────
        case 'list_variations':
        case 'get_variation':
        case 'update_variation':
        case 'update_stock':
            return nibwp_fc_variations($action, $in, $per_page, $offset);

        // ── Orders ────────────────────────────────────────────────────
        case 'list_orders':
        case 'get_order':
        case 'list_order_items':
        case 'update_order_status':
        case 'list_transactions':
            return nibwp_fc_orders($action, $in, $per_page, $offset);

        // ── Customers ─────────────────────────────────────────────────
        case 'list_customers':
        case 'get_customer':
        case 'update_customer':
        case 'customer_orders':
            return nibwp_fc_customers($action, $in, $per_page, $offset);

        // ── Coupons ───────────────────────────────────────────────────
        case 'list_coupons':
        case 'get_coupon':
        case 'create_coupon':
        case 'update_coupon':
        case 'delete_coupon':
            return nibwp_fc_coupons($action, $in, $per_page, $offset);

        // ── Subscriptions (Pro) ───────────────────────────────────────
        case 'list_subscriptions':
        case 'get_subscription':
        case 'cancel_subscription':
            return nibwp_fc_subscriptions($action, $in, $per_page, $offset);

        // ── Carts, activity, reporting ────────────────────────────────
        case 'list_abandoned_carts':
            $cart = nibwp_fc_model('Cart');
            if (!$cart) {
                return new WP_Error('nibwp_fc_no_model', __('FluentCart carts are unavailable on this version.', domain: 'nibwp'));
            }
            $rows = $cart::query()->orderBy('id', 'desc')->limit($per_page)->offset($offset)->get();

            return [
                'carts' => array_map(static fn($c) => [
                    'id'         => (int) $c->id,
                    'status'     => (string) ($c->status ?? ''),
                    'email'      => (string) ($c->email ?? ''),
                    'total'      => nibwp_fc_money($c->total ?? 0),
                    'created_at' => (string) $c->created_at,
                ], $rows->all()),
                'total' => (int) $cart::query()->count(),
            ];

        case 'list_activity':
            $activity = nibwp_fc_model('Activity');
            if (!$activity) {
                return new WP_Error('nibwp_fc_no_model', __('FluentCart activity log is unavailable on this version.', domain: 'nibwp'));
            }
            $rows = $activity::query()->orderBy('id', 'desc')->limit($per_page)->offset($offset)->get();

            return [
                'activity' => array_map(static fn($a) => [
                    'id'         => (int) $a->id,
                    'title'      => (string) ($a->title ?? ''),
                    'status'     => (string) ($a->status ?? ''),
                    'module_type' => (string) ($a->module_type ?? ''),
                    'created_at' => (string) $a->created_at,
                ], $rows->all()),
            ];

        case 'revenue_summary':
        case 'top_products':
        case 'store_info':
            return nibwp_fc_reports($action, $in);
    }

    return new WP_Error('nibwp_fc_unknown_action', sprintf(
        /* translators: %s: the action that was requested. */
        __('Unknown FluentCart action: %s', domain: 'nibwp'),
        $action
    ));
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fc_variations(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    $model = nibwp_fc_model('ProductVariation');
    if (!$model) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart variations are unavailable on this version.', domain: 'nibwp'));
    }

    if ($action === 'list_variations') {
        $q = $model::query();
        if (!empty($in['product_id'])) {
            $q->where('post_id', (int) $in['product_id']);
        }

        return [
            'variations' => array_map(
                static fn($v) => nibwp_fc_shape('variation', $v),
                $q->limit($per_page)->offset($offset)->get()->all()
            ),
        ];
    }

    $id = (int) ($in['variation_id'] ?? 0);
    $row = $id > 0 ? $model::query()->find($id) : null;
    if (!$row) {
        return new WP_Error('nibwp_fc_no_variation', __('No variation with that ID.', domain: 'nibwp'));
    }

    if ($action === 'get_variation') {
        return ['variation' => nibwp_fc_shape('variation', $row)];
    }

    if ($action === 'update_stock') {
        if (!isset($in['quantity'])) {
            return new WP_Error('nibwp_fc_no_qty', __('update_stock needs a quantity.', domain: 'nibwp'));
        }
        $qty = max(0, (int) $in['quantity']);
        $row->total_stock  = $qty;
        $row->manage_stock = 1;
        // Keep the status honest rather than leaving "instock" on a zero shelf.
        $row->stock_status = $qty > 0 ? 'instock' : 'outofstock';
        $row->save();

        return ['updated' => true, 'variation' => nibwp_fc_shape('variation', $row->refresh())];
    }

    // update_variation
    $touched = false;
    if (isset($in['price'])) {
        $row->item_price = (int) round(((float) $in['price']) * 100);
        $touched = true;
    }
    if (isset($in['title'])) {
        $row->variation_title = (string) $in['title'];
        $touched = true;
    }
    if (!$touched) {
        return new WP_Error('nibwp_fc_nothing', __('Nothing to update — send price or title.', domain: 'nibwp'));
    }
    $row->save();

    return ['updated' => true, 'variation' => nibwp_fc_shape('variation', $row->refresh())];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fc_orders(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    $model = nibwp_fc_model('Order');
    if (!$model) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart orders are unavailable on this version.', domain: 'nibwp'));
    }

    if ($action === 'list_orders') {
        $q = $model::query()->orderBy('id', 'desc');
        foreach (['status' => 'status', 'payment_status' => 'payment_status'] as $key => $col) {
            if (!empty($in[$key])) {
                $q->where($col, (string) $in[$key]);
            }
        }
        if (!empty($in['customer_id'])) {
            $q->where('customer_id', (int) $in['customer_id']);
        }

        return [
            'orders' => array_map(
                static fn($o) => nibwp_fc_shape('order', $o),
                (clone $q)->limit($per_page)->offset($offset)->get()->all()
            ),
            'total' => (int) $q->count(),
        ];
    }

    $id = (int) ($in['order_id'] ?? 0);
    $order = $id > 0 ? $model::query()->find($id) : null;
    if (!$order) {
        return new WP_Error('nibwp_fc_no_order', __('No order with that ID.', domain: 'nibwp'));
    }

    if ($action === 'get_order') {
        $items = nibwp_fc_model('OrderItem');
        $customer = nibwp_fc_model('Customer');

        return [
            'order' => nibwp_fc_shape('order', $order),
            'items' => $items
                ? array_map(static fn($i) => [
                    'id'         => (int) $i->id,
                    'product_id' => (int) ($i->post_id ?? 0),
                    'title'      => (string) ($i->post_title ?? ''),
                    'quantity'   => (int) ($i->quantity ?? 0),
                    'line_total' => nibwp_fc_money($i->line_total ?? 0),
                ], $items::query()->where('order_id', $id)->get()->all())
                : [],
            'customer' => $customer && $order->customer_id
                ? nibwp_fc_shape('customer', $customer::query()->find((int) $order->customer_id))
                : null,
        ];
    }

    if ($action === 'list_order_items') {
        $items = nibwp_fc_model('OrderItem');

        return [
            'items' => $items
                ? array_map(static fn($i) => (array) $i->getAttributes(), $items::query()->where('order_id', $id)->get()->all())
                : [],
        ];
    }

    if ($action === 'list_transactions') {
        $tx = nibwp_fc_model('OrderTransaction');

        return [
            'transactions' => $tx
                ? array_map(
                    static fn($t) => nibwp_fc_shape('transaction', $t),
                    $tx::query()->where('order_id', $id)->get()->all()
                )
                : [],
        ];
    }

    // update_order_status
    $status = (string) ($in['status'] ?? '');
    $allowed = ['processing', 'completed', 'on-hold', 'canceled', 'failed'];
    if (!in_array($status, $allowed, strict: true)) {
        return new WP_Error('nibwp_fc_bad_status', sprintf(
            /* translators: %s: comma-separated list of allowed statuses. */
            __('Order status must be one of: %s', domain: 'nibwp'),
            implode(', ', $allowed)
        ));
    }

    $was = (string) $order->status;
    $order->status = $status;
    $order->save();

    return [
        'updated' => true,
        'order'   => nibwp_fc_shape('order', $order->refresh()),
        'was'     => $was,
        'note'    => __('Status only. No money moved — refunds happen in the payment gateway.', domain: 'nibwp'),
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fc_customers(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    $model = nibwp_fc_model('Customer');
    if (!$model) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart customers are unavailable on this version.', domain: 'nibwp'));
    }

    if ($action === 'list_customers') {
        $q = $model::query()->orderBy('id', 'desc');
        if (!empty($in['search'])) {
            $needle = '%' . (string) $in['search'] . '%';
            $q->where(static function ($sub) use ($needle) {
                $sub->where('email', 'LIKE', $needle)
                    ->orWhere('first_name', 'LIKE', $needle)
                    ->orWhere('last_name', 'LIKE', $needle);
            });
        }

        return [
            'customers' => array_map(
                static fn($c) => nibwp_fc_shape('customer', $c),
                (clone $q)->limit($per_page)->offset($offset)->get()->all()
            ),
            'total' => (int) $q->count(),
        ];
    }

    $id = (int) ($in['customer_id'] ?? 0);
    $customer = $id > 0 ? $model::query()->find($id) : null;
    if (!$customer) {
        return new WP_Error('nibwp_fc_no_customer', __('No customer with that ID.', domain: 'nibwp'));
    }

    if ($action === 'get_customer') {
        $addr = nibwp_fc_model('CustomerAddresses');

        return [
            'customer'  => nibwp_fc_shape('customer', $customer),
            'addresses' => $addr
                ? array_map(static fn($a) => (array) $a->getAttributes(), $addr::query()->where('customer_id', $id)->get()->all())
                : [],
        ];
    }

    if ($action === 'customer_orders') {
        $orders = nibwp_fc_model('Order');

        return [
            'orders' => $orders
                ? array_map(
                    static fn($o) => nibwp_fc_shape('order', $o),
                    $orders::query()->where('customer_id', $id)->orderBy('id', 'desc')->limit($per_page)->get()->all()
                )
                : [],
        ];
    }

    // update_customer
    $touched = false;
    foreach (['email', 'first_name', 'last_name', 'status'] as $field) {
        if (isset($in[$field])) {
            $customer->{$field} = (string) $in[$field];
            $touched = true;
        }
    }
    if (!$touched) {
        return new WP_Error('nibwp_fc_nothing', __('Nothing to update — send email, first_name, last_name or status.', domain: 'nibwp'));
    }
    $customer->save();

    return ['updated' => true, 'customer' => nibwp_fc_shape('customer', $customer->refresh())];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fc_coupons(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    $model = nibwp_fc_model('Coupon');
    if (!$model) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart coupons are unavailable on this version.', domain: 'nibwp'));
    }

    if ($action === 'list_coupons') {
        return [
            'coupons' => array_map(
                static fn($c) => nibwp_fc_shape('coupon', $c),
                $model::query()->orderBy('id', 'desc')->limit($per_page)->offset($offset)->get()->all()
            ),
            'total' => (int) $model::query()->count(),
        ];
    }

    if ($action === 'create_coupon') {
        $code = trim((string) ($in['code'] ?? ''));
        if ($code === '') {
            return new WP_Error('nibwp_fc_no_code', __('A coupon needs a code.', domain: 'nibwp'));
        }
        if ($model::query()->where('code', $code)->exists()) {
            return new WP_Error('nibwp_fc_dupe_code', __('A coupon with that code already exists.', domain: 'nibwp'));
        }

        $coupon = $model::query()->create([
            'code'   => $code,
            'title'  => (string) ($in['title'] ?? $code),
            'type'   => (string) ($in['type'] ?? 'percent'),
            'amount' => (string) ($in['amount'] ?? '0'),
            'status' => (string) ($in['status'] ?? 'active'),
        ]);

        return ['created' => true, 'coupon' => nibwp_fc_shape('coupon', $coupon)];
    }

    $id = (int) ($in['coupon_id'] ?? 0);
    $coupon = $id > 0 ? $model::query()->find($id) : null;
    if (!$coupon) {
        return new WP_Error('nibwp_fc_no_coupon', __('No coupon with that ID.', domain: 'nibwp'));
    }

    if ($action === 'get_coupon') {
        return ['coupon' => nibwp_fc_shape('coupon', $coupon)];
    }

    if ($action === 'delete_coupon') {
        $coupon->delete();

        return ['deleted' => true, 'coupon_id' => $id];
    }

    // update_coupon
    $touched = false;
    foreach (['code', 'title', 'type', 'amount', 'status'] as $field) {
        if (isset($in[$field])) {
            $coupon->{$field} = (string) $in[$field];
            $touched = true;
        }
    }
    if (!$touched) {
        return new WP_Error('nibwp_fc_nothing', __('Nothing to update — send code, title, type, amount or status.', domain: 'nibwp'));
    }
    $coupon->save();

    return ['updated' => true, 'coupon' => nibwp_fc_shape('coupon', $coupon->refresh())];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fc_subscriptions(string $action, array $in, int $per_page, int $offset): array|WP_Error
{
    if ($guard = nibwp_fc_pro_guard()) {
        return $guard;
    }

    $model = nibwp_fc_model('Subscription');
    if (!$model) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart subscriptions are unavailable on this version.', domain: 'nibwp'));
    }

    if ($action === 'list_subscriptions') {
        $q = $model::query()->orderBy('id', 'desc');
        if (!empty($in['status'])) {
            $q->where('status', (string) $in['status']);
        }
        if (!empty($in['customer_id'])) {
            $q->where('customer_id', (int) $in['customer_id']);
        }

        return [
            'subscriptions' => array_map(
                static fn($s) => nibwp_fc_shape('subscription', $s),
                (clone $q)->limit($per_page)->offset($offset)->get()->all()
            ),
            'total' => (int) $q->count(),
        ];
    }

    $id = (int) ($in['subscription_id'] ?? 0);
    $sub = $id > 0 ? $model::query()->find($id) : null;
    if (!$sub) {
        return new WP_Error('nibwp_fc_no_subscription', __('No subscription with that ID.', domain: 'nibwp'));
    }

    if ($action === 'get_subscription') {
        return ['subscription' => nibwp_fc_shape('subscription', $sub)];
    }

    // cancel_subscription — confirm-gated upstream.
    $was = (string) $sub->status;
    if ($was === 'canceled') {
        return ['subscription' => nibwp_fc_shape('subscription', $sub), 'note' => __('Already canceled; nothing changed.', domain: 'nibwp')];
    }

    $sub->status = 'canceled';
    $sub->save();

    return [
        'canceled'     => true,
        'subscription' => nibwp_fc_shape('subscription', $sub->refresh()),
        'was'          => $was,
        'note'         => __('Marked canceled in FluentCart. If the gateway holds the mandate, cancel it there too or it may keep charging.', domain: 'nibwp'),
    ];
}

/** @return array<string, mixed>|WP_Error */
function nibwp_fc_reports(string $action, array $in): array|WP_Error
{
    $orders = nibwp_fc_model('Order');
    if (!$orders) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart orders are unavailable on this version.', domain: 'nibwp'));
    }

    if ($action === 'store_info') {
        $products = wp_count_posts('fc_product');
        $customer = nibwp_fc_model('Customer');
        $sub      = nibwp_fc_model('Subscription');

        return [
            'fluentcart_version' => defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : null,
            'pro_active'         => defined('FLUENTCART_PRO_VERSION') || class_exists('FluentCartPro\\App\\App'),
            'currency'           => (string) (get_option('fluent_cart_settings')['currency'] ?? ''),
            'counts'             => [
                'products'      => (int) ($products->publish ?? 0),
                'orders'        => (int) $orders::query()->count(),
                'customers'     => $customer ? (int) $customer::query()->count() : null,
                'subscriptions' => $sub ? (int) $sub::query()->count() : null,
            ],
        ];
    }

    $days  = max(1, min(365, (int) ($in['days'] ?? 30)));
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    if ($action === 'revenue_summary') {
        // Paid orders only — counting pending carts as revenue is how a store
        // owner ends up believing a number that never arrived.
        $q = $orders::query()->where('payment_status', 'paid')->where('created_at', '>=', $since);
        $rows = $q->get();

        $gross = 0;
        foreach ($rows as $row) {
            $gross += (int) ($row->total_amount ?? 0);
        }
        $count = count($rows);

        return [
            'window_days'   => $days,
            'orders_paid'   => $count,
            'gross'         => nibwp_fc_money($gross),
            'average_order' => nibwp_fc_money($count > 0 ? (int) round($gross / $count) : 0),
        ];
    }

    // top_products
    $items = nibwp_fc_model('OrderItem');
    if (!$items) {
        return new WP_Error('nibwp_fc_no_model', __('FluentCart order items are unavailable on this version.', domain: 'nibwp'));
    }

    $tally = [];
    foreach ($items::query()->where('created_at', '>=', $since)->get() as $item) {
        $pid = (int) ($item->post_id ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($tally[$pid])) {
            $tally[$pid] = ['product_id' => $pid, 'title' => (string) ($item->post_title ?? ''), 'quantity' => 0, 'revenue_cents' => 0];
        }
        $tally[$pid]['quantity']      += (int) ($item->quantity ?? 0);
        $tally[$pid]['revenue_cents'] += (int) ($item->line_total ?? 0);
    }

    usort($tally, static fn($a, $b) => $b['revenue_cents'] <=> $a['revenue_cents']);

    return [
        'window_days' => $days,
        'products'    => array_map(static fn($t) => [
            'product_id' => $t['product_id'],
            'title'      => $t['title'],
            'quantity'   => $t['quantity'],
            'revenue'    => nibwp_fc_money($t['revenue_cents']),
        ], array_slice($tally, 0, 10)),
    ];
}
