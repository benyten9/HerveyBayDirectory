<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WP Mail SMTP integration for NIBWP (Pro tier).
 *
 * One hub ability that answers the question a site owner actually has: can this
 * site send mail, and if not, what is wrong. A store whose "order complete"
 * notification silently fails looks healthy from every other angle — the order
 * is there, the payment cleared, nothing is logged where anyone looks — so
 * `status` is the action this file exists for. Everything else supports it.
 *
 * CREDENTIALS NEVER LEAVE. This plugin's whole job is holding SMTP passwords,
 * provider API keys, OAuth client secrets and refresh tokens. Reading them back
 * over REST or MCP would hand an agent the keys to the site's mail, so no action
 * here returns one — not in full, not masked, not truncated. For every setting
 * the answer is its NAME, whether it is set, and where the value comes from.
 *
 * The rule is enforced by an ALLOW-LIST (nibwp_wpms_public_fields()) of the
 * fields whose value may travel, not a denylist of names somebody remembered.
 * That direction matters: WP Mail SMTP's own storage already carries keys its
 * options map never declares — `auth_code`, `access_token`, `user_details` and
 * the SendLayer quick-connect fields are written by
 * process_mailer_specific_options() (src/Options.php:1607-1628) and read back by
 * get_group(), and Pro adds more. A denylist would have shipped all of those.
 *
 * Verified against WP Mail SMTP 4.10.0 (wp_mail_smtp.php:4). That build is Lite:
 * there is no src/Pro, so it has NO email log — LogsTab is a placeholder
 * (src/Admin/Pages/LogsTab.php:9) and the Email Log dashboard widget returns its
 * education state without ever querying a table
 * (src/Admin/Dashboard/Widgets/EmailLog.php:68-71). What this build does keep is
 * the Debug Events table, which is where send failures land, so the log actions
 * here read that. Nothing in this build can re-send a recorded email, so no
 * action pretends to.
 *
 * REQUIRES: WP Mail SMTP active (Lite or Pro).
 */

/** One guard for every action: is WP Mail SMTP even here? */
function nibwp_wpms_guard(): ?WP_Error
{
    if (!function_exists('wp_mail_smtp') || !class_exists('WPMailSMTP\\Options')) {
        return new WP_Error(
            'nibwp_wpms_missing',
            __('WP Mail SMTP is not active on this site.', 'nibwp')
        );
    }

    return null;
}

/**
 * The plugin's Options singleton, or null when it cannot be had.
 *
 * Options::init() is the only supported way in (src/Options.php:242); the
 * constructor is deprecated. A throw here means a broken install, not a missing
 * one, so callers get null and say so rather than fataling.
 */
function nibwp_wpms_options(): ?object
{
    static $options = null;

    if ($options !== null) {
        return $options;
    }
    if (nibwp_wpms_guard() !== null) {
        return null;
    }

    try {
        $candidate = \WPMailSMTP\Options::init();
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_object($candidate) || !method_exists($candidate, 'get')) {
        return null;
    }

    $options = $candidate;

    return $options;
}

/** The plugin's Core singleton, or null. */
function nibwp_wpms_core(): ?object
{
    if (!function_exists('wp_mail_smtp')) {
        return null;
    }

    try {
        $core = wp_mail_smtp();
    } catch (\Throwable $e) {
        return null;
    }

    return is_object($core) ? $core : null;
}

/**
 * Every setting group WP Mail SMTP recognises, and the keys in each.
 *
 * Mirrors Options::$map (src/Options.php:29-153), which is private, plus the
 * keys process_mailer_specific_options() writes but never declares there
 * (src/Options.php:1607-1628), the OAuth keys AuthAbstract writes straight into
 * the mailer's group (src/Providers/AuthAbstract.php:157-210), and the `general`
 * and `debug_events` keys process_generic_options() handles
 * (src/Options.php:1499-1526).
 *
 * This list decides which settings get NAMED. It never decides which get
 * valued — nibwp_wpms_public_fields() does that, and anything this list misses
 * is still named at runtime from the keys the stored row actually holds.
 *
 * @return array<string, list<string>>
 */
function nibwp_wpms_known_fields(): array
{
    return [
        'mail' => ['from_name', 'from_email', 'mailer', 'return_path', 'from_name_force', 'from_email_force'],
        'smtp' => ['host', 'port', 'encryption', 'autotls', 'auth', 'user', 'pass'],
        'gmail' => ['one_click_setup_enabled', 'client_id', 'client_secret', 'auth_code', 'access_token', 'refresh_token', 'scopes', 'is_setup_wizard_auth'],
        'outlook' => ['one_click_setup_enabled', 'client_id', 'client_secret', 'auth_code', 'access_token', 'refresh_token', 'scopes', 'is_setup_wizard_auth', 'user_details'],
        'zoho' => ['domain', 'client_id', 'client_secret', 'access_token', 'refresh_token', 'scopes', 'is_setup_wizard_auth', 'user_details'],
        'amazonses' => ['client_id', 'client_secret', 'region'],
        'mailgun' => ['api_key', 'domain', 'region'],
        'mailjet' => ['api_key', 'secret_key'],
        'mailersend' => ['api_key', 'has_pro_plan'],
        'mandrill' => ['api_key'],
        'sendgrid' => ['api_key', 'domain'],
        'sparkpost' => ['api_key', 'region'],
        'postmark' => ['server_api_token', 'message_stream'],
        'smtpcom' => ['api_key', 'channel'],
        'sendinblue' => ['api_key', 'domain'],
        'sendlayer' => ['api_key', 'quick_connect', 'is_shared_domain', 'sender_domain'],
        'elasticemail' => ['api_key'],
        'smtp2go' => ['api_key'],
        'resend' => ['api_key'],
        'pepipostapi' => ['api_key'],
        'pepipost' => ['host', 'port', 'encryption', 'auth', 'user', 'pass'],
        'license' => ['key', 'type'],
        'general' => [
            'do_not_send',
            'am_notifications_hidden',
            'email_delivery_errors_hidden',
            'dashboard_widget_hidden',
            'uninstall',
            'usage-tracking-enabled',
            'summary_report_email_disabled',
            'optimize_email_sending_enabled',
        ],
        'debug_events' => ['email_debug', 'retention_period'],
        'alert_email' => ['enabled', 'connections'],
        'alert_slack_webhook' => ['enabled', 'connections'],
        'alert_discord_webhook' => ['enabled', 'connections'],
        'alert_teams_webhook' => ['enabled', 'connections'],
        'alert_twilio_sms' => ['enabled', 'connections'],
        'alert_custom_webhook' => ['enabled', 'connections'],
        'alert_push_notifications' => ['enabled', 'connections'],
        'alert_whatsapp' => ['enabled', 'connections'],
        'alert_events' => ['email_hard_bounced'],
    ];
}

/**
 * THE ALLOW-LIST. A setting's value may leave this site only if it is named
 * here. Everything else — including every key this file has never heard of —
 * comes back as a name and an is_set boolean.
 *
 * Judgement calls, so they are not silently re-litigated later:
 *
 *  - `smtp.user` is absent. WP Mail SMTP's own CLI registry does not mark it
 *    sensitive (src/WPCLI/Options/Registry.php:236-241), but it is one half of a
 *    credential pair and nothing here needs it. The mailer's own diagnostics
 *    settle for "Yes/No" on the pair, so this does too.
 *  - OAuth `client_id` is absent for gmail/outlook/zoho, and `amazonses.client_id`
 *    is an AWS access key ID that the same registry does mark sensitive
 *    (src/WPCLI/Options/Registry.php:551-557).
 *  - `license.key` is absent; `license.type` (lite/pro/elite) is not a secret.
 *  - every alert channel exposes `enabled` only. `connections` is an array of
 *    webhook URLs, Twilio auth tokens and WhatsApp access tokens
 *    (src/Options.php:846-1000).
 *
 * @return array<string, list<string>>
 */
function nibwp_wpms_public_fields(): array
{
    return [
        'mail' => ['from_name', 'from_email', 'mailer', 'return_path', 'from_name_force', 'from_email_force'],
        'smtp' => ['host', 'port', 'encryption', 'autotls', 'auth'],
        'gmail' => ['one_click_setup_enabled', 'scopes', 'is_setup_wizard_auth'],
        'outlook' => ['one_click_setup_enabled', 'scopes', 'is_setup_wizard_auth'],
        'zoho' => ['domain', 'scopes', 'is_setup_wizard_auth'],
        'amazonses' => ['region'],
        'mailgun' => ['domain', 'region'],
        'mailjet' => [],
        'mailersend' => ['has_pro_plan'],
        'mandrill' => [],
        'sendgrid' => ['domain'],
        'sparkpost' => ['region'],
        'postmark' => ['message_stream'],
        'smtpcom' => ['channel'],
        'sendinblue' => ['domain'],
        'sendlayer' => ['quick_connect', 'is_shared_domain', 'sender_domain'],
        'elasticemail' => [],
        'smtp2go' => [],
        'resend' => [],
        'pepipostapi' => [],
        'pepipost' => ['host', 'port', 'encryption', 'auth'],
        'license' => ['type'],
        'general' => [
            'do_not_send',
            'am_notifications_hidden',
            'email_delivery_errors_hidden',
            'dashboard_widget_hidden',
            'uninstall',
            'usage-tracking-enabled',
            'summary_report_email_disabled',
            'optimize_email_sending_enabled',
        ],
        'debug_events' => ['email_debug', 'retention_period'],
        'alert_email' => ['enabled'],
        'alert_slack_webhook' => ['enabled'],
        'alert_discord_webhook' => ['enabled'],
        'alert_teams_webhook' => ['enabled'],
        'alert_twilio_sms' => ['enabled'],
        'alert_custom_webhook' => ['enabled'],
        'alert_push_notifications' => ['enabled'],
        'alert_whatsapp' => ['enabled'],
        'alert_events' => ['email_hard_bounced'],
    ];
}

/**
 * The wp-config.php constant that overrides each setting, so a report can say
 * WHERE a value came from without saying what it is.
 *
 * Transcribed from Options::is_const_defined() (src/Options.php:1041-1400). The
 * whole layer is inert unless WPMS_ON is true (src/Options.php:1007), which is
 * why every report also carries `constants_enabled`.
 *
 * Two asymmetries in the plugin worth knowing about: `alert_teams_webhook` has a
 * constant but is absent from the options map, and `alert_push_notifications` is
 * in the map with no constant.
 *
 * @return array<string, array<string, string>>
 */
function nibwp_wpms_constant_map(): array
{
    return [
        'mail' => [
            'from_name' => 'WPMS_MAIL_FROM_NAME',
            'from_email' => 'WPMS_MAIL_FROM',
            'mailer' => 'WPMS_MAILER',
            'return_path' => 'WPMS_SET_RETURN_PATH',
            'from_name_force' => 'WPMS_MAIL_FROM_NAME_FORCE',
            'from_email_force' => 'WPMS_MAIL_FROM_FORCE',
        ],
        'smtp' => [
            'host' => 'WPMS_SMTP_HOST',
            'port' => 'WPMS_SMTP_PORT',
            'encryption' => 'WPMS_SSL',
            'auth' => 'WPMS_SMTP_AUTH',
            'autotls' => 'WPMS_SMTP_AUTOTLS',
            'user' => 'WPMS_SMTP_USER',
            'pass' => 'WPMS_SMTP_PASS',
        ],
        'sendlayer' => ['api_key' => 'WPMS_SENDLAYER_API_KEY'],
        'gmail' => [
            'client_id' => 'WPMS_GMAIL_CLIENT_ID',
            'client_secret' => 'WPMS_GMAIL_CLIENT_SECRET',
        ],
        'outlook' => [
            'client_id' => 'WPMS_OUTLOOK_CLIENT_ID',
            'client_secret' => 'WPMS_OUTLOOK_CLIENT_SECRET',
        ],
        'zoho' => [
            'domain' => 'WPMS_ZOHO_DOMAIN',
            'client_id' => 'WPMS_ZOHO_CLIENT_ID',
            'client_secret' => 'WPMS_ZOHO_CLIENT_SECRET',
        ],
        'amazonses' => [
            'client_id' => 'WPMS_AMAZONSES_CLIENT_ID',
            'client_secret' => 'WPMS_AMAZONSES_CLIENT_SECRET',
            'region' => 'WPMS_AMAZONSES_REGION',
        ],
        'mailgun' => [
            'api_key' => 'WPMS_MAILGUN_API_KEY',
            'domain' => 'WPMS_MAILGUN_DOMAIN',
            'region' => 'WPMS_MAILGUN_REGION',
        ],
        'mailjet' => [
            'api_key' => 'WPMS_MAILJET_API_KEY',
            'secret_key' => 'WPMS_MAILJET_SECRET_KEY',
        ],
        'sendgrid' => [
            'api_key' => 'WPMS_SENDGRID_API_KEY',
            'domain' => 'WPMS_SENDGRID_DOMAIN',
        ],
        'sparkpost' => [
            'api_key' => 'WPMS_SPARKPOST_API_KEY',
            'region' => 'WPMS_SPARKPOST_REGION',
        ],
        'postmark' => [
            'server_api_token' => 'WPMS_POSTMARK_SERVER_API_TOKEN',
            'message_stream' => 'WPMS_POSTMARK_MESSAGE_STREAM',
        ],
        'smtpcom' => [
            'api_key' => 'WPMS_SMTPCOM_API_KEY',
            'channel' => 'WPMS_SMTPCOM_CHANNEL',
        ],
        'sendinblue' => [
            'api_key' => 'WPMS_SENDINBLUE_API_KEY',
            'domain' => 'WPMS_SENDINBLUE_DOMAIN',
        ],
        'elasticemail' => ['api_key' => 'WPMS_ELASTICEMAIL_API_KEY'],
        'smtp2go' => ['api_key' => 'WPMS_SMTP2GO_API_KEY'],
        'resend' => ['api_key' => 'WPMS_RESEND_API_KEY'],
        'pepipostapi' => ['api_key' => 'WPMS_PEPIPOST_API_KEY'],
        'mailersend' => [
            'api_key' => 'WPMS_MAILERSEND_API_KEY',
            'has_pro_plan' => 'WPMS_MAILERSEND_HAS_PRO_PLAN',
        ],
        'mandrill' => ['api_key' => 'WPMS_MANDRILL_API_KEY'],
        'license' => ['key' => 'WPMS_LICENSE_KEY'],
        'general' => [
            'do_not_send' => 'WPMS_DO_NOT_SEND',
            'summary_report_email_disabled' => 'WPMS_SUMMARY_REPORT_EMAIL_DISABLED',
            'optimize_email_sending_enabled' => 'WPMS_OPTIMIZED_EMAIL_SENDING_ENABLED',
        ],
        'debug_events' => ['retention_period' => 'WPMS_DEBUG_EVENTS_RETENTION_PERIOD'],
        'alert_email' => ['connections' => 'WPMS_ALERT_EMAIL_SEND_TO'],
        'alert_slack_webhook' => ['connections' => 'WPMS_ALERT_SLACK_WEBHOOK_URL'],
        'alert_discord_webhook' => ['connections' => 'WPMS_ALERT_DISCORD_WEBHOOK_URL'],
        'alert_teams_webhook' => ['connections' => 'WPMS_ALERT_TEAMS_WEBHOOK_URL'],
        'alert_twilio_sms' => ['connections' => 'WPMS_ALERT_TWILIO_SMS_ACCOUNT_SID'],
        'alert_custom_webhook' => ['connections' => 'WPMS_ALERT_CUSTOM_WEBHOOK_URL'],
        'alert_whatsapp' => ['connections' => 'WPMS_ALERT_WHATSAPP_ACCESS_TOKEN'],
    ];
}

/** Whether a setting value counts as configured. */
function nibwp_wpms_is_set($value): bool
{
    if ($value === null || $value === false || $value === '') {
        return false;
    }
    if (is_array($value)) {
        return $value !== [];
    }

    return true;
}

/** Is the constants layer switched on at all (WPMS_ON)? */
function nibwp_wpms_constants_enabled(): bool
{
    $options = nibwp_wpms_options();

    if ($options === null || !method_exists($options, 'is_const_enabled')) {
        return false;
    }

    try {
        return (bool) $options->is_const_enabled();
    } catch (\Throwable $e) {
        return false;
    }
}

/** Does a wp-config.php constant currently win over the stored value? */
function nibwp_wpms_is_const(string $group, string $key): bool
{
    $options = nibwp_wpms_options();

    if ($options === null || !method_exists($options, 'is_const_defined')) {
        return false;
    }

    try {
        return (bool) $options->is_const_defined($group, $key);
    } catch (\Throwable $e) {
        return false;
    }
}

/** One setting's resolved value, or null when it cannot be read. */
function nibwp_wpms_get(string $group, string $key)
{
    $options = nibwp_wpms_options();

    if ($options === null) {
        return null;
    }

    try {
        return $options->get($group, $key);
    } catch (\Throwable $e) {
        return null;
    }
}

/** One group's stored values as the plugin resolves them. */
function nibwp_wpms_get_group(string $group): array
{
    $options = nibwp_wpms_options();

    if ($options === null || !method_exists($options, 'get_group')) {
        return [];
    }

    try {
        $values = $options->get_group($group);
    } catch (\Throwable $e) {
        return [];
    }

    return is_array($values) ? $values : [];
}

/**
 * Report one setting group: values for the allow-listed fields, names and
 * is_set flags for everything else.
 *
 * The field list is the union of what this file knows about and what the stored
 * row actually holds, so a setting a future version adds is reported by name
 * and never by value. That is the whole point of the union: a key nobody has
 * classified is treated as a credential until somebody says otherwise.
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_group_report(string $group): array
{
    $public = (array) (nibwp_wpms_public_fields()[$group] ?? []);
    $known = (array) (nibwp_wpms_known_fields()[$group] ?? []);
    $constants = (array) (nibwp_wpms_constant_map()[$group] ?? []);
    $stored = nibwp_wpms_get_group($group);

    $names = $known;
    foreach (array_keys($stored) as $key) {
        $key = (string) $key;
        if (!in_array($key, $names, true)) {
            $names[] = $key;
        }
    }
    sort($names);

    $settings = [];
    $fields = [];

    foreach ($names as $key) {
        $value = array_key_exists($key, $stored) ? $stored[$key] : nibwp_wpms_get($group, $key);
        $is_const = nibwp_wpms_is_const($group, $key);
        $may_travel = in_array($key, $public, true);

        if ($may_travel) {
            $settings[$key] = $value;
        }

        $fields[] = [
            'name' => $key,
            'is_set' => nibwp_wpms_is_set($value),
            'value_returned' => $may_travel,
            'source' => $is_const
                ? 'constant'
                : (array_key_exists($key, $stored) ? 'database' : 'default'),
            'constant' => (string) ($constants[$key] ?? '') !== '' ? (string) $constants[$key] : null,
        ];
    }

    return [
        'group' => $group,
        'constants_enabled' => nibwp_wpms_constants_enabled(),
        'settings' => $settings,
        'fields' => $fields,
        'withheld' => __('Any field with value_returned false is withheld on purpose. Credentials are never returned by this tool, masked or otherwise.', 'nibwp'),
    ];
}

/**
 * Every credential value currently configured, for the scrub below. Never
 * returned to a caller — the only consumer is nibwp_wpms_scrub().
 *
 * @return list<string>
 */
function nibwp_wpms_secret_values(): array
{
    static $secrets = null;

    if (is_array($secrets)) {
        return $secrets;
    }

    $options = nibwp_wpms_options();

    // Deliberately not cached on this branch: the plugin may simply not have
    // booted yet, and caching an empty list would make the scrub a no-op for the
    // rest of the request.
    if ($options === null) {
        return [];
    }

    $secrets = [];
    $public = nibwp_wpms_public_fields();

    // Resolved values first, because that is where a wp-config.php constant's
    // value appears — get() returns the constant, not the row.
    foreach (nibwp_wpms_known_fields() as $group => $keys) {
        $allowed = (array) ($public[$group] ?? []);

        foreach ($keys as $key) {
            if (in_array($key, $allowed, true)) {
                continue;
            }

            nibwp_wpms_collect_secret(nibwp_wpms_get($group, $key), $secrets);
        }
    }

    // Then the raw stored row. Two reasons it is not redundant: a value a
    // constant shadows is still sitting in the options table, and a group's own
    // nested arrays — an alert channel's connections, an OAuth token bundle —
    // hold more strings than the one key that names them.
    if (method_exists($options, 'get_all_raw')) {
        try {
            $raw = $options->get_all_raw();
        } catch (\Throwable $e) {
            $raw = [];
        }

        foreach ((array) $raw as $group => $row) {
            if (!is_array($row)) {
                continue;
            }

            $allowed = (array) ($public[(string) $group] ?? []);

            foreach ($row as $key => $value) {
                if (in_array((string) $key, $allowed, true)) {
                    continue;
                }

                nibwp_wpms_collect_secret($value, $secrets);
            }
        }
    }

    $secrets = array_values(array_unique($secrets));

    return $secrets;
}

/**
 * Add one stored value, or every string inside it, to the scrub list.
 *
 * Six characters is the floor: shorter strings collide with ordinary words, and
 * scrubbing those would mangle the diagnostics this file exists to report.
 *
 * @param list<string> $secrets
 */
function nibwp_wpms_collect_secret($value, array &$secrets): void
{
    if (is_array($value)) {
        foreach ($value as $item) {
            nibwp_wpms_collect_secret($item, $secrets);
        }

        return;
    }

    if (is_string($value) && strlen($value) >= 6) {
        $secrets[] = $value;
    }
}

/**
 * Strip credentials out of provider prose before it is returned.
 *
 * Only free text goes through here — a mailer's error message, a debug event's
 * body, a domain-check note. Structured settings never carry a credential in the
 * first place, because the allow-list decides what they hold; this is the
 * backstop for the strings a remote server or PHPMailer composed, which nobody
 * here controls.
 *
 * The AUTH pass matters more than it looks. PHPMailer hides the credential lines
 * at SMTPDebug level 3, and level 3 is what the plugin sets
 * (src/MailCatcherTrait.php:241-243) — but the
 * `wp_mail_smtp_admin_test_email_smtp_debug` filter can raise it to 4, and at
 * level 4 PHPMailer emits the raw base64 lines at level 1, which is under the
 * cut debug_output_callback() applies (src/MailCatcherTrait.php:752-757). Those
 * lines then land in the debug event body (src/MailCatcherTrait.php:336-342).
 */
function nibwp_wpms_scrub(string $text): string
{
    if ($text === '') {
        return $text;
    }

    foreach (nibwp_wpms_secret_values() as $secret) {
        $text = str_replace($secret, '[redacted]', $text);
    }

    $text = (string) preg_replace('/\b(AUTH\s+(?:LOGIN|PLAIN|XOAUTH2|CRAM-MD5))\s+\S+/i', '$1 [redacted]', $text);
    $text = (string) preg_replace('/(CLIENT -> SERVER:\s*)[A-Za-z0-9+\/]{16,}={0,2}(?=\s|$)/i', '$1[redacted]', $text);

    return $text;
}

/**
 * Turn a nested credential row into names and is_set flags.
 *
 * Used for the alert channels, whose `connections` entries hold webhook URLs,
 * Twilio auth tokens and WhatsApp access tokens. One level deep on purpose: the
 * job is to name fields, not to walk a tree looking for something to print.
 *
 * @return list<array<string, mixed>>
 */
function nibwp_wpms_names_and_flags(array $row): array
{
    $out = [];

    foreach ($row as $key => $value) {
        $out[] = [
            'name' => (string) $key,
            'is_set' => nibwp_wpms_is_set($value),
            'value_returned' => false,
        ];
    }

    return $out;
}

/**
 * The capability for writing settings and for reading logged mail.
 *
 * Both are plugin-wide: one is the configuration every email on the site goes
 * through, the other is the recipients and contents of real messages. WP Mail
 * SMTP raises its own bar to manage_network_options on a multisite that keeps
 * settings on the main site (src/Core.php:1434-1450), so ask the plugin rather
 * than deciding here and disagreeing with it.
 */
function nibwp_wpms_require_manage(string $what): ?WP_Error
{
    $cap = 'manage_options';
    $core = nibwp_wpms_core();

    if ($core !== null && method_exists($core, 'get_capability_manage_global_options')) {
        try {
            $candidate = (string) $core->get_capability_manage_global_options();
            if ($candidate !== '') {
                $cap = $candidate;
            }
        } catch (\Throwable $e) {
            $cap = 'manage_options';
        }
    }

    if (!current_user_can($cap)) {
        return new WP_Error('nibwp_forbidden', sprintf(
            /* translators: 1: what was being attempted, 2: WordPress capability name. */
            __('%1$s needs the %2$s capability.', 'nibwp'),
            $what,
            $cap
        ), ['status' => 403]);
    }

    return null;
}

/** The hard ceiling on any listing, whatever a caller asks for. */
function nibwp_wpms_max_per_page(): int
{
    return 100;
}

/** Clamp a requested page size to something a response can carry. */
function nibwp_wpms_bound($requested, int $default = 20): int
{
    $value = is_numeric($requested) ? (int) $requested : $default;

    return max(1, min(nibwp_wpms_max_per_page(), $value));
}

/** The active mailer slug, or an empty string. */
function nibwp_wpms_mailer_slug(): string
{
    return (string) nibwp_wpms_get('mail', 'mailer');
}

/** A mailer's display title, falling back to its slug. */
function nibwp_wpms_mailer_title(string $slug): string
{
    $core = nibwp_wpms_core();

    if ($core === null || !method_exists($core, 'get_providers') || $slug === '') {
        return $slug;
    }

    try {
        $providers = $core->get_providers();
        if (!is_object($providers) || !method_exists($providers, 'get_options')) {
            return $slug;
        }
        $options = $providers->get_options($slug);
        if (is_object($options) && method_exists($options, 'get_title')) {
            return (string) $options->get_title();
        }
    } catch (\Throwable $e) {
        return $slug;
    }

    return $slug;
}

/**
 * Is the active mailer set up completely enough to send?
 *
 * The answer lives on the Mailer object, not on Options
 * (src/Providers/MailerInterface.php:49), and getting one needs a PHPMailer
 * instance — so this follows the plugin's own shortcut
 * (src/Admin/Dashboard/Widgets/AbstractWidget.php:310-321) and returns null when
 * it cannot reach a verdict rather than guessing at one.
 */
function nibwp_wpms_mailer_complete(): ?bool
{
    $core = nibwp_wpms_core();

    if ($core === null || !method_exists($core, 'get_connections_manager')) {
        return null;
    }

    try {
        $manager = $core->get_connections_manager();
        if (!is_object($manager) || !method_exists($manager, 'get_primary_connection')) {
            return null;
        }

        $connection = $manager->get_primary_connection();
        if (!is_object($connection) || !method_exists($connection, 'get_mailer_slug')) {
            return null;
        }

        if (in_array((string) $connection->get_mailer_slug(), ['', 'mail'], true)) {
            // The PHP mail() mailer is always "complete"; whether the server
            // will actually deliver is a different question, reported separately.
            return true;
        }

        if (!method_exists($connection, 'get_mailer')) {
            return null;
        }

        $mailer = $connection->get_mailer();
        if (!is_object($mailer) || !method_exists($mailer, 'is_mailer_complete')) {
            return null;
        }

        return (bool) $mailer->is_mailer_complete();
    } catch (\Throwable $e) {
        return null;
    }
}

/** The primary connection object, or null. */
function nibwp_wpms_primary_connection(): ?object
{
    $core = nibwp_wpms_core();

    if ($core === null || !method_exists($core, 'get_connections_manager')) {
        return null;
    }

    try {
        $manager = $core->get_connections_manager();
        if (!is_object($manager) || !method_exists($manager, 'get_primary_connection')) {
            return null;
        }
        $connection = $manager->get_primary_connection();
    } catch (\Throwable $e) {
        return null;
    }

    return is_object($connection) ? $connection : null;
}

/**
 * The last recorded send failure for a connection, allow-listed.
 *
 * The stored record also carries `mailer_debug_info` and `smtp_debug_info`
 * (src/MailCatcherTrait.php:645-668). Neither travels: the second is raw
 * PHPMailer session output, and the first is whatever a mailer chose to put in
 * a string, including Pro mailers this file has never seen.
 *
 * @return array<string, mixed>|null
 */
function nibwp_wpms_last_failure(string $connection_id = 'primary'): ?array
{
    if (!class_exists('WPMailSMTP\\EmailSendingDebug')) {
        return null;
    }

    try {
        $record = \WPMailSMTP\EmailSendingDebug::get($connection_id);
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($record) || $record === []) {
        return null;
    }

    $conflicts = [];
    foreach ((array) ($record['conflicts'] ?? []) as $name) {
        $conflicts[] = nibwp_wpms_scrub((string) $name);
    }

    $occurred = (int) ($record['occurred_at'] ?? 0);

    return [
        'connection_id' => $connection_id,
        'occurred_at' => $occurred,
        'occurred_at_gmt' => $occurred > 0 ? gmdate('Y-m-d H:i:s', $occurred) : null,
        'context' => (string) ($record['context'] ?? ''),
        'status' => (string) ($record['status'] ?? ''),
        'mailer' => (string) ($record['mailer'] ?? ''),
        'error_code' => (string) ($record['error_code'] ?? ''),
        'error_key' => (string) ($record['error_key'] ?? ''),
        'error_message' => nibwp_wpms_scrub((string) ($record['error_message'] ?? '')),
        'initiator_name' => (string) ($record['initiator_name'] ?? ''),
        'initiator_file' => (string) ($record['initiator_file'] ?? ''),
        'debug_event_id' => isset($record['debug_event_id']) && $record['debug_event_id'] !== false
            ? (int) $record['debug_event_id']
            : null,
        'wp_version' => (string) ($record['wp_version'] ?? ''),
        'php_version' => (string) ($record['php_version'] ?? ''),
        'plugin_version' => (string) ($record['plugin_version'] ?? ''),
        'is_multisite' => (bool) ($record['is_multisite'] ?? false),
        'constants_enabled' => (bool) ($record['constants_enabled'] ?? false),
        'conflicts' => $conflicts,
        'debug_blobs_withheld' => __('The stored record also holds raw PHPMailer session output and mailer debug text; both can contain credentials and neither is returned.', 'nibwp'),
    ];
}

/** Names of other active plugins also taking over wp_mail(), if any. */
function nibwp_wpms_conflicts(): array
{
    if (!class_exists('WPMailSMTP\\Conflicts')) {
        return [];
    }

    try {
        $conflicts = new \WPMailSMTP\Conflicts();
        if (!method_exists($conflicts, 'is_detected') || !method_exists($conflicts, 'get_all_conflict_names')) {
            return [];
        }
        // is_detected() is what populates the list, so it has to run first — and
        // only once, because it appends rather than replaces.
        if (!$conflicts->is_detected()) {
            return [];
        }

        return array_values(array_map('strval', (array) $conflicts->get_all_conflict_names()));
    } catch (\Throwable $e) {
        return [];
    }
}

/** Plugin tables the install is missing, which is why a log can look empty. */
function nibwp_wpms_missing_tables(): array
{
    $core = nibwp_wpms_core();

    if ($core === null || !method_exists($core, 'get_site_health')) {
        return [];
    }

    try {
        $health = $core->get_site_health();
        if (!is_object($health) || !method_exists($health, 'get_missing_db_tables')) {
            return [];
        }

        return array_values(array_map('strval', (array) $health->get_missing_db_tables()));
    } catch (\Throwable $e) {
        return [];
    }
}

/** Count of recorded error events inside a window, or null when unavailable. */
function nibwp_wpms_error_event_count(int $days): ?int
{
    if (!class_exists('WPMailSMTP\\Admin\\DebugEvents\\DebugEvents')) {
        return null;
    }

    try {
        $count = \WPMailSMTP\Admin\DebugEvents\DebugEvents::get_error_debug_events_count('-' . $days . ' days');
    } catch (\Throwable $e) {
        return null;
    }

    return is_wp_error($count) ? null : (int) $count;
}

/** The plugin's Reports object, or null. */
function nibwp_wpms_reports(): ?object
{
    $core = nibwp_wpms_core();

    if ($core === null || !method_exists($core, 'get_reports')) {
        return null;
    }

    try {
        $reports = $core->get_reports();
    } catch (\Throwable $e) {
        return null;
    }

    return is_object($reports) ? $reports : null;
}

wp_register_ability('nibwp/wp-mail-smtp-manage', [
    'label' => __('WP Mail SMTP – Mail Delivery', 'nibwp'),
    'description' => 'Diagnose and configure WordPress email delivery through WP Mail SMTP: which mailer is active and whether it is complete, the From name and address, a read-only connection probe, a real test send, SPF/DKIM/DMARC domain checks, the debug-event log, failure counts and deliverability alerts. Credentials are never returned, in any form.',
    'category' => 'utilities',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Can this site send mail, and what is wrong if not
                    'status', 'get_diagnostics',
                    // Mailer selection and configuration
                    'get_mailer', 'list_mailers', 'get_mailer_settings', 'set_mailer', 'set_setting',
                    // Sender identity
                    'get_from', 'set_from',
                    // Testing
                    'test_connection', 'send_test_email', 'check_domain',
                    // Failures and the log this build keeps
                    'last_send_failure', 'clear_last_failure',
                    'list_debug_events', 'get_debug_event', 'count_failures', 'email_stats',
                    // Alerts and the master switch
                    'alerts_status', 'set_do_not_send',
                ],
                'description' => 'The mail-delivery operation to perform.',
            ],

            'group' => [
                'type' => 'string',
                'description' => 'Setting group for get_mailer_settings — a mailer slug, or one of general, debug_events, license, mail. Defaults to the active mailer.',
            ],
            'field' => [
                'type' => 'string',
                'description' => 'Dotted setting path for set_setting, e.g. smtp.host or sendgrid.api_key.',
            ],
            'value' => [
                'description' => 'New value for set_setting. Never echoed back, whatever it is.',
            ],
            'mailer' => [
                'type' => 'string',
                'description' => 'Mailer slug for set_mailer, test_connection and check_domain. Defaults to the active mailer where it is optional.',
            ],

            'from_email' => ['type' => 'string', 'description' => 'From address for set_from.'],
            'from_name' => ['type' => 'string', 'description' => 'From name for set_from.'],
            'force_from_email' => ['type' => 'boolean', 'description' => 'Overwrite the From address on every outgoing message.'],
            'force_from_name' => ['type' => 'boolean', 'description' => 'Overwrite the From name on every outgoing message.'],

            'email' => ['type' => 'string', 'description' => 'Recipient for send_test_email. A real message is delivered to it.'],
            'html' => ['type' => 'boolean', 'default' => true, 'description' => 'Send the test message as HTML rather than plain text.'],

            'event_id' => ['type' => 'integer', 'description' => 'Debug event id for get_debug_event.'],
            'severity' => ['type' => 'string', 'enum' => ['error', 'debug'], 'description' => 'Filter the debug log by severity.'],
            'search' => ['type' => 'string', 'description' => 'Free-text filter for list_debug_events.'],
            'connection_id' => ['type' => 'string', 'default' => 'primary', 'description' => 'Connection whose failure record to read or clear.'],

            'days' => ['type' => 'integer', 'default' => 30, 'description' => 'Window in days for count_failures.'],
            'per_page' => ['type' => 'integer', 'default' => 20, 'description' => 'Rows per page, capped at 100.'],
            'page' => ['type' => 'integer', 'default' => 1],

            'enabled' => ['type' => 'boolean', 'description' => 'Desired state for set_do_not_send.'],
            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required by set_do_not_send when turning sending off, and by clear_last_failure.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_wp_mail_smtp_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Diagnose and configure WordPress email delivery.',
                '',
                'START WITH `status`. It answers in one call whether this site can send',
                'mail, and when it cannot it names the reason: sending switched off,',
                'an incomplete mailer, a recorded failure, another plugin also taking',
                'over wp_mail, a missing database table.',
                '',
                'CREDENTIALS ARE NEVER RETURNED. Not an SMTP password, not an API key,',
                'not a client secret, not an OAuth token, and not a masked version of',
                'any of them. get_mailer_settings answers with field NAMES, an is_set',
                'flag and where the value comes from. Do not ask for a key another way',
                'and do not tell the user a value was withheld because of a bug — it is',
                'the design. set_setting writes one field and never echoes it back, so',
                'a key an owner pastes in is stored and not repeated. When a field',
                'reports source "constant" it is fixed in wp-config.php and cannot be',
                'written from here; say so and name the constant.',
                '',
                'TWO DIFFERENT TESTS:',
                '- test_connection makes no email. It drives the provider or the SMTP',
                '  session and reports coded findings. Use it first.',
                '- send_test_email DELIVERS A REAL MESSAGE to the address given. Confirm',
                '  the address with the user before calling it.',
                '',
                'set_do_not_send true STOPS ALL OUTGOING EMAIL from this site, including',
                'password resets and order receipts. It needs confirm=true.',
                '',
                'The debug log holds the text of real failures, including recipient',
                'addresses. Summarise it; do not paste it wholesale.',
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
function nibwp_wp_mail_smtp_manage(array $input): array|WP_Error
{
    if ($guard = nibwp_wpms_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    try {
        return nibwp_wpms_dispatch($action, $input);
    } catch (\Throwable $e) {
        // The message can carry a provider response, so it goes through the same
        // scrub as everything else quoted from outside this file.
        return new WP_Error('nibwp_wpms_failed', sprintf(
            /* translators: 1: action name, 2: error message. */
            __('WP Mail SMTP could not complete %1$s: %2$s', 'nibwp'),
            $action,
            nibwp_wpms_scrub($e->getMessage())
        ));
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_dispatch(string $action, array $in): array|WP_Error
{
    switch ($action) {
        case 'status':
            return nibwp_wpms_status();

        case 'get_diagnostics':
            return nibwp_wpms_diagnostics();

        case 'get_mailer':
        case 'list_mailers':
        case 'get_mailer_settings':
        case 'set_mailer':
        case 'set_setting':
            return nibwp_wpms_mailers($action, $in);

        case 'get_from':
        case 'set_from':
            return nibwp_wpms_sender($action, $in);

        case 'test_connection':
        case 'send_test_email':
        case 'check_domain':
            return nibwp_wpms_tests($action, $in);

        case 'last_send_failure':
        case 'clear_last_failure':
        case 'list_debug_events':
        case 'get_debug_event':
        case 'count_failures':
        case 'email_stats':
            return nibwp_wpms_log($action, $in);

        case 'alerts_status':
            return nibwp_wpms_alerts();

        case 'set_do_not_send':
            return nibwp_wpms_set_do_not_send($in);
    }

    return new WP_Error('nibwp_wpms_unknown_action', sprintf(
        /* translators: %s: the action that was requested. */
        __('Unknown WP Mail SMTP action: %s', 'nibwp'),
        $action
    ));
}

/**
 * The one call worth making first: can this site send mail, and what is wrong.
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_status(): array
{
    $slug = nibwp_wpms_mailer_slug();
    $complete = nibwp_wpms_mailer_complete();
    $blocked = (bool) nibwp_wpms_get('general', 'do_not_send');
    $conflicts = nibwp_wpms_conflicts();
    $missing_tables = nibwp_wpms_missing_tables();
    $failure = nibwp_wpms_last_failure('primary');
    $errors_30d = nibwp_wpms_error_event_count(30);

    $blockers = [];
    $warnings = [];
    $next = [];

    if ($blocked) {
        $blockers[] = __('Sending is switched off: WP Mail SMTP is set not to send email at all, so nothing leaves this site — including password resets and order receipts.', 'nibwp');
        $next[] = __('Turn sending back on with set_do_not_send { enabled: false }.', 'nibwp');
    }

    if ($slug === '' || $slug === 'mail') {
        $warnings[] = __('No mailer is configured. Mail is handed to the web server, which most hosts either rate-limit or drop outright, and there is no way to tell from here which happened.', 'nibwp');
        $next[] = __('Pick a mailer with set_mailer, then add its credentials with set_setting.', 'nibwp');
    } elseif ($complete === false) {
        $blockers[] = __('The selected mailer is missing required settings, so no message can be sent through it.', 'nibwp');
        $next[] = __('Call get_mailer_settings to see which fields are unset, then set_setting to fill them.', 'nibwp');
    } elseif ($complete === null) {
        $warnings[] = __('Could not confirm whether the selected mailer is fully configured on this install.', 'nibwp');
    }

    if ($failure !== null && ($failure['status'] ?? '') === 'failed') {
        $blockers[] = __('The last send through this connection failed and the failure has not been cleared.', 'nibwp');
        $next[] = __('Read the reason with last_send_failure, then re-probe with test_connection.', 'nibwp');
    }

    if ($conflicts !== []) {
        $warnings[] = sprintf(
            /* translators: %s: comma-separated plugin names. */
            __('Another active plugin is also taking over WordPress mail: %s. Two of them fighting over the same hook is a common cause of mail that vanishes without an error.', 'nibwp'),
            implode(', ', $conflicts)
        );
    }

    if ($missing_tables !== []) {
        $warnings[] = __('A WP Mail SMTP database table is missing, so its own records of what happened are incomplete.', 'nibwp');
    }

    if (is_int($errors_30d) && $errors_30d > 0) {
        $warnings[] = sprintf(
            /* translators: %d: number of recorded errors. */
            _n(
                '%d send error has been recorded in the last 30 days.',
                '%d send errors have been recorded in the last 30 days.',
                $errors_30d,
                'nibwp'
            ),
            $errors_30d
        );
    }

    $can_send = !$blocked && $complete !== false;

    return [
        'can_send' => $can_send,
        'verdict' => $can_send
            ? ($warnings === []
                ? __('This site can send mail and nothing looks wrong.', 'nibwp')
                : __('This site can send mail, but something about the setup is worth looking at.', 'nibwp'))
            : __('This site cannot reliably send mail right now.', 'nibwp'),
        'blockers' => $blockers,
        'warnings' => $warnings,
        'next_steps' => $next,
        'mailer' => [
            'slug' => $slug,
            'title' => nibwp_wpms_mailer_title($slug),
            'is_php_mail' => $slug === '' || $slug === 'mail',
            'is_complete' => $complete,
        ],
        'from' => [
            'email' => (string) nibwp_wpms_get('mail', 'from_email'),
            'name' => (string) nibwp_wpms_get('mail', 'from_name'),
            'force_email' => (bool) nibwp_wpms_get('mail', 'from_email_force'),
            'force_name' => (bool) nibwp_wpms_get('mail', 'from_name_force'),
        ],
        'sending_disabled' => $blocked,
        'constants_enabled' => nibwp_wpms_constants_enabled(),
        'conflicts' => $conflicts,
        'missing_db_tables' => $missing_tables,
        'error_events_last_30_days' => $errors_30d,
        'domain_check_state' => nibwp_wpms_domain_check_state(),
        // Deliberately the summary and not the record. A provider's error text can
        // name the person a message was addressed to, and that belongs behind the
        // log gate — last_send_failure — rather than in the one action everybody
        // calls first.
        'last_send_failure' => $failure === null ? null : [
            'occurred_at_gmt' => $failure['occurred_at_gmt'],
            'mailer' => $failure['mailer'],
            'error_code' => $failure['error_code'],
            'status' => $failure['status'],
            'read_with' => 'last_send_failure',
        ],
    ];
}

/** The stored SPF/DKIM/DMARC verdict, without making a network call. */
function nibwp_wpms_domain_check_state(): string
{
    if (!class_exists('WPMailSMTP\\DomainCheckState')) {
        return 'unknown';
    }

    try {
        return (string) \WPMailSMTP\DomainCheckState::get_state('primary');
    } catch (\Throwable $e) {
        return 'unknown';
    }
}

/**
 * Install facts an agent needs to reason about the rest, minus the licence key.
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_diagnostics(): array
{
    $core = nibwp_wpms_core();
    $pro = false;
    $license_type = '';

    if ($core !== null) {
        try {
            $pro = method_exists($core, 'is_pro') ? (bool) $core->is_pro() : false;
            $license_type = method_exists($core, 'get_license_type') ? (string) $core->get_license_type() : '';
        } catch (\Throwable $e) {
            $pro = false;
        }
    }

    $queue_enabled = false;
    $queued = null;

    if ($core !== null && method_exists($core, 'get_queue')) {
        try {
            $queue = $core->get_queue();
            if (is_object($queue) && method_exists($queue, 'is_enabled')) {
                $queue_enabled = (bool) $queue->is_enabled();
            }
            if ($queue_enabled && is_object($queue) && method_exists($queue, 'count_queued_emails')) {
                $queued = (int) $queue->count_queued_emails();
            }
        } catch (\Throwable $e) {
            $queue_enabled = false;
        }
    }

    return [
        'plugin_version' => defined('WPMS_PLUGIN_VER') ? WPMS_PLUGIN_VER : null,
        'edition' => $pro ? 'pro' : 'lite',
        'license_type' => $license_type,
        'license_key_returned' => false,
        'has_email_log' => $pro,
        'email_log_note' => $pro
            ? __('This build keeps a full email log of its own. list_debug_events and get_debug_event read the debug-event log, which is where send failures are recorded; the full log is on the plugin\'s own Email Log screen.', 'nibwp')
            : __('This build has no email log — that is a paid feature of the plugin. list_debug_events and get_debug_event read the debug-event log instead, which is where send failures are recorded. Nothing in this build can re-send a recorded message.', 'nibwp'),
        'constants_enabled' => nibwp_wpms_constants_enabled(),
        'debug_logging_enabled' => (bool) nibwp_wpms_get('debug_events', 'email_debug'),
        'debug_retention_days' => (int) nibwp_wpms_get('debug_events', 'retention_period'),
        'summary_report_disabled' => (bool) nibwp_wpms_get('general', 'summary_report_email_disabled'),
        'optimized_sending_enabled' => (bool) nibwp_wpms_get('general', 'optimize_email_sending_enabled'),
        'queue' => ['enabled' => $queue_enabled, 'queued' => $queued],
        'missing_db_tables' => nibwp_wpms_missing_tables(),
        'conflicts' => nibwp_wpms_conflicts(),
        'is_multisite' => is_multisite(),
    ];
}

/**
 * Mailer selection and per-mailer settings.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_mailers(string $action, array $in): array|WP_Error
{
    $slug = nibwp_wpms_mailer_slug();

    if ($action === 'get_mailer') {
        // "mail" is both the PHP-mail mailer slug and the name of the generic
        // From/mailer group, so a mailer of "mail" has no settings group of its
        // own — reporting the generic one here would read as if it did.
        $group = ($slug !== '' && $slug !== 'mail') ? $slug : '';
        $has_group = $group !== '' && array_key_exists($group, nibwp_wpms_known_fields());

        return [
            'slug' => $slug,
            'title' => nibwp_wpms_mailer_title($slug),
            'is_php_mail' => $slug === '' || $slug === 'mail',
            'is_complete' => nibwp_wpms_mailer_complete(),
            'mailer_set_by_constant' => nibwp_wpms_is_const('mail', 'mailer'),
            'settings' => $has_group ? nibwp_wpms_group_report($group) : null,
            'note' => $has_group
                ? __('Read the From name and address with get_from.', 'nibwp')
                : __('Mail is handed to the web server, so there is nothing to configure and nothing to test beyond an actual send.', 'nibwp'),
        ];
    }

    if ($action === 'list_mailers') {
        return nibwp_wpms_list_mailers($slug);
    }

    if ($action === 'get_mailer_settings') {
        $group = (string) ($in['group'] ?? ($slug !== '' ? $slug : 'mail'));

        if (!array_key_exists($group, nibwp_wpms_known_fields())) {
            return new WP_Error('nibwp_wpms_unknown_group', sprintf(
                /* translators: %s: the group name that was requested. */
                __('WP Mail SMTP has no setting group called "%s".', 'nibwp'),
                $group
            ));
        }

        return nibwp_wpms_group_report($group);
    }

    if ($action === 'set_mailer') {
        if ($error = nibwp_wpms_require_manage(__('Changing the mailer', 'nibwp'))) {
            return $error;
        }

        $wanted = (string) ($in['mailer'] ?? '');
        if ($wanted === '' || !array_key_exists($wanted, nibwp_wpms_known_fields())) {
            return new WP_Error('nibwp_wpms_unknown_mailer', sprintf(
                /* translators: %s: the mailer slug that was requested. */
                __('"%s" is not a mailer WP Mail SMTP knows about.', 'nibwp'),
                $wanted
            ));
        }

        $written = nibwp_wpms_write('mail.mailer', $wanted);
        if (is_wp_error($written)) {
            return $written;
        }

        return [
            'updated' => true,
            'mailer' => nibwp_wpms_mailer_slug(),
            'settings' => nibwp_wpms_group_report($wanted),
            'note' => __('Switching the service does not carry credentials over. Check which fields are unset before sending anything.', 'nibwp'),
        ];
    }

    // set_setting
    if ($error = nibwp_wpms_require_manage(__('Changing a WP Mail SMTP setting', 'nibwp'))) {
        return $error;
    }

    $field = (string) ($in['field'] ?? '');
    if (!array_key_exists('value', $in)) {
        return new WP_Error('nibwp_wpms_no_value', __('set_setting needs a value. Send an empty string to clear a field.', 'nibwp'));
    }

    $written = nibwp_wpms_write($field, $in['value']);
    if (is_wp_error($written)) {
        return $written;
    }

    [$group] = explode('.', $field, 2);

    return [
        'updated' => true,
        'field' => $field,
        'value_returned' => false,
        'settings' => nibwp_wpms_group_report($group),
    ];
}

/**
 * Which mailers this install offers, and which of them can be probed without
 * sending anything.
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_list_mailers(string $active): array
{
    $core = nibwp_wpms_core();
    $slugs = [];

    if ($core !== null && method_exists($core, 'get_providers')) {
        try {
            $providers = $core->get_providers();
            if (is_object($providers) && method_exists($providers, 'get_providers')) {
                $slugs = array_keys((array) $providers->get_providers());
            }
        } catch (\Throwable $e) {
            $slugs = [];
        }
    }

    if ($slugs === []) {
        // Nothing to ask, so fall back to the groups this file knows carry a
        // mailer's own settings rather than answering an empty list.
        $slugs = array_values(array_diff(
            array_keys(nibwp_wpms_known_fields()),
            ['mail', 'general', 'debug_events', 'license', 'alert_events']
        ));
        $slugs = array_values(array_filter($slugs, static fn($s) => strpos((string) $s, 'alert_') !== 0));
    }

    $mailers = [];

    foreach ($slugs as $slug) {
        $slug = (string) $slug;
        $public = (array) (nibwp_wpms_public_fields()[$slug] ?? []);
        $known = (array) (nibwp_wpms_known_fields()[$slug] ?? []);

        $mailers[] = [
            'slug' => $slug,
            'title' => nibwp_wpms_mailer_title($slug),
            'active' => $slug === $active,
            'has_connection_probe' => nibwp_wpms_has_preflight($slug),
            'credential_fields' => array_values(array_diff($known, $public)),
            'configurable_fields' => $public,
        ];
    }

    return ['active' => $active, 'mailers' => $mailers];
}

/** Can this mailer be probed read-only, without sending mail? */
function nibwp_wpms_has_preflight(string $slug): bool
{
    $core = nibwp_wpms_core();

    if ($core === null || !method_exists($core, 'get_providers') || $slug === '') {
        return false;
    }

    try {
        $providers = $core->get_providers();
        if (!is_object($providers) || !method_exists($providers, 'has_preflight')) {
            return false;
        }

        return (bool) $providers->has_preflight($slug);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Write one setting through the plugin's own path.
 *
 * Follows Writer::write() (src/WPCLI/Options/Writer.php:271-330): read the raw
 * row, set the one path, hand the whole thing back to Options::set(). Writing a
 * bare group would drop every other key, because set() replaces the row.
 *
 * A constant-shadowed key is refused rather than written: Options::set() forces
 * the stored value to '' whenever the constant is defined, so the write would
 * silently wipe what was there.
 *
 * @return true|WP_Error
 */
function nibwp_wpms_write(string $field, $value)
{
    $parts = explode('.', $field);

    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return new WP_Error('nibwp_wpms_bad_field', __('A setting is named group.key, for example smtp.host or sendgrid.api_key.', 'nibwp'));
    }

    [$group, $key] = $parts;
    $known = nibwp_wpms_known_fields();

    if (!array_key_exists($group, $known)) {
        return new WP_Error('nibwp_wpms_unknown_group', sprintf(
            /* translators: %s: the group name that was requested. */
            __('WP Mail SMTP has no setting group called "%s".', 'nibwp'),
            $group
        ));
    }
    if (!in_array($key, $known[$group], true)) {
        return new WP_Error('nibwp_wpms_unknown_field', sprintf(
            /* translators: 1: setting key, 2: group name. */
            __('"%1$s" is not a setting WP Mail SMTP stores under "%2$s".', 'nibwp'),
            $key,
            $group
        ));
    }
    // A licence key, anything the OAuth handshake owns, and an alert channel's
    // connection list are all written by flows with their own state. Setting one
    // by hand leaves the plugin believing something that is not true.
    $plugin_owned = ['access_token', 'refresh_token', 'auth_code', 'scopes', 'is_setup_wizard_auth', 'user_details', 'connections'];

    if ($group === 'license' || in_array($key, $plugin_owned, true)) {
        return new WP_Error('nibwp_wpms_not_writable', __('That field is managed by the plugin itself — a licence key, an OAuth token or an alert connection — and is not writable from here.', 'nibwp'));
    }
    if (nibwp_wpms_is_const($group, $key)) {
        $constant = (string) (nibwp_wpms_constant_map()[$group][$key] ?? '');

        return new WP_Error('nibwp_wpms_const_shadowed', sprintf(
            /* translators: 1: setting path, 2: wp-config.php constant name. */
            __('%1$s is fixed by the %2$s constant in wp-config.php. Storing a value here would be discarded, so nothing was written. Remove the constant first.', 'nibwp'),
            $field,
            $constant !== '' ? $constant : strtoupper('WPMS_' . $group . '_' . $key)
        ));
    }

    $options = nibwp_wpms_options();
    if ($options === null || !method_exists($options, 'get_all_raw') || !method_exists($options, 'set')) {
        return new WP_Error('nibwp_wpms_no_options', __('WP Mail SMTP options are not available on this install.', 'nibwp'));
    }

    $raw = $options->get_all_raw();
    if (!is_array($raw)) {
        $raw = [];
    }
    if (!isset($raw[$group]) || !is_array($raw[$group])) {
        $raw[$group] = [];
    }

    $raw[$group][$key] = $value;
    $options->set($raw);

    return true;
}

/**
 * The From identity, and the two "force" switches that decide whether it wins.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_sender(string $action, array $in): array|WP_Error
{
    if ($action === 'get_from') {
        $report = nibwp_wpms_group_report('mail');

        return [
            'from_email' => (string) nibwp_wpms_get('mail', 'from_email'),
            'from_name' => (string) nibwp_wpms_get('mail', 'from_name'),
            'force_from_email' => (bool) nibwp_wpms_get('mail', 'from_email_force'),
            'force_from_name' => (bool) nibwp_wpms_get('mail', 'from_name_force'),
            'return_path' => (bool) nibwp_wpms_get('mail', 'return_path'),
            'force_note' => __('With forcing off, any plugin that sets its own From address keeps it. With forcing on, this address replaces theirs on every message — which is usually what fixes mail a provider rejects for an unverified sender.', 'nibwp'),
            'fields' => $report['fields'],
            'constants_enabled' => $report['constants_enabled'],
        ];
    }

    // set_from
    if ($error = nibwp_wpms_require_manage(__('Changing the From name or address', 'nibwp'))) {
        return $error;
    }

    $writes = [];

    if (isset($in['from_email'])) {
        $email = (string) $in['from_email'];
        if (!is_email($email)) {
            return new WP_Error('nibwp_wpms_bad_email', sprintf(
                /* translators: %s: the address that was rejected. */
                __('"%s" is not a valid email address.', 'nibwp'),
                $email
            ));
        }
        $writes['mail.from_email'] = $email;
    }
    if (isset($in['from_name'])) {
        $writes['mail.from_name'] = (string) $in['from_name'];
    }
    if (isset($in['force_from_email'])) {
        $writes['mail.from_email_force'] = (bool) $in['force_from_email'];
    }
    if (isset($in['force_from_name'])) {
        $writes['mail.from_name_force'] = (bool) $in['force_from_name'];
    }

    if ($writes === []) {
        return new WP_Error('nibwp_wpms_nothing', __('Nothing to change — send from_email, from_name, force_from_email or force_from_name.', 'nibwp'));
    }

    $written = [];
    foreach ($writes as $field => $value) {
        $result = nibwp_wpms_write($field, $value);
        if (is_wp_error($result)) {
            return $result;
        }
        $written[] = $field;
    }

    return [
        'updated' => true,
        'written' => $written,
        'from_email' => (string) nibwp_wpms_get('mail', 'from_email'),
        'from_name' => (string) nibwp_wpms_get('mail', 'from_name'),
        'force_from_email' => (bool) nibwp_wpms_get('mail', 'from_email_force'),
        'force_from_name' => (bool) nibwp_wpms_get('mail', 'from_name_force'),
    ];
}

/**
 * The three ways to find out whether delivery works.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_tests(string $action, array $in): array|WP_Error
{
    if ($action === 'test_connection') {
        return nibwp_wpms_test_connection($in);
    }

    if ($action === 'check_domain') {
        return nibwp_wpms_check_domain($in);
    }

    return nibwp_wpms_send_test_email($in);
}

/**
 * Probe the saved configuration without composing a message.
 *
 * Mirrors get_failed_send_preflight() (src/Admin/SetupWizard/RestApi.php:695-707):
 * hand the mailer's own settings group to its probe and report the findings.
 * Findings carry a code, a field NAME and a severity — never a value
 * (src/Providers/Preflight/Finding.php:189-196).
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_test_connection(array $in): array|WP_Error
{
    $slug = (string) ($in['mailer'] ?? nibwp_wpms_mailer_slug());

    if ($slug === '' || !array_key_exists($slug, nibwp_wpms_known_fields())) {
        return new WP_Error('nibwp_wpms_unknown_mailer', sprintf(
            /* translators: %s: the mailer slug that was requested. */
            __('"%s" is not a mailer WP Mail SMTP knows about.', 'nibwp'),
            $slug
        ));
    }

    $core = nibwp_wpms_core();
    if ($core === null || !method_exists($core, 'get_providers')) {
        return new WP_Error('nibwp_wpms_no_providers', __('WP Mail SMTP mailers are not available on this install.', 'nibwp'));
    }

    $providers = $core->get_providers();
    if (!is_object($providers) || !method_exists($providers, 'get_preflight')) {
        return [
            'mailer' => $slug,
            'checked' => false,
            'findings' => [],
            'note' => __('This version of WP Mail SMTP has no read-only connection probe. Use send_test_email instead, which delivers a real message.', 'nibwp'),
        ];
    }

    $preflight = $providers->get_preflight($slug);
    if (!is_object($preflight) || !method_exists($preflight, 'run')) {
        return [
            'mailer' => $slug,
            'checked' => false,
            'findings' => [],
            'note' => __('This mailer has nothing to probe without sending. Use send_test_email to confirm delivery.', 'nibwp'),
        ];
    }

    // The probe opens an outbound connection, so it gets the plugin's own rate
    // limit rather than a fresh one an agent could loop on.
    if (class_exists('WPMailSMTP\\Providers\\Preflight\\Throttle')) {
        $throttle = new \WPMailSMTP\Providers\Preflight\Throttle();
        $key = 'nibwp-' . get_current_user_id();

        if (method_exists($throttle, 'allows') && !$throttle->allows($key)) {
            return new WP_Error('nibwp_wpms_throttled', __('Too many connection probes in a short window. Wait a minute and try again.', 'nibwp'));
        }
        if (method_exists($throttle, 'record')) {
            $throttle->record($key);
        }
    }

    try {
        $findings = $preflight->run(nibwp_wpms_get_group($slug));
        $wire = is_object($findings) && method_exists($findings, 'to_array') ? (array) $findings->to_array() : [];
        $blocking = is_object($findings) && method_exists($findings, 'has_blocking') ? (bool) $findings->has_blocking() : false;
        $inconclusive = is_object($findings) && method_exists($findings, 'is_inconclusive') ? (bool) $findings->is_inconclusive() : false;
    } catch (\Throwable $e) {
        return [
            'mailer' => $slug,
            'checked' => true,
            'findings' => [['code' => 'unexpected_response', 'field' => null, 'severity' => 'inconclusive']],
            'passed' => false,
            'note' => nibwp_wpms_scrub($e->getMessage()),
        ];
    }

    return [
        'mailer' => $slug,
        'checked' => true,
        'passed' => $wire === [],
        'blocking' => $blocking,
        'inconclusive' => $inconclusive,
        'findings' => $wire,
        'note' => $wire === []
            ? __('The probe completed with nothing to report: the service accepted the configuration. No email was sent.', 'nibwp')
            : __('Each finding names a code, the setting at fault and how serious it is. No email was sent.', 'nibwp'),
    ];
}

/**
 * Send a real email. This is an outward-facing side effect: a message leaves the
 * site and lands in somebody's inbox.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_send_test_email(array $in): array|WP_Error
{
    if ($error = nibwp_wpms_require_manage(__('Sending a test email', 'nibwp'))) {
        return $error;
    }

    $recipient = (string) ($in['email'] ?? '');
    if ($recipient === '') {
        return new WP_Error('nibwp_wpms_no_recipient', __('send_test_email needs an address to send to. It delivers a real message, so confirm the address first.', 'nibwp'));
    }
    if (!is_email($recipient)) {
        return new WP_Error('nibwp_wpms_bad_email', sprintf(
            /* translators: %s: the address that was rejected. */
            __('"%s" is not a valid email address.', 'nibwp'),
            $recipient
        ));
    }
    if ((bool) nibwp_wpms_get('general', 'do_not_send')) {
        return new WP_Error('nibwp_wpms_sending_disabled', __('Sending is switched off in WP Mail SMTP, so a test email would go nowhere. Turn it back on with set_do_not_send first.', 'nibwp'));
    }
    if (!class_exists('WPMailSMTP\\TestEmail\\TestEmail')) {
        return new WP_Error('nibwp_wpms_no_test_email', __('This version of WP Mail SMTP has no test-email sender.', 'nibwp'));
    }

    // wp_mail() answers only true or false. The reason lives in wp_mail_failed,
    // so it is captured for the length of this one send, exactly as the plugin's
    // own CLI command does (src/WPCLI/Commands/Test.php:52-62).
    $captured = null;
    $capture = static function ($error) use (&$captured): void {
        $captured = $error;
    };
    add_action('wp_mail_failed', $capture);

    try {
        $test = new \WPMailSMTP\TestEmail\TestEmail();
        if (method_exists($test, 'as_html')) {
            $test->as_html((bool) ($in['html'] ?? true));
        }
        $test->send($recipient);
        $sent = method_exists($test, 'is_successful') ? (bool) $test->is_successful() : false;
    } catch (\Throwable $e) {
        remove_action('wp_mail_failed', $capture);

        return new WP_Error('nibwp_wpms_test_failed', sprintf(
            /* translators: %s: error message from the mailer. */
            __('The test email could not be sent: %s', 'nibwp'),
            nibwp_wpms_scrub($e->getMessage())
        ));
    }

    remove_action('wp_mail_failed', $capture);

    $reason = '';
    if (!$sent) {
        $reason = is_wp_error($captured)
            ? nibwp_wpms_scrub((string) $captured->get_error_message())
            : __('wp_mail() returned false and no further detail was reported.', 'nibwp');
    }

    return [
        'sent' => $sent,
        'recipient' => $recipient,
        'mailer' => nibwp_wpms_mailer_slug(),
        'reason' => $reason,
        'last_send_failure' => $sent ? null : nibwp_wpms_last_failure('primary'),
        'note' => $sent
            ? __('A real message was delivered. If it does not arrive, delivery left this site successfully and the problem is downstream — check the recipient spam folder and run check_domain.', 'nibwp')
            : __('Nothing was delivered. Run test_connection for a coded reason.', 'nibwp'),
    ];
}

/**
 * Ask the plugin's domain service whether this sender is authenticated — SPF,
 * DKIM, DMARC. Makes an outbound API call; sends no mail.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_check_domain(array $in): array|WP_Error
{
    if (!class_exists('WPMailSMTP\\Admin\\DomainChecker')) {
        return new WP_Error('nibwp_wpms_no_domain_check', __('This version of WP Mail SMTP has no domain checker.', 'nibwp'));
    }

    $slug = (string) ($in['mailer'] ?? nibwp_wpms_mailer_slug());
    $from = (string) nibwp_wpms_get('mail', 'from_email');

    if ($from === '') {
        return new WP_Error('nibwp_wpms_no_from', __('There is no From address configured, so there is no domain to check.', 'nibwp'));
    }

    // Three mailers carry their own sending domain, and the check wants it when
    // it is set (src/TestEmail/TestEmail.php:318-323).
    $domain = in_array($slug, ['mailgun', 'sendinblue', 'sendgrid'], true)
        ? (string) nibwp_wpms_get($slug, 'domain')
        : '';

    try {
        $connection = nibwp_wpms_primary_connection();
        $checker = new \WPMailSMTP\Admin\DomainChecker($slug, $from, $domain, $connection);
        $results = method_exists($checker, 'get_results') ? (array) $checker->get_results() : [];
        $no_issues = method_exists($checker, 'no_issues') ? (bool) $checker->no_issues() : false;
    } catch (\Throwable $e) {
        return new WP_Error('nibwp_wpms_domain_check_failed', sprintf(
            /* translators: %s: error message from the domain check. */
            __('The domain check could not run: %s', 'nibwp'),
            nibwp_wpms_scrub($e->getMessage())
        ));
    }

    $checks = [];
    foreach ((array) ($results['checks'] ?? []) as $check) {
        if (!is_array($check)) {
            continue;
        }
        $checks[] = [
            'type' => (string) ($check['type'] ?? ''),
            'state' => (string) ($check['state'] ?? ''),
            'message' => nibwp_wpms_scrub(wp_strip_all_tags((string) ($check['message'] ?? ''))),
        ];
    }

    return [
        'mailer' => $slug,
        'from_email' => $from,
        'sending_domain' => $domain,
        'ran' => (bool) ($results['success'] ?? false),
        'no_issues' => $no_issues,
        'message' => nibwp_wpms_scrub(wp_strip_all_tags((string) ($results['message'] ?? ''))),
        'checks' => $checks,
        'stored_state' => nibwp_wpms_domain_check_state(),
    ];
}

/**
 * Failures and the log this build keeps.
 *
 * The debug-event log holds the text of real send failures, which can include
 * the recipient address, so every read here is gated on the same capability as
 * writing settings.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_log(string $action, array $in): array|WP_Error
{
    if ($error = nibwp_wpms_require_manage(__('Reading the WP Mail SMTP log', 'nibwp'))) {
        return $error;
    }

    $connection_id = (string) ($in['connection_id'] ?? 'primary');
    if ($connection_id === '') {
        $connection_id = 'primary';
    }

    if ($action === 'last_send_failure') {
        $failure = nibwp_wpms_last_failure($connection_id);

        return [
            'connection_id' => $connection_id,
            'has_failure' => $failure !== null,
            'failure' => $failure,
            'note' => $failure === null
                ? __('No send failure is on record for this connection.', 'nibwp')
                : __('This record stays until it is cleared or a send succeeds. Fix the cause, re-probe with test_connection, then clear_last_failure.', 'nibwp'),
        ];
    }

    if ($action === 'clear_last_failure') {
        if (!(bool) ($in['confirm'] ?? false)) {
            return new WP_Error('nibwp_wpms_unconfirmed', __('Clearing the failure record throws away the only copy of why the last send failed. Re-issue with confirm set to true if that is intended.', 'nibwp'));
        }
        if (!class_exists('WPMailSMTP\\EmailSendingDebug')) {
            return new WP_Error('nibwp_wpms_no_failure_store', __('This version of WP Mail SMTP has no per-connection failure record.', 'nibwp'));
        }

        \WPMailSMTP\EmailSendingDebug::clear($connection_id);

        return [
            'cleared' => true,
            'connection_id' => $connection_id,
            'note' => __('The admin warning about failed email will stop showing. It comes back on the next failure.', 'nibwp'),
        ];
    }

    if ($action === 'count_failures') {
        $days = max(1, min(365, (int) ($in['days'] ?? 30)));

        return [
            'window_days' => $days,
            'error_events' => nibwp_wpms_error_event_count($days),
            'last_failure' => nibwp_wpms_last_failure($connection_id),
            'note' => __('Counts recorded error events, which is what this build keeps. It is not a count of undelivered messages — mail a provider accepted and then bounced leaves no error here.', 'nibwp'),
        ];
    }

    if ($action === 'email_stats') {
        return nibwp_wpms_email_stats();
    }

    if ($action === 'get_debug_event') {
        return nibwp_wpms_get_debug_event((int) ($in['event_id'] ?? 0));
    }

    return nibwp_wpms_list_debug_events($in);
}

/**
 * Counters this build keeps: lifetime sends, and sends and failures by ISO week.
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_email_stats(): array
{
    $reports = nibwp_wpms_reports();

    if ($reports === null) {
        return [
            'available' => false,
            'note' => __('This install exposes no email counters.', 'nibwp'),
        ];
    }

    $total = null;
    $weekly_sent = [];
    $weekly_failed = [];
    $week = null;

    try {
        if (method_exists($reports, 'get_total_emails_sent')) {
            $total = (int) $reports->get_total_emails_sent();
        }
        if (method_exists($reports, 'get_total_weekly_emails_sent')) {
            $weekly_sent = (array) $reports->get_total_weekly_emails_sent();
        }
        if (method_exists($reports, 'get_total_weekly_emails_failed')) {
            $weekly_failed = (array) $reports->get_total_weekly_emails_failed();
        }
        if (method_exists($reports, 'get_current_week')) {
            $week = (int) $reports->get_current_week();
        }
    } catch (\Throwable $e) {
        return [
            'available' => false,
            'note' => nibwp_wpms_scrub($e->getMessage()),
        ];
    }

    return [
        'available' => true,
        'total_sent' => $total,
        'current_week' => $week,
        'weekly_sent' => $weekly_sent,
        'weekly_failed' => $weekly_failed,
        'note' => __('Weekly counters are keyed by ISO week number and only the last twelve are kept.', 'nibwp'),
    ];
}

/**
 * A bounded page of the debug-event log. Bodies are trimmed here; the full text
 * of one entry comes from get_debug_event.
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_list_debug_events(array $in): array
{
    if (!class_exists('WPMailSMTP\\Admin\\DebugEvents\\EventsCollection') || !class_exists('WPMailSMTP\\Admin\\DebugEvents\\Event')) {
        return [
            'available' => false,
            'events' => [],
            'note' => __('This install has no debug-event log.', 'nibwp'),
        ];
    }

    $per_page = nibwp_wpms_bound($in['per_page'] ?? 20, 20);
    $page = max(1, (int) ($in['page'] ?? 1));

    $params = ['per_page' => $per_page, 'offset' => ($page - 1) * $per_page];

    $severity = (string) ($in['severity'] ?? '');
    if ($severity === 'error') {
        $params['type'] = \WPMailSMTP\Admin\DebugEvents\Event::TYPE_ERROR;
    } elseif ($severity === 'debug') {
        $params['type'] = \WPMailSMTP\Admin\DebugEvents\Event::TYPE_DEBUG;
    }

    $search = trim((string) ($in['search'] ?? ''));
    if ($search !== '') {
        $params['search'] = $search;
    }

    $collection = new \WPMailSMTP\Admin\DebugEvents\EventsCollection($params);
    $total = method_exists($collection, 'get_count') ? (int) $collection->get_count() : null;

    $events = [];
    foreach ($collection->get() as $event) {
        $events[] = nibwp_wpms_shape_event($event, 400);
    }

    return [
        'available' => true,
        'events' => $events,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'max_per_page' => nibwp_wpms_max_per_page(),
        'note' => __('Bodies are trimmed. Read one in full with get_debug_event.', 'nibwp'),
    ];
}

/**
 * One debug event, in full.
 *
 * This is the single action whose job is reading a recorded entry whole, which
 * is why it is the only one that returns an untrimmed body — and why that body
 * goes through the credential scrub first.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_get_debug_event(int $event_id): array|WP_Error
{
    if ($event_id <= 0) {
        return new WP_Error('nibwp_wpms_no_event_id', __('get_debug_event needs an event_id.', 'nibwp'));
    }
    if (!class_exists('WPMailSMTP\\Admin\\DebugEvents\\Event')) {
        return new WP_Error('nibwp_wpms_no_log', __('This install has no debug-event log.', 'nibwp'));
    }

    $event = new \WPMailSMTP\Admin\DebugEvents\Event($event_id);

    if (!method_exists($event, 'is_valid') || !$event->is_valid()) {
        return new WP_Error('nibwp_wpms_no_event', __('No debug event with that id.', 'nibwp'));
    }

    return [
        'event' => nibwp_wpms_shape_event($event, 0),
        'note' => __('Recorded failure text can name the people a message was addressed to. Summarise it for the user rather than repeating it.', 'nibwp'),
    ];
}

/**
 * Trim one log row to something an agent can read.
 *
 * @param object $event   a WPMailSMTP Event
 * @param int    $trim    characters to keep of the body, or 0 for all of it
 * @return array<string, mixed>
 */
function nibwp_wpms_shape_event(object $event, int $trim): array
{
    $content = method_exists($event, 'get_content') ? nibwp_wpms_scrub((string) $event->get_content()) : '';
    $truncated = false;

    if ($trim > 0 && strlen($content) > $trim) {
        $content = substr($content, 0, $trim);
        $truncated = true;
    }

    $is_error = method_exists($event, 'is_error') ? (bool) $event->is_error() : false;

    return [
        'id' => method_exists($event, 'get_id') ? (int) $event->get_id() : 0,
        'severity' => $is_error ? 'error' : 'debug',
        'created_at' => method_exists($event, 'get_created_at') ? (string) $event->get_created_at() : '',
        'initiator' => method_exists($event, 'get_initiator') ? nibwp_wpms_scrub((string) $event->get_initiator()) : '',
        'content' => $content,
        'truncated' => $truncated,
    ];
}

/**
 * Deliverability alerts: which channels are switched on, and whether each has
 * its credentials filled in. The credentials themselves never appear — every
 * channel's `connections` entry holds a webhook URL or an access token
 * (src/Options.php:846-1000).
 *
 * @return array<string, mixed>
 */
function nibwp_wpms_alerts(): array
{
    $channels = [];
    $any_enabled = false;

    foreach (array_keys(nibwp_wpms_known_fields()) as $group) {
        if (strpos($group, 'alert_') !== 0 || $group === 'alert_events') {
            continue;
        }

        $enabled = (bool) nibwp_wpms_get($group, 'enabled');
        $any_enabled = $any_enabled || $enabled;

        $connections = nibwp_wpms_get($group, 'connections');
        $connections = is_array($connections) ? $connections : [];

        $shaped = [];
        foreach ($connections as $connection) {
            $shaped[] = [
                'fields' => is_array($connection) ? nibwp_wpms_names_and_flags($connection) : [],
            ];
        }

        $channels[] = [
            'channel' => $group,
            'enabled' => $enabled,
            'connection_count' => count($connections),
            'set_by_constant' => nibwp_wpms_is_const($group, 'connections'),
            'constant' => nibwp_wpms_constant_map()[$group]['connections'] ?? null,
            'connections' => $shaped,
        ];
    }

    $core = nibwp_wpms_core();
    $pro = false;
    if ($core !== null && method_exists($core, 'is_pro')) {
        try {
            $pro = (bool) $core->is_pro();
        } catch (\Throwable $e) {
            $pro = false;
        }
    }

    return [
        'any_enabled' => $any_enabled,
        'can_fire' => $pro,
        'channels' => $channels,
        'events' => ['email_hard_bounced' => (bool) nibwp_wpms_get('alert_events', 'email_hard_bounced')],
        'note' => $pro
            ? __('Alerts fire on this build when a send fails.', 'nibwp')
            : __('These settings are readable on this build, but nothing acts on them — alerts are a paid feature of the plugin. A channel showing enabled here will still not notify anyone.', 'nibwp'),
        'credentials_note' => __('Webhook URLs, auth tokens and access keys are reported as names and is_set flags only.', 'nibwp'),
    ];
}

/**
 * The master switch. On means no email leaves this site at all — not password
 * resets, not order receipts, not the test email above.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wpms_set_do_not_send(array $in): array|WP_Error
{
    if ($error = nibwp_wpms_require_manage(__('Switching outgoing email on or off', 'nibwp'))) {
        return $error;
    }

    if (!array_key_exists('enabled', $in)) {
        return new WP_Error('nibwp_wpms_nothing', __('Send enabled true to stop all outgoing email, or false to allow it again.', 'nibwp'));
    }

    $stop = (bool) $in['enabled'];

    if ($stop && !(bool) ($in['confirm'] ?? false)) {
        return new WP_Error('nibwp_wpms_unconfirmed', __('This stops every email this site sends, including password resets and purchase receipts. Re-issue with confirm set to true if that is intended.', 'nibwp'));
    }

    $was = (bool) nibwp_wpms_get('general', 'do_not_send');
    $written = nibwp_wpms_write('general.do_not_send', $stop);

    if (is_wp_error($written)) {
        return $written;
    }

    return [
        'updated' => true,
        'sending_disabled' => (bool) nibwp_wpms_get('general', 'do_not_send'),
        'was' => $was,
        'note' => $stop
            ? __('Outgoing email is now switched off. Nothing will be queued or retried — messages are simply not sent.', 'nibwp')
            : __('Outgoing email is allowed again. Confirm with send_test_email.', 'nibwp'),
    ];
}
