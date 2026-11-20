<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: TranslatePress.
 *
 * Verified against the TranslatePress source on disk (2.9.x).
 *
 * WHERE THE TRANSLATIONS LIVE decides the shape of this file. TranslatePress does
 * not duplicate a post per language and it does not keep translations in post
 * meta. It keeps ONE copy of the content and a table PER TARGET LANGUAGE holding
 * original/translated pairs:
 *
 *   {prefix}trp_dictionary_{default}_{target}   page strings  (class-query.php:1424)
 *   {prefix}trp_gettext_{target}                theme and plugin strings  (:1436)
 *   {prefix}trp_original_strings                id => original  (:1111)
 *   {prefix}trp_original_meta                   which post a string was found on (:1120)
 *
 * So a table NAME is assembled from two language codes, and an identifier is the
 * one thing $wpdb->prepare() cannot protect. Every code that reaches a table name
 * here has first been matched against the codes in the site's own settings option
 * and REPLACED by the site's spelling — see nibwp_translatepress_language() and
 * nibwp_translatepress_table(). A code from the request is never interpolated.
 *
 * STATUS IS THE WHOLE POINT. Each dictionary row carries a status
 * (class-query.php:34-38):
 *
 *   0 untranslated        no translation; the original renders
 *   1 machine translated  a machine guessed, nobody checked
 *   2 human reviewed      a person agreed
 *   3 similar translated  copied from a string that looked the same
 *   4 from language file  gettext rows mirroring a .mo file
 *
 * An owner who machine-translates a site has a database full of 1s and believes
 * the site is translated. The difference between a 1 and a 2 is the difference
 * between "a machine guessed" and "a person agreed", and it is the number this
 * integration reports everywhere, because it is the one nobody else surfaces.
 *
 * HOW A STRING IS KEYED, because writing under the wrong key silently does
 * nothing: a page string is keyed by its ORIGINAL TEXT, byte for byte, in the
 * target language's dictionary table, narrowed by block_type = 0 (0 is a plain
 * string, 1 and 2 are translation blocks — whole HTML chunks that merely contain
 * strings). There is no context and no hash for page strings. The `original_id`
 * column links the row to trp_original_strings and is how the same string is
 * recognised across languages; it can be NULL, and the audit says so when it
 * compares languages. Gettext strings are keyed differently — by original,
 * text domain AND context, hashed as md5(original . "\x1F" . domain . "\x1F" .
 * context) into trp_gettext_original_strings.lookup_hash (class-query.php:1195) —
 * which is why this file reads gettext progress but does not write gettext rows.
 *
 * SECRETS: the automatic-translation engine key lives in
 * trp_machine_translation_settings (google-translate-key, deepl-api-key), the
 * plugin's own AI key in the trp_ai_api_key option and the licence in
 * trp_license_key. None of them leaves here. Settings travel through an
 * ALLOW-LIST of field names, typed, and a value that is not scalar is DROPPED
 * rather than cast — a serialised blob under an innocent key is exactly how a
 * credential would smuggle itself out.
 */

/** Settings and language changes: publishing a half-finished language changes what every visitor sees. */
const NIBWP_TRANSLATEPRESS_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_TRANSLATEPRESS_MAX_ROWS = 200;

/** How many languages the audit will compare against each other in one call. */
const NIBWP_TRANSLATEPRESS_MAX_LANGUAGES = 8;

/** Below this percentage a published language is reported as published too early. */
const NIBWP_TRANSLATEPRESS_INCOMPLETE_PERCENT = 80;

/** Status values, by their names in the plugin (includes/queries/class-query.php:34-38). */
const NIBWP_TRANSLATEPRESS_STATUS = [
    'untranslated' => 0,
    'machine' => 1,
    'reviewed' => 2,
    'similar' => 3,
    'from_language_file' => 4,
];

/**
 * A plain string row, as opposed to a translation block.
 *
 * block_type 1 and 2 are whole HTML chunks that contain strings already stored
 * individually (class-query.php:39-41). Counting both would double every total, so
 * progress and completion are regular strings only.
 */
const NIBWP_TRANSLATEPRESS_REGULAR_STRING = 0;

/* --------------------------------------------------------------- the guard */

/**
 * Is TranslatePress actually loaded?
 *
 * The constant is defined by the main plugin file and the class is what the
 * settings writes go through, so between them they separate "not installed" from
 * "installed and half-loaded".
 */
function nibwp_translatepress_guard(): ?WP_Error
{
    if (!defined('TRP_PLUGIN_VERSION') && !class_exists('TRP_Translate_Press')) {
        return new WP_Error(
            'nibwp_trp_missing',
            __('TranslatePress is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_translatepress_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_trp_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_translatepress_forbidden(string $what): WP_Error
{
    return new WP_Error(
        'nibwp_trp_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            NIBWP_TRANSLATEPRESS_CAP
        ),
        ['status' => 403]
    );
}

/**
 * The one capability gate, applied to reads as well as writes.
 *
 * Reading is not a lower bar than writing here: the dictionary holds every string
 * on the site, including strings from pages that are not public.
 */
function nibwp_translatepress_require(string $what): ?WP_Error
{
    return current_user_can(NIBWP_TRANSLATEPRESS_CAP) ? null : nibwp_translatepress_forbidden($what);
}

/* ------------------------------------------------------- settings and codes */

/**
 * The plugin's own settings option (includes/class-settings.php:401).
 *
 * @return array<string, mixed>
 */
function nibwp_translatepress_settings(): array
{
    $settings = get_option('trp_settings', []);

    return is_array($settings) ? $settings : [];
}

/**
 * The automatic-translation settings (includes/class-settings.php:494).
 *
 * @return array<string, mixed>
 */
function nibwp_translatepress_mt_settings(): array
{
    $settings = get_option('trp_machine_translation_settings', []);

    return is_array($settings) ? $settings : [];
}

/**
 * The advanced settings, which is where the hreflang switches live.
 *
 * @return array<string, mixed>
 */
function nibwp_translatepress_advanced_settings(): array
{
    $settings = get_option('trp_advanced_settings', []);

    return is_array($settings) ? $settings : [];
}

/** The site's original language — the first half of every dictionary table name. */
function nibwp_translatepress_default_language(): string
{
    $settings = nibwp_translatepress_settings();
    $default = isset($settings['default-language']) && is_string($settings['default-language'])
        ? $settings['default-language']
        : '';

    return $default !== '' ? $default : 'en_US';
}

/**
 * Every language code this site is configured for, default included.
 *
 * This list is the allow-list a code from input has to appear in before it can
 * reach a table name. It comes from the option, never from the request. A code
 * the option itself holds is still filtered against the plugin's own rule
 * (includes/external-functions.php:146: A-Z a-z 0-9 - _ and nothing else),
 * because older versions could save codes that break it — TranslatePress raises
 * an admin notice when it finds one, which is proof it happens.
 *
 * @return array<int, string>
 */
function nibwp_translatepress_languages(): array
{
    $settings = nibwp_translatepress_settings();
    $codes = isset($settings['translation-languages']) && is_array($settings['translation-languages'])
        ? $settings['translation-languages']
        : [];

    $out = [];

    foreach (array_merge([nibwp_translatepress_default_language()], $codes) as $code) {
        if (!is_string($code) || $code === '') {
            continue;
        }
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $code) !== 1) {
            continue;
        }
        if (!in_array($code, $out, true)) {
            $out[] = $code;
        }
    }

    return $out;
}

/**
 * Which of the configured languages are published to visitors.
 *
 * @return array<int, string>
 */
function nibwp_translatepress_published(): array
{
    $settings = nibwp_translatepress_settings();
    $published = isset($settings['publish-languages']) && is_array($settings['publish-languages'])
        ? $settings['publish-languages']
        : [];

    return array_values(array_filter($published, 'is_string'));
}

/**
 * The URL slug configured for each language (settings key url-slugs).
 *
 * @return array<string, string>
 */
function nibwp_translatepress_slugs(): array
{
    $settings = nibwp_translatepress_settings();
    $slugs = isset($settings['url-slugs']) && is_array($settings['url-slugs']) ? $settings['url-slugs'] : [];
    $out = [];

    foreach ($slugs as $code => $slug) {
        if (is_string($code) && is_scalar($slug)) {
            $out[$code] = (string) $slug;
        }
    }

    return $out;
}

/**
 * Turn a language code from input into one of the site's own codes, or refuse.
 *
 * The returned string is taken FROM the configured list, not from the argument.
 * A caller that sends `fr_FR` + a backtick, a quote or a semicolon matches
 * nothing and is refused HERE, before a table name exists and before any query
 * is built. A caller that sends a different casing gets the site's spelling
 * back, because that spelling is what the table was named after.
 */
function nibwp_translatepress_language(mixed $raw, string $field = 'language_code'): string|WP_Error
{
    $wanted = is_string($raw) ? trim($raw) : '';

    if ($wanted === '') {
        return nibwp_translatepress_err(
            'missing_language',
            sprintf(
                /* translators: %s: the input field name */
                __('Give the language code as %s.', 'nibwp'),
                $field
            ),
            422
        );
    }

    foreach (nibwp_translatepress_languages() as $code) {
        if (strcasecmp($code, $wanted) === 0) {
            return $code;
        }
    }

    return nibwp_translatepress_err(
        'unknown_language',
        sprintf(
            /* translators: 1: the rejected code, 2: list of configured codes */
            __('"%1$s" is not a language this site is configured for, so nothing was read or written. Configured: %2$s.', 'nibwp'),
            $wanted,
            implode(', ', nibwp_translatepress_languages())
        ),
        422
    );
}

/**
 * The last gate before a language code becomes part of an identifier.
 *
 * nibwp_translatepress_language() has already replaced the caller's code with the
 * site's own, so in a correct call this cannot fail. It exists because an
 * identifier cannot be prepared and a future edit that skips the verification
 * step has to hit something.
 */
function nibwp_translatepress_safe_code(string $code): bool
{
    return preg_match('/\A[A-Za-z0-9_-]+\z/', $code) === 1;
}

/**
 * The dictionary table for one verified language code.
 *
 * Both halves of the name come from the settings option by way of
 * nibwp_translatepress_language(). Nothing from the request is interpolated.
 */
function nibwp_translatepress_table(string $verified_code): string|WP_Error
{
    global $wpdb;

    $default = nibwp_translatepress_default_language();

    foreach ([$verified_code, $default] as $part) {
        if (!nibwp_translatepress_safe_code($part)) {
            return nibwp_translatepress_err(
                'unsafe_language_code',
                __('A language code held characters that cannot appear in a table name, so no query was run.', 'nibwp'),
                500
            );
        }
    }

    return $wpdb->prefix . 'trp_dictionary_' . strtolower($default) . '_' . strtolower($verified_code);
}

/**
 * The gettext table for one verified language code (class-query.php:1436).
 *
 * This one is named after the target language alone.
 */
function nibwp_translatepress_gettext_table(string $verified_code): string|WP_Error
{
    global $wpdb;

    if (!nibwp_translatepress_safe_code($verified_code)) {
        return nibwp_translatepress_err(
            'unsafe_language_code',
            __('A language code held characters that cannot appear in a table name, so no query was run.', 'nibwp'),
            500
        );
    }

    return $wpdb->prefix . 'trp_gettext_' . strtolower($verified_code);
}

/** Where a string's post links are recorded (class-query.php:1120). */
function nibwp_translatepress_meta_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'trp_original_meta';
}

/** Does a table exist? A language can be configured before its table is built. */
function nibwp_translatepress_table_exists(string $table): bool
{
    global $wpdb;

    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return is_string($found) && $found === $table;
}

/**
 * The dictionary table for a verified code, or a refusal naming which it is.
 *
 * Every read and write funnels through here so that "that language has no table
 * yet" is one answer with one code, rather than an empty result that reads like
 * a fully translated language.
 */
function nibwp_translatepress_existing_table(string $verified_code): string|WP_Error
{
    $table = nibwp_translatepress_table($verified_code);

    if ($table instanceof WP_Error) {
        return $table;
    }

    if (!nibwp_translatepress_table_exists($table)) {
        return nibwp_translatepress_err(
            'no_table',
            sprintf(
                /* translators: %s: language code */
                __('TranslatePress has not built a translation table for %s yet. It builds one the first time the site is visited in that language.', 'nibwp'),
                $verified_code
            ),
            404
        );
    }

    return $table;
}

/** Clamp a row count that arrived over the wire. */
function nibwp_translatepress_limit(mixed $value, int $default = 50): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_TRANSLATEPRESS_MAX_ROWS, $limit));
}

/** The name this integration uses for a status value. */
function nibwp_translatepress_status_name(int $status): string
{
    foreach (NIBWP_TRANSLATEPRESS_STATUS as $name => $value) {
        if ($value === $status) {
            return $name;
        }
    }

    return 'unknown';
}

/**
 * A status name from input, as its number.
 *
 * Names rather than numbers across the wire: "machine" is checkable and 1 is not.
 */
function nibwp_translatepress_status_value(mixed $raw): int|WP_Error
{
    $wanted = is_string($raw) ? strtolower(trim($raw)) : '';

    if ($wanted === '') {
        return nibwp_translatepress_err('missing_status', __('Give the status by name.', 'nibwp'), 422);
    }

    if (isset(NIBWP_TRANSLATEPRESS_STATUS[$wanted])) {
        return NIBWP_TRANSLATEPRESS_STATUS[$wanted];
    }

    return nibwp_translatepress_err(
        'bad_status',
        sprintf(
            /* translators: 1: the rejected value, 2: the list of valid status names */
            __('"%1$s" is not a translation status. Valid: %2$s.', 'nibwp'),
            $wanted,
            implode(', ', array_keys(NIBWP_TRANSLATEPRESS_STATUS))
        ),
        422
    );
}

/**
 * One dictionary row, in this integration's vocabulary.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function nibwp_translatepress_row(array $row): array
{
    $status = (int) ($row['status'] ?? 0);
    $translated = isset($row['translated']) && is_scalar($row['translated']) ? (string) $row['translated'] : '';
    $block_type = (int) ($row['block_type'] ?? 0);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'original' => isset($row['original']) && is_scalar($row['original']) ? (string) $row['original'] : '',
        'translated' => $translated,
        'status' => $status,
        'status_name' => nibwp_translatepress_status_name($status),
        'is_block' => $block_type !== NIBWP_TRANSLATEPRESS_REGULAR_STRING,
        // A row that claims a translation and holds nothing renders as empty text
        // on the front end, which looks like a layout bug rather than a missing
        // translation. The audit hunts these; every row reports it.
        'empty_but_marked_translated' => $translated === '' && $status !== NIBWP_TRANSLATEPRESS_STATUS['untranslated'],
        'linked_original_id' => isset($row['original_id']) && is_numeric($row['original_id'])
            ? (int) $row['original_id']
            : null,
    ];
}

/* ------------------------------------------------- the plugin's own objects */

/**
 * One of TranslatePress's components, or null when it cannot be had.
 *
 * Language and settings writes go through the plugin's own sanitize path rather
 * than straight to update_option, so that whatever it does on a save — creating
 * the new language's table, re-inserting the default at the head of the list,
 * flushing rewrite rules — happens here too.
 */
function nibwp_translatepress_component(string $name): ?object
{
    if (!class_exists('TRP_Translate_Press') || !method_exists('TRP_Translate_Press', 'get_trp_instance')) {
        return null;
    }

    try {
        $trp = TRP_Translate_Press::get_trp_instance();

        if (!is_object($trp) || !method_exists($trp, 'get_component')) {
            return null;
        }

        $component = $trp->get_component($name);

        return is_object($component) ? $component : null;
    } catch (\Throwable $error) {
        return null;
    }
}

/**
 * Human names for language codes, when the plugin can supply them.
 *
 * @param array<int, string> $codes
 * @return array<string, string>
 */
function nibwp_translatepress_language_names(array $codes): array
{
    $component = nibwp_translatepress_component('languages');

    if ($component === null || !method_exists($component, 'get_language_names')) {
        return [];
    }

    try {
        $names = $component->get_language_names($codes);
    } catch (\Throwable $error) {
        return [];
    }

    if (!is_array($names)) {
        return [];
    }

    $out = [];

    foreach ($names as $code => $name) {
        if (is_string($code) && is_scalar($name)) {
            $out[$code] = (string) $name;
        }
    }

    return $out;
}

/**
 * Write the settings option the way the plugin writes it.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_save_settings(array $settings): array|WP_Error
{
    $component = nibwp_translatepress_component('settings');

    if ($component === null || !method_exists($component, 'sanitize_settings')) {
        return nibwp_translatepress_err(
            'no_settings_api',
            __('This version of TranslatePress does not expose its settings sanitizer, and this integration will not write the option by hand — a language list saved without it leaves the site half-configured.', 'nibwp'),
            500
        );
    }

    $sanitized = $component->sanitize_settings($settings);

    // sanitize_settings() answers with the OLD option when it finds an invalid
    // code (class-settings.php:248), so a successful-looking return is not proof
    // the change landed. The callers check for their own change afterwards.
    if (!is_array($sanitized) || !isset($sanitized['translation-languages'])) {
        return nibwp_translatepress_err(
            'settings_rejected',
            __('TranslatePress rejected the change while sanitizing its settings, so nothing was saved.', 'nibwp'),
            500
        );
    }

    update_option('trp_settings', $sanitized);

    return $sanitized;
}

/* ----------------------------------------------------- what may never travel */

/**
 * Settings whose VALUE may be reported, and what each one is.
 *
 * An allow-list rather than a denylist: trp_settings is one flat key space that
 * add-ons extend, and a key added by a later version would default to leaking.
 *
 * @return array<string, string> key => bool|int|string
 */
function nibwp_translatepress_public_settings(): array
{
    return [
        'default-language' => 'string',
        'native_or_english_name' => 'string',
        'add-subdirectory-to-default-language' => 'string',
        'force-language-to-custom-links' => 'string',
        'trp-ls-floater' => 'string',
        'shortcode-options' => 'string',
        'menu-options' => 'string',
        'floater-options' => 'string',
        'floater-position' => 'string',
        'floater-color' => 'string',
        'trp-ls-show-poweredby' => 'string',
    ];
}

/**
 * Automatic-translation settings whose VALUE may be reported.
 *
 * The engine keys are deliberately absent: google-translate-key and
 * deepl-api-key live in the same array as these.
 *
 * @return array<string, string>
 */
function nibwp_translatepress_public_mt_settings(): array
{
    return [
        'machine-translation' => 'string',
        'translation-engine' => 'string',
        'block-crawlers' => 'string',
        'automatically-translate-slug' => 'string',
        'machine_translation_limit_enabled' => 'string',
        'machine_translation_limit' => 'int',
        'machine_translation_counter_date' => 'string',
        'ai_words_notification_enabled' => 'string',
        'ai_words_notification_threshold' => 'int',
    ];
}

/**
 * Credentials, reported as a name and whether they are set. Never as a value.
 *
 * Listed rather than hidden so an owner can confirm the engine key IS configured
 * without being handed it. `option` reads a WordPress option, `mt` reads a field
 * of trp_machine_translation_settings.
 *
 * @return array<int, array<string, string>>
 */
function nibwp_translatepress_credentials(): array
{
    return [
        ['source' => 'mt', 'name' => 'google-translate-key'],
        ['source' => 'mt', 'name' => 'deepl-api-key'],
        ['source' => 'option', 'name' => 'trp_ai_api_key'],
        ['source' => 'option', 'name' => 'trp_license_key'],
        ['source' => 'option', 'name' => 'trp_license_details'],
    ];
}

/**
 * Report one setting, or report nothing at all.
 *
 * A value that is not scalar is DROPPED, not cast. An array or an object under an
 * allow-listed name is how a credential set would travel out under cover, and
 * (string) on an array is "Array" at best and a serialised blob at worst.
 *
 * @param array<string, mixed> $values
 * @param array<string, string> $allowed
 * @return array<int, array<string, mixed>>
 */
function nibwp_translatepress_setting_rows(array $values, array $allowed): array
{
    $rows = [];

    foreach ($allowed as $key => $type) {
        if (!array_key_exists($key, $values)) {
            continue;
        }

        $value = $values[$key];

        if (!is_scalar($value)) {
            continue;
        }

        $rows[] = [
            'key' => $key,
            'value' => match ($type) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                default => (string) $value,
            },
        ];
    }

    return $rows;
}

/**
 * The credential block: name, whether it is set, and nothing else.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_translatepress_credential_rows(): array
{
    $mt = nibwp_translatepress_mt_settings();
    $rows = [];

    foreach (nibwp_translatepress_credentials() as $credential) {
        $value = $credential['source'] === 'mt'
            ? ($mt[$credential['name']] ?? null)
            : get_option($credential['name'], null);

        $rows[] = [
            'key' => $credential['name'],
            'is_set' => $value !== null && $value !== false && $value !== '' && $value !== [],
            'value_returned' => false,
        ];
    }

    return $rows;
}

/* ------------------------------------------------------------------ counting */

/**
 * Counts by status for one dictionary table.
 *
 * Regular strings only: a translation block row holds the HTML of a whole chunk
 * whose strings are already counted individually, so including blocks would
 * inflate every total and deflate every percentage.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_counts(string $table): array|WP_Error
{
    global $wpdb;

    // CHAR_LENGTH rather than a quoted empty string so the SQL carries no literals
    // at all and every value in it is a placeholder.
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT status, COUNT(*) AS rows_found,'
            . ' SUM(CASE WHEN translated IS NULL OR CHAR_LENGTH(translated) = 0 THEN 1 ELSE 0 END) AS empty_rows'
            . ' FROM `' . $table . '` WHERE block_type = %d GROUP BY status LIMIT %d',
            NIBWP_TRANSLATEPRESS_REGULAR_STRING,
            20
        ),
        ARRAY_A
    );

    $by_status = array_fill_keys(array_keys(NIBWP_TRANSLATEPRESS_STATUS), 0);
    $by_status['unknown'] = 0;
    $total = 0;
    $empty_but_marked = 0;
    $usable = 0;
    $reviewed = 0;
    $machine = 0;

    foreach ($rows as $row) {
        $status = (int) ($row['status'] ?? 0);
        $found = (int) ($row['rows_found'] ?? 0);
        $empty = (int) ($row['empty_rows'] ?? 0);
        $name = nibwp_translatepress_status_name($status);

        $by_status[$name] = ($by_status[$name] ?? 0) + $found;
        $total += $found;

        if ($status === NIBWP_TRANSLATEPRESS_STATUS['untranslated']) {
            continue;
        }

        $empty_but_marked += $empty;
        $usable += $found - $empty;

        if ($status === NIBWP_TRANSLATEPRESS_STATUS['reviewed']) {
            $reviewed += $found - $empty;
        }
        if ($status === NIBWP_TRANSLATEPRESS_STATUS['machine']) {
            $machine += $found - $empty;
        }
    }

    return [
        'total_strings' => $total,
        'by_status' => $by_status,
        'translated' => $usable,
        'machine_not_reviewed' => $machine,
        'reviewed' => $reviewed,
        'empty_but_marked_translated' => $empty_but_marked,
        'percent_translated' => $total > 0 ? round($usable / $total * 100, 1) : 0.0,
        'percent_reviewed' => $total > 0 ? round($reviewed / $total * 100, 1) : 0.0,
    ];
}

/**
 * Counts and completion for one language, or a note saying why there are none.
 *
 * @return array<string, mixed>
 */
function nibwp_translatepress_language_progress(string $verified_code): array
{
    $table = nibwp_translatepress_table($verified_code);

    if ($table instanceof WP_Error) {
        return ['table_present' => false, 'note' => $table->get_error_message()];
    }

    if (!nibwp_translatepress_table_exists($table)) {
        return [
            'table_present' => false,
            'note' => __('No translation table yet, so nothing has ever been translated in this language.', 'nibwp'),
        ];
    }

    $counts = nibwp_translatepress_counts($table);

    if ($counts instanceof WP_Error) {
        return ['table_present' => true, 'note' => $counts->get_error_message()];
    }

    return ['table_present' => true] + $counts;
}

/**
 * The languages the audit and the summary will look at, bounded.
 *
 * @return array<int, string>
 */
function nibwp_translatepress_bounded_languages(): array
{
    return array_slice(nibwp_translatepress_languages(), 0, NIBWP_TRANSLATEPRESS_MAX_LANGUAGES);
}

/* ------------------------------------------------------------- the registry */

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_translatepress_actions(): array
{
    return [
        // Reads.
        'translation_summary',
        'list_languages',
        'translation_progress',
        'post_translation_progress',
        'gettext_progress',
        'get_translations',
        'get_translation',
        'get_settings',
        'automatic_translation_settings',
        'seo_settings',
        'translation_audit',
        // Languages — every one of these changes what visitors see.
        'add_language',
        'remove_language_and_its_translations',
        'set_default_language',
        'publish_language',
        'unpublish_language',
        // Translations.
        'update_translation',
        'update_translations',
        'mark_translation_reviewed',
        'delete_translation',
    ];
}

wp_register_ability('nibwp/translatepress-manage', [
    'label' => __('TranslatePress – Translation Manager', 'nibwp'),
    'description' => 'Manage a TranslatePress site: list languages with their publish state and how complete each one is, add a language, set the default, publish or unpublish one, '
        . 'and read or edit the translations themselves — search by language, status or original text, update one string or a batch, mark a machine translation as reviewed, '
        . 'or delete a translation so the original shows again. Reports progress per language, for one post, and for theme and plugin strings, plus the automatic-translation engine, '
        . 'whether it is on and how much of its character allowance is spent. An audit names what is wrong: machine translations nobody reviewed, strings translated in one language '
        . 'but not another, translations that are empty while claiming not to be, and languages published while far from finished. '
        . 'The automatic-translation engine key, the licence key and every other credential are never returned — only whether they are set.',
    'category' => 'multilingual',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_translatepress_actions(),
                'description' => 'Which operation to run. Start with translation_summary.',
            ],
            'language_code' => [
                'type' => 'string',
                'description' => 'Which language to work in. Must be a language this site is already configured for, except for add_language.',
            ],
            'translation_data' => [
                'type' => 'object',
                'properties' => [
                    'original' => ['type' => 'string', 'description' => 'The original string, byte for byte — this is the key.'],
                    'translated' => ['type' => 'string', 'description' => 'The translation.'],
                    'language_code' => ['type' => 'string', 'description' => 'Target language code.'],
                ],
                'description' => 'For update_translation.',
            ],
            'translations' => [
                'type' => 'array',
                'description' => 'For update_translations: a list of {original, translated, language_code}.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'original' => ['type' => 'string'],
                        'translated' => ['type' => 'string'],
                        'language_code' => ['type' => 'string'],
                    ],
                ],
            ],
            'original' => ['type' => 'string', 'description' => 'For get_translation / mark_translation_reviewed / delete_translation: the original string.'],
            'translated' => ['type' => 'string', 'description' => 'For update_translation, as an alternative to translation_data.'],
            'translation_id' => ['type' => 'integer', 'description' => 'A row id from get_translations, as an alternative to original.'],
            'status' => [
                'type' => 'string',
                'description' => 'For get_translations: untranslated, machine, reviewed, similar or from_language_file.',
            ],
            'search' => ['type' => 'string', 'description' => 'For get_translations: match the original or the translation.'],
            'post_id' => ['type' => 'integer', 'description' => 'For post_translation_progress: which post or page.'],
            'slug' => ['type' => 'string', 'description' => 'For add_language: the URL slug. Defaults to the first part of the code.'],
            'per_page' => ['type' => 'integer', 'description' => 'Rows per page. Default 50, max 200.'],
            'page' => ['type' => 'integer', 'description' => 'Which page of a listing.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 50, max 200.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
            'languages' => ['type' => 'array'],
            'translations' => ['type' => 'array'],
            'updated' => ['type' => 'boolean'],
            'total' => ['type' => 'integer'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_translatepress_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'TranslatePress keeps one copy of the content and a table per target',
                'language holding original/translated pairs. There is no duplicated post',
                'per language, so do not look for one.',
                '',
                'START WITH translation_summary. It answers the question an owner actually',
                'has: which languages are live, how finished each one is, and how much of',
                'what is live was written by a machine that nobody checked.',
                '',
                'STATUS IS THE POINT. Every string is untranslated, machine, reviewed,',
                'similar or from_language_file. "Translated" on a machine-translated site',
                'means a machine guessed. When you report progress, report the reviewed',
                'share separately — a site at 100% machine and 0% reviewed has had no human',
                'read a word of it.',
                '',
                'A STRING IS KEYED BY ITS ORIGINAL TEXT, byte for byte. Copy the original',
                'from get_translations rather than retyping it: one different space and the',
                'write lands on a new row that nothing will ever render.',
                '',
                'LANGUAGE CHANGES ARE NOT SETTINGS CHANGES:',
                '- publish_language makes a language visible to every visitor. Read its',
                '  completion first and say it out loud; publishing at 40% ships a page',
                '  that is half in the wrong language.',
                '- remove_language_and_its_translations DELETES that language\'s',
                '  translations. They are not recoverable from here. Ask first, always.',
                '- set_default_language renames every dictionary table, because the table',
                '  name contains the default language. Existing translations stop being',
                '  found. Treat it as a migration, not a setting.',
                '',
                'BULK WRITES: update_translations is per-row all-or-nothing. It reports how',
                'many rows it wrote and how many actually changed, and those differ when a',
                'translation was already identical. Report the number it gives you; do not',
                'count the rows you sent.',
                '',
                'CREDENTIALS: the automatic-translation engine key and the licence key never',
                'come back. automatic_translation_settings says whether a key is set, which',
                'is the only part of that question anyone needs answered.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_manage(array $input): array|WP_Error
{
    $guard = nibwp_translatepress_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_translatepress_actions(), true)) {
        return nibwp_translatepress_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown TranslatePress action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_translatepress_actions())
            )
        );
    }

    // TranslatePress reaches its own tables and, for a settings write, its own
    // components. One catch here turns any of that into a named refusal rather
    // than a fatal that takes the whole REST request down.
    try {
        return nibwp_translatepress_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_translatepress_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from TranslatePress */
                __('TranslatePress could not complete %1$s: %2$s', 'nibwp'),
                $action,
                $error->getMessage()
            ),
            500
        );
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'translation_summary' => nibwp_translatepress_translation_summary(),
        'list_languages' => nibwp_translatepress_list_languages(),
        'translation_progress' => nibwp_translatepress_translation_progress($input),
        'post_translation_progress' => nibwp_translatepress_post_translation_progress($input),
        'gettext_progress' => nibwp_translatepress_gettext_progress($input),
        'get_translations' => nibwp_translatepress_get_translations($input),
        'get_translation' => nibwp_translatepress_get_translation($input),
        'get_settings' => nibwp_translatepress_get_settings(),
        'automatic_translation_settings' => nibwp_translatepress_automatic_translation_settings(),
        'seo_settings' => nibwp_translatepress_seo_settings(),
        'translation_audit' => nibwp_translatepress_translation_audit($input),
        'add_language' => nibwp_translatepress_add_language($input),
        'remove_language_and_its_translations' => nibwp_translatepress_remove_language($input),
        'set_default_language' => nibwp_translatepress_set_default_language($input),
        'publish_language' => nibwp_translatepress_set_published($input, true),
        'unpublish_language' => nibwp_translatepress_set_published($input, false),
        'update_translation' => nibwp_translatepress_update_translation($input),
        'update_translations' => nibwp_translatepress_update_translations($input),
        'mark_translation_reviewed' => nibwp_translatepress_mark_reviewed($input),
        'delete_translation' => nibwp_translatepress_delete_translation($input),
        default => nibwp_translatepress_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * The few things an owner should know, in one response.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_translation_summary(): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress summary', 'nibwp'))) {
        return $denied;
    }

    $languages = nibwp_translatepress_list_languages();
    $automatic = nibwp_translatepress_automatic_translation_settings();
    $audit = nibwp_translatepress_translation_audit([]);

    $act_on = [];

    if (!$audit instanceof WP_Error) {
        foreach ((array) ($audit['findings'] ?? []) as $finding) {
            if (is_array($finding) && !empty($finding['summary'])) {
                $act_on[] = (string) $finding['summary'];
            }
        }
    }

    return [
        'default_language' => nibwp_translatepress_default_language(),
        'languages' => $languages instanceof WP_Error ? null : ($languages['languages'] ?? null),
        'automatic_translation' => $automatic instanceof WP_Error
            ? null
            : [
                'enabled' => $automatic['enabled'] ?? null,
                'engine' => $automatic['engine'] ?? null,
                'characters' => $automatic['characters'] ?? null,
            ],
        'act_on' => $act_on,
        'looks_reasonable' => $act_on === [],
    ];
}

/**
 * Every configured language, with publish state and completion.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_list_languages(): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress languages', 'nibwp'))) {
        return $denied;
    }

    $default = nibwp_translatepress_default_language();
    $published = nibwp_translatepress_published();
    $slugs = nibwp_translatepress_slugs();
    $codes = nibwp_translatepress_bounded_languages();
    $names = nibwp_translatepress_language_names($codes);

    $rows = [];

    foreach ($codes as $code) {
        $is_default = $code === $default;
        $progress = $is_default ? [] : nibwp_translatepress_language_progress($code);

        $rows[] = [
            'code' => $code,
            // Legacy callers read `name`; it falls back to the code when the
            // plugin's language list is not loadable.
            'name' => $names[$code] ?? $code,
            'is_default' => $is_default,
            'is_published' => in_array($code, $published, true),
            'url_slug' => $slugs[$code] ?? null,
            'progress' => $is_default ? null : $progress,
        ];
    }

    return [
        'default_language' => $default,
        'returned' => count($rows),
        'languages' => $rows,
        'note' => count(nibwp_translatepress_languages()) > count($codes)
            ? __('More languages are configured than are reported here; this listing is bounded.', 'nibwp')
            : __('The default language has no dictionary table of its own to measure, so it reports no progress.', 'nibwp'),
    ];
}

/**
 * Counts per language, or for one language.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_translation_progress(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress translation progress', 'nibwp'))) {
        return $denied;
    }

    $default = nibwp_translatepress_default_language();
    $wanted = $input['language_code'] ?? null;

    if ($wanted !== null && $wanted !== '') {
        $code = nibwp_translatepress_language($wanted);

        if ($code instanceof WP_Error) {
            return $code;
        }

        $codes = [$code];
    } else {
        $codes = nibwp_translatepress_bounded_languages();
    }

    $rows = [];

    foreach ($codes as $code) {
        if ($code === $default) {
            continue;
        }

        $rows[] = ['code' => $code] + nibwp_translatepress_language_progress($code);
    }

    return [
        'default_language' => $default,
        'languages' => $rows,
        'note' => __('Counts are regular strings only. A translation block holds the HTML of a whole chunk whose strings are already counted one by one, so counting both would inflate every total.', 'nibwp'),
    ];
}

/**
 * Counts for the strings found on one post or page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_post_translation_progress(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress progress for one post', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;

    if ($post_id <= 0) {
        return nibwp_translatepress_err('missing_post', __('Give the post or page as post_id.', 'nibwp'), 422);
    }

    $post = get_post($post_id);

    if (!is_object($post)) {
        return nibwp_translatepress_err(
            'no_post',
            sprintf(
                /* translators: %d: post ID */
                __('There is no post with the ID %d.', 'nibwp'),
                $post_id
            ),
            404
        );
    }

    $default = nibwp_translatepress_default_language();
    $wanted = $input['language_code'] ?? null;

    if ($wanted !== null && $wanted !== '') {
        $code = nibwp_translatepress_language($wanted);

        if ($code instanceof WP_Error) {
            return $code;
        }

        $codes = [$code];
    } else {
        $codes = nibwp_translatepress_bounded_languages();
    }

    $meta_table = nibwp_translatepress_meta_table();
    $rows = [];

    foreach ($codes as $code) {
        if ($code === $default) {
            continue;
        }

        $table = nibwp_translatepress_table($code);

        if ($table instanceof WP_Error || !nibwp_translatepress_table_exists($table)) {
            $rows[] = [
                'code' => $code,
                'table_present' => false,
                'note' => __('No translation table for this language yet.', 'nibwp'),
            ];
            continue;
        }

        // The link from a string to the post it was found on is a row in
        // trp_original_meta with meta_key post_parent_id (class-query.php:1253),
        // joined on original_id — which is also how the plugin's own search works
        // (class-search.php:104-112).
        $counted = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT d.status AS status, COUNT(*) AS rows_found,'
                . ' SUM(CASE WHEN d.translated IS NULL OR CHAR_LENGTH(d.translated) = 0 THEN 1 ELSE 0 END) AS empty_rows'
                . ' FROM `' . $table . '` AS d'
                . ' INNER JOIN `' . $meta_table . '` AS m ON d.original_id = m.original_id'
                . ' WHERE m.meta_key = %s AND m.meta_value = %s AND d.block_type = %d'
                . ' GROUP BY d.status LIMIT %d',
                'post_parent_id',
                (string) $post_id,
                NIBWP_TRANSLATEPRESS_REGULAR_STRING,
                20
            ),
            ARRAY_A
        );

        $by_status = array_fill_keys(array_keys(NIBWP_TRANSLATEPRESS_STATUS), 0);
        $total = 0;
        $usable = 0;
        $reviewed = 0;

        foreach ($counted as $row) {
            $status = (int) ($row['status'] ?? 0);
            $found = (int) ($row['rows_found'] ?? 0);
            $empty = (int) ($row['empty_rows'] ?? 0);
            $name = nibwp_translatepress_status_name($status);

            $by_status[$name] = ($by_status[$name] ?? 0) + $found;
            $total += $found;

            if ($status !== NIBWP_TRANSLATEPRESS_STATUS['untranslated']) {
                $usable += $found - $empty;
            }
            if ($status === NIBWP_TRANSLATEPRESS_STATUS['reviewed']) {
                $reviewed += $found - $empty;
            }
        }

        $rows[] = [
            'code' => $code,
            'table_present' => true,
            'total_strings' => $total,
            'by_status' => $by_status,
            'translated' => $usable,
            'reviewed' => $reviewed,
            'percent_translated' => $total > 0 ? round($usable / $total * 100, 1) : 0.0,
        ];
    }

    return [
        'post_id' => $post_id,
        'post_title' => isset($post->post_title) && is_scalar($post->post_title) ? (string) $post->post_title : '',
        'languages' => $rows,
        'note' => __('A string is linked to a post only once TranslatePress has rendered that post at least once, so a page nobody has visited in a second language reports nothing rather than reporting trouble.', 'nibwp'),
    ];
}

/**
 * Counts for the theme and plugin strings of one language.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_gettext_progress(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress gettext progress', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $code = nibwp_translatepress_language($input['language_code'] ?? null);

    if ($code instanceof WP_Error) {
        return $code;
    }

    $table = nibwp_translatepress_gettext_table($code);

    if ($table instanceof WP_Error) {
        return $table;
    }

    if (!nibwp_translatepress_table_exists($table)) {
        return nibwp_translatepress_err(
            'no_table',
            sprintf(
                /* translators: %s: language code */
                __('TranslatePress has not built a gettext table for %s yet.', 'nibwp'),
                $code
            ),
            404
        );
    }

    // The gettext table has no block_type column, so this one counts every row.
    $counted = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT status, COUNT(*) AS rows_found FROM `' . $table . '` GROUP BY status LIMIT %d',
            20
        ),
        ARRAY_A
    );

    $by_status = array_fill_keys(array_keys(NIBWP_TRANSLATEPRESS_STATUS), 0);
    $by_status['unknown'] = 0;
    $total = 0;

    foreach ($counted as $row) {
        $name = nibwp_translatepress_status_name((int) ($row['status'] ?? 0));
        $found = (int) ($row['rows_found'] ?? 0);
        $by_status[$name] = ($by_status[$name] ?? 0) + $found;
        $total += $found;
    }

    return [
        'language_code' => $code,
        'total_strings' => $total,
        'by_status' => $by_status,
        'writable_here' => false,
        'note' => __('These are strings from the theme and from plugins. They are keyed by original text, text domain AND context, hashed together, so this integration reports them but does not write them — a write under the wrong hash lands on nothing. Edit them in the TranslatePress editor.', 'nibwp'),
    ];
}

/**
 * Search the dictionary by language, status or text.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_get_translations(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading TranslatePress translations', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    // A language was optional on this action before, defaulting to the first
    // non-default one. It still is, because a caller that has only one translation
    // language should not have to name it.
    $wanted = $input['language_code'] ?? null;

    if ($wanted === null || $wanted === '') {
        $wanted = nibwp_translatepress_first_translation_language();

        if ($wanted === null) {
            return nibwp_translatepress_err(
                'no_translation_language',
                __('This site has no translation language besides its default, so there is nothing to read. Add one with add_language.', 'nibwp'),
                404
            );
        }
    }

    $code = nibwp_translatepress_language($wanted);

    if ($code instanceof WP_Error) {
        return $code;
    }

    $table = nibwp_translatepress_existing_table($code);

    if ($table instanceof WP_Error) {
        return $table;
    }

    $limit = nibwp_translatepress_limit($input['per_page'] ?? ($input['limit'] ?? null));
    $page = isset($input['page']) ? max(1, (int) $input['page']) : 1;
    $offset = ($page - 1) * $limit;

    $where = '1 = 1';
    $args = [];

    if (isset($input['status']) && $input['status'] !== '') {
        $status = nibwp_translatepress_status_value($input['status']);

        if ($status instanceof WP_Error) {
            return $status;
        }

        $where .= ' AND status = %d';
        $args[] = $status;
    }

    if (isset($input['search']) && is_string($input['search']) && trim($input['search']) !== '') {
        $like = '%' . $wpdb->esc_like(trim($input['search'])) . '%';
        $where .= ' AND (original LIKE %s OR translated LIKE %s)';
        $args[] = $like;
        $args[] = $like;
    }

    $count_args = $args;
    $count_args[] = 1;

    $total = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where . ' LIMIT %d',
            ...$count_args
        )
    );

    $args[] = $limit;
    $args[] = $offset;

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, original, translated, status, block_type, original_id FROM `' . $table . '`'
            . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            ...$args
        ),
        ARRAY_A
    );

    $translations = [];

    foreach ($rows as $row) {
        $translations[] = nibwp_translatepress_row((array) $row);
    }

    return [
        'language_code' => $code,
        'total' => $total,
        'returned' => count($translations),
        'limit' => $limit,
        'page' => $page,
        'translations' => $translations,
        'note' => __('Copy an original from here rather than retyping it. The original text is the key, byte for byte, and a write under a nearly-identical original lands on a new row that nothing renders.', 'nibwp'),
    ];
}

/** The first configured language that is not the default, if there is one. */
function nibwp_translatepress_first_translation_language(): ?string
{
    $default = nibwp_translatepress_default_language();

    foreach (nibwp_translatepress_languages() as $code) {
        if ($code !== $default) {
            return $code;
        }
    }

    return null;
}

/**
 * One row, by id or by its original text.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_get_translation(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading one TranslatePress translation', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $located = nibwp_translatepress_locate_row($input);

    if ($located instanceof WP_Error) {
        return $located;
    }

    $row = nibwp_translatepress_row($located['row']);
    $posts = [];

    if ($row['linked_original_id'] !== null) {
        $found = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT meta_value FROM `' . nibwp_translatepress_meta_table() . '`'
                . ' WHERE original_id = %d AND meta_key = %s LIMIT %d',
                $row['linked_original_id'],
                'post_parent_id',
                20
            ),
            ARRAY_A
        );

        foreach ($found as $link) {
            if (isset($link['meta_value']) && is_numeric($link['meta_value'])) {
                $posts[] = (int) $link['meta_value'];
            }
        }
    }

    return [
        'language_code' => $located['code'],
        'translation' => $row,
        'appears_on_posts' => $posts,
        'note' => $row['linked_original_id'] === null
            ? __('This row has no link to the originals table, so it cannot be compared across languages and no post can be named for it. Rows written from outside the TranslatePress editor look like this.', 'nibwp')
            : null,
    ];
}

/**
 * The allow-listed settings, and the credentials as names only.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_get_settings(): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress settings', 'nibwp'))) {
        return $denied;
    }

    return [
        'settings' => nibwp_translatepress_setting_rows(
            nibwp_translatepress_settings(),
            nibwp_translatepress_public_settings()
        ),
        'withheld' => nibwp_translatepress_credential_rows(),
        'licence_status' => (string) get_option('trp_license_status', ''),
        'note' => __('Only the fields above report a value, and a value that is not a plain string or number is dropped rather than flattened. The engine key, the licence key and the licence details are reported as a name and whether they are set, never as a value.', 'nibwp'),
    ];
}

/**
 * Which engine, whether it is on, and how much of the allowance is spent.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_automatic_translation_settings(): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress automatic-translation settings', 'nibwp'))) {
        return $denied;
    }

    $mt = nibwp_translatepress_mt_settings();

    $engine = isset($mt['translation-engine']) && is_scalar($mt['translation-engine'])
        ? (string) $mt['translation-engine']
        : null;
    $enabled = isset($mt['machine-translation']) && (string) $mt['machine-translation'] === 'yes';

    // The character counter is a standalone option rather than a field of the
    // settings array (class-machine-translator-logger.php:126), because the
    // plugin updates it under a row lock on every translated request.
    $used = get_option('trp_machine_translation_counter', 0);
    $used = is_numeric($used) ? (int) $used : 0;

    $limit_enabled = isset($mt['machine_translation_limit_enabled'])
        && (string) $mt['machine_translation_limit_enabled'] === 'yes';
    $limit = isset($mt['machine_translation_limit']) && is_numeric($mt['machine_translation_limit'])
        ? (int) $mt['machine_translation_limit']
        : null;

    return [
        'enabled' => $enabled,
        'engine' => $engine,
        'settings' => nibwp_translatepress_setting_rows($mt, nibwp_translatepress_public_mt_settings()),
        'withheld' => nibwp_translatepress_credential_rows(),
        'characters' => [
            'used' => $used,
            'limit' => $limit_enabled ? $limit : null,
            'limit_enforced' => $limit_enabled,
            'percent_used' => $limit_enabled && $limit !== null && $limit > 0
                ? round($used / $limit * 100, 1)
                : null,
            'counter_resets_on' => isset($mt['machine_translation_counter_date']) && is_scalar($mt['machine_translation_counter_date'])
                ? (string) $mt['machine_translation_counter_date']
                : null,
        ],
        'api_key_returned' => false,
        'note' => __('The engine key is never returned. Whether it is set is in withheld, which is the whole of what anyone needs to know from here — a key that needs changing is changed on the plugin\'s own screen.', 'nibwp'),
    ];
}

/**
 * The settings that decide how a translated page looks to a search engine.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_seo_settings(): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Reading the TranslatePress SEO settings', 'nibwp'))) {
        return $denied;
    }

    $settings = nibwp_translatepress_settings();
    $mt = nibwp_translatepress_mt_settings();
    $advanced = nibwp_translatepress_advanced_settings();
    $slugs = nibwp_translatepress_slugs();
    $published = nibwp_translatepress_published();

    $slug_rows = [];

    foreach (nibwp_translatepress_languages() as $code) {
        $slug_rows[] = [
            'code' => $code,
            'url_slug' => $slugs[$code] ?? null,
            'is_published' => in_array($code, $published, true),
        ];
    }

    $concerns = [];

    foreach ($slug_rows as $row) {
        if ($row['is_published'] && ($row['url_slug'] === null || $row['url_slug'] === '')) {
            $concerns[] = sprintf(
                /* translators: %s: language code */
                __('%s is published but has no URL slug, so its pages have no distinct address for a search engine to index.', 'nibwp'),
                (string) $row['code']
            );
        }
    }

    return [
        'translated_slugs' => [
            'automatic' => isset($mt['automatically-translate-slug'])
                && (string) $mt['automatically-translate-slug'] === 'yes',
            'note' => __('When this is on, a post\'s slug is translated along with its content, so the translated page gets its own address instead of sharing the original one.', 'nibwp'),
        ],
        'language_slugs' => $slug_rows,
        'default_language_in_subdirectory' => isset($settings['add-subdirectory-to-default-language'])
            && (string) $settings['add-subdirectory-to-default-language'] === 'yes',
        'hreflang' => [
            'x_default' => isset($advanced['enable_hreflang_xdefault']) && is_scalar($advanced['enable_hreflang_xdefault'])
                ? (string) $advanced['enable_hreflang_xdefault']
                : null,
            'remove_locale' => isset($advanced['hreflang_remove_locale']) && is_scalar($advanced['hreflang_remove_locale'])
                ? (string) $advanced['hreflang_remove_locale']
                : null,
            'html_lang_remove_locale' => isset($advanced['html_lang_remove_locale']) && is_scalar($advanced['html_lang_remove_locale'])
                ? (string) $advanced['html_lang_remove_locale']
                : null,
            'note' => __('hreflang is how a search engine learns that two pages are the same content in two languages. An unpublished language gets no hreflang tag, which is correct — and a published one that is barely translated gets one, which is not.', 'nibwp'),
        ],
        'languages_sitemap_disabled' => isset($advanced['disable_languages_sitemap'])
            && (string) $advanced['disable_languages_sitemap'] === 'yes',
        'concerns' => $concerns,
    ];
}

/**
 * The findings that earn their place: what is wrong and nobody can see.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_translation_audit(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Auditing the TranslatePress translations', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $default = nibwp_translatepress_default_language();
    $published = nibwp_translatepress_published();
    $codes = nibwp_translatepress_bounded_languages();
    $limit = nibwp_translatepress_limit($input['limit'] ?? null, 10);

    $findings = [];

    $machine = [];
    $empty = [];
    $incomplete = [];
    $missing_tables = [];
    $tables = [];

    foreach ($codes as $code) {
        if ($code === $default) {
            continue;
        }

        $table = nibwp_translatepress_table($code);

        if ($table instanceof WP_Error || !nibwp_translatepress_table_exists($table)) {
            $missing_tables[] = $code;
            continue;
        }

        $tables[$code] = $table;
        $counts = nibwp_translatepress_counts($table);

        if ($counts instanceof WP_Error) {
            continue;
        }

        if ((int) $counts['machine_not_reviewed'] > 0) {
            $machine[] = [
                'code' => $code,
                'strings' => (int) $counts['machine_not_reviewed'],
                'samples' => nibwp_translatepress_samples($table, NIBWP_TRANSLATEPRESS_STATUS['machine'], $limit),
            ];
        }

        if ((int) $counts['empty_but_marked_translated'] > 0) {
            $empty[] = [
                'code' => $code,
                'strings' => (int) $counts['empty_but_marked_translated'],
            ];
        }

        $percent = (float) $counts['percent_translated'];

        if (in_array($code, $published, true) && $percent < NIBWP_TRANSLATEPRESS_INCOMPLETE_PERCENT) {
            $incomplete[] = [
                'code' => $code,
                'percent_translated' => $percent,
                'percent_reviewed' => (float) $counts['percent_reviewed'],
            ];
        }
    }

    // Translated in one language and not in another. The comparison is exact
    // rather than a difference of totals, because the two tables share the
    // original_id column that points at trp_original_strings — which also means a
    // row written without that link is not comparable, and the note says so.
    $inconsistent = [];

    foreach ($tables as $code => $table) {
        foreach ($tables as $other_code => $other_table) {
            if ($code === $other_code) {
                continue;
            }

            $behind = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM `' . $table . '` AS b'
                    . ' INNER JOIN `' . $other_table . '` AS a ON a.original_id = b.original_id'
                    . ' WHERE a.original_id IS NOT NULL AND a.block_type = %d AND b.block_type = %d'
                    . ' AND a.status <> %d AND CHAR_LENGTH(a.translated) > 0'
                    . ' AND (b.status = %d OR b.translated IS NULL OR CHAR_LENGTH(b.translated) = 0)',
                    NIBWP_TRANSLATEPRESS_REGULAR_STRING,
                    NIBWP_TRANSLATEPRESS_REGULAR_STRING,
                    NIBWP_TRANSLATEPRESS_STATUS['untranslated'],
                    NIBWP_TRANSLATEPRESS_STATUS['untranslated']
                )
            );

            if ($behind > 0) {
                $inconsistent[] = [
                    'code' => $code,
                    'behind_language' => $other_code,
                    'strings' => $behind,
                ];
            }
        }
    }

    if ($machine !== []) {
        $total = array_sum(array_column($machine, 'strings'));
        $findings[] = [
            'finding' => 'machine_translated_never_reviewed',
            'summary' => sprintf(
                /* translators: %d: number of strings */
                _n(
                    '%d string was translated by a machine and never reviewed by anybody.',
                    '%d strings were translated by a machine and never reviewed by anybody.',
                    (int) $total,
                    'nibwp'
                ),
                (int) $total
            ),
            'languages' => $machine,
            'what_to_do' => __('Read them and mark the ones that are right with mark_translation_reviewed, or correct them with update_translation, which marks them reviewed as it writes.', 'nibwp'),
        ];
    }

    if ($inconsistent !== []) {
        $findings[] = [
            'finding' => 'translated_in_one_language_not_another',
            'summary' => sprintf(
                /* translators: %d: number of language pairs */
                _n(
                    '%d language pair has strings translated on one side and not the other.',
                    '%d language pairs have strings translated on one side and not the other.',
                    count($inconsistent),
                    'nibwp'
                ),
                count($inconsistent)
            ),
            'pairs' => $inconsistent,
            'what_to_do' => __('These are strings somebody has already decided the wording of once. Translating them is the cheapest work on the list.', 'nibwp'),
        ];
    }

    if ($empty !== []) {
        $total = array_sum(array_column($empty, 'strings'));
        $findings[] = [
            'finding' => 'empty_translation_hiding_an_untranslated_string',
            'summary' => sprintf(
                /* translators: %d: number of strings */
                _n(
                    '%d string claims to be translated and holds nothing.',
                    '%d strings claim to be translated and hold nothing.',
                    (int) $total,
                    'nibwp'
                ),
                (int) $total
            ),
            'languages' => $empty,
            'what_to_do' => __('These are the worst kind: the page renders empty text where a sentence should be, and every count in the plugin reports them as done. Either write the translation or delete the row so the original shows again.', 'nibwp'),
        ];
    }

    if ($incomplete !== []) {
        $findings[] = [
            'finding' => 'published_while_far_from_complete',
            'summary' => sprintf(
                /* translators: 1: number of languages, 2: completion threshold */
                _n(
                    '%1$d published language is less than %2$d%% translated, so visitors are seeing pages in the wrong language.',
                    '%1$d published languages are less than %2$d%% translated, so visitors are seeing pages in the wrong language.',
                    count($incomplete),
                    'nibwp'
                ),
                count($incomplete),
                NIBWP_TRANSLATEPRESS_INCOMPLETE_PERCENT
            ),
            'languages' => $incomplete,
            'what_to_do' => __('Either finish them or unpublish them with unpublish_language until they are ready. An unpublished language is still editable; it is just not offered to visitors.', 'nibwp'),
        ];
    }

    if ($missing_tables !== []) {
        $findings[] = [
            'finding' => 'configured_without_a_table',
            'summary' => sprintf(
                /* translators: %s: list of language codes */
                __('Configured with no translation table yet: %s.', 'nibwp'),
                implode(', ', $missing_tables)
            ),
            'languages' => $missing_tables,
            'what_to_do' => __('TranslatePress builds the table the first time the site is viewed in that language. If one of these is published, visitors are being shown an entirely untranslated page.', 'nibwp'),
        ];
    }

    return [
        'languages_examined' => array_keys($tables),
        'findings' => $findings,
        'looks_reasonable' => $findings === [],
        'note' => __('The cross-language comparison only sees rows linked to the originals table. A row written outside the TranslatePress editor can lack that link, so this is a floor and not a ceiling.', 'nibwp'),
    ];
}

/**
 * A few originals at one status, so a finding can be checked rather than believed.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_translatepress_samples(string $table, int $status, int $limit): array
{
    global $wpdb;

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, original, translated, status, block_type, original_id FROM `' . $table . '`'
            . ' WHERE status = %d AND block_type = %d ORDER BY id DESC LIMIT %d',
            $status,
            NIBWP_TRANSLATEPRESS_REGULAR_STRING,
            $limit
        ),
        ARRAY_A
    );

    $out = [];

    foreach ($rows as $row) {
        $out[] = nibwp_translatepress_row((array) $row);
    }

    return $out;
}

/* ------------------------------------------------------------ language writes */

/**
 * Add a language, unpublished.
 *
 * TranslatePress's own add publishes immediately. This one does not: a language
 * added and published in the same breath is a language visitors see at zero
 * percent translated. publish_language is one call away and says what it is doing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_add_language(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Adding a TranslatePress language', 'nibwp'))) {
        return $denied;
    }

    $wanted = isset($input['language_code']) && is_string($input['language_code'])
        ? trim($input['language_code'])
        : '';

    if ($wanted === '') {
        return nibwp_translatepress_err('missing_language', __('Give the language code as language_code.', 'nibwp'), 422);
    }

    // add_language is the one action whose code CANNOT be matched against the
    // configured list — the point is that it is not in it yet. So it is validated
    // against the plugin's own rule and then against the plugin's own catalogue of
    // languages, and it never reaches a table name in this call: TranslatePress
    // builds the table itself on the first front-end request in that language.
    if (!nibwp_translatepress_safe_code($wanted)) {
        return nibwp_translatepress_err(
            'bad_language_code',
            __('A language code may hold only A-Z, a-z, 0-9, hyphen and underscore.', 'nibwp'),
            422
        );
    }

    $known = nibwp_translatepress_known_languages();

    if ($known !== [] && !in_array($wanted, $known, true)) {
        return nibwp_translatepress_err(
            'unrecognised_language',
            sprintf(
                /* translators: %s: the rejected code */
                __('TranslatePress does not recognise "%s" as a language code.', 'nibwp'),
                $wanted
            ),
            422
        );
    }

    $settings = nibwp_translatepress_settings();
    $configured = isset($settings['translation-languages']) && is_array($settings['translation-languages'])
        ? array_values(array_filter($settings['translation-languages'], 'is_string'))
        : [];

    if (in_array($wanted, $configured, true) || $wanted === nibwp_translatepress_default_language()) {
        return nibwp_translatepress_err(
            'language_already_configured',
            sprintf(
                /* translators: %s: language code */
                __('"%s" is already configured on this site.', 'nibwp'),
                $wanted
            ),
            409
        );
    }

    $slug = isset($input['slug']) && is_string($input['slug']) ? sanitize_title($input['slug']) : '';

    if ($slug === '') {
        $slug = sanitize_title((string) strtok($wanted, '_'));
    }

    $slugs = nibwp_translatepress_slugs();

    foreach ($slugs as $other => $other_slug) {
        if ($other !== $wanted && $other_slug === $slug) {
            return nibwp_translatepress_err(
                'slug_in_use',
                sprintf(
                    /* translators: 1: slug, 2: language code already using it */
                    __('The slug "%1$s" is already used by %2$s. Give a different slug.', 'nibwp'),
                    $slug,
                    $other
                ),
                409
            );
        }
    }

    $configured[] = $wanted;
    $slugs[$wanted] = $slug;

    $settings['translation-languages'] = array_values(array_unique($configured));
    $settings['publish-languages'] = nibwp_translatepress_published();
    $settings['url-slugs'] = $slugs;

    $saved = nibwp_translatepress_save_settings($settings);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    $landed = isset($saved['translation-languages']) && is_array($saved['translation-languages'])
        && in_array($wanted, $saved['translation-languages'], true);

    if (!$landed) {
        return nibwp_translatepress_err(
            'settings_rejected',
            __('TranslatePress did not keep the new language when it sanitized the settings, so nothing changed.', 'nibwp'),
            500
        );
    }

    return [
        'added' => $wanted,
        'url_slug' => $slug,
        'is_published' => in_array($wanted, nibwp_translatepress_published(), true),
        'note' => __('Added but NOT published, so no visitor sees it yet and nothing about the live site changed. Translate it first, then publish_language.', 'nibwp'),
    ];
}

/**
 * The language codes TranslatePress itself recognises, if it will say.
 *
 * @return array<int, string>
 */
function nibwp_translatepress_known_languages(): array
{
    $component = nibwp_translatepress_component('languages');

    if ($component === null || !method_exists($component, 'get_languages')) {
        return [];
    }

    try {
        $languages = $component->get_languages();
    } catch (\Throwable $error) {
        return [];
    }

    if (!is_array($languages)) {
        return [];
    }

    return array_values(array_filter(array_keys($languages), 'is_string'));
}

/**
 * Unconfigure a language AND delete the translations it holds.
 *
 * Named for what it does. TranslatePress's own removal leaves the dictionary table
 * in the database, so a language removed and re-added comes back with its
 * translations intact — which means an owner who asked for a language to be
 * removed, and was told it was, still has every string on disk. This action
 * deletes them, because "remove" that leaves the data behind is a lie in the other
 * direction and a surprise either way. It reports how many rows went.
 *
 * The drop is not recoverable from here. TranslatePress rebuilds an empty table
 * on the next front-end request in that language, so re-adding works — with
 * nothing in it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_remove_language(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Removing a TranslatePress language and its translations', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $code = nibwp_translatepress_language($input['language_code'] ?? null);

    if ($code instanceof WP_Error) {
        return $code;
    }

    if ($code === nibwp_translatepress_default_language()) {
        return nibwp_translatepress_err(
            'cannot_remove_default',
            __('The default language cannot be removed: it is the site\'s own content, not a translation of it. Set another language as default first, and read what set_default_language says before you do.', 'nibwp'),
            409
        );
    }

    $query = nibwp_translatepress_component('query');

    if ($query === null || !method_exists($query, 'drop_table')) {
        return nibwp_translatepress_err(
            'no_drop_api',
            __('This version of TranslatePress does not expose a way to drop a translation table, and this integration will not issue the statement itself. Remove the language on the plugin\'s own screen.', 'nibwp'),
            500
        );
    }

    $deleted = 0;
    $dropped = [];

    $table = nibwp_translatepress_table($code);

    if (!$table instanceof WP_Error && nibwp_translatepress_table_exists($table)) {
        $deleted = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE status <> %d', NIBWP_TRANSLATEPRESS_STATUS['untranslated'])
        );
        $query->drop_table($table);
        $dropped[] = $table;
    }

    $gettext = nibwp_translatepress_gettext_table($code);

    if (!$gettext instanceof WP_Error && nibwp_translatepress_table_exists($gettext)) {
        $query->drop_table($gettext);
        $dropped[] = $gettext;
    }

    $settings = nibwp_translatepress_settings();
    $configured = isset($settings['translation-languages']) && is_array($settings['translation-languages'])
        ? array_values(array_filter($settings['translation-languages'], 'is_string'))
        : [];
    $slugs = nibwp_translatepress_slugs();
    unset($slugs[$code]);

    $settings['translation-languages'] = array_values(array_diff($configured, [$code]));
    $settings['publish-languages'] = array_values(array_diff(nibwp_translatepress_published(), [$code]));
    $settings['url-slugs'] = $slugs;

    $saved = nibwp_translatepress_save_settings($settings);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    return [
        'removed' => $code,
        'translations_deleted' => $deleted,
        'tables_dropped' => $dropped,
        'recoverable' => false,
        'note' => __('The language is gone from the site and its translations are deleted. Nothing here can bring them back.', 'nibwp'),
    ];
}

/**
 * Make another configured language the site's default.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_set_default_language(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Setting the TranslatePress default language', 'nibwp'))) {
        return $denied;
    }

    $code = nibwp_translatepress_language($input['language_code'] ?? null);

    if ($code instanceof WP_Error) {
        return $code;
    }

    $previous = nibwp_translatepress_default_language();

    if ($code === $previous) {
        return [
            'default_language' => $code,
            'previous_default_language' => $previous,
            'changed' => false,
        ];
    }

    $settings = nibwp_translatepress_settings();
    $settings['default-language'] = $code;

    $saved = nibwp_translatepress_save_settings($settings);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    $landed = isset($saved['default-language']) && is_string($saved['default-language'])
        ? $saved['default-language']
        : $previous;

    return [
        'default_language' => $landed,
        'previous_default_language' => $previous,
        'changed' => $landed === $code,
        // This is the consequence nobody expects, and it is not reversible by
        // setting the default back unless the old tables are still there.
        'warning' => __('Every dictionary table is named after the default language, so the existing tables no longer match. TranslatePress will build new, empty ones. Treat this as a migration and check the site in each language before telling anyone it is done.', 'nibwp'),
    ];
}

/**
 * Publish or unpublish one language.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_set_published(array $input, bool $publish): array|WP_Error
{
    $what = $publish
        ? __('Publishing a TranslatePress language', 'nibwp')
        : __('Unpublishing a TranslatePress language', 'nibwp');

    if ($denied = nibwp_translatepress_require($what)) {
        return $denied;
    }

    $code = nibwp_translatepress_language($input['language_code'] ?? null);

    if ($code instanceof WP_Error) {
        return $code;
    }

    if (!$publish && $code === nibwp_translatepress_default_language()) {
        return nibwp_translatepress_err(
            'cannot_unpublish_default',
            __('The default language is the site itself and is always published.', 'nibwp'),
            409
        );
    }

    $progress = nibwp_translatepress_language_progress($code);
    $percent = isset($progress['percent_translated']) ? (float) $progress['percent_translated'] : 0.0;

    if ($publish && ($progress['table_present'] ?? false) !== true) {
        return nibwp_translatepress_err(
            'nothing_translated',
            sprintf(
                /* translators: %s: language code */
                __('%s has no translation table yet, so publishing it would show visitors an entirely untranslated site. Translate something first.', 'nibwp'),
                $code
            ),
            409
        );
    }

    $published = nibwp_translatepress_published();
    $already = in_array($code, $published, true);

    if ($already === $publish) {
        return [
            'language_code' => $code,
            'is_published' => $publish,
            'changed' => false,
            'percent_translated' => $percent,
        ];
    }

    $settings = nibwp_translatepress_settings();
    $settings['publish-languages'] = $publish
        ? array_values(array_unique(array_merge($published, [$code])))
        : array_values(array_diff($published, [$code]));

    $saved = nibwp_translatepress_save_settings($settings);

    if ($saved instanceof WP_Error) {
        return $saved;
    }

    $now = isset($saved['publish-languages']) && is_array($saved['publish-languages'])
        && in_array($code, $saved['publish-languages'], true);

    return [
        'language_code' => $code,
        'is_published' => $now,
        'changed' => $now === $publish,
        'percent_translated' => $percent,
        'percent_reviewed' => isset($progress['percent_reviewed']) ? (float) $progress['percent_reviewed'] : 0.0,
        'note' => $publish
            ? sprintf(
                /* translators: 1: language code, 2: percentage translated */
                __('%1$s is now offered to every visitor, at %2$s%% translated. Say that number to whoever asked.', 'nibwp'),
                $code,
                (string) $percent
            )
            : __('No visitor is offered this language any more. Its translations are untouched and it stays editable.', 'nibwp'),
    ];
}

/* --------------------------------------------------------- translation writes */

/**
 * Find one row, by id or by original text, in a verified language.
 *
 * @param array<string, mixed> $input
 * @return array{code: string, table: string, row: array<string, mixed>}|WP_Error
 */
function nibwp_translatepress_locate_row(array $input): array|WP_Error
{
    global $wpdb;

    $code = nibwp_translatepress_language($input['language_code'] ?? null);

    if ($code instanceof WP_Error) {
        return $code;
    }

    $table = nibwp_translatepress_existing_table($code);

    if ($table instanceof WP_Error) {
        return $table;
    }

    $columns = 'id, original, translated, status, block_type, original_id';
    $id = isset($input['translation_id']) ? (int) $input['translation_id'] : 0;
    $original = isset($input['original']) && is_string($input['original']) ? $input['original'] : '';

    if ($id > 0) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ' . $columns . ' FROM `' . $table . '` WHERE id = %d LIMIT %d',
                $id,
                1
            ),
            ARRAY_A
        );
    } elseif ($original !== '') {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ' . $columns . ' FROM `' . $table . '` WHERE original = %s AND block_type = %d LIMIT %d',
                $original,
                NIBWP_TRANSLATEPRESS_REGULAR_STRING,
                1
            ),
            ARRAY_A
        );
    } else {
        return nibwp_translatepress_err(
            'missing_row',
            __('Name the row by translation_id or by its exact original text as original.', 'nibwp'),
            422
        );
    }

    if (!is_array($row) || $row === []) {
        return nibwp_translatepress_err(
            'row_not_found',
            __('No string in that language matches. The original must match byte for byte, including punctuation and spacing.', 'nibwp'),
            404
        );
    }

    return ['code' => $code, 'table' => $table, 'row' => $row];
}

/**
 * Update one translation.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_update_translation(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Updating a TranslatePress translation', 'nibwp'))) {
        return $denied;
    }

    // translation_data is the shape this action has always taken; the flat keys
    // are accepted because every other action here uses them.
    $data = isset($input['translation_data']) && is_array($input['translation_data'])
        ? $input['translation_data']
        : $input;

    if (!isset($data['language_code']) || $data['language_code'] === '') {
        $data['language_code'] = $input['language_code'] ?? null;
    }

    $written = nibwp_translatepress_write_one($data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return ['updated' => true] + $written;
}

/**
 * Update several translations in one call.
 *
 * Per row, all-or-nothing: a row is validated completely — language configured,
 * table present, original non-empty, translation a string — before anything is
 * written for it, and a row that fails is reported with a reason while the others
 * proceed. The counts say exactly what happened: `rows_written` is how many writes
 * the database accepted, `rows_changed` is how many rows actually differ
 * afterwards. They are not the same number when a translation was already what it
 * was being set to, and reporting the first as the second would be a claim about
 * work that did not happen.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_update_translations(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Updating TranslatePress translations in bulk', 'nibwp'))) {
        return $denied;
    }

    $batch = isset($input['translations']) && is_array($input['translations']) ? $input['translations'] : [];

    if ($batch === []) {
        return nibwp_translatepress_err(
            'missing_translations',
            __('Give the rows as translations: a list of objects with original, translated and language_code.', 'nibwp'),
            422
        );
    }

    if (count($batch) > NIBWP_TRANSLATEPRESS_MAX_ROWS) {
        return nibwp_translatepress_err(
            'batch_too_large',
            sprintf(
                /* translators: 1: number of rows sent, 2: maximum */
                __('%1$d rows were sent and the most this will write in one call is %2$d. Split the batch.', 'nibwp'),
                count($batch),
                NIBWP_TRANSLATEPRESS_MAX_ROWS
            ),
            422
        );
    }

    $fallback = $input['language_code'] ?? null;
    $written = [];
    $failed = [];
    $rows_changed = 0;

    foreach ($batch as $index => $row) {
        if (!is_array($row)) {
            $failed[] = ['index' => (int) $index, 'reason' => __('Not an object.', 'nibwp')];
            continue;
        }

        if (!isset($row['language_code']) || $row['language_code'] === '') {
            $row['language_code'] = $fallback;
        }

        $result = nibwp_translatepress_write_one($row);

        if ($result instanceof WP_Error) {
            $failed[] = [
                'index' => (int) $index,
                'code' => $result->get_error_code(),
                'reason' => $result->get_error_message(),
            ];
            continue;
        }

        $rows_changed += (int) $result['rows_changed'];
        $written[] = ['index' => (int) $index] + $result;
    }

    return [
        'rows_sent' => count($batch),
        'rows_written' => count($written),
        'rows_changed' => $rows_changed,
        'rows_failed' => count($failed),
        'written' => $written,
        'failed' => $failed,
        'note' => __('rows_changed counts rows the database actually changed. A row whose translation already said this is written and not changed, which is why the two numbers can differ.', 'nibwp'),
    ];
}

/**
 * Write one translation, keyed by its original text.
 *
 * The write marks the row reviewed, because a person asked for this wording. The
 * row is matched on original AND block_type = 0: a translation block row holds the
 * HTML of a whole chunk, and writing a plain sentence over one would replace a
 * page section with a sentence.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_write_one(array $data): array|WP_Error
{
    global $wpdb;

    $code = nibwp_translatepress_language($data['language_code'] ?? null);

    if ($code instanceof WP_Error) {
        return $code;
    }

    if ($code === nibwp_translatepress_default_language()) {
        return nibwp_translatepress_err(
            'cannot_translate_default',
            __('That is the site\'s own language. There is nothing to translate into it; edit the post instead.', 'nibwp'),
            422
        );
    }

    $original = isset($data['original']) && is_string($data['original']) ? $data['original'] : '';
    $translated = $data['translated'] ?? null;

    if ($original === '') {
        return nibwp_translatepress_err(
            'missing_original',
            __('A translation needs the original string as original. It is the key, so it must match byte for byte.', 'nibwp'),
            422
        );
    }

    if (!is_string($translated)) {
        return nibwp_translatepress_err(
            'missing_translated',
            __('A translation needs the new wording as translated. To clear one, use delete_translation, which lets the original show again.', 'nibwp'),
            422
        );
    }

    $table = nibwp_translatepress_existing_table($code);

    if ($table instanceof WP_Error) {
        return $table;
    }

    $status = NIBWP_TRANSLATEPRESS_STATUS['reviewed'];

    $existing = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT id FROM `' . $table . '` WHERE original = %s AND block_type = %d LIMIT %d',
            $original,
            NIBWP_TRANSLATEPRESS_REGULAR_STRING,
            1
        )
    );

    if (is_numeric($existing)) {
        $changed = $wpdb->update(
            $table,
            ['translated' => $translated, 'status' => $status],
            ['id' => (int) $existing]
        );

        if ($changed === false) {
            return nibwp_translatepress_err(
                'write_failed',
                __('The database refused the update, so this row is unchanged.', 'nibwp'),
                500
            );
        }

        return [
            'language_code' => $code,
            'id' => (int) $existing,
            'created' => false,
            'status' => $status,
            'status_name' => nibwp_translatepress_status_name($status),
            'rows_changed' => (int) $changed,
        ];
    }

    // A row TranslatePress has never seen: inserted with original_id left NULL,
    // because the originals table is the plugin's to populate when it next renders
    // the page. Rendering looks the string up by its text (class-query.php:105),
    // so the translation works immediately; the audit is what notices the missing
    // link later.
    $inserted = $wpdb->insert($table, [
        'original' => $original,
        'translated' => $translated,
        'status' => $status,
        'block_type' => NIBWP_TRANSLATEPRESS_REGULAR_STRING,
    ]);

    if ($inserted === false) {
        return nibwp_translatepress_err(
            'write_failed',
            __('The database refused the insert, so nothing was written.', 'nibwp'),
            500
        );
    }

    return [
        'language_code' => $code,
        'id' => (int) $wpdb->insert_id,
        'created' => true,
        'status' => $status,
        'status_name' => nibwp_translatepress_status_name($status),
        'rows_changed' => 1,
        'note' => __('This original was not in the dictionary, so a row was added. If the string does not appear on the site, the original did not match what the page actually renders.', 'nibwp'),
    ];
}

/**
 * Mark a machine translation as reviewed, without touching its wording.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_mark_reviewed(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Marking a TranslatePress translation as reviewed', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $located = nibwp_translatepress_locate_row($input);

    if ($located instanceof WP_Error) {
        return $located;
    }

    $row = nibwp_translatepress_row($located['row']);

    if ($row['status'] === NIBWP_TRANSLATEPRESS_STATUS['untranslated']) {
        return nibwp_translatepress_err(
            'nothing_to_review',
            __('That string has no translation yet, so there is nothing to approve. Write one with update_translation.', 'nibwp'),
            409
        );
    }

    if ($row['translated'] === '') {
        return nibwp_translatepress_err(
            'empty_translation',
            __('That row claims a translation and holds nothing. Approving it would publish an empty sentence. Write the translation or delete the row.', 'nibwp'),
            409
        );
    }

    if ($row['status'] === NIBWP_TRANSLATEPRESS_STATUS['reviewed']) {
        return [
            'language_code' => $located['code'],
            'id' => $row['id'],
            'status_name' => $row['status_name'],
            'changed' => false,
        ];
    }

    $changed = $wpdb->update(
        $located['table'],
        ['status' => NIBWP_TRANSLATEPRESS_STATUS['reviewed']],
        ['id' => $row['id']]
    );

    if ($changed === false) {
        return nibwp_translatepress_err(
            'write_failed',
            __('The database refused the update, so the status is unchanged.', 'nibwp'),
            500
        );
    }

    return [
        'language_code' => $located['code'],
        'id' => $row['id'],
        'was' => $row['status_name'],
        'status_name' => 'reviewed',
        'changed' => (int) $changed > 0,
        'rows_changed' => (int) $changed,
        'translation_unchanged' => true,
        'note' => __('The wording is exactly as it was; only the claim that somebody read it has changed. Do not mark a batch reviewed on an owner\'s behalf without their say — reviewed is a statement about a person, not about the text.', 'nibwp'),
    ];
}

/**
 * Delete one translation so the original shows again.
 *
 * Only this language's row goes. TranslatePress's own delete removes the original
 * from every language at once (class-regular-delete.php:31); this one is narrower
 * on purpose, because "that French sentence is wrong" is not a reason to lose the
 * German one.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_translatepress_delete_translation(array $input): array|WP_Error
{
    if ($denied = nibwp_translatepress_require(__('Deleting a TranslatePress translation', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $located = nibwp_translatepress_locate_row($input);

    if ($located instanceof WP_Error) {
        return $located;
    }

    $row = nibwp_translatepress_row($located['row']);

    $deleted = $wpdb->delete($located['table'], ['id' => $row['id']]);

    if ($deleted === false) {
        return nibwp_translatepress_err(
            'write_failed',
            __('The database refused the delete, so the translation is still there.', 'nibwp'),
            500
        );
    }

    return [
        'language_code' => $located['code'],
        'id' => $row['id'],
        'deleted' => (int) $deleted > 0,
        'rows_changed' => (int) $deleted,
        'was' => $row['status_name'],
        'note' => __('The page now shows the original text in that language instead of a translation. Only this language lost the string; the others are untouched.', 'nibwp'),
    ];
}
