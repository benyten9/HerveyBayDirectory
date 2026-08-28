<?php

declare(strict_types=1);

/**
 * Contact Form 7 integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Ten abilities cover a CF7 install properly: forms, the form-tag template,
 * the fields parsed out of it, both mail templates, the response messages,
 * additional settings, submissions through Flamingo, spam protection, and
 * deletion.
 *
 * WHY A DEDICATED INTEGRATION when the universal `forms` one already lists and
 * reads CF7 forms: because the generic surface stops at the form. It cannot
 * touch the mail template, and in CF7 the mail template IS the form — a
 * contact form whose recipient is wrong collects submissions and delivers them
 * nowhere, and that is by far the most common way a CF7 install fails. Nor can
 * it reach the response messages, the additional settings, or the spam
 * configuration. Those are the parts people actually need changed.
 *
 * Mechanism is IN-PROCESS, through CF7's own API:
 *   wpcf7_contact_form($id)                    load one form
 *   WPCF7_ContactForm::find() / ::get_template() list and scaffold
 *   $form->prop() / set_properties() / save()  read and write properties
 *   $form->scan_form_tags()                    the fields, parsed by CF7
 *   Flamingo_Inbound_Message (flamingo_inbound) stored submissions
 *
 * CF7 stores everything as properties on a `wpcf7_contact_form` post:
 * `form` (the tag markup), `mail`, `mail_2`, `messages`,
 * `additional_settings`. Reading and writing goes through the properties API
 * rather than post meta, so CF7's own sanitising and defaults apply.
 *
 * SUBMISSIONS ARE NOT STORED BY CF7. It emails them and forgets them. Anything
 * that lists past submissions is reading Flamingo, and without Flamingo there
 * is nothing to read — which the abilities say plainly rather than returning an
 * empty list that looks like "no enquiries".
 *
 * Detection: WPCF7_VERSION.
 *
 * Verified against Contact Form 7 6.1.6 source.
 *
 * Two things that verification corrected, both silent rather than fatal:
 * WPCF7_FormTag::$basetype is a public property and not a method, so reading
 * it through method_exists() returned an empty string for every field; and
 * WPCF7_ContactForm::get_template() falls back to determine_locale() only when
 * the locale key is absent, so passing an empty string built forms carrying no
 * locale at all.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Contact Form 7 active? */
function nibwp_cf7_available(): bool
{
    return defined('WPCF7_VERSION') && function_exists('wpcf7_contact_form');
}

/** Is Flamingo present to store submissions? */
function nibwp_cf7_flamingo(): bool
{
    return class_exists('Flamingo_Inbound_Message') || post_type_exists('flamingo_inbound');
}

/** House WP_Error wrapper. */
function nibwp_cf7_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_cf7_guard(): ?WP_Error
{
    if (!nibwp_cf7_available()) {
        return nibwp_cf7_err(
            'nibwp_cf7_missing',
            __('Contact Form 7 is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Run a CF7 call, converting throwables into WP_Error. */
function nibwp_cf7_try(callable $fn, string $code = 'nibwp_cf7_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_cf7_err($code, $e->getMessage());
    }
}

/**
 * Load one contact form.
 *
 * wpcf7_contact_form() returns null for an unknown ID rather than throwing, so
 * the miss is turned into an error a caller can read.
 *
 * @return object|WP_Error
 */
function nibwp_cf7_form(int $id)
{
    if ($id <= 0) {
        return nibwp_cf7_err('nibwp_cf7_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    $form = wpcf7_contact_form($id);

    if (!$form || !is_object($form)) {
        return nibwp_cf7_err('nibwp_cf7_not_found', __('No Contact Form 7 form with that ID.', domain: 'nibwp'));
    }

    return $form;
}

/**
 * Write properties back to a form.
 *
 * set_properties() merges rather than replaces, which is what we want: an
 * agent changing a mail subject should not have to resend the body, the
 * recipient and every header to avoid clearing them.
 *
 * @return true|WP_Error
 */
function nibwp_cf7_save(object $form, array $properties)
{
    if (!method_exists($form, 'set_properties') || !method_exists($form, 'save')) {
        return nibwp_cf7_err(
            'nibwp_cf7_readonly',
            __('This Contact Form 7 version does not expose writing form properties.', domain: 'nibwp')
        );
    }

    try {
        $form->set_properties($properties);
        $form->save();
    } catch (\Throwable $e) {
        return nibwp_cf7_err('nibwp_cf7_save_failed', $e->getMessage());
    }

    return true;
}

/** Read one property, defaulting to an empty array/string rather than null. */
function nibwp_cf7_prop(object $form, string $name, $default = '')
{
    if (!method_exists($form, 'prop')) {
        return $default;
    }

    $value = $form->prop($name);

    return $value === null ? $default : $value;
}

/** Clamp pagination to the house 1..100 window. */
function nibwp_cf7_paginate(array $in): array
{
    $per_page = min(max((int) ($in['per_page'] ?? 20), 1), 100);

    return ['per_page' => $per_page, 'page' => max((int) ($in['page'] ?? 1), 1)];
}

/**
 * The mail keys CF7 understands, so a write cannot invent one.
 *
 * @return list<string>
 */
function nibwp_cf7_mail_keys(): array
{
    return ['active', 'subject', 'sender', 'recipient', 'body', 'additional_headers', 'attachments', 'use_html', 'exclude_blank'];
}

/**
 * Which additional-settings directives stop this form delivering mail.
 *
 * A named function rather than an inline check so the self-check can exercise
 * the shipped rule instead of a copy of it — a test carrying its own regex
 * passes happily while the code beside it drifts.
 *
 * `on`, `true` and `1` are all accepted, because that is exactly what
 * WPCF7_ContactForm::is_true() treats as on. Matching only "on" meant
 * `demo_mode: true` silently stopped delivery with no warning — which is the
 * failure this check exists to catch.
 *
 * @return list<string>
 */
function nibwp_cf7_suppressing_directives(string $settings): array
{
    $found = [];

    foreach (['demo_mode', 'skip_mail'] as $directive) {
        if (preg_match('/^\s*' . $directive . '\s*:\s*(on|true|1)\s*$/mi', $settings)) {
            $found[] = $directive;
        }
    }

    return $found;
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/cf7-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-info', [
    'label'       => __('Contact Form 7 — Info', domain: 'nibwp'),
    'description' => __('Detect Contact Form 7, its version, how many forms exist, whether submissions are being stored, and which spam protections are configured (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. The important field is stores_submissions: Contact Form 7 emails submissions and keeps no copy, so without Flamingo there is no history to read.',
                'If someone asks where their enquiries went and stores_submissions is false, that is the answer.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_cf7_info(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    return nibwp_cf7_try(static function (): array {
        $counts = wp_count_posts('wpcf7_contact_form');

        $recaptcha = false;
        if (class_exists('WPCF7') && method_exists('WPCF7', 'get_option')) {
            $recaptcha = (bool) WPCF7::get_option('recaptcha');
        }

        return [
            'active'             => true,
            'version'            => defined('WPCF7_VERSION') ? WPCF7_VERSION : '',
            'form_count'         => (int) ($counts->publish ?? 0),
            'stores_submissions' => nibwp_cf7_flamingo(),
            'storage'            => nibwp_cf7_flamingo() ? 'flamingo' : 'none',
            'spam'               => [
                'akismet'   => (bool) (defined('AKISMET_VERSION') || class_exists('Akismet')),
                'recaptcha' => $recaptcha,
            ],
            'note' => nibwp_cf7_flamingo()
                ? ''
                : __('Contact Form 7 does not store submissions. Install Flamingo if a record of enquiries is wanted.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/cf7-forms (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-forms', [
    'label'       => __('Contact Form 7 — Forms', domain: 'nibwp'),
    'description' => __('List, read, create, rename and duplicate Contact Form 7 forms, and get the shortcode for embedding one.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'rename', 'duplicate', 'shortcode'], 'description' => 'The action to perform.'],
            'form_id' => ['type' => 'integer', 'description' => 'Form ID. Required for everything except list and create.'],
            'title'   => ['type' => 'string', 'description' => 'Title for create and rename.'],
            'locale'  => ['type' => 'string', 'description' => 'Locale for create, e.g. en_US. Defaults to the site locale.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_forms',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A new form is created from CF7\'s default template, so it already has sensible fields and a working mail template — check both rather than assuming they are what the user wants.',
                'duplicate is the safe way to experiment on a form that is live somewhere.',
                'Deleting lives in nibwp/cf7-delete.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_forms(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');
    $form_id = (int) ($input['form_id'] ?? 0);

    if (in_array($action, ['get', 'rename', 'duplicate', 'shortcode'], strict: true) && $form_id <= 0) {
        return nibwp_cf7_err('nibwp_cf7_bad_id', __('A valid form ID is required.', domain: 'nibwp'));
    }

    return nibwp_cf7_try(static function () use ($action, $form_id, $input) {
        if ($action === 'list') {
            $page = nibwp_cf7_paginate($input);
            $posts = get_posts([
                'post_type'      => 'wpcf7_contact_form',
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
                    'shortcode' => sprintf('[contact-form-7 id="%d" title="%s"]', $post->ID, $post->post_title),
                ];
            }

            return ['forms' => $rows, 'count' => count($rows)];
        }

        if ($action === 'create') {
            if (!class_exists('WPCF7_ContactForm')) {
                throw new \RuntimeException(__('Contact Form 7 classes are unavailable.', domain: 'nibwp'));
            }

            // Built from CF7's own default template so a new form arrives
            // working — fields, mail and messages — rather than as an empty
            // shell that silently sends nothing.
            $args = ['title' => (string) ($input['title'] ?? __('Untitled form', domain: 'nibwp'))];

            // The locale key is omitted rather than passed empty. CF7 falls back
            // to determine_locale() only when the key is NOT SET, and an empty
            // string is set — so passing '' builds a form carrying no locale
            // instead of the site's.
            $locale = trim((string) ($input['locale'] ?? ''));
            if ($locale !== '') {
                $args['locale'] = $locale;
            }

            $form = WPCF7_ContactForm::get_template($args);

            if (!$form || !method_exists($form, 'save')) {
                throw new \RuntimeException(__('Contact Form 7 did not return a usable template.', domain: 'nibwp'));
            }

            $new_id = (int) $form->save();

            return ['form_id' => $new_id, 'created' => true, 'shortcode' => sprintf('[contact-form-7 id="%d"]', $new_id)];
        }

        $form = nibwp_cf7_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        switch ($action) {
            case 'get':
                return [
                    'form_id'  => $form_id,
                    'title'    => method_exists($form, 'title') ? $form->title() : '',
                    'locale'   => method_exists($form, 'locale') ? $form->locale() : '',
                    'shortcode' => method_exists($form, 'shortcode') ? $form->shortcode() : '',
                    'properties' => [
                        'form'                => nibwp_cf7_prop($form, 'form'),
                        'mail'                => nibwp_cf7_prop($form, 'mail', []),
                        'mail_2'              => nibwp_cf7_prop($form, 'mail_2', []),
                        'messages'            => nibwp_cf7_prop($form, 'messages', []),
                        'additional_settings' => nibwp_cf7_prop($form, 'additional_settings'),
                    ],
                ];

            case 'rename':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new \RuntimeException(__('A title is required.', domain: 'nibwp'));
                }
                $updated = wp_update_post(['ID' => $form_id, 'post_title' => $title], true);
                if (is_wp_error($updated)) {
                    return $updated;
                }

                return ['form_id' => $form_id, 'title' => $title, 'renamed' => true];

            case 'duplicate':
                if (!method_exists($form, 'copy')) {
                    throw new \RuntimeException(__('This Contact Form 7 version does not expose duplicating a form.', domain: 'nibwp'));
                }
                $copy = $form->copy();
                $new_id = (int) $copy->save();

                return ['form_id' => $new_id, 'duplicated_from' => $form_id];

            case 'shortcode':
                return [
                    'form_id'   => $form_id,
                    'shortcode' => method_exists($form, 'shortcode') ? $form->shortcode() : sprintf('[contact-form-7 id="%d"]', $form_id),
                ];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/cf7-template (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-template', [
    'label'       => __('Contact Form 7 — Form template', domain: 'nibwp'),
    'description' => __('Read and replace the form-tag markup that defines a form\'s fields and layout.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'set', 'default'], 'default' => 'get'],
            'form_id' => ['type' => 'integer', 'description' => 'Form ID. Required for get and set.'],
            'form'    => ['type' => 'string', 'description' => 'set: the complete form-tag markup.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_template',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This markup is the form. CF7 tags look like [text* your-name] and [email* your-email] — the asterisk marks a required field.',
                'set REPLACES the whole template. Read it first, and keep the field NAMES unless you also update the mail template: mail bodies reference fields by name, so renaming one silently empties it from the email.',
                'action=default returns CF7\'s own starter markup, useful as a reference for correct syntax.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_template(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');
    $form_id = (int) ($input['form_id'] ?? 0);

    return nibwp_cf7_try(static function () use ($action, $form_id, $input) {
        if ($action === 'default') {
            $default = class_exists('WPCF7_ContactFormTemplate')
                ? WPCF7_ContactFormTemplate::get_default('form')
                : '';

            return ['form' => $default];
        }

        $form = nibwp_cf7_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        if ($action === 'set') {
            if (!array_key_exists('form', $input)) {
                throw new \RuntimeException(__('The form markup is required.', domain: 'nibwp'));
            }

            $before = (string) nibwp_cf7_prop($form, 'form');
            $saved = nibwp_cf7_save($form, ['form' => (string) $input['form']]);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            // Field names are the join between the template and the mail body,
            // so a rename that the mail template does not know about is
            // reported rather than left to be discovered in an empty email.
            $lost = array_values(array_diff(
                nibwp_cf7_tag_names($before),
                nibwp_cf7_tag_names((string) $input['form'])
            ));

            return [
                'form_id' => $form_id,
                'updated' => true,
                'removed_fields' => $lost,
                'warning' => $lost === []
                    ? ''
                    : __('Fields disappeared from the template. Any mail template referencing them by name will now send that line empty — check nibwp/cf7-mail.', domain: 'nibwp'),
            ];
        }

        return ['form_id' => $form_id, 'form' => nibwp_cf7_prop($form, 'form')];
    });
}

/** Field names appearing in CF7 form-tag markup. */
function nibwp_cf7_tag_names(string $markup): array
{
    if (!preg_match_all('/\[[a-zA-Z0-9_]+\*?\s+([a-zA-Z0-9_\-]+)/', $markup, $matches)) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/cf7-fields (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-fields', [
    'label'       => __('Contact Form 7 — Fields', domain: 'nibwp'),
    'description' => __('The fields of a form as Contact Form 7 itself parses them — name, type, whether required, and any options (read-only).', domain: 'nibwp'),
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
    'execute_callback'    => 'nibwp_cf7_fields',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read this before writing a mail template — these are the exact names a mail body can reference with [square-bracket] placeholders. Guessing a name produces an empty line in every email.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_cf7_fields(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $form = nibwp_cf7_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_cf7_try(static function () use ($form, $input) {
        $fields = [];

        if (method_exists($form, 'scan_form_tags')) {
            foreach ((array) $form->scan_form_tags() as $tag) {
                $name = is_object($tag) ? (string) ($tag->name ?? '') : '';
                if ($name === '') {
                    continue;
                }

                $fields[] = [
                    'name'     => $name,
                    'type'     => is_object($tag) ? (string) ($tag->type ?? '') : '',
                    // basetype is a public PROPERTY on WPCF7_FormTag, not a
                    // method — reading it through method_exists() returns an
                    // empty string for every field, which is what this did
                    // until CF7 was available to check against.
                    'basetype' => is_object($tag) ? (string) ($tag->basetype ?? '') : '',
                    'required' => is_object($tag) && method_exists($tag, 'is_required') ? (bool) $tag->is_required() : false,
                    'options'  => is_object($tag) ? (array) ($tag->options ?? []) : [],
                    'values'   => is_object($tag) ? (array) ($tag->values ?? []) : [],
                ];
            }
        }

        if ($fields === []) {
            // Falling back to the markup rather than reporting "no fields",
            // which would be wrong on any CF7 version whose tag API differs.
            foreach (nibwp_cf7_tag_names((string) nibwp_cf7_prop($form, 'form')) as $name) {
                $fields[] = ['name' => $name, 'type' => '', 'basetype' => '', 'required' => false, 'options' => [], 'values' => []];
            }
        }

        return [
            'form_id' => (int) ($input['form_id'] ?? 0),
            'fields'  => $fields,
            'count'   => count($fields),
            'mail_placeholders' => array_map(static fn(array $f): string => '[' . $f['name'] . ']', $fields),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/cf7-mail (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-mail', [
    'label'       => __('Contact Form 7 — Mail templates', domain: 'nibwp'),
    'description' => __('Read and change what a form sends on submit: recipient, sender, subject, body, headers and attachments, for both the main mail and the autoresponder.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id' => ['type' => 'integer'],
            'which'   => ['type' => 'string', 'enum' => ['mail', 'mail_2'], 'default' => 'mail', 'description' => 'mail is the notification to you; mail_2 is the autoresponder to the sender.'],
            'values'  => ['type' => 'object', 'description' => 'set: any of active, recipient, sender, subject, body, additional_headers, attachments, use_html, exclude_blank. Merged with what is there.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_mail',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This is the part that decides whether anyone learns about a submission. A form with a wrong recipient looks like it works and delivers nowhere.',
                'Bodies reference fields by name in square brackets — [your-name], [your-email]. Read nibwp/cf7-fields first; a name that does not exist produces an empty line, not an error.',
                'mail_2 is the autoresponder and is off by default. Switching it on emails whoever filled the form, so confirm before enabling it on a live site.',
                'Values are merged, so send only what changes.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_mail(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $form = nibwp_cf7_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    $which = (string) ($input['which'] ?? 'mail');
    if (!in_array($which, ['mail', 'mail_2'], strict: true)) {
        return nibwp_cf7_err('nibwp_cf7_bad_mail', __('which must be mail or mail_2.', domain: 'nibwp'));
    }

    $action = (string) ($input['action'] ?? 'get');

    return nibwp_cf7_try(static function () use ($form, $which, $action, $input) {
        $current = (array) nibwp_cf7_prop($form, $which, []);

        if ($action === 'set') {
            $values = (array) ($input['values'] ?? []);
            if ($values === []) {
                throw new \RuntimeException(__('Nothing to set.', domain: 'nibwp'));
            }

            $allowed = nibwp_cf7_mail_keys();
            $unknown = array_values(array_diff(array_keys($values), $allowed));
            if ($unknown !== []) {
                throw new \RuntimeException(sprintf(
                    /* translators: 1: unknown keys, 2: the keys CF7 accepts */
                    __('Contact Form 7 has no mail key %1$s. Accepted keys: %2$s', domain: 'nibwp'),
                    implode(', ', $unknown),
                    implode(', ', $allowed)
                ));
            }

            $merged = array_merge($current, $values);
            $saved = nibwp_cf7_save($form, [$which => $merged]);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            // Placeholders that name a field the form does not have are the
            // quiet failure here: the email sends, the line is blank.
            $known = nibwp_cf7_tag_names((string) nibwp_cf7_prop($form, 'form'));
            $used = [];
            preg_match_all('/\[([a-zA-Z0-9_\-]+)\]/', (string) ($merged['body'] ?? '') . ' ' . (string) ($merged['subject'] ?? ''), $matches);
            foreach ($matches[1] as $placeholder) {
                // CF7's own special tags are legitimate and not form fields.
                if (str_starts_with($placeholder, '_')) {
                    continue;
                }
                if (!in_array($placeholder, $known, strict: true)) {
                    $used[] = $placeholder;
                }
            }

            return [
                'form_id' => (int) $input['form_id'],
                'which'   => $which,
                'updated' => true,
                'unknown_placeholders' => array_values(array_unique($used)),
                'warning' => $used === []
                    ? ''
                    : __('The template references names this form has no field for. Those lines will arrive empty.', domain: 'nibwp'),
            ];
        }

        return [
            'form_id'      => (int) $input['form_id'],
            'which'        => $which,
            'mail'         => $current,
            'active'       => (bool) ($current['active'] ?? ($which === 'mail')),
            'accepted_keys' => nibwp_cf7_mail_keys(),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/cf7-messages (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-messages', [
    'label'       => __('Contact Form 7 — Messages', domain: 'nibwp'),
    'description' => __('Read and change the messages a form shows: success, validation errors, spam refusal and the rest.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id'  => ['type' => 'integer'],
            'messages' => ['type' => 'object', 'description' => 'set: message key to text. Merged with what is there.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_messages',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Read them before writing — the keys vary by CF7 version, and an invented key is stored and never shown. Messages are user-facing copy, so match the site\'s tone rather than leaving CF7\'s defaults if the rest of the site has a voice.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_messages(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $form = nibwp_cf7_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_cf7_try(static function () use ($form, $input) {
        $current = (array) nibwp_cf7_prop($form, 'messages', []);

        if ((string) ($input['action'] ?? 'get') === 'set') {
            $messages = (array) ($input['messages'] ?? []);
            if ($messages === []) {
                throw new \RuntimeException(__('Nothing to set.', domain: 'nibwp'));
            }

            $unknown = $current === [] ? [] : array_values(array_diff(array_keys($messages), array_keys($current)));

            $saved = nibwp_cf7_save($form, ['messages' => array_merge($current, $messages)]);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            return [
                'form_id' => (int) $input['form_id'],
                'updated' => true,
                'unknown_keys' => $unknown,
                'warning' => $unknown === []
                    ? ''
                    : __('Some keys are not ones this Contact Form 7 version uses. They are stored but will never be shown.', domain: 'nibwp'),
            ];
        }

        return ['form_id' => (int) $input['form_id'], 'messages' => $current, 'count' => count($current)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/cf7-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-settings', [
    'label'       => __('Contact Form 7 — Additional settings', domain: 'nibwp'),
    'description' => __('Read and change a form\'s additional settings — demo mode, skipping mail, subscribers only, and any custom directives.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'form_id'  => ['type' => 'integer'],
            'settings' => ['type' => 'string', 'description' => 'set: the complete settings block, one directive per line.'],
        ],
        'required' => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Two directives here change whether mail is delivered at all: demo_mode: on and skip_mail: on. Both are useful while testing and disastrous if left behind.',
                'set REPLACES the whole block. Read it first.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $form = nibwp_cf7_form((int) ($input['form_id'] ?? 0));
    if ($form instanceof WP_Error) {
        return $form;
    }

    return nibwp_cf7_try(static function () use ($form, $input) {
        $current = (string) nibwp_cf7_prop($form, 'additional_settings');

        if ((string) ($input['action'] ?? 'get') === 'set') {
            if (!array_key_exists('settings', $input)) {
                throw new \RuntimeException(__('The settings block is required.', domain: 'nibwp'));
            }

            $next = (string) $input['settings'];
            $saved = nibwp_cf7_save($form, ['additional_settings' => $next]);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            $suppressed = nibwp_cf7_suppressing_directives($next);

            return [
                'form_id' => (int) $input['form_id'],
                'updated' => true,
                'mail_suppressed_by' => $suppressed,
                'warning' => $suppressed === []
                    ? ''
                    : __('This form will no longer deliver mail. Tell the user, and remove the directive when testing is done.', domain: 'nibwp'),
            ];
        }

        $active = [];
        foreach (preg_split('/\r\n|\r|\n/', $current) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $active[] = $line;
            }
        }

        return ['form_id' => (int) $input['form_id'], 'additional_settings' => $current, 'directives' => $active];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/cf7-submissions (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-submissions', [
    'label'       => __('Contact Form 7 — Submissions', domain: 'nibwp'),
    'description' => __('List and read stored submissions through Flamingo, and mark them spam or not spam.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['list', 'get', 'mark_spam', 'mark_ham'], 'default' => 'list'],
            'submission_id' => ['type' => 'integer', 'description' => 'Required for everything except list.'],
            'search'        => ['type' => 'string', 'description' => 'list: free-text search.'],
            'per_page'      => ['type' => 'integer', 'default' => 20],
            'page'          => ['type' => 'integer', 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_submissions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Contact Form 7 does not store submissions. This reads Flamingo, and without Flamingo there is nothing to read — which is reported as such rather than as an empty list.',
                'Submissions are personal data. Read what the task needs and do not copy it somewhere it was not collected for.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_submissions(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    if (!nibwp_cf7_flamingo()) {
        return nibwp_cf7_err(
            'nibwp_cf7_no_storage',
            __('Contact Form 7 does not keep submissions, and Flamingo is not installed, so there is no history to read. Install Flamingo to start storing them — it cannot recover enquiries already sent.', domain: 'nibwp')
        );
    }

    $action = (string) ($input['action'] ?? 'list');
    $submission_id = (int) ($input['submission_id'] ?? 0);

    if ($action !== 'list' && $submission_id <= 0) {
        return nibwp_cf7_err('nibwp_cf7_bad_id', __('A valid submission ID is required.', domain: 'nibwp'));
    }

    return nibwp_cf7_try(static function () use ($action, $submission_id, $input) {
        if ($action === 'list') {
            $page = nibwp_cf7_paginate($input);
            $posts = get_posts([
                'post_type'      => 'flamingo_inbound',
                'post_status'    => 'any',
                'posts_per_page' => $page['per_page'],
                'paged'          => $page['page'],
                's'              => (string) ($input['search'] ?? ''),
            ]);

            $rows = [];
            foreach ($posts as $post) {
                $rows[] = [
                    'id'      => (int) $post->ID,
                    'subject' => $post->post_title,
                    'date'    => $post->post_date_gmt,
                    'status'  => $post->post_status,
                ];
            }

            return ['submissions' => $rows, 'count' => count($rows)];
        }

        $post = get_post($submission_id);
        if (!$post || $post->post_type !== 'flamingo_inbound') {
            throw new \RuntimeException(__('No such submission.', domain: 'nibwp'));
        }

        if ($action === 'get') {
            $fields = [];
            foreach (get_post_meta($submission_id) as $key => $value) {
                if (str_starts_with($key, '_field_')) {
                    $fields[substr($key, 7)] = maybe_unserialize($value[0] ?? '');
                }
            }

            return [
                'id'      => $submission_id,
                'subject' => $post->post_title,
                'date'    => $post->post_date_gmt,
                'fields'  => $fields,
                'body'    => $post->post_content,
            ];
        }

        // Flamingo models spam as a post status, so this is a status change
        // rather than a meta flag.
        $status = $action === 'mark_spam' ? 'spam' : 'publish';
        wp_update_post(['ID' => $submission_id, 'post_status' => $status]);

        return ['id' => $submission_id, 'status' => $status];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/cf7-spam (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-spam', [
    'label'       => __('Contact Form 7 — Spam protection', domain: 'nibwp'),
    'description' => __('Report what is protecting a form from spam: Akismet field mapping, reCAPTCHA or Turnstile configuration, and whether a form has any protection at all (read-only).', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'form_id' => ['type' => 'integer', 'description' => 'Check one form. Omit for the site-wide picture.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_spam',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'An unprotected public contact form fills with spam within days. If nothing is configured, say so — it is one of the few things worth raising unprompted.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_cf7_spam(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    return nibwp_cf7_try(static function () use ($input) {
        $recaptcha = false;
        $turnstile = false;

        if (class_exists('WPCF7') && method_exists('WPCF7', 'get_option')) {
            $recaptcha = (bool) WPCF7::get_option('recaptcha');
            $turnstile = (bool) WPCF7::get_option('turnstile');
        }

        $akismet_active = defined('AKISMET_VERSION') || class_exists('Akismet');

        $out = [
            'site' => [
                'akismet'   => $akismet_active,
                'recaptcha' => $recaptcha,
                'turnstile' => $turnstile,
            ],
        ];

        $form_id = (int) ($input['form_id'] ?? 0);
        if ($form_id <= 0) {
            return $out;
        }

        $form = nibwp_cf7_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $markup = (string) nibwp_cf7_prop($form, 'form');

        // Akismet only inspects fields that opt in via akismet: options in the
        // tag, so an active Akismet with no mapping protects nothing.
        $akismet_mapped = (bool) preg_match('/akismet:(author|author_email|author_url)/', $markup);
        $has_captcha = (bool) preg_match('/\[(recaptcha|turnstile|cfturnstile)/', $markup);

        $protected = ($akismet_active && $akismet_mapped) || $has_captcha || $recaptcha || $turnstile;

        $out['form'] = [
            'form_id'        => $form_id,
            'akismet_mapped' => $akismet_mapped,
            'captcha_tag'    => $has_captcha,
            'protected'      => $protected,
        ];

        if (!$protected) {
            $out['form']['warning'] = __('This form has no spam protection. Map Akismet fields in the form tags, or add a CAPTCHA.', domain: 'nibwp');
        } elseif ($akismet_active && !$akismet_mapped) {
            $out['form']['warning'] = __('Akismet is active but this form maps no fields to it, so Akismet never sees the submission.', domain: 'nibwp');
        }

        return $out;
    });
}

/* ----------------------------------------------------------------------------
 * Ability 10 — nibwp/cf7-delete (destructive)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/cf7-delete', [
    'label'       => __('Contact Form 7 — Delete', domain: 'nibwp'),
    'description' => __('Permanently delete a form or a stored submission. Irreversible.', domain: 'nibwp'),
    'category'    => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['delete_form', 'delete_submission']],
            'form_id'       => ['type' => 'integer'],
            'submission_id' => ['type' => 'integer'],
            'confirm'       => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_cf7_delete',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Contact Form 7 has no trash for forms — deleting one is final, and every page embedding its shortcode will render nothing.',
                'Check where a form is used before deleting it. Duplicating is the safe way to experiment.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_cf7_delete(array $input): array|WP_Error
{
    if ($guard = nibwp_cf7_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? '');

    if (!(bool) ($input['confirm'] ?? false)) {
        return nibwp_cf7_err(
            'nibwp_cf7_unconfirmed',
            __('This permanently destroys data and cannot be undone. Re-issue with confirm true if that is intended.', domain: 'nibwp')
        );
    }

    return nibwp_cf7_try(static function () use ($action, $input) {
        if ($action === 'delete_form') {
            $form_id = (int) ($input['form_id'] ?? 0);
            $form = nibwp_cf7_form($form_id);
            if ($form instanceof WP_Error) {
                return $form;
            }

            if (method_exists($form, 'delete')) {
                $form->delete();
            } else {
                wp_delete_post($form_id, true);
            }

            return ['form_id' => $form_id, 'deleted' => true, 'reversible' => false];
        }

        if ($action === 'delete_submission') {
            $submission_id = (int) ($input['submission_id'] ?? 0);
            if ($submission_id <= 0) {
                throw new \RuntimeException(__('A valid submission ID is required.', domain: 'nibwp'));
            }
            wp_delete_post($submission_id, true);

            return ['submission_id' => $submission_id, 'deleted' => true, 'reversible' => false];
        }

        throw new \RuntimeException(__('Unknown action.', domain: 'nibwp'));
    });
}
