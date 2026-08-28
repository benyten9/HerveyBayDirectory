<?php

declare(strict_types=1);

/**
 * JetFormBuilder integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Ten abilities cover JetFormBuilder: forms, fields, post-submit actions,
 * messages, settings, restrictions, gateways, records, an audit and deletion.
 *
 * HOW JETFORMBUILDER STORES A FORM, and why this is unlike every other form
 * plugin here: the form itself is Gutenberg block markup in post_content of a
 * `jet-form-builder` post. Fields are blocks, not rows in a JSON array. And
 * everything the form DOES lives in separate post meta keys, all prefixed
 * `_jf_`:
 *
 *   _jf_actions           what happens on submit — the important one
 *   _jf_messages          success and error text
 *   _jf_args              general settings
 *   _jf_gateways          payment configuration
 *   _jf_recaptcha         spam protection
 *   _jf_validation        validation behavior
 *   _jf_limit_responses   submission cap
 *   _jf_schedule_form     open/close dates
 *   _jf_preset            prefill sources
 *   _jf_save_progress     resumable submissions
 *
 * `_jf_actions` is the one that matters most. A JetFormBuilder form with no
 * actions parses, renders, accepts a submission and then does absolutely
 * nothing with it — no email, no post created, no record kept. It is the
 * plugin's own equivalent of a form with no notification, and it is the
 * default state of every newly created form.
 *
 * Fields are read by parsing blocks, because that is what they are. Names come
 * from each block's `name` attribute, which is what actions and records key on.
 *
 * Detection: JET_FORM_BUILDER_VERSION.
 *
 * Verified against JetFormBuilder 3.6.5.1 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** The post type JetFormBuilder registers. */
const NIBWP_JFB_POST_TYPE = 'jet-form-builder';

/** Is JetFormBuilder active? */
function nibwp_jfb_available(): bool
{
    return defined('JET_FORM_BUILDER_VERSION') && post_type_exists(NIBWP_JFB_POST_TYPE);
}

/** House WP_Error wrapper. */
function nibwp_jfb_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_jfb_guard(): ?WP_Error
{
    if (!nibwp_jfb_available()) {
        return nibwp_jfb_err('nibwp_jfb_missing', __('JetFormBuilder is not active on this site.', domain: 'nibwp'));
    }

    return null;
}

/** Run a call, converting throwables into WP_Error. */
function nibwp_jfb_try(callable $fn, string $code = 'nibwp_jfb_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_jfb_err($code, $e->getMessage());
    }
}

/**
 * Load a form post.
 *
 * @return \WP_Post|WP_Error
 */
function nibwp_jfb_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_jfb_err('nibwp_jfb_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $post = get_post($form_id);

    if (!$post || $post->post_type !== NIBWP_JFB_POST_TYPE) {
        return nibwp_jfb_err('nibwp_jfb_not_found', __('No JetFormBuilder form with that ID.', domain: 'nibwp'));
    }

    return $post;
}

/** Every `_jf_` meta key JetFormBuilder uses, so a write cannot invent one. */
function nibwp_jfb_meta_keys(): array
{
    return [
        '_jf_actions', '_jf_messages', '_jf_args', '_jf_gateways', '_jf_recaptcha',
        '_jf_validation', '_jf_limit_responses', '_jf_limit_responses_counters',
        '_jf_schedule_form', '_jf_preset', '_jf_save_progress', '_jf_address_autocomplete',
    ];
}

/**
 * Read one `_jf_` meta value, decoded.
 *
 * JetFormBuilder stores these as JSON strings in some versions and as arrays in
 * others, so both are handled rather than assuming one.
 *
 * @return mixed
 */
function nibwp_jfb_meta(int $form_id, string $key)
{
    $raw = get_post_meta($form_id, $key, true);

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);

        return $decoded === null ? $raw : $decoded;
    }

    return $raw;
}

/**
 * Write one `_jf_` meta value.
 *
 * Stored as JSON when the existing value was a JSON string, and as an array
 * when it was an array — writing the wrong type is how JetFormBuilder's editor
 * starts showing an empty panel for settings that are actually there.
 *
 * @return true|WP_Error
 */
function nibwp_jfb_meta_put(int $form_id, string $key, $value)
{
    if (!in_array($key, nibwp_jfb_meta_keys(), strict: true)) {
        return nibwp_jfb_err(
            'nibwp_jfb_bad_key',
            sprintf(
                /* translators: 1: the key given, 2: the keys JetFormBuilder uses */
                __('"%1$s" is not a JetFormBuilder meta key. Known keys: %2$s', domain: 'nibwp'),
                $key,
                implode(', ', nibwp_jfb_meta_keys())
            )
        );
    }

    $existing = get_post_meta($form_id, $key, true);
    $store_as_json = is_string($existing) && $existing !== '';

    update_post_meta($form_id, $key, $store_as_json ? (string) wp_json_encode($value) : $value);

    return true;
}

/**
 * Parse a form's fields out of its block markup.
 *
 * JetFormBuilder fields ARE blocks, so this walks the parsed block tree rather
 * than reading a field array. The `name` attribute is what actions, records and
 * conditional logic all key on, so a field without one is not usable and is
 * reported as such rather than silently skipped.
 */
function nibwp_jfb_fields(int $form_id): array
{
    $post = get_post($form_id);
    if (!$post) {
        return [];
    }

    $blocks = parse_blocks((string) $post->post_content);
    $fields = [];

    $walk = static function (array $blocks) use (&$walk, &$fields): void {
        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');

            if (str_starts_with($name, 'jet-forms/')) {
                $attrs = (array) ($block['attrs'] ?? []);
                $field_name = (string) ($attrs['name'] ?? '');
                $type = substr($name, strlen('jet-forms/'));

                // Layout blocks carry no name because they hold nothing; only
                // an input block missing one is a problem worth reporting.
                $is_layout = in_array($type, ['form-break', 'conditional-block', 'repeater-field', 'column', 'row', 'group-break'], strict: true);

                if ($field_name !== '' || !$is_layout) {
                    $fields[] = [
                        'name'     => $field_name,
                        'type'     => $type,
                        'label'    => (string) ($attrs['label'] ?? ''),
                        'required' => !empty($attrs['required']),
                        'usable'   => $field_name !== '',
                    ];
                }
            }

            if (!empty($block['innerBlocks'])) {
                $walk((array) $block['innerBlocks']);
            }
        }
    };

    $walk($blocks);

    return $fields;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_jfb_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/jetformbuilder-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-info', [
    'label'       => __('JetFormBuilder — Info', domain: 'nibwp'),
    'description' => __('Detect JetFormBuilder, its version, how many forms exist and whether records are being stored (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_jfb_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first.',
                'JetFormBuilder is unlike the other form plugins here: the form is Gutenberg blocks, and everything it DOES lives in _jf_ post meta. A form with no actions submits successfully and does nothing at all.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_jfb_info(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    return nibwp_jfb_try(static function (): array {
        $counts = wp_count_posts(NIBWP_JFB_POST_TYPE);

        return [
            'active'     => true,
            'version'    => defined('JET_FORM_BUILDER_VERSION') ? JET_FORM_BUILDER_VERSION : '',
            'post_type'  => NIBWP_JFB_POST_TYPE,
            'form_count' => (int) ($counts->publish ?? 0),
            'meta_keys'  => nibwp_jfb_meta_keys(),
            'note'       => __('Fields are blocks in post_content; behavior lives in _jf_ post meta. Read nibwp/jetformbuilder-actions before assuming a form does anything on submit.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/jetformbuilder-forms (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-forms', [
    'label'       => __('JetFormBuilder — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, rename and duplicate JetFormBuilder forms, and get the block or shortcode for embedding one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'rename', 'duplicate', 'embed'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string', 'description' => 'create: the form as block markup. Omit for an empty form.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_jfb_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A form is Gutenberg block markup. Fields are blocks named jet-forms/text-field, jet-forms/select-field and so on, each with a `name` attribute — that name is what actions and records key on.',
                'duplicate copies the meta too, because a copy without its actions is a form that does nothing.',
                'A newly created form has no actions. It will submit and discard the data until one is added.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_jfb_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'rename', 'duplicate', 'embed'], strict: true) && $form_id <= 0) {
        return nibwp_jfb_err('nibwp_jfb_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_jfb_try(static function () use ($action, $form_id, $input) {
        if ($action === 'list') {
            $page = nibwp_jfb_paginate($input);
            $posts = get_posts([
                'post_type'      => NIBWP_JFB_POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => $page['per_page'],
                'paged'          => $page['page'],
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);

            $rows = [];
            foreach ($posts as $post) {
                $actions = nibwp_jfb_meta((int) $post->ID, '_jf_actions');
                $rows[] = [
                    'id'      => (int) $post->ID,
                    'title'   => $post->post_title,
                    'status'  => $post->post_status,
                    'actions' => is_array($actions) ? count($actions) : 0,
                ];
            }

            return ['forms' => $rows, 'count' => count($rows)];
        }

        if ($action === 'create') {
            $new_id = wp_insert_post([
                'post_type'    => NIBWP_JFB_POST_TYPE,
                'post_title'   => (string) ($input['title'] ?? __('Untitled form', domain: 'nibwp')),
                'post_status'  => 'publish',
                'post_content' => (string) ($input['content'] ?? ''),
            ], true);

            if (is_wp_error($new_id)) {
                return $new_id;
            }

            return [
                'form_id' => (int) $new_id,
                'created' => true,
                'note'    => __('No actions configured, so this form will submit and discard the data. Add one with nibwp/jetformbuilder-actions.', domain: 'nibwp'),
            ];
        }

        $post = nibwp_jfb_form($form_id);
        if ($post instanceof WP_Error) {
            return $post;
        }

        switch ($action) {
            case 'get':
                $fields = nibwp_jfb_fields($form_id);
                $actions = nibwp_jfb_meta($form_id, '_jf_actions');

                return [
                    'form_id' => $form_id,
                    'title'   => $post->post_title,
                    'status'  => $post->post_status,
                    'fields'  => $fields,
                    'field_count' => count($fields),
                    'unnamed_fields' => count(array_filter($fields, static fn(array $f): bool => !$f['usable'])),
                    'action_count'   => is_array($actions) ? count($actions) : 0,
                ];

            case 'rename':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new \RuntimeException(__('A title is required.', domain: 'nibwp'));
                }
                wp_update_post(['ID' => $form_id, 'post_title' => $title]);

                return ['form_id' => $form_id, 'title' => $title, 'renamed' => true];

            case 'duplicate':
                $new_id = wp_insert_post([
                    'post_type'    => NIBWP_JFB_POST_TYPE,
                    'post_title'   => $post->post_title . ' ' . __('(copy)', domain: 'nibwp'),
                    'post_status'  => $post->post_status,
                    'post_content' => $post->post_content,
                ], true);

                if (is_wp_error($new_id)) {
                    return $new_id;
                }

                // The blocks alone are a form that does nothing. Every _jf_ key
                // has to come with it or the copy is not a copy.
                foreach (nibwp_jfb_meta_keys() as $key) {
                    $value = get_post_meta($form_id, $key, true);
                    if ($value !== '' && $value !== null) {
                        update_post_meta((int) $new_id, $key, $value);
                    }
                }

                return ['form_id' => (int) $new_id, 'duplicated_from' => $form_id, 'meta_copied' => true];

            case 'embed':
                return [
                    'form_id'   => $form_id,
                    'block'     => sprintf('<!-- wp:jet-forms/form-block {"form_id":%d} /-->', $form_id),
                    'shortcode' => sprintf('[jet_fb_form form_id="%d"]', $form_id),
                ];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/jetformbuilder-fields (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-fields', [
    'label'       => __('JetFormBuilder — Fields', domain: 'nibwp'),
    'description' => __('The fields on a form, parsed out of its block markup, with the names that actions and records key on (read-only).', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_jfb_fields_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read-only on purpose. Fields are blocks in post_content, and rewriting block markup by hand produces a form the JetFormBuilder editor cannot open. Edit fields in the editor; read them here.',
                'A field with usable=false has no name attribute, so nothing can reference its value — actions will never see it.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_jfb_fields_ability(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $post = nibwp_jfb_form($form_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    return nibwp_jfb_try(static function () use ($form_id): array {
        $fields = nibwp_jfb_fields($form_id);
        $unusable = array_values(array_filter($fields, static fn(array $f): bool => !$f['usable']));

        return [
            'form_id' => $form_id,
            'fields'  => $fields,
            'count'   => count($fields),
            'names'   => array_values(array_filter(array_column($fields, 'name'))),
            'unusable' => $unusable,
            'warning' => $unusable === []
                ? ''
                : __('Some fields have no name attribute, so no action can read their values.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/jetformbuilder-actions (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-actions', [
    'label'       => __('JetFormBuilder — Post-submit actions', domain: 'nibwp'),
    'description' => __('Read and configure what a form does when it is submitted — send an email, insert a post, register a user, call a webhook, save a record.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'set', 'add', 'delete'], 'default' => 'list'],
            'form_id' => ['type' => 'integer'],
            'actions' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'set: the complete action list, replacing what is there.'],
            'new_action' => ['type' => 'object', 'description' => 'add: one action — type and its settings.'],
            'index'   => ['type' => 'integer', 'description' => 'delete: which action to remove, by position in the list.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_jfb_actions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'THIS is what makes a JetFormBuilder form do anything. With no actions a form submits successfully and discards the data — no email, no post, no record. It is the default state of every new form.',
                'Common types: send_email, insert_post, register_user, call_webhook, save_record, redirect_to_page.',
                'Each action type has its own settings shape. Read an existing action of that type before writing one — a wrong shape is stored and then silently does nothing.',
                'set REPLACES the whole list. Prefer add unless you mean to discard the rest.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_jfb_actions(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $post = nibwp_jfb_form($form_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    return nibwp_jfb_try(static function () use ($form_id, $input) {
        $action = (string) ($input['action'] ?? 'list');
        $current = nibwp_jfb_meta($form_id, '_jf_actions');
        $current = is_array($current) ? $current : [];

        if ($action === 'list') {
            $rows = [];
            foreach ($current as $index => $item) {
                $item = (array) $item;
                $rows[] = [
                    'index'    => $index,
                    'type'     => (string) ($item['type'] ?? ''),
                    'id'       => $item['id'] ?? null,
                    'settings' => $item['settings'] ?? null,
                ];
            }

            return [
                'form_id' => $form_id,
                'actions' => $rows,
                'count'   => count($rows),
                'warning' => $rows === []
                    ? __('This form has no actions. It will accept a submission and do nothing with it — no email, no record, nothing.', domain: 'nibwp')
                    : '',
            ];
        }

        if ($action === 'set') {
            $actions = array_values((array) ($input['actions'] ?? []));
            $result = nibwp_jfb_meta_put($form_id, '_jf_actions', $actions);

            return $result instanceof WP_Error ? $result : ['form_id' => $form_id, 'count' => count($actions), 'updated' => true];
        }

        if ($action === 'add') {
            $new = (array) ($input['new_action'] ?? []);
            if (($new['type'] ?? '') === '') {
                throw new \RuntimeException(__('An action type is required — send_email, insert_post, register_user, call_webhook, and so on.', domain: 'nibwp'));
            }

            $current[] = $new;
            $result = nibwp_jfb_meta_put($form_id, '_jf_actions', array_values($current));

            return $result instanceof WP_Error ? $result : ['form_id' => $form_id, 'index' => count($current) - 1, 'type' => $new['type'], 'created' => true];
        }

        $index = (int) ($input['index'] ?? -1);
        if (!array_key_exists($index, $current)) {
            throw new \RuntimeException(__('No action at that position.', domain: 'nibwp'));
        }

        array_splice($current, $index, 1);
        $result = nibwp_jfb_meta_put($form_id, '_jf_actions', array_values($current));

        return $result instanceof WP_Error ? $result : [
            'form_id' => $form_id,
            'index'   => $index,
            'deleted' => true,
            'warning' => $current === []
                ? __('That was the last action. This form now does nothing on submit.', domain: 'nibwp')
                : '',
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/jetformbuilder-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-settings', [
    'label'       => __('JetFormBuilder — Settings', domain: 'nibwp'),
    'description' => __('Read and change any of a form\'s settings meta: messages, general arguments, validation, spam protection, response limits, scheduling, prefill and save-progress.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'get_all', 'set'], 'default' => 'get_all'],
            'form_id'  => ['type' => 'integer'],
            'meta_key' => ['type' => 'string', 'description' => 'One of the _jf_ keys. Required for get and set.'],
            'value'    => ['description' => 'set: the new value.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_jfb_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get_all first — it shows which settings the form actually carries.',
                'Two of these silently close a form: _jf_limit_responses once the cap is reached, and _jf_schedule_form once the end date passes. Both are easy to leave behind after a campaign.',
                'Only JetFormBuilder\'s own keys are accepted; an invented key is refused rather than written where nothing will read it.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_jfb_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $post = nibwp_jfb_form($form_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    return nibwp_jfb_try(static function () use ($form_id, $input) {
        $action = (string) ($input['action'] ?? 'get_all');
        $key = (string) ($input['meta_key'] ?? '');

        if ($action === 'get_all') {
            $out = [];
            foreach (nibwp_jfb_meta_keys() as $meta_key) {
                $value = nibwp_jfb_meta($form_id, $meta_key);
                if ($value !== '' && $value !== null && $value !== []) {
                    $out[$meta_key] = $value;
                }
            }

            return ['form_id' => $form_id, 'settings' => $out, 'keys_present' => array_keys($out), 'known_keys' => nibwp_jfb_meta_keys()];
        }

        if ($key === '') {
            throw new \RuntimeException(__('A meta_key is required.', domain: 'nibwp'));
        }

        if ($action === 'get') {
            return ['form_id' => $form_id, 'meta_key' => $key, 'value' => nibwp_jfb_meta($form_id, $key)];
        }

        if (!array_key_exists('value', $input)) {
            throw new \RuntimeException(__('A value is required.', domain: 'nibwp'));
        }

        $result = nibwp_jfb_meta_put($form_id, $key, $input['value']);
        if ($result instanceof WP_Error) {
            return $result;
        }

        $gates = [];
        foreach (['_jf_limit_responses', '_jf_schedule_form'] as $gate) {
            $value = nibwp_jfb_meta($form_id, $gate);
            if (is_array($value) && !empty($value['enabled'])) {
                $gates[] = $gate;
            }
        }

        return [
            'form_id'  => $form_id,
            'meta_key' => $key,
            'updated'  => true,
            'submission_gates' => $gates,
            'warning'  => $gates === []
                ? ''
                : __('This form now restricts when or how often it can be submitted. Confirm that is intended.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/jetformbuilder-records (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-records', [
    'label'       => __('JetFormBuilder — Records', domain: 'nibwp'),
    'description' => __('Read stored form submissions, when the form has a save-record action keeping them (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id'  => ['type' => 'integer'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_jfb_records',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'JetFormBuilder only stores a submission when the form has a save-record action. A form without one keeps nothing, and an empty result here means the submissions were never recorded rather than that none arrived.',
                'Check nibwp/jetformbuilder-actions before concluding a form has had no submissions.',
                'Records are personal data. Read what the task needs and no more.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_jfb_records(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    global $wpdb;

    $form_id = (int) ($input['form_id'] ?? 0);
    $page = nibwp_jfb_paginate($input);

    return nibwp_jfb_try(static function () use ($wpdb, $form_id, $input, $page) {
        $table = $wpdb->prefix . 'jet_fb_records';

        // Checked rather than assumed: the records table only exists once
        // JetFormBuilder has created it, and querying a missing table is a
        // database error rather than an empty result.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists !== $table) {
            $has_save_action = false;
            if ($form_id > 0) {
                $actions = nibwp_jfb_meta($form_id, '_jf_actions');
                foreach ((array) $actions as $action) {
                    if (str_contains((string) ((array) $action)['type'] ?? '', 'record')) {
                        $has_save_action = true;
                    }
                }
            }

            return [
                'records' => [],
                'count'   => 0,
                'storage_available' => false,
                'note' => $has_save_action
                    ? __('The form has a save-record action but the records table does not exist yet — it is created on the first stored submission.', domain: 'nibwp')
                    : __('No records table on this site. JetFormBuilder only stores submissions for forms with a save-record action.', domain: 'nibwp'),
            ];
        }

        $offset = ($page['page'] - 1) * $page['per_page'];

        if ($form_id > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $form_id, $page['per_page'], $offset),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $page['per_page'], $offset),
                ARRAY_A
            );
        }

        return [
            'form_id' => $form_id ?: null,
            'records' => is_array($rows) ? $rows : [],
            'count'   => is_array($rows) ? count($rows) : 0,
            'storage_available' => true,
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/jetformbuilder-gateways (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-gateways', [
    'label'       => __('JetFormBuilder — Payment gateways', domain: 'nibwp'),
    'description' => __('Read a form\'s payment gateway configuration (read-only).', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_jfb_gateways',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read-only deliberately. A gateway change alters what a customer is charged, and getting it wrong is a refund conversation rather than a bug report — that belongs in the JetFormBuilder editor with its own confirmations.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_jfb_gateways(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $post = nibwp_jfb_form($form_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    return nibwp_jfb_try(static function () use ($form_id): array {
        $gateways = nibwp_jfb_meta($form_id, '_jf_gateways');

        return [
            'form_id'  => $form_id,
            'gateways' => $gateways === '' ? null : $gateways,
            'configured' => is_array($gateways) && $gateways !== [],
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/jetformbuilder-audit (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-audit', [
    'label'       => __('JetFormBuilder — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the faults that lose submissions: no actions at all, fields with no name, and restrictions that have quietly closed a form (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_jfb_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'The headline check is "no actions". A JetFormBuilder form with none looks completely normal, submits successfully, shows a success message, and throws the data away.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_jfb_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    return nibwp_jfb_try(static function () use ($input): array {
        $form_id = (int) ($input['form_id'] ?? 0);

        $posts = $form_id > 0
            ? array_filter([get_post($form_id)], static fn($p): bool => $p && $p->post_type === NIBWP_JFB_POST_TYPE)
            : get_posts(['post_type' => NIBWP_JFB_POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 200]);

        $findings = [];

        foreach ($posts as $post) {
            $id = (int) $post->ID;
            $title = (string) $post->post_title;

            $actions = nibwp_jfb_meta($id, '_jf_actions');
            $actions = is_array($actions) ? $actions : [];

            if ($actions === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'blocker',
                    'code'     => 'no_actions',
                    'message'  => __('No post-submit actions. This form accepts a submission, shows a success message and discards the data.', domain: 'nibwp'),
                    'fix'      => __('Add one with nibwp/jetformbuilder-actions — send_email at minimum, or save_record to keep a copy.', domain: 'nibwp'),
                ];
            }

            $fields = nibwp_jfb_fields($id);
            $unusable = array_filter($fields, static fn(array $f): bool => !$f['usable']);

            if ($fields === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('No fields found in the block markup, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add fields in the JetFormBuilder editor.', domain: 'nibwp'),
                ];
            } elseif ($unusable !== []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'unnamed_fields',
                    'message'  => sprintf(
                        /* translators: %d: number of fields with no name */
                        __('%d field(s) have no name attribute, so no action can read their values.', domain: 'nibwp'),
                        count($unusable)
                    ),
                    'fix'      => __('Give each field a name in the editor.', domain: 'nibwp'),
                ];
            }

            foreach (['_jf_limit_responses' => 'response limit', '_jf_schedule_form' => 'schedule'] as $key => $label) {
                $value = nibwp_jfb_meta($id, $key);
                if (is_array($value) && !empty($value['enabled'])) {
                    $findings[] = [
                        'form_id'  => $id,
                        'title'    => $title,
                        'severity' => 'note',
                        'code'     => 'restriction_' . ltrim($key, '_'),
                        'message'  => sprintf(
                            /* translators: %s: the restriction name */
                            __('A %s is active on this form, which limits when or how often it accepts submissions.', domain: 'nibwp'),
                            $label
                        ),
                        'fix'      => __('Intentional on a campaign form; a leftover on a contact form.', domain: 'nibwp'),
                    ];
                }
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'forms_checked' => count($posts),
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'notes'    => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'note')),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/jetformbuilder-delete (destructive)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/jetformbuilder-delete', [
    'label'       => __('JetFormBuilder — Delete', domain: 'nibwp'),
    'description' => __('Trash or permanently delete a JetFormBuilder form.', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_jfb_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Prefer trash — it is reversible. Deleting a form takes its actions and settings with it, and any page embedding it renders nothing afterwards.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_jfb_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_jfb_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    $post = nibwp_jfb_form($form_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $action = (string) ($input['action'] ?? '');

    if ($action === 'delete' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_jfb_err(
            'nibwp_jfb_unconfirmed',
            __('This permanently destroys the form along with its actions and settings. Trashing is reversible; re-issue with confirm true if deletion is intended.', domain: 'nibwp')
        );
    }

    return nibwp_jfb_try(static function () use ($action, $form_id) {
        if ($action === 'trash') {
            wp_trash_post($form_id);

            return ['form_id' => $form_id, 'trashed' => true, 'reversible' => true];
        }

        wp_delete_post($form_id, true);

        return ['form_id' => $form_id, 'deleted' => true, 'reversible' => false];
    });
}
