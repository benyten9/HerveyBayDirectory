<?php

declare(strict_types=1);

/**
 * Formidable Forms integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Ten abilities: forms, fields, entries, entry meta, actions, settings,
 * export, analytics, an audit and deletion.
 *
 * Formidable keeps a form's behavior in `frm_form_actions` posts rather than
 * inside the form row — one post per action, with the form ID in post_menu_order
 * and the configuration in post_content. That is where email notifications live,
 * so a form with none accepts entries and notifies nobody, and nothing about the
 * form row itself reveals it.
 *
 * Mechanism is IN-PROCESS through Formidable's own model classes:
 *   FrmForm::get_published_forms() / ::getOne() / ::create() / ::update()
 *   FrmField::get_all_for_form() / ::create() / ::update() / ::destroy()
 *   FrmEntry::getAll() / ::getOne() / ::destroy() / ::get_entries_count()
 *   FrmEntryMeta::get_entry_meta_info()
 *
 * Detection: class_exists('FrmForm'). Pro gates on FrmProDb, which is how
 * Formidable itself distinguishes the editions.
 *
 * Verified against Formidable Forms 6.34 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Is Formidable active? */
function nibwp_frm_available(): bool
{
    return class_exists('FrmForm') && class_exists('FrmField');
}

/** Is Formidable Pro active? */
function nibwp_frm_pro(): bool
{
    return class_exists('FrmProDb');
}

/** House WP_Error wrapper. */
function nibwp_frm_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_frm_guard(): ?WP_Error
{
    if (!nibwp_frm_available()) {
        return nibwp_frm_err('nibwp_frm_missing', __('Formidable Forms is not active on this site.', domain: 'nibwp'));
    }

    return null;
}

/** Run a Formidable call, converting throwables into WP_Error. */
function nibwp_frm_try(callable $fn, string $code = 'nibwp_frm_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_frm_err($code, $e->getMessage());
    }
}

/**
 * Load a form row.
 *
 * @return object|WP_Error
 */
function nibwp_frm_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_frm_err('nibwp_frm_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $form = FrmForm::getOne($form_id);

    if (!$form || !is_object($form)) {
        return nibwp_frm_err('nibwp_frm_not_found', __('No Formidable form with that ID.', domain: 'nibwp'));
    }

    return $form;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_frm_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/**
 * The form actions attached to a form.
 *
 * Formidable stores each as a `frm_form_actions` post whose post_menu_order is
 * the form ID. That is not obvious from anywhere else, and it is why a form's
 * notifications cannot be read off the form row.
 *
 * @return list<array<string, mixed>>
 */
function nibwp_frm_actions(int $form_id): array
{
    $posts = get_posts([
        'post_type'      => 'frm_form_actions',
        'post_status'    => 'any',
        'numberposts'    => 100,
        'menu_order'     => $form_id,
        'orderby'        => 'menu_order',
    ]);

    $out = [];
    foreach ($posts as $post) {
        if ((int) $post->menu_order !== $form_id) {
            continue;
        }

        $settings = json_decode((string) $post->post_content, true);

        $out[] = [
            'id'       => (int) $post->ID,
            'type'     => (string) $post->post_excerpt,
            'name'     => (string) $post->post_title,
            'active'   => $post->post_status === 'publish',
            'settings' => is_array($settings) ? $settings : null,
        ];
    }

    return $out;
}

/* ── Ability 1 — info ───────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-info', [
    'label'       => __('Formidable — Info', domain: 'nibwp'),
    'description' => __('Detect Formidable Forms, whether Pro is active, and how many forms and entries exist (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call this first. Formidable stores entries in its own tables, so there is always a history — but a form\'s notifications live in separate frm_form_actions posts, so read nibwp/formidable-actions before assuming anyone is told about a submission.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_frm_info(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    return nibwp_frm_try(static function (): array {
        return [
            'active'      => true,
            'version'     => defined('FrmAppHelper::plugin_version') ? '' : (class_exists('FrmAppHelper') ? (string) FrmAppHelper::plugin_version() : ''),
            'edition'     => nibwp_frm_pro() ? 'pro' : 'lite',
            'form_count'  => (int) FrmForm::get_count(),
            'stores_entries' => true,
        ];
    });
}

/* ── Ability 2 — forms ──────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-forms', [
    'label'       => __('Formidable — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, update, duplicate and trash Formidable forms, and get the shortcode for embedding one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'duplicate', 'trash', 'shortcode'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'name'    => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'values'  => ['type' => 'object', 'description' => 'update: fields to change on the form row.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get returns the form with its field and action counts — the action count is the one that tells you whether the form does anything on submit.',
                'A new form has no actions, so submissions are stored and nobody is emailed.',
                'trash is reversible from the Formidable admin; permanent deletion lives in nibwp/formidable-delete.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_frm_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'duplicate', 'trash', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_frm_err('nibwp_frm_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_frm_try(static function () use ($action, $form_id, $input) {
        if ($action === 'list') {
            $forms = FrmForm::get_published_forms();

            $rows = [];
            foreach ((array) $forms as $form) {
                $id = (int) ($form->id ?? 0);
                $rows[] = [
                    'id'        => $id,
                    'name'      => (string) ($form->name ?? ''),
                    'key'       => (string) ($form->form_key ?? ''),
                    'actions'   => count(nibwp_frm_actions($id)),
                    'shortcode' => sprintf('[formidable id=%d]', $id),
                ];
            }

            return ['forms' => $rows, 'count' => count($rows)];
        }

        if ($action === 'create') {
            $new_id = FrmForm::create([
                'name'        => (string) ($input['name'] ?? __('Untitled form', domain: 'nibwp')),
                'description' => (string) ($input['description'] ?? ''),
            ]);

            return [
                'form_id' => (int) $new_id,
                'created' => true,
                'note'    => __('No actions yet, so submissions will be stored and nobody emailed.', domain: 'nibwp'),
            ];
        }

        $form = nibwp_frm_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        switch ($action) {
            case 'get':
                $fields = FrmField::get_all_for_form($form_id);

                return [
                    'form_id'      => $form_id,
                    'name'         => (string) ($form->name ?? ''),
                    'key'          => (string) ($form->form_key ?? ''),
                    'description'  => (string) ($form->description ?? ''),
                    'field_count'  => is_array($fields) ? count($fields) : 0,
                    'action_count' => count(nibwp_frm_actions($form_id)),
                    'shortcode'    => sprintf('[formidable id=%d]', $form_id),
                ];

            case 'update':
                $values = (array) ($input['values'] ?? []);
                if (isset($input['name'])) {
                    $values['name'] = (string) $input['name'];
                }
                if ($values === []) {
                    throw new \RuntimeException(__('Nothing to update.', domain: 'nibwp'));
                }
                FrmForm::update($form_id, $values);

                return ['form_id' => $form_id, 'updated' => true];

            case 'duplicate':
                $new_id = FrmForm::duplicate($form_id);

                return ['form_id' => (int) $new_id, 'duplicated_from' => $form_id];

            case 'trash':
                FrmForm::set_status($form_id, 'trash');

                return ['form_id' => $form_id, 'trashed' => true, 'reversible' => true];

            case 'shortcode':
                return ['form_id' => $form_id, 'shortcode' => sprintf('[formidable id=%d]', $form_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ── Ability 3 — fields ─────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-fields', [
    'label'       => __('Formidable — Fields', domain: 'nibwp'),
    'description' => __('List, read, add, update and delete the fields on a form.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer'],
            'field_id' => ['type' => 'integer'],
            'field'    => ['type' => 'object', 'description' => 'add/update: type and name at minimum for add.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Entries store values against field IDs, so deleting a field leaves the values already recorded against it unreachable through the form.',
                'Actions reference fields by ID in shortcodes like [123]. Removing a field leaves those pointing at nothing, and they render empty rather than erroring.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_frm_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_frm_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? 'list');
    $field_id = (int) ($input['field_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'delete'], strict: true) && $field_id <= 0) {
        return nibwp_frm_err('nibwp_frm_bad_id', __('A valid field ID is required.', domain: 'nibwp'));
    }

    return nibwp_frm_try(static function () use ($form_id, $action, $field_id, $input) {
        if ($action === 'list') {
            $fields = FrmField::get_all_for_form($form_id);

            $rows = [];
            foreach ((array) $fields as $field) {
                $rows[] = [
                    'id'       => (int) ($field->id ?? 0),
                    'key'      => (string) ($field->field_key ?? ''),
                    'type'     => (string) ($field->type ?? ''),
                    'name'     => (string) ($field->name ?? ''),
                    'required' => (bool) ($field->required ?? false),
                ];
            }

            return ['form_id' => $form_id, 'fields' => $rows, 'count' => count($rows)];
        }

        if ($action === 'get') {
            $field = FrmField::getOne($field_id);
            if (!$field) {
                throw new \RuntimeException(__('No field with that ID.', domain: 'nibwp'));
            }

            return ['form_id' => $form_id, 'field' => $field];
        }

        if ($action === 'add') {
            $new = (array) ($input['field'] ?? []);
            if (($new['type'] ?? '') === '') {
                throw new \RuntimeException(__('A field type is required — text, email, textarea, select, checkbox, and so on.', domain: 'nibwp'));
            }
            $new['form_id'] = $form_id;
            $new_id = FrmField::create($new);

            return ['form_id' => $form_id, 'field_id' => (int) $new_id, 'created' => true];
        }

        if ($action === 'update') {
            $changes = (array) ($input['field'] ?? []);
            unset($changes['id'], $changes['form_id']);
            FrmField::update($field_id, $changes);

            return ['form_id' => $form_id, 'field_id' => $field_id, 'updated' => true];
        }

        FrmField::destroy($field_id);

        return [
            'form_id'  => $form_id,
            'field_id' => $field_id,
            'deleted'  => true,
            'note'     => __('Values already recorded against this field remain in past entries but are no longer reachable through the form.', domain: 'nibwp'),
        ];
    });
}

/* ── Ability 4 — entries ────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-entries', [
    'label'       => __('Formidable — Entries', domain: 'nibwp'),
    'description' => __('List, read and count entries, and read the values recorded against each field.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'count'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer'],
            'entry_id' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_entries',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Entries are personal data. Read what the task needs and do not copy it somewhere it was not collected for.',
                'Values are keyed by field ID — read nibwp/formidable-fields to know which is which.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_frm_entries(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_frm_err('nibwp_frm_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_frm_try(static function () use ($form_id, $input) {
        $action = (string) ($input['action'] ?? 'list');

        if ($action === 'count') {
            return ['form_id' => $form_id, 'total' => (int) FrmEntry::getRecordCount($form_id)];
        }

        if ($action === 'get') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            if ($entry_id <= 0) {
                throw new \RuntimeException(__('A valid entry ID is required.', domain: 'nibwp'));
            }

            $entry = FrmEntry::getOne($entry_id, true);
            if (!$entry) {
                throw new \RuntimeException(__('No entry with that ID.', domain: 'nibwp'));
            }

            return ['entry_id' => $entry_id, 'entry' => $entry];
        }

        $page = nibwp_frm_paginate($input);
        $entries = FrmEntry::getAll(
            ['it.form_id' => $form_id],
            ' ORDER BY it.created_at DESC',
            $page['per_page'] . ' OFFSET ' . (($page['page'] - 1) * $page['per_page']),
            true
        );

        $rows = [];
        foreach ((array) $entries as $entry) {
            $rows[] = [
                'id'         => (int) ($entry->id ?? 0),
                'created_at' => (string) ($entry->created_at ?? ''),
                'is_draft'   => (bool) ($entry->is_draft ?? false),
                'values'     => $entry->metas ?? null,
            ];
        }

        return ['form_id' => $form_id, 'entries' => $rows, 'count' => count($rows)];
    });
}

/* ── Ability 5 — actions ────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-actions', [
    'label'       => __('Formidable — Form actions', domain: 'nibwp'),
    'description' => __('Read what a form does when submitted — email notifications, autoresponders and any other configured action.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'get', 'toggle'], 'default' => 'list'],
            'form_id'   => ['type' => 'integer'],
            'action_id' => ['type' => 'integer', 'description' => 'The action post ID, as returned by list.'],
            'enabled'   => ['type' => 'boolean', 'description' => 'toggle: whether the action runs.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_actions_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This decides whether anyone learns about a submission. A form with no active action stores entries silently.',
                'Actions are separate frm_form_actions posts, not part of the form row, which is why they cannot be read off the form.',
                'Only toggling is offered for writes. An action\'s settings shape belongs to its type, and a wrong shape is stored and then silently does nothing — configure those in the Formidable editor.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_frm_actions_ability(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_frm_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_frm_try(static function () use ($form_id, $input) {
        $action = (string) ($input['action'] ?? 'list');
        $actions = nibwp_frm_actions($form_id);

        if ($action === 'list') {
            $active = array_filter($actions, static fn(array $a): bool => $a['active']);

            return [
                'form_id' => $form_id,
                'actions' => $actions,
                'count'   => count($actions),
                'active'  => count($active),
                'warning' => $active === []
                    ? __('No active action. Entries are stored and nobody is told about them.', domain: 'nibwp')
                    : '',
            ];
        }

        $action_id = (int) ($input['action_id'] ?? 0);
        if ($action_id <= 0) {
            throw new \RuntimeException(__('An action_id is required. List them first.', domain: 'nibwp'));
        }

        $match = null;
        foreach ($actions as $candidate) {
            if ($candidate['id'] === $action_id) {
                $match = $candidate;
            }
        }

        if ($match === null) {
            throw new \RuntimeException(__('That action does not belong to this form.', domain: 'nibwp'));
        }

        if ($action === 'get') {
            return ['form_id' => $form_id, 'action' => $match];
        }

        $enabled = (bool) ($input['enabled'] ?? true);
        wp_update_post(['ID' => $action_id, 'post_status' => $enabled ? 'publish' : 'draft']);

        return ['form_id' => $form_id, 'action_id' => $action_id, 'active' => $enabled];
    });
}

/* ── Ability 6 — export ─────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-export', [
    'label'       => __('Formidable — Export entries', domain: 'nibwp'),
    'description' => __('Export a form\'s entries as rows or CSV, with field names as headers (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id'  => ['type' => 'integer'],
            'format'   => ['type' => 'string', 'enum' => ['rows', 'csv'], 'default' => 'rows'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_export',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Paginated on purpose. Personal data leaving its original context — export the columns the task needs, not everything.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_frm_export(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_frm_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_frm_try(static function () use ($form_id, $input) {
        $page = nibwp_frm_paginate($input);

        $labels = [];
        foreach ((array) FrmField::get_all_for_form($form_id) as $field) {
            $labels[(int) ($field->id ?? 0)] = (string) ($field->name ?? ('Field ' . ($field->id ?? '')));
        }

        $entries = FrmEntry::getAll(
            ['it.form_id' => $form_id],
            ' ORDER BY it.created_at DESC',
            $page['per_page'] . ' OFFSET ' . (($page['page'] - 1) * $page['per_page']),
            true
        );

        $rows = [];
        foreach ((array) $entries as $entry) {
            $record = ['Entry ID' => (int) ($entry->id ?? 0), 'Date' => (string) ($entry->created_at ?? '')];
            $metas = (array) ($entry->metas ?? []);

            foreach ($labels as $field_id => $label) {
                $value = $metas[$field_id] ?? '';
                $record[$label] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }

            $rows[] = $record;
        }

        $columns = array_merge(['Entry ID', 'Date'], array_values($labels));

        if ((string) ($input['format'] ?? 'rows') === 'csv') {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                throw new \RuntimeException(__('Could not open a buffer to build the CSV.', domain: 'nibwp'));
            }
            fputcsv($handle, $columns);
            foreach ($rows as $record) {
                fputcsv($handle, array_values($record));
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return ['form_id' => $form_id, 'format' => 'csv', 'csv' => $csv, 'count' => count($rows)];
        }

        return ['form_id' => $form_id, 'format' => 'rows', 'columns' => $columns, 'rows' => $rows, 'count' => count($rows)];
    });
}

/* ── Ability 7 — audit ──────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-audit', [
    'label'       => __('Formidable — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the faults that lose enquiries: no active action, and no fields (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'A Formidable form with no active action still stores every entry, so nothing looks broken — the enquiries simply pile up in the admin unread. That is the fault this looks for.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_frm_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    return nibwp_frm_try(static function () use ($input): array {
        $form_id = (int) ($input['form_id'] ?? 0);

        $forms = $form_id > 0
            ? array_filter([FrmForm::getOne($form_id)])
            : (array) FrmForm::get_published_forms();

        $findings = [];

        foreach ($forms as $form) {
            $id = (int) ($form->id ?? 0);
            $name = (string) ($form->name ?? '');

            $actions = nibwp_frm_actions($id);
            $active = array_filter($actions, static fn(array $a): bool => $a['active']);

            if ($active === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'blocker',
                    'code'     => 'no_active_action',
                    'message'  => __('No active action. Entries are stored but nobody is emailed, so the enquiries sit unread in the admin.', domain: 'nibwp'),
                    'fix'      => __('Add or enable an email action in the Formidable editor, then confirm with nibwp/formidable-actions.', domain: 'nibwp'),
                ];
            }

            $fields = FrmField::get_all_for_form($id);
            if (!is_array($fields) || $fields === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $name,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('The form has no fields, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add fields with nibwp/formidable-fields.', domain: 'nibwp'),
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

/* ── Ability 8 — delete ─────────────────────────────────────────────── */

wp_register_ability('nibwp/formidable-delete', [
    'label'       => __('Formidable — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete a form or an entry. Irreversible.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['delete_form', 'delete_entry']],
            'form_id'  => ['type' => 'integer'],
            'entry_id' => ['type' => 'integer'],
            'confirm'  => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_frm_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Prefer trashing a form with nibwp/formidable-forms, which is reversible. Deleting one takes every entry it holds with it.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_frm_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_frm_guard()) {
        return $guard;
    }

    if (!(bool) ($input['confirm'] ?? false)) {
        return nibwp_frm_err(
            'nibwp_frm_unconfirmed',
            __('This permanently destroys data and cannot be undone. Trashing a form is reversible; re-issue with confirm true if deletion is intended.', domain: 'nibwp')
        );
    }

    return nibwp_frm_try(static function () use ($input) {
        $action = (string) ($input['action'] ?? '');

        if ($action === 'delete_form') {
            $form_id = (int) ($input['form_id'] ?? 0);
            $form = nibwp_frm_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            $entries = (int) FrmEntry::getRecordCount($form_id);
            FrmForm::destroy($form_id);

            return ['form_id' => $form_id, 'deleted' => true, 'entries_deleted' => $entries, 'reversible' => false];
        }

        if ($action === 'delete_entry') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            if ($entry_id <= 0) {
                throw new \RuntimeException(__('A valid entry ID is required.', domain: 'nibwp'));
            }
            FrmEntry::destroy($entry_id);

            return ['entry_id' => $entry_id, 'deleted' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
