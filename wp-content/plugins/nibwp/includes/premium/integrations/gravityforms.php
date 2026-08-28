<?php

declare(strict_types=1);

/**
 * Gravity Forms integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Thirteen abilities cover a Gravity Forms install end to end: forms, their
 * fields, entries and entry properties, entry notes, notifications,
 * confirmations, add-on feeds, form settings, programmatic submission and
 * validation, entry export, an add-on inventory, and deletion.
 *
 * WHY A DEDICATED INTEGRATION when the universal `forms` one already lists and
 * reads Gravity Forms: because the generic surface handles the form and its
 * entries, and everything that makes Gravity Forms worth paying for lives
 * outside those two. Notifications decide whether anyone hears about a
 * submission. Confirmations decide what the visitor sees next. Feeds are how
 * every add-on — Mailchimp, Stripe, HubSpot, Zapier, User Registration — is
 * configured, and they are invisible to a generic form reader. A form with no
 * notification and no feed collects data and does nothing with it.
 *
 * Mechanism is IN-PROCESS through GFAPI, Gravity Forms' own documented public
 * API, rather than the database or the REST API:
 *   GFAPI::get_form() / add_form() / update_form() / duplicate_form()
 *   GFAPI::get_entries() / add_entry() / update_entry_property()
 *   GFAPI::get_notes() / add_note()
 *   GFAPI::get_feeds() / add_feed() / update_feed()
 *   GFAPI::submit_form() / validate_form()
 *
 * Notifications and confirmations are NOT separate records — they live inside
 * the form object under `notifications` and `confirmations`, keyed by a UID.
 * Editing one means reading the form, changing that key, and writing the form
 * back, which is what those abilities do.
 *
 * Detection: GFAPI + GFCommon.
 *
 * Verified against Gravity Forms 2.9.0 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Gravity Forms active? */
function nibwp_gf_available(): bool
{
    return class_exists('GFAPI') && class_exists('GFCommon');
}

/** House WP_Error wrapper. */
function nibwp_gf_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_gf_guard(): ?WP_Error
{
    if (!nibwp_gf_available()) {
        return nibwp_gf_err(
            'nibwp_gf_missing',
            __('Gravity Forms is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Run a GFAPI call, converting throwables into WP_Error. */
function nibwp_gf_try(callable $fn, string $code = 'nibwp_gf_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_gf_err($code, $e->getMessage());
    }
}

/**
 * Load a form.
 *
 * GFAPI::get_form() returns false for an unknown ID rather than a WP_Error, so
 * the miss is turned into something a caller can read.
 *
 * @return array|WP_Error
 */
function nibwp_gf_form(int $form_id)
{
    if ($form_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $form = GFAPI::get_form($form_id);

    if (!$form || !is_array($form)) {
        return nibwp_gf_err('nibwp_gf_not_found', __('No Gravity Forms form with that ID.', domain: 'nibwp'));
    }

    return $form;
}

/**
 * Write a form back.
 *
 * @return true|WP_Error
 */
function nibwp_gf_save(array $form)
{
    $result = GFAPI::update_form($form);

    return is_wp_error($result) ? $result : true;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_gf_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return [
        'page_size' => $per_page,
        'offset'    => (max((int) ($in['page'] ?? 1), 1) - 1) * $per_page,
    ];
}

/**
 * A form summarised for a list — never the whole object.
 *
 * A Gravity Forms form with conditional logic and forty fields is a very large
 * array, and returning fifty of them at once buries the answer.
 */
function nibwp_gf_form_summary(array $form): array
{
    return [
        'id'            => (int) ($form['id'] ?? 0),
        'title'         => (string) ($form['title'] ?? ''),
        'is_active'     => (bool) ($form['is_active'] ?? true),
        'is_trash'      => (bool) ($form['is_trash'] ?? false),
        'field_count'   => count((array) ($form['fields'] ?? [])),
        'notifications' => count((array) ($form['notifications'] ?? [])),
        'confirmations' => count((array) ($form['confirmations'] ?? [])),
    ];
}

/**
 * Generate the UID Gravity Forms uses to key notifications and confirmations.
 *
 * GF uses uniqid() for these, so a new one follows the same shape rather than
 * inventing a scheme the admin screens would not recognize.
 */
function nibwp_gf_uid(): string
{
    return uniqid('', true) !== '' ? substr(md5(uniqid('', true)), 0, 13) : substr(md5((string) wp_rand()), 0, 13);
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/gravityforms-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-info', [
    'label'       => __('Gravity Forms — Info', domain: 'nibwp'),
    'description' => __('Detect Gravity Forms, its version, how many forms and entries exist, and which add-ons are active (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. The add-on list matters: feeds are how add-ons are configured, and a feed can only exist for an add-on that is installed.',
                'Unlike Contact Form 7, Gravity Forms stores every entry itself — there is always a submission history to read.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_gf_info(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    return nibwp_gf_try(static function (): array {
        $forms = GFAPI::get_forms(true, false);
        $forms = is_array($forms) ? $forms : [];

        $form_ids = array_map(static fn(array $f): int => (int) ($f['id'] ?? 0), $forms);
        $entries = $form_ids === [] ? 0 : (int) GFAPI::count_entries($form_ids);

        $addons = [];
        if (class_exists('GFAddOn') && method_exists('GFAddOn', 'get_registered_addons')) {
            // Passing true returns instances rather than class names — the
            // add-on's own accessor, instead of us re-implementing the
            // is_callable dance it already does.
            foreach ((array) GFAddOn::get_registered_addons(true) as $instance) {
                if (!is_object($instance)) {
                    continue;
                }
                $addons[] = [
                    'slug' => method_exists($instance, 'get_slug') ? (string) $instance->get_slug() : get_class($instance),
                    'name' => method_exists($instance, 'get_short_title') ? (string) $instance->get_short_title() : get_class($instance),
                ];
            }
        }

        return [
            'active'       => true,
            'version'      => (string) (GFCommon::$version ?? ''),
            'form_count'   => count($forms),
            'entry_count'  => $entries,
            'stores_entries' => true,
            'addons'       => $addons,
            'addon_count'  => count($addons),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/gravityforms-forms (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-forms', [
    'label'       => __('Gravity Forms — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, update, duplicate, activate, deactivate, trash and restore Gravity Forms forms.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'duplicate', 'activate', 'deactivate', 'trash', 'restore'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer', 'description' => 'Form ID. Required for everything except list and create.'],
            'form'    => ['type' => 'object', 'description' => 'create/update: the form object. For create, at minimum a title and fields.'],
            'title'   => ['type' => 'string', 'description' => 'Title for create, when not passing a whole form object.'],
            'include_trash' => ['type' => 'boolean', 'default' => false, 'description' => 'list: include trashed forms.'],
            'active_only'   => ['type' => 'boolean', 'default' => true, 'description' => 'list: only active forms.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'list returns summaries, not whole form objects — a Gravity Forms form with conditional logic is very large and fifty of them buries the answer. Use get for one.',
                'update REPLACES the form object. Read it, change what you mean, write it back — a partial object drops fields, notifications and confirmations.',
                'A new form has no notification and no feed, so it collects entries and tells nobody. Set at least one before calling it done.',
                'trash is reversible with restore. Permanent deletion lives in nibwp/gravityforms-delete.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    $needs_id = ['get', 'update', 'duplicate', 'activate', 'deactivate', 'trash', 'restore'];
    if (in_array($action, $needs_id, strict: true) && $form_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_gf_try(static function () use ($action, $form_id, $input) {
        switch ($action) {
            case 'list':
                $forms = GFAPI::get_forms(
                    (bool) ($input['active_only'] ?? true),
                    (bool) ($input['include_trash'] ?? false)
                );
                $rows = array_map('nibwp_gf_form_summary', is_array($forms) ? $forms : []);

                return ['forms' => $rows, 'count' => count($rows)];

            case 'get':
                $form = nibwp_gf_form($form_id);

                return $form instanceof WP_Error ? $form : ['form_id' => $form_id, 'form' => $form];

            case 'create':
                $form = (array) ($input['form'] ?? []);
                if ($form === []) {
                    $form = [
                        'title'  => (string) ($input['title'] ?? __('Untitled form', domain: 'nibwp')),
                        'fields' => [],
                    ];
                }
                $new_id = GFAPI::add_form($form);
                if (is_wp_error($new_id)) {
                    return $new_id;
                }

                return [
                    'form_id' => (int) $new_id,
                    'created' => true,
                    'note'    => __('The form has no notification and no feed yet, so a submission would reach nobody.', domain: 'nibwp'),
                ];

            case 'update':
                $form = (array) ($input['form'] ?? []);
                if ($form === []) {
                    throw new \RuntimeException(__('A form object is required. Read the form first — update replaces it wholesale.', domain: 'nibwp'));
                }
                $form['id'] = $form_id;
                $saved = nibwp_gf_save($form);
                if ($saved instanceof WP_Error) {
                    return $saved;
                }

                return ['form_id' => $form_id, 'updated' => true];

            case 'duplicate':
                $new_id = GFAPI::duplicate_form($form_id);

                return is_wp_error($new_id) ? $new_id : ['form_id' => (int) $new_id, 'duplicated_from' => $form_id];

            case 'activate':
            case 'deactivate':
                $result = GFAPI::update_form_property($form_id, 'is_active', $action === 'activate' ? 1 : 0);

                return is_wp_error($result) ? $result : ['form_id' => $form_id, 'is_active' => $action === 'activate'];

            case 'trash':
            case 'restore':
                $result = GFAPI::update_form_property($form_id, 'is_trash', $action === 'trash' ? 1 : 0);

                return is_wp_error($result)
                    ? $result
                    : ['form_id' => $form_id, 'is_trash' => $action === 'trash', 'reversible' => true];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/gravityforms-fields (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-fields', [
    'label'       => __('Gravity Forms — Fields', domain: 'nibwp'),
    'description' => __('List, read, add, update and remove the fields on a form, without rewriting the whole form object.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete'], 'description' => 'The action to perform.'],
            'form_id'  => ['type' => 'integer'],
            'field_id' => ['type' => 'integer', 'description' => 'Field ID within the form. Required for get, update and delete.'],
            'field'    => ['type' => 'object', 'description' => 'add/update: the field object. type and label at minimum for add.'],
        ],
        'required' => ['action', 'form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Field IDs are permanent and are what entries are keyed by. Deleting a field does not delete the values already stored against its ID in past entries — those become unreachable through the form but remain in the entry.',
                'Changing a field\'s type on a form that already has entries is how data stops matching its own field. Prefer adding a new field.',
                'add allocates the next free ID itself, the way Gravity Forms does.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $form = nibwp_gf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? '');
    $field_id = (int) ($input['field_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'delete'], strict: true) && $field_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid field ID is required.', domain: 'nibwp'));
    }

    return nibwp_gf_try(static function () use ($form, $action, $field_id, $input) {
        $form_id = (int) $form['id'];
        $fields = (array) ($form['fields'] ?? []);

        if ($action === 'list') {
            $rows = [];
            foreach ($fields as $field) {
                $rows[] = [
                    'id'         => (int) (is_object($field) ? ($field->id ?? 0) : ($field['id'] ?? 0)),
                    'type'       => (string) (is_object($field) ? ($field->type ?? '') : ($field['type'] ?? '')),
                    'label'      => (string) (is_object($field) ? ($field->label ?? '') : ($field['label'] ?? '')),
                    'isRequired' => (bool) (is_object($field) ? ($field->isRequired ?? false) : ($field['isRequired'] ?? false)),
                ];
            }

            return ['form_id' => $form_id, 'fields' => $rows, 'count' => count($rows)];
        }

        if ($action === 'get') {
            $field = GFAPI::get_field($form_id, $field_id);
            if (!$field) {
                throw new \RuntimeException(__('No field with that ID on this form.', domain: 'nibwp'));
            }

            return ['form_id' => $form_id, 'field' => $field];
        }

        if ($action === 'add') {
            $new = (array) ($input['field'] ?? []);
            if (($new['type'] ?? '') === '') {
                throw new \RuntimeException(__('A field type is required — text, email, textarea, select, checkbox, and so on.', domain: 'nibwp'));
            }

            // Gravity Forms keys entries by field ID, so a new field must take
            // an ID no past entry has used. nextFieldId is what the admin uses.
            $next = (int) ($form['nextFieldId'] ?? 0);
            if ($next <= 0) {
                $next = 1;
                foreach ($fields as $field) {
                    $next = max($next, ((int) (is_object($field) ? ($field->id ?? 0) : ($field['id'] ?? 0))) + 1);
                }
            }

            $new['id'] = $next;
            $form['fields'][] = $new;
            $form['nextFieldId'] = $next + 1;

            $saved = nibwp_gf_save($form);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            return ['form_id' => $form_id, 'field_id' => $next, 'created' => true];
        }

        $index = null;
        foreach ($fields as $i => $field) {
            $id = (int) (is_object($field) ? ($field->id ?? 0) : ($field['id'] ?? 0));
            if ($id === $field_id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            throw new \RuntimeException(__('No field with that ID on this form.', domain: 'nibwp'));
        }

        if ($action === 'update') {
            $changes = (array) ($input['field'] ?? []);
            $existing = $fields[$index];

            foreach ($changes as $key => $value) {
                if ($key === 'id') {
                    // Refused rather than applied: the ID is the key every past
                    // entry stores its value under.
                    continue;
                }
                if (is_object($existing)) {
                    $existing->{$key} = $value;
                } else {
                    $existing[$key] = $value;
                }
            }

            $form['fields'][$index] = $existing;
            $saved = nibwp_gf_save($form);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'field_id' => $field_id, 'updated' => true];
        }

        // delete
        array_splice($form['fields'], $index, 1);
        $saved = nibwp_gf_save($form);

        return $saved instanceof WP_Error ? $saved : [
            'form_id'  => $form_id,
            'field_id' => $field_id,
            'deleted'  => true,
            'note'     => __('Values already stored against this field ID remain in past entries but are no longer reachable through the form.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/gravityforms-entries (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-entries', [
    'label'       => __('Gravity Forms — Entries', domain: 'nibwp'),
    'description' => __('Search, read, count, create and update entries, and change their state: starred, read, spam and trash.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get', 'count', 'create', 'update', 'star', 'unstar', 'mark_read', 'mark_unread', 'mark_spam', 'unmark_spam', 'trash', 'restore'], 'description' => 'The action to perform.'],
            'form_id'  => ['type' => 'integer', 'description' => 'Form to search. Required for list, count and create.'],
            'entry_id' => ['type' => 'integer', 'description' => 'Required for every per-entry action.'],
            'entry'    => ['type' => 'object', 'description' => 'create/update: the entry, keyed by field ID.'],
            'search'   => ['type' => 'object', 'description' => 'list/count: a Gravity Forms search_criteria array — status, date_range, field_filters.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_entries',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Entries are personal data. Read what the task needs and do not copy it somewhere it was not collected for.',
                'Entries are keyed by FIELD ID, not by label — {"1": "Jane", "3": "jane@example.com"}. Read the fields first with nibwp/gravityforms-fields.',
                'create writes an entry as though it had been submitted, but does NOT run notifications or feeds. Use nibwp/gravityforms-submit if the side effects are wanted.',
                'trash is reversible with restore.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_entries(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);
    $entry_id = (int) ($input['entry_id'] ?? 0);

    $per_entry = ['get', 'update', 'star', 'unstar', 'mark_read', 'mark_unread', 'mark_spam', 'unmark_spam', 'trash', 'restore'];
    if (in_array($action, $per_entry, strict: true) && $entry_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid entry ID is required.', domain: 'nibwp'));
    }
    if (in_array($action, ['list', 'count', 'create'], strict: true) && $form_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_gf_try(static function () use ($action, $form_id, $entry_id, $input) {
        $search = (array) ($input['search'] ?? []);

        switch ($action) {
            case 'list':
                $page = nibwp_gf_paginate($input);
                $total = 0;
                $entries = GFAPI::get_entries(
                    $form_id,
                    $search,
                    null,
                    ['offset' => $page['offset'], 'page_size' => $page['page_size']],
                    $total
                );

                return [
                    'form_id' => $form_id,
                    'entries' => is_array($entries) ? $entries : [],
                    'count'   => is_array($entries) ? count($entries) : 0,
                    'total'   => (int) $total,
                ];

            case 'count':
                return ['form_id' => $form_id, 'total' => (int) GFAPI::count_entries($form_id, $search)];

            case 'get':
                $entry = GFAPI::get_entry($entry_id);

                return is_wp_error($entry) ? $entry : ['entry_id' => $entry_id, 'entry' => $entry];

            case 'create':
                $entry = (array) ($input['entry'] ?? []);
                $entry['form_id'] = $form_id;
                $new_id = GFAPI::add_entry($entry);

                return is_wp_error($new_id) ? $new_id : [
                    'entry_id' => (int) $new_id,
                    'created'  => true,
                    'note'     => __('Stored only. No notification was sent and no feed ran — use nibwp/gravityforms-submit for that.', domain: 'nibwp'),
                ];

            case 'update':
                $entry = (array) ($input['entry'] ?? []);
                if ($entry === []) {
                    throw new \RuntimeException(__('An entry object is required.', domain: 'nibwp'));
                }
                $entry['id'] = $entry_id;
                $result = GFAPI::update_entry($entry, $entry_id);

                return is_wp_error($result) ? $result : ['entry_id' => $entry_id, 'updated' => true];
        }

        // The remaining actions are all single-property writes.
        $map = [
            'star'        => ['is_starred', 1],
            'unstar'      => ['is_starred', 0],
            'mark_read'   => ['is_read', 1],
            'mark_unread' => ['is_read', 0],
            'mark_spam'   => ['status', 'spam'],
            'unmark_spam' => ['status', 'active'],
            'trash'       => ['status', 'trash'],
            'restore'     => ['status', 'active'],
        ];

        if (!isset($map[$action])) {
            throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
        }

        [$property, $value] = $map[$action];
        $result = GFAPI::update_entry_property($entry_id, $property, $value);

        return is_wp_error($result)
            ? $result
            : ['entry_id' => $entry_id, $property => $value, 'reversible' => in_array($action, ['trash', 'mark_spam'], strict: true)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/gravityforms-notes (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-notes', [
    'label'       => __('Gravity Forms — Entry notes', domain: 'nibwp'),
    'description' => __('Read, add and remove the internal notes attached to an entry.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'add', 'delete'], 'default' => 'list'],
            'entry_id' => ['type' => 'integer', 'description' => 'Required for list and add.'],
            'note_id'  => ['type' => 'integer', 'description' => 'Required for delete.'],
            'note'     => ['type' => 'string', 'description' => 'add: the note text.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_notes',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Notes are internal and never shown to the person who submitted the form. A note is attributed to the user the connection authenticated as.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_notes(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'list');
    $entry_id = (int) ($input['entry_id'] ?? 0);
    $note_id = (int) ($input['note_id'] ?? 0);

    if (in_array($action, ['list', 'add'], strict: true) && $entry_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid entry ID is required.', domain: 'nibwp'));
    }
    if ($action === 'delete' && $note_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid note ID is required.', domain: 'nibwp'));
    }

    return nibwp_gf_try(static function () use ($action, $entry_id, $note_id, $input) {
        if ($action === 'list') {
            $notes = GFAPI::get_notes(['entry_id' => $entry_id]);

            return ['entry_id' => $entry_id, 'notes' => is_array($notes) ? $notes : [], 'count' => is_array($notes) ? count($notes) : 0];
        }

        if ($action === 'add') {
            $text = trim((string) ($input['note'] ?? ''));
            if ($text === '') {
                throw new \RuntimeException(__('Note text is required.', domain: 'nibwp'));
            }

            $user = wp_get_current_user();
            $result = GFAPI::add_note(
                $entry_id,
                (int) $user->ID,
                (string) ($user->display_name ?: 'NibWP'),
                $text
            );

            return is_wp_error($result) ? $result : ['entry_id' => $entry_id, 'note_id' => (int) $result, 'created' => true];
        }

        $result = GFAPI::delete_note($note_id);

        return is_wp_error($result) ? $result : ['note_id' => $note_id, 'deleted' => true];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/gravityforms-notifications (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-notifications', [
    'label'       => __('Gravity Forms — Notifications', domain: 'nibwp'),
    'description' => __('Read and configure the emails a form sends on submit — recipient, subject, message, and when each one fires.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete', 'toggle'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'notification_id' => ['type' => 'string', 'description' => 'The notification UID, as returned by list.'],
            'notification'    => ['type' => 'object', 'description' => 'add/update: name, to, subject, message, event, and optional conditionalLogic.'],
            'enabled' => ['type' => 'boolean', 'description' => 'toggle: whether it is active.'],
        ],
        'required' => ['action', 'form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_notifications',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This decides whether anyone learns about a submission. A form with no active notification collects entries silently.',
                'Notifications live inside the form object keyed by a UID — there is no separate record. add generates the UID.',
                'Messages use merge tags: {Field Label:2} for a field, {all_fields} for everything. A merge tag naming a field that does not exist renders empty rather than erroring.',
                'update merges into the existing notification, so send only what changes.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_notifications(array $input): array|WP_Error
{
    return nibwp_gf_form_collection($input, 'notifications');
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/gravityforms-confirmations (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-confirmations', [
    'label'       => __('Gravity Forms — Confirmations', domain: 'nibwp'),
    'description' => __('Read and configure what a visitor sees after submitting — a message, a redirect, or another page.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete', 'toggle'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer'],
            'confirmation_id' => ['type' => 'string', 'description' => 'The confirmation UID, as returned by list.'],
            'confirmation'    => ['type' => 'object', 'description' => 'add/update: name, type (message|page|redirect), message, pageId or url, and optional conditionalLogic.'],
            'enabled' => ['type' => 'boolean'],
        ],
        'required' => ['action', 'form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_confirmations',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Every form has a default confirmation. Adding more only makes sense with conditional logic deciding between them, otherwise the first match always wins.',
                'A redirect confirmation to a URL that does not exist is a silent dead end for the visitor — check the target.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_confirmations(array $input): array|WP_Error
{
    return nibwp_gf_form_collection($input, 'confirmations');
}

/**
 * Notifications and confirmations are the same shape of thing: a UID-keyed
 * collection living inside the form object. One implementation, so the two
 * cannot drift into behaving differently.
 *
 * @return array|WP_Error
 */
function nibwp_gf_form_collection(array $input, string $collection)
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $form = nibwp_gf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $action = (string) ($input['action'] ?? '');
    $singular = $collection === 'notifications' ? 'notification' : 'confirmation';
    $uid = (string) ($input[$singular . '_id'] ?? '');

    if (in_array($action, ['get', 'update', 'delete', 'toggle'], strict: true) && $uid === '') {
        return nibwp_gf_err('nibwp_gf_bad_id', __('The UID is required. List them first.', domain: 'nibwp'));
    }

    return nibwp_gf_try(static function () use ($form, $action, $collection, $singular, $uid, $input) {
        $form_id = (int) $form['id'];
        $items = (array) ($form[$collection] ?? []);

        if ($action === 'list') {
            $rows = [];
            foreach ($items as $key => $item) {
                $item = (array) $item;
                $rows[] = [
                    'id'      => (string) $key,
                    'name'    => (string) ($item['name'] ?? ''),
                    'event'   => (string) ($item['event'] ?? ''),
                    'type'    => (string) ($item['type'] ?? ''),
                    'to'      => (string) ($item['to'] ?? ''),
                    'subject' => (string) ($item['subject'] ?? ''),
                    'isActive' => !array_key_exists('isActive', $item) || (bool) $item['isActive'],
                    'has_conditional_logic' => !empty($item['conditionalLogic']),
                ];
            }

            $active = array_filter($rows, static fn(array $r): bool => $r['isActive']);

            return [
                'form_id'   => $form_id,
                $collection => $rows,
                'count'     => count($rows),
                'active'    => count($active),
                'warning'   => ($collection === 'notifications' && $active === [])
                    ? __('No active notification. Submissions to this form reach nobody.', domain: 'nibwp')
                    : '',
            ];
        }

        if ($action === 'add') {
            $item = (array) ($input[$singular] ?? []);
            if ($item === []) {
                throw new \RuntimeException(__('An object is required.', domain: 'nibwp'));
            }

            $new_uid = nibwp_gf_uid();
            $item['id'] = $new_uid;
            if (!isset($item['isActive'])) {
                $item['isActive'] = true;
            }

            $form[$collection][$new_uid] = $item;
            $saved = nibwp_gf_save($form);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'id' => $new_uid, 'created' => true];
        }

        if (!isset($items[$uid])) {
            throw new \RuntimeException(__('Nothing with that UID on this form.', domain: 'nibwp'));
        }

        if ($action === 'get') {
            return ['form_id' => $form_id, $singular => $items[$uid]];
        }

        if ($action === 'update') {
            // Merged, not replaced: an agent changing a subject should not have
            // to resend the message body, the recipient and the conditional
            // logic to avoid clearing them.
            $form[$collection][$uid] = array_merge((array) $items[$uid], (array) ($input[$singular] ?? []));
            $form[$collection][$uid]['id'] = $uid;
            $saved = nibwp_gf_save($form);

            return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'id' => $uid, 'updated' => true];
        }

        if ($action === 'toggle') {
            $form[$collection][$uid]['isActive'] = (bool) ($input['enabled'] ?? true);
            $saved = nibwp_gf_save($form);

            return $saved instanceof WP_Error ? $saved : [
                'form_id' => $form_id,
                'id'      => $uid,
                'isActive' => (bool) ($input['enabled'] ?? true),
            ];
        }

        unset($form[$collection][$uid]);
        $saved = nibwp_gf_save($form);

        return $saved instanceof WP_Error ? $saved : ['form_id' => $form_id, 'id' => $uid, 'deleted' => true];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/gravityforms-feeds (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-feeds', [
    'label'       => __('Gravity Forms — Add-on feeds', domain: 'nibwp'),
    'description' => __('Read and configure add-on feeds — how Mailchimp, Stripe, User Registration, Zapier and the rest are wired to a form.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'add', 'update', 'delete', 'toggle'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer', 'description' => 'Filter by form, or the form to add a feed to.'],
            'feed_id' => ['type' => 'integer'],
            'addon_slug' => ['type' => 'string', 'description' => 'Add-on slug — gravityformsmailchimp, gravityformsstripe, and so on. Required for add.'],
            'meta'    => ['type' => 'object', 'description' => 'add/update: the feed meta. Its shape is defined by the add-on.'],
            'enabled' => ['type' => 'boolean'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_feeds',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Feeds are the part a generic form tool cannot see, and they are where the money is: payments, CRM sync, user registration.',
                'Feed meta shape is defined by each add-on, not by Gravity Forms. Read an existing feed for that add-on before writing one — a guessed shape is accepted and then does nothing.',
                'A payment feed change affects real transactions. Say what will change before writing.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_feeds(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $feed_id = (int) ($input['feed_id'] ?? 0);
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'update', 'delete', 'toggle'], strict: true) && $feed_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid feed ID is required.', domain: 'nibwp'));
    }

    return nibwp_gf_try(static function () use ($action, $feed_id, $form_id, $input) {
        switch ($action) {
            case 'list':
                $feeds = GFAPI::get_feeds(
                    null,
                    $form_id > 0 ? $form_id : null,
                    (string) ($input['addon_slug'] ?? '') ?: null,
                    false
                );

                if (is_wp_error($feeds)) {
                    // GF returns a WP_Error when nothing matches, which is not
                    // an error worth propagating — an empty list is the answer.
                    return ['feeds' => [], 'count' => 0];
                }

                $rows = [];
                foreach ((array) $feeds as $feed) {
                    $rows[] = [
                        'id'         => (int) ($feed['id'] ?? 0),
                        'form_id'    => (int) ($feed['form_id'] ?? 0),
                        'addon_slug' => (string) ($feed['addon_slug'] ?? ''),
                        'is_active'  => (bool) ($feed['is_active'] ?? true),
                        'name'       => (string) ($feed['meta']['feedName'] ?? ''),
                    ];
                }

                return ['feeds' => $rows, 'count' => count($rows)];

            case 'get':
                $feed = GFAPI::get_feed($feed_id);

                return is_wp_error($feed) ? $feed : ['feed_id' => $feed_id, 'feed' => $feed];

            case 'add':
                $slug = (string) ($input['addon_slug'] ?? '');
                if ($slug === '' || $form_id <= 0) {
                    throw new \RuntimeException(__('A form_id and an addon_slug are both required.', domain: 'nibwp'));
                }
                $new_id = GFAPI::add_feed($form_id, (array) ($input['meta'] ?? []), $slug);

                return is_wp_error($new_id) ? $new_id : ['feed_id' => (int) $new_id, 'form_id' => $form_id, 'addon_slug' => $slug, 'created' => true];

            case 'update':
                $result = GFAPI::update_feed($feed_id, (array) ($input['meta'] ?? []));

                return is_wp_error($result) ? $result : ['feed_id' => $feed_id, 'updated' => true];

            case 'toggle':
                $result = GFAPI::update_feed_property($feed_id, 'is_active', (bool) ($input['enabled'] ?? true) ? 1 : 0);

                return is_wp_error($result) ? $result : ['feed_id' => $feed_id, 'is_active' => (bool) ($input['enabled'] ?? true)];

            case 'delete':
                $result = GFAPI::delete_feed($feed_id);

                return is_wp_error($result) ? $result : ['feed_id' => $feed_id, 'deleted' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/gravityforms-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-settings', [
    'label'       => __('Gravity Forms — Form settings', domain: 'nibwp'),
    'description' => __('Read and change a form\'s settings: title, description, button, entry limits, scheduling, login requirement, honeypot and save-and-continue.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id'  => ['type' => 'integer'],
            'settings' => ['type' => 'object', 'description' => 'set: any top-level form setting. Merged into the form.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Three settings stop a form accepting submissions and are easy to leave behind: limitEntries with a reached limit, scheduleForm with a past end date, and requireLogin.',
                'Settings merge into the form, so send only what changes — fields, notifications and confirmations are untouched.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $form = nibwp_gf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_gf_try(static function () use ($form, $input) {
        $form_id = (int) $form['id'];

        $keys = [
            'title', 'description', 'labelPlacement', 'descriptionPlacement', 'button',
            'limitEntries', 'limitEntriesCount', 'limitEntriesPeriod', 'limitEntriesMessage',
            'scheduleForm', 'scheduleStart', 'scheduleEnd', 'scheduleMessage',
            'requireLogin', 'requireLoginMessage', 'enableHoneypot', 'enableAnimation',
            'save', 'postContentTemplateEnabled', 'useCurrentUserAsAuthor',
        ];

        if ((string) ($input['action'] ?? 'get') === 'set') {
            $settings = (array) ($input['settings'] ?? []);
            if ($settings === []) {
                throw new \RuntimeException(__('Nothing to set.', domain: 'nibwp'));
            }

            foreach ($settings as $key => $value) {
                $form[$key] = $value;
            }

            $saved = nibwp_gf_save($form);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            // The three settings that silently close a form to submissions.
            $blocking = [];
            if (!empty($form['limitEntries'])) {
                $blocking[] = 'limitEntries';
            }
            if (!empty($form['scheduleForm'])) {
                $blocking[] = 'scheduleForm';
            }
            if (!empty($form['requireLogin'])) {
                $blocking[] = 'requireLogin';
            }

            return [
                'form_id' => $form_id,
                'updated' => true,
                'submission_gates' => $blocking,
                'warning' => $blocking === []
                    ? ''
                    : __('This form now restricts who can submit, or when. Confirm that is intended.', domain: 'nibwp'),
            ];
        }

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $form)) {
                $out[$key] = $form[$key];
            }
        }

        return ['form_id' => $form_id, 'settings' => $out, 'known_keys' => $keys];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 10 — nibwp/gravityforms-submit (write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-submit', [
    'label'       => __('Gravity Forms — Submit and validate', domain: 'nibwp'),
    'description' => __('Validate a set of values against a form\'s rules, or submit them properly — running validation, notifications and feeds exactly as a real submission would.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['validate', 'submit'], 'default' => 'validate'],
            'form_id' => ['type' => 'integer'],
            'values'  => ['type' => 'object', 'description' => 'Input values keyed as the form expects — input_1, input_3.2, and so on.'],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'submit: must be true. A real submission sends email and runs payment feeds.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_submit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'validate is the safe one and answers most questions — it reports which fields would fail and why, without writing anything.',
                'submit runs the real thing: it stores an entry, sends every active notification and executes every active feed. On a form with a payment feed that can charge a card. It is confirm-gated for that reason.',
                'Never submit to a live form to "test" it without saying so first.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_submit(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) {
        return nibwp_gf_err('nibwp_gf_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $action = (string) ($input['action'] ?? 'validate');
    $values = (array) ($input['values'] ?? []);

    if ($action === 'submit' && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_gf_err(
            'nibwp_gf_unconfirmed',
            __('A real submission stores an entry, sends notifications and runs feeds — including payment feeds. Validate first, then re-issue with confirm true if a genuine submission is intended.', domain: 'nibwp')
        );
    }

    return nibwp_gf_try(static function () use ($action, $form_id, $values) {
        if ($action === 'validate') {
            $result = GFAPI::validate_form($form_id, $values);

            if (is_wp_error($result)) {
                return $result;
            }

            $failed = [];
            foreach ((array) ($result['form']['fields'] ?? []) as $field) {
                $failed_flag = is_object($field) ? ($field->failed_validation ?? false) : ($field['failed_validation'] ?? false);
                if ($failed_flag) {
                    $failed[] = [
                        'id'      => is_object($field) ? ($field->id ?? null) : ($field['id'] ?? null),
                        'label'   => is_object($field) ? ($field->label ?? '') : ($field['label'] ?? ''),
                        'message' => is_object($field) ? ($field->validation_message ?? '') : ($field['validation_message'] ?? ''),
                    ];
                }
            }

            return [
                'form_id'      => $form_id,
                'is_valid'     => (bool) ($result['is_valid'] ?? false),
                'failed_fields' => $failed,
                'wrote_anything' => false,
            ];
        }

        $result = GFAPI::submit_form($form_id, $values);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'form_id'    => $form_id,
            'is_valid'   => (bool) ($result['is_valid'] ?? false),
            'entry_id'   => (int) ($result['entry_id'] ?? 0),
            'validation_messages' => $result['validation_messages'] ?? [],
            'confirmation_message' => $result['confirmation_message'] ?? '',
            'side_effects' => __('An entry was stored, active notifications were sent and active feeds ran.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 11 — nibwp/gravityforms-export (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-export', [
    'label'       => __('Gravity Forms — Export entries', domain: 'nibwp'),
    'description' => __('Export a form\'s entries as rows or CSV, with the field labels as headers (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id'  => ['type' => 'integer'],
            'format'   => ['type' => 'string', 'enum' => ['rows', 'csv'], 'default' => 'rows'],
            'search'   => ['type' => 'object', 'description' => 'A Gravity Forms search_criteria array.'],
            'per_page' => ['type' => 'integer', 'default' => 50],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_export',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Paginated on purpose. A form with tens of thousands of entries will not fit in one response, and asking for it all fails slowly.',
                'This is personal data leaving its original context — export the columns the task needs, not everything.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_gf_export(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    $form = nibwp_gf_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_gf_try(static function () use ($form, $input) {
        $form_id = (int) $form['id'];
        $page = nibwp_gf_paginate($input);

        $total = 0;
        $entries = GFAPI::get_entries(
            $form_id,
            (array) ($input['search'] ?? []),
            null,
            ['offset' => $page['offset'], 'page_size' => $page['page_size']],
            $total
        );
        $entries = is_array($entries) ? $entries : [];

        // Headers come from the field labels so the export reads like the form,
        // not like the database.
        $columns = ['id' => 'Entry ID', 'date_created' => 'Date'];
        foreach ((array) ($form['fields'] ?? []) as $field) {
            $id = (string) (is_object($field) ? ($field->id ?? '') : ($field['id'] ?? ''));
            $label = (string) (is_object($field) ? ($field->label ?? '') : ($field['label'] ?? ''));
            if ($id !== '') {
                $columns[$id] = $label !== '' ? $label : $id;
            }
        }

        $rows = [];
        foreach ($entries as $entry) {
            $row = [];
            foreach ($columns as $key => $label) {
                $row[$label] = $entry[$key] ?? '';
            }
            $rows[] = $row;
        }

        if ((string) ($input['format'] ?? 'rows') === 'csv') {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                throw new \RuntimeException(__('Could not open a buffer to build the CSV.', domain: 'nibwp'));
            }
            fputcsv($handle, array_values($columns));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn($v) => is_scalar($v) ? (string) $v : wp_json_encode($v), array_values($row)));
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return ['form_id' => $form_id, 'format' => 'csv', 'csv' => $csv, 'count' => count($rows), 'total' => (int) $total];
        }

        return [
            'form_id' => $form_id,
            'format'  => 'rows',
            'columns' => array_values($columns),
            'rows'    => $rows,
            'count'   => count($rows),
            'total'   => (int) $total,
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 12 — nibwp/gravityforms-audit (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-audit', [
    'label'       => __('Gravity Forms — Audit', domain: 'nibwp'),
    'description' => __('Check every form for the faults that lose enquiries: no active notification, no confirmation, a reached entry limit, an expired schedule, or an inactive form still embedded (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Audit one form. Omit for every form.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run this on any site that already uses Gravity Forms. The failure it looks for is silent by definition: a form that collects entries nobody is told about looks identical to one that works.',
                'Findings carry their fix. Show them and let the user decide.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_gf_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    return nibwp_gf_try(static function () use ($input) {
        $form_id = (int) ($input['form_id'] ?? 0);

        if ($form_id > 0) {
            $one = nibwp_gf_form($form_id);
            if ($one instanceof WP_Error) {
                return $one;
            }
            $forms = [$one];
        } else {
            $forms = GFAPI::get_forms(true, false);
            $forms = is_array($forms) ? $forms : [];
        }

        $findings = [];

        foreach ($forms as $form) {
            $id = (int) ($form['id'] ?? 0);
            $title = (string) ($form['title'] ?? '');

            $active_notifications = 0;
            foreach ((array) ($form['notifications'] ?? []) as $notification) {
                $notification = (array) $notification;
                if (!array_key_exists('isActive', $notification) || $notification['isActive']) {
                    $active_notifications++;
                }
            }

            if ($active_notifications === 0) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'blocker',
                    'code'     => 'no_active_notification',
                    'message'  => __('No active notification. Entries are stored and nobody is told.', domain: 'nibwp'),
                    'fix'      => __('Add one with nibwp/gravityforms-notifications, or confirm the entries are only read in the admin.', domain: 'nibwp'),
                ];
            }

            if ((array) ($form['confirmations'] ?? []) === []) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'warning',
                    'code'     => 'no_confirmation',
                    'message'  => __('No confirmation, so a visitor gets no acknowledgement after submitting.', domain: 'nibwp'),
                    'fix'      => __('Add one with nibwp/gravityforms-confirmations.', domain: 'nibwp'),
                ];
            }

            if (!empty($form['limitEntries'])) {
                $limit = (int) ($form['limitEntriesCount'] ?? 0);
                $count = (int) GFAPI::count_entries($id, ['status' => 'active']);
                if ($limit > 0 && $count >= $limit) {
                    $findings[] = [
                        'form_id'  => $id,
                        'title'    => $title,
                        'severity' => 'blocker',
                        'code'     => 'entry_limit_reached',
                        'message'  => sprintf(
                            /* translators: 1: entry count, 2: configured limit */
                            __('The entry limit is reached (%1$d of %2$d), so the form no longer accepts submissions.', domain: 'nibwp'),
                            $count,
                            $limit
                        ),
                        'fix'      => __('Raise or remove limitEntriesCount with nibwp/gravityforms-settings.', domain: 'nibwp'),
                    ];
                }
            }

            if (!empty($form['scheduleForm'])) {
                $end = (string) ($form['scheduleEnd'] ?? '');
                if ($end !== '' && strtotime($end) !== false && strtotime($end) < time()) {
                    $findings[] = [
                        'form_id'  => $id,
                        'title'    => $title,
                        'severity' => 'blocker',
                        'code'     => 'schedule_expired',
                        'message'  => __('The scheduled end date has passed, so the form is closed to submissions.', domain: 'nibwp'),
                        'fix'      => __('Change or clear scheduleEnd with nibwp/gravityforms-settings.', domain: 'nibwp'),
                    ];
                }
            }

            if (!empty($form['requireLogin'])) {
                $findings[] = [
                    'form_id'  => $id,
                    'title'    => $title,
                    'severity' => 'note',
                    'code'     => 'requires_login',
                    'message'  => __('Only logged-in users can submit this form.', domain: 'nibwp'),
                    'fix'      => __('Intentional on a members-only form; a mistake on a public contact form.', domain: 'nibwp'),
                ];
            }
        }

        $blockers = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'blocker'));

        return [
            'forms_checked' => count($forms),
            'verdict'  => $blockers === [] ? 'ok' : 'needs_attention',
            'blockers' => $blockers,
            'warnings' => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'notes'    => array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'note')),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 13 — nibwp/gravityforms-delete (destructive)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/gravityforms-delete', [
    'label'       => __('Gravity Forms — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete a form, an entry or a feed. Irreversible.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['delete_form', 'delete_entry', 'delete_feed']],
            'form_id'  => ['type' => 'integer'],
            'entry_id' => ['type' => 'integer'],
            'feed_id'  => ['type' => 'integer'],
            'confirm'  => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_gf_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Prefer trashing. Forms and entries both have a reversible trash state on nibwp/gravityforms-forms and nibwp/gravityforms-entries.',
                'Deleting a form deletes every entry it holds. On a form with real submissions that is customer data, gone.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_gf_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_gf_guard()) {
        return $guard;
    }

    if (!(bool) ($input['confirm'] ?? false)) {
        return nibwp_gf_err(
            'nibwp_gf_unconfirmed',
            __('This permanently destroys data and cannot be undone. Trashing is reversible; if deletion is genuinely intended, re-issue with confirm true.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? '');

    return nibwp_gf_try(static function () use ($action, $input) {
        switch ($action) {
            case 'delete_form':
                $form_id = (int) ($input['form_id'] ?? 0);
                if ($form_id <= 0) {
                    throw new \RuntimeException(__('A valid form ID is required.', domain: 'nibwp'));
                }
                $count = (int) GFAPI::count_entries($form_id);
                $result = GFAPI::delete_form($form_id);

                return is_wp_error($result) ? $result : [
                    'form_id'         => $form_id,
                    'deleted'         => true,
                    'entries_deleted' => $count,
                    'reversible'      => false,
                ];

            case 'delete_entry':
                $entry_id = (int) ($input['entry_id'] ?? 0);
                if ($entry_id <= 0) {
                    throw new \RuntimeException(__('A valid entry ID is required.', domain: 'nibwp'));
                }
                $result = GFAPI::delete_entry($entry_id);

                return is_wp_error($result) ? $result : ['entry_id' => $entry_id, 'deleted' => true, 'reversible' => false];

            case 'delete_feed':
                $feed_id = (int) ($input['feed_id'] ?? 0);
                if ($feed_id <= 0) {
                    throw new \RuntimeException(__('A valid feed ID is required.', domain: 'nibwp'));
                }
                $result = GFAPI::delete_feed($feed_id);

                return is_wp_error($result) ? $result : ['feed_id' => $feed_id, 'deleted' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
