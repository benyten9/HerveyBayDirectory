<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// =====================================================================
// FORMS (category: 'forms')
// =====================================================================

wp_register_ability('nibwp/forms-manage', [
    'label' => __('Forms – Universal Manager', 'nibwp'),
    'description' => 'Universal forms manager. Auto-detects Gravity Forms, WPForms, or Fluent Forms and provides a unified API for listing forms, getting entries, and creating entries.',
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
                'enum' => ['gravityforms', 'wpforms', 'fluentform', 'cf7', 'ninjaforms', 'formidable', 'forminator', 'happyforms', 'bitform', 'jetform'],
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
                '- Bit Form (BITFORMS_VERSION) — list/get forms, list/delete entries',
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
        'bitform'      => static fn() => defined('BITFORMS_VERSION') && class_exists('BitCode\BitForm\Core\Database\FormModel'),
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
            'bitform'      => ['Bit Form',         static fn () => defined('BITFORMS_VERSION') && class_exists('BitCode\BitForm\Core\Database\FormModel')],
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
        return new WP_Error('no_form_plugin', 'No supported form plugin detected. Install one of: Fluent Forms (recommended, free), Gravity Forms, WPForms, Contact Form 7, Ninja Forms, Formidable Forms, Forminator, Happy Forms, Bit Form, JetFormBuilder.');
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
            case 'bitform':
                return nibwp_forms_bitform($action, $form_id, $entry_id, $per_page, $page);
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

/**
 * Bit Form branch of the universal manager.
 *
 * Bit Form has no public API class, so this goes through its own data layer:
 * FormModel for the `{prefix}bitforms_form` rows and FormEntryModel for
 * `{prefix}bitforms_form_entries` — whose bulkDelete() is a joined DELETE that
 * takes the entry's field values in `{prefix}bitforms_form_entrymeta` with it.
 *
 * Creating a form is deliberately not offered. A Bit Form form is one JSON
 * document where fields, layout and a generated class map have to agree; a
 * hand-built one opens in the builder as an empty canvas. The dedicated
 * nibwp/bitform-* abilities cover the rest of the surface, including fields,
 * notifications and the audit.
 */
function nibwp_forms_bitform(string $action, mixed $form_id, mixed $entry_id, int $per_page, int $page): array|WP_Error
{
    $form_model_class  = 'BitCode\BitForm\Core\Database\FormModel';
    $entry_model_class = 'BitCode\BitForm\Core\Database\FormEntryModel';

    if (!class_exists($form_model_class)) {
        return new WP_Error('bitform_unavailable', 'Bit Form is active but its data layer did not load.');
    }

    global $wpdb;
    $entries_table = $wpdb->prefix . 'bitforms_form_entries';
    $offset = ($page - 1) * $per_page;

    // Model::get() answers with [] when the table is missing and a
    // WP_Error('result_empty') when nothing matched, so both mean "no rows".
    $rows = static function ($result): array {
        return is_array($result)
            ? array_values(array_filter($result, static fn($row): bool => is_object($row)))
            : [];
    };

    switch ($action) {
        case 'list_forms':
            $forms = $rows((new $form_model_class())->get(
                ['id', 'form_name', 'status', 'created_at'],
                [],
                $per_page,
                $offset,
                'id',
                'DESC'
            ));
            $result = [];
            foreach ($forms as $form) {
                $result[] = [
                    'id'     => (int) ($form->id ?? 0),
                    'name'   => (string) ($form->form_name ?? ''),
                    // 0 unpublished, 1 published, 2 trashed.
                    'status' => (int) ($form->status ?? 0) === 1 ? 'publish' : 'draft',
                ];
            }
            return ['plugin' => 'Bit Form', 'forms' => $result];

        case 'get_form':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            $forms = $rows((new $form_model_class())->get(
                ['id', 'form_name', 'form_content', 'status', 'views', 'created_at'],
                ['id' => (int) $form_id]
            ));
            if ($forms === []) {
                return new WP_Error('bitform_not_found', 'No Bit Form form with that ID.');
            }
            $form = $forms[0];
            $content = json_decode((string) ($form->form_content ?? ''));
            return ['plugin' => 'Bit Form', 'form' => [
                'id'        => (int) ($form->id ?? 0),
                'name'      => (string) ($form->form_name ?? ''),
                'status'    => (int) ($form->status ?? 0) === 1 ? 'publish' : 'draft',
                'views'     => (int) ($form->views ?? 0),
                'shortcode' => sprintf('[bitform id="%d"]', (int) ($form->id ?? 0)),
                'fields'    => is_object($content) ? ($content->fields ?? null) : null,
            ]];

        case 'get_entries':
            if (!$form_id) {
                return new WP_Error('missing_form_id', 'form_id is required.');
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb->prefix.
            $entry_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `created_at` FROM `{$entries_table}` WHERE `form_id` = %d ORDER BY `id` DESC LIMIT %d OFFSET %d",
                (int) $form_id,
                $per_page,
                $offset
            ));
            $ids = array_map(static fn($row): int => (int) ($row->id ?? 0), $entry_rows);
            $values = function_exists('nibwp_bitform_entry_values') ? nibwp_bitform_entry_values($ids) : [];
            $entries = [];
            foreach ($entry_rows as $row) {
                $id = (int) ($row->id ?? 0);
                $entries[] = [
                    'id'           => $id,
                    'date_created' => (string) ($row->created_at ?? ''),
                    'meta_data'    => $values[$id] ?? [],
                ];
            }
            return ['plugin' => 'Bit Form', 'entries' => $entries];

        case 'delete_entry':
            if (!$form_id || !$entry_id) {
                return new WP_Error('missing_ids', 'form_id and entry_id are required.');
            }
            if (!class_exists($entry_model_class)) {
                return new WP_Error('bitform_unavailable', 'The Bit Form entry model did not load.');
            }
            // Table-qualified keys are required: the joined DELETE sees `id` on
            // both tables and a bare column name is ambiguous.
            (new $entry_model_class())->bulkDelete([
                "`{$entries_table}`.`id`"      => (int) $entry_id,
                "`{$entries_table}`.`form_id`" => (int) $form_id,
            ]);
            return ['plugin' => 'Bit Form', 'deleted' => true];

        case 'create_form':
        case 'create_entry':
            return new WP_Error('bitform_partial_support', sprintf(
                'Bit Form does not expose a safe way to %s from outside its builder. Use the nibwp/bitform-* abilities to read, audit and export instead.',
                $action === 'create_form' ? 'build a form' : 'inject an entry'
            ));

        default:
            return new WP_Error('invalid_action', sprintf('Unknown action: %s', $action));
    }
}

// =====================================================================
// CRM & MARKETING (category: 'crm')
// =====================================================================

wp_register_ability('nibwp/learndash-manage', [
    'label' => __('LearnDash – LMS Management', 'nibwp'),
    'description' => 'Manage LearnDash LMS courses, lessons, quizzes, enrollments, and user progress.',
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

wp_register_ability('nibwp/memberpress-manage', [
    'label' => __('MemberPress – Membership Management', 'nibwp'),
    'description' => 'Manage MemberPress memberships, members, transactions, and subscriptions.',
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

wp_register_ability('nibwp/wpml-manage', [
    'label' => __('WPML – Multilingual Manager', 'nibwp'),
    'description' => 'Manage WPML multilingual content. List languages, get translation status, set post language, and duplicate posts for translation.',
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
                    // Slashed: the content came out of the database unslashed and
                    // wp_insert_post() unslashes again, which would strip the
                    // block-attribute escapes out of the duplicated page.
                    'post_content' => wp_slash($post->post_content),
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
