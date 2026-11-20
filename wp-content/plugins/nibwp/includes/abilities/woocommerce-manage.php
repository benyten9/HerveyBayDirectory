<?php
declare(strict_types=1);

/**
 * WooCommerce — the write half of running a store.
 *
 * woocommerce.php covers the catalogue and reads the ledger. This file is what
 * an agent needs to actually operate the shop: customers, variations, orders it
 * can build and amend, refunds, product terms, reviews, bulk stock, and an
 * audit that hunts for the faults a store keeps quiet about.
 *
 * Everything here goes through the WooCommerce CRUD classes — WC_Customer,
 * WC_Coupon, WC_Product_Variation, WC_Order, wc_create_refund — never straight
 * into posts or postmeta. Money and stock are involved, so bad input is refused
 * with a WP_Error rather than guessed at.
 *
 * Ships in the Free build, same as woocommerce.php.
 */

if (!defined('ABSPATH')) {
    exit();
}

// ---------------------------------------------------------------------------
// Shared helpers. Prefixed nibwp_wcm_ so they cannot collide with the
// nibwp_wc_* helpers woocommerce.php already declares.
// ---------------------------------------------------------------------------

/**
 * The one guard every ability in this file opens with.
 */
function nibwp_wcm_guard(): ?WP_Error
{
    if (!class_exists('WooCommerce')) {
        return new WP_Error('wc_not_active', 'WooCommerce is not active.');
    }

    return null;
}

/**
 * Clamp a per_page/page pair to the house window.
 *
 * @return array{per_page:int, page:int}
 */
function nibwp_wcm_paginate(array $input, int $default = 20): array
{
    return [
        'per_page' => min(max((int) ($input['per_page'] ?? $default), 1), 100),
        'page' => max((int) ($input['page'] ?? 1), 1),
    ];
}

/**
 * Validate a price-shaped string. '' clears the price, which is legal.
 *
 * Returns the value to hand a setter, or a WP_Error — never a guess, because a
 * silently-zeroed price sells stock for nothing.
 */
function nibwp_wcm_price(mixed $value, string $field): string|WP_Error
{
    if ($value === null) {
        return '';
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (!is_numeric($raw)) {
        return new WP_Error(
            'invalid_price',
            sprintf('%s must be a number or an empty string to clear it, got "%s".', $field, $raw),
        );
    }
    if ((float) $raw < 0) {
        return new WP_Error('invalid_price', sprintf('%s cannot be negative.', $field));
    }

    return $raw;
}

/**
 * Validate a non-negative decimal used for weight/dimensions.
 */
function nibwp_wcm_measure(mixed $value, string $field): string|WP_Error
{
    if ($value === null) {
        return '';
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (!is_numeric($raw) || (float) $raw < 0) {
        return new WP_Error('invalid_measure', sprintf('%s must be a non-negative number or an empty string.', $field));
    }

    return $raw;
}

/** The billing address fields WooCommerce stores, in setter order. */
function nibwp_wcm_billing_fields(): array
{
    return ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];
}

/** The shipping address fields WooCommerce stores. No email — orders ship, they do not email. */
function nibwp_wcm_shipping_fields(): array
{
    return ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'];
}

/**
 * Apply a billing/shipping address array through the individual CRUD setters.
 *
 * Individual setters rather than set_billing_address(): they have existed since
 * WooCommerce 3.0 and they ignore nothing silently.
 *
 * @param WC_Customer|WC_Order $object
 * @return array<int, string> The fields actually applied.
 */
function nibwp_wcm_apply_address(object $object, string $type, array $address): array
{
    $allowed = $type === 'billing' ? nibwp_wcm_billing_fields() : nibwp_wcm_shipping_fields();
    $applied = [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $address)) {
            continue;
        }
        $setter = 'set_' . $type . '_' . $field;
        if (!method_exists($object, $setter)) {
            continue;
        }
        $object->$setter((string) $address[$field]);
        $applied[] = $type . '.' . $field;
    }

    return $applied;
}

/**
 * Read an address off a customer or order into a flat array.
 *
 * @param WC_Customer|WC_Order $object
 */
function nibwp_wcm_read_address(object $object, string $type): array
{
    $fields = $type === 'billing' ? nibwp_wcm_billing_fields() : nibwp_wcm_shipping_fields();
    $out = [];

    foreach ($fields as $field) {
        $getter = 'get_' . $type . '_' . $field;
        $out[$field] = method_exists($object, $getter) ? (string) $object->$getter() : '';
    }

    return $out;
}

/**
 * Resolve a product or variation by id or SKU.
 */
function nibwp_wcm_resolve_product(mixed $id, mixed $sku): WC_Product|WP_Error
{
    $id = (int) ($id ?? 0);
    $sku = trim((string) ($sku ?? ''));

    if ($id <= 0 && $sku === '') {
        return new WP_Error('missing_product_ref', 'Provide either product_id or sku.');
    }

    if ($id <= 0) {
        $id = (int) wc_get_product_id_by_sku($sku);
        if ($id <= 0) {
            return new WP_Error('sku_not_found', sprintf('No product or variation found with SKU "%s".', $sku));
        }
    }

    $product = wc_get_product($id);
    if (!$product) {
        return new WP_Error('product_not_found', sprintf('Product %d not found.', $id));
    }

    return $product;
}

/**
 * The summary shape every variation ability returns.
 */
function nibwp_wcm_variation_data(WC_Product $variation): array
{
    $image_id = (int) $variation->get_image_id();

    return [
        'id' => $variation->get_id(),
        'parent_id' => $variation->get_parent_id(),
        'sku' => $variation->get_sku(),
        'status' => $variation->get_status(),
        'price' => $variation->get_price(),
        'regular_price' => $variation->get_regular_price(),
        'sale_price' => $variation->get_sale_price(),
        'manage_stock' => $variation->get_manage_stock(),
        'stock_quantity' => $variation->get_stock_quantity(),
        'stock_status' => $variation->get_stock_status(),
        'backorders' => $variation->get_backorders(),
        'attributes' => $variation->get_attributes(),
        'weight' => $variation->get_weight(),
        'dimensions' => [
            'length' => $variation->get_length(),
            'width' => $variation->get_width(),
            'height' => $variation->get_height(),
        ],
        'shipping_class' => $variation->get_shipping_class(),
        'description' => $variation->get_description(),
        'image' => $image_id > 0 ? ['id' => $image_id, 'src' => wp_get_attachment_url($image_id)] : null,
        'purchasable' => $variation->is_purchasable(),
    ];
}

/**
 * Map {name, option} pairs onto the keys WooCommerce stores a variation under.
 *
 * A variation whose attribute keys do not match the parent's variation
 * attributes is invisible in the shop and unbuyable, and WooCommerce says
 * nothing about it — so an unknown attribute is refused here rather than
 * dropped the way the REST controller drops it.
 *
 * @return array<string, string>|WP_Error
 */
function nibwp_wcm_variation_attributes(WC_Product $parent, array $attributes): array|WP_Error
{
    $parent_attributes = $parent->get_attributes();
    $out = [];

    foreach ($attributes as $attribute) {
        $name = trim((string) ($attribute['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('invalid_attribute', 'Each attribute needs a name.');
        }

        $key = sanitize_title($name);
        if (!isset($parent_attributes[$key])) {
            return new WP_Error(
                'unknown_attribute',
                sprintf(
                    'The parent product has no attribute "%s". Its attributes are: %s.',
                    $name,
                    implode(', ', array_keys($parent_attributes)) ?: '(none)',
                ),
            );
        }

        /** @var WC_Product_Attribute $parent_attribute */
        $parent_attribute = $parent_attributes[$key];
        if (!$parent_attribute->get_variation()) {
            return new WP_Error(
                'attribute_not_for_variations',
                sprintf('The parent attribute "%s" is not marked "used for variations", so a variation cannot be assigned to it.', $name),
            );
        }

        $value = (string) ($attribute['option'] ?? '');

        // "Any <attribute>" is an empty value, and it is legitimate.
        if ($value !== '' && $parent_attribute->is_taxonomy()) {
            $term = get_term_by('name', $value, $key);
            if (!$term || is_wp_error($term)) {
                $term = get_term_by('slug', sanitize_title($value), $key);
            }
            if (!$term || is_wp_error($term)) {
                return new WP_Error(
                    'unknown_attribute_term',
                    sprintf('"%s" is not a term of the global attribute "%s".', $value, $key),
                );
            }
            $value = $term->slug;
        }

        $out[sanitize_title($parent_attribute->get_name())] = $value;
    }

    return $out;
}

/**
 * The coupon shape the coupon abilities return.
 */
function nibwp_wcm_coupon_data(WC_Coupon $coupon): array
{
    return [
        'id' => $coupon->get_id(),
        'code' => $coupon->get_code(),
        'status' => $coupon->get_status(),
        'discount_type' => $coupon->get_discount_type(),
        'amount' => $coupon->get_amount(),
        'description' => $coupon->get_description(),
        'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d H:i:s') : null,
        'usage_count' => $coupon->get_usage_count(),
        'usage_limit' => $coupon->get_usage_limit(),
        'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
        'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
        'individual_use' => $coupon->get_individual_use(),
        'free_shipping' => $coupon->get_free_shipping(),
        'exclude_sale_items' => $coupon->get_exclude_sale_items(),
        'minimum_amount' => $coupon->get_minimum_amount(),
        'maximum_amount' => $coupon->get_maximum_amount(),
        'product_ids' => $coupon->get_product_ids(),
        'excluded_product_ids' => $coupon->get_excluded_product_ids(),
        'product_categories' => $coupon->get_product_categories(),
        'excluded_product_categories' => $coupon->get_excluded_product_categories(),
        'email_restrictions' => $coupon->get_email_restrictions(),
    ];
}

/**
 * One audit finding, in the shape the bitform audit established.
 */
function nibwp_wcm_finding(string $type, int $id, string $title, string $severity, string $code, string $message, string $fix): array
{
    return [
        'type' => $type,
        'id' => $id,
        'title' => $title,
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
        'fix' => $fix,
    ];
}

/** Every rule nibwp/wc-audit-store can report, so a client can enumerate them. */
function nibwp_wcm_audit_codes(): array
{
    return [
        'out_of_stock_no_backorders',
        'no_price',
        'variable_without_variations',
        'variation_without_price',
        'no_image',
        'no_category',
        'missing_sku',
        'coupon_expired_still_published',
        'coupon_usage_limit_reached',
        'free_shipping_without_minimum',
        'order_stuck_processing',
        'order_stuck_on_hold',
    ];
}

// ---------------------------------------------------------------------------
// CUSTOMERS
// ---------------------------------------------------------------------------

// --- Ability: nibwp/wc-get-customer ---

wp_register_ability('nibwp/wc-get-customer', [
    'label' => __('WooCommerce – Get Customer', 'nibwp'),
    'description' => 'Get one WooCommerce customer by ID or email: profile, billing and shipping addresses, order count, lifetime spend, and last order.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'customer_id' => ['type' => 'integer', 'description' => 'The customer user ID.'],
            'email' => ['type' => 'string', 'description' => 'Look the customer up by email instead of ID.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'customer' => ['type' => 'object', 'description' => 'Full customer data.'],
        ],
        'required' => ['customer'],
    ],
    'execute_callback' => 'nibwp_wc_get_customer',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Retrieve one WooCommerce customer.',
                '',
                'LOOKUP: pass customer_id, or email. One of the two is required.',
                '',
                'Returns:',
                '- Profile: id, email, username, first_name, last_name, display_name, role.',
                '- Addresses: billing (with email and phone) and shipping.',
                '- Trading history: orders_count, total_spent, last_order (id, status, total, date).',
                '- Flags: is_paying_customer, is_vat_exempt.',
                '- Dates: date_created, date_modified.',
                '',
                'GUEST ORDERS: a customer who checked out without an account has no user',
                'record, so nothing is returned here. Use wc-list-orders with a billing',
                'email search instead.',
                '',
                'PERSONAL DATA: this returns a named person\'s address and spend. Read it',
                'for the task at hand and do not copy it elsewhere.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_get_customer(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $customer_id = (int) ($input['customer_id'] ?? 0);
    $email = trim((string) ($input['email'] ?? ''));

    if ($customer_id <= 0 && $email === '') {
        return new WP_Error('missing_customer_ref', 'Provide either customer_id or email.');
    }

    if ($customer_id <= 0) {
        $user = get_user_by('email', $email);
        if (!$user) {
            return new WP_Error('customer_not_found', sprintf('No user account with the email "%s". A guest checkout has no customer record.', $email));
        }
        $customer_id = (int) $user->ID;
    }

    $user = get_user_by('id', $customer_id);
    if (!$user) {
        return new WP_Error('customer_not_found', sprintf('Customer %d not found.', $customer_id));
    }

    $customer = new WC_Customer($customer_id);

    $last_order = $customer->get_last_order();
    $last = null;
    if ($last_order instanceof WC_Order) {
        $last = [
            'id' => $last_order->get_id(),
            'status' => $last_order->get_status(),
            'total' => $last_order->get_total(),
            'date_created' => $last_order->get_date_created() ? $last_order->get_date_created()->date('Y-m-d H:i:s') : null,
        ];
    }

    return [
        'customer' => [
            'id' => $customer->get_id(),
            'email' => $customer->get_email(),
            'username' => $customer->get_username(),
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'display_name' => $customer->get_display_name(),
            'role' => $customer->get_role(),
            'billing' => nibwp_wcm_read_address($customer, 'billing'),
            'shipping' => nibwp_wcm_read_address($customer, 'shipping'),
            'orders_count' => (int) $customer->get_order_count(),
            'total_spent' => $customer->get_total_spent(),
            'last_order' => $last,
            'is_paying_customer' => $customer->get_is_paying_customer(),
            'is_vat_exempt' => $customer->get_is_vat_exempt(),
            'avatar_url' => $customer->get_avatar_url(),
            'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date('Y-m-d H:i:s') : null,
            'date_modified' => $customer->get_date_modified() ? $customer->get_date_modified()->date('Y-m-d H:i:s') : null,
            'edit_url' => admin_url('user-edit.php?user_id=' . $customer->get_id()),
        ],
    ];
}

// --- Ability: nibwp/wc-create-customer ---

wp_register_ability('nibwp/wc-create-customer', [
    'label' => __('WooCommerce – Create Customer', 'nibwp'),
    'description' => 'Create a WooCommerce customer account with billing and shipping addresses. Refuses if the email or username is already taken.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'email' => ['type' => 'string', 'description' => 'Account email. Must be valid and unused.'],
            'username' => ['type' => 'string', 'description' => 'Login name. Generated from the email when omitted.'],
            'password' => ['type' => 'string', 'description' => 'Account password. A strong one is generated when omitted.'],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'billing' => [
                'type' => 'object',
                'description' => 'Billing address: first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone.',
            ],
            'shipping' => [
                'type' => 'object',
                'description' => 'Shipping address: same fields as billing minus email.',
            ],
        ],
        'required' => ['email'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'email' => ['type' => 'string'],
            'username' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
        ],
        'required' => ['id', 'email'],
    ],
    'execute_callback' => 'nibwp_wc_create_customer',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Create a WooCommerce customer (a WordPress user in the "customer" role).',
                '',
                'REQUIRED: email. It must be a valid address that no account already uses.',
                '',
                'USERNAME: generated from the email when you omit it.',
                'PASSWORD: generated when you omit it, and never returned. The customer',
                'resets it through the normal lost-password flow.',
                '',
                'ADDRESSES: billing and shipping are plain objects of address fields —',
                'first_name, last_name, company, address_1, address_2, city, state,',
                'postcode, country, phone, and email on billing only. country and state',
                'want the two-letter codes WooCommerce stores (GB, US, CA…).',
                '',
                'NOT IDEMPOTENT: calling twice with the same email refuses the second time',
                'rather than creating a duplicate.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_customer(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $email = trim((string) ($input['email'] ?? ''));
    if ($email === '') {
        return new WP_Error('missing_email', 'email is required.');
    }
    if (!is_email($email)) {
        return new WP_Error('invalid_email', sprintf('"%s" is not a valid email address.', $email));
    }
    if (email_exists($email)) {
        return new WP_Error('email_exists', sprintf('An account already uses "%s".', $email));
    }

    $username = trim((string) ($input['username'] ?? ''));
    if ($username !== '' && username_exists($username)) {
        return new WP_Error('username_exists', sprintf('The username "%s" is taken.', $username));
    }

    $customer = new WC_Customer();
    $customer->set_email($email);
    if ($username !== '') {
        $customer->set_username($username);
    }
    if (!empty($input['password'])) {
        $customer->set_password((string) $input['password']);
    }
    if (isset($input['first_name'])) {
        $customer->set_first_name((string) $input['first_name']);
    }
    if (isset($input['last_name'])) {
        $customer->set_last_name((string) $input['last_name']);
    }
    if (isset($input['display_name'])) {
        $customer->set_display_name((string) $input['display_name']);
    }
    if (isset($input['billing'])) {
        nibwp_wcm_apply_address($customer, 'billing', (array) $input['billing']);
    }
    if (isset($input['shipping'])) {
        nibwp_wcm_apply_address($customer, 'shipping', (array) $input['shipping']);
    }

    // WC_Customer::save() throws WC_Data_Exception when the underlying user
    // cannot be created — a refusal, not a crash.
    try {
        $customer->save();
    } catch (Exception $e) {
        return new WP_Error('save_failed', sprintf('Failed to create the customer: %s', $e->getMessage()));
    }

    $customer_id = (int) $customer->get_id();
    if ($customer_id <= 0) {
        return new WP_Error('save_failed', 'Failed to create the customer.');
    }

    return [
        'id' => $customer_id,
        'email' => $customer->get_email(),
        'username' => $customer->get_username(),
        'edit_url' => admin_url('user-edit.php?user_id=' . $customer_id),
    ];
}

// --- Ability: nibwp/wc-update-customer ---

wp_register_ability('nibwp/wc-update-customer', [
    'label' => __('WooCommerce – Update Customer', 'nibwp'),
    'description' => 'Update a WooCommerce customer: name, email, and billing or shipping address. Only the fields you pass are changed.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'customer_id' => ['type' => 'integer', 'description' => 'The customer user ID to update.'],
            'email' => ['type' => 'string', 'description' => 'New account email. Must not belong to another account.'],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'billing' => ['type' => 'object', 'description' => 'Billing address fields to change. Omitted fields stay as they are.'],
            'shipping' => ['type' => 'object', 'description' => 'Shipping address fields to change.'],
            'is_vat_exempt' => ['type' => 'boolean', 'description' => 'Exempt this customer from tax.'],
        ],
        'required' => ['customer_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['id', 'updated_fields'],
    ],
    'execute_callback' => 'nibwp_wc_update_customer',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update an existing WooCommerce customer.',
                '',
                'REQUIRED: customer_id.',
                '',
                'Only the fields you include change. Inside billing and shipping the same',
                'rule applies per field, so passing {"billing": {"city": "Leeds"}} changes',
                'the city and leaves the rest of the address alone.',
                '',
                'PASSWORDS are deliberately not settable here. Resetting someone\'s password',
                'locks them out of their account; point them at the lost-password flow.',
                '',
                'TIP: read the current state with wc-get-customer first.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_update_customer(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $customer_id = (int) ($input['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        return new WP_Error('invalid_customer_id', 'customer_id must be a positive integer.');
    }

    if (!get_user_by('id', $customer_id)) {
        return new WP_Error('customer_not_found', sprintf('Customer %d not found.', $customer_id));
    }

    $customer = new WC_Customer($customer_id);
    $updated_fields = [];

    if (array_key_exists('email', $input)) {
        $email = trim((string) $input['email']);
        if (!is_email($email)) {
            return new WP_Error('invalid_email', sprintf('"%s" is not a valid email address.', $email));
        }
        $owner = email_exists($email);
        if ($owner && (int) $owner !== $customer_id) {
            return new WP_Error('email_exists', sprintf('Another account already uses "%s".', $email));
        }
        $customer->set_email($email);
        $updated_fields[] = 'email';
    }

    foreach (['first_name' => 'set_first_name', 'last_name' => 'set_last_name', 'display_name' => 'set_display_name'] as $field => $setter) {
        if (array_key_exists($field, $input)) {
            $customer->$setter((string) $input[$field]);
            $updated_fields[] = $field;
        }
    }

    if (array_key_exists('is_vat_exempt', $input)) {
        $customer->set_is_vat_exempt((bool) $input['is_vat_exempt']);
        $updated_fields[] = 'is_vat_exempt';
    }

    if (isset($input['billing'])) {
        foreach (nibwp_wcm_apply_address($customer, 'billing', (array) $input['billing']) as $applied) {
            $updated_fields[] = $applied;
        }
    }
    if (isset($input['shipping'])) {
        foreach (nibwp_wcm_apply_address($customer, 'shipping', (array) $input['shipping']) as $applied) {
            $updated_fields[] = $applied;
        }
    }

    if ($updated_fields === []) {
        return new WP_Error('nothing_to_update', 'No updatable fields were provided.');
    }

    try {
        $customer->save();
    } catch (Exception $e) {
        return new WP_Error('save_failed', sprintf('Failed to update the customer: %s', $e->getMessage()));
    }

    return [
        'id' => $customer_id,
        'updated_fields' => $updated_fields,
    ];
}

// ---------------------------------------------------------------------------
// COUPONS
// ---------------------------------------------------------------------------

// --- Ability: nibwp/wc-update-coupon ---

wp_register_ability('nibwp/wc-update-coupon', [
    'label' => __('WooCommerce – Update Coupon', 'nibwp'),
    'description' => 'Update an existing WooCommerce coupon: amount, discount type, expiry, usage limits, restrictions, and publish status.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'coupon_id' => ['type' => 'integer', 'description' => 'The coupon ID to update.'],
            'code' => ['type' => 'string', 'description' => 'Look the coupon up by code instead of ID.'],
            'new_code' => ['type' => 'string', 'description' => 'Rename the coupon. Must not collide with another coupon.'],
            'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private'], 'description' => 'Set to draft to take a coupon out of use without deleting it.'],
            'discount_type' => ['type' => 'string', 'enum' => ['percent', 'fixed_cart', 'fixed_product']],
            'amount' => ['type' => 'string', 'description' => 'Discount amount. Must be a non-negative number.'],
            'description' => ['type' => 'string'],
            'date_expires' => ['type' => 'string', 'description' => 'Expiry date (Y-m-d). Pass an empty string to remove the expiry.'],
            'usage_limit' => ['type' => 'integer', 'description' => 'Total uses allowed. 0 for unlimited.'],
            'usage_limit_per_user' => ['type' => 'integer', 'description' => 'Uses allowed per customer. 0 for unlimited.'],
            'limit_usage_to_x_items' => ['type' => 'integer', 'description' => 'Cap the number of qualifying items the discount applies to.'],
            'individual_use' => ['type' => 'boolean'],
            'free_shipping' => ['type' => 'boolean'],
            'exclude_sale_items' => ['type' => 'boolean'],
            'minimum_amount' => ['type' => 'string', 'description' => 'Minimum cart subtotal. Empty string removes the requirement.'],
            'maximum_amount' => ['type' => 'string'],
            'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'excluded_product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'product_categories' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'product_cat term IDs the coupon applies to.'],
            'excluded_product_categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'email_restrictions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Billing emails allowed to use the coupon.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'coupon' => ['type' => 'object'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['coupon', 'updated_fields'],
    ],
    'execute_callback' => 'nibwp_wc_update_coupon',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update an existing WooCommerce coupon.',
                '',
                'LOOKUP: coupon_id, or code. One of the two is required.',
                'RENAME: new_code changes the code customers type. Anything already printed',
                'or emailed with the old code stops working, so prefer creating a second',
                'coupon over renaming a live one.',
                '',
                'Only the fields you include change.',
                '',
                'TAKING A COUPON OUT OF USE: set status to draft. That keeps the usage',
                'history, which wc-delete-coupon throws away.',
                '',
                'CLEARING A VALUE: pass an empty string for date_expires, minimum_amount or',
                'maximum_amount, or 0 for the usage limits.',
                '',
                'FREE SHIPPING: free_shipping only does anything if the store has a',
                '"Free shipping" method set to require a coupon. A free-shipping coupon with',
                'no minimum_amount ships a one-item order for nothing — wc-audit-store flags',
                'exactly that.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_update_coupon(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $coupon = nibwp_wcm_resolve_coupon($input);
    if (is_wp_error($coupon)) {
        return $coupon;
    }

    $updated_fields = [];

    if (array_key_exists('new_code', $input)) {
        $new_code = trim((string) $input['new_code']);
        if ($new_code === '') {
            return new WP_Error('invalid_code', 'new_code cannot be empty.');
        }
        $existing_id = (int) wc_get_coupon_id_by_code($new_code);
        if ($existing_id > 0 && $existing_id !== $coupon->get_id()) {
            return new WP_Error('duplicate_code', sprintf('Another coupon already uses the code "%s" (ID: %d).', $new_code, $existing_id));
        }
        $coupon->set_code($new_code);
        $updated_fields[] = 'code';
    }

    if (array_key_exists('amount', $input)) {
        $amount = nibwp_wcm_price($input['amount'], 'amount');
        if (is_wp_error($amount)) {
            return $amount;
        }
        if ($amount === '') {
            return new WP_Error('invalid_price', 'amount cannot be cleared — a coupon with no amount discounts nothing.');
        }
        $type = (string) ($input['discount_type'] ?? $coupon->get_discount_type());
        if ($type === 'percent' && (float) $amount > 100) {
            return new WP_Error('invalid_price', sprintf('A percent coupon cannot discount more than 100%%, got %s.', $amount));
        }
        $coupon->set_amount($amount);
        $updated_fields[] = 'amount';
    }

    if (array_key_exists('discount_type', $input)) {
        $type = (string) $input['discount_type'];
        if (!in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            return new WP_Error('invalid_discount_type', sprintf('Unknown discount_type "%s".', $type));
        }
        $coupon->set_discount_type($type);
        $updated_fields[] = 'discount_type';
    }

    if (array_key_exists('status', $input)) {
        $status = (string) $input['status'];
        if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
            return new WP_Error('invalid_status', sprintf('Unknown coupon status "%s".', $status));
        }
        $coupon->set_status($status);
        $updated_fields[] = 'status';
    }

    if (array_key_exists('description', $input)) {
        $coupon->set_description((string) $input['description']);
        $updated_fields[] = 'description';
    }

    if (array_key_exists('date_expires', $input)) {
        $expires = trim((string) $input['date_expires']);
        $coupon->set_date_expires($expires === '' ? null : $expires);
        $updated_fields[] = 'date_expires';
    }

    foreach (['usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $value = (int) $input[$field];
        if ($value < 0) {
            return new WP_Error('invalid_limit', sprintf('%s cannot be negative.', $field));
        }
        $setter = 'set_' . $field;
        $coupon->$setter($value);
        $updated_fields[] = $field;
    }

    foreach (['individual_use', 'free_shipping', 'exclude_sale_items'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $setter = 'set_' . $field;
        $coupon->$setter((bool) $input[$field]);
        $updated_fields[] = $field;
    }

    foreach (['minimum_amount', 'maximum_amount'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $value = nibwp_wcm_price($input[$field], $field);
        if (is_wp_error($value)) {
            return $value;
        }
        $setter = 'set_' . $field;
        $coupon->$setter($value);
        $updated_fields[] = $field;
    }

    foreach (['product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $setter = 'set_' . $field;
        $coupon->$setter(array_map('intval', (array) $input[$field]));
        $updated_fields[] = $field;
    }

    if (array_key_exists('email_restrictions', $input)) {
        $emails = [];
        foreach ((array) $input['email_restrictions'] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            // A typo here locks every customer out of the coupon without a word.
            if (!is_email($candidate) && !str_contains($candidate, '*')) {
                return new WP_Error('invalid_email', sprintf('"%s" is neither an email address nor a wildcard pattern.', $candidate));
            }
            $emails[] = $candidate;
        }
        $coupon->set_email_restrictions($emails);
        $updated_fields[] = 'email_restrictions';
    }

    if ($updated_fields === []) {
        return new WP_Error('nothing_to_update', 'No updatable fields were provided.');
    }

    $coupon->save();

    return [
        'coupon' => nibwp_wcm_coupon_data($coupon),
        'updated_fields' => $updated_fields,
    ];
}

/**
 * Resolve a coupon from coupon_id or code.
 */
function nibwp_wcm_resolve_coupon(array $input): WC_Coupon|WP_Error
{
    $coupon_id = (int) ($input['coupon_id'] ?? 0);
    $code = trim((string) ($input['code'] ?? ''));

    if ($coupon_id <= 0 && $code === '') {
        return new WP_Error('missing_coupon_ref', 'Provide either coupon_id or code.');
    }

    if ($coupon_id <= 0) {
        $coupon_id = (int) wc_get_coupon_id_by_code($code);
        if ($coupon_id <= 0) {
            return new WP_Error('coupon_not_found', sprintf('No coupon with the code "%s".', $code));
        }
    }

    if (get_post_type($coupon_id) !== 'shop_coupon') {
        return new WP_Error('coupon_not_found', sprintf('%d is not a coupon.', $coupon_id));
    }

    $coupon = new WC_Coupon($coupon_id);
    if ($coupon->get_id() <= 0) {
        return new WP_Error('coupon_not_found', sprintf('Coupon %d not found.', $coupon_id));
    }

    return $coupon;
}

// --- Ability: nibwp/wc-delete-coupon ---

wp_register_ability('nibwp/wc-delete-coupon', [
    'label' => __('WooCommerce – Delete Coupon', 'nibwp'),
    'description' => 'Trash or permanently delete a WooCommerce coupon. Trashing is the default and is reversible.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'coupon_id' => ['type' => 'integer', 'description' => 'The coupon ID to delete.'],
            'code' => ['type' => 'string', 'description' => 'Look the coupon up by code instead of ID.'],
            'force' => ['type' => 'boolean', 'default' => false, 'description' => 'True deletes permanently. False (default) moves to trash.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'code' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
            'trashed' => ['type' => 'boolean'],
        ],
        'required' => ['id', 'deleted', 'trashed'],
    ],
    'execute_callback' => 'nibwp_wc_delete_coupon',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Trash or permanently delete a WooCommerce coupon.',
                '',
                'LOOKUP: coupon_id, or code.',
                '',
                'By default (force=false) the coupon is trashed and can be restored.',
                'With force=true it is gone, along with its usage history.',
                '',
                'PREFER wc-update-coupon with status=draft when the intent is "stop this',
                'coupon working". It keeps the record of who used it, which reporting and',
                'any later dispute need.',
                '',
                'A deleted coupon code stops working immediately for anyone holding it.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_delete_coupon(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $coupon = nibwp_wcm_resolve_coupon($input);
    if (is_wp_error($coupon)) {
        return $coupon;
    }

    $coupon_id = $coupon->get_id();
    $code = $coupon->get_code();
    $force = (bool) ($input['force'] ?? false);

    $coupon->delete($force);

    return [
        'id' => $coupon_id,
        'code' => $code,
        'deleted' => $force,
        'trashed' => !$force,
    ];
}

// ---------------------------------------------------------------------------
// VARIATIONS
// ---------------------------------------------------------------------------

// --- Ability: nibwp/wc-list-variations ---

wp_register_ability('nibwp/wc-list-variations', [
    'label' => __('WooCommerce – List Variations', 'nibwp'),
    'description' => 'List the variations of a variable product with price, stock, attributes, image, and dimensions for each.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => ['type' => 'integer', 'description' => 'The variable product ID.'],
            'sku' => ['type' => 'string', 'description' => 'Look the parent product up by SKU instead of ID.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'variations' => ['type' => 'array', 'items' => ['type' => 'object']],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
            'parent' => ['type' => 'object', 'description' => 'The parent product, and which of its attributes drive variations.'],
        ],
        'required' => ['variations', 'total', 'pages'],
    ],
    'execute_callback' => 'nibwp_wc_list_variations',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List the variations of one variable product.',
                '',
                'LOOKUP: product_id, or sku of the parent.',
                '',
                'Per variation: id, sku, price, regular_price, sale_price, stock (managed,',
                'quantity, status, backorders), attributes, weight, dimensions,',
                'shipping_class, image, and whether it is purchasable.',
                '',
                'The parent block lists variation_attributes — the attribute names a new',
                'variation must be assigned to. wc-create-variation refuses any other name.',
                '',
                'A variation with an empty price is not purchasable and WooCommerce hides it',
                'without complaining. purchasable=false is how you spot one.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_variations(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $parent = nibwp_wcm_resolve_variable_parent($input);
    if (is_wp_error($parent)) {
        return $parent;
    }

    $page = nibwp_wcm_paginate($input);
    $children = $parent->get_children();
    $total = count($children);
    $slice = array_slice($children, ($page['page'] - 1) * $page['per_page'], $page['per_page']);

    $variations = [];
    foreach ($slice as $variation_id) {
        $variation = wc_get_product((int) $variation_id);
        if (!$variation) {
            continue;
        }
        $variations[] = nibwp_wcm_variation_data($variation);
    }

    $variation_attributes = [];
    foreach ($parent->get_attributes() as $key => $attribute) {
        if ($attribute instanceof WC_Product_Attribute && $attribute->get_variation()) {
            $variation_attributes[] = [
                'key' => (string) $key,
                'name' => $attribute->get_name(),
                'options' => $attribute->get_options(),
                'is_taxonomy' => $attribute->is_taxonomy(),
            ];
        }
    }

    return [
        'variations' => $variations,
        'total' => $total,
        'pages' => (int) ceil($total / $page['per_page']),
        'parent' => [
            'id' => $parent->get_id(),
            'name' => $parent->get_name(),
            'sku' => $parent->get_sku(),
            'variation_attributes' => $variation_attributes,
        ],
    ];
}

/**
 * Resolve the parent of a variation operation and insist it is variable.
 */
function nibwp_wcm_resolve_variable_parent(array $input): WC_Product|WP_Error
{
    $parent = nibwp_wcm_resolve_product($input['product_id'] ?? 0, $input['sku'] ?? '');
    if (is_wp_error($parent)) {
        return $parent;
    }

    if (!$parent->is_type('variable')) {
        return new WP_Error(
            'not_variable',
            sprintf('Product %d is a %s product, not a variable one, so it has no variations.', $parent->get_id(), $parent->get_type()),
        );
    }

    return $parent;
}

// --- Ability: nibwp/wc-create-variation ---

wp_register_ability('nibwp/wc-create-variation', [
    'label' => __('WooCommerce – Create Variation', 'nibwp'),
    'description' => 'Add a variation to a variable product with its attributes, price, stock, image, and dimensions.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => ['type' => 'integer', 'description' => 'The variable product to add the variation to.'],
            'sku' => ['type' => 'string', 'description' => 'Look the parent up by its SKU instead of by ID.'],
            'attributes' => [
                'type' => 'array',
                'description' => 'One entry per variation attribute: {name, option}. An empty option means "any".',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'option' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
            'variation_sku' => ['type' => 'string', 'description' => 'The variation\'s own SKU. Must be unique across the store.'],
            'regular_price' => ['type' => 'string'],
            'sale_price' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['publish', 'private'], 'default' => 'publish', 'description' => 'publish = enabled, private = disabled.'],
            'description' => ['type' => 'string'],
            'manage_stock' => ['type' => 'boolean'],
            'stock_quantity' => ['type' => 'integer'],
            'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
            'backorders' => ['type' => 'string', 'enum' => ['no', 'notify', 'yes']],
            'weight' => ['type' => 'string'],
            'dimensions' => [
                'type' => 'object',
                'properties' => [
                    'length' => ['type' => 'string'],
                    'width' => ['type' => 'string'],
                    'height' => ['type' => 'string'],
                ],
            ],
            'shipping_class' => ['type' => 'string', 'description' => 'Shipping class slug.'],
            'image' => [
                'type' => 'object',
                'description' => 'One image: {id} for an existing attachment, or {src} to sideload from a URL.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'src' => ['type' => 'string'],
                ],
            ],
            'menu_order' => ['type' => 'integer'],
        ],
        'required' => ['attributes'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'variation' => ['type' => 'object'],
        ],
        'required' => ['variation'],
    ],
    'execute_callback' => 'nibwp_wc_create_variation',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Add one variation to a variable product.',
                '',
                'REQUIRED: the parent (product_id or sku) and attributes.',
                '',
                'ATTRIBUTES are the load-bearing part. Each {name, option} must name an',
                'attribute the parent has marked "used for variations" — run',
                'wc-list-variations first and read parent.variation_attributes. An unknown',
                'name is refused here rather than silently dropped, because a variation with',
                'mismatched attribute keys is unbuyable and WooCommerce never says so.',
                '- For a global attribute (pa_size), option takes the term name or slug.',
                '- For a per-product attribute, option takes one of the options verbatim.',
                '- An empty option means "any value of this attribute".',
                '',
                'PRICE: with no regular_price the variation is not purchasable. Set one',
                'unless you deliberately want it hidden.',
                '',
                'STOCK: manage_stock true plus stock_quantity tracks this variation on its',
                'own. Leave manage_stock out to inherit the parent handling.',
                '',
                'SKU: variation_sku, not sku — sku identifies the parent for lookup.',
                '',
                'The parent is re-synced afterwards, so its price range and stock status',
                'reflect the new variation immediately.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_variation(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $parent = nibwp_wcm_resolve_variable_parent($input);
    if (is_wp_error($parent)) {
        return $parent;
    }

    $attributes = $input['attributes'] ?? null;
    if (!is_array($attributes) || $attributes === []) {
        return new WP_Error('missing_attributes', 'attributes is required — a variation with no attributes cannot be selected in the shop.');
    }

    $resolved = nibwp_wcm_variation_attributes($parent, $attributes);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent->get_id());
    $variation->set_attributes($resolved);
    $variation->set_status((string) ($input['status'] ?? 'publish'));

    $applied = nibwp_wcm_apply_variation_fields($variation, $input);
    if (is_wp_error($applied)) {
        return $applied;
    }

    $variation_id = (int) $variation->save();
    if ($variation_id <= 0) {
        return new WP_Error('save_failed', 'Failed to save the variation.');
    }

    $image = nibwp_wcm_apply_variation_image($variation, $input);
    if (is_wp_error($image)) {
        return $image;
    }
    if ($image) {
        $variation->save();
    }

    WC_Product_Variable::sync($parent->get_id());

    return ['variation' => nibwp_wcm_variation_data($variation)];
}

/**
 * Apply the price/stock/shipping fields shared by variation create and update.
 *
 * Returns the applied field names, or a WP_Error — every numeric field is
 * validated before it reaches a setter.
 *
 * @return array<int, string>|WP_Error
 */
function nibwp_wcm_apply_variation_fields(WC_Product $variation, array $input): array|WP_Error
{
    $applied = [];

    foreach (['regular_price', 'sale_price'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $value = nibwp_wcm_price($input[$field], $field);
        if (is_wp_error($value)) {
            return $value;
        }
        $setter = 'set_' . $field;
        $variation->$setter($value);
        $applied[] = $field;
    }

    // A sale price at or above the regular price never shows as a sale, which
    // looks like a broken discount to whoever set it.
    $regular = (string) $variation->get_regular_price();
    $sale = (string) $variation->get_sale_price();
    if ($sale !== '' && $regular !== '' && (float) $sale >= (float) $regular) {
        return new WP_Error(
            'sale_price_not_lower',
            sprintf('sale_price (%s) must be below regular_price (%s), or WooCommerce ignores it.', $sale, $regular),
        );
    }

    if (array_key_exists('variation_sku', $input)) {
        $sku = trim((string) $input['variation_sku']);
        if ($sku !== '') {
            $owner = (int) wc_get_product_id_by_sku($sku);
            if ($owner > 0 && $owner !== $variation->get_id()) {
                return new WP_Error('duplicate_sku', sprintf('The SKU "%s" already belongs to product %d.', $sku, $owner));
            }
        }
        $variation->set_sku($sku);
        $applied[] = 'variation_sku';
    }

    if (array_key_exists('description', $input)) {
        $variation->set_description((string) $input['description']);
        $applied[] = 'description';
    }

    if (array_key_exists('manage_stock', $input)) {
        $variation->set_manage_stock((bool) $input['manage_stock']);
        $applied[] = 'manage_stock';
    }

    if (array_key_exists('stock_quantity', $input)) {
        $quantity = $input['stock_quantity'];
        if ($quantity !== null && !is_numeric($quantity)) {
            return new WP_Error('invalid_stock_quantity', 'stock_quantity must be a number or null.');
        }
        $variation->set_stock_quantity($quantity === null ? null : (int) $quantity);
        $applied[] = 'stock_quantity';
    }

    if (array_key_exists('stock_status', $input)) {
        $status = (string) $input['stock_status'];
        if (!in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
            return new WP_Error('invalid_stock_status', sprintf('Unknown stock_status "%s". Use instock, outofstock or onbackorder.', $status));
        }
        $variation->set_stock_status($status);
        $applied[] = 'stock_status';
    }

    if (array_key_exists('backorders', $input)) {
        $backorders = (string) $input['backorders'];
        if (!in_array($backorders, ['no', 'notify', 'yes'], true)) {
            return new WP_Error('invalid_backorders', sprintf('Unknown backorders value "%s". Use no, notify or yes.', $backorders));
        }
        $variation->set_backorders($backorders);
        $applied[] = 'backorders';
    }

    if (array_key_exists('weight', $input)) {
        $weight = nibwp_wcm_measure($input['weight'], 'weight');
        if (is_wp_error($weight)) {
            return $weight;
        }
        $variation->set_weight($weight);
        $applied[] = 'weight';
    }

    if (isset($input['dimensions'])) {
        $dimensions = (array) $input['dimensions'];
        foreach (['length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height'] as $field => $setter) {
            if (!array_key_exists($field, $dimensions)) {
                continue;
            }
            $value = nibwp_wcm_measure($dimensions[$field], 'dimensions.' . $field);
            if (is_wp_error($value)) {
                return $value;
            }
            $variation->$setter($value);
        }
        $applied[] = 'dimensions';
    }

    if (array_key_exists('shipping_class', $input)) {
        $variation->set_shipping_class((string) $input['shipping_class']);
        $applied[] = 'shipping_class';
    }

    if (array_key_exists('menu_order', $input)) {
        $variation->set_menu_order((int) $input['menu_order']);
        $applied[] = 'menu_order';
    }

    return $applied;
}

/**
 * Attach the variation image, sideloading it when only a URL was given.
 *
 * @return bool|WP_Error True when an image was set or cleared.
 */
function nibwp_wcm_apply_variation_image(WC_Product $variation, array $input): bool|WP_Error
{
    if (!array_key_exists('image', $input)) {
        return false;
    }

    $image = $input['image'];

    // An explicit null clears the image.
    if ($image === null || $image === []) {
        $variation->set_image_id(0);

        return true;
    }

    $image = (array) $image;

    if (!empty($image['id'])) {
        $attachment_id = (int) $image['id'];
        if (get_post_type($attachment_id) !== 'attachment') {
            return new WP_Error('invalid_image_id', sprintf('%d is not an attachment.', $attachment_id));
        }
        $variation->set_image_id($attachment_id);

        return true;
    }

    $src = trim((string) ($image['src'] ?? ''));
    if ($src === '') {
        return new WP_Error('invalid_image', 'image needs either an id or a src.');
    }

    $ids = nibwp_wc_sideload_images([['src' => $src]], $variation->get_id());
    if (is_wp_error($ids)) {
        return $ids;
    }
    if ($ids === []) {
        return new WP_Error('image_sideload_failed', sprintf('Nothing was attached from %s.', $src));
    }

    $variation->set_image_id($ids[0]);

    return true;
}

// --- Ability: nibwp/wc-update-variation ---

wp_register_ability('nibwp/wc-update-variation', [
    'label' => __('WooCommerce – Update Variation', 'nibwp'),
    'description' => 'Update one variation: price, stock, attributes, image, dimensions, shipping class, or enabled state.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'variation_id' => ['type' => 'integer', 'description' => 'The variation ID to update.'],
            'variation_sku' => ['type' => 'string', 'description' => 'Sets the variation SKU. Must be unique across the store.'],
            'attributes' => [
                'type' => 'array',
                'description' => 'Replaces the attribute assignment entirely.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'option' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
            'regular_price' => ['type' => 'string'],
            'sale_price' => ['type' => 'string', 'description' => 'Empty string ends the sale.'],
            'status' => ['type' => 'string', 'enum' => ['publish', 'private']],
            'description' => ['type' => 'string'],
            'manage_stock' => ['type' => 'boolean'],
            'stock_quantity' => ['type' => 'integer'],
            'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
            'backorders' => ['type' => 'string', 'enum' => ['no', 'notify', 'yes']],
            'weight' => ['type' => 'string'],
            'dimensions' => [
                'type' => 'object',
                'properties' => [
                    'length' => ['type' => 'string'],
                    'width' => ['type' => 'string'],
                    'height' => ['type' => 'string'],
                ],
            ],
            'shipping_class' => ['type' => 'string'],
            'image' => [
                'type' => 'object',
                'description' => '{id} or {src}. Pass null to remove the variation image.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'src' => ['type' => 'string'],
                ],
            ],
            'menu_order' => ['type' => 'integer'],
        ],
        'required' => ['variation_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'variation' => ['type' => 'object'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['variation', 'updated_fields'],
    ],
    'execute_callback' => 'nibwp_wc_update_variation',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update one variation of a variable product.',
                '',
                'REQUIRED: variation_id.',
                '',
                'Only the fields you include change.',
                '',
                'ATTRIBUTES, when passed, replace the whole assignment. Every name must be',
                'one of the parent variation attributes or the call is refused.',
                '',
                'ENDING A SALE: pass sale_price as an empty string. A sale_price at or above',
                'regular_price is refused, because WooCommerce quietly ignores it and the',
                'discount you thought you set never appears.',
                '',
                'REMOVING THE IMAGE: pass image as null.',
                '',
                'The parent is re-synced afterwards so its price range and stock status stay',
                'truthful.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_update_variation(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $variation_id = (int) ($input['variation_id'] ?? 0);
    if ($variation_id <= 0) {
        return new WP_Error('invalid_variation_id', 'variation_id must be a positive integer.');
    }

    $variation = wc_get_product($variation_id);
    if (!$variation) {
        return new WP_Error('variation_not_found', sprintf('Variation %d not found.', $variation_id));
    }
    if (!$variation->is_type('variation')) {
        return new WP_Error(
            'not_a_variation',
            sprintf('%d is a %s product, not a variation. Use wc-update-product for it.', $variation_id, $variation->get_type()),
        );
    }

    $parent = wc_get_product($variation->get_parent_id());
    if (!$parent) {
        return new WP_Error('parent_not_found', sprintf('Variation %d has no readable parent product.', $variation_id));
    }

    $updated_fields = [];

    if (array_key_exists('attributes', $input)) {
        $attributes = (array) $input['attributes'];
        if ($attributes === []) {
            return new WP_Error('missing_attributes', 'attributes cannot be emptied — a variation with no attributes cannot be selected.');
        }
        $resolved = nibwp_wcm_variation_attributes($parent, $attributes);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $variation->set_attributes($resolved);
        $updated_fields[] = 'attributes';
    }

    if (array_key_exists('status', $input)) {
        $status = (string) $input['status'];
        if (!in_array($status, ['publish', 'private'], true)) {
            return new WP_Error('invalid_status', sprintf('A variation is either publish (enabled) or private (disabled), got "%s".', $status));
        }
        $variation->set_status($status);
        $updated_fields[] = 'status';
    }

    $applied = nibwp_wcm_apply_variation_fields($variation, $input);
    if (is_wp_error($applied)) {
        return $applied;
    }
    foreach ($applied as $field) {
        $updated_fields[] = $field;
    }

    $image = nibwp_wcm_apply_variation_image($variation, $input);
    if (is_wp_error($image)) {
        return $image;
    }
    if ($image) {
        $updated_fields[] = 'image';
    }

    if ($updated_fields === []) {
        return new WP_Error('nothing_to_update', 'No updatable fields were provided.');
    }

    $variation->save();
    WC_Product_Variable::sync($parent->get_id());

    return [
        'variation' => nibwp_wcm_variation_data($variation),
        'updated_fields' => $updated_fields,
    ];
}

// --- Ability: nibwp/wc-delete-variation ---

wp_register_ability('nibwp/wc-delete-variation', [
    'label' => __('WooCommerce – Delete Variation', 'nibwp'),
    'description' => 'Trash or permanently delete one variation of a variable product, then re-sync the parent.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'variation_id' => ['type' => 'integer', 'description' => 'The variation ID to delete.'],
            'force' => ['type' => 'boolean', 'default' => false, 'description' => 'True deletes permanently. False (default) moves to trash.'],
        ],
        'required' => ['variation_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'parent_id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
            'trashed' => ['type' => 'boolean'],
            'remaining_variations' => ['type' => 'integer'],
        ],
        'required' => ['id', 'deleted', 'trashed'],
    ],
    'execute_callback' => 'nibwp_wc_delete_variation',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Delete one variation.',
                '',
                'By default (force=false) it is trashed and can be restored. With force=true',
                'it is gone for good.',
                '',
                'PREFER DISABLING: wc-update-variation with status=private takes a variation',
                'out of the shop while keeping it on past orders and in reporting.',
                '',
                'A variable product whose last variation is deleted cannot be bought at all —',
                'remaining_variations in the response tells you when you have reached that',
                'point. wc-audit-store reports it as a blocker.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_delete_variation(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $variation_id = (int) ($input['variation_id'] ?? 0);
    if ($variation_id <= 0) {
        return new WP_Error('invalid_variation_id', 'variation_id must be a positive integer.');
    }

    $variation = wc_get_product($variation_id);
    if (!$variation) {
        return new WP_Error('variation_not_found', sprintf('Variation %d not found.', $variation_id));
    }
    if (!$variation->is_type('variation')) {
        return new WP_Error(
            'not_a_variation',
            sprintf('%d is a %s product, not a variation. Use wc-delete-product for it.', $variation_id, $variation->get_type()),
        );
    }

    $parent_id = (int) $variation->get_parent_id();
    $force = (bool) ($input['force'] ?? false);

    $variation->delete($force);

    $remaining = 0;
    $parent = wc_get_product($parent_id);
    if ($parent && $parent->is_type('variable')) {
        WC_Product_Variable::sync($parent_id);
        $remaining = count($parent->get_children());
    }

    return [
        'id' => $variation_id,
        'parent_id' => $parent_id,
        'deleted' => $force,
        'trashed' => !$force,
        'remaining_variations' => $remaining,
    ];
}

// ---------------------------------------------------------------------------
// ORDERS
// ---------------------------------------------------------------------------

/**
 * Turn one line_items entry into the product it refers to.
 */
function nibwp_wcm_line_item_product(array $line): WC_Product|WP_Error
{
    $variation_id = (int) ($line['variation_id'] ?? 0);
    if ($variation_id > 0) {
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return new WP_Error('product_not_found', sprintf('Variation %d not found.', $variation_id));
        }

        return $variation;
    }

    return nibwp_wcm_resolve_product($line['product_id'] ?? 0, $line['sku'] ?? '');
}

/**
 * Validate a line item quantity.
 */
function nibwp_wcm_line_quantity(mixed $value): int|WP_Error
{
    if ($value === null || $value === '') {
        return new WP_Error('invalid_quantity', 'Each line item needs a quantity.');
    }
    if (!is_numeric($value)) {
        return new WP_Error('invalid_quantity', sprintf('quantity must be a number, got "%s".', (string) $value));
    }
    $quantity = (int) $value;
    if ($quantity < 1) {
        return new WP_Error('invalid_quantity', 'quantity must be at least 1. To take a line off an order pass remove: true.');
    }

    return $quantity;
}

/**
 * Resolve and validate every line of a create/update call before any of them is
 * written.
 *
 * WooCommerce persists an order item the moment it is added, so validating as
 * we go would leave a failed call with some of its lines already on the order.
 * Everything is checked here first; nibwp_wcm_apply_order_lines() then only
 * writes.
 *
 * @return array{line_items: array, shipping_lines: array, fee_lines: array}|WP_Error
 */
function nibwp_wcm_plan_order_lines(array $input): array|WP_Error
{
    $plan = ['line_items' => [], 'shipping_lines' => [], 'fee_lines' => []];

    foreach ((array) ($input['line_items'] ?? []) as $line) {
        $line = (array) $line;
        $product = nibwp_wcm_line_item_product($line);
        if (is_wp_error($product)) {
            return $product;
        }
        if ($product->is_type('variable')) {
            return new WP_Error(
                'variable_parent_on_order',
                sprintf('Product %d is a variable parent. Order the variation instead — pass variation_id.', $product->get_id()),
            );
        }

        $quantity = nibwp_wcm_line_quantity($line['quantity'] ?? 1);
        if (is_wp_error($quantity)) {
            return $quantity;
        }

        $args = [];
        foreach (['total', 'subtotal'] as $field) {
            if (!array_key_exists($field, $line)) {
                continue;
            }
            $value = nibwp_wcm_price($line[$field], 'line_items.' . $field);
            if (is_wp_error($value)) {
                return $value;
            }
            if ($value !== '') {
                $args[$field] = $value;
            }
        }
        // A total with no subtotal reads as a discounted line, which is rarely
        // what "charge this much" means.
        if (isset($args['total']) && !isset($args['subtotal'])) {
            $args['subtotal'] = $args['total'];
        }
        if (!empty($line['name'])) {
            $args['name'] = (string) $line['name'];
        }

        $plan['line_items'][] = ['product' => $product, 'quantity' => $quantity, 'args' => $args];
    }

    foreach ((array) ($input['shipping_lines'] ?? []) as $line) {
        $line = (array) $line;
        $total = nibwp_wcm_price($line['total'] ?? '0', 'shipping_lines.total');
        if (is_wp_error($total)) {
            return $total;
        }
        $plan['shipping_lines'][] = [
            'method_title' => (string) ($line['method_title'] ?? ($line['method_id'] ?? 'Shipping')),
            'method_id' => isset($line['method_id']) ? (string) $line['method_id'] : '',
            'total' => $total === '' ? '0' : $total,
        ];
    }

    foreach ((array) ($input['fee_lines'] ?? []) as $line) {
        $line = (array) $line;
        $name = trim((string) ($line['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('invalid_fee', 'Each fee line needs a name.');
        }
        $total = nibwp_wcm_price($line['total'] ?? '', 'fee_lines.total');
        if (is_wp_error($total)) {
            return $total;
        }
        if ($total === '') {
            return new WP_Error('invalid_fee', sprintf('The fee "%s" needs a total.', $name));
        }
        $plan['fee_lines'][] = [
            'name' => $name,
            'total' => $total,
            'tax_class' => isset($line['tax_class']) ? (string) $line['tax_class'] : '',
        ];
    }

    return $plan;
}

/**
 * Write a plan from nibwp_wcm_plan_order_lines() onto an order.
 *
 * @return array<int, string>|WP_Error What was added.
 */
function nibwp_wcm_apply_order_lines(WC_Order $order, array $plan): array|WP_Error
{
    $added = [];

    foreach ($plan['line_items'] as $line) {
        /** @var WC_Product $product */
        $product = $line['product'];
        $item_id = $order->add_product($product, $line['quantity'], $line['args']);
        if (!$item_id) {
            return new WP_Error('line_item_failed', sprintf('Failed to add product %d to the order.', $product->get_id()));
        }
        $added[] = 'line_item:' . $item_id;
    }

    foreach ($plan['shipping_lines'] as $line) {
        $item = new WC_Order_Item_Shipping();
        $item->set_method_title($line['method_title']);
        if ($line['method_id'] !== '') {
            $item->set_method_id($line['method_id']);
        }
        $item->set_total($line['total']);
        $order->add_item($item);
        $added[] = 'shipping_line';
    }

    foreach ($plan['fee_lines'] as $line) {
        $item = new WC_Order_Item_Fee();
        $item->set_name($line['name']);
        $item->set_total($line['total']);
        if ($line['tax_class'] !== '') {
            $item->set_tax_class($line['tax_class']);
        }
        $order->add_item($item);
        $added[] = 'fee_line';
    }

    return $added;
}

/**
 * The order summary both order-writing abilities return.
 */
function nibwp_wcm_order_summary(WC_Order $order): array
{
    $items = [];
    foreach ($order->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        $items[] = [
            'id' => $item->get_id(),
            'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'subtotal' => $item->get_subtotal(),
            'total' => $item->get_total(),
        ];
    }

    return [
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'status' => $order->get_status(),
        'currency' => $order->get_currency(),
        'subtotal' => $order->get_subtotal(),
        'shipping_total' => $order->get_shipping_total(),
        'discount_total' => $order->get_discount_total(),
        'total_tax' => $order->get_total_tax(),
        'total' => $order->get_total(),
        'customer_id' => $order->get_customer_id(),
        'items' => $items,
        'coupons' => $order->get_coupon_codes(),
        'billing' => nibwp_wcm_read_address($order, 'billing'),
        'shipping' => nibwp_wcm_read_address($order, 'shipping'),
        'edit_url' => $order->get_edit_order_url(),
    ];
}

// --- Ability: nibwp/wc-create-order ---

wp_register_ability('nibwp/wc-create-order', [
    'label' => __('WooCommerce – Create Order', 'nibwp'),
    'description' => 'Create a WooCommerce order with line items, a customer, billing and shipping addresses, shipping and fee lines, and coupons.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'description' => 'Starting status without the wc- prefix. Defaults to pending (awaiting payment).',
                'default' => 'pending',
            ],
            'customer_id' => ['type' => 'integer', 'description' => 'Attach the order to a customer account. 0 or omitted makes it a guest order.'],
            'customer_note' => ['type' => 'string', 'description' => 'The note a customer would leave at checkout.'],
            'billing' => ['type' => 'object', 'description' => 'Billing address fields, including email and phone.'],
            'shipping' => ['type' => 'object', 'description' => 'Shipping address fields.'],
            'line_items' => [
                'type' => 'array',
                'description' => 'The products ordered.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'variation_id' => ['type' => 'integer'],
                        'sku' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer'],
                        'total' => ['type' => 'string', 'description' => 'Override the line total. Defaults to the product price times quantity.'],
                        'subtotal' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            'shipping_lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'method_id' => ['type' => 'string'],
                        'method_title' => ['type' => 'string'],
                        'total' => ['type' => 'string'],
                    ],
                ],
            ],
            'fee_lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'total' => ['type' => 'string'],
                        'tax_class' => ['type' => 'string'],
                    ],
                ],
            ],
            'coupons' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Coupon codes to apply after the items are added.'],
            'payment_method' => ['type' => 'string', 'description' => 'Gateway id, e.g. bacs or cod.'],
            'payment_method_title' => ['type' => 'string'],
            'transaction_id' => ['type' => 'string'],
            'meta_data' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'value' => ['type' => 'string'],
                    ],
                ],
            ],
            'calculate_totals' => ['type' => 'boolean', 'default' => true, 'description' => 'Recalculate taxes and the order total from the lines. Leave on unless you are setting every total yourself.'],
        ],
        'required' => ['line_items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'order' => ['type' => 'object'],
        ],
        'required' => ['order'],
    ],
    'execute_callback' => 'nibwp_wc_create_order',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Create a WooCommerce order the way a shop manager would in wp-admin.',
                '',
                'REQUIRED: line_items, with at least one entry.',
                '',
                'LINE ITEMS: identify the product by product_id, variation_id or sku.',
                'A variable parent is refused — order the variation. quantity must be at',
                'least 1. Leave total out and WooCommerce prices the line from the product;',
                'pass total to charge something else, and subtotal follows it unless you set',
                'both (subtotal above total is what makes a line read as discounted).',
                '',
                'STATUS defaults to pending, which means "awaiting payment". Nothing is',
                'charged and no stock is reduced. Move it on with wc-update-order-status when',
                'the money actually arrives — that is what fires the customer emails.',
                '',
                'NO PAYMENT IS TAKEN. payment_method and transaction_id only record how the',
                'order was paid; they do not charge anyone.',
                '',
                'COUPONS are applied after the items exist, and each one is validated the way',
                'checkout validates it. An invalid or expired code fails the call rather than',
                'being ignored.',
                '',
                'ADDRESSES: billing and shipping take the usual address fields. Give billing',
                'an email if the customer should receive order emails.',
                '',
                'TOTALS are recalculated from the lines by default, taxes included.',
                '',
                'NOT IDEMPOTENT: two calls make two orders.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_order(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $line_items = (array) ($input['line_items'] ?? []);
    if ($line_items === []) {
        return new WP_Error('missing_line_items', 'line_items is required — an order with nothing in it is not an order.');
    }

    $customer_id = (int) ($input['customer_id'] ?? 0);
    if ($customer_id > 0 && !get_user_by('id', $customer_id)) {
        return new WP_Error('customer_not_found', sprintf('Customer %d not found.', $customer_id));
    }

    $status = trim((string) ($input['status'] ?? 'pending'));
    if ($status === '') {
        $status = 'pending';
    }
    $known = array_keys(wc_get_order_statuses());
    if (!in_array('wc-' . $status, $known, true) && !in_array($status, $known, true)) {
        return new WP_Error(
            'invalid_status',
            sprintf('Unknown order status "%s". Known statuses: %s.', $status, implode(', ', array_map(static fn(string $s): string => substr($s, 3), $known))),
        );
    }

    // Every line is resolved and priced before an order exists, so a bad line
    // leaves nothing behind at all.
    $plan = nibwp_wcm_plan_order_lines($input);
    if (is_wp_error($plan)) {
        return $plan;
    }

    $note = (string) ($input['customer_note'] ?? '');

    $order = wc_create_order([
        'status' => $status,
        'customer_id' => $customer_id,
        'customer_note' => $note,
        'created_via' => 'nibwp',
    ]);
    if (is_wp_error($order)) {
        return $order;
    }
    if (!$order instanceof WC_Order) {
        return new WP_Error('create_failed', 'WooCommerce did not return an order.');
    }

    if (isset($input['billing'])) {
        nibwp_wcm_apply_address($order, 'billing', (array) $input['billing']);
    }
    if (isset($input['shipping'])) {
        nibwp_wcm_apply_address($order, 'shipping', (array) $input['shipping']);
    }

    foreach (['payment_method' => 'set_payment_method', 'payment_method_title' => 'set_payment_method_title', 'transaction_id' => 'set_transaction_id'] as $field => $setter) {
        if (isset($input[$field])) {
            $order->$setter((string) $input[$field]);
        }
    }

    foreach ((array) ($input['meta_data'] ?? []) as $meta) {
        $meta = (array) $meta;
        $key = trim((string) ($meta['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $order->update_meta_data($key, $meta['value'] ?? '');
    }

    $lines = nibwp_wcm_apply_order_lines($order, $plan);
    if (is_wp_error($lines)) {
        // Planning passed and WooCommerce still refused an item. The order
        // exists and is unusable, so trash it rather than leave a half-built
        // order in the list for someone to try to fulfil.
        $order->delete(false);

        return $lines;
    }

    $order->save();

    foreach ((array) ($input['coupons'] ?? []) as $code) {
        $code = trim((string) $code);
        if ($code === '') {
            continue;
        }
        $applied = $order->apply_coupon($code);
        if (is_wp_error($applied)) {
            return new WP_Error(
                'coupon_not_applied',
                sprintf('Order %d was created, but the coupon "%s" was refused: %s', $order->get_id(), $code, $applied->get_error_message()),
            );
        }
    }

    if ((bool) ($input['calculate_totals'] ?? true)) {
        $order->calculate_totals(true);
    }

    $order->save();

    return ['order' => nibwp_wcm_order_summary($order)];
}

// --- Ability: nibwp/wc-update-order ---

wp_register_ability('nibwp/wc-update-order', [
    'label' => __('WooCommerce – Update Order', 'nibwp'),
    'description' => 'Amend an existing order: add or remove line items, change quantities, correct addresses, set payment details, and write order meta.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'order_id' => ['type' => 'integer', 'description' => 'The order ID to amend.'],
            'customer_id' => ['type' => 'integer', 'description' => 'Reassign the order to another customer. 0 makes it a guest order.'],
            'customer_note' => ['type' => 'string'],
            'billing' => ['type' => 'object', 'description' => 'Billing fields to change. Omitted fields stay as they are.'],
            'shipping' => ['type' => 'object', 'description' => 'Shipping fields to change.'],
            'line_items' => [
                'type' => 'array',
                'description' => 'Add new lines. Give no id.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'variation_id' => ['type' => 'integer'],
                        'sku' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer'],
                        'total' => ['type' => 'string'],
                        'subtotal' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            'update_line_items' => [
                'type' => 'array',
                'description' => 'Change existing lines. Each entry needs the order item id.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'The order item ID, as returned by wc-get-order.'],
                        'quantity' => ['type' => 'integer'],
                        'total' => ['type' => 'string'],
                        'subtotal' => ['type' => 'string'],
                        'remove' => ['type' => 'boolean', 'description' => 'True takes this line off the order.'],
                    ],
                    'required' => ['id'],
                ],
            ],
            'shipping_lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'method_id' => ['type' => 'string'],
                        'method_title' => ['type' => 'string'],
                        'total' => ['type' => 'string'],
                    ],
                ],
            ],
            'fee_lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'total' => ['type' => 'string'],
                        'tax_class' => ['type' => 'string'],
                    ],
                ],
            ],
            'payment_method' => ['type' => 'string'],
            'payment_method_title' => ['type' => 'string'],
            'transaction_id' => ['type' => 'string'],
            'meta_data' => [
                'type' => 'array',
                'description' => 'Order meta to write. An entry with no value deletes the key.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'value' => ['type' => 'string'],
                        'delete' => ['type' => 'boolean'],
                    ],
                    'required' => ['key'],
                ],
            ],
            'calculate_totals' => ['type' => 'boolean', 'default' => true],
        ],
        'required' => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'order' => ['type' => 'object'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['order', 'updated_fields'],
    ],
    'execute_callback' => 'nibwp_wc_update_order',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Amend an existing order — everything except its status, which belongs to',
                'wc-update-order-status because that is what sends the emails.',
                '',
                'REQUIRED: order_id. Only what you pass changes.',
                '',
                'ADDING LINES: line_items, exactly as on wc-create-order.',
                'CHANGING LINES: update_line_items, each with the order item id from',
                'wc-get-order. Pass remove: true to take a line off.',
                '',
                'QUANTITY AND MONEY: changing a quantity without a total re-prices the line',
                'at the unit price already recorded on it, so the amount the customer agreed',
                'to is what gets multiplied — not today\'s catalogue price. Pass total (and',
                'subtotal) explicitly when you want a different figure.',
                '',
                'REFUNDS ARE NOT THIS. Editing a paid order\'s lines changes what the order',
                'says, not what was charged. Use wc-create-refund to give money back.',
                '',
                'META: meta_data writes keys on the order. delete: true removes a key. Do not',
                'use it to fake gateway or payment fields.',
                '',
                'TOTALS are recalculated from the lines by default whenever anything that',
                'affects them changed.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_update_order(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $order_id = (int) ($input['order_id'] ?? 0);
    if ($order_id <= 0) {
        return new WP_Error('invalid_order_id', 'order_id must be a positive integer.');
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return new WP_Error('order_not_found', sprintf('Order %d not found.', $order_id));
    }

    // New lines are resolved and priced before anything on the order is
    // touched. WooCommerce saves an order item as soon as it is added, so a bad
    // line found halfway through would otherwise leave the order part-changed.
    $plan = nibwp_wcm_plan_order_lines($input);
    if (is_wp_error($plan)) {
        return $plan;
    }

    $updated_fields = [];

    if (array_key_exists('customer_id', $input)) {
        $customer_id = (int) $input['customer_id'];
        if ($customer_id > 0 && !get_user_by('id', $customer_id)) {
            return new WP_Error('customer_not_found', sprintf('Customer %d not found.', $customer_id));
        }
        $order->set_customer_id($customer_id);
        $updated_fields[] = 'customer_id';
    }

    if (array_key_exists('customer_note', $input)) {
        $order->set_customer_note((string) $input['customer_note']);
        $updated_fields[] = 'customer_note';
    }

    foreach (['billing', 'shipping'] as $type) {
        if (!isset($input[$type])) {
            continue;
        }
        foreach (nibwp_wcm_apply_address($order, $type, (array) $input[$type]) as $applied) {
            $updated_fields[] = $applied;
        }
    }

    foreach (['payment_method' => 'set_payment_method', 'payment_method_title' => 'set_payment_method_title', 'transaction_id' => 'set_transaction_id'] as $field => $setter) {
        if (array_key_exists($field, $input)) {
            $order->$setter((string) $input[$field]);
            $updated_fields[] = $field;
        }
    }

    foreach ((array) ($input['meta_data'] ?? []) as $meta) {
        $meta = (array) $meta;
        $key = trim((string) ($meta['key'] ?? ''));
        if ($key === '') {
            return new WP_Error('invalid_meta', 'Every meta_data entry needs a key.');
        }
        if (!empty($meta['delete'])) {
            $order->delete_meta_data($key);
            $updated_fields[] = 'meta:-' . $key;
            continue;
        }
        $order->update_meta_data($key, $meta['value'] ?? '');
        $updated_fields[] = 'meta:' . $key;
    }

    // Existing lines first: a removal followed by an addition of the same
    // product reads as a replacement, which is what "swap this item" means.
    foreach ((array) ($input['update_line_items'] ?? []) as $change) {
        $change = (array) $change;
        $item_id = (int) ($change['id'] ?? 0);
        if ($item_id <= 0) {
            return new WP_Error('invalid_item_id', 'Every update_line_items entry needs the order item id.');
        }

        $item = $order->get_item($item_id);
        if (!$item) {
            return new WP_Error('item_not_found', sprintf('Order %d has no item %d.', $order_id, $item_id));
        }

        if (!empty($change['remove'])) {
            $order->remove_item($item_id);
            $updated_fields[] = 'removed_item:' . $item_id;
            continue;
        }

        $has_total = array_key_exists('total', $change);
        $has_subtotal = array_key_exists('subtotal', $change);

        if (array_key_exists('quantity', $change)) {
            $quantity = nibwp_wcm_line_quantity($change['quantity']);
            if (is_wp_error($quantity)) {
                return $quantity;
            }
            if (!method_exists($item, 'set_quantity')) {
                return new WP_Error('item_not_quantifiable', sprintf('Order item %d has no quantity.', $item_id));
            }

            $old_quantity = (int) $item->get_quantity();
            $item->set_quantity($quantity);

            // Keep the recorded unit price rather than re-reading the catalogue:
            // the customer agreed to what is on the order, not to today's price.
            if (!$has_total && $old_quantity > 0 && method_exists($item, 'set_total')) {
                $item->set_total((string) wc_format_decimal(((float) $item->get_total() / $old_quantity) * $quantity));
            }
            if (!$has_subtotal && $old_quantity > 0 && method_exists($item, 'set_subtotal')) {
                $item->set_subtotal((string) wc_format_decimal(((float) $item->get_subtotal() / $old_quantity) * $quantity));
            }
            $updated_fields[] = 'item_quantity:' . $item_id;
        }

        foreach (['total' => 'set_total', 'subtotal' => 'set_subtotal'] as $field => $setter) {
            if (!array_key_exists($field, $change)) {
                continue;
            }
            $value = nibwp_wcm_price($change[$field], 'update_line_items.' . $field);
            if (is_wp_error($value)) {
                return $value;
            }
            if ($value === '') {
                return new WP_Error('invalid_price', sprintf('%s cannot be cleared on an order line.', $field));
            }
            if (!method_exists($item, $setter)) {
                return new WP_Error('item_not_priceable', sprintf('Order item %d has no %s.', $item_id, $field));
            }
            $item->$setter($value);
            $updated_fields[] = 'item_' . $field . ':' . $item_id;
        }

        $item->save();
    }

    $lines = nibwp_wcm_apply_order_lines($order, $plan);
    if (is_wp_error($lines)) {
        return $lines;
    }
    foreach ($lines as $line) {
        $updated_fields[] = 'added_' . $line;
    }

    if ($updated_fields === []) {
        return new WP_Error('nothing_to_update', 'No updatable fields were provided.');
    }

    $order->save();

    if ((bool) ($input['calculate_totals'] ?? true)) {
        $order->calculate_totals(true);
        $order->save();
    }

    return [
        'order' => nibwp_wcm_order_summary($order),
        'updated_fields' => $updated_fields,
    ];
}

// --- Ability: nibwp/wc-create-refund ---

wp_register_ability('nibwp/wc-create-refund', [
    'label' => __('WooCommerce – Create Refund', 'nibwp'),
    'description' => 'Refund an order in full or in part, optionally per line item, optionally restocking the returned items.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'order_id' => ['type' => 'integer', 'description' => 'The order to refund.'],
            'amount' => ['type' => 'string', 'description' => 'How much to refund. Omit with full=true to refund everything still refundable.'],
            'full' => ['type' => 'boolean', 'default' => false, 'description' => 'Refund the whole remaining refundable amount.'],
            'reason' => ['type' => 'string', 'description' => 'Why, for the order history and the customer email.'],
            'line_items' => [
                'type' => 'array',
                'description' => 'Per-line refund detail. Required when restock is true.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'The order item ID from wc-get-order.'],
                        'quantity' => ['type' => 'integer', 'description' => 'How many units come back.'],
                        'refund_total' => ['type' => 'string', 'description' => 'How much of this line to refund.'],
                        'refund_tax' => ['type' => 'string', 'description' => 'Tax to refund on this line.'],
                    ],
                    'required' => ['id'],
                ],
            ],
            'restock' => ['type' => 'boolean', 'default' => false, 'description' => 'Put the refunded quantities back into stock. Needs line_items with quantities.'],
            'refund_payment' => ['type' => 'boolean', 'default' => false, 'description' => 'Ask the gateway to move the money. Only works if the gateway supports refunds.'],
        ],
        'required' => ['order_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'refund_id' => ['type' => 'integer'],
            'order_id' => ['type' => 'integer'],
            'amount' => ['type' => 'string'],
            'reason' => ['type' => 'string'],
            'restocked' => ['type' => 'boolean'],
            'refunded_via_gateway' => ['type' => 'boolean'],
            'order_status' => ['type' => 'string'],
            'order_total_refunded' => ['type' => 'string'],
            'remaining_refundable' => ['type' => 'string'],
        ],
        'required' => ['refund_id', 'order_id', 'amount'],
    ],
    'execute_callback' => 'nibwp_wc_create_refund',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Refund an order. This moves money and cannot be undone.',
                '',
                'REQUIRED: order_id, plus either amount or full=true. Passing neither is',
                'refused rather than guessed at, and passing both is refused as well.',
                '',
                'AMOUNT must be positive and no larger than what is still refundable —',
                'the response reports remaining_refundable after each refund. An order that',
                'is already fully refunded is refused.',
                '',
                'PARTIAL PER LINE: line_items with {id, quantity, refund_total} records which',
                'items came back. The refund total still comes from amount; the lines are how',
                'the refund is attributed and how restocking knows what to put back.',
                '',
                'RESTOCK: restock=true requires line_items with quantities, because there is',
                'nothing to put back otherwise — the call is refused rather than silently',
                'restocking nothing.',
                '',
                'GATEWAY: refund_payment=true asks the payment gateway to return the money.',
                'It fails if the gateway cannot do refunds, or if the original transaction is',
                'too old. Left off (the default) the refund is recorded in WooCommerce only,',
                'and you move the money yourself.',
                '',
                'EMAILS: WooCommerce emails the customer and, when the whole order is',
                'refunded, sets the order to refunded.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_refund(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $order_id = (int) ($input['order_id'] ?? 0);
    if ($order_id <= 0) {
        return new WP_Error('invalid_order_id', 'order_id must be a positive integer.');
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return new WP_Error('order_not_found', sprintf('Order %d not found.', $order_id));
    }

    $full = (bool) ($input['full'] ?? false);
    $has_amount = array_key_exists('amount', $input) && trim((string) $input['amount']) !== '';

    if (!$full && !$has_amount) {
        return new WP_Error('missing_amount', 'Pass amount, or full=true to refund everything still refundable. Refusing to guess how much.');
    }
    if ($full && $has_amount) {
        return new WP_Error('ambiguous_amount', 'Pass either amount or full=true, not both.');
    }

    $remaining = (float) $order->get_remaining_refund_amount();
    if ($remaining <= 0) {
        return new WP_Error(
            'nothing_refundable',
            sprintf('Order %d has nothing left to refund (total %s, already refunded %s).', $order_id, $order->get_total(), $order->get_total_refunded()),
        );
    }

    if ($full) {
        $amount = (string) $remaining;
    } else {
        $validated = nibwp_wcm_price($input['amount'], 'amount');
        if (is_wp_error($validated)) {
            return $validated;
        }
        if ($validated === '' || (float) $validated <= 0) {
            return new WP_Error('invalid_amount', 'amount must be greater than zero.');
        }
        if ((float) $validated > $remaining) {
            return new WP_Error(
                'amount_exceeds_remaining',
                sprintf('Cannot refund %s — only %s of order %d is still refundable.', $validated, (string) $remaining, $order_id),
            );
        }
        $amount = $validated;
    }

    $restock = (bool) ($input['restock'] ?? false);
    $line_items = [];

    foreach ((array) ($input['line_items'] ?? []) as $line) {
        $line = (array) $line;
        $item_id = (int) ($line['id'] ?? 0);
        if ($item_id <= 0) {
            return new WP_Error('invalid_item_id', 'Every refund line needs the order item id.');
        }
        if (!$order->get_item($item_id)) {
            return new WP_Error('item_not_found', sprintf('Order %d has no item %d.', $order_id, $item_id));
        }

        $quantity = 0;
        if (array_key_exists('quantity', $line)) {
            if (!is_numeric($line['quantity']) || (int) $line['quantity'] < 0) {
                return new WP_Error('invalid_quantity', sprintf('Refund quantity for item %d must be zero or more.', $item_id));
            }
            $quantity = (int) $line['quantity'];
        }

        $refund_total = nibwp_wcm_price($line['refund_total'] ?? '0', 'line_items.refund_total');
        if (is_wp_error($refund_total)) {
            return $refund_total;
        }

        $refund_tax = nibwp_wcm_price($line['refund_tax'] ?? '0', 'line_items.refund_tax');
        if (is_wp_error($refund_tax)) {
            return $refund_tax;
        }

        $line_items[$item_id] = [
            'qty' => $quantity,
            'refund_total' => $refund_total === '' ? 0 : (float) $refund_total,
            'refund_tax' => $refund_tax === '' || (float) $refund_tax === 0.0 ? [] : [(float) $refund_tax],
        ];
    }

    if ($restock) {
        $restockable = 0;
        foreach ($line_items as $line) {
            $restockable += (int) $line['qty'];
        }
        if ($restockable < 1) {
            return new WP_Error(
                'restock_without_quantities',
                'restock=true needs line_items with a quantity on at least one line — there is nothing to put back otherwise.',
            );
        }
    }

    $refund = wc_create_refund([
        'order_id' => $order_id,
        'amount' => $amount,
        'reason' => isset($input['reason']) ? (string) $input['reason'] : null,
        'line_items' => $line_items,
        'restock_items' => $restock,
        'refund_payment' => (bool) ($input['refund_payment'] ?? false),
    ]);

    if (is_wp_error($refund)) {
        return $refund;
    }
    if (!$refund instanceof WC_Order_Refund) {
        return new WP_Error('refund_failed', sprintf('WooCommerce did not create a refund for order %d.', $order_id));
    }

    $fresh = wc_get_order($order_id);

    return [
        'refund_id' => $refund->get_id(),
        'order_id' => $order_id,
        'amount' => (string) $refund->get_amount(),
        'reason' => (string) $refund->get_reason(),
        'restocked' => $restock,
        'refunded_via_gateway' => (bool) ($input['refund_payment'] ?? false),
        'order_status' => $fresh instanceof WC_Order ? $fresh->get_status() : $order->get_status(),
        'order_total_refunded' => (string) ($fresh instanceof WC_Order ? $fresh->get_total_refunded() : $order->get_total_refunded()),
        'remaining_refundable' => (string) ($fresh instanceof WC_Order ? $fresh->get_remaining_refund_amount() : 0),
    ];
}

// --- Ability: nibwp/wc-add-order-note ---

wp_register_ability('nibwp/wc-add-order-note', [
    'label' => __('WooCommerce – Add Order Note', 'nibwp'),
    'description' => 'Add a note to an order, either a private note for staff or a customer note that emails the customer.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'order_id' => ['type' => 'integer', 'description' => 'The order to annotate.'],
            'note' => ['type' => 'string', 'description' => 'The note text.'],
            'customer_note' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'True emails the note to the customer and shows it on their order page. False (default) keeps it internal.',
            ],
            'added_by_user' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Attribute the note to the logged-in user instead of to WooCommerce.',
            ],
        ],
        'required' => ['order_id', 'note'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'note_id' => ['type' => 'integer'],
            'order_id' => ['type' => 'integer'],
            'customer_note' => ['type' => 'boolean'],
        ],
        'required' => ['note_id', 'order_id', 'customer_note'],
    ],
    'execute_callback' => 'nibwp_wc_add_order_note',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Add a note to an order.',
                '',
                'REQUIRED: order_id and note.',
                '',
                'PRIVATE (the default): staff-only, visible in the order screen.',
                '',
                'CUSTOMER-FACING: customer_note=true sends the note to the customer by email',
                'and shows it on their order page. That is a message to a real person, so',
                'write it as one — and note that it cannot be unsent.',
                '',
                'Notes are append-only. There is no ability to edit or remove one, on purpose:',
                'an order history that can be rewritten is worth nothing in a dispute.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_add_order_note(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $order_id = (int) ($input['order_id'] ?? 0);
    if ($order_id <= 0) {
        return new WP_Error('invalid_order_id', 'order_id must be a positive integer.');
    }

    $note = trim((string) ($input['note'] ?? ''));
    if ($note === '') {
        return new WP_Error('missing_note', 'note is required and cannot be empty.');
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return new WP_Error('order_not_found', sprintf('Order %d not found.', $order_id));
    }

    $customer_note = (bool) ($input['customer_note'] ?? false);
    $note_id = (int) $order->add_order_note($note, $customer_note ? 1 : 0, (bool) ($input['added_by_user'] ?? false));

    if ($note_id <= 0) {
        return new WP_Error('note_failed', sprintf('Failed to add a note to order %d.', $order_id));
    }

    return [
        'note_id' => $note_id,
        'order_id' => $order_id,
        'customer_note' => $customer_note,
    ];
}

// ---------------------------------------------------------------------------
// PRODUCT CATEGORIES
// ---------------------------------------------------------------------------

/**
 * The product_cat shape the category abilities return.
 */
function nibwp_wcm_category_data(WP_Term $term): array
{
    $thumbnail_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
    $display = (string) get_term_meta($term->term_id, 'display_type', true);
    $link = get_term_link($term);

    return [
        'id' => (int) $term->term_id,
        'name' => $term->name,
        'slug' => $term->slug,
        'parent' => (int) $term->parent,
        'description' => $term->description,
        'display' => $display === '' ? 'default' : $display,
        'menu_order' => (int) get_term_meta($term->term_id, 'order', true),
        'count' => (int) $term->count,
        'image' => $thumbnail_id > 0 ? ['id' => $thumbnail_id, 'src' => wp_get_attachment_url($thumbnail_id)] : null,
        'link' => $link instanceof WP_Error ? '' : (string) $link,
    ];
}

/**
 * Write the term meta WooCommerce keeps beside a product category.
 *
 * @return array<int, string>|WP_Error
 */
function nibwp_wcm_apply_category_meta(int $term_id, array $input): array|WP_Error
{
    $applied = [];

    if (array_key_exists('display', $input)) {
        $display = (string) $input['display'];
        if (!in_array($display, ['default', 'products', 'subcategories', 'both'], true)) {
            return new WP_Error('invalid_display', sprintf('Unknown display "%s". Use default, products, subcategories or both.', $display));
        }
        update_term_meta($term_id, 'display_type', $display === 'default' ? '' : $display);
        $applied[] = 'display';
    }

    if (array_key_exists('menu_order', $input)) {
        update_term_meta($term_id, 'order', (int) $input['menu_order']);
        $applied[] = 'menu_order';
    }

    if (array_key_exists('image', $input)) {
        $image = $input['image'];

        if ($image === null || $image === [] || $image === '') {
            delete_term_meta($term_id, 'thumbnail_id');
            $applied[] = 'image';

            return $applied;
        }

        $image = (array) $image;
        $attachment_id = (int) ($image['id'] ?? 0);

        if ($attachment_id <= 0) {
            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '') {
                return new WP_Error('invalid_image', 'image needs either an id or a src.');
            }
            $ids = nibwp_wc_sideload_images([['src' => $src]], 0);
            if (is_wp_error($ids)) {
                return $ids;
            }
            if ($ids === []) {
                return new WP_Error('image_sideload_failed', sprintf('Nothing was attached from %s.', $src));
            }
            $attachment_id = $ids[0];
        }

        if (get_post_type($attachment_id) !== 'attachment') {
            return new WP_Error('invalid_image_id', sprintf('%d is not an attachment.', $attachment_id));
        }

        update_term_meta($term_id, 'thumbnail_id', $attachment_id);
        $applied[] = 'image';
    }

    return $applied;
}

// --- Ability: nibwp/wc-list-categories ---

wp_register_ability('nibwp/wc-list-categories', [
    'label' => __('WooCommerce – List Categories', 'nibwp'),
    'description' => 'List product categories with hierarchy, product counts, image, display type, and menu order.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'parent' => ['type' => 'integer', 'description' => 'Only the children of this category. 0 returns top-level categories only.'],
            'search' => ['type' => 'string', 'description' => 'Match against the category name.'],
            'hide_empty' => ['type' => 'boolean', 'default' => false, 'description' => 'Leave out categories with no products.'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page' => ['type' => 'integer', 'default' => 1],
            'orderby' => ['type' => 'string', 'enum' => ['name', 'slug', 'count', 'id', 'menu_order'], 'default' => 'name'],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'ASC'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'categories' => ['type' => 'array', 'items' => ['type' => 'object']],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
            'default_category_id' => ['type' => 'integer', 'description' => 'The category WooCommerce falls back to. It cannot be deleted.'],
        ],
        'required' => ['categories', 'total', 'pages'],
    ],
    'execute_callback' => 'nibwp_wc_list_categories',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WooCommerce product categories (the product_cat taxonomy).',
                '',
                'FILTERS: parent (0 for top level only), search, hide_empty.',
                'SORTING: orderby name, slug, count, id or menu_order.',
                '',
                'Per category: id, name, slug, parent, description, display type, menu_order,',
                'product count, image and front-end link.',
                '',
                'default_category_id is the category WooCommerce assigns when a product has',
                'none. wc-delete-category refuses to remove it.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_categories(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $page = nibwp_wcm_paginate($input, 50);

    $orderby = (string) ($input['orderby'] ?? 'name');
    $args = [
        'taxonomy' => 'product_cat',
        'hide_empty' => (bool) ($input['hide_empty'] ?? false),
        'number' => $page['per_page'],
        'offset' => ($page['page'] - 1) * $page['per_page'],
        'order' => (string) ($input['order'] ?? 'ASC'),
    ];

    if ($orderby === 'menu_order') {
        $args['orderby'] = 'meta_value_num';
        $args['meta_key'] = 'order';
    } else {
        $args['orderby'] = $orderby === 'id' ? 'term_id' : $orderby;
    }

    if (array_key_exists('parent', $input)) {
        $args['parent'] = (int) $input['parent'];
    }
    if (!empty($input['search'])) {
        $args['search'] = (string) $input['search'];
    }

    $terms = get_terms($args);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $count_args = $args;
    unset($count_args['number'], $count_args['offset'], $count_args['orderby'], $count_args['meta_key']);
    $count_args['fields'] = 'count';
    $total = get_terms($count_args);
    $total = is_wp_error($total) ? count((array) $terms) : (int) $total;

    $categories = [];
    foreach ((array) $terms as $term) {
        if ($term instanceof WP_Term) {
            $categories[] = nibwp_wcm_category_data($term);
        }
    }

    return [
        'categories' => $categories,
        'total' => $total,
        'pages' => (int) ceil($total / $page['per_page']),
        'default_category_id' => (int) get_option('default_product_cat', 0),
    ];
}

// --- Ability: nibwp/wc-create-category ---

wp_register_ability('nibwp/wc-create-category', [
    'label' => __('WooCommerce – Create Category', 'nibwp'),
    'description' => 'Create a product category with an optional parent, slug, description, image, display type, and menu order.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'The category name.'],
            'slug' => ['type' => 'string', 'description' => 'URL slug. Derived from the name when omitted.'],
            'parent' => ['type' => 'integer', 'description' => 'Parent category ID. 0 for a top-level category.'],
            'description' => ['type' => 'string'],
            'display' => ['type' => 'string', 'enum' => ['default', 'products', 'subcategories', 'both'], 'description' => 'What the category archive shows.'],
            'menu_order' => ['type' => 'integer'],
            'image' => [
                'type' => 'object',
                'description' => '{id} for an existing attachment, or {src} to sideload from a URL.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'src' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'category' => ['type' => 'object'],
        ],
        'required' => ['category'],
    ],
    'execute_callback' => 'nibwp_wc_create_category',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Create a WooCommerce product category.',
                '',
                'REQUIRED: name. A category with the same name under the same parent is',
                'refused, so this is safe to retry.',
                '',
                'PARENT: pass the parent category ID to nest, or 0 / omit for top level.',
                '',
                'DISPLAY: default, products, subcategories or both — what the category',
                'archive page shows.',
                '',
                'IMAGE: {id} to reuse an attachment, {src} to sideload from a URL.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wc_create_category(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        return new WP_Error('missing_name', 'name is required.');
    }

    $parent = (int) ($input['parent'] ?? 0);
    if ($parent > 0) {
        $parent_term = get_term($parent, 'product_cat');
        if (!$parent_term instanceof WP_Term) {
            return new WP_Error('parent_not_found', sprintf('Parent category %d not found.', $parent));
        }
    }

    $args = ['parent' => $parent];
    if (isset($input['slug'])) {
        $args['slug'] = (string) $input['slug'];
    }
    if (isset($input['description'])) {
        $args['description'] = (string) $input['description'];
    }

    $result = wp_insert_term($name, 'product_cat', $args);
    if (is_wp_error($result)) {
        return $result;
    }

    $term_id = (int) $result['term_id'];

    $meta = nibwp_wcm_apply_category_meta($term_id, $input);
    if (is_wp_error($meta)) {
        return $meta;
    }

    $term = get_term($term_id, 'product_cat');
    if (!$term instanceof WP_Term) {
        return new WP_Error('category_not_found', sprintf('Category %d was created but cannot be read back.', $term_id));
    }

    return ['category' => nibwp_wcm_category_data($term)];
}

// --- Ability: nibwp/wc-update-category ---

wp_register_ability('nibwp/wc-update-category', [
    'label' => __('WooCommerce – Update Category', 'nibwp'),
    'description' => 'Rename a product category or change its slug, parent, description, image, display type, or menu order.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'category_id' => ['type' => 'integer', 'description' => 'The category term ID.'],
            'name' => ['type' => 'string'],
            'slug' => ['type' => 'string', 'description' => 'Changing this changes the category URL and breaks existing links to it.'],
            'parent' => ['type' => 'integer'],
            'description' => ['type' => 'string'],
            'display' => ['type' => 'string', 'enum' => ['default', 'products', 'subcategories', 'both']],
            'menu_order' => ['type' => 'integer'],
            'image' => [
                'type' => 'object',
                'description' => '{id} or {src}. Pass null to remove the category image.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'src' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => ['category_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'category' => ['type' => 'object'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['category', 'updated_fields'],
    ],
    'execute_callback' => 'nibwp_wc_update_category',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update a product category.',
                '',
                'REQUIRED: category_id. Only the fields you pass change.',
                '',
                'SLUG: changing it changes the archive URL. Anything linking to the old URL',
                'breaks, so leave the slug alone unless a redirect is in place.',
                '',
                'PARENT: a category cannot be made its own parent, nor a descendant of itself.',
                'Both are refused.',
                '',
                'IMAGE: pass null to remove it.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_update_category(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $category_id = (int) ($input['category_id'] ?? 0);
    if ($category_id <= 0) {
        return new WP_Error('invalid_category_id', 'category_id must be a positive integer.');
    }

    $term = get_term($category_id, 'product_cat');
    if (!$term instanceof WP_Term) {
        return new WP_Error('category_not_found', sprintf('Category %d not found.', $category_id));
    }

    $args = [];
    $updated_fields = [];

    foreach (['name', 'slug', 'description'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $value = (string) $input[$field];
        if ($field === 'name' && trim($value) === '') {
            return new WP_Error('invalid_name', 'name cannot be empty.');
        }
        $args[$field] = $value;
        $updated_fields[] = $field;
    }

    if (array_key_exists('parent', $input)) {
        $parent = (int) $input['parent'];
        if ($parent === $category_id) {
            return new WP_Error('invalid_parent', 'A category cannot be its own parent.');
        }
        if ($parent > 0) {
            $parent_term = get_term($parent, 'product_cat');
            if (!$parent_term instanceof WP_Term) {
                return new WP_Error('parent_not_found', sprintf('Parent category %d not found.', $parent));
            }
            $ancestors = get_ancestors($parent, 'product_cat', 'taxonomy');
            if (in_array($category_id, array_map('intval', $ancestors), true)) {
                return new WP_Error('invalid_parent', sprintf('Category %d is an ancestor of %d, so it cannot become its child.', $category_id, $parent));
            }
        }
        $args['parent'] = $parent;
        $updated_fields[] = 'parent';
    }

    if ($args !== []) {
        $result = wp_update_term($category_id, 'product_cat', $args);
        if (is_wp_error($result)) {
            return $result;
        }
    }

    $meta = nibwp_wcm_apply_category_meta($category_id, $input);
    if (is_wp_error($meta)) {
        return $meta;
    }
    foreach ($meta as $field) {
        $updated_fields[] = $field;
    }

    if ($updated_fields === []) {
        return new WP_Error('nothing_to_update', 'No updatable fields were provided.');
    }

    $fresh = get_term($category_id, 'product_cat');
    if (!$fresh instanceof WP_Term) {
        return new WP_Error('category_not_found', sprintf('Category %d cannot be read back.', $category_id));
    }

    return [
        'category' => nibwp_wcm_category_data($fresh),
        'updated_fields' => $updated_fields,
    ];
}

// --- Ability: nibwp/wc-delete-category ---

wp_register_ability('nibwp/wc-delete-category', [
    'label' => __('WooCommerce – Delete Category', 'nibwp'),
    'description' => 'Delete a product category. Refuses the store default, and refuses a category with children or products unless told to proceed.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'category_id' => ['type' => 'integer', 'description' => 'The category term ID to delete.'],
            'force' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required to delete a category that still has products or child categories.',
            ],
        ],
        'required' => ['category_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
            'products_affected' => ['type' => 'integer'],
            'children_promoted' => ['type' => 'integer'],
        ],
        'required' => ['id', 'deleted'],
    ],
    'execute_callback' => 'nibwp_wc_delete_category',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Delete a product category.',
                '',
                'There is no trash for taxonomy terms. This is immediate and irreversible.',
                '',
                'REFUSED WITHOUT force=true when the category still has products or child',
                'categories. Products lose the category (WooCommerce moves any left with none',
                'to the store default) and children are promoted to the deleted category\'s',
                'parent — neither is obvious from the outside, so it takes a deliberate flag.',
                '',
                'ALWAYS REFUSED for the store default category, because WooCommerce needs',
                'somewhere to put uncategorised products. wc-list-categories reports which',
                'one that is.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_delete_category(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $category_id = (int) ($input['category_id'] ?? 0);
    if ($category_id <= 0) {
        return new WP_Error('invalid_category_id', 'category_id must be a positive integer.');
    }

    $term = get_term($category_id, 'product_cat');
    if (!$term instanceof WP_Term) {
        return new WP_Error('category_not_found', sprintf('Category %d not found.', $category_id));
    }

    $default_id = (int) get_option('default_product_cat', 0);
    if ($default_id > 0 && $category_id === $default_id) {
        return new WP_Error(
            'default_category_protected',
            sprintf('Category %d ("%s") is the store default and cannot be deleted. Point the default elsewhere in WooCommerce first.', $category_id, $term->name),
        );
    }

    $children = get_term_children($category_id, 'product_cat');
    $children = is_wp_error($children) ? [] : (array) $children;
    $products = (int) $term->count;
    $force = (bool) ($input['force'] ?? false);

    if (!$force && ($products > 0 || $children !== [])) {
        return new WP_Error(
            'category_in_use',
            sprintf(
                'Category %d ("%s") has %d product(s) and %d child categor(y|ies). Pass force=true to delete it anyway.',
                $category_id,
                $term->name,
                $products,
                count($children),
            ),
        );
    }

    $result = wp_delete_term($category_id, 'product_cat');
    if (is_wp_error($result)) {
        return $result;
    }
    if ($result === false || $result === 0) {
        return new WP_Error('delete_failed', sprintf('Category %d could not be deleted.', $category_id));
    }

    return [
        'id' => $category_id,
        'name' => $term->name,
        'deleted' => true,
        'products_affected' => $products,
        'children_promoted' => count($children),
    ];
}

// ---------------------------------------------------------------------------
// REVIEWS
// ---------------------------------------------------------------------------

/**
 * The review shape the review abilities return.
 */
function nibwp_wcm_review_data(WP_Comment $comment): array
{
    $status = match ((string) $comment->comment_approved) {
        '1' => 'approved',
        '0' => 'hold',
        'spam' => 'spam',
        'trash' => 'trash',
        default => (string) $comment->comment_approved,
    };

    $rating = get_comment_meta($comment->comment_ID, 'rating', true);
    $product_id = (int) $comment->comment_post_ID;

    return [
        'id' => (int) $comment->comment_ID,
        'product_id' => $product_id,
        'product_name' => get_the_title($product_id),
        'status' => $status,
        'reviewer' => $comment->comment_author,
        'reviewer_email' => $comment->comment_author_email,
        'review' => $comment->comment_content,
        'rating' => $rating === '' ? null : (int) $rating,
        'verified' => (bool) get_comment_meta($comment->comment_ID, 'verified', true),
        'date_created' => $comment->comment_date,
    ];
}

// --- Ability: nibwp/wc-list-reviews ---

wp_register_ability('nibwp/wc-list-reviews', [
    'label' => __('WooCommerce – List Reviews', 'nibwp'),
    'description' => 'List product reviews with rating, status, verified-owner flag, and the product they belong to.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => ['type' => 'integer', 'description' => 'Only reviews of this product.'],
            'status' => [
                'type' => 'string',
                'enum' => ['approved', 'hold', 'spam', 'trash', 'all'],
                'default' => 'all',
                'description' => 'hold means awaiting moderation.',
            ],
            'rating' => ['type' => 'integer', 'description' => 'Only reviews with this star rating (1-5).'],
            'search' => ['type' => 'string', 'description' => 'Match against the review text.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
            'orderby' => ['type' => 'string', 'enum' => ['date', 'id'], 'default' => 'date'],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'reviews' => ['type' => 'array', 'items' => ['type' => 'object']],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
            'awaiting_moderation' => ['type' => 'integer', 'description' => 'Reviews on hold across the whole store, whatever this page shows.'],
        ],
        'required' => ['reviews', 'total', 'pages'],
    ],
    'execute_callback' => 'nibwp_wc_list_reviews',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'List WooCommerce product reviews.',
                '',
                'FILTERS: product_id, status (approved, hold, spam, trash, all), rating,',
                'search over the review text.',
                '',
                'Per review: id, product_id and name, status, reviewer name and email,',
                'the text, rating, whether the reviewer actually bought the product',
                '(verified), and the date.',
                '',
                'awaiting_moderation counts every held review in the store, not just this',
                'page — unmoderated reviews are invisible to shoppers and cost sales.',
                '',
                'Legacy reviews stored as plain comments are included, not only ones',
                'WooCommerce recorded with its newer review comment type.',
                '',
                'PERSONAL DATA: reviewer emails are in the response. Leave them there.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_list_reviews(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $page = nibwp_wcm_paginate($input);

    $status = (string) ($input['status'] ?? 'all');
    $status_map = ['approved' => 'approve', 'hold' => 'hold', 'spam' => 'spam', 'trash' => 'trash', 'all' => 'all'];
    if (!isset($status_map[$status])) {
        return new WP_Error('invalid_status', sprintf('Unknown review status "%s".', $status));
    }

    $args = [
        // 'review' is what WooCommerce records today; 'comment' picks up reviews
        // left before it started using its own comment type.
        'type__in' => ['review', 'comment'],
        'post_type' => 'product',
        'status' => $status_map[$status],
        'number' => $page['per_page'],
        'offset' => ($page['page'] - 1) * $page['per_page'],
        'orderby' => ((string) ($input['orderby'] ?? 'date')) === 'id' ? 'comment_ID' : 'comment_date_gmt',
        'order' => (string) ($input['order'] ?? 'DESC'),
        'no_found_rows' => false,
        'count' => false,
    ];

    if (!empty($input['product_id'])) {
        $product_id = (int) $input['product_id'];
        if (get_post_type($product_id) !== 'product') {
            return new WP_Error('product_not_found', sprintf('%d is not a product.', $product_id));
        }
        $args['post_id'] = $product_id;
    }
    if (!empty($input['search'])) {
        $args['search'] = (string) $input['search'];
    }
    if (isset($input['rating'])) {
        $rating = (int) $input['rating'];
        if ($rating < 1 || $rating > 5) {
            return new WP_Error('invalid_rating', 'rating must be between 1 and 5.');
        }
        $args['meta_query'] = [
            [
                'key' => 'rating',
                'value' => $rating,
                'compare' => '=',
                'type' => 'NUMERIC',
            ],
        ];
    }

    $query = new WP_Comment_Query();
    $comments = (array) $query->query($args);

    $reviews = [];
    foreach ($comments as $comment) {
        if ($comment instanceof WP_Comment) {
            $reviews[] = nibwp_wcm_review_data($comment);
        }
    }

    $count_args = $args;
    $count_args['count'] = true;
    unset($count_args['number'], $count_args['offset'], $count_args['orderby'], $count_args['no_found_rows']);
    $count_query = new WP_Comment_Query();
    $total = (int) $count_query->query($count_args);

    $held_query = new WP_Comment_Query();
    $awaiting = (int) $held_query->query([
        'type__in' => ['review', 'comment'],
        'post_type' => 'product',
        'status' => 'hold',
        'count' => true,
    ]);

    return [
        'reviews' => $reviews,
        'total' => $total,
        'pages' => (int) ceil($total / $page['per_page']),
        'awaiting_moderation' => $awaiting,
    ];
}

// --- Ability: nibwp/wc-moderate-review ---

wp_register_ability('nibwp/wc-moderate-review', [
    'label' => __('WooCommerce – Moderate Review', 'nibwp'),
    'description' => 'Approve, unapprove, spam, or trash a product review.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'review_id' => ['type' => 'integer', 'description' => 'The review (comment) ID.'],
            'action' => [
                'type' => 'string',
                'enum' => ['approve', 'unapprove', 'spam', 'trash', 'untrash'],
                'description' => 'approve publishes it, unapprove holds it, spam and trash remove it from the product page.',
            ],
        ],
        'required' => ['review_id', 'action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'review' => ['type' => 'object'],
            'old_status' => ['type' => 'string'],
            'new_status' => ['type' => 'string'],
        ],
        'required' => ['review', 'old_status', 'new_status'],
    ],
    'execute_callback' => 'nibwp_wc_moderate_review',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Change the moderation status of one product review.',
                '',
                'ACTIONS:',
                '- approve: publish it on the product page and let it count towards the',
                '  product\'s average rating.',
                '- unapprove: put it back on hold. It stops being visible.',
                '- spam: mark as spam and hide it.',
                '- trash: move to trash.',
                '- untrash: restore a trashed review to being held.',
                '',
                'Every one of these is reversible — nothing is deleted here.',
                '',
                'A review is a customer\'s own words. Deal with genuine spam and abuse, and',
                'leave honest criticism alone: a store with only five-star reviews is one',
                'nobody believes.',
                '',
                'Approving or unapproving changes the product\'s average rating and review',
                'count, which WooCommerce recalculates automatically.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_moderate_review(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $review_id = (int) ($input['review_id'] ?? 0);
    if ($review_id <= 0) {
        return new WP_Error('invalid_review_id', 'review_id must be a positive integer.');
    }

    $action = (string) ($input['action'] ?? '');
    $map = [
        'approve' => 'approve',
        'unapprove' => 'hold',
        'spam' => 'spam',
        'trash' => 'trash',
        'untrash' => 'hold',
    ];
    if (!isset($map[$action])) {
        return new WP_Error(
            'invalid_action',
            sprintf('Unknown action "%s". Use approve, unapprove, spam, trash or untrash.', $action),
        );
    }

    $comment = get_comment($review_id);
    if (!$comment instanceof WP_Comment) {
        return new WP_Error('review_not_found', sprintf('Review %d not found.', $review_id));
    }
    if (get_post_type((int) $comment->comment_post_ID) !== 'product') {
        return new WP_Error(
            'not_a_review',
            sprintf('Comment %d is not on a product, so it is not a product review. Use nibwp/wp-update-comment for it.', $review_id),
        );
    }

    $before = nibwp_wcm_review_data($comment);

    if ($action === 'untrash') {
        if (!wp_untrash_comment($review_id)) {
            return new WP_Error('moderation_failed', sprintf('Review %d could not be restored from trash.', $review_id));
        }
        // wp_untrash_comment restores the pre-trash status, which may be
        // approved; hold is only the fallback WordPress uses when it has none.
        $restored = get_comment($review_id);
        $after = $restored instanceof WP_Comment ? nibwp_wcm_review_data($restored) : $before;

        return [
            'review' => $after,
            'old_status' => $before['status'],
            'new_status' => $after['status'],
        ];
    }

    $result = wp_set_comment_status($review_id, $map[$action], true);
    if (is_wp_error($result)) {
        return $result;
    }
    if ($result === false) {
        return new WP_Error('moderation_failed', sprintf('Review %d could not be moved to "%s".', $review_id, $map[$action]));
    }

    $fresh = get_comment($review_id);
    $after = $fresh instanceof WP_Comment ? nibwp_wcm_review_data($fresh) : $before;

    return [
        'review' => $after,
        'old_status' => $before['status'],
        'new_status' => $after['status'],
    ];
}

// ---------------------------------------------------------------------------
// BULK STOCK
// ---------------------------------------------------------------------------

// --- Ability: nibwp/wc-bulk-update-stock ---

wp_register_ability('nibwp/wc-bulk-update-stock', [
    'label' => __('WooCommerce – Bulk Update Stock', 'nibwp'),
    'description' => 'Set stock quantity, stock status, stock management, or backorders on many products and variations in one call, by ID or SKU.',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'description' => 'One entry per product or variation. Identify it by product_id or sku.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'Product or variation ID.'],
                        'sku' => ['type' => 'string', 'description' => 'SKU, for a product or a variation.'],
                        'stock_quantity' => ['type' => 'integer', 'description' => 'The absolute quantity to store. Not a delta.'],
                        'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
                        'manage_stock' => ['type' => 'boolean'],
                        'backorders' => ['type' => 'string', 'enum' => ['no', 'notify', 'yes']],
                        'low_stock_amount' => ['type' => 'integer', 'description' => 'Threshold for the low-stock email. Only meaningful when stock is managed.'],
                    ],
                ],
            ],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'updated' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'One entry per item, with the before and after state.'],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['updated', 'count'],
    ],
    'execute_callback' => 'nibwp_wc_bulk_update_stock',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Set stock on many products and variations at once — a stock take, a delivery',
                'booked in, a supplier feed applied.',
                '',
                'REQUIRED: items, up to 100 per call. Each item names a product or variation',
                'by product_id or sku. Variations have their own SKUs and work here.',
                '',
                'ABSOLUTE VALUES ONLY. stock_quantity is the number to store, never a change',
                'to apply. There is deliberately no increase/decrease: a call that times out',
                'and gets retried would apply the delta twice, and nobody would know.',
                '',
                'ALL OR NOTHING. Every item is validated first — a product that cannot be',
                'found, a quantity that is not a number, an unknown stock status — and one bad',
                'item refuses the whole call with the index and the reason. Nothing is half',
                'applied.',
                '',
                'STOCK-MANAGED PRODUCTS derive their stock_status from stock_quantity and',
                'backorders when WooCommerce saves them, so passing stock_status for one is',
                'refused rather than quietly ignored. Set stock_quantity instead.',
                '',
                'stock_status is for products that do not manage stock, where "in stock" is',
                'just a flag.',
                '',
                'The response reports each item before and after, so you can see what actually',
                'changed. Variable parents are re-synced from their variations.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_bulk_update_stock(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $items = $input['items'] ?? null;
    if (!is_array($items) || $items === []) {
        return new WP_Error('missing_items', 'items is required and must hold at least one entry.');
    }
    if (count($items) > 100) {
        return new WP_Error('too_many_items', sprintf('%d items in one call is too many — the cap is 100.', count($items)));
    }

    // Phase one: resolve and validate everything. A half-applied stock update is
    // worse than a refused one, so nothing is written until every item is sound.
    $planned = [];
    $index = -1;

    foreach ($items as $item) {
        $index++;
        $item = (array) $item;

        $product = nibwp_wcm_resolve_product($item['product_id'] ?? 0, $item['sku'] ?? '');
        if (is_wp_error($product)) {
            return new WP_Error(
                $product->get_error_code(),
                sprintf('items[%d]: %s', $index, $product->get_error_message()),
            );
        }

        if ($product->is_type('variable')) {
            return new WP_Error(
                'variable_parent_stock',
                sprintf('items[%d]: product %d is a variable parent. Its stock comes from its variations — update those.', $index, $product->get_id()),
            );
        }

        $has_any = false;
        $plan = ['product' => $product];

        if (array_key_exists('manage_stock', $item)) {
            $plan['manage_stock'] = (bool) $item['manage_stock'];
            $has_any = true;
        }

        if (array_key_exists('stock_quantity', $item)) {
            if (!is_numeric($item['stock_quantity'])) {
                return new WP_Error('invalid_stock_quantity', sprintf('items[%d]: stock_quantity must be a number.', $index));
            }
            $plan['stock_quantity'] = (int) $item['stock_quantity'];
            $has_any = true;
        }

        if (array_key_exists('backorders', $item)) {
            $backorders = (string) $item['backorders'];
            if (!in_array($backorders, ['no', 'notify', 'yes'], true)) {
                return new WP_Error('invalid_backorders', sprintf('items[%d]: unknown backorders value "%s".', $index, $backorders));
            }
            $plan['backorders'] = $backorders;
            $has_any = true;
        }

        if (array_key_exists('low_stock_amount', $item)) {
            if (!is_numeric($item['low_stock_amount'])) {
                return new WP_Error('invalid_low_stock_amount', sprintf('items[%d]: low_stock_amount must be a number.', $index));
            }
            $plan['low_stock_amount'] = (int) $item['low_stock_amount'];
            $has_any = true;
        }

        if (array_key_exists('stock_status', $item)) {
            $status = (string) $item['stock_status'];
            if (!in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
                return new WP_Error('invalid_stock_status', sprintf('items[%d]: unknown stock_status "%s".', $index, $status));
            }
            // WC_Product::validate_props() recomputes stock_status on save for a
            // stock-managed product, so this would be thrown away on the way out.
            $will_manage = $plan['manage_stock'] ?? (bool) $product->get_manage_stock();
            if ($will_manage) {
                return new WP_Error(
                    'stock_status_ignored',
                    sprintf(
                        'items[%d]: product %d manages its own stock, so WooCommerce derives stock_status from stock_quantity and backorders and would discard the value you passed. Set stock_quantity (and backorders) instead, or pass manage_stock: false.',
                        $index,
                        $product->get_id(),
                    ),
                );
            }
            $plan['stock_status'] = $status;
            $has_any = true;
        }

        if (!$has_any) {
            return new WP_Error(
                'nothing_to_update',
                sprintf('items[%d]: nothing to change — pass at least one of stock_quantity, stock_status, manage_stock, backorders or low_stock_amount.', $index),
            );
        }

        $planned[] = $plan;
    }

    // Phase two: apply.
    $updated = [];
    $parents_to_sync = [];

    foreach ($planned as $plan) {
        /** @var WC_Product $product */
        $product = $plan['product'];

        $before = [
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'backorders' => $product->get_backorders(),
        ];

        if (array_key_exists('manage_stock', $plan)) {
            $product->set_manage_stock($plan['manage_stock']);
        }
        if (array_key_exists('backorders', $plan)) {
            $product->set_backorders($plan['backorders']);
        }
        if (array_key_exists('stock_quantity', $plan)) {
            $product->set_stock_quantity($plan['stock_quantity']);
        }
        if (array_key_exists('low_stock_amount', $plan)) {
            $product->set_low_stock_amount($plan['low_stock_amount']);
        }
        if (array_key_exists('stock_status', $plan)) {
            $product->set_stock_status($plan['stock_status']);
        }

        $product->save();

        if ($product->is_type('variation')) {
            $parents_to_sync[(int) $product->get_parent_id()] = true;
        }

        $updated[] = [
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'before' => $before,
            'after' => [
                'manage_stock' => $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'backorders' => $product->get_backorders(),
            ],
        ];
    }

    foreach (array_keys($parents_to_sync) as $parent_id) {
        WC_Product_Variable::sync((int) $parent_id);
    }

    return [
        'updated' => $updated,
        'count' => count($updated),
    ];
}

// ---------------------------------------------------------------------------
// AUDIT
// ---------------------------------------------------------------------------

// --- Ability: nibwp/wc-audit-store ---

wp_register_ability('nibwp/wc-audit-store', [
    'label' => __('WooCommerce – Audit Store', 'nibwp'),
    'description' => 'Find the store faults that quietly cost money: unbuyable published products, variations with no price, coupons past their expiry or without a minimum, and orders stuck in processing (read-only).',
    'category' => 'woocommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'include' => [
                'type' => 'array',
                'description' => 'Which groups of checks to run. Defaults to all of them.',
                'items' => ['type' => 'string', 'enum' => ['products', 'variations', 'coupons', 'orders']],
            ],
            'limit' => ['type' => 'integer', 'default' => 200, 'description' => 'Most published products to examine in one pass (max 1000).'],
            'stale_processing_days' => ['type' => 'integer', 'default' => 7, 'description' => 'How old a processing order has to be before it counts as stuck.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'products_checked' => ['type' => 'integer'],
            'variations_checked' => ['type' => 'integer'],
            'coupons_checked' => ['type' => 'integer'],
            'orders_checked' => ['type' => 'integer'],
            'verdict' => ['type' => 'string'],
            'blockers' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'object']],
            'checks_run' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['products_checked', 'coupons_checked', 'orders_checked', 'verdict', 'blockers', 'warnings'],
    ],
    'execute_callback' => 'nibwp_wc_audit_store',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read-only. It changes nothing; it finds what the store is not telling you.',
                '',
                'Every fault here is one WooCommerce permits without a warning, and every one',
                'of them has a cost:',
                '',
                'BLOCKERS — the shop is refusing money right now.',
                '- A published product out of stock with backorders off. It is in the',
                '  catalogue, it is in search, it cannot be bought.',
                '- A published product with no price. WooCommerce simply does not render an',
                '  add-to-cart button, and nothing says why.',
                '- A variable product with no variations, or whose variations have no price.',
                '  The variation dropdown appears and then nothing can be selected.',
                '- An order sitting in processing for longer than stale_processing_days. The',
                '  money was taken and nobody shipped it.',
                '',
                'WARNINGS — costing sales rather than blocking them.',
                '- No image: products without one convert far worse.',
                '- No category, or only the store default: the product is effectively',
                '  unbrowsable.',
                '- No SKU while the rest of the catalogue has them, which breaks every export,',
                '  feed and stock take that keys on SKU.',
                '- A coupon still published after its expiry date. Customers type it, it is',
                '  refused, and they abandon the basket.',
                '- A coupon whose usage limit is used up but which is still advertised.',
                '- A free-shipping coupon with no minimum spend: a one-item order ships free.',
                '- An order left on hold past the same threshold.',
                '',
                'Each finding carries the type, the id, a severity, a code, what is wrong, and',
                'the exact fix — usually naming the ability that applies it.',
                '',
                'SCOPE: limit caps how many published products are examined (newest first),',
                'so a large catalogue takes several passes. products_checked tells you how far',
                'it got. Use include to run only part of it.',
                '',
                'REQUIRES: WooCommerce plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wc_audit_store(array $input): array|WP_Error
{
    if ($guard = nibwp_wcm_guard()) {
        return $guard;
    }

    $all_groups = ['products', 'variations', 'coupons', 'orders'];
    $include = $input['include'] ?? null;
    if ($include === null || $include === []) {
        $include = $all_groups;
    }
    $include = array_values(array_intersect($all_groups, array_map('strval', (array) $include)));
    if ($include === []) {
        return new WP_Error(
            'invalid_include',
            sprintf('include must name at least one of: %s.', implode(', ', $all_groups)),
        );
    }

    $limit = min(max((int) ($input['limit'] ?? 200), 1), 1000);
    $stale_days = max((int) ($input['stale_processing_days'] ?? 7), 1);

    $findings = [];
    $products_checked = 0;
    $variations_checked = 0;
    $coupons_checked = 0;
    $orders_checked = 0;

    $default_category = (int) get_option('default_product_cat', 0);

    if (in_array('products', $include, true) || in_array('variations', $include, true)) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $with_sku = 0;
        $without_sku = [];

        foreach ((array) $products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            $products_checked++;

            $id = $product->get_id();
            $name = $product->get_name();
            $is_variable = $product->is_type('variable');

            if (in_array('products', $include, true)) {
                // Purchasable in the catalogue, unbuyable in practice.
                if (!$is_variable && (string) $product->get_price() === '') {
                    $findings[] = nibwp_wcm_finding(
                        'product',
                        $id,
                        $name,
                        'blocker',
                        'no_price',
                        __('Published with no price, so WooCommerce renders no add-to-cart button. The product is in the catalogue and in search results, and cannot be bought.', 'nibwp'),
                        __('Set regular_price with nibwp/wc-update-product, or unpublish it.', 'nibwp'),
                    );
                } elseif ($product->get_stock_status() === 'outofstock' && $product->get_backorders() === 'no') {
                    $findings[] = nibwp_wcm_finding(
                        'product',
                        $id,
                        $name,
                        'blocker',
                        'out_of_stock_no_backorders',
                        __('Out of stock with backorders off, but still published. Every visitor who finds it is turned away at the product page.', 'nibwp'),
                        __('Restock it with nibwp/wc-bulk-update-stock, allow backorders, or unpublish it until it is back.', 'nibwp'),
                    );
                }

                if ((int) $product->get_image_id() === 0) {
                    $findings[] = nibwp_wcm_finding(
                        'product',
                        $id,
                        $name,
                        'warning',
                        'no_image',
                        __('No product image. The shop and category pages show a placeholder, and a placeholder does not sell.', 'nibwp'),
                        __('Add an image with nibwp/wc-update-product.', 'nibwp'),
                    );
                }

                $categories = array_map('intval', $product->get_category_ids());
                $meaningful = array_values(array_filter($categories, static fn(int $term_id): bool => $term_id !== $default_category));
                if ($meaningful === []) {
                    $findings[] = nibwp_wcm_finding(
                        'product',
                        $id,
                        $name,
                        'warning',
                        'no_category',
                        __('No product category beyond the store default, so it appears in no category listing and in no category-based menu or filter. Only search and a direct link will find it.', 'nibwp'),
                        __('Assign a category with nibwp/wc-update-product — nibwp/wc-list-categories shows what exists.', 'nibwp'),
                    );
                }

                if ((string) $product->get_sku() === '') {
                    $without_sku[] = ['id' => $id, 'name' => $name];
                } else {
                    $with_sku++;
                }
            }

            if ($is_variable && in_array('variations', $include, true)) {
                $children = $product->get_children();

                if ($children === []) {
                    $findings[] = nibwp_wcm_finding(
                        'product',
                        $id,
                        $name,
                        'blocker',
                        'variable_without_variations',
                        __('A variable product with no variations. The product page offers a choice that has nothing in it, so there is no way to add anything to the basket.', 'nibwp'),
                        __('Add variations with nibwp/wc-create-variation, or change the product type to simple.', 'nibwp'),
                    );
                }

                foreach ($children as $child_id) {
                    $variation = wc_get_product((int) $child_id);
                    if (!$variation) {
                        continue;
                    }
                    $variations_checked++;

                    if ((string) $variation->get_price() === '') {
                        $findings[] = nibwp_wcm_finding(
                            'variation',
                            (int) $variation->get_id(),
                            sprintf('%s — %s', $name, implode(', ', array_map('strval', $variation->get_attributes()))),
                            'blocker',
                            'variation_without_price',
                            __('This variation has no price, so WooCommerce hides it from the selector without a word. Customers who want that combination cannot buy it and see no reason why.', 'nibwp'),
                            __('Set regular_price on it with nibwp/wc-update-variation, or delete the variation.', 'nibwp'),
                        );
                    }
                }
            }
        }

        // A missing SKU only matters in a store that uses SKUs. If nothing has
        // one, that is a choice; if most things have one, the gaps are mistakes.
        if ($with_sku > 0) {
            foreach ($without_sku as $product_without) {
                $findings[] = nibwp_wcm_finding(
                    'product',
                    (int) $product_without['id'],
                    (string) $product_without['name'],
                    'warning',
                    'missing_sku',
                    __('No SKU, while the rest of the catalogue has them. Every export, product feed and stock take that keys on SKU quietly skips this product.', 'nibwp'),
                    __('Give it a SKU with nibwp/wc-update-product.', 'nibwp'),
                );
            }
        }
    }

    if (in_array('coupons', $include, true)) {
        $now = (int) current_time('timestamp', true);

        $coupon_query = new WP_Query([
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'posts_per_page' => min($limit, 500),
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        foreach ($coupon_query->posts as $post) {
            $coupon = new WC_Coupon($post->ID);
            if ($coupon->get_id() <= 0) {
                continue;
            }
            $coupons_checked++;

            $code = $coupon->get_code();
            $expires = $coupon->get_date_expires();

            if ($expires && $expires->getTimestamp() < $now) {
                $findings[] = nibwp_wcm_finding(
                    'coupon',
                    (int) $coupon->get_id(),
                    $code,
                    'warning',
                    'coupon_expired_still_published',
                    sprintf(
                        /* translators: %s: coupon expiry date. */
                        __('Expired on %s but still published. Anyone who has the code from an old email or a voucher site types it, gets a refusal, and often abandons the basket rather than asking.', 'nibwp'),
                        $expires->date('Y-m-d'),
                    ),
                    __('Set it to draft with nibwp/wc-update-coupon, or extend date_expires if it should still work.', 'nibwp'),
                );
            }

            $usage_limit = (int) $coupon->get_usage_limit();
            if ($usage_limit > 0 && (int) $coupon->get_usage_count() >= $usage_limit) {
                $findings[] = nibwp_wcm_finding(
                    'coupon',
                    (int) $coupon->get_id(),
                    $code,
                    'warning',
                    'coupon_usage_limit_reached',
                    __('Its usage limit is used up, but it is still published. The code is dead and anyone still holding it finds that out at checkout.', 'nibwp'),
                    __('Raise usage_limit or set the coupon to draft with nibwp/wc-update-coupon.', 'nibwp'),
                );
            }

            if ($coupon->get_free_shipping() && (string) $coupon->get_minimum_amount() === '') {
                $findings[] = nibwp_wcm_finding(
                    'coupon',
                    (int) $coupon->get_id(),
                    $code,
                    'warning',
                    'free_shipping_without_minimum',
                    __('Grants free shipping with no minimum spend, so a single cheap item ships for nothing and the postage comes out of the margin.', 'nibwp'),
                    __('Set minimum_amount with nibwp/wc-update-coupon to the basket value that makes free shipping pay for itself.', 'nibwp'),
                );
            }
        }
    }

    if (in_array('orders', $include, true)) {
        $cutoff = gmdate('Y-m-d H:i:s', (int) current_time('timestamp', true) - $stale_days * DAY_IN_SECONDS);

        $stale_orders = wc_get_orders([
            'status' => ['processing', 'on-hold'],
            'date_created' => '<' . $cutoff,
            'limit' => min($limit, 500),
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        foreach ((array) $stale_orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $orders_checked++;

            $created = $order->get_date_created();
            $age = $created ? (int) floor(((int) current_time('timestamp', true) - $created->getTimestamp()) / DAY_IN_SECONDS) : $stale_days;
            $title = sprintf('#%s %s', $order->get_order_number(), trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));

            if ($order->get_status() === 'processing') {
                $findings[] = nibwp_wcm_finding(
                    'order',
                    (int) $order->get_id(),
                    $title,
                    'blocker',
                    'order_stuck_processing',
                    sprintf(
                        /* translators: 1: number of days, 2: order total. */
                        __('Paid and sitting in processing for %1$d days. %2$s was taken and nothing has shipped, which is how a chargeback starts.', 'nibwp'),
                        $age,
                        (string) $order->get_total(),
                    ),
                    __('Fulfil it and move it to completed with nibwp/wc-update-order-status, or refund it with nibwp/wc-create-refund and tell the customer why.', 'nibwp'),
                );
                continue;
            }

            $findings[] = nibwp_wcm_finding(
                'order',
                (int) $order->get_id(),
                $title,
                'warning',
                'order_stuck_on_hold',
                sprintf(
                    /* translators: %d: number of days. */
                    __('On hold for %d days. Either the payment never arrived and the order should be cancelled so the stock goes back, or it did arrive and the customer is still waiting.', 'nibwp'),
                    $age,
                ),
                __('Chase the payment, or cancel it with nibwp/wc-update-order-status.', 'nibwp'),
            );
        }
    }

    $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

    return [
        'products_checked' => $products_checked,
        'variations_checked' => $variations_checked,
        'coupons_checked' => $coupons_checked,
        'orders_checked' => $orders_checked,
        'verdict' => $blockers === [] ? 'ok' : 'needs_attention',
        'blockers' => $blockers,
        'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
        'checks_run' => nibwp_wcm_audit_codes(),
    ];
}
