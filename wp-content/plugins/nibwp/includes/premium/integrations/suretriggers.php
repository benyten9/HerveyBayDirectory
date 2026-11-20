<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: SureTriggers (automation).
 *
 * READ THIS BEFORE ADDING AN ACTION. The automations themselves are not on this
 * site. The plugin is a connector: the recipes, their steps, their filters and
 * every credential for a connected third-party app live in the vendor's hosted
 * service, and the WordPress screen is a React shell that frames that service
 * (src/Loader.php:414-440 adds submenu items that are nothing but deep links
 * into /workflows, /recipes, /history, /apps, /members, /settings).
 *
 * What is genuinely here, and all this integration can read:
 *
 *   - the TRIGGER SUBSCRIPTIONS the service pushes down so the site knows which
 *     WordPress hooks to listen on: suretrigger_options['triggers'], written by
 *     the service over REST (src/Controllers/RoutesController.php:54-62 →
 *     src/Controllers/RestController.php:479-508) and read back at
 *     src/Controllers/AutomationController.php:50 to attach the hooks;
 *   - the CATALOGUE this install registers — 148 integration classes, each
 *     contributing triggers and actions through two filters
 *     (src/Controllers/EventController.php:65-66). That catalogue is the useful
 *     part for an agent planning an automation, and it is entirely local;
 *   - the DELIVERY LOG, {prefix}suretriggers_webhook_requests
 *     (src/Controllers/WebhookRequestsController.php:86-108), one row per
 *     outbound trigger delivery to the service;
 *   - the connection state, the three background schedules, and who may open the
 *     automation dashboard.
 *
 * So this is a smaller integration than most, on purpose, and it says so in its
 * own description. Fifteen actions that work beat twenty-five that refuse.
 *
 * WHAT IS NOT HERE, AND WILL NOT BE:
 *
 *   - RUNNING OR RE-RUNNING AN AUTOMATION. An automation can email customers,
 *     charge cards and write into systems this site does not own, and none of
 *     that can be undone from here. The plugin has a re-send
 *     (WebhookRequestsController::suretriggers_retry_trigger_request(), with its
 *     own fresh-UUID step at src/Controllers/WebhookRequestsController.php:273
 *     specifically so the service will not treat the replay as a duplicate) and
 *     it is deliberately not reachable through this hub.
 *   - RE-ARMING THE BACKGROUND SCHEDULES, for the same reason one step removed:
 *     suretriggers_retry_failed_requests re-sends every failed delivery still
 *     under five attempts (src/Controllers/WebhookRequestsController.php:198-212),
 *     so scheduling it fires automations downstream. The audit reports a missing
 *     schedule; a person re-arms it.
 *   - ENABLING OR DISABLING AN AUTOMATION. There is no local switch to flip. The
 *     'triggers' option is a derived subscription list, not the automation: a
 *     write here would leave the service still believing the automation is live,
 *     show nothing in its dashboard, and be silently overwritten on the next sync
 *     (RestController.php:498). Refusing is more honest than appearing to work.
 *     The one local switch that does exist — delivery logging — is here, and says
 *     plainly what turning it off costs.
 *
 * A test parses the enum and fails on any action whose name reads like running,
 * re-running, retrying or re-sending, so a later edit has to argue with it first.
 *
 * Verified against SureTriggers 1.1.40 (src/Loader.php:329).
 *
 * SECRETS: the service connection secret lives, encrypted, in the same option
 * bag as everything else this integration reads — suretrigger_options['secret_key']
 * (src/Models/SaasApiToken.php:29, src/Controllers/OptionController.php:39).
 * Values leave that bag only through an allow-list, and a non-scalar is dropped
 * rather than cast: an array is how a credential set travels under an innocent
 * key, and this plugin connects to a great many services, so a denylist would
 * miss the next one.
 *
 * PERSONAL DATA: every delivery-log row carries request_data, the JSON body that
 * was sent to the service — the order, the customer, the form submission. The
 * service's own reply is kept beside it in error_info. Neither leaves here, at
 * any depth. Counts, statuses, response codes and timings do.
 */

/** Automations are site infrastructure: one capability, reads included. */
const NIBWP_SURETRIGGERS_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_SURETRIGGERS_MAX_ROWS = 200;

/** How far back any windowed read will look. */
const NIBWP_SURETRIGGERS_MAX_DAYS = 90;

/** The option bag the plugin keeps everything connection-shaped in. */
const NIBWP_SURETRIGGERS_OPTION = 'suretrigger_options';

/**
 * Is the plugin actually loaded?
 *
 * The version constant is defined from the Loader constructor at plugin load
 * (src/Loader.php:329), before anything registers; the option controller is what
 * every connection read here goes through. Between them they separate "not
 * installed" from "installed and half-booted".
 */
function nibwp_suretriggers_guard(): ?WP_Error
{
    if (
        !defined('SURE_TRIGGERS_VER')
        && !class_exists('SureTriggers\\Controllers\\OptionController')
    ) {
        return new WP_Error(
            'nibwp_suretriggers_missing',
            __('The SureTriggers automation plugin is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about authority rather than about the request. */
function nibwp_suretriggers_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_suretriggers_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_SURETRIGGERS_CAP
        ),
        ['status' => 403]
    );
}

/** The one capability gate, applied to reads as well as writes. */
function nibwp_suretriggers_require(string $what): ?WP_Error
{
    // Reading is not a lower bar here: the subscription list says which business
    // events this site reports to an outside service, and the log says how often
    // that reporting fails.
    return current_user_can(NIBWP_SURETRIGGERS_CAP) ? null : nibwp_suretriggers_forbidden($what);
}

/** A refusal about the request itself. */
function nibwp_suretriggers_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_suretriggers_' . $code, $message, ['status' => $status]);
}

/** Clamp a row count that arrived over the wire. */
function nibwp_suretriggers_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_SURETRIGGERS_MAX_ROWS, $limit));
}

/** Clamp an offset that arrived over the wire. */
function nibwp_suretriggers_offset(mixed $value): int
{
    $offset = is_numeric($value) ? (int) $value : 0;

    return max(0, $offset);
}

/** Clamp a lookback window that arrived over the wire. */
function nibwp_suretriggers_days(mixed $value, int $default = 7): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_SURETRIGGERS_MAX_DAYS, $days));
}

/**
 * The plugin's option bag, read as a plain option.
 *
 * Deliberately not through the plugin's own OptionController: that class caches
 * into a static at construction (src/Controllers/OptionController.php:62), and
 * this way the read does not depend on when the plugin booted.
 *
 * @return array<string, mixed>
 */
function nibwp_suretriggers_options(): array
{
    $options = get_option(NIBWP_SURETRIGGERS_OPTION, []);

    return is_array($options) ? $options : [];
}

/**
 * Keys in the option bag whose VALUE may leave, and what each one is.
 *
 * An allow-list, not a denylist. The bag is one flat key space the vendor adds to
 * between versions — secret_key, connected_email_key, triggers, trigger_data and
 * test_triggers today — and a key added by a later release has to be named here
 * before it can travel. A denylist would have defaulted the new one to leaking.
 *
 * @return array<string, string> key => scalar type to report it as
 */
function nibwp_suretriggers_option_allow_list(): array
{
    return [
        // Which hosted account this site is linked to. Answers the only question
        // the connection state is ever asked, and is not a credential.
        'connected_email_key' => 'string',
    ];
}

/**
 * Keys that must never travel, however the request is phrased.
 *
 * A second layer rather than the only one: the allow-list above already decides
 * what leaves. A name landing in both lists is a mistake worth failing loudly on,
 * which the check asserts.
 *
 * @return array<int, string>
 */
function nibwp_suretriggers_secret_keys(): array
{
    return [
        'secret_key',
        'access_key',
        'api_key',
        'webhook_secret',
        'refresh_token',
        'access_token',
    ];
}

/**
 * Report one option-bag entry the way this integration reports them.
 *
 * A non-scalar is DROPPED, never cast. An array under an innocent key is exactly
 * how a credential set for a connected app would arrive, and json-encoding it to
 * make it a string would publish it.
 *
 * @return array<string, mixed>
 */
function nibwp_suretriggers_option_row(string $key, mixed $value, ?string $type): array
{
    $row = [
        'key' => $key,
        'is_set' => $value !== null && $value !== '' && $value !== [] && $value !== false,
        'value_returned' => false,
        'value' => null,
    ];

    if ($type === null) {
        return $row;
    }

    if (!is_scalar($value)) {
        return $row;
    }

    $row['value_returned'] = true;
    $row['value'] = match ($type) {
        'bool' => (bool) $value,
        'int' => (int) $value,
        default => (string) $value,
    };

    return $row;
}

/**
 * Every entry in the option bag, allow-listed.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_suretriggers_option_report(): array
{
    $allowed = nibwp_suretriggers_option_allow_list();
    $rows = [];

    foreach (nibwp_suretriggers_options() as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $rows[] = nibwp_suretriggers_option_row($key, $value, $allowed[$key] ?? null);
    }

    return $rows;
}

/** One allow-listed scalar out of the bag, or null. */
function nibwp_suretriggers_option(string $key): ?string
{
    if (!array_key_exists($key, nibwp_suretriggers_option_allow_list())) {
        return null;
    }

    $value = nibwp_suretriggers_options()[$key] ?? null;

    if (!is_scalar($value) || (string) $value === '') {
        return null;
    }

    return (string) $value;
}

/** Is the site linked to the hosted service at all? */
function nibwp_suretriggers_linked(): bool
{
    $secret = nibwp_suretriggers_options()['secret_key'] ?? null;

    // 'connection-denied' is what the plugin stores when the owner refuses the
    // handshake (src/Controllers/AuthController.php:115), so a non-empty value is
    // not by itself a link.
    return is_string($secret) && $secret !== '' && $secret !== 'connection-denied';
}

/**
 * The trigger/action catalogue this install registers.
 *
 * Populated on init at priority 0 (src/Controllers/EventController.php:55), so by
 * the time an ability runs it is there. Wrapped anyway: the filters run arbitrary
 * third-party code and an uncaught Error in an ability takes the REST request.
 *
 * @return array{triggers: array<string, mixed>, actions: array<string, mixed>}
 */
function nibwp_suretriggers_events(): array
{
    $empty = ['triggers' => [], 'actions' => []];

    if (!class_exists('SureTriggers\\Controllers\\EventController')) {
        return $empty;
    }

    try {
        $controller = SureTriggers\Controllers\EventController::get_instance();

        if (!is_object($controller)) {
            return $empty;
        }

        return [
            'triggers' => isset($controller->triggers) && is_array($controller->triggers) ? $controller->triggers : [],
            'actions' => isset($controller->actions) && is_array($controller->actions) ? $controller->actions : [],
        ];
    } catch (\Throwable $error) {
        return $empty;
    }
}

/**
 * The integration classes this install carries, keyed by slug.
 *
 * @return array<string, object>
 */
function nibwp_suretriggers_integrations(): array
{
    if (!class_exists('SureTriggers\\Controllers\\IntegrationsController')) {
        return [];
    }

    try {
        $classes = SureTriggers\Controllers\IntegrationsController::get_integrations();
    } catch (\Throwable $error) {
        return [];
    }

    if (!is_array($classes)) {
        return [];
    }

    $out = [];

    foreach ($classes as $slug => $object) {
        if (is_string($slug) && is_object($object)) {
            $out[$slug] = $object;
        }
    }

    return $out;
}

/**
 * Is the plugin behind one integration installed and running?
 *
 * is_enabled() ends in the integration's own is_plugin_installed()
 * (src/Integrations/Integrations.php:130-139), which is a different check per
 * integration and occasionally touches a class that is mid-upgrade.
 */
function nibwp_suretriggers_integration_active(object $integration): bool
{
    if (!method_exists($integration, 'is_enabled')) {
        return false;
    }

    try {
        return (bool) $integration->is_enabled();
    } catch (\Throwable $error) {
        return false;
    }
}

/** An integration's human name, if it has one. */
function nibwp_suretriggers_integration_name(string $slug, object $integration): string
{
    if (!method_exists($integration, 'get_name')) {
        return $slug;
    }

    try {
        $name = $integration->get_name();
    } catch (\Throwable $error) {
        return $slug;
    }

    return is_string($name) && $name !== '' ? $name : $slug;
}

/**
 * Describe one registered trigger or action.
 *
 * The registration array carries a 'function' entry which is a live callable —
 * an object and its method (src/Integrations/woocommerce/triggers/AddProductToCart.php:70).
 * It is dropped: it is not information, and serialising a bound object is how an
 * object graph escapes through a response.
 *
 * @return array<string, mixed>
 */
function nibwp_suretriggers_event_row(string $key, mixed $event): array
{
    $row = [
        'id' => $key,
        'label' => null,
        'hooks' => [],
        'priority' => null,
        'accepted_args' => null,
    ];

    if (!is_array($event)) {
        return $row;
    }

    if (isset($event['label']) && is_scalar($event['label'])) {
        $row['label'] = (string) $event['label'];
    }

    $hooks = $event['common_action'] ?? ($event['action'] ?? null);

    if (is_scalar($hooks)) {
        $row['hooks'] = [(string) $hooks];
    } elseif (is_array($hooks)) {
        foreach ($hooks as $hook) {
            if (is_scalar($hook)) {
                $row['hooks'][] = (string) $hook;
            }
        }
    }

    if (isset($event['priority']) && is_numeric($event['priority'])) {
        $row['priority'] = (int) $event['priority'];
    }

    if (isset($event['accepted_args']) && is_numeric($event['accepted_args'])) {
        $row['accepted_args'] = (int) $event['accepted_args'];
    }

    return $row;
}

/**
 * The subscription rows out of one option key, annotated against this install.
 *
 * One automation in the hosted service can produce several of these, and two
 * automations on the same event produce one — so the count is a count of watched
 * events, not of automations, and every caller is told so.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_suretriggers_subscriptions(string $option_key): array
{
    $raw = nibwp_suretriggers_options()[$option_key] ?? [];

    if (!is_array($raw)) {
        return [];
    }

    $events = nibwp_suretriggers_events();
    $integrations = nibwp_suretriggers_integrations();
    $trigger_data = nibwp_suretriggers_options()['trigger_data'] ?? [];
    $rows = [];

    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }

        $integration = isset($item['integration']) && is_scalar($item['integration'])
            ? (string) $item['integration']
            : '';
        $trigger = isset($item['trigger']) && is_scalar($item['trigger'])
            ? (string) $item['trigger']
            : '';

        $registered = $events['triggers'][$integration][$trigger] ?? null;
        $known = array_key_exists($integration, $integrations);

        $rows[] = [
            'integration' => $integration,
            'trigger' => $trigger,
            'label' => is_array($registered) && isset($registered['label']) && is_scalar($registered['label'])
                ? (string) $registered['label']
                : null,
            'trigger_registered' => $registered !== null,
            'integration_known' => $known,
            'integration_active' => $known
                ? nibwp_suretriggers_integration_active($integrations[$integration])
                : false,
            // The selections made in the hosted builder are stored alongside
            // (RestController.php:494) and are free-form: a product id, an email,
            // a form field. Whether they exist is useful; what they say is not
            // ours to publish.
            'has_saved_selections' => is_array($trigger_data)
                && isset($trigger_data[$integration][$trigger]['selected_options'])
                && $trigger_data[$integration][$trigger]['selected_options'] !== [],
        ];
    }

    return $rows;
}

/** The delivery-log table, named from $wpdb and never from input. */
function nibwp_suretriggers_log_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'suretriggers_webhook_requests';
}

/** Does the delivery log exist? A missing table means an install that never ran. */
function nibwp_suretriggers_log_table_exists(): bool
{
    global $wpdb;

    $table = nibwp_suretriggers_log_table();
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return is_string($found) && $found !== '';
}

/** Is the plugin recording deliveries at all? */
function nibwp_suretriggers_logging_enabled(): bool
{
    // Two overrides win over the option: a wp-config constant and a filter, both
    // checked before the insert (src/Controllers/WebhookRequestsController.php:151-169).
    if (defined('SURETRIGGERS_DISABLE_LOGGING') && constant('SURETRIGGERS_DISABLE_LOGGING')) {
        return false;
    }

    return !get_option('suretriggers_disable_request_logging', false);
}

/**
 * What a response code from the service means, in one word.
 *
 * The raw reply body is not returned — see the file header — so this is what a
 * caller gets to reason about instead.
 */
function nibwp_suretriggers_failure_class(int $code): string
{
    if ($code === 0) {
        return 'unreachable';
    }
    if ($code === 401 || $code === 403) {
        return 'authentication';
    }
    if ($code === 404 || $code === 405) {
        return 'endpoint';
    }
    if ($code === 408 || $code === 429) {
        return 'throttled';
    }
    if ($code >= 500) {
        return 'service_error';
    }
    if ($code >= 400) {
        return 'rejected';
    }
    if ($code >= 200 && $code < 300) {
        return 'delivered';
    }

    return 'unknown';
}

/** A MySQL datetime n days back, in site time, matching how rows are written. */
function nibwp_suretriggers_since(int $days): string
{
    return gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
}

/** The three background schedules the plugin relies on, and what each one does. */
function nibwp_suretriggers_schedules(): array
{
    return [
        'suretriggers_retry_failed_requests' => __('Re-sends failed deliveries to the service every 30 minutes, up to five attempts.', 'nibwp'),
        'suretriggers_webhook_requests_cleanup_logs' => __('Prunes the delivery log daily: failures after 60 days, successes after 30.', 'nibwp'),
        'suretriggers_verify_api_connection' => __('Re-checks the service connection every 6 hours.', 'nibwp'),
    ];
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_suretriggers_actions(): array
{
    return [
        // Reads.
        'overview',
        'connection_status',
        'system_status',
        'list_trigger_subscriptions',
        'list_test_subscriptions',
        'list_integrations',
        'describe_integration',
        'search_events',
        'automation_coverage',
        'list_delivery_log',
        'delivery_failures',
        'access_control',
        'audit',
        // Writes — two, both the same local switch, and nothing else.
        'enable_delivery_log',
        'disable_delivery_log',
    ];
}

wp_register_ability('nibwp/suretriggers-manage', [
    'label' => __('SureTriggers – Automation Surface', 'nibwp'),
    'description' => 'Read what the SureTriggers automation plugin keeps on THIS site. '
        . 'Be clear about the model before you answer anything: the automations are not stored here. '
        . 'Recipes, their steps, their filters and every credential for a connected app live in the vendor\'s hosted service, '
        . 'and the WordPress screen is a frame around that service. What is local, and what this reads: the trigger subscriptions the service pushes down so the site knows which hooks to watch; '
        . 'the full catalogue of triggers and actions this install registers and for which other plugins, which is the useful part when planning an automation; '
        . 'the delivery log of outbound trigger sends, as counts, statuses, response codes and timings; the connection state, the background schedules, and who may open the automation dashboard. '
        . 'So this is a deliberately small integration — to list, read, edit or switch off an automation, the owner goes to the hosted dashboard, and saying so is the correct answer. '
        . 'Nothing here runs, re-runs, retries or re-sends an automation, and nothing here re-arms the retry schedule, because that re-sends too. '
        . 'Two writes, both the same local switch: turn delivery logging on or off. '
        . 'The connection secret and any third-party credential are never returned, and neither is a delivery payload.',
    'category' => 'utilities',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_suretriggers_actions(),
                'description' => 'Which operation to run. Start with overview.',
            ],
            'integration' => [
                'type' => 'string',
                'description' => 'For describe_integration: the integration slug, exactly as list_integrations gives it. Case-sensitive.',
            ],
            'query' => [
                'type' => 'string',
                'description' => 'For search_events: words to match against a trigger or action id and label.',
            ],
            'kind' => [
                'type' => 'string',
                'description' => 'For search_events and describe_integration: triggers, actions, or both. Default both.',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'For list_integrations: all, active, inactive. For list_delivery_log: all, success, failed, pending.',
            ],
            'days' => [
                'type' => 'integer',
                'description' => 'Lookback window for the log reads and the audit. Default 7, max 90.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Rows to return. Default 50, max 200.',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Where a listing starts.',
            ],
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
    'execute_callback' => 'nibwp_suretriggers_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SureTriggers. The automations are NOT on this site — lead with that.',
                '',
                'START WITH overview. It states where the automations live, whether this site',
                'is linked to the hosted service, how many events the site is watching, how',
                'much of the catalogue is usable here, and whether deliveries are getting',
                'through. Everything else refines one line of it.',
                '',
                'THE MODEL, so you do not promise what cannot be done: the plugin is a',
                'connector. A recipe and its steps, the apps it talks to and their',
                'credentials are stored and run in the vendor\'s hosted service. What this',
                'site holds is a list of WordPress hooks to watch, pushed down by that',
                'service, plus a log of what it sent. One automation can put several entries',
                'in that list and two automations can share one, so a subscription count is a',
                'count of watched events, never a count of automations. If you are asked how',
                'many automations exist, what they do, or which ones are switched off, the',
                'honest answer is that only the hosted dashboard knows — this hub will not',
                'guess and will not call out to find out.',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'It cannot run, re-run, retry or re-send an automation or a delivery. An',
                'automation can email customers, charge cards and write into systems this site',
                'does not own, and none of that is reversible from here. It also will not',
                're-arm the retry schedule, because the retry schedule re-sends every failed',
                'delivery and so fires those automations downstream — the audit will report a',
                'missing schedule and a person re-arms it in wp-admin.',
                'It cannot enable or disable an automation either, and not for squeamishness:',
                'there is no local switch. The subscription list is derived state. Writing to',
                'it would leave the hosted service still believing the automation is live,',
                'change nothing in its dashboard, and be overwritten on the next sync. Do not',
                'look for another action that does this; there is not one, and saying so is',
                'the right answer.',
                '',
                'THE TWO WRITES, which are one switch:',
                'enable_delivery_log and disable_delivery_log. This changes site behaviour',
                'with nobody watching, so say what changed and what it costs. With logging',
                'off, nothing is recorded AND failed deliveries are never retried, so an',
                'automation that fails fails silently and the owner learns nothing from the',
                'log afterwards. Turning it back on does not recover what was missed.',
                '',
                'SECRETS: the connection secret sits in the same option bag as the rest of the',
                'connection state. Values leave that bag only through an allow-list, so most',
                'entries come back as a name and whether they are set. The secret, and any',
                'credential for a connected app, never travel, and asking differently will',
                'not change that.',
                '',
                'PERSONAL DATA: every delivery-log row holds the JSON body that was sent —',
                'the order, the customer, the form submission — and the service\'s raw reply',
                'beside it. Neither is returned at any depth. You get counts, statuses,',
                'response codes and timings. For the body itself, send the owner to the',
                'plugin\'s own Status screen in wp-admin.',
                '',
                'A USEFUL HABIT: describe_integration and search_events read the local',
                'catalogue of triggers and actions, which is complete and reliable. That is',
                'the right tool for "what could I automate here" — unlike anything about the',
                'automations themselves.',
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
function nibwp_suretriggers_manage(array $input): array|WP_Error
{
    $guard = nibwp_suretriggers_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_suretriggers_actions(), true)) {
        return nibwp_suretriggers_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown SureTriggers action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_suretriggers_actions())
            )
        );
    }

    // The catalogue is built by 148 third-party integration classes through two
    // filters, and any of them can throw on a site mid-update. One catch turns
    // that into a named refusal instead of a fatal that takes the REST request.
    try {
        return nibwp_suretriggers_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_suretriggers_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from the plugin */
                __('SureTriggers could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_suretriggers_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'overview' => nibwp_suretriggers_overview(),
        'connection_status' => nibwp_suretriggers_connection_status(),
        'system_status' => nibwp_suretriggers_system_status(),
        'list_trigger_subscriptions' => nibwp_suretriggers_list_subscriptions($input, 'triggers'),
        'list_test_subscriptions' => nibwp_suretriggers_list_subscriptions($input, 'test_triggers'),
        'list_integrations' => nibwp_suretriggers_list_integrations($input),
        'describe_integration' => nibwp_suretriggers_describe_integration($input),
        'search_events' => nibwp_suretriggers_search_events($input),
        'automation_coverage' => nibwp_suretriggers_automation_coverage($input),
        'list_delivery_log' => nibwp_suretriggers_list_delivery_log($input),
        'delivery_failures' => nibwp_suretriggers_delivery_failures($input),
        'access_control' => nibwp_suretriggers_access_control(),
        'audit' => nibwp_suretriggers_audit($input),
        'enable_delivery_log' => nibwp_suretriggers_set_logging(true),
        'disable_delivery_log' => nibwp_suretriggers_set_logging(false),
        default => nibwp_suretriggers_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * The shape of the whole thing, in one call.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_overview(): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers overview', 'nibwp'))) {
        return $denied;
    }

    $subscriptions = nibwp_suretriggers_subscriptions('triggers');
    $test = nibwp_suretriggers_subscriptions('test_triggers');
    $integrations = nibwp_suretriggers_integrations();
    $events = nibwp_suretriggers_events();

    $active = 0;
    foreach ($integrations as $integration) {
        if (nibwp_suretriggers_integration_active($integration)) {
            $active++;
        }
    }

    $triggers = 0;
    foreach ($events['triggers'] as $set) {
        $triggers += is_array($set) ? count($set) : 0;
    }

    $actions = 0;
    foreach ($events['actions'] as $set) {
        $actions += is_array($set) ? count($set) : 0;
    }

    $counts = nibwp_suretriggers_log_counts(7);

    return [
        'automations_stored' => 'hosted_service',
        'where_automations_live' => __('Not on this site. The recipes, their steps and the credentials for every connected app are stored and run in the vendor\'s hosted service; this plugin is the connector. The WordPress screen frames that service.', 'nibwp'),
        'what_is_local' => [
            __('The list of WordPress hooks to watch, pushed down by the service.', 'nibwp'),
            __('The catalogue of triggers and actions this install registers.', 'nibwp'),
            __('A log of outbound trigger deliveries.', 'nibwp'),
            __('The connection state, three background schedules, and dashboard access.', 'nibwp'),
        ],
        'linked' => nibwp_suretriggers_linked(),
        'account_email' => nibwp_suretriggers_option('connected_email_key'),
        'plan_id' => nibwp_suretriggers_plan_id(),
        'connection_check' => nibwp_suretriggers_verify_status(),
        'watched_events' => count($subscriptions),
        'watched_events_note' => __('A count of events the site listens for, not of automations. One automation can register several and two can share one. How many automations exist is only knowable in the hosted dashboard.', 'nibwp'),
        'leftover_test_events' => count($test),
        'integrations_available' => count($integrations),
        'integrations_active' => $active,
        'registered_triggers' => $triggers,
        'registered_actions' => $actions,
        'delivery_log' => [
            'enabled' => nibwp_suretriggers_logging_enabled(),
            'table_exists' => nibwp_suretriggers_log_table_exists(),
            'window_days' => 7,
        ] + $counts,
        'audit_hint' => __('Run audit for what needs attention, and describe_integration or search_events for what this install could automate.', 'nibwp'),
    ];
}

/**
 * Whether the site is linked, and to what.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_connection_status(): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers connection status', 'nibwp'))) {
        return $denied;
    }

    return [
        'linked' => nibwp_suretriggers_linked(),
        'account_email' => nibwp_suretriggers_option('connected_email_key'),
        'plan_id' => nibwp_suretriggers_plan_id(),
        'install_source' => nibwp_suretriggers_scalar_option('suretriggers_source'),
        'connection_check' => nibwp_suretriggers_verify_status(),
        'connection_secret_set' => nibwp_suretriggers_linked(),
        'connection_secret_returned' => false,
        'option_bag' => nibwp_suretriggers_option_report(),
        'option_bag_note' => __('Entries come back as a name and whether they are set. Only an allow-listed, scalar entry returns a value, so the connection secret and anything a later version adds stay here.', 'nibwp'),
        'remote_only' => __('Whether the hosted service still accepts this site, and what automations it holds, are not knowable from here. This hub makes no request to the service.', 'nibwp'),
    ];
}

/**
 * The install's own health: version, table, schedules, logging.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_system_status(): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers system status', 'nibwp'))) {
        return $denied;
    }

    $schedules = [];

    foreach (nibwp_suretriggers_schedules() as $hook => $what) {
        $next = wp_next_scheduled($hook);

        $schedules[] = [
            'hook' => $hook,
            'does' => $what,
            'scheduled' => $next !== false,
            'next_run_gmt' => is_numeric($next) ? gmdate('Y-m-d H:i:s', (int) $next) : null,
        ];
    }

    return [
        'plugin_version' => defined('SURE_TRIGGERS_VER') ? (string) constant('SURE_TRIGGERS_VER') : null,
        'db_version' => defined('SURE_TRIGGERS_DB_VER') ? (string) constant('SURE_TRIGGERS_DB_VER') : null,
        'rest_namespace' => defined('SURE_TRIGGERS_REST_NAMESPACE') ? (string) constant('SURE_TRIGGERS_REST_NAMESPACE') : null,
        'delivery_log' => [
            'table_exists' => nibwp_suretriggers_log_table_exists(),
            'logging_enabled' => nibwp_suretriggers_logging_enabled(),
            'disabled_by_constant' => defined('SURETRIGGERS_DISABLE_LOGGING') && (bool) constant('SURETRIGGERS_DISABLE_LOGGING'),
            'disabled_by_option' => (bool) get_option('suretriggers_disable_request_logging', false),
            'retention_failed_days' => 60,
            'retention_success_days' => 30,
        ],
        'schedules' => $schedules,
        'inbound_endpoints' => [
            __('The service pushes the watched-event list and runs actions through this site\'s REST API, authenticated with a bearer secret.', 'nibwp'),
        ],
        'note' => __('A missing schedule is reported, not fixed: re-arming the retry schedule re-sends every failed delivery, which fires those automations again downstream. That is a decision for a person in wp-admin.', 'nibwp'),
    ];
}

/**
 * The watched-event list, annotated against this install.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_list_subscriptions(array $input, string $option_key): array|WP_Error
{
    $what = $option_key === 'test_triggers'
        ? __('Reading the SureTriggers leftover test subscriptions', 'nibwp')
        : __('Reading the SureTriggers watched-event list', 'nibwp');

    if ($denied = nibwp_suretriggers_require($what)) {
        return $denied;
    }

    $rows = nibwp_suretriggers_subscriptions($option_key);
    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $offset = nibwp_suretriggers_offset($input['offset'] ?? null);

    $note = $option_key === 'test_triggers'
        ? __('These are left over from testing an automation in the hosted builder. They attach real WordPress hooks like any other subscription, so a forgotten one is a live listener nobody remembers asking for.', 'nibwp')
        : __('These are the events this site listens for, pushed down by the hosted service. One automation can register several and two can share one, so this is not a list of automations and its length is not their number.', 'nibwp');

    return [
        'source_option' => NIBWP_SURETRIGGERS_OPTION . '[' . $option_key . ']',
        'total' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'subscriptions' => array_slice($rows, $offset, $limit),
        'note' => $note,
    ];
}

/**
 * The integration catalogue, with whether each one's plugin is here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_list_integrations(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers integration catalogue', 'nibwp'))) {
        return $denied;
    }

    $status = isset($input['status']) && is_scalar($input['status']) ? (string) $input['status'] : 'all';
    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        $status = 'all';
    }

    $events = nibwp_suretriggers_events();
    $rows = [];
    $active_count = 0;
    $inactive_count = 0;

    foreach (nibwp_suretriggers_integrations() as $slug => $integration) {
        $active = nibwp_suretriggers_integration_active($integration);

        if ($active) {
            $active_count++;
        } else {
            $inactive_count++;
        }

        if ($status === 'active' && !$active) {
            continue;
        }
        if ($status === 'inactive' && $active) {
            continue;
        }

        $rows[] = [
            'slug' => $slug,
            'name' => nibwp_suretriggers_integration_name($slug, $integration),
            'plugin_active' => $active,
            'trigger_count' => isset($events['triggers'][$slug]) && is_array($events['triggers'][$slug])
                ? count($events['triggers'][$slug])
                : 0,
            'action_count' => isset($events['actions'][$slug]) && is_array($events['actions'][$slug])
                ? count($events['actions'][$slug])
                : 0,
        ];
    }

    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $offset = nibwp_suretriggers_offset($input['offset'] ?? null);

    return [
        'status' => $status,
        'total' => count($rows),
        'active_count' => $active_count,
        'inactive_count' => $inactive_count,
        'limit' => $limit,
        'offset' => $offset,
        'integrations' => array_slice($rows, $offset, $limit),
        'note' => __('Triggers and actions only register for an integration whose plugin is active, so an inactive one reports zero of each until its plugin is installed.', 'nibwp'),
    ];
}

/**
 * Everything one integration contributes to the catalogue.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_describe_integration(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading a SureTriggers integration', 'nibwp'))) {
        return $denied;
    }

    $slug = isset($input['integration']) && is_scalar($input['integration'])
        ? trim((string) $input['integration'])
        : '';

    if ($slug === '') {
        return nibwp_suretriggers_err(
            'missing_integration',
            __('Give the integration slug as integration. Use list_integrations for the valid slugs; they are case-sensitive.', 'nibwp'),
            422
        );
    }

    $integrations = nibwp_suretriggers_integrations();
    $events = nibwp_suretriggers_events();

    $has_events = isset($events['triggers'][$slug]) || isset($events['actions'][$slug]);

    if (!array_key_exists($slug, $integrations) && !$has_events) {
        return nibwp_suretriggers_err(
            'unknown_integration',
            sprintf(
                /* translators: %s: the rejected integration slug */
                __('No integration called "%s". Slugs are case-sensitive; use list_integrations.', 'nibwp'),
                $slug
            ),
            404
        );
    }

    $kind = isset($input['kind']) && is_scalar($input['kind']) ? (string) $input['kind'] : 'both';
    if (!in_array($kind, ['triggers', 'actions', 'both'], true)) {
        $kind = 'both';
    }

    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $triggers = [];
    $actions = [];

    if ($kind !== 'actions' && isset($events['triggers'][$slug]) && is_array($events['triggers'][$slug])) {
        foreach (array_slice($events['triggers'][$slug], 0, $limit, true) as $key => $event) {
            $triggers[] = nibwp_suretriggers_event_row((string) $key, $event);
        }
    }

    if ($kind !== 'triggers' && isset($events['actions'][$slug]) && is_array($events['actions'][$slug])) {
        foreach (array_slice($events['actions'][$slug], 0, $limit, true) as $key => $event) {
            $actions[] = nibwp_suretriggers_event_row((string) $key, $event);
        }
    }

    $object = $integrations[$slug] ?? null;

    return [
        'slug' => $slug,
        'name' => is_object($object) ? nibwp_suretriggers_integration_name($slug, $object) : $slug,
        'plugin_active' => is_object($object) ? nibwp_suretriggers_integration_active($object) : false,
        'kind' => $kind,
        'limit' => $limit,
        'trigger_count' => isset($events['triggers'][$slug]) && is_array($events['triggers'][$slug])
            ? count($events['triggers'][$slug])
            : 0,
        'action_count' => isset($events['actions'][$slug]) && is_array($events['actions'][$slug])
            ? count($events['actions'][$slug])
            : 0,
        'triggers' => $triggers,
        'actions' => $actions,
        'note' => __('A trigger fires when the event happens on this site and the site reports it to the hosted service. An action is something the service can ask this site to do. Building the automation out of them happens in the hosted dashboard, not here.', 'nibwp'),
    ];
}

/**
 * Find a trigger or an action by name across the whole catalogue.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_search_events(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Searching the SureTriggers catalogue', 'nibwp'))) {
        return $denied;
    }

    $query = isset($input['query']) && is_scalar($input['query']) ? trim((string) $input['query']) : '';

    if ($query === '') {
        return nibwp_suretriggers_err('missing_query', __('Give something to search for as query.', 'nibwp'), 422);
    }

    $kind = isset($input['kind']) && is_scalar($input['kind']) ? (string) $input['kind'] : 'both';
    if (!in_array($kind, ['triggers', 'actions', 'both'], true)) {
        $kind = 'both';
    }

    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $needle = strtolower($query);
    $events = nibwp_suretriggers_events();
    $matches = [];
    $total = 0;

    foreach (['triggers', 'actions'] as $bucket) {
        if ($kind !== 'both' && $kind !== $bucket) {
            continue;
        }

        foreach ($events[$bucket] as $slug => $set) {
            if (!is_array($set)) {
                continue;
            }

            foreach ($set as $key => $event) {
                $row = nibwp_suretriggers_event_row((string) $key, $event);
                $haystack = strtolower((string) $key . ' ' . (string) ($row['label'] ?? ''));

                if (!str_contains($haystack, $needle)) {
                    continue;
                }

                $total++;

                // Counted before the ceiling so the caller learns the search was
                // wider than the answer, rather than believing it was complete.
                if (count($matches) < $limit) {
                    $matches[] = ['integration' => (string) $slug, 'kind' => $bucket] + $row;
                }
            }
        }
    }

    return [
        'query' => $query,
        'kind' => $kind,
        'limit' => $limit,
        'total' => $total,
        'truncated' => $total > count($matches),
        'matches' => $matches,
    ];
}

/**
 * What this install could automate and what has quietly stopped being automatable.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_automation_coverage(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers automation coverage', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $subscriptions = nibwp_suretriggers_subscriptions('triggers');
    $events = nibwp_suretriggers_events();

    $used = [];
    foreach ($subscriptions as $row) {
        $used[$row['integration']] = ($used[$row['integration']] ?? 0) + 1;
    }

    $unused = [];
    $used_but_gone = [];

    foreach (nibwp_suretriggers_integrations() as $slug => $integration) {
        $active = nibwp_suretriggers_integration_active($integration);
        $trigger_count = isset($events['triggers'][$slug]) && is_array($events['triggers'][$slug])
            ? count($events['triggers'][$slug])
            : 0;

        if ($active && $trigger_count > 0 && !isset($used[$slug])) {
            $unused[] = [
                'slug' => $slug,
                'name' => nibwp_suretriggers_integration_name($slug, $integration),
                'trigger_count' => $trigger_count,
            ];
        }

        if (!$active && isset($used[$slug])) {
            $used_but_gone[] = [
                'slug' => $slug,
                'name' => nibwp_suretriggers_integration_name($slug, $integration),
                'watched_events' => $used[$slug],
            ];
        }
    }

    return [
        'limit' => $limit,
        'integrations_in_use' => count($used),
        'watched_events' => count($subscriptions),
        'unused_active_total' => count($unused),
        'unused_active' => array_slice($unused, 0, $limit),
        'watched_but_plugin_inactive_total' => count($used_but_gone),
        'watched_but_plugin_inactive' => array_slice($used_but_gone, 0, $limit),
        'note' => __('An entry under watched_but_plugin_inactive is an automation that cannot fire any more and says nothing about it: the hooks it waits on no longer exist on this site.', 'nibwp'),
    ];
}

/**
 * Counts out of the delivery log for a window.
 *
 * @return array<string, int>
 */
function nibwp_suretriggers_log_counts(int $days): array
{
    global $wpdb;

    $empty = ['total' => 0, 'success' => 0, 'failed' => 0, 'pending' => 0];

    if (!nibwp_suretriggers_log_table_exists()) {
        return $empty;
    }

    $table = nibwp_suretriggers_log_table();
    $since = nibwp_suretriggers_since($days);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT status, COUNT(*) AS hits FROM ' . $table . ' WHERE created_at >= %s GROUP BY status',
            $since
        ),
        ARRAY_A
    );

    if (!is_array($rows)) {
        return $empty;
    }

    $counts = $empty;

    foreach ($rows as $row) {
        $status = isset($row['status']) ? (string) $row['status'] : '';
        $hits = isset($row['hits']) ? (int) $row['hits'] : 0;
        $counts['total'] += $hits;

        if (array_key_exists($status, $counts) && $status !== 'total') {
            $counts[$status] += $hits;
        }
    }

    return $counts;
}

/**
 * The delivery log, without what was delivered.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_list_delivery_log(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers delivery log', 'nibwp'))) {
        return $denied;
    }

    if (!nibwp_suretriggers_log_table_exists()) {
        return nibwp_suretriggers_err(
            'no_log_table',
            __('The delivery log table does not exist on this site, so there is nothing to read. That usually means the plugin was never activated properly.', 'nibwp'),
            404
        );
    }

    global $wpdb;

    $status = isset($input['status']) && is_scalar($input['status']) ? (string) $input['status'] : 'all';
    if (!in_array($status, ['all', 'success', 'failed', 'pending'], true)) {
        $status = 'all';
    }

    $days = nibwp_suretriggers_days($input['days'] ?? null);
    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $offset = nibwp_suretriggers_offset($input['offset'] ?? null);
    $since = nibwp_suretriggers_since($days);
    $table = nibwp_suretriggers_log_table();

    $total = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE created_at >= %s AND (%s = %s OR status = %s)',
            $since,
            $status,
            'all',
            $status
        )
    );

    // Only the columns that may travel are named. request_data is the JSON body
    // that was sent — the order, the customer — and error_info is the service's
    // raw reply, which can echo it back. Neither is selected, so neither can be
    // forgotten about in a later edit of the loop below.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, status, response_code, retry_attempts, created_at, processed_at, updated_at'
            . ' FROM ' . $table
            . ' WHERE created_at >= %s AND (%s = %s OR status = %s)'
            . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $since,
            $status,
            'all',
            $status,
            $limit,
            $offset
        ),
        ARRAY_A
    );

    $deliveries = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $code = isset($row['response_code']) ? (int) $row['response_code'] : 0;

        $deliveries[] = [
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'status' => isset($row['status']) ? (string) $row['status'] : '',
            'response_code' => $code,
            'failure_class' => nibwp_suretriggers_failure_class($code),
            'retry_attempts' => isset($row['retry_attempts']) ? (int) $row['retry_attempts'] : 0,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'processed_at' => isset($row['processed_at']) && $row['processed_at'] !== null
                ? (string) $row['processed_at']
                : null,
            'updated_at' => isset($row['updated_at']) && $row['updated_at'] !== null
                ? (string) $row['updated_at']
                : null,
        ];
    }

    return [
        'status' => $status,
        'days' => $days,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'deliveries' => $deliveries,
        'withheld' => [
            'request_payload' => __('The JSON body sent to the service: the order, the customer record, the form submission. Never returned.', 'nibwp'),
            'service_reply' => __('The service\'s raw response body, which can echo the payload back. Never returned.', 'nibwp'),
        ],
        'note' => __('One row is one outbound delivery, not one automation run: the service decides what to do with it afterwards. To read a payload, open the plugin\'s own Status screen in wp-admin.', 'nibwp'),
    ];
}

/**
 * Failures over a window, grouped.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_delivery_failures(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers delivery failures', 'nibwp'))) {
        return $denied;
    }

    if (!nibwp_suretriggers_log_table_exists()) {
        return nibwp_suretriggers_err(
            'no_log_table',
            __('The delivery log table does not exist on this site, so there is nothing to read. That usually means the plugin was never activated properly.', 'nibwp'),
            404
        );
    }

    global $wpdb;

    $days = nibwp_suretriggers_days($input['days'] ?? null);
    $limit = nibwp_suretriggers_limit($input['limit'] ?? null);
    $since = nibwp_suretriggers_since($days);
    $table = nibwp_suretriggers_log_table();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT response_code, COUNT(*) AS hits, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen,'
            . ' MAX(retry_attempts) AS worst_retries'
            . ' FROM ' . $table
            . ' WHERE status = %s AND created_at >= %s'
            . ' GROUP BY response_code ORDER BY hits DESC LIMIT %d',
            'failed',
            $since,
            $limit
        ),
        ARRAY_A
    );

    $groups = [];
    $failed = 0;
    $auth = 0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        $code = isset($row['response_code']) ? (int) $row['response_code'] : 0;
        $hits = isset($row['hits']) ? (int) $row['hits'] : 0;
        $class = nibwp_suretriggers_failure_class($code);
        $failed += $hits;

        if ($class === 'authentication') {
            $auth += $hits;
        }

        $groups[] = [
            'response_code' => $code,
            'failure_class' => $class,
            'count' => $hits,
            'first_seen' => isset($row['first_seen']) ? (string) $row['first_seen'] : null,
            'last_seen' => isset($row['last_seen']) ? (string) $row['last_seen'] : null,
            'worst_retry_attempts' => isset($row['worst_retries']) ? (int) $row['worst_retries'] : 0,
        ];
    }

    // Five attempts is where the plugin's own retry gives up
    // (src/Controllers/WebhookRequestsController.php:203), so a row at or past it
    // is a delivery that will never arrive without somebody acting.
    $exhausted = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE status = %s AND created_at >= %s AND retry_attempts >= %d',
            'failed',
            $since,
            5
        )
    );

    return [
        'days' => $days,
        'limit' => $limit,
        'failed_total' => $failed,
        'authentication_failures' => $auth,
        'retry_exhausted' => $exhausted,
        'groups' => $groups,
        'logging_enabled' => nibwp_suretriggers_logging_enabled(),
        'note' => __('Counts, codes and timings only; no payloads. An authentication class points at the connection, not at the automation. A retry-exhausted row has given up after five attempts and will not arrive on its own.', 'nibwp'),
    ];
}

/**
 * Who may open the automation dashboard on this site.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_access_control(): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Reading the SureTriggers dashboard access settings', 'nibwp'))) {
        return $denied;
    }

    $users = get_option('suretriggers_enabled_users', []);
    $roles = get_option('suretriggers_enabled_user_roles', []);

    $user_ids = [];
    foreach (is_array($users) ? $users : [] as $user) {
        if (is_scalar($user)) {
            $user_ids[] = (int) $user;
        }
    }

    $role_slugs = [];
    foreach (is_array($roles) ? $roles : [] as $role) {
        if (is_scalar($role)) {
            $role_slugs[] = (string) $role;
        }
    }

    return [
        'extra_user_ids' => $user_ids,
        'extra_user_id_count' => count($user_ids),
        'roles' => $role_slugs,
        'role_count' => count($role_slugs),
        'note' => __('Administrators always have access; these are the accounts and roles granted it on top. Read-only here on purpose: granting a role access to the automation dashboard is a privilege change, and it belongs in wp-admin with the consequences in front of someone.', 'nibwp'),
    ];
}

/**
 * What needs attention.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_suretriggers_require(__('Auditing SureTriggers', 'nibwp'))) {
        return $denied;
    }

    $days = nibwp_suretriggers_days($input['days'] ?? null, 30);
    $limit = nibwp_suretriggers_limit($input['limit'] ?? null, 20);
    $findings = [];

    if (!nibwp_suretriggers_linked()) {
        $findings[] = [
            'code' => 'not_linked',
            'severity' => 'high',
            'detail' => __('This site is not linked to the hosted service, so no automation can run on it at all.', 'nibwp'),
            'count' => 1,
            'examples' => [],
        ];
    }

    $verify = nibwp_suretriggers_verify_status();
    if (is_array($verify) && ($verify['healthy'] ?? null) === false) {
        $findings[] = [
            'code' => 'connection_check_failing',
            'severity' => 'high',
            'detail' => (string) ($verify['meaning'] ?? ''),
            'count' => 1,
            'examples' => [],
        ];
    }

    $subscriptions = nibwp_suretriggers_subscriptions('triggers');

    if ($subscriptions === []) {
        $findings[] = [
            'code' => 'nothing_watched',
            'severity' => 'info',
            'detail' => __('No events are being watched. Either no automation has been built for this site in the hosted dashboard, or the service has not pushed its list down yet.', 'nibwp'),
            'count' => 0,
            'examples' => [],
        ];
    }

    $dead = [];
    $unregistered = [];

    foreach ($subscriptions as $row) {
        if ($row['integration_known'] && !$row['integration_active']) {
            $dead[] = $row['integration'] . ' / ' . $row['trigger'];
            continue;
        }
        if (!$row['trigger_registered']) {
            $unregistered[] = $row['integration'] . ' / ' . $row['trigger'];
        }
    }

    if ($dead !== []) {
        $findings[] = [
            'code' => 'trigger_plugin_inactive',
            'severity' => 'high',
            'detail' => __('These events are watched for a plugin that is no longer active. The automations behind them cannot fire and will not say so.', 'nibwp'),
            'count' => count($dead),
            'examples' => array_slice($dead, 0, $limit),
        ];
    }

    if ($unregistered !== []) {
        $findings[] = [
            'code' => 'trigger_not_registered',
            'severity' => 'high',
            'detail' => __('These events are watched but this install registers no such trigger, so no hook is attached and nothing will ever fire. Usually a version drift between the plugin and the automation that was built against it.', 'nibwp'),
            'count' => count($unregistered),
            'examples' => array_slice($unregistered, 0, $limit),
        ];
    }

    $test = nibwp_suretriggers_subscriptions('test_triggers');

    if ($test !== []) {
        $leftovers = [];
        foreach ($test as $row) {
            $leftovers[] = $row['integration'] . ' / ' . $row['trigger'];
        }

        $findings[] = [
            'code' => 'leftover_test_subscriptions',
            'severity' => 'medium',
            'detail' => __('Test subscriptions from the hosted builder are still here. They attach real hooks, so they are live listeners nobody remembers asking for.', 'nibwp'),
            'count' => count($leftovers),
            'examples' => array_slice($leftovers, 0, $limit),
        ];
    }

    if (!nibwp_suretriggers_log_table_exists()) {
        $findings[] = [
            'code' => 'log_table_missing',
            'severity' => 'high',
            'detail' => __('The delivery log table does not exist, so nothing can be recorded and nothing failed can be retried. The plugin creates it on activation.', 'nibwp'),
            'count' => 1,
            'examples' => [],
        ];
    } elseif (!nibwp_suretriggers_logging_enabled()) {
        $findings[] = [
            'code' => 'logging_disabled',
            'severity' => 'medium',
            'detail' => __('Delivery logging is off. Nothing is being recorded and failed deliveries are never retried, so an automation that fails fails silently.', 'nibwp'),
            'count' => 1,
            'examples' => [],
        ];
    }

    $missing = [];
    foreach (nibwp_suretriggers_schedules() as $hook => $what) {
        if (wp_next_scheduled($hook) === false) {
            $missing[] = $hook;
        }
    }

    if ($missing !== []) {
        $findings[] = [
            'code' => 'schedule_missing',
            'severity' => 'medium',
            'detail' => __('A background schedule is not armed. Reported, not fixed: arming the retry schedule re-sends every failed delivery, which fires those automations again downstream, so a person does it in wp-admin.', 'nibwp'),
            'count' => count($missing),
            'examples' => array_slice($missing, 0, $limit),
        ];
    }

    if (nibwp_suretriggers_log_table_exists()) {
        $failures = nibwp_suretriggers_delivery_failures(['days' => $days, 'limit' => $limit]);

        if (!$failures instanceof WP_Error) {
            $repeated = [];

            foreach ($failures['groups'] as $group) {
                if ($group['count'] >= 3) {
                    $repeated[] = sprintf(
                        /* translators: 1: how many times, 2: HTTP status code, 3: one-word classification */
                        __('%1$d x HTTP %2$d (%3$s)', 'nibwp'),
                        $group['count'],
                        $group['response_code'],
                        $group['failure_class']
                    );
                }
            }

            if ($repeated !== []) {
                $findings[] = [
                    'code' => 'repeated_delivery_failures',
                    'severity' => 'high',
                    'detail' => __('The same failure keeps happening, so this is a standing fault rather than a blip.', 'nibwp'),
                    'count' => count($repeated),
                    'examples' => array_slice($repeated, 0, $limit),
                ];
            }

            if ($failures['authentication_failures'] > 0) {
                $findings[] = [
                    'code' => 'authentication_failures',
                    'severity' => 'high',
                    'detail' => __('Deliveries are being refused as unauthenticated. That is the connection, not the automation: the site needs re-linking in the hosted dashboard.', 'nibwp'),
                    'count' => (int) $failures['authentication_failures'],
                    'examples' => [],
                ];
            }

            if ($failures['retry_exhausted'] > 0) {
                $findings[] = [
                    'code' => 'retry_exhausted',
                    'severity' => 'medium',
                    'detail' => __('Deliveries have used all five attempts and will not arrive on their own. Whatever those automations were meant to do did not happen.', 'nibwp'),
                    'count' => (int) $failures['retry_exhausted'],
                    'examples' => [],
                ];
            }
        }
    }

    $severity = 'ok';
    foreach ($findings as $finding) {
        if ($finding['severity'] === 'high') {
            $severity = 'high';
            break;
        }
        if ($finding['severity'] === 'medium') {
            $severity = 'medium';
        }
    }

    return [
        'days' => $days,
        'limit' => $limit,
        'worst_severity' => $severity,
        'finding_count' => count($findings),
        'findings' => array_slice($findings, 0, $limit),
        'note' => __('This audits what is local. An automation switched off in the hosted dashboard leaves no trace here, so a quiet automation is not necessarily a broken one — the dashboard is the place to check that.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Turn delivery logging on or off.
 *
 * The one switch this site genuinely owns. Off, the plugin records nothing
 * (src/Controllers/WebhookRequestsController.php:156) — and because the retry
 * schedule works from those same rows, a delivery that fails is then never
 * retried and leaves no trace. That is site behaviour changing with nobody
 * watching, so the response says exactly what changed and what it costs.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_suretriggers_set_logging(bool $enable): array|WP_Error
{
    $what = $enable
        ? __('Turning SureTriggers delivery logging on', 'nibwp')
        : __('Turning SureTriggers delivery logging off', 'nibwp');

    if ($denied = nibwp_suretriggers_require($what)) {
        return $denied;
    }

    $was = nibwp_suretriggers_logging_enabled();

    // A wp-config constant overrides the option entirely, so writing the option
    // would report a change that does not happen. Say so instead.
    if (defined('SURETRIGGERS_DISABLE_LOGGING') && constant('SURETRIGGERS_DISABLE_LOGGING')) {
        return nibwp_suretriggers_err(
            'logging_locked',
            __('Delivery logging is switched off by a constant in wp-config.php, which overrides this setting. Changing it here would do nothing. Edit wp-config.php.', 'nibwp'),
            409
        );
    }

    update_option('suretriggers_disable_request_logging', !$enable);

    $now = nibwp_suretriggers_logging_enabled();

    return [
        'logging_enabled' => $now,
        'was_enabled' => $was,
        'changed' => $was !== $now,
        'option' => 'suretriggers_disable_request_logging',
        'what_this_changes' => $enable
            ? __('Every outbound delivery is recorded again from now on, and failed ones become eligible for the automatic retry. Deliveries missed while it was off are not recoverable.', 'nibwp')
            : __('Nothing will be recorded from now on, and failed deliveries will never be retried. An automation that fails will fail silently and leave nothing to look at afterwards.', 'nibwp'),
        'note' => __('Tell whoever asked what this now does and does not do. It changed site behaviour and nobody else was watching.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------ helpers */

/** The billing plan the service last reported, if any. */
function nibwp_suretriggers_plan_id(): ?string
{
    $data = get_option('suretriggers_lifetime_user_plan_data');

    if (!is_array($data) || !isset($data['plan_id']) || !is_scalar($data['plan_id'])) {
        return null;
    }

    $plan = (string) $data['plan_id'];

    return $plan === '' ? null : $plan;
}

/** One plain scalar option, or null. */
function nibwp_suretriggers_scalar_option(string $key): ?string
{
    $value = get_option($key);

    if (!is_scalar($value) || (string) $value === '') {
        return null;
    }

    return (string) $value;
}

/**
 * What the plugin's own six-hourly connection check last concluded.
 *
 * The string is written by the plugin's cron, not by us: this reads the verdict,
 * it does not reach the service to get a fresh one.
 *
 * @return array<string, mixed>
 */
function nibwp_suretriggers_verify_status(): array
{
    $status = get_option('suretriggers_verify_connection', '');
    $status = is_scalar($status) ? (string) $status : '';

    $meaning = match ($status) {
        'suretriggers_connection_successful' => __('The last check reached the service and it accepted this site.', 'nibwp'),
        'suretriggers_connection_error' => __('The last check reached the service and it refused this site. Automations will not run until it is re-linked.', 'nibwp'),
        'suretriggers_connection_wp_error' => __('The last check could not reach the service from this server at all — a network or firewall problem on this side.', 'nibwp'),
        default => __('No check has recorded a verdict yet, so the connection state is unverified rather than known good.', 'nibwp'),
    };

    return [
        'status' => $status,
        'healthy' => $status === 'suretriggers_connection_successful'
            ? true
            : ($status === '' ? null : false),
        'meaning' => $meaning,
        'checked_remotely_now' => false,
    ];
}
