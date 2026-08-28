<?php

declare(strict_types=1);

/**
 * Ninja Forms integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Nine abilities: forms, fields, actions, submissions, settings, export, an
 * audit and deletion, plus info.
 *
 * THE THING TO KNOW ABOUT NINJA FORMS: storing a submission is itself an
 * action. Ninja Forms models everything a form does on submit — emailing,
 * redirecting, showing a success message, and SAVING THE SUBMISSION — as
 * entries in an action list. Remove the "save" action and the form still
 * validates, still emails, still thanks the visitor, and keeps no record at
 * all. Every other form plugin in this directory stores by default and
 * notifies optionally; Ninja Forms makes both optional, so a form can be
 * misconfigured into losing data in two separate ways.
 *
 * That is what the audit looks for, and why the actions ability reports the
 * save and email actions separately rather than just counting them.
 *
 * Mechanism is IN-PROCESS through Ninja Forms' own model layer:
 *   Ninja_Forms()->form()->get_forms()         list
 *   Ninja_Forms()->form( $id )->get_fields()   fields
 *   Ninja_Forms()->form( $id )->get_actions()  actions
 *   Ninja_Forms()->form( $id )->get_subs()     submissions
 *   ->get_setting() / ->update_setting() / ->save() on each model
 *
 * Ninja_Forms()->form( $id ) returns a ModelFactory, not the model itself. Its
 * __call() proxies unknown methods through to the underlying model, which is
 * how get_setting(), update_setting(), save() and delete() are reached. That
 * proxy passes its arguments as a single ARRAY rather than spreading them, so
 * anything called through it takes no arguments here — the factory is asked for
 * the right object first, then the method is called bare.
 *
 * There is no duplicate() anywhere in the model layer. Ninja Forms duplicates a
 * form by exporting it and importing the result, which is what this does.
 *
 * Detection: function_exists('Ninja_Forms').
 *
 * Verified against Ninja Forms 3.15.0 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Is Ninja Forms active? */
function nibwp_nf_available(): bool
{
    return function_exists('Ninja_Forms');
}

/** House WP_Error wrapper. */
function nibwp_nf_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_nf_guard(): ?WP_Error
{
    if (!nibwp_nf_available()) {
        return nibwp_nf_err('nibwp_nf_missing', __('Ninja Forms is not active on this site.', domain: 'nibwp'));
    }

    return null;
}

/** Run a Ninja Forms call, converting throwables into WP_Error. */
function nibwp_nf_try(callable $fn, string $code = 'nibwp_nf_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_nf_err($code, $e->getMessage());
    }
}

/**
 * The model behind a form.
 *
 * Ninja_Forms()->form( $id ) hands back a ModelFactory, not the model — the
 * factory carries the collection getters (fields, actions, subs) while the
 * settings live on the model underneath it. Asking the factory for
 * get_settings() looks reasonable and is always false to method_exists(),
 * because it only answers that call through __call.
 *
 * @return object|null
 */
function nibwp_nf_model(int $form_id)
{
    $factory = Ninja_Forms()->form($form_id);

    if (!is_object($factory) || !method_exists($factory, 'get_model')) {
        return null;
    }

    $model = $factory->get_model($form_id, 'form');

    return is_object($model) ? $model : null;
}

/**
 * Load a form, returning the FACTORY — which is what the collection getters
 * hang off. Existence is decided on the model's settings, because Ninja Forms
 * builds a factory for any ID whether or not a form is behind it.
 *
 * @return object|WP_Error
 */
function nibwp_nf_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_nf_err('nibwp_nf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $model = nibwp_nf_model($form_id);

    if ($model === null || !method_exists($model, 'get_settings')) {
        return nibwp_nf_err('nibwp_nf_not_found', __('No Ninja Forms form with that ID.', domain: 'nibwp'));
    }

    $settings = $model->get_settings();

    if (!is_array($settings) || $settings === []) {
        return nibwp_nf_err('nibwp_nf_not_found', __('No Ninja Forms form with that ID.', domain: 'nibwp'));
    }

    return Ninja_Forms()->form($form_id);
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_nf_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/** One model's settings, whatever accessor this version offers. */
function nibwp_nf_settings($model): array
{
    if (!is_object($model)) {
        return [];
    }

    if (method_exists($model, 'get_settings')) {
        $settings = $model->get_settings();

        return is_array($settings) ? $settings : [];
    }

    return [];
}

/**
 * A form's actions, summarised, with the two that matter called out.
 *
 * @return array{actions: list<array>, has_email: bool, has_save: bool}
 */
function nibwp_nf_action_summary(int $form_id): array
{
    $actions = [];
    $has_email = false;
    $has_save = false;

    $form = Ninja_Forms()->form($form_id);

    if (!is_object($form) || !method_exists($form, 'get_actions')) {
        return ['actions' => [], 'has_email' => false, 'has_save' => false];
    }

    foreach ((array) $form->get_actions() as $action) {
        $settings = nibwp_nf_settings($action);
        $type = (string) ($settings['type'] ?? '');
        // Ninja Forms marks a disabled action with an `active` setting; absent
        // means active, so a missing key must not read as switched off.
        $active = !array_key_exists('active', $settings) || (bool) $settings['active'];

        if ($active && $type === 'email') {
            $has_email = true;
        }
        if ($active && $type === 'save') {
            $has_save = true;
        }

        $actions[] = [
            'id'     => method_exists($action, 'get_id') ? (int) $action->get_id() : null,
            'type'   => $type,
            'label'  => (string) ($settings['label'] ?? ''),
            'active' => $active,
        ];
    }

    return ['actions' => $actions, 'has_email' => $has_email, 'has_save' => $has_save];
}

/* ── Ability 1 — info ───────────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-info', [
    'label'       => __('Ninja Forms — Info', domain: 'nibwp'),
    'description' => __('Detect Ninja Forms, its version and how many forms and submissions exist (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first.',
                'Ninja Forms is unusual: SAVING a submission is an action, not a default. A form can be configured to email you and store nothing, or store everything and email nobody. Check nibwp/ninjaforms-actions before assuming either.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_nf_info(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    return nibwp_nf_try(static function (): array {
        $forms = Ninja_Forms()->form()->get_forms();
        $forms = is_array($forms) ? $forms : [];

        $submissions = null;
        if (post_type_exists('nf_sub')) {
            $counts = wp_count_posts('nf_sub');
            $submissions = (int) ($counts->publish ?? 0);
        }

        return [
            'active'      => true,
            'version'     => defined('NF_PLUGIN_VERSION') ? NF_PLUGIN_VERSION : '',
            'form_count'  => count($forms),
            'stored_submissions' => $submissions,
            'note' => __('Storing a submission is an action in Ninja Forms. A form without a "save" action keeps nothing, however healthy it looks.', domain: 'nibwp'),
        ];
    });
}

/* ── Ability 2 — forms ──────────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-forms', [
    'label'       => __('Ninja Forms — Forms', domain: 'nibwp'),
    'description' => __('List, read, rename and duplicate Ninja Forms forms, and get the shortcode for embedding one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'rename', 'duplicate', 'shortcode'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'get reports whether the form has an email action and whether it has a save action. Those two answer "does anyone hear about this" and "is any of it kept", which are separate questions in Ninja Forms.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_nf_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'rename', 'duplicate', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_nf_err('nibwp_nf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_nf_try(static function () use ($action, $form_id, $input) {
        if ($action === 'list') {
            $forms = Ninja_Forms()->form()->get_forms();
            $forms = is_array($forms) ? $forms : [];

            $page = nibwp_nf_paginate($input);
            $slice = array_slice($forms, ($page['page'] - 1) * $page['per_page'], $page['per_page']);

            $rows = [];
            foreach ($slice as $form) {
                $id = method_exists($form, 'get_id') ? (int) $form->get_id() : 0;
                $settings = nibwp_nf_settings($form);

                $rows[] = [
                    'id'        => $id,
                    'title'     => (string) ($settings['title'] ?? ''),
                    'shortcode' => sprintf('[ninja_form id=%d]', $id),
                ];
            }

            return ['forms' => $rows, 'count' => count($rows), 'total' => count($forms)];
        }

        $form = nibwp_nf_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        // Settings come off the model; the collection getters off the factory.
        $settings = nibwp_nf_settings(nibwp_nf_model($form_id));

        switch ($action) {
            case 'get':
                $summary = nibwp_nf_action_summary($form_id);
                $fields = method_exists($form, 'get_fields') ? (array) $form->get_fields() : [];

                return [
                    'form_id'     => $form_id,
                    'title'       => (string) ($settings['title'] ?? ''),
                    'field_count' => count($fields),
                    'action_count' => count($summary['actions']),
                    'emails_someone' => $summary['has_email'],
                    'stores_submissions' => $summary['has_save'],
                    'shortcode'   => sprintf('[ninja_form id=%d]', $form_id),
                ];

            case 'rename':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new \RuntimeException(__('A title is required.', domain: 'nibwp'));
                }

                // The model, not the factory: update_setting() takes two
                // arguments, and the factory's __call proxy would deliver them
                // as one array.
                $model = nibwp_nf_model($form_id);
                if ($model === null || !method_exists($model, 'update_setting')) {
                    throw new \RuntimeException(__('This Ninja Forms version does not expose updating a form setting.', domain: 'nibwp'));
                }
                $model->update_setting('title', $title);
                $model->save();

                return ['form_id' => $form_id, 'title' => $title, 'renamed' => true];

            case 'duplicate':
                // Ninja Forms has no duplicate() — a copy is an export fed
                // straight back through import, which is what its own admin
                // does. Anything else misses the actions and fields.
                $factory = Ninja_Forms()->form($form_id);

                if (!method_exists($factory, 'export_form') || !method_exists(Ninja_Forms()->form(), 'import_form')) {
                    throw new \RuntimeException(__('This Ninja Forms version does not expose exporting or importing a form.', domain: 'nibwp'));
                }

                $export = $factory->export_form(true);
                if (empty($export)) {
                    throw new \RuntimeException(__('Ninja Forms returned nothing to copy.', domain: 'nibwp'));
                }

                $new_id = Ninja_Forms()->form()->import_form($export);

                return ['form_id' => (int) $new_id, 'duplicated_from' => $form_id, 'via' => 'export/import'];

            case 'shortcode':
                return ['form_id' => $form_id, 'shortcode' => sprintf('[ninja_form id=%d]', $form_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ── Ability 3 — fields ─────────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-fields', [
    'label'       => __('Ninja Forms — Fields', domain: 'nibwp'),
    'description' => __('Read the fields on a form, with the keys that submissions and merge tags reference.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer'],
            'field_id' => ['type' => 'integer'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Field KEYS, not IDs, are what email actions reference in merge tags like {field:your_name} and what submissions store values under. Read them before writing or interpreting either.',
                'Read-only: Ninja Forms builds fields through its own builder with per-type settings and validation, and writing them from outside produces forms the builder then rewrites.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_nf_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_nf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_nf_try(static function () use ($form, $form_id, $input) {
        if (!method_exists($form, 'get_fields')) {
            throw new \RuntimeException(__('This Ninja Forms version does not expose form fields.', domain: 'nibwp'));
        }

        $fields = (array) $form->get_fields();
        $rows = [];

        foreach ($fields as $field) {
            $settings = nibwp_nf_settings($field);
            $rows[] = [
                'id'       => method_exists($field, 'get_id') ? (int) $field->get_id() : null,
                'key'      => (string) ($settings['key'] ?? ''),
                'type'     => (string) ($settings['type'] ?? ''),
                'label'    => (string) ($settings['label'] ?? ''),
                'required' => !empty($settings['required']),
            ];
        }

        if ((string) ($input['action'] ?? 'list') === 'get') {
            $field_id = (int) ($input['field_id'] ?? 0);
            foreach ($rows as $row) {
                if ($row['id'] === $field_id) {
                    return ['form_id' => $form_id, 'field' => $row];
                }
            }

            throw new \RuntimeException(__('No field with that ID on this form.', domain: 'nibwp'));
        }

        return [
            'form_id' => $form_id,
            'fields'  => $rows,
            'count'   => count($rows),
            'merge_tags' => array_values(array_map(
                static fn(array $r): string => '{field:' . $r['key'] . '}',
                array_filter($rows, static fn(array $r): bool => $r['key'] !== '')
            )),
        ];
    });
}

/* ── Ability 4 — actions ────────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-actions', [
    'label'       => __('Ninja Forms — Actions', domain: 'nibwp'),
    'description' => __('Read what a form does on submit — email, save, redirect, success message — and enable or disable one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'get', 'toggle'], 'default' => 'list'],
            'form_id'   => ['type' => 'integer'],
            'action_id' => ['type' => 'integer', 'description' => 'The action ID, as returned by list.'],
            'enabled'   => ['type' => 'boolean', 'description' => 'toggle: whether the action runs.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_actions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This is the whole behavior of a Ninja Forms form. Two action types matter more than the rest: `email` decides whether anyone is told, and `save` decides whether the submission is kept at all.',
                'Disabling the save action does not warn anyone in Ninja Forms. The form keeps working and quietly stops recording.',
                'Only toggling is offered for writes — each action type has its own settings shape, and a wrong shape is stored and then silently does nothing. Configure those in the Ninja Forms builder.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_nf_actions(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_nf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_nf_try(static function () use ($form, $form_id, $input) {
        $action = (string) ($input['action'] ?? 'list');
        $summary = nibwp_nf_action_summary($form_id);

        if ($action === 'list') {
            $warnings = [];
            if (!$summary['has_email']) {
                $warnings[] = __('No active email action — nobody is told about a submission.', domain: 'nibwp');
            }
            if (!$summary['has_save']) {
                $warnings[] = __('No active save action — submissions are not stored anywhere.', domain: 'nibwp');
            }

            return [
                'form_id' => $form_id,
                'actions' => $summary['actions'],
                'count'   => count($summary['actions']),
                'emails_someone' => $summary['has_email'],
                'stores_submissions' => $summary['has_save'],
                'warnings' => $warnings,
            ];
        }

        $action_id = (int) ($input['action_id'] ?? 0);
        if ($action_id <= 0) {
            throw new \RuntimeException(__('An action_id is required. List them first.', domain: 'nibwp'));
        }

        $match = null;
        foreach ($summary['actions'] as $candidate) {
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
        $model = Ninja_Forms()->form($form_id)->get_action($action_id);

        if (!is_object($model) || !method_exists($model, 'update_setting')) {
            throw new \RuntimeException(__('This Ninja Forms version does not expose updating an action.', domain: 'nibwp'));
        }

        $model->update_setting('active', $enabled ? 1 : 0);
        $model->save();

        // Disabling the save action is the one change here that loses data
        // from that moment on, so it is called out rather than left implicit.
        $warning = '';
        if (!$enabled && $match['type'] === 'save') {
            $warning = __('The save action is now off. Submissions from this point are not stored anywhere.', domain: 'nibwp');
        } elseif (!$enabled && $match['type'] === 'email') {
            $warning = __('The email action is now off. Nobody will be told about new submissions.', domain: 'nibwp');
        }

        return ['form_id' => $form_id, 'action_id' => $action_id, 'active' => $enabled, 'warning' => $warning];
    });
}

/* ── Ability 5 — submissions ────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-submissions', [
    'label'       => __('Ninja Forms — Submissions', domain: 'nibwp'),
    'description' => __('List and read the submissions a form has stored (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'count'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer'],
            'sub_id'   => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_submissions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'An empty result does not mean no submissions arrived. If the form has no active save action, none were ever recorded — check nibwp/ninjaforms-actions before drawing a conclusion.',
                'Submissions are personal data. Read what the task needs and no more.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_nf_submissions(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_nf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_nf_try(static function () use ($form, $form_id, $input) {
        if (!method_exists($form, 'get_subs')) {
            throw new \RuntimeException(__('This Ninja Forms version does not expose submissions.', domain: 'nibwp'));
        }

        $subs = (array) $form->get_subs();
        $summary = nibwp_nf_action_summary($form_id);
        $action = (string) ($input['action'] ?? 'list');

        // The distinction that matters: nothing stored, versus nothing
        // arrived. Only the actions can tell them apart.
        $context = ($subs === [] && !$summary['has_save'])
            ? __('This form has no active save action, so submissions were never recorded. An empty list here is not evidence that none arrived.', domain: 'nibwp')
            : '';

        if ($action === 'count') {
            return ['form_id' => $form_id, 'total' => count($subs), 'stores_submissions' => $summary['has_save'], 'note' => $context];
        }

        if ($action === 'get') {
            $sub_id = (int) ($input['sub_id'] ?? 0);
            if ($sub_id <= 0) {
                throw new \RuntimeException(__('A valid submission ID is required.', domain: 'nibwp'));
            }

            foreach ($subs as $sub) {
                $id = method_exists($sub, 'get_id') ? (int) $sub->get_id() : 0;
                if ($id === $sub_id) {
                    return [
                        'form_id' => $form_id,
                        'sub_id'  => $sub_id,
                        'values'  => method_exists($sub, 'get_field_values') ? $sub->get_field_values() : null,
                    ];
                }
            }

            throw new \RuntimeException(__('No submission with that ID on this form.', domain: 'nibwp'));
        }

        $page = nibwp_nf_paginate($input);
        $slice = array_slice($subs, ($page['page'] - 1) * $page['per_page'], $page['per_page']);

        $rows = [];
        foreach ($slice as $sub) {
            $rows[] = [
                'id'     => method_exists($sub, 'get_id') ? (int) $sub->get_id() : null,
                'values' => method_exists($sub, 'get_field_values') ? $sub->get_field_values() : null,
            ];
        }

        return [
            'form_id'     => $form_id,
            'submissions' => $rows,
            'count'       => count($rows),
            'total'       => count($subs),
            'stores_submissions' => $summary['has_save'],
            'note'        => $context,
        ];
    });
}

/* ── Ability 6 — settings ───────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-settings', [
    'label'       => __('Ninja Forms — Form settings', domain: 'nibwp'),
    'description' => __('Read a form\'s settings, and change one by key.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id' => ['type' => 'integer'],
            'key'     => ['type' => 'string', 'description' => 'set: the setting to change.'],
            'value'   => ['description' => 'set: the new value.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_settings_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'One key at a time on purpose. Ninja Forms settings drive the builder\'s own state, and writing an object wholesale is how a form ends up with settings the builder disagrees with.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_nf_settings_ability(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_nf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_nf_try(static function () use ($form, $form_id, $input) {
        if ((string) ($input['action'] ?? 'get') === 'set') {
            $key = (string) ($input['key'] ?? '');
            if ($key === '') {
                throw new \RuntimeException(__('A setting key is required.', domain: 'nibwp'));
            }
            if (!array_key_exists('value', $input)) {
                throw new \RuntimeException(__('A value is required.', domain: 'nibwp'));
            }
            $model = nibwp_nf_model($form_id);
            if ($model === null || !method_exists($model, 'update_setting')) {
                throw new \RuntimeException(__('This Ninja Forms version does not expose updating a form setting.', domain: 'nibwp'));
            }

            $before = nibwp_nf_settings($model)[$key] ?? null;
            $model->update_setting($key, $input['value']);
            $model->save();

            return ['form_id' => $form_id, 'key' => $key, 'before' => $before, 'after' => $input['value'], 'updated' => true];
        }

        return ['form_id' => $form_id, 'settings' => nibwp_nf_settings(nibwp_nf_model($form_id))];
    });
}

/* ── Ability 7 — export ─────────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-export', [
    'label'       => __('Ninja Forms — Export submissions', domain: 'nibwp'),
    'description' => __('Export a form\'s stored submissions as rows or CSV, with field labels as headers (read-only).', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_nf_export',
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

function nibwp_nf_export(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_nf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_nf_try(static function () use ($form, $form_id, $input) {
        $labels = [];
        if (method_exists($form, 'get_fields')) {
            foreach ((array) $form->get_fields() as $field) {
                $settings = nibwp_nf_settings($field);
                $key = (string) ($settings['key'] ?? '');
                if ($key !== '') {
                    $labels[$key] = (string) ($settings['label'] ?? $key);
                }
            }
        }

        $subs = method_exists($form, 'get_subs') ? (array) $form->get_subs() : [];
        $page = nibwp_nf_paginate($input);
        $slice = array_slice($subs, ($page['page'] - 1) * $page['per_page'], $page['per_page']);

        $rows = [];
        foreach ($slice as $sub) {
            $values = method_exists($sub, 'get_field_values') ? (array) $sub->get_field_values() : [];
            $record = ['Submission ID' => method_exists($sub, 'get_id') ? (int) $sub->get_id() : null];

            foreach ($labels as $key => $label) {
                $value = $values[$key] ?? '';
                $record[$label] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }

            $rows[] = $record;
        }

        $columns = array_merge(['Submission ID'], array_values($labels));

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

/* ── Ability 8 — audit ──────────────────────────────────────────────── */

wp_register_ability('nibwp/ninjaforms-audit', [
    'label'       => __('Ninja Forms — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the two ways a Ninja Forms form loses data: no email action, and no save action (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Ninja Forms is the only form plugin here where a form can be misconfigured into losing data in two independent ways. A form with neither an email nor a save action submits, thanks the visitor, and the enquiry ceases to exist.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_nf_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    return nibwp_nf_try(static function () use ($input): array {
        $form_id = (int) ($input['form_id'] ?? 0);

        if ($form_id > 0) {
            $one = nibwp_nf_form($form_id);
            $forms = $one instanceof WP_Error ? [] : [$one];
        } else {
            $forms = Ninja_Forms()->form()->get_forms();
            $forms = is_array($forms) ? $forms : [];
        }

        $findings = [];

        foreach ($forms as $form) {
            $id = method_exists($form, 'get_id') ? (int) $form->get_id() : 0;
            if ($id <= 0) {
                continue;
            }

            $settings = nibwp_nf_settings($form);
            $title = (string) ($settings['title'] ?? '');
            $summary = nibwp_nf_action_summary($id);

            if (!$summary['has_email'] && !$summary['has_save']) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'blocker',
                    'code'     => 'no_email_no_save',
                    'message'  => __('Neither an email nor a save action. Every submission is acknowledged and then lost with no record anywhere.', domain: 'nibwp'),
                    'fix'      => __('Add an email action, a save action, or both, in the Ninja Forms builder.', domain: 'nibwp'),
                ];
            } elseif (!$summary['has_save']) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_save_action',
                    'message'  => __('No save action. Submissions are emailed but never stored, so there is no record to go back to.', domain: 'nibwp'),
                    'fix'      => __('Add a save action if a history is wanted.', domain: 'nibwp'),
                ];
            } elseif (!$summary['has_email']) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_email_action',
                    'message'  => __('No email action. Submissions are stored but nobody is told, so they accumulate unread.', domain: 'nibwp'),
                    'fix'      => __('Add an email action, or confirm the entries are only read in the admin.', domain: 'nibwp'),
                ];
            }

            if (method_exists($form, 'get_fields') && (array) $form->get_fields() === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('The form has no fields, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add fields in the Ninja Forms builder.', domain: 'nibwp'),
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

wp_register_ability('nibwp/ninjaforms-delete', [
    'label'       => __('Ninja Forms — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete a form or a submission. Irreversible.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['delete_form', 'delete_submission']],
            'form_id' => ['type' => 'integer'],
            'sub_id'  => ['type' => 'integer'],
            'confirm' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_nf_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Deleting a form takes its actions and every stored submission with it. Ninja Forms has no trash for forms, so this is final.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_nf_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_nf_guard()) {
        return $guard;
    }

    if (!(bool) ($input['confirm'] ?? false)) {
        return nibwp_nf_err(
            'nibwp_nf_unconfirmed',
            __('This permanently destroys data and cannot be undone — Ninja Forms has no trash for forms. Re-issue with confirm true if that is intended.', domain: 'nibwp')
        );
    }

    return nibwp_nf_try(static function () use ($input) {
        $action = (string) ($input['action'] ?? '');

        if ($action === 'delete_form') {
            $form_id = (int) ($input['form_id'] ?? 0);
            $form = nibwp_nf_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            $subs = method_exists($form, 'get_subs') ? count((array) $form->get_subs()) : 0;

            // delete() lives on the model, reached through the factory's
            // __call proxy — and that proxy wraps arguments in an array, so it
            // is called bare against the factory already bound to this form.
            Ninja_Forms()->form($form_id)->delete();

            return ['form_id' => $form_id, 'deleted' => true, 'submissions_deleted' => $subs, 'reversible' => false];
        }

        if ($action === 'delete_submission') {
            $sub_id = (int) ($input['sub_id'] ?? 0);
            if ($sub_id <= 0) {
                throw new \RuntimeException(__('A valid submission ID is required.', domain: 'nibwp'));
            }

            // Submissions are nf_sub posts, so this is the reliable path
            // across versions rather than a model method that has moved.
            wp_delete_post($sub_id, true);

            return ['sub_id' => $sub_id, 'deleted' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
