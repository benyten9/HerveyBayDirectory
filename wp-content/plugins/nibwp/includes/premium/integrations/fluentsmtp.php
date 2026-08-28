<?php

declare(strict_types=1);

/**
 * FluentSMTP integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Six domain-grouped abilities let an AI agent fully configure and operate
 * FluentSMTP: discovery, the list of mail connections, configuring an email
 * server (SMTP host/port/auth or any API provider — SendGrid, Mailgun, SES,
 * Postmark, Gmail, Outlook…), routing/default/fallback, sending a real test
 * email, the email logs, and the misc settings (logging, retention).
 *
 * Mechanism: in-process, through FluentSMTP's own settings layer
 * (fluentMailGetSettings / fluentMailSetSettings, the FluentMail\App\Models\
 * Settings model) so connections are stored exactly as the plugin expects — a
 * connection keyed by md5(sender_email) holding provider_settings, an email→key
 * mappings table, and misc.default_connection / fallback_connection. Test emails
 * go through wp_mail(), which FluentSMTP intercepts and routes via the active
 * connection; failures are captured from the wp_mail_failed hook. Logs read the
 * fsmtp_email_logs table when logging is enabled. Verified against FluentSMTP
 * 2.x (16 providers; SMTP provider_settings keys host/port/auth/username/
 * password/encryption/auto_tls/sender_email/sender_name).
 *
 * Detection: FLUENTMAIL_PLUGIN_VERSION / fluentMailGetSettings().
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is FluentSMTP active? */
function nibwp_fsmtp_available(): bool
{
    return defined('FLUENTMAIL_PLUGIN_VERSION') || function_exists('fluentMailGetSettings');
}

/** The full FluentSMTP settings array. */
function nibwp_fsmtp_settings(): array
{
    if (function_exists('fluentMailGetSettings')) {
        $s = fluentMailGetSettings();
        return is_array($s) ? $s : [];
    }
    $s = get_option('fluentmail-settings', []);
    return is_array($s) ? $s : [];
}

/** Persist the full FluentSMTP settings array. */
function nibwp_fsmtp_save(array $settings): void
{
    if (function_exists('fluentMailSetSettings')) {
        fluentMailSetSettings($settings);
    } else {
        update_option('fluentmail-settings', $settings);
    }
}

/** House WP_Error wrapper. */
function nibwp_fsmtp_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** The supported provider keys. */
function nibwp_fsmtp_providers(): array
{
    return ['smtp', 'sendgrid', 'mailgun', 'ses', 'gmail', 'outlook', 'postmark', 'sparkpost', 'sendinblue', 'elasticmail', 'pepipost', 'smtp2go', 'sendpulse', 'default'];
}

/** Redact secrets when returning a connection for reading. */
function nibwp_fsmtp_redact(array $ps): array
{
    foreach (['password', 'api_key', 'secret_key', 'access_key', 'key', 'server_api_token', 'domain_api_key'] as $k) {
        if (!empty($ps[$k])) {
            $ps[$k] = '••••••••';
        }
    }
    return $ps;
}

/** Store a connection (any provider) keyed by md5(sender_email) + map the sender. */
function nibwp_fsmtp_store_connection(array $providerSettings, string $title, bool $makeDefault): array
{
    $settings = nibwp_fsmtp_settings();
    $email = (string) ($providerSettings['sender_email'] ?? '');
    if ($email === '' || !is_email($email)) {
        return ['error' => 'A valid sender_email is required.'];
    }
    $key = md5($email);
    $settings['connections'] = is_array($settings['connections'] ?? null) ? $settings['connections'] : [];
    $settings['mappings']    = is_array($settings['mappings'] ?? null) ? $settings['mappings'] : [];
    $settings['misc']        = is_array($settings['misc'] ?? null) ? $settings['misc'] : [];

    $settings['connections'][$key] = [
        'title'             => $title !== '' ? sanitize_text_field($title) : $email,
        'provider_settings' => $providerSettings,
    ];
    $settings['mappings'][$email] = $key;

    if ($makeDefault || empty($settings['misc']['default_connection'])) {
        $settings['misc']['default_connection'] = $key;
    }
    nibwp_fsmtp_save($settings);
    return ['key' => $key, 'email' => $email];
}

/* ----------------------------------------------------------------------------
 * 1) fluentsmtp-info — discovery
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentsmtp-info', [
    'label'       => __('FluentSMTP — Info', 'nibwp'),
    'description' => __('Detect FluentSMTP, its version, whether it is configured, the number of connections, the default + fallback connection, logging state, and the supported providers.', 'nibwp'),
    'category'    => 'email',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fsmtp_info_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_fsmtp_info_execute(array $input): array|WP_Error
{
    if (!nibwp_fsmtp_available()) {
        return nibwp_fsmtp_err('fsmtp_inactive', 'FluentSMTP is not active on this site.', 404);
    }
    global $wpdb;
    $settings = nibwp_fsmtp_settings();
    $connections = is_array($settings['connections'] ?? null) ? $settings['connections'] : [];
    $misc = is_array($settings['misc'] ?? null) ? $settings['misc'] : [];
    $logTable = $wpdb->prefix . 'fsmtp_email_logs';
    $hasLogTable = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logTable)) === $logTable;

    return [
        'fluentsmtp_active' => true,
        'version'      => defined('FLUENTMAIL_PLUGIN_VERSION') ? FLUENTMAIL_PLUGIN_VERSION : '',
        'configured'   => count($connections) > 0,
        'connection_count' => count($connections),
        'default_connection' => $misc['default_connection'] ?? '',
        'fallback_connection' => $misc['fallback_connection'] ?? '',
        'logging_enabled' => ($misc['log_emails'] ?? 'no') === 'yes',
        'log_table_ready' => $hasLogTable,
        'log_count'    => $hasLogTable ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logTable}") : 0,
        'providers'    => nibwp_fsmtp_providers(),
        'abilities'    => ['connections', 'configure', 'send-test', 'logs', 'settings'],
    ];
}

/* ----------------------------------------------------------------------------
 * 2) fluentsmtp-connections — list / get / delete
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentsmtp-connections', [
    'label'       => __('FluentSMTP — Connections', 'nibwp'),
    'description' => __('List, read and delete FluentSMTP mail connections. Secrets are redacted on read. Actions: list, get, delete.', 'nibwp'),
    'category'    => 'email',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'get', 'delete']],
            'key'    => ['type' => 'string', 'description' => 'Connection key (md5 of its sender email).'],
            'email'  => ['type' => 'string', 'description' => 'Alternatively identify the connection by sender email.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fsmtp_connections_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_fsmtp_connections_execute(array $input): array|WP_Error
{
    if (!nibwp_fsmtp_available()) {
        return nibwp_fsmtp_err('fsmtp_inactive', 'FluentSMTP is not active on this site.', 404);
    }
    $action   = (string) ($input['action'] ?? '');
    $settings = nibwp_fsmtp_settings();
    $connections = is_array($settings['connections'] ?? null) ? $settings['connections'] : [];
    $mappings = is_array($settings['mappings'] ?? null) ? $settings['mappings'] : [];

    $resolveKey = static function (array $in) use ($connections, $mappings): string {
        if (!empty($in['key'])) { return (string) $in['key']; }
        if (!empty($in['email']) && isset($mappings[$in['email']])) { return (string) $mappings[$in['email']]; }
        if (!empty($in['email'])) { return md5((string) $in['email']); }
        return '';
    };

    switch ($action) {
        case 'list':
            $out = [];
            $default = $settings['misc']['default_connection'] ?? '';
            foreach ($connections as $key => $conn) {
                $ps = is_array($conn['provider_settings'] ?? null) ? $conn['provider_settings'] : [];
                $out[] = [
                    'key'          => $key,
                    'title'        => $conn['title'] ?? '',
                    'provider'     => $ps['provider'] ?? '',
                    'sender_email' => $ps['sender_email'] ?? '',
                    'sender_name'  => $ps['sender_name'] ?? '',
                    'is_default'   => $key === $default,
                ];
            }
            return ['connections' => $out, 'count' => count($out)];

        case 'get':
            $key = $resolveKey($input);
            if (!isset($connections[$key])) {
                return nibwp_fsmtp_err('not_found', 'Connection not found.', 404);
            }
            $conn = $connections[$key];
            $conn['provider_settings'] = nibwp_fsmtp_redact(is_array($conn['provider_settings'] ?? null) ? $conn['provider_settings'] : []);
            return ['key' => $key, 'connection' => $conn];

        case 'delete':
            $key = $resolveKey($input);
            if (!isset($connections[$key])) {
                return nibwp_fsmtp_err('not_found', 'Connection not found.', 404);
            }
            unset($connections[$key]);
            foreach ($mappings as $email => $mappedKey) {
                if ($mappedKey === $key) {
                    unset($mappings[$email]);
                }
            }
            $settings['connections'] = $connections;
            $settings['mappings'] = $mappings;
            if (($settings['misc']['default_connection'] ?? '') === $key) {
                $settings['misc']['default_connection'] = (string) (array_key_first($mappings) ? $mappings[array_key_first($mappings)] : '');
            }
            if (($settings['misc']['fallback_connection'] ?? '') === $key) {
                $settings['misc']['fallback_connection'] = '';
            }
            nibwp_fsmtp_save($settings);
            return ['deleted' => true, 'key' => $key];
    }
    return nibwp_fsmtp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 3) fluentsmtp-configure — set up an email server / provider
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentsmtp-configure', [
    'label'       => __('FluentSMTP — Configure', 'nibwp'),
    'description' => __('Configure how WordPress sends mail. Actions: save_smtp (a custom SMTP server — host, port, encryption, auth), save_provider (any API provider with raw provider_settings), set_default, set_fallback, map_sender. After saving, use fluentsmtp-send-test to verify delivery.', 'nibwp'),
    'category'    => 'email',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['save_smtp', 'save_provider', 'set_default', 'set_fallback', 'map_sender']],
            'title'        => ['type' => 'string'],
            'sender_email' => ['type' => 'string'],
            'sender_name'  => ['type' => 'string'],
            'host'         => ['type' => 'string', 'description' => 'save_smtp: SMTP host.'],
            'port'         => ['type' => 'integer', 'description' => 'save_smtp: 587 (TLS), 465 (SSL), 25.'],
            'encryption'   => ['type' => 'string', 'enum' => ['tls', 'ssl', 'none'], 'default' => 'tls'],
            'auto_tls'     => ['type' => 'boolean', 'default' => true],
            'username'     => ['type' => 'string'],
            'password'     => ['type' => 'string'],
            'provider'     => ['type' => 'string', 'description' => 'save_provider: e.g. sendgrid, mailgun, ses, postmark, gmail, outlook.'],
            'provider_settings' => ['type' => 'object', 'description' => 'save_provider: the raw provider config (api keys, region, domain…) merged with sender + provider.'],
            'make_default' => ['type' => 'boolean', 'default' => true],
            'key'          => ['type' => 'string', 'description' => 'set_default/set_fallback: the connection key.'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fsmtp_configure_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'instructions' => 'save_smtp stores the SMTP password in the database (FluentSMTP key_store=db). Always run fluentsmtp-send-test afterwards. A connection is keyed by its sender_email — re-saving the same sender overwrites it.']],
]);

function nibwp_fsmtp_configure_execute(array $input): array|WP_Error
{
    if (!nibwp_fsmtp_available()) {
        return nibwp_fsmtp_err('fsmtp_inactive', 'FluentSMTP is not active on this site.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $name   = sanitize_text_field((string) ($input['sender_name'] ?? get_bloginfo('name')));
    $email  = sanitize_email((string) ($input['sender_email'] ?? ''));

    switch ($action) {
        case 'save_smtp':
            if ($email === '' || empty($input['host'])) {
                return nibwp_fsmtp_err('bad_input', 'save_smtp needs sender_email + host.');
            }
            $enc = in_array($input['encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $input['encryption'] : 'tls';
            $hasAuth = isset($input['username']) && $input['username'] !== '';
            $ps = [
                'provider'        => 'smtp',
                'sender_name'     => $name,
                'sender_email'    => $email,
                'force_from_name' => 'no',
                'force_from_email'=> 'no',
                'host'            => sanitize_text_field((string) $input['host']),
                'port'            => (string) ((int) ($input['port'] ?? ($enc === 'ssl' ? 465 : 587))),
                'auth'            => $hasAuth ? 'yes' : 'no',
                'username'        => (string) ($input['username'] ?? ''),
                'password'        => (string) ($input['password'] ?? ''),
                'encryption'      => $enc,
                'auto_tls'        => !empty($input['auto_tls']) ? 'yes' : 'no',
                'key_store'       => 'db',
            ];
            $res = nibwp_fsmtp_store_connection($ps, (string) ($input['title'] ?? ''), !empty($input['make_default']));
            return isset($res['error']) ? nibwp_fsmtp_err('save_failed', $res['error']) : ['saved' => true, 'provider' => 'smtp'] + $res;

        case 'save_provider':
            $provider = sanitize_key((string) ($input['provider'] ?? ''));
            if ($provider === '' || $email === '') {
                return nibwp_fsmtp_err('bad_input', 'save_provider needs provider + sender_email.');
            }
            $ps = is_array($input['provider_settings'] ?? null) ? $input['provider_settings'] : [];
            $ps['provider']     = $provider;
            $ps['sender_email'] = $email;
            $ps['sender_name']  = $name;
            $ps += ['force_from_name' => 'no', 'force_from_email' => 'no', 'key_store' => 'db'];
            $res = nibwp_fsmtp_store_connection($ps, (string) ($input['title'] ?? ''), !empty($input['make_default']));
            return isset($res['error']) ? nibwp_fsmtp_err('save_failed', $res['error']) : ['saved' => true, 'provider' => $provider] + $res;

        case 'set_default':
        case 'set_fallback':
            $settings = nibwp_fsmtp_settings();
            $key = (string) ($input['key'] ?? '');
            if (!isset($settings['connections'][$key])) {
                return nibwp_fsmtp_err('not_found', 'Connection key not found.', 404);
            }
            $settings['misc'] = is_array($settings['misc'] ?? null) ? $settings['misc'] : [];
            $settings['misc'][$action === 'set_default' ? 'default_connection' : 'fallback_connection'] = $key;
            nibwp_fsmtp_save($settings);
            return ['updated' => true, $action => $key];

        case 'map_sender':
            $settings = nibwp_fsmtp_settings();
            $key = (string) ($input['key'] ?? '');
            if (!isset($settings['connections'][$key])) {
                return nibwp_fsmtp_err('not_found', 'Connection key not found.', 404);
            }
            if ($email === '') {
                return nibwp_fsmtp_err('bad_input', 'map_sender needs sender_email + key.');
            }
            $settings['mappings'] = is_array($settings['mappings'] ?? null) ? $settings['mappings'] : [];
            $settings['mappings'][$email] = $key;
            nibwp_fsmtp_save($settings);
            return ['mapped' => true, 'email' => $email, 'key' => $key];
    }
    return nibwp_fsmtp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 4) fluentsmtp-send-test — send a real test email
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentsmtp-send-test', [
    'label'       => __('FluentSMTP — Send Test', 'nibwp'),
    'description' => __('Send a real test email through the active FluentSMTP connection (via wp_mail) and report success or the exact delivery error. Actions: send. Optionally route through a specific from-email/connection.', 'nibwp'),
    'category'    => 'email',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'to'         => ['type' => 'string', 'description' => 'Recipient email. Defaults to the site admin.'],
            'subject'    => ['type' => 'string'],
            'body'       => ['type' => 'string', 'description' => 'HTML body.'],
            'from_email' => ['type' => 'string', 'description' => 'Force the From address (routes via its mapped connection).'],
            'from_name'  => ['type' => 'string'],
        ],
        'required' => [],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fsmtp_send_test_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Sends a genuine email. Use a recipient you control. The result includes the captured wp_mail_failed error when delivery fails.']],
]);

function nibwp_fsmtp_send_test_execute(array $input): array|WP_Error
{
    if (!nibwp_fsmtp_available()) {
        return nibwp_fsmtp_err('fsmtp_inactive', 'FluentSMTP is not active on this site.', 404);
    }
    $to = sanitize_email((string) ($input['to'] ?? get_option('admin_email')));
    if ($to === '' || !is_email($to)) {
        return nibwp_fsmtp_err('bad_to', 'Provide a valid "to" email.');
    }
    $subject = sanitize_text_field((string) ($input['subject'] ?? ('FluentSMTP test from ' . get_bloginfo('name'))));
    $body    = (string) ($input['body'] ?? ('<p>This is a test email sent via FluentSMTP + NIBWP at ' . esc_html(current_time('mysql')) . '.</p>'));

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    if (!empty($input['from_email']) && is_email((string) $input['from_email'])) {
        $fromName = sanitize_text_field((string) ($input['from_name'] ?? get_bloginfo('name')));
        $headers[] = 'From: ' . $fromName . ' <' . sanitize_email((string) $input['from_email']) . '>';
    }

    // Capture any delivery failure.
    $captured = ['error' => ''];
    $listener = static function ($wp_error) use (&$captured) {
        if (is_wp_error($wp_error)) {
            $captured['error'] = $wp_error->get_error_message();
            $data = $wp_error->get_error_data();
            if (is_array($data) || is_string($data)) {
                $captured['detail'] = $data;
            }
        }
    };
    add_action('wp_mail_failed', $listener);
    $ok = wp_mail($to, $subject, $body, $headers);
    remove_action('wp_mail_failed', $listener);

    // Which connection handled it.
    $settings = nibwp_fsmtp_settings();
    $mappings = is_array($settings['mappings'] ?? null) ? $settings['mappings'] : [];
    $usedKey = !empty($input['from_email']) && isset($mappings[$input['from_email']])
        ? $mappings[$input['from_email']]
        : ($settings['misc']['default_connection'] ?? '');
    $conn = $settings['connections'][$usedKey] ?? null;

    return [
        'sent'        => (bool) $ok,
        'to'          => $to,
        'subject'     => $subject,
        'connection'  => $usedKey,
        'provider'    => $conn['provider_settings']['provider'] ?? '',
        'error'       => $captured['error'],
        'detail'      => $captured['detail'] ?? null,
    ];
}

/* ----------------------------------------------------------------------------
 * 5) fluentsmtp-logs — the email log
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentsmtp-logs', [
    'label'       => __('FluentSMTP — Logs', 'nibwp'),
    'description' => __('Read and manage the FluentSMTP email log (requires logging enabled). Actions: list, get, resend, delete, clear. Filter list by status (sent|failed).', 'nibwp'),
    'category'    => 'email',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'resend', 'delete', 'clear']],
            'id'       => ['type' => 'integer'],
            'status'   => ['type' => 'string', 'enum' => ['sent', 'failed']],
            'per_page' => ['type' => 'integer'],
            'page'     => ['type' => 'integer'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fsmtp_logs_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
]);

function nibwp_fsmtp_logs_execute(array $input): array|WP_Error
{
    if (!nibwp_fsmtp_available()) {
        return nibwp_fsmtp_err('fsmtp_inactive', 'FluentSMTP is not active on this site.', 404);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fsmtp_email_logs';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return nibwp_fsmtp_err('no_logs', 'The email log table does not exist — enable logging via fluentsmtp-settings (log_emails) first.', 404);
    }
    $action = (string) ($input['action'] ?? '');
    $per  = min(max((int) ($input['per_page'] ?? 25), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);
    $offset = ($page - 1) * $per;

    switch ($action) {
        case 'list':
            $where = '';
            if (!empty($input['status']) && in_array($input['status'], ['sent', 'failed'], true)) {
                $where = $wpdb->prepare(' WHERE status = %s', $input['status']);
            }
            $rows = $wpdb->get_results("SELECT id, `to`, `from`, subject, status, created_at FROM {$table}{$where} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}", ARRAY_A);
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");
            return ['logs' => $rows ?: [], 'count' => count($rows ?: []), 'total' => $total];

        case 'get':
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) ($input['id'] ?? 0)), ARRAY_A);
            return $row ? ['log' => $row] : nibwp_fsmtp_err('not_found', 'Log entry not found.', 404);

        case 'resend':
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) ($input['id'] ?? 0)), ARRAY_A);
            if (!$row) {
                return nibwp_fsmtp_err('not_found', 'Log entry not found.', 404);
            }
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $ok = wp_mail($row['to'], $row['subject'], (string) ($row['body'] ?? $row['extra'] ?? ''), $headers);
            return ['resent' => (bool) $ok, 'id' => (int) $row['id'], 'to' => $row['to']];

        case 'delete':
            $deleted = $wpdb->delete($table, ['id' => (int) ($input['id'] ?? 0)]);
            return ['deleted' => (int) $deleted];

        case 'clear':
            $wpdb->query("TRUNCATE TABLE {$table}");
            return ['cleared' => true];
    }
    return nibwp_fsmtp_err('bad_action', 'Unknown action: ' . $action);
}

/* ----------------------------------------------------------------------------
 * 6) fluentsmtp-settings — misc settings (logging, retention, routing)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentsmtp-settings', [
    'label'       => __('FluentSMTP — Settings', 'nibwp'),
    'description' => __('Read/write FluentSMTP misc settings: email logging on/off, log retention days, default + fallback connection, and FluentCRM log behavior. Actions: get, update.', 'nibwp'),
    'category'    => 'email',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'update']],
            'settings' => ['type' => 'object', 'description' => 'For update: keys like log_emails (yes|no), log_saved_interval_days, default_connection, fallback_connection, disable_fluentcrm_logs (yes|no).'],
        ],
        'required' => ['action'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fsmtp_settings_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_fsmtp_settings_execute(array $input): array|WP_Error
{
    if (!nibwp_fsmtp_available()) {
        return nibwp_fsmtp_err('fsmtp_inactive', 'FluentSMTP is not active on this site.', 404);
    }
    $settings = nibwp_fsmtp_settings();
    $misc = is_array($settings['misc'] ?? null) ? $settings['misc'] : [];
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'get':
            return ['misc' => $misc];

        case 'update':
            $patch = (array) ($input['settings'] ?? []);
            if ($patch === []) {
                return nibwp_fsmtp_err('no_settings', 'Provide a non-empty "settings" object.');
            }
            $allowed = ['log_emails', 'log_saved_interval_days', 'default_connection', 'fallback_connection', 'disable_fluentcrm_logs', 'is_inline_logging_disabled'];
            $changed = [];
            foreach ($patch as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $misc[$k] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
                    $changed[] = $k;
                }
            }
            $settings['misc'] = $misc;
            nibwp_fsmtp_save($settings);
            return ['updated' => $changed, 'misc' => $misc];
    }
    return nibwp_fsmtp_err('bad_action', 'Unknown action: ' . $action);
}
