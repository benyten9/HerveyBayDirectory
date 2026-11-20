<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Easy Digital Downloads.
 *
 * Verified against Easy Digital Downloads 3.7.1 (readme.txt "Stable Tag: 3.7.1",
 * easy-digital-downloads.php:30).
 *
 * WHICH TABLES THIS READS, AND WHY IT MATTERS MORE HERE THAN ANYWHERE ELSE:
 * 3.x moved orders off the posts table into custom tables of its own. An order is
 * a row in {prefix}edd_orders (src/Database/Tables/Orders.php:32, columns in
 * src/Database/Schemas/Orders.php), its lines are rows in edd_order_items
 * (src/Database/Schemas/Order_Items.php), and the query object that reads them is
 * EDD\Database\Queries\Order (src/Database/Queries/Order.php:36 `$table_name =
 * 'orders'`). edd_get_order() and edd_get_orders() go through that query object
 * (includes/orders/functions/orders.php:431, 482), so they are what this file
 * uses. The 2.x helpers edd_get_payment()/edd_get_payments() still exist
 * (includes/payments/functions.php:25, 71) but they are compatibility wrappers
 * over the same tables, and edd_get_earnings_by_date() is outright deprecated
 * (includes/deprecated-functions.php:567) — which is why the period figures here
 * are aggregated from edd_orders directly instead.
 *
 * This is not a detail. A payments action written against the old `edd_payment`
 * post type returns an empty list on a 3.x store and says nothing is wrong: the
 * posts are gone, the query succeeds, the answer is zero. Downloads ARE still a
 * post type (`download`), so this file reads products through the posts table and
 * orders through the custom tables, deliberately and in different places.
 *
 * WHAT NEVER LEAVES, however it is asked for:
 *   - the order's payment_key (edd_orders column, Schemas/Orders.php:145). It is
 *     what a receipt URL and a file-download link are signed with;
 *   - a gateway transaction reference (edd_order_transactions.transaction_id,
 *     Schemas/Order_Transactions.php:66);
 *   - a stored card: edd_payment_tokens holds token_id and gateway_customer_id
 *     per customer (Schemas/PaymentTokens.php:66, 75);
 *   - the store's own gateway secrets, and a user's EDD API keys, which live in
 *     user meta as edd_user_public_key / edd_user_secret_key
 *     (includes/api/class-edd-api.php:2149-2150);
 *   - a visitor's IP, recorded against every order (Schemas/Orders.php:113).
 * Enforced two ways: an ALLOW-LIST per record type, so a column a later EDD
 * release adds defaults to not travelling, and a recursive pass over the finished
 * response that drops a secret-looking key at any depth. A non-scalar under an
 * allowed key is DROPPED, not cast — an array is how a token set rides out under
 * an innocent name.
 *
 * WHAT NEVER LEAVES, SECOND CATEGORY: a download's file URLs. On a store selling
 * digital goods the file URL *is* the product. get_download reports how many files
 * are attached and what they are called, and nothing in this file returns a URL, a
 * path, or a signed download link. Nor a product permalink — not because that is
 * secret, but because the check can then assert that no value in any download
 * response contains "://" at all, and an assertion that simple is one a later edit
 * cannot quietly weaken.
 *
 * WHAT IS NOT HERE, AND WILL NOT BE: nothing issues a refund, and nothing deletes
 * or trashes an order. EDD will do both from a function call
 * (includes/orders/functions/refunds.php), which is exactly the problem: money
 * leaving a store is a decision with a bank on the other end of it, and an agent
 * acting on a sentence it half-understood is the wrong shape of thing to make it.
 * refund_status reads the refund picture and stops there. The owner's wp-admin
 * order screen is one click away. A test parses the enum and fails if an action
 * whose name reads like a refund, a void, a delete or a payout ever appears.
 */

/** The floor under every action here. EDD defines it (includes/class-edd-roles.php:131-132). */
const NIBWP_EDD_CAP = 'manage_shop_settings';

/**
 * The extra gate on a named order or a named customer.
 *
 * EDD's own name for "may see who bought what" (includes/class-edd-roles.php:129,
 * 135). A store manager can hold manage_shop_settings without it, and one order
 * row carries a buyer's email and address, so the two gates are separate on
 * purpose and refuse with different codes.
 */
const NIBWP_EDD_CAP_SENSITIVE = 'view_shop_sensitive_data';

/** Writing a product. EDD's capability (includes/class-edd-roles.php:150). */
const NIBWP_EDD_CAP_PRODUCTS = 'edit_products';

/** Writing a discount. EDD's capability (includes/class-edd-roles.php:132, 137). */
const NIBWP_EDD_CAP_DISCOUNTS = 'manage_shop_discounts';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_EDD_MAX_ROWS = 200;

/** The longest window a report will cover, in days. */
const NIBWP_EDD_MAX_DAYS = 730;

/**
 * Is Easy Digital Downloads actually loaded?
 *
 * Three things, because they fail apart: the main class is what the plugin
 * defines first, the constant comes from the bootstrap
 * (easy-digital-downloads.php:151), and edd_get_orders() is the 3.x query layer
 * this file depends on. A site mid-migration can have the first two and not the
 * third, and that is worth distinguishing from "not installed".
 */
function nibwp_edd_guard(): ?WP_Error
{
    if (!class_exists('Easy_Digital_Downloads') && !defined('EDD_PLUGIN_FILE')) {
        return new WP_Error(
            'nibwp_edd_missing',
            __('Easy Digital Downloads is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    if (!function_exists('edd_get_orders')) {
        return new WP_Error(
            'nibwp_edd_missing',
            __('Easy Digital Downloads is present but its order query layer is not loaded, so nothing here can answer reliably.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_edd_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_edd_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_edd_forbidden(string $code, string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_edd_' . $code,
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/**
 * The floor. Every action passes through this, reads included.
 *
 * Reading a store is not a lower bar than changing one: the order list names
 * buyers and the revenue report is the business.
 */
function nibwp_edd_require(string $what): ?WP_Error
{
    return current_user_can(NIBWP_EDD_CAP) ? null : nibwp_edd_forbidden('forbidden', $what, NIBWP_EDD_CAP);
}

/**
 * The extra gate on a named order or customer.
 *
 * Its own error code, not the floor's. Two gates answering with one code means
 * denying everything lets whichever runs second look like it worked, and a
 * deleted gate then survives a mutation test.
 */
function nibwp_edd_require_sensitive(string $what): ?WP_Error
{
    return current_user_can(NIBWP_EDD_CAP_SENSITIVE)
        ? null
        : nibwp_edd_forbidden('sensitive_forbidden', $what, NIBWP_EDD_CAP_SENSITIVE);
}

/** The extra gate on writing a product. */
function nibwp_edd_require_products(string $what): ?WP_Error
{
    return current_user_can(NIBWP_EDD_CAP_PRODUCTS)
        ? null
        : nibwp_edd_forbidden('products_forbidden', $what, NIBWP_EDD_CAP_PRODUCTS);
}

/** The extra gate on writing a discount. */
function nibwp_edd_require_discounts(string $what): ?WP_Error
{
    return current_user_can(NIBWP_EDD_CAP_DISCOUNTS)
        ? null
        : nibwp_edd_forbidden('discounts_forbidden', $what, NIBWP_EDD_CAP_DISCOUNTS);
}

/* ------------------------------------------------------- what may leave, ever */

/**
 * Key fragments that mark a value as not ours to hand out.
 *
 * Matched as substrings against a lowercased key, at any depth. Broad on purpose:
 * a false positive costs one renamed response key, a false negative ships a card
 * token. The allow-lists below already decide what travels — this is the second
 * layer, for the shapes nobody listed because they did not exist yet.
 *
 * @return array<int, string>
 */
function nibwp_edd_secret_fragments(): array
{
    return [
        'payment_key',
        'token',
        'secret',
        'api_key',
        'apikey',
        'private_key',
        'client_secret',
        'password',
        'passwd',
        'user_pass',
        'card',
        'cvc',
        'cvv',
        'last4',
        'transaction_id',
        'gateway_customer',
        'credential',
        'iban',
        'routing_number',
        'account_number',
        'nonce',
        'authorization',
    ];
}

/**
 * Keys that must not travel but are too short to match as a fragment.
 *
 * `ip` is the orders table's visitor address (Schemas/Orders.php:113). It is not a
 * secret, it is a person, and no question this hub answers needs it.
 *
 * @return array<int, string>
 */
function nibwp_edd_secret_names(): array
{
    return ['ip', 'user_ip', 'customer_ip'];
}

/** Is this response key one that must never carry a value out? */
function nibwp_edd_is_secret_key(string $key): bool
{
    $lower = strtolower($key);

    if (in_array($lower, nibwp_edd_secret_names(), true)) {
        return true;
    }

    foreach (nibwp_edd_secret_fragments() as $fragment) {
        if (str_contains($lower, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * The last pass over a finished response, at every nesting depth.
 *
 * Three jobs: drop a secret-looking key wherever it sits, recurse into arrays so
 * a nested order line or price variation gets the same treatment, and drop any
 * remaining non-scalar leaf rather than letting it be serialised. An EDD object
 * handed straight into a response would otherwise be flattened by the REST layer
 * with every protected property on it, payment_key included.
 *
 * @param array<array-key, mixed> $value
 * @return array<array-key, mixed>
 */
function nibwp_edd_scrub(array $value): array
{
    $clean = [];

    foreach ($value as $key => $item) {
        if (is_string($key) && nibwp_edd_is_secret_key($key)) {
            continue;
        }

        if (is_array($item)) {
            $clean[$key] = nibwp_edd_scrub($item);
            continue;
        }

        if ($item === null || is_scalar($item)) {
            $clean[$key] = $item;
        }

        // Anything else — an object, a resource, a closure — is dropped. Casting
        // it would be the bug: (array) on an EDD row object hands back every
        // protected property it has.
    }

    return $clean;
}

/**
 * Read one field off an array or an EDD row object without letting it throw.
 *
 * EDD's row objects keep their columns protected and expose them through
 * __get()/__isset() on the shared base (src/Database/Base.php:73, 104), so
 * property access is the documented way in — but a version where a column was
 * renamed makes that raise, and an uncaught Error in an ability takes the whole
 * REST request with it.
 */
function nibwp_edd_field(array|object $raw, string $key): mixed
{
    if (is_array($raw)) {
        return $raw[$key] ?? null;
    }

    try {
        return isset($raw->$key) ? $raw->$key : null;
    } catch (\Throwable $error) {
        return null;
    }
}

/**
 * Shape a record from an allow-list, dropping anything that is not a scalar.
 *
 * The allow-list is the whole defence. A denylist would have had to name
 * payment_key before payment_key existed, and the next column EDD adds would
 * default to leaking.
 *
 * @param array<int, string> $allowed
 * @return array<string, mixed>
 */
function nibwp_edd_pick(array|object $raw, array $allowed): array
{
    $out = [];

    foreach ($allowed as $key) {
        $value = nibwp_edd_field($raw, $key);

        if ($value === null) {
            $out[$key] = null;
            continue;
        }

        // Dropped, not cast. An array under an allowed key is how a token set or a
        // capability map travels, and (string) on it would still put something in
        // the response that a reader trusts.
        $out[$key] = is_scalar($value) ? $value : null;
    }

    return $out;
}

/**
 * Columns of edd_orders that may be reported (Schemas/Orders.php).
 *
 * Absent on purpose: payment_key (:145) and ip (:113).
 *
 * @return array<int, string>
 */
function nibwp_edd_order_fields(): array
{
    return [
        'id',
        'parent',
        'order_number',
        'status',
        'type',
        'user_id',
        'customer_id',
        'email',
        'currency',
        'gateway',
        'mode',
        'subtotal',
        'discount',
        'tax',
        'total',
        'date_created',
        'date_modified',
        'date_completed',
    ];
}

/**
 * Columns of edd_order_items that may be reported (Schemas/Order_Items.php).
 *
 * @return array<int, string>
 */
function nibwp_edd_order_item_fields(): array
{
    return [
        'id',
        'order_id',
        'product_id',
        'product_name',
        'price_id',
        'quantity',
        'amount',
        'subtotal',
        'discount',
        'tax',
        'total',
        'status',
    ];
}

/**
 * Columns of edd_customers that may be reported (Schemas/Customers.php).
 *
 * @return array<int, string>
 */
function nibwp_edd_customer_fields(): array
{
    return [
        'id',
        'user_id',
        'email',
        'name',
        'status',
        'purchase_count',
        'purchase_value',
        'date_created',
        'date_modified',
    ];
}

/**
 * A download, as this hub reports one.
 *
 * No permalink and no content: both can carry a URL, and withholding them lets
 * the check assert that no value in a download response contains "://" at all.
 *
 * @return array<int, string>
 */
function nibwp_edd_download_fields(): array
{
    return [
        'id',
        'title',
        'slug',
        'status',
        'type',
        'author_id',
        'sku',
        'price',
        'has_variable_prices',
        'sales',
        'earnings',
        'date_created',
        'date_modified',
    ];
}

/**
 * A discount, as this hub reports one (Schemas/Adjustments.php; discounts are
 * adjustments of type 'discount', includes/discount-functions.php:440).
 *
 * @return array<int, string>
 */
function nibwp_edd_discount_fields(): array
{
    return [
        'id',
        'name',
        'code',
        'status',
        'type',
        'scope',
        'amount_type',
        'amount',
        'description',
        'max_uses',
        'use_count',
        'once_per_customer',
        'min_charge_amount',
        'start_date',
        'end_date',
        'date_created',
    ];
}

/** @return array<string, mixed> */
function nibwp_edd_order_record(array|object $raw): array
{
    return nibwp_edd_pick($raw, nibwp_edd_order_fields());
}

/** @return array<string, mixed> */
function nibwp_edd_order_item_record(array|object $raw): array
{
    return nibwp_edd_pick($raw, nibwp_edd_order_item_fields());
}

/** @return array<string, mixed> */
function nibwp_edd_customer_record(array|object $raw): array
{
    return nibwp_edd_pick($raw, nibwp_edd_customer_fields());
}

/** @return array<string, mixed> */
function nibwp_edd_download_record(array|object $raw): array
{
    return nibwp_edd_pick($raw, nibwp_edd_download_fields());
}

/** @return array<string, mixed> */
function nibwp_edd_discount_record(array|object $raw): array
{
    return nibwp_edd_pick($raw, nibwp_edd_discount_fields());
}

/* ----------------------------------------------------- files, without the URLs */

/**
 * What one attached file is called, with nothing of where it lives.
 *
 * EDD stores a file as ['name' => ..., 'file' => <url>, 'condition' => ...] in the
 * edd_download_files meta (includes/admin/import/class-batch-import-downloads.php:348),
 * and edd_get_file_name() falls back to basename() of the URL when there is no
 * name (includes/download-functions.php:915-925). The fallback is reproduced here
 * rather than called, because the name a store owner typed can itself be a URL,
 * and the point is that nothing URL-shaped leaves regardless of which field it
 * came from.
 *
 * @param array<string, mixed> $file
 */
function nibwp_edd_file_label(array $file): string
{
    $name = isset($file['name']) && is_scalar($file['name']) ? trim((string) $file['name']) : '';

    if ($name === '') {
        $path = isset($file['file']) && is_scalar($file['file']) ? (string) $file['file'] : '';
        $name = $path === '' ? '' : (string) strtok(basename($path), '?');
    }

    if ($name === '' || $name === false) {
        return __('(unnamed file)', 'nibwp');
    }

    // Belt and braces: whatever the name came from, it leaves as a bare filename.
    if (str_contains($name, '://') || str_contains($name, '/') || str_contains($name, '\\')) {
        $name = (string) strtok(basename(str_replace('\\', '/', $name)), '?');
    }

    return $name === '' || str_contains($name, '://')
        ? __('(unnamed file)', 'nibwp')
        : $name;
}

/**
 * How many files a download has and what they are called. Never where they are.
 *
 * @param mixed $files Whatever edd_get_download_files() returned.
 * @return array<string, mixed>
 */
function nibwp_edd_file_summary(mixed $files): array
{
    $names = [];

    foreach (is_array($files) ? $files : [] as $file) {
        if (!is_array($file)) {
            continue;
        }

        $names[] = nibwp_edd_file_label($file);
    }

    return [
        'file_count' => count($names),
        'file_names' => $names,
        'file_urls_returned' => false,
        'files_note' => __('File names only. A download URL is the product on a store like this one, so no URL, path or signed link is returned here by any action — a customer gets theirs from their purchase receipt.', 'nibwp'),
    ];
}

/**
 * A download's price options, without the files each one unlocks.
 *
 * edd_get_variable_prices() returns the edd_variable_prices meta
 * (includes/download-functions.php:318-330), whose rows carry a name and an
 * amount. Only those two travel: the row can also carry a condition pointing at a
 * file, and that is file plumbing, not pricing.
 *
 * @param mixed $prices
 * @return array<int, array<string, mixed>>
 */
function nibwp_edd_variation_rows(mixed $prices): array
{
    $rows = [];

    foreach (is_array($prices) ? $prices : [] as $index => $price) {
        if (!is_array($price)) {
            continue;
        }

        $rows[] = [
            'price_id' => is_scalar($index) ? (string) $index : null,
            'name' => isset($price['name']) && is_scalar($price['name']) ? (string) $price['name'] : null,
            'amount' => isset($price['amount']) && is_scalar($price['amount']) ? (string) $price['amount'] : null,
        ];
    }

    return $rows;
}

/* ------------------------------------------------------------------ the inputs */

/** Clamp a row count that arrived over the wire. */
function nibwp_edd_limit(array $input, int $default = 25): int
{
    // per_page is what the first version of this integration called it, and a
    // customer prompt still says it. limit is what every integration written since
    // calls it. Both work; neither can raise the ceiling.
    $raw = $input['limit'] ?? ($input['per_page'] ?? null);
    $limit = is_numeric($raw) ? (int) $raw : $default;

    return max(1, min(NIBWP_EDD_MAX_ROWS, $limit));
}

/** Where a listing starts, from either offset or the older 1-based page. */
function nibwp_edd_offset(array $input, int $limit): int
{
    if (isset($input['offset']) && is_numeric($input['offset'])) {
        return max(0, (int) $input['offset']);
    }

    $page = isset($input['page']) && is_numeric($input['page']) ? (int) $input['page'] : 1;

    return max(0, (max(1, $page) - 1) * $limit);
}

/**
 * The window a report covers, as UTC strings.
 *
 * The custom order tables store datetimes in UTC (Schemas/Orders.php:213), so the
 * bounds are built with gmdate() and not with the site's timezone.
 *
 * @return array<string, mixed>
 */
function nibwp_edd_period(array $input, int $default = 30): array
{
    $raw = $input['days'] ?? null;
    $days = is_numeric($raw) ? (int) $raw : $default;
    $days = max(1, min(NIBWP_EDD_MAX_DAYS, $days));

    $now = time();
    $start = $now - ($days * DAY_IN_SECONDS);

    return [
        'days' => $days,
        'start' => gmdate('Y-m-d H:i:s', $start),
        'end' => gmdate('Y-m-d H:i:s', $now),
        'previous_start' => gmdate('Y-m-d H:i:s', $start - ($days * DAY_IN_SECONDS)),
        'previous_end' => gmdate('Y-m-d H:i:s', $start),
    ];
}

/**
 * The order statuses EDD recognises.
 *
 * Preferring the plugin's own list (includes/payments/functions.php:538) because
 * it is filtered, and an extension that adds a status would otherwise have its
 * orders refused as an invalid filter.
 *
 * @return array<int, string>
 */
function nibwp_edd_statuses(): array
{
    if (function_exists('edd_get_payment_statuses')) {
        $statuses = edd_get_payment_statuses();

        if (is_array($statuses) && $statuses !== []) {
            return array_values(array_map('strval', array_keys($statuses)));
        }
    }

    return [
        'pending',
        'processing',
        'complete',
        'refunded',
        'partially_refunded',
        'revoked',
        'failed',
        'abandoned',
        'on_hold',
    ];
}

/** The statuses that count as money the store actually kept. */
function nibwp_edd_earning_statuses(): array
{
    return ['complete', 'partially_refunded'];
}

/** Validate a status filter, or refuse it by name. */
function nibwp_edd_status(array $input): string|WP_Error
{
    if (!isset($input['status']) || !is_scalar($input['status'])) {
        return '';
    }

    $status = strtolower(trim((string) $input['status']));

    if ($status === '') {
        return '';
    }

    if (!in_array($status, nibwp_edd_statuses(), true)) {
        return nibwp_edd_err(
            'bad_status',
            sprintf(
                /* translators: 1: the rejected status, 2: list of valid statuses */
                __('"%1$s" is not an order status on this store. Valid statuses: %2$s.', 'nibwp'),
                $status,
                implode(', ', nibwp_edd_statuses())
            ),
            422
        );
    }

    return $status;
}

/** A positive integer id from input, under either of two names. */
function nibwp_edd_id(array $input, string $primary, string $fallback = ''): int
{
    foreach ([$primary, $fallback] as $key) {
        if ($key !== '' && isset($input[$key]) && is_numeric($input[$key])) {
            $id = (int) $input[$key];

            if ($id > 0) {
                return $id;
            }
        }
    }

    return 0;
}

/* ---------------------------------------------------------- the two table names */

/** {prefix}edd_orders (src/Database/Tables/Orders.php:32, prefixed in Table.php:825-828). */
function nibwp_edd_orders_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'edd_orders';
}

/** {prefix}edd_order_items. */
function nibwp_edd_order_items_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'edd_order_items';
}

/** Is there a database handle to aggregate against? */
function nibwp_edd_db(): ?WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare')) {
        return nibwp_edd_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    return null;
}

/**
 * Orders and revenue between two UTC datetimes.
 *
 * Aggregated from edd_orders rather than through EDD\Stats, because that class
 * boots the whole Reports API in its constructor (src/Stats.php:103
 * `new Reports\Init()`) — a lot of admin-shaped machinery to stand up inside a
 * REST request, and the figures are one GROUP-less SUM away in the same table.
 * The status list is a literal in the SQL on purpose: it is ours, not the
 * caller's, so there is nothing to interpolate.
 *
 * @return array<string, mixed>
 */
function nibwp_edd_window_totals(string $start, string $end): array
{
    global $wpdb;

    $sql = $wpdb->prepare(
        'SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM ' . nibwp_edd_orders_table()
        . " WHERE type = %s AND status IN ('complete', 'partially_refunded')"
        . ' AND date_created >= %s AND date_created <= %s',
        'sale',
        $start,
        $end
    );

    $row = $wpdb->get_row($sql, ARRAY_A);
    $row = is_array($row) ? $row : [];

    return [
        'orders' => (int) ($row['orders'] ?? 0),
        'revenue' => round((float) ($row['revenue'] ?? 0), 2),
    ];
}

/* ------------------------------------------------- fetching one named object */

/**
 * One download post, or a refusal.
 *
 * Declared `object` rather than `object|WP_Error`: WP_Error is itself an object,
 * so a union of the two is a fatal parse error. Callers check with instanceof.
 */
function nibwp_edd_download_post(int $download_id): object
{
    $post = get_post($download_id);

    if (!is_object($post) || (string) nibwp_edd_field($post, 'post_type') !== 'download') {
        return nibwp_edd_err(
            'not_found',
            sprintf(
                /* translators: %d: the download ID that was asked for */
                __('No download with ID %d exists on this store.', 'nibwp'),
                $download_id
            ),
            404
        );
    }

    return $post;
}

/** One order row, or a refusal. */
function nibwp_edd_order_row(int $order_id): object
{
    $order = edd_get_order($order_id);

    if (!is_object($order) || (int) nibwp_edd_field($order, 'id') <= 0) {
        return nibwp_edd_err(
            'not_found',
            sprintf(
                /* translators: %d: the order ID that was asked for */
                __('No order with ID %d exists on this store.', 'nibwp'),
                $order_id
            ),
            404
        );
    }

    return $order;
}

/** One customer row, or a refusal. */
function nibwp_edd_customer_row(int $customer_id): object
{
    if (!function_exists('edd_get_customer')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose its customers where this integration can read them.', 'nibwp'), 500);
    }

    $customer = edd_get_customer($customer_id);

    if (!is_object($customer) || (int) nibwp_edd_field($customer, 'id') <= 0) {
        return nibwp_edd_err(
            'not_found',
            sprintf(
                /* translators: %d: the customer ID that was asked for */
                __('No customer with ID %d exists on this store.', 'nibwp'),
                $customer_id
            ),
            404
        );
    }

    return $customer;
}

/** One discount row, or a refusal. */
function nibwp_edd_discount_row(int $discount_id): object
{
    if (!function_exists('edd_get_discount')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose its discounts where this integration can read them.', 'nibwp'), 500);
    }

    $discount = edd_get_discount($discount_id);

    if (!is_object($discount) || (int) nibwp_edd_field($discount, 'id') <= 0) {
        return nibwp_edd_err(
            'not_found',
            sprintf(
                /* translators: %d: the discount ID that was asked for */
                __('No discount with ID %d exists on this store.', 'nibwp'),
                $discount_id
            ),
            404
        );
    }

    return $discount;
}

/* ------------------------------------------------------------------- the hub */

/**
 * The actions this hub offers.
 *
 * The first five are the names this integration shipped with. They stay, spelled
 * exactly as they were, because a customer prompt already says them out loud.
 *
 * @return array<int, string>
 */
function nibwp_edd_actions(): array
{
    return [
        // Products.
        'list_downloads',
        'get_download',
        'create_download',
        'update_download_price',
        'update_download_status',
        // Orders — read-only on money.
        'list_payments',
        'get_payment',
        'order_totals',
        'refund_status',
        // Customers.
        'search_customers',
        'get_customer',
        'customer_orders',
        // Discounts.
        'list_discounts',
        'get_discount',
        'create_discount',
        'deactivate_discount',
        // Reports.
        'get_stats',
        'revenue_report',
        'best_sellers',
        'earnings_by_product',
        // Audit.
        'audit_store',
        'downloads_without_files',
        'expired_discounts_still_active',
        'stalled_orders',
    ];
}

wp_register_ability('nibwp/edd-manage', [
    'label' => __('Easy Digital Downloads – Store Management', 'nibwp'),
    'description' => 'Run an Easy Digital Downloads store: list and read downloads with their price variations, create one, change its price or its status; '
        . 'search orders and read one; count orders and revenue over a period against the period before it; read the refund picture; '
        . 'search customers, read one with its lifetime value, and list a customer\'s orders; list, read, create and deactivate discount codes; '
        . 'see best-selling downloads and earnings by product; and audit the store for downloads with no file attached, discounts that expired but are still advertised, and orders stuck pending. '
        . 'Read-only on money: nothing here issues a refund or deletes an order. '
        . 'A download\'s file URLs are never returned — only how many files there are and what they are called. '
        . 'No card detail, payment-gateway token, API key or gateway secret is ever returned, and neither is a buyer\'s IP address.',
    'category' => 'ecommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_edd_actions(),
                'description' => 'Which operation to run. Start with get_stats for the store, or audit_store for what needs attention.',
            ],
            'download_id' => ['type' => 'integer', 'description' => 'For the download actions: the download (product) ID.'],
            'payment_id' => ['type' => 'integer', 'description' => 'For get_payment and refund_status: the order ID. Called payment_id for continuity; in EDD 3.x it is an order ID.'],
            'customer_id' => ['type' => 'integer', 'description' => 'For get_customer and customer_orders: the EDD customer ID, not the WordPress user ID.'],
            'discount_id' => ['type' => 'integer', 'description' => 'For get_discount and deactivate_discount: the discount ID.'],
            'status' => ['type' => 'string', 'description' => 'For list_payments and update_download_status: an order status (complete, pending, refunded, …) or a post status (publish, draft, pending, private).'],
            'search' => ['type' => 'string', 'description' => 'For list_downloads and search_customers: match on title, name or email.'],
            'title' => ['type' => 'string', 'description' => 'For create_download: the product title.'],
            'content' => ['type' => 'string', 'description' => 'For create_download: the product description.'],
            'price' => ['type' => 'number', 'description' => 'For create_download and update_download_price: the single price.'],
            'code' => ['type' => 'string', 'description' => 'For create_discount: the code a buyer types.'],
            'name' => ['type' => 'string', 'description' => 'For create_discount: what the discount is called in wp-admin.'],
            'amount' => ['type' => 'number', 'description' => 'For create_discount: the discount amount.'],
            'amount_type' => ['type' => 'string', 'description' => 'For create_discount: percent or flat. Default percent.'],
            'max_uses' => ['type' => 'integer', 'description' => 'For create_discount: how many times it may be redeemed. 0 for unlimited.'],
            'end_date' => ['type' => 'string', 'description' => 'For create_discount: when it expires, as YYYY-MM-DD HH:MM:SS in UTC.'],
            'active' => ['type' => 'boolean', 'description' => 'For create_discount: true to create it live. Default false — a new code is created switched off.'],
            'days' => ['type' => 'integer', 'description' => 'For the report and audit actions: how many days back. Default 30, max 730.'],
            'hours' => ['type' => 'integer', 'description' => 'For stalled_orders: how long an order must have been pending to count. Default 48, max 720.'],
            'per_page' => ['type' => 'integer', 'description' => 'Rows to return. Default 25, max 200.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Same ceiling as per_page.'],
            'page' => ['type' => 'integer', 'description' => '1-based page for a listing.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts. Overrides page.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
            'downloads' => ['type' => 'array'],
            'download' => ['type' => 'object'],
            'payments' => ['type' => 'array'],
            'payment' => ['type' => 'object'],
            'customers' => ['type' => 'array'],
            'customer' => ['type' => 'object'],
            'discounts' => ['type' => 'array'],
            'discount' => ['type' => 'object'],
            'stats' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_edd_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Easy Digital Downloads — a store with real money in it.',
                '',
                'START WITH get_stats for the shape of the store, or audit_store when the',
                'question is "what needs attention". audit_store runs the three checks that',
                'cost a store money quietly: downloads_without_files (a product a buyer pays',
                'for and receives nothing), expired_discounts_still_active (a code still',
                'advertised that now refuses at checkout), and stalled_orders (money taken',
                'and never completed, or a gateway that stopped calling back).',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'It cannot issue a refund, void a payment, or delete or trash an order.',
                'There is no action that does, and there will not be — money leaving a store',
                'is not something to do from a tool call on a sentence. refund_status reads',
                'the refund picture and stops there. When asked to refund, say so and point',
                'at the order screen in wp-admin.',
                '',
                'FILE URLS: get_download reports how many files a download has and what they',
                'are called. It does not return the URLs, the paths, or a download link, and',
                'no other action does either. On a store selling digital goods the file URL',
                'is the product. Asking differently will not change it; a buyer gets theirs',
                'from their purchase receipt.',
                '',
                'SECRETS: no card detail, saved-card token, gateway transaction reference, API',
                'key or gateway secret is returned by anything here, at any depth, and nor is',
                'the order\'s payment_key.',
                '',
                'PERSONAL DATA: an order and a customer name a real buyer by email. Prefer',
                'order_totals and revenue_report, which are counts and sums, over list_payments',
                'when the question is about the business rather than about one person. Buyer IP',
                'addresses are never returned.',
                '',
                'THE READS, by name: list_downloads and get_download for products;',
                'list_payments and get_payment for orders; search_customers, get_customer and',
                'customer_orders for buyers; list_discounts and get_discount for codes;',
                'get_stats, revenue_report, order_totals, best_sellers and earnings_by_product',
                'for the numbers; refund_status for the refund picture.',
                '',
                'IDs: payment_id is an order ID — EDD 3.x moved orders into their own tables',
                'and the old payment post IDs carried over as order IDs. customer_id is the',
                'EDD customer ID from the customers table, not a WordPress user ID; get',
                'it from search_customers.',
                '',
                'WRITES, and what each one will refuse:',
                '- create_download makes a draft with a single price. It attaches no files, so',
                '  the product is not sellable until somebody uploads one in wp-admin.',
                '- update_download_price refuses on a variable-priced product, because setting',
                '  the base price there changes nothing a buyer sees.',
                '- create_discount creates the code SWITCHED OFF unless active is true.',
                '- deactivate_discount switches one off. Nothing here deletes one.',
                '',
                'EVERY LISTING IS BOUNDED at 200 rows and the bound cannot be raised from',
                'input. Page through with page or offset.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_manage(array $input): array|WP_Error
{
    $guard = nibwp_edd_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) && is_scalar($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_edd_actions(), true)) {
        return nibwp_edd_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Easy Digital Downloads action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_edd_actions())
            )
        );
    }

    // EDD's own internals reach the database, the gateways and a filter chain any
    // extension can hook. One catch here turns all of that into a named refusal
    // rather than a fatal that takes the whole REST request with it.
    try {
        return nibwp_edd_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_edd_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from the store */
                __('Easy Digital Downloads could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'list_downloads' => nibwp_edd_list_downloads($input),
        'get_download' => nibwp_edd_get_download($input),
        'create_download' => nibwp_edd_create_download($input),
        'update_download_price' => nibwp_edd_update_download_price($input),
        'update_download_status' => nibwp_edd_update_download_status($input),
        'list_payments' => nibwp_edd_list_payments($input),
        'get_payment' => nibwp_edd_get_payment($input),
        'order_totals' => nibwp_edd_order_totals($input),
        'refund_status' => nibwp_edd_refund_status($input),
        'search_customers' => nibwp_edd_search_customers($input),
        'get_customer' => nibwp_edd_get_customer($input),
        'customer_orders' => nibwp_edd_customer_orders($input),
        'list_discounts' => nibwp_edd_list_discounts($input),
        'get_discount' => nibwp_edd_get_discount($input),
        'create_discount' => nibwp_edd_create_discount($input),
        'deactivate_discount' => nibwp_edd_deactivate_discount($input),
        'get_stats' => nibwp_edd_get_stats($input),
        'revenue_report' => nibwp_edd_revenue_report($input),
        'best_sellers' => nibwp_edd_best_sellers($input),
        'earnings_by_product' => nibwp_edd_earnings_by_product($input),
        'audit_store' => nibwp_edd_audit_store($input),
        'downloads_without_files' => nibwp_edd_downloads_without_files($input),
        'expired_discounts_still_active' => nibwp_edd_expired_discounts($input),
        'stalled_orders' => nibwp_edd_stalled_orders($input),
        default => nibwp_edd_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    if ($result instanceof WP_Error) {
        return $result;
    }

    // The last pass. Every response goes through it, so a field added to a record
    // shape later cannot carry a secret out just because nobody re-read this file.
    return ['action' => $action] + nibwp_edd_scrub($result);
}

/* ---------------------------------------------------------------- downloads */

/**
 * The store's products.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_list_downloads(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Listing the store\'s downloads', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_edd_limit($input);
    $offset = nibwp_edd_offset($input, $limit);

    $args = [
        'post_type' => 'download',
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
    ];

    if (isset($input['search']) && is_scalar($input['search']) && trim((string) $input['search']) !== '') {
        $args['s'] = sanitize_text_field((string) $input['search']);
    }

    $posts = get_posts($args);
    $rows = [];

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!is_object($post)) {
            continue;
        }

        $rows[] = nibwp_edd_download_summary($post, false);
    }

    return [
        'downloads' => $rows,
        'returned' => count($rows),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'note' => __('One page of products. total counts what came back, not the whole catalogue — page through with page or offset. No file URLs are included.', 'nibwp'),
    ];
}

/**
 * Shape one download, with or without its price options and file summary.
 *
 * @return array<string, mixed>
 */
function nibwp_edd_download_summary(object $post, bool $detailed): array
{
    $id = (int) nibwp_edd_field($post, 'ID');

    $variable = function_exists('edd_has_variable_prices') && (bool) edd_has_variable_prices($id);

    $raw = [
        'id' => $id,
        'title' => (string) nibwp_edd_field($post, 'post_title'),
        'slug' => (string) nibwp_edd_field($post, 'post_name'),
        'status' => (string) nibwp_edd_field($post, 'post_status'),
        'type' => function_exists('edd_get_download_type') ? (string) edd_get_download_type($id) : null,
        'author_id' => (int) nibwp_edd_field($post, 'post_author'),
        'sku' => function_exists('edd_get_download_sku') ? edd_get_download_sku($id) : null,
        'price' => function_exists('edd_get_download_price') ? edd_get_download_price($id) : get_post_meta($id, 'edd_price', true),
        'has_variable_prices' => $variable,
        'sales' => function_exists('edd_get_download_sales_stats') ? (int) edd_get_download_sales_stats($id) : 0,
        'earnings' => function_exists('edd_get_download_earnings_stats') ? edd_get_download_earnings_stats($id) : 0,
        'date_created' => (string) nibwp_edd_field($post, 'post_date_gmt'),
        'date_modified' => (string) nibwp_edd_field($post, 'post_modified_gmt'),
    ];

    $record = nibwp_edd_download_record($raw);

    if (!$detailed) {
        return $record;
    }

    $files = function_exists('edd_get_download_files') ? edd_get_download_files($id) : [];
    $prices = $variable && function_exists('edd_get_variable_prices') ? edd_get_variable_prices($id) : [];

    return $record + nibwp_edd_file_summary($files) + [
        'price_variations' => nibwp_edd_variation_rows($prices),
        'permalink_returned' => false,
    ];
}

/**
 * One product, with its price options and what files it has.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_get_download(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading a download', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'download_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the download as download_id.', 'nibwp'), 422);
    }

    $post = nibwp_edd_download_post($id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    return ['download' => nibwp_edd_download_summary($post, true)];
}

/**
 * A new product, as a draft with a single price and no files.
 *
 * A draft on purpose: a product with no file attached takes money and delivers
 * nothing, and the file upload has to happen in wp-admin. Publishing it here would
 * put an empty product on the storefront.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_create_download(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Creating a download', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_products(__('Creating a download', 'nibwp'))) {
        return $denied;
    }

    $title = isset($input['title']) && is_scalar($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_edd_err('missing_title', __('Give the product name as title.', 'nibwp'), 422);
    }

    // wp_slash, and no kses of our own. wp_insert_post() unslashes what it is
    // handed, so an unslashed write eats an escaped quote or a block comment's
    // &; and sanitising is core's job — it runs the content_save_pre
    // filters, which include wp_filter_post_kses for any account without
    // unfiltered_html. Applying kses here as well mangles block markup a caller
    // legitimately passed. tests/post-content-slash-check.php pins both halves.
    $content = isset($input['content']) && is_scalar($input['content']) ? (string) $input['content'] : '';

    $created = wp_insert_post([
        'post_type' => 'download',
        'post_status' => 'draft',
        'post_title' => $title,
        'post_content' => wp_slash($content),
    ], true);

    if ($created instanceof WP_Error) {
        return nibwp_edd_err(
            'create_failed',
            sprintf(
                /* translators: %s: error message from WordPress */
                __('WordPress refused to create the download: %s', 'nibwp'),
                $created->get_error_message()
            ),
            500
        );
    }

    $id = (int) $created;

    if ($id <= 0) {
        return nibwp_edd_err('create_failed', __('WordPress returned no ID for the new download.', 'nibwp'), 500);
    }

    $price = isset($input['price']) && is_numeric($input['price']) ? max(0.0, (float) $input['price']) : 0.0;
    $stored = function_exists('edd_sanitize_amount') ? edd_sanitize_amount($price) : number_format($price, 2, '.', '');
    update_post_meta($id, 'edd_price', $stored);

    return [
        'created' => true,
        'download_id' => $id,
        'status' => 'draft',
        'price' => is_scalar($stored) ? (string) $stored : null,
        'note' => __('Created as a draft with no files attached, so it is not sellable yet. Upload the file in wp-admin and publish it there once the delivery is right.', 'nibwp'),
    ];
}

/**
 * Change a product's single price.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_update_download_price(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Changing a download\'s price', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_products(__('Changing a download\'s price', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'download_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the download as download_id.', 'nibwp'), 422);
    }

    if (!isset($input['price']) || !is_numeric($input['price'])) {
        return nibwp_edd_err('bad_amount', __('Give the new price as price, a number.', 'nibwp'), 422);
    }

    $price = (float) $input['price'];

    if ($price < 0) {
        return nibwp_edd_err('bad_amount', __('A price cannot be negative.', 'nibwp'), 422);
    }

    $post = nibwp_edd_download_post($id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    // Refused rather than silently useless: on a variable-priced product the
    // storefront reads the edd_variable_prices rows, so writing edd_price changes
    // the stored number and nothing a buyer ever sees
    // (includes/download-functions.php:318-330).
    if (function_exists('edd_has_variable_prices') && edd_has_variable_prices($id)) {
        return nibwp_edd_err(
            'variable_priced',
            __('This download has price options, so its base price is not what a buyer pays. Edit the price options in wp-admin instead — changing the base price here would look like it worked and change nothing on the storefront.', 'nibwp'),
            409
        );
    }

    $before = get_post_meta($id, 'edd_price', true);
    $stored = function_exists('edd_sanitize_amount') ? edd_sanitize_amount($price) : number_format($price, 2, '.', '');
    update_post_meta($id, 'edd_price', $stored);

    return [
        'updated' => true,
        'download_id' => $id,
        'price_before' => is_scalar($before) ? (string) $before : null,
        'price_after' => is_scalar($stored) ? (string) $stored : null,
        'note' => __('The new price applies to checkouts from now on. Orders already placed keep the price they were placed at.', 'nibwp'),
    ];
}

/**
 * Publish, unpublish or otherwise restatus a product.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_update_download_status(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Changing a download\'s status', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_products(__('Changing a download\'s status', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'download_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the download as download_id.', 'nibwp'), 422);
    }

    $allowed = ['publish', 'draft', 'pending', 'private'];
    $status = isset($input['status']) && is_scalar($input['status']) ? strtolower(trim((string) $input['status'])) : '';

    // 'trash' is deliberately not in that list. Trashing a product a store has
    // sold breaks the purchase records that point at it, and un-trashing is not
    // something to rely on an agent to remember.
    if (!in_array($status, $allowed, true)) {
        return nibwp_edd_err(
            'bad_status',
            sprintf(
                /* translators: %s: list of valid statuses */
                __('Give the new status as status, one of: %s. Trashing a download is not offered here, because it breaks the purchase records that point at it.', 'nibwp'),
                implode(', ', $allowed)
            ),
            422
        );
    }

    $post = nibwp_edd_download_post($id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $before = (string) nibwp_edd_field($post, 'post_status');

    $updated = wp_update_post([
        'ID' => $id,
        'post_status' => $status,
    ], true);

    if ($updated instanceof WP_Error) {
        return nibwp_edd_err(
            'update_failed',
            sprintf(
                /* translators: %s: error message from WordPress */
                __('WordPress refused to change the download\'s status: %s', 'nibwp'),
                $updated->get_error_message()
            ),
            500
        );
    }

    return [
        'updated' => true,
        'download_id' => $id,
        'status_before' => $before,
        'status_after' => $status,
        'note' => $status === 'publish'
            ? __('Published. Check it has a file attached before telling anyone about it — a published download with no file takes money and delivers nothing.', 'nibwp')
            : __('Status changed. Anyone holding a direct link to the product page will now see whatever that status shows them.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- orders */

/**
 * Search orders.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_list_payments(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Listing orders', 'nibwp'))) {
        return $denied;
    }

    // An order list names buyers by email, which is the same exposure as reading
    // one order, so it takes the same extra capability.
    if ($denied = nibwp_edd_require_sensitive(__('Listing orders', 'nibwp'))) {
        return $denied;
    }

    $status = nibwp_edd_status($input);
    if ($status instanceof WP_Error) {
        return $status;
    }

    $limit = nibwp_edd_limit($input);
    $offset = nibwp_edd_offset($input, $limit);

    $args = [
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'date_created',
        'order' => 'DESC',
        'type' => 'sale',
    ];

    if ($status !== '') {
        $args['status'] = $status;
    }

    if (isset($input['search']) && is_scalar($input['search']) && trim((string) $input['search']) !== '') {
        $args['email'] = sanitize_text_field((string) $input['search']);
    }

    $orders = edd_get_orders($args);
    $rows = [];

    foreach (is_array($orders) ? $orders : [] as $order) {
        if (!is_object($order) && !is_array($order)) {
            continue;
        }

        $rows[] = nibwp_edd_order_record($order);
    }

    $total = function_exists('edd_count_orders')
        ? (int) edd_count_orders(array_diff_key($args, ['number' => 0, 'offset' => 0]))
        : count($rows);

    return [
        'payments' => $rows,
        'returned' => count($rows),
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'status' => $status === '' ? null : $status,
        'note' => __('Orders from the EDD 3.x order tables. Each row names a buyer by email; the payment key and the buyer\'s IP are not returned. Nothing here refunds or deletes an order.', 'nibwp'),
    ];
}

/**
 * One order, with its lines and its refund position.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_get_payment(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading an order', 'nibwp'))) {
        return $denied;
    }

    // Checked BEFORE the order is looked up, so an account that may read neither
    // an order that exists nor one that does not gets the same refusal for both
    // and the error message cannot be used to enumerate order IDs.
    if ($denied = nibwp_edd_require_sensitive(__('Reading an order', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'payment_id', 'order_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the order as payment_id.', 'nibwp'), 422);
    }

    $order = nibwp_edd_order_row($id);
    if ($order instanceof WP_Error) {
        return $order;
    }

    $items = [];
    $raw_items = nibwp_edd_field($order, 'items');

    if (!is_array($raw_items) && function_exists('edd_get_order_items')) {
        $raw_items = edd_get_order_items(['order_id' => $id, 'number' => NIBWP_EDD_MAX_ROWS]);
    }

    foreach (is_array($raw_items) ? $raw_items : [] as $item) {
        if (!is_object($item) && !is_array($item)) {
            continue;
        }

        $items[] = nibwp_edd_order_item_record($item);
    }

    return [
        'payment' => nibwp_edd_order_record($order) + [
            'items' => $items,
            'item_count' => count($items),
            'refunds' => nibwp_edd_refund_rows($id),
            'order_key_withheld' => true,
            'gateway_reference_withheld' => true,
            'buyer_address_withheld' => true,
        ],
        'note' => __('The order and its lines. The payment key, the gateway\'s own transaction reference and the buyer\'s IP are withheld. Nothing here issues a refund.', 'nibwp'),
    ];
}

/**
 * The refunds recorded against one order.
 *
 * In 3.x a refund is itself an order row, of type 'refund', with the refunded
 * order as its parent (includes/orders/functions/refunds.php:370). Reading only.
 *
 * @return array<string, mixed>
 */
function nibwp_edd_refund_rows(int $order_id): array
{
    if (!function_exists('edd_get_order_refunds')) {
        return ['count' => 0, 'amount' => 0.0, 'rows' => [], 'available' => false];
    }

    $refunds = edd_get_order_refunds($order_id);
    $rows = [];
    $amount = 0.0;

    foreach (is_array($refunds) ? $refunds : [] as $refund) {
        if (!is_object($refund) && !is_array($refund)) {
            continue;
        }

        $total = nibwp_edd_field($refund, 'total');
        $amount += is_numeric($total) ? (float) $total : 0.0;
        $rows[] = nibwp_edd_order_record($refund);
    }

    return [
        'count' => count($rows),
        // EDD stores a refund's total as a negative number, so the absolute value
        // is what somebody asking "how much was refunded" means.
        'amount' => round(abs($amount), 2),
        'rows' => $rows,
        'available' => true,
    ];
}

/**
 * How many orders and how much money, over a window, with the status breakdown.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_order_totals(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Counting orders and revenue', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_edd_db()) {
        return $denied;
    }

    global $wpdb;

    $period = nibwp_edd_period($input);
    $totals = nibwp_edd_window_totals((string) $period['start'], (string) $period['end']);

    $sql = $wpdb->prepare(
        'SELECT status, COUNT(*) AS orders FROM ' . nibwp_edd_orders_table()
        . ' WHERE type = %s AND date_created >= %s AND date_created <= %s'
        . ' GROUP BY status ORDER BY orders DESC LIMIT %d',
        'sale',
        (string) $period['start'],
        (string) $period['end'],
        NIBWP_EDD_MAX_ROWS
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $by_status = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $by_status[] = [
            'status' => isset($row['status']) && is_scalar($row['status']) ? (string) $row['status'] : '',
            'orders' => (int) ($row['orders'] ?? 0),
        ];
    }

    return [
        'days' => $period['days'],
        'from' => $period['start'],
        'to' => $period['end'],
        'currency' => function_exists('edd_get_currency') ? (string) edd_get_currency() : null,
        'orders_counted' => $totals['orders'],
        'revenue' => $totals['revenue'],
        'by_status' => $by_status,
        'note' => __('Counts and sums only, so no buyer is named. revenue covers completed and partially refunded orders, which is the money the store kept.', 'nibwp'),
    ];
}

/**
 * The refund picture — for one order, or across a window.
 *
 * Read-only, deliberately and permanently. This action exists so an owner can ask
 * "what has been refunded" without this hub being able to refund anything.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_refund_status(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading the refund position', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'payment_id', 'order_id');

    if ($id > 0) {
        // A named order, so the per-order gate applies — and before the lookup.
        if ($denied = nibwp_edd_require_sensitive(__('Reading one order\'s refund position', 'nibwp'))) {
            return $denied;
        }

        $order = nibwp_edd_order_row($id);
        if ($order instanceof WP_Error) {
            return $order;
        }

        $refunds = nibwp_edd_refund_rows($id);
        $total = nibwp_edd_field($order, 'total');

        return [
            'scope' => 'order',
            'order_id' => $id,
            'status' => (string) nibwp_edd_field($order, 'status'),
            'order_total' => is_numeric($total) ? round((float) $total, 2) : null,
            'refunded_count' => $refunds['count'],
            'refunded_amount' => $refunds['amount'],
            'refunds' => $refunds['rows'],
            'can_refund_from_here' => false,
            'note' => __('Read-only. This hub cannot issue or reverse a refund, by design — do it on the order screen in wp-admin, where the amount and the gateway are in front of you.', 'nibwp'),
        ];
    }

    if ($denied = nibwp_edd_db()) {
        return $denied;
    }

    global $wpdb;

    $period = nibwp_edd_period($input);

    // Refunds are order rows of their own, of type 'refund', so they are counted
    // in the same table rather than from a separate log.
    $sql = $wpdb->prepare(
        'SELECT COUNT(*) AS refunds, COALESCE(SUM(ABS(total)), 0) AS refunded FROM ' . nibwp_edd_orders_table()
        . ' WHERE type = %s AND date_created >= %s AND date_created <= %s',
        'refund',
        (string) $period['start'],
        (string) $period['end']
    );

    $row = $wpdb->get_row($sql, ARRAY_A);
    $row = is_array($row) ? $row : [];
    $sales = nibwp_edd_window_totals((string) $period['start'], (string) $period['end']);

    $refunded = round((float) ($row['refunded'] ?? 0), 2);

    return [
        'scope' => 'store',
        'days' => $period['days'],
        'from' => $period['start'],
        'to' => $period['end'],
        'refund_count' => (int) ($row['refunds'] ?? 0),
        'refunded_amount' => $refunded,
        'revenue' => $sales['revenue'],
        'refund_rate_percent' => $sales['revenue'] > 0 ? round(($refunded / $sales['revenue']) * 100, 2) : null,
        'can_refund_from_here' => false,
        'note' => __('Read-only. Nothing in this integration issues a refund or deletes an order; that stays on the order screen in wp-admin.', 'nibwp'),
    ];
}

/* ---------------------------------------------------------------- customers */

/**
 * Find customers by name or email.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_search_customers(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Searching customers', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_sensitive(__('Searching customers', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('edd_get_customers')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose its customers where this integration can read them.', 'nibwp'), 500);
    }

    $limit = nibwp_edd_limit($input);
    $offset = nibwp_edd_offset($input, $limit);

    $args = [
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'purchase_value',
        'order' => 'DESC',
    ];

    if (isset($input['search']) && is_scalar($input['search']) && trim((string) $input['search']) !== '') {
        $args['search'] = sanitize_text_field((string) $input['search']);
    }

    $customers = edd_get_customers($args);
    $rows = [];

    foreach (is_array($customers) ? $customers : [] as $customer) {
        if (!is_object($customer) && !is_array($customer)) {
            continue;
        }

        $rows[] = nibwp_edd_customer_record($customer);
    }

    return [
        'customers' => $rows,
        'returned' => count($rows),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'note' => __('Highest lifetime value first. These are real people: say only as much about them as the question needs. No saved card, token or gateway customer reference is returned.', 'nibwp'),
    ];
}

/**
 * One customer and what they are worth.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_get_customer(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading a customer', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_sensitive(__('Reading a customer', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'customer_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the customer as customer_id. It is the EDD customer ID, not a WordPress user ID.', 'nibwp'), 422);
    }

    $customer = nibwp_edd_customer_row($id);
    if ($customer instanceof WP_Error) {
        return $customer;
    }

    $record = nibwp_edd_customer_record($customer);
    $count = (int) ($record['purchase_count'] ?? 0);
    $value = is_numeric($record['purchase_value'] ?? null) ? (float) $record['purchase_value'] : 0.0;

    return [
        'customer' => $record + [
            'lifetime_value' => round($value, 2),
            'average_order_value' => $count > 0 ? round($value / $count, 2) : null,
            'currency' => function_exists('edd_get_currency') ? (string) edd_get_currency() : null,
            'stored_payment_methods_withheld' => true,
            'customer_access_keys_withheld' => true,
        ],
        'note' => __('Lifetime value is the purchase_value the customers table keeps, which EDD maintains as orders complete and refund. Saved cards, gateway customer references and any EDD API keys this person holds are never returned.', 'nibwp'),
    ];
}

/**
 * What one customer has bought.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_customer_orders(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Listing a customer\'s orders', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_sensitive(__('Listing a customer\'s orders', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'customer_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the customer as customer_id.', 'nibwp'), 422);
    }

    $customer = nibwp_edd_customer_row($id);
    if ($customer instanceof WP_Error) {
        return $customer;
    }

    $limit = nibwp_edd_limit($input);
    $offset = nibwp_edd_offset($input, $limit);

    $orders = edd_get_orders([
        'customer_id' => $id,
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'date_created',
        'order' => 'DESC',
        'type' => 'sale',
    ]);

    $rows = [];
    $spent = 0.0;

    foreach (is_array($orders) ? $orders : [] as $order) {
        if (!is_object($order) && !is_array($order)) {
            continue;
        }

        $record = nibwp_edd_order_record($order);
        $rows[] = $record;

        if (in_array((string) ($record['status'] ?? ''), nibwp_edd_earning_statuses(), true) && is_numeric($record['total'] ?? null)) {
            $spent += (float) $record['total'];
        }
    }

    return [
        'customer_id' => $id,
        'email' => (string) nibwp_edd_field($customer, 'email'),
        'payments' => $rows,
        'returned' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'spent_on_this_page' => round($spent, 2),
        'note' => __('One page of this customer\'s orders. spent_on_this_page covers only the rows returned — read lifetime_value from get_customer for the whole relationship.', 'nibwp'),
    ];
}

/* ---------------------------------------------------------------- discounts */

/**
 * The store's discount codes.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_list_discounts(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Listing discount codes', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('edd_get_discounts')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose its discounts where this integration can read them.', 'nibwp'), 500);
    }

    $limit = nibwp_edd_limit($input);
    $offset = nibwp_edd_offset($input, $limit);

    $args = [
        'number' => $limit,
        'offset' => $offset,
        'orderby' => 'date_created',
        'order' => 'DESC',
    ];

    if (isset($input['status']) && is_scalar($input['status'])) {
        $status = strtolower(trim((string) $input['status']));

        if (in_array($status, ['active', 'inactive', 'expired', 'archived'], true)) {
            $args['status'] = $status;
        }
    }

    $discounts = edd_get_discounts($args);
    $rows = [];

    foreach (is_array($discounts) ? $discounts : [] as $discount) {
        if (!is_object($discount) && !is_array($discount)) {
            continue;
        }

        $rows[] = nibwp_edd_discount_record($discount);
    }

    return [
        'discounts' => $rows,
        'returned' => count($rows),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'note' => __('Discount codes as the store holds them. A code with status active but an end_date in the past still refuses at checkout — expired_discounts_still_active finds those.', 'nibwp'),
    ];
}

/**
 * One discount code.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_get_discount(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading a discount code', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_edd_id($input, 'discount_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the discount as discount_id.', 'nibwp'), 422);
    }

    $discount = nibwp_edd_discount_row($id);
    if ($discount instanceof WP_Error) {
        return $discount;
    }

    $record = nibwp_edd_discount_record($discount);
    $end = isset($record['end_date']) && is_scalar($record['end_date']) ? (string) $record['end_date'] : '';
    $expired = $end !== '' && strtotime($end) !== false && strtotime($end) < time();

    $max = (int) ($record['max_uses'] ?? 0);
    $used = (int) ($record['use_count'] ?? 0);

    return [
        'discount' => $record + [
            'is_expired' => $expired,
            'uses_remaining' => $max > 0 ? max(0, $max - $used) : null,
            'exhausted' => $max > 0 && $used >= $max,
            'would_apply_now' => (string) ($record['status'] ?? '') === 'active' && !$expired && !($max > 0 && $used >= $max),
        ],
        'note' => __('would_apply_now is the useful field: a code can be active, unexpired and still refuse because it has been used up.', 'nibwp'),
    ];
}

/**
 * A new discount code, switched off unless asked otherwise.
 *
 * Off by default because a live code is a standing instruction to take less money,
 * and a typo in the amount is only visible once somebody has used it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_create_discount(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Creating a discount code', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_discounts(__('Creating a discount code', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('edd_add_discount')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose a way to create a discount from here.', 'nibwp'), 500);
    }

    $code = isset($input['code']) && is_scalar($input['code']) ? strtoupper(trim(sanitize_text_field((string) $input['code']))) : '';

    if ($code === '') {
        return nibwp_edd_err('missing_code', __('Give the code a buyer types as code.', 'nibwp'), 422);
    }

    if (function_exists('edd_get_discount_by_code')) {
        $existing = edd_get_discount_by_code($code);

        if (is_object($existing) && (int) nibwp_edd_field($existing, 'id') > 0) {
            return nibwp_edd_err(
                'duplicate_code',
                sprintf(
                    /* translators: %s: the discount code */
                    __('The code "%s" already exists on this store. Codes are not unique by accident — reusing one would change the terms of a code that may already be published somewhere.', 'nibwp'),
                    $code
                ),
                409
            );
        }
    }

    if (!isset($input['amount']) || !is_numeric($input['amount'])) {
        return nibwp_edd_err('bad_amount', __('Give the discount amount as amount, a number.', 'nibwp'), 422);
    }

    $amount = (float) $input['amount'];

    if ($amount <= 0) {
        return nibwp_edd_err('bad_amount', __('A discount amount has to be greater than zero.', 'nibwp'), 422);
    }

    $type = isset($input['amount_type']) && is_scalar($input['amount_type'])
        ? strtolower(trim((string) $input['amount_type']))
        : 'percent';

    if (!in_array($type, ['percent', 'flat'], true)) {
        return nibwp_edd_err('bad_amount_type', __('amount_type must be percent or flat.', 'nibwp'), 422);
    }

    if ($type === 'percent' && $amount > 100) {
        return nibwp_edd_err('bad_amount', __('A percentage discount over 100 would pay the buyer. Give amount as a number up to 100, or use amount_type flat.', 'nibwp'), 422);
    }

    $name = isset($input['name']) && is_scalar($input['name']) ? sanitize_text_field((string) $input['name']) : $code;
    $active = isset($input['active']) && $input['active'] === true;

    $data = [
        'name' => $name,
        'code' => $code,
        'status' => $active ? 'active' : 'inactive',
        'type' => 'discount',
        'scope' => 'global',
        'amount_type' => $type,
        'amount' => $amount,
    ];

    if (isset($input['max_uses']) && is_numeric($input['max_uses'])) {
        $data['max_uses'] = max(0, (int) $input['max_uses']);
    }

    if (isset($input['end_date']) && is_scalar($input['end_date']) && trim((string) $input['end_date']) !== '') {
        $end = trim((string) $input['end_date']);

        if (strtotime($end) === false) {
            return nibwp_edd_err('bad_date', __('end_date has to be a date this store can read, such as 2026-12-31 23:59:59.', 'nibwp'), 422);
        }

        $data['end_date'] = gmdate('Y-m-d H:i:s', (int) strtotime($end));
    }

    $created = edd_add_discount($data);
    $id = is_numeric($created) ? (int) $created : 0;

    if ($id <= 0) {
        return nibwp_edd_err('create_failed', __('Easy Digital Downloads did not create the discount and gave no reason.', 'nibwp'), 500);
    }

    return [
        'created' => true,
        'discount_id' => $id,
        'code' => $code,
        'amount' => $amount,
        'amount_type' => $type,
        'status' => $active ? 'active' : 'inactive',
        'note' => $active
            ? __('The code is live now. Say the code and the amount back to whoever asked, so a wrong number is caught before a buyer finds it.', 'nibwp')
            : __('Created switched off, which is the default: a live code is a standing instruction to take less money, and a wrong amount only shows up after somebody has used it. Activate it in wp-admin once the terms read right.', 'nibwp'),
    ];
}

/**
 * Switch a discount code off.
 *
 * Deactivated rather than deleted. A deleted code loses the record of what the
 * orders that used it were discounted by.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_deactivate_discount(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Deactivating a discount code', 'nibwp'))) {
        return $denied;
    }
    if ($denied = nibwp_edd_require_discounts(__('Deactivating a discount code', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('edd_update_discount_status')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose a way to change a discount\'s status from here.', 'nibwp'), 500);
    }

    $id = nibwp_edd_id($input, 'discount_id');

    if ($id <= 0) {
        return nibwp_edd_err('missing_id', __('Give the discount as discount_id.', 'nibwp'), 422);
    }

    $discount = nibwp_edd_discount_row($id);
    if ($discount instanceof WP_Error) {
        return $discount;
    }

    $before = (string) nibwp_edd_field($discount, 'status');
    $code = (string) nibwp_edd_field($discount, 'code');

    $updated = (bool) edd_update_discount_status($id, 'inactive');

    return [
        'deactivated' => $updated,
        'discount_id' => $id,
        'code' => $code,
        'status_before' => $before,
        'status_after' => $updated ? 'inactive' : $before,
        'note' => __('Switched off, not deleted — the orders that used this code keep the record of what it discounted. Anywhere the code is still advertised will now refuse it at checkout, so it is worth taking it down too.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------ reports */

/**
 * The store at a glance.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_get_stats(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading the store statistics', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_edd_db()) {
        return $denied;
    }

    $period = nibwp_edd_period($input);
    $current = nibwp_edd_window_totals((string) $period['start'], (string) $period['end']);

    $stats = [
        // All-time figures EDD maintains itself (includes/payments/functions.php:601, 618).
        'total_earnings' => function_exists('edd_get_total_earnings') ? round((float) edd_get_total_earnings(), 2) : null,
        'total_sales' => function_exists('edd_get_total_sales') ? (int) edd_get_total_sales() : null,
        'currency' => function_exists('edd_get_currency') ? (string) edd_get_currency() : null,
        'days' => $period['days'],
        'orders_in_period' => $current['orders'],
        'revenue_in_period' => $current['revenue'],
        'customers' => function_exists('edd_count_customers') ? (int) edd_count_customers([]) : null,
        'downloads_published' => (int) wp_count_posts('download')->publish,
        'order_storage' => 'custom_tables',
    ];

    return [
        'stats' => $stats,
        'note' => __('Orders on this store live in Easy Digital Downloads\' own tables, not the posts table, which is why the period figures are aggregated rather than counted from posts. The per-day helper EDD used to offer for this is deprecated in 3.x.', 'nibwp'),
    ];
}

/**
 * Revenue over a window against the window before it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_revenue_report(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading the revenue report', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_edd_db()) {
        return $denied;
    }

    $period = nibwp_edd_period($input);
    $current = nibwp_edd_window_totals((string) $period['start'], (string) $period['end']);
    $previous = nibwp_edd_window_totals((string) $period['previous_start'], (string) $period['previous_end']);

    // Left null rather than reported as a percentage off zero: "up 100%" from no
    // revenue at all is a sentence that reads like good news and means nothing.
    $change = $previous['revenue'] > 0
        ? round((($current['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 2)
        : null;

    return [
        'days' => $period['days'],
        'currency' => function_exists('edd_get_currency') ? (string) edd_get_currency() : null,
        'current' => [
            'from' => $period['start'],
            'to' => $period['end'],
            'orders' => $current['orders'],
            'revenue' => $current['revenue'],
            'average_order_value' => $current['orders'] > 0 ? round($current['revenue'] / $current['orders'], 2) : null,
        ],
        'previous' => [
            'from' => $period['previous_start'],
            'to' => $period['previous_end'],
            'orders' => $previous['orders'],
            'revenue' => $previous['revenue'],
            'average_order_value' => $previous['orders'] > 0 ? round($previous['revenue'] / $previous['orders'], 2) : null,
        ],
        'revenue_change_percent' => $change,
        'order_change' => $current['orders'] - $previous['orders'],
        'note' => $previous['revenue'] > 0
            ? __('The two windows are the same length, back to back. Both count completed and partially refunded orders.', 'nibwp')
            : __('The previous window took no money, so there is no percentage to compare against — the change is left out rather than reported as a growth figure off zero.', 'nibwp'),
    ];
}

/**
 * What sold most, by units.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_best_sellers(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading the best-selling downloads', 'nibwp'))) {
        return $denied;
    }

    return nibwp_edd_product_report($input, 'units');
}

/**
 * What earned most, by money.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_earnings_by_product(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Reading earnings by product', 'nibwp'))) {
        return $denied;
    }

    return nibwp_edd_product_report($input, 'earnings');
}

/**
 * Order lines rolled up per product, ordered by whichever column was asked for.
 *
 * $by is one of two literals chosen here, never a string from input, so the ORDER
 * BY identifier is ours — an identifier cannot be bound by prepare(), which is
 * exactly why it must not come from a caller.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_product_report(array $input, string $by): array|WP_Error
{
    if ($denied = nibwp_edd_db()) {
        return $denied;
    }

    global $wpdb;

    $period = nibwp_edd_period($input);
    $limit = nibwp_edd_limit($input, 10);
    $order_by = $by === 'units' ? 'units' : 'earnings';

    $sql = $wpdb->prepare(
        'SELECT product_id, product_name,'
        . ' COALESCE(SUM(quantity), 0) AS units,'
        . ' COALESCE(SUM(total), 0) AS earnings,'
        . ' COUNT(DISTINCT order_id) AS orders'
        . ' FROM ' . nibwp_edd_order_items_table()
        . " WHERE status IN ('complete', 'partially_refunded')"
        . ' AND date_created >= %s AND date_created <= %s'
        . ' GROUP BY product_id, product_name'
        . ' ORDER BY ' . $order_by . ' DESC'
        . ' LIMIT %d',
        (string) $period['start'],
        (string) $period['end'],
        $limit
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $products = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $products[] = [
            'download_id' => (int) ($row['product_id'] ?? 0),
            'title' => isset($row['product_name']) && is_scalar($row['product_name']) ? (string) $row['product_name'] : '',
            'units' => (int) ($row['units'] ?? 0),
            'earnings' => round((float) ($row['earnings'] ?? 0), 2),
            'orders' => (int) ($row['orders'] ?? 0),
        ];
    }

    return [
        'ranked_by' => $order_by,
        'days' => $period['days'],
        'from' => $period['start'],
        'to' => $period['end'],
        'currency' => function_exists('edd_get_currency') ? (string) edd_get_currency() : null,
        'returned' => count($products),
        'limit' => $limit,
        'products' => $products,
        'note' => __('Rolled up from the order lines, so a product renamed since a sale appears under the name it was sold as. No file URLs are included.', 'nibwp'),
    ];
}

/* -------------------------------------------------------------------- audit */

/**
 * Products a buyer could pay for and receive nothing from.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_downloads_without_files(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Auditing downloads for missing files', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_edd_limit($input, 50);

    $posts = get_posts([
        'post_type' => 'download',
        'post_status' => ['publish', 'private'],
        'posts_per_page' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
    ]);

    $rows = [];

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!is_object($post)) {
            continue;
        }

        $id = (int) nibwp_edd_field($post, 'ID');
        $type = function_exists('edd_get_download_type') ? (string) edd_get_download_type($id) : '';

        // A bundle legitimately has no files of its own: it delivers the files of
        // the products inside it (src/Downloads/Traits/Files.php:38-41 returns an
        // empty list for one). Flagging bundles would make this audit noise.
        if ($type === 'bundle') {
            continue;
        }

        $files = function_exists('edd_get_download_files') ? edd_get_download_files($id) : [];

        if (is_array($files) && $files !== []) {
            continue;
        }

        $rows[] = [
            'download_id' => $id,
            'title' => (string) nibwp_edd_field($post, 'post_title'),
            'status' => (string) nibwp_edd_field($post, 'post_status'),
            'price' => function_exists('edd_get_download_price') ? edd_get_download_price($id) : get_post_meta($id, 'edd_price', true),
            'sales' => function_exists('edd_get_download_sales_stats') ? (int) edd_get_download_sales_stats($id) : 0,
        ];
    }

    return [
        'count' => count($rows),
        'scanned' => is_array($posts) ? count($posts) : 0,
        'limit' => $limit,
        'downloads' => $rows,
        'note' => $rows === []
            ? __('Every published download in the page scanned has at least one file attached. Bundles are skipped, because a bundle delivers the files of the products inside it.', 'nibwp')
            : __('Each of these is live and takes money, and a buyer gets nothing for it. Fix the worst by sales count first. Bundles are skipped, because a bundle has no files of its own.', 'nibwp'),
    ];
}

/**
 * Codes still switched on that a buyer will now be refused.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_expired_discounts(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Auditing discount codes', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('edd_get_discounts')) {
        return nibwp_edd_err('no_api', __('This version of Easy Digital Downloads does not expose its discounts where this integration can read them.', 'nibwp'), 500);
    }

    $limit = nibwp_edd_limit($input, 50);

    $discounts = edd_get_discounts([
        'status' => 'active',
        'number' => $limit,
        'orderby' => 'end_date',
        'order' => 'ASC',
    ]);

    $now = time();
    $rows = [];

    foreach (is_array($discounts) ? $discounts : [] as $discount) {
        if (!is_object($discount) && !is_array($discount)) {
            continue;
        }

        $record = nibwp_edd_discount_record($discount);
        $end = isset($record['end_date']) && is_scalar($record['end_date']) ? (string) $record['end_date'] : '';
        $stamp = $end === '' ? false : strtotime($end);

        $max = (int) ($record['max_uses'] ?? 0);
        $used = (int) ($record['use_count'] ?? 0);
        $exhausted = $max > 0 && $used >= $max;
        $expired = $stamp !== false && $stamp < $now;

        if (!$expired && !$exhausted) {
            continue;
        }

        $rows[] = [
            'discount_id' => (int) ($record['id'] ?? 0),
            'code' => (string) ($record['code'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'end_date' => $end === '' ? null : $end,
            'expired' => $expired,
            'exhausted' => $exhausted,
            'use_count' => $used,
            'max_uses' => $max,
            'reason' => $expired
                ? __('The end date has passed, but the code is still switched on.', 'nibwp')
                : __('Every permitted use has been taken, but the code is still switched on.', 'nibwp'),
        ];
    }

    return [
        'count' => count($rows),
        'scanned' => is_array($discounts) ? count($discounts) : 0,
        'limit' => $limit,
        'discounts' => $rows,
        'note' => $rows === []
            ? __('No active code is past its date or used up in the page scanned.', 'nibwp')
            : __('Each of these looks live in wp-admin and refuses at checkout. If any is still printed in an email or on a page, that is a buyer being told a price the store will not honour. deactivate_discount switches one off; it does not take down wherever it is advertised.', 'nibwp'),
    ];
}

/**
 * Orders that took money and never completed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_stalled_orders(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Auditing stalled orders', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_edd_db()) {
        return $denied;
    }

    global $wpdb;

    $raw = $input['hours'] ?? null;
    $hours = is_numeric($raw) ? (int) $raw : 48;
    $hours = max(1, min(720, $hours));
    $limit = nibwp_edd_limit($input, 50);
    $before = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));

    // Counts and totals, not buyer emails: the question this answers is "is
    // something wrong with the gateway callback", which a count answers. The
    // status list is a literal here, not a caller's.
    $sql = $wpdb->prepare(
        'SELECT status, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS value,'
        . ' MIN(date_created) AS oldest'
        . ' FROM ' . nibwp_edd_orders_table()
        . " WHERE type = %s AND status IN ('pending', 'processing', 'on_hold')"
        . ' AND date_created <= %s'
        . ' GROUP BY status ORDER BY orders DESC LIMIT %d',
        'sale',
        $before,
        $limit
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $by_status = [];
    $total = 0;
    $value = 0.0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        $count = (int) ($row['orders'] ?? 0);
        $total += $count;
        $value += (float) ($row['value'] ?? 0);

        $by_status[] = [
            'status' => isset($row['status']) && is_scalar($row['status']) ? (string) $row['status'] : '',
            'orders' => $count,
            'value' => round((float) ($row['value'] ?? 0), 2),
            'oldest' => isset($row['oldest']) && is_scalar($row['oldest']) ? (string) $row['oldest'] : null,
        ];
    }

    return [
        'hours' => $hours,
        'older_than' => $before,
        'stalled_orders' => $total,
        'stalled_value' => round($value, 2),
        'by_status' => $by_status,
        'buyer_details_returned' => false,
        'note' => $total === 0
            ? __('Nothing has been sitting unfinished that long.', 'nibwp')
            : __('An order stuck pending usually means the gateway never called back, which can mean money taken and nothing delivered. Counts only — use list_payments with status pending to see which, and settle each one on the order screen.', 'nibwp'),
    ];
}

/**
 * The three audits in one response, with what to act on first.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_edd_audit_store(array $input): array|WP_Error
{
    if ($denied = nibwp_edd_require(__('Auditing the store', 'nibwp'))) {
        return $denied;
    }

    $files = nibwp_edd_downloads_without_files($input);
    $discounts = nibwp_edd_expired_discounts($input);
    $stalled = nibwp_edd_stalled_orders($input);

    $without_files = $files instanceof WP_Error ? 0 : (int) ($files['count'] ?? 0);
    $dead_codes = $discounts instanceof WP_Error ? 0 : (int) ($discounts['count'] ?? 0);
    $stuck = $stalled instanceof WP_Error ? 0 : (int) ($stalled['stalled_orders'] ?? 0);

    $act_on = [];

    // _n() rather than one string with a %d in it: a language with more than two
    // plural forms cannot be made to agree with a hardcoded English singular, and
    // the English itself reads wrong at one.
    if ($without_files > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of downloads with no file attached */
            _n(
                '%d live download has no file attached, so a buyer pays and receives nothing.',
                '%d live downloads have no file attached, so a buyer pays and receives nothing.',
                $without_files,
                'nibwp'
            ),
            $without_files
        );
    }

    if ($dead_codes > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of active discount codes that no longer work */
            _n(
                '%d discount code is switched on but will be refused at checkout.',
                '%d discount codes are switched on but will be refused at checkout.',
                $dead_codes,
                'nibwp'
            ),
            $dead_codes
        );
    }

    if ($stuck > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of orders stuck in a pending state */
            _n(
                '%d order has been sitting unfinished, which can mean money taken and nothing delivered.',
                '%d orders have been sitting unfinished, which can mean money taken and nothing delivered.',
                $stuck,
                'nibwp'
            ),
            $stuck
        );
    }

    return [
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
        'downloads_without_files' => $files instanceof WP_Error ? null : $files,
        'expired_discounts_still_active' => $discounts instanceof WP_Error ? null : $discounts,
        'stalled_orders' => $stalled instanceof WP_Error ? null : $stalled,
        'note' => __('Three checks that cost a store money quietly. Each is bounded, so a large catalogue needs the individual actions with page or offset to cover all of it.', 'nibwp'),
    ];
}
