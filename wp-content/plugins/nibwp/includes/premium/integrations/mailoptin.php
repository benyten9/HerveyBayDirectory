<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: MailOptin.
 *
 * Verified against MailOptin 1.2.78.4 (mailoptin.php:19).
 *
 * WHERE THINGS LIVE, because almost none of it is where a WordPress developer
 * would guess:
 *
 *   - optin campaigns are NOT a post type. There is no register_post_type() call
 *     anywhere in the plugin. A campaign is a row in {prefix}mo_optin_campaigns
 *     with id, name, uuid, optin_class and optin_type
 *     (RegisterActivation/CreateDBTables.php:42-53, table name at Core.php:8);
 *   - a campaign's SETTINGS are not in that row and not in any meta table. Every
 *     campaign's whole configuration lives in ONE wp_options row, `mo_optin_campaign`,
 *     as a nested array keyed by campaign id (Base.php:45,
 *     OptinCampaignsRepository.php:526, :649). "Enabled" is the `activate_optin`
 *     key inside it being boolean true (:700) — the `activated` column on the row
 *     is vestigial and is not what the front end reads;
 *   - leads are rows in {prefix}mo_conversions: name, email, user_agent,
 *     conversion_page, referrer, date_added (CreateDBTables.php:55-67) plus
 *     custom_fields and meta_data added later (DBUpdates.php:203, :261);
 *   - statistics are two counters in {prefix}mo_optin_campaignmeta under the meta
 *     keys `mo_counter` (impressions) and `mo_conversions` (conversions)
 *     (OptinCampaignStat.php:19, :26). They are LIFETIME totals with no dates on
 *     them, which is why there is no "impressions last 30 days" action here;
 *   - email campaigns are rows in {prefix}mo_email_campaigns, and what was
 *     actually sent is logged in {prefix}mo_campaign_log (CreateDBTables.php:69-90).
 *
 * CREDENTIALS: every email-service credential this plugin holds lives in ONE flat
 * wp_options array, `mailoptin_connections` (Base.php:42, PluginSettings/Connections.php:38)
 * — `mailchimp_api_key`, `hubspot_access_token`, `salesforce_consumer_secret`,
 * `aweber_access_token_secret`, `icontact_password` and roughly ninety more, one
 * per service, with eighty-odd services in the box and more every release. That is
 * precisely why what may leave here is an ALLOW-LIST of six non-secret keys and
 * nothing else. A denylist would have to be right about a key name that does not
 * exist yet. Every other key is reported as a name and whether it is set. The
 * plugin's own licence key (`mo_license_key`, LicenseUpgrader.php:309) is reported
 * the same way.
 *
 * NO NETWORK. Each connection class has an is_connected() that calls the service
 * over the wire and caches the answer (AbstractMailChimpConnect.php:34-67 opens a
 * curl client and calls getAccount()). Nothing here calls it. "Configured" here
 * means the credential key is present locally; whether the service would actually
 * accept it is only knowable remotely, and the responses say so rather than
 * reaching out from inside a tool call.
 *
 * WHAT IS NOT HERE, AND WILL NOT BE: anything that sends a newsletter or a
 * broadcast. MailOptin's email-campaign side exists to mail lists of real people,
 * and mail cannot be recalled. Reading what newsletters exist and what the log
 * says went out is useful; pressing send from a tool call is not an agent's
 * decision. A check parses the enum and fails if an action that reads like a send
 * ever appears, so a later edit has to argue with the test.
 *
 * LEADS ARE PEOPLE. Every lead row is a name and an email address somebody typed
 * into a form, so reading them takes the capability MailOptin gates its own Leads
 * screen on — manage_mailoptin where the site defines it, manage_options otherwise
 * (LeadBank.php:36 calling get_capability(), functions.php:351-358). An allow-list
 * governs which lead columns may leave, every listing is bounded, and a value that
 * is not a scalar is DROPPED rather than cast, because custom_fields and meta_data
 * are whatever a form was configured to collect and a filtered row can hand back
 * an array under a column name that looks innocent.
 */

/* ---------------------------------------------------------------- constants */

/**
 * The capability MailOptin itself gates every one of its own screens on.
 *
 * get_capability() returns 'manage_mailoptin' when the current user has it and
 * falls back to 'manage_options' (functions.php:351-358), and the Leads screen is
 * registered with exactly that (LeadBank.php:36). So the narrower capability is
 * the real answer on a site that grants it, and this follows the plugin rather
 * than flattening everything to manage_options.
 */
const NIBWP_MAILOPTIN_CAP = 'manage_mailoptin';

/** The bar MailOptin falls back to, and the boundary on anything irreversible. */
const NIBWP_MAILOPTIN_CAP_ADMIN = 'manage_options';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_MAILOPTIN_MAX_ROWS = 200;

/** Default rows when a caller does not say. */
const NIBWP_MAILOPTIN_DEFAULT_ROWS = 50;

/** The longest window any period query will look back over. */
const NIBWP_MAILOPTIN_MAX_DAYS = 365;

/* -------------------------------------------------------------------- guard */

/**
 * Is MailOptin actually loaded?
 *
 * MAILOPTIN_VERSION_NUMBER is defined by the main plugin file before anything
 * boots (mailoptin.php:19), and MailOptin\Core\Core is the class whose init()
 * builds the rest (mailoptin.php:21). Between them they tell "not installed" apart
 * from "installed but did not finish loading", and both are refusals rather than
 * fatals.
 */
function nibwp_mailoptin_guard(): ?WP_Error
{
    if (!defined('MAILOPTIN_VERSION_NUMBER') && !class_exists('MailOptin\\Core\\Core')) {
        return new WP_Error(
            'nibwp_mailoptin_missing',
            __('MailOptin is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/* ------------------------------------------------------------------ refusals */

/** A refusal about authority rather than about the request. */
function nibwp_mailoptin_forbidden(string $what, string $capability): WP_Error
{
    return new WP_Error(
        'nibwp_mailoptin_forbidden',
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
 * The capability gate MailOptin's own screens use.
 *
 * Mirrors get_capability() (functions.php:351-358): the narrower manage_mailoptin
 * satisfies it, and manage_options does too because that is what the plugin falls
 * back to. The refusal names manage_mailoptin, since that is the capability a site
 * should be granting to whoever is meant to do this.
 */
function nibwp_mailoptin_require(string $what): ?WP_Error
{
    if (current_user_can(NIBWP_MAILOPTIN_CAP) || current_user_can(NIBWP_MAILOPTIN_CAP_ADMIN)) {
        return null;
    }

    return nibwp_mailoptin_forbidden($what, NIBWP_MAILOPTIN_CAP);
}

/**
 * The boundary on anything irreversible or bulk.
 *
 * A site can hand manage_mailoptin to whoever runs its popups. Destroying a
 * campaign, destroying a lead, or pulling a block of leads out in one response is
 * a different kind of act, so those sit behind manage_options as well. Both gates
 * refuse with the same error code and each names its own capability in the
 * message, which is what lets a caller — and the check — tell which door closed.
 */
function nibwp_mailoptin_require_admin(string $what): ?WP_Error
{
    return current_user_can(NIBWP_MAILOPTIN_CAP_ADMIN)
        ? null
        : nibwp_mailoptin_forbidden($what, NIBWP_MAILOPTIN_CAP_ADMIN);
}

/** A refusal about the request itself. */
function nibwp_mailoptin_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_mailoptin_' . $code, $message, ['status' => $status]);
}

/* -------------------------------------------------------------- plugin state */

/**
 * One of MailOptin's table names.
 *
 * The name is matched against a fixed map, so a table identifier can never come
 * from input — identifiers cannot be bound by prepare() on any supported
 * WordPress, and this is the alternative to hoping. Where the plugin is loaded its
 * own class constants win, so a build that renamed one still works.
 */
function nibwp_mailoptin_table(string $name): string
{
    $known = [
        'campaigns' => ['mo_optin_campaigns', 'optin_campaigns_table_name'],
        'campaign_meta' => ['mo_optin_campaignmeta', 'optin_campaign_meta_table_name'],
        'leads' => ['mo_conversions', 'conversions_table_name'],
        'email_campaigns' => ['mo_email_campaigns', 'email_campaigns_table_name'],
        'email_log' => ['mo_campaign_log', 'campaign_log_table_name'],
    ];

    if (!isset($known[$name])) {
        return '';
    }

    global $wpdb;

    if (!is_object($wpdb) || !isset($wpdb->prefix) || !is_string($wpdb->prefix)) {
        return '';
    }

    $suffix = $known[$name][0];
    $constant = 'MailOptin\\Core\\Core::' . $known[$name][1];

    if (class_exists('MailOptin\\Core\\Core') && defined($constant)) {
        $declared = constant($constant);

        if (is_string($declared) && $declared !== '') {
            $suffix = $declared;
        }
    }

    return $wpdb->prefix . $suffix;
}

/**
 * One of MailOptin's option names.
 *
 * Same reasoning as the table map: a fixed set, the plugin's own constant when it
 * is defined, and the documented default otherwise.
 */
function nibwp_mailoptin_option_name(string $name): string
{
    $known = [
        'campaign_settings' => ['mo_optin_campaign', 'MO_OPTIN_CAMPAIGN_WP_OPTION_NAME'],
        'email_campaign_settings' => ['mo_email_campaigns', 'MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME'],
        'connections' => ['mailoptin_connections', 'MAILOPTIN_CONNECTIONS_DB_OPTION_NAME'],
        'license' => ['mo_license_key', ''],
    ];

    if (!isset($known[$name])) {
        return '';
    }

    $constant = $known[$name][1];

    if ($constant !== '' && defined($constant)) {
        $declared = constant($constant);

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }
    }

    return $known[$name][0];
}

/**
 * A usable $wpdb, or a refusal.
 *
 * Everything here reads MailOptin's own tables, because the plugin has no public
 * API: its repositories are static classes with no stability promise, and the one
 * listing method it does expose for leads reads $_POST['s'] itself and has no
 * default LIMIT (OptinConversionsRepository.php:170-216). Prepared statements with
 * our own bound LIMIT are the better of two second choices, named as such.
 */
function nibwp_mailoptin_db(): ?WP_Error
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
        return nibwp_mailoptin_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    return null;
}

/**
 * Every campaign's settings, as the one option MailOptin keeps them in.
 *
 * @return array<int|string, mixed>
 */
function nibwp_mailoptin_all_settings(): array
{
    $option = nibwp_mailoptin_option_name('campaign_settings');

    if ($option === '') {
        return [];
    }

    $settings = get_option($option, []);

    return is_array($settings) ? $settings : [];
}

/**
 * One campaign's settings.
 *
 * @return array<string, mixed>
 */
function nibwp_mailoptin_settings_for(int $campaign_id): array
{
    $all = nibwp_mailoptin_all_settings();
    $own = $all[$campaign_id] ?? null;

    return is_array($own) ? $own : [];
}

/**
 * Is a campaign live on the front end?
 *
 * The `activated` column on the campaign row is not what decides this — the
 * `activate_optin` key in the settings option is, and only when it is boolean true
 * (OptinCampaignsRepository.php:700-705).
 */
function nibwp_mailoptin_is_enabled(int $campaign_id): bool
{
    $settings = nibwp_mailoptin_settings_for($campaign_id);

    return ($settings['activate_optin'] ?? null) === true;
}

/**
 * A static method on one of MailOptin's repositories, or a refusal.
 *
 * Writes go through the plugin's own repository rather than our SQL, so that cache
 * bursting and its own bookkeeping happen. When the class or the method is not
 * there, that is a refusal naming what is missing — never a fatal.
 */
function nibwp_mailoptin_repo_missing(string $class, string $method): ?WP_Error
{
    if (class_exists($class) && method_exists($class, $method)) {
        return null;
    }

    return nibwp_mailoptin_err(
        'no_plugin_api',
        sprintf(
            /* translators: 1: class name, 2: method name */
            __('This version of MailOptin does not provide %1$s::%2$s(), so this operation cannot be performed safely from here. Use MailOptin\'s own screens.', 'nibwp'),
            $class,
            $method
        ),
        500
    );
}

/* ----------------------------------------------------------------- shaping */

/** Clamp a row count that arrived over the wire. */
function nibwp_mailoptin_limit(mixed $value): int
{
    $limit = is_numeric($value) ? (int) $value : NIBWP_MAILOPTIN_DEFAULT_ROWS;

    return max(1, min(NIBWP_MAILOPTIN_MAX_ROWS, $limit));
}

/** Clamp an offset. */
function nibwp_mailoptin_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** Clamp a look-back window in days. */
function nibwp_mailoptin_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_MAILOPTIN_MAX_DAYS, $days));
}

/**
 * Lead columns that may leave this hub, and nothing else.
 *
 * An allow-list because the shape is not fixed: the table has already grown
 * custom_fields and meta_data after the fact (DBUpdates.php:203, :261), and both
 * are blobs of whatever a particular form was configured to collect. A release
 * that adds one more column would default to leaking it.
 *
 * Deliberately absent, each for its own reason:
 *   - custom_fields — arbitrary extra form fields, contents unknown by definition;
 *   - meta_data — the same, for whatever an add-on decided to stash;
 *   - user_agent — a device fingerprint for a named person, and no question worth
 *     asking needs it.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_lead_fields(): array
{
    return [
        'id',
        'optin_id',
        'optin_type',
        'name',
        'email',
        'conversion_page',
        'referrer',
        'date_added',
    ];
}

/**
 * Lead columns that must never travel, however they are asked for.
 *
 * The allow-list above already governs what leaves; this exists so a name landing
 * in both lists is a loud failure rather than a quiet leak, which the check
 * asserts.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_lead_secret_fields(): array
{
    return ['custom_fields', 'meta_data', 'user_agent'];
}

/**
 * One lead row, trimmed to what may leave.
 *
 * A value that is not a scalar is DROPPED rather than cast or encoded. $wpdb hands
 * back strings, but `mo_` filters sit on these reads inside the plugin and an
 * array under an allow-listed column name is exactly how a bundle of extra
 * captured fields would travel out looking tidy. json_encode()ing it would carry
 * it while appearing to sanitise.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_mailoptin_lead_shape(array $raw): array
{
    $out = [];

    foreach (nibwp_mailoptin_lead_fields() as $field) {
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

        $out[$field] = in_array($field, ['id', 'optin_id'], true) ? (int) $value : $value;
    }

    $out['custom_fields_returned'] = false;

    return $out;
}

/**
 * One campaign row, trimmed.
 *
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function nibwp_mailoptin_campaign_shape(array $raw): array
{
    $id = isset($raw['id']) && is_scalar($raw['id']) ? (int) $raw['id'] : 0;

    $out = ['id' => $id];

    foreach (['name', 'uuid', 'optin_class', 'optin_type'] as $field) {
        $value = $raw[$field] ?? null;
        $out[$field] = is_scalar($value) ? (string) $value : null;
    }

    $out['enabled'] = nibwp_mailoptin_is_enabled($id);

    return $out;
}

/**
 * The display-rule and targeting settings MailOptin defines, grouped as its own
 * customizer groups them.
 *
 * Taken from display_rules_settings() (OptinForm/CustomizerSettings.php:654-806)
 * and the device-targeting reads in OptinCampaignsRepository.php:43-61, so this is
 * the plugin's vocabulary rather than a guess at it.
 *
 * @return array<string, array<int, string>>
 */
function nibwp_mailoptin_rule_groups(): array
{
    return [
        'trigger' => [
            'exit_intent_status',
            'x_seconds_status',
            'x_seconds_value',
            'x_scroll_status',
            'x_scroll_value',
            'x_page_views_status',
            'x_page_views_condition',
            'x_page_views_value',
            'schedule_status',
            'schedule_start',
            'schedule_end',
            'schedule_timezone',
        ],
        'page_targeting' => [
            'load_optin_globally',
            'load_optin_index',
            'exclusive_post_types_load',
            'exclusive_post_types_posts_load',
            'cpt_never_load',
            'pages_never_load',
            'posts_never_load',
            'post_categories_load',
            'post_categories_hide',
            'post_tags_load',
        ],
        'query_targeting' => [
            'filter_query_string',
            'filter_query_value',
            'filter_query_action',
        ],
        'user_targeting' => [
            'who_see_optin',
            'show_to_roles',
        ],
        'device_targeting' => [
            'device_targeting_hide_desktop',
            'device_targeting_hide_tablet',
            'device_targeting_hide_mobile',
        ],
    ];
}

/**
 * Display-rule settings a caller may change, and the shape each one takes.
 *
 * Short on purpose. These are the switches somebody asks about when they ask why a
 * popup is or is not showing. Everything else about a campaign is design work that
 * belongs in MailOptin's customizer, where it can be seen.
 *
 * @return array<string, string>
 */
function nibwp_mailoptin_writable_rules(): array
{
    return [
        'exit_intent_status' => 'bool',
        'x_seconds_status' => 'bool',
        'x_seconds_value' => 'int',
        'x_scroll_status' => 'bool',
        'x_scroll_value' => 'int',
        'x_page_views_status' => 'bool',
        'x_page_views_value' => 'int',
        'schedule_status' => 'bool',
        'load_optin_globally' => 'bool',
        'load_optin_index' => 'bool',
    ];
}

/**
 * A display-rule value as it may be reported.
 *
 * Unlike a lead field, an array here is the answer rather than a risk: the page
 * and category targeting rules ARE lists of post ids the site itself chose. So an
 * array is rebuilt from its scalar members, bounded, and anything deeper is
 * dropped — the bound matters because these lists come from a settings screen with
 * no limit on how many pages somebody can tick.
 */
function nibwp_mailoptin_rule_value(mixed $value): mixed
{
    if ($value === null || is_scalar($value)) {
        return $value;
    }

    if (!is_array($value)) {
        return null;
    }

    $out = [];

    foreach ($value as $member) {
        if (is_scalar($member)) {
            $out[] = $member;
        }

        if (count($out) >= NIBWP_MAILOPTIN_MAX_ROWS) {
            break;
        }
    }

    return $out;
}

/** Does a value count as a rule actually being set? */
function nibwp_mailoptin_rule_is_set(mixed $value): bool
{
    if ($value === null || $value === '' || $value === false || $value === '0' || $value === 0) {
        return false;
    }

    return !is_array($value) || $value !== [];
}

/**
 * The only keys in `mailoptin_connections` whose VALUE may be reported.
 *
 * Six keys, each of which is a region, a sending domain or a from-address — facts
 * about configuration that cannot be used to authenticate as anybody. Everything
 * else in that option is a name and an is-set flag, because the option is one flat
 * key space shared by every service the plugin supports (PluginSettings/Connections.php:23-26)
 * and a denylist would have to be right about the credential key the next release
 * invents.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_connection_public_keys(): array
{
    return [
        'mailgun_domain_name',
        'mailgun_domain_region',
        'orttocrm_region',
        'fbca_api_version',
        'egoi_sender',
        'sendgrid_sender',
    ];
}

/**
 * Substrings that mark a connection key as a credential.
 *
 * NOT a denylist standing in for the allow-list above — the allow-list is what
 * decides whether a value travels. This only labels the keys in the inventory, so
 * "eleven credentials set across four services" reads as something an owner can
 * act on. A key nobody recognised is still reported as set, with its value
 * withheld, because the allow-list and not this list is the gate.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_credential_markers(): array
{
    return [
        'api_key',
        'apikey',
        'api_token',
        'api_secret',
        'access_token',
        'refresh_token',
        'client_secret',
        'consumer_secret',
        'secret_key',
        'publishable_key',
        'private_key',
        'auth_token',
        'password',
        'token',
        'secret',
    ];
}

/** Does this connection key name a credential? */
function nibwp_mailoptin_is_credential_key(string $key): bool
{
    foreach (nibwp_mailoptin_credential_markers() as $marker) {
        if (str_contains($key, $marker)) {
            return true;
        }
    }

    return false;
}

/**
 * The whole connections option, as names and is-set flags.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_mailoptin_connection_inventory(): array
{
    $option = nibwp_mailoptin_option_name('connections');
    $stored = $option === '' ? [] : get_option($option, []);

    if (!is_array($stored)) {
        return [];
    }

    $public = nibwp_mailoptin_connection_public_keys();
    $rows = [];

    foreach ($stored as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $row = [
            'key' => $key,
            'is_credential' => nibwp_mailoptin_is_credential_key($key),
            'is_set' => is_array($value) ? $value !== [] : ($value !== null && (string) $value !== ''),
            'value' => null,
        ];

        // The allow-list decides, and it decides on the key name alone. A value
        // only travels when its key was named there on purpose, and only when it
        // is a scalar — an array under an allow-listed key is dropped.
        if (in_array($key, $public, true) && is_scalar($value)) {
            $row['value'] = $value;
        }

        $rows[] = $row;

        if (count($rows) >= NIBWP_MAILOPTIN_MAX_ROWS) {
            break;
        }
    }

    return $rows;
}

/**
 * The credential-key prefix for a connection name.
 *
 * A connection class is named like MailChimpConnect and its keys are prefixed
 * `mailchimp_` (AbstractMailChimpConnect.php:36), so the prefix is derived from
 * the name rather than kept in a second list that would drift out of step.
 */
function nibwp_mailoptin_connection_prefix(string $service): string
{
    $name = preg_replace('/Connect$/', '', $service);

    return strtolower(is_string($name) ? $name : $service);
}

/**
 * Does the site hold any credential for this connection?
 *
 * Local state only. Whether the service would accept it is a question for the
 * service, and this hub does not make network requests.
 *
 * @param array<int, array<string, mixed>> $inventory
 */
function nibwp_mailoptin_connection_has_credential(string $service, array $inventory): bool
{
    $prefix = nibwp_mailoptin_connection_prefix($service);

    if ($prefix === '') {
        return false;
    }

    foreach ($inventory as $row) {
        $key = (string) ($row['key'] ?? '');

        if (str_starts_with($key, $prefix . '_') && !empty($row['is_set'])) {
            return true;
        }
    }

    return false;
}

/* ------------------------------------------------------------- the actions */

/**
 * The actions this hub offers.
 *
 * There is no action that sends a newsletter or a broadcast, and there will not
 * be. A check parses this list and fails if one appears.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_actions(): array
{
    return [
        // Optin campaigns.
        'list_campaigns',
        'get_campaign',
        'create_campaign',
        'rename_campaign',
        'enable_campaign',
        'disable_campaign',
        'delete_campaign',
        // Display rules and targeting.
        'get_display_rules',
        'set_display_rule',
        // Leads. Personal data, every one of them.
        'search_leads',
        'get_lead',
        'count_leads',
        'export_leads',
        'delete_lead',
        // Connected email services.
        'list_connections',
        'campaign_connections',
        // Statistics.
        'campaign_stats',
        'stats_leaderboard',
        'conversions_over_period',
        // Email campaigns, read-only.
        'list_email_campaigns',
        'get_email_campaign',
        'email_campaign_log',
        // Judgement.
        'optin_audit',
    ];
}

/**
 * The actions that destroy something that cannot be rebuilt.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_irreversible(): array
{
    return ['delete_campaign', 'delete_lead'];
}

wp_register_ability('nibwp/mailoptin-manage', [
    'label' => __('MailOptin – Optin Campaigns, Leads and Conversions', 'nibwp'),
    'description' => 'Manage MailOptin on this site: optin campaigns and their display rules, the leads they captured, which email service each campaign feeds, '
        . 'impression and conversion statistics, the newsletters and automated emails this build has, '
        . 'plus an audit that names campaigns shown to everybody that convert nobody, campaigns with no connected list so their leads go nowhere, '
        . 'campaigns with no display rules at all, and a connection whose credentials are missing. '
        . 'There is deliberately NO action that sends a newsletter or a broadcast — that stays in wp-admin where the recipient count is on screen. '
        . 'Enabling or disabling a campaign changes what every visitor to the site sees, immediately. '
        . 'Deleting a campaign or a lead is irreversible; both need confirm: true and manage_options, and deleting a campaign leaves its captured leads behind rather than removing them. '
        . 'No email-service API key, OAuth token or licence key is ever returned — those come back as a name and whether they are set. '
        . 'Nothing here contacts an email service: it reads this site only.',
    'category' => 'marketing',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_mailoptin_actions(),
                'description' => 'Which operation to run. Start with optin_audit or list_campaigns.',
            ],
            'campaign_id' => ['type' => 'integer', 'description' => 'An optin campaign id.'],
            'lead_id' => ['type' => 'integer', 'description' => 'A lead (conversion) id.'],
            'email_campaign_id' => ['type' => 'integer', 'description' => 'An email campaign id, for the email-campaign actions.'],
            'name' => ['type' => 'string', 'description' => 'For create_campaign / rename_campaign: the campaign name. It has to be unique.'],
            'optin_type' => ['type' => 'string', 'description' => 'For create_campaign: lightbox, sidebar, inpost, bar or slidein.'],
            'optin_class' => ['type' => 'string', 'description' => 'For create_campaign: the theme class, e.g. BareMetal. list_campaigns shows what this site already uses.'],
            'search' => ['type' => 'string', 'description' => 'For search_leads and export_leads: matched against lead name and email.'],
            'rule' => ['type' => 'string', 'description' => 'For set_display_rule: which rule to change. get_display_rules lists the writable ones.'],
            'value' => ['description' => 'For set_display_rule: the new setting, a boolean or a whole number depending on the rule.'],
            'days' => ['type' => 'integer', 'description' => 'Look-back window in days. Default 30, max 365.'],
            'min_impressions' => ['type' => 'integer', 'description' => 'For optin_audit and stats_leaderboard: how many impressions a campaign needs before a zero conversion count counts as a finding. Default 50.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true on delete_campaign and delete_lead.'],
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
    'execute_callback' => 'nibwp_mailoptin_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'MailOptin. Popups and optin forms, the leads they captured, and where those leads go.',
                '',
                'START WITH optin_audit when the question is "why is this not working".',
                'It puts the four things an owner should act on into one response: campaigns that',
                'are live and have been seen but have converted nobody, campaigns with no connected',
                'email list so their leads only ever land in MailOptin\'s own lead bank, campaigns',
                'with no display rules at all so they show to everybody on every page, and a',
                'connection a campaign points at whose credentials are not stored on this site.',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'It cannot send a newsletter or a broadcast, or schedule one. Do not look for',
                'another action that does; there is not one. Mail to real people cannot be',
                'recalled, so that is a decision for a person looking at the recipient count in',
                'wp-admin, and saying so is the correct answer when asked.',
                '',
                'VISIBLE TO EVERY VISITOR:',
                'enable_campaign and disable_campaign change what every visitor to the site sees,',
                'from the next page load. set_display_rule does too. Say which campaign changed and',
                'what it now does, every time.',
                '',
                'IRREVERSIBLE:',
                '- delete_campaign removes the campaign row and its whole configuration. Its',
                '  captured leads are NOT removed with it: they stay in the lead bank still tagged',
                '  with the deleted campaign\'s id, which means they become unattributable rather',
                '  than deleted. If the intent is "stop showing it", use disable_campaign, which',
                '  is reversible.',
                '- delete_lead destroys one person\'s record. There is no trash and no undo.',
                'Both need confirm: true and both need manage_options.',
                '',
                'LEADS ARE PERSONAL DATA. A lead is a name and an email address somebody typed into',
                'a form. Reading them takes the capability MailOptin gates its own Leads screen on.',
                'Extra fields a form collected are never returned, because what is in them is',
                'whatever that form was set up to ask for. Quote back only what was asked for.',
                '',
                'CREDENTIALS: list_connections reports which credential keys are stored and whether',
                'they are set, never their values, and nothing here changes one. The plugin licence',
                'key is treated the same way.',
                '',
                'NO NETWORK: nothing here contacts an email service. "Configured" means the',
                'credential is stored on this site. Whether the service would still accept it is',
                'only knowable by asking the service, which is a thing to say rather than to do.',
                '',
                'STATISTICS, HONESTLY: impressions and conversions are lifetime counters with no',
                'dates attached, so there is no "impressions last month" and no conversion rate for',
                'a window. conversions_over_period counts captured leads by date, which is the one',
                'thing this build really can answer over a period, and campaign_stats says so',
                'rather than dividing a windowed count by a lifetime total.',
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
function nibwp_mailoptin_manage(array $input): array|WP_Error
{
    // The plugin check is first. Without MailOptin loaded there are no tables to
    // read, and a capability refusal would be a lie about why the call failed.
    $guard = nibwp_mailoptin_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) && is_scalar($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_mailoptin_actions(), true)) {
        return nibwp_mailoptin_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown MailOptin action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_mailoptin_actions())
            )
        );
    }

    // MailOptin's repositories are static classes that reach the database directly
    // and raise on a schema they did not expect. One catch here turns any of that
    // into a named refusal rather than a fatal that takes the whole REST request
    // down with it.
    try {
        return nibwp_mailoptin_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_mailoptin_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from MailOptin */
                __('MailOptin could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_mailoptin_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'list_campaigns' => nibwp_mailoptin_list_campaigns($input),
        'get_campaign' => nibwp_mailoptin_get_campaign($input),
        'create_campaign' => nibwp_mailoptin_create_campaign($input),
        'rename_campaign' => nibwp_mailoptin_rename_campaign($input),
        'enable_campaign' => nibwp_mailoptin_set_campaign_enabled($input, true),
        'disable_campaign' => nibwp_mailoptin_set_campaign_enabled($input, false),
        'delete_campaign' => nibwp_mailoptin_delete_campaign($input),
        'get_display_rules' => nibwp_mailoptin_get_display_rules($input),
        'set_display_rule' => nibwp_mailoptin_set_display_rule($input),
        'search_leads' => nibwp_mailoptin_search_leads($input),
        'get_lead' => nibwp_mailoptin_get_lead($input),
        'count_leads' => nibwp_mailoptin_count_leads($input),
        'export_leads' => nibwp_mailoptin_export_leads($input),
        'delete_lead' => nibwp_mailoptin_delete_lead($input),
        'list_connections' => nibwp_mailoptin_list_connections($input),
        'campaign_connections' => nibwp_mailoptin_campaign_connections($input),
        'campaign_stats' => nibwp_mailoptin_campaign_stats($input),
        'stats_leaderboard' => nibwp_mailoptin_stats_leaderboard($input),
        'conversions_over_period' => nibwp_mailoptin_conversions_over_period($input),
        'list_email_campaigns' => nibwp_mailoptin_list_email_campaigns($input),
        'get_email_campaign' => nibwp_mailoptin_get_email_campaign($input),
        'email_campaign_log' => nibwp_mailoptin_email_campaign_log($input),
        'optin_audit' => nibwp_mailoptin_audit($input),
        default => nibwp_mailoptin_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* ------------------------------------------------------------ input helpers */

/** A campaign id from input, as a positive integer. */
function nibwp_mailoptin_campaign_id(mixed $raw): int|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_mailoptin_err('missing_campaign_id', __('Give the campaign as campaign_id, a positive integer.', 'nibwp'), 422);
    }

    return $id;
}

/** A lead id from input. */
function nibwp_mailoptin_lead_id(mixed $raw): int|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_mailoptin_err('missing_lead_id', __('Give the lead as lead_id, a positive integer.', 'nibwp'), 422);
    }

    return $id;
}

/** An email campaign id from input. */
function nibwp_mailoptin_email_campaign_id(mixed $raw): int|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_mailoptin_err('missing_email_campaign_id', __('Give the email campaign as email_campaign_id, a positive integer.', 'nibwp'), 422);
    }

    return $id;
}

/** A required non-empty name from input. */
function nibwp_mailoptin_name(mixed $raw): string|WP_Error
{
    $name = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';

    if ($name === '') {
        return nibwp_mailoptin_err('missing_name', __('Give the name as name.', 'nibwp'), 422);
    }

    // The column is varchar(200) since update_routine_14 (DBUpdates.php:255), and
    // a longer name would be truncated by MySQL rather than refused.
    if (strlen($name) > 200) {
        return nibwp_mailoptin_err('bad_name', __('A campaign name cannot be longer than 200 characters.', 'nibwp'), 422);
    }

    return $name;
}

/** An explicit confirmation on anything that cannot be undone. */
function nibwp_mailoptin_confirmed(array $input, string $what): ?WP_Error
{
    if (!empty($input['confirm'])) {
        return null;
    }

    return nibwp_mailoptin_err(
        'confirm_required',
        sprintf(
            /* translators: %s: a description of the operation */
            __('%s cannot be undone. Pass confirm: true once whoever asked has said yes to that specifically.', 'nibwp'),
            $what
        ),
        422
    );
}

/**
 * The optin types MailOptin ships themes for.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_optin_types(): array
{
    return ['lightbox', 'sidebar', 'inpost', 'bar', 'slidein'];
}

/**
 * The theme classes registered on this install.
 *
 * defaultThemes() looks like the method to ask, but it assigns to a static and
 * returns nothing (OptinThemesRepository.php:10-18), so the two methods it merges
 * are asked instead. An empty result means "could not establish", and the caller
 * treats that as "accept what was given" rather than refusing a legitimate theme
 * an add-on registered.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_theme_classes(): array
{
    $classes = [];

    foreach (['free_themes', 'premium_themes'] as $method) {
        if (nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinThemesRepository', $method) instanceof WP_Error) {
            continue;
        }

        $themes = \MailOptin\Core\Repositories\OptinThemesRepository::$method();

        if (!is_array($themes)) {
            continue;
        }

        foreach ($themes as $theme) {
            if (is_array($theme) && isset($theme['optin_class']) && is_scalar($theme['optin_class'])) {
                $classes[] = (string) $theme['optin_class'];
            }
        }
    }

    return array_values(array_unique($classes));
}

/* ------------------------------------------------------------ the campaigns */

/**
 * Every optin campaign, with whether it is live and what it has achieved.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_list_campaigns(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading the MailOptin campaigns', 'nibwp'))) {
        return $denied;
    }

    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('campaigns');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s campaign table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $limit = nibwp_mailoptin_limit($input['limit'] ?? null);
    $offset = nibwp_mailoptin_offset($input['offset'] ?? null);

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, name, uuid, optin_class, optin_type FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $limit,
            $offset
        ),
        ARRAY_A
    );

    $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE %d = %d', 1, 1));
    $stats = nibwp_mailoptin_stat_counters();
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $shaped = nibwp_mailoptin_campaign_shape($row);
        $id = (int) $shaped['id'];
        $shaped['impressions'] = (int) ($stats[$id]['impressions'] ?? 0);
        $shaped['conversions'] = (int) ($stats[$id]['conversions'] ?? 0);
        $out[] = $shaped;

        if (count($out) >= $limit) {
            break;
        }
    }

    return [
        'total' => $total,
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'campaigns' => $out,
        'note' => __('"enabled" is what the front end actually reads: the activate_optin setting, not the activated column on the row. Impressions and conversions are lifetime counters with no dates on them.', 'nibwp'),
    ];
}

/**
 * One campaign, with its type, its settings and where its leads go.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_get_campaign(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading a MailOptin campaign', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $settings = nibwp_mailoptin_settings_for($id);
    $stats = nibwp_mailoptin_stat_counters();

    $service = isset($settings['connection_service']) && is_scalar($settings['connection_service'])
        ? (string) $settings['connection_service']
        : '';

    $shaped = nibwp_mailoptin_campaign_shape($row);
    $shaped['impressions'] = (int) ($stats[$id]['impressions'] ?? 0);
    $shaped['conversions'] = (int) ($stats[$id]['conversions'] ?? 0);

    return [
        'campaign' => $shaped,
        'test_mode' => ($settings['test_mode'] ?? null) === true,
        'connection' => [
            'service' => $service === '' ? null : $service,
            'email_list' => isset($settings['connection_email_list']) && is_scalar($settings['connection_email_list'])
                ? (string) $settings['connection_email_list']
                : null,
        ],
        'display_rules' => nibwp_mailoptin_rules_for($settings),
        'settings_keys' => nibwp_mailoptin_setting_key_names($settings),
        'note' => __('settings_keys is the list of setting names this campaign has, not their values: the rest of a campaign\'s configuration is design — copy, colours, images — and is best read and changed in MailOptin\'s own customizer where it can be seen.', 'nibwp'),
    ];
}

/**
 * One campaign row, or a refusal that says it is not there.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_campaign_row(int $id): array|WP_Error
{
    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('campaigns');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s campaign table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT id, name, uuid, optin_class, optin_type FROM ' . $table . ' WHERE id = %d', $id),
        ARRAY_A
    );

    if (!is_array($row) || $row === []) {
        return nibwp_mailoptin_err(
            'campaign_not_found',
            sprintf(
                /* translators: %d: campaign id */
                __('No MailOptin campaign with id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    return $row;
}

/**
 * The names of the settings a campaign has, bounded.
 *
 * @param array<string, mixed> $settings
 * @return array<int, string>
 */
function nibwp_mailoptin_setting_key_names(array $settings): array
{
    $names = [];

    foreach (array_keys($settings) as $key) {
        if (is_string($key)) {
            $names[] = $key;
        }

        if (count($names) >= NIBWP_MAILOPTIN_MAX_ROWS) {
            break;
        }
    }

    return $names;
}

/**
 * Create a campaign.
 *
 * The campaign is created DISABLED, whatever else is asked for. A new popup is a
 * thing every visitor sees, and a write made from a tool call should not be the
 * moment it starts appearing — enable_campaign is a separate decision with its own
 * description saying what it does.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_create_campaign(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Creating a MailOptin campaign', 'nibwp'))) {
        return $denied;
    }

    $name = nibwp_mailoptin_name($input['name'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $type = isset($input['optin_type']) && is_scalar($input['optin_type'])
        ? strtolower(trim((string) $input['optin_type']))
        : '';

    if (!in_array($type, nibwp_mailoptin_optin_types(), true)) {
        return nibwp_mailoptin_err(
            'bad_optin_type',
            sprintf(
                /* translators: %s: list of valid optin types */
                __('optin_type must be one of: %s.', 'nibwp'),
                implode(', ', nibwp_mailoptin_optin_types())
            ),
            422
        );
    }

    $class = isset($input['optin_class']) && is_scalar($input['optin_class'])
        ? sanitize_text_field((string) $input['optin_class'])
        : '';

    if ($class === '') {
        return nibwp_mailoptin_err('missing_optin_class', __('Give the theme as optin_class. list_campaigns shows which themes this site already uses.', 'nibwp'), 422);
    }

    $known = nibwp_mailoptin_theme_classes();

    if ($known !== [] && !in_array($class, $known, true)) {
        return nibwp_mailoptin_err(
            'bad_optin_class',
            sprintf(
                /* translators: %s: list of registered theme classes */
                __('optin_class must be a theme registered on this site: %s.', 'nibwp'),
                implode(', ', $known)
            ),
            422
        );
    }

    if ($missing = nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinCampaignsRepository', 'add_optin_campaign')) {
        return $missing;
    }

    // The name column is UNIQUE (CreateDBTables.php:50), so a duplicate would be a
    // silent false out of insert() rather than anything a caller could read.
    if (!(nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinCampaignsRepository', 'campaign_name_exist') instanceof WP_Error)) {
        if (\MailOptin\Core\Repositories\OptinCampaignsRepository::campaign_name_exist($name)) {
            return nibwp_mailoptin_err(
                'campaign_exists',
                __('A MailOptin campaign with that name already exists, and the name has to be unique. Pick another.', 'nibwp'),
                409
            );
        }
    }

    $uuid = nibwp_mailoptin_uuid();

    $created = \MailOptin\Core\Repositories\OptinCampaignsRepository::add_optin_campaign($uuid, $name, $class, $type);
    $id = is_numeric($created) ? (int) $created : 0;

    if ($id <= 0) {
        return nibwp_mailoptin_err('create_failed', __('MailOptin did not create the campaign and did not say why.', 'nibwp'), 500);
    }

    return [
        'created' => true,
        'campaign_id' => $id,
        'name' => $name,
        'optin_type' => $type,
        'optin_class' => $class,
        'uuid' => $uuid,
        'enabled' => false,
        'note' => __('The campaign exists but is NOT showing to anybody: it has no design, no connected email list and no display rules yet, and it was deliberately created disabled. Finish it in MailOptin\'s customizer, then enable_campaign when it should start appearing.', 'nibwp'),
    ];
}

/**
 * A ten-character uuid for a new campaign.
 *
 * MailOptin generates its own with AjaxHandler::generateUniqueId() (AjaxHandler.php:556),
 * and that is used when it is there so the format matches what the plugin expects
 * in a char(10) column. wp_generate_password without special characters is the
 * fallback, which is the same alphabet plus digits.
 */
function nibwp_mailoptin_uuid(): string
{
    if (!(nibwp_mailoptin_repo_missing('MailOptin\\Core\\AjaxHandler', 'generateUniqueId') instanceof WP_Error)) {
        $uuid = \MailOptin\Core\AjaxHandler::generateUniqueId();

        if (is_string($uuid) && $uuid !== '') {
            return substr($uuid, 0, 10);
        }
    }

    return substr((string) wp_generate_password(10, false, false), 0, 10);
}

/**
 * Rename a campaign.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_rename_campaign(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Renaming a MailOptin campaign', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $name = nibwp_mailoptin_name($input['name'] ?? null);
    if ($name instanceof WP_Error) {
        return $name;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    if ($missing = nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinCampaignsRepository', 'updateCampaignName')) {
        return $missing;
    }

    $before = isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : '';

    \MailOptin\Core\Repositories\OptinCampaignsRepository::updateCampaignName($name, $id);

    return [
        'renamed' => true,
        'campaign_id' => $id,
        'was' => $before,
        'now' => $name,
        'note' => __('A campaign name is internal — it is what the MailOptin screens and the shortcode list call it, not something a visitor sees.', 'nibwp'),
    ];
}

/**
 * Enable or disable a campaign.
 *
 * This is the one pair here whose effect is immediately visible to the public:
 * enabling means the popup or form starts appearing to every visitor the display
 * rules allow, from the next page load, and disabling means it stops. The response
 * says which campaign changed, what it was, and what it is now.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_set_campaign_enabled(array $input, bool $enable): array|WP_Error
{
    $what = $enable
        ? __('Enabling a MailOptin campaign, which starts showing it to visitors', 'nibwp')
        : __('Disabling a MailOptin campaign, which stops showing it to visitors', 'nibwp');

    if ($denied = nibwp_mailoptin_require($what)) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $method = $enable ? 'activate_campaign' : 'deactivate_campaign';

    if ($missing = nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinCampaignsRepository', $method)) {
        return $missing;
    }

    $was = nibwp_mailoptin_is_enabled($id);

    \MailOptin\Core\Repositories\OptinCampaignsRepository::$method($id);

    $rules = nibwp_mailoptin_rules_for(nibwp_mailoptin_settings_for($id));

    return [
        'campaign_id' => $id,
        'name' => isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : null,
        'was_enabled' => $was,
        'enabled' => $enable,
        'visible_to_visitors' => $enable,
        'rules_set' => (int) ($rules['rules_set'] ?? 0),
        'note' => $enable
            ? __('This campaign is now live. Every visitor the display rules allow will see it from the next page load, and if no display rules are set that means everybody on every page. Check get_display_rules if that was not the intent.', 'nibwp')
            : __('This campaign has stopped appearing to visitors from the next page load. Nothing was deleted: its design, its settings, its statistics and its captured leads are all still there, and enable_campaign puts it back.', 'nibwp'),
    ];
}

/**
 * Delete a campaign. Irreversible, and it does NOT take the leads with it.
 *
 * delete_optin_campaign() removes the row, unsets the campaign's entry in the
 * settings option, and deletes one meta key (OptinCampaignsRepository.php:840-852).
 * It does not touch {prefix}mo_conversions at all, so the leads captured by this
 * campaign stay in the lead bank still carrying the deleted id in optin_id. They
 * are not deleted — they become unattributable, which is worth saying out loud
 * because "deleting the campaign deletes the data" is the assumption everybody
 * makes, and acting on it would be wrong in both directions.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_delete_campaign(array $input): array|WP_Error
{
    // The boundary first, named separately from the plugin-level gate below,
    // because destroying a campaign is not the same kind of act as renaming one.
    if ($denied = nibwp_mailoptin_require_admin(__('Deleting a MailOptin campaign', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_mailoptin_require(__('Deleting a MailOptin campaign', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    if ($denied = nibwp_mailoptin_confirmed($input, __('Deleting a MailOptin campaign', 'nibwp'))) {
        return $denied;
    }

    if ($missing = nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinCampaignsRepository', 'delete_optin_campaign')) {
        return $missing;
    }

    $leads = nibwp_mailoptin_lead_count_for($id);

    \MailOptin\Core\Repositories\OptinCampaignsRepository::delete_optin_campaign($id);

    return [
        'deleted' => true,
        'campaign_id' => $id,
        'name' => isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : null,
        'leads_left_behind' => $leads,
        'note' => __('The campaign and its whole configuration are gone and cannot be restored. Its captured leads were NOT deleted with it: they are still in the lead bank, still tagged with this campaign id, which now matches nothing — so they are unattributable rather than removed. Delete them individually if that was the intent.', 'nibwp'),
    ];
}

/* ------------------------------------------------------- the display rules */

/**
 * The display rules a campaign has, grouped as MailOptin groups them.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function nibwp_mailoptin_rules_for(array $settings): array
{
    $groups = [];
    $set = 0;

    foreach (nibwp_mailoptin_rule_groups() as $group => $keys) {
        $values = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $values[$key] = nibwp_mailoptin_rule_value($settings[$key]);

            if (nibwp_mailoptin_rule_is_set($settings[$key])) {
                $set++;
            }
        }

        $groups[$group] = $values;
    }

    return [
        'groups' => $groups,
        'rules_set' => $set,
        'unrestricted' => $set === 0,
    ];
}

/**
 * Read a campaign's display rules and targeting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_get_display_rules(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading a MailOptin campaign\'s display rules', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $rules = nibwp_mailoptin_rules_for(nibwp_mailoptin_settings_for($id));

    return [
        'campaign_id' => $id,
        'enabled' => nibwp_mailoptin_is_enabled($id),
        'display_rules' => $rules,
        'writable' => array_keys(nibwp_mailoptin_writable_rules()),
        'note' => $rules['unrestricted']
            ? __('This campaign has no display rules set at all. If it is enabled, that means every visitor on every page with no trigger condition — which is usually not what somebody meant to build.', 'nibwp')
            : __('Changing any of these changes what visitors see. The writable list is short on purpose: the page, category and tag targeting lists are easier to get right in MailOptin\'s own customizer, where the site\'s content is on screen.', 'nibwp'),
    ];
}

/**
 * Change one display rule.
 *
 * Bounded by an allow-list of rule names with a declared type for each, so a
 * caller cannot write an arbitrary key into the campaign's settings array — that
 * array holds the campaign's entire design, and a stray key in it is a broken
 * popup nobody can explain.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_set_display_rule(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Changing a MailOptin campaign\'s display rules, which changes what visitors see', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $writable = nibwp_mailoptin_writable_rules();
    $rule = isset($input['rule']) && is_scalar($input['rule']) ? (string) $input['rule'] : '';

    if (!isset($writable[$rule])) {
        return nibwp_mailoptin_err(
            'bad_rule',
            sprintf(
                /* translators: %s: list of writable rule names */
                __('rule must be one of: %s. Everything else about a campaign is changed in MailOptin\'s customizer.', 'nibwp'),
                implode(', ', array_keys($writable))
            ),
            422
        );
    }

    if (!array_key_exists('value', $input)) {
        return nibwp_mailoptin_err('missing_value', __('Give the new setting as value.', 'nibwp'), 422);
    }

    $raw = $input['value'];

    if ($writable[$rule] === 'bool') {
        if (!is_bool($raw) && !in_array($raw, [0, 1, '0', '1'], true)) {
            return nibwp_mailoptin_err(
                'bad_value',
                sprintf(
                    /* translators: %s: rule name */
                    __('%s is a switch, so value must be true or false.', 'nibwp'),
                    $rule
                ),
                422
            );
        }

        $value = (bool) $raw;
    } else {
        if (!is_numeric($raw) || (int) $raw < 0) {
            return nibwp_mailoptin_err(
                'bad_value',
                sprintf(
                    /* translators: %s: rule name */
                    __('%s takes a whole number of zero or more.', 'nibwp'),
                    $rule
                ),
                422
            );
        }

        $value = (int) $raw;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    if ($missing = nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinCampaignsRepository', 'updateSettings')) {
        return $missing;
    }

    // Read, change one key, write the whole option back — which is how MailOptin
    // itself does it (OptinCampaignsRepository.php:750-787), because every
    // campaign's settings share one option row and there is no narrower write.
    $all = nibwp_mailoptin_all_settings();
    $own = isset($all[$id]) && is_array($all[$id]) ? $all[$id] : [];
    $was = $own[$rule] ?? null;
    $own[$rule] = $value;
    $all[$id] = $own;

    \MailOptin\Core\Repositories\OptinCampaignsRepository::updateSettings($all);

    $live = nibwp_mailoptin_is_enabled($id);

    return [
        'campaign_id' => $id,
        'rule' => $rule,
        'was' => nibwp_mailoptin_rule_value($was),
        'now' => $value,
        'enabled' => $live,
        'note' => $live
            ? __('This campaign is enabled, so the change affects what visitors see from the next page load.', 'nibwp')
            : __('This campaign is disabled, so nothing changes for visitors until it is enabled.', 'nibwp'),
    ];
}

/* ----------------------------------------------------------------- the leads */

/**
 * Search the captured leads.
 *
 * Bounded, always. These are real people: a name and the email address they typed
 * into a form, plus the page they were on when they did it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_search_leads(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading MailOptin leads', 'nibwp'))) {
        return $denied;
    }

    $rows = nibwp_mailoptin_lead_query($input, nibwp_mailoptin_limit($input['limit'] ?? null));

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    return $rows + [
        'note' => __('These are real people who gave this site their address. Extra fields a form collected are never returned, because their contents are whatever that form was configured to ask for.', 'nibwp'),
    ];
}

/**
 * The lead listing, shared by search_leads and export_leads.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_lead_query(array $input, int $limit): array|WP_Error
{
    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('leads');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s lead table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $offset = nibwp_mailoptin_offset($input['offset'] ?? null);

    // Every clause carries its own placeholder and every value is bound. The one
    // always-true clause exists so the count and the listing share a format string
    // instead of branching into a version with no WHERE at all.
    $where = ['%d = %d'];
    $args = [1, 1];

    if (isset($input['campaign_id'])) {
        $id = nibwp_mailoptin_campaign_id($input['campaign_id']);
        if ($id instanceof WP_Error) {
            return $id;
        }

        $where[] = 'optin_id = %d';
        $args[] = $id;
    }

    if (isset($input['search']) && is_scalar($input['search']) && trim((string) $input['search']) !== '') {
        $term = sanitize_text_field((string) $input['search']);

        if (strlen($term) > 200) {
            return nibwp_mailoptin_err('bad_search', __('That search term is too long to be a name or an email address.', 'nibwp'), 422);
        }

        $like = '%' . $wpdb->esc_like($term) . '%';
        $where[] = '(name LIKE %s OR email LIKE %s)';
        $args[] = $like;
        $args[] = $like;
    }

    if (isset($input['days'])) {
        $where[] = 'date_added >= %s';
        $args[] = nibwp_mailoptin_since(nibwp_mailoptin_days($input['days']));
    }

    $clause = ' WHERE ' . implode(' AND ', $where);

    $total = (int) $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM ' . $table . $clause, $args)
    );

    $page_args = $args;
    $page_args[] = $limit;
    $page_args[] = $offset;

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, optin_id, optin_type, name, email, conversion_page, referrer, date_added FROM '
            . $table . $clause . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $page_args
        ),
        ARRAY_A
    );

    $leads = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $leads[] = nibwp_mailoptin_lead_shape($row);

        if (count($leads) >= $limit) {
            break;
        }
    }

    return [
        'total_matching' => $total,
        'returned' => count($leads),
        'limit' => $limit,
        'offset' => $offset,
        'leads' => $leads,
        'fields_returned' => nibwp_mailoptin_lead_fields(),
    ];
}

/** The MySQL datetime N days ago, for a bound comparison. */
function nibwp_mailoptin_since(int $days): string
{
    return gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
}

/**
 * One lead.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_get_lead(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading a MailOptin lead', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_lead_id($input['lead_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_lead_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    return [
        'lead' => nibwp_mailoptin_lead_shape($row),
        'fields_returned' => nibwp_mailoptin_lead_fields(),
    ];
}

/**
 * One lead row, or a refusal.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_lead_row(int $id): array|WP_Error
{
    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('leads');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s lead table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, optin_id, optin_type, name, email, conversion_page, referrer, date_added FROM '
            . $table . ' WHERE id = %d',
            $id
        ),
        ARRAY_A
    );

    if (!is_array($row) || $row === []) {
        return nibwp_mailoptin_err(
            'lead_not_found',
            sprintf(
                /* translators: %d: lead id */
                __('No MailOptin lead with id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    return $row;
}

/**
 * How many leads came in over a period, in total and per campaign.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_count_leads(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Counting MailOptin leads', 'nibwp'))) {
        return $denied;
    }

    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('leads');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s lead table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $days = nibwp_mailoptin_days($input['days'] ?? null);
    $since = nibwp_mailoptin_since($days);

    $in_period = (int) $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE date_added >= %s', $since)
    );

    $all_time = (int) $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE %d = %d', 1, 1)
    );

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT optin_id, COUNT(*) AS captured FROM ' . $table
            . ' WHERE date_added >= %s GROUP BY optin_id ORDER BY captured DESC LIMIT %d',
            $since,
            NIBWP_MAILOPTIN_MAX_ROWS
        ),
        ARRAY_A
    );

    $names = nibwp_mailoptin_campaign_names();
    $per_campaign = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int) ($row['optin_id'] ?? 0);
        $per_campaign[] = [
            'campaign_id' => $id,
            'name' => $names[$id] ?? null,
            'captured' => (int) ($row['captured'] ?? 0),
            'campaign_still_exists' => isset($names[$id]),
        ];
    }

    return [
        'days' => $days,
        'since' => $since,
        'captured_in_period' => $in_period,
        'captured_all_time' => $all_time,
        'per_campaign' => $per_campaign,
        'note' => __('A row whose campaign_still_exists is false belongs to a campaign that was deleted: deleting a campaign leaves its leads in place. Note also that this build removes lead rows older than 90 days on a schedule unless MailOptin\'s paid add-on is active, so "all time" may be shorter than the site is old.', 'nibwp'),
    ];
}

/**
 * Pull leads out in bulk.
 *
 * Behind manage_options as well as the plugin's own capability, because handing
 * back a block of names and addresses is a different act from looking one up, and
 * bounded like everything else — there is no "all" here, by design.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_export_leads(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require_admin(__('Exporting MailOptin leads in bulk', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_mailoptin_require(__('Exporting MailOptin leads in bulk', 'nibwp'))) {
        return $denied;
    }

    // The export's default is the maximum rather than 50, because an export asked
    // for without a limit means "as much as you will give me". It is still the same
    // hard bound, and it is still not raisable from input.
    $limit = nibwp_mailoptin_limit($input['limit'] ?? NIBWP_MAILOPTIN_MAX_ROWS);

    $rows = nibwp_mailoptin_lead_query($input, $limit);

    if ($rows instanceof WP_Error) {
        return $rows;
    }

    $returned = (int) ($rows['returned'] ?? 0);
    $total = (int) ($rows['total_matching'] ?? 0);

    return $rows + [
        'complete' => $returned >= $total,
        'note' => __('This is personal data leaving the site. It is capped, so page through with offset if more was wanted, and pass it only to whoever is entitled to it. MailOptin\'s own Leads screen has a CSV export for a genuine full dump.', 'nibwp'),
    ];
}

/**
 * Delete one lead. Irreversible.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_delete_lead(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require_admin(__('Deleting a MailOptin lead', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_mailoptin_require(__('Deleting a MailOptin lead', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_lead_id($input['lead_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_lead_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    if ($denied = nibwp_mailoptin_confirmed($input, __('Deleting a MailOptin lead', 'nibwp'))) {
        return $denied;
    }

    if ($missing = nibwp_mailoptin_repo_missing('MailOptin\\Core\\Repositories\\OptinConversionsRepository', 'delete')) {
        return $missing;
    }

    \MailOptin\Core\Repositories\OptinConversionsRepository::delete($id);

    return [
        'deleted' => true,
        'lead_id' => $id,
        'note' => __('That record is gone. There is no trash and no undo for a lead, and the row is not handed back on the way out. If this was a data-erasure request, remember the same address may also exist at whichever email service the campaign fed, which this hub cannot reach.', 'nibwp'),
    ];
}

/** How many leads a campaign has captured. */
function nibwp_mailoptin_lead_count_for(int $campaign_id): ?int
{
    if (nibwp_mailoptin_db() instanceof WP_Error) {
        return null;
    }

    $table = nibwp_mailoptin_table('leads');

    if ($table === '') {
        return null;
    }

    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE optin_id = %d', $campaign_id)
    );
}

/* ----------------------------------------------------------- the connections */

/**
 * Which email services this site holds credentials for.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_list_connections(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading the MailOptin connections', 'nibwp'))) {
        return $denied;
    }

    $inventory = nibwp_mailoptin_connection_inventory();
    $services = [];

    foreach (nibwp_mailoptin_services_in_use() as $service) {
        $services[] = [
            'service' => $service,
            'has_stored_credential' => nibwp_mailoptin_connection_has_credential($service, $inventory),
            'add_on_loaded' => class_exists('MailOptin\\' . $service . '\\Connect'),
        ];
    }

    $license = nibwp_mailoptin_option_name('license');
    $license_value = $license === '' ? '' : get_option($license, '');

    return [
        'credential_keys' => $inventory,
        'credential_keys_returned' => count($inventory),
        'values_returned_for' => nibwp_mailoptin_connection_public_keys(),
        'services_used_by_campaigns' => $services,
        'license_key_is_set' => is_scalar($license_value) && (string) $license_value !== '',
        'secrets_returned' => false,
        'note' => __('No API key, OAuth token, secret or password is returned here, and neither is the plugin licence key — only the key name and whether something is stored under it. The handful of keys whose values do travel are listed, and they are regions, sending domains and from-addresses. Nothing here contacted a service: has_stored_credential means a credential is saved on this site, not that the service accepted it.', 'nibwp'),
    ];
}

/**
 * Which connection and which list each campaign feeds.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_campaign_connections(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading where MailOptin campaigns send their leads', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_mailoptin_limit($input['limit'] ?? null);
    $names = nibwp_mailoptin_campaign_names();
    $inventory = nibwp_mailoptin_connection_inventory();
    $rows = [];
    $unconnected = 0;

    foreach ($names as $raw_id => $name) {
        if (count($rows) >= $limit) {
            break;
        }

        $id = (int) $raw_id;
        $settings = nibwp_mailoptin_settings_for($id);
        $service = isset($settings['connection_service']) && is_scalar($settings['connection_service'])
            ? (string) $settings['connection_service']
            : '';
        $list = isset($settings['connection_email_list']) && is_scalar($settings['connection_email_list'])
            ? (string) $settings['connection_email_list']
            : '';

        if ($service === '') {
            $unconnected++;
        }

        $rows[] = [
            'campaign_id' => $id,
            'name' => $name,
            'enabled' => nibwp_mailoptin_is_enabled($id),
            'service' => $service === '' ? null : $service,
            'email_list' => $list === '' ? null : $list,
            'has_stored_credential' => $service === '' ? null : nibwp_mailoptin_connection_has_credential($service, $inventory),
            'add_on_loaded' => $service === '' ? null : class_exists('MailOptin\\' . $service . '\\Connect'),
        ];
    }

    return [
        'returned' => count($rows),
        'limit' => $limit,
        'campaigns_without_a_connection' => $unconnected,
        'campaigns' => $rows,
        'note' => __('A campaign with no service still works: it saves the lead in MailOptin\'s own lead bank and nothing else. That is fine if somebody exports it, and a slow leak if nobody does — this build deletes lead rows older than 90 days on a schedule unless the paid add-on is active.', 'nibwp'),
    ];
}

/**
 * Every connection name any campaign points at.
 *
 * Derived from the campaigns' own settings rather than from MailOptin's registered
 * connection list, because that list is built by a filter each add-on hooks and
 * reading it would also mean asking every connection whether it is connected,
 * which is a network call per service (PluginSettings/Connections.php:30).
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_services_in_use(): array
{
    $services = [];

    foreach (nibwp_mailoptin_all_settings() as $settings) {
        if (!is_array($settings)) {
            continue;
        }

        $service = $settings['connection_service'] ?? null;

        if (is_string($service) && $service !== '') {
            $services[$service] = true;
        }

        if (count($services) >= NIBWP_MAILOPTIN_MAX_ROWS) {
            break;
        }
    }

    return array_keys($services);
}

/* ----------------------------------------------------------- the statistics */

/**
 * Every campaign's impression and conversion counters, in one bounded query.
 *
 * They are plain rows in the campaign meta table under two fixed meta keys
 * (OptinCampaignStat.php:19, :26), so one grouped read beats asking per campaign.
 *
 * @return array<int, array<string, int>>
 */
function nibwp_mailoptin_stat_counters(): array
{
    if (nibwp_mailoptin_db() instanceof WP_Error) {
        return [];
    }

    $table = nibwp_mailoptin_table('campaign_meta');

    if ($table === '') {
        return [];
    }

    global $wpdb;

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT optin_campaign_id, meta_key, meta_value FROM ' . $table
            . ' WHERE meta_key IN (%s, %s) ORDER BY meta_id ASC LIMIT %d',
            'mo_counter',
            'mo_conversions',
            NIBWP_MAILOPTIN_MAX_ROWS * 4
        ),
        ARRAY_A
    );

    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int) ($row['optin_campaign_id'] ?? 0);
        $key = (string) ($row['meta_key'] ?? '');
        $value = (int) ($row['meta_value'] ?? 0);

        if (!isset($out[$id])) {
            $out[$id] = ['impressions' => 0, 'conversions' => 0];
        }

        if ($key === 'mo_counter') {
            $out[$id]['impressions'] = $value;
        } elseif ($key === 'mo_conversions') {
            $out[$id]['conversions'] = $value;
        }
    }

    return $out;
}

/**
 * Campaign id => name, bounded.
 *
 * @return array<int, string>
 */
function nibwp_mailoptin_campaign_names(): array
{
    if (nibwp_mailoptin_db() instanceof WP_Error) {
        return [];
    }

    $table = nibwp_mailoptin_table('campaigns');

    if ($table === '') {
        return [];
    }

    global $wpdb;

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare('SELECT id, name FROM ' . $table . ' ORDER BY id ASC LIMIT %d', NIBWP_MAILOPTIN_MAX_ROWS),
        ARRAY_A
    );

    $out = [];

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id'])) {
            $out[(int) $row['id']] = isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : '';
        }
    }

    return $out;
}

/** Conversion rate as a number, or null when there is nothing to divide by. */
function nibwp_mailoptin_rate(int $conversions, int $impressions): ?float
{
    if ($impressions <= 0) {
        return null;
    }

    return round(($conversions / $impressions) * 100, 2);
}

/**
 * One campaign's statistics.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_campaign_stats(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading MailOptin statistics', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_campaign_id($input['campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    $row = nibwp_mailoptin_campaign_row($id);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $days = nibwp_mailoptin_days($input['days'] ?? null);
    $stats = nibwp_mailoptin_stat_counters();
    $impressions = (int) ($stats[$id]['impressions'] ?? 0);
    $conversions = (int) ($stats[$id]['conversions'] ?? 0);

    $in_period = null;
    $table = nibwp_mailoptin_table('leads');

    if ($table !== '' && !(nibwp_mailoptin_db() instanceof WP_Error)) {
        global $wpdb;

        $in_period = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE optin_id = %d AND date_added >= %s',
                $id,
                nibwp_mailoptin_since($days)
            )
        );
    }

    return [
        'campaign_id' => $id,
        'name' => isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : null,
        'enabled' => nibwp_mailoptin_is_enabled($id),
        'impressions_all_time' => $impressions,
        'conversions_all_time' => $conversions,
        'conversion_rate_all_time_percent' => nibwp_mailoptin_rate($conversions, $impressions),
        'days' => $days,
        'leads_captured_in_period' => $in_period,
        'conversion_rate_in_period_percent' => null,
        'note' => __('The impression and conversion counters MailOptin keeps are lifetime totals with no dates attached, so a conversion rate for a window cannot be computed from them and comes back null rather than guessed. leads_captured_in_period is a real count of lead rows by date, which is the one part of this a period applies to. Per-day impression history is something only MailOptin\'s paid analytics add-on records.', 'nibwp'),
    ];
}

/**
 * Best and worst performers.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_stats_leaderboard(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading MailOptin statistics', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_mailoptin_limit($input['limit'] ?? 10);
    $floor = isset($input['min_impressions']) && is_numeric($input['min_impressions'])
        ? max(0, (int) $input['min_impressions'])
        : 50;

    $names = nibwp_mailoptin_campaign_names();
    $stats = nibwp_mailoptin_stat_counters();
    $ranked = [];
    $too_quiet = 0;

    foreach ($names as $raw_id => $name) {
        $id = (int) $raw_id;
        $impressions = (int) ($stats[$id]['impressions'] ?? 0);
        $conversions = (int) ($stats[$id]['conversions'] ?? 0);

        if ($impressions < $floor) {
            $too_quiet++;
            continue;
        }

        $ranked[] = [
            'campaign_id' => $id,
            'name' => $name,
            'enabled' => nibwp_mailoptin_is_enabled($id),
            'impressions' => $impressions,
            'conversions' => $conversions,
            'conversion_rate_percent' => nibwp_mailoptin_rate($conversions, $impressions),
        ];
    }

    usort($ranked, static function (array $a, array $b): int {
        return ($b['conversion_rate_percent'] ?? 0.0) <=> ($a['conversion_rate_percent'] ?? 0.0);
    });

    return [
        'min_impressions' => $floor,
        'ranked' => count($ranked),
        'excluded_too_few_impressions' => $too_quiet,
        'best' => array_slice($ranked, 0, $limit),
        'worst' => array_slice(array_reverse($ranked), 0, $limit),
        'note' => __('Ranked on the lifetime conversion rate, and a campaign with fewer impressions than min_impressions is left out rather than ranked — something seen three times and converting once is not a 33% winner. Raise min_impressions on a busy site.', 'nibwp'),
    ];
}

/**
 * Conversions over a period, per campaign.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_conversions_over_period(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading MailOptin conversions', 'nibwp'))) {
        return $denied;
    }

    $counted = nibwp_mailoptin_count_leads($input);

    if ($counted instanceof WP_Error) {
        return $counted;
    }

    $stats = nibwp_mailoptin_stat_counters();
    $rows = [];

    foreach ((array) ($counted['per_campaign'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int) ($row['campaign_id'] ?? 0);
        $row['impressions_all_time'] = (int) ($stats[$id]['impressions'] ?? 0);
        $row['conversions_all_time'] = (int) ($stats[$id]['conversions'] ?? 0);
        $rows[] = $row;
    }

    return [
        'days' => $counted['days'] ?? null,
        'since' => $counted['since'] ?? null,
        'conversions_in_period' => $counted['captured_in_period'] ?? null,
        'per_campaign' => $rows,
        'note' => __('A conversion here is a lead row, dated. The impression side has no dates, so the all-time counters sit beside it for context rather than as a rate: dividing a windowed count by a lifetime total would produce a number that looks like a conversion rate and is not one.', 'nibwp'),
    ];
}

/* ------------------------------------------------------ the email campaigns */

/**
 * The newsletters and automated emails this build has.
 *
 * Read-only, all of it. Nothing here sends.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_list_email_campaigns(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading the MailOptin email campaigns', 'nibwp'))) {
        return $denied;
    }

    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('email_campaigns');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s email campaign table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $limit = nibwp_mailoptin_limit($input['limit'] ?? null);
    $offset = nibwp_mailoptin_offset($input['offset'] ?? null);

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, name, campaign_type, template_class FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $limit,
            $offset
        ),
        ARRAY_A
    );

    $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE %d = %d', 1, 1));
    $settings = nibwp_mailoptin_email_settings();
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int) ($row['id'] ?? 0);
        $own = isset($settings[$id]) && is_array($settings[$id]) ? $settings[$id] : [];

        $out[] = [
            'id' => $id,
            'name' => isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : null,
            'campaign_type' => isset($row['campaign_type']) && is_scalar($row['campaign_type']) ? (string) $row['campaign_type'] : null,
            'template_class' => isset($row['template_class']) && is_scalar($row['template_class']) ? (string) $row['template_class'] : null,
            'active' => ($own['activate_campaign'] ?? null) === true,
            'connection_service' => isset($own['connection_service']) && is_scalar($own['connection_service']) ? (string) $own['connection_service'] : null,
        ];

        if (count($out) >= $limit) {
            break;
        }
    }

    return [
        'total' => $total,
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'email_campaigns' => $out,
        'sending_available_here' => false,
        'note' => __('Reading only. There is no action on this hub that sends a newsletter or a broadcast, or schedules one — that is a decision for a person looking at the recipient count in MailOptin\'s own screens.', 'nibwp'),
    ];
}

/**
 * Every email campaign's settings, as the one option they live in.
 *
 * @return array<int|string, mixed>
 */
function nibwp_mailoptin_email_settings(): array
{
    $option = nibwp_mailoptin_option_name('email_campaign_settings');

    if ($option === '') {
        return [];
    }

    $settings = get_option($option, []);

    return is_array($settings) ? $settings : [];
}

/**
 * One email campaign.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_get_email_campaign(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading a MailOptin email campaign', 'nibwp'))) {
        return $denied;
    }

    $id = nibwp_mailoptin_email_campaign_id($input['email_campaign_id'] ?? null);
    if ($id instanceof WP_Error) {
        return $id;
    }

    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('email_campaigns');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s email campaign table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT id, name, campaign_type, template_class FROM ' . $table . ' WHERE id = %d', $id),
        ARRAY_A
    );

    if (!is_array($row) || $row === []) {
        return nibwp_mailoptin_err(
            'email_campaign_not_found',
            sprintf(
                /* translators: %d: email campaign id */
                __('No MailOptin email campaign with id %d exists on this site.', 'nibwp'),
                $id
            ),
            404
        );
    }

    $all = nibwp_mailoptin_email_settings();
    $own = isset($all[$id]) && is_array($all[$id]) ? $all[$id] : [];

    return [
        'email_campaign' => [
            'id' => $id,
            'name' => isset($row['name']) && is_scalar($row['name']) ? (string) $row['name'] : null,
            'campaign_type' => isset($row['campaign_type']) && is_scalar($row['campaign_type']) ? (string) $row['campaign_type'] : null,
            'template_class' => isset($row['template_class']) && is_scalar($row['template_class']) ? (string) $row['template_class'] : null,
            'active' => ($own['activate_campaign'] ?? null) === true,
            'connection_service' => isset($own['connection_service']) && is_scalar($own['connection_service']) ? (string) $own['connection_service'] : null,
            'connection_email_list' => isset($own['connection_email_list']) && is_scalar($own['connection_email_list']) ? (string) $own['connection_email_list'] : null,
        ],
        'settings_keys' => nibwp_mailoptin_setting_key_names($own),
        'sending_available_here' => false,
    ];
}

/**
 * What this site's email campaigns actually sent, from the log.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_email_campaign_log(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Reading the MailOptin email campaign log', 'nibwp'))) {
        return $denied;
    }

    if ($no_db = nibwp_mailoptin_db()) {
        return $no_db;
    }

    $table = nibwp_mailoptin_table('email_log');

    if ($table === '') {
        return nibwp_mailoptin_err('no_table', __('MailOptin\'s email campaign log table could not be located.', 'nibwp'), 500);
    }

    global $wpdb;

    $limit = nibwp_mailoptin_limit($input['limit'] ?? null);
    $offset = nibwp_mailoptin_offset($input['offset'] ?? null);
    $where = ['%d = %d'];
    $args = [1, 1];

    if (isset($input['email_campaign_id'])) {
        $id = nibwp_mailoptin_email_campaign_id($input['email_campaign_id']);
        if ($id instanceof WP_Error) {
            return $id;
        }

        $where[] = 'email_campaign_id = %d';
        $args[] = $id;
    }

    $args[] = $limit;
    $args[] = $offset;

    // The title and the status are what somebody asks about. The stored content_html
    // and content_text are whole rendered emails and are deliberately not read:
    // they are bulk, not fact, and one of them would fill a response on its own.
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, email_campaign_id, title, status, status_time, note FROM ' . $table
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $args
        ),
        ARRAY_A
    );

    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $entry = [];

        foreach (['id', 'email_campaign_id', 'title', 'status', 'status_time', 'note'] as $field) {
            $value = $row[$field] ?? null;

            if ($value === null) {
                $entry[$field] = null;
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $entry[$field] = in_array($field, ['id', 'email_campaign_id'], true) ? (int) $value : $value;
        }

        $out[] = $entry;

        if (count($out) >= $limit) {
            break;
        }
    }

    return [
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'log' => $out,
        'note' => __('The rendered email bodies are in this table too and are not returned: they are whole HTML documents. Reading the log does not re-send anything, and there is no action here that sends.', 'nibwp'),
    ];
}

/* --------------------------------------------------------------- the audit */

/**
 * The four findings an owner can act on.
 *
 * Each one is a thing that looks fine on the campaign list and is quietly wrong:
 * a popup everybody sees that converts nobody; a campaign whose leads only ever
 * reach a lead bank that gets purged; a campaign with no rules at all, which is
 * almost never what somebody built on purpose; and a connection a campaign points
 * at with no credential stored for it, which means every submission to it fails at
 * the far end with nothing on screen to say so.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_mailoptin_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_mailoptin_require(__('Auditing MailOptin', 'nibwp'))) {
        return $denied;
    }

    $floor = isset($input['min_impressions']) && is_numeric($input['min_impressions'])
        ? max(1, (int) $input['min_impressions'])
        : 50;

    $names = nibwp_mailoptin_campaign_names();
    $stats = nibwp_mailoptin_stat_counters();
    $inventory = nibwp_mailoptin_connection_inventory();

    $converting_nothing = [];
    $no_connected_list = [];
    $no_display_rules = [];
    $credentials_missing = [];
    $seen_services = [];
    $enabled = 0;

    foreach ($names as $raw_id => $name) {
        $id = (int) $raw_id;
        $settings = nibwp_mailoptin_settings_for($id);

        // Only live campaigns are audited: a disabled one showing nothing to
        // nobody is not a finding, it is a decision somebody already made.
        if (($settings['activate_optin'] ?? null) !== true) {
            continue;
        }

        $enabled++;

        $impressions = (int) ($stats[$id]['impressions'] ?? 0);
        $conversions = (int) ($stats[$id]['conversions'] ?? 0);

        if ($impressions >= $floor && $conversions === 0) {
            $converting_nothing[] = [
                'campaign_id' => $id,
                'name' => $name,
                'impressions' => $impressions,
                'conversions' => 0,
            ];
        }

        $service = isset($settings['connection_service']) && is_scalar($settings['connection_service'])
            ? (string) $settings['connection_service']
            : '';
        $list = isset($settings['connection_email_list']) && is_scalar($settings['connection_email_list'])
            ? (string) $settings['connection_email_list']
            : '';

        if ($service === '' || $list === '') {
            $no_connected_list[] = [
                'campaign_id' => $id,
                'name' => $name,
                'service' => $service === '' ? null : $service,
                'email_list' => $list === '' ? null : $list,
                'leads_captured' => nibwp_mailoptin_lead_count_for($id),
            ];
        }

        if ($service !== '' && !isset($seen_services[$service])) {
            $seen_services[$service] = true;

            if (!nibwp_mailoptin_connection_has_credential($service, $inventory)) {
                $credentials_missing[] = [
                    'service' => $service,
                    'add_on_loaded' => class_exists('MailOptin\\' . $service . '\\Connect'),
                    'used_by_campaign_id' => $id,
                ];
            }
        }

        if (nibwp_mailoptin_rules_for($settings)['unrestricted'] === true) {
            $no_display_rules[] = [
                'campaign_id' => $id,
                'name' => $name,
                'impressions' => $impressions,
            ];
        }

        if ($enabled >= NIBWP_MAILOPTIN_MAX_ROWS) {
            break;
        }
    }

    return [
        'campaigns_total' => count($names),
        'campaigns_enabled' => $enabled,
        'min_impressions' => $floor,
        'findings' => [
            'enabled_but_converting_nothing' => $converting_nothing,
            'no_connected_list' => $no_connected_list,
            'no_display_rules' => $no_display_rules,
            'connection_credentials_missing' => $credentials_missing,
        ],
        'counts' => [
            'enabled_but_converting_nothing' => count($converting_nothing),
            'no_connected_list' => count($no_connected_list),
            'no_display_rules' => count($no_display_rules),
            'connection_credentials_missing' => count($credentials_missing),
        ],
        'note' => __('Only enabled campaigns are audited. "no_connected_list" means a submission is saved locally and goes no further, and this build deletes lead rows older than 90 days on a schedule unless the paid add-on is active, so those leads have a shelf life. "connection_credentials_missing" is read from this site only: a credential that IS stored may still have been revoked at the service, and the only way to know that is to log in there.', 'nibwp'),
    ];
}
