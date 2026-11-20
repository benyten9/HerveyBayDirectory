<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: Everest Forms.
 *
 * One hub. Forms and their field structure, the settings that decide whether a
 * form is useful (success message, redirect, notifications, anti-spam), the
 * fields themselves, the entries people submitted, and three audits for the
 * failures that are silent: a form with no notification, a form notifying
 * somewhere other than the site admin, and a form nobody has ever submitted.
 *
 * HOW A FORM IS STORED, which is the thing to get right before writing anything.
 * A form is a post of type `everest_form` (includes/class-evf-post-types.php:47)
 * and the WHOLE form — fields, layout, settings — is JSON in that post's
 * `post_content`. Not post meta, and not serialised PHP: `evf_encode()` is
 * `wp_slash(wp_json_encode($data))` (includes/evf-core-functions.php:1127-1134)
 * and `evf_decode()` is `json_decode($data, true)` (1109-1116). Nothing in this
 * file calls unserialize(), and nothing needs to.
 *
 * The shape, as the plugin's own builder writes it:
 *
 *   id             the form's own post id, repeated inside the JSON
 *   form_field_id  the next-field counter; ids are minted from it
 *   settings       form_title, form_desc, the email connections, anti-spam, …
 *   form_fields    map of field id => { id, type, label, description, required, meta-key, … }
 *   structure      row_N => grid_M => [ field id, … ]  — the builder's layout
 *
 * Verified three ways rather than guessed: EVF_Form_Handler::create() writes
 * exactly those five keys (includes/class-evf-form-handler.php:198-253);
 * EVF_Form_Handler::update() re-encodes the same array back into post_content
 * (351); the builder renders its canvas from structure.row_N.grid_M
 * (includes/admin/builder/class-evf-builder-fields.php:267-413); a new field's
 * default shape is { id, type, label, description } with the id minted as
 * random-string + '-' + counter (includes/abstracts/class-evf-form-fields.php:2578-2583,
 * includes/class-evf-form-handler.php:538-565); and the canonical settings key
 * list is the form migrator's, which exists precisely to produce a form the
 * builder can open (includes/admin/form-migrator/class-evf-fm-contactform7.php:432-464).
 * Writes here go through the same filter dance the plugin does — kses and
 * targeted-link-rel off, balanceTags off — because those filters corrupt JSON in
 * post_content and the plugin removes them for that reason (class-evf-form-handler.php:206-224,
 * 320-338). A form written here still opens in the builder.
 *
 * PERSONAL DATA. Entries are what visitors typed, plus their IP address and
 * device string (includes/class-evf-install.php:407-421). The capability is the
 * one the plugin gates its own Entries screen on — `everest_forms_view_entries`,
 * falling back to `everest_forms_view_others_entries`
 * (includes/admin/class-evf-admin-menus.php:241) — and per object the meta caps
 * `everest_forms_view_entry` / `_edit_entry` / `_delete_entry`, which the plugin
 * maps to own-vs-others through map_meta_cap (includes/class-evf-install.php:670-712)
 * and which evf_get_entry() itself uses (includes/evf-entry-functions.php:22-23).
 * Not edit_posts, which would hand every submitted phone number to anyone who
 * may draft a blog post. A listing returns entry metadata only — never the
 * submitted values and never the IP — and get_entry, one entry at a time, is the
 * only way to the values.
 *
 * SECRETS. The plugin holds captcha secrets for four providers, an AI API key,
 * REST API keys and an entry-approval token (includes/admin/settings/class-evf-settings-security.php:96-144,
 * and the option names enumerated in nibwp_everest_secret_options()). None of
 * them leave. Settings travel through an ALLOW-LIST of keys whose value may be
 * reported; everything else comes back as a name and whether it is set. An
 * allow-list rather than a denylist because add-ons write their own keys into a
 * form's settings and into options, so a denylist would default to leaking
 * whatever the next version adds.
 *
 * IRREVERSIBLE. delete_form permanently deletes the form AND every entry ever
 * submitted to it, and delete_entry permanently deletes one submission. There is
 * no trash step on either and no undo. Both demand confirm:true.
 *
 * Verified against Everest Forms 3.6.2 (everest-forms.php:6).
 */

/** The post type a form is. Everything that takes an id checks this first. */
const NIBWP_EVEREST_CPT = 'everest_form';

/** How many rows any listing returns, whatever was asked for. */
const NIBWP_EVEREST_MAX_ROWS = 100;

/** The furthest back a period query will look. */
const NIBWP_EVEREST_MAX_DAYS = 365;

/**
 * Is Everest Forms actually loaded?
 *
 * EVF_VERSION is defined by the main class once the autoloader succeeded
 * (includes/class-everest-forms.php:220) and evf() is the accessor everything
 * else goes through (everest-forms.php:89). Between them they tell "not
 * installed" apart from "installed and half-loaded", and this file reads
 * post_content directly rather than through the plugin, so either is enough to
 * proceed.
 */
function nibwp_everest_guard(): ?WP_Error
{
    if (!defined('EVF_VERSION') && !function_exists('evf') && !class_exists('EverestForms')) {
        return new WP_Error(
            'nibwp_everest_missing',
            __('Everest Forms is not active on this site.', 'nibwp'),
            ['status' => 400]
        );
    }

    return null;
}

/** A refusal about the request itself. */
function nibwp_everest_err(string $code, string $message, int $status = 400): WP_Error
{
    return new WP_Error('nibwp_everest_' . $code, $message, ['status' => $status]);
}

/** A refusal about authority rather than about the request. */
function nibwp_everest_forbidden(string $what, string $cap): WP_Error
{
    return new WP_Error(
        'nibwp_everest_forbidden',
        sprintf(
            /* translators: 1: what was being attempted, 2: capability name */
            __('%1$s needs the %2$s capability, which this account does not have.', 'nibwp'),
            $what,
            $cap
        ),
        ['status' => 403]
    );
}

/* ------------------------------------------------------------- id and access */

/**
 * Settle the claim that an id is a form, FIRST and on its own.
 *
 * An id arriving from an agent is a claim, not a fact. If the type check came
 * after the capability check, an account that may delete posts could hand
 * delete_form the id of a page and be told "forbidden" on a good day and
 * nothing at all on a bad one. So: bad id, then missing post, then wrong type —
 * each with its own code so a caller can tell which happened — and only then
 * does anything ask what the account may do.
 *
 * Returns WP_Post or WP_Error. Declared `object` because `object|WP_Error` does
 * not parse: WP_Error is itself an object.
 */
function nibwp_everest_form(mixed $raw): object
{
    $id = is_numeric($raw) ? (int) $raw : 0;

    if ($id <= 0) {
        return nibwp_everest_err(
            'bad_id',
            __('Give the form id as form_id, a positive integer.', 'nibwp'),
            422
        );
    }

    $post = get_post($id);

    if (!$post instanceof WP_Post) {
        return nibwp_everest_err(
            'not_found',
            sprintf(
                /* translators: %d: the id that was asked for */
                __('Nothing on this site has the id %d.', 'nibwp'),
                $id
            ),
            404
        );
    }

    if ((string) $post->post_type !== NIBWP_EVEREST_CPT) {
        return nibwp_everest_err(
            'not_a_form',
            sprintf(
                /* translators: 1: the id, 2: the post type it actually is, 3: the expected post type */
                __('Id %1$d is a "%2$s", not an Everest Forms form (%3$s). Nothing was read or changed. List the forms if you need the right id.', 'nibwp'),
                $id,
                (string) $post->post_type,
                NIBWP_EVEREST_CPT
            ),
            422
        );
    }

    return $post;
}

/**
 * May this account read this form?
 *
 * The plugin's own meta cap on the object, which map_meta_cap resolves to
 * own-vs-others (includes/class-evf-install.php:670-712), or the plugin-wide
 * manage capability. Never the plural everest_forms_view_forms on its own: that
 * says "may see the forms list", not "may open this one".
 */
function nibwp_everest_can_read_form(int $form_id): ?WP_Error
{
    if (current_user_can('everest_forms_view_form', $form_id) || current_user_can('manage_everest_forms')) {
        return null;
    }

    return nibwp_everest_forbidden(__('Reading this form', 'nibwp'), 'everest_forms_view_form');
}

/**
 * May this account change this form?
 *
 * Both gates, deliberately. A form is a post, so edit_post on THAT post is the
 * core question; and the plugin has its own per-form capability, so that one too.
 * edit_posts alone — the plural — would let anybody who may draft a blog entry
 * repoint where a form's submissions are emailed.
 */
function nibwp_everest_can_edit_form(int $form_id): ?WP_Error
{
    if (!current_user_can('edit_post', $form_id)) {
        return nibwp_everest_forbidden(__('Changing this form', 'nibwp'), 'edit_post');
    }

    if (!current_user_can('everest_forms_edit_form', $form_id) && !current_user_can('manage_everest_forms')) {
        return nibwp_everest_forbidden(__('Changing this form', 'nibwp'), 'everest_forms_edit_form');
    }

    return null;
}

/** May this account delete this form? Same reasoning, the delete capabilities. */
function nibwp_everest_can_delete_form(int $form_id): ?WP_Error
{
    if (!current_user_can('delete_post', $form_id)) {
        return nibwp_everest_forbidden(__('Deleting this form', 'nibwp'), 'delete_post');
    }

    if (!current_user_can('everest_forms_delete_form', $form_id) && !current_user_can('manage_everest_forms')) {
        return nibwp_everest_forbidden(__('Deleting this form', 'nibwp'), 'everest_forms_delete_form');
    }

    return null;
}

/** May this account create a form at all? The plugin's own gate. */
function nibwp_everest_can_create_form(): ?WP_Error
{
    if (current_user_can('everest_forms_create_forms') || current_user_can('manage_everest_forms')) {
        return null;
    }

    return nibwp_everest_forbidden(__('Creating a form', 'nibwp'), 'everest_forms_create_forms');
}

/**
 * May this account read the entries of one form?
 *
 * everest_forms_view_form_entries against that form id — the meta cap the
 * plugin's own forms list uses to decide whether to show the entries link
 * (includes/admin/class-evf-admin-forms-table-list.php:68 and the meta-cap map
 * at includes/class-evf-install.php:544-560).
 */
function nibwp_everest_can_read_entries(int $form_id): ?WP_Error
{
    if (current_user_can('everest_forms_view_form_entries', $form_id) || current_user_can('manage_everest_forms')) {
        return null;
    }

    return nibwp_everest_forbidden(__('Reading this form\'s entries', 'nibwp'), 'everest_forms_view_form_entries');
}

/**
 * May this account read entries at all, with no particular form in mind?
 *
 * The capability the plugin puts on its own Entries menu page
 * (includes/admin/class-evf-admin-menus.php:241). Not edit_posts.
 */
function nibwp_everest_can_read_any_entries(): ?WP_Error
{
    if (
        current_user_can('everest_forms_view_entries')
        || current_user_can('everest_forms_view_others_entries')
        || current_user_can('manage_everest_forms')
    ) {
        return null;
    }

    return nibwp_everest_forbidden(__('Reading form entries', 'nibwp'), 'everest_forms_view_entries');
}

/** May this account read ONE entry? The meta cap evf_get_entry() itself uses. */
function nibwp_everest_can_read_entry(int $entry_id): ?WP_Error
{
    if (current_user_can('everest_forms_view_entry', $entry_id) || current_user_can('manage_everest_forms')) {
        return null;
    }

    return nibwp_everest_forbidden(__('Reading this entry', 'nibwp'), 'everest_forms_view_entry');
}

/** May this account change one entry — marking it read, for instance? */
function nibwp_everest_can_edit_entry(int $entry_id): ?WP_Error
{
    if (current_user_can('everest_forms_edit_entry', $entry_id) || current_user_can('manage_everest_forms')) {
        return null;
    }

    return nibwp_everest_forbidden(__('Changing this entry', 'nibwp'), 'everest_forms_edit_entry');
}

/** May this account delete one entry? Irreversible, so its own capability. */
function nibwp_everest_can_delete_entry(int $entry_id): ?WP_Error
{
    if (current_user_can('everest_forms_delete_entry', $entry_id) || current_user_can('manage_everest_forms')) {
        return null;
    }

    return nibwp_everest_forbidden(__('Deleting this entry', 'nibwp'), 'everest_forms_delete_entry');
}

/* --------------------------------------------------------------- form data io */

/**
 * The form's data array, decoded from post_content.
 *
 * json_decode, never unserialize: post_content is JSON and has been since the
 * first version (includes/evf-core-functions.php:1109-1116). A form saved by an
 * older version, or one whose JSON a content filter mangled before the plugin
 * learned to turn those filters off, decodes to null — which is reported rather
 * than silently treated as an empty form, because "empty" and "unreadable" lead
 * to opposite decisions.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_form_data(WP_Post $form): array|WP_Error
{
    $raw = (string) $form->post_content;

    if (trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return nibwp_everest_err(
            'bad_form_data',
            sprintf(
                /* translators: %d: form id */
                __('Form %d has a post_content that is not readable JSON, so its fields and settings cannot be reported or changed from here. Open it in the form builder and save it once.', 'nibwp'),
                (int) $form->ID
            ),
            500
        );
    }

    return $data;
}

/**
 * Write a form data array back to post_content, exactly as the builder does.
 *
 * The filter dance is not optional. kses, wp_targeted_link_rel and balanceTags
 * all run on content_save_pre and all of them will happily rewrite JSON into
 * something that no longer parses — which is why the plugin removes them around
 * its own save and puts them back afterwards (includes/class-evf-form-handler.php:206-224
 * and 320-338). Skipping it produces a form that looks saved and will not open.
 *
 * @param array<string, mixed> $data
 * @return true|WP_Error
 */
function nibwp_everest_write_form(WP_Post $form, array $data): bool|WP_Error
{
    $form_id = (int) $form->ID;

    // The id inside the JSON is part of the shape; the builder and every add-on
    // read it rather than the post id.
    $data['id'] = $form_id;

    $title = isset($data['settings']['form_title']) && is_scalar($data['settings']['form_title'])
        ? (string) $data['settings']['form_title']
        : (string) $form->post_title;

    $kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
    if ($kses) {
        kses_remove_filters();
    }

    $link_rel = function_exists('wp_remove_targeted_link_rel_filters')
        && has_filter('content_save_pre', 'wp_targeted_link_rel') !== false;
    if ($link_rel) {
        wp_remove_targeted_link_rel_filters();
    }

    // has_filter() hands back the priority it is attached at, which is what
    // remove_filter() needs — removing at a priority it is not attached at does
    // nothing, and adding it back at the wrong one would double it up.
    $balance = has_filter('content_save_pre', 'balanceTags');
    if ($balance !== false) {
        remove_filter('content_save_pre', 'balanceTags', (int) $balance);
    }

    $encoded = wp_json_encode($data);

    $result = $encoded === false
        ? 0
        : wp_update_post([
            'ID' => $form_id,
            'post_title' => esc_html($title),
            'post_content' => wp_slash($encoded),
        ]);

    if ($balance !== false) {
        add_filter('content_save_pre', 'balanceTags', (int) $balance);
    }
    if ($link_rel && function_exists('wp_init_targeted_link_rel_filters')) {
        wp_init_targeted_link_rel_filters();
    }
    if ($kses) {
        kses_init_filters();
    }

    if ($encoded === false) {
        return nibwp_everest_err(
            'write_failed',
            __('The form data could not be encoded as JSON, so nothing was written.', 'nibwp'),
            500
        );
    }

    if (is_wp_error($result) || (int) $result === 0) {
        return nibwp_everest_err(
            'write_failed',
            __('WordPress refused to save the form.', 'nibwp'),
            500
        );
    }

    return true;
}

/* --------------------------------------------------------- what may be shown */

/**
 * Form settings whose VALUE may be reported, and what each one is.
 *
 * This list is the form migrator's, which exists to produce a form the builder
 * can open and therefore enumerates the keys the builder actually understands
 * (includes/admin/form-migrator/class-evf-fm-contactform7.php:432-464), minus
 * the two the migrator writes that the builder does not read. `email` is here
 * because notifications are the point of half this hub; its contents are walked
 * and scrubbed rather than trusted.
 *
 * Everything else in a form's settings — anything an add-on wrote — is reported
 * as a name and whether it is set.
 *
 * @return array<string, string> key => bool|int|string|array
 */
function nibwp_everest_public_settings(): array
{
    return [
        'form_title' => 'string',
        'form_desc' => 'string',
        'form_disable_message' => 'string',
        'successful_form_submission_message' => 'string',
        'submission_message_scroll' => 'bool',
        'redirect_to' => 'string',
        'custom_page' => 'string',
        'external_url' => 'string',
        'enable_redirect_query_string' => 'bool',
        'query_string' => 'string',
        'form_state_type' => 'string',
        'layout_class' => 'string',
        'form_class' => 'string',
        'submit_button_text' => 'string',
        'submit_button_processing_text' => 'string',
        'submit_button_class' => 'string',
        'ajax_form_submission' => 'bool',
        'disabled_entries' => 'bool',
        'honeypot' => 'bool',
        'akismet' => 'bool',
        'akismet_protection_type' => 'string',
        'recaptcha_support' => 'bool',
        'email' => 'array',
    ];
}

/**
 * Keys inside one email connection whose value may be reported.
 *
 * Recipients and subjects are the whole question an audit asks, so they travel.
 * Anything else a mail add-on hangs off a connection does not.
 *
 * @return array<int, string>
 */
function nibwp_everest_public_email_keys(): array
{
    return [
        'connection_name',
        'enable_email_notification',
        'evf_to_email',
        'evf_carboncopy',
        'evf_blindcarboncopy',
        'evf_from_name',
        'evf_from_email',
        'evf_reply_to',
        'evf_email_subject',
        'evf_email_message',
        'evf_email_attachement',
        'conditional_logic_status',
    ];
}

/**
 * Site-wide options whose VALUE may be reported.
 *
 * Site keys are on the list because a captcha site key is printed into every
 * page that renders the widget — it is public by construction, and whether it
 * matches the secret is the thing owners actually get wrong. Secrets are not,
 * ever, by any spelling.
 *
 * @return array<string, string>
 */
function nibwp_everest_public_options(): array
{
    return [
        'everest_forms_recaptcha_type' => 'string',
        'everest_forms_recaptcha_v2_enable' => 'string',
        'everest_forms_recaptcha_v2_invisible' => 'string',
        'everest_forms_recaptcha_v3_enable' => 'string',
        'everest_forms_recaptcha_hcaptcha_enable' => 'string',
        'everest_forms_recaptcha_turnstile_enable' => 'string',
        'everest_forms_recaptcha_v2_site_key' => 'string',
        'everest_forms_recaptcha_v2_invisible_site_key' => 'string',
        'everest_forms_recaptcha_v3_site_key' => 'string',
        'everest_forms_recaptcha_hcaptcha_site_key' => 'string',
        'everest_forms_recaptcha_turnstile_site_key' => 'string',
        'everest_forms_recaptcha_v3_threshold_score' => 'string',
        'everest_forms_disable_user_details' => 'string',
        'everest_forms_email_template' => 'string',
        'everest_forms_enable_email_copies' => 'string',
        'everest_forms_enable_restapi' => 'string',
    ];
}

/**
 * Options whose value must never travel, however it is asked for.
 *
 * A second layer, not the only one: the allow-lists above already govern what
 * leaves, and nibwp_everest_is_secret_key() catches a name nobody listed. This
 * exists so get_global_settings can say the captcha secret IS configured —
 * which is what an owner is asking — without handing it over. A name appearing
 * in both this list and a public one is a mistake worth failing loudly on, and
 * the check asserts there is no overlap.
 *
 * @return array<int, string>
 */
function nibwp_everest_secret_options(): array
{
    return [
        'everest_forms_recaptcha_v2_secret_key',
        'everest_forms_recaptcha_v2_invisible_secret_key',
        'everest_forms_recaptcha_v3_secret_key',
        'everest_forms_recaptcha_hcaptcha_secret_key',
        'everest_forms_recaptcha_turnstile_secret_key',
        'everest_forms_recaptcha_cleantalk_access_key',
        'everest_forms_recaptcha_site_secret',
        'everest_forms_ai_api_key',
        'everest_forms_restapi_keys',
        'everest_forms_admin_entry_approval_token',
    ];
}

/**
 * Does this key name something that must not travel, whatever level it is at?
 *
 * The backstop. Add-ons write their own keys into a form's settings and into
 * options, so the question cannot only be "is it on a list somebody wrote in
 * 2026" — a webhook secret nested three levels inside an add-on's settings has
 * to be caught on the strength of its name alone.
 */
function nibwp_everest_is_secret_key(string $key): bool
{
    return preg_match(
        '/(secret|api_key|apikey|access_key|private_key|client_secret|_token|password|passwd|auth_key|salt|licen[cs]e_key)/i',
        $key
    ) === 1;
}

/** Is there anything in this value at all? Used for is_set, never for a value. */
function nibwp_everest_is_set(mixed $value): bool
{
    if (is_array($value)) {
        return $value !== [];
    }

    return $value !== '' && $value !== null && $value !== false;
}

/**
 * Walk a value, dropping anything whose key names a secret at any depth.
 *
 * Withheld names accumulate with their path so the response can say WHERE
 * something was held back rather than quietly thinning the payload.
 *
 * @param array<int, array<string, mixed>> $withheld
 */
function nibwp_everest_scrub(mixed $value, array &$withheld, string $path = ''): mixed
{
    if (!is_array($value)) {
        return is_scalar($value) || $value === null ? $value : null;
    }

    $out = [];

    foreach ($value as $key => $item) {
        $key = (string) $key;
        $here = $path === '' ? $key : $path . '.' . $key;

        if (nibwp_everest_is_secret_key($key)) {
            $withheld[] = [
                'key' => $here,
                'is_set' => nibwp_everest_is_set($item),
                'value_returned' => false,
            ];
            continue;
        }

        $out[$key] = nibwp_everest_scrub($item, $withheld, $here);
    }

    return $out;
}

/**
 * Report one email connection through the connection allow-list.
 *
 * @param array<string, mixed> $connection
 * @param array<int, array<string, mixed>> $withheld
 * @return array<string, mixed>
 */
function nibwp_everest_report_connection(string $id, array $connection, array &$withheld): array
{
    $allowed = nibwp_everest_public_email_keys();
    $row = ['connection_id' => $id];

    foreach ($connection as $key => $value) {
        $key = (string) $key;
        $here = 'email.' . $id . '.' . $key;

        if (nibwp_everest_is_secret_key($key) || !in_array($key, $allowed, true)) {
            $withheld[] = [
                'key' => $here,
                'is_set' => nibwp_everest_is_set($value),
                'value_returned' => false,
            ];
            continue;
        }

        $row[$key] = nibwp_everest_scrub($value, $withheld, $here);
    }

    return $row;
}

/**
 * Report a form's settings: values for the allow-list, names for the rest.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function nibwp_everest_report_settings(array $settings): array
{
    $allowed = nibwp_everest_public_settings();
    $rows = [];
    $withheld = [];

    foreach ($settings as $key => $value) {
        $key = (string) $key;

        if (nibwp_everest_is_secret_key($key) || !isset($allowed[$key])) {
            $withheld[] = [
                'key' => $key,
                'is_set' => nibwp_everest_is_set($value),
                'value_returned' => false,
            ];
            continue;
        }

        if ($key === 'email') {
            $connections = [];

            foreach (is_array($value) ? $value : [] as $connection_id => $connection) {
                if (!is_array($connection)) {
                    // Pre-3.0 forms kept the single notification flat under
                    // settings.email rather than under a connection id
                    // (includes/admin/builder/class-evf-builder-settings.php:367-379).
                    continue;
                }

                $connections[] = nibwp_everest_report_connection((string) $connection_id, $connection, $withheld);
            }

            $rows[$key] = $connections;
            continue;
        }

        $rows[$key] = match ($allowed[$key]) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'array' => nibwp_everest_scrub($value, $withheld, $key),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    return [
        'settings' => $rows,
        'withheld' => $withheld,
        'note' => __('Only the settings above report a value. Anything else on this form — including whatever an add-on wrote — is reported as a name and whether it is set.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- numbers */

/** Clamp a row count that arrived over the wire. */
function nibwp_everest_limit(mixed $value, int $default = 25): int
{
    $limit = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_EVEREST_MAX_ROWS, $limit));
}

/** Clamp an offset. */
function nibwp_everest_offset(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

/** Clamp a number of days. */
function nibwp_everest_days(mixed $value, int $default = 30): int
{
    $days = is_numeric($value) ? (int) $value : $default;

    return max(1, min(NIBWP_EVEREST_MAX_DAYS, $days));
}

/* ---------------------------------------------------------------- the tables */

/**
 * The entries table name.
 *
 * $wpdb->prefix, never anything from input — an identifier cannot be bound by
 * prepare(), so the only safe identifier is one the caller never touches. Note
 * it is the per-site prefix, not base_prefix: EVF creates the tables per site
 * (includes/class-evf-install.php:407).
 */
function nibwp_everest_entries_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'evf_entries';
}

/** The entry-meta table name, on the same terms. */
function nibwp_everest_entrymeta_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'evf_entrymeta';
}

/** A usable $wpdb, or a refusal that says so. */
function nibwp_everest_db(): object
{
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
        return nibwp_everest_err('no_db', __('No database handle is available.', 'nibwp'), 500);
    }

    return $wpdb;
}

/**
 * One entry row, straight from the table.
 *
 * Not evf_get_entry(): that function marks an unread entry as read as a side
 * effect of reading it (includes/evf-entry-functions.php:36-48), and its own
 * capability check only applies inside wp-admin (22) — so over REST it would
 * both skip the gate and mutate. Reading is reading here, and mark_entry_read
 * is a separate, named action.
 *
 * @return object WP_Error on failure.
 */
function nibwp_everest_entry_row(mixed $raw): object
{
    $entry_id = is_numeric($raw) ? (int) $raw : 0;

    if ($entry_id <= 0) {
        return nibwp_everest_err('bad_entry_id', __('Give the entry id as entry_id, a positive integer.', 'nibwp'), 422);
    }

    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . nibwp_everest_entries_table() . ' WHERE entry_id = %d LIMIT 1',
            $entry_id
        )
    );

    if (!is_object($row)) {
        return nibwp_everest_err(
            'entry_not_found',
            sprintf(
                /* translators: %d: entry id */
                __('No entry with id %d.', 'nibwp'),
                $entry_id
            ),
            404
        );
    }

    return $row;
}

/* ---------------------------------------------------------------- the fields */

/**
 * The field types this site can actually build with.
 *
 * Asked of the plugin's own registry when it is loaded
 * (includes/class-evf-fields.php:143-155), because which types exist depends on
 * which add-ons are active and hard-coding the answer would mean telling an
 * agent a type is available on a site where it is not. The fallback list is the
 * types that ship in the free plugin, read off includes/fields/.
 *
 * @return array<int, string>
 */
function nibwp_everest_field_types(): array
{
    if (function_exists('evf')) {
        try {
            $evf = evf();

            if (is_object($evf) && isset($evf->form_fields) && is_object($evf->form_fields) && method_exists($evf->form_fields, 'get_form_field_types')) {
                $types = (array) $evf->form_fields->get_form_field_types();

                if ($types !== []) {
                    return array_values(array_unique(array_map('strval', $types)));
                }
            }
        } catch (\Throwable $error) {
            // Fall through to the static list rather than refusing: knowing the
            // type list is a convenience, and the plugin validates on save too.
        }
    }

    return [
        'first-name', 'last-name', 'text', 'textarea', 'select', 'radio', 'checkbox',
        'email', 'url', 'number', 'phone', 'date-time', 'address', 'country',
        'password', 'hidden', 'html', 'divider', 'title', 'privacy-policy',
        'image-upload', 'file-upload', 'color', 'range-slider', 'rating', 'yes-no',
    ];
}

/** Which of those are Pro-only, so a refusal can say why. */
function nibwp_everest_pro_field_types(): array
{
    if (!function_exists('evf')) {
        return [];
    }

    try {
        $evf = evf();

        if (is_object($evf) && isset($evf->form_fields) && is_object($evf->form_fields) && method_exists($evf->form_fields, 'get_pro_form_field_types')) {
            return array_values(array_unique(array_map('strval', (array) $evf->form_fields->get_pro_form_field_types())));
        }
    } catch (\Throwable $error) {
        return [];
    }

    return [];
}

/**
 * Mint a field id the way the plugin does.
 *
 * random string + '-' + the form's field counter
 * (includes/class-evf-form-handler.php:563, includes/evf-core-functions.php:1169-1181).
 * The counter is what the builder increments, so it has to be carried forward in
 * the same array that gets written — which is why this takes it by reference.
 */
function nibwp_everest_new_field_id(int &$counter): string
{
    $random = function_exists('evf_get_random_string')
        ? (string) evf_get_random_string(10)
        : (string) wp_generate_password(10, false, false);

    $id = $random . '-' . $counter;
    ++$counter;

    return $id;
}

/**
 * The meta-key the builder would give a field.
 *
 * sanitised label plus a short random suffix, because two fields labelled the
 * same would otherwise collide and a submission is keyed by meta-key
 * (includes/evf-core-functions.php:1234-1250).
 */
function nibwp_everest_meta_key(string $label, string $type): string
{
    $base = sanitize_key(str_replace(' ', '_', strtolower(trim($label))));

    if ($base === '') {
        $base = sanitize_key($type);
    }

    if ($base === '') {
        $base = 'field';
    }

    return $base . '_' . (string) wp_rand(100, 999);
}

/**
 * Build one field in the shape the builder writes.
 *
 * { id, type, label, description } is what field_new() produces
 * (includes/abstracts/class-evf-form-fields.php:2578-2583); required is '1' or
 * '0' (641-643); choices are a 1-based map of label/value/default
 * (includes/fields/class-evf-field-select.php:25-40). Nothing else is invented:
 * a key the builder does not write is a key the builder will not read.
 *
 * @param array<string, mixed> $spec
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_build_field(array $spec, int &$counter): array|WP_Error
{
    $type = isset($spec['type']) ? sanitize_key((string) $spec['type']) : '';

    if ($type === '') {
        return nibwp_everest_err('missing_arg', __('Each field needs a type. Call list_field_types first if you are unsure which this site has.', 'nibwp'), 422);
    }

    $known = nibwp_everest_field_types();

    if (!in_array($type, $known, true)) {
        $pro = nibwp_everest_pro_field_types();

        return nibwp_everest_err(
            'bad_arg',
            in_array($type, $pro, true)
                ? sprintf(
                    /* translators: %s: field type */
                    __('The "%s" field needs an Everest Forms add-on that is not active on this site.', 'nibwp'),
                    $type
                )
                : sprintf(
                    /* translators: 1: field type, 2: list of available types */
                    __('"%1$s" is not a field type this site has. Available: %2$s.', 'nibwp'),
                    $type,
                    implode(', ', $known)
                ),
            422
        );
    }

    $label = isset($spec['label']) ? sanitize_text_field((string) $spec['label']) : '';

    if ($label === '') {
        $label = ucwords(str_replace('-', ' ', $type));
    }

    $field = [
        'id' => nibwp_everest_new_field_id($counter),
        'type' => $type,
        'label' => $label,
        'description' => isset($spec['description']) ? sanitize_text_field((string) $spec['description']) : '',
        'required' => !empty($spec['required']) ? '1' : '0',
        'meta-key' => nibwp_everest_meta_key($label, $type),
    ];

    if (isset($spec['placeholder'])) {
        $field['placeholder'] = sanitize_text_field((string) $spec['placeholder']);
    }

    // Choice fields are the one place a field carries structure of its own, and
    // the builder refuses to render one with no options at all.
    if (in_array($type, ['select', 'radio', 'checkbox', 'payment-multiple', 'payment-checkbox'], true)) {
        $choices = [];
        $index = 1;

        foreach ((array) ($spec['choices'] ?? []) as $choice) {
            $choice_label = is_array($choice)
                ? (string) ($choice['label'] ?? '')
                : (is_scalar($choice) ? (string) $choice : '');

            if (trim($choice_label) === '') {
                continue;
            }

            $choices[$index] = [
                'label' => sanitize_text_field($choice_label),
                'value' => is_array($choice) && isset($choice['value']) ? sanitize_text_field((string) $choice['value']) : '',
                'default' => is_array($choice) && !empty($choice['default']) ? '1' : '',
            ];
            ++$index;
        }

        if ($choices === []) {
            return nibwp_everest_err(
                'missing_arg',
                sprintf(
                    /* translators: %s: field type */
                    __('A "%s" field needs at least one choice. Give choices as a list of labels, or of objects with label and value.', 'nibwp'),
                    $type
                ),
                422
            );
        }

        $field['choices'] = $choices;
    }

    return $field;
}

/**
 * Place a field id in the layout, in the row and column the builder expects.
 *
 * structure is row_N => grid_M => [ ids ]
 * (includes/admin/builder/class-evf-builder-fields.php:343-413). A field that is
 * in form_fields but in no row never renders — it is in the form and invisible —
 * so every add goes into the layout in the same operation.
 *
 * @param array<string, mixed> $structure
 * @return array<string, mixed>
 */
function nibwp_everest_place_field(array $structure, string $field_id, int $row, int $grid): array
{
    $row_key = 'row_' . max(1, $row);
    $grid_key = 'grid_' . max(1, min(4, $grid));

    if (!isset($structure[$row_key]) || !is_array($structure[$row_key])) {
        $structure[$row_key] = [];
    }
    if (!isset($structure[$row_key][$grid_key]) || !is_array($structure[$row_key][$grid_key])) {
        $structure[$row_key][$grid_key] = [];
    }

    $structure[$row_key][$grid_key][] = $field_id;

    return $structure;
}

/** Drop a field id from every row and column it appears in. */
function nibwp_everest_unplace_field(array $structure, string $field_id): array
{
    foreach ($structure as $row_key => $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach ($row as $grid_key => $ids) {
            if (!is_array($ids)) {
                continue;
            }

            $structure[$row_key][$grid_key] = array_values(array_filter(
                array_map('strval', $ids),
                static fn (string $id): bool => $id !== $field_id
            ));
        }
    }

    return $structure;
}

/** Every field id the layout places, in layout order. */
function nibwp_everest_layout_order(array $structure): array
{
    $order = [];

    foreach ($structure as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach ($row as $ids) {
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (is_scalar($id) && (string) $id !== '') {
                    $order[] = (string) $id;
                }
            }
        }
    }

    return $order;
}

/**
 * Report one field without carrying its whole definition out.
 *
 * @param array<string, mixed> $field
 * @return array<string, mixed>
 */
function nibwp_everest_report_field(string $id, array $field): array
{
    $choices = [];

    foreach ((array) ($field['choices'] ?? []) as $choice) {
        $choice = (array) $choice;
        $choices[] = [
            'label' => isset($choice['label']) && is_scalar($choice['label']) ? (string) $choice['label'] : '',
            'value' => isset($choice['value']) && is_scalar($choice['value']) ? (string) $choice['value'] : '',
            'default' => !empty($choice['default']),
        ];
    }

    return [
        'id' => $id,
        'type' => isset($field['type']) && is_scalar($field['type']) ? (string) $field['type'] : '',
        'label' => isset($field['label']) && is_scalar($field['label']) ? (string) $field['label'] : '',
        'description' => isset($field['description']) && is_scalar($field['description']) ? (string) $field['description'] : '',
        'required' => !empty($field['required']) && (string) $field['required'] !== '0',
        'meta_key' => isset($field['meta-key']) && is_scalar($field['meta-key']) ? (string) $field['meta-key'] : '',
        'choices' => $choices,
    ];
}

/* ------------------------------------------------------------------ the enum */

/**
 * The actions this hub offers.
 *
 * @return array<int, string>
 */
function nibwp_everest_actions(): array
{
    return [
        // Forms.
        'list_forms',
        'get_form',
        'create_form',
        'rename_form',
        'duplicate_form',
        'delete_form',
        // Settings.
        'get_form_settings',
        'set_success_message',
        'set_redirect',
        'list_notifications',
        'set_notification',
        'set_required_fields',
        'set_antispam',
        'get_global_settings',
        // Fields.
        'list_field_types',
        'add_field',
        'update_field',
        'remove_field',
        'reorder_fields',
        // Entries.
        'list_entries',
        'get_entry',
        'count_entries',
        'mark_entry_read',
        'delete_entry',
        // Audit.
        'audit_no_notification',
        'audit_notification_recipients',
        'audit_never_submitted',
    ];
}

wp_register_ability('nibwp/everest-forms-manage', [
    'label' => __('Everest Forms – Forms, Fields, Settings and Entries', 'nibwp'),
    'description' => 'Read and manage Everest Forms: list forms and read one with its full field structure, create a form from a description of its fields, rename, duplicate and delete; '
        . 'read and change the settings that decide whether a form works — success message, redirect, email notifications and their recipients, which fields are required, anti-spam; '
        . 'add, update, remove and reorder fields; list entries (metadata only, bounded), read one entry, count entries over a period, mark one read, delete one; '
        . 'and three audits: forms with no notification, forms notifying somewhere other than the site admin, and forms that have never received an entry. '
        . 'Captcha secrets, the AI API key, REST API keys and the entry-approval token are never returned — only whether each is set.',
    'category' => 'nibwp-forms',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => nibwp_everest_actions(),
                'description' => 'Which operation to run. Start with list_forms.',
            ],
            'form_id' => ['type' => 'integer', 'description' => 'The form to act on. Checked against the everest_form post type before anything else.'],
            'entry_id' => ['type' => 'integer', 'description' => 'For get_entry / mark_entry_read / delete_entry.'],
            'field_id' => ['type' => 'string', 'description' => 'For update_field / remove_field: the field id as get_form reports it.'],
            'title' => ['type' => 'string', 'description' => 'For create_form / rename_form / duplicate_form.'],
            'description' => ['type' => 'string', 'description' => 'For create_form: the form description shown above the fields.'],
            'fields' => [
                'type' => 'array',
                'description' => 'For create_form / add_field: each item { type, label, description, required, placeholder, choices, row, grid }. choices is a list of labels, or of { label, value, default }.',
                'items' => ['type' => 'object'],
            ],
            'field' => ['type' => 'object', 'description' => 'For update_field: the keys to change — label, description, required, placeholder, choices.'],
            'order' => [
                'type' => 'array',
                'description' => 'For reorder_fields: every field id, in the order they should appear. One row, one column.',
                'items' => ['type' => 'string'],
            ],
            'required_fields' => [
                'type' => 'array',
                'description' => 'For set_required_fields: the field ids that should be required. Every other field is made optional.',
                'items' => ['type' => 'string'],
            ],
            'message' => ['type' => 'string', 'description' => 'For set_success_message: what the visitor sees after submitting.'],
            'redirect_to' => ['type' => 'string', 'description' => 'For set_redirect: same, custom_page or external_url.'],
            'page_id' => ['type' => 'integer', 'description' => 'For set_redirect with custom_page.'],
            'url' => ['type' => 'string', 'description' => 'For set_redirect with external_url. http(s) only.'],
            'connection_id' => ['type' => 'string', 'description' => 'For set_notification: which notification, as list_notifications reports it. Omit to create a new one.'],
            'enabled' => ['type' => 'boolean', 'description' => 'For set_notification: whether the notification sends at all.'],
            'to_email' => ['type' => 'string', 'description' => 'For set_notification: recipients, comma-separated. Smart tags such as {admin_email} are allowed.'],
            'subject' => ['type' => 'string', 'description' => 'For set_notification: the subject line.'],
            'honeypot' => ['type' => 'boolean', 'description' => 'For set_antispam.'],
            'akismet' => ['type' => 'boolean', 'description' => 'For set_antispam. Needs the Akismet plugin active and configured to do anything.'],
            'akismet_protection_type' => ['type' => 'string', 'description' => 'For set_antispam: validation_failed (reject) or mark_as_spam.'],
            'captcha' => ['type' => 'boolean', 'description' => 'For set_antispam: whether this form shows the site-wide captcha.'],
            'status' => ['type' => 'string', 'description' => 'For list_forms / list_entries: publish, draft, trash, or any.'],
            'days' => ['type' => 'integer', 'description' => 'For count_entries / audit_never_submitted: how far back. Default 30, max 365.'],
            'limit' => ['type' => 'integer', 'description' => 'Rows to return. Default 25, max 100.'],
            'offset' => ['type' => 'integer', 'description' => 'Where a listing starts.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for delete_form and delete_entry. Both are permanent.'],
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
    'execute_callback' => 'nibwp_everest_forms_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Everest Forms. One hub; every operation takes an action.',
                '',
                'START WITH list_forms, then get_form for the one you mean. get_form returns the',
                'whole field structure with each field id, which is what add_field, update_field,',
                'remove_field and reorder_fields all target.',
                '',
                'A FORM IS A POST of type everest_form, and the entire form lives as JSON in that',
                'post. An id you were given is a claim: every action checks the post type first and',
                'refuses a page or a post on the type, before asking what the account may do. If',
                'you get nibwp_everest_not_a_form, you have the wrong id — list the forms.',
                '',
                'ENTRIES ARE PEOPLE. list_entries returns metadata only — when, which form, read or',
                'not — never the submitted values and never the visitor\'s IP address. get_entry is',
                'the only way to the values, one entry at a time, and takes the capability the',
                'plugin puts on its own Entries screen. Prefer count_entries when the question is',
                '"how many".',
                '',
                'IRREVERSIBLE, both demanding confirm:true:',
                '- delete_form permanently deletes the form AND every entry ever submitted to it.',
                '  There is no trash step. Say that back to whoever asked, with the entry count,',
                '  before you call it.',
                '- delete_entry permanently deletes one submission and its meta. No undo.',
                '',
                'SECRETS: get_global_settings reports captcha SITE keys, which are public by',
                'construction, and reports every secret — captcha secret keys, the AI API key, the',
                'REST API keys, the entry-approval token — as a name and whether it is set. Asking',
                'differently will not change that.',
                '',
                'AUDITS are where the silent failures are. audit_no_notification finds forms that',
                'collect submissions and tell nobody. audit_notification_recipients finds forms',
                'emailing somewhere other than the site admin — which is usually deliberate and',
                'occasionally a departed employee. audit_never_submitted finds forms nobody has',
                'ever filled in, which is as often a broken form as an unused one.',
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
function nibwp_everest_forms_manage(array $input): array|WP_Error
{
    $guard = nibwp_everest_guard();
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $action = isset($input['action']) ? (string) $input['action'] : '';

    if (!in_array($action, nibwp_everest_actions(), true)) {
        return nibwp_everest_err(
            'unknown_action',
            sprintf(
                /* translators: 1: action name, 2: list of valid actions */
                __('Unknown Everest Forms action "%1$s". Valid actions: %2$s.', 'nibwp'),
                $action,
                implode(', ', nibwp_everest_actions())
            )
        );
    }

    // The plugin's internals are version-sensitive and its field registry
    // instantiates every add-on's classes. One catch here turns any of that into
    // a named refusal rather than a fatal that takes the REST request with it.
    try {
        return nibwp_everest_dispatch($action, $input);
    } catch (\Throwable $error) {
        return nibwp_everest_err(
            'plugin_error',
            sprintf(
                /* translators: 1: action name, 2: error message from the plugin */
                __('Everest Forms could not complete %1$s: %2$s', 'nibwp'),
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
function nibwp_everest_dispatch(string $action, array $input): array|WP_Error
{
    switch ($action) {
        case 'list_forms':
            $result = nibwp_everest_list_forms($input);
            break;
        case 'get_form':
            $result = nibwp_everest_get_form($input);
            break;
        case 'create_form':
            $result = nibwp_everest_create_form($input);
            break;
        case 'rename_form':
            $result = nibwp_everest_rename_form($input);
            break;
        case 'duplicate_form':
            $result = nibwp_everest_duplicate_form($input);
            break;
        case 'delete_form':
            $result = nibwp_everest_delete_form($input);
            break;
        case 'get_form_settings':
            $result = nibwp_everest_get_form_settings($input);
            break;
        case 'set_success_message':
            $result = nibwp_everest_set_success_message($input);
            break;
        case 'set_redirect':
            $result = nibwp_everest_set_redirect($input);
            break;
        case 'list_notifications':
            $result = nibwp_everest_list_notifications($input);
            break;
        case 'set_notification':
            $result = nibwp_everest_set_notification($input);
            break;
        case 'set_required_fields':
            $result = nibwp_everest_set_required_fields($input);
            break;
        case 'set_antispam':
            $result = nibwp_everest_set_antispam($input);
            break;
        case 'get_global_settings':
            $result = nibwp_everest_get_global_settings();
            break;
        case 'list_field_types':
            $result = nibwp_everest_list_field_types();
            break;
        case 'add_field':
            $result = nibwp_everest_add_field($input);
            break;
        case 'update_field':
            $result = nibwp_everest_update_field($input);
            break;
        case 'remove_field':
            $result = nibwp_everest_remove_field($input);
            break;
        case 'reorder_fields':
            $result = nibwp_everest_reorder_fields($input);
            break;
        case 'list_entries':
            $result = nibwp_everest_list_entries($input);
            break;
        case 'get_entry':
            $result = nibwp_everest_get_entry($input);
            break;
        case 'count_entries':
            $result = nibwp_everest_count_entries($input);
            break;
        case 'mark_entry_read':
            $result = nibwp_everest_mark_entry_read($input);
            break;
        case 'delete_entry':
            $result = nibwp_everest_delete_entry($input);
            break;
        case 'audit_no_notification':
            $result = nibwp_everest_audit_no_notification($input);
            break;
        case 'audit_notification_recipients':
            $result = nibwp_everest_audit_notification_recipients($input);
            break;
        case 'audit_never_submitted':
            $result = nibwp_everest_audit_never_submitted($input);
            break;
        default:
            $result = nibwp_everest_err('unhandled_action', __('Unhandled action.', 'nibwp'));
            break;
    }

    return $result instanceof WP_Error ? $result : ['action' => $action] + $result;
}

/**
 * The two steps every id-taking action opens with, in the only safe order.
 *
 * Type first, alone. Capability second. Returns the WP_Post, or the WP_Error
 * from whichever step refused — and because the two steps answer with different
 * codes, the caller (and the check) can tell which one it was.
 *
 * @param 'read'|'edit'|'delete' $intent
 */
function nibwp_everest_open_form(array $input, string $intent): object
{
    $form = nibwp_everest_form($input['form_id'] ?? null);

    if ($form instanceof WP_Error) {
        return $form;
    }

    $denied = match ($intent) {
        'edit' => nibwp_everest_can_edit_form((int) $form->ID),
        'delete' => nibwp_everest_can_delete_form((int) $form->ID),
        default => nibwp_everest_can_read_form((int) $form->ID),
    };

    return $denied instanceof WP_Error ? $denied : $form;
}

/* -------------------------------------------------------------------- forms */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_list_forms(array $input): array|WP_Error
{
    if ($denied = nibwp_everest_can_read_any_forms()) {
        return $denied;
    }

    $limit = nibwp_everest_limit($input['limit'] ?? null);
    $offset = nibwp_everest_offset($input['offset'] ?? null);
    $status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'publish';

    if (!in_array($status, ['publish', 'draft', 'trash', 'any'], true)) {
        return nibwp_everest_err('bad_arg', __('status must be publish, draft, trash or any.', 'nibwp'), 422);
    }

    $posts = get_posts([
        'post_type' => NIBWP_EVEREST_CPT,
        'post_status' => $status === 'any' ? ['publish', 'draft', 'trash'] : $status,
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
    ]);

    $rows = [];

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        // A form the account may not open is not named: the list is a listing of
        // what this account can work with, not a map of the site.
        if (nibwp_everest_can_read_form((int) $post->ID) instanceof WP_Error) {
            continue;
        }

        $data = nibwp_everest_form_data($post);
        $fields = $data instanceof WP_Error ? [] : (array) ($data['form_fields'] ?? []);

        $rows[] = [
            'form_id' => (int) $post->ID,
            'title' => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'created' => (string) $post->post_date_gmt,
            'modified' => (string) $post->post_modified_gmt,
            'field_count' => count($fields),
            'readable' => !$data instanceof WP_Error,
            'shortcode' => '[everest_form id="' . (int) $post->ID . '"]',
        ];
    }

    return [
        'returned' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'forms' => $rows,
        'note' => __('Forms this account may not open are left out rather than named. get_form returns the field structure for one.', 'nibwp'),
    ];
}

/** May this account see the forms list at all? */
function nibwp_everest_can_read_any_forms(): ?WP_Error
{
    if (
        current_user_can('everest_forms_view_forms')
        || current_user_can('everest_forms_view_others_forms')
        || current_user_can('manage_everest_forms')
    ) {
        return null;
    }

    return nibwp_everest_forbidden(__('Listing forms', 'nibwp'), 'everest_forms_view_forms');
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_get_form(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'read');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $fields = (array) ($data['form_fields'] ?? []);
    $structure = (array) ($data['structure'] ?? []);
    $order = nibwp_everest_layout_order($structure);

    $reported = [];
    foreach ($order as $id) {
        if (isset($fields[$id]) && is_array($fields[$id])) {
            $reported[] = nibwp_everest_report_field($id, $fields[$id]);
        }
    }

    // A field that is in form_fields but in no row never renders. That is a real
    // and invisible failure, so it is named rather than folded into the list.
    $orphans = [];
    foreach ($fields as $id => $field) {
        if (!in_array((string) $id, $order, true) && is_array($field)) {
            $orphans[] = nibwp_everest_report_field((string) $id, $field);
        }
    }

    return [
        'form_id' => (int) $form->ID,
        'title' => (string) $form->post_title,
        'status' => (string) $form->post_status,
        'description' => isset($data['settings']['form_desc']) && is_scalar($data['settings']['form_desc'])
            ? (string) $data['settings']['form_desc']
            : '',
        'shortcode' => '[everest_form id="' . (int) $form->ID . '"]',
        'field_count' => count($fields),
        'fields' => $reported,
        'fields_not_in_layout' => $orphans,
        'layout' => $structure,
        'next_field_counter' => isset($data['form_field_id']) ? (int) $data['form_field_id'] : count($fields),
        'note' => $orphans === []
            ? __('fields are in the order the form renders them. layout is the builder\'s own row and column map.', 'nibwp')
            : __('fields_not_in_layout are in the form but in no row, so they do not render. reorder_fields puts every field back into one column.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_create_form(array $input): array|WP_Error
{
    if ($denied = nibwp_everest_can_create_form()) {
        return $denied;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_everest_err('missing_arg', __('Give the new form a title.', 'nibwp'), 422);
    }

    $specs = (array) ($input['fields'] ?? []);

    if ($specs === []) {
        return nibwp_everest_err(
            'missing_arg',
            __('Give the fields the form should have, as fields: a list of { type, label, required }. A form with no fields cannot be submitted.', 'nibwp'),
            422
        );
    }

    if (count($specs) > NIBWP_EVEREST_MAX_ROWS) {
        return nibwp_everest_err(
            'bad_arg',
            sprintf(
                /* translators: %d: the maximum */
                __('That is more than %d fields in one call. Create the form, then add the rest with add_field.', 'nibwp'),
                NIBWP_EVEREST_MAX_ROWS
            ),
            422
        );
    }

    // Everything is validated before a post exists, so a bad field type does not
    // leave a half-built form behind.
    $counter = 1;
    $fields = [];
    $structure = [];
    $row = 1;

    foreach ($specs as $spec) {
        $field = nibwp_everest_build_field(is_array($spec) ? $spec : [], $counter);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $fields[(string) $field['id']] = $field;
        $structure = nibwp_everest_place_field(
            $structure,
            (string) $field['id'],
            is_array($spec) && isset($spec['row']) && is_numeric($spec['row']) ? (int) $spec['row'] : $row,
            is_array($spec) && isset($spec['grid']) && is_numeric($spec['grid']) ? (int) $spec['grid'] : 1
        );
        ++$row;
    }

    $form_id = wp_insert_post([
        'post_type' => NIBWP_EVEREST_CPT,
        'post_status' => 'publish',
        'post_title' => esc_html($title),
        // wp_slash even on a placeholder: wp_insert_post() unslashes what it is
        // given, and the rule that every post_content write is slashed is only
        // useful if it has no exceptions to argue about later.
        'post_content' => wp_slash('{}'),
    ]);

    if (is_wp_error($form_id) || (int) $form_id === 0) {
        return nibwp_everest_err('write_failed', __('WordPress refused to create the form post.', 'nibwp'), 500);
    }

    $form = get_post((int) $form_id);

    if (!$form instanceof WP_Post) {
        return nibwp_everest_err('write_failed', __('The form post was created but cannot be read back.', 'nibwp'), 500);
    }

    $data = [
        'id' => (int) $form_id,
        'form_enabled' => '1',
        'form_field_id' => (string) $counter,
        'form_fields' => $fields,
        'structure' => $structure,
        'settings' => [
            'form_title' => $title,
            'form_desc' => isset($input['description']) ? sanitize_text_field((string) $input['description']) : '',
            'form_disable_message' => __('This form is disabled.', 'nibwp'),
            'successful_form_submission_message' => __('Thanks for getting in touch. We will be back to you shortly.', 'nibwp'),
            'redirect_to' => 'same',
            'custom_page' => '',
            'external_url' => '',
            'query_string' => '',
            'layout_class' => 'default',
            'form_class' => '',
            'submit_button_text' => __('Submit', 'nibwp'),
            'submit_button_processing_text' => __('Processing…', 'nibwp'),
            'submit_button_class' => '',
            'ajax_form_submission' => '0',
            'disabled_entries' => '0',
            // Honeypot on by default, which is what the builder defaults to
            // (includes/admin/builder/class-evf-builder-settings.php:868-877).
            'honeypot' => '1',
            'akismet' => '0',
            'akismet_protection_type' => 'validation_failed',
            'recaptcha_support' => '0',
            // One notification, to the site admin. A form created with none
            // collects submissions and tells nobody, which is the single most
            // common way a new form is quietly broken.
            'email' => [
                'connection_1' => [
                    'connection_name' => __('Admin Notification', 'nibwp'),
                    'enable_email_notification' => '1',
                    'evf_to_email' => '{admin_email}',
                    'evf_from_name' => (string) get_bloginfo('name'),
                    'evf_from_email' => '{admin_email}',
                    'evf_reply_to' => '',
                    'evf_email_subject' => sprintf(
                        /* translators: %s: form title */
                        __('New entry: %s', 'nibwp'),
                        $title
                    ),
                    'evf_email_message' => '{all_fields}',
                ],
            ],
        ],
    ];

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'created' => true,
        'form_id' => (int) $form_id,
        'title' => $title,
        'field_count' => count($fields),
        'shortcode' => '[everest_form id="' . (int) $form_id . '"]',
        'notification' => __('One notification was created, to the site administrator. Change it with set_notification.', 'nibwp'),
        'note' => __('The form is published and will open in the form builder. Place the shortcode on a page for it to appear.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_rename_form(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $title = isset($input['title']) ? sanitize_text_field((string) $input['title']) : '';

    if ($title === '') {
        return nibwp_everest_err('missing_arg', __('Give the new title as title.', 'nibwp'), 422);
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $was = (string) $form->post_title;

    // The title lives twice — the post title and settings.form_title — and the
    // builder reads the second (includes/class-evf-form-handler.php:306-311).
    // Changing only one leaves the two disagreeing for good.
    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = [];
    }
    $data['settings']['form_title'] = $title;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'renamed' => true,
        'form_id' => (int) $form->ID,
        'was' => $was,
        'now' => $title,
        'note' => __('The shortcode uses the form id, not the title, so anything already published still works.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_duplicate_form(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'read');
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_everest_can_create_form()) {
        return $denied;
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $title = isset($input['title']) && trim((string) $input['title']) !== ''
        ? sanitize_text_field((string) $input['title'])
        : sprintf(
            /* translators: %s: the original form title */
            __('%s (copy)', 'nibwp'),
            (string) $form->post_title
        );

    $new_id = wp_insert_post([
        'post_type' => NIBWP_EVEREST_CPT,
        'post_status' => 'draft',
        'post_title' => esc_html($title),
        // wp_slash even on a placeholder: wp_insert_post() unslashes what it is
        // given, and the rule that every post_content write is slashed is only
        // useful if it has no exceptions to argue about later.
        'post_content' => wp_slash('{}'),
    ]);

    if (is_wp_error($new_id) || (int) $new_id === 0) {
        return nibwp_everest_err('write_failed', __('WordPress refused to create the duplicate.', 'nibwp'), 500);
    }

    $copy = get_post((int) $new_id);

    if (!$copy instanceof WP_Post) {
        return nibwp_everest_err('write_failed', __('The duplicate was created but cannot be read back.', 'nibwp'), 500);
    }

    // Field ids are carried over unchanged on purpose: a submission is keyed by
    // meta-key and the copy is a separate form with its own entries table rows,
    // so nothing collides, and keeping the ids means any conditional logic
    // inside the form still points at the right field.
    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = [];
    }
    $data['settings']['form_title'] = $title;
    unset($data['is_new_form']);

    $written = nibwp_everest_write_form($copy, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'duplicated' => true,
        'form_id' => (int) $new_id,
        'copied_from' => (int) $form->ID,
        'title' => $title,
        'status' => 'draft',
        'note' => __('The copy is a draft, and no entries were copied — entries belong to the form they were submitted to. Publish it when it is ready.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_delete_form(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'delete');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $form_id = (int) $form->ID;
    $entries = nibwp_everest_count_form_entries($form_id);

    if (empty($input['confirm'])) {
        return nibwp_everest_err(
            'no_confirm',
            sprintf(
                /* translators: 1: form title, 2: form id, 3: number of entries */
                __('Deleting "%1$s" (id %2$d) is permanent: the form goes, and so do all %3$d entries ever submitted to it, with no trash step and no undo. Say that to whoever asked, then call again with confirm:true.', 'nibwp'),
                (string) $form->post_title,
                $form_id,
                $entries instanceof WP_Error ? 0 : $entries
            ),
            428
        );
    }

    // The entry rows are deleted here rather than left to the plugin. Everest
    // Forms cleans them up on the deleted_post hook, but that hook is registered
    // by EVF_Admin_Forms (includes/admin/class-evf-admin-forms.php:21), which is
    // only included when is_admin() (includes/class-everest-forms.php:352-355) —
    // so over REST the form would go and its entries would stay behind as rows
    // nothing can reach. Doing it explicitly is also idempotent if the hook does
    // happen to be there.
    $removed = nibwp_everest_purge_form_entries($form_id);

    if ($removed instanceof WP_Error) {
        return $removed;
    }

    $deleted = wp_delete_post($form_id, true);

    if ($deleted === false || $deleted === null) {
        return nibwp_everest_err('write_failed', __('WordPress refused to delete the form. Its entries have already been removed.', 'nibwp'), 500);
    }

    return [
        'deleted' => true,
        'form_id' => $form_id,
        'title' => (string) $form->post_title,
        'entries_deleted' => $removed,
        'note' => __('Permanent. The form and its entries are gone and cannot be restored. Anywhere the shortcode was published now renders nothing.', 'nibwp'),
    ];
}

/* ----------------------------------------------------------------- settings */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_get_form_settings(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'read');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $report = nibwp_everest_report_settings((array) ($data['settings'] ?? []));

    return [
        'form_id' => (int) $form->ID,
        'title' => (string) $form->post_title,
        'enabled' => !isset($data['form_enabled']) || (string) $data['form_enabled'] !== '0',
    ] + $report;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_set_success_message(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    if (!isset($input['message']) || trim((string) $input['message']) === '') {
        return nibwp_everest_err('missing_arg', __('Give the message as message.', 'nibwp'), 422);
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    // wp_kses_post rather than sanitize_text_field: the builder's own field is a
    // rich-text one, so a link or a line break is legitimate here and stripping
    // it would quietly flatten what an owner wrote.
    $message = wp_kses_post((string) $input['message']);

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = [];
    }

    $was = isset($data['settings']['successful_form_submission_message'])
        ? (string) $data['settings']['successful_form_submission_message']
        : '';

    $data['settings']['successful_form_submission_message'] = $message;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'form_id' => (int) $form->ID,
        'was' => $was,
        'now' => $message,
        'shown_when' => isset($data['settings']['redirect_to']) && (string) $data['settings']['redirect_to'] !== 'same'
            ? __('This form redirects after submitting, so the message is stored but the visitor never sees it. set_redirect with same would show it.', 'nibwp')
            : __('The visitor sees this in place of the form after submitting.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_set_redirect(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $mode = isset($input['redirect_to']) ? sanitize_key((string) $input['redirect_to']) : '';

    if (!in_array($mode, ['same', 'custom_page', 'external_url'], true)) {
        return nibwp_everest_err(
            'bad_arg',
            __('redirect_to must be same, custom_page or external_url. These are the three the builder offers (includes/admin/builder/class-evf-builder-settings.php:666-667).', 'nibwp'),
            422
        );
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = [];
    }

    $page_id = 0;
    $url = '';

    if ($mode === 'custom_page') {
        $page_id = isset($input['page_id']) && is_numeric($input['page_id']) ? (int) $input['page_id'] : 0;

        if ($page_id <= 0 || !get_post($page_id) instanceof WP_Post) {
            return nibwp_everest_err('bad_arg', __('custom_page needs page_id to be a post or page that exists on this site.', 'nibwp'), 422);
        }
    }

    if ($mode === 'external_url') {
        $url = isset($input['url']) ? trim((string) $input['url']) : '';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        // A redirect target is followed by a visitor's browser the moment they
        // submit, so javascript:, data: and the rest are refused on the scheme
        // rather than left to whatever the front end does with them.
        if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
            return nibwp_everest_err('bad_arg', __('external_url needs url to be an http or https address.', 'nibwp'), 422);
        }

        $url = esc_url_raw($url);
    }

    $was = isset($data['settings']['redirect_to']) ? (string) $data['settings']['redirect_to'] : 'same';

    $data['settings']['redirect_to'] = $mode;
    $data['settings']['custom_page'] = $mode === 'custom_page' ? (string) $page_id : '';
    $data['settings']['external_url'] = $mode === 'external_url' ? $url : '';

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'form_id' => (int) $form->ID,
        'was' => $was,
        'now' => $mode,
        'page_id' => $page_id > 0 ? $page_id : null,
        'url' => $url !== '' ? $url : null,
        'note' => $mode === 'same'
            ? __('The form now shows its success message in place instead of redirecting.', 'nibwp')
            : __('Entries are still recorded on redirect. Check the destination actually exists before telling anyone it works.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_list_notifications(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'read');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $connections = nibwp_everest_connections($data);
    $withheld = [];
    $rows = [];

    foreach ($connections as $id => $connection) {
        $rows[] = nibwp_everest_report_connection((string) $id, $connection, $withheld) + [
            'sends' => nibwp_everest_notification_enabled($connection),
        ];
    }

    $sending = array_filter($rows, static fn (array $row): bool => (bool) ($row['sends'] ?? false));

    return [
        'form_id' => (int) $form->ID,
        'count' => count($rows),
        'sending_count' => count($sending),
        'notifications' => $rows,
        'withheld' => $withheld,
        'note' => $sending === []
            ? __('No notification on this form is switched on, so submissions are recorded and nobody is told. That is the most common way a form looks fine and is not.', 'nibwp')
            : __('A notification with enable_email_notification "0" is stored but does not send.', 'nibwp'),
    ];
}

/**
 * The email connections on a form, normalised.
 *
 * Pre-3.0 forms kept one notification flat under settings.email rather than
 * under a connection id, and the builder migrates that on first open
 * (includes/admin/builder/class-evf-builder-settings.php:367-379). Reading a
 * form that has not been opened since means seeing the old shape, so both are
 * handled here rather than in four call sites.
 *
 * @param array<string, mixed> $data
 * @return array<string, array<string, mixed>>
 */
function nibwp_everest_connections(array $data): array
{
    $email = (array) ($data['settings']['email'] ?? []);
    $out = [];

    foreach ($email as $key => $value) {
        if (is_array($value)) {
            $out[(string) $key] = $value;
        }
    }

    if ($out === [] && $email !== []) {
        // The flat legacy shape. Presented under the id the builder would give
        // it, so set_notification can address it.
        $out['connection_1'] = $email;
    }

    return $out;
}

/**
 * Does this connection actually send?
 *
 * Absent means yes: the builder defaults the toggle to '1' and only writes '0'
 * when it is switched off (includes/admin/builder/class-evf-builder-settings.php:398-414).
 * Reading absence as "off" would report every form built before the toggle
 * existed as silent.
 *
 * @param array<string, mixed> $connection
 */
function nibwp_everest_notification_enabled(array $connection): bool
{
    if (!isset($connection['enable_email_notification'])) {
        return true;
    }

    return (string) $connection['enable_email_notification'] !== '0';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_set_notification(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $connections = nibwp_everest_connections($data);
    $id = isset($input['connection_id']) ? sanitize_key((string) $input['connection_id']) : '';
    $creating = false;

    if ($id === '') {
        $id = 'connection_' . (count($connections) + 1);

        while (isset($connections[$id])) {
            $id = 'connection_' . (string) wp_rand(1000, 999999);
        }

        $creating = true;
    } elseif (!isset($connections[$id])) {
        return nibwp_everest_err(
            'not_found',
            sprintf(
                /* translators: 1: connection id, 2: the ids that do exist */
                __('This form has no notification "%1$s". It has: %2$s. Leave connection_id out to add a new one.', 'nibwp'),
                $id,
                $connections === [] ? __('none', 'nibwp') : implode(', ', array_keys($connections))
            ),
            404
        );
    }

    $connection = $connections[$id] ?? [
        'connection_name' => __('Notification', 'nibwp'),
        'evf_from_name' => (string) get_bloginfo('name'),
        'evf_from_email' => '{admin_email}',
        'evf_reply_to' => '',
        'evf_email_message' => '{all_fields}',
    ];

    $changed = [];

    if (isset($input['enabled'])) {
        $connection['enable_email_notification'] = $input['enabled'] ? '1' : '0';
        $changed[] = 'enabled';
    }

    if (isset($input['to_email'])) {
        $recipients = nibwp_everest_recipients((string) $input['to_email']);

        if ($recipients instanceof WP_Error) {
            return $recipients;
        }

        $connection['evf_to_email'] = $recipients;
        $changed[] = 'to_email';
    }

    if (isset($input['subject'])) {
        $subject = sanitize_text_field((string) $input['subject']);

        if ($subject === '') {
            return nibwp_everest_err('bad_arg', __('An empty subject line makes the notification look like spam. Give one.', 'nibwp'), 422);
        }

        $connection['evf_email_subject'] = $subject;
        $changed[] = 'subject';
    }

    if ($creating && !isset($connection['evf_to_email'])) {
        return nibwp_everest_err('missing_arg', __('A new notification needs to_email: who it goes to.', 'nibwp'), 422);
    }

    if ($changed === [] && !$creating) {
        return nibwp_everest_err('missing_arg', __('Nothing to change. Give at least one of enabled, to_email or subject.', 'nibwp'), 422);
    }

    if ($creating) {
        $connection['connection_name'] = isset($input['title'])
            ? sanitize_text_field((string) $input['title'])
            : (string) $connection['connection_name'];
        $connection['enable_email_notification'] = $connection['enable_email_notification'] ?? '1';
        $connection['evf_email_subject'] = $connection['evf_email_subject'] ?? sprintf(
            /* translators: %s: form title */
            __('New entry: %s', 'nibwp'),
            (string) $form->post_title
        );
    }

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = [];
    }

    // Written back as the keyed shape whatever shape it arrived in, which is the
    // same normalisation the builder performs on first open.
    $connections[$id] = $connection;
    $data['settings']['email'] = $connections;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'created' => $creating,
        'form_id' => (int) $form->ID,
        'connection_id' => $id,
        'changed' => $changed,
        'sends' => nibwp_everest_notification_enabled($connection),
        'to_email' => isset($connection['evf_to_email']) ? (string) $connection['evf_to_email'] : '',
        'note' => __('Where a notification goes decides who finds out about a submission. Say the recipients back to whoever asked.', 'nibwp'),
    ];
}

/**
 * Validate a recipient list.
 *
 * Everest Forms allows smart tags such as {admin_email} and {field_id="x"} in
 * the To field, so a plain is_email() over the whole string would refuse what
 * the builder itself writes. Each comma-separated part is either a smart tag or
 * an address, and a part that is neither is named.
 *
 * @return string|WP_Error
 */
function nibwp_everest_recipients(string $raw): string|WP_Error
{
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== '');

    if ($parts === []) {
        return nibwp_everest_err('bad_arg', __('to_email is empty. A notification with no recipient sends nowhere.', 'nibwp'), 422);
    }

    $clean = [];

    foreach ($parts as $part) {
        if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
            $clean[] = sanitize_text_field($part);
            continue;
        }

        if (is_email($part) === false) {
            return nibwp_everest_err(
                'bad_arg',
                sprintf(
                    /* translators: %s: the rejected recipient */
                    __('"%s" is neither an email address nor a smart tag such as {admin_email}.', 'nibwp'),
                    $part
                ),
                422
            );
        }

        $clean[] = sanitize_email($part);
    }

    return implode(', ', $clean);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_set_required_fields(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    if (!isset($input['required_fields']) || !is_array($input['required_fields'])) {
        return nibwp_everest_err(
            'missing_arg',
            __('Give required_fields as a list of field ids. Every field not in the list is made optional, so send the whole list, not just the additions.', 'nibwp'),
            422
        );
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $fields = (array) ($data['form_fields'] ?? []);
    $wanted = array_values(array_unique(array_map('strval', $input['required_fields'])));
    $unknown = array_values(array_diff($wanted, array_map('strval', array_keys($fields))));

    if ($unknown !== []) {
        return nibwp_everest_err(
            'not_found',
            sprintf(
                /* translators: %s: the field ids that do not exist */
                __('This form has no field with id: %s. Nothing was changed. get_form lists the ids.', 'nibwp'),
                implode(', ', $unknown)
            ),
            404
        );
    }

    // Some field types have nothing to require — a divider, a heading, an HTML
    // block. Marking one required would make the form unsubmittable with nothing
    // for the visitor to fill in.
    $decorative = ['html', 'divider', 'title', 'captcha', 'recaptcha', 'hcaptcha', 'turnstile', 'reset', 'private-note', 'payment_summary', 'progress'];
    $required_now = [];
    $optional_now = [];
    $skipped = [];

    foreach ($fields as $id => $field) {
        if (!is_array($field)) {
            continue;
        }

        $id = (string) $id;
        $type = isset($field['type']) ? (string) $field['type'] : '';

        if (in_array($type, $decorative, true)) {
            if (in_array($id, $wanted, true)) {
                $skipped[] = ['field_id' => $id, 'type' => $type];
            }

            $fields[$id]['required'] = '0';
            continue;
        }

        if (in_array($id, $wanted, true)) {
            $fields[$id]['required'] = '1';
            $required_now[] = $id;
        } else {
            $fields[$id]['required'] = '0';
            $optional_now[] = $id;
        }
    }

    $data['form_fields'] = $fields;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'form_id' => (int) $form->ID,
        'required' => $required_now,
        'optional' => $optional_now,
        'skipped' => $skipped,
        'note' => $skipped === []
            ? __('Every field not listed is now optional.', 'nibwp')
            : __('A heading, divider or HTML block has nothing a visitor can fill in, so those were left optional rather than making the form impossible to submit.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_set_antispam(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = [];
    }

    $changed = [];

    if (isset($input['honeypot'])) {
        $data['settings']['honeypot'] = $input['honeypot'] ? '1' : '0';
        $changed[] = 'honeypot';
    }

    if (isset($input['akismet'])) {
        $data['settings']['akismet'] = $input['akismet'] ? '1' : '0';
        $changed[] = 'akismet';
    }

    if (isset($input['akismet_protection_type'])) {
        $type = sanitize_key((string) $input['akismet_protection_type']);

        if (!in_array($type, ['validation_failed', 'mark_as_spam'], true)) {
            return nibwp_everest_err(
                'bad_arg',
                __('akismet_protection_type must be validation_failed (reject the submission) or mark_as_spam (accept it and flag the entry).', 'nibwp'),
                422
            );
        }

        $data['settings']['akismet_protection_type'] = $type;
        $changed[] = 'akismet_protection_type';
    }

    if (isset($input['captcha'])) {
        $data['settings']['recaptcha_support'] = $input['captcha'] ? '1' : '0';
        $changed[] = 'captcha';
    }

    if ($changed === []) {
        return nibwp_everest_err(
            'missing_arg',
            __('Nothing to change. Give at least one of honeypot, akismet, akismet_protection_type or captcha.', 'nibwp'),
            422
        );
    }

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    // Switching a protection on that has no credentials behind it looks like
    // protection and is not, so the response says which is which rather than
    // reporting success and leaving the form open.
    $warnings = [];

    if (!empty($data['settings']['akismet']) && (string) $data['settings']['akismet'] === '1' && !nibwp_everest_akismet_ready()) {
        $warnings[] = __('Akismet is switched on for this form but the Akismet plugin is not active and configured, so it does nothing.', 'nibwp');
    }

    if (!empty($data['settings']['recaptcha_support']) && (string) $data['settings']['recaptcha_support'] === '1' && !nibwp_everest_captcha_ready()) {
        $warnings[] = __('The captcha is switched on for this form but no captcha provider has both a site key and a secret key configured site-wide, so no challenge appears.', 'nibwp');
    }

    return [
        'updated' => true,
        'form_id' => (int) $form->ID,
        'changed' => $changed,
        'honeypot' => (string) ($data['settings']['honeypot'] ?? '1') === '1',
        'akismet' => (string) ($data['settings']['akismet'] ?? '0') === '1',
        'akismet_protection_type' => (string) ($data['settings']['akismet_protection_type'] ?? 'validation_failed'),
        'captcha' => (string) ($data['settings']['recaptcha_support'] ?? '0') === '1',
        'warnings' => $warnings,
        'note' => __('The honeypot costs a visitor nothing and stops most of it. A captcha costs every visitor something, so turn it on when the honeypot is not enough rather than by default.', 'nibwp'),
    ];
}

/** Is Akismet actually there to do the work? */
function nibwp_everest_akismet_ready(): bool
{
    if (!class_exists('Akismet')) {
        return false;
    }

    if (function_exists('evf_is_akismet_configured')) {
        try {
            return (bool) evf_is_akismet_configured();
        } catch (\Throwable $error) {
            return false;
        }
    }

    $key = get_option('wordpress_api_key');

    return is_string($key) && $key !== '';
}

/**
 * Is any captcha provider fully configured?
 *
 * Both halves, because a site key with no secret renders a widget that can never
 * verify, which is the configuration owners accidentally ship.
 */
function nibwp_everest_captcha_ready(): bool
{
    foreach (
        [
            ['everest_forms_recaptcha_v2_site_key', 'everest_forms_recaptcha_v2_secret_key'],
            ['everest_forms_recaptcha_v2_invisible_site_key', 'everest_forms_recaptcha_v2_invisible_secret_key'],
            ['everest_forms_recaptcha_v3_site_key', 'everest_forms_recaptcha_v3_secret_key'],
            ['everest_forms_recaptcha_hcaptcha_site_key', 'everest_forms_recaptcha_hcaptcha_secret_key'],
            ['everest_forms_recaptcha_turnstile_site_key', 'everest_forms_recaptcha_turnstile_secret_key'],
        ] as $pair
    ) {
        if (
            nibwp_everest_is_set(get_option($pair[0], ''))
            && nibwp_everest_is_set(get_option($pair[1], ''))
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Site-wide settings, with every secret reported as a name only.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_get_global_settings(): array|WP_Error
{
    // Site-wide settings are a site-wide decision, so this is the plugin-wide
    // capability rather than a per-form one.
    if (!current_user_can('manage_everest_forms') && !current_user_can('manage_options')) {
        return nibwp_everest_forbidden(__('Reading the Everest Forms site settings', 'nibwp'), 'manage_everest_forms');
    }

    $public = [];
    $withheld = [];

    foreach (nibwp_everest_public_options() as $option => $type) {
        // The backstop runs even over the allow-list: if a name that reads like a
        // secret ever lands on the public list by mistake, it is still held back
        // rather than reported.
        if (nibwp_everest_is_secret_key($option)) {
            $withheld[] = [
                'key' => $option,
                'is_set' => nibwp_everest_is_set(get_option($option, '')),
                'value_returned' => false,
            ];
            continue;
        }

        $value = get_option($option, '');

        $public[] = [
            'key' => $option,
            'value' => match ($type) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                default => is_scalar($value) ? (string) $value : null,
            },
        ];
    }

    foreach (nibwp_everest_secret_options() as $option) {
        $withheld[] = [
            'key' => $option,
            'is_set' => nibwp_everest_is_set(get_option($option, '')),
            'value_returned' => false,
        ];
    }

    return [
        'settings' => $public,
        'withheld' => $withheld,
        'captcha_fully_configured' => nibwp_everest_captcha_ready(),
        'akismet_ready' => nibwp_everest_akismet_ready(),
        'secrets_returned' => false,
        'note' => __('Captcha SITE keys are reported because they are printed into every page that renders the widget. Secret keys, the AI API key, the REST API keys and the entry-approval token are reported as a name and whether they are set, and will not be returned however the question is phrased.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------- fields */

/**
 * @return array<string, mixed>
 */
function nibwp_everest_list_field_types(): array
{
    $all = nibwp_everest_field_types();
    $pro = nibwp_everest_pro_field_types();

    return [
        'types' => array_values(array_diff($all, $pro)),
        'needs_addon' => array_values(array_intersect($all, $pro)),
        'from_registry' => function_exists('evf'),
        'note' => __('Only the types in "types" can be added on this site. "needs_addon" are registered but locked behind an Everest Forms add-on, and adding one would produce a field that does not work.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_add_field(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $specs = (array) ($input['fields'] ?? []);

    if ($specs === []) {
        return nibwp_everest_err('missing_arg', __('Give the fields to add as fields: a list of { type, label, required }.', 'nibwp'), 422);
    }

    if (count($specs) > NIBWP_EVEREST_MAX_ROWS) {
        return nibwp_everest_err(
            'bad_arg',
            sprintf(
                /* translators: %d: the maximum */
                __('That is more than %d fields in one call. Split it.', 'nibwp'),
                NIBWP_EVEREST_MAX_ROWS
            ),
            422
        );
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $fields = (array) ($data['form_fields'] ?? []);
    $structure = (array) ($data['structure'] ?? []);

    // The counter is the form's, and the builder increments the same one, so a
    // field added here will not collide with the next one added in wp-admin.
    $counter = isset($data['form_field_id']) && is_numeric($data['form_field_id'])
        ? max(1, (int) $data['form_field_id'])
        : count($fields) + 1;

    $next_row = count($structure) + 1;
    $added = [];

    // Validated in full before anything is written, so one bad type does not
    // leave half the batch in the form.
    foreach ($specs as $spec) {
        $field = nibwp_everest_build_field(is_array($spec) ? $spec : [], $counter);

        if ($field instanceof WP_Error) {
            return $field;
        }

        $id = (string) $field['id'];
        $fields[$id] = $field;
        $structure = nibwp_everest_place_field(
            $structure,
            $id,
            is_array($spec) && isset($spec['row']) && is_numeric($spec['row']) ? (int) $spec['row'] : $next_row,
            is_array($spec) && isset($spec['grid']) && is_numeric($spec['grid']) ? (int) $spec['grid'] : 1
        );
        $added[] = nibwp_everest_report_field($id, $field);
        ++$next_row;
    }

    $data['form_fields'] = $fields;
    $data['structure'] = $structure;
    $data['form_field_id'] = (string) $counter;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'added' => count($added),
        'form_id' => (int) $form->ID,
        'fields' => $added,
        'field_count' => count($fields),
        'note' => __('Each field was placed in its own row unless row and grid said otherwise, which is what the builder does when you drop one on the canvas.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_update_field(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $field_id = isset($input['field_id']) ? (string) $input['field_id'] : '';

    if (trim($field_id) === '') {
        return nibwp_everest_err('missing_arg', __('Give the field id as field_id. get_form lists them.', 'nibwp'), 422);
    }

    $changes = (array) ($input['field'] ?? []);

    if ($changes === []) {
        return nibwp_everest_err('missing_arg', __('Give the keys to change as field: label, description, required, placeholder or choices.', 'nibwp'), 422);
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $fields = (array) ($data['form_fields'] ?? []);

    if (!isset($fields[$field_id]) || !is_array($fields[$field_id])) {
        return nibwp_everest_err(
            'not_found',
            sprintf(
                /* translators: 1: field id, 2: form id */
                __('Form %2$d has no field with id "%1$s". get_form lists the ids.', 'nibwp'),
                $field_id,
                (int) $form->ID
            ),
            404
        );
    }

    $field = $fields[$field_id];
    $changed = [];

    if (isset($changes['label'])) {
        $label = sanitize_text_field((string) $changes['label']);

        if ($label === '') {
            return nibwp_everest_err('bad_arg', __('A field with no label gives the visitor nothing to go on. Give one.', 'nibwp'), 422);
        }

        $field['label'] = $label;
        $changed[] = 'label';
    }

    if (isset($changes['description'])) {
        $field['description'] = sanitize_text_field((string) $changes['description']);
        $changed[] = 'description';
    }

    if (isset($changes['placeholder'])) {
        $field['placeholder'] = sanitize_text_field((string) $changes['placeholder']);
        $changed[] = 'placeholder';
    }

    if (isset($changes['required'])) {
        $field['required'] = $changes['required'] ? '1' : '0';
        $changed[] = 'required';
    }

    if (isset($changes['choices'])) {
        // Rebuilt through the same builder that create_form uses, so a choice
        // list written here has the shape the field's own option UI expects. The
        // counter it increments is a throwaway: the field keeps the id it has,
        // and the form's own counter must not move for an edit.
        $throwaway = 1;
        $rebuilt = nibwp_everest_build_field(
            [
                'type' => isset($field['type']) ? (string) $field['type'] : '',
                'label' => isset($field['label']) ? (string) $field['label'] : '',
                'choices' => $changes['choices'],
            ],
            $throwaway
        );

        if ($rebuilt instanceof WP_Error) {
            return $rebuilt;
        }

        if (!isset($rebuilt['choices'])) {
            return nibwp_everest_err(
                'bad_arg',
                sprintf(
                    /* translators: %s: field type */
                    __('A "%s" field has no choices to set.', 'nibwp'),
                    isset($field['type']) ? (string) $field['type'] : ''
                ),
                422
            );
        }

        $field['choices'] = $rebuilt['choices'];
        $changed[] = 'choices';
    }

    if ($changed === []) {
        return nibwp_everest_err(
            'bad_arg',
            __('None of those keys can be changed from here. Allowed: label, description, required, placeholder, choices. The field id, type and meta-key are fixed — entries already submitted are keyed by meta-key, and changing it would orphan them.', 'nibwp'),
            422
        );
    }

    // id, type and meta-key stay as they were, whatever arrived: a submission is
    // keyed by meta-key (includes/class-evf-form-task.php:1587), so renaming one
    // silently detaches every entry already collected from the field it came from.
    $field['id'] = $field_id;
    $field['type'] = $fields[$field_id]['type'] ?? '';
    if (isset($fields[$field_id]['meta-key'])) {
        $field['meta-key'] = $fields[$field_id]['meta-key'];
    }

    $fields[$field_id] = $field;
    $data['form_fields'] = $fields;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'updated' => true,
        'form_id' => (int) $form->ID,
        'field_id' => $field_id,
        'changed' => $changed,
        'field' => nibwp_everest_report_field($field_id, $field),
        'note' => __('The field id, type and meta-key are unchanged on purpose: entries already submitted are keyed by meta-key.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_remove_field(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    $field_id = isset($input['field_id']) ? (string) $input['field_id'] : '';

    if (trim($field_id) === '') {
        return nibwp_everest_err('missing_arg', __('Give the field id as field_id.', 'nibwp'), 422);
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $fields = (array) ($data['form_fields'] ?? []);

    if (!isset($fields[$field_id])) {
        return nibwp_everest_err(
            'not_found',
            sprintf(
                /* translators: 1: field id, 2: form id */
                __('Form %2$d has no field with id "%1$s".', 'nibwp'),
                $field_id,
                (int) $form->ID
            ),
            404
        );
    }

    $removed = nibwp_everest_report_field($field_id, (array) $fields[$field_id]);

    unset($fields[$field_id]);

    $data['form_fields'] = $fields;
    $data['structure'] = nibwp_everest_unplace_field((array) ($data['structure'] ?? []), $field_id);

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'removed' => true,
        'form_id' => (int) $form->ID,
        'field' => $removed,
        'field_count' => count($fields),
        'note' => __('The field is gone from the form. Entries already submitted keep the value they collected — nothing was deleted from the entries table — but new submissions will not have it.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_reorder_fields(array $input): array|WP_Error
{
    $form = nibwp_everest_open_form($input, 'edit');
    if ($form instanceof WP_Error) {
        return $form;
    }

    if (!isset($input['order']) || !is_array($input['order']) || $input['order'] === []) {
        return nibwp_everest_err('missing_arg', __('Give order as the complete list of field ids, in the order they should appear.', 'nibwp'), 422);
    }

    $data = nibwp_everest_form_data($form);
    if ($data instanceof WP_Error) {
        return $data;
    }

    $fields = (array) ($data['form_fields'] ?? []);
    $have = array_map('strval', array_keys($fields));
    $want = array_values(array_unique(array_map('strval', $input['order'])));

    $unknown = array_values(array_diff($want, $have));
    $missing = array_values(array_diff($have, $want));

    if ($unknown !== []) {
        return nibwp_everest_err(
            'not_found',
            sprintf(
                /* translators: %s: the field ids that do not exist */
                __('This form has no field with id: %s. Nothing was reordered.', 'nibwp'),
                implode(', ', $unknown)
            ),
            404
        );
    }

    // A partial order would drop whatever was left out of the layout entirely —
    // the field would stay in the form and stop rendering. Refusing is kinder
    // than silently losing it.
    if ($missing !== []) {
        return nibwp_everest_err(
            'bad_arg',
            sprintf(
                /* translators: %s: the field ids that were left out */
                __('order must list every field. These were left out and would stop rendering: %s.', 'nibwp'),
                implode(', ', $missing)
            ),
            422
        );
    }

    // One field per row, one column. The builder's multi-column rows are a visual
    // decision made by dragging, and flattening them is honest about what an
    // ordered list can express — the response says so.
    $structure = [];
    $row = 1;

    foreach ($want as $id) {
        $structure = nibwp_everest_place_field($structure, $id, $row, 1);
        ++$row;
    }

    // form_fields is reordered too, because the plugin re-sorts it to match the
    // layout on every save (includes/class-evf-ajax.php:615-617) and leaving the
    // two disagreeing invites whichever one a later reader trusts.
    $sorted = [];
    foreach ($want as $id) {
        $sorted[$id] = $fields[$id];
    }

    $data['form_fields'] = $sorted;
    $data['structure'] = $structure;

    $written = nibwp_everest_write_form($form, $data);

    if ($written instanceof WP_Error) {
        return $written;
    }

    return [
        'reordered' => true,
        'form_id' => (int) $form->ID,
        'order' => $want,
        'rows' => count($structure),
        'note' => __('Every field is now in its own full-width row. Side-by-side columns are a layout decision for the builder canvas; this puts them back in one column.', 'nibwp'),
    ];
}

/* ------------------------------------------------------------------ entries */

/**
 * How many entries a form has, not counting trashed ones.
 *
 * @return int|WP_Error
 */
function nibwp_everest_count_form_entries(int $form_id): int|WP_Error
{
    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $count = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . nibwp_everest_entries_table() . ' WHERE form_id = %d AND status != %s',
            $form_id,
            'trash'
        )
    );

    return (int) $count;
}

/**
 * Delete every entry row belonging to one form.
 *
 * @return int|WP_Error How many entry rows went.
 */
function nibwp_everest_purge_form_entries(int $form_id): int|WP_Error
{
    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $meta_sql = $wpdb->prepare(
        'DELETE FROM ' . nibwp_everest_entrymeta_table()
        . ' WHERE entry_id IN (SELECT entry_id FROM ' . nibwp_everest_entries_table() . ' WHERE form_id = %d)',
        $form_id
    );

    $wpdb->query($meta_sql);

    $removed = $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM ' . nibwp_everest_entries_table() . ' WHERE form_id = %d',
            $form_id
        )
    );

    return is_numeric($removed) ? (int) $removed : 0;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_list_entries(array $input): array|WP_Error
{
    $form = nibwp_everest_form($input['form_id'] ?? null);
    if ($form instanceof WP_Error) {
        return $form;
    }

    if ($denied = nibwp_everest_can_read_entries((int) $form->ID)) {
        return $denied;
    }

    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $limit = nibwp_everest_limit($input['limit'] ?? null);
    $offset = nibwp_everest_offset($input['offset'] ?? null);
    $status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'any';

    if (!in_array($status, ['publish', 'spam', 'trash', 'denied', 'any'], true)) {
        return nibwp_everest_err('bad_arg', __('status must be publish, spam, trash, denied or any.', 'nibwp'), 422);
    }

    // Columns are named rather than SELECT *: the `fields` column is the whole
    // submission and `user_ip_address` is the visitor. Neither belongs in a
    // listing, and naming the columns means a later schema addition cannot
    // quietly start travelling.
    $sql = $status === 'any'
        ? $wpdb->prepare(
            'SELECT entry_id, form_id, status, viewed, starred, date_created, user_id'
            . ' FROM ' . nibwp_everest_entries_table()
            . ' WHERE form_id = %d ORDER BY entry_id DESC LIMIT %d OFFSET %d',
            (int) $form->ID,
            $limit,
            $offset
        )
        : $wpdb->prepare(
            'SELECT entry_id, form_id, status, viewed, starred, date_created, user_id'
            . ' FROM ' . nibwp_everest_entries_table()
            . ' WHERE form_id = %d AND status = %s ORDER BY entry_id DESC LIMIT %d OFFSET %d',
            (int) $form->ID,
            $status,
            $limit,
            $offset
        );

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $entries = [];
    $total = nibwp_everest_count_form_entries((int) $form->ID);

    foreach ($rows as $row) {
        $entries[] = [
            'entry_id' => (int) ($row['entry_id'] ?? 0),
            'form_id' => (int) ($row['form_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'read' => !empty($row['viewed']),
            'starred' => !empty($row['starred']),
            'submitted' => (string) ($row['date_created'] ?? ''),
            'by_logged_in_user' => (int) ($row['user_id'] ?? 0) > 0,
        ];
    }

    return [
        'form_id' => (int) $form->ID,
        'returned' => count($entries),
        'limit' => $limit,
        'max_limit' => NIBWP_EVEREST_MAX_ROWS,
        'offset' => $offset,
        'total' => $total instanceof WP_Error ? null : $total,
        'entries' => $entries,
        'values_returned' => false,
        'note' => __('Metadata only. What people typed, and the IP address they typed it from, are not in a listing — get_entry returns one entry\'s values, one at a time.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_get_entry(array $input): array|WP_Error
{
    $row = nibwp_everest_entry_row($input['entry_id'] ?? null);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $entry_id = (int) ($row->entry_id ?? 0);

    if ($denied = nibwp_everest_can_read_entry($entry_id)) {
        return $denied;
    }

    // json_decode, because the submitted values are stored with wp_json_encode
    // (includes/class-evf-form-task.php:1532) and read back with evf_decode
    // (includes/evf-entry-functions.php:55). unserialize() is never involved.
    $decoded = json_decode(is_string($row->fields ?? null) ? (string) $row->fields : '', true);
    $values = [];

    foreach (is_array($decoded) ? $decoded : [] as $field) {
        $field = (array) $field;

        $values[] = [
            'label' => isset($field['name']) && is_scalar($field['name']) ? (string) $field['name'] : '',
            'meta_key' => isset($field['meta_key']) && is_scalar($field['meta_key']) ? (string) $field['meta_key'] : '',
            'type' => isset($field['type']) && is_scalar($field['type']) ? (string) $field['type'] : '',
            'value' => is_scalar($field['value'] ?? null) ? (string) $field['value'] : wp_json_encode($field['value'] ?? null),
        ];
    }

    return [
        'entry_id' => $entry_id,
        'form_id' => (int) ($row->form_id ?? 0),
        'status' => (string) ($row->status ?? ''),
        'read' => !empty($row->viewed),
        'starred' => !empty($row->starred),
        'submitted' => (string) ($row->date_created ?? ''),
        'by_logged_in_user' => (int) ($row->user_id ?? 0) > 0,
        'values' => $values,
        'value_count' => count($values),
        'readable' => is_array($decoded),
        'note' => __('This is personal data somebody submitted. Quote only what was asked for. Reading does not mark it read — mark_entry_read does that, and only if you are asked to.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_count_entries(array $input): array|WP_Error
{
    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $days = nibwp_everest_days($input['days'] ?? null);
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    $form_id = isset($input['form_id']) && is_numeric($input['form_id']) ? (int) $input['form_id'] : 0;

    if ($form_id > 0) {
        $form = nibwp_everest_form($form_id);
        if ($form instanceof WP_Error) {
            return $form;
        }

        if ($denied = nibwp_everest_can_read_entries((int) $form->ID)) {
            return $denied;
        }

        $sql = $wpdb->prepare(
            'SELECT status, COUNT(*) AS hits FROM ' . nibwp_everest_entries_table()
            . ' WHERE form_id = %d AND date_created >= %s GROUP BY status ORDER BY hits DESC LIMIT %d',
            $form_id,
            $since,
            NIBWP_EVEREST_MAX_ROWS
        );
    } else {
        // Site-wide, which no single form id can gate, so it takes the capability
        // the plugin puts on its own Entries screen.
        if ($denied = nibwp_everest_can_read_any_entries()) {
            return $denied;
        }

        $sql = $wpdb->prepare(
            'SELECT status, COUNT(*) AS hits FROM ' . nibwp_everest_entries_table()
            . ' WHERE date_created >= %s GROUP BY status ORDER BY hits DESC LIMIT %d',
            $since,
            NIBWP_EVEREST_MAX_ROWS
        );
    }

    $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    $by_status = [];
    $total = 0;

    foreach ($rows as $row) {
        $hits = (int) ($row['hits'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $by_status[] = ['status' => $status, 'count' => $hits];

        if ($status !== 'trash') {
            $total += $hits;
        }
    }

    return [
        'form_id' => $form_id > 0 ? $form_id : null,
        'scope' => $form_id > 0 ? 'form' : 'site',
        'days' => $days,
        'since' => $since,
        'total' => $total,
        'by_status' => $by_status,
        'note' => __('Counts only, which is usually the whole answer and names nobody. Trashed entries are listed but not counted in total.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_mark_entry_read(array $input): array|WP_Error
{
    $row = nibwp_everest_entry_row($input['entry_id'] ?? null);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $entry_id = (int) ($row->entry_id ?? 0);

    if ($denied = nibwp_everest_can_edit_entry($entry_id)) {
        return $denied;
    }

    if (!empty($row->viewed)) {
        return [
            'updated' => false,
            'entry_id' => $entry_id,
            'read' => true,
            'note' => __('It was already marked read. Nothing changed.', 'nibwp'),
        ];
    }

    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $updated = $wpdb->update(
        nibwp_everest_entries_table(),
        ['viewed' => 1],
        ['entry_id' => $entry_id],
        ['%d'],
        ['%d']
    );

    if ($updated === false) {
        return nibwp_everest_err('write_failed', __('The entry could not be marked read.', 'nibwp'), 500);
    }

    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($entry_id, 'evf-entry');
    }

    return [
        'updated' => true,
        'entry_id' => $entry_id,
        'form_id' => (int) ($row->form_id ?? 0),
        'read' => true,
        'note' => __('The unread count in wp-admin now reflects this. Marking an entry read is a claim that somebody dealt with it, so only do it when asked.', 'nibwp'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_delete_entry(array $input): array|WP_Error
{
    $row = nibwp_everest_entry_row($input['entry_id'] ?? null);
    if ($row instanceof WP_Error) {
        return $row;
    }

    $entry_id = (int) ($row->entry_id ?? 0);

    if ($denied = nibwp_everest_can_delete_entry($entry_id)) {
        return $denied;
    }

    if (empty($input['confirm'])) {
        return nibwp_everest_err(
            'no_confirm',
            sprintf(
                /* translators: 1: entry id, 2: form id, 3: when it was submitted */
                __('Deleting entry %1$d (form %2$d, submitted %3$s) is permanent: the submission and its meta go, with no trash step and no undo. Call again with confirm:true.', 'nibwp'),
                $entry_id,
                (int) ($row->form_id ?? 0),
                (string) ($row->date_created ?? '')
            ),
            428
        );
    }

    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    // Meta first: an entry row with no meta is a recoverable mess, meta rows with
    // no entry are unreachable garbage.
    $wpdb->delete(nibwp_everest_entrymeta_table(), ['entry_id' => $entry_id], ['%d']);

    $deleted = $wpdb->delete(nibwp_everest_entries_table(), ['entry_id' => $entry_id], ['%d']);

    if ($deleted === false) {
        return nibwp_everest_err('write_failed', __('The entry could not be deleted.', 'nibwp'), 500);
    }

    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($entry_id, 'evf-entry');
        wp_cache_delete($entry_id, 'evf-entrymeta');
    }

    return [
        'deleted' => true,
        'entry_id' => $entry_id,
        'form_id' => (int) ($row->form_id ?? 0),
        'note' => __('Permanent. The submission is gone and cannot be restored. Any file somebody uploaded with it stays in the uploads folder — the plugin removes those on its own schedule, not here.', 'nibwp'),
    ];
}

/* -------------------------------------------------------------------- audits */

/**
 * Every form this account may read, with its decoded data. Bounded.
 *
 * @return array<int, array{post: WP_Post, data: array<string, mixed>}>
 */
function nibwp_everest_audit_forms(int $limit): array
{
    $posts = get_posts([
        'post_type' => NIBWP_EVEREST_CPT,
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => false,
    ]);

    $out = [];

    foreach (is_array($posts) ? $posts : [] as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }

        if (nibwp_everest_can_read_form((int) $post->ID) instanceof WP_Error) {
            continue;
        }

        $data = nibwp_everest_form_data($post);

        $out[] = ['post' => $post, 'data' => $data instanceof WP_Error ? [] : $data];
    }

    return $out;
}

/**
 * Forms that collect submissions and tell nobody.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_audit_no_notification(array $input): array|WP_Error
{
    if ($denied = nibwp_everest_can_read_any_forms()) {
        return $denied;
    }

    $limit = nibwp_everest_limit($input['limit'] ?? null, NIBWP_EVEREST_MAX_ROWS);
    $forms = nibwp_everest_audit_forms($limit);
    $silent = [];

    foreach ($forms as $entry) {
        $post = $entry['post'];
        $connections = nibwp_everest_connections($entry['data']);
        $sending = [];

        foreach ($connections as $id => $connection) {
            if (nibwp_everest_notification_enabled($connection) && nibwp_everest_is_set($connection['evf_to_email'] ?? '')) {
                $sending[] = (string) $id;
            }
        }

        if ($sending !== []) {
            continue;
        }

        $collected = nibwp_everest_count_form_entries((int) $post->ID);

        $silent[] = [
            'form_id' => (int) $post->ID,
            'title' => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'notifications_defined' => count($connections),
            'why' => $connections === []
                ? __('No notification is defined at all.', 'nibwp')
                : __('Every notification is either switched off or has no recipient.', 'nibwp'),
            'entries' => $collected instanceof WP_Error ? null : $collected,
        ];
    }

    return [
        'checked' => count($forms),
        'limit' => $limit,
        'count' => count($silent),
        'forms' => $silent,
        'note' => $silent === []
            ? __('Every form checked has at least one notification that is switched on and has a recipient.', 'nibwp')
            : __('These forms accept submissions and email nobody. Where the entry count is above zero, somebody has already filled one in and nobody was told. set_notification fixes it.', 'nibwp'),
    ];
}

/**
 * Forms notifying somewhere other than the site administrator.
 *
 * Usually deliberate — a sales address, a shared inbox — and occasionally a
 * person who left. Reported rather than judged, with the admin address named so
 * the comparison is visible.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_audit_notification_recipients(array $input): array|WP_Error
{
    if ($denied = nibwp_everest_can_read_any_forms()) {
        return $denied;
    }

    $limit = nibwp_everest_limit($input['limit'] ?? null, NIBWP_EVEREST_MAX_ROWS);
    $forms = nibwp_everest_audit_forms($limit);
    $admin = strtolower(trim((string) get_option('admin_email', '')));
    $elsewhere = [];
    $site_domain = strtolower((string) wp_parse_url((string) home_url(), PHP_URL_HOST));

    foreach ($forms as $entry) {
        $post = $entry['post'];

        foreach (nibwp_everest_connections($entry['data']) as $id => $connection) {
            $to = isset($connection['evf_to_email']) && is_scalar($connection['evf_to_email'])
                ? (string) $connection['evf_to_email']
                : '';

            if (trim($to) === '') {
                continue;
            }

            $offsite = [];

            foreach (array_map('trim', explode(',', $to)) as $recipient) {
                if ($recipient === '') {
                    continue;
                }

                // {admin_email} resolves to the admin address, so it is not
                // "somewhere else" however many times it is written.
                if (str_starts_with($recipient, '{')) {
                    if ($recipient !== '{admin_email}') {
                        $offsite[] = [
                            'recipient' => $recipient,
                            'kind' => 'smart_tag',
                            'same_domain' => null,
                        ];
                    }

                    continue;
                }

                if (strtolower($recipient) === $admin) {
                    continue;
                }

                $domain = strtolower((string) substr($recipient, (int) strpos($recipient . '@', '@') + 1));

                $offsite[] = [
                    'recipient' => $recipient,
                    'kind' => 'address',
                    'same_domain' => $site_domain !== '' && $domain !== '' && str_ends_with($site_domain, $domain),
                ];
            }

            if ($offsite === []) {
                continue;
            }

            $elsewhere[] = [
                'form_id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'connection_id' => (string) $id,
                'sends' => nibwp_everest_notification_enabled($connection),
                'recipients' => $offsite,
            ];

            if (count($elsewhere) >= $limit) {
                break 2;
            }
        }
    }

    return [
        'checked' => count($forms),
        'limit' => $limit,
        'admin_email' => $admin,
        'count' => count($elsewhere),
        'notifications' => $elsewhere,
        'note' => $elsewhere === []
            ? __('Every notification checked goes to the site administrator.', 'nibwp')
            : __('A recipient that is not the site administrator is usually intentional. Worth asking about: an address at a domain this site does not own, and a field-based recipient that lets a visitor choose who hears from the form. A smart tag other than {admin_email} resolves at send time, so its real destination is not visible here.', 'nibwp'),
    ];
}

/**
 * Forms nobody has ever submitted.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_everest_audit_never_submitted(array $input): array|WP_Error
{
    if ($denied = nibwp_everest_can_read_any_forms()) {
        return $denied;
    }

    $wpdb = nibwp_everest_db();
    if ($wpdb instanceof WP_Error) {
        return $wpdb;
    }

    $limit = nibwp_everest_limit($input['limit'] ?? null, NIBWP_EVEREST_MAX_ROWS);
    $forms = nibwp_everest_audit_forms($limit);

    // One grouped query rather than one per form, bounded like everything else.
    $counts = [];
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT form_id, COUNT(*) AS hits, MAX(date_created) AS latest'
            . ' FROM ' . nibwp_everest_entries_table()
            . ' WHERE status != %s GROUP BY form_id ORDER BY hits DESC LIMIT %d',
            'trash',
            NIBWP_EVEREST_MAX_ROWS
        ),
        ARRAY_A
    );

    foreach ($rows as $row) {
        $counts[(int) ($row['form_id'] ?? 0)] = [
            'hits' => (int) ($row['hits'] ?? 0),
            'latest' => (string) ($row['latest'] ?? ''),
        ];
    }

    $never = [];
    $stale = [];
    $days = nibwp_everest_days($input['days'] ?? null, 90);
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    foreach ($forms as $entry) {
        $post = $entry['post'];
        $form_id = (int) $post->ID;
        $seen = $counts[$form_id] ?? null;
        $fields = (array) ($entry['data']['form_fields'] ?? []);

        if ($seen === null || $seen['hits'] === 0) {
            $never[] = [
                'form_id' => $form_id,
                'title' => (string) $post->post_title,
                'status' => (string) $post->post_status,
                'created' => (string) $post->post_date_gmt,
                'field_count' => count($fields),
                'entries_disabled' => (string) ($entry['data']['settings']['disabled_entries'] ?? '0') === '1',
            ];
            continue;
        }

        if ($seen['latest'] !== '' && $seen['latest'] < $cutoff) {
            $stale[] = [
                'form_id' => $form_id,
                'title' => (string) $post->post_title,
                'entries' => $seen['hits'],
                'last_entry' => $seen['latest'],
            ];
        }
    }

    return [
        'checked' => count($forms),
        'limit' => $limit,
        'quiet_for_days' => $days,
        'never_submitted' => $never,
        'nothing_recently' => $stale,
        'note' => __('A form with fields and no entries is as often broken as unused: check it is embedded somewhere, that entries are not switched off for it, and that submitting it actually works. entries_disabled true means the plugin is told not to store submissions, so there would be none even if people were filling it in.', 'nibwp'),
    ];
}
