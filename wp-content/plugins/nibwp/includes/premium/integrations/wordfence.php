<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Wordfence.
 *
 * Deliberately read-mostly. An agent that can switch a firewall off is a
 * liability, and one that can read the firewall's own secrets is worse — so the
 * reads cover everything an owner needs to judge their security posture, and the
 * writes are three named operations and nothing else: start a scan, block an IP,
 * unblock an IP.
 *
 * What is NOT here, and will not be: disabling the firewall or the scanner,
 * changing rules wholesale, whitelisting a path or a rule, turning off two-factor
 * or login protection, deleting scan findings, and repairing or deleting a file
 * the scanner flagged. Every one of those is a decision a person should make in
 * wp-admin with the consequences in front of them, and the plugin's own screens
 * are one click away. A test parses the enum and fails if an action whose name
 * reads like any of them ever appears, so a later edit has to argue with it.
 *
 * Verified against Wordfence 9.0.2 (wordfence.php:41).
 *
 * SECRETS: wfConfig holds the plugin's API key, and the login-security module
 * holds per-user two-factor secrets and recovery codes. Settings leave here as a
 * name and whether they are set, through an allow-list of fields that may travel.
 * An allow-list rather than a denylist because wfConfig is one flat key space with
 * hundreds of keys — naming the handful that are safe is tractable, guessing every
 * key that is dangerous is not, and a key added by a later version would default
 * to leaking.
 *
 * PERSONAL DATA: block records and live traffic carry visitor IP addresses.
 * Counts and reasons are preferred throughout; the one action that necessarily
 * returns addresses is the block list, which is bounded and takes manage_options
 * like everything else here.
 */

/** Every action on this hub reads or writes security posture. */
const NIBWP_WF_CAP = 'manage_options';

/** How many rows any listing will return, whatever was asked for. */
const NIBWP_WF_MAX_ROWS = 200;

/**
 * Is Wordfence actually loaded?
 *
 * The constant is defined by the main plugin file before anything else, and the
 * config class is what every read here goes through, so between them they
 * distinguish "not installed" from "installed and broken".
 */
function nibwp_wf_guard(): ?WP_Error
{
    if (!defined('WORDFENCE_VERSION') && !class_exists('wfConfig')) {
        return new WP_Error(
            'nibwp_wf_missing',
            __('Wordfence is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about authority rather than about the request. */
function nibwp_wf_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_wf_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_WF_CAP
        ),
        ['status' => 403]
    );
}

/** The one capability gate, applied to reads as well as writes. */
function nibwp_wf_require(string $what): ?WP_Error
{
    // Reading security posture is not a lower bar than changing it: the scan
    // findings name the vulnerable file, and the block list names real visitors.
    return current_user_can(NIBWP_WF_CAP) ? null : nibwp_wf_forbidden($what);
}

/** A refusal about the request itself. */
function nibwp_wf_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_wf_' . $code, $message, ['status' => $status]);
}

/**
 * Read one Wordfence config value without letting the plugin's internals throw.
 *
 * wfConfig reaches the database and, for a few keys, the firewall's own storage
 * engine (lib/wfConfig.php:511). On a site mid-update that can raise, and an
 * uncaught Error in an ability takes the whole REST request with it.
 */
function nibwp_wf_config(string $key, mixed $default = false): mixed
{
    if (!class_exists('wfConfig') || !method_exists('wfConfig', 'get')) {
        return $default;
    }

    try {
        return wfConfig::get($key, $default);
    } catch (\Throwable $error) {
        return $default;
    }
}

/**
 * Config keys whose VALUE may be reported, and what each one is.
 *
 * Everything else in wfConfig is reported as a name and whether it is set. The
 * list is short on purpose: these are the switches an owner is asking about when
 * they ask whether the site is protected.
 *
 * @return array<string, string> key => bool|int|string
 */
function nibwp_wf_public_config(): array
{
    return [
        'scheduledScansEnabled'      => 'bool',
        'autoUpdate'                 => 'bool',
        'other_scanComment'          => 'bool',
        'scansEnabled_core'          => 'bool',
        'scansEnabled_themes'        => 'bool',
        'scansEnabled_plugins'       => 'bool',
        'scansEnabled_malware'       => 'bool',
        'scansEnabled_fileContents'  => 'bool',
        'scansEnabled_posts'         => 'bool',
        'scansEnabled_comments'      => 'bool',
        'scansEnabled_passwds'       => 'bool',
        'scansEnabled_diskSpace'     => 'bool',
        'scansEnabled_options'       => 'bool',
        'scansEnabled_suspiciousAdminUsers' => 'bool',
        'loginSec_enableSeparateTwoFactor' => 'bool',
        'loginSec_lockInvalidUsers'  => 'bool',
        'loginSec_maxFailures'       => 'int',
        'loginSec_maxForgotPasswd'   => 'int',
        'loginSec_lockoutMins'       => 'int',
        'loginSecurityBlockAdminReg' => 'bool',
        'loginSec_breachPasswds_enabled' => 'bool',
        'loginSec_strongPasswds_enabled' => 'bool',
        'blockFakeBots'              => 'bool',
        'neverBlockBG'               => 'string',
        'liveTrafficEnabled'         => 'bool',
        'alertOn_block'              => 'bool',
        'alertOn_loginLockout'       => 'bool',
        'alertOn_scanIssues'         => 'bool',
        'alertOn_update'             => 'bool',
        'alertOn_wordfenceDeactivated' => 'bool',
        'isPaid'                     => 'bool',
    ];
}

/**
 * Config keys whose value must never travel, however it is asked for.
 *
 * This exists as a second layer rather than the only one: the allow-list above
 * already governs what leaves. A name landing in both is a mistake worth
 * failing loudly on, which the check asserts.
 *
 * @return array<int, string>
 */
function nibwp_wf_secret_config(): array
{
    return [
        'apiKey',
        'encKey',
        'longEncKey',
        'wf_SchedToRun',
        'authKey',
        'wafRulesCRC',
        'wordfenceI18n',
    ];
}

/**
 * Report one setting the way this integration reports settings.
 *
 * @return array<string, mixed>
 */
function nibwp_wf_setting_row(string $key, mixed $value, string $type): array
{
    return [
        'key'   => $key,
        'value' => match ($type) {
            'bool' => (bool) $value,
            'int'  => (int) $value,
            default => is_scalar($value) ? (string) $value : null,
        },
    ];
}

/**
 * Clamp a row count that arrived over the wire.
 */
function nibwp_wf_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_WF_MAX_ROWS, $limit));
}

/**
 * Validate an IP address from input.
 *
 * Checked before it reaches the plugin: Wordfence stores blocks keyed by address
 * and a malformed one would either be stored as a block that matches nobody or
 * passed into a query, and neither failure announces itself.
 */
function nibwp_wf_ip(mixed $raw): string|WP_Error
{
    $ip = is_string($raw) ? trim($raw) : '';

    if ($ip === '') {
        return nibwp_wf_err('missing_ip', __('Give the IP address as ip.', 'nibwp'), 422);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return nibwp_wf_err(
            'bad_ip',
            sprintf(
                /* translators: %s: the rejected value */
                __('"%s" is not an IP address.', 'nibwp'),
                $ip
            ),
            422
        );
    }

    return $ip;
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_wf_actions(): array
{
    return [
        // Reads.
        'security_summary',
        'protection_status',
        'scan_status',
        'scan_findings',
        'vulnerable_components',
        'login_security',
        'blocked_activity',
        'list_blocks',
        'get_settings',
        // Writes — three, and no more.
        'start_scan',
        'block_ip',
        'unblock_ip',
    ];
}

wp_register_ability('nibwp/wordfence-manage', [
    'label' => __('Wordfence – Security Posture', 'nibwp'),
    'description' => 'Read this site\'s Wordfence security posture: whether the firewall is in extended protection, when the last scan ran and what it found, '
        . 'which installed plugins or themes are known to be vulnerable, whether two-factor is required for administrators, and what has been blocked lately. '
        . 'Three write operations and no others: start a scan, block an IP, unblock an IP. '
        . 'Nothing here can disable the firewall or the scanner, change rules, whitelist anything, turn off two-factor, or touch a file the scanner flagged — those stay in wp-admin. '
        . 'The plugin\'s API key and any two-factor secret are never returned.',
    'category' => 'security',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_wf_actions(),
                'description' => 'Which operation to run. Start with security_summary.',
            ],
            'ip' => ['type' => 'string', 'description' => 'For block_ip / unblock_ip: the address, IPv4 or IPv6.'],
            'reason' => ['type' => 'string', 'description' => 'For block_ip: why, recorded against the block.'],
            'days' => ['type' => 'integer', 'description' => 'For blocked_activity: how many days back. Default 7, max 90.'],
            'severity' => ['type' => 'string', 'description' => 'For scan_findings: critical or warning. Left out, both.'],
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
    'execute_callback' => 'nibwp_wordfence_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Wordfence, read-mostly on purpose.',
                '',
                'START WITH security_summary. It puts the handful of things an owner should',
                'act on into one response: whether the firewall is in extended protection,',
                'whether scans are scheduled, what the last scan found, whether any installed',
                'component is known to be vulnerable, and whether administrators are required',
                'to use two-factor.',
                '',
                'WHAT THIS CANNOT DO, by design:',
                'It cannot disable the firewall or the scanner, change or whitelist a rule,',
                'turn off two-factor or login protection, delete a scan finding, or repair or',
                'delete a file the scanner flagged. Do not look for another action that does;',
                'there is not one. Those are decisions for a person in wp-admin, and saying so',
                'is the correct answer when asked.',
                '',
                'THE THREE WRITES:',
                '- start_scan queues a scan. It does not wait for it; poll scan_status.',
                '- block_ip blocks one address. Say the address back to the user.',
                '- unblock_ip removes a block on one address.',
                '',
                'SECRETS: get_settings reports values only for a short list of protection',
                'switches. Everything else comes back as a name and whether it is set. The',
                'API key and any two-factor secret never travel, and asking differently will',
                'not change that.',
                '',
                'PERSONAL DATA: blocked_activity and list_blocks concern real visitors.',
                'Prefer blocked_activity, which is counts by reason, over list_blocks.',
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
function nibwp_wordfence_manage(array $input): array|WP_Error
{
    $guard = nibwp_wf_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_wf_actions(), true)) {
        return nibwp_wf_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Wordfence action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_wf_actions())
            )
        );
    }

    // Wordfence's own internals are version-sensitive and throw. One catch here
    // turns any of that into a named refusal rather than a fatal that takes the
    // whole REST request down.
    try {
        return nibwp_wf_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_wf_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from Wordfence */
                __('Wordfence could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_wf_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'security_summary' => nibwp_wf_security_summary(),
        'protection_status' => nibwp_wf_protection_status(),
        'scan_status' => nibwp_wf_scan_status(),
        'scan_findings' => nibwp_wf_scan_findings($input),
        'vulnerable_components' => nibwp_wf_vulnerable_components(),
        'login_security' => nibwp_wf_login_security(),
        'blocked_activity' => nibwp_wf_blocked_activity($input),
        'list_blocks' => nibwp_wf_list_blocks($input),
        'get_settings' => nibwp_wf_get_settings(),
        'start_scan' => nibwp_wf_start_scan(),
        'block_ip' => nibwp_wf_block_ip($input),
        'unblock_ip' => nibwp_wf_unblock_ip($input),
        default => nibwp_wf_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * The firewall's own state.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_protection_status(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence protection status', 'nibwp'))) {
        return $denied;
    }

    $level = null;
    $learning = null;

    // The WAF keeps its own storage engine, separate from wfConfig, and it is
    // absent on an install where the firewall never initialised.
    if (class_exists('wfWAF') && method_exists('wfWAF', 'getInstance')) {
        $waf = wfWAF::getInstance();

        if (is_object($waf) && method_exists($waf, 'getStorageEngine')) {
            $storage = $waf->getStorageEngine();

            if (is_object($storage) && method_exists($storage, 'getConfig')) {
                $level = $storage->getConfig('wafStatus', null);
                $learning = (bool) $storage->getConfig('learningModeAttackThreshold', false);
            }
        }
    }

    $extended = defined('WFWAF_AUTO_PREPEND') && WFWAF_AUTO_PREPEND;

    return [
        'plugin_version' => defined('WORDFENCE_VERSION') ? (string) constant('WORDFENCE_VERSION') : null,
        'edition' => nibwp_wf_config('isPaid') ? 'premium' : 'free',
        'firewall' => [
            'status' => is_string($level) ? $level : null,
            'extended_protection' => $extended,
            'learning_mode' => $learning,
            'note' => $extended
                ? null
                : __('The firewall is in basic protection: it runs as a WordPress plugin, so it sees a request only after WordPress has started. Extended protection runs it before WordPress loads.', 'nibwp'),
        ],
        'live_traffic_enabled' => (bool) nibwp_wf_config('liveTrafficEnabled'),
        'scheduled_scans_enabled' => (bool) nibwp_wf_config('scheduledScansEnabled'),
        'api_key_set' => nibwp_wf_config('apiKey') !== false && nibwp_wf_config('apiKey') !== '',
        'api_key_returned' => false,
    ];
}

/**
 * When the last scan ran and how it ended.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_scan_status(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence scan status', 'nibwp'))) {
        return $denied;
    }

    // 'ok' means the scan finished cleanly; anything else is the failure text
    // (lib/wfDashboard.php:80-102), so it is reported as a message rather than a
    // status an agent might try to compare against a list.
    $last = nibwp_wf_config('lastScanCompleted');
    $running = false;

    if (class_exists('wfScanner') && method_exists('wfScanner', 'shared')) {
        $scanner = wfScanner::shared();

        if (is_object($scanner) && method_exists($scanner, 'isRunning')) {
            $running = (bool) $scanner->isRunning();
        }
    }

    $start = nibwp_wf_config('scanTime');

    return [
        'running' => $running,
        'last_completed' => is_numeric($start) ? gmdate('Y-m-d H:i:s', (int) $start) : null,
        'last_result' => $last === 'ok' ? 'clean' : ($last === false || $last === '' ? 'never_run' : 'failed'),
        'last_failure_message' => is_string($last) && $last !== 'ok' && $last !== '' ? $last : null,
        'scheduled' => (bool) nibwp_wf_config('scheduledScansEnabled'),
    ];
}

/**
 * What the scanner found, in summary and as rows.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_scan_findings(array $input): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence scan findings', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('wfIssues')) {
        return nibwp_wf_err('no_issues_api', __('This version of Wordfence does not expose its scan findings where this integration can read them.', 'nibwp'), 500);
    }

    $issues = new wfIssues();
    $counts = method_exists($issues, 'getIssueCounts') ? (array) $issues->getIssueCounts() : [];
    $limit = nibwp_wf_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $wanted = isset($input['severity']) ? strtolower(trim((string) $input['severity'])) : '';

    if ($wanted !== '' && !in_array($wanted, ['critical', 'warning'], true)) {
        return nibwp_wf_err('bad_severity', __('severity must be critical or warning.', 'nibwp'), 422);
    }

    // getIssues() takes separate windows for live and ignored findings
    // (lib/wfIssues.php:600). Only the live ones matter here: an ignored finding is
    // one somebody already decided about.
    $raw = method_exists($issues, 'getIssues') ? (array) $issues->getIssues($offset, $limit, 0, 0) : [];
    $rows = [];

    foreach (['new', 'critical', 'warning'] as $bucket) {
        foreach ((array) ($raw[$bucket] ?? []) as $issue) {
            $issue = (array) $issue;
            $severity = (int) ($issue['severity'] ?? 0) >= 2 ? 'critical' : 'warning';

            if ($wanted !== '' && $severity !== $wanted) {
                continue;
            }

            // The finding's own data can hold the contents of a flagged file. Only
            // what identifies the finding travels.
            $rows[] = [
                'id' => (int) ($issue['id'] ?? 0),
                'type' => (string) ($issue['type'] ?? ''),
                'severity' => $severity,
                'short_message' => (string) ($issue['shortMsg'] ?? ''),
                'concerns' => nibwp_wf_issue_subject((array) ($issue['data'] ?? [])),
                'found' => isset($issue['time']) && is_numeric($issue['time'])
                    ? gmdate('Y-m-d H:i:s', (int) $issue['time'])
                    : null,
            ];

            if (count($rows) >= $limit) {
                break 2;
            }
        }
    }

    return [
        'counts' => [
            'critical' => (int) ($counts['critical'] ?? 0),
            'warning' => (int) ($counts['warning'] ?? 0),
        ],
        'returned' => count($rows),
        'findings' => $rows,
        'file_contents_returned' => false,
        'note' => __('A finding names the file or component it concerns. The contents of a flagged file are not returned, and nothing here repairs or deletes one — that is done in wp-admin.', 'nibwp'),
    ];
}

/**
 * What a finding is about, without carrying the finding's payload out.
 *
 * @param array<string, mixed> $data
 */
function nibwp_wf_issue_subject(array $data): string
{
    foreach (['file', 'slug', 'option_name', 'table', 'name'] as $key) {
        if (isset($data[$key]) && is_scalar($data[$key])) {
            return (string) $data[$key];
        }
    }

    return '';
}

/**
 * Installed plugins and themes Wordfence knows to be vulnerable.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_vulnerable_components(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence vulnerability findings', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('wfIssues')) {
        return nibwp_wf_err('no_issues_api', __('This version of Wordfence does not expose its scan findings where this integration can read them.', 'nibwp'), 500);
    }

    // Wordfence records a vulnerable component as a scan finding rather than in a
    // list of its own, so this is that list filtered by type — which also means it
    // is only as current as the last scan, and the response says so.
    $issues = new wfIssues();
    $raw = method_exists($issues, 'getIssues') ? (array) $issues->getIssues(0, NIBWP_WF_MAX_ROWS, 0, 0) : [];
    $rows = [];

    foreach (['new', 'critical', 'warning'] as $bucket) {
        foreach ((array) ($raw[$bucket] ?? []) as $issue) {
            $issue = (array) $issue;
            $type = (string) ($issue['type'] ?? '');

            if (!str_contains($type, 'Vuln') && !str_contains($type, 'vuln')) {
                continue;
            }

            $data = (array) ($issue['data'] ?? []);
            $rows[] = [
                'type' => $type,
                'component' => nibwp_wf_issue_subject($data),
                'installed_version' => isset($data['version']) && is_scalar($data['version']) ? (string) $data['version'] : null,
                'severity' => (int) ($issue['severity'] ?? 0) >= 2 ? 'critical' : 'warning',
                'short_message' => (string) ($issue['shortMsg'] ?? ''),
            ];
        }
    }

    $last = nibwp_wf_config('lastScanCompleted');

    return [
        'count' => count($rows),
        'components' => $rows,
        'as_of' => $last === 'ok' ? 'last_successful_scan' : 'unknown',
        'note' => __('Wordfence records a vulnerable component when it scans, so this is as current as the last scan and no fresher. Run start_scan first if that matters.', 'nibwp'),
    ];
}

/**
 * The login-security posture.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_login_security(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence login security settings', 'nibwp'))) {
        return $denied;
    }

    // The login-security module keeps its own settings store, separate from
    // wfConfig (modules/login-security/classes/controller/settings.php:176). It is
    // absent on an install where that module never loaded.
    $two_factor = null;
    $require_admin = null;
    $captcha = null;

    $settings = 'WordfenceLS\\Controller_Settings';

    if (class_exists($settings) && method_exists($settings, 'shared')) {
        $shared = $settings::shared();

        if (is_object($shared) && method_exists($shared, 'get')) {
            $two_factor = (bool) $shared->get('enable-2fa', false);
            $require_admin = (bool) $shared->get('require-2fa.administrator', false);
            $captcha = (bool) $shared->get('enable-captcha', false);
        }
    }

    $blockers = [];

    if ($two_factor === false) {
        $blockers[] = __('Two-factor authentication is switched off altogether.', 'nibwp');
    } elseif ($require_admin === false) {
        $blockers[] = __('Two-factor is available but not required for administrators, so an administrator can still sign in with a password alone.', 'nibwp');
    }

    return [
        'two_factor_enabled' => $two_factor,
        'two_factor_required_for_admins' => $require_admin,
        'captcha_enabled' => $captcha,
        'lock_out_after_failures' => (int) nibwp_wf_config('loginSec_maxFailures'),
        'lockout_minutes' => (int) nibwp_wf_config('loginSec_lockoutMins'),
        'lock_invalid_usernames' => (bool) nibwp_wf_config('loginSec_lockInvalidUsers'),
        'block_breached_passwords' => (bool) nibwp_wf_config('loginSec_breachPasswds_enabled'),
        'require_strong_passwords' => (bool) nibwp_wf_config('loginSec_strongPasswds_enabled'),
        'blockers' => $blockers,
        'secrets_returned' => false,
        'note' => __('Per-user two-factor secrets and recovery codes are never returned, and nothing here can switch two-factor off.', 'nibwp'),
    ];
}

/**
 * What has been blocked lately, as counts by reason.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_blocked_activity(array $input): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence blocked activity', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_wf_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $days = isset($input['days']) ? (int) $input['days'] : 7;
    $days = max(1, min(90, $days));
    $since = time() - ($days * DAY_IN_SECONDS);

    $table = $wpdb->base_prefix . 'wfBlockedIPLog';

    // Counts by reason rather than rows: every row here is a real visitor's
    // address, and the question an owner is asking is "what is being blocked and
    // how much of it", which a count answers without naming anybody.
    $sql = $wpdb->prepare(
        'SELECT blockType, COUNT(*) AS hits FROM ' . $table
        . ' WHERE unixday >= %d GROUP BY blockType ORDER BY hits DESC LIMIT %d',
        (int) floor($since / DAY_IN_SECONDS),
        50
    );

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $by_reason = [];
    $total = 0;

    foreach ($rows as $row) {
        $hits = (int) ($row['hits'] ?? 0);
        $total += $hits;
        $by_reason[] = ['reason' => (string) ($row['blockType'] ?? ''), 'hits' => $hits];
    }

    return [
        'days' => $days,
        'total_hits' => $total,
        'by_reason' => $by_reason,
        'addresses_returned' => false,
        'note' => $rows === []
            ? __('Nothing recorded in that window. Wordfence keeps this log only while the firewall is active and prunes it, so an empty result is not proof nothing was blocked.', 'nibwp')
            : __('Counts only. Individual visitor addresses are not returned here; list_blocks reports the blocks an owner has in place.', 'nibwp'),
    ];
}

/**
 * The blocks currently in place.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_list_blocks(array $input): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence block list', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('wfBlock') || !method_exists('wfBlock', 'allBlocks')) {
        return nibwp_wf_err('no_block_api', __('This version of Wordfence does not expose its block list where this integration can read them.', 'nibwp'), 500);
    }

    $limit = nibwp_wf_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    // allBlocks() takes an offset and limit of its own (models/block/wfBlock.php:743).
    $blocks = (array) wfBlock::allBlocks(false, [], $offset, $limit);
    $rows = [];

    foreach ($blocks as $block) {
        if (!is_object($block)) {
            continue;
        }

        $rows[] = [
            'id' => isset($block->id) ? (int) $block->id : null,
            'type' => isset($block->type) ? (string) $block->type : null,
            'ip' => isset($block->ip) && is_scalar($block->ip) ? (string) $block->ip : null,
            'reason' => isset($block->reason) && is_scalar($block->reason) ? (string) $block->reason : null,
            'expires' => isset($block->expiration) && is_numeric($block->expiration) && (int) $block->expiration > 0
                ? gmdate('Y-m-d H:i:s', (int) $block->expiration)
                : 'never',
        ];
    }

    return [
        'returned' => count($rows),
        'blocks' => $rows,
        'note' => __('These are blocks in place, so they name addresses. unblock_ip removes one.', 'nibwp'),
    ];
}

/**
 * The protection switches, with values for the short list and names for the rest.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_get_settings(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence settings', 'nibwp'))) {
        return $denied;
    }

    $public = [];

    foreach (nibwp_wf_public_config() as $key => $type) {
        $public[] = nibwp_wf_setting_row($key, nibwp_wf_config($key), $type);
    }

    // The secrets are reported as names and whether they are set, and never as
    // values. They are listed rather than hidden so an owner can see that the API
    // key IS configured without being handed it.
    $withheld = [];

    foreach (nibwp_wf_secret_config() as $key) {
        $value = nibwp_wf_config($key);
        $withheld[] = [
            'key' => $key,
            'is_set' => $value !== false && $value !== '' && $value !== null,
            'value_returned' => false,
        ];
    }

    return [
        'settings' => $public,
        'withheld' => $withheld,
        'note' => __('Only the protection switches above report a value. Everything else in Wordfence\'s configuration, including the API key, is reported as a name and whether it is set.', 'nibwp'),
    ];
}

/**
 * The few things an owner should act on, in one response.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_security_summary(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Reading the Wordfence security summary', 'nibwp'))) {
        return $denied;
    }

    $protection = nibwp_wf_protection_status();
    $scan = nibwp_wf_scan_status();
    $findings = nibwp_wf_scan_findings([]);
    $vulnerable = nibwp_wf_vulnerable_components();
    $login = nibwp_wf_login_security();

    $act_on = [];

    if (!$protection instanceof WP_Error && ($protection['firewall']['extended_protection'] ?? null) === false) {
        $act_on[] = __('The firewall is in basic protection, so it only sees a request after WordPress has started.', 'nibwp');
    }
    if (!$scan instanceof WP_Error && ($scan['scheduled'] ?? null) === false) {
        $act_on[] = __('Scans are not scheduled, so nothing is checking this site unless somebody remembers to.', 'nibwp');
    }
    if (!$scan instanceof WP_Error && ($scan['last_result'] ?? '') === 'never_run') {
        $act_on[] = __('No scan has ever completed on this site.', 'nibwp');
    }
    $critical = $findings instanceof WP_Error ? 0 : (int) ($findings['counts']['critical'] ?? 0);
    $vulnerable_count = $vulnerable instanceof WP_Error ? 0 : (int) ($vulnerable['count'] ?? 0);

    // _n() rather than one string with a %d in it: a language with more than two
    // plural forms cannot be made to agree with a hardcoded English singular, and
    // the English itself reads wrong at one.
    if ($critical > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of critical scan findings */
            _n(
                '%d critical scan finding is waiting to be looked at.',
                '%d critical scan findings are waiting to be looked at.',
                $critical,
                'nibwp'
            ),
            $critical
        );
    }
    if ($vulnerable_count > 0) {
        $act_on[] = sprintf(
            /* translators: %d: number of vulnerable plugins or themes */
            _n(
                '%d installed plugin or theme is known to be vulnerable.',
                '%d installed plugins or themes are known to be vulnerable.',
                $vulnerable_count,
                'nibwp'
            ),
            $vulnerable_count
        );
    }
    if (!$login instanceof WP_Error) {
        foreach ((array) ($login['blockers'] ?? []) as $blocker) {
            $act_on[] = (string) $blocker;
        }
    }

    return [
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
        'protection' => $protection instanceof WP_Error ? null : $protection,
        'scan' => $scan instanceof WP_Error ? null : $scan,
        'findings' => $findings instanceof WP_Error ? null : ($findings['counts'] ?? null),
        'vulnerable_components' => $vulnerable instanceof WP_Error ? null : $vulnerable_count,
        'login_security' => $login instanceof WP_Error ? null : $login,
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Queue a scan.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_start_scan(): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Starting a Wordfence scan', 'nibwp'))) {
        return $denied;
    }

    if (!class_exists('wfScanEngine') || !method_exists('wfScanEngine', 'startScan')) {
        return nibwp_wf_err('no_scan_api', __('This version of Wordfence does not expose a way to start a scan from here.', 'nibwp'), 500);
    }

    if (class_exists('wfScanner') && method_exists('wfScanner', 'shared')) {
        $scanner = wfScanner::shared();

        if (is_object($scanner) && method_exists($scanner, 'isRunning') && $scanner->isRunning()) {
            return [
                'started' => false,
                'already_running' => true,
                'note' => __('A scan is already running. Poll scan_status rather than starting another.', 'nibwp'),
            ];
        }
    }

    wfScanEngine::startScan();

    return [
        'started' => true,
        'note' => __('The scan is queued. It runs in the background and can take a while on a large site — poll scan_status for the result rather than waiting.', 'nibwp'),
    ];
}

/**
 * Block one address.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_block_ip(array $input): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Blocking an IP address in Wordfence', 'nibwp'))) {
        return $denied;
    }

    $ip = nibwp_wf_ip($input['ip'] ?? null);
    if ($ip instanceof WP_Error) {
        return $ip;
    }

    if (!class_exists('wfBlock') || !method_exists('wfBlock', 'createIP')) {
        return nibwp_wf_err('no_block_api', __('This version of Wordfence does not expose a way to block an address from here.', 'nibwp'), 500);
    }

    $reason = isset($input['reason']) ? sanitize_text_field((string) $input['reason']) : '';

    if ($reason === '') {
        $reason = __('Blocked through NibWP.', 'nibwp');
    }

    // A blocked address stops reaching the site at all, including an address the
    // owner is themselves behind, so the reason is recorded and reported back.
    wfBlock::createIP($reason, $ip);

    return [
        'blocked' => true,
        'ip' => $ip,
        'reason' => $reason,
        'note' => __('That address can no longer reach this site. Say the address back to whoever asked, in case it is their own.', 'nibwp'),
    ];
}

/**
 * Remove a block on one address.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_wf_unblock_ip(array $input): array|WP_Error
{
    if ($denied = nibwp_wf_require(__('Unblocking an IP address in Wordfence', 'nibwp'))) {
        return $denied;
    }

    $ip = nibwp_wf_ip($input['ip'] ?? null);
    if ($ip instanceof WP_Error) {
        return $ip;
    }

    if (!class_exists('wfBlock') || !method_exists('wfBlock', 'unblockIP')) {
        return nibwp_wf_err('no_block_api', __('This version of Wordfence does not expose a way to unblock an address from here.', 'nibwp'), 500);
    }

    wfBlock::unblockIP($ip);

    return [
        'unblocked' => true,
        'ip' => $ip,
        'note' => __('Wordfence reports no error when the address was not blocked in the first place, so this does not confirm one existed. Check list_blocks.', 'nibwp'),
    ];
}
