<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: GiveWP.
 *
 * Verified against GiveWP 4.17.0 (give.php:9).
 *
 * WHERE THE DONATIONS ACTUALLY ARE. GiveWP has been moving its data into tables
 * of its own for years — donors, revenue, subscriptions, campaigns and events all
 * have one — and it is easy to assume donations went with them. In 4.17.0 they
 * did not. A donation is still a post of type `give_payment`
 * (src/Donations/Repositories/DonationRepository.php:96), its status is the post
 * status (src/Donations/ValueObjects/DonationStatus.php:35-45), and everything
 * about it lives in `{prefix}give_donationmeta` keyed by `donation_id`
 * (includes/database/class-give-db-payment-meta.php:41,58). `give_revenue` holds
 * a second copy of the amount only, in MINOR units
 * (src/Revenue/Repositories/Revenue.php:81) — it is a reporting index, not the
 * donation. So every donation read here starts from the posts table. Written
 * against the wrong store this file would return an empty list on a site with
 * thousands of donations and look like a quiet day.
 *
 * ANONYMITY IS THE WHOLE RISK. GiveWP lets a donor hide who they are, and it
 * records that as the donation meta `_give_anonymous_donation`
 * (src/Donations/ValueObjects/DonationMetaKeys.php:79). It is a property of the
 * DONATION, not of the donor — the same person can give once in their own name
 * and once anonymously. So the flag is resolved before a row is built, in one
 * function every donation read goes through, and a row that is anonymous never
 * carries a name, an email or a company. The resolver fails CLOSED: a value it
 * does not recognise is treated as anonymous, because the cost of guessing wrong
 * is a donor's name in a report they asked to be left out of. Aggregates get the
 * same treatment one level up — a donor who has only ever given anonymously is
 * not named in a listing, a top-donor table or anywhere else.
 *
 * SECRETS. The donation meta beside the amount includes the gateway's own
 * transaction id, the purchase key that unlocks the public receipt, and the
 * donor's IP and phone. The donors table carries a `token` and a `verify_key`,
 * and the subscriptions table carries the gateway `profile_id` and `transaction_id`
 * (src/Subscriptions/Migrations/CreateSubscriptionTables.php:30-45). None of it
 * leaves. Fields travel by ALLOW-LIST, not by denylist: donation meta is one flat
 * key space that every add-on writes into, so naming the handful that may leave is
 * tractable and guessing every key that must not is not — and a key added by a
 * later version, or by an add-on nobody here has heard of, defaults to staying put.
 * A value that is not scalar is DROPPED rather than cast: `(string) $array` is a
 * notice, and a serialised blob flattened into a response is how a card record
 * ends up in a transcript.
 *
 * WHAT IS NOT HERE, AND WILL NOT BE: refunding a donation, deleting a donation,
 * deleting or merging a donor, exporting donor data, and reading the site's
 * payment-gateway settings. A refund moves real money and cannot be undone from a
 * chat window; deleting a donor destroys the giving history an organisation is
 * legally obliged to keep. Both belong in wp-admin with the consequences on
 * screen. A test parses the enum and fails if an action whose name reads like any
 * of them ever appears.
 *
 * MONEY. Two stores, two scales. `_give_payment_total` is a decimal string in
 * major units; `give_revenue.amount` is an integer in minor units. Amounts are
 * therefore always reported with their currency attached, and totals are grouped
 * BY currency and never added across them — a site taking EUR and USD has no
 * single "total raised" and this file does not invent one.
 */

/** The gate on every read. GiveWP's own capability for its donor and report screens. */
const NIBWP_GIVEWP_CAP = 'view_give_reports';

/** The extra gate on a named donation. GiveWP's own per-donation view capability. */
const NIBWP_GIVEWP_CAP_DONATION = 'view_give_payments';

/** The gate on anything that changes a form. GiveWP's own form-edit capability. */
const NIBWP_GIVEWP_CAP_FORMS = 'edit_give_forms';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_GIVEWP_MAX_ROWS = 200;

/** The longest reporting window, in days. Five years. */
const NIBWP_GIVEWP_MAX_DAYS = 1825;

/* The donation meta keys this file knows by name, from
 * src/Donations/ValueObjects/DonationMetaKeys.php:52-81. Named as constants so no
 * string an agent sends can ever become a meta key. */
const NIBWP_GIVEWP_META_AMOUNT = '_give_payment_total';
const NIBWP_GIVEWP_META_CURRENCY = '_give_payment_currency';
const NIBWP_GIVEWP_META_FORM_ID = '_give_payment_form_id';
const NIBWP_GIVEWP_META_FORM_TITLE = '_give_payment_form_title';
const NIBWP_GIVEWP_META_GATEWAY = '_give_payment_gateway';
const NIBWP_GIVEWP_META_DONOR_ID = '_give_payment_donor_id';
const NIBWP_GIVEWP_META_FIRST_NAME = '_give_donor_billing_first_name';
const NIBWP_GIVEWP_META_LAST_NAME = '_give_donor_billing_last_name';
const NIBWP_GIVEWP_META_EMAIL = '_give_payment_donor_email';
const NIBWP_GIVEWP_META_COMPANY = '_give_donation_company';
const NIBWP_GIVEWP_META_ANONYMOUS = '_give_anonymous_donation';
const NIBWP_GIVEWP_META_LEVEL_ID = '_give_payment_price_id';
const NIBWP_GIVEWP_META_RECURRING = '_give_is_donation_recurring';
const NIBWP_GIVEWP_META_SUBSCRIPTION_ID = 'subscription_id';
const NIBWP_GIVEWP_META_MODE = '_give_payment_mode';
const NIBWP_GIVEWP_META_CAMPAIGN_ID = '_give_campaign_id';

/* Form meta. The enable flag and the amount are two different keys and they are
 * easy to swap: `_give_goal_option` is 'enabled'/'disabled'
 * (includes/forms/functions.php:447) and `_give_set_goal` is the number
 * (includes/class-give-donate-form.php:648). A version of this integration that
 * read the amount out of the flag reported every goal as zero. */
const NIBWP_GIVEWP_FORM_GOAL_OPTION = '_give_goal_option';
const NIBWP_GIVEWP_FORM_GOAL_AMOUNT = '_give_set_goal';
const NIBWP_GIVEWP_FORM_GOAL_FORMAT = '_give_goal_format';
const NIBWP_GIVEWP_FORM_GOAL_CLOSE = '_give_close_form_when_goal_achieved';
const NIBWP_GIVEWP_FORM_EARNINGS = '_give_form_earnings';
const NIBWP_GIVEWP_FORM_SALES = '_give_form_sales';
const NIBWP_GIVEWP_FORM_PRICE_OPTION = '_give_price_option';
const NIBWP_GIVEWP_FORM_SET_PRICE = '_give_set_price';
const NIBWP_GIVEWP_FORM_LEVELS = '_give_donation_levels';

/**
 * Is GiveWP actually loaded?
 *
 * GIVE_VERSION is defined by the main plugin file before anything else
 * (give.php:9 and the version constant it sets), and give() is the container every
 * modern read goes through. Between them they distinguish "not installed" from
 * "installed and half-booted".
 */
function nibwp_givewp_guard(): ?WP_Error
{
    if (!defined('GIVE_VERSION') && !function_exists('give')) {
        return new WP_Error(
            'nibwp_givewp_missing',
            __('GiveWP is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_givewp_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_givewp_' . $code, $message, ['status' => $status]);
}

/**
 * The one hub-level capability gate, applied to reads as well as writes.
 *
 * Reading is not a lower bar than writing here: a donation list names individual
 * people and says what each of them gave. GiveWP gates its own donor and report
 * screens on view_give_reports and deliberately withholds it from the give_worker
 * role (src/Framework/Permissions/DonorPermissions.php:26,34;
 * includes/class-give-roles.php:143,149,183), so that is the bar, not
 * manage_options — a site that gave its bookkeeper a GiveWP role on purpose
 * should not have to make them an administrator to get a report.
 */
function nibwp_givewp_require(string $what): ?WP_Error
{
    if (current_user_can(NIBWP_GIVEWP_CAP)) {
        return null;
    }

    return new WP_Error(
        'nibwp_givewp_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_GIVEWP_CAP
        ),
        ['status' => 403, 'capability' => NIBWP_GIVEWP_CAP]
    );
}

/**
 * The extra gate on anything that names one capability beyond the hub's own.
 *
 * A distinct error code on purpose. If the object-level refusal shared a code with
 * the hub-level one, a test could not tell which check answered, and a mutation
 * that deleted either would survive behind the other.
 */
function nibwp_givewp_require_cap(string $capability, string $what): ?WP_Error
{
    if (current_user_can($capability)) {
        return null;
    }

    return new WP_Error(
        'nibwp_givewp_forbidden_object',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $capability
        ),
        ['status' => 403, 'capability' => $capability]
    );
}

/* ------------------------------------------------------- tables and plumbing */

/**
 * One of GiveWP's tables, by the name this install actually uses.
 *
 * Never from input. The donation meta table is the interesting one: GiveWP renamed
 * it from give_paymentmeta to give_donationmeta in 2.2 and points
 * $wpdb->donationmeta at whichever one is live
 * (includes/database/class-give-db-payment-meta.php:58-65), so a site that never
 * finished that upgrade still has a table and this still finds it.
 */
function nibwp_givewp_table(string $which): string
{
    global $wpdb;

    $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '';

    if ($which === 'donationmeta') {
        return is_object($wpdb) && isset($wpdb->donationmeta) && is_string($wpdb->donationmeta) && $wpdb->donationmeta !== ''
            ? $wpdb->donationmeta
            : $prefix . 'give_donationmeta';
    }

    if ($which === 'formmeta') {
        return is_object($wpdb) && isset($wpdb->formmeta) && is_string($wpdb->formmeta) && $wpdb->formmeta !== ''
            ? $wpdb->formmeta
            : $prefix . 'give_formmeta';
    }

    if ($which === 'posts') {
        return is_object($wpdb) && isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== ''
            ? $wpdb->posts
            : $prefix . 'posts';
    }

    return $prefix . 'give_' . $which;
}

/**
 * The column the donation meta table keys on.
 *
 * 'donation_id' after the 2.2 rename, 'payment_id' before it — GiveWP keeps the
 * answer in the meta object's own type (includes/database/class-give-db-payment-meta.php:41,63).
 * The result is checked against a two-value list before it can reach SQL: this is
 * an identifier, and an identifier that came from somewhere else cannot be bound
 * by prepare().
 */
function nibwp_givewp_meta_column(): string
{
    $column = 'donation_id';

    if (function_exists('give')) {
        try {
            $give = give();

            if (is_object($give) && isset($give->payment_meta) && is_object($give->payment_meta)
                && method_exists($give->payment_meta, 'get_meta_type')) {
                $column = (string) $give->payment_meta->get_meta_type() . '_id';
            }
        } catch (\Throwable $error) {
            $column = 'donation_id';
        }
    }

    return in_array($column, ['donation_id', 'payment_id'], true) ? $column : 'donation_id';
}

/** Does a table this file wants to read actually exist on this install? */
function nibwp_givewp_table_exists(string $table): bool
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
        return false;
    }

    // SHOW TABLES LIKE takes the name as a VALUE, so it binds. Interpolating it
    // would be the one place in this file where an identifier reached SQL raw.
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return is_string($found) && $found !== '';
}

/**
 * Does this build have GiveWP's recurring donations at all?
 *
 * Core ships the subscriptions tables (src/Subscriptions/Migrations/CreateSubscriptionTables.php:28)
 * but only the Recurring Donations add-on ever writes to them. So the right answer
 * to "are there recurring donations" on a core-only site is "this build does not
 * do recurring", not an empty list that reads like nobody subscribed.
 */
function nibwp_givewp_recurring_supported(): bool
{
    return nibwp_givewp_table_exists(nibwp_givewp_table('subscriptions'));
}

/** Clamp a row count that arrived over the wire. */
function nibwp_givewp_limit(array $input, int $default = 25): int
{
    // per_page is what this hub has always called it; limit is what every other
    // hub calls it. Both are accepted so neither an old caller nor a new one has
    // to care, and either way the clamp is the last word.
    $raw = $input['limit'] ?? ($input['per_page'] ?? null);
    $limit = is_numeric($raw) ? (int) $raw : $default;

    return max(1, min(NIBWP_GIVEWP_MAX_ROWS, $limit));
}

/** Where a listing starts. page is 1-based and predates offset here. */
function nibwp_givewp_offset(array $input, int $limit): int
{
    if (isset($input['offset']) && is_numeric($input['offset'])) {
        return max(0, (int) $input['offset']);
    }

    if (isset($input['page']) && is_numeric($input['page'])) {
        return max(0, ((int) $input['page']) - 1) * $limit;
    }

    return 0;
}

/** A window in days, clamped. */
function nibwp_givewp_days(array $input, int $default = 30): int
{
    $raw = $input['days'] ?? null;
    $days = is_numeric($raw) ? (int) $raw : $default;

    return max(1, min(NIBWP_GIVEWP_MAX_DAYS, $days));
}

/** A positive object id from input, or a refusal naming the parameter. */
function nibwp_givewp_id(array $input, string $key): int|WP_Error
{
    $raw = $input[$key] ?? null;

    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return nibwp_givewp_err(
            'missing_id',
            sprintf(
                /* translators: %s: the name of the missing input parameter */
                __('Give the record to work on as %s.', 'nibwp'),
                $key
            ),
            422
        );
    }

    $id = (int) $raw;

    if ($id <= 0) {
        return nibwp_givewp_err(
            'bad_id',
            sprintf(
                /* translators: 1: parameter name, 2: the rejected value */
                __('%1$s must be a positive id; "%2$s" is not.', 'nibwp'),
                $key,
                (string) $raw
            ),
            422
        );
    }

    return $id;
}

/**
 * Keep a value only if it is scalar.
 *
 * DROPPED rather than cast. Donation meta is a flat key space every add-on writes
 * into, and some of it is serialised — a card record, a gateway response, a whole
 * billing object. `(string) $array` is a notice and a useless "Array" in the
 * response; worse, json_encode()ing it to "be helpful" is how the thing we are
 * trying not to return gets returned.
 */
function nibwp_givewp_scalar(mixed $value): string|int|float|bool|null
{
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    return is_scalar($value) ? $value : null;
}

/** A scalar as a trimmed string, or null. */
function nibwp_givewp_text(mixed $value): ?string
{
    $scalar = nibwp_givewp_scalar($value);

    if ($scalar === null || is_bool($scalar)) {
        return null;
    }

    $text = trim((string) $scalar);

    return $text === '' ? null : $text;
}

/**
 * An amount, with its currency, from GiveWP's major-unit decimal string.
 *
 * The currency travels with the number every time. GiveWP stores a donation's
 * currency per donation (_give_payment_currency), so a site that has ever changed
 * currency, or takes more than one, has donations in several — and a bare number
 * out of this file would be added to another one somewhere downstream.
 */
function nibwp_givewp_money(mixed $raw, mixed $currency): array
{
    $amount = is_numeric($raw) ? (float) $raw : 0.0;

    return [
        'amount' => round($amount, 2),
        'currency' => nibwp_givewp_text($currency),
    ];
}

/** The site's base currency, for labelling a figure GiveWP computed itself. */
function nibwp_givewp_base_currency(): ?string
{
    if (!function_exists('give_get_currency')) {
        return null;
    }

    try {
        return nibwp_givewp_text(give_get_currency());
    } catch (\Throwable $error) {
        return null;
    }
}

/* --------------------------------------------------------------- allow-lists */

/**
 * The donation meta this file will read, and the name each one leaves under.
 *
 * An ALLOW-LIST. Everything not named here stays in the database, including every
 * key a later GiveWP or an add-on invents. The aliases are also the SQL column
 * aliases in the donation query, which is why they are fixed strings from this
 * list and never anything that arrived over the wire.
 *
 * @return array<string, string>
 */
function nibwp_givewp_donation_fields(): array
{
    return [
        NIBWP_GIVEWP_META_AMOUNT => 'amount_raw',
        NIBWP_GIVEWP_META_CURRENCY => 'currency',
        NIBWP_GIVEWP_META_FORM_ID => 'form_id',
        NIBWP_GIVEWP_META_FORM_TITLE => 'form_title',
        NIBWP_GIVEWP_META_GATEWAY => 'gateway',
        NIBWP_GIVEWP_META_DONOR_ID => 'donor_id',
        NIBWP_GIVEWP_META_MODE => 'mode',
        NIBWP_GIVEWP_META_LEVEL_ID => 'level_id',
        NIBWP_GIVEWP_META_CAMPAIGN_ID => 'campaign_id',
        NIBWP_GIVEWP_META_RECURRING => 'recurring_flag',
        NIBWP_GIVEWP_META_SUBSCRIPTION_ID => 'subscription_id',
        NIBWP_GIVEWP_META_ANONYMOUS => 'anonymous_flag',
        NIBWP_GIVEWP_META_FIRST_NAME => 'first_name',
        NIBWP_GIVEWP_META_LAST_NAME => 'last_name',
        NIBWP_GIVEWP_META_EMAIL => 'email',
        NIBWP_GIVEWP_META_COMPANY => 'company',
    ];
}

/**
 * Keys that must never leave, however they are asked for.
 *
 * A second layer rather than the only one — the allow-list above already governs
 * what travels. This list exists so the intent is written down and so a name
 * landing in both fails loudly, which the check asserts. Every one of these is
 * either a credential, a key that unlocks something, or a fact about a person that
 * no report needs.
 *
 * @return array<int, string>
 */
function nibwp_givewp_withheld_fields(): array
{
    return [
        // The gateway's own identifiers. Hand these over and you have handed over
        // the ability to look the charge up at the processor.
        '_give_payment_transaction_id',
        // The purchase key is what the public receipt URL authenticates on.
        '_give_payment_purchase_key',
        // Personal and never needed for a report.
        '_give_payment_donor_ip',
        '_give_payment_donor_phone',
        '_give_donor_billing_address1',
        '_give_donor_billing_address2',
        '_give_donor_billing_city',
        '_give_donor_billing_state',
        '_give_donor_billing_zip',
        '_give_donor_billing_country',
        // The pre-2.0 serialised blob that holds all of the above at once.
        '_give_payment_meta',
        // The donors table's own credentials (includes/database/class-give-db-donors.php:589-591).
        'token',
        'verify_key',
        'verify_throttle',
        // The subscription's gateway handles (src/Subscriptions/Migrations/CreateSubscriptionTables.php:37,44).
        'profile_id',
        // And the site's own gateway credentials, which this file never reads at all.
        'give_settings',
        'stripe_secret_key',
        'live_secret_key',
        'test_secret_key',
        'api_key',
        'api_secret',
    ];
}

/** The donation statuses this file will accept and report (DonationStatus.php:35-45). */
function nibwp_givewp_statuses(): array
{
    return [
        'publish',
        'pending',
        'processing',
        'refunded',
        'failed',
        'cancelled',
        'abandoned',
        'preapproval',
        'revoked',
        'give_subscription',
    ];
}

/** What counts as money actually received. */
function nibwp_givewp_successful_statuses(): array
{
    // A renewal is recorded with the legacy give_subscription status on older data
    // and as publish on newer (DonationStatus.php:51), so both are income.
    return ['publish', 'give_subscription'];
}

/** The subscription statuses (src/Subscriptions/ValueObjects/SubscriptionStatus.php:34-53). */
function nibwp_givewp_subscription_statuses(): array
{
    return [
        'pending',
        'active',
        'expired',
        'completed',
        'failing',
        'cancelled',
        'suspended',
        'paused',
        'trashed',
        'refunded',
        'abandoned',
    ];
}

/** A donation status from input, validated against the list. */
function nibwp_givewp_status(array $input, string $key = 'status'): ?string
{
    $raw = nibwp_givewp_text($input[$key] ?? null);

    if ($raw === null) {
        return null;
    }

    // "complete" is what a person says and what the old instructions told callers
    // to send; publish is what the post status is.
    $raw = strtolower($raw);

    if ($raw === 'complete' || $raw === 'completed') {
        return 'publish';
    }

    return in_array($raw, nibwp_givewp_statuses(), true) ? $raw : '';
}

/* ------------------------------------------------------------- anonymity core */

/**
 * Is this donation one the donor asked to be anonymous?
 *
 * GiveWP writes '1' into `_give_anonymous_donation` and compares against the
 * string (src/API/REST/V3/Routes/Campaigns/CampaignCommentsController.php:125),
 * so '1' is the case that matters. Everything else is judged on what it would cost
 * to be wrong, and this FAILS CLOSED: an empty value and a literal zero are the
 * only things read as "not anonymous". A value this integration has never seen —
 * 'yes', 'true', a serialised array from an add-on that stored it differently — is
 * treated as anonymous. Withholding a name that could have been shown costs a
 * follow-up question. Showing one that should have been withheld is the failure
 * the owner has to apologise to a donor for.
 */
function nibwp_givewp_is_anonymous(mixed $raw): bool
{
    if ($raw === null) {
        return false;
    }

    if (!is_scalar($raw)) {
        return true;
    }

    if (is_bool($raw)) {
        return $raw;
    }

    $value = strtolower(trim((string) $raw));

    return !in_array($value, ['', '0', 'no', 'false'], true);
}

/**
 * One donation, as it is allowed to leave.
 *
 * EVERY donation read in this file goes through here, which is the point: there is
 * exactly one place where a donor's name can be attached to a donation, so there
 * is exactly one place to get it wrong and exactly one place to check.
 *
 * The identity keys are always present and always null when the donation is
 * anonymous, rather than being omitted. A stable shape means a caller reading
 * `donor_name` does not get a missing-key warning and go looking for the name
 * somewhere else; and a response that silently changes shape per row is one a
 * reader stops trusting.
 *
 * donor_id survives on an anonymous row. It is not a name or an email, and the
 * donor-level rule below means the id cannot be traded for one: a donor whose
 * donations are all anonymous is not named by any action here either.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_givewp_donation_row(array $row): array
{
    $anonymous = nibwp_givewp_is_anonymous($row['anonymous_flag'] ?? null);

    $first = nibwp_givewp_text($row['first_name'] ?? null);
    $last = nibwp_givewp_text($row['last_name'] ?? null);
    $name = trim(((string) $first) . ' ' . ((string) $last));

    $out = [
        'id' => (int) ($row['id'] ?? 0),
        'status' => nibwp_givewp_text($row['status'] ?? null),
        'date' => nibwp_givewp_text($row['date_gmt'] ?? null),
        'form_id' => (int) nibwp_givewp_scalar($row['form_id'] ?? 0),
        'form_title' => nibwp_givewp_text($row['form_title'] ?? null),
        'amount' => (string) (nibwp_givewp_scalar($row['amount_raw'] ?? null) ?? ''),
        'money' => nibwp_givewp_money($row['amount_raw'] ?? null, $row['currency'] ?? null),
        'currency' => nibwp_givewp_text($row['currency'] ?? null),
        'gateway' => nibwp_givewp_text($row['gateway'] ?? null),
        'mode' => nibwp_givewp_text($row['mode'] ?? null),
        'level_id' => nibwp_givewp_text($row['level_id'] ?? null),
        'campaign_id' => nibwp_givewp_text($row['campaign_id'] ?? null) === null
            ? null
            : (int) nibwp_givewp_scalar($row['campaign_id']),
        'donor_id' => (int) nibwp_givewp_scalar($row['donor_id'] ?? 0),
        'recurring' => nibwp_givewp_is_recurring($row),
        'subscription_id' => nibwp_givewp_text($row['subscription_id'] ?? null) === null
            ? null
            : (int) nibwp_givewp_scalar($row['subscription_id']),
        'anonymous' => $anonymous,
        // The legacy keys, kept because callers already read them.
        'donor_name' => null,
        'donor_email' => null,
        'first_name' => null,
        'last_name' => null,
        'company' => null,
    ];

    if (!$anonymous) {
        $out['donor_name'] = $name === '' ? null : $name;
        $out['donor_email'] = nibwp_givewp_text($row['email'] ?? null);
        $out['first_name'] = $first;
        $out['last_name'] = $last;
        $out['company'] = nibwp_givewp_text($row['company'] ?? null);
    }

    return $out;
}

/**
 * Is this donation part of a recurring arrangement?
 *
 * Two signals, because they disagree on real data: `_give_is_donation_recurring`
 * is written by the add-on on the first donation of a subscription, and
 * `subscription_id` is written on every renewal. A donation with either is
 * recurring; a donation with neither is a one-off.
 *
 * @param array<string, mixed> $row
 */
function nibwp_givewp_is_recurring(array $row): bool
{
    $flag = nibwp_givewp_text($row['recurring_flag'] ?? null);
    $subscription = nibwp_givewp_text($row['subscription_id'] ?? null);

    if ($flag !== null && $flag !== '0') {
        return true;
    }

    if ($subscription !== null && $subscription !== '0') {
        return true;
    }

    return (string) ($row['status'] ?? '') === 'give_subscription';
}

/**
 * Which of these donors may be named?
 *
 * The donor record itself has no anonymity flag — anonymity is per donation. So a
 * donor is nameable here only if they have at least one donation that is NOT
 * anonymous. A donor who has only ever given anonymously is named nowhere in this
 * file, including in aggregates that merely happen to carry a name: a top-donor
 * table that lists "A. Donor — 12,000" has deanonymised them just as surely as a
 * donation row would have.
 *
 * One query for the whole page of donors rather than one per donor, because a
 * listing of 200 donors is not worth 200 round trips.
 *
 * @param array<int, int> $donor_ids
 * @return array<int, bool> donor id => may be named
 */
function nibwp_givewp_nameable_donors(array $donor_ids): array
{
    $ids = [];

    foreach ($donor_ids as $id) {
        $id = (int) $id;

        if ($id > 0) {
            $ids[$id] = false;
        }
    }

    if ($ids === []) {
        return [];
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        // No way to ask, so nobody is nameable. Failing closed again: the
        // alternative is naming every donor on a site whose database handle is
        // missing, which is exactly when nobody is watching.
        return $ids;
    }

    $meta = nibwp_givewp_table('donationmeta');
    $column = nibwp_givewp_meta_column();
    $keys = array_keys($ids);
    $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

    $sql = $wpdb->prepare(
        'SELECT d.meta_value AS donor_id FROM ' . $meta . ' d'
        . ' LEFT JOIN ' . $meta . ' a ON a.' . $column . ' = d.' . $column . ' AND a.meta_key = %s'
        . ' WHERE d.meta_key = %s AND d.meta_value IN (' . $placeholders . ')'
        . ' AND (a.meta_value IS NULL OR a.meta_value = %s OR a.meta_value = %s)'
        . ' GROUP BY d.meta_value',
        array_merge(
            [NIBWP_GIVEWP_META_ANONYMOUS, NIBWP_GIVEWP_META_DONOR_ID],
            array_map(static fn (int $id): string => (string) $id, $keys),
            ['0', '']
        )
    );

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $found) {
        $id = (int) ($found['donor_id'] ?? 0);

        if (isset($ids[$id])) {
            $ids[$id] = true;
        }
    }

    return $ids;
}

/**
 * One donor, as they are allowed to leave.
 *
 * The donors table carries a login token and a verification key
 * (includes/database/class-give-db-donors.php:589-591). Neither is in the
 * allow-list below, and neither ever will be.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_givewp_donor_row(array $row, bool $nameable): array
{
    $out = [
        'id' => (int) ($row['id'] ?? 0),
        'donation_count' => (int) ($row['purchase_count'] ?? 0),
        'lifetime_total' => nibwp_givewp_money($row['purchase_value'] ?? 0, nibwp_givewp_base_currency()),
        'first_donation' => nibwp_givewp_text($row['date_created'] ?? null),
        'wordpress_user_id' => (int) ($row['user_id'] ?? 0) > 0 ? (int) $row['user_id'] : null,
        'anonymous' => !$nameable,
        'name' => null,
        'email' => null,
    ];

    if ($nameable) {
        $out['name'] = nibwp_givewp_text($row['name'] ?? null);
        $out['email'] = nibwp_givewp_text($row['email'] ?? null);
    }

    return $out;
}

/* ------------------------------------------------------------ donation reads */

/**
 * The donation query every donation listing in this file is built on.
 *
 * One function, because the anonymity rule and the allow-list both live in the row
 * builder and a second query would be a second chance to forget them.
 *
 * @param array<string, mixed> $args
 * @return array<int, array<string, mixed>>|WP_Error
 */
function nibwp_givewp_fetch_donations(array $args): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $posts = nibwp_givewp_table('posts');
    $meta = nibwp_givewp_table('donationmeta');
    $column = nibwp_givewp_meta_column();

    // The SELECT placeholders are bound before the WHERE ones, so they are
    // collected separately and merged in that order. Getting this backwards binds
    // a meta key into a date comparison and returns nothing, quietly.
    $select_values = [];
    $select = 'p.ID AS id, p.post_status AS status, p.post_date_gmt AS date_gmt';

    foreach (nibwp_givewp_donation_fields() as $key => $alias) {
        $select .= ', MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS ' . $alias;
        $select_values[] = $key;
    }

    $where = ['p.post_type = %s'];
    $where_values = ['give_payment'];

    $statuses = $args['statuses'] ?? nibwp_givewp_statuses();
    $statuses = array_values(array_intersect((array) $statuses, nibwp_givewp_statuses()));

    if ($statuses === []) {
        return [];
    }

    $where[] = 'p.post_status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')';

    foreach ($statuses as $status) {
        $where_values[] = $status;
    }

    if (!empty($args['from'])) {
        $where[] = 'p.post_date_gmt >= %s';
        $where_values[] = (string) $args['from'];
    }

    if (!empty($args['to'])) {
        $where[] = 'p.post_date_gmt <= %s';
        $where_values[] = (string) $args['to'];
    }

    foreach ([
        'form_id' => NIBWP_GIVEWP_META_FORM_ID,
        'donor_id' => NIBWP_GIVEWP_META_DONOR_ID,
    ] as $arg => $key) {
        if (!empty($args[$arg])) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . $meta . ' x WHERE x.' . $column . ' = p.ID AND x.meta_key = %s AND x.meta_value = %s)';
            $where_values[] = $key;
            $where_values[] = (string) (int) $args[$arg];
        }
    }

    $limit = max(1, min(NIBWP_GIVEWP_MAX_ROWS, (int) ($args['limit'] ?? 25)));
    $offset = max(0, (int) ($args['offset'] ?? 0));

    $sql = $wpdb->prepare(
        'SELECT ' . $select
        . ' FROM ' . $posts . ' p LEFT JOIN ' . $meta . ' m ON m.' . $column . ' = p.ID'
        . ' WHERE ' . implode(' AND ', $where)
        . ' GROUP BY p.ID ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d OFFSET %d',
        array_merge($select_values, $where_values, [$limit, $offset])
    );

    $rows = [];

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $row = (array) $row;
        $donation = nibwp_givewp_donation_row($row);

        // The recurring filter is applied after the row is built because the answer
        // depends on two meta keys and a post status, and expressing that in SQL
        // would be a third place the rule lives.
        if (isset($args['recurring']) && $args['recurring'] !== null
            && $donation['recurring'] !== (bool) $args['recurring']) {
            continue;
        }

        $rows[] = $donation;
    }

    return $rows;
}

/**
 * Counts and totals over a window, grouped by currency.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_tally(array $args): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $posts = nibwp_givewp_table('posts');
    $meta = nibwp_givewp_table('donationmeta');
    $column = nibwp_givewp_meta_column();

    $statuses = array_values(array_intersect(
        (array) ($args['statuses'] ?? nibwp_givewp_successful_statuses()),
        nibwp_givewp_statuses()
    ));

    if ($statuses === []) {
        return ['donations' => 0, 'by_currency' => [], 'donors' => 0];
    }

    $values = [NIBWP_GIVEWP_META_AMOUNT, NIBWP_GIVEWP_META_CURRENCY, NIBWP_GIVEWP_META_DONOR_ID, 'give_payment'];
    $where = ['p.post_type = %s'];

    $where[] = 'p.post_status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')';

    foreach ($statuses as $status) {
        $values[] = $status;
    }

    if (!empty($args['from'])) {
        $where[] = 'p.post_date_gmt >= %s';
        $values[] = (string) $args['from'];
    }

    if (!empty($args['to'])) {
        $where[] = 'p.post_date_gmt <= %s';
        $values[] = (string) $args['to'];
    }

    if (!empty($args['form_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM ' . $meta . ' x WHERE x.' . $column . ' = p.ID AND x.meta_key = %s AND x.meta_value = %s)';
        $values[] = NIBWP_GIVEWP_META_FORM_ID;
        $values[] = (string) (int) $args['form_id'];
    }

    // SUM(meta_value + 0) rather than CAST: GiveWP normalises the amount to a
    // decimal-point string before it is stored (give_sanitize_amount), so + 0 is
    // exact for the values it writes and cheap enough to index around.
    $sql = $wpdb->prepare(
        'SELECT c.meta_value AS currency, COUNT(DISTINCT p.ID) AS donations,'
        . ' SUM(a.meta_value + 0) AS total, COUNT(DISTINCT d.meta_value) AS donors'
        . ' FROM ' . $posts . ' p'
        . ' INNER JOIN ' . $meta . ' a ON a.' . $column . ' = p.ID AND a.meta_key = %s'
        . ' LEFT JOIN ' . $meta . ' c ON c.' . $column . ' = p.ID AND c.meta_key = %s'
        . ' LEFT JOIN ' . $meta . ' d ON d.' . $column . ' = p.ID AND d.meta_key = %s'
        . ' WHERE ' . implode(' AND ', $where)
        . ' GROUP BY c.meta_value LIMIT %d',
        array_merge($values, [NIBWP_GIVEWP_MAX_ROWS])
    );

    $by_currency = [];
    $donations = 0;
    $donors = 0;

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $count = (int) ($row['donations'] ?? 0);
        $donations += $count;
        $donors = max($donors, (int) ($row['donors'] ?? 0));

        $by_currency[] = [
            'currency' => nibwp_givewp_text($row['currency'] ?? null),
            'donations' => $count,
            'total' => nibwp_givewp_money($row['total'] ?? 0, $row['currency'] ?? null),
            'average' => nibwp_givewp_money(
                $count > 0 ? ((float) ($row['total'] ?? 0)) / $count : 0,
                $row['currency'] ?? null
            ),
        ];
    }

    return [
        'donations' => $donations,
        'donors' => $donors,
        'by_currency' => $by_currency,
        'currencies_combined' => false,
    ];
}

/** A window as GMT datetime strings, from days or explicit dates. */
function nibwp_givewp_window(array $input): array
{
    $from = nibwp_givewp_text($input['from'] ?? null);
    $to = nibwp_givewp_text($input['to'] ?? null);

    // A date that is not a date would silently become 1970 through strtotime(), so
    // it is only accepted in the shape GiveWP's own tables use.
    $valid = static function (?string $value): ?string {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ' 00:00:00';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    };

    $from_ok = $valid($from);
    $to_ok = $valid($to);

    if ($from_ok !== null || $to_ok !== null) {
        return [
            'from' => $from_ok,
            'to' => $to_ok,
            'days' => null,
            'rejected' => array_values(array_filter([
                $from !== null && $from_ok === null ? 'from' : null,
                $to !== null && $to_ok === null ? 'to' : null,
            ])),
        ];
    }

    $days = nibwp_givewp_days($input);

    return [
        'from' => gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS)),
        'to' => gmdate('Y-m-d H:i:s'),
        'days' => $days,
        'rejected' => array_values(array_filter([
            $from !== null && $from_ok === null ? 'from' : null,
            $to !== null && $to_ok === null ? 'to' : null,
        ])),
    ];
}

/* ------------------------------------------------------------------ the forms */

/** One form's meta, through GiveWP's own accessor where it exists. */
function nibwp_givewp_form_meta(int $form_id, string $key): mixed
{
    try {
        if (function_exists('give_get_meta')) {
            return give_get_meta($form_id, $key, true);
        }

        // GiveWP filters get_post_metadata so form meta resolves out of
        // give_formmeta transparently (includes/database/class-give-db-meta.php:97),
        // which is why plain get_post_meta() is the right fallback and not a bug.
        return function_exists('get_post_meta') ? get_post_meta($form_id, $key, true) : null;
    } catch (\Throwable $error) {
        return null;
    }
}

/** Is a form's goal switched on? */
function nibwp_givewp_goal_enabled(int $form_id): bool
{
    $option = nibwp_givewp_text(nibwp_givewp_form_meta($form_id, NIBWP_GIVEWP_FORM_GOAL_OPTION));

    return $option !== null && strtolower($option) === 'enabled';
}

/**
 * One form's goal, what it has raised, and how far along it is.
 *
 * GiveWP's own give_goal_progress_stats() is preferred where it exists: the
 * percentage depends on the goal FORMAT, and a goal counted in donors or in
 * donations is not a money comparison at all
 * (includes/misc-functions.php:2035-2065). Recomputing that here would drift from
 * what the owner sees on the form.
 *
 * @return array<string, mixed>
 */
function nibwp_givewp_goal(int $form_id): array
{
    $enabled = nibwp_givewp_goal_enabled($form_id);
    $target = nibwp_givewp_scalar(nibwp_givewp_form_meta($form_id, NIBWP_GIVEWP_FORM_GOAL_AMOUNT));
    $format = nibwp_givewp_text(nibwp_givewp_form_meta($form_id, NIBWP_GIVEWP_FORM_GOAL_FORMAT)) ?? 'amount';
    $earnings = nibwp_givewp_scalar(nibwp_givewp_form_meta($form_id, NIBWP_GIVEWP_FORM_EARNINGS));
    $sales = (int) nibwp_givewp_scalar(nibwp_givewp_form_meta($form_id, NIBWP_GIVEWP_FORM_SALES));
    $currency = nibwp_givewp_base_currency();

    $progress = null;

    if (function_exists('give_goal_progress_stats')) {
        try {
            $stats = give_goal_progress_stats($form_id);

            if (is_array($stats)) {
                $progress = is_numeric($stats['progress'] ?? null) ? round((float) $stats['progress'], 2) : null;
                $target = $stats['raw_goal'] ?? $target;
                $earnings = $stats['raw_actual'] ?? $earnings;
                $format = nibwp_givewp_text($stats['format'] ?? null) ?? $format;
            }
        } catch (\Throwable $error) {
            $progress = null;
        }
    }

    if ($progress === null && is_numeric($target) && (float) $target > 0) {
        $progress = round(((float) $earnings / (float) $target) * 100, 2);
    }

    return [
        'enabled' => $enabled,
        'format' => $format,
        // Only a goal counted in money is money. A goal of "500 donors" reported
        // with a currency beside it is a number somebody will put in a budget.
        'target' => $format === 'donation' || $format === 'donors'
            ? ['count' => (int) (is_numeric($target) ? $target : 0)]
            : nibwp_givewp_money($target, $currency),
        'raised' => $format === 'donation' || $format === 'donors'
            ? ['count' => (int) (is_numeric($earnings) ? $earnings : 0)]
            : nibwp_givewp_money($earnings, $currency),
        'donation_count' => $sales,
        'progress_percent' => $progress,
        'met' => $progress !== null && $progress >= 100,
        'closes_when_met' => strtolower((string) nibwp_givewp_text(
            nibwp_givewp_form_meta($form_id, NIBWP_GIVEWP_FORM_GOAL_CLOSE)
        )) === 'enabled',
    ];
}

/**
 * The donation forms, bounded.
 *
 * @param array<string, mixed> $input
 * @return array<int, WP_Post>|WP_Error
 */
function nibwp_givewp_form_posts(array $input, int $limit, int $offset): array|WP_Error
{
    if (!function_exists('get_posts')) {
        return nibwp_givewp_err('no_posts_api', __('WordPress is not loaded far enough to read the donation forms.', 'nibwp'), 500);
    }

    $status = nibwp_givewp_text($input['form_status'] ?? null);
    $allowed = ['publish', 'draft', 'pending', 'private', 'future', 'trash', 'any'];

    if ($status !== null && !in_array($status, $allowed, true)) {
        return nibwp_givewp_err(
            'bad_form_status',
            sprintf(
                /* translators: 1: the rejected value, 2: list of valid statuses */
                __('form_status "%1$s" is not one GiveWP uses. Valid: %2$s.', 'nibwp'),
                $status,
                implode(', ', $allowed)
            ),
            422
        );
    }

    return (array) get_posts([
        'post_type' => 'give_forms',
        'post_status' => $status ?? 'publish',
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
    ]);
}

/**
 * Does this id name a post of the type we think it does?
 *
 * The return type is `object` and not `object|WP_Error`: that union names both
 * object and a class, which PHP refuses to compile, and php -l is the only thing
 * that would ever tell you. A WP_Error is an object, so the caller checks with
 * instanceof like everywhere else.
 */
function nibwp_givewp_require_post(int $id, string $type, string $noun): object
{
    if (!function_exists('get_post')) {
        return nibwp_givewp_err('no_posts_api', __('WordPress is not loaded far enough to read that record.', 'nibwp'), 500);
    }

    $post = get_post($id);

    if (!is_object($post) || (string) ($post->post_type ?? '') !== $type) {
        return nibwp_givewp_err(
            'not_found',
            sprintf(
                /* translators: 1: the noun for the record, 2: the id that was asked for */
                __('No %1$s with id %2$d. The id may belong to another kind of post, which this will not read.', 'nibwp'),
                $noun,
                $id
            ),
            404
        );
    }

    return $post;
}

/* ------------------------------------------------------------ registration */

/**
 * The actions this hub offers.
 *
 * The first five are the names this hub shipped with. They stay, with their
 * response keys, because customers already call them by name.
 *
 * @return array<int, string>
 */
function nibwp_givewp_actions(): array
{
    return [
        // Forms.
        'list_forms',
        'get_form',
        'create_form',
        'update_form_goal',
        'set_form_status',
        'form_goal_progress',
        // Donations.
        'list_donations',
        'get_donation',
        'count_donations',
        'donation_totals',
        'list_recurring_donations',
        'recurring_summary',
        // Donors.
        'list_donors',
        'get_donor',
        'donor_donations',
        'top_donors',
        // Reporting.
        'get_stats',
        'revenue_summary',
        'revenue_by_form',
        'average_gift',
        'goal_progress_all',
        // Audit.
        'audit_summary',
        'audit_forms_without_goal',
        'audit_forms_never_funded',
        'audit_goals_met_still_open',
        'audit_failing_recurring',
    ];
}

wp_register_ability('nibwp/givewp-manage', [
    'label' => __('GiveWP – Donations, Donors and Goals', 'nibwp'),
    'description' => 'Read and report on GiveWP fundraising: donation forms with their levels and goals, donations searched by date, form, donor or status, '
        . 'donors with their giving history and lifetime total, revenue for a period against the period before it, average gift, goal progress per form, '
        . 'and an audit that finds forms published with no goal, forms that have never received a donation, goals already met but still soliciting, '
        . 'and recurring donations that failed their last attempt. '
        . 'Three write operations and no others: create a donation form as a draft, change a form\'s goal, change a form\'s status. '
        . 'Nothing here refunds a donation, deletes a donation, or deletes or merges a donor — those stay in wp-admin. '
        . 'A donation the donor marked anonymous never returns their name or email, in any action. '
        . 'No gateway transaction id, receipt key, card detail or API credential is ever returned.',
    'category' => 'donations',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_givewp_actions(),
                'description' => 'Which operation to run. Start with get_stats or audit_summary.',
            ],
            'form_id' => ['type' => 'integer', 'description' => 'A donation form id.'],
            'donation_id' => ['type' => 'integer', 'description' => 'A donation id.'],
            'donor_id' => ['type' => 'integer', 'description' => 'A donor id, from the give_donors table.'],
            'status' => ['type' => 'string', 'description' => 'Filter donations by status: complete (publish), pending, processing, refunded, failed, cancelled, abandoned, revoked, preapproval.'],
            'form_status' => ['type' => 'string', 'description' => 'For list_forms: publish, draft, pending, private, future, trash or any. Default publish.'],
            'title' => ['type' => 'string', 'description' => 'For create_form: the form title.'],
            'goal_amount' => ['type' => 'number', 'description' => 'For create_form / update_form_goal: the goal target.'],
            'goal_enabled' => ['type' => 'boolean', 'description' => 'For update_form_goal: whether the goal is shown at all.'],
            'goal_format' => ['type' => 'string', 'description' => 'For update_form_goal: amount, percentage, donation or donors.'],
            'new_status' => ['type' => 'string', 'description' => 'For set_form_status: publish, draft, pending or private.'],
            'recurring' => ['type' => 'boolean', 'description' => 'For list_donations: true for recurring only, false for one-off only.'],
            'days' => ['type' => 'integer', 'description' => 'Window in days. Default 30, max 1825.'],
            'from' => ['type' => 'string', 'description' => 'Window start, YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, GMT. Overrides days.'],
            'to' => ['type' => 'string', 'description' => 'Window end, same shape as from.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 25, max 200.'],
            'per_page' => ['type' => 'integer', 'description' => 'What limit used to be called here. Still works.'],
            'page' => ['type' => 'integer', 'description' => '1-based page, as an alternative to offset.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
            'confirm' => ['type' => 'boolean', 'description' => 'For set_form_status: required to take a published form offline.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
            'forms' => ['type' => 'array'],
            'form' => ['type' => 'object'],
            'donations' => ['type' => 'array'],
            'donation' => ['type' => 'object'],
            'donors' => ['type' => 'array'],
            'donor' => ['type' => 'object'],
            'stats' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_givewp_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'GiveWP fundraising, read-mostly on purpose.',
                '',
                'START WITH get_stats for the headline numbers, or audit_summary for the few',
                'things an owner should act on: forms published with no goal, forms that have',
                'never received a donation, a goal already met on a form still asking for money,',
                'and recurring donations that failed their last attempt.',
                '',
                'ANONYMOUS DONATIONS. GiveWP lets a donor hide who they are. Those donations come',
                'back with anonymous:true and with donor_name, donor_email, first_name, last_name',
                'and company all null, in every action including the aggregates. A donor who has',
                'only ever given anonymously is not named by list_donors, get_donor or top_donors',
                'either. This is not a gap to work around: do not try to match an anonymous',
                'donation to a name through donor_id, a date or an amount, and say plainly that',
                'the donor asked not to be named.',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'It cannot refund a donation, delete a donation, delete or merge a donor, export',
                'donor data, or read the site\'s payment-gateway settings. Do not look for another',
                'action that does; there is not one. A refund moves real money and cannot be',
                'undone from here, and deleting a donor destroys records an organisation has to',
                'keep. Saying so is the correct answer when asked.',
                '',
                'THE THREE WRITES:',
                '- create_form makes a donation form as a DRAFT, never published. The owner',
                '  publishes it once they have looked at it.',
                '- update_form_goal changes a goal target, format, or whether it shows.',
                '- set_form_status changes a form\'s status. Taking a PUBLISHED form offline',
                '  needs confirm:true, because a draft form takes no donations and nothing',
                '  announces that it stopped.',
                '',
                'MONEY IS NOT ONE NUMBER. Every amount comes back as {amount, currency}, and',
                'totals are grouped BY currency under by_currency. A site taking more than one',
                'currency has no single total, and nothing here converts between them. Report',
                'each currency separately rather than adding them.',
                '',
                'SECRETS: no gateway transaction id, receipt/purchase key, card detail, donor IP,',
                'billing address, subscription profile id or API credential is ever returned.',
                'Asking differently will not change that.',
                '',
                'RECURRING: only sites running GiveWP\'s recurring donations have subscriptions.',
                'Where they do not, recurring_summary and audit_failing_recurring answer with',
                'supported:false rather than an empty list that reads like nobody subscribed.',
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
function nibwp_givewp_manage(array $input): array|WP_Error
{
    $guard = nibwp_givewp_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_givewp_actions(), true)) {
        return nibwp_givewp_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown GiveWP action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_givewp_actions())
            )
        );
    }

    // GiveWP's internals are version-sensitive, its container throws when a
    // service is not registered, and its own money objects throw on a malformed
    // amount. One catch here turns any of that into a named refusal rather than a
    // fatal that takes the whole REST request down.
    try {
        return nibwp_givewp_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_givewp_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from GiveWP */
                __('GiveWP could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_givewp_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'list_forms' => nibwp_givewp_list_forms($input),
        'get_form' => nibwp_givewp_get_form($input),
        'create_form' => nibwp_givewp_create_form($input),
        'update_form_goal' => nibwp_givewp_update_form_goal($input),
        'set_form_status' => nibwp_givewp_set_form_status($input),
        'form_goal_progress' => nibwp_givewp_form_goal_progress($input),
        'list_donations' => nibwp_givewp_list_donations($input),
        'get_donation' => nibwp_givewp_get_donation($input),
        'count_donations' => nibwp_givewp_count_donations($input),
        'donation_totals' => nibwp_givewp_donation_totals($input),
        'list_recurring_donations' => nibwp_givewp_list_recurring_donations($input),
        'recurring_summary' => nibwp_givewp_recurring_summary($input),
        'list_donors' => nibwp_givewp_list_donors($input),
        'get_donor' => nibwp_givewp_get_donor($input),
        'donor_donations' => nibwp_givewp_donor_donations($input),
        'top_donors' => nibwp_givewp_top_donors($input),
        'get_stats' => nibwp_givewp_get_stats($input),
        'revenue_summary' => nibwp_givewp_revenue_summary($input),
        'revenue_by_form' => nibwp_givewp_revenue_by_form($input),
        'average_gift' => nibwp_givewp_average_gift($input),
        'goal_progress_all' => nibwp_givewp_goal_progress_all($input),
        'audit_summary' => nibwp_givewp_audit_summary($input),
        'audit_forms_without_goal' => nibwp_givewp_audit_forms_without_goal($input),
        'audit_forms_never_funded' => nibwp_givewp_audit_forms_never_funded($input),
        'audit_goals_met_still_open' => nibwp_givewp_audit_goals_met_still_open($input),
        'audit_failing_recurring' => nibwp_givewp_audit_failing_recurring($input),
        default => nibwp_givewp_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------- forms: reads */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_list_forms(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Listing the GiveWP donation forms', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_givewp_limit($input);
    $offset = nibwp_givewp_offset($input, $limit);
    $posts = nibwp_givewp_form_posts($input, $limit, $offset);

    if ($posts instanceof WP_Error) {
        return $posts;
    }

    $forms = [];

    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }

        $id = (int) ($post->ID ?? 0);
        $goal = nibwp_givewp_goal($id);

        $forms[] = [
            'id' => $id,
            'title' => nibwp_givewp_text($post->post_title ?? null),
            'status' => nibwp_givewp_text($post->post_status ?? null),
            'date' => nibwp_givewp_text($post->post_date ?? null),
            // The keys this hub has always returned, with the right meta behind
            // them: the old reader took the goal AMOUNT out of _give_set_goal's
            // enable flag and reported every goal as empty.
            'goal_enabled' => $goal['enabled'],
            'goal_amount' => $goal['enabled'] ? ($goal['target']['amount'] ?? $goal['target']['count'] ?? null) : null,
            'total_earned' => $goal['raised']['amount'] ?? $goal['raised']['count'] ?? 0,
            'donation_count' => $goal['donation_count'],
            'goal' => $goal,
        ];
    }

    return [
        'forms' => $forms,
        'total' => count($forms),
        'returned' => count($forms),
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_get_form(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading a GiveWP donation form', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'form_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $post = nibwp_givewp_require_post($id, 'give_forms', __('donation form', 'nibwp'));
    if ($post instanceof WP_Error) {
        return $post;
    }

    $levels = nibwp_givewp_form_meta($id, NIBWP_GIVEWP_FORM_LEVELS);
    $clean = [];

    foreach ((is_array($levels) ? $levels : []) as $level) {
        $level = (array) $level;

        // The level rows are GiveWP's own repeater output and carry an internal
        // _give_id structure beside the numbers. Only the three fields that mean
        // something to a reader travel.
        $clean[] = [
            'amount' => nibwp_givewp_text($level['_give_amount'] ?? null),
            'label' => nibwp_givewp_text($level['_give_text'] ?? null),
            'default' => nibwp_givewp_text($level['_give_default'] ?? null) === 'default',
        ];
    }

    $goal = nibwp_givewp_goal($id);

    return [
        'form' => [
            'id' => $id,
            'title' => nibwp_givewp_text($post->post_title ?? null),
            'content' => nibwp_givewp_text($post->post_content ?? null),
            'status' => nibwp_givewp_text($post->post_status ?? null),
            'date' => nibwp_givewp_text($post->post_date ?? null),
            'goal_enabled' => $goal['enabled'],
            'goal_amount' => $goal['enabled'] ? ($goal['target']['amount'] ?? $goal['target']['count'] ?? null) : null,
            'price_option' => nibwp_givewp_text(nibwp_givewp_form_meta($id, NIBWP_GIVEWP_FORM_PRICE_OPTION)),
            'set_price' => nibwp_givewp_text(nibwp_givewp_form_meta($id, NIBWP_GIVEWP_FORM_SET_PRICE)),
            'donation_levels' => $clean,
            'total_earned' => $goal['raised']['amount'] ?? $goal['raised']['count'] ?? 0,
            'donation_count' => $goal['donation_count'],
            'goal' => $goal,
        ],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_form_goal_progress(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading a GiveWP goal', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'form_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $post = nibwp_givewp_require_post($id, 'give_forms', __('donation form', 'nibwp'));
    if ($post instanceof WP_Error) {
        return $post;
    }

    $goal = nibwp_givewp_goal($id);

    return [
        'form_id' => $id,
        'form_title' => nibwp_givewp_text($post->post_title ?? null),
        'goal' => $goal,
        'note' => $goal['enabled']
            ? null
            : __('This form has no goal switched on, so the progress figures are what it has raised rather than progress towards anything.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_goal_progress_all(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP goals', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_givewp_limit($input, 50);
    $offset = nibwp_givewp_offset($input, $limit);
    $posts = nibwp_givewp_form_posts(['form_status' => 'publish'], $limit, $offset);

    if ($posts instanceof WP_Error) {
        return $posts;
    }

    $rows = [];

    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }

        $id = (int) ($post->ID ?? 0);
        $goal = nibwp_givewp_goal($id);

        if (!$goal['enabled']) {
            continue;
        }

        $rows[] = [
            'form_id' => $id,
            'form_title' => nibwp_givewp_text($post->post_title ?? null),
            'goal' => $goal,
        ];
    }

    usort($rows, static fn (array $a, array $b): int => ($b['goal']['progress_percent'] ?? 0) <=> ($a['goal']['progress_percent'] ?? 0));

    return [
        'returned' => count($rows),
        'goals' => $rows,
        'limit' => $limit,
        'note' => __('Published forms with a goal switched on, most complete first. Forms without a goal are in audit_forms_without_goal.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------- forms: writes */

/**
 * Create a donation form, as a draft.
 *
 * Through GiveWP's own model, never by writing meta directly. A GiveWP 3+ form is
 * a block document in give_formmeta (src/DonationForms/Repositories/DonationFormRepository.php:116-123),
 * and a form assembled out of the pre-3.0 meta keys saves without complaint and
 * then refuses to render — which looks like a plugin bug and is actually us. If
 * the model is not there, this refuses instead of writing something broken.
 *
 * Always a draft. Publishing a form that takes card payments is a decision with a
 * person's money in it, and the person should be looking at the form when they
 * make it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_create_form(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Creating a GiveWP donation form', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_givewp_require_cap(NIBWP_GIVEWP_CAP_FORMS, __('Creating a GiveWP donation form', 'nibwp'))) {
        return $denied;
    }

    $title = nibwp_givewp_text($input['title'] ?? null);

    if ($title === null) {
        return nibwp_givewp_err('missing_title', __('Give the form a title as title.', 'nibwp'), 422);
    }

    $amount = $input['goal_amount'] ?? null;

    if ($amount !== null && (!is_numeric($amount) || (float) $amount < 0)) {
        return nibwp_givewp_err('bad_amount', __('goal_amount must be a number of zero or more.', 'nibwp'), 422);
    }

    $model = 'Give\\DonationForms\\Models\\DonationForm';
    $settings = 'Give\\DonationForms\\Properties\\FormSettings';
    $blocks = 'Give\\Framework\\Blocks\\BlockCollection';

    if (!class_exists($model) || !class_exists($settings) || !class_exists($blocks)
        || !method_exists($model, 'create') || !method_exists($settings, 'fromArray')
        || !method_exists($blocks, 'fromJson')) {
        return nibwp_givewp_err(
            'no_form_api',
            __('This version of GiveWP does not expose its form model where this integration can use it. Create the form in the Form Builder instead — writing one out of raw meta here would save a form that cannot render.', 'nibwp'),
            500
        );
    }

    $blueprint = defined('GIVE_PLUGIN_DIR')
        ? ((string) constant('GIVE_PLUGIN_DIR')) . 'src/FormBuilder/resources/js/form-builder/src/blocks.json'
        : '';

    if ($blueprint === '' || !is_readable($blueprint)) {
        return nibwp_givewp_err(
            'no_form_blueprint',
            __('GiveWP\'s default form layout is not readable on this install, and a form with no fields is worse than no form. Create it in the Form Builder.', 'nibwp'),
            500
        );
    }

    $json = (string) file_get_contents($blueprint);

    $form = $model::create([
        'title' => $title,
        'status' => 'draft',
        'settings' => $settings::fromArray([
            'enableDonationGoal' => $amount !== null,
            'goalAmount' => $amount === null ? 0 : (float) $amount,
            'goalType' => 'amount',
            'designId' => 'classic',
            'showHeading' => true,
            'showDescription' => true,
            'heading' => $title,
        ]),
        'blocks' => $blocks::fromJson($json),
    ]);

    $id = is_object($form) && isset($form->id) ? (int) $form->id : 0;

    return [
        'created' => $id > 0,
        'form_id' => $id,
        'title' => $title,
        'status' => 'draft',
        'published' => false,
        'note' => __('Created as a draft and nothing else. A form that takes card payments is published by a person looking at it, not from here — open it in the Form Builder, check the fields and the goal, then publish.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_update_form_goal(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Changing a GiveWP goal', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_givewp_require_cap(NIBWP_GIVEWP_CAP_FORMS, __('Changing a GiveWP goal', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'form_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $post = nibwp_givewp_require_post($id, 'give_forms', __('donation form', 'nibwp'));
    if ($post instanceof WP_Error) {
        return $post;
    }

    $amount = $input['goal_amount'] ?? null;
    $enabled = $input['goal_enabled'] ?? null;
    $format = nibwp_givewp_text($input['goal_format'] ?? null);

    if ($amount === null && $enabled === null && $format === null) {
        return nibwp_givewp_err(
            'nothing_to_change',
            __('Give at least one of goal_amount, goal_enabled or goal_format.', 'nibwp'),
            422
        );
    }

    if ($amount !== null && (!is_numeric($amount) || (float) $amount < 0)) {
        return nibwp_givewp_err('bad_amount', __('goal_amount must be a number of zero or more.', 'nibwp'), 422);
    }

    $formats = ['amount', 'percentage', 'donation', 'donors'];

    if ($format !== null && !in_array($format, $formats, true)) {
        return nibwp_givewp_err(
            'bad_goal_format',
            sprintf(
                /* translators: 1: the rejected value, 2: list of valid formats */
                __('goal_format "%1$s" is not one GiveWP uses. Valid: %2$s.', 'nibwp'),
                $format,
                implode(', ', $formats)
            ),
            422
        );
    }

    $before = nibwp_givewp_goal($id);
    $written = [];

    if ($amount !== null) {
        nibwp_givewp_write_form_meta($id, NIBWP_GIVEWP_FORM_GOAL_AMOUNT, (string) (float) $amount);
        $written[] = 'goal_amount';
    }

    if ($enabled !== null) {
        // GiveWP's own vocabulary, not a boolean: give_is_setting_enabled()
        // compares against the string (includes/forms/functions.php:447).
        nibwp_givewp_write_form_meta($id, NIBWP_GIVEWP_FORM_GOAL_OPTION, $enabled ? 'enabled' : 'disabled');
        $written[] = 'goal_enabled';
    }

    if ($format !== null) {
        nibwp_givewp_write_form_meta($id, NIBWP_GIVEWP_FORM_GOAL_FORMAT, $format);
        $written[] = 'goal_format';
    }

    return [
        'form_id' => $id,
        'updated' => $written,
        'before' => $before,
        'after' => nibwp_givewp_goal($id),
        'note' => __('A form built in GiveWP\'s Form Builder also keeps a goal in its own settings document. If the form still shows the old goal on the front end, open it in the Form Builder and save once.', 'nibwp'),
    ];
}

/** Write one form meta value through GiveWP's own writer where it exists. */
function nibwp_givewp_write_form_meta(int $form_id, string $key, string $value): void
{
    if (function_exists('give_update_meta')) {
        give_update_meta($form_id, $key, $value);

        return;
    }

    if (function_exists('update_post_meta')) {
        update_post_meta($form_id, $key, $value);
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_set_form_status(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Changing a GiveWP form status', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_givewp_require_cap(NIBWP_GIVEWP_CAP_FORMS, __('Changing a GiveWP form status', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'form_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $post = nibwp_givewp_require_post($id, 'give_forms', __('donation form', 'nibwp'));
    if ($post instanceof WP_Error) {
        return $post;
    }

    $new = nibwp_givewp_text($input['new_status'] ?? null);

    // Not trash. Trashing a form hides the donations attached to it from the
    // reports an organisation reconciles against, and it is a click away in
    // wp-admin with a warning beside it.
    $allowed = ['publish', 'draft', 'pending', 'private'];

    if ($new === null || !in_array($new, $allowed, true)) {
        return nibwp_givewp_err(
            'bad_status',
            sprintf(
                /* translators: %s: list of valid statuses */
                __('new_status must be one of: %s. Trashing a form is not done from here.', 'nibwp'),
                implode(', ', $allowed)
            ),
            422
        );
    }

    $current = (string) nibwp_givewp_text($post->post_status ?? null);

    if ($current === $new) {
        return [
            'form_id' => $id,
            'changed' => false,
            'status' => $new,
            'note' => __('Already in that status, so nothing was changed.', 'nibwp'),
        ];
    }

    // Taking a live form offline stops donations arriving and nothing announces
    // it. The confirmation is the only thing standing between "tidy up the forms"
    // and a fundraising page that quietly stopped working.
    if ($current === 'publish' && $new !== 'publish' && empty($input['confirm'])) {
        return nibwp_givewp_err(
            'confirm_required',
            sprintf(
                /* translators: 1: form title, 2: the requested status */
                __('"%1$s" is published and taking donations. Moving it to %2$s stops that, and nothing on the site will say so. Send confirm:true if that is intended.', 'nibwp'),
                (string) nibwp_givewp_text($post->post_title ?? null),
                $new
            ),
            409
        );
    }

    if (!function_exists('wp_update_post')) {
        return nibwp_givewp_err('no_posts_api', __('WordPress is not loaded far enough to change that form.', 'nibwp'), 500);
    }

    $updated = wp_update_post(['ID' => $id, 'post_status' => $new], true);

    if ($updated instanceof WP_Error) {
        return nibwp_givewp_err('update_failed', $updated->get_error_message(), 500);
    }

    return [
        'form_id' => $id,
        'changed' => true,
        'was' => $current,
        'status' => $new,
        'note' => $new === 'publish'
            ? __('The form is live and can take donations.', 'nibwp')
            : __('The form is offline. It will not take donations, and the donations it already has are untouched.', 'nibwp'),
    ];
}

/* ---------------------------------------------------------- donations: reads */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_list_donations(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Listing GiveWP donations', 'nibwp'))) {
        return $denied;
    }

    $status = nibwp_givewp_status($input);

    if ($status === '') {
        return nibwp_givewp_err(
            'bad_status',
            sprintf(
                /* translators: %s: list of valid statuses */
                __('status must be one of: %s (or "complete", which means publish).', 'nibwp'),
                implode(', ', nibwp_givewp_statuses())
            ),
            422
        );
    }

    $limit = nibwp_givewp_limit($input);
    $offset = nibwp_givewp_offset($input, $limit);
    $window = nibwp_givewp_window($input);

    $rows = nibwp_givewp_fetch_donations([
        'statuses' => $status === null ? nibwp_givewp_statuses() : [$status],
        // A listing with no window asked for is not silently a month: the whole
        // history is what "list donations" means, and days/from/to narrow it.
        'from' => isset($input['days']) || isset($input['from']) ? $window['from'] : null,
        'to' => isset($input['days']) || isset($input['to']) ? $window['to'] : null,
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
        'donor_id' => isset($input['donor_id']) ? (int) $input['donor_id'] : null,
        'recurring' => isset($input['recurring']) ? (bool) $input['recurring'] : null,
        'limit' => $limit,
        'offset' => $offset,
    ]);

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    $anonymous = 0;

    foreach ($rows as $row) {
        if ($row['anonymous']) {
            $anonymous++;
        }
    }

    return [
        'donations' => $rows,
        'total' => count($rows),
        'returned' => count($rows),
        'anonymous_count' => $anonymous,
        'limit' => $limit,
        'offset' => $offset,
        'window' => ['from' => $window['from'], 'to' => $window['to']],
        'note' => __('A donation marked anonymous comes back with anonymous:true and no donor name or email. Do not try to put a name to it.', 'nibwp'),
    ];
}

/**
 * One donation.
 *
 * Two gates, not one: the hub's own, and GiveWP's per-donation view capability.
 * Naming a single donation is a different act from reading a count, and GiveWP
 * draws the same line itself (src/Framework/Permissions/DonationPermissions.php:16).
 * Then the object: an id that is not a give_payment post is refused rather than
 * read, so this cannot be used to fetch an arbitrary post's meta.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_get_donation(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading a GiveWP donation', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_givewp_require_cap(NIBWP_GIVEWP_CAP_DONATION, __('Reading a single GiveWP donation', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'donation_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $post = nibwp_givewp_require_post($id, 'give_payment', __('donation', 'nibwp'));
    if ($post instanceof WP_Error) {
        return $post;
    }

    // The listing query is ordered by date and takes no id, so the single read
    // builds its row from the post and its own meta — through the SAME row
    // builder, which is the only reason the anonymity rule cannot be skipped here.
    $row = nibwp_givewp_donation_meta_row($id, $post);

    if ($row instanceof WP_Error) {
        return $row;
    }

    return [
        'donation' => $row,
        'note' => $row['anonymous']
            ? __('This donor asked to be anonymous. Their name and email are withheld here and everywhere else in this integration — say that, rather than looking for them another way.', 'nibwp')
            : null,
    ];
}

/**
 * One donation's row, built from its own meta.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_donation_meta_row(int $id, object $post): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $meta = nibwp_givewp_table('donationmeta');
    $column = nibwp_givewp_meta_column();
    $fields = nibwp_givewp_donation_fields();
    $keys = array_keys($fields);
    $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

    // The allow-list is in the WHERE clause, so the meta this file is not allowed
    // to report never leaves the database in the first place. A row builder that
    // filters afterwards works right up until somebody logs the query result.
    $sql = $wpdb->prepare(
        'SELECT meta_key, meta_value FROM ' . $meta
        . ' WHERE ' . $column . ' = %d AND meta_key IN (' . $placeholders . ') LIMIT %d',
        array_merge([$id], $keys, [count($keys) * 2])
    );

    $row = [
        'id' => $id,
        'status' => nibwp_givewp_text($post->post_status ?? null),
        'date_gmt' => nibwp_givewp_text($post->post_date_gmt ?? null) ?? nibwp_givewp_text($post->post_date ?? null),
    ];

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $found) {
        $found = (array) $found;
        $key = (string) ($found['meta_key'] ?? '');

        if (isset($fields[$key])) {
            $row[$fields[$key]] = $found['meta_value'] ?? null;
        }
    }

    return nibwp_givewp_donation_row($row);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_count_donations(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Counting GiveWP donations', 'nibwp'))) {
        return $denied;
    }

    $window = nibwp_givewp_window($input);
    $status = nibwp_givewp_status($input);

    if ($status === '') {
        return nibwp_givewp_err('bad_status', __('status is not one GiveWP uses.', 'nibwp'), 422);
    }

    $tally = nibwp_givewp_tally([
        'statuses' => $status === null ? nibwp_givewp_successful_statuses() : [$status],
        'from' => $window['from'],
        'to' => $window['to'],
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
    ]);

    if ($tally instanceof WP_Error) {
        return $tally;
    }

    return [
        'window' => ['from' => $window['from'], 'to' => $window['to'], 'days' => $window['days']],
        'status' => $status ?? 'successful',
        'donations' => $tally['donations'],
        'donors' => $tally['donors'],
        'rejected_input' => $window['rejected'],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_donation_totals(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Totalling GiveWP donations', 'nibwp'))) {
        return $denied;
    }

    $window = nibwp_givewp_window($input);
    $status = nibwp_givewp_status($input);

    if ($status === '') {
        return nibwp_givewp_err('bad_status', __('status is not one GiveWP uses.', 'nibwp'), 422);
    }

    $tally = nibwp_givewp_tally([
        'statuses' => $status === null ? nibwp_givewp_successful_statuses() : [$status],
        'from' => $window['from'],
        'to' => $window['to'],
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
    ]);

    if ($tally instanceof WP_Error) {
        return $tally;
    }

    return [
        'window' => ['from' => $window['from'], 'to' => $window['to'], 'days' => $window['days']],
        'status' => $status ?? 'successful',
        'donations' => $tally['donations'],
        'by_currency' => $tally['by_currency'],
        'currencies_combined' => false,
        'note' => __('Totals are per currency and are not added together. A site taking more than one currency has no single total, and converting between them is not this integration\'s call to make.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_list_recurring_donations(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Listing GiveWP recurring donations', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_givewp_limit($input);
    $offset = nibwp_givewp_offset($input, $limit);

    $rows = nibwp_givewp_fetch_donations([
        'statuses' => nibwp_givewp_statuses(),
        'recurring' => true,
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
        'donor_id' => isset($input['donor_id']) ? (int) $input['donor_id'] : null,
        'limit' => $limit,
        'offset' => $offset,
    ]);

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    return [
        'donations' => $rows,
        'returned' => count($rows),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'recurring_supported' => nibwp_givewp_recurring_supported(),
        'note' => __('Recurring is read from the donation itself — the flag the first donation carries, or the subscription id a renewal carries. A site not running recurring donations has none of either, and that is not the same as a quiet month.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_recurring_summary(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP recurring donations summary', 'nibwp'))) {
        return $denied;
    }

    if (!nibwp_givewp_recurring_supported()) {
        return [
            'supported' => false,
            'subscriptions' => 0,
            'by_status' => [],
            'note' => __('This build has no subscriptions table, so it is not running GiveWP\'s recurring donations. There are no recurring donations to report rather than none this month.', 'nibwp'),
        ];
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $table = nibwp_givewp_table('subscriptions');

    $sql = $wpdb->prepare(
        'SELECT status, COUNT(*) AS subscriptions, SUM(recurring_amount + 0) AS recurring_total'
        . ' FROM ' . $table . ' WHERE status <> %s GROUP BY status ORDER BY subscriptions DESC LIMIT %d',
        ['trashed', NIBWP_GIVEWP_MAX_ROWS]
    );

    $by_status = [];
    $total = 0;
    $currency = nibwp_givewp_base_currency();

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $count = (int) ($row['subscriptions'] ?? 0);
        $total += $count;

        $by_status[] = [
            'status' => nibwp_givewp_text($row['status'] ?? null),
            'subscriptions' => $count,
            'recurring_total' => nibwp_givewp_money($row['recurring_total'] ?? 0, $currency),
        ];
    }

    return [
        'supported' => true,
        'subscriptions' => $total,
        'by_status' => $by_status,
        'note' => __('Counts and amounts only. The gateway profile id and transaction id a subscription carries are credentials at the processor and are never returned.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- donors */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_list_donors(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Listing GiveWP donors', 'nibwp'))) {
        return $denied;
    }

    return nibwp_givewp_donor_listing($input, 'date_created');
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_top_donors(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP top donors', 'nibwp'))) {
        return $denied;
    }

    $result = nibwp_givewp_donor_listing($input, 'purchase_value');

    if ($result instanceof WP_Error) {
        return $result;
    }

    return $result + [
        'note' => __('Ranked by lifetime total. A donor whose giving has all been anonymous appears here as a total with no name — that is the donor\'s choice, not a gap to fill in.', 'nibwp'),
    ];
}

/**
 * The donors table, bounded and name-gated.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_donor_listing(array $input, string $order): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $table = nibwp_givewp_table('donors');

    if (!nibwp_givewp_table_exists($table)) {
        return nibwp_givewp_err('no_donors_table', __('This install has no GiveWP donors table.', 'nibwp'), 500);
    }

    $limit = nibwp_givewp_limit($input);
    $offset = nibwp_givewp_offset($input, $limit);

    // ORDER BY is an identifier, so it comes from this two-value list and never
    // from input. The columns asked for are the allow-list: token, verify_key and
    // verify_throttle are not among them and never will be.
    // purchase_value is a mediumtext column holding a decimal string
    // (includes/database/class-give-db-donors.php:586), so it sorts as text unless
    // it is pushed through arithmetic first — "9" above "10000" otherwise.
    $column = $order === 'purchase_value' ? 'purchase_value + 0' : 'date_created';

    $sql = $wpdb->prepare(
        'SELECT id, user_id, email, name, purchase_value, purchase_count, date_created FROM ' . $table
        . ' WHERE purchase_count > %d ORDER BY ' . $column . ' DESC, id DESC LIMIT %d OFFSET %d',
        [0, $limit, $offset]
    );

    $raw = (array) $wpdb->get_results($sql, ARRAY_A);
    $nameable = nibwp_givewp_nameable_donors(array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        array_map(static fn ($row): array => (array) $row, $raw)
    ));

    $donors = [];
    $withheld = 0;

    foreach ($raw as $row) {
        $row = (array) $row;
        $id = (int) ($row['id'] ?? 0);
        $may_name = $nameable[$id] ?? false;

        if (!$may_name) {
            $withheld++;
        }

        $donors[] = nibwp_givewp_donor_row($row, $may_name);
    }

    return [
        'donors' => $donors,
        'returned' => count($donors),
        'total' => count($donors),
        'names_withheld' => $withheld,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/**
 * One donor, with their giving history.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_get_donor(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading a GiveWP donor', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_givewp_require_cap(NIBWP_GIVEWP_CAP_DONATION, __('Reading one named GiveWP donor', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'donor_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_givewp_donor_record($id);

    if ($row instanceof WP_Error) {
        return $row;
    }

    $nameable = nibwp_givewp_nameable_donors([$id]);
    $limit = nibwp_givewp_limit($input, 20);

    $history = nibwp_givewp_fetch_donations([
        'statuses' => nibwp_givewp_statuses(),
        'donor_id' => $id,
        'limit' => $limit,
        'offset' => nibwp_givewp_offset($input, $limit),
    ]);

    if ($history instanceof WP_Error) {
        return $history;
    }

    $donor = nibwp_givewp_donor_row($row, $nameable[$id] ?? false);

    return [
        'donor' => $donor,
        'history' => $history,
        'history_returned' => count($history),
        'history_limit' => $limit,
        'note' => $donor['anonymous']
            ? __('Every donation this donor has made was marked anonymous, so their name and email are withheld here as well. The giving history is still reportable; the identity is not.', 'nibwp')
            : __('Each donation in the history carries its own anonymity: a donor who normally gives in their own name can still have given anonymously once, and that row has no name on it.', 'nibwp'),
    ];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_donor_record(int $id): array|WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_row') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $table = nibwp_givewp_table('donors');

    if (!nibwp_givewp_table_exists($table)) {
        return nibwp_givewp_err('no_donors_table', __('This install has no GiveWP donors table.', 'nibwp'), 500);
    }

    $sql = $wpdb->prepare(
        'SELECT id, user_id, email, name, purchase_value, purchase_count, date_created FROM ' . $table
        . ' WHERE id = %d LIMIT %d',
        [$id, 1]
    );

    $row = $wpdb->get_row($sql, ARRAY_A);

    if (!is_array($row) || ($row['id'] ?? null) === null) {
        return nibwp_givewp_err(
            'not_found',
            sprintf(
                /* translators: %d: the donor id that was asked for */
                __('No donor with id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    return $row;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_donor_donations(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Listing one donor\'s GiveWP donations', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_givewp_require_cap(NIBWP_GIVEWP_CAP_DONATION, __('Listing one donor\'s GiveWP donations', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_givewp_id($input, 'donor_id');
    if ($id instanceof WP_Error) {
        return $id;
    }

    $record = nibwp_givewp_donor_record($id);

    if ($record instanceof WP_Error) {
        return $record;
    }

    $limit = nibwp_givewp_limit($input);
    $offset = nibwp_givewp_offset($input, $limit);

    $rows = nibwp_givewp_fetch_donations([
        'statuses' => nibwp_givewp_statuses(),
        'donor_id' => $id,
        'limit' => $limit,
        'offset' => $offset,
    ]);

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    $nameable = nibwp_givewp_nameable_donors([$id]);

    return [
        'donor' => nibwp_givewp_donor_row($record, $nameable[$id] ?? false),
        'donations' => $rows,
        'returned' => count($rows),
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/* --------------------------------------------------------------- reporting */

/**
 * The headline numbers. The shape this hub has always returned.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_get_stats(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP statistics', 'nibwp'))) {
        return $denied;
    }

    $lifetime = nibwp_givewp_tally(['statuses' => nibwp_givewp_successful_statuses()]);

    if ($lifetime instanceof WP_Error) {
        return $lifetime;
    }

    $earnings = 0.0;

    if (function_exists('give_get_total_earnings')) {
        try {
            $earnings = (float) give_get_total_earnings();
        } catch (\Throwable $error) {
            $earnings = 0.0;
        }
    }

    $donors = nibwp_givewp_donor_count();

    return [
        'stats' => [
            // The legacy keys. total_earnings is GiveWP's own lifetime figure,
            // which it keeps in the base currency and does not split — so the
            // per-currency breakdown is beside it rather than instead of it.
            'total_earnings' => round($earnings, 2),
            'total_donations' => $lifetime['donations'],
            'total_donors' => $donors,
            'base_currency' => nibwp_givewp_base_currency(),
            'by_currency' => $lifetime['by_currency'],
            'recurring_supported' => nibwp_givewp_recurring_supported(),
        ],
        'note' => __('total_earnings is GiveWP\'s own lifetime figure in the site\'s base currency. by_currency is what this integration counted, split by the currency each donation was actually taken in — if the two disagree, the site has taken more than one currency.', 'nibwp'),
    ];
}

/** How many donors the site has. */
function nibwp_givewp_donor_count(): int
{
    global $wpdb;

    $table = nibwp_givewp_table('donors');

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
        return 0;
    }

    if (!nibwp_givewp_table_exists($table)) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE purchase_count > %d', 0));
}

/**
 * A period against the period before it, so a number has something to mean.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_revenue_summary(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP revenue summary', 'nibwp'))) {
        return $denied;
    }

    $days = nibwp_givewp_days($input);
    $now = time();
    $from = $now - ($days * DAY_IN_SECONDS);
    $previous_from = $from - ($days * DAY_IN_SECONDS);

    $current = nibwp_givewp_tally([
        'statuses' => nibwp_givewp_successful_statuses(),
        'from' => gmdate('Y-m-d H:i:s', $from),
        'to' => gmdate('Y-m-d H:i:s', $now),
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
    ]);

    if ($current instanceof WP_Error) {
        return $current;
    }

    $previous = nibwp_givewp_tally([
        'statuses' => nibwp_givewp_successful_statuses(),
        'from' => gmdate('Y-m-d H:i:s', $previous_from),
        'to' => gmdate('Y-m-d H:i:s', $from),
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
    ]);

    if ($previous instanceof WP_Error) {
        return $previous;
    }

    // Compared per currency, because a change computed across currencies is a
    // number about exchange rates dressed up as a number about fundraising.
    $before = [];

    foreach ($previous['by_currency'] as $row) {
        $before[(string) ($row['currency'] ?? '')] = $row;
    }

    $comparison = [];

    foreach ($current['by_currency'] as $row) {
        $code = (string) ($row['currency'] ?? '');
        $was = $before[$code]['total']['amount'] ?? 0.0;
        $is = $row['total']['amount'] ?? 0.0;

        $comparison[] = [
            'currency' => $row['currency'],
            'this_period' => $row['total'],
            'previous_period' => $before[$code]['total'] ?? nibwp_givewp_money(0, $row['currency']),
            'donations' => $row['donations'],
            'previous_donations' => (int) ($before[$code]['donations'] ?? 0),
            'change_percent' => (float) $was > 0 ? round(((((float) $is) - (float) $was) / (float) $was) * 100, 1) : null,
            'change_note' => (float) $was > 0 ? null : __('Nothing was raised in this currency in the period before, so there is no percentage to give.', 'nibwp'),
        ];
    }

    return [
        'days' => $days,
        'window' => ['from' => gmdate('Y-m-d H:i:s', $from), 'to' => gmdate('Y-m-d H:i:s', $now)],
        'previous_window' => ['from' => gmdate('Y-m-d H:i:s', $previous_from), 'to' => gmdate('Y-m-d H:i:s', $from)],
        'donations' => $current['donations'],
        'previous_donations' => $previous['donations'],
        'by_currency' => $comparison,
        'currencies_combined' => false,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_revenue_by_form(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading GiveWP revenue by form', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $window = nibwp_givewp_window($input);
    $limit = nibwp_givewp_limit($input, 50);
    $posts = nibwp_givewp_table('posts');
    $meta = nibwp_givewp_table('donationmeta');
    $column = nibwp_givewp_meta_column();
    $statuses = nibwp_givewp_successful_statuses();

    $values = [
        NIBWP_GIVEWP_META_FORM_ID,
        NIBWP_GIVEWP_META_FORM_TITLE,
        NIBWP_GIVEWP_META_AMOUNT,
        NIBWP_GIVEWP_META_CURRENCY,
        'give_payment',
    ];

    foreach ($statuses as $status) {
        $values[] = $status;
    }

    $values[] = (string) $window['from'];
    $values[] = (string) $window['to'];
    $values[] = $limit;

    $sql = $wpdb->prepare(
        'SELECT f.meta_value AS form_id, MAX(t.meta_value) AS form_title, c.meta_value AS currency,'
        . ' COUNT(DISTINCT p.ID) AS donations, SUM(a.meta_value + 0) AS total'
        . ' FROM ' . $posts . ' p'
        . ' INNER JOIN ' . $meta . ' f ON f.' . $column . ' = p.ID AND f.meta_key = %s'
        . ' LEFT JOIN ' . $meta . ' t ON t.' . $column . ' = p.ID AND t.meta_key = %s'
        . ' INNER JOIN ' . $meta . ' a ON a.' . $column . ' = p.ID AND a.meta_key = %s'
        . ' LEFT JOIN ' . $meta . ' c ON c.' . $column . ' = p.ID AND c.meta_key = %s'
        . ' WHERE p.post_type = %s AND p.post_status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')'
        . ' AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s'
        . ' GROUP BY f.meta_value, c.meta_value ORDER BY total DESC LIMIT %d',
        $values
    );

    $rows = [];

    foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
        $count = (int) ($row['donations'] ?? 0);

        $rows[] = [
            'form_id' => (int) ($row['form_id'] ?? 0),
            'form_title' => nibwp_givewp_text($row['form_title'] ?? null),
            'donations' => $count,
            'total' => nibwp_givewp_money($row['total'] ?? 0, $row['currency'] ?? null),
            'average' => nibwp_givewp_money(
                $count > 0 ? ((float) ($row['total'] ?? 0)) / $count : 0,
                $row['currency'] ?? null
            ),
        ];
    }

    return [
        'window' => ['from' => $window['from'], 'to' => $window['to'], 'days' => $window['days']],
        'returned' => count($rows),
        'forms' => $rows,
        'limit' => $limit,
        'currencies_combined' => false,
        'note' => __('One row per form per currency. A form that took donations in two currencies has two rows, because the totals are not the same kind of number.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_average_gift(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP average gift', 'nibwp'))) {
        return $denied;
    }

    $window = nibwp_givewp_window($input);

    $tally = nibwp_givewp_tally([
        'statuses' => nibwp_givewp_successful_statuses(),
        'from' => $window['from'],
        'to' => $window['to'],
        'form_id' => isset($input['form_id']) ? (int) $input['form_id'] : null,
    ]);

    if ($tally instanceof WP_Error) {
        return $tally;
    }

    $rows = [];

    foreach ($tally['by_currency'] as $row) {
        $rows[] = [
            'currency' => $row['currency'],
            'donations' => $row['donations'],
            'total' => $row['total'],
            'average_gift' => $row['average'],
        ];
    }

    return [
        'window' => ['from' => $window['from'], 'to' => $window['to'], 'days' => $window['days']],
        'donations' => $tally['donations'],
        'by_currency' => $rows,
        'currencies_combined' => false,
    ];
}

/* ------------------------------------------------------------------- audit */

/**
 * Published forms with no goal switched on.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_audit_forms_without_goal(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Auditing the GiveWP goals', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_givewp_limit($input, 50);
    $posts = nibwp_givewp_form_posts(['form_status' => 'publish'], $limit, nibwp_givewp_offset($input, $limit));

    if ($posts instanceof WP_Error) {
        return $posts;
    }

    $rows = [];

    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }

        $id = (int) ($post->ID ?? 0);
        $goal = nibwp_givewp_goal($id);

        // A goal switched on with a target of zero is the same problem wearing a
        // tick: the thermometer shows 100% on the first pound.
        $target = (float) ($goal['target']['amount'] ?? $goal['target']['count'] ?? 0);

        if ($goal['enabled'] && $target > 0) {
            continue;
        }

        $rows[] = [
            'form_id' => $id,
            'form_title' => nibwp_givewp_text($post->post_title ?? null),
            'goal_enabled' => $goal['enabled'],
            'goal_target' => $target,
            'reason' => $goal['enabled']
                ? __('The goal is switched on but the target is zero, so the progress bar is full from the first donation.', 'nibwp')
                : __('No goal is switched on, so the form has nothing to show donors it is working towards.', 'nibwp'),
        ];
    }

    return [
        'returned' => count($rows),
        'forms' => $rows,
        'limit' => $limit,
        'note' => __('A goal is the single biggest thing a donation form can have and not have. This is published forms only — a draft with no goal is not a problem yet.', 'nibwp'),
    ];
}

/**
 * Published forms that have never received a donation.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_audit_forms_never_funded(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Auditing the GiveWP forms', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_givewp_limit($input, 50);
    $posts = nibwp_givewp_form_posts(['form_status' => 'publish'], $limit, nibwp_givewp_offset($input, $limit));

    if ($posts instanceof WP_Error) {
        return $posts;
    }

    $rows = [];

    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }

        $id = (int) ($post->ID ?? 0);
        $goal = nibwp_givewp_goal($id);

        if ($goal['donation_count'] > 0) {
            continue;
        }

        // The cached sales counter can be stale or missing, so a form it claims is
        // empty is checked against the donations themselves before it is reported.
        // Reporting a funded form as never funded is how an owner stops believing
        // the audit.
        $confirm = nibwp_givewp_tally([
            'statuses' => nibwp_givewp_successful_statuses(),
            'form_id' => $id,
        ]);

        if (!$confirm instanceof WP_Error && $confirm['donations'] > 0) {
            continue;
        }

        $rows[] = [
            'form_id' => $id,
            'form_title' => nibwp_givewp_text($post->post_title ?? null),
            'published' => nibwp_givewp_text($post->post_date ?? null),
            'reason' => __('Published and has never taken a donation. Either nobody can find it, or something on it does not work.', 'nibwp'),
        ];
    }

    return [
        'returned' => count($rows),
        'forms' => $rows,
        'limit' => $limit,
        'note' => __('Checked against the donations themselves, not just the cached counter on the form, because that counter can be stale.', 'nibwp'),
    ];
}

/**
 * A goal already met on a form that is still asking.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_audit_goals_met_still_open(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Auditing the GiveWP goals', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_givewp_limit($input, 50);
    $posts = nibwp_givewp_form_posts(['form_status' => 'publish'], $limit, nibwp_givewp_offset($input, $limit));

    if ($posts instanceof WP_Error) {
        return $posts;
    }

    $rows = [];

    foreach ($posts as $post) {
        if (!is_object($post)) {
            continue;
        }

        $id = (int) ($post->ID ?? 0);
        $goal = nibwp_givewp_goal($id);

        if (!$goal['enabled'] || !$goal['met']) {
            continue;
        }

        $rows[] = [
            'form_id' => $id,
            'form_title' => nibwp_givewp_text($post->post_title ?? null),
            'goal' => $goal,
            'closes_when_met' => $goal['closes_when_met'],
            // Not a defect — it is a decision the owner may not know they are
            // making. Some campaigns deliberately overshoot; others look careless.
            'reason' => $goal['closes_when_met']
                ? __('The goal is met and the form is set to close when it is, so it may already have stopped taking donations. Worth checking that is still what is wanted.', 'nibwp')
                : __('The goal is met and the form is still asking for money. That may be deliberate — but donors are being shown a bar that is already full.', 'nibwp'),
        ];
    }

    return [
        'returned' => count($rows),
        'forms' => $rows,
        'limit' => $limit,
    ];
}

/**
 * Recurring donations whose last attempt failed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_audit_failing_recurring(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Auditing the GiveWP recurring donations', 'nibwp'))) {
        return $denied;
    }

    if (!nibwp_givewp_recurring_supported()) {
        return [
            'supported' => false,
            'returned' => 0,
            'subscriptions' => [],
            'note' => __('This build has no subscriptions table, so it is not running GiveWP\'s recurring donations and there is nothing here to fail.', 'nibwp'),
        ];
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_givewp_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $table = nibwp_givewp_table('subscriptions');
    $limit = nibwp_givewp_limit($input, 50);

    // failing is the status the gateway sets when a rebill is declined; suspended
    // and expired are the two it lands on afterwards. All three are money that
    // stopped arriving without anybody deciding it should.
    $statuses = ['failing', 'suspended', 'expired'];
    $values = $statuses;
    $values[] = $limit;

    $sql = $wpdb->prepare(
        'SELECT id, customer_id, product_id, status, period, frequency, initial_amount, recurring_amount,'
        . ' bill_times, created, expiration FROM ' . $table
        . ' WHERE status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')'
        . ' ORDER BY expiration DESC, id DESC LIMIT %d',
        $values
    );

    $raw = (array) $wpdb->get_results($sql, ARRAY_A);
    $donor_ids = [];

    foreach ($raw as $row) {
        $donor_ids[] = (int) (((array) $row)['customer_id'] ?? 0);
    }

    $nameable = nibwp_givewp_nameable_donors($donor_ids);
    $currency = nibwp_givewp_base_currency();
    $rows = [];

    foreach ($raw as $row) {
        $row = (array) $row;
        $donor_id = (int) ($row['customer_id'] ?? 0);

        // The subscription's own transaction_id, profile_id and notes columns are
        // not selected above, so there is nothing here to forget to drop. The
        // donor's name follows the same rule as everywhere else.
        $rows[] = [
            'subscription_id' => (int) ($row['id'] ?? 0),
            'donor_id' => $donor_id,
            'donor_named' => (bool) ($nameable[$donor_id] ?? false),
            'form_id' => (int) ($row['product_id'] ?? 0),
            'status' => nibwp_givewp_text($row['status'] ?? null),
            'period' => nibwp_givewp_text($row['period'] ?? null),
            'frequency' => (int) ($row['frequency'] ?? 1),
            'recurring_amount' => nibwp_givewp_money($row['recurring_amount'] ?? 0, $currency),
            'payments_taken' => (int) ($row['bill_times'] ?? 0),
            'started' => nibwp_givewp_text($row['created'] ?? null),
            'last_or_next_payment' => nibwp_givewp_text($row['expiration'] ?? null),
        ];
    }

    return [
        'supported' => true,
        'returned' => count($rows),
        'subscriptions' => $rows,
        'statuses_counted' => $statuses,
        'limit' => $limit,
        'note' => __('Recurring income that stopped without anybody deciding it should: a declined card, a suspended plan, an expired one. Each is a conversation with a donor who thinks they are still giving. No gateway profile id or transaction id is returned — those live at the processor.', 'nibwp'),
    ];
}

/**
 * The few things an owner should act on, in one response.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_givewp_audit_summary(array $input): array|WP_Error
{
    if ($denied = nibwp_givewp_require(__('Reading the GiveWP audit summary', 'nibwp'))) {
        return $denied;
    }

    $no_goal = nibwp_givewp_audit_forms_without_goal($input);
    $never = nibwp_givewp_audit_forms_never_funded($input);
    $met = nibwp_givewp_audit_goals_met_still_open($input);
    $failing = nibwp_givewp_audit_failing_recurring($input);

    $act_on = [];

    $no_goal_count = $no_goal instanceof WP_Error ? 0 : (int) ($no_goal['returned'] ?? 0);
    $never_count = $never instanceof WP_Error ? 0 : (int) ($never['returned'] ?? 0);
    $met_count = $met instanceof WP_Error ? 0 : (int) ($met['returned'] ?? 0);
    $failing_count = $failing instanceof WP_Error ? 0 : (int) ($failing['returned'] ?? 0);

    // _n() rather than one string with a %d in it: a language with more than two
    // plural forms cannot be made to agree with a hardcoded English singular, and
    // the English itself reads wrong at one.
    if ($no_goal_count > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of published donation forms with no usable goal */
            _n(
                '%d published donation form has no usable goal.',
                '%d published donation forms have no usable goal.',
                $no_goal_count,
                'nibwp'
            ),
            $no_goal_count
        );
    }

    if ($never_count > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of published forms that have never taken a donation */
            _n(
                '%d published donation form has never taken a donation.',
                '%d published donation forms have never taken a donation.',
                $never_count,
                'nibwp'
            ),
            $never_count
        );
    }

    if ($met_count > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of forms whose goal is met but still soliciting */
            _n(
                '%d form has met its goal and is still asking for money.',
                '%d forms have met their goal and are still asking for money.',
                $met_count,
                'nibwp'
            ),
            $met_count
        );
    }

    if ($failing_count > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of recurring donations that stopped */
            _n(
                '%d recurring donation has stopped without being cancelled.',
                '%d recurring donations have stopped without being cancelled.',
                $failing_count,
                'nibwp'
            ),
            $failing_count
        );
    }

    return [
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
        'forms_without_goal' => $no_goal_count,
        'forms_never_funded' => $never_count,
        'goals_met_still_open' => $met_count,
        'failing_recurring' => $failing_count,
        'recurring_supported' => nibwp_givewp_recurring_supported(),
        'note' => __('Each count has an action of its own that lists the forms behind it. Nothing here is fixed automatically — a goal and a form going offline are both the owner\'s decision.', 'nibwp'),
    ];
}
