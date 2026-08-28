<?php

declare(strict_types=1);

/**
 * WPForms integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Ten abilities cover WPForms: forms, fields, notifications, confirmations,
 * settings, entries, export, an audit and deletion.
 *
 * THE THING TO KNOW ABOUT WPFORMS LITE: it does not store entries. Lite emails
 * a submission and keeps no copy; the entries database is a Pro feature. So the
 * entry abilities here refuse on Lite with an explanation rather than returning
 * an empty list, because "no entries" and "entries were never stored" look
 * identical to an agent and mean opposite things to a user asking where their
 * enquiries went.
 *
 * HOW WPFORMS STORES A FORM. A `wpforms` post whose post_content is the whole
 * form as JSON: fields keyed by ID, and a `settings` object holding
 * notifications and confirmations, each keyed by a numeric ID. Editing any of
 * it means decoding that JSON, changing the branch, and writing it back — which
 * is what these abilities do, through WPForms' own form handler so its
 * sanitising applies.
 *
 * Mechanism is IN-PROCESS through wpforms()->form, the plugin's own handler:
 *   ->get() / ->add() / ->update() / ->duplicate() / ->update_status()
 *   ->get_field() / ->next_field_id()
 *
 * Detection: wpforms() + WPFORMS_VERSION. Pro gates on WPFORMS_PRO, which is
 * how WPForms distinguishes the two itself.
 *
 * Verified against WPForms Lite 2.0.0.4 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is WPForms active? */
function nibwp_wpf_available(): bool
{
    return function_exists('wpforms') && defined('WPFORMS_VERSION');
}

/** Is the Pro edition active? Entries only exist there. */
function nibwp_wpf_pro(): bool
{
    return defined('WPFORMS_PRO') && WPFORMS_PRO;
}

/** House WP_Error wrapper. */
function nibwp_wpf_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_wpf_guard(): ?WP_Error
{
    if (!nibwp_wpf_available()) {
        return nibwp_wpf_err('nibwp_wpf_missing', __('WPForms is not active on this site.', domain: 'nibwp'));
    }

    return null;
}

/** Run a WPForms call, converting throwables into WP_Error. */
function nibwp_wpf_try(callable $fn, string $code = 'nibwp_wpf_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_wpf_err($code, $e->getMessage());
    }
}

/** The form handler, or null when WPForms has not booted it. */
function nibwp_wpf_handler()
{
    if (!function_exists('wpforms')) {
        return null;
    }

    $wpforms = wpforms();

    return $wpforms->form ?? null;
}

/**
 * Read a form and decode its JSON into a usable array.
 *
 * @return array{post: \WP_Post, data: array}|WP_Error
 */
function nibwp_wpf_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_wpf_err('nibwp_wpf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $handler = nibwp_wpf_handler();
    if ($handler === null) {
        return nibwp_wpf_err('nibwp_wpf_no_handler', __('The WPForms form handler is unavailable.', domain: 'nibwp'));
    }

    $post = $handler->get($form_id);

    if (!$post || !is_object($post)) {
        return nibwp_wpf_err('nibwp_wpf_not_found', __('No WPForms form with that ID.', domain: 'nibwp'));
    }

    $data = json_decode((string) ($post->post_content ?? ''), true);

    return ['post' => $post, 'data' => is_array($data) ? $data : []];
}

/**
 * Write a form's decoded data back.
 *
 * Through the handler's update() rather than wp_update_post, so WPForms
 * sanitises the payload the way it does for its own builder.
 *
 * @return true|WP_Error
 */
function nibwp_wpf_save(int $form_id, array $data)
{
    $handler = nibwp_wpf_handler();
    if ($handler === null) {
        return nibwp_wpf_err('nibwp_wpf_no_handler', __('The WPForms form handler is unavailable.', domain: 'nibwp'));
    }

    $result = $handler->update($form_id, $data);

    return $result ? true : nibwp_wpf_err('nibwp_wpf_save_failed', __('WPForms refused the update.', domain: 'nibwp'));
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_wpf_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/** Fields flattened to id/type/label, the shape everything else keys on. */
function nibwp_wpf_field_rows(array $data): array
{
    $rows = [];

    foreach ((array) ($data['fields'] ?? []) as $id => $field) {
        $field = (array) $field;
        $rows[] = [
            'id'       => (int) ($field['id'] ?? $id),
            'type'     => (string) ($field['type'] ?? ''),
            'label'    => (string) ($field['label'] ?? ''),
            'required' => !empty($field['required']),
        ];
    }

    return $rows;
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/wpforms-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-info', [
    'label'       => __('WPForms — Info', domain: 'nibwp'),
    'description' => __('Detect WPForms, whether it is Lite or Pro, how many forms exist, and whether submissions are being stored at all (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first, and read stores_entries before anything else.',
                'WPForms Lite does NOT store submissions — it emails them and keeps no copy. If someone asks to see past enquiries on a Lite site, the honest answer is that they were never saved, and no tool here can recover them.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpf_info(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    return nibwp_wpf_try(static function (): array {
        $counts = wp_count_posts('wpforms');

        return [
            'active'      => true,
            'version'     => defined('WPFORMS_VERSION') ? WPFORMS_VERSION : '',
            'edition'     => nibwp_wpf_pro() ? 'pro' : 'lite',
            'form_count'  => (int) ($counts->publish ?? 0),
            'stores_entries' => nibwp_wpf_pro(),
            'note' => nibwp_wpf_pro()
                ? ''
                : __('This is WPForms Lite, which does not store submissions. Entries are emailed and not kept, so there is no history to read.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/wpforms-forms (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-forms', [
    'label'       => __('WPForms — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, rename and duplicate WPForms forms, and get the shortcode for embedding one.', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_wpf_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'get returns the fields plus a count of notifications and confirmations, which is the quickest way to see whether a form does anything on submit.',
                'duplicate is the safe way to experiment on a form that is embedded somewhere.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpf_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'rename', 'duplicate', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_wpf_err('nibwp_wpf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_wpf_try(static function () use ($action, $form_id, $input) {
        $handler = nibwp_wpf_handler();
        if ($handler === null) {
            throw new \RuntimeException(__('The WPForms form handler is unavailable.', domain: 'nibwp'));
        }

        if ($action === 'list') {
            $page = nibwp_wpf_paginate($input);
            $forms = $handler->get('', ['posts_per_page' => $page['per_page'], 'paged' => $page['page']]);

            $rows = [];
            foreach ((array) $forms as $form) {
                $id = (int) ($form->ID ?? 0);
                $rows[] = [
                    'id'        => $id,
                    'title'     => (string) ($form->post_title ?? ''),
                    'status'    => (string) ($form->post_status ?? ''),
                    'shortcode' => sprintf('[wpforms id="%d"]', $id),
                ];
            }

            return ['forms' => $rows, 'count' => count($rows)];
        }

        if ($action === 'create') {
            $title = (string) ($input['title'] ?? __('Untitled form', domain: 'nibwp'));
            $new_id = $handler->add($title, [], ['builder' => false]);

            if (!$new_id) {
                throw new \RuntimeException(__('WPForms refused to create the form.', domain: 'nibwp'));
            }

            return [
                'form_id'   => (int) $new_id,
                'created'   => true,
                'shortcode' => sprintf('[wpforms id="%d"]', (int) $new_id),
            ];
        }

        $form = nibwp_wpf_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        switch ($action) {
            case 'get':
                $fields = nibwp_wpf_field_rows($form['data']);

                return [
                    'form_id' => $form_id,
                    'title'   => (string) ($form['data']['settings']['form_title'] ?? ($form['post']->post_title ?? '')),
                    'fields'  => $fields,
                    'field_count' => count($fields),
                    'notifications' => count((array) ($form['data']['settings']['notifications'] ?? [])),
                    'confirmations' => count((array) ($form['data']['settings']['confirmations'] ?? [])),
                    'shortcode' => sprintf('[wpforms id="%d"]', $form_id),
                ];

            case 'rename':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new \RuntimeException(__('A title is required.', domain: 'nibwp'));
                }
                $data = $form['data'];
                $data['settings']['form_title'] = $title;
                $saved = nibwp_wpf_save($form_id, $data);

                return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'title' => $title, 'renamed' => true];

            case 'duplicate':
                $result = $handler->duplicate($form_id);

                return ['form_id' => is_numeric($result) ? (int) $result : null, 'duplicated_from' => $form_id, 'duplicated' => (bool) $result];

            case 'shortcode':
                return ['form_id' => $form_id, 'shortcode' => sprintf('[wpforms id="%d"]', $form_id)];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/wpforms-fields (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-fields', [
    'label'       => __('WPForms — Fields', domain: 'nibwp'),
    'description' => __('List, read, add, update and remove the fields on a form.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete'], 'default' => 'list'],
            'form_id'  => ['type' => 'integer'],
            'field_id' => ['type' => 'integer'],
            'field'    => ['type' => 'object', 'description' => 'add/update: type and label at minimum for add.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Notifications reference fields by ID in smartcodes like {field_id="3"}. Deleting a field leaves those pointing at nothing, and they render empty rather than erroring — so check the notifications after removing one.',
                'add takes the next free ID the way the builder does, and never reuses one.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpf_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    $form = nibwp_wpf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? 'list');
    $field_id = (int) ($input['field_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'delete'], strict: true) && $field_id <= 0) {
        return nibwp_wpf_err('nibwp_wpf_bad_id', __('A valid field ID is required.', domain: 'nibwp'));
    }

    return nibwp_wpf_try(static function () use ($form, $action, $field_id, $input) {
        $form_id = (int) $form['post']->ID;
        $data = $form['data'];
        $fields = (array) ($data['fields'] ?? []);

        if ($action === 'list') {
            $rows = nibwp_wpf_field_rows($data);

            return ['form_id' => $form_id, 'fields' => $rows, 'count' => count($rows)];
        }

        if ($action === 'get') {
            if (!isset($fields[$field_id])) {
                throw new \RuntimeException(__('No field with that ID on this form.', domain: 'nibwp'));
            }

            return ['form_id' => $form_id, 'field' => $fields[$field_id]];
        }

        if ($action === 'add') {
            $new = (array) ($input['field'] ?? []);
            if (($new['type'] ?? '') === '') {
                throw new \RuntimeException(__('A field type is required — text, email, textarea, select, checkbox, and so on.', domain: 'nibwp'));
            }

            // Never reuse an ID: notifications reference fields by ID, and a
            // recycled one silently points a smartcode at different content.
            $next = 1;
            foreach (array_keys($fields) as $existing) {
                $next = max($next, ((int) $existing) + 1);
            }

            $new['id'] = $next;
            $data['fields'][$next] = $new;

            $saved = nibwp_wpf_save($form_id, $data);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'field_id' => $next, 'created' => true];
        }

        if (!isset($fields[$field_id])) {
            throw new \RuntimeException(__('No field with that ID on this form.', domain: 'nibwp'));
        }

        if ($action === 'update') {
            $changes = (array) ($input['field'] ?? []);
            unset($changes['id']);
            $data['fields'][$field_id] = array_merge((array) $fields[$field_id], $changes);

            $saved = nibwp_wpf_save($form_id, $data);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'field_id' => $field_id, 'updated' => true];
        }

        unset($data['fields'][$field_id]);
        $saved = nibwp_wpf_save($form_id, $data);

        return $saved instanceof WP_Error ? $saved : [
            'form_id'  => $form_id,
            'field_id' => $field_id,
            'deleted'  => true,
            'note'     => __('Any notification referencing this field ID will now render that line empty.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Abilities 4 & 5 — notifications and confirmations
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-notifications', [
    'label'       => __('WPForms — Notifications', domain: 'nibwp'),
    'description' => __('Read and configure the emails a form sends on submit — recipient, subject, message, and whether each is enabled.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'delete'], 'default' => 'list'],
            'form_id' => ['type' => 'integer'],
            'notification_id' => ['type' => 'string', 'description' => 'The numeric key, as returned by list.'],
            'notification'    => ['type' => 'object', 'description' => 'add/update: notification_name, email, subject, message, enable.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_notifications',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'On WPForms Lite this is the ONLY record of a submission — nothing is stored, so a disabled notification means the enquiry is gone for good.',
                'Messages use smartcodes: {all_fields} for everything, {field_id="3"} for one field. A smartcode naming a field that does not exist renders empty.',
                'update merges, so send only what changes.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpf_notifications(array $input): array|WP_Error
{
    return nibwp_wpf_settings_collection($input, 'notifications', 'notification');
}

wp_register_ability('nibwp/wpforms-confirmations', [
    'label'       => __('WPForms — Confirmations', domain: 'nibwp'),
    'description' => __('Read and configure what a visitor sees after submitting — a message, a page, or a redirect.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'add', 'update', 'delete'], 'default' => 'list'],
            'form_id' => ['type' => 'integer'],
            'confirmation_id' => ['type' => 'string'],
            'confirmation'    => ['type' => 'object', 'description' => 'add/update: type (message|page|redirect), message, page, redirect.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_confirmations',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'A redirect to a URL that does not exist is a silent dead end for whoever just filled the form. Check the target before setting one.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpf_confirmations(array $input): array|WP_Error
{
    return nibwp_wpf_settings_collection($input, 'confirmations', 'confirmation');
}

/**
 * Notifications and confirmations are the same shape: numerically-keyed
 * collections under the form's `settings`. One implementation, so they cannot
 * drift into behaving differently.
 *
 * @return array|WP_Error
 */
function nibwp_wpf_settings_collection(array $input, string $collection, string $singular)
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    $form = nibwp_wpf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? 'list');
    $key = (string) ($input[$singular . '_id'] ?? '');

    if (in_array($action, ['update', 'delete'], strict: true) && $key === '') {
        return nibwp_wpf_err('nibwp_wpf_bad_id', __('An ID is required. List them first.', domain: 'nibwp'));
    }

    return nibwp_wpf_try(static function () use ($form, $action, $collection, $singular, $key, $input) {
        $form_id = (int) $form['post']->ID;
        $data = $form['data'];
        $items = (array) ($data['settings'][$collection] ?? []);

        if ($action === 'list') {
            $rows = [];
            $enabled = 0;

            foreach ($items as $id => $item) {
                $item = (array) $item;
                // WPForms treats a missing `enable` as on for the first
                // notification, so an absent key must not read as disabled.
                $is_on = !array_key_exists('enable', $item) || (bool) $item['enable'];
                if ($is_on) {
                    $enabled++;
                }

                $rows[] = [
                    'id'      => (string) $id,
                    'name'    => (string) ($item[$singular . '_name'] ?? ($item['name'] ?? '')),
                    'enabled' => $is_on,
                    'email'   => $item['email'] ?? null,
                    'subject' => (string) ($item['subject'] ?? ''),
                    'type'    => (string) ($item['type'] ?? ''),
                ];
            }

            return [
                'form_id'   => $form_id,
                $collection => $rows,
                'count'     => count($rows),
                'enabled'   => $enabled,
                'warning'   => ($collection === 'notifications' && $enabled === 0)
                    ? __('No enabled notification. On WPForms Lite nothing is stored either, so submissions to this form are lost entirely.', domain: 'nibwp')
                    : '',
            ];
        }

        if ($action === 'add') {
            $value = (array) ($input[$singular] ?? []);
            if ($value === []) {
                throw new \RuntimeException(__('An object is required.', domain: 'nibwp'));
            }

            $next = 1;
            foreach (array_keys($items) as $existing) {
                $next = max($next, ((int) $existing) + 1);
            }

            $data['settings'][$collection][$next] = $value;
            $saved = nibwp_wpf_save($form_id, $data);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'id' => (string) $next, 'created' => true];
        }

        if (!array_key_exists($key, $items)) {
            throw new \RuntimeException(__('Nothing with that ID on this form.', domain: 'nibwp'));
        }

        if ($action === 'update') {
            // Merged, not replaced: changing a subject must not clear the
            // recipient and the message body.
            $data['settings'][$collection][$key] = array_merge((array) $items[$key], (array) ($input[$singular] ?? []));
            $saved = nibwp_wpf_save($form_id, $data);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'id' => $key, 'updated' => true];
        }

        unset($data['settings'][$collection][$key]);
        $saved = nibwp_wpf_save($form_id, $data);

        return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'id' => $key, 'deleted' => true];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/wpforms-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-settings', [
    'label'       => __('WPForms — Form settings', domain: 'nibwp'),
    'description' => __('Read and change a form\'s settings: title, description, submit button, anti-spam, and the rest of the settings object.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id'  => ['type' => 'integer'],
            'settings' => ['type' => 'object', 'description' => 'set: merged into the existing settings. Notifications and confirmations have their own abilities.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Settings merge, so send only what changes. Notifications and confirmations live under here too, but edit those through their own abilities — merging into them wholesale is how a recipient gets cleared.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpf_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    $form = nibwp_wpf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_wpf_try(static function () use ($form, $input) {
        $form_id = (int) $form['post']->ID;
        $data = $form['data'];
        $settings = (array) ($data['settings'] ?? []);

        if ((string) ($input['action'] ?? 'get') === 'set') {
            $changes = (array) ($input['settings'] ?? []);
            if ($changes === []) {
                throw new \RuntimeException(__('Nothing to set.', domain: 'nibwp'));
            }

            // Refused rather than merged: these two are collections keyed by ID
            // and a partial merge silently rewrites entries inside them.
            foreach (['notifications', 'confirmations'] as $guarded) {
                if (array_key_exists($guarded, $changes)) {
                    throw new \RuntimeException(sprintf(
                        /* translators: %s: the settings key */
                        __('Edit %s through its own ability — merging it here rewrites entries inside the collection.', domain: 'nibwp'),
                        $guarded
                    ));
                }
            }

            $data['settings'] = array_replace_recursive($settings, $changes);
            $saved = nibwp_wpf_save($form_id, $data);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'updated' => true];
        }

        // Collections are summarised rather than dumped — they have their own
        // abilities and returning them here doubles the payload for nothing.
        $out = $settings;
        $out['notifications'] = count((array) ($settings['notifications'] ?? []));
        $out['confirmations'] = count((array) ($settings['confirmations'] ?? []));

        return ['form_id' => $form_id, 'settings' => $out];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/wpforms-entries (read + write, Pro)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-entries', [
    'label'       => __('WPForms — Entries', domain: 'nibwp'),
    'description' => __('List and read stored entries, and mark them read or starred. Needs WPForms Pro — Lite stores nothing.', domain: 'nibwp'),
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
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_entries',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Refuses on WPForms Lite, which stores no entries at all. That refusal is the answer to "where are my submissions" — they were emailed and never saved.',
                'Entries are personal data. Read what the task needs and no more.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpf_entries(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    if (!nibwp_wpf_pro()) {
        return nibwp_wpf_err(
            'nibwp_wpf_lite_no_entries',
            __('WPForms Lite does not store entries — past submissions were never saved, only emailed. There is no history to read and nothing here can recover them; this is not an empty result but an absent feature. Storing entries needs WPForms Pro.', domain: 'nibwp')
        );
    }

    $wpforms = wpforms();
    $entry_handler = $wpforms->entry ?? null;

    if ($entry_handler === null) {
        return nibwp_wpf_err('nibwp_wpf_no_entry_handler', __('The WPForms entry handler is unavailable.', domain: 'nibwp'));
    }

    return nibwp_wpf_try(static function () use ($entry_handler, $input) {
        $action = (string) ($input['action'] ?? 'list');
        $form_id = (int) ($input['form_id'] ?? 0);

        if ($action === 'count') {
            return ['form_id' => $form_id, 'total' => (int) $entry_handler->get_entries(['form_id' => $form_id], true)];
        }

        if ($action === 'get') {
            $entry_id = (int) ($input['entry_id'] ?? 0);
            if ($entry_id <= 0) {
                throw new \RuntimeException(__('A valid entry ID is required.', domain: 'nibwp'));
            }

            return ['entry_id' => $entry_id, 'entry' => $entry_handler->get($entry_id)];
        }

        $page = nibwp_wpf_paginate($input);
        $entries = $entry_handler->get_entries([
            'form_id' => $form_id,
            'number'  => $page['per_page'],
            'offset'  => ($page['page'] - 1) * $page['per_page'],
        ]);

        return ['form_id' => $form_id, 'entries' => $entries, 'count' => is_array($entries) ? count($entries) : 0];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/wpforms-audit (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-audit', [
    'label'       => __('WPForms — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the faults that lose enquiries: no enabled notification, no confirmation, and no fields (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_wpf_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'On Lite this matters more than anywhere else: with no entry storage, a form whose notification is disabled loses submissions permanently and looks completely normal.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_wpf_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    return nibwp_wpf_try(static function () use ($input): array {
        $handler = nibwp_wpf_handler();
        $form_id = (int) ($input['form_id'] ?? 0);

        $posts = $form_id > 0
            ? array_filter([$handler->get($form_id)])
            : (array) $handler->get('', ['posts_per_page' => 200]);

        $findings = [];
        $lite = !nibwp_wpf_pro();

        foreach ($posts as $post) {
            $id = (int) ($post->ID ?? 0);
            $data = json_decode((string) ($post->post_content ?? ''), true);
            $data = is_array($data) ? $data : [];
            $title = (string) ($data['settings']['form_title'] ?? ($post->post_title ?? ''));

            $notifications = (array) ($data['settings']['notifications'] ?? []);
            $enabled = 0;
            foreach ($notifications as $notification) {
                $notification = (array) $notification;
                if (!array_key_exists('enable', $notification) || $notification['enable']) {
                    $enabled++;
                }
            }

            if ($enabled === 0) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'blocker',
                    'code'     => 'no_enabled_notification',
                    'message'  => $lite
                        ? __('No enabled notification, and Lite stores nothing. Submissions to this form are lost entirely.', domain: 'nibwp')
                        : __('No enabled notification. Entries are stored but nobody is told.', domain: 'nibwp'),
                    'fix'      => __('Enable or add one with nibwp/wpforms-notifications.', domain: 'nibwp'),
                ];
            }

            if ((array) ($data['settings']['confirmations'] ?? []) === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_confirmation',
                    'message'  => __('No confirmation, so a visitor gets no acknowledgement after submitting.', domain: 'nibwp'),
                    'fix'      => __('Add one with nibwp/wpforms-confirmations.', domain: 'nibwp'),
                ];
            }

            if ((array) ($data['fields'] ?? []) === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_fields',
                    'message'  => __('The form has no fields, so there is nothing to submit.', domain: 'nibwp'),
                    'fix'      => __('Add fields with nibwp/wpforms-fields.', domain: 'nibwp'),
                ];
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'edition'       => $lite ? 'lite' : 'pro',
            'stores_entries' => !$lite,
            'forms_checked' => count($posts),
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/wpforms-delete (destructive)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/wpforms-delete', [
    'label'       => __('WPForms — Delete', domain: 'nibwp'),
    'description' => __('Trash or permanently delete a WPForms form. Irreversible where stated.', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_wpf_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Prefer trash — it is reversible. Deleting a form on Pro deletes its entries with it, and every page embedding the shortcode renders nothing afterwards.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_wpf_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_wpf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if ($form_id <= 0) {
        return nibwp_wpf_err('nibwp_wpf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    if ($action === 'delete' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_wpf_err(
            'nibwp_wpf_unconfirmed',
            __('This permanently destroys the form, and its entries on Pro. Trashing is reversible; re-issue with confirm true if deletion is intended.', domain: 'nibwp')
        );
    }

    return nibwp_wpf_try(static function () use ($action, $form_id) {
        $handler = nibwp_wpf_handler();

        if ($action === 'trash') {
            $handler->update_status($form_id, 'trash');

            return ['form_id' => $form_id, 'trashed' => true, 'reversible' => true];
        }

        $handler->delete($form_id);

        return ['form_id' => $form_id, 'deleted' => true, 'reversible' => false];
    });
}
