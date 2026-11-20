<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: SureForms.
 *
 * One hub. Reads cover the forms, their fields, what happens after a submission,
 * the email notifications, the spam protection and the entries; writes cover the
 * things an owner actually asks for — make a form, rename it, duplicate it,
 * change the success message, change the redirect, switch the notification on or
 * off and set its recipient, switch the captcha — plus the deletions, each named
 * for what it does.
 *
 * Verified against SureForms 2.12.8 (sureforms.php:10, SRFM_VER at sureforms.php:28).
 *
 * WHERE THE FIELDS LIVE, and why they are READ-ONLY here.
 * A SureForms form is a Gutenberg document. The post type is `sureforms_form`
 * (sureforms.php:31) and the fields are `srfm/*` blocks in the post's
 * `post_content` — SureForms reads them back with exactly that call,
 * parse_blocks( $post->post_content ) (inc/abilities/forms/get-form.php:116).
 * There is no field meta array to edit.
 *
 * Block markup is writable in principle, and this file does write post_content
 * when it duplicates a form. What it will NOT do is synthesise or edit an
 * individual field, for three reasons found in the source rather than assumed:
 *
 *   1. A field's input name is derived, not stored. Base::set_field_name() builds
 *      it as `$string . '-' . $this->block_slug` (inc/fields/base.php:456) over a
 *      unique slug of `'srfm-' . $slug . '-' . $block_id . $input_label`
 *      (inc/fields/base.php:494). So the key a submission arrives under — and the
 *      key every historical entry is already stored under — is a function of the
 *      block's `block_id`, its `slug` and its label together. Write a field with
 *      a block_id the editor would not have minted and the new submissions stop
 *      lining up with the old entries, silently.
 *   2. `block_id` is minted in the editor, not in PHP. It is a plain attribute on
 *      every field block (inc/blocks/input/block.json:24) with no default and no
 *      server-side generator; `slug` is the companion (inc/blocks/input/block.json:75).
 *      Two fields that end up sharing a slug collide on submit, and the editor's
 *      de-duplication is the thing that prevents it.
 *   3. SureForms itself does not edit fields surgically. Its own update-form
 *      ability regenerates the WHOLE post_content from a question list via
 *      Field_Mapping::generate_gutenberg_fields_from_questions()
 *      (inc/abilities/forms/update-form.php:397-407, generator at
 *      inc/ai-form-builder/field-mapping.php:32) — which replaces every existing
 *      field, and every existing block_id with it. There is no vendor path that
 *      adds one field and leaves the others alone, so there is nothing here to
 *      follow and a great deal to get wrong.
 *
 * Each of the ~20 field types also carries its own attribute schema with its own
 * required keys (one block.json per directory under inc/blocks/). "Add a field"
 * from the outside means reimplementing all of that plus the editor's slug
 * bookkeeping, and the failure mode is not a visible error — it is a form that
 * looks right and quietly loses answers. So get_form_fields and
 * get_required_fields report, and the answer when asked to add a field is: open
 * the form in the block editor. Both actions say so in their own response.
 *
 * Where post_content IS written — duplicate_form — it goes through wp_slash().
 * wp_insert_post() unslashes what it is handed, and block markup is exactly
 * where that bites: serialize_block_attributes() escapes `&` as & and `--`
 * as -- to keep the delimiter comment valid HTML, and an unslashed
 * write strips those backslashes and leaves the escape as literal text. The
 * duplicate is byte-compared against its source before it is reported as a
 * duplicate, so a corrupted copy is a refusal rather than a surprise.
 *
 * ENTRIES ARE PERSONAL DATA, and they are NOT a post type. They live in
 * SureForms' own table, `{prefix}srfm_entries` — the suffix is 'entries'
 * (inc/database/tables/entries.php:33) and the prefix is built as
 * `$wpdb->prefix . 'srfm_'` (inc/database/base.php:104). Columns are ID,
 * form_id, user_id, status ('unread'|'read'|'trash'), type, form_data, logs,
 * notes, submission_info, extras, created_at, updated_at
 * (inc/database/tables/entries.php:119-141). The array columns are JSON, not
 * PHP serialisation — Base decodes them with json_decode (inc/database/base.php:1276,
 * encodes at :1305), so reading an entry never needs to unserialise anything.
 *
 * Reading an entry takes manage_options. That is not a guess and not a floor
 * picked by hand: SureForms gates every one of its own entry abilities on it —
 * list (inc/abilities/entries/list-entries.php:36), read
 * (inc/abilities/entries/get-entry.php:39), bulk read
 * (inc/abilities/entries/bulk-get-entries.php:46), status change
 * (inc/abilities/entries/update-entry-status.php:43) and delete
 * (inc/abilities/entries/delete-entry.php:42) — and its ability base class makes
 * manage_options a hard minimum that an extension cannot lower
 * (inc/abilities/abstract-ability.php:32, enforced at :157).
 *
 * The forms surface takes the same capability, and for once that is the post
 * type's own answer rather than a stricter choice: `sureforms_form` is registered
 * with every capability mapped to manage_options — edit_post, read_post,
 * delete_post, edit_posts, edit_others_posts, publish_posts, read_private_posts,
 * create_posts (inc/post-types.php:291-301). Because map_meta_cap is left off,
 * WordPress resolves edit_post on this type to the literal 'manage_options'
 * rather than deriving it, so the per-object check below lands on a capability a
 * real administrator actually holds. It is read off the registered post type
 * instead of hard-coded, because that is where the answer lives.
 *
 * CREDENTIALS. SureForms keeps the captcha secrets in its security option,
 * `srfm_security_settings_options` — the reCAPTCHA v2 checkbox, v2 invisible and
 * v3 secrets, the Cloudflare Turnstile secret and the hCaptcha secret
 * (inc/global-settings/global-settings.php:298-324). SureForms masks exactly
 * those five in its own settings ability (inc/abilities/settings/settings-secret-keys.php:31-37).
 * This file withholds those five by name AND withholds anything whose key reads
 * like a credential — secret, token, password, api key, webhook, licence — so a
 * key added by a later release or by a Pro add-on is withheld by default rather
 * than leaked by default. Settings leave through an ALLOW-LIST of keys whose
 * value may travel; a key on neither list is reported as a name and whether it
 * is set. Site keys are public by construction (they are printed into the page,
 * inc/fields/inlinebutton-markup.php:179) and are reported, which is how an
 * owner can be told their captcha is half-configured.
 *
 * NO unserialize(). One meta value here can legitimately be a serialised string:
 * `_srfm_form_confirmation` on a legacy form, which SureForms itself reads with
 * maybe_unserialize() (inc/post-types.php:160). This file uses the same core
 * function, never the bare one, and validates the shape before it writes
 * anything back. The check parses this file's own source to prove it.
 */

/** SureForms' form post type (sureforms.php:31). */
const NIBWP_SUREFORMS_FORM_TYPE = 'sureforms_form';

/** The entries table suffix; the prefix is $wpdb->prefix . 'srfm_' (inc/database/base.php:104). */
const NIBWP_SUREFORMS_ENTRIES_TABLE = 'srfm_entries';

/** The notification list, index 0 being the one the editor shows first (inc/abilities/forms/form-metadata.php:175-218). */
const NIBWP_SUREFORMS_NOTIFICATION_META = '_srfm_email_notification';

/** Success message / redirect (inc/abilities/forms/form-metadata.php:53-70). */
const NIBWP_SUREFORMS_CONFIRMATION_META = '_srfm_form_confirmation';

/** Which captcha a form uses, or 'none' (inc/fields/inlinebutton-markup.php:130). */
const NIBWP_SUREFORMS_CAPTCHA_META = '_srfm_captcha_security_type';

/** The reCAPTCHA variant, when the captcha type is g-recaptcha (inc/fields/inlinebutton-markup.php:170). */
const NIBWP_SUREFORMS_RECAPTCHA_META = '_srfm_form_recaptcha';

/** Where the captcha secrets live (inc/global-settings/global-settings.php:314-324). */
const NIBWP_SUREFORMS_SECURITY_OPTION = 'srfm_security_settings_options';

/** General behaviour, including the IP and views switches (inc/abilities/settings/get-global-settings.php:144). */
const NIBWP_SUREFORMS_GENERAL_OPTION = 'srfm_general_settings_options';

/**
 * Reading, exporting, marking or deleting an entry takes this.
 *
 * Every SureForms entry ability requires it and its ability base class refuses
 * to register one that asks for less (inc/abilities/abstract-ability.php:32,
 * inc/abilities/entries/get-entry.php:39). Entries hold whatever a visitor
 * typed, so this is the floor and not a starting point.
 */
const NIBWP_SUREFORMS_ENTRIES_CAP = 'manage_options';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_SUREFORMS_MAX_ROWS = 100;

/** How many forms an audit will walk in one pass. */
const NIBWP_SUREFORMS_MAX_FORMS = 200;

/** Longest string value this hub will echo back out of a form or an entry. */
const NIBWP_SUREFORMS_MAX_VALUE_CHARS = 2000;

/**
 * Is SureForms actually loaded?
 *
 * SRFM_VER is defined by the main plugin file before anything else is required
 * (sureforms.php:28, require at :42) and Plugin_Loader is what boots the rest,
 * so between them they separate "not installed" from "installed and half-loaded".
 */
function nibwp_sureforms_guard(): ?WP_Error
{
    if (!defined('SRFM_VER') && !class_exists('SRFM\\Plugin_Loader')) {
        return new WP_Error(
            'nibwp_sureforms_missing',
            __('SureForms is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_sureforms_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_sureforms_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_sureforms_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_sureforms_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/**
 * The capability the forms surface takes.
 *
 * Read off the registered post type, because that is where the answer lives and
 * a later SureForms release could change it. On 2.12.8 it is manage_options
 * (inc/post-types.php:296).
 */
function nibwp_sureforms_forms_cap(): string
{
    if (function_exists('get_post_type_object')) {
        $type = get_post_type_object(NIBWP_SUREFORMS_FORM_TYPE);

        if (is_object($type) && isset($type->cap) && is_object($type->cap) && isset($type->cap->edit_posts)) {
            $cap = (string) $type->cap->edit_posts;

            if ($cap !== '') {
                return $cap;
            }
        }
    }

    return 'manage_options';
}

/** Reading the form list, a form, its fields or its settings. */
function nibwp_sureforms_require_forms(string $what): ?WP_Error
{
    $cap = nibwp_sureforms_forms_cap();

    return current_user_can($cap) ? null : nibwp_sureforms_forbidden($what, $cap);
}

/** The capability gate for everything that touches an entry. */
function nibwp_sureforms_require_entries(string $what): ?WP_Error
{
    return current_user_can(NIBWP_SUREFORMS_ENTRIES_CAP)
        ? null
        : nibwp_sureforms_forbidden($what, NIBWP_SUREFORMS_ENTRIES_CAP);
}

/**
 * May this account change THIS form?
 *
 * The per-object question, which is the right question. On this post type it
 * resolves to manage_options: sureforms_form maps edit_post and delete_post to
 * manage_options and leaves map_meta_cap off (inc/post-types.php:292-294), so
 * WordPress uses the mapped capability literally rather than deriving one from
 * post ownership. No weaker fallback is offered — there is nothing to rescue.
 *
 * @param string $meta_cap 'edit_post' or 'delete_post'.
 */
function nibwp_sureforms_can_touch_form(int $form_id, string $what, string $meta_cap = 'edit_post'): ?WP_Error
{
    return current_user_can($meta_cap, $form_id) ? null : nibwp_sureforms_forbidden($what, $meta_cap);
}

/**
 * Resolve a form id, checking ONLY that it is a SureForms form.
 *
 * Deliberately alone and deliberately first. An id from an agent is a claim, and
 * if the capability check ran first then "yes you may edit post 12" would be
 * answered about a page — and the next line would be writing SureForms meta onto
 * it, or trashing it. So the type is established before authority is discussed,
 * and the two refusals carry different codes so a caller (and the check) can
 * tell which one spoke.
 *
 * Declared `object` rather than `WP_Post|WP_Error`: a union naming WP_Error is a
 * parse error on this runtime, and WP_Error is an object, so callers test with
 * instanceof.
 */
function nibwp_sureforms_form(mixed $raw): object
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_sureforms_err('missing_form_id', __('Give the form as form_id, a positive post ID.', 'nibwp'), 422);
    }

    $post = get_post($id);

    if (!is_object($post) || !isset($post->post_type)) {
        return nibwp_sureforms_err(
            'form_not_found',
            sprintf(
                /* translators: %d: the post ID that was asked for */
                __('There is no post with ID %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ((string) $post->post_type !== NIBWP_SUREFORMS_FORM_TYPE) {
        return nibwp_sureforms_err(
            'not_a_form',
            sprintf(
                /* translators: 1: post ID, 2: the post type it actually is, 3: SureForms' form post type */
                __('Post %1$d is a "%2$s", not a "%3$s". Nothing was read or changed.', 'nibwp'),
                $id,
                (string) $post->post_type,
                NIBWP_SUREFORMS_FORM_TYPE
            ),
            422
        );
    }

    return $post;
}

/** The entries table name. The prefix comes from $wpdb, never from input. */
function nibwp_sureforms_entries_table(): string
{
    global $wpdb;

    return (is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_')
        . NIBWP_SUREFORMS_ENTRIES_TABLE;
}

/**
 * Resolve an entry id, checking ONLY that a SureForms entry row exists for it.
 *
 * The same shape and the same reason as nibwp_sureforms_form(): establish what
 * the id IS before discussing who may touch it. An entry is a row in SureForms'
 * own table rather than a post, so the equivalent of the post-type check is that
 * the row is in that table at all — which is also why handing an entry action a
 * page ID cannot reach anybody's page.
 *
 * Declared `object`: WP_Error on refusal, a plain row object on success.
 */
function nibwp_sureforms_entry(mixed $raw): object
{
    global $wpdb;

    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_sureforms_err('missing_entry_id', __('Give the entry as entry_id, a positive row ID.', 'nibwp'), 422);
    }

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
        return nibwp_sureforms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . nibwp_sureforms_entries_table() . ' WHERE ID = %d',
        $id
    ));

    if (!is_object($row) || !isset($row->ID)) {
        return nibwp_sureforms_err(
            'entry_not_found',
            sprintf(
                /* translators: %d: the entry row ID that was asked for */
                __('There is no SureForms entry with ID %d. An entry is a row in SureForms\' own table rather than a post, so a post ID will not find one.', 'nibwp'),
                $id
            ),
            404
        );
    }

    return $row;
}

/** Clamp a row count that arrived over the wire. */
function nibwp_sureforms_limit(mixed $value, int $default = 25): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_SUREFORMS_MAX_ROWS, $limit));
}

/** Clamp an offset that arrived over the wire. */
function nibwp_sureforms_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** Clamp a window in days. */
function nibwp_sureforms_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(365, $days));
}

/**
 * Settings keys whose VALUE may be reported, and what each one is.
 *
 * Everything else is reported as a name and whether it is set. These are the
 * keys that answer "what does this form do when somebody submits it" and "is the
 * spam protection actually configured", which are the questions an owner asks.
 *
 * Site keys are here on purpose: a captcha site key is printed into the page by
 * SureForms itself (inc/fields/inlinebutton-markup.php:179), so it is not a
 * secret, and reporting it is the only way to say "the secret is set but the
 * site key is missing".
 *
 * @return array<string, string> key => bool|int|string
 */
function nibwp_sureforms_public_settings(): array
{
    return [
        // Security option — the public half.
        'srfm_honeypot'                => 'bool',
        'srfm_captcha_language'        => 'string',
        'srfm_v2_checkbox_site_key'    => 'string',
        'srfm_v2_invisible_site_key'   => 'string',
        'srfm_v3_site_key'             => 'string',
        'srfm_cf_turnstile_site_key'   => 'string',
        'srfm_hcaptcha_site_key'       => 'string',
        'srfm_cf_appearance_mode'      => 'string',
        // General option.
        'srfm_form_analytics'          => 'bool',
        'srfm_ip_log'                  => 'bool',
        'srfm_enable_quick_action_sidebar' => 'bool',
        'srfm_delete_data_on_uninstall' => 'bool',
        'srfm_default_dynamic_block_option' => 'string',
        // Email summary option.
        'srfm_email_summary'           => 'bool',
        'srfm_email_sent_to'           => 'string',
        'srfm_schedule_report'         => 'string',
        // Per-form meta, reported under the same rules.
        '_srfm_submit_button_text'     => 'string',
        '_srfm_use_label_as_placeholder' => 'bool',
        '_srfm_form_container_width'   => 'int',
        '_srfm_instant_form'           => 'bool',
        '_srfm_submit_alignment'       => 'string',
        '_srfm_captcha_security_type'  => 'string',
        '_srfm_form_recaptcha'         => 'string',
        '_srfm_is_ai_generated'        => 'bool',
        '_srfm_page_form_title'        => 'string',
    ];
}

/**
 * Keys whose value must never travel, named explicitly.
 *
 * These are the five SureForms masks in its own settings ability
 * (inc/abilities/settings/settings-secret-keys.php:31-37), which is the vendor's
 * own statement of what is secret in this plugin.
 *
 * This list is the floor, not the whole gate: nibwp_sureforms_is_secret() also
 * withholds anything whose key READS like a credential, so a key added by a
 * later release or by a Pro add-on is withheld by default rather than leaked by
 * default. And the allow-list above governs what leaves in the first place — a
 * key on neither list is reported as a name only.
 *
 * @return array<int, string>
 */
function nibwp_sureforms_secret_keys(): array
{
    return [
        'srfm_v2_checkbox_secret_key',
        'srfm_v2_invisible_secret_key',
        'srfm_v3_secret_key',
        'srfm_cf_turnstile_secret_key',
        'srfm_hcaptcha_secret_key',
    ];
}

/**
 * Does this key name a credential?
 *
 * The named list first, then the shape of the name. The pattern is what covers
 * the keys nobody here has heard of — a Pro add-on's mail-service API key, a
 * webhook URL (which IS the credential, since whoever holds it can post to it),
 * a licence key, an OAuth token. Site keys are excluded by name because they are
 * public by construction.
 */
function nibwp_sureforms_is_secret(string $key): bool
{
    if (in_array($key, nibwp_sureforms_secret_keys(), true)) {
        return true;
    }

    $lower = strtolower($key);

    // A site key is printed into the page, so it is not a credential and must
    // not be swept up by the "_key" pattern below.
    if (str_contains($lower, 'site_key') || str_contains($lower, 'sitekey') || str_contains($lower, 'public_key')) {
        return false;
    }

    foreach (['secret', 'token', 'password', 'passwd', 'api_key', 'apikey', 'webhook', 'licence', 'license', 'private_key', 'client_secret', 'auth'] as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }

    // A trailing _key catches whatever the patterns above missed, after site
    // keys have already been let through.
    return str_ends_with($lower, '_key') || $lower === 'key';
}

/**
 * Keep one scalar to a sane size, and refuse to stringify anything else.
 *
 * A non-scalar is DROPPED rather than cast. Casting an array to a string is how
 * a credential nested one level down rides out under an innocent key, and
 * "Array" in a response is worse than nothing anyway.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_sureforms_clip(mixed $value): mixed
{
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    if (!is_scalar($value)) {
        return null;
    }

    $text = (string) $value;

    return strlen($text) > NIBWP_SUREFORMS_MAX_VALUE_CHARS
        ? substr($text, 0, NIBWP_SUREFORMS_MAX_VALUE_CHARS) . '…'
        : $text;
}

/**
 * Strip anything that looks like a credential out of a structure, at any depth.
 *
 * The allow-list governs the settings reports, so this is belt and braces — but
 * it earns its place on the paths where the shape is not ours. An entry's
 * form_data is keyed by whatever the form's fields are called, a notification
 * body is author-supplied, and a Pro add-on can put anything in `extras`. A
 * secret three levels inside one of those would otherwise travel under a key
 * nobody thought to check.
 *
 * @param mixed $value
 * @return mixed
 */
function nibwp_sureforms_scrub(mixed $value, int $depth = 0): mixed
{
    if ($depth > 8) {
        return null;
    }

    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (!is_array($value)) {
        return nibwp_sureforms_clip($value);
    }

    $clean = [];

    foreach ($value as $key => $item) {
        if (is_string($key) && nibwp_sureforms_is_secret($key)) {
            $clean[$key] = '[withheld]';
            continue;
        }

        $clean[$key] = nibwp_sureforms_scrub($item, $depth + 1);
    }

    return $clean;
}

/** Report one setting the way this integration reports settings. */
function nibwp_sureforms_setting_value(mixed $value, string $type): mixed
{
    return match ($type) {
        'bool' => (bool) $value && $value !== '0',
        'int' => is_numeric($value) ? (int) $value : 0,
        default => is_scalar($value) ? nibwp_sureforms_clip($value) : null,
    };
}

/**
 * Turn a settings array into a response: values for the allow-list, names for
 * everything else.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function nibwp_sureforms_report_settings(array $settings): array
{
    $allowed = nibwp_sureforms_public_settings();
    $public = [];

    foreach ($allowed as $key => $type) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }

        // Belt and braces: a key that is somehow on both lists is withheld, not
        // reported. The check pins this so the two lists cannot drift into a leak.
        if (nibwp_sureforms_is_secret($key)) {
            continue;
        }

        $public[$key] = nibwp_sureforms_setting_value($settings[$key], $type);
    }

    $withheld = [];

    foreach ($settings as $key => $value) {
        if (!is_string($key) || isset($allowed[$key])) {
            continue;
        }

        $withheld[] = [
            'key' => $key,
            'is_set' => $value !== '' && $value !== null && $value !== false && $value !== [],
            'value_returned' => false,
            'is_credential' => nibwp_sureforms_is_secret($key),
        ];
    }

    return [
        'settings' => $public,
        'withheld' => $withheld,
        'note' => __('Only the keys above report a value. Everything else comes back as a name and whether it is set — including every captcha secret, API key, token, webhook URL and licence key, at any nesting depth, however the request is phrased.', 'nibwp'),
    ];
}

/**
 * Read one of SureForms' structured meta values as an array.
 *
 * A legacy form can hold `_srfm_form_confirmation` as a serialised string rather
 * than an array, which is why SureForms reads it through maybe_unserialize()
 * (inc/post-types.php:160). The same core function is used here — never the bare
 * unserialize(), which would run __wakeup on whatever class name a string named —
 * and the result is shape-checked before anybody relies on it.
 *
 * @return array<int|string, mixed>
 */
function nibwp_sureforms_meta_array(int $form_id, string $key): array
{
    $raw = get_post_meta($form_id, $key, true);

    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    // JSON first: _srfm_form_restriction is stored as a JSON string
    // (inc/abilities/forms/form-metadata.php:262).
    $decoded = json_decode($raw, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    $value = maybe_unserialize($raw);

    return is_array($value) ? $value : [];
}

/**
 * The first notification on a form, normalised.
 *
 * SureForms stores notifications as a list and the editor's first one is index 0
 * (inc/abilities/forms/form-metadata.php:176-218). Its defaults are quoted from
 * that same block, so a form that has never been touched reads the way SureForms
 * would render it.
 *
 * @return array<string, mixed>
 */
function nibwp_sureforms_notification(int $form_id): array
{
    $all = nibwp_sureforms_meta_array($form_id, NIBWP_SUREFORMS_NOTIFICATION_META);
    $first = isset($all[0]) && is_array($all[0]) ? $all[0] : [];

    return [
        'exists' => $first !== [],
        'enabled' => $first !== [] && !empty($first['status']),
        'name' => isset($first['name']) ? nibwp_sureforms_clip($first['name']) : null,
        'email_to' => isset($first['email_to']) ? nibwp_sureforms_clip($first['email_to']) : null,
        'email_cc' => isset($first['email_cc']) ? nibwp_sureforms_clip($first['email_cc']) : null,
        'email_bcc' => isset($first['email_bcc']) ? nibwp_sureforms_clip($first['email_bcc']) : null,
        'email_reply_to' => isset($first['email_reply_to']) ? nibwp_sureforms_clip($first['email_reply_to']) : null,
        'from_name' => isset($first['from_name']) ? nibwp_sureforms_clip($first['from_name']) : null,
        'from_email' => isset($first['from_email']) ? nibwp_sureforms_clip($first['from_email']) : null,
        'subject' => isset($first['subject']) ? nibwp_sureforms_clip($first['subject']) : null,
        'email_body' => isset($first['email_body']) ? nibwp_sureforms_clip($first['email_body']) : null,
        'count' => count($all),
    ];
}

/** How many notifications a form has switched on. */
function nibwp_sureforms_notifies(int $form_id): bool
{
    foreach (nibwp_sureforms_meta_array($form_id, NIBWP_SUREFORMS_NOTIFICATION_META) as $item) {
        if (is_array($item) && !empty($item['status'])) {
            return true;
        }
    }

    return false;
}

/**
 * Where a form's notification actually goes.
 *
 * SureForms' default recipient is the smart tag `{admin_email}`
 * (inc/abilities/forms/form-metadata.php:181), which resolves to the site admin.
 * A literal address that is not the admin's is the case the audit is looking for.
 *
 * @return array<int, string>
 */
function nibwp_sureforms_recipients(int $form_id): array
{
    $out = [];

    foreach (nibwp_sureforms_meta_array($form_id, NIBWP_SUREFORMS_NOTIFICATION_META) as $item) {
        if (!is_array($item) || empty($item['status']) || !isset($item['email_to']) || !is_scalar($item['email_to'])) {
            continue;
        }

        foreach (preg_split('/[,;]/', (string) $item['email_to']) ?: [] as $one) {
            $one = trim((string) $one);

            if ($one !== '') {
                $out[] = $one;
            }
        }
    }

    return array_values(array_unique($out));
}

/**
 * What a form does after a submission.
 *
 * @return array<string, mixed>
 */
function nibwp_sureforms_confirmation(int $form_id): array
{
    $all = nibwp_sureforms_meta_array($form_id, NIBWP_SUREFORMS_CONFIRMATION_META);
    $first = isset($all[0]) && is_array($all[0]) ? $all[0] : [];

    return [
        'confirmation_type' => isset($first['confirmation_type']) ? nibwp_sureforms_clip($first['confirmation_type']) : null,
        'message' => isset($first['message']) ? nibwp_sureforms_clip($first['message']) : null,
        'page_url' => isset($first['page_url']) ? nibwp_sureforms_clip($first['page_url']) : null,
        'custom_url' => isset($first['custom_url']) ? nibwp_sureforms_clip($first['custom_url']) : null,
        'submission_action' => isset($first['submission_action']) ? nibwp_sureforms_clip($first['submission_action']) : null,
    ];
}

/**
 * The captcha types SureForms recognises.
 *
 * Taken from the branches it actually tests: 'none', 'g-recaptcha',
 * 'cf-turnstile' and 'hcaptcha' (inc/fields/inlinebutton-markup.php:130-152,
 * inc/form-submit.php:452-503).
 *
 * @return array<int, string>
 */
function nibwp_sureforms_captcha_types(): array
{
    return ['none', 'g-recaptcha', 'cf-turnstile', 'hcaptcha'];
}

/**
 * The reCAPTCHA variants (inc/fields/inlinebutton-markup.php:175-198).
 *
 * @return array<int, string>
 */
function nibwp_sureforms_recaptcha_versions(): array
{
    return ['v2-checkbox', 'v2-invisible', 'v3-reCAPTCHA'];
}

/**
 * Is a form protected against spam at all?
 *
 * Two independent things: the per-form captcha
 * (inc/fields/inlinebutton-markup.php:130) and the site-wide honeypot
 * (inc/form-submit.php:570). A form with neither is wide open, which is what the
 * audit reports — and reporting the captcha alone would call a honeypot-only site
 * unprotected, which it is not.
 *
 * @return array<string, mixed>
 */
function nibwp_sureforms_spam_protection(int $form_id): array
{
    $type = get_post_meta($form_id, NIBWP_SUREFORMS_CAPTCHA_META, true);
    $type = is_scalar($type) ? (string) $type : '';
    $security = get_option(NIBWP_SUREFORMS_SECURITY_OPTION, []);
    $honeypot = is_array($security) && !empty($security['srfm_honeypot']);
    $captcha_on = $type !== '' && $type !== 'none';

    $version = get_post_meta($form_id, NIBWP_SUREFORMS_RECAPTCHA_META, true);

    return [
        'captcha_type' => $type === '' ? 'none' : $type,
        'recaptcha_version' => is_scalar($version) && (string) $version !== '' ? (string) $version : null,
        'captcha_enabled' => $captcha_on,
        'honeypot_enabled' => $honeypot,
        'protected' => $captcha_on || $honeypot,
    ];
}

/**
 * Read one form's fields out of its block markup.
 *
 * The same walk SureForms does: parse_blocks over post_content, keep the `srfm/*`
 * blocks, step through the `srfm/form` wrapper and any non-SureForms container
 * (inc/abilities/forms/get-form.php:116-186). Nothing is written back — not even
 * in memory SureForms owns.
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_sureforms_fields(int $form_id): array
{
    $post = get_post($form_id);
    $content = is_object($post) && isset($post->post_content) ? (string) $post->post_content : '';

    if ($content === '' || !function_exists('parse_blocks')) {
        return [];
    }

    $blocks = parse_blocks($content);

    if (!is_array($blocks)) {
        return [];
    }

    $fields = [];
    nibwp_sureforms_walk_blocks($blocks, $fields, 0);

    return $fields;
}

/**
 * Walk a block tree collecting SureForms field blocks.
 *
 * @param array<int|string, mixed> $blocks
 * @param array<int, array<string, mixed>> $fields
 */
function nibwp_sureforms_walk_blocks(array $blocks, array &$fields, int $depth): void
{
    // A bound stops a hand-edited or pathologically nested document from taking
    // the request down with it.
    if ($depth > 24 || count($fields) >= NIBWP_SUREFORMS_MAX_ROWS) {
        return;
    }

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        $name = isset($block['blockName']) && is_scalar($block['blockName']) ? (string) $block['blockName'] : '';
        $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [];

        // A non-SureForms block, or SureForms' own form wrapper, is a container
        // to step through rather than a field.
        if ($name === '' || !str_starts_with($name, 'srfm/') || $name === 'srfm/form') {
            if ($inner !== []) {
                nibwp_sureforms_walk_blocks($inner, $fields, $depth + 1);
            }
            continue;
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

        $fields[] = [
            'type' => substr($name, 5),
            'block' => $name,
            'label' => isset($attrs['label']) ? nibwp_sureforms_clip($attrs['label']) : null,
            'slug' => isset($attrs['slug']) ? nibwp_sureforms_clip($attrs['slug']) : null,
            'block_id' => isset($attrs['block_id']) ? nibwp_sureforms_clip($attrs['block_id']) : null,
            'placeholder' => isset($attrs['placeholder']) ? nibwp_sureforms_clip($attrs['placeholder']) : null,
            'help' => isset($attrs['help']) ? nibwp_sureforms_clip($attrs['help']) : null,
            'required' => !empty($attrs['required']),
        ];

        if (count($fields) >= NIBWP_SUREFORMS_MAX_ROWS) {
            return;
        }

        if ($inner !== []) {
            nibwp_sureforms_walk_blocks($inner, $fields, $depth + 1);
        }
    }
}

/**
 * How many entries a form has, optionally within a window.
 *
 * 'trash' is excluded because a trashed entry is one somebody already deleted, so
 * counting it would overstate what the form received (status values at
 * inc/database/tables/entries.php:76-79).
 */
function nibwp_sureforms_entry_count(int $form_id, ?int $days = null): int
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return 0;
    }

    if ($days === null) {
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(ID) FROM ' . nibwp_sureforms_entries_table() . ' WHERE form_id = %d AND status != %s',
            $form_id,
            'trash'
        ));
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(ID) FROM ' . nibwp_sureforms_entries_table()
        . ' WHERE form_id = %d AND status != %s AND created_at >= %s',
        $form_id,
        'trash',
        gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
    ));
}

/**
 * The forms on this site, bounded.
 *
 * @return array<int, object>
 */
function nibwp_sureforms_form_posts(int $limit, int $offset = 0): array
{
    $posts = get_posts([
        'post_type' => NIBWP_SUREFORMS_FORM_TYPE,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
        'no_found_rows' => true,
    ]);

    return is_array($posts) ? $posts : [];
}

/**
 * Write one of the four meta keys this integration is allowed to write.
 *
 * A third layer after the allow-list and the secret list, and the one that
 * matters most: nothing reaches update_post_meta that is not named here, so no
 * write path can be talked into setting a credential whatever the caller sends.
 *
 * wp_slash because update_metadata() unslashes what it is given, which is also
 * what SureForms does on this exact meta (inc/single-form-settings/form-settings-api.php:174).
 * Without it a success message containing an apostrophe comes back mangled.
 *
 * @param mixed $value
 */
function nibwp_sureforms_write_meta(int $form_id, string $key, mixed $value): ?WP_Error
{
    $writable = [
        NIBWP_SUREFORMS_NOTIFICATION_META,
        NIBWP_SUREFORMS_CONFIRMATION_META,
        NIBWP_SUREFORMS_CAPTCHA_META,
        NIBWP_SUREFORMS_RECAPTCHA_META,
    ];

    if (!in_array($key, $writable, true)) {
        return nibwp_sureforms_err(
            'not_writable',
            sprintf(
                /* translators: %s: the meta key that was refused */
                __('"%s" is not a setting this integration writes.', 'nibwp'),
                $key
            ),
            422
        );
    }

    update_post_meta($form_id, $key, wp_slash($value));

    return null;
}

/**
 * The actions this hub offers.
 *
 * Named to match the other form integrations in this plugin, so an agent that
 * has learned one finds the same shape here.
 *
 * @return array<int, string>
 */
function nibwp_sureforms_actions(): array
{
    return [
        // Forms and fields — reads.
        'forms_overview',
        'list_forms',
        'get_form',
        'get_form_fields',
        'get_required_fields',
        // Settings — reads.
        'get_form_settings',
        'list_notifications',
        'get_global_settings',
        // Entries — reads.
        'list_entries',
        'get_entry',
        'count_entries',
        'export_entries',
        // Audit.
        'audit_no_notification',
        'audit_notification_recipients',
        'audit_never_submitted',
        'audit_spam_protection_off',
        // Forms — writes.
        'create_form',
        'rename_form',
        'duplicate_form',
        'trash_form',
        'delete_form_permanently',
        // Settings — writes.
        'set_success_message',
        'set_redirect',
        'set_notification',
        'set_antispam',
        // Entries — writes.
        'mark_entry_read',
        'delete_entry_permanently',
    ];
}

wp_register_ability('nibwp/sureforms-manage', [
    'label' => __('SureForms – Forms, Settings and Entries', 'nibwp'),
    'description' => 'Read and manage SureForms: list the forms, read one with its field structure, read and change what happens after a submission (the success message, the redirect, the email notification and its recipients), switch the spam protection, '
        . 'read entries and count them over a period, mark one read, export a bounded set, and audit for forms that tell nobody when a submission arrives, forms notifying somewhere other than the site admin, forms that have never received an entry, and forms taking traffic with the spam protection switched off. '
        . 'Creates, renames, duplicates, trashes and permanently deletes forms, and permanently deletes a single entry. '
        . 'Fields are READ-ONLY: a SureForms form is a Gutenberg document and a field\'s submission key is derived from its block_id, slug and label together, so writing one from outside the editor silently decouples new submissions from the existing entries — use the block editor for that. '
        . 'Entries are personal data and take manage_options, which is what SureForms requires for its own entry abilities. No captcha secret, API key, token, webhook URL or licence key is ever returned.',
    'category' => 'forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_sureforms_actions(),
                'description' => 'Which operation to run. Start with forms_overview.',
            ],
            'form_id' => ['type' => 'integer', 'description' => 'The form post ID. Required by everything that names one form.'],
            'entry_id' => ['type' => 'integer', 'description' => 'For get_entry / mark_entry_read / delete_entry_permanently: the entry row ID from SureForms\' entries table, not a post ID.'],
            'title' => ['type' => 'string', 'description' => 'For create_form / rename_form / duplicate_form: the form title.'],
            'message' => ['type' => 'string', 'description' => 'For set_success_message: what the visitor sees after submitting.'],
            'url' => ['type' => 'string', 'description' => 'For set_redirect: where to send the visitor after submitting. Empty clears it and goes back to the message.'],
            'enabled' => ['type' => 'boolean', 'description' => 'For set_notification: on or off.'],
            'email_to' => ['type' => 'string', 'description' => 'For set_notification: the recipient, or several separated by commas. {admin_email} means the site admin.'],
            'subject' => ['type' => 'string', 'description' => 'For set_notification: the email subject.'],
            'body' => ['type' => 'string', 'description' => 'For set_notification: the email body. SureForms smart tags like {all_data} are kept.'],
            'captcha' => ['type' => 'string', 'enum' => ['none', 'g-recaptcha', 'cf-turnstile', 'hcaptcha'], 'description' => 'For set_antispam: which captcha this form uses.'],
            'recaptcha_version' => ['type' => 'string', 'enum' => ['v2-checkbox', 'v2-invisible', 'v3-reCAPTCHA'], 'description' => 'For set_antispam when captcha is g-recaptcha.'],
            'days' => ['type' => 'integer', 'description' => 'For count_entries and the audits: how many days back. Default 30, max 365.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 25, max 100.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string'],
        ],
        'required' => ['action'],
    ],
    'execute_callback' => 'nibwp_sureforms_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SureForms — forms, what they do on submit, and their entries.',
                '',
                'START WITH forms_overview. It gives every form with its entry count, whether',
                'anybody is notified and whether it has any spam protection — which is usually',
                'the answer to both "why did nobody see my enquiry" and "why is this form full',
                'of junk".',
                '',
                'FIELDS ARE READ-ONLY, and this is not an omission to work around. A SureForms',
                'form is a Gutenberg document: the fields are srfm/* blocks in the form post\'s',
                'post_content. The key a submission arrives under is derived from the block\'s',
                'block_id, its slug and its label together, and block_id is minted by the',
                'editor. Write a field from outside and new submissions stop lining up with the',
                'entries already stored — with no error, just answers going missing. SureForms',
                'itself has no surgical field editor: its own update path regenerates the whole',
                'document and every block_id with it. get_form_fields and get_required_fields',
                'report what is there; adding, renaming or removing a field is done by opening',
                'the form in the block editor, and saying so is the correct answer.',
                '',
                'WHAT CAN BE CHANGED: the title, the success message, the redirect, the email',
                'notification (set_notification for on/off, recipient, subject and body) and the',
                'spam protection (set_antispam). Nothing else. No action here sets a captcha',
                'secret, an API key or a webhook — there is no such action, and the writer',
                'refuses any key not on its list of four.',
                '',
                'ENTRIES ARE PEOPLE\'S DATA. They take manage_options, which is what SureForms',
                'requires for every one of its own entry abilities. They are rows in SureForms\'',
                'own table, not posts, so entry_id is a row ID. Prefer count_entries, which',
                'answers "is this form working" without reading anybody\'s submission;',
                'list_entries gives dates and status only, and get_entry is the one action that',
                'returns what a person actually typed — read one when the question needs it, and',
                'quote only what it needs. Every listing is bounded at 100 rows and the bound',
                'cannot be raised.',
                '',
                'THE THREE DELETIONS: trash_form is reversible. delete_form_permanently is not,',
                'AND IT TAKES THE FORM\'S ENTRIES WITH IT — every submission anybody ever made to',
                'that form. delete_entry_permanently is not reversible either. They are named',
                'that way so nothing calls one by accident. Confirm with the person first, and',
                'say what will be lost.',
                '',
                'CREDENTIALS: get_form_settings and get_global_settings report values only for a',
                'named list of behaviour settings. Every other key comes back as a name and',
                'whether it is set. The reCAPTCHA v2 and v3 secrets, the Turnstile secret, the',
                'hCaptcha secret and anything else whose name reads like a credential never',
                'travel, at any nesting depth, however the request is phrased. Captcha SITE keys',
                'are reported, because SureForms prints them into the page anyway and knowing',
                'one is missing is how you spot a half-configured captcha.',
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
function nibwp_sureforms_manage(array $input): array|WP_Error
{
    $guard = nibwp_sureforms_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_sureforms_actions(), true)) {
        return nibwp_sureforms_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown SureForms action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_sureforms_actions())
            )
        );
    }

    // SureForms reaches its own database layer, the block registry, Action
    // Scheduler and a handful of optional integrations, any of which can raise on
    // a half-updated site — and its entries table can be missing outright
    // (inc/database/register.php:293). One catch here turns that into a named
    // refusal instead of a fatal that takes the whole REST request with it.
    try {
        return nibwp_sureforms_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_sureforms_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from SureForms */
                __('SureForms could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_sureforms_dispatch(string $action, array $input): array|WP_Error
{
    $result = match ($action) {
        'forms_overview' => nibwp_sureforms_forms_overview($input),
        'list_forms' => nibwp_sureforms_list_forms($input),
        'get_form' => nibwp_sureforms_get_form($input),
        'get_form_fields' => nibwp_sureforms_get_form_fields($input),
        'get_required_fields' => nibwp_sureforms_get_required_fields($input),
        'get_form_settings' => nibwp_sureforms_get_form_settings($input),
        'list_notifications' => nibwp_sureforms_list_notifications($input),
        'get_global_settings' => nibwp_sureforms_get_global_settings(),
        'list_entries' => nibwp_sureforms_list_entries($input),
        'get_entry' => nibwp_sureforms_get_entry($input),
        'count_entries' => nibwp_sureforms_count_entries($input),
        'export_entries' => nibwp_sureforms_export_entries($input),
        'audit_no_notification' => nibwp_sureforms_audit_no_notification($input),
        'audit_notification_recipients' => nibwp_sureforms_audit_recipients($input),
        'audit_never_submitted' => nibwp_sureforms_audit_never_submitted($input),
        'audit_spam_protection_off' => nibwp_sureforms_audit_spam($input),
        'create_form' => nibwp_sureforms_create_form($input),
        'rename_form' => nibwp_sureforms_rename_form($input),
        'duplicate_form' => nibwp_sureforms_duplicate_form($input),
        'trash_form' => nibwp_sureforms_trash_form($input),
        'delete_form_permanently' => nibwp_sureforms_delete_form($input),
        'set_success_message' => nibwp_sureforms_set_success_message($input),
        'set_redirect' => nibwp_sureforms_set_redirect($input),
        'set_notification' => nibwp_sureforms_set_notification($input),
        'set_antispam' => nibwp_sureforms_set_antispam($input),
        'mark_entry_read' => nibwp_sureforms_mark_entry_read($input),
        'delete_entry_permanently' => nibwp_sureforms_delete_entry($input),
        default => nibwp_sureforms_err('unhandled_action', __('Unhandled action.', 'nibwp')),
    };

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/* -------------------------------------------------------------------- reads */

/**
 * Every form with the facts that decide whether it is working.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_forms_overview(array $input): array|WP_Error
{
    if ($denied = nibwp_sureforms_require_forms(__('Reading the SureForms forms', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sureforms_limit($input['limit'] ?? null);
    $forms = nibwp_sureforms_form_posts($limit, nibwp_sureforms_offset($input['offset'] ?? null));
    $rows = [];
    $act_on = [];

    // The entry counts need manage_options. On this post type the forms
    // capability is manage_options too, so in practice a reader who got this far
    // has it — but it is asked rather than assumed, and a count nobody may see is
    // reported as null rather than silently as zero.
    $may_count = current_user_can(NIBWP_SUREFORMS_ENTRIES_CAP);

    foreach ($forms as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $id = (int) $form->ID;
        $title = isset($form->post_title) ? (string) $form->post_title : '';
        $notifies = nibwp_sureforms_notifies($id);
        $spam = nibwp_sureforms_spam_protection($id);
        $count = $may_count ? nibwp_sureforms_entry_count($id) : null;
        $fields = nibwp_sureforms_fields($id);

        $rows[] = [
            'id' => $id,
            'title' => $title,
            'status' => isset($form->post_status) ? (string) $form->post_status : '',
            'field_count' => count($fields),
            'notifies' => $notifies,
            'spam_protected' => $spam['protected'],
            'captcha_type' => $spam['captcha_type'],
            'entries' => $count,
        ];

        if (!$notifies) {
            $act_on[] = sprintf(
                /* translators: 1: form title, 2: form ID */
                __('"%1$s" (#%2$d) has no notification switched on, so nobody is emailed when a submission arrives — somebody has to remember to look at the entries.', 'nibwp'),
                $title,
                $id
            );
        }

        if (!$spam['protected'] && is_int($count) && $count > 0) {
            $act_on[] = sprintf(
                /* translators: 1: form title, 2: form ID */
                __('"%1$s" (#%2$d) has neither a captcha nor the honeypot, and it is receiving submissions.', 'nibwp'),
                $title,
                $id
            );
        }

        if (count($fields) === 0) {
            $act_on[] = sprintf(
                /* translators: 1: form title, 2: form ID */
                __('"%1$s" (#%2$d) has no fields yet. Open it in the block editor and add some.', 'nibwp'),
                $title,
                $id
            );
        }
    }

    return [
        'returned' => count($rows),
        'limit' => $limit,
        'forms' => $rows,
        'act_on' => $act_on,
        'entry_counts_available' => $may_count,
        'fields_writable' => false,
        'note' => __('Fields are read-only everywhere in this integration: a SureForms form is a Gutenberg document and a field\'s submission key is derived from its block_id. Use get_form_fields to see them.', 'nibwp'),
    ];
}

/**
 * The forms, plainly.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_list_forms(array $input): array|WP_Error
{
    if ($denied = nibwp_sureforms_require_forms(__('Reading the SureForms forms', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sureforms_limit($input['limit'] ?? null);
    $forms = nibwp_sureforms_form_posts($limit, nibwp_sureforms_offset($input['offset'] ?? null));
    $rows = [];

    foreach ($forms as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $rows[] = [
            'id' => (int) $form->ID,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'status' => isset($form->post_status) ? (string) $form->post_status : '',
            'created' => isset($form->post_date_gmt) ? (string) $form->post_date_gmt : null,
            'shortcode' => sprintf('[sureforms id="%d"]', (int) $form->ID),
        ];
    }

    return [
        'returned' => count($rows),
        'limit' => $limit,
        'forms' => $rows,
    ];
}

/**
 * One form: what it is, what it does on submit, and what fields it has.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_get_form(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_sureforms_require_forms(__('Reading a SureForms form', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $fields = nibwp_sureforms_fields($id);
    $may_count = current_user_can(NIBWP_SUREFORMS_ENTRIES_CAP);

    return [
        'id' => $id,
        'title' => isset($form->post_title) ? (string) $form->post_title : '',
        'status' => isset($form->post_status) ? (string) $form->post_status : '',
        'shortcode' => sprintf('[sureforms id="%d"]', $id),
        'fields' => $fields,
        'field_count' => count($fields),
        'fields_writable' => false,
        'fields_stored_in' => 'post_content (Gutenberg block markup)',
        'on_submit' => nibwp_sureforms_confirmation($id),
        'notification' => nibwp_sureforms_notification($id),
        'spam_protection' => nibwp_sureforms_spam_protection($id),
        'entries' => $may_count ? nibwp_sureforms_entry_count($id) : null,
        'note' => __('The fields come from the form\'s block markup in post_content and are read-only here. Changing one means editing that document, which is what the block editor is for.', 'nibwp'),
    ];
}

/**
 * The fields, with the reason they cannot be written from here.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_get_form_fields(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_sureforms_require_forms(__('Reading a SureForms form\'s fields', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $fields = nibwp_sureforms_fields($id);
    $has_content = isset($form->post_content) && (string) $form->post_content !== '';

    return [
        'form_id' => $id,
        'count' => count($fields),
        'fields' => $fields,
        'writable' => false,
        'stored_in' => 'post_content',
        'has_block_markup' => $has_content,
        'note' => $fields === [] && !$has_content
            ? __('This form has no block markup yet, so it has no fields. Open it in the block editor and add some.', 'nibwp')
            : __('Fields are srfm/* blocks in the form post\'s post_content, not a settings array, and they are read-only here on purpose. A field\'s submission key is derived from its block_id, its slug and its label together, and block_id is minted by the editor — so a field written from outside decouples new submissions from the entries already stored, with no error to notice. Open the form in the block editor to change a field.', 'nibwp'),
    ];
}

/**
 * Which fields a visitor must fill in.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_get_required_fields(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_sureforms_require_forms(__('Reading a SureForms form\'s required fields', 'nibwp'))) {
        return $denied;
    }

    $required = [];
    $optional = [];

    foreach (nibwp_sureforms_fields((int) $form->ID) as $field) {
        $name = isset($field['label']) && is_string($field['label']) && $field['label'] !== ''
            ? $field['label']
            : (string) ($field['type'] ?? '');

        if (($field['required'] ?? false) === true) {
            $required[] = $name;
            continue;
        }

        $optional[] = $name;
    }

    return [
        'form_id' => (int) $form->ID,
        'required' => $required,
        'optional' => $optional,
        'writable' => false,
        'note' => __('Whether a field is required is the "required" attribute on its block, so it is reported here and changed in the block editor. Nothing in this integration writes it.', 'nibwp'),
    ];
}

/**
 * One form's settings, allow-listed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_get_form_settings(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_sureforms_require_forms(__('Reading a SureForms form\'s settings', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $meta = get_post_meta($id);
    $flat = [];

    if (is_array($meta)) {
        foreach ($meta as $key => $values) {
            if (!is_string($key)) {
                continue;
            }

            $flat[$key] = is_array($values) && array_key_exists(0, $values) ? $values[0] : $values;
        }
    }

    $report = nibwp_sureforms_report_settings($flat);

    return [
        'form_id' => $id,
        'settings' => $report['settings'],
        'withheld' => $report['withheld'],
        'on_submit' => nibwp_sureforms_confirmation($id),
        'spam_protection' => nibwp_sureforms_spam_protection($id),
        'note' => $report['note'],
    ];
}

/**
 * The notifications, and whether either will actually arrive.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_list_notifications(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_sureforms_require_forms(__('Reading a SureForms form\'s notifications', 'nibwp'))) {
        return $denied;
    }

    $id = (int) $form->ID;
    $all = nibwp_sureforms_meta_array($id, NIBWP_SUREFORMS_NOTIFICATION_META);
    $admin_email = (string) get_option('admin_email', '');
    $rows = [];
    $problems = [];

    foreach ($all as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $to = isset($item['email_to']) && is_scalar($item['email_to']) ? trim((string) $item['email_to']) : '';
        $on = !empty($item['status']);

        $rows[] = [
            'index' => is_int($index) ? $index : 0,
            'id' => isset($item['id']) ? nibwp_sureforms_clip($item['id']) : null,
            'name' => isset($item['name']) ? nibwp_sureforms_clip($item['name']) : null,
            'enabled' => $on,
            'email_to' => $to === '' ? null : nibwp_sureforms_clip($to),
            'email_cc' => isset($item['email_cc']) ? nibwp_sureforms_clip($item['email_cc']) : null,
            'email_bcc' => isset($item['email_bcc']) ? nibwp_sureforms_clip($item['email_bcc']) : null,
            'email_reply_to' => isset($item['email_reply_to']) ? nibwp_sureforms_clip($item['email_reply_to']) : null,
            'from_email' => isset($item['from_email']) ? nibwp_sureforms_clip($item['from_email']) : null,
            'subject' => isset($item['subject']) ? nibwp_sureforms_clip($item['subject']) : null,
            'email_body' => isset($item['email_body']) ? nibwp_sureforms_clip($item['email_body']) : null,
            'goes_to_site_admin' => $to === '{admin_email}' || ($admin_email !== '' && strcasecmp($to, $admin_email) === 0),
        ];

        if ($on && $to === '') {
            $problems[] = sprintf(
                /* translators: %d: the notification's position in the list */
                __('Notification %d is on but has no recipient, so it is sent to nobody.', 'nibwp'),
                is_int($index) ? $index + 1 : 1
            );
        }
    }

    if (!nibwp_sureforms_notifies($id)) {
        $problems[] = __('No notification is switched on, so a submission to this form emails nobody.', 'nibwp');
    }

    return [
        'form_id' => $id,
        'count' => count($rows),
        'notifications' => $rows,
        'problems' => $problems,
        'note' => __('SureForms hands these to wp_mail, so whether they arrive also depends on how this site sends mail — a notification that is on and correct can still be dropped by the recipient\'s server.', 'nibwp'),
    ];
}

/**
 * The site-wide settings, allow-listed.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_get_global_settings(): array|WP_Error
{
    // This is where the captcha secrets live, so it takes manage_options — which
    // is also what SureForms' own settings ability requires
    // (inc/abilities/settings/get-global-settings.php:46).
    if (!current_user_can('manage_options')) {
        return nibwp_sureforms_forbidden(__('Reading SureForms\' site-wide settings', 'nibwp'), 'manage_options');
    }

    $merged = [];

    foreach ([NIBWP_SUREFORMS_SECURITY_OPTION, NIBWP_SUREFORMS_GENERAL_OPTION, 'srfm_email_summary_settings_options'] as $option) {
        $value = get_option($option, []);

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $merged[$key] = $item;
                }
            }
        }
    }

    $report = nibwp_sureforms_report_settings($merged);
    $security = get_option(NIBWP_SUREFORMS_SECURITY_OPTION, []);
    $security = is_array($security) ? $security : [];
    $secrets = [];

    foreach (nibwp_sureforms_secret_keys() as $key) {
        $secrets[$key] = [
            'is_set' => isset($security[$key]) && is_scalar($security[$key]) && (string) $security[$key] !== '',
            'value_returned' => false,
        ];
    }

    return [
        'options_read' => [NIBWP_SUREFORMS_SECURITY_OPTION, NIBWP_SUREFORMS_GENERAL_OPTION, 'srfm_email_summary_settings_options'],
        'settings' => $report['settings'],
        'withheld' => $report['withheld'],
        'captcha_secrets' => $secrets,
        'note' => __('Captcha secrets are reported as "is this configured" only. Site keys ARE reported, because SureForms prints them into the page anyway — which is how you can tell a captcha is half-configured: a secret set with no site key, or the other way round.', 'nibwp'),
    ];
}

/**
 * Entries for one form, or across all forms. Bounded.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_list_entries(array $input): array|WP_Error
{
    // The form id is resolved BEFORE the capability is discussed, for the same
    // reason as everywhere else in this file: a capability answer must never be
    // given about the wrong object.
    $form_id = 0;

    if (isset($input['form_id'])) {
        $form = nibwp_sureforms_form($input['form_id']);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $form_id = (int) $form->ID;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Listing SureForms entries', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_sureforms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $limit = nibwp_sureforms_limit($input['limit'] ?? null);
    $offset = nibwp_sureforms_offset($input['offset'] ?? null);

    // Two whole queries rather than one built up from pieces, and the table name
    // inlined rather than held in a local: every value goes through prepare and the
    // only thing concatenated is the helper, which builds the name from $wpdb->prefix
    // and a constant. Keeping each query intact is what makes that visible at a glance.
    if ($form_id > 0) {
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT ID, form_id, user_id, status, type, created_at FROM ' . nibwp_sureforms_entries_table()
            . ' WHERE form_id = %d AND status != %s'
            . ' ORDER BY created_at DESC, ID DESC LIMIT %d OFFSET %d',
            $form_id,
            'trash',
            $limit,
            $offset
        ));
    } else {
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT ID, form_id, user_id, status, type, created_at FROM ' . nibwp_sureforms_entries_table()
            . ' WHERE status != %s'
            . ' ORDER BY created_at DESC, ID DESC LIMIT %d OFFSET %d',
            'trash',
            $limit,
            $offset
        ));
    }

    $out = [];

    foreach ($rows as $row) {
        if (!is_object($row) || !isset($row->ID)) {
            continue;
        }

        $out[] = [
            'id' => (int) $row->ID,
            'form_id' => isset($row->form_id) ? (int) $row->form_id : 0,
            'user_id' => isset($row->user_id) ? (int) $row->user_id : 0,
            'status' => isset($row->status) ? (string) $row->status : '',
            'type' => isset($row->type) ? (string) $row->type : '',
            'created_at' => isset($row->created_at) ? (string) $row->created_at : null,
        ];
    }

    return [
        'form_id' => $form_id > 0 ? $form_id : null,
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'max_limit' => NIBWP_SUREFORMS_MAX_ROWS,
        'entries' => $out,
        'note' => __('This listing deliberately returns no submitted values — only dates and status. Use get_entry for one entry when the question needs what somebody typed.', 'nibwp'),
    ];
}

/**
 * One entry, including what the person typed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_get_entry(array $input): array|WP_Error
{
    $entry = nibwp_sureforms_entry($input['entry_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Reading a SureForms entry', 'nibwp'))) {
        return $denied;
    }

    // form_data is JSON in the column, not PHP serialisation — Base encodes and
    // decodes it with json_decode (inc/database/base.php:1276). So no
    // unserialisation is needed anywhere on this path.
    $data = nibwp_sureforms_decode_column($entry->form_data ?? null);
    $info = nibwp_sureforms_decode_column($entry->submission_info ?? null);

    $form_id = isset($entry->form_id) ? (int) $entry->form_id : 0;
    $form = $form_id > 0 ? get_post($form_id) : null;

    return [
        'id' => (int) $entry->ID,
        'form_id' => $form_id,
        'form_title' => is_object($form) && isset($form->post_title) ? (string) $form->post_title : null,
        'user_id' => isset($entry->user_id) ? (int) $entry->user_id : 0,
        'status' => isset($entry->status) ? (string) $entry->status : '',
        'created_at' => isset($entry->created_at) ? (string) $entry->created_at : null,
        'form_data' => nibwp_sureforms_scrub($data),
        'submission_info' => nibwp_sureforms_scrub($info),
        'note' => __('This is somebody\'s personal data. Quote only the part the question needs, and do not repeat it further than it has to go.', 'nibwp'),
    ];
}

/**
 * Decode one of the entry table's JSON columns.
 *
 * @param mixed $raw
 * @return array<int|string, mixed>
 */
function nibwp_sureforms_decode_column(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * How many entries, over a window.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_count_entries(array $input): array|WP_Error
{
    $form_id = 0;

    if (isset($input['form_id'])) {
        $form = nibwp_sureforms_form($input['form_id']);
        if ($form instanceof WP_Error) {
            return $form;
        }

        $form_id = (int) $form->ID;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Counting SureForms entries', 'nibwp'))) {
        return $denied;
    }

    $days = nibwp_sureforms_days($input['days'] ?? null);

    if ($form_id > 0) {
        return [
            'form_id' => $form_id,
            'days' => $days,
            'in_period' => nibwp_sureforms_entry_count($form_id, $days),
            'all_time' => nibwp_sureforms_entry_count($form_id),
        ];
    }

    $rows = [];
    $total = 0;

    foreach (nibwp_sureforms_form_posts(NIBWP_SUREFORMS_MAX_FORMS) as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $count = nibwp_sureforms_entry_count((int) $form->ID, $days);
        $total += $count;

        $rows[] = [
            'id' => (int) $form->ID,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'in_period' => $count,
            'all_time' => nibwp_sureforms_entry_count((int) $form->ID),
        ];
    }

    return [
        'days' => $days,
        'in_period' => $total,
        'forms' => $rows,
        'forms_examined' => count($rows),
        'max_forms' => NIBWP_SUREFORMS_MAX_FORMS,
    ];
}

/**
 * A bounded export of one form's entries, including the submitted values.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_export_entries(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Exporting SureForms entries', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
        return nibwp_sureforms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $id = (int) $form->ID;
    $limit = nibwp_sureforms_limit($input['limit'] ?? null);
    $offset = nibwp_sureforms_offset($input['offset'] ?? null);

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT ID, form_id, user_id, status, created_at, form_data FROM ' . nibwp_sureforms_entries_table()
        . ' WHERE form_id = %d AND status != %s'
        . ' ORDER BY created_at DESC, ID DESC LIMIT %d OFFSET %d',
        $id,
        'trash',
        $limit,
        $offset
    ));

    $out = [];
    $columns = [];

    foreach ($rows as $row) {
        if (!is_object($row) || !isset($row->ID)) {
            continue;
        }

        $data = nibwp_sureforms_scrub(nibwp_sureforms_decode_column($row->form_data ?? null));

        if (is_array($data)) {
            foreach (array_keys($data) as $key) {
                $columns[(string) $key] = true;
            }
        }

        $out[] = [
            'id' => (int) $row->ID,
            'created_at' => isset($row->created_at) ? (string) $row->created_at : null,
            'status' => isset($row->status) ? (string) $row->status : '',
            'user_id' => isset($row->user_id) ? (int) $row->user_id : 0,
            'values' => $data,
        ];
    }

    return [
        'form_id' => $id,
        'returned' => count($out),
        'limit' => $limit,
        'offset' => $offset,
        'max_limit' => NIBWP_SUREFORMS_MAX_ROWS,
        'columns' => array_keys($columns),
        'entries' => $out,
        'note' => __('A page of entries, not the whole table — raise offset to walk it. This is personal data in bulk: use it to answer a question, not to copy a mailing list somewhere else.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- audits */

/**
 * Forms with no notification at all.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_audit_no_notification(array $input): array|WP_Error
{
    if ($denied = nibwp_sureforms_require_forms(__('Auditing SureForms notifications', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sureforms_limit($input['limit'] ?? null, NIBWP_SUREFORMS_MAX_ROWS);
    $forms = nibwp_sureforms_form_posts(NIBWP_SUREFORMS_MAX_FORMS);
    $may_count = current_user_can(NIBWP_SUREFORMS_ENTRIES_CAP);
    $findings = [];
    $examined = 0;

    foreach ($forms as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $examined++;
        $id = (int) $form->ID;

        if (nibwp_sureforms_notifies($id)) {
            continue;
        }

        $all = nibwp_sureforms_meta_array($id, NIBWP_SUREFORMS_NOTIFICATION_META);

        $findings[] = [
            'id' => $id,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'status' => isset($form->post_status) ? (string) $form->post_status : '',
            'notifications_defined' => count($all),
            'entries' => $may_count ? nibwp_sureforms_entry_count($id) : null,
            'why' => $all === []
                ? __('No notification is configured on this form at all.', 'nibwp')
                : __('A notification is configured but switched off.', 'nibwp'),
        ];

        if (count($findings) >= $limit) {
            break;
        }
    }

    return [
        'forms_examined' => $examined,
        'found' => count($findings),
        'limit' => $limit,
        'forms' => $findings,
        'note' => $findings === []
            ? __('Every form examined has a notification switched on.', 'nibwp')
            : __('A form with no notification still stores its entries — nobody is told they arrived. Either switch a notification on with set_notification, or make sure somebody checks the entries.', 'nibwp'),
    ];
}

/**
 * Forms notifying somewhere other than the site admin.
 *
 * Not a fault on its own — plenty of forms should go to a team address. It is
 * worth reporting because it is how a notification quietly ends up at an ex
 * employee's address, and because a typo here is invisible until somebody
 * notices the silence.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_audit_recipients(array $input): array|WP_Error
{
    if ($denied = nibwp_sureforms_require_forms(__('Auditing SureForms notification recipients', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sureforms_limit($input['limit'] ?? null, NIBWP_SUREFORMS_MAX_ROWS);
    $admin_email = (string) get_option('admin_email', '');
    $findings = [];
    $examined = 0;

    foreach (nibwp_sureforms_form_posts(NIBWP_SUREFORMS_MAX_FORMS) as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $examined++;
        $id = (int) $form->ID;
        $recipients = nibwp_sureforms_recipients($id);

        if ($recipients === []) {
            continue;
        }

        $elsewhere = [];
        $unparseable = [];

        foreach ($recipients as $to) {
            // The default is the smart tag {admin_email}, which resolves to the
            // site admin (inc/abilities/forms/form-metadata.php:181), so a form
            // left alone is not a finding.
            if ($to === '{admin_email}') {
                continue;
            }

            if (str_contains($to, '{') || str_contains($to, '}')) {
                // Some other smart tag. It may well resolve to the right person;
                // it is reported separately rather than called a problem.
                $unparseable[] = $to;
                continue;
            }

            if ($admin_email !== '' && strcasecmp($to, $admin_email) === 0) {
                continue;
            }

            $elsewhere[] = $to;
        }

        if ($elsewhere === [] && $unparseable === []) {
            continue;
        }

        $findings[] = [
            'id' => $id,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'goes_to' => $elsewhere,
            'smart_tags' => $unparseable,
            'site_admin' => $admin_email === '' ? null : $admin_email,
            'invalid' => array_values(array_filter($elsewhere, static fn (string $one): bool => !is_email($one))),
        ];

        if (count($findings) >= $limit) {
            break;
        }
    }

    return [
        'forms_examined' => $examined,
        'found' => count($findings),
        'limit' => $limit,
        'site_admin' => $admin_email === '' ? null : $admin_email,
        'forms' => $findings,
        'note' => __('Sending somewhere other than the site admin is usually deliberate. Read this as a list to confirm, not a list to fix — but do check anything under "invalid", because those addresses cannot receive mail at all.', 'nibwp'),
    ];
}

/**
 * Forms that have never received an entry.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_audit_never_submitted(array $input): array|WP_Error
{
    if ($denied = nibwp_sureforms_require_forms(__('Auditing SureForms forms without entries', 'nibwp'))) {
        return $denied;
    }

    // The counts are the whole point of this audit, so without the entries
    // capability it refuses rather than reporting every form as never submitted.
    if ($denied = nibwp_sureforms_require_entries(__('Auditing SureForms forms without entries', 'nibwp'))) {
        return $denied;
    }

    $limit = nibwp_sureforms_limit($input['limit'] ?? null, NIBWP_SUREFORMS_MAX_ROWS);
    $findings = [];
    $examined = 0;

    foreach (nibwp_sureforms_form_posts(NIBWP_SUREFORMS_MAX_FORMS) as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $examined++;
        $id = (int) $form->ID;

        if (nibwp_sureforms_entry_count($id) > 0) {
            continue;
        }

        $fields = nibwp_sureforms_fields($id);

        $findings[] = [
            'id' => $id,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'status' => isset($form->post_status) ? (string) $form->post_status : '',
            'created' => isset($form->post_date_gmt) ? (string) $form->post_date_gmt : null,
            'field_count' => count($fields),
            'notifies' => nibwp_sureforms_notifies($id),
            'why' => count($fields) === 0
                ? __('No entries, and no fields either — this form was never finished.', 'nibwp')
                : __('No entries. Either nobody has reached it, or it is on a page nobody visits, or submitting it is failing.', 'nibwp'),
        ];

        if (count($findings) >= $limit) {
            break;
        }
    }

    return [
        'forms_examined' => $examined,
        'found' => count($findings),
        'limit' => $limit,
        'forms' => $findings,
        'note' => $findings === []
            ? __('Every form examined has received at least one entry.', 'nibwp')
            : __('A brand-new form belongs on this list. An old one with fields and no entries is worth checking on the front end: a form that errors on submit looks exactly like a form nobody uses.', 'nibwp'),
    ];
}

/**
 * Spam protection switched off on a form that gets traffic.
 *
 * The "gets traffic" half is what makes this worth reporting: an unprotected form
 * nobody has found is a tidying job, an unprotected form taking submissions is a
 * problem now.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_audit_spam(array $input): array|WP_Error
{
    if ($denied = nibwp_sureforms_require_forms(__('Auditing SureForms spam protection', 'nibwp'))) {
        return $denied;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Auditing SureForms spam protection', 'nibwp'))) {
        return $denied;
    }

    $days = nibwp_sureforms_days($input['days'] ?? null);
    $limit = nibwp_sureforms_limit($input['limit'] ?? null, NIBWP_SUREFORMS_MAX_ROWS);
    $security = get_option(NIBWP_SUREFORMS_SECURITY_OPTION, []);
    $security = is_array($security) ? $security : [];
    $honeypot = !empty($security['srfm_honeypot']);
    $findings = [];
    $examined = 0;

    foreach (nibwp_sureforms_form_posts(NIBWP_SUREFORMS_MAX_FORMS) as $form) {
        if (!is_object($form) || !isset($form->ID)) {
            continue;
        }

        $examined++;
        $id = (int) $form->ID;
        $spam = nibwp_sureforms_spam_protection($id);
        $recent = nibwp_sureforms_entry_count($id, $days);

        if ($spam['protected'] === true || $recent === 0) {
            continue;
        }

        $findings[] = [
            'id' => $id,
            'title' => isset($form->post_title) ? (string) $form->post_title : '',
            'captcha_type' => $spam['captcha_type'],
            'honeypot_enabled' => $honeypot,
            'entries_in_period' => $recent,
            'days' => $days,
            'why' => __('No captcha on the form and the site-wide honeypot is off, and this form received submissions in the period.', 'nibwp'),
        ];

        if (count($findings) >= $limit) {
            break;
        }
    }

    // A half-configured captcha is its own trap: the type is set but the keys are
    // not, which on some types means the widget never renders and the check
    // silently does nothing.
    $misconfigured = [];

    foreach ([
        'g-recaptcha' => ['srfm_v3_site_key', 'srfm_v2_checkbox_site_key', 'srfm_v2_invisible_site_key'],
        'cf-turnstile' => ['srfm_cf_turnstile_site_key'],
        'hcaptcha' => ['srfm_hcaptcha_site_key'],
    ] as $type => $keys) {
        $has_key = false;

        foreach ($keys as $key) {
            if (isset($security[$key]) && is_scalar($security[$key]) && (string) $security[$key] !== '') {
                $has_key = true;
                break;
            }
        }

        if (!$has_key) {
            $misconfigured[] = $type;
        }
    }

    return [
        'days' => $days,
        'forms_examined' => $examined,
        'found' => count($findings),
        'limit' => $limit,
        'honeypot_enabled' => $honeypot,
        'captcha_types_without_site_key' => $misconfigured,
        'forms' => $findings,
        'note' => $findings === []
            ? __('No form examined is both unprotected and receiving submissions. Switching the honeypot on site-wide protects every form at once and costs the visitor nothing.', 'nibwp')
            : __('Set a captcha per form with set_antispam, or switch the honeypot on in SureForms\' security settings to cover every form at once. A captcha type listed under captcha_types_without_site_key has no site key configured, so choosing it would not actually protect anything.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- writes */

/**
 * Make a new, empty form.
 *
 * Empty on purpose. A form's fields are blocks whose submission keys depend on
 * editor-minted block_ids, so this creates the form and the owner adds the fields
 * in the editor — which is also how SureForms' own "add new" works
 * (inc/create-new-form.php:145-151, a plain wp_insert_post).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_create_form(array $input): array|WP_Error
{
    $cap = nibwp_sureforms_forms_cap();

    if (!current_user_can($cap)) {
        return nibwp_sureforms_forbidden(__('Creating a SureForms form', 'nibwp'), $cap);
    }

    $title = isset($input['title']) ? trim((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_sureforms_err('missing_title', __('Give the new form a title.', 'nibwp'), 422);
    }

    $id = wp_insert_post([
        'post_type' => NIBWP_SUREFORMS_FORM_TYPE,
        'post_title' => $title,
        'post_status' => 'draft',
        // wp_slash even on an empty string: wp_insert_post() unslashes what it is
        // given, and a path that only sometimes slashes is a path that will one
        // day be handed real block markup without it.
        'post_content' => wp_slash(''),
    ], true);

    if ($id instanceof WP_Error) {
        return nibwp_sureforms_err('create_failed', $id->get_error_message(), 500);
    }

    $id = (int) $id;

    if ($id <= 0) {
        return nibwp_sureforms_err('create_failed', __('WordPress did not return a new form ID.', 'nibwp'), 500);
    }

    return [
        'id' => $id,
        'title' => $title,
        'status' => 'draft',
        'shortcode' => sprintf('[sureforms id="%d"]', $id),
        'field_count' => 0,
        'note' => __('The form exists and is a draft with no fields. Add the fields in the block editor — they cannot be written from here — then publish it.', 'nibwp'),
    ];
}

/**
 * Rename a form.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_rename_form(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Renaming a SureForms form', 'nibwp'))) {
        return $denied;
    }

    $title = isset($input['title']) ? trim((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_sureforms_err('missing_title', __('Give the new title as title.', 'nibwp'), 422);
    }

    $was = isset($form->post_title) ? (string) $form->post_title : '';
    $updated = wp_update_post(['ID' => $id, 'post_title' => $title], true);

    if ($updated instanceof WP_Error) {
        return nibwp_sureforms_err('rename_failed', $updated->get_error_message(), 500);
    }

    return ['id' => $id, 'was' => $was, 'now' => $title];
}

/**
 * Copy a form, fields and settings included.
 *
 * This is the one write that touches post_content, and the only safe way to
 * produce valid block markup from outside the editor: copy markup the editor
 * already wrote, byte for byte, without parsing or re-serialising it. The copy
 * is read back and compared against the source before it is reported as a
 * duplicate, so a mangled write is a refusal rather than a surprise.
 *
 * wp_slash is what makes that comparison pass. wp_insert_post() unslashes what
 * it is handed, and block markup is exactly where that bites: a field block's
 * attributes escape `&` as & and `--` as -- to keep the delimiter
 * comment valid HTML, and an unslashed write strips those backslashes and leaves
 * the escape as literal text in the form.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_duplicate_form(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $source_id = (int) $form->ID;
    $cap = nibwp_sureforms_forms_cap();

    if (!current_user_can($cap)) {
        return nibwp_sureforms_forbidden(__('Duplicating a SureForms form', 'nibwp'), $cap);
    }

    if ($denied = nibwp_sureforms_can_touch_form($source_id, __('Duplicating a SureForms form', 'nibwp'))) {
        return $denied;
    }

    $content = isset($form->post_content) ? (string) $form->post_content : '';
    $title = isset($input['title']) && trim((string) $input['title']) !== ''
        ? trim((string) $input['title'])
        : sprintf(
            /* translators: %s: the original form title */
            __('%s (copy)', 'nibwp'),
            isset($form->post_title) ? (string) $form->post_title : ''
        );

    $new_id = wp_insert_post([
        'post_type' => NIBWP_SUREFORMS_FORM_TYPE,
        'post_title' => $title,
        'post_status' => 'draft',
        'post_content' => wp_slash($content),
    ], true);

    if ($new_id instanceof WP_Error) {
        return nibwp_sureforms_err('duplicate_failed', $new_id->get_error_message(), 500);
    }

    $new_id = (int) $new_id;

    if ($new_id <= 0) {
        return nibwp_sureforms_err('duplicate_failed', __('WordPress did not return a new form ID.', 'nibwp'), 500);
    }

    // Prove the markup survived before saying the duplicate is good. If it did
    // not, the copy is trashed rather than left behind as a broken form.
    $written = get_post($new_id);
    $written = is_object($written) && isset($written->post_content) ? (string) $written->post_content : '';

    if ($written !== $content) {
        if (function_exists('wp_trash_post')) {
            wp_trash_post($new_id);
        }

        return nibwp_sureforms_err(
            'content_round_trip_failed',
            __('The copied block markup did not survive the write, so the duplicate was trashed rather than left in place. The original form is untouched.', 'nibwp'),
            500
        );
    }

    // Copy the settings SureForms treats as the form's own, through the same
    // short writable list every other action here goes through.
    $copied = [];

    foreach ([
        NIBWP_SUREFORMS_NOTIFICATION_META,
        NIBWP_SUREFORMS_CONFIRMATION_META,
        NIBWP_SUREFORMS_CAPTCHA_META,
        NIBWP_SUREFORMS_RECAPTCHA_META,
    ] as $key) {
        $value = get_post_meta($source_id, $key, true);

        if ($value === '' || $value === null || $value === false) {
            continue;
        }

        $failed = nibwp_sureforms_write_meta($new_id, $key, $value);

        if ($failed instanceof WP_Error) {
            return $failed;
        }

        $copied[] = $key;
    }

    return [
        'id' => $new_id,
        'copied_from' => $source_id,
        'title' => $title,
        'status' => 'draft',
        'shortcode' => sprintf('[sureforms id="%d"]', $new_id),
        'field_count' => count(nibwp_sureforms_fields($new_id)),
        'settings_copied' => $copied,
        'markup_verified' => true,
        'note' => __('The copy is a draft. Its block markup was read back and compared against the original byte for byte, so it opens in the editor exactly as the source does. No entries were copied — a duplicate starts empty.', 'nibwp'),
    ];
}

/**
 * Trash a form. Reversible.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_trash_form(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Trashing a SureForms form', 'nibwp'), 'delete_post')) {
        return $denied;
    }

    if (!function_exists('wp_trash_post')) {
        return nibwp_sureforms_err('no_trash', __('wp_trash_post is not available.', 'nibwp'), 500);
    }

    $trashed = wp_trash_post($id);

    if (!$trashed) {
        return nibwp_sureforms_err('trash_failed', __('WordPress would not trash that form.', 'nibwp'), 500);
    }

    return [
        'id' => $id,
        'title' => isset($form->post_title) ? (string) $form->post_title : '',
        'reversible' => true,
        'note' => __('The form is in the trash and can be restored. Its entries are untouched. Use delete_form_permanently only when the form and its entries should go for good.', 'nibwp'),
    ];
}

/**
 * Delete a form for good. IRREVERSIBLE, and it takes the form's entries with it.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_delete_form(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Permanently deleting a SureForms form', 'nibwp'), 'delete_post')) {
        return $denied;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Permanently deleting a SureForms form and its entries', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    $title = isset($form->post_title) ? (string) $form->post_title : '';
    $entries = nibwp_sureforms_entry_count($id);

    // The entries go first and explicitly. Leaving them behind would orphan rows
    // pointing at a form that no longer exists, which is worse than either
    // outcome the owner was choosing between.
    $deleted_entries = 0;

    if (is_object($wpdb) && method_exists($wpdb, 'query')) {
        $deleted_entries = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . nibwp_sureforms_entries_table() . ' WHERE form_id = %d',
            $id
        ));
    }

    if (!function_exists('wp_delete_post')) {
        return nibwp_sureforms_err('no_delete', __('wp_delete_post is not available.', 'nibwp'), 500);
    }

    $gone = wp_delete_post($id, true);

    if (!$gone) {
        return nibwp_sureforms_err('delete_failed', __('WordPress would not delete that form.', 'nibwp'), 500);
    }

    return [
        'id' => $id,
        'title' => $title,
        'entries_deleted' => $deleted_entries,
        'entries_before' => $entries,
        'reversible' => false,
        'note' => __('The form is gone for good and so is every entry anybody ever submitted to it. This cannot be undone.', 'nibwp'),
    ];
}

/**
 * What the visitor sees after submitting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_set_success_message(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Changing a SureForms success message', 'nibwp'))) {
        return $denied;
    }

    if (!isset($input['message'])) {
        return nibwp_sureforms_err('missing_message', __('Give the message as message.', 'nibwp'), 422);
    }

    $message = (string) $input['message'];
    $item = nibwp_sureforms_confirmation_item($id);
    $was = isset($item['message']) ? (string) $item['message'] : '';

    $item['message'] = $message;
    // Showing a message and sending somewhere else are the same setting in
    // SureForms, so setting a message also means choosing to stay on the page.
    $item['confirmation_type'] = 'same page';

    $failed = nibwp_sureforms_write_meta($id, NIBWP_SUREFORMS_CONFIRMATION_META, [$item]);

    if ($failed instanceof WP_Error) {
        return $failed;
    }

    return [
        'form_id' => $id,
        'was' => nibwp_sureforms_clip($was),
        'now' => nibwp_sureforms_clip($message),
        'confirmation_type' => 'same page',
        'note' => __('Any redirect this form had is now off: SureForms treats "show a message" and "send somewhere" as one choice. Use set_redirect to go back to redirecting.', 'nibwp'),
    ];
}

/**
 * Where the visitor goes after submitting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_set_redirect(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Changing a SureForms redirect', 'nibwp'))) {
        return $denied;
    }

    $url = isset($input['url']) ? trim((string) $input['url']) : '';
    $item = nibwp_sureforms_confirmation_item($id);
    $was = isset($item['custom_url']) ? (string) $item['custom_url'] : '';

    if ($url === '') {
        $item['custom_url'] = '';
        $item['confirmation_type'] = 'same page';

        $failed = nibwp_sureforms_write_meta($id, NIBWP_SUREFORMS_CONFIRMATION_META, [$item]);

        if ($failed instanceof WP_Error) {
            return $failed;
        }

        return [
            'form_id' => $id,
            'was' => nibwp_sureforms_clip($was),
            'now' => null,
            'confirmation_type' => 'same page',
            'note' => __('The redirect is cleared. The form now shows its success message instead.', 'nibwp'),
        ];
    }

    $clean = esc_url_raw($url);

    if ($clean === '' || !wp_http_validate_url($clean)) {
        return nibwp_sureforms_err(
            'bad_url',
            __('That does not look like a URL a browser can be sent to. Give a full http:// or https:// address, or an empty string to clear the redirect.', 'nibwp'),
            422
        );
    }

    $item['custom_url'] = $clean;
    $item['confirmation_type'] = 'custom url';

    $failed = nibwp_sureforms_write_meta($id, NIBWP_SUREFORMS_CONFIRMATION_META, [$item]);

    if ($failed instanceof WP_Error) {
        return $failed;
    }

    return [
        'form_id' => $id,
        'was' => nibwp_sureforms_clip($was),
        'now' => nibwp_sureforms_clip($clean),
        'confirmation_type' => 'custom url',
        'note' => __('The form now sends the visitor to that URL instead of showing its success message.', 'nibwp'),
    ];
}

/**
 * The first confirmation entry, with SureForms' own defaults filled in.
 *
 * Read-modify-write, because this is one meta value holding a list and a careless
 * write drops every key it did not know about. The defaults are quoted from
 * SureForms (inc/abilities/forms/form-metadata.php:58-65) so a form that has
 * never been touched comes out the way SureForms would render it.
 *
 * @return array<string, mixed>
 */
function nibwp_sureforms_confirmation_item(int $form_id): array
{
    $all = nibwp_sureforms_meta_array($form_id, NIBWP_SUREFORMS_CONFIRMATION_META);

    if (isset($all[0]) && is_array($all[0])) {
        return $all[0];
    }

    return [
        'id' => 1,
        'confirmation_type' => 'same page',
        'page_url' => '',
        'custom_url' => '',
        'message' => '',
        'submission_action' => 'hide form',
    ];
}

/**
 * Switch the notification on or off, and set who it goes to.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_set_notification(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Changing a SureForms notification', 'nibwp'))) {
        return $denied;
    }

    $all = nibwp_sureforms_meta_array($id, NIBWP_SUREFORMS_NOTIFICATION_META);
    $item = isset($all[0]) && is_array($all[0]) ? $all[0] : [
        'id' => 1,
        'status' => true,
        'is_raw_format' => false,
        'name' => '',
        'email_to' => '{admin_email}',
        'email_reply_to' => '{admin_email}',
        'from_name' => '{site_title}',
        'from_email' => '{admin_email}',
        'email_cc' => '',
        'email_bcc' => '',
        'subject' => '',
        'email_body' => '{all_data}',
    ];

    $changed = [];

    if (isset($input['enabled'])) {
        $item['status'] = (bool) $input['enabled'];
        $changed[] = 'enabled';
    }

    if (isset($input['email_to'])) {
        $to = trim((string) $input['email_to']);

        if ($to === '') {
            return nibwp_sureforms_err(
                'missing_recipient',
                __('An empty recipient would send the notification to nobody. Give an address, several separated by commas, or {admin_email} for the site admin — or switch the notification off with enabled: false.', 'nibwp'),
                422
            );
        }

        // Validate each address before writing. A smart tag like {admin_email} is
        // resolved by SureForms at send time, so it is allowed through as-is.
        $bad = [];

        foreach (explode(',', $to) as $one) {
            $one = trim($one);

            if ($one === '' || str_contains($one, '{')) {
                continue;
            }

            if (!is_email($one)) {
                $bad[] = $one;
            }
        }

        if ($bad !== []) {
            return nibwp_sureforms_err(
                'bad_recipient',
                sprintf(
                    /* translators: %s: the addresses that were refused */
                    __('These are not valid email addresses, so nothing was changed: %s', 'nibwp'),
                    implode(', ', $bad)
                ),
                422
            );
        }

        $item['email_to'] = $to;
        $changed[] = 'email_to';
    }

    if (isset($input['subject'])) {
        $item['subject'] = (string) $input['subject'];
        $changed[] = 'subject';
    }

    if (isset($input['body'])) {
        $item['email_body'] = (string) $input['body'];
        $changed[] = 'body';
    }

    if ($changed === []) {
        return nibwp_sureforms_err(
            'nothing_to_write',
            __('Nothing was given to change. Pass enabled, email_to, subject or body.', 'nibwp'),
            422
        );
    }

    // Keep the rest of the list exactly as it was; only index 0 is touched.
    $all[0] = $item;
    $all = array_values($all);

    $failed = nibwp_sureforms_write_meta($id, NIBWP_SUREFORMS_NOTIFICATION_META, $all);

    if ($failed instanceof WP_Error) {
        return $failed;
    }

    return [
        'form_id' => $id,
        'changed' => $changed,
        'notification' => nibwp_sureforms_notification($id),
        'note' => __('Only the first notification was changed; any others on this form are untouched. SureForms hands this to wp_mail, so delivery still depends on how the site sends mail.', 'nibwp'),
    ];
}

/**
 * Switch a form's captcha.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_set_antispam(array $input): array|WP_Error
{
    $form = nibwp_sureforms_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    $id = (int) $form->ID;

    if ($denied = nibwp_sureforms_can_touch_form($id, __('Changing a SureForms form\'s spam protection', 'nibwp'))) {
        return $denied;
    }

    $captcha = isset($input['captcha']) ? (string) $input['captcha'] : '';

    if (!in_array($captcha, nibwp_sureforms_captcha_types(), true)) {
        return nibwp_sureforms_err(
            'bad_captcha',
            sprintf(
                /* translators: %s: the captcha types SureForms recognises */
                __('Give captcha as one of: %s.', 'nibwp'),
                implode(', ', nibwp_sureforms_captcha_types())
            ),
            422
        );
    }

    $was = nibwp_sureforms_spam_protection($id);
    $version = null;

    if ($captcha === 'g-recaptcha') {
        $version = isset($input['recaptcha_version']) ? (string) $input['recaptcha_version'] : 'v2-checkbox';

        if (!in_array($version, nibwp_sureforms_recaptcha_versions(), true)) {
            return nibwp_sureforms_err(
                'bad_recaptcha_version',
                sprintf(
                    /* translators: %s: the reCAPTCHA versions SureForms recognises */
                    __('Give recaptcha_version as one of: %s.', 'nibwp'),
                    implode(', ', nibwp_sureforms_recaptcha_versions())
                ),
                422
            );
        }
    }

    $failed = nibwp_sureforms_write_meta($id, NIBWP_SUREFORMS_CAPTCHA_META, $captcha);

    if ($failed instanceof WP_Error) {
        return $failed;
    }

    if ($version !== null) {
        $failed = nibwp_sureforms_write_meta($id, NIBWP_SUREFORMS_RECAPTCHA_META, $version);

        if ($failed instanceof WP_Error) {
            return $failed;
        }
    }

    $now = nibwp_sureforms_spam_protection($id);
    $warnings = [];

    // A captcha with no site key does not render, and a check that never runs is
    // worse than no check at all because it looks like protection.
    $security = get_option(NIBWP_SUREFORMS_SECURITY_OPTION, []);
    $security = is_array($security) ? $security : [];
    $needed = match ($captcha) {
        'cf-turnstile' => ['srfm_cf_turnstile_site_key'],
        'hcaptcha' => ['srfm_hcaptcha_site_key'],
        'g-recaptcha' => $version === 'v3-reCAPTCHA'
            ? ['srfm_v3_site_key']
            : ($version === 'v2-invisible' ? ['srfm_v2_invisible_site_key'] : ['srfm_v2_checkbox_site_key']),
        default => [],
    };

    foreach ($needed as $key) {
        if (!isset($security[$key]) || !is_scalar($security[$key]) || (string) $security[$key] === '') {
            $warnings[] = sprintf(
                /* translators: %s: the site key setting that is empty */
                __('"%s" is empty in SureForms\' security settings, so this captcha has nothing to render with and will not actually stop anything until it is filled in.', 'nibwp'),
                $key
            );
        }
    }

    if ($captcha === 'none' && !$now['honeypot_enabled']) {
        $warnings[] = __('This form now has no captcha and the site-wide honeypot is off, so nothing is checking its submissions for spam.', 'nibwp');
    }

    return [
        'form_id' => $id,
        'was' => $was['captcha_type'],
        'now' => $now['captcha_type'],
        'recaptcha_version' => $now['recaptcha_version'],
        'honeypot_enabled' => $now['honeypot_enabled'],
        'protected' => $now['protected'],
        'warnings' => $warnings,
        'note' => __('The captcha keys themselves are site-wide and are not set from here — this only chooses which captcha this form uses.', 'nibwp'),
    ];
}

/**
 * Mark an entry read.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_mark_entry_read(array $input): array|WP_Error
{
    $entry = nibwp_sureforms_entry($input['entry_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Marking a SureForms entry read', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
        return nibwp_sureforms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $id = (int) $entry->ID;
    $was = isset($entry->status) ? (string) $entry->status : '';

    $wpdb->query($wpdb->prepare(
        'UPDATE ' . nibwp_sureforms_entries_table() . ' SET status = %s WHERE ID = %d AND status != %s',
        'read',
        $id,
        'trash'
    ));

    return [
        'entry_id' => $id,
        'was' => $was,
        'now' => 'read',
        'note' => __('Only the status changed. Nothing the person submitted was altered.', 'nibwp'),
    ];
}

/**
 * Delete one entry for good. IRREVERSIBLE.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_sureforms_delete_entry(array $input): array|WP_Error
{
    $entry = nibwp_sureforms_entry($input['entry_id'] ?? null);
    if ($entry instanceof WP_Error) {
        return $entry;
    }

    if ($denied = nibwp_sureforms_require_entries(__('Permanently deleting a SureForms entry', 'nibwp'))) {
        return $denied;
    }

    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
        return nibwp_sureforms_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    $id = (int) $entry->ID;
    $form_id = isset($entry->form_id) ? (int) $entry->form_id : 0;

    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . nibwp_sureforms_entries_table() . ' WHERE ID = %d',
        $id
    ));

    return [
        'entry_id' => $id,
        'form_id' => $form_id,
        'reversible' => false,
        'note' => __('That entry and everything the person submitted in it are gone for good. This cannot be undone.', 'nibwp'),
    ];
}
