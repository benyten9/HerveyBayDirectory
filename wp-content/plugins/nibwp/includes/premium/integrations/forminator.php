<?php

declare(strict_types=1);

/**
 * Forminator integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Ten abilities: forms, fields, entries, settings, polls, quizzes, export, an
 * audit, and deletion — plus info.
 *
 * Forminator is three products behind one plugin: forms, polls and quizzes.
 * They share a data model and an API but are separate module types, so a tool
 * that only understands "forms" misses two thirds of what is on the site. Each
 * gets its own ability here.
 *
 * Mechanism is IN-PROCESS through Forminator_API, the plugin's documented
 * public API:
 *   get_forms() / get_form() / add_form() / update_form() / delete_form()
 *   get_form_fields() / add_form_field() / update_form_field() / delete_form_field()
 *   get_form_entries() / count_form_entries() / delete_form_entry()
 *   update_form_setting() / update_form_settings()
 *   get_polls() / get_quizzes() and their entry counterparts
 *
 * Detection: class_exists('Forminator_API').
 *
 * Verified against Forminator 1.57.0 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Is Forminator active? */
function nibwp_fmtr_available(): bool
{
    return class_exists('Forminator_API');
}

/** House WP_Error wrapper. */
function nibwp_fmtr_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_fmtr_guard(): ?WP_Error
{
    if (!nibwp_fmtr_available()) {
        return nibwp_fmtr_err('nibwp_fmtr_missing', __('Forminator is not active on this site.', domain: 'nibwp'));
    }

    // Forminator's API asks to be initialised before use; skipping it is how
    // the first call in a request returns nothing for no visible reason.
    if (method_exists('Forminator_API', 'initialize')) {
        Forminator_API::initialize();
    }

    return null;
}

/** Run a Forminator call, converting throwables into WP_Error. */
function nibwp_fmtr_try(callable $fn, string $code = 'nibwp_fmtr_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_fmtr_err($code, $e->getMessage());
    }
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_fmtr_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/** A module summarised for a list — never the whole object. */
function nibwp_fmtr_summary($module): array
{
    return [
        'id'     => (int) ($module->id ?? 0),
        'name'   => (string) ($module->name ?? ($module->settings['formName'] ?? '')),
        'status' => (string) ($module->status ?? ''),
        'type'   => (string) ($module->post_type ?? ''),
    ];
}

/* ── Ability 1 — info ───────────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-info', [
    'label'       => __('Forminator — Info', domain: 'nibwp'),
    'description' => __('Detect Forminator and count what exists on the site: forms, polls and quizzes (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call this first. Forminator is three things in one plugin — forms, polls and quizzes — and each has its own ability here. A site can have plenty of the other two while appearing to have few forms.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fmtr_info(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    return nibwp_fmtr_try(static function (): array {
        $forms = Forminator_API::get_forms(null, 1, 100);
        $polls = method_exists('Forminator_API', 'get_polls') ? Forminator_API::get_polls(null, 1, 100) : [];
        $quizzes = method_exists('Forminator_API', 'get_quizzes') ? Forminator_API::get_quizzes(null, 1, 100) : [];

        return [
            'active'       => true,
            'version'      => defined('FORMINATOR_VERSION') ? FORMINATOR_VERSION : '',
            'form_count'   => is_array($forms) ? count($forms) : 0,
            'poll_count'   => is_array($polls) ? count($polls) : 0,
            'quiz_count'   => is_array($quizzes) ? count($quizzes) : 0,
            'stores_entries' => true,
        ];
    });
}

/* ── Ability 2 — forms ──────────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-forms', [
    'label'       => __('Forminator — Forms', domain: 'nibwp'),
    'description' => __('List, read, create and update Forminator forms, and get the shortcode for embedding one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'shortcode'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'name'    => ['type' => 'string'],
            'settings' => ['type' => 'object', 'description' => 'create/update: form settings.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'list returns summaries. A Forminator form object carries every field and setting, so returning fifty of them buries the answer — use get for one.',
                'Email notifications live in the form settings, so a form created without them stores entries and tells nobody.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fmtr_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_fmtr_err('nibwp_fmtr_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_fmtr_try(static function () use ($action, $form_id, $input) {
        switch ($action) {
            case 'list':
                $page = nibwp_fmtr_paginate($input);
                $forms = Forminator_API::get_forms(null, $page['page'], $page['per_page']);
                $rows = array_map('nibwp_fmtr_summary', is_array($forms) ? $forms : []);

                return ['forms' => $rows, 'count' => count($rows)];

            case 'get':
                $form = Forminator_API::get_form($form_id);
                if (is_wp_error($form)) {
                    return $form;
                }

                return ['form_id' => $form_id, 'form' => $form, 'shortcode' => sprintf('[forminator_form id="%d"]', $form_id)];

            case 'create':
                $new_id = Forminator_API::add_form(
                    (string) ($input['name'] ?? __('Untitled form', domain: 'nibwp')),
                    [],
                    (array) ($input['settings'] ?? [])
                );

                return is_wp_error($new_id) ? $new_id : [
                    'form_id' => (int) $new_id,
                    'created' => true,
                    'note'    => __('Check the notification settings — a form without one stores entries and emails nobody.', domain: 'nibwp'),
                ];

            case 'update':
                $result = Forminator_API::update_form($form_id, null, (array) ($input['settings'] ?? []));

                return is_wp_error($result) ? $result : ['form_id' => $form_id, 'updated' => true];

            case 'shortcode':
                return ['form_id' => $form_id, 'shortcode' => sprintf('[forminator_form id="%d"]', $form_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ── Ability 3 — fields ─────────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-fields', [
    'label'       => __('Forminator — Fields', domain: 'nibwp'),
    'description' => __('List, read, add, update and delete the fields on a form.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete', 'by_type'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer'],
            'field_id' => ['type' => 'string', 'description' => 'Forminator field IDs are strings such as "name-1".'],
            'field'    => ['type' => 'object', 'description' => 'add/update: the field definition.'],
            'type'     => ['type' => 'string', 'description' => 'by_type: e.g. email, text, textarea.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Forminator field IDs are STRINGS like "email-1", not integers — entries and notification merge tags both key on them.',
                'Deleting a field leaves values already recorded against its ID in past entries unreachable, and any notification referencing it renders empty.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fmtr_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_fmtr_err('nibwp_fmtr_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $action = (string) ($input['action'] ?? 'list');
    $field_id = (string) ($input['field_id'] ?? '');

    if (in_array($action, ['get', 'update', 'delete'], strict: true) && $field_id === '') {
        return nibwp_fmtr_err('nibwp_fmtr_bad_id', __('A field ID is required — a string such as "email-1".', domain: 'nibwp'));
    }

    return nibwp_fmtr_try(static function () use ($form_id, $action, $field_id, $input) {
        switch ($action) {
            case 'list':
                $fields = Forminator_API::get_form_fields($form_id);
                if (is_wp_error($fields)) {
                    return $fields;
                }

                $rows = [];
                foreach ((array) $fields as $field) {
                    $data = is_object($field) && method_exists($field, 'to_array') ? $field->to_array() : (array) $field;
                    $rows[] = [
                        'id'       => (string) ($data['element_id'] ?? ''),
                        'type'     => (string) ($data['type'] ?? ''),
                        'label'    => (string) ($data['field_label'] ?? ''),
                        'required' => !empty($data['required']),
                    ];
                }

                return ['form_id' => $form_id, 'fields' => $rows, 'count' => count($rows)];

            case 'by_type':
                $type = (string) ($input['type'] ?? '');
                if ($type === '') {
                    throw new \RuntimeException(__('A field type is required.', domain: 'nibwp'));
                }

                return ['form_id' => $form_id, 'type' => $type, 'fields' => Forminator_API::get_form_fields_by_type($form_id, $type)];

            case 'get':
                return ['form_id' => $form_id, 'field' => Forminator_API::get_form_field($form_id, $field_id)];

            case 'add':
                $field = (array) ($input['field'] ?? []);
                if (($field['type'] ?? '') === '') {
                    throw new \RuntimeException(__('A field type is required.', domain: 'nibwp'));
                }
                $result = Forminator_API::add_form_field($form_id, (string) $field['type'], $field);

                return is_wp_error($result) ? $result : ['form_id' => $form_id, 'created' => true, 'result' => $result];

            case 'update':
                $result = Forminator_API::update_form_field($form_id, $field_id, (array) ($input['field'] ?? []));

                return is_wp_error($result) ? $result : ['form_id' => $form_id, 'field_id' => $field_id, 'updated' => true];

            case 'delete':
                $result = Forminator_API::delete_form_field($form_id, $field_id);

                return is_wp_error($result) ? $result : [
                    'form_id'  => $form_id,
                    'field_id' => $field_id,
                    'deleted'  => true,
                    'note'     => __('Values recorded against this field remain in past entries but are no longer reachable through the form.', domain: 'nibwp'),
                ];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ── Ability 4 — entries ────────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-entries', [
    'label'       => __('Forminator — Entries', domain: 'nibwp'),
    'description' => __('List, read and count entries for a form, poll or quiz.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'count'], 'default' => 'list'],
            'module'   => ['type' => 'string', 'enum' => ['form', 'poll', 'quiz'], 'default' => 'form'],
            'module_id' => ['type' => 'integer'],
            'entry_id' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['module_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_entries',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Works across all three module types — set module to form, poll or quiz.',
                'Entries are personal data. Read what the task needs and no more.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fmtr_entries(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    $module_id = (int) ($input['module_id'] ?? 0);
    if ($module_id <= 0) {
        return nibwp_fmtr_err('nibwp_fmtr_bad_id', __('A valid module ID is required.', domain: 'nibwp'));
    }

    return nibwp_fmtr_try(static function () use ($module_id, $input) {
        $module = (string) ($input['module'] ?? 'form');
        $action = (string) ($input['action'] ?? 'list');

        $methods = [
            'form' => ['get' => 'get_form_entries', 'one' => 'get_form_entry', 'count' => 'count_form_entries'],
            'poll' => ['get' => 'get_poll_entries', 'one' => 'get_poll_entry', 'count' => 'count_poll_entries'],
            'quiz' => ['get' => 'get_quiz_entries', 'one' => 'get_quiz_entry', 'count' => 'count_quiz_entries'],
        ];

        if (!isset($methods[$module])) {
            throw new \RuntimeException(__('module must be form, poll or quiz.', domain: 'nibwp'));
        }

        $api = $methods[$module];

        if ($action === 'count') {
            return ['module' => $module, 'module_id' => $module_id, 'total' => (int) Forminator_API::{$api['count']}($module_id)];
        }

        if ($action === 'get') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            if ($entry_id <= 0) {
                throw new \RuntimeException(__('A valid entry ID is required.', domain: 'nibwp'));
            }

            return ['module' => $module, 'entry_id' => $entry_id, 'entry' => Forminator_API::{$api['one']}($module_id, $entry_id)];
        }

        $entries = Forminator_API::{$api['get']}($module_id);
        $entries = is_array($entries) ? $entries : [];

        // Paged here rather than in the API call: Forminator's getters return
        // everything, and handing back ten thousand entries is not an answer.
        $page = nibwp_fmtr_paginate($input);
        $slice = array_slice($entries, ($page['page'] - 1) * $page['per_page'], $page['per_page']);

        return [
            'module'    => $module,
            'module_id' => $module_id,
            'entries'   => $slice,
            'count'     => count($slice),
            'total'     => count($entries),
        ];
    });
}

/* ── Ability 5 — settings ───────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-settings', [
    'label'       => __('Forminator — Form settings', domain: 'nibwp'),
    'description' => __('Read and change a form\'s settings, including its email notifications.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'set', 'set_one'], 'default' => 'get'],
            'form_id' => ['type' => 'integer'],
            'settings' => ['type' => 'object', 'description' => 'set: settings to merge.'],
            'key'     => ['type' => 'string', 'description' => 'set_one: the setting name.'],
            'value'   => ['description' => 'set_one: the new value.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Notifications live in here under `notifications`. That is what decides whether anyone hears about a submission.',
                'set_one changes a single setting and is the safer choice; set merges an object and can rewrite more than intended.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fmtr_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_fmtr_err('nibwp_fmtr_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_fmtr_try(static function () use ($form_id, $input) {
        $action = (string) ($input['action'] ?? 'get');

        if ($action === 'get') {
            $form = Forminator_API::get_form($form_id);
            if (is_wp_error($form)) {
                return $form;
            }

            $settings = (array) ($form->settings ?? []);
            $notifications = (array) ($settings['notifications'] ?? []);

            return [
                'form_id'  => $form_id,
                'settings' => $settings,
                'notification_count' => count($notifications),
                'warning'  => $notifications === []
                    ? __('No notifications configured. Entries are stored and nobody is emailed.', domain: 'nibwp')
                    : '',
            ];
        }

        if ($action === 'set_one') {
            $key = (string) ($input['key'] ?? '');
            if ($key === '') {
                throw new \RuntimeException(__('A setting key is required.', domain: 'nibwp'));
            }
            $result = Forminator_API::update_form_setting($form_id, $key, $input['value'] ?? null);

            return is_wp_error($result) ? $result : ['form_id' => $form_id, 'key' => $key, 'updated' => true];
        }

        $settings = (array) ($input['settings'] ?? []);
        if ($settings === []) {
            throw new \RuntimeException(__('Nothing to set.', domain: 'nibwp'));
        }
        $result = Forminator_API::update_form_settings($form_id, $settings);

        return is_wp_error($result) ? $result : ['form_id' => $form_id, 'updated' => true];
    });
}

/* ── Ability 6 — polls, Ability 7 — quizzes ─────────────────────────── */

wp_register_ability('nibwp/forminator-polls', [
    'label'       => __('Forminator — Polls', domain: 'nibwp'),
    'description' => __('List, read, create and update Forminator polls.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update'], 'default' => 'list'],
            'poll_id' => ['type' => 'integer'],
            'name'    => ['type' => 'string'],
            'settings' => ['type' => 'object'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_polls',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Polls are a separate module type from forms — a site can have many while its form list looks short. Poll results are read through nibwp/forminator-entries with module=poll.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fmtr_polls(array $input): array|WP_Error
{
    return nibwp_fmtr_module($input, 'poll');
}

wp_register_ability('nibwp/forminator-quizzes', [
    'label'       => __('Forminator — Quizzes', domain: 'nibwp'),
    'description' => __('List, read, create and update Forminator quizzes.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update'], 'default' => 'list'],
            'quiz_id' => ['type' => 'integer'],
            'name'    => ['type' => 'string'],
            'settings' => ['type' => 'object'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_quizzes',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Quizzes are a separate module type. Results are read through nibwp/forminator-entries with module=quiz.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fmtr_quizzes(array $input): array|WP_Error
{
    return nibwp_fmtr_module($input, 'quiz');
}

/**
 * Polls and quizzes are the same shape of module behind a different set of API
 * method names. One implementation so they cannot drift apart.
 *
 * @return array|WP_Error
 */
function nibwp_fmtr_module(array $input, string $module)
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    $id_key = $module . '_id';
    $id = (int) ($input[$id_key] ?? 0);
    $action = (string) ($input['action'] ?? 'list');

    if (in_array($action, ['get', 'update'], strict: true) && $id <= 0) {
        return nibwp_fmtr_err('nibwp_fmtr_bad_id', __('A valid ID is required.', domain: 'nibwp'));
    }

    return nibwp_fmtr_try(static function () use ($module, $id, $action, $input) {
        $plural = $module . 's';
        $getters = ['list' => 'get_' . $plural, 'one' => 'get_' . $module, 'add' => 'add_' . $module, 'update' => 'update_' . $module];

        foreach ($getters as $method) {
            if (!method_exists('Forminator_API', $method)) {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: the API method */
                    __('This Forminator version does not expose %s.', domain: 'nibwp'),
                    $method
                ));
            }
        }

        switch ($action) {
            case 'list':
                $page = nibwp_fmtr_paginate($input);
                $items = Forminator_API::{$getters['list']}(null, $page['page'], $page['per_page']);
                $rows = array_map('nibwp_fmtr_summary', is_array($items) ? $items : []);

                return [$plural => $rows, 'count' => count($rows)];

            case 'get':
                return ['id' => $id, $module => Forminator_API::{$getters['one']}($id)];

            case 'create':
                $new_id = Forminator_API::{$getters['add']}(
                    (string) ($input['name'] ?? __('Untitled', domain: 'nibwp')),
                    [],
                    (array) ($input['settings'] ?? [])
                );

                return is_wp_error($new_id) ? $new_id : ['id' => (int) $new_id, 'created' => true];

            case 'update':
                $result = Forminator_API::{$getters['update']}($id, null, (array) ($input['settings'] ?? []));

                return is_wp_error($result) ? $result : ['id' => $id, 'updated' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ── Ability 8 — audit ──────────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-audit', [
    'label'       => __('Forminator — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the faults that lose enquiries: no notifications, and no fields (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Forminator stores every entry, so a form with no notification looks perfectly healthy — the enquiries simply accumulate unread. That is the fault this finds.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fmtr_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    return nibwp_fmtr_try(static function () use ($input): array {
        $form_id = (int) ($input['form_id'] ?? 0);

        if ($form_id > 0) {
            $one = Forminator_API::get_form($form_id);
            $forms = is_wp_error($one) ? [] : [$one];
        } else {
            $forms = Forminator_API::get_forms(null, 1, 200);
            $forms = is_array($forms) ? $forms : [];
        }

        $findings = [];

        foreach ($forms as $form) {
            $id = (int) ($form->id ?? 0);
            $settings = (array) ($form->settings ?? []);
            $name = (string) ($settings['formName'] ?? ($form->name ?? ''));

            if ((array) ($settings['notifications'] ?? []) === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'blocker',
                    'code'     => 'no_notifications',
                    'message'  => __('No email notifications. Entries are stored but nobody is told, so they accumulate unread.', domain: 'nibwp'),
                    'fix'      => __('Add one through the form settings, then confirm with nibwp/forminator-settings.', domain: 'nibwp'),
                ];
            }

            $fields = Forminator_API::get_form_fields($id);
            if (is_wp_error($fields) || (array) $fields === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('The form has no fields, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add fields with nibwp/forminator-fields.', domain: 'nibwp'),
                ];
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'forms_checked' => count($forms),
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
        ];
    });
}

/* ── Ability 9 — delete ─────────────────────────────────────────────── */

wp_register_ability('nibwp/forminator-delete', [
    'label'       => __('Forminator — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete a form, poll, quiz or entry. Irreversible.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['delete_module', 'delete_entry', 'delete_entries']],
            'module'    => ['type' => 'string', 'enum' => ['form', 'poll', 'quiz'], 'default' => 'form'],
            'module_id' => ['type' => 'integer'],
            'entry_id'  => ['type' => 'integer'],
            'confirm'   => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_fmtr_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Deleting a module takes every entry it holds with it. delete_entries empties a module without removing it, which is still irreversible.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fmtr_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_fmtr_guard()) {
        return $guard;
    }

    if (!(bool) ($input['confirm'] ?? false)) {
        return nibwp_fmtr_err(
            'nibwp_fmtr_unconfirmed',
            __('This permanently destroys data and cannot be undone. Re-issue with confirm true if that is intended.', domain: 'nibwp')
        );
    }

    return nibwp_fmtr_try(static function () use ($input) {
        $action = (string) ($input['action'] ?? '');
        $module = (string) ($input['module'] ?? 'form');
        $module_id = (int) ($input['module_id'] ?? 0);

        if ($module_id <= 0) {
            throw new \RuntimeException(__('A valid module ID is required.', domain: 'nibwp'));
        }

        if ($action === 'delete_module') {
            $method = 'delete_' . $module;
            if (!method_exists('Forminator_API', $method)) {
                throw new \RuntimeException(__('This Forminator version cannot delete that module type.', domain: 'nibwp'));
            }
            $result = Forminator_API::{$method}($module_id);

            return is_wp_error($result) ? $result : ['module' => $module, 'module_id' => $module_id, 'deleted' => true, 'reversible' => false];
        }

        if ($action === 'delete_entry') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            if ($entry_id <= 0) {
                throw new \RuntimeException(__('A valid entry ID is required.', domain: 'nibwp'));
            }
            $method = 'delete_' . $module . '_entry';
            $result = Forminator_API::{$method}($module_id, $entry_id);

            return is_wp_error($result) ? $result : ['entry_id' => $entry_id, 'deleted' => true, 'reversible' => false];
        }

        $method = 'delete_' . $module . '_entries';
        $result = Forminator_API::{$method}($module_id);

        return is_wp_error($result) ? $result : ['module' => $module, 'module_id' => $module_id, 'entries_deleted' => true, 'reversible' => false];
    });
}
