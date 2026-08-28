<?php

declare(strict_types=1);

/**
 * Weglot integration — NIBWP MCP abilities (Layer 1 adapter).
 *
 * Twelve abilities cover translating a WordPress site with Weglot: languages,
 * settings, the switcher, exclusions, translated slugs, per-page publication,
 * media and email translation, plan and quota, a readiness audit, and a guided
 * workflow that reads the site's current state and says what to do next.
 *
 * WHAT WEGLOT ACTUALLY IS matters for what these abilities can do. Translation
 * happens in Weglot's cloud, not in WordPress: the plugin proxies a page
 * through Weglot's API and swaps the strings on the way out. So there are no
 * translated strings sitting in the database to read or edit here. What lives
 * locally is configuration — which languages, which URLs and blocks to leave
 * alone, how the switcher looks, whether slugs are translated.
 *
 * That shapes the whole integration: it is a configuration and diagnosis
 * surface, not a content one. The value is in getting the configuration right,
 * because the failures are quiet — a missing hreflang tag costs search
 * rankings, an unexcluded code block gets translated into nonsense, and a
 * language added carelessly consumes plan quota nobody budgeted for.
 *
 * Mechanism is IN-PROCESS, through Weglot's own services:
 *   weglot_get_service('Option_Service_Weglot')   settings, exclusions, slugs
 *   weglot_get_service('Language_Service_Weglot') languages
 *   weglot_get_service('User_Api_Service_Weglot') plan, workspace, quota
 * plus the weglot_get_* helper functions for the common reads.
 *
 * Detection: weglot_get_options() + weglot_get_service().
 *
 * Verified against Weglot 6.2 source.
 */

if (!defined('ABSPATH')) {
    exit();
}

/* ----------------------------------------------------------------------------
 * Shared helpers (file-local)
 * ------------------------------------------------------------------------- */

/** Is Weglot active on this site? */
function nibwp_weglot_available(): bool
{
    return function_exists('weglot_get_options') && function_exists('weglot_get_service');
}

/** House WP_Error wrapper. */
function nibwp_weglot_err(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message);
}

/** The guard every callback opens with. */
function nibwp_weglot_guard(): ?WP_Error
{
    if (!nibwp_weglot_available()) {
        return nibwp_weglot_err(
            'nibwp_weglot_missing',
            __('Weglot is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** Run a Weglot call, converting throwables into WP_Error. */
function nibwp_weglot_try(callable $fn, string $code = 'nibwp_weglot_failed')
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return nibwp_weglot_err($code, $e->getMessage());
    }
}

/**
 * One of Weglot's services.
 *
 * @return object|null
 */
function nibwp_weglot_service(string $name)
{
    if (!function_exists('weglot_get_service')) {
        return null;
    }

    try {
        return weglot_get_service($name);
    } catch (\Throwable $e) {
        return null;
    }
}

/** Is an API key configured? Without one nothing translates. */
function nibwp_weglot_has_key(): bool
{
    if (!function_exists('weglot_get_api_key')) {
        return false;
    }

    try {
        return trim((string) weglot_get_api_key()) !== '';
    } catch (\Throwable $e) {
        return false;
    }
}

/** Every option, or an empty array when Weglot cannot answer. */
function nibwp_weglot_options(): array
{
    if (!function_exists('weglot_get_options')) {
        return [];
    }

    try {
        return (array) weglot_get_options();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Destination language codes, normalised to plain strings.
 *
 * Weglot returns these as objects in some versions and arrays in others, and a
 * caller comparing them against a code should not have to care which.
 *
 * @return list<string>
 */
function nibwp_weglot_destination_codes(): array
{
    $codes = [];

    if (function_exists('weglot_get_destination_languages')) {
        try {
            foreach ((array) weglot_get_destination_languages() as $language) {
                $codes[] = nibwp_weglot_language_code($language);
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    return array_values(array_filter(array_unique($codes)));
}

/** Pull a language code out of whatever shape Weglot handed back. */
function nibwp_weglot_language_code($language): string
{
    if (is_string($language)) {
        return $language;
    }

    if (is_array($language)) {
        return (string) ($language['language_to'] ?? ($language['code'] ?? ($language['external_code'] ?? '')));
    }

    if (is_object($language)) {
        foreach (['getExternalCode', 'getInternalCode', 'getCode'] as $method) {
            if (method_exists($language, $method)) {
                $value = $language->{$method}();
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        foreach (['language_to', 'code', 'external_code'] as $property) {
            if (isset($language->{$property}) && is_string($language->{$property})) {
                return $language->{$property};
            }
        }
    }

    return '';
}

/** The original language code. */
function nibwp_weglot_original_code(): string
{
    if (!function_exists('weglot_get_original_language')) {
        return '';
    }

    try {
        return nibwp_weglot_language_code(weglot_get_original_language());
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Write one option, locally and to the Weglot workspace.
 *
 * Weglot keeps the same settings in two places and reads whichever it trusts
 * for a given field, so writing only the local copy produces a change that
 * appears in the admin and does nothing on the front end. Both are written,
 * and the push is reported separately because it can fail on its own.
 *
 * @return array{local:bool, pushed:bool, message:string}
 */
function nibwp_weglot_write_option(string $key, $value): array
{
    $service = nibwp_weglot_service('Option_Service_Weglot');

    if ($service === null || !method_exists($service, 'set_option_by_key')) {
        return ['local' => false, 'pushed' => false, 'message' => __('This Weglot version does not expose option writing.', domain: 'nibwp')];
    }

    $local = false;
    $pushed = false;
    $message = '';

    try {
        $service->set_option_by_key($key, $value);
        $local = true;
    } catch (\Throwable $e) {
        return ['local' => false, 'pushed' => false, 'message' => $e->getMessage()];
    }

    if (method_exists($service, 'save_options_to_weglot')) {
        try {
            $service->save_options_to_weglot();
            $pushed = true;
        } catch (\Throwable $e) {
            $message = sprintf(
                /* translators: %s: error from Weglot */
                __('Saved locally, but pushing to your Weglot workspace failed: %s', domain: 'nibwp'),
                $e->getMessage()
            );
        }
    }

    return ['local' => $local, 'pushed' => $pushed, 'message' => $message];
}

/* ----------------------------------------------------------------------------
 * Ability 1 — nibwp/weglot-info (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-info', [
    'label'       => __('Weglot — Info', domain: 'nibwp'),
    'description' => __('Detect Weglot, whether an API key is configured, the original and destination languages, the translation engine, and whether the site is actually translating (read-only).', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Call this first. Without an API key nothing translates, no matter what else is configured.',
                'Weglot translates in its cloud, not in WordPress — there are no translated strings in the database to read or edit. What you can change here is configuration.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_weglot_info(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    return nibwp_weglot_try(static function (): array {
        $options = nibwp_weglot_options();
        $service = nibwp_weglot_service('Option_Service_Weglot');

        $engine = '';
        if ($service !== null && method_exists($service, 'get_translation_engine')) {
            $engine = (string) $service->get_translation_engine();
        }

        return [
            'active'       => true,
            'version'      => defined('WEGLOT_VERSION') ? WEGLOT_VERSION : '',
            'api_key_set'  => nibwp_weglot_has_key(),
            'translating'  => (bool) ($options['active_translation'] ?? false) && nibwp_weglot_has_key(),
            'original_language'     => nibwp_weglot_original_code(),
            'destination_languages' => nibwp_weglot_destination_codes(),
            'translation_engine'    => $engine,
            'translate_slugs'       => (bool) ($options['active_slugs'] ?? false),
            'hreflang'              => (bool) ($options['add_hreflang'] ?? true),
            'auto_redirect'         => function_exists('weglot_has_auto_redirect') ? (bool) weglot_has_auto_redirect() : null,
            'excluded_urls'         => count((array) ($options['exclude_urls'] ?? [])),
            'excluded_blocks'       => count((array) ($options['exclude_blocks'] ?? [])),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 2 — nibwp/weglot-languages (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-languages', [
    'label'       => __('Weglot — Languages', domain: 'nibwp'),
    'description' => __('List the languages Weglot supports, read which are configured on this site, and add a destination language.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['configured', 'available', 'add'], 'default' => 'configured'],
            'code'     => ['type' => 'string', 'description' => 'Language code to add, e.g. fr, de, es. Required for add.'],
            'search'   => ['type' => 'string', 'description' => 'available: filter the supported list.'],
            'confirm'  => ['type' => 'boolean', 'default' => false, 'description' => 'add: must be true. A new language consumes plan quota.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_languages',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Adding a language is not free. Weglot bills by translated words across all languages, so each one multiplies usage against the plan — check nibwp/weglot-plan first and tell the user what it will cost them.',
                'Adding is confirm-gated for that reason.',
                'Removing a language is not offered here: it discards the translations already paid for, and belongs in the Weglot dashboard where the consequences are spelled out.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_languages(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'configured');

    return nibwp_weglot_try(static function () use ($action, $input) {
        $service = nibwp_weglot_service('Language_Service_Weglot');

        if ($action === 'available') {
            if ($service === null || !method_exists($service, 'get_languages_available')) {
                throw new \RuntimeException(__('This Weglot version does not expose the language list.', domain: 'nibwp'));
            }

            $search = strtolower((string) ($input['search'] ?? ''));
            $rows = [];

            foreach ((array) $service->get_languages_available() as $language) {
                $code = nibwp_weglot_language_code($language);
                $name = is_object($language) && method_exists($language, 'getLocalName')
                    ? (string) $language->getLocalName()
                    : (is_array($language) ? (string) ($language['name'] ?? '') : '');

                if ($search !== '' && !str_contains(strtolower($code . ' ' . $name), $search)) {
                    continue;
                }

                $rows[] = ['code' => $code, 'name' => $name];
            }

            return ['languages' => $rows, 'count' => count($rows)];
        }

        if ($action === 'add') {
            $code = trim((string) ($input['code'] ?? ''));
            if ($code === '') {
                throw new \RuntimeException(__('A language code is required.', domain: 'nibwp'));
            }

            if (!(bool) ($input['confirm'] ?? false)) {
                throw new \RuntimeException(__('Adding a language increases translated word usage against the plan. Re-issue with confirm true once the user has agreed.', domain: 'nibwp'));
            }

            if (in_array($code, nibwp_weglot_destination_codes(), strict: true)) {
                return ['code' => $code, 'added' => false, 'note' => __('That language is already configured.', domain: 'nibwp')];
            }

            if ($service === null || !method_exists($service, 'add_language')) {
                throw new \RuntimeException(__('This Weglot version does not expose adding a language.', domain: 'nibwp'));
            }

            $service->add_language($code);

            return [
                'code'  => $code,
                'added' => true,
                'destination_languages' => nibwp_weglot_destination_codes(),
                'note'  => __('Weglot will begin translating into this language. Watch the word count against the plan.', domain: 'nibwp'),
            ];
        }

        return [
            'original'    => nibwp_weglot_original_code(),
            'destination' => nibwp_weglot_destination_codes(),
            'count'       => count(nibwp_weglot_destination_codes()),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 3 — nibwp/weglot-settings (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-settings', [
    'label'       => __('Weglot — Settings', domain: 'nibwp'),
    'description' => __('Read every Weglot option, and change the ones that matter for translation quality and SEO: hreflang tags, translated slugs, automatic redirection, private-mode and search-engine indexing.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'key'    => ['type' => 'string', 'description' => 'set: the option to change. get: optionally one option instead of all.'],
            'value'  => ['description' => 'set: the new value.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_settings',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Settings live both locally and in the Weglot workspace. This writes both — a local-only change shows in the admin and does nothing on the front end.',
                'The options worth knowing: add_hreflang (SEO, leave on), active_slugs (translate URLs), allow_private, exclude_urls, exclude_blocks.',
                'Read before writing. A blind write to a key you did not read is how a working configuration becomes a broken one.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_settings(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');

    return nibwp_weglot_try(static function () use ($action, $input) {
        if ($action === 'set') {
            $key = trim((string) ($input['key'] ?? ''));
            if ($key === '') {
                throw new \RuntimeException(__('An option key is required.', domain: 'nibwp'));
            }
            if (!array_key_exists('value', $input)) {
                throw new \RuntimeException(__('A value is required.', domain: 'nibwp'));
            }

            $before = nibwp_weglot_options()[$key] ?? null;
            $result = nibwp_weglot_write_option($key, $input['value']);

            return [
                'key'     => $key,
                'before'  => $before,
                'after'   => $input['value'],
                'saved'   => $result['local'],
                'pushed_to_weglot' => $result['pushed'],
                'note'    => $result['message'],
            ];
        }

        $options = nibwp_weglot_options();
        $key = trim((string) ($input['key'] ?? ''));

        if ($key !== '') {
            return ['key' => $key, 'value' => $options[$key] ?? null, 'exists' => array_key_exists($key, $options)];
        }

        return ['options' => $options, 'count' => count($options)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 4 — nibwp/weglot-exclusions (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-exclusions', [
    'label'       => __('Weglot — Exclusions', domain: 'nibwp'),
    'description' => __('Read and set what Weglot must leave alone: URLs that should not be translated, and CSS selectors for blocks such as code samples, brand names and product codes.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['get', 'set_urls', 'set_blocks'], 'default' => 'get'],
            'urls'      => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'set_urls: the complete list, in the shape action=get returns.'],
            'selectors' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'set_blocks: CSS selectors to leave untranslated, e.g. ".hljs", "code", ".brand-name".'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_exclusions',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'This is the highest-value setting on a technical site and the most often forgotten. Untranslated code samples stay correct; translated ones become nonsense that someone will paste and run.',
                'Worth excluding almost always: code and pre blocks, syntax-highlighted regions, brand and product names, SKUs, addresses, and anything rendered from a third-party embed.',
                'Both set actions REPLACE the list. Read it first and send the whole thing back with your addition.',
                'Excluding also saves quota — an excluded block is not translated and not billed.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_exclusions(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');

    return nibwp_weglot_try(static function () use ($action, $input) {
        $options = nibwp_weglot_options();

        if ($action === 'set_urls') {
            $urls = array_values((array) ($input['urls'] ?? []));
            $result = nibwp_weglot_write_option('exclude_urls', $urls);

            return ['exclude_urls' => $urls, 'count' => count($urls), 'saved' => $result['local'], 'pushed_to_weglot' => $result['pushed'], 'note' => $result['message']];
        }

        if ($action === 'set_blocks') {
            $selectors = array_values(array_filter(array_map('strval', (array) ($input['selectors'] ?? []))));

            // Weglot stores these as a list of {value: selector} rows in most
            // versions; the shape already on the site is followed rather than
            // assumed, so an existing configuration is not reshaped underneath it.
            $existing = (array) ($options['exclude_blocks'] ?? []);
            $wrapped = $existing !== [] && is_array(reset($existing));

            $payload = $wrapped
                ? array_map(static fn(string $s): array => ['value' => $s], $selectors)
                : $selectors;

            $result = nibwp_weglot_write_option('exclude_blocks', $payload);

            return ['exclude_blocks' => $selectors, 'count' => count($selectors), 'saved' => $result['local'], 'pushed_to_weglot' => $result['pushed'], 'note' => $result['message']];
        }

        return [
            'exclude_urls'   => (array) ($options['exclude_urls'] ?? []),
            'exclude_blocks' => (array) ($options['exclude_blocks'] ?? []),
            'suggestions'    => [
                'code', 'pre', '.hljs', '.wp-block-code', '.brand', '[data-no-translate]',
            ],
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 5 — nibwp/weglot-switcher (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-switcher', [
    'label'       => __('Weglot — Language switcher', domain: 'nibwp'),
    'description' => __('Read and change the language switcher: its style, flags, placement and custom CSS.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'button' => ['type' => 'object', 'description' => 'set: the button option object, in the shape action=get returns.'],
            'css'    => ['type' => 'string', 'description' => 'set: custom CSS for the switcher.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_switcher',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'A translated site with no visible switcher is a site nobody knows is translated. Check one is reachable on every page, not only the home page.',
                'Flags are not languages — Spanish is not one country and English is not one flag. Prefer language names where the audience spans regions.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_switcher(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');

    return nibwp_weglot_try(static function () use ($action, $input) {
        $service = nibwp_weglot_service('Option_Service_Weglot');

        if ($action === 'set') {
            $written = [];

            if (isset($input['button']) && is_array($input['button'])) {
                $written['button'] = nibwp_weglot_write_option('button_style', $input['button']);
            }
            if (isset($input['css'])) {
                $written['css'] = nibwp_weglot_write_option('css_custom_inline', (string) $input['css']);
            }

            if ($written === []) {
                throw new \RuntimeException(__('Give either a button object or css.', domain: 'nibwp'));
            }

            return ['updated' => true, 'results' => $written];
        }

        $button = null;
        if ($service !== null && method_exists($service, 'get_option_button')) {
            $button = $service->get_option_button();
        }

        $css = '';
        if ($service !== null && method_exists($service, 'get_css_custom_inline')) {
            $css = (string) $service->get_css_custom_inline();
        }

        return ['button' => $button, 'custom_css' => $css];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 6 — nibwp/weglot-slugs (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-slugs', [
    'label'       => __('Weglot — Translated slugs', domain: 'nibwp'),
    'description' => __('Read the translated URL slugs Weglot holds for this site, and whether slug translation is switched on (read-only).', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_slugs',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Translated slugs are worth having: /fr/a-propos ranks in French where /fr/about does not.',
                'Editing individual slugs happens in the Weglot dashboard. This reads what is there so you can tell the user what will change.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_weglot_slugs(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    if (!nibwp_weglot_has_key()) {
        return nibwp_weglot_err('nibwp_weglot_no_key', __('No Weglot API key is configured, so slugs cannot be read.', domain: 'nibwp'));
    }

    return nibwp_weglot_try(static function (): array {
        $service = nibwp_weglot_service('Option_Service_Weglot');
        $options = nibwp_weglot_options();

        $slugs = [];
        if ($service !== null && method_exists($service, 'get_slugs_from_api_with_api_key') && function_exists('weglot_get_api_key')) {
            $slugs = (array) $service->get_slugs_from_api_with_api_key(weglot_get_api_key());
        }

        return [
            'translate_slugs' => (bool) ($options['active_slugs'] ?? false),
            'slugs'           => $slugs,
            'count'           => count($slugs),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 7 — nibwp/weglot-pages (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-pages', [
    'label'       => __('Weglot — Page translation control', domain: 'nibwp'),
    'description' => __('Read and set which pages are left untranslated in which languages — Weglot\'s page unpublication rules.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'rules'  => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'set: the complete rule list, in the shape action=get returns.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_pages',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Useful for pages that make no sense translated — a jobs page for one country, legal text that must stay in its original language, a landing page aimed at one market.',
                'set REPLACES the rules. Read them first.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_pages(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');

    return nibwp_weglot_try(static function () use ($action, $input) {
        if ($action === 'set') {
            $rules = array_values((array) ($input['rules'] ?? []));
            $result = nibwp_weglot_write_option('page_unpublications', $rules);

            return ['rules' => $rules, 'count' => count($rules), 'saved' => $result['local'], 'pushed_to_weglot' => $result['pushed'], 'note' => $result['message']];
        }

        $service = nibwp_weglot_service('Option_Service_Weglot');
        $rules = [];

        if ($service !== null && method_exists($service, 'get_page_unpublications')) {
            $rules = (array) $service->get_page_unpublications();
        }

        return ['rules' => $rules, 'count' => count($rules)];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 8 — nibwp/weglot-media (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-media', [
    'label'       => __('Weglot — Media, PDF and email', domain: 'nibwp'),
    'description' => __('Read and switch the translation of things beyond page text: media and image URLs, PDF documents, and outgoing emails.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
            'media'  => ['type' => 'boolean', 'description' => 'set: translate media and image URLs.'],
            'pdf'    => ['type' => 'boolean', 'description' => 'set: translate linked PDF documents.'],
            'emails' => ['type' => 'boolean', 'description' => 'set: translate outgoing emails.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_media',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Each of these consumes quota. PDF translation especially — a long document can cost more than the pages around it.',
                'Email translation changes what customers receive. Confirm before switching it on for a live site.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_media(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    $action = (string) ($input['action'] ?? 'get');

    return nibwp_weglot_try(static function () use ($action, $input) {
        $map = [
            'media'  => 'translate_search',
            'pdf'    => 'translate_pdf',
            'emails' => 'translate_email',
        ];

        $options = nibwp_weglot_options();

        if ($action === 'set') {
            $results = [];

            foreach ($map as $field => $key) {
                if (array_key_exists($field, $input)) {
                    $results[$field] = nibwp_weglot_write_option($key, (bool) $input[$field]);
                }
            }

            if ($results === []) {
                throw new \RuntimeException(__('Give at least one of media, pdf or emails.', domain: 'nibwp'));
            }

            return ['updated' => true, 'results' => $results];
        }

        $out = [];
        foreach ($map as $field => $key) {
            $out[$field] = (bool) ($options[$key] ?? false);
        }

        return $out;
    });
}

/* ----------------------------------------------------------------------------
 * Ability 9 — nibwp/weglot-plan (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-plan', [
    'label'       => __('Weglot — Plan and usage', domain: 'nibwp'),
    'description' => __('Read the Weglot account: plan, word allowance, how much is used, and how many languages it covers (read-only).', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_plan',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read this BEFORE adding a language or switching on PDF or email translation. Weglot bills by translated words across every language, and going over stops translation rather than charging quietly.',
                'Tell the user the numbers before they commit to anything that increases usage.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_weglot_plan(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    if (!nibwp_weglot_has_key()) {
        return nibwp_weglot_err('nibwp_weglot_no_key', __('No Weglot API key is configured, so the account cannot be read.', domain: 'nibwp'));
    }

    return nibwp_weglot_try(static function (): array {
        $service = nibwp_weglot_service('User_Api_Service_Weglot');

        if ($service === null) {
            throw new \RuntimeException(__('The Weglot account service is unavailable in this version.', domain: 'nibwp'));
        }

        $user = method_exists($service, 'get_user_info') ? $service->get_user_info() : null;
        $workspace = method_exists($service, 'get_workspace_info') ? $service->get_workspace_info() : null;

        return [
            'user'      => $user,
            'workspace' => $workspace,
            'languages_configured' => count(nibwp_weglot_destination_codes()),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 10 — nibwp/weglot-audit (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-audit', [
    'label'       => __('Weglot — Audit', domain: 'nibwp'),
    'description' => __('Check a Weglot setup for the mistakes that cost money or rankings: missing API key, no hreflang, untranslated slugs, no exclusions on a technical site, private mode left on, and languages configured but not reachable (read-only).', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Run this on any site that already has Weglot before changing anything. Most Weglot problems are configuration that was never revisited.',
                'Findings are ranked. Fix the blockers first — everything else is wasted while the site is not translating at all.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_weglot_audit(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    return nibwp_weglot_try(static function (): array {
        $options = nibwp_weglot_options();
        $destinations = nibwp_weglot_destination_codes();
        $has_key = nibwp_weglot_has_key();

        $blockers = [];
        $warnings = [];
        $notes = [];

        if (!$has_key) {
            $blockers[] = [
                'code'    => 'no_api_key',
                'message' => __('No API key. Nothing is being translated, whatever else is configured.', domain: 'nibwp'),
                'fix'     => __('Add the key from your Weglot account under Weglot → Settings.', domain: 'nibwp'),
            ];
        }

        if ($destinations === []) {
            $blockers[] = [
                'code'    => 'no_languages',
                'message' => __('No destination languages. The site has Weglot installed and translates into nothing.', domain: 'nibwp'),
                'fix'     => __('Add one with nibwp/weglot-languages after checking the plan.', domain: 'nibwp'),
            ];
        }

        if (array_key_exists('active_translation', $options) && !$options['active_translation']) {
            $blockers[] = [
                'code'    => 'translation_off',
                'message' => __('Translation is switched off in the settings.', domain: 'nibwp'),
                'fix'     => __('Set active_translation to true with nibwp/weglot-settings.', domain: 'nibwp'),
            ];
        }

        if (!(bool) ($options['add_hreflang'] ?? true)) {
            $warnings[] = [
                'code'    => 'no_hreflang',
                'message' => __('hreflang tags are off. Search engines cannot tell which language version to show, which is the most common way a translated site fails to rank.', domain: 'nibwp'),
                'fix'     => __('Set add_hreflang to true.', domain: 'nibwp'),
            ];
        }

        if (!(bool) ($options['active_slugs'] ?? false)) {
            $warnings[] = [
                'code'    => 'slugs_untranslated',
                'message' => __('URL slugs are not translated. /fr/about ranks worse in French than /fr/a-propos.', domain: 'nibwp'),
                'fix'     => __('Set active_slugs to true, then review the slugs in the Weglot dashboard.', domain: 'nibwp'),
            ];
        }

        if ((bool) ($options['allow_private'] ?? false)) {
            $warnings[] = [
                'code'    => 'private_mode_on',
                'message' => __('Private mode is on. Translations are not visible to the public — fine while building, a problem if it was forgotten.', domain: 'nibwp'),
                'fix'     => __('Set allow_private to false when the site is ready.', domain: 'nibwp'),
            ];
        }

        $exclude_blocks = (array) ($options['exclude_blocks'] ?? []);
        if ($exclude_blocks === []) {
            $warnings[] = [
                'code'    => 'no_exclusions',
                'message' => __('Nothing is excluded from translation. Code samples, brand names, SKUs and addresses are being translated along with the prose — and billed.', domain: 'nibwp'),
                'fix'     => __('Set exclusions with nibwp/weglot-exclusions. Start with code, pre and any syntax-highlighting class.', domain: 'nibwp'),
            ];
        }

        if (count($destinations) > 5) {
            $notes[] = [
                'code'    => 'many_languages',
                'message' => sprintf(
                    /* translators: %d: number of destination languages */
                    __('%d destination languages. Every word on the site is translated once per language, so usage grows with the site, not just with the language count.', domain: 'nibwp'),
                    count($destinations)
                ),
            ];
        }

        $score = $blockers === [] ? ($warnings === [] ? 'good' : 'needs_attention') : 'not_working';

        return [
            'verdict'  => $score,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'notes'    => $notes,
            'summary'  => [
                'api_key_set'  => $has_key,
                'languages'    => count($destinations),
                'hreflang'     => (bool) ($options['add_hreflang'] ?? true),
                'slugs'        => (bool) ($options['active_slugs'] ?? false),
                'exclusions'   => count($exclude_blocks),
            ],
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 11 — nibwp/weglot-workflow (read-only)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-workflow', [
    'label'       => __('Weglot — Translation workflow', domain: 'nibwp'),
    'description' => __('The ordered plan for translating this particular site, built from its current state: what is already done, what to do next, and what it will cost (read-only).', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'target_languages' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Language codes the user wants, e.g. ["fr","de","es"].'],
            'site_type'        => ['type' => 'string', 'enum' => ['general', 'technical', 'ecommerce', 'legal'], 'default' => 'general', 'description' => 'Shapes the exclusion advice.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_workflow',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Start here when the user says "translate my site". It reads what is already configured and returns only the steps that still apply.',
                'The order matters: exclusions BEFORE the first translation pass, because anything translated once has already been billed.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_weglot_workflow(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    return nibwp_weglot_try(static function () use ($input): array {
        $options = nibwp_weglot_options();
        $existing = nibwp_weglot_destination_codes();
        $wanted = array_values(array_filter(array_map('strval', (array) ($input['target_languages'] ?? []))));
        $site_type = (string) ($input['site_type'] ?? 'general');

        $missing = array_values(array_diff($wanted, $existing));

        $exclusions = match ($site_type) {
            'technical' => ['code', 'pre', '.hljs', '.wp-block-code', '[data-no-translate]'],
            'ecommerce' => ['.sku', '.product-code', '.brand', '[data-no-translate]'],
            'legal'     => ['.legal-reference', '.case-citation', '[data-no-translate]'],
            default     => ['code', 'pre', '[data-no-translate]'],
        };

        $steps = [];

        if (!nibwp_weglot_has_key()) {
            $steps[] = [
                'step'    => 'Connect the account',
                'why'     => __('Nothing translates without an API key.', domain: 'nibwp'),
                'ability' => 'Weglot → Settings in wp-admin',
            ];
        }

        $steps[] = [
            'step'    => 'Check the plan before committing',
            'why'     => __('Weglot bills by translated words across every language. Know the allowance before adding languages, because going over stops translation rather than charging quietly.', domain: 'nibwp'),
            'ability' => 'nibwp/weglot-plan',
        ];

        // Exclusions come before languages on purpose: a word translated once
        // has already been paid for, and excluding it afterwards does not
        // refund it.
        $steps[] = [
            'step'    => 'Set exclusions FIRST',
            'why'     => __('Anything translated once is already billed. Excluding code, brand names and product codes before the first pass saves both quota and nonsense output.', domain: 'nibwp'),
            'ability' => 'nibwp/weglot-exclusions',
            'suggested_selectors' => $exclusions,
        ];

        if ($missing !== []) {
            $steps[] = [
                'step'    => 'Add the languages',
                'why'     => sprintf(
                    /* translators: %s: comma-separated language codes */
                    __('Not yet configured: %s. Each one multiplies word usage.', domain: 'nibwp'),
                    implode(', ', $missing)
                ),
                'ability' => 'nibwp/weglot-languages',
                'codes'   => $missing,
            ];
        }

        if (!(bool) ($options['add_hreflang'] ?? true)) {
            $steps[] = [
                'step'    => 'Switch hreflang on',
                'why'     => __('Without it search engines cannot tell the language versions apart, and the translated pages compete with each other instead of ranking.', domain: 'nibwp'),
                'ability' => 'nibwp/weglot-settings',
            ];
        }

        if (!(bool) ($options['active_slugs'] ?? false)) {
            $steps[] = [
                'step'    => 'Translate the slugs',
                'why'     => __('A French page on an English URL ranks worse than one on a French URL.', domain: 'nibwp'),
                'ability' => 'nibwp/weglot-settings',
            ];
        }

        $steps[] = [
            'step'    => 'Place the switcher where people will find it',
            'why'     => __('A translated site nobody can switch is a translated site nobody reads.', domain: 'nibwp'),
            'ability' => 'nibwp/weglot-switcher',
        ];

        $steps[] = [
            'step'    => 'Decide what should NOT be translated',
            'why'     => __('Country-specific pages, legal text that must stay in its original language, market-specific landing pages.', domain: 'nibwp'),
            'ability' => 'nibwp/weglot-pages',
        ];

        $steps[] = [
            'step'    => 'Re-audit',
            'why'     => __('Confirm the configuration before telling anyone the site is translated.', domain: 'nibwp'),
            'ability' => 'nibwp/weglot-audit',
        ];

        return [
            'site_type'          => $site_type,
            'already_configured' => $existing,
            'to_add'             => $missing,
            'steps'              => $steps,
            'principle'          => __('Exclusions before languages, languages before polish. A word translated once is billed once, whatever you do afterwards.', domain: 'nibwp'),
        ];
    });
}

/* ----------------------------------------------------------------------------
 * Ability 12 — nibwp/weglot-sync (read + write)
 * ------------------------------------------------------------------------- */

wp_register_ability('nibwp/weglot-sync', [
    'label'       => __('Weglot — Sync with the workspace', domain: 'nibwp'),
    'description' => __('Pull the configuration held in your Weglot workspace, or push the local one to it, when the two have drifted apart.', domain: 'nibwp'),
    'category'    => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['compare', 'pull', 'push'], 'default' => 'compare'],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Required for pull and push — both overwrite one side with the other.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_weglot_sync',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Weglot keeps settings in two places. When the dashboard and the plugin disagree, compare first and show the user the differences before choosing a direction.',
                'Both pull and push overwrite one side entirely. They are confirm-gated for that reason.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_weglot_sync(array $input): array|WP_Error
{
    if ($guard = nibwp_weglot_guard()) {
        return $guard;
    }

    if (!nibwp_weglot_has_key()) {
        return nibwp_weglot_err('nibwp_weglot_no_key', __('No Weglot API key is configured, so there is no workspace to sync with.', domain: 'nibwp'));
    }

    $action = (string) ($input['action'] ?? 'compare');

    if (in_array($action, ['pull', 'push'], strict: true) && !(bool) ($input['confirm'] ?? false)) {
        return nibwp_weglot_err(
            'nibwp_weglot_unconfirmed',
            __('This overwrites one side of the configuration with the other. Compare first, then re-issue with confirm true.', domain: 'nibwp')
        );
    }

    return nibwp_weglot_try(static function () use ($action) {
        $service = nibwp_weglot_service('Option_Service_Weglot');

        if ($service === null) {
            throw new \RuntimeException(__('The Weglot option service is unavailable.', domain: 'nibwp'));
        }

        $local = nibwp_weglot_options();

        $remote = [];
        if (method_exists($service, 'get_options_from_api_with_api_key') && function_exists('weglot_get_api_key')) {
            $remote = (array) $service->get_options_from_api_with_api_key(weglot_get_api_key());
        }

        if ($action === 'compare') {
            $differences = [];

            foreach (array_unique(array_merge(array_keys($local), array_keys($remote))) as $key) {
                $a = $local[$key] ?? null;
                $b = $remote[$key] ?? null;
                if (wp_json_encode($a) !== wp_json_encode($b)) {
                    $differences[$key] = ['local' => $a, 'workspace' => $b];
                }
            }

            return [
                'in_sync'     => $differences === [],
                'differences' => $differences,
                'count'       => count($differences),
            ];
        }

        if ($action === 'pull') {
            if ($remote === []) {
                throw new \RuntimeException(__('The workspace returned no configuration to pull.', domain: 'nibwp'));
            }
            if (!method_exists($service, 'set_options')) {
                throw new \RuntimeException(__('This Weglot version does not expose replacing the local options.', domain: 'nibwp'));
            }

            $service->set_options($remote);

            return ['pulled' => true, 'keys' => count($remote)];
        }

        if (!method_exists($service, 'save_options_to_weglot')) {
            throw new \RuntimeException(__('This Weglot version does not expose pushing to the workspace.', domain: 'nibwp'));
        }

        $service->save_options_to_weglot();

        return ['pushed' => true, 'keys' => count($local)];
    });
}
