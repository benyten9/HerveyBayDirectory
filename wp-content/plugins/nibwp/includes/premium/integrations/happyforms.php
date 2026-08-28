<?php

declare(strict_types=1);

/**
 * HappyForms integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Eight abilities: forms, parts, messages, settings, an audit and deletion,
 * plus info and export.
 *
 * HappyForms calls its fields "parts", and they live in the form's post meta
 * rather than as separate rows. Submissions are `happyforms_message` posts,
 * kept only when the form has responses enabled — a form without that stores
 * nothing, which is the same trap WPForms Lite sets and worth reporting the
 * same way.
 *
 * Mechanism is IN-PROCESS through HappyForms' own controllers:
 *   happyforms_get_form_controller()  get / create / update / duplicate / delete
 *                                     get_fields / get_field / to_array
 *   happyforms_get_message_controller() for stored submissions where available
 *
 * Detection: HAPPYFORMS_VERSION + happyforms_get_form_controller().
 *
 * Verified against HappyForms 1.26.15 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The post type HappyForms registers for forms. */
const NIBWP_HF_POST_TYPE = 'happyform';

/** Is HappyForms active? */
function nibwp_hf_available(): bool
{
    return defined('HAPPYFORMS_VERSION') && function_exists('happyforms_get_form_controller');
}

/** House WP_Error wrapper. */
function nibwp_hf_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_hf_guard(): ?WP_Error
{
    if (!nibwp_hf_available()) {
        return nibwp_hf_err('nibwp_hf_missing', __('HappyForms is not active on this site.', domain: 'nibwp'));
    }

    return null;
}

/** Run a HappyForms call, converting throwables into WP_Error. */
function nibwp_hf_try(callable $fn, string $code = 'nibwp_hf_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_hf_err($code, $e->getMessage());
    }
}

/** The form controller. */
function nibwp_hf_controller()
{
    return function_exists('happyforms_get_form_controller') ? happyforms_get_form_controller() : null;
}

/**
 * Load a form as an array.
 *
 * The controller's get() returns a WP_Post; to_array() is what turns it into
 * the structure carrying the parts and settings.
 *
 * @return array|WP_Error
 */
function nibwp_hf_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_hf_err('nibwp_hf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $controller = nibwp_hf_controller();
    if ($controller === null) {
        return nibwp_hf_err('nibwp_hf_no_controller', __('The HappyForms form controller is unavailable.', domain: 'nibwp'));
    }

    $post = $controller->get($form_id);

    if (!$post) {
        return nibwp_hf_err('nibwp_hf_not_found', __('No HappyForms form with that ID.', domain: 'nibwp'));
    }

    $form = method_exists($controller, 'to_array') ? $controller->to_array($post) : (array) $post;

    return is_array($form) ? $form : (array) $post;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_hf_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/** Does this form keep its submissions? */
function nibwp_hf_stores_responses(array $form): bool
{
    // HappyForms names this differently across versions, so both are checked
    // rather than assuming one and reporting "stores nothing" incorrectly.
    foreach (['receive_email_alerts', 'store_responses', 'responses'] as $key) {
        if (array_key_exists($key, $form)) {
            return (bool) $form[$key];
        }
    }

    return true;
}

/* ── Ability 1 — info ───────────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-info', [
    'label'       => __('HappyForms — Info', domain: 'nibwp'),
    'description' => __('Detect HappyForms, its version and how many forms and stored submissions exist (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_hf_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Call this first. HappyForms calls fields "parts", and stores submissions as happyforms_message posts only when a form is set to keep them.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_hf_info(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    return nibwp_hf_try(static function (): array {
        $forms = wp_count_posts(NIBWP_HF_POST_TYPE);
        $messages = post_type_exists('happyforms_message') ? wp_count_posts('happyforms_message') : null;

        return [
            'active'      => true,
            'version'     => defined('HAPPYFORMS_VERSION') ? HAPPYFORMS_VERSION : '',
            'post_type'   => NIBWP_HF_POST_TYPE,
            'form_count'  => (int) ($forms->publish ?? 0),
            'stored_submissions' => $messages === null ? null : (int) ($messages->publish ?? 0),
            'note' => __('Fields are called "parts" in HappyForms. Submissions are only kept for forms configured to store them.', domain: 'nibwp'),
        ];
    });
}

/* ── Ability 2 — forms ──────────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-forms', [
    'label'       => __('HappyForms — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, rename and duplicate HappyForms forms, and get the shortcode for embedding one.', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_hf_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'get reports the parts and whether the form keeps its submissions — a form that neither emails nor stores is one whose enquiries vanish.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_hf_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'rename', 'duplicate', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_hf_err('nibwp_hf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_hf_try(static function () use ($action, $form_id, $input) {
        $controller = nibwp_hf_controller();

        if ($action === 'list') {
            $page = nibwp_hf_paginate($input);
            $posts = get_posts([
                'post_type'      => NIBWP_HF_POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => $page['per_page'],
                'paged'          => $page['page'],
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            $rows = [];
            foreach ($posts as $post) {
                $rows[] = [
                    'id'        => (int) $post->ID,
                    'title'     => $post->post_title,
                    'status'    => $post->post_status,
                    'shortcode' => sprintf('[happyforms id="%d"]', (int) $post->ID),
                ];
            }

            return ['forms' => $rows, 'count' => count($rows)];
        }

        if ($action === 'create') {
            if (!method_exists($controller, 'create')) {
                throw new \RuntimeException(__('This HappyForms version does not expose creating a form.', domain: 'nibwp'));
            }

            $new_id = $controller->create(['post_title' => (string) ($input['title'] ?? __('Untitled form', domain: 'nibwp'))]);

            return ['form_id' => (int) $new_id, 'created' => true];
        }

        $form = nibwp_hf_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        switch ($action) {
            case 'get':
                $parts = (array) ($form['parts'] ?? []);

                return [
                    'form_id'    => $form_id,
                    'title'      => (string) ($form['post_title'] ?? ''),
                    'part_count' => count($parts),
                    'parts'      => array_map(static fn($part): array => [
                        'id'    => (string) ((array) $part)['id'] ?? '',
                        'type'  => (string) ((array) $part)['type'] ?? '',
                        'label' => (string) ((array) $part)['label'] ?? '',
                    ], $parts),
                    'stores_responses' => nibwp_hf_stores_responses($form),
                    'shortcode'  => sprintf('[happyforms id="%d"]', $form_id),
                ];

            case 'rename':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new \RuntimeException(__('A title is required.', domain: 'nibwp'));
                }
                wp_update_post(['ID' => $form_id, 'post_title' => $title]);

                return ['form_id' => $form_id, 'title' => $title, 'renamed' => true];

            case 'duplicate':
                if (!method_exists($controller, 'duplicate')) {
                    throw new \RuntimeException(__('This HappyForms version does not expose duplicating a form.', domain: 'nibwp'));
                }
                $new_id = $controller->duplicate($form_id);

                return ['form_id' => (int) $new_id, 'duplicated_from' => $form_id];

            case 'shortcode':
                return ['form_id' => $form_id, 'shortcode' => sprintf('[happyforms id="%d"]', $form_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ── Ability 3 — parts ──────────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-parts', [
    'label'       => __('HappyForms — Parts', domain: 'nibwp'),
    'description' => __('Read the parts of a form — what other plugins would call its fields (read-only).', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_hf_parts',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'HappyForms calls fields "parts". Their IDs are what stored submissions key on.',
                'Read-only: parts carry per-type validation and layout state that the customizer maintains, and writing them from outside produces forms the customizer then repairs unpredictably. Edit parts in HappyForms; read them here.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_hf_parts(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_hf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_hf_try(static function () use ($form_id, $form): array {
        $parts = (array) ($form['parts'] ?? []);

        $rows = [];
        foreach ($parts as $part) {
            $part = (array) $part;
            $rows[] = [
                'id'       => (string) ($part['id'] ?? ''),
                'type'     => (string) ($part['type'] ?? ''),
                'label'    => (string) ($part['label'] ?? ''),
                'required' => !empty($part['required']),
            ];
        }

        return ['form_id' => $form_id, 'parts' => $rows, 'count' => count($rows)];
    });
}

/* ── Ability 4 — settings ───────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-settings', [
    'label'       => __('HappyForms — Settings', domain: 'nibwp'),
    'description' => __('Read a form\'s settings, including where its email alerts go and whether submissions are stored.', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_hf_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only. HappyForms validates a form as a whole through its customizer, and writing individual settings from outside can leave a form in a state the customizer disagrees with. Report what is wrong; let the user fix it there.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_hf_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_hf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_hf_try(static function () use ($form_id, $form): array {
        // The parts array is the bulk of a form and has its own ability, so it
        // is summarised rather than repeated here.
        $settings = $form;
        unset($settings['parts']);

        $recipients = $form['email_recipients'] ?? ($form['recipients'] ?? null);

        return [
            'form_id'  => $form_id,
            'settings' => $settings,
            'email_recipients' => $recipients,
            'stores_responses' => nibwp_hf_stores_responses($form),
            'warning'  => (empty($recipients) && !nibwp_hf_stores_responses($form))
                ? __('This form neither emails anyone nor stores its submissions. Anything sent through it is lost.', domain: 'nibwp')
                : '',
        ];
    });
}

/* ── Ability 5 — submissions ────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-submissions', [
    'label'       => __('HappyForms — Submissions', domain: 'nibwp'),
    'description' => __('List and read the submissions HappyForms has stored (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer', 'description' => 'Filter to one form.'],
            'submission_id' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_hf_submissions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Only forms set to keep their submissions have any. An empty result may mean the form was never storing them rather than that none arrived — check nibwp/happyforms-settings before concluding anything.',
                'Submissions are personal data. Read what the task needs and no more.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_hf_submissions(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    if (!post_type_exists('happyforms_message')) {
        return nibwp_hf_err(
            'nibwp_hf_no_storage',
            __('HappyForms is not storing submissions on this site, so there is no history to read.', domain: 'nibwp')
        );
    }

    return nibwp_hf_try(static function () use ($input) {
        $action = (string) ($input['action'] ?? 'list');

        if ($action === 'get') {
            $id = (int) ($input['submission_id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException(__('A valid submission ID is required.', domain: 'nibwp'));
            }

            $post = get_post($id);
            if (!$post || $post->post_type !== 'happyforms_message') {
                throw new \RuntimeException(__('No such submission.', domain: 'nibwp'));
            }

            return [
                'submission_id' => $id,
                'date'    => $post->post_date_gmt,
                'form_id' => (int) get_post_meta($id, 'happyforms_form_id', true),
                'values'  => get_post_meta($id),
            ];
        }

        $page = nibwp_hf_paginate($input);
        $form_id = (int) ($input['form_id'] ?? 0);

        $args = [
            'post_type'      => 'happyforms_message',
            'post_status'    => 'any',
            'posts_per_page' => $page['per_page'],
            'paged'          => $page['page'],
        ];

        if ($form_id > 0) {
            $args['meta_query'] = [['key' => 'happyforms_form_id', 'value' => $form_id]];
        }

        $query = new WP_Query($args);

        $rows = [];
        foreach ($query->posts as $post) {
            $rows[] = [
                'id'      => (int) $post->ID,
                'date'    => $post->post_date_gmt,
                'form_id' => (int) get_post_meta((int) $post->ID, 'happyforms_form_id', true),
            ];
        }

        return ['submissions' => $rows, 'count' => count($rows), 'total' => (int) $query->found_posts];
    });
}

/* ── Ability 6 — audit ──────────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-audit', [
    'label'       => __('HappyForms — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the fault that loses enquiries outright: neither emailing anyone nor storing what is submitted (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_hf_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'The worst case here is a form that neither emails nor stores. It submits, thanks the visitor, and the enquiry is simply gone — with nothing anywhere to show it ever arrived.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_hf_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    return nibwp_hf_try(static function () use ($input): array {
        $form_id = (int) ($input['form_id'] ?? 0);

        $posts = $form_id > 0
            ? array_filter([get_post($form_id)], static fn($p): bool => $p && $p->post_type === NIBWP_HF_POST_TYPE)
            : get_posts(['post_type' => NIBWP_HF_POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 200]);

        $findings = [];

        foreach ($posts as $post) {
            $id = (int) $post->ID;
            $form = nibwp_hf_form($id);
            if ($form instanceof WP_Error) {
                continue;
            }

            $recipients = $form['email_recipients'] ?? ($form['recipients'] ?? null);
            $stores = nibwp_hf_stores_responses($form);

            if (empty($recipients) && !$stores) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => (string) $post->post_title,
                    'severity' => 'blocker',
                    'code'     => 'no_email_no_storage',
                    'message'  => __('This form neither emails anyone nor stores submissions. Every enquiry sent through it is lost with no record anywhere.', domain: 'nibwp'),
                    'fix'      => __('Set an email recipient or enable response storage in the HappyForms customizer.', domain: 'nibwp'),
                ];
            } elseif (empty($recipients)) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => (string) $post->post_title,
                    'severity' => 'warning',
                    'code'     => 'no_email_recipient',
                    'message'  => __('No email recipient. Submissions are stored but nobody is notified.', domain: 'nibwp'),
                    'fix'      => __('Add a recipient in the HappyForms customizer.', domain: 'nibwp'),
                ];
            }

            if ((array) ($form['parts'] ?? []) === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => (string) $post->post_title,
                    'severity' => 'warning',
                    'code'     => 'no_parts',
                    'message'  => __('The form has no parts, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add parts in the HappyForms customizer.', domain: 'nibwp'),
                ];
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'forms_checked' => count($posts),
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
        ];
    });
}

/* ── Ability 7 — delete ─────────────────────────────────────────────── */

wp_register_ability('nibwp/happyforms-delete', [
    'label'       => __('HappyForms — Delete', domain: 'nibwp'),
    'description' => __('Trash or permanently delete a HappyForms form.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['trash', 'delete']],
            'form_id' => ['type' => 'integer'],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Required for delete. Trashing does not need it.'],
        ],
        'required' => ['action', 'form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_hf_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Prefer trash — it is reversible. Any page embedding a deleted form renders nothing afterwards.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_hf_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_hf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $form = nibwp_hf_form($form_id);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'delete' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_hf_err(
            'nibwp_hf_unconfirmed',
            __('This permanently destroys the form. Trashing is reversible; re-issue with confirm true if deletion is intended.', domain: 'nibwp')
        );
    }

    return nibwp_hf_try(static function () use ($action, $form_id) {
        if ($action === 'trash') {
            wp_trash_post($form_id);

            return ['form_id' => $form_id, 'trashed' => true, 'reversible' => true];
        }

        wp_delete_post($form_id, true);

        return ['form_id' => $form_id, 'deleted' => true, 'reversible' => false];
    });
}
