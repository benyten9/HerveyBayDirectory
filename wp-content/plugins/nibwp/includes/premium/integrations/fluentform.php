<?php

declare(strict_types=1);

/**
 * Fluent Forms integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Fourteen abilities cover Fluent Forms and Fluent Forms Pro: forms and their
 * fields, submissions and their notes, email notifications, confirmations,
 * form settings, integration feeds, payments, analytics, export, an audit, and
 * deletion.
 *
 * WHY A DEDICATED INTEGRATION when the universal `forms` one already lists and
 * reads Fluent Forms: the generic surface reaches the form and its entries, and
 * stops. Everything that decides whether a form is useful lives in the
 * form_meta table beside it — notifications, confirmations, settings, and every
 * integration feed an add-on writes. A form with no notification collects
 * submissions and tells nobody, and no generic form reader can see that.
 *
 * HOW FLUENT FORMS STORES A FORM. The `fluentform_forms` row holds the title
 * and the field JSON. Everything else is rows in `fluentform_form_meta`, keyed
 * by meta_key, with a JSON value:
 *
 *   notifications              email notifications, one row each
 *   confirmations              what the visitor sees after submitting
 *   formSettings               layout, restrictions, scheduling, login
 *   fluentform_webhook_feed    webhooks
 *   <addon>_feeds              every integration add-on writes its own key
 *
 * That last line is why the feeds ability is generic rather than a list of
 * known add-ons: the key belongs to whichever add-on wrote it, and enumerating
 * the meta keys a form actually has is the only way to see them all.
 *
 * Mechanism is IN-PROCESS through Fluent Forms' own classes:
 *   fluentFormApi('forms')       list, find, form properties
 *   fluentFormApi('submissions') entries, transactions, subscriptions
 *   FluentForm\App\Models\{Form, FormMeta, Submission, SubmissionMeta}
 *
 * Detection: FLUENTFORM + fluentFormApi(). Pro features gate on FLUENTFORMPRO,
 * which is exactly how Fluent Forms checks for itself (Helpers\Helper:1292).
 *
 * Verified against Fluent Forms 6.1.20 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Fluent Forms active? */
function nibwp_ff_available(): bool
{
    return defined('FLUENTFORM') && function_exists('fluentFormApi');
}

/** Is Fluent Forms Pro active? Same check Fluent Forms makes for itself. */
function nibwp_ff_pro(): bool
{
    return defined('FLUENTFORMPRO');
}

/** House WP_Error wrapper. */
function nibwp_ff_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_ff_guard(): ?WP_Error
{
    if (!nibwp_ff_available()) {
        return nibwp_ff_err(
            'nibwp_ff_missing',
            __('Fluent Forms is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** The guard a Pro-only ability opens with. */
function nibwp_ff_guard_pro(): ?WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    if (!nibwp_ff_pro()) {
        return nibwp_ff_err(
            'nibwp_ff_no_pro',
            __('This needs Fluent Forms Pro, which is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Run a Fluent Forms call, converting throwables into WP_Error. */
function nibwp_ff_try(callable $fn, string $code = 'nibwp_ff_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_ff_err($code, $e->getMessage());
    }
}

/**
 * Load a form row.
 *
 * @return object|WP_Error
 */
function nibwp_ff_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_ff_err('nibwp_ff_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $model = '\\FluentForm\\App\\Models\\Form';
    if (!class_exists($model)) {
        return nibwp_ff_err('nibwp_ff_no_model', __('The Fluent Forms model layer is unavailable.', domain: 'nibwp'));
    }

    $form = $model::find($form_id);

    if (!$form) {
        return nibwp_ff_err('nibwp_ff_not_found', __('No Fluent Forms form with that ID.', domain: 'nibwp'));
    }

    return $form;
}

/**
 * Read one form_meta value, JSON-decoded.
 *
 * Fluent Forms stores several rows under the same meta_key — one per
 * notification, for instance — so this returns a list keyed by the row ID
 * rather than a single value. Collapsing them would lose which row is which,
 * and the row ID is what an update has to target.
 *
 * @return array<int|string, mixed>
 */
function nibwp_ff_meta(int $form_id, string $key): array
{
    $model = '\\FluentForm\\App\\Models\\FormMeta';
    if (!class_exists($model)) {
        return [];
    }

    $rows = $model::where('form_id', $form_id)->where('meta_key', $key)->get();

    $out = [];
    foreach ($rows as $row) {
        $value = $row->value ?? null;
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $out[(int) ($row->id ?? 0)] = $decoded === null && is_string($value) ? $value : $decoded;
    }

    return $out;
}

/**
 * Write one form_meta row.
 *
 * @return int|WP_Error The row ID.
 */
function nibwp_ff_meta_put(int $form_id, string $key, $value, ?int $meta_id = null)
{
    $model = '\\FluentForm\\App\\Models\\FormMeta';
    if (!class_exists($model)) {
        return nibwp_ff_err('nibwp_ff_no_model', __('The Fluent Forms model layer is unavailable.', domain: 'nibwp'));
    }

    $encoded = is_string($value) ? $value : (string) wp_json_encode($value);

    if ($meta_id !== null && $meta_id > 0) {
        $row = $model::find($meta_id);
        if (!$row || (int) $row->form_id !== $form_id) {
            return nibwp_ff_err('nibwp_ff_bad_meta', __('That meta row does not belong to this form.', domain: 'nibwp'));
        }
        $row->value = $encoded;
        $row->save();

        return (int) $row->id;
    }

    $row = $model::create([
        'form_id'  => $form_id,
        'meta_key' => $key,
        'value'    => $encoded,
    ]);

    return (int) ($row->id ?? 0);
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_ff_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/** The form fields, decoded from the row's JSON. */
function nibwp_ff_fields(object $form): array
{
    $raw = $form->form_fields ?? '';
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

    return is_array($decoded) ? $decoded : [];
}

/**
 * Flatten Fluent Forms' nested field structure into name/type/label rows.
 *
 * Fields nest inside containers, and a submission is keyed by the field NAME,
 * so a flat list of names is what anything reading or writing entries needs.
 */
function nibwp_ff_flatten_fields(array $fields, array &$out = []): array
{
    foreach ($fields as $field) {
        $field = (array) $field;

        if (!empty($field['columns'])) {
            foreach ((array) $field['columns'] as $column) {
                $column = (array) $column;
                nibwp_ff_flatten_fields((array) ($column['fields'] ?? []), $out);
            }
            continue;
        }

        if (!empty($field['fields'])) {
            nibwp_ff_flatten_fields((array) $field['fields'], $out);
            continue;
        }

        $attributes = (array) ($field['attributes'] ?? []);
        $settings = (array) ($field['settings'] ?? []);
        $name = (string) ($attributes['name'] ?? '');

        if ($name === '') {
            continue;
        }

        $out[] = [
            'name'     => $name,
            'element'  => (string) ($field['element'] ?? ''),
            'type'     => (string) ($attributes['type'] ?? ''),
            'label'    => (string) ($settings['label'] ?? ''),
            'required' => !empty($settings['validation_rules']['required']['value']),
        ];
    }

    return $out;
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/fluentform-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-info', [
    'label'       => __('Fluent Forms — Info', domain: 'nibwp'),
    'description' => __('Detect Fluent Forms, whether Pro is active, how many forms and submissions exist, and what the Pro license unlocks here (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. `pro` decides what is reachable: payments, subscriptions and several integration feeds only exist with Fluent Forms Pro.',
                'Everything a form does beyond collecting data lives in form meta — notifications, confirmations, settings and feeds. Read those before concluding a form works.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_ff_info(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    return nibwp_ff_try(static function (): array {
        $forms = fluentFormApi('forms')->forms(['per_page' => 1]);
        $total_forms = (int) ($forms['total'] ?? (is_array($forms['data'] ?? null) ? count($forms['data']) : 0));

        $submissions = 0;
        $model = '\\FluentForm\\App\\Models\\Submission';
        if (class_exists($model)) {
            $submissions = (int) $model::count();
        }

        return [
            'active'      => true,
            'version'     => defined('FLUENTFORM_VERSION') ? FLUENTFORM_VERSION : '',
            'pro'         => nibwp_ff_pro(),
            'form_count'  => $total_forms,
            'submission_count' => $submissions,
            'pro_features' => nibwp_ff_pro()
                ? ['payments', 'subscriptions', 'advanced integration feeds', 'conversational forms']
                : [],
            'note' => nibwp_ff_pro()
                ? ''
                : __('Fluent Forms Pro is not active, so payments, subscriptions and the Pro integration feeds are unavailable.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/fluentform-forms (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-forms', [
    'label'       => __('Fluent Forms — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, rename and duplicate Fluent Forms forms, and get the shortcode for embedding one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'rename', 'duplicate', 'shortcode'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get returns the form with its fields and a summary of what meta it carries — notifications, confirmations, feeds — which is the fastest way to see whether a form actually does anything on submit.',
                'A newly created form has no notification, so submissions reach nobody until one is added.',
                'duplicate is the safe way to experiment on a form that is embedded somewhere.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'rename', 'duplicate', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_ff_err('nibwp_ff_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_ff_try(static function () use ($action, $form_id, $input) {
        if ($action === 'list') {
            $page = nibwp_ff_paginate($input);
            $result = fluentFormApi('forms')->forms(['per_page' => $page['per_page'], 'page' => $page['page']]);

            $rows = [];
            foreach ((array) ($result['data'] ?? []) as $form) {
                $form = (array) $form;
                $rows[] = [
                    'id'        => (int) ($form['id'] ?? 0),
                    'title'     => (string) ($form['title'] ?? ''),
                    'status'    => (string) ($form['status'] ?? ''),
                    'shortcode' => sprintf('[fluentform id="%d"]', (int) ($form['id'] ?? 0)),
                ];
            }

            return ['forms' => $rows, 'count' => count($rows), 'total' => (int) ($result['total'] ?? count($rows))];
        }

        $form = nibwp_ff_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        switch ($action) {
            case 'get':
                $fields = nibwp_ff_flatten_fields(nibwp_ff_fields($form));

                return [
                    'form_id' => $form_id,
                    'title'   => (string) ($form->title ?? ''),
                    'status'  => (string) ($form->status ?? ''),
                    'fields'  => $fields,
                    'field_count' => count($fields),
                    'meta_summary' => [
                        'notifications' => count(nibwp_ff_meta($form_id, 'notifications')),
                        'confirmations' => count(nibwp_ff_meta($form_id, 'confirmations')),
                        'settings'      => count(nibwp_ff_meta($form_id, 'formSettings')),
                    ],
                    'shortcode' => sprintf('[fluentform id="%d"]', $form_id),
                ];

            case 'create':
                $model = '\\FluentForm\\App\\Models\\Form';
                $new = $model::create([
                    'title'       => (string) ($input['title'] ?? __('Untitled form', domain: 'nibwp')),
                    'status'      => 'published',
                    'form_fields' => (string) wp_json_encode(['fields' => [], 'submitButton' => []]),
                    'has_payment' => 0,
                    'type'        => 'form',
                ]);

                return [
                    'form_id' => (int) ($new->id ?? 0),
                    'created' => true,
                    'note'    => __('No notification yet, so a submission would reach nobody.', domain: 'nibwp'),
                ];

            case 'rename':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new \RuntimeException(__('A title is required.', domain: 'nibwp'));
                }
                $form->title = $title;
                $form->save();

                return ['form_id' => $form_id, 'title' => $title, 'renamed' => true];

            case 'duplicate':
                $model = '\\FluentForm\\App\\Models\\Form';
                $copy = $model::create([
                    'title'       => (string) ($form->title ?? '') . ' ' . __('(copy)', domain: 'nibwp'),
                    'status'      => (string) ($form->status ?? 'published'),
                    'form_fields' => (string) ($form->form_fields ?? ''),
                    'has_payment' => (int) ($form->has_payment ?? 0),
                    'type'        => (string) ($form->type ?? 'form'),
                ]);

                $new_id = (int) ($copy->id ?? 0);

                // The form row alone is an empty shell: without the meta the
                // copy has no notifications, no confirmations and no settings,
                // which is not what "duplicate" means to anyone.
                foreach (['notifications', 'confirmations', 'formSettings'] as $key) {
                    foreach (nibwp_ff_meta($form_id, $key) as $value) {
                        nibwp_ff_meta_put($new_id, $key, $value);
                    }
                }

                return ['form_id' => $new_id, 'duplicated_from' => $form_id, 'meta_copied' => true];

            case 'shortcode':
                return ['form_id' => $form_id, 'shortcode' => sprintf('[fluentform id="%d"]', $form_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/fluentform-fields (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-fields', [
    'label'       => __('Fluent Forms — Fields', domain: 'nibwp'),
    'description' => __('Read a form\'s fields flattened to names and labels, read the raw field structure, or replace it.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'raw', 'set'], 'default' => 'list'],
            'form_id' => ['type' => 'integer'],
            'fields'  => ['type' => 'string', 'description' => 'set: the complete field structure as a JSON string.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_fields_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Submissions are keyed by field NAME, not by ID. list gives you those names — read it before touching entries, notifications or exports.',
                'set REPLACES the whole structure. Read raw first, and keep the existing names: renaming a field orphans every value already submitted under the old name, and any notification referencing it renders empty.',
                'Fluent Forms nests fields inside containers and columns; list flattens that, raw preserves it.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_fields_ability(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $form = nibwp_ff_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'set') {
        $raw = (string) ($input['fields'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return nibwp_ff_err(
                'nibwp_ff_bad_fields',
                sprintf(
                    /* translators: %s: JSON parser error */
                    __('The field structure could not be parsed: %s', domain: 'nibwp'),
                    json_last_error_msg()
                )
            );
        }
        $input['_decoded'] = $decoded;
    }

    return nibwp_ff_try(static function () use ($form, $action, $input) {
        $form_id = (int) $form->id;

        if ($action === 'raw') {
            return ['form_id' => $form_id, 'fields' => nibwp_ff_fields($form)];
        }

        if ($action === 'set') {
            $before = nibwp_ff_flatten_fields(nibwp_ff_fields($form));
            $before_names = array_column($before, 'name');

            $form->form_fields = (string) wp_json_encode($input['_decoded']);
            $form->save();

            $after_names = array_column(nibwp_ff_flatten_fields($input['_decoded']), 'name');
            $lost = array_values(array_diff($before_names, $after_names));

            return [
                'form_id' => $form_id,
                'updated' => true,
                'removed_fields' => $lost,
                'warning' => $lost === []
                    ? ''
                    : __('Field names disappeared. Values already submitted under them are orphaned, and any notification referencing them will render empty.', domain: 'nibwp'),
            ];
        }

        $fields = nibwp_ff_flatten_fields(nibwp_ff_fields($form));

        return ['form_id' => $form_id, 'fields' => $fields, 'count' => count($fields)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/fluentform-entries (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-entries', [
    'label'       => __('Fluent Forms — Submissions', domain: 'nibwp'),
    'description' => __('List and read submissions, and change their state: status, favorite and read/unread.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'set_status', 'favorite', 'unfavorite', 'mark_read', 'mark_unread', 'count'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer', 'description' => 'Required for list and count.'],
            'entry_id' => ['type' => 'integer', 'description' => 'Required for every per-entry action.'],
            'status'  => ['type' => 'string', 'description' => 'set_status: unread, read, trashed — or whatever statuses this install uses.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_entries',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Submissions are personal data. Read what the task needs and do not copy it somewhere it was not collected for.',
                'Values are keyed by field NAME — read nibwp/fluentform-fields first.',
                'Setting a status to trashed is reversible by setting it back; permanent deletion lives in nibwp/fluentform-delete.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_entries(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $entry_id = (int) ($input['entry_id'] ?? 0);

    $per_entry = ['get', 'set_status', 'favorite', 'unfavorite', 'mark_read', 'mark_unread'];
    if (in_array($action, $per_entry, strict: true) && $entry_id <= 0) {
        return nibwp_ff_err('nibwp_ff_bad_id', __('A valid submission ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['list', 'count'], strict: true) && $form_id <= 0) {
        return nibwp_ff_err('nibwp_ff_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_ff_try(static function () use ($action, $form_id, $entry_id, $input) {
        $model = '\\FluentForm\\App\\Models\\Submission';

        if ($action === 'count') {
            return ['form_id' => $form_id, 'total' => (int) $model::where('form_id', $form_id)->count()];
        }

        if ($action === 'list') {
            $page = nibwp_ff_paginate($input);
            $rows = $model::where('form_id', $form_id)
                ->orderBy('id', 'DESC')
                ->limit($page['per_page'])
                ->offset(($page['page'] - 1) * $page['per_page'])
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'      => (int) $row->id,
                    'status'  => (string) ($row->status ?? ''),
                    'is_favorite' => (bool) ($row->is_favorite ?? false),
                    'created_at'   => (string) ($row->created_at ?? ''),
                ];
            }

            return [
                'form_id'     => $form_id,
                'submissions' => $out,
                'count'       => count($out),
                'total'       => (int) $model::where('form_id', $form_id)->count(),
            ];
        }

        $entry = $model::find($entry_id);
        if (!$entry) {
            throw new \RuntimeException(__('No submission with that ID.', domain: 'nibwp'));
        }

        if ($action === 'get') {
            $response = $entry->response ?? '';
            $decoded = is_string($response) ? json_decode($response, true) : $response;

            return [
                'entry_id'   => $entry_id,
                'form_id'    => (int) ($entry->form_id ?? 0),
                'status'     => (string) ($entry->status ?? ''),
                'created_at' => (string) ($entry->created_at ?? ''),
                'values'     => is_array($decoded) ? $decoded : [],
            ];
        }

        $map = [
            'set_status'  => ['status', (string) ($input['status'] ?? '')],
            'favorite'   => ['is_favorite', 1],
            'unfavorite' => ['is_favorite', 0],
            'mark_read'   => ['status', 'read'],
            'mark_unread' => ['status', 'unread'],
        ];

        if (!isset($map[$action])) {
            throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
        }

        [$property, $value] = $map[$action];

        if ($action === 'set_status' && $value === '') {
            throw new \RuntimeException(__('A status is required.', domain: 'nibwp'));
        }

        $entry->{$property} = $value;
        $entry->save();

        return ['entry_id' => $entry_id, $property => $value];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/fluentform-notifications (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-notifications', [
    'label'       => __('Fluent Forms — Email notifications', domain: 'nibwp'),
    'description' => __('Read and configure the emails a form sends on submit — recipient, subject, body, and whether each is enabled.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'delete'], 'default' => 'list'],
            'form_id' => ['type' => 'integer'],
            'meta_id' => ['type' => 'integer', 'description' => 'The meta row ID, as returned by list. Required for update and delete.'],
            'notification' => ['type' => 'object', 'description' => 'add/update: name, sendTo, subject, message, enabled.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_notifications',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This decides whether anyone learns about a submission. A form with no enabled notification collects data silently.',
                'Each notification is its own row in form meta, addressed by meta_id — not by an index into a list.',
                'Bodies use smartcodes: {inputs.your_field_name} for a value, {all_data} for everything. A smartcode naming a field that does not exist renders empty rather than erroring, so read the field names first.',
                'update merges, so send only what changes.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_notifications(array $input): array|WP_Error
{
    return nibwp_ff_meta_collection($input, 'notifications', 'notification');
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/fluentform-confirmations (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-confirmations', [
    'label'       => __('Fluent Forms — Confirmations', domain: 'nibwp'),
    'description' => __('Read and configure what a visitor sees after submitting — a message, a redirect, or another page.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'delete'], 'default' => 'list'],
            'form_id' => ['type' => 'integer'],
            'meta_id' => ['type' => 'integer'],
            'confirmation' => ['type' => 'object', 'description' => 'add/update: redirectTo, messageToShow, customPage, customUrl.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_confirmations',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'A redirect to a URL that does not exist is a silent dead end for whoever just filled the form — check the target before setting one.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_confirmations(array $input): array|WP_Error
{
    return nibwp_ff_meta_collection($input, 'confirmations', 'confirmation');
}

/**
 * Notifications and confirmations are the same shape: JSON rows in form meta
 * under one key. One implementation, so the two cannot drift apart.
 *
 * @return array|WP_Error
 */
function nibwp_ff_meta_collection(array $input, string $key, string $singular)
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $form = nibwp_ff_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? 'list');
    $meta_id = (int) ($input['meta_id'] ?? 0);

    if (in_array($action, ['update', 'delete'], strict: true) && $meta_id <= 0) {
        return nibwp_ff_err('nibwp_ff_bad_id', __('A meta_id is required. List them first.', domain: 'nibwp'));
    }

    return nibwp_ff_try(static function () use ($form, $action, $key, $singular, $meta_id, $input) {
        $form_id = (int) $form->id;
        $rows = nibwp_ff_meta($form_id, $key);

        if ($action === 'list') {
            $out = [];
            $enabled = 0;

            foreach ($rows as $id => $value) {
                $value = (array) $value;
                // Fluent Forms treats a missing `enabled` as on, so an absent
                // key must not be read as disabled.
                $is_on = !array_key_exists('enabled', $value) || (bool) $value['enabled'];
                if ($is_on) {
                    $enabled++;
                }

                $out[] = [
                    'meta_id' => $id,
                    'name'    => (string) ($value['name'] ?? ''),
                    'enabled' => $is_on,
                    'sendTo'  => $value['sendTo'] ?? null,
                    'subject' => (string) ($value['subject'] ?? ''),
                    $singular => $value,
                ];
            }

            return [
                'form_id' => $form_id,
                $key      => $out,
                'count'   => count($out),
                'enabled' => $enabled,
                'warning' => ($key === 'notifications' && $enabled === 0)
                    ? __('No enabled notification. Submissions to this form reach nobody.', domain: 'nibwp')
                    : '',
            ];
        }

        if ($action === 'add') {
            $value = (array) ($input[$singular] ?? []);
            if ($value === []) {
                throw new \RuntimeException(__('An object is required.', domain: 'nibwp'));
            }
            $new_id = nibwp_ff_meta_put($form_id, $key, $value);

            return $new_id instanceof WP_Error ? $new_id : ['form_id' => $form_id, 'meta_id' => $new_id, 'created' => true];
        }

        if (!array_key_exists($meta_id, $rows)) {
            throw new \RuntimeException(__('No such row on this form.', domain: 'nibwp'));
        }

        if ($action === 'update') {
            // Merged, not replaced: changing a subject should not clear the
            // recipient and the body.
            $merged = array_merge((array) $rows[$meta_id], (array) ($input[$singular] ?? []));
            $result = nibwp_ff_meta_put($form_id, $key, $merged, $meta_id);

            return $result instanceof WP_Error ? $result : ['form_id' => $form_id, 'meta_id' => $meta_id, 'updated' => true];
        }

        $model = '\\FluentForm\\App\\Models\\FormMeta';
        $row = $model::find($meta_id);
        if ($row) {
            $row->delete();
        }

        return ['form_id' => $form_id, 'meta_id' => $meta_id, 'deleted' => true];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/fluentform-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-settings', [
    'label'       => __('Fluent Forms — Form settings', domain: 'nibwp'),
    'description' => __('Read and change a form\'s settings: layout, scheduling, entry limits, login requirement and the rest of formSettings.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id'  => ['type' => 'integer'],
            'settings' => ['type' => 'object', 'description' => 'set: merged into the existing settings.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Restrictions live here: scheduling, entry limits and login requirements all silently close a form to submissions and are easy to leave behind after a campaign.',
                'Settings merge, so send only what changes.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $form = nibwp_ff_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_ff_try(static function () use ($form, $input) {
        $form_id = (int) $form->id;
        $rows = nibwp_ff_meta($form_id, 'formSettings');
        $meta_id = array_key_first($rows);
        $current = $meta_id === null ? [] : (array) $rows[$meta_id];

        if ((string) ($input['action'] ?? 'get') === 'set') {
            $settings = (array) ($input['settings'] ?? []);
            if ($settings === []) {
                throw new \RuntimeException(__('Nothing to set.', domain: 'nibwp'));
            }

            $merged = array_replace_recursive($current, $settings);
            $result = nibwp_ff_meta_put($form_id, 'formSettings', $merged, $meta_id);
            if ($result instanceof WP_Error) {
                return $result;
            }

            $restrictions = (array) ($merged['restrictions'] ?? []);
            $gates = [];
            foreach (['limitNumberOfEntries', 'scheduleForm', 'requireLogin', 'restrictForm'] as $gate) {
                if (!empty($restrictions[$gate]['enabled']) || !empty($merged[$gate])) {
                    $gates[] = $gate;
                }
            }

            return [
                'form_id' => $form_id,
                'updated' => true,
                'submission_gates' => $gates,
                'warning' => $gates === []
                    ? ''
                    : __('This form now restricts who can submit, or when. Confirm that is intended.', domain: 'nibwp'),
            ];
        }

        return ['form_id' => $form_id, 'settings' => $current, 'meta_id' => $meta_id];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/fluentform-integrations (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-integrations', [
    'label'       => __('Fluent Forms — Integration feeds', domain: 'nibwp'),
    'description' => __('Discover every integration feed configured on a form — webhooks, CRM syncs, mail platforms — and read or change one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['discover', 'get', 'set', 'delete'], 'default' => 'discover'],
            'form_id'  => ['type' => 'integer'],
            'meta_key' => ['type' => 'string', 'description' => 'The feed key, as returned by discover.'],
            'meta_id'  => ['type' => 'integer', 'description' => 'The specific row. Required for set on an existing feed, and for delete.'],
            'value'    => ['type' => 'object', 'description' => 'set: the feed configuration.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_integrations',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Deliberately generic. Every Fluent Forms add-on writes its own meta key, so enumerating what a form actually has is the only way to see them all — a hardcoded list of known integrations would miss whatever is installed here.',
                'discover first. The shape of a feed is defined by its add-on, so read an existing one before writing that kind, and never guess: a wrong shape is stored and then silently does nothing.',
                'Changing a CRM or payment feed affects live data. Say what will change before writing.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_integrations(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $form = nibwp_ff_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_ff_try(static function () use ($form, $input) {
        $form_id = (int) $form->id;
        $model = '\\FluentForm\\App\\Models\\FormMeta';
        $action = (string) ($input['action'] ?? 'discover');

        // The keys Fluent Forms uses for the form itself rather than for an
        // integration. Everything else on a form is a feed of some kind.
        $core_keys = ['notifications', 'confirmations', 'formSettings', '_total_views', 'template_name', 'revision', '_notes', '_custom_form_css', '_custom_form_js', '_entry_uid_hash', '__entry_intermediate_hash', 'is_conversion_form', '_fluent_forms_has_role'];

        if ($action === 'discover') {
            $rows = $model::where('form_id', $form_id)->get();

            $feeds = [];
            $core = [];
            foreach ($rows as $row) {
                $key = (string) ($row->meta_key ?? '');
                $entry = ['meta_id' => (int) $row->id, 'meta_key' => $key];

                if (in_array($key, $core_keys, strict: true)) {
                    $core[] = $entry;
                } else {
                    $feeds[] = $entry;
                }
            }

            return [
                'form_id'   => $form_id,
                'feeds'     => $feeds,
                'feed_count' => count($feeds),
                'core_meta' => $core,
                'note'      => $feeds === []
                    ? __('No integration feeds on this form — it does nothing beyond storing the submission and sending any notifications.', domain: 'nibwp')
                    : '',
            ];
        }

        $meta_key = (string) ($input['meta_key'] ?? '');
        $meta_id = (int) ($input['meta_id'] ?? 0);

        if ($action === 'get') {
            if ($meta_key === '') {
                throw new \RuntimeException(__('A meta_key is required. Run discover first.', domain: 'nibwp'));
            }

            return ['form_id' => $form_id, 'meta_key' => $meta_key, 'feeds' => nibwp_ff_meta($form_id, $meta_key)];
        }

        if ($action === 'set') {
            if ($meta_key === '') {
                throw new \RuntimeException(__('A meta_key is required.', domain: 'nibwp'));
            }
            $result = nibwp_ff_meta_put($form_id, $meta_key, (array) ($input['value'] ?? []), $meta_id > 0 ? $meta_id : null);

            return $result instanceof WP_Error ? $result : ['form_id' => $form_id, 'meta_key' => $meta_key, 'meta_id' => $result, 'saved' => true];
        }

        if ($meta_id <= 0) {
            throw new \RuntimeException(__('A meta_id is required to delete a feed.', domain: 'nibwp'));
        }

        $row = $model::find($meta_id);
        if (!$row || (int) $row->form_id !== $form_id) {
            throw new \RuntimeException(__('That feed does not belong to this form.', domain: 'nibwp'));
        }
        $row->delete();

        return ['form_id' => $form_id, 'meta_id' => $meta_id, 'deleted' => true];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/fluentform-payments (read-only, Pro)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-payments', [
    'label'       => __('Fluent Forms — Payments', domain: 'nibwp'),
    'description' => __('Read transactions and subscriptions captured by Fluent Forms Pro payment forms (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['transactions', 'transaction', 'subscriptions', 'subscription', 'by_submission'], 'default' => 'transactions'],
            'id'       => ['type' => 'integer', 'description' => 'Transaction or subscription ID.'],
            'entry_id' => ['type' => 'integer', 'description' => 'by_submission: the submission to read payments for.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_payments',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read-only on purpose. Refunds and subscription cancellations belong at the payment provider, where the money actually is — changing a row here would desynchronise the two and lie about both.',
                'Financial and personal data. Read what the task needs, nothing more.',
                'Needs Fluent Forms Pro.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_ff_payments(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard_pro()) {
        return $guard;
    }

    return nibwp_ff_try(static function () use ($input) {
        $api = fluentFormApi('submissions');
        $action = (string) ($input['action'] ?? 'transactions');
        $id = (int) ($input['id'] ?? 0);

        switch ($action) {
            case 'transactions':
                return ['transactions' => $api->transactions()];

            case 'transaction':
                if ($id <= 0) {
                    throw new \RuntimeException(__('A transaction ID is required.', domain: 'nibwp'));
                }

                return ['transaction' => $api->transaction($id)];

            case 'subscriptions':
                return ['subscriptions' => $api->subscriptions()];

            case 'subscription':
                if ($id <= 0) {
                    throw new \RuntimeException(__('A subscription ID is required.', domain: 'nibwp'));
                }

                return ['subscription' => $api->getSubscription($id)];

            case 'by_submission':
                $entry_id = (int) ($input['entry_id'] ?? 0);
                if ($entry_id <= 0) {
                    throw new \RuntimeException(__('A submission ID is required.', domain: 'nibwp'));
                }

                return ['entry_id' => $entry_id, 'transactions' => $api->transactionsBySubmissionId($entry_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 10 — nibwp/fluentform-analytics (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-analytics', [
    'label'       => __('Fluent Forms — Analytics', domain: 'nibwp'),
    'description' => __('Views, submissions, conversion rate and unread count for a form (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_analytics',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'A high view count with a low conversion rate usually means the form is too long, or a required field is failing validation more often than anyone realises. Worth pairing with the field list.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_ff_analytics(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_ff_err('nibwp_ff_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_ff_try(static function () use ($form_id): array {
        // form(), not find(). find() returns the Form model row; form() wraps it
        // in FormProperties, which is what exposes viewCount, submissionCount,
        // unreadCount and conversionRate through its __get.
        $properties = fluentFormApi('forms')->form($form_id);

        if (!is_object($properties)) {
            throw new \RuntimeException(__('No Fluent Forms form with that ID.', domain: 'nibwp'));
        }

        return [
            'form_id'    => $form_id,
            'views'      => isset($properties->viewCount) ? (int) $properties->viewCount : null,
            'submissions' => isset($properties->submissionCount) ? (int) $properties->submissionCount : null,
            'unread'     => isset($properties->unreadCount) ? (int) $properties->unreadCount : null,
            'conversion_rate' => $properties->conversionRate ?? null,
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 11 — nibwp/fluentform-export (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-export', [
    'label'       => __('Fluent Forms — Export submissions', domain: 'nibwp'),
    'description' => __('Export a form\'s submissions as rows or CSV, with the field labels as headers (read-only).', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_ff_export',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Paginated on purpose. A busy form will not fit in one response and asking for everything fails slowly.',
                'Personal data leaving its original context — export the columns the task needs, not everything.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_ff_export(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    $form = nibwp_ff_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_ff_try(static function () use ($form, $input) {
        $form_id = (int) $form->id;
        $page = nibwp_ff_paginate($input);

        $fields = nibwp_ff_flatten_fields(nibwp_ff_fields($form));
        $labels = [];
        foreach ($fields as $field) {
            $labels[$field['name']] = $field['label'] !== '' ? $field['label'] : $field['name'];
        }

        $model = '\\FluentForm\\App\\Models\\Submission';
        $rows = $model::where('form_id', $form_id)
            ->orderBy('id', 'DESC')
            ->limit($page['per_page'])
            ->offset(($page['page'] - 1) * $page['per_page'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $response = $row->response ?? '';
            $values = is_string($response) ? json_decode($response, true) : $response;
            $values = is_array($values) ? $values : [];

            $record = ['Submission ID' => (int) $row->id, 'Date' => (string) ($row->created_at ?? '')];
            foreach ($labels as $name => $label) {
                $value = $values[$name] ?? '';
                // A value can be an array — checkboxes, multi-selects, address
                // groups — and a raw array in a CSV cell is unreadable.
                $record[$label] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }
            $out[] = $record;
        }

        $columns = array_merge(['Submission ID', 'Date'], array_values($labels));

        if ((string) ($input['format'] ?? 'rows') === 'csv') {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                throw new \RuntimeException(__('Could not open a buffer to build the CSV.', domain: 'nibwp'));
            }
            fputcsv($handle, $columns);
            foreach ($out as $record) {
                fputcsv($handle, array_values($record));
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return ['form_id' => $form_id, 'format' => 'csv', 'csv' => $csv, 'count' => count($out)];
        }

        return ['form_id' => $form_id, 'format' => 'rows', 'columns' => $columns, 'rows' => $out, 'count' => count($out)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 12 — nibwp/fluentform-audit (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-audit', [
    'label'       => __('Fluent Forms — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the faults that lose enquiries: no enabled notification, no confirmation, no fields, and restrictions that have quietly closed a form (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_ff_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run this on any site that already uses Fluent Forms. A form collecting submissions nobody is told about looks identical to a working one.',
                'Findings carry their fix. Show them and let the user decide.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_ff_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    return nibwp_ff_try(static function () use ($input): array {
        $model = '\\FluentForm\\App\\Models\\Form';
        $form_id = (int) ($input['form_id'] ?? 0);

        $forms = $form_id > 0
            ? array_filter([$model::find($form_id)])
            : $model::orderBy('id', 'ASC')->limit(200)->get();

        $findings = [];
        $checked = 0;

        foreach ($forms as $form) {
            $checked++;
            $id = (int) $form->id;
            $title = (string) ($form->title ?? '');

            $notifications = nibwp_ff_meta($id, 'notifications');
            $enabled = 0;
            foreach ($notifications as $notification) {
                $notification = (array) $notification;
                if (!array_key_exists('enabled', $notification) || $notification['enabled']) {
                    $enabled++;
                }
            }

            if ($enabled === 0) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'blocker',
                    'code'     => 'no_enabled_notification',
                    'message'  => __('No enabled notification. Submissions are stored and nobody is told.', domain: 'nibwp'),
                    'fix'      => __('Add one with nibwp/fluentform-notifications, or confirm the entries are only read in the admin.', domain: 'nibwp'),
                ];
            }

            if (nibwp_ff_meta($id, 'confirmations') === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_confirmation',
                    'message'  => __('No confirmation, so a visitor gets no acknowledgement after submitting.', domain: 'nibwp'),
                    'fix'      => __('Add one with nibwp/fluentform-confirmations.', domain: 'nibwp'),
                ];
            }

            if (nibwp_ff_flatten_fields(nibwp_ff_fields($form)) === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('The form has no fields, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add fields with nibwp/fluentform-fields.', domain: 'nibwp'),
                ];
            }

            $settings_rows = nibwp_ff_meta($id, 'formSettings');
            $settings = $settings_rows === [] ? [] : (array) reset($settings_rows);
            $restrictions = (array) ($settings['restrictions'] ?? []);

            foreach (['limitNumberOfEntries', 'scheduleForm', 'requireLogin'] as $gate) {
                if (!empty($restrictions[$gate]['enabled'])) {
                    $findings[] = [
                        'form_id'  => $id,
                        'title'    => $title,
                        'severity' => 'note',
                        'code'     => 'restriction_' . $gate,
                        'message'  => sprintf(
                            /* translators: %s: the restriction key */
                            __('The restriction "%s" is on, which limits who can submit or when.', domain: 'nibwp'),
                            $gate
                        ),
                        'fix'      => __('Intentional on a campaign or members-only form; a mistake left behind on a contact form.', domain: 'nibwp'),
                    ];
                }
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'forms_checked' => $checked,
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'notes'    => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'note')),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 13 — nibwp/fluentform-delete (destructive)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/fluentform-delete', [
    'label'       => __('Fluent Forms — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete a form or a submission. Irreversible.', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_ff_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Deleting a form takes its submissions, its notifications, its settings and every integration feed with it.',
                'A submission can be set to a trashed status instead with nibwp/fluentform-entries, which is reversible. Prefer that.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_ff_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_ff_guard()) {
        return $guard;
    }

    if (!(bool) ($input['confirm'] ?? false)) {
        return nibwp_ff_err(
            'nibwp_ff_unconfirmed',
            __('This permanently destroys data and cannot be undone. Re-issue with confirm true if that is intended.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? '');

    return nibwp_ff_try(static function () use ($action, $input) {
        if ($action === 'delete_form') {
            $form_id = (int) ($input['form_id'] ?? 0);
            $form = nibwp_ff_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            $submission = '\\FluentForm\\App\\Models\\Submission';
            $meta = '\\FluentForm\\App\\Models\\FormMeta';

            $entries = (int) $submission::where('form_id', $form_id)->count();

            // Deleted explicitly rather than trusting a cascade: the meta and
            // submission tables are separate, and orphaned rows on a reused ID
            // would attach someone else's notifications to a future form.
            $submission::where('form_id', $form_id)->delete();
            $meta::where('form_id', $form_id)->delete();
            $form->delete();

            return [
                'form_id'         => $form_id,
                'deleted'         => true,
                'entries_deleted' => $entries,
                'reversible'      => false,
            ];
        }

        if ($action === 'delete_entry') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            if ($entry_id <= 0) {
                throw new \RuntimeException(__('A valid submission ID is required.', domain: 'nibwp'));
            }

            $submission = '\\FluentForm\\App\\Models\\Submission';
            $row = $submission::find($entry_id);
            if (!$row) {
                throw new \RuntimeException(__('No submission with that ID.', domain: 'nibwp'));
            }
            $row->delete();

            return ['entry_id' => $entry_id, 'deleted' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
