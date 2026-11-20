<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Survey Maker.
 *
 * One hub. Surveys are authored and audited through it; responses are read in
 * aggregate, because a response is what a real person said and the plugin stores
 * their IP address, name and email address in the same row.
 *
 * Verified against Survey Maker 5.2.4.1 (survey-maker.php:19).
 *
 * HOW THE PLUGIN STORES A SURVEY — all custom tables, prefix
 * $wpdb->prefix . 'ayssurvey_' (survey-maker.php:40), created in
 * includes/class-survey-maker-activator.php:
 *
 *   surveys                id, title, question_ids, section_ids, status,
 *                          trash_status, custom_post_id          (activator:54-73)
 *   questions              id, question, type, status, ordering   (activator:86-103)
 *   sections               id, title, ordering                    (activator:137-145)
 *   answers                id, question_id, answer, ordering      (activator:178-186)
 *   submissions            one per response: user_id, user_ip,
 *                          user_name, user_email, dates, duration (activator:198-216)
 *   submissions_questions  one per answered question, carrying
 *                          survey_id, answer_id and user_answer   (activator:228-243)
 *   settings               meta_key / meta_value, JSON             (activator:254-262)
 *
 * surveys.question_ids and surveys.section_ids are comma-joined id lists rather
 * than foreign keys, which is why reading one survey is several queries and not
 * one join.
 *
 * CAPABILITY. The plugin's own results screen — Submissions — is registered with
 * $this->capability (admin/class-survey-maker-admin.php:653-663), and
 * survey_maker_capabilities() returns 'manage_options' on its first line
 * (admin/class-survey-maker-admin.php:798-800). The role-based branch under that
 * return, which would have granted 'read' to roles listed in the user_roles
 * setting, is commented out — it is dead code, and the user_roles row in the
 * settings table is written but never consulted. So the real gate on every screen
 * in this plugin, results included, is manage_options, and that is the gate here.
 * It is not edit_posts and it never was.
 *
 * PERSONAL DATA. submissions.user_ip, user_name and user_email hold a
 * respondent's identity (activator:203-206), and so does the ANSWER to a
 * question of type email, name or phone (the type list is in
 * admin/partials/surveys/actions/survey-maker-surveys-actions-options.php:493-506).
 * None of those leave here, at any nesting depth. A response reports whether it
 * belongs to a logged-in account as a flag; the account's id does not travel.
 * Every response-level read goes through an allow-list of fields, drops a value
 * that is not scalar rather than casting it, and is bounded.
 *
 * CREDENTIALS. The settings table holds one row per integration — mailchimp,
 * monitor, slack, active_camp, zapier (activator:840-851) — and the MailChimp row
 * is read as JSON with an apiKey member (class-survey-maker-integrations.php:195-198).
 * Those rows are reported as a name and whether they are set, never as a value,
 * and the general options row is filtered for key-shaped member names on the way
 * out. On this build the reCAPTCHA fields are an upsell mock-up with no name
 * attribute and nothing behind them (class-survey-maker-integrations.php:1889-1902),
 * but a key that arrives later must not start leaking on the strength of that, so
 * the filter is by shape and not by a list of known keys.
 */

/** The gate on every screen this plugin registers, results included. */
const NIBWP_SURVEY_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_SURVEY_MAX_ROWS = 200;

/** How far back a trend may reach, in days. Bounds the bucket count too. */
const NIBWP_SURVEY_MAX_DAYS = 180;

/** The longest title that may be stored. */
const NIBWP_SURVEY_MAX_TITLE = 300;

/**
 * Is Survey Maker actually loaded?
 *
 * Both constants are defined by the plugin's bootstrap file before it requires
 * anything (survey-maker.php:38-41), so between them they distinguish "not
 * installed" from "installed and half-loaded" — and the second one is the table
 * prefix every query below depends on, so there is no point proceeding without it.
 */
function nibwp_survey_guard(): ?WP_Error
{
    if (!defined('SURVEY_MAKER_VERSION') || !defined('SURVEY_MAKER_DB_PREFIX')) {
        return new WP_Error(
            'nibwp_survey_missing',
            __('Survey Maker is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_survey_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_survey_' . $code, $message, ['status' => $status]);
}

/**
 * The capability gate, applied to reads as well as writes.
 *
 * Reading is not a lower bar here than writing: the aggregate is harmless but it
 * is computed from rows that name people, and the plugin asks for manage_options
 * to show the same numbers in wp-admin.
 */
function nibwp_survey_require(string $what): ?WP_Error
{
    if (current_user_can(NIBWP_SURVEY_CAP)) {
        return null;
    }

    return new WP_Error(
        'nibwp_survey_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_SURVEY_CAP
        ),
        ['status' => 403]
    );
}

/**
 * One of this plugin's tables, by name.
 *
 * The name comes from this function's own list and never from input, so no
 * identifier is ever interpolated from something a caller sent.
 */
function nibwp_survey_table(string $name): string
{
    global $wpdb;

    $known = [
        'surveys',
        'questions',
        'sections',
        'answers',
        'submissions',
        'submissions_questions',
        'settings',
    ];

    if (!in_array($name, $known, true)) {
        // Unreachable from input. If it ever fires, it is a typo in this file,
        // and failing loudly beats building a query against a guessed table.
        throw new \InvalidArgumentException('Unknown Survey Maker table: ' . $name);
    }

    $prefix = defined('SURVEY_MAKER_DB_PREFIX') ? (string) constant('SURVEY_MAKER_DB_PREFIX') : 'ayssurvey_';

    return $wpdb->prefix . $prefix . $name;
}

/** The database handle, or a refusal. */
function nibwp_survey_db(): object
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
        return nibwp_survey_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    return $wpdb;
}

/** Clamp a row count that arrived over the wire. */
function nibwp_survey_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_SURVEY_MAX_ROWS, $limit));
}

/** Clamp a window that arrived over the wire. */
function nibwp_survey_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_SURVEY_MAX_DAYS, $days));
}

/** A survey id from input, or a refusal. */
function nibwp_survey_id(mixed $raw): int|WP_Error
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_survey_err('bad_id', __('Give the survey as survey_id, a positive integer.', 'nibwp'), 422);
    }

    return $id;
}

/** A percentage of a whole, or null when there is no whole to be part of. */
function nibwp_survey_percent(int $part, int $whole): ?float
{
    if ($whole <= 0) {
        return null;
    }

    return round(($part / $whole) * 100, 1);
}

/**
 * Project a row through an allow-list.
 *
 * A value that is not scalar is DROPPED rather than cast: the options columns hold
 * JSON, and (string) on an array yields "Array" while (string) on an object either
 * throws or runs __toString — both of which turn "I do not know what this is" into
 * something that looks like data.
 *
 * @param array<string, mixed> $row
 * @param array<string, string> $allowed field => bool|int|float|string
 * @return array<string, mixed>
 */
function nibwp_survey_pick(array $row, array $allowed): array
{
    $out = [];

    foreach ($allowed as $key => $type) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];

        if ($value === null) {
            $out[$key] = null;
            continue;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $out[$key] = match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            default => (string) $value,
        };
    }

    return $out;
}

/**
 * Question types whose answer IS an identity.
 *
 * An answer to one of these is a name, a phone number or an email address typed
 * by the respondent, so it is withheld exactly like the submission's own email
 * column. The slugs come from the plugin's own type table
 * (survey-maker-surveys-actions-options.php:493-506).
 *
 * @return array<int, string>
 */
function nibwp_survey_identity_types(): array
{
    return ['email', 'name', 'phone'];
}

/**
 * Question types that cannot work without stored answer options.
 *
 * Deliberately short: a star rating and a yes/no build their own choices, so an
 * empty answers table for one of those is normal and flagging it would teach an
 * owner to ignore the audit.
 *
 * @return array<int, string>
 */
function nibwp_survey_choice_types(): array
{
    return ['radio', 'checkbox', 'select'];
}

/**
 * Member names inside a settings value that must never travel.
 *
 * By shape rather than by a list of known keys: the settings row for an
 * integration is whatever JSON that integration wrote, a later version adds
 * members nobody here has heard of, and a denylist of names would default to
 * leaking each new one.
 */
function nibwp_survey_is_secret_name(string $key): bool
{
    return preg_match('/(secret|password|passwd|api[_-]?key|access[_-]?token|token|private[_-]?key|\bkey\b|_key$)/i', $key) === 1;
}

/**
 * Settings rows whose VALUE may be reported.
 *
 * The other rows the plugin creates — mailchimp, monitor, slack, active_camp,
 * zapier, user_roles, survey_default_options (activator:840-851) — are reported as
 * a name and whether they are set. An allow-list because the credential rows are
 * free-form JSON written by whichever integration owns them.
 *
 * @return array<int, string>
 */
function nibwp_survey_public_settings(): array
{
    return ['options', 'default_texts', 'buttons_texts'];
}

/**
 * Settings rows known to carry an integration's credentials.
 *
 * Listed so an owner can see that MailChimp IS configured without being handed
 * the key that configures it.
 *
 * @return array<int, string>
 */
function nibwp_survey_credential_settings(): array
{
    return ['mailchimp', 'monitor', 'slack', 'active_camp', 'zapier'];
}

/**
 * Read one settings row, decoded.
 *
 * @return array<string, mixed>
 */
function nibwp_survey_setting(string $meta_key): array
{
    $wpdb = nibwp_survey_db();

    if ($wpdb instanceof WP_Error) {
        return [];
    }

    $value = $wpdb->get_var($wpdb->prepare(
        'SELECT meta_value FROM ' . nibwp_survey_table('settings') . ' WHERE meta_key = %s LIMIT %d',
        $meta_key,
        1
    ));

    if (!is_string($value) || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Strip the key-shaped members out of a settings value before it travels.
 *
 * @param array<string, mixed> $value
 * @return array<int, array<string, mixed>>
 */
function nibwp_survey_safe_setting_members(array $value): array
{
    $rows = [];

    foreach ($value as $key => $member) {
        $key = (string) $key;

        if (nibwp_survey_is_secret_name($key)) {
            $rows[] = [
                'key' => $key,
                'is_set' => $member !== '' && $member !== null && $member !== false,
                'value_returned' => false,
            ];
            continue;
        }

        if (!is_scalar($member) && $member !== null) {
            // Nested structure. Reporting that it is there is honest; walking into
            // it would need the same filter again at every depth, and no setting
            // this hub reports is nested.
            $rows[] = ['key' => $key, 'is_set' => true, 'value_returned' => false];
            continue;
        }

        $rows[] = ['key' => $key, 'value' => $member];
    }

    return $rows;
}

/**
 * Load one survey row.
 *
 * Declared `object` rather than `object|WP_Error` on purpose: the union is a parse
 * error on the runtime this ships to. WP_Error is an object, so callers check
 * `instanceof`.
 */
function nibwp_survey_row(int $survey_id): object
{
    $wpdb = nibwp_survey_db();

    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, author_id, title, description, question_ids, section_ids, sections_count,'
        . ' questions_count, date_created, date_modified, status, trash_status, post_id, custom_post_id'
        . ' FROM ' . nibwp_survey_table('surveys') . ' WHERE id = %d LIMIT %d',
        $survey_id,
        1
    ), ARRAY_A);

    if (!is_array($row) || $row === []) {
        return nibwp_survey_err(
            'not_found',
            sprintf(
                /* translators: %d: survey id */
                __('There is no survey with id %d.', 'nibwp'),
                $survey_id
            ),
            404
        );
    }

    return (object) $row;
}

/**
 * Object-level authorization for one survey.
 *
 * Two separate questions, and they refuse with different codes so a caller can
 * tell them apart: does this survey exist, and may this account edit the post
 * that carries it. A survey is published through a post of type ays-survey-maker
 * (class-survey-maker-custom-post-type.php:33-56), so when there is one, the
 * question is edit_post on THAT post and not a blanket capability.
 */
function nibwp_survey_authorize(int $survey_id, string $what): object
{
    $survey = nibwp_survey_row($survey_id);

    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $post_id = isset($survey->custom_post_id) ? (int) $survey->custom_post_id : 0;

    if ($post_id > 0 && get_post_type($post_id) === 'ays-survey-maker' && !current_user_can('edit_post', $post_id)) {
        return new WP_Error(
            'nibwp_survey_forbidden_survey',
            sprintf(
                /* translators: 1: what was being attempted, 2: survey title */
                __('%1$s is not allowed: this account may not edit the post that publishes "%2$s".', 'nibwp'),
                $what,
                (string) ($survey->title ?? '')
            ),
            ['status' => 403]
        );
    }

    return $survey;
}

/** Comma-joined id list from a surveys row, as integers. @return array<int, int> */
function nibwp_survey_ids(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $ids = [];

    foreach (explode(',', $raw) as $piece) {
        $id = (int) trim($piece);

        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * A placeholder list for an IN clause.
 *
 * The format string is built from a count, never from the values, so prepare()
 * still binds every one of them.
 */
function nibwp_survey_placeholders(int $count): string
{
    return implode(',', array_fill(0, max(1, $count), '%d'));
}

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_survey_actions(): array
{
    return [
        // Where to start.
        'overview',
        // Surveys.
        'list_surveys',
        'get_survey',
        'list_questions',
        'create_survey',
        'rename_survey',
        'trash_survey',
        'restore_survey',
        'delete_survey',
        // Settings.
        'get_settings',
        'response_privacy',
        // Results, in aggregate.
        'survey_results',
        'question_results',
        'response_totals',
        'survey_stats',
        // Responses, one at a time, bounded.
        'list_responses',
        'get_response',
        // Audit.
        'audit',
    ];
}

wp_register_ability('nibwp/survey-maker-manage', [
    'label' => __('Survey Maker – Surveys and Results', 'nibwp'),
    'description' => 'Read and author Survey Maker surveys: list them, read one with its sections, questions and answer options, create one, rename one, trash, restore or delete one. '
        . 'Results come back in aggregate — per-question answer counts and percentages, response totals day by day over a period, and per-survey statistics. '
        . 'An audit names surveys that are published but have never been answered, surveys with no questions, and choice questions with no answer options. '
        . 'Deleting a survey also deletes every response anybody ever gave it, and its questions, sections and answer options, permanently. '
        . 'A respondent\'s IP address, name and email address are never returned, and neither is the answer to a question that asks for an email address, a name or a phone number. '
        . 'Integration credentials in the plugin\'s settings — a MailChimp API key, a captcha secret — are reported as a name and whether they are set, never as a value.',
    'category' => 'forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_survey_actions(),
                'description' => 'Which operation to run. Start with overview.',
            ],
            'survey_id' => ['type' => 'integer', 'description' => 'Which survey. Required by everything that names one.'],
            'question_id' => ['type' => 'integer', 'description' => 'For question_results: which question.'],
            'response_id' => ['type' => 'integer', 'description' => 'For get_response: which response. It must belong to survey_id.'],
            'title' => ['type' => 'string', 'description' => 'For create_survey / rename_survey. Up to 300 characters.'],
            'description' => ['type' => 'string', 'description' => 'For create_survey: the survey description.'],
            'status' => ['type' => 'string', 'description' => 'For list_surveys: published, trashed or any. Default published.'],
            'days' => ['type' => 'integer', 'description' => 'For response_totals: how many days back. Default 30, max 180.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
            'confirm' => ['type' => 'boolean', 'description' => 'For delete_survey: must be true. Deleting a survey deletes its responses.'],
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
    'execute_callback' => 'nibwp_survey_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Survey Maker: surveys, and results in aggregate.',
                '',
                'START WITH overview. It says how many surveys exist, how many have ever',
                'been answered, how many responses there are in total, and whether the site',
                'is storing respondent IP addresses.',
                '',
                'RESULTS ARE AGGREGATE BY DEFAULT, and that is the useful shape:',
                '- survey_results gives every question with its answer options, how many',
                '  people chose each, and what share of those who answered that chose it.',
                '- question_results does the same for one question.',
                '- response_totals gives responses per day over a window.',
                '- survey_stats gives totals, first and last response, average time taken,',
                '  and what share of respondents were signed in.',
                'Answer the question with those. Reach for list_responses or get_response',
                'only when somebody specifically needs one person\'s answers.',
                '',
                'WHAT NEVER COMES BACK, however it is asked for:',
                'a respondent\'s IP address, name or email address; the user id of a',
                'respondent who was signed in — only a flag saying that they were; and the',
                'answer to any question of type email, name or phone, which is an identity',
                'the respondent typed rather than an opinion. Those answers come back marked',
                'withheld. Say so plainly when asked; there is no other action that returns',
                'them.',
                '',
                'FREE TEXT: survey_results reports how many people answered a free-text',
                'question and how many distinct answers there were, not the text. The text of',
                'one person\'s answer is in get_response, one response at a time.',
                '',
                'DELETING: delete_survey is permanent and it takes every response with it,',
                'along with the survey\'s questions, sections and answer options. It needs',
                'confirm:true. trash_survey is almost always what is wanted instead — it',
                'hides the survey and its responses and restore_survey puts them back.',
                '',
                'CREDENTIALS: get_settings reports the plugin\'s own settings, but an',
                'integration credential — a MailChimp API key, a captcha secret — comes back',
                'as a name and whether it is set. Never as a value.',
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
function nibwp_survey_manage(array $input): array|WP_Error
{
    // Before anything else, including before the action name is judged: with the
    // plugin absent there is no table to query and no class to call, and every
    // answer should say that one thing rather than something more specific.
    $guard = nibwp_survey_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_survey_actions(), true)) {
        return nibwp_survey_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Survey Maker action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_survey_actions())
            )
        );
    }

    // The plugin's own classes are version-sensitive, and a malformed id list in a
    // surveys row can make a query come back as something nothing here expects.
    // One catch turns all of that into a named refusal rather than a fatal that
    // takes the whole REST request down.
    try {
        return nibwp_survey_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_survey_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message */
                __('Survey Maker could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_survey_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'overview' => nibwp_survey_overview(),
        'list_surveys' => nibwp_survey_list_surveys($input),
        'get_survey' => nibwp_survey_get_survey($input),
        'list_questions' => nibwp_survey_list_questions($input),
        'create_survey' => nibwp_survey_create_survey($input),
        'rename_survey' => nibwp_survey_rename_survey($input),
        'trash_survey' => nibwp_survey_trash_survey($input),
        'restore_survey' => nibwp_survey_restore_survey($input),
        'delete_survey' => nibwp_survey_delete_survey($input),
        'get_settings' => nibwp_survey_get_settings(),
        'response_privacy' => nibwp_survey_response_privacy(),
        'survey_results' => nibwp_survey_survey_results($input),
        'question_results' => nibwp_survey_question_results($input),
        'response_totals' => nibwp_survey_response_totals($input),
        'survey_stats' => nibwp_survey_survey_stats($input),
        'list_responses' => nibwp_survey_list_responses($input),
        'get_response' => nibwp_survey_get_response($input),
        'audit' => nibwp_survey_audit($input),
        default => nibwp_survey_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * The shape of the site's survey data, in one response.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_overview(): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading the Survey Maker overview', 'nibwp'))) {
        return $denied;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $published = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('surveys') . ' WHERE status = %s',
        'published'
    ));
    $trashed = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('surveys') . ' WHERE status = %s',
        'trashed'
    ));
    $responses = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('submissions') . ' WHERE status = %s',
        'published'
    ));
    $answered = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(DISTINCT survey_id) FROM ' . nibwp_survey_table('submissions') . ' WHERE status = %s',
        'published'
    ));
    $questions = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('questions') . ' WHERE status = %s',
        'published'
    ));

    $privacy = nibwp_survey_privacy_flags();

    return [
        'surveys' => ['published' => $published, 'trashed' => $trashed, 'ever_answered' => $answered],
        'questions' => $questions,
        'responses' => $responses,
        'never_answered' => max(0, $published - $answered),
        'storing_ip_addresses' => $privacy['stores_ip'],
        'note' => __('Results are reported in aggregate. Use survey_results for per-question counts and percentages, and response_totals for a trend.', 'nibwp'),
    ];
}

/**
 * The surveys, with how many responses each has.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_list_surveys(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Listing Survey Maker surveys', 'nibwp'))) {
        return $denied;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $wanted = isset($input['status']) ? strtolower(trim((string) $input['status'])) : 'published';

    if (!in_array($wanted, ['published', 'trashed', 'any'], true)) {
        return nibwp_survey_err('bad_status', __('status must be published, trashed or any.', 'nibwp'), 422);
    }

    $limit = nibwp_survey_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $surveys = nibwp_survey_table('surveys');
    $submissions = nibwp_survey_table('submissions');

    // One LEFT JOIN rather than a count per survey: a site with a few hundred
    // surveys would otherwise be a few hundred queries behind one listing.
    if ($wanted === 'any') {
        $sql = $wpdb->prepare(
            'SELECT s.id, s.title, s.status, s.questions_count, s.sections_count, s.date_created,'
            . ' s.date_modified, s.custom_post_id, COUNT(sub.id) AS responses'
            . ' FROM ' . $surveys . ' AS s'
            . ' LEFT JOIN ' . $submissions . ' AS sub ON sub.survey_id = s.id AND sub.status = %s'
            . ' GROUP BY s.id ORDER BY s.id DESC LIMIT %d OFFSET %d',
            'published',
            $limit,
            $offset
        );
    } else {
        $sql = $wpdb->prepare(
            'SELECT s.id, s.title, s.status, s.questions_count, s.sections_count, s.date_created,'
            . ' s.date_modified, s.custom_post_id, COUNT(sub.id) AS responses'
            . ' FROM ' . $surveys . ' AS s'
            . ' LEFT JOIN ' . $submissions . ' AS sub ON sub.survey_id = s.id AND sub.status = %s'
            . ' WHERE s.status = %s'
            . ' GROUP BY s.id ORDER BY s.id DESC LIMIT %d OFFSET %d',
            'published',
            $wanted,
            $limit,
            $offset
        );
    }

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $out = [];

    foreach ($rows as $row) {
        $row = (array) $row;
        $survey = nibwp_survey_pick($row, [
            'id' => 'int',
            'title' => 'string',
            'status' => 'string',
            'questions_count' => 'int',
            'sections_count' => 'int',
            'date_created' => 'string',
            'date_modified' => 'string',
            'responses' => 'int',
        ]);
        $survey['shortcode'] = '[ays_survey id="' . (int) ($row['id'] ?? 0) . '"]';
        $out[] = $survey;
    }

    return [
        'status_filter' => $wanted,
        'returned' => count($out),
        'surveys' => $out,
        'limit' => $limit,
        'note' => __('The response count is published responses only; a trashed survey has its responses marked trashed too.', 'nibwp'),
    ];
}

/**
 * One survey, with its sections, questions and answer options.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_get_survey(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading a Survey Maker survey', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_row($survey_id);
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $question_ids = nibwp_survey_ids($survey->question_ids ?? '');
    $section_ids = nibwp_survey_ids($survey->section_ids ?? '');

    $sections = [];

    if ($section_ids !== []) {
        $section_ids = array_slice($section_ids, 0, NIBWP_SURVEY_MAX_ROWS);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, description, ordering FROM ' . nibwp_survey_table('sections')
            . ' WHERE id IN (' . nibwp_survey_placeholders(count($section_ids)) . ')'
            . ' ORDER BY ordering ASC LIMIT %d',
            array_merge($section_ids, [NIBWP_SURVEY_MAX_ROWS])
        ), ARRAY_A);

        foreach ($rows as $row) {
            $sections[] = nibwp_survey_pick((array) $row, [
                'id' => 'int',
                'title' => 'string',
                'description' => 'string',
                'ordering' => 'int',
            ]);
        }
    }

    $questions = nibwp_survey_questions_with_options($question_ids);

    if ($questions instanceof WP_Error) {
        return $questions;
    }

    return [
        'survey' => nibwp_survey_pick((array) $survey, [
            'id' => 'int',
            'title' => 'string',
            'description' => 'string',
            'status' => 'string',
            'questions_count' => 'int',
            'sections_count' => 'int',
            'date_created' => 'string',
            'date_modified' => 'string',
        ]),
        'shortcode' => '[ays_survey id="' . $survey_id . '"]',
        'sections' => $sections,
        'questions' => $questions,
        'note' => __('Questions are listed in the order the survey stores them. A question of type email, name or phone asks the respondent for an identity — its answers are never returned by this hub.', 'nibwp'),
    ];
}

/**
 * Questions and their answer options, by id.
 *
 * @param array<int, int> $question_ids
 * @return array<int, array<string, mixed>>|WP_Error
 */
function nibwp_survey_questions_with_options(array $question_ids): array|WP_Error
{
    if ($question_ids === []) {
        return [];
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $question_ids = array_slice($question_ids, 0, NIBWP_SURVEY_MAX_ROWS);
    $placeholders = nibwp_survey_placeholders(count($question_ids));

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT id, question, type, status, ordering, section_id FROM ' . nibwp_survey_table('questions')
        . ' WHERE id IN (' . $placeholders . ') ORDER BY ordering ASC, id ASC LIMIT %d',
        array_merge($question_ids, [NIBWP_SURVEY_MAX_ROWS])
    ), ARRAY_A);

    $answers = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT id, question_id, answer, ordering FROM ' . nibwp_survey_table('answers')
        . ' WHERE question_id IN (' . $placeholders . ') ORDER BY ordering ASC, id ASC LIMIT %d',
        array_merge($question_ids, [NIBWP_SURVEY_MAX_ROWS * 10])
    ), ARRAY_A);

    $by_question = [];

    foreach ($answers as $answer) {
        $answer = (array) $answer;
        $by_question[(int) ($answer['question_id'] ?? 0)][] = nibwp_survey_pick($answer, [
            'id' => 'int',
            'answer' => 'string',
            'ordering' => 'int',
        ]);
    }

    $out = [];

    foreach ($rows as $row) {
        $row = (array) $row;
        $id = (int) ($row['id'] ?? 0);
        $question = nibwp_survey_pick($row, [
            'id' => 'int',
            'question' => 'string',
            'type' => 'string',
            'status' => 'string',
            'ordering' => 'int',
            'section_id' => 'int',
        ]);
        $question['answer_options'] = $by_question[$id] ?? [];
        $question['asks_for_identity'] = in_array((string) ($row['type'] ?? ''), nibwp_survey_identity_types(), true);
        $out[] = $question;
    }

    return $out;
}

/**
 * Questions across the site, or one survey's.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_list_questions(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Listing Survey Maker questions', 'nibwp'))) {
        return $denied;
    }

    if (isset($input['survey_id'])) {
        $survey_id = nibwp_survey_id($input['survey_id']);
        if ($survey_id instanceof WP_Error) {
            return $survey_id;
        }

        $survey = nibwp_survey_row($survey_id);
        if ($survey instanceof WP_Error) {
            return $survey;
        }

        $questions = nibwp_survey_questions_with_options(nibwp_survey_ids($survey->question_ids ?? ''));

        if ($questions instanceof WP_Error) {
            return $questions;
        }

        return [
            'survey_id' => $survey_id,
            'returned' => count($questions),
            'questions' => $questions,
        ];
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $limit = nibwp_survey_limit($input['limit'] ?? null);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT q.id, q.question, q.type, q.status, q.section_id, COUNT(a.id) AS answer_options'
        . ' FROM ' . nibwp_survey_table('questions') . ' AS q'
        . ' LEFT JOIN ' . nibwp_survey_table('answers') . ' AS a ON a.question_id = q.id'
        . ' WHERE q.status = %s GROUP BY q.id ORDER BY q.id DESC LIMIT %d OFFSET %d',
        'published',
        $limit,
        $offset
    ), ARRAY_A);

    $out = [];

    foreach ($rows as $row) {
        $row = (array) $row;
        $question = nibwp_survey_pick($row, [
            'id' => 'int',
            'question' => 'string',
            'type' => 'string',
            'status' => 'string',
            'section_id' => 'int',
            'answer_options' => 'int',
        ]);
        $question['asks_for_identity'] = in_array((string) ($row['type'] ?? ''), nibwp_survey_identity_types(), true);
        $out[] = $question;
    }

    return ['returned' => count($out), 'questions' => $out, 'limit' => $limit];
}

/* ----------------------------------------------------------------- settings */

/**
 * Whether the site is storing respondent identities at all.
 *
 * @return array<string, bool>
 */
function nibwp_survey_privacy_flags(): array
{
    $options = nibwp_survey_setting('options');

    // The settings screen writes 'on' when the box is ticked and '' or 'off' when
    // it is not (survey-maker-settings-actions.php:33-39), and the box reads "Do
    // not store...", so ticked means NOT stored.
    $off = static fn (mixed $value): bool => $value === 'on';

    return [
        'stores_ip' => !$off($options['survey_disable_user_ip'] ?? ''),
        'stores_name' => !$off($options['survey_disable_user_name'] ?? ''),
        'stores_email' => !$off($options['survey_disable_user_email'] ?? ''),
    ];
}

/**
 * What the plugin keeps about the people who answer.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_response_privacy(): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading the Survey Maker privacy settings', 'nibwp'))) {
        return $denied;
    }

    $flags = nibwp_survey_privacy_flags();
    $wpdb = nibwp_survey_db();

    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    // How many stored responses actually carry an address, which is the number
    // that matters when somebody asks what this site holds — the setting only
    // governs what happens from now on.
    $with_ip = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('submissions') . ' WHERE user_ip != %s',
        ''
    ));
    $with_email = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('submissions') . ' WHERE user_email != %s',
        ''
    ));
    $total = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('submissions') . ' WHERE id > %d',
        0
    ));

    $act_on = [];

    if ($flags['stores_ip'] && $with_ip > 0) {
        $act_on[] = __('This site stores the IP address of everyone who answers a survey. The General Settings screen has a switch to stop that.', 'nibwp');
    }
    if ($flags['stores_email'] && $with_email > 0) {
        $act_on[] = __('Stored responses carry email addresses. If the surveys do not need them, the General Settings screen has a switch to stop storing them.', 'nibwp');
    }

    return [
        'settings' => $flags,
        'stored_responses' => $total,
        'responses_carrying_an_ip_address' => $with_ip,
        'responses_carrying_an_email_address' => $with_email,
        'act_on' => $act_on,
        'values_returned' => false,
        'note' => __('Counts only. No address and no email from any response is returned by this hub, in this action or any other.', 'nibwp'),
    ];
}

/**
 * The plugin's settings, with credentials withheld.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_get_settings(): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading the Survey Maker settings', 'nibwp'))) {
        return $denied;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT meta_key, meta_value FROM ' . nibwp_survey_table('settings') . ' WHERE id > %d ORDER BY id ASC LIMIT %d',
        0,
        NIBWP_SURVEY_MAX_ROWS
    ), ARRAY_A);

    $public = [];
    $withheld = [];
    $allowed = nibwp_survey_public_settings();
    $credentials = nibwp_survey_credential_settings();

    foreach ($rows as $row) {
        $row = (array) $row;
        $key = isset($row['meta_key']) && is_scalar($row['meta_key']) ? (string) $row['meta_key'] : '';
        $raw = isset($row['meta_value']) && is_scalar($row['meta_value']) ? (string) $row['meta_value'] : '';

        if ($key === '') {
            continue;
        }

        $is_set = trim($raw) !== '' && trim($raw) !== '[]' && trim($raw) !== '{}';

        if (!in_array($key, $allowed, true)) {
            $withheld[] = [
                'key' => $key,
                'is_set' => $is_set,
                'holds_credentials' => in_array($key, $credentials, true),
                'value_returned' => false,
            ];
            continue;
        }

        $decoded = json_decode($raw, true);
        $public[] = [
            'key' => $key,
            'members' => is_array($decoded) ? nibwp_survey_safe_setting_members($decoded) : [],
        ];
    }

    return [
        'settings' => $public,
        'withheld' => $withheld,
        'privacy' => nibwp_survey_privacy_flags(),
        'note' => __('Only the plugin\'s own option rows report values, and a member whose name reads like a key, a token or a secret is reported as set or not set rather than as a value. Everything else, including every integration credential row, is a name and whether it is set.', 'nibwp'),
    ];
}

/* ------------------------------------------------------ results, aggregated */

/**
 * Per-question answer counts and percentages for one survey.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_survey_results(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading Survey Maker results', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_row($survey_id);
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $question_ids = nibwp_survey_ids($survey->question_ids ?? '');
    $questions = nibwp_survey_questions_with_options($question_ids);

    if ($questions instanceof WP_Error) {
        return $questions;
    }

    $counts = nibwp_survey_answer_counts($survey_id);
    if ($counts instanceof WP_Error) {
        return $counts;
    }

    $answered = nibwp_survey_answered_counts($survey_id);
    if ($answered instanceof WP_Error) {
        return $answered;
    }

    $responses = nibwp_survey_response_count($survey_id);
    if ($responses instanceof WP_Error) {
        return $responses;
    }

    $out = [];

    foreach ($questions as $question) {
        $out[] = nibwp_survey_question_report($question, $counts, $answered, $responses);
    }

    return [
        'survey_id' => $survey_id,
        'survey_title' => (string) ($survey->title ?? ''),
        'responses' => $responses,
        'questions' => $out,
        'percent_denominator' => 'people who answered that question',
        'note' => __('Counts and percentages only. A free-text question reports how many people answered and how many distinct answers there were, never the text; an identity question reports neither.', 'nibwp'),
    ];
}

/**
 * One question's answer counts and percentages.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_question_results(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading Survey Maker question results', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_row($survey_id);
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $question_id = is_numeric($input['question_id'] ?? null) ? (int) $input['question_id'] : 0;

    if ($question_id <= 0) {
        return nibwp_survey_err('bad_question_id', __('Give the question as question_id, a positive integer.', 'nibwp'), 422);
    }

    // The question has to belong to THIS survey. Without that, question_id is a
    // way to read a question out of a survey the caller did not name.
    if (!in_array($question_id, nibwp_survey_ids($survey->question_ids ?? ''), true)) {
        return nibwp_survey_err(
            'question_not_in_survey',
            sprintf(
                /* translators: 1: question id, 2: survey id */
                __('Question %1$d is not part of survey %2$d.', 'nibwp'),
                $question_id,
                $survey_id
            ),
            404
        );
    }

    $questions = nibwp_survey_questions_with_options([$question_id]);
    if ($questions instanceof WP_Error) {
        return $questions;
    }

    if ($questions === []) {
        return nibwp_survey_err(
            'question_not_found',
            sprintf(
                /* translators: %d: question id */
                __('There is no question with id %d.', 'nibwp'),
                $question_id
            ),
            404
        );
    }

    $counts = nibwp_survey_answer_counts($survey_id);
    if ($counts instanceof WP_Error) {
        return $counts;
    }

    $answered = nibwp_survey_answered_counts($survey_id);
    if ($answered instanceof WP_Error) {
        return $answered;
    }

    $responses = nibwp_survey_response_count($survey_id);
    if ($responses instanceof WP_Error) {
        return $responses;
    }

    return [
        'survey_id' => $survey_id,
        'responses' => $responses,
        'question' => nibwp_survey_question_report($questions[0], $counts, $answered, $responses),
        'percent_denominator' => 'people who answered that question',
    ];
}

/**
 * One question's row in a results report.
 *
 * @param array<string, mixed> $question
 * @param array<int, int> $counts answer option id => times chosen
 * @param array<int, array<string, int>> $answered question id => answered / distinct
 * @return array<string, mixed>
 */
function nibwp_survey_question_report(array $question, array $counts, array $answered, int $responses): array
{
    $id = (int) ($question['id'] ?? 0);
    $type = (string) ($question['type'] ?? '');
    $stats = $answered[$id] ?? ['answered' => 0, 'distinct' => 0];
    $identity = in_array($type, nibwp_survey_identity_types(), true);

    $report = [
        'id' => $id,
        'question' => (string) ($question['question'] ?? ''),
        'type' => $type,
        'answered' => (int) $stats['answered'],
        'skipped' => max(0, $responses - (int) $stats['answered']),
        'response_rate_percent' => nibwp_survey_percent((int) $stats['answered'], $responses),
    ];

    $options = [];

    foreach ((array) ($question['answer_options'] ?? []) as $option) {
        $option = (array) $option;
        $option_id = (int) ($option['id'] ?? 0);
        $chose = (int) ($counts[$option_id] ?? 0);
        $options[] = [
            'id' => $option_id,
            'answer' => (string) ($option['answer'] ?? ''),
            'chose' => $chose,
            'percent' => nibwp_survey_percent($chose, (int) $stats['answered']),
        ];
    }

    if ($options !== []) {
        $report['answer_options'] = $options;

        return $report;
    }

    // No stored options, so the answers are whatever the respondent typed.
    if ($identity) {
        $report['free_text'] = false;
        $report['answers_withheld'] = true;
        $report['withheld_reason'] = __('This question asks the respondent for a name, a phone number or an email address, so its answers are an identity rather than an opinion. They are not returned, counted or sampled here.', 'nibwp');

        return $report;
    }

    $report['free_text'] = true;
    $report['distinct_answers'] = (int) $stats['distinct'];
    $report['text_returned'] = false;
    $report['note'] = __('A free-text question. How many people answered and how many gave a distinct answer, not what any of them wrote.', 'nibwp');

    return $report;
}

/**
 * How many times each answer option was chosen, across one survey.
 *
 * Two queries, because the plugin stores a choice two different ways: a single
 * choice lands in submissions_questions.answer_id, and a checkbox question's
 * selections land as a comma set in user_answer with answer_id left at 0 — which
 * is why the plugin's own summary joins with FIND_IN_SET for that type and a
 * plain equality for the rest (class-survey-maker-data.php:1570-1583). Counting
 * only answer_id would silently report every checkbox option as zero.
 *
 * @return array<int, int>|WP_Error
 */
function nibwp_survey_answer_counts(int $survey_id): array|WP_Error
{
    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $counts = [];

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT answer_id, COUNT(*) AS hits FROM ' . nibwp_survey_table('submissions_questions')
        . ' WHERE survey_id = %d AND answer_id > %d AND type != %s'
        . ' GROUP BY answer_id ORDER BY hits DESC LIMIT %d',
        $survey_id,
        0,
        'checkbox',
        NIBWP_SURVEY_MAX_ROWS * 10
    ), ARRAY_A);

    foreach ($rows as $row) {
        $row = (array) $row;
        $counts[(int) ($row['answer_id'] ?? 0)] = (int) ($row['hits'] ?? 0);
    }

    // Restricted to checkbox rows on purpose: user_answer holds free text for the
    // text types, and FIND_IN_SET against that would count a respondent who
    // happened to type a number as having chosen the option with that id.
    $boxes = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT a.id AS answer_id, COUNT(sq.id) AS hits'
        . ' FROM ' . nibwp_survey_table('answers') . ' AS a'
        . ' INNER JOIN ' . nibwp_survey_table('submissions_questions') . ' AS sq'
        . ' ON (sq.answer_id = a.id OR FIND_IN_SET(a.id, sq.user_answer))'
        . ' WHERE sq.survey_id = %d AND sq.type = %s'
        . ' GROUP BY a.id ORDER BY hits DESC LIMIT %d',
        $survey_id,
        'checkbox',
        NIBWP_SURVEY_MAX_ROWS * 10
    ), ARRAY_A);

    foreach ($boxes as $row) {
        $row = (array) $row;
        $counts[(int) ($row['answer_id'] ?? 0)] = (int) ($row['hits'] ?? 0);
    }

    return $counts;
}

/**
 * How many people answered each question, and how many distinct answers there were.
 *
 * @return array<int, array<string, int>>|WP_Error
 */
function nibwp_survey_answered_counts(int $survey_id): array|WP_Error
{
    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT question_id, COUNT(DISTINCT submission_id) AS answered,'
        . ' COUNT(DISTINCT CASE WHEN user_answer != %s THEN user_answer ELSE NULL END) AS distinct_answers'
        . ' FROM ' . nibwp_survey_table('submissions_questions')
        . ' WHERE survey_id = %d GROUP BY question_id LIMIT %d',
        '',
        $survey_id,
        NIBWP_SURVEY_MAX_ROWS * 10
    ), ARRAY_A);

    $out = [];

    foreach ($rows as $row) {
        $row = (array) $row;
        $out[(int) ($row['question_id'] ?? 0)] = [
            'answered' => (int) ($row['answered'] ?? 0),
            'distinct' => (int) ($row['distinct_answers'] ?? 0),
        ];
    }

    return $out;
}

/** How many published responses one survey has. */
function nibwp_survey_response_count(int $survey_id): int|WP_Error
{
    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . nibwp_survey_table('submissions') . ' WHERE survey_id = %d AND status = %s',
        $survey_id,
        'published'
    ));
}

/**
 * Responses per day over a window.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_response_totals(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading Survey Maker response totals', 'nibwp'))) {
        return $denied;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $days = nibwp_survey_days($input['days'] ?? null);
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    $survey_id = 0;
    $title = null;

    if (isset($input['survey_id'])) {
        $survey_id = nibwp_survey_id($input['survey_id']);
        if ($survey_id instanceof WP_Error) {
            return $survey_id;
        }

        $survey = nibwp_survey_row($survey_id);
        if ($survey instanceof WP_Error) {
            return $survey;
        }

        $title = (string) ($survey->title ?? '');
    }

    $table = nibwp_survey_table('submissions');

    // The bucket count is bounded by the day window, which is itself clamped, so
    // the LIMIT can never truncate a window the caller was allowed to ask for.
    if ($survey_id > 0) {
        $sql = $wpdb->prepare(
            'SELECT DATE(submission_date) AS day, COUNT(*) AS responses FROM ' . $table
            . ' WHERE survey_id = %d AND status = %s AND submission_date >= %s'
            . ' GROUP BY DATE(submission_date) ORDER BY day ASC LIMIT %d',
            $survey_id,
            'published',
            $since,
            NIBWP_SURVEY_MAX_DAYS + 1
        );
    } else {
        $sql = $wpdb->prepare(
            'SELECT DATE(submission_date) AS day, COUNT(*) AS responses FROM ' . $table
            . ' WHERE status = %s AND submission_date >= %s'
            . ' GROUP BY DATE(submission_date) ORDER BY day ASC LIMIT %d',
            'published',
            $since,
            NIBWP_SURVEY_MAX_DAYS + 1
        );
    }

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $daily = [];
    $total = 0;

    foreach ($rows as $row) {
        $row = (array) $row;
        $responses = (int) ($row['responses'] ?? 0);
        $total += $responses;
        $daily[] = [
            'day' => isset($row['day']) && is_scalar($row['day']) ? (string) $row['day'] : null,
            'responses' => $responses,
        ];
    }

    return [
        'survey_id' => $survey_id > 0 ? $survey_id : null,
        'survey_title' => $title,
        'days' => $days,
        'since' => $since,
        'total' => $total,
        'per_day_average' => $days > 0 ? round($total / $days, 2) : null,
        'daily' => $daily,
        'note' => $rows === []
            ? __('No responses in that window. The window is the submission date, so a response given before it does not appear here even though it is still stored.', 'nibwp')
            : __('Days with no responses are absent rather than zero. Dates are UTC.', 'nibwp'),
    ];
}

/**
 * One survey's response statistics.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_survey_stats(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading Survey Maker survey statistics', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_row($survey_id);
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    // Aggregates in SQL, so no row carrying an address is ever loaded into PHP.
    // signed_in counts rows whose user_id is set; the ids themselves stay in the
    // database.
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT COUNT(*) AS responses, MIN(submission_date) AS first_response,'
        . ' MAX(submission_date) AS last_response, AVG(duration) AS average_duration,'
        . ' SUM(CASE WHEN user_id > %d THEN 1 ELSE 0 END) AS signed_in,'
        . ' SUM(CASE WHEN `read` = %d THEN 1 ELSE 0 END) AS unread'
        . ' FROM ' . nibwp_survey_table('submissions')
        . ' WHERE survey_id = %d AND status = %s',
        0,
        0,
        $survey_id,
        'published'
    ), ARRAY_A);

    $row = is_array($row) ? $row : [];
    $responses = (int) ($row['responses'] ?? 0);
    $signed_in = (int) ($row['signed_in'] ?? 0);

    return [
        'survey_id' => $survey_id,
        'survey_title' => (string) ($survey->title ?? ''),
        'status' => (string) ($survey->status ?? ''),
        'questions' => (int) ($survey->questions_count ?? 0),
        'responses' => $responses,
        'first_response' => isset($row['first_response']) && is_scalar($row['first_response']) ? (string) $row['first_response'] : null,
        'last_response' => isset($row['last_response']) && is_scalar($row['last_response']) ? (string) $row['last_response'] : null,
        'average_seconds_taken' => isset($row['average_duration']) && is_numeric($row['average_duration'])
            ? round((float) $row['average_duration'], 1)
            : null,
        'from_signed_in_accounts' => $signed_in,
        'from_signed_in_percent' => nibwp_survey_percent($signed_in, $responses),
        'not_yet_read' => (int) ($row['unread'] ?? 0),
        'identities_returned' => false,
        'note' => __('Aggregates. No respondent is named, and the user id of a signed-in respondent is counted but not returned.', 'nibwp'),
    ];
}

/* ------------------------------------------------- responses, one at a time */

/**
 * One survey's responses, as metadata and nothing more.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_list_responses(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Listing Survey Maker responses', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_row($survey_id);
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $limit = nibwp_survey_limit($input['limit'] ?? null, 25);
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    // The SELECT is the allow-list: user_ip, user_name, user_email and options are
    // not named, so they never reach PHP. user_id is reduced to a flag in SQL.
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT id, survey_id, submission_date, duration, questions_count, `read`,'
        . ' CASE WHEN user_id > %d THEN 1 ELSE 0 END AS signed_in'
        . ' FROM ' . nibwp_survey_table('submissions')
        . ' WHERE survey_id = %d AND status = %s'
        . ' ORDER BY id DESC LIMIT %d OFFSET %d',
        0,
        $survey_id,
        'published',
        $limit,
        $offset
    ), ARRAY_A);

    $out = [];

    foreach ($rows as $row) {
        $row = (array) $row;
        $response = nibwp_survey_pick($row, [
            'id' => 'int',
            'survey_id' => 'int',
            'submission_date' => 'string',
            'duration' => 'int',
            'questions_count' => 'int',
            'read' => 'bool',
        ]);
        $response['belongs_to_logged_in_user'] = (bool) ($row['signed_in'] ?? 0);
        $out[] = $response;
    }

    return [
        'survey_id' => $survey_id,
        'returned' => count($out),
        'limit' => $limit,
        'max_limit' => NIBWP_SURVEY_MAX_ROWS,
        'responses' => $out,
        'identities_returned' => false,
        'note' => __('Metadata only: when it was given, how long it took, and whether the respondent was signed in. No address, no name, no email, and no account id. get_response reads one response\'s answers.', 'nibwp'),
    ];
}

/**
 * One response, through an allow-list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_get_response(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Reading one Survey Maker response', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_row($survey_id);
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $response_id = is_numeric($input['response_id'] ?? null) ? (int) $input['response_id'] : 0;

    if ($response_id <= 0) {
        return nibwp_survey_err('bad_response_id', __('Give the response as response_id, a positive integer.', 'nibwp'), 422);
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, survey_id, submission_date, start_date, end_date, duration, questions_count, `read`, status,'
        . ' CASE WHEN user_id > %d THEN 1 ELSE 0 END AS signed_in'
        . ' FROM ' . nibwp_survey_table('submissions') . ' WHERE id = %d LIMIT %d',
        0,
        $response_id,
        1
    ), ARRAY_A);

    if (!is_array($row) || $row === []) {
        return nibwp_survey_err(
            'response_not_found',
            sprintf(
                /* translators: %d: response id */
                __('There is no response with id %d.', 'nibwp'),
                $response_id
            ),
            404
        );
    }

    // Object-level: the response has to belong to the survey the caller named.
    // Without this, response_id walks the whole submissions table one id at a time
    // regardless of which survey was asked about.
    if ((int) ($row['survey_id'] ?? 0) !== $survey_id) {
        return nibwp_survey_err(
            'response_mismatch',
            sprintf(
                /* translators: 1: response id, 2: survey id */
                __('Response %1$d does not belong to survey %2$d.', 'nibwp'),
                $response_id,
                $survey_id
            ),
            404
        );
    }

    $answers = nibwp_survey_response_answers($response_id, $survey_id);

    if ($answers instanceof WP_Error) {
        return $answers;
    }

    $response = nibwp_survey_pick($row, [
        'id' => 'int',
        'survey_id' => 'int',
        'submission_date' => 'string',
        'start_date' => 'string',
        'end_date' => 'string',
        'duration' => 'int',
        'questions_count' => 'int',
        'read' => 'bool',
        'status' => 'string',
    ]);
    $response['belongs_to_logged_in_user'] = (bool) ($row['signed_in'] ?? 0);

    return [
        'survey_id' => $survey_id,
        'response' => $response,
        'answers' => $answers,
        'identities_returned' => false,
        'note' => __('This is one person\'s answers. Their IP address, name, email address and account id are not here and cannot be asked for, and an answer to a question of type email, name or phone is withheld for the same reason.', 'nibwp'),
    ];
}

/**
 * One response's answers, with the identity answers withheld.
 *
 * @return array<int, array<string, mixed>>|WP_Error
 */
function nibwp_survey_response_answers(int $response_id, int $survey_id): array|WP_Error
{
    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    // Both ids in the WHERE, not just the submission: the pair is what the caller
    // was authorised for.
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT sq.id, sq.question_id, sq.answer_id, sq.user_answer, sq.type, q.question'
        . ' FROM ' . nibwp_survey_table('submissions_questions') . ' AS sq'
        . ' LEFT JOIN ' . nibwp_survey_table('questions') . ' AS q ON q.id = sq.question_id'
        . ' WHERE sq.submission_id = %d AND sq.survey_id = %d'
        . ' ORDER BY sq.id ASC LIMIT %d',
        $response_id,
        $survey_id,
        NIBWP_SURVEY_MAX_ROWS
    ), ARRAY_A);

    $answer_ids = [];

    foreach ($rows as $row) {
        $id = (int) ((array) $row)['answer_id'];

        if ($id > 0) {
            $answer_ids[] = $id;
        }
    }

    $labels = [];

    if ($answer_ids !== []) {
        $answer_ids = array_slice(array_values(array_unique($answer_ids)), 0, NIBWP_SURVEY_MAX_ROWS);
        $chosen = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, answer FROM ' . nibwp_survey_table('answers')
            . ' WHERE id IN (' . nibwp_survey_placeholders(count($answer_ids)) . ') LIMIT %d',
            array_merge($answer_ids, [NIBWP_SURVEY_MAX_ROWS])
        ), ARRAY_A);

        foreach ($chosen as $one) {
            $one = (array) $one;
            $labels[(int) ($one['id'] ?? 0)] = isset($one['answer']) && is_scalar($one['answer'])
                ? (string) $one['answer']
                : '';
        }
    }

    $out = [];
    $identity_types = nibwp_survey_identity_types();

    foreach ($rows as $row) {
        $row = (array) $row;
        $type = isset($row['type']) && is_scalar($row['type']) ? (string) $row['type'] : '';
        $answer_id = (int) ($row['answer_id'] ?? 0);

        $entry = nibwp_survey_pick($row, [
            'question_id' => 'int',
            'question' => 'string',
            'type' => 'string',
        ]);
        $entry['answer_option_id'] = $answer_id > 0 ? $answer_id : null;

        if (in_array($type, $identity_types, true)) {
            // The answer to this question IS an identity: an email address, a name
            // or a phone number the respondent typed. It is dropped here, at the one
            // place that would otherwise return it.
            $entry['answer'] = null;
            $entry['withheld'] = true;
            $entry['withheld_reason'] = __('This question asks for a name, a phone number or an email address. Its answer is an identity and is never returned.', 'nibwp');
            $out[] = $entry;
            continue;
        }

        $typed = $row['user_answer'] ?? null;

        if ($typed !== null && !is_scalar($typed)) {
            // Dropped rather than cast: whatever this is, it is not the answer.
            $typed = null;
        }

        $entry['answer'] = $answer_id > 0 && isset($labels[$answer_id])
            ? $labels[$answer_id]
            : ($typed === null ? null : (string) $typed);
        $entry['withheld'] = false;
        $out[] = $entry;
    }

    return $out;
}

/* -------------------------------------------------------------------- audit */

/**
 * The things worth fixing, in one response.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Auditing Survey Maker surveys', 'nibwp'))) {
        return $denied;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $limit = nibwp_survey_limit($input['limit'] ?? null);

    $never = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT s.id, s.title, s.date_created, s.questions_count'
        . ' FROM ' . nibwp_survey_table('surveys') . ' AS s'
        . ' LEFT JOIN ' . nibwp_survey_table('submissions') . ' AS sub'
        . ' ON sub.survey_id = s.id AND sub.status = %s'
        . ' WHERE s.status = %s GROUP BY s.id HAVING COUNT(sub.id) = %d'
        . ' ORDER BY s.date_created ASC LIMIT %d',
        'published',
        'published',
        0,
        $limit
    ), ARRAY_A);

    $empty = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT id, title, date_created FROM ' . nibwp_survey_table('surveys')
        . ' WHERE status = %s AND (question_ids = %s OR questions_count = %d)'
        . ' ORDER BY id ASC LIMIT %d',
        'published',
        '',
        0,
        $limit
    ), ARRAY_A);

    // Only the types that cannot work without stored options, so the finding means
    // something: a star rating builds its own scale and would be noise here.
    $choice_types = nibwp_survey_choice_types();
    $optionless = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT q.id, q.question, q.type FROM ' . nibwp_survey_table('questions') . ' AS q'
        . ' LEFT JOIN ' . nibwp_survey_table('answers') . ' AS a ON a.question_id = q.id'
        . ' WHERE q.status = %s AND q.type IN (' . implode(',', array_fill(0, count($choice_types), '%s')) . ')'
        . ' GROUP BY q.id HAVING COUNT(a.id) = %d ORDER BY q.id ASC LIMIT %d',
        array_merge(['published'], $choice_types, [0, $limit])
    ), ARRAY_A);

    $findings = [];

    foreach ($never as $row) {
        $findings[] = nibwp_survey_pick((array) $row, [
            'id' => 'int',
            'title' => 'string',
            'date_created' => 'string',
            'questions_count' => 'int',
        ]);
    }

    $without_questions = [];

    foreach ($empty as $row) {
        $without_questions[] = nibwp_survey_pick((array) $row, [
            'id' => 'int',
            'title' => 'string',
            'date_created' => 'string',
        ]);
    }

    $without_options = [];

    foreach ($optionless as $row) {
        $without_options[] = nibwp_survey_pick((array) $row, [
            'id' => 'int',
            'question' => 'string',
            'type' => 'string',
        ]);
    }

    $act_on = [];

    if ($findings !== []) {
        $act_on[] = sprintf(
            /* translators: %d: number of surveys */
            __('%d published survey has never been answered. Either nobody can reach it or nobody wants to answer it.', 'nibwp'),
            count($findings)
        );
    }
    if ($without_questions !== []) {
        $act_on[] = sprintf(
            /* translators: %d: number of surveys */
            __('%d published survey has no questions, so anyone who opens it sees nothing to answer.', 'nibwp'),
            count($without_questions)
        );
    }
    if ($without_options !== []) {
        $act_on[] = sprintf(
            /* translators: %d: number of questions */
            __('%d choice question has no answer options, so it offers nothing to choose.', 'nibwp'),
            count($without_options)
        );
    }

    return [
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
        'published_but_never_answered' => $findings,
        'published_without_questions' => $without_questions,
        'choice_questions_without_options' => $without_options,
        'limit' => $limit,
        'note' => __('Each list is bounded by limit, so a long list may be a sample. Counts above are of what was returned.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * A title from input, or a refusal.
 */
function nibwp_survey_title(mixed $raw): string|WP_Error
{
    $title = is_string($raw) ? sanitize_text_field($raw) : '';
    $title = trim($title);

    if ($title === '') {
        return nibwp_survey_err('bad_title', __('Give the survey title as title.', 'nibwp'), 422);
    }

    if (mb_strlen($title) > NIBWP_SURVEY_MAX_TITLE) {
        return nibwp_survey_err(
            'title_too_long',
            sprintf(
                /* translators: %d: maximum number of characters */
                __('A survey title may be at most %d characters.', 'nibwp'),
                NIBWP_SURVEY_MAX_TITLE
            ),
            422
        );
    }

    return $title;
}

/**
 * Create an empty survey.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_create_survey(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Creating a Survey Maker survey', 'nibwp'))) {
        return $denied;
    }

    $title = nibwp_survey_title($input['title'] ?? null);
    if ($title instanceof WP_Error) {
        return $title;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $description = isset($input['description']) && is_string($input['description'])
        ? sanitize_textarea_field($input['description'])
        : '';
    $now = current_time('mysql');

    // Every NOT NULL column is given a value rather than left to its default: two
    // of them have no default at all (activator:57 title, :70 ordering), and a
    // database in strict mode rejects the insert outright.
    $written = $wpdb->insert(
        nibwp_survey_table('surveys'),
        [
            'author_id' => get_current_user_id(),
            'title' => $title,
            'description' => $description,
            'category_ids' => '',
            'question_ids' => '',
            'section_ids' => '',
            'sections_count' => 0,
            'questions_count' => 0,
            'date_created' => $now,
            'date_modified' => $now,
            'image' => '',
            'status' => 'published',
            'trash_status' => '',
            'ordering' => 0,
            'options' => '',
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
    );

    if ($written === false) {
        return nibwp_survey_err('create_failed', __('Survey Maker rejected the new survey row.', 'nibwp'), 500);
    }

    $survey_id = (int) $wpdb->insert_id;
    $post_id = 0;

    // The survey is also published as a post carrying its shortcode, which is what
    // gives it a preview URL. Without the plugin's class the survey still works
    // through the shortcode, so this is reported rather than fatal.
    if (
        class_exists('Survey_Maker_Custom_Post_Type')
        && method_exists('Survey_Maker_Custom_Post_Type', 'survey_add_custom_post')
    ) {
        $post_id = (int) Survey_Maker_Custom_Post_Type::survey_add_custom_post([
            'survey_id' => $survey_id,
            'survey_title' => $title,
            'author_id' => get_current_user_id(),
        ]);
    }

    return [
        'created' => true,
        'survey_id' => $survey_id,
        'title' => $title,
        'shortcode' => '[ays_survey id="' . $survey_id . '"]',
        'post_created' => $post_id > 0,
        'note' => __('An empty survey. Questions, sections and answer options are built on the survey\'s own screen in wp-admin; nothing here adds them.', 'nibwp'),
    ];
}

/**
 * Rename a survey.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_rename_survey(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Renaming a Survey Maker survey', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $title = nibwp_survey_title($input['title'] ?? null);
    if ($title instanceof WP_Error) {
        return $title;
    }

    $survey = nibwp_survey_authorize($survey_id, __('Renaming this survey', 'nibwp'));
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $was = (string) ($survey->title ?? '');

    $wpdb->update(
        nibwp_survey_table('surveys'),
        ['title' => $title, 'date_modified' => current_time('mysql')],
        ['id' => $survey_id],
        ['%s', '%s'],
        ['%d']
    );

    // The post that publishes the survey carries the title too, so leaving it
    // behind would make wp-admin and the site disagree about the survey's name.
    $post_id = isset($survey->custom_post_id) ? (int) $survey->custom_post_id : 0;
    $post_renamed = false;

    if ($post_id > 0 && get_post_type($post_id) === 'ays-survey-maker') {
        wp_update_post(['ID' => $post_id, 'post_title' => $title]);
        $post_renamed = true;
    }

    return [
        'renamed' => true,
        'survey_id' => $survey_id,
        'was' => $was,
        'title' => $title,
        'post_renamed' => $post_renamed,
        'note' => __('Only the name changed. Questions, answer options and every response are untouched.', 'nibwp'),
    ];
}

/**
 * Trash a survey, and its responses with it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_trash_survey(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Trashing a Survey Maker survey', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_authorize($survey_id, __('Trashing this survey', 'nibwp'));
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $status = (string) ($survey->status ?? '');

    if ($status === 'trashed') {
        return [
            'trashed' => false,
            'already_trashed' => true,
            'survey_id' => $survey_id,
            'note' => __('That survey is already in the trash. restore_survey puts it back.', 'nibwp'),
        ];
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    // Exactly what the plugin's own trash does, in the same order: the responses
    // are marked trashed and the previous status is kept in trash_status so a
    // restore can put it back (class-survey-maker-surveys-list-table.php:2565-2588).
    $wpdb->update(
        nibwp_survey_table('submissions'),
        ['status' => 'trashed'],
        ['survey_id' => $survey_id],
        ['%s'],
        ['%d']
    );

    $wpdb->update(
        nibwp_survey_table('surveys'),
        ['status' => 'trashed', 'trash_status' => $status],
        ['id' => $survey_id],
        ['%s', '%s'],
        ['%d']
    );

    return [
        'trashed' => true,
        'survey_id' => $survey_id,
        'was' => $status,
        'note' => __('The survey and its responses are hidden, not deleted. restore_survey puts both back. Nothing was destroyed.', 'nibwp'),
    ];
}

/**
 * Restore a trashed survey.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_restore_survey(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Restoring a Survey Maker survey', 'nibwp'))) {
        return $denied;
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_authorize($survey_id, __('Restoring this survey', 'nibwp'));
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    if ((string) ($survey->status ?? '') !== 'trashed') {
        return [
            'restored' => false,
            'not_trashed' => true,
            'survey_id' => $survey_id,
            'status' => (string) ($survey->status ?? ''),
            'note' => __('That survey is not in the trash, so there is nothing to restore.', 'nibwp'),
        ];
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $back_to = (string) ($survey->trash_status ?? '');
    $back_to = $back_to === '' ? 'published' : $back_to;

    $wpdb->update(
        nibwp_survey_table('submissions'),
        ['status' => 'published'],
        ['survey_id' => $survey_id],
        ['%s'],
        ['%d']
    );

    $wpdb->update(
        nibwp_survey_table('surveys'),
        ['status' => $back_to, 'trash_status' => ''],
        ['id' => $survey_id],
        ['%s', '%s'],
        ['%d']
    );

    return [
        'restored' => true,
        'survey_id' => $survey_id,
        'status' => $back_to,
        'note' => __('The survey and its responses are visible again.', 'nibwp'),
    ];
}

/**
 * Delete a survey, its questions, its answer options and every response.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_survey_delete_survey(array $input): array|WP_Error
{
    if ($denied = nibwp_survey_require(__('Deleting a Survey Maker survey', 'nibwp'))) {
        return $denied;
    }

    // The confirm gate comes before the id is even looked up, and refuses with a
    // code of its own: "it refused" must not be the same answer for "you did not
    // confirm" and "no such survey", or a caller cannot tell a typo from a
    // safety catch.
    if (($input['confirm'] ?? null) !== true) {
        return nibwp_survey_err(
            'needs_confirm',
            __('Deleting a survey permanently deletes every response anybody ever gave it, along with its questions, sections and answer options. Pass confirm:true to go ahead, or use trash_survey, which is reversible.', 'nibwp'),
            428
        );
    }

    $survey_id = nibwp_survey_id($input['survey_id'] ?? null);
    if ($survey_id instanceof WP_Error) {
        return $survey_id;
    }

    $survey = nibwp_survey_authorize($survey_id, __('Deleting this survey', 'nibwp'));
    if ($survey instanceof WP_Error) {
        return $survey;
    }

    $wpdb = nibwp_survey_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $responses = nibwp_survey_response_count($survey_id);
    $responses = $responses instanceof WP_Error ? 0 : $responses;

    $question_ids = nibwp_survey_ids($survey->question_ids ?? '');
    $section_ids = nibwp_survey_ids($survey->section_ids ?? '');

    if ($question_ids !== []) {
        $question_ids = array_slice($question_ids, 0, NIBWP_SURVEY_MAX_ROWS * 10);
        $placeholders = nibwp_survey_placeholders(count($question_ids));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . nibwp_survey_table('answers') . ' WHERE question_id IN (' . $placeholders . ')',
            $question_ids
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . nibwp_survey_table('questions') . ' WHERE id IN (' . $placeholders . ')',
            $question_ids
        ));
    }

    if ($section_ids !== []) {
        $section_ids = array_slice($section_ids, 0, NIBWP_SURVEY_MAX_ROWS * 10);
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . nibwp_survey_table('sections')
            . ' WHERE id IN (' . nibwp_survey_placeholders(count($section_ids)) . ')',
            $section_ids
        ));
    }

    // Keyed on survey_id rather than on the question ids the plugin's own delete
    // uses (class-survey-maker-surveys-list-table.php:2520): the table carries
    // survey_id (activator:232), and a row whose question was already deleted by
    // hand would otherwise be left behind as an orphan answer.
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . nibwp_survey_table('submissions_questions') . ' WHERE survey_id = %d',
        $survey_id
    ));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . nibwp_survey_table('submissions') . ' WHERE survey_id = %d',
        $survey_id
    ));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . nibwp_survey_table('surveys') . ' WHERE id = %d',
        $survey_id
    ));

    $post_id = isset($survey->custom_post_id) ? (int) $survey->custom_post_id : 0;
    $post_deleted = false;

    // Only a post of the plugin's own type, and only by force: trashing it would
    // leave a post pointing at a survey that no longer exists.
    if ($post_id > 0 && get_post_type($post_id) === 'ays-survey-maker') {
        wp_delete_post($post_id, true);
        $post_deleted = true;
    }

    return [
        'deleted' => true,
        'survey_id' => $survey_id,
        'title' => (string) ($survey->title ?? ''),
        'responses_deleted' => $responses,
        'questions_deleted' => count($question_ids),
        'sections_deleted' => count($section_ids),
        'post_deleted' => $post_deleted,
        'note' => __('Permanent. The survey, its questions, its sections, its answer options and every response given to it are gone, and there is no undo.', 'nibwp'),
    ];
}
