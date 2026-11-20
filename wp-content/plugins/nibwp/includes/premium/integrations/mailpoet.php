<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: MailPoet.
 *
 * Verified against MailPoet 5.40.0 (mailpoet.php:8).
 *
 * Everything that CAN go through MailPoet's own public API does. That API is
 * `\MailPoet\API\API::MP('v1')` (lib/API/API.php:17), which resolves
 * \MailPoet\API\MP\v1\API out of the plugin's container — the class whose own
 * docblock says "API used by other plugins" (lib/API/MP/v1/API.php:11-14). Lists,
 * subscribers and custom subscriber fields are all read and written through it,
 * so when MailPoet moves a column or renames a Doctrine entity that stays
 * MailPoet's problem rather than ours.
 *
 * Where the public API has nothing — campaigns, statistics, the sending queue —
 * this reads MailPoet's tables directly with prepared statements and a LIMIT on
 * every query. That is a deliberate second choice, named as such, not an
 * oversight: there is no v1 API for newsletters at all, and the alternative was
 * reaching into Doctrine repositories, which are private implementation with no
 * stability promise whatsoever.
 *
 * WHAT IS NOT HERE, AND WILL NOT BE: anything that sends a campaign to a list.
 * No send, no schedule, no resume, no "activate this welcome email". Mail to
 * real people cannot be recalled, the cost of a mistaken one is borne by
 * everybody on the list, and an agent acting on a half-understood instruction is
 * exactly the wrong actor for that decision. MailPoet's own screens are one
 * click away and show the recipient count before the button. The one thing that
 * does send is send_test_email: one address, named by the caller, manage_options,
 * and the description says it sends real mail. A check parses the enum and fails
 * if an action that sends to a list ever appears, so a later edit has to argue
 * with it.
 *
 * TOKENS: MailPoet's own subscriber payload carries `unsubscribe_token` and
 * `link_token` (lib/API/JSON/ResponseBuilders/SubscribersResponseBuilder.php:112-113),
 * and getSubscriber()/getSubscribers() both return exactly that payload
 * (lib/API/MP/v1/Subscribers.php:128-131, 438-447). Either token lets the holder
 * act AS that person — unsubscribe them, or open their subscription-management
 * page. So nothing MailPoet hands back is returned as it arrives. An allow-list
 * of field names governs what may leave, a value that is not a scalar is dropped
 * rather than cast, and the same builder also merges arbitrary `cf_*` custom
 * fields into the top level of that array (SubscribersResponseBuilder.php:138),
 * which is precisely why a denylist would not have held.
 *
 * CREDENTIALS: the `mta` setting holds whichever credential the configured
 * sending method needs — `mailpoet_api_key`, `api_key`, `access_key`,
 * `secret_key`, `login`, `password` (lib/Mailer/MailerFactory.php:60-61, 83,
 * 110-111). None of them is ever returned. They are reported as a name and
 * whether they are set, so an owner can see that sending IS configured without
 * being handed the key.
 *
 * PAID SURFACES: bounce data only exists when MailPoet's own sending service is
 * enabled with a valid key, because that is the only thing that reports bounces
 * (lib/Cron/Workers/Bounce.php:100-102). Per-recipient engagement rows are
 * populated by the Premium plugin; the free plugin ships an empty implementation
 * (lib/Newsletter/Statistics/Export/StatisticsExporter.php:24-30, 105). Both
 * refuse with their own reason rather than quietly answering zero.
 */

/* ---------------------------------------------------------------- constants */

/** Reading subscribers: the capability MailPoet gates its own subscribers screens on. */
const NIBWP_MAILPOET_CAP_SUBSCRIBERS = 'mailpoet_manage_subscribers';

/** Lists are MailPoet "segments" internally, and have their own capability. */
const NIBWP_MAILPOET_CAP_LISTS = 'mailpoet_manage_segments';

/** Campaigns and their statistics. */
const NIBWP_MAILPOET_CAP_EMAILS = 'mailpoet_manage_emails';

/** Settings, and the boundary on anything irreversible. */
const NIBWP_MAILPOET_CAP_SETTINGS = 'manage_options';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_MAILPOET_MAX_ROWS = 200;

/** Default rows when a caller does not say. */
const NIBWP_MAILPOET_DEFAULT_ROWS = 50;

/** The longest window any statistics action will look back over. */
const NIBWP_MAILPOET_MAX_DAYS = 365;

/* -------------------------------------------------------------------- guard */

/**
 * Is MailPoet actually loaded?
 *
 * MAILPOET_VERSION is defined by the initializer before the plugin boots
 * (mailpoet_initializer.php:104), and \MailPoet\API\API is the public entry point
 * everything here goes through. Between them they distinguish "not installed"
 * from "installed but did not finish loading", and both are refusals rather than
 * fatals.
 */
function nibwp_mailpoet_guard(): ?WP_Error
{
    if (!defined('MAILPOET_VERSION') && !class_exists('MailPoet\\API\\API')) {
        return new WP_Error(
            'nibwp_mailpoet_missing',
            __('MailPoet is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/**
 * MailPoet Premium, for the surfaces only Premium populates.
 *
 * The Premium plugin defines MAILPOET_PREMIUM_VERSION (lib/Config/Initializer.php:549)
 * and MAILPOET_PREMIUM_LICENSE, which is what MailPoet's own
 * ServicesChecker::isPremiumPluginActive() reads (lib/Config/ServicesChecker.php:30
 * via lib/Util/License/License.php:11-18).
 */
function nibwp_mailpoet_premium_guard(): ?WP_Error
{
    if (defined('MAILPOET_PREMIUM_VERSION') || defined('MAILPOET_PREMIUM_LICENSE')) {
        return null;
    }

    return new WP_Error(
        'nibwp_mailpoet_premium_required',
        __('This reads data that only the paid MailPoet Premium plugin records, and Premium is not active on this site. The free plugin leaves those rows empty, so answering would mean reporting zero as though it were a measurement.', 'nibwp'),
        ['status' => 400]
    );
}

/**
 * MailPoet's own sending service, for bounce data.
 *
 * Nothing else reports bounces: the bounce worker refuses to run unless the
 * sending service is enabled and its key is valid (lib/Cron/Workers/Bounce.php:100-102),
 * so on any other sending method the bounce table is empty by construction and a
 * count of zero would be a lie dressed as a fact.
 */
function nibwp_mailpoet_sending_service_guard(): ?WP_Error
{
    $method = '';
    $config = nibwp_mailpoet_setting('mta');

    if (is_array($config) && isset($config['method']) && is_scalar($config['method'])) {
        $method = (string) $config['method'];
    }

    if ($method === 'MailPoet') {
        return null;
    }

    return new WP_Error(
        'nibwp_mailpoet_sending_service_required',
        __('Bounce data only exists when this site sends through MailPoet\'s own paid sending service, which reports bounces back. On any other sending method nothing records them, so the answer would be zero regardless of how many emails actually bounced.', 'nibwp'),
        ['status' => 400]
    );
}

/* -------------------------------------------------------------- refusals */

/** A refusal about authority rather than about the request. */
function nibwp_mailpoet_forbidden(string $what, string $capability): WP_Error
{
    return new WP_Error(
        'nibwp_mailpoet_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $capability
        ),
        ['status' => 403]
    );
}

/**
 * The one capability gate.
 *
 * Several actions call it twice: once for the object they touch, and once for the
 * boundary that an irreversible operation sits behind. Both refusals carry the
 * same code and name their own capability in the message, which is what lets a
 * caller tell which door closed.
 */
function nibwp_mailpoet_require(string $what, string $capability): ?WP_Error
{
    return current_user_can($capability) ? null : nibwp_mailpoet_forbidden($what, $capability);
}

/** A refusal about the request itself. */
function nibwp_mailpoet_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_mailpoet_' . $code, $message, ['status' => $status]);
}

/* ------------------------------------------------------------ plugin access */

/**
 * MailPoet's public API object.
 *
 * Declared `object` rather than a union with WP_Error, because a union with a
 * class type is not a thing PHP will parse. A caller gets an object or an
 * exception, and the dispatcher's one catch turns the exception into a named
 * refusal.
 *
 * @throws RuntimeException when MailPoet's API is not reachable.
 */
function nibwp_mailpoet_api(): object
{
    if (!class_exists('MailPoet\\API\\API') || !method_exists('MailPoet\\API\\API', 'MP')) {
        throw new RuntimeException('MailPoet does not expose its public API on this install.');
    }

    $api = \MailPoet\API\API::MP('v1');

    if (!is_object($api)) {
        throw new RuntimeException('MailPoet returned no API object for version v1.');
    }

    return $api;
}

/**
 * One service out of MailPoet's container.
 *
 * Used only for the sending method, which has no counterpart in the public API.
 *
 * @throws RuntimeException when the container or the service is absent.
 */
function nibwp_mailpoet_service(string $class): object
{
    if (!class_exists('MailPoet\\DI\\ContainerWrapper')) {
        throw new RuntimeException('MailPoet\'s service container is not available.');
    }

    $container = \MailPoet\DI\ContainerWrapper::getInstance();

    if (!is_object($container) || !method_exists($container, 'get')) {
        throw new RuntimeException('MailPoet\'s service container is not usable.');
    }

    $service = $container->get($class);

    if (!is_object($service)) {
        throw new RuntimeException('MailPoet does not provide ' . $class . ' on this install.');
    }

    return $service;
}

/**
 * Read one MailPoet setting without letting its internals throw.
 *
 * SettingsController reaches the database (lib/Settings/SettingsController.php:43),
 * and on a site mid-migration that can raise. A missing setting is a null here,
 * never an exception that takes the REST request with it.
 */
function nibwp_mailpoet_setting(string $key): mixed
{
    if (!class_exists('MailPoet\\Settings\\SettingsController')) {
        return null;
    }

    try {
        $settings = \MailPoet\Settings\SettingsController::getInstance();

        if (!is_object($settings) || !method_exists($settings, 'get')) {
            return null;
        }

        return $settings->get($key);
    } catch (\Throwable $error) {
        return null;
    }
}

/**
 * One of MailPoet's table names.
 *
 * MailPoet builds its prefix as $wpdb->prefix plus a filterable plugin prefix
 * (lib/Config/Env.php:102-105), so a site that filtered `mailpoet_db_prefix`
 * still works — ask the plugin first and only fall back to the default. The name
 * is matched against a fixed list so a table identifier can never come from
 * input: identifiers cannot be bound by prepare() on every supported WordPress,
 * and this is the alternative to hoping.
 */
function nibwp_mailpoet_table(string $name): string
{
    $known = [
        'segments',
        'subscribers',
        'subscriber_segment',
        'newsletters',
        'newsletter_segment',
        'sending_queues',
        'scheduled_tasks',
        'statistics_newsletters',
        'statistics_opens',
        'statistics_clicks',
        'statistics_bounces',
        'statistics_unsubscribes',
    ];

    if (!in_array($name, $known, true)) {
        return '';
    }

    global $wpdb;

    $base = is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix) ? $wpdb->prefix : '';
    $prefix = $base . 'mailpoet_';

    if (class_exists('MailPoet\\Config\\Env') && property_exists('MailPoet\\Config\\Env', 'dbPrefix')) {
        $declared = \MailPoet\Config\Env::$dbPrefix;

        if (is_string($declared) && $declared !== '') {
            $prefix = $declared;
        }
    }

    return $prefix . $name;
}

/**
 * A usable $wpdb, or a refusal.
 *
 * Reads that fall back to SQL say so by needing this; everything the public API
 * covers never calls it.
 */
function nibwp_mailpoet_db(): ?WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_mailpoet_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    return null;
}

/* ----------------------------------------------------------------- shaping */

/** Clamp a row count that arrived over the wire. */
function nibwp_mailpoet_limit(mixed $value): int
{
    $limit = is_numeric($value) ? (int) $value : NIBWP_MAILPOET_DEFAULT_ROWS;

    return max(1, min(NIBWP_MAILPOET_MAX_ROWS, $limit));
}

/** Clamp an offset. */
function nibwp_mailpoet_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** Clamp a look-back window in days. */
function nibwp_mailpoet_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_MAILPOET_MAX_DAYS, $days));
}

/**
 * Subscriber fields that may leave this hub, and nothing else.
 *
 * An allow-list rather than a denylist because the payload this filters is not a
 * fixed shape: SubscribersResponseBuilder merges every custom field the site has
 * ever defined into the top level of the same array
 * (lib/API/JSON/ResponseBuilders/SubscribersResponseBuilder.php:138), and a
 * MailPoet release that adds one more field would default to leaking it.
 *
 * Deliberately absent, each for its own reason:
 *   - unsubscribe_token, link_token — either one lets the holder act AS that
 *     person (SubscribersResponseBuilder.php:112-113);
 *   - subscribed_ip, confirmed_ip — the subscriber's home address, near enough;
 *   - unconfirmed_data — a blob of whatever a form collected before confirmation;
 *   - tracking_consent_copy — the full consent text, which is bulk, not fact;
 *   - cf_* — arbitrary site-defined fields, contents unknown by definition.
 *
 * @return array<int, string>
 */
function nibwp_mailpoet_subscriber_fields(): array
{
    return [
        'id',
        'email',
        'first_name',
        'last_name',
        'status',
        'created_at',
        'updated_at',
        'confirmed_at',
        'last_subscribed_at',
        'deleted_at',
        'count_confirmations',
        'source',
        'wp_user_id',
        'is_woocommerce_user',
        'timezone',
        'engagement_score',
        'engagement_score_type',
        'tracking_consent',
        'tracking_consent_updated_at',
        'tracking_consent_method',
    ];
}

/**
 * Field names that must never travel, however they are asked for.
 *
 * The allow-list above already governs what leaves; this exists so that a name
 * landing in both lists is a loud failure rather than a quiet leak, which the
 * check asserts.
 *
 * @return array<int, string>
 */
function nibwp_mailpoet_subscriber_secret_fields(): array
{
    return [
        'unsubscribe_token',
        'link_token',
        'subscribed_ip',
        'confirmed_ip',
        'unconfirmed_data',
        'tracking_consent_copy',
    ];
}

/**
 * Turn whatever MailPoet handed back into something safe to return.
 *
 * Scalars and nulls from the allow-list, and nothing else. A value that is not a
 * scalar is DROPPED rather than cast or encoded: an array is exactly how a set of
 * tokens would travel under an innocent-looking key, and json_encode()ing it
 * would carry them out in a string while looking tidy.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_mailpoet_subscriber_shape(array $raw): array
{
    $out = [];

    foreach (nibwp_mailpoet_subscriber_fields() as $field) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }

        $value = $raw[$field];

        if ($value === null) {
            $out[$field] = null;
            continue;
        }

        if (!is_scalar($value)) {
            // Dropped, not serialised. See the docblock.
            continue;
        }

        $out[$field] = $value;
    }

    // The list memberships are rebuilt from scratch rather than passed through,
    // so that whatever else MailPoet puts on a subscription row stays behind.
    $out['lists'] = nibwp_mailpoet_subscription_shape($raw['subscriptions'] ?? null);
    $out['tags'] = nibwp_mailpoet_tag_shape($raw['tags'] ?? null);
    $out['tokens_returned'] = false;
    $out['custom_fields_returned'] = false;

    return $out;
}

/**
 * List memberships, as list id plus status and nothing else.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_mailpoet_subscription_shape(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $out = [];

    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = $row['segment_id'] ?? null;
        $status = $row['status'] ?? null;

        $out[] = [
            'list_id' => is_scalar($id) ? (int) $id : null,
            'status' => is_scalar($status) ? (string) $status : null,
        ];

        if (count($out) >= NIBWP_MAILPOET_MAX_ROWS) {
            break;
        }
    }

    return $out;
}

/**
 * Tag names only.
 *
 * MailPoet builds a tag as an array; a future version adding a field to it should
 * not change what leaves here.
 *
 * @return array<int, string>
 */
function nibwp_mailpoet_tag_shape(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $out = [];

    foreach ($raw as $tag) {
        if (is_scalar($tag)) {
            $out[] = (string) $tag;
        } elseif (is_array($tag) && isset($tag['name']) && is_scalar($tag['name'])) {
            $out[] = (string) $tag['name'];
        }

        if (count($out) >= NIBWP_MAILPOET_MAX_ROWS) {
            break;
        }
    }

    return $out;
}

/**
 * One list, as MailPoet's Segments API returns it, trimmed.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_mailpoet_list_shape(array $raw): array
{
    $out = [];

    foreach (['id', 'name', 'type', 'description', 'created_at', 'updated_at', 'deleted_at'] as $field) {
        if (!array_key_exists($field, $raw)) {
            continue;
        }

        $value = $raw[$field];

        if ($value === null) {
            $out[$field] = null;
        } elseif (is_scalar($value)) {
            $out[$field] = $field === 'id' ? (int) $value : $value;
        }
    }

    return $out;
}

/**
 * The credential keys inside the `mta` setting, by sending method.
 *
 * Reported as a name and whether it is set. Never as a value, and no action here
 * accepts a new one — rotating a sending credential is an owner's job in
 * wp-admin, where the consequence of getting it wrong is visible.
 *
 * @return array<int, string>
 */
function nibwp_mailpoet_credential_keys(): array
{
    return [
        'mailpoet_api_key',
        'api_key',
        'access_key',
        'secret_key',
        'login',
        'password',
        'token',
        'premium_key',
    ];
}

/**
 * Settings whose VALUE may be reported.
 *
 * Short on purpose: these are the switches somebody is asking about when they ask
 * whether this site can send and whether its list is being looked after.
 *
 * @return array<string, string> dotted key => bool|int|string
 */
function nibwp_mailpoet_public_settings(): array
{
    return [
        'mta_group' => 'string',
        'mta.method' => 'string',
        'mta.frequency.emails' => 'int',
        'mta.frequency.interval' => 'int',
        'signup_confirmation.enabled' => 'bool',
        'tracking.level' => 'string',
        'deactivate_subscriber_after_inactive_days' => 'int',
        'delete_unconfirmed_subscribers_after_days' => 'string',
        'bounce.address' => 'string',
        'sender.name' => 'string',
        'sender.address' => 'string',
        'reply_to.address' => 'string',
    ];
}

/* ---------------------------------------------------------------- the actions */

/**
 * The actions this hub offers.
 *
 * There is no action that sends a campaign to a list, and there will not be. A
 * check parses this list and fails if one appears.
 *
 * @return array<int, string>
 */
function nibwp_mailpoet_actions(): array
{
    return [
        // Lists.
        'list_lists',
        'create_list',
        'rename_list',
        'delete_list',
        // Subscribers.
        'search_subscribers',
        'get_subscriber',
        'add_subscriber',
        'update_subscriber',
        'unsubscribe_subscriber',
        'delete_subscriber',
        'add_subscriber_to_list',
        'remove_subscriber_from_list',
        // Custom subscriber fields.
        'list_subscriber_fields',
        'create_subscriber_field',
        // Campaigns.
        'list_campaigns',
        'get_campaign',
        'campaign_stats',
        'campaign_recipient_engagement',
        // Deliverability and sending.
        'bounce_report',
        'sending_statistics',
        'sending_method_status',
        'sending_queue',
        // Judgement.
        'list_health_audit',
        // The one thing here that puts mail in front of a person.
        'send_test_email',
    ];
}

wp_register_ability('nibwp/mailpoet-manage', [
    'label' => __('MailPoet – Lists, Subscribers and Campaigns', 'nibwp'),
    'description' => 'Manage MailPoet on this site: lists, subscribers, custom subscriber fields, campaigns and their statistics, the sending method and its queue, '
        . 'plus an audit that names lists with nobody on them, campaigns stuck in the queue, and a list that is decaying through bounces and unconfirmed sign-ups. '
        . 'There is deliberately NO action that sends a campaign to a list — no send, no schedule, no resume. That stays in wp-admin where the recipient count is on screen. '
        . 'send_test_email sends one real email to one address you name, and takes manage_options. '
        . 'Deleting a subscriber or a list is irreversible and takes their history with it; both are named explicitly and both need manage_options. '
        . 'A subscriber\'s unsubscribe token and link hash are never returned, and neither is any sending credential — those come back as a name and whether they are set.',
    'category' => 'marketing',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_mailpoet_actions(),
                'description' => 'Which operation to run. Start with list_health_audit or list_lists.',
            ],
            'list_id' => ['type' => 'integer', 'description' => 'A MailPoet list ("segment") id.'],
            'subscriber' => ['type' => 'string', 'description' => 'A subscriber, by email address or by numeric id.'],
            'campaign_id' => ['type' => 'integer', 'description' => 'A MailPoet newsletter / campaign id.'],
            'email' => ['type' => 'string', 'description' => 'For add_subscriber and send_test_email: the email address.'],
            'first_name' => ['type' => 'string', 'description' => 'For add_subscriber / update_subscriber.'],
            'last_name' => ['type' => 'string', 'description' => 'For add_subscriber / update_subscriber.'],
            'name' => ['type' => 'string', 'description' => 'For create_list / rename_list / create_subscriber_field: the name.'],
            'description' => ['type' => 'string', 'description' => 'For create_list / rename_list: the list description.'],
            'field_type' => ['type' => 'string', 'description' => 'For create_subscriber_field: text, textarea, radio, checkbox, select or date.'],
            'status' => ['type' => 'string', 'description' => 'For search_subscribers: subscribed, unconfirmed, unsubscribed, inactive or bounced.'],
            'type' => ['type' => 'string', 'description' => 'For list_campaigns: standard, notification, welcome, automatic or re_engagement.'],
            'campaign_status' => ['type' => 'string', 'description' => 'For list_campaigns: draft, scheduled, sending, sent or active.'],
            'days' => ['type' => 'integer', 'description' => 'Look-back window in days. Default 30, max 365.'],
            'hours' => ['type' => 'integer', 'description' => 'For sending_queue / list_health_audit: how long a queue entry must be idle to count as stuck. Default 6.'],
            'subject' => ['type' => 'string', 'description' => 'For send_test_email: the subject line.'],
            'body' => ['type' => 'string', 'description' => 'For send_test_email: the plain-text body.'],
            'send_confirmation_email' => ['type' => 'boolean', 'description' => 'For add_subscriber: whether MailPoet sends its confirmation email. Default false, because that is real mail.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true on delete_subscriber, delete_list and send_test_email.'],
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
    'execute_callback' => 'nibwp_mailpoet_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'MailPoet. Reads freely, writes carefully, and does not send campaigns.',
                '',
                'START WITH list_health_audit when the question is "is the mailing list healthy".',
                'It puts the three things an owner should act on into one response: lists with',
                'nobody subscribed to them, campaigns sitting in the sending queue going nowhere,',
                'and the share of each list that has bounced or never confirmed — which is how a',
                'list decays without anybody noticing.',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'It cannot send a campaign to a list, schedule one, resume a paused one, or',
                'activate a welcome or post-notification email. Do not look for another action',
                'that does; there is not one. Mail to real people cannot be recalled, so that is',
                'a decision for a person looking at the recipient count in wp-admin, and saying',
                'so is the correct answer when asked.',
                '',
                'THE ONE THING THAT SENDS:',
                'send_test_email puts one real email in one real inbox, at the address the caller',
                'names. Say the address back. It needs confirm: true.',
                '',
                'IRREVERSIBLE:',
                '- delete_subscriber removes the person AND their whole engagement history. It',
                '  cannot be undone. If the intent is "stop emailing them", use',
                '  unsubscribe_subscriber instead, which keeps the record.',
                '- delete_list removes the list. MailPoet refuses when a campaign or a form still',
                '  uses it, and that refusal is worth reading rather than working around.',
                'Both need confirm: true and both need manage_options.',
                '',
                'TOKENS: a subscriber\'s unsubscribe token and link hash are never returned. Either',
                'one lets whoever holds it act as that person, so there is no form of the request',
                'that produces them. Custom subscriber fields are not returned either, because',
                'their contents are whatever this site decided to collect.',
                '',
                'CREDENTIALS: sending_method_status reports which credential keys are set, never',
                'their values, and nothing here changes one.',
                '',
                'PAID SURFACES: bounce_report needs MailPoet\'s own sending service, and',
                'campaign_recipient_engagement needs MailPoet Premium. Each refuses with its own',
                'reason rather than reporting zero as if it were a measurement.',
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
function nibwp_mailpoet_manage(array $input): array|WP_Error
{
    $guard = nibwp_mailpoet_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_mailpoet_actions(), true)) {
        return nibwp_mailpoet_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown MailPoet action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_mailpoet_actions())
            )
        );
    }

    // MailPoet's public API throws typed exceptions for ordinary outcomes — a
    // subscriber who does not exist, an email already on the list, a list still
    // used by a form (lib/API/MP/v1/APIException.php). One catch here turns any
    // of that, and anything else the plugin's internals raise, into a named
    // refusal rather than a fatal that takes the whole REST request down.
    try {
        return nibwp_mailpoet_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_mailpoet_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from MailPoet */
                __('MailPoet could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_mailpoet_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'list_lists' => nibwp_mailpoet_list_lists($input),
        'create_list' => nibwp_mailpoet_create_list($input),
        'rename_list' => nibwp_mailpoet_rename_list($input),
        'delete_list' => nibwp_mailpoet_delete_list($input),
        'search_subscribers' => nibwp_mailpoet_search_subscribers($input),
        'get_subscriber' => nibwp_mailpoet_get_subscriber($input),
        'add_subscriber' => nibwp_mailpoet_add_subscriber($input),
        'update_subscriber' => nibwp_mailpoet_update_subscriber($input),
        'unsubscribe_subscriber' => nibwp_mailpoet_unsubscribe_subscriber($input),
        'delete_subscriber' => nibwp_mailpoet_delete_subscriber($input),
        'add_subscriber_to_list' => nibwp_mailpoet_add_subscriber_to_list($input),
        'remove_subscriber_from_list' => nibwp_mailpoet_remove_subscriber_from_list($input),
        'list_subscriber_fields' => nibwp_mailpoet_list_subscriber_fields(),
        'create_subscriber_field' => nibwp_mailpoet_create_subscriber_field($input),
        'list_campaigns' => nibwp_mailpoet_list_campaigns($input),
        'get_campaign' => nibwp_mailpoet_get_campaign($input),
        'campaign_stats' => nibwp_mailpoet_campaign_stats($input),
        'campaign_recipient_engagement' => nibwp_mailpoet_campaign_recipient_engagement($input),
        'bounce_report' => nibwp_mailpoet_bounce_report($input),
        'sending_statistics' => nibwp_mailpoet_sending_statistics($input),
        'sending_method_status' => nibwp_mailpoet_sending_method_status(),
        'sending_queue' => nibwp_mailpoet_sending_queue($input),
        'list_health_audit' => nibwp_mailpoet_list_health_audit($input),
        'send_test_email' => nibwp_mailpoet_send_test_email($input),
        default => nibwp_mailpoet_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------------ input helpers */

/**
 * A list id from input, as a positive integer.
 */
function nibwp_mailpoet_list_id(mixed $raw): int|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_mailpoet_err('missing_list_id', __('Give the list as list_id, a positive integer.', 'nibwp'), 422);
    }

    return $id;
}

/**
 * A campaign id from input.
 */
function nibwp_mailpoet_campaign_id(mixed $raw): int|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_mailpoet_err('missing_campaign_id', __('Give the campaign as campaign_id, a positive integer.', 'nibwp'), 422);
    }

    return $id;
}

/**
 * A subscriber reference: an email address, or a numeric id.
 *
 * MailPoet's API takes either (lib/API/MP/v1/Subscribers.php:533), so this only
 * has to reject the empty and the absurd.
 */
function nibwp_mailpoet_subscriber_ref(mixed $raw): string|WP_Error
{
    $ref = is_scalar($raw) ? trim((string) $raw) : '';

    if ($ref === '') {
        return nibwp_mailpoet_err('missing_subscriber', __('Give the subscriber as subscriber, either an email address or a numeric id.', 'nibwp'), 422);
    }

    if (strlen($ref) > 254) {
        return nibwp_mailpoet_err('bad_subscriber', __('That subscriber reference is too long to be either an email address or an id.', 'nibwp'), 422);
    }

    if (!is_numeric($ref) && !is_email($ref)) {
        return nibwp_mailpoet_err(
            'bad_subscriber',
            __('subscriber must be an email address or a numeric id.', 'nibwp'),
            422
        );
    }

    return $ref;
}

/** A required non-empty name from input. */
function nibwp_mailpoet_name(mixed $raw): string|WP_Error
{
    $name = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';

    if ($name === '') {
        return nibwp_mailpoet_err('missing_name', __('Give the name as name.', 'nibwp'), 422);
    }

    return $name;
}

/** An explicit confirmation on anything that cannot be undone. */
function nibwp_mailpoet_confirmed(array $input, string $what): ?WP_Error
{
    if (!empty($input['confirm'])) {
        return null;
    }

    return nibwp_mailpoet_err(
        'confirm_required',
        sprintf(
            /* translators: %s: a description of the operation */
            __('%s cannot be undone. Pass confirm: true once whoever asked has said yes to that specifically.', 'nibwp'),
            $what
        ),
        422
    );
}

/* ---------------------------------------------------------------- the lists */

/**
 * Every list, with how many people are actually on it.
 *
 * The names and ids come from MailPoet's public API; the counts come from one
 * bounded query, because the API returns no count and an agent asked "which
 * lists are worth sending to" cannot answer from names alone.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_list_lists(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet lists', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
        return $denied;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'getLists')) {
        return nibwp_mailpoet_err('no_lists_api', __('This version of MailPoet does not expose its lists where this integration can read them.', 'nibwp'), 500);
    }

    $limit = nibwp_mailpoet_limit($input['limit'] ?? null);
    $offset = nibwp_mailpoet_offset($input['offset'] ?? null);

    // getLists() returns every default-type list in one go (lib/API/MP/v1/Segments.php:35-42),
    // so the bound is applied here rather than asked for.
    $all = (array) $api->getLists();
    $page = array_slice($all, $offset, $limit);
    $counts = nibwp_mailpoet_list_counts();
    $rows = [];

    foreach ($page as $list) {
        if (!is_array($list)) {
            continue;
        }

        $shaped = nibwp_mailpoet_list_shape($list);
        $id = (int) ($shaped['id'] ?? 0);
        $shaped['subscribed'] = isset($counts[$id]) ? (int) $counts[$id]['subscribed'] : null;
        $shaped['unconfirmed'] = isset($counts[$id]) ? (int) $counts[$id]['unconfirmed'] : null;
        $shaped['unsubscribed'] = isset($counts[$id]) ? (int) $counts[$id]['unsubscribed'] : null;
        $shaped['bounced'] = isset($counts[$id]) ? (int) $counts[$id]['bounced'] : null;
        $rows[] = $shaped;
    }

    return [
        'total' => count($all),
        'returned' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'lists' => $rows,
        'note' => __('These are the lists an owner manages. MailPoet also keeps internal lists for WordPress users and WooCommerce customers; those are a different type and are not returned or editable here.', 'nibwp'),
    ];
}

/**
 * Per-list subscriber counts by status, in one bounded query.
 *
 * @return array<int, array<string, int>>
 */
function nibwp_mailpoet_list_counts(): array
{
    if (nibwp_mailpoet_db() instanceof WP_Error) {
        return [];
    }

    global $wpdb;

    $segments = nibwp_mailpoet_table('segments');
    $pivot = nibwp_mailpoet_table('subscriber_segment');
    $subscribers = nibwp_mailpoet_table('subscribers');

    if ($segments === '' || $pivot === '' || $subscribers === '') {
        return [];
    }

    $sql = $wpdb->prepare(
        'SELECT s.id AS list_id,'
        . ' SUM(CASE WHEN sub.status = %s AND ss.status = %s THEN 1 ELSE 0 END) AS subscribed,'
        . ' SUM(CASE WHEN sub.status = %s THEN 1 ELSE 0 END) AS unconfirmed,'
        . ' SUM(CASE WHEN sub.status = %s THEN 1 ELSE 0 END) AS unsubscribed,'
        . ' SUM(CASE WHEN sub.status = %s THEN 1 ELSE 0 END) AS bounced'
        . ' FROM ' . $segments . ' s'
        . ' LEFT JOIN ' . $pivot . ' ss ON ss.segment_id = s.id'
        . ' LEFT JOIN ' . $subscribers . ' sub ON sub.id = ss.subscriber_id AND sub.deleted_at IS NULL'
        . ' WHERE s.type = %s AND s.deleted_at IS NULL'
        . ' GROUP BY s.id ORDER BY s.id ASC LIMIT %d',
        'subscribed',
        'subscribed',
        'unconfirmed',
        'unsubscribed',
        'bounced',
        'default',
        NIBWP_MAILPOET_MAX_ROWS
    );

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $out[(int) ($row['list_id'] ?? 0)] = [
            'subscribed' => (int) ($row['subscribed'] ?? 0),
            'unconfirmed' => (int) ($row['unconfirmed'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
            'bounced' => (int) ($row['bounced'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Create a list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_create_list(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Creating a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
        return $denied;
    }

    $name = nibwp_mailpoet_name($input['name'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'addList')) {
        return nibwp_mailpoet_err('no_lists_api', __('This version of MailPoet does not expose a way to create a list from here.', 'nibwp'), 500);
    }

    $payload = ['name' => $name];

    if (isset($input['description']) && is_scalar($input['description'])) {
        $payload['description'] = sanitize_textarea_field((string) $input['description']);
    }

    // MailPoet refuses a duplicate name with LIST_EXISTS (lib/API/MP/v1/Segments.php:178-190).
    // That throw is caught in the dispatcher and reported as-is, because "there is
    // already a list called that" is an answer, not a malfunction.
    $list = (array) $api->addList($payload);

    return [
        'created' => true,
        'list' => nibwp_mailpoet_list_shape($list),
    ];
}

/**
 * Rename a list, and optionally re-describe it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_rename_list(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Renaming a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
        return $denied;
    }

    $id = nibwp_mailpoet_list_id($input['list_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $name = nibwp_mailpoet_name($input['name'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'updateList')) {
        return nibwp_mailpoet_err('no_lists_api', __('This version of MailPoet does not expose a way to update a list from here.', 'nibwp'), 500);
    }

    $payload = ['id' => $id, 'name' => $name];

    if (isset($input['description']) && is_scalar($input['description'])) {
        $payload['description'] = sanitize_textarea_field((string) $input['description']);
    }

    $list = (array) $api->updateList($payload);

    return [
        'renamed' => true,
        'list' => nibwp_mailpoet_list_shape($list),
        'note' => __('A list name appears in the subscription-management page people already have links to, so the new name is what they will see next time.', 'nibwp'),
    ];
}

/**
 * Delete a list. Irreversible.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_delete_list(array $input): array|WP_Error
{
    // The boundary first, named separately from the object check below, because
    // deleting a list is not the same kind of act as renaming one.
    if ($denied = nibwp_mailpoet_require(__('Deleting a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_SETTINGS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_require(__('Deleting a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
        return $denied;
    }

    $id = nibwp_mailpoet_list_id($input['list_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    if ($denied = nibwp_mailpoet_confirmed($input, __('Deleting a MailPoet list', 'nibwp'))) {
        return $denied;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'deleteList')) {
        return nibwp_mailpoet_err('no_lists_api', __('This version of MailPoet does not expose a way to delete a list from here.', 'nibwp'), 500);
    }

    // MailPoet refuses when a campaign or a form still uses the list
    // (lib/API/MP/v1/Segments.php:109-139). Those refusals surface through the
    // dispatcher's catch unchanged, and they are worth reading rather than
    // routing around.
    $deleted = (bool) $api->deleteList((string) $id);

    return [
        'deleted' => $deleted,
        'list_id' => $id,
        'note' => __('The list is gone and cannot be restored. The subscribers themselves still exist; what is lost is their membership of this list and anything that depended on it.', 'nibwp'),
    ];
}

/* ---------------------------------------------------------- the subscribers */

/**
 * Search subscribers.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_search_subscribers(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading MailPoet subscribers', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'getSubscribers')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose its subscribers where this integration can read them.', 'nibwp'), 500);
    }

    $filter = [];

    if (isset($input['status'])) {
        $status = is_scalar($input['status']) ? strtolower(trim((string) $input['status'])) : '';
        $allowed = ['subscribed', 'unconfirmed', 'unsubscribed', 'inactive', 'bounced'];

        if ($status !== '' && !in_array($status, $allowed, true)) {
            return nibwp_mailpoet_err(
                'bad_status',
                sprintf(
                    /* translators: %s: list of valid statuses */
                    __('status must be one of: %s.', 'nibwp'),
                    implode(', ', $allowed)
                ),
                422
            );
        }

        if ($status !== '') {
            $filter['status'] = $status;
        }
    }

    if (isset($input['list_id'])) {
        $list = nibwp_mailpoet_list_id($input['list_id']);
        if ($list instanceof WP_Error) {
            return $list;
        }
        // buildListingDefinition() only honours listId when it is a real int
        // (lib/API/MP/v1/Subscribers.php:466-468), so a numeric string would be
        // silently ignored and the caller would get the whole site back.
        $filter['listId'] = $list;
    }

    $limit = nibwp_mailpoet_limit($input['limit'] ?? null);
    $offset = nibwp_mailpoet_offset($input['offset'] ?? null);

    $raw = (array) $api->getSubscribers($filter, $limit, $offset);
    $total = method_exists($api, 'getSubscribersCount') ? (int) $api->getSubscribersCount($filter) : null;
    $rows = [];

    foreach ($raw as $subscriber) {
        if (!is_array($subscriber)) {
            continue;
        }

        $rows[] = nibwp_mailpoet_subscriber_shape($subscriber);

        if (count($rows) >= $limit) {
            break;
        }
    }

    return [
        'total_matching' => $total,
        'returned' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'filter' => $filter,
        'subscribers' => $rows,
        'tokens_returned' => false,
        'note' => __('These are real people. Their unsubscribe tokens and link hashes are never returned, and neither are any custom fields this site collects.', 'nibwp'),
    ];
}

/**
 * One subscriber.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_get_subscriber(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading a MailPoet subscriber', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $ref = nibwp_mailpoet_subscriber_ref($input['subscriber'] ?? null);
    if ($ref instanceof WP_Error) {
        return $ref;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'getSubscriber')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose its subscribers where this integration can read them.', 'nibwp'), 500);
    }

    // SUBSCRIBER_NOT_EXISTS arrives as a thrown APIException
    // (lib/API/MP/v1/APIException.php:12), which the dispatcher turns into a refusal.
    $raw = (array) $api->getSubscriber($ref);

    return [
        'subscriber' => nibwp_mailpoet_subscriber_shape($raw),
        'tokens_returned' => false,
    ];
}

/**
 * Add a subscriber.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_add_subscriber(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Adding a MailPoet subscriber', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $email = isset($input['email']) && is_scalar($input['email']) ? trim((string) $input['email']) : '';

    if ($email === '' || !is_email($email)) {
        return nibwp_mailpoet_err('bad_email', __('Give a valid email address as email.', 'nibwp'), 422);
    }

    $lists = [];

    if (isset($input['list_id'])) {
        $list = nibwp_mailpoet_list_id($input['list_id']);
        if ($list instanceof WP_Error) {
            return $list;
        }
        $lists[] = $list;

        if ($denied = nibwp_mailpoet_require(__('Putting a subscriber on a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
            return $denied;
        }
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'addSubscriber')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose a way to add a subscriber from here.', 'nibwp'), 500);
    }

    $data = ['email' => $email];

    if (isset($input['first_name']) && is_scalar($input['first_name'])) {
        $data['first_name'] = sanitize_text_field((string) $input['first_name']);
    }
    if (isset($input['last_name']) && is_scalar($input['last_name'])) {
        $data['last_name'] = sanitize_text_field((string) $input['last_name']);
    }

    // Both default OFF. MailPoet treats a missing option as "yes, send it"
    // (lib/API/MP/v1/Subscribers.php:134-136), which would mean an agent adding a
    // test address puts mail in a stranger's inbox as a side effect of a write
    // nobody described as sending anything.
    $options = [
        'send_confirmation_email' => !empty($input['send_confirmation_email']),
        'schedule_welcome_email' => false,
        'skip_subscriber_notification' => true,
    ];

    $raw = (array) $api->addSubscriber($data, $lists, $options);

    return [
        'added' => true,
        'subscriber' => nibwp_mailpoet_subscriber_shape($raw),
        'confirmation_email_sent' => (bool) $options['send_confirmation_email'],
        'note' => $options['send_confirmation_email']
            ? __('MailPoet has sent its confirmation email to that address. That is real mail.', 'nibwp')
            : __('No confirmation or welcome email was sent. The subscriber may therefore sit at "unconfirmed" until somebody confirms them, which is the safe default for a write made from a tool call.', 'nibwp'),
    ];
}

/**
 * Update a subscriber's own details.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_update_subscriber(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Updating a MailPoet subscriber', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $ref = nibwp_mailpoet_subscriber_ref($input['subscriber'] ?? null);
    if ($ref instanceof WP_Error) {
        return $ref;
    }

    $data = [];

    foreach (['first_name', 'last_name'] as $field) {
        if (isset($input[$field]) && is_scalar($input[$field])) {
            $data[$field] = sanitize_text_field((string) $input[$field]);
        }
    }

    if (isset($input['email']) && is_scalar($input['email'])) {
        $email = trim((string) $input['email']);

        if (!is_email($email)) {
            return nibwp_mailpoet_err('bad_email', __('email must be a valid address.', 'nibwp'), 422);
        }

        $data['email'] = $email;
    }

    if ($data === []) {
        return nibwp_mailpoet_err('nothing_to_update', __('Give at least one of email, first_name or last_name.', 'nibwp'), 422);
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'updateSubscriber')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose a way to update a subscriber from here.', 'nibwp'), 500);
    }

    $raw = (array) $api->updateSubscriber($ref, $data);

    return [
        'updated' => true,
        'fields' => array_keys($data),
        'subscriber' => nibwp_mailpoet_subscriber_shape($raw),
        'note' => __('A subscriber\'s status is not changed here. Use unsubscribe_subscriber for that, which records why and when.', 'nibwp'),
    ];
}

/**
 * Unsubscribe somebody from everything.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_unsubscribe_subscriber(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Unsubscribing a MailPoet subscriber', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $ref = nibwp_mailpoet_subscriber_ref($input['subscriber'] ?? null);
    if ($ref instanceof WP_Error) {
        return $ref;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'unsubscribe')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose a way to unsubscribe a subscriber from here.', 'nibwp'), 500);
    }

    // Already-unsubscribed throws SUBSCRIBER_ALREADY_UNSUBSCRIBED
    // (lib/API/MP/v1/Subscribers.php:411-413), which is a useful answer.
    $raw = (array) $api->unsubscribe($ref);

    return [
        'unsubscribed' => true,
        'subscriber' => nibwp_mailpoet_subscriber_shape($raw),
        'note' => __('They are off every list and MailPoet has recorded the unsubscribe against this integration as the source. The record is kept, which is what makes this reversible and deletion not.', 'nibwp'),
    ];
}

/**
 * Delete a subscriber. Irreversible, and takes their history.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_delete_subscriber(array $input): array|WP_Error
{
    // The boundary, named in its own right: there is a world of difference
    // between "may edit subscribers" and "may erase a person and their history".
    if ($denied = nibwp_mailpoet_require(__('Deleting a MailPoet subscriber', 'nibwp'), NIBWP_MAILPOET_CAP_SETTINGS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_require(__('Deleting a MailPoet subscriber', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $ref = nibwp_mailpoet_subscriber_ref($input['subscriber'] ?? null);
    if ($ref instanceof WP_Error) {
        return $ref;
    }

    if ($denied = nibwp_mailpoet_confirmed($input, __('Deleting a MailPoet subscriber', 'nibwp'))) {
        return $denied;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'getSubscriber')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose its subscribers where this integration can read them.', 'nibwp'), 500);
    }

    // Resolve first, so an email or an id both land on the same record and the
    // response can say WHO was deleted rather than echoing what was asked for.
    $raw = (array) $api->getSubscriber($ref);
    $id = isset($raw['id']) && is_scalar($raw['id']) ? (int) $raw['id'] : 0;
    $email = isset($raw['email']) && is_scalar($raw['email']) ? (string) $raw['email'] : '';

    if ($id <= 0) {
        return nibwp_mailpoet_err('subscriber_not_found', __('No subscriber matched that reference.', 'nibwp'), 404);
    }

    // MailPoet's public API has no delete: it offers unsubscribe, which keeps the
    // record. Deletion lives on the repository (lib/Subscribers/SubscribersRepository.php:225),
    // so this is the one write that reaches past the public API, and it is guarded
    // accordingly.
    $repository = nibwp_mailpoet_service('MailPoet\\Subscribers\\SubscribersRepository');

    if (!method_exists($repository, 'bulkDelete')) {
        return nibwp_mailpoet_err('no_delete_api', __('This version of MailPoet does not expose a way to delete a subscriber from here. Unsubscribing them is available and is reversible.', 'nibwp'), 500);
    }

    $deleted = (int) $repository->bulkDelete([$id]);

    return [
        'deleted' => $deleted > 0,
        'subscriber_id' => $id,
        'email' => $email,
        'note' => __('That person and their whole engagement history are gone and cannot be restored. If the intent was only to stop emailing them, that was unsubscribe_subscriber and this was the wrong call.', 'nibwp'),
    ];
}

/**
 * Put an existing subscriber on a list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_add_subscriber_to_list(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Putting a subscriber on a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_require(__('Putting a subscriber on a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
        return $denied;
    }

    $ref = nibwp_mailpoet_subscriber_ref($input['subscriber'] ?? null);
    if ($ref instanceof WP_Error) {
        return $ref;
    }

    $list = nibwp_mailpoet_list_id($input['list_id'] ?? null);
    if ($list instanceof WP_Error) {
        return $list;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'getSubscriber') || !method_exists($api, 'subscribeToList')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose list membership where this integration can write it.', 'nibwp'), 500);
    }

    $existing = (array) $api->getSubscriber($ref);
    $id = isset($existing['id']) && is_scalar($existing['id']) ? (int) $existing['id'] : 0;

    if ($id <= 0) {
        return nibwp_mailpoet_err('subscriber_not_found', __('No subscriber matched that reference.', 'nibwp'), 404);
    }

    // Confirmation and welcome mail off: subscribing somebody from a tool call
    // should not be the thing that emails them. MailPoet also refuses to put
    // anybody on its internal WordPress-users or WooCommerce lists
    // (lib/API/MP/v1/APIException.php:13-15), which surfaces as a refusal.
    $raw = (array) $api->subscribeToList($id, $list, [
        'send_confirmation_email' => false,
        'schedule_welcome_email' => false,
        'skip_subscriber_notification' => true,
    ]);

    return [
        'added_to_list' => true,
        'list_id' => $list,
        'subscriber' => nibwp_mailpoet_subscriber_shape($raw),
        'note' => __('No confirmation or welcome email was sent by this call.', 'nibwp'),
    ];
}

/**
 * Take a subscriber off a list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_remove_subscriber_from_list(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Taking a subscriber off a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_require(__('Taking a subscriber off a MailPoet list', 'nibwp'), NIBWP_MAILPOET_CAP_LISTS)) {
        return $denied;
    }

    $ref = nibwp_mailpoet_subscriber_ref($input['subscriber'] ?? null);
    if ($ref instanceof WP_Error) {
        return $ref;
    }

    $list = nibwp_mailpoet_list_id($input['list_id'] ?? null);
    if ($list instanceof WP_Error) {
        return $list;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'unsubscribeFromList')) {
        return nibwp_mailpoet_err('no_subscribers_api', __('This version of MailPoet does not expose list membership where this integration can write it.', 'nibwp'), 500);
    }

    $raw = (array) $api->unsubscribeFromList($ref, $list);

    return [
        'removed_from_list' => true,
        'list_id' => $list,
        'subscriber' => nibwp_mailpoet_subscriber_shape($raw),
        'note' => __('They are off this one list and still on any others. Their record is kept.', 'nibwp'),
    ];
}

/* ------------------------------------------------------ custom field schema */

/**
 * The subscriber fields this site has, built-in and custom.
 *
 * This returns the SCHEMA — names, types and parameters — not anybody's values.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_list_subscriber_fields(): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet subscriber fields', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'getSubscriberFields')) {
        return nibwp_mailpoet_err('no_fields_api', __('This version of MailPoet does not expose its subscriber fields where this integration can read them.', 'nibwp'), 500);
    }

    $raw = (array) $api->getSubscriberFields();
    $rows = [];

    foreach ($raw as $field) {
        if (!is_array($field)) {
            continue;
        }

        $params = $field['params'] ?? [];

        $rows[] = [
            'id' => isset($field['id']) && is_scalar($field['id']) ? (string) $field['id'] : null,
            'name' => isset($field['name']) && is_scalar($field['name']) ? (string) $field['name'] : null,
            'type' => isset($field['type']) && is_scalar($field['type']) ? (string) $field['type'] : null,
            'required' => is_array($params) && !empty($params['required']),
            'custom' => isset($field['id']) && is_scalar($field['id']) && str_starts_with((string) $field['id'], 'cf_'),
        ];

        if (count($rows) >= NIBWP_MAILPOET_MAX_ROWS) {
            break;
        }
    }

    return [
        'returned' => count($rows),
        'fields' => $rows,
        'note' => __('This is the shape of a subscriber record, not anybody\'s data. The values stored in custom fields are never returned by this integration, because what they hold is whatever this site decided to collect.', 'nibwp'),
    ];
}

/**
 * Add a custom subscriber field.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_create_subscriber_field(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Creating a MailPoet subscriber field', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    $name = nibwp_mailpoet_name($input['name'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $types = ['text', 'textarea', 'radio', 'checkbox', 'select', 'date'];
    $type = isset($input['field_type']) && is_scalar($input['field_type'])
        ? strtolower(trim((string) $input['field_type']))
        : 'text';

    if (!in_array($type, $types, true)) {
        return nibwp_mailpoet_err(
            'bad_field_type',
            sprintf(
                /* translators: %s: list of valid field types */
                __('field_type must be one of: %s.', 'nibwp'),
                implode(', ', $types)
            ),
            422
        );
    }

    $api = nibwp_mailpoet_api();

    if (!method_exists($api, 'addSubscriberField')) {
        return nibwp_mailpoet_err('no_fields_api', __('This version of MailPoet does not expose a way to add a subscriber field from here.', 'nibwp'), 500);
    }

    // MailPoet's sanitizer raises InvalidArgumentException, which the API wraps as
    // an APIException (lib/API/MP/v1/API.php:48-54); either way it reaches the
    // dispatcher's catch as a refusal rather than a fatal.
    $field = (array) $api->addSubscriberField([
        'name' => $name,
        'type' => $type,
        'params' => ['required' => '', 'label' => $name],
    ]);

    return [
        'created' => true,
        'field' => [
            'id' => isset($field['id']) && is_scalar($field['id']) ? (string) $field['id'] : null,
            'name' => isset($field['name']) && is_scalar($field['name']) ? (string) $field['name'] : null,
            'type' => isset($field['type']) && is_scalar($field['type']) ? (string) $field['type'] : null,
        ],
        'note' => __('The field exists but no form asks for it yet. Adding it to a subscription form is done in wp-admin.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------- the campaigns */

/**
 * Campaigns, newest first.
 *
 * MailPoet's public v1 API has nothing for newsletters at all, so this and the
 * two actions after it read the tables with prepared statements and a LIMIT.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_list_campaigns(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet campaigns', 'nibwp'), NIBWP_MAILPOET_CAP_EMAILS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $table = nibwp_mailpoet_table('newsletters');

    if ($table === '') {
        return nibwp_mailpoet_err('no_table', __('MailPoet\'s campaign table could not be located.', 'nibwp'), 500);
    }

    // Both filters are matched against a fixed vocabulary before they go near a
    // query, so an unknown value is a refusal rather than an empty result the
    // caller would read as "no campaigns".
    $types = ['standard', 'notification', 'notification_history', 'welcome', 'automatic', 're_engagement', 'automation'];
    $statuses = ['draft', 'scheduled', 'sending', 'sent', 'active', 'corrupt'];

    $type = isset($input['type']) && is_scalar($input['type']) ? strtolower(trim((string) $input['type'])) : '';
    $status = isset($input['campaign_status']) && is_scalar($input['campaign_status']) ? strtolower(trim((string) $input['campaign_status'])) : '';

    if ($type !== '' && !in_array($type, $types, true)) {
        return nibwp_mailpoet_err(
            'bad_type',
            sprintf(
                /* translators: %s: list of valid campaign types */
                __('type must be one of: %s.', 'nibwp'),
                implode(', ', $types)
            ),
            422
        );
    }

    if ($status !== '' && !in_array($status, $statuses, true)) {
        return nibwp_mailpoet_err(
            'bad_campaign_status',
            sprintf(
                /* translators: %s: list of valid campaign statuses */
                __('campaign_status must be one of: %s.', 'nibwp'),
                implode(', ', $statuses)
            ),
            422
        );
    }

    global $wpdb;

    $limit = nibwp_mailpoet_limit($input['limit'] ?? null);
    $offset = nibwp_mailpoet_offset($input['offset'] ?? null);

    $where = 'deleted_at IS NULL';
    $args = [];

    if ($type !== '') {
        $where .= ' AND type = %s';
        $args[] = $type;
    }
    if ($status !== '') {
        $where .= ' AND status = %s';
        $args[] = $status;
    }

    $args[] = $limit;
    $args[] = $offset;

    $sql = $wpdb->prepare(
        'SELECT id, subject, type, status, sender_name, sender_address, sent_at, created_at, updated_at, parent_id'
        . ' FROM ' . $table . ' WHERE ' . $where
        . ' ORDER BY COALESCE(sent_at, updated_at) DESC, id DESC LIMIT %d OFFSET %d',
        $args
    );

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $out = [];

    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = nibwp_mailpoet_campaign_shape($row);
        }
    }

    return [
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'campaigns' => $out,
        'note' => __('The rendered body of a campaign is not returned: it is large, and nothing an agent decides depends on the HTML. Nothing here sends one.', 'nibwp'),
    ];
}

/**
 * One campaign row, trimmed. The unsubscribe_token column is never read.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_mailpoet_campaign_shape(array $row): array
{
    $out = [];

    foreach (['id', 'parent_id'] as $field) {
        $out[$field] = isset($row[$field]) && is_numeric($row[$field]) ? (int) $row[$field] : null;
    }

    foreach (['subject', 'type', 'status', 'sender_name', 'sender_address', 'sent_at', 'created_at', 'updated_at'] as $field) {
        if (!array_key_exists($field, $row)) {
            continue;
        }

        $value = $row[$field];
        $out[$field] = $value === null ? null : (is_scalar($value) ? (string) $value : null);
    }

    return $out;
}

/**
 * One campaign, with its lists.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_get_campaign(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading a MailPoet campaign', 'nibwp'), NIBWP_MAILPOET_CAP_EMAILS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $id = nibwp_mailpoet_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $newsletters = nibwp_mailpoet_table('newsletters');
    $pivot = nibwp_mailpoet_table('newsletter_segment');
    $segments = nibwp_mailpoet_table('segments');

    if ($newsletters === '' || $pivot === '' || $segments === '') {
        return nibwp_mailpoet_err('no_table', __('MailPoet\'s campaign tables could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, subject, type, status, sender_name, sender_address, reply_to_address, preheader,'
            . ' sent_at, created_at, updated_at, parent_id'
            . ' FROM ' . $newsletters . ' WHERE id = %d AND deleted_at IS NULL LIMIT 1',
            $id
        ),
        ARRAY_A
    );

    if (!is_array($row) || $row === []) {
        return nibwp_mailpoet_err('campaign_not_found', __('No campaign with that id.', 'nibwp'), 404);
    }

    $lists = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT s.id AS id, s.name AS name FROM ' . $pivot . ' ns'
            . ' INNER JOIN ' . $segments . ' s ON s.id = ns.segment_id'
            . ' WHERE ns.newsletter_id = %d ORDER BY s.id ASC LIMIT %d',
            $id,
            NIBWP_MAILPOET_MAX_ROWS
        ),
        ARRAY_A
    );

    $sends_to = [];

    foreach ($lists as $list) {
        if (is_array($list)) {
            $sends_to[] = [
                'list_id' => (int) ($list['id'] ?? 0),
                'name' => isset($list['name']) && is_scalar($list['name']) ? (string) $list['name'] : null,
            ];
        }
    }

    $campaign = nibwp_mailpoet_campaign_shape($row);
    $campaign['preheader'] = isset($row['preheader']) && is_scalar($row['preheader']) ? (string) $row['preheader'] : null;
    $campaign['reply_to_address'] = isset($row['reply_to_address']) && is_scalar($row['reply_to_address']) ? (string) $row['reply_to_address'] : null;

    return [
        'campaign' => $campaign,
        'sends_to' => $sends_to,
        'note' => __('sends_to is what this campaign is aimed at. Nothing in this integration will send it; that stays in wp-admin where the recipient count is on screen.', 'nibwp'),
    ];
}

/**
 * What one campaign achieved.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_campaign_stats(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet campaign statistics', 'nibwp'), NIBWP_MAILPOET_CAP_EMAILS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $id = nibwp_mailpoet_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    global $wpdb;

    $sent = nibwp_mailpoet_count_for_campaign('statistics_newsletters', $id);
    $opens = nibwp_mailpoet_count_for_campaign('statistics_opens', $id, 0);
    $machine_opens = nibwp_mailpoet_count_for_campaign('statistics_opens', $id, 1);
    $clicks = nibwp_mailpoet_count_for_campaign('statistics_clicks', $id, 0);
    $unsubscribes = nibwp_mailpoet_count_for_campaign('statistics_unsubscribes', $id);
    $bounces = nibwp_mailpoet_count_for_campaign('statistics_bounces', $id);

    $rate = static function (?int $part, ?int $whole): ?float {
        if ($part === null || $whole === null || $whole <= 0) {
            return null;
        }

        return round(($part / $whole) * 100, 2);
    };

    return [
        'campaign_id' => $id,
        'sent' => $sent,
        'opened' => $opens,
        'machine_opened' => $machine_opens,
        'clicked' => $clicks,
        'unsubscribed' => $unsubscribes,
        'bounced' => $bounces,
        'open_rate_percent' => $rate($opens, $sent),
        'click_rate_percent' => $rate($clicks, $sent),
        'unsubscribe_rate_percent' => $rate($unsubscribes, $sent),
        'note' => __('Opened counts distinct people, and machine_opened is counted separately because a mail client prefetching an image is not a person reading. Bounces are only recorded when this site sends through MailPoet\'s own service — see bounce_report.', 'nibwp'),
    ];
}

/**
 * One distinct-subscriber count for a campaign, bounded by the campaign id.
 *
 * @return int|null null when the table is not there, which is not the same as zero.
 */
function nibwp_mailpoet_count_for_campaign(string $table_name, int $campaign_id, ?int $user_agent_type = null): ?int
{
    if (nibwp_mailpoet_db() instanceof WP_Error) {
        return null;
    }

    $table = nibwp_mailpoet_table($table_name);

    if ($table === '') {
        return null;
    }

    global $wpdb;

    if ($user_agent_type === null) {
        $sql = $wpdb->prepare(
            'SELECT COUNT(DISTINCT subscriber_id) FROM ' . $table . ' WHERE newsletter_id = %d',
            $campaign_id
        );
    } else {
        $sql = $wpdb->prepare(
            'SELECT COUNT(DISTINCT subscriber_id) FROM ' . $table . ' WHERE newsletter_id = %d AND user_agent_type = %d',
            $campaign_id,
            $user_agent_type
        );
    }

    $value = $wpdb->get_var($sql);

    return $value === null ? null : (int) $value;
}

/**
 * Per-recipient engagement for one campaign, as counts.
 *
 * Premium-only, and still counts rather than names: who opened an email is the
 * kind of fact that is useful in aggregate and intrusive individually, and an
 * agent asking "did this campaign land" is asking the aggregate question.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_campaign_recipient_engagement(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet per-recipient engagement', 'nibwp'), NIBWP_MAILPOET_CAP_EMAILS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_premium_guard()) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $id = nibwp_mailpoet_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $sent = nibwp_mailpoet_count_for_campaign('statistics_newsletters', $id);
    $opened = nibwp_mailpoet_count_for_campaign('statistics_opens', $id, 0);
    $clicked = nibwp_mailpoet_count_for_campaign('statistics_clicks', $id, 0);

    $opened_not_clicked = ($opened === null || $clicked === null) ? null : max(0, $opened - $clicked);
    $silent = ($sent === null || $opened === null) ? null : max(0, $sent - $opened);

    return [
        'campaign_id' => $id,
        'sent' => $sent,
        'clicked' => $clicked,
        'opened_without_clicking' => $opened_not_clicked,
        'no_recorded_engagement' => $silent,
        'recipients_named' => false,
        'note' => __('Counts, not names. Who opened a particular email is intrusive one person at a time and useful in aggregate, so the aggregate is what this returns.', 'nibwp'),
    ];
}

/* ----------------------------------------------------- deliverability, queue */

/**
 * Bounces over a period. Needs MailPoet's own sending service.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_bounce_report(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet bounce report', 'nibwp'), NIBWP_MAILPOET_CAP_SUBSCRIBERS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_sending_service_guard()) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    global $wpdb;

    $bounces = nibwp_mailpoet_table('statistics_bounces');
    $newsletters = nibwp_mailpoet_table('newsletters');
    $subscribers = nibwp_mailpoet_table('subscribers');

    if ($bounces === '' || $newsletters === '' || $subscribers === '') {
        return nibwp_mailpoet_err('no_table', __('MailPoet\'s bounce tables could not be located.', 'nibwp'), 500);
    }

    $days = nibwp_mailpoet_days($input['days'] ?? null);
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    $limit = nibwp_mailpoet_limit($input['limit'] ?? null);

    // Grouped by campaign rather than listed per person: the question a bounce
    // report answers is "which sends are hurting this domain's reputation", and
    // that does not need a list of addresses.
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT b.newsletter_id AS campaign_id, n.subject AS subject, COUNT(*) AS bounces'
            . ' FROM ' . $bounces . ' b'
            . ' LEFT JOIN ' . $newsletters . ' n ON n.id = b.newsletter_id'
            . ' WHERE b.created_at >= %s'
            . ' GROUP BY b.newsletter_id, n.subject ORDER BY bounces DESC LIMIT %d',
            $since,
            $limit
        ),
        ARRAY_A
    );

    $by_campaign = [];
    $total = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $count = (int) ($row['bounces'] ?? 0);
        $total += $count;
        $by_campaign[] = [
            'campaign_id' => isset($row['campaign_id']) && is_numeric($row['campaign_id']) ? (int) $row['campaign_id'] : null,
            'subject' => isset($row['subject']) && is_scalar($row['subject']) ? (string) $row['subject'] : null,
            'bounces' => $count,
        ];
    }

    $standing = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $subscribers . ' WHERE status = %s AND deleted_at IS NULL',
            'bounced'
        )
    );

    return [
        'days' => $days,
        'since' => $since,
        'bounces_in_window' => $total,
        'by_campaign' => $by_campaign,
        'subscribers_marked_bounced' => $standing === null ? null : (int) $standing,
        'addresses_returned' => false,
        'note' => __('Grouped by campaign, not listed by person. A subscriber marked bounced stays on the list but is never sent to again; list_health_audit reports how much of each list is in that state.', 'nibwp'),
    ];
}

/**
 * Sending over a period.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_sending_statistics(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet sending statistics', 'nibwp'), NIBWP_MAILPOET_CAP_EMAILS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    global $wpdb;

    $days = nibwp_mailpoet_days($input['days'] ?? null);
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $sent = nibwp_mailpoet_count_since('statistics_newsletters', 'sent_at', $since);
    $opens = nibwp_mailpoet_count_since('statistics_opens', 'created_at', $since);
    $clicks = nibwp_mailpoet_count_since('statistics_clicks', 'created_at', $since);
    $unsubscribes = nibwp_mailpoet_count_since('statistics_unsubscribes', 'created_at', $since);
    $bounces = nibwp_mailpoet_count_since('statistics_bounces', 'created_at', $since);

    $campaigns = nibwp_mailpoet_table('newsletters');
    $campaigns_sent = null;

    if ($campaigns !== '') {
        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $campaigns . ' WHERE sent_at >= %s AND deleted_at IS NULL',
                $since
            )
        );
        $campaigns_sent = $value === null ? null : (int) $value;
    }

    return [
        'days' => $days,
        'since' => $since,
        'emails_sent' => $sent,
        'campaigns_sent' => $campaigns_sent,
        'opens' => $opens,
        'clicks' => $clicks,
        'unsubscribes' => $unsubscribes,
        'bounces' => $bounces,
        'note' => __('MailPoet prunes its sending-status rows on a retention setting, so an old window can under-report and that is the setting rather than a fault. Bounces are only recorded on MailPoet\'s own sending service.', 'nibwp'),
    ];
}

/**
 * One count since a timestamp.
 *
 * @return int|null null when the table is not there.
 */
function nibwp_mailpoet_count_since(string $table_name, string $column, string $since): ?int
{
    if (nibwp_mailpoet_db() instanceof WP_Error) {
        return null;
    }

    // The column is chosen from a fixed set rather than taken from input, because
    // a column name cannot be bound by prepare().
    if (!in_array($column, ['sent_at', 'created_at', 'updated_at'], true)) {
        return null;
    }

    $table = nibwp_mailpoet_table($table_name);

    if ($table === '') {
        return null;
    }

    global $wpdb;

    $value = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' >= %s',
            $since
        )
    );

    return $value === null ? null : (int) $value;
}

/**
 * How this site sends, and whether sending is stuck.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_sending_method_status(): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet sending configuration', 'nibwp'), NIBWP_MAILPOET_CAP_SETTINGS)) {
        return $denied;
    }

    $config = nibwp_mailpoet_setting('mta');
    $config = is_array($config) ? $config : [];

    $method = isset($config['method']) && is_scalar($config['method']) ? (string) $config['method'] : null;
    $frequency = is_array($config['frequency'] ?? null) ? $config['frequency'] : [];

    // Credentials: name and whether set. Never the value, and this is the only
    // place in the file that touches them at all.
    $credentials = [];

    foreach (nibwp_mailpoet_credential_keys() as $key) {
        if (!array_key_exists($key, $config)) {
            continue;
        }

        $value = $config[$key];
        $credentials[] = [
            'key' => $key,
            'is_set' => $value !== null && $value !== '' && $value !== false,
            'value_returned' => false,
        ];
    }

    $paused = null;
    $error = null;

    // MailerLog is where a paused send and its reason live (lib/Mailer/MailerLog.php:402, 266).
    if (class_exists('MailPoet\\Mailer\\MailerLog')) {
        try {
            if (method_exists('MailPoet\\Mailer\\MailerLog', 'isSendingPaused')) {
                $paused = (bool) \MailPoet\Mailer\MailerLog::isSendingPaused();
            }

            if (method_exists('MailPoet\\Mailer\\MailerLog', 'getError')) {
                $raw = \MailPoet\Mailer\MailerLog::getError();

                if (is_array($raw)) {
                    $error = [
                        'operation' => isset($raw['operation']) && is_scalar($raw['operation']) ? (string) $raw['operation'] : null,
                        'message' => isset($raw['error_message']) && is_scalar($raw['error_message']) ? (string) $raw['error_message'] : null,
                    ];
                }
            }
        } catch (\Throwable $thrown) {
            // A broken log is not a reason to refuse the rest of this answer.
            $paused = null;
        }
    }

    $settings = [];

    foreach (nibwp_mailpoet_public_settings() as $key => $type) {
        $value = nibwp_mailpoet_setting($key);

        $settings[] = [
            'key' => $key,
            'value' => match ($type) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                default => is_scalar($value) ? (string) $value : null,
            },
        ];
    }

    return [
        'method' => $method,
        'group' => is_scalar(nibwp_mailpoet_setting('mta_group')) ? (string) nibwp_mailpoet_setting('mta_group') : null,
        'emails_per_interval' => isset($frequency['emails']) && is_numeric($frequency['emails']) ? (int) $frequency['emails'] : null,
        'interval_minutes' => isset($frequency['interval']) && is_numeric($frequency['interval']) ? (int) $frequency['interval'] : null,
        'sending_paused' => $paused,
        'last_error' => $error,
        'premium_plugin_active' => defined('MAILPOET_PREMIUM_VERSION') || defined('MAILPOET_PREMIUM_LICENSE'),
        'settings' => $settings,
        'credentials' => $credentials,
        'credentials_returned' => false,
        'note' => __('Credential values never leave this hub, and nothing here changes one — rotating a sending key is an owner\'s job in wp-admin. If sending is paused, the reason above is MailPoet\'s own wording.', 'nibwp'),
    ];
}

/**
 * What is in the sending queue right now.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_sending_queue(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Reading the MailPoet sending queue', 'nibwp'), NIBWP_MAILPOET_CAP_EMAILS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $limit = nibwp_mailpoet_limit($input['limit'] ?? null);
    $rows = nibwp_mailpoet_queue_rows($limit, null);

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    return [
        'returned' => count($rows),
        'limit' => $limit,
        'queue' => $rows,
        'note' => __('A queue entry with status null is running; "scheduled" is waiting for the cron to pick it up and "paused" is stopped. Nothing here resumes or cancels one — that is wp-admin, where the recipient count is visible.', 'nibwp'),
    ];
}

/**
 * Queue rows, optionally only the ones idle longer than $stuck_hours.
 *
 * @return array<int, array<string, mixed>>|WP_Error
 */
function nibwp_mailpoet_queue_rows(int $limit, ?int $stuck_hours): array|WP_Error
{
    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $queues = nibwp_mailpoet_table('sending_queues');
    $tasks = nibwp_mailpoet_table('scheduled_tasks');
    $newsletters = nibwp_mailpoet_table('newsletters');

    if ($queues === '' || $tasks === '' || $newsletters === '') {
        return nibwp_mailpoet_err('no_table', __('MailPoet\'s sending-queue tables could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $sql = 'SELECT q.id AS queue_id, q.newsletter_id AS campaign_id, n.subject AS subject,'
        . ' t.status AS status, t.scheduled_at AS scheduled_at, t.updated_at AS updated_at,'
        . ' q.count_total AS count_total, q.count_processed AS count_processed, q.count_to_process AS count_to_process'
        . ' FROM ' . $queues . ' q'
        . ' INNER JOIN ' . $tasks . ' t ON t.id = q.task_id'
        . ' LEFT JOIN ' . $newsletters . ' n ON n.id = q.newsletter_id'
        . ' WHERE q.deleted_at IS NULL AND t.deleted_at IS NULL'
        . ' AND (t.status IS NULL OR t.status IN (%s, %s))';

    $args = ['scheduled', 'paused'];

    if ($stuck_hours !== null) {
        $sql .= ' AND q.count_to_process > 0 AND t.updated_at < %s';
        $args[] = gmdate('Y-m-d H:i:s', time() - (max(1, min(720, $stuck_hours)) * HOUR_IN_SECONDS));
    }

    $sql .= ' ORDER BY t.updated_at ASC LIMIT %d';
    $args[] = max(1, min(NIBWP_MAILPOET_MAX_ROWS, $limit));

    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $out[] = [
            'queue_id' => (int) ($row['queue_id'] ?? 0),
            'campaign_id' => isset($row['campaign_id']) && is_numeric($row['campaign_id']) ? (int) $row['campaign_id'] : null,
            'subject' => isset($row['subject']) && is_scalar($row['subject']) ? (string) $row['subject'] : null,
            // null is MailPoet's way of recording "running" (lib/Entities/ScheduledTaskEntity.php:30).
            'status' => ($row['status'] ?? null) === null ? 'running' : (string) $row['status'],
            'scheduled_at' => isset($row['scheduled_at']) && is_scalar($row['scheduled_at']) ? (string) $row['scheduled_at'] : null,
            'last_touched' => isset($row['updated_at']) && is_scalar($row['updated_at']) ? (string) $row['updated_at'] : null,
            'total' => (int) ($row['count_total'] ?? 0),
            'processed' => (int) ($row['count_processed'] ?? 0),
            'remaining' => (int) ($row['count_to_process'] ?? 0),
        ];
    }

    return $out;
}

/* -------------------------------------------------------------- the audit */

/**
 * The three things an owner should act on, in one response.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_list_health_audit(array $input): array|WP_Error
{
    // The audit crosses subscribers, lists and the queue at once, so it asks for
    // the strictest of the three rather than any one of them.
    if ($denied = nibwp_mailpoet_require(__('Auditing the MailPoet lists', 'nibwp'), NIBWP_MAILPOET_CAP_SETTINGS)) {
        return $denied;
    }

    if ($denied = nibwp_mailpoet_db()) {
        return $denied;
    }

    $counts = nibwp_mailpoet_list_counts();
    $hours = isset($input['hours']) && is_numeric($input['hours']) ? (int) $input['hours'] : 6;
    $stuck = nibwp_mailpoet_queue_rows(nibwp_mailpoet_limit($input['limit'] ?? null), $hours);

    if ($stuck instanceof WP_Error) {
        return $stuck;
    }

    $names = nibwp_mailpoet_list_names();
    $empty = [];
    $decaying = [];
    $act_on = [];

    foreach ($counts as $id => $row) {
        $subscribed = (int) $row['subscribed'];
        $spoiled = (int) $row['bounced'] + (int) $row['unconfirmed'];
        $total = $subscribed + $spoiled + (int) $row['unsubscribed'];
        $name = $names[$id] ?? null;

        if ($subscribed === 0) {
            $empty[] = ['list_id' => $id, 'name' => $name, 'total_records' => $total];
            continue;
        }

        $share = $total > 0 ? round(($spoiled / $total) * 100, 2) : 0.0;

        // A fifth of the list unreachable or never confirmed is the point at which
        // the deliverability cost starts outrunning the reach. Reported with the
        // numbers beside it so an owner can disagree with the threshold.
        if ($share >= 20.0) {
            $decaying[] = [
                'list_id' => $id,
                'name' => $name,
                'subscribed' => $subscribed,
                'unconfirmed' => (int) $row['unconfirmed'],
                'bounced' => (int) $row['bounced'],
                'unreachable_share_percent' => $share,
            ];
        }
    }

    if ($empty !== []) {
        $act_on[] = sprintf(
            /* translators: %d: number of lists */
            __('%d list has nobody subscribed to it. Either it is finished with, or a form is pointing somewhere else.', 'nibwp'),
            count($empty)
        );
    }

    if ($stuck !== []) {
        $act_on[] = sprintf(
            /* translators: 1: number of campaigns, 2: number of hours */
            __('%1$d campaign has been sitting in the sending queue with recipients left and nothing touching it for over %2$d hours. That is usually the cron, not the campaign.', 'nibwp'),
            count($stuck),
            max(1, $hours)
        );
    }

    if ($decaying !== []) {
        $act_on[] = sprintf(
            /* translators: %d: number of lists */
            __('%d list has a fifth or more of its records bounced or never confirmed, which is a list decaying and a deliverability problem building.', 'nibwp'),
            count($decaying)
        );
    }

    return [
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
        'empty_lists' => $empty,
        'stuck_in_queue' => $stuck,
        'decaying_lists' => $decaying,
        'stuck_threshold_hours' => max(1, $hours),
        'note' => __('Nothing here changes anything. An empty list is a judgement call, a stuck queue is usually WordPress\'s scheduler rather than MailPoet, and a decaying list is fixed by removing records rather than by sending to them harder.', 'nibwp'),
    ];
}

/**
 * List id => name, bounded.
 *
 * @return array<int, string>
 */
function nibwp_mailpoet_list_names(): array
{
    if (nibwp_mailpoet_db() instanceof WP_Error) {
        return [];
    }

    $table = nibwp_mailpoet_table('segments');

    if ($table === '') {
        return [];
    }

    global $wpdb;

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, name FROM ' . $table . ' WHERE type = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT %d',
            'default',
            NIBWP_MAILPOET_MAX_ROWS
        ),
        ARRAY_A
    );

    $out = [];

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id'], $row['name']) && is_scalar($row['name'])) {
            $out[(int) $row['id']] = (string) $row['name'];
        }
    }

    return $out;
}

/* ------------------------------------------------------------- the test send */

/**
 * Send one real email to one address, through MailPoet's configured method.
 *
 * This is the only thing in this file that puts mail in front of a person. It is
 * one address, named by the caller, and it is not a campaign: nothing about it
 * reaches a list.
 *
 * MailPoet gates its own "test this sending method" on the settings capability
 * (lib/API/JSON/v1/Mailer.php:38-43), which is the same bar applied here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailpoet_send_test_email(array $input): array|WP_Error
{
    if ($denied = nibwp_mailpoet_require(__('Sending a MailPoet test email', 'nibwp'), NIBWP_MAILPOET_CAP_SETTINGS)) {
        return $denied;
    }

    $address = isset($input['email']) && is_scalar($input['email']) ? trim((string) $input['email']) : '';

    if ($address === '' || !is_email($address)) {
        return nibwp_mailpoet_err('bad_email', __('Give one valid recipient as email. This sends real mail, so the address matters.', 'nibwp'), 422);
    }

    if ($denied = nibwp_mailpoet_confirmed($input, __('Sending a real email to that address', 'nibwp'))) {
        return $denied;
    }

    $subject = isset($input['subject']) && is_scalar($input['subject'])
        ? sanitize_text_field((string) $input['subject'])
        : __('MailPoet test from NibWP', 'nibwp');

    $body = isset($input['body']) && is_scalar($input['body'])
        ? sanitize_textarea_field((string) $input['body'])
        : __('This is a test of this site\'s MailPoet sending method. If it arrived, sending works.', 'nibwp');

    $factory = nibwp_mailpoet_service('MailPoet\\Mailer\\MailerFactory');

    if (!method_exists($factory, 'getDefaultMailer')) {
        return nibwp_mailpoet_err('no_mailer_api', __('This version of MailPoet does not expose its mailer where this integration can use it.', 'nibwp'), 500);
    }

    $mailer = $factory->getDefaultMailer();

    if (!is_object($mailer) || !method_exists($mailer, 'send')) {
        return nibwp_mailpoet_err('no_mailer_api', __('MailPoet returned no usable mailer for the configured sending method.', 'nibwp'), 500);
    }

    // The shape Mailer::send() expects (lib/Mailer/Methods/PHPMailerMethod.php:75-84).
    $result = $mailer->send(
        [
            'subject' => $subject,
            'body' => ['text' => $body, 'html' => '<p>' . esc_html($body) . '</p>'],
        ],
        ['email' => $address]
    );

    $result = is_array($result) ? $result : [];
    $sent = !empty($result['response']);
    $failure = null;

    if (!$sent && isset($result['error']) && is_object($result['error']) && method_exists($result['error'], 'getMessage')) {
        $failure = (string) $result['error']->getMessage();
    }

    return [
        'sent' => $sent,
        'to' => $address,
        'subject' => $subject,
        'failure' => $failure,
        'note' => $sent
            ? __('A real email has been delivered to that address. Say the address back to whoever asked. Nothing was sent to any list.', 'nibwp')
            : __('MailPoet could not send it, and the reason above is MailPoet\'s own. Nothing was sent to any list either way.', 'nibwp'),
    ];
}
