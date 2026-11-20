<?php

declare(strict_types=1);

/**
 * Bit Form integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Nine abilities: info, forms, fields, entries, settings, mail, an audit,
 * an export and deletion.
 *
 * Bit Form keeps nothing in posts. Every form is a row in its own
 * `{prefix}bitforms_form` table whose `form_content` column is one big JSON
 * document holding `fields`, `layout`, `formInfo`, `additional`, `buttons` and
 * `formPermissions`. Submissions are rows in `{prefix}bitforms_form_entries`
 * with one `{prefix}bitforms_form_entrymeta` row per field value, keyed by
 * `bitforms_form_entry_id` — a WordPress-shaped meta table that is NOT
 * postmeta, so get_post_meta() and the entry APIs of other form plugins find
 * nothing here.
 *
 * Two things a form owner cannot see from the builder, and both lose enquiries:
 *
 *   1. `form_content.additional.enabled.submission` — when that key is set,
 *      Bit Form runs the workflows and then ROLLS THE TRANSACTION BACK
 *      (FormManager::formSubmit) and deletes the entry again
 *      (Integrations::deleteEntry). The visitor is thanked, the form counts a
 *      submission, and nothing is kept. It is a deliberate privacy switch, but
 *      combined with no notification it means the enquiry never existed.
 *   2. Notifications live in a SEPARATE table, `{prefix}bitforms_email_template`,
 *      each with its own `status` tinyint and a JSON `config` holding `to`,
 *      `cc`, `bcc`, `replyto`, `from`, `from_name`. A form with entries piling
 *      up and every template switched off looks perfectly healthy.
 *
 * Mechanism is IN-PROCESS through Bit Form's own classes wherever they exist:
 *   BitCode\BitForm\Core\Form\FormManager          getInstance / isExist /
 *                                                  getFields / getFormContent /
 *                                                  getFormInfo / getFormName /
 *                                                  getFormData / checkStatus
 *   BitCode\BitForm\Core\Database\FormModel        get / update / delete
 *   BitCode\BitForm\Core\Database\FormEntryModel   get / bulkDelete
 *   BitCode\BitForm\Core\Database\FormEntryMetaModel        get
 *   BitCode\BitForm\Core\Database\EmailTemplateModel        update
 *   BitCode\BitForm\Core\Messages\EmailTemplateHandler      getAllTemplate /
 *                                                           normalizeConfig
 *   BitCode\BitForm\Core\WorkFlow\WorkFlowHandler  getAllworkFlow
 *   BitCode\BitForm\Core\WorkFlow\Helper           getDefaultMailNotifications
 *   BitCode\BitForm\Core\Integration\IntegrationHandler     getAllIntegration
 *
 * Its Model::get() returns rows keyed by the FIRST selected column
 * (get_results(..., OBJECT_K)), an empty array when the table is missing, and a
 * WP_Error('result_empty') when nothing matched — so every read here goes
 * through nibwp_bitform_rows() rather than being used directly. Counting is the
 * one place raw SQL wins: Model::count() returns $wpdb->last_result rather than
 * a number, so COUNT(*) is issued with $wpdb->prepare() against the real column
 * names instead.
 *
 * Detection: BITFORMS_VERSION (defined unconditionally in bitforms.php) plus
 * the FormModel class, because the constant is defined before the autoloader
 * runs and a half-loaded plugin would otherwise read as available.
 *
 * Shortcode: [bitform id="N"]. The handler also accepts form_id, type and
 * entry_id, but `id` is the attribute the builder hands users.
 *
 * Verified against Bit Form 3.3.1 (bit-form, db version 3.2).
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Bit Form's own table suffixes, appended to $wpdb->prefix as its Model does. */
const NIBWP_BITFORM_TABLE_FORM = 'bitforms_form';
const NIBWP_BITFORM_TABLE_ENTRIES = 'bitforms_form_entries';
const NIBWP_BITFORM_TABLE_ENTRYMETA = 'bitforms_form_entrymeta';
const NIBWP_BITFORM_TABLE_MAIL = 'bitforms_email_template';
const NIBWP_BITFORM_TABLE_INTEGRATION = 'bitforms_integration';
const NIBWP_BITFORM_TABLE_WORKFLOWS = 'bitforms_workflows';

/** Is Bit Form active? */
function nibwp_bitform_available(): bool
{
    return defined('BITFORMS_VERSION')
        && class_exists('BitCode\\BitForm\\Core\\Database\\FormModel');
}

/** House WP_Error wrapper. */
function nibwp_bitform_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_bitform_guard(): ?WP_Error
{
    if (!nibwp_bitform_available()) {
        return nibwp_bitform_err('nibwp_bitform_missing', __('Bit Form is not active on this site.', 'nibwp'));
    }

    return null;
}

/** Run a Bit Form call, converting throwables into WP_Error. */
function nibwp_bitform_try(callable $fn, string $code = 'nibwp_bitform_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_bitform_err($code, $e->getMessage());
    }
}

/** A prefixed Bit Form table name, built the way its Model builds it. */
function nibwp_bitform_table(string $suffix): string
{
    global $wpdb;

    return $wpdb->prefix . $suffix;
}

/**
 * Normalise anything Bit Form's Model::get() hands back into a plain list.
 *
 * Three shapes come out of that method and only one of them is rows: an empty
 * array when the table does not exist, a WP_Error('result_empty') when the
 * query matched nothing, and otherwise rows keyed by the first selected
 * column. Treating the WP_Error as a failure would report "Bit Form is broken"
 * for the ordinary case of a form with no entries yet.
 *
 * @return array<int, object>
 */
function nibwp_bitform_rows(mixed $result): array
{
    if (!is_array($result)) {
        return [];
    }

    return array_values(array_filter($result, static fn($row): bool => is_object($row)));
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_bitform_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);
    $page = max((int) ($in['page'] ?? 1), 1);

    return [
        'per_page' => $per_page,
        'page'     => $page,
        'limit'    => $per_page,
        'offset'   => ($page - 1) * $per_page,
    ];
}

/**
 * COUNT(*) on one of Bit Form's tables.
 *
 * Its Model::count() returns $wpdb->last_result rather than a number, so it
 * cannot be used for a count. The table name is a constant from this file, not
 * input, and the one variable is bound through prepare().
 */
function nibwp_bitform_count(string $suffix, int $form_id = 0): int
{
    global $wpdb;

    $table = nibwp_bitform_table($suffix);

    if ($form_id > 0) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE form_id = %d", $form_id));
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
}

/** A FormModel instance, or null when Bit Form is not loaded. */
function nibwp_bitform_form_model()
{
    $class = 'BitCode\\BitForm\\Core\\Database\\FormModel';

    return class_exists($class) ? new $class() : null;
}

/**
 * Load one form row.
 *
 * form_content is the whole form, so it is selected explicitly rather than with
 * `*`: the atomic class map and the generated script page ids are builder
 * bookkeeping nothing here reads, and they can run to hundreds of kilobytes.
 *
 * @return object|WP_Error
 */
function nibwp_bitform_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }

    $model = nibwp_bitform_form_model();
    if ($model === null) {
        return nibwp_bitform_err('nibwp_bitform_no_model', __('The Bit Form data layer is unavailable.', 'nibwp'));
    }

    $rows = nibwp_bitform_rows($model->get(
        ['id', 'form_name', 'form_content', 'status', 'views', 'entries', 'created_at', 'updated_at'],
        ['id' => $form_id]
    ));

    if ($rows === []) {
        return nibwp_bitform_err('nibwp_bitform_not_found', __('No Bit Form form with that ID.', 'nibwp'));
    }

    return $rows[0];
}

/** The decoded form_content document, or an empty object. */
function nibwp_bitform_content(object $form): object
{
    $raw = isset($form->form_content) ? (string) $form->form_content : '';
    $decoded = $raw === '' ? null : json_decode($raw);

    return is_object($decoded) ? $decoded : new \stdClass();
}

/**
 * Does this form keep what is submitted?
 *
 * `additional.enabled.submission` is Bit Form's submission restriction. When
 * the key is present FormManager::formSubmit() rolls the insert back and
 * Integrations::deleteEntry() removes the row, so the answer is the inverse of
 * the key being set. Absent means "stores", which is the default.
 */
function nibwp_bitform_stores_entries(object $content): bool
{
    return !isset($content->additional->enabled->submission);
}

/** Human status for the `status` tinyint on a form row (0 draft, 1 published, 2 trashed). */
function nibwp_bitform_status_label(int $status): string
{
    return match ($status) {
        0 => 'unpublished',
        1 => 'published',
        2 => 'trashed',
        default => 'unknown',
    };
}

/** The shortcode that embeds a form. */
function nibwp_bitform_shortcode(int $form_id): string
{
    return sprintf('[bitform id="%d"]', $form_id);
}

/**
 * The email templates attached to one form, with the recipients decoded.
 *
 * Read through Bit Form's own EmailTemplateHandler so the column list and the
 * config normalisation stay its business rather than ours.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_bitform_mail_templates(int $form_id): array
{
    $handler_class = 'BitCode\\BitForm\\Core\\Messages\\EmailTemplateHandler';
    if (!class_exists($handler_class)) {
        return [];
    }

    $handler = new $handler_class($form_id);
    $templates = nibwp_bitform_rows($handler->getAllTemplate());

    $rows = [];
    foreach ($templates as $template) {
        $config = $handler_class::normalizeConfig($template->config ?? null);
        $to = $config->to ?? [];
        $recipients = array_values(array_filter(array_map('strval', (array) $to), static fn(string $v): bool => trim($v) !== ''));

        $rows[] = [
            'id'            => (int) ($template->id ?? 0),
            'title'         => (string) ($template->title ?? ''),
            'subject'       => (string) ($template->sub ?? ''),
            'active'        => ((int) ($template->status ?? 1)) === 1,
            'to'            => $recipients,
            'cc'            => array_values((array) ($config->cc ?? [])),
            'bcc'           => array_values((array) ($config->bcc ?? [])),
            'reply_to'      => array_values((array) ($config->replyto ?? [])),
            'from'          => (string) ($config->from ?? ''),
            'from_name'     => (string) ($config->from_name ?? ''),
            'has_recipient' => $recipients !== [],
        ];
    }

    return $rows;
}

/**
 * The workflow rows that fire a mail action.
 *
 * A workflow's conditions live in `workflow_condition` as a JSON array of
 * blocks, each with an `actions.success` list of `{type, details}`. Bit Form
 * sends mail for `type === 'mailNotify'` when `details.id` is set, so that is
 * exactly what is counted — not the presence of a workflow, which proves
 * nothing about whether anyone is told.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_bitform_workflow_mail(int $form_id): array
{
    global $wpdb;

    $table = nibwp_bitform_table(NIBWP_BITFORM_TABLE_WORKFLOWS);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT `id`, `workflow_name`, `workflow_status`, `workflow_run`, `workflow_condition`"
            . " FROM `{$table}` WHERE `form_id` = %d",
        $form_id
    ));

    $out = [];
    foreach ((array) $rows as $row) {
        $blocks = json_decode((string) ($row->workflow_condition ?? ''));
        if (!is_array($blocks)) {
            continue;
        }

        $mail_actions = 0;
        foreach ($blocks as $block) {
            foreach ((array) ($block->actions->success ?? []) as $action) {
                if (($action->type ?? '') === 'mailNotify' && !empty($action->details->id)) {
                    $mail_actions++;
                }
            }
        }

        if ($mail_actions === 0) {
            continue;
        }

        $out[] = [
            'workflow_id' => (int) ($row->id ?? 0),
            'name'        => (string) ($row->workflow_name ?? ''),
            'runs_on'     => (string) ($row->workflow_run ?? ''),
            'active'      => ((int) ($row->workflow_status ?? 0)) === 1,
            'mail_actions' => $mail_actions,
        ];
    }

    return $out;
}

/**
 * The third-party integrations attached to one form.
 *
 * `integration_details` holds API credentials, so only the identifying columns
 * are read. An active integration means the submission leaves the site even
 * when nobody is emailed, which changes an audit blocker into a warning — the
 * data is somewhere, just not in anyone's inbox.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_bitform_integrations(int $form_id): array
{
    $handler_class = 'BitCode\\BitForm\\Core\\Integration\\IntegrationHandler';
    if (!class_exists($handler_class)) {
        return [];
    }

    $handler = new $handler_class($form_id);
    $rows = nibwp_bitform_rows($handler->getAllIntegration());

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'     => (int) ($row->id ?? 0),
            'name'   => (string) ($row->integration_name ?? ''),
            'type'   => (string) ($row->integration_type ?? ''),
            'active' => ((int) ($row->status ?? 0)) === 1,
        ];
    }

    return $out;
}

/**
 * Everything that decides whether a submission to this form reaches a person.
 *
 * `resolved` is Bit Form's own answer: Helper::getDefaultMailNotifications()
 * is the method the submit path calls to build the mail queue, so it already
 * applies template status and the workflow exclusion filter. It is reported
 * alongside the raw templates because the two disagreeing is itself the
 * interesting case.
 *
 * @return array<string, mixed>
 */
function nibwp_bitform_delivery(int $form_id): array
{
    $templates = nibwp_bitform_mail_templates($form_id);
    $workflow_mail = nibwp_bitform_workflow_mail($form_id);
    $integrations = nibwp_bitform_integrations($form_id);

    $active_with_recipient = array_values(array_filter(
        $templates,
        static fn(array $t): bool => $t['active'] && $t['has_recipient']
    ));
    $active_workflow_mail = array_values(array_filter(
        $workflow_mail,
        static fn(array $w): bool => $w['active']
    ));
    $active_integrations = array_values(array_filter(
        $integrations,
        static fn(array $i): bool => $i['active']
    ));

    $resolved = [];
    $helper_class = 'BitCode\\BitForm\\Core\\WorkFlow\\Helper';
    if (method_exists($helper_class, 'getDefaultMailNotifications')) {
        foreach ((array) $helper_class::getDefaultMailNotifications($form_id) as $notify) {
            $resolved[] = [
                'to'        => array_values((array) ($notify->to ?? [])),
                'from'      => (string) ($notify->from ?? ''),
                'from_name' => (string) ($notify->from_name ?? ''),
            ];
        }
    }

    $notifies = $active_with_recipient !== [] || $active_workflow_mail !== [];

    return [
        'templates'             => $templates,
        'workflow_mail'         => $workflow_mail,
        'integrations'          => $integrations,
        'active_mail_count'     => count($active_with_recipient),
        'active_workflow_mail'  => count($active_workflow_mail),
        'active_integrations'   => count($active_integrations),
        'resolved_notifications' => $resolved,
        'notifies'              => $notifies,
        'reaches_anything'      => $notifies || $active_integrations !== [],
    ];
}

/**
 * The fields of one form, flattened to key / label / type / required.
 *
 * FormManager::getFields() is Bit Form's own reader: it walks
 * form_content.fields, drops the captcha pseudo-fields, resolves a label from
 * `lbl` then `adminLbl` then `fieldName`, and lifts the required flag out of
 * the nested `valid.req`. Parsing that JSON here instead would drift from it.
 *
 * @return array<int, array<string, mixed>>|WP_Error
 */
function nibwp_bitform_fields(int $form_id)
{
    $manager_class = 'BitCode\\BitForm\\Core\\Form\\FormManager';
    if (!class_exists($manager_class)) {
        return nibwp_bitform_err('nibwp_bitform_no_manager', __('The Bit Form form manager is unavailable.', 'nibwp'));
    }

    $manager = $manager_class::getInstance($form_id);
    if (!$manager->isExist()) {
        return nibwp_bitform_err('nibwp_bitform_not_found', __('No Bit Form form with that ID.', 'nibwp'));
    }

    $rows = [];
    foreach ((array) $manager->getFields() as $key => $field) {
        $field = (array) $field;
        $valid = (array) ($field['valid'] ?? []);

        $rows[] = [
            'key'      => (string) $key,
            'name'     => (string) ($field['name'] ?? ''),
            'label'    => (string) ($field['label'] ?? ''),
            'type'     => (string) ($field['type'] ?? ''),
            'required' => !empty($valid['req']),
        ];
    }

    return $rows;
}

/**
 * Field labels keyed by field key, for the header row of an export.
 *
 * @return array<string, string>
 */
function nibwp_bitform_field_labels(int $form_id): array
{
    $fields = nibwp_bitform_fields($form_id);
    if ($fields instanceof WP_Error) {
        return [];
    }

    $labels = [];
    foreach ($fields as $field) {
        $labels[$field['key']] = $field['label'] !== '' ? $field['label'] : $field['key'];
    }

    return $labels;
}

/**
 * The stored values for a set of entries, keyed entry id => field key => value.
 *
 * One query for the whole page rather than one per entry: the meta table has an
 * index on bitforms_form_entry_id and a 100-entry page would otherwise be 100
 * round trips.
 *
 * @param array<int, int> $entry_ids
 * @return array<int, array<string, string>>
 */
function nibwp_bitform_entry_values(array $entry_ids): array
{
    global $wpdb;

    $entry_ids = array_values(array_unique(array_filter(array_map('intval', $entry_ids))));
    if ($entry_ids === []) {
        return [];
    }

    $table = nibwp_bitform_table(NIBWP_BITFORM_TABLE_ENTRYMETA);
    $placeholders = implode(',', array_fill(0, count($entry_ids), '%d'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant, ids are bound.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT `bitforms_form_entry_id`, `meta_key`, `meta_value` FROM `{$table}`"
            . " WHERE `bitforms_form_entry_id` IN ({$placeholders})",
        $entry_ids
    ));

    $out = [];
    foreach ((array) $rows as $row) {
        $entry_id = (int) ($row->bitforms_form_entry_id ?? 0);
        $out[$entry_id][(string) ($row->meta_key ?? '')] = (string) ($row->meta_value ?? '');
    }

    return $out;
}

/* ── Ability 1 — info ───────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-info', [
    'label'       => __('Bit Form — Info', 'nibwp'),
    'description' => 'Detect Bit Form, its version and database version, how many forms and stored entries exist, and which Bit Form capabilities the current user holds (read-only).',
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'active'       => ['type' => 'boolean'],
            'version'      => ['type' => 'string'],
            'db_version'   => ['type' => 'string'],
            'form_count'   => ['type' => 'integer'],
            'entry_count'  => ['type' => 'integer'],
            'shortcode'    => ['type' => 'string'],
            'tables'       => ['type' => 'object'],
            'capabilities' => ['type' => 'object'],
        ],
        'required' => ['active', 'version', 'form_count', 'entry_count'],
    ],
    'execute_callback'    => 'nibwp_bitform_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. Bit Form stores forms and entries in its own tables, not in posts — nothing in WP_Query or postmeta will find them.',
                'Its notifications live in a separate table with a per-template on/off switch, so a form with entries and no mail is normal-looking and broken. nibwp/bitform-audit is the fast way to find those.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_info(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    return nibwp_bitform_try(static function (): array {
        return [
            'active'      => true,
            'version'     => defined('BITFORMS_VERSION') ? (string) BITFORMS_VERSION : '',
            'db_version'  => (string) get_option('bitforms_db_version', ''),
            'form_count'  => nibwp_bitform_count(NIBWP_BITFORM_TABLE_FORM),
            'entry_count' => nibwp_bitform_count(NIBWP_BITFORM_TABLE_ENTRIES),
            'shortcode'   => '[bitform id="N"]',
            'tables'      => [
                'forms'        => nibwp_bitform_table(NIBWP_BITFORM_TABLE_FORM),
                'entries'      => nibwp_bitform_table(NIBWP_BITFORM_TABLE_ENTRIES),
                'entry_meta'   => nibwp_bitform_table(NIBWP_BITFORM_TABLE_ENTRYMETA),
                'mail'         => nibwp_bitform_table(NIBWP_BITFORM_TABLE_MAIL),
                'integrations' => nibwp_bitform_table(NIBWP_BITFORM_TABLE_INTEGRATION),
                'workflows'    => nibwp_bitform_table(NIBWP_BITFORM_TABLE_WORKFLOWS),
            ],
            // Bit Form checks its own capabilities rather than manage_options
            // alone, and grants them through a role manager rather than on
            // activation — so an editor can be locked out of the builder while
            // still being able to read entries.
            'capabilities' => [
                'manage_bitform'     => current_user_can('manage_bitform'),
                'access_bitform'     => current_user_can('access_bitform'),
                'bitform_entry_edit' => current_user_can('bitform_entry_edit'),
                'manage_options'     => current_user_can('manage_options'),
            ],
            'note' => __('Bit Form keeps forms, entries and notifications in its own tables. Entry values are one row per field in its entry-meta table, not postmeta.', 'nibwp'),
        ];
    });
}

/* ── Ability 2 — forms ──────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-forms', [
    'label'       => __('Bit Form — Forms', 'nibwp'),
    'description' => 'List Bit Form forms, read one with its settings summary, get its embed shortcode, or publish, unpublish or trash it.',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'shortcode', 'set_status'], 'description' => 'The action to perform.'],
            'form_id'  => ['type' => 'integer', 'description' => 'Required for get, shortcode and set_status.'],
            'status'   => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed'], 'description' => 'The status to set. Required for set_status.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bitform_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get reports whether the form stores what is submitted and whether anything notifies a person, which is the pair that decides if an enquiry survives.',
                'The form itself is not editable here. form_content is one JSON document the builder maintains — layout, atomic class map and per-field validation all have to agree, and rewriting it from outside produces forms the builder then repairs unpredictably. Read it here, edit it in Bit Form.',
                'set_status only moves the status column: unpublished forms refuse submissions, trashed forms are recoverable in the Bit Form admin.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_bitform_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'shortcode', 'set_status'], true) && $form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($action, $form_id, $input) {
        $model = nibwp_bitform_form_model();
        if ($model === null) {
            return nibwp_bitform_err('nibwp_bitform_no_model', __('The Bit Form data layer is unavailable.', 'nibwp'));
        }

        if ($action === 'list') {
            $page = nibwp_bitform_paginate($input);
            $rows = nibwp_bitform_rows($model->get(
                ['id', 'form_name', 'status', 'views', 'entries', 'created_at'],
                [],
                $page['limit'],
                $page['offset'],
                'id',
                'DESC'
            ));

            $forms = [];
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                $forms[] = [
                    'id'           => $id,
                    'name'         => (string) ($row->form_name ?? ''),
                    'status'       => nibwp_bitform_status_label((int) ($row->status ?? 0)),
                    'views'        => (int) ($row->views ?? 0),
                    'entry_count'  => nibwp_bitform_count(NIBWP_BITFORM_TABLE_ENTRIES, $id),
                    'created_at'   => (string) ($row->created_at ?? ''),
                    'shortcode'    => nibwp_bitform_shortcode($id),
                ];
            }

            return [
                'forms' => $forms,
                'count' => count($forms),
                'total' => nibwp_bitform_count(NIBWP_BITFORM_TABLE_FORM),
            ];
        }

        if ($action === 'shortcode') {
            $form = nibwp_bitform_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            return ['form_id' => $form_id, 'shortcode' => nibwp_bitform_shortcode($form_id)];
        }

        if ($action === 'set_status') {
            $form = nibwp_bitform_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            $wanted = (string) ($input['status'] ?? '');
            $codes = ['unpublished' => 0, 'published' => 1, 'trashed' => 2];
            if (!isset($codes[$wanted])) {
                throw new \RuntimeException(__('status must be published, unpublished or trashed.', 'nibwp'));
            }

            $model->update(['status' => $codes[$wanted]], ['id' => $form_id]);

            return ['form_id' => $form_id, 'status' => $wanted, 'reversible' => true];
        }

        // get
        $form = nibwp_bitform_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $content = nibwp_bitform_content($form);
        $fields = nibwp_bitform_fields($form_id);
        $delivery = nibwp_bitform_delivery($form_id);
        $stores = nibwp_bitform_stores_entries($content);

        return [
            'form_id'     => $form_id,
            'name'        => (string) ($form->form_name ?? ''),
            'status'      => nibwp_bitform_status_label((int) ($form->status ?? 0)),
            'views'       => (int) ($form->views ?? 0),
            'created_at'  => (string) ($form->created_at ?? ''),
            'updated_at'  => (string) ($form->updated_at ?? ''),
            'field_count' => $fields instanceof WP_Error ? 0 : count($fields),
            'fields'      => $fields instanceof WP_Error ? [] : $fields,
            'entry_count' => nibwp_bitform_count(NIBWP_BITFORM_TABLE_ENTRIES, $form_id),
            'stores_entries' => $stores,
            'notifies'    => $delivery['notifies'],
            'active_mail_count' => $delivery['active_mail_count'],
            'active_integrations' => $delivery['active_integrations'],
            'shortcode'   => nibwp_bitform_shortcode($form_id),
            'warning'     => (!$stores && !$delivery['reaches_anything'])
                ? __('This form discards every submission and notifies nobody. Anything sent through it is gone.', 'nibwp')
                : '',
        ];
    });
}

/* ── Ability 3 — fields ─────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-fields', [
    'label'       => __('Bit Form — Fields', 'nibwp'),
    'description' => 'Read a form\'s fields: key, name, label, type and whether each one is required (read-only).',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'The form to read.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer'],
            'count'   => ['type' => 'integer'],
            'fields'  => [
                'type'  => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key'      => ['type' => 'string'],
                        'name'     => ['type' => 'string'],
                        'label'    => ['type' => 'string'],
                        'type'     => ['type' => 'string'],
                        'required' => ['type' => 'boolean'],
                    ],
                    'required' => ['key', 'label', 'type', 'required'],
                ],
            ],
        ],
        'required' => ['form_id', 'fields', 'count'],
    ],
    'execute_callback'    => 'nibwp_bitform_fields_read',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'The `key` is what entry meta rows are keyed on, so it is the column name to use when reading or exporting values.',
                'Read-only: fields carry per-type validation, layout coordinates and a generated class map that the builder keeps in step. Writing one of those from outside leaves a form the builder disagrees with.',
                'recaptcha and hcaptcha fields are excluded, matching Bit Form\'s own field reader — they are not data-carrying fields.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_fields_read(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($form_id) {
        $fields = nibwp_bitform_fields($form_id);
        if ($fields instanceof WP_Error) {
            return $fields;
        }

        return [
            'form_id' => $form_id,
            'fields'  => $fields,
            'count'   => count($fields),
        ];
    });
}

/* ── Ability 4 — entries ────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-entries', [
    'label'       => __('Bit Form — Entries', 'nibwp'),
    'description' => 'List stored entries, filtered by form and paginated, or read one entry with all of its field values (read-only).',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer', 'description' => 'Filter to one form. Omit to list across every form.'],
            'entry_id' => ['type' => 'integer', 'description' => 'Required for get.'],
            'with_values' => ['type' => 'boolean', 'default' => false, 'description' => 'Include field values on a list. Off by default — it multiplies the personal data returned.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bitform_entries',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'An empty result does not prove nothing was submitted. A form with the submission restriction switched on rolls its entries back after the workflows run, so it counts submissions and keeps none — check stores_entries on nibwp/bitform-forms before concluding anything.',
                'Values live one row per field in the entry-meta table and are keyed by field key, not label. Use nibwp/bitform-fields to translate them.',
                'Entries are personal data. Read what the task needs and no more; with_values is off by default for that reason.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_entries(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'get' && (int) ($input['entry_id'] ?? 0) <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid entry ID is required.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($action, $input) {
        global $wpdb;

        $table = nibwp_bitform_table(NIBWP_BITFORM_TABLE_ENTRIES);

        if ($action === 'get') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT `id`, `form_id`, `user_id`, `user_ip`, `user_device`, `referer`, `status`, `created_at`, `updated_at`"
                    . " FROM `{$table}` WHERE `id` = %d",
                $entry_id
            ));

            if ($row === null) {
                return nibwp_bitform_err('nibwp_bitform_not_found', __('No Bit Form entry with that ID.', 'nibwp'));
            }

            $form_id = (int) ($row->form_id ?? 0);
            $values = nibwp_bitform_entry_values([$entry_id])[$entry_id] ?? [];
            $labels = nibwp_bitform_field_labels($form_id);

            $labelled = [];
            foreach ($values as $key => $value) {
                $labelled[] = [
                    'key'   => (string) $key,
                    'label' => (string) ($labels[$key] ?? $key),
                    'value' => $value,
                ];
            }

            return [
                'entry_id'   => $entry_id,
                'form_id'    => $form_id,
                'created_at' => (string) ($row->created_at ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
                // 0 not viewed, 1 viewed, 2 trashed — Bit Form's own encoding.
                'viewed'     => ((int) ($row->status ?? 0)) === 1,
                'trashed'    => ((int) ($row->status ?? 0)) === 2,
                'user_id'    => (int) ($row->user_id ?? 0),
                'referer'    => (string) ($row->referer ?? ''),
                'values'     => $labelled,
            ];
        }

        $page = nibwp_bitform_paginate($input);
        $form_id = (int) ($input['form_id'] ?? 0);

        if ($form_id > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `form_id`, `status`, `created_at` FROM `{$table}` WHERE `form_id` = %d"
                    . " ORDER BY `id` DESC LIMIT %d OFFSET %d",
                $form_id,
                $page['limit'],
                $page['offset']
            ));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `form_id`, `status`, `created_at` FROM `{$table}`"
                    . " ORDER BY `id` DESC LIMIT %d OFFSET %d",
                $page['limit'],
                $page['offset']
            ));
        }

        $rows = (array) $rows;
        $ids = array_map(static fn($row): int => (int) ($row->id ?? 0), $rows);
        $values = !empty($input['with_values']) ? nibwp_bitform_entry_values($ids) : [];

        $entries = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $entry = [
                'entry_id'   => $id,
                'form_id'    => (int) ($row->form_id ?? 0),
                'created_at' => (string) ($row->created_at ?? ''),
                'viewed'     => ((int) ($row->status ?? 0)) === 1,
                'trashed'    => ((int) ($row->status ?? 0)) === 2,
            ];

            if (!empty($input['with_values'])) {
                $entry['values'] = $values[$id] ?? [];
            }

            $entries[] = $entry;
        }

        return [
            'form_id'  => $form_id > 0 ? $form_id : null,
            'entries'  => $entries,
            'count'    => count($entries),
            'total'    => nibwp_bitform_count(NIBWP_BITFORM_TABLE_ENTRIES, $form_id),
            'per_page' => $page['per_page'],
            'page'     => $page['page'],
        ];
    });
}

/* ── Ability 5 — settings ───────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-settings', [
    'label'       => __('Bit Form — Settings', 'nibwp'),
    'description' => 'Read a form\'s settings out of its form_content document — submission restriction, buttons, layout and permissions — or the site-level Bit Form options (read-only).',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'scope'   => ['type' => 'string', 'enum' => ['form', 'site'], 'default' => 'form'],
            'form_id' => ['type' => 'integer', 'description' => 'Required when scope is form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bitform_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'The setting that matters most is additional.enabled.submission. When it is present Bit Form still runs the workflows and then throws the entry away — it is reported here as stores_entries false rather than left for you to infer.',
                'Read-only. These live inside one JSON document the builder validates as a whole; report what is wrong and let the user fix it in Bit Form.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $scope = (string) ($input['scope'] ?? 'form');

    if ($scope === 'form' && (int) ($input['form_id'] ?? 0) <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($scope, $input) {
        if ($scope === 'site') {
            return [
                'scope'   => 'site',
                'options' => [
                    'bitforms_db_version'    => get_option('bitforms_db_version', ''),
                    'bitforms_version'       => get_option('bitforms_version', ''),
                    'bitforms_allow_tracking' => get_option('bitforms_allow_tracking', ''),
                    'bitform_app_config'     => get_option('bitform_app_config', null),
                    'bitform_app_settings'   => get_option('bitform_app_settings', null),
                    // The file-download route Bit Form registers for uploaded
                    // attachments. A missing entry here is why a stored upload
                    // 404s while the entry still lists the filename.
                    'bitforms_routes'        => get_option('bitforms_routes', null),
                ],
            ];
        }

        $form_id = (int) ($input['form_id'] ?? 0);
        $form = nibwp_bitform_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $content = nibwp_bitform_content($form);
        $stores = nibwp_bitform_stores_entries($content);
        $delivery = nibwp_bitform_delivery($form_id);

        return [
            'scope'      => 'form',
            'form_id'    => $form_id,
            'name'       => (string) ($form->form_name ?? ''),
            'status'     => nibwp_bitform_status_label((int) ($form->status ?? 0)),
            'stores_entries' => $stores,
            // The fields array is the bulk of form_content and has its own
            // ability, so it is left out rather than repeated here.
            'form_info'  => $content->formInfo ?? null,
            'additional' => $content->additional ?? null,
            'buttons'    => $content->buttons ?? null,
            'permissions' => $content->formPermissions ?? null,
            'theme'      => $content->theme ?? null,
            'notifies'   => $delivery['notifies'],
            'warning'    => (!$stores && !$delivery['reaches_anything'])
                ? __('The submission restriction is on and nothing notifies anyone. Every submission is discarded without a trace.', 'nibwp')
                : '',
        ];
    });
}

/* ── Ability 6 — mail ───────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-mail', [
    'label'       => __('Bit Form — Notifications', 'nibwp'),
    'description' => 'Read who a form actually tells about a submission: its email templates and their recipients, the workflow mail actions, the third-party integrations it fires, and Bit Form\'s own resolved notification list. Can also switch a template on or off.',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'set_template_status'], 'default' => 'list'],
            'form_id'     => ['type' => 'integer', 'description' => 'The form to read.'],
            'template_id' => ['type' => 'integer', 'description' => 'Required for set_template_status.'],
            'enabled'     => ['type' => 'boolean', 'default' => true, 'description' => 'Whether the template should fire. Used by set_template_status.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bitform_mail',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A template existing is not the same as it firing. Each row carries its own status flag and a config whose `to` may be empty, so active plus has_recipient is the pair to look at.',
                'resolved_notifications is Bit Form\'s own answer, built by the method its submit path calls. When it is empty and templates are listed, nothing will be sent whatever the builder shows.',
                'An active integration means the submission leaves the site even when nobody is emailed. That is delivery, not notification, and it is reported separately for that reason.',
                'set_template_status writes one tinyint and is the direct fix for a form whose enquiries nobody hears about.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_mail(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }

    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'set_template_status' && (int) ($input['template_id'] ?? 0) <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid template ID is required.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($action, $form_id, $input) {
        $form = nibwp_bitform_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $delivery = nibwp_bitform_delivery($form_id);

        if ($action === 'set_template_status') {
            $template_id = (int) ($input['template_id'] ?? 0);

            $owned = false;
            foreach ($delivery['templates'] as $template) {
                if ($template['id'] === $template_id) {
                    $owned = true;
                }
            }
            if (!$owned) {
                throw new \RuntimeException(__('That email template does not belong to this form.', 'nibwp'));
            }

            $model_class = 'BitCode\\BitForm\\Core\\Database\\EmailTemplateModel';
            if (!class_exists($model_class)) {
                throw new \RuntimeException(__('The Bit Form email template model is unavailable.', 'nibwp'));
            }

            $enabled = (bool) ($input['enabled'] ?? true);
            (new $model_class())->update(
                ['status' => $enabled ? 1 : 0],
                ['id' => $template_id, 'form_id' => $form_id]
            );

            return [
                'form_id'     => $form_id,
                'template_id' => $template_id,
                'active'      => $enabled,
            ];
        }

        return [
            'form_id'   => $form_id,
            'templates' => $delivery['templates'],
            'workflow_mail' => $delivery['workflow_mail'],
            'integrations'  => $delivery['integrations'],
            'active_mail_count'    => $delivery['active_mail_count'],
            'active_workflow_mail' => $delivery['active_workflow_mail'],
            'active_integrations'  => $delivery['active_integrations'],
            'resolved_notifications' => $delivery['resolved_notifications'],
            'notifies'  => $delivery['notifies'],
            'warning'   => $delivery['notifies']
                ? ''
                : __('Nothing on this form notifies a person when it is submitted.', 'nibwp'),
        ];
    });
}

/* ── Ability 7 — audit ──────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-audit', [
    'label'       => __('Bit Form — Audit', 'nibwp'),
    'description' => 'Check every form for the faults that lose enquiries: storing entries nobody is notified about, discarding submissions outright, and forms with no fields (read-only).',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
            'limit'   => ['type' => 'integer', 'default' => 100, 'description' => 'Most forms to audit in one pass when form_id is omitted.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'forms_checked' => ['type' => 'integer'],
            'verdict'  => ['type' => 'string'],
            'blockers' => ['type' => 'array', 'items' => ['type' => 'object']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['forms_checked', 'verdict', 'blockers', 'warnings'],
    ],
    'execute_callback'    => 'nibwp_bitform_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Two real failures, and Bit Form allows both without a word of warning.',
                'A form whose email templates are all switched off, or whose only template has an empty recipient list, stores every entry and tells nobody. The enquiries sit in the Bit Form admin, unread, until someone happens to look.',
                'A form with the submission restriction on discards the entry after the workflows run. Combined with no notification, the visitor is thanked and the enquiry never existed anywhere.',
                'An active integration is treated as delivery but not as notification, so "the data went to a spreadsheet nobody reads" is a warning rather than a pass.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    return nibwp_bitform_try(static function () use ($input) {
        $model = nibwp_bitform_form_model();
        if ($model === null) {
            return nibwp_bitform_err('nibwp_bitform_no_model', __('The Bit Form data layer is unavailable.', 'nibwp'));
        }

        $form_id = (int) ($input['form_id'] ?? 0);
        $limit = min(max((int) ($input['limit'] ?? 100), 1), 500);

        $rows = $form_id > 0
            ? nibwp_bitform_rows($model->get(['id', 'form_name', 'form_content', 'status'], ['id' => $form_id]))
            : nibwp_bitform_rows($model->get(['id', 'form_name', 'form_content', 'status'], [], $limit, 0, 'id', 'DESC'));

        $findings = [];

        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $name = (string) ($row->form_name ?? '');
            $content = nibwp_bitform_content($row);
            $stores = nibwp_bitform_stores_entries($content);
            $delivery = nibwp_bitform_delivery($id);

            if (!$stores && !$delivery['reaches_anything']) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'blocker',
                    'code'     => 'no_storage_no_delivery',
                    'message'  => __('The submission restriction is on and nothing notifies anyone. Bit Form runs the workflows and then deletes the entry, so the visitor is thanked and no record of the enquiry exists anywhere.', 'nibwp'),
                    'fix'      => __('Turn the submission restriction off in the Bit Form builder, or give the form an enabled email template with a recipient.', 'nibwp'),
                ];
            } elseif (!$delivery['reaches_anything']) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'blocker',
                    'code'     => 'no_active_notification',
                    'message'  => __('Entries are stored but nothing notifies anyone. The enquiries pile up unread in the Bit Form admin while the form looks healthy.', 'nibwp'),
                    'fix'      => __('Enable an email template with a recipient — nibwp/bitform-mail lists them and can switch one on.', 'nibwp'),
                ];
            } elseif (!$delivery['notifies']) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'warning',
                    'code'     => 'delivered_only_by_integration',
                    'message'  => __('No email is sent. The submission only leaves through an integration, so anyone waiting for a notification will never get one.', 'nibwp'),
                    'fix'      => __('Add an email template with a recipient if a person is meant to hear about these.', 'nibwp'),
                ];
            } elseif (!$stores) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'warning',
                    'code'     => 'stores_nothing',
                    'message'  => __('The submission restriction is on, so nothing is kept. The notification email is the only copy, and a bounced or filtered email loses the enquiry for good.', 'nibwp'),
                    'fix'      => __('Leave it on only if discarding entries is deliberate, and make sure the notification is reaching an inbox someone reads.', 'nibwp'),
                ];
            }

            $fields = nibwp_bitform_fields($id);
            if (!$fields instanceof WP_Error && $fields === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('The form has no data-carrying fields, so there is nothing to submit.', 'nibwp'),
                    'fix'      => __('Add fields in the Bit Form builder.', 'nibwp'),
                ];
            }

            if ((int) ($row->status ?? 1) !== 1) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'warning',
                    'code'     => 'not_published',
                    'message'  => __('The form is not published, so it refuses submissions wherever it is embedded.', 'nibwp'),
                    'fix'      => __('Publish it with nibwp/bitform-forms set_status, or remove the embed.', 'nibwp'),
                ];
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'forms_checked' => count($rows),
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
        ];
    });
}

/* ── Ability 8 — export ─────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-export', [
    'label'       => __('Bit Form — Export entries', 'nibwp'),
    'description' => 'Export a form\'s entries as rows, JSON or CSV with field labels as headers, paginated (read-only).',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id'  => ['type' => 'integer', 'description' => 'The form whose entries to export.'],
            'format'   => ['type' => 'string', 'enum' => ['rows', 'json', 'csv'], 'default' => 'rows'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bitform_export',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Paginated on purpose, and capped at 100 rows a call.',
                'Columns come from the form\'s current fields, so an entry submitted before a field was renamed still lands in the right column — but a field deleted since is not in the header and its stored values are dropped.',
                'Personal data leaving its original context. Export the columns the task needs, not everything.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_bitform_export(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($form_id, $input) {
        global $wpdb;

        $form = nibwp_bitform_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $page = nibwp_bitform_paginate($input + ['per_page' => 50]);
        $table = nibwp_bitform_table(NIBWP_BITFORM_TABLE_ENTRIES);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a file constant.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT `id`, `created_at` FROM `{$table}` WHERE `form_id` = %d ORDER BY `id` DESC LIMIT %d OFFSET %d",
            $form_id,
            $page['limit'],
            $page['offset']
        ));

        $labels = nibwp_bitform_field_labels($form_id);
        $values = nibwp_bitform_entry_values(array_map(static fn($row): int => (int) ($row->id ?? 0), $rows));

        $records = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $record = ['Entry ID' => $id, 'Date' => (string) ($row->created_at ?? '')];
            $entry_values = $values[$id] ?? [];

            foreach ($labels as $key => $label) {
                $record[$label] = (string) ($entry_values[$key] ?? '');
            }

            $records[] = $record;
        }

        $columns = array_merge(['Entry ID', 'Date'], array_values($labels));
        $format = (string) ($input['format'] ?? 'rows');

        if ($format === 'csv') {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                throw new \RuntimeException(__('Could not open a buffer to build the CSV.', 'nibwp'));
            }
            fputcsv($handle, $columns);
            foreach ($records as $record) {
                fputcsv($handle, array_values($record));
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return ['form_id' => $form_id, 'format' => 'csv', 'csv' => $csv, 'count' => count($records)];
        }

        if ($format === 'json') {
            return [
                'form_id' => $form_id,
                'format'  => 'json',
                'json'    => (string) wp_json_encode($records),
                'count'   => count($records),
            ];
        }

        return [
            'form_id' => $form_id,
            'format'  => 'rows',
            'columns' => $columns,
            'rows'    => $records,
            'count'   => count($records),
            'total'   => nibwp_bitform_count(NIBWP_BITFORM_TABLE_ENTRIES, $form_id),
        ];
    });
}

/* ── Ability 9 — delete ─────────────────────────────────────────────── */

wp_register_ability('nibwp/bitform-delete', [
    'label'       => __('Bit Form — Delete', 'nibwp'),
    'description' => 'Trash or permanently delete a Bit Form form or entry. Permanent deletion is irreversible.',
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['trash_form', 'delete_form', 'trash_entry', 'delete_entry'],
                'description' => 'The action to perform.',
            ],
            'form_id'  => ['type' => 'integer', 'description' => 'Required for the form actions and for delete_entry.'],
            'entry_id' => ['type' => 'integer', 'description' => 'Required for the entry actions.'],
            'confirm'  => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for any permanent deletion. Trashing does not require it.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_bitform_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Prefer trashing. trash_form and trash_entry only move the status column and are undone with nibwp/bitform-forms set_status or in the Bit Form admin.',
                'delete_form removes the form row only. Bit Form\'s own admin also clears that form\'s entries, notifications, workflows and integrations, so deleting the row from here leaves those behind — trash the form and delete it in Bit Form when a full clean-up is wanted.',
                'delete_entry removes the entry and its field values together, through Bit Form\'s own joined delete. Uploaded files stay on disk.',
                'Any page embedding a deleted form renders nothing afterwards.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_bitform_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_bitform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $entry_id = (int) ($input['entry_id'] ?? 0);

    // The confirm gate comes before the ID checks on purpose: a caller who
    // forgot confirm should be told that, not sent to fix an ID and try the
    // destructive call again believing it is now approved.
    if (in_array($action, ['delete_form', 'delete_entry'], true) && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_bitform_err(
            'nibwp_bitform_unconfirmed',
            __('This permanently destroys data. Trashing is reversible; re-issue the call with confirm set to true if deletion is intended.', 'nibwp')
        );
    }

    if (in_array($action, ['trash_form', 'delete_form'], true) && $form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid form ID is required.', 'nibwp'));
    }
    if (in_array($action, ['trash_entry', 'delete_entry'], true) && $entry_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('A valid entry ID is required.', 'nibwp'));
    }
    // Bit Form's joined delete needs both ids, and requiring the form id also
    // stops an entry from another form being removed by a stray number.
    if ($action === 'delete_entry' && $form_id <= 0) {
        return nibwp_bitform_err('nibwp_bitform_bad_id', __('Both form_id and entry_id are required to delete an entry.', 'nibwp'));
    }

    return nibwp_bitform_try(static function () use ($action, $form_id, $entry_id) {
        global $wpdb;

        if ($action === 'trash_form' || $action === 'delete_form') {
            $form = nibwp_bitform_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            $model = nibwp_bitform_form_model();
            if ($model === null) {
                throw new \RuntimeException(__('The Bit Form data layer is unavailable.', 'nibwp'));
            }

            if ($action === 'trash_form') {
                $model->update(['status' => 2], ['id' => $form_id]);

                return ['form_id' => $form_id, 'status' => 'trashed', 'reversible' => true];
            }

            $model->delete(['id' => $form_id]);

            return ['form_id' => $form_id, 'deleted' => true, 'reversible' => false];
        }

        $entry_model_class = 'BitCode\\BitForm\\Core\\Database\\FormEntryModel';
        if (!class_exists($entry_model_class)) {
            throw new \RuntimeException(__('The Bit Form entry model is unavailable.', 'nibwp'));
        }
        $entry_model = new $entry_model_class();

        if ($action === 'trash_entry') {
            $entry_model->update(['status' => 2], ['id' => $entry_id]);

            return ['entry_id' => $entry_id, 'status' => 'trashed', 'reversible' => true];
        }

        // FormEntryModel::bulkDelete() is a two-table joined DELETE, so the
        // entry and its field values go together. Its condition keys must be
        // table-qualified — a bare `id` is ambiguous across the join.
        $prefix = $wpdb->prefix;
        $entry_model->bulkDelete([
            "`{$prefix}" . NIBWP_BITFORM_TABLE_ENTRIES . "`.`id`"      => $entry_id,
            "`{$prefix}" . NIBWP_BITFORM_TABLE_ENTRIES . "`.`form_id`" => $form_id,
        ]);

        return ['entry_id' => $entry_id, 'form_id' => $form_id, 'deleted' => true, 'reversible' => false];
    });
}
