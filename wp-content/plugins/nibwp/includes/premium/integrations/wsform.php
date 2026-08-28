<?php

declare(strict_types=1);

/**
 * WS Form integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Fourteen domain-grouped abilities expose full read/write control of WS Form
 * to an AI agent: forms, their JSON, fields, tabs, sections, actions,
 * submissions, exports, styles, templates, data sources and plugin settings.
 *
 * Mechanism is IN-PROCESS — these run inside the same WP request as the MCP
 * call, so we use WS Form's own PHP classes rather than its REST API:
 *   1. WS_Form_Form / _Field / _Group / _Section / _Submit / _Style (db_* methods)
 *   2. WS_Form_Common for meta access, WS_Form_Config for configuration
 *   3. WS_Form_Submit_Export for submission rows
 * Objects follow one convention throughout: construct, assign ->id, call db_*.
 *
 * WS Form 1.12 registers ~35 abilities of its own under the `ws-form/`
 * namespace when the WordPress Abilities API is present (WP 6.9+). This file
 * deliberately covers the same ground under `nibwp/wsform-*`, for three
 * reasons: it works below WP 6.9 and below WS Form 1.12 where the native set
 * never registers at all; it groups by domain so a client sees fourteen tools
 * rather than thirty-five; and it reaches subsystems the native set does not
 * expose — actions, submission writes and deletes, exports, templates and data
 * sources. The namespaces are distinct, so both can be live at once.
 *
 * Detection: WS_FORM_VERSION.
 *
 * Verified against WS Form 1.12.3 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is WS Form active on this site? */
function nibwp_wsform_available(): bool
{
    return defined('WS_FORM_VERSION') && class_exists('WS_Form_Form');
}

/** House WP_Error wrapper. */
function nibwp_wsform_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_wsform_guard(): ?WP_Error
{
    if (!nibwp_wsform_available()) {
        return nibwp_wsform_err(
            'nibwp_wsform_missing',
            __('WS Form is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_wsform_paginate(array $in): array
{
    return [
        'limit'  => min(max((int) ($in['per_page'] ?? 20), 1), 100),
        'offset' => (max((int) ($in['page'] ?? 1), 1) - 1) * min(max((int) ($in['per_page'] ?? 20), 1), 100),
    ];
}

/**
 * Read a form object by ID.
 *
 * db_read() throws when the ID does not exist, which is a better failure than
 * the null it would otherwise return three calls later.
 *
 * @return object|WP_Error
 */
function nibwp_wsform_form_object(int $form_id, bool $published = false)
{
    if ($form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    try {
        $form = new WS_Form_Form();
        $form->id = $form_id;

        return $published ? $form->db_read_published(true, true) : $form->db_read(true, true);
    } catch (\Throwable $e) {
        return nibwp_wsform_err('nibwp_wsform_read_failed', $e->getMessage());
    }
}

/**
 * Run a WS Form call and convert its exceptions into WP_Error.
 *
 * WS Form signals every failure by throwing, including ordinary ones like an
 * unknown ID or a capability refusal. Left alone those surface as a fatal in
 * the MCP request rather than as a result the agent can read and act on.
 *
 * @param callable $fn
 * @return mixed|WP_Error
 */
function nibwp_wsform_try(callable $fn, string $code = 'nibwp_wsform_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_wsform_err($code, $e->getMessage());
    }
}

/** Cast the string columns $wpdb hands back into the types a schema promises. */
function nibwp_wsform_int_ids(array $rows, string $key = 'id'): array
{
    foreach ($rows as $i => $row) {
        if (is_array($row) && isset($row[$key])) {
            $rows[$i][$key] = (int) $row[$key];
        } elseif (is_object($row) && isset($row->{$key})) {
            $rows[$i]->{$key} = (int) $row->{$key};
        }
    }

    return $rows;
}

/**
 * Read one meta value off a WS Form object.
 *
 * Actions, conditional logic and most form configuration live in object meta
 * rather than in columns, so almost everything interesting goes through here.
 *
 * @return mixed
 */
function nibwp_wsform_meta(object $object, string $key, $default = '')
{
    if (!class_exists('WS_Form_Common')) {
        return $default;
    }

    return WS_Form_Common::get_object_meta_value($object, $key, $default);
}

/** Are WS Form's own `ws-form/` abilities registered on this request? */
function nibwp_wsform_native_abilities_live(): bool
{
    if (!function_exists('wp_get_abilities')) {
        return false;
    }

    foreach (wp_get_abilities() as $ability) {
        $name = is_object($ability) && method_exists($ability, 'get_name')
            ? $ability->get_name()
            : (string) $ability;

        if (str_starts_with($name, 'ws-form/')) {
            return true;
        }
    }

    return false;
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/wsform-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-info', [
    'label'       => __('WS Form — Info', domain: 'nibwp'),
    'description' => __('Detect WS Form, its edition and version, form and submission counts, and whether its own abilities are registered (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. It reports whether WS Form is active and what this site can do.',
                'native_abilities tells you whether WS Form registers its own ws-form/* tools; when true you may use either set.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wsform_info(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    return nibwp_wsform_try(static function (): array {
        $form = new WS_Form_Form();
        $forms = $form->get_all(false, 'label', 'ASC');
        $forms = is_array($forms) ? $forms : [];

        $counts = [];
        if (method_exists($form, 'db_get_count_by_status')) {
            $counts = (array) $form->db_get_count_by_status();
        }

        $unread = method_exists($form, 'db_get_count_submit_unread_total')
            ? (int) $form->db_get_count_submit_unread_total()
            : 0;

        return [
            'active'            => true,
            'version'           => defined('WS_FORM_VERSION') ? WS_FORM_VERSION : '',
            'edition'           => defined('WS_FORM_PRO') ? 'pro' : 'basic',
            'form_count'        => count($forms),
            'form_status_counts' => $counts,
            'submissions_unread' => $unread,
            'native_abilities'  => nibwp_wsform_native_abilities_live(),
            'abilities_api'     => function_exists('wp_register_ability'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/wsform-forms (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-forms', [
    'label'       => __('WS Form — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, update, clone, publish, draft, restore and count WS Form forms, and get their shortcode or block markup.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    'list', 'get', 'create', 'create_from_template', 'update_label',
                    'clone', 'publish', 'draft', 'restore', 'set_status',
                    'stats', 'shortcode', 'block', 'locations', 'counts',
                ],
                'description' => 'The action to perform.',
            ],
            'id'          => ['type' => 'integer', 'description' => 'Form ID. Required for everything except list, create, create_from_template and counts.'],
            'label'       => ['type' => 'string', 'description' => 'Form label. Used by create and update_label.'],
            'template'    => ['type' => 'string', 'description' => 'Template ID for create_from_template.'],
            'status'      => ['type' => 'string', 'enum' => ['publish', 'draft', 'trash'], 'description' => 'Target status for set_status.'],
            'published'   => ['type' => 'boolean', 'default' => false, 'description' => 'list: return only published forms.'],
            'order_by'    => ['type' => 'string', 'enum' => ['label', 'id'], 'default' => 'label'],
            'order'       => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'ASC'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Form lifecycle. A new form is created empty — add fields with nibwp/wsform-fields, or build the whole thing at once with nibwp/wsform-json.',
                'publish makes a draft live; draft takes a published form back out of circulation without deleting it.',
                'Deleting a form lives in nibwp/wsform-delete, not here.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $id = (int) ($input['id'] ?? 0);

    $needs_id = ['get', 'update_label', 'clone', 'publish', 'draft', 'restore', 'set_status', 'stats', 'shortcode', 'block', 'locations'];
    if (in_array($action, $needs_id, strict: true) && $id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required for this action.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $id, $input) {
        $form = new WS_Form_Form();
        $form->id = $id;

        switch ($action) {
            case 'list':
                $rows = $form->get_all(
                    (bool) ($input['published'] ?? false),
                    (string) ($input['order_by'] ?? 'label'),
                    (string) ($input['order'] ?? 'ASC')
                );

                return ['forms' => nibwp_wsform_int_ids(is_array($rows) ? $rows : [])];

            case 'get':
                $object = $form->db_read(true, true);

                return [
                    'id'     => $id,
                    'label'  => $object->label ?? '',
                    'status' => $object->status ?? '',
                    'form'   => $object,
                ];

            case 'create':
                $form->label = (string) ($input['label'] ?? __('New form', domain: 'nibwp'));
                $new_id = (int) $form->db_create();

                return ['id' => $new_id, 'created' => true];

            case 'create_from_template':
                $template = (string) ($input['template'] ?? '');
                if ($template === '') {
                    throw new \RuntimeException(__('A template ID is required. List them with nibwp/wsform-templates.', domain: 'nibwp'));
                }
                $new_id = (int) $form->db_create_from_template($template);

                return ['id' => $new_id, 'template' => $template, 'created' => true];

            case 'update_label':
                $object = $form->db_read(true, true);
                $object->label = (string) ($input['label'] ?? '');
                $form->db_update_from_object($object);

                return ['id' => $id, 'label' => $object->label, 'updated' => true];

            case 'clone':
                $new_id = (int) $form->db_clone();

                return ['id' => $new_id, 'cloned_from' => $id];

            case 'publish':
                $form->db_publish();

                return ['id' => $id, 'status' => 'publish'];

            case 'draft':
                $form->db_draft();

                return ['id' => $id, 'status' => 'draft'];

            case 'restore':
                $form->db_restore();

                return ['id' => $id, 'restored' => true];

            case 'set_status':
                $status = (string) ($input['status'] ?? '');
                if ($status === '') {
                    throw new \RuntimeException(__('A status is required.', domain: 'nibwp'));
                }
                $form->db_set_status($status);

                return ['id' => $id, 'status' => $status];

            case 'stats':
                $stat = class_exists('WS_Form_Form_Stat') ? new WS_Form_Form_Stat() : null;
                if ($stat === null) {
                    throw new \RuntimeException(__('Form statistics are unavailable in this WS Form edition.', domain: 'nibwp'));
                }
                $stat->form_id = $id;

                return ['id' => $id, 'stats' => method_exists($stat, 'db_read') ? $stat->db_read() : []];

            case 'shortcode':
                return ['id' => $id, 'shortcode' => sprintf('[ws_form id="%d"]', $id)];

            case 'block':
                return [
                    'id'    => $id,
                    'block' => sprintf('<!-- wp:wsf/form {"formId":"%d"} /-->', $id),
                ];

            case 'locations':
                return [
                    'id'        => $id,
                    'locations' => method_exists($form, 'db_get_locations') ? $form->db_get_locations() : [],
                ];

            case 'counts':
                return ['counts' => (array) $form->db_get_count_by_status()];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/wsform-json (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-json', [
    'label'       => __('WS Form — Form JSON', domain: 'nibwp'),
    'description' => __('Read a whole form as JSON, create a form from JSON, or replace an existing form\'s JSON in one call.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['get', 'create', 'update', 'checksum'],
                'description' => 'The action to perform.',
            ],
            'id'   => ['type' => 'integer', 'description' => 'Form ID. Required for get, update and checksum.'],
            'json' => ['type' => 'string', 'description' => 'The form definition as a JSON string. Required for create and update.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_json',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'The fastest way to build a complete form: get the JSON of one that already works, adapt it, then create.',
                'update REPLACES the form definition. Read the current JSON first if you intend to keep any of it.',
                'The JSON carries fields, tabs, sections, actions and conditional logic together.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_json(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $id = (int) ($input['id'] ?? 0);

    if (in_array($action, ['get', 'update', 'checksum'], strict: true) && $id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    if (in_array($action, ['create', 'update'], strict: true)) {
        $raw = (string) ($input['json'] ?? '');
        if ($raw === '') {
            return nibwp_wsform_err('nibwp_wsform_bad_json', __('A JSON string is required.', domain: 'nibwp'));
        }
        // Decoded here rather than inside the try: a syntax error in agent-supplied
        // JSON is a bad argument, not a WS Form failure, and should say so.
        $decoded = json_decode($raw);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return nibwp_wsform_err(
                'nibwp_wsform_bad_json',
                sprintf(
                    /* translators: %s: JSON parser error */
                    __('The form JSON could not be parsed: %s', domain: 'nibwp'),
                    json_last_error_msg()
                )
            );
        }
        $input['_decoded'] = $decoded;
    }

    return nibwp_wsform_try(static function () use ($action, $id, $input) {
        $form = new WS_Form_Form();
        $form->id = $id;

        switch ($action) {
            case 'get':
                return ['id' => $id, 'form' => $form->db_read(true, true)];

            case 'create':
                $object = $input['_decoded'];
                if (isset($object->id)) {
                    unset($object->id);
                }
                $form->label = isset($object->label) ? (string) $object->label : __('New form', domain: 'nibwp');
                $new_id = (int) $form->db_create();
                $form->id = $new_id;
                $object->id = $new_id;
                $form->db_update_from_object($object);

                return ['id' => $new_id, 'created' => true];

            case 'update':
                $object = $input['_decoded'];
                $object->id = $id;
                $form->db_update_from_object($object);

                return ['id' => $id, 'updated' => true];

            case 'checksum':
                return ['id' => $id, 'checksum' => $form->db_checksum()];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/wsform-fields (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-fields', [
    'label'       => __('WS Form — Fields', domain: 'nibwp'),
    'description' => __('List, read, add, update, clone and delete fields on a form, and list the field types this install offers.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'add', 'update', 'clone', 'delete', 'types'],
                'description' => 'The action to perform.',
            ],
            'form_id'    => ['type' => 'integer', 'description' => 'Form ID. Required for list and add.'],
            'field_id'   => ['type' => 'integer', 'description' => 'Field ID. Required for get, update, clone and delete.'],
            'section_id' => ['type' => 'integer', 'description' => 'Section the field belongs in. Required for add.'],
            'type'       => ['type' => 'string', 'description' => 'Field type for add — e.g. text, email, textarea, select, checkbox, file. List them with action=types.'],
            'label'      => ['type' => 'string', 'description' => 'Field label.'],
            'meta'       => ['type' => 'object', 'description' => 'Field meta to set, as key/value pairs — required, placeholder, help text and so on.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Fields live inside sections, sections inside tabs. A field cannot be added without a section_id — read the form first, or create a section with nibwp/wsform-sections.',
                'Call action=types before inventing a field type; the list depends on the WS Form edition installed.',
                'delete removes one field and is irreversible.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $field_id = (int) ($input['field_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'clone', 'delete'], strict: true) && $field_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid field ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['list', 'add'], strict: true) && $form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $form_id, $field_id, $input) {
        $field = new WS_Form_Field();

        switch ($action) {
            case 'types':
                $types = class_exists('WS_Form_Config') ? WS_Form_Config::get_field_types_flat() : [];

                return ['field_types' => $types];

            case 'list':
                $form = new WS_Form_Form();
                $form->id = $form_id;
                $object = $form->db_read(true, true);
                $fields = [];
                foreach (($object->groups ?? []) as $group) {
                    foreach (($group->sections ?? []) as $section) {
                        foreach (($section->fields ?? []) as $f) {
                            $fields[] = [
                                'id'         => (int) ($f->id ?? 0),
                                'type'       => $f->type ?? '',
                                'label'      => $f->label ?? '',
                                'section_id' => (int) ($section->id ?? 0),
                                'group_id'   => (int) ($group->id ?? 0),
                            ];
                        }
                    }
                }

                return ['form_id' => $form_id, 'fields' => $fields, 'count' => count($fields)];

            case 'get':
                $field->id = $field_id;

                return ['field' => $field->db_read(true, true)];

            case 'add':
                $section_id = (int) ($input['section_id'] ?? 0);
                if ($section_id <= 0) {
                    throw new \RuntimeException(__('A section_id is required. Read the form to find one, or create a section first.', domain: 'nibwp'));
                }
                $field->form_id = $form_id;
                $field->section_id = $section_id;
                $field->type = (string) ($input['type'] ?? 'text');
                $field->label = (string) ($input['label'] ?? __('New field', domain: 'nibwp'));
                $new_id = (int) $field->db_create();

                if (!empty($input['meta']) && is_array($input['meta'])) {
                    $field->id = $new_id;
                    $object = $field->db_read(true, true);
                    foreach ($input['meta'] as $key => $value) {
                        $object->meta->{$key} = $value;
                    }
                    $field->db_update_from_object($object);
                }

                return ['id' => $new_id, 'form_id' => $form_id, 'section_id' => $section_id, 'created' => true];

            case 'update':
                $field->id = $field_id;
                $object = $field->db_read(true, true);
                if (isset($input['label'])) {
                    $object->label = (string) $input['label'];
                }
                if (isset($input['type'])) {
                    $object->type = (string) $input['type'];
                }
                if (!empty($input['meta']) && is_array($input['meta'])) {
                    foreach ($input['meta'] as $key => $value) {
                        $object->meta->{$key} = $value;
                    }
                }
                $field->db_update_from_object($object);

                return ['id' => $field_id, 'updated' => true];

            case 'clone':
                $field->id = $field_id;

                return ['id' => (int) $field->db_clone(), 'cloned_from' => $field_id];

            case 'delete':
                $field->id = $field_id;
                $field->db_delete();

                return ['id' => $field_id, 'deleted' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/wsform-tabs (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-tabs', [
    'label'       => __('WS Form — Tabs', domain: 'nibwp'),
    'description' => __('List, add, update, clone and delete a form\'s tabs — the top level of the WS Form layout, used for multi-step forms.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'clone', 'delete'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer', 'description' => 'Form ID. Required for list and add.'],
            'tab_id'  => ['type' => 'integer', 'description' => 'Tab ID. Required for update, clone and delete.'],
            'label'   => ['type' => 'string', 'description' => 'Tab label.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_tabs',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Tabs are WS Form groups. One tab is an ordinary form; several tabs make it multi-step.',
                'Deleting a tab deletes the sections and fields inside it.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_tabs(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $tab_id = (int) ($input['tab_id'] ?? 0);

    if (in_array($action, ['update', 'clone', 'delete'], strict: true) && $tab_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid tab ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['list', 'add'], strict: true) && $form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $form_id, $tab_id, $input) {
        $group = new WS_Form_Group();

        switch ($action) {
            case 'list':
                $form = new WS_Form_Form();
                $form->id = $form_id;
                $object = $form->db_read(true, true);
                $tabs = [];
                foreach (($object->groups ?? []) as $g) {
                    $tabs[] = [
                        'id'            => (int) ($g->id ?? 0),
                        'label'         => $g->label ?? '',
                        'section_count' => count((array) ($g->sections ?? [])),
                    ];
                }

                return ['form_id' => $form_id, 'tabs' => $tabs, 'count' => count($tabs)];

            case 'add':
                $group->form_id = $form_id;
                $group->label = (string) ($input['label'] ?? __('New tab', domain: 'nibwp'));

                return ['id' => (int) $group->db_create(), 'form_id' => $form_id, 'created' => true];

            case 'update':
                $group->id = $tab_id;
                $object = $group->db_read(true, true);
                if (isset($input['label'])) {
                    $object->label = (string) $input['label'];
                }
                $group->db_update_from_object($object);

                return ['id' => $tab_id, 'updated' => true];

            case 'clone':
                $group->id = $tab_id;

                return ['id' => (int) $group->db_clone(), 'cloned_from' => $tab_id];

            case 'delete':
                $group->id = $tab_id;
                $group->db_delete();

                return ['id' => $tab_id, 'deleted' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/wsform-sections (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-sections', [
    'label'       => __('WS Form — Sections', domain: 'nibwp'),
    'description' => __('List, add, update, clone and delete the sections inside a form\'s tabs. Sections hold the fields.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'clone', 'delete'], 'description' => 'The action to perform.'],
            'form_id'    => ['type' => 'integer', 'description' => 'Form ID. Required for list and add.'],
            'tab_id'     => ['type' => 'integer', 'description' => 'Tab (group) the section belongs to. Required for add.'],
            'section_id' => ['type' => 'integer', 'description' => 'Section ID. Required for update, clone and delete.'],
            'label'      => ['type' => 'string', 'description' => 'Section label.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_sections',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A field needs a section to live in, so this is usually the step before adding fields to a new tab.',
                'Deleting a section deletes the fields inside it.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_sections(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $section_id = (int) ($input['section_id'] ?? 0);

    if (in_array($action, ['update', 'clone', 'delete'], strict: true) && $section_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid section ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['list', 'add'], strict: true) && $form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $form_id, $section_id, $input) {
        $section = new WS_Form_Section();

        switch ($action) {
            case 'list':
                $form = new WS_Form_Form();
                $form->id = $form_id;
                $object = $form->db_read(true, true);
                $sections = [];
                foreach (($object->groups ?? []) as $group) {
                    foreach (($group->sections ?? []) as $s) {
                        $sections[] = [
                            'id'          => (int) ($s->id ?? 0),
                            'label'       => $s->label ?? '',
                            'tab_id'      => (int) ($group->id ?? 0),
                            'field_count' => count((array) ($s->fields ?? [])),
                        ];
                    }
                }

                return ['form_id' => $form_id, 'sections' => $sections, 'count' => count($sections)];

            case 'add':
                $tab_id = (int) ($input['tab_id'] ?? 0);
                if ($tab_id <= 0) {
                    throw new \RuntimeException(__('A tab_id is required. List the form\'s tabs first.', domain: 'nibwp'));
                }
                $section->form_id = $form_id;
                $section->group_id = $tab_id;
                $section->label = (string) ($input['label'] ?? __('New section', domain: 'nibwp'));

                return ['id' => (int) $section->db_create(), 'tab_id' => $tab_id, 'created' => true];

            case 'update':
                $section->id = $section_id;
                $object = $section->db_read(true, true);
                if (isset($input['label'])) {
                    $object->label = (string) $input['label'];
                }
                $section->db_update_from_object($object);

                return ['id' => $section_id, 'updated' => true];

            case 'clone':
                $section->id = $section_id;

                return ['id' => (int) $section->db_clone(), 'cloned_from' => $section_id];

            case 'delete':
                $section->id = $section_id;
                $section->db_delete();

                return ['id' => $section_id, 'deleted' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/wsform-actions (read + write)
 *
 * Not covered by WS Form's own abilities. Actions are what a form DOES on
 * submit — send mail, call a webhook, push to a CRM — so a form built without
 * them looks finished and silently does nothing.
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-actions', [
    'label'       => __('WS Form — Actions', domain: 'nibwp'),
    'description' => __('Read and configure what a form does on submit: list configured actions, list the action types available, and add, update or remove one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'types', 'add', 'update', 'delete'], 'description' => 'The action to perform.'],
            'form_id'     => ['type' => 'integer', 'description' => 'Form ID. Required for everything except types.'],
            'action_type' => ['type' => 'string', 'description' => 'The WS Form action type to add — e.g. email, webhook. List them with action=types.'],
            'action_id'   => ['type' => 'string', 'description' => 'Identifier of a configured action, as returned by list. Required for update and delete.'],
            'settings'    => ['type' => 'object', 'description' => 'Action settings as key/value pairs — for email that is to, subject, message and so on.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_actions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A form with no action collects submissions and notifies nobody. Check this after building one.',
                'Actions are stored in the form\'s action data grid, so adding one rewrites that grid — read with list first.',
                'Available action types depend on the WS Form edition and any add-ons installed.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_actions(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if ($action !== 'types' && $form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $form_id, $input) {
        if ($action === 'types') {
            // WS_Form_Action::get_settings() directly, not WS_Form_Config::get_config():
            // that builds the whole config array and only populates `actions` when
            // it believes it is running in admin, so it returns nothing here.
            $config = class_exists('WS_Form_Action') ? WS_Form_Action::get_settings() : [];
            $types = [];
            foreach ((array) $config as $key => $definition) {
                $types[] = [
                    'type'  => (string) $key,
                    'label' => is_object($definition) ? ($definition->label ?? '') : (is_array($definition) ? ($definition['label'] ?? '') : ''),
                ];
            }

            return ['action_types' => $types, 'count' => count($types)];
        }

        $form = new WS_Form_Form();
        $form->id = $form_id;
        $object = $form->db_read(true, true);

        // Actions live in a data grid on the form's `action` meta. Everything
        // below reads that grid, changes it, and writes the form back.
        $grid = nibwp_wsform_meta($object, 'action', null);
        $rows = [];
        if (is_object($grid) && isset($grid->groups[0]->rows) && is_array($grid->groups[0]->rows)) {
            $rows = $grid->groups[0]->rows;
        }

        switch ($action) {
            case 'list':
                $out = [];
                foreach ($rows as $row) {
                    $cells = [];
                    foreach ((array) ($row->data ?? []) as $cell) {
                        $cells[] = $cell;
                    }
                    $out[] = [
                        'action_id' => (string) ($row->id ?? ''),
                        'enabled'   => (bool) ($row->enabled ?? true),
                        'data'      => $cells,
                    ];
                }

                return ['form_id' => $form_id, 'actions' => $out, 'count' => count($out)];

            case 'add':
                $type = (string) ($input['action_type'] ?? '');
                if ($type === '') {
                    throw new \RuntimeException(__('An action_type is required. List them with action=types.', domain: 'nibwp'));
                }
                if (!is_object($grid)) {
                    $grid = (object) ['groups' => [(object) ['rows' => []]]];
                }
                if (!isset($grid->groups[0])) {
                    $grid->groups[0] = (object) ['rows' => []];
                }

                $next_id = 1;
                foreach ($rows as $row) {
                    $next_id = max($next_id, ((int) ($row->id ?? 0)) + 1);
                }

                $new_row = (object) [
                    'id'      => $next_id,
                    'enabled' => true,
                    'data'    => [$type],
                ];
                foreach ((array) ($input['settings'] ?? []) as $key => $value) {
                    $new_row->{$key} = $value;
                }

                $grid->groups[0]->rows = array_merge($rows, [$new_row]);
                $object->meta->action = $grid;
                $form->db_update_from_object($object);

                return ['form_id' => $form_id, 'action_id' => (string) $next_id, 'action_type' => $type, 'created' => true];

            case 'update':
                $action_id = (string) ($input['action_id'] ?? '');
                if ($action_id === '') {
                    throw new \RuntimeException(__('An action_id is required. List the form\'s actions first.', domain: 'nibwp'));
                }
                $found = false;
                foreach ($rows as $i => $row) {
                    if ((string) ($row->id ?? '') !== $action_id) {
                        continue;
                    }
                    $found = true;
                    foreach ((array) ($input['settings'] ?? []) as $key => $value) {
                        $rows[$i]->{$key} = $value;
                    }
                }
                if (!$found) {
                    throw new \RuntimeException(__('No action with that ID exists on this form.', domain: 'nibwp'));
                }
                $grid->groups[0]->rows = $rows;
                $object->meta->action = $grid;
                $form->db_update_from_object($object);

                return ['form_id' => $form_id, 'action_id' => $action_id, 'updated' => true];

            case 'delete':
                $action_id = (string) ($input['action_id'] ?? '');
                if ($action_id === '') {
                    throw new \RuntimeException(__('An action_id is required.', domain: 'nibwp'));
                }
                $kept = [];
                foreach ($rows as $row) {
                    if ((string) ($row->id ?? '') !== $action_id) {
                        $kept[] = $row;
                    }
                }
                if (count($kept) === count($rows)) {
                    throw new \RuntimeException(__('No action with that ID exists on this form.', domain: 'nibwp'));
                }
                $grid->groups[0]->rows = $kept;
                $object->meta->action = $grid;
                $form->db_update_from_object($object);

                return ['form_id' => $form_id, 'action_id' => $action_id, 'deleted' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/wsform-submissions (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-submissions', [
    'label'       => __('WS Form — Submissions', domain: 'nibwp'),
    'description' => __('List and read form submissions, and change their state: status, starred, read/unread, and restore from trash.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'set_status', 'star', 'unstar', 'mark_read', 'mark_unread', 'restore', 'counts'],
                'description' => 'The action to perform.',
            ],
            'form_id'       => ['type' => 'integer', 'description' => 'Form ID. Required for list and counts.'],
            'submission_id' => ['type' => 'integer', 'description' => 'Submission ID. Required for every per-submission action.'],
            'status'        => ['type' => 'string', 'description' => 'Target status for set_status — e.g. publish, trash, spam.'],
            'per_page'      => ['type' => 'integer', 'default' => 20],
            'page'          => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_submissions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Submission data is personal data. Read only what the task needs and do not copy it somewhere it was not collected for.',
                'set_status to trash is reversible with restore. Permanent deletion lives in nibwp/wsform-delete.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_submissions(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $submission_id = (int) ($input['submission_id'] ?? 0);

    $per_submission = ['get', 'set_status', 'star', 'unstar', 'mark_read', 'mark_unread', 'restore'];
    if (in_array($action, $per_submission, strict: true) && $submission_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid submission ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['list', 'counts'], strict: true) && $form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $form_id, $submission_id, $input) {
        $submit = new WS_Form_Submit();

        switch ($action) {
            case 'list':
                $page = nibwp_wsform_paginate($input);
                $submit->form_id = $form_id;
                $rows = $submit->db_read_all(
                    '',
                    '',
                    '',
                    '',
                    (string) $page['limit'],
                    (string) $page['offset']
                );

                return [
                    'form_id'     => $form_id,
                    'submissions' => is_array($rows) ? $rows : [],
                    'count'       => is_array($rows) ? count($rows) : 0,
                ];

            case 'get':
                $submit->id = $submission_id;

                return ['submission' => $submit->db_read(true, true)];

            case 'set_status':
                $status = (string) ($input['status'] ?? '');
                if ($status === '') {
                    throw new \RuntimeException(__('A status is required.', domain: 'nibwp'));
                }
                $submit->id = $submission_id;
                $submit->db_set_status($status);

                return ['id' => $submission_id, 'status' => $status];

            case 'star':
            case 'unstar':
                $submit->id = $submission_id;
                $submit->db_set_starred($action === 'star');

                return ['id' => $submission_id, 'starred' => $action === 'star'];

            case 'mark_read':
            case 'mark_unread':
                $submit->id = $submission_id;
                $submit->db_set_viewed($action === 'mark_read');

                return ['id' => $submission_id, 'read' => $action === 'mark_read'];

            case 'restore':
                $submit->id = $submission_id;
                $submit->db_restore();

                return ['id' => $submission_id, 'restored' => true];

            case 'counts':
                $submit->form_id = $form_id;

                return ['form_id' => $form_id, 'counts' => (array) $submit->db_get_count_by_status()];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/wsform-export (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-export', [
    'label'       => __('WS Form — Export submissions', domain: 'nibwp'),
    'description' => __('Export a form\'s submissions as rows or CSV, with the column headers WS Form would use (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id'  => ['type' => 'integer', 'description' => 'Form ID to export.'],
            'format'   => ['type' => 'string', 'enum' => ['rows', 'csv'], 'default' => 'rows', 'description' => 'rows returns structured data; csv returns a single CSV string.'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_export',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Paginated on purpose. A form with thousands of submissions will not fit in one response, and asking for it all at once fails slowly.',
                'This is personal data leaving its original context — export the columns the task needs, not everything.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wsform_export(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    if (!class_exists('WS_Form_Submit_Export')) {
        return nibwp_wsform_err(
            'nibwp_wsform_no_export',
            __('Submission export is unavailable in this WS Form edition.', domain: 'nibwp')
        );
    }

    return nibwp_wsform_try(static function () use ($form_id, $input) {
        $page = nibwp_wsform_paginate($input);

        $submit = new WS_Form_Submit();
        $submit->form_id = $form_id;
        $objects = $submit->db_read_all('', '', '', '', (string) $page['limit'], (string) $page['offset']);
        $objects = is_array($objects) ? $objects : [];

        $export = new WS_Form_Submit_Export($form_id);
        $header = method_exists($export, 'get_header') ? $export->get_header() : [];
        $rows = method_exists($export, 'process_rows') ? $export->process_rows($objects, false, false, false) : [];

        if ((string) ($input['format'] ?? 'rows') === 'csv') {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                throw new \RuntimeException(__('Could not open a buffer to build the CSV.', domain: 'nibwp'));
            }
            if (!empty($header)) {
                fputcsv($handle, array_map(static fn($c) => is_scalar($c) ? (string) $c : '', (array) $header));
            }
            foreach ((array) $rows as $row) {
                fputcsv($handle, array_map(static fn($c) => is_scalar($c) ? (string) $c : wp_json_encode($c), (array) $row));
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return ['form_id' => $form_id, 'format' => 'csv', 'csv' => $csv, 'count' => count((array) $rows)];
        }

        return [
            'form_id' => $form_id,
            'format'  => 'rows',
            'header'  => $header,
            'rows'    => $rows,
            'count'   => count((array) $rows),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 10 — nibwp/wsform-notes (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-notes', [
    'label'       => __('WS Form — Submission notes', domain: 'nibwp'),
    'description' => __('Read, add, edit and remove the internal notes attached to a submission.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'delete'], 'description' => 'The action to perform.'],
            'submission_id' => ['type' => 'integer', 'description' => 'Submission ID. Required for list and create.'],
            'note_id'       => ['type' => 'integer', 'description' => 'Note ID. Required for update and delete.'],
            'note'          => ['type' => 'string', 'description' => 'Note text.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_notes',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Notes are internal and are never shown to the person who submitted the form.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_notes(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    if (!class_exists('WS_Form_Submit_Note')) {
        return nibwp_wsform_err(
            'nibwp_wsform_no_notes',
            __('Submission notes are unavailable in this WS Form edition.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? '');
    $submission_id = (int) ($input['submission_id'] ?? 0);
    $note_id = (int) ($input['note_id'] ?? 0);

    if (in_array($action, ['list', 'create'], strict: true) && $submission_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid submission ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['update', 'delete'], strict: true) && $note_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid note ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $submission_id, $note_id, $input) {
        $note = new WS_Form_Submit_Note();

        switch ($action) {
            case 'list':
                $note->submit_id = $submission_id;

                return [
                    'submission_id' => $submission_id,
                    'notes'         => method_exists($note, 'db_read_all') ? $note->db_read_all() : [],
                ];

            case 'create':
                $note->submit_id = $submission_id;
                $note->note = (string) ($input['note'] ?? '');

                return ['id' => (int) $note->db_create(), 'submission_id' => $submission_id, 'created' => true];

            case 'update':
                $note->id = $note_id;
                $note->note = (string) ($input['note'] ?? '');
                $note->db_update();

                return ['id' => $note_id, 'updated' => true];

            case 'delete':
                $note->id = $note_id;
                $note->db_delete();

                return ['id' => $note_id, 'deleted' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 11 — nibwp/wsform-styles (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-styles', [
    'label'       => __('WS Form — Styles', domain: 'nibwp'),
    'description' => __('List, read, create, update, clone, publish, draft and delete WS Form styles, and assign one to a form.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list', 'get', 'create', 'update', 'clone', 'publish', 'draft', 'delete', 'assign', 'defaults'],
                'description' => 'The action to perform.',
            ],
            'style_id' => ['type' => 'integer', 'description' => 'Style ID. Required for everything except list, create and defaults.'],
            'form_id'  => ['type' => 'integer', 'description' => 'Form ID. Required for assign.'],
            'label'    => ['type' => 'string', 'description' => 'Style label.'],
            'meta'     => ['type' => 'object', 'description' => 'Style meta as key/value pairs — colors, spacing and the rest of the styler variables.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_styles',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Styles are shared: changing one changes every form using it. Clone before experimenting on a style that is in use.',
                'A style has to be published before a visitor sees it, the same as a form.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_styles(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    if (!class_exists('WS_Form_Style')) {
        return nibwp_wsform_err(
            'nibwp_wsform_no_styles',
            __('Styles are unavailable in this WS Form edition.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? '');
    $style_id = (int) ($input['style_id'] ?? 0);

    $needs_style = ['get', 'update', 'clone', 'publish', 'draft', 'delete', 'assign'];
    if (in_array($action, $needs_style, strict: true) && $style_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid style ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $style_id, $input) {
        $style = new WS_Form_Style();
        $style->id = $style_id;

        switch ($action) {
            case 'list':
                $rows = $style->db_read_all();

                return ['styles' => is_array($rows) ? nibwp_wsform_int_ids($rows) : []];

            case 'defaults':
                return ['default_style_id' => (int) $style->get_style_id_default()];

            case 'get':
                return ['style' => $style->db_read(true, true)];

            case 'create':
                $style->label = (string) ($input['label'] ?? __('New style', domain: 'nibwp'));
                $new_id = (int) $style->db_create();

                if (!empty($input['meta']) && is_array($input['meta'])) {
                    $style->id = $new_id;
                    $object = $style->db_read(true, true);
                    foreach ($input['meta'] as $key => $value) {
                        $object->meta->{$key} = $value;
                    }
                    $style->db_update_from_object($object);
                }

                return ['id' => $new_id, 'created' => true];

            case 'update':
                $object = $style->db_read(true, true);
                if (isset($input['label'])) {
                    $object->label = (string) $input['label'];
                }
                foreach ((array) ($input['meta'] ?? []) as $key => $value) {
                    $object->meta->{$key} = $value;
                }
                $style->db_update_from_object($object);

                return ['id' => $style_id, 'updated' => true];

            case 'clone':
                return ['id' => (int) $style->db_clone(), 'cloned_from' => $style_id];

            case 'publish':
                $style->db_publish();

                return ['id' => $style_id, 'status' => 'publish'];

            case 'draft':
                $style->db_draft();

                return ['id' => $style_id, 'status' => 'draft'];

            case 'delete':
                $style->db_delete();

                return ['id' => $style_id, 'deleted' => true];

            case 'assign':
                $form_id = (int) ($input['form_id'] ?? 0);
                if ($form_id <= 0) {
                    throw new \RuntimeException(__('A form_id is required to assign a style.', domain: 'nibwp'));
                }
                $form = new WS_Form_Form();
                $form->id = $form_id;
                $object = $form->db_read(true, true);
                $object->meta->style_id = $style_id;
                $form->db_update_from_object($object);

                return ['form_id' => $form_id, 'style_id' => $style_id, 'assigned' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 12 — nibwp/wsform-templates (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-templates', [
    'label'       => __('WS Form — Templates', domain: 'nibwp'),
    'description' => __('List the form templates this install offers, with their categories, so a form can be created from one (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'categories'], 'default' => 'list', 'description' => 'The action to perform.'],
            'template_id' => ['type' => 'string', 'description' => 'Template ID. Required for get.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_templates',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Starting from a template is usually faster and more correct than building a form field by field. Create one with nibwp/wsform-forms action=create_from_template.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wsform_templates(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    if (!class_exists('WS_Form_Template')) {
        return nibwp_wsform_err(
            'nibwp_wsform_no_templates',
            __('Templates are unavailable in this WS Form edition.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? 'list');

    return nibwp_wsform_try(static function () use ($action, $input) {
        $template = new WS_Form_Template();

        switch ($action) {
            case 'categories':
                $config = $template->read_config(false, false, false);
                $categories = [];
                foreach ((array) $config as $category) {
                    $categories[] = [
                        'id'    => $category->id ?? '',
                        'label' => $category->label ?? '',
                        'count' => count((array) ($category->templates ?? [])),
                    ];
                }

                return ['categories' => $categories, 'count' => count($categories)];

            case 'get':
                $template_id = (string) ($input['template_id'] ?? '');
                if ($template_id === '') {
                    throw new \RuntimeException(__('A template_id is required.', domain: 'nibwp'));
                }

                return ['template_id' => $template_id, 'template' => $template->read($template_id)];

            case 'list':
            default:
                $all = $template->read_all(false, false, false);
                $out = [];
                foreach ((array) $all as $id => $t) {
                    $out[] = [
                        'id'    => (string) $id,
                        'label' => is_object($t) ? ($t->label ?? '') : '',
                    ];
                }

                return ['templates' => $out, 'count' => count($out)];
        }
    });
}

/* ----------------------------------------------------------------------------
 * Ability 13 — nibwp/wsform-config (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-config', [
    'label'       => __('WS Form — Configuration', domain: 'nibwp'),
    'description' => __('Read WS Form configuration: field types, data sources, frameworks, plugin settings and the parse variables available to actions (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'section' => [
                'type' => 'string',
                'enum' => ['field_types', 'data_sources', 'frameworks', 'settings', 'parse_variables', 'file_types'],
                'description' => 'Which part of the configuration to read.',
            ],
        ],
        'required' => ['section'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_config',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read this before writing anything that names a field type, a framework or a parse variable — what is available depends on the edition and add-ons installed.',
                'parse_variables are the #field(...) style tokens actions use to pull submitted values into an email or webhook.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wsform_config(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    if (!class_exists('WS_Form_Config')) {
        return nibwp_wsform_err('nibwp_wsform_no_config', __('WS Form configuration is unavailable.', domain: 'nibwp'));
    }

    $section = (string) ($input['section'] ?? '');

    return nibwp_wsform_try(static function () use ($section) {
        switch ($section) {
            case 'field_types':
                return ['field_types' => WS_Form_Config::get_field_types_flat()];

            case 'data_sources':
                if (!class_exists('WS_Form_Data_Source')) {
                    throw new \RuntimeException(__('Data sources are unavailable in this WS Form edition.', domain: 'nibwp'));
                }

                return ['data_sources' => WS_Form_Data_Source::get_settings()];

            case 'frameworks':
                return ['frameworks' => WS_Form_Config::get_frameworks()];

            case 'settings':
                return ['settings' => WS_Form_Config::get_settings_plugin()];

            case 'parse_variables':
                return ['parse_variables' => WS_Form_Config::get_parse_variables()];

            case 'file_types':
                return ['file_types' => WS_Form_Config::get_file_types()];
        }

        throw new \RuntimeException(__('Unknown configuration section.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 14 — nibwp/wsform-delete (destructive)
 *
 * Every irreversible operation lives here, behind its own tool, so a
 * connection can be granted everything above without being able to destroy
 * anything.
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wsform-delete', [
    'label'       => __('WS Form — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete forms, submissions and expired submission data. Irreversible.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['trash_form', 'delete_form', 'trash_submission', 'delete_submission', 'delete_expired', 'empty_trash'],
                'description' => 'The action to perform.',
            ],
            'form_id'       => ['type' => 'integer', 'description' => 'Form ID. Required for the form actions and for empty_trash.'],
            'submission_id' => ['type' => 'integer', 'description' => 'Submission ID. Required for the submission actions.'],
            'confirm'       => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true for any permanent deletion. Trashing does not require it.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wsform_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Prefer trashing. trash_form and trash_submission are reversible with the restore actions on the other tools.',
                'Permanent deletion requires confirm=true, and deleting a form takes its submissions with it.',
                'delete_expired applies the retention period configured in WS Form and can remove a great deal at once.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wsform_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_wsform_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $submission_id = (int) ($input['submission_id'] ?? 0);
    $confirm = (bool) ($input['confirm'] ?? false);

    $permanent = ['delete_form', 'delete_submission', 'delete_expired', 'empty_trash'];
    if (in_array($action, $permanent, strict: true) && !$confirm) {
        return nibwp_wsform_err(
            'nibwp_wsform_unconfirmed',
            __('This permanently destroys data. Re-issue the call with confirm set to true if that is intended.', domain: 'nibwp')
        );
    }

    if (in_array($action, ['trash_form', 'delete_form', 'delete_expired', 'empty_trash'], strict: true) && $form_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['trash_submission', 'delete_submission'], strict: true) && $submission_id <= 0) {
        return nibwp_wsform_err('nibwp_wsform_bad_id', __('A valid submission ID is required.', domain: 'nibwp'));
    }

    return nibwp_wsform_try(static function () use ($action, $form_id, $submission_id) {
        switch ($action) {
            case 'trash_form':
                $form = new WS_Form_Form();
                $form->id = $form_id;
                $form->db_set_status('trash');

                return ['id' => $form_id, 'status' => 'trash', 'reversible' => true];

            case 'delete_form':
                $form = new WS_Form_Form();
                $form->id = $form_id;
                $form->db_delete();

                return ['id' => $form_id, 'deleted' => true, 'reversible' => false];

            case 'trash_submission':
                $submit = new WS_Form_Submit();
                $submit->id = $submission_id;
                $submit->db_delete(false);

                return ['id' => $submission_id, 'status' => 'trash', 'reversible' => true];

            case 'delete_submission':
                $submit = new WS_Form_Submit();
                $submit->id = $submission_id;
                $submit->db_delete(true);

                return ['id' => $submission_id, 'deleted' => true, 'reversible' => false];

            case 'delete_expired':
                $submit = new WS_Form_Submit();
                $submit->form_id = $form_id;
                $submit->db_delete_expired();

                return ['form_id' => $form_id, 'expired_deleted' => true];

            case 'empty_trash':
                $submit = new WS_Form_Submit();
                $submit->form_id = $form_id;
                $submit->db_trash_delete();

                return ['form_id' => $form_id, 'trash_emptied' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
