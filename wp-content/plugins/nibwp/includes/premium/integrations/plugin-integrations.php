<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// =====================================================================
// FORMS (category: 'forms')
// =====================================================================

wp_register_ability('nibwp/forms-manage', [
    'label' => __('Forms – Universal Manager', domain: 'nibwp'),
    'description' => __(
        'Universal forms manager. Auto-detects Gravity Forms, WPForms, or Fluent Forms and provides a unified API for listing forms, getting entries, and creating entries.',
        domain: 'nibwp',
    ),
    'category' => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_plugins', 'list_forms', 'get_form', 'create_form', 'delete_form', 'get_entries', 'create_entry', 'delete_entry'],
                'description' => 'The action to perform. `list_plugins` returns all installed form plugins so you can pick one before creating.',
            ],
            'form_id' => [
                'type' => ['integer', 'string'],
                'description' => 'Form ID (required for get_form, get_entries, create_entry, delete_entry, delete_form).',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Form title for create_form.',
            ],
            'fields' => [
                'type' => 'array',
                'description' => 'For create_form: array of field defs. Each item: { type, label, name?, required?, default?, options?, placeholder? }. Types: text, email, tel, url, textarea, number, select, checkbox, radio, date, file.',
                'items' => ['type' => 'object'],
            ],
            'entry_id' => [
                'type' => ['integer', 'string'],
                'description' => 'Entry ID (required for delete_entry).',
            ],
            'entry_data' => [
                'type' => 'object',
                'description' => 'Key-value pairs of field IDs/names to values for create_entry.',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Entries per page.',
                'default' => 20,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Page number.',
                'default' => 1,
            ],
            'plugin' => [
                'type' => 'string',
                'enum' => ['gravityforms', 'wpforms', 'fluentform', 'cf7', 'ninjaforms', 'formidable', 'forminator', 'happyforms', 'jetform'],
                'description' => 'Optional. Force a specific form plugin when several are installed. Otherwise auto-detected.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'plugin' => ['type' => 'string'],
            'forms' => ['type' => 'array'],
            'form' => ['type' => 'object'],
            'entries' => ['type' => 'array'],
            'entry' => ['type' => 'object'],
            'deleted' => ['type' => 'boolean'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_forms_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Universal forms manager that auto-detects the active form plugin.',
                '',
                'SUPPORTED PLUGINS (auto-detected in order):',
                '- Gravity Forms (GFAPI) — full CRUD',
                '- WPForms (wpforms()) — full CRUD',
                '- Fluent Forms (wpFluent) — full CRUD',
                '- Contact Form 7 (WPCF7) — list/get; entries via Flamingo if active',
                '- Ninja Forms (Ninja_Forms) — full CRUD',
                '- Formidable Forms (FrmForm) — full CRUD',
                '- Forminator (Forminator_API) — full CRUD',
                '- Happy Forms / JetFormBuilder — list_forms only',
                '',
                'Pass `plugin` to force a specific plugin when multiple are installed.',
                '',
                'ACTIONS:',
                '- list_forms: List all forms. No extra params needed.',
                '- get_form: Get a single form by form_id.',
                '- get_entries: Get entries for a form. Requires form_id. Supports per_page/page.',
                '- create_entry: Create an entry. Requires form_id and entry_data (field_id => value map).',
                '- delete_entry: Delete an entry. Requires form_id and entry_id.',
                '',
                'Returns WP_Error if no supported form plugin is active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Detect which form plugin is active. Optional override via `?plugin=` input
 * lets agents target a specific plugin when multiple are installed.
 */
function nibwp_forms_detect_plugin(?string $prefer = null): ?string
{
    $candidates = [
        'gravityforms' => static fn() => class_exists('GFForms'),
        'wpforms'      => static fn() => defined('WPFORMS_VERSION'),
        'fluentform'   => static fn() => defined('FLUENTFORM'),
        'cf7'          => static fn() => defined('WPCF7_VERSION') || class_exists('WPCF7'),
        'ninjaforms'   => static fn() => defined('NF_PLUGIN_VERSION') || class_exists('Ninja_Forms'),
        'formidable'   => static fn() => function_exists('load_formidable_forms') || class_exists('FrmForm'),
        'forminator'   => static fn() => defined('FORMINATOR_VERSION') || class_exists('Forminator_API'),
        'happyforms'   => static fn() => defined('HAPPYFORMS_VERSION'),
        'jetform'      => static fn() => class_exists('\\Jet_Form_Builder\\Plugin'),
    ];

    if ($prefer !== null && isset($candidates[$prefer]) && $candidates[$prefer]()) {
        return $prefer;
    }

    foreach ($candidates as $key => $check) {
        if ($check()) {
            return $key;
        }
    }
    return null;
}

function nibwp_forms_manage(array $input): array|WP_Error
{
    $action = (string) ($input['action'] ?? '');

    // `list_plugins` is plugin-independent — report which form plugins are
    // installed + which is active.
    if ($action === 'list_plugins') {
        $candidates = [
            'fluentform'   => ['Fluent Forms',     static fn () => defined('FLUENTFORM')],
            'gravityforms' => ['Gravity Forms',    static fn () => class_exists('GFForms')],
            'wpforms'      => ['WPForms',          static fn () => defined('WPFORMS_VERSION')],
            'cf7'          => ['Contact Form 7',   static fn () => defined('WPCF7_VERSION') || class_exists('WPCF7')],
            'ninjaforms'   => ['Ninja Forms',      static fn () => defined('NF_PLUGIN_VERSION') || class_exists('Ninja_Forms')],
            'formidable'   => ['Formidable Forms', static fn () => function_exists('load_formidable_forms') || class_exists('FrmForm')],
            'forminator'   => ['Forminator',       static fn () => defined('FORMINATOR_VERSION') || class_exists('Forminator_API')],
            'happyforms'   => ['Happy Forms',      static fn () => defined('HAPPYFORMS_VERSION')],
            'jetform'      => ['JetFormBuilder',   static fn () => class_exists('\\Jet_Form_Builder\\Plugin')],
        ];
        $out = [];
        foreach ($candidates as $slug => [$label, $check]) {
            $out[] = ['slug' => $slug, 'label' => $label, 'installed' => (bool) $check()];
        }
        return ['plugins' => $out];
    }

    $prefer = isset($input['plugin']) ? (string) $input['plugin'] : null;
    $plugin = nibwp_forms_detect_plugin($prefer);
    if (!$plugin) {
        return new WP_Error('no_form_plugin', 'No supported form plugin detected. Install one of: Fluent Forms (recommended, free), Gravity Forms, WPForms, Contact Form 7, Ninja Forms, Formidable Forms, Forminator, Happy Forms, JetFormBuilder.');
    }

    $form_id = $input['form_id'] ?? null;
    $entry_id = $input['entry_id'] ?? null;
    $entry_data = $input['entry_data'] ?? [];
    $title = (string) ($input['title'] ?? '');
    $fields = (array) ($input['fields'] ?? []);
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($plugin) {
            case 'gravityforms':
                if (!class_exists('GFAPI')) {
                    return new WP_Error('gf_api_missing', 'GFAPI class not available.');
                }
                return nibwp_forms_gf($action, $form_id, $entry_id, $entry_data, $per_page, $page, $title, $fields);
            case 'wpforms':
                return nibwp_forms_wpforms($action, $form_id, $entry_id, $entry_data, $per_page, $page, $title, $fields);
            case 'fluentform':
                return nibwp_forms_fluent($action, $form_id, $entry_id, $entry_data, $per_page, $page, $title, $fields);
            case 'cf7':
                return nibwp_forms_cf7($action, $form_id, $entry_id, $entry_data, $per_page, $page, $title, $fields);
            case 'ninjaforms':
                return nibwp_forms_ninja($action, $form_id, $entry_id, $entry_data, $per_page, $page);
            case 'formidable':
                return nibwp_forms_formidable($action, $form_id, $entry_id, $entry_data, $per_page, $page);
            case 'forminator':
                return nibwp_forms_forminator($action, $form_id, $entry_id, $entry_data, $per_page, $page);
            case 'happyforms':
            case 'jetform':
                return new WP_Error('forms_partial_support', sprintf(
                    'Detected %s but only list_forms is currently supported for this plugin. PR welcome.',
                    $plugin,
                ));
        }
    } catch (\Throwable $e) {
        return new WP_Error('forms_error', $e->getMessage());
    }

    return new WP_Error('unknown_plugin', 'Unknown form plugin.');
}

/**
 * Normalize a list of agent-supplied field defs to a shared shape.
 *
 * Each field becomes: { type, label, name (auto-slugified if absent),
 * required, placeholder, default, options[], min, max, step, maxlength }
 *
 * @param array<int, array<string, mixed>> $fields
 * @return array<int, array<string, mixed>>
 */
function nibwp_forms_normalize_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        $label = (string) ($f['label'] ?? '');
        $name  = (string) ($f['name'] ?? '');
        if ($name === '' && $label !== '') {
            $name = sanitize_title($label);
        }
        if ($name === '') {
            $name = 'field_' . substr(md5((string) wp_rand()), 0, 6);
        }
        $type = strtolower((string) ($f['type'] ?? 'text'));
        $out[] = [
            'type'        => $type,
            'label'       => $label !== '' ? $label : ucfirst($name),
            'name'        => $name,
            'required'    => !empty($f['required']),
            'placeholder' => (string) ($f['placeholder'] ?? ''),
            'default'     => $f['default'] ?? '',
            'options'     => array_values((array) ($f['options'] ?? [])),
            'min'         => $f['min']  ?? null,
            'max'         => $f['max']  ?? null,
            'step'        => $f['step'] ?? null,
            'maxlength'   => $f['maxlength'] ?? null,
        ];
    }
    return $out;
}

/**
 * Build a Fluent Forms field schema entry from a normalized field def.
 *
 * Fluent Forms stores forms as JSON in wp_fluentform_forms.form_fields with
 * a top-level `fields` array; each field has `element` + `attributes` +
 * `settings`. Recreating that JSON by hand is fiddly — we use a small whitelist
 * of element types that covers 95% of real-world forms.
 */
function nibwp_forms_fluent_build_field(array $f): array
{
    $type = $f['type'];
    $map  = [
        'text'     => 'input_text',
        'email'    => 'input_email',
        'tel'      => 'input_text',
        'url'      => 'input_url',
        'textarea' => 'textarea',
        'number'   => 'input_number',
        'select'   => 'select',
        'checkbox' => 'input_checkbox',
        'radio'    => 'input_radio',
        'date'     => 'input_date',
        'file'     => 'input_file',
    ];
    $element = $map[$type] ?? 'input_text';
    $uniq    = $f['name'];
    $attrs = [
        'type'        => $type === 'email' ? 'email' : ($type === 'number' ? 'number' : 'text'),
        'name'        => $uniq,
        'placeholder' => $f['placeholder'],
        'value'       => $f['default'],
        'required'    => $f['required'],
        'maxlength'   => $f['maxlength'],
    ];
    $settings = [
        'container_class' => '',
        'placeholder'     => $f['placeholder'],
        'label'           => $f['label'],
        'label_placement' => '',
        'admin_field_label' => $f['label'],
        'help_message'    => '',
        'validation_rules' => [
            'required' => ['value' => $f['required'], 'message' => 'This field is required'],
        ],
    ];
    $field = [
        'index'      => 0,
        'element'    => $element,
        'attributes' => array_filter($attrs, static fn ($v) => $v !== null),
        'settings'   => $settings,
        'editor_options' => [
            'title' => $f['label'],
            'icon_class' => 'el-icon-edit',
            'template' => $element,
        ],
        'uniqElKey' => $uniq . '_' . substr(md5((string) wp_rand()), 0, 8),
    ];
    if (in_array($type, ['select', 'radio', 'checkbox'], true) && $f['options']) {
        $opts = [];
        foreach ($f['options'] as $opt) {
            if (is_array($opt)) {
                $opts[] = ['label' => (string) ($opt['label'] ?? $opt['value'] ?? ''), 'value' => (string) ($opt['value'] ?? $opt['label'] ?? '')];
            } else {
                $opts[] = ['label' => (string) $opt, 'value' => (string) $opt];
            }
        }
        $field['settings']['advanced_options'] = $opts;
    }
    return $field;
}

function nibwp_forms_gf(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page, string $title = '', array $fields = []): array|WP_Error
{
    if ($action === 'create_form') {
        if ($title === '' || $fields === []) {
            return new WP_Error('missing_create_form_params', 'create_form requires `title` and `fields`.');
        }
        $normalized = nibwp_forms_normalize_fields($fields);
        $gf_fields = [];
        $i = 1;
        $type_map = [
            'text' => 'text', 'email' => 'email', 'tel' => 'phone', 'url' => 'website',
            'textarea' => 'textarea', 'number' => 'number', 'select' => 'select',
            'checkbox' => 'checkbox', 'radio' => 'radio', 'date' => 'date', 'file' => 'fileupload',
        ];
        foreach ($normalized as $f) {
            $gf_field = [
                'id'          => $i,
                'type'        => $type_map[$f['type']] ?? 'text',
                'label'       => $f['label'],
                'isRequired'  => $f['required'],
                'placeholder' => $f['placeholder'],
                'defaultValue'=> $f['default'],
                'inputName'   => $f['name'],
            ];
            if (in_array($f['type'], ['select', 'radio', 'checkbox'], true) && $f['options']) {
                $choices = [];
                foreach ($f['options'] as $opt) {
                    $val = is_array($opt) ? (string) ($opt['value'] ?? '') : (string) $opt;
                    $choices[] = ['text' => is_array($opt) ? (string) ($opt['label'] ?? $val) : $val, 'value' => $val];
                }
                $gf_field['choices'] = $choices;
            }
            $gf_fields[] = $gf_field;
            $i++;
        }
        $form = ['title' => $title, 'fields' => $gf_fields, 'is_active' => 1];
        $new_id = GFAPI::add_form($form);
        if (is_wp_error($new_id)) {
            return $new_id;
        }
        return [
            'plugin' => 'Gravity Forms',
            'form'   => [
                'id'        => (int) $new_id,
                'title'     => $title,
                'shortcode' => sprintf('[gravityform id="%d" title="false" description="false" ajax="true"]', (int) $new_id),
                'fields'    => array_column($normalized, 'name'),
            ],
        ];
    }

    if ($action === 'delete_form') {
        if (!$form_id) {
            return new WP_Error('missing_form_id', 'form_id is required.');
        }
        $ok = GFAPI::delete_form((int) $form_id);
        return is_wp_error($ok) ? $ok : ['plugin' => 'Gravity Forms', 'deleted' => true];
    }

    switch ($action) {
        case 'list_forms':
            $forms = GFAPI::get_forms();
            $result = [];
            foreach ($forms as $form) {
                $result[] = [
                    'id' => $form['id'],
                    'title' => $form['title'],
                    'is_active' => !empty($form['is_active']),
                    'entries_count' => (int) GFAPI::count_entries($form['id']),
                ];
            }
            return ['plugin' => 'Gravity Forms', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $form = GFAPI::get_form((int) $form_id);
            if (is_wp_error($form)) {
                return $form;
            }
            return ['plugin' => 'Gravity Forms', 'form' => $form];

        case 'get_entries':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $search = [
                'status' => 'active',
            ];
            $paging = [
                'offset' => ($page - 1) * $per_page,
                'page_size' => $per_page,
            ];
            $total = GFAPI::count_entries((int) $form_id, $search);
            $entries = GFAPI::get_entries((int) $form_id, $search, null, $paging);
            if (is_wp_error($entries)) {
                return $entries;
            }
            return ['plugin' => 'Gravity Forms', 'entries' => $entries, 'total' => (int) $total];

        case 'create_entry':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $entry_data['form_id'] = (int) $form_id;
            $result = GFAPI::add_entry($entry_data);
            if (is_wp_error($result)) {
                return $result;
            }
            $entry = GFAPI::get_entry($result);
            return ['plugin' => 'Gravity Forms', 'entry' => $entry];

        case 'delete_entry':
            if (!$entry_id) {
                return new WP_Error('missing_entry_id', 'entry_id is required.');
            }
            $result = GFAPI::delete_entry((int) $entry_id);
            if (is_wp_error($result)) {
                return $result;
            }
            return ['plugin' => 'Gravity Forms', 'deleted' => true];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

function nibwp_forms_wpforms(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page, string $title = '', array $fields = []): array|WP_Error
{
    if (!function_exists('wpforms')) {
        return new WP_Error('wpforms_unavailable', 'WPForms function not available.');
    }

    switch ($action) {
        case 'list_forms':
            $forms_raw = wpforms()->form->get('', ['posts_per_page' => -1]);
            $forms = [];
            if ($forms_raw) {
                foreach ($forms_raw as $form) {
                    $forms[] = [
                        'id' => $form->ID,
                        'title' => $form->post_title,
                        'status' => $form->post_status,
                        'created' => $form->post_date,
                    ];
                }
            }
            return ['plugin' => 'WPForms', 'forms' => $forms];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $form = wpforms()->form->get((int) $form_id);
            if (!$form) {
                return new WP_Error('form_not_found', sprintf('Form %s not found.', $form_id));
            }
            $fields = json_decode($form->post_content, true);
            return ['plugin' => 'WPForms', 'form' => [
                'id' => $form->ID,
                'title' => $form->post_title,
                'fields' => $fields['fields'] ?? [],
                'settings' => $fields['settings'] ?? [],
            ]];

        case 'get_entries':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $entries = wpforms()->entry->get_entries([
                'form_id' => (int) $form_id,
                'number' => $per_page,
                'offset' => ($page - 1) * $per_page,
            ]);
            $total = wpforms()->entry->get_entries([
                'form_id' => (int) $form_id,
            ], true);
            return ['plugin' => 'WPForms', 'entries' => $entries ?: [], 'total' => (int) $total];

        case 'create_entry':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $fields = [];
            foreach ($entry_data as $field_id => $value) {
                $fields[$field_id] = ['value' => $value, 'id' => $field_id];
            }
            $entry = [
                'form_id' => (int) $form_id,
                'fields' => $fields,
                'date' => current_time('mysql'),
            ];
            $entry_id_new = wpforms()->entry->add($entry);
            if (!$entry_id_new) {
                return new WP_Error('create_failed', 'Failed to create WPForms entry.');
            }
            return ['plugin' => 'WPForms', 'entry' => ['id' => $entry_id_new]];

        case 'delete_entry':
            if (!$entry_id) {
                return new WP_Error('missing_entry_id', 'entry_id is required.');
            }
            $deleted = wpforms()->entry->delete((int) $entry_id);
            return ['plugin' => 'WPForms', 'deleted' => (bool) $deleted];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

function nibwp_forms_fluent(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page, string $title = '', array $fields = []): array|WP_Error
{
    if (!function_exists('wpFluent')) {
        return new WP_Error('fluent_unavailable', 'wpFluent function not available.');
    }

    switch ($action) {
        case 'list_forms':
            $forms = wpFluent()->table('fluentform_forms')
                ->select(['id', 'title', 'status', 'created_at'])
                ->get();
            $result = [];
            foreach ($forms as $form) {
                $result[] = (array) $form;
            }
            return ['plugin' => 'Fluent Forms', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $form = wpFluent()->table('fluentform_forms')->find((int) $form_id);
            if (!$form) {
                return new WP_Error('form_not_found', sprintf('Form %s not found.', $form_id));
            }
            $form_data = (array) $form;
            if (!empty($form_data['form_fields'])) {
                $form_data['form_fields'] = json_decode($form_data['form_fields'], true);
            }
            return ['plugin' => 'Fluent Forms', 'form' => $form_data];

        case 'get_entries':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $total = (int) wpFluent()->table('fluentform_submissions')
                ->where('form_id', (int) $form_id)
                ->count();
            $entries = wpFluent()->table('fluentform_submissions')
                ->where('form_id', (int) $form_id)
                ->orderBy('id', 'DESC')
                ->offset(($page - 1) * $per_page)
                ->limit($per_page)
                ->get();
            $result = [];
            foreach ($entries as $entry) {
                $row = (array) $entry;
                if (!empty($row['response'])) {
                    $row['response'] = json_decode($row['response'], true);
                }
                $result[] = $row;
            }
            return ['plugin' => 'Fluent Forms', 'entries' => $result, 'total' => $total];

        case 'create_entry':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $inserted = wpFluent()->table('fluentform_submissions')->insert([
                'form_id' => (int) $form_id,
                'response' => wp_json_encode($entry_data),
                'status' => 'unread',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            return ['plugin' => 'Fluent Forms', 'entry' => ['id' => $inserted]];

        case 'delete_entry':
            if (!$entry_id) {
                return new WP_Error('missing_entry_id', 'entry_id is required.');
            }
            wpFluent()->table('fluentform_submissions')
                ->where('id', (int) $entry_id)
                ->delete();
            return ['plugin' => 'Fluent Forms', 'deleted' => true];

        case 'create_form':
            if ($title === '' || $fields === []) {
                return new WP_Error('missing_create_form_params', 'create_form requires `title` (string) and `fields` (array of field defs).');
            }
            $normalized = nibwp_forms_normalize_fields($fields);
            $built = [];
            $idx = 0;
            foreach ($normalized as $f) {
                $field = nibwp_forms_fluent_build_field($f);
                $field['index'] = $idx++;
                $built[] = $field;
            }
            // Append a submit button as the last field — Fluent Forms expects one.
            $built[] = [
                'index' => $idx,
                'element' => 'button',
                'attributes' => [
                    'type' => 'submit',
                    'class' => '',
                    'value' => 'Submit',
                ],
                'settings' => [
                    'container_class' => '',
                    'help_message' => '',
                    'button_style' => 'default',
                    'button_size' => 'md',
                    'align' => 'left',
                ],
                'editor_options' => [
                    'title' => 'Submit Button',
                    'icon_class' => 'ff-edit-button',
                    'template' => 'button',
                ],
                'uniqElKey' => 'submit_' . substr(md5((string) wp_rand()), 0, 8),
            ];
            $form_payload = [
                'fields'    => $built,
                'submitButton' => $built[count($built) - 1],
            ];
            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'fluentform_forms', [
                'title'       => $title,
                'status'      => 'published',
                'has_payment' => 0,
                'type'        => 'form',
                'form_fields' => wp_json_encode($form_payload),
                'conditions'  => null,
                'appearance_settings' => null,
                'created_by'  => get_current_user_id(),
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
            ]);
            $insert_id = (int) $wpdb->insert_id;
            return [
                'plugin'    => 'Fluent Forms',
                'form'      => [
                    'id'        => (int) $insert_id,
                    'title'     => $title,
                    'shortcode' => sprintf('[fluentform id="%d"]', (int) $insert_id),
                    'fields'    => array_column($normalized, 'name'),
                ],
            ];

        case 'delete_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            wpFluent()->table('fluentform_forms')->where('id', (int) $form_id)->delete();
            wpFluent()->table('fluentform_submissions')->where('form_id', (int) $form_id)->delete();
            return ['plugin' => 'Fluent Forms', 'deleted' => true];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action "%s" for Fluent Forms. Supported: list_forms, get_form, create_form, delete_form, get_entries, create_entry, delete_entry.', $action));
    }
}

// ---------------------------------------------------------------------
// Contact Form 7 — list / get_form / get_entries via Flamingo
// ---------------------------------------------------------------------
function nibwp_forms_cf7(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page, string $title = '', array $fields = []): array|WP_Error
{
    switch ($action) {
        case 'list_forms':
            $posts = get_posts([
                'post_type'      => 'wpcf7_contact_form',
                'posts_per_page' => -1,
                'post_status'    => ['publish', 'draft'],
            ]);
            $result = [];
            foreach ($posts as $p) {
                $result[] = [
                    'id'    => $p->ID,
                    'title' => $p->post_title,
                    'slug'  => $p->post_name,
                    'status'=> $p->post_status,
                ];
            }
            return ['plugin' => 'Contact Form 7', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $p = get_post((int) $form_id);
            if (!$p || $p->post_type !== 'wpcf7_contact_form') {
                return new WP_Error('form_not_found', sprintf('CF7 form %s not found.', $form_id));
            }
            $cf = function_exists('wpcf7_contact_form') ? wpcf7_contact_form((int) $form_id) : null;
            return [
                'plugin' => 'Contact Form 7',
                'form'   => [
                    'id'         => $p->ID,
                    'title'      => $p->post_title,
                    'form_markup' => $cf ? $cf->prop('form') : '',
                    'mail'       => $cf ? $cf->prop('mail') : null,
                    'messages'   => $cf ? $cf->prop('messages') : null,
                ],
            ];

        case 'get_entries':
            if (!class_exists('Flamingo_Inbound_Message')) {
                return new WP_Error('flamingo_missing', 'CF7 does not store entries by default. Install the Flamingo plugin to capture submissions.');
            }
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $cf = function_exists('wpcf7_contact_form') ? wpcf7_contact_form((int) $form_id) : null;
            $title = $cf ? $cf->title() : '';
            $args = [
                'post_type'      => 'flamingo_inbound',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'meta_query'     => [[
                    'key'   => '_channel',
                    'value' => sanitize_title($title),
                ]],
            ];
            $q = new WP_Query($args);
            $entries = [];
            foreach ($q->posts as $entry) {
                $fields = get_post_meta($entry->ID, '_meta', true);
                $entries[] = [
                    'id'      => $entry->ID,
                    'date'    => $entry->post_date,
                    'fields'  => is_array($fields) ? $fields : [],
                    'subject' => $entry->post_title,
                ];
            }
            return ['plugin' => 'Contact Form 7 + Flamingo', 'entries' => $entries, 'total' => (int) $q->found_posts];

        case 'create_entry':
        case 'delete_entry':
            return new WP_Error('cf7_unsupported', sprintf(
                'CF7 does not expose programmatic %s. Submit via the public REST endpoint or use the Flamingo storage plugin for managed entries.',
                $action,
            ));

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

// ---------------------------------------------------------------------
// Ninja Forms — full CRUD
// ---------------------------------------------------------------------
function nibwp_forms_ninja(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page): array|WP_Error
{
    if (!function_exists('Ninja_Forms')) {
        return new WP_Error('ninja_unavailable', 'Ninja_Forms() function not available.');
    }
    $nf = Ninja_Forms();

    switch ($action) {
        case 'list_forms':
            $forms = $nf->form()->get_forms();
            $result = [];
            foreach ($forms as $form) {
                $settings = $form->get_settings();
                $result[] = [
                    'id'    => $form->get_id(),
                    'title' => $settings['title'] ?? '',
                ];
            }
            return ['plugin' => 'Ninja Forms', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $form = $nf->form((int) $form_id)->get();
            if (!$form) {
                return new WP_Error('form_not_found', sprintf('Ninja form %s not found.', $form_id));
            }
            $fields = [];
            foreach ($nf->form((int) $form_id)->get_fields() as $field) {
                $fields[] = $field->get_settings();
            }
            return ['plugin' => 'Ninja Forms', 'form' => ['id' => $form_id, 'settings' => $form->get_settings(), 'fields' => $fields]];

        case 'get_entries':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $subs = $nf->form((int) $form_id)->get_subs();
            $entries = [];
            $offset = ($page - 1) * $per_page;
            $sliced = array_slice($subs, $offset, $per_page);
            foreach ($sliced as $sub) {
                $entries[] = [
                    'id'         => $sub->get_id(),
                    'created_at' => $sub->get_sub_date(),
                    'fields'     => $sub->get_field_values(),
                ];
            }
            return ['plugin' => 'Ninja Forms', 'entries' => $entries, 'total' => count($subs)];

        case 'create_entry':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $sub = $nf->form((int) $form_id)->sub();
            foreach ($entry_data as $k => $v) {
                $sub->update_field_value($k, $v);
            }
            $sub->save();
            return ['plugin' => 'Ninja Forms', 'entry' => ['id' => $sub->get_id()]];

        case 'delete_entry':
            if (!$entry_id) {
                return new WP_Error('missing_entry_id', 'entry_id is required.');
            }
            wp_delete_post((int) $entry_id, true);
            return ['plugin' => 'Ninja Forms', 'deleted' => true];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

// ---------------------------------------------------------------------
// Formidable Forms — full CRUD
// ---------------------------------------------------------------------
function nibwp_forms_formidable(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page): array|WP_Error
{
    if (!class_exists('FrmForm')) {
        return new WP_Error('formidable_unavailable', 'FrmForm class not available.');
    }

    switch ($action) {
        case 'list_forms':
            $forms = FrmForm::get_published_forms();
            $result = [];
            foreach ($forms as $f) {
                $result[] = ['id' => $f->id, 'title' => $f->name, 'status' => $f->status];
            }
            return ['plugin' => 'Formidable Forms', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $form = FrmForm::getOne((int) $form_id);
            if (!$form) {
                return new WP_Error('form_not_found', sprintf('Formidable form %s not found.', $form_id));
            }
            $fields = class_exists('FrmField') ? FrmField::get_all_for_form((int) $form_id) : [];
            return ['plugin' => 'Formidable Forms', 'form' => ['id' => $form->id, 'name' => $form->name, 'fields' => $fields]];

        case 'get_entries':
            if (!$form_id || !class_exists('FrmEntry')) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $entries_raw = FrmEntry::getAll(['it.form_id' => (int) $form_id], 'it.created_at DESC', $per_page * $page);
            $entries = array_slice($entries_raw, ($page - 1) * $per_page, $per_page);
            $total = FrmEntry::getRecordCount(['form_id' => (int) $form_id]);
            $result = [];
            foreach ($entries as $e) {
                $result[] = [
                    'id'         => $e->id,
                    'created_at' => $e->created_at,
                    'metas'      => class_exists('FrmEntryMeta') ? FrmEntryMeta::getAll(['item_id' => $e->id]) : [],
                ];
            }
            return ['plugin' => 'Formidable Forms', 'entries' => $result, 'total' => (int) $total];

        case 'create_entry':
            if (!$form_id || !class_exists('FrmEntry')) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $payload = ['form_id' => (int) $form_id, 'item_meta' => $entry_data];
            $new_id = FrmEntry::create($payload);
            return ['plugin' => 'Formidable Forms', 'entry' => ['id' => $new_id]];

        case 'delete_entry':
            if (!$entry_id || !class_exists('FrmEntry')) {
                return new WP_Error('missing_entry_id', 'entry_id is required.');
            }
            FrmEntry::destroy((int) $entry_id);
            return ['plugin' => 'Formidable Forms', 'deleted' => true];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

// ---------------------------------------------------------------------
// Forminator — full CRUD via Forminator_API
// ---------------------------------------------------------------------
function nibwp_forms_forminator(string $action, mixed $form_id, mixed $entry_id, array $entry_data, int $per_page, int $page): array|WP_Error
{
    if (!class_exists('Forminator_API')) {
        return new WP_Error('forminator_unavailable', 'Forminator_API class not available.');
    }

    switch ($action) {
        case 'list_forms':
            $forms = Forminator_API::get_forms(null, $page, $per_page, 'publish');
            $result = [];
            foreach ((array) $forms as $f) {
                $result[] = ['id' => $f->id, 'name' => $f->name, 'status' => $f->status];
            }
            return ['plugin' => 'Forminator', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $form = Forminator_API::get_form((int) $form_id);
            if (is_wp_error($form)) {
                return $form;
            }
            return ['plugin' => 'Forminator', 'form' => $form];

        case 'get_entries':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $entries_obj = Forminator_API::get_entries((int) $form_id, $page, $per_page);
            $entries = [];
            foreach ((array) $entries_obj as $e) {
                $entries[] = ['id' => $e->entry_id ?? null, 'date_created' => $e->date_created ?? null, 'meta_data' => $e->meta_data ?? []];
            }
            return ['plugin' => 'Forminator', 'entries' => $entries];

        case 'create_entry':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $new_id = Forminator_API::add_form_entry((int) $form_id, $entry_data);
            if (is_wp_error($new_id)) {
                return $new_id;
            }
            return ['plugin' => 'Forminator', 'entry' => ['id' => $new_id]];

        case 'delete_entry':
            if (!$form_id || !$entry_id) {
                return new WP_Error('missing_ids', 'form_id and entry_id are required.');
            }
            Forminator_API::delete_form_entries((int) $form_id, [(int) $entry_id]);
            return ['plugin' => 'Forminator', 'deleted' => true];

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

// =====================================================================
// CRM & MARKETING (category: 'crm')
// =====================================================================

wp_register_ability('nibwp/fluentcrm-manage', [
    'label' => __('FluentCRM – Contacts & Campaigns', domain: 'nibwp'),
    'description' => __(
        'Manage FluentCRM contacts, tags, lists, and campaigns. Create/update contacts, add/remove tags, and get campaign statistics.',
        domain: 'nibwp',
    ),
    'category' => 'crm',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_contacts', 'get_contact', 'create_contact', 'update_contact', 'add_tag', 'remove_tag', 'list_tags', 'list_campaigns', 'get_campaign_stats'],
                'description' => 'The action to perform.',
            ],
            'contact_data' => [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string'],
                    'first_name' => ['type' => 'string'],
                    'last_name' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                    'lists' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                ],
                'description' => 'Contact data for create/update. Email is required for create.',
            ],
            'email' => ['type' => 'string', 'description' => 'Email to look up a contact.'],
            'contact_id' => ['type' => 'integer', 'description' => 'Contact ID.'],
            'tag_id' => ['type' => ['integer', 'string'], 'description' => 'Tag ID or name for add_tag/remove_tag.'],
            'campaign_id' => ['type' => 'integer', 'description' => 'Campaign ID for get_campaign_stats.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'contacts' => ['type' => 'array'],
            'contact' => ['type' => 'object'],
            'tags' => ['type' => 'array'],
            'campaigns' => ['type' => 'array'],
            'stats' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_fluentcrm_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage FluentCRM contacts, tags, and campaigns.',
                '',
                'ACTIONS:',
                '- list_contacts: List contacts. Supports per_page/page.',
                '- get_contact: Get a contact by email or contact_id.',
                '- create_contact: Create a contact. Requires contact_data with email.',
                '- update_contact: Update a contact. Requires contact_id or email plus contact_data.',
                '- add_tag: Add a tag to a contact. Requires contact_id/email and tag_id.',
                '- remove_tag: Remove a tag from a contact. Requires contact_id/email and tag_id.',
                '- list_tags: List all tags.',
                '- list_campaigns: List all email campaigns.',
                '- get_campaign_stats: Get stats for a campaign. Requires campaign_id.',
                '',
                'CONTACT DATA:',
                '- email (required for create), first_name, last_name, status (subscribed/unsubscribed/pending).',
                '- tags: Array of tag IDs to attach.',
                '- lists: Array of list IDs to attach.',
                '',
                'REQUIRES: FluentCRM plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fluentcrm_manage(array $input): array|WP_Error
{
    if (!defined('FLUENTCRM')) {
        return new WP_Error('fluentcrm_not_active', 'FluentCRM is not active.');
    }

    if (!function_exists('FluentCrmApi')) {
        return new WP_Error('fluentcrm_api_missing', 'FluentCrmApi function not available.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        $contactApi = FluentCrmApi('contacts');

        switch ($action) {
            case 'list_contacts':
                $contacts = $contactApi->getInstance()
                    ->orderBy('id', 'DESC')
                    ->paginate($per_page, ['*'], 'page', $page);
                $items = [];
                foreach ($contacts->items() as $contact) {
                    $items[] = [
                        'id' => $contact->id,
                        'email' => $contact->email,
                        'first_name' => $contact->first_name,
                        'last_name' => $contact->last_name,
                        'status' => $contact->status,
                        'created_at' => $contact->created_at,
                    ];
                }
                return ['contacts' => $items, 'total' => $contacts->total()];

            case 'get_contact':
                $contact = null;
                if (!empty($input['email'])) {
                    $contact = $contactApi->getContactByUserRef($input['email']);
                } elseif (!empty($input['contact_id'])) {
                    $contact = $contactApi->getContact((int) $input['contact_id']);
                }
                if (!$contact) {
                    return new WP_Error('contact_not_found', 'Contact not found.');
                }
                return ['contact' => $contact->toArray()];

            case 'create_contact':
                $data = $input['contact_data'] ?? [];
                if (empty($data['email'])) {
                    return new WP_Error('missing_email', 'contact_data.email is required.');
                }
                $contact = $contactApi->createOrUpdate($data);
                if (!$contact) {
                    return new WP_Error('create_failed', 'Failed to create contact.');
                }
                if (!empty($data['tags'])) {
                    $contact->attachTags($data['tags']);
                }
                if (!empty($data['lists'])) {
                    $contact->attachLists($data['lists']);
                }
                return ['contact' => $contact->toArray()];

            case 'update_contact':
                $contact = null;
                if (!empty($input['contact_id'])) {
                    $contact = $contactApi->getContact((int) $input['contact_id']);
                } elseif (!empty($input['email'])) {
                    $contact = $contactApi->getContactByUserRef($input['email']);
                }
                if (!$contact) {
                    return new WP_Error('contact_not_found', 'Contact not found.');
                }
                $data = $input['contact_data'] ?? [];
                if (!empty($data)) {
                    $contact->fill($data);
                    $contact->save();
                }
                if (!empty($data['tags'])) {
                    $contact->attachTags($data['tags']);
                }
                if (!empty($data['lists'])) {
                    $contact->attachLists($data['lists']);
                }
                return ['contact' => $contact->toArray()];

            case 'add_tag':
                $contact = null;
                if (!empty($input['contact_id'])) {
                    $contact = $contactApi->getContact((int) $input['contact_id']);
                } elseif (!empty($input['email'])) {
                    $contact = $contactApi->getContactByUserRef($input['email']);
                }
                if (!$contact) {
                    return new WP_Error('contact_not_found', 'Contact not found.');
                }
                $tag_id = $input['tag_id'] ?? null;
                if (!$tag_id) {
                    return new WP_Error('missing_tag_id', 'tag_id is required.');
                }
                $contact->attachTags([$tag_id]);
                return ['contact' => $contact->toArray()];

            case 'remove_tag':
                $contact = null;
                if (!empty($input['contact_id'])) {
                    $contact = $contactApi->getContact((int) $input['contact_id']);
                } elseif (!empty($input['email'])) {
                    $contact = $contactApi->getContactByUserRef($input['email']);
                }
                if (!$contact) {
                    return new WP_Error('contact_not_found', 'Contact not found.');
                }
                $tag_id = $input['tag_id'] ?? null;
                if (!$tag_id) {
                    return new WP_Error('missing_tag_id', 'tag_id is required.');
                }
                $contact->detachTags([$tag_id]);
                return ['contact' => $contact->toArray()];

            case 'list_tags':
                $tags = FluentCrmApi('tags')->all();
                $result = [];
                foreach ($tags as $tag) {
                    $result[] = ['id' => $tag->id, 'title' => $tag->title, 'slug' => $tag->slug];
                }
                return ['tags' => $result];

            case 'list_campaigns':
                $campaignModel = FluentCrmApi('campaigns');
                $campaigns = $campaignModel->getInstance()->orderBy('id', 'DESC')->get();
                $result = [];
                foreach ($campaigns as $campaign) {
                    $result[] = [
                        'id' => $campaign->id,
                        'title' => $campaign->title,
                        'status' => $campaign->status,
                        'type' => $campaign->type ?? 'campaign',
                        'created_at' => $campaign->created_at,
                    ];
                }
                return ['campaigns' => $result];

            case 'get_campaign_stats':
                $campaign_id = (int) ($input['campaign_id'] ?? 0);
                if ($campaign_id <= 0) {
                    return new WP_Error('missing_campaign_id', 'campaign_id is required.');
                }
                $campaign = FluentCrmApi('campaigns')->getInstance()->find($campaign_id);
                if (!$campaign) {
                    return new WP_Error('campaign_not_found', 'Campaign not found.');
                }
                $stats = $campaign->stat();
                return ['stats' => [
                    'id' => $campaign->id,
                    'title' => $campaign->title,
                    'status' => $campaign->status,
                    'total' => $stats['total'] ?? 0,
                    'sent' => $stats['sent'] ?? 0,
                    'opens' => $stats['opens'] ?? 0,
                    'clicks' => $stats['clicks'] ?? 0,
                    'unsubscribes' => $stats['unsubscribes'] ?? 0,
                ]];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('fluentcrm_error', $e->getMessage());
    }
}

// =====================================================================
// E-COMMERCE (category: 'ecommerce')
// =====================================================================


// --- Ability: nibwp/edd-manage ---

wp_register_ability('nibwp/edd-manage', [
    'label' => __('Easy Digital Downloads – Management', domain: 'nibwp'),
    'description' => __(
        'Manage Easy Digital Downloads products, payments, and stats. List downloads, get payment details, and retrieve earnings statistics.',
        domain: 'nibwp',
    ),
    'category' => 'ecommerce',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_downloads', 'get_download', 'list_payments', 'get_payment', 'get_stats'],
                'description' => 'The action to perform.',
            ],
            'download_id' => ['type' => 'integer', 'description' => 'Download/product ID.'],
            'payment_id' => ['type' => 'integer', 'description' => 'Payment ID.'],
            'status' => ['type' => 'string', 'description' => 'Filter payments by status (complete, pending, refunded, etc.).'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'downloads' => ['type' => 'array'],
            'download' => ['type' => 'object'],
            'payments' => ['type' => 'array'],
            'payment' => ['type' => 'object'],
            'stats' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_edd_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage Easy Digital Downloads products and payments.',
                '',
                'ACTIONS:',
                '- list_downloads: List download products. Supports per_page/page.',
                '- get_download: Get a download by download_id.',
                '- list_payments: List payments. Supports status filter and per_page/page.',
                '- get_payment: Get payment details. Requires payment_id.',
                '- get_stats: Get overall earnings and sales statistics.',
                '',
                'REQUIRES: Easy Digital Downloads plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_edd_manage(array $input): array|WP_Error
{
    if (!class_exists('Easy_Digital_Downloads')) {
        return new WP_Error('edd_not_active', 'Easy Digital Downloads is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_downloads':
                $args = [
                    'post_type' => 'download',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                ];
                $query = new WP_Query($args);
                $downloads = [];
                foreach ($query->posts as $post) {
                    $downloads[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'price' => function_exists('edd_get_download_price') ? edd_get_download_price($post->ID) : get_post_meta($post->ID, 'edd_price', true),
                        'sales' => function_exists('edd_get_download_sales_stats') ? (int) edd_get_download_sales_stats($post->ID) : 0,
                        'status' => $post->post_status,
                        'date' => $post->post_date,
                    ];
                }
                return ['downloads' => $downloads, 'total' => $query->found_posts];

            case 'get_download':
                $download_id = (int) ($input['download_id'] ?? 0);
                if ($download_id <= 0) {
                    return new WP_Error('missing_download_id', 'download_id is required.');
                }
                $post = get_post($download_id);
                if (!$post || $post->post_type !== 'download') {
                    return new WP_Error('download_not_found', sprintf('Download %d not found.', $download_id));
                }
                $download = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'price' => function_exists('edd_get_download_price') ? edd_get_download_price($post->ID) : get_post_meta($post->ID, 'edd_price', true),
                    'sales' => function_exists('edd_get_download_sales_stats') ? (int) edd_get_download_sales_stats($post->ID) : 0,
                    'earnings' => function_exists('edd_get_download_earnings_stats') ? edd_get_download_earnings_stats($post->ID) : 0,
                    'status' => $post->post_status,
                    'date' => $post->post_date,
                ];
                if (function_exists('edd_get_download_files')) {
                    $files = edd_get_download_files($post->ID);
                    $download['files'] = $files ? array_values($files) : [];
                }
                return ['download' => $download];

            case 'list_payments':
                $args = [
                    'number' => $per_page,
                    'offset' => ($page - 1) * $per_page,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ];
                if (!empty($input['status'])) {
                    $args['status'] = (string) $input['status'];
                }
                if (function_exists('edd_get_payments')) {
                    $payments_raw = edd_get_payments($args);
                } else {
                    $payments_raw = [];
                }
                $payments = [];
                foreach ($payments_raw as $payment) {
                    if (is_object($payment)) {
                        $payments[] = [
                            'id' => $payment->ID,
                            'email' => $payment->email ?? '',
                            'total' => $payment->total ?? 0,
                            'status' => $payment->status ?? '',
                            'date' => $payment->date ?? '',
                            'gateway' => $payment->gateway ?? '',
                        ];
                    }
                }
                return ['payments' => $payments, 'total' => count($payments)];

            case 'get_payment':
                $payment_id = (int) ($input['payment_id'] ?? 0);
                if ($payment_id <= 0) {
                    return new WP_Error('missing_payment_id', 'payment_id is required.');
                }
                if (!function_exists('edd_get_payment')) {
                    return new WP_Error('edd_function_missing', 'edd_get_payment function not available.');
                }
                $payment = edd_get_payment($payment_id);
                if (!$payment) {
                    return new WP_Error('payment_not_found', sprintf('Payment %d not found.', $payment_id));
                }
                return ['payment' => [
                    'id' => $payment->ID,
                    'email' => $payment->email,
                    'first_name' => $payment->first_name,
                    'last_name' => $payment->last_name,
                    'total' => $payment->total,
                    'subtotal' => $payment->subtotal,
                    'tax' => $payment->tax,
                    'status' => $payment->status,
                    'date' => $payment->date,
                    'gateway' => $payment->gateway,
                    'cart_details' => $payment->cart_details,
                ]];

            case 'get_stats':
                $stats = [
                    'total_earnings' => function_exists('edd_get_total_earnings') ? edd_get_total_earnings() : 0,
                    'total_sales' => function_exists('edd_get_total_sales') ? edd_get_total_sales() : 0,
                ];
                if (function_exists('edd_get_earnings_by_date')) {
                    $stats['earnings_today'] = edd_get_earnings_by_date(
                        (int) date('j'), (int) date('n'), (int) date('Y')
                    );
                    $stats['earnings_this_month'] = edd_get_earnings_by_date(
                        null, (int) date('n'), (int) date('Y')
                    );
                }
                return ['stats' => $stats];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('edd_error', $e->getMessage());
    }
}

// =====================================================================
// EVENTS (category: 'events')
// =====================================================================

wp_register_ability('nibwp/events-manage', [
    'label' => __('The Events Calendar – Management', domain: 'nibwp'),
    'description' => __(
        'Manage The Events Calendar events, venues, and organizers. Create, update, and list events with full details.',
        domain: 'nibwp',
    ),
    'category' => 'events',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_events', 'get_event', 'create_event', 'update_event', 'list_venues', 'create_venue', 'list_organizers'],
                'description' => 'The action to perform.',
            ],
            'event_id' => ['type' => 'integer', 'description' => 'Event post ID.'],
            'event_data' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'start_date' => ['type' => 'string', 'description' => 'Start date/time (Y-m-d H:i:s).'],
                    'end_date' => ['type' => 'string', 'description' => 'End date/time (Y-m-d H:i:s).'],
                    'venue_id' => ['type' => 'integer'],
                    'organizer_id' => ['type' => 'integer'],
                    'cost' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                ],
                'description' => 'Event data for create/update.',
            ],
            'venue_data' => [
                'type' => 'object',
                'properties' => [
                    'venue' => ['type' => 'string', 'description' => 'Venue name.'],
                    'address' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                    'state' => ['type' => 'string'],
                    'country' => ['type' => 'string'],
                    'zip' => ['type' => 'string'],
                ],
                'description' => 'Venue data for create_venue.',
            ],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'events' => ['type' => 'array'],
            'event' => ['type' => 'object'],
            'venues' => ['type' => 'array'],
            'venue' => ['type' => 'object'],
            'organizers' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_events_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage The Events Calendar events, venues, and organizers.',
                '',
                'ACTIONS:',
                '- list_events: List upcoming events. Supports per_page/page.',
                '- get_event: Get event details. Requires event_id.',
                '- create_event: Create an event. Requires event_data with title, start_date, end_date.',
                '- update_event: Update an event. Requires event_id and event_data.',
                '- list_venues: List all venues.',
                '- create_venue: Create a venue. Requires venue_data with venue (name).',
                '- list_organizers: List all organizers.',
                '',
                'DATE FORMAT: Y-m-d H:i:s (e.g. 2025-06-15 09:00:00).',
                '',
                'REQUIRES: The Events Calendar plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_events_manage(array $input): array|WP_Error
{
    if (!class_exists('Tribe__Events__Main')) {
        return new WP_Error('tec_not_active', 'The Events Calendar is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_events':
                if (!function_exists('tribe_get_events')) {
                    return new WP_Error('tec_function_missing', 'tribe_get_events not available.');
                }
                $events_raw = tribe_get_events([
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                    'start_date' => 'now',
                ]);
                $events = [];
                foreach ($events_raw as $event) {
                    $events[] = [
                        'id' => $event->ID,
                        'title' => $event->post_title,
                        'start_date' => get_post_meta($event->ID, '_EventStartDate', true),
                        'end_date' => get_post_meta($event->ID, '_EventEndDate', true),
                        'venue_id' => (int) get_post_meta($event->ID, '_EventVenueID', true),
                        'organizer_id' => (int) get_post_meta($event->ID, '_EventOrganizerID', true),
                        'cost' => get_post_meta($event->ID, '_EventCost', true),
                        'url' => get_post_meta($event->ID, '_EventURL', true),
                        'status' => $event->post_status,
                    ];
                }
                return ['events' => $events, 'total' => count($events)];

            case 'get_event':
                $event_id = (int) ($input['event_id'] ?? 0);
                if ($event_id <= 0) {
                    return new WP_Error('missing_event_id', 'event_id is required.');
                }
                $event = get_post($event_id);
                if (!$event || $event->post_type !== 'tribe_events') {
                    return new WP_Error('event_not_found', sprintf('Event %d not found.', $event_id));
                }
                $venue_id = (int) get_post_meta($event_id, '_EventVenueID', true);
                $venue_name = $venue_id ? get_the_title($venue_id) : '';
                $organizer_id = (int) get_post_meta($event_id, '_EventOrganizerID', true);
                $organizer_name = $organizer_id ? get_the_title($organizer_id) : '';
                return ['event' => [
                    'id' => $event->ID,
                    'title' => $event->post_title,
                    'description' => $event->post_content,
                    'start_date' => get_post_meta($event_id, '_EventStartDate', true),
                    'end_date' => get_post_meta($event_id, '_EventEndDate', true),
                    'venue_id' => $venue_id,
                    'venue_name' => $venue_name,
                    'organizer_id' => $organizer_id,
                    'organizer_name' => $organizer_name,
                    'cost' => get_post_meta($event_id, '_EventCost', true),
                    'url' => get_post_meta($event_id, '_EventURL', true),
                    'status' => $event->post_status,
                ]];

            case 'create_event':
                $data = $input['event_data'] ?? [];
                if (empty($data['title']) || empty($data['start_date']) || empty($data['end_date'])) {
                    return new WP_Error('missing_data', 'event_data requires title, start_date, and end_date.');
                }
                if (!function_exists('tribe_create_event')) {
                    return new WP_Error('tec_function_missing', 'tribe_create_event not available.');
                }
                $args = [
                    'post_title' => $data['title'],
                    'post_content' => $data['description'] ?? '',
                    'post_status' => 'publish',
                    'EventStartDate' => $data['start_date'],
                    'EventEndDate' => $data['end_date'],
                ];
                if (!empty($data['venue_id'])) {
                    $args['EventVenueID'] = (int) $data['venue_id'];
                }
                if (!empty($data['organizer_id'])) {
                    $args['EventOrganizerID'] = (int) $data['organizer_id'];
                }
                if (isset($data['cost'])) {
                    $args['EventCost'] = (string) $data['cost'];
                }
                if (!empty($data['url'])) {
                    $args['EventURL'] = (string) $data['url'];
                }
                $event_id = tribe_create_event($args);
                if (!$event_id || is_wp_error($event_id)) {
                    return is_wp_error($event_id) ? $event_id : new WP_Error('create_failed', 'Failed to create event.');
                }
                return ['event' => ['id' => $event_id, 'title' => $data['title']]];

            case 'update_event':
                $event_id = (int) ($input['event_id'] ?? 0);
                if ($event_id <= 0) {
                    return new WP_Error('missing_event_id', 'event_id is required.');
                }
                $event = get_post($event_id);
                if (!$event || $event->post_type !== 'tribe_events') {
                    return new WP_Error('event_not_found', sprintf('Event %d not found.', $event_id));
                }
                if (!function_exists('tribe_update_event')) {
                    return new WP_Error('tec_function_missing', 'tribe_update_event not available.');
                }
                $data = $input['event_data'] ?? [];
                $args = [];
                if (!empty($data['title'])) {
                    $args['post_title'] = $data['title'];
                }
                if (isset($data['description'])) {
                    $args['post_content'] = $data['description'];
                }
                if (!empty($data['start_date'])) {
                    $args['EventStartDate'] = $data['start_date'];
                }
                if (!empty($data['end_date'])) {
                    $args['EventEndDate'] = $data['end_date'];
                }
                if (!empty($data['venue_id'])) {
                    $args['EventVenueID'] = (int) $data['venue_id'];
                }
                if (!empty($data['organizer_id'])) {
                    $args['EventOrganizerID'] = (int) $data['organizer_id'];
                }
                if (isset($data['cost'])) {
                    $args['EventCost'] = (string) $data['cost'];
                }
                if (!empty($data['url'])) {
                    $args['EventURL'] = (string) $data['url'];
                }
                $result = tribe_update_event($event_id, $args);
                if (!$result || is_wp_error($result)) {
                    return is_wp_error($result) ? $result : new WP_Error('update_failed', 'Failed to update event.');
                }
                return ['event' => ['id' => $event_id, 'updated' => true]];

            case 'list_venues':
                $venues_raw = get_posts([
                    'post_type' => 'tribe_venue',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                    'post_status' => 'publish',
                ]);
                $venues = [];
                foreach ($venues_raw as $venue) {
                    $venues[] = [
                        'id' => $venue->ID,
                        'name' => $venue->post_title,
                        'address' => get_post_meta($venue->ID, '_VenueAddress', true),
                        'city' => get_post_meta($venue->ID, '_VenueCity', true),
                        'state' => get_post_meta($venue->ID, '_VenueState', true),
                        'country' => get_post_meta($venue->ID, '_VenueCountry', true),
                        'zip' => get_post_meta($venue->ID, '_VenueZip', true),
                    ];
                }
                return ['venues' => $venues];

            case 'create_venue':
                $data = $input['venue_data'] ?? [];
                if (empty($data['venue'])) {
                    return new WP_Error('missing_venue_name', 'venue_data.venue (name) is required.');
                }
                if (!function_exists('tribe_create_venue')) {
                    // Fallback to manual creation.
                    $venue_id = wp_insert_post([
                        'post_type' => 'tribe_venue',
                        'post_title' => $data['venue'],
                        'post_status' => 'publish',
                    ]);
                    if (is_wp_error($venue_id)) {
                        return $venue_id;
                    }
                    if (!empty($data['address'])) update_post_meta($venue_id, '_VenueAddress', $data['address']);
                    if (!empty($data['city'])) update_post_meta($venue_id, '_VenueCity', $data['city']);
                    if (!empty($data['state'])) update_post_meta($venue_id, '_VenueState', $data['state']);
                    if (!empty($data['country'])) update_post_meta($venue_id, '_VenueCountry', $data['country']);
                    if (!empty($data['zip'])) update_post_meta($venue_id, '_VenueZip', $data['zip']);
                } else {
                    $venue_id = tribe_create_venue($data);
                    if (!$venue_id || is_wp_error($venue_id)) {
                        return is_wp_error($venue_id) ? $venue_id : new WP_Error('create_failed', 'Failed to create venue.');
                    }
                }
                return ['venue' => ['id' => $venue_id, 'name' => $data['venue']]];

            case 'list_organizers':
                $organizers_raw = get_posts([
                    'post_type' => 'tribe_organizer',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                    'post_status' => 'publish',
                ]);
                $organizers = [];
                foreach ($organizers_raw as $org) {
                    $organizers[] = [
                        'id' => $org->ID,
                        'name' => $org->post_title,
                        'phone' => get_post_meta($org->ID, '_OrganizerPhone', true),
                        'email' => get_post_meta($org->ID, '_OrganizerEmail', true),
                        'website' => get_post_meta($org->ID, '_OrganizerWebsite', true),
                    ];
                }
                return ['organizers' => $organizers];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('events_error', $e->getMessage());
    }
}

// =====================================================================
// COMMUNITY (category: 'community')
// =====================================================================

wp_register_ability('nibwp/buddypress-manage', [
    'label' => __('BuddyPress – Community Management', domain: 'nibwp'),
    'description' => __(
        'Manage BuddyPress/BuddyBoss community features. List members, groups, and activity. Create groups and post activity updates.',
        domain: 'nibwp',
    ),
    'category' => 'community',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_members', 'get_member', 'list_groups', 'get_group', 'create_group', 'list_activity', 'post_activity'],
                'description' => 'The action to perform.',
            ],
            'user_id' => ['type' => 'integer', 'description' => 'User/member ID.'],
            'group_id' => ['type' => 'integer', 'description' => 'Group ID.'],
            'group_data' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['public', 'private', 'hidden']],
                ],
                'description' => 'Group data for create_group.',
            ],
            'activity_data' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string'],
                    'user_id' => ['type' => 'integer'],
                    'component' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ],
                'description' => 'Activity data for post_activity.',
            ],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'members' => ['type' => 'array'],
            'member' => ['type' => 'object'],
            'groups' => ['type' => 'array'],
            'group' => ['type' => 'object'],
            'activities' => ['type' => 'array'],
            'activity' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_buddypress_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage BuddyPress/BuddyBoss community features.',
                '',
                'ACTIONS:',
                '- list_members: List community members. Supports per_page/page.',
                '- get_member: Get member profile. Requires user_id.',
                '- list_groups: List groups. Supports per_page/page.',
                '- get_group: Get group details. Requires group_id.',
                '- create_group: Create a group. Requires group_data with name.',
                '- list_activity: List activity stream. Supports per_page/page.',
                '- post_activity: Post an activity update. Requires activity_data with content.',
                '',
                'REQUIRES: BuddyPress or BuddyBoss plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_buddypress_manage(array $input): array|WP_Error
{
    if (!class_exists('BuddyPress')) {
        return new WP_Error('buddypress_not_active', 'BuddyPress/BuddyBoss is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_members':
                if (!function_exists('bp_core_get_users')) {
                    return new WP_Error('bp_function_missing', 'bp_core_get_users not available.');
                }
                $result = bp_core_get_users([
                    'per_page' => $per_page,
                    'page' => $page,
                    'type' => 'active',
                ]);
                $members = [];
                if (!empty($result['users'])) {
                    foreach ($result['users'] as $user) {
                        $members[] = [
                            'id' => $user->ID ?? $user->id,
                            'display_name' => $user->display_name ?? $user->fullname ?? '',
                            'user_login' => $user->user_login ?? '',
                            'user_email' => $user->user_email ?? '',
                            'last_activity' => $user->last_activity ?? '',
                        ];
                    }
                }
                return ['members' => $members, 'total' => (int) ($result['total'] ?? 0)];

            case 'get_member':
                $user_id = (int) ($input['user_id'] ?? 0);
                if ($user_id <= 0) {
                    return new WP_Error('missing_user_id', 'user_id is required.');
                }
                $user = get_userdata($user_id);
                if (!$user) {
                    return new WP_Error('member_not_found', sprintf('Member %d not found.', $user_id));
                }
                $member = [
                    'id' => $user->ID,
                    'display_name' => $user->display_name,
                    'user_login' => $user->user_login,
                    'user_email' => $user->user_email,
                    'registered' => $user->user_registered,
                ];
                if (function_exists('bp_get_member_last_active')) {
                    $member['last_active'] = bp_get_user_last_activity($user_id);
                }
                if (function_exists('bp_get_profile_field_data')) {
                    $member['profile_fields'] = [];
                    if (function_exists('bp_has_profile')) {
                        global $profile_template;
                        if (bp_has_profile(['user_id' => $user_id])) {
                            while (bp_profile_groups()) {
                                bp_the_profile_group();
                                while (bp_profile_fields()) {
                                    bp_the_profile_field();
                                    $member['profile_fields'][$profile_template->field->name] = $profile_template->field->data->value ?? '';
                                }
                            }
                        }
                    }
                }
                return ['member' => $member];

            case 'list_groups':
                if (!function_exists('groups_get_groups')) {
                    return new WP_Error('bp_function_missing', 'groups_get_groups not available.');
                }
                $result = groups_get_groups([
                    'per_page' => $per_page,
                    'page' => $page,
                    'orderby' => 'date_created',
                    'order' => 'DESC',
                ]);
                $groups = [];
                if (!empty($result['groups'])) {
                    foreach ($result['groups'] as $group) {
                        $groups[] = [
                            'id' => $group->id,
                            'name' => $group->name,
                            'description' => $group->description,
                            'status' => $group->status,
                            'total_member_count' => (int) ($group->total_member_count ?? 0),
                            'date_created' => $group->date_created,
                        ];
                    }
                }
                return ['groups' => $groups, 'total' => (int) ($result['total'] ?? 0)];

            case 'get_group':
                $group_id = (int) ($input['group_id'] ?? 0);
                if ($group_id <= 0) {
                    return new WP_Error('missing_group_id', 'group_id is required.');
                }
                if (!function_exists('groups_get_group')) {
                    return new WP_Error('bp_function_missing', 'groups_get_group not available.');
                }
                $group = groups_get_group($group_id);
                if (!$group || empty($group->id)) {
                    return new WP_Error('group_not_found', sprintf('Group %d not found.', $group_id));
                }
                return ['group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'status' => $group->status,
                    'total_member_count' => (int) ($group->total_member_count ?? 0),
                    'date_created' => $group->date_created,
                    'creator_id' => $group->creator_id,
                ]];

            case 'create_group':
                $data = $input['group_data'] ?? [];
                if (empty($data['name'])) {
                    return new WP_Error('missing_name', 'group_data.name is required.');
                }
                if (!function_exists('groups_create_group')) {
                    return new WP_Error('bp_function_missing', 'groups_create_group not available.');
                }
                $group_id = groups_create_group([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? '',
                    'status' => $data['status'] ?? 'public',
                    'creator_id' => get_current_user_id(),
                ]);
                if (!$group_id) {
                    return new WP_Error('create_failed', 'Failed to create group.');
                }
                return ['group' => ['id' => $group_id, 'name' => $data['name']]];

            case 'list_activity':
                if (!function_exists('bp_activity_get')) {
                    return new WP_Error('bp_function_missing', 'bp_activity_get not available.');
                }
                $result = bp_activity_get([
                    'per_page' => $per_page,
                    'page' => $page,
                ]);
                $activities = [];
                if (!empty($result['activities'])) {
                    foreach ($result['activities'] as $activity) {
                        $activities[] = [
                            'id' => $activity->id,
                            'user_id' => $activity->user_id,
                            'component' => $activity->component,
                            'type' => $activity->type,
                            'action' => $activity->action,
                            'content' => $activity->content,
                            'date_recorded' => $activity->date_recorded,
                        ];
                    }
                }
                return ['activities' => $activities, 'total' => (int) ($result['total'] ?? 0)];

            case 'post_activity':
                $data = $input['activity_data'] ?? [];
                if (empty($data['content'])) {
                    return new WP_Error('missing_content', 'activity_data.content is required.');
                }
                if (!function_exists('bp_activity_add')) {
                    return new WP_Error('bp_function_missing', 'bp_activity_add not available.');
                }
                $activity_id = bp_activity_add([
                    'content' => $data['content'],
                    'user_id' => $data['user_id'] ?? get_current_user_id(),
                    'component' => $data['component'] ?? 'activity',
                    'type' => $data['type'] ?? 'activity_update',
                ]);
                if (!$activity_id) {
                    return new WP_Error('post_failed', 'Failed to post activity.');
                }
                return ['activity' => ['id' => $activity_id]];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('buddypress_error', $e->getMessage());
    }
}

// =====================================================================
// LMS (category: 'lms')
// =====================================================================

wp_register_ability('nibwp/learndash-manage', [
    'label' => __('LearnDash – LMS Management', domain: 'nibwp'),
    'description' => __(
        'Manage LearnDash LMS courses, lessons, quizzes, enrollments, and user progress.',
        domain: 'nibwp',
    ),
    'category' => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_courses', 'get_course', 'list_lessons', 'list_quizzes', 'enroll_user', 'get_user_progress', 'list_enrolled_users'],
                'description' => 'The action to perform.',
            ],
            'course_id' => ['type' => 'integer', 'description' => 'Course post ID.'],
            'user_id' => ['type' => 'integer', 'description' => 'User ID.'],
            'lesson_id' => ['type' => 'integer', 'description' => 'Lesson post ID.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'courses' => ['type' => 'array'],
            'course' => ['type' => 'object'],
            'lessons' => ['type' => 'array'],
            'quizzes' => ['type' => 'array'],
            'progress' => ['type' => 'object'],
            'users' => ['type' => 'array'],
            'enrolled' => ['type' => 'boolean'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_learndash_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage LearnDash LMS courses, content, and enrollments.',
                '',
                'ACTIONS:',
                '- list_courses: List all courses. Supports per_page/page.',
                '- get_course: Get course details. Requires course_id.',
                '- list_lessons: List lessons for a course. Requires course_id.',
                '- list_quizzes: List quizzes for a course. Requires course_id.',
                '- enroll_user: Enroll a user in a course. Requires course_id and user_id.',
                '- get_user_progress: Get user progress in a course. Requires course_id and user_id.',
                '- list_enrolled_users: List users enrolled in a course. Requires course_id.',
                '',
                'REQUIRES: LearnDash LMS plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_learndash_manage(array $input): array|WP_Error
{
    if (!defined('LEARNDASH_VERSION')) {
        return new WP_Error('learndash_not_active', 'LearnDash LMS is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_courses':
                $args = [
                    'post_type' => 'sfwd-courses',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                ];
                $query = new WP_Query($args);
                $courses = [];
                foreach ($query->posts as $post) {
                    $meta = get_post_meta($post->ID, '_sfwd-courses', true);
                    $courses[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'status' => $post->post_status,
                        'price' => $meta['sfwd-courses_course_price'] ?? '',
                        'price_type' => $meta['sfwd-courses_course_price_type'] ?? 'open',
                        'date' => $post->post_date,
                    ];
                }
                return ['courses' => $courses, 'total' => $query->found_posts];

            case 'get_course':
                $course_id = (int) ($input['course_id'] ?? 0);
                if ($course_id <= 0) {
                    return new WP_Error('missing_course_id', 'course_id is required.');
                }
                $post = get_post($course_id);
                if (!$post || $post->post_type !== 'sfwd-courses') {
                    return new WP_Error('course_not_found', sprintf('Course %d not found.', $course_id));
                }
                $meta = get_post_meta($post->ID, '_sfwd-courses', true);
                $lessons_count = 0;
                if (function_exists('learndash_get_lesson_list')) {
                    $lessons = learndash_get_lesson_list($course_id);
                    $lessons_count = is_array($lessons) ? count($lessons) : 0;
                }
                return ['course' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'price' => $meta['sfwd-courses_course_price'] ?? '',
                    'price_type' => $meta['sfwd-courses_course_price_type'] ?? 'open',
                    'prerequisites' => $meta['sfwd-courses_course_prerequisite'] ?? [],
                    'lessons_count' => $lessons_count,
                    'status' => $post->post_status,
                    'date' => $post->post_date,
                ]];

            case 'list_lessons':
                $course_id = (int) ($input['course_id'] ?? 0);
                if ($course_id <= 0) {
                    return new WP_Error('missing_course_id', 'course_id is required.');
                }
                if (!function_exists('learndash_get_lesson_list')) {
                    return new WP_Error('ld_function_missing', 'learndash_get_lesson_list not available.');
                }
                $lessons_raw = learndash_get_lesson_list($course_id);
                $lessons = [];
                if (is_array($lessons_raw)) {
                    foreach ($lessons_raw as $lesson) {
                        $lessons[] = [
                            'id' => $lesson->ID,
                            'title' => $lesson->post_title,
                            'order' => $lesson->menu_order ?? 0,
                            'status' => $lesson->post_status,
                        ];
                    }
                }
                return ['lessons' => $lessons, 'total' => count($lessons)];

            case 'list_quizzes':
                $course_id = (int) ($input['course_id'] ?? 0);
                if ($course_id <= 0) {
                    return new WP_Error('missing_course_id', 'course_id is required.');
                }
                if (!function_exists('learndash_get_course_quiz_list')) {
                    // Fallback to WP_Query.
                    $quiz_query = new WP_Query([
                        'post_type' => 'sfwd-quiz',
                        'posts_per_page' => -1,
                        'meta_query' => [
                            [
                                'key' => 'course_id',
                                'value' => $course_id,
                            ],
                        ],
                    ]);
                    $quizzes = [];
                    foreach ($quiz_query->posts as $quiz) {
                        $quizzes[] = [
                            'id' => $quiz->ID,
                            'title' => $quiz->post_title,
                            'status' => $quiz->post_status,
                        ];
                    }
                    return ['quizzes' => $quizzes, 'total' => count($quizzes)];
                }
                $quizzes_raw = learndash_get_course_quiz_list($course_id);
                $quizzes = [];
                if (is_array($quizzes_raw)) {
                    foreach ($quizzes_raw as $quiz) {
                        $quizzes[] = [
                            'id' => $quiz['post']->ID ?? $quiz['id'] ?? 0,
                            'title' => $quiz['post']->post_title ?? '',
                            'status' => $quiz['post']->post_status ?? '',
                        ];
                    }
                }
                return ['quizzes' => $quizzes, 'total' => count($quizzes)];

            case 'enroll_user':
                $course_id = (int) ($input['course_id'] ?? 0);
                $user_id = (int) ($input['user_id'] ?? 0);
                if ($course_id <= 0 || $user_id <= 0) {
                    return new WP_Error('missing_params', 'course_id and user_id are required.');
                }
                if (!function_exists('ld_update_course_access')) {
                    return new WP_Error('ld_function_missing', 'ld_update_course_access not available.');
                }
                $result = ld_update_course_access($user_id, $course_id);
                return ['enrolled' => true, 'user_id' => $user_id, 'course_id' => $course_id];

            case 'get_user_progress':
                $course_id = (int) ($input['course_id'] ?? 0);
                $user_id = (int) ($input['user_id'] ?? 0);
                if ($course_id <= 0 || $user_id <= 0) {
                    return new WP_Error('missing_params', 'course_id and user_id are required.');
                }
                $progress = [];
                if (function_exists('learndash_user_get_course_progress')) {
                    $progress = learndash_user_get_course_progress($user_id, $course_id);
                }
                $completed = 0;
                $total_steps = 0;
                if (function_exists('learndash_course_progress')) {
                    $course_progress = learndash_course_progress([
                        'user_id' => $user_id,
                        'course_id' => $course_id,
                        'array' => true,
                    ]);
                    if (is_array($course_progress)) {
                        $completed = $course_progress['completed'] ?? 0;
                        $total_steps = $course_progress['total'] ?? 0;
                    }
                }
                return ['progress' => [
                    'user_id' => $user_id,
                    'course_id' => $course_id,
                    'completed_steps' => $completed,
                    'total_steps' => $total_steps,
                    'percentage' => $total_steps > 0 ? round(($completed / $total_steps) * 100, 1) : 0,
                    'details' => $progress,
                ]];

            case 'list_enrolled_users':
                $course_id = (int) ($input['course_id'] ?? 0);
                if ($course_id <= 0) {
                    return new WP_Error('missing_course_id', 'course_id is required.');
                }
                if (!function_exists('learndash_get_users_for_course')) {
                    // Fallback via meta query.
                    $user_query = new WP_User_Query([
                        'meta_key' => 'course_' . $course_id . '_access_from',
                        'number' => $per_page,
                        'offset' => ($page - 1) * $per_page,
                    ]);
                    $users = [];
                    foreach ($user_query->get_results() as $user) {
                        $users[] = [
                            'id' => $user->ID,
                            'display_name' => $user->display_name,
                            'email' => $user->user_email,
                        ];
                    }
                    return ['users' => $users, 'total' => $user_query->get_total()];
                }
                $user_query = learndash_get_users_for_course($course_id, [
                    'number' => $per_page,
                    'paged' => $page,
                ], false);
                $users = [];
                if ($user_query instanceof WP_User_Query) {
                    foreach ($user_query->get_results() as $user) {
                        $users[] = [
                            'id' => $user->ID,
                            'display_name' => $user->display_name,
                            'email' => $user->user_email,
                        ];
                    }
                    return ['users' => $users, 'total' => $user_query->get_total()];
                }
                return ['users' => [], 'total' => 0];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('learndash_error', $e->getMessage());
    }
}

// --- Ability: nibwp/lifterlms-manage ---

wp_register_ability('nibwp/lifterlms-manage', [
    'label' => __('LifterLMS – Management', domain: 'nibwp'),
    'description' => __(
        'Manage LifterLMS courses, memberships, and student enrollments.',
        domain: 'nibwp',
    ),
    'category' => 'lms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_courses', 'get_course', 'list_memberships', 'enroll_student', 'get_student_progress'],
                'description' => 'The action to perform.',
            ],
            'course_id' => ['type' => 'integer', 'description' => 'Course post ID.'],
            'membership_id' => ['type' => 'integer', 'description' => 'Membership post ID.'],
            'user_id' => ['type' => 'integer', 'description' => 'Student user ID.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'courses' => ['type' => 'array'],
            'course' => ['type' => 'object'],
            'memberships' => ['type' => 'array'],
            'enrolled' => ['type' => 'boolean'],
            'progress' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_lifterlms_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage LifterLMS courses, memberships, and enrollments.',
                '',
                'ACTIONS:',
                '- list_courses: List all courses. Supports per_page/page.',
                '- get_course: Get course details. Requires course_id.',
                '- list_memberships: List all memberships. Supports per_page/page.',
                '- enroll_student: Enroll a student. Requires course_id (or membership_id) and user_id.',
                '- get_student_progress: Get student progress. Requires course_id and user_id.',
                '',
                'REQUIRES: LifterLMS plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_lifterlms_manage(array $input): array|WP_Error
{
    if (!defined('LLMS_PLUGIN_FILE')) {
        return new WP_Error('lifterlms_not_active', 'LifterLMS is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_courses':
                $query = new WP_Query([
                    'post_type' => 'course',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                ]);
                $courses = [];
                foreach ($query->posts as $post) {
                    $course = new LLMS_Course($post);
                    $courses[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'status' => $post->post_status,
                        'enrollment_status' => $course->get('enrollment_status') ?? '',
                        'students' => method_exists($course, 'get_student_count') ? $course->get_student_count() : 0,
                        'date' => $post->post_date,
                    ];
                }
                return ['courses' => $courses, 'total' => $query->found_posts];

            case 'get_course':
                $course_id = (int) ($input['course_id'] ?? 0);
                if ($course_id <= 0) {
                    return new WP_Error('missing_course_id', 'course_id is required.');
                }
                $post = get_post($course_id);
                if (!$post || $post->post_type !== 'course') {
                    return new WP_Error('course_not_found', sprintf('Course %d not found.', $course_id));
                }
                $course = new LLMS_Course($post);
                $lessons = $course->get_lessons('posts');
                return ['course' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'enrollment_status' => $course->get('enrollment_status') ?? '',
                    'students' => method_exists($course, 'get_student_count') ? $course->get_student_count() : 0,
                    'lessons_count' => is_array($lessons) ? count($lessons) : 0,
                    'status' => $post->post_status,
                    'date' => $post->post_date,
                ]];

            case 'list_memberships':
                $query = new WP_Query([
                    'post_type' => 'llms_membership',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                ]);
                $memberships = [];
                foreach ($query->posts as $post) {
                    $memberships[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'status' => $post->post_status,
                        'date' => $post->post_date,
                    ];
                }
                return ['memberships' => $memberships, 'total' => $query->found_posts];

            case 'enroll_student':
                $user_id = (int) ($input['user_id'] ?? 0);
                $course_id = (int) ($input['course_id'] ?? 0);
                $membership_id = (int) ($input['membership_id'] ?? 0);
                $product_id = $course_id > 0 ? $course_id : $membership_id;
                if ($user_id <= 0 || $product_id <= 0) {
                    return new WP_Error('missing_params', 'user_id and course_id (or membership_id) are required.');
                }
                if (!function_exists('llms_enroll_student')) {
                    return new WP_Error('llms_function_missing', 'llms_enroll_student not available.');
                }
                $result = llms_enroll_student($user_id, $product_id, 'admin_' . get_current_user_id());
                return ['enrolled' => (bool) $result, 'user_id' => $user_id, 'product_id' => $product_id];

            case 'get_student_progress':
                $user_id = (int) ($input['user_id'] ?? 0);
                $course_id = (int) ($input['course_id'] ?? 0);
                if ($user_id <= 0 || $course_id <= 0) {
                    return new WP_Error('missing_params', 'user_id and course_id are required.');
                }
                if (!class_exists('LLMS_Student')) {
                    return new WP_Error('llms_class_missing', 'LLMS_Student class not available.');
                }
                $student = new LLMS_Student($user_id);
                $progress = $student->get_progress($course_id, 'course');
                $grade = $student->get_grade($course_id);
                $enrollment_date = $student->get_enrollment_date($course_id);
                $status = $student->get_enrollment_status($course_id);
                return ['progress' => [
                    'user_id' => $user_id,
                    'course_id' => $course_id,
                    'progress_percentage' => $progress,
                    'grade' => $grade,
                    'enrollment_date' => $enrollment_date,
                    'enrollment_status' => $status,
                ]];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('lifterlms_error', $e->getMessage());
    }
}

// =====================================================================
// MEMBERSHIP (category: 'membership')
// =====================================================================

wp_register_ability('nibwp/memberpress-manage', [
    'label' => __('MemberPress – Membership Management', domain: 'nibwp'),
    'description' => __(
        'Manage MemberPress memberships, members, transactions, and subscriptions.',
        domain: 'nibwp',
    ),
    'category' => 'membership',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_memberships', 'get_membership', 'list_members', 'list_transactions', 'list_subscriptions'],
                'description' => 'The action to perform.',
            ],
            'membership_id' => ['type' => 'integer', 'description' => 'Membership product ID.'],
            'user_id' => ['type' => 'integer', 'description' => 'User/member ID.'],
            'status' => ['type' => 'string', 'description' => 'Filter by status.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'memberships' => ['type' => 'array'],
            'membership' => ['type' => 'object'],
            'members' => ['type' => 'array'],
            'transactions' => ['type' => 'array'],
            'subscriptions' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_memberpress_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage MemberPress memberships, members, and transactions.',
                '',
                'ACTIONS:',
                '- list_memberships: List all membership products.',
                '- get_membership: Get membership details. Requires membership_id.',
                '- list_members: List members. Optionally filter by membership_id. Supports per_page/page.',
                '- list_transactions: List transactions. Optionally filter by membership_id, user_id, or status.',
                '- list_subscriptions: List subscriptions. Optionally filter by membership_id or user_id.',
                '',
                'REQUIRES: MemberPress plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_memberpress_manage(array $input): array|WP_Error
{
    if (!defined('MEPR_VERSION')) {
        return new WP_Error('memberpress_not_active', 'MemberPress is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_memberships':
                $query = new WP_Query([
                    'post_type' => 'memberpressproduct',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                ]);
                $memberships = [];
                foreach ($query->posts as $post) {
                    $product = new MeprProduct($post->ID);
                    $memberships[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'price' => $product->price,
                        'period' => $product->period,
                        'period_type' => $product->period_type,
                        'trial' => $product->trial,
                        'trial_days' => $product->trial_days,
                        'status' => $post->post_status,
                    ];
                }
                return ['memberships' => $memberships, 'total' => $query->found_posts];

            case 'get_membership':
                $membership_id = (int) ($input['membership_id'] ?? 0);
                if ($membership_id <= 0) {
                    return new WP_Error('missing_membership_id', 'membership_id is required.');
                }
                $post = get_post($membership_id);
                if (!$post || $post->post_type !== 'memberpressproduct') {
                    return new WP_Error('membership_not_found', sprintf('Membership %d not found.', $membership_id));
                }
                $product = new MeprProduct($membership_id);
                return ['membership' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'price' => $product->price,
                    'period' => $product->period,
                    'period_type' => $product->period_type,
                    'trial' => $product->trial,
                    'trial_days' => $product->trial_days,
                    'trial_amount' => $product->trial_amount,
                    'limit_cycles' => $product->limit_cycles,
                    'limit_cycles_num' => $product->limit_cycles_num,
                    'status' => $post->post_status,
                ]];

            case 'list_members':
                $mepr_db = new MeprDb();
                $args = [
                    'per_page' => $per_page,
                    'page' => $page,
                ];
                if (!empty($input['membership_id'])) {
                    $args['product_id'] = (int) $input['membership_id'];
                }
                // Use MeprUser model to list active members.
                $users_query = new WP_User_Query([
                    'number' => $per_page,
                    'offset' => ($page - 1) * $per_page,
                    'meta_query' => !empty($input['membership_id']) ? [
                        [
                            'key' => 'mepr_active_product_' . (int) $input['membership_id'],
                            'compare' => 'EXISTS',
                        ],
                    ] : [],
                ]);
                $members = [];
                foreach ($users_query->get_results() as $user) {
                    $mepr_user = new MeprUser($user->ID);
                    $active_subs = $mepr_user->active_product_subscriptions('ids');
                    $members[] = [
                        'id' => $user->ID,
                        'display_name' => $user->display_name,
                        'email' => $user->user_email,
                        'registered' => $user->user_registered,
                        'active_memberships' => $active_subs,
                    ];
                }
                return ['members' => $members, 'total' => $users_query->get_total()];

            case 'list_transactions':
                $args = [
                    'limit' => $per_page,
                    'offset' => ($page - 1) * $per_page,
                    'order_by' => 'created_at',
                    'order' => 'DESC',
                ];
                if (!empty($input['membership_id'])) {
                    $args['product_id'] = (int) $input['membership_id'];
                }
                if (!empty($input['user_id'])) {
                    $args['user_id'] = (int) $input['user_id'];
                }
                if (!empty($input['status'])) {
                    $args['status'] = (string) $input['status'];
                }
                $txn_model = MeprTransaction::list_table($args, '', '', true);
                $total = MeprTransaction::list_table($args, '', '', false, true);
                $transactions = [];
                if (is_array($txn_model)) {
                    foreach ($txn_model as $txn) {
                        $transactions[] = [
                            'id' => $txn->id,
                            'user_id' => $txn->user_id,
                            'product_id' => $txn->product_id,
                            'amount' => $txn->amount,
                            'total' => $txn->total,
                            'status' => $txn->status,
                            'gateway' => $txn->gateway,
                            'created_at' => $txn->created_at,
                            'expires_at' => $txn->expires_at,
                        ];
                    }
                }
                return ['transactions' => $transactions, 'total' => (int) $total];

            case 'list_subscriptions':
                $args = [
                    'limit' => $per_page,
                    'offset' => ($page - 1) * $per_page,
                    'order_by' => 'created_at',
                    'order' => 'DESC',
                ];
                if (!empty($input['membership_id'])) {
                    $args['product_id'] = (int) $input['membership_id'];
                }
                if (!empty($input['user_id'])) {
                    $args['user_id'] = (int) $input['user_id'];
                }
                $sub_model = MeprSubscription::list_table($args, '', '', true);
                $total = MeprSubscription::list_table($args, '', '', false, true);
                $subscriptions = [];
                if (is_array($sub_model)) {
                    foreach ($sub_model as $sub) {
                        $subscriptions[] = [
                            'id' => $sub->id,
                            'user_id' => $sub->user_id,
                            'product_id' => $sub->product_id,
                            'price' => $sub->price,
                            'period' => $sub->period,
                            'period_type' => $sub->period_type,
                            'status' => $sub->status,
                            'gateway' => $sub->gateway,
                            'created_at' => $sub->created_at,
                            'active' => $sub->is_active(),
                        ];
                    }
                }
                return ['subscriptions' => $subscriptions, 'total' => (int) $total];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('memberpress_error', $e->getMessage());
    }
}

// =====================================================================
// DONATIONS (category: 'donations')
// =====================================================================

wp_register_ability('nibwp/givewp-manage', [
    'label' => __('GiveWP – Donation Management', domain: 'nibwp'),
    'description' => __(
        'Manage GiveWP donation forms, donations, and statistics.',
        domain: 'nibwp',
    ),
    'category' => 'donations',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_forms', 'get_form', 'list_donations', 'get_donation', 'get_stats'],
                'description' => 'The action to perform.',
            ],
            'form_id' => ['type' => 'integer', 'description' => 'Donation form ID.'],
            'donation_id' => ['type' => 'integer', 'description' => 'Donation/payment ID.'],
            'status' => ['type' => 'string', 'description' => 'Filter donations by status (publish/complete, pending, refunded, etc.).'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'forms' => ['type' => 'array'],
            'form' => ['type' => 'object'],
            'donations' => ['type' => 'array'],
            'donation' => ['type' => 'object'],
            'stats' => ['type' => 'object'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_givewp_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage GiveWP donation forms and payments.',
                '',
                'ACTIONS:',
                '- list_forms: List all donation forms. Supports per_page/page.',
                '- get_form: Get donation form details. Requires form_id.',
                '- list_donations: List donations. Optionally filter by form_id, status. Supports per_page/page.',
                '- get_donation: Get donation details. Requires donation_id.',
                '- get_stats: Get overall donation statistics (total raised, donor count, etc.).',
                '',
                'REQUIRES: GiveWP plugin must be active.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_givewp_manage(array $input): array|WP_Error
{
    if (!defined('GIVE_VERSION')) {
        return new WP_Error('givewp_not_active', 'GiveWP is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            case 'list_forms':
                $query = new WP_Query([
                    'post_type' => 'give_forms',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                ]);
                $forms = [];
                foreach ($query->posts as $post) {
                    $goal = get_post_meta($post->ID, '_give_set_goal', true);
                    $goal_amount = get_post_meta($post->ID, '_give_set_goal_amount', true);
                    $income = function_exists('give_get_form_earnings_stats') ? give_get_form_earnings_stats($post->ID) : 0;
                    $forms[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'goal_enabled' => $goal === 'enabled',
                        'goal_amount' => $goal_amount ?: null,
                        'total_earned' => $income,
                        'status' => $post->post_status,
                        'date' => $post->post_date,
                    ];
                }
                return ['forms' => $forms, 'total' => $query->found_posts];

            case 'get_form':
                $form_id = (int) ($input['form_id'] ?? 0);
                if ($form_id <= 0) {
                    return new WP_Error('missing_form_id', 'form_id is required.');
                }
                $post = get_post($form_id);
                if (!$post || $post->post_type !== 'give_forms') {
                    return new WP_Error('form_not_found', sprintf('Form %d not found.', $form_id));
                }
                $levels = get_post_meta($form_id, '_give_donation_levels', true);
                return ['form' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'goal_enabled' => get_post_meta($form_id, '_give_set_goal', true) === 'enabled',
                    'goal_amount' => get_post_meta($form_id, '_give_set_goal_amount', true) ?: null,
                    'price_option' => get_post_meta($form_id, '_give_price_option', true),
                    'set_price' => get_post_meta($form_id, '_give_set_price', true),
                    'donation_levels' => is_array($levels) ? $levels : [],
                    'total_earned' => function_exists('give_get_form_earnings_stats') ? give_get_form_earnings_stats($form_id) : 0,
                    'donation_count' => function_exists('give_get_form_sales_stats') ? give_get_form_sales_stats($form_id) : 0,
                    'status' => $post->post_status,
                ]];

            case 'list_donations':
                if (!class_exists('Give_Payments_Query')) {
                    return new WP_Error('give_class_missing', 'Give_Payments_Query class not available.');
                }
                $args = [
                    'number' => $per_page,
                    'offset' => ($page - 1) * $per_page,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ];
                if (!empty($input['form_id'])) {
                    $args['give_forms'] = [(int) $input['form_id']];
                }
                if (!empty($input['status'])) {
                    $args['status'] = (string) $input['status'];
                }
                $payments_query = new Give_Payments_Query($args);
                $payments = $payments_query->get_payments();
                $donations = [];
                foreach ($payments as $payment) {
                    $donations[] = [
                        'id' => $payment->ID,
                        'form_id' => (int) get_post_meta($payment->ID, '_give_payment_form_id', true),
                        'form_title' => get_post_meta($payment->ID, '_give_payment_form_title', true),
                        'donor_email' => get_post_meta($payment->ID, '_give_payment_donor_email', true),
                        'amount' => get_post_meta($payment->ID, '_give_payment_total', true),
                        'gateway' => get_post_meta($payment->ID, '_give_payment_gateway', true),
                        'status' => $payment->post_status,
                        'date' => $payment->post_date,
                    ];
                }
                return ['donations' => $donations, 'total' => count($donations)];

            case 'get_donation':
                $donation_id = (int) ($input['donation_id'] ?? 0);
                if ($donation_id <= 0) {
                    return new WP_Error('missing_donation_id', 'donation_id is required.');
                }
                $payment = get_post($donation_id);
                if (!$payment || $payment->post_type !== 'give_payment') {
                    return new WP_Error('donation_not_found', sprintf('Donation %d not found.', $donation_id));
                }
                return ['donation' => [
                    'id' => $payment->ID,
                    'form_id' => (int) get_post_meta($donation_id, '_give_payment_form_id', true),
                    'form_title' => get_post_meta($donation_id, '_give_payment_form_title', true),
                    'donor_email' => get_post_meta($donation_id, '_give_payment_donor_email', true),
                    'first_name' => get_post_meta($donation_id, '_give_donor_billing_first_name', true),
                    'last_name' => get_post_meta($donation_id, '_give_donor_billing_last_name', true),
                    'amount' => get_post_meta($donation_id, '_give_payment_total', true),
                    'currency' => get_post_meta($donation_id, '_give_payment_currency', true),
                    'gateway' => get_post_meta($donation_id, '_give_payment_gateway', true),
                    'mode' => get_post_meta($donation_id, '_give_payment_mode', true),
                    'status' => $payment->post_status,
                    'date' => $payment->post_date,
                ]];

            case 'get_stats':
                $stats = [
                    'total_earnings' => function_exists('give_get_total_earnings') ? give_get_total_earnings() : 0,
                    'total_donations' => 0,
                    'total_donors' => 0,
                ];
                // Count total donations.
                $count = wp_count_posts('give_payment');
                if ($count) {
                    $stats['total_donations'] = (int) ($count->publish ?? 0) + (int) ($count->give_subscription ?? 0);
                }
                // Count donors.
                if (function_exists('give') && isset(give()->donors)) {
                    $stats['total_donors'] = (int) give()->donors->count();
                } else {
                    global $wpdb;
                    $table = $wpdb->prefix . 'give_donors';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                        $stats['total_donors'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
                    }
                }
                return ['stats' => $stats];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('givewp_error', $e->getMessage());
    }
}

// =====================================================================
// UTILITIES (category: 'utilities')
// =====================================================================


// =====================================================================
// MULTILINGUAL (category: 'multilingual')
// =====================================================================

wp_register_ability('nibwp/translatepress-manage', [
    'label' => __('TranslatePress – Translation Manager', domain: 'nibwp'),
    'description' => __(
        'Manage TranslatePress translations. List languages, get translations, and update translation strings.',
        domain: 'nibwp',
    ),
    'category' => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_languages', 'get_translations', 'update_translation'],
                'description' => 'The action to perform.',
            ],
            'translation_data' => [
                'type' => 'object',
                'properties' => [
                    'original' => ['type' => 'string', 'description' => 'Original string to translate.'],
                    'translated' => ['type' => 'string', 'description' => 'Translated string.'],
                    'language_code' => ['type' => 'string', 'description' => 'Target language code.'],
                ],
                'description' => 'Translation data for update_translation.',
            ],
            'search' => ['type' => 'string', 'description' => 'Search string for get_translations.'],
            'language_code' => ['type' => 'string', 'description' => 'Language code to filter translations.'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page' => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'languages' => ['type' => 'array'],
            'translations' => ['type' => 'array'],
            'updated' => ['type' => 'boolean'],
            'total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_translatepress_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage TranslatePress translations.',
                '',
                'ACTIONS:',
                '- list_languages: List configured languages (default + translation languages).',
                '- get_translations: Get translation strings. Supports search, language_code filter, and per_page/page.',
                '- update_translation: Update a translation. Requires translation_data with original, translated, language_code.',
                '',
                'REQUIRES: TranslatePress plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_translatepress_manage(array $input): array|WP_Error
{
    if (!defined('TRP_PLUGIN_VERSION')) {
        return new WP_Error('translatepress_not_active', 'TranslatePress is not active.');
    }

    $action = $input['action'] ?? '';
    $per_page = min(max((int) ($input['per_page'] ?? 50), 1), 200);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        $settings = get_option('trp_settings', []);
        $default_language = $settings['default-language'] ?? 'en_US';
        $translation_languages = $settings['translation-languages'] ?? [];

        switch ($action) {
            case 'list_languages':
                $languages = [];
                $trp = TRP_Translate_Press::get_trp_instance();
                $lang_helper = $trp->get_component('languages');
                $published = $lang_helper->get_language_names($translation_languages);
                foreach ($translation_languages as $code) {
                    $languages[] = [
                        'code' => $code,
                        'name' => $published[$code] ?? $code,
                        'is_default' => $code === $default_language,
                    ];
                }
                return ['languages' => $languages];

            case 'get_translations':
                global $wpdb;
                $language_code = $input['language_code'] ?? '';
                if (empty($language_code)) {
                    // Use first non-default translation language.
                    foreach ($translation_languages as $lang) {
                        if ($lang !== $default_language) {
                            $language_code = $lang;
                            break;
                        }
                    }
                }
                if (empty($language_code)) {
                    return new WP_Error('no_language', 'No translation language found. Provide language_code.');
                }
                $table = $wpdb->prefix . 'trp_dictionary_' . strtolower(sanitize_key($default_language)) . '_' . strtolower(sanitize_key($language_code));
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                    return new WP_Error('table_not_found', sprintf('Translation table for %s not found.', $language_code));
                }
                $where = '';
                $prepare_args = [];
                if (!empty($input['search'])) {
                    $search = '%' . $wpdb->esc_like($input['search']) . '%';
                    $where = 'WHERE original LIKE %s OR translated LIKE %s';
                    $prepare_args[] = $search;
                    $prepare_args[] = $search;
                }
                $total_query = "SELECT COUNT(*) FROM $table $where";
                $total = (int) ($prepare_args ? $wpdb->get_var($wpdb->prepare($total_query, ...$prepare_args)) : $wpdb->get_var($total_query));
                $offset = ($page - 1) * $per_page;
                $query = "SELECT id, original, translated, status FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d";
                $all_args = array_merge($prepare_args, [$per_page, $offset]);
                $rows = $wpdb->get_results($wpdb->prepare($query, ...$all_args));
                $translations = [];
                foreach ($rows as $row) {
                    $translations[] = [
                        'id' => (int) $row->id,
                        'original' => $row->original,
                        'translated' => $row->translated,
                        'status' => (int) $row->status,
                    ];
                }
                return ['translations' => $translations, 'total' => $total, 'language_code' => $language_code];

            case 'update_translation':
                $data = $input['translation_data'] ?? [];
                if (empty($data['original']) || !isset($data['translated']) || empty($data['language_code'])) {
                    return new WP_Error('missing_data', 'translation_data requires original, translated, and language_code.');
                }
                global $wpdb;
                $language_code = $data['language_code'];
                $table = $wpdb->prefix . 'trp_dictionary_' . strtolower(sanitize_key($default_language)) . '_' . strtolower(sanitize_key($language_code));
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                    return new WP_Error('table_not_found', sprintf('Translation table for %s not found.', $language_code));
                }
                // Try to find existing row.
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM $table WHERE original = %s LIMIT 1",
                    $data['original']
                ));
                if ($existing) {
                    $wpdb->update(
                        $table,
                        ['translated' => $data['translated'], 'status' => 2],
                        ['id' => $existing->id]
                    );
                } else {
                    $wpdb->insert($table, [
                        'original' => $data['original'],
                        'translated' => $data['translated'],
                        'status' => 2,
                    ]);
                }
                return ['updated' => true, 'language_code' => $language_code];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('translatepress_error', $e->getMessage());
    }
}

// --- Ability: nibwp/wpml-manage ---

wp_register_ability('nibwp/wpml-manage', [
    'label' => __('WPML – Multilingual Manager', domain: 'nibwp'),
    'description' => __(
        'Manage WPML multilingual content. List languages, get translation status, set post language, and duplicate posts for translation.',
        domain: 'nibwp',
    ),
    'category' => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['list_languages', 'get_translations', 'set_language', 'duplicate_post'],
                'description' => 'The action to perform.',
            ],
            'post_id' => ['type' => 'integer', 'description' => 'Post ID.'],
            'language_code' => ['type' => 'string', 'description' => 'Language code (e.g. en, fr, de).'],
            'post_type' => ['type' => 'string', 'description' => 'Post type for get_translations.', 'default' => 'post'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'languages' => ['type' => 'array'],
            'translations' => ['type' => 'object'],
            'updated' => ['type' => 'boolean'],
            'duplicated' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => 'nibwp_wpml_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage WPML multilingual content.',
                '',
                'ACTIONS:',
                '- list_languages: List all active languages configured in WPML.',
                '- get_translations: Get translation status for a post. Requires post_id.',
                '- set_language: Set the language of a post. Requires post_id and language_code.',
                '- duplicate_post: Duplicate a post for translation into another language. Requires post_id and language_code.',
                '',
                'LANGUAGE CODES: Use WPML language codes (e.g. en, fr, de, es, it, pt-br).',
                '',
                'REQUIRES: WPML plugin must be active.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpml_manage(array $input): array|WP_Error
{
    if (!defined('ICL_SITEPRESS_VERSION')) {
        return new WP_Error('wpml_not_active', 'WPML is not active.');
    }

    global $sitepress;
    if (!$sitepress || !is_object($sitepress)) {
        return new WP_Error('wpml_sitepress_missing', 'WPML SitePress global not available.');
    }

    $action = $input['action'] ?? '';

    try {
        switch ($action) {
            case 'list_languages':
                $active_languages = $sitepress->get_active_languages();
                $default = $sitepress->get_default_language();
                $languages = [];
                foreach ($active_languages as $code => $lang) {
                    $languages[] = [
                        'code' => $code,
                        'english_name' => $lang['english_name'] ?? $code,
                        'native_name' => $lang['native_name'] ?? $code,
                        'is_default' => $code === $default,
                        'active' => (bool) ($lang['active'] ?? true),
                    ];
                }
                return ['languages' => $languages];

            case 'get_translations':
                $post_id = (int) ($input['post_id'] ?? 0);
                if ($post_id <= 0) {
                    return new WP_Error('missing_post_id', 'post_id is required.');
                }
                $post = get_post($post_id);
                if (!$post) {
                    return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
                }
                $post_type = $post->post_type;
                $element_type = 'post_' . $post_type;
                $trid = $sitepress->get_element_trid($post_id, $element_type);
                if (!$trid) {
                    return new WP_Error('no_trid', 'Post is not registered with WPML.');
                }
                $translations_raw = $sitepress->get_element_translations($trid, $element_type);
                $translations = [];
                foreach ($translations_raw as $lang_code => $translation) {
                    $translations[$lang_code] = [
                        'post_id' => (int) $translation->element_id,
                        'language_code' => $lang_code,
                        'title' => get_the_title($translation->element_id),
                        'status' => get_post_status($translation->element_id),
                        'source_language' => $translation->source_language_code ?? null,
                    ];
                }
                $lang_details = apply_filters('wpml_post_language_details', null, $post_id);
                return [
                    'translations' => $translations,
                    'current_language' => is_array($lang_details) ? ($lang_details['language_code'] ?? '') : '',
                    'trid' => $trid,
                ];

            case 'set_language':
                $post_id = (int) ($input['post_id'] ?? 0);
                $language_code = $input['language_code'] ?? '';
                if ($post_id <= 0 || empty($language_code)) {
                    return new WP_Error('missing_params', 'post_id and language_code are required.');
                }
                $post = get_post($post_id);
                if (!$post) {
                    return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
                }
                $element_type = 'post_' . $post->post_type;
                $sitepress->set_element_language_details($post_id, $element_type, null, $language_code);
                return ['updated' => true, 'post_id' => $post_id, 'language_code' => $language_code];

            case 'duplicate_post':
                $post_id = (int) ($input['post_id'] ?? 0);
                $language_code = $input['language_code'] ?? '';
                if ($post_id <= 0 || empty($language_code)) {
                    return new WP_Error('missing_params', 'post_id and language_code are required.');
                }
                $post = get_post($post_id);
                if (!$post) {
                    return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
                }
                // Check if translation already exists.
                $translated_id = apply_filters('wpml_object_id', $post_id, $post->post_type, false, $language_code);
                if ($translated_id && $translated_id !== $post_id) {
                    return new WP_Error('already_translated', sprintf('A translation for language %s already exists (post ID %d).', $language_code, $translated_id));
                }
                // Create a duplicate.
                $new_post_id = wp_insert_post([
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_excerpt' => $post->post_excerpt,
                    'post_status' => 'draft',
                    'post_type' => $post->post_type,
                    'post_author' => $post->post_author,
                ]);
                if (is_wp_error($new_post_id)) {
                    return $new_post_id;
                }
                // Copy post meta.
                $meta = get_post_meta($post_id);
                foreach ($meta as $key => $values) {
                    if (str_starts_with($key, '_wpml_') || str_starts_with($key, '_icl_')) {
                        continue;
                    }
                    foreach ($values as $value) {
                        add_post_meta($new_post_id, $key, maybe_unserialize($value));
                    }
                }
                // Link translation.
                $element_type = 'post_' . $post->post_type;
                $trid = $sitepress->get_element_trid($post_id, $element_type);
                $sitepress->set_element_language_details($new_post_id, $element_type, $trid, $language_code);
                return ['duplicated' => [
                    'original_id' => $post_id,
                    'new_id' => $new_post_id,
                    'language_code' => $language_code,
                    'status' => 'draft',
                ]];

            default:
                return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
        }
    } catch (\Throwable $e) {
        return new WP_Error('wpml_error', $e->getMessage());
    }
}
