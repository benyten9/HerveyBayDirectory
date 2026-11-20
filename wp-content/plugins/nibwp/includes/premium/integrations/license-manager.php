<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: License Manager for WooCommerce.
 *
 * Verified against 3.0.20 (license-manager-for-woocommerce.php:6, and the same
 * string in LMFWC_VERSION at :39). The integration key is license-manager; the
 * plugin directory is license-manager-for-woocommerce and its own prefix is
 * lmfwc_, which is why every function here is nibwp_lmfw_ — short enough to read,
 * far enough from the plugin's own namespace to never be mistaken for it.
 *
 * BEARER CREDENTIALS, which is the whole shape of this file. A licence key is not
 * a record about a customer, it is the thing that makes the customer's software
 * run. Whoever holds the characters can activate the product, so a key that
 * travels out of here in a listing has been copied to wherever that listing
 * lands: a chat transcript, a log, an agent's scratch memory. The plugin's own
 * REST API returns whole decrypted keys in a collection response
 * (includes/Api/V2/Licenses.php:304) — that is a reasonable choice for a licence
 * server talking to a client that already holds the key, and the wrong choice for
 * an agent browsing a shop. So:
 *
 *   - every listing, search and audit returns the plugin's row id, a MASKED key
 *     (at most the last four characters) and the metadata an owner actually
 *     searches on: status, product, order, customer, expiry, activation counts;
 *   - exactly one action returns a whole key, reveal_license_key_in_full. It is
 *     named so that nobody reaches it by accident, it needs manage_woocommerce
 *     and edit_shop_order on the order the key was sold on, it needs confirm,
 *     and its own response says it just handed over a live credential;
 *   - the ciphertext column, the `hash` column (an HMAC of the key, which is a
 *     verifier for it) and the activation `token` never travel at all.
 *
 * HOW KEYS ARE STORED, because the masking depends on it. wp_lmfwc_licenses holds
 * `license_key` as Defuse ciphertext and `hash` as HMAC-SHA256 of the plaintext
 * (Setup.php:162-163; functions/lmfwc-license-functions.php:77-78). The symmetric
 * key lives in wp-content/uploads/lmfwc-files/defuse.txt and the HMAC secret in
 * secret.txt, unless the site defines them as constants instead, which the
 * handbook recommends and which usually means they sit in wp-config.php
 * (Crypto.php:75-99, Setup.php:265). Nothing in this file reads either one, by
 * either road: no constant lookup, no file read, no uploads path. A masked key
 * therefore costs one decryption per row, and a decryption that fails is reported
 * per row rather than failing the whole listing.
 *
 * DECRYPTION THROWS. Crypto::decrypt() swallows only
 * WrongKeyOrModifiedCiphertextException and then returns null (Crypto.php:143-155);
 * BadFormatException and EnvironmentIsBrokenException from
 * loadEncryptionKeyFromConfig() pass straight through, and when the key file is
 * missing that method hands DefuseCrypto::decrypt() an empty string where a Key
 * object belongs (Crypto.php:109-116), which is a TypeError. All three reach us
 * as a Throwable, and all three mean the same thing to an owner: the key that
 * encrypted these rows is not the key this site is holding now. Hence one named
 * refusal, nibwp_lmfw_decrypt_failed, and never a fatal.
 *
 * STATUSES. Four, and only four: sold=1, delivered=2, active=3, inactive=4
 * (Enums/LicenseStatus.php:17-38). There is no "disabled" status in this build,
 * although Enums/LicenseStatus.php:210 switches on LicenseStatus::DISABLED — a
 * constant the class never declares, so that branch is an undefined-constant
 * Error waiting for a status outside the four. We do not offer it and we do not
 * write it.
 *
 * ORDERS: posts or HPOS. The plugin declares itself compatible with custom order
 * tables (license-manager-for-woocommerce.php:41-46) and keeps only an order_id
 * integer of its own (Setup.php:159), so it does not care which store is live and
 * neither do we: orders are read through wc_get_order()/wc_get_orders(), which
 * answer from whichever store WooCommerce is using. The object-level capability is
 * current_user_can('edit_shop_order', $id) — a meta capability WooCommerce maps
 * under both stores — and the response reports which store answered, because
 * "the order is not there" means different things on the two.
 *
 * WRITES. Status, expiry and activation limit each decide what a paying customer
 * can do tomorrow, so each one reports what it changed from and to, and says in
 * plain words what the customer will notice. delete_license is irreversible and
 * will strand whoever is holding that key: it is confirm-gated and says so twice.
 *
 * NOT HERE, on purpose: activating a licence on a customer's behalf (that is the
 * customer's software talking to the plugin's API, and a spurious activation
 * consumes a seat they paid for), generating licences into an order, changing the
 * plugin's crypto settings, and touching the API-key table — consumer keys and
 * secrets for the plugin's own REST API live there (Setup.php:204-205) and this
 * integration has no reason to read them.
 */

/** The baseline for every action, read or write. This is shop data throughout. */
const NIBWP_LMFW_CAP = 'manage_woocommerce';

/** Rows any listing will return, whatever was asked for. */
const NIBWP_LMFW_MAX_ROWS = 200;

/** Characters of a key a mask may show. Four is the receipt convention. */
const NIBWP_LMFW_MASK_TAIL = 4;

/**
 * Is the plugin here, and is WooCommerce?
 *
 * Two requirements with two reasons, because they are two different fixes: one is
 * "install the licence plugin", the other is "WooCommerce is off and your shop is
 * down, which is a bigger problem than this call".
 */
function nibwp_lmfw_guard(): ?WP_Error
{
    if (!defined('LMFWC_VERSION') && !class_exists('LicenseManagerForWooCommerce\\Setup')) {
        return new WP_Error(
            'nibwp_lmfw_missing',
            __('License Manager for WooCommerce is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    // The licence plugin loads with or without WooCommerce, but orders, products
    // and customers — which is most of what is worth asking about — are
    // WooCommerce's, and wc_get_order() is what reads them under either store.
    if (!class_exists('WooCommerce') && !function_exists('wc_get_order')) {
        return new WP_Error(
            'nibwp_lmfw_wc_missing',
            __('WooCommerce is not active on this site, so licences cannot be read against their orders, products or customers.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_lmfw_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_lmfw_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_lmfw_forbidden(string $what, string $capability): WP_Error
{
    return new WP_Error(
        'nibwp_lmfw_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $capability
        ),
        ['status' => 403]
    );
}

/** The one baseline gate. Reading a licence is not a lower bar than changing one. */
function nibwp_lmfw_require(string $what): ?WP_Error
{
    return current_user_can(NIBWP_LMFW_CAP) ? null : nibwp_lmfw_forbidden($what, NIBWP_LMFW_CAP);
}

/**
 * The object-level gate for one order.
 *
 * manage_woocommerce says "may run the shop"; edit_shop_order says "may open THIS
 * order". A shop manager's reach can be narrower than the primitive suggests, and
 * a licence row names the order it was sold on, so anything that reads or writes a
 * licence against a specific order asks about that order too.
 */
function nibwp_lmfw_require_order(int $order_id, string $what): ?WP_Error
{
    if ($order_id <= 0) {
        return null;
    }

    if (current_user_can('edit_shop_order', $order_id)) {
        return null;
    }

    return nibwp_lmfw_forbidden(
        sprintf(
            /* translators: 1: what was being attempted, 2: order id */
            __('%1$s on order %2$d', 'nibwp'),
            $what,
            $order_id
        ),
        'edit_shop_order'
    );
}

/** The object-level gate for one customer account. */
function nibwp_lmfw_require_user(int $user_id, string $what): ?WP_Error
{
    if ($user_id <= 0) {
        return null;
    }

    if (current_user_can('edit_user', $user_id)) {
        return null;
    }

    return nibwp_lmfw_forbidden(
        sprintf(
            /* translators: 1: what was being attempted, 2: user id */
            __('%1$s for customer %2$d', 'nibwp'),
            $what,
            $user_id
        ),
        'edit_user'
    );
}

/**
 * One of the plugin's tables, by a fixed name.
 *
 * $wpdb->prefix rather than base_prefix, because that is what the plugin's own
 * repositories use (Repositories/Resources/License.php:26) — on a network each
 * site has its own licences. The argument is always a literal in this file: no
 * identifier reaches SQL from input, here or anywhere below.
 */
function nibwp_lmfw_table(string $which): string
{
    global $wpdb;

    $tables = [
        'licenses'    => 'lmfwc_licenses',
        'generators'  => 'lmfwc_generators',
        'activations' => 'lmfwc_activations',
    ];

    return $wpdb->prefix . ($tables[$which] ?? 'lmfwc_licenses');
}

/** Is there a database handle to work with at all? */
function nibwp_lmfw_db(): ?WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
        return nibwp_lmfw_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    return null;
}

/**
 * The four statuses this build has, as names an agent can use.
 *
 * One array, read in both directions, so the name list and the number list cannot
 * drift apart.
 *
 * @return array<string, int>
 */
function nibwp_lmfw_statuses(): array
{
    return [
        'sold'      => 1,
        'delivered' => 2,
        'active'    => 3,
        'inactive'  => 4,
    ];
}

/** What a status number means, or null for a number this build never writes. */
function nibwp_lmfw_status_name(?int $status): ?string
{
    if ($status === null) {
        return null;
    }

    $found = array_search($status, nibwp_lmfw_statuses(), true);

    return is_string($found) ? $found : null;
}

/** A status name from input, as the number the column holds. */
function nibwp_lmfw_status_number(mixed $raw): int|WP_Error
{
    $statuses = nibwp_lmfw_statuses();
    $wanted = is_string($raw) ? strtolower(trim($raw)) : '';

    if ($wanted === '' && is_numeric($raw)) {
        // A caller who read the plugin's own docs will send the number.
        $wanted = (string) nibwp_lmfw_status_name((int) $raw);
    }

    if (!isset($statuses[$wanted])) {
        return nibwp_lmfw_err(
            'bad_status',
            sprintf(
                /* translators: 1: the rejected value, 2: list of valid statuses */
                __('"%1$s" is not a licence status. This build has exactly four: %2$s.', 'nibwp'),
                is_scalar($raw) ? (string) $raw : gettype($raw),
                implode(', ', array_keys($statuses))
            ),
            422
        );
    }

    return $statuses[$wanted];
}

/** Where a licence came from (Enums/LicenseSource.php:21-42). */
function nibwp_lmfw_source_name(?int $source): ?string
{
    $sources = [1 => 'generator', 2 => 'import', 3 => 'api', 4 => 'migration'];

    return $source === null ? null : ($sources[$source] ?? null);
}

/**
 * Mask a licence key.
 *
 * At most the last four characters, and nothing at all from a key short enough
 * that four would be most of it. The mask length is capped so the response does
 * not leak how long the key is either.
 */
function nibwp_lmfw_mask(string $key): string
{
    $length = function_exists('mb_strlen') ? (int) mb_strlen($key, 'UTF-8') : strlen($key);

    if ($length <= NIBWP_LMFW_MASK_TAIL) {
        return str_repeat('*', 8);
    }

    $tail = function_exists('mb_substr')
        ? (string) mb_substr($key, -NIBWP_LMFW_MASK_TAIL, NIBWP_LMFW_MASK_TAIL, 'UTF-8')
        : substr($key, -NIBWP_LMFW_MASK_TAIL);

    return str_repeat('*', 8) . $tail;
}

/**
 * Decrypt one stored key, or say why not.
 *
 * Every call into the plugin's crypto goes through here. See the file header for
 * the three ways this fails and why they are one refusal.
 */
function nibwp_lmfw_decrypt(string $cipher): string|WP_Error
{
    if (trim($cipher) === '') {
        return nibwp_lmfw_err(
            'decrypt_failed',
            __('That licence row holds no encrypted key, so there is nothing to read.', 'nibwp'),
            500
        );
    }

    if (!function_exists('has_filter') || !has_filter('lmfwc_decrypt')) {
        return nibwp_lmfw_err(
            'decrypt_unavailable',
            __('The plugin\'s decryption filter is not registered, so its crypto never started on this request. Licence keys cannot be read until it does.', 'nibwp'),
            500
        );
    }

    try {
        $plain = apply_filters('lmfwc_decrypt', $cipher);
    } catch (\Throwable $error) {
        return nibwp_lmfw_err(
            'decrypt_failed',
            sprintf(
                /* translators: %s: the error message from the plugin's crypto */
                __('The stored licence key could not be decrypted: %s. That normally means the encryption key this site is holding is not the one these licences were written with — check wp-content/uploads/lmfwc-files, or the constants a previous install defined, before changing anything.', 'nibwp'),
                $error->getMessage()
            ),
            500
        );
    }

    // The plugin's own decrypt() returns null when the key is wrong rather than
    // raising, so an empty answer is a failure and not an empty key.
    if (!is_string($plain) || $plain === '') {
        return nibwp_lmfw_err(
            'decrypt_failed',
            __('The stored licence key decrypted to nothing, which is what this plugin does when the encryption key has changed since the licence was written. The row is intact; the key to read it is not here.', 'nibwp'),
            500
        );
    }

    return $plain;
}

/** The plugin's own HMAC of a plaintext key — the only way to find a row by key. */
function nibwp_lmfw_hash(string $key): string|WP_Error
{
    if (!function_exists('has_filter') || !has_filter('lmfwc_hash')) {
        return nibwp_lmfw_err(
            'decrypt_unavailable',
            __('The plugin\'s hashing filter is not registered, so a licence cannot be looked up by its key on this request.', 'nibwp'),
            500
        );
    }

    try {
        $hash = apply_filters('lmfwc_hash', $key);
    } catch (\Throwable $error) {
        return nibwp_lmfw_err(
            'decrypt_failed',
            sprintf(
                /* translators: %s: the error message from the plugin's crypto */
                __('The licence key could not be hashed for lookup: %s', 'nibwp'),
                $error->getMessage()
            ),
            500
        );
    }

    return is_string($hash) && $hash !== ''
        ? $hash
        : nibwp_lmfw_err('decrypt_failed', __('The plugin returned no hash for that key, so no row can be matched against it.', 'nibwp'), 500);
}

/** Clamp a row count that arrived over the wire. */
function nibwp_lmfw_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_LMFW_MAX_ROWS, $limit));
}

/** Clamp an offset. */
function nibwp_lmfw_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** A positive row id from input, or a refusal naming the parameter. */
function nibwp_lmfw_id(mixed $raw, string $field): int|WP_Error
{
    if (!is_numeric($raw) || (int) $raw <= 0) {
        return nibwp_lmfw_err(
            'bad_id',
            sprintf(
                /* translators: %s: the input parameter name */
                __('Give %s as a positive whole number.', 'nibwp'),
                $field
            ),
            422
        );
    }

    return (int) $raw;
}

/**
 * A UTC datetime from input, normalised to the column's format.
 *
 * The column is DATETIME (Setup.php:164) and the plugin compares it against
 * "now" in UTC when deciding whether a licence has expired
 * (functions/lmfwc-license-functions.php:375-390), so a bare date is read as the
 * very start of that day in UTC — which is a customer losing the licence a day
 * earlier than whoever typed the date probably meant. Every response that writes
 * one says so.
 */
function nibwp_lmfw_datetime(mixed $raw): string|WP_Error
{
    $value = is_string($raw) ? trim($raw) : '';

    if ($value === '') {
        return nibwp_lmfw_err('bad_date', __('Give the date as YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, in UTC.', 'nibwp'), 422);
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $value) === 1) {
        $value .= ' 00:00:00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
        return nibwp_lmfw_err(
            'bad_date',
            sprintf(
                /* translators: %s: the rejected value */
                __('"%s" is not a date this column accepts. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, in UTC.', 'nibwp'),
                $value
            ),
            422
        );
    }

    $stamp = strtotime($value . ' UTC');

    if ($stamp === false) {
        return nibwp_lmfw_err(
            'bad_date',
            sprintf(
                /* translators: %s: the rejected value */
                __('"%s" has the right shape but is not a real date.', 'nibwp'),
                $value
            ),
            422
        );
    }

    return gmdate('Y-m-d H:i:s', $stamp);
}

/** Now, in the format the plugin's own columns use. */
function nibwp_lmfw_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Which order store answered — the same licence row reads differently on each. */
function nibwp_lmfw_order_storage(): string
{
    $util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';

    if (class_exists($util) && method_exists($util, 'custom_orders_table_usage_is_enabled')) {
        try {
            return $util::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts';
        } catch (\Throwable $error) {
            return 'unknown';
        }
    }

    return 'posts';
}

/**
 * One raw licence row by id.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_find_license(int $id): array|WP_Error
{
    global $wpdb;

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses') . ' WHERE id = %d LIMIT %d';
    $row = $wpdb->get_row($wpdb->prepare($sql, $id, 1), ARRAY_A);

    if (!is_array($row) || $row === []) {
        return nibwp_lmfw_err(
            'not_found',
            sprintf(
                /* translators: %d: licence row id */
                __('No licence with id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    return $row;
}

/**
 * The product a licence is for, named, or null.
 *
 * @return array<string, mixed>|null
 */
function nibwp_lmfw_product(mixed $product_id): ?array
{
    $id = is_numeric($product_id) ? (int) $product_id : 0;

    if ($id <= 0) {
        return null;
    }

    $name = null;

    if (function_exists('wc_get_product')) {
        try {
            $product = wc_get_product($id);

            if (is_object($product) && method_exists($product, 'get_name')) {
                $name = (string) $product->get_name();
            }
        } catch (\Throwable $error) {
            $name = null;
        }
    }

    return ['id' => $id, 'name' => $name];
}

/**
 * One licence row, shaped for a response — MASKED.
 *
 * This is the only function any listing uses to build a row, so there is one place
 * where a key could ever escape and it is this one. The ciphertext, the HMAC in
 * `hash`, and the plaintext all stay here.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_lmfw_license_row(array $row): array
{
    $plain = nibwp_lmfw_decrypt((string) ($row['license_key'] ?? ''));
    $unreadable = $plain instanceof WP_Error;

    $expires = isset($row['expires_at']) && is_string($row['expires_at']) && trim($row['expires_at']) !== ''
        ? (string) $row['expires_at']
        : null;

    $expired = null;

    if ($expires !== null) {
        $stamp = strtotime($expires . ' UTC');
        $expired = $stamp === false ? null : $stamp < time();
    }

    $activated = isset($row['times_activated']) && $row['times_activated'] !== null ? (int) $row['times_activated'] : 0;
    $allowed = isset($row['times_activated_max']) && $row['times_activated_max'] !== null
        ? (int) $row['times_activated_max']
        : null;

    $status = isset($row['status']) && $row['status'] !== null ? (int) $row['status'] : null;

    return [
        'id' => (int) ($row['id'] ?? 0),
        // Never the key itself. Four characters is enough for an owner to confirm
        // with a customer that they are talking about the same licence.
        'masked_key' => $unreadable ? null : nibwp_lmfw_mask($plain),
        'whole_key_returned' => false,
        'key_unreadable' => $unreadable,
        'key_unreadable_reason' => $unreadable ? $plain->get_error_message() : null,
        'status' => nibwp_lmfw_status_name($status),
        'status_number' => $status,
        'product' => nibwp_lmfw_product($row['product_id'] ?? null),
        'order_id' => isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : null,
        'customer_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'expires_at' => $expires,
        'expired' => $expired,
        'valid_for_days' => isset($row['valid_for']) && $row['valid_for'] !== null ? (int) $row['valid_for'] : null,
        'times_activated' => $activated,
        'activation_limit' => $allowed,
        'activations_left' => $allowed === null ? null : max(0, $allowed - $activated),
        'over_activation_limit' => $allowed !== null && $allowed > 0 && $activated > $allowed,
        'source' => nibwp_lmfw_source_name(isset($row['source']) ? (int) $row['source'] : null),
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

/**
 * Shape a set of raw rows, and count the ones whose key would not decrypt.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array{rows: array<int, array<string, mixed>>, unreadable: int}
 */
function nibwp_lmfw_license_rows(array $rows): array
{
    $shaped = [];
    $unreadable = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $one = nibwp_lmfw_license_row($row);

        if ($one['key_unreadable'] === true) {
            $unreadable++;
        }

        $shaped[] = $one;
    }

    return ['rows' => $shaped, 'unreadable' => $unreadable];
}

/**
 * Write a licence row, through a fixed column allow-list.
 *
 * The column names come from the keys of $columns below, which are literals in
 * this file; a column that is not in the list is refused rather than written. That
 * is what keeps an identifier from input out of the statement while still letting
 * the three mutation actions share one code path.
 *
 * @param array<string, mixed> $data
 */
function nibwp_lmfw_write_license(int $id, array $data): int|WP_Error
{
    global $wpdb;

    $columns = [
        'status'              => '%d',
        'expires_at'          => '%s',
        'times_activated_max' => '%d',
        'times_activated'     => '%d',
    ];

    $sets = [];
    $values = [];

    foreach ($data as $column => $value) {
        if (!is_string($column) || !isset($columns[$column])) {
            return nibwp_lmfw_err(
                'bad_column',
                sprintf(
                    /* translators: %s: the rejected column name */
                    __('"%s" is not a licence field this integration writes.', 'nibwp'),
                    is_scalar($column) ? (string) $column : gettype($column)
                ),
                422
            );
        }

        if ($value === null) {
            $sets[] = $column . ' = NULL';
            continue;
        }

        $sets[] = $column . ' = ' . $columns[$column];
        $values[] = $value;
    }

    if ($sets === []) {
        return nibwp_lmfw_err('nothing_to_write', __('Nothing was given to change.', 'nibwp'), 422);
    }

    // updated_at / updated_by, the way the plugin's own repository stamps them
    // (Abstracts/ResourceRepository.php:252-257), so the admin list keeps telling
    // the truth about who touched a licence last.
    $sets[] = 'updated_at = %s';
    $values[] = nibwp_lmfw_now();
    $sets[] = 'updated_by = %d';
    $values[] = get_current_user_id();

    $sql = 'UPDATE ' . nibwp_lmfw_table('licenses') . ' SET ' . implode(', ', $sets) . ' WHERE id = %d';
    $values[] = $id;

    $written = $wpdb->query($wpdb->prepare($sql, ...$values));

    return is_numeric($written) ? (int) $written : 0;
}

/**
 * Keep WooCommerce stock in step with a status change.
 *
 * The plugin counts ACTIVE licences for a product as that product's stock and
 * adjusts it on every status change of its own
 * (functions/lmfwc-license-functions.php:258-294; Integrations/WooCommerce/Stock.php:20-21).
 * Writing the column without doing the same would leave a shop whose stock figure
 * slowly stops matching its licences. Both filters are no-ops unless the shop
 * opted into stock management, so this is safe on a shop that did not.
 */
function nibwp_lmfw_sync_stock(?int $product_id, int $old_status, int $new_status): bool
{
    if ($product_id === null || $product_id <= 0 || $old_status === $new_status) {
        return false;
    }

    $active = nibwp_lmfw_statuses()['active'];

    try {
        if ($old_status === $active && $new_status !== $active) {
            apply_filters('lmfwc_stock_decrease', $product_id);

            return true;
        }

        if ($new_status === $active && $old_status !== $active) {
            apply_filters('lmfwc_stock_increase', $product_id);

            return true;
        }
    } catch (\Throwable $error) {
        return false;
    }

    return false;
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_lmfw_actions(): array
{
    return [
        // Licence keys — reads, all masked.
        'search_licenses',
        'get_license',
        'lookup_license_by_key',
        'reveal_license_key_in_full',
        // Licence keys — writes.
        'create_license',
        'update_license_status',
        'update_license_expiry',
        'update_license_activation_limit',
        'delete_license',
        // Generators.
        'list_generators',
        'get_generator',
        'create_generator',
        'update_generator',
        'generator_products',
        // Activations.
        'list_activations',
        'count_activations',
        'deactivate_activation',
        // Customers.
        'licenses_for_order',
        'licenses_for_customer',
        // Audit.
        'audit_expiring_licenses',
        'audit_over_activation_limit',
        'audit_never_activated',
        'audit_orders_without_licenses',
    ];
}

/**
 * Actions that cannot be undone from here.
 *
 * @return array<int, string>
 */
function nibwp_lmfw_irreversible(): array
{
    return [
        'delete_license',
        'reveal_license_key_in_full',
    ];
}

wp_register_ability('nibwp/license-manager-manage', [
    'label' => __('License Manager for WooCommerce – Licences', 'nibwp'),
    'description' => 'Manage the licence keys a WooCommerce shop sells: search and read licences with their status, product, order, customer, expiry and activation counts; '
        . 'create one; change a status, an expiry date or an activation limit; delete one; read and write key generators and see which products use them; '
        . 'list, count and deactivate the activations on a licence; find a customer\'s licences by order or email; '
        . 'and audit what is expiring soon, what is over its activation limit, which keys were never activated, and which orders never had their licences generated. '
        . 'Licence keys are credentials: every search and listing returns a MASKED key (last four characters) and never the whole thing. '
        . 'One action, reveal_license_key_in_full, returns a whole working key for one licence so an owner can resend it — it is confirm-gated and reports that it did. '
        . 'The plugin\'s encryption key and HMAC secret are never read or returned. '
        . 'Changing a status, expiry or activation limit changes what a paying customer can do, and deleting a licence is irreversible and may strand them.',
    'category' => 'ecommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_lmfw_actions(),
                'description' => 'Which operation to run. Start with search_licenses.',
            ],
            'license_id' => ['type' => 'integer', 'description' => 'The plugin\'s licence row id, as every listing returns it.'],
            'license_key' => ['type' => 'string', 'description' => 'For lookup_license_by_key and create_license only: a whole licence key. Never needed to read, change or delete a licence — use license_id for those.'],
            'generator_id' => ['type' => 'integer', 'description' => 'A generator\'s id.'],
            'activation_id' => ['type' => 'integer', 'description' => 'For deactivate_activation: the activation row id from list_activations.'],
            'order_id' => ['type' => 'integer', 'description' => 'A WooCommerce order id.'],
            'customer_id' => ['type' => 'integer', 'description' => 'A WordPress user id.'],
            'product_id' => ['type' => 'integer', 'description' => 'A WooCommerce product id.'],
            'email' => ['type' => 'string', 'description' => 'For licenses_for_customer: the customer\'s email address.'],
            'status' => ['type' => 'string', 'description' => 'A licence status: sold, delivered, active or inactive. There is no other status in this plugin.'],
            'expires_at' => ['type' => 'string', 'description' => 'For update_license_expiry and create_license: YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, in UTC. A bare date means the very start of that day.'],
            'clear_expiry' => ['type' => 'boolean', 'description' => 'For update_license_expiry: true makes the licence never expire.'],
            'activation_limit' => ['type' => 'integer', 'description' => 'How many activations the licence allows. 0 or omitted with clear_limit means unlimited.'],
            'clear_limit' => ['type' => 'boolean', 'description' => 'For update_license_activation_limit: true removes the limit, making activations unlimited.'],
            'name' => ['type' => 'string', 'description' => 'For create_generator / update_generator: the generator\'s name.'],
            'charset' => ['type' => 'string', 'description' => 'Generator character set, e.g. ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.'],
            'chunks' => ['type' => 'integer', 'description' => 'Generator: how many chunks a key has.'],
            'chunk_length' => ['type' => 'integer', 'description' => 'Generator: how many characters in a chunk.'],
            'separator' => ['type' => 'string', 'description' => 'Generator: what goes between chunks, e.g. a hyphen.'],
            'prefix' => ['type' => 'string', 'description' => 'Generator: text before the key.'],
            'suffix' => ['type' => 'string', 'description' => 'Generator: text after the key.'],
            'expires_in' => ['type' => 'integer', 'description' => 'Generator: days a licence it makes stays valid for.'],
            'days' => ['type' => 'integer', 'description' => 'For the audits: the window in days. Default 30, max 3650.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required by delete_license and reveal_license_key_in_full.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_license_manager_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'License Manager for WooCommerce. The shop\'s licence keys.',
                '',
                'A LICENCE KEY IS A CREDENTIAL. Whoever holds the characters can activate',
                'the product. search_licenses, get_license and every audit return a MASKED',
                'key — eight asterisks and the last four characters — plus the id, status,',
                'product, order, customer, expiry and activation counts. That is enough to',
                'identify a licence to a customer and to decide what to do about it.',
                '',
                'ONE action returns a whole working key: reveal_license_key_in_full. Use it',
                'only when the owner has asked you to resend or quote a specific key, pass',
                'confirm:true, and do not repeat the key back into anything that is kept.',
                'Never call it in a loop over a listing. If you are asked for "all the keys",',
                'the answer is a masked listing and a sentence explaining why.',
                '',
                'STATUS has four values and no others: sold, delivered, active, inactive.',
                'An inactive licence is one the customer\'s software will be refused on.',
                '',
                'WRITES THAT REACH A PAYING CUSTOMER:',
                '- update_license_status changes whether their software runs.',
                '- update_license_expiry changes when it stops. A bare date is the START of',
                '  that day in UTC, so 2026-12-01 ends the licence on the 30th of November.',
                '- update_license_activation_limit changes how many machines they may use.',
                '  Lowering it below what they have already activated does not deactivate',
                '  anything, it just refuses the next one.',
                '- delete_license is IRREVERSIBLE and strands whoever holds that key. The',
                '  key stops working and there is no undo here or in wp-admin. It needs',
                '  confirm:true. Say what will happen before you ask for it.',
                '',
                'ACTIVATIONS: list_activations and count_activations are per licence.',
                'count_activations also compares the licence\'s own times_activated counter',
                'against the activation rows actually present and reports a disagreement,',
                'which is usually what someone is really asking about when a customer says',
                '"it says I have used all my seats".',
                '',
                'AUDITS, for the questions an owner actually has: audit_expiring_licenses,',
                'audit_over_activation_limit, audit_never_activated, and',
                'audit_orders_without_licenses — paid orders for a licensed product that',
                'never had a key generated, which is a customer who paid and got nothing.',
                '',
                'SECRETS: the plugin\'s encryption key and HMAC secret are never read here',
                'and never returned, and no action will produce them however it is asked.',
                'If a key will not decrypt, the row reports key_unreadable with the reason —',
                'that means this site is holding a different encryption key than the one the',
                'licence was written with, which is a restore-from-backup problem, not a',
                'licence problem.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_license_manager_manage(array $input): array|WP_Error
{
    $guard = nibwp_lmfw_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_lmfw_actions(), true)) {
        return nibwp_lmfw_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown licence action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_lmfw_actions())
            )
        );
    }

    $db = nibwp_lmfw_db();
    if ($db instanceof WP_Error) {
        return $db;
    }

    // Two of these cannot be taken back: one deletes a customer's licence, the
    // other hands a live credential to whatever is on the other end of the call.
    if (in_array($action, nibwp_lmfw_irreversible(), true) && ($input['confirm'] ?? false) !== true) {
        return nibwp_lmfw_err(
            'unconfirmed',
            sprintf(
                /* translators: %s: action name */
                __('%s cannot be undone from here. Explain to whoever asked what it will do, then re-issue the call with confirm set to true.', 'nibwp'),
                $action
            ),
            422
        );
    }

    // The plugin's crypto, its models and WooCommerce's order store all raise, and
    // a Throwable out of an ability takes the whole REST request with it.
    try {
        return nibwp_lmfw_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_lmfw_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message */
                __('License Manager could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_lmfw_dispatch(string $action, array $input): array|WP_Error
{
    switch ($action) {
        case 'search_licenses':
            $result = nibwp_lmfw_search_licenses($input);
            break;
        case 'get_license':
            $result = nibwp_lmfw_get_license($input);
            break;
        case 'lookup_license_by_key':
            $result = nibwp_lmfw_lookup_license_by_key($input);
            break;
        case 'reveal_license_key_in_full':
            $result = nibwp_lmfw_reveal_license_key_in_full($input);
            break;
        case 'create_license':
            $result = nibwp_lmfw_create_license($input);
            break;
        case 'update_license_status':
            $result = nibwp_lmfw_update_license_status($input);
            break;
        case 'update_license_expiry':
            $result = nibwp_lmfw_update_license_expiry($input);
            break;
        case 'update_license_activation_limit':
            $result = nibwp_lmfw_update_license_activation_limit($input);
            break;
        case 'delete_license':
            $result = nibwp_lmfw_delete_license($input);
            break;
        case 'list_generators':
            $result = nibwp_lmfw_list_generators($input);
            break;
        case 'get_generator':
            $result = nibwp_lmfw_get_generator($input);
            break;
        case 'create_generator':
            $result = nibwp_lmfw_create_generator($input);
            break;
        case 'update_generator':
            $result = nibwp_lmfw_update_generator($input);
            break;
        case 'generator_products':
            $result = nibwp_lmfw_generator_products($input);
            break;
        case 'list_activations':
            $result = nibwp_lmfw_list_activations($input);
            break;
        case 'count_activations':
            $result = nibwp_lmfw_count_activations($input);
            break;
        case 'deactivate_activation':
            $result = nibwp_lmfw_deactivate_activation($input);
            break;
        case 'licenses_for_order':
            $result = nibwp_lmfw_licenses_for_order($input);
            break;
        case 'licenses_for_customer':
            $result = nibwp_lmfw_licenses_for_customer($input);
            break;
        case 'audit_expiring_licenses':
            $result = nibwp_lmfw_audit_expiring($input);
            break;
        case 'audit_over_activation_limit':
            $result = nibwp_lmfw_audit_over_limit($input);
            break;
        case 'audit_never_activated':
            $result = nibwp_lmfw_audit_never_activated($input);
            break;
        case 'audit_orders_without_licenses':
            $result = nibwp_lmfw_audit_orders_without_licenses($input);
            break;
        default:
            $result = nibwp_lmfw_err('unhandled_action', __('Unhandled action.', 'nibwp'));
            break;
    }

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------ licence keys: reads */

/**
 * Search licences. Masked, bounded, and never by key.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_search_licenses(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Searching licences', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $where = ['1=1'];
    $values = [];

    if (isset($input['status'])) {
        $status = nibwp_lmfw_status_number($input['status']);
        if ($status instanceof WP_Error) {
            return $status;
        }
        $where[] = 'status = %d';
        $values[] = $status;
    }

    if (isset($input['product_id'])) {
        $product_id = nibwp_lmfw_id($input['product_id'], 'product_id');
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }
        $where[] = 'product_id = %d';
        $values[] = $product_id;
    }

    if (isset($input['order_id'])) {
        $order_id = nibwp_lmfw_id($input['order_id'], 'order_id');
        if ($order_id instanceof WP_Error) {
            return $order_id;
        }
        // Naming one order makes this a question about that order.
        if ($denied = nibwp_lmfw_require_order($order_id, __('Searching licences', 'nibwp'))) {
            return $denied;
        }
        $where[] = 'order_id = %d';
        $values[] = $order_id;
    }

    if (isset($input['customer_id'])) {
        $customer_id = nibwp_lmfw_id($input['customer_id'], 'customer_id');
        if ($customer_id instanceof WP_Error) {
            return $customer_id;
        }
        if ($denied = nibwp_lmfw_require_user($customer_id, __('Searching licences', 'nibwp'))) {
            return $denied;
        }
        $where[] = 'user_id = %d';
        $values[] = $customer_id;
    }

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $offset = nibwp_lmfw_offset($input['offset'] ?? null);

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses')
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    $values[] = $limit;
    $values[] = $offset;

    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A);
    $shaped = nibwp_lmfw_license_rows($rows);

    return [
        'returned' => count($shaped['rows']),
        'limit' => $limit,
        'offset' => $offset,
        'licenses' => $shaped['rows'],
        'keys_unreadable' => $shaped['unreadable'],
        'whole_keys_returned' => false,
        'note' => __('Keys are masked: eight asterisks and the last four characters. That is deliberate — a listing is copied wherever the answer goes, and a licence key is a working credential. reveal_license_key_in_full returns one whole key, for one licence, on request.', 'nibwp'),
    ];
}

/**
 * One licence, masked, with its order and customer named.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_get_license(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Reading a licence', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_lmfw_find_license($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Reading a licence', 'nibwp'))) {
        return $denied;
    }

    $shaped = nibwp_lmfw_license_row($row);
    $customer_id = $shaped['customer_id'] ?? null;
    $customer = null;

    // The email is on the single read rather than in listings, and only once the
    // account-level question has been asked about that customer.
    if (is_int($customer_id) && $customer_id > 0 && nibwp_lmfw_require_user($customer_id, __('Reading a licence', 'nibwp')) === null) {
        $user = function_exists('get_userdata') ? get_userdata($customer_id) : false;

        if (is_object($user)) {
            $customer = [
                'id' => $customer_id,
                'email' => isset($user->user_email) ? (string) $user->user_email : null,
                'login' => isset($user->user_login) ? (string) $user->user_login : null,
            ];
        }
    }

    return [
        'license' => $shaped,
        'customer' => $customer,
        'order_storage' => nibwp_lmfw_order_storage(),
        'whole_key_returned' => false,
        'note' => __('The key is masked. reveal_license_key_in_full on this id returns the whole one, which is a live credential.', 'nibwp'),
    ];
}

/**
 * Find a licence from a key somebody already has.
 *
 * The key never travels back: the caller sent it, so echoing it would only copy it
 * into one more place. The plugin finds rows by HMAC of the plaintext
 * (functions/lmfwc-license-functions.php:119-123), which is why this works at all
 * without decrypting every row.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_lookup_license_by_key(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Looking a licence up by its key', 'nibwp'))) {
        return $denied;
    }

    $key = isset($input['license_key']) ? trim((string) $input['license_key']) : '';

    if ($key === '') {
        return nibwp_lmfw_err('missing_key', __('Give the licence key as license_key.', 'nibwp'), 422);
    }

    $hash = nibwp_lmfw_hash($key);
    if ($hash instanceof WP_Error) {
        return $hash;
    }

    global $wpdb;

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses') . ' WHERE hash = %s LIMIT %d';
    $row = $wpdb->get_row($wpdb->prepare($sql, $hash, 1), ARRAY_A);

    if (!is_array($row) || $row === []) {
        return nibwp_lmfw_err(
            'not_found',
            __('No licence on this site matches that key. Check for a copied space or a swapped character before concluding it is not genuine.', 'nibwp'),
            404
        );
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Looking a licence up by its key', 'nibwp'))) {
        return $denied;
    }

    return [
        'license' => nibwp_lmfw_license_row($row),
        'whole_key_returned' => false,
        'note' => __('Matched on the plugin\'s hash of the key. The key is not repeated back here — you already have it, and repeating it would copy it into this response too.', 'nibwp'),
    ];
}

/**
 * Return one whole, working licence key.
 *
 * The one action in this file that does. An owner legitimately needs it: a customer
 * lost the delivery email and the key has to be resent. Everything about it is
 * deliberate — the long name so it is never reached by accident, the baseline
 * capability, the order-level capability on the order it was sold on, the explicit
 * confirm, and a response that says plainly what just happened.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_reveal_license_key_in_full(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Revealing a whole licence key', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_lmfw_find_license($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Revealing a whole licence key', 'nibwp'))) {
        return $denied;
    }

    $plain = nibwp_lmfw_decrypt((string) ($row['license_key'] ?? ''));
    if ($plain instanceof WP_Error) {
        return $plain;
    }

    return [
        'license_id' => $id,
        'license_key' => $plain,
        'whole_key_returned' => true,
        'masked_key' => nibwp_lmfw_mask($plain),
        'status' => nibwp_lmfw_status_name(isset($row['status']) ? (int) $row['status'] : null),
        'order_id' => $order_id > 0 ? $order_id : null,
        'customer_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'warning' => __('This is a live credential. Anyone holding these characters can activate the product on this licence. Give it to the customer it belongs to and nowhere else — do not repeat it into a summary, a note, a ticket title or a log, and do not call this action again to re-read it.', 'nibwp'),
    ];
}

/* ----------------------------------------------------- licence keys: writes */

/**
 * Add a licence the shop already has the key for.
 *
 * Goes through the plugin's own lmfwc_add_license(), which encrypts, hashes and
 * refuses a duplicate (functions/lmfwc-license-functions.php:26-106). Worth
 * knowing: that function records the source as IMPORT whatever the caller intended
 * (:88), and defaults the status to inactive (:28).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_create_license(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Creating a licence', 'nibwp'))) {
        return $denied;
    }

    if (!function_exists('lmfwc_add_license')) {
        return nibwp_lmfw_err(
            'no_api',
            __('This version of the plugin does not expose lmfwc_add_license(), so a licence cannot be created from here.', 'nibwp'),
            500
        );
    }

    $key = isset($input['license_key']) ? trim((string) $input['license_key']) : '';

    if ($key === '') {
        return nibwp_lmfw_err(
            'missing_key',
            __('Give the key to store as license_key. This action does not invent one — use a generator in wp-admin for that, so the key the customer gets is the key the shop expects.', 'nibwp'),
            422
        );
    }

    $data = [];

    if (isset($input['status'])) {
        $status = nibwp_lmfw_status_number($input['status']);
        if ($status instanceof WP_Error) {
            return $status;
        }
        $data['status'] = $status;
    }

    if (isset($input['product_id'])) {
        $product_id = nibwp_lmfw_id($input['product_id'], 'product_id');
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }
        $data['product_id'] = $product_id;
    }

    if (isset($input['order_id'])) {
        $order_id = nibwp_lmfw_id($input['order_id'], 'order_id');
        if ($order_id instanceof WP_Error) {
            return $order_id;
        }
        if ($denied = nibwp_lmfw_require_order($order_id, __('Creating a licence', 'nibwp'))) {
            return $denied;
        }
        $data['order_id'] = $order_id;
    }

    if (isset($input['customer_id'])) {
        $customer_id = nibwp_lmfw_id($input['customer_id'], 'customer_id');
        if ($customer_id instanceof WP_Error) {
            return $customer_id;
        }
        if ($denied = nibwp_lmfw_require_user($customer_id, __('Creating a licence', 'nibwp'))) {
            return $denied;
        }
        $data['user_id'] = $customer_id;
    }

    if (isset($input['expires_at'])) {
        $expires = nibwp_lmfw_datetime($input['expires_at']);
        if ($expires instanceof WP_Error) {
            return $expires;
        }
        $data['expires_at'] = $expires;
    }

    if (isset($input['activation_limit'])) {
        $data['times_activated_max'] = max(0, (int) $input['activation_limit']);
    }

    try {
        $license = lmfwc_add_license($key, $data);
    } catch (\Throwable $error) {
        // The plugin's own duplicate-key exception interpolates the whole key into
        // its message (functions/lmfwc-license-functions.php:70), so passing that
        // message through verbatim would leak a working credential out of an error
        // path — the one place nobody thinks to look. Mask it on the way out.
        return nibwp_lmfw_err(
            'create_failed',
            sprintf(
                /* translators: %s: error message from the plugin */
                __('The plugin refused the new licence: %s', 'nibwp'),
                str_replace($key, nibwp_lmfw_mask($key), $error->getMessage())
            ),
            409
        );
    }

    if (!is_object($license) || !method_exists($license, 'getId')) {
        return nibwp_lmfw_err('create_failed', __('The plugin did not create the licence and gave no reason.', 'nibwp'), 500);
    }

    $row = nibwp_lmfw_find_license((int) $license->getId());

    return [
        'created' => true,
        'license' => $row instanceof WP_Error ? ['id' => (int) $license->getId()] : nibwp_lmfw_license_row($row),
        'whole_key_returned' => false,
        'note' => __('Stored encrypted. The plugin records every licence added this way with source "import" regardless of where it came from, and leaves the status inactive unless one was given. The key is not echoed back.', 'nibwp'),
    ];
}

/**
 * Change a licence's status.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_update_license_status(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Changing a licence status', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $status = nibwp_lmfw_status_number($input['status'] ?? null);
    if ($status instanceof WP_Error) {
        return $status;
    }

    $row = nibwp_lmfw_find_license($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Changing a licence status', 'nibwp'))) {
        return $denied;
    }

    $old = isset($row['status']) && $row['status'] !== null ? (int) $row['status'] : 0;
    $old_name = nibwp_lmfw_status_name($old);
    $new_name = nibwp_lmfw_status_name($status);

    if ($old === $status) {
        return [
            'changed' => false,
            'license_id' => $id,
            'status' => $new_name,
            'note' => __('It was already that status, so nothing was written.', 'nibwp'),
        ];
    }

    $written = nibwp_lmfw_write_license($id, ['status' => $status]);
    if ($written instanceof WP_Error) {
        return $written;
    }

    $product_id = isset($row['product_id']) && $row['product_id'] !== null ? (int) $row['product_id'] : null;
    $stock = nibwp_lmfw_sync_stock($product_id, $old, $status);

    $active = nibwp_lmfw_statuses()['active'];

    return [
        'changed' => $written > 0,
        'license_id' => $id,
        'status_before' => $old_name,
        'status_after' => $new_name,
        'product_stock_resynced' => $stock,
        'customer_impact' => $status === $active
            ? __('This licence may now be activated by the customer\'s software.', 'nibwp')
            : sprintf(
                /* translators: %s: the new status name */
                __('This licence is now "%s". The customer\'s software will be refused the next time it checks in, and existing activations stop being honoured — somebody who paid may be locked out within the hour.', 'nibwp'),
                (string) $new_name
            ),
        'note' => __('WooCommerce stock for the product is kept in step the way the plugin does it itself, counting active licences as stock. That only moves if the shop opted into licence stock management.', 'nibwp'),
    ];
}

/**
 * Change or clear a licence's expiry date.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_update_license_expiry(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Changing a licence expiry date', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $clear = ($input['clear_expiry'] ?? false) === true;

    if (!$clear && !isset($input['expires_at'])) {
        return nibwp_lmfw_err(
            'missing_date',
            __('Give expires_at, or clear_expiry set to true to make the licence never expire.', 'nibwp'),
            422
        );
    }

    $expires = null;

    if (!$clear) {
        $expires = nibwp_lmfw_datetime($input['expires_at'] ?? null);
        if ($expires instanceof WP_Error) {
            return $expires;
        }
    }

    $row = nibwp_lmfw_find_license($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Changing a licence expiry date', 'nibwp'))) {
        return $denied;
    }

    $before = isset($row['expires_at']) && is_string($row['expires_at']) && trim($row['expires_at']) !== ''
        ? (string) $row['expires_at']
        : null;

    $written = nibwp_lmfw_write_license($id, ['expires_at' => $expires]);
    if ($written instanceof WP_Error) {
        return $written;
    }

    $shortened = $before !== null && $expires !== null && strtotime($expires . ' UTC') < strtotime($before . ' UTC');

    return [
        'changed' => $written > 0,
        'license_id' => $id,
        'expires_before' => $before,
        'expires_after' => $expires,
        'never_expires' => $expires === null,
        'customer_impact' => $expires === null
            ? __('This licence no longer expires. The customer keeps it indefinitely, including past whatever they paid for.', 'nibwp')
            : sprintf(
                $shortened
                    /* translators: %s: the new expiry datetime */
                    ? __('The customer\'s licence now stops working at %s UTC, which is EARLIER than before — they may lose access they have already paid for.', 'nibwp')
                    /* translators: %s: the new expiry datetime */
                    : __('The customer\'s licence now runs until %s UTC.', 'nibwp'),
                $expires
            ),
        'note' => __('Read as UTC, and a bare date means the very start of that day: 2026-12-01 ends the licence at midnight going into the 1st, not at the end of it.', 'nibwp'),
    ];
}

/**
 * Change or clear how many activations a licence allows.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_update_license_activation_limit(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Changing a licence activation limit', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $clear = ($input['clear_limit'] ?? false) === true;

    if (!$clear && !isset($input['activation_limit'])) {
        return nibwp_lmfw_err(
            'missing_limit',
            __('Give activation_limit, or clear_limit set to true for unlimited activations.', 'nibwp'),
            422
        );
    }

    $limit = null;

    if (!$clear) {
        if (!is_numeric($input['activation_limit'] ?? null) || (int) $input['activation_limit'] < 0) {
            return nibwp_lmfw_err('bad_limit', __('activation_limit must be 0 or more. 0 and clear_limit both mean unlimited.', 'nibwp'), 422);
        }

        $limit = (int) $input['activation_limit'];

        if ($limit === 0) {
            $limit = null;
        }
    }

    $row = nibwp_lmfw_find_license($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Changing a licence activation limit', 'nibwp'))) {
        return $denied;
    }

    $before = isset($row['times_activated_max']) && $row['times_activated_max'] !== null
        ? (int) $row['times_activated_max']
        : null;
    $used = isset($row['times_activated']) && $row['times_activated'] !== null ? (int) $row['times_activated'] : 0;

    $written = nibwp_lmfw_write_license($id, ['times_activated_max' => $limit]);
    if ($written instanceof WP_Error) {
        return $written;
    }

    $impact = $limit === null
        ? __('The customer may now activate this licence on as many machines as they like.', 'nibwp')
        : sprintf(
            /* translators: 1: new limit, 2: activations already used */
            __('The customer may activate this licence %1$d time(s); they have used %2$d.', 'nibwp'),
            $limit,
            $used
        );

    return [
        'changed' => $written > 0,
        'license_id' => $id,
        'limit_before' => $before,
        'limit_after' => $limit,
        'times_activated' => $used,
        'now_over_limit' => $limit !== null && $used > $limit,
        'customer_impact' => $limit !== null && $used > $limit
            ? $impact . ' ' . __('That is already over the new limit. Nothing was deactivated — their existing activations keep working and the next one is refused.', 'nibwp')
            : $impact,
        'note' => __('Lowering a limit never deactivates anything by itself. deactivate_activation does that, one activation at a time.', 'nibwp'),
    ];
}

/**
 * Delete a licence. There is no undo.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_delete_license(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Deleting a licence', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_lmfw_find_license($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $order_id = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Deleting a licence', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $before = nibwp_lmfw_license_row($row);
    $old_status = isset($row['status']) && $row['status'] !== null ? (int) $row['status'] : 0;
    $product_id = isset($row['product_id']) && $row['product_id'] !== null ? (int) $row['product_id'] : null;

    // The activation rows go too. The plugin does not cascade — it has no foreign
    // key — so leaving them behind would leave activations pointing at a licence
    // that is not there, which reads as corruption to anyone looking later.
    $activations_sql = 'DELETE FROM ' . nibwp_lmfw_table('activations') . ' WHERE license_id = %d';
    $activations = $wpdb->query($wpdb->prepare($activations_sql, $id));

    $sql = 'DELETE FROM ' . nibwp_lmfw_table('licenses') . ' WHERE id = %d';
    $deleted = $wpdb->query($wpdb->prepare($sql, $id));

    // Same stock bookkeeping the plugin does on its own delete path
    // (functions/lmfwc-license-functions.php:317-322).
    $stock = nibwp_lmfw_sync_stock($product_id, $old_status, 0);

    return [
        'deleted' => is_numeric($deleted) && (int) $deleted > 0,
        'license_id' => $id,
        'was' => $before,
        'activations_deleted' => is_numeric($activations) ? (int) $activations : 0,
        'product_stock_resynced' => $stock,
        'customer_impact' => __('That key no longer exists on this site. Whoever holds it is now stranded: their software will be refused and there is nothing left to re-send or restore — not here, and not in wp-admin. If it was sold, the only fix is to issue a new licence and tell the customer.', 'nibwp'),
        'reversible' => false,
    ];
}

/* ---------------------------------------------------------------- generators */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_list_generators(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Listing licence generators', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $offset = nibwp_lmfw_offset($input['offset'] ?? null);

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('generators') . ' ORDER BY id ASC LIMIT %d OFFSET %d';
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $limit, $offset), ARRAY_A);

    $generators = [];

    foreach ($rows as $row) {
        if (is_array($row)) {
            $generators[] = nibwp_lmfw_generator_row($row);
        }
    }

    return [
        'returned' => count($generators),
        'limit' => $limit,
        'offset' => $offset,
        'generators' => $generators,
        'note' => __('A generator is a recipe for new keys, not a key. Nothing secret here — but a generator with few chunks and a short charset makes keys somebody could guess.', 'nibwp'),
    ];
}

/**
 * One generator row, shaped.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_lmfw_generator_row(array $row): array
{
    $charset = isset($row['charset']) ? (string) $row['charset'] : '';
    $chunks = isset($row['chunks']) ? (int) $row['chunks'] : 0;
    $chunk_length = isset($row['chunk_length']) ? (int) $row['chunk_length'] : 0;
    $unique = $charset === '' ? 0 : count(array_unique(str_split($charset)));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => isset($row['name']) ? (string) $row['name'] : null,
        'charset' => $charset,
        'charset_unique_characters' => $unique,
        'chunks' => $chunks,
        'chunk_length' => $chunk_length,
        'separator' => isset($row['separator']) ? (string) $row['separator'] : null,
        'prefix' => isset($row['prefix']) ? (string) $row['prefix'] : null,
        'suffix' => isset($row['suffix']) ? (string) $row['suffix'] : null,
        'expires_in_days' => isset($row['expires_in']) && $row['expires_in'] !== null ? (int) $row['expires_in'] : null,
        'activation_limit' => isset($row['times_activated_max']) && $row['times_activated_max'] !== null
            ? (int) $row['times_activated_max']
            : null,
        // The plugin refuses to generate more keys than the recipe has
        // combinations (Generator.php:67-73), so this is the ceiling on how many
        // licences this generator can ever produce.
        'possible_keys' => $unique > 0 && $chunks > 0 && $chunk_length > 0
            ? (float) pow($unique, $chunks * $chunk_length)
            : 0.0,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_get_generator(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Reading a licence generator', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['generator_id'] ?? null, 'generator_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_lmfw_find_generator($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    return ['generator' => nibwp_lmfw_generator_row($row)];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_find_generator(int $id): array|WP_Error
{
    global $wpdb;

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('generators') . ' WHERE id = %d LIMIT %d';
    $row = $wpdb->get_row($wpdb->prepare($sql, $id, 1), ARRAY_A);

    if (!is_array($row) || $row === []) {
        return nibwp_lmfw_err(
            'not_found',
            sprintf(
                /* translators: %d: generator id */
                __('No generator with id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    return $row;
}

/**
 * Validate the generator fields that arrive on create and update.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_generator_input(array $input, bool $require_all): array|WP_Error
{
    $data = [];

    if (isset($input['name'])) {
        $name = function_exists('sanitize_text_field') ? (string) sanitize_text_field((string) $input['name']) : trim((string) $input['name']);

        if ($name === '') {
            return nibwp_lmfw_err('bad_name', __('A generator needs a name.', 'nibwp'), 422);
        }

        $data['name'] = $name;
    } elseif ($require_all) {
        return nibwp_lmfw_err('bad_name', __('Give the generator a name.', 'nibwp'), 422);
    }

    if (isset($input['charset'])) {
        $charset = trim((string) $input['charset']);

        // Two unique characters is the minimum that makes a random key random at
        // all, and the plugin picks from this string one character at a time
        // (Generator.php:41), so an empty one would be a division by nothing.
        if (count(array_unique(str_split($charset))) < 2) {
            return nibwp_lmfw_err('bad_charset', __('The character set needs at least two different characters, or every key would be the same.', 'nibwp'), 422);
        }

        $data['charset'] = $charset;
    } elseif ($require_all) {
        return nibwp_lmfw_err('bad_charset', __('Give the generator a charset, e.g. ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.', 'nibwp'), 422);
    }

    foreach (['chunks' => 1, 'chunk_length' => 1] as $field => $minimum) {
        if (isset($input[$field])) {
            if (!is_numeric($input[$field]) || (int) $input[$field] < $minimum || (int) $input[$field] > 100) {
                return nibwp_lmfw_err(
                    'bad_' . $field,
                    sprintf(
                        /* translators: 1: field name, 2: minimum value */
                        __('%1$s must be a whole number between %2$d and 100.', 'nibwp'),
                        $field,
                        $minimum
                    ),
                    422
                );
            }

            $data[$field] = (int) $input[$field];
        } elseif ($require_all) {
            return nibwp_lmfw_err(
                'bad_' . $field,
                sprintf(
                    /* translators: %s: field name */
                    __('Give %s.', 'nibwp'),
                    $field
                ),
                422
            );
        }
    }

    foreach (['separator', 'prefix', 'suffix'] as $field) {
        if (isset($input[$field])) {
            $data[$field] = function_exists('sanitize_text_field')
                ? (string) sanitize_text_field((string) $input[$field])
                : trim((string) $input[$field]);
        }
    }

    foreach (['expires_in' => 'expires_in', 'activation_limit' => 'times_activated_max'] as $field => $column) {
        if (isset($input[$field])) {
            if (!is_numeric($input[$field]) || (int) $input[$field] < 0) {
                return nibwp_lmfw_err(
                    'bad_' . $field,
                    sprintf(
                        /* translators: %s: field name */
                        __('%s must be 0 or more.', 'nibwp'),
                        $field
                    ),
                    422
                );
            }

            $value = (int) $input[$field];
            $data[$column] = $value === 0 ? null : $value;
        }
    }

    if ($data === []) {
        return nibwp_lmfw_err('nothing_to_write', __('Nothing was given to change.', 'nibwp'), 422);
    }

    return $data;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_create_generator(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Creating a licence generator', 'nibwp'))) {
        return $denied;
    }

    $data = nibwp_lmfw_generator_input($input, true);
    if ($data instanceof WP_Error) {
        return $data;
    }

    global $wpdb;

    $columns = nibwp_lmfw_generator_columns();
    $names = [];
    $placeholders = [];
    $values = [];

    foreach ($data as $column => $value) {
        if (!isset($columns[$column])) {
            continue;
        }

        $names[] = $column;
        $placeholders[] = $value === null ? 'NULL' : $columns[$column];

        if ($value !== null) {
            $values[] = $value;
        }
    }

    $names[] = 'created_at';
    $placeholders[] = '%s';
    $values[] = nibwp_lmfw_now();
    $names[] = 'created_by';
    $placeholders[] = '%d';
    $values[] = get_current_user_id();

    $sql = 'INSERT INTO ' . nibwp_lmfw_table('generators')
        . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $placeholders) . ')';

    $wpdb->query($wpdb->prepare($sql, ...$values));

    $id = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;
    $row = $id > 0 ? nibwp_lmfw_find_generator($id) : nibwp_lmfw_err('create_failed', __('The generator was not created.', 'nibwp'), 500);

    if ($row instanceof WP_Error) {
        return $row;
    }

    return [
        'created' => true,
        'generator' => nibwp_lmfw_generator_row($row),
        'note' => __('Nothing uses it yet. A generator only starts making keys once a product is pointed at it in the product\'s License Manager tab — generator_products shows which products are.', 'nibwp'),
    ];
}

/**
 * The generator columns this integration writes, and how each one binds.
 *
 * @return array<string, string>
 */
function nibwp_lmfw_generator_columns(): array
{
    return [
        'name'                => '%s',
        'charset'             => '%s',
        'chunks'              => '%d',
        'chunk_length'        => '%d',
        'separator'           => '%s',
        'prefix'              => '%s',
        'suffix'              => '%s',
        'expires_in'          => '%d',
        'times_activated_max' => '%d',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_update_generator(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Changing a licence generator', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['generator_id'] ?? null, 'generator_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $data = nibwp_lmfw_generator_input($input, false);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $row = nibwp_lmfw_find_generator($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    global $wpdb;

    $columns = nibwp_lmfw_generator_columns();
    $sets = [];
    $values = [];

    foreach ($data as $column => $value) {
        if (!isset($columns[$column])) {
            continue;
        }

        if ($value === null) {
            $sets[] = $column . ' = NULL';
            continue;
        }

        $sets[] = $column . ' = ' . $columns[$column];
        $values[] = $value;
    }

    if ($sets === []) {
        return nibwp_lmfw_err('nothing_to_write', __('Nothing was given to change.', 'nibwp'), 422);
    }

    $sets[] = 'updated_at = %s';
    $values[] = nibwp_lmfw_now();
    $sets[] = 'updated_by = %d';
    $values[] = get_current_user_id();

    $sql = 'UPDATE ' . nibwp_lmfw_table('generators') . ' SET ' . implode(', ', $sets) . ' WHERE id = %d';
    $values[] = $id;

    $written = $wpdb->query($wpdb->prepare($sql, ...$values));

    $after = nibwp_lmfw_find_generator($id);

    return [
        'changed' => is_numeric($written) && (int) $written > 0,
        'generator_before' => nibwp_lmfw_generator_row($row),
        'generator_after' => $after instanceof WP_Error ? null : nibwp_lmfw_generator_row($after),
        'note' => __('Licences already issued are untouched — a generator only shapes the next key it makes. Shortening the recipe lowers how many distinct keys remain possible, and the plugin refuses to generate past that ceiling.', 'nibwp'),
    ];
}

/**
 * Which products hand their key generation to this generator.
 *
 * The link is product meta, not a column: lmfwc_licensed_product_assigned_generator
 * holds the generator id and lmfwc_licensed_product_use_generator says whether the
 * product actually uses it (Integrations/WooCommerce/ProductData.php:86-89). A
 * product can carry a stale assignment with the switch off, which is why both are
 * reported rather than one.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_generator_products(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Listing the products a generator serves', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['generator_id'] ?? null, 'generator_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $generator = nibwp_lmfw_find_generator($id);
    if ($generator instanceof WP_Error) {
        return $generator;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);

    // Products are posts under HPOS as much as before it — HPOS moved orders, not
    // products — so postmeta is the right place to ask, either way.
    $sql = 'SELECT post_id FROM ' . $wpdb->postmeta
        . ' WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT %d';
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare($sql, 'lmfwc_licensed_product_assigned_generator', (string) $id, $limit),
        ARRAY_A
    );

    $products = [];

    foreach ($rows as $row) {
        $product_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;

        if ($product_id <= 0) {
            continue;
        }

        $uses = get_post_meta($product_id, 'lmfwc_licensed_product_use_generator', true);
        $licensed = get_post_meta($product_id, 'lmfwc_licensed_product', true);

        $products[] = [
            'id' => $product_id,
            'name' => nibwp_lmfw_product($product_id)['name'] ?? null,
            'sells_licenses' => (string) $licensed === '1',
            'uses_this_generator' => (string) $uses === '1',
        ];
    }

    $using = 0;

    foreach ($products as $product) {
        if ($product['uses_this_generator'] === true && $product['sells_licenses'] === true) {
            $using++;
        }
    }

    return [
        'generator' => nibwp_lmfw_generator_row($generator),
        'returned' => count($products),
        'limit' => $limit,
        'actively_using' => $using,
        'products' => $products,
        'note' => __('A product listed with uses_this_generator false still points at this generator but is set to sell from stock instead, so changing the generator will not affect it until that switch changes.', 'nibwp'),
    ];
}

/* --------------------------------------------------------------- activations */

/**
 * The activations on one licence.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_list_activations(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Listing licence activations', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $license = nibwp_lmfw_find_license($id);
    if ($license instanceof WP_Error) {
        return $license;
    }

    $order_id = isset($license['order_id']) && $license['order_id'] !== null ? (int) $license['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Listing licence activations', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $offset = nibwp_lmfw_offset($input['offset'] ?? null);

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('activations')
        . ' WHERE license_id = %d ORDER BY id DESC LIMIT %d OFFSET %d';
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $id, $limit, $offset), ARRAY_A);

    $activations = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $token = isset($row['token']) ? (string) $row['token'] : '';

        $activations[] = [
            'id' => (int) ($row['id'] ?? 0),
            'license_id' => $id,
            'label' => isset($row['label']) ? (string) $row['label'] : null,
            // The token is what the plugin's own API accepts to deactivate this
            // activation, so it is masked like a key. deactivate_activation here
            // takes the id above and needs no token.
            'token_masked' => $token === '' ? null : nibwp_lmfw_mask($token),
            'source' => nibwp_lmfw_activation_source(isset($row['source']) ? (int) $row['source'] : null),
            'ip_address' => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            'user_agent' => isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            'activated_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'deactivated_at' => isset($row['deactivated_at']) && $row['deactivated_at'] !== null
                ? (string) $row['deactivated_at']
                : null,
            'active' => !isset($row['deactivated_at']) || $row['deactivated_at'] === null,
        ];
    }

    return [
        'license_id' => $id,
        'returned' => count($activations),
        'limit' => $limit,
        'offset' => $offset,
        'activations' => $activations,
        'note' => __('An activation row names the machine that activated: its IP address and user agent. That is personal data about the customer\'s installation, so quote it only where it answers the question. Activation tokens are masked.', 'nibwp'),
    ];
}

/** Where an activation came from (Enums/ActivationProcessor.php:17-31). */
function nibwp_lmfw_activation_source(?int $source): ?string
{
    $sources = [1 => 'web', 2 => 'api', 3 => 'migration'];

    return $source === null ? null : ($sources[$source] ?? 'other');
}

/**
 * Count the activations on a licence, and check the counter against reality.
 *
 * The licences table carries its own times_activated, which the plugin increments
 * and decrements separately from writing the activation rows
 * (functions/lmfwc-license-functions.php:455-466). Two places holding one number
 * is two places that can disagree, and when they do, the customer's software is
 * refused for a seat nobody is using. That disagreement is the answer here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_count_activations(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Counting licence activations', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_lmfw_id($input['license_id'] ?? null, 'license_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $license = nibwp_lmfw_find_license($id);
    if ($license instanceof WP_Error) {
        return $license;
    }

    $order_id = isset($license['order_id']) && $license['order_id'] !== null ? (int) $license['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Counting licence activations', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $table = nibwp_lmfw_table('activations');

    $total_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE license_id = %d';
    $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, $id));

    $live_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE license_id = %d AND deactivated_at IS NULL';
    $live = (int) $wpdb->get_var($wpdb->prepare($live_sql, $id));

    $counter = isset($license['times_activated']) && $license['times_activated'] !== null
        ? (int) $license['times_activated']
        : 0;
    $allowed = isset($license['times_activated_max']) && $license['times_activated_max'] !== null
        ? (int) $license['times_activated_max']
        : null;

    return [
        'license_id' => $id,
        'activation_rows_total' => $total,
        'activation_rows_live' => $live,
        'activation_rows_deactivated' => max(0, $total - $live),
        'license_counter' => $counter,
        'activation_limit' => $allowed,
        'activations_left' => $allowed === null ? null : max(0, $allowed - $counter),
        'counter_agrees' => $counter === $live,
        'verdict' => $counter === $live
            ? __('The licence\'s own counter matches the activations on record.', 'nibwp')
            : sprintf(
                /* translators: 1: counter on the licence, 2: live activation rows */
                __('The licence says %1$d activation(s) are used but only %2$d activation row(s) are live. The counter is what the plugin checks against the limit, so the customer is being charged seats that nothing is holding. Fix it by deactivating the stale rows, or by setting the limit to match what they actually use.', 'nibwp'),
                $counter,
                $live
            ),
    ];
}

/**
 * Release one activation.
 *
 * Mirrors what the plugin's own deactivate path does by token: stamp
 * deactivated_at and take one off the licence's counter
 * (functions/lmfwc-license-functions.php:543-558). It takes the activation's id
 * rather than its token so that nothing has to hand a token around to do it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_deactivate_activation(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Deactivating a licence activation', 'nibwp'))) {
        return $denied;
    }

    $activation_id = nibwp_lmfw_id($input['activation_id'] ?? null, 'activation_id');
    if ($activation_id instanceof WP_Error) {
        return $activation_id;
    }

    global $wpdb;

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('activations') . ' WHERE id = %d LIMIT %d';
    $row = $wpdb->get_row($wpdb->prepare($sql, $activation_id, 1), ARRAY_A);

    if (!is_array($row) || $row === []) {
        return nibwp_lmfw_err(
            'not_found',
            sprintf(
                /* translators: %d: activation id */
                __('No activation with id %d.', 'nibwp'),
                $activation_id
            ),
            404
        );
    }

    if (isset($row['deactivated_at']) && $row['deactivated_at'] !== null && trim((string) $row['deactivated_at']) !== '') {
        return nibwp_lmfw_err(
            'already_deactivated',
            sprintf(
                /* translators: 1: activation id, 2: when it was deactivated */
                __('Activation %1$d was already deactivated at %2$s, so nothing was written and the licence counter was left alone.', 'nibwp'),
                $activation_id,
                (string) $row['deactivated_at']
            ),
            409
        );
    }

    $license_id = isset($row['license_id']) ? (int) $row['license_id'] : 0;
    $license = $license_id > 0 ? nibwp_lmfw_find_license($license_id) : nibwp_lmfw_err('not_found', __('That activation points at no licence.', 'nibwp'), 404);

    if ($license instanceof WP_Error) {
        return $license;
    }

    $order_id = isset($license['order_id']) && $license['order_id'] !== null ? (int) $license['order_id'] : 0;

    if ($denied = nibwp_lmfw_require_order($order_id, __('Deactivating a licence activation', 'nibwp'))) {
        return $denied;
    }

    $now = nibwp_lmfw_now();
    $stamp_sql = 'UPDATE ' . nibwp_lmfw_table('activations')
        . ' SET deactivated_at = %s, updated_at = %s WHERE id = %d AND deactivated_at IS NULL';
    $stamped = $wpdb->query($wpdb->prepare($stamp_sql, $now, $now, $activation_id));

    $counter = isset($license['times_activated']) && $license['times_activated'] !== null
        ? (int) $license['times_activated']
        : 0;
    $new_counter = max(0, $counter - 1);

    $written = nibwp_lmfw_write_license($license_id, ['times_activated' => $new_counter]);
    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'deactivated' => is_numeric($stamped) && (int) $stamped > 0,
        'activation_id' => $activation_id,
        'license_id' => $license_id,
        'counter_before' => $counter,
        'counter_after' => $new_counter,
        'customer_impact' => __('That installation gives up its seat. Whatever was running on it will be refused the next time it checks in, and the seat is free for another machine.', 'nibwp'),
    ];
}

/* ----------------------------------------------------------------- customers */

/**
 * The licences sold on one order.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_licenses_for_order(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Reading an order\'s licences', 'nibwp'))) {
        return $denied;
    }

    $order_id = nibwp_lmfw_id($input['order_id'] ?? null, 'order_id');
    if ($order_id instanceof WP_Error) {
        return $order_id;
    }

    if ($denied = nibwp_lmfw_require_order($order_id, __('Reading an order\'s licences', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses')
        . ' WHERE order_id = %d ORDER BY id ASC LIMIT %d';
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $order_id, $limit), ARRAY_A);
    $shaped = nibwp_lmfw_license_rows($rows);

    $delivered = null;
    $order_status = null;

    if (function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);

        if (is_object($order)) {
            if (method_exists($order, 'get_meta')) {
                // The plugin stamps this once it has generated and delivered the
                // keys for an order (Integrations/WooCommerce/Order.php:210).
                $delivered = (string) $order->get_meta('lmfwc_order_complete') === '1';
            }
            if (method_exists($order, 'get_status')) {
                $order_status = (string) $order->get_status();
            }
        }
    }

    return [
        'order_id' => $order_id,
        'order_status' => $order_status,
        'licenses_delivered_flag' => $delivered,
        'returned' => count($shaped['rows']),
        'limit' => $limit,
        'licenses' => $shaped['rows'],
        'keys_unreadable' => $shaped['unreadable'],
        'whole_keys_returned' => false,
        'order_storage' => nibwp_lmfw_order_storage(),
        'note' => count($shaped['rows']) === 0
            ? __('No licence rows reference this order. If it paid for a licensed product, that is a customer who paid and received nothing — audit_orders_without_licenses finds the rest of them.', 'nibwp')
            : __('Keys are masked. reveal_license_key_in_full takes one id and returns one whole key.', 'nibwp'),
    ];
}

/**
 * A customer's licences, by user id or email address.
 *
 * A licence row carries a user_id and nothing else about the buyer
 * (Setup.php:161), so a guest purchase leaves no customer on the licence at all —
 * only the order. Both roads are therefore walked: the account, and the orders
 * that billing address paid for.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_licenses_for_customer(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Reading a customer\'s licences', 'nibwp'))) {
        return $denied;
    }

    $customer_id = 0;
    $email = isset($input['email']) ? trim((string) $input['email']) : '';

    if (isset($input['customer_id'])) {
        $resolved = nibwp_lmfw_id($input['customer_id'], 'customer_id');
        if ($resolved instanceof WP_Error) {
            return $resolved;
        }
        $customer_id = $resolved;
    }

    if ($customer_id === 0 && $email !== '') {
        if (function_exists('is_email') && is_email($email) === false) {
            return nibwp_lmfw_err(
                'bad_email',
                sprintf(
                    /* translators: %s: the rejected value */
                    __('"%s" is not an email address.', 'nibwp'),
                    $email
                ),
                422
            );
        }

        $user = function_exists('get_user_by') ? get_user_by('email', $email) : false;

        if (is_object($user) && isset($user->ID)) {
            $customer_id = (int) $user->ID;
        }
    }

    if ($customer_id === 0 && $email === '') {
        return nibwp_lmfw_err('missing_customer', __('Give customer_id or email.', 'nibwp'), 422);
    }

    if ($customer_id > 0 && ($denied = nibwp_lmfw_require_user($customer_id, __('Reading a customer\'s licences', 'nibwp')))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $rows = [];

    if ($customer_id > 0) {
        $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses')
            . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d';
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $customer_id, $limit), ARRAY_A);
    }

    $seen = [];

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id'])) {
            $seen[(int) $row['id']] = true;
        }
    }

    // The guest road. wc_get_orders() answers from whichever order store is live,
    // which is the only reason this works unchanged under HPOS.
    $orders_read = 0;
    $orders_skipped = 0;

    if ($email !== '' && function_exists('wc_get_orders')) {
        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit' => $limit,
            'return' => 'ids',
        ]);

        foreach ((array) $orders as $order_id) {
            $order_id = (int) $order_id;

            if ($order_id <= 0) {
                continue;
            }

            // Per order, because an account that may run the shop is not
            // necessarily an account that may open this order.
            if (!current_user_can('edit_shop_order', $order_id)) {
                $orders_skipped++;
                continue;
            }

            $orders_read++;

            $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses')
                . ' WHERE order_id = %d ORDER BY id ASC LIMIT %d';
            $found = (array) $wpdb->get_results($wpdb->prepare($sql, $order_id, $limit), ARRAY_A);

            foreach ($found as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                if (!isset($seen[(int) $row['id']])) {
                    $seen[(int) $row['id']] = true;
                    $rows[] = $row;
                }
            }
        }
    }

    $shaped = nibwp_lmfw_license_rows(array_slice($rows, 0, $limit));

    return [
        'customer_id' => $customer_id > 0 ? $customer_id : null,
        'email' => $email !== '' ? $email : null,
        'has_account' => $customer_id > 0,
        'returned' => count($shaped['rows']),
        'limit' => $limit,
        'licenses' => $shaped['rows'],
        'keys_unreadable' => $shaped['unreadable'],
        'whole_keys_returned' => false,
        'guest_orders_read' => $orders_read,
        'guest_orders_skipped' => $orders_skipped,
        'order_storage' => nibwp_lmfw_order_storage(),
        'note' => $orders_skipped > 0
            ? __('Some of this customer\'s orders were skipped because this account may not open them, so the list may be short. Keys are masked.', 'nibwp')
            : __('Keys are masked. A licence bought as a guest carries no customer id at all, only the order, which is why the email road exists.', 'nibwp'),
    ];
}

/* -------------------------------------------------------------------- audits */

/** A day window from input, clamped. */
function nibwp_lmfw_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(3650, $days));
}

/**
 * Licences expiring inside a window.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_audit_expiring(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Auditing expiring licences', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $days = nibwp_lmfw_days($input['days'] ?? null);
    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $now = nibwp_lmfw_now();
    $until = gmdate('Y-m-d H:i:s', time() + ($days * (defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400)));

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses')
        . ' WHERE expires_at IS NOT NULL AND expires_at >= %s AND expires_at <= %s'
        . ' ORDER BY expires_at ASC LIMIT %d';
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $now, $until, $limit), ARRAY_A);
    $shaped = nibwp_lmfw_license_rows($rows);

    $expired_sql = 'SELECT COUNT(*) FROM ' . nibwp_lmfw_table('licenses')
        . ' WHERE expires_at IS NOT NULL AND expires_at < %s';
    $already = (int) $wpdb->get_var($wpdb->prepare($expired_sql, $now));

    return [
        'days' => $days,
        'window_ends' => $until,
        'returned' => count($shaped['rows']),
        'limit' => $limit,
        'licenses' => $shaped['rows'],
        'already_expired' => $already,
        'keys_unreadable' => $shaped['unreadable'],
        'whole_keys_returned' => false,
        'note' => __('Dates are UTC, soonest first. Licences with no expiry date never appear here because they never expire. already_expired counts the ones that are past it now, which is a renewal conversation rather than a warning.', 'nibwp'),
    ];
}

/**
 * Licences used more times than they allow.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_audit_over_limit(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Auditing licences over their activation limit', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $table = nibwp_lmfw_table('licenses');

    $sql = 'SELECT * FROM ' . $table
        . ' WHERE times_activated_max IS NOT NULL AND times_activated_max > %d'
        . ' AND times_activated IS NOT NULL AND times_activated > times_activated_max'
        . ' ORDER BY (times_activated - times_activated_max) DESC LIMIT %d';
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, 0, $limit), ARRAY_A);
    $shaped = nibwp_lmfw_license_rows($rows);

    $at_sql = 'SELECT COUNT(*) FROM ' . $table
        . ' WHERE times_activated_max IS NOT NULL AND times_activated_max > %d'
        . ' AND times_activated IS NOT NULL AND times_activated = times_activated_max';
    $at_limit = (int) $wpdb->get_var($wpdb->prepare($at_sql, 0));

    return [
        'returned' => count($shaped['rows']),
        'limit' => $limit,
        'licenses' => $shaped['rows'],
        'at_limit' => $at_limit,
        'keys_unreadable' => $shaped['unreadable'],
        'whole_keys_returned' => false,
        'note' => __('Over the limit, not at it — these are licences whose counter has passed what they allow, which usually means the limit was lowered after the fact rather than that anybody cheated. at_limit counts the ones with no room left, whose next activation will be refused.', 'nibwp'),
    ];
}

/**
 * Keys that were never activated.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_audit_never_activated(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Auditing licences that were never activated', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);
    $days = isset($input['days']) ? nibwp_lmfw_days($input['days']) : 0;
    $statuses = nibwp_lmfw_statuses();

    $where = ['(times_activated IS NULL OR times_activated = %d)'];
    $values = [0];

    // Sold and delivered, not inactive stock: a key nobody bought was never meant
    // to be activated, and reporting it as a problem buries the ones that were.
    $where[] = 'status IN (%d, %d, %d)';
    $values[] = $statuses['sold'];
    $values[] = $statuses['delivered'];
    $values[] = $statuses['active'];

    $older_than = null;

    if ($days > 0) {
        $older_than = gmdate('Y-m-d H:i:s', time() - ($days * (defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400)));
        $where[] = 'created_at IS NOT NULL AND created_at <= %s';
        $values[] = $older_than;
    }

    $sql = 'SELECT * FROM ' . nibwp_lmfw_table('licenses')
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at ASC LIMIT %d';
    $values[] = $limit;

    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A);
    $shaped = nibwp_lmfw_license_rows($rows);

    return [
        'returned' => count($shaped['rows']),
        'limit' => $limit,
        'created_before' => $older_than,
        'licenses' => $shaped['rows'],
        'keys_unreadable' => $shaped['unreadable'],
        'whole_keys_returned' => false,
        'note' => __('Sold, delivered or active licences whose activation counter is still zero — a customer who paid and never installed, or a delivery email that never arrived. Pass days to look only at ones old enough to be worth chasing. Unsold stock is left out on purpose.', 'nibwp'),
    ];
}

/**
 * Paid orders for a licensed product that never got a licence.
 *
 * This is the one audit here that is about money already taken. Orders are read
 * through wc_get_orders(), so it answers under either order store; the licence
 * side is one bounded query against the licences table.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_lmfw_audit_orders_without_licenses(array $input): array|WP_Error
{
    if ($denied = nibwp_lmfw_require(__('Auditing orders whose licences were never generated', 'nibwp'))) {
        return $denied;
    }

    // The list-level question first: this reads orders, plural, so it asks the
    // primitive WooCommerce gates its own Orders screen on, and then asks about
    // each order it actually opens.
    if (!current_user_can('edit_shop_orders')) {
        return nibwp_lmfw_forbidden(__('Auditing orders whose licences were never generated', 'nibwp'), 'edit_shop_orders');
    }

    if (!function_exists('wc_get_orders')) {
        return nibwp_lmfw_err(
            'no_api',
            __('WooCommerce does not expose wc_get_orders() here, so orders cannot be read.', 'nibwp'),
            500
        );
    }

    global $wpdb;

    $limit = nibwp_lmfw_limit($input['limit'] ?? null);

    $orders = wc_get_orders([
        'limit' => $limit,
        'status' => ['wc-processing', 'wc-completed'],
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
    ]);

    $checked = 0;
    $skipped = 0;
    $candidates = [];

    foreach ((array) $orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            continue;
        }

        $order_id = (int) $order->get_id();

        if (!current_user_can('edit_shop_order', $order_id)) {
            $skipped++;
            continue;
        }

        $checked++;

        $licensed = [];

        if (method_exists($order, 'get_items')) {
            foreach ((array) $order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product')) {
                    continue;
                }

                $product = $item->get_product();

                if (!is_object($product) || !method_exists($product, 'get_meta')) {
                    continue;
                }

                if ((string) $product->get_meta('lmfwc_licensed_product', true) === '1') {
                    $licensed[] = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
                }
            }
        }

        if ($licensed === []) {
            continue;
        }

        $candidates[$order_id] = [
            'order_id' => $order_id,
            'status' => method_exists($order, 'get_status') ? (string) $order->get_status() : null,
            'licensed_product_ids' => array_values(array_unique($licensed)),
            'delivered_flag' => method_exists($order, 'get_meta')
                ? (string) $order->get_meta('lmfwc_order_complete') === '1'
                : null,
            'licenses' => 0,
        ];
    }

    if ($candidates !== []) {
        $ids = array_map('intval', array_keys($candidates));
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $sql = 'SELECT order_id, COUNT(*) AS licenses FROM ' . nibwp_lmfw_table('licenses')
            . ' WHERE order_id IN (' . $placeholders . ') GROUP BY order_id LIMIT %d';
        $values = $ids;
        $values[] = count($ids);

        $counts = (array) $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A);

        foreach ($counts as $count) {
            $order_id = isset($count['order_id']) ? (int) $count['order_id'] : 0;

            if (isset($candidates[$order_id])) {
                $candidates[$order_id]['licenses'] = isset($count['licenses']) ? (int) $count['licenses'] : 0;
            }
        }
    }

    $missing = [];

    foreach ($candidates as $candidate) {
        if ($candidate['licenses'] === 0) {
            $missing[] = $candidate;
        }
    }

    return [
        'orders_checked' => $checked,
        'orders_skipped' => $skipped,
        'orders_with_licensed_products' => count($candidates),
        'orders_without_licenses' => count($missing),
        'orders' => $missing,
        'limit' => $limit,
        'order_storage' => nibwp_lmfw_order_storage(),
        'note' => count($missing) === 0
            ? __('Every processing or completed order for a licensed product in this window has at least one licence against it.', 'nibwp')
            : __('These orders paid for a licensed product and have no licence row. Each one is a customer who paid and has nothing to install. The plugin generates keys when an order reaches a delivery status, so the usual causes are a status that never got there, a product set to sell from stock that had none, or a generator that could not make a unique key. Generating the licences is done from the order in wp-admin, not from here.', 'nibwp'),
    ];
}
