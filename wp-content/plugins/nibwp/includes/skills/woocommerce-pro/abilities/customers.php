<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — nibwp/wcpro-customers.
 *
 * The clients, as a base rather than one at a time. The Free wc-* abilities list,
 * read, create and update a customer; none of them answers "who spent over 500
 * and has not ordered since spring", "what is this person's whole history", or
 * "these nine guest orders are obviously the same human as this account".
 *
 * This hub reads personal data, and that shapes every line of it:
 *
 *   1. list_users at the door — the capability that opens Users → All Users in
 *      wp-admin — and current_user_can('edit_user', $id) per customer inside.
 *      WooCommerce filters edit_user through map_meta_cap so a shop manager
 *      reaches customers and not administrators; asking per customer honours
 *      that rather than reimplementing a guess at it. A customer the account may
 *      not read is COUNTED as skipped, never silently folded into a total.
 *   2. One exit. Every customer record leaves through
 *      nibwp_wcpro_customer_record(), which is an allow-list: a password hash, a
 *      session token, an application password and the wp_* capability meta are
 *      not on it, and neither is anything WordPress or a plugin adds next year.
 *      A denylist of today's dangerous names would pass tomorrow's.
 *   3. Linking guest orders to an account writes somebody's order history, so it
 *      asks for the capability that would let the account edit those orders, it
 *      refuses an order whose billing email is not the account's — otherwise it
 *      is a way to show a stranger's order in My Account — and it reports every
 *      order it touched instead of doing it quietly.
 *
 * Scans are bounded and say so. A number computed over the first 200 customers
 * of 40,000 is a different fact from the same number over all of them, and a
 * response that does not say which is worse than no response.
 */

require_once __DIR__ . '/../lib/guard.php';
require_once __DIR__ . '/../lib/sanitize.php';

/**
 * The action enum. Single source of truth — the registration and
 * tests/skills/woocommerce-pro-check.php both read this, and the switch in
 * nibwp_wcpro_customers_execute() must match it exactly in both directions.
 *
 * @return array<int, string>
 */
function nibwp_wcpro_customers_actions(): array
{
    return [
        // Finding people.
        'search_customers',
        'get_customer_profile',
        'segment_customers',
        // Notes on their orders.
        'list_customer_notes',
        'add_customer_note',
        // Guest orders and the accounts they belong to.
        'find_guest_orders',
        'link_guest_orders',
    ];
}

nibwp_wcpro_register_hub(
    'nibwp/wcpro-customers',
    __('WooCommerce Pro — customers beyond one record', 'nibwp'),
    'The customer base, not one customer: search by lifetime spend, order count, last order date and registration date; read one customer\'s whole picture (orders, lifetime value, average order, addresses, note count); list and add order notes against a customer\'s orders; find guest orders that share an email with a registered account and link them to it; and segment the base into named groups, returning counts and ids rather than every record. This hub reads personal data: it needs list_users, it asks about each customer individually, and it counts the records the connected account may not read instead of including them. For creating or editing one customer use the Free nibwp/wc-create-customer / wc-update-customer.',
    nibwp_wcpro_customers_actions(),
    [
        'customer_id' => ['type' => 'integer', 'description' => 'The WordPress user id of the customer.'],
        'email' => ['type' => 'string', 'description' => 'Resolve the customer by email instead of id. For find_guest_orders it is also the billing email the guest orders were placed with.'],
        'criteria' => [
            'type' => 'object',
            'description' => 'For search_customers: {min_spend, max_spend, min_orders, max_orders, never_ordered:true, last_order_after:"YYYY-MM-DD", last_order_before:"YYYY-MM-DD", registered_after:"YYYY-MM-DD", registered_before:"YYYY-MM-DD"}. Every key is optional; with none given the whole scanned window matches.',
        ],
        'segments' => [
            'type' => 'array',
            'description' => 'For segment_customers: [{name:"Loyal", min_orders:5}, {name:"Lapsed", min_orders:1, last_order_before:"2026-04-01"}]. Same criteria keys as search_customers, plus a name. Left out, the lifecycle preset is used: never_ordered, one_time, repeat, loyal, lapsed.',
            'items' => ['type' => 'object'],
        ],
        'note' => ['type' => 'string', 'description' => 'For add_customer_note: the note text.'],
        'customer_note' => ['type' => 'boolean', 'default' => false, 'description' => 'For add_customer_note: true makes the note visible to the customer, which ALSO EMAILS THEM when the customer_note email is enabled. false is an internal note only staff see.'],
        'order_id' => ['type' => 'integer', 'description' => 'For add_customer_note: which of the customer\'s orders to note against. Left out, the most recent one is used. For list_customer_notes: narrow to one order.'],
        'order_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'For link_guest_orders: exactly which guest orders to attach. Left out, every guest order matching the account\'s email is attached.'],
        'note_type' => ['type' => 'string', 'enum' => ['all', 'customer', 'internal'], 'default' => 'all', 'description' => 'For list_customer_notes.'],
        'order_limit' => ['type' => 'integer', 'default' => 20, 'description' => 'How many of the customer\'s most recent orders to read. Max 100.'],
        'scan_limit' => ['type' => 'integer', 'default' => 200, 'description' => 'For search_customers / segment_customers: how many customers to examine. Max 2000. The response says how many were scanned and whether the window was full.'],
        'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'For search_customers / segment_customers: where the scan window starts, newest registration first. Walk a large base with offset + scan_limit.'],
        'max_ids' => ['type' => 'integer', 'default' => 50, 'description' => 'For segment_customers: how many ids to list per segment. Counts are always complete for the scanned window. Max 500.'],
        'role' => ['type' => 'string', 'default' => 'customer', 'description' => 'Which role to scan. Default "customer"; "any" scans every user, which needs the capability to read them.'],
        'per_page' => ['type' => 'integer', 'default' => 20],
        'page' => ['type' => 'integer', 'default' => 1],
        'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'For link_guest_orders: report exactly what would be attached and write nothing.'],
    ],
    'nibwp_wcpro_customers_execute',
    implode("\n", [
        'This hub reads personal data. It needs list_users, and it asks current_user_can(\'edit_user\', id) about each customer — so a response can legitimately hold fewer records than the store has. The count of skipped records is in every response; report it rather than presenting a partial list as the whole base.',
        'search_customers { criteria:{…} } scans a bounded window of customers, newest registration first, and returns the ones that match. The response carries scanned, matched, skipped and window_full. If window_full is true there are more customers past the window: raise scan_limit or narrow the criteria with registered_after, which is applied in the query rather than after it.',
        'Lifetime spend and order count come from WooCommerce\'s own per-customer totals. The LAST ORDER DATE costs a query per customer, so it is only read when a criterion or the profile needs it; elsewhere it comes back empty rather than wrong.',
        'get_customer_profile { customer_id } is the whole picture: totals, average order, both addresses, the most recent orders and how many notes sit on them.',
        'add_customer_note with customer_note:true is visible to the customer AND emails them when that email is enabled. Say which of the two you are adding before you add it; an internal note is the safe default.',
        'find_guest_orders { email } lists orders placed with that email and no account attached. link_guest_orders { customer_id } attaches them — a write to somebody\'s order history, so it needs the capability to edit those orders, it refuses any order whose billing email is not the account\'s, and it reports every order it linked. Run it with dry_run:true first and tell the user what it found.',
        'segment_customers returns counts and ids, never full records. A customer can fall in more than one segment; the response says so rather than pretending the groups are exclusive.',
        'For one customer, the Free nibwp/wc-get-customer, wc-create-customer and wc-update-customer own it. Do not route here.',
    ]),
);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_customers_execute(array $input): array|WP_Error
{
    $guard = nibwp_wcpro_guard($input, false);
    if (is_wp_error($guard)) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    // The door. list_users is what opens Users → All Users in wp-admin, and it is
    // the honest boundary for a hub whose cheapest action still returns somebody's
    // email address. Each customer is then asked about individually below.
    $denied = nibwp_wcpro_require_cap('list_users', 'Reading the customer base');
    if ($denied !== null) {
        return $denied;
    }

    switch ($action) {
        case 'search_customers':
            return nibwp_wcpro_search_customers($input);

        case 'get_customer_profile':
            return nibwp_wcpro_get_customer_profile($input);

        case 'segment_customers':
            return nibwp_wcpro_segment_customers($input);

        case 'list_customer_notes':
            return nibwp_wcpro_list_customer_notes($input);

        case 'add_customer_note':
            return nibwp_wcpro_add_customer_note($input);

        case 'find_guest_orders':
            return nibwp_wcpro_find_guest_orders($input);

        case 'link_guest_orders':
            return nibwp_wcpro_link_guest_orders($input);
    }

    return nibwp_wcpro_unknown_action($action, nibwp_wcpro_customers_actions());
}

/* ------------------------------------------------------------- the one exit */

/**
 * The fields a customer record may carry out of this hub, and the type each one
 * leaves as.
 *
 * An allow-list, deliberately. A WordPress user row holds user_pass; user meta
 * holds session_tokens, wp_capabilities, wp_user_level and, on a site with
 * application passwords, _application_passwords. None of them is a customer
 * detail, all of them are an escalation if they leak, and a denylist of those
 * five names would not have covered the fifth before it existed. Anything not
 * named here does not leave, whatever a future edit puts in the raw record.
 *
 * @return array<string, string> field => int|float_string|bool|string
 */
function nibwp_wcpro_customer_record_fields(): array
{
    return [
        'id'                  => 'int',
        'email'               => 'string',
        'username'            => 'string',
        'display_name'        => 'string',
        'first_name'          => 'string',
        'last_name'           => 'string',
        'registered'          => 'string',
        'is_paying_customer'  => 'bool',
        'order_count'         => 'int',
        'total_spent'         => 'string',
        'average_order_value' => 'string',
        'last_order_id'       => 'int',
        'last_order_date'     => 'string',
        'billing_company'     => 'string',
        'billing_address_1'   => 'string',
        'billing_address_2'   => 'string',
        'billing_city'        => 'string',
        'billing_state'       => 'string',
        'billing_postcode'    => 'string',
        'billing_country'     => 'string',
        'billing_phone'       => 'string',
        'billing_email'       => 'string',
        'shipping_first_name' => 'string',
        'shipping_last_name'  => 'string',
        'shipping_company'    => 'string',
        'shipping_address_1'  => 'string',
        'shipping_address_2'  => 'string',
        'shipping_city'       => 'string',
        'shipping_state'      => 'string',
        'shipping_postcode'   => 'string',
        'shipping_country'    => 'string',
    ];
}

/**
 * The only shape a customer leaves this hub in.
 *
 * Every action that returns customer data returns what this gives back, so a
 * field added to a gatherer later cannot travel without being named above. A
 * non-scalar value is dropped rather than serialised, because an array is how a
 * meta blob — session tokens, a capability map — would smuggle itself into a
 * response under an innocent-looking key.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_wcpro_customer_record(array $raw): array
{
    $record = [];

    foreach (nibwp_wcpro_customer_record_fields() as $field => $type) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }
        $value = $raw[$field];
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $record[$field] = match ($type) {
            'int'  => (int) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }

    return $record;
}

/* ------------------------------------------------------------------ helpers */

/**
 * Resolve the customer a request is about, and ask whether this account may read
 * that person.
 *
 * @return array{id: int, user: object}|WP_Error
 */
function nibwp_wcpro_customer_target(array $input): array|WP_Error
{
    $id = (int) ($input['customer_id'] ?? 0);
    $email = trim((string) ($input['email'] ?? ''));

    if ($id <= 0 && $email === '') {
        return nibwp_wcpro_err('missing_customer_ref', 'Provide customer_id or email.', 422);
    }

    if ($id <= 0) {
        if (!function_exists('get_user_by')) {
            return nibwp_wcpro_err('wp_function_missing', 'get_user_by() is not available, so a customer cannot be resolved by email.', 500);
        }
        $found = get_user_by('email', $email);
        if (!is_object($found)) {
            return nibwp_wcpro_err('customer_not_found', sprintf('No account with the email "%s".', $email), 404);
        }
        $id = (int) ($found->ID ?? 0);
    }

    // The object-level question, before the read, so a refusal says nothing about
    // whether the account exists.
    if (!current_user_can('edit_user', $id)) {
        return nibwp_wcpro_forbidden(sprintf('Not allowed to read customer %d.', $id));
    }

    $user = function_exists('get_userdata') ? get_userdata($id) : null;
    if (!is_object($user)) {
        return nibwp_wcpro_err('customer_not_found', sprintf('No account with the id %d.', $id), 404);
    }

    return ['id' => $id, 'user' => $user];
}

/**
 * Everything this hub knows about one customer, before the allow-list sees it.
 *
 * $with_last_order is false by default because WooCommerce has no cached "last
 * order" — reading it is a query per customer, and a scan of two hundred
 * customers that does it anyway is a scan that times out.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_customer_raw(int $user_id, object $user, bool $with_last_order = false): array|WP_Error
{
    $denied = nibwp_wcpro_require_class('WC_Customer', 'Reading a customer');
    if ($denied !== null) {
        return $denied;
    }

    $customer = new WC_Customer($user_id);
    $orders = (int) $customer->get_order_count();
    $spent = (float) $customer->get_total_spent();

    $raw = [
        'id'                 => $user_id,
        'email'              => (string) $customer->get_email(),
        'username'           => (string) ($user->user_login ?? ''),
        'display_name'       => (string) ($user->display_name ?? ''),
        'first_name'         => (string) $customer->get_first_name(),
        'last_name'          => (string) $customer->get_last_name(),
        'registered'         => (string) ($user->user_registered ?? ''),
        'is_paying_customer' => (bool) $customer->get_is_paying_customer(),
        'order_count'        => $orders,
        'total_spent'        => number_format($spent, 2, '.', ''),
        'average_order_value' => $orders > 0 ? number_format($spent / $orders, 2, '.', '') : '0.00',
        'last_order_id'      => 0,
        'last_order_date'    => '',
        'billing_company'    => (string) $customer->get_billing_company(),
        'billing_address_1'  => (string) $customer->get_billing_address_1(),
        'billing_address_2'  => (string) $customer->get_billing_address_2(),
        'billing_city'       => (string) $customer->get_billing_city(),
        'billing_state'      => (string) $customer->get_billing_state(),
        'billing_postcode'   => (string) $customer->get_billing_postcode(),
        'billing_country'    => (string) $customer->get_billing_country(),
        'billing_phone'      => (string) $customer->get_billing_phone(),
        'billing_email'      => (string) $customer->get_billing_email(),
        'shipping_first_name' => (string) $customer->get_shipping_first_name(),
        'shipping_last_name' => (string) $customer->get_shipping_last_name(),
        'shipping_company'   => (string) $customer->get_shipping_company(),
        'shipping_address_1' => (string) $customer->get_shipping_address_1(),
        'shipping_address_2' => (string) $customer->get_shipping_address_2(),
        'shipping_city'      => (string) $customer->get_shipping_city(),
        'shipping_state'     => (string) $customer->get_shipping_state(),
        'shipping_postcode'  => (string) $customer->get_shipping_postcode(),
        'shipping_country'   => (string) $customer->get_shipping_country(),
    ];

    if ($with_last_order) {
        $last = nibwp_wcpro_customer_last_order($customer, $user_id);
        if (is_object($last) && method_exists($last, 'get_id')) {
            $raw['last_order_id'] = (int) $last->get_id();
            $created = method_exists($last, 'get_date_created') ? $last->get_date_created() : null;
            $raw['last_order_date'] = is_object($created) && method_exists($created, 'date') ? (string) $created->date('Y-m-d H:i:s') : '';
        }
    }

    return $raw;
}

/** The customer's most recent order, or null. Both spellings WooCommerce has had. */
function nibwp_wcpro_customer_last_order(object $customer, int $user_id): ?object
{
    $order = null;
    if (method_exists($customer, 'get_last_order')) {
        $order = $customer->get_last_order();
    } elseif (function_exists('wc_get_customer_last_order')) {
        $order = wc_get_customer_last_order($user_id);
    }

    return is_object($order) ? $order : null;
}

/**
 * Normalise the criteria a search or a segment is expressed in.
 *
 * Every value is validated rather than cast: "500" and "five hundred" both cast
 * to something, and only one of them is a spend threshold.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_customer_criteria(array $raw, string $label): array|WP_Error
{
    $criteria = [];

    // An array or an object where a threshold belongs is a caller mistake worth a
    // sentence: (string) on one of those is "Array" and a notice, and then every
    // comparison below quietly means something else.
    foreach (['min_spend', 'max_spend', 'min_orders', 'max_orders', 'last_order_after', 'last_order_before', 'registered_after', 'registered_before'] as $key) {
        if (array_key_exists($key, $raw) && !is_scalar($raw[$key]) && $raw[$key] !== null) {
            return nibwp_wcpro_err('invalid_value', sprintf('%s.%s must be a single value, not a list.', $label, $key), 422);
        }
    }

    foreach (['min_spend', 'max_spend'] as $key) {
        if (!array_key_exists($key, $raw) || trim((string) $raw[$key]) === '') {
            continue;
        }
        $value = nibwp_wcpro_price($raw[$key], sprintf('%s.%s', $label, $key));
        if (is_wp_error($value)) {
            return $value;
        }
        $criteria[$key] = (float) $value;
    }

    foreach (['min_orders', 'max_orders'] as $key) {
        if (!array_key_exists($key, $raw) || trim((string) $raw[$key]) === '') {
            continue;
        }
        if (!is_numeric($raw[$key]) || (int) $raw[$key] < 0) {
            return nibwp_wcpro_err('invalid_value', sprintf('%s.%s must be a whole number of orders, 0 or more.', $label, $key), 422);
        }
        $criteria[$key] = (int) $raw[$key];
    }

    foreach (['last_order_after', 'last_order_before', 'registered_after', 'registered_before'] as $key) {
        if (!array_key_exists($key, $raw) || trim((string) $raw[$key]) === '') {
            continue;
        }
        $date = nibwp_wcpro_date($raw[$key], sprintf('%s.%s', $label, $key));
        if (is_wp_error($date)) {
            return $date;
        }
        $criteria[$key] = $date;
    }

    if (array_key_exists('never_ordered', $raw) && nibwp_wcpro_bool($raw['never_ordered'])) {
        $criteria['never_ordered'] = true;
    }

    if (isset($criteria['min_spend'], $criteria['max_spend']) && $criteria['min_spend'] > $criteria['max_spend']) {
        return nibwp_wcpro_err('impossible_criteria', sprintf('%s asks for a spend above %s and below %s, which nothing can satisfy.', $label, (string) $criteria['min_spend'], (string) $criteria['max_spend']), 422);
    }
    if (isset($criteria['min_orders'], $criteria['max_orders']) && $criteria['min_orders'] > $criteria['max_orders']) {
        return nibwp_wcpro_err('impossible_criteria', sprintf('%s asks for more than %d orders and fewer than %d.', $label, $criteria['min_orders'], $criteria['max_orders']), 422);
    }

    return $criteria;
}

/** Does a criteria set need the last order date, which costs a query per customer? */
function nibwp_wcpro_criteria_needs_last_order(array $criteria): bool
{
    return isset($criteria['last_order_after']) || isset($criteria['last_order_before']);
}

/**
 * @param array<string, mixed> $raw      a record straight from nibwp_wcpro_customer_raw()
 * @param array<string, mixed> $criteria
 */
function nibwp_wcpro_customer_matches(array $raw, array $criteria): bool
{
    $orders = (int) ($raw['order_count'] ?? 0);
    $spent = (float) ($raw['total_spent'] ?? 0);

    if (($criteria['never_ordered'] ?? false) === true && $orders > 0) {
        return false;
    }
    if (isset($criteria['min_orders']) && $orders < (int) $criteria['min_orders']) {
        return false;
    }
    if (isset($criteria['max_orders']) && $orders > (int) $criteria['max_orders']) {
        return false;
    }
    if (isset($criteria['min_spend']) && $spent < (float) $criteria['min_spend']) {
        return false;
    }
    if (isset($criteria['max_spend']) && $spent > (float) $criteria['max_spend']) {
        return false;
    }

    $last = substr((string) ($raw['last_order_date'] ?? ''), 0, 10);
    if (isset($criteria['last_order_after'])) {
        if ($last === '' || $last < substr((string) $criteria['last_order_after'], 0, 10)) {
            return false;
        }
    }
    if (isset($criteria['last_order_before'])) {
        if ($last !== '' && $last > substr((string) $criteria['last_order_before'], 0, 10)) {
            return false;
        }
        // No order at all counts as "not since": a customer who never ordered has
        // not ordered since March either, and excluding them would hide the group
        // a win-back campaign is actually for. A customer WITH orders whose last
        // order date could not be read is a different case — unknown, so it is
        // not claimed either way.
        if ($last === '' && $orders > 0) {
            return false;
        }
    }

    return true;
}

/**
 * Scan a bounded window of customers once and hand back the raw records the
 * account is allowed to read.
 *
 * The registration-date criteria are pushed into the query rather than filtered
 * afterwards, because narrowing the window is the only way a scan of a large
 * store answers at all.
 *
 * @return array{records: array<int, array<string, mixed>>, scanned: int, skipped: int, total: int, window_full: bool}|WP_Error
 */
function nibwp_wcpro_customer_scan(array $input, array $criteria, bool $with_last_order): array|WP_Error
{
    if (!class_exists('WP_User_Query')) {
        return nibwp_wcpro_err('wp_class_missing', 'WP_User_Query is not available, so customers cannot be scanned.', 500);
    }

    $scan_limit = min(max((int) ($input['scan_limit'] ?? 200), 1), 2000);
    $role = nibwp_wcpro_text($input['role'] ?? 'customer', 60);

    $args = [
        'number'      => $scan_limit,
        'offset'      => max(0, (int) ($input['offset'] ?? 0)),
        'orderby'     => 'registered',
        'order'       => 'DESC',
        'fields'      => 'ID',
        'count_total' => true,
    ];
    if ($role !== '' && $role !== 'any') {
        $args['role__in'] = [$role];
    }

    $date_query = [];
    if (isset($criteria['registered_after'])) {
        $date_query['after'] = (string) $criteria['registered_after'];
    }
    if (isset($criteria['registered_before'])) {
        $date_query['before'] = (string) $criteria['registered_before'];
    }
    if ($date_query !== []) {
        $date_query['inclusive'] = true;
        $args['date_query'] = [$date_query];
    }

    $query = new WP_User_Query($args);
    $ids = (array) $query->get_results();
    $total = (int) $query->get_total();

    $records = [];
    $skipped = 0;
    foreach ($ids as $id) {
        $id = (int) $id;
        // Per customer, not per role. WooCommerce filters edit_user so a shop
        // manager reaches customers and not administrators, and this honours that
        // filter rather than second-guessing who it lets through.
        if (!current_user_can('edit_user', $id)) {
            $skipped++;
            continue;
        }
        $user = function_exists('get_userdata') ? get_userdata($id) : null;
        if (!is_object($user)) {
            $skipped++;
            continue;
        }
        $raw = nibwp_wcpro_customer_raw($id, $user, $with_last_order);
        if (is_wp_error($raw)) {
            return $raw;
        }
        if (!nibwp_wcpro_customer_matches($raw, $criteria)) {
            continue;
        }
        $records[] = $raw;
    }

    return [
        'records'     => $records,
        'scanned'     => count($ids),
        'skipped'     => $skipped,
        'total'       => $total,
        'window_full' => count($ids) >= $scan_limit && $total > count($ids),
    ];
}

/**
 * The bounded set of a customer's orders, newest first.
 *
 * @return array<int, object>|WP_Error
 */
function nibwp_wcpro_customer_orders(int $customer_id, int $limit): array|WP_Error
{
    $denied = nibwp_wcpro_require_function('wc_get_orders', 'Reading a customer\'s orders');
    if ($denied !== null) {
        return $denied;
    }

    $orders = [];
    foreach ((array) wc_get_orders([
        'customer_id' => $customer_id,
        'limit'       => $limit,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'return'      => 'objects',
    ]) as $order) {
        if (is_object($order) && method_exists($order, 'get_id')) {
            $orders[] = $order;
        }
    }

    return $orders;
}

/**
 * One order, as this hub reports it.
 *
 * @return array<string, mixed>
 */
function nibwp_wcpro_customer_order_summary(object $order): array
{
    $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;

    return [
        'id'       => (int) $order->get_id(),
        'number'   => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order->get_id(),
        'status'   => method_exists($order, 'get_status') ? (string) $order->get_status() : '',
        'date'     => is_object($created) && method_exists($created, 'date') ? (string) $created->date('Y-m-d H:i:s') : '',
        'total'    => method_exists($order, 'get_total') ? (string) $order->get_total() : '',
        'currency' => method_exists($order, 'get_currency') ? (string) $order->get_currency() : '',
        'items'    => method_exists($order, 'get_item_count') ? (int) $order->get_item_count() : 0,
        'billing_email' => method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '',
        'customer_id'   => method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0,
    ];
}

/** How many of the customer's orders to read. Bounded, because a loop here is per-order queries. */
function nibwp_wcpro_order_limit(array $input, int $default = 20, int $max = 100): int
{
    return min(max((int) ($input['order_limit'] ?? $default), 1), $max);
}

/* ------------------------------------------------------------------ searching */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_search_customers(array $input): array|WP_Error
{
    $criteria = nibwp_wcpro_customer_criteria((array) ($input['criteria'] ?? []), 'criteria');
    if (is_wp_error($criteria)) {
        return $criteria;
    }

    $scan = nibwp_wcpro_customer_scan($input, $criteria, nibwp_wcpro_criteria_needs_last_order($criteria));
    if (is_wp_error($scan)) {
        return $scan;
    }

    $window = nibwp_wcpro_paginate($input, 20, 200);
    $page = array_slice($scan['records'], $window['offset'], $window['per_page']);

    $items = [];
    foreach ($page as $raw) {
        $items[] = nibwp_wcpro_customer_record($raw);
    }

    $steps = [];
    if ($scan['window_full']) {
        $steps[] = sprintf(
            'The scan window was full: %d of %d customers were examined. Raise scan_limit, or narrow with criteria.registered_after — that one is applied in the query rather than after it.',
            $scan['scanned'],
            $scan['total'],
        );
    }
    if ($scan['skipped'] > 0) {
        $steps[] = sprintf('%d record(s) were skipped because this account may not read them. They are not in any number above.', $scan['skipped']);
    }
    if (!nibwp_wcpro_criteria_needs_last_order($criteria)) {
        $steps[] = 'last_order_date is empty here on purpose: it costs a query per customer and no criterion asked for it. Pass criteria.last_order_before or .last_order_after to have it read.';
    }

    return nibwp_wcpro_ok(
        'search_customers',
        sprintf('%d of %d scanned customer(s) match.', count($scan['records']), $scan['scanned']),
        [
            'items' => $items,
            'count' => count($items),
            'result' => [
                'matched'     => count($scan['records']),
                'scanned'     => $scan['scanned'],
                'skipped'     => $scan['skipped'],
                'total_users' => $scan['total'],
                'window_full' => $scan['window_full'],
                'criteria'    => $criteria,
            ],
            'next_steps' => $steps,
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_get_customer_profile(array $input): array|WP_Error
{
    $target = nibwp_wcpro_customer_target($input);
    if (is_wp_error($target)) {
        return $target;
    }

    $raw = nibwp_wcpro_customer_raw($target['id'], $target['user'], true);
    if (is_wp_error($raw)) {
        return $raw;
    }

    $orders = nibwp_wcpro_customer_orders($target['id'], nibwp_wcpro_order_limit($input));
    if (is_wp_error($orders)) {
        return $orders;
    }

    $summaries = [];
    $notes = 0;
    $first_date = '';
    foreach ($orders as $order) {
        $summary = nibwp_wcpro_customer_order_summary($order);
        $summaries[] = $summary;
        if ($summary['date'] !== '' && ($first_date === '' || $summary['date'] < $first_date)) {
            $first_date = $summary['date'];
        }
        if (function_exists('wc_get_order_notes')) {
            $notes += count((array) wc_get_order_notes(['order_id' => $summary['id'], 'limit' => 100]));
        }
    }

    return nibwp_wcpro_ok(
        'get_customer_profile',
        sprintf(
            'Customer %d: %d order(s), %s spent, average %s.',
            $target['id'],
            (int) $raw['order_count'],
            (string) $raw['total_spent'],
            (string) $raw['average_order_value'],
        ),
        [
            'result' => [
                'customer' => nibwp_wcpro_customer_record($raw),
                'orders'   => $summaries,
                'orders_read' => count($summaries),
                'first_order_date' => $first_date,
                'order_notes_count' => $notes,
            ],
            'next_steps' => count($summaries) >= nibwp_wcpro_order_limit($input)
                ? [sprintf('Only the %d most recent orders were read. Raise order_limit for more.', count($summaries))]
                : [],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_segment_customers(array $input): array|WP_Error
{
    $given = (array) ($input['segments'] ?? []);
    $definitions = $given === [] ? nibwp_wcpro_customer_lifecycle_preset() : $given;

    $segments = [];
    $needs_last_order = false;
    foreach ($definitions as $index => $definition) {
        $definition = (array) $definition;
        $name = nibwp_wcpro_text($definition['name'] ?? '', 80);
        if ($name === '') {
            $name = sprintf('segment_%d', (int) $index + 1);
        }
        $criteria = nibwp_wcpro_customer_criteria($definition, sprintf('segments[%s]', (string) $index));
        if (is_wp_error($criteria)) {
            return $criteria;
        }
        $segments[] = ['name' => $name, 'criteria' => $criteria];
        $needs_last_order = $needs_last_order || nibwp_wcpro_criteria_needs_last_order($criteria);
    }

    if ($segments === []) {
        return nibwp_wcpro_err('no_segments', 'Give segments:[{name, …criteria}], or leave segments out to use the lifecycle preset.', 422);
    }

    // One scan for every segment. Scanning per segment would multiply the cost by
    // the number of groups and could report inconsistent totals, because a
    // customer's order count can change between two scans.
    $scan = nibwp_wcpro_customer_scan($input, [], $needs_last_order);
    if (is_wp_error($scan)) {
        return $scan;
    }

    $max_ids = min(max((int) ($input['max_ids'] ?? 50), 1), 500);
    $items = [];
    $placed = [];
    foreach ($segments as $segment) {
        $ids = [];
        foreach ($scan['records'] as $raw) {
            if (!nibwp_wcpro_customer_matches($raw, $segment['criteria'])) {
                continue;
            }
            $id = (int) $raw['id'];
            $ids[] = $id;
            $placed[$id] = (int) ($placed[$id] ?? 0) + 1;
        }
        $items[] = [
            'name'          => $segment['name'],
            'criteria'      => $segment['criteria'],
            'count'         => count($ids),
            'ids'           => array_slice($ids, 0, $max_ids),
            'ids_truncated' => count($ids) > $max_ids,
        ];
    }

    $overlapping = 0;
    foreach ($placed as $count) {
        if ($count > 1) {
            $overlapping++;
        }
    }

    $steps = [];
    if ($overlapping > 0) {
        $steps[] = sprintf('%d customer(s) fall in more than one segment, so the counts do not add up to the base. The groups are filters, not buckets.', $overlapping);
    }
    if ($scan['window_full']) {
        $steps[] = sprintf('Every count is over the %d customers scanned, not the %d the store has. Raise scan_limit before quoting these to anyone.', $scan['scanned'], $scan['total']);
    }
    if ($scan['skipped'] > 0) {
        $steps[] = sprintf('%d record(s) this account may not read were skipped and are in no segment.', $scan['skipped']);
    }
    $unplaced = count($scan['records']) - count($placed);
    if ($unplaced > 0) {
        $steps[] = sprintf('%d scanned customer(s) matched no segment.', $unplaced);
    }

    return nibwp_wcpro_ok(
        'segment_customers',
        sprintf('%d segment(s) over %d scanned customer(s).', count($items), $scan['scanned']),
        [
            'items' => $items,
            'count' => count($items),
            'result' => [
                'scanned'     => $scan['scanned'],
                'skipped'     => $scan['skipped'],
                'total_users' => $scan['total'],
                'window_full' => $scan['window_full'],
                'preset_used' => $given === [] ? 'lifecycle' : '',
            ],
            'next_steps' => $steps,
        ],
    );
}

/**
 * The default grouping, when the caller did not define one.
 *
 * Lifecycle rather than value, because the thresholds are the same in every
 * currency — a "high value" cut at 500 means nothing without knowing what the
 * shop sells.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_wcpro_customer_lifecycle_preset(): array
{
    $day = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    $lapsed_before = gmdate('Y-m-d', time() - (180 * $day));

    return [
        ['name' => 'never_ordered', 'never_ordered' => true],
        ['name' => 'one_time', 'min_orders' => 1, 'max_orders' => 1],
        ['name' => 'repeat', 'min_orders' => 2, 'max_orders' => 4],
        ['name' => 'loyal', 'min_orders' => 5],
        ['name' => 'lapsed', 'min_orders' => 1, 'last_order_before' => $lapsed_before],
    ];
}

/* --------------------------------------------------------------------- notes */

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_list_customer_notes(array $input): array|WP_Error
{
    $target = nibwp_wcpro_customer_target($input);
    if (is_wp_error($target)) {
        return $target;
    }

    // Order notes are order data. WooCommerce gates its Orders screens on this
    // primitive, and with HPOS there is no post to map a meta capability against
    // at the list level, so this is the honest equivalent of "could open Orders".
    $denied = nibwp_wcpro_require_cap('edit_shop_orders', 'Reading order notes');
    if ($denied !== null) {
        return $denied;
    }
    $denied = nibwp_wcpro_require_function('wc_get_order_notes', 'Reading order notes');
    if ($denied !== null) {
        return $denied;
    }

    $type = nibwp_wcpro_enum($input['note_type'] ?? 'all', ['all', 'customer', 'internal'], 'note_type', 'all');
    if (is_wp_error($type)) {
        return $type;
    }

    $only_order = (int) ($input['order_id'] ?? 0);
    $orders = nibwp_wcpro_customer_orders($target['id'], nibwp_wcpro_order_limit($input));
    if (is_wp_error($orders)) {
        return $orders;
    }

    $items = [];
    $skipped = 0;
    $read = 0;
    foreach ($orders as $order) {
        $order_id = (int) $order->get_id();
        if ($only_order > 0 && $order_id !== $only_order) {
            continue;
        }
        // Per order, because a shop manager's reach can be narrower than the
        // primitive suggests and a note quotes the order it hangs on.
        if (!current_user_can('edit_shop_order', $order_id)) {
            $skipped++;
            continue;
        }
        $read++;
        $args = ['order_id' => $order_id, 'limit' => 100];
        if ($type !== 'all') {
            $args['type'] = $type;
        }
        foreach ((array) wc_get_order_notes($args) as $note) {
            if (!is_object($note)) {
                continue;
            }
            $added = $note->date_created ?? null;
            $items[] = [
                'id'       => (int) ($note->id ?? 0),
                'order_id' => $order_id,
                'note'     => (string) ($note->content ?? ''),
                'added_by' => (string) ($note->added_by ?? ''),
                'customer_note' => (bool) ($note->customer_note ?? false),
                // A WC_DateTime answers ->date(); anything else object-shaped has no
                // __toString to fall back on, so it reports as empty rather than fataling.
                'date'     => is_object($added)
                    ? (method_exists($added, 'date') ? (string) $added->date('Y-m-d H:i:s') : '')
                    : (string) $added,
            ];
        }
    }

    if ($only_order > 0 && $read === 0) {
        return nibwp_wcpro_err(
            'order_not_this_customer',
            sprintf('Order %d is not among the %d most recent orders of customer %d. Raise order_limit, or check the order id.', $only_order, count($orders), $target['id']),
            404,
        );
    }

    return nibwp_wcpro_ok(
        'list_customer_notes',
        sprintf('%d note(s) across %d of customer %d\'s orders.', count($items), $read, $target['id']),
        [
            'items' => $items,
            'count' => count($items),
            'result' => ['customer_id' => $target['id'], 'orders_read' => $read, 'orders_skipped' => $skipped, 'note_type' => $type],
            'next_steps' => $skipped > 0
                ? [sprintf('%d order(s) were skipped because this account may not edit them.', $skipped)]
                : [],
        ],
    );
}

/** @return array<string, mixed>|WP_Error */
function nibwp_wcpro_add_customer_note(array $input): array|WP_Error
{
    $target = nibwp_wcpro_customer_target($input);
    if (is_wp_error($target)) {
        return $target;
    }

    $note = trim((string) ($input['note'] ?? ''));
    if ($note === '') {
        return nibwp_wcpro_err('missing_note', 'Provide note — the text to add.', 422);
    }
    $note = function_exists('wp_kses_post') ? (string) wp_kses_post($note) : nibwp_wcpro_text($note);

    $denied = nibwp_wcpro_require_cap('edit_shop_orders', 'Adding an order note');
    if ($denied !== null) {
        return $denied;
    }

    $orders = nibwp_wcpro_customer_orders($target['id'], nibwp_wcpro_order_limit($input));
    if (is_wp_error($orders)) {
        return $orders;
    }
    if ($orders === []) {
        return nibwp_wcpro_err('no_orders', sprintf('Customer %d has no orders, so there is nothing to note against.', $target['id']), 404);
    }

    $wanted = (int) ($input['order_id'] ?? 0);
    $order = null;
    foreach ($orders as $candidate) {
        if ($wanted === 0 || (int) $candidate->get_id() === $wanted) {
            $order = $candidate;
            break;
        }
    }
    if (!is_object($order)) {
        // Refusing rather than noting the most recent order instead: a note on the
        // wrong order is visible to the customer and cannot be unsent.
        return nibwp_wcpro_err(
            'order_not_this_customer',
            sprintf('Order %d is not among the %d most recent orders of customer %d, so nothing was written. Check the id, or raise order_limit.', $wanted, count($orders), $target['id']),
            404,
        );
    }

    $order_id = (int) $order->get_id();
    // The object-level question about the thing being written.
    if (!current_user_can('edit_shop_order', $order_id)) {
        return nibwp_wcpro_forbidden(sprintf('Not allowed to edit order %d, so no note was added.', $order_id));
    }
    if (!method_exists($order, 'add_order_note')) {
        return nibwp_wcpro_err('note_not_supported', sprintf('Order %d has no add_order_note() on this site.', $order_id), 409);
    }

    $is_customer_note = nibwp_wcpro_bool($input['customer_note'] ?? false);
    $note_id = (int) $order->add_order_note($note, $is_customer_note ? 1 : 0, true);

    return nibwp_wcpro_ok(
        'add_customer_note',
        sprintf(
            'Added %s note %d to order %d.',
            $is_customer_note ? 'a CUSTOMER-VISIBLE' : 'an internal',
            $note_id,
            $order_id,
        ),
        [
            'result' => [
                'note_id'       => $note_id,
                'order_id'      => $order_id,
                'customer_id'   => $target['id'],
                'customer_note' => $is_customer_note,
            ],
            'next_steps' => $is_customer_note
                ? ['This note is visible to the customer and WooCommerce emails it to them when the "customer_note" email is enabled — check that with nibwp/wcpro-emails { action:"get_email", email_id:"customer_note" }. It cannot be unsent.']
                : ['Internal notes are visible to staff on the order screen only. Pass customer_note:true if the customer was meant to see it.'],
        ],
    );
}

/* ------------------------------------------------------------- guest orders */

/**
 * Guest orders that share an email with a registered account.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_find_guest_orders(array $input): array|WP_Error
{
    $resolved = nibwp_wcpro_guest_order_scope($input);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $items = [];
    $total = 0.0;
    foreach ($resolved['orders'] as $order) {
        $summary = nibwp_wcpro_customer_order_summary($order);
        $summary['editable'] = current_user_can('edit_shop_order', $summary['id']);
        $items[] = $summary;
        $total += (float) $summary['total'];
    }

    $steps = [];
    if ($items !== [] && $resolved['account_id'] > 0) {
        $steps[] = sprintf(
            'Attach them with link_guest_orders { customer_id: %d, dry_run: true } first, then again without dry_run. It is a write to this person\'s order history — tell them what it will attach.',
            $resolved['account_id'],
        );
    }
    if ($items !== [] && $resolved['account_id'] === 0) {
        $steps[] = sprintf('There is no account with the email "%s", so there is nothing to attach these orders to yet. Create one with the Free nibwp/wc-create-customer first.', $resolved['email']);
    }
    if (count($items) >= $resolved['limit']) {
        $steps[] = sprintf('The %d-order window was full; there may be more. Raise order_limit.', $resolved['limit']);
    }

    return nibwp_wcpro_ok(
        'find_guest_orders',
        sprintf('%d guest order(s) placed with %s.', count($items), $resolved['email']),
        [
            'items' => $items,
            'count' => count($items),
            'result' => [
                'email'      => $resolved['email'],
                'account_id' => $resolved['account_id'],
                'total_value' => number_format($total, 2, '.', ''),
            ],
            'next_steps' => $steps,
        ],
    );
}

/**
 * The email, the account behind it and the guest orders it placed — the common
 * half of find_guest_orders and link_guest_orders.
 *
 * @return array{email: string, account_id: int, orders: array<int, object>, limit: int}|WP_Error
 */
function nibwp_wcpro_guest_order_scope(array $input): array|WP_Error
{
    $denied = nibwp_wcpro_require_cap('edit_shop_orders', 'Reading guest orders');
    if ($denied !== null) {
        return $denied;
    }
    $denied = nibwp_wcpro_require_function('wc_get_orders', 'Reading guest orders');
    if ($denied !== null) {
        return $denied;
    }

    $email = trim((string) ($input['email'] ?? ''));
    $account_id = (int) ($input['customer_id'] ?? 0);

    if ($account_id > 0) {
        // The account's own record is being read and, in link_guest_orders,
        // written to — so ask about that person before anything else.
        $target = nibwp_wcpro_customer_target(['customer_id' => $account_id]);
        if (is_wp_error($target)) {
            return $target;
        }
        $email = (string) ($target['user']->user_email ?? $email);
    }

    if ($email === '') {
        return nibwp_wcpro_err('missing_customer_ref', 'Provide customer_id, or the email the guest orders were placed with.', 422);
    }
    if (!nibwp_wcpro_is_email($email)) {
        return nibwp_wcpro_err('invalid_email', sprintf('"%s" is not a valid email address.', $email), 422);
    }

    if ($account_id === 0 && function_exists('get_user_by')) {
        $found = get_user_by('email', $email);
        if (is_object($found)) {
            $candidate = (int) ($found->ID ?? 0);
            // An account exists for this email, so reading or writing its orders
            // is reading or writing that person's history: ask about them.
            if ($candidate > 0 && !current_user_can('edit_user', $candidate)) {
                return nibwp_wcpro_forbidden(sprintf('An account exists for "%s" and this account may not read it.', $email));
            }
            $account_id = $candidate;
        }
    }

    $limit = nibwp_wcpro_order_limit($input, 50, 200);

    $orders = [];
    foreach ((array) wc_get_orders([
        'customer' => $email,
        'limit'    => $limit,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'return'   => 'objects',
    ]) as $order) {
        if (!is_object($order) || !method_exists($order, 'get_customer_id')) {
            continue;
        }
        // A guest order is one with no account on it. The same query also returns
        // the account's own orders, which are not what this is about.
        if ((int) $order->get_customer_id() !== 0) {
            continue;
        }
        $orders[] = $order;
    }

    return ['email' => $email, 'account_id' => $account_id, 'orders' => $orders, 'limit' => $limit];
}

/**
 * Attach guest orders to the account that shares their email.
 *
 * A write to somebody's order history, and one the account holder sees: a linked
 * order appears in My Account with its items, addresses and totals. So an order
 * whose billing email is not the account's is refused however it was named —
 * otherwise this is a way to show one customer another customer's order.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wcpro_link_guest_orders(array $input): array|WP_Error
{
    $account_id = (int) ($input['customer_id'] ?? 0);
    if ($account_id <= 0) {
        return nibwp_wcpro_err('missing_customer_ref', 'Provide customer_id — the account the orders are attached to.', 422);
    }

    $resolved = nibwp_wcpro_guest_order_scope($input);
    if (is_wp_error($resolved)) {
        return $resolved;
    }
    if ($resolved['account_id'] !== $account_id) {
        return nibwp_wcpro_err('account_mismatch', sprintf('Customer %d could not be resolved for linking.', $account_id), 409);
    }

    $wanted = nibwp_wcpro_ids($input['order_ids'] ?? []);
    $dry_run = nibwp_wcpro_bool($input['dry_run'] ?? false);
    $account_email = strtolower($resolved['email']);

    $candidates = [];
    foreach ($resolved['orders'] as $order) {
        $candidates[(int) $order->get_id()] = $order;
    }

    // A named order that the guest-order query did not return is reported rather
    // than fetched separately: it either belongs to somebody else, already has an
    // account, or is outside the window, and all three deserve a sentence.
    $linked = [];
    $skipped = [];
    $ids = $wanted === [] ? array_keys($candidates) : $wanted;

    foreach ($ids as $order_id) {
        $order_id = (int) $order_id;
        $order = $candidates[$order_id] ?? null;
        if (!is_object($order)) {
            $skipped[] = ['order_id' => $order_id, 'reason' => 'not a guest order placed with this account\'s email, or outside the window read'];
            continue;
        }
        if (!current_user_can('edit_shop_order', $order_id)) {
            $skipped[] = ['order_id' => $order_id, 'reason' => 'this account may not edit that order'];
            continue;
        }
        $billing = strtolower(method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '');
        if ($billing === '' || $billing !== $account_email) {
            $skipped[] = ['order_id' => $order_id, 'reason' => sprintf('billing email "%s" is not the account\'s', $billing)];
            continue;
        }
        if (!method_exists($order, 'set_customer_id') || !method_exists($order, 'save')) {
            $skipped[] = ['order_id' => $order_id, 'reason' => 'this order object cannot be written on this site'];
            continue;
        }

        $summary = nibwp_wcpro_customer_order_summary($order);
        if (!$dry_run) {
            $order->set_customer_id($account_id);
            $order->save();
            if (method_exists($order, 'add_order_note')) {
                $order->add_order_note(
                    sprintf('Guest order attached to account #%d (%s).', $account_id, $resolved['email']),
                    0,
                    true,
                );
            }
        }
        $linked[] = $summary;
    }

    $total = 0.0;
    foreach ($linked as $order) {
        $total += (float) $order['total'];
    }

    return nibwp_wcpro_ok(
        'link_guest_orders',
        $dry_run
            ? sprintf('Dry run: %d guest order(s) would be attached to customer %d, %d skipped.', count($linked), $account_id, count($skipped))
            : sprintf('Attached %d guest order(s) to customer %d, %d skipped.', count($linked), $account_id, count($skipped)),
        [
            'dry_run' => $dry_run,
            'items'   => $linked,
            'count'   => count($linked),
            'problems' => $skipped,
            'result' => [
                'customer_id' => $account_id,
                'email'       => $resolved['email'],
                'linked'      => count($linked),
                'skipped'     => count($skipped),
                'total_value' => number_format($total, 2, '.', ''),
            ],
            'next_steps' => $dry_run
                ? ['Nothing was written. Tell the user which orders this would attach — they become visible in that account\'s My Account — then call again with dry_run:false.']
                : ['Each linked order carries a note saying what happened, so the change is on the record. The customer now sees these orders in My Account.'],
        ],
    );
}
